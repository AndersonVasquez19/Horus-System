<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class ConfiguracionController extends Controller implements HasMiddleware
{
    /**
     * Permisos Spatie por acción; el grupo /admin ya exige sesión y rol administrativo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:configuracion.ver', only: ['index']),
            new Middleware('permission:configuracion.editar', only: ['update']),
        ];
    }

    public function index(): View
    {
        $settings = [
            'nombre_empresa' => SystemSetting::value('nombre_empresa', 'Horus Group SRL'),
            'telefono' => SystemSetting::value('telefono', '+51 987 654 321'),
            'correo_contacto' => SystemSetting::value('correo_contacto', 'contacto@horusgroup.com'),
            'direccion' => SystemSetting::value('direccion', 'Lima, Perú'),
            'moneda' => SystemSetting::value('moneda', 'S/'),
            'api_resend' => SystemSetting::value('api_resend', 'No configurada'),
        ];

        return view('admin.configuracion.index', ['settings' => $settings]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'settings.nombre_empresa' => ['nullable', 'string', 'max:255'],
            'settings.telefono' => ['nullable', 'string', 'max:255'],
            'settings.correo_contacto' => ['nullable', 'email', 'max:255'],
            'settings.direccion' => ['nullable', 'string', 'max:255'],
            'settings.moneda' => ['nullable', 'string', 'max:20'],
            'settings.api_resend' => ['nullable', 'string', 'max:255'],
        ]);

        $changes = [];
        foreach ($data['settings'] ?? [] as $key => $value) {
            SystemSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'description' => 'Configuración del sistema'],
            );
            $changes[$key] = $value;
        }

        AuditLog::record('settings_updated', 'SystemSetting', null, $changes);

        return redirect()->route('admin.configuracion.index')->with('success', 'La configuración del sistema se guardó correctamente.');
    }
}
