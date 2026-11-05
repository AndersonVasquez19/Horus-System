# Sprint 15: preparación de la integración real del backend

Fecha: 28 de octubre de 2026. Rama `anderson`, HEAD inicial `98a5a4a`, árbol limpio, 5 commits por delante de la referencia local `origin/anderson`, dos stashes conservados. Sin frontend, `.env`, credenciales, PostgreSQL real (`horus_group`), perfil aislado S7, Resend, OpenAI, correos reales, commits, push ni despliegue.

**Este documento prepara la integración; no la ejecuta.** Toda la evidencia propia procede de SQLite `:memory:` o de generación de SQL sin conexión. No demuestra comportamiento en PostgreSQL (bloqueos, DDL real, concurrencia), entrega de correo ni despliegue.

## 1. Migraciones pendientes de integración

| Orden | Archivo | Crea / cambia | Depende de | Usado por el código desde |
| --- | --- | --- | --- | --- |
| 1 | `2026_10_27_000012_add_matriculas_exportar_eliminar_permissions` | Permisos `matriculas.exportar`, `matriculas.eliminar`; los concede solo a `admin` | Tablas Spatie (`000006`) | Exportación y borrado lógico de matrículas |
| 2 | `2026_10_27_000013_add_matriculas_integrity_constraints` | Índice único parcial `matriculas_vigente_unica`; en PostgreSQL, 3 `CHECK` (cupos, precio, monto) | `cursos`, `matriculas` | Integridad (la app ya aplica las reglas) |
| 3 | `2026_10_28_000020_create_matricula_notifications_table` | Bandeja de bienvenida (FK `cascade` a `matriculas`) | `matriculas` | **Cada alta de matrícula** (observer) |
| 4 | `2026_10_28_000021_create_curso_publicaciones_table` | Fichas editoriales + permiso `cursos.publicar` para `admin` | `cursos`, `users`, Spatie | Panel de publicación; catálogo (apagado) |
| 5 | `2026_10_28_000022_create_registros_pendientes_table` | Altas pendientes de verificación | — | Solo con la verificación habilitada |

**Estado de aplicación en `horus_group`:** medido en el Sprint 15-B (§8) en solo lectura. Ninguna de las cinco está aplicada.

### Compatibilidad observada

- **DDL PostgreSQL generado sin conexión**: se ejecutó `up()` en modo `pretend` sobre una conexión `pgsql` ficticia cuyo PDO nunca ejecuta SQL. Tipos (`bigserial`, `uuid`, `smallint`, `boolean default '0'`, `timestamp(0) without time zone`), FKs, únicos, índice parcial y `CHECK` son SQL PostgreSQL estándar. `000021` solo pudo generarse hasta el `insert ... returning "id"` de Spatie (limitación de `pretend`, no de la migración). Esto valida la sintaxis generada, **no** su ejecución en PostgreSQL 18.
- **Ida y vuelta en SQLite `:memory:`** sobre una base con roles sembrados: `up` → `down` (5 pasos) → `up` sin errores; tablas, índice y permisos aparecen y desaparecen según lo esperado.
- La suite existente cubre instalación nueva y base sembrada para estas migraciones (grupo `Migration`: 47 pruebas).

### Riesgos

- **`000013` aborta si hay datos incompatibles** (duplicados vigentes, cupos fuera de rango, precio o monto negativos) y lista hasta 20 ids por regla. En PostgreSQL el DDL es transaccional: no deja cambios parciales. Ejecutar antes sus consultas de diagnóstico (sección 3 de `docs/postgresql-l2.2-c-integridad.md`) en solo lectura.
- `000013` crea el índice sin `CONCURRENTLY` (bloquea escrituras en `matriculas` mientras se construye) y los `CHECK` validan la tabla con bloqueo exclusivo. Con el volumen actual es breve; aplicar en ventana sin uso del panel.
- **El `down` no es neutral.** `000012` y `000021` borran sus permisos aunque ya existieran por el seeder, y con ellos toda asignación manual a otros roles o usuarios (comprobado: el rol `admin` pasa de 46 a 43 permisos). `000020`/`000021`/`000022` eliminan tablas con datos (bandeja, fichas editoriales, altas pendientes). Ante un fallo **posterior** a la migración, restaurar desde backup es preferible a `migrate:rollback`.
- Ejecutar `RolesAndPermissionsSeeder` después resincroniza los roles base y descarta permisos concedidos a mano (ver `CLAUDE.md` §4). No forma parte de este procedimiento.

## 2. Resend (bienvenida de matrícula)

**Listo en código:** bandeja transaccional, despacho por scheduler, job con solo el ID (`tries=1`, `timeout=30` s; `retry_after` de la cola 90 s), reserva de despacho (5 min) y de envío (2 min), snapshot cifrado reutilizado, `Idempotency-Key` estable, máximo 4 intentos, ventana de 23 h, estados terminales `accepted`/`failed`/`uncertain`/`skipped`, logs sin PII, `horus:matriculas-notificar --status`.

