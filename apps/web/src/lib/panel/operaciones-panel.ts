import 'server-only';

/**
 * Operaciones del navegador que el BFF reenvía a `/api/panel/v1/*` (`app/api/bff/panel/[...ruta]`).
 *
 * Es una lista cerrada, no un proxy: cada entrada fija método, forma de la ruta, campos del cuerpo y parámetros de
 * consulta que se reenvían. Todo lo demás se descarta o se rechaza con 404. Laravel sigue siendo la autoridad de
 * sesión, CSRF, permisos y reglas; el BFF solo impide que el navegador elija destinos o envíe datos inesperados.
 */

export type Metodo = 'GET' | 'POST' | 'PUT' | 'DELETE';

export interface Operacion {
  metodo: Metodo;
  /** Ruta relativa a /api/panel/v1, completa (de principio a fin). */
  patron: RegExp;
  /** Campos del cuerpo JSON que se reenvían (solo cadenas, números, booleanos o null). */
  campos?: readonly string[];
  /** Campos del cuerpo que son listas de textos (roles o permisos): hasta `MAX_LISTA` elementos de texto acotado. */
  listas?: readonly string[];
  /** El cuerpo trae `horarios`: hasta `MAX_HORARIOS` bloques de horario de una convocatoria (campos acotados). */
  horarios?: boolean;
  /** El cuerpo trae `asistencias`: pares {matricula_id, estado, observacion} de una sesión. */
  asistencias?: boolean;
  /** El cuerpo trae `cuotas`: hasta `MAX_CUOTAS` pares {monto, vence} de un plan fraccionado. */
  cuotas?: boolean;
  /** Parámetros de consulta que se reenvían. */
  consulta?: readonly string[];
  /** La respuesta es un archivo con alguno de estos tipos MIME. */
  archivo?: readonly string[];
  /** El archivo se muestra en la página (vista previa) en lugar de descargarse. */
  enLinea?: boolean;
  /**
   * Cuerpo `multipart/form-data`: campos de texto que se reenvían. El único archivo admitido es `imagen` (una sola
   * imagen JPEG, PNG o WebP de hasta `MAX_IMAGEN` bytes); cualquier otro campo o archivo se descarta.
   */
  multipart?: readonly string[];
  /** Con `multipart`: además de una imagen, admite un PDF (solo los comprobantes de pago). Laravel lo revisa y lo sirve solo como descarga. */
  pdf?: boolean;
}

/** Tamaño máximo de la imagen adjunta (comprobantes de pago en `PagoController`: 3 MB). */
export const MAX_IMAGEN = 3 * 1024 * 1024;

/** Elementos admitidos en un campo de lista (Laravel limita roles a 20 y permisos a 100). */
export const MAX_LISTA = 100;

/** Bloques de horario admitidos por convocatoria (igual que `Convocatoria::MAX_HORARIOS` en Laravel). */
export const MAX_HORARIOS = 20;

export const MAX_ASISTENCIAS = 300;
const MAX_CUOTAS = 24;

export interface CuotaReenviada {
  monto: string | null;
  vence: string | null;
}

export interface AsistenciaReenviada {
  matricula_id: number | null;
  estado: string | null;
  observacion: string | null;
}

export interface HorarioReenviado {
  dia_semana: number | null;
  hora_inicio: string | null;
  hora_fin: string | null;
}

export type ValorCuerpo = string | number | boolean | null | string[] | HorarioReenviado[] | AsistenciaReenviada[] | CuotaReenviada[] | Record<string, string | number | boolean | null>;

const ID = '[1-9][0-9]{0,17}';
const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

