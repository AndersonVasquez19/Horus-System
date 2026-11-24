<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Entrada;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Respaldo local de PostgreSQL con `pg_dump` (formato personalizado) en un disco privado, con suma SHA-256 y retención.
 *
 * - Solo PostgreSQL: con otro motor se niega (un volcado de SQLite no es un respaldo de producción).
 * - La contraseña viaja por la variable de entorno del proceso (PGPASSWORD), nunca por la línea de comandos, y ningún mensaje
 *   de error la incluye.
 * - El archivo se escribe como temporal y solo se publica si el volcado terminó bien y no está vacío.
 * - Los nombres siguen un patrón cerrado (`horus-AAAAMMDD-HHMMSS.dump`): nada fuera de ese patrón se lista, descarga ni borra.
 * - Restaurar es una operación manual y deliberada (docs/runbook-respaldo-local.md); este servicio no restaura.
 */
final class RespaldoLocal
{
    public const PATRON = '/\Ahorus-\d{8}-\d{6}\.dump\z/';

    private const ZONA = 'America/Lima';

    /**
     * @return array{nombre: string, bytes: int, sha256: string}
     */
    public function crear(): array
    {
        $conexion = $this->conexionPostgres();
        $disco = $this->disco();
        $carpeta = $this->carpeta();
        $disco->makeDirectory($carpeta);

        $nombre = 'horus-'.CarbonImmutable::now(self::ZONA)->format('Ymd-His').'.dump';
        $temporal = $carpeta.'/'.$nombre.'.tmp';
        $rutaTemporal = $this->rutaAbsoluta($temporal);

        $comando = [
            Entrada::texto(config('respaldo.pg_dump')),
            '--format=custom', '--no-owner', '--no-privileges',
            '--host='.$conexion['host'], '--port='.$conexion['port'],
            '--username='.$conexion['username'], '--dbname='.$conexion['database'],
            '--file='.$rutaTemporal,
        ];
        $entorno = ['PGPASSWORD' => $conexion['password']];
        if ($conexion['sslmode'] !== '') {
            $entorno['PGSSLMODE'] = $conexion['sslmode'];
        }

        try {
            try {
                $resultado = Process::timeout($this->entero(config('respaldo.timeout'), 900))->env($entorno)->run($comando);
            } catch (ProcessTimedOutException) {
                // El mensaje de Symfony incluye la línea de comandos (usuario, host y base): no se propaga.
                throw new RuntimeException('pg_dump superó el tiempo máximo.');
            }
            if (! $resultado->successful()) {
                // Sin la salida del proceso: puede nombrar usuario, host o la base.
                throw new RuntimeException('pg_dump terminó con error (código '.$resultado->exitCode().').');
            }
            if (! $disco->exists($temporal) || $disco->size($temporal) <= 0) {
                throw new RuntimeException('El volcado quedó vacío.');
            }

            $destino = $carpeta.'/'.$nombre;
            $disco->move($temporal, $destino);
        } finally {
            if ($disco->exists($temporal)) {
                $disco->delete($temporal);
            }
        }

        $sha = hash_file('sha256', $this->rutaAbsoluta($destino));
        if ($sha === false) {
            $disco->delete($destino);
            throw new RuntimeException('No se pudo calcular la suma de verificación.');
        }
        $disco->put($destino.'.sha256', $sha.'  '.$nombre."\n");

        return ['nombre' => $nombre, 'bytes' => (int) $disco->size($destino), 'sha256' => $sha];
    }

    /**
     * Copias existentes, de la más reciente a la más antigua.
     *
     * @return list<array{nombre: string, bytes: int, creado: int, sha256: string|null}>
     */
    public function listar(): array
    {
        $disco = $this->disco();
        $filas = [];
        foreach ($disco->files($this->carpeta()) as $ruta) {
            $nombre = basename($ruta);
            if (preg_match(self::PATRON, $nombre) !== 1) {
                continue;
            }
            $suma = $ruta.'.sha256';
            $filas[] = [
                'nombre' => $nombre,
                'bytes' => (int) $disco->size($ruta),
                'creado' => (int) $disco->lastModified($ruta),
                'sha256' => $disco->exists($suma) ? strtok(trim($disco->get($suma) ?? ''), ' ') ?: null : null,
            ];
        }
        usort($filas, static fn (array $a, array $b): int => strcmp($b['nombre'], $a['nombre']));

        return $filas;
    }

