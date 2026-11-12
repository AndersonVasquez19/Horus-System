<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicarCursoRequest;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Services\PublicacionCursos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

class CursoPublicacionController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PublicacionCursos $publicacion) {}

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
        $actual = $request->user();
        abort_unless($actual !== null, 403);
        $this->publicacion->publicar(
            $curso,
            $request->safe()->except('confirmacion_publica'),
            $actual,
            fn (Curso $bloqueado, ?CursoPublicacion $ficha) => $this->comprobarRevision($request, $bloqueado, $ficha),
        );

        return redirect()->route('admin.cursos.publicacion.edit', $curso)->with('success', 'La ficha pública fue autorizada. Su visibilidad depende de la vigencia indicada y del estado del curso.');
    }

    public function destroy(Request $request, Curso $curso): RedirectResponse
    {
        $actual = $request->user();
        abort_unless($actual !== null, 403);
        $this->publicacion->despublicar(
            $curso,
            $actual,
            fn (Curso $bloqueado, ?CursoPublicacion $ficha) => $this->comprobarRevision($request, $bloqueado, $ficha),
        );

        return redirect()->route('admin.cursos.publicacion.edit', $curso)->with('success', 'La ficha dejó de estar publicada.');
    }

    private function revision(Curso $curso, ?CursoPublicacion $publicacion): string
    {
        return PublicacionCursos::revision($curso, $publicacion);
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
