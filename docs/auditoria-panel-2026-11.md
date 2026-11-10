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

Resueltos después: hora de Lima en el resto de pantallas y en el «mes» del inicio, configuración efectiva y páginas de error propias (sección 8); accesibilidad del layout y campana de notificaciones (sección 9); exportaciones por POST, escape de `LIKE` y fecha de nacimiento en hora de Lima (sección 10); selectores de matrícula con búsqueda en el servidor (sección 12); mensajes de validación en español (sección 13); retirada de permisos sin uso (sección 14); migraciones suspendidas apartadas (sección 15); `CHECK` de estados preparado, sin aplicar (sección 16).

**Requieren decisión funcional**

- Pagos/cuotas de matrícula, importación masiva, respaldo real de PostgreSQL (`pg_dump` programado y retención).
- Permisos sin uso: `seguimientos.*` y `asistente.entrenar` se retiraron (sección 14). `reclamaciones.gestionar|responder` **no son huérfanos** (los exige `GestionReclamaciones`; el panel de reclamaciones es de solo lectura por diseño) y `procesos_internos` se conserva a propósito (`docs/retiro-procesos.md`).
- `cascadeOnDelete` en `matriculas`: migración 000040 preparada, sin aplicar (§17). `json` frente a `jsonb`: decidido no migrar (§17).

**Despliegue (no se tocó `.env.example`)**

- Producción: `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` (o `TRUSTED_PROXIES` correcto), CSP en modo `enforce`, `JWT_SECRET` definido, `LOG_STACK=daily`.
- Integración continua y guía: ver la sección 11 (`.github/workflows/ci.yml` y `docs/guia-despliegue.md`). Ambas están sin probar en un entorno real hasta el primer push y el primer despliegue.

## 6. Aplicar en PostgreSQL (cuando se autorice)

**Aplicadas el 10/11/2026** en la base local `horus_group` (127.0.0.1:5432, entorno `local`), con autorización expresa del propietario y solo para estas dos: `000037` (índices) y `000038` (`notificacion_lecturas`), cada una con `--path`. Verificado después: los 12 índices y la tabla (0 filas) existen. `000022`, `000035` y `000036` **no** se aplicaron y desde el 10/11/2026 están apartadas (sección 15). Otros entornos (producción) requieren su propia aplicación.

**Solicitudes de matrícula (000035 y 000036): decisión del propietario del 10/11/2026, no aplicarlas.** El código existe en el repositorio (`SolicitudMatriculaController`, `SolicitudMatriculaPublicaController`, `GestionSolicitudesMatricula`, vistas y rutas, commit `0190498`) pero queda **inactivo** mientras no se apliquen sus migraciones: el formulario público `/solicitar-matricula` responde 503 (comprueba `Schema::hasTable('solicitudes_matricula')`), `/admin/solicitudes-matricula` responde 403 porque los permisos `solicitudes-matricula.ver|gestionar` no existen en `horus_group`, y el enlace del menú lateral permanece oculto. La `000036` es la única que concede permisos. No hay que ejecutar `migrate` sin `--path`; `000022` sigue suspendida.

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
- Fuera de alcance: la exportación JSON de auditoría, que conserva `fecha` en UTC para no cambiar su formato. (`before_or_equal:today` de la fecha de nacimiento se corrigió después, sección 10.)

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

## 10. Exportaciones por POST, escape de LIKE y fecha de nacimiento (10/11/2026)

Aprobado por el propietario, incluidas las tres pantallas con botón de exportación.

**Exportaciones por POST.** Tres exportaciones escribían auditoría (`audit_exported`, `data_export_generated` y la exportación de matrículas) pero se disparaban con `GET`, que un prefetch, un enlace ajeno o un favorito pueden activar sin que nadie lo pida.
- `POST /admin/matriculas/exportar/csv` (`matriculas.csv`), `POST /admin/auditoria/exportar` (`auditoria.exportar`) y `POST /admin/respaldo/exportar` (`respaldo.exportar`). Mismos nombres, permisos y `throttle:admin-export`; un `GET` a esas URLs responde 405 y no deja registro de auditoría. Los controladores no cambiaron.
- Los botones son formularios con `@csrf`. Los filtros viajan como campos ocultos: en matrículas solo `busqueda`, `estado`, `curso_id` y `eliminados`, y solo si son texto (un filtro con forma de array no se reenvía); en auditoría, los filtros vigentes ya saneados.
- Sin enlaces externos conocidos a estas URLs. Un favorito o script antiguo con `GET` dejará de funcionar.

