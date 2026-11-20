<?php

declare(strict_types=1);

namespace App\Support\Panel;

use App\Models\AuditLog;
use App\Support\Entrada;

/**
 * Presenta un registro de auditoría en lenguaje común: etiqueta de la acción, de lo que se tocó y un resumen de los cambios en
 * frases, sin nombres de clases, claves internas ni JSON. Los valores crudos (`accion`, `modelo`) siguen disponibles para los
 * filtros y la exportación; esta clase solo decide qué se enseña a quien revisa el historial.
 *
 * Trabaja sobre `redactedChanges()`: nunca ve secretos. Una acción, modelo o campo sin traducción se muestra con un texto
 * legible calculado, de modo que un registro nuevo no rompe la pantalla.
 */
final class PresentadorAuditoria
{
    /** @var array<string, string> */
    public const ACCIONES = [
        'login' => 'Inicio de sesión',
        'login_failed' => 'Intento de acceso fallido',
        'logout' => 'Cierre de sesión',
        'password_reset' => 'Contraseña restablecida',
        'perfil_actualizado' => 'Perfil actualizado',
        'perfil_preferencias_actualizadas' => 'Preferencias de notificación actualizadas',
        'sesiones_cerradas' => 'Sesiones cerradas',
        'acceso_denegado' => 'Acceso denegado',
        'user_created' => 'Usuario creado',
        'user_updated' => 'Usuario modificado',
        'user_deleted' => 'Usuario eliminado',
        'user_deactivated' => 'Usuario dado de baja',
        'user_restored' => 'Usuario restaurado',
        'user_suspended' => 'Cuenta suspendida',
        'user_unlocked' => 'Suspensión levantada',
        'user_locked_auto' => 'Bloqueo automático por intentos fallidos',
        'user_invited' => 'Enlace de acceso enviado',
        'user_sessions_revoked' => 'Sesiones de usuario cerradas',
        'two_factor_enabled' => '2FA activado',
        'two_factor_disabled' => '2FA desactivado',
        'two_factor_recovery_used' => 'Código de recuperación 2FA usado',
        'role_created' => 'Rol creado',
        'role_updated' => 'Rol modificado',
        'role_deleted' => 'Rol eliminado',
        'admin_role_granted_local' => 'Rol de administrador concedido',
        'audit_exported' => 'Historial de actividad descargado',
        'data_export_generated' => 'Datos exportados',
        'acta_exportada' => 'Acta descargada',
        'asistencia_exportada' => 'Asistencia descargada',
        'pagos_exportados' => 'Pagos descargados',
        'estudiante_creado' => 'Estudiante registrado',
        'estudiante_actualizado' => 'Estudiante modificado',
        'estudiante_eliminado' => 'Estudiante eliminado',
        'estudiante_restaurado' => 'Estudiante restaurado',
        'curso_creado' => 'Curso creado',
        'curso_actualizado' => 'Curso modificado',
        'curso_eliminado' => 'Curso eliminado',
        'curso_restaurado' => 'Curso restaurado',
        'curso_publicado' => 'Curso publicado en la web',
        'curso_despublicado' => 'Curso retirado de la web',
        'matricula_creada' => 'Matrícula registrada',
        'matricula_actualizada' => 'Matrícula modificada',
        'matricula_anulada' => 'Matrícula anulada',
        'matricula_eliminada' => 'Matrícula eliminada',
        'matricula_restaurada' => 'Matrícula restaurada',
        'matricula_estado_derivado' => 'Estado de matrícula actualizado',
        'docente_creado' => 'Docente registrado',
        'docente_actualizado' => 'Docente modificado',
        'docente_eliminado' => 'Docente eliminado',
        'docente_restaurado' => 'Docente restaurado',
        'convocatoria_creada' => 'Edición creada',
        'convocatoria_actualizada' => 'Edición modificada',
        'convocatoria_docentes_asignados' => 'Docentes asignados a una edición',
        'convocatoria_infraestructura_asignada' => 'Aula asignada a una edición',
        'horario_modificado' => 'Horario modificado',
        'sede_creada' => 'Sede creada',
        'sede_actualizada' => 'Sede modificada',
        'sede_estado_cambiado' => 'Estado de una sede cambiado',
        'aula_creada' => 'Aula creada',
        'aula_actualizada' => 'Aula modificada',
        'aula_estado_cambiado' => 'Estado de un aula cambiado',
        'plataforma_creada' => 'Plataforma virtual creada',
        'plataforma_actualizada' => 'Plataforma virtual modificada',
        'plataforma_estado_cambiado' => 'Estado de una plataforma virtual cambiado',
        'sesion_programada' => 'Clase programada',
        'sesion_actualizada' => 'Clase modificada',
        'sesion_cancelada' => 'Clase cancelada',
        'sesion_realizada' => 'Clase marcada como realizada',
        'sesion_enlace_consultado' => 'Enlace de una clase consultado',
        'sesiones_generadas' => 'Clases generadas',
        'asistencia_registrada' => 'Asistencia registrada',
        'asistencia_corregida' => 'Asistencia corregida',
        'nota_registrada' => 'Nota registrada',
        'nota_corregida' => 'Nota corregida',
        'evaluacion_creada' => 'Evaluación creada',
        'evaluacion_modificada' => 'Evaluación modificada',
        'evaluacion_eliminada' => 'Evaluación eliminada',
        'acta_cerrada' => 'Acta cerrada',
        'obligacion_pago_creada' => 'Cobro planificado',
        'obligacion_pago_anulada' => 'Cobro anulado',
        'pago_registrado' => 'Pago registrado',
        'pago_confirmado' => 'Pago confirmado',
        'pago_rechazado' => 'Pago rechazado',
        'pago_reversado' => 'Pago reversado',
        'comprobante_adjuntado' => 'Comprobante de pago adjuntado',
        'comprobante_consultado' => 'Comprobante de pago consultado',
        'servicio_creado' => 'Servicio creado',
        'propuesta_aceptada' => 'Propuesta aceptada',
        'propuesta_rechazada' => 'Propuesta rechazada',
        'propuesta_vencida' => 'Propuesta vencida',
        'correo_enviado' => 'Correo enviado',
        'correo_fallido' => 'Correo no enviado',
        'aviso_plazo' => 'Aviso de plazo',
        'reclamacion_bandeja_consultada' => 'Bandeja de reclamaciones consultada',
        'reclamacion_registrada' => 'Reclamación registrada',
        'reclamacion_consultada' => 'Expediente consultado',
        'reclamacion_impresa' => 'Hoja imprimible generada',
        'reclamacion_respondida' => 'Reclamación respondida',
        'reclamacion_propuesta' => 'Propuesta enviada',
        'reclamacion_nota' => 'Nota en reclamación',
        'reclamacion_responsable' => 'Responsable de reclamación asignado',
        'reclamacion_tipo' => 'Tipo de reclamación actualizado',
        'reclamacion_reintento' => 'Reintento de correo en reclamación',
        'contacto_recibido' => 'Mensaje de contacto recibido',
        'contacto_atendido' => 'Mensaje de contacto atendido',
        'aprobacion_creada' => 'Aprobación solicitada',
        'aprobacion_actualizada' => 'Aprobación modificada',
        'aprobacion_eliminada' => 'Aprobación eliminada',
        'aprobacion_aprobada' => 'Aprobación aprobada',
        'aprobacion_rechazada' => 'Aprobación rechazada',
        'aprobacion_reabierta' => 'Aprobación reabierta',
    ];

