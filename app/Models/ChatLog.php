<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatLog extends Model
{
    protected $fillable = [
        'session_id',
        'mensaje_usuario',
        'respuesta_bot',
        'intent_detectado',
    ];
}