const CAMPOS_ESTUDIANTE = ['dni', 'nombres', 'apellidos', 'email', 'telefono', 'fecha_nacimiento', 'direccion', 'estado'] as const;
const CAMPOS_CURSO = ['codigo', 'nombre', 'descripcion', 'categoria', 'duracion_horas', 'precio', 'modalidad', 'horario', 'cupos_maximos', 'estado', 'enlace_grupo_whatsapp'] as const;
const CAMPOS_FICHA = ['titulo', 'resumen', 'modalidad', 'horario', 'fecha_inicio', 'duracion', 'certificacion', 'temario', 'inscripcion', 'vigente_desde', 'vigente_hasta', 'confirmacion_publica', '_revision'] as const;
const FILTROS_REPORTE = ['tipo', 'busqueda', 'estado', 'curso_id', 'eliminados', 'completo', 'lote'] as const;
const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
const IMAGENES = ['image/jpeg', 'image/png', 'image/webp'] as const;
const CAMPOS_CONVOCATORIA = ['curso_id', 'nombre', 'modalidad', 'fecha_inicio', 'fecha_fin', 'cupos_maximos', '_revision'] as const;
const CAMPOS_SESION = ['numero', 'fecha', 'hora_inicio', 'hora_fin', 'tema', '_revision'] as const;
const CAMPOS_EVALUACION = ['nombre', 'peso', 'fecha', '_revision'] as const;
const CAMPOS_MATRICULA = ['estudiante_id', 'curso_id', 'fecha_matricula', 'estado', 'motivo', 'observaciones'] as const;
/** La convocatoria y la creación automática de obligación solo se eligen al registrar. */
const CAMPOS_MATRICULA_ALTA = [...CAMPOS_MATRICULA, 'convocatoria', 'crear_obligacion'] as const;
const FILTROS_MATRICULAS = ['busqueda', 'estado', 'curso_id', 'convocatoria', 'estado_cobro', 'eliminados'] as const;

const SESION = '[a-f0-9]{32}';
const CAMPOS_GESTION_RECLAMACION = ['operacion', 'responsable_id', 'tipo', 'nota', 'asunto', 'cuerpo', 'fecha_recepcion', 'medio', 'constancia', 'respuesta', 'canal', 'envio_id', 'confirmar_envio'] as const;
const CAMPOS_USUARIO = ['name', 'email', 'password', 'password_confirmation', 'temporal'] as const;

