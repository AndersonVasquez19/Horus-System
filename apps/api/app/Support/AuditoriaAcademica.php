<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Matricula;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Auditoría de operaciones académicas (Sprint 20): estudiantes, cursos y matrículas.
 *
 * La privacidad se garantiza con listas permitidas explícitas por entidad, no solo con
 * {@see SensitiveData::redact()} (que enmascara por nombre de clave):
 *  - «valores»: campos de los que se guardan el valor anterior y el nuevo.
 *  - «nombres»: campos de los que solo se guarda el nombre, nunca el valor.
 *  - Cualquier otro campo se ignora por completo (ni valor ni nombre).
 *
 * Esta clase no abre transacciones: el llamador debe invocarla como ÚLTIMA escritura dentro de la
 * misma transacción que modifica el modelo, de modo que un fallo de la auditoría revierta la operación.
 * El actor se recibe de forma explícita para no depender de {@see auth()}.
 *
 * La lista de «curso» se usa desde la fase 2b (solo precio y cupos llevan valores). La de «matricula»
 * la usará el controlador en las fases siguientes del Sprint 21.2; el núcleo (diferencias, efecto sobre
 * los cupos y registro de eliminación/restauración) ya está disponible y probado, pero ningún controlador
 * lo invoca todavía.
 *
 * Efecto sobre los cupos (solo clasificación; esta clase nunca modifica cupos): ver {@see self::efectoCupo()}.
 */
final class AuditoriaAcademica
{
    public const ESQUEMA = 1;

    /** Efectos de una operación de matrícula sobre los cupos del curso (lista cerrada). */
    public const EFECTO_OCUPA = 'ocupa';

    public const EFECTO_LIBERA = 'libera';

    public const EFECTO_TRASLADA = 'traslada';

    public const EFECTO_NINGUNO = 'ninguno';

    /** Operaciones de matrícula que se registran sin diferencias (lista cerrada, sin texto libre). */
    public const OPERACION_ELIMINACION = 'eliminacion_logica';

    public const OPERACION_RESTAURACION = 'restauracion';

    private const EFECTOS = [
        self::EFECTO_OCUPA,
        self::EFECTO_LIBERA,
        self::EFECTO_TRASLADA,
        self::EFECTO_NINGUNO,
    ];

    private const OPERACIONES_MATRICULA = [
        self::OPERACION_ELIMINACION,
        self::OPERACION_RESTAURACION,
    ];

    /** Estados de matrícula que ocupan cupo; «anulada» no lo ocupa. */
    private const ESTADOS_QUE_OCUPAN = ['pendiente', 'pagada'];

    private const ESTADO_ANULADA = 'anulada';

    public const ESTUDIANTE = 'estudiante';

    public const CURSO = 'curso';

    public const MATRICULA = 'matricula';

    /** Columnas de control que nunca forman parte de una diferencia auditada. */
    private const IGNORADOS = ['updated_at', 'created_at', 'deleted_at'];

    /**
     * @var array<string, array{valores: list<string>, nombres: list<string>}>
     */
    private const LISTAS = [
        self::ESTUDIANTE => [
            'valores' => ['estado'],
            'nombres' => ['dni', 'nombres', 'apellidos', 'email', 'telefono', 'fecha_nacimiento', 'direccion'],
        ],
        self::CURSO => [
            // «cupos» autorizados expresamente: la tabla no tiene una columna «cupos», sino estas dos.
            'valores' => ['precio', 'cupos_maximos', 'cupos_disponibles'],
            'nombres' => ['codigo', 'nombre', 'descripcion', 'categoria', 'duracion_horas', 'modalidad', 'horario', 'estado'],
        ],
        self::MATRICULA => [
            'valores' => ['estudiante_id', 'curso_id', 'fecha_matricula', 'estado', 'monto_pagado'],
            'nombres' => ['observaciones'],
        ],
    ];

    /**
     * Campos autorizados (con valor o solo con nombre) de una entidad.
     *
     * @return list<string>
     */
    public static function camposPermitidos(string $entidad): array
    {
        $lista = self::lista($entidad);

        return array_merge($lista['valores'], $lista['nombres']);
    }

