<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\SafeSpreadsheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use ZipArchive;

/**
 * Plan de mejora del panel, Fase 3.8: matrícula masiva por convenio desde una planilla Excel.
 *
 * Dos pasos sobre el mismo archivo: **vista previa** (no escribe nada) y **confirmación** (vuelve a analizar el archivo, nunca confía
 * en la vista previa). Cada fila se procesa en su propia transacción: matrícula pendiente y, si la fila trae nombres y apellidos de
 * un DNI nuevo, el estudiante (si quien confirma puede crear estudiantes). Repetir el mismo archivo no duplica nada: lo ya
 * matriculado se informa como «ya matriculado». Las filas que no caben en los cupos libres quedan «sin cupo», en el orden del archivo.
 *
 * Planilla: primera hoja, encabezados `dni`, `nombres`, `apellidos`, `telefono`, `email` (solo `dni` es obligatorio). Un DNI guardado
 * como número se completa con ceros a la izquierda hasta 8 dígitos. Tope: {@see self::MAX_FILAS} filas y {@see self::MAX_BYTES} bytes.
 *
 * @phpstan-type Fila array{fila: int, dni: string, nombres: string, apellidos: string, telefono: string, email: string}
 * @phpstan-type Resultado array{fila: int, dni: string, nombre: string, accion: string, motivo: string|null}
 */
final class MatriculaMasiva
{
    public const MAX_FILAS = 200;

    public const MAX_BYTES = 1_048_576;

    /** Descompresión máxima admitida del XLSX (frente a archivos «bomba»). */
    private const MAX_DESCOMPRIMIDO = 20_971_520;

    public const MATRICULAR = 'matricular';

    public const CREAR_Y_MATRICULAR = 'crear_y_matricular';

    public const OMITIR = 'omitir';

    public const SIN_CUPO = 'sin_cupo';

    public const ERROR = 'error';

    private const COLUMNAS = ['dni', 'nombres', 'apellidos', 'telefono', 'email'];

    public function __construct(
        private readonly GestionMatriculas $matriculas,
        private readonly GestionEstudiantes $estudiantes,
    ) {}

    /** Escribe la planilla modelo (encabezados y una fila de ejemplo). */
    public function escribirPlantilla(string $archivo): void
    {
        $libro = new Spreadsheet;
        try {
            $hoja = $libro->getActiveSheet();
            $hoja->setTitle('Matrícula masiva');
            SafeSpreadsheet::writeRow($hoja, 1, self::COLUMNAS);
            SafeSpreadsheet::writeRow($hoja, 2, ['12345678', 'María', 'Quispe Rojas', '987654321', 'maria@example.com']);
            $writer = new Xlsx($libro);
            $writer->setPreCalculateFormulas(false);
            $writer->save($archivo);
        } finally {
            $libro->disconnectWorksheets();
        }
    }

