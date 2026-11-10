<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MensajesValidacionEspanolTest extends TestCase
{
    public function test_el_idioma_por_defecto_es_espanol(): void
    {
        $this->assertSame('es', app()->getLocale());
    }

    public function test_los_mensajes_de_validacion_salen_en_espanol_con_atributos_legibles(): void
    {
        $errores = Validator::make(
            ['email' => 'no-es-correo', 'dni' => ''],
            ['email' => 'email', 'dni' => 'required', 'fecha_nacimiento' => 'required'],
        )->errors();

        $this->assertSame('El campo correo electrónico debe ser un correo electrónico válido.', $errores->first('email'));
        $this->assertSame('El campo DNI es obligatorio.', $errores->first('dni'));
        $this->assertSame('El campo fecha de nacimiento es obligatorio.', $errores->first('fecha_nacimiento'));
    }

    public function test_hay_una_traduccion_para_cada_clave_de_validacion_de_laravel(): void
    {
        $base = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $es = require lang_path('es/validation.php');

        $this->assertSame([], array_values(array_diff(array_keys($base), array_keys($es))));
    }
}
