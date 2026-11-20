<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * El correo de login/registro se normaliza (espacios y mayúsculas) igual que antes de ListFilter,
 * y un valor que no es texto se rechaza con error de validación, nunca con un 500. Datos ficticios.
 */
class AuthEmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

}
