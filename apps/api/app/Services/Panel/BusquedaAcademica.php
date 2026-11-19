<?php

declare(strict_types=1);

namespace App\Services\Panel;

use App\Models\Convocatoria;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Support\BusquedaLike;

/**
 * Búsqueda global del panel académico: estudiantes, matrículas y ediciones. Cada grupo se incluye SOLO si la
 * persona tiene el permiso de su módulo (el servidor no envía lo que no podría consultar). Devuelve lo mínimo para identificar y
 * enlazar (hasta {@see self::POR_GRUPO} por grupo); nunca montos, notas, enlaces virtuales ni códigos de verificación.
 */
final class BusquedaAcademica
{
    public const POR_GRUPO = 5;

    public const MIN = 2;

    public const MAX = 100;

    /** @return array<string, list<array<string, mixed>>> */
    public function buscar(User $user, string $texto): array
    {
        $texto = trim($texto);
        $grupos = [];
        if (mb_strlen($texto) < self::MIN) {
            return $grupos;
        }
        $texto = mb_substr($texto, 0, self::MAX);

        if ($user->can('estudiantes.ver')) {
            $q = Estudiante::query();
            BusquedaLike::contiene($q, ['dni', 'nombres', 'apellidos'], $texto);
            $grupos['estudiantes'] = $q->orderBy('apellidos')->orderBy('id')->limit(self::POR_GRUPO)->get()->map(fn (Estudiante $e): array => [
                'id' => $e->id, 'texto' => trim($e->apellidos.', '.$e->nombres), 'detalle' => 'DNI '.$e->dni,
            ])->all();
        }

        if ($user->can('matriculas.ver')) {
            $grupos['matriculas'] = Matricula::query()->with(['estudiante:id,nombres,apellidos,dni', 'curso:id,nombre'])
                ->whereHas('estudiante', fn ($e) => BusquedaLike::contiene($e, ['dni', 'nombres', 'apellidos'], $texto))
                ->latest('id')->limit(self::POR_GRUPO)->get()->map(fn (Matricula $m): array => [
                    'id' => $m->id, 'texto' => trim(($m->estudiante->apellidos ?? '').', '.($m->estudiante->nombres ?? '')), 'detalle' => ($m->curso?->nombre ?? 'Curso').' · '.$m->estado,
                ])->all();
        }

        if ($user->can('convocatorias.ver')) {
            $q = Convocatoria::query()->with('curso:id,nombre');
            $q->where(function ($w) use ($texto): void {
                BusquedaLike::contiene($w, ['codigo'], $texto);
                $w->orWhereHas('curso', fn ($c) => BusquedaLike::contiene($c, ['nombre'], $texto));
            });
            $grupos['ediciones'] = $q->orderByDesc('fecha_inicio')->limit(self::POR_GRUPO)->get()->map(fn (Convocatoria $c): array => [
                'identificador' => $c->identificador, 'texto' => $c->codigo, 'detalle' => $c->curso?->nombre ?? '',
            ])->all();
        }

        return $grupos;
    }
}
