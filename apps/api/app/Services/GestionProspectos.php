<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Prospecto;
use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Prospectos del panel de servicios (Ola 3, docs/ola3-diseno-datos.md §3.2).
 *
 * Única implementación de las reglas: bloqueo de fila, revisión fuerte `If-Match` (428/412), rol y permiso releídos con
 * datos vigentes, transiciones de estado cerradas y conversión a cliente en una sola transacción e idempotente.
 * La auditoría guarda revisión, estados e indicadores, nunca nombre, correo, teléfono, empresa ni motivos.
 */
class GestionProspectos
{
    /** @param array<string, mixed> $datos */
    public function crear(Request $request, array $datos): Prospecto
    {
        return DB::transaction(function () use ($request, $datos): Prospecto {
            $autor = $this->autorizar($request, 'prospectos.crear');

            $prospecto = new Prospecto(self::campos($datos));
            $prospecto->identificador = (string) Str::uuid();
            $prospecto->estado = 'nuevo';
            $prospecto->responsable_id = $autor->id;
            $prospecto->servicio_interes_id = $this->servicioInteres($datos['servicio_interes'] ?? null);
            $prospecto->revision = 1;
            $prospecto->save();
            AuditLog::record('prospecto_creado', Prospecto::class, $prospecto->id, ['revision' => 1, 'origen' => $prospecto->origen], $autor->id);

            return $prospecto;
        });
    }

