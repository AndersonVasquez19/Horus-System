<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\User;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Autorización editorial de la ficha pública de un curso (extraída de Admin\CursoPublicacionController sin
 * cambiar su comportamiento). La usan el panel Blade y la API de los paneles de apps/web.
 *
 * Todo ocurre en una transacción que bloquea curso y ficha; la precondición de revisión (ETag) se evalúa bajo
 * ese bloqueo con el cierre `$comprobar(Curso, ?CursoPublicacion)`, que lanza la excepción propia de cada canal.
 */
final class PublicacionCursos
{
    /** Revisión fuerte de la ficha (valor del ETag/`If-Match`). */
    public static function revision(Curso $curso, ?CursoPublicacion $publicacion): string
    {
        return '"'.hash('sha256', serialize([$curso->id, $publicacion?->getAttributes()])).'"';
    }

    /**
     * Autoriza (o actualiza) la ficha. La misma autorización y contenido no renuevan fechas ni duplican auditoría.
     *
     * @param  array<string, mixed>  $datos  campos de la ficha ya validados (sin `confirmacion_publica`)
     * @param  Closure(Curso, ?CursoPublicacion): void  $comprobar
     */
    public function publicar(Curso $curso, array $datos, User $actual, Closure $comprobar): void
    {
        DB::transaction(function () use ($curso, $datos, $actual, $comprobar): void {
            $curso = Curso::withTrashed()->lockForUpdate()->findOrFail($curso->id);
            $autor = $this->autorizar($actual);
            $existente = CursoPublicacion::where('curso_id', $curso->id)->lockForUpdate()->first();
            $comprobar($curso, $existente);

            if ($curso->trashed() || $curso->estado !== 'activo') {
                throw ValidationException::withMessages(['titulo' => 'Solo puedes publicar un curso activo y no eliminado.']);
            }

            $publicacion = $existente ?? new CursoPublicacion(['curso_id' => $curso->id]);
            $publicacion->fill([...$datos, 'publicada' => true, 'autorizado_por' => $autor->id]);
            if ($publicacion->exists && ! $publicacion->isDirty()) {
                return;
            }

            $publicacion->fill(['autorizado_at' => now()])->save();
            AuditLog::record('curso_publicado', Curso::class, $curso->id, [
                'modalidad' => $publicacion->modalidad,
                'vigente_desde' => (string) $publicacion->vigente_desde,
                'vigente_hasta' => (string) $publicacion->vigente_hasta,
            ], $autor->id);
        });
    }

    /** @param  Closure(Curso, ?CursoPublicacion): void  $comprobar */
    public function despublicar(Curso $curso, User $actual, Closure $comprobar): void
    {
        DB::transaction(function () use ($curso, $actual, $comprobar): void {
            $curso = Curso::withTrashed()->lockForUpdate()->findOrFail($curso->id);
            $autor = $this->autorizar($actual);
            $publicacion = CursoPublicacion::where('curso_id', $curso->id)->lockForUpdate()->first();
            $comprobar($curso, $publicacion);

            if ($publicacion === null || ! $publicacion->publicada) {
                return;
            }

            $publicacion->update(['publicada' => false]);
            AuditLog::record('curso_despublicado', Curso::class, $curso->id, ['publicada' => false], $autor->id);
        });
    }

    /** Se relee el usuario: un cambio de rol o permiso entre la petición y el bloqueo debe verse. */
    private function autorizar(User $actual): User
    {
        $autor = $actual->fresh();
        abort_unless($autor !== null && $autor->hasAnyRole(RolesAndPermissionsSeeder::STAFF_ROLES)
            && $autor->can('cursos.publicar'), 403);

        return $autor;
    }
}
