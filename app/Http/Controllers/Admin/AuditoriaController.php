<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
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
        $query = AuditLog::query()->with('user');

        if ($request->filled('accion')) {
            $query->where('action', 'like', '%'.$request->input('accion').'%');
        }

        if ($request->filled('modelo')) {
            $query->where('model_type', 'like', '%'.$request->input('modelo').'%');
        }

        if ($request->filled('usuario')) {
            $usuario = trim((string) $request->input('usuario'));
            $query->whereHas('user', function ($q) use ($usuario): void {
                $q->where('name', 'like', "%{$usuario}%")
                    ->orWhere('email', 'like', "%{$usuario}%");
            });
        }

        $registros = $query
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.auditoria.index', [
            'registros' => $registros,
            'accion' => $request->input('accion'),
            'modelo' => $request->input('modelo'),
            'usuario' => $request->input('usuario'),
        ]);
    }

    public function exportar(): StreamedResponse
    {
        $registros = AuditLog::query()->with('user')->latest('created_at')->get()->map(function ($log): array {
            return [
                'id' => $log->id,
                'usuario' => $log->user?->name ?? 'Sistema',
                'correo' => $log->user?->email ?? 'N/A',
                'accion' => $log->action,
                'modelo' => $log->model_type,
                'modelo_id' => $log->model_id,
                'ip' => $log->ip_address,
                'cambios' => json_encode($log->changes ?? []),
                'fecha' => $log->created_at?->format('Y-m-d H:i:s'),
            ];
        })->toArray();

        $archivo = 'auditoria_'.now()->format('Ymd_His').'.json';
        Storage::disk('local')->put('exports/'.$archivo, json_encode($registros, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return response()->streamDownload(function () use ($registros): void {
            echo json_encode($registros, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $archivo, [
            'Content-Type' => 'application/json',
        ]);
    }
}
