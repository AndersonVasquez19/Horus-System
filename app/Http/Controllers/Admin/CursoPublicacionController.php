<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicarCursoRequest;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CursoPublicacionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('permission:cursos.publicar')];
    }

    public function edit(Curso $curso): View
    {
        return view('admin.cursos.publicacion', [
            'curso' => $curso,
            'publicacion' => CursoPublicacion::where('curso_id', $curso->id)->first(),
        ]);
    }

    public function update(PublicarCursoRequest $request, Curso $curso): RedirectResponse
    {
        $datos = $request->safe()->except('confirmacion_publica');
        DB::transaction(function () use ($curso, $datos, $request): void {
            $curso = Curso::withTrashed()->lockForUpdate()->findOrFail($curso->id);
            if ($curso->trashed() || $curso->estado !== 'activo') {
                throw ValidationException::withMessages(['titulo' => 'Solo puedes publicar un curso activo y no eliminado.']);
            }
            CursoPublicacion::updateOrCreate(['curso_id' => $curso->id], [
                ...$datos, 'publicada' => true, 'autorizado_por' => $request->user()->id, 'autorizado_at' => now(),
            ]);
            AuditLog::record('curso_publicado', Curso::class, $curso->id);
        });

        return redirect()->route('admin.cursos.publicacion.edit', $curso)->with('success', 'La ficha pública fue autorizada. Su visibilidad depende de la vigencia indicada y del estado del curso.');
    }

    public function destroy(Curso $curso): RedirectResponse
    {
        DB::transaction(function () use ($curso): void {
            Curso::withTrashed()->lockForUpdate()->findOrFail($curso->id);
            CursoPublicacion::where('curso_id', $curso->id)->update(['publicada' => false]);
            AuditLog::record('curso_despublicado', Curso::class, $curso->id);
        });

        return redirect()->route('admin.cursos.publicacion.edit', $curso)->with('success', 'La ficha dejó de estar publicada.');
    }
}
