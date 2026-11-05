<?php

declare(strict_types=1);

/**
 * Evalúa FiltroPrivacidadChat contra corpus etiquetados (CATEGORIA|B/P|MENSAJE).
 *
 * Uso: php scripts/privacidad-evaluar.php <corpus.txt> [<corpus2.txt> ...] [--errores] [--json]
 *
 * Solo lectura: no abre red, base de datos ni servicios. Los corpus contienen identidades ficticias.
 * Las categorías CTX, ID, SEG|B y AMB cuentan para «cobertura con contexto»; SIN se mide aparte;
 * LEG y SEG|P cuentan para la especificidad. Nada se excluye ni se reetiqueta.
 */

require __DIR__.'/../vendor/autoload.php';

use App\Support\FiltroPrivacidadChat;

$mostrarErrores = in_array('--errores', $argv, true);
$json = in_array('--json', $argv, true);
$archivos = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => ! str_starts_with($a, '--')));
if ($archivos === []) {
    fwrite(STDERR, "Indique al menos un corpus.\n");
    exit(2);
}

/** @return array{0: float, 1: float} */
function wilson(int $exitos, int $n): array
{
    if ($n === 0) {
        return [0.0, 0.0];
    }
    $z = 1.96;
    $p = $exitos / $n;
    $den = 1 + $z * $z / $n;
    $centro = ($p + $z * $z / (2 * $n)) / $den;
    $margen = $z * sqrt(($p * (1 - $p) + $z * $z / (4 * $n)) / $n) / $den;

    return [max(0.0, $centro - $margen), min(1.0, $centro + $margen)];
}

$filtro = new FiltroPrivacidadChat;
$casos = [];
foreach ($archivos as $archivo) {
    foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
        if (str_starts_with($linea, '#')) {
            continue;
        }
        $partes = explode('|', $linea, 3);
        if (count($partes) === 3) {
            $casos[] = ['cat' => $partes[0], 'esperado' => $partes[1] === 'B', 'msg' => $partes[2]];
        }
    }
}

$inicio = hrtime(true);
$tiempos = [];
$res = [];
$errores = [];
foreach ($casos as $c) {
    $t = hrtime(true);
    $motivo = $filtro->motivoBloqueo($c['msg']);
    $tiempos[] = (hrtime(true) - $t) / 1e6;
    $bloqueado = $motivo !== null;
    $clave = $c['cat'].'|'.($c['esperado'] ? 'B' : 'P');
    $res[$clave] ??= ['n' => 0, 'ok' => 0];
    $res[$clave]['n']++;
    if ($bloqueado === $c['esperado']) {
        $res[$clave]['ok']++;
    } else {
        $errores[] = ($c['esperado'] ? 'FN' : 'FP').' '.$c['cat'].' ['.($motivo ?? '-').'] '.$c['msg'];
    }
}

// Agregados. Positivo = debe bloquearse.
$grupos = [
    'contexto' => static fn (string $k): bool => in_array($k, ['CTX|B', 'ID|B', 'SEG|B', 'AMB|B', 'ERR|B'], true),
    'sin_contexto' => static fn (string $k): bool => $k === 'SIN|B',
];
$tp = $fn = $tn = $fp = 0;
$ctxTp = $ctxN = $sinTp = $sinN = 0;
foreach ($res as $k => $v) {
    $esB = str_ends_with($k, '|B');
    if ($esB) {
        if ($grupos['sin_contexto']($k)) {
            $sinTp += $v['ok'];
            $sinN += $v['n'];
        } else {
            $ctxTp += $v['ok'];
            $ctxN += $v['n'];
        }
        $tp += $v['ok'];
        $fn += $v['n'] - $v['ok'];
    } else {
        $tn += $v['ok'];
        $fp += $v['n'] - $v['ok'];
    }
}
$pct = static fn (int $a, int $b): float => $b > 0 ? 100 * $a / $b : 0.0;
sort($tiempos);
$p = static fn (float $q): float => $tiempos[(int) min(count($tiempos) - 1, floor($q * count($tiempos)))] ?? 0.0;

$salida = [
    'casos' => count($casos),
    'matriz' => ['VP' => $tp, 'FN' => $fn, 'FP' => $fp, 'VN' => $tn],
    'cobertura_global' => round($pct($tp, $tp + $fn), 1),
    'cobertura_con_contexto' => ['n' => $ctxN, 'bloqueados' => $ctxTp, 'pct' => round($pct($ctxTp, $ctxN), 1), 'ic95' => array_map(static fn (float $x): float => round(100 * $x, 1), wilson($ctxTp, $ctxN))],
    'nombres_sin_contexto' => ['n' => $sinN, 'bloqueados' => $sinTp, 'pct' => round($pct($sinTp, $sinN), 1), 'ic95' => array_map(static fn (float $x): float => round(100 * $x, 1), wilson($sinTp, $sinN))],
    'especificidad' => ['n' => $tn + $fp, 'permitidos' => $tn, 'pct' => round($pct($tn, $tn + $fp), 1), 'ic95' => array_map(static fn (float $x): float => round(100 * $x, 1), wilson($tn, $tn + $fp))],
    'precision' => round($pct($tp, $tp + $fp), 1),
    'por_categoria' => array_map(static fn (array $v): string => $v['ok'].'/'.$v['n'], $res),
    'latencia_ms' => ['p50' => round($p(0.5), 3), 'p95' => round($p(0.95), 3), 'max' => round(end($tiempos) ?: 0.0, 3), 'total_s' => round((hrtime(true) - $inicio) / 1e9, 3)],
    'memoria_pico_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
];
if ($mostrarErrores) {
    $salida['errores'] = $errores;
}

echo json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
if ($json === false && $mostrarErrores) {
    echo implode("\n", $errores), "\n";
}
