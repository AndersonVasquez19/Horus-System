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
  /** El cuerpo trae `contactos`: hasta `MAX_CONTACTOS` objetos con campos de texto acotados (clientes). */
  contactos?: boolean;
  /** Campos de un único objeto anidado `oportunidad` (conversión de un prospecto): solo escalares, texto acotado. */
  oportunidad?: readonly string[];
  /** El cuerpo trae `horarios`: hasta `MAX_HORARIOS` bloques de horario de una convocatoria (campos acotados). */
  horarios?: boolean;
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
}

/** Tamaño máximo de la imagen de la ficha (igual que `ImagenesServicios::MAX_KB` en Laravel: 3 MB). */
export const MAX_IMAGEN = 3 * 1024 * 1024;

/** Elementos admitidos en un campo de lista (Laravel limita roles a 20 y permisos a 100). */
export const MAX_LISTA = 100;

/** Contactos admitidos por cliente (igual que `GuardarClienteRequest::MAX_CONTACTOS` en Laravel). */
export const MAX_CONTACTOS = 10;

/** Bloques de horario admitidos por convocatoria (igual que `Convocatoria::MAX_HORARIOS` en Laravel). */
export const MAX_HORARIOS = 20;

export interface HorarioReenviado {
  dia_semana: number | null;
  hora_inicio: string | null;
  hora_fin: string | null;
  lugar: string | null;
  docente: string | null;
}

export interface ContactoReenviado {
  nombre: string | null;
  cargo: string | null;
  email: string | null;
  telefono: string | null;
  principal: boolean;
}

export type ValorCuerpo = string | number | boolean | null | string[] | ContactoReenviado[] | HorarioReenviado[] | Record<string, string | number | boolean | null>;

const ID = '[1-9][0-9]{0,17}';
const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

const CAMPOS_ESTUDIANTE = ['dni', 'nombres', 'apellidos', 'email', 'telefono', 'fecha_nacimiento', 'direccion', 'estado'] as const;
const CAMPOS_CURSO = ['codigo', 'nombre', 'descripcion', 'categoria', 'duracion_horas', 'precio', 'modalidad', 'horario', 'cupos_maximos', 'estado'] as const;
const CAMPOS_FICHA = ['titulo', 'resumen', 'modalidad', 'horario', 'inscripcion', 'vigente_desde', 'vigente_hasta', 'confirmacion_publica', '_revision'] as const;
const FILTROS_REPORTE = ['busqueda', 'estado', 'eliminados', 'lote'] as const;
const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
const CAMPOS_SERVICIO = ['codigo', 'nombre', 'categoria', 'estado', '_revision'] as const;
const CAMPOS_FICHA_SERVICIO = ['titulo', 'resumen', 'descripcion', 'categoria', 'vigente_desde', 'vigente_hasta', 'titulo_tarjetas', 'imagen_principal_alt', '_revision'] as const;
const CAMPOS_IMAGEN = ['imagen_principal_alt', '_revision'] as const;
const CAMPOS_TARJETA = ['titulo', 'descripcion', 'caracteristicas', 'variante_imagen', 'imagen_alt', 'activo', 'orden', 'quitar_imagen', '_revision'] as const;
const IMAGENES = ['image/jpeg', 'image/png', 'image/webp'] as const;
const CAMPOS_COTIZACION = ['contacto_nombre', 'necesidad_cliente', 'contacto_email', 'contacto_telefono', 'servicio_uuid', '_revision', '_clave'] as const;
const CAMPOS_CLIENTE = ['tipo', 'nombre', 'documento_tipo', 'documento_numero', 'email', 'telefono', 'direccion', '_revision'] as const;
const CAMPOS_PROSPECTO = ['nombre_contacto', 'empresa', 'email', 'telefono', 'origen', 'servicio_interes', 'proximo_seguimiento', '_revision'] as const;
const CAMPOS_OPORTUNIDAD = ['cliente', 'titulo', 'monto_estimado', 'probabilidad', 'fecha_cierre_estimada', 'servicio', 'cotizacion', '_revision'] as const;
const CAMPOS_OPORTUNIDAD_EDICION = ['titulo', 'monto_estimado', 'probabilidad', 'fecha_cierre_estimada', '_revision'] as const;
const CAMPOS_PROYECTO = ['cliente', 'oportunidad', 'nombre', 'descripcion', 'fecha_inicio', 'fecha_fin_estimada', 'presupuesto', '_revision'] as const;
const CAMPOS_PROYECTO_EDICION = ['nombre', 'descripcion', 'fecha_inicio', 'fecha_fin_estimada', 'presupuesto', '_revision'] as const;
const CAMPOS_TICKET = ['cliente', 'proyecto', 'asunto', 'descripcion', 'prioridad', 'canal', '_revision'] as const;
const CAMPOS_CONVOCATORIA = ['curso_id', 'nombre', 'modalidad', 'fecha_inicio', 'fecha_fin', 'cupos_maximos', '_revision'] as const;
const CAMPOS_MATRICULA = ['estudiante_id', 'curso_id', 'fecha_matricula', 'estado', 'monto_pagado', 'observaciones'] as const;
/** La convocatoria solo se elige al registrar: queda fija y la edición no la acepta. */
const CAMPOS_MATRICULA_ALTA = [...CAMPOS_MATRICULA, 'convocatoria'] as const;
const FILTROS_MATRICULAS = ['busqueda', 'estado', 'curso_id', 'eliminados'] as const;

