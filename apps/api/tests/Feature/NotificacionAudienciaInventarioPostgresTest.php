<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Lógica de decisión del inventario PostgreSQL de la migración 2026_10_31_000032.
 *
 * ATENCIÓN — NO ES EVIDENCIA DE POSTGRESQL. Estas pruebas no abren ninguna conexión: alimentan
 * evaluarCatalogoPostgres() con instantáneas SINTÉTICAS del catálogo (la misma forma que produce
 * catalogoPostgres()) y comprueban qué considera completo, incompleto o alterado. La evidencia PostgreSQL
 * está en tests/PostgresIntegrity/NotificacionAudienciaPostgresTest (clúster aislado 55433). Las referencias
 * se obtienen como CHECK NOT VALID en un SAVEPOINT que se deshace, por eso llevan el sufijo « NOT VALID».
 */
class NotificacionAudienciaInventarioPostgresTest extends TestCase
{
    private const MIGRACION = '2026_10_31_000032_add_audiencia_to_notificaciones_internas.php';

    private const FUNCION = 'notificaciones_internas_audiencia_coherente';

    private const FUNCION_OID = 90001;

    private const CHECKS = [
        'notificaciones_internas_audiencia_valida',
        'notificaciones_internas_general_sin_destinatario',
        'notificaciones_internas_indeterminada_sin_destinatario',
    ];

    // tgtype: ROW (1) | BEFORE (2) | INSERT (4) | UPDATE (16) = 23.
    private const TGTYPE_ESPERADO = 23;

    public function test_a_complete_catalog_is_accepted(): void
    {
        $resultado = $this->evaluar($this->catalogo());

        $this->assertTrue($resultado['columna']);
        $this->assertSame([], $resultado['faltan']);
        $this->assertEqualsCanonicalizing([...self::CHECKS, self::FUNCION.'()', 'trigger '.self::FUNCION], $resultado['presentes']);
    }

    public function test_postgres_boolean_representations_are_read_strictly(): void
    {
        $catalogo = $this->catalogo();
        $catalogo['columna']['attnotnull'] = 't';
        $catalogo['checks'][0]['convalidated'] = 't';
        $this->assertSame([], $this->evaluar($catalogo)['faltan']);

        $catalogo['checks'][0]['convalidated'] = 'f';     // (bool) 'f' sería true: no debe aceptarse
        $this->assertContains(self::CHECKS[0].' (no validada)', $this->evaluar($catalogo)['faltan']);
    }

    // --- Tabla y columna ------------------------------------------------------------------

    public function test_a_missing_table_stops_the_inventory(): void
    {
        $catalogo = $this->catalogo();
        $catalogo['tabla_oid'] = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no existe');
        $this->evaluar($catalogo);
    }

    public function test_a_missing_column_is_reported_explicitly(): void
    {
        $catalogo = $this->catalogo();
        $catalogo['columna'] = null;
        $catalogo['referencias'] = [];

        $resultado = $this->evaluar($catalogo);

        $this->assertFalse($resultado['columna']);
        $this->assertContains('columna audiencia', $resultado['faltan']);
        $this->assertContains(self::FUNCION.'()', $resultado['presentes'], 'Los objetos sobrantes siguen detectándose');
    }

    public function test_a_nullable_or_mistyped_column_is_rejected(): void
    {
        $catalogo = $this->catalogo();
        $catalogo['columna']['attnotnull'] = false;
        $this->assertContains('audiencia NOT NULL', $this->evaluar($catalogo)['faltan']);

        $catalogo = $this->catalogo();
        $catalogo['columna']['tipo'] = 'text';
        $this->assertContains('audiencia character varying(20) (tipo text)', $this->evaluar($catalogo)['faltan']);
    }

    public function test_missing_null_values_never_count_as_satisfied(): void
    {
        $catalogo = $this->catalogo();
        $catalogo['columna']['attnotnull'] = null;
        $catalogo['checks'][1]['convalidated'] = null;
        $catalogo['checks'][2]['conenforced'] = null;

        $faltan = $this->evaluar($catalogo)['faltan'];

        $this->assertContains('audiencia NOT NULL', $faltan);
        $this->assertContains(self::CHECKS[1].' (no validada)', $faltan);
        $this->assertContains(self::CHECKS[2].' (no aplicada)', $faltan);
    }

    // --- Restricciones CHECK --------------------------------------------------------------

    public function test_a_check_with_the_right_name_but_a_different_definition_is_rejected(): void
    {
        $catalogo = $this->catalogo();
        $catalogo['checks'][0]['definicion'] = 'CHECK (true)';

        $this->assertContains(self::CHECKS[0].' (definición distinta)', $this->evaluar($catalogo)['faltan']);
    }

