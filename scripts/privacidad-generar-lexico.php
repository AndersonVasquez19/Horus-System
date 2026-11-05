<?php

declare(strict_types=1);

/**
 * Genera app/Support/Privacidad/lexico-publico.php: vocabulario común que el filtro de privacidad
 * reconoce como «no nombre propio». Solo lectura de fuentes del repositorio; no abre red ni base.
 *
 * Fuentes:
 *  1. lexico-base.txt / lexico-general.txt  palabras curadas a mano (por categorías semánticas).
 *  2. lexico-verbos.txt    infinitivos regulares; se generan sus formas conjugadas.
 *  3. Contenido público    texto de resources/views (sin admin), knowledge.json, intents.json, conversations.json
 *                          y las consultas del split «dev» de evaluation.json. Solo palabras que aparecen al menos
 *                          una vez en minúscula (los nombres propios se escriben con mayúscula inicial).
 *
 * Uso: php scripts/privacidad-generar-lexico.php
 */
$raiz = dirname(__DIR__);
$dir = $raiz.'/app/Support/Privacidad';

function normalizar(string $t): string
{
    $t = mb_strtolower($t, 'UTF-8');
    if (class_exists(Normalizer::class)) {
        $t = Normalizer::normalize($t, Normalizer::FORM_KD) ?: $t;
        $t = preg_replace('/\p{Mn}+/u', '', $t) ?? $t;
    }

    return strtr($t, ['ñ' => 'n']);
}

/** @return list<string> */
function palabrasMinuscula(string $texto): array
{
    preg_match_all('/(?<![\p{L}\p{Mn}\'’])\p{Ll}[\p{Ll}\p{Mn}]+(?![\p{L}\p{Mn}\'’])/u', $texto, $m);

    return $m[0];
}

$palabras = [];
$conteo = ['base' => 0, 'verbos' => 0, 'sitio' => 0, 'datos' => 0];

