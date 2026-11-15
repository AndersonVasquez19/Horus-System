<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\Cliente;
use App\Models\Oportunidad;
use App\Models\Prospecto;
use App\Models\Proyecto;
use App\Models\TicketSoporte;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Ola 3, bloque de Servicios (clientes, prospectos, pipeline, proyectos, soporte y reportes comerciales) sobre
 * PostgreSQL real: los `CHECK` que solo existen en este motor, las claves foráneas sin cascada, las unicidades, el
 * contador de códigos y las carreras con bloqueo de fila. Datos ficticios; solo la base desechable del perfil aislado
 * (127.0.0.1:55433, horus_l2_test). Usan DatabaseTruncation (con RefreshDatabase la segunda sesión no vería los datos).
 *
 * Protocolo de carrera (igual que PanelConcurrenciaPostgresTest): la sesión B toma el bloqueo; la petición real, con
 * lock_timeout corto, debe quedar BLOQUEADA (55P03); al confirmar B se repite y decide con el estado ya confirmado.
 */
class CrmPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private const TOKEN = 'token-de-servicio-del-bff-para-pruebas-0123456789';

    private const CHECK_VIOLATION = '23514';

    /** Borrar un padre con hijos bajo `ON DELETE RESTRICT`: PostgreSQL responde `restrict_violation` (23001). */
    private const RESTRICT_VIOLATION = '23001';

    private const UNIQUE_VIOLATION = '23505';

    private const LOCK_NOT_AVAILABLE = '55P03';

    // Utilidades ---------------------------------------------------------------------------------------------------

    private function admin(): User
    {
        config(['horus-web.service_token' => self::TOKEN]);

        return $this->adminUser();
    }

    /** @param array<string, mixed> $datos */
    private function como(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/servicios/'.$ruta, $datos);
    }

    private function assertViolation(string $sqlstate, callable $consulta, string $mensaje = ''): void
    {
        try {
            DB::transaction($consulta);
            $this->fail('La base debía rechazar la operación. '.$mensaje);
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->getCode(), $mensaje.' '.$e->getMessage());
        }
    }

    private function assertBlocked(callable $peticion): void
    {
        $this->withoutExceptionHandling();
        try {
            $peticion();
            $this->fail('La petición debía quedar bloqueada por la transacción de la otra sesión.');
        } catch (QueryException $e) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $e->getCode(), 'Debe esperar el bloqueo (55P03), no fallar por otra causa.');
        } finally {
            $this->withExceptionHandling();
        }
    }

    private function cliente(string $estado = 'activo'): Cliente
    {
        $cliente = new Cliente(['tipo' => 'persona', 'nombre' => 'Cliente Ficticio']);
        $cliente->identificador = (string) Str::uuid();
        $cliente->estado = $estado;
        $cliente->revision = 1;
        $cliente->save();

        return $cliente;
    }

    /** @return array<string, mixed> */
    private function filaProspecto(array $cambios = []): array
    {
        return [
            'identificador' => (string) Str::uuid(), 'nombre_contacto' => 'P', 'origen' => 'web', 'estado' => 'nuevo', 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(), ...$cambios,
        ];
    }

    /** @return array<string, mixed> */
    private function filaOportunidad(int $cliente, array $cambios = []): array
    {
        return [
            'identificador' => (string) Str::uuid(), 'cliente_id' => $cliente, 'titulo' => 'O', 'etapa' => 'nueva', 'monto_estimado' => 10,
            'probabilidad' => 10, 'revision' => 1, 'created_at' => now(), 'updated_at' => now(), ...$cambios,
        ];
    }

    /** @return array<string, mixed> */
    private function filaProyecto(int $cliente, string $codigo, array $cambios = []): array
    {
        return [
            'identificador' => (string) Str::uuid(), 'codigo' => $codigo, 'cliente_id' => $cliente, 'nombre' => 'Y', 'estado' => 'planificado',
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(), ...$cambios,
        ];
    }

    /** @return array<string, mixed> */
    private function filaTicket(int $cliente, string $codigo, array $cambios = []): array
    {
        return [
            'identificador' => (string) Str::uuid(), 'codigo' => $codigo, 'cliente_id' => $cliente, 'asunto' => 'T', 'prioridad' => 'media',
            'estado' => 'abierto', 'canal' => 'web', 'vence_at' => now()->addDay(), 'revision' => 1, 'created_at' => now(), 'updated_at' => now(), ...$cambios,
        ];
    }

    // CHECK solo de PostgreSQL ---------------------------------------------------------------------------------------

    public function test_los_check_de_clientes_y_prospectos(): void
    {
        $cliente = $this->cliente();

        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('clientes')->insert([
            'identificador' => (string) Str::uuid(), 'tipo' => 'robot', 'nombre' => 'x', 'estado' => 'activo', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]), 'clientes.tipo');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('clientes')->where('id', $cliente->id)->update(['estado' => 'archivado']), 'clientes.estado');

        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('prospectos')->insert($this->filaProspecto(['origen' => 'inventado'])), 'origen');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('prospectos')->insert($this->filaProspecto(['estado' => 'perdido'])), 'estado');
        // El motivo existe si y solo si el prospecto está descartado.
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('prospectos')->insert($this->filaProspecto(['estado' => 'descartado'])), 'descartado sin motivo');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('prospectos')->insert($this->filaProspecto(['motivo_descarte' => 'x'])), 'motivo sin descartar');
        // Un convertido tiene cliente.
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('prospectos')->insert($this->filaProspecto(['estado' => 'convertido'])), 'convertido sin cliente');

        DB::table('prospectos')->insert($this->filaProspecto(['estado' => 'descartado', 'motivo_descarte' => 'No responde']));
        DB::table('prospectos')->insert($this->filaProspecto(['estado' => 'convertido', 'cliente_id' => $cliente->id]));
        $this->assertSame(2, DB::table('prospectos')->count());
    }

    public function test_los_check_de_oportunidades(): void
    {
        $c = $this->cliente()->id;

        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('oportunidades')->insert($this->filaOportunidad($c, ['etapa' => 'soñada'])), 'etapa');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('oportunidades')->insert($this->filaOportunidad($c, ['probabilidad' => 101])), 'probabilidad');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('oportunidades')->insert($this->filaOportunidad($c, ['monto_estimado' => -1])), 'monto');
        // Perdida si y solo si hay motivo; cerrada si y solo si hay fecha de cierre.
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('oportunidades')->insert($this->filaOportunidad($c, ['etapa' => 'perdida', 'cerrada_at' => now()])), 'perdida sin motivo');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('oportunidades')->insert($this->filaOportunidad($c, ['motivo_perdida' => 'x'])), 'motivo sin perder');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('oportunidades')->insert($this->filaOportunidad($c, ['etapa' => 'ganada'])), 'ganada sin cierre');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('oportunidades')->insert($this->filaOportunidad($c, ['cerrada_at' => now()])), 'cierre sin cerrar');

        DB::table('oportunidades')->insert($this->filaOportunidad($c, ['etapa' => 'perdida', 'motivo_perdida' => 'Precio', 'cerrada_at' => now(), 'probabilidad' => 0]));
        DB::table('oportunidades')->insert($this->filaOportunidad($c, ['etapa' => 'ganada', 'cerrada_at' => now(), 'probabilidad' => 100]));
        $id = DB::table('oportunidades')->insertGetId($this->filaOportunidad($c));
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('oportunidad_historial')->insert(['oportunidad_id' => $id, 'etapa_nueva' => 'nada', 'created_at' => now()]), 'historial');
    }

    public function test_los_check_de_proyectos(): void
    {
        $c = $this->cliente()->id;

        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('proyectos')->insert($this->filaProyecto($c, 'PRY-2026-0001', ['estado' => 'archivado'])), 'estado');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('proyectos')->insert($this->filaProyecto($c, 'PRY-2026-0001', ['presupuesto' => -5])), 'presupuesto');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('proyectos')->insert($this->filaProyecto($c, 'PRY-2026-0001', ['fecha_inicio' => '2026-12-10', 'fecha_fin_estimada' => '2026-12-01'])), 'fechas');
        // La fecha de fin real existe si y solo si está completado.
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('proyectos')->insert($this->filaProyecto($c, 'PRY-2026-0001', ['estado' => 'completado'])), 'completado sin fin real');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('proyectos')->insert($this->filaProyecto($c, 'PRY-2026-0001', ['fecha_fin_real' => '2026-12-01'])), 'fin real sin completar');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('secuencias_codigo')->insert(['tipo' => 'PRY', 'anio' => 2026, 'ultimo' => -1]), 'secuencia');

        DB::table('proyectos')->insert($this->filaProyecto($c, 'PRY-2026-0001', ['estado' => 'completado', 'fecha_fin_real' => '2026-12-01']));
        $this->assertSame(1, DB::table('proyectos')->count());
    }

    public function test_los_check_de_tickets(): void
    {
        $c = $this->cliente()->id;

        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('tickets_soporte')->insert($this->filaTicket($c, 'SOP-2026-0001', ['estado' => 'archivado'])), 'estado');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('tickets_soporte')->insert($this->filaTicket($c, 'SOP-2026-0001', ['prioridad' => 'urgente'])), 'prioridad');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('tickets_soporte')->insert($this->filaTicket($c, 'SOP-2026-0001', ['canal' => 'paloma'])), 'canal');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('tickets_soporte')->insert($this->filaTicket($c, 'SOP-2026-0001', ['estado' => 'resuelto'])), 'resuelto sin fecha');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('tickets_soporte')->insert($this->filaTicket($c, 'SOP-2026-0001', ['estado' => 'cerrado'])), 'cerrado sin fecha');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('tickets_soporte')->insert($this->filaTicket($c, 'SOP-2026-0001', ['cerrado_at' => now()])), 'cierre sin cerrar');

        DB::table('tickets_soporte')->insert($this->filaTicket($c, 'SOP-2026-0001', ['estado' => 'cerrado', 'resuelto_at' => now(), 'cerrado_at' => now()]));
        $this->assertSame(1, DB::table('tickets_soporte')->count());
    }

    // Claves foráneas y unicidades --------------------------------------------------------------------------------------

    public function test_ningun_cliente_con_historial_se_puede_eliminar_ni_se_borra_en_cascada(): void
    {
        $cliente = $this->cliente();
        $c = $cliente->id;
        $oportunidad = DB::table('oportunidades')->insertGetId($this->filaOportunidad($c));
        DB::table('oportunidad_historial')->insert(['oportunidad_id' => $oportunidad, 'etapa_nueva' => 'nueva', 'created_at' => now()]);
        $proyecto = DB::table('proyectos')->insertGetId($this->filaProyecto($c, 'PRY-2026-0001'));
        DB::table('proyecto_hitos')->insert(['identificador' => (string) Str::uuid(), 'proyecto_id' => $proyecto, 'nombre' => 'H', 'orden' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $ticket = DB::table('tickets_soporte')->insertGetId($this->filaTicket($c, 'SOP-2026-0001'));
        DB::table('ticket_notas')->insert(['ticket_id' => $ticket, 'interna' => true, 'texto' => 'x', 'created_at' => now()]);
        DB::table('prospectos')->insert($this->filaProspecto(['estado' => 'convertido', 'cliente_id' => $c]));

        foreach (['oportunidad_historial' => 'oportunidades', 'proyecto_hitos' => 'proyectos', 'ticket_notas' => 'tickets_soporte'] as $hijo => $padre) {
            $this->assertViolation(self::RESTRICT_VIOLATION, fn () => DB::table($padre)->delete(), "{$padre} con {$hijo}");
        }
        $this->assertViolation(self::RESTRICT_VIOLATION, fn () => DB::table('clientes')->where('id', $c)->delete(), 'cliente con historial');
        $this->assertSame(1, DB::table('clientes')->count());
    }

    public function test_unicidades_de_vinculos_y_codigos(): void
    {
        $c = $this->cliente()->id;
        $solicitud = DB::table('solicitudes_cotizacion')->insertGetId([
            'identificador' => (string) Str::uuid(), 'codigo' => 'COT-PG-1', 'recibida_at' => now(), 'necesidad_cliente' => 'x', 'contacto_nombre' => 'x',
            'contacto_email' => 'x@example.test', 'estado' => 'recibida', 'revision' => 1, 'origen' => 'administrativo', 'creador_ambito' => 1,
            'creacion_clave_hash' => hash('sha256', 'a'), 'creacion_huella' => hash('sha256', 'b'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Una solicitud de cotización da un solo prospecto; los sin solicitud conviven.
        DB::table('prospectos')->insert($this->filaProspecto(['solicitud_cotizacion_id' => $solicitud]));
        DB::table('prospectos')->insert($this->filaProspecto());
        DB::table('prospectos')->insert($this->filaProspecto());
        $this->assertViolation(self::UNIQUE_VIOLATION, fn () => DB::table('prospectos')->insert($this->filaProspecto(['solicitud_cotizacion_id' => $solicitud])), 'prospecto-solicitud');

        // Una oportunidad da un solo proyecto; el código es único.
        $oportunidad = DB::table('oportunidades')->insertGetId($this->filaOportunidad($c, ['etapa' => 'ganada', 'cerrada_at' => now(), 'probabilidad' => 100]));
        DB::table('proyectos')->insert($this->filaProyecto($c, 'PRY-2026-0001', ['oportunidad_id' => $oportunidad]));
        $this->assertViolation(self::UNIQUE_VIOLATION, fn () => DB::table('proyectos')->insert($this->filaProyecto($c, 'PRY-2026-0002', ['oportunidad_id' => $oportunidad])), 'proyecto-oportunidad');
        $this->assertViolation(self::UNIQUE_VIOLATION, fn () => DB::table('proyectos')->insert($this->filaProyecto($c, 'PRY-2026-0001')), 'código de proyecto');
        DB::table('tickets_soporte')->insert($this->filaTicket($c, 'SOP-2026-0001'));
        $this->assertViolation(self::UNIQUE_VIOLATION, fn () => DB::table('tickets_soporte')->insert($this->filaTicket($c, 'SOP-2026-0001')), 'código de ticket');
        DB::table('secuencias_codigo')->insert(['tipo' => 'PRY', 'anio' => 2030, 'ultimo' => 1]);
        $this->assertViolation(self::UNIQUE_VIOLATION, fn () => DB::table('secuencias_codigo')->insert(['tipo' => 'PRY', 'anio' => 2030, 'ultimo' => 2]), 'secuencia');
    }

    // Carreras con bloqueo de fila -------------------------------------------------------------------------------------

    public function test_el_contador_de_codigos_serializa_dos_altas_de_proyecto(): void
    {
        $admin = $this->admin();
        $cliente = $this->cliente();
        $b = $this->secondSession();
        $anio = (int) now('America/Lima')->format('Y');
        DB::table('secuencias_codigo')->insert(['tipo' => 'PRY', 'anio' => $anio, 'ultimo' => 0]);

        // B: otra petición que ya tomó el contador y está por usar el número 1.
        $b->beginTransaction();
        $b->select("select id from secuencias_codigo where tipo = 'PRY' and anio = ? for update", [$anio]);
        $b->update("update secuencias_codigo set ultimo = ultimo + 1 where tipo = 'PRY' and anio = ?", [$anio]);

        DB::statement("SET lock_timeout = '2s'");
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json('POST', '/api/panel/v1/servicios/proyectos', ['cliente' => $cliente->identificador, 'nombre' => 'Segundo']));
        $this->assertSame(0, Proyecto::query()->count(), 'El alta bloqueada no deja rastro.');

        $b->commit();

        $codigo = $this->como($admin, 'POST', 'proyectos', ['cliente' => $cliente->identificador, 'nombre' => 'Segundo'])->assertCreated()->json('data.codigo');
        $this->assertSame(sprintf('PRY-%d-0002', $anio), $codigo, 'Tras confirmar B, el siguiente número es el 2: nunca se repite el 1.');
        $this->assertSame(2, (int) DB::table('secuencias_codigo')->where('tipo', 'PRY')->where('anio', $anio)->value('ultimo'));
    }

    public function test_la_conversion_de_un_prospecto_espera_el_bloqueo_y_luego_decide_con_el_estado_confirmado(): void
    {
        $admin = $this->admin();
        $creado = $this->como($admin, 'POST', 'prospectos', ['nombre_contacto' => 'Lucía Ficticia', 'email' => 'lucia@example.test', 'origen' => 'referido'])->assertCreated();
        $uuid = $creado->json('data.identificador');
        foreach (['contactado', 'calificado'] as $paso) {
            $revision = Prospecto::query()->where('identificador', $uuid)->firstOrFail()->etag();
            $this->como($admin, 'POST', "prospectos/{$uuid}/estado", ['estado' => $paso, '_revision' => $revision])->assertOk();
        }
        $revision = Prospecto::query()->where('identificador', $uuid)->firstOrFail()->etag();
        $b = $this->secondSession();

        // B: otra petición que ya tomó la fila del prospecto y lo descarta.
        $b->beginTransaction();
        $b->select('select id from prospectos where identificador = ? for update', [$uuid]);
        $b->update("update prospectos set estado = 'descartado', motivo_descarte = 'Cambió de idea', revision = revision + 1 where identificador = ?", [$uuid]);

        DB::statement("SET lock_timeout = '2s'");
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json('POST', "/api/panel/v1/servicios/prospectos/{$uuid}/convertir", ['_revision' => $revision]));
        $this->assertSame(0, Cliente::query()->count(), 'La conversión bloqueada no crea cliente.');

        $b->commit();

        // Con la revisión que tenía antes de la carrera, ya no es válida.
        $this->como($admin, 'POST', "prospectos/{$uuid}/convertir", ['_revision' => $revision])->assertStatus(412);
        $this->assertSame(0, Cliente::query()->count());
        $this->assertSame('descartado', Prospecto::query()->where('identificador', $uuid)->firstOrFail()->estado);
    }

    public function test_el_cambio_de_etapa_espera_el_bloqueo_y_el_historial_queda_completo(): void
    {
        $admin = $this->admin();
        $cliente = $this->cliente();
        $creada = $this->como($admin, 'POST', 'oportunidades', ['cliente' => $cliente->identificador, 'titulo' => 'Cableado'])->assertCreated();
        $uuid = $creada->json('data.identificador');
        $revision = Oportunidad::query()->where('identificador', $uuid)->firstOrFail()->etag();
        $b = $this->secondSession();

        $b->beginTransaction();
        $b->select('select id from oportunidades where identificador = ? for update', [$uuid]);
        $b->update("update oportunidades set etapa = 'calificada', probabilidad = 25, revision = revision + 1 where identificador = ?", [$uuid]);

        DB::statement("SET lock_timeout = '2s'");
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json('POST', "/api/panel/v1/servicios/oportunidades/{$uuid}/etapa", ['etapa' => 'calificada', '_revision' => $revision]));

        $b->commit();

        $this->como($admin, 'POST', "oportunidades/{$uuid}/etapa", ['etapa' => 'calificada', '_revision' => $revision])->assertStatus(412);
        $this->assertSame(1, DB::table('oportunidad_historial')->count(), 'Solo el alta: el cambio bloqueado no dejó historial.');
    }

    public function test_dos_proyectos_de_la_misma_oportunidad_resultan_en_uno_solo(): void
    {
        $admin = $this->admin();
        $c = $this->cliente()->id;
        $oportunidad = new Oportunidad(['titulo' => 'Ganada', 'monto_estimado' => 100]);
        $oportunidad->identificador = (string) Str::uuid();
        $oportunidad->cliente_id = $c;
        $oportunidad->etapa = 'ganada';
        $oportunidad->probabilidad = 100;
        $oportunidad->cerrada_at = now();
        $oportunidad->revision = 1;
        $oportunidad->save();

        $primero = $this->como($admin, 'POST', 'proyectos', ['oportunidad' => $oportunidad->identificador])->assertCreated();
        $segundo = $this->como($admin, 'POST', 'proyectos', ['oportunidad' => $oportunidad->identificador])->assertOk();

        $this->assertSame($primero->json('data.identificador'), $segundo->json('data.identificador'));
        $this->assertSame(1, Proyecto::query()->count());
        $this->assertSame(1, (int) DB::table('secuencias_codigo')->where('tipo', 'PRY')->value('ultimo'), 'La repetición no consume otro número.');
    }

    // Consultas del reporte en PostgreSQL ----------------------------------------------------------------------------------

    public function test_el_reporte_comercial_se_ejecuta_en_postgresql_con_agregados_fechas_y_filtros(): void
    {
        $admin = $this->admin();
        $c = $this->cliente()->id;
        $hace = now()->subDays(2);

        DB::table('prospectos')->insert($this->filaProspecto(['created_at' => $hace]));
        DB::table('prospectos')->insert($this->filaProspecto(['estado' => 'convertido', 'cliente_id' => $c, 'created_at' => $hace]));
        DB::table('oportunidades')->insert($this->filaOportunidad($c, ['monto_estimado' => 1000, 'probabilidad' => 50]));
        DB::table('oportunidades')->insert($this->filaOportunidad($c, ['etapa' => 'ganada', 'monto_estimado' => 400.25, 'probabilidad' => 100, 'cerrada_at' => $hace]));
        DB::table('oportunidades')->insert($this->filaOportunidad($c, ['etapa' => 'perdida', 'motivo_perdida' => 'x', 'probabilidad' => 0, 'cerrada_at' => $hace]));
        DB::table('tickets_soporte')->insert($this->filaTicket($c, 'SOP-2026-0001', ['created_at' => $hace->copy()->subDay(), 'estado' => 'resuelto', 'resuelto_at' => $hace->copy()->addHours(5), 'vence_at' => $hace]));
        DB::table('tickets_soporte')->insert($this->filaTicket($c, 'SOP-2026-0002', ['vence_at' => now()->subHour(), 'created_at' => $hace]));
        $proyecto = DB::table('proyectos')->insertGetId($this->filaProyecto($c, 'PRY-2026-0001', ['estado' => 'en_curso']));
        DB::table('proyecto_hitos')->insert(['identificador' => (string) Str::uuid(), 'proyecto_id' => $proyecto, 'nombre' => 'H', 'orden' => 1, 'fecha_objetivo' => now('America/Lima')->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $reporte = $this->como($admin, 'GET', 'reportes-comerciales')->assertOk();
        $reporte->assertJsonPath('data.bloques.prospectos.creados', 2)
            ->assertJsonPath('data.bloques.prospectos.tasa_conversion', 50)
            ->assertJsonPath('data.bloques.oportunidades.abiertas.monto_ponderado', '500.00')
            ->assertJsonPath('data.bloques.oportunidades.ganadas.monto', '400.25')
            ->assertJsonPath('data.bloques.oportunidades.tasa_exito', 50)
            ->assertJsonPath('data.bloques.soporte.vencidos', 1)
            ->assertJsonPath('data.bloques.soporte.resueltos', 1)
            ->assertJsonPath('data.bloques.proyectos.hitos_vencidos', 1);
        $this->assertEqualsWithDelta(29.0, (float) $reporte->json('data.bloques.soporte.tiempo_medio_resolucion_horas'), 0.2);

        // La exportación sale con las mismas consultas y deja constancia.
        $this->como($admin, 'POST', 'reportes-comerciales/exportar')->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'reporte_comercial_exportado')->count());

        // Un ticket asignado a otra persona queda fuera del filtro por responsable.
        $this->como($admin, 'GET', 'reportes-comerciales?responsable_id='.$admin->id)->assertOk()->assertJsonPath('data.bloques.soporte.creados', 0);
        $this->assertSame(2, TicketSoporte::query()->count());
    }
}
