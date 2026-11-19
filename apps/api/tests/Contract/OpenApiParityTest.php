<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\Yaml\Yaml;

/*
 * Paridad contrato ↔ rutas (docs/horus-academic/06-contratos-openapi.md §5).
 *
 *   (a) Toda operación `vigente` del contrato (sin `x-estado`, o `x-estado: vigente`) existe en el enrutador con el mismo
 *       método y la misma ruta.
 *   (b) Toda ruta de `routes/panel.php` bajo `auth/` y `academico/` figura en el contrato.
 *   (c) El permiso que el contrato declara en `description` (`Permiso: …`) coincide con un middleware `permission:` de esa ruta
 *       (del grupo o del controlador).
 *   (d) Ninguna operación `propuesto` existe todavía en el servidor: al implementarla se cambia a `vigente` en el mismo cambio.
 *
 * Alcance acotado a `auth/` y `academico/`: los módulos `compartido/` y `servicios/` aún no figuran completos en el contrato
 * (los de servicios se retiran en la Fase 7). No necesita base de datos.
 */

const PREFIJO_PANEL = 'api/panel/v1/';

/** @return array<string, array<string, mixed>> */
function contratoPanel(): array
{
    $ruta = dirname(base_path(), 2).'/packages/contracts/openapi/horus-panel-api.yaml';
    expect(is_file($ruta))->toBeTrue();

    /** @var array{paths: array<string, array<string, mixed>>} $contrato */
    $contrato = Yaml::parse((string) file_get_contents($ruta));

    return $contrato['paths'];
}

function normalizar(string $ruta): string
{
    return (string) preg_replace('/\{[^}]+\}/', '{}', trim($ruta, '/'));
}

/** @return array<string, Route> clave «METODO /ruta-normalizada» sin el prefijo del panel */
function rutasDelPanel(): array
{
    $rutas = [];
    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), PREFIJO_PANEL)) {
            continue;
        }
        foreach ($route->methods() as $metodo) {
            if ($metodo === 'HEAD') {
                continue;
            }
            $rutas[$metodo.' '.normalizar(substr($route->uri(), strlen(PREFIJO_PANEL)))] = $route;
        }
    }

    return $rutas;
}

/** @return array<string, array<string, mixed>> clave «METODO /ruta-normalizada» */
function operacionesDelContrato(): array
{
    $operaciones = [];
    foreach (contratoPanel() as $ruta => $metodos) {
        foreach ($metodos as $metodo => $operacion) {
            if (! in_array($metodo, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }
            $operaciones[strtoupper($metodo).' '.normalizar($ruta)] = $operacion + ['_ruta' => $ruta];
        }
    }

    return $operaciones;
}

/** @return list<string> permisos `permission:` de la ruta (grupo y controlador), ya separados por `|` */
function permisosDeLaRuta(Route $route): array
{
    $permisos = [];
    foreach ([...$route->gatherMiddleware(), ...$route->controllerMiddleware()] as $middleware) {
        if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
            $permisos[] = substr($middleware, strlen('permission:'));
        }
    }

    return $permisos;
}

it('toda operación vigente del contrato existe en el enrutador con el mismo método y ruta', function (): void {
    $rutas = rutasDelPanel();
    $faltan = [];
    foreach (operacionesDelContrato() as $clave => $operacion) {
        if (($operacion['x-estado'] ?? 'vigente') === 'vigente' && ! isset($rutas[$clave])) {
            $faltan[] = $clave;
        }
    }

    expect($faltan)->toBe([], 'Operaciones vigentes del contrato que no existen en routes/panel.php');
});

it('toda ruta del panel bajo auth/ y academico/ figura en el contrato', function (): void {
    $contrato = operacionesDelContrato();
    $sinContrato = [];
    foreach (rutasDelPanel() as $clave => $route) {
        [, $ruta] = explode(' ', $clave, 2);
        if ((str_starts_with($ruta, 'auth/') || str_starts_with($ruta, 'academico/')) && ! isset($contrato[$clave])) {
            $sinContrato[] = $clave;
        }
    }

    expect($sinContrato)->toBe([], 'Rutas de routes/panel.php sin documentar en horus-panel-api.yaml');
});

it('el permiso que el contrato declara coincide con el middleware de la ruta', function (): void {
    $rutas = rutasDelPanel();
    $distintos = [];
    foreach (operacionesDelContrato() as $clave => $operacion) {
        if (($operacion['x-estado'] ?? 'vigente') !== 'vigente' || ! isset($rutas[$clave]) || ! str_starts_with((string) $operacion['_ruta'], '/academico')) {
            continue;
        }
        if (preg_match('/Permiso:\s*`([^`]+)`/', (string) ($operacion['description'] ?? ''), $m) !== 1) {
            continue;
        }
        $declarado = str_replace([' ', '|'], ['', '|'], $m[1]);
        $reales = array_map(static fn (string $p): string => str_replace(' ', '', $p), permisosDeLaRuta($rutas[$clave]));
        if (! in_array($declarado, $reales, true)) {
            $distintos[] = "{$clave}: contrato `{$declarado}` vs middleware `".implode(' , ', $reales).'`';
        }
    }

    expect($distintos)->toBe([], 'Permisos del contrato que no coinciden con el servidor');
});

it('ninguna operación propuesta existe todavía en el servidor', function (): void {
    $rutas = rutasDelPanel();
    $adelantadas = [];
    foreach (operacionesDelContrato() as $clave => $operacion) {
        if (($operacion['x-estado'] ?? null) === 'propuesto' && isset($rutas[$clave])) {
            $adelantadas[] = $clave;
        }
    }

    expect($adelantadas)->toBe([], 'Rutas ya implementadas que siguen marcadas «propuesto»: cámbialas a «vigente»');
});

it('el contrato no documenta ninguna ruta de estudiante ni de portal', function (): void {
    foreach (array_keys(contratoPanel()) as $ruta) {
        expect($ruta)->not->toMatch('#/(portal|mi-cuenta|mis-)#');
    }
});
