<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\EnviarAvisoExterno;
use App\Models\AvisoExterno;
use App\Models\User;
use App\Support\Entrada;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Despacho transaccional e idempotente de avisos externos (correo vía Resend y WhatsApp Cloud con plantilla aprobada),
 * replicando el patrón de retención, reserva atómica y reintentos acotados de {@see MatriculaNotificationService}.
 */
class EnvioAvisosExternosService
{
    private const ACTIVE = ['pending', 'retry', 'sending'];

    public const HELD = 'held';

    public const HELD_DISABLED = 'captured_while_disabled';

    public const HELD_STALE = 'stale_before_first_attempt';

    public const MAX_FIRST_ATTEMPT_AGE_HOURS = 24;

    public function __construct(private readonly WhatsAppCloudService $whatsapp) {}

    public function channelConfigured(string $canal): bool
    {
        $connection = Entrada::texto(config('recordatorios.queue_connection'));
        $asyncQueue = in_array(config("queue.connections.{$connection}.driver"), ['database', 'redis', 'sqs', 'beanstalkd'], true)
            && is_string(config('recordatorios.queue')) && trim((string) config('recordatorios.queue')) !== '';

        if (! $asyncQueue) {
            return false;
        }

        if ($canal === 'whatsapp') {
            return $this->whatsapp->configured();
        }

        if ($canal === 'email') {
            return config('recordatorios.mail_enabled') === true
                && is_string(config('services.resend.key')) && trim((string) config('services.resend.key')) !== ''
                && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false
                && ! preg_match('/[\r\n<>]/', Entrada::texto(config('mail.from.name')))
                && ! Str::endsWith(strtolower(Entrada::texto(config('mail.from.address'))), ['@example.com', '@example.org', '@example.net']);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function registrar(
        string $canal,
        string $tipo,
        string $claveUnica,
        array $payload,
        ?int $userId = null,
        ?int $estudianteId = null,
    ): AvisoExterno {
        $existente = AvisoExterno::query()->where('clave_unica', $claveUnica)->first();
        if ($existente instanceof AvisoExterno) {
            return $existente;
        }

        $configurado = $this->channelConfigured($canal);

        try {
            return AvisoExterno::query()->create([
                'canal' => $canal,
                'tipo' => $tipo,
                'clave_unica' => $claveUnica,
                'delivery_key' => (string) Str::uuid(),
                'status' => $configurado ? 'pending' : self::HELD,
                'last_error' => $configurado ? null : self::HELD_DISABLED,
                'payload' => $payload,
                'user_id' => $userId,
                'estudiante_id' => $estudianteId,
                'available_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return AvisoExterno::query()->where('clave_unica', $claveUnica)->firstOrFail();
        }
    }

    public function dispatchDue(int $limit = 100): int
    {
        if (DB::transactionLevel() > 0) {
            return 0;
        }

        $canalesActivos = array_values(array_filter(['email', 'whatsapp'], fn (string $c): bool => $this->channelConfigured($c)));
        if ($canalesActivos === []) {
            return 0;
        }

        $this->holdStale();

        $rows = AvisoExterno::query()
            ->whereIn('canal', $canalesActivos)
            ->whereIn('status', self::ACTIVE)
            ->where('available_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('dispatched_until')->orWhere('dispatched_until', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            $reserved = AvisoExterno::query()
                ->whereKey($row->id)
                ->whereIn('status', self::ACTIVE)
                ->where('available_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('dispatched_until')->orWhere('dispatched_until', '<=', now()))
                ->toBase()
                ->update(['dispatched_until' => now()->addMinutes(5)]);

            if (! $reserved) {
                continue;
            }

            try {
                Bus::dispatch((new EnviarAvisoExterno((int) $row->id))
                    ->onConnection(Entrada::texto(config('recordatorios.queue_connection')))
                    ->onQueue(Entrada::texto(config('recordatorios.queue'))));
                $count++;
            } catch (Throwable) {
                AvisoExterno::query()->whereKey($row->id)->update([
                    'available_at' => now()->addMinute(),
                    'dispatched_until' => null,
                    'last_error' => 'queue_unavailable',
                ]);
                Log::warning('aviso.externo', ['outcome' => 'queue_unavailable']);
            }
        }

        return $count;
    }

    public function send(int $id): void
    {
        if (DB::transactionLevel() > 0) {
            return;
        }

        $row = DB::transaction(function () use ($id): ?AvisoExterno {
            $row = AvisoExterno::query()->lockForUpdate()->find($id);
            if (! $row || ! in_array($row->status, self::ACTIVE, true) || ! $this->channelConfigured($row->canal)) {
                return null;
            }
            if ($row->status === 'sending' && $row->updated_at?->gt(now()->subMinutes(2))) {
                return null;
            }
            if ($row->status === 'retry' && $row->available_at?->isFuture()) {
                return null;
            }
            if ($row->status === 'pending' && $row->attempts === 0 && ($row->created_at === null || $row->created_at->lte(now()->subHours(self::MAX_FIRST_ATTEMPT_AGE_HOURS)))) {
                $row->update(['status' => self::HELD, 'last_error' => self::HELD_STALE, 'payload' => null, 'dispatched_until' => null]);

                return null;
            }
            if ($row->first_attempt_at?->lte(now()->subHours(23)) || $row->attempts >= 4) {
                $row->update(['status' => 'uncertain', 'last_error' => 'retry_window_closed', 'payload' => null]);

                return null;
            }

            $payload = $row->payload;
            if (! is_array($payload)) {
                $row->update(['status' => 'skipped', 'last_error' => 'missing_payload']);

                return null;
            }

            if ($row->canal === 'whatsapp' && ($payload['consentimiento'] ?? false) !== true) {
                $row->update(['status' => 'skipped', 'last_error' => 'sin_consentimiento', 'payload' => null]);

                return null;
            }

            // Relectura al enviar: la cuenta debe seguir activa y conservar el canal (y, en WhatsApp, el consentimiento y el mismo teléfono).
            $motivo = $this->motivoOmision($row, $payload);
            if ($motivo !== null) {
                $row->update(['status' => 'skipped', 'last_error' => $motivo, 'payload' => null, 'dispatched_until' => null]);

                return null;
            }

            $row->uncertain = $row->uncertain || $row->status === 'sending';
            $row->status = 'sending';
            $row->lease = (string) Str::uuid();
            $row->attempts++;
            $row->first_attempt_at ??= now();
            $row->available_at = now()->addMinutes(2);
            $row->save();

            return $row;
        });

        if (! $row instanceof AvisoExterno) {
            return;
        }

        if ($row->canal === 'email') {
            $this->enviarEmail($row);

            return;
        }

        $this->enviarWhatsApp($row);
    }

    /**
     * Motivo para no enviar un aviso ya encolado (cuenta dada de baja o suspendida, canal o consentimiento retirados, teléfono cambiado).
     *
     * @param  array<string, mixed>  $payload
     */
    private function motivoOmision(AvisoExterno $row, array $payload): ?string
    {
        if ($row->user_id === null) {
            return null;
        }

        $user = User::query()->whereKey($row->user_id)->first();
        if (! $user instanceof User || $user->estaDadoDeBaja() || $user->estaSuspendido()) {
            return 'cuenta_no_disponible';
        }

        $prefs = $user->preferenciasNotificacion();
        if ($row->canal === 'email') {
            return ($prefs['email'] ?? false) ? null : 'canal_desactivado';
        }

        if (! ($prefs['whatsapp'] ?? false) || ! ($prefs['whatsapp_consentimiento'] ?? false)) {
            return 'sin_consentimiento';
        }

        return trim(Entrada::texto($user->telefono)) !== '' && trim(Entrada::texto($user->telefono)) === trim(Entrada::texto(($payload['telefono'] ?? '')))
            ? null
            : 'telefono_cambiado';
    }

    private function enviarEmail(AvisoExterno $row): void
    {
        $payload = $row->payload ?? [];
        $destino = trim(Entrada::texto(($payload['to'] ?? '')));
        if (! filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            $this->finish($row, ['status' => 'skipped', 'last_error' => 'invalid_recipient', 'payload' => null]);

            return;
        }

        try {
            $body = [
                'from' => (new Address(Entrada::texto(config('mail.from.address')), Entrada::texto(config('mail.from.name'))))->toString(),
                'to' => [$destino],
                'subject' => Entrada::texto(($payload['subject'] ?? 'Notificación Horus Academic')),
                'text' => Entrada::texto(($payload['text'] ?? '')),
            ];
            if (is_string($payload['html'] ?? null) && $payload['html'] !== '') {
                $body['html'] = $payload['html'];
            }

            $response = Http::withToken(Entrada::texto(config('services.resend.key')))->acceptJson()
                ->withHeaders(['Idempotency-Key' => 'aviso-externo/'.$row->delivery_key])
                ->connectTimeout(3)->timeout(12)->withoutRedirecting()
                ->post('https://api.resend.com/emails', $body);

            $providerId = $response->json('id');
            if ($response->successful() && is_string($providerId) && preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $providerId)) {
                $this->finish($row, ['status' => 'accepted', 'provider_id' => $providerId, 'last_error' => null, 'payload' => null]);

                return;
            }

            $status = $response->status();
            $retryable = $status === 429 || $status === 409 || $status >= 500;
            $uncertain = $row->uncertain || $status === 409 || $status >= 500 || $response->successful();
            if (! $retryable && ! $response->successful()) {
                $this->finish($row, ['status' => $uncertain ? 'uncertain' : 'failed', 'last_error' => 'provider_http_'.$status, 'payload' => null]);

                return;
            }
            $retryAfter = $response->header('Retry-After');
            $delay = ctype_digit($retryAfter) ? min(3600, (int) $retryAfter) : 0;
            $this->retry($row, $uncertain, 'provider_http_'.$status, $delay);
        } catch (Throwable) {
            $this->retry($row, true, 'transport_uncertain');
        }
    }

    private function enviarWhatsApp(AvisoExterno $row): void
    {
        $payload = $row->payload ?? [];
        try {
            $res = $this->whatsapp->enviarPlantilla(
                Entrada::texto(($payload['telefono'] ?? '')),
                Entrada::texto(($payload['plantilla'] ?? 'horus_recordatorio_general')),
                array_values(array_map(static fn (mixed $parametro): string => Entrada::texto($parametro), is_array($payload['parametros'] ?? null) ? $payload['parametros'] : [])),
            );

            if ($res['ok']) {
                $this->finish($row, ['status' => 'accepted', 'provider_id' => $res['provider_id'], 'last_error' => null, 'payload' => null]);

                return;
            }
            if (! $res['retryable']) {
                $this->finish($row, ['status' => 'failed', 'last_error' => 'whatsapp_http_'.$res['status'], 'payload' => null]);

                return;
            }

            $this->retry($row, true, 'whatsapp_http_'.$res['status']);
        } catch (Throwable) {
            $this->retry($row, true, 'transport_uncertain');
        }
    }

    private function holdStale(): void
    {
        AvisoExterno::query()->where('status', 'pending')->where('attempts', 0)
            ->where('created_at', '<=', now()->subHours(self::MAX_FIRST_ATTEMPT_AGE_HOURS))
            ->toBase()->update(['status' => self::HELD, 'last_error' => self::HELD_STALE, 'payload' => null, 'dispatched_until' => null, 'updated_at' => now()]);
    }

    private function retry(AvisoExterno $row, bool $uncertain, string $error, int $delay = 0): void
    {
        $terminal = $row->attempts >= 4;
        $this->finish($row, [
            'status' => $terminal ? ($uncertain ? 'uncertain' : 'failed') : 'retry',
            'uncertain' => $uncertain,
            'last_error' => $error,
            'available_at' => now()->addSeconds(max($delay, [60, 300, 900, 3600][$row->attempts - 1])),
            ...($terminal ? ['payload' => null] : []),
        ]);
    }

    /** @param array<string, mixed> $values */
    private function finish(AvisoExterno $row, array $values): void
    {
        AvisoExterno::query()->whereKey($row->id)->where('lease', $row->lease)
            ->update([...$values, 'lease' => null, 'dispatched_until' => null, 'updated_at' => now()]);
    }
}
