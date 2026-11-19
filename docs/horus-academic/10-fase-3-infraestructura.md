> **RETIRADO el 19/11/2026.** Los módulos Docentes e Infraestructura se eliminaron del panel y de la base de datos (migraciones `000084` y `000085`). Este documento queda como historial; ver [`../retiro-docentes-infraestructura.md`](../retiro-docentes-infraestructura.md).

# 10 · Fase 3 — Infraestructura y consulta de DNI

> 16/11/2026. SQLite en memoria; **ninguna migración ejecutada** contra base real ni PostgreSQL aislado. Sin commits.

## Implementado

- **Sedes, aulas y plataformas virtuales** (CRUD sin eliminación: se desactivan) y **asignación a ediciones**
  (`PUT /academico/convocatorias/{id}/infraestructura`, con revisión `If-Match`). Rutas bajo `/api/panel/v1/academico/`.
- **Reglas:** presencial ⇒ sede + aula (del mismo local, activos, capacidad ≥ cupos) y sin plataforma; virtual ⇒ plataforma
  activa y sin sede/aula; las modalidades históricas (`semipresencial`, `hibrido`) no admiten asignación ni se pueden crear
  ediciones nuevas con ellas; no se desactiva ni se reduce lo que usan ediciones vigentes; una edición **nueva** no se abre sin
  su infraestructura; las ediciones existentes se comportan exactamente igual que antes.
- **Consulta de DNI** (`POST /academico/consultas/dni`, permiso `estudiantes.crear`, 10/min por usuario): servicio único
  `JsonPeService` con el contrato de JSON.pe entregado por el propietario; el token sale de `config('services.jsonpe.token')`
  (`JSONPE_TOKEN`), nunca se devuelve ni se registra; errores controlados (`dni_no_encontrado`, `dni_servicio_no_configurado`,
  `dni_servicio_no_autorizado`, `dni_limite_proveedor`, `dni_servicio_no_disponible`, `dni_respuesta_incompleta`); no reenvía
  mensajes del proveedor; caché de 10 min con clave hasheada; solo consulta, no registra a nadie. Pruebas con `Http::fake`
  (nunca se llamó al proveedor real).
- Permisos `infraestructura.ver|gestionar` (seeder + migración `000064`): admin y coordinador ambos; secretaria solo `ver`.
- OpenAPI sincronizado (17 operaciones nuevas `vigente`) y la prueba de paridad lo exige.

## Decisión de diseño que cambia lo documentado

El `CHECK … NOT VALID` de modalidad propuesto en la Fase 1 se **sustituyó** por un indicador `exige_infraestructura`: en PostgreSQL
un `CHECK` se evalúa en cada `UPDATE` de la fila aunque sea `NOT VALID`, y las matrículas actualizan `cupos_disponibles` de
ediciones heredadas sin infraestructura (fallarían). Detalle en [02](02-diccionario-de-datos.md) y en la migración `000063`.

## Migraciones (creadas, **sin ejecutar**)

`000062` tablas `sedes`, `aulas`, `plataformas_virtuales` · `000063` columnas en `convocatorias` + `CHECK` y FK compuesta (PostgreSQL) ·
`000064` permisos. Reversibles; en SQLite `down()` es parcial (limitación conocida, solo entorno de pruebas).

## Límites de la evidencia

SQLite no prueba: los tres `CHECK`, la FK compuesta (sede, aula) ni las carreras entre dos asignaciones simultáneas (el servicio
bloquea filas con `lockForUpdate()`, que SQLite ignora). Eso requiere el clúster aislado `55433` con autorización.

## Bloqueos y pendientes

- **Ninguno bloqueante.** Docentes y la unicidad de DNI entre estudiantes y docentes se implementaron en la Fase 4 (ver abajo).
- La integración real con JSON.pe la prueba el propietario (todo lo demás usa respuestas simuladas).
- D19 (Configuración) resuelto: ver abajo.

## Fase 4 (parte 1): docentes, y retiro de Configuración

- **Docentes:** identificados solo por DNI (8 dígitos, texto, único, inmutable, columna `dni`); `docentes` con soft delete y
  `convocatoria_docentes` (rol principal/apoyo, a lo sumo un principal por edición, índice parcial). CRUD con listado minimizado,
  asignación a ediciones con revisión `If-Match`, `docentes.*` (admin todos; coordinador ver/crear/editar; secretaria ver).
  No se elimina ni inactiva a un docente en ediciones vigentes. La auditoría guarda ids y nombres de campo, nunca datos personales.
