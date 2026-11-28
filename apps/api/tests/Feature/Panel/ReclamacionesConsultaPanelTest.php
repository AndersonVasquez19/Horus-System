<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\ReclamacionHistorial;
use App\Models\User;
use App\Services\GestionReclamaciones;
use App\Support\AntispamReclamaciones;
use App\Support\HojaReclamacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\PreparaReclamaciones;

/**
 * E3 — Consulta administrativa del Libro de Reclamaciones por la API del panel (SQLite en memoria). Sustituye a
 * `AdminReclamacionesTest` (Blade, retirado el 14/11/2026): búsqueda con escape literal, filtros, paginación, detalle,
 * historial y envíos, hoja, auditoría de lecturas, que los GET no modifican nada y la autorización. No mide PostgreSQL.
 * Las comprobaciones de HTML (escapado, formularios, textos de pantalla) pasaron al componente de Next.
 */
final class ReclamacionesConsultaPanelTest extends PanelApiTestCase
{
    use PreparaReclamaciones;

    private const BASE = '/api/panel/v1/compartido/reclamaciones';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepararReclamaciones(habilitar: true);
    }

    /** @param array<string, mixed> $consulta */
    private function ver(User $usuario, string $ruta = '', array $consulta = []): TestResponse
    {
        $url = self::BASE.$ruta.($consulta === [] ? '' : '?'.http_build_query($consulta));

        return $this->actingAs($usuario)->json('GET', $url, [], ['X-Horus-Web-Token' => self::TOKEN]);
    }

    /**
     * Números de las reclamaciones que devuelve la bandeja.
     *
     * @param  array<string, string>  $parametros
     * @return list<string>
     */
    private function numerosEncontrados(array $parametros): array
    {
        $numeros = $this->ver($this->admin, '', $parametros)->assertOk()->json('data.*.numero');
        sort($numeros);

        return $numeros;
    }

    /** @return array{0: Reclamacion, 1: Reclamacion} */
    private function registrarParaBusqueda(): array
    {
        $queja = $this->registrar([
            'tipo' => 'queja', 'nombres' => 'Ana Lucia', 'apellidos' => 'Torres Vega',
            'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null,
        ])['reclamacion'];

        $reclamo = $this->registrar([
            'tipo' => 'reclamo', 'nombres' => 'Bruno', 'apellidos' => 'Quispe Rojas',
            'bien_tipo' => 'servicio', 'bien_descripcion' => 'Taller', 'monto_bien' => '100.00',
        ])['reclamacion'];

        return [$queja, $reclamo];
    }

    // ---------------------------------------------------------------- acceso

    public function test_matriz_de_acceso_solo_quien_tiene_el_permiso_consulta_detalla_e_imprime(): void
    {
        $rec = $this->registrar()['reclamacion'];
        $rutas = ['', "/{$rec->identificador}", "/{$rec->identificador}/hoja"];

        // Visitante primero: actingAs() mantiene la sesión en las peticiones siguientes.
        foreach ($rutas as $ruta) {
            $this->withHeaders(['X-Horus-Web-Token' => self::TOKEN])->getJson(self::BASE.$ruta)->assertUnauthorized();
        }

        foreach ($rutas as $ruta) {
            $this->ver($this->admin, $ruta)->assertOk();
            foreach (['coordinador', 'secretaria'] as $rol) {
                $this->ver($this->usuarioConPermisos($rol), $ruta)->assertForbidden();
            }
            $this->ver(User::factory()->create(), $ruta)->assertForbidden();
        }
    }

    public function test_los_datos_personales_no_se_exponen_en_el_listado_ni_a_quien_no_esta_autorizado(): void
    {
        $rec = $this->registrar(['numero_documento' => '76543210', 'domicilio' => 'Direccion Privada y Secreta 789', 'email' => 'secreto@consumidor.test'])['reclamacion'];

        $crudo = $this->ver($this->admin)->assertOk()->getContent();
        foreach (['76543210', 'Direccion Privada y Secreta 789', 'secreto@consumidor.test'] as $dato) {
            $this->assertStringNotContainsString($dato, $crudo);
        }

        $secretaria = $this->usuarioConPermisos('secretaria');
        $this->assertStringNotContainsString('Quispe', $this->ver($secretaria, '', ['busqueda' => 'Quispe'])->assertForbidden()->getContent());
        $this->ver($secretaria, "/{$rec->identificador}")->assertForbidden();
    }

    public function test_e3_es_de_solo_lectura_otros_metodos_responden_405(): void
    {
        $rec = $this->registrar()['reclamacion'];

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $metodo) {
            foreach (["/{$rec->identificador}", "/{$rec->identificador}/hoja"] as $ruta) {
                $this->actingAs($this->admin)->json($metodo, self::BASE.$ruta, [], ['X-Horus-Web-Token' => self::TOKEN])->assertStatus(405);
            }
        }
    }

    // ---------------------------------------------------------------- bandeja

    public function test_los_filtros_de_tipo_estado_numero_nombre_y_fecha_funcionan(): void
    {
        $q = $this->registrar(['tipo' => 'queja', 'nombres' => 'Elena', 'apellidos' => 'Ramos', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null])['reclamacion'];
        $r = $this->registrar(['tipo' => 'reclamo', 'nombres' => 'Fernando', 'apellidos' => 'Quispe', 'bien_tipo' => 'servicio', 'bien_descripcion' => 'Taller', 'monto_bien' => '100.00'])['reclamacion'];

        $this->assertSame([$q->numero], $this->numerosEncontrados(['tipo' => 'queja']));
        $this->assertSame([$r->numero], $this->numerosEncontrados(['tipo' => 'reclamo']));
        $this->assertCount(2, $this->numerosEncontrados(['estado' => 'abierto']));
        $this->assertSame([$q->numero], $this->numerosEncontrados(['busqueda' => $q->numero]));
        $this->assertSame([$r->numero], $this->numerosEncontrados(['busqueda' => 'Quispe']));
        // El registro es del 26/10/2026 (la prueba viaja a esa fecha).
        $this->assertCount(2, $this->numerosEncontrados(['desde' => '2026-10-26', 'hasta' => '2026-10-26']));
        $this->assertSame([], $this->numerosEncontrados(['desde' => '2025-01-01', 'hasta' => '2025-01-02']));
    }

    public function test_la_busqueda_encuentra_por_numero_parcial_nombres_y_nombre_completo(): void
    {
        [$queja, $reclamo] = $this->registrarParaBusqueda();
        $this->assertNotSame(substr($queja->numero, 0, 9), substr($reclamo->numero, 0, 9));

        $this->assertSame([$queja->numero], $this->numerosEncontrados(['busqueda' => substr($queja->numero, 0, 9)]));
        $this->assertSame([$reclamo->numero], $this->numerosEncontrados(['busqueda' => 'Bruno']));
        $this->assertSame([$queja->numero], $this->numerosEncontrados(['busqueda' => 'Lucia']));
        // Nombre completo: cruza el final de «nombres» con el inicio de «apellidos».
        $this->assertSame([$reclamo->numero], $this->numerosEncontrados(['busqueda' => 'Bruno Quispe']));
        $this->assertSame([$queja->numero], $this->numerosEncontrados(['busqueda' => 'Lucia Torres']));
        $this->assertSame(['Bruno Quispe Rojas'], $this->ver($this->admin, '', ['busqueda' => 'Bruno Quispe'])->json('data.*.consumidor'));
    }

    public function test_la_busqueda_parcial_no_distingue_mayusculas_ascii(): void
    {
        [$queja, $reclamo] = $this->registrarParaBusqueda();

        $this->assertSame([$reclamo->numero], $this->numerosEncontrados(['busqueda' => 'uisp']));
        $this->assertSame([$reclamo->numero], $this->numerosEncontrados(['busqueda' => 'bruno quispe']));
        $this->assertSame([$queja->numero], $this->numerosEncontrados(['busqueda' => 'TORRES']));
        $this->assertSame([$queja->numero], $this->numerosEncontrados(['busqueda' => 'lUcIa tOr']));
    }

    public function test_la_busqueda_sin_coincidencias_devuelve_una_lista_vacia(): void
    {
        $this->registrarParaBusqueda();

        $this->assertSame([], $this->numerosEncontrados(['busqueda' => 'Inexistente']));
        $this->assertSame([], $this->ver($this->admin, '', ['busqueda' => 'Inexistente'])->json('data'));
    }

    public function test_la_busqueda_encuentra_caracteres_literales_sin_ampliar_resultados(): void
    {
        $literal = $this->registrar(['nombres' => 'Ana_%!\\Literal'])['reclamacion'];
        $this->registrar(['nombres' => 'AnaXYZLiteral']);

        foreach (['_', '%', '!', '\\', 'Ana_%!\\Literal'] as $termino) {
            $this->assertSame([$literal->numero], $this->numerosEncontrados(['busqueda' => $termino]));
        }
    }

    public function test_los_comodines_escritos_por_el_usuario_no_devuelven_todo(): void
    {
        $this->registrarParaBusqueda();

        foreach (['%', '_', '%%', '_%_'] as $termino) {
            $this->assertSame([], $this->numerosEncontrados(['busqueda' => $termino]), "El término {$termino} devolvió registros.");
        }
    }

    public function test_la_busqueda_se_combina_con_los_filtros_de_tipo_estado_y_fechas(): void
    {
        [, $reclamo] = $this->registrarParaBusqueda();

        $this->assertSame([], $this->numerosEncontrados(['busqueda' => 'Bruno', 'tipo' => 'queja']));
        $this->assertSame([$reclamo->numero], $this->numerosEncontrados([
            'busqueda' => 'Bruno', 'tipo' => 'reclamo', 'estado' => 'abierto', 'desde' => '2026-10-26', 'hasta' => '2026-10-26',
        ]));
        $this->assertSame([], $this->numerosEncontrados(['busqueda' => 'Bruno', 'estado' => 'respondido']));
        $this->assertSame([], $this->numerosEncontrados(['busqueda' => 'Bruno', 'desde' => '2025-01-01', 'hasta' => '2025-01-02']));
    }

    public function test_la_busqueda_rechaza_mas_de_120_caracteres(): void
    {
        $this->registrarParaBusqueda();

        $this->assertSame([], $this->numerosEncontrados(['busqueda' => str_repeat('a', 120)]));
        $this->ver($this->admin, '', ['busqueda' => str_repeat('a', 121)])->assertUnprocessable()->assertJsonValidationErrors('busqueda');
    }

    public function test_la_paginacion_funciona_con_el_mas_reciente_primero(): void
    {
        for ($i = 1; $i <= 16; $i++) {
            $this->registrar(['nombres' => 'Consumidor', 'apellidos' => "Num{$i}"]);
        }

        $pagina1 = $this->ver($this->admin, '', ['page' => 1, 'per_page' => 15])->assertOk();
        $this->assertCount(15, $pagina1->json('data'));
        $this->assertSame('Consumidor Num16', $pagina1->json('data.0.consumidor'));
        $this->assertNotContains('Consumidor Num1', $pagina1->json('data.*.consumidor'));
        $this->assertSame(['pagina' => 1, 'por_pagina' => 15, 'total' => 16, 'paginas' => 2], collect($pagina1->json('meta'))->only(['pagina', 'por_pagina', 'total', 'paginas'])->all());

        $this->assertSame(['Consumidor Num1'], $this->ver($this->admin, '', ['page' => 2, 'per_page' => 15])->json('data.*.consumidor'));
    }

    // ---------------------------------------------------------------- integración con el alta pública

    public function test_el_formulario_publico_alimenta_la_bandeja_y_el_detalle(): void
    {
        $token = (string) Str::uuid();
        $marca = AntispamReclamaciones::emitirMarca(time() - 30);

        $envio = $this->postJson('/api/public/v1/reclamaciones', [
            'tipo' => 'reclamo', 'nombres' => 'Gabriel', 'apellidos' => 'Vargas', 'tipo_documento' => 'dni',
            'numero_documento' => '99887766', 'domicilio' => 'Calle San Martin 123, Cajamarca', 'telefono' => '976543210',
            'email' => 'gabriel.vargas@ejemplo.test', 'canal_respuesta' => 'correo', 'es_menor' => false,
            'bien_tipo' => 'servicio', 'bien_descripcion' => 'Diplomado en Gestión Pública', 'monto_bien' => '450.00',
            'detalle' => 'Detalle del reclamo real sobre la plataforma de clases.', 'pedido' => 'Reprogramación o devolución del módulo 2.',
            'acepta_privacidad' => true, 'marca' => $marca, 'token_envio' => $token,
        ], ['X-Horus-Web-Token' => self::TOKEN]);
        $envio->assertStatus(201);
        $numero = $envio->json('numero');
        $this->assertNotEmpty($numero);

        $this->assertSame(['Gabriel Vargas'], $this->ver($this->admin)->assertOk()->json('data.*.consumidor'));

        $r = Reclamacion::query()->where('numero', $numero)->firstOrFail();
        $detalle = $this->ver($this->admin, "/{$r->identificador}")->assertOk();
        $detalle->assertJsonPath('data.numero', $numero)
            ->assertJsonPath('data.consumidor.numero_documento', '99887766')
            ->assertJsonPath('data.consumidor.email', 'gabriel.vargas@ejemplo.test')
            ->assertJsonPath('data.consumidor.domicilio', 'Calle San Martin 123, Cajamarca')
            ->assertJsonPath('data.bien.descripcion', 'Diplomado en Gestión Pública')
            ->assertJsonPath('data.bien.monto', '450.00')
            ->assertJsonPath('data.detalle', 'Detalle del reclamo real sobre la plataforma de clases.')
            ->assertJsonPath('data.pedido', 'Reprogramación o devolución del módulo 2.');
    }

    // ---------------------------------------------------------------- detalle, historial y envíos

    public function test_uuid_inexistente_o_malformado_responde_404_en_detalle_y_hoja(): void
    {
        foreach (['', '/hoja'] as $sufijo) {
            $this->ver($this->admin, '/'.Str::uuid().$sufijo)->assertNotFound();
            $this->ver($this->admin, '/cadena-no-uuid'.$sufijo)->assertNotFound();
        }
        $this->assertSame(0, AuditLog::query()->whereIn('action', ['reclamacion_consultada', 'reclamacion_impresa'])->count());
    }

    public function test_el_detalle_muestra_el_historial_y_los_envios_de_la_reclamacion(): void
    {
        $rec = $this->registrar(['email' => 'persona.ficticia@example.test'])['reclamacion'];
        app(GestionReclamaciones::class)->agregarNota($this->admin, $rec->identificador, 'Se llamó al consumidor para confirmar datos.');

        $d = $this->ver($this->admin, "/{$rec->identificador}")->assertOk();

        $this->assertContains('Reclamación registrada', $d->json('data.historial.*.etiqueta'));
        $this->assertContains('Nota interna', $d->json('data.historial.*.etiqueta'));
        $this->assertContains('Se llamó al consumidor para confirmar datos.', $d->json('data.historial.*.nota'));
        $this->assertContains($this->admin->name, $d->json('data.historial.*.actor'));
        $this->assertContains('Sistema', $d->json('data.historial.*.actor'));
        $this->assertContains('Fecha límite: '.$rec->fecha_limite->format('d/m/Y'), collect($d->json('data.historial'))->pluck('detalles')->flatten()->all());

        $envio = ReclamacionEnvio::query()->where('reclamacion_id', $rec->id)->sole();
        $d->assertJsonPath('data.envios.0.id', $envio->id)
            ->assertJsonPath('data.envios.0.tipo', 'Copia de la hoja al consumidor')
            ->assertJsonPath('data.envios.0.canal', 'Correo electrónico')
            ->assertJsonPath('data.envios.0.estado_etiqueta', 'Pendiente')
            ->assertJsonPath('data.envios.0.destinatario', 'pe•••@example.test');
    }

    public function test_el_detalle_no_expone_el_cuerpo_ni_datos_tecnicos_de_los_envios(): void
    {
        $rec = $this->registrar()['reclamacion'];
        $envio = ReclamacionEnvio::query()->where('reclamacion_id', $rec->id)->sole();
        $envio->forceFill(['proveedor_id' => 'prov-id-secreto-123'])->save();

        $crudo = $this->ver($this->admin, "/{$rec->identificador}")->assertOk()->getContent();

        foreach (['Conserve esta copia como constancia de su reclamación.', 'LIBRO DE RECLAMACIONES', (string) $envio->clave, 'prov-id-secreto-123', (string) $envio->asunto] as $oculto) {
            $this->assertStringNotContainsString($oculto, $crudo);
        }
    }

    public function test_el_detalle_no_muestra_historial_ni_envios_de_otra_reclamacion(): void
    {
        $gestion = app(GestionReclamaciones::class);
        $a = $this->registrar(['nombres' => 'Primera'])['reclamacion'];
        $b = $this->registrar(['nombres' => 'Segunda', 'email' => 'zz.otra@otro.test'])['reclamacion'];
        $gestion->agregarNota($this->admin, $a->identificador, 'Nota exclusiva del expediente A.');
        $gestion->agregarNota($this->admin, $b->identificador, 'Nota exclusiva del expediente B.');
        $envioB = ReclamacionEnvio::query()->where('reclamacion_id', $b->id)->sole();

        $d = $this->ver($this->admin, "/{$a->identificador}")->assertOk();
        $crudo = $d->getContent();

        $this->assertContains('Nota exclusiva del expediente A.', $d->json('data.historial.*.nota'));
        $this->assertStringNotContainsString('Nota exclusiva del expediente B.', $crudo);
        $this->assertStringNotContainsString('zz•••@otro.test', $crudo);
        $this->assertStringNotContainsString($b->numero, $crudo);
        $this->assertNotContains($envioB->id, $d->json('data.envios.*.id'));
        $this->assertCount(1, $d->json('data.envios'));
    }

    // ---------------------------------------------------------------- hoja

    public function test_la_hoja_corresponde_al_expediente_correcto(): void
    {
        $rec = $this->registrar(['nombres' => 'Teresa', 'apellidos' => 'Huamán', 'numero_documento' => '70112233', 'detalle' => 'Hechos del expediente impreso.'])['reclamacion'];
        $otra = $this->registrar(['nombres' => 'Otro', 'apellidos' => 'Consumidor', 'detalle' => 'Hechos de otro expediente.'])['reclamacion'];

        config(['reclamaciones.proveedor' => []]);
        $res = $this->ver($this->admin, "/{$rec->identificador}/hoja")->assertOk();
        $texto = $res->json('data.texto_oficial');

        $this->assertSame(HojaReclamacion::cuerpo($rec->fresh()), $texto);
        $this->assertStringContainsString('LIBRO DE RECLAMACIONES — HOJA DE RECLAMACIÓN', $texto);
        $this->assertStringContainsString('Teresa Huamán', $texto);
        $this->assertStringContainsString('DNI 70112233', $texto);
        $this->assertStringContainsString('Hechos del expediente impreso.', $texto);
        // Datos del proveedor sin configurar: se rotulan, no se inventan.
        $this->assertStringContainsString('Razón social: no configurado', $texto);
        $res->assertJsonPath('data.numero', $rec->numero)->assertJsonPath('data.respuesta', null);
        $this->assertStringNotContainsString($otra->numero, $res->getContent());
        $this->assertStringNotContainsString('Hechos de otro expediente.', $res->getContent());
        $cache = (string) $res->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    public function test_la_hoja_incluye_la_respuesta_cuando_existe(): void
    {
        $rec = $this->registrar()['reclamacion'];
        app(GestionReclamaciones::class)->registrarRespuesta($this->admin, $rec->identificador, 'Texto de la respuesta formal por carta.', 'carta', 'Carta entregada 27/10/2026, cargo 001');

        $this->ver($this->admin, "/{$rec->identificador}/hoja")->assertOk()->assertJsonPath('data.respuesta', 'Texto de la respuesta formal por carta.');
    }

    // ---------------------------------------------------------------- auditoría de las consultas

    public function test_la_consulta_del_listado_queda_auditada_sin_datos_personales(): void
    {
        $this->registrar(['nombres' => 'Rosa', 'apellidos' => 'Chávez', 'numero_documento' => '44556677']);

        $this->ver($this->admin, '', ['busqueda' => 'Chávez', 'tipo' => 'reclamo'])->assertOk();

        $audit = AuditLog::query()->where('action', 'reclamacion_bandeja_consultada')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame(Reclamacion::class, $audit->model_type);
        $this->assertNull($audit->model_id);
        $this->assertSame(['esquema' => 1, 'filtros' => ['busqueda', 'tipo'], 'pagina' => 1], $audit->changes);
        $crudo = (string) json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE);
        foreach (['Chávez', 'Rosa', '44556677'] as $dato) {
            $this->assertStringNotContainsString($dato, $crudo);
        }
    }

    public function test_una_peticion_invalida_o_denegada_al_listado_no_se_audita(): void
    {
        $this->ver($this->admin, '', ['tipo' => 'otro'])->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->ver($this->usuarioConPermisos('coordinador'))->assertForbidden();

        $this->assertSame(0, AuditLog::query()->where('action', 'reclamacion_bandeja_consultada')->count());
    }

    public function test_la_consulta_del_detalle_queda_auditada_sin_datos_personales(): void
    {
        $rec = $this->registrar(['nombres' => 'Lidia', 'domicilio' => 'Jr. Reservado 77'])['reclamacion'];

        $this->ver($this->admin, "/{$rec->identificador}")->assertOk();

        $audit = AuditLog::query()->where('action', 'reclamacion_consultada')->sole();
        $this->assertSame([$this->admin->id, $rec->id], [$audit->user_id, $audit->model_id]);
        $this->assertSame(['esquema' => 1, 'numero' => $rec->numero], $audit->changes);
        $crudo = (string) json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Lidia', $crudo);
        $this->assertStringNotContainsString('Jr. Reservado 77', $crudo);
    }

    public function test_la_impresion_queda_auditada_y_la_denegada_no(): void
    {
        $rec = $this->registrar(['nombres' => 'Valeria'])['reclamacion'];

        $this->ver($this->admin, "/{$rec->identificador}/hoja")->assertOk();
        $this->ver($this->usuarioConPermisos('secretaria'), "/{$rec->identificador}/hoja")->assertForbidden();

        $audit = AuditLog::query()->where('action', 'reclamacion_impresa')->sole();
        $this->assertSame([$this->admin->id, $rec->id], [$audit->user_id, $audit->model_id]);
        $this->assertSame(['esquema' => 1, 'numero' => $rec->numero], $audit->changes);
        $this->assertStringNotContainsString('Valeria', (string) json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE));
    }

    // ---------------------------------------------------------------- las consultas no modifican reclamaciones

    /** @return array<string, mixed> */
    private function fotografia(Reclamacion $r): array
    {
        return [
            'reclamacion' => Reclamacion::query()->whereKey($r->id)->firstOrFail()->getAttributes(),
            'historial' => ReclamacionHistorial::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'envios' => ReclamacionEnvio::query()->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }

    public function test_listado_detalle_y_hoja_no_modifican_una_propuesta_vencida(): void
    {
        $gestion = app(GestionReclamaciones::class);
        $rec = $this->registrar()['reclamacion'];
        $gestion->enviarPropuesta($this->admin, $rec->identificador, 'Propuesta de solución', 'Ofrecemos reprogramar el curso.');
        $rec->refresh();
        $this->assertNotNull($rec->suspension_hasta);

        // Pasada la ventana de la propuesta, el vencimiento queda pendiente del proceso diario.
        $this->travelTo($rec->suspension_hasta->copy()->addDays(3)->setTime(12, 0));
        $this->assertTrue($gestion->propuestaVencidaSinProcesar($rec->fresh()));

        $antes = $this->fotografia($rec);
        $this->travel(5)->minutes();

        for ($i = 0; $i < 2; $i++) {
            $this->ver($this->admin)->assertOk();
            $this->ver($this->admin, "/{$rec->identificador}")->assertOk()->assertJsonPath('meta.propuesta_vencida', true);
            $this->ver($this->admin, "/{$rec->identificador}/hoja")->assertOk();
        }

        // Ni estado, ni plazos, ni updated_at, ni historial de negocio, ni envíos cambiaron.
        $this->assertSame($antes, $this->fotografia($rec));
        $this->assertSame(0, AuditLog::query()->where('action', 'reclamacion_propuesta_vencida')->count());
        // La auditoría de lectura sí registra cada acceso.
        $this->assertSame(2, AuditLog::query()->where('action', 'reclamacion_bandeja_consultada')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'reclamacion_consultada')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'reclamacion_impresa')->count());

        // Control: el mecanismo independiente sí resolvería el vencimiento (las reglas no cambiaron).
        $this->assertTrue($gestion->resolverVencimiento($rec->identificador));
        $this->assertNull($rec->fresh()->suspension_hasta);
    }

    public function test_consultas_repetidas_no_modifican_un_caso_abierto(): void
    {
        $rec = $this->registrar()['reclamacion'];
        $antes = $this->fotografia($rec);
        $this->travel(1)->hours();

        for ($i = 0; $i < 3; $i++) {
            $this->ver($this->admin, "/{$rec->identificador}")->assertOk();
            $this->ver($this->admin, "/{$rec->identificador}/hoja")->assertOk();
        }

        $this->assertSame($antes, $this->fotografia($rec));
        $this->assertSame('abierto', $rec->fresh()->estado);
    }

    // ---------------------------------------------------------------- sin consultas N+1

    private function consultasDe(string $ruta): int
    {
        $this->actingAs($this->admin);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->ver($this->admin, $ruta)->assertOk();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }

    public function test_el_listado_y_el_detalle_no_tienen_consultas_n_mas_1(): void
    {
        $gestion = app(GestionReclamaciones::class);
        $una = $this->registrar()['reclamacion'];
        // Calentamiento: la primera petición carga roles y permisos del usuario; las siguientes los reutilizan.
        $this->consultasDe('');
        $this->consultasDe("/{$una->identificador}");
        $listadoConUna = $this->consultasDe('');
        $detalleCorto = $this->consultasDe("/{$una->identificador}");

        $varias = $this->registrar()['reclamacion'];
        for ($i = 1; $i <= 6; $i++) {
            $gestion->agregarNota($this->admin, $varias->identificador, "Nota número {$i}.");
            $this->registrar();
        }

        $this->assertSame($listadoConUna, $this->consultasDe(''), 'El listado no debe crecer con el número de filas.');
        $this->assertSame($detalleCorto, $this->consultasDe("/{$varias->identificador}"), 'El detalle no debe crecer con el historial.');
    }
}
