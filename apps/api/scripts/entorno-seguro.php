<?php

declare(strict_types=1);

use Horus\Local\EntornoSeguro;
use Symfony\Component\Process\Process;

/**
 * Ejecuta un comando con el entorno defensivo (sin llamadas externas) o muestra el perfil que aplicaría.
 * No lee ni escribe archivos de entorno ni inicia servicios por sí mismo.
 */
require dirname(__DIR__).'/vendor/autoload.php';
require_once __DIR__.'/Support/EntornoSeguro.php';

$uso = "Uso:\n"
    ."  php scripts/entorno-seguro.php mostrar [--permitir=gemini,correo] [--sin-bloqueo-de-red]\n"
    ."  php scripts/entorno-seguro.php ejecutar [--permitir=gemini,correo] [--sin-bloqueo-de-red] -- <comando> [argumentos]\n"
    .'Familias permitibles: '.implode(', ', EntornoSeguro::familias())."\n";

$accion = $argv[1] ?? '';
if (! in_array($accion, ['mostrar', 'ejecutar'], true)) {
    fwrite(STDERR, $uso);
    exit(2);
}

$resto = array_slice($argv, 2);
$separador = array_search('--', $resto, true);
$opciones = $separador === false ? $resto : array_slice($resto, 0, $separador);
$comando = $separador === false ? [] : array_slice($resto, $separador + 1);

$permitir = [];
$bloqueo = true;
foreach ($opciones as $opcion) {
    if (str_starts_with($opcion, '--permitir=')) {
        $permitir = array_values(array_filter(explode(',', substr($opcion, 11)), static fn (string $f): bool => $f !== ''));
    } elseif ($opcion === '--sin-bloqueo-de-red') {
        $bloqueo = false;
    } else {
        fwrite(STDERR, 'Opción desconocida: '.$opcion."\n".$uso);
        exit(2);
    }
}

try {
    $entorno = EntornoSeguro::permitiendo($permitir);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(2);
}
if ($bloqueo) {
    $entorno += EntornoSeguro::bloqueoDeRed();
}

$resumen = 'permitido: '.($permitir === [] ? 'nada' : implode(', ', $permitir)).'; bloqueo de red: '.($bloqueo ? 'sí' : 'no');

if ($accion === 'mostrar') {
    echo "Perfil defensivo ({$resumen})\n";
    foreach ($entorno as $nombre => $valor) {
        echo '  '.$nombre.' = '.($valor === '' ? '<vacía>' : $valor)."\n";
    }
    exit(0);
}

if ($comando === []) {
    fwrite(STDERR, "Falta el comando tras `--`.\n".$uso);
    exit(2);
}

fwrite(STDERR, "[entorno-seguro] {$resumen}\n");
$proceso = new Process($comando, getcwd() ?: dirname(__DIR__), $entorno);
$proceso->setTimeout(null);
$salida = $proceso->run(static function (string $tipo, string $datos): void {
    fwrite($tipo === Process::ERR ? STDERR : STDOUT, $datos);
});

exit($salida);
