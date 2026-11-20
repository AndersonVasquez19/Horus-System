<?php

declare(strict_types=1);

use App\Models\ComprobantePago;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Comprobantes en PDF. Un PDF no se puede volver a codificar como una imagen: se acepta tal cual o se rechaza, con una revisión de
 * MEJOR ESFUERZO (acciones, scripts, archivos incrustados, cifrado y objetos comprimidos que no se pueden leer), y se sirve SOLO como
 * descarga. Estas pruebas no demuestran que un PDF sea inocuo: demuestran los casos que la revisión sí detecta.
 */

beforeEach(function (): void {
    Storage::fake('local');
});

/** PDF mínimo de texto. `$catalogo` se añade al diccionario del catálogo; `$cuerpo`, antes del final. */
function pdfDe(string $catalogo = '', string $cuerpo = ''): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R {$catalogo} >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n{$cuerpo}trailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

/** Flujo de objetos comprimido (`/ObjStm`), donde un PDF puede esconder diccionarios. */
function pdfConObjStm(string $contenido): string
{
    $flujo = gzcompress($contenido);

    return pdfDe('', "5 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length ".strlen((string) $flujo)." >>\nstream\n{$flujo}\nendstream\nendobj\n");
}

function pdfSubir(object $t, $usuario, string $transaccion, string $contenido)
{
    return pagoComprobante($t, $usuario, $transaccion, UploadedFile::fake()->createWithContent('voucher.pdf', $contenido));
}

function pdfPago(object $t, $usuario, string $dni = '70000001', string $clave = 'clave-pdf-0000001'): string
{
    return pagoDeMatricula($t, $usuario, pagoMatricula($dni)->id, '50', $clave)->assertCreated()->json('transaccion');
}