**Escape de `LIKE`.** `whereLike('col', "%{$q}%")` dejaba que un `%` o un `_` escrito en el buscador actuara como comodín (buscar `%` devolvía todo el listado).
- Nuevo `App\Support\BusquedaLike` (`termino()`, `contiene()`): escapa `!`, `%` y `_`, declara `ESCAPE '!'` y mantiene la búsqueda insensible a mayúsculas (`ILIKE` en PostgreSQL, `LIKE` en SQLite). Las columnas son fijas del código y pasan por la gramática; el texto va siempre como parámetro. El `id` de matrícula se compara como `CAST(id AS TEXT)`.
- Aplicado en estudiantes, cursos, usuarios, matrículas, aprobaciones, búsqueda global, reportes (filtro de matrículas) y filtros de auditoría (acción, modelo y usuario). `ReclamacionController` ya escapaba con el mismo criterio y no se tocó.
- La sintaxis PostgreSQL se fija compilando la consulta sin conexión; no se ejecutó contra PostgreSQL.

**Fecha de nacimiento.** `before_or_equal:today` se evaluaba en UTC: desde las 19:00 de Lima aceptaba la fecha de mañana. Ahora usa `HoraLocal::hoy()` en alta y edición de estudiantes. `fecha_matricula` no tiene tope superior y no se cambió.

Pruebas: `ExportacionesPostTest`, `BusquedaLikeEscapeTest` y `EstudianteFechaNacimientoLimaTest`. Las demás pruebas de exportación cambiaron de `get` a `post` sin debilitar aserciones; `AdminAuthorizationTest` ya no incluye las dos exportaciones en la matriz de páginas GET (su matriz está en `ExportacionesPostTest`), y la prueba de filtros con forma de array de `AdminListFilterInputTest` ya no cubre `matriculas.csv` (lo cubre `AuditoriaExportacionMatriculasTest`).

**Límites.** Solo SQLite en memoria. La concurrencia y el comportamiento real de `ILIKE ... ESCAPE` en PostgreSQL no se midieron. No se probó la descarga real en un navegador.

## 11. Integración continua y guía de despliegue (10/11/2026)

**CI (suspendido el 10/11/2026 por decisión del propietario: solo `workflow_dispatch`, ejecución manual; los disparadores originales están comentados en el archivo).** `.github/workflows/ci.yml` (GitHub Actions; diseñado para pull requests y push a `main` y `anderson`, permisos de solo lectura y sin secretos):
- Job PHP (8.4): `composer install`, compilación de recursos (`npm ci` y `npm run build`: sin `public/build/manifest.json` fallan las ~370 pruebas que renderizan vistas; el primer run en GitHub falló por eso), `.env` temporal con `key:generate` en el runner efímero, `php artisan test --compact` y `vendor/bin/phpstan analyse`.
- Job frontend (Node 24): `npm ci`, `npm test` y `npm run build`.
- Pint solo se ejecuta, de forma informativa, sobre los `.php` que cambia el pull request: el estilo global tiene deuda preexistente.
- No ejecuta los smokes de navegador ni el perfil PostgreSQL aislado. Con SQLite en memoria **no prueba PostgreSQL ni concurrencia**.
- Los mismos comandos pasan en local (suite, PHPStan, `npm test`, build) y el YAML se validó como sintaxis; **el primer run en GitHub (10/11/2026) falló** por el manifest de Vite ausente en el job PHP; se corrigió compilando los recursos antes de las pruebas y subiendo las acciones a `checkout@v7`, `cache@v6` y `setup-node@v7`. Falta un run verde que lo confirme (pueden aparecer diferencias Linux/Windows aún no vistas).

**Guía.** `docs/guia-despliegue.md`: requisitos, variables por nombre, orden de despliegue con migraciones siempre con `--path` (000022 suspendida), procesos permanentes (scheduler y worker), verificación y reversa. `route:cache`, `view:cache` y `schedule:list` se comprobaron en local y los cachés generados se limpiaron. No se probó en un servidor.

