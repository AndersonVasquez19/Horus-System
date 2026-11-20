<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asistencia;
use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Matricula;
use App\Models\SesionClase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asistencia (Horus Academic, Fase 6). Única implementación de las reglas:
 *
 *  - solo de matrículas NO anuladas de la edición de la sesión, y sesión no cancelada, no futura y de edición vigente;
 *  - registrar crea lo que falta; repetir el mismo estado no cambia nada (idempotente); un estado distinto a uno ya registrado exige
 *    CORREGIR (permiso propio y motivo). Un lote entero se aplica o no se aplica;
 *  - orden de bloqueo: edición → sesión; la unicidad (sesión, matrícula) respalda la regla en la base;
 *  - la auditoría guarda ids, cantidades y estados; jamás observaciones ni motivos (texto libre).
 *
 * Porcentaje de asistencia: asistidas (presente + tardanza) ÷ (sesiones REALIZADAS − justificadas) × 100. La falta justificada no
 * penaliza; una sesión no realizada no cuenta. Sin sesiones computables el porcentaje es `null`.
 */
class GestionAsistencias
{
    public const MAX_LOTE = 300;

    public function __construct(private readonly GestionConvocatorias $convocatorias) {}

    /**
     * @param  list<array{matricula_id: positive-int, estado: string, observacion?: ?string}>  $items
     * @return array{registradas: int, sin_cambios: int}
     */
    public function registrar(Request $request, string $sesionIdentificador, array $items): array
    {
        if ($items === [] || count($items) > self::MAX_LOTE) {
            throw ValidationException::withMessages(['asistencias' => 'Envía entre 1 y '.self::MAX_LOTE.' asistencias.']);
        }
        $vistos = [];
        foreach ($items as $i => $item) {
            if (! in_array($item['estado'], Asistencia::ESTADOS, true)) {
                throw ValidationException::withMessages(["asistencias.{$i}.estado" => 'Estado de asistencia no válido.']);
            }
            if (isset($vistos[$item['matricula_id']])) {
                throw ValidationException::withMessages(["asistencias.{$i}.matricula_id" => 'La matrícula se repite en el lote.']);
            }
            $vistos[$item['matricula_id']] = true;
        }

        return DB::transaction(function () use ($request, $sesionIdentificador, $items): array {
            [$convocatoria, $sesion] = $this->bloquear($sesionIdentificador);
            $autor = $this->convocatorias->autorizar($request, 'asistencias.registrar');
            $this->comprobarSesion($convocatoria, $sesion, true);

            $matriculas = $this->matriculasVigentes($convocatoria)->keyBy('id');
            $existentes = Asistencia::query()->where('sesion_clase_id', $sesion->id)->get()->keyBy('matricula_id');
            $crear = [];
            $sinCambios = 0;
            foreach ($items as $i => $item) {
                if (! $matriculas->has($item['matricula_id'])) {
                    throw ValidationException::withMessages(["asistencias.{$i}.matricula_id" => 'La matrícula no está vigente en esta edición.']);
                }
                $actual = $existentes->get($item['matricula_id']);
                if ($actual === null) {
                    $crear[] = $item;
                } elseif ($actual->estado === $item['estado']) {
                    $obs = isset($item['observacion']) ? (trim((string) $item['observacion']) ?: null) : null;
                    if (array_key_exists('observacion', $item) && $actual->observacion !== $obs) {
                        $actual->observacion = $obs;
                        $actual->save();
                    }
                    $sinCambios++;
                } else {
                    abort(409, 'Una asistencia ya registrada solo se cambia con una corrección y su motivo.');
                }
            }
            foreach ($crear as $item) {
                $a = new Asistencia;
                $a->sesion_clase_id = $sesion->id;
                $a->matricula_id = $item['matricula_id'];
                $a->estado = $item['estado'];
                $a->observacion = isset($item['observacion']) ? trim((string) $item['observacion']) ?: null : null;
                $a->registrado_por = $autor->id;
                $a->save();
            }
            if ($crear !== []) {
                AuditLog::record('asistencia_registrada', SesionClase::class, $sesion->id, ['convocatoria' => $convocatoria->id, 'registradas' => count($crear)], $autor->id);
            }

            return ['registradas' => count($crear), 'sin_cambios' => $sinCambios];
        });
    }

