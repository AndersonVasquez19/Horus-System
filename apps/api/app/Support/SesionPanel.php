<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Estado de la sesión web usada por la API de los paneles de apps/web.
 */
final class SesionPanel
{
    private const CLAVE_INICIO = 'panel.autenticado_en';

    /** Momento del inicio de sesión: base de la vida máxima absoluta. */
    public static function iniciar(Request $request): void
    {
        $request->session()->put(self::CLAVE_INICIO, now()->getTimestamp());
    }

    public static function marcarSiFalta(Request $request): void
    {
        if (! is_int($request->session()->get(self::CLAVE_INICIO))) {
            self::iniciar($request);
        }
    }

    public static function expiraEn(Request $request): ?int
    {
        $inicio = $request->session()->get(self::CLAVE_INICIO);

        return is_int($inicio) ? $inicio + ((int) config('panel.sesion_absoluta_minutos')) * 60 : null;
    }

    public static function expirada(Request $request): bool
    {
        $expira = self::expiraEn($request);

        return $expira !== null && now()->getTimestamp() >= $expira;
    }

    /** Cierra la sesión: desautentica, invalida la sesión y rota el token CSRF. */
    public static function cerrar(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /** Un usuario puede usar la API del panel si tiene al menos un permiso de entrada. */
    public static function tieneAccesoAlgunPanel(User $user): bool
    {
        return $user->hasAnyPermission(RolesAndPermissionsSeeder::PANEL_PERMISSIONS);
    }

    /**
     * Representación mínima de la sesión para el BFF. Los permisos se leen en cada petición (sin caché en Next);
     * el menú de Next solo los usa para ocultar opciones: Laravel vuelve a comprobar cada operación.
     *
     * @return array<string, mixed>
     */
    public static function representar(Request $request, User $user): array
    {
        $permisos = $user->getAllPermissions()->pluck('name')->sort()->values()->all();

        return [
            'usuario' => ['id' => $user->getKey(), 'nombre' => $user->name, 'email' => $user->email],
            'roles' => $user->getRoleNames()->sort()->values()->all(),
            'permisos' => $permisos,
            'paneles' => [
                'academico' => in_array(RolesAndPermissionsSeeder::PANEL_ACADEMICO, $permisos, true),
            ],
            'csrf_token' => $request->session()->token(),
            'expira_en' => self::expiraEn($request),
        ];
    }
}
