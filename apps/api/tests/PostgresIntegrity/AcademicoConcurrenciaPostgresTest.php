<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Services\GestionPagos;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Horus Academic sobre PostgreSQL REAL: carreras entre dos sesiones (pagos, reversos, matrícula con pago).
 *
 * Protocolo (el mismo que MatriculaOperacionesConcurrencia…): la sesión B abre una transacción y toma los bloqueos que tomaría la otra
 * petición, dejando sus escrituras SIN confirmar; la petición real, con `lock_timeout` corto, debe quedar BLOQUEADA (55P03) y no dejar
 * rastro; al confirmar B, la petición se repite y debe decidir con el estado ya confirmado (sin duplicar ni sobrepasar nada).
 *
 * B toma `FOR NO KEY UPDATE` (no `FOR UPDATE`): choca con el `lockForUpdate` del servicio pero NO con la comprobación de clave foránea de
 * un INSERT. Comprobado por mutación: quitar el `lockForUpdate` de la obligación HACE FALLAR las pruebas de
 * sobrepago (es el bloqueo el que protege). En la idempotencia la barrera de fondo es el índice
 * único (clave por obligación): sin el bloqueo la petición igual espera en el índice y, al confirmar B,
 * fallaría con un duplicado (23505) en vez de devolver lo ya creado; esa prueba verifica la barrera del índice y la respuesta limpia.
 *
 * Usan DatabaseTruncation (con RefreshDatabase la segunda sesión no vería los datos). Datos ficticios; solo el clúster aislado.
 */
class AcademicoConcurrenciaPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private const TOKEN = 'token-de-servicio-del-bff-para-pruebas-0123456789';

    private const BLOQUEADO = '55P03';

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['horus-web.service_token' => self::TOKEN]);
        Storage::fake('local');
        // DatabaseTruncation vacía hasta lo que siembran las migraciones.
        DB::table('metodos_pago')->insertOrIgnore(['codigo' => 'yape', 'nombre' => 'Yape', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---------------------------------------------------------------- pagos

    public function test_dos_pagos_que_juntos_exceden_el_saldo_no_pasan_ambos(): void
    {
        $e = $this->escenarioPago();
        $admin = $this->adminUser();
        $b = $this->secondSession();

        // B: otra petición ya tomó la obligación y dejó un pago pendiente de 200 de los 300.
        $b->beginTransaction();
        $b->select('select id from obligaciones_pago where id = ? for no key update', [$e['obligacion']]);
        $this->transaccion($b, $e['obligacion'], 'pago', 'pendiente', '200.00', ['clave_idempotencia' => 'clave-de-b-0001']);

        $this->limitLockWait();
        $this->assertBloqueado(fn () => $this->pago($admin, $e['uuid'], '200.00', 'clave-de-a-0001'));
        $this->assertSame(0, DB::table('transacciones_pago')->count(), 'El intento bloqueado no deja ningún pago.');
        $this->assertSame(0, AuditLog::where('action', 'pago_registrado')->count());

        $b->commit();

        // Con el pago de B ya confirmado en la base, quedan 100 de saldo: 200 se rechaza.
        $this->withExceptionHandling();
        $this->pago($admin, $e['uuid'], '200.00', 'clave-de-a-0001')->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->pago($admin, $e['uuid'], '100.00', 'clave-de-a-0002')->assertCreated();
        $this->assertSame(2, DB::table('transacciones_pago')->count());
        $this->assertSame('300.00', number_format((float) DB::table('transacciones_pago')->sum('monto'), 2, '.', ''));
    }

    public function test_la_misma_idempotency_key_en_carrera_devuelve_el_pago_original_sin_duplicar(): void
    {
        $e = $this->escenarioPago();
        $admin = $this->adminUser();
        $b = $this->secondSession();
        $fecha = now()->toDateString();

        $b->beginTransaction();
        $b->select('select id from obligaciones_pago where id = ? for no key update', [$e['obligacion']]);
        $this->transaccion($b, $e['obligacion'], 'pago', 'pendiente', '100.00', [
            'clave_idempotencia' => 'clave-carrera-001', 'referencia' => 'OP-1', 'fecha_pago' => $fecha,
            'huella' => GestionPagos::huella('yape', 10000, $fecha, 'OP-1', false),
        ]);

        $this->limitLockWait();
        $this->assertBloqueado(fn () => $this->pago($this->adminUser(), $e['uuid'], '100.00', 'clave-carrera-001', ['referencia' => 'OP-1']));
        $b->commit();

        $this->withExceptionHandling();
        $this->pago($admin, $e['uuid'], '100.00', 'clave-carrera-001', ['referencia' => 'OP-1'])->assertOk()->assertJsonPath('message', 'Este pago ya estaba registrado.');
        $this->assertSame(1, DB::table('transacciones_pago')->count(), 'Misma clave y mismos datos: un solo pago.');
        // Con otros datos, la misma clave es un conflicto.
        $this->pago($admin, $e['uuid'], '90.00', 'clave-carrera-001', ['referencia' => 'OP-1'])->assertStatus(409);
    }

    public function test_dos_confirmaciones_del_mismo_pago_aplican_las_asignaciones_una_sola_vez(): void
    {
        $e = $this->escenarioPago();
        $admin = $this->adminUser();
        $b = $this->secondSession();
        $t = $this->transaccion(DB::connection(), $e['obligacion'], 'pago', 'pendiente', '100.00');
        DB::table('comprobantes_pago')->insert(['transaccion_pago_id' => $t, 'ruta' => 'comprobantes/'.Str::uuid().'.png', 'mime' => 'image/png', 'tamano' => 10, 'sha256' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        $uuid = (string) DB::table('transacciones_pago')->where('id', $t)->value('identificador');

        // B: la otra confirmación ya reparte el importe y marca el pago como confirmado (sin confirmar la transacción).
        $b->beginTransaction();
        $b->select('select id from obligaciones_pago where id = ? for no key update', [$e['obligacion']]);
        $b->select('select id from transacciones_pago where id = ? for no key update', [$t]);
        $b->update('update transacciones_pago set estado = ?, resuelto_en = ? where id = ?', ['confirmada', now(), $t]);
        $b->table('asignaciones_pago')->insert(['transaccion_pago_id' => $t, 'cuota_obligacion_id' => $e['cuota'], 'monto' => '100.00', 'created_at' => now(), 'updated_at' => now()]);

        $this->limitLockWait();
        $this->assertBloqueado(fn () => $this->como($admin, 'POST', "academico/pagos/transacciones/{$uuid}/confirmar"));
        $this->assertSame(0, DB::table('asignaciones_pago')->count(), 'Lo no confirmado de B no es visible.');
        $b->commit();

        $this->withExceptionHandling();
        $this->como($admin, 'POST', "academico/pagos/transacciones/{$uuid}/confirmar")->assertOk();
        $this->assertSame(1, DB::table('asignaciones_pago')->count(), 'El reparto no se duplica.');
        $this->assertSame('100.00', number_format((float) DB::table('asignaciones_pago')->sum('monto'), 2, '.', ''));
        $this->assertSame(0, AuditLog::where('action', 'pago_confirmado')->count(), 'La segunda confirmación no audita otra vez.');
    }

    public function test_dos_reversos_del_mismo_pago_dejan_un_solo_reverso(): void
    {
        $e = $this->escenarioPago();
        $admin = $this->adminUser();
        $b = $this->secondSession();
        $t = $this->transaccion(DB::connection(), $e['obligacion'], 'pago', 'confirmada', '100.00');
        DB::table('asignaciones_pago')->insert(['transaccion_pago_id' => $t, 'cuota_obligacion_id' => $e['cuota'], 'monto' => '100.00', 'created_at' => now(), 'updated_at' => now()]);
        $uuid = (string) DB::table('transacciones_pago')->where('id', $t)->value('identificador');

        $b->beginTransaction();
        $b->select('select id from obligaciones_pago where id = ? for no key update', [$e['obligacion']]);
        $b->select('select id from transacciones_pago where id = ? for no key update', [$t]);
        $this->transaccion($b, $e['obligacion'], 'reverso', 'confirmada', '100.00', ['reversa_de_id' => $t]);
        $b->table('asignaciones_pago')->insert(['transaccion_pago_id' => $t, 'cuota_obligacion_id' => $e['cuota'], 'monto' => '-100.00', 'created_at' => now(), 'updated_at' => now()]);

        $this->limitLockWait();
        $this->assertBloqueado(fn () => $this->como($admin, 'POST', "academico/pagos/transacciones/{$uuid}/reversar", ['motivo' => 'Yape devuelto al cliente']));
        $this->assertSame(0, DB::table('transacciones_pago')->where('tipo', 'reverso')->count());
        $b->commit();

        $this->withExceptionHandling();
        $this->como($admin, 'POST', "academico/pagos/transacciones/{$uuid}/reversar", ['motivo' => 'Yape devuelto al cliente'])->assertOk();
        $this->assertSame(1, DB::table('transacciones_pago')->where('tipo', 'reverso')->count(), 'Un pago tiene a lo sumo un reverso.');
        $this->assertSame(0, AuditLog::where('action', 'pago_reversado')->count(), 'El segundo intento no audita otro reverso.');
    }

    // ---------------------------------------------------------------- matricular con pago

    public function test_matricular_con_pago_bloqueado_no_deja_matricula_pago_ni_archivo_y_luego_se_completa(): void
    {
        $admin = $this->adminUser();
        $b = $this->secondSession();
        $c = $this->edicionAbierta();
        $estudiante = Estudiante::create(['dni' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'estado' => 'activo']);

        // B: otra matrícula en curso ya tiene tomado el bloqueo del curso.
        $b->beginTransaction();
        $b->select('select id from cursos where id = ? for update', [$c['curso']]);

        $this->limitLockWait();
        $this->assertBloqueado(fn () => $this->conPago($admin, $c['uuid'], $estudiante->id));
        $this->assertSame(0, Matricula::query()->count());
        $this->assertSame(0, DB::table('obligaciones_pago')->count());
        $this->assertSame(0, DB::table('transacciones_pago')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('comprobantes'), 'La captura guardada antes se borra al fallar.');
        $this->assertSame(20, Convocatoria::query()->whereKey($c['id'])->value('cupos_disponibles'));
        $b->commit();

        $this->withExceptionHandling();
        $this->conPago($admin, $c['uuid'], $estudiante->id)->assertCreated()->assertJsonPath('data.estado', 'pagada');
        $this->assertSame(1, Matricula::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('comprobantes'));
        $this->assertSame(19, Convocatoria::query()->whereKey($c['id'])->value('cupos_disponibles'));
        // Repetir la misma intención no duplica nada.
        $this->conPago($admin, $c['uuid'], $estudiante->id)->assertOk();
        $this->assertSame(1, Matricula::query()->count());
    }

    // ---------------------------------------------------------------- ayudas

    private function assertBloqueado(callable $peticion): void
    {
        $this->withoutExceptionHandling();
        try {
            $peticion();
            $this->fail('La petición debía quedar bloqueada por la transacción de la otra sesión.');
        } catch (QueryException $e) {
            $this->assertSame(self::BLOQUEADO, $e->getCode(), 'Debe esperar el bloqueo (55P03), no fallar por otra causa: '.$e->getMessage());
        } finally {
            $this->withExceptionHandling();
        }
    }

    /** @param array<string, mixed> $datos @param array<string, string> $cabeceras */
    private function como(User $user, string $metodo, string $ruta, array $datos = [], array $cabeceras = []): TestResponse
    {
        return $this->actingAs($user)->withHeaders(['X-Horus-Web-Token' => self::TOKEN] + $cabeceras)->json($metodo, '/api/panel/v1/'.$ruta, $datos);
    }

    /** @param array<string, mixed> $extra */
    private function pago(User $user, string $obligacion, string $monto, string $clave, array $extra = []): TestResponse
    {
        return $this->como($user, 'POST', "academico/pagos/obligaciones/{$obligacion}/pagos",
            ['metodo' => 'yape', 'monto' => $monto, 'fecha_pago' => now()->toDateString()] + $extra, ['Idempotency-Key' => $clave]);
    }

    private function conPago(User $user, string $edicion, int $estudiante): TestResponse
    {
        return $this->actingAs($user)->post('/api/panel/v1/academico/matriculas/con-pago', [
            'estudiante_id' => $estudiante, 'convocatoria' => $edicion, 'fecha_pago' => now()->toDateString(), 'referencia' => 'OP-C-1',
            'imagen' => UploadedFile::fake()->image('yape.png', 300, 500),
        ], ['X-Horus-Web-Token' => self::TOKEN, 'Accept' => 'application/json', 'Idempotency-Key' => 'clave-con-pago-0001']);
    }

    /** @return array{id: int, uuid: string, curso: int} edición abierta de un curso de S/ 250, con 20 cupos */
    private function edicionAbierta(): array
    {
        $this->n++;
        $curso = Curso::create(['codigo' => 'CCP-'.$this->n, 'nombre' => 'Curso '.$this->n, 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 10,
            'precio' => 250, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 50, 'cupos_disponibles' => 50, 'estado' => 'activo']);
        $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->addDay()->toDateString(), 'fecha_fin' => now()->addDays(40)->toDateString(), 'cupos_maximos' => 20]);
        $c->identificador = (string) Str::uuid();
        $c->codigo = 'CNV-2026-6'.str_pad((string) $this->n, 3, '0', STR_PAD_LEFT);
        $c->curso_id = $curso->id;
        $c->estado = 'abierta';
        $c->cupos_disponibles = 20;
        $c->revision = 1;
        $c->save();

        return ['id' => $c->id, 'uuid' => $c->identificador, 'curso' => $curso->id];
    }

    /** @return array{obligacion: int, uuid: string, cuota: int} matrícula con una obligación vigente de S/ 300 en una sola cuota */
    private function escenarioPago(): array
    {
        $c = $this->edicionAbierta();
        $e = Estudiante::create(['dni' => (string) (72000000 + $this->n), 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'estado' => 'activo']);
        $m = Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $c['curso'], 'convocatoria_id' => $c['id'], 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);
        $uuid = (string) Str::uuid();
        $obligacion = DB::table('obligaciones_pago')->insertGetId(['identificador' => $uuid, 'matricula_id' => $m->id, 'concepto' => 'Pago de prueba', 'plan' => 'completo',
            'monto_total' => '300.00', 'moneda' => 'PEN', 'estado' => 'vigente', 'created_at' => now(), 'updated_at' => now()]);
        $cuota = DB::table('cuotas_obligacion')->insertGetId(['obligacion_pago_id' => $obligacion, 'numero' => 1, 'monto' => '300.00', 'vence' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        return ['obligacion' => $obligacion, 'uuid' => $uuid, 'cuota' => $cuota];
    }

    /** @param array<string, mixed> $extra */
    private function transaccion(Connection $con, int $obligacion, string $tipo, string $estado, string $monto, array $extra = []): int
    {
        return (int) $con->table('transacciones_pago')->insertGetId($extra + ['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $obligacion,
            'metodo_pago_id' => (int) DB::table('metodos_pago')->where('codigo', 'yape')->value('id'), 'tipo' => $tipo, 'estado' => $estado, 'monto' => $monto,
            'fecha_pago' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }

}
