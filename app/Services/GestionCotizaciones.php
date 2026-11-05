<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CotizacionHistorial;
use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GestionCotizaciones
{
    /**
     * @param  array<string, mixed>  $datos
     * @return array{solicitud: SolicitudCotizacion, repetida: bool}
     */
    public function crear(Request $request, array $datos): array
    {
        $datos = [...['contacto_email' => null, 'contacto_telefono' => null, 'servicio_uuid' => null], ...$datos];
        $clave = $this->clave($request);
        $huella = $this->huella($datos);
        try {
            return DB::transaction(function () use ($request, $datos, $clave, $huella): array {
                $actor = $this->autorizar($request, 'cotizaciones.crear');
                $previa = $this->creacionPrevia($actor->id, $clave);
                if ($previa !== null) {
                    $this->compararHuella($previa->creacion_huella, $huella);

                    return ['solicitud' => $previa, 'repetida' => true];
                }
                $solicitud = new SolicitudCotizacion($datos);
                $this->contactoValido($solicitud);
                $this->asociar($solicitud, $datos['servicio_uuid']);
                $solicitud->identificador = (string) Str::uuid();
                $solicitud->codigo = 'SOL-'.strtoupper($solicitud->identificador);
                $solicitud->estado = 'recibida';
                $solicitud->recibida_at = now();
                $solicitud->creado_por = $actor->id;
                $solicitud->creador_ambito = $actor->id;
                $solicitud->creacion_clave_hash = $clave;
                $solicitud->creacion_huella = $huella;
                $solicitud->revision = 1;
                $solicitud->save();
                $this->registrar($solicitud, $actor, 'creada', ['estado' => 'recibida']);

                return ['solicitud' => $solicitud, 'repetida' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // Tras el rollback, una carrera por la misma clave se reconcilia en otra transacción.
            return DB::transaction(function () use ($request, $clave, $huella): array {
                $actor = $this->autorizar($request, 'cotizaciones.crear');
                $previa = $this->creacionPrevia($actor->id, $clave);
                abort_if($previa === null, 409, 'Conflicto de identidad. Revisa antes de continuar.');
                $this->compararHuella($previa->creacion_huella, $huella);

                return ['solicitud' => $previa, 'repetida' => true];
            });
        }
    }

    /** @param array<string, mixed> $datos */
    public function cambiar(Request $request, string $uuid, string $operacion, array $datos): SolicitudCotizacion
    {
        return DB::transaction(function () use ($request, $uuid, $operacion, $datos): SolicitudCotizacion {
            $solicitud = SolicitudCotizacion::query()->where('identificador', $uuid)->lockForUpdate()->firstOrFail();
            $actor = $this->autorizar($request, $operacion === 'editar' ? 'cotizaciones.editar' : 'cotizaciones.gestionar');
            $this->comprobarRevision($request, $solicitud);
            $metadatos = [];
            $clave = $huella = $nota = null;
            if ($operacion === 'editar') {
                $solicitud->fill($datos);
                $this->contactoValido($solicitud);
                if (array_key_exists('servicio_uuid', $datos)) {
                    $this->asociar($solicitud, $datos['servicio_uuid']);
                }
                $evento = 'informacion_actualizada';
                $metadatos = ['campos' => array_keys($solicitud->getDirty())];
            } elseif ($operacion === 'estado') {
                abort_unless(in_array($datos['estado'], ['recibida', 'en_revision'], true), 409, 'Estado no permitido.');
                abort_if($solicitud->estado === 'en_revision' && $datos['estado'] !== 'en_revision', 409, 'Transición no autorizada.');
                $metadatos = ['anterior' => $solicitud->estado, 'estado' => $datos['estado']];
                $solicitud->estado = $datos['estado'];
                $evento = 'estado_modificado';
            } elseif ($operacion === 'responsable') {
                if ($datos['responsable_id'] !== null) {
                    $responsable = User::query()->find($datos['responsable_id']);
                    if ($responsable === null || ! $responsable->hasRole('admin') || ! $responsable->can('cotizaciones.ver')) {
                        throw ValidationException::withMessages(['responsable_id' => 'Selecciona un administrador autorizado para consultar solicitudes.']);
                    }
                }
                $solicitud->responsable_id = $datos['responsable_id'];
                $evento = 'responsable_asignado';
                $metadatos = ['responsable_id' => $datos['responsable_id']];
            } elseif ($operacion === 'seguimiento') {
                $clave = $this->clave($request);
                $huella = $this->huella($datos);
                $previo = CotizacionHistorial::query()->where('solicitud_id', $solicitud->id)
                    ->where('actor_ambito', $actor->id)->where('clave_hash', $clave)->first();
                if ($previo !== null) {
                    $this->compararHuella($previo->huella, $huella);

                    return $solicitud;
                }
                $nota = $datos['nota'];
                $solicitud->ultimo_seguimiento_at = now();
                if (array_key_exists('proximo_seguimiento', $datos)) {
                    $solicitud->proximo_seguimiento = $datos['proximo_seguimiento'];
                }
                $evento = 'seguimiento_registrado';
            } else {
                throw new \InvalidArgumentException('Operación desconocida.');
            }
            if (! $solicitud->isDirty() && $operacion !== 'seguimiento') {
                return $solicitud;
            }
            abort_if($solicitud->revision >= 2147483647, 409, 'Límite de revisión alcanzado.');
            $solicitud->revision++;
            $solicitud->save();
            $this->registrar($solicitud, $actor, $evento, $metadatos, $nota, $clave, $huella);

            return $solicitud;
        });
    }

    private function autorizar(Request $request, string $permiso): User
    {
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User && $actor->hasRole('admin') && $actor->can($permiso), 403);

        return $actor;
    }

    private function contactoValido(SolicitudCotizacion $solicitud): void
    {
        if (blank($solicitud->contacto_email) && blank($solicitud->contacto_telefono)) {
            throw ValidationException::withMessages(['contacto_email' => 'Indica al menos correo o teléfono de contacto.']);
        }
    }

    private function asociar(SolicitudCotizacion $solicitud, ?string $uuid): void
    {
        if ($solicitud->servicio_uuid !== null) {
            abort_unless($solicitud->servicio_uuid === $uuid, 409, 'La asociación histórica no se sustituye ni se elimina.');

            return;
        }
        if ($uuid === null) {
            return;
        }
        $servicio = ServicioTecnologico::query()->where('identificador_publico', $uuid)->lockForUpdate()->first();
        if ($servicio === null) {
            throw ValidationException::withMessages(['servicio_uuid' => 'Selecciona un servicio existente.']);
        }
        $solicitud->servicio_id = $servicio->id;
        $solicitud->servicio_uuid = $servicio->identificador_publico;
        // Alta privada manual: snapshot administrativo, nunca inferido de una publicación pública.
        $solicitud->servicio_nombre_historico = $servicio->nombre;
    }

    private function comprobarRevision(Request $request, SolicitudCotizacion $solicitud): void
    {
        $etag = $request->header('If-Match');
        abort_if($etag === null, 428, 'Envía If-Match obtenido del detalle privado.');
        if (! preg_match('/\A"cotizacion-[0-9a-f-]{36}-v[1-9][0-9]*"\z/D', $etag)) {
            throw ValidationException::withMessages(['revision' => 'Envía una única revisión fuerte.']);
        }
        abort_unless(hash_equals($solicitud->etag(), $etag), 412, 'La solicitud cambió. Relee y revisa antes de continuar.');
    }

    private function clave(Request $request): string
    {
        $clave = $request->header('Idempotency-Key');
        if (! is_string($clave) || ! Str::isUuid($clave)) {
            throw ValidationException::withMessages(['idempotencia' => 'Envía Idempotency-Key como UUID aleatorio por intención.']);
        }

        return hash('sha256', strtolower($clave));
    }

    /** @param array<string, mixed> $datos */
    private function huella(array $datos): string
    {
        ksort($datos);
        $secreto = config('app.key');
        abort_unless(is_string($secreto) && $secreto !== '', 503, 'Configuración de integridad no disponible.');

        return hash_hmac('sha256', json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $secreto);
    }

    private function compararHuella(string $guardada, string $actual): void
    {
        abort_unless(hash_equals($guardada, $actual), 409, 'La clave ya identifica otra intención. No reenvíes con datos distintos.');
    }

    private function creacionPrevia(int $actor, string $clave): ?SolicitudCotizacion
    {
        return SolicitudCotizacion::query()->where('creador_ambito', $actor)->where('creacion_clave_hash', $clave)->lockForUpdate()->first();
    }

    /** @param array<string, mixed> $metadatos */
    private function registrar(SolicitudCotizacion $solicitud, User $actor, string $evento, array $metadatos, ?string $nota = null, ?string $clave = null, ?string $huella = null): void
    {
        CotizacionHistorial::query()->create([
            'solicitud_id' => $solicitud->id, 'revision' => $solicitud->revision, 'evento' => $evento,
            'actor_id' => $actor->id, 'actor_ambito' => $actor->id, 'metadatos' => $metadatos,
            'nota' => $nota, 'clave_hash' => $clave, 'huella' => $huella, 'created_at' => now(),
        ]);
        AuditLog::record('cotizacion_'.$evento, SolicitudCotizacion::class, $solicitud->id,
            ['revision' => $solicitud->revision, ...$metadatos], $actor->id);
    }
}
