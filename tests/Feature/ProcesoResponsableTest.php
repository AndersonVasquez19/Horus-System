<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProcesoInterno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProcesoResponsableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();

        // Matriz mínima ficticia en memoria; no se ejecuta ni modifica el seeder.
        foreach (['procesos.ver', 'procesos.gestionar'] as $permiso) {
            Permission::create(['name' => $permiso, 'guard_name' => 'web']);
        }
        Role::create(['name' => 'admin', 'guard_name' => 'web'])
            ->givePermissionTo(['procesos.ver', 'procesos.gestionar']);
        Role::create(['name' => 'coordinador', 'guard_name' => 'web'])
            ->givePermissionTo('procesos.ver');
        Role::create(['name' => 'secretaria', 'guard_name' => 'web']);
        $this->actingAs($this->usuario('admin'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function candidatos(): iterable
    {
        yield 'gerencia' => ['admin', true];
        yield 'coordinacion' => ['coordinador', true];
        yield 'secretaria' => ['secretaria', false];
        yield 'cuenta publica sin roles' => ['publico', false];
        yield 'permiso directo sin rol' => ['directo', false];
        yield 'secretaria con permiso directo' => ['secretaria_directo', false];
        yield 'varios roles elegibles' => ['multiple', true];
        yield 'rol elegible sin permiso' => ['sin_permiso', false];
        yield 'rol elegible con permiso directo' => ['coordinador_directo', true];
        yield 'permiso heredado de otro rol' => ['heredado_multiple', true];
    }

    #[DataProvider('candidatos')]
    public function test_selectores_y_escrituras_comparten_la_elegibilidad(string $caso, bool $elegible): void
    {
        $candidato = $this->candidato($caso);
        $proceso = $this->proceso();

        foreach (['admin.procesos.create', 'admin.procesos.edit'] as $ruta) {
            $respuesta = $this->get(route($ruta, $ruta === 'admin.procesos.edit' ? $proceso : []))->assertOk();
            $ids = $respuesta->viewData('responsables')->modelKeys();
            $this->assertSame($elegible, in_array($candidato->id, $ids, true));
            $this->assertSame(count($ids), count(array_unique($ids)));
            $opcion = '<option value="'.$candidato->id.'"';
            if ($elegible) {
                $respuesta->assertSee($opcion, false);
            } else {
                $respuesta->assertDontSee($opcion, false);
            }
        }

        $datos = $this->datos(['responsable_id' => (string) $candidato->id]);
        $alta = $this->post(route('admin.procesos.store'), $datos);
        $antes = $proceso->fresh()->getAttributes();
        $edicion = $this->put(route('admin.procesos.update', $proceso), $datos);
        if ($elegible) {
            $alta->assertSessionHasNoErrors()->assertRedirect(route('admin.procesos.index'));
            $edicion->assertSessionHasNoErrors()->assertRedirect(route('admin.procesos.index'));
            $this->assertDatabaseHas('procesos_internos', ['titulo' => 'Proceso nuevo ficticio', 'responsable_id' => $candidato->id]);
            $this->assertSame($candidato->id, $proceso->fresh()->responsable_id);
            $this->assertSame(2, ProcesoInterno::count());
        } else {
            $alta->assertSessionHasErrors('responsable_id');
            $edicion->assertSessionHasErrors('responsable_id');
            $this->assertSame($antes, $proceso->fresh()->getAttributes());
            $this->assertSame(1, ProcesoInterno::count());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function idsInvalidos(): iterable
    {
        yield 'inexistente' => [999999];
        yield 'array' => [[1]];
        yield 'texto' => ['invalido'];
        yield 'decimal' => [1.5];
    }

    #[DataProvider('idsInvalidos')]
    public function test_rechaza_ids_invalidos_sin_mutar_el_proceso(mixed $id): void
    {
        $proceso = $this->proceso();
        $antes = $proceso->fresh()->getAttributes();
        $datos = $this->datos(['responsable_id' => $id]);
        $this->postJson(route('admin.procesos.store'), $datos)->assertUnprocessable()->assertJsonValidationErrors('responsable_id');
        $this->putJson(route('admin.procesos.update', $proceso), $datos)->assertUnprocessable()->assertJsonValidationErrors('responsable_id');
        $this->assertSame($antes, $proceso->fresh()->getAttributes());
        $this->assertSame(1, ProcesoInterno::count());
    }

    public function test_null_permite_crear_y_desasignar_expresamente(): void
    {
        $this->post(route('admin.procesos.store'), $this->datos())
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('procesos_internos', ['titulo' => 'Proceso nuevo ficticio', 'responsable_id' => null]);

        $proceso = $this->proceso($this->usuario('coordinador'));
        $this->put(route('admin.procesos.update', $proceso), $this->datos())
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull($proceso->fresh()->responsable_id);
    }

    public function test_historico_se_conserva_solo_en_su_proceso_y_permanece_seleccionado(): void
    {
        $historico = $this->usuario('coordinador');
        $proceso = $this->proceso($historico);
        $historico->removeRole('coordinador');
        $otro = $this->proceso();

        $this->get(route('admin.procesos.edit', $proceso))->assertOk()
            ->assertSee('<option value="'.$historico->id.'" selected>', false);
        $this->get(route('admin.procesos.create'))->assertOk()
            ->assertDontSee('<option value="'.$historico->id.'"', false);
        $this->get(route('admin.procesos.edit', $otro))->assertOk()
            ->assertDontSee('<option value="'.$historico->id.'"', false);

        $datos = $this->datos(['responsable_id' => (string) $historico->id, 'titulo' => 'Edicion historica']);
        $this->put(route('admin.procesos.update', $proceso), $datos)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($historico->id, $proceso->fresh()->responsable_id);
        $this->assertSame('Edicion historica', $proceso->fresh()->titulo);

        unset($datos['responsable_id']);
        $this->put(route('admin.procesos.update', $proceso), $datos)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($historico->id, $proceso->fresh()->responsable_id);

        $datos['responsable_id'] = $historico->id;
        $this->postJson(route('admin.procesos.store'), $datos)->assertUnprocessable();
        $this->putJson(route('admin.procesos.update', $otro), $datos)->assertUnprocessable();
        $this->assertNull($otro->fresh()->responsable_id);

        $rechazado = $this->usuario('secretaria');
        $this->putJson(route('admin.procesos.update', $proceso), $this->datos(['responsable_id' => $rechazado->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('responsable_id');
        $this->assertSame($historico->id, $proceso->fresh()->responsable_id);

        $elegible = $this->usuario('coordinador');
        $this->put(route('admin.procesos.update', $proceso), $this->datos(['responsable_id' => $elegible->id]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($elegible->id, $proceso->fresh()->responsable_id);
        $this->putJson(route('admin.procesos.update', $proceso), $datos)->assertUnprocessable();
    }

    public function test_historico_que_pierde_permiso_y_el_reenvio_tras_error_no_se_desasignan(): void
    {
        $historico = $this->usuario('coordinador');
        $proceso = $this->proceso($historico);
        Role::findByName('coordinador')->revokePermissionTo('procesos.ver');
        $this->from(route('admin.procesos.edit', $proceso))
            ->put(route('admin.procesos.update', $proceso), $this->datos(['responsable_id' => $historico->id, 'estado' => 'invalido']))
            ->assertSessionHasErrors('estado');
        $this->get(route('admin.procesos.edit', $proceso))->assertOk()
            ->assertSee('<option value="'.$historico->id.'" selected>', false);
        $this->put(route('admin.procesos.update', $proceso), $this->datos(['responsable_id' => $historico->id]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($historico->id, $proceso->fresh()->responsable_id);
        $this->postJson(route('admin.procesos.store'), $this->datos(['responsable_id' => $historico->id]))
            ->assertUnprocessable();
    }

    public function test_autorizacion_del_actor_no_cambia(): void
    {
        $proceso = $this->proceso();
        foreach (['coordinador', 'secretaria', null] as $rol) {
            $this->actingAs($this->usuario($rol));
            $listado = $this->get(route('admin.procesos.index'));
            if ($rol === 'coordinador') {
                $listado->assertOk();
            } else {
                $listado->assertForbidden();
            }
            $this->get(route('admin.procesos.create'))->assertForbidden();
            $this->get(route('admin.procesos.edit', $proceso))->assertForbidden();
            $this->post(route('admin.procesos.store'), $this->datos())->assertForbidden();
            $this->put(route('admin.procesos.update', $proceso), $this->datos())->assertForbidden();
            $this->delete(route('admin.procesos.destroy', $proceso))->assertForbidden();
        }
        $this->assertSame(1, ProcesoInterno::count());
        $this->assertSame('Proceso original', $proceso->fresh()->titulo);
    }

    public function test_listado_conserva_nombres_filtros_y_paginacion_sin_coleccion_global(): void
    {
        $responsable = $this->usuario('coordinador');
        $creador = $this->usuario('admin');
        for ($i = 0; $i < 13; $i++) {
            ProcesoInterno::create($this->datos([
                'titulo' => 'Revision ficticia '.$i, 'responsable_id' => $responsable->id, 'created_by' => $creador->id,
            ]));
        }
        ProcesoInterno::create($this->datos(['titulo' => 'Excluido', 'estado' => 'cancelado']));
        $respuesta = $this->get(route('admin.procesos.index', ['estado' => 'pendiente', 'busqueda' => 'revision']))
            ->assertOk()->assertSee($responsable->name)->assertSee($creador->name)->assertDontSee('Excluido');
        $this->assertArrayNotHasKey('responsables', $respuesta->original->getData());
        $paginador = $respuesta->viewData('procesos');
        $this->assertSame(13, $paginador->total());
        $this->assertCount(12, $paginador->items());
        foreach ($paginador->items() as $proceso) {
            $this->assertTrue($proceso->relationLoaded('responsable'));
            $this->assertTrue($proceso->relationLoaded('creador'));
        }
        $this->assertStringContainsString('busqueda=revision', $paginador->nextPageUrl());
        $this->assertStringContainsString('estado=pendiente', $paginador->nextPageUrl());
        $segunda = $this->get($paginador->nextPageUrl())->assertOk()->viewData('procesos');
        $this->assertCount(1, $segunda->items());
    }

    private function usuario(?string $rol = null): User
    {
        $usuario = User::factory()->create();
        if ($rol !== null) {
            $usuario->assignRole($rol);
        }

        return $usuario;
    }

    private function candidato(string $caso): User
    {
        $usuario = $this->usuario();
        match ($caso) {
            'admin', 'coordinador', 'secretaria' => $usuario->assignRole($caso),
            'directo' => $usuario->givePermissionTo('procesos.ver'),
            'secretaria_directo' => $usuario->assignRole('secretaria')->givePermissionTo('procesos.ver'),
            'multiple' => $usuario->assignRole(['coordinador', 'secretaria']),
            'sin_permiso', 'coordinador_directo', 'heredado_multiple' => $usuario->assignRole('coordinador'),
            default => null,
        };
        if (in_array($caso, ['sin_permiso', 'coordinador_directo', 'heredado_multiple'], true)) {
            Role::findByName('coordinador')->revokePermissionTo('procesos.ver');
        }
        if ($caso === 'coordinador_directo') {
            $usuario->givePermissionTo('procesos.ver');
        }
        if ($caso === 'heredado_multiple') {
            Role::findByName('secretaria')->givePermissionTo('procesos.ver');
            $usuario->assignRole('secretaria');
        }

        return $usuario;
    }

    /** @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        return array_replace([
            'titulo' => 'Proceso nuevo ficticio', 'responsable_id' => null,
            'estado' => 'pendiente', 'prioridad' => 'media',
        ], $cambios);
    }

    private function proceso(?User $responsable = null): ProcesoInterno
    {
        return ProcesoInterno::create($this->datos(['titulo' => 'Proceso original', 'responsable_id' => $responsable?->id]));
    }
}
