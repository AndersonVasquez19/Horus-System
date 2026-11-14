<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Auditoría por la API del panel (`/api/panel/v1/compartido/auditoria`): mismos filtros y exportación que el Blade
 * (`ConsultaAuditoria`), permisos propios (`auditoria.ver` y `auditoria.exportar`) y cambios siempre enmascarados.
 */
final class AuditoriaPanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)->json($metodo, '/api/panel/v1/compartido/auditoria'.$ruta, $datos, ['X-Horus-Web-Token' => self::TOKEN]);
    }

    private function admin(): User
    {
        return $this->usuarioConRol('admin', 'auditor@example.test');
    }

    /** `created_at` se guarda en UTC. */
    private function registro(string $accion, string $utc, ?User $user = null, ?array $cambios = null): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $user?->id, 'action' => $accion, 'model_type' => 'App\\Models\\Curso', 'model_id' => 1,
            'changes' => $cambios === null ? null : json_encode($cambios), 'created_at' => $utc,
        ]);
    }

    private function sembrar(): void
    {
        $this->registro('antes_del_rango', '2026-11-03 04:59:59');   // 2026-11-02 23:59:59 en Lima
        $this->registro('inicio_del_rango', '2026-11-03 05:00:00');  // 2026-11-03 00:00:00 en Lima
        $this->registro('noche_en_lima', '2026-11-05 04:30:00');     // 2026-11-04 23:30:00 en Lima
        $this->registro('despues_del_rango', '2026-11-05 05:00:00'); // 2026-11-05 00:00:00 en Lima
    }

    /** @return list<string> */
    private function acciones(User $admin, string $consulta): array
    {
        $acciones = $this->como($admin, 'GET', $consulta)->assertOk()->json('data.*.accion');
        sort($acciones);

        return $acciones;
    }

    // ---------------------------------------------------------------- acceso

    public function test_exige_sesion_y_los_permisos_propios_de_cada_accion(): void
    {
        $this->withHeaders(['X-Horus-Web-Token' => self::TOKEN])->getJson('/api/panel/v1/compartido/auditoria')->assertUnauthorized();

        $this->admin();
        foreach (['coordinador', 'secretaria'] as $i => $rol) {
            $usuario = $this->usuarioConRol($rol, "sinaud{$i}@example.test");
            $this->como($usuario, 'GET', '')->assertForbidden();
            $this->como($usuario, 'POST', '/exportar')->assertForbidden();
        }
        $this->assertSame(0, AuditLog::query()->where('action', 'audit_exported')->count());
    }

    public function test_ver_y_exportar_son_permisos_independientes(): void
    {
        $this->admin();
        $soloVer = User::factory()->create();
        $soloVer->givePermissionTo(['panel.academico.acceder', 'auditoria.ver']);
        $soloExportar = User::factory()->create();
        $soloExportar->givePermissionTo(['panel.servicios.acceder', 'auditoria.exportar']);

        $this->como($soloVer, 'GET', '')->assertOk();
        $this->como($soloVer, 'POST', '/exportar')->assertForbidden();
        $this->como($soloExportar, 'GET', '')->assertForbidden();
        $this->como($soloExportar, 'POST', '/exportar')->assertOk();
    }

    public function test_la_exportacion_solo_acepta_post(): void
    {
        $admin = $this->admin();

        $this->como($admin, 'GET', '/exportar')->assertStatus(405);
        $this->assertSame(0, AuditLog::query()->where('action', 'audit_exported')->count());
    }

    // ---------------------------------------------------------------- listado

    public function test_el_rango_usa_dias_completos_de_lima(): void
    {
        $admin = $this->admin();
        $this->sembrar();

        $this->assertSame(['inicio_del_rango', 'noche_en_lima'], $this->acciones($admin, '?desde=2026-11-03&hasta=2026-11-04&modelo=Curso'));
        $this->assertSame(['despues_del_rango'], $this->acciones($admin, '?desde=2026-11-05&modelo=Curso'));
        $this->assertSame(['antes_del_rango'], $this->acciones($admin, '?hasta=2026-11-02&modelo=Curso'));
    }

    public function test_fechas_invalidas_o_con_forma_de_array_se_ignoran_sin_error(): void
    {
        $admin = $this->admin();
        $this->sembrar();

        foreach (['2026-02-30', '03/11/2026', '2026-11-3', 'x'] as $valor) {
            $this->como($admin, 'GET', '?modelo=Curso&desde='.urlencode($valor))->assertOk()->assertJsonPath('meta.total', 4);
        }
        $this->como($admin, 'GET', '?modelo=Curso&hasta[]=2026-11-03')->assertOk()->assertJsonPath('meta.total', 4);
        $this->como($admin, 'GET', '?accion[]=x&usuario[]=y&modelo[]=z')->assertOk();
    }

    public function test_muestra_la_hora_de_lima_y_el_usuario_o_sistema(): void
    {
        $admin = $this->admin();
        $this->registro('noche_en_lima', '2026-11-05 04:30:00', $admin);
        $this->registro('sin_usuario', '2026-11-05 04:31:00');

        $filas = array_column($this->como($admin, 'GET', '?modelo=Curso')->json('data'), null, 'accion');
        $this->assertSame('04/11/2026 23:30:00', $filas['noche_en_lima']['fecha_local']);
        $this->assertSame('auditor@example.test', $filas['noche_en_lima']['usuario']['email']);
        $this->assertNull($filas['sin_usuario']['usuario']);
    }

    public function test_filtra_por_accion_y_usuario_sin_distinguir_mayusculas_ni_tratar_comodines(): void
    {
        $admin = $this->admin();
        $this->registro('curso_creado', '2026-11-03 10:00:00', $admin);
        $this->registro('curso_ARCHIVADO', '2026-11-03 10:01:00');
        $this->registro('matricula_creada', '2026-11-03 10:02:00');

        $this->assertSame(['curso_ARCHIVADO', 'curso_creado'], $this->acciones($admin, '?accion=CURSO'));
        $this->assertSame(['curso_creado'], $this->acciones($admin, '?usuario=AUDITOR'));
        $this->assertSame([], $this->acciones($admin, '?accion=%25'));
        // `_` es texto, no comodín de un carácter: «curs_» no coincide con «curso».
        $this->assertSame([], $this->acciones($admin, '?accion=curs%5F'));
    }

    public function test_pagina_y_ordena_por_fecha_descendente(): void
    {
        $admin = $this->admin();
        foreach (range(10, 54) as $minuto) {
            $this->registro("evento_{$minuto}", "2026-11-03 10:{$minuto}:00");
        }

        $this->como($admin, 'GET', '?modelo=Curso&per_page=20&page=3')->assertOk()->assertJsonPath('meta.paginas', 3)->assertJsonCount(5, 'data');
        $this->como($admin, 'GET', '?modelo=Curso')->assertJsonPath('data.0.accion', 'evento_54');
        $this->como($admin, 'GET', '?modelo=Curso&sort=fecha')->assertJsonPath('data.0.accion', 'evento_10');
    }

    public function test_los_cambios_salen_siempre_enmascarados(): void
    {
        $admin = $this->admin();
        $this->registro('perfil_actualizado', '2026-11-03 10:00:00', null, [
            'campos' => ['name'], 'password' => 'Secreta-2026!', 'api_token' => 'abc123', 'busqueda' => 'Dni 70123456',
        ]);

        $cuerpo = (string) $this->como($admin, 'GET', '?accion=perfil')->assertOk()->getContent();
        foreach (['Secreta-2026!', 'abc123', '70123456'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $cuerpo);
        }
        $this->assertStringContainsString('REDACTADO', $cuerpo);
    }

    // ---------------------------------------------------------------- exportación

    public function test_la_exportacion_aplica_los_mismos_filtros_y_registra_cuales_se_usaron(): void
    {
        $admin = $this->admin();
        $this->sembrar();

        $json = $this->como($admin, 'POST', '/exportar', ['desde' => '2026-11-03', 'hasta' => '2026-11-04', 'usuario' => 'ana@example.test'])
            ->assertOk()->streamedContent();
        $this->assertSame([], json_decode($json, true, flags: JSON_THROW_ON_ERROR));

        $respuesta = $this->como($admin, 'POST', '/exportar', ['desde' => '2026-11-03', 'hasta' => '2026-11-04'])
            ->assertOk()->assertHeader('Content-Type', 'application/json');
        $acciones = array_column(json_decode($respuesta->streamedContent(), true, flags: JSON_THROW_ON_ERROR), 'accion');
        sort($acciones);
        $this->assertSame(['inicio_del_rango', 'noche_en_lima'], $acciones);

        $registros = AuditLog::where('action', 'audit_exported')->orderBy('id')->get();
        $this->assertCount(2, $registros);
        $this->assertSame(0, $registros[0]->changes['records']);
        $this->assertTrue($registros[0]->changes['filtros']['usuario']);
        $this->assertStringNotContainsString('ana@example.test', (string) json_encode($registros[0]->getAttributes()));
        $this->assertSame(2, $registros[1]->changes['records']);
        $this->assertFalse($registros[1]->changes['filtros']['usuario']);
    }

    public function test_la_exportacion_enmascara_los_cambios_y_no_deja_copia_en_el_servidor(): void
    {
        $admin = $this->admin();
        $this->registro('perfil_actualizado', '2026-11-03 10:00:00', null, ['password' => 'Secreta-2026!', 'campos' => ['name']]);

        $json = $this->como($admin, 'POST', '/exportar', ['accion' => 'perfil'])->assertOk()->streamedContent();

        $this->assertStringNotContainsString('Secreta-2026!', $json);
        $this->assertStringContainsString('REDACTADO', $json);
        $this->assertSame([], glob(storage_path('app/private/exports/auditoria_*')) ?: []);
    }
}
