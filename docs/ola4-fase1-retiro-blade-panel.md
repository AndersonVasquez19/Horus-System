# Ola 4, Fase 1: retiro del Blade del panel

15/11/2026. Mismo procedimiento que las olas anteriores: la regla ya vive en un servicio de Laravel, el panel de Next la usa por la API de
`/api/panel/v1`, la ruta Blade pasa a responder 302 hacia Next conservando su nombre (barra lateral, panel y enlaces guardados en las
notificaciones) y se borran la vista, el controlador Blade y sus pruebas, tras portar cada regla de negocio a una prueba del panel. Sin push.
Orden: aprobaciones, configuración, respaldo, asistente, búsqueda y dashboard, campana.

## 1. Aprobaciones internas

| Blade (retirado) | API del panel | Página Next |
| --- | --- | --- |
| `GET /admin/aprobaciones` | `GET /compartido/aprobaciones` (`estado`, `tipo`, `busqueda`, `page`, `per_page`; pendientes primero) | `/admin/{panel}/aprobaciones` |
| `POST /admin/aprobaciones` (`aprobaciones.gestionar`) | `POST /compartido/aprobaciones` (201) | «Nueva solicitud» (diálogo) |
| `PUT /admin/aprobaciones/{id}` (`aprobaciones.ver`) | `PUT /compartido/aprobaciones/{id}` | sin pantalla (el Blade tampoco la tenía) |
| `DELETE /admin/aprobaciones/{id}` | `DELETE /compartido/aprobaciones/{id}` | «Eliminar» (confirmación) |
| `POST …/aprobar`, `…/rechazar`, `…/reabrir` (`aprobaciones.gestionar`) | igual, bajo `/compartido/aprobaciones/{id}/…` | Aprobar, Rechazar (motivo), Reabrir (motivo) |

- **Ninguna regla se reescribe:** la API llama a `GestionAprobaciones` (transacción, bloqueo de fila, estado releído bajo bloqueo, autoridad por tipo,
  sin autoaprobación, auditoría como última escritura y notificaciones por los eventos del modelo). El controlador solo valida la entrada y da forma
  a la respuesta. Los permisos por acción son los del Blade; la secretaría puede editar y eliminar **lo propio** (`aprobaciones.ver`) pero no registrar.
- **Acciones por solicitud:** la respuesta trae `puede_resolver`, `puede_reabrir`, `puede_eliminar` y `es_propia`, calculadas con las mismas reglas
  que el servidor aplica al ejecutar; la interfaz solo ofrece esas. Un 409 (ya resuelta por otra persona) se muestra y relee la lista.
- **Historial de decisiones** en cada solicitud (acción, persona, estados, motivo o comentario) a partir de la auditoría redactada.
- **Cambio de contrato:** un estado o tipo desconocido en el filtro **se ignora** (convención de `Listado`); el Blade filtraba por ese valor y
  devolvía una lista vacía. El tamaño de página pasa de 12 a 20 (`per_page` hasta 100).
- **Respuestas** con `Cache-Control: no-store, private`: incluyen comentarios y motivos internos.
- **BFF:** 4 operaciones cerradas (`POST` de alta, `aprobar`, `rechazar|reabrir` y `DELETE`); la edición no se expone porque la pantalla no la usa.
- **Menú:** «Aprobaciones» en «Sistema» de los dos paneles (`aprobaciones.ver`); `/admin/aprobaciones` entra por el despachador de módulos
  compartidos, así que los enlaces de las notificaciones guardadas siguen funcionando.

### Pruebas

`Panel\AprobacionesPanelTest` (11) sustituye a las 29 de `AprobacionOperationsTest`, organizadas por regla: acceso, autoridad por tipo (Gerencia
compras, Coordinación operativas, tipo antiguo sin autoridad, sin autoaprobación), doble decisión (409), motivo obligatorio, reapertura con
historial, auditoría y avisos, atomicidad si falla la auditoría, alta con solicitante y estado fijados, edición sin cambiar estado, tipo ni
solicitante, propietario o Gerencia para editar y eliminar (y 409 si ya está resuelta) y listado con acciones por usuario, filtros, mayúsculas y `%`
como texto. Se portaron también: permisos por migración, atomicidad con notificación fallida, campos de usuario como arreglo (`OldInput`) y la
carrera en PostgreSQL (`AprobacionesConcurrenciaPostgresTest`, **sin ejecutar**: la autorización de PostgreSQL fue solo para el bloque académico).
Se retiraron las comprobaciones de HTML de la página Blade (un `h1`, el diálogo de confirmación, que se prueban en otra página que sigue en Blade) y
la de «404 de modelo sin nombres internos», que ya no tiene ninguna ruta Blade con modelo enlazado.

