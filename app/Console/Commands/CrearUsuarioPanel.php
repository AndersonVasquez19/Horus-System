<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Email;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Aprovisiona una cuenta del panel sin credenciales versionadas.
 * La contraseña se pide de forma oculta y nunca se imprime. No modifica cuentas existentes.
 */
class CrearUsuarioPanel extends Command
{
    protected $signature = 'horus:crear-usuario
        {--email= : Correo de la nueva cuenta}
        {--name= : Nombre visible}
        {--rol=admin : Rol del panel (admin, coordinador o secretaria)}';

    protected $description = 'Crea una cuenta del panel administrativo pidiendo la contraseña de forma oculta';

    public function handle(): int
    {
        $rol = (string) $this->option('rol');
        if (! in_array($rol, RolesAndPermissionsSeeder::STAFF_ROLES, true)) {
            $this->error('Rol no válido. Usa: '.implode(', ', RolesAndPermissionsSeeder::STAFF_ROLES).'.');

            return self::FAILURE;
        }

        if (! Role::where('name', $rol)->where('guard_name', 'web')->exists()) {
            $this->error('El rol no existe. Ejecuta antes: php artisan db:seed --class=RolesAndPermissionsSeeder');

            return self::FAILURE;
        }

        $email = Email::normalize((string) ($this->option('email') ?: $this->ask('Correo')));
        $name = trim((string) ($this->option('name') ?: $this->ask('Nombre')));

        // Sin distinguir mayúsculas, como el registro y el panel: una fila histórica con mayúsculas es la misma cuenta.
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            $this->error('Ya existe una cuenta con ese correo. No se modificó.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Contraseña (no se mostrará)');
        $confirmation = (string) $this->secret('Repite la contraseña');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'email' => ['required', 'string', 'email', 'max:255', Email::uniqueRule(null, 'Ya existe una cuenta con ese correo. No se modificó.')],
                'name' => ['required', 'string', 'max:120'],
                'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password, $rol): void {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->assignRole($rol);
        });

        $this->info("Cuenta creada con el rol {$rol}.");

        return self::SUCCESS;
    }
}
