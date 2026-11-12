<?php

use App\Http\Controllers\Api\Panel\Academico;
use App\Http\Controllers\Api\Panel\AutenticacionController;
use App\Http\Controllers\Api\Panel\Servicios;
use App\Http\Middleware\SesionPanelVigente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

/*
 * API de los paneles de apps/web, montada en bootstrap/app.php bajo /api/panel/v1 con:
 *   AutenticarBffPanel (solo el BFF, respuestas JSON, IP real del visitante) + grupo `web` (sesión y CSRF).
 *
 * Reglas para toda ruta nueva de este archivo:
 *   - Fuera de `auth/*`, exige `auth` + SesionPanelVigente + el permiso de entrada del panel + el permiso de módulo
 *     de la acción. Lo comprueba tests/Feature/Panel/InventarioRutasPanelTest.php.
 *   - Ninguna devuelve vistas ni redirecciones.
 */

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::get('csrf', [AutenticacionController::class, 'csrf'])->name('csrf');
    Route::post('login', [AutenticacionController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('contrasena/solicitar', [AutenticacionController::class, 'solicitarRestablecimiento'])
        ->middleware('throttle:password-recovery')->name('contrasena.solicitar');
    Route::post('contrasena/restablecer', [AutenticacionController::class, 'restablecer'])
        ->middleware('throttle:password-recovery')->name('contrasena.restablecer');

    Route::middleware(['auth', SesionPanelVigente::class])->group(function (): void {
        Route::get('sesion', [AutenticacionController::class, 'sesion'])->name('sesion');
        Route::post('logout', [AutenticacionController::class, 'logout'])->name('logout');
    });
});

// Panel académico (/admin/academico). El permiso de módulo de cada acción lo declara su controlador.
Route::middleware(['auth', SesionPanelVigente::class, 'permission:'.RolesAndPermissionsSeeder::PANEL_ACADEMICO])
    ->prefix('academico')
    ->name('academico.')
    ->group(function (): void {
        Route::get('resumen', Academico\ResumenController::class)->name('resumen');
        Route::get('cursos', [Academico\CursoController::class, 'index'])->name('cursos.index');
        Route::get('cursos/{curso}', [Academico\CursoController::class, 'show'])->whereNumber('curso')->name('cursos.show');
        Route::get('cursos/{curso}/publicacion', [Academico\CursoPublicacionController::class, 'show'])->whereNumber('curso')->name('cursos.publicacion.show');
        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::post('cursos', [Academico\CursoController::class, 'store'])->name('cursos.store');
            Route::put('cursos/{curso}', [Academico\CursoController::class, 'update'])->whereNumber('curso')->name('cursos.update');
            Route::delete('cursos/{curso}', [Academico\CursoController::class, 'destroy'])->whereNumber('curso')->name('cursos.destroy');
            Route::post('cursos/{curso}/restaurar', [Academico\CursoController::class, 'restaurar'])->whereNumber('curso')->name('cursos.restaurar');
            Route::put('cursos/{curso}/publicacion', [Academico\CursoPublicacionController::class, 'update'])->whereNumber('curso')->name('cursos.publicacion.update');
            Route::post('cursos/{curso}/publicacion/retirar', [Academico\CursoPublicacionController::class, 'retirar'])->whereNumber('curso')->name('cursos.publicacion.retirar');
        });

        // Estudiantes: mismas reglas que /admin/estudiantes (GestionEstudiantes). Las escrituras, con throttle de panel.
        Route::get('estudiantes', [Academico\EstudianteController::class, 'index'])->name('estudiantes.index');
        Route::get('estudiantes/{estudiante}', [Academico\EstudianteController::class, 'show'])->whereNumber('estudiante')->name('estudiantes.show');
        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::post('estudiantes', [Academico\EstudianteController::class, 'store'])->name('estudiantes.store');
            Route::put('estudiantes/{estudiante}', [Academico\EstudianteController::class, 'update'])->whereNumber('estudiante')->name('estudiantes.update');
            Route::delete('estudiantes/{estudiante}', [Academico\EstudianteController::class, 'destroy'])->whereNumber('estudiante')->name('estudiantes.destroy');
            Route::post('estudiantes/{estudiante}/restaurar', [Academico\EstudianteController::class, 'restaurar'])->whereNumber('estudiante')->name('estudiantes.restaurar');
        });

        // Reportes de matrículas: misma consulta, lotes, exportaciones y auditoría que /admin/reportes.
        Route::get('reportes', [Academico\ReporteController::class, 'index'])->name('reportes.index');
        Route::post('reportes/exportar/excel', [Academico\ReporteController::class, 'exportarExcel'])
            ->middleware('throttle:admin-export')->name('reportes.exportar.excel');
        Route::post('reportes/exportar/pdf', [Academico\ReporteController::class, 'exportarPdf'])
            ->middleware('throttle:admin-export')->name('reportes.exportar.pdf');

        // Matrículas: mismas reglas que /admin/matriculas (GestionMatriculas, protocolo de bloqueo incluido).
        Route::get('matriculas', [Academico\MatriculaController::class, 'index'])->name('matriculas.index');
        Route::get('matriculas/opciones/estudiantes', [Academico\MatriculaController::class, 'opcionesEstudiantes'])
            ->middleware('throttle:admin-opciones')->name('matriculas.opciones.estudiantes');
        Route::get('matriculas/opciones/cursos', [Academico\MatriculaController::class, 'opcionesCursos'])
            ->middleware('throttle:admin-opciones')->name('matriculas.opciones.cursos');
        Route::post('matriculas/exportar', [Academico\MatriculaController::class, 'exportar'])
            ->middleware('throttle:admin-export')->name('matriculas.exportar');
        Route::get('matriculas/{matricula}', [Academico\MatriculaController::class, 'show'])->whereNumber('matricula')->name('matriculas.show');
        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::post('matriculas', [Academico\MatriculaController::class, 'store'])->name('matriculas.store');
            Route::put('matriculas/{matricula}', [Academico\MatriculaController::class, 'update'])->whereNumber('matricula')->name('matriculas.update');
            Route::post('matriculas/{matricula}/anular', [Academico\MatriculaController::class, 'anular'])->whereNumber('matricula')->name('matriculas.anular');
            Route::delete('matriculas/{matricula}', [Academico\MatriculaController::class, 'destroy'])->whereNumber('matricula')->name('matriculas.destroy');
            Route::post('matriculas/{matricula}/restaurar', [Academico\MatriculaController::class, 'restaurar'])->whereNumber('matricula')->name('matriculas.restaurar');
        });
    });