## Cierre: retiro completo de Blade y Vite (18/11/2026)

La Ola 4 terminó con el retiro de **todo** Blade y Vite de `apps/api`, en cinco fases, cada una autorizada de forma expresa por el
propietario y cerrada con la suite PHP completa en verde (SQLite en memoria). Las copias de lo borrado quedaron fuera del repositorio, en
`%LOCALAPPDATA%\HorusDemo\archivo-blade-fase1` a `fase4`. Sin commits ni push.

| Fase | Se retiró de Laravel | Qué lo sustituye |
| --- | --- | --- |
| 0 (preparación, sin borrar) | — | Bloque «Seguimiento» en el tablero de Next (`ResumenAcademico::seguimiento`), `redirects()` en `next.config.ts`, rutas literales en `PaginasServicios` |
| 1 | `/tecnologias/*`, `/educacion/cursos`, `/libro-reclamaciones`, `/solicitar-cotizacion`, `POST /solicitudes-cotizacion`, `POST /chat/mensaje`; sus controladores, vistas y componentes | Páginas de Next y `/api/public/v1/{paginas,cursos,servicios,reclamaciones,cotizaciones,chat}` |
| 2 | Panel `/admin` (tablero, búsqueda, respaldo, asistente, notificaciones), `RedirigirAWebController`, `NoStoreAdminResponse`, alias `role`, vistas `admin/*` | Panel de Next sobre `/api/panel/v1`; el PDF de reportes pasó a `pdf/reporte-matriculas` |
| 3 | Login, registro y recuperación de contraseña web; `Admin/AuthController`, `Auth/*`, `ResetPasswordNotification` | `/api/panel/v1/auth/*`; `redirectGuestsTo` → `HORUS_WEB_URL/login`; recuperación con `RestablecerContrasenaWebNotification` |
| 4 | `vite.config.js`, Tailwind, PostCSS, `package.json` de la API, `resources/js|css`, `public/assets/{css,js}`, `tests/frontend`, `tests/browser`, routers de navegador; Vite y `unsafe-eval` del CSP; paso de Node del CI | Nada: la API ya no tiene interfaz. Páginas de error con botón a `HORUS_WEB_URL` |
| 5 | — | Documentación (`CLAUDE.md`, `AGENTS.md`, `PROJECT_CONTEXT.md`, `README.md`, `guia-despliegue.md`, esta nota) |

**Se conservó a propósito:** JWT (`/api/auth/*`), el grupo `web` (sesión y CSRF) del panel, `config/horus-web.php`, `ConfiguracionSistema`, las
vistas de PDF, correos y errores, `public/assets/images` y `/storage`, y toda la API pública y del panel.

**Decisiones del propietario en esta ola:** certificados retirados (N3 descartada), panel comercial de servicios retirado (catálogo público y
su API se conservan), respaldo y asistente movidos al panel de Next, registro público en Next, y retirada de `MatriculaNotificationBacklogTest`.

**Cobertura que se dio de baja con Blade:** pruebas de HTML de las páginas retiradas, de la campana Blade, de `old()` en formularios, del
enlace canónico Blade de recuperación y 41 pruebas de login, registro y recuperación web (cubiertas por `AutenticacionPanelTest` y
`RegistroPublicoPanelTest`). Las reglas de negocio se portaron a pruebas de la API (`ApiPublicaServiciosDefensasTest`, `RespaldoPanelTest`,
`SecretsAndExportsTest` sobre el panel, etc.).

**No medido:** PostgreSQL aislado (`MatriculaRulesPostgresTest` se editó sin ejecutar), el envío real por Resend, el mapa de rutas en un
servidor real y el flujo completo en un navegador real.
