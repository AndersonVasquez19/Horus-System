<?php

declare(strict_types=1);

use App\Services\AsistenteVirtualService;
use App\Support\FiltroPrivacidadChat;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Cliente de la prueba de integración real Laravel → FastAPI (lo lanza run-privacidad-integracion.py).
 *
 * Seguridad del entorno: exige HORUS_PRIVACIDAD_INTEGRACION=1, SQLite :memory:, Horus AI en 127.0.0.1 y un token
 * efímero entregado por el runner. No toca PostgreSQL, .env ni servicios externos. Los corpus son ficticios.
 *
 * Uso (solo desde el runner): php tests/Support/privacidad-integracion.php <bloqueados|permitidos> <corpus.txt>...
 * Salida: una línea JSON con contadores; nunca imprime mensajes ni respuestas.
 */
if (PHP_SAPI !== 'cli' || getenv('HORUS_PRIVACIDAD_INTEGRACION') !== '1') {
    fwrite(STDERR, "Ejecute este script solo mediante run-privacidad-integracion.py (opt-in explícito).\n");
    exit(2);
}
$modo = $argv[1] ?? '';
if (! in_array($modo, ['bloqueados', 'permitidos'], true) || count($argv) < 3) {
    fwrite(STDERR, "Uso: privacidad-integracion.php <bloqueados|permitidos> <corpus.txt>...\n");
    exit(2);
}

$raiz = dirname(__DIR__, 2);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.example');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
    fwrite(STDERR, "Se requiere SQLite en memoria.\n");
    exit(3);
}
if (! str_starts_with((string) config('horus-advisor.url'), 'http://127.0.0.1:') || ! config('horus-advisor.enabled')) {
    fwrite(STDERR, "Horus AI debe apuntar a loopback y estar habilitado solo para esta prueba.\n");
    exit(3);
}
Artisan::call('migrate', ['--force' => true]);

$filtro = new FiltroPrivacidadChat;
$mensajes = [];
foreach (array_slice($argv, 2) as $archivo) {
    foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
        if (str_starts_with($linea, '#')) {
            continue;
        }
        $partes = explode('|', $linea, 3);
        if (count($partes) !== 3) {
            continue;
        }
        $bloquear = $partes[1] === 'B';
        if (($modo === 'bloqueados') === $bloquear) {
            $mensajes[] = $partes[2];
        }
    }
}

$servicio = app(AsistenteVirtualService::class);
$resumen = ['modo' => $modo, 'enviados' => 0, 'respuesta_privacidad' => 0, 'fuente_advisor' => 0, 'fuente_respaldo_otro' => 0, 'filtro_dice_seguro' => 0];
$unicos = [];
foreach ($mensajes as $i => $mensaje) {
    $resumen['enviados']++;
    $seguro = $filtro->motivoBloqueo($mensaje) === null;
    $resumen['filtro_dice_seguro'] += $seguro ? 1 : 0;
    $unicos[$mensaje] = true;
    $resultado = $servicio->procesarMensaje($mensaje, 'integracion-'.$i);
    if ($resultado['respuesta'] === AsistenteVirtualService::RESPUESTA_PRIVACIDAD) {
        $resumen['respuesta_privacidad']++;
    } elseif ($resultado['fuente'] === 'advisor') {
        $resumen['fuente_advisor']++;
    } else {
        $resumen['fuente_respaldo_otro']++;
    }
}
$resumen['mensajes_unicos'] = count($unicos);
$resumen['chatlog_filas'] = DB::table('chat_logs')->count();
$resumen['chatlog_con_contenido'] = DB::table('chat_logs')->where('mensaje_usuario', '!=', '[contenido omitido por privacidad]')->count();

echo json_encode($resumen, JSON_UNESCAPED_UNICODE), "\n";
