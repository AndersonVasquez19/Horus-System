<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendMatriculaWelcome;
use App\Mail\MatriculaWelcome;
use App\Models\Matricula;
use App\Models\MatriculaNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Throwable;

class MatriculaNotificationService
{
    private const ACTIVE = ['pending', 'retry', 'sending'];

    /**
     * Retenida: nunca se despacha ni se intenta automáticamente. Conserva fila y motivo para revisión.
     * No es un estado de entrega: no implica aceptación ni fallo del proveedor.
     */
    public const HELD = 'held';

    /** Capturada cuando el envío no estaba habilitado y completamente configurado. */
    public const HELD_DISABLED = 'captured_while_disabled';

    /** Existía sin intentos antes de introducir la política de retención (migración 000023). */
    public const HELD_BEFORE_POLICY = 'captured_before_policy';

    /** Nunca se intentó dentro del plazo: reactivación tardía o cola/worker detenidos. */
    public const HELD_STALE = 'stale_before_first_attempt';

    /** Antigüedad máxima de un aviso pendiente para su primer intento. */
    public const MAX_FIRST_ATTEMPT_AGE_HOURS = 24;

    /**
     * Estado inicial de una intención nueva. Solo se considera enviable si el envío estaba habilitado y
     * completamente configurado en el momento de la captura: el criterio queda persistido en la fila,
     * de modo que activar el envío después nunca despacha avisos anteriores a esa activación.
     *
     * @return array{status: string, last_error: string|null}
     */
    public function initialState(): array
    {
        return $this->configured()
            ? ['status' => 'pending', 'last_error' => null]
            : ['status' => self::HELD, 'last_error' => self::HELD_DISABLED];
    }

    public function configured(): bool
    {
        $connection = config('matricula-mail.connection');

        return config('matricula-mail.enabled') === true
            && in_array(config("queue.connections.{$connection}.driver"), ['database', 'redis', 'sqs', 'beanstalkd'], true)
            && is_string(config('matricula-mail.queue')) && trim(config('matricula-mail.queue')) !== ''
            && is_string(config('services.resend.key')) && trim(config('services.resend.key')) !== ''
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false
            && ! preg_match('/[\r\n<>]/', (string) config('mail.from.name'))
            && ! Str::endsWith(strtolower((string) config('mail.from.address')), ['@example.com', '@example.org', '@example.net']);
    }

