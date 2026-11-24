<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Academico;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MatriculaMasiva;
use App\Support\TemporaryExportFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Matrícula masiva por convenio (Fase 3.8): planilla modelo, vista previa y confirmación. Exige `matriculas.crear`. */
final class MatriculaMasivaController extends Controller implements HasMiddleware
{
    private const CABECERAS = ['Cache-Control' => 'no-store, private'];

    public function __construct(private readonly MatriculaMasiva $masiva) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('permission:matriculas.crear')];
    }

    public function plantilla(): BinaryFileResponse
    {
        $disco = Storage::disk('local');
        if (! $disco->makeDirectory('exports')) {
            throw new RuntimeException('No se pudo crear el directorio temporal de exportación.');
        }
        $archivo = $disco->path('exports/'.Str::uuid()->toString().'.xlsx');
        $temporal = new TemporaryExportFile($archivo, false);
        $this->masiva->escribirPlantilla($archivo);

        return response()->download($temporal, 'plantilla-matricula-masiva.xlsx', self::CABECERAS)->deleteFileAfterSend(true);
    }

    public function vistaPrevia(Request $request, string $identificador): JsonResponse
    {
        $filas = $this->masiva->leer($this->archivo($request));
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->masiva->analizar($actor, $identificador, $filas)], 200, self::CABECERAS);
    }

    public function confirmar(Request $request, string $identificador): JsonResponse
    {
        $request->validate(['crear_obligacion' => ['nullable', 'in:0,1,true,false']]);
        $filas = $this->masiva->leer($this->archivo($request));
        /** @var User $actor */
        $actor = $request->user();
        $obligacion = filter_var($request->input('crear_obligacion', false), FILTER_VALIDATE_BOOLEAN);

        return response()->json(['data' => $this->masiva->confirmar($actor, $identificador, $filas, $obligacion)], 200, self::CABECERAS);
    }

    /** Ruta del XLSX subido, ya validado como archivo de hasta 1 MB. */
    private function archivo(Request $request): string
    {
        $request->validate(['archivo' => ['required', 'file', 'max:1024', 'extensions:xlsx']], [
            'archivo.required' => 'Adjunta la planilla Excel.',
            'archivo.max' => 'La planilla debe pesar hasta 1 MB.',
            'archivo.extensions' => 'La planilla debe ser un archivo .xlsx.',
        ]);

        return (string) $request->file('archivo')?->getRealPath();
    }
}
