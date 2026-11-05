<?php

declare(strict_types=1);

namespace Tests\ConsolidacionPostgres;

use App\Models\NotificacionInterna;
use App\Models\User;
use App\Support\Testing\ConcurrentTestSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PostgresIntegrity\Concerns\ReclamacionesPostgres;

class IntegridadExistenteTest extends CasoPostgresReversible
{
    use ReclamacionesPostgres;

    public function test_coordinador_espera_bloqueo_y_no_modifica_administrador_existente(): void
    {
        $adminId = User::role('admin')->value('users.id');
        $this->assertNotNull($adminId, 'No se modifica ni crea la cuenta preexistente.');
        $actor = User::factory()->create();
        $actor->assignRole('coordinador');
        $actor->givePermissionTo(['usuarios.editar', 'usuarios.asignar-roles']);
        $b = ConcurrentTestSession::open($this->app);
        try {
            $b->beginTransaction();
            $b->select("SELECT id FROM roles WHERE name = 'admin' AND guard_name = 'web' FOR UPDATE");
            $datos = ['name' => 'Solicitud sintética', 'email' => 'consolidacion@example.test', 'roles' => ['secretaria']];
            $this->assertBloqueada(fn () => $this->actingAs($actor)->withoutExceptionHandling()
                ->put(route('admin.usuarios.update', $adminId), $datos));
            $b->rollBack();
            $this->withExceptionHandling()->put(route('admin.usuarios.update', $adminId), $datos)->assertForbidden();
            $this->assertTrue(User::query()->findOrFail($adminId)->hasRole('admin'));
        } finally {
            ConcurrentTestSession::close($b);
        }
    }

