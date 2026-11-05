<?php

declare(strict_types=1);

namespace App\Support;

use DateInterval;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Collection\Memory\SimpleCache3;
use RuntimeException;

/**
 * Caché PSR-16 por exportación. Solo el índice permanece en RAM; las celdas van a disco.
 * tmpfile() elimina el archivo al cerrar el recurso, también al finalizar PHP.
 * Nunca se comparte con la caché de la aplicación ni se aplica un TTL por defecto.
 */
final class SpreadsheetCellCache extends SimpleCache3
{
    private const BUFFER_SIZE = 1048576;

    /** @var resource|null */
    private $stream;

    /** @var array<string, string> Posición, longitud y expiración empaquetadas (24 bytes). */
    private array $offsets = [];

    private string $pending = '';

    private int $writtenBytes = 0;

    private string $readWindow = '';

    private int $readOffset = 0;

    public function __construct()
    {
        $stream = tmpfile();
        if ($stream === false) {
            throw new RuntimeException('No se pudo crear la caché temporal del Excel.');
        }
        $this->stream = $stream;
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $this->stream();
        $expires = $ttl instanceof DateInterval
            ? (new DateTimeImmutable)->add($ttl)->getTimestamp()
            : ($ttl === null ? 0 : time() + $ttl);
        if ($expires !== 0 && $expires <= time()) {
            return $this->delete($key);
        }
        $data = serialize($value);
        $offset = $this->writtenBytes + strlen($this->pending);
        $length = strlen($data);
        $this->pending .= $data;
        $this->offsets[$key] = pack('J3', $offset, $length, $expires);
        if (strlen($this->pending) >= self::BUFFER_SIZE) {
            $this->flushWrites();
        }

        return true;
    }

    private function flushWrites(): void
    {
        $stream = $this->stream();
        if (fseek($stream, 0, SEEK_END) !== 0 || ftell($stream) !== $this->writtenBytes) {
            throw new RuntimeException('No se pudo posicionar la caché del Excel.');
        }
        $length = strlen($this->pending);
        $written = 0;
        while ($written < $length) {
            $bytes = fwrite($stream, substr($this->pending, $written));
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('No se pudo escribir la caché del Excel.');
            }
            $written += $bytes;
        }
        $this->writtenBytes += $length;
        $this->pending = '';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->has($key)) {
            return $default;
        }
        $entry = unpack('Joffset/Jlength/Jexpires', $this->offsets[$key]);
        if ($entry['offset'] >= $this->writtenBytes) {
            $data = substr($this->pending, $entry['offset'] - $this->writtenBytes, $entry['length']);
        } else {
            if ($entry['offset'] < $this->readOffset
                || $this->readOffset + strlen($this->readWindow) < $entry['offset'] + $entry['length']) {
                $stream = $this->stream();
                if (fseek($stream, $entry['offset']) !== 0) {
                    throw new RuntimeException('No se pudo leer la caché del Excel.');
                }
                $window = stream_get_contents($stream, max(self::BUFFER_SIZE, $entry['length']));
                if ($window === false) {
                    throw new RuntimeException('No se pudo leer la caché del Excel.');
                }
                $this->readOffset = $entry['offset'];
                $this->readWindow = $window;
            }
            $data = substr($this->readWindow, $entry['offset'] - $this->readOffset, $entry['length']);
        }
        if (strlen($data) !== $entry['length']) {
            throw new RuntimeException('La caché temporal del Excel está incompleta.');
        }

        // Solo contiene objetos serializados por esta misma instancia privada.
        return unserialize($data);
    }

    public function has(string $key): bool
    {
        if (! isset($this->offsets[$key])) {
            return false;
        }
        $entry = unpack('Joffset/Jlength/Jexpires', $this->offsets[$key]);
        if ($entry['expires'] !== 0 && $entry['expires'] <= time()) {
            $this->delete($key);

            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->offsets[$key]);

        return true;
    }

    public function clear(): bool
    {
        if (! ftruncate($this->stream(), 0)) {
            throw new RuntimeException('No se pudo vaciar la caché del Excel.');
        }
        $this->offsets = [];
        $this->pending = $this->readWindow = '';
        $this->writtenBytes = $this->readOffset = 0;

        return true;
    }

    /** @return resource */
    private function stream()
    {
        if (! is_resource($this->stream)) {
            throw new RuntimeException('La caché temporal del Excel está cerrada.');
        }

        return $this->stream;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
        $this->offsets = [];
        $this->pending = $this->readWindow = '';
        $this->writtenBytes = $this->readOffset = 0;
    }

    public function __destruct()
    {
        $this->close();
    }
}
