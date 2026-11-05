<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reclamacion;
use App\Models\User;
use App\Services\GestionReclamaciones;
use App\Support\HojaReclamacion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Bandeja administrativa del Libro de Reclamaciones (Sprint 20, E3): consulta e impresión, sin gestión.
 *
 * Permite a Gerencia y personal con permiso `reclamaciones.ver` consultar las quejas
 * y reclamos recibidos, aplicar filtros por tipo, estado, fechas y texto, revisar
 * el detalle completo de cada registro (con su historial y envíos) e imprimir la hoja.
 * Ninguna de estas peticiones GET modifica reclamaciones: la única escritura es la auditoría del acceso.
 */
class ReclamacionController extends Controller implements HasMiddleware
{
    public function __construct(private readonly GestionReclamaciones $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:reclamaciones.ver', only: ['index', 'show', 'hoja']),
        ];
    }

    public function index(Request $request): Response
    {
        /** @var array{busqueda?: string|null, tipo?: string|null, estado?: string|null, desde?: string|null, hasta?: string|null, page?: int|null} $filtros */
        $filtros = $request->validate([
            'busqueda' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', 'string', Rule::in(['queja', 'reclamo'])],
            'estado' => ['nullable', 'string', Rule::in(['abierto', 'respondido', 'concluido_por_acuerdo'])],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', ...($request->filled('desde') ? ['after_or_equal:desde'] : [])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        $consulta = Reclamacion::query();

        $busqueda = trim((string) ($filtros['busqueda'] ?? ''));
        if ($busqueda !== '') {
            $termino = '%'.strtr($busqueda, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            // Escape explícito compartido: SQLite no define el escape implícito de PostgreSQL.
            // Operador y columnas son SQL fijo; el texto personal siempre viaja como parámetro.
            $operador = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $consulta->where(function ($q) use ($termino, $operador) {
                $q->whereRaw("numero {$operador} ? ESCAPE '!'", [$termino])
                    ->orWhereRaw("nombres {$operador} ? ESCAPE '!'", [$termino])
                    ->orWhereRaw("apellidos {$operador} ? ESCAPE '!'", [$termino])
                    ->orWhereRaw("(nombres || ' ' || apellidos) {$operador} ? ESCAPE '!'", [$termino]);
            });
        }

        if (! empty($filtros['tipo'])) {
            $consulta->where('tipo', $filtros['tipo']);
        }

        if (! empty($filtros['estado'])) {
            $consulta->where('estado', $filtros['estado']);
        }

        if (! empty($filtros['desde'])) {
            $consulta->where('recibida_at', '>=', CarbonImmutable::parse($filtros['desde'], 'America/Lima')->startOfDay()->utc());
        }

        if (! empty($filtros['hasta'])) {
            $consulta->where('recibida_at', '<=', CarbonImmutable::parse($filtros['hasta'], 'America/Lima')->endOfDay()->utc());
        }

        $reclamaciones = $consulta->orderByDesc('id')->paginate(15)->withQueryString();

        $metricas = Reclamacion::query()
            ->selectRaw("
                COUNT(*) as total,
                COUNT(CASE WHEN tipo = 'queja' THEN 1 END) as total_quejas,
                COUNT(CASE WHEN tipo = 'reclamo' THEN 1 END) as total_reclamos,
                COUNT(CASE WHEN estado = 'abierto' THEN 1 END) as total_abiertos
            ")
            ->first();

        // Constancia del acceso a la bandeja: solo los nombres de los filtros usados y la página, nunca sus valores.
        $usados = array_values(array_filter(
            ['busqueda', 'tipo', 'estado', 'desde', 'hasta'],
            fn (string $filtro): bool => trim((string) ($filtros[$filtro] ?? '')) !== '',
        ));
        $this->gestion->registrarConsultaBandeja($this->usuario($request), $usados, $reclamaciones->currentPage());

        return response()->view('admin.reclamaciones.index', [
            'reclamaciones' => $reclamaciones,
            'filtros' => $filtros,
            'metricas' => [
                'total' => (int) ($metricas?->getAttribute('total') ?? 0),
                'quejas' => (int) ($metricas?->getAttribute('total_quejas') ?? 0),
                'reclamos' => (int) ($metricas?->getAttribute('total_reclamos') ?? 0),
                'abiertos' => (int) ($metricas?->getAttribute('total_abiertos') ?? 0),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, Reclamacion $reclamacion): Response
    {
        $registro = $this->gestion->consultar($this->usuario($request), $reclamacion->identificador);

        return response()->view('admin.reclamaciones.show', [
            'reclamacion' => $registro,
            // Indicadores de solo lectura: se calculan para mostrarlos, sin persistir nada.
            'avisoPlazo' => $this->gestion->umbralAviso($registro),
            'propuestaVencida' => $this->gestion->propuestaVencidaSinProcesar($registro),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Hoja de reclamación imprimible: el texto oficial es el de {@see HojaReclamacion} (el mismo de la copia al consumidor). */
    public function hoja(Request $request, Reclamacion $reclamacion): Response
    {
        $registro = $this->gestion->imprimir($this->usuario($request), $reclamacion->identificador);

        return response()->view('admin.reclamaciones.hoja', [
            'reclamacion' => $registro,
            'hoja' => HojaReclamacion::cuerpo($registro),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function usuario(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
