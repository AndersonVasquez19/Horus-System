<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Http\Controllers\Api\Panel\Compartido\NotificacionController;
use App\Models\AuditLog;
use App\Models\NotificacionInterna;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * Módulos compartidos de la cuenta propia (/api/panel/v1/compartido): notificaciones, perfil y sesiones. Mismas reglas
 * que el Blade (que sigue en pie mientras exista su shell); aquí se prueban contra la API que usa apps/web.
 */
final class CompartidoCuentaPanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)->json($metodo, '/api/panel/v1/compartido/'.$ruta, $datos, ['X-Horus-Web-Token' => self::TOKEN]);
    }

    private function personal(User $user, string $titulo, array $extra = []): NotificacionInterna
    {
        return NotificacionInterna::crearPersonal($user->getKey(), ['titulo' => $titulo, 'mensaje' => 'Mensaje de '.$titulo, 'tipo' => 'info', ...$extra]);
    }

    private function general(string $titulo): NotificacionInterna
    {
        return NotificacionInterna::crearGeneral(['titulo' => $titulo, 'mensaje' => 'Mensaje de '.$titulo, 'tipo' => 'info']);
    }

    // ---------------------------------------------------------------- acceso

    public function test_exige_sesion_y_permiso_de_entrada_a_algun_panel(): void
    {
        $this->withHeaders(['X-Horus-Web-Token' => self::TOKEN])->getJson('/api/panel/v1/compartido/notificaciones')->assertUnauthorized();
        $this->withHeaders(['X-Horus-Web-Token' => self::TOKEN])->getJson('/api/panel/v1/compartido/perfil')->assertUnauthorized();

        $this->seed(RolesAndPermissionsSeeder::class);
        $sinPanel = User::factory()->create();
        foreach (['notificaciones', 'notificaciones/recientes', 'notificaciones/conteos', 'perfil', 'perfil/sesiones'] as $ruta) {
            $this->como($sinPanel, 'GET', $ruta)->assertForbidden();
        }

        foreach (['admin', 'coordinador', 'secretaria'] as $i => $rol) {
            $this->como($this->usuarioConRol($rol, "cuenta{$i}@example.test"), 'GET', 'perfil')->assertOk();
        }
    }

    // ---------------------------------------------------------------- notificaciones

    public function test_el_listado_solo_trae_lo_visible_para_el_usuario_y_su_estado_de_lectura(): void
    {
        $yo = $this->usuarioConRol('secretaria', 'yo@example.test');
        $otro = User::factory()->create();
        $otro->assignRole('secretaria');
        $this->personal($yo, 'Mía sin leer');
        $this->personal($yo, 'Mía leída', ['leida' => true]);
        $this->personal($otro, 'De otra persona');
        $this->general('General para todos');

        $respuesta = $this->como($yo, 'GET', 'notificaciones')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.pendientes', 2);
        $titulos = $respuesta->json('data.*.titulo');
        $this->assertNotContains('De otra persona', $titulos);
        $porTitulo = array_column($respuesta->json('data'), 'leida', 'titulo');
        $this->assertFalse($porTitulo['Mía sin leer']);
        $this->assertTrue($porTitulo['Mía leída']);
        $this->assertFalse($porTitulo['General para todos']);
    }

    public function test_el_listado_pagina_y_ordena_por_fecha_descendente(): void
    {
        $yo = $this->usuarioConRol('admin', 'pagina@example.test');
        foreach (range(1, 25) as $n) {
            $this->personal($yo, "Aviso {$n}");
        }

        $this->como($yo, 'GET', 'notificaciones?per_page=10&page=3')->assertOk()
            ->assertJsonPath('meta.total', 25)->assertJsonPath('meta.paginas', 3)->assertJsonCount(5, 'data');
        $this->como($yo, 'GET', 'notificaciones')->assertJsonPath('data.0.titulo', 'Aviso 25');
    }

    public function test_recientes_limita_a_cinco_no_leidas_y_recorta_el_mensaje(): void
    {
        $yo = $this->usuarioConRol('coordinador', 'recientes@example.test');
        foreach (range(1, 7) as $n) {
            $this->personal($yo, "No leída {$n}", ['mensaje' => str_repeat('x', 300)]);
        }
        $this->personal($yo, 'Ya leída', ['leida' => true]);

        $respuesta = $this->como($yo, 'GET', 'notificaciones/recientes')->assertOk()
            ->assertJsonCount(NotificacionController::RECIENTES, 'data')
            ->assertJsonPath('meta.pendientes', 7)
            ->assertJsonPath('meta.conteos.notificaciones_sin_leer', 7)
            ->assertJsonPath('meta.conteos.aprobaciones_pendientes', 0)
            ->assertJsonPath('meta.conteos.reclamaciones_por_vencer', 0)
            ->assertJsonPath('meta.conteos.mensajes_nuevos', 0);
        $this->assertLessThanOrEqual(143, mb_strlen((string) $respuesta->json('data.0.mensaje')));
        $this->assertNotContains('Ya leída', $respuesta->json('data.*.titulo'));

        $this->como($yo, 'GET', 'notificaciones/conteos')->assertOk()
            ->assertJsonPath('data.notificaciones_sin_leer', 7)
            ->assertJsonPath('data.aprobaciones_pendientes', 0)
            ->assertJsonPath('data.reclamaciones_por_vencer', 0)
            ->assertJsonPath('data.mensajes_nuevos', 0);
    }

    public function test_el_vinculo_solo_se_entrega_si_es_una_ruta_interna_segura(): void
    {
        $yo = $this->usuarioConRol('admin', 'vinculo@example.test');
        $this->personal($yo, 'Interno', ['vinculo' => '/admin/academico/cursos']);
        $this->personal($yo, 'Externo', ['vinculo' => 'https://malo.example/robo']);
        $this->personal($yo, 'Protocolo relativo', ['vinculo' => '//malo.example']);
        $this->personal($yo, 'Retirado', ['vinculo' => '/admin/procesos/3']);

        $vinculos = array_column($this->como($yo, 'GET', 'notificaciones')->json('data'), 'vinculo', 'titulo');
        $this->assertSame('/admin/academico/cursos', $vinculos['Interno']);
        foreach (['Externo', 'Protocolo relativo', 'Retirado'] as $titulo) {
            $this->assertNull($vinculos[$titulo], $titulo);
        }
    }

    public function test_marcar_una_leida_respeta_la_visibilidad_y_la_lectura_de_una_general_es_propia(): void
    {
        $yo = $this->usuarioConRol('secretaria', 'leida@example.test');
        $otro = User::factory()->create();
        $otro->assignRole('secretaria');
        $mia = $this->personal($yo, 'Mía');
        $ajena = $this->personal($otro, 'Ajena');
        $general = $this->general('General');

        $this->como($yo, 'POST', "notificaciones/{$mia->id}/leida")->assertOk()->assertJsonPath('meta.pendientes', 1);
        $this->como($yo, 'POST', "notificaciones/{$ajena->id}/leida")->assertForbidden();
        $this->assertFalse($ajena->fresh()->leida);

        $this->como($yo, 'POST', "notificaciones/{$general->id}/leida")->assertOk()->assertJsonPath('meta.pendientes', 0);
        // La lectura de una general es de quien la marca: para la otra persona sigue pendiente.
        $this->como($otro, 'GET', 'notificaciones')->assertJsonPath('meta.pendientes', 2);
    }

    public function test_marcar_todas_solo_afecta_al_propio_usuario(): void
    {
        $yo = $this->usuarioConRol('admin', 'todas@example.test');
        $otro = User::factory()->create();
        $otro->assignRole('admin');
        $this->personal($yo, 'Mía 1');
        $this->personal($yo, 'Mía 2');
        $ajena = $this->personal($otro, 'Ajena');
        $this->general('General');

        $this->como($yo, 'POST', 'notificaciones/leidas')->assertOk()
            ->assertJsonPath('data.marcadas', 3)->assertJsonPath('message', 'Se marcaron 3 notificaciones como leídas.');
        $this->assertFalse($ajena->fresh()->leida);
        $this->como($yo, 'GET', 'notificaciones')->assertJsonPath('meta.pendientes', 0);
        $this->como($otro, 'GET', 'notificaciones')->assertJsonPath('meta.pendientes', 2);
        $this->como($yo, 'POST', 'notificaciones/leidas')->assertJsonPath('message', 'No había notificaciones pendientes.');
    }

    // ---------------------------------------------------------------- perfil

    public function test_el_perfil_devuelve_solo_los_datos_de_la_propia_cuenta(): void
    {
        $yo = $this->usuarioConRol('coordinador', 'perfil@example.test');

        $cuerpo = (string) $this->como($yo, 'GET', 'perfil')->assertOk()
            ->assertJsonPath('data.email', 'perfil@example.test')->assertJsonPath('data.roles', ['coordinador'])->getContent();
        $this->assertStringNotContainsString('password', $cuerpo);
        $this->assertStringNotContainsString('remember_token', $cuerpo);
    }

    public function test_actualizar_el_nombre_no_pide_la_clave_y_audita_solo_los_campos(): void
    {
        $yo = $this->usuarioConRol('secretaria', 'nombre@example.test');

        $this->como($yo, 'PUT', 'perfil', ['name' => 'Nombre Nuevo', 'email' => 'nombre@example.test'])->assertOk()
            ->assertJsonPath('message', 'Tu perfil se actualizó correctamente.')->assertJsonPath('data.nombre', 'Nombre Nuevo');
        $registro = AuditLog::query()->where('action', 'perfil_actualizado')->sole();
        $this->assertSame(['name'], $registro->changes['campos']);
        $this->assertFalse($registro->changes['clave_modificada']);

        $this->como($yo, 'PUT', 'perfil', ['name' => 'Nombre Nuevo', 'email' => 'nombre@example.test'])
            ->assertJsonPath('message', 'No hubo cambios en tu perfil.');
        $this->assertSame(1, AuditLog::query()->where('action', 'perfil_actualizado')->count());
    }

    public function test_cambiar_correo_o_clave_exige_la_clave_actual(): void
    {
        $yo = $this->usuarioConRol('admin', 'credenciales@example.test');

        $this->como($yo, 'PUT', 'perfil', ['name' => 'X', 'email' => 'otro.correo@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->como($yo, 'PUT', 'perfil', ['name' => 'X', 'email' => 'credenciales@example.test', 'password' => 'Nueva-Clave-2026!', 'password_confirmation' => 'Nueva-Clave-2026!', 'current_password' => 'incorrecta'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame('credenciales@example.test', $yo->fresh()->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $yo->fresh()->password));
    }

    public function test_cambiar_la_clave_revoca_los_jwt_y_nunca_se_registra_en_la_auditoria(): void
    {
        $yo = $this->usuarioConRol('admin', 'clave@example.test');
        $version = (int) $yo->jwt_version;

        $this->como($yo, 'PUT', 'perfil', [
            'name' => $yo->name, 'email' => 'clave@example.test', 'password' => 'Nueva-Clave-2026!',
            'password_confirmation' => 'Nueva-Clave-2026!', 'current_password' => self::PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check('Nueva-Clave-2026!', $yo->fresh()->password));
        $this->assertSame($version + 1, (int) $yo->fresh()->jwt_version);
        $registro = AuditLog::query()->where('action', 'perfil_actualizado')->sole();
        $this->assertTrue($registro->changes['clave_modificada']);
        $this->assertStringNotContainsString('Nueva-Clave', (string) json_encode($registro->changes));
        $this->assertStringNotContainsString('$2y$', (string) json_encode($registro->changes));
    }

    public function test_una_clave_debil_o_sin_confirmar_se_rechaza(): void
    {
        $yo = $this->usuarioConRol('admin', 'debil@example.test');

        $this->como($yo, 'PUT', 'perfil', ['name' => 'X', 'email' => 'debil@example.test', 'password' => '123', 'password_confirmation' => '123', 'current_password' => self::PASSWORD])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->como($yo, 'PUT', 'perfil', ['name' => 'X', 'email' => 'debil@example.test', 'password' => 'Nueva-Clave-2026!', 'password_confirmation' => 'otra', 'current_password' => self::PASSWORD])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check(self::PASSWORD, $yo->fresh()->password));
    }

    public function test_el_correo_no_puede_pertenecer_a_otra_cuenta(): void
    {
        $yo = $this->usuarioConRol('admin', 'unico@example.test');
        User::factory()->create(['email' => 'ocupado@example.test']);

        $this->como($yo, 'PUT', 'perfil', ['name' => 'X', 'email' => 'ocupado@example.test', 'current_password' => self::PASSWORD])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_el_perfil_nunca_toca_los_roles_ni_el_usuario_de_la_peticion(): void
    {
        $yo = $this->usuarioConRol('secretaria', 'roles@example.test');
        $otro = User::factory()->create(['name' => 'Otro intacto']);

        $this->como($yo, 'PUT', 'perfil', ['name' => 'Yo', 'email' => 'roles@example.test', 'roles' => ['admin'], 'id' => $otro->id, 'user_id' => $otro->id])->assertOk();

        $this->assertSame(['secretaria'], $yo->fresh()->getRoleNames()->all());
        $this->assertSame('Otro intacto', $otro->fresh()->name);
    }

    // ---------------------------------------------------------------- sesiones

    public function test_cerrar_sesiones_exige_la_clave_actual(): void
    {
        $yo = $this->usuarioConRol('admin', 'sesiones@example.test');

        $this->como($yo, 'POST', 'perfil/sesiones/cerrar-otras')->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->como($yo, 'POST', 'perfil/sesiones/cerrar-otras', ['current_password' => 'incorrecta'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->como($yo, 'POST', 'perfil/sesiones/'.str_repeat('a', 32).'/cerrar')->assertUnprocessable();
    }

    public function test_cerrar_las_otras_sesiones_revoca_los_jwt_y_audita_sin_datos_sensibles(): void
    {
        $yo = $this->usuarioConRol('admin', 'otras@example.test');
        $version = (int) $yo->jwt_version;

        $this->como($yo, 'POST', 'perfil/sesiones/cerrar-otras', ['current_password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('data.cerradas', 0);

        $this->assertSame($version + 1, (int) $yo->fresh()->jwt_version);
        $registro = AuditLog::query()->where('action', 'sesiones_cerradas')->sole();
        $this->assertSame('otras', $registro->changes['alcance']);
        // SensitiveData enmascara cualquier clave con «token» al leer la auditoría.
        $this->assertContains($registro->changes['tokens_api_revocados'], [true, '[REDACTADO]']);
    }

    public function test_el_listado_de_sesiones_informa_si_esta_disponible_y_no_expone_identificadores_reales(): void
    {
        $yo = $this->usuarioConRol('coordinador', 'lista@example.test');

        $cuerpo = $this->como($yo, 'GET', 'perfil/sesiones')->assertOk()->assertJsonStructure(['data' => ['disponible', 'sesiones']]);
        foreach ($cuerpo->json('data.sesiones') as $sesion) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $sesion['identificador']);
        }
    }

    public function test_cerrar_una_sesion_inexistente_responde_404_sin_auditar(): void
    {
        $yo = $this->usuarioConRol('admin', 'una@example.test');

        $this->como($yo, 'POST', 'perfil/sesiones/'.str_repeat('a', 32).'/cerrar', ['current_password' => self::PASSWORD])->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('action', 'sesiones_cerradas')->count());
    }
}
