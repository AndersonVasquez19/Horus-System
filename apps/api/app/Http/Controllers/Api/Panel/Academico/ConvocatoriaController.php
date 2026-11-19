<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarConvocatoriaRequest;
use App\Models\Convocatoria;
use App\Services\GestionConvocatorias;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\Panel\Listado;
use App\Support\Panel\PrecondicionesPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Convocatorias y horarios del panel académico. El permiso de cada acción lo declara este controlador
 * (`convocatorias.ver|crear|editar|cerrar`); toda escritura pasa por {@see GestionConvocatorias} y la revisión llega en
 * `_revision` ({@see PrecondicionesPanel}). Los horarios se sustituyen juntos y avanzan la revisión de la convocatoria.
 */
final class ConvocatoriaController extends Controller implements HasMiddleware
{
    private const ORDENABLES = ['inicio' => 'fecha_inicio', 'codigo' => 'codigo', 'creado' => 'created_at'];

    public function __construct(private readonly GestionConvocatorias $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:convocatorias.ver', only: ['index', 'show']),
            new Middleware('permission:convocatorias.crear', only: ['store']),
            // `estado` exige además `convocatorias.cerrar` para cerrar o cancelar: lo comprueba el servicio.
            new Middleware('permission:convocatorias.editar|convocatorias.cerrar', only: ['estado']),
            new Middleware('permission:convocatorias.editar', only: ['update', 'horarios']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', Convocatoria::ESTADOS),
            'vigentes' => $request->boolean('vigentes'),
            'curso_id' => ctype_digit((string) $request->query('curso_id')) ? (int) $request->query('curso_id') : null,
        ];

        $consulta = Convocatoria::query()->with('curso:id,codigo,nombre');
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($consulta, ['codigo', 'nombre'], $filtros['busqueda']);
        }
        if ($filtros['estado'] !== '') {
            $consulta->where('estado', $filtros['estado']);
        }
        if ($filtros['vigentes']) {
            $consulta->whereIn('estado', Convocatoria::VIGENTES);
        }
        if ($filtros['curso_id'] !== null) {
            $consulta->where('curso_id', $filtros['curso_id']);
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, '-inicio');

        return Listado::responder(Listado::paginar($consulta, $request), fn (Convocatoria $c): array => $this->resumen($c), $orden, $filtros);
    }

    public function show(string $identificador): JsonResponse
    {
        return $this->detalle($this->buscar($identificador));
    }

    public function store(GuardarConvocatoriaRequest $request): JsonResponse
    {
        $convocatoria = $this->gestion->crear($request, $request->validated());

        return $this->detalle($this->buscar($convocatoria->identificador), 201, 'La convocatoria se registró correctamente.');
    }

    public function update(GuardarConvocatoriaRequest $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->actualizar($request, $this->buscar($identificador)->identificador, $request->validated());

        return $this->detalle($this->buscar($identificador), 200, 'Los datos de la convocatoria se actualizaron.');
    }

    public function estado(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate(['estado' => ['required', 'string', Rule::in(Convocatoria::ESTADOS)]]);
        $this->gestion->cambiarEstado($request, $this->buscar($identificador)->identificador, $datos['estado']);

        return $this->detalle($this->buscar($identificador), 200, 'El estado de la convocatoria se actualizó.');
    }

    /** Sustituye todos los bloques de horario. `horarios` puede ir vacío para quitarlos todos. */
    public function horarios(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate([
            'horarios' => ['present', 'array', 'max:'.Convocatoria::MAX_HORARIOS],
            'horarios.*' => ['array:dia_semana,hora_inicio,hora_fin'],
            'horarios.*.dia_semana' => ['required', 'integer', 'between:1,7'],
            'horarios.*.hora_inicio' => ['required', 'date_format:H:i'],
            'horarios.*.hora_fin' => ['required', 'date_format:H:i'],
        ]);
        $horarios = array_values($datos['horarios']);
        foreach ($horarios as $i => $bloque) {
            if ($bloque['hora_fin'] <= $bloque['hora_inicio']) {
                throw ValidationException::withMessages(["horarios.{$i}.hora_fin" => 'La hora de fin debe ser posterior a la de inicio.']);
            }
        }
        $this->gestion->sustituirHorarios($request, $this->buscar($identificador)->identificador, $horarios);

        return $this->detalle($this->buscar($identificador), 200, 'El horario se actualizó.');
    }

    private function buscar(string $identificador): Convocatoria
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Convocatoria::query()->with(['curso:id,codigo,nombre', 'horarios'])
            ->where('identificador', strtolower($identificador))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function resumen(Convocatoria $c): array
    {
        return [
            'identificador' => $c->identificador,
            'codigo' => $c->codigo,
            'nombre' => $c->nombre ?? $c->curso?->nombre,
            'nombre_propio' => $c->nombre,
            'curso' => $c->curso === null ? null : ['id' => $c->curso->id, 'codigo' => $c->curso->codigo, 'nombre' => $c->curso->nombre],
            'modalidad' => $c->modalidad,
            'estado' => $c->estado,
            'fecha_inicio' => $c->fecha_inicio->toDateString(),
            'fecha_fin' => $c->fecha_fin->toDateString(),
            'cupos' => ['maximos' => $c->cupos_maximos, 'disponibles' => $c->cupos_disponibles, 'ocupados' => $c->cuposOcupados()],
        ];
    }

    private function detalle(Convocatoria $c, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        $datos = [
            ...$this->resumen($c),
            'horarios' => $c->horarios->map(fn ($h): array => [
                'dia_semana' => (int) $h->dia_semana,
                'hora_inicio' => substr((string) $h->hora_inicio, 0, 5),
                'hora_fin' => substr((string) $h->hora_fin, 0, 5),
            ])->all(),
            'creado_en' => $c->created_at?->toIso8601String(),
            'revision' => $c->etag(),
        ];

        return response()->json(array_filter(['message' => $mensaje, 'data' => $datos], fn ($valor): bool => $valor !== null), $estado)
            ->header('ETag', $c->etag())->header('Cache-Control', 'no-store, private');
    }
}
