<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

class FirmaCatalogoAdvisor
{
    private const CAMPOS = ['schema_version', 'catalog_version', 'source_system', 'generated_at', 'expires_at', 'items'];

    /** @param array<string, mixed> $catalogo */
    public function canonico(array $catalogo): string
    {
        $payload = [];
        foreach (self::CAMPOS as $campo) {
            if (! array_key_exists($campo, $catalogo)) {
                throw new InvalidArgumentException('Catálogo incompleto.');
            }
            $payload[$campo] = $catalogo[$campo];
        }

        return json_encode($this->ordenar($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    /** @param array<string, mixed> $catalogo */
    public function firmar(array $catalogo): string
    {
        $key = config('horus-advisor.signing_key');
        if (! is_string($key) || mb_strlen($key) < 32) {
            throw new InvalidArgumentException('Falta una clave de firma válida.');
        }

        return 'hmac-sha256:'.hash_hmac('sha256', $this->canonico($catalogo), $key);
    }

    /** @param array<string, mixed> $catalogo */
    public function verificar(array $catalogo): bool
    {
        return is_string($catalogo['signature'] ?? null)
            && hash_equals($this->firmar($catalogo), $catalogo['signature']);
    }

    private function ordenar(mixed $valor): mixed
    {
        if (is_array($valor)) {
            if (! array_is_list($valor)) {
                ksort($valor, SORT_STRING);
            }

            return array_map($this->ordenar(...), $valor);
        }
        // El exportador no genera floats ni objetos: evita diferencias entre runtimes.
        if (! is_string($valor) && ! is_int($valor) && ! is_bool($valor) && $valor !== null) {
            throw new InvalidArgumentException('Tipo no admitido en el catálogo.');
        }

        return $valor;
    }
}
