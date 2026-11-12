<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PDOException;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Predicado real de 000033 sobre fixtures SQLite :memory:, sin ejecutar up/down ni Artisan. */
final class ServicioElementosOrdenPreparacionTest extends TestCase
{
    private function condicion(): string
    {
        $ruta = dirname(__DIR__, 2).'/database/migrations/2026_11_02_000033_add_contenido_to_servicio_publicaciones.php';
        $arbol = (new ParserFactory)->createForHostVersion()->parse(file_get_contents($ruta));
        $asignacion = (new NodeFinder)->findFirst($arbol, fn ($nodo) => $nodo instanceof Assign
            && $nodo->var instanceof Variable && $nodo->var->name === 'checks');
        self::assertInstanceOf(Assign::class, $asignacion);
        self::assertInstanceOf(Array_::class, $asignacion->expr);
        foreach ($asignacion->expr->items as $item) {
            if ($item?->key instanceof String_ && $item->key->value === 'servicio_elementos_orden_rango') {
                self::assertInstanceOf(String_::class, $item->value);

                return $item->value->value;
            }
        }
        self::fail('No existe la restricción de rango de 000033.');
    }

    /** @return iterable<string, array{int, bool}> */
    public static function limites(): iterable
    {
        yield 'negativo' => [-1, false];
        yield 'minimo' => [0, true];
        yield 'maximo' => [9999, true];
        yield 'exceso' => [10000, false];
    }

    #[DataProvider('limites')]
    public function test_predicado_rechaza_fuera_del_rango_en_insert_y_update(int $orden, bool $valido): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Tabla sintética, no instalación de la migración: prueba el predicado sin ejecutar DDL PostgreSQL.
        $pdo->exec('CREATE TABLE elementos (id INTEGER PRIMARY KEY, orden INTEGER NOT NULL)');
        $condicion = preg_replace('/\borden\b/', 'NEW.orden', $this->condicion());
        foreach (['INSERT', 'UPDATE'] as $operacion) {
            $pdo->exec("CREATE TRIGGER rango_$operacion BEFORE $operacion ON elementos WHEN NOT ($condicion) BEGIN SELECT RAISE(ABORT, 'servicio_elementos_orden_rango'); END");
        }
        $pdo->exec('INSERT INTO elementos VALUES (1, 0)');
        foreach (["INSERT INTO elementos VALUES (2, $orden)", "UPDATE elementos SET orden = $orden WHERE id = 1"] as $sql) {
            try {
                $pdo->exec($sql);
                self::assertTrue($valido, 'Se aceptó un orden fuera del rango.');
            } catch (PDOException $error) {
                self::assertFalse($valido);
                self::assertSame('23000', $error->errorInfo[0]);
                self::assertSame(19, $error->errorInfo[1]);
                self::assertStringContainsString('servicio_elementos_orden_rango', $error->getMessage());
            }
        }
        self::assertSame($valido ? $orden : 0, (int) $pdo->query('SELECT orden FROM elementos WHERE id = 1')->fetchColumn());
    }
}