// Valor 1: sustantivos/adjetivos (admiten plural y cambio de género). Valor 2: funcionales y formas verbales,
// que NO admiten el cambio a↔o (evita que «daría» haga «conocido» a «Darío»).
foreach (['lexico-base.txt', 'lexico-general.txt'] as $archivoLexico) {
    $valor = 1;
    foreach (file($dir.'/'.$archivoLexico, FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
        if ($linea === '') {
            continue;
        }
        if ($linea[0] === '#') {
            if (str_starts_with($linea, '# ---')) {
                $valor = (str_contains($linea, 'funcionales') || str_contains($linea, 'verbos')) ? 2 : 1;
            }

            continue;
        }
        foreach (preg_split('/\s+/', trim($linea), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            $clave = normalizar($p);
            $palabras[$clave] = isset($palabras[$clave]) ? min($palabras[$clave], $valor) : $valor;
        }
    }
}

// Conjugación regular. Cubre presente, pretéritos, futuro, condicional, subjuntivo e imperativo frecuentes;
// se añaden clíticos al infinitivo, gerundio e imperativo («matricularme», «ayúdame»).
$terminaciones = [
    'ar' => ['o', 'as', 'a', 'amos', 'an', 'e', 'es', 'emos', 'en', 'aba', 'abas', 'abamos', 'aban', 'aste', 'aron',
        'are', 'ara', 'aras', 'aremos', 'aran', 'aria', 'arias', 'ariamos', 'arian', 'ase', 'aramos', 'ando', 'ado', 'ada', 'ados', 'adas', 'ar'],
    'er' => ['o', 'es', 'e', 'emos', 'en', 'a', 'as', 'amos', 'an', 'ia', 'ias', 'iamos', 'ian', 'i', 'iste', 'io', 'imos', 'ieron',
        'ere', 'era', 'eras', 'eremos', 'eran', 'eria', 'erias', 'eriamos', 'erian', 'iera', 'ieras', 'ieran', 'iendo', 'ido', 'ida', 'idos', 'idas', 'er'],
    'ir' => ['o', 'es', 'e', 'imos', 'en', 'a', 'as', 'amos', 'an', 'ia', 'ias', 'iamos', 'ian', 'i', 'iste', 'io', 'ieron',
        'ire', 'ira', 'iras', 'iremos', 'iran', 'iria', 'irias', 'iriamos', 'irian', 'iera', 'ieras', 'ieran', 'iendo', 'ido', 'ida', 'idos', 'idas', 'ir'],
];
$cliticos = ['me', 'te', 'se', 'nos', 'lo', 'la', 'le', 'les', 'los', 'las', 'melo', 'telo', 'selo', 'mela', 'tela', 'sela'];
foreach (file($dir.'/lexico-verbos.txt', FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
    if ($linea === '' || $linea[0] === '#') {
        continue;
    }
    foreach (preg_split('/\s+/', trim($linea), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $inf) {
        $inf = normalizar($inf);
        $tipo = substr($inf, -2);
        if (! isset($terminaciones[$tipo])) {
            continue;
        }
        $raizVerbo = substr($inf, 0, -2);
        $formas = [$inf];
        foreach ($terminaciones[$tipo] as $t) {
            $formas[] = $raizVerbo.$t;
        }
        $basesConClitico = [$inf, $raizVerbo.($tipo === 'ar' ? 'ando' : 'iendo'), $raizVerbo.($tipo === 'ar' ? 'a' : 'e'), $raizVerbo.($tipo === 'ar' ? 'e' : 'a')];
        foreach ($basesConClitico as $base) {
            foreach ($cliticos as $c) {
                $formas[] = $base.$c;
            }
        }
        foreach ($formas as $f) {
            $palabras[$f] ??= 2;
        }
    }
}

// Contenido público: vistas del sitio (sin el panel administrativo).
$vistas = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz.'/resources/views', FilesystemIterator::SKIP_DOTS));
foreach ($vistas as $archivo) {
    $ruta = str_replace('\\', '/', (string) $archivo);
    if (! str_ends_with($ruta, '.blade.php') || str_contains($ruta, '/admin/')) {
        continue;
    }
    $t = (string) file_get_contents($ruta);
    $t = preg_replace(['/\{\{--.*?--\}\}/s', '/<script\b.*?<\/script>/is', '/<style\b.*?<\/style>/is', '/@php.*?@endphp/s', '/\{!!.*?!!\}/s', '/\{\{.*?\}\}/s', '/@\w+(\([^)]*\))?/', '/<[^>]+>/', '/&\w+;/'], ' ', $t) ?? $t;
    foreach (palabrasMinuscula($t) as $p) {
        $palabras[normalizar($p)] ??= 1;
    }
}

// Datos públicos del asistente.
$datos = $raiz.'/chatbot-python/data';
$textos = [];
$recorrer = static function (mixed $nodo) use (&$recorrer, &$textos): void {
    if (is_string($nodo)) {
        $textos[] = $nodo;
    } elseif (is_array($nodo)) {
        foreach ($nodo as $v) {
            $recorrer($v);
        }
    }
};
foreach (['knowledge', 'intents', 'conversations'] as $f) {
    $recorrer(json_decode((string) file_get_contents("$datos/$f.json"), true));
}
foreach ((json_decode((string) file_get_contents("$datos/evaluation.json"), true)['items'] ?? []) as $item) {
    if (($item['split'] ?? '') === 'dev' && is_string($item['query'] ?? null)) {
        $textos[] = $item['query'];
    }
}
foreach ($textos as $t) {
    foreach (palabrasMinuscula($t) as $p) {
        $palabras[normalizar($p)] ??= 1;
    }
}

// Higiene: solo letras latinas ASCII tras normalizar, longitud razonable.
$salida = [];
foreach ($palabras as $p => $valor) {
    $p = (string) $p;
    if ($p === '' || strlen($p) > 30 || preg_match('/^[a-z]+$/', $p) !== 1) {
        continue;
    }
    $salida[$p] = $valor;
}
ksort($salida);

$lineas = '';
foreach ($salida as $palabra => $valor) {
    $lineas .= "    '$palabra' => $valor,
";
}
$cabecera = '<?php

// Generado por scripts/privacidad-generar-lexico.php. No editar a mano: edite lexico-base.txt / lexico-general.txt / lexico-verbos.txt y regenere.
'
    .'// Palabras: '.count($salida).' · valor 1 = admite plural/género · valor 2 = funcional o forma verbal (solo coincidencia exacta)

return [
';
file_put_contents($dir.'/lexico-publico.php', $cabecera.$lineas.'];
');
echo 'Palabras: '.count($salida).' (valor 1: '.count(array_filter($salida, static fn (int $v): bool => $v === 1)).')
';
