<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\ReclamacionMail;
use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Support\AuditoriaReclamaciones;
use App\Support\Reclamaciones;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Envío de la bandeja `reclamacion_envios` por correo, sin duplicados:
 *  1. Reserva atómica: UPDATE condicional `pendiente|procesando vencido → procesando` con `intentos + 1`; si afecta
 *     a 0 filas, otro trabajador la tiene y este no envía.
 *  2. El correo se envía FUERA de transacción, con el texto exacto guardado en `cuerpo`.
 *  3. Éxito: `enviado`, evidencia y, si era la respuesta formal, el caso pasa a «respondido» (misma transacción).
 *  4. Fallo: vuelve a `pendiente` con un código corto (nunca el mensaje de la excepción) y se relanza una excepción
 *     genérica para que la cola reintente con retroceso; agotados los intentos queda `fallido` + `correo_fallido`.
 * Si el proceso muere tras enviar y antes de guardar, el envío puede repetirse una vez vencida la reserva: riesgo
 * aceptado y documentado (una copia duplicada no perjudica al consumidor).
 */
class EnvioReclamaciones
{
    public const ENVIADO = 'enviado';

    public const OMITIDO = 'omitido';

    public const FALLIDO = 'fallido';

    public function __construct(private readonly GestionReclamaciones $gestion) {}

    /**
     * @return 'enviado'|'omitido'|'fallido'
     *
     * @throws RuntimeException Fallo transitorio reintentable (sin datos personales en el mensaje).
     */
    public function enviar(int $envioId): string
    {
        if (! Reclamaciones::envioHabilitado()) {
            return self::OMITIDO;
        }
        $envio = $this->reservar($envioId);
        if ($envio === null) {
            return self::OMITIDO;
        }
        $reclamacion = Reclamacion::query()->findOrFail($envio->reclamacion_id);
        try {
            $enviado = Mail::to($reclamacion->email)->send(new ReclamacionMail($envio->asunto, $envio->cuerpo));
            $proveedorId = $enviado?->getMessageId();
        } catch (Throwable $e) {
            return $this->fallo($envio->id, $e instanceof TransportExceptionInterface ? 'transporte' : 'error_envio');
        }
        $this->exito($envio->id, is_string($proveedorId) ? mb_substr($proveedorId, 0, 100) : null);

        return self::ENVIADO;
    }

    private function reservar(int $envioId): ?ReclamacionEnvio
    {
        $reserva = (int) config('reclamaciones.correo.reserva_minutos', 10);
        $limite = now()->subMinutes($reserva);

        return DB::transaction(function () use ($envioId, $limite): ?ReclamacionEnvio {
            $envio = ReclamacionEnvio::query()->whereKey($envioId)->lockForUpdate()->first();
            if ($envio === null || $envio->canal !== 'correo' || in_array($envio->estado, ['enviado', 'fallido'], true)) {
                return null;
            }
            if ($envio->intentos >= Reclamaciones::maxIntentos()) {
                $this->agotar($envio);

                return null;
            }
            $afectadas = ReclamacionEnvio::query()->whereKey($envio->id)
                ->where('intentos', $envio->intentos)
                ->where(fn ($q) => $q->where('estado', 'pendiente')->orWhere(fn ($q2) => $q2->where('estado', 'procesando')->where('updated_at', '<', $limite)))
                ->update(['estado' => 'procesando', 'intentos' => $envio->intentos + 1, 'updated_at' => now()]);
            if ($afectadas !== 1) {
                return null;
            }

            return $envio->refresh();
        });
    }

    private function exito(int $envioId, ?string $proveedorId): void
    {
        DB::transaction(function () use ($envioId, $proveedorId): void {
            $envio = ReclamacionEnvio::query()->whereKey($envioId)->lockForUpdate()->firstOrFail();
            $r = Reclamacion::query()->whereKey($envio->reclamacion_id)->lockForUpdate()->firstOrFail();
            $envio->forceFill(['estado' => 'enviado', 'enviado_at' => now(), 'proveedor_id' => $proveedorId, 'error_codigo' => null])->save();
            $this->gestion->historial($r, 'correo_enviado', null, ['envio_id' => $envio->id, 'tipo' => $envio->tipo]);
            if ($envio->tipo === 'respuesta') {
                $this->gestion->marcarRespondida($r, $envio);
            }
            AuditoriaReclamaciones::registrar('correo_enviado', $r, ['envio_id' => $envio->id, 'envio_tipo' => $envio->tipo, 'estado' => $r->estado], null);
        });
    }

    private function fallo(int $envioId, string $codigo): string
    {
        $agotado = DB::transaction(function () use ($envioId, $codigo): bool {
            $envio = ReclamacionEnvio::query()->whereKey($envioId)->lockForUpdate()->firstOrFail();
            $envio->forceFill(['estado' => 'pendiente', 'error_codigo' => $codigo])->save();
            if ($envio->intentos >= Reclamaciones::maxIntentos()) {
                $this->agotar($envio);

                return true;
            }

            return false;
        });
        Log::warning('reclamacion.envio', ['envio_id' => $envioId, 'outcome' => $codigo]);
        if ($agotado) {
            return self::FALLIDO;
        }

        throw new RuntimeException('Envío de reclamación fallido; se reintentará.');
    }

    /** Marca el envío como fallido definitivo y deja constancia (requiere transacción y el envío bloqueado). */
    private function agotar(ReclamacionEnvio $envio): void
    {
        $r = Reclamacion::query()->whereKey($envio->reclamacion_id)->lockForUpdate()->firstOrFail();
        $envio->forceFill(['estado' => 'fallido'])->save();
        $this->gestion->historial($r, 'correo_fallido', null, ['envio_id' => $envio->id, 'tipo' => $envio->tipo, 'resultado' => $envio->error_codigo ?? 'intentos_agotados']);
        AuditoriaReclamaciones::registrar('correo_fallido', $r, ['envio_id' => $envio->id, 'envio_tipo' => $envio->tipo, 'resultado' => $envio->error_codigo ?? 'intentos_agotados'], null);
    }
}