    /**
     * Identificador explícito del usuario autenticado; la auditoría no depende del fallback de auth().
     */
    public static function actorDe(Request $request): int
    {
        $id = $request->user()?->getAuthIdentifier();
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id)), 403);

        return (int) $id;
    }

    /**
     * Calcula las diferencias reales de un modelo ya rellenado (fill) y todavía sin guardar.
     * Debe llamarse ANTES de save(), porque después getDirty() queda vacío.
     *
     * @return array{campos: list<string>, antes: array<string, mixed>, despues: array<string, mixed>}
     */
    public static function diferencias(Model $modelo, string $entidad): array
    {
        $lista = self::lista($entidad);
        $permitidos = self::camposPermitidos($entidad);
        $sucios = array_diff_key($modelo->getDirty(), array_flip(self::IGNORADOS));

        $campos = [];
        $antes = [];
        $despues = [];

        foreach ($permitidos as $campo) {
            if (! array_key_exists($campo, $sucios)) {
                continue;
            }

            if (in_array($campo, $lista['valores'], true)) {
                $valorAntes = self::normalizar($modelo, $campo, $modelo->getOriginal($campo));
                $valorDespues = self::normalizar($modelo, $campo, $modelo->getAttribute($campo));

                // Sprint 21.2 (D10-a), solo matrículas: Eloquent marca «sucio» un atributo de fecha cuando el valor
                // nuevo trae hora (se compara con fromDateTime) aunque la fecha sea la misma. Si el valor ya
                // normalizado es idéntico no hay cambio real que auditar. No se altera ningún valor guardado, y
                // estudiantes y cursos conservan exactamente su regla anterior.
                if ($entidad === self::MATRICULA && $valorAntes === $valorDespues) {
                    continue;
                }

                $antes[$campo] = $valorAntes;
                $despues[$campo] = $valorDespues;
            }

            $campos[] = $campo;
        }

        return ['campos' => $campos, 'antes' => $antes, 'despues' => $despues];
    }

    /**
     * Efectos válidos de una operación de matrícula sobre los cupos.
     *
     * @return list<string>
     */
    public static function efectosPermitidos(): array
    {
        return self::EFECTOS;
    }

    /**
     * Clasifica, sin tocar ningún dato, el efecto de una operación de matrícula sobre los cupos.
     *
     * Una matrícula «ocupa» cupo cuando existe (no eliminada) y su estado es pendiente o pagada; la
     * anulada, la eliminada y la inexistente no lo ocupan. El estado «null» representa «no existe / no
     * está vigente» y sirve para expresar la creación (antes), la eliminación (después) y la
     * restauración (antes).
     *
     *  - no ocupaba y no ocupa      → ninguno (incluye nacer anulada, anulada ya anulada, corregir historia)
     *  - no ocupaba y ocupa         → ocupa (alta vigente, reactivación, restauración vigente)
     *  - ocupaba y ya no ocupa      → libera (anulación, eliminación de una vigente)
     *  - ocupaba y ocupa            → traslada si el curso cambia; ninguno si no cambia
     *
     * Un estado distinto de pendiente, pagada o anulada lanza {@see InvalidArgumentException}.
     */
    public static function efectoCupo(?string $estadoAntes, ?int $cursoAntes, ?string $estadoDespues, ?int $cursoDespues): string
    {
        $ocupabaAntes = self::ocupaCupo($estadoAntes);
        $ocupaDespues = self::ocupaCupo($estadoDespues);

        if (! $ocupabaAntes && ! $ocupaDespues) {
            return self::EFECTO_NINGUNO;
        }

        if (! $ocupabaAntes) {
            return self::EFECTO_OCUPA;
        }

        if (! $ocupaDespues) {
            return self::EFECTO_LIBERA;
        }

        return $cursoAntes !== $cursoDespues ? self::EFECTO_TRASLADA : self::EFECTO_NINGUNO;
    }

    /**
     * Detalle de una creación: nombres de los campos informados y valores solo de los autorizados.
     *
     * @return array<string, mixed>
     */
    public static function detalleCreacion(Model $modelo, string $entidad, ?string $efectoCupo = null): array
    {
        $lista = self::lista($entidad);
        $campos = [];
        $valores = [];

        foreach (self::camposPermitidos($entidad) as $campo) {
            if ($modelo->getAttribute($campo) === null) {
                continue;
            }

            $campos[] = $campo;

            if (in_array($campo, $lista['valores'], true)) {
                $valores[$campo] = self::normalizar($modelo, $campo, $modelo->getAttribute($campo));
            }
        }

        return self::conEfecto(['campos' => $campos, 'valores' => $valores], $efectoCupo);
    }

    /**
     * Detalle de una eliminación lógica o restauración de matrícula (decisión D7): operación de una
     * lista cerrada, efecto sobre cupos, y solo «curso_id» y «estado» como valores. Nada más.
     *
     * @return array<string, mixed>
     */
    public static function detalleOperacionMatricula(Model $matricula, string $operacion, string $efectoCupo): array
    {
        if (! in_array($operacion, self::OPERACIONES_MATRICULA, true)) {
            throw new InvalidArgumentException("Operación de matrícula desconocida: {$operacion}");
        }

        $detalle = ['operacion' => $operacion, 'efecto_cupo' => self::validarEfecto($efectoCupo)];

        foreach (['curso_id', 'estado'] as $campo) {
            $valor = self::normalizar($matricula, $campo, $matricula->getAttribute($campo));
            if ($valor !== null) {
                $detalle[$campo] = $valor;
            }
        }

        return $detalle;
    }

    /**
     * Registra una creación. «efectoCupo» es opcional y solo lo usan las matrículas.
     */
    public static function registrarCreacion(string $accion, Model $modelo, string $entidad, int $actorId, ?string $efectoCupo = null): AuditLog
    {
        return self::registrar($accion, $modelo, self::detalleCreacion($modelo, $entidad, $efectoCupo), $actorId);
    }

    /**
     * Registra una actualización solo si hay diferencias reales. Devuelve false cuando no registra nada.
     * «efectoCupo» es opcional y solo lo usan las matrículas; sin diferencias tampoco se registra.
     *
     * @param  array{campos: list<string>, antes: array<string, mixed>, despues: array<string, mixed>}  $diferencias
     */
    public static function registrarActualizacion(string $accion, Model $modelo, array $diferencias, int $actorId, ?string $efectoCupo = null): bool
    {
        if ($diferencias['campos'] === []) {
            return false;
        }

        $detalle = self::conEfecto([
            'campos' => $diferencias['campos'],
            'antes' => $diferencias['antes'],
            'despues' => $diferencias['despues'],
        ], $efectoCupo);

        self::registrar($accion, $modelo, $detalle, $actorId);

        return true;
    }

    /**
     * Registra la eliminación lógica o la restauración de una matrícula (ver {@see self::detalleOperacionMatricula()}).
     */
    public static function registrarOperacionMatricula(string $accion, Model $matricula, string $operacion, string $efectoCupo, int $actorId): AuditLog
    {
        return self::registrar($accion, $matricula, self::detalleOperacionMatricula($matricula, $operacion, $efectoCupo), $actorId);
    }

    /**
     * Registra la exportación CSV de matrículas con una lista cerrada (Fase 4): nombre del archivo, filtros
     * efectivos de valor acotado y un booleano que indica que hubo búsqueda. Nunca el texto buscado, que podía
     * ser un DNI o un nombre. Mantiene la acción histórica `matriculas_csv_exported` y no usa el esquema 1.
     */
    public static function registrarExportacionMatriculas(
        string $archivo,
        ?string $estado,
        ?int $cursoId,
        bool $eliminados,
        bool $busquedaAplicada,
        int $actorId,
    ): AuditLog {
        return AuditLog::record(
            'matriculas_csv_exported',
            Matricula::class,
            null,
            [
                'filename' => $archivo,
                'estado' => $estado,
                'curso_id' => $cursoId,
                'eliminados' => $eliminados,
                'busqueda_aplicada' => $busquedaAplicada,
            ],
            $actorId,
        );
    }

    /**
     * Registro semántico mínimo (eliminación lógica, restauración…): identifica la operación sin ningún valor.
     */
    public static function registrarOperacion(string $accion, Model $modelo, string $operacion, int $actorId): AuditLog
    {
        return self::registrar($accion, $modelo, ['operacion' => $operacion], $actorId);
    }

    /**
     * @param  array<string, mixed>  $detalle
     */
    private static function registrar(string $accion, Model $modelo, array $detalle, int $actorId): AuditLog
    {
        return AuditLog::record(
            $accion,
            $modelo::class,
            $modelo->getKey(),
            ['esquema' => self::ESQUEMA] + $detalle,
            $actorId,
        );
    }

    /**
     * Añade «efecto_cupo» (validado contra la lista cerrada) solo cuando se informa.
     *
     * @param  array<string, mixed>  $detalle
     * @return array<string, mixed>
     */
    private static function conEfecto(array $detalle, ?string $efectoCupo): array
    {
        if ($efectoCupo !== null) {
            $detalle['efecto_cupo'] = self::validarEfecto($efectoCupo);
        }

        return $detalle;
    }

    private static function validarEfecto(string $efectoCupo): string
    {
        if (! in_array($efectoCupo, self::EFECTOS, true)) {
            throw new InvalidArgumentException("Efecto de cupo desconocido: {$efectoCupo}");
        }

        return $efectoCupo;
    }

    /**
     * Si una matrícula con este estado ocupa cupo; «null» significa que no existe o no está vigente.
     */
    private static function ocupaCupo(?string $estado): bool
    {
        if ($estado === null || $estado === self::ESTADO_ANULADA) {
            return false;
        }

        if (! in_array($estado, self::ESTADOS_QUE_OCUPAN, true)) {
            throw new InvalidArgumentException("Estado de matrícula desconocido: {$estado}");
        }

        return true;
    }

    /**
     * @return array{valores: list<string>, nombres: list<string>}
     */
    private static function lista(string $entidad): array
    {
        return self::LISTAS[$entidad] ?? throw new InvalidArgumentException("Entidad de auditoría desconocida: {$entidad}");
    }

    /**
     * Reduce un valor a un escalar estable para JSON; lo que no sea representable se guarda como null.
     */
    private static function normalizar(Model $modelo, string $campo, mixed $valor): string|int|float|bool|null
    {
        if ($valor instanceof Carbon) {
            return $modelo->hasCast($campo, ['date']) ? $valor->toDateString() : $valor->toIso8601String();
        }

        if ($valor instanceof BackedEnum) {
            return $valor->value;
        }

        return is_scalar($valor) ? $valor : null;
    }
}
