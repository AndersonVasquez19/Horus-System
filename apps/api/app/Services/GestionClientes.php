<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\ClienteContacto;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Altas, cambios y estado de los clientes del panel de servicios (Ola 3, docs/ola3-diseno-datos.md §3.1).
 *
 * Única implementación de las reglas: bloqueo de fila, revisión fuerte `If-Match` (412), permiso releído con datos
 * vigentes, documento único y auditoría sin datos personales (solo revisión y estado, nunca nombre, correo, teléfono
 * ni documento). Los contactos se reemplazan en bloque cuando el cuerpo trae `contactos`; si no, no se tocan.
 */
class GestionClientes
{
    /** @param array<string, mixed> $datos */
    public function crear(Request $request, array $datos): Cliente
    {
        try {
            return DB::transaction(function () use ($request, $datos): Cliente {
                $autor = $this->autorizar($request, 'clientes.crear');
                $this->comprobarDocumentoLibre($datos, null);

                $cliente = new Cliente(self::campos($datos));
                $cliente->identificador = (string) Str::uuid();
                $cliente->estado = 'activo';
                $cliente->responsable_id = $autor->id;
                $cliente->revision = 1;
                $cliente->save();
                $this->sustituirContactos($cliente, $datos['contactos'] ?? []);
                AuditLog::record('cliente_creado', Cliente::class, $cliente->id, ['revision' => 1], $autor->id);

                return $cliente;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['documento_numero' => 'Ya existe un cliente con ese documento.']);
        }
    }

    /** @param array<string, mixed> $datos */
    public function actualizar(Request $request, string $identificador, array $datos): Cliente
    {
        try {
            return DB::transaction(function () use ($request, $identificador, $datos): Cliente {
                $cliente = $this->bloquear($identificador);
                $autor = $this->autorizar($request, 'clientes.editar');
                $this->comprobarRevision($request, $cliente);
                $this->comprobarDocumentoLibre($datos, $cliente);

                $cliente->fill(self::campos($datos));
                $cambioContactos = array_key_exists('contactos', $datos) && $this->contactosCambian($cliente, $datos['contactos']);
                if (! $cliente->isDirty() && ! $cambioContactos) {
                    return $cliente->load(['responsable:id,name', 'contactos']);
                }

                $cliente->revision++;
                $cliente->save();
                if ($cambioContactos) {
                    $this->sustituirContactos($cliente, $datos['contactos']);
                }
                AuditLog::record('cliente_actualizado', Cliente::class, $cliente->id, ['revision' => $cliente->revision], $autor->id);

                return $cliente->load(['responsable:id,name', 'contactos']);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['documento_numero' => 'Ya existe un cliente con ese documento.']);
        }
    }

    /** Activa o inactiva. Un cliente inactivo se conserva con todo su historial; no se borra. */
    public function cambiarEstado(Request $request, string $identificador, string $estado): Cliente
    {
        abort_unless(in_array($estado, Cliente::ESTADOS, true), 422, 'Estado no permitido.');

        return DB::transaction(function () use ($request, $identificador, $estado): Cliente {
            $cliente = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'clientes.editar');
            $this->comprobarRevision($request, $cliente);

            if ($cliente->estado === $estado) {
                return $cliente->load(['responsable:id,name', 'contactos']);
            }
            $cliente->estado = $estado;
            $cliente->revision++;
            $cliente->save();
            AuditLog::record('cliente_estado_actualizado', Cliente::class, $cliente->id, ['revision' => $cliente->revision, 'estado' => $estado], $autor->id);

            return $cliente->load(['responsable:id,name', 'contactos']);
        });
    }

    /** Cambia el responsable. Solo puede serlo un administrador que pueda ver clientes. */
    public function asignarResponsable(Request $request, string $identificador, ?int $responsableId): Cliente
    {
        return DB::transaction(function () use ($request, $identificador, $responsableId): Cliente {
            $cliente = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'clientes.editar');
            $this->comprobarRevision($request, $cliente);

            if ($responsableId !== null) {
                $responsable = User::query()->find($responsableId);
                if (! $responsable instanceof User || ! $responsable->hasRole('admin') || ! $responsable->can('clientes.ver')) {
                    throw ValidationException::withMessages(['responsable_id' => 'El responsable no es válido.']);
                }
            }
            if ($cliente->responsable_id === $responsableId) {
                return $cliente->load(['responsable:id,name', 'contactos']);
            }
            $cliente->responsable_id = $responsableId;
            $cliente->revision++;
            $cliente->save();
            AuditLog::record('cliente_responsable_actualizado', Cliente::class, $cliente->id, ['revision' => $cliente->revision], $autor->id);

            return $cliente->load(['responsable:id,name', 'contactos']);
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private static function campos(array $datos): array
    {
        $campos = array_intersect_key($datos, array_flip(['tipo', 'nombre', 'documento_tipo', 'documento_numero', 'email', 'telefono', 'direccion']));
        // Sin documento, ambos campos quedan nulos (la base también lo exige).
        if (($campos['documento_tipo'] ?? null) === null || ($campos['documento_numero'] ?? null) === null) {
            if (array_key_exists('documento_tipo', $campos) || array_key_exists('documento_numero', $campos)) {
                $campos['documento_tipo'] = null;
                $campos['documento_numero'] = null;
            }
        }

        return $campos;
    }

    /** @param array<string, mixed> $datos */
    private function comprobarDocumentoLibre(array $datos, ?Cliente $actual): void
    {
        $tipo = $datos['documento_tipo'] ?? null;
        $numero = $datos['documento_numero'] ?? null;
        if (! is_string($tipo) || ! is_string($numero)) {
            return;
        }
        $existe = Cliente::query()->withTrashed()
            ->where('documento_tipo', $tipo)->where('documento_numero', $numero)
            ->when($actual !== null, fn ($q) => $q->where('id', '!=', $actual->id))
            ->exists();
        if ($existe) {
            throw ValidationException::withMessages(['documento_numero' => 'Ya existe un cliente con ese documento.']);
        }
    }

    /** @param list<array<string, mixed>> $contactos */
    private function contactosCambian(Cliente $cliente, array $contactos): bool
    {
        $actuales = $cliente->contactos()->get()->map(fn (ClienteContacto $c): array => $this->huella($c->only(['nombre', 'cargo', 'email', 'telefono', 'principal'])))->all();
        $nuevos = array_map(fn (array $c): array => $this->huella($c), $contactos);

        return $actuales !== $nuevos;
    }

    /**
     * @param  array<string, mixed>  $contacto
     * @return array<string, mixed>
     */
    private function huella(array $contacto): array
    {
        return [
            'nombre' => $contacto['nombre'] ?? null,
            'cargo' => $contacto['cargo'] ?? null,
            'email' => $contacto['email'] ?? null,
            'telefono' => $contacto['telefono'] ?? null,
            'principal' => filter_var($contacto['principal'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** @param list<array<string, mixed>> $contactos */
    private function sustituirContactos(Cliente $cliente, array $contactos): void
    {
        ClienteContacto::query()->where('cliente_id', $cliente->id)->delete();
        foreach ($contactos as $contacto) {
            $nuevo = new ClienteContacto($this->huella($contacto));
            $nuevo->cliente_id = $cliente->id;
            $nuevo->save();
        }
    }

    private function bloquear(string $identificador): Cliente
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Cliente::query()->lockForUpdate()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function autorizar(Request $request, string $permiso): User
    {
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User && $actor->hasAnyRole(RolesAndPermissionsSeeder::STAFF_ROLES) && $actor->hasRole('admin') && $actor->can($permiso), 403);

        return $actor;
    }

    private function comprobarRevision(Request $request, Cliente $cliente): void
    {
        $revision = $request->header('If-Match');
        abort_if($revision === null, 428, 'Envía If-Match obtenido del detalle del cliente.');
        if (! preg_match('/\A"cliente-[0-9a-f-]{36}-v[1-9][0-9]*"\z/D', $revision)) {
            throw ValidationException::withMessages(['revision' => 'Envía una única revisión fuerte.']);
        }
        abort_unless(hash_equals($cliente->etag(), $revision), 412, 'El cliente cambió. Relee y revisa antes de continuar.');
    }
}