export const OPERACIONES: readonly Operacion[] = [
  // Cuenta propia compartida por los dos paneles: notificaciones, perfil y sesiones
  { metodo: 'GET', patron: /^compartido\/notificaciones\/(recientes|conteos)$/ },
  { metodo: 'POST', patron: /^compartido\/notificaciones\/leidas$/ },
  { metodo: 'POST', patron: new RegExp(`^compartido/notificaciones/${ID}/leida$`) },
  { metodo: 'PUT', patron: /^compartido\/perfil$/, campos: ['name', 'email', 'password', 'password_confirmation', 'current_password'] },
  { metodo: 'PUT', patron: /^compartido\/perfil\/preferencias$/, campos: ['telefono', 'interno', 'email', 'whatsapp', 'whatsapp_consentimiento', 'recordatorio_cuotas', 'recordatorio_sesiones', 'recordatorio_reclamaciones', 'alerta_accesos'] },
  { metodo: 'POST', patron: /^compartido\/perfil\/2fa\/iniciar$/, campos: ['current_password'] },
  { metodo: 'POST', patron: /^compartido\/perfil\/2fa\/confirmar$/, campos: ['codigo'] },
  { metodo: 'POST', patron: /^compartido\/perfil\/2fa\/desactivar$/, campos: ['current_password', 'codigo'] },
  { metodo: 'POST', patron: /^compartido\/auditoria\/exportar$/, consulta: ['accion', 'modelo', 'usuario', 'desde', 'hasta', 'formato'], archivo: ['application/json', 'text/csv', XLSX] },
  { metodo: 'POST', patron: /^compartido\/respaldo\/exportar$/, consulta: ['tablas', 'desde', 'hasta'], archivo: ['application/zip'] },
  { metodo: 'POST', patron: /^compartido\/asistente\/mensaje$/, campos: ['mensaje', 'session_id'] },
  { metodo: 'POST', patron: /^compartido\/reclamaciones\/exportar$/, consulta: ['formato', 'busqueda', 'tipo', 'estado', 'canal', 'responsable', 'plazo', 'desde', 'hasta', 'sort'], archivo: ['text/csv', XLSX] },
  { metodo: 'POST', patron: /^compartido\/reclamaciones\/asignar-lote$/, campos: ['responsable_id'], listas: ['identificadores'] },
  { metodo: 'POST', patron: new RegExp(`^compartido/reclamaciones/${UUID}/gestion$`), campos: CAMPOS_GESTION_RECLAMACION },
  // Mensajes de «Contáctanos»: marcar como atendido (el listado lo pide el servidor de Next)
  { metodo: 'POST', patron: /^compartido\/reclamaciones-mensajes\/atender-lote$/, listas: ['identificadores'] },
  { metodo: 'POST', patron: new RegExp(`^compartido/reclamaciones-mensajes/${UUID}/atender$`) },
  // Aprobaciones internas: el servidor decide quién puede qué; aquí solo viajan los campos de cada operación
  { metodo: 'POST', patron: /^compartido\/aprobaciones\/exportar$/, consulta: ['formato', 'busqueda', 'estado', 'tipo', 'alcance'], archivo: ['text/csv', XLSX] },
  { metodo: 'POST', patron: /^compartido\/aprobaciones$/, campos: ['titulo', 'tipo', 'descripcion', 'referencia', 'comentario'] },
  { metodo: 'PUT', patron: new RegExp(`^compartido/aprobaciones/${ID}$`), campos: ['titulo', 'tipo', 'descripcion', 'referencia', 'comentario'] },
  { metodo: 'POST', patron: new RegExp(`^compartido/aprobaciones/${ID}/aprobar$`), campos: ['comentario'] },
  { metodo: 'POST', patron: new RegExp(`^compartido/aprobaciones/${ID}/(rechazar|reabrir)$`), campos: ['motivo'] },
  { metodo: 'DELETE', patron: new RegExp(`^compartido/aprobaciones/${ID}$`) },
  { metodo: 'POST', patron: /^compartido\/usuarios\/exportar$/, consulta: ['formato', 'busqueda', 'rol', 'estado', 'eliminados', 'sort'], archivo: ['text/csv', XLSX] },
  { metodo: 'POST', patron: /^compartido\/usuarios$/, campos: CAMPOS_USUARIO, listas: ['roles'] },
  { metodo: 'PUT', patron: new RegExp(`^compartido/usuarios/${ID}$`), campos: CAMPOS_USUARIO, listas: ['roles'] },
  { metodo: 'DELETE', patron: new RegExp(`^compartido/usuarios/${ID}$`) },
  { metodo: 'POST', patron: new RegExp(`^compartido/usuarios/${ID}/(desactivar|restaurar|desbloquear|cerrar-sesiones|invitar)$`) },
  { metodo: 'POST', patron: new RegExp(`^compartido/usuarios/${ID}/suspender$`), campos: ['dias', 'motivo'] },
  { metodo: 'POST', patron: /^compartido\/roles$/, campos: ['name'], listas: ['permissions'] },
  { metodo: 'PUT', patron: new RegExp(`^compartido/roles/${ID}$`), campos: ['name'], listas: ['permissions'] },
  { metodo: 'DELETE', patron: new RegExp(`^compartido/roles/${ID}$`) },
  { metodo: 'POST', patron: /^compartido\/perfil\/sesiones\/cerrar-otras$/, campos: ['current_password'] },
  { metodo: 'POST', patron: new RegExp(`^compartido/perfil/sesiones/${SESION}/cerrar$`), campos: ['current_password'] },

  // Consulta de DNI (JSON.pe desde Laravel): solo consulta; el token nunca llega al navegador
  { metodo: 'POST', patron: /^academico\/consultas\/dni$/, campos: ['dni'] },

  // Búsqueda global del panel académico (paleta Ctrl+K)
  { metodo: 'GET', patron: /^academico\/busqueda$/, consulta: ['q'] },

  // Sesiones de clase
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/sesiones$`), campos: CAMPOS_SESION },
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/sesiones/generar$`), campos: ['desde', 'hasta'] },
  { metodo: 'PUT', patron: new RegExp(`^academico/sesiones/${UUID}$`), campos: CAMPOS_SESION },
  { metodo: 'POST', patron: new RegExp(`^academico/sesiones/${UUID}/cancelar$`), campos: ['motivo', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^academico/sesiones/${UUID}/realizada$`) },

  // Asistencia: la lista de una sesión se lee desde el navegador al abrir el diálogo; el resumen se pide en el servidor
  { metodo: 'GET', patron: new RegExp(`^academico/sesiones/${UUID}/asistencias$`) },
  { metodo: 'PUT', patron: new RegExp(`^academico/sesiones/${UUID}/asistencias$`), asistencias: true },
  { metodo: 'PUT', patron: new RegExp(`^academico/sesiones/${UUID}/asistencias/[0-9]{1,10}$`), campos: ['estado', 'motivo'] },
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/asistencias/exportar$`), archivo: [XLSX] },


  // Caja del administrador: matrícula + abono/pago + comprobante (obligatorio Yape/Plin, opcional otros)
  { metodo: 'POST', patron: /^academico\/matriculas\/con-pago$/, multipart: ['estudiante_id', 'convocatoria', 'curso_id', 'metodo', 'monto', 'fecha_pago', 'referencia', '_clave'], pdf: true },

  // Finanzas (solo PEN y métodos activos): la clave de idempotencia viaja como `_clave` y Laravel la convierte en cabecera
  { metodo: 'GET', patron: /^academico\/pagos\/metodos$/ },
  { metodo: 'GET', patron: new RegExp(`^academico/pagos/obligaciones/${UUID}/recibo$`), archivo: ['application/pdf'] },
  { metodo: 'POST', patron: /^academico\/matriculas\/[0-9]{1,10}\/obligacion$/, campos: ['concepto', 'plan', 'monto_total'], cuotas: true },
  { metodo: 'POST', patron: new RegExp(`^academico/matriculas/${ID}/obligacion/generar$`) },
  { metodo: 'POST', patron: new RegExp(`^academico/pagos/obligaciones/${UUID}/pagos$`), campos: ['metodo', 'monto', 'fecha_pago', 'referencia', 'confirmar', '_clave'] },
  // «Subir comprobante» de Pagos: busca la matrícula, lee su obligación (si la tiene) y registra el pago sobre la matrícula
  // (Laravel crea la obligación si falta). Caja diaria y aviso de código de operación repetido, usados desde la misma pantalla.
  { metodo: 'GET', patron: /^academico\/matriculas$/, consulta: ['busqueda', 'estado', 'per_page'] },
  { metodo: 'GET', patron: new RegExp(`^academico/matriculas/${ID}/obligacion$`) },
  { metodo: 'POST', patron: new RegExp(`^academico/matriculas/${ID}/pagos$`), campos: ['metodo', 'monto', 'fecha_pago', 'referencia', 'confirmar', '_clave'] },
  { metodo: 'GET', patron: /^academico\/pagos\/verificar-referencia$/, consulta: ['referencia'] },
  { metodo: 'GET', patron: /^academico\/pagos\/caja-diaria\/resumen$/, consulta: ['fecha'] },
  { metodo: 'POST', patron: /^academico\/pagos\/caja-diaria\/pdf$/, archivo: ['application/pdf'] },
  { metodo: 'POST', patron: new RegExp(`^academico/pagos/obligaciones/${UUID}/anular$`), campos: ['motivo'] },
  { metodo: 'POST', patron: /^academico\/pagos\/exportar$/, consulta: ['busqueda', 'estado', 'desde', 'hasta', 'sort'], archivo: [XLSX] },
  // Captura del comprobante: una sola imagen (campo `imagen`); se muestra en línea y solo con permiso en Laravel
  // Una captura se muestra en línea; un PDF solo se descarga (el BFF lo fuerza, aunque `enLinea` esté activo).
  { metodo: 'GET', patron: new RegExp(`^academico/pagos/transacciones/${UUID}/comprobante$`), archivo: [...IMAGENES, 'application/pdf'], enLinea: true },
  { metodo: 'POST', patron: new RegExp(`^academico/pagos/transacciones/${UUID}/comprobante$`), multipart: [], pdf: true },
  { metodo: 'POST', patron: new RegExp(`^academico/pagos/transacciones/${UUID}/confirmar$`) },
  { metodo: 'POST', patron: new RegExp(`^academico/pagos/transacciones/${UUID}/(rechazar|reversar)$`), campos: ['motivo'] },


  // Estudiantes
  { metodo: 'POST', patron: /^academico\/estudiantes\/exportar$/, consulta: ['formato', 'busqueda', 'estado', 'eliminados', 'sort'], archivo: ['text/csv', XLSX] },
  { metodo: 'POST', patron: new RegExp(`^academico/estudiantes/${ID}/historial/pdf$`), archivo: ['application/pdf'] },
  { metodo: 'POST', patron: /^academico\/estudiantes$/, campos: CAMPOS_ESTUDIANTE },
  { metodo: 'PUT', patron: new RegExp(`^academico/estudiantes/${ID}$`), campos: CAMPOS_ESTUDIANTE },
  { metodo: 'DELETE', patron: new RegExp(`^academico/estudiantes/${ID}$`) },
  { metodo: 'POST', patron: new RegExp(`^academico/estudiantes/${ID}/restaurar$`) },

  // Cursos y ficha pública (la revisión viaja en el cuerpo: `_revision`)
  { metodo: 'GET', patron: new RegExp(`^academico/cursos/${ID}$`) },
  { metodo: 'POST', patron: /^academico\/cursos\/exportar$/, consulta: ['formato', 'busqueda', 'estado', 'modalidad', 'categoria', 'con_cupos', 'publicado', 'eliminados', 'sort'], archivo: ['text/csv', XLSX] },
  { metodo: 'POST', patron: /^academico\/cursos$/, campos: CAMPOS_CURSO },
  { metodo: 'PUT', patron: new RegExp(`^academico/cursos/${ID}$`), campos: CAMPOS_CURSO },
  { metodo: 'POST', patron: new RegExp(`^academico/cursos/${ID}/duplicar$`) },
  { metodo: 'DELETE', patron: new RegExp(`^academico/cursos/${ID}$`) },
  { metodo: 'POST', patron: new RegExp(`^academico/cursos/${ID}/restaurar$`) },
  { metodo: 'GET', patron: new RegExp(`^academico/cursos/${ID}/publicacion$`) },
  { metodo: 'PUT', patron: new RegExp(`^academico/cursos/${ID}/publicacion$`), campos: CAMPOS_FICHA },
  { metodo: 'POST', patron: new RegExp(`^academico/cursos/${ID}/publicacion/imagen$`), multipart: ['_revision'] },
  { metodo: 'POST', patron: new RegExp(`^academico/cursos/${ID}/publicacion/imagen/eliminar$`), campos: ['_revision'] },
  { metodo: 'POST', patron: new RegExp(`^academico/cursos/${ID}/publicacion/retirar$`), campos: ['_revision'] },

  // Reportes de matrículas (Excel y PDF se generan en Laravel)
  { metodo: 'GET', patron: /^academico\/reportes$/, consulta: FILTROS_REPORTE },
  { metodo: 'POST', patron: /^academico\/reportes\/exportar\/excel$/, consulta: FILTROS_REPORTE, archivo: [XLSX] },
  { metodo: 'POST', patron: /^academico\/reportes\/exportar\/pdf$/, consulta: FILTROS_REPORTE, archivo: ['application/pdf'] },

  // Convocatorias y horarios (Ola 3). Los horarios se sustituyen juntos y llevan la `_revision` de la convocatoria.
  { metodo: 'GET', patron: /^academico\/cursos$/, consulta: ['busqueda', 'estado'] },
  // Selector de convocatoria del formulario de matrícula (las vigentes de un curso)
  { metodo: 'GET', patron: /^academico\/convocatorias$/, consulta: ['curso_id', 'vigentes'] },
  { metodo: 'POST', patron: /^academico\/convocatorias\/exportar$/, consulta: ['formato', 'busqueda', 'estado', 'curso_id', 'desde', 'hasta', 'vigentes', 'sort'], archivo: ['text/csv', XLSX] },
  { metodo: 'GET', patron: new RegExp(`^academico/convocatorias/${UUID}/calendario\\.ics$`), archivo: ['text/calendar'] },
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/lista-aula/pdf$`), archivo: ['application/pdf'] },
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/contactos/csv$`), archivo: ['text/csv'] },
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/siguiente$`), campos: ['fecha_inicio', 'fecha_fin', 'cupos_maximos', 'nombre'] },
  { metodo: 'POST', patron: /^academico\/convocatorias$/, campos: CAMPOS_CONVOCATORIA },
  { metodo: 'PUT', patron: new RegExp(`^academico/convocatorias/${UUID}$`), campos: CAMPOS_CONVOCATORIA },
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/estado$`), campos: ['estado', '_revision'] },
  { metodo: 'PUT', patron: new RegExp(`^academico/convocatorias/${UUID}/horarios$`), campos: ['_revision'], horarios: true },

  // Calificaciones: evaluaciones, notas y acta (la estructura y el cierre llevan `_revision`; la nota no)
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/evaluaciones$`), campos: CAMPOS_EVALUACION },
  { metodo: 'PUT', patron: new RegExp(`^academico/convocatorias/${UUID}/evaluaciones/${UUID}$`), campos: CAMPOS_EVALUACION },
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/evaluaciones/${UUID}/eliminar$`), campos: ['_revision'] },
  { metodo: 'PUT', patron: new RegExp(`^academico/convocatorias/${UUID}/calificaciones$`), campos: ['evaluacion', 'matricula_id', 'nota', 'nota_actual', 'motivo'] },
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/acta/cerrar$`), campos: ['_revision'] },
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/acta/exportar$`), archivo: [XLSX] },
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/acta/borrador/exportar$`), archivo: [XLSX] },

  // Certificados institucionales
  { metodo: 'GET', patron: new RegExp(`^academico/certificados/${UUID}/pdf$`), archivo: ['application/pdf'] },
  { metodo: 'POST', patron: new RegExp(`^academico/matriculas/${ID}/certificado$`), campos: ['motivo_excepcion'] },
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/certificados/emitir-lote$`) },
  { metodo: 'POST', patron: new RegExp(`^academico/certificados/${UUID}/anular$`), campos: ['motivo'] },

  // Matrículas
  { metodo: 'GET', patron: /^academico\/matriculas\/opciones\/(estudiantes|cursos)$/, consulta: ['q', 'contexto'] },
  { metodo: 'GET', patron: new RegExp(`^academico/matriculas/${ID}/constancia$`), archivo: ['application/pdf'] },
  { metodo: 'POST', patron: new RegExp(`^academico/matriculas/${ID}/encuesta$`) },
  { metodo: 'PUT', patron: new RegExp(`^academico/matriculas/${ID}/etiqueta$`), campos: ['etiqueta'] },
  { metodo: 'POST', patron: /^academico\/matriculas$/, campos: CAMPOS_MATRICULA_ALTA },
  { metodo: 'PUT', patron: new RegExp(`^academico/matriculas/${ID}$`), campos: CAMPOS_MATRICULA },
  { metodo: 'POST', patron: new RegExp(`^academico/matriculas/${ID}/anular$`), campos: ['motivo'] },
  { metodo: 'POST', patron: new RegExp(`^academico/matriculas/${ID}/restaurar$`) },
  { metodo: 'DELETE', patron: new RegExp(`^academico/matriculas/${ID}$`), campos: ['motivo'] },
  { metodo: 'POST', patron: /^academico\/matriculas\/exportar$/, consulta: FILTROS_MATRICULAS, archivo: ['text/csv'] },
];

