<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Support\ConfiguracionSistema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ConfiguracionController extends Controller implements HasMiddleware
{
    /**
     * Ajustes editables desde el panel y su valor por defecto ({@see ConfiguracionSistema::PREDETERMINADOS}).
     *
     * Los secretos (p. ej. la clave de Resend) NO se gestionan aquí: el transporte de correo
     * lee `RESEND_API_KEY` desde el entorno (config/services.php), nunca desde la base de datos.
     */
    private const SETTINGS = ConfiguracionSistema::PREDETERMINADOS;

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
        $settings = [];
        foreach (self::SETTINGS as $key => $default) {
            $settings[$key] = SystemSetting::value($key, $default);
        }

        return view('admin.configuracion.index', ['settings' => $settings]);
    }

    public function update(Request $request): RedirectResponse
    {
        // Solo se validan (y por tanto solo se persisten) las claves permitidas; cualquier otra,
        // incluida la antigua `api_resend`, se ignora.
        $data = $request->validate([
            'settings.nombre_empresa' => ['nullable', 'string', 'max:255'],
            'settings.telefono' => ['nullable', 'string', 'max:255'],
            'settings.correo_contacto' => ['nullable', 'email', 'max:255'],
            'settings.direccion' => ['nullable', 'string', 'max:255'],
            'settings.moneda' => ['nullable', 'string', 'max:20'],
        ]);

        $huboCambios = DB::transaction(function () use ($data): bool {
            $antes = [];
            $despues = [];
            foreach ($data['settings'] ?? [] as $key => $value) {
                if (! array_key_exists($key, self::SETTINGS)) {
                    continue;
                }

                // Se compara el valor efectivo (vacío = predeterminado): guardar lo mismo no es un cambio.
                $anterior = SystemSetting::value($key, self::SETTINGS[$key]);
                $anteriorEfectivo = is_string($anterior) && trim($anterior) !== '' ? $anterior : self::SETTINGS[$key];
                $nuevoEfectivo = is_string($value) && trim($value) !== '' ? $value : self::SETTINGS[$key];
                if ($nuevoEfectivo === $anteriorEfectivo) {
                    continue;
                }

                SystemSetting::updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'description' => 'Configuración del sistema'],
                );
                $antes[$key] = $anteriorEfectivo;
                $despues[$key] = $value;
            }

            if ($despues === []) {
                return false;
            }

            AuditLog::record('settings_updated', 'SystemSetting', null, ['antes' => $antes, 'despues' => $despues]);

            return true;
        });

        ConfiguracionSistema::olvidar();

        return redirect()->route('admin.configuracion.index')->with(
            'success',
            $huboCambios ? 'La configuración del sistema se guardó correctamente.' : 'No hubo cambios en la configuración.',
        );
    }
}
