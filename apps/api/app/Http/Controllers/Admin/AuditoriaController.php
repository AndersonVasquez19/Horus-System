<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ConsultaAuditoria;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditoriaController extends Controller implements HasMiddleware
{
    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:auditoria.ver', only: ['index']),
            new Middleware('permission:auditoria.exportar', only: ['exportar']),
        ];
    }

    public function index(Request $request): View
    {
        $filtros = ConsultaAuditoria::filtros($request);
        $query = AuditLog::query()->with('user:id,name,email');
        ConsultaAuditoria::aplicar($query, $filtros);

        $registros = $query
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.auditoria.index', [
            'registros' => $registros,
            'accion' => $filtros['accion'],
            'modelo' => $filtros['modelo'],
            'usuario' => $filtros['usuario'],
            'desde' => $filtros['desde'],
            'hasta' => $filtros['hasta'],
        ]);
    }

    /**
     * Exportación administrativa de la auditoría: se entrega al navegador y NO se conserva
     * ninguna copia en el servidor. Los valores sensibles de los cambios van enmascarados.
     * Acepta los mismos filtros que el listado (sin filtros exporta todo, como antes).
     */
    public function exportar(Request $request): StreamedResponse
    {
        $filtros = ConsultaAuditoria::filtros($request);
        $archivo = 'auditoria_'.now()->format('Ymd_His').'.json';

        // Tope superior fijado antes de auditar: el id máximo acota lo que se exporta aunque entren
        // registros nuevos (incluido el propio `audit_exported`) mientras se transmite.
        $maxId = (int) AuditLog::query()->max('id');
        $conteo = AuditLog::query()->where('id', '<=', $maxId);
        ConsultaAuditoria::aplicar($conteo, $filtros);
        $total = $conteo->count();

        AuditLog::record('audit_exported', AuditLog::class, null, [
            'filename' => $archivo,
            'records' => $total,
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
            // Se mantiene el formato histórico (arreglo JSON, más reciente primero) pero se emite por
            // bloques con cursor, sin cargar la tabla completa en memoria.
            echo "[\n";
            $primero = true;

            $consulta = AuditLog::query()->with('user')->where('id', '<=', $maxId);
            ConsultaAuditoria::aplicar($consulta, $filtros);

            $consulta
                ->lazyByIdDesc(500)
                ->each(function (AuditLog $log) use (&$primero): void {
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
        ]);
    }
}
