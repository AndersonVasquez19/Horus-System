<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\GeneraExportacionAdministrativa;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

/**
 * Exportación ADMINISTRATIVA de tablas seleccionadas para el panel de apps/web (ZIP con CSV por tabla). Misma generación y
 * misma minimización que el módulo Blade ({@see GeneraExportacionAdministrativa}). No es una copia de seguridad
 * restaurable: un respaldo integral se obtiene con `pg_dump` en el servidor (docs/seguridad-l1.1.md).
 *
 * El ZIP no se conserva: se crea como temporal, se entrega y se elimina; si la auditoría falla, no se entrega nada.
 */
class RespaldoController extends Controller implements HasMiddleware
{
    use GeneraExportacionAdministrativa;

    /** Tablas incluidas (las mismas que el módulo Blade). */
    private const TABLAS = [
        'users', 'estudiantes', 'cursos', 'matriculas', 'procesos_internos',
        'notificaciones_internas', 'audit_logs', 'system_settings', 'aprobaciones_internas',
    ];

    /** Nombre con el que se presenta cada tabla en el panel (la clave técnica no se muestra). */
    private const ETIQUETAS = [
        'users' => 'Usuarios del panel',
        'estudiantes' => 'Estudiantes',
        'cursos' => 'Cursos',
        'matriculas' => 'Matrículas',
        'procesos_internos' => 'Procesos internos',
        'notificaciones_internas' => 'Notificaciones',
        'audit_logs' => 'Historial de actividad',
        'system_settings' => 'Configuración del sistema',
        'aprobaciones_internas' => 'Aprobaciones',
    ];

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:respaldo.ver', only: ['index']),
            new Middleware('permission:respaldo.exportar', only: ['exportar']),
        ];
    }

    /** @return list<string> */
    protected function tablasExportables(): array
    {
        return self::TABLAS;
    }

    /** Qué incluye la exportación, para mostrarlo antes de descargar. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'tablas' => self::TABLAS,
            'contenido' => array_map(
                static fn (string $tabla): array => ['clave' => $tabla, 'etiqueta' => self::ETIQUETAS[$tabla] ?? $tabla],
                self::TABLAS,
            ),
            'es_respaldo_restaurable' => false,
            'aviso' => 'Resumen de la información principal del sistema. No incluye contraseñas ni claves de acceso y no permite restaurar el sistema.',
        ]]);
    }

    public function exportar(): JsonResponse|BinaryFileResponse
    {
        if (! class_exists(ZipArchive::class)) {
            return response()->json(['message' => 'No se pueden preparar descargas comprimidas en este servidor. Contacta con soporte.'], 503);
        }

        $nombre = 'exportacion_'.now()->format('Ymd_His').'.zip';
        $zipPath = $this->newTemporaryFile();
        if ($zipPath === false) {
            return response()->json(['message' => 'No se pudo preparar la descarga. Inténtalo de nuevo.'], 500);
        }

        // Solo se conserva el temporal si la respuesta de descarga llegó a construirse.
        $entregable = false;

        try {
            $incluidas = $this->buildArchive($zipPath);

            AuditLog::record('data_export_generated', null, null, [
                'filename' => $nombre,
                'tables' => array_keys($incluidas),
                'canal' => 'panel',
            ]);

            $respuesta = response()
                ->download($zipPath, $nombre, ['Content-Type' => 'application/zip'])
                ->deleteFileAfterSend(true);
            $entregable = true;

            return $respuesta;
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo generar la exportación. No se descargó ningún archivo.'], 500);
        } finally {
            if (! $entregable) {
                @unlink($zipPath);
            }
        }
    }
}
