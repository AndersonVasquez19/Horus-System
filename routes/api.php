<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\VerificacionRegistroController;
use App\Http\Middleware\VerificacionRegistroHabilitada;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('api.auth.')->group(function (): void {
    Route::post('/email-verification/resend', [VerificacionRegistroController::class, 'reenviar'])
        ->middleware([VerificacionRegistroHabilitada::class, 'throttle:5,1'])->name('verification.resend');
    Route::post('/email-verification/confirm', [VerificacionRegistroController::class, 'confirmar'])
        ->middleware([VerificacionRegistroHabilitada::class, 'throttle:verification-confirm'])->name('verification.confirm');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::middleware('jwt.auth')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});
