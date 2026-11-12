<?php

declare(strict_types=1);

use App\Support\Testing\PostgresTestGuard;
use App\Support\Testing\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$action = $argv[1] ?? 'inspect';
if (! in_array($action, ['inspect', 'test', 'ddl'], true)
    || ($action === 'inspect' ? count($argv) !== 2 : count($argv) !== 3 || $argv[2] !== '--execute')) {
    fwrite(STDERR, "Uso: php scripts/consolidacion-postgres.php inspect | test --execute | ddl --execute\n");
    exit(1);
}
// Consentimiento solo del proceso solicitado. Nunca modifica archivos de entorno.
putenv('HORUS_PG_TEST_PROFILE=1');
$_ENV['HORUS_PG_TEST_PROFILE'] = $_SERVER['HORUS_PG_TEST_PROFILE'] = '1';
if ($action !== 'inspect') {
    putenv('HORUS_PG_TEST_CONFIRM='.PostgresTestGuard::CONFIRMATION);
    $_ENV['HORUS_PG_TEST_CONFIRM'] = $_SERVER['HORUS_PG_TEST_CONFIRM'] = PostgresTestGuard::CONFIRMATION;
    $file = $action === 'ddl' ? 'MigracionesAprobadasTest.php' : 'IntegridadExistenteTest.php';
    $process = new Process([PHP_BINARY, dirname(__DIR__).'/vendor/bin/phpunit',
        '--configuration', dirname(__DIR__).'/phpunit.pgsql.fase4.xml',
        '--stop-on-error', '--stop-on-failure', dirname(__DIR__).'/tests/ConsolidacionPostgres/'.$file]);
    $process->setTimeout(null);
    exit($process->run(static function (string $type, string $output): void {
        echo $output;
    }));
}
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->instance(TestDatabaseSafety::READ_ONLY_PROBE, true);
$app->make(Kernel::class)->bootstrap();
$db = $app->make('db')->connection();
$db->getPdo();
$tables = $db->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
$applied = $db->getSchemaBuilder()->hasTable('migrations')
    ? $db->table('migrations')->orderBy('migration')->pluck('migration')->all() : [];
echo json_encode(['tables' => array_column($tables, 'tablename'), 'migrations' => $applied,
    'users_count' => $db->getSchemaBuilder()->hasTable('users') ? $db->table('users')->count() : null], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
