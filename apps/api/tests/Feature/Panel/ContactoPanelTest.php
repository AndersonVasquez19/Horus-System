<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\MensajeContacto;
use App\Models\NotificacionInterna;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * «Contáctanos»: el formulario público (`POST /api/public/v1/contactos`, solo con el token de servicio del BFF) guarda el mensaje y
 * avisa al equipo; la bandeja del panel lo muestra con los permisos de reclamaciones. SQLite en memoria.
 */
final class ContactoPanelTest extends PanelApiTestCase
{
    private const VISITANTE = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('contacto-bff-v10:'.self::VISITANTE);
    }

    /** @param array<string, mixed> $extra */
    private function enviar(array $extra = [], bool $conToken = true): TestResponse
    {
        $cuerpo = $extra + [
            'nombre' => 'María Pérez', 'telefono' => '927 000 000', 'correo' => 'maria@example.test',
            'asunto' => 'Cursos', 'mensaje' => 'Quisiera información sobre los cursos.',
        ];

        return $this->postJson('/api/public/v1/contactos', $cuerpo, $conToken ? ['X-Horus-Web-Token' => self::TOKEN, 'X-Horus-Visitante' => self::VISITANTE] : []);
    }

    /** @param array<string, mixed> $datos */
    private function como(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)->withHeaders(['X-Horus-Web-Token' => self::TOKEN])->json($metodo, '/api/panel/v1/'.$ruta, $datos);
    }

    public function test_guarda_el_mensaje_audita_sin_texto_y_avisa_al_equipo(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');

        $this->enviar()->assertCreated()->assertJsonPath('recibido', true);

        $this->assertSame(1, MensajeContacto::query()->count());
        $this->assertDatabaseHas('mensajes_contacto', ['nombre' => 'María Pérez', 'correo' => 'maria@example.test', 'estado' => 'nuevo']);
        $auditoria = AuditLog::query()->where('action', 'contacto_recibido')->sole();
        $this->assertStringNotContainsString('María', json_encode($auditoria->changes));
        $this->assertStringNotContainsString('información', json_encode($auditoria->changes));
        $aviso = NotificacionInterna::query()->where('user_id', $admin->id)->where('tipo', 'contacto')->sole();
        $this->assertStringNotContainsString('María', (string) $aviso->mensaje);
        $this->assertSame('/admin/academico/reclamaciones?vista=mensajes', $aviso->vinculoSeguro());
    }

    public function test_exige_el_token_de_servicio_y_valida_los_campos(): void
    {
        $this->enviar(conToken: false)->assertStatus(401);
        $this->enviar(['nombre' => ''])->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->enviar(['asunto' => 'Inventado'])->assertUnprocessable()->assertJsonValidationErrors('asunto');
        $this->enviar(['telefono' => '', 'correo' => ''])->assertUnprocessable()->assertJsonValidationErrors(['telefono', 'correo']);
        $this->enviar(['correo' => 'no-es-correo'])->assertUnprocessable()->assertJsonValidationErrors('correo');
        $this->enviar(['sitio_web' => 'https://spam.example'])->assertUnprocessable();
        $this->assertSame(0, MensajeContacto::query()->count());
    }

    public function test_limita_los_envios_seguidos_de_un_visitante(): void
    {
        foreach (range(1, 3) as $i) {
            $this->enviar(['mensaje' => "Mensaje número {$i} de prueba."])->assertCreated();
        }
        $this->enviar()->assertStatus(429);
    }

    public function test_la_bandeja_exige_permiso_y_el_atendido_lo_deja_registrado(): void
    {
        $this->enviar()->assertCreated();
        $id = MensajeContacto::query()->sole()->identificador;
        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');
        $admin = User::factory()->create(['email' => 'otro-admin@horus.test']);
        $admin->assignRole('admin');

        $this->como($secretaria, 'GET', 'compartido/reclamaciones-mensajes')->assertForbidden();

        $lista = $this->como($admin, 'GET', 'compartido/reclamaciones-mensajes')->assertOk();
        $lista->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.nombre', 'María Pérez')->assertJsonPath('meta.nuevos', 1);
        $this->assertArrayNotHasKey('visitante', $lista->json('data.0'));

        $this->como($admin, 'POST', "compartido/reclamaciones-mensajes/{$id}/atender")->assertOk();
        $this->como($admin, 'POST', "compartido/reclamaciones-mensajes/{$id}/atender")->assertOk();
        $this->assertDatabaseHas('mensajes_contacto', ['identificador' => $id, 'estado' => 'atendido', 'atendido_por' => $admin->id]);
        $this->assertSame(1, AuditLog::query()->where('action', 'contacto_atendido')->count());
        $this->como($admin, 'GET', 'compartido/reclamaciones-mensajes?estado=nuevo')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_atender_en_lote_y_filtros_de_mensajes_de_contacto(): void
    {
        $this->enviar(['nombre' => 'María Pérez', 'asunto' => 'Cursos', 'mensaje' => 'Consulta sobre PHP'])->assertCreated();
        $this->enviar(['nombre' => 'Ana Gómez', 'correo' => 'ana@example.test', 'asunto' => 'Educación', 'mensaje' => 'Consulta diplomado'])->assertCreated();
        $ids = MensajeContacto::query()->orderBy('id')->pluck('identificador')->all();

        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');

        $this->como($admin, 'GET', 'compartido/reclamaciones-mensajes?busqueda=diplomado&asunto=Educaci%C3%B3n')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Ana Gómez')
            ->assertJsonPath('meta.asuntos', ['Cursos', 'Educación']);

        $this->como($secretaria, 'POST', 'compartido/reclamaciones-mensajes/atender-lote', ['identificadores' => $ids])->assertForbidden();

        $this->como($admin, 'POST', 'compartido/reclamaciones-mensajes/atender-lote', ['identificadores' => $ids])
            ->assertOk()
            ->assertJsonPath('data.marcados', 2);

        $this->assertSame(2, MensajeContacto::query()->where('estado', 'atendido')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'contacto_atendido')->count());
    }
}