export function buscarOperacion(metodo: string, ruta: string): Operacion | null {
  return OPERACIONES.find((operacion) => operacion.metodo === metodo && operacion.patron.test(ruta)) ?? null;
}

/** Cuerpo reenviado: solo los campos declarados y con valores escalares. */
export function cuerpoPermitido(operacion: Operacion, cuerpo: Record<string, unknown>): Record<string, ValorCuerpo> | undefined {
  if (operacion.metodo === 'GET') return undefined;
  if (operacion.metodo === 'DELETE' && (!operacion.campos || operacion.campos.length === 0)) return undefined;
  const resultado: Record<string, ValorCuerpo> = {};
  for (const campo of operacion.campos ?? []) {
    const valor = cuerpo[campo];
    if (valor === null || typeof valor === 'string' || typeof valor === 'number' || typeof valor === 'boolean') {
      resultado[campo] = typeof valor === 'string' ? valor.slice(0, 4000) : valor;
    }
  }
  for (const campo of operacion.listas ?? []) {
    const valor = cuerpo[campo];
    // Solo listas de textos cortos; una lista ausente se omite (Laravel distingue «sin campo» de «lista vacía»).
    if (Array.isArray(valor) && valor.length <= MAX_LISTA && valor.every((elemento) => typeof elemento === 'string')) {
      resultado[campo] = valor.map((elemento: string) => elemento.slice(0, 100));
    }
  }
  const horarios = cuerpo.horarios;
  if (operacion.horarios && Array.isArray(horarios) && horarios.length <= MAX_HORARIOS && horarios.every(esObjeto)) {
    const texto = (valor: unknown): string | null => (typeof valor === 'string' ? valor.slice(0, 120) : null);
    resultado.horarios = horarios.map((h) => ({
      dia_semana: typeof h.dia_semana === 'number' ? h.dia_semana : null,
      hora_inicio: texto(h.hora_inicio),
      hora_fin: texto(h.hora_fin),
    }));
  }
  const cuotas = cuerpo.cuotas;
  if (operacion.cuotas && Array.isArray(cuotas) && cuotas.length <= MAX_CUOTAS && cuotas.every(esObjeto)) {
    resultado.cuotas = cuotas.map((c) => ({
      monto: typeof c.monto === 'string' ? c.monto.slice(0, 20) : null,
      vence: typeof c.vence === 'string' ? c.vence.slice(0, 10) : null,
    }));
  }
  const asistencias = cuerpo.asistencias;
  if (operacion.asistencias && Array.isArray(asistencias) && asistencias.length <= MAX_ASISTENCIAS && asistencias.every(esObjeto)) {
    resultado.asistencias = asistencias.map((a) => ({
      matricula_id: typeof a.matricula_id === 'number' ? a.matricula_id : null,
      estado: typeof a.estado === 'string' ? a.estado.slice(0, 20) : null,
      observacion: typeof a.observacion === 'string' ? a.observacion.slice(0, 200) : null,
    }));
  }
  return resultado;
}

function esObjeto(valor: unknown): valor is Record<string, unknown> {
  return typeof valor === 'object' && valor !== null && !Array.isArray(valor);
}

/** Cadena de consulta con solo los parámetros declarados (texto acotado). */
export function consultaPermitida(operacion: Operacion, parametros: URLSearchParams): string {
  const salida = new URLSearchParams();
  for (const nombre of operacion.consulta ?? []) {
    const valor = parametros.get(nombre);
    if (valor !== null && valor.trim() !== '') salida.set(nombre, valor.trim().slice(0, 200));
  }
  const texto = salida.toString();
  return texto === '' ? '' : `?${texto}`;
}