    #[DataProvider('casosMatricula')]
    public function test_integridad_academica_sin_reconstruccion(string $caso): void
    {
        $estudiante = $this->fixture('estudiantes', ['dni' => '31999999', 'nombres' => 'Persona', 'apellidos' => 'Sintética', 'estado' => 'activo']);
        $curso = $this->fixture('cursos', ['codigo' => 'CONSOLIDACION-PG', 'nombre' => 'Curso sintético',
            'descripcion' => 'Descripción sintética', 'horario' => 'Horario sintético',
            'categoria' => 'tecnologia', 'duracion_horas' => 20, 'precio' => 100, 'modalidad' => 'virtual',
            'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo']);
        $fila = ['estudiante_id' => $estudiante, 'curso_id' => $curso, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente'];
        if ($caso === 'historicas') {
            $this->fixture('matriculas', ['estado' => 'anulada'] + $fila);
            $this->fixture('matriculas', ['deleted_at' => now()] + $fila);
            $this->fixture('matriculas', $fila);
            $this->assertSame(3, DB::table('matriculas')->where('curso_id', $curso)->count());
        } elseif ($caso === 'restauracion') {
            $id = $this->fixture('matriculas', ['deleted_at' => now()] + $fila);
            $this->fixture('matriculas', $fila);
            $this->assertSqlState('23505', fn () => DB::table('matriculas')->where('id', $id)->update(['deleted_at' => null]));
        } elseif ($caso === 'duplicados') {
            $this->fixture('matriculas', $fila);
            $this->assertSqlState('23505', fn () => $this->fixture('matriculas', ['estado' => 'pagada'] + $fila));
        } elseif ($caso === 'cupos') {
            foreach ([-1, 6] as $cupos) {
                $this->assertSqlState('23514', fn () => DB::table('cursos')->where('id', $curso)->update(['cupos_disponibles' => $cupos]));
            }
        } else {
            $id = $this->fixture('matriculas', $fila);
            $this->assertSqlState('23514', fn () => DB::table('matriculas')->where('id', $id)->update(['monto_pagado' => -0.01]));
            $this->assertSqlState('23514', fn () => DB::table('cursos')->where('id', $curso)->update(['precio' => -1]));
        }
    }

    public static function casosMatricula(): iterable
    {
        foreach (['duplicados', 'historicas', 'restauracion', 'cupos', 'montos'] as $caso) {
            yield $caso => [$caso];
        }
    }

    public function test_restrict_con_evidencia_es_23001_y_referencia_inexistente_es_23503(): void
    {
        $id = $this->fixture('reclamaciones', $this->filaReclamacion());
        $this->fixture('reclamacion_historial', ['reclamacion_id' => $id, 'evento' => 'registrada',
            'actor_nombre' => 'Sistema', 'metadatos' => '{}', 'created_at' => now()]);
        $this->assertSqlState('23001', fn () => DB::table('reclamaciones')->where('id', $id)->delete());
        $otro = $this->fixture('reclamaciones', $this->filaReclamacion());
        $this->fixture('reclamacion_envios', ['reclamacion_id' => $otro, 'tipo' => 'copia_hoja', 'canal' => 'correo',
            'clave' => (string) Str::uuid(), 'estado' => 'pendiente', 'intentos' => 0,
            'asunto' => 'Sintético', 'cuerpo' => 'Sintético', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSqlState('23001', fn () => DB::table('reclamaciones')->where('id', $otro)->delete());
        $this->assertSame(2, DB::table('reclamaciones')->whereIn('id', [$id, $otro])->count());
        $ausente = $this->fixture('reclamaciones', $this->filaReclamacion());
        DB::table('reclamaciones')->where('id', $ausente)->delete();
        $this->assertSqlState('23503', fn () => $this->fixture('reclamacion_historial', [
            'reclamacion_id' => $ausente, 'evento' => 'registrada', 'actor_nombre' => 'Sistema',
            'metadatos' => '{}', 'created_at' => now(),
        ]));
    }

    public function test_notificaciones_restricciones_visibilidad_y_set_null(): void
    {
        $usuario = User::factory()->create();
        $otro = User::factory()->create();
        $fila = ['titulo' => 'Sintético', 'mensaje' => 'Prueba aislada', 'tipo' => 'info', 'leida' => false];
        foreach ([
            [null, $usuario->id, '23502', 'audiencia'],
            ['otra', null, '23514', 'notificaciones_internas_audiencia_valida'],
            ['general', $usuario->id, '23514', 'notificaciones_internas_general_sin_destinatario'],
            ['indeterminada', $usuario->id, '23514', 'notificaciones_internas_indeterminada_sin_destinatario'],
            ['personal', null, '23514', 'notificaciones_internas_personal_con_destinatario'],
        ] as [$audiencia, $destino, $estado, $restriccion]) {
            $error = $this->assertSqlState($estado, fn () => $this->fixture('notificaciones_internas',
                ['audiencia' => $audiencia, 'user_id' => $destino] + $fila));
            $this->assertStringContainsString($restriccion, $error->getMessage());
        }
        $personal = NotificacionInterna::crearPersonal($usuario->id, $fila);
        $general = NotificacionInterna::crearGeneral($fila);
        $indeterminada = $this->fixture('notificaciones_internas', ['audiencia' => 'indeterminada', 'user_id' => null] + $fila);
        foreach ([[$personal->id, ['audiencia' => 'general', 'user_id' => null]],
            [$personal->id, ['user_id' => $otro->id]], [$general->id, ['user_id' => $usuario->id]],
            [$indeterminada, ['audiencia' => 'personal', 'user_id' => $usuario->id]],
            [$general->id, ['audiencia' => 'indeterminada']]] as [$id, $cambio]) {
            $error = $this->assertSqlState('23514', fn () => DB::table('notificaciones_internas')->where('id', $id)->update($cambio));
            $this->assertStringContainsString('notificaciones_internas_audiencia_inmutable', $error->getMessage());
        }
        DB::table('notificaciones_internas')->where('id', $personal->id)->update(['leida' => true]);
        DB::table('users')->where('id', $usuario->id)->delete();
        $personal->refresh();
        $this->assertNull($personal->user_id);
        $this->assertSame('personal', $personal->audiencia);
        $this->assertFalse($personal->esVisiblePara($otro));
        $visibles = NotificacionInterna::query()->visiblePara($otro)->pluck('id')->all();
        $this->assertNotContains($personal->id, $visibles);
        $this->assertContains($general->id, $visibles);
    }
}
