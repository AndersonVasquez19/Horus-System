<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\AprobacionController;
use App\Http\Controllers\Admin\AsistenteController;
use App\Http\Controllers\Admin\AuditoriaController;
use App\Http\Controllers\Admin\BusquedaController;
use App\Http\Controllers\Admin\ConfiguracionController;
use App\Http\Controllers\Admin\CursoController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EstudianteController;
use App\Http\Controllers\Admin\MatriculaController;
use App\Http\Controllers\Admin\NotificacionController;
use App\Http\Controllers\Admin\ProcesoController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RespaldoController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\ChatWidgetController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.home')->name('home');
Route::view('/quienes-somos', 'pages.quienes-somos')->name('quienes-somos');
Route::view('/tecnologias/cableado-estructurado', 'pages.tecnologias.cableado-estructurado')->name('tecnologias.cableado');
Route::view('/tecnologias/camaras-seguridad', 'pages.tecnologias.camaras-seguridad')->name('tecnologias.camaras');
Route::view('/tecnologias/soporte-mantenimiento', 'pages.tecnologias.soporte-mantenimiento')->name('tecnologias.soporte');
Route::view('/educacion/asesoramiento', 'pages.educacion.asesoramiento')->name('educacion.asesoramiento');
Route::view('/educacion/capacitaciones', 'pages.educacion.capacitaciones')->name('educacion.capacitaciones');
Route::view('/educacion/cursos', 'pages.educacion.cursos')->name('educacion.cursos');
Route::view('/market', 'pages.market')->name('market');
Route::view('/galeria', 'pages.galeria')->name('galeria');
Route::view('/contactos', 'pages.contactos')->name('contactos');
Route::view('/politicas-devolucion', 'pages.legales.politicas-devolucion')->name('legales.devolucion');
Route::view('/politicas-privacidad', 'pages.legales.politicas-privacidad')->name('legales.privacidad');
Route::view('/politicas-cookies', 'pages.legales.politicas-cookies')->name('legales.cookies');
Route::view('/preguntas-frecuentes', 'pages.legales.preguntas-frecuentes')->name('legales.faq');
Route::view('/libro-reclamaciones', 'pages.legales.libro-reclamaciones')->name('legales.reclamaciones');

Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.post');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.post');
    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('password.store');
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

        Route::resource('estudiantes', EstudianteController::class);
        Route::patch('estudiantes/{estudiante}/restaurar', [EstudianteController::class, 'restaurar'])
            ->name('estudiantes.restaurar');

        Route::resource('cursos', CursoController::class);
        Route::patch('cursos/{curso}/restaurar', [CursoController::class, 'restaurar'])
            ->name('cursos.restaurar');

        Route::get('matriculas/exportar/csv', [MatriculaController::class, 'exportarCsv'])
            ->name('matriculas.csv');
        Route::resource('matriculas', MatriculaController::class);
        Route::patch('matriculas/{matricula}/anular', [MatriculaController::class, 'anular'])
            ->name('matriculas.anular');
        Route::patch('matriculas/{matricula}/restaurar', [MatriculaController::class, 'restaurar'])
            ->name('matriculas.restaurar');

        Route::resource('usuarios', UserController::class);
        Route::resource('roles', RoleController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('procesos', ProcesoController::class);
        Route::resource('aprobaciones', AprobacionController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('notificaciones', [NotificacionController::class, 'index'])->name('notificaciones.index');
        Route::patch('notificaciones/{notificacion}/leida', [NotificacionController::class, 'marcarLeida'])->name('notificaciones.leida');
        Route::get('reportes', [ReportController::class, 'index'])->name('reportes.index');
        Route::post('reportes/exportar/excel', [ReportController::class, 'exportarExcel'])->name('reportes.exportar.excel');
        Route::post('reportes/exportar/pdf', [ReportController::class, 'exportarPdf'])->name('reportes.exportar.pdf');
        Route::get('configuracion', [ConfiguracionController::class, 'index'])->name('configuracion.index');
        Route::put('configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');
        Route::get('auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');
        Route::get('auditoria/exportar', [AuditoriaController::class, 'exportar'])->name('auditoria.exportar');
        Route::get('respaldo', [RespaldoController::class, 'index'])->name('respaldo.index');
        Route::get('respaldo/exportar', [RespaldoController::class, 'exportar'])->name('respaldo.exportar');
        Route::get('asistente', [AsistenteController::class, 'index'])->name('asistente.index');
        Route::post('asistente/mensaje', [AsistenteController::class, 'mensaje'])->name('asistente.mensaje');
    });

Route::post('/chat/mensaje', [ChatWidgetController::class, 'mensajePublico'])
    ->middleware('throttle:30,1')
    ->name('chat.mensaje');

Route::redirect('/home', '/');