## 12. Selectores de matrícula con búsqueda en el servidor (10/11/2026)

Aprobado por el propietario, incluidas las vistas y el módulo de JavaScript. Antes, crear y editar una matrícula cargaban **todos** los estudiantes y cursos en dos `<select>`, y el listado cargaba todos los cursos para su filtro: la página crecía sin límite.

- **Opciones JSON** (`MatriculaController::opcionesEstudiantes` y `opcionesCursos`): `GET /admin/matriculas/opciones/estudiantes` y `/cursos`, parámetros `q` y `contexto` (`crear`, `editar`, `filtro`; el filtro solo aplica a cursos). Máximo 20 resultados ordenados, búsqueda con `BusquedaLike`, solo `id`, `texto` y `eliminado` (la misma etiqueta de antes; sin correo, teléfono ni descripciones).
- **Permisos:** el middleware admite `matriculas.ver|crear|editar` y cada contexto exige el suyo (crear → `matriculas.crear`, editar → `matriculas.editar`, filtro → `matriculas.ver`). Limitador `admin-opciones`: 120 por minuto por usuario.
- **Elegibilidad sin cambios** (`App\Support\OpcionesMatricula`): alta → estudiantes activos y cursos activos con cupos; edición → cualquier registro no eliminado, más el estudiante y el curso actuales de la matrícula aunque estén eliminados; filtro → cursos incluso eliminados. La validación de escritura (`StoreMatriculaRequest`, `UpdateMatriculaRequest` y la relectura bajo bloqueo) sigue siendo la autoridad: el selector solo propone.
- **Vista:** `matriculas/_selector.blade.php` (combobox con `role="combobox"`, `aria-controls`, `aria-activedescendant`, región `aria-live`) y `resources/js/selector-asincrono.mjs` (pausa de 250 ms, cancelación con `AbortController`, teclado: flechas, Inicio, Fin, Enter, Escape y Tab). El valor viaja en un `<input type="hidden">` con el nombre original (`estudiante_id`, `curso_id`); el valor y la etiqueta iniciales (valor previo, preselección o registro actual) se resuelven en el servidor. Escape cierra solo la lista, no el diálogo del formulario. El filtro del listado usa el mismo componente.
- **Límite:** sin JavaScript no se puede elegir estudiante ni curso; el panel ya depende de JavaScript para modales y menú.
- **Pruebas:** `MatriculaOpcionesTest`, `tests/frontend/selector-asincrono.test.mjs` y, en el smoke de navegador `formularios-modal-smoke.mjs`, un recorrido con teclado (el router del fixture admite ahora las dos rutas de opciones). Cambiaron las pruebas que leían las etiquetas `<option>` (`AdminMatriculaContextTest`, `AdminMatriculaTest`, `AdminListFilterInputTest`, `BackendPerformanceTest`, `SelectorMatriculaColumnasTest`); la regresión de `old()` y de las preselecciones sigue cubierta con el valor oculto.

## 13. Mensajes de validación en español (10/11/2026)

- Se añadió `lang/es/{validation,auth,passwords,pagination}.php` con las claves de Laravel 12 y un mapa `attributes` para los campos más visibles (DNI, correo electrónico, fecha de nacimiento…).
- `config/app.php` usa `es` como valor por defecto de `APP_LOCALE`. **`.env` y `.env.example` no se tocaron:** `.env.example` sigue con `APP_LOCALE=en`, y un `.env` que lo defina prevalece sobre el valor por defecto. Para ver los mensajes en español en un entorno concreto hay que poner `APP_LOCALE=es` (y `APP_FALLBACK_LOCALE=en` puede quedarse).
- `phpunit.xml` fija `APP_LOCALE=es`, así las pruebas son deterministas en local y en CI. Suite completa sin cambios en las demás pruebas.
- `tests/Feature/MensajesValidacionEspanolTest.php` comprueba el idioma, textos con atributos legibles y que no falte ninguna clave de validación de Laravel.

## 14. Permisos sin uso retirados (10/11/2026)

