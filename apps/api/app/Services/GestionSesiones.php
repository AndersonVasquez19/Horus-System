<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Aula;
use App\Models\Convocatoria;
use App\Models\Docente;
use App\Models\PlataformaVirtual;
use App\Models\SesionClase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sesiones de clase (Horus Academic, Fase 5). Única implementación de las reglas:
 *
 *  - solo en ediciones vigentes y con fecha dentro del rango de la edición;
 *  - el lugar sigue la modalidad: presencial = aula de la sede de la edición (por defecto la de la edición); virtual = plataforma
 *    (por defecto la de la edición) y, opcionalmente, un enlace privado https guardado cifrado;
 *  - el docente debe estar asignado a la edición y activo;
 *  - sin cruces: una sesión no cancelada no puede solaparse en el tiempo con otra del mismo aula ni del mismo docente. Se comprueba
 *    bajo bloqueos consultivos por aula y por docente en PostgreSQL (en SQLite ignorados: la regla vive aquí y no se prueba la carrera);
 *  - orden de bloqueo: edición → sesión; luego aulas y docentes por id;
 *  - la auditoría guarda ids y NOMBRES de campos; el enlace virtual jamás se escribe en ella.
 */
class GestionSesiones
{
    /** Tope de sesiones creadas por una generación desde el horario semanal. */
    public const MAX_GENERADAS = 200;

    private const MAX_OMITIDAS_INFORMADAS = 50;

    public function __construct(private readonly GestionConvocatorias $convocatorias) {}

    /** @param array<string, mixed> $datos `fecha`, `hora_inicio`, `hora_fin` y opcionales `numero`, `tema`, `docente`, `aula`, `plataforma_virtual`, `enlace_virtual` */
    public function crear(Request $request, string $edicion, array $datos): SesionClase
    {
        return DB::transaction(function () use ($request, $edicion, $datos): SesionClase {
            $convocatoria = $this->convocatorias->bloquear($edicion);
            $autor = $this->convocatorias->autorizar($request, 'sesiones.crear');
            $this->comprobarVigente($convocatoria);

            $campos = $this->resolver($convocatoria, $datos, null);
            $numero = $datos['numero'] ?? null;
            if ($numero !== null && SesionClase::query()->where('convocatoria_id', $convocatoria->id)->where('numero', $numero)->exists()) {
                throw ValidationException::withMessages(['numero' => 'Ya existe una sesión con ese número en la edición.']);
            }
            $sesion = $this->insertar($convocatoria, $campos, $numero === null ? $this->siguienteNumero($convocatoria) : (int) $numero);
            AuditLog::record('sesion_programada', SesionClase::class, $sesion->id,
                ['convocatoria' => $convocatoria->id, 'numero' => $sesion->numero, 'fecha' => $sesion->fecha, 'campos' => array_keys(array_filter($campos, static fn (mixed $v): bool => $v !== null))], $autor->id);

            return $sesion;
        });
    }