    public function corregir(Request $request, string $sesionIdentificador, int $matriculaId, string $estado, string $motivo): Asistencia
    {
        if (! in_array($estado, Asistencia::ESTADOS, true)) {
            throw ValidationException::withMessages(['estado' => 'Estado de asistencia no válido.']);
        }

        return DB::transaction(function () use ($request, $sesionIdentificador, $matriculaId, $estado, $motivo): Asistencia {
            [$convocatoria, $sesion] = $this->bloquear($sesionIdentificador);
            $autor = $this->convocatorias->autorizar($request, 'asistencias.corregir');
            $this->comprobarSesion($convocatoria, $sesion, false);

            $asistencia = Asistencia::query()->lockForUpdate()->where('sesion_clase_id', $sesion->id)->where('matricula_id', $matriculaId)->first();
            abort_if($asistencia === null, 404, 'Esa matrícula no tiene asistencia registrada en la sesión.');
            if ($asistencia->estado === $estado) {
                throw ValidationException::withMessages(['estado' => 'La asistencia ya tiene ese estado.']);
            }

            $anterior = $asistencia->estado;
            $asistencia->estado = $estado;
            $asistencia->motivo_correccion = $motivo;
            $asistencia->registrado_por = $autor->id;
            $asistencia->save();
            AuditLog::record('asistencia_corregida', SesionClase::class, $sesion->id,
                ['convocatoria' => $convocatoria->id, 'matricula' => $matriculaId, 'estado_anterior' => $anterior, 'estado_nuevo' => $estado], $autor->id);

            return $asistencia;
        });
    }

    /**
     * Lista de la sesión: una fila por matrícula vigente de la edición, con su asistencia si ya se registró.
     *
     * @return list<array<string, mixed>>
     */
    public function lista(SesionClase $sesion): array
    {
        $convocatoria = Convocatoria::query()->findOrFail($sesion->convocatoria_id);
        $registradas = Asistencia::query()->where('sesion_clase_id', $sesion->id)->get()->keyBy('matricula_id');

        return array_values($this->matriculasVigentes($convocatoria)->map(function (Matricula $m) use ($registradas): array {
            $a = $registradas->get($m->id);

            return [
                'matricula_id' => $m->id,
                'estudiante' => ['dni' => $m->estudiante->dni ?? '', 'nombre' => trim(($m->estudiante->apellidos ?? '').', '.($m->estudiante->nombres ?? ''))],
                'estado' => $a?->estado,
                'observacion' => $a?->observacion,
                'corregida' => $a?->motivo_correccion !== null,
            ];
        })->values()->all());
    }

    /**
     * Resumen por matrícula vigente: contadores y porcentaje. También lo usa la exportación.
     *
     * @return array{sesiones_realizadas: int, matriculas: list<array{matricula_id: int, estudiante: array{dni: string, nombre: string}, presentes: int, tardanzas: int, ausentes: int, justificadas: int, sin_registro: int, porcentaje: float|null}>}
     */
    public function resumen(Convocatoria $convocatoria): array
    {
        $realizadas = SesionClase::query()->where('convocatoria_id', $convocatoria->id)->where('estado', 'realizada')->pluck('id');
        $conteos = Asistencia::query()->whereIn('sesion_clase_id', $realizadas)->get()->groupBy('matricula_id');

        $filas = $this->matriculasVigentes($convocatoria)->map(function (Matricula $m) use ($conteos, $realizadas): array {
            $por = ($conteos->get($m->id) ?? collect())->countBy('estado');
            $asistidas = (int) ($por['presente'] ?? 0) + (int) ($por['tardanza'] ?? 0);
            $justificadas = (int) ($por['justificada'] ?? 0);
            $base = $realizadas->count() - $justificadas;

            return [
                'matricula_id' => $m->id,
                'estudiante' => ['dni' => $m->estudiante->dni ?? '', 'nombre' => trim(($m->estudiante->apellidos ?? '').', '.($m->estudiante->nombres ?? ''))],
                'presentes' => (int) ($por['presente'] ?? 0),
                'tardanzas' => (int) ($por['tardanza'] ?? 0),
                'ausentes' => (int) ($por['ausente'] ?? 0),
                'justificadas' => $justificadas,
                'sin_registro' => max(0, $realizadas->count() - array_sum($por->all())),
                'porcentaje' => $base > 0 ? round($asistidas / $base * 100, 2) : null,
            ];
        })->values()->all();

        return ['sesiones_realizadas' => $realizadas->count(), 'matriculas' => array_values($filas)];
    }

