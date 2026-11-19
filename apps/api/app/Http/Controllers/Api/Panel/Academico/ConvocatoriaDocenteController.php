<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Docente;
use App\Services\GestionDocentes;
use App\Support\Panel\PrecondicionesPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Docentes asignados a una edición. Consultar exige `docentes.ver`; reemplazar la lista, `convocatorias.editar` y la revisión
 * (`If-Match` / `_revision`). El listado devuelve solo los datos mínimos de cada docente.
 */
final class ConvocatoriaDocenteController extends Controller implements HasMiddleware
{
    public function __construct(private readonly GestionDocentes $gestion) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:docentes.ver', only: ['show']),
            new Middleware('permission:convocatorias.editar', only: ['update']),
        ];
    }

    public function show(string $identificador): JsonResponse
    {
        return $this->respuesta($this->buscar($identificador));
    }

    public function update(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate([
            'docentes' => ['present', 'array', 'max:'.GestionDocentes::MAX_POR_EDICION],
            'docentes.*' => ['array:docente,rol'],
            'docentes.*.docente' => ['required', 'string', 'uuid'],
            'docentes.*.rol' => ['required', 'string', Rule::in(Docente::ROLES)],
        ], [
            'docentes.present' => 'Envía la lista de docentes (puede ir vacía).',
            'docentes.max' => 'Una edición admite hasta '.GestionDocentes::MAX_POR_EDICION.' docentes.',
            'docentes.*.docente.required' => 'Selecciona un docente.',
            'docentes.*.docente.uuid' => 'Un docente seleccionado no es válido.',
            'docentes.*.rol.required' => 'Indica el rol del docente.',
            'docentes.*.rol.in' => 'El rol debe ser principal o apoyo.',
        ]);
        $this->gestion->asignarAEdicion($request, $this->buscar($identificador)->identificador, array_values($datos['docentes']));

        return $this->respuesta($this->buscar($identificador), 200, 'Los docentes de la edición se actualizaron.');
    }

    private function buscar(string $identificador): Convocatoria
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Convocatoria::query()->with('docentes')->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function respuesta(Convocatoria $c, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        $docentes = $c->docentes->sortBy([['pivot.rol', 'asc'], ['apellidos', 'asc']])->map(fn (Docente $d): array => [
            'docente' => $d->identificador,
            'dni' => $d->dni,
            'nombres' => $d->nombres,
            'apellidos' => $d->apellidos,
            'rol' => $d->pivot->rol,
            'estado' => $d->estado,
        ])->values()->all();

        return response()->json(array_filter(['message' => $mensaje, 'data' => ['docentes' => $docentes, 'revision' => $c->etag()]], fn ($v): bool => $v !== null), $estado)
            ->header('ETag', $c->etag())->header('Cache-Control', 'no-store, private');
    }
}
