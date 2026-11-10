<?php

use App\Http\Controllers\Admin\AprobacionController;
use App\Http\Controllers\Admin\AsistenteController;
use App\Http\Controllers\Admin\AuditoriaController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BusquedaController;
use App\Http\Controllers\Admin\ConfiguracionController;
use App\Http\Controllers\Admin\CotizacionController;
use App\Http\Controllers\Admin\CursoController;
use App\Http\Controllers\Admin\CursoPublicacionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EstudianteController;
use App\Http\Controllers\Admin\MatriculaController;
use App\Http\Controllers\Admin\NotificacionController;
use App\Http\Controllers\Admin\PerfilController;
use App\Http\Controllers\Admin\PerfilSesionesController;
use App\Http\Controllers\Admin\ReclamacionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RespaldoController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ServicioTecnologicoController;
use App\Http\Controllers\Admin\SolicitudMatriculaController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\VerificacionRegistroController;
use App\Http\Controllers\ChatWidgetController;
use App\Http\Controllers\CursosPublicosController;
use App\Http\Controllers\LibroReclamacionesController;
use App\Http\Controllers\PaginaTecnologicaController;
use App\Http\Controllers\ServiciosPublicosController;
use App\Http\Controllers\SolicitudCotizacionPublicaController;
use App\Http\Controllers\SolicitudMatriculaPublicaController;
use App\Http\Middleware\RecepcionCotizacionesHabilitada;
use App\Http\Middleware\RecepcionReclamacionesHabilitada;
use App\Http\Middleware\VerificacionRegistroHabilitada;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.home')->name('home');
Route::view('/quienes-somos', 'pages.quienes-somos')->name('quienes-somos');
Route::get('/tecnologias/cableado-estructurado', [PaginaTecnologicaController::class, 'cableado'])->name('tecnologias.cableado');
Route::get('/tecnologias/camaras-seguridad', [PaginaTecnologicaController::class, 'camaras'])->name('tecnologias.camaras');
Route::get('/tecnologias/soporte-mantenimiento', ServiciosPublicosController::class)->name('tecnologias.soporte');
Route::view('/educacion/asesoramiento', 'pages.educacion.asesoramiento')->name('educacion.asesoramiento');
Route::view('/educacion/capacitaciones', 'pages.educacion.capacitaciones')->name('educacion.capacitaciones');
Route::get('/educacion/cursos', CursosPublicosController::class)->name('educacion.cursos');
Route::get('/solicitar-matricula', [SolicitudMatriculaPublicaController::class, 'create'])->name('matricula-publica.create');
Route::post('/solicitar-matricula', [SolicitudMatriculaPublicaController::class, 'store'])->middleware('throttle:matricula-publica')->name('matricula-publica.store');
Route::view('/galeria', 'pages.galeria')->name('galeria');
Route::view('/contactos', 'pages.contactos')->name('contactos');
Route::view('/politicas-devolucion', 'pages.legales.politicas-devolucion')->name('legales.devolucion');
Route::view('/politicas-privacidad', 'pages.legales.politicas-privacidad')->name('legales.privacidad');
Route::view('/politicas-cookies', 'pages.legales.politicas-cookies')->name('legales.cookies');
Route::view('/preguntas-frecuentes', 'pages.legales.preguntas-frecuentes')->name('legales.faq');
Route::get('/libro-reclamaciones', [LibroReclamacionesController::class, 'show'])->name('legales.reclamaciones');
// Alta pública: la compuerta va antes del límite para que, apagado, responda 404 sin consumir cupo ni leer el cuerpo.
Route::post('/libro-reclamaciones', [LibroReclamacionesController::class, 'store'])
    ->middleware([RecepcionReclamacionesHabilitada::class, 'throttle:reclamaciones'])
    ->name('legales.reclamaciones.store');

