<?php

declare(strict_types=1);

namespace Tests\ConsolidacionPostgres;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Sin migrador, reconstrucción, truncado ni cambios en las secuencias preexistentes. */
abstract class CasoPostgresReversible extends TestCase
{
    protected bool $ddlAutorizado = false;

    private ?array $antes = null;

    private int $idFixture = -1900000000;

    protected function setUp(): void
    {
        if (getenv('HORUS_PG_TEST_PROFILE') !== '1') {
            $this->markTestSkipped('Exclusivamente perfil PostgreSQL protegido.');
        }
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->assertSame('horus_l2_test', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        config(['horus-advisor.enabled' => false, 'horus-catalog.sync_enabled' => false]);
        Event::listen(CommandStarting::class, static function (): never {
            throw new RuntimeException('Esta cápsula no permite comandos Artisan.');
        });
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (! $this->ddlAutorizado && preg_match('/^\s*(ALTER|CREATE|DROP|TRUNCATE|COMMENT|GRANT|REVOKE)\b/i', $sql)) {
                throw new RuntimeException('DDL fuera del escenario autorizado.');
            }
        });
        $lock = DB::selectOne('SELECT pg_try_advisory_lock(199405, 4) AS adquirido');
        $this->assertTrue(in_array($lock->adquirido, [true, 't'], true), 'Otra validación conserva el bloqueo.');
        $this->antes = $this->huella();
        DB::beginTransaction();
        Event::listen('eloquent.creating: *', function (string $event, array $models): void {
            $model = $models[0];
            if ($model instanceof Model && $model->getKeyType() === 'int' && $model->getKey() === null) {
                $model->setAttribute($model->getKeyName(), $this->nuevoId());
            }
        });
    }

    protected function nuevoId(): int
    {
        return --$this->idFixture;
    }

    protected function fixture(string $tabla, array $fila): int
    {
        $id = $this->nuevoId();
        DB::table($tabla)->insert(['id' => $id] + $fila);

        return $id;
    }

    protected function migracion(string $nombre): object
    {
        if (! $this->ddlAutorizado || ! in_array($nombre, [
            '2026_11_02_000033_add_contenido_to_servicio_publicaciones',
            '2026_11_03_000034_add_fuente_to_chat_logs',
        ], true)) {
            throw new RuntimeException('Migración fuera de la lista autorizada.');
        }

        return require database_path('migrations/'.$nombre.'.php');
    }

    /** Solo devuelve resúmenes: los valores de las filas no salen del servidor. */
    private function huella(): array
    {
        $tablas = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
        $datos = [];
        foreach ($tablas as $tabla) {
            $nombre = '"'.str_replace('"', '""', $tabla->tablename).'"';
            $datos[$tabla->tablename] = (array) DB::selectOne("SELECT count(*)::text AS n, md5(coalesce(string_agg(h, '' ORDER BY h), '')) AS h FROM (SELECT md5(row_to_json(t)::text) AS h FROM public.$nombre t) filas");
        }
        $catalogo = [];
        foreach ([
            "SELECT c.oid::text, c.relname, c.relkind::text FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace ORDER BY c.oid",
            "SELECT a.* FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid WHERE c.relnamespace = 'public'::regnamespace ORDER BY a.attrelid,a.attnum",
            "SELECT c.oid::text, pg_get_constraintdef(c.oid) AS definicion, c.convalidated FROM pg_constraint c WHERE c.connamespace = 'public'::regnamespace ORDER BY c.oid",
            "SELECT i.indexrelid::text, pg_get_indexdef(i.indexrelid) AS definicion FROM pg_index i JOIN pg_class c ON c.oid = i.indrelid WHERE c.relnamespace = 'public'::regnamespace ORDER BY i.indexrelid",
            "SELECT t.oid::text, t.tgenabled, pg_get_triggerdef(t.oid) AS definicion FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relnamespace = 'public'::regnamespace ORDER BY t.oid",
            "SELECT p.oid::text, pg_get_functiondef(p.oid) AS definicion FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.prokind = 'f' ORDER BY p.oid",
        ] as $sql) {
            $catalogo[] = hash('sha256', json_encode(DB::select($sql), JSON_THROW_ON_ERROR));
        }
        $secuencias = [];
        foreach (DB::select("SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' ORDER BY sequencename") as $s) {
            $nombre = '"'.str_replace('"', '""', $s->sequencename).'"';
            $secuencias[$s->sequencename] = (array) DB::selectOne("SELECT last_value::text, is_called FROM public.$nombre");
        }

        return [$datos, $catalogo, $secuencias];
    }

    protected function tearDown(): void
    {
        try {
            if ($this->antes !== null) {
                DB::rollBack(0);
                $this->assertSame(0, DB::transactionLevel());
                $this->assertFalse(DB::getPdo()->inTransaction());
                // Mensaje minimizado: nunca imprimir valores ni resúmenes de datos preexistentes.
                $this->assertTrue($this->antes === $this->huella(), 'Esquema, datos, registro de migraciones o secuencias cambiaron.');
                DB::select('SELECT pg_advisory_unlock(199405, 4)');
            }
        } finally {
            parent::tearDown();
        }
    }
}
