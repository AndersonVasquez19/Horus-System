# Arquitectura objetivo de los dos paneles administrativos

Estado (12/11/2026): decisiones aprobadas. **Etapas 0 y 1: ejecutadas y aceptadas con observaciones. Etapa 1.1:
ejecutada, pendiente de aceptación. Etapa 2 en adelante: sin autorización.** Este documento describe el destino y lo
distingue de lo implementado; lo que no está en código se marca como *objetivo*. La evidencia sobre el estado actual sale
de la auditoría de solo lectura del 12/11/2026 (rama `monorepo/base`, HEAD `38dc510`), de la revisión de aceptación de las
Etapas 0 y 1 y de `PROJECT_CONTEXT.md`. Las restricciones de alcance son las de [`AGENTS.md`](../AGENTS.md).

## 1. Decisiones aprobadas

1. Una sola aplicación Next.js (`apps/web`) con dos paneles independientes por rutas, layouts y módulos.
   No se crean dos aplicaciones, ni se duplica Laravel, ni se crea una segunda base de datos.
2. Despliegue bajo un mismo origen mediante proxy inverso (decide cookies, CSRF y el reparto de `/login` y `/admin`).
3. Laravel es el backend central y la autoridad de autenticación y autorización.
4. Se conservan los roles `admin`, `coordinador` y `secretaria`.
5. Se incorporan permisos independientes de entrada a cada panel (objetivo; ver §5).
6. Capacitaciones y asesorías serán módulos operativos del dominio académico (hoy solo son contenido público).
7. Las reclamaciones conservan una gestión centralizada con clasificación por dominio.

## 2. Dominios, responsabilidades y rutas

| Panel | Ruta | Identidad | Responsabilidad |
| --- | --- | --- | --- |
| Gestión académica | `/admin/academico` | Azul (`academico-*`) | Cursos, categorías, convocatorias, ediciones y horarios, cupos, estudiantes, matrículas, calificaciones, capacitaciones, asesorías, reclamaciones académicas, reportes académicos |
| Servicios tecnológicos | `/admin/servicios` | Verde (`servicios-*`) | Catálogo de servicios, clientes, prospectos, CRM y pipeline, cotizaciones, proyectos, solicitudes, soporte técnico, convenios tecnológicos, reclamaciones tecnológicas, reportes comerciales |
| Compartido | `/admin` (shell) | Neutro (`panel-*`) | Acceso, selector de panel, perfil, usuarios y roles, notificaciones, auditoría, configuración institucional, reclamaciones centrales |

Reglas de separación: el panel académico no incorpora cotizaciones, CRM, proyectos ni soporte; el panel de servicios no
incorpora matrículas ni calificaciones. Ningún controlador de un dominio lee las tablas del otro. Lo compartido pasa por
servicios comunes. Los reportes se filtran por dominio en el servidor.

### Estado de los módulos (verificado el 12/11/2026)

- **Implementados en Laravel (vistas Blade, sin API JSON de panel):** estudiantes, cursos y su publicación editorial,
  matrículas y cupos, servicios tecnológicos, cotizaciones, reclamaciones, usuarios, roles y permisos, notificaciones,
  aprobaciones y auditoría.
- **Parciales:** horarios (solo la columna de texto `cursos.horario`), categorías (columna `cursos.categoria`), convenios
  (contenido estático en `ConveniosPublicos`, sin entidad), reportes (solo académicos, con `reportes.ingresos` omitido por
  decisión del propietario).
- **No existen:** convocatorias, ediciones, calificaciones, capacitaciones y asesorías como entidad, clientes, prospectos,
  CRM, pipeline, proyectos y soporte técnico. Los datos del solicitante de una cotización están embebidos en
  `solicitudes_cotizacion`; no hay tabla `clientes`.
- **En Next.js:** solo el sitio público (16 páginas, una por cada `page.tsx`) y el catálogo de cursos desde Laravel. No hay
  autenticación ni panel.

## 3. Servicios compartidos

