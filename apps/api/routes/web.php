<?php

use App\Http\Controllers\Admin\AprobacionController;
use App\Http\Controllers\Admin\AsistenteController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BusquedaController;
use App\Http\Controllers\Admin\ConfiguracionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\NotificacionController;
use App\Http\Controllers\Admin\RespaldoController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\ChatWidgetController;
use App\Http\Controllers\CursosPublicosController;
use App\Http\Controllers\LibroReclamacionesController;
use App\Http\Controllers\PaginaTecnologicaController;
use App\Http\Controllers\RedirigirAWebController;
use App\Http\Controllers\ServiciosPublicosController;
use App\Http\Controllers\SolicitudCotizacionPublicaController;
use App\Http\Middleware\NoStoreAdminResponse;
use App\Http\Middleware\RecepcionCotizacionesHabilitada;
use App\Http\Middleware\RecepcionReclamacionesHabilitada;
use Illuminate\Support\Facades\Route;

// Páginas estáticas retiradas de Blade (Fase 1, 12/11/2026): las sirve apps/web; aquí solo redirigen.
Route::get('/', RedirigirAWebController::class)->defaults('destino', '/')->name('home');
Route::get('/quienes-somos', RedirigirAWebController::class)->defaults('destino', '/quienes-somos')->name('quienes-somos');
Route::get('/tecnologias/cableado-estructurado', [PaginaTecnologicaController::class, 'cableado'])->name('tecnologias.cableado');
Route::get('/tecnologias/camaras-seguridad', [PaginaTecnologicaController::class, 'camaras'])->name('tecnologias.camaras');
Route::get('/tecnologias/soporte-mantenimiento', ServiciosPublicosController::class)->name('tecnologias.soporte');
Route::get('/educacion/asesoramiento', RedirigirAWebController::class)->defaults('destino', '/educacion/asesoramiento')->name('educacion.asesoramiento');
Route::get('/educacion/capacitaciones', RedirigirAWebController::class)->defaults('destino', '/educacion/capacitaciones')->name('educacion.capacitaciones');
Route::get('/educacion/cursos', CursosPublicosController::class)->name('educacion.cursos');
Route::get('/galeria', RedirigirAWebController::class)->defaults('destino', '/galeria')->name('galeria');
Route::get('/contactos', RedirigirAWebController::class)->defaults('destino', '/contactos')->name('contactos');
Route::get('/politicas-devolucion', RedirigirAWebController::class)->defaults('destino', '/legales/politicas-devolucion')->name('legales.devolucion');
Route::get('/politicas-privacidad', RedirigirAWebController::class)->defaults('destino', '/legales/politicas-privacidad')->name('legales.privacidad');
Route::get('/politicas-cookies', RedirigirAWebController::class)->defaults('destino', '/legales/politicas-cookies')->name('legales.cookies');
Route::get('/preguntas-frecuentes', RedirigirAWebController::class)->defaults('destino', '/legales/preguntas-frecuentes')->name('legales.faq');
Route::get('/libro-reclamaciones', [LibroReclamacionesController::class, 'show'])->name('legales.reclamaciones');
// Alta pública: la compuerta va antes del límite para que, apagado, responda 404 sin consumir cupo ni leer el cuerpo.
Route::post('/libro-reclamaciones', [LibroReclamacionesController::class, 'store'])
    ->middleware([RecepcionReclamacionesHabilitada::class, 'throttle:reclamaciones'])
    ->name('legales.reclamaciones.store');

