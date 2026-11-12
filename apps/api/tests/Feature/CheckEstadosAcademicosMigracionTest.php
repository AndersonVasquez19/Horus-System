<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\PublicarCursoRequest;
use App\Http\Requests\StoreCursoRequest;
use App\Http\Requests\StoreEstudianteRequest;
use App\Http\Requests\StoreMatriculaRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\In;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Los CHECK de estados solo existen en PostgreSQL (no aplicados aún en ninguna base). Aquí se comprueba que
 * la migración es inofensiva en SQLite y que sus conjuntos coinciden con los de los FormRequest.
 */
class CheckEstadosAcademicosMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const ARCHIVO = '2026_11_10_000039_add_check_estados_academicos';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedTestDatabase();
    }

    private function migracion(): object
    {
        return require database_path('migrations/'.self::ARCHIVO.'.php');
    }

    /** @return list<string> */
    private function valoresDeLaRegla(FormRequest $request, string $campo): array
    {
        foreach ($request->rules()[$campo] as $regla) {
            if ($regla instanceof In) {
                $valores = str_getcsv(substr((string) $regla, strlen('in:')), ',', '"', '');
                sort($valores);

                return $valores;
            }
        }

        $this->fail("El campo {$campo} no tiene Rule::in en ".$request::class);
    }

    /** @return iterable<string, array{string, FormRequest, string}> */
    public static function conjuntos(): iterable
    {
        yield 'matriculas.estado' => ['matriculas_estado_valido', new StoreMatriculaRequest, 'estado'];
        yield 'cursos.estado' => ['cursos_estado_valido', new StoreCursoRequest, 'estado'];
        yield 'cursos.modalidad' => ['cursos_modalidad_valida', new StoreCursoRequest, 'modalidad'];
        yield 'estudiantes.estado' => ['estudiantes_estado_valido', new StoreEstudianteRequest, 'estado'];
        yield 'curso_publicaciones.modalidad' => ['curso_publicaciones_modalidad_valida', new PublicarCursoRequest, 'modalidad'];
    }

    #[DataProvider('conjuntos')]
    public function test_los_valores_del_check_coinciden_con_los_de_la_validacion(string $nombre, FormRequest $request, string $campo): void
    {
        $definicion = $this->migracion()::VALORES[$nombre];
        $esperados = $definicion['valores'];
        sort($esperados);

        $this->assertSame($campo, $definicion['columna']);
        $this->assertSame($this->valoresDeLaRegla($request, $campo), $esperados);
        $this->assertTrue(Schema::hasColumn($definicion['tabla'], $definicion['columna']));
    }

    public function test_las_sentencias_son_las_esperadas_y_solo_usan_la_constante(): void
    {
        $sentencias = $this->migracion()::sentencias();

        $this->assertSame(array_keys($this->migracion()::VALORES), array_keys($sentencias));
        $this->assertSame(
            "alter table matriculas add constraint matriculas_estado_valido check (estado in ('pendiente', 'pagada', 'anulada'))",
            $sentencias['matriculas_estado_valido']
        );
        $this->assertSame(
            "alter table cursos add constraint cursos_modalidad_valida check (modalidad in ('presencial', 'semipresencial', 'virtual', 'hibrido'))",
            $sentencias['cursos_modalidad_valida']
        );
        foreach ($sentencias as $sql) {
            $this->assertDoesNotMatchRegularExpression('/[;"\\\\]/', $sql);
        }
    }

    public function test_up_y_down_no_hacen_nada_en_sqlite(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $migracion = $this->migracion();

        $migracion->up();
        $migracion->down();

        $this->assertTrue(Schema::hasTable('matriculas'));
    }
}