| Servicio | Estado actual | Objetivo |
| --- | --- | --- |
| Autenticación | Sesión + CSRF (panel Blade) y JWT HS256 (`/api/auth/*`), sin integración entre ambos | Un solo acceso; cookie de sesión HttpOnly con CSRF a través de un BFF en Next (aún no implementado) |
| Usuarios, roles, permisos | Spatie, guard `web`, tres roles, 52 permisos `modulo.accion` (recuento por expresión regular sobre el seeder), `AdminProtection` | Se conserva; se añaden permisos de entrada por panel |
| Reclamaciones | Módulo completo en Laravel (`Reclamacion*`, `GestionReclamaciones`, vencimientos), sin campo de dominio | Misma tabla con clasificación por dominio (`academico`, `tecnologico`, `sin_clasificar`) y responsable; cada panel ve su subconjunto |
| Notificaciones | `notificaciones_internas`, `notificacion_lecturas` | Se conserva |
| Auditoría | `audit_logs` y `AuditLog::record()` | Se conserva; se evaluará registrar el dominio para filtrar por panel |
| Configuración institucional | `system_settings` | Se conserva |

## 4. Comunicación Next.js ↔ Laravel

- **Hoy:** solo servidor a servidor, con la cabecera `X-Horus-Web-Token` y únicamente para el catálogo público
  (`GET /api/public/v1/{cursos,servicios,paginas/{pagina}}`, Fase 2a). No existe una API JSON del panel.
- **Objetivo:** API de panel `/api/panel/v1/{academico,servicios,compartido}/*` en Laravel y BFF en Next. Un middleware de
  grupo por panel exigirá el permiso de entrada. Los contratos se documentarán en `packages/contracts` (hoy sin
  `/api/public/v1`). Nada de esto existe todavía.
- **Proxy bajo el mismo origen:** Laravel sirve hoy el panel Blade en `/admin/*`, `/login` y `/register`. Durante la
  transición el proxy deberá enviar a Next solo `/admin/academico` y `/admin/servicios` (y lo que se migre) y el resto de
  `/admin/*` a Laravel. Las reglas concretas se definirán en la Etapa 2.

## 5. Autenticación y autorización (decisiones)

- **Laravel decide siempre.** El frontend solo oculta navegación; ninguna protección vive únicamente en Next.
- **Un usuario, uno o dos paneles.** Dos permisos de entrada independientes, de nombre aún sin confirmar (en estudio
  `panel.academico.acceder` y `panel.servicios.acceder`; **no existen** en `RolesAndPermissionsSeeder`). Tener permisos
  académicos no concede acceso a `/admin/servicios`, y a la inversa. La navegación saldrá de un endpoint de identidad que
  devuelva los permisos.
- **Roles.** Se conservan `admin`, `coordinador` y `secretaria` (`STAFF_ROLES`). Reparto verificado en el seeder:
  `admin` recibe todos; `coordinador` recibe permisos académicos (estudiantes, cursos, matrículas) más permisos
  transversales (`aprobaciones.ver|gestionar`, `reportes.ver|exportar.*`, `asistente.ver`, `respaldo.ver`); `secretaria`
  recibe un subconjunto académico y `aprobaciones.ver` y `reportes.ver`. Los permisos de servicios, cotizaciones y
  reclamaciones son solo de `admin`, y cotizaciones exige además `role:admin`
  (`docs/restriccion-acceso-comercial-admin.md`). Los permisos transversales no pertenecen a un solo panel: antes de
  repartirlos entre paneles hay que decidir su alcance por dominio (en particular `respaldo.ver`, cuyo contenido no se ha
  comprobado frente a datos comerciales). Si el panel de servicios necesita un rol propio es una decisión pendiente.
- **Permisos nuevos:** constante en `RolesAndPermissionsSeeder` **y** migración que los cree y los conceda sin
  resincronizar el resto (regla de `CLAUDE.md` §4). Ningún permiso nuevo se concede por defecto a roles de personal.