    /** Ruta absoluta de una copia por su nombre, o null si el nombre no cumple el patrón o no existe. */
    public function ruta(string $nombre): ?string
    {
        if (preg_match(self::PATRON, $nombre) !== 1) {
            return null;
        }
        $relativa = $this->carpeta().'/'.$nombre;

        return $this->disco()->exists($relativa) ? $this->rutaAbsoluta($relativa) : null;
    }

    /** True si la suma guardada coincide con la del archivo actual. */
    public function verificar(string $nombre): bool
    {
        $ruta = $this->ruta($nombre);
        $esperada = collect($this->listar())->firstWhere('nombre', $nombre)['sha256'] ?? null;
        if ($ruta === null || $esperada === null) {
            return false;
        }

        return hash_equals($esperada, (string) hash_file('sha256', $ruta));
    }

    /**
     * Borra las copias más antiguas que la retención, conservando siempre las `minimo` más recientes.
     *
     * @return list<string> Nombres borrados (o que se borrarían con $simular).
     */
    public function podar(bool $simular = false): array
    {
        $dias = $this->entero(config('respaldo.retencion_dias'), 14);
        $minimo = $this->entero(config('respaldo.minimo'), 3);
        $limite = CarbonImmutable::now(self::ZONA)->subDays($dias)->getTimestamp();
        $disco = $this->disco();
        $borradas = [];

        foreach (array_slice($this->listar(), $minimo) as $copia) {
            if ($copia['creado'] >= $limite) {
                continue;
            }
            $borradas[] = $copia['nombre'];
            if (! $simular) {
                $ruta = $this->carpeta().'/'.$copia['nombre'];
                $disco->delete([$ruta, $ruta.'.sha256']);
            }
        }

        return $borradas;
    }

    /** @return array{host: string, port: string, database: string, username: string, password: string, sslmode: string} */
    private function conexionPostgres(): array
    {
        $configurada = Entrada::texto(config('respaldo.conexion'));
        $nombre = $configurada !== '' ? $configurada : Entrada::texto(config('database.default'));
        $c = config('database.connections.'.$nombre);
        if (! is_array($c) || ($c['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('El respaldo con pg_dump solo está disponible con PostgreSQL.');
        }

        return [
            'host' => Entrada::texto($c['host'] ?? ''),
            'port' => Entrada::texto($c['port'] ?? '5432'),
            'database' => Entrada::texto($c['database'] ?? ''),
            'username' => Entrada::texto($c['username'] ?? ''),
            'password' => Entrada::texto($c['password'] ?? ''),
            'sslmode' => Entrada::texto($c['sslmode'] ?? ''),
        ];
    }

    private function disco(): FilesystemAdapter
    {
        $nombre = Entrada::texto(config('respaldo.disco'));
        if (in_array($nombre, ['public', 's3-public'], true)) {
            throw new RuntimeException('El disco de respaldos debe ser privado.');
        }
        if (config('filesystems.disks.'.$nombre.'.visibility') === 'public') {
            throw new RuntimeException('El disco de respaldos debe ser privado.');
        }

        return Storage::disk($nombre);
    }

    private function carpeta(): string
    {
        return trim(Entrada::texto(config('respaldo.carpeta')), '/');
    }

    private function rutaAbsoluta(string $relativa): string
    {
        return $this->disco()->path($relativa);
    }

    private function entero(mixed $valor, int $porDefecto): int
    {
        return is_numeric($valor) ? (int) $valor : $porDefecto;
    }
}
