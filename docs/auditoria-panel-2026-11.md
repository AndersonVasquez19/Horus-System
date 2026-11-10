# Auditoría del panel administrativo (noviembre 2026)

Fechas: 09–10/11/2026. Rama `anderson`. **Sin commits ni despliegue**: todos los cambios están en el árbol de trabajo.

Este documento registra qué se revisó en `/admin`, qué se corrigió, qué se decidió no cambiar y qué queda pendiente. Complementa a `CLAUDE.md` y a los documentos de sprint; si discrepan, manda el código.

## 1. Alcance y método

- Revisión de solo lectura de rutas, controladores `Admin`, requests, servicios, modelos, migraciones, vistas del panel, configuración y pruebas. Cada hallazgo se reverificó en el código antes de corregirlo; varios de la primera exploración resultaron ser reglas deliberadas (sección 4).
- Línea base antes de tocar nada: `php artisan test --compact` → 3559 pasan, 1 omitida. Los 5 fallos por fixtures de privacidad que citaban documentos anteriores ya no ocurren.
- Límites respetados (`AGENTS.md`): backend primero; vistas solo con aprobación explícita del propietario (concedida para Perfil → Sesiones y Auditoría → filtros); sin tocar `.env*`, sin migraciones ni escrituras sobre PostgreSQL, sin Resend ni OpenAI.

**Evidencia:** todo se probó en SQLite en memoria (`phpunit.xml`). No demuestra PostgreSQL (bloqueos `lockForUpdate`, DDL, planes de consulta), concurrencia real, sesiones en Redis/archivo ni despliegue.

## 2. Cambios aplicados

### Seguridad y robustez (Fase 1)

| # | Problema | Cambio | Archivos | Prueba |
| --- | --- | --- | --- | --- |
| 1 | La exportación JSON de auditoría cargaba la tabla completa en memoria | Emisión por bloques con `lazyByIdDesc(500)` y tope por id fijado antes de auditar (el propio `audit_exported` no entra). Formato y orden iguales | `Admin/AuditoriaController.php` | `ExportacionesPanelRobustezTest` |
| 3 | Exportaciones costosas sin límite de velocidad (varias son GET con efecto lateral en auditoría) | Limitador `admin-export`: 6/min por usuario, compartido por CSV de matrículas, Excel/PDF de reportes, auditoría y respaldo | `AppServiceProvider.php`, `routes/web.php` | `ExportacionesPanelRobustezTest` |
| 11 | Un id no numérico (`/admin/estudiantes/abc`) llega a la consulta: en PostgreSQL es `SQLSTATE 22P02` → 500 | `Route::pattern('[0-9]+')` para `aprobacion`, `curso`, `estudiante`, `matricula`, `notificacion`, `role`, `user` (todos solo existen bajo `/admin`) → 404 | `AppServiceProvider.php` | `ExportacionesPanelRobustezTest` |
| 2 | Sin rastro de accesos | Auditoría de `login`, `login_failed`, `logout` y `password_reset`. El fallido guarda `cuenta_existente` y una huella HMAC del correo (16 hex con `APP_KEY`), nunca el correo ni la contraseña | `Admin/AuthController.php`, `Auth/ResetPasswordController.php` | `AuditoriaAccesosTest` |
| 8 | El respaldo armaba cada CSV como string y el ZIP con `addFromString` | Cada tabla se escribe a un temporal (`horus_csv_*`) y se añade con `addFile`; los temporales se borran siempre tras cerrar el ZIP | `Admin/RespaldoController.php` | `ExportacionesPanelRobustezTest`, `SecretsAndExportsTest` |
| 9 | Se podía desactivar un estudiante o archivar un curso con matrículas vigentes (cupos ocupados por alumnos «eliminados») | **Decisión del propietario: bloquear.** `destroy` responde redirección con error (o 422 en JSON) si hay matrículas `pendiente`/`pagada`, evaluadas bajo el bloqueo de la fila. Con todas anuladas se permite | `Admin/EstudianteController.php`, `Admin/CursoController.php`; actualizado `docs/sprint-07-consistencia-matriculas.md` | `ArchivadoConMatriculasVigentesTest` |
| 12 (parcial) | Textos sin tope | `observaciones` de matrícula ≤ 2000; `descripcion` de curso ≤ 5000 (creación y edición) | `Store/UpdateMatriculaRequest.php`, `Store/UpdateCursoRequest.php` | `LimitesTextoLargoTest` |
| 16 (parcial) | Los roles base `coordinador` y `secretaria` se podían renombrar o eliminar (dejaría a sus usuarios sin acceso al panel) | **Decisión del propietario.** `AdminProtection::ROLES_BASE`: no se renombran ni eliminan; sus permisos siguen editables | `Services/AdminProtection.php` | `ProteccionRolesBaseTest` |