- **DNI único entre estudiantes y docentes** (`App\Support\DniUnico`): se comprueba al crear/editar estudiantes y al crear docentes,
  contando también a los eliminados lógicamente; en PostgreSQL con bloqueo consultivo por DNI. **Interpretación literal de la regla
  del propietario:** una misma persona no puede estar en ambas tablas; si un docente debiera poder matricularse como alumno, la regla
  habría que cambiarla (decisión a confirmar).
- **Configuración retirada:** rutas `/admin/configuracion`, enlaces del panel Blade, `UpdateConfiguracionRequest` y los permisos
  `configuracion.ver|editar` (migración `000065`, reversible). Se conserva `ConfiguracionSistema` (usada por los PDF). Quedan
  fuera del alcance de esta limpieza el smoke de navegador `tests/browser/formularios-modal-smoke.mjs` (apunta a una pantalla que
  ya no existe) y los tests de Blade no relacionados.
- Migraciones nuevas, **sin ejecutar**: `000065` retiro de permisos de Configuración, `000066` docentes y asignaciones, `000067` permisos.
- SQLite no prueba: el `CHECK` del DNI, el bloqueo consultivo ni las altas simultáneas.

## S0 + S1 (frontend): kit de formularios y pantallas de infraestructura y docentes

- **Kit (`apps/web/src/components/shared/use-formulario-panel.ts`):** React Hook Form + Zod + errores de Laravel por campo; Zod solo ayuda,
  Laravel valida. Se usó en todos los formularios nuevos; los formularios anteriores no se tocaron.
- **Pantallas nuevas (`/admin/academico/…`):** `infraestructura/{sedes,aulas,plataformas}` (alta, edición, activar/desactivar),
  `docentes` (listado, ficha, alta con **consulta de DNI** y confirmación; alta manual solo si el servicio externo falla; el DNI ya
  registrado como docente o estudiante bloquea el alta) y, en la ficha de la edición, los bloques **Infraestructura** y **Docentes**.
  Menú: «Ediciones» (antes «Convocatorias»), «Docentes», «Infraestructura».
- **BFF (`lib/panel/operaciones-panel.ts`):** 19 operaciones nuevas en la lista cerrada, más un saneador de `docentes` (hasta 10 pares
  docente/rol). Los datos para los selectores se piden en el servidor; no hay GET nuevos desde el navegador.
- **No se añadió ninguna dependencia** (React Hook Form, Zod y `@hookform/resolvers` ya estaban). shadcn/ui y TanStack Query siguen
  sin instalar: no hicieron falta; se incorporarán cuando una pantalla lo requiera (p. ej. calendario de sesiones).
- **Verificación:** `tsc`, ESLint sobre los archivos nuevos y `next build` limpios, y un recorrido completo de 24 comprobaciones
  (BFF → Laravel → SQLite **desechable** fuera del repositorio, ya eliminado, sin servicios externos) incluyendo las páginas
  renderizadas con datos reales. **No probado:** la interacción en un navegador real (diálogos, validación del lado del cliente).

## Certificados: pantallas y verificación pública (N2, 17/11/2026)

- **Panel (`/admin/academico`):** bloque «Certificado» en la ficha de cada matrícula con edición (emitir; si existe, datos, código de
  verificación en formato `ABCD-EFGH-JKLM`, «Copiar enlace de verificación» y «Anular certificado» con motivo, solo con
  `certificados.anular`) y listado `certificados` con búsqueda y filtro por estado. El listado NO trae el código de verificación.
  Laravel decide si se puede emitir (acta cerrada, aprobada, asistencia mínima) y sus mensajes se muestran tal cual.
- **Público (sin sesión):** `/verificar` (formulario; normaliza mayúsculas, espacios y guiones y reenvía al resultado) y
  `/verificar/{codigo}` (la dirección a la que apuntará el QR del certificado). Muestra solo estado, número, nombre, curso, horas y
  fecha de emisión; nunca DNI, nota, asistencia ni el motivo de anulación. No se indexa (`noindex`). Un código mal formado se explica
  sin consultar a Laravel; uno desconocido responde igual que cualquier otro inexistente.
- **Límite de consultas por visitante:** Laravel aplica `certificados-bff` (20 por minuto y visitante; el visitante lo identifica Next
  con un HMAC de su IP, como en el chat). Antes el límite era por IP del servidor de Next y lo habría agotado un solo visitante.
- **Verificación:** `tsc`, ESLint y `next build` limpios y un recorrido real BFF → Laravel → SQLite desechable (ya eliminado) con
  14 comprobaciones: emitir (la desaprobada se rechaza con motivo), ficha, listado sin código, verificación pública, formulario,
  códigos inválidos y desconocidos, anulación y verificación posterior. **No probado:** interacción en un navegador real (diálogos,
  copiar al portapapeles). El reenvío del formulario usa `meta refresh` de Next (la respuesta es 200, no 307) porque la página ya
  empezó a transmitir.