- **Sesión:** cookie HttpOnly + CSRF, mismo origen. No se traslada ningún JWT ni credencial al navegador. JWT se conserva
  para las integraciones existentes.
- **No tocar:** `AdminProtection` (último administrador y escalada de privilegios) ni sus `CRITICAL_PERMISSIONS`.

## 6. Reclamaciones centrales y Horus AI

- Laravel conserva la gestión y el plazo legal. Se añadirá clasificación por dominio y asignación a un responsable.
- Horus AI **solo sugiere** un dominio con su confianza; Laravel decide y una persona confirma. No hay autoasignación.
  Hoy Horus AI no clasifica reclamaciones ni deriva a paneles: es un asesor público (FastAPI, `/api/v1/chat`) que
  distingue "aprender" frente a "contratar un servicio" por reglas, con el filtro de privacidad bidireccional y los
  interruptores apagados por defecto. No se activa ni se cambia ninguna política en estas etapas.

## 7. Estructura de `apps/web` (implementada)

```
apps/web/src/
├── app/
│   ├── layout.tsx                       # raíz; barra, pie y chat dentro de contenedores `data-chrome-publico`
│   ├── globals.css                      # tokens academico-*, servicios-*, panel-*, estado-* + aislamiento del tema público
│   ├── (admin)/admin/
│   │   ├── layout.tsx                   # MarcaPanel `data-panel-root`, tema claro, metadatos propios, noindex
│   │   ├── academico/layout.tsx         # PanelShell, tono azul, navegación vacía
│   │   └── servicios/layout.tsx         # PanelShell, tono verde, navegación vacía
│   └── ...                              # las 16 páginas públicas, sin mover
├── components/
│   ├── shared/
│   │   ├── panel-shell.tsx              # estructura: enlace de salto, cabecera, barra lateral, contenido
│   │   ├── panel-navegacion.tsx         # cliente: grupos, estado activo, aria-current, menú móvil
│   │   ├── panel-tipos.ts               # contrato de navegación (TonoPanel, ModeloNavegacion, ...)
│   │   ├── panel-iconos.ts              # registro de iconos permitidos (claves serializables)
│   │   └── marca-panel.tsx              # cliente: marcadores `data-panel-*` solo mientras el panel está activo
│   ├── academico/   servicios/          # vacíos (.gitkeep)
└── features/{academico,servicios,reclamaciones,usuarios,notificaciones}/   # vacíos (.gitkeep)
```

- **Ninguna ruta de panel está expuesta:** hay layouts, pero ninguna `page.tsx` bajo `/admin`. El build lista 17 entradas
  (las 16 páginas públicas y `/_not-found`) y genera 19 páginas estáticas, igual que antes de las Etapas 0 y 1.
- **Nombres:** el dominio se llama `academico` y `servicios` en rutas, carpetas, tonos y tokens (`academico-*`,
  `servicios-*`). No se usan `academic`, `technology`, `acad` ni `serv`.
- **Compartir solo lo necesario:** `PanelShell` aporta estructura y la barra lateral; cada panel decide su navegación.
  No se crean primitivas de UI (botones, tablas, modales) hasta que un módulo las pida.

### Aislamiento del tema público (Etapa 1.1)

El layout raíz aplica al `<body>` el tema público y las páginas públicas no se pueden trasladar todavía a un grupo
`(public)`. Los paneles se aíslan con CSS, sin lógica de ruta en el cliente:

- `MarcaPanel` pone `data-panel-root` (layout de `/admin`) y `data-panel-tono` (`PanelShell`) en el HTML del servidor, para
  que el primer pintado ya esté aislado.
- Reglas sin capa de Tailwind en `globals.css` (`:root:has([data-panel-root])`) sustituyen fondo, texto, `color-scheme`,
  `scroll-behavior`, selección y barra de desplazamiento, y ocultan `[data-chrome-publico]` (barra, pie y chat). Como
  actúan sobre `<html>`/`<body>`, los portales de React, menús y diálogos que se pintan en `document.body` heredan el tema
  del panel. Sin marcador (sitio público y 404) no se aplica nada.
