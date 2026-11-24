<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Estudiante;
use App\Models\ListaEspera;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan de mejora del panel, Fase 3.7: lista de espera por edición.
 *
 * Solo se espera en una edición **llena** y que admita matrículas; si hay cupo se matricula directamente. El orden es el de llegada.
 * La lista no reserva ni toma cupos: cuando se libera uno, el aviso diario (`RecordatoriosService`) avisa al personal y la matrícula
 * se hace por el flujo normal. Quien termina matriculado sale de la lista (se sincroniza al listar y en el aviso diario).
 * Sin permisos nuevos: ver = `convocatorias.ver`; agregar o quitar = `matriculas.crear` (lo comprueba el controlador).
 */
final class GestionListaEspera
{
    /** Tope de personas esperando por edición (evita listas sin fin). */
    public const MAXIMO = 100;

    public function __construct(private readonly GestionConvocatorias $convocatorias) {}

    /**
     * @return array<string, mixed>
     */
    public function listar(string $identificador): array
    {
        $convocatoria = Convocatoria::query()->where('identificador', strtolower($identificador))->firstOrFail();
        $this->sincronizar((int) $convocatoria->id);

        $items = ListaEspera::query()->with('estudiante:id,nombres,apellidos,dni,telefono')
            ->where('convocatoria_id', $convocatoria->id)->where('estado', ListaEspera::ESPERANDO)->orderBy('id')->get();

        return [
            'cupos_disponibles' => (int) $convocatoria->cupos_disponibles,
            'hay_cupo' => $convocatoria->cupos_disponibles > 0,
            'admite_matriculas' => in_array($convocatoria->estado, Convocatoria::MATRICULABLES, true),
            'total' => $items->count(),
            'items' => $items->values()->map(function (ListaEspera $e, int $i): array {
                return [
                    'identificador' => $e->identificador,
                    'posicion' => $i + 1,
                    'estudiante' => $e->estudiante === null ? null : [
                        'id' => (int) $e->estudiante->id,
                        'nombre' => trim($e->estudiante->nombres.' '.$e->estudiante->apellidos),
                        'dni' => $e->estudiante->dni,
                        'telefono' => $e->estudiante->telefono,
                    ],
                    'nota' => $e->nota,
                    'desde' => $e->created_at?->toIso8601String(),
                ];
            })->all(),
        ];
    }

    /** @return array{identificador: string, posicion: int} */
    public function agregar(User $actor, string $identificador, int $estudianteId, ?string $nota): array
    {
        return DB::transaction(function () use ($actor, $identificador, $estudianteId, $nota): array {
            $convocatoria = $this->convocatorias->bloquear($identificador);
            $estudiante = Estudiante::query()->findOrFail($estudianteId);

            abort_unless(in_array($convocatoria->estado, Convocatoria::MATRICULABLES, true), 409, 'La edición no admite matrículas, así que no tiene lista de espera.');
            abort_if($convocatoria->cupos_disponibles > 0, 409, 'La edición aún tiene cupos: matricula directamente.');
            abort_unless($estudiante->estado === 'activo', 409, 'Solo un estudiante activo puede entrar a la lista de espera.');
            abort_if(Matricula::query()->where('convocatoria_id', $convocatoria->id)->where('estudiante_id', $estudiante->id)->where('estado', '!=', 'anulada')->exists(),
                409, 'El estudiante ya está matriculado en esta edición.');

            $this->sincronizar((int) $convocatoria->id);
            $esperando = ListaEspera::query()->where('convocatoria_id', $convocatoria->id)->where('estado', ListaEspera::ESPERANDO);
            abort_if((clone $esperando)->where('estudiante_id', $estudiante->id)->exists(), 409, 'El estudiante ya está en la lista de espera de esta edición.');
            abort_if((clone $esperando)->count() >= self::MAXIMO, 409, 'La lista de espera llegó a su tope de '.self::MAXIMO.' personas.');

            $e = new ListaEspera;
            $e->identificador = (string) Str::uuid();
            $e->convocatoria_id = $convocatoria->id;
            $e->estudiante_id = $estudiante->id;
            $e->nota = $nota !== null && trim($nota) !== '' ? trim($nota) : null;
            $e->registrado_por = $actor->id;
            try {
                $e->save();
            } catch (QueryException) {
                abort(409, 'El estudiante ya está en la lista de espera de esta edición.');
            }
            AuditLog::record('lista_espera_agregada', ListaEspera::class, $e->id, ['convocatoria' => $convocatoria->codigo, 'estudiante' => $estudiante->id], $actor->id);

            return ['identificador' => $e->identificador, 'posicion' => (clone $esperando)->count()];
        });
    }

    public function quitar(User $actor, string $identificador, string $entrada): void
    {
        DB::transaction(function () use ($actor, $identificador, $entrada): void {
            $convocatoria = $this->convocatorias->bloquear($identificador);
            $e = ListaEspera::query()->lockForUpdate()->where('convocatoria_id', $convocatoria->id)->where('identificador', strtolower($entrada))->firstOrFail();
            abort_unless($e->estado === ListaEspera::ESPERANDO, 409, 'Esta persona ya no está esperando.');

            $e->estado = ListaEspera::CANCELADO;
            $e->activa = null;
            $e->cerrada_at = now();
            $e->save();
            AuditLog::record('lista_espera_quitada', ListaEspera::class, $e->id, ['convocatoria' => $convocatoria->codigo, 'estudiante' => $e->estudiante_id], $actor->id);
        });
    }

    /** Pasa a `matriculado` a quien ya tiene matrícula vigente en la edición. Idempotente. Devuelve cuántos cerró. */
    public function sincronizar(?int $convocatoriaId = null): int
    {
        $consulta = ListaEspera::query()->where('estado', ListaEspera::ESPERANDO)
            ->whereExists(fn ($m) => $m->select(DB::raw(1))->from('matriculas')
                ->whereColumn('matriculas.convocatoria_id', 'lista_espera.convocatoria_id')
                ->whereColumn('matriculas.estudiante_id', 'lista_espera.estudiante_id')
                ->where('matriculas.estado', '!=', 'anulada')->whereNull('matriculas.deleted_at'));
        if ($convocatoriaId !== null) {
            $consulta->where('convocatoria_id', $convocatoriaId);
        }

        return $consulta->update(['estado' => ListaEspera::MATRICULADO, 'activa' => null, 'cerrada_at' => now()]);
    }

    /**
     * Ediciones que admiten matrículas, tienen cupo libre y personas esperando (para el aviso diario).
     *
     * @return list<array{codigo: string, esperando: int, cupos: int}>
     */
    public function edicionesConCupoYEspera(): array
    {
        $this->sincronizar();

        $salida = [];
        $ediciones = Convocatoria::query()->whereIn('estado', Convocatoria::MATRICULABLES)->where('cupos_disponibles', '>', 0)
            ->whereExists(fn ($l) => $l->select(DB::raw(1))->from('lista_espera')->whereColumn('lista_espera.convocatoria_id', 'convocatorias.id')->where('lista_espera.estado', ListaEspera::ESPERANDO))
            ->orderBy('id')->limit(50)->get();
        foreach ($ediciones as $c) {
            $salida[] = [
                'codigo' => (string) $c->codigo,
                'esperando' => ListaEspera::query()->where('convocatoria_id', $c->id)->where('estado', ListaEspera::ESPERANDO)->count(),
                'cupos' => (int) $c->cupos_disponibles,
            ];
        }

        return $salida;
    }
}