    // El scheduler solo observa filas persistidas. No se ejecuta desde el controlador.
    public function dispatchDue(int $limit = 100): int
    {
        if (DB::transactionLevel() > 0 || ! $this->configured()) {
            return 0;
        }

        $this->holdStale();

        $count = 0;
        $rows = MatriculaNotification::query()->whereIn('status', self::ACTIVE)
            ->where('available_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('dispatched_until')->orWhere('dispatched_until', '<=', now()))
            ->orderBy('id')->limit($limit)->get();

        foreach ($rows as $row) {
            // Reserva de despacho recuperable si el proceso cae antes de encolar.
            $reserved = MatriculaNotification::query()->whereKey($row->id)
                ->whereIn('status', self::ACTIVE)->where('available_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('dispatched_until')->orWhere('dispatched_until', '<=', now()))
                ->toBase()->update(['dispatched_until' => now()->addMinutes(5)]);
            if (! $reserved) {
                continue;
            }
            try {
                Bus::dispatch((new SendMatriculaWelcome((int) $row->id))
                    ->onConnection(config('matricula-mail.connection'))
                    ->onQueue(config('matricula-mail.queue')));
                $count++;
            } catch (Throwable) {
                // No se imprime la excepción del backend de cola (puede contener credenciales).
                MatriculaNotification::query()->whereKey($row->id)->update([
                    'available_at' => now()->addMinute(), 'dispatched_until' => null, 'last_error' => 'queue_unavailable',
                ]);
                Log::warning('matricula.mail', ['outcome' => 'queue_unavailable']);
            }
        }

        return $count;
    }

    public function send(int $id): void
    {
        if (DB::transactionLevel() > 0 || ! $this->configured()) {
            return;
        }

        // Toda decisión se persiste ANTES del HTTP. La petición no mantiene locks SQL.
        $row = DB::transaction(function () use ($id): ?MatriculaNotification {
            $row = MatriculaNotification::query()->lockForUpdate()->find($id);
            if (! $row || ! in_array($row->status, self::ACTIVE, true)) {
                return null;
            }
            if ($row->status === 'sending' && $row->updated_at->gt(now()->subMinutes(2))) {
                return null;
            }
            if ($row->status === 'retry' && $row->available_at?->isFuture()) {
                return null;
            }
            // Un job ya encolado (worker reiniciado, reserva anterior) tampoco envía un aviso caducado.
            if ($row->status === 'pending' && $row->attempts === 0 && $this->isStale($row)) {
                $row->update(['status' => self::HELD, 'last_error' => self::HELD_STALE, 'payload' => null,
                    'dispatched_until' => null]);
                Log::warning('matricula.mail', ['outcome' => 'held_stale', 'count' => 1]);

                return null;
            }
            if ($row->first_attempt_at?->lte(now()->subHours(23)) || $row->attempts >= 4) {
                $row->update(['status' => 'uncertain', 'last_error' => 'retry_window_closed', 'payload' => null]);

                return null;
            }

            $matricula = Matricula::query()->with(['estudiante', 'curso'])->find($row->matricula_id);
            if (! $matricula || ! in_array($matricula->estado, ['pendiente', 'pagada'], true)
                || ! $matricula->estudiante || $matricula->estudiante->trashed()
                || ! $matricula->curso || $matricula->curso->trashed()) {
                $row->update(['status' => $row->attempts ? 'uncertain' : 'skipped', 'last_error' => 'enrollment_unavailable', 'payload' => null]);

                return null;
            }

            if ($row->payload === null) {
                $email = trim((string) $matricula->estudiante->email);
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $row->update(['status' => 'skipped', 'last_error' => 'invalid_recipient']);

                    return null;
                }
                $mail = new MatriculaWelcome(trim($matricula->estudiante->nombres.' '.$matricula->estudiante->apellidos), $matricula->curso->nombre);
                $row->payload = [
                    'from' => (new Address(config('mail.from.address'), config('mail.from.name')))->toString(),
                    'to' => [$email], 'subject' => $mail->envelope()->subject,
                    'html' => $mail->render(), 'text' => $mail->plainText(),
                ];
            }

            // Un job huérfano implica resultado desconocido: mismo payload y misma clave.
            $row->uncertain = $row->uncertain || $row->status === 'sending';
            $row->status = 'sending';
            $row->lease = (string) Str::uuid();
            $row->attempts++;
            $row->first_attempt_at ??= now();
            $row->available_at = now()->addMinutes(2);
            $row->save();

            return $row;
        });

        if (! $row) {
            return;
        }

        try {
            $response = Http::withToken(config('services.resend.key'))->acceptJson()
                ->withHeaders(['Idempotency-Key' => 'matricula-welcome/'.$row->delivery_key])
                ->connectTimeout(3)->timeout(12)->withoutRedirecting()
                ->post('https://api.resend.com/emails', $row->payload);
            $providerId = $response->json('id');
            if ($response->successful() && is_string($providerId) && preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $providerId)) {
                $this->finish($row, ['status' => 'accepted', 'provider_id' => $providerId, 'last_error' => null, 'payload' => null]);

                return;
            }

            $status = $response->status();
            // 409 puede ser una solicitud simultánea con la misma clave: conservar incertidumbre.
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
            // Ni cuerpo de respuesta ni mensaje de excepción: pueden contener PII o API keys.
            $this->retry($row, true, 'transport_uncertain');
        }
    }

    /**
     * Retiene, sin borrarlos, los avisos que nunca se intentaron y superaron la antigüedad máxima.
     * Solo afecta a `pending` sin intentos: `retry`, `sending` e inciertos siguen su ventana de 23 h.
     */
    private function holdStale(): void
    {
        $held = MatriculaNotification::query()->where('status', 'pending')->where('attempts', 0)
            ->where('created_at', '<=', now()->subHours(self::MAX_FIRST_ATTEMPT_AGE_HOURS))
            ->toBase()->update(['status' => self::HELD, 'last_error' => self::HELD_STALE, 'payload' => null,
                'dispatched_until' => null, 'updated_at' => now()]);
        if ($held > 0) {
            Log::warning('matricula.mail', ['outcome' => 'held_stale', 'count' => $held]);
        }
    }

    private function isStale(MatriculaNotification $row): bool
    {
        return $row->created_at === null
            || $row->created_at->lte(now()->subHours(self::MAX_FIRST_ATTEMPT_AGE_HOURS));
    }

    private function retry(MatriculaNotification $row, bool $uncertain, string $error, int $delay = 0): void
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
    private function finish(MatriculaNotification $row, array $values): void
    {
        // Un trabajador antiguo no puede sobrescribir una reserva nueva.
        $changed = MatriculaNotification::query()->whereKey($row->id)->where('lease', $row->lease)
            ->update([...$values, 'lease' => null, 'dispatched_until' => null, 'updated_at' => now()]);
        if ($changed) {
            Log::info('matricula.mail', ['outcome' => $values['status']]);
        }
    }
}