**`configured()` exige a la vez:** `MATRICULA_MAIL_ENABLED=true` (booleano), conexión de cola asíncrona (`database`, `redis`, `sqs`, `beanstalkd`), nombre de cola, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS` válido y fuera de `example.*`, `MAIL_FROM_NAME` sin saltos ni `<>`. Si falta algo, ni el comando ni el job hacen nada.

**Bloqueos operativos:**

1. **Despliegue antes de migrar rompe las matrículas.** El observer de `Matricula` inserta en `matricula_notifications` sin comprobar la tabla. Con el código actual sobre una base sin `000020`, toda alta de matrícula fallaría y revertiría. La migración debe aplicarse antes de (o junto con, en modo mantenimiento) desplegar este código.
2. **Avisos acumulados.** Resuelto en el Sprint 15-B (§8): los avisos capturados sin envío configurado quedan `held` y nunca se despachan automáticamente.
3. Worker y scheduler no existen hasta configurarlos: `php artisan queue:work <conexión> --queue=<cola>` y `php artisan schedule:run` cada minuto (Programador de tareas en Windows) o `schedule:work`. Mientras el envío esté apagado, el comando programado termina con código de fallo cada minuto: es ruido esperado, no un error.
4. Dominio verificado en Resend y remitente de ese dominio: dependen de acceso a la cuenta, no del código.
5. `payload` está cifrado con `APP_KEY`. Rotarla con avisos en `retry` deja esos payloads ilegibles; no rotar durante la operación.

**Prueba manual pendiente:** un único envío autorizado (procedimiento C).

## 3. Verificación de correo (Sprint 14)

> **Suspendida por decisión del propietario (Sprint 15-B)** hasta nueva autorización expresa. No se activa, no se continúa ni se aplica `000022`. Lo que sigue queda solo como referencia.

**Estado:** desactivada. `config/registro-verificacion.php` fija `enabled => false` y `mailer => null` sin leer el entorno: activar exige un cambio de código y un despliegue, no una variable. Con la opción apagada, los endpoints nuevos responden 404 y el registro web/API conserva su contrato anterior (cubierto por las 28 pruebas del Sprint 14 y las de autenticación previas).

**Contratos preparados** (detalle en `docs/sprint-14-verificacion-registro.md`): respuestas neutras 202 en registro y reenvío, código de 256 bits almacenado como SHA-256, caducidad de 30 min, consumo único con actualización condicional, cooldown de 60 s por correo, `throttle:5,1` en registro/reenvío y 10/min por IP en confirmación, sin sesión ni JWT antes de verificar, fallos de transporte con la misma respuesta neutra y log sin destinatario ni código, y mailer explícito con lista de transportes permitidos (rechaza `log`, `failover`, `sendmail`).

**Requisitos pendientes:**

1. **Cuentas del personal sin verificar (bloqueo de activación).** `horus:crear-usuario` y `/admin/usuarios` crean cuentas con `email_verified_at` nulo; ningún seeder ni comando lo rellena (las factories de prueba sí lo hacen, por eso la suite no lo muestra). Al activar, `attemptWhen` rechaza su login y `DescartarSesionSinVerificar`/`AuthenticateJwt` cierran sus sesiones y JWT: **todo el personal, incluidos los administradores, perdería acceso al panel**. La única vía de recuperación sería el flujo de código, que exige correo operativo y que hoy solo tiene endpoints (sin pantalla web). Antes de activar, el propietario debe decidir cómo se verifican esas cuentas (escritura de datos autorizada o cambio de contrato del comando).
2. Integración frontend pendiente y **no implementada**: pantalla para introducir el código, nombre y contraseña definitiva (`POST /email-verification/confirm`), acción de reenvío (`POST /email-verification/resend`), y adaptación del formulario de registro al mensaje neutro. Los clientes API deben aceptar 202 sin JWT en `/api/auth/register`.
3. Mailer autorizado con entrega real comprobada; activar sin él impide completar cualquier alta.
4. Retención de `registros_pendientes` (no hay limpieza automática). `email_hash` es un HMAC con `APP_KEY`: rotar la clave invalida las búsquedas de solicitudes abiertas.
5. Todas las sesiones previas sin marcador se cierran al activar: comunicarlo.

## 4. Hallazgos

| Id | Severidad | Evidencia | Resolución |
| --- | --- | --- | --- |
| S15-01 | Media | `horus:crear-usuario` comprobaba duplicados con `where('email')` y `Rule::unique`, sensibles a mayúsculas; panel y registro usan `lower(email)`. Con una fila histórica `Jefa@Example.com`, el comando seguía adelante para crear `jefa@example.com` | **Corregido**: misma normalización y `Email::uniqueRule`. Prueba de regresión que falla con el código de HEAD y pasa con la corrección |
| S15-02 | Alta (despliegue) | Observer de `Matricula` sin guarda frente a la ausencia de `matricula_notifications` | Bloqueo de orden: migrar antes de desplegar. Sin cambio de código (añadir una consulta de esquema en cada alta no aporta frente a un orden correcto) |
| S15-03 | Alta (activación Resend) | `dispatchDue()` selecciona todo `pending` sin límite de antigüedad | **Corregido en 15-B** (§8): estado `held` persistente, migración `000023` y límite de 24 h para el primer intento |
| S15-04 | Alta (activación verificación) | Cuentas del personal creadas sin `email_verified_at` (medido: 3 de 3 en `horus_group`); `AuthController::login` usa `attemptWhen(... hasVerifiedEmail())` | Sin efecto mientras la verificación esté suspendida; no se modifica `email_verified_at` |
| S15-09 | Alta (integración) | `horus_group` carece de `aprobaciones.ver`, `aprobaciones.gestionar`, `respaldo.ver`, `respaldo.exportar`; ninguna migración los creaba. Spatie responde 403 a todos, incluido `admin` | **Corregido en 15-B**: migración `000024` |
| S15-05 | Media | `down()` de `000012`/`000021` borra permisos y asignaciones manuales; tablas nuevas pierden datos | Documentado: backup obligatorio, no confiar en rollback |
| S15-06 | Media | `000013` aborta ante datos incompatibles y bloquea `matriculas` durante el DDL | Diagnóstico previo de solo lectura y ventana de mantenimiento |
| S15-07 | Baja | `payload` y `email_hash` dependen de `APP_KEY` | No rotar la clave durante la integración |
| S15-08 | Informativa | Estado de aplicación de migraciones en `horus_group` desconocido | `migrate:status` de solo lectura con autorización |

## 5. Procedimiento de integración (no ejecutado)

> Para las migraciones, **el procedimiento vigente es el §9** (aplicación individual con `--path`, `000022` excluida). Los bloques B y D de esta sección quedan como referencia histórica.

Cada bloque requiere autorización expresa del propietario que nombre el destino. Nunca sobre el perfil S7 suspendido.

### A. Preparación

1. Backup completo verificado del destino (`pg_dump -Fc` y restauración de prueba en otra instancia). Anotar tamaño y hora.
2. Confirmar destino sin imprimir credenciales: `DB_CONNECTION`, host, puerto y nombre de base.
3. Solo lectura: `php artisan migrate:status` y `php artisan migrate --pretend` sobre el destino. Anotar qué migraciones faltan.
4. Solo lectura: consultas de diagnóstico de `000013` (sección 3 de `docs/postgresql-l2.2-c-integridad.md`). Si devuelven filas, detenerse: corregir datos es una decisión de negocio.
5. Confirmar `MATRICULA_MAIL_ENABLED=false`, `config/registro-verificacion.php` en `false/null`, `chatbot.public_catalog_enabled=false`.
6. Confirmar que existe el rol `admin` (las migraciones de permisos solo conceden a ese rol).

### B. Migraciones

1. Activar modo mantenimiento (`php artisan down`) y detener workers y scheduler.
2. **No ejecutar `php artisan migrate` sin argumentos**: aplicaría también `000022`, suspendida. Usar la lista explícita del §8.4, que deja `000022` pendiente. En PostgreSQL cada migración corre en su transacción.
3. Comprobar después: `migrate:status` sin pendientes; existe `matriculas_vigente_unica` y los tres `CHECK`; existen las tres tablas; `admin` tiene `matriculas.exportar`, `matriculas.eliminar` y `cursos.publicar`; `php artisan permission:cache-reset`.
4. Desplegar el código (si no estaba ya) y `php artisan config:cache`. Salir de mantenimiento.
5. Prueba funcional mínima: crear una matrícula de prueba autorizada y comprobar que se crea una fila `held` (`captured_while_disabled`) en `matricula_notifications` sin que nada se envíe (`horus:matriculas-notificar --status`). Comprobar acceso de `admin` a Aprobaciones y Respaldo.
6. **Si falla una migración**: se revierte su transacción; leer el mensaje, no reintentar a ciegas, mantener el sitio en mantenimiento o volver al código anterior. **Si el fallo aparece después**: restaurar backup antes que `migrate:rollback` (ver riesgos del §1).

### C. Resend (una única prueba autorizada)

1. Dominio verificado en Resend; remitente de ese dominio; clave con permiso solo de envío.
2. Instalación y base desechables (nunca `horus_group` ni datos reales) y un destinatario controlado.
3. Decidir el tratamiento del atraso (S15-03). En la base de prueba no debe haber más `pending` que el de la prueba.
4. Configurar cola asíncrona, `RESEND_API_KEY`, `MAIL_FROM_*`, `MATRICULA_MAIL_ENABLED=true`; `config:cache`.
5. Arrancar un worker y ejecutar una vez `horus:matriculas-notificar --limit=1`; no dejar el scheduler en marcha.
6. Comprobar `accepted` con `provider_id`, el registro en el panel de Resend y la llegada al buzón. `accepted` no acredita entrega.
7. Desactivar (`MATRICULA_MAIL_ENABLED=false`, `config:cache`), detener el worker y comprobar `--status`.

### D. Verificación de correo

**Suspendido (Sprint 15-B).** No ejecutar ningún paso de este bloque sin nueva autorización expresa.

1. Requisitos previos: frontend autorizado e implementado, mailer validado con entrega real, decisión sobre cuentas del personal (S15-04) y clientes API adaptados.
2. Comprobar en solo lectura cuántas cuentas con rol tienen `email_verified_at` nulo. Ninguna cuenta del personal debe quedar sin verificar antes de activar.
3. Cambiar `config/registro-verificacion.php` (`enabled => true`, `mailer => '<mailer autorizado>'`) en un cambio revisado; desplegar y `config:cache`.
4. Prueba con una cuenta controlada: registro (202 neutro), código recibido, confirmación (200), login web y API.
5. **Desactivar ante incidencias**: volver a `false/null`, desplegar y `config:cache`. Restablece el contrato anterior; las cuentas ya confirmadas permanecen.

### E. Recuperación

| Situación | Acción |
| --- | --- |
| Falla una migración | Su transacción se revierte. Mantener mantenimiento, analizar el mensaje, no editar migraciones aplicadas; restaurar backup si el fallo es posterior |
| Se cae el worker | Las reservas caducan (5 min despacho, 2 min envío) y el scheduler reencola. No ejecutar `queue:retry` ni cambiar estados |
| Fallos de Resend | 429/409/5xx y transporte: reintento con backoff hasta 4 intentos; 4xx restantes: `failed`. Si persisten, desactivar el envío |
| Notificación `uncertain` | Comprobar `provider_id`, clave e historial en Resend con acceso autorizado. No reenviar a ciegas |
| Dejar una función apagada | Resend: `MATRICULA_MAIL_ENABLED=false` + `config:cache` + detener workers. Verificación: `false/null` en su archivo de configuración y despliegue |

## 6. Validación de este sprint

| Comprobación | Resultado |
| --- | --- |
| Regresión S15-01 con el código de HEAD | Falla (el comando pide contraseña para crear un duplicado) |
| `SecureProvisioningTest` + `Sprint12AtomicityTest` con la corrección | 19 pruebas, 86 aserciones, aprobadas |
| Grupos `MatriculaNotification` / `Sprint14EmailVerification` / `Permission` / `Migration` / `Publicacion` | 45/352, 28/263, 61/288, 47/188, 35/304, aprobadas |
| Suite completa SQLite `:memory:` | **794 pruebas, 5029 aserciones**, aprobadas |
| Larastan nivel 6 (`app`, `routes`, `database`) | Sin errores |
| Pint sobre los 2 archivos PHP propios | `--test` aprobado |
| `git diff --check` | Aprobado; advertencias LF/CRLF conocidas |
| DDL PostgreSQL sin conexión / ida y vuelta SQLite | Ver §1; scripts temporales fuera del repositorio |

## 7. Archivos

- `app/Console/Commands/CrearUsuarioPanel.php`: unicidad de correo sin distinguir mayúsculas (S15-01).
- `tests/Feature/SecureProvisioningTest.php`: prueba de regresión de S15-01.
- `docs/sprint-15-preparacion-integracion.md`: este documento.
- `CLAUDE.md`: entrada en el índice de documentación.

## 8. Sprint 15-B: integración segura del backend y notificaciones

28 de octubre de 2026. Mismo HEAD `98a5a4a`; se conservan los cambios sin commit del Sprint 15 y los dos stashes. **Verificación de correo suspendida** por decisión del propietario: sin cambios en su código, configuración (`false/null`), pruebas, `email_verified_at` ni contratos de registro; `000022` no se ejecuta, no se modifica ni se revierte. Resend sigue desactivado; sin correos ni HTTP reales.

### 8.1 Diagnóstico de solo lectura sobre `horus_group`

Destino comprobado antes de conectar, sin imprimir credenciales: conexión `pgsql`, `127.0.0.1:5432`, base `horus_group`, sin `DB_URL`, sin configuración en caché y sin `HORUS_PG_TEST_PROFILE` (el perfil aislado usa `55432` y otro `.env`). El servidor confirmó `current_database() = horus_group`, `inet_server_port() = 5432`, PostgreSQL 18.6 y `transaction_read_only = on` al inicio y al final. Sesión y transacción en solo lectura, `statement_timeout = 5s`, solo metadatos y conteos, cierre con `ROLLBACK`. Clúster S7 no utilizado.

| Aspecto | Resultado |
| --- | --- |
| Migraciones aplicadas | 15, hasta `2026_10_27_000011` (lotes 1–3). **Pendientes: `000012`, `000013`, `000020`, `000021`, `000022`** y las nuevas `000023`, `000024` |
| Tablas | Existen las base, Spatie, `jobs`, `failed_jobs`, `cache`, `sessions`. No existen `matricula_notifications`, `curso_publicaciones`, `registros_pendientes` |
| Índice/`CHECK` de `000013` | Ausentes. `matriculas` solo tiene `pkey` e índice `(estudiante_id, curso_id)` |
| Conflictos para `000013` | 0 duplicados vigentes, 0 cupos fuera de rango, 0 precios o montos negativos, 0 nulos en columnas de los `CHECK` |
| Volumen | 8 cursos, 10 estudiantes, 15 matrículas (11 `pagada`, 4 `pendiente`, 0 eliminadas), 3 usuarios |
| Roles y permisos | `admin`, `coordinador`, `secretaria` (guard `web`); 39 permisos; admin 39, coordinador 18, secretaria 8; 0 asignaciones directas a usuarios |
| Permisos ausentes | `cursos.publicar`, `matriculas.exportar`, `matriculas.eliminar` (los crean `000012`/`000021`) y **`aprobaciones.ver`, `aprobaciones.gestionar`, `respaldo.ver`, `respaldo.exportar`** (ninguna migración los creaba: S15-09) |
| Usuarios | 0 correos duplicados sin distinguir mayúsculas, 0 correos sin normalizar; 3 de 3 cuentas del personal sin `email_verified_at` (irrelevante mientras la verificación esté suspendida) |
| Cola | `jobs` vacía |

Ninguna migración necesaria depende de `000022`: `registros_pendientes` solo se usa con la verificación habilitada (`VerificacionRegistroService`, rutas con 404 cuando está apagada).

### 8.2 S15-03: política de retención de avisos

Nuevo estado `held` (retenido). Nunca está entre los estados que se despachan (`pending`, `retry`, `sending`), no implica aceptación ni fallo del proveedor, no borra la fila y conserva `matricula_id`, `delivery_key`, fechas y motivo en `last_error`. No existe reenvío manual: liberar un aviso retenido exige una decisión administrativa futura.

| Motivo (`last_error`) | Cuándo | Criterio |
| --- | --- | --- |
| `captured_while_disabled` | El observer captura una matrícula y `configured()` es falso (envío apagado o configuración incompleta) | **Persistente al capturar**: la fila registra si era enviable; activar después no la libera |
| `captured_before_policy` | Migración `000023`: `pending` sin intentos existentes al aplicarla | Corte explícito del atraso previo (el envío nunca se activó). `down()` sin efecto a propósito |
| `stale_before_first_attempt` | `dispatchDue()` o `send()` encuentran un `pending` sin intentos con más de 24 h | Red de seguridad para reactivaciones y caídas largas de cola o worker; también ante jobs ya encolados |

`retry`, `sending`, `uncertain`, `accepted`, `failed` y `skipped` no cambian: siguen su ventana de 23 h y su límite de 4 intentos. El observer solo lee configuración (sin consultas ni HTTP): registrar matrículas con el envío apagado sigue funcionando. `horus:matriculas-notificar --status` muestra `held: N` y `held/<motivo>: N`, sin escribir.

Pruebas nuevas (`tests/Feature/MatriculaNotificationBacklogTest.php`, 10 casos, 109 aserciones): aviso anterior y posterior a la activación, scheduler reiniciado tres veces, job repetido para un retenido, configuración incompleta al capturar, migración del atraso (reservado con job encolado, `retry`, `uncertain` y `accepted` intactos, idempotencia, `down` sin efecto), antigüedad en el despacho, worker reiniciado tras una caída larga, cola caída más de 24 h, matrícula anulada o eliminada y diagnóstico sin escrituras. Con el código de HEAD fallan las 10.

### 8.3 S15-09: permisos de Aprobaciones y Respaldo

`000024` crea los cuatro permisos con `insertOrIgnore` y los concede según `RolesAndPermissionsSeeder`: `aprobaciones.ver` a los tres roles; `aprobaciones.gestionar` y `respaldo.ver` a admin y coordinador; `respaldo.exportar` solo a admin. No resincroniza ni quita nada; `down()` sin efecto. Pruebas (`AprobacionesRespaldoPermissionsMigrationTest`, 5 casos): admin recibe 403 en ambas pantallas antes y 200 después, matriz idéntica al seeder, idempotencia y conservación de asignaciones manuales, base sin roles e instalación nueva intacta.

### 8.4 Orden de integración propuesto (no ejecutado)

Réplica en SQLite `:memory:` del estado medido (15 migraciones, 39 permisos, 39/18/8 por rol) y la orden siguiente: quedan 21 migraciones, 46 permisos, 46/21/9 por rol (matriz del seeder) y `000022` aparece `Pending` sin crear `registros_pendientes`.

```
php artisan migrate --force ^
  --path=database/migrations/2026_10_27_000012_add_matriculas_exportar_eliminar_permissions.php ^
  --path=database/migrations/2026_10_27_000013_add_matriculas_integrity_constraints.php ^
  --path=database/migrations/2026_10_28_000020_create_matricula_notifications_table.php ^
  --path=database/migrations/2026_10_28_000021_create_curso_publicaciones_table.php ^
  --path=database/migrations/2026_10_28_000023_hold_matricula_notifications_backlog.php ^
  --path=database/migrations/2026_10_28_000024_add_aprobaciones_respaldo_permissions.php
