<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Publico\CatalogoPublicoController;
use App\Http\Controllers\Api\Publico\ChatPublicoController;
use App\Http\Controllers\Api\Publico\CotizacionesPublicoController;
use App\Http\Controllers\Api\Publico\ReclamacionesPublicoController;
use App\Http\Controllers\Api\Publico\SolicitudesMatriculaPublicoController;
use App\Http\Middleware\AutenticarServicioWeb;
use App\Http\Middleware\RecepcionReclamacionesHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('api.auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register')->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::middleware('jwt.auth')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});

// Lectura pública para el BFF de apps/web (Fase 2a): servidor a servidor con X-Horus-Web-Token; nunca desde el navegador.
Route::prefix('public/v1')->name('api.publico.')->middleware([AutenticarServicioWeb::class, 'throttle:publico-web'])->group(function (): void {
    Route::get('/cursos', [CatalogoPublicoController::class, 'cursos'])->name('cursos');
    Route::get('/servicios', [CatalogoPublicoController::class, 'servicios'])->name('servicios');
    Route::get('/paginas/{pagina}', [CatalogoPublicoController::class, 'pagina'])->where('pagina', '[a-z-]{1,40}')->name('paginas');
    // Fase 2b: chat de Horus AI; límite propio por visitante además del global del BFF.
    Route::post('/chat', [ChatPublicoController::class, 'mensaje'])->middleware('throttle:chat-bff')->name('chat');
    // Fase 2c.1: Libro de Reclamaciones (solo envío; no hay consulta pública). Apagado, el POST responde 404 antes de leer el cuerpo.
    Route::get('/reclamaciones/formulario', [ReclamacionesPublicoController::class, 'formulario'])
        ->middleware('throttle:reclamaciones-bff-formulario')->name('reclamaciones.formulario');
    Route::post('/reclamaciones', [ReclamacionesPublicoController::class, 'store'])
        ->middleware([RecepcionReclamacionesHabilitada::class, 'throttle:reclamaciones-bff-intentos'])->name('reclamaciones.store');
    // Fase 2c.2: solicitud de matrícula (un contacto para el personal; nunca crea matrícula). Sin consulta pública.
    Route::get('/matriculas/formulario', [SolicitudesMatriculaPublicoController::class, 'formulario'])
        ->middleware('throttle:matriculas-bff-formulario')->name('matriculas.formulario');
    Route::post('/matriculas', [SolicitudesMatriculaPublicoController::class, 'store'])
        ->middleware('throttle:matriculas-bff-intentos')->name('matriculas.store');
    // Fase 2c.3: solicitud de cotización (entra al módulo comercial existente; no es una cotización emitida). Sin consulta pública.
    Route::get('/cotizaciones/formulario', [CotizacionesPublicoController::class, 'formulario'])
        ->middleware('throttle:cotizaciones-bff-formulario')->name('cotizaciones.formulario');
    Route::post('/cotizaciones', [CotizacionesPublicoController::class, 'store'])
        ->middleware('throttle:cotizaciones-bff-intentos')->name('cotizaciones.store');
});