## Tablero y búsqueda global (N4, 17/11/2026)

- **Ocho cifras** (`GET /academico/resumen`, ampliado sin romper las claves anteriores): **acciones** (pagos por confirmar y matrículas con
  saldo pendiente, con `pagos.ver`; ediciones por iniciar o por cerrar el acta, con `convocatorias.ver`), **negocio** (cobrado del mes y saldo
  por cobrar, con `pagos.ver`; matrículas del mes frente al mes anterior, con `matriculas.ver`) y **calidad académica** (cupos por edición,
  asistencia promedio de las ediciones en curso con alerta bajo el mínimo, y certificados del mes y aprobados sin certificado).
- **El dinero sale del libro de pagos** (confirmado neto de reversos; lo pendiente no cuenta como cobrado), no de `matriculas.monto_pagado`.
- **Visibilidad por permiso en el servidor:** un bloque sin permiso no se envía (llega vacío). Solo con `pagos.ver` viaja dinero, y hoy ese
  permiso es solo de `admin`; el coordinador y la secretaria reciben el resto. La secretaria, además, ve ediciones y certificados porque su
  rol tiene esos permisos de lectura.
- **Búsqueda global** (`GET /academico/busqueda?q=`, 2 a 100 caracteres, hasta 5 por grupo, límite `admin-opciones`): estudiantes, docentes,
  matrículas, ediciones y certificados, cada grupo solo con el permiso de su módulo. Solo devuelve lo mínimo para identificar y enlazar; nunca
  montos, notas ni códigos de verificación. En el panel: página `/admin/academico/buscar` y formulario en el inicio (funciona sin JavaScript).
- **Menú:** nuevo grupo «Operación diaria» (Matrículas, Pagos, Certificados) y entrada «Buscar».
- **Verificación:** recorrido real BFF → Laravel → SQLite desechable (ya eliminado) con 15 comprobaciones, incluido que el HTML del
  coordinador no contiene ninguna cifra de dinero. **No probado:** interacción en un navegador real.

## Retiros del 18/11/2026

- **Certificados (N2, N3 y S6 retirados por decisión del propietario).** Se eliminaron del panel (menú, listado, bloque en la matrícula, tarjetas del tablero, grupo del buscador), del sitio público (`/verificar`) y del backend (rutas, controladores, `GestionCertificados`, modelo, permisos `certificados.*`, limitador `certificados-bff`, OpenAPI y pruebas). Las migraciones `000076` y `000077` se borraron; **nunca corrieron en la base real**. Respaldo del código retirado: `%LOCALAPPDATA%\HorusDemo\archivo-certificados-*` (fuera del repositorio). Las secciones anteriores sobre certificados son históricas.
- **Panel de servicios tecnológicos (parte comercial).** Retiradas las pantallas `/admin/servicios/*` y, en la API, resumen, cotizaciones, clientes, prospectos, oportunidades, proyectos, soporte y reportes comerciales (controladores, servicios, modelos CRM, requests, pruebas y contrato). **Se conservan:** tablas y migraciones, catálogo público de servicios, formulario público de cotización (las solicitudes se siguen guardando, sin bandeja), Horus AI, el permiso de entrada `panel.servicios.acceder` (inerte) y la API de edición de fichas `servicios/servicios/*` (sin pantalla: es la única vía de autoría del catálogo público y la usan las pruebas del catálogo). Quitarla exige reescribir esas pruebas.

## Retiro de Blade y Vite de Laravel (18/11/2026)

Laravel dejó de servir interfaz: Next.js es el único frontend. Se retiraron las páginas públicas de Blade, el panel `/admin`, la autenticación
web y Vite (y con ellos `tests/browser` y `tests/frontend`) en cinco fases autorizadas una a una, cada una con la suite PHP completa en verde.
Para este módulo: el respaldo («Exportar datos») y el asistente virtual viven ahora en el panel de Next (`compartido/respaldo` y
`compartido/asistente`), el tablero de Next trae el bloque «Seguimiento» (aprobaciones y notificaciones pendientes, actividad reciente) y
`/admin/{matriculas,estudiantes,cursos,reportes,buscar,respaldo,asistente}` redirigen a `/admin/academico/…`. `HORUS_WEB_URL` es obligatoria.
Detalle completo y cobertura dada de baja: [`docs/ola4-fase1-retiro-blade-panel.md`](../ola4-fase1-retiro-blade-panel.md). El smoke
`tests/browser/formularios-modal-smoke.mjs` que se mencionaba arriba ya no existe.
