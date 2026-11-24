<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * Respaldo local (`horus:respaldo`). SIEMPRE con Process::fake y Storage::fake: nunca se ejecuta pg_dump ni se toca una base
 * real. La conexión PostgreSQL de las pruebas es solo una configuración ficticia que el servicio lee.
 */

const CLAVE_FALSA = 'clave-falsa-NO-debe-aparecer-9f3a';

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'respaldo.conexion' => 'pg_respaldo',
        'database.connections.pg_respaldo' => [
            'driver' => 'pgsql', 'host' => 'bd.interna.test', 'port' => '5432', 'database' => 'horus_prueba',
            'username' => 'usuario_prueba', 'password' => CLAVE_FALSA, 'sslmode' => 'prefer',
        ],
        'respaldo.retencion_dias' => 14,
        'respaldo.minimo' => 3,
    ]);
    // 07:00 UTC = 02:00 en Lima → horus-20261123-020000.dump
    Carbon::setTestNow(Carbon::parse('2026-11-23 07:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

function nombreDeHoy(): string
{
    return 'horus-20261123-020000.dump';
}

/** Deja un archivo de volcado de mentira con una antigüedad dada (días). */
function copiaFalsa(string $nombre, int $dias, string $contenido = 'volcado'): void
{
    $disco = Storage::disk('local');
    $disco->put('respaldos/'.$nombre, $contenido);
    $disco->put('respaldos/'.$nombre.'.sha256', hash('sha256', $contenido).'  '.$nombre."\n");
    touch($disco->path('respaldos/'.$nombre), now()->subDays($dias)->getTimestamp());
}

describe('comando horus:respaldo', function (): void {
    it('crea la copia, su suma SHA-256 y pasa la contraseña solo por el entorno del proceso', function (): void {
        Process::fake(['*' => Process::result(exitCode: 0)]);
        // El pg_dump falso no escribe: se deja el temporal que escribiría.
        Storage::disk('local')->put('respaldos/'.nombreDeHoy().'.tmp', 'contenido-del-volcado');

        $this->artisan('horus:respaldo')->expectsOutputToContain(nombreDeHoy())->assertSuccessful();

        $disco = Storage::disk('local');
        expect($disco->exists('respaldos/'.nombreDeHoy()))->toBeTrue()
            ->and($disco->exists('respaldos/'.nombreDeHoy().'.tmp'))->toBeFalse()
            ->and($disco->get('respaldos/'.nombreDeHoy().'.sha256'))->toContain(hash('sha256', 'contenido-del-volcado'));

        Process::assertRan(function (PendingProcess $p): bool {
            $linea = is_array($p->command) ? implode(' ', $p->command) : (string) $p->command;

            return str_contains($linea, '--format=custom')
                && str_contains($linea, '--dbname=horus_prueba')
                && ! str_contains($linea, CLAVE_FALSA)
                && ($p->environment['PGPASSWORD'] ?? null) === CLAVE_FALSA;
        });
    });

    it('si pg_dump falla no publica nada y no filtra credenciales ni la salida del proceso', function (): void {
        Process::fake(['*' => Process::result(output: 'host bd.interna.test', errorOutput: 'password '.CLAVE_FALSA, exitCode: 1)]);
        Storage::disk('local')->put('respaldos/'.nombreDeHoy().'.tmp', 'parcial');

        $this->artisan('horus:respaldo')
            ->doesntExpectOutputToContain(CLAVE_FALSA)
            ->doesntExpectOutputToContain('bd.interna.test')
            ->expectsOutputToContain('pg_dump terminó con error')
            ->assertFailed();

        expect(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('rechaza un volcado vacío', function (): void {
        Process::fake(['*' => Process::result(exitCode: 0)]);

        $this->artisan('horus:respaldo')->expectsOutputToContain('quedó vacío')->assertFailed();

        expect(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('se niega con un motor que no es PostgreSQL y no ejecuta nada', function (): void {
        config(['respaldo.conexion' => null]); // la conexión por defecto de la prueba es SQLite
        Process::fake();

        $this->artisan('horus:respaldo')->expectsOutputToContain('solo está disponible con PostgreSQL')->assertFailed();

        Process::assertNothingRan();
    });

    it('no usa un disco público', function (): void {
        config(['respaldo.disco' => 'public']);
        Process::fake();

        $this->artisan('horus:respaldo')->expectsOutputToContain('privado')->assertFailed();

        Process::assertNothingRan();
    });

    it('aplica la retención conservando las copias mínimas y sin tocar archivos ajenos', function (): void {
        copiaFalsa('horus-20261122-020000.dump', 1);
        copiaFalsa('horus-20261001-020000.dump', 53);
        copiaFalsa('horus-20260901-020000.dump', 83);
        copiaFalsa('horus-20260801-020000.dump', 113);
        copiaFalsa('horus-20260701-020000.dump', 143);
        Storage::disk('local')->put('respaldos/notas.txt', 'ajeno');
        Process::fake();

        $this->artisan('horus:respaldo', ['--solo-podar' => true])->assertSuccessful();

        $disco = Storage::disk('local');
        expect($disco->exists('respaldos/horus-20261122-020000.dump'))->toBeTrue()
            ->and($disco->exists('respaldos/horus-20261001-020000.dump'))->toBeTrue()
            ->and($disco->exists('respaldos/horus-20260901-020000.dump'))->toBeTrue()
            ->and($disco->exists('respaldos/horus-20260801-020000.dump'))->toBeFalse()
            ->and($disco->exists('respaldos/horus-20260801-020000.dump.sha256'))->toBeFalse()
            ->and($disco->exists('respaldos/horus-20260701-020000.dump'))->toBeFalse()
            ->and($disco->get('respaldos/notas.txt'))->toBe('ajeno');
        Process::assertNothingRan();
    });

    it('con --simular no crea ni borra nada', function (): void {
        copiaFalsa('horus-20261122-020000.dump', 1);
        copiaFalsa('horus-20260101-020000.dump', 300);
        copiaFalsa('horus-20260102-020000.dump', 299);
        copiaFalsa('horus-20260103-020000.dump', 298);
        Process::fake();

        $this->artisan('horus:respaldo', ['--simular' => true])->expectsOutputToContain('Simulación')->assertSuccessful();

        expect(Storage::disk('local')->files('respaldos'))->toHaveCount(8); // 4 copias + 4 sumas
        Process::assertNothingRan();
    });
});

describe('copias en el panel', function (): void {
    it('solo respaldo.exportar (admin) lista y descarga; el coordinador, que ve el respaldo administrativo, no', function (): void {
        copiaFalsa(nombreDeHoy(), 0, 'contenido');

        // Primero sin sesión: `actingAs` persiste dentro de la misma prueba.
        $this->panelComo(null, 'GET', 'compartido/respaldo/copias')->assertUnauthorized();
        $this->panelComo($this->usuarioConRol('admin'), 'GET', 'compartido/respaldo/copias')
            ->assertOk()
            ->assertJsonPath('data.copias.0.nombre', nombreDeHoy())
            ->assertJsonPath('data.copias.0.bytes', 9)
            ->assertJsonPath('data.copias.0.sha256', hash('sha256', 'contenido'));
        $this->panelComo($this->usuarioConRol('coordinador'), 'GET', 'compartido/respaldo/copias')->assertForbidden();
        $this->panelComo($this->usuarioConRol('coordinador'), 'GET', 'compartido/respaldo/copias/'.nombreDeHoy())->assertForbidden();
        $this->panelComo($this->usuarioConRol('secretaria'), 'GET', 'compartido/respaldo/copias')->assertForbidden();
    });

    it('no revela rutas del servidor en el listado', function (): void {
        copiaFalsa(nombreDeHoy(), 0);

        $texto = (string) $this->panelComo($this->usuarioConRol('admin'), 'GET', 'compartido/respaldo/copias')->getContent();

        expect($texto)->not->toContain(Storage::disk('local')->path(''))->not->toContain('storage');
    });

    it('descarga una copia íntegra y deja constancia en la auditoría', function (): void {
        copiaFalsa(nombreDeHoy(), 0, 'contenido-integro');
        $admin = $this->usuarioConRol('admin');

        $respuesta = $this->panelComo($admin, 'GET', 'compartido/respaldo/copias/'.nombreDeHoy())->assertOk();

        expect($respuesta->baseResponse->getFile()->getPathname())->toEndWith(nombreDeHoy())
            ->and((string) $respuesta->headers->get('Cache-Control'))->toContain('no-store');
        $registro = AuditLog::query()->where('action', 'respaldo_copia_descargada')->firstOrFail();
        expect($registro->user_id)->toBe($admin->id)->and($registro->changes)->toBe(['nombre' => nombreDeHoy()]);
    });

    it('no entrega una copia cuya suma ya no coincide (alterada o corrupta)', function (): void {
        copiaFalsa(nombreDeHoy(), 0, 'original');
        Storage::disk('local')->put('respaldos/'.nombreDeHoy(), 'alterado');

        $this->panelComo($this->usuarioConRol('admin'), 'GET', 'compartido/respaldo/copias/'.nombreDeHoy())->assertStatus(409);

        expect(AuditLog::query()->where('action', 'respaldo_copia_rechazada')->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'respaldo_copia_descargada')->count())->toBe(0);
    });

    it('rechaza nombres fuera del patrón y copias inexistentes (sin salir de la carpeta)', function (string $nombre): void {
        Storage::disk('local')->put('secreto.txt', 'no debe salir');
        $admin = $this->usuarioConRol('admin');

        $this->panelComo($admin, 'GET', 'compartido/respaldo/copias/'.$nombre)->assertNotFound();
    })->with([
        'recorrido de carpetas' => ['..%2F..%2Fsecreto.txt'],
        'extensión distinta' => ['horus-20261123-020000.txt'],
        'archivo de suma' => ['horus-20261123-020000.dump.sha256'],
        'patrón válido pero inexistente' => ['horus-20200101-000000.dump'],
    ]);
});
