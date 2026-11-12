<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FiltroPrivacidadChat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Corpus de DESARROLLO del filtro de privacidad (tests/Fixtures/privacidad/*.txt).
 *
 * Las etiquetas (B = bloquear, P = permitir) están escritas y fijadas aparte de la implementación: este test no
 * reutiliza ninguna expresión regular del filtro. NO es una evaluación independiente: el corpus participó en el
 * ajuste. La evaluación de aceptación la hace un auditor distinto con casos que no existen en este repositorio.
 */
class FiltroPrivacidadCorpusTest extends TestCase
{
    /** @return list<array{0: string, 1: bool, 2: string}> [categoría, debeBloquear, mensaje] */
    private static function casos(): array
    {
        $casos = [];
        foreach (['desarrollo.txt', 'errores-compartibles.txt'] as $archivo) {
            foreach (file(__DIR__.'/../Fixtures/privacidad/'.$archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
                if (str_starts_with($linea, '#')) {
                    continue;
                }
                $partes = explode('|', $linea, 3);
                self::assertCount(3, $partes, 'Línea mal formada en '.$archivo);
                $casos[] = [$partes[0], $partes[1] === 'B', $partes[2]];
            }
        }

        return $casos;
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function corpus(): iterable
    {
        foreach (self::casos() as $i => [$categoria, $bloquear, $mensaje]) {
            yield sprintf('%03d %s %s %s', $i, $categoria, $bloquear ? 'B' : 'P', mb_substr($mensaje, 0, 50)) => [$categoria, $bloquear, $mensaje];
        }
    }

    #[DataProvider('corpus')]
    public function test_cada_caso_recibe_la_decision_etiquetada(string $categoria, bool $bloquear, string $mensaje): void
    {
        $motivo = (new FiltroPrivacidadChat)->motivoBloqueo($mensaje);

        $this->assertSame($bloquear, $motivo !== null, ($bloquear ? 'Debía bloquearse: ' : 'Debía permitirse: ').$mensaje.' ['.$categoria.']');
    }

    public function test_el_corpus_tiene_al_menos_300_mensajes_nuevos_y_las_categorias_pedidas(): void
    {
        $casos = array_filter(self::casos(), static fn (array $c): bool => $c[0] !== 'ERR');
        $this->assertGreaterThanOrEqual(300, count($casos));
        $this->assertSame(38, count(self::casos()) - count($casos), 'Los 38 errores compartibles de la auditoría.');
        $categorias = array_unique(array_column($casos, 0));
        sort($categorias);
        $this->assertSame(['AMB', 'CTX', 'ID', 'LEG', 'SEG', 'SIN'], $categorias);
    }

    public function test_metricas_de_desarrollo_cumplen_los_umbrales(): void
    {
        $filtro = new FiltroPrivacidadChat;
        $vp = $fn = $vn = $fp = 0;
        $ctxVp = $ctxN = 0;
        foreach (self::casos() as [$categoria, $bloquear, $mensaje]) {
            $bloqueado = $filtro->motivoBloqueo($mensaje) !== null;
            if ($bloquear) {
                $bloqueado ? $vp++ : $fn++;
                if ($categoria !== 'SIN') {
                    $ctxN++;
                    $ctxVp += $bloqueado ? 1 : 0;
                }
            } else {
                $bloqueado ? $fp++ : $vn++;
            }
        }
        $this->assertGreaterThanOrEqual(0.95, $ctxVp / $ctxN, 'Cobertura con contexto explícito');
        $this->assertGreaterThanOrEqual(0.95, $vn / ($vn + $fp), 'Especificidad');
        $this->assertGreaterThanOrEqual(0.95, $vp / ($vp + $fp), 'Precisión');
    }

    /** Los identificadores y las inyecciones con datos son casos críticos: ninguno puede escaparse. */
    public function test_casos_criticos_de_privacidad_se_bloquean_al_cien_por_cien(): void
    {
        $filtro = new FiltroPrivacidadChat;
        foreach (self::casos() as [$categoria, $bloquear, $mensaje]) {
            if ($bloquear && in_array($categoria, ['ID', 'SEG', 'AMB'], true)) {
                $this->assertNotNull($filtro->motivoBloqueo($mensaje), $mensaje);
            }
        }
    }
}
