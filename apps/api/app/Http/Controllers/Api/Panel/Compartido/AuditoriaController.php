<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\AuditoriaAcademica;
use App\Support\ConsultaAuditoria;
use App\Support\Panel\ConsultaListadoAuditoria;
use App\Support\Panel\ExportacionListado;
use App\Support\Panel\Listado;
use App\Support\Panel\PresentadorAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Auditoría del sistema para los dos paneles. Solo lectura y exportación: nada aquí escribe en `audit_logs` salvo el
 * propio registro de la exportación. Los cambios de cada registro pasan siempre por `redactedChanges()` (secretos y
 * texto de búsqueda enmascarados). Mismos filtros y misma exportación que el Blade (`ConsultaAuditoria`).
 */
final class AuditoriaController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['fecha' => 'created_at'];

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:auditoria.ver', only: ['index']),
            new Middleware('permission:auditoria.exportar', only: ['exportar']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = ConsultaAuditoria::filtros($request);
        $consulta = AuditLog::query()->with('user:id,name,email');
        ConsultaAuditoria::aplicar($consulta, $filtros);
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, '-fecha');

        $respuesta = Listado::responder(
            Listado::paginar($consulta, $request),
            fn (AuditLog $registro): array => [
                'id' => $registro->id,
                'fecha' => $registro->created_at->toIso8601String(),
                'fecha_local' => $registro->created_at->copy()->timezone('America/Lima')->format('d/m/Y H:i:s'),
                'usuario' => $registro->user === null ? null : ['nombre' => $registro->user->name, 'email' => $registro->user->email],
                'accion' => $registro->action,
                'modelo' => $registro->model_type,
                'modelo_id' => $registro->model_id,
                'ip' => $registro->ip_address,
                'cambios' => $registro->changes === null || $registro->changes === [] ? null : $registro->redactedChanges(),
                // Lo que enseña el panel: etiquetas y frases, sin clases, claves internas ni JSON.
                ...PresentadorAuditoria::de($registro),
            ],
            $orden,
            $filtros,
        );

        // Opciones de los filtros «Acción» y «Qué se tocó», con su texto en español (excluyendo módulos retirados).
        $contenido = $respuesta->getData(true);
        $contenido['meta']['acciones'] = collect(PresentadorAuditoria::accionesSelector())
            ->map(fn (string $etiqueta, string $clave): array => ['clave' => $clave, 'etiqueta' => $etiqueta])
            ->sortBy('etiqueta')->values()->all();
        $contenido['meta']['secciones'] = collect(PresentadorAuditoria::modelosSelector())
            ->map(fn (string $etiqueta, string $clave): array => ['clave' => $clave, 'etiqueta' => $etiqueta])
            ->sortBy('etiqueta')->values()->all();

        return response()->json($contenido);
    }

    /**
     * Exportación JSON (por defecto), CSV o XLSX (más reciente primero) que se entrega al navegador y no se conserva en el servidor.
     */
    public function exportar(Request $request): StreamedResponse|BinaryFileResponse
    {
        $filtros = ConsultaAuditoria::filtros($request);
        $formato = strtolower(trim((string) $request->query('formato', $request->input('formato', 'json'))));

        $maxId = (int) AuditLog::query()->max('id');
        $contextoFiltros = [
            'accion' => $filtros['accion'] !== '' ? $filtros['accion'] : null,
            'modelo' => $filtros['modelo'] !== '' ? $filtros['modelo'] : null,
            'usuario' => $filtros['usuario'] !== '',
            'desde' => $filtros['desde'] !== '' ? $filtros['desde'] : null,
            'hasta' => $filtros['hasta'] !== '' ? $filtros['hasta'] : null,
        ];

        if (in_array($formato, ['csv', 'xlsx'], true)) {
            $consulta = AuditLog::query()->with('user')->where('id', '<=', $maxId);
            ConsultaAuditoria::aplicar($consulta, $filtros);

            $filas = $consulta->lazyByIdDesc(500)->map(fn (AuditLog $log): array => [
                $log->id,
                $log->created_at->format('Y-m-d H:i:s'),
                $log->user->name ?? 'Sistema',
                $log->user->email ?? 'N/A',
                $log->action,
                $log->model_type,
                $log->model_id,
                $log->ip_address,
                $log->changes === null || $log->changes === []
                    ? null
                    : json_encode($log->redactedChanges(), JSON_UNESCAPED_UNICODE),
            ]);

            return ExportacionListado::responder(
                'auditoria',
                'Auditoría',
                $formato,
                ['ID', 'Fecha', 'Usuario', 'Correo', 'Acción', 'Módulo', 'Entidad ID', 'IP', 'Cambios'],
                $filas,
                'audit_exported',
                AuditLog::class,
                AuditoriaAcademica::actorDe($request),
                ['filtros' => $contextoFiltros],
            );
        }

        $archivo = 'auditoria_'.now()->format('Ymd_His').'.json';
        $conteo = AuditLog::query()->where('id', '<=', $maxId);
        ConsultaAuditoria::aplicar($conteo, $filtros);

        AuditLog::record('audit_exported', AuditLog::class, null, [
            'filename' => $archivo,
            'records' => $conteo->count(),
            // El texto buscado por usuario puede ser un nombre o correo: solo se registra si se usó.
            'filtros' => $contextoFiltros,
        ]);

        return response()->streamDownload(function () use ($maxId, $filtros): void {
            echo "[\n";
            $primero = true;

            $consulta = AuditLog::query()->with('user')->where('id', '<=', $maxId);
            ConsultaAuditoria::aplicar($consulta, $filtros);

            $consulta->lazyByIdDesc(500)->each(function (AuditLog $log) use (&$primero): void {
                $fila = json_encode([
                    'id' => $log->id,
                    'usuario' => $log->user->name ?? 'Sistema',
                    'correo' => $log->user->email ?? 'N/A',
                    'accion' => $log->action,
                    'modelo' => $log->model_type,
                    'modelo_id' => $log->model_id,
                    'ip' => $log->ip_address,
                    'cambios' => $log->changes === null || $log->changes === [] ? null : $log->redactedChanges(),
                    'fecha' => $log->created_at->format('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE);

                echo ($primero ? '' : ",\n").$fila;
                $primero = false;
            });

            echo "\n]";
        }, $archivo, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