    /**
     * Prospecto a partir de una solicitud de cotización. Una solicitud da un solo prospecto: repetir la operación
     * devuelve el mismo (`repetido`). Hace falta poder crear prospectos y consultar cotizaciones.
     *
     * @return array{prospecto: Prospecto, repetido: bool}
     */
    public function desdeCotizacion(Request $request, string $identificadorSolicitud): array
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificadorSolicitud) === 1, 404);

        try {
            return DB::transaction(function () use ($request, $identificadorSolicitud): array {
                $autor = $this->autorizar($request, 'prospectos.crear');
                abort_unless($autor->can('cotizaciones.ver'), 403);
                $solicitud = SolicitudCotizacion::query()->lockForUpdate()->where('identificador', strtolower($identificadorSolicitud))->firstOrFail();

                $previo = Prospecto::query()->withTrashed()->where('solicitud_cotizacion_id', $solicitud->id)->first();
                if ($previo !== null) {
                    return ['prospecto' => $previo, 'repetido' => true];
                }

                $prospecto = new Prospecto([
                    'nombre_contacto' => $solicitud->contacto_nombre,
                    'email' => is_string($solicitud->contacto_email) ? strtolower(trim($solicitud->contacto_email)) : null,
                    'telefono' => $solicitud->contacto_telefono,
                    'origen' => 'cotizacion',
                ]);
                $prospecto->identificador = (string) Str::uuid();
                $prospecto->estado = 'nuevo';
                $prospecto->responsable_id = $autor->id;
                $prospecto->servicio_interes_id = $solicitud->servicio_id;
                $prospecto->solicitud_cotizacion_id = $solicitud->id;
                $prospecto->revision = 1;
                $prospecto->save();
                AuditLog::record('prospecto_creado', Prospecto::class, $prospecto->id, ['revision' => 1, 'origen' => 'cotizacion'], $autor->id);

                return ['prospecto' => $prospecto, 'repetido' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // Carrera con otra petición idéntica: gana la que confirmó primero.
            $solicitud = SolicitudCotizacion::query()->where('identificador', strtolower($identificadorSolicitud))->firstOrFail();

            return ['prospecto' => Prospecto::query()->where('solicitud_cotizacion_id', $solicitud->id)->firstOrFail(), 'repetido' => true];
        }
    }

    /** @param array<string, mixed> $datos */
    public function actualizar(Request $request, string $identificador, array $datos): Prospecto
    {
        return DB::transaction(function () use ($request, $identificador, $datos): Prospecto {
            $prospecto = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'prospectos.editar');
            $this->comprobarRevision($request, $prospecto);
            abort_if($prospecto->estado === 'convertido', 409, 'Un prospecto convertido ya no se edita: los datos viven en el cliente.');

            $campos = self::campos($datos);
            if ($prospecto->origen === 'cotizacion') {
                // Lo fijó el sistema al crearlo desde una solicitud: no se cambia a mano.
                unset($campos['origen']);
            }
            $prospecto->fill($campos);
            if (array_key_exists('servicio_interes', $datos)) {
                $prospecto->servicio_interes_id = $this->servicioInteres($datos['servicio_interes']);
            }
            if (! $prospecto->isDirty()) {
                return $prospecto;
            }

            $prospecto->revision++;
            $prospecto->save();
            AuditLog::record('prospecto_actualizado', Prospecto::class, $prospecto->id, ['revision' => $prospecto->revision], $autor->id);

            return $prospecto;
        });
    }

    /** Avanza, descarta o reabre. `convertido` no se pide aquí: se alcanza con {@see convertir()}. */
    public function cambiarEstado(Request $request, string $identificador, string $estado, ?string $motivo): Prospecto
    {
        if (! in_array($estado, Prospecto::ESTADOS, true)) {
            throw ValidationException::withMessages(['estado' => 'Estado no permitido.']);
        }
        if ($estado === 'convertido') {
            throw ValidationException::withMessages(['estado' => 'Para convertir un prospecto usa la conversión a cliente.']);
        }

        return DB::transaction(function () use ($request, $identificador, $estado, $motivo): Prospecto {
            $prospecto = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'prospectos.editar');
            $this->comprobarRevision($request, $prospecto);

            if ($prospecto->estado === $estado) {
                return $prospecto;
            }
            abort_unless(in_array($estado, Prospecto::TRANSICIONES[$prospecto->estado] ?? [], true), 409, 'Transición no permitida desde el estado actual.');
            if ($estado === 'descartado' && blank($motivo)) {
                throw ValidationException::withMessages(['motivo_descarte' => 'Indica el motivo del descarte.']);
            }

            $anterior = $prospecto->estado;
            $prospecto->estado = $estado;
            $prospecto->motivo_descarte = $estado === 'descartado' ? $motivo : null;
            $prospecto->revision++;
            $prospecto->save();
            AuditLog::record('prospecto_estado_actualizado', Prospecto::class, $prospecto->id,
                ['revision' => $prospecto->revision, 'anterior' => $anterior, 'estado' => $estado], $autor->id);

            return $prospecto;
        });
    }

    /** Cambia el responsable. Solo puede serlo un administrador que pueda ver prospectos. */
    public function asignarResponsable(Request $request, string $identificador, ?int $responsableId): Prospecto
    {
        return DB::transaction(function () use ($request, $identificador, $responsableId): Prospecto {
            $prospecto = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'prospectos.editar');
            $this->comprobarRevision($request, $prospecto);

            if ($responsableId !== null) {
                $responsable = User::query()->find($responsableId);
                if (! $responsable instanceof User || ! $responsable->hasRole('admin') || ! $responsable->can('prospectos.ver')) {
                    throw ValidationException::withMessages(['responsable_id' => 'El responsable no es válido.']);
                }
            }
            if ($prospecto->responsable_id === $responsableId) {
                return $prospecto;
            }
            $prospecto->responsable_id = $responsableId;
            $prospecto->revision++;
            $prospecto->save();
            AuditLog::record('prospecto_responsable_actualizado', Prospecto::class, $prospecto->id, ['revision' => $prospecto->revision], $autor->id);

            return $prospecto;
        });
    }

    /**
     * Convierte un prospecto calificado en cliente: vincula uno existente (`$clienteUuid`, hace falta `clientes.ver`) o
     * crea uno con sus datos (hace falta `clientes.crear`). Idempotente: sobre un prospecto ya convertido devuelve el
     * mismo resultado sin cambiar nada. Nunca se fusiona con un cliente existente por coincidencia de correo.
     */
    public function convertir(Request $request, string $identificador, ?string $clienteUuid): Prospecto
    {
        return DB::transaction(function () use ($request, $identificador, $clienteUuid): Prospecto {
            $prospecto = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'prospectos.convertir');

            if ($prospecto->estado === 'convertido') {
                return $prospecto;
            }
            $this->comprobarRevision($request, $prospecto);
            abort_unless($prospecto->estado === 'calificado', 409, 'Solo se convierte un prospecto calificado.');

            if ($clienteUuid !== null) {
                abort_unless($autor->can('clientes.ver'), 403);
                $cliente = Cliente::query()->lockForUpdate()->where('identificador', strtolower($clienteUuid))->first();
                if ($cliente === null) {
                    throw ValidationException::withMessages(['cliente' => 'Selecciona un cliente existente.']);
                }
                $creado = false;
            } else {
                $cliente = $this->crearCliente($request, $prospecto);
                $creado = true;
            }

            $prospecto->cliente_id = $cliente->id;
            $prospecto->estado = 'convertido';
            $prospecto->motivo_descarte = null;
            $prospecto->revision++;
            $prospecto->save();
            AuditLog::record('prospecto_convertido', Prospecto::class, $prospecto->id,
                ['revision' => $prospecto->revision, 'cliente_creado' => $creado], $autor->id);

            return $prospecto;
        });
    }

    private function crearCliente(Request $request, Prospecto $prospecto): Cliente
    {
        $correo = is_string($prospecto->email) ? strtolower(trim($prospecto->email)) : null;
        if ($correo !== null && $correo !== '' && Cliente::query()->withTrashed()->where('email', $correo)->exists()) {
            throw ValidationException::withMessages(['cliente' => 'Ya existe un cliente con ese correo: vincula el prospecto a ese cliente.']);
        }

        $empresa = filled($prospecto->empresa);
        $datos = array_filter([
            'tipo' => $empresa ? 'empresa' : 'persona',
            'nombre' => $empresa ? $prospecto->empresa : $prospecto->nombre_contacto,
            'email' => $correo,
            'telefono' => $prospecto->telefono,
        ], fn (mixed $valor): bool => $valor !== null && $valor !== '');
        if ($empresa) {
            // La persona de contacto del prospecto pasa a ser el contacto principal de la empresa.
            $datos['contactos'] = [['nombre' => $prospecto->nombre_contacto, 'email' => $correo, 'telefono' => $prospecto->telefono, 'principal' => true]];
        }

        return app(GestionClientes::class)->crear($request, $datos);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private static function campos(array $datos): array
    {
        return array_intersect_key($datos, array_flip(['nombre_contacto', 'empresa', 'email', 'telefono', 'origen', 'proximo_seguimiento']));
    }

    private function servicioInteres(mixed $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $servicio = ServicioTecnologico::query()->where('identificador_publico', (string) $uuid)->where('estado', '!=', 'archivado')->first();
        if ($servicio === null) {
            throw ValidationException::withMessages(['servicio_interes' => 'Selecciona un servicio existente.']);
        }

        return $servicio->id;
    }

    private function bloquear(string $identificador): Prospecto
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Prospecto::query()->lockForUpdate()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function autorizar(Request $request, string $permiso): User
    {
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User && $actor->hasRole('admin') && $actor->can($permiso), 403);

        return $actor;
    }

    private function comprobarRevision(Request $request, Prospecto $prospecto): void
    {
        $revision = $request->header('If-Match');
        abort_if($revision === null, 428, 'Envía If-Match obtenido del detalle del prospecto.');
        if (! preg_match('/\A"prospecto-[0-9a-f-]{36}-v[1-9][0-9]*"\z/D', $revision)) {
            throw ValidationException::withMessages(['revision' => 'Envía una única revisión fuerte.']);
        }
        abort_unless(hash_equals($prospecto->etag(), $revision), 412, 'El prospecto cambió. Relee y revisa antes de continuar.');
    }
}
