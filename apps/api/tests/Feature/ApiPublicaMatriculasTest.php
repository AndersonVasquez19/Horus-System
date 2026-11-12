<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\Estudiante;
use App\Models\SolicitudMatricula;
use App\Models\User;
use App\Support\FormularioSolicitudMatricula;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\InstalaMigracionesSuspendidas;
use Tests\TestCase;

/**
 * Fase 2c.2: solicitud de matrícula por el BFF (`/api/public/v1/matriculas*`). Reutiliza el módulo existente
 * (`SolicitudMatriculaTest` cubre sus reglas de negocio y la bandeja), así que aquí se prueba el contrato del
 * endpoint: token de servicio, límites por visitante, duplicados, menores, y que una solicitud nunca se convierte en
 * matrícula. Transporte simulado y SQLite en memoria con la migración apartada instalada solo en la prueba:
 * NO prueba PostgreSQL, ni concurrencia real, ni correo.
 */
class ApiPublicaMatriculasTest extends TestCase
{
    use InstalaMigracionesSuspendidas;
    use RefreshDatabase;

    private const TOKEN = 'token-de-prueba-bff-0123456789abcdefghij';

    private const VISITANTE = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private User $admin;

    private Curso $curso;

    private CursoPublicacion $publicacion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->instalarMigracionesSuspendidas(['2026_11_09_000035_create_solicitudes_matricula_table']);
        Http::preventStrayRequests();
        Mail::fake();
        config(['catalogo-publico.enabled' => true, 'horus-web.service_token' => self::TOKEN]);
        $this->admin = $this->adminUser();
        $this->curso = Curso::create([
            'codigo' => 'BFF-TEST', 'nombre' => 'Nombre administrativo privado', 'descripcion' => 'Descripción interna',
            'categoria' => 'tecnologia', 'duracion_horas' => 20, 'precio' => 9876.54, 'modalidad' => 'virtual',
            'horario' => 'Interno', 'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo',
        ]);
        $this->publicacion = CursoPublicacion::create([
            'curso_id' => $this->curso->id, 'titulo' => 'Curso público de redes', 'resumen' => 'Contenido autorizado.',
            'modalidad' => 'virtual', 'horario' => 'Sábados 9:00', 'inscripcion' => 'Hasta el 30 de noviembre',
            'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(),
            'publicada' => true, 'autorizado_por' => $this->admin->id, 'autorizado_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function bff(?string $visitante = self::VISITANTE): array
    {
        return ['X-Horus-Web-Token' => self::TOKEN] + ($visitante === null ? [] : ['X-Horus-Visitante' => $visitante]);
    }

    /** @return array<string, mixed> */
    private function formulario(): array
    {
        return $this->getJson('/api/public/v1/matriculas/formulario', $this->bff())->assertOk()->json();
    }

    /** Marca de formulario con la antigüedad mínima ya cumplida (el BFF real espera segundos entre cargar y enviar). */
    private function marcaMadura(): string
    {
        $marca = $this->formulario()['marca'];
        $this->travel(5)->seconds();

        return $marca;
    }

    /** @return array<string, mixed> */
    private function cuerpo(array $extra = [], ?string $marca = null, ?string $curso = null): array
    {
        return $extra + [
            'curso' => $curso ?? $this->formulario()['cursos'][0]['valor'], 'nombres' => 'Persona', 'apellidos' => 'Ficticia',
            'email' => 'persona.ficticia@example.test', 'telefono' => '+51 900 000 000', 'mensaje' => 'Consulta sintética.',
            'consentimiento' => true, 'marca' => $marca ?? $this->marcaMadura(),
        ];
    }

    private function enviar(array $cuerpo, ?string $visitante = self::VISITANTE): TestResponse
    {
        return $this->postJson('/api/public/v1/matriculas', $cuerpo, $this->bff($visitante));
    }

    // --- Acceso y disponibilidad ---------------------------------------------------------------------------

    public function test_exige_el_token_de_servicio_en_ambas_rutas(): void
    {
        $this->getJson('/api/public/v1/matriculas/formulario')->assertUnauthorized();
        $this->postJson('/api/public/v1/matriculas', ['curso' => 'x'])->assertUnauthorized();
        $this->postJson('/api/public/v1/matriculas', [], ['X-Horus-Web-Token' => 'otro-token-otro-token-otro-token-otro!'])->assertUnauthorized();
        $this->assertDatabaseCount('solicitudes_matricula', 0);

        config(['horus-web.service_token' => '']);
        $this->getJson('/api/public/v1/matriculas/formulario', $this->bff())->assertNotFound();
    }

    public function test_el_formulario_solo_ofrece_datos_editoriales_y_una_marca_valida(): void
    {
        $r = $this->getJson('/api/public/v1/matriculas/formulario', $this->bff())->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('habilitado', true)->assertJsonCount(1, 'cursos');
        $curso = $r->json('cursos.0');

        $this->assertSame(['valor', 'titulo', 'resumen', 'modalidad', 'horario', 'inscripcion', 'vigente_hasta'], array_keys($curso));
        $this->assertSame(['Curso público de redes', 'Contenido autorizado.', 'virtual', 'Sábados 9:00'], [$curso['titulo'], $curso['resumen'], $curso['modalidad'], $curso['horario']]);
        $this->assertStringNotContainsString('9876', $r->getContent());
        $this->assertStringNotContainsString('Nombre administrativo privado', $r->getContent());
        $this->assertStringNotContainsString('cupos', $r->getContent());
        $this->assertNotNull(FormularioSolicitudMatricula::leer($this->travelAndMarca($r->json('marca'))));
    }

    private function travelAndMarca(string $marca): string
    {
        $this->travel(5)->seconds();

        return $marca;
    }

    public function test_con_el_catalogo_apagado_o_sin_la_tabla_no_hay_solicitudes(): void
    {
        $cuerpo = $this->cuerpo();
        config(['catalogo-publico.enabled' => false]);
        $this->getJson('/api/public/v1/matriculas/formulario', $this->bff())->assertOk()
            ->assertJson(['habilitado' => false, 'marca' => null, 'cursos' => []]);
        $this->enviar($cuerpo)->assertStatus(503);

        config(['catalogo-publico.enabled' => true]);
        Schema::drop('solicitudes_matricula');
        $this->getJson('/api/public/v1/matriculas/formulario', $this->bff())->assertOk()->assertJsonPath('habilitado', false);
        $this->enviar($cuerpo)->assertStatus(503);
    }

    // --- Alta ----------------------------------------------------------------------------------------------

    public function test_solicitud_correcta_queda_pendiente_cifrada_y_no_es_una_matricula(): void
    {
        $cuerpo = $this->cuerpo();

        $r = $this->enviar($cuerpo)->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $solicitud = SolicitudMatricula::sole();

        $r->assertExactJson(['codigo' => $solicitud->codigoSeguimiento(), 'curso' => 'Curso público de redes', 'modalidad' => 'virtual', 'repetida' => false]);
        $this->assertMatchesRegularExpression('/^SM-[0-9A-F]{8}$/', $r->json('codigo'));
        $this->assertStringNotContainsString('Ficticia', $r->getContent());
        $this->assertStringNotContainsString('persona.ficticia', $r->getContent());
        $this->assertSame('pendiente', $solicitud->estado);
        $this->assertSame('persona.ficticia@example.test', $solicitud->email);
        $this->assertFalse($solicitud->es_menor);
        foreach (['nombres', 'apellidos', 'email', 'telefono', 'mensaje'] as $campo) {
            $this->assertNotSame($cuerpo[$campo], DB::table('solicitudes_matricula')->value($campo));
            $this->assertSame($solicitud->$campo, Crypt::decryptString((string) DB::table('solicitudes_matricula')->value($campo)));
        }
        // Nunca matrícula, estudiante ni consumo de cupo; sin correo ni HTTP.
        $this->assertDatabaseCount('matriculas', 0);
        $this->assertDatabaseCount('estudiantes', 0);
        $this->assertSame(10, $this->curso->fresh()->cupos_disponibles);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    public function test_la_auditoria_deja_constancia_sin_ip_ni_datos_personales(): void
    {
        $this->enviar($this->cuerpo())->assertCreated();

        $audit = AuditLog::query()->where('action', 'solicitud_matricula_recibida')->sole();
        $this->assertNull($audit->user_id);
        $this->assertNull($audit->ip_address);
        $solicitud = SolicitudMatricula::sole();
        $this->assertSame(['codigo' => $solicitud->codigoSeguimiento(), 'curso' => 'Curso público de redes', 'es_menor' => false], $audit->changes);
        $json = AuditLog::all()->toJson();
        foreach (['Ficticia', 'persona.ficticia', '900 000 000', 'Consulta sintética', self::VISITANTE] as $privado) {
            $this->assertStringNotContainsString($privado, $json);
        }
    }

    public function test_participante_menor_exige_representante_y_se_guarda_cifrado(): void
    {
        $marca = $this->marcaMadura();
        $curso = $this->formulario()['cursos'][0]['valor'];
        $base = ['es_menor' => true] + $this->cuerpo([], $marca, $curso);

        $this->enviar($base)->assertStatus(422)->assertJsonValidationErrors(['representante_nombre', 'representante_email', 'representante_telefono']);
        $this->assertDatabaseCount('solicitudes_matricula', 0);

        $this->enviar($base + ['representante_nombre' => 'Madre Ficticia', 'representante_email' => 'madre@example.test', 'representante_telefono' => '+51 911 111 111'])->assertCreated();
        $solicitud = SolicitudMatricula::sole();
        $this->assertTrue($solicitud->es_menor);
        $this->assertSame('Madre Ficticia', $solicitud->representante_nombre);
        $this->assertNotSame('Madre Ficticia', DB::table('solicitudes_matricula')->value('representante_nombre'));
        $this->assertArrayNotHasKey('representante_email', $solicitud->toArray());
        $this->assertStringNotContainsString('madre@example.test', AuditLog::all()->toJson());
    }

    public function test_un_adulto_no_guarda_datos_de_representante_aunque_los_envie(): void
    {
        $this->enviar($this->cuerpo(['representante_nombre' => 'Alguien', 'representante_email' => 'otro@example.test', 'representante_telefono' => '+51 922 222 222']))->assertCreated();

        $this->assertNull(SolicitudMatricula::sole()->representante_nombre);
        $this->assertNull(DB::table('solicitudes_matricula')->value('representante_email'));
    }

    // --- Reglas de disponibilidad (siempre releídas en Laravel) ---------------------------------------------

    public function test_un_curso_retirado_vencido_o_inventado_se_rechaza_aunque_la_web_lo_mostrara(): void
    {
        $cuerpo = $this->cuerpo();

        $this->enviar([...$cuerpo, 'curso' => str_repeat('a', 64)])->assertStatus(422)->assertJsonValidationErrors('curso');
        $this->publicacion->update(['publicada' => false]);
        $this->enviar($cuerpo)->assertStatus(422)->assertJsonValidationErrors('curso');
        $this->publicacion->update(['publicada' => true, 'vigente_hasta' => now('America/Lima')->subDay()->toDateString()]);
        $this->enviar($cuerpo)->assertStatus(422)->assertJsonValidationErrors('curso');
        $this->publicacion->update(['vigente_hasta' => now('America/Lima')->addMonth()->toDateString()]);
        $this->curso->update(['estado' => 'inactivo']);
        $this->enviar($cuerpo)->assertStatus(422)->assertJsonValidationErrors('curso');
        $this->assertDatabaseCount('solicitudes_matricula', 0);
    }

    public function test_el_cliente_no_puede_fijar_estado_curso_interno_ni_identificadores(): void
    {
        $cuerpo = $this->cuerpo();

        foreach (['estado' => 'atendida', 'curso_id' => 1, 'estudiante_id' => 1, 'revision' => 9, 'identificador' => 'x', 'huella' => 'x', 'nota_revision' => 'x'] as $campo => $valor) {
            $this->enviar([...$cuerpo, $campo => $valor])->assertStatus(422)->assertJsonValidationErrors($campo);
        }
        $this->assertDatabaseCount('solicitudes_matricula', 0);
    }

    public function test_datos_invalidos_responden_422_sin_escribir(): void
    {
        $cuerpo = $this->cuerpo();

        $this->enviar([])->assertStatus(422)->assertJsonValidationErrors(['curso', 'nombres', 'apellidos', 'email', 'telefono', 'consentimiento', 'marca']);
        foreach ([['email', 'sin-correo', 'email'], ['telefono', 'abc', 'telefono'], ['nombres', 'A', 'nombres'], ['mensaje', str_repeat('x', 1001), 'mensaje'], ['consentimiento', false, 'consentimiento'], ['sitio_web', 'https://spam.test', 'sitio_web']] as [$campo, $valor, $error]) {
            $this->enviar([...$cuerpo, $campo => $valor])->assertStatus(422)->assertJsonValidationErrors($error);
        }
        $this->enviar([...$cuerpo, 'marca' => 'no-firmada'])->assertStatus(422)->assertJsonValidationErrors('marca');
        $this->assertDatabaseCount('solicitudes_matricula', 0);
    }

    public function test_formulario_demasiado_rapido_o_caducado_se_rechaza(): void
    {
        $curso = $this->formulario()['cursos'][0]['valor'];
        $marca = $this->formulario()['marca'];
        $datos = ['curso' => $curso, 'nombres' => 'Persona', 'apellidos' => 'Ficticia', 'email' => 'persona.ficticia@example.test', 'telefono' => '+51 900 000 000', 'consentimiento' => true, 'marca' => $marca];

        $this->enviar($datos)->assertStatus(422)->assertJsonValidationErrors('marca');
        $this->travel(7201)->seconds();
        $this->enviar($datos)->assertStatus(422)->assertJsonValidationErrors('marca');
        $this->assertDatabaseCount('solicitudes_matricula', 0);
    }

    // --- Idempotencia y duplicados -------------------------------------------------------------------------

    public function test_reenviar_el_mismo_formulario_devuelve_la_misma_solicitud_y_un_cambio_se_rechaza(): void
    {
        $cuerpo = $this->cuerpo();

        $primera = $this->enviar($cuerpo)->assertCreated();
        $this->enviar($cuerpo)->assertOk()->assertJson(['codigo' => $primera->json('codigo'), 'repetida' => true]);
        $this->enviar([...$cuerpo, 'nombres' => 'Otra persona'])->assertStatus(422)->assertJsonValidationErrors('marca');

        $this->assertDatabaseCount('solicitudes_matricula', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'solicitud_matricula_recibida')->count());
    }

    public function test_la_misma_persona_no_duplica_una_solicitud_abierta_para_el_mismo_curso(): void
    {
        $primera = $this->enviar($this->cuerpo())->assertCreated();

        // Otro formulario (marca distinta) con la misma persona y curso, ignorando mayúsculas del correo y nombre.
        $segunda = $this->enviar($this->cuerpo(['email' => 'PERSONA.Ficticia@Example.test', 'nombres' => 'PERSONA']))->assertOk();

        $this->assertSame($primera->json('codigo'), $segunda->json('codigo'));
        $this->assertTrue($segunda->json('repetida'));
        $this->assertDatabaseCount('solicitudes_matricula', 1);
    }

    public function test_tras_atender_la_solicitud_se_admite_una_nueva_y_otro_curso_es_otra_solicitud(): void
    {
        $this->enviar($this->cuerpo())->assertCreated();
        SolicitudMatricula::query()->update(['estado' => 'atendida']);
        $this->enviar($this->cuerpo())->assertCreated();
        $this->assertDatabaseCount('solicitudes_matricula', 2);

        $otro = Curso::create([
            'codigo' => 'BFF-OTRO', 'nombre' => 'Otro', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 5,
            'precio' => 10, 'modalidad' => 'virtual', 'horario' => 'x', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo',
        ]);
        CursoPublicacion::create([
            'curso_id' => $otro->id, 'titulo' => 'Otro curso público', 'resumen' => 'Autorizado.', 'modalidad' => 'virtual',
            'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(),
            'publicada' => true, 'autorizado_por' => $this->admin->id, 'autorizado_at' => now(),
        ]);
        $cursos = collect($this->formulario()['cursos']);
        $valorOtro = $cursos->firstWhere('titulo', 'Otro curso público')['valor'];

        $this->enviar($this->cuerpo([], null, $valorOtro))->assertCreated();
        $this->assertDatabaseCount('solicitudes_matricula', 3);
    }

    // --- Estudiantes y cupos -------------------------------------------------------------------------------

    public function test_un_estudiante_existente_no_se_consulta_ni_se_revela(): void
    {
        $existente = Estudiante::create(['dni' => '12345678', 'nombres' => 'Persona', 'apellidos' => 'Ficticia', 'email' => 'persona.ficticia@example.test', 'telefono' => '900000000', 'estado' => 'activo']);
        $sinEstudiante = $this->enviar($this->cuerpo(['email' => 'nadie.conocido@example.test']))->assertCreated();
        $conEstudiante = $this->enviar($this->cuerpo())->assertCreated();

        // La respuesta tiene la misma forma: no distingue a quien ya es estudiante, y el estudiante no se toca.
        $this->assertSame(array_keys($sinEstudiante->json()), array_keys($conEstudiante->json()));
        $this->assertDatabaseCount('estudiantes', 1);
        $this->assertSame($existente->updated_at->toIso8601String(), $existente->fresh()->updated_at->toIso8601String());
        $this->assertDatabaseCount('matriculas', 0);
    }

    public function test_con_cupos_agotados_la_solicitud_se_recibe_pero_no_reserva_ni_consume_cupo(): void
    {
        $this->curso->update(['cupos_disponibles' => 0]);

        $this->enviar($this->cuerpo())->assertCreated();

        // Los cupos se verifican al registrar la matrícula en el panel; una solicitud es un contacto, no una reserva.
        $this->assertSame(0, $this->curso->fresh()->cupos_disponibles);
        $this->assertSame(10, $this->curso->fresh()->cupos_maximos);
        $this->assertDatabaseCount('matriculas', 0);
    }

    // --- Límites -------------------------------------------------------------------------------------------

    public function test_sexto_envio_valido_del_mismo_visitante_en_10_minutos_responde_429(): void
    {
        foreach (range(1, 5) as $n) {
            $this->enviar($this->cuerpo(['email' => "persona{$n}@example.test"]))->assertCreated();
        }

        $r = $this->enviar($this->cuerpo(['email' => 'persona6@example.test']))->assertStatus(429);
        $this->assertGreaterThan(0, (int) $r->headers->get('Retry-After'));
        $this->assertSame('demasiadas_solicitudes', $r->json('error.code'));
        $this->assertDatabaseCount('solicitudes_matricula', 5);

        $this->enviar($this->cuerpo(['email' => 'persona6@example.test']), str_repeat('b', 64))->assertCreated();
    }

    public function test_reintentar_o_equivocarse_no_consume_el_limite_de_envio(): void
    {
        $cuerpo = $this->cuerpo();
        $this->enviar($cuerpo)->assertCreated();
        for ($i = 0; $i < 3; $i++) {
            $this->enviar($cuerpo)->assertOk()->assertJson(['repetida' => true]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->enviar([...$cuerpo, 'email' => 'mal'])->assertStatus(422);
        }

        // Solo la primera hoja consumió cupo: quedan 4 envíos nuevos.
        foreach (range(1, 4) as $n) {
            $this->enviar($this->cuerpo(['email' => "nueva{$n}@example.test"]))->assertCreated();
        }
        $this->enviar($this->cuerpo(['email' => 'nueva5@example.test']))->assertStatus(429);
    }

    public function test_un_curso_rechazado_por_el_servicio_tampoco_consume_el_limite(): void
    {
        $cuerpo = $this->cuerpo();
        $inventado = [...$cuerpo, 'curso' => str_repeat('a', 64)];
        for ($i = 0; $i < 4; $i++) {
            $this->enviar($inventado)->assertStatus(422)->assertJsonValidationErrors('curso');
        }

        // Los 5 envíos válidos del visitante siguen disponibles.
        foreach (range(1, 5) as $n) {
            $this->enviar($this->cuerpo(['email' => "valida{$n}@example.test"]))->assertCreated();
        }
        $this->enviar($this->cuerpo(['email' => 'valida6@example.test']))->assertStatus(429);
    }

    public function test_rafagas_de_intentos_invalidos_se_frenan_por_visitante(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->enviar([])->assertStatus(422);
        }

        $this->enviar([])->assertStatus(429);
        $this->enviar([], 'no-es-un-hmac')->assertStatus(422);
    }

    // --- Sin consulta pública ni efectos en otros módulos ---------------------------------------------------

    public function test_no_existe_ninguna_ruta_publica_para_consultar_solicitudes(): void
    {
        $r = $this->enviar($this->cuerpo())->assertCreated();
        $solicitud = SolicitudMatricula::sole();

        foreach (['/api/public/v1/matriculas', '/api/public/v1/matriculas/'.$solicitud->identificador, '/api/public/v1/matriculas/'.$r->json('codigo')] as $ruta) {
            $this->assertContains($this->getJson($ruta, $this->bff())->getStatusCode(), [404, 405], $ruta);
        }
        // La bandeja del panel sigue exigiendo sesión y permiso.
        $this->get(route('admin.solicitudes-matricula.index'))->assertRedirect();
    }

    public function test_el_formulario_blade_sigue_funcionando(): void
    {
        $this->post(route('matricula-publica.store'), $this->cuerpo())->assertRedirect(route('matricula-publica.create'))
            ->assertSessionHas('solicitud_matricula_recibida');
        $this->assertDatabaseCount('solicitudes_matricula', 1);
    }

    // --- Bandeja del panel académico -----------------------------------------------------------------------

    public function test_la_bandeja_muestra_el_codigo_busca_por_el_y_muestra_al_representante_solo_al_personal(): void
    {
        $this->enviar($this->cuerpo(['es_menor' => true, 'representante_nombre' => 'Madre Ficticia', 'representante_email' => 'madre@example.test', 'representante_telefono' => '+51 911 111 111']))->assertCreated();
        $solicitud = SolicitudMatricula::sole();
        $url = route('admin.solicitudes-matricula.index');

        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee($solicitud->codigoSeguimiento())
            ->assertSee('Participante menor de edad')->assertSee('Madre Ficticia');
        $this->get($url.'?codigo='.$solicitud->codigoSeguimiento())->assertOk()->assertSee($solicitud->codigoSeguimiento());
        $this->get($url.'?codigo=SM-FFFFFFFF')->assertOk()->assertSee('No hay solicitudes que coincidan');
        $this->get($url.'?codigo='.urlencode('x%; drop'))->assertSessionHasErrors('codigo');

        auth()->logout();
        $sinPermiso = User::factory()->create();
        $this->actingAs($sinPermiso)->get($url)->assertForbidden();
    }
}
