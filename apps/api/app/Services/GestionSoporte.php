<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\TicketNota;
use App\Models\TicketSoporte;
use App\Models\User;
use App\Support\CodigoSecuencial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Soporte técnico del panel de servicios (Ola 3, docs/ola3-diseno-datos.md §3.5).
 *
 * Única implementación de las reglas: bloqueo de fila, revisión fuerte `If-Match` (428/412), rol y permiso releídos con
 * datos vigentes, estados cerrados, código `SOP-AAAA-NNNN` bajo bloqueo, vencimiento por prioridad y reapertura acotada.
 * Las notas son de solo lectura una vez creadas y no exigen revisión (solo se añaden). La auditoría guarda revisión,
 * estados y prioridades, nunca asunto, descripción, notas ni datos del cliente.
 */
class GestionSoporte
{
    /**
     * @param  array<string, mixed>  $datos  `cliente` (UUID), `asunto`, `prioridad`, `canal` y opcionales `descripcion`
     *                                       y `proyecto` (UUID, del mismo cliente)
     */
    public function crear(Request $request, array $datos): TicketSoporte
    {
        return DB::transaction(function () use ($request, $datos): TicketSoporte {
            $autor = $this->autorizar($request, 'soporte.crear');
            abort_unless($autor->can('clientes.ver'), 403);

            $cliente = Cliente::query()->where('identificador', strtolower((string) ($datos['cliente'] ?? '')))->first();
            if ($cliente === null) {
                throw ValidationException::withMessages(['cliente' => 'Selecciona un cliente existente.']);
            }
            if ($cliente->estado !== 'activo') {
                throw ValidationException::withMessages(['cliente' => 'El cliente está inactivo: reactívalo antes de abrir un ticket.']);
            }

            $ticket = new TicketSoporte(array_intersect_key($datos, array_flip(['asunto', 'descripcion', 'prioridad', 'canal'])));
            $ticket->identificador = (string) Str::uuid();
            $ticket->codigo = CodigoSecuencial::siguiente('SOP');
            $ticket->cliente_id = $cliente->id;
            $ticket->proyecto_id = $this->proyecto($autor, $datos['proyecto'] ?? null, $cliente);
            $ticket->estado = 'abierto';
            $ticket->vence_at = now()->addHours(TicketSoporte::SLA_HORAS[$ticket->prioridad]);
            $ticket->revision = 1;
            $ticket->save();
            AuditLog::record('ticket_creado', TicketSoporte::class, $ticket->id, ['revision' => 1, 'prioridad' => $ticket->prioridad], $autor->id);

            return $ticket;
        });
    }

