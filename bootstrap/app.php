<?php

use App\Http\Middleware\AuthenticateJwt;
use App\Support\Testing\TestDatabaseSafety;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Env;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'jwt.auth' => AuthenticateJwt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

if (Env::get('HORUS_PG_TEST_PROFILE') !== null && Env::get('HORUS_PG_TEST_PROFILE') !== '') {
    // Never fall back to the daily development .env in the isolated profile.
    $app->loadEnvironmentFrom('.env.pgsql-testing.local');
}

// Before providers boot and before RefreshDatabase can invoke migrate:fresh.
$app->afterBootstrapping(LoadConfiguration::class, TestDatabaseSafety::install(...));

return $app;