```

Ejecutar antes con `--pretend` (solo lectura). `000023` requiere `000020`. Tras migrar, `php artisan permission:cache-reset`. Mientras `000022` esté suspendida, **no ejecutar nunca `php artisan migrate` sin `--path`**. El SQL PostgreSQL de `000023`/`000024` se generó sin conexión (`update ... where status/attempts`, `insert ... on conflict do nothing` sobre el único `(name, guard_name)` de Spatie); no se ha ejecutado en PostgreSQL. Ante un fallo posterior, restaurar backup: no revertir migraciones que tocan permisos.

### 8.5 Validación

| Comprobación | Resultado |
| --- | --- |
| `MatriculaNotification` (incluye retención) | 55 pruebas, 461 aserciones |
| `Migration` | 53 / 240 |
| `Matricula` | 227 / 1873 |
| `Permission` | 66 / 316 |
| Registro, verificación y autenticación (sin cambios) | 46 / 441 |
| Suite completa SQLite `:memory:` | **809 pruebas, 5166 aserciones**, aprobadas |
| Larastan nivel 6 | Sin errores |
| Pint sobre los 9 archivos PHP del sprint | Aprobado |
| `git diff --check` | Aprobado; advertencias LF/CRLF conocidas |
| Frontend, `config/`, rutas, `app/Http`, `app/Models`, verificación, `000022`, `.env.example` | Sin diferencias respecto de HEAD |

SQLite no demuestra concurrencia, bloqueos ni DDL de PostgreSQL. La reserva de despacho es una actualización condicional; su comportamiento bajo varios schedulers simultáneos en PostgreSQL no se ha medido.

### 8.6 Archivos del Sprint 15-B

- `app/Services/MatriculaNotificationService.php`: estado `held`, motivos, `initialState()`, retención por antigüedad en `dispatchDue()` y `send()`.
- `app/Observers/MatriculaNotificationObserver.php`: persiste el estado inicial según la configuración al capturar.
- `app/Console/Commands/DispatchMatriculaNotifications.php`: `--status` desglosa retenidos por motivo.
- `database/migrations/2026_10_28_000023_hold_matricula_notifications_backlog.php`: corte del atraso previo.
- `database/migrations/2026_10_28_000024_add_aprobaciones_respaldo_permissions.php`: permisos ausentes en bases sembradas.
- `tests/Feature/MatriculaNotificationBacklogTest.php`, `tests/Feature/AprobacionesRespaldoPermissionsMigrationTest.php`: pruebas nuevas.
- `CLAUDE.md`: contrato de retención en la sección de bienvenida.
- Este documento.

## 9. Plan de ejecución supervisada en `horus_group` (preparado, NO ejecutado)

Requiere autorización expresa del propietario para esta operación concreta. Decisiones vigentes: verificación de correo suspendida, **`000022` excluida**, avisos antiguos retenidos sin liberación, Resend desactivado, frontend intocable, S7 suspendido.

### 9.1 Revisión final de las migraciones

| Orden | Migración | Depende de (aplicadas) | Efecto | Datos existentes |
| --- | --- | --- | --- | --- |
| 1 | `2026_10_27_000012_add_matriculas_exportar_eliminar_permissions` | `000006` (Spatie) | 2 permisos, solo a `admin` | `insertOrIgnore`: no quita ni cambia asignaciones |
| 2 | `2026_10_27_000013_add_matriculas_integrity_constraints` | `000002`, `000003` | Índice parcial único + 3 `CHECK` | No borra ni modifica filas; aborta sin cambios si hay incompatibles (medidos: 0) |
| 3 | `2026_10_28_000020_create_matricula_notifications_table` | `000003` | Tabla nueva, FK a `matriculas.id` (bigint) | No rellena avisos para las 15 matrículas existentes |
| 4 | `2026_10_28_000021_create_curso_publicaciones_table` | `000002`, `0001_..._000000` (users), `000006` | Tabla nueva + `cursos.publicar` a `admin` | `givePermissionTo` solo añade (`attach`); no publica nada |
| 5 | `2026_10_28_000023_hold_matricula_notifications_backlog` | **`000020`** | `pending` sin intentos → `held` | En `horus_group` la tabla estará vacía: sin efecto. Sin `000020` falla sin cambios (ensayado) |
| 6 | `2026_10_28_000024_add_aprobaciones_respaldo_permissions` | `000006` | 4 permisos con el reparto del seeder | `insertOrIgnore`; no resincroniza ni quita nada |
| — | `2026_10_28_000022_create_registros_pendientes_table` | — | **Excluida** (verificación suspendida) | Ninguna de las seis depende de ella |

Comprobaciones de esta revisión:

- **Compatibilidad con el esquema medido (§8.1):** existen todas las columnas que usan `000013` (`estado`, `deleted_at`, `monto_pagado numeric`, `cupos_* integer not null`, `precio numeric not null`). Las FKs nuevas apuntan a `bigint` (`cursos.id`, `matriculas.id`, `users.id`). `permissions` tiene único `(name, guard_name)` y `role_has_permissions` clave primaria `(permission_id, role_id)` (migración `000006`, aplicada), por lo que `on conflict do nothing` es efectivo.
- **Transacciones:** en PostgreSQL Laravel ejecuta cada migración dentro de su propia transacción (`supportsSchemaTransactions`). Un fallo revierte esa migración completa; las anteriores, ejecutadas por separado, permanecen.
- **Sin correos:** con `matricula-mail.enabled=false`, `configured()` es falso: el observer guarda `held/captured_while_disabled`, el comando programado no encola nada y el job no hace HTTP. Las migraciones no crean avisos ni jobs.
- **Ensayo en SQLite `:memory:`** sobre una réplica del estado medido (15 migraciones, 39 permisos, 15 matrículas, una asignación directa): `--pretend` por archivo sin cambios; `000023` antes de `000020` falla (`QueryException`) sin registrar nada; aplicación individual en 6 lotes; matrículas/cursos/estudiantes, roles de usuario, asignación directa y las 65 asignaciones previas intactas, +11 nuevas (2 + 1 a admin y 8 de `000024`); `000022` sin aplicar; alta de matrícula posterior → `held`, 0 jobs. SQLite **no** demuestra el DDL ni los bloqueos de PostgreSQL.
- **Limitación conocida de `--pretend` en PostgreSQL:** en `000021`, `Permission::findOrCreate()` necesita el id devuelto por `insert ... returning`, que `pretend` no produce; el ensayo se detiene con `Undefined array key 0`. No aplica nada, pero esa migración debe ensayarse sobre la copia restaurada (9.5 B).
- **Situación actual de la aplicación local contra `horus_group`:** mientras no exista `matricula_notifications`, cualquier alta de matrícula desde el panel falla y se revierte (S15-02), y Aprobaciones/Respaldo responden 403. No usar esas funciones hasta completar el plan.

**Bloqueos que detienen el plan: ninguno identificado.**

### 9.2 Reglas de la operación

- Nunca `php artisan migrate` sin `--path`, ni `migrate:fresh`, `migrate:refresh`, `migrate:rollback`, `db:wipe` ni seeders.
- No revertir automáticamente migraciones de permisos. La recuperación es la restauración del backup, con autorización.
- No escribir contraseñas en la línea de comandos ni en el registro: `pg_dump`/`psql` las piden interactivamente (`-W`).
- No imprimir `config:show database.connections.pgsql` completo (incluye la contraseña): consultar solo las claves de 9.3.
- Detenerse ante cualquier código de salida distinto de 0 o resultado inesperado.

Variables de PowerShell usadas abajo (ajustar la ruta del respaldo, **fuera del repositorio**):

```powershell
$PG = 'C:\Program Files\PostgreSQL\18\bin'
$PGUSER_OPERADOR = '<usuario autorizado>'          # no es secreto; la contraseña se pide aparte
$RESPALDOS = 'D:\respaldos-horus'                     # carpeta privada, fuera del repo
$TS = Get-Date -Format 'yyyyMMdd-HHmmss'
$DUMP = Join-Path $RESPALDOS "horus_group-$TS.dump"
$M = 'database/migrations'
```

### 9.3 Paso 1: identificar la base destino

```powershell
if ($env:HORUS_PG_TEST_PROFILE) { throw 'Perfil aislado activo: detener' }
if (Test-Path bootstrap/cache/config.php) { throw 'Configuración en caché: revisar antes de continuar' }
php artisan config:show database.default                      # esperado: pgsql
php artisan config:show database.connections.pgsql.host        # esperado: 127.0.0.1
php artisan config:show database.connections.pgsql.port        # esperado: 5432
php artisan config:show database.connections.pgsql.database    # esperado: horus_group
php artisan config:show database.connections.pgsql.url         # esperado: vacío
```

Confirmación en el servidor (solo lectura):

```powershell
& "$PG\psql.exe" -h 127.0.0.1 -p 5432 -U $PGUSER_OPERADOR -d horus_group -W -v ON_ERROR_STOP=1 -c "select current_database(), inet_server_port(), current_setting('server_version');"
```

Esperado: `horus_group | 5432 | 18.x`. Cualquier otro valor (por ejemplo `55432` o `horus_l2_test`) detiene la operación.

### 9.4 Paso 2: detener escrituras administrativas

1. Avisar a los usuarios del panel (3 cuentas) y acordar la ventana.
2. Detener workers (`queue:work`), la tarea programada de `schedule:run` y el Vite de desarrollo si está activo.
3. `php artisan down` (el sitio responde 503 hasta `php artisan up`).
4. Comprobar que no quedan otras sesiones en la base:

```sql
select count(*) as otras_sesiones from pg_stat_activity
where datname = 'horus_group' and pid <> pg_backend_pid();
```

Esperado: `0` (o solo sesiones identificadas como propias, p. ej. pgAdmin, que deben cerrarse).

### 9.5 Paso 3: backup recuperable y ensayo

**A. Crear y verificar el respaldo**

```powershell
New-Item -ItemType Directory -Force $RESPALDOS | Out-Null
& "$PG\pg_dump.exe" -h 127.0.0.1 -p 5432 -U $PGUSER_OPERADOR -W -d horus_group -Fc -f $DUMP
if ($LASTEXITCODE -ne 0) { throw 'pg_dump falló: detener' }
& "$PG\pg_restore.exe" --list $DUMP | Measure-Object -Line      # índice legible, > 0 líneas
Get-FileHash -Algorithm SHA256 $DUMP                             # anotar el hash en el registro
```

**B. Restauración de prueba y ensayo real (recomendado; requiere autorización para crear una base auxiliar en el mismo servidor)**

```powershell
& "$PG\createdb.exe" -h 127.0.0.1 -p 5432 -U $PGUSER_OPERADOR -W -T template0 horus_group_ensayo
& "$PG\pg_restore.exe" -h 127.0.0.1 -p 5432 -U $PGUSER_OPERADOR -W -d horus_group_ensayo --no-owner --exit-on-error $DUMP
```

Comparar conteos (9.6) entre `horus_group` y `horus_group_ensayo`: deben coincidir. Después, aplicar el paso 9.7 **contra la copia**, apuntando temporalmente la aplicación a ella en esa misma consola:

```powershell
$env:DB_DATABASE = 'horus_group_ensayo'
php artisan config:show database.connections.pgsql.database    # debe mostrar horus_group_ensayo
# ... ejecutar 9.7 y 9.8 contra la copia ...
Remove-Item Env:DB_DATABASE
php artisan config:show database.connections.pgsql.database    # debe volver a horus_group
```

Si el ensayo falla, detener el plan y documentarlo. Al terminar, eliminar la copia solo con autorización (`dropdb ... horus_group_ensayo`).

**C. Ensayo mínimo sin copia** (`--pretend`, no aplica nada; `000021` se detiene por la limitación descrita en 9.1):

```powershell
foreach ($f in '2026_10_27_000012_add_matriculas_exportar_eliminar_permissions','2026_10_27_000013_add_matriculas_integrity_constraints','2026_10_28_000020_create_matricula_notifications_table','2026_10_28_000021_create_curso_publicaciones_table','2026_10_28_000023_hold_matricula_notifications_backlog','2026_10_28_000024_add_aprobaciones_respaldo_permissions') {
  php artisan migrate --pretend --path="$M/$f.php"
}
php artisan migrate:status    # debe seguir mostrando 15 Ran y las pendientes sin cambios
```

### 9.6 Paso 4: conflictos de integridad e instantánea previa (solo lectura)

Ejecutar en `psql` dentro de una transacción de solo lectura. Solo devuelve conteos:

```sql
begin transaction read only;
select
  (select count(*) from (select 1 from matriculas where deleted_at is null and estado <> 'anulada'
     group by estudiante_id, curso_id having count(*) > 1) d)                                         as duplicados_vigentes,
  (select count(*) from cursos where not (cupos_maximos >= 0 and cupos_disponibles >= 0
     and cupos_disponibles <= cupos_maximos))                                                         as cupos_fuera_rango,
  (select count(*) from cursos where not (precio >= 0))                                               as precio_negativo,
  (select count(*) from matriculas where not (monto_pagado is null or monto_pagado >= 0))             as monto_negativo,
  (select count(*) from migrations)                                                                   as migraciones,
  (select count(*) from permissions)                                                                  as permisos,
  (select count(*) from role_has_permissions)                                                         as asignaciones_rol,
  (select count(*) from model_has_permissions)                                                        as asignaciones_directas,
  (select count(*) from model_has_roles)                                                              as roles_usuario,
  (select count(*) from matriculas)                                                                   as matriculas,
  (select count(*) from cursos)                                                                       as cursos,
  (select count(*) from estudiantes)                                                                  as estudiantes;
