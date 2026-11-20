<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Ola 3, bloque académico (convocatorias y matrículas con convocatoria) sobre PostgreSQL real: los `CHECK` que solo
 * existen en este motor, el `RESTRICT` de las claves foráneas y la carrera por el último cupo de una convocatoria.
 * Datos ficticios; solo la base desechable del perfil aislado (127.0.0.1:55433, horus_l2_test). Usan DatabaseTruncation
 * (con RefreshDatabase la segunda sesión no vería los datos).
 *
 * Ejecutadas en PostgreSQL el 15/11/2026 con autorización expresa (ver docs/ola3-postgresql.md).
 *
 * Protocolo de carrera (igual que CrmPostgresTest): la sesión B toma el bloqueo; la petición real, con lock_timeout
 * corto, debe quedar BLOQUEADA (55P03); al confirmar B se repite y decide con el estado ya confirmado.
 */
class AcademicoCuposPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private const TOKEN = 'token-de-servicio-del-bff-para-pruebas-0123456789';

    private const CHECK_VIOLATION = '23514';

    private const RESTRICT_VIOLATION = '23001';

    private const LOCK_NOT_AVAILABLE = '55P03';

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
            ->json($metodo, '/api/panel/v1/academico/'.$ruta, $datos);
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

    private function curso(): Curso
    {
        return Curso::create([
            'codigo' => 'CUR-PG', 'nombre' => 'Curso ficticio', 'descripcion' => 'Descripción.', 'categoria' => 'tecnologia',
            'duracion_horas' => 20, 'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Lunes', 'cupos_maximos' => 10,
            'cupos_disponibles' => 10, 'estado' => 'activo',
        ]);
    }

    private function estudiante(string $dni): Estudiante
    {
        return Estudiante::create([
            'dni' => $dni, 'nombres' => 'Nombre', 'apellidos' => 'Apellido', 'email' => "{$dni}@correo.test",
            'telefono' => '999999999', 'estado' => 'activo',
        ]);
    }

    /** @return array<string, mixed> */
    private function filaConvocatoria(Curso $curso, array $cambios = []): array
    {
        return [
            'identificador' => (string) Str::uuid(), 'codigo' => 'CNV-2026-'.random_int(1000, 9999), 'curso_id' => $curso->id, 'modalidad' => 'virtual',
            'fecha_inicio' => '2026-12-01', 'fecha_fin' => '2026-12-31', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'abierta',
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(), ...$cambios,
        ];
    }

    public function test_los_check_de_convocatorias_y_horarios(): void
    {
        $curso = $this->curso();

        foreach ([
            'estado' => ['estado' => 'inventado'],
            'modalidad' => ['modalidad' => 'mixta'],
            'fechas' => ['fecha_inicio' => '2026-12-31', 'fecha_fin' => '2026-12-01'],
            'máximo cero' => ['cupos_maximos' => 0, 'cupos_disponibles' => 0],
            'disponibles sobre el máximo' => ['cupos_maximos' => 5, 'cupos_disponibles' => 6],
        ] as $caso => $cambios) {
            $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('convocatorias')->insert($this->filaConvocatoria($curso, $cambios)), $caso);
        }

        $convocatoria = DB::table('convocatorias')->insertGetId($this->filaConvocatoria($curso));
        $bloque = ['convocatoria_id' => $convocatoria, 'dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '10:00', 'created_at' => now(), 'updated_at' => now()];
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('convocatoria_horarios')->insert([...$bloque, 'dia_semana' => 8]), 'día');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('convocatoria_horarios')->insert([...$bloque, 'hora_fin' => '08:00']), 'horas');
    }

    public function test_ni_la_convocatoria_ni_el_curso_se_borran_con_hijos(): void
    {
        $curso = $this->curso();
        $estudiante = $this->estudiante('11111111');
        $convocatoria = DB::table('convocatorias')->insertGetId($this->filaConvocatoria($curso));
        DB::table('matriculas')->insert([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'convocatoria_id' => $convocatoria, 'fecha_matricula' => '2026-11-10',
            'estado' => 'pendiente', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertViolation(self::RESTRICT_VIOLATION, fn () => DB::table('convocatorias')->where('id', $convocatoria)->delete(), 'convocatoria con matrículas');
        DB::table('matriculas')->delete();
        DB::table('convocatoria_horarios')->insert(['convocatoria_id' => $convocatoria, 'dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '10:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertViolation(self::RESTRICT_VIOLATION, fn () => DB::table('convocatorias')->where('id', $convocatoria)->delete(), 'convocatoria con horarios');
        $this->assertViolation(self::RESTRICT_VIOLATION, fn () => DB::table('cursos')->where('id', $curso->id)->delete(), 'curso con convocatorias');
    }

    public function test_los_check_de_evaluaciones_notas_y_resultado(): void
    {
        $admin = $this->admin();
        $curso = $this->curso();
        $estudiante = $this->estudiante('11111111');
        $convocatoria = DB::table('convocatorias')->insertGetId($this->filaConvocatoria($curso, ['estado' => 'en_curso']));
        $matricula = DB::table('matriculas')->insertGetId([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'convocatoria_id' => $convocatoria, 'fecha_matricula' => '2026-11-10',
            'estado' => 'pagada', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fila = fn (array $cambios = []): array => [
            'identificador' => (string) Str::uuid(), 'convocatoria_id' => $convocatoria, 'nombre' => 'Examen', 'peso' => 50, 'orden' => 1,
            'created_at' => now(), 'updated_at' => now(), ...$cambios,
        ];

        foreach (['cero' => 0, 'sobre 100' => 100.01, 'negativo' => -5] as $caso => $peso) {
            $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('evaluaciones')->insert($fila(['peso' => $peso])), "peso {$caso}");
        }
        $evaluacion = DB::table('evaluaciones')->insertGetId($fila());
        $nota = fn (float $valor): array => [
            'matricula_id' => $matricula, 'evaluacion_id' => $evaluacion, 'nota' => $valor, 'registrado_por' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ];
        foreach (['negativa' => -0.01, 'sobre 20' => 20.01] as $caso => $valor) {
            $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('calificaciones')->insert($nota($valor)), "nota {$caso}");
        }
        DB::table('calificaciones')->insert($nota(20));

        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('matriculas')->where('id', $matricula)->update(['nota_final' => 21, 'resultado' => 'aprobado']), 'nota final fuera de rango');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('matriculas')->where('id', $matricula)->update(['nota_final' => 15, 'resultado' => 'inventado']), 'resultado inválido');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('matriculas')->where('id', $matricula)->update(['nota_final' => 15]), 'nota final sin resultado');
        $this->assertViolation(self::CHECK_VIOLATION, fn () => DB::table('matriculas')->where('id', $matricula)->update(['resultado' => 'aprobado']), 'resultado sin nota final');
        DB::table('matriculas')->where('id', $matricula)->update(['nota_final' => 15, 'resultado' => 'aprobado']);

        $this->assertViolation(self::RESTRICT_VIOLATION, fn () => DB::table('evaluaciones')->where('id', $evaluacion)->delete(), 'evaluación con notas');
        $this->assertViolation(self::RESTRICT_VIOLATION, fn () => DB::table('matriculas')->where('id', $matricula)->delete(), 'matrícula con notas');
    }

    public function test_una_nota_espera_el_bloqueo_de_la_convocatoria_y_luego_decide_con_el_estado_confirmado(): void
    {
        $admin = $this->admin();
        $curso = $this->curso();
        $estudiante = $this->estudiante('11111111');
        $convocatoria = Convocatoria::query()->findOrFail(DB::table('convocatorias')->insertGetId($this->filaConvocatoria($curso, ['estado' => 'en_curso'])));
        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'convocatoria_id' => $convocatoria->id,
            'fecha_matricula' => '2026-11-10', 'estado' => 'pagada',
        ]);
        $evaluacion = (string) Str::uuid();
        DB::table('evaluaciones')->insert([
            'identificador' => $evaluacion, 'convocatoria_id' => $convocatoria->id, 'nombre' => 'Examen', 'peso' => 100, 'orden' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $datos = ['evaluacion' => $evaluacion, 'matricula_id' => $matricula->id, 'nota' => 15];
        $b = $this->secondSession();

        // B: el cierre de acta de otra petición ya tomó la convocatoria y la deja cerrada (aún sin confirmar).
        $b->beginTransaction();
        $b->select('select id from convocatorias where id = ? for update', [$convocatoria->id]);
        $b->update("update convocatorias set estado = 'cerrada', revision = revision + 1 where id = ?", [$convocatoria->id]);

        DB::statement("SET lock_timeout = '2s'");
        $this->withoutExceptionHandling();
        try {
            $this->como($admin, 'PUT', "convocatorias/{$convocatoria->identificador}/calificaciones", $datos);
            $this->fail('La petición debía quedar bloqueada por la transacción de la otra sesión.');
        } catch (QueryException $e) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $e->getCode(), 'Debe esperar el bloqueo (55P03), no fallar por otra causa.');
        } finally {
            $this->withExceptionHandling();
        }
        $this->assertSame(0, DB::table('calificaciones')->count(), 'La nota bloqueada no deja rastro.');

        $b->commit();

        $this->como($admin, 'PUT', "convocatorias/{$convocatoria->identificador}/calificaciones", $datos)->assertStatus(409);
        $this->assertSame(0, DB::table('calificaciones')->count(), 'Con el acta cerrada ya no se registran notas.');
    }

    public function test_la_ultima_plaza_espera_el_bloqueo_y_luego_decide_con_el_estado_confirmado(): void
    {
        $admin = $this->admin();
        $curso = $this->curso();
        $ana = $this->estudiante('11111111');
        $beto = $this->estudiante('22222222');
        $convocatoria = Convocatoria::query()->findOrFail(DB::table('convocatorias')->insertGetId($this->filaConvocatoria($curso, ['cupos_maximos' => 1, 'cupos_disponibles' => 1])));
        $datos = fn (Estudiante $e): array => [
            'estudiante_id' => $e->id, 'curso_id' => $curso->id, 'convocatoria' => $convocatoria->identificador,
            'fecha_matricula' => '2026-11-10', 'estado' => 'pendiente',
        ];
        $b = $this->secondSession();

        // B: otra matrícula que ya tomó la fila de la convocatoria y se queda con la última plaza (aún sin confirmar).
        $b->beginTransaction();
        $b->select('select id from convocatorias where id = ? for update', [$convocatoria->id]);
        $b->update('update convocatorias set cupos_disponibles = 0 where id = ?', [$convocatoria->id]);

        DB::statement("SET lock_timeout = '2s'");
        $this->withoutExceptionHandling();
        try {
            $this->como($admin, 'POST', 'matriculas', $datos($ana));
            $this->fail('La petición debía quedar bloqueada por la transacción de la otra sesión.');
        } catch (QueryException $e) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $e->getCode(), 'Debe esperar el bloqueo (55P03), no fallar por otra causa.');
        } finally {
            $this->withExceptionHandling();
        }
        $this->assertSame(0, Matricula::query()->count(), 'La matrícula bloqueada no deja rastro.');

        $b->commit();

        $this->como($admin, 'POST', 'matriculas', $datos($beto))
            ->assertUnprocessable()
            ->assertJsonPath('errors.convocatoria.0', 'La convocatoria no tiene cupos disponibles.');
        $this->assertSame(0, Matricula::query()->count());
        $this->assertSame(0, (int) $convocatoria->fresh()->cupos_disponibles, 'Nunca queda por debajo de cero.');
        $this->assertSame(10, (int) $curso->fresh()->cupos_disponibles, 'El cupo del curso no se toca.');
    }
}
