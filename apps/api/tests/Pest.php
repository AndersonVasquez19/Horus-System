<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Academic\Concerns\LlamaAlPanel;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest en Horus Academic
|--------------------------------------------------------------------------
|
| Pest convive con PHPUnit (docs/horus-academic/07-plan-pruebas.md): las ~115 clases PHPUnit existentes siguen ejecutándose
| tal cual y NO se reescriben en bloque. Aquí solo se configuran los directorios nuevos:
|
|   tests/Academic/**  pruebas de los módulos de Horus Academic (base de datos SQLite en memoria, RefreshDatabase).
|   tests/Contract/**  pruebas de contrato (OpenAPI ↔ rutas); no necesitan base de datos.
|
| Cada prueba que escribe conserva la salvaguarda de las clases PHPUnit: `assertIsolatedTestDatabase()` (SQLite :memory: o,
| en el perfil PostgreSQL aislado, la conexión protegida). Las pruebas del perfil PostgreSQL aislado se escriben como clases
| PHPUnit hasta demostrar que Pest respeta `RequiresIsolatedPostgres`.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->use(LlamaAlPanel::class)
    ->beforeEach(function (): void {
        /** @var TestCase $this */
        $this->assertIsolatedTestDatabase();
    })
    ->in('Academic');

pest()->extend(TestCase::class)->in('Contract');
