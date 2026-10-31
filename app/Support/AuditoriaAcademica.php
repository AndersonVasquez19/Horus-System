<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
 * solo prepara la fase 2c; hasta entonces ningún controlador la utiliza.
 */
final class AuditoriaAcademica
{
    public const ESQUEMA = 1;

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

            $campos[] = $campo;

            if (in_array($campo, $lista['valores'], true)) {
                $antes[$campo] = self::normalizar($modelo, $campo, $modelo->getOriginal($campo));
                $despues[$campo] = self::normalizar($modelo, $campo, $modelo->getAttribute($campo));
            }
        }

        return ['campos' => $campos, 'antes' => $antes, 'despues' => $despues];
    }

    /**
     * Registra una creación: nombres de los campos informados y valores solo de los autorizados.
     */
    public static function registrarCreacion(string $accion, Model $modelo, string $entidad, int $actorId): AuditLog
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

        return self::registrar($accion, $modelo, ['campos' => $campos, 'valores' => $valores], $actorId);
    }

    /**
     * Registra una actualización solo si hay diferencias reales. Devuelve false cuando no registra nada.
     *
     * @param  array{campos: list<string>, antes: array<string, mixed>, despues: array<string, mixed>}  $diferencias
     */
    public static function registrarActualizacion(string $accion, Model $modelo, array $diferencias, int $actorId): bool
    {
        if ($diferencias['campos'] === []) {
            return false;
        }

        self::registrar($accion, $modelo, $diferencias, $actorId);

        return true;
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
     * @return array{valores: list<string>, nombres: list<string>}
     */
    private static function lista(string $entidad): array
    {
        return self::LISTAS[$entidad] ?? throw new \InvalidArgumentException("Entidad de auditoría desconocida: {$entidad}");
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
