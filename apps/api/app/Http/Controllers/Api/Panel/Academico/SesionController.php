<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarSesionRequest;
use App\Models\Convocatoria;
use App\Models\SesionClase;
use App\Services\GestionSesiones;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use App\Support\Panel\PrecondicionesPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Sesiones de clase (`sesiones.ver|crear|editar|cancelar|ver-enlace`). Toda escritura pasa por {@see GestionSesiones}. Ninguna
 * representación incluye el enlace virtual: solo `GET …/enlace` lo devuelve, con permiso propio y auditoría, sin caché.
 */
final class SesionController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['numero' => 'numero', 'fecha' => 'fecha'];

    public function __construct(private readonly GestionSesiones $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:sesiones.ver', only: ['index', 'show']),
            new Middleware('permission:sesiones.crear', only: ['store', 'generar']),
            new Middleware('permission:sesiones.editar', only: ['update', 'realizada']),
            new Middleware('permission:sesiones.cancelar', only: ['cancelar']),
            new Middleware('permission:sesiones.ver-enlace', only: ['enlace']),
        ];
    }

    public function index(Request $request, string $identificador): JsonResponse
    {
        $edicion = $this->edicion($identificador);
        $filtros = [
            'estado' => ListFilter::in($request, 'estado', SesionClase::ESTADOS),
            'desde' => $this->fecha($request, 'desde'),
            'hasta' => $this->fecha($request, 'hasta'),
        ];
        $consulta = SesionClase::query()->with(['docente', 'aula.sede', 'plataformaVirtual'])->where('convocatoria_id', $edicion->id);
        if ($filtros['estado'] !== '') {
            $consulta->where('estado', $filtros['estado']);
        }
        if ($filtros['desde'] !== '') {
            $consulta->where('fecha', '>=', $filtros['desde']);
        }
        if ($filtros['hasta'] !== '') {
            $consulta->where('fecha', '<=', $filtros['hasta']);
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, 'numero');

        return Listado::responder(Listado::paginar($consulta, $request), fn (SesionClase $s): array => $this->representar($s, $edicion), $orden, $filtros)
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(string $sesion): JsonResponse
    {
        return $this->respuesta($this->buscar($sesion), 200);
    }

    public function store(GuardarSesionRequest $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $sesion = $this->gestion->crear($request, $this->edicion($identificador)->identificador, $request->validated());

        return $this->respuesta($this->buscar($sesion->identificador), 201, 'La sesión se programó correctamente.');
    }

    public function update(GuardarSesionRequest $request, string $sesion): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $modelo = $this->gestion->actualizar($request, $this->buscar($sesion)->identificador, $request->validated());

        return $this->respuesta($this->buscar($modelo->identificador), 200, 'La sesión se actualizó.');
    }

    public function cancelar(Request $request, string $sesion): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:200']], [
            'motivo.required' => 'Indica el motivo de la cancelación.',
            'motivo.max' => 'El motivo admite hasta 200 caracteres.',
        ]);
        $modelo = $this->gestion->cancelar($request, $this->buscar($sesion)->identificador, trim($datos['motivo']));

        return $this->respuesta($this->buscar($modelo->identificador), 200, 'La sesión se canceló.');
    }

    public function realizada(Request $request, string $sesion): JsonResponse
    {
        $modelo = $this->gestion->marcarRealizada($request, $this->buscar($sesion)->identificador);

        return $this->respuesta($this->buscar($modelo->identificador), 200, 'La sesión se marcó como realizada.');
    }

    public function generar(Request $request, string $identificador): JsonResponse
    {
        $datos = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ], ['desde.date_format' => 'La fecha inicial debe tener el formato AAAA-MM-DD.', 'hasta.date_format' => 'La fecha final debe tener el formato AAAA-MM-DD.']);
        $resultado = $this->gestion->generar($request, $this->edicion($identificador)->identificador, $datos);

        return response()->json(['message' => $resultado['creadas'].' sesión(es) generada(s).', 'data' => $resultado])->header('Cache-Control', 'no-store, private');
    }

    public function enlace(Request $request, string $sesion): JsonResponse
    {
        $enlace = $this->gestion->enlace($request, $sesion);

        return response()->json(['data' => ['enlace_virtual' => $enlace]])->header('Cache-Control', 'no-store, private');
    }

    private function edicion(string $identificador): Convocatoria
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Convocatoria::query()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function buscar(string $identificador): SesionClase
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return SesionClase::query()->with(['convocatoria', 'docente', 'aula.sede', 'plataformaVirtual'])->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function fecha(Request $request, string $clave): string
    {
        $valor = $request->query($clave);

        return is_string($valor) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $valor) === 1 ? $valor : '';
    }

    private function respuesta(SesionClase $s, int $estado, ?string $mensaje = null): JsonResponse
    {
        return response()->json(array_filter(['message' => $mensaje, 'data' => $this->representar($s, $s->convocatoria)], static fn ($v): bool => $v !== null), $estado)
            ->header('ETag', $s->etag())->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, mixed> sin enlace virtual ni DNI del docente */
    private function representar(SesionClase $s, ?Convocatoria $edicion): array
    {
        return [
            'identificador' => $s->identificador,
            'edicion' => $edicion?->identificador,
            'numero' => $s->numero,
            'fecha' => $s->fecha,
            'hora_inicio' => substr($s->hora_inicio, 0, 5),
            'hora_fin' => substr($s->hora_fin, 0, 5),
            'tema' => $s->tema,
            'estado' => $s->estado,
            'motivo_cancelacion' => $s->motivo_cancelacion,
            'docente' => $s->docente === null ? null : ['identificador' => $s->docente->identificador, 'nombres' => $s->docente->nombres, 'apellidos' => $s->docente->apellidos],
            'aula' => $s->aula === null ? null : ['identificador' => $s->aula->identificador, 'codigo' => $s->aula->codigo, 'nombre' => $s->aula->nombre, 'sede' => $s->aula->sede?->nombre],
            'plataforma_virtual' => $s->plataformaVirtual === null ? null : ['identificador' => $s->plataformaVirtual->identificador, 'nombre' => $s->plataformaVirtual->nombre, 'tipo' => $s->plataformaVirtual->tipo],
            'tiene_enlace' => $s->tieneEnlace(),
            'revision' => $s->etag(),
        ];
    }
}