rollback;
```

Valores medidos el 28/10/2026: conflictos `0/0/0/0`; 15 migraciones; 39 permisos; 65 asignaciones por rol (39 + 18 + 8); 0 directas; 3 roles de usuario; 15 matrículas; 8 cursos; 10 estudiantes. **Cualquier conflicto distinto de 0 detiene el plan**: corregir datos es una decisión del propietario.

### 9.7 Paso 5: aplicar las seis migraciones, una por una

```powershell
$plan = @(
  '2026_10_27_000012_add_matriculas_exportar_eliminar_permissions',
  '2026_10_27_000013_add_matriculas_integrity_constraints',
  '2026_10_28_000020_create_matricula_notifications_table',
  '2026_10_28_000021_create_curso_publicaciones_table',
  '2026_10_28_000023_hold_matricula_notifications_backlog',
  '2026_10_28_000024_add_aprobaciones_respaldo_permissions'
)
foreach ($f in $plan) {
  php artisan migrate --force --path="$M/$f.php"
  if ($LASTEXITCODE -ne 0) { throw "Falló ${f}: detener, no continuar ni revertir" }
  php artisan migrate:status | Select-String $f              # debe aparecer como Ran
}
php artisan permission:cache-reset
```

Cada migración queda en su propio lote (previsto: lotes 4 a 9). Se recomienda ejecutarlas una a una a mano, revisando la salida antes de pasar a la siguiente, en lugar del bucle.

### 9.8 Paso 6: verificaciones posteriores

```sql
begin transaction read only;
select
  (select count(*) from migrations)                                                                   as migraciones,          -- 21
  (select count(*) from migrations where migration like '%000022%')                                   as m000022,              -- 0
  to_regclass('public.matricula_notifications') is not null                                           as t_notificaciones,     -- t
  to_regclass('public.curso_publicaciones') is not null                                               as t_publicaciones,      -- t
  to_regclass('public.registros_pendientes') is not null                                              as t_registros,          -- f
  (select count(*) from pg_indexes where indexname = 'matriculas_vigente_unica')                      as indice_vigente,       -- 1
  (select count(*) from pg_constraint where conname in ('cursos_cupos_rango','cursos_precio_no_negativo',
     'matriculas_monto_no_negativo') and convalidated)                                                as checks_validados,     -- 3
  (select count(*) from permissions)                                                                  as permisos,             -- 46
  (select count(*) from role_has_permissions)                                                         as asignaciones_rol,     -- 76
  (select count(*) from model_has_permissions)                                                        as asignaciones_directas,-- igual que antes (0)
  (select count(*) from model_has_roles)                                                              as roles_usuario,        -- igual que antes (3)
  (select count(*) from matricula_notifications)                                                      as avisos,               -- 0
  (select count(*) from curso_publicaciones)                                                          as fichas,               -- 0
  (select count(*) from matriculas)                                                                   as matriculas;           -- igual que antes (15)