- **Con `cacheComponents`, Next conserva el panel oculto en el DOM al navegar fuera de él** (`display: none` en el
  contenedor). Un atributo fijo seguiría casando con `:has()` y el tema del panel se filtraría a las páginas públicas
  (comprobado en el navegador). Por eso `MarcaPanel` retira el atributo al ocultarse el subárbol y lo repone antes de
  pintar (`useLayoutEffect`).
- **Descartado:** ocultar el chrome con un componente de cliente que lea `usePathname`. El 404 se prerenderiza una vez
  con la barra y el pie, y en el navegador `usePathname` sale de `location.href` (código de `app-router.js`), así que
  `/admin/<ruta inexistente>` hidrataría un árbol distinto. Esa conclusión es por el HTML de producción y el código fuente
  de Next: no se ejecutó `next start`.
- Requisito: `:has()` (Chrome 105, Safari 15.4, Firefox 121). El chrome oculto sigue montado en el cliente (sin efectos
  visibles ni interacción: la barra y el chat quedan en `display: none`).

### Navegación y permisos (contrato, Etapa 1.1)

`panel-tipos.ts` define el contrato: `ModeloNavegacion` = grupos (`id`, `etiqueta?`) de entradas con `id` estable,
`etiqueta`, `href`, `icono?` (clave de `panel-iconos.ts`), `permisosRequeridos?` (cadenas opacas, todas exigidas) y
`coincidencia?` (`'exacta'` | `'prefijo'`, por defecto prefijo; usar `'exacta'` en el inicio de cada panel). El estado
activo se calcula en cliente (`usePathname`) y se anuncia con `aria-current` (`page` si es la página, `true` si es un
ancestro). **No hay filtrado por permisos, lectura de sesión ni autenticación**, y no se definió ningún permiso: los
arreglos `NAVEGACION` de los layouts están vacíos.

Impacto de `cacheComponents` y `Suspense` en el filtrado futuro (según la guía de Next 16 incluida en el paquete):

- Leer `cookies()` o `headers()` en un layout fuera de `<Suspense>` se señala como *blocking-prerender-runtime*. El acceso
  debe vivir en un componente de servidor envuelto en `<Suspense>` para que el resto del shell siga prerenderizado.
- Recomendación (a validar en la Etapa 2): un componente asíncrono de servidor lee la sesión, pide la identidad a Laravel,
  filtra el `ModeloNavegacion` y entrega a `PanelNavegacion` solo lo permitido, dentro de un `<Suspense>` con esqueleto.
  `PanelShell` ya envuelve la barra lateral en `<Suspense>`, que además cubre el caso en que `usePathname` suspenda bajo
  rutas con parámetros dinámicos.
- El filtro de la interfaz es solo comodidad: Laravel debe volver a comprobar cada permiso en cada petición.
- Alternativa descartada por ahora: pedir la identidad desde el cliente tras cargar (parpadeo del menú y petición
  adicional).

### Accesibilidad (Etapa 1.1)

El layout raíz aporta el único `<main>`; el shell no declara otro `<main>`, ni `<header>` ni `<aside>` (no serían
landmarks útiles y la navegación no es contenido complementario). La barra lateral es un `<nav>` con nombre, con menú
plegable en móvil (`aria-expanded`, `aria-controls`, Escape y retorno del foco) y estado activo con `aria-current`
(además del color, negrita y barra lateral). Hay enlace para saltar al contenido (visible al enfocarlo) cuyo destino
muestra indicador de foco. Foco con tonos 700. Contrastes calculados (WCAG 2.1): texto blanco sobre `academico-800`
8,7:1 y sobre `servicios-800` 7,7:1; `panel-muted` sobre blanco 7,6:1; contornos de foco `academico-700` 6,7:1 y
`servicios-700` 5,5:1; `servicios-600` (3,8:1) solo para elementos grandes o decorativos. Un `banner` y un `main` propios
llegarán cuando cada grupo de rutas tenga su propio layout raíz.

