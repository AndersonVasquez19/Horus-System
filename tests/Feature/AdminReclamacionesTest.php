<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\ReclamacionHistorial;
use App\Models\User;
use App\Services\GestionReclamaciones;
use App\Support\AntispamReclamaciones;
use App\Support\HojaReclamacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\PreparaReclamaciones;
use Tests\TestCase;

/**
 * E3 — Bandeja administrativa del Libro de Reclamaciones (SQLite en memoria).
 * Verifica la consulta administrativa por Gerencia (admin), filtros, detalle, historial, envíos, impresión,
 * auditoría de lecturas, que los GET no modifican reclamaciones y la autorización. No mide PostgreSQL.
 */
class AdminReclamacionesTest extends TestCase
{
    use PreparaReclamaciones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepararReclamaciones(habilitar: true);
    }

    public function test_gerencia_puede_consultar_la_bandeja(): void
    {
        $this->registrar(['tipo' => 'queja', 'nombres' => 'Ana', 'apellidos' => 'Torres']);

        $respuesta = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index'));

        $respuesta->assertOk();
        $respuesta->assertViewIs('admin.reclamaciones.index');
        $respuesta->assertSee('Libro de Reclamaciones');
        $respuesta->assertSee('Total registros');
        $respuesta->assertSee('Ana Torres');
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
    }

    public function test_gerencia_puede_abrir_el_detalle(): void
    {
        $resultado = $this->registrar([
            'tipo' => 'reclamo',
            'nombres' => 'Carlos',
            'apellidos' => 'Mendoza',
            'domicilio' => 'Av. Los Fresnos 456, Cajamarca',
            'detalle' => 'Detalle extendido del caso para la prueba.',
            'pedido' => 'Pedido de devolución formal.',
            'monto_bien' => '250.00',
        ]);
        $rec = $resultado['reclamacion'];

        $conteoAuditAntes = AuditLog::query()->where('action', 'reclamacion_consultada')->count();

        $respuesta = $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $rec));

        $respuesta->assertOk();
        $respuesta->assertViewIs('admin.reclamaciones.show');
        $respuesta->assertSee($rec->numero);
        $respuesta->assertSee('Carlos Mendoza');
        $respuesta->assertSee('Av. Los Fresnos 456, Cajamarca');
        $respuesta->assertSee('Detalle extendido del caso para la prueba.');
        $respuesta->assertSee('Pedido de devolución formal.');
        $respuesta->assertSee('S/ 250.00');

        // Comprueba registro en auditoría sin filtrar datos personales
        $this->assertSame($conteoAuditAntes + 1, AuditLog::query()->where('action', 'reclamacion_consultada')->count());
    }

    public function test_visitante_no_puede_acceder(): void
    {
        $resultado = $this->registrar();
        $rec = $resultado['reclamacion'];

        // Bandeja principal
        $this->get(route('admin.reclamaciones.index'))
            ->assertRedirect(route('login'));

        // Detalle
        $this->get(route('admin.reclamaciones.show', $rec))
            ->assertRedirect(route('login'));
    }

    public function test_usuario_sin_autorizacion_recibe_403(): void
    {
        $resultado = $this->registrar();
        $rec = $resultado['reclamacion'];

        // Usuario con rol 'secretaria' (sin permiso reclamaciones.ver)
        $secretaria = $this->usuarioConPermisos('secretaria');

        $this->actingAs($secretaria)->get(route('admin.reclamaciones.index'))
            ->assertForbidden();

        $this->actingAs($secretaria)->get(route('admin.reclamaciones.show', $rec))
            ->assertForbidden();

        // Usuario con rol 'coordinador' (sin permiso reclamaciones.ver)
        $coordinador = $this->usuarioConPermisos('coordinador');

        $this->actingAs($coordinador)->get(route('admin.reclamaciones.index'))
            ->assertForbidden();

        $this->actingAs($coordinador)->get(route('admin.reclamaciones.show', $rec))
            ->assertForbidden();
    }

    public function test_las_quejas_aparecen_correctamente(): void
    {
        $this->registrar([
            'tipo' => 'queja',
            'nombres' => 'Beatriz',
            'apellidos' => 'Salazar',
            'numero_documento' => '87654321',
            'bien_tipo' => null,
            'bien_descripcion' => null,
            'monto_bien' => null,
        ]);

        $respuesta = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index'));

        $respuesta->assertOk();
        $respuesta->assertSee('QUEJA');
        $respuesta->assertSee('Beatriz Salazar');
        $respuesta->assertSee('••••4321');
    }

    public function test_los_reclamos_aparecen_correctamente(): void
    {
        $this->registrar([
            'tipo' => 'reclamo',
            'nombres' => 'Daniel',
            'apellidos' => 'Gomez',
            'numero_documento' => '11223344',
            'bien_tipo' => 'servicio',
            'bien_descripcion' => 'Curso de Especialización',
            'monto_bien' => '320.00',
        ]);

        $respuesta = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index'));

        $respuesta->assertOk();
        $respuesta->assertSee('RECLAMO');
        $respuesta->assertSee('Daniel Gomez');
        $respuesta->assertSee('••••3344');
    }

    public function test_los_filtros_funcionan(): void
    {
        $q = $this->registrar([
            'tipo' => 'queja',
            'nombres' => 'Elena',
            'apellidos' => 'Ramos',
            'bien_tipo' => null,
            'bien_descripcion' => null,
            'monto_bien' => null,
        ])['reclamacion'];

        $r = $this->registrar([
            'tipo' => 'reclamo',
            'nombres' => 'Fernando',
            'apellidos' => 'Quispe',
            'bien_tipo' => 'servicio',
            'bien_descripcion' => 'Taller',
            'monto_bien' => '100.00',
        ])['reclamacion'];

        // Filtro tipo=queja
        $resQueja = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', ['tipo' => 'queja']));
        $resQueja->assertOk();
        $resQueja->assertSee('Elena Ramos');
        $resQueja->assertDontSee('Fernando Quispe');

        // Filtro tipo=reclamo
        $resReclamo = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', ['tipo' => 'reclamo']));
        $resReclamo->assertOk();
        $resReclamo->assertSee('Fernando Quispe');
        $resReclamo->assertDontSee('Elena Ramos');

        // Filtro estado=abierto
        $resEstado = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', ['estado' => 'abierto']));
        $resEstado->assertOk();
        $resEstado->assertSee('Elena Ramos');
        $resEstado->assertSee('Fernando Quispe');

        // Filtro búsqueda por número
        $resNum = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', ['busqueda' => $q->numero]));
        $resNum->assertOk();
        $resNum->assertSee('Elena Ramos');
        $resNum->assertDontSee('Fernando Quispe');

        // Filtro búsqueda por nombre
        $resNom = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', ['busqueda' => 'Quispe']));
        $resNom->assertOk();
        $resNom->assertSee('Fernando Quispe');
        $resNom->assertDontSee('Elena Ramos');

        // Filtro por fecha (viajamos a la fecha de creación: 2026-10-26)
        $resFecha = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', [
            'desde' => '2026-10-26',
            'hasta' => '2026-10-26',
        ]));
        $resFecha->assertOk();
        $resFecha->assertSee('Elena Ramos');
        $resFecha->assertSee('Fernando Quispe');

        // Filtro por fecha fuera de rango
        $resFechaFuera = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', [
            'desde' => '2025-01-01',
            'hasta' => '2025-01-02',
        ]));
        $resFechaFuera->assertOk();
        $resFechaFuera->assertDontSee('Elena Ramos');
        $resFechaFuera->assertDontSee('Fernando Quispe');
    }

    // --- Buscador administrativo (LIKE/ILIKE con escape literal explícito) ---------------------------------
    // Esta suite corre en SQLite en memoria: demuestra el comportamiento aquí, no la ejecución en PostgreSQL.

    /**
     * Números de las reclamaciones que devuelve la bandeja. Se comparan números y no solo texto visible,
     * porque el campo de búsqueda repite el término escrito en la página.
     *
     * @param  array<string, string>  $parametros
     * @return list<string>
     */
    private function numerosEncontrados(array $parametros): array
    {
        $respuesta = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', $parametros));
        $respuesta->assertOk();
        $paginador = $respuesta->viewData('reclamaciones');
        $this->assertInstanceOf(LengthAwarePaginator::class, $paginador);

        /** @var list<string> $numeros */
        $numeros = collect($paginador->items())->map(fn (Reclamacion $r): string => (string) $r->numero)->sort()->values()->all();

        return $numeros;
    }

    /** @return array{0: Reclamacion, 1: Reclamacion} */
    private function registrarParaBusqueda(): array
    {
        $queja = $this->registrar([
            'tipo' => 'queja',
            'nombres' => 'Ana Lucia',
            'apellidos' => 'Torres Vega',
            'bien_tipo' => null,
            'bien_descripcion' => null,
            'monto_bien' => null,
        ])['reclamacion'];

        $reclamo = $this->registrar([
            'tipo' => 'reclamo',
            'nombres' => 'Bruno',
            'apellidos' => 'Quispe Rojas',
            'bien_tipo' => 'servicio',
            'bien_descripcion' => 'Taller',
            'monto_bien' => '100.00',
        ])['reclamacion'];

        return [$queja, $reclamo];
    }

    public function test_la_busqueda_encuentra_por_numero_parcial_nombres_y_nombre_completo(): void
    {
        [$queja, $reclamo] = $this->registrarParaBusqueda();
        $this->assertNotSame(substr($queja->numero, 0, 9), substr($reclamo->numero, 0, 9));

        // Número parcial: el correlativo sin el año.
        $this->assertSame([$queja->numero], $this->numerosEncontrados(['busqueda' => substr($queja->numero, 0, 9)]));
        // Nombres (incluye un segundo nombre).
        $this->assertSame([$reclamo->numero], $this->numerosEncontrados(['busqueda' => 'Bruno']));
        $this->assertSame([$queja->numero], $this->numerosEncontrados(['busqueda' => 'Lucia']));
        // Nombre completo: cruza el final de «nombres» con el inicio de «apellidos».
        $this->assertSame([$reclamo->numero], $this->numerosEncontrados(['busqueda' => 'Bruno Quispe']));
        $this->assertSame([$queja->numero], $this->numerosEncontrados(['busqueda' => 'Lucia Torres']));

        $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', ['busqueda' => 'Bruno Quispe']))
            ->assertOk()
            ->assertSee('Bruno Quispe Rojas')
            ->assertDontSee('Torres Vega');
    }

    public function test_la_busqueda_parcial_no_distingue_mayusculas_ascii(): void
    {
        [$queja, $reclamo] = $this->registrarParaBusqueda();

        $this->assertSame([$reclamo->numero], $this->numerosEncontrados(['busqueda' => 'uisp']));
        $this->assertSame([$reclamo->numero], $this->numerosEncontrados(['busqueda' => 'bruno quispe']));
        $this->assertSame([$queja->numero], $this->numerosEncontrados(['busqueda' => 'TORRES']));
        $this->assertSame([$queja->numero], $this->numerosEncontrados(['busqueda' => 'lUcIa tOr']));
    }

    public function test_la_busqueda_sin_coincidencias_muestra_el_estado_vacio(): void
    {
        $this->registrarParaBusqueda();

        $this->assertSame([], $this->numerosEncontrados(['busqueda' => 'Inexistente']));
        $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', ['busqueda' => 'Inexistente']))
            ->assertOk()
            ->assertSee('No hay registros')
            ->assertDontSee('Torres Vega')
            ->assertDontSee('Quispe Rojas');
    }

    public function test_la_busqueda_encuentra_caracteres_literales_sin_ampliar_resultados(): void
    {
        $literal = $this->registrar(['nombres' => 'Ana_%!\\Literal'])['reclamacion'];
        $this->registrar(['nombres' => 'AnaXYZLiteral']);

        foreach (['_', '%', '!', '\\', 'Ana_%!\\Literal'] as $termino) {
            $this->assertSame([$literal->numero], $this->numerosEncontrados(['busqueda' => $termino]));
        }
    }

    /** Los comodines escritos por el usuario se buscan como caracteres literales. */
    public function test_los_caracteres_comodin_escritos_por_el_usuario_no_devuelven_todo(): void
    {
        $this->registrarParaBusqueda();

        foreach (['%', '_', '%%', '_%_'] as $termino) {
            $this->assertSame([], $this->numerosEncontrados(['busqueda' => $termino]), "El término {$termino} devolvió registros.");
        }
    }

    public function test_la_busqueda_se_combina_con_los_filtros_de_tipo_estado_y_fechas(): void
    {
        [, $reclamo] = $this->registrarParaBusqueda();

        // Coincide el texto pero no el tipo: sin resultados.
        $this->assertSame([], $this->numerosEncontrados(['busqueda' => 'Bruno', 'tipo' => 'queja']));
        // Coinciden texto, tipo, estado y rango de fechas (el registro es del 26/10/2026).
        $this->assertSame([$reclamo->numero], $this->numerosEncontrados([
            'busqueda' => 'Bruno', 'tipo' => 'reclamo', 'estado' => 'abierto', 'desde' => '2026-10-26', 'hasta' => '2026-10-26',
        ]));
        // Coincide el texto pero no el estado ni las fechas.
        $this->assertSame([], $this->numerosEncontrados(['busqueda' => 'Bruno', 'estado' => 'respondido']));
        $this->assertSame([], $this->numerosEncontrados(['busqueda' => 'Bruno', 'desde' => '2025-01-01', 'hasta' => '2025-01-02']));
    }

    public function test_la_busqueda_rechaza_mas_de_120_caracteres(): void
    {
        $this->registrarParaBusqueda();

        $this->assertSame([], $this->numerosEncontrados(['busqueda' => str_repeat('a', 120)]));
        $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', ['busqueda' => str_repeat('a', 121)]))
            ->assertSessionHasErrors('busqueda');
    }

    public function test_la_busqueda_y_los_filtros_no_exponen_resultados_sin_autorizacion(): void
    {
        $this->registrarParaBusqueda();
        $parametros = ['busqueda' => 'Bruno', 'tipo' => 'reclamo', 'estado' => 'abierto'];

        $this->get(route('admin.reclamaciones.index', $parametros))
            ->assertRedirect(route('login'));

        foreach (['secretaria', 'coordinador'] as $rol) {
            $this->actingAs($this->usuarioConPermisos($rol))
                ->get(route('admin.reclamaciones.index', $parametros))
                ->assertForbidden()
                ->assertDontSee('Quispe Rojas');
        }
    }

    public function test_la_paginacion_funciona(): void
    {
        for ($i = 1; $i <= 16; $i++) {
            $this->registrar(['nombres' => 'Consumidor', 'apellidos' => "Num{$i}"]);
        }

        $resPagina1 = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', ['page' => 1]));
        $resPagina1->assertOk();
        $resPagina1->assertSee('Consumidor Num16');
        $resPagina1->assertDontSee('Consumidor Num1 ');

        $resPagina2 = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', ['page' => 2]));
        $resPagina2->assertOk();
        $resPagina2->assertSee('Consumidor Num1');
    }

    public function test_los_registros_inexistentes_muestran_un_estado_vacio(): void
    {
        $respuesta = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index'));

        $respuesta->assertOk();
        $respuesta->assertSee('No hay registros');
        $respuesta->assertSee('No se encontraron quejas o reclamos en el Libro de Reclamaciones');
    }

    public function test_los_datos_personales_no_se_exponen_a_usuarios_no_autorizados(): void
    {
        $resultado = $this->registrar([
            'numero_documento' => '76543210',
            'domicilio' => 'Direccion Privada y Secreta 789',
            'email' => 'secreto@consumidor.test',
        ]);
        $rec = $resultado['reclamacion'];

        // En el listado no aparece el domicilio ni el email, y el documento está enmascarado
        $resIndex = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index'));
        $resIndex->assertOk();
        $resIndex->assertSee('••••3210');
        $resIndex->assertDontSee('76543210');
        $resIndex->assertDontSee('Direccion Privada y Secreta 789');
        $resIndex->assertDontSee('secreto@consumidor.test');

        // Un usuario sin autorización institucional no puede consultar el detalle para ver los datos completos
        $secretaria = $this->usuarioConPermisos('secretaria');
        $this->actingAs($secretaria)->get(route('admin.reclamaciones.show', $rec))->assertForbidden();
    }

    public function test_formulario_publico_continua_funcionando_e1_e2_e3_integracion(): void
    {
        $token = (string) Str::uuid();
        $marca = AntispamReclamaciones::emitirMarca(time() - 30);

        // Envío público desde formulario (E1 + E2)
        $envio = $this->postJson('/libro-reclamaciones', [
            'tipo' => 'reclamo',
            'nombres' => 'Gabriel',
            'apellidos' => 'Vargas',
            'tipo_documento' => 'dni',
            'numero_documento' => '99887766',
            'domicilio' => 'Calle San Martin 123, Cajamarca',
            'telefono' => '+51 976 543 210',
            'email' => 'gabriel.vargas@ejemplo.test',
            'canal_respuesta' => 'correo',
            'es_menor' => false,
            'bien_tipo' => 'servicio',
            'bien_descripcion' => 'Diplomado en Gestión Pública',
            'monto_bien' => '450.00',
            'detalle' => 'Detalle del reclamo real sobre la plataforma de clases.',
            'pedido' => 'Reprogramación o devolución del módulo 2.',
            'politica' => '1',
            'marca' => $marca,
            'token_envio' => $token,
        ]);

        $envio->assertStatus(201);
        $numeroReal = $envio->json('numero');
        $this->assertNotEmpty($numeroReal);

        // Verificación en bandeja administrativa (E3)
        $resBandeja = $this->actingAs($this->admin)->get(route('admin.reclamaciones.index'));
        $resBandeja->assertOk();
        $resBandeja->assertSee($numeroReal);
        $resBandeja->assertSee('Gabriel Vargas');
        $resBandeja->assertSee('••••7766');

        // Verificación en detalle administrativo (E3)
        $reclamacionDb = Reclamacion::query()->where('numero', $numeroReal)->firstOrFail();
        $resDetalle = $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $reclamacionDb));
        $resDetalle->assertOk();
        $resDetalle->assertSee($numeroReal);
        $resDetalle->assertSee('Gabriel Vargas');
        $resDetalle->assertSee('99887766');
        $resDetalle->assertSee('gabriel.vargas@ejemplo.test');
        $resDetalle->assertSee('Calle San Martin 123, Cajamarca');
        $resDetalle->assertSee('Diplomado en Gestión Pública');
        $resDetalle->assertSee('S/ 450.00');
        $resDetalle->assertSee('Detalle del reclamo real sobre la plataforma de clases.');
        $resDetalle->assertSee('Reprogramación o devolución del módulo 2.');
    }

    public function test_uuid_inexistente_o_malformado_en_detalle_responde_404(): void
    {
        // UUID inexistente
        $uuidInexistente = (string) Str::uuid();
        $this->actingAs($this->admin)->get("/admin/reclamaciones/{$uuidInexistente}")
            ->assertNotFound();

        // Cadena que no es UUID
        $this->actingAs($this->admin)->get('/admin/reclamaciones/cadena-no-uuid')
            ->assertNotFound();
    }

    public function test_detalle_muestra_representante_cuando_es_menor(): void
    {
        $resultado = $this->registrar([
            'tipo' => 'reclamo',
            'nombres' => 'Menor',
            'apellidos' => 'Estudiante',
            'es_menor' => true,
            'representante_nombre' => 'Padre Tutor',
            'representante_domicilio' => 'Calle Los Álamos 456',
            'representante_telefono' => '+51 987 654 321',
            'representante_email' => 'tutor@ejemplo.test',
        ]);
        $rec = $resultado['reclamacion'];

        $res = $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $rec));

        $res->assertOk();
        $res->assertSee('E. Representante legal o apoderado');
        $res->assertSee('Padre Tutor');
        $res->assertSee('+51 987 654 321');
        $res->assertSee('tutor@ejemplo.test');
        $res->assertSee('Calle Los Álamos 456');
    }

    // --- E3: matriz de acceso completa (listado, detalle e impresión) --------------------------------------------

    public function test_matriz_de_acceso_solo_gerencia_consulta_detalla_e_imprime(): void
    {
        $rec = $this->registrar()['reclamacion'];
        $rutas = [
            route('admin.reclamaciones.index'),
            route('admin.reclamaciones.show', $rec),
            route('admin.reclamaciones.hoja', $rec),
        ];

        // Visitante: primero, porque actingAs() mantiene la sesión autenticada en las peticiones siguientes.
        foreach ($rutas as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        foreach ($rutas as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
            foreach (['coordinador', 'secretaria'] as $rol) {
                $this->actingAs($this->usuarioConPermisos($rol))->get($url)->assertForbidden();
            }
            // Usuario común: sin rol de personal ni permisos.
            $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        }
    }

    public function test_e3_no_expone_escrituras_salvo_la_gestion_e4_del_detalle(): void
    {
        $rec = $this->registrar()['reclamacion'];
        $gestion = route('admin.reclamaciones.gestionar', $rec);

        // El layout incluye un modal de confirmación genérico. Fuera de la gestión E4 (formularios POST del detalle
        // hacia la única ruta de gestión) no debe existir ningún formulario de escritura dirigido a reclamaciones.
        foreach (['admin.reclamaciones.index', 'admin.reclamaciones.show', 'admin.reclamaciones.hoja'] as $ruta) {
            $html = (string) $this->actingAs($this->admin)->get(route($ruta, $rec))->assertOk()->getContent();
            preg_match_all('#<form\b[^>]*>#i', $html, $formularios);
            foreach ($formularios[0] as $form) {
                if (! str_contains($form, 'reclamaciones')) {
                    continue;
                }
                if ($ruta === 'admin.reclamaciones.show' && str_contains($form, 'action="'.$gestion.'"')) {
                    $this->assertMatchesRegularExpression('#method="post"#i', $form, "{$ruta}: {$form}");

                    continue;
                }
                // Solo se admite el formulario de filtros del listado, que es GET.
                $this->assertMatchesRegularExpression('#method="get"#i', $form, "{$ruta}: {$form}");
            }
            $this->assertDoesNotMatchRegularExpression('#formaction="[^"]*reclamaciones#', $html, $ruta);
            // El modal genérico del layout usa un _method dinámico (:value); aquí no debe haber uno fijo.
            $this->assertDoesNotMatchRegularExpression('#name="_method"\s+value="(put|patch|delete)"#i', $html, $ruta);
        }

        foreach (['post', 'put', 'patch', 'delete'] as $metodo) {
            $this->actingAs($this->admin)->{$metodo}('/admin/reclamaciones/'.$rec->identificador)->assertStatus(405);
            $this->actingAs($this->admin)->{$metodo}('/admin/reclamaciones/'.$rec->identificador.'/hoja')->assertStatus(405);
        }
    }

    // --- E3: auditoría de las consultas -----------------------------------------------------------------------

    public function test_la_consulta_del_listado_queda_auditada_sin_datos_personales(): void
    {
        $this->registrar(['nombres' => 'Rosa', 'apellidos' => 'Chávez', 'numero_documento' => '44556677']);

        $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', ['busqueda' => 'Chávez', 'tipo' => 'reclamo']))->assertOk();

        $audit = AuditLog::query()->where('action', 'reclamacion_bandeja_consultada')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame(Reclamacion::class, $audit->model_type);
        $this->assertNull($audit->model_id);
        $this->assertNotNull($audit->created_at);
        $this->assertSame(['esquema' => 1, 'filtros' => ['busqueda', 'tipo'], 'pagina' => 1], $audit->changes);
        $crudo = (string) json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE);
        foreach (['Chávez', 'Rosa', '44556677'] as $dato) {
            $this->assertStringNotContainsString($dato, $crudo);
        }
    }

    public function test_una_peticion_invalida_al_listado_no_se_audita(): void
    {
        $this->actingAs($this->admin)->get(route('admin.reclamaciones.index', ['tipo' => 'otro']))->assertSessionHasErrors('tipo');
        $this->actingAs($this->usuarioConPermisos('coordinador'))->get(route('admin.reclamaciones.index'))->assertForbidden();

        $this->assertSame(0, AuditLog::query()->where('action', 'reclamacion_bandeja_consultada')->count());
    }

    public function test_la_consulta_del_detalle_queda_auditada_sin_datos_personales(): void
    {
        $rec = $this->registrar(['nombres' => 'Lidia', 'domicilio' => 'Jr. Reservado 77'])['reclamacion'];

        $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $rec))->assertOk();

        $audit = AuditLog::query()->where('action', 'reclamacion_consultada')->sole();
        $this->assertSame([$this->admin->id, $rec->id], [$audit->user_id, $audit->model_id]);
        $this->assertSame(['esquema' => 1, 'numero' => $rec->numero], $audit->changes);
        $crudo = (string) json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Lidia', $crudo);
        $this->assertStringNotContainsString('Jr. Reservado 77', $crudo);
    }

    // --- E3: historial de actuaciones y envíos ------------------------------------------------------------------

    public function test_el_detalle_muestra_el_historial_y_los_envios_de_la_reclamacion(): void
    {
        $rec = $this->registrar(['email' => 'persona.ficticia@example.test'])['reclamacion'];
        app(GestionReclamaciones::class)->agregarNota($this->admin, $rec->identificador, 'Se llamó al consumidor para confirmar datos.');

        $res = $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $rec))->assertOk();

        $res->assertSee('F. Historial de actuaciones');
        $res->assertSee('Reclamación registrada');
        $res->assertSee('Nota interna');
        $res->assertSee('Se llamó al consumidor para confirmar datos.');
        $res->assertSee($this->admin->name);
        $res->assertSee('Sistema');
        $res->assertSee('Fecha límite: '.$rec->fecha_limite->format('d/m/Y'));

        $res->assertSee('G. Envíos y notificaciones');
        $res->assertSee('Copia de la hoja al consumidor');
        $res->assertSee('Correo electrónico');
        $res->assertSee('Pendiente');
        // Destinatario enmascarado en la tabla de envíos.
        $res->assertSee('pe•••@example.test');
        $envio = ReclamacionEnvio::query()->where('reclamacion_id', $rec->id)->sole();
        $res->assertSee('data-envio-id="'.$envio->id.'"', false);
    }

    public function test_el_detalle_no_expone_el_cuerpo_ni_datos_tecnicos_de_los_envios(): void
    {
        $rec = $this->registrar()['reclamacion'];
        $envio = ReclamacionEnvio::query()->where('reclamacion_id', $rec->id)->sole();
        $envio->forceFill(['proveedor_id' => 'prov-id-secreto-123'])->save();

        $res = $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $rec))->assertOk();

        // Frases que solo existen en el cuerpo de la copia (HojaReclamacion::cuerpo), y datos técnicos del envío.
        $res->assertDontSee('Conserve esta copia como constancia de su reclamación.');
        $res->assertDontSee('LIBRO DE RECLAMACIONES — HOJA DE RECLAMACIÓN');
        $res->assertDontSee($envio->clave);
        $res->assertDontSee('prov-id-secreto-123');
        $res->assertDontSee($envio->asunto);
    }

    public function test_el_detalle_no_muestra_historial_ni_envios_de_otra_reclamacion(): void
    {
        $gestion = app(GestionReclamaciones::class);
        $a = $this->registrar(['nombres' => 'Primera'])['reclamacion'];
        $b = $this->registrar(['nombres' => 'Segunda', 'email' => 'zz.otra@otro.test'])['reclamacion'];
        $gestion->agregarNota($this->admin, $a->identificador, 'Nota exclusiva del expediente A.');
        $gestion->agregarNota($this->admin, $b->identificador, 'Nota exclusiva del expediente B.');
        $envioB = ReclamacionEnvio::query()->where('reclamacion_id', $b->id)->sole();

        $res = $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $a))->assertOk();

        $res->assertSee('Nota exclusiva del expediente A.');
        $res->assertDontSee('Nota exclusiva del expediente B.');
        $res->assertDontSee('data-envio-id="'.$envioB->id.'"', false);
        $res->assertDontSee('zz•••@otro.test');
        $res->assertDontSee($b->numero);
        $this->assertSame([$a->id], $res->viewData('reclamacion')->historial->pluck('reclamacion_id')->unique()->values()->all());
        $this->assertSame([$a->id], $res->viewData('reclamacion')->envios->pluck('reclamacion_id')->unique()->values()->all());
    }

    public function test_el_contenido_malicioso_se_muestra_escapado_en_detalle_e_impresion(): void
    {
        $script = '<script>alert("xss")</script>';
        $rec = $this->registrar(['detalle' => 'Hechos '.$script, 'pedido' => '<img src=x onerror=alert(1)>', 'nombres' => '<b>Nombre</b>'])['reclamacion'];
        app(GestionReclamaciones::class)->agregarNota($this->admin, $rec->identificador, 'Nota <iframe src="javascript:alert(2)"></iframe>');

        foreach (['admin.reclamaciones.show', 'admin.reclamaciones.hoja'] as $ruta) {
            $html = (string) $this->actingAs($this->admin)->get(route($ruta, $rec))->assertOk()->getContent();
            $this->assertStringNotContainsString($script, $html, $ruta);
            $this->assertStringNotContainsString('<img src=x onerror', $html, $ruta);
            $this->assertStringNotContainsString('<b>Nombre</b>', $html, $ruta);
            $this->assertStringNotContainsString('<iframe', $html, $ruta);
            $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html, $ruta);
        }
    }

    // --- E3: impresión de la hoja -----------------------------------------------------------------------------

    public function test_gerencia_imprime_la_hoja_del_expediente_correcto(): void
    {
        $rec = $this->registrar(['nombres' => 'Teresa', 'apellidos' => 'Huamán', 'numero_documento' => '70112233', 'detalle' => 'Hechos del expediente impreso.'])['reclamacion'];
        $otra = $this->registrar(['nombres' => 'Otro', 'apellidos' => 'Consumidor', 'detalle' => 'Hechos de otro expediente.'])['reclamacion'];

        $res = $this->actingAs($this->admin)->get(route('admin.reclamaciones.hoja', $rec));

        $res->assertOk();
        $res->assertViewIs('admin.reclamaciones.hoja');
        $this->assertSame(HojaReclamacion::cuerpo($rec->fresh()), $res->viewData('hoja'));
        $res->assertSee('Hoja de Reclamación N.º '.$rec->numero);
        $res->assertSee('LIBRO DE RECLAMACIONES — HOJA DE RECLAMACIÓN');
        $res->assertSee('Teresa Huamán');
        $res->assertSee('DNI 70112233');
        $res->assertSee('Hechos del expediente impreso.');
        // Datos del proveedor sin configurar: se rotulan, no se inventan.
        $res->assertSee('Razón social: no configurado');
        $res->assertSee('Pendiente de respuesta.');
        $res->assertDontSee($otra->numero);
        $res->assertDontSee('Hechos de otro expediente.');

        $cache = (string) $res->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    public function test_la_impresion_queda_auditada(): void
    {
        $rec = $this->registrar(['nombres' => 'Valeria'])['reclamacion'];

        $this->actingAs($this->admin)->get(route('admin.reclamaciones.hoja', $rec))->assertOk();
        $this->actingAs($this->usuarioConPermisos('secretaria'))->get(route('admin.reclamaciones.hoja', $rec))->assertForbidden();

        $audit = AuditLog::query()->where('action', 'reclamacion_impresa')->sole();
        $this->assertSame([$this->admin->id, $rec->id], [$audit->user_id, $audit->model_id]);
        $this->assertSame(['esquema' => 1, 'numero' => $rec->numero], $audit->changes);
        $this->assertStringNotContainsString('Valeria', (string) json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE));
    }

    public function test_imprimir_uuid_inexistente_o_malformado_responde_404(): void
    {
        $this->actingAs($this->admin)->get('/admin/reclamaciones/'.Str::uuid().'/hoja')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/reclamaciones/no-es-uuid/hoja')->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('action', 'reclamacion_impresa')->count());
    }

    public function test_la_hoja_impresa_incluye_la_respuesta_cuando_existe(): void
    {
        $rec = $this->registrar()['reclamacion'];
        app(GestionReclamaciones::class)->registrarRespuesta($this->admin, $rec->identificador, 'Texto de la respuesta formal por carta.', 'carta', 'Carta entregada 27/10/2026, cargo 001');

        $res = $this->actingAs($this->admin)->get(route('admin.reclamaciones.hoja', $rec))->assertOk();

        $res->assertSee('4. Observaciones y acciones adoptadas por el proveedor');
        $res->assertSee('Texto de la respuesta formal por carta.');
        $res->assertDontSee('Pendiente de respuesta.');
    }

    // --- E3: las consultas GET no modifican reclamaciones -------------------------------------------------------

    /** @return array<string, mixed> */
    private function fotografia(Reclamacion $r): array
    {
        return [
            'reclamacion' => Reclamacion::query()->whereKey($r->id)->firstOrFail()->getAttributes(),
            'historial' => ReclamacionHistorial::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'envios' => ReclamacionEnvio::query()->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }

    public function test_listado_detalle_e_impresion_no_modifican_una_propuesta_vencida(): void
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
            $this->actingAs($this->admin)->get(route('admin.reclamaciones.index'))->assertOk();
            $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $rec))->assertOk()
                ->assertSee('El proceso diario de vencimientos reanudará el plazo');
            $this->actingAs($this->admin)->get(route('admin.reclamaciones.hoja', $rec))->assertOk();
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
            $this->actingAs($this->admin)->get(route('admin.reclamaciones.show', $rec))->assertOk();
            $this->actingAs($this->admin)->get(route('admin.reclamaciones.hoja', $rec))->assertOk();
        }

        $this->assertSame($antes, $this->fotografia($rec));
        $this->assertSame('abierto', $rec->fresh()->estado);
    }

    // --- E3: sin consultas N+1 ----------------------------------------------------------------------------------

    private function consultasDe(string $url): int
    {
        $this->actingAs($this->admin);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }

    public function test_el_listado_y_el_detalle_no_tienen_consultas_n_mas_1(): void
    {
        $gestion = app(GestionReclamaciones::class);
        $una = $this->registrar()['reclamacion'];
        // Calentamiento: la primera petición de la prueba carga roles y permisos del usuario autenticado; las
        // siguientes los reutilizan. Sin esto la diferencia sería de autenticación, no de filas.
        $this->consultasDe(route('admin.reclamaciones.index'));
        $this->consultasDe(route('admin.reclamaciones.show', $una));
        $listadoConUna = $this->consultasDe(route('admin.reclamaciones.index'));
        $detalleCorto = $this->consultasDe(route('admin.reclamaciones.show', $una));

        $varias = $this->registrar()['reclamacion'];
        for ($i = 1; $i <= 6; $i++) {
            $gestion->agregarNota($this->admin, $varias->identificador, "Nota número {$i}.");
            $this->registrar();
        }

        $this->assertSame($listadoConUna, $this->consultasDe(route('admin.reclamaciones.index')), 'El listado no debe crecer con el número de filas.');
        $this->assertSame($detalleCorto, $this->consultasDe(route('admin.reclamaciones.show', $varias)), 'El detalle no debe crecer con el historial.');
    }
}