select r.name as rol, count(*) as permisos from role_has_permissions rp join roles r on r.id = rp.role_id
group by r.name order by r.name;                                                                       -- admin 46, coordinador 21, secretaria 9
rollback;
```

Desde la aplicación:

```powershell
php artisan migrate:status | Select-String '000022'                  # Pending
php artisan config:show matricula-mail.enabled                        # false
php artisan config:show registro-verificacion.enabled                 # false
php artisan config:show registro-verificacion.mailer                  # null
php artisan config:show chatbot.public_catalog_enabled                # false
php artisan horus:matriculas-notificar --status                       # sin filas (o solo held)
php artisan up
```

Comprobación funcional sin escrituras: iniciar sesión como `admin` y abrir Aprobaciones y Respaldo (200, ya no 403). Crear una matrícula de prueba es una escritura en datos reales: solo con autorización expresa. Si se hace, el aviso debe quedar `held/captured_while_disabled` y la cola `jobs` en 0.

### 9.9 Paso 7: registro de resultados

Registrar únicamente: fecha y hora, operador, destino (`horus_group@127.0.0.1:5432`), versión del servidor, nombre y hash SHA-256 del respaldo (no su contenido ni su ubicación pública), conteos de 9.6 y 9.8, lotes asignados, códigos de salida y estado final de `000022`, Resend y verificación. No registrar contraseñas, cadenas de conexión completas, correos, nombres ni datos de estudiantes.

### 9.10 Si algo falla

| Situación | Acción |
| --- | --- |
| Falla una migración | Su transacción se revierte. Detener el plan, mantener `php artisan down`, registrar el mensaje sin datos sensibles. No ejecutar `migrate:rollback`. Con autorización, continuar tras corregir la causa o restaurar el respaldo |
| Falla una verificación posterior | Mantener mantenimiento; no corregir a mano permisos o tablas. Con autorización, restaurar: `pg_restore --clean --if-exists --no-owner --exit-on-error -d horus_group $DUMP`, y repetir 9.6 para comprobar que vuelven los valores previos |
| Aparecen avisos o jobs inesperados | No arrancar workers; `horus:matriculas-notificar --status`; con Resend apagado ningún aviso puede enviarse |
| Duda sobre el destino | No ejecutar nada; volver a 9.3 |

### 9.11 Acciones que requieren autorización explícita

1. La ventana de mantenimiento y `php artisan down` en el entorno que usa `horus_group`.
2. Crear el respaldo y guardarlo en la carpeta acordada.
3. Crear, restaurar y eliminar `horus_group_ensayo`.
4. Aplicar las seis migraciones en `horus_group`.
5. Cualquier restauración del respaldo sobre `horus_group`.
6. Cualquier matrícula de prueba en datos reales.

Siguen fuera de alcance sin nueva decisión: `000022` y la verificación de correo, la activación de Resend, la liberación de avisos retenidos, el frontend y S7.

## 10. Fase A: backup verificado y ensayo en PostgreSQL real (ejecutada)

28 de octubre de 2026, con autorización del propietario limitada a respaldo y ensayo. **`horus_group` no recibió escrituras.** Sin servicios detenidos, sin `.env` modificado, sin Resend, verificación, frontend, S7, commits ni push.

| Paso | Resultado |
| --- | --- |
| Identidad | `127.0.0.1:5432`, PostgreSQL 18.6, `data_directory = C:/Program Files/PostgreSQL/18/data` (no es S7: `C:/pgdata/horus_l2_test`, `55432`). Bases: `horus_group`, `postgres`. `horus_group_ensayo` no existía |
| Actividad previa | 0 sesiones adicionales y 0 transacciones de escritura abiertas en `horus_group` |
| Backup | `pg_dump -Fc` (instantánea transaccional consistente sin detener servicios), 65 093 bytes, SHA-256 `72D0CBD9A4B746A4829DAA52907A896E9E9FE6540AB45537E8DBA5BB3B571276`; índice legible (170 entradas: 24 tablas con datos, 33 restricciones, 9 índices, 12 FK, extensión `uuid-ossp`). Guardado en `%USERPROFILE%\respaldos-horus\fase-a\` sin herencia de permisos (solo el usuario, SYSTEM y Administradores) |
| Base auxiliar | `horus_group_ensayo` creada desde la base de mantenimiento `postgres`, `template0`, UTF8, `Spanish_Spain.1252` (igual que el origen) |
| Restauración | `pg_restore --no-owner --exit-on-error --single-transaction`: código 0, sin avisos. **Idéntica** al origen previo: conteos, permisos por rol, migraciones, tablas (24), índices (42), restricciones (148) y huella MD5 del contenido de 10 tablas |
| `--pretend` | Correcto en `000012`, `000013`, `000020`, `000023`, `000024`; `000021` se detiene con `Undefined array key 0` (limitación prevista en 9.1); 0 cambios |
| Migraciones | Las seis, una a una con `--path`, contra la conexión `pgsql` redirigida explícitamente a `horus_group_ensayo` en el propio proceso y comprobada en el servidor antes de cada una: lotes 4–9, todas `DONE` |
| Datos | Cursos 8, estudiantes 10, matrículas 15, usuarios 3, roles de usuario 3: iguales antes y después, con huellas de contenido idénticas |
| Permisos | 39 → 46; asignaciones 65 → 76; las 65 previas conservadas; 11 nuevas exactamente las previstas; admin 46, coordinador 21, secretaria 9, **idénticas a la matriz del seeder**; 0 asignaciones directas antes y después |
| Restricciones e índices | 3 `CHECK` creados y validados; `matriculas_vigente_unica` parcial (`deleted_at is null and estado <> 'anulada'`); únicos nuevos en `matricula_notifications` (`matricula_id`, `delivery_key`) y `curso_publicaciones` (`curso_id`); FKs `cascade`, `restrict`, `set null` según lo diseñado |
| Notificaciones y publicación | 0 avisos, 0 jobs, 0 fichas editoriales: las migraciones no generan avisos ni publican cursos |
| `000022` | No registrada; `migrate:status` la muestra `Pending`; `registros_pendientes` no existe |
| Configuración | `matricula-mail.enabled=false`, `configured()=false`, verificación `false/null`, catálogo público `false` |
| `horus_group` después | Instantánea idéntica a la previa (incluidas huellas); 15 migraciones, 39 permisos, sin tablas nuevas; contador de escrituras de `pg_stat_user_tables` 152 → 152 |

Evidencias (solo conteos, metadatos y huellas; sin filas ni datos personales) y scripts usados: `%USERPROFILE%\respaldos-horus\fase-a\evidencias\`.

**Incidencias y limitaciones**

- Dos fallos de los propios scripts, sin efecto en las bases: la guarda de directorio comparaba con barras invertidas (abortó antes del backup) y una consulta de verificación necesitó una conversión de tipo.
- El usuario de base de datos de la aplicación es **superusuario** de PostgreSQL. Funciona, pero excede el mínimo privilegio; conviene un rol propio de aplicación en el futuro (decisión operativa).
- El backup y la base auxiliar contienen datos personales y tablas `sessions`/`cache`. Están protegidos por permisos de carpeta y por las credenciales del servidor, pero el archivo no está cifrado.
- El ensayo usó caché en memoria. En la Fase B la caché es `database`: las migraciones de permisos ya invalidan la caché de Spatie, y el plan añade `permission:cache-reset`.
- El ensayo no mide concurrencia ni bloqueos con usuarios activos; cada migración duró entre 3 y 35 ms con este volumen. La Fase B sigue requiriendo ventana de mantenimiento.
- El nombre del archivo usa hora UTC (`20:58`); la cabecera del dump muestra la hora del servidor (`15:58`, UTC-5).
- `horus_group_ensayo` y el backup **se conservan**; eliminarlos requiere autorización.

**Recomendación:** el plan funciona sobre una copia exacta en PostgreSQL 18.6 real. Es seguro solicitar autorización para la Fase B (§9), con un backup nuevo tomado en la propia ventana de mantenimiento.