    /** @param array<string, mixed> $datos mismos campos que `crear`; solo cambia lo enviado */
    public function actualizar(Request $request, string $identificador, array $datos): SesionClase
    {
        return DB::transaction(function () use ($request, $identificador, $datos): SesionClase {
            [$convocatoria, $sesion] = $this->bloquear($identificador);
            $autor = $this->convocatorias->autorizar($request, 'sesiones.editar');
            $this->comprobarRevision($request, $sesion);
            $this->comprobarVigente($convocatoria);
            abort_unless($sesion->estado === 'programada', 409, 'Solo se edita una sesión programada.');

            // Lo no enviado conserva su valor actual: se completa el conjunto y se valida entero.
            $actuales = [
                'fecha' => $sesion->fecha, 'hora_inicio' => $this->hora($sesion->hora_inicio), 'hora_fin' => $this->hora($sesion->hora_fin),
                'tema' => $sesion->tema, 'docente' => $sesion->docente?->identificador, 'aula' => $sesion->aula?->identificador,
                'plataforma_virtual' => $sesion->plataformaVirtual?->identificador,
            ];
            $campos = $this->resolver($convocatoria, [...$actuales, ...array_intersect_key($datos, $actuales + ['enlace_virtual' => 1, 'numero' => 1])], $sesion);

            if (isset($datos['numero']) && (int) $datos['numero'] !== $sesion->numero) {
                if (SesionClase::query()->where('convocatoria_id', $convocatoria->id)->where('numero', (int) $datos['numero'])->exists()) {
                    throw ValidationException::withMessages(['numero' => 'Ya existe una sesión con ese número en la edición.']);
                }
                $sesion->numero = (int) $datos['numero'];
            }
            // Solo se asigna lo que de verdad cambia, comparando valores NORMALIZADOS: la base devuelve las horas como `HH:MM:SS` y la
            // validación las entrega como `HH:MM`; comparar sin normalizar hacía avanzar la revisión en una edición idéntica (PostgreSQL).
            if ($campos['fecha'] !== (string) $sesion->fecha) {
                $sesion->fecha = $campos['fecha'];
            }
            if ($campos['hora_inicio'] !== $this->hora((string) $sesion->hora_inicio)) {
                $sesion->hora_inicio = $campos['hora_inicio'].':00';
            }
            if ($campos['hora_fin'] !== $this->hora((string) $sesion->hora_fin)) {
                $sesion->hora_fin = $campos['hora_fin'].':00';
            }
            if ($campos['tema'] !== $sesion->tema) {
                $sesion->tema = $campos['tema'];
            }
            foreach (['docente_id', 'aula_id', 'plataforma_virtual_id'] as $columna) {
                if (($campos[$columna] === null ? null : (int) $campos[$columna]) !== ($sesion->{$columna} === null ? null : (int) $sesion->{$columna})) {
                    $sesion->{$columna} = $campos[$columna];
                }
            }
            if (array_key_exists('enlace_virtual', $datos)) {
                $sesion->enlace_virtual = $campos['enlace_virtual'];
            }
            if (! $sesion->isDirty()) {
                return $sesion;
            }

            $cambiados = array_keys($sesion->getDirty());
            $sesion->revision++;
            $sesion->save();
            AuditLog::record('sesion_actualizada', SesionClase::class, $sesion->id, ['convocatoria' => $convocatoria->id, 'revision' => $sesion->revision, 'campos' => $cambiados], $autor->id);

            return $sesion;
        });
    }

    public function cancelar(Request $request, string $identificador, string $motivo): SesionClase
    {
        return DB::transaction(function () use ($request, $identificador, $motivo): SesionClase {
            [$convocatoria, $sesion] = $this->bloquear($identificador);
            $autor = $this->convocatorias->autorizar($request, 'sesiones.cancelar');
            if ($sesion->estado === 'cancelada') {
                return $sesion;
            }
            $this->comprobarRevision($request, $sesion);
            $this->comprobarVigente($convocatoria);
            abort_unless($sesion->estado === 'programada', 409, 'Una sesión realizada no se cancela.');

            $sesion->estado = 'cancelada';
            $sesion->motivo_cancelacion = $motivo;
            $sesion->revision++;
            $sesion->save();
            AuditLog::record('sesion_cancelada', SesionClase::class, $sesion->id, ['convocatoria' => $convocatoria->id, 'revision' => $sesion->revision], $autor->id);

            return $sesion;
        });
    }

    public function marcarRealizada(Request $request, string $identificador): SesionClase
    {
        return DB::transaction(function () use ($request, $identificador): SesionClase {
            [$convocatoria, $sesion] = $this->bloquear($identificador);
            $autor = $this->convocatorias->autorizar($request, 'sesiones.editar');
            if ($sesion->estado === 'realizada') {
                return $sesion;
            }
            $this->comprobarVigente($convocatoria);
            abort_unless($sesion->estado === 'programada', 409, 'Una sesión cancelada no se marca como realizada.');
            abort_if($sesion->fecha > CarbonImmutable::now('America/Lima')->toDateString(), 409, 'Una sesión futura no puede marcarse como realizada.');

            $sesion->estado = 'realizada';
            $sesion->revision++;
            $sesion->save();
            AuditLog::record('sesion_realizada', SesionClase::class, $sesion->id, ['convocatoria' => $convocatoria->id, 'revision' => $sesion->revision], $autor->id);

            return $sesion;
        });
    }

