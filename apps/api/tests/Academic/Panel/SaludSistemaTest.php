<?php

declare(strict_types=1);

use App\Models\AvisoExterno;
use App\Models\NotificacionInterna;
use App\Services\Panel\SaludSistema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Salud operativa del sistema y su vigilante. SQLite en memoria; sin HTTP ni correo. La caché de pruebas es `array`.
 */

beforeEach(function (): void {
    Storage::fake('local');
    Cache::flush();
    Carbon::setTestNow(Carbon::parse('2026-11-23 12:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

/** @return array<string, array{estado: string, detalle: string}> comprobaciones indexadas por clave */
function salud(): array
{
    return collect(app(SaludSistema::class)->evaluar()['comprobaciones'])->keyBy('clave')->all();
}

function trabajoFallido(): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subHour()->toDateTimeString(),
    ]);
}

describe('endpoint compartido/salud', function (): void {
    it('lo ve solo quien tiene respaldo.exportar (admin) y no se guarda en caché', function (): void {
        $this->panelComo(null, 'GET', 'compartido/salud')->assertUnauthorized();

        $respuesta = $this->panelComo($this->usuarioConRol('admin'), 'GET', 'compartido/salud')->assertOk();
        expect(collect($respuesta->json('data.comprobaciones'))->pluck('clave')->all())
            ->toBe(['base_datos', 'scheduler', 'cola', 'fallidos', 'avisos', 'disco', 'respaldo'])
            ->and((string) $respuesta->headers->get('Cache-Control'))->toContain('no-store');

        $this->panelComo($this->usuarioConRol('coordinador'), 'GET', 'compartido/salud')->assertForbidden();
        $this->panelComo($this->usuarioConRol('secretaria'), 'GET', 'compartido/salud')->assertForbidden();
    });
});

describe('comprobaciones', function (): void {
    it('la base de datos responde', function (): void {
        expect(salud()['base_datos']['estado'])->toBe('ok');
    });

    it('el scheduler envejece: sin latido avisa, reciente está bien y a los 6 minutos es error', function (): void {
        expect(salud()['scheduler']['estado'])->toBe('alerta');

        SaludSistema::latir();
        expect(salud()['scheduler']['estado'])->toBe('ok');

        Carbon::setTestNow(now()->addSeconds(150));
        expect(salud()['scheduler']['estado'])->toBe('alerta');

        Carbon::setTestNow(now()->addMinutes(4));
        expect(salud()['scheduler']['estado'])->toBe('error');
    });

    it('cuenta los trabajos fallidos de las últimas 24 horas', function (): void {
        expect(salud()['fallidos']['estado'])->toBe('ok');

        trabajoFallido();
        expect(salud()['fallidos']['estado'])->toBe('alerta');

        foreach (range(1, 9) as $_) {
            trabajoFallido();
        }
        expect(salud()['fallidos']['estado'])->toBe('error');
    });

    it('detecta trabajos en cola que llevan demasiado esperando', function (): void {
        config(['queue.default' => 'database']);
        expect(salud()['cola']['estado'])->toBe('ok');

        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(15)->getTimestamp(), 'created_at' => now()->subMinutes(15)->getTimestamp(),
        ]);
        expect(salud()['cola']['estado'])->toBe('alerta');
    });

    it('detecta avisos externos atascados o con entrega incierta', function (): void {
        expect(salud()['avisos']['estado'])->toBe('ok');

        AvisoExterno::create([
            'canal' => 'email', 'tipo' => 'recordatorio', 'clave_unica' => 'k1', 'delivery_key' => (string) Str::uuid(),
            'status' => 'pending', 'available_at' => now()->subHour(),
        ]);
        expect(salud()['avisos']['estado'])->toBe('alerta');
    });

    it('el respaldo es informativo si está apagado y alerta si está programado y no hay copias', function (): void {
        expect(salud()['respaldo']['estado'])->toBe('info');

        config(['respaldo.programado' => true]);
        expect(salud()['respaldo']['estado'])->toBe('alerta');
    });

    it('el estado general es el peor de las comprobaciones, pero lo informativo no cuenta', function (): void {
        SaludSistema::latir();
        // Con latido reciente y todo lo demás en orden (el respaldo apagado es solo informativo) el estado es ok.
        expect(app(SaludSistema::class)->evaluar()['estado'])->toBe('ok');

        trabajoFallido();
        expect(app(SaludSistema::class)->evaluar()['estado'])->toBe('alerta');
    });
});

describe('horus:salud-vigilar', function (): void {
    it('avisa a cada administrador una sola vez al día y no al resto del personal', function (): void {
        $admin = $this->usuarioConRol('admin');
        $secretaria = $this->usuarioConRol('secretaria');
        trabajoFallido();
        SaludSistema::latir();

        $this->artisan('horus:salud-vigilar')->assertSuccessful();
        $this->artisan('horus:salud-vigilar')->assertSuccessful();

        $avisos = NotificacionInterna::query()->where('tipo', 'danger')->where('titulo', 'like', 'Alerta del sistema:%')->get();
        expect($avisos)->toHaveCount(1)
            ->and($avisos->first()->user_id)->toBe($admin->id)
            ->and($avisos->first()->titulo)->toBe('Alerta del sistema: Trabajos fallidos')
            ->and(NotificacionInterna::query()->where('user_id', $secretaria->id)->count())->toBe(0);

        // Al día siguiente vuelve a avisar.
        Carbon::setTestNow(now()->addDay());
        SaludSistema::latir();
        trabajoFallido(); // el de ayer ya salió de la ventana de 24 h
        $this->artisan('horus:salud-vigilar')->assertSuccessful();
        expect(NotificacionInterna::query()->where('titulo', 'Alerta del sistema: Trabajos fallidos')->count())->toBe(2);
    });

    it('con --simular describe el aviso sin crear ninguna notificación', function (): void {
        $this->usuarioConRol('admin');
        trabajoFallido();
        SaludSistema::latir();

        $this->artisan('horus:salud-vigilar', ['--simular' => true])->expectsOutputToContain('Simulación')->assertSuccessful();

        expect(NotificacionInterna::query()->count())->toBe(0);
    });

    it('no hace nada si todo está en orden', function (): void {
        $this->usuarioConRol('admin');
        SaludSistema::latir();

        $this->artisan('horus:salud-vigilar')->expectsOutputToContain('todo en orden')->assertSuccessful();

        expect(NotificacionInterna::query()->count())->toBe(0);
    });
});
