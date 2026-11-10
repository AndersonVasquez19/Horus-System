<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\VerificacionRegistroService;
use App\Support\Email;
use App\Support\PasswordPolicy;
use App\Support\SesionesActivas;
use App\Support\SessionRevoker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Perfil propio del personal del panel. No requiere un permiso de Spatie adicional: cada persona gestiona solo
 * su cuenta (el usuario se toma de la sesión, nunca de la petición) y sin tocar roles ni permisos.
 * Cambiar el correo o la contraseña exige la contraseña actual, de modo que una sesión desatendida o robada no
 * baste para quedarse con la cuenta.
 */
class PerfilController extends Controller
{
    public function edit(Request $request): View
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return view('admin.perfil.edit', [
            'usuario' => $usuario,
            'sesionesDisponibles' => SesionesActivas::disponible(),
            'sesiones' => SesionesActivas::listar($usuario, $request->session()->getId()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Email::normalizeRequest($request);

        /** @var User $actual */
        $actual = $request->user();

        $cambiaCorreo = $request->has('email') && (string) $request->input('email') !== $actual->email;
        $cambiaClave = $request->filled('password');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Email::uniqueRule($actual->id)],
            'password' => ['nullable', 'string', 'confirmed', PasswordPolicy::rule()],
            // Solo se pide (y se comprueba contra la contraseña guardada) al cambiar credenciales.
            'current_password' => $cambiaCorreo || $cambiaClave ? ['required', 'string', 'current_password'] : ['nullable'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'current_password.required' => 'Escribe tu contraseña actual para cambiar el correo o la contraseña.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
        ] + PasswordPolicy::messages());

        $cambios = DB::transaction(function () use ($request, $actual, $data, $cambiaClave): array {
            $user = User::query()->whereKey($actual->getKey())->lockForUpdate()->firstOrFail();

            $antes = ['name' => $user->name, 'email' => $user->email];
            $user->fill(['name' => $data['name'], 'email' => $data['email']]);

            if ($cambiaClave) {
                $user->password = Hash::make($data['password']);
                // Revoca los JWT emitidos con la contraseña anterior.
                $user->jwt_version = (int) $user->jwt_version + 1;
            }

            $campos = array_keys($user->getDirty());
            $user->save();

            if ($cambiaClave) {
                // Cierra las demás sesiones; esta se conserva y se renueva su identificador.
                SessionRevoker::revokeSessions($user, $request->session()->getId());
                $request->session()->regenerate();
            }

            // Con la verificación de registro activa la sesión se compara con `jwt_version`.
            if (VerificacionRegistroService::enabled() && $user->hasVerifiedEmail()) {
                $request->session()->put('auth.verification_version', (int) $user->jwt_version);
            }

            if ($campos !== []) {
                // Nunca se registran contraseñas ni hashes: solo qué campos cambiaron. (La clave se llama
                // `clave_modificada` porque SensitiveData enmascara cualquier nombre con «password».)
                AuditLog::record('perfil_actualizado', User::class, $user->id, [
                    'campos' => array_values(array_diff($campos, ['password', 'jwt_version', 'remember_token', 'email_verified_at'])),
                    'clave_modificada' => $cambiaClave,
                    'email_antes' => $antes['email'] !== $user->email ? $antes['email'] : null,
                ], $user->id);
            }

            return $campos;
        });

        return redirect()->route('admin.perfil.edit')->with(
            'success',
            $cambios === [] ? 'No hubo cambios en tu perfil.' : 'Tu perfil se actualizó correctamente.',
        );
    }
}
