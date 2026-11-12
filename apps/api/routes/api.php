<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Publico\CatalogoPublicoController;
use App\Http\Controllers\Api\Publico\ChatPublicoController;
use App\Http\Controllers\Auth\VerificacionRegistroController;
use App\Http\Middleware\AutenticarServicioWeb;
use App\Http\Middleware\VerificacionRegistroHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('api.auth.')->group(function (): void {
    Route::post('/email-verification/resend', [VerificacionRegistroController::class, 'reenviar'])
        ->middleware([VerificacionRegistroHabilitada::class, 'throttle:verification-resend'])->name('verification.resend');
    Route::post('/email-verification/confirm', [VerificacionRegistroController::class, 'confirmar'])
        ->middleware([VerificacionRegistroHabilitada::class, 'throttle:verification-confirm'])->name('verification.confirm');
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
});