## 8. Plan por etapas

Desde el 12/11/2026 rige el prompt maestro de ejecución continua (fases 1–8, ver `AGENTS.md`).

| Etapa | Contenido | Estado |
| --- | --- | --- |
| 0 | Documentación | Ejecutada; aceptada con observaciones (12/11/2026) |
| 1 | Base de Next.js: carpetas, layouts, tokens | Ejecutada; aceptada con observaciones (12/11/2026) |
| 1.1 | Cierre de observaciones: hidratación, documentación, `AGENTS.md`, contrato de navegación, aislamiento visual, accesibilidad | Ejecutada |
| 2 | Autenticación (cookie HttpOnly + CSRF) y API base del panel en Laravel; permisos de entrada | **Ejecutada** (sección 10) |
| 3 | Shell `/admin`: acceso, selector de panel, protección de rutas | **Ejecutada** |
| 4 | Panel académico con los módulos ya implementados | En curso: inicio y cursos (lectura) |
| 5 | Panel de servicios con los módulos ya implementados (servicios, cotizaciones) | En curso: inicio, servicios y cotizaciones (lectura) |
| 6 | Reclamaciones centrales: dominio, vistas por panel, asignación manual | — |
| 7 | Módulos académicos nuevos: convocatorias, ediciones, calificaciones, capacitaciones, asesorías | — |
| 8 | CRM: clientes, prospectos, pipeline, proyectos, soporte | — |
| 9 | Horus AI: sugerencia de dominio para reclamaciones | — |
| 10 | Retiro de Blade y Vite del panel, con paridad módulo a módulo | — |

## 9. Riesgos y pendientes

| # | Riesgo o pendiente | Gravedad |
| --- | --- | --- |
| 1 | No existe API de panel; construirla es el mayor trabajo | Alta |
| 2 | Sesión por cookie con CORS inexistente y sin Sanctum; requiere diseño y pruebas de CSRF | Alta |
| 3 | Un permiso nuevo concedido a un rol de personal abre el panel de servicios | Alta |
| 4 | Sin Policies: la autorización vive en `HasMiddleware` por controlador; una ruta nueva sin ese middleware queda abierta al personal | Alta |
| 5 | Reparto de `/login` y `/admin` entre Next y Laravel bajo un mismo origen (reglas del proxy; Laravel sirve hoy `/admin/*`) | Alta |
| 6 | Mover las 16 páginas públicas a `(public)` puede romperlas; hacerlo en una fase aislada | Media |
| 7 | Permisos transversales (`reportes`, `respaldo`, `asistente`, `aprobaciones`) sin dominio; `respaldo.ver` sin comprobar frente a datos comerciales | Media |
| 8 | CHECK, índice único parcial y FK RESTRICT solo existen en PostgreSQL; la suite SQLite no los verifica | Media |
| 9 | `cursos.cupos_disponibles` es un contador sin CHECK visible | Media |
| 10 | `audit_logs` sin protección de inmutabilidad; cobertura de `SensitiveData` sin verificar | Media |
| 11 | Migraciones suspendidas con código y permisos activos (`solicitudes-matricula`) | Media |
| 12 | CI suspendido (solo `workflow_dispatch`): nada valida los commits | Media |
| 13 | `apps/web` sin pruebas automáticas; `test:routes` es un humo manual solo para Windows | Media |
| 14 | El formulario de reclamaciones de Next parece simulado (sin envío al backend); no verificado en detalle | Media |
| 15 | `apps/api/database/database.sqlite.backup` existe, está ignorado por Git (`*.sqlite*`) y no se ha comprobado su contenido | Media |
| 16 | El aislamiento visual depende de `:has()` y de `MarcaPanel`; sustituirlo por un atributo fijo reintroduciría la fuga del tema entre panel y sitio público | Media |
| 17 | Tras editar `globals.css`, `next dev` puede seguir sirviendo el CSS anterior (caché de Turbopack en `.next/dev`); detener el servidor propio y vaciarla | Baja |
| 18 | Chrome público oculto pero montado bajo `/admin` (cargas del bundle y efectos de la barra y el chat); desaparece con `(public)` | Baja |
| 19 | `cacheComponents` exige `Suspense` en todo componente que lea cookies, cabeceras o parámetros de ruta no conocidos | Baja |
| 20 | OpenAPI sin `/api/public/v1/*`; `CLAUDE.md` y `README.md` sin referencia a este documento ni a los paneles | Baja |
| 21 | ~~Nombres de los permisos de entrada y su reparto~~ Resuelto: `panel.academico.acceder` (admin, coordinador, secretaria) y `panel.servicios.acceder` (admin), migración 000042 | — |
| 22 | La `Navbar` pública (layout raíz, protegido) usa `usePathname` fuera de `<Suspense>`: las rutas dinámicas del panel necesitan `export const instant = false` hasta que la web pública pase a `(public)` | Baja |
| 23 | La sesión desliza en Laravel, pero la cookie del navegador solo se renueva por el BFF (`RenovadorSesion` tras actividad); una pestaña inactiva caduca a los `SESSION_LIFETIME` minutos | Baja |
| 24 | El enlace de `/admin/servicios/cotizaciones` se muestra a quien tenga `cotizaciones.ver` aunque no sea admin; Laravel responde 403 y la página lo explica | Baja |

