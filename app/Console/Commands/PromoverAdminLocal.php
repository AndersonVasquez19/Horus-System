<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Email;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Concede el rol admin a una cuenta YA existente de la base local del desarrollador.
 *
 * Solo se ejecuta desde la terminal (no hay ruta HTTP), solo en entornos local/development,
 * solo contra una base en la propia máquina y exige escribir el nombre de esa base como
 * confirmación. No crea cuentas, no ejecuta seeders ni migraciones y no imprime secretos.
 */
class PromoverAdminLocal extends Command
{
    /** Entornos donde el comando puede ejecutarse. `testing` solo con SQLite en memoria. */
    private const ENTORNOS_PERMITIDOS = ['local', 'development'];

    private const HOSTS_LOCALES = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    protected $signature = 'horus:promover-admin
        {--email= : Correo de la cuenta que ya se registró en esta instancia}
        {--base= : Nombre de la base de datos local, como confirmación explícita}';

    protected $description = 'Promueve una cuenta existente al rol admin en la base LOCAL del desarrollador';

    public function handle(): int
    {
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}", []);

        if (($problem = $this->entornoNoAutorizado($config)) !== null
            || ($problem = $this->baseNoLocal($config)) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $baseActual = $this->nombreDeBase($config);
        $this->line("Destino: conexión «{$connection}», base «{$baseActual}», entorno «{$this->laravel->environment()}».");

        $confirmada = (string) ($this->option('base') ?: $this->ask('Escribe el nombre de esta base de datos para confirmar'));
        if ($confirmada === '' || ! hash_equals($baseActual, $confirmada)) {
            $this->error('La base indicada no coincide con la base conectada. No se cambió nada.');

            return self::FAILURE;
        }

        $email = Email::normalize($this->option('email') ?: $this->ask('Correo de la cuenta a promover'));
        if ($email === '') {
            $this->error('Indica el correo de la cuenta (--email=correo@ejemplo.com).');

            return self::FAILURE;
        }

        $existentes = Role::query()->where('guard_name', 'web')
            ->whereIn('name', RolesAndPermissionsSeeder::STAFF_ROLES)->pluck('name')->all();
        $faltantes = array_values(array_diff(RolesAndPermissionsSeeder::STAFF_ROLES, $existentes));
        if ($faltantes !== []) {
            $this->error('Faltan roles en esta base: '.implode(', ', $faltantes).'.');
            $this->line('Este comando no ejecuta seeders. Si es una base local desechable, revisa y ejecuta tú mismo:');
            $this->line('  php artisan db:seed --class=RolesAndPermissionsSeeder');

            return self::FAILURE;
        }

        $usuarios = User::query()->whereRaw('lower(email) = ?', [$email])->get();
        if ($usuarios->isEmpty()) {
            $this->error('No existe una cuenta con ese correo en esta base. Regístrala primero en /register y vuelve a ejecutar el comando.');

            return self::FAILURE;
        }
        if ($usuarios->count() > 1) {
            $this->error('Hay varias cuentas con ese correo (difieren solo en mayúsculas). Resuelve el duplicado antes de promover.');

            return self::FAILURE;
        }

        $usuario = $usuarios->first();

        if ($usuario->hasRole('admin')) {
            $this->info("La cuenta #{$usuario->getKey()} ya tiene el rol admin. No se cambió nada.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($usuario): void {
            $usuario->assignRole('admin');

            AuditLog::record('admin_role_granted_local', User::class, $usuario->getKey(), [
                'via' => 'artisan horus:promover-admin',
            ], null);
        });

        $this->info("Listo: la cuenta #{$usuario->getKey()} ahora tiene el rol admin en la base «{$baseActual}».");
        $this->line('Inicia sesión en /login para entrar a /admin.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $config */
    private function entornoNoAutorizado(array $config): ?string
    {
        $entorno = (string) $this->laravel->environment();

        if (in_array($entorno, self::ENTORNOS_PERMITIDOS, true)) {
            return null;
        }

        $sqliteEnMemoria = $entorno === 'testing'
            && ($config['driver'] ?? null) === 'sqlite'
            && ($config['database'] ?? null) === ':memory:';

        return $sqliteEnMemoria
            ? null
            : "Entorno «{$entorno}» no autorizado. Solo se permite en: ".implode(', ', self::ENTORNOS_PERMITIDOS).'.';
    }

    /** @param array<string, mixed> $config */
    private function baseNoLocal(array $config): ?string
    {
        $host = $config['host'] ?? null;

        if (! empty($config['url'])) {
            $host = parse_url((string) $config['url'], PHP_URL_HOST) ?: null;
        }

        if ($host === null || $host === '') {
            return null; // SQLite o socket local por defecto.
        }

        if (! is_string($host)) {
            return 'La conexión usa varios hosts; no se puede comprobar que la base sea local.';
        }

        if (in_array(strtolower($host), self::HOSTS_LOCALES, true) || str_starts_with($host, '/')) {
            return null;
        }

        return 'La base no está en esta máquina (host no local). Por seguridad el comando no se ejecuta contra servidores remotos.';
    }

    /** @param array<string, mixed> $config */
    private function nombreDeBase(array $config): string
    {
        $base = (string) ($config['database'] ?? '');

        if (($config['driver'] ?? null) === 'sqlite' && $base !== ':memory:') {
            return basename($base);
        }

        return $base;
    }
}
