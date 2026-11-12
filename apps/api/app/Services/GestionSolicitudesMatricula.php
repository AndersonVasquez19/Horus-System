<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SolicitudMatricula;
use App\Models\User;
use App\Support\FormularioSolicitudMatricula;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GestionSolicitudesMatricula
{
    public function __construct(private readonly CatalogoPublicoCursos $catalogo) {}

    /** @param array<string, mixed> $ficha */
    public function referencia(array $ficha): string
    {
        return hash_hmac('sha256', json_encode($ficha, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @return list<array{valor: string, titulo: string, modalidad: string}> */
    public function cursos(): array
    {
        return array_map(fn (array $ficha): array => [
            'valor' => $this->referencia($ficha), 'titulo' => $ficha['titulo'], 'modalidad' => $ficha['modalidad'],
        ], $this->catalogo->paraWeb()['cursos']);
    }

    /**
     * Cursos publicados con la información editorial que ve el visitante (nunca precio, cupos ni datos administrativos).
     * La referencia `valor` es opaca y se vuelve a resolver contra el catálogo al registrar.
     *
     * @return list<array{valor: string, titulo: string, resumen: string, modalidad: string, horario: ?string, inscripcion: ?string, vigente_hasta: string}>
     */
    public function cursosConDetalle(): array
    {
        return array_map(fn (array $ficha): array => [
            'valor' => $this->referencia($ficha), 'titulo' => $ficha['titulo'], 'resumen' => $ficha['resumen'],
            'modalidad' => $ficha['modalidad'], 'horario' => $ficha['horario'], 'inscripcion' => $ficha['inscripcion'],
            'vigente_hasta' => $ficha['vigente_hasta'],
        ], $this->catalogo->paraWeb()['cursos']);
    }

    /** @param array<string, mixed> $datos */
    public function registrar(array $datos): SolicitudMatricula
    {
        return $this->registrarConEstado($datos)['solicitud'];
    }

    /**
     * Registra la solicitud (nunca una matrícula: no crea estudiante ni matrícula ni toca cupos). Idempotente por la
     * marca del formulario y, además, no duplica una solicitud ABIERTA de la misma persona para el mismo curso.
     * La disponibilidad se resuelve aquí contra el catálogo vigente, no con lo que mostró la web.
     *
     * @param  array<string, mixed>  $datos
     * @return array{solicitud: SolicitudMatricula, repetida: bool}
     */
    public function registrarConEstado(array $datos): array
    {
        $marca = FormularioSolicitudMatricula::leer($datos['marca']);
        if ($marca === null) {
            throw ValidationException::withMessages(['marca' => 'Vuelve a cargar el formulario antes de enviarlo.']);
        }
        $contacto = array_intersect_key($datos, array_flip(['nombres', 'apellidos', 'email', 'telefono', 'mensaje']));
        $contacto['mensaje'] = $contacto['mensaje'] ?? null;
        $menor = filter_var($datos['es_menor'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $contacto['es_menor'] = $menor;
        foreach (['representante_nombre', 'representante_email', 'representante_telefono'] as $campo) {
            $contacto[$campo] = $menor ? ($datos[$campo] ?? null) : null;
        }
        // Orden fijo: una repetición del mismo formulario no depende del orden de las claves HTTP.
        $contenido = [$datos['curso'], $contacto['nombres'], $contacto['apellidos'], $contacto['email'], $contacto['telefono'], $contacto['mensaje']];
        if ($menor) {
            array_push($contenido, true, $contacto['representante_nombre'], $contacto['representante_email'], $contacto['representante_telefono']);
        }
        $huella = hash_hmac('sha256', json_encode($contenido, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $anterior = SolicitudMatricula::where('identificador', $marca['identificador'])->first();
        if ($anterior !== null) {
            return ['solicitud' => $this->comprobarRepeticion($anterior, $huella), 'repetida' => true];
        }
        $ficha = collect($this->cursos())->first(fn (array $curso): bool => hash_equals($curso['valor'], $datos['curso']));
        if ($ficha === null) {
            throw ValidationException::withMessages(['curso' => 'Este curso ya no está disponible en el catálogo. Elige uno de los cursos publicados.']);
        }
        $duplicado = hash_hmac('sha256', json_encode(['dup', $ficha['titulo'], $ficha['modalidad'], mb_strtolower((string) $contacto['email']),
            mb_strtolower((string) $contacto['nombres']), mb_strtolower((string) $contacto['apellidos'])], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($marca, $huella, $duplicado, $ficha, $contacto): array {
            $abierta = SolicitudMatricula::where('huella_duplicado', $duplicado)->whereIn('estado', SolicitudMatricula::ESTADOS_ABIERTOS)->orderBy('id')->first();
            if ($abierta !== null) {
                return ['solicitud' => $abierta, 'repetida' => true];
            }
            // createOrFirst: inserta y, si otra petición con la misma marca ganó la carrera (índice único), devuelve esa fila.
            $solicitud = SolicitudMatricula::createOrFirst(['identificador' => $marca['identificador']], [
                ...$contacto, 'huella' => $huella, 'huella_duplicado' => $duplicado, 'curso_titulo' => $ficha['titulo'],
                'modalidad' => $ficha['modalidad'], 'consentimiento_at' => now(),
            ]);

            if ($solicitud->wasRecentlyCreated) {
                // Sin IP del visitante (en el BFF sería la del servidor de Next) y sin datos personales: solo código y curso.
                AuditLog::create([
                    'user_id' => null, 'action' => 'solicitud_matricula_recibida', 'model_type' => SolicitudMatricula::class,
                    'model_id' => $solicitud->id, 'ip_address' => null, 'created_at' => now(),
                    'changes' => ['codigo' => $solicitud->codigoSeguimiento(), 'curso' => $solicitud->curso_titulo, 'es_menor' => $solicitud->es_menor],
                ]);
            }

            return ['solicitud' => $this->comprobarRepeticion($solicitud, $huella), 'repetida' => ! $solicitud->wasRecentlyCreated];
        });
    }

    private function comprobarRepeticion(SolicitudMatricula $solicitud, string $huella): SolicitudMatricula
    {
        if (! hash_equals($solicitud->huella, $huella)) {
            throw ValidationException::withMessages(['marca' => 'Este formulario ya fue recibido con otros datos. Abre uno nuevo para otra solicitud.']);
        }

        return $solicitud;
    }

    /** @param array{estado: string, nota_revision?: ?string, revision: int|string} $datos */
    public function revisar(string $identificador, array $datos, User $actor): void
    {
        DB::transaction(function () use ($identificador, $datos, $actor): void {
            $solicitud = SolicitudMatricula::where('identificador', $identificador)->lockForUpdate()->firstOrFail();
            if ($solicitud->revision !== (int) $datos['revision']) {
                throw ValidationException::withMessages(['revision' => 'Otra persona revisó esta solicitud. Recarga el listado antes de continuar.']);
            }
            $destinos = match ($solicitud->estado) {
                'pendiente' => ['en_revision', 'rechazada'],
                'en_revision' => ['atendida', 'rechazada'],
                default => [],
            };
            if (! in_array($datos['estado'], $destinos, true)) {
                throw ValidationException::withMessages(['estado' => 'La solicitud no admite ese cambio de estado.']);
            }
            $anterior = $solicitud->estado;
            $solicitud->estado = $datos['estado'];
            $solicitud->nota_revision = $datos['nota_revision'] ?? null;
            $solicitud->revision++;
            $solicitud->revisado_por = $actor->id;
            $solicitud->revisado_at = now();
            $solicitud->save();
            AuditLog::record('solicitud_matricula_revisada', SolicitudMatricula::class, $solicitud->id,
                ['anterior' => $anterior, 'estado' => $solicitud->estado, 'revision' => $solicitud->revision], $actor->id);
        });
    }
}