    /** @var array<string, string> */
    public const MODELOS = [
        'User' => 'Usuario',
        'Role' => 'Rol',
        'Estudiante' => 'Estudiante',
        'Curso' => 'Curso',
        'Matricula' => 'Matrícula',
        'Docente' => 'Docente',
        'Convocatoria' => 'Edición de curso',
        'Sede' => 'Sede',
        'Aula' => 'Aula',
        'PlataformaVirtual' => 'Plataforma virtual',
        'SesionClase' => 'Clase',
        'Asistencia' => 'Asistencia',
        'Calificacion' => 'Nota',
        'Evaluacion' => 'Evaluación',
        'ObligacionPago' => 'Cobro',
        'TransaccionPago' => 'Pago',
        'AprobacionInterna' => 'Aprobación interna',
        'ServicioTecnologico' => 'Servicio',
        'Reclamacion' => 'Reclamación',
        'MensajeContacto' => 'Mensaje de contacto',
        'AuditLog' => 'Historial de actividad',
    ];

    /** @var list<string> Claves de módulos retirados del panel (conservadas solo para mostrar históricos). */
    private const ACCIONES_RETIRADAS = [
        'docente_creado',
        'docente_actualizado',
        'docente_eliminado',
        'docente_restaurado',
        'convocatoria_docentes_asignados',
        'convocatoria_infraestructura_asignada',
        'sede_creada',
        'sede_actualizada',
        'sede_estado_cambiado',
        'aula_creada',
        'aula_actualizada',
        'aula_estado_cambiado',
        'plataforma_creada',
        'plataforma_actualizada',
        'plataforma_estado_cambiado',
        'sesion_enlace_consultado',
        'servicio_creado',
    ];