- `seguimientos.ver|crear|editar|eliminar` y `asistente.entrenar` no los comprobaba ninguna ruta, controlador, vista ni prueba (el seguimiento de cotizaciones usa `cotizaciones.gestionar`). Decisión del propietario: **retirar sin borrar**, como `procesos.*`.
- `RolesAndPermissionsSeeder` ya no los crea ni los concede (`PERMISSIONS`, coordinador, secretaria), y `RoleController::PERMISOS_RETIRADOS` los oculta en la pantalla de Roles.
- **En bases existentes las filas siguen en `permissions` y `role_has_permissions`**: solo dejan de verse. No hay migración ni escritura en PostgreSQL; borrarlas exigiría una migración y autorización aparte. Al guardar un rol desde la pantalla se desasocian de ese rol (inocuo: nada los consulta).
- `PermisosRetiradosTest` cubre instalación nueva, ocultación sin borrado y que tenerlos o no no cambia el acceso.

## 15. Migraciones suspendidas apartadas (10/11/2026)

- **Decisión del propietario:** quitar las 3 migraciones pendientes de `horus_group` sin eliminarlas. Se movieron con `git mv` (mismo contenido y SHA-256) a `database/migraciones-suspendidas/`: `000022` (`registros_pendientes`, verificación de correo apagada), `000035` (`solicitudes_matricula`) y `000036` (permisos `solicitudes-matricula.*`). Laravel solo escanea `database/migrations`, así que `php artisan migrate:status --pending` dice «No pending migrations» y `migrate` plano ya no arrastra la `000022`.
- El **código de ambas funciones sigue en el repositorio, inactivo**: verificación apagada (`config/registro-verificacion.php`), formulario público 503, panel de solicitudes 403, enlace del menú oculto. `README.md` de la carpeta explica cómo reactivarlas (`git mv` de vuelta y autorización expresa; la `000036` pide copia reciente).
- **Pruebas:** `InstalaMigracionesSuspendidas` (tests/Support) ejecuta esos archivos solo en SQLite `:memory:` para `SolicitudMatriculaTest`, `Sprint14EmailVerificationTest` y el router del smoke de solicitudes. El manifiesto de Fase 4 apunta a la nueva ruta de la `000022` (el hash no cambia; los instaladores ya la saltan por regex).
- `SOLICITUDES_MATRICULA_PERMISSIONS` sigue dentro de `RolesAndPermissionsSeeder::PERMISSIONS` (sin cambio en instalaciones nuevas); decidir su destino cuando se reactive el módulo.
- **Riesgo no medible desde aquí:** si alguna se aplicó en otro entorno, su fila queda en `migrations` sin archivo; `migrate` lo tolera, `migrate:rollback` de ese lote fallaría.
- **Coherencia de pagos (`monto_pagado` ↔ estado): suspendida por decisión del propietario.** Sin cambios.
- Nada se escribió en PostgreSQL.

## 16. CHECK de estados del núcleo académico (10/11/2026, aplicado en `horus_group`)

- **Migración nueva** `2026_11_10_000039_add_check_estados_academicos.php`, solo PostgreSQL (en SQLite es un no-op: allí rigen los `FormRequest`). Añade `matriculas_estado_valido` {pendiente, pagada, anulada}, `cursos_estado_valido` {activo, inactivo, archivado}, `cursos_modalidad_valida` y `curso_publicaciones_modalidad_valida` {presencial, semipresencial, virtual, hibrido}, y `estudiantes_estado_valido` {activo, inactivo}. Los conjuntos salen de los `FormRequest` vigentes.
- **Preflight integrado:** cuenta filas fuera del conjunto (incluidas las eliminadas lógicamente) y se detiene con tabla, columna, conteo e ids, sin datos personales. Es idempotente y tiene `down()`.
- **Verificación de datos reales (solo lectura, `horus_group`):** `estudiantes.estado` = {activo}; `cursos.estado` = {activo}; `cursos.modalidad` = {hibrido, presencial, virtual}; `matriculas.estado` = {pagada, pendiente}; `curso_publicaciones` vacía. Todo cumple los conjuntos.
- **Aplicada el 10/11/2026** en la base local `horus_group` (pgsql 127.0.0.1:5432, entorno `local`) con autorización expresa del propietario, con `php artisan migrate --force --path=…000039…` (107 ms). No se hizo una copia nueva antes (tablas pequeñas, DDL transaccional, `down()` disponible). Verificado después en solo lectura: las 5 restricciones existen y están validadas, y `migrate:status` no tiene pendientes. **Otros entornos (producción) requieren su propia aplicación**, con copia reciente y `--path`: es DDL real sobre cuatro tablas (`ACCESS EXCLUSIVE` breve). Tablas fuera de alcance: `aprobaciones_internas`, `notificaciones_internas.tipo`, `matricula_notifications.status` y `procesos_internos`.
- **Pruebas:** `CheckEstadosAcademicosMigracionTest` (SQLite) fija que los conjuntos del `CHECK` coinciden con las reglas `Rule::in` de los `FormRequest`, que el SQL generado es el esperado y que `up()`/`down()` no hacen nada en SQLite. `tests/PostgresIntegrity/EstadosCheckPostgresTest.php` (rechazo `23514`, valores válidos y preflight) está escrita pero **no se ejecutó**: exige el perfil PostgreSQL aislado con consentimiento destructivo.
- `json` frente a `jsonb` y `cascadeOnDelete`: ver §17.