### Datos y rendimiento (Fase 2)

- **Migración `2026_11_10_000037_add_indices_consultas_panel`**: 10 índices justificados por consultas reales o por FKs que PostgreSQL no indexa:
  - `audit_logs`: `created_at`, `user_id`
  - `notificaciones_internas`: `(user_id, audiencia, leida)` (ambas ramas de `visiblePara()` y contador de no leídas)
  - `aprobaciones_internas`: `estado`, `solicitante_id`, `aprobador_id`
  - `matriculas`: `curso_id`, `fecha_matricula`
  - `chat_logs`: `created_at`, `(intent_detectado, created_at)`

  Solo añade índices, omite los existentes (`Schema::hasIndex`), es idempotente y reversible. Prueba: `IndicesConsultasPanelMigracionTest` (instalación nueva y base ya sembrada).
- **Selectores de matrícula** (`create`/`edit`): solo hidratan las columnas que pinta `_form` (sin correo, teléfono ni descripciones). Prueba: `SelectorMatriculaColumnasTest`.

### Funcionalidades nuevas (Fase 3)

**Sesiones activas** (`Admin/PerfilSesionesController.php`, `Support/SesionesActivas.php`, vista `admin/perfil/edit`):

| Ruta | Efecto |
| --- | --- |
| `GET /admin/perfil/sesiones` | JSON con las sesiones vigentes propias: IP, navegador, última actividad (Lima) y cuál es la actual. `Cache-Control: no-store` |
| `DELETE /admin/perfil/sesiones` | Cierra las demás sesiones web, sube `jwt_version` (revoca JWT) y rota `remember_token`. Auditado como `sesiones_cerradas` |
| `DELETE /admin/perfil/sesiones/{identificador}` | Cierra una sesión concreta propia |

- Las operaciones de cierre exigen la contraseña actual (bolsa de errores `sesiones`) y usan `throttle:perfil`.
- El id de sesión nunca sale del servidor: se expone una huella HMAC de 32 hex. Solo se opera sobre filas del propio usuario; las caducadas (`session.lifetime`) no se listan.
- Requiere `SESSION_DRIVER=database` (predeterminado); con otro driver la sección informa que no está disponible.
- La pantalla Perfil muestra la lista (renderizada en servidor, sin JavaScript nuevo), un cierre por sesión con `<details>` nativo y «Cerrar las demás sesiones».
- Prueba: `SesionesActivasTest`.

**Filtros de auditoría** (`Admin/AuditoriaController.php`, vista `admin/auditoria/index`):

- `desde` y `hasta` (AAAA-MM-DD) como días completos en `America/Lima`, convertidos al UTC en que se guarda `created_at`. Una fecha con otro formato o inexistente se ignora como filtro vacío.
- Listado y exportación comparten los mismos filtros (acción, modelo, usuario, desde, hasta). Sin filtros la exportación sigue siendo total.
- `audit_exported` registra qué filtros se usaron; del filtro de usuario solo un booleano (nunca el nombre o correo buscado).
- La pantalla añade los campos de fecha, «Limpiar», el botón de exportar con los filtros actuales y muestra la columna Fecha en hora de Lima.
- Prueba: `AuditoriaFiltrosTest`.

## 3. Decisiones del propietario