    /** @var list<string> */
    private const MODELOS_RETIRADOS = [
        'Docente',
        'Sede',
        'Aula',
        'PlataformaVirtual',
        'ServicioTecnologico',
    ];

    /** @return array<string, string> */
    public static function accionesSelector(): array
    {
        return array_diff_key(self::ACCIONES, array_flip(self::ACCIONES_RETIRADAS));
    }

    /** @return array<string, string> */
    public static function modelosSelector(): array
    {
        return array_diff_key(self::MODELOS, array_flip(self::MODELOS_RETIRADOS));
    }

    /** @var array<string, string> */
    private const CAMPOS = [
        'estado' => 'Estado',
        'motivo' => 'Motivo',
        'nombre' => 'Nombre',
        'email' => 'Correo',
        'correo' => 'Correo',
        'roles' => 'Roles',
        'role' => 'Rol',
        'permisos' => 'Permisos',
        'permissions' => 'Permisos',
        'precio' => 'Precio',
        'cupos_maximos' => 'Cupos máximos',
        'cupos_disponibles' => 'Cupos disponibles',
        'descripcion' => 'Descripción',
        'categoria' => 'Categoría',
        'modalidad' => 'Modalidad',
        'horario' => 'Horario',
        'duracion_horas' => 'Duración en horas',
        'codigo' => 'Código',
        'curso_id' => 'Curso',
        'filename' => 'Archivo',
        'tables' => 'Secciones',
        'records' => 'Registros',
        'eliminados' => 'Eliminados',
        'desde' => 'Desde',
        'hasta' => 'Hasta',
        'accion' => 'Acción',
        'usuario' => 'Usuario',
        'pagina' => 'Página',
    ];

    /** Claves internas que no dicen nada a quien revisa el historial. */
    private const OCULTOS = ['esquema', 'huella_correo', 'canal', 'ip', 'personal_panel', 'pagina', 'filtros', 'modelo', 'campos', 'tablas'];

    /** @var array<string, string> */
    private const ESTADOS = [
        'pendiente' => 'Pendiente', 'pagada' => 'Pagada', 'anulada' => 'Anulada', 'activo' => 'Activo', 'inactivo' => 'Inactivo',
        'archivado' => 'Archivado', 'confirmada' => 'Confirmada', 'rechazada' => 'Rechazada', 'reversada' => 'Reversada',
        'abierta' => 'Abierta', 'cerrada' => 'Cerrada', 'cancelada' => 'Cancelada', 'realizada' => 'Realizada', 'programada' => 'Programada',
    ];

    public static function etiquetaAccion(string $accion): string
    {
        return self::ACCIONES[$accion] ?? self::legible($accion);
    }

