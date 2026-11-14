<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ConsultaAuditoria;
use App\Support\Panel\Listado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
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

        return Listado::responder(
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
            ],
            $orden,
            $filtros,
        );
    }

    /**
     * Exportación JSON (más reciente primero) que se entrega al navegador y no se conserva en el servidor. El id máximo
     * acota lo exportado aunque entren registros nuevos (incluido el propio `audit_exported`) mientras se transmite.
     */
    public function exportar(Request $request): StreamedResponse
    {
        $filtros = ConsultaAuditoria::filtros($request);
        $archivo = 'auditoria_'.now()->format('Ymd_His').'.json';

        $maxId = (int) AuditLog::query()->max('id');
        $conteo = AuditLog::query()->where('id', '<=', $maxId);
        ConsultaAuditoria::aplicar($conteo, $filtros);

        AuditLog::record('audit_exported', AuditLog::class, null, [
            'filename' => $archivo,
            'records' => $conteo->count(),
            // El texto buscado por usuario puede ser un nombre o correo: solo se registra si se usó.
            'filtros' => [
                'accion' => $filtros['accion'] !== '' ? $filtros['accion'] : null,
                'modelo' => $filtros['modelo'] !== '' ? $filtros['modelo'] : null,
                'usuario' => $filtros['usuario'] !== '',
                'desde' => $filtros['desde'] !== '' ? $filtros['desde'] : null,
                'hasta' => $filtros['hasta'] !== '' ? $filtros['hasta'] : null,
            ],
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
                    'cambios' => json_encode($log->redactedChanges(), JSON_UNESCAPED_UNICODE),
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
