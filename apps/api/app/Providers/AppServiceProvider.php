<?php

namespace App\Providers;

use App\Http\Controllers\Api\Publico\ChatPublicoController;
use App\Models\Asistencia;
use App\Models\Convocatoria;
use App\Models\CuotaObligacion;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\Matricula;
use App\Models\ObligacionPago;
use App\Models\ServicioPublicacion;
use App\Models\ServicioTecnologico;
use App\Models\SesionClase;
use App\Models\TransaccionPago;
use App\Observers\PublicacionCatalogoAdvisorObserver;
use App\Services\Panel\ResumenAcademico;
use App\Support\Email;
use App\Support\Entrada;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // Tablero: los bloques costosos se guardan unos segundos; toda escritura de un modelo del que dependen cambia la versión de la
        // clave (tras confirmar la transacción) para que el dato nuevo se vea al recargar. Ver ResumenAcademico::recordar().
        foreach ([Matricula::class, TransaccionPago::class, ObligacionPago::class, CuotaObligacion::class, Convocatoria::class, SesionClase::class, Asistencia::class] as $modelo) {
            foreach (['saved', 'deleted', 'restored'] as $evento) {
                Event::listen("eloquent.{$evento}: {$modelo}", static function (): void {
                    DB::afterCommit(static fn () => ResumenAcademico::invalidar());
                });
            }
        }

        Curso::observe(PublicacionCatalogoAdvisorObserver::class);
        CursoPublicacion::observe(PublicacionCatalogoAdvisorObserver::class);
        ServicioTecnologico::observe(PublicacionCatalogoAdvisorObserver::class);
        ServicioPublicacion::observe(PublicacionCatalogoAdvisorObserver::class);

        // Identificadores numéricos del panel: un valor no numérico responde 404 en lugar de llegar a la
        // consulta (PostgreSQL lo rechaza con SQLSTATE 22P02 y la petición terminaría en 500).
        foreach (['aprobacion', 'curso', 'estudiante', 'matricula', 'notificacion', 'role', 'servicio', 'user'] as $parametro) {
            Route::pattern($parametro, '[0-9]+');
        }

        // `throttle:N,M` numérico firma solo por dominio|IP: todas las rutas así declaradas comparten un único
        // contador. Los limitadores con nombre separan el cupo de cada superficie conservando los mismos límites.
        // Registro web y API comparten cupo a propósito (Sprint 13).
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by('register:'.$request->ip()));
        RateLimiter::for('password-recovery', fn (Request $request) => Limit::perMinute(5)->by('password-recovery:'.$request->ip()));
        // Perfil propio: la contraseña actual se comprueba en cada intento, así que se limita por usuario
        // para que una sesión robada no sirva para adivinarla.
        RateLimiter::for('perfil', fn (Request $request) => Limit::perMinute(5)->by('perfil:'.Entrada::texto($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Exportaciones del panel (CSV, Excel, PDF, auditoría, respaldo): costosas y, en varias, con efecto lateral
        // en la auditoría. Se limitan por usuario autenticado y comparten un único cupo.
        RateLimiter::for('admin-export', fn (Request $request) => Limit::perMinute(6)->by(
            'admin-export:'.Entrada::texto($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
        // Lecturas pesadas del panel (reportes, auditoría, respaldo, obligaciones de pago).
        RateLimiter::for('admin-heavy-read', fn (Request $request) => Limit::perMinute(60)->by(
            'admin-heavy-read:'.Entrada::texto($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
        // Selectores de matrícula: una consulta por pausa al teclear; se limita por usuario sin estorbar el uso normal.
        RateLimiter::for('admin-opciones', fn (Request $request) => Limit::perMinute(120)->by(
            'admin-opciones:'.Entrada::texto($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
        // Escrituras y subidas de archivos del panel: acota ráfagas automatizadas sin afectar la operación humana.
        RateLimiter::for('admin-write', fn (Request $request) => Limit::perMinute(120)->by(
            'admin-write:'.Entrada::texto($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
        // Consulta de DNI (JSON.pe, servicio externo con cuota): 10 por minuto y usuario, cubo propio.
        RateLimiter::for('consulta-dni', fn (Request $request) => Limit::perMinute(10)->by(
            'consulta-dni:'.Entrada::texto($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
        // Ver capturas de comprobantes: 60 por minuto y usuario, sin compartir cupo con otras rutas.
        RateLimiter::for('comprobante-ver', fn (Request $request) => Limit::perMinute(60)->by(
            'comprobante-ver:'.Entrada::texto($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
        // Operaciones que resuelven dinero (confirmar, rechazar, reversar, anular obligación): más estrictas que
        // `admin-write`, por usuario. 30 por minuto sobra para la operación humana y frena la automatización.
        RateLimiter::for('pagos-resolver', fn (Request $request) => Limit::perMinute(30)->by(
            'pagos-resolver:'.Entrada::texto($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
        // BFF de apps/web: un solo cubo por servicio (el visitante final no llega a Laravel con su IP en las lecturas).
        RateLimiter::for('publico-web', fn (Request $request) => Limit::perMinute(300)->by('publico-web'));
        // Chat del BFF: el visitante lo identifica el servidor de Next con un HMAC de su IP (opaco, sin IP en claro).
        RateLimiter::for('chat-bff', fn (Request $request) => Limit::perMinute(20)->by('chat-bff:'.ChatPublicoController::visitante($request)));
        RateLimiter::for('chat-voto-bff', fn (Request $request) => Limit::perMinute(20)->by('chat-voto-bff:'.ChatPublicoController::visitante($request)));
        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(30)->by(
            $request->user() !== null ? 'chat-user:'.Entrada::texto($request->user()->getAuthIdentifier()) : 'chat-ip:'.$request->ip(),
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
            Limit::perMinutes(10, Entrada::entero(config('reclamaciones.antispam.limite_ip_10min', 3)))->by('reclamaciones-ip10:'.$request->ip()),
            Limit::perDay(Entrada::entero(config('reclamaciones.antispam.limite_ip_dia', 10)))->by('reclamaciones-ipdia:'.$request->ip()),
            Limit::perDay(Entrada::entero(config('reclamaciones.antispam.limite_correo_dia', 3)))->by('reclamaciones-correo:'.hash('sha256', Email::normalize($request->input('email')))),
        ]);
        // Fase 2c.1 (BFF): todas las peticiones llegan desde el servidor de Next, así que se agrupa por el visitante que
        // este calcula (HMAC, nunca la IP). Aquí solo hay topes amplios contra ráfagas, incluidas las inválidas; los
        // límites estrictos de envío (3/10 min, 10/día por visitante y 3/día por correo) los aplica el controlador
        // después de validar, para que equivocarse al rellenar el formulario no bloquee al consumidor.
        RateLimiter::for('reclamaciones-bff-intentos', fn (Request $request) => Limit::perMinutes(10, 20)->by('reclamaciones-bff-intentos:'.ChatPublicoController::visitante($request)));
        RateLimiter::for('reclamaciones-bff-formulario', fn (Request $request) => Limit::perMinute(30)->by('reclamaciones-bff-formulario:'.ChatPublicoController::visitante($request)));
        // Fase 2c.3 (BFF): solicitud de cotización. Topes amplios por visitante; los estrictos los aplica el controlador tras validar.
        RateLimiter::for('cotizaciones-bff-intentos', fn (Request $request) => Limit::perMinutes(10, 20)->by('cotizaciones-bff-intentos:'.ChatPublicoController::visitante($request)));
        // «Contáctanos» del BFF: tope amplio por visitante; los estrictos los aplica el controlador tras validar.
        RateLimiter::for('contacto-bff-intentos', fn (Request $request) => Limit::perMinutes(10, 15)->by('contacto-bff-intentos:'.ChatPublicoController::visitante($request)));
        RateLimiter::for('cotizaciones-bff-formulario', fn (Request $request) => Limit::perMinute(30)->by('cotizaciones-bff-formulario:'.ChatPublicoController::visitante($request)));
    }
}