const SESION = '[a-f0-9]{32}';
const CAMPOS_GESTION_RECLAMACION = ['operacion', 'responsable_id', 'tipo', 'nota', 'asunto', 'cuerpo', 'fecha_recepcion', 'medio', 'constancia', 'respuesta', 'canal', 'envio_id', 'confirmar_envio'] as const;
const CAMPOS_USUARIO = ['name', 'email', 'password', 'password_confirmation'] as const;

export const OPERACIONES: readonly Operacion[] = [
  // Cuenta propia compartida por los dos paneles: notificaciones, perfil y sesiones
  { metodo: 'GET', patron: /^compartido\/notificaciones\/recientes$/ },
  { metodo: 'POST', patron: /^compartido\/notificaciones\/leidas$/ },
  { metodo: 'POST', patron: new RegExp(`^compartido/notificaciones/${ID}/leida$`) },
  { metodo: 'PUT', patron: /^compartido\/perfil$/, campos: ['name', 'email', 'password', 'password_confirmation', 'current_password'] },
  { metodo: 'POST', patron: /^compartido\/auditoria\/exportar$/, consulta: ['accion', 'modelo', 'usuario', 'desde', 'hasta'], archivo: ['application/json'] },
  { metodo: 'POST', patron: new RegExp(`^compartido/reclamaciones/${UUID}/gestion$`), campos: CAMPOS_GESTION_RECLAMACION },
  { metodo: 'POST', patron: /^compartido\/usuarios$/, campos: CAMPOS_USUARIO, listas: ['roles'] },
  { metodo: 'PUT', patron: new RegExp(`^compartido/usuarios/${ID}$`), campos: CAMPOS_USUARIO, listas: ['roles'] },
  { metodo: 'DELETE', patron: new RegExp(`^compartido/usuarios/${ID}$`) },
  { metodo: 'POST', patron: /^compartido\/roles$/, campos: ['name'], listas: ['permissions'] },
  { metodo: 'PUT', patron: new RegExp(`^compartido/roles/${ID}$`), campos: ['name'], listas: ['permissions'] },
  { metodo: 'DELETE', patron: new RegExp(`^compartido/roles/${ID}$`) },
  { metodo: 'POST', patron: /^compartido\/perfil\/sesiones\/cerrar-otras$/, campos: ['current_password'] },
  { metodo: 'POST', patron: new RegExp(`^compartido/perfil/sesiones/${SESION}/cerrar$`), campos: ['current_password'] },

  // Estudiantes
  { metodo: 'POST', patron: /^academico\/estudiantes$/, campos: CAMPOS_ESTUDIANTE },
  { metodo: 'PUT', patron: new RegExp(`^academico/estudiantes/${ID}$`), campos: CAMPOS_ESTUDIANTE },
  { metodo: 'DELETE', patron: new RegExp(`^academico/estudiantes/${ID}$`) },
  { metodo: 'POST', patron: new RegExp(`^academico/estudiantes/${ID}/restaurar$`) },

  // Cursos y ficha pública (la revisión viaja en el cuerpo: `_revision`)
  { metodo: 'POST', patron: /^academico\/cursos$/, campos: CAMPOS_CURSO },
  { metodo: 'PUT', patron: new RegExp(`^academico/cursos/${ID}$`), campos: CAMPOS_CURSO },
  { metodo: 'DELETE', patron: new RegExp(`^academico/cursos/${ID}$`) },
  { metodo: 'POST', patron: new RegExp(`^academico/cursos/${ID}/restaurar$`) },
  { metodo: 'GET', patron: new RegExp(`^academico/cursos/${ID}/publicacion$`) },
  { metodo: 'PUT', patron: new RegExp(`^academico/cursos/${ID}/publicacion$`), campos: CAMPOS_FICHA },
  { metodo: 'POST', patron: new RegExp(`^academico/cursos/${ID}/publicacion/retirar$`), campos: ['_revision'] },

  // Reportes de matrículas (Excel y PDF se generan en Laravel)
  { metodo: 'GET', patron: /^academico\/reportes$/, consulta: FILTROS_REPORTE },
  { metodo: 'POST', patron: /^academico\/reportes\/exportar\/excel$/, consulta: FILTROS_REPORTE, archivo: [XLSX] },
  { metodo: 'POST', patron: /^academico\/reportes\/exportar\/pdf$/, consulta: FILTROS_REPORTE, archivo: ['application/pdf'] },

  // Servicios tecnológicos y su ficha pública (la revisión viaja en el cuerpo: `_revision`)
  { metodo: 'POST', patron: /^servicios\/servicios$/, campos: CAMPOS_SERVICIO },
  { metodo: 'PUT', patron: new RegExp(`^servicios/servicios/${ID}$`), campos: CAMPOS_SERVICIO },
  { metodo: 'POST', patron: new RegExp(`^servicios/servicios/${ID}/estado$`), campos: ['estado', '_revision'] },
  { metodo: 'PUT', patron: new RegExp(`^servicios/servicios/${ID}/publicacion$`), campos: CAMPOS_FICHA_SERVICIO },
  { metodo: 'POST', patron: new RegExp(`^servicios/servicios/${ID}/publicacion/autorizar$`), campos: ['confirmacion_publica', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/servicios/${ID}/publicacion/retirar$`), campos: ['_revision'] },

  // Imagen principal y tarjetas de la ficha (multipart con una sola imagen; revisión en `_revision`)
  { metodo: 'GET', patron: new RegExp(`^servicios/servicios/${ID}/imagen$`), archivo: IMAGENES, enLinea: true },
  { metodo: 'GET', patron: new RegExp(`^servicios/servicios/${ID}/elementos/${ID}/imagen$`), archivo: IMAGENES, enLinea: true },
  { metodo: 'POST', patron: new RegExp(`^servicios/servicios/${ID}/imagen$`), multipart: CAMPOS_IMAGEN },
  { metodo: 'POST', patron: new RegExp(`^servicios/servicios/${ID}/imagen/quitar$`), campos: ['_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/servicios/${ID}/elementos$`), multipart: CAMPOS_TARJETA },
  { metodo: 'POST', patron: new RegExp(`^servicios/servicios/${ID}/elementos/${ID}$`), multipart: CAMPOS_TARJETA },
  { metodo: 'POST', patron: new RegExp(`^servicios/servicios/${ID}/elementos/${ID}/eliminar$`), campos: ['_revision'] },

  // Cotizaciones (identificador = UUID; la revisión en `_revision` y la idempotencia en `_clave`)
  { metodo: 'GET', patron: /^servicios\/cotizaciones\/opciones\/(responsables|servicios)$/ },
  { metodo: 'POST', patron: /^servicios\/cotizaciones$/, campos: CAMPOS_COTIZACION },
  { metodo: 'PUT', patron: new RegExp(`^servicios/cotizaciones/${UUID}$`), campos: CAMPOS_COTIZACION },
  { metodo: 'POST', patron: new RegExp(`^servicios/cotizaciones/${UUID}/estado$`), campos: ['estado', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/cotizaciones/${UUID}/responsable$`), campos: ['responsable_id', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/cotizaciones/${UUID}/seguimientos$`), campos: ['nota', 'proximo_seguimiento', '_revision', '_clave'] },

  // Clientes (CRM). Los contactos son una lista de objetos acotada: se reenvían por `contactos` (ver `cuerpoPermitido`).
  { metodo: 'GET', patron: /^servicios\/clientes\/opciones\/responsables$/ },
  // Selector de «vincular cliente» en una cotización: búsqueda acotada del listado (correo y documento ya van enmascarados).
  { metodo: 'GET', patron: /^servicios\/clientes$/, consulta: ['busqueda', 'estado'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/cotizaciones/${UUID}/cliente$`), campos: ['cliente', 'crear_cliente', '_revision'] },
  { metodo: 'POST', patron: /^servicios\/clientes$/, campos: CAMPOS_CLIENTE, contactos: true },
  { metodo: 'PUT', patron: new RegExp(`^servicios/clientes/${UUID}$`), campos: CAMPOS_CLIENTE, contactos: true },
  { metodo: 'POST', patron: new RegExp(`^servicios/clientes/${UUID}/estado$`), campos: ['estado', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/clientes/${UUID}/responsable$`), campos: ['responsable_id', '_revision'] },

  // Prospectos (CRM)
  { metodo: 'GET', patron: /^servicios\/prospectos\/opciones\/(responsables|servicios)$/ },
  { metodo: 'POST', patron: /^servicios\/prospectos$/, campos: CAMPOS_PROSPECTO },
  { metodo: 'PUT', patron: new RegExp(`^servicios/prospectos/${UUID}$`), campos: CAMPOS_PROSPECTO },
  { metodo: 'POST', patron: new RegExp(`^servicios/prospectos/${UUID}/estado$`), campos: ['estado', 'motivo_descarte', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/prospectos/${UUID}/responsable$`), campos: ['responsable_id', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/prospectos/${UUID}/convertir$`), campos: ['cliente', '_revision'], oportunidad: ['titulo', 'monto_estimado', 'fecha_cierre_estimada'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/cotizaciones/${UUID}/prospecto$`) },

  // Pipeline de oportunidades (CRM). El tablero y el listado son lecturas de las páginas de servidor; el navegador solo escribe.
  { metodo: 'GET', patron: /^servicios\/oportunidades\/opciones\/responsables$/ },
  { metodo: 'POST', patron: /^servicios\/oportunidades$/, campos: CAMPOS_OPORTUNIDAD },
  { metodo: 'PUT', patron: new RegExp(`^servicios/oportunidades/${UUID}$`), campos: CAMPOS_OPORTUNIDAD_EDICION },
  { metodo: 'POST', patron: new RegExp(`^servicios/oportunidades/${UUID}/etapa$`), campos: ['etapa', 'motivo_perdida', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/oportunidades/${UUID}/responsable$`), campos: ['responsable_id', '_revision'] },

  // Proyectos (CRM). Los hitos no tienen revisión propia: cada cambio viaja con la `_revision` del proyecto.
  { metodo: 'GET', patron: /^servicios\/proyectos\/opciones\/responsables$/ },
  { metodo: 'GET', patron: /^servicios\/oportunidades$/, consulta: ['etapa', 'abiertas', 'busqueda'] },
  { metodo: 'POST', patron: /^servicios\/proyectos$/, campos: CAMPOS_PROYECTO },
  { metodo: 'PUT', patron: new RegExp(`^servicios/proyectos/${UUID}$`), campos: CAMPOS_PROYECTO_EDICION },
  { metodo: 'POST', patron: new RegExp(`^servicios/proyectos/${UUID}/estado$`), campos: ['estado', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/proyectos/${UUID}/responsable$`), campos: ['responsable_id', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/proyectos/${UUID}/hitos$`), campos: ['nombre', 'fecha_objetivo', '_revision'] },
  { metodo: 'PUT', patron: new RegExp(`^servicios/proyectos/${UUID}/hitos/${UUID}$`), campos: ['nombre', 'fecha_objetivo', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/proyectos/${UUID}/hitos/${UUID}/completar$`), campos: ['completado', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/proyectos/${UUID}/hitos/${UUID}/eliminar$`), campos: ['_revision'] },

  // Soporte técnico (CRM). Las notas solo se añaden y no llevan revisión.
  { metodo: 'GET', patron: /^servicios\/tickets\/opciones\/asignados$/ },
  { metodo: 'POST', patron: /^servicios\/tickets$/, campos: CAMPOS_TICKET },
  { metodo: 'PUT', patron: new RegExp(`^servicios/tickets/${UUID}$`), campos: CAMPOS_TICKET },
  { metodo: 'POST', patron: new RegExp(`^servicios/tickets/${UUID}/estado$`), campos: ['estado', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/tickets/${UUID}/asignar$`), campos: ['asignado_a', '_revision'] },
  { metodo: 'POST', patron: new RegExp(`^servicios/tickets/${UUID}/notas$`), campos: ['texto', 'interna'] },

  // Reportes comerciales (CRM). La lectura (y la lista de responsables) la hace la página de servidor; el navegador solo exporta.
  { metodo: 'POST', patron: /^servicios\/reportes-comerciales\/exportar$/, consulta: ['desde', 'hasta', 'responsable_id'], archivo: [XLSX] },

  // Convocatorias y horarios (Ola 3). Los horarios se sustituyen juntos y llevan la `_revision` de la convocatoria.
  { metodo: 'GET', patron: /^academico\/cursos$/, consulta: ['busqueda', 'estado'] },
  // Selector de convocatoria del formulario de matrícula (las vigentes de un curso)
  { metodo: 'GET', patron: /^academico\/convocatorias$/, consulta: ['curso_id', 'vigentes'] },
  { metodo: 'POST', patron: /^academico\/convocatorias$/, campos: CAMPOS_CONVOCATORIA },
  { metodo: 'PUT', patron: new RegExp(`^academico/convocatorias/${UUID}$`), campos: CAMPOS_CONVOCATORIA },
  { metodo: 'POST', patron: new RegExp(`^academico/convocatorias/${UUID}/estado$`), campos: ['estado', '_revision'] },
  { metodo: 'PUT', patron: new RegExp(`^academico/convocatorias/${UUID}/horarios$`), campos: ['_revision'], horarios: true },

  // Matrículas
  { metodo: 'GET', patron: /^academico\/matriculas\/opciones\/(estudiantes|cursos)$/, consulta: ['q', 'contexto'] },
  { metodo: 'POST', patron: /^academico\/matriculas$/, campos: CAMPOS_MATRICULA_ALTA },
  { metodo: 'PUT', patron: new RegExp(`^academico/matriculas/${ID}$`), campos: CAMPOS_MATRICULA },
  { metodo: 'POST', patron: new RegExp(`^academico/matriculas/${ID}/(anular|restaurar)$`) },
  { metodo: 'DELETE', patron: new RegExp(`^academico/matriculas/${ID}$`) },
  { metodo: 'POST', patron: /^academico\/matriculas\/exportar$/, consulta: FILTROS_MATRICULAS, archivo: ['text/csv'] },
];

export function buscarOperacion(metodo: string, ruta: string): Operacion | null {
  return OPERACIONES.find((operacion) => operacion.metodo === metodo && operacion.patron.test(ruta)) ?? null;
}

/** Cuerpo reenviado: solo los campos declarados y con valores escalares. */
export function cuerpoPermitido(operacion: Operacion, cuerpo: Record<string, unknown>): Record<string, ValorCuerpo> | undefined {
  if (operacion.metodo === 'GET' || operacion.metodo === 'DELETE') return undefined;
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
  const oportunidad = cuerpo.oportunidad;
  if (operacion.oportunidad && esObjeto(oportunidad)) {
    const anidada: Record<string, string | number | boolean | null> = {};
    for (const campo of operacion.oportunidad) {
      const valor = oportunidad[campo];
      if (valor === null || typeof valor === 'string' || typeof valor === 'number' || typeof valor === 'boolean') {
        anidada[campo] = typeof valor === 'string' ? valor.slice(0, 200) : valor;
      }
    }
    resultado.oportunidad = anidada;
  }
  const horarios = cuerpo.horarios;
  if (operacion.horarios && Array.isArray(horarios) && horarios.length <= MAX_HORARIOS && horarios.every(esObjeto)) {
    const texto = (valor: unknown): string | null => (typeof valor === 'string' ? valor.slice(0, 120) : null);
    resultado.horarios = horarios.map((h) => ({
      dia_semana: typeof h.dia_semana === 'number' ? h.dia_semana : null,
      hora_inicio: texto(h.hora_inicio),
      hora_fin: texto(h.hora_fin),
      lugar: texto(h.lugar),
      docente: texto(h.docente),
    }));
  }
  const contactos = cuerpo.contactos;
  if (operacion.contactos && Array.isArray(contactos) && contactos.length <= MAX_CONTACTOS && contactos.every(esObjeto)) {
    const texto = (valor: unknown): string | null => (typeof valor === 'string' ? valor.slice(0, 200) : null);
    resultado.contactos = contactos.map((c) => ({
      nombre: texto(c.nombre),
      cargo: texto(c.cargo),
      email: texto(c.email),
      telefono: texto(c.telefono),
      principal: c.principal === true,
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
