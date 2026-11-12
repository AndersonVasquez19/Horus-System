<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\ReportMatriculasRequest;
use App\Models\AuditLog;
use App\Models\Matricula;
use App\Support\BusquedaLike;
use App\Support\TemporaryExportFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Consulta, paginación por lotes y descarga temporal de los reportes de matrículas (extraídas de
 * Admin\ReportController sin cambiar su comportamiento). La usan el panel Blade y la API de los paneles de apps/web.
 */
final class ReportesMatriculas
{
    public const TAMANO_LOTE = 50;

    /** @return Builder<Matricula> */
    public function consulta(ReportMatriculasRequest $request): Builder
    {
        // No hidratar observaciones ni datos personales que el reporte no utiliza.
        $query = Matricula::query()
            ->select(['id', 'estudiante_id', 'curso_id', 'fecha_matricula', 'estado', 'monto_pagado'])
            ->with(['estudiante:id,nombres,apellidos', 'curso:id,codigo,nombre']);
        $filtros = $request->filters();

        if ($request->boolean('eliminados')) {
            $query->onlyTrashed();
        }
        if (isset($filtros['estado'])) {
            $query->where('estado', $filtros['estado']);
        }
        if (isset($filtros['curso_id'])) {
            $query->where('curso_id', $filtros['curso_id']);
        }
        if (isset($filtros['busqueda'])) {
            $busqueda = (string) $filtros['busqueda'];
            $query->where(function (Builder $query) use ($busqueda): void {
                $query->whereHas('estudiante', function (Builder $estudiante) use ($busqueda): void {
                    BusquedaLike::contiene($estudiante, ['dni', 'nombres', 'apellidos'], $busqueda);
                })->orWhereHas('curso', function (Builder $curso) use ($busqueda): void {
                    BusquedaLike::contiene($curso, ['codigo', 'nombre'], $busqueda);
                });
            });
        }

        return $query;
    }

    /**
     * El mismo agregado filtrado alimenta el resumen y el total del paginador.
     *
     * @param  Builder<Matricula>  $query
     * @return object{total: int|string, ingresos: int|float|string}
     */
    public function resumen(Builder $query): object
    {
        return (clone $query)->toBase()->select([])
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN estado = ? THEN monto_pagado ELSE 0 END), 0) as ingresos', ['pagada'])
            ->first();
    }

    /**
     * @param  Builder<Matricula>  $query
     * @return LengthAwarePaginator<int, Matricula>
     */
    public function lote(Builder $query, ReportMatriculasRequest $request, ?int $total = null): LengthAwarePaginator
    {
        $matriculas = (clone $query)->orderByDesc('fecha_matricula')->orderByDesc('id')
            ->paginate(self::TAMANO_LOTE, ['*'], 'lote', (int) ($request->validated('lote') ?? 1), $total)
            ->appends($request->filters());

        abort_if($matriculas->currentPage() > $matriculas->lastPage(), 404, 'El lote solicitado no existe. Vuelve a aplicar los filtros.');

        return $matriculas;
    }

    /**
     * Escribe el reporte en un temporal privado de nombre único, audita y lo entrega para descarga.
     *
     * El nombre interno es un UUID para que dos exportaciones simultáneas no compartan archivo.
     * Si la escritura o la auditoría fallan, el temporal (con datos personales) se borra antes
     * de propagar el error; en el camino feliz lo elimina deleteFileAfterSend().
     *
     * @param  callable(string): mixed  $escribir
     * @param  array<string, mixed>  $filtros
     */
    public function descargaTemporal(string $extension, string $accionAuditoria, callable $escribir, array $filtros = []): BinaryFileResponse
    {
        $nombre = 'reporte-matriculas-'.now()->format('Ymd-His').'.'.$extension;
        $disco = Storage::disk('local');
        if (! $disco->makeDirectory('exports')) {
            throw new RuntimeException('No se pudo crear el directorio temporal de exportación.');
        }

        $archivo = $disco->path('exports/'.Str::uuid()->toString().'.'.$extension);
        $temporal = new TemporaryExportFile($archivo, false);

        try {
            $escribir($archivo);

            clearstatcache(true, $archivo);
            if (! is_file($archivo) || filesize($archivo) === 0) {
                throw new RuntimeException('No se pudo completar el archivo de exportación.');
            }

            AuditLog::record($accionAuditoria, Matricula::class, null, ['filename' => $nombre, 'filtros' => $filtros]);

            return response()->download($temporal, $nombre)->deleteFileAfterSend(true);
        } catch (Throwable $error) {
            if (is_file($archivo)) {
                unlink($archivo);
            }

            throw $error;
        }
    }

    /**
     * Resumen acotado de los filtros usados en la exportación (el texto libre de búsqueda no se guarda).
     *
     * @return array{estado: string|null, curso_id: int|null, eliminados: bool, con_busqueda: bool, lote?: int}
     */
    public function filtrosAuditados(ReportMatriculasRequest $request, ?int $lote = null): array
    {
        $filtros = $request->filters();
        $resumen = [
            'estado' => isset($filtros['estado']) ? (string) $filtros['estado'] : null,
            'curso_id' => isset($filtros['curso_id']) ? (int) $filtros['curso_id'] : null,
            'eliminados' => $request->boolean('eliminados'),
            'con_busqueda' => isset($filtros['busqueda']) && (string) $filtros['busqueda'] !== '',
        ];

        if ($lote !== null) {
            $resumen['lote'] = $lote;
        }

        return $resumen;
    }
}