Route::middleware(RecepcionCotizacionesHabilitada::class)->group(function () {
    Route::get('/solicitar-cotizacion', [SolicitudCotizacionPublicaController::class, 'show'])->name('cotizacion-publica.show');
    Route::post('/solicitudes-cotizacion', [SolicitudCotizacionPublicaController::class, 'store'])->name('cotizacion-publica.store');
});

Route::middleware('guest')->group(function () {
    Route::post('/email-verification/resend', [VerificacionRegistroController::class, 'reenviar'])
        ->middleware([VerificacionRegistroHabilitada::class, 'throttle:verification-resend'])->name('verification.resend');
    Route::post('/email-verification/confirm', [VerificacionRegistroController::class, 'confirmar'])
        ->middleware([VerificacionRegistroHabilitada::class, 'throttle:verification-confirm'])->name('verification.confirm');
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
    ->middleware(['auth', 'role:admin|coordinador|secretaria'])
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

        Route::resource('estudiantes', EstudianteController::class);
        Route::patch('estudiantes/{estudiante}/restaurar', [EstudianteController::class, 'restaurar'])
            ->name('estudiantes.restaurar');

        Route::resource('cursos', CursoController::class);
        // Expedientes comerciales privados: no hay formularios ni recepción pública.
        Route::get('cotizaciones', [CotizacionController::class, 'index'])->name('cotizaciones.index');
        Route::post('cotizaciones', [CotizacionController::class, 'store'])->name('cotizaciones.store');
        Route::get('cotizaciones/{cotizacion:identificador}', [CotizacionController::class, 'show'])->name('cotizaciones.show')->whereUuid('cotizacion');
        Route::put('cotizaciones/{cotizacion:identificador}', [CotizacionController::class, 'update'])->name('cotizaciones.update')->whereUuid('cotizacion');
        Route::patch('cotizaciones/{cotizacion:identificador}/estado', [CotizacionController::class, 'estado'])->name('cotizaciones.estado')->whereUuid('cotizacion');
        Route::patch('cotizaciones/{cotizacion:identificador}/responsable', [CotizacionController::class, 'responsable'])->name('cotizaciones.responsable')->whereUuid('cotizacion');
        Route::post('cotizaciones/{cotizacion:identificador}/seguimientos', [CotizacionController::class, 'seguimiento'])->name('cotizaciones.seguimiento')->whereUuid('cotizacion');
        Route::get('cotizaciones/{cotizacion:identificador}/historial', [CotizacionController::class, 'historial'])->name('cotizaciones.historial')->whereUuid('cotizacion');

        // Libro de Reclamaciones: consulta administrativa e impresión, solo lectura (Sprint 20, E3).
        Route::get('reclamaciones', [ReclamacionController::class, 'index'])->name('reclamaciones.index');
        Route::get('reclamaciones/{reclamacion:identificador}', [ReclamacionController::class, 'show'])
            ->name('reclamaciones.show')
            ->whereUuid('reclamacion');
        Route::get('reclamaciones/{reclamacion:identificador}/hoja', [ReclamacionController::class, 'hoja'])
            ->name('reclamaciones.hoja')
            ->whereUuid('reclamacion');

        Route::resource('servicios', ServicioTecnologicoController::class)->only(['index', 'store', 'show'])->parameters(['servicios' => 'servicio']);
        Route::put('servicios/{servicio}', [ServicioTecnologicoController::class, 'update'])->name('servicios.update');
        Route::patch('servicios/{servicio}/estado', [ServicioTecnologicoController::class, 'estado'])->name('servicios.estado');
        Route::get('servicios/{servicio}/publicacion', [ServicioTecnologicoController::class, 'publicacion'])->name('servicios.publicacion.show');
        Route::put('servicios/{servicio}/publicacion', [ServicioTecnologicoController::class, 'preparar'])->name('servicios.publicacion.preparar');
        Route::post('servicios/{servicio}/publicacion/autorizar', [ServicioTecnologicoController::class, 'autorizar'])->name('servicios.publicacion.autorizar');
        Route::delete('servicios/{servicio}/publicacion', [ServicioTecnologicoController::class, 'retirar'])->name('servicios.publicacion.retirar');
        // Contenido de la ficha: imagen principal y tarjetas (multipart, mismo If-Match; permiso servicios.editar).
        Route::post('servicios/{servicio}/publicacion/imagen', [ServicioTecnologicoController::class, 'imagenPrincipal'])->name('servicios.publicacion.imagen');
        Route::post('servicios/{servicio}/elementos', [ServicioTecnologicoController::class, 'elementoStore'])->name('servicios.elementos.store');
        Route::post('servicios/{servicio}/elementos/{elemento}', [ServicioTecnologicoController::class, 'elementoUpdate'])->whereNumber('elemento')->name('servicios.elementos.update');
        Route::delete('servicios/{servicio}/elementos/{elemento}', [ServicioTecnologicoController::class, 'elementoDestroy'])->whereNumber('elemento')->name('servicios.elementos.destroy');
        Route::get('cursos/{curso}/publicacion', [CursoPublicacionController::class, 'edit'])->withTrashed()->name('cursos.publicacion.edit');
        Route::put('cursos/{curso}/publicacion', [CursoPublicacionController::class, 'update'])->name('cursos.publicacion.update');
        Route::delete('cursos/{curso}/publicacion', [CursoPublicacionController::class, 'destroy'])->withTrashed()->name('cursos.publicacion.destroy');
        Route::patch('cursos/{curso}/restaurar', [CursoController::class, 'restaurar'])
            ->name('cursos.restaurar');

        Route::get('matriculas/exportar/csv', [MatriculaController::class, 'exportarCsv'])
            ->middleware('throttle:admin-export')
            ->name('matriculas.csv');
        Route::resource('matriculas', MatriculaController::class);
        Route::get('solicitudes-matricula', [SolicitudMatriculaController::class, 'index'])->name('solicitudes-matricula.index');
        Route::patch('solicitudes-matricula/{solicitud:identificador}', [SolicitudMatriculaController::class, 'update'])
            ->whereUuid('solicitud')->name('solicitudes-matricula.update');
        Route::patch('matriculas/{matricula}/anular', [MatriculaController::class, 'anular'])
            ->name('matriculas.anular');
        Route::patch('matriculas/{matricula}/restaurar', [MatriculaController::class, 'restaurar'])
            ->name('matriculas.restaurar');

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
        Route::patch('notificaciones/{notificacion}/leida', [NotificacionController::class, 'marcarLeida'])->name('notificaciones.leida');
        Route::get('reportes', [ReportController::class, 'index'])->name('reportes.index');
        Route::post('reportes/exportar/excel', [ReportController::class, 'exportarExcel'])->middleware('throttle:admin-export')->name('reportes.exportar.excel');
        Route::post('reportes/exportar/pdf', [ReportController::class, 'exportarPdf'])->middleware('throttle:admin-export')->name('reportes.exportar.pdf');
        Route::get('configuracion', [ConfiguracionController::class, 'index'])->name('configuracion.index');
        Route::put('configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');
        Route::get('auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');
        Route::get('auditoria/exportar', [AuditoriaController::class, 'exportar'])->middleware('throttle:admin-export')->name('auditoria.exportar');
        Route::get('respaldo', [RespaldoController::class, 'index'])->name('respaldo.index');
        Route::get('respaldo/exportar', [RespaldoController::class, 'exportar'])->middleware('throttle:admin-export')->name('respaldo.exportar');
        Route::get('asistente', [AsistenteController::class, 'index'])->name('asistente.index');
        Route::post('asistente/mensaje', [AsistenteController::class, 'mensaje'])->middleware('throttle:chat')->name('asistente.mensaje');
    });

Route::post('/chat/mensaje', [ChatWidgetController::class, 'mensajePublico'])
    ->middleware('throttle:chat')
    ->name('chat.mensaje');

Route::redirect('/home', '/');
