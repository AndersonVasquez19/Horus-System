<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

test('los listados académicos y compartidos exportan CSV y XLSX respetando permisos y neutralizando fórmulas', function (): void {
    $admin = $this->usuarioConRol('admin');
    $secretaria = $this->usuarioConRol('secretaria');

    Estudiante::create([
        'dni' => '74000001', 'nombres' => '=HYPERLINK("http://evil")', 'apellidos' => 'Perez', 'estado' => 'activo',
    ]);
    $curso = Curso::create([
        'codigo' => 'EXP-1', 'nombre' => 'Curso Export', 'descripcion' => 'Desc', 'categoria' => 'tecnologia',
        'duracion_horas' => 10, 'precio' => '100.00', 'modalidad' => 'virtual', 'horario' => 'Lun',
        'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo',
        'enlace_grupo_whatsapp' => 'https://chat.whatsapp.com/PrivadoNoExportar1234',
    ]);
    Convocatoria::forceCreate([
        'identificador' => (string) Str::uuid(), 'codigo' => 'CNV-EXP-01', 'curso_id' => $curso->id,
        'modalidad' => 'virtual', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-12-01',
        'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'abierta', 'revision' => 1,
    ]);

    // Secretaria no tiene usuarios.ver ni reclamaciones.ver -> 403
    $this->panelComo($secretaria, 'POST', 'compartido/usuarios/exportar')->assertForbidden();
    $this->panelComo($secretaria, 'POST', 'compartido/reclamaciones/exportar')->assertForbidden();

    // CSV de estudiantes neutraliza fórmulas
    $csvEst = $this->panelComo($admin, 'POST', 'academico/estudiantes/exportar?formato=csv')->assertOk();
    $contenidoCsv = $csvEst->streamedContent();
    expect($contenidoCsv)->toStartWith("\xEF\xBB\xBF")
        ->and($contenidoCsv)->toContain("'=HYPERLINK");

    // XLSX de cursos omite enlace_grupo_whatsapp y genera hoja válida
    $xlsxCurso = $this->panelComo($admin, 'POST', 'academico/cursos/exportar?formato=xlsx')->assertOk()->assertDownload();
    $ruta = $xlsxCurso->baseResponse->getFile()->getPathname();
    try {
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        expect($hoja->getHighestDataRow())->toBe(2);
    } finally {
        @unlink($ruta);
    }
    expect(json_encode(AuditLog::where('action', 'cursos_exportados')->latest('id')->first()?->changes))->not->toContain('PrivadoNoExportar');

    // CSV ediciones, usuarios, aprobaciones y reclamaciones
    $this->panelComo($admin, 'POST', 'academico/convocatorias/exportar?formato=csv')->assertOk()->streamedContent();
    $this->panelComo($admin, 'POST', 'compartido/usuarios/exportar?formato=csv')->assertOk()->streamedContent();
    $this->panelComo($admin, 'POST', 'compartido/aprobaciones/exportar?formato=csv')->assertOk()->streamedContent();
    $this->panelComo($admin, 'POST', 'compartido/reclamaciones/exportar?formato=csv')->assertOk()->streamedContent();

    // Auditoría soporta CSV y XLSX además de JSON (usuario distinto para no agotar throttle admin-export en el mismo test)
    $otroAdmin = $this->usuarioConRol('admin');
    $csvAud = $this->panelComo($otroAdmin, 'POST', 'compartido/auditoria/exportar?formato=csv')->assertOk()->streamedContent();
    expect($csvAud)->toStartWith("\xEF\xBB\xBF");

    $xlsxAud = $this->panelComo($otroAdmin, 'POST', 'compartido/auditoria/exportar?formato=xlsx')->assertOk()->assertDownload();
    $rutaAud = $xlsxAud->baseResponse->getFile()->getPathname();
    try {
        expect(IOFactory::load($rutaAud)->getActiveSheet()->getHighestDataRow())->toBeGreaterThanOrEqual(2);
    } finally {
        @unlink($rutaAud);
    }
});

test('respaldo acepta selección de tablas y rango de fechas y omite enlaces privados', function (): void {
    $admin = $this->usuarioConRol('admin');
    Curso::create([
        'codigo' => 'RSP-1', 'nombre' => 'Curso Respaldo', 'descripcion' => 'Desc', 'categoria' => 'tecnologia',
        'duracion_horas' => 10, 'precio' => '100.00', 'modalidad' => 'virtual', 'horario' => 'Lun',
        'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo',
        'enlace_grupo_whatsapp' => 'https://chat.whatsapp.com/OcultoEnZip123456',
    ]);

    $resp = $this->panelComo($admin, 'POST', 'compartido/respaldo/exportar?tablas=users,cursos&desde=2026-01-01&hasta=2027-12-31')
        ->assertOk()
        ->assertDownload();

    $rutaZip = $resp->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($rutaZip))->toBeTrue();
    $nombres = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nombres[] = $zip->getNameIndex($i);
    }
    $cursosCsv = (string) $zip->getFromName('cursos.csv');
    $zip->close();
    @unlink($rutaZip);

    expect($nombres)->toEqualCanonicalizing(['users.csv', 'cursos.csv', 'manifest.json'])
        ->and($cursosCsv)->not->toContain('OcultoEnZip123456');
});