## 17. `matriculas`: de CASCADE a RESTRICT (10/11/2026, preparada, NO aplicada)

- **Hallazgo (solo lectura, `horus_group`):** `matriculas_estudiante_id_foreign` y `matriculas_curso_id_foreign` son `ON DELETE CASCADE`; `curso_publicaciones.curso_id` ya es `RESTRICT`. La aplicación solo borra lógicamente (`SoftDeletes`, sin `forceDelete` en `app/`), así que el riesgo es un `DELETE` físico fuera de ella (SQL, script, consola), que borraría en silencio las matrículas (historial de pagos) de un estudiante o curso. 15 matrículas, ninguna eliminada.
- **Migración nueva** `2026_11_10_000040_restrict_on_delete_matriculas.php`, solo PostgreSQL (no-op en SQLite): recrea ambas claves con el mismo nombre en una sola sentencia (`DROP` + `ADD CONSTRAINT … ON DELETE RESTRICT`), idempotente (omite la que ya es `RESTRICT`), sin tocar datos, con `down()` que restaura `CASCADE`.
- **Decisión del propietario:** `matricula_notifications.matricula_id` **se queda en `CASCADE`** (el aviso forma parte de la matrícula).
- **Efecto:** sin cambio en la aplicación. Un `DELETE` físico de un estudiante o curso con matrículas pasa a fallar con error de clave foránea (`23503`); un borrado físico legítimo debe borrar antes las matrículas. Bloqueo breve en `matriculas` y la tabla padre al aplicar.
- **Pruebas:** `RestrictOnDeleteMatriculasMigracionTest` (SQLite; SQL esperado, no-op, no toca `matricula_notifications`). `tests/PostgresIntegrity/RestrictOnDeleteMatriculasPostgresTest.php` (rechazo `23503` y `CASCADE` conservado) escrita y **no ejecutada**.
- **Estado:** no aplicada en ninguna base; requiere autorización expresa (copia reciente y `--path`).

### `json` frente a `jsonb`: no se migra (10/11/2026)

- **Columnas `json` hoy (`horus_group`):** `audit_logs.changes` (31 filas), `chat_intents.keywords` (9), y vacías `cotizacion_historial.metadatos`, `reclamacion_historial.metadatos` y `servicio_publicacion_elementos.caracteristicas`.
- **Sin beneficio:** las únicas consultas por clave JSON son `where('changes->clave', valor)` (p. ej. `GestionReclamaciones`), que Laravel compila a `->>` y funcionan igual en `json` y `jsonb`; ningún índice GIN las aceleraría.
- **Con riesgo:** `ALTER COLUMN TYPE jsonb` reescribe la tabla con `ACCESS EXCLUSIVE`, y `jsonb` normaliza el texto (orden de claves, espacios). El trigger de inmutabilidad de `reclamacion_historial` compara `metadatos::text`, y el historial pasaría a guardarse reordenado.
- **Cuándo reabrirlo:** si aparecen consultas por contenido JSON a gran volumen; entonces se convertiría solo `audit_logs.changes` con índice GIN, en una migración con plan propio.
