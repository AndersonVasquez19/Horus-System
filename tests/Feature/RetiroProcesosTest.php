<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificacionInterna;
use App\Models\ProcesoInterno;
use App\Models\User;
use Database\Seeders\ProcesosInternosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RetiroProcesosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
    }

    /** @return iterable<string, array{string, string}> */
    public static function rutasRetiradas(): iterable
    {
        yield 'listado' => ['GET', '/admin/procesos'];
        yield 'formulario de alta' => ['GET', '/admin/procesos/create'];
        yield 'alta' => ['POST', '/admin/procesos'];
        yield 'detalle' => ['GET', '/admin/procesos/1'];
        yield 'formulario de edición' => ['GET', '/admin/procesos/1/edit'];
        yield 'edición PUT' => ['PUT', '/admin/procesos/1'];
        yield 'edición PATCH' => ['PATCH', '/admin/procesos/1'];
        yield 'eliminación' => ['DELETE', '/admin/procesos/1'];
    }

    #[DataProvider('rutasRetiradas')]
    public function test_los_accesos_antiguos_no_permiten_consultar_ni_modificar_el_historico(string $metodo, string $ruta): void
    {
        $this->adminUser();
        $proceso = $this->historico();
        $antes = $proceso->fresh()->getAttributes();
        foreach (['procesos.ver', 'procesos.gestionar'] as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        $this->json($metodo, $ruta)->assertNotFound();
        foreach (['admin', 'coordinador', 'secretaria'] as $rol) {
            $usuario = User::factory()->create();
            $usuario->assignRole($rol)->givePermissionTo(['procesos.ver', 'procesos.gestionar']);
            $this->actingAs($usuario)->json($metodo, $ruta)->assertNotFound();
        }

        $this->assertDatabaseCount('procesos_internos', 1);
        $this->assertSame($antes, $proceso->fresh()->getAttributes());
    }

    /** @return iterable<string, array{string}> */
    public static function rolesDelPanel(): iterable
    {
        foreach (['admin', 'coordinador', 'secretaria'] as $rol) {
            yield $rol => [$rol];
        }
    }

    #[DataProvider('rolesDelPanel')]
    public function test_el_inicio_y_el_menu_funcionan_sin_consultar_procesos(string $rol): void
    {
        $this->adminUser();
        $usuario = User::factory()->create()->assignRole($rol);
        $proceso = $this->historico();
        $this->actingAs($usuario);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $respuesta = $this->get(route('admin.dashboard'));
            $consultas = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $respuesta->assertOk()
            ->assertDontSee('/admin/procesos', false)
            ->assertDontSee('Procesos pendientes')
            ->assertSee('Aprobaciones pendientes')
            ->assertSee('Notificaciones pendientes');
        foreach ($consultas as $consulta) {
            $this->assertStringNotContainsString('procesos_internos', $consulta['query']);
        }
        $this->assertModelExists($proceso);
    }

    public function test_los_permisos_historicos_no_se_ofrecen_en_roles_ni_se_borran_al_consultarlos(): void
    {
        $admin = $this->adminUser();
        foreach (['procesos.ver', 'procesos.gestionar'] as $permiso) {
            Permission::findOrCreate($permiso, 'web');
            $admin->givePermissionTo($permiso);
        }

        $this->actingAs($admin)->get(route('admin.roles.index'))
            ->assertOk()
            ->assertDontSee('procesos.ver')
            ->assertDontSee('procesos.gestionar')
            ->assertSee('aprobaciones.ver');

        $this->assertTrue($admin->fresh()->hasAllPermissions(['procesos.ver', 'procesos.gestionar']));
        $this->assertSame(2, Permission::whereIn('name', ['procesos.ver', 'procesos.gestionar'])->count());
    }

    /** @return iterable<string, array{string}> */
    public static function vinculosHistoricos(): iterable
    {
        yield 'listado' => ['/admin/procesos'];
        yield 'filtros' => ['/admin/procesos?estado=pendiente'];
        yield 'edición' => ['/admin/procesos/7/edit'];
        yield 'absoluto' => ['https://horus.example/admin/procesos/7?origen=aviso'];
    }

    #[DataProvider('vinculosHistoricos')]
    public function test_los_avisos_historicos_se_conservan_sin_ofrecer_enlaces_retirados(string $vinculo): void
    {
        $admin = $this->adminUser();
        $aviso = NotificacionInterna::crearPersonal($admin->id, [
            'titulo' => 'Aviso histórico ficticio', 'mensaje' => 'Seguimiento anterior.',
            'tipo' => 'info', 'leida' => false, 'vinculo' => $vinculo,
        ]);
        NotificacionInterna::crearPersonal($admin->id, [
            'titulo' => 'Aviso vigente ficticio', 'mensaje' => 'Solicitud pendiente.',
            'tipo' => 'info', 'leida' => false, 'vinculo' => '/admin/aprobaciones',
        ]);

        $this->actingAs($admin)->get(route('admin.notificaciones.index'))
            ->assertOk()
            ->assertSee('Aviso histórico ficticio')
            ->assertDontSee('href="'.$vinculo.'"', false)
            ->assertSee('href="/admin/aprobaciones"', false);

        $this->assertSame($vinculo, $aviso->fresh()->vinculo);
        $this->assertFalse($aviso->fresh()->leida);
    }

    public function test_los_datos_demo_no_generan_procesos_ni_accesos_retirados(): void
    {
        $this->adminUser();
        $proceso = $this->historico();
        User::factory()->create(['email' => 'admin@horusgroup.com']);
        User::factory()->create(['email' => 'coordinador@horusgroup.com']);

        $this->seed(ProcesosInternosSeeder::class);
        $this->seed(ProcesosInternosSeeder::class);

        $this->assertDatabaseCount('procesos_internos', 1);
        $this->assertModelExists($proceso);
        $this->assertDatabaseCount('notificaciones_internas', 3);
        $this->assertSame(0, NotificacionInterna::where('vinculo', '/admin/procesos')->count());
    }

    private function historico(): ProcesoInterno
    {
        return ProcesoInterno::create([
            'titulo' => 'Tarea histórica ficticia', 'estado' => 'pendiente', 'prioridad' => 'media',
        ]);
    }
}