| Tema | Decisión |
| --- | --- |
| Archivar con matrículas vigentes | Bloquear |
| Ingresos visibles a coordinador/secretaria en Reportes | **Omitir.** Se llegó a implementar un permiso `reportes.ingresos` y se revirtió por completo a pedido del propietario. Reportes muestra ingresos a quien tenga `reportes.ver`; el inicio sigue limitándolos al rol `admin` |
| Usuarios y roles | Solo proteger los roles base; no se cambió el borrado físico de usuarios |
| Vistas | Aprobadas solo Perfil → Sesiones activas y Auditoría → filtros de fecha y exportación filtrada |

## 4. Revisado y no cambiado (con motivo)

- **`If-Match` opcional en la publicación de cursos.** El editor ya envía `_revision` siempre. Exigirla hizo fallar ~80 pruebas que usan la ruta sin precondición, y `docs/sprint-17-1-editor-panel.md` registra esa compatibilidad como concesión deliberada. Se revirtió.
- **Aprobaciones: `ver` habilita editar/eliminar.** `update`/`destroy` ya exigen ser el solicitante (o admin) y estado pendiente; `AprobacionOperationsTest` fija a propósito que una secretaria solicitante puede editar o borrar su propia solicitud pendiente. Se revirtió.
- **Comodines `%`/`_` sin escapar en búsquedas `LIKE`.** No es inyección (`whereLike` enlaza parámetros); solo ensancha búsquedas sobre datos que el usuario ya puede listar. Corregirlo exige `whereRaw ... ESCAPE` por columna en 8 controladores (patrón ya presente en `ReclamacionController`). Baja prioridad.
- **Coherencia `monto_pagado` ↔ estado de la matrícula** (p. ej. `pagada` sin monto). Regla de negocio: requiere decisión.
- **Bloqueo persistente de cuenta y 2FA.** Ya existe `throttle:login` (10/min por IP y por correo). Un bloqueo persistente necesita columnas nuevas y diseño.

## 5. Pendientes

**Requieren aprobación de vistas**

- Pasar a POST las exportaciones que hoy son GET con efecto lateral (auditoría, respaldo, CSV de matrículas): cambia botones y enlaces.
- Búsqueda asíncrona/paginada en los selectores de matrícula para volúmenes grandes.
- Mensajes de validación por defecto en inglés: requieren `APP_LOCALE=es` y archivos `lang/es` (cambio de entorno, no autorizado aquí).

Resueltos después: hora de Lima en el resto de pantallas y en el «mes» del inicio, configuración efectiva y páginas de error propias (sección 8); accesibilidad del layout y campana de notificaciones (sección 9).

**Requieren decisión funcional**

- Pagos/cuotas de matrícula, importación masiva, respaldo real de PostgreSQL (`pg_dump` programado y retención).
- Permisos huérfanos (`seguimientos.*`, `asistente.entrenar`, `reclamaciones.gestionar|responder` sin rutas) y la tabla `procesos_internos` sin pantalla.
- `cascadeOnDelete` en `matriculas` frente a `restrictOnDelete` en el resto; estados sin `CHECK`; `json` frente a `jsonb`.

**Despliegue (no se tocó `.env.example`)**

- Producción: `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` (o `TRUSTED_PROXIES` correcto), CSP en modo `enforce`, `JWT_SECRET` definido, `LOG_STACK=daily`.
- No hay integración continua: conviene ejecutar `php artisan test`, `vendor/bin/phpstan analyse` y `npm test` en cada cambio.

## 6. Aplicar en PostgreSQL (cuando se autorice)

**Aplicadas el 10/11/2026** en la base local `horus_group` (127.0.0.1:5432, entorno `local`), con autorización expresa del propietario y solo para estas dos: `000037` (índices) y `000038` (`notificacion_lecturas`), cada una con `--path`. Verificado después: los 12 índices y la tabla (0 filas) existen. Siguen pendientes y **no** se aplicaron: `000022` (suspendida), `000035` y `000036` (solicitudes de matrícula). Otros entornos (producción) requieren su propia aplicación.

