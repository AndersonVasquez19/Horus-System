<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Http\Middleware\AutenticarBffPanel;
use App\Http\Middleware\SesionPanelVigente;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Rutas;
use Tests\TestCase;

/**
 * Red de seguridad de /api/panel/v1: el grupo /admin de Blade solo exigía rol, de modo que una ruta nueva sin
 * permiso quedaba abierta a todo el personal. Aquí ninguna ruta del panel puede existir sin su puerta.
 */
final class InventarioRutasPanelTest extends TestCase
{
    /** Rutas públicas de autenticación: sin sesión, pero con la puerta del BFF y el grupo web. */
    private const PUBLICAS = [
        'api/panel/v1/auth/csrf',
        'api/panel/v1/auth/login',
        'api/panel/v1/auth/registro',
        'api/panel/v1/auth/contrasena/solicitar',
        'api/panel/v1/auth/contrasena/restablecer',
    ];

    /** Rutas autenticadas comunes a ambos paneles (no exigen permiso de módulo). */
    private const SOLO_SESION = [
        'api/panel/v1/auth/sesion',
        'api/panel/v1/auth/logout',
    ];

    /**
     * Dashboards y búsqueda global: solo el permiso de entrada, porque filtran cada bloque o grupo de resultados por el permiso de
     * su módulo (lo cubren sus pruebas propias, `tests/Academic/Panel/TableroTest.php`).
     */
    private const SOLO_ENTRADA = [
        'api/panel/v1/academico/resumen',
        'api/panel/v1/academico/busqueda',
        'api/panel/v1/servicios/resumen',
    ];

    /**
     * Módulos compartidos de la cuenta propia (notificaciones y perfil): sin permiso de módulo, porque el usuario sale
     * siempre de la sesión. Exigen el permiso de entrada a cualquiera de los dos paneles.
     */
    private const CUENTA_PROPIA_PREFIJOS = [
        'api/panel/v1/compartido/notificaciones',
        'api/panel/v1/compartido/perfil',
    ];

    private function esCuentaPropia(string $uri): bool
    {
        foreach (self::CUENTA_PROPIA_PREFIJOS as $prefijo) {
            if (str_starts_with($uri, $prefijo)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Route> */
    private function rutasPanel(): array
    {
        return array_values(array_filter(
            Rutas::getRoutes()->getRoutes(),
            fn (Route $ruta): bool => str_starts_with($ruta->uri(), 'api/panel/'),
        ));
    }

    public function test_existen_rutas_del_panel(): void
    {
        $this->assertNotEmpty($this->rutasPanel());
    }

    public function test_toda_ruta_del_panel_pasa_por_el_bff_y_la_sesion_web(): void
    {
        foreach ($this->rutasPanel() as $ruta) {
            $middleware = $ruta->gatherMiddleware();
            $this->assertContains(AutenticarBffPanel::class, $middleware, $ruta->uri());
            $this->assertContains('web', $middleware, $ruta->uri());
            $this->assertStringStartsWith('api/panel/v1/', $ruta->uri());
        }
    }

    public function test_toda_ruta_privada_exige_sesion_vigente_y_permisos(): void
    {
        foreach ($this->rutasPanel() as $ruta) {
            if (in_array($ruta->uri(), self::PUBLICAS, true)) {
                continue;
            }

            $middleware = $ruta->gatherMiddleware();
            $this->assertContains('auth', $middleware, $ruta->uri());
            $this->assertContains(SesionPanelVigente::class, $middleware, $ruta->uri());

            if (in_array($ruta->uri(), self::SOLO_SESION, true)) {
                continue;
            }

            $permisos = array_values(array_filter($middleware, fn ($m): bool => is_string($m) && str_starts_with($m, 'permission:')));
            $entrada = array_filter($permisos, fn (string $m): bool => str_starts_with($m, 'permission:panel.'));
            $this->assertNotEmpty($entrada, "{$ruta->uri()} no exige permiso de entrada al panel");

            if (in_array($ruta->uri(), self::SOLO_ENTRADA, true) || $this->esCuentaPropia($ruta->uri())) {
                continue;
            }

            // El permiso de módulo puede ir en la ruta o en el controlador (HasMiddleware).
            $controlador = $ruta->controllerMiddleware();
            $modulo = array_filter([...$permisos, ...$controlador], fn ($m): bool => is_string($m)
                && str_starts_with($m, 'permission:') && ! str_starts_with($m, 'permission:panel.'));
            $this->assertNotEmpty($modulo, "{$ruta->uri()} no exige permiso de módulo");
        }
    }

    public function test_las_rutas_de_un_panel_exigen_su_propio_permiso_de_entrada(): void
    {
        foreach ($this->rutasPanel() as $ruta) {
            $middleware = $ruta->gatherMiddleware();
            if (str_starts_with($ruta->uri(), 'api/panel/v1/academico/')) {
                $this->assertContains('permission:panel.academico.acceder', $middleware, $ruta->uri());
                $this->assertNotContains('permission:panel.servicios.acceder', $middleware, $ruta->uri());
            }
            if (str_starts_with($ruta->uri(), 'api/panel/v1/servicios/')) {
                $this->assertContains('permission:panel.servicios.acceder', $middleware, $ruta->uri());
                $this->assertNotContains('permission:panel.academico.acceder', $middleware, $ruta->uri());
            }
            if (str_starts_with($ruta->uri(), 'api/panel/v1/compartido/')) {
                // Basta cualquiera de los dos permisos de entrada (nunca uno solo de ellos).
                $this->assertContains('permission:panel.academico.acceder|panel.servicios.acceder', $middleware, $ruta->uri());
            }
        }
    }
}