    public function test_a_check_without_reference_definition_is_not_accepted(): void
    {
        $catalogo = $this->catalogo();
        unset($catalogo['referencias'][self::CHECKS[1]]);

        $this->assertContains(self::CHECKS[1].' (definición distinta)', $this->evaluar($catalogo)['faltan']);
    }

    public function test_the_not_valid_suffix_is_ignored_only_on_the_reference_definition(): void
    {
        // Real «NOT VALID» con convalidated (incoherente) también difiere: el sufijo solo se quita de la referencia.
        $catalogo = $this->catalogo();
        $catalogo['checks'][0]['definicion'] .= ' NOT VALID';
        $this->assertContains(self::CHECKS[0].' (definición distinta)', $this->evaluar($catalogo)['faltan']);

        // Solo se quita el sufijo exacto al final; cualquier otro texto sigue comparándose.
        $catalogo = $this->catalogo();
        $catalogo['referencias'][self::CHECKS[1]] = 'CHECK ((expresion_normalizada_1)) NOT VALIDO';
        $this->assertContains(self::CHECKS[1].' (definición distinta)', $this->evaluar($catalogo)['faltan']);

        // CHECK (true) no pasa aunque la referencia lleve el sufijo.
        $catalogo = $this->catalogo();
        $catalogo['checks'][2]['definicion'] = 'CHECK (true)';
        $this->assertContains(self::CHECKS[2].' (definición distinta)', $this->evaluar($catalogo)['faltan']);
    }

    public function test_a_not_validated_check_is_rejected(): void
    {
        $catalogo = $this->catalogo();
        $catalogo['checks'][1]['convalidated'] = false;

        $this->assertContains(self::CHECKS[1].' (no validada)', $this->evaluar($catalogo)['faltan']);
    }

    public function test_a_not_enforced_check_is_rejected(): void
    {
        $catalogo = $this->catalogo();
        $catalogo['checks'][2]['conenforced'] = false;

        $this->assertContains(self::CHECKS[2].' (no aplicada)', $this->evaluar($catalogo)['faltan']);
    }

    public function test_a_constraint_of_another_type_or_a_missing_check_is_rejected(): void
    {
        $catalogo = $this->catalogo();
        $catalogo['checks'][0]['contype'] = 'u';
        unset($catalogo['checks'][2]);

        $faltan = $this->evaluar($catalogo)['faltan'];

        $this->assertContains(self::CHECKS[0].' (no es CHECK)', $faltan);
        $this->assertContains(self::CHECKS[2], $faltan);
    }

    // --- Función --------------------------------------------------------------------------

    /** @return iterable<string, array{string, mixed, string}> */
    public static function funcionesIncorrectas(): iterable
    {
        yield 'con argumentos' => ['pronargs', 1, '(firma distinta)'];
        yield 'retorno distinto' => ['retorno', 'void', '(firma distinta)'];
        yield 'lenguaje distinto' => ['lenguaje', 'sql', '(firma distinta)'];
        yield 'procedimiento' => ['prokind', 'p', '(firma distinta)'];
        yield 'cuerpo distinto' => ['prosrc', 'BEGIN RETURN NEW; END;', '(definición distinta)'];
    }

    #[DataProvider('funcionesIncorrectas')]
    public function test_a_function_with_a_wrong_signature_or_body_is_rejected(string $campo, mixed $valor, string $defecto): void
    {
        $catalogo = $this->catalogo();
        $catalogo['funciones'][0][$campo] = $valor;

        $faltan = $this->evaluar($catalogo)['faltan'];

        $this->assertContains(self::FUNCION.'() '.$defecto, $faltan);
        // Sin función válida, el trigger tampoco puede apuntar a «la» función esperada.
        $this->assertContains('trigger '.self::FUNCION.' (función distinta)', $faltan);
    }

    public function test_an_ambiguous_overload_is_rejected(): void
    {
        $catalogo = $this->catalogo();
        $catalogo['funciones'][] = ['oid' => 90002] + $catalogo['funciones'][0];
        $catalogo['funciones'][1]['pronargs'] = 1;

        $this->assertContains(self::FUNCION.'() (sobrecargas ambiguas)', $this->evaluar($catalogo)['faltan']);
    }

    public function test_a_missing_function_is_rejected(): void
    {
        $catalogo = $this->catalogo();
        $catalogo['funciones'] = [];

        $faltan = $this->evaluar($catalogo)['faltan'];

        $this->assertContains(self::FUNCION.'()', $faltan);
        $this->assertContains('trigger '.self::FUNCION.' (función distinta)', $faltan);
    }