    /**
     * Lee la primera hoja del XLSX. Valida tamaño, estructura ZIP y tope de filas antes de interpretar nada.
     *
     * @return list<Fila>
     */
    public function leer(string $ruta): array
    {
        if (! is_file($ruta) || filesize($ruta) === false || filesize($ruta) === 0 || filesize($ruta) > self::MAX_BYTES) {
            $this->rechazar('La planilla debe pesar hasta 1 MB.');
        }
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::CHECKCONS) !== true) {
            $this->rechazar('El archivo no es una planilla Excel (.xlsx) válida.');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $total += (int) ($zip->statIndex($i)['size'] ?? 0);
        }
        $entradas = $zip->numFiles;
        $zip->close();
        if ($entradas > 200 || $total > self::MAX_DESCOMPRIMIDO) {
            $this->rechazar('La planilla es demasiado grande o no tiene el formato esperado.');
        }

        try {
            $lector = IOFactory::createReader('Xlsx');
            $lector->setReadDataOnly(true);
            $libro = $lector->load($ruta);
            $matriz = $libro->getSheet(0)->toArray(null, false, false, false);
            $libro->disconnectWorksheets();
        } catch (Throwable) {
            $this->rechazar('No se pudo leer la planilla. Usa la planilla modelo y guárdala como .xlsx.');
        }

        $cabecera = array_shift($matriz) ?? [];
        $indices = [];
        foreach ($cabecera as $i => $titulo) {
            $clave = $this->clave($titulo);
            if (in_array($clave, self::COLUMNAS, true) && ! isset($indices[$clave])) {
                $indices[$clave] = (int) $i;
            }
        }
        if (! isset($indices['dni'])) {
            $this->rechazar('La primera fila debe tener el encabezado «dni» (usa la planilla modelo).');
        }

        $filas = [];
        foreach ($matriz as $n => $celdas) {
            $fila = ['fila' => $n + 2];
            foreach (self::COLUMNAS as $columna) {
                $fila[$columna] = isset($indices[$columna]) ? $this->texto($celdas[$indices[$columna]] ?? null, $columna === 'dni') : '';
            }
            if ($fila['dni'] === '' && $fila['nombres'] === '' && $fila['apellidos'] === '') {
                continue;
            }
            $filas[] = $fila;
            if (count($filas) > self::MAX_FILAS) {
                $this->rechazar('La planilla admite hasta '.self::MAX_FILAS.' filas por carga.');
            }
        }
        if ($filas === []) {
            $this->rechazar('La planilla no tiene filas con datos.');
        }

        /** @var list<Fila> $filas */
        return $filas;
    }

    /**
     * Analiza cada fila sin escribir. El orden del archivo decide quién entra cuando faltan cupos.
     *
     * @param  list<Fila>  $filas
     * @return array{puede_crear_estudiantes: bool, cupos_disponibles: int, resumen: array<string, int>, filas: list<Resultado>}
     */
    public function analizar(User $actor, string $identificador, array $filas): array
    {
        $convocatoria = Convocatoria::query()->where('identificador', strtolower($identificador))->firstOrFail();
        $this->comprobarEdicion($convocatoria);
        $puedeCrear = $actor->can('estudiantes.crear');
        $cupos = max(0, (int) $convocatoria->cupos_disponibles);
        $vistos = [];
        $resultados = [];

        foreach ($filas as $f) {
            [$accion, $motivo, $nombre] = $this->evaluar($f, $convocatoria, $puedeCrear, $vistos);
            if (in_array($accion, [self::MATRICULAR, self::CREAR_Y_MATRICULAR], true)) {
                if ($cupos <= 0) {
                    [$accion, $motivo] = [self::SIN_CUPO, 'No quedan cupos libres.'];
                } else {
                    $cupos--;
                }
            }
            $resultados[] = ['fila' => $f['fila'], 'dni' => $f['dni'], 'nombre' => $nombre, 'accion' => $accion, 'motivo' => $motivo];
        }

        $resumen = [self::MATRICULAR => 0, self::CREAR_Y_MATRICULAR => 0, self::OMITIR => 0, self::SIN_CUPO => 0, self::ERROR => 0];
        foreach ($resultados as $r) {
            $resumen[$r['accion']]++;
        }

        return ['puede_crear_estudiantes' => $puedeCrear, 'cupos_disponibles' => (int) $convocatoria->cupos_disponibles, 'resumen' => $resumen, 'filas' => $resultados];
    }

    /**
     * Matricula a quien corresponde. Vuelve a analizar y procesa fila por fila; una fila que falle no frena a las demás.
     *
     * @param  list<Fila>  $filas
     * @return array{resumen: array<string, int>, filas: list<Resultado>}
     */
    public function confirmar(User $actor, string $identificador, array $filas, bool $crearObligacion): array
    {
        if ($crearObligacion && ! $actor->can('pagos.planificar')) {
            abort(403, 'No tienes permiso para crear cuentas por cobrar.');
        }
        $analisis = $this->analizar($actor, $identificador, $filas);
        $porFila = [];
        foreach ($filas as $f) {
            $porFila[$f['fila']] = $f;
        }

        $convocatoria = Convocatoria::query()->where('identificador', strtolower($identificador))->firstOrFail();
        $resumen = ['matriculadas' => 0, 'estudiantes_creados' => 0, self::OMITIR => 0, self::SIN_CUPO => 0, self::ERROR => 0];
        $salida = [];

        foreach ($analisis['filas'] as $r) {
            $accion = $r['accion'];
            if (in_array($accion, [self::MATRICULAR, self::CREAR_Y_MATRICULAR], true)) {
                try {
                    $creado = $this->procesar($actor, $convocatoria, $porFila[$r['fila']], $accion === self::CREAR_Y_MATRICULAR, $crearObligacion);
                    $resumen['matriculadas']++;
                    $resumen['estudiantes_creados'] += $creado ? 1 : 0;
                    $r['accion'] = 'matriculada';
                } catch (ValidationException $e) {
                    [$r['accion'], $r['motivo']] = [self::ERROR, Entrada::texto(collect($e->errors())->flatten()->first() ?? 'No se pudo matricular.')];
                } catch (HttpException $e) {
                    [$r['accion'], $r['motivo']] = [self::ERROR, $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo matricular.'];
                }
            }
            if (isset($resumen[$r['accion']])) {
                $resumen[$r['accion']]++;
            }
            $salida[] = $r;
        }

        AuditLog::record('matricula_masiva', Convocatoria::class, $convocatoria->id, [
            'convocatoria' => $convocatoria->codigo, 'filas' => count($filas), 'matriculadas' => $resumen['matriculadas'],
            'estudiantes_creados' => $resumen['estudiantes_creados'], 'omitidas' => $resumen[self::OMITIR], 'sin_cupo' => $resumen[self::SIN_CUPO],
            'con_error' => $resumen[self::ERROR], 'con_obligacion' => $crearObligacion,
        ], $actor->id);

        return ['resumen' => $resumen, 'filas' => $salida];
    }

    /**
     * Matricula una fila en su propia transacción. Devuelve si creó al estudiante.
     *
     * @param  Fila  $f
     */
    private function procesar(User $actor, Convocatoria $convocatoria, array $f, bool $crearEstudiante, bool $crearObligacion): bool
    {
        $creado = false;
        DB::transaction(function () use ($actor, $convocatoria, $f, $crearEstudiante, $crearObligacion, &$creado): void {
            if ($crearEstudiante) {
                $estudiante = $this->estudiantes->crear([
                    'dni' => $f['dni'], 'nombres' => $f['nombres'], 'apellidos' => $f['apellidos'], 'estado' => 'activo',
                    'telefono' => $f['telefono'] !== '' ? $f['telefono'] : null, 'email' => $f['email'] !== '' ? strtolower($f['email']) : null,
                ], (int) $actor->id);
                $creado = true;
            } else {
                $estudiante = Estudiante::query()->where('dni', $f['dni'])->firstOrFail();
            }

            $this->matriculas->crear([
                'estudiante_id' => $estudiante->id, 'curso_id' => $convocatoria->curso_id, 'convocatoria' => $convocatoria->identificador,
                'fecha_matricula' => HoraLocal::hoy(), 'estado' => 'pendiente', 'crear_obligacion' => $crearObligacion,
                'observaciones' => 'Matrícula masiva por convenio',
            ], (int) $actor->id);
        });

        return $creado;
    }

    /**
     * @param  Fila  $f
     * @param  array<string, true>  $vistos
     * @return array{0: string, 1: string|null, 2: string}
     */
    private function evaluar(array $f, Convocatoria $convocatoria, bool $puedeCrear, array &$vistos): array
    {
        $nombre = trim($f['nombres'].' '.$f['apellidos']);
        if (preg_match('/\A\d{8}\z/', $f['dni']) !== 1 || $f['dni'] === '00000000') {
            return [self::ERROR, 'El DNI debe tener 8 dígitos y no ser 00000000.', $nombre];
        }
        if (isset($vistos[$f['dni']])) {
            return [self::ERROR, 'El DNI se repite en la planilla.', $nombre];
        }
        $vistos[$f['dni']] = true;

        $e = Estudiante::withTrashed()->where('dni', $f['dni'])->first();
        if ($e !== null) {
            $nombre = trim($e->nombres.' '.$e->apellidos);
            if ($e->trashed()) {
                return [self::ERROR, 'El estudiante está eliminado; restáuralo antes.', $nombre];
            }
            if ($e->estado !== 'activo') {
                return [self::ERROR, 'El estudiante está inactivo.', $nombre];
            }
            if (Matricula::query()->where('estudiante_id', $e->id)->where('curso_id', $convocatoria->curso_id)->where('estado', '!=', 'anulada')->exists()) {
                return [self::OMITIR, 'Ya matriculado en este curso.', $nombre];
            }

            return [self::MATRICULAR, null, $nombre];
        }

        if (! $puedeCrear) {
            return [self::ERROR, 'El DNI no está registrado y no tienes permiso para crear estudiantes.', $nombre];
        }
        $error = $this->validarNuevo($f);
        if ($error !== null) {
            return [self::ERROR, $error, $nombre];
        }

        return [self::CREAR_Y_MATRICULAR, null, $nombre];
    }

    /** @param  Fila  $f */
    private function validarNuevo(array $f): ?string
    {
        $patron = '/\A[\p{L}\s\'\-\.]{2,100}\z/u';
        if (preg_match($patron, $f['nombres']) !== 1 || preg_match($patron, $f['apellidos']) !== 1) {
            return 'DNI nuevo: indica nombres y apellidos válidos (solo letras, 2 a 100 caracteres).';
        }
        $telefono = preg_replace('/[\s\-]+/', '', $f['telefono']) ?? '';
        if ($telefono !== '' && preg_match('/\A9\d{8}\z/', $telefono) !== 1) {
            return 'El teléfono debe tener 9 dígitos e iniciar con 9.';
        }
        if ($f['email'] !== '') {
            if (filter_var($f['email'], FILTER_VALIDATE_EMAIL) === false || strlen($f['email']) > 150) {
                return 'El email no es válido.';
            }
            if (Estudiante::withTrashed()->where('email', strtolower($f['email']))->exists()) {
                return 'Ese email ya está registrado en otro estudiante.';
            }
        }

        return null;
    }

    private function comprobarEdicion(Convocatoria $c): void
    {
        abort_unless(in_array($c->estado, Convocatoria::MATRICULABLES, true), 409, 'La edición no admite matrículas en su estado actual.');
        $curso = Curso::query()->find($c->curso_id);
        abort_unless($curso !== null && $curso->estado === 'activo', 409, 'El curso de la edición no está activo.');
    }

    private function clave(mixed $titulo): string
    {
        $t = mb_strtolower(trim(Entrada::texto($titulo)));

        return strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    private function texto(mixed $valor, bool $esDni): string
    {
        if (is_int($valor) || is_float($valor)) {
            // Un DNI guardado como número pierde los ceros de la izquierda.
            return $esDni ? str_pad((string) (int) $valor, 8, '0', STR_PAD_LEFT) : (string) $valor;
        }

        return mb_substr(trim(Entrada::texto($valor)), 0, 150);
    }

    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages(['archivo' => $mensaje]);
    }
}
