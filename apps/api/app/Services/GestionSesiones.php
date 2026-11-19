<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Convocatoria;
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
 *  - una sesión solo lleva fecha, horas y tema: la gestión académica no asigna docentes ni lugares (esos módulos se retiraron);
 *  - orden de bloqueo: edición → sesión;
 *  - la auditoría guarda ids y NOMBRES de campos.
 */
class GestionSesiones
{
    /** Tope de sesiones creadas por una generación desde el horario semanal. */
    public const MAX_GENERADAS = 200;

    private const MAX_OMITIDAS_INFORMADAS = 50;

    public function __construct(private readonly GestionConvocatorias $convocatorias) {}

    /** @param array<string, mixed> $datos `fecha`, `hora_inicio`, `hora_fin` y opcionales `numero`, `tema` */
    public function crear(Request $request, string $edicion, array $datos): SesionClase
    {
        return DB::transaction(function () use ($request, $edicion, $datos): SesionClase {
            $convocatoria = $this->convocatorias->bloquear($edicion);
            $autor = $this->convocatorias->autorizar($request, 'sesiones.crear');
            $this->comprobarVigente($convocatoria);

            $campos = $this->resolver($convocatoria, $datos);
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
                'tema' => $sesion->tema,
            ];
            $campos = $this->resolver($convocatoria, [...$actuales, ...array_intersect_key($datos, $actuales + ['numero' => 1])]);

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
     * Genera sesiones desde el horario semanal de la edición, entre `desde` y `hasta` (por defecto, las fechas de la edición).
     * Omite, sin fallar, lo que ya existe, e informa el motivo.
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

            $creadas = 0;
            $omitidas = [];
            $numero = $this->siguienteNumero($convocatoria) - 1;
            foreach ($candidatas as $c) {
                if (SesionClase::query()->where('convocatoria_id', $convocatoria->id)->where('estado', '!=', 'cancelada')
                    ->where('fecha', $c['fecha'])->where('hora_inicio', $c['hora_inicio'].':00')->exists()) {
                    $omitidas[] = [...$this->resumenCandidata($c), 'motivo' => 'ya_existe'];

                    continue;
                }
                $this->insertar($convocatoria, [...$c, 'tema' => null], ++$numero);
                $creadas++;
            }
            AuditLog::record('sesiones_generadas', Convocatoria::class, $convocatoria->id, ['creadas' => $creadas, 'omitidas' => count($omitidas)], $autor->id);

            return ['creadas' => $creadas, 'omitidas' => array_slice($omitidas, 0, self::MAX_OMITIDAS_INFORMADAS), 'omitidas_total' => count($omitidas)];
        });
    }

    /**
     * Valida y completa los datos de una sesión; devuelve las columnas a guardar.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function resolver(Convocatoria $convocatoria, array $datos): array
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

        return ['fecha' => $fecha, 'hora_inicio' => $inicio, 'hora_fin' => $fin, 'tema' => $datos['tema'] ?? null];
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
        $sesion = SesionClase::query()->lockForUpdate()->findOrFail($previa->id);

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
