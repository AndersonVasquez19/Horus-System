<?php

namespace App\Providers;

use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\ServicioPublicacion;
use App\Models\ServicioTecnologico;
use App\Observers\PublicacionCatalogoAdvisorObserver;
use App\Support\Email;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Curso::observe(PublicacionCatalogoAdvisorObserver::class);
        CursoPublicacion::observe(PublicacionCatalogoAdvisorObserver::class);
        ServicioTecnologico::observe(PublicacionCatalogoAdvisorObserver::class);
        ServicioPublicacion::observe(PublicacionCatalogoAdvisorObserver::class);

        // Identificadores numéricos del panel: un valor no numérico responde 404 en lugar de llegar a la
        // consulta (PostgreSQL lo rechaza con SQLSTATE 22P02 y la petición terminaría en 500).
        foreach (['aprobacion', 'curso', 'estudiante', 'matricula', 'notificacion', 'role', 'servicio', 'user'] as $parametro) {
            Route::pattern($parametro, '[0-9]+');
        }

        RateLimiter::for('verification-confirm', fn (Request $request) => Limit::perMinute(10)->by('ip:'.$request->ip()));

        // `throttle:N,M` numérico firma solo por dominio|IP: todas las rutas así declaradas comparten un único
        // contador. Los limitadores con nombre separan el cupo de cada superficie conservando los mismos límites.
        // Registro web y API comparten cupo a propósito (Sprint 13).
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by('register:'.$request->ip()));
        RateLimiter::for('verification-resend', fn (Request $request) => Limit::perMinute(5)->by('verification-resend:'.$request->ip()));
        RateLimiter::for('password-recovery', fn (Request $request) => Limit::perMinute(5)->by('password-recovery:'.$request->ip()));
        RateLimiter::for('matricula-publica', fn (Request $request) => Limit::perMinutes(10, 5)->by('matricula-publica:'.$request->ip()));
        RateLimiter::for('cotizacion-publica', fn (Request $request) => Limit::perMinutes(10, 5)->by('cotizacion-publica:'.$request->ip()));
        // Perfil propio: la contraseña actual se comprueba en cada intento, así que se limita por usuario
        // para que una sesión robada no sirva para adivinarla.
        RateLimiter::for('perfil', fn (Request $request) => Limit::perMinute(5)->by('perfil:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Exportaciones del panel (CSV, Excel, PDF, auditoría, respaldo): costosas y, en varias, con efecto lateral
        // en la auditoría. Se limitan por usuario autenticado y comparten un único cupo.
        RateLimiter::for('admin-export', fn (Request $request) => Limit::perMinute(6)->by(
            'admin-export:'.($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
        // Selectores de matrícula: una consulta por pausa al teclear; se limita por usuario sin estorbar el uso normal.
        RateLimiter::for('admin-opciones', fn (Request $request) => Limit::perMinute(120)->by(
            'admin-opciones:'.($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
        // Escrituras y subidas de archivos del panel: acota ráfagas automatizadas sin afectar la operación humana.
        RateLimiter::for('admin-write', fn (Request $request) => Limit::perMinute(120)->by(
            'admin-write:'.($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
        RateLimiter::for('chat',fn (Request $request) => Limit::perMinute(30)->by(
            $request->user() !== null ? 'chat-user:'.$request->user()->getAuthIdentifier() : 'chat-ip:'.$request->ip(),
        ));

        // Login web y API: límite por IP y también por correo, para frenar ataques distribuidos
        // contra una misma cuenta sin que un solo correo bloquee a todos los demás.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(10)->by('email:'.Email::normalize($request->input('email'))),
        ]);

        // Libro de Reclamaciones (Sprint 20): por IP (corto y diario) y por correo normalizado. La clave del correo
        // se guarda como hash para no dejar datos personales en la caché. Aún no hay ruta pública que lo use.
        RateLimiter::for('reclamaciones', fn (Request $request) => [
            Limit::perMinutes(10, (int) config('reclamaciones.antispam.limite_ip_10min', 3))->by('reclamaciones-ip10:'.$request->ip()),
            Limit::perDay((int) config('reclamaciones.antispam.limite_ip_dia', 10))->by('reclamaciones-ipdia:'.$request->ip()),
            Limit::perDay((int) config('reclamaciones.antispam.limite_correo_dia', 3))->by('reclamaciones-correo:'.hash('sha256', Email::normalize($request->input('email')))),
        ]);
    }
}
