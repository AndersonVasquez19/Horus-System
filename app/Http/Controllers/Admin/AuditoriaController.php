<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ListFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
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

    /** Días completos de los filtros de fecha: los registros se guardan en UTC y el personal trabaja en Lima. */
    private const ZONA = 'America/Lima';

    public function index(Request $request): View
    {
        $filtros = $this->filtros($request);
        $query = AuditLog::query()->with('user');
        $this->aplicarFiltros($query, $filtros);

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
        $filtros = $this->filtros($request);
        $archivo = 'auditoria_'.now()->format('Ymd_His').'.json';

        // Tope superior fijado antes de auditar: el id máximo acota lo que se exporta aunque entren
        // registros nuevos (incluido el propio `audit_exported`) mientras se transmite.
        $maxId = (int) AuditLog::query()->max('id');
        $conteo = AuditLog::query()->where('id', '<=', $maxId);
        $this->aplicarFiltros($conteo, $filtros);
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
            $this->aplicarFiltros($consulta, $filtros);

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

    /**
     * Filtros del listado y de la exportación. Una fecha con formato distinto de AAAA-MM-DD (o inexistente,
     * como 2026-02-30) se ignora igual que un filtro vacío.
     *
     * @return array{accion: string, modelo: string, usuario: string, desde: string, hasta: string}
     */
    private function filtros(Request $request): array
    {
        return [
            'accion' => ListFilter::text($request, 'accion'),
            'modelo' => ListFilter::text($request, 'modelo'),
            'usuario' => ListFilter::text($request, 'usuario'),
            'desde' => $this->fecha(ListFilter::text($request, 'desde')),
            'hasta' => $this->fecha(ListFilter::text($request, 'hasta')),
        ];
    }

    private function fecha(string $valor): string
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $valor) !== 1) {
            return '';
        }

        [$anio, $mes, $dia] = array_map('intval', explode('-', $valor));

        return checkdate($mes, $dia, $anio) ? $valor : '';
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @param  array{accion: string, modelo: string, usuario: string, desde: string, hasta: string}  $filtros
     */
    private function aplicarFiltros(Builder $query, array $filtros): void
    {
        if ($filtros['accion'] !== '') {
            $query->whereLike('action', '%'.$filtros['accion'].'%');
        }

        if ($filtros['modelo'] !== '') {
            $query->whereLike('model_type', '%'.$filtros['modelo'].'%');
        }

        if ($filtros['usuario'] !== '') {
            $usuario = $filtros['usuario'];
            $query->whereHas('user', function (Builder $q) use ($usuario): void {
                $q->whereLike('name', "%{$usuario}%")
                    ->orWhereLike('email', "%{$usuario}%");
            });
        }

        // Días completos en hora de Lima convertidos al UTC en que se guarda `created_at`.
        if ($filtros['desde'] !== '') {
            $query->where('created_at', '>=', Carbon::parse($filtros['desde'], self::ZONA)->startOfDay()->utc());
        }

        if ($filtros['hasta'] !== '') {
            $query->where('created_at', '<=', Carbon::parse($filtros['hasta'], self::ZONA)->endOfDay()->utc());
        }
    }
}
