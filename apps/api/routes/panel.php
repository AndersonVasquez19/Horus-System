<?php

use App\Http\Controllers\Api\Panel\Academico;
use App\Http\Controllers\Api\Panel\AutenticacionController;
use App\Http\Controllers\Api\Panel\Compartido;
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
    Route::post('registro', [AutenticacionController::class, 'registrar'])->middleware('throttle:register')->name('registro');
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

        // Exportación administrativa de tablas (ZIP de CSV minimizados; no es un respaldo restaurable).
        Route::get('respaldo', [Compartido\RespaldoController::class, 'index'])->name('respaldo.index');
        Route::post('respaldo/exportar', [Compartido\RespaldoController::class, 'exportar'])
            ->middleware('throttle:admin-export')->name('respaldo.exportar');

        // Probador y registros del asistente virtual (Horus AI con contingencia institucional).
        Route::get('asistente', [Compartido\AsistenteController::class, 'index'])->name('asistente.index');
        Route::post('asistente/mensaje', [Compartido\AsistenteController::class, 'mensaje'])
            ->middleware('throttle:chat')->name('asistente.mensaje');

        // Libro de Reclamaciones: datos personales; el permiso lo declara el controlador y la lógica es la del Blade.
        Route::get('reclamaciones', [Compartido\ReclamacionController::class, 'index'])->name('reclamaciones.index');
        // Mensajes de «Contáctanos»: misma bandeja y mismos permisos del libro (ver: reclamaciones.ver; atender: reclamaciones.gestionar).
        Route::get('reclamaciones-mensajes', [Compartido\MensajeContactoController::class, 'index'])->name('mensajes-contacto.index');
        Route::post('reclamaciones-mensajes/{mensaje:identificador}/atender', [Compartido\MensajeContactoController::class, 'atender'])
            ->middleware('throttle:admin-write')->whereUuid('mensaje')->name('mensajes-contacto.atender');
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

        // Aprobaciones internas: el permiso lo declara el controlador y la lógica es la de `GestionAprobaciones`, compartida
        // con el Blade que se retira.
        Route::get('aprobaciones', [Compartido\AprobacionController::class, 'index'])->name('aprobaciones.index');
        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::post('aprobaciones', [Compartido\AprobacionController::class, 'store'])->name('aprobaciones.store');
            Route::put('aprobaciones/{aprobacion}', [Compartido\AprobacionController::class, 'update'])->whereNumber('aprobacion')->name('aprobaciones.update');
            Route::delete('aprobaciones/{aprobacion}', [Compartido\AprobacionController::class, 'destroy'])->whereNumber('aprobacion')->name('aprobaciones.destroy');
            Route::post('aprobaciones/{aprobacion}/aprobar', [Compartido\AprobacionController::class, 'aprobar'])->whereNumber('aprobacion')->name('aprobaciones.aprobar');
            Route::post('aprobaciones/{aprobacion}/rechazar', [Compartido\AprobacionController::class, 'rechazar'])->whereNumber('aprobacion')->name('aprobaciones.rechazar');
            Route::post('aprobaciones/{aprobacion}/reabrir', [Compartido\AprobacionController::class, 'reabrir'])->whereNumber('aprobacion')->name('aprobaciones.reabrir');
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
        // Búsqueda global: cada grupo (estudiantes, matrículas, ediciones) solo con el permiso de su módulo.
        Route::get('busqueda', Academico\BusquedaController::class)->middleware('throttle:admin-opciones')->name('busqueda');
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

        // Convocatorias y horarios (Ola 3): el permiso de cada acción lo declara el controlador.
        Route::get('convocatorias', [Academico\ConvocatoriaController::class, 'index'])->name('convocatorias.index');
        Route::get('convocatorias/{identificador}', [Academico\ConvocatoriaController::class, 'show'])->name('convocatorias.show');
        // Calificaciones (Ola 3): evaluaciones, notas y acta de una convocatoria.
        Route::get('convocatorias/{identificador}/calificaciones', [Academico\CalificacionController::class, 'index'])->name('calificaciones.index');
        Route::post('convocatorias/{identificador}/acta/exportar', [Academico\CalificacionController::class, 'exportarActa'])
            ->middleware('throttle:admin-export')->name('calificaciones.acta.exportar');
        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::post('convocatorias/{identificador}/evaluaciones', [Academico\CalificacionController::class, 'crearEvaluacion'])->name('calificaciones.evaluaciones.store');
            Route::put('convocatorias/{identificador}/evaluaciones/{evaluacion}', [Academico\CalificacionController::class, 'actualizarEvaluacion'])->name('calificaciones.evaluaciones.update');
            Route::post('convocatorias/{identificador}/evaluaciones/{evaluacion}/eliminar', [Academico\CalificacionController::class, 'eliminarEvaluacion'])->name('calificaciones.evaluaciones.destroy');
            Route::put('convocatorias/{identificador}/calificaciones', [Academico\CalificacionController::class, 'nota'])->name('calificaciones.nota');
            Route::post('convocatorias/{identificador}/acta/cerrar', [Academico\CalificacionController::class, 'cerrarActa'])->name('calificaciones.acta.cerrar');
        });
        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::post('convocatorias', [Academico\ConvocatoriaController::class, 'store'])->name('convocatorias.store');
            Route::put('convocatorias/{identificador}', [Academico\ConvocatoriaController::class, 'update'])->name('convocatorias.update');
            Route::post('convocatorias/{identificador}/estado', [Academico\ConvocatoriaController::class, 'estado'])->name('convocatorias.estado');
            Route::put('convocatorias/{identificador}/horarios', [Academico\ConvocatoriaController::class, 'horarios'])->name('convocatorias.horarios');
        });


        // Sesiones de clase (Horus Academic, Fase 5). El permiso (`sesiones.*`) lo declara el controlador.
        Route::get('convocatorias/{identificador}/sesiones', [Academico\SesionController::class, 'index'])->name('sesiones.index');
        Route::get('sesiones/{sesion}', [Academico\SesionController::class, 'show'])->name('sesiones.show');
        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::post('convocatorias/{identificador}/sesiones', [Academico\SesionController::class, 'store'])->name('sesiones.store');
            Route::post('convocatorias/{identificador}/sesiones/generar', [Academico\SesionController::class, 'generar'])->name('sesiones.generar');
            Route::put('sesiones/{sesion}', [Academico\SesionController::class, 'update'])->name('sesiones.update');
            Route::post('sesiones/{sesion}/cancelar', [Academico\SesionController::class, 'cancelar'])->name('sesiones.cancelar');
            Route::post('sesiones/{sesion}/realizada', [Academico\SesionController::class, 'realizada'])->name('sesiones.realizada');
        });

        // Asistencia (Horus Academic, Fase 6). El permiso (`asistencias.*`) lo declara el controlador.
        Route::get('sesiones/{sesion}/asistencias', [Academico\AsistenciaController::class, 'lista'])->name('asistencias.lista');
        Route::get('convocatorias/{identificador}/asistencias/resumen', [Academico\AsistenciaController::class, 'resumen'])->name('asistencias.resumen');
        Route::post('convocatorias/{identificador}/asistencias/exportar', [Academico\AsistenciaController::class, 'exportar'])->middleware('throttle:admin-export')->name('asistencias.exportar');
        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::put('sesiones/{sesion}/asistencias', [Academico\AsistenciaController::class, 'registrar'])->name('asistencias.registrar');
            Route::put('sesiones/{sesion}/asistencias/{matricula}', [Academico\AsistenciaController::class, 'corregir'])->whereNumber('matricula')->name('asistencias.corregir');
        });

        // Finanzas (Horus Academic, Fase 7): libro de pagos inmutable. El permiso (`pagos.*`) lo declara el controlador.
        Route::get('pagos/metodos', [Academico\PagoController::class, 'metodos'])->name('pagos.metodos');
        Route::get('pagos/obligaciones', [Academico\PagoController::class, 'index'])->name('pagos.obligaciones.index');
        Route::get('pagos/transacciones/{transaccion}/comprobante', [Academico\PagoController::class, 'verComprobante'])->middleware('throttle:60,1')->name('pagos.comprobante.ver');
        Route::post('pagos/exportar', [Academico\PagoController::class, 'exportar'])->middleware('throttle:admin-export')->name('pagos.exportar');
        Route::get('pagos/obligaciones/{obligacion}',[Academico\PagoController::class, 'show'])->name('pagos.obligaciones.show');
        Route::get('matriculas/{matricula}/obligacion', [Academico\PagoController::class, 'deMatricula'])->whereNumber('matricula')->name('pagos.obligaciones.matricula');
        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::post('matriculas/{matricula}/obligacion', [Academico\PagoController::class, 'crearObligacion'])->whereNumber('matricula')->name('pagos.obligaciones.store');
            Route::post('pagos/obligaciones/{obligacion}/pagos', [Academico\PagoController::class, 'registrar'])->name('pagos.registrar');
            Route::post('pagos/obligaciones/{obligacion}/anular', [Academico\PagoController::class, 'anular'])->name('pagos.obligaciones.anular');
            Route::post('pagos/transacciones/{transaccion}/comprobante', [Academico\PagoController::class, 'adjuntarComprobante'])->name('pagos.comprobante.adjuntar');
            Route::post('pagos/transacciones/{transaccion}/confirmar', [Academico\PagoController::class, 'confirmar'])->name('pagos.confirmar');
            Route::post('pagos/transacciones/{transaccion}/rechazar', [Academico\PagoController::class, 'rechazar'])->name('pagos.rechazar');
            Route::post('pagos/transacciones/{transaccion}/reversar', [Academico\PagoController::class, 'reversar'])->name('pagos.reversar');
        });

        // Consulta de DNI (JSON.pe) para el alta de personas: solo consulta, limitada por usuario; el permiso lo declara el controlador.
        Route::post('consultas/dni', Academico\ConsultaDniController::class)->middleware('throttle:10,1')->name('consultas.dni');

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
        // Caja del administrador: matrícula + obligación + pago Yape + captura + confirmación en un solo paso atómico.
        Route::post('matriculas/con-pago', [Academico\MatriculaConPagoController::class, 'store'])->middleware('throttle:admin-write')->name('matriculas.con-pago');
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