    /**
     * Genera sesiones desde el horario semanal de la edición, entre `desde` y `hasta` (por defecto, las fechas de la edición). Usa la
     * infraestructura de la edición y su docente principal. Omite, sin fallar, lo que ya existe o se cruza, e informa el motivo.
     *
     * @param  array{desde?: ?string, hasta?: ?string}  $opciones
     * @return array{creadas: int, omitidas: list<array{fecha: string, hora_inicio: string, motivo: string}>, omitidas_total: int}
     */
    public function generar(Request $request, string $edicion, array $opciones): array
    {
        return DB::transaction(function () use ($request, $edicion, $opciones): array {
            $convocatoria = $this->convocatorias->bloquear($edicion);
            $autor = $this->convocatorias->autorizar($request, 'sesiones.crear');
            $this->comprobarVigente($convocatoria);
            if (! $convocatoria->tieneInfraestructuraCompleta() || ($convocatoria->aula_id === null && $convocatoria->plataforma_virtual_id === null)) {
                throw ValidationException::withMessages(['edicion' => 'Asigna primero la infraestructura de la edición.']);
            }
            $horarios = $convocatoria->horarios()->get();
            if ($horarios->isEmpty()) {
                throw ValidationException::withMessages(['edicion' => 'La edición no tiene horario semanal definido.']);
            }

            $inicio = CarbonImmutable::parse($convocatoria->fecha_inicio->toDateString());
            $fin = CarbonImmutable::parse($convocatoria->fecha_fin->toDateString());
            $desde = ($opciones['desde'] ?? null) !== null ? CarbonImmutable::parse($opciones['desde'])->max($inicio) : $inicio;
            $hasta = ($opciones['hasta'] ?? null) !== null ? CarbonImmutable::parse($opciones['hasta'])->min($fin) : $fin;
            if ($desde->greaterThan($hasta)) {
                throw ValidationException::withMessages(['desde' => 'El rango no contiene fechas de la edición.']);
            }

            $candidatas = [];
            for ($dia = $desde; $dia->lessThanOrEqualTo($hasta); $dia = $dia->addDay()) {
                foreach ($horarios as $h) {
                    if ($dia->dayOfWeekIso === (int) $h->dia_semana) {
                        $candidatas[] = ['fecha' => $dia->toDateString(), 'hora_inicio' => $this->hora($h->hora_inicio), 'hora_fin' => $this->hora($h->hora_fin)];
                    }
                }
            }
            if (count($candidatas) > self::MAX_GENERADAS) {
                throw ValidationException::withMessages(['hasta' => 'El rango genera más de '.self::MAX_GENERADAS.' sesiones: acórtalo.']);
            }

            $principal = $convocatoria->docentes()->wherePivot('rol', 'principal')->where('docentes.estado', 'activo')->whereNull('docentes.deleted_at')->first();
            $creadas = 0;
            $omitidas = [];
            $numero = $this->siguienteNumero($convocatoria) - 1;
            foreach ($candidatas as $c) {
                $campos = [
                    ...$c, 'tema' => null, 'enlace_virtual' => null, 'docente_id' => $principal?->id,
                    'aula_id' => $convocatoria->aula_id, 'plataforma_virtual_id' => $convocatoria->aula_id === null ? $convocatoria->plataforma_virtual_id : null,
                ];
                if (SesionClase::query()->where('convocatoria_id', $convocatoria->id)->where('estado', '!=', 'cancelada')
                    ->where('fecha', $c['fecha'])->where('hora_inicio', $c['hora_inicio'].':00')->exists()) {
                    $omitidas[] = [...$this->resumenCandidata($c), 'motivo' => 'ya_existe'];

                    continue;
                }
                $cruce = $this->cruce($campos, null);
                if ($cruce !== null) {
                    $omitidas[] = [...$this->resumenCandidata($c), 'motivo' => $cruce];

                    continue;
                }
                $this->insertar($convocatoria, $campos, ++$numero);
                $creadas++;
            }
            AuditLog::record('sesiones_generadas', Convocatoria::class, $convocatoria->id, ['creadas' => $creadas, 'omitidas' => count($omitidas)], $autor->id);

            return ['creadas' => $creadas, 'omitidas' => array_slice($omitidas, 0, self::MAX_OMITIDAS_INFORMADAS), 'omitidas_total' => count($omitidas)];
        });
    }

    /** Descifra el enlace privado. Cada lectura se audita con ids; el enlace no se escribe en la auditoría. */
    public function enlace(Request $request, string $identificador): string
    {
        return DB::transaction(function () use ($request, $identificador): string {
            $sesion = $this->buscar($identificador);
            $autor = $this->convocatorias->autorizar($request, 'sesiones.ver-enlace');
            abort_if($sesion->estado === 'cancelada', 409, 'La sesión está cancelada.');
            abort_unless($sesion->tieneEnlace(), 409, 'La sesión no tiene un enlace virtual.');

            $enlace = (string) $sesion->enlace_virtual;
            AuditLog::record('sesion_enlace_consultado', SesionClase::class, $sesion->id, ['convocatoria' => $sesion->convocatoria_id], $autor->id);

            return $enlace;
        });
    }