    public static function etiquetaModelo(?string $modelo): ?string
    {
        if ($modelo === null || $modelo === '') {
            return null;
        }
        $base = class_basename($modelo);

        return self::MODELOS[$base] ?? self::legible(mb_strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $base) ?? $base));
    }

    /**
     * Frases que resumen los cambios, p. ej. «Estado: Pendiente → Pagada».
     *
     * @param  array<string, mixed>|null  $cambios  ya enmascarados
     * @return list<string>
     */
    public static function resumen(?array $cambios): array
    {
        if ($cambios === null || $cambios === []) {
            return [];
        }

        $frases = [];
        $antes = is_array($cambios['antes'] ?? null) ? $cambios['antes'] : [];
        $despues = is_array($cambios['despues'] ?? null) ? $cambios['despues'] : [];

        foreach ($despues as $campo => $nuevo) {
            if (! is_string($campo) || in_array($campo, self::OCULTOS, true)) {
                continue;
            }
            $anterior = array_key_exists($campo, $antes) ? self::valor($campo, $antes[$campo]) : null;
            $frases[] = self::campo($campo).': '.($anterior !== null ? $anterior.' → ' : '').self::valor($campo, $nuevo);
        }

        foreach ($cambios as $clave => $valor) {
            if (in_array($clave, [...self::OCULTOS, 'antes', 'despues'], true)) {
                continue;
            }
            if ($clave === 'valores' && is_array($valor)) {
                foreach ($valor as $campo => $contenido) {
                    if (is_string($campo) && ! in_array($campo, self::OCULTOS, true)) {
                        $frases[] = self::campo($campo).': '.self::valor($campo, $contenido);
                    }
                }

                continue;
            }
            if (is_array($valor) && ! array_is_list($valor)) {
                continue; // estructuras internas
            }
            $texto = self::valor($clave, $valor);
            if ($texto !== '') {
                $frases[] = self::campo($clave).': '.$texto;
            }
        }

        if (is_array($cambios['campos'] ?? null) && $despues === [] && ! array_key_exists('valores', $cambios)) {
            $nombres = array_map(static fn (mixed $c): string => is_string($c) ? self::campo($c) : '', (array) $cambios['campos']);
            $nombres = array_values(array_filter($nombres));
            if ($nombres !== []) {
                $frases[] = 'Datos modificados: '.mb_strtolower(implode(', ', $nombres));
            }
        }

        return array_slice(array_values(array_unique($frases)), 0, 12);
    }

    /**
     * @return array{accion_etiqueta: string, modelo_etiqueta: string|null, resumen: list<string>}
     */
    public static function de(AuditLog $registro): array
    {
        $cambios = $registro->changes === null || $registro->changes === [] ? null : $registro->redactedChanges();

        return [
            'accion_etiqueta' => self::etiquetaAccion((string) $registro->action),
            'modelo_etiqueta' => self::etiquetaModelo($registro->model_type),
            'resumen' => self::resumen($cambios),
        ];
    }

    private static function campo(string $campo): string
    {
        return self::CAMPOS[$campo] ?? self::legible($campo);
    }

    private static function valor(string $campo, mixed $valor): string
    {
        if ($valor === null) {
            return 'sin dato';
        }
        if (is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }
        if (is_array($valor)) {
            $partes = array_map(static fn (bool|float|int|string $v): string => (string) $v, array_filter($valor, 'is_scalar'));

            return implode(', ', array_filter($partes, static fn (string $p): bool => $p !== ''));
        }
        $texto = Entrada::texto($valor);
        if ($campo === 'estado') {
            return self::ESTADOS[$texto] ?? self::legible($texto);
        }

        return mb_strlen($texto) > 120 ? mb_substr($texto, 0, 117).'…' : $texto;
    }

    private static function legible(string $texto): string
    {
        $limpio = trim(str_replace(['_', '-', '.'], ' ', $texto));

        return mb_strtoupper(mb_substr($limpio, 0, 1)).mb_substr($limpio, 1);
    }
}
