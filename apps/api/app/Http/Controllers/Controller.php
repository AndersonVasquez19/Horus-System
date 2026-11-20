<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Usuario autenticado de la petición; responde 401 si falta (las rutas ya pasan por el middleware de autenticación). */
    protected function usuarioAutenticado(Request $request): User
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 401);

        return $usuario;
    }
}
