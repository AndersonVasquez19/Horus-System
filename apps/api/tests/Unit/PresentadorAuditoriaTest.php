<?php

namespace Tests\Unit;

use App\Support\Panel\PresentadorAuditoria;
use PHPUnit\Framework\TestCase;

class PresentadorAuditoriaTest extends TestCase
{
    public function test_etiquetas_de_accion_y_modelo_sin_nombres_tecnicos(): void
    {
        $this->assertSame('Intento de acceso fallido', PresentadorAuditoria::etiquetaAccion('login_failed'));
        $this->assertSame('Matrícula', PresentadorAuditoria::etiquetaModelo('App\Models\Matricula'));
        $this->assertSame('Cosa nueva', PresentadorAuditoria::etiquetaAccion('cosa_nueva'));
        $this->assertSame('Registro nuevo', PresentadorAuditoria::etiquetaModelo('App\Models\RegistroNuevo'));
        $this->assertNull(PresentadorAuditoria::etiquetaModelo(null));
    }

    public function test_el_resumen_no_muestra_claves_internas_ni_json(): void
    {
        $this->assertSame([], PresentadorAuditoria::resumen(['canal' => 'panel', 'personal_panel' => true]));
        $this->assertSame([], PresentadorAuditoria::resumen(['esquema' => 1, 'filtros' => [], 'pagina' => 1]));

        $resumen = PresentadorAuditoria::resumen([
            'esquema' => 1,
            'antes' => ['estado' => 'pendiente', 'cupos_maximos' => 10],
            'despues' => ['estado' => 'pagada', 'cupos_maximos' => 8],
            'huella_correo' => 'abc123',
        ]);

        $this->assertSame(['Estado: Pendiente → Pagada', 'Cupos máximos: 10 → 8'], $resumen);
        foreach ($resumen as $frase) {
            $this->assertStringNotContainsString('{', $frase);
            $this->assertStringNotContainsString('abc123', $frase);
        }
    }
}
