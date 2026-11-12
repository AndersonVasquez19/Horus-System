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