- La migración de índices no se ha ejecutado en PostgreSQL. `CREATE INDEX` no concurrente bloquea escrituras de cada tabla mientras se construye: aplicarla en una ventana de poco uso.
- Mientras la migración `000022` siga suspendida, no ejecutar `migrate` sin `--path` (`docs/sprint-15-preparacion-integracion.md` §8.4). Ejemplo: `php artisan migrate --path=database/migrations/2026_11_10_000037_add_indices_consultas_panel.php`.
- Tras aplicarla, conviene contrastar planes (`EXPLAIN`) del listado de auditoría y del contador de notificaciones.
- Lectura por usuario de notificaciones generales (sección 9): `php artisan migrate --path=database/migrations/2026_11_10_000038_create_notificacion_lecturas_table.php`. Solo crea la tabla `notificacion_lecturas`; no toca filas existentes. Sin ella, la bandeja, la campana y el inicio fallan al contar no leídas, así que debe aplicarse junto con el despliegue del código.

## 7. Pruebas añadidas

`ExportacionesPanelRobustezTest`, `AuditoriaAccesosTest`, `ArchivadoConMatriculasVigentesTest`, `LimitesTextoLargoTest`, `ProteccionRolesBaseTest`, `IndicesConsultasPanelMigracionTest`, `SelectorMatriculaColumnasTest`, `SesionesActivasTest`, `AuditoriaFiltrosTest` (todas en `tests/Feature`).

## 8. Pulido del panel (10/11/2026)

Aprobado por el propietario, incluidas las pantallas implicadas.

**Hora de Lima.** `app/Support/HoraLocal.php` (`ZONA`, `ahora()`, `hoy()`, `formato()`) centraliza la presentación; `config/app.php` sigue en UTC a propósito (filtros `->utc()`, JSON de cotizaciones, scheduler y guardas PostgreSQL dependen de ello).
- Pantallas que pasaron de UTC a Lima: inicio («Actualizado al» y auditoría reciente), detalle de matrícula, aprobaciones (creada, actualizada, historial), asistente, notificaciones, estudiantes eliminados y archivos heredados de respaldo. La fecha propuesta al crear una matrícula y la fecha del PDF de reportes también son de Lima.
- El «mes actual» y los últimos 6 meses del inicio se calculan desde la hora de Lima.
- Sin cambio: reclamaciones y cotizaciones (ya en Lima con su propia configuración) y columnas `date`.
- Fuera de alcance: `before_or_equal:today` de la fecha de nacimiento (se evalúa en UTC) y la exportación JSON de auditoría, que conserva `fecha` en UTC para no cambiar su formato.

**Configuración efectiva.** `app/Support/ConfiguracionSistema.php` lee los 5 ajustes en una sola consulta por petición (memoria en el `Request`, sin caché entre peticiones; vacío = predeterminado).
- El nombre de la empresa se usa en el título del panel, la barra superior, el menú lateral, el inicio, la pantalla de Configuración y los PDF administrativos (título, encabezado y pie).
- La moneda se usa en todos los importes del panel y del PDF de reportes, y en las etiquetas «Precio» y «Monto pagado».
- Predeterminados corregidos a los datos publicados en el sitio (teléfono, correo y dirección de Cajamarca) en el controlador y el seeder. El seeder usa `firstOrCreate`: **en bases existentes conviene revisar Configuración**, que puede conservar los datos de ejemplo anteriores (`+51 987 654 321`, `contacto@horusgroup.com`, `Lima, Perú`).
- La pantalla muestra un resumen de errores y el error de cada campo.
- `settings_updated` registra `antes`/`despues` solo de las claves que cambiaron y no se registra si no hubo cambios (mensaje «No hubo cambios»).
- No se cablean: sitio público, correos, asistente ni el Libro de Reclamaciones (monto legal en `S/`).

**Páginas de error.** `resources/views/errors/` con 403, 404, 419, 429, 500 y 503 en español sobre una plantilla autónoma: sin Vite, scripts, rutas con nombre ni base de datos, y sin mostrar nunca el mensaje técnico de la excepción. El enlace vuelve al panel si el error ocurrió bajo `/admin` y a la página principal en otro caso. Las verá también el visitante del sitio. Las respuestas JSON no cambian.

