<?php

namespace App\Providers;

use App\Support\Email;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('verification-confirm', fn (Request $request) => Limit::perMinute(10)->by('ip:'.$request->ip()));

        // Login web y API: límite por IP y también por correo, para frenar ataques distribuidos
        // contra una misma cuenta sin que un solo correo bloquee a todos los demás.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(10)->by('email:'.Email::normalize($request->input('email'))),
        ]);
    }
}