    /** @param array<string, mixed> $datos */
    public function actualizar(Request $request, string $identificador, array $datos): TicketSoporte
    {
        return DB::transaction(function () use ($request, $identificador, $datos): TicketSoporte {
            $ticket = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'soporte.gestionar');
            $this->comprobarRevision($request, $ticket);
            abort_unless($ticket->esAbierto(), 409, 'Un ticket cerrado no se edita.');

            $ticket->fill(array_intersect_key($datos, array_flip(['asunto', 'descripcion', 'prioridad', 'canal'])));
            if (array_key_exists('proyecto', $datos)) {
                $ticket->proyecto_id = $this->proyecto($autor, $datos['proyecto'], $ticket->cliente);
            }
            if (! $ticket->isDirty()) {
                return $ticket;
            }
            if ($ticket->isDirty('prioridad')) {
                // El SLA corre desde que se abrió el ticket, no desde el cambio de prioridad.
                $ticket->vence_at = $ticket->created_at->copy()->addHours(TicketSoporte::SLA_HORAS[$ticket->prioridad]);
            }

            $ticket->revision++;
            $ticket->save();
            AuditLog::record('ticket_actualizado', TicketSoporte::class, $ticket->id, ['revision' => $ticket->revision, 'prioridad' => $ticket->prioridad], $autor->id);

            return $ticket;
        });
    }

    public function cambiarEstado(Request $request, string $identificador, string $estado): TicketSoporte
    {
        if (! in_array($estado, TicketSoporte::ESTADOS, true)) {
            throw ValidationException::withMessages(['estado' => 'Estado no permitido.']);
        }

        return DB::transaction(function () use ($request, $identificador, $estado): TicketSoporte {
            $ticket = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'soporte.gestionar');
            $this->comprobarRevision($request, $ticket);

            if ($ticket->estado === $estado) {
                return $ticket;
            }
            abort_unless(in_array($estado, TicketSoporte::TRANSICIONES[$ticket->estado] ?? [], true), 409, 'Transición no permitida desde el estado actual.');
            if ($ticket->estado === 'resuelto' && $estado === 'en_progreso'
                && $ticket->resuelto_at?->lt(now()->subDays(TicketSoporte::DIAS_REAPERTURA)) !== false) {
                abort(409, 'Pasaron más de '.TicketSoporte::DIAS_REAPERTURA.' días desde la resolución: abre otro ticket.');
            }

            $anterior = $ticket->estado;
            $ticket->estado = $estado;
            $ticket->resuelto_at = match ($estado) {
                'resuelto' => now(),
                'en_progreso' => $anterior === 'resuelto' ? null : $ticket->resuelto_at,
                default => $ticket->resuelto_at,
            };
            $ticket->cerrado_at = $estado === 'cerrado' ? now() : null;
            $ticket->revision++;
            $ticket->save();
            AuditLog::record('ticket_estado_cambiado', TicketSoporte::class, $ticket->id,
                ['revision' => $ticket->revision, 'anterior' => $anterior, 'estado' => $estado], $autor->id);

            return $ticket;
        });
    }

    /** Asigna el ticket. Solo puede atenderlo un administrador que pueda ver soporte. */
    public function asignar(Request $request, string $identificador, ?int $asignadoId): TicketSoporte
    {
        return DB::transaction(function () use ($request, $identificador, $asignadoId): TicketSoporte {
            $ticket = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'soporte.gestionar');
            $this->comprobarRevision($request, $ticket);
            abort_unless($ticket->esAbierto(), 409, 'Un ticket cerrado no se reasigna.');

            if ($asignadoId !== null) {
                $asignado = User::query()->find($asignadoId);
                if (! $asignado instanceof User || ! $asignado->hasRole('admin') || ! $asignado->can('soporte.ver')) {
                    throw ValidationException::withMessages(['asignado_a' => 'La persona asignada no es válida.']);
                }
            }
            if ($ticket->asignado_a === $asignadoId) {
                return $ticket;
            }
            $ticket->asignado_a = $asignadoId;
            $ticket->revision++;
            $ticket->save();
            AuditLog::record('ticket_asignado', TicketSoporte::class, $ticket->id, ['revision' => $ticket->revision], $autor->id);

            return $ticket;
        });
    }

    /** Añade una nota (solo se crean; para corregir se añade otra). No avanza la revisión del ticket. */
    public function agregarNota(Request $request, string $identificador, string $texto, bool $interna): TicketNota
    {
        return DB::transaction(function () use ($request, $identificador, $texto, $interna): TicketNota {
            $ticket = $this->bloquear($identificador);
            $autor = $this->autorizar($request, 'soporte.gestionar');
            abort_unless($ticket->esAbierto(), 409, 'Un ticket cerrado no admite notas.');

            $nota = new TicketNota;
            $nota->ticket_id = $ticket->id;
            $nota->autor_id = $autor->id;
            $nota->interna = $interna;
            $nota->texto = $texto;
            $nota->save();
            AuditLog::record('ticket_nota_agregada', TicketSoporte::class, $ticket->id, ['interna' => $interna], $autor->id);

            return $nota;
        });
    }

    private function proyecto(User $autor, mixed $uuid, ?Cliente $cliente): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        abort_unless($autor->can('proyectos.ver'), 403);
        $proyecto = Proyecto::query()->where('identificador', strtolower((string) $uuid))->first();
        if ($proyecto === null || $proyecto->cliente_id !== $cliente?->id) {
            throw ValidationException::withMessages(['proyecto' => 'Selecciona un proyecto de este cliente.']);
        }

        return $proyecto->id;
    }

    private function bloquear(string $identificador): TicketSoporte
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return TicketSoporte::query()->lockForUpdate()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private function autorizar(Request $request, string $permiso): User
    {
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User && $actor->hasRole('admin') && $actor->can($permiso), 403);

        return $actor;
    }

    private function comprobarRevision(Request $request, TicketSoporte $ticket): void
    {
        $revision = $request->header('If-Match');
        abort_if($revision === null, 428, 'Envía If-Match obtenido del detalle del ticket.');
        if (! preg_match('/\A"ticket-[0-9a-f-]{36}-v[1-9][0-9]*"\z/D', $revision)) {
            throw ValidationException::withMessages(['revision' => 'Envía una única revisión fuerte.']);
        }
        abort_unless(hash_equals($ticket->etag(), $revision), 412, 'El ticket cambió. Relee y revisa antes de continuar.');
    }
}
