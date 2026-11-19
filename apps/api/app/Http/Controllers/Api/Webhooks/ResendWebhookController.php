<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\AvisoExterno;
use App\Models\MatriculaNotification;
use App\Models\NotificacionInterna;
use App\Models\ReclamacionEnvio;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

/**
 * Webhook de eventos de entrega de Resend (firma Svix HMAC-SHA256) para `matricula_notifications`,
 * `reclamacion_envios` y `avisos_externos`. Nunca registra direcciones de correo ni cuerpos en los logs.
 */
final class ResendWebhookController extends Controller
{
    /** @var array<string, int> */
    private const RANGO = ['delayed' => 1, 'delivered' => 2, 'bounced' => 3, 'complained' => 3];

    public function __invoke(Request $request): JsonResponse
    {
        $secret = trim((string) config('recordatorios.webhooks.resend_secret', ''));
        if ($secret === '') {
            return response()->json(['message' => 'Webhook de correo no configurado.'], 503);
        }

        $svixId = (string) $request->header('svix-id', '');
        $svixTimestamp = (string) $request->header('svix-timestamp', '');
        $svixSignature = (string) $request->header('svix-signature', '');

        if ($svixId === '' || ! ctype_digit($svixTimestamp) || $svixSignature === '') {
            return response()->json(['message' => 'Cabeceras de firma incompletas.'], 401);
        }

        $tolerancia = max(30, (int) config('recordatorios.webhooks.resend_tolerancia_segundos', 300));
        if (abs(time() - (int) $svixTimestamp) > $tolerancia) {
            return response()->json(['message' => 'Firma de webhook expirada.'], 401);
        }

        $claveBinaria = $this->decodificarSecreto($secret);
        if ($claveBinaria === '') {
            return response()->json(['message' => 'Secreto de webhook inválido.'], 503);
        }

        $cuerpoCrudo = $request->getContent();
        $esperada = base64_encode(hash_hmac('sha256', "{$svixId}.{$svixTimestamp}.{$cuerpoCrudo}", $claveBinaria, true));

        if (! $this->firmaValida($svixSignature, $esperada)) {
            return response()->json(['message' => 'Firma de webhook inválida.'], 401);
        }

        $payload = json_decode($cuerpoCrudo, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Carga JSON inválida.'], 400);
        }

        $tipo = (string) ($payload['type'] ?? '');
        $emailId = $payload['data']['email_id'] ?? null;
        if (! is_string($emailId) || $emailId === '' || strlen($emailId) > 100) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $estadoProveedor = match ($tipo) {
            'email.delivered' => 'delivered',
            'email.bounced' => 'bounced',
            'email.complained' => 'complained',
            'email.delivery_delayed' => 'delayed',
            default => null,
        };

        if ($estadoProveedor === null) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        // Reintentos de Resend: el mismo `svix-id` no se procesa dos veces (evita duplicar avisos de rebote).
        if (! Cache::add('webhook.resend.'.hash('sha256', $svixId), 1, now()->addDay())) {
            return response()->json(['ok' => true, 'duplicado' => true]);
        }

        $esEntrega = $estadoProveedor === 'delivered';
        $esRebote = in_array($estadoProveedor, ['bounced', 'complained'], true);
        $ahora = now();

        // Los eventos pueden llegar desordenados: un estado solo se reemplaza por otro de mayor rango (demorado < entregado < rebote/queja).
        $rango = self::RANGO[$estadoProveedor];
        $menores = array_keys(array_filter(self::RANGO, fn (int $r): bool => $r < $rango));

        $actualizados = DB::transaction(function () use ($emailId, $estadoProveedor, $esEntrega, $esRebote, $ahora, $menores): int {
            $campos = [
                'provider_status' => $estadoProveedor,
                ...($esEntrega ? ['delivered_at' => $ahora] : []),
                ...($esRebote ? ['bounced_at' => $ahora] : []),
                'updated_at' => $ahora,
            ];
            $avanza = fn ($q) => $q->whereNull('provider_status')->orWhereIn('provider_status', $menores);

            $total = 0;
            $total += MatriculaNotification::query()->where('provider_id', $emailId)->where($avanza)->update($campos);
            $total += AvisoExterno::query()->where('provider_id', $emailId)->where($avanza)->update($campos);

            $envios = ReclamacionEnvio::query()->where('proveedor_id', $emailId)->where($avanza)->with('reclamacion')->get();
            foreach ($envios as $envio) {
                $envio->forceFill([
                    'provider_status' => $estadoProveedor,
                    ...($esEntrega && $envio->delivered_at === null ? ['delivered_at' => $ahora] : []),
                    ...($esRebote && $envio->bounced_at === null ? ['bounced_at' => $ahora] : []),
                ])->save();
                $total++;

                if ($esRebote && $envio->reclamacion !== null) {
                    $this->notificarReboteReclamacion($envio->reclamacion->numero, $envio->reclamacion->identificador);
                }
            }

            return $total;
        });

        Log::info('webhook.resend', ['evento' => $tipo, 'coincidencias' => $actualizados]);

        return response()->json(['ok' => true, 'actualizados' => $actualizados]);
    }

    private function decodificarSecreto(string $secret): string
    {
        if (str_starts_with($secret, 'whsec_')) {
            $decodificado = base64_decode(substr($secret, 6), true);

            return $decodificado === false ? '' : $decodificado;
        }

        return $secret;
    }

    private function firmaValida(string $cabecera, string $esperadaBase64): bool
    {
        foreach (preg_split('/\s+/', trim($cabecera)) ?: [] as $entrada) {
            $partes = explode(',', $entrada, 2);
            if (count($partes) === 2 && $partes[0] === 'v1' && hash_equals($esperadaBase64, $partes[1])) {
                return true;
            }
        }

        return false;
    }

    private function notificarReboteReclamacion(string $numero, string $identificador): void
    {
        if (! Permission::query()->where('name', 'reclamaciones.ver')->where('guard_name', 'web')->exists()) {
            return;
        }

        foreach (User::permission('reclamaciones.ver')->get() as $usuario) {
            if ($usuario->estaSuspendido()) {
                continue;
            }
            NotificacionInterna::crearPersonal((int) $usuario->id, [
                'titulo' => "Rebote de correo en reclamación {$numero}",
                'mensaje' => 'El proveedor reportó rebote o queja en el correo enviado al consumidor. Revisa el expediente.',
                'tipo' => 'reclamacion_rebote',
                'vinculo' => '/admin/academico/reclamaciones/'.$identificador,
            ]);
        }
    }
}
