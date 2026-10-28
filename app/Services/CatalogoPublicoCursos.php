<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CatalogoPublicoCursos
{
    /** @return list<array{titulo: string, resumen: string, modalidad: string, horario: ?string, inscripcion: ?string, vigente_desde: string, vigente_hasta: string}> */
    public function vigentes(): array
    {
        // Un despliegue sin esta migración nunca convierte datos administrativos en públicos.
        if (! Schema::hasTable('curso_publicaciones')) {
            return [];
        }
        $hoy = now('America/Lima')->toDateString();

        return DB::table('curso_publicaciones as p')->join('cursos as c', 'c.id', '=', 'p.curso_id')
            ->where('p.publicada', true)->where('c.estado', 'activo')->whereNull('c.deleted_at')
            ->whereDate('p.vigente_desde', '<=', $hoy)->whereDate('p.vigente_hasta', '>=', $hoy)
            ->orderBy('p.titulo')->orderBy('p.id')
            ->get(['p.titulo', 'p.resumen', 'p.modalidad', 'p.horario', 'p.inscripcion', 'p.vigente_desde', 'p.vigente_hasta'])
            ->map(fn (object $p): array => [
                'titulo' => (string) $p->titulo, 'resumen' => (string) $p->resumen, 'modalidad' => (string) $p->modalidad,
                'horario' => $p->horario === null ? null : (string) $p->horario,
                'inscripcion' => $p->inscripcion === null ? null : (string) $p->inscripcion,
                'vigente_desde' => substr((string) $p->vigente_desde, 0, 10), 'vigente_hasta' => substr((string) $p->vigente_hasta, 0, 10),
            ])->all();
    }
}