it('acepta un PDF simple como comprobante, lo guarda tal cual y lo sirve solo como descarga', function (): void {
    $sec = pagoOperador($this, 'sec-pdf@example.invalid', ['pagos.ver', 'pagos.registrar']);
    $tx = pdfPago($this, $sec);
    $pdf = pdfDe();

    $r = pdfSubir($this, $sec, $tx, $pdf)->assertOk();
    $r->assertJsonPath('data.transacciones.0.tiene_comprobante', true)->assertJsonPath('data.transacciones.0.tipo_comprobante', 'pdf');
    $fila = ComprobantePago::query()->sole();
    expect($fila->mime)->toBe('application/pdf')->and($fila->sha256)->toBe(hash('sha256', $pdf))
        ->and(Storage::disk('local')->get($fila->ruta))->toBe($pdf)->and($fila->ruta)->toEndWith('.pdf');

    $descarga = $this->panelComo($sec, 'GET', "academico/pagos/transacciones/{$tx}/comprobante")->assertOk();
    $descarga->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($descarga->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain('comprobante-de-pago.pdf')
        ->and($descarga->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

it('una captura sigue mostrándose en línea y se informa su tipo', function (): void {
    $sec = pagoOperador($this, 'sec-img@example.invalid', ['pagos.ver', 'pagos.registrar']);
    $tx = pdfPago($this, $sec);

    pagoComprobante($this, $sec, $tx)->assertOk()->assertJsonPath('data.transacciones.0.tipo_comprobante', 'imagen');
    $r = $this->panelComo($sec, 'GET', "academico/pagos/transacciones/{$tx}/comprobante")->assertOk();
    expect($r->headers->get('Content-Disposition'))->toBe('inline');
    $lista = $this->panelComo($sec, 'GET', 'academico/pagos/comprobantes')->assertOk()->json('data.0.tipo_comprobante');
    expect($lista)->toBe('imagen');
});

it('rechaza un PDF con JavaScript, con acciones que lanzan o abren archivos, formularios con envío o cifrado', function (string $catalogo): void {
    $sec = pagoOperador($this, 'sec-mal@example.invalid', ['pagos.ver', 'pagos.registrar']);
    $tx = pdfPago($this, $sec);

    pdfSubir($this, $sec, $tx, pdfDe($catalogo))->assertStatus(422)->assertJsonValidationErrors('imagen');
    expect(ComprobantePago::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles('comprobantes'))->toBe([]);
})->with([
    'javascript' => ['/OpenAction << /S /JavaScript /JS (app.alert(1)) >>'],
    'js abreviado' => ['/AA << /O << /S /JavaScript /JS (x) >> >>'],
    'launch' => ['/OpenAction << /S /Launch /F (calc.exe) >>'],
    'archivo incrustado' => ['/Names << /EmbeddedFiles << /Names [] >> >>'],
    'archivo adjunto' => ['/Annots [ << /Subtype /FileAttachment >> ]'],
    'envío de formulario' => ['/OpenAction << /S /SubmitForm /F (http://x.test) >>'],
    'abre otro archivo' => ['/OpenAction << /S /GoToR /F (otro.pdf) >>'],
    'cifrado' => ['/Encrypt 9 0 R'],
    'formulario XFA' => ['/AcroForm << /XFA [] >>'],
    'nombre escrito con #xx' => ['/OpenAction << /S /J#61vaScript /J#53 (x) >>'],
]);

it('revisa dentro de los objetos comprimidos y rechaza lo que no puede leer', function (): void {
    $sec = pagoOperador($this, 'sec-objstm@example.invalid', ['pagos.ver', 'pagos.registrar']);
    $tx = pdfPago($this, $sec);

    // JavaScript escondido en un flujo de objetos comprimido: se descomprime y se detecta.
    pdfSubir($this, $sec, $tx, pdfConObjStm('1 0 << /S /JavaScript /JS (x) >>'))->assertStatus(422);
    // Objetos comprimidos que no se pueden descomprimir: no se pueden revisar, se rechaza.
    $ilegible = pdfDe('', "5 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Length 12 >>\nstream\nNOESZLIBNOES\nendstream\nendobj\n");
    pdfSubir($this, $sec, $tx, $ilegible)->assertStatus(422);
    // Un flujo de objetos benigno sí se acepta.
    pdfSubir($this, $sec, $tx, pdfConObjStm('1 0 << /Tipo /Libre >>'))->assertOk();
});

it('no confunde datos binarios de un flujo común con elementos prohibidos', function (): void {
    $sec = pagoOperador($this, 'sec-bin@example.invalid', ['pagos.ver', 'pagos.registrar']);
    $tx = pdfPago($this, $sec);
    $cuerpo = "6 0 obj\n<< /Length 30 >>\nstream\nbytes /JS /Launch /Encrypt xx\nendstream\nendobj\n";

    pdfSubir($this, $sec, $tx, pdfDe('', $cuerpo))->assertOk();
});

it('rechaza lo que no es un PDF aunque se llame .pdf, y los PDF de más de 3 MB', function (): void {
    $sec = pagoOperador($this, 'sec-falso@example.invalid', ['pagos.ver', 'pagos.registrar']);
    $tx = pdfPago($this, $sec);

    pdfSubir($this, $sec, $tx, "MZ\x90\x00 esto es un ejecutable, no un PDF")->assertStatus(422);
    pdfSubir($this, $sec, $tx, "<html><script>alert(1)</script></html>")->assertStatus(422);
    pdfSubir($this, $sec, $tx, pdfDe('', str_repeat('0', 3 * 1024 * 1024 + 10)))->assertStatus(422);
    expect(ComprobantePago::query()->count())->toBe(0);
});

it('«matricular con pago» sigue aceptando solo capturas, no PDF', function (): void {
    config(['horus-web.service_token' => 'token-de-servicio-del-bff-para-pruebas-0123456789']);
    [$c, $e] = cpEscenario(20, '90000099');
    $admin = $this->usuarioConRol('admin', 'adm-pdf@example.invalid');

    $cuerpo = cpDatos($c, $e) + ['imagen' => UploadedFile::fake()->createWithContent('voucher.pdf', pdfDe())];
    (fn () => $this->actingAs($admin)->post('/api/panel/v1/academico/matriculas/con-pago', $cuerpo, [
        'X-Horus-Web-Token' => 'token-de-servicio-del-bff-para-pruebas-0123456789', 'Accept' => 'application/json', 'Idempotency-Key' => 'clave-con-pago-pdf',
    ]))->call($this)->assertStatus(422)->assertJsonValidationErrors('imagen');
});

it('detecta el mismo PDF subido a dos pagos', function (): void {
    $sec = pagoOperador($this, 'sec-pdfdup@example.invalid', ['pagos.ver', 'pagos.registrar']);
    $a = pdfPago($this, $sec, '70000011', 'clave-pdf-dup-0001');
    $b = pdfPago($this, $sec, '70000012', 'clave-pdf-dup-0002');
    $pdf = pdfDe('/Lang (es)');

    pdfSubir($this, $sec, $a, $pdf)->assertOk()->assertJsonPath('advertencias.imagen_repetida', null);
    pdfSubir($this, $sec, $b, $pdf)->assertOk()->assertJsonPath('advertencias.imagen_repetida.matricula_id', fn ($v) => is_int($v));
});
