<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

// Operación específica autorizada: no admite otros destinos, rutas ni migraciones.
if (count($argv) !== 3 || $argv[1] !== '--execute' || ! str_starts_with($argv[2], '--backup-dir=')) {
    fwrite(STDERR, "Uso: php scripts/aplicar-editorial-auditoria.php --execute --backup-dir=<directorio privado existente>\n");
    exit(1);
}
$backupDir = substr($argv[2], strlen('--backup-dir='));
$files = [
    '2026_11_02_000033_add_contenido_to_servicio_publicaciones',
    '2026_11_03_000034_add_fuente_to_chat_logs',
];
$committed = false;
$backup = null;
$connection = null;
$pdo = null;

function exigir(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function identidad(PDO $pdo): array
{
    $identity = $pdo->query("SELECT current_database() AS database, current_user AS username,
        host(inet_server_addr()) AS host, inet_server_port() AS port,
        current_setting('server_version') AS version,
        (SELECT system_identifier::text FROM pg_control_system()) AS system_identifier")->fetch(PDO::FETCH_ASSOC);
    exigir($identity['database'] === 'horus_group' && $identity['host'] === '127.0.0.1'
        && (int) $identity['port'] === 5432 && $identity['version'] === '18.6'
        && $identity['system_identifier'] === '7701086120026667920', 'Identidad física distinta de la autorizada.');

    return $identity;
}

function identificador(string $name): string
{
    return '"'.str_replace('"', '""', $name).'"';
}

/** Los valores preexistentes permanecen en PostgreSQL: solo se recuperan sus resúmenes. */
function datos(PDO $pdo, array $columns, array $migrations): array
{
    $result = [];
    foreach ($columns as $table => $names) {
        $projection = implode(',', array_map(identificador(...), $names));
        $where = $table === 'migrations' ? ' WHERE migration NOT IN ('.$pdo->quote($migrations[0]).','.$pdo->quote($migrations[1]).')' : '';
        $sql = 'SELECT count(*)::text AS n, md5(coalesce(string_agg(h, \'\' ORDER BY h), \'\')) AS h FROM '
            .'(SELECT md5(row_to_json(t)::text) AS h FROM (SELECT '.$projection.' FROM public.'.identificador($table).$where.') t) filas';
        $result[$table] = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    }

    return $result;
}

try {
    exigir(is_dir($backupDir) && is_writable($backupDir), 'Directorio privado de respaldo no disponible.');
    $root = dirname(__DIR__);
    $config = Dotenv::parse(file_get_contents($root.'/.env'));
    foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432', 'DB_DATABASE' => 'horus_group'] as $key => $value) {
        exigir(($config[$key] ?? null) === $value, 'Configuración distinta del destino autorizado: '.$key);
    }
    exigir(empty($config['DB_URL']) && ! getenv('HORUS_PG_TEST_PROFILE'), 'Perfil alternativo no permitido.');
    $manifest = json_decode(file_get_contents($root.'/docs/consolidacion-fase-4-manifiesto.json'), true, 512, JSON_THROW_ON_ERROR);
    $hashes = [];
    foreach ($files as $name) {
        $path = 'database/migrations/'.$name.'.php';
        $entry = array_values(array_filter($manifest['migrations'], static fn (array $m): bool => $m['file'] === $path));
        exigir(count($entry) === 1 && hash_equals($entry[0]['sha256'], hash_file('sha256', $root.'/'.$path)), 'Migración distinta de la probada.');
        $hashes[$name] = $entry[0]['sha256'];
    }
    $pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=horus_group;options='-c default_transaction_read_only=on -c lock_timeout=2000'",
        $config['DB_USERNAME'], $config['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = identidad($pdo);
    exigir($pdo->query("SELECT current_setting('transaction_read_only')")->fetchColumn() === 'on', 'Preflight requiere solo lectura.');
    $pending = $pdo->prepare('SELECT count(*) FROM public.migrations WHERE migration IN (?,?)');
    $pending->execute($files);
    exigir((int) $pending->fetchColumn() === 0, 'Alguna migración autorizada ya figura aplicada.');
    exigir($pdo->query("SELECT to_regclass('public.servicio_publicacion_elementos') IS NULL")->fetchColumn() === true, 'Tabla editorial ya existente.');
    $partial = $pdo->query("SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND
        ((table_name='servicio_publicaciones' AND column_name IN ('titulo_tarjetas','imagen_principal_ruta','imagen_principal_alt'))
        OR (table_name='chat_logs' AND column_name='fuente'))")->fetchColumn();
    exigir((int) $partial === 0, 'Esquema parcial o ya ampliado: no se modifica.');

    $backup = $backupDir.DIRECTORY_SEPARATOR.'horus_group-pre-000033-000034.dump';
    exigir(! file_exists($backup), 'El respaldo ya existe; no se sobrescribe.');
    $bin = 'C:/Program Files/PostgreSQL/18/bin/';
    $env = ['PGPASSWORD' => $config['DB_PASSWORD'], 'PGOPTIONS' => '-c default_transaction_read_only=on',
        'PGSERVICE' => false, 'PGSERVICEFILE' => false, 'PGPASSFILE' => false];
    $dump = new Process([$bin.'pg_dump.exe', '--host=127.0.0.1', '--port=5432', '--username='.$config['DB_USERNAME'],
        '--dbname=horus_group', '--no-password', '--format=custom', '--file='.$backup], $root, $env);
    $dump->setTimeout(300);
    exigir($dump->run() === 0 && is_file($backup) && filesize($backup) > 0, 'Falló el respaldo: no se aplican migraciones.');
    // Lee y descomprime todo el archivo sin conectarse ni ejecutar SQL de restauración.
    $verify = new Process([$bin.'pg_restore.exe', '--exit-on-error', '--file=NUL', $backup], $root);
    $verify->setTimeout(300);
    exigir($verify->run() === 0, 'Falló la lectura íntegra del respaldo.');
    $backupHash = hash_file('sha256', $backup);
    file_put_contents($backup.'.sha256', $backupHash.'  '.basename($backup).PHP_EOL, LOCK_EX);
    echo "Respaldo privado creado y lectura íntegra verificada.\n";

    $app = require $root.'/bootstrap/app.php';
    exigir(! $app->configurationIsCached(), 'Configuración cacheada: se detiene sin limpiarla.');
    $app->make(Kernel::class)->bootstrap();
    exigir(config('database.default') === 'pgsql', 'Conexión predeterminada distinta.');
    foreach (['host' => '127.0.0.1', 'port' => '5432', 'database' => 'horus_group',
        'username' => $config['DB_USERNAME'], 'password' => $config['DB_PASSWORD']] as $key => $value) {
        exigir((string) config('database.connections.pgsql.'.$key) === $value, 'Configuración Laravel incompatible.');
    }
    $connection = DB::connection('pgsql');
    $connection->setPdo($pdo);
    $connection->setReadPdo($pdo);
    identidad($pdo);
    $pdo->exec('SET default_transaction_read_only = off');
    $pdo->exec("SET lock_timeout = '2000ms'");
    $connection->beginTransaction();
    exigir($pdo->query('SELECT pg_try_advisory_xact_lock(770108,3334)')->fetchColumn() === true, 'Otra aplicación conserva el bloqueo.');
    $columns = [];
    foreach ($pdo->query("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position") as $column) {
        $columns[$column['table_name']][] = $column['column_name'];
    }
    $tables = implode(',', array_map(static fn (string $t): string => 'public.'.identificador($t), array_keys($columns)));
    $pdo->exec('LOCK TABLE '.$tables.' IN SHARE MODE');
    $before = datos($pdo, $columns, $files);
    $sequenceBefore = [];
    foreach ($pdo->query("SELECT sequencename FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename") as $sequence) {
        $name = $sequence['sequencename'];
        $sequenceBefore[$name] = $pdo->query('SELECT last_value::text,is_called FROM public.'.identificador($name))->fetch(PDO::FETCH_ASSOC);
    }
    foreach ($hashes as $name => $hash) {
        exigir(hash_equals($hash, hash_file('sha256', $root.'/database/migrations/'.$name.'.php')), 'Cambió una migración antes de ejecutarla.');
    }
    $paths = array_map(static fn (string $name): string => $root.'/database/migrations/'.$name.'.php', $files);
    $app->make('migrator')->run($paths, ['pretend' => false, 'step' => false]);
    exigir($connection->transactionLevel() === 1 && $pdo->inTransaction(), 'Se perdió la transacción exterior.');
    exigir($before === datos($pdo, $columns, $files), 'Los datos preexistentes no coinciden: se revierte.');
    $registered = $pdo->prepare('SELECT migration,batch FROM public.migrations WHERE migration IN (?,?) ORDER BY migration');
    $registered->execute($files);
    $rows = $registered->fetchAll(PDO::FETCH_ASSOC);
    exigir(count($rows) === 2 && $rows[0]['batch'] === $rows[1]['batch'], 'Registro de migraciones incompleto.');
    $checks = $pdo->query("SELECT conname,pg_get_constraintdef(oid) AS definicion,convalidated,conenforced FROM pg_constraint
        WHERE conrelid='public.servicio_publicacion_elementos'::regclass AND contype='c' ORDER BY conname")->fetchAll(PDO::FETCH_ASSOC);
    exigir(count($checks) === 2, 'Faltan CHECK editoriales.');
    foreach ($checks as $check) {
        exigir($check['convalidated'] === true && $check['conenforced'] === true, 'CHECK no validado o no aplicado.');
        if ($check['conname'] === 'servicio_elementos_orden_rango') {
            exigir((bool) preg_match('/orden >= 0/', $check['definicion']) && (bool) preg_match('/orden <= 9999/', $check['definicion']), 'Rango editorial incorrecto.');
        }
    }
    $fk = $pdo->query("SELECT count(*) FROM pg_constraint WHERE conrelid='public.servicio_publicacion_elementos'::regclass
        AND contype='f' AND confrelid='public.servicio_publicaciones'::regclass AND confdeltype='r'")->fetchColumn();
    exigir((int) $fk === 1, 'FK editorial RESTRICT ausente.');
    $source = $pdo->query("SELECT data_type,character_maximum_length,is_nullable FROM information_schema.columns
        WHERE table_schema='public' AND table_name='chat_logs' AND column_name='fuente'")->fetch(PDO::FETCH_ASSOC);
    exigir($source && $source['data_type'] === 'character varying' && (int) $source['character_maximum_length'] === 16
        && $source['is_nullable'] === 'YES', 'Columna fuente incompatible.');
    exigir((int) $pdo->query('SELECT count(*) FROM public.chat_logs WHERE fuente IS NOT NULL')->fetchColumn() === 0, 'Se atribuyó fuente a históricos.');
    exigir((int) $pdo->query('SELECT count(*) FROM public.servicio_publicacion_elementos')->fetchColumn() === 0, 'Se generó contenido editorial automáticamente.');
    foreach ($sequenceBefore as $name => $state) {
        if ($name !== 'migrations_id_seq') {
            exigir($state === $pdo->query('SELECT last_value::text,is_called FROM public.'.identificador($name))->fetch(PDO::FETCH_ASSOC), 'Cambió una secuencia preexistente ajena a migrations.');
        }
    }
    $connection->commit();
    $committed = true;
    $pdo->exec('SET default_transaction_read_only = on');
    identidad($pdo);
    exigir($before === datos($pdo, $columns, $files), 'La comprobación posterior de datos requiere revisión.');
    $report = ['database' => $identity, 'backup' => $backup, 'backup_sha256' => $backupHash,
        'migrations' => $rows, 'migration_sha256' => $hashes, 'committed' => true,
        'preexisting_data_unchanged' => true, 'historical_source_null' => true, 'editorial_rows_created' => 0];
    file_put_contents($backupDir.DIRECTORY_SEPARATOR.'resultado-aplicacion.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if (! $committed && $connection !== null && $connection->transactionLevel() > 0) {
        try {
            $connection->rollBack(0);
        } catch (Throwable) {
            fwrite(STDERR, "No se pudo comprobar la reversión; no reintentar ni restaurar automáticamente.\n");
        }
    }
    $safe = get_class($error) === RuntimeException::class ? $error->getMessage() : 'Error técnico; detalles privados omitidos.';
    fwrite(STDERR, ($committed ? 'COMMIT confirmado; detener y revisar: ' : 'Aplicación no confirmada; detener: ').$safe.PHP_EOL);
    exit(1);
}
