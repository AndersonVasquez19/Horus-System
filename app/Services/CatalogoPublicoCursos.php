<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class CatalogoPublicoCursos
{
    /** @return list<array{titulo: string, resumen: string, modalidad: string, horario: ?string, inscripcion: ?string, vigente_desde: string, vigente_hasta: string}> */
    public function vigentes(): array
    {
        return $this->consultar(false);
    }

    /**
     * Contrato de lectura para una conexión futura de la web; no es un endpoint.
     *
     * @return array{version: string, habilitado: bool, total: int, cursos: list<array{titulo: string, resumen: string, modalidad: string, horario: ?string, inscripcion: ?string, vigente_desde: string, vigente_hasta: string}>}
     */
    public function paraWeb(): array
    {
        $habilitado = (bool) config('catalogo-publico.enabled', false);
        $fichas = $habilitado ? $this->consultar(true) : [];

        return ['version' => '1.0', 'habilitado' => $habilitado, 'total' => count($fichas), 'cursos' => $fichas];
    }

    /** @return list<array{titulo: string, resumen: string, modalidad: string, horario: ?string, inscripcion: ?string, vigente_desde: string, vigente_hasta: string}> */
    private function consultar(bool $autorizacionActual): array
    {
        // Un despliegue sin esta migración nunca convierte datos administrativos en públicos.
        if (! Schema::hasTable('curso_publicaciones')) {
            return [];
        }
        $hoy = now('America/Lima')->toDateString();

        $consulta = DB::table('curso_publicaciones as p')->join('cursos as c', 'c.id', '=', 'p.curso_id')
            ->where('p.publicada', true)->where('c.estado', 'activo')->whereNull('c.deleted_at')
            ->whereDate('p.vigente_desde', '<=', $hoy)->whereDate('p.vigente_hasta', '>=', $hoy)
            ->orderBy('p.titulo')->orderBy('p.id');
        if ($autorizacionActual) {
            // La futura web exige la misma procedencia vigente que el exportador del Sprint 16.
            // vigentes() conserva su contrato anterior para no cambiar el chatbot PHP.
            try {
                $autores = User::permission('cursos.publicar')->select('users.id');
            } catch (PermissionDoesNotExist) {
                return [];
            }
            $consulta->whereNotNull('p.autorizado_at')->where('p.autorizado_at', '<=', now())
                ->whereIn('p.autorizado_por', $autores);
        }

        return $consulta->get(['p.titulo', 'p.resumen', 'p.modalidad', 'p.horario', 'p.inscripcion', 'p.vigente_desde', 'p.vigente_hasta'])
            ->map(fn (object $p): array => [
                'titulo' => (string) $p->titulo, 'resumen' => (string) $p->resumen, 'modalidad' => (string) $p->modalidad,
                'horario' => $p->horario === null ? null : (string) $p->horario,
                'inscripcion' => $p->inscripcion === null ? null : (string) $p->inscripcion,
                'vigente_desde' => substr((string) $p->vigente_desde, 0, 10), 'vigente_hasta' => substr((string) $p->vigente_hasta, 0, 10),
            ])->all();
    }
}
