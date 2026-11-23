<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Entrada;
use App\Support\GeneraExportacionAdministrativa;
use App\Support\HoraLocal;
use App\Support\ListFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /** Tablas incluidas en la exportación administrativa de Horus Academic. */
    private const TABLAS = [
        'users', 'estudiantes', 'cursos', 'convocatorias', 'matriculas',
        'sesiones_clase', 'asistencias', 'evaluaciones', 'calificaciones', 'certificados',
        'obligaciones_pago', 'cuotas_obligacion', 'transacciones_pago',
        'reclamaciones', 'mensajes_contacto',
        'notificaciones_internas', 'audit_logs', 'system_settings', 'aprobaciones_internas',
    ];

    /** Nombre con el que se presenta cada tabla en el panel (la clave técnica no se muestra). */
    private const ETIQUETAS = [
        'users' => 'Usuarios del panel',
        'estudiantes' => 'Estudiantes',
        'cursos' => 'Cursos',
        'convocatorias' => 'Ediciones (convocatorias)',
        'matriculas' => 'Matrículas',
        'sesiones_clase' => 'Sesiones de clase',
        'asistencias' => 'Asistencias',
        'evaluaciones' => 'Evaluaciones',
        'calificaciones' => 'Calificaciones',
        'certificados' => 'Certificados emitidos',
        'obligaciones_pago' => 'Obligaciones de pago',
        'cuotas_obligacion' => 'Cuotas de pago',
        'transacciones_pago' => 'Transacciones del libro',
        'reclamaciones' => 'Reclamaciones',
        'mensajes_contacto' => 'Mensajes de contacto',
        'notificaciones_internas' => 'Notificaciones',
        'audit_logs' => 'Historial de actividad',
        'system_settings' => 'Configuración del sistema',
        'aprobaciones_internas' => 'Aprobaciones',
    ];

    /** Agrupación visual de las tablas para paquetes rápidos en el panel. */
    private const GRUPOS = [
        'estudiantes' => 'academico',
        'cursos' => 'academico',
        'convocatorias' => 'academico',
        'matriculas' => 'academico',
        'sesiones_clase' => 'academico',
        'asistencias' => 'academico',
        'evaluaciones' => 'academico',
        'calificaciones' => 'academico',
        'certificados' => 'academico',
        'obligaciones_pago' => 'finanzas',
        'cuotas_obligacion' => 'finanzas',
        'transacciones_pago' => 'finanzas',
        'reclamaciones' => 'atencion',
        'mensajes_contacto' => 'atencion',
        'aprobaciones_internas' => 'atencion',
        'users' => 'administracion',
        'notificaciones_internas' => 'administracion',
        'audit_logs' => 'administracion',
        'system_settings' => 'administracion',
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

    /** Qué incluye la exportación, conteos actuales e historial reciente, para mostrarlo antes de descargar. */
    public function index(): JsonResponse
    {
        $contenido = array_map(static function (string $tabla): array {
            $registros = Schema::hasTable($tabla) ? (int) DB::table($tabla)->count() : 0;

            return [
                'clave' => $tabla,
                'etiqueta' => self::ETIQUETAS[$tabla],
                'grupo' => self::GRUPOS[$tabla] ?? 'administracion',
                'registros' => $registros,
            ];
        }, self::TABLAS);

        $historial = AuditLog::query()
            ->where('action', 'data_export_generated')
            ->with('user:id,name')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(static function (AuditLog $log): array {
                $cambios = is_array($log->changes) ? $log->changes : [];
                $tablas = is_array($cambios['tables'] ?? null) ? count($cambios['tables']) : null;

                return [
                    'id' => $log->id,
                    'fecha_local' => HoraLocal::formato($log->created_at),
                    'usuario' => $log->user?->name ?? 'Sistema',
                    'tablas' => $tablas,
                ];
            })
            ->values()
            ->all();

        return response()->json(['data' => [
            'tablas' => self::TABLAS,
            'contenido' => $contenido,
            'historial' => $historial,
            'es_respaldo_restaurable' => false,
            'aviso' => 'Resumen de la información principal del sistema. No incluye contraseñas ni claves de acceso y no permite restaurar el sistema.',
        ]]);
    }

    public function exportar(Request $request): JsonResponse|BinaryFileResponse
    {
        if (! class_exists(ZipArchive::class)) {
            return response()->json(['message' => 'No se pueden preparar descargas comprimidas en este servidor. Contacta con soporte.'], 503);
        }

        $brutoTablas = $request->input('tablas', $request->query('tablas'));
        $seleccionadas = match (true) {
            is_array($brutoTablas) => array_values(array_filter(array_map(static fn (mixed $tabla): string => Entrada::texto($tabla), $brutoTablas))),
            is_string($brutoTablas) && trim($brutoTablas) !== '' => array_values(array_filter(array_map('trim', explode(',', $brutoTablas)))),
            default => null,
        };
        if ($seleccionadas !== null) {
            $seleccionadas = array_values(array_intersect(self::TABLAS, $seleccionadas));
            if ($seleccionadas === []) {
                return response()->json(['message' => 'Selecciona al menos una tabla válida para exportar.'], 422);
            }
        }

        $desde = ListFilter::date($request, 'desde');
        $hasta = ListFilter::date($request, 'hasta');
        if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
            return response()->json(['message' => 'La fecha «Desde» no puede ser posterior a «Hasta».'], 422);
        }

        $nombre = 'exportacion_'.now()->format('Ymd_His').'.zip';
        $zipPath = $this->newTemporaryFile();
        if ($zipPath === false) {
            return response()->json(['message' => 'No se pudo preparar la descarga. Inténtalo de nuevo.'], 500);
        }

        // Solo se conserva el temporal si la respuesta de descarga llegó a construirse.
        $entregable = false;

        try {
            $incluidas = $this->buildArchive(
                $zipPath,
                $seleccionadas,
                $desde !== '' ? $desde : null,
                $hasta !== '' ? $hasta : null,
            );

            AuditLog::record('data_export_generated', null, null, [
                'filename' => $nombre,
                'tables' => array_keys($incluidas),
                'desde' => $desde !== '' ? $desde : null,
                'hasta' => $hasta !== '' ? $hasta : null,
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
