<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicarCursoRequest;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CursoPublicacionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('permission:cursos.publicar')];
    }

    public function edit(Curso $curso): Response
    {
        $publicacion = CursoPublicacion::where('curso_id', $curso->id)->first();

        return response()->view('admin.cursos.publicacion', [
            'curso' => $curso,
            'publicacion' => $publicacion,
            'revision' => $this->revision($curso, $publicacion),
        ])->header('ETag', $this->revision($curso, $publicacion))->header('Cache-Control', 'no-store, private');
    }

    public function update(PublicarCursoRequest $request, Curso $curso): RedirectResponse
    {
        $datos = $request->safe()->except('confirmacion_publica');
        DB::transaction(function () use ($curso, $datos, $request): void {
            $curso = Curso::withTrashed()->lockForUpdate()->findOrFail($curso->id);
            $autor = $this->autorizarActual($request);
            $publicacion = CursoPublicacion::firstOrNew(['curso_id' => $curso->id]);
            $this->comprobarRevision($request, $curso, $publicacion->exists ? $publicacion : null);
            if ($curso->trashed() || $curso->estado !== 'activo') {
                throw ValidationException::withMessages(['titulo' => 'Solo puedes publicar un curso activo y no eliminado.']);
            }
            $publicacion->fill([...$datos, 'publicada' => true, 'autorizado_por' => $autor->id]);
            // La misma autorización y contenido no renuevan fechas ni duplican auditoría.
            if ($publicacion->exists && ! $publicacion->isDirty()) {
                return;
            }
            $publicacion->fill(['autorizado_at' => now()])->save();
            AuditLog::record('curso_publicado', Curso::class, $curso->id);
        });

        return redirect()->route('admin.cursos.publicacion.edit', $curso)->with('success', 'La ficha pública fue autorizada. Su visibilidad depende de la vigencia indicada y del estado del curso.');
    }

    public function destroy(Request $request, Curso $curso): RedirectResponse
    {
        DB::transaction(function () use ($curso, $request): void {
            $curso = Curso::withTrashed()->lockForUpdate()->findOrFail($curso->id);
            $this->autorizarActual($request);
            $publicacion = CursoPublicacion::where('curso_id', $curso->id)->first();
            $this->comprobarRevision($request, $curso, $publicacion);
            if ($publicacion === null || ! $publicacion->publicada) {
                return;
            }
            $publicacion->update(['publicada' => false]);
            AuditLog::record('curso_despublicado', Curso::class, $curso->id);
        });

        return redirect()->route('admin.cursos.publicacion.edit', $curso)->with('success', 'La ficha dejó de estar publicada.');
    }

    private function revision(Curso $curso, ?CursoPublicacion $publicacion): string
    {
        return '"'.hash('sha256', serialize([$curso->id, $publicacion?->getAttributes()])).'"';
    }

    private function autorizarActual(Request $request): User
    {
        $autor = $request->user()?->fresh();
        abort_unless($autor !== null && $autor->hasAnyRole(RolesAndPermissionsSeeder::STAFF_ROLES)
            && $autor->can('cursos.publicar'), 403);

        return $autor;
    }

    private function comprobarRevision(Request $request, Curso $curso, ?CursoPublicacion $publicacion): void
    {
        $cabecera = $request->header('If-Match');
        $tieneCampo = $request->exists('_revision');
        $revision = $cabecera ?? $request->input('_revision');
        // Se conserva la compatibilidad con clientes anteriores sin precondición.
        if ($cabecera === null && ! $tieneCampo) {
            return;
        }
        if (! is_string($revision) || ! preg_match('/\A"[a-f0-9]{64}"\z/D', $revision)
            || ($cabecera !== null && $tieneCampo && $cabecera !== $request->input('_revision'))) {
            throw ValidationException::withMessages(['revision' => 'Envía una única revisión fuerte obtenida del editor.']);
        }
        if (! hash_equals($this->revision($curso, $publicacion), $revision)) {
            if ($tieneCampo && ! $request->expectsJson()) {
                throw ValidationException::withMessages([
                    'revision' => $request->isMethod('DELETE')
                        ? 'La ficha cambió. No se retiró la publicación. Carga la versión actual y revísala antes de volver a retirarla.'
                        : 'La ficha cambió. Tu texto se conservó y no se guardó. Cópialo antes de cargar la versión actual y revisar de nuevo la publicación.',
                ])->redirectTo(route('admin.cursos.publicacion.edit', $curso));
            }
            abort(412, 'La ficha cambió. Vuelve a abrir el editor antes de continuar.');
        }
    }
}