    /**
     * Valida y completa los datos de una sesión; devuelve las columnas a guardar. `$actual` es la sesión editada (se excluye de los cruces).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function resolver(Convocatoria $convocatoria, array $datos, ?SesionClase $actual): array
    {
        $fecha = (string) $datos['fecha'];
        $inicio = $this->hora((string) $datos['hora_inicio']);
        $fin = $this->hora((string) $datos['hora_fin']);
        if ($fin <= $inicio) {
            throw ValidationException::withMessages(['hora_fin' => 'La hora de fin debe ser posterior a la de inicio.']);
        }
        if ($fecha < $convocatoria->fecha_inicio->toDateString() || $fecha > $convocatoria->fecha_fin->toDateString()) {
            throw ValidationException::withMessages(['fecha' => 'La fecha debe estar dentro del periodo de la edición.']);
        }

        $aula = $this->lugar($datos['aula'] ?? null, $convocatoria->aula_id, Aula::class, 'aula');
        $plataforma = $this->lugar($datos['plataforma_virtual'] ?? null, $convocatoria->plataforma_virtual_id, PlataformaVirtual::class, 'plataforma_virtual');
        $explicitaAula = ($datos['aula'] ?? null) !== null;
        $explicitaPlataforma = ($datos['plataforma_virtual'] ?? null) !== null;
        if ($explicitaAula && $explicitaPlataforma) {
            throw ValidationException::withMessages(['aula' => 'Una sesión se dicta en un aula o en una plataforma, no en ambas.']);
        }
        // La modalidad manda: presencial usa solo aula; virtual solo plataforma. Lo heredado se admite en cualquiera.
        if ($convocatoria->modalidad === 'presencial') {
            if ($explicitaPlataforma) {
                throw ValidationException::withMessages(['plataforma_virtual' => 'Una edición presencial no usa plataforma virtual.']);
            }
            $plataforma = null;
        } elseif ($convocatoria->modalidad === 'virtual') {
            if ($explicitaAula) {
                throw ValidationException::withMessages(['aula' => 'Una edición virtual no usa aula.']);
            }
            $aula = null;
        } elseif ($explicitaAula) {
            $plataforma = null;
        } elseif ($explicitaPlataforma) {
            $aula = null;
        } elseif ($aula !== null && $plataforma !== null) {
            $plataforma = null;
        }
        if ($aula !== null) {
            if (! $aula->estaActiva()) {
                throw ValidationException::withMessages(['aula' => 'El aula seleccionada está inactiva.']);
            }
            if ($convocatoria->sede_id !== null && $aula->sede_id !== $convocatoria->sede_id) {
                throw ValidationException::withMessages(['aula' => 'El aula debe pertenecer a la sede de la edición.']);
            }
        }
        if ($plataforma !== null && ! $plataforma->estaActiva()) {
            throw ValidationException::withMessages(['plataforma_virtual' => 'La plataforma seleccionada está inactiva.']);
        }

        $enlace = $datos['enlace_virtual'] ?? null;
        if ($enlace !== null && $plataforma === null) {
            throw ValidationException::withMessages(['enlace_virtual' => 'El enlace solo aplica a sesiones en una plataforma virtual.']);
        }

        $docente = null;
        if (($datos['docente'] ?? null) !== null) {
            $docente = Docente::query()->where('identificador', strtolower((string) $datos['docente']))->first();
            if ($docente === null || ! $docente->estaActivo()) {
                throw ValidationException::withMessages(['docente' => 'El docente seleccionado no existe o está inactivo.']);
            }
            if (! DB::table('convocatoria_docentes')->where('convocatoria_id', $convocatoria->id)->where('docente_id', $docente->id)->exists()) {
                throw ValidationException::withMessages(['docente' => 'El docente no está asignado a la edición.']);
            }
        }

        $campos = [
            'fecha' => $fecha, 'hora_inicio' => $inicio, 'hora_fin' => $fin, 'tema' => $datos['tema'] ?? null,
            'docente_id' => $docente?->id, 'aula_id' => $aula?->id, 'plataforma_virtual_id' => $plataforma?->id, 'enlace_virtual' => $enlace,
        ];
        $cruce = $this->cruce($campos, $actual?->id);
        if ($cruce === 'cruce_aula') {
            throw ValidationException::withMessages(['aula' => 'El aula ya está ocupada en ese horario.']);
        }
        if ($cruce === 'cruce_docente') {
            throw ValidationException::withMessages(['docente' => 'El docente ya tiene otra sesión en ese horario.']);
        }

        return $campos;
    }

    /** Aula o plataforma: la indicada (uuid) o, si no se indica, la de la edición. */
    private function lugar(mixed $uuid, ?int $porDefecto, string $modelo, string $campo): Aula|PlataformaVirtual|null
    {
        if ($uuid === null) {
            return $porDefecto === null ? null : $modelo::query()->find($porDefecto);
        }
        $lugar = $modelo::query()->where('identificador', strtolower((string) $uuid))->first();
        if ($lugar === null) {
            throw ValidationException::withMessages([$campo => 'El lugar seleccionado no existe.']);
        }

        return $lugar;
    }