    // --- Trigger --------------------------------------------------------------------------

    /** @return iterable<string, array{string, mixed, string}> */
    public static function triggersIncorrectos(): iterable
    {
        yield 'deshabilitado' => ['tgenabled', 'D', '(deshabilitado o solo réplica)'];
        yield 'solo réplica' => ['tgenabled', 'R', '(deshabilitado o solo réplica)'];
        yield 'solo INSERT' => ['tgtype', 1 | 2 | 4, '(eventos o modalidad distintos)'];
        yield 'solo UPDATE' => ['tgtype', 1 | 2 | 16, '(eventos o modalidad distintos)'];
        yield 'con DELETE añadido' => ['tgtype', self::TGTYPE_ESPERADO | 8, '(eventos o modalidad distintos)'];
        yield 'AFTER' => ['tgtype', 1 | 4 | 16, '(eventos o modalidad distintos)'];
        yield 'por sentencia' => ['tgtype', 2 | 4 | 16, '(eventos o modalidad distintos)'];
        yield 'función distinta' => ['tgfoid', 90099, '(función distinta)'];
        yield 'con WHEN' => ['con_when', true, '(condición WHEN inesperada)'];
        yield 'interno' => ['tgisinternal', true, '(interno o de restricción)'];
        yield 'de restricción' => ['de_restriccion', true, '(interno o de restricción)'];
        yield 'con argumentos' => ['tgnargs', 1, '(argumentos o columnas inesperados)'];
        yield 'UPDATE OF columnas' => ['columnas', 1, '(argumentos o columnas inesperados)'];
    }

    #[DataProvider('triggersIncorrectos')]
    public function test_an_altered_trigger_is_rejected(string $campo, mixed $valor, string $defecto): void
    {
        $catalogo = $this->catalogo();
        $catalogo['triggers'][0][$campo] = $valor;

        $this->assertContains('trigger '.self::FUNCION.' '.$defecto, $this->evaluar($catalogo)['faltan']);
    }

    public function test_a_trigger_enabled_always_is_accepted_and_a_missing_one_is_not(): void
    {
        $catalogo = $this->catalogo();
        $catalogo['triggers'][0]['tgenabled'] = 'A';
        $this->assertSame([], $this->evaluar($catalogo)['faltan']);

        $catalogo['triggers'] = [];
        $this->assertContains('trigger '.self::FUNCION, $this->evaluar($catalogo)['faltan']);
    }

    // --- Utilidades ------------------------------------------------------------------------

    /**
     * Catálogo completo sintético, con la forma que devuelve catalogoPostgres(). Las definiciones son
     * textos de ejemplo: la migración compara cada CHECK con la definición que PostgreSQL normaliza para
     * la misma expresión en una tabla temporal de referencia (aquí, el mapa «referencias»).
     *
     * @return array<string, mixed>
     */
    private function catalogo(): array
    {
        $checks = [];
        $referencias = [];
        foreach (self::CHECKS as $i => $nombre) {
            $definicion = "CHECK ((expresion_normalizada_{$i}))";
            $checks[] = ['conname' => $nombre, 'contype' => 'c', 'convalidated' => true, 'conenforced' => true, 'definicion' => $definicion];
            // La referencia se crea NOT VALID sobre la tabla real, y pg_get_constraintdef() añade ese sufijo.
            $referencias[$nombre] = "$definicion NOT VALID";
        }

        return [
            'tabla_oid' => 16500,
            'columna' => ['attnotnull' => true, 'tipo' => 'character varying(20)'],
            'checks' => $checks,
            'referencias' => $referencias,
            'funciones' => [[
                'oid' => self::FUNCION_OID, 'pronargs' => 0, 'prokind' => 'f', 'retorno' => 'trigger',
                'lenguaje' => 'plpgsql', 'prosrc' => $this->migracion()->cuerpoFuncionPostgres(),
            ]],
            'triggers' => [[
                'tgfoid' => self::FUNCION_OID, 'tgtype' => self::TGTYPE_ESPERADO, 'tgenabled' => 'O',
                'tgisinternal' => false, 'de_restriccion' => false, 'con_when' => false, 'tgnargs' => 0, 'columnas' => 0,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $catalogo
     * @return array{columna: bool, faltan: list<string>, presentes: list<string>}
     */
    private function evaluar(array $catalogo): array
    {
        return $this->migracion()->evaluarCatalogoPostgres($catalogo);
    }

    private function migracion(): object
    {
        return require database_path('migrations/'.self::MIGRACION);
    }
}