    /**
     * Matriz para exportar: una columna por sesión no cancelada y una fila por matrícula vigente (letra del estado o vacío).
     *
     * @return array{sesiones: list<array{numero: int, fecha: string}>, filas: list<array{dni: string, nombre: string, estados: list<string>, porcentaje: ?float}>}
     */
    public function matriz(Convocatoria $convocatoria): array
    {
        $sesiones = SesionClase::query()->where('convocatoria_id', $convocatoria->id)->where('estado', '!=', 'cancelada')->orderBy('numero')->get();
        $registradas = Asistencia::query()->whereIn('sesion_clase_id', $sesiones->pluck('id'))->get()->groupBy('matricula_id');
        $letras = ['presente' => 'P', 'ausente' => 'A', 'tardanza' => 'T', 'justificada' => 'J'];
        $resumen = collect($this->resumen($convocatoria)['matriculas'])->keyBy('matricula_id');

        $filas = $this->matriculasVigentes($convocatoria)->map(function (Matricula $m) use ($sesiones, $registradas, $letras, $resumen): array {
            $porSesion = ($registradas->get($m->id) ?? collect())->keyBy('sesion_clase_id');

            return [
                'dni' => $m->estudiante->dni ?? '',
                'nombre' => trim(($m->estudiante->apellidos ?? '').', '.($m->estudiante->nombres ?? '')),
                'estados' => array_values($sesiones->map(fn (SesionClase $s): string => $letras[$porSesion->get($s->id)?->estado] ?? '')->all()),
                'porcentaje' => $resumen->get($m->id)['porcentaje'] ?? null,
            ];
        })->values()->all();

        return ['sesiones' => array_values($sesiones->map(fn (SesionClase $s): array => ['numero' => $s->numero, 'fecha' => $s->fecha])->all()), 'filas' => array_values($filas)];
    }

    /** @return Collection<int, Matricula> matrículas no anuladas ni eliminadas de la edición, por apellidos */
    private function matriculasVigentes(Convocatoria $convocatoria): Collection
    {
        return Matricula::query()->with('estudiante')->where('convocatoria_id', $convocatoria->id)->where('estado', '!=', 'anulada')
            ->get()->sortBy(fn (Matricula $m): string => mb_strtolower(($m->estudiante->apellidos ?? '').' '.($m->estudiante->nombres ?? '')))->values();
    }

    private function comprobarSesion(Convocatoria $convocatoria, SesionClase $sesion, bool $exigirNoFutura): void
    {
        abort_unless($convocatoria->estaVigente(), 409, 'Una edición cerrada o cancelada no admite cambios de asistencia.');
        abort_if($sesion->estado === 'cancelada', 409, 'La sesión está cancelada.');
        abort_if($exigirNoFutura && $sesion->fecha > CarbonImmutable::now('America/Lima')->toDateString(), 409, 'No se registra asistencia de una sesión futura.');
    }

    /** @return array{0: Convocatoria, 1: SesionClase} edición → sesión, ambas bloqueadas */
    private function bloquear(string $identificador): array
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);
        $previa = SesionClase::query()->where('identificador', strtolower($identificador))->firstOrFail();
        $convocatoria = Convocatoria::query()->lockForUpdate()->findOrFail($previa->convocatoria_id);
        $sesion = SesionClase::query()->lockForUpdate()->findOrFail($previa->id);

        return [$convocatoria, $sesion];
    }
}
