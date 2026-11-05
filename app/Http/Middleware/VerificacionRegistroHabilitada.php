<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\VerificacionRegistroService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificacionRegistroHabilitada
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(VerificacionRegistroService::enabled(), 404);

        return $next($request);
    }
}
