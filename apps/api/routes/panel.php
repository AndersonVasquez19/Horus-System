<?php

use App\Http\Controllers\Api\Panel\Academico;
use App\Http\Controllers\Api\Panel\AutenticacionController;
use App\Http\Controllers\Api\Panel\Compartido;
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

// Módulos compartidos por los dos paneles (/admin): basta el permiso de entrada a cualquiera de ellos. Cada módulo
// declara su propio permiso (o, si es de la cuenta propia, ninguno: el usuario sale siempre de la sesión).
Route::middleware(['auth', SesionPanelVigente::class, 'permission:'.implode('|', RolesAndPermissionsSeeder::PANEL_PERMISSIONS)])
    ->prefix('compartido')
    ->name('compartido.')
    ->group(function (): void {
        // Notificaciones y perfil: cuenta propia.
        Route::get('notificaciones', [Compartido\NotificacionController::class, 'index'])->name('notificaciones.index');
        Route::get('notificaciones/recientes', [Compartido\NotificacionController::class, 'recientes'])->name('notificaciones.recientes');
        Route::get('perfil', [Compartido\PerfilController::class, 'show'])->name('perfil.show');
        Route::get('perfil/sesiones', [Compartido\PerfilController::class, 'sesiones'])->name('perfil.sesiones');

        // Auditoría: solo lectura y exportación; el permiso lo declara el controlador.
        Route::get('auditoria', [Compartido\AuditoriaController::class, 'index'])->name('auditoria.index');
        Route::post('auditoria/exportar', [Compartido\AuditoriaController::class, 'exportar'])
            ->middleware('throttle:admin-export')->name('auditoria.exportar');

        // Libro de Reclamaciones: datos personales; el permiso lo declara el controlador y la lógica es la del Blade.
        Route::get('reclamaciones', [Compartido\ReclamacionController::class, 'index'])->name('reclamaciones.index');
        Route::get('reclamaciones/{reclamacion:identificador}', [Compartido\ReclamacionController::class, 'show'])
            ->whereUuid('reclamacion')->name('reclamaciones.show');
        Route::get('reclamaciones/{reclamacion:identificador}/hoja', [Compartido\ReclamacionController::class, 'hoja'])
            ->whereUuid('reclamacion')->name('reclamaciones.hoja');
        Route::post('reclamaciones/{reclamacion:identificador}/gestion', [Compartido\ReclamacionController::class, 'gestionar'])
            ->middleware('throttle:admin-write')->whereUuid('reclamacion')->name('reclamaciones.gestionar');

        // Usuarios y roles: el permiso lo declara cada controlador; la lógica (R1 a R4, último administrador) es la de
        // `GestionUsuarios` y `GestionRoles`, compartida con el Blade.
        Route::get('usuarios', [Compartido\UsuarioController::class, 'index'])->name('usuarios.index');
        Route::get('usuarios/roles-asignables', [Compartido\UsuarioController::class, 'rolesAsignables'])->name('usuarios.roles-asignables');
        Route::get('usuarios/{user}', [Compartido\UsuarioController::class, 'show'])->whereNumber('user')->name('usuarios.show');
        Route::get('roles', [Compartido\RolController::class, 'index'])->name('roles.index');
        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::post('usuarios', [Compartido\UsuarioController::class, 'store'])->name('usuarios.store');
            Route::put('usuarios/{user}', [Compartido\UsuarioController::class, 'update'])->whereNumber('user')->name('usuarios.update');
            Route::delete('usuarios/{user}', [Compartido\UsuarioController::class, 'destroy'])->whereNumber('user')->name('usuarios.destroy');
            Route::post('roles', [Compartido\RolController::class, 'store'])->name('roles.store');
            Route::put('roles/{role}', [Compartido\RolController::class, 'update'])->whereNumber('role')->name('roles.update');
            Route::delete('roles/{role}', [Compartido\RolController::class, 'destroy'])->whereNumber('role')->name('roles.destroy');
        });

        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::post('notificaciones/leidas', [Compartido\NotificacionController::class, 'marcarTodas'])->name('notificaciones.leidas');
            Route::post('notificaciones/{notificacion}/leida', [Compartido\NotificacionController::class, 'marcarLeida'])
                ->whereNumber('notificacion')->name('notificaciones.leida');
        });
        Route::middleware('throttle:perfil')->group(function (): void {
            Route::put('perfil', [Compartido\PerfilController::class, 'update'])->name('perfil.update');
            Route::post('perfil/sesiones/cerrar-otras', [Compartido\PerfilController::class, 'cerrarOtras'])->name('perfil.sesiones.cerrar-otras');
            Route::post('perfil/sesiones/{identificador}/cerrar', [Compartido\PerfilController::class, 'cerrarUna'])
                ->where('identificador', '[a-f0-9]{32}')->name('perfil.sesiones.cerrar');
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
        Route::get('servicios/{servicio}/imagen', [Servicios\ServicioContenidoController::class, 'verImagenPrincipal'])
            ->whereNumber('servicio')->middleware('throttle:admin-opciones')->name('servicios.imagen.ver');
        Route::get('servicios/{servicio}/elementos/{elemento}/imagen', [Servicios\ServicioContenidoController::class, 'verImagenElemento'])
            ->whereNumber(['servicio', 'elemento'])->middleware('throttle:admin-opciones')->name('servicios.elementos.imagen.ver');
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

            // Imagen principal y tarjetas de la ficha (multipart; revisión en `_revision`).
            Route::post('servicios/{servicio}/imagen', [Servicios\ServicioContenidoController::class, 'imagenPrincipal'])->whereNumber('servicio')->name('servicios.imagen.subir');
            Route::post('servicios/{servicio}/imagen/quitar', [Servicios\ServicioContenidoController::class, 'quitarImagenPrincipal'])->whereNumber('servicio')->name('servicios.imagen.quitar');
            Route::post('servicios/{servicio}/elementos', [Servicios\ServicioContenidoController::class, 'elementoStore'])->whereNumber('servicio')->name('servicios.elementos.store');
            Route::post('servicios/{servicio}/elementos/{elemento}', [Servicios\ServicioContenidoController::class, 'elementoUpdate'])->whereNumber(['servicio', 'elemento'])->name('servicios.elementos.update');
            Route::post('servicios/{servicio}/elementos/{elemento}/eliminar', [Servicios\ServicioContenidoController::class, 'elementoDestroy'])->whereNumber(['servicio', 'elemento'])->name('servicios.elementos.destroy');

            Route::post('cotizaciones', [Servicios\CotizacionController::class, 'store'])->name('cotizaciones.store');
            Route::put('cotizaciones/{identificador}', [Servicios\CotizacionController::class, 'update'])->name('cotizaciones.update');
            Route::post('cotizaciones/{identificador}/estado', [Servicios\CotizacionController::class, 'estado'])->name('cotizaciones.estado');
            Route::post('cotizaciones/{identificador}/responsable', [Servicios\CotizacionController::class, 'responsable'])->name('cotizaciones.responsable');
            Route::post('cotizaciones/{identificador}/seguimientos', [Servicios\CotizacionController::class, 'seguimiento'])->name('cotizaciones.seguimiento');
        });
    });