Pruebas: `Unit/HoraLocalTest`, `HoraLocalPanelTest`, `ConfiguracionEfectivaTest`, `PaginasErrorTest`; se actualizó la aserción de `SecretsAndExportsTest` al nuevo formato de `settings_updated`.

## 9. Accesibilidad y campana de notificaciones (10/11/2026)

Aprobado por el propietario, incluidas las pantallas implicadas, con dos decisiones: lectura **por usuario** de las notificaciones generales y campana con **desplegable**.

**Lectura por usuario.** Antes, marcar una general como leída la dejaba leída para todo el personal.
- Migración `2026_11_10_000038`: tabla `notificacion_lecturas` (`notificacion_id`, `user_id`, `leida_at`; única por par; borrado en cascada con la notificación o el usuario).
- Personales: siguen usando `leida` en su fila. Generales: cada usuario registra su propia lectura; `leida` de la fila no cambia.
- Una general con `leida=true` anterior al cambio se sigue considerando leída para todos (no se reabren avisos antiguos).
- `NotificacionInterna`: `noLeidasPara()`, `estaLeidaPara()`, `marcarLeidaPara()` y `vinculoSeguro()` (solo rutas internas y nunca `/admin/procesos*`; un vínculo absoluto ya no se ofrece). El conteo lo centraliza `App\Support\NotificacionesPendientes`, una consulta por petición compartida por la campana y el inicio.

**Campana.** En la barra superior, con contador (hasta «99+») y etiqueta accesible «Notificaciones, N sin leer».
- Al abrirla carga `GET /admin/notificaciones/recientes` (JSON: pendientes y las 5 últimas no leídas con título, mensaje recortado, tipo, fecha en Lima y vínculo seguro). Nada se consulta hasta abrirla.
- «Marcar todas como leídas»: `PATCH /admin/notificaciones/leidas` (JSON o redirección con mensaje), también en la bandeja. Solo afecta a las notificaciones visibles del usuario.
- Escape o clic fuera la cierran; Escape devuelve el foco al botón. Sin JavaScript el botón es un enlace a la bandeja.
- Mismo control de acceso que la bandeja (sesión y rol del panel).

**Accesibilidad del layout.**
- Enlace «Saltar al contenido principal» como primer elemento enfocable; `<main>` recibe el foco.
- Menú lateral: `id`, `aria-controls` y etiqueta Abrir/Cerrar en la hamburguesa; cerrado en móvil queda `inert`; al abrir el foco pasa al primer enlace y al cerrar vuelve al botón.
- Confirmación de borrado: `<dialog>` nativo con `showModal()` (foco atrapado, fondo inerte, Escape), `aria-describedby` y devolución del foco al control que la abrió. El evento `open-confirm-modal` no cambia.
- Avisos: los de éxito se cierran a los 6 s con pausa por puntero o foco; los de error permanecen hasta cerrarlos. La región viva recibe el texto tras iniciar Alpine para anunciarse.
- Un solo `<h1>` por página: en las 10 vistas de formulario el título interno pasó a `<h2 id="admin-form-title">`, que rotula el `<dialog>` (`aria-labelledby`).
- Lógica en módulos (`admin-shell.mjs`, `admin-avisos.mjs`, `confirm-modal.mjs`, `campana-notificaciones.mjs`) registrados en `app.js`, compatible con la CSP (sin scripts en línea). Sin dependencias nuevas.

**Límites.** Solo SQLite en memoria y pruebas unitarias de JavaScript; la migración `000038` no se ha aplicado en PostgreSQL (ver sección 6). Lectores de pantalla reales no se probaron.

Pruebas: `NotificacionLecturaPorUsuarioTest`, `AccesibilidadPanelTest`, `tests/frontend/admin-accesibilidad.test.mjs` y `campana-notificaciones.test.mjs`. Cambiaron a propósito, por la decisión de lectura por usuario, `AdminNotificacionTest` (marcar una general) y `NotificacionPrivacidadUsuarioEliminadoTest` (la lectura compartida pasa a ser por usuario).
