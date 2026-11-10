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
- Mostrar en hora de Lima las demás pantallas que hoy imprimen UTC (notificaciones, matrículas, asistente, respaldo) y el «mes» del inicio, que se calcula en UTC.
- Configuración sin efecto: los valores de `SystemSetting` (empresa, teléfono, moneda…) no se usan en ninguna vista y la pantalla no muestra errores de validación.
- Accesibilidad: sidebar móvil sin `inert`, modal sin trampa de foco, toast de error que se cierra solo, sin enlace «saltar al contenido», doble `<h1>` en formularios.
- Páginas de error propias (403/404/419/429/500) en español; campana de notificaciones con contador.

**Requieren decisión funcional**

- Pagos/cuotas de matrícula, importación masiva, respaldo real de PostgreSQL (`pg_dump` programado y retención).
- Permisos huérfanos (`seguimientos.*`, `asistente.entrenar`, `reclamaciones.gestionar|responder` sin rutas) y la tabla `procesos_internos` sin pantalla.
- `cascadeOnDelete` en `matriculas` frente a `restrictOnDelete` en el resto; estados sin `CHECK`; `json` frente a `jsonb`.

**Despliegue (no se tocó `.env.example`)**

- Producción: `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` (o `TRUSTED_PROXIES` correcto), CSP en modo `enforce`, `JWT_SECRET` definido, `LOG_STACK=daily`.
- No hay integración continua: conviene ejecutar `php artisan test`, `vendor/bin/phpstan analyse` y `npm test` en cada cambio.

## 6. Aplicar en PostgreSQL (cuando se autorice)

- La migración de índices no se ha ejecutado en PostgreSQL. `CREATE INDEX` no concurrente bloquea escrituras de cada tabla mientras se construye: aplicarla en una ventana de poco uso.
- Mientras la migración `000022` siga suspendida, no ejecutar `migrate` sin `--path` (`docs/sprint-15-preparacion-integracion.md` §8.4). Ejemplo: `php artisan migrate --path=database/migrations/2026_11_10_000037_add_indices_consultas_panel.php`.
- Tras aplicarla, conviene contrastar planes (`EXPLAIN`) del listado de auditoría y del contador de notificaciones.

## 7. Pruebas añadidas

`ExportacionesPanelRobustezTest`, `AuditoriaAccesosTest`, `ArchivadoConMatriculasVigentesTest`, `LimitesTextoLargoTest`, `ProteccionRolesBaseTest`, `IndicesConsultasPanelMigracionTest`, `SelectorMatriculaColumnasTest`, `SesionesActivasTest`, `AuditoriaFiltrosTest` (todas en `tests/Feature`).