## 10. Implementación del prompt maestro (12/11/2026)

### Autenticación (Fase 1)

Sesión web de Laravel reenviada por un BFF de Next (alternativa A1 de la Etapa 2A). El navegador solo guarda la cookie
de sesión de Laravel (HttpOnly); nunca ve el token de servicio ni llama a Laravel.

```
Navegador ──JSON, mismo origen──> Next /api/bff/auth/*  ──X-Horus-Web-Token, IP real, Cookie, X-CSRF-TOKEN──> Laravel /api/panel/v1/auth/*
```

| Pieza | Ubicación | Qué hace |
| --- | --- | --- |
| Puerta del BFF | `AutenticarBffPanel` | 404 sin token configurado; 401 con token incorrecto; fuerza JSON; acepta `X-Horus-Client-Ip` solo tras autenticar al BFF (los limitadores cuentan por visitante) |
| Rutas | `routes/panel.php`, montado en `bootstrap/app.php` con el grupo `web` (sesión + `ValidateCsrfToken`) | `auth/csrf`, `auth/login`, `auth/sesion`, `auth/logout`, `auth/contrasena/{solicitar,restablecer}` |
| Login | `Api\Panel\AutenticacionController` | `CredentialTiming`, `attemptWhen` (correo verificado si aplica **y** algún permiso de entrada), `regenerate()` + `regenerateToken()`, auditoría `login`/`login_failed` con canal `panel`; misma respuesta 422 para credenciales malas y cuentas sin acceso |
| Vida absoluta | `SesionPanelVigente` + `config/panel.php` (`PANEL_SESION_ABSOLUTA_MINUTOS`, 720 por defecto) | Además de la inactividad (`SESSION_LIFETIME`); al expirar, cierra la sesión y responde 401 `sesion_expirada` |
| Recuperación | `Support\RestablecimientoContrasena` (compartido con Blade) + `RestablecerContrasenaWebNotification` | Enlace a `HORUS_WEB_URL/restablecer-contrasena#token=…&email=…` (fragmento: no viaja a servidores ni a registros); el restablecimiento revoca JWT y sesiones |
| Errores | `Support\ErroresApiPanel` | `{message, code, errors?}` estable: `no_autenticado`, `sesion_expirada`, `prohibido`, `no_encontrado`, `csrf`, `validacion`, `demasiadas_solicitudes`, `error_interno` |
| BFF | `apps/web/src/app/api/bff/auth/*`, `src/lib/panel/{laravel,bff,operaciones-auth}.ts` | Handlers por operación (sin proxy genérico); verifican origen (`Origin` o `Sec-Fetch-Site`) y JSON; relevan solo la cookie de sesión con HttpOnly, SameSite=Lax, Path=/, sin Domain y Secure en producción |
| Protección de rutas | `src/proxy.ts` (optimista: sin cookie → `/login?next=`) y `GuardiaPanel` en `PanelShell` | Los hijos del panel solo se renderizan tras confirmar Laravel la sesión y el permiso de entrada |
| Páginas | `src/app/(acceso)/{login,recuperar-contrasena,restablecer-contrasena}`, `src/app/(admin)/admin/page.tsx` | El selector `/admin` redirige al único panel permitido o deja elegir |

