<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarConvocatoriaRequest;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Matricula;
use App\Models\User;
use App\Services\GestionConvocatorias;
use App\Services\GestionPagos;
use App\Support\AuditoriaAcademica;
use App\Support\BusquedaLike;
use App\Support\ListFilter;
use App\Support\Panel\ExportacionListado;
use App\Support\Panel\Listado;
use App\Support\Panel\PrecondicionesPanel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Convocatorias y horarios del panel académico. El permiso de cada acción lo declara este controlador
 * (`convocatorias.ver|crear|editar|cerrar`); toda escritura pasa por {@see GestionConvocatorias} y la revisión llega en
 * `_revision` ({@see PrecondicionesPanel}). Los horarios se sustituyen juntos y avanzan la revisión de la convocatoria.
 */
final class ConvocatoriaController extends Controller implements HasMiddleware
{
    private const LIMITE_EXPORTACION = 5000;

    private const ORDENABLES = ['inicio' => 'fecha_inicio', 'codigo' => 'codigo', 'creado' => 'created_at'];

    public function __construct(
        private readonly GestionConvocatorias $gestion,
        private readonly GestionPagos $pagos,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:convocatorias.ver', only: ['index', 'show', 'exportar', 'calendarioIcs', 'encuestas']),
            new Middleware('permission:matriculas.ver', only: ['listaAulaPdf', 'contactosCsv']),
            new Middleware('permission:convocatorias.crear', only: ['store', 'siguiente']),
            // `estado` exige además `convocatorias.cerrar` para cerrar o cancelar: lo comprueba el servicio.
            new Middleware('permission:convocatorias.editar|convocatorias.cerrar', only: ['estado']),
            new Middleware('permission:convocatorias.editar', only: ['update', 'horarios']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        [$consulta, $filtros, $orden] = $this->consultaFiltrada($request);

        $respuesta = Listado::responder(Listado::paginar($consulta, $request), fn (Convocatoria $c): array => $this->resumen($c), $orden, $filtros);
        $contenido = Listado::contenido($respuesta);
        $contenido['meta']['cursos'] = Curso::withTrashed()
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre'])
            ->map(fn (Curso $c): array => ['id' => $c->id, 'codigo' => $c->codigo, 'nombre' => $c->nombre])
            ->all();

        return response()->json($contenido);
    }

    public function exportar(Request $request): StreamedResponse|BinaryFileResponse
    {
        [$consulta, $filtros] = $this->consultaFiltrada($request);
        $filas = $consulta->limit(self::LIMITE_EXPORTACION)->get()->map(fn (Convocatoria $c): array => [
            $c->codigo,
            $c->nombre ?: ($c->curso->nombre ?? $c->codigo),
            $c->curso ? $c->curso->codigo.' · '.$c->curso->nombre : null,
            $c->modalidad,
            $c->fecha_inicio->toDateString(),
            $c->fecha_fin->toDateString(),
            $c->cupos_disponibles,
            $c->cupos_maximos,
            ucfirst((string) $c->estado),
        ]);

        return ExportacionListado::responder(
            'ediciones',
            'Ediciones',
            ExportacionListado::formato($request),
            ['Código', 'Nombre', 'Curso', 'Modalidad', 'Inicio', 'Fin', 'Cupos disponibles', 'Cupos máximos', 'Estado'],
            $filas,
            'convocatorias_exportadas',
            Convocatoria::class,
            AuditoriaAcademica::actorDe($request),
            ['estado' => $filtros['estado'] ?: null, 'con_busqueda' => $filtros['busqueda'] !== ''],
        );
    }

    /** @return array{0: Builder<Convocatoria>, 1: array<string, mixed>, 2: string} */
    private function consultaFiltrada(Request $request): array
    {
        $filtros = [
            'busqueda' => ListFilter::text($request, 'busqueda'),
            'estado' => ListFilter::in($request, 'estado', Convocatoria::ESTADOS),
            'vigentes' => $request->boolean('vigentes'),
            'curso_id' => ctype_digit((string) $request->query('curso_id')) ? (int) $request->query('curso_id') : null,
            'desde' => ListFilter::date($request, 'desde'),
            'hasta' => ListFilter::date($request, 'hasta'),
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
        if ($filtros['desde'] !== '') {
            $consulta->whereDate('fecha_inicio', '>=', $filtros['desde']);
        }
        if ($filtros['hasta'] !== '') {
            $consulta->whereDate('fecha_inicio', '<=', $filtros['hasta']);
        }
        $orden = Listado::ordenar($consulta, $request, self::ORDENABLES, '-inicio');

        return [$consulta, $filtros, $orden];
    }

    public function show(Request $request, string $identificador): JsonResponse
    {
        return $this->detalle($this->buscar($identificador), 200, null, $request->user());
    }

    public function store(GuardarConvocatoriaRequest $request): JsonResponse
    {
        $convocatoria = $this->gestion->crear($request, $request->validated());

        return $this->detalle($this->buscar($convocatoria->identificador), 201, 'La convocatoria se registró correctamente.', $request->user());
    }

    public function update(GuardarConvocatoriaRequest $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $this->gestion->actualizar($request, $this->buscar($identificador)->identificador, $request->validated());

        return $this->detalle($this->buscar($identificador), 200, 'Los datos de la convocatoria se actualizaron.', $request->user());
    }

    public function estado(Request $request, string $identificador): JsonResponse
    {
        PrecondicionesPanel::aplicar($request);
        $datos = $request->validate(['estado' => ['required', 'string', Rule::in(Convocatoria::ESTADOS)]]);
        $this->gestion->cambiarEstado($request, $this->buscar($identificador)->identificador, $datos['estado']);

        return $this->detalle($this->buscar($identificador), 200, 'El estado de la convocatoria se actualizó.', $request->user());
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

        return $this->detalle($this->buscar($identificador), 200, 'El horario se actualizó.', $request->user());
    }

    /** Crea una nueva edición copiando estructura y horarios de la actual. */
    public function siguiente(Request $request, string $identificador): JsonResponse
    {
        $datos = $request->validate([
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'nombre' => ['nullable', 'string', 'max:120'],
            'cupos_maximos' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'abrir' => ['nullable', 'boolean'],
        ], [
            'fecha_inicio.required' => 'Indica la fecha de inicio.',
            'fecha_fin.required' => 'Indica la fecha de fin.',
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser igual o posterior al inicio.',
        ]);

        $origen = $this->buscar($identificador);
        $nueva = $this->gestion->abrirSiguiente($request, $origen->identificador, $datos);

        return $this->detalle($this->buscar($nueva->identificador), 201, 'La siguiente edición se creó con los mismos horarios.', $request->user());
    }

    /** Descarga archivo .ics (RFC 5545) con las sesiones vigentes de la edición. */
    public function calendarioIcs(string $identificador): Response
    {
        $convocatoria = $this->buscar($identificador);
        $sesiones = \App\Models\SesionClase::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('estado', '!=', 'cancelada')
            ->orderBy('fecha')
            ->orderBy('hora_inicio')
            ->get();

        $cursoNombre = $convocatoria->curso->nombre ?? $convocatoria->codigo;
        $lineas = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Horus Group//Horus Academic//ES',
            'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:'.$this->escaparIcs($cursoNombre.' ('.$convocatoria->codigo.')'),
        ];

        foreach ($sesiones as $s) {
            $fechaLimpia = str_replace('-', '', substr((string) $s->fecha, 0, 10));
            $ini = str_replace(':', '', substr((string) $s->hora_inicio, 0, 5)).'00';
            $fin = str_replace(':', '', substr((string) $s->hora_fin, 0, 5)).'00';
            $titulo = 'Sesión '.$s->numero.': '.$cursoNombre.($s->tema ? ' — '.$s->tema : '');

            $lineas[] = 'BEGIN:VEVENT';
            $lineas[] = 'UID:horus-sesion-'.$s->identificador.'@horusgroup';
            $lineas[] = 'DTSTART;TZID=America/Lima:'.$fechaLimpia.'T'.$ini;
            $lineas[] = 'DTEND;TZID=America/Lima:'.$fechaLimpia.'T'.$fin;
            $lineas[] = 'SUMMARY:'.$this->escaparIcs($titulo);
            $lineas[] = 'DESCRIPTION:'.$this->escaparIcs('Edición '.$convocatoria->codigo.' · Modalidad: '.$convocatoria->modalidad);
            $lineas[] = 'END:VEVENT';
        }
        $lineas[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lineas)."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"calendario-{$convocatoria->codigo}.ics\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Lista imprimible de aula (PDF de 1 hoja) con los matriculados vigentes de la edición. */
    public function listaAulaPdf(Request $request, string $identificador): Response
    {
        $convocatoria = $this->buscar($identificador);
        $alumnos = Matricula::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('estado', '!=', 'anulada')
            ->with('estudiante:id,dni,nombres,apellidos,telefono')
            ->get()
            ->sortBy(fn (Matricula $m): string => ($m->estudiante->apellidos ?? '').' '.($m->estudiante->nombres ?? ''))
            ->values()
            ->map(fn (Matricula $m): array => [
                'dni' => $m->estudiante->dni ?? '—',
                'nombre' => $m->estudiante ? trim($m->estudiante->apellidos.', '.$m->estudiante->nombres) : '—',
                'telefono' => $m->estudiante?->telefono,
            ])
            ->all();

        $pdf = Pdf::loadView('pdf.lista-aula', [
            'convocatoria' => $convocatoria,
            'alumnos' => $alumnos,
            'fecha' => \App\Support\HoraLocal::ahora()->format('d/m/Y H:i'),
        ]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"lista-aula-{$convocatoria->codigo}.pdf\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Exportación CSV de contactos lista para importar a Google Contacts / WhatsApp. */
    public function contactosCsv(string $identificador): StreamedResponse
    {
        $convocatoria = $this->buscar($identificador);
        $matriculas = Matricula::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('estado', '!=', 'anulada')
            ->with('estudiante:id,nombres,apellidos,telefono')
            ->get();

        $prefijo = $convocatoria->codigo;

        return response()->streamDownload(function () use ($matriculas, $prefijo): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Name', 'Phone 1 - Value']);
            foreach ($matriculas as $m) {
                $est = $m->estudiante;
                if ($est === null || ! $est->telefono) {
                    continue;
                }
                $digitos = preg_replace('/\D+/', '', (string) $est->telefono) ?? '';
                if (strlen($digitos) === 9 && str_starts_with($digitos, '9')) {
                    $digitos = '+51'.$digitos;
                }
                $nombre = $prefijo.' '.trim($est->nombres.' '.$est->apellidos);
                if (preg_match('/^[=+\-@]/', $nombre) === 1) {
                    $nombre = "'".$nombre;
                }
                fputcsv($out, [$nombre, $digitos]);
            }
            fclose($out);
        }, "contactos-{$convocatoria->codigo}.csv", [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Resumen agregado de encuestas post-clase de la edición. */
    public function encuestas(string $identificador): JsonResponse
    {
        $convocatoria = $this->buscar($identificador);
        $respondidas = \App\Models\EncuestaCurso::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->whereNotNull('respondida_at')
            ->get();

        $conteos = [1 => 0, 2 => 0, 3 => 0];
        $sugerencias = [];
        foreach ($respondidas as $e) {
            $cal = (int) ($e->calificacion ?? 0);
            if (isset($conteos[$cal])) {
                $conteos[$cal]++;
            }
            if ($e->sugerencia_curso !== null && trim($e->sugerencia_curso) !== '') {
                $sugerencias[] = trim($e->sugerencia_curso);
            }
        }

        return response()->json([
            'data' => [
                'total_respondidas' => $respondidas->count(),
                'triste' => $conteos[1],
                'normal' => $conteos[2],
                'feliz' => $conteos[3],
                'sugerencias' => array_values(array_unique($sugerencias)),
            ],
        ]);
    }

    private function escaparIcs(string $texto): string
    {
        return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $texto);
    }

    private function buscar(string $identificador): Convocatoria
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Convocatoria::query()->with(['curso:id,codigo,nombre,enlace_grupo_whatsapp', 'horarios'])
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

    private function detalle(Convocatoria $c, int $estado = 200, ?string $mensaje = null, ?User $usuario = null): JsonResponse
    {
        $datos = [
            ...$this->resumen($c),
            'curso' => $c->curso === null ? null : [
                'id' => $c->curso->id,
                'codigo' => $c->curso->codigo,
                'nombre' => $c->curso->nombre,
                'enlace_grupo_whatsapp' => \App\Models\Curso::enlaceGrupoVisible($c->curso->enlace_grupo_whatsapp),
            ],
            'horarios' => $c->horarios->map(fn ($h): array => [
                'dia_semana' => (int) $h->dia_semana,
                'hora_inicio' => substr((string) $h->hora_inicio, 0, 5),
                'hora_fin' => substr((string) $h->hora_fin, 0, 5),
            ])->all(),
            'creado_en' => $c->created_at?->toIso8601String(),
            'revision' => $c->etag(),
        ];

        if ($usuario?->can('matriculas.ver') === true) {
            $conPagos = $usuario->can('pagos.ver');
            $matriculas = Matricula::query()
                ->where('convocatoria_id', $c->id)
                ->with([
                    'estudiante:id,dni,nombres,apellidos,email,telefono,deleted_at',
                    ...($conPagos ? ['obligacionPago.transacciones'] : []),
                ])
                ->orderByDesc('id')
                ->get();

            $datos['matriculados'] = $matriculas->map(function (Matricula $m) use ($conPagos): array {
                $obligacion = null;
                if ($conPagos && $m->obligacionPago !== null) {
                    $s = $this->pagos->saldos($m->obligacionPago);
                    $estadoCobro = $s['confirmado'] <= 0 ? 'pendiente' : ($s['confirmado'] >= $s['total'] ? 'pagada' : 'parcial');
                    $obligacion = [
                        'identificador' => $m->obligacionPago->identificador,
                        'estado' => $estadoCobro,
                        'monto_total' => GestionPagos::formato($s['total']),
                        'pagado' => GestionPagos::formato($s['confirmado']),
                        'saldo' => GestionPagos::formato(max(0, $s['total'] - $s['confirmado'])),
                    ];
                }

                return [
                    'id' => $m->id,
                    'fecha' => $m->fecha_matricula->toDateString(),
                    'estado' => $m->estado,
                    'estudiante' => $m->estudiante === null ? null : [
                        'id' => $m->estudiante->id,
                        'dni' => $m->estudiante->dni,
                        'nombre' => trim($m->estudiante->nombres.' '.$m->estudiante->apellidos),
                        'email' => $m->estudiante->email,
                        'telefono' => $m->estudiante->telefono,
                    ],
                    'obligacion' => $obligacion,
                ];
            })->all();
        }

        return response()->json(array_filter(['message' => $mensaje, 'data' => $datos], fn ($valor): bool => $valor !== null), $estado)
            ->header('ETag', $c->etag())->header('Cache-Control', 'no-store, private');
    }
}
