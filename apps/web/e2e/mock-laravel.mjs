/**
 * Laravel FALSO para las pruebas e2e del panel. No es el backend: responde, con datos fijos, lo mínimo que `apps/web`
 * necesita para pintar las pantallas y probar la interfaz (diálogos, menús, formularios, accesibilidad).
 *
 * Qué NO prueba: las reglas de negocio, la autorización real ni la integración con Laravel. Eso se comprueba contra el
 * backend verdadero. Node puro, sin dependencias.
 *
 * Contrato que imita (`apps/web/src/lib/panel/laravel.ts`): `GET|POST ${HORUS_API_URL}/api/panel/v1/<ruta>` con la
 * cabecera `X-Horus-Web-Token` y la cookie `laravel-session`.
 *
 * Extras para las pruebas: `GET /__peticiones` devuelve lo que llegó; `DELETE /__peticiones` lo vacía.
 */
import http from 'node:http';

const PUERTO = Number(process.env.MOCK_LARAVEL_PORT ?? 4010);
const COOKIE_SESION = process.env.HORUS_SESSION_COOKIE?.trim() || 'laravel-session';
const VALOR_SESION = 'sesion-de-prueba';

const PERMISOS = [
  'aprobaciones.gestionar', 'aprobaciones.ver', 'asistente.ver', 'auditoria.exportar', 'auditoria.ver',
  'calificaciones.corregir', 'calificaciones.exportar', 'calificaciones.registrar', 'convocatorias.cerrar',
  'convocatorias.crear', 'convocatorias.editar', 'convocatorias.ver', 'cursos.crear', 'cursos.editar', 'cursos.eliminar',
  'cursos.publicar', 'cursos.restaurar', 'cursos.ver', 'estudiantes.crear', 'estudiantes.editar', 'estudiantes.eliminar',
  'estudiantes.restaurar', 'estudiantes.ver', 'matriculas.anular', 'matriculas.crear', 'matriculas.editar',
  'matriculas.eliminar', 'matriculas.exportar', 'matriculas.restaurar', 'matriculas.ver', 'pagos.confirmar',
  'pagos.exportar', 'pagos.planificar', 'pagos.registrar', 'pagos.reversar', 'pagos.ver', 'reclamaciones.gestionar',
  'reclamaciones.responder', 'reclamaciones.ver', 'reportes.ver', 'reportes.exportar.excel', 'reportes.exportar.pdf', 'respaldo.exportar', 'respaldo.ver', 'roles.crear',
  'roles.editar', 'roles.eliminar', 'roles.ver', 'sesiones.cancelar', 'sesiones.crear', 'sesiones.editar',
  'usuarios.asignar-roles', 'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar', 'usuarios.ver',
];

const SESION = {
  usuario: { id: 1, nombre: 'Administrador de prueba', email: 'admin@horus.test' },
  roles: ['admin'],
  permisos: PERMISOS,
  paneles: { academico: true },
  debe_cambiar_clave: false,
  two_factor_habilitado: false,
  csrf_token: 'csrf-de-prueba',
  expira_en: null,
};

const AHORA = '2026-11-20T15:00:00-05:00';

const NOMBRES = ['Ana', 'Luis', 'María', 'Carlos', 'Rosa', 'Jorge', 'Elena', 'Pedro', 'Lucía', 'Miguel'];
const APELLIDOS = ['Quispe Rojas', 'Torres Díaz', 'Vásquez León', 'Cruzado Paz', 'Mendoza Silva'];

const ESTUDIANTES = Array.from({ length: 25 }, (_, i) => ({
  id: i + 1,
  dni: String(70000001 + i),
  nombres: NOMBRES[i % NOMBRES.length],
  // Combinación nombre + apellido única en las 25 filas (los 10 nombres se repiten cada 10, el apellido se desfasa).
  apellidos: APELLIDOS[(i + Math.floor(i / 5)) % APELLIDOS.length],
  email: i % 4 === 0 ? null : `estudiante${i + 1}@ejemplo.test`,
  telefono: i % 3 === 0 ? null : `98765${String(4000 + i)}`,
  estado: i % 7 === 6 ? 'inactivo' : 'activo',
  eliminado: false,
  creado_en: AHORA,
}));

const CURSOS = Array.from({ length: 12 }, (_, i) => ({
  id: i + 1,
  codigo: `CUR-${String(i + 1).padStart(3, '0')}`,
  nombre: `Curso de prueba ${i + 1}`,
  categoria: 'tecnologia',
  modalidad: i % 2 === 0 ? 'presencial' : 'virtual',
  duracion_horas: 40 + i,
  precio: (250 + i * 10).toFixed(2),
  estado: 'activo',
  cupos_maximos: 10,
  cupos_disponibles: 10 - (i % 4),
  publicado: i % 3 === 0,
  enlace_grupo_whatsapp: null,
  eliminado: false,
  creado_en: AHORA,
}));

