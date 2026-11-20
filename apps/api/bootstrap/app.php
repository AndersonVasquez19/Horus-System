<?php

use App\Http\Middleware\AutenticarBffPanel;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ErroresApiPanel;
use App\Support\Testing\TestDatabaseSafety;
use App\Support\UrlWeb;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // API de los paneles de apps/web: sesión web + CSRF, solo para el BFF de Next (ver routes/panel.php).
        then: function (): void {
            Route::middleware([AutenticarBffPanel::class, 'web'])
                ->prefix('api/panel/v1')
                ->name('api.panel.')
                ->group(__DIR__.'/../routes/panel.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Detrás de un balanceador, sin proxies de confianza `$request->ip()` sería la IP del proxy y todos los
        // limitadores por IP compartirían un único cubo. Vacío (valor por defecto) conserva el comportamiento actual.
        $proxies = array_values(array_filter(array_map('trim', explode(',', (is_string($proxiesCrudos = Env::get('TRUSTED_PROXIES', '')) ? $proxiesCrudos : '')))));
        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);
        }

        $middleware->append(SecurityHeaders::class);
        // Una ruta con `auth` sin sesión (petición no JSON) manda a iniciar sesión a la interfaz Next, no a una ruta de Laravel.
        $middleware->redirectGuestsTo(fn (): string => UrlWeb::ruta('/login'));
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'jwt.auth' => AuthenticateJwt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ErroresApiPanel::registrar($exceptions);
    })->create();

if (Env::get('HORUS_PG_TEST_PROFILE') !== null && Env::get('HORUS_PG_TEST_PROFILE') !== '') {
    // Never fall back to the daily development .env in the isolated profile.
    $app->loadEnvironmentFrom('.env.pgsql-testing.local');
}

// Before providers boot and before RefreshDatabase can invoke migrate:fresh.
$app->afterBootstrapping(LoadConfiguration::class, TestDatabaseSafety::install(...));

return $app;
