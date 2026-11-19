<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConsultaDniException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consulta de DNI peruano mediante JSON.pe. Servicio ÚNICO y compartido: estudiantes, docentes y cualquier módulo futuro lo
 * usan; nada más habla con el proveedor.
 *
 * Contrato del proveedor (documentación entregada por el propietario, 16/11/2026): `POST {url}` con cuerpo `{ "dni": "8 dígitos" }`,
 * autenticación `Bearer <token>` y respuesta `{ "success": true, "data": { "numero", "nombres", "apellido_paterno",
 * "apellido_materno", "codigo_verificacion" } }`. Solo se usan esos campos documentados: no se inventan ni se completan datos.
 *
 * Seguridad: el token sale únicamente de `config('services.jsonpe.token')` (servidor), no se registra ni se devuelve; el
 * proveedor solo se llama por HTTPS; los mensajes del proveedor no se reenvían a la persona usuaria; los logs llevan solo el
 * resultado y el estado HTTP, nunca el DNI ni los nombres. Las consultas correctas se guardan 10 minutos (clave hasheada)
 * para no gastar cupo del proveedor por reintentos.
 */
class JsonPeService
{
    public const TTL_CACHE_SEGUNDOS = 600;

    /**
     * @return array{dni: string, nombres: string, apellido_paterno: string, apellido_materno: string}
     *
     * @throws ConsultaDniException
     */
    public function consultarDni(string $dni): array
    {
        if (preg_match('/\A[0-9]{8}\z/', $dni) !== 1) {
            // Defensa en profundidad: la validación HTTP ya lo exige; nunca se envía al proveedor un valor distinto.
            throw ConsultaDniException::de(ConsultaDniException::NO_ENCONTRADO);
        }

        $clave = 'jsonpe:dni:'.hash_hmac('sha256', $dni, (string) config('app.key'));
        $enCache = Cache::get($clave);
        if (is_array($enCache)) {
            return $enCache;
        }

        $resultado = $this->normalizar($dni, $this->solicitar($dni));
        Cache::put($clave, $resultado, self::TTL_CACHE_SEGUNDOS);

        return $resultado;
    }

    private function solicitar(string $dni): Response
    {
        $token = config('services.jsonpe.token');
        $url = (string) config('services.jsonpe.url');
        if (! is_string($token) || trim($token) === '' || ! str_starts_with($url, 'https://')) {
            Log::error('jsonpe.configuracion_invalida');
            throw ConsultaDniException::de(ConsultaDniException::NO_CONFIGURADO);
        }

        try {
            $respuesta = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout(5)
                ->timeout(15)
                ->post($url, ['dni' => $dni]);
        } catch (ConnectionException) {
            Log::warning('jsonpe.sin_conexion');
            throw ConsultaDniException::de(ConsultaDniException::NO_DISPONIBLE);
        } catch (Throwable) {
            Log::warning('jsonpe.error_inesperado');
            throw ConsultaDniException::de(ConsultaDniException::NO_DISPONIBLE);
        }

        if ($respuesta->successful()) {
            return $respuesta;
        }

        $estado = $respuesta->status();
        Log::warning('jsonpe.error_http', ['estado' => $estado]);

        throw ConsultaDniException::de(match (true) {
            $estado === 401 || $estado === 403 => ConsultaDniException::NO_AUTORIZADO,
            $estado === 404 => ConsultaDniException::NO_ENCONTRADO,
            $estado === 429 => ConsultaDniException::LIMITE,
            default => ConsultaDniException::NO_DISPONIBLE,
        });
    }

    /**
     * @return array{dni: string, nombres: string, apellido_paterno: string, apellido_materno: string}
     */
    private function normalizar(string $dni, Response $respuesta): array
    {
        try {
            $cuerpo = $respuesta->json();
        } catch (RequestException|Throwable) {
            $cuerpo = null;
        }

        if (! is_array($cuerpo) || ! array_key_exists('success', $cuerpo)) {
            Log::warning('jsonpe.respuesta_ilegible');
            throw ConsultaDniException::de(ConsultaDniException::RESPUESTA_INCOMPLETA);
        }

        // `success: false` con HTTP 2xx: el proveedor no encontró o no validó el DNI. Su mensaje no se reenvía.
        $datos = $cuerpo['data'] ?? null;
        if ($cuerpo['success'] !== true || ! is_array($datos)) {
            Log::info('jsonpe.dni_no_encontrado');
            throw ConsultaDniException::de(ConsultaDniException::NO_ENCONTRADO);
        }

        $numero = $datos['numero'] ?? null;
        $nombres = $this->texto($datos['nombres'] ?? null);
        $paterno = $this->texto($datos['apellido_paterno'] ?? null);
        $materno = $this->texto($datos['apellido_materno'] ?? null);

        // Debe coincidir el DNI consultado y venir nombres y primer apellido. El segundo apellido puede ser vacío.
        if ($numero === null || (string) $numero !== $dni || $nombres === '' || $paterno === '') {
            Log::warning('jsonpe.respuesta_incompleta');
            throw ConsultaDniException::de(ConsultaDniException::RESPUESTA_INCOMPLETA);
        }

        return ['dni' => $dni, 'nombres' => $nombres, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno];
    }

    private function texto(mixed $valor): string
    {
        return is_string($valor) ? trim((string) preg_replace('/\s+/u', ' ', $valor)) : '';
    }
}