    /**
     * Comprueba cruces con sesiones NO canceladas. Toma antes los bloqueos consultivos (solo PostgreSQL) por aula y docente, siempre en
     * el mismo orden, para que dos altas simultáneas no se crucen.
     *
     * @param  array<string, mixed>  $c
     * @return 'cruce_aula'|'cruce_docente'|null
     */
    private function cruce(array $c, ?int $excluir): ?string
    {
        if (DB::getDriverName() === 'pgsql') {
            $claves = array_filter(['aula:'.($c['aula_id'] ?? ''), 'docente:'.($c['docente_id'] ?? '')], static fn (string $k): bool => ! str_ends_with($k, ':'));
            sort($claves);
            foreach ($claves as $clave) {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['sesion-'.$clave]);
            }
        }

        $solapadas = fn () => SesionClase::query()->where('estado', '!=', 'cancelada')->where('fecha', $c['fecha'])
            ->where('hora_inicio', '<', $c['hora_fin'].':00')->where('hora_fin', '>', $c['hora_inicio'].':00')
            ->when($excluir !== null, fn ($q) => $q->whereKeyNot($excluir));

        if ($c['aula_id'] !== null && $solapadas()->where('aula_id', $c['aula_id'])->exists()) {
            return 'cruce_aula';
        }
        if ($c['docente_id'] !== null && $solapadas()->where('docente_id', $c['docente_id'])->exists()) {
            return 'cruce_docente';
        }

        return null;
    }

    /** @param array<string, mixed> $campos */
    private function insertar(Convocatoria $convocatoria, array $campos, int $numero): SesionClase
    {
        $sesion = new SesionClase(['tema' => $campos['tema']]);
        $sesion->identificador = (string) Str::uuid();
        $sesion->convocatoria_id = $convocatoria->id;
        $sesion->numero = $numero;
        $sesion->fecha = $campos['fecha'];
        $sesion->hora_inicio = $campos['hora_inicio'].':00';
        $sesion->hora_fin = $campos['hora_fin'].':00';
        $sesion->docente_id = $campos['docente_id'];
        $sesion->aula_id = $campos['aula_id'];
        $sesion->plataforma_virtual_id = $campos['plataforma_virtual_id'];
        $sesion->enlace_virtual = $campos['enlace_virtual'];
        $sesion->estado = 'programada';
        $sesion->revision = 1;
        $sesion->save();

        return $sesion;
    }

    private function siguienteNumero(Convocatoria $convocatoria): int
    {
        return (int) SesionClase::query()->where('convocatoria_id', $convocatoria->id)->max('numero') + 1;
    }

    /** @return array{0: Convocatoria, 1: SesionClase} edición → sesión, ambas bloqueadas */
    private function bloquear(string $identificador): array
    {
        $previa = $this->buscar($identificador);
        $convocatoria = Convocatoria::query()->lockForUpdate()->findOrFail($previa->convocatoria_id);
        $sesion = SesionClase::query()->lockForUpdate()->with(['docente', 'aula', 'plataformaVirtual'])->findOrFail($previa->id);

        return [$convocatoria, $sesion];
    }

    private function buscar(string $identificador): SesionClase
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return SesionClase::query()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function comprobarVigente(Convocatoria $convocatoria): void
    {
        abort_unless($convocatoria->estaVigente(), 409, 'Una edición cerrada o cancelada no admite cambios en sus sesiones.');
    }

    private function comprobarRevision(Request $request, SesionClase $sesion): void
    {
        $revision = $request->header('If-Match');
        abort_if($revision === null, 428, 'Envía If-Match obtenido del detalle de la sesión.');
        if (! preg_match('/\A"sesion-[0-9a-f-]{36}-v[1-9][0-9]*"\z/D', $revision)) {
            throw ValidationException::withMessages(['revision' => 'Envía una única revisión fuerte.']);
        }
        abort_unless(hash_equals($sesion->etag(), $revision), 412, 'La sesión cambió. Relee y revisa antes de continuar.');
    }

    private function hora(string $valor): string
    {
        return substr($valor, 0, 5);
    }

    /**
     * @param  array<string, string>  $c
     * @return array{fecha: string, hora_inicio: string}
     */
    private function resumenCandidata(array $c): array
    {
        return ['fecha' => $c['fecha'], 'hora_inicio' => $c['hora_inicio']];
    }
}