CSRF en dos capas: el BFF exige mismo origen y JSON (un formulario de otro sitio no puede enviarlo sin preflight) y Laravel
valida el token CSRF de la sesión en cada mutación. Redirecciones con lista blanca (`destinoSeguro`).

### API del panel (Fase 2)

- Listados (`Support\Panel\Listado`): `page`, `per_page` (1..100, 20), `sort=campo|-campo` con lista blanca (un valor
  desconocido usa el orden por defecto), filtros con nombre propio y valores en lista blanca. Respuesta
  `{data, meta: {pagina, por_pagina, total, paginas, orden, filtros}}`; el orden termina en `id`.
- Cada ruta exige `auth` + `SesionPanelVigente` + permiso de entrada del panel + permiso de módulo (en el controlador).
  `tests/Feature/Panel/InventarioRutasPanelTest.php` falla si una ruta nueva no lo cumple. Los dashboards solo exigen
  la entrada porque filtran cada bloque por permiso.

### Módulos conectados

| Panel | Pantalla | Endpoint | Permiso |
| --- | --- | --- | --- |
| Académico | Inicio (KPIs, matrículas por mes, últimas matrículas) | `GET academico/resumen` | entrada; bloques por `estudiantes.ver`, `cursos.ver`, `matriculas.ver`; ingresos solo rol `admin` |
| Académico | Cursos (filtros, orden, paginación, eliminados) | `GET academico/cursos` | `cursos.ver` |
| Académico | Detalle de curso (estudiantes minimizados) | `GET academico/cursos/{id}` | `cursos.ver` |
| Servicios | Inicio (catálogo, fichas, cotizaciones, seguimientos) | `GET servicios/resumen` | entrada; cotizaciones solo rol `admin` + `cotizaciones.ver` |
| Servicios | Servicios (catálogo interno y ficha pública) | `GET servicios/servicios` | `servicios.ver` |
| Servicios | Cotizaciones (bandeja sin datos de contacto) | `GET servicios/cotizaciones` | rol `admin` + `cotizaciones.ver` |

### Variables de despliegue (solo nombres)

`apps/web`: `HORUS_API_URL`, `HORUS_WEB_SERVICE_TOKEN`, `HORUS_SESSION_COOKIE` (igual a `SESSION_COOKIE` de Laravel;
por defecto `laravel-session`), `HORUS_WEB_ORIGIN` (orígenes propios, recomendado en producción).
`apps/api`: `HORUS_WEB_SERVICE_TOKEN`, `HORUS_WEB_URL`, `PANEL_SESION_ABSOLUTA_MINUTOS`, `SESSION_SECURE_COOKIE=true`
en producción. El proxy de borde debe sobrescribir `X-Forwarded-For`/`X-Real-IP` y no exponer `/api/panel/v1/*`.

## 11. Qué no se ha hecho

No se movió ni retiró ninguna página pública, vista Blade, ruta Blade ni Vite. No se instaló ninguna dependencia. No se
ejecutó ninguna migración sobre bases reales: la 000042 se probó en SQLite en memoria y en un SQLite desechable fuera
del repositorio. Las escrituras de los módulos (alta, edición, anulación…) siguen solo en Blade.
