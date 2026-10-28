<?php

declare(strict_types=1);

namespace Tests\Benchmarks;

use App\Services\AsistenteVirtualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Opt-in: php vendor/bin/phpunit --configuration=phpunit.xml tests/benchmarks/BackendQueriesBenchmarkTest.php
// Solo SQLite :memory:, datos sintéticos, sin HTTP externo. Dos calentamientos y cinco muestras.
class BackendQueriesBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public static function volumes(): array
    {
        return [[100], [1000]];
    }

    #[DataProvider('volumes')]
    public function test_measure_actual_backend_operations(int $size): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->travelTo(now()->setDate(2026, 10, 28)->startOfDay());
        config(['matricula-mail.enabled' => false, 'services.openai.api_key' => null,
            'chatbot.public_catalog_enabled' => false]);
        Http::preventStrayRequests();
        $this->actingAs($this->adminUser());

        // SQL masivo deliberado: no mide observers, escrituras ni consumo de cupos.
        for ($i = 1; $i <= $size; $i++) {
            DB::table('estudiantes')->insert(['id' => $i, 'dni' => sprintf('%08d', $i),
                'nombres' => 'Prueba', 'apellidos' => sprintf('Ficticia %05d', $i), 'estado' => 'activo',
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('cursos')->insert(['id' => $i, 'codigo' => 'P'.$i, 'nombre' => sprintf('Curso Prueba %05d', $i),
                'descripcion' => str_repeat('Ficticio ', 1024), 'categoria' => 'tecnologia', 'duracion_horas' => 10,
                'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Ficticio', 'cupos_maximos' => 10, 'cupos_disponibles' => 9,
                'estado' => 'activo', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('matriculas')->insert(['id' => $i, 'estudiante_id' => $i, 'curso_id' => $i,
                'fecha_matricula' => '2026-10-28', 'estado' => ['pendiente', 'pagada', 'anulada'][$i % 3],
                'monto_pagado' => 100, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('chat_intents')->insert(['nombre' => 'inactiva_'.$i, 'keywords' => '["ficticio"]',
                'respuesta' => str_repeat('Inactiva ', 128), 'prioridad' => 1, 'activo' => false,
                'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('chat_intents')->insert(['nombre' => 'personalizada', 'keywords' => '["beneficio ficticio"]',
            'respuesta' => 'Respuesta ficticia.', 'prioridad' => 1, 'activo' => true]);

        $hydrated = [];
        Event::listen('eloquent.retrieved: *', function (string $event, array $data) use (&$hydrated): void {
            $class = $data[0]::class;
            $hydrated[$class] = ($hydrated[$class] ?? 0) + 1;
        });
        foreach (['estudiantes', 'cursos', 'matriculas', 'reportes', 'dashboard', 'chatbot'] as $operation) {
            $samples = [];
            for ($sample = 0; $sample < 7; $sample++) {
                gc_collect_cycles();
                DB::enableQueryLog();
                DB::flushQueryLog();
                $hydrated = [];
                memory_reset_peak_usage();
                $memory = memory_get_usage(false);
                $start = hrtime(true);
                if ($operation === 'chatbot') {
                    $result = app(AsistenteVirtualService::class)->procesarMensaje('beneficio ficticio', 'bench-'.$size.'-'.$sample);
                } else {
                    $parameters = in_array($operation, ['reportes', 'matriculas'], true) ? ['busqueda' => 'Prueba'] : [];
                    $route = $operation === 'dashboard' ? 'admin.dashboard' : 'admin.'.$operation.'.index';
                    $response = $this->get(route($route, $parameters));
                    $variable = $operation === 'reportes' ? 'matriculas' : $operation;
                    $result = $operation === 'dashboard'
                        ? [$response->viewData('matriculasMes'), (float) $response->viewData('ingresosMes'), $response->viewData('meses')->all()]
                        : [$response->viewData($variable)->pluck('id')->all(), $response->viewData($variable)->total()];
                    if ($operation === 'reportes') {
                        $result[] = $response->viewData('resumen');
                    } elseif ($operation === 'matriculas') {
                        $result[] = $response->viewData('cursos')->map(fn ($course) => [$course->id, $course->codigo, $course->nombre, $course->trashed()])->all();
                    }
                }
                $elapsed = (hrtime(true) - $start) / 1e6;
                $peak = memory_get_peak_usage(false);
                $queries = count(DB::getQueryLog());
                DB::disableQueryLog();
                DB::flushQueryLog();
                if ($operation !== 'chatbot') {
                    $response->assertOk();
                }
                if ($sample >= 2) {
                    $samples[] = ['ms' => $elapsed, 'peak_delta_mib' => ($peak - $memory) / 1048576,
                        'queries' => $queries, 'hydrated' => $hydrated,
                        'checksum' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR))];
                }
                unset($response, $result);
            }
            $median = static function (array $values): float {
                sort($values);

                return round($values[2], 3);
            };
            $this->assertCount(1, array_unique(array_column($samples, 'checksum')));
            fwrite(STDOUT, json_encode(['size' => $size, 'operation' => $operation,
                'median_ms' => $median(array_column($samples, 'ms')),
                'median_peak_delta_mib' => $median(array_column($samples, 'peak_delta_mib')),
                'queries' => $samples[0]['queries'], 'hydrated' => $samples[0]['hydrated'],
                'checksum' => $samples[0]['checksum']], JSON_THROW_ON_ERROR).PHP_EOL);
        }
        Http::assertNothingSent();
    }
}
