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
