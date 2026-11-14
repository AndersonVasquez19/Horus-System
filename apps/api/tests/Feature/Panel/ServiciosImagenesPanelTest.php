<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\ServicioPublicacion;
use App\Models\ServicioTecnologico;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Imagen principal y tarjetas de la ficha de servicios desde el panel (multipart por el BFF): seguridad del archivo,
 * revisión, consistencia entre archivo y registro, limpieza de huérfanos y vista previa autenticada.
 */
final class ServiciosImagenesPanelTest extends PanelApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** @param array<string, mixed> $datos */
    private function enviar(User $user, string $ruta, array $datos): TestResponse
    {
        return $this->actingAs($user)
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN, 'Accept' => 'application/json'])
            ->post('/api/panel/v1/servicios/'.$ruta, $datos);
    }

    private function leer(User $user, string $ruta): TestResponse
    {
        return $this->actingAs($user)->withHeaders(['X-Horus-Web-Token' => self::TOKEN])->get('/api/panel/v1/servicios/'.$ruta);
    }

    private function admin(): User
    {
        return $this->usuarioConRol('admin', 'admin@horus.test');
    }

    /** Servicio con ficha en borrador. */
    private function servicioConFicha(string $estado = 'borrador', string $codigo = 'REDES'): ServicioTecnologico
    {
        $servicio = ServicioTecnologico::forceCreate([
            'identificador_publico' => (string) Str::uuid(), 'codigo' => $codigo, 'nombre' => 'Redes', 'categoria' => 'redes', 'estado' => 'activo', 'revision' => 1,
        ]);
        $ficha = new ServicioPublicacion([
            'titulo' => 'Redes', 'resumen' => 'Resumen', 'descripcion' => 'Descripción pública.',
            'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(),
        ]);
        $ficha->servicio_tecnologico_id = $servicio->id;
        $ficha->estado = $estado;
        if ($estado === 'publicada') {
            $ficha->autorizado_at = now();
        }
        $ficha->save();

        return $servicio->refresh();
    }

    private function revision(ServicioTecnologico $servicio): string
    {
        return $servicio->refresh()->etag();
    }

    private function archivos(): int
    {
        return count(Storage::disk('public')->allFiles('servicios'));
    }

    public function test_subir_sustituir_y_quitar_la_imagen_principal(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha();

        $subida = $this->enviar($admin, "servicios/{$servicio->id}/imagen", [
            'imagen' => UploadedFile::fake()->image('Mi Foto Original.png', 400, 300), 'imagen_principal_alt' => 'Rack de red', '_revision' => $this->revision($servicio),
        ])->assertOk()->assertJsonPath('data.ficha.con_imagen', true)->assertJsonPath('data.ficha.imagen_principal_alt', 'Rack de red');

        $ruta = (string) $servicio->publicacion()->first()->imagen_principal_ruta;
        $this->assertMatchesRegularExpression('#\Aservicios/[0-9a-f-]{36}\.png\z#', $ruta);
        $this->assertStringNotContainsString('Foto', $ruta);
        $this->assertSame(1, $this->archivos());
        $this->assertStringNotContainsString('servicios/', (string) $subida->getContent(), 'La respuesta no debe revelar rutas de almacenamiento.');
        $this->assertSame(1, AuditLog::query()->where('action', 'servicio_imagen_principal_actualizada')->count());

        $this->enviar($admin, "servicios/{$servicio->id}/imagen", ['imagen' => UploadedFile::fake()->image('otra.jpg', 300, 300), '_revision' => $this->revision($servicio)])->assertOk();
        $nueva = (string) $servicio->publicacion()->first()->imagen_principal_ruta;
        $this->assertNotSame($ruta, $nueva);
        $this->assertSame(1, $this->archivos(), 'La imagen sustituida no debe quedar huérfana.');
        Storage::disk('public')->assertMissing($ruta);

        $this->enviar($admin, "servicios/{$servicio->id}/imagen/quitar", ['_revision' => $this->revision($servicio)])
            ->assertOk()->assertJsonPath('data.ficha.con_imagen', false)->assertJsonPath('data.ficha.imagen_principal_alt', 'Rack de red');
        $this->assertSame(0, $this->archivos());
        $this->assertNull($servicio->publicacion()->first()->imagen_principal_ruta);
    }

    public function test_rechaza_archivos_peligrosos_sin_guardar_nada_ni_cambiar_la_revision(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha();
        $revision = $this->revision($servicio);
        $ruta = "servicios/{$servicio->id}/imagen";

        $casos = [
            'texto con nombre de imagen' => UploadedFile::fake()->createWithContent('foto.png', 'esto no es una imagen'),
            'script php con extension de imagen' => UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo 1; ?>'),
            'svg con script' => UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'imagen con extension ejecutable' => UploadedFile::fake()->image('foto.php', 200, 200),
            'gif no permitido' => UploadedFile::fake()->image('anim.gif', 200, 200),
            'demasiado pesada' => UploadedFile::fake()->image('grande.png', 500, 500)->size(4000),
            'demasiado pequena' => UploadedFile::fake()->image('mini.png', 50, 50),
            'dimensiones excesivas' => UploadedFile::fake()->image('enorme.png', 9000, 100),
        ];

        foreach ($casos as $nombre => $archivo) {
            $this->enviar($admin, $ruta, ['imagen' => $archivo, '_revision' => $revision])->assertStatus(422, "Debe rechazar: {$nombre}");
        }

        $this->assertSame(0, $this->archivos());
        $this->assertSame($revision, $this->revision($servicio));
        $this->assertNull($servicio->publicacion()->first()->imagen_principal_ruta);
    }

    // Recodificación (Etapa E, 14/11/2026): la imagen guardada es una imagen NUEVA -------------------------------

    private function bytesJpeg(int $ancho = 200, int $alto = 200): string
    {
        $im = imagecreatetruecolor($ancho, $alto);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 30, 120, 200));
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    /** Inserta un segmento APP1 (EXIF) justo tras SOI. */
    private function conApp1(string $jpeg, string $carga): string
    {
        return substr($jpeg, 0, 2).hex2bin('FFE1').pack('n', strlen($carga) + 2).$carga.substr($jpeg, 2);
    }

    private function bytesPng(int $ancho = 200, int $alto = 200): string
    {
        $im = imagecreatetruecolor($ancho, $alto);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 60, 60));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function trozoPng(string $tipo, string $datos): string
    {
        return pack('N', strlen($datos)).$tipo.$datos.pack('N', crc32($tipo.$datos));
    }

    private function subirBytes(User $admin, ServicioTecnologico $servicio, string $nombre, string $bytes): TestResponse
    {
        return $this->enviar($admin, "servicios/{$servicio->id}/imagen", [
            'imagen' => UploadedFile::fake()->createWithContent($nombre, $bytes), '_revision' => $this->revision($servicio),
        ]);
    }

    private function guardada(ServicioTecnologico $servicio): string
    {
        return (string) Storage::disk('public')->get((string) $servicio->publicacion()->first()->imagen_principal_ruta);
    }

    public function test_jpeg_se_recodifica_sin_exif_gps_ni_bytes_tras_el_final(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha();
        $peligroso = $this->conApp1($this->bytesJpeg(), 'Exif'.hex2bin('0000').'GPS-LATITUD-SECRETA-4.1234')."<?php echo 'CARGA-POLYGLOT'; ?>";
        $this->assertStringContainsString('SECRETA', $peligroso);

        $this->subirBytes($admin, $servicio, 'foto.jpg', $peligroso)->assertOk();

        $guardada = $this->guardada($servicio);
        $this->assertStringNotContainsString('SECRETA', $guardada);
        $this->assertStringNotContainsString('POLYGLOT', $guardada);
        $this->assertStringNotContainsString('<?php', $guardada);
        $info = getimagesizefromstring($guardada);
        $this->assertIsArray($info);
        $this->assertSame([200, 200, 'image/jpeg'], [$info[0], $info[1], $info['mime']]);
        $this->assertSame('FFD9', strtoupper(bin2hex(substr($guardada, -2))), 'El archivo termina exactamente en el marcador EOI.');
    }

    public function test_png_se_recodifica_sin_chunks_de_texto_ni_bytes_tras_iend(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha();
        $png = $this->bytesPng();
        $texto = $this->trozoPng('tEXt', 'Comment'.hex2bin('00').'DATO-PRIVADO-EN-TEXT');
        $peligroso = substr($png, 0, -12).$texto.substr($png, -12)."<?php system('id'); ?>CARGA-POLYGLOT";

        $this->subirBytes($admin, $servicio, 'logo.png', $peligroso)->assertOk();

        $guardada = $this->guardada($servicio);
        $this->assertStringNotContainsString('DATO-PRIVADO', $guardada);
        $this->assertStringNotContainsString('POLYGLOT', $guardada);
        $this->assertStringNotContainsString('<?php', $guardada);
        $this->assertSame('0000000049454e44ae426082', bin2hex(substr($guardada, -12)), 'Termina exactamente en IEND.');
        $this->assertSame([200, 200], array_slice((array) getimagesizefromstring($guardada), 0, 2));
    }

    public function test_aplica_la_orientacion_exif_antes_de_descartar_los_metadatos(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha();
        // TIFF big-endian, un solo campo: Orientation (0x0112) = 6 (girar 90° a la derecha).
        $tiff = hex2bin('4d4d002a00000008'.'0001'.'0112'.'0003'.'00000001'.'00060000'.'00000000');
        $original = $this->conApp1($this->bytesJpeg(200, 120), 'Exif'.hex2bin('0000').$tiff);

        $this->subirBytes($admin, $servicio, 'movil.jpg', $original)->assertOk();

        $info = getimagesizefromstring($this->guardada($servicio));
        $this->assertIsArray($info);
        $this->assertSame([120, 200], [$info[0], $info[1]], 'La foto apaisada con orientación 6 queda vertical.');
    }

    public function test_imagen_ilegible_da_422_y_no_guarda_nada(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha();
        $revision = $this->revision($servicio);
        // Cabecera válida (pasa las reglas de tipo y dimensiones) con datos de imagen inservibles.
        $ihdr = pack('N', 300).pack('N', 300).hex2bin('0802000000');
        $casos = [
            'png sin datos de imagen' => ['a.png', hex2bin('89504e470d0a1a0a').$this->trozoPng('IHDR', $ihdr).$this->trozoPng('IEND', '')],
            'png con datos corruptos' => ['b.png', hex2bin('89504e470d0a1a0a').$this->trozoPng('IHDR', $ihdr).$this->trozoPng('IDAT', 'no-es-zlib').$this->trozoPng('IEND', '')],
        ];
        foreach ($casos as $nombre => [$archivo, $bytes]) {
            $this->subirBytes($admin, $servicio, $archivo, $bytes)->assertStatus(422, "Debe rechazar: {$nombre}");
        }

        $this->assertSame(0, $this->archivos());
        $this->assertSame($revision, $this->revision($servicio));
    }

    public function test_cabecera_de_ocho_mil_por_ocho_mil_se_rechaza_sin_decodificarla(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha();
        // Firma PNG + IHDR 8000×8000 + IEND: pocos bytes, 64 millones de píxeles al decodificar.
        $ihdr = pack('N', 8000).pack('N', 8000).hex2bin('0802000000');
        $bomba = hex2bin('89504e470d0a1a0a').$this->trozoPng('IHDR', $ihdr).$this->trozoPng('IEND', '');

        $this->subirBytes($admin, $servicio, 'bomba.png', $bomba)->assertStatus(422);

        $this->assertSame(0, $this->archivos());
    }

    public function test_doble_extension_y_nombre_con_recorrido_no_influyen_en_la_ruta_guardada(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha();

        $this->subirBytes($admin, $servicio, '../../public/shell.php.png', $this->bytesPng())->assertOk();

        $ruta = (string) $servicio->publicacion()->first()->imagen_principal_ruta;
        $this->assertMatchesRegularExpression('#\Aservicios/[0-9a-f-]{36}\.png\z#', $ruta);
        $this->assertSame([$ruta], Storage::disk('public')->allFiles());
    }

    public function test_no_acepta_rutas_enviadas_por_el_cliente_ni_varios_archivos(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha();
        $revision = $this->revision($servicio);

        $this->enviar($admin, "servicios/{$servicio->id}/imagen", [
            'imagen' => UploadedFile::fake()->image('a.png', 200, 200), 'imagen_principal_ruta' => '../../.env', '_revision' => $revision,
        ])->assertStatus(422)->assertJsonValidationErrors('imagen_principal_ruta');

        $this->enviar($admin, "servicios/{$servicio->id}/imagen", [
            'imagen' => [UploadedFile::fake()->image('a.png', 200, 200), UploadedFile::fake()->image('b.png', 200, 200)], '_revision' => $revision,
        ])->assertStatus(422);

        $this->assertSame(0, $this->archivos());
        $this->assertSame($revision, $this->revision($servicio));
    }

    public function test_exige_revision_vigente_y_ficha_no_publicada_sin_dejar_huerfanos(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha();
        $ruta = "servicios/{$servicio->id}/imagen";
        $imagen = fn (): UploadedFile => UploadedFile::fake()->image('a.png', 200, 200);

        $this->enviar($admin, $ruta, ['imagen' => $imagen()])->assertStatus(428);
        $this->enviar($admin, $ruta, ['imagen' => $imagen(), '_revision' => '"servicio-'.$servicio->id.'-v99"'])->assertStatus(412);
        $this->assertSame(0, $this->archivos());

        $publicada = $this->servicioConFicha('publicada', 'OTRO');
        $this->enviar($admin, "servicios/{$publicada->id}/imagen", ['imagen' => $imagen(), '_revision' => $this->revision($publicada)])->assertStatus(409);
        $this->assertSame(0, $this->archivos(), 'Una subida rechazada tras guardar el archivo no debe dejarlo huérfano.');
    }

    public function test_sin_permiso_de_edicion_no_se_sube_ni_se_ve(): void
    {
        $servicio = $this->servicioConFicha();
        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');

        $this->enviar($secretaria, "servicios/{$servicio->id}/imagen", ['imagen' => UploadedFile::fake()->image('a.png', 200, 200), '_revision' => $this->revision($servicio)])->assertForbidden();
        $this->enviar($secretaria, "servicios/{$servicio->id}/elementos", ['titulo' => 'T', 'descripcion' => 'D', '_revision' => $this->revision($servicio)])->assertForbidden();
        $this->leer($secretaria, "servicios/{$servicio->id}/imagen")->assertForbidden();
        $this->assertSame(0, $this->archivos());
    }

    public function test_tarjetas_con_imagen_crear_editar_reemplazar_y_eliminar(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha();

        $creada = $this->enviar($admin, "servicios/{$servicio->id}/elementos", [
            'titulo' => 'Cableado', 'descripcion' => 'Instalación certificada.', 'caracteristicas' => "Cat6\nCertificación", 'variante_imagen' => 'producto',
            'imagen_alt' => 'Bobina de cable', 'imagen' => UploadedFile::fake()->image('c.webp', 300, 300), '_revision' => $this->revision($servicio),
        ])->assertCreated()->assertJsonPath('data.ficha.tarjetas', 1)->assertJsonPath('data.ficha.elementos.0.con_imagen', true)
            ->assertJsonPath('data.ficha.elementos.0.caracteristicas', ['Cat6', 'Certificación']);
        $id = $creada->json('data.ficha.elementos.0.id');
        $primera = (string) $servicio->publicacion()->first()->elementos()->first()->imagen_ruta;
        $this->assertSame(1, $this->archivos());

        $this->enviar($admin, "servicios/{$servicio->id}/elementos/{$id}", [
            'titulo' => 'Cableado Cat6', 'descripcion' => 'Instalación certificada.', 'imagen' => UploadedFile::fake()->image('d.png', 300, 300), '_revision' => $this->revision($servicio),
        ])->assertOk()->assertJsonPath('data.ficha.elementos.0.titulo', 'Cableado Cat6');
        $this->assertSame(1, $this->archivos());
        Storage::disk('public')->assertMissing($primera);

        $this->enviar($admin, "servicios/{$servicio->id}/elementos/{$id}", [
            'titulo' => 'Cableado Cat6', 'descripcion' => 'Instalación certificada.', 'quitar_imagen' => '1', '_revision' => $this->revision($servicio),
        ])->assertOk()->assertJsonPath('data.ficha.elementos.0.con_imagen', false);
        $this->assertSame(0, $this->archivos());

        $this->enviar($admin, "servicios/{$servicio->id}/elementos/{$id}/eliminar", ['_revision' => $this->revision($servicio)])
            ->assertOk()->assertJsonPath('data.ficha.tarjetas', 0);
        $this->enviar($admin, "servicios/{$servicio->id}/elementos", ['titulo' => '', 'descripcion' => 'x', '_revision' => $this->revision($servicio)])
            ->assertStatus(422)->assertJsonValidationErrors('titulo');
    }

    public function test_vista_previa_autenticada_con_tipo_real_y_sin_rutas_arbitrarias(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha();

        $this->leer($admin, "servicios/{$servicio->id}/imagen")->assertNotFound();

        $this->enviar($admin, "servicios/{$servicio->id}/imagen", ['imagen' => UploadedFile::fake()->image('a.png', 200, 200), '_revision' => $this->revision($servicio)])->assertOk();
        $respuesta = $this->leer($admin, "servicios/{$servicio->id}/imagen")->assertOk();
        $this->assertSame('image/png', $respuesta->headers->get('Content-Type'));
        $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
        $this->assertStringStartsWith("\x89PNG", (string) $respuesta->getContent());

        // Una ruta manipulada en la base (traversal) nunca se sirve.
        $servicio->publicacion()->first()->forceFill(['imagen_principal_ruta' => 'servicios/../../.env'])->save();
        $this->leer($admin, "servicios/{$servicio->id}/imagen")->assertNotFound();
    }
}
