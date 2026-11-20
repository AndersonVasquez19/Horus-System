<?php

declare(strict_types=1);

use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\MetodoPago;
use App\Models\ObligacionPago;
use App\Models\TransaccionPago;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

test('reportes soporta cobranza, asistencia, calificaciones, permisos de origen y pdf completo', function (): void {
    $admin = $this->usuarioConRol('admin');
    $secretaria = $this->usuarioConRol('secretaria');

    $curso = Curso::create([
        'codigo' => 'REP-1', 'nombre' => 'Curso Reportes', 'descripcion' => 'Desc', 'categoria' => 'tecnologia',
        'duracion_horas' => 20, 'precio' => '250.00', 'modalidad' => 'virtual', 'horario' => 'Lun 19:00',
        'cupos_maximos' => 10, 'cupos_disponibles' => 8, 'estado' => 'activo',
    ]);
    $conv = Convocatoria::forceCreate([
        'identificador' => (string) Str::uuid(), 'codigo' => 'CNV-REP-01', 'curso_id' => $curso->id,
        'modalidad' => 'virtual', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-12-01',
        'cupos_maximos' => 10, 'cupos_disponibles' => 9, 'estado' => 'en_curso', 'revision' => 1,
    ]);
    $est = Estudiante::create([
        'dni' => '73000001', 'nombres' => 'Mateo', 'apellidos' => 'Quispe', 'estado' => 'activo',
    ]);
    $m = Matricula::create([
        'estudiante_id' => $est->id, 'curso_id' => $curso->id, 'convocatoria_id' => $conv->id,
        'fecha_matricula' => '2026-11-10', 'estado' => 'pagada', 'monto_pagado' => '250.00',
    ]);
    $m->forceFill(['nota_final' => '16.50', 'resultado' => 'aprobado'])->save();

    $metodo = MetodoPago::firstOrCreate(['codigo' => 'yape'], ['nombre' => 'Yape', 'activo' => true]);
    $ob = ObligacionPago::create([
        'identificador' => (string) Str::uuid(), 'matricula_id' => $m->id, 'concepto' => 'Matrícula',
        'plan' => 'completo', 'monto_total' => '250.00', 'estado' => 'vigente',
    ]);
    TransaccionPago::create([
        'identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $ob->id, 'tipo' => 'pago',
        'metodo_pago_id' => $metodo->id, 'monto' => '250.00', 'fecha_pago' => '2026-11-10', 'estado' => 'confirmada',
    ]);

    // Secretaría tiene pagos.ver desde el 20/11/2026 (sube comprobantes) -> ve la cobranza; sin ese permiso sería 403.
    $this->panelComo($secretaria, 'GET', 'academico/reportes?tipo=cobranza')->assertOk();
    $sinPagos = \App\Models\User::factory()->create(['email' => 'sin-pagos@example.invalid']);
    $sinPagos->givePermissionTo(['panel.academico.acceder', 'reportes.ver']);
    $this->panelComo($sinPagos, 'GET', 'academico/reportes?tipo=cobranza')->assertForbidden();

    $this->panelComo($admin, 'GET', 'academico/reportes?tipo=cobranza')
        ->assertOk()
        ->assertJsonPath('tipo', 'cobranza')
        ->assertJsonPath('resumen.obligaciones', 1)
        ->assertJsonPath('resumen.cobrado', '250.00');

    $this->panelComo($admin, 'GET', 'academico/reportes?tipo=asistencia')
        ->assertOk()
        ->assertJsonPath('tipo', 'asistencia')
        ->assertJsonPath('resumen.ediciones', 1);

    $this->panelComo($admin, 'GET', 'academico/reportes?tipo=calificaciones')
        ->assertOk()
        ->assertJsonPath('tipo', 'calificaciones')
        ->assertJsonPath('resumen.aprobados', 1);

    // Exportación Excel por tipo secundario
    $xlsx = $this->panelComo($admin, 'POST', 'academico/reportes/exportar/excel?tipo=cobranza')
        ->assertOk()
        ->assertDownload();
    $rutaXlsx = $xlsx->baseResponse->getFile()->getPathname();
    try {
        $hoja = IOFactory::load($rutaXlsx)->getActiveSheet();
        expect($hoja->getHighestDataRow())->toBe(2);
    } finally {
        @unlink($rutaXlsx);
    }

    // Exportación PDF completo para matrículas y tipos secundarios
    $pdfCompleto = $this->panelComo($admin, 'POST', 'academico/reportes/exportar/pdf?completo=1')
        ->assertOk()
        ->assertDownload();
    $rutaPdf = $pdfCompleto->baseResponse->getFile()->getPathname();
    try {
        expect((string) file_get_contents($rutaPdf))->toStartWith('%PDF-');
    } finally {
        @unlink($rutaPdf);
    }
});
