<?php

use App\Http\Controllers\Admin\AprobacionController;
use App\Http\Controllers\Admin\AsistenteController;
use App\Http\Controllers\Admin\AuditoriaController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BusquedaController;
use App\Http\Controllers\Admin\ConfiguracionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\NotificacionController;
use App\Http\Controllers\Admin\PerfilController;
use App\Http\Controllers\Admin\PerfilSesionesController;
use App\Http\Controllers\Admin\ReclamacionController;
use App\Http\Controllers\Admin\RespaldoController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
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

        // Perfil propio: el usuario sale de la sesión; cambiar correo o clave exige la contraseña actual.
        Route::get('perfil', [PerfilController::class, 'edit'])->name('perfil.edit');
        Route::put('perfil', [PerfilController::class, 'update'])->middleware('throttle:perfil')->name('perfil.update');
        Route::get('perfil/sesiones', [PerfilSesionesController::class, 'index'])->name('perfil.sesiones.index');
        Route::delete('perfil/sesiones', [PerfilSesionesController::class, 'destroyOtras'])
            ->middleware('throttle:perfil')->name('perfil.sesiones.destroy-otras');
        Route::delete('perfil/sesiones/{identificador}', [PerfilSesionesController::class, 'destroy'])
            ->where('identificador', '[a-f0-9]{32}')->middleware('throttle:perfil')->name('perfil.sesiones.destroy');

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

        // Libro de Reclamaciones: consulta e impresión (E3) y gestión del caso (E4).
        Route::get('reclamaciones', [ReclamacionController::class, 'index'])->name('reclamaciones.index');
        Route::get('reclamaciones/{reclamacion:identificador}', [ReclamacionController::class, 'show'])
            ->name('reclamaciones.show')
            ->whereUuid('reclamacion');
        Route::get('reclamaciones/{reclamacion:identificador}/hoja', [ReclamacionController::class, 'hoja'])
            ->name('reclamaciones.hoja')
            ->whereUuid('reclamacion');
        // Gestión del caso (E4): una sola entrada; `operacion` elige la acción y su permiso (GestionarReclamacionRequest).
        Route::post('reclamaciones/{reclamacion:identificador}/gestion', [ReclamacionController::class, 'gestionar'])
            ->middleware('throttle:admin-write')
            ->name('reclamaciones.gestionar')
            ->whereUuid('reclamacion');

        // Catálogo de servicios tecnológicos (retirado de Blade el 14/11/2026): lo atiende apps/web con
        // /api/panel/v1/servicios/servicios. Se conserva el nombre para la barra lateral Blade; responde 302 a Next.
        Route::get('servicios', RedirigirAWebController::class)->defaults('destino', '/admin/servicios/catalogo')->name('servicios.index');

        // Matrículas (retiradas de Blade el 14/11/2026): las atiende apps/web con /api/panel/v1/academico/matriculas.
        // Se conservan `index` y `show` solo por sus nombres (búsqueda global, barra lateral, panel); responden 302 a Next.
        Route::get('matriculas', RedirigirAWebController::class)->defaults('destino', '/admin/academico/matriculas')->name('matriculas.index');
        Route::get('matriculas/{matricula}', RedirigirAWebController::class)->defaults('destino', '/admin/academico/matriculas/{matricula}')
            ->whereNumber('matricula')->name('matriculas.show');

        // El nombre del parámetro debe coincidir con el argumento del controlador para que el binding resuelva el registro.
        Route::resource('usuarios', UserController::class)->parameters(['usuarios' => 'user']);
        Route::resource('roles', RoleController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('aprobaciones', AprobacionController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->parameters(['aprobaciones' => 'aprobacion']);
        Route::post('aprobaciones/{aprobacion}/aprobar', [AprobacionController::class, 'aprobar'])->name('aprobaciones.aprobar');
        Route::post('aprobaciones/{aprobacion}/rechazar', [AprobacionController::class, 'rechazar'])->name('aprobaciones.rechazar');
        Route::post('aprobaciones/{aprobacion}/reabrir', [AprobacionController::class, 'reabrir'])->name('aprobaciones.reabrir');
        Route::get('notificaciones', [NotificacionController::class, 'index'])->name('notificaciones.index');
        Route::get('notificaciones/recientes', [NotificacionController::class, 'recientes'])->name('notificaciones.recientes');
        Route::patch('notificaciones/leidas', [NotificacionController::class, 'marcarTodas'])->name('notificaciones.leidas');
        Route::patch('notificaciones/{notificacion}/leida', [NotificacionController::class, 'marcarLeida'])->name('notificaciones.leida');
        // Reportes retirados de Blade (14/11/2026): los sirve apps/web con /api/panel/v1/academico/reportes. Se conserva el
        // nombre para los enlaces de las vistas Blade que quedan (barra lateral, panel); responde 302 a Next.
        Route::get('reportes', RedirigirAWebController::class)->defaults('destino', '/admin/academico/reportes')->name('reportes.index');
        Route::get('configuracion', [ConfiguracionController::class, 'index'])->name('configuracion.index');
        Route::put('configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');
        Route::get('auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');
        Route::post('auditoria/exportar', [AuditoriaController::class, 'exportar'])->middleware('throttle:admin-export')->name('auditoria.exportar');
        Route::get('respaldo', [RespaldoController::class, 'index'])->name('respaldo.index');
        Route::post('respaldo/exportar', [RespaldoController::class, 'exportar'])->middleware('throttle:admin-export')->name('respaldo.exportar');
        Route::get('asistente', [AsistenteController::class, 'index'])->name('asistente.index');
        Route::post('asistente/mensaje', [AsistenteController::class, 'mensaje'])->middleware('throttle:chat')->name('asistente.mensaje');
    });

Route::post('/chat/mensaje', [ChatWidgetController::class, 'mensajePublico'])
    ->middleware('throttle:chat')
    ->name('chat.mensaje');

Route::redirect('/home', '/');