Route::middleware(RecepcionCotizacionesHabilitada::class)->group(function () {
    Route::get('/solicitar-cotizacion', [SolicitudCotizacionPublicaController::class, 'show'])->name('cotizacion-publica.show');
    Route::post('/solicitudes-cotizacion', [SolicitudCotizacionPublicaController::class, 'store'])
        ->middleware('throttle:cotizacion-publica')
        ->name('cotizacion-publica.store');
});

Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register')->name('register.post');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.post');
    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->middleware('throttle:password-recovery')->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->middleware('throttle:password-recovery')->name('password.store');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'role:admin|coordinador|secretaria', NoStoreAdminResponse::class])
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('buscar', [BusquedaController::class, 'index'])->name('busqueda.index');

        // Perfil propio retirado de Blade (14/11/2026): lo sirve apps/web con /api/panel/v1/compartido/perfil. Se conserva el
        // nombre `perfil.edit` para la barra lateral y la barra superior; responde 302 a `/admin/perfil` de Next. La edición
        // (PUT) y las sesiones activas (GET/DELETE) ya no existen aquí.
        Route::get('perfil', RedirigirAWebController::class)->defaults('destino', '/admin/perfil')->name('perfil.edit');

        // Estudiantes (retirados de Blade el 14/11/2026): los atiende apps/web con /api/panel/v1/academico/estudiantes.
        // Se conservan `index` y `show` solo por sus nombres (búsqueda global, barra lateral); responden 302 a Next.
        Route::get('estudiantes', RedirigirAWebController::class)->defaults('destino', '/admin/academico/estudiantes')->name('estudiantes.index');
        Route::get('estudiantes/{estudiante}', RedirigirAWebController::class)->defaults('destino', '/admin/academico/estudiantes/{estudiante}')
            ->whereNumber('estudiante')->name('estudiantes.show');

        // Cursos y su ficha pública (retirados de Blade el 14/11/2026): los atiende apps/web con /api/panel/v1/academico/cursos.
        // Se conservan `index` y `show` solo por sus nombres (búsqueda global, barra lateral); responden 302 a Next.
        Route::get('cursos', RedirigirAWebController::class)->defaults('destino', '/admin/academico/cursos')->name('cursos.index');
        Route::get('cursos/{curso}', RedirigirAWebController::class)->defaults('destino', '/admin/academico/cursos/{curso}')
            ->whereNumber('curso')->name('cursos.show');
        // Expedientes comerciales privados (retirados de Blade el 14/11/2026): la bandeja es la de apps/web con
        // /api/panel/v1/servicios/cotizaciones. Se conserva el nombre para la barra lateral Blade; responde 302 a Next.
        Route::get('cotizaciones', RedirigirAWebController::class)->defaults('destino', '/admin/servicios/cotizaciones')->name('cotizaciones.index');

        // Libro de Reclamaciones retirado de Blade (14/11/2026): lo sirve apps/web con /api/panel/v1/compartido/reclamaciones.
        // Se conservan los nombres de consulta para la barra lateral y los vínculos de las notificaciones; responden 302 a
        // `/admin/reclamaciones` de Next, que elige el panel de la persona. La gestión POST ya no existe aquí.
        Route::get('reclamaciones', RedirigirAWebController::class)->defaults('destino', '/admin/reclamaciones')->name('reclamaciones.index');
        Route::get('reclamaciones/{reclamacion}', RedirigirAWebController::class)->defaults('destino', '/admin/reclamaciones/{reclamacion}')
            ->name('reclamaciones.show')
            ->whereUuid('reclamacion');
        Route::get('reclamaciones/{reclamacion}/hoja', RedirigirAWebController::class)->defaults('destino', '/admin/reclamaciones/{reclamacion}/hoja')
            ->name('reclamaciones.hoja')
            ->whereUuid('reclamacion');

        // Catálogo de servicios tecnológicos (retirado de Blade el 14/11/2026): lo atiende apps/web con
        // /api/panel/v1/servicios/servicios. Se conserva el nombre para la barra lateral Blade; responde 302 a Next.
        Route::get('servicios', RedirigirAWebController::class)->defaults('destino', '/admin/servicios/catalogo')->name('servicios.index');

        // Matrículas (retiradas de Blade el 14/11/2026): las atiende apps/web con /api/panel/v1/academico/matriculas.
        // Se conservan `index` y `show` solo por sus nombres (búsqueda global, barra lateral, panel); responden 302 a Next.
        Route::get('matriculas', RedirigirAWebController::class)->defaults('destino', '/admin/academico/matriculas')->name('matriculas.index');
        Route::get('matriculas/{matricula}', RedirigirAWebController::class)->defaults('destino', '/admin/academico/matriculas/{matricula}')
            ->whereNumber('matricula')->name('matriculas.show');

        // Usuarios retirados de Blade (14/11/2026): los sirve apps/web con /api/panel/v1/compartido/usuarios. Se conserva el
        // nombre `usuarios.index` (barra lateral, búsqueda global, panel) y responde 302 a Next; crear, ver, editar y
        // eliminar ya no existen aquí.
        Route::get('usuarios', RedirigirAWebController::class)->defaults('destino', '/admin/usuarios')->name('usuarios.index');
        // Roles retirados de Blade (14/11/2026): los sirve apps/web con /api/panel/v1/compartido/roles. Se conserva el nombre
        // `roles.index` (barra lateral, panel) y responde 302 a Next; crear, editar y eliminar ya no existen aquí.
        Route::get('roles', RedirigirAWebController::class)->defaults('destino', '/admin/roles')->name('roles.index');
        Route::resource('aprobaciones', AprobacionController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->parameters(['aprobaciones' => 'aprobacion']);
        Route::post('aprobaciones/{aprobacion}/aprobar', [AprobacionController::class, 'aprobar'])->name('aprobaciones.aprobar');
        Route::post('aprobaciones/{aprobacion}/rechazar', [AprobacionController::class, 'rechazar'])->name('aprobaciones.rechazar');
        Route::post('aprobaciones/{aprobacion}/reabrir', [AprobacionController::class, 'reabrir'])->name('aprobaciones.reabrir');
        // Bandeja de notificaciones retirada de Blade (14/11/2026): la sirve apps/web con /api/panel/v1/compartido/notificaciones.
        // `notificaciones.index` responde 302 a Next. Se conservan `recientes` y `leidas` (JSON) porque la campana del shell
        // Blade, que sigue en pie hasta la Ola 4, las consume; «marcar una» ya no existe aquí.
        Route::get('notificaciones', RedirigirAWebController::class)->defaults('destino', '/admin/notificaciones')->name('notificaciones.index');
        Route::get('notificaciones/recientes', [NotificacionController::class, 'recientes'])->name('notificaciones.recientes');
        Route::patch('notificaciones/leidas', [NotificacionController::class, 'marcarTodas'])->name('notificaciones.leidas');
        // Reportes retirados de Blade (14/11/2026): los sirve apps/web con /api/panel/v1/academico/reportes. Se conserva el
        // nombre para los enlaces de las vistas Blade que quedan (barra lateral, panel); responde 302 a Next.
        Route::get('reportes', RedirigirAWebController::class)->defaults('destino', '/admin/academico/reportes')->name('reportes.index');
        Route::get('configuracion', [ConfiguracionController::class, 'index'])->name('configuracion.index');
        Route::put('configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');
        // Auditoría retirada de Blade (14/11/2026): la sirve apps/web con /api/panel/v1/compartido/auditoria. `/admin/auditoria`
        // de Next elige el panel al que la persona tiene acceso. La exportación POST ya no existe aquí.
        Route::get('auditoria', RedirigirAWebController::class)->defaults('destino', '/admin/auditoria')->name('auditoria.index');
        Route::get('respaldo', [RespaldoController::class, 'index'])->name('respaldo.index');
        Route::post('respaldo/exportar', [RespaldoController::class, 'exportar'])->middleware('throttle:admin-export')->name('respaldo.exportar');
        Route::get('asistente', [AsistenteController::class, 'index'])->name('asistente.index');
        Route::post('asistente/mensaje', [AsistenteController::class, 'mensaje'])->middleware('throttle:chat')->name('asistente.mensaje');
    });

Route::post('/chat/mensaje', [ChatWidgetController::class, 'mensajePublico'])
    ->middleware('throttle:chat')
    ->name('chat.mensaje');

Route::redirect('/home', '/');