// Panel de servicios tecnológicos (/admin/servicios).
Route::middleware(['auth', SesionPanelVigente::class, 'permission:'.RolesAndPermissionsSeeder::PANEL_SERVICIOS])
    ->prefix('servicios')
    ->name('servicios.')
    ->group(function (): void {
        Route::get('resumen', Servicios\ResumenController::class)->name('resumen');
        Route::get('servicios', [Servicios\ServicioController::class, 'index'])->name('servicios.index');
        Route::get('servicios/{servicio}', [Servicios\ServicioController::class, 'show'])->whereNumber('servicio')->name('servicios.show');
        Route::get('cotizaciones', [Servicios\CotizacionController::class, 'index'])->name('cotizaciones.index');
        Route::get('cotizaciones/opciones/responsables', [Servicios\CotizacionController::class, 'opcionesResponsables'])
            ->middleware('throttle:admin-opciones')->name('cotizaciones.opciones.responsables');
        Route::get('cotizaciones/opciones/servicios', [Servicios\CotizacionController::class, 'opcionesServicios'])
            ->middleware('throttle:admin-opciones')->name('cotizaciones.opciones.servicios');
        Route::get('cotizaciones/{identificador}', [Servicios\CotizacionController::class, 'show'])->name('cotizaciones.show');

        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::post('servicios', [Servicios\ServicioController::class, 'store'])->name('servicios.store');
            Route::put('servicios/{servicio}', [Servicios\ServicioController::class, 'update'])->whereNumber('servicio')->name('servicios.update');
            Route::post('servicios/{servicio}/estado', [Servicios\ServicioController::class, 'estado'])->whereNumber('servicio')->name('servicios.estado');
            Route::put('servicios/{servicio}/publicacion', [Servicios\ServicioController::class, 'preparar'])->whereNumber('servicio')->name('servicios.publicacion.preparar');
            Route::post('servicios/{servicio}/publicacion/autorizar', [Servicios\ServicioController::class, 'autorizar'])->whereNumber('servicio')->name('servicios.publicacion.autorizar');
            Route::post('servicios/{servicio}/publicacion/retirar', [Servicios\ServicioController::class, 'retirar'])->whereNumber('servicio')->name('servicios.publicacion.retirar');

            Route::post('cotizaciones', [Servicios\CotizacionController::class, 'store'])->name('cotizaciones.store');
            Route::put('cotizaciones/{identificador}', [Servicios\CotizacionController::class, 'update'])->name('cotizaciones.update');
            Route::post('cotizaciones/{identificador}/estado', [Servicios\CotizacionController::class, 'estado'])->name('cotizaciones.estado');
            Route::post('cotizaciones/{identificador}/responsable', [Servicios\CotizacionController::class, 'responsable'])->name('cotizaciones.responsable');
            Route::post('cotizaciones/{identificador}/seguimientos', [Servicios\CotizacionController::class, 'seguimiento'])->name('cotizaciones.seguimiento');
        });
    });