const peticiones = [];

function enviar(res, estado, cuerpo) {
  const texto = JSON.stringify(cuerpo);
  res.writeHead(estado, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': Buffer.byteLength(texto) });
  res.end(texto);
}

/** Pagina y filtra como lo hace `Listado::paginar`: `per_page` de 1 a 100 (20 por omisión) y `page`. */
function listado(filas, consulta, camposBusqueda) {
  const porPagina = Math.min(100, Math.max(1, Number.parseInt(consulta.get('per_page') ?? '', 10) || 20));
  const busqueda = (consulta.get('busqueda') ?? '').trim().toLowerCase();
  const filtradas = busqueda === ''
    ? filas
    : filas.filter((fila) => camposBusqueda.some((campo) => String(fila[campo] ?? '').toLowerCase().includes(busqueda)));
  const paginas = Math.max(1, Math.ceil(filtradas.length / porPagina));
  const pagina = Math.min(paginas, Math.max(1, Number.parseInt(consulta.get('page') ?? '', 10) || 1));
  const filtros = {};
  if (busqueda !== '') filtros.busqueda = consulta.get('busqueda');
  return {
    data: filtradas.slice((pagina - 1) * porPagina, pagina * porPagina),
    meta: { pagina, por_pagina: porPagina, total: filtradas.length, paginas, orden: consulta.get('sort') ?? '', filtros },
  };
}

const RESUMEN = {
  indicadores: {
    estudiantes_activos: 21, cursos_activos: 12, cupos_disponibles: 85, cupos_maximos: 120,
    matriculas_mes: 9, matriculas_pendientes: 3, matriculas_mes_anterior: 6, ingresos_mes: '1850.00',
  },
  bloques: {
    matriculas_por_mes: [
      { periodo: '2026-09', etiqueta: 'Sep', total: 4 }, { periodo: '2026-10', etiqueta: 'Oct', total: 6 },
      { periodo: '2026-11', etiqueta: 'Nov', total: 9 },
    ],
    ultimas_matriculas: [
      { id: 1, estudiante: 'Ana Quispe Rojas', curso: 'Curso de prueba 1', estado: 'pendiente', fecha: '2026-11-19' },
      { id: 2, estudiante: 'Luis Torres Díaz', curso: 'Curso de prueba 2', estado: 'pagada', fecha: '2026-11-18' },
    ],
  },
  acciones: { pagos_por_confirmar: { cantidad: 2, monto: '300.00' } },
  negocio: { cobrado_mes: '1850.00', saldo_por_cobrar: '640.00' },
  seguimiento: { aprobaciones_pendientes: 2, notificaciones_sin_leer: 3 },
};

const MENSAJE_LARGO = `Mensaje muy largo del historial. ${'Quisiera saber más detalles sobre el curso, los horarios y las modalidades disponibles. '.repeat(5)}Fin del mensaje largo.`;

const METRICAS_ASISTENTE = {
  dias: 30, consultas: 97, conversaciones: 32, respondidas: 87, contingencia: 10, sin_origen: 0, con_tema: 41, sin_dato: 3,
  etapas: { interesado: 18, matricula: 6, contacto: 2, reclamos: 1, whatsapp: 3 },
  temas: [
    { nombre: 'training_info', total: 24 }, { nombre: 'enrollment_request', total: 11 },
    { nombre: 'cortesia', total: 6 }, { nombre: 'unavailable_info', total: 3 },
  ],
  por_dia: ['19/11', '20/11', '21/11', '22/11', '23/11', '24/11', '25/11'].map((etiqueta, i) => ({ etiqueta, total: [4, 7, 15, 11, 3, 0, 22][i] })),
  votos: { utiles: 9, no_utiles: 1 }, separa_canal: true,
};

const intercambio = (id, tema, respuesta, extra = {}) => ({
  id, hora_local: '25/11 08:3' + (id % 10), tema, respuesta, privacidad: false, contingencia: false, voto: null, ...extra,
});

const CONVERSACIONES_ASISTENTE = [
  {
    clave: 3, visitante: '7F3A', probador: false, ultima_local: '25/11/2026 08:33', total: 2,
    temas: ['training_info', 'followup_attribute'], utiles: 1, no_utiles: 0,
    etapas: ['whatsapp', 'matricula'], contingencia: false, vista_previa: 'Para matricularte sigue estos pasos: 1. Elige el curso vigente de tu interés en nuestra web. 2. Escríbenos por WhatsApp para confirmar vacante…',
    intercambios: [
      intercambio(1, 'training_info', 'Cursos y capacitaciones vigentes: Comunicacion; Matemática; Ciencias Sociales.', { voto: 'up' }),
      intercambio(2, 'followup_attribute', MENSAJE_LARGO),
      intercambio(5, 'enrollment_request', 'Escríbenos: https://wa.me/51972021374?text=Hola%2C%20deseo%20consultar.'),
    ],
  },
  {
    clave: 2, visitante: 'B21C', probador: false, ultima_local: '25/11/2026 07:43', total: 2,
    temas: [], utiles: 0, no_utiles: 1,
    etapas: ['reclamos'], privacidad: true, contingencia: true, vista_previa: 'Ahora mismo no puedo responder esa consulta.',
    intercambios: [
      intercambio(3, null, 'Parece que incluiste un nombre o dato personal.', { privacidad: true, contingencia: true }),
      intercambio(4, null, 'Ahora mismo no puedo responder esa consulta.', { contingencia: true, voto: 'down' }),
    ],
  },
];

/** Cuántas veces ha llegado cada mensaje de prueba: «falla una vez» falla solo la primera. */
const llegadas = new Map();

const CURSO_REPORTE = { codigo: 'CUR-001', nombre: 'Curso de prueba 1' };

/** Reportes: misma forma que `ReporteController::index` (filas del lote actual + `resumen` de totales). */
function reporte(tipo) {
  const meta = { lote: 1, lotes: 1, tamano_lote: 50, filtros: {}, cursos: [{ id: 1, ...CURSO_REPORTE }] };
  if (tipo === 'cobranza') {
    return {
      tipo,
      data: [
        { id: 1, obligacion: 'obl-1', estudiante: 'Ana Quispe Rojas', dni: '70000001', curso: CURSO_REPORTE, concepto: 'Matrícula', total: '250.00', pagado: '100.00', saldo: '150.00', estado: 'parcial' },
        { id: 2, obligacion: 'obl-2', estudiante: 'Luis Torres Díaz', dni: '70000002', curso: CURSO_REPORTE, concepto: 'Matrícula', total: '250.00', pagado: '250.00', saldo: '0.00', estado: 'pagada' },
      ],
      resumen: { obligaciones: 2, total_facturado: '500.00', cobrado: '350.00', saldo_pendiente: '150.00' },
      meta: { ...meta, lotes: 2, total: 2 },
    };
  }
  if (tipo === 'asistencia') {
    return {
      tipo,
      data: [{ identificador: 'ed-1', codigo: 'ED-001', edicion: 'Edición 1', curso: CURSO_REPORTE, sesiones_realizadas: 8, matriculados: 10, promedio: 82.5, estado: 'en_curso' }],
      resumen: { ediciones: 1, sesiones_realizadas: 8, matriculados: 10, promedio_asistencia: 82.5 },
      meta: { ...meta, total: 1 },
    };
  }
  if (tipo === 'calificaciones') {
    return {
      tipo,
      data: [{ identificador: 'ed-1', codigo: 'ED-001', edicion: 'Edición 1', curso: CURSO_REPORTE, matriculados: 10, con_nota: 8, aprobados: 6, desaprobados: 2, promedio: 13.4, estado: 'en_curso' }],
      resumen: { ediciones: 1, evaluados: 8, aprobados: 6, promedio_general: 13.4 },
      meta: { ...meta, total: 1 },
    };
  }
  return {
    tipo: 'matriculas',
    data: [
      { id: 1, estudiante: 'Ana Quispe Rojas', curso: CURSO_REPORTE, fecha: '2026-11-19', estado: 'pendiente', monto_pagado: '0.00' },
      { id: 2, estudiante: 'Luis Torres Díaz', curso: CURSO_REPORTE, fecha: '2026-11-18', estado: 'pagada', monto_pagado: '250.00' },
    ],
    resumen: { estudiantes: 2, cursos: 1, matriculas: 2, ingresos: '250.00' },
    meta: { ...meta, total: 2 },
  };
}

function manejarPanel(req, res, ruta, consulta, cuerpo) {
  if (ruta === 'auth/sesion') return enviar(res, 200, { data: SESION });
  if (ruta === 'academico/resumen') return enviar(res, 200, { data: RESUMEN });

  if (ruta === 'compartido/notificaciones/recientes') {
    return enviar(res, 200, { data: [], meta: { pendientes: 3, conteos: { aprobaciones_pendientes: 2 } } });
  }
  if (ruta === 'compartido/notificaciones') {
    return enviar(res, 200, {
      data: [],
      meta: { pagina: 1, por_pagina: 20, total: 0, paginas: 1, orden: '-fecha', filtros: {}, pendientes: 0, tipos: [] },
    });
  }

  if (ruta === 'compartido/asistente' && req.method === 'GET') {
    const vista = new URLSearchParams(consulta).get('vista') ?? 'resumen';
    const base = { vista, horus_ai: { habilitado: true } };
    if (vista === 'conversaciones') {
      return enviar(res, 200, {
        data: { ...base, conversaciones: CONVERSACIONES_ASISTENTE, temas: ['followup_attribute', 'training_info'], con_votos: true, con_whatsapp: true, separa_canal: true },
        meta: { pagina: 1, por_pagina: 10, total: CONVERSACIONES_ASISTENTE.length, paginas: 1, orden: '', filtros: {} },
      });
    }
    return enviar(res, 200, { data: vista === 'resumen' ? { ...base, metricas: METRICAS_ASISTENTE } : base });
  }
  if (ruta === 'compartido/asistente/mensaje' && req.method === 'POST') {
    const mensaje = String(cuerpo?.mensaje ?? '');
    const veces = (llegadas.get(mensaje) ?? 0) + 1;
    llegadas.set(mensaje, veces);
    if (mensaje === 'falla una vez' && veces === 1) {
      return enviar(res, 503, { message: 'El asistente no está disponible.', code: 'no_disponible' });
    }
    const responder = () => enviar(res, 200, { respuesta: `Respuesta de prueba a: ${mensaje}` });
    // «lento» tarda lo bastante para ver el indicador «escribiendo».
    return mensaje === 'lento' ? setTimeout(responder, 1200) : responder();
  }

  if (ruta === 'academico/reportes' && req.method === 'GET') return enviar(res, 200, reporte(consulta.get('tipo') ?? 'matriculas'));

  if (ruta === 'academico/estudiantes' && req.method === 'GET') return enviar(res, 200, listado(ESTUDIANTES, consulta, ['dni', 'nombres', 'apellidos']));
  if (ruta === 'academico/cursos' && req.method === 'GET') return enviar(res, 200, listado(CURSOS, consulta, ['codigo', 'nombre']));

  if (ruta === 'academico/estudiantes' && req.method === 'POST') {
    const datos = cuerpo ?? {};
    // DNI 00000000: simula «ya registrado» para probar que el error de Laravel cae en su campo.
    if (datos.dni === '00000000') {
      return enviar(res, 422, { message: 'Los datos no son válidos.', code: 'validacion', errors: { dni: ['Este DNI ya está registrado.'] } });
    }
    return enviar(res, 201, { message: 'Estudiante registrado correctamente.', data: { ...ESTUDIANTES[0], id: 999, ...datos } });
  }
  if (ruta === 'academico/consultas/dni' && req.method === 'POST') {
    return enviar(res, 200, { data: { nombres: 'ANA', apellidos: 'QUISPE ROJAS', ya_registrado_como_estudiante: false } });
  }

  return enviar(res, 404, { message: `Ruta no simulada: ${req.method} ${ruta}`, code: 'no_encontrado' });
}

const servidor = http.createServer((req, res) => {
  const url = new URL(req.url ?? '/', 'http://localhost');

  if (url.pathname === '/__peticiones') {
    if (req.method === 'DELETE') {
      peticiones.length = 0;
      llegadas.clear();
    }
    return enviar(res, 200, peticiones);
  }

  const prefijo = '/api/panel/v1/';
  if (!url.pathname.startsWith(prefijo)) return enviar(res, 404, { message: 'No es la API del panel.', code: 'no_encontrado' });

  const cookies = String(req.headers.cookie ?? '');
  if (!cookies.split(';').some((par) => par.trim() === `${COOKIE_SESION}=${VALOR_SESION}`)) {
    return enviar(res, 401, { message: 'No autenticado.', code: 'no_autenticado' });
  }

  const trozos = [];
  req.on('data', (trozo) => trozos.push(trozo));
  req.on('end', () => {
    let cuerpo = null;
    try {
      cuerpo = trozos.length > 0 ? JSON.parse(Buffer.concat(trozos).toString('utf8')) : null;
    } catch {
      cuerpo = null;
    }
    const ruta = url.pathname.slice(prefijo.length);
    peticiones.push({ metodo: req.method, ruta, consulta: Object.fromEntries(url.searchParams), cuerpo });
    manejarPanel(req, res, ruta, url.searchParams, cuerpo);
  });
});

servidor.listen(PUERTO, '127.0.0.1', () => {
  console.log(`[mock-laravel] escuchando en http://127.0.0.1:${PUERTO}`);
});
