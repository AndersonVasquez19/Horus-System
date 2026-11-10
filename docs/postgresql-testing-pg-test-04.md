# PG-TEST-04 — endurecimiento de identidad, auditoría de migraciones y matriz de pruebas PostgreSQL

> **Estado vigente al 30/10/2026:** este documento conserva los informes históricos. Consultar la [consolidación final](#13-consolidación-del-30102026) antes de cualquier operación. Las menciones antiguas de 27 migraciones y de diez etapas pertenecen al plan inicial: hay **26 archivos de migración** y la selección vigente de etapa 1 contiene **116 pruebas**, separada de las etapas 2–7. Las etapas 1 (116 / 525) y 2 (48 / 410) están ejecutadas y aprobadas (acumulado 164 pruebas / 935 aserciones; ver [etapa 2 ejecutada](#etapa-2-ejecutada-stage2-ronda1-30102026)); siguen pendientes las etapas 3–7, la concurrencia, la suite completa y la restauración. No ejecutar los ejemplos antiguos de consentimiento, `check` o migraciones como parte de una revisión de solo lectura.

29/10/2026. Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`. **Fase de endurecimiento y preparación: sin ejecución PostgreSQL.** No se ejecutaron migraciones, `check`, `migrate:fresh` ni pruebas con escrituras; el consentimiento destructivo sigue vacío; no hubo conexiones a 5432, 55432, `horus_group` ni `horus_group_ensayo`; HBA, contraseñas, servicios, Resend, verificación de correo, Advisor y frontend sin cambios. Sin commits ni push.

Contexto: clúster independiente `127.0.0.1:55433`, PostgreSQL 18.6, `system_identifier` `7702263743609677164`, bases `horus_l2_test` y `horus_l2_restore_test` ([informe del clúster](postgresql-testing-cluster-independiente.md), §15–§16).

## 1. Identidad del clúster en todas las rutas con escritura

### Hallazgo

Tras PG-TEST-03 solo `probe` comprobaba `system_identifier` y `cluster_name`. El camino por defecto de `GuardedPostgresConnector` (usado por `check`, `test`, `artisan`, `RefreshDatabase`/`DatabaseTruncation` y `ConcurrentTestSession`) habilitaba escrituras tras la sonda de marcador y privilegios, sin verificar el clúster físico. Otro clúster con la misma dirección, puerto, nombres de base y rol y un marcador equivalente habría sido aceptado.

Rutas auditadas que pueden habilitar escrituras: todas pasan por `db.connector.pgsql` = `GuardedPostgresConnector` (instalado por `TestDatabaseSafety` en el perfil; `ConcurrentTestSession` exige ese conector y la fábrica protegida; cualquier otra conexión o reconexión la rechaza el listener `ConnectionEstablished`). Por tanto el único punto donde se ejecuta `SET default_transaction_read_only = off` es el conector.

### Cambio

`GuardedPostgresConnector::connect()` verifica ahora, **en todos los modos y antes de cualquier escritura**, con la sesión aún en solo lectura:

1. Configuración exacta (sin cambios: host `127.0.0.1`, puerto `55433`, lista cerrada de bases/runners, rechazo explícito de 55432, sin `DB_URL`/`PG*`/conexiones secundarias).
2. Sonda de identidad existente: base, rol, dirección, puerto, `search_path`, `TimeZone`, aislamiento, marcador, privilegios mínimos, sin acceso a otras bases, marcador no escribible.
3. **Nuevo para la ruta con escritura:** `cluster_name = horus_pgt_55433`, `system_identifier = 7702263743609677164`, `transaction_read_only = on`, `default_transaction_read_only = on`.
4. Solo entonces: `probe` devuelve la sesión de solo lectura; el resto ejecuta `SET default_transaction_read_only = off`.

Si la lectura de `pg_control_system()` falla, se rechaza con `cluster identity probe failed; no writes authorized.` (falla cerrado, sin excepción previa ni detalles del driver). El identificador y el nombre esperados son constantes de código (`PostgresTestGuard::SYSTEM_IDENTIFIER`, `CLUSTER_NAME`): ninguna variable de entorno, `.env` o configuración los sustituye. Recrear el clúster exige actualizarlos con revisión.

### Pruebas nuevas (dobles, sin sockets)

| Prueba | Demuestra |
| --- | --- |
| `test_exact_probed_pdo_is_returned_and_only_then_write_mode_is_enabled` (adaptada) | Orden obligatorio: sonda de marcador → identidad del clúster → `SET ... = off` |
| `test_write_path_refuses_any_other_cluster_before_enabling_writes` (8 casos) | Identificador de S7, del primer clúster descartado, arbitrario o ausente; `cluster_name` de S7 o vacío; sesión o predeterminado ya escribibles: rechazo y `exec` nunca llamado |
| `test_write_path_fails_closed_when_the_identifier_cannot_be_read` | `permission denied` → rechazo sin escritura, sin excepción previa ni secretos |
| `test_environment_cannot_override_the_expected_cluster_identity` | Variables como `HORUS_PG_TEST_SYSTEM_IDENTIFIER` no cambian la expectativa |
| `test_second_session_on_another_cluster_never_enables_writes` | `ConcurrentTestSession` con otro clúster: rechazo, sin escritura |

Se mantienen todas las pruebas previas: rechazos de 55432/5432/host/base/rol/perfil/consentimiento, marcador, privilegios y `probe`.

## 2. Matriz de migraciones 000022–000028

Notas comunes para PostgreSQL 18 (comprobadas contra el código de Laravel 12 instalado y las migraciones): `enum()` se crea como `varchar` + `CHECK`; `uuid()` es tipo nativo `uuid`; `unsignedInteger/unsignedBigInteger` son `integer/bigint` **sin** restricción de no negatividad (solo hay `CHECK` explícitos donde se indican); `timestampTz` es `timestamp(0) with time zone`; las migraciones se ejecutan dentro de una transacción (DDL transaccional en PostgreSQL). Ninguna de estas migraciones crea tipos, extensiones, funciones ni objetos fuera de `public`.

| Migración | Crea / modifica | Índices y restricciones | FK | Dependencias | Destructiva / reversible | Efectos secundarios | Pruebas que dependen |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **000022** `create_registros_pendientes_table` | Tabla `registros_pendientes`: `id`, `email_hash` varchar(64), `email` text, `token_hash` varchar(64) null, `expires_at`, `requested_at`, `consumed_at`, timestamps | UNIQUE `email_hash`, UNIQUE `token_hash`, índice `expires_at` | Ninguna | Ninguna | No destructiva. `down()` hace `dropIfExists` (pierde filas) | Ninguno por sí misma (ver §3) | `Sprint14EmailVerificationTest` (incluye `down()`+`up()` dentro de la prueba) |
| **000023** `hold_matricula_notifications_backlog` | `UPDATE matricula_notifications` de `pending` con 0 intentos → `held` (`captured_before_policy`), `payload`/`dispatched_until` a null | — | — | **000020** (`matricula_notifications`) | Modifica datos; **irreversible a propósito** (`down()` vacío). En base nueva afecta a 0 filas | No envía ni libera avisos | `MatriculaNotificationBacklogTest` (vuelve a ejecutar `up()`) |
| **000024** `add_aprobaciones_respaldo_permissions` | Inserta 4 permisos y asignaciones a `admin/coordinador/secretaria` según el seeder | `insertOrIgnore` → `ON CONFLICT DO NOTHING` sobre las unicidades de Spatie | — | **000006** (tablas Spatie); roles existentes (si faltan, solo crea permisos) | Aditiva, idempotente; `down()` vacío a propósito | Limpia la caché de permisos | `AprobacionesRespaldoPermissionsMigrationTest` |
| **000025** `create_servicios_tecnologicos_tables` | `servicios_tecnologicos` (uuid público, código, nombre, categoría, estado, revisión) y `servicio_publicaciones` (título, resumen, descripción, categoría, estado, vigencias, autorización) | UNIQUE `identificador_publico`, `codigo`, `servicio_tecnologico_id`; índices `(estado,id)` y `servicio_publicaciones_vigencia`; CHECK de enums; en pgsql `servicios_revision_positiva`, `servicios_vigencia_ordenada`, `servicios_autorizacion_fecha` (en SQLite son triggers) | `servicio_tecnologico_id` → servicios **RESTRICT**; `autorizado_por` → users **SET NULL** | **0001_01_01_000000** (`users`) | No destructiva. `down()` borra ambas tablas | Ninguno; el catálogo público sigue apagado (`catalogo-servicios.enabled=false`) | `ServicioTecnologicoTest`, `PanelServiciosTest`, `CatalogoWebServiciosTest` |
| **000026** `add_servicios_permissions` | `Permission::findOrCreate` de `servicios.ver/crear/editar/publicar` y concesión a `admin` | Unicidad Spatie | — | **000006**, constante `RolesAndPermissionsSeeder::SERVICIOS_PERMISSIONS`; rol `admin` opcional (`?->`) | Aditiva; `down()` vacío | Limpia la caché de permisos | `ServicioTecnologicoTest` |
| **000027** `create_solicitudes_cotizacion_tables` | `solicitudes_cotizacion` (uuid, código, `recibida_at` tz, servicio y snapshot, necesidad, contacto, estado, responsable, creador, seguimientos, revisión, ámbito/clave/huella) y `cotizacion_historial` (revisión, evento, actor, metadatos json, nota, ámbito, clave, huella) | UNIQUE `identificador`, `codigo`, `cotizaciones_creacion_unica`, `cotizacion_historial_revision_unica`, `cotizacion_seguimiento_unico`; 7 índices en solicitudes y `actor_id` en historial; CHECK de enums; en pgsql `cotizacion_revision_positiva`, `cotizacion_contacto_minimo`, `cotizacion_servicio_snapshot`, `cotizacion_historial_revision_positiva` | `servicio_id` → servicios **RESTRICT**; `responsable_id`, `creado_por`, `actor_id` → users **SET NULL**; `solicitud_id` → solicitudes **RESTRICT** | **000025** y `users` | No destructiva. `down()` borra ambas tablas | Ninguno; no hay recepción pública | `CotizacionTest`, `PanelCotizacionesTest` |
| **000028** `add_cotizaciones_permissions` | `cotizaciones.ver/crear/editar/gestionar` y concesión a `admin` | Unicidad Spatie | — | **000006**, `RolesAndPermissionsSeeder::COTIZACIONES_PERMISSIONS`; `admin` opcional | Aditiva; `down()` vacío | Limpia la caché de permisos | `CotizacionTest` |

Observaciones de compatibilidad PostgreSQL que solo se confirmarán ejecutando:

- Una unicidad compuesta con columna nula (`cotizacion_seguimiento_unico` con `clave_hash` null) admite varias filas con null en PostgreSQL, igual que en SQLite; lo exigen los eventos sin clave del historial.
- `cotizacion_contacto_minimo` usa `length(trim(...))`, válido en ambos motores.
- Los nombres de restricciones `CHECK` son explícitos y únicos por tabla.

## 3. 000022 en la base desechable: demostración

Pregunta: ¿aplicar `000022` en `horus_l2_test` permite probar el esquema sin activar la verificación de correo?

Evidencia en código:

1. La migración solo crea una tabla vacía, sin FK, datos, triggers ni permisos.
2. La única lectura/escritura de `registros_pendientes` está en `VerificacionRegistroService` (modelo `RegistroPendiente`). Sus métodos `solicitar()` y `confirmar()` salen de inmediato si `! self::enabled()`.
3. `VerificacionRegistroService::enabled()` es `config('registro-verificacion.enabled') === true`, y `config/registro-verificacion.php` fija `'enabled' => false` y `'mailer' => null` como literales (no leen `.env`).
4. Todos los consumidores (`Admin\AuthController`, `Api\AuthController`, `ResetPasswordController`, `DescartarSesionSinVerificar`, `VerificacionRegistroController`) deciden por `VerificacionRegistroService::enabled()`. **Ningún código comprueba `Schema::hasTable('registros_pendientes')`**: los únicos `hasTable` del proyecto son para catálogos de cursos/servicios y respaldo.
5. `Sprint14EmailVerificationTest` activa la funcionalidad solo dentro de cada prueba con `config([...enabled => true, mailer => 'array'])`; el perfil fuerza `MAIL_MAILER=array` y prohíbe `RESEND_API_KEY`/`POSTMARK_API_KEY`. No hay envío real.

Conclusión: **sí**. En `horus_l2_test` la tabla existe pero la funcionalidad no se activa: la configuración, las rutas y el mailer no cambian. La suspensión de `000022` sigue vigente para `horus_group` y cualquier base real; esta conclusión no la autoriza fuera de la base desechable. Límite: es una demostración por lectura de código, no una ejecución.

## 4. Pruebas que dependen de SQLite o necesitan adaptación

| Prueba | Dependencia | Adaptación necesaria antes de PG-TEST-05 |
| --- | --- | --- |
| `CotizacionTest`, `ServicioTecnologicoTest`, `CatalogoWebServiciosTest` | `setUp()` ejecuta `assertIsolatedTestDatabase()` **y además** `assertSame('sqlite', DB::getDriverName())` | **Resuelto en PG-TEST-05A (§9)**, junto con los savepoints de las escrituras que deben fallar |
| `TestDatabaseSafetyTest`, `ConcurrentTestSessionTest`, `PromoverAdminLocalTest` | SQLite/dobles por diseño | Ya excluidas en `phpunit.pgsql.xml` |
| `PostgresTestGuardTest`, `ReadOnlyProbeTest` | Dobles puros, sin base | Se incluirían en la suite `PostgreSQL isolated` (`tests/Unit`): no abren sockets; neutrales. Valorar excluirlas por claridad |
| `BackendPerformanceTest`, `CatalogoWebCursosTest`, `CatalogoWebServiciosTest`, `CotizacionTest`, `PanelServiciosTest`, `ServicioTecnologicoTest` | Conteos de consultas | PostgreSQL puede añadir consultas (introspección, savepoints): hallazgo esperable, no relajar |
| `AdminSearchTest`, `AdminListFilterInputTest`, `SecretsAndExportsTest`, `ServicioTecnologicoTest` | `LIKE`/orden | `LIKE` distingue mayúsculas en PostgreSQL; colación `Spanish_Spain.1252` puede cambiar órdenes |
| `CatalogoWebCursosTest`, `CatalogoWebServiciosTest`, `ServicioTecnologicoTest`, `Sprint14EmailVerificationTest`, `MatriculaNotificationBacklogTest`, `AprobacionesRespaldoPermissionsMigrationTest`, `MatriculaIntegrityConstraintsTest`, `MatriculaSensitivePermissionsTest`, `CursoPublicacionTest`, `ReportExcelExportTest` | DDL dentro de la prueba (`Schema::drop`, `down()/up()`) | DDL transaccional en PostgreSQL; el `ROLLBACK` de `RefreshDatabase` lo revierte. Verificar al ejecutar. Excepción: `ReportExcelExportTest` usa `DatabaseMigrations` desde la corrección del §12; su `Schema::drop('audit_logs')` lo deshace el `migrate:rollback` del final de la prueba (`down()` usa `dropIfExists`) |
| `tests/PostgresIntegrity/*` (14 pruebas) | Solo PostgreSQL; se omiten fuera del perfil | Ninguna |
| `tests/benchmarks/BackendQueriesBenchmarkTest.php` | Fuera de las suites de `phpunit.pgsql.xml` | No se ejecuta |

Límites del conector a tener en cuenta: `statement_timeout = 5000 ms` y `lock_timeout = 2000 ms` se aplican a todas las sesiones de prueba (configuración del conector protegido). Pruebas largas o con esperas de bloqueo pueden fallar con 57014/55P03; eso es un hallazgo a analizar, no un motivo para ampliarlos.

## 5. Matriz de ejecución por etapas (para PG-TEST-05)

Todas sobre `horus_l2_test` con `horus_l2_test_runner`. `horus_l2_restore_test` queda **reservada** para escenarios de restauración: no se usa en estas etapas y el perfil no apunta a ella. Cada etapa con `--stop-on-failure`; ante el primer fallo, detenerse, clasificar y no reintentar.

| Etapa | Objetivo | Comando previsto | Pruebas |
| --- | --- | --- | --- |
| 1 | Migraciones completas | `php scripts/pgsql-test.php artisan migrate:status`, luego `artisan migrate`, luego `artisan migrate:status` | Las 27 migraciones, incluida `000022` (solo en esta base) |
| 2 | Índices y restricciones | `test --filter="MatriculaConstraintsPostgresTest\|MatriculaIntegrityConstraintsTest\|Migration\|Permission" --stop-on-failure` | Restricciones 23505/23514, migraciones de permisos |
| 3 | Estudiantes y cursos | `test --filter="AdminEstudianteTest\|AdminCursoTest\|CursoPublicacionTest" --stop-on-failure` | CRUD, borrado lógico, publicación editorial |
| 4 | Matrículas, cupos y duplicados | `test --filter="AdminMatriculaTest\|MatriculaOperationsTest\|MatriculaRulesPostgresTest\|MatriculaSensitivePermissionsTest" --stop-on-failure` | Reglas, cupos, duplicados, permisos sensibles |
| 5 | Transacciones y rollback | `test --filter="Sprint12AtomicityTest\|BackendRobustnessTest" --stop-on-failure` | Atomicidad y reversión |
| 6 | Notificaciones y retenidos | `test --filter="MatriculaNotificationTest\|MatriculaNotificationBacklogTest" --stop-on-failure` | Bandeja, `held`, `000023` |
| 7 | Servicios y publicación | `test --filter="ServicioTecnologicoTest\|PanelServiciosTest\|CatalogoWebServiciosTest" --stop-on-failure` | Requiere la adaptación del §4 |
| 8 | Cotizaciones, permisos, historial | `test --filter="CotizacionTest\|PanelCotizacionesTest" --stop-on-failure` | Requiere la adaptación del §4 |
| 9 | Concurrencia | `test --filter=ConcurrencyPostgresTest --stop-on-failure` | Segunda sesión real con `ConcurrentTestSession` |
| 10 | Suite completa | `php scripts/pgsql-test.php test` | Suites `PostgreSQL isolated` y `PostgreSQL integrity` |

Evidencia por etapa (fuera de Git, en `%LOCALAPPDATA%\HorusPgTest55433\evidencia\pgt05\`): comando, hora, número de pruebas/aserciones, fallos con clase y mensaje sin datos ni SQL con valores, duración. Al terminar: vaciar el consentimiento y ejecutar `probe`.

## 6. Riesgos pendientes

| Riesgo | Tratamiento |
| --- | --- |
| Las tres pruebas con `assertSame('sqlite')` bloquean las etapas 7, 8 y 10 | Adaptación mínima autorizada al inicio de PG-TEST-05 |
| Conteos de consultas, `LIKE`, colación y SQLSTATE pueden diferir | Son hallazgos; analizar sin relajar aserciones |
| `statement_timeout`/`lock_timeout` del conector | No ampliarlos; registrar 57014/55P03 si aparecen |
| `RefreshDatabase` aplica todas las migraciones, incluida `000022` | Demostrado inocuo en la base desechable (§3); requiere aceptación expresa en PG-TEST-05 |
| `migrate:fresh` borra todo `public` del runner | Esperado y acotado: el marcador está en `horus_test_control` (de `horus_pgt_owner`) y el runner no tiene privilegios allí |
| Cambio de clúster | `SYSTEM_IDENTIFIER` debe actualizarse con revisión; mientras no, todas las rutas rechazan |
| El servidor es un proceso de usuario | Comprobar `pg_isready` y `probe` antes de cada etapa |
| Resultados PostgreSQL no equivalen a producción | `horus_group`, Fase B y `000022` en bases reales siguen fuera de alcance |

## 7. Procedimiento exacto de PG-TEST-05 (requiere autorización independiente)

Consola PowerShell **no elevada**, en la raíz del repositorio. No incluye secretos.

```powershell
# 0. Comprobaciones previas (solo lectura)
$bin='C:\Program Files\PostgreSQL\18\bin'; $ev=Join-Path $env:LOCALAPPDATA 'HorusPgTest55433\evidencia\pgt05'
New-Item -ItemType Directory -Force $ev | Out-Null
& "$bin\pg_isready.exe" -h 127.0.0.1 -p 55433
php scripts/pgsql-test.php probe            # debe terminar con exit 0 e identificador 7702263743609677164
git status --short; git stash list

# 0.b Adaptación de pruebas: ya realizada en PG-TEST-05A (§9). Confirmar solo que la suite SQLite sigue verde.
php artisan test --compact

# 1. Activar el consentimiento (valor no secreto) solo en el perfil local
(Get-Content .env.pgsql-testing.local) -replace '^HORUS_PG_TEST_CONFIRM=$','HORUS_PG_TEST_CONFIRM=AUTHORIZE_HORUS_L2_DESTRUCTIVE_TESTS' | Set-Content .env.pgsql-testing.local -Encoding ascii
php scripts/pgsql-test.php check            # sonda + identidad de clúster; habilita escritura y sale sin DDL/DML

# 2. Etapa 1: migraciones
php scripts/pgsql-test.php artisan migrate:status *> "$ev\01-status-antes.txt"
php scripts/pgsql-test.php artisan migrate         *> "$ev\01-migrate.txt"
php scripts/pgsql-test.php artisan migrate:status *> "$ev\01-status-despues.txt"

# 3. Etapas 2-10 (detenerse ante el primer fallo)
php scripts/pgsql-test.php test --filter="MatriculaConstraintsPostgresTest|MatriculaIntegrityConstraintsTest|Migration|Permission" --stop-on-failure *> "$ev\02.txt"
php scripts/pgsql-test.php test --filter="AdminEstudianteTest|AdminCursoTest|CursoPublicacionTest" --stop-on-failure *> "$ev\03.txt"
php scripts/pgsql-test.php test --filter="AdminMatriculaTest|MatriculaOperationsTest|MatriculaRulesPostgresTest|MatriculaSensitivePermissionsTest" --stop-on-failure *> "$ev\04.txt"
php scripts/pgsql-test.php test --filter="Sprint12AtomicityTest|BackendRobustnessTest" --stop-on-failure *> "$ev\05.txt"
php scripts/pgsql-test.php test --filter="MatriculaNotificationTest|MatriculaNotificationBacklogTest" --stop-on-failure *> "$ev\06.txt"
php scripts/pgsql-test.php test --filter="ServicioTecnologicoTest|PanelServiciosTest|CatalogoWebServiciosTest" --stop-on-failure *> "$ev\07.txt"
php scripts/pgsql-test.php test --filter="CotizacionTest|PanelCotizacionesTest" --stop-on-failure *> "$ev\08.txt"
php scripts/pgsql-test.php test --filter=ConcurrencyPostgresTest --stop-on-failure *> "$ev\09.txt"
php scripts/pgsql-test.php test *> "$ev\10.txt"

# 4. Cierre: retirar el consentimiento y verificar solo lectura
(Get-Content .env.pgsql-testing.local) -replace '^HORUS_PG_TEST_CONFIRM=.*$','HORUS_PG_TEST_CONFIRM=' | Set-Content .env.pgsql-testing.local -Encoding ascii
php scripts/pgsql-test.php probe
git status --short; git stash list
```

Notas: al reescribir `.env.pgsql-testing.local` con `Set-Content` se conserva su ACL (el archivo ya existe). `check`, `test` y `artisan` exigen ya la identidad del clúster antes de escribir. Cualquier fallo de las etapas detiene el procedimiento: no se reintenta, no se amplían tiempos ni se relajan guardas.

## 8. Validación de esta fase (SQLite y dobles)

| Comprobación | Resultado |
| --- | --- |
| `ReadOnlyProbeTest`, `PostgresTestGuardTest`, `TestDatabaseSafetyTest`, `ConcurrentTestSessionTest` | **166 aprobadas, 556 aserciones** (antes 155/512; 11 pruebas nuevas) |
| Suite SQLite completa | **1136 aprobadas, 7637 aserciones**, 125,14 s |
| Larastan nivel 6 | Sin errores |
| Pint `--test` sobre los 5 PHP tocados | Aprobado |
| `git diff --check` | Aprobado |

Ninguna de estas comprobaciones abre sockets PostgreSQL. No acreditan el comportamiento de las migraciones ni de las pruebas en PostgreSQL 18: eso es PG-TEST-05.

### Archivos de esta fase

| Archivo | Cambio |
| --- | --- |
| `app/Support/Testing/GuardedPostgresConnector.php` | Identidad del clúster obligatoria en todos los modos antes de `SET default_transaction_read_only = off`; mensaje de fallo `no writes authorized` |
| `tests/Support/PostgresSafetyFixtures.php` | La sonda simulada incluye identidad de clúster (`safeClusterIdentity()`) |
| `tests/Unit/PostgresTestGuardTest.php` | Prueba de orden adaptada y 10 casos nuevos |
| `tests/Unit/ConcurrentTestSessionTest.php` | 1 prueba nueva (segunda sesión sobre otro clúster) |
| `tests/Unit/ReadOnlyProbeTest.php` | Expectativas alineadas (dos consultas en la ruta por defecto, mensaje común) |
| Este documento, el informe del clúster (§17), `PROJECT_CONTEXT.md` y el índice de `CLAUDE.md` | Documentación |

## 9. PG-TEST-05A — adaptación de tres pruebas (29/10/2026, autorizada)

Alcance autorizado: solo compatibilizar `CotizacionTest`, `ServicioTecnologicoTest` y `CatalogoWebServiciosTest` con PostgreSQL Testing. Sin consentimiento destructivo, migraciones, pruebas PostgreSQL ni cambios de credenciales, HBA, servicios o clúster.

### Dependencias reales de SQLite encontradas

1. **Aserción del driver en `setUp()`** (las tres): `assertSame('sqlite', DB::getDriverName())` tras `assertIsolatedTestDatabase()`. Es redundante: fuera del perfil `assertIsolatedTestDatabase()` ya exige driver `sqlite` y base `:memory:`; dentro exige `GuardedPostgresConnection` sobre una base de `PostgresTestGuard::TARGETS`.
2. **Escrituras que deben fallar dentro de la transacción de `RefreshDatabase`** (`CotizacionTest` y `ServicioTecnologicoTest`). SQLite permite seguir operando tras una violación; PostgreSQL aborta la transacción (`25P02`). Sin aislamiento:
   - en los bucles de restricciones, el segundo caso y siguientes «fallarían» por la transacción abortada y no por su restricción: un falso positivo que oculta qué se comprueba;
   - en `test_servicio_historico_...` el `assertDatabaseCount` posterior al `delete()` rechazado por la FK fallaría.

### Cambios (solo pruebas)

| Archivo | Cambio |
| --- | --- |
| `tests/Feature/CotizacionTest.php` | Retirada la aserción redundante del driver (se mantiene `assertIsolatedTestDatabase()`); `DB::transaction(...)` (savepoint) en el `delete()` protegido por la FK y en cada caso de `test_restricciones_sql_de_esquema` |
| `tests/Feature/ServicioTecnologicoTest.php` | Retirada la aserción redundante; savepoint en cada caso de `test_restricciones_sql_protegen_identidad_duplicados_y_fechas` |
| `tests/Feature/CatalogoWebServiciosTest.php` | Retirada la aserción redundante |

No se eliminó ninguna comprobación de aislamiento ni funcional: cada caso sigue exigiendo `QueryException` y `fail()` si la escritura prospera; los conteos posteriores se conservan. En SQLite un savepoint alrededor de una sentencia rechazada no cambia el resultado.

### Validación (solo SQLite)

| Comprobación | Resultado |
| --- | --- |
| Las tres clases | **102 aprobadas, 1161 aserciones** |
| Suite completa | **1136 aprobadas, 7535 aserciones**, 132,44 s. Respecto a PG-TEST-04 (1136/7637) faltan exactamente **102 aserciones**: una por prueba de las tres clases, la aserción redundante del driver. Ninguna otra aserción cambió |
| Larastan nivel 6 | Sin errores |
| Pint `--test` sobre los tres archivos | Aprobado (LF) |
| `git diff --check` | Aprobado |

### Riesgos que solo PostgreSQL resolverá

- `CatalogoWebServiciosTest::test_columnas_incompletas_fallan_sin_registrar_sql`: la aplicación captura la `QueryException` de la columna eliminada; en PostgreSQL la transacción de la prueba queda abortada. La prueba no consulta después, pero si la petición ejecuta otra consulta tras capturar el error, fallará con `25P02`. Es un artefacto de la transacción de prueba, no del flujo real; no se modificó código de aplicación.
- Conteos de consultas (`test_paginacion_sql_orden_estable_consultas_constantes_y_limite`, lecturas sin escrituras con `^select`), textos de SQL (`limit 2 offset 2`, `"p"."titulo"`), zona horaria con `travelTo` en Lima frente a `timestampTz`, y orden por colación: pendientes de observar en PG-TEST-05B.

## 10. Procedimiento exacto de PG-TEST-05B (requiere autorización independiente)

Es el procedimiento del §7 a partir del paso 1 (la adaptación 0.b ya está hecha). Consola PowerShell **no elevada** en la raíz del repositorio:

```powershell
# 0. Previo (solo lectura)
$bin='C:\Program Files\PostgreSQL\18\bin'; $ev=Join-Path $env:LOCALAPPDATA 'HorusPgTest55433\evidencia\pgt05'
New-Item -ItemType Directory -Force $ev | Out-Null
& "$bin\pg_isready.exe" -h 127.0.0.1 -p 55433
php scripts/pgsql-test.php probe          # exit 0, identificador 7702263743609677164
git status --short; git stash list

# 1. Consentimiento (valor no secreto, solo en el perfil local; conserva su ACL)
(Get-Content .env.pgsql-testing.local) -replace '^HORUS_PG_TEST_CONFIRM=$','HORUS_PG_TEST_CONFIRM=AUTHORIZE_HORUS_L2_DESTRUCTIVE_TESTS' | Set-Content .env.pgsql-testing.local -Encoding ascii
php scripts/pgsql-test.php check          # identidad de clúster + sonda; sale sin DDL/DML

# 2. Etapa 1: migraciones completas en horus_l2_test (incluye 000022 solo en esta base)
php scripts/pgsql-test.php artisan migrate:status *> "$ev\01-status-antes.txt"
php scripts/pgsql-test.php artisan migrate         *> "$ev\01-migrate.txt"
php scripts/pgsql-test.php artisan migrate:status *> "$ev\01-status-despues.txt"

# 3. Etapas 2-10 (detenerse ante el primer fallo; no reintentar)
php scripts/pgsql-test.php test --filter="MatriculaConstraintsPostgresTest|MatriculaIntegrityConstraintsTest|Migration|Permission" --stop-on-failure *> "$ev\02.txt"
php scripts/pgsql-test.php test --filter="AdminEstudianteTest|AdminCursoTest|CursoPublicacionTest" --stop-on-failure *> "$ev\03.txt"
php scripts/pgsql-test.php test --filter="AdminMatriculaTest|MatriculaOperationsTest|MatriculaRulesPostgresTest|MatriculaSensitivePermissionsTest" --stop-on-failure *> "$ev\04.txt"
php scripts/pgsql-test.php test --filter="Sprint12AtomicityTest|BackendRobustnessTest" --stop-on-failure *> "$ev\05.txt"
php scripts/pgsql-test.php test --filter="MatriculaNotificationTest|MatriculaNotificationBacklogTest" --stop-on-failure *> "$ev\06.txt"
php scripts/pgsql-test.php test --filter="ServicioTecnologicoTest|PanelServiciosTest|CatalogoWebServiciosTest" --stop-on-failure *> "$ev\07.txt"
php scripts/pgsql-test.php test --filter="CotizacionTest|PanelCotizacionesTest" --stop-on-failure *> "$ev\08.txt"
php scripts/pgsql-test.php test --filter=ConcurrencyPostgresTest --stop-on-failure *> "$ev\09.txt"
php scripts/pgsql-test.php test *> "$ev\10.txt"

# 4. Cierre: retirar el consentimiento y volver a solo lectura
(Get-Content .env.pgsql-testing.local) -replace '^HORUS_PG_TEST_CONFIRM=.*$','HORUS_PG_TEST_CONFIRM=' | Set-Content .env.pgsql-testing.local -Encoding ascii
php scripts/pgsql-test.php probe
git status --short; git stash list
```

Ante el primer fallo de cualquier etapa: detenerse, conservar la evidencia, retirar el consentimiento (paso 4) y clasificar el fallo (diferencia esperable de motor, defecto de prueba o defecto de aplicación) antes de proponer correcciones. No se amplían tiempos, no se relajan guardas ni aserciones y no se reintenta automáticamente. `horus_l2_restore_test` no se usa.

## 11. PG-TEST-05B-1 — primera aplicación real de migraciones (29/10/2026, autorizada)

Alcance autorizado: activar temporalmente el consentimiento, `check`, aplicar las migraciones pendientes **solo** en `horus_l2_test`, verificar el esquema y retirar el consentimiento. Sin pruebas funcionales, concurrencia, rollback, `migrate:fresh` ni seeders. `horus_l2_restore_test`, `horus_group`, `horus_group_ensayo`, 5432 y S7 (55432) no se tocaron; HBA, roles, contraseñas y servicios sin cambios. Verificación de correo suspendida y Resend desactivado.

### Secuencia y resultado

| Paso | Resultado |
| --- | --- |
| Git previo | HEAD `0388952…`, índice vacío, 2 stashes, 41 entradas preexistentes en el árbol (preservadas) |
| `pg_isready -h 127.0.0.1 -p 55433` | Aceptando conexiones |
| `probe` | `horus_l2_test` / `horus_l2_test_runner` / 55433 / `horus_pgt_55433` / `7702263743609677164`; solo lectura, `search_path` `public`, UTC |
| Diagnóstico previo **sin consentimiento** (conector protegido en modo sonda, sesión de solo lectura) | PostgreSQL 18.6; `public` sin objetos; solo `horus_test_control` (marcador). `migrate:status`: «Migration table not found» → las 26 migraciones del repositorio pendientes, todas identificadas (§2 y documentación de sprints) |
| Consentimiento en el perfil local | Activado; ACL del archivo sin cambios |
| `check` | Sesión autorizada `horus_l2_test` en `127.0.0.1:55433`, sin DDL/DML |
| `migrate:status` (antes) | «Migration table not found» |
| `migrate` | 26/26 `DONE`, lote 1, sin errores SQL (incluida `000022` solo en esta base) |
| `migrate:status` (después) | 26 `Ran`, lote `[1]`, ninguna pendiente |
| Consentimiento | Retirado inmediatamente después (ACL sin cambios); `check` pasa a rechazarse, como corresponde |

### Verificación del esquema (sesión de solo lectura, sin consentimiento)

| Comprobación | Resultado |
| --- | --- |
| Tablas en `public` | 31, exactamente las esperadas (ninguna falta ni sobra) |
| Columnas | 259 (detalle por tabla en la evidencia) |
| Índices | 75, 54 únicos; 0 inválidos o no listos |
| Índices únicos clave | `matriculas_vigente_unica` parcial (`deleted_at IS NULL AND estado <> 'anulada'`), `cotizaciones_creacion_unica`, `cotizacion_historial_revision_unica`, `cotizacion_seguimiento_unico`; `registros_pendientes` con `email_hash` y `token_hash` únicos |
| CHECK | 14: los 10 con nombre propio (`cursos_cupos_rango`, `cursos_precio_no_negativo`, `matriculas_monto_no_negativo`, `servicios_revision_positiva`, `servicios_vigencia_ordenada`, `servicios_autorizacion_fecha`, `cotizacion_revision_positiva`, `cotizacion_contacto_minimo`, `cotizacion_servicio_snapshot`, `cotizacion_historial_revision_positiva`) y 4 generados por `enum()` en 000025/000027 |
| Claves foráneas | 22, con las acciones previstas: `RESTRICT` en servicio/solicitud/curso de publicaciones y cotizaciones, `SET NULL` en referencias a `users`, `CASCADE` en matrículas, avisos y tablas Spatie |
| Restricciones sin validar | 0 |
| Triggers, funciones y tipos propios | 0 (los triggers solo existen en SQLite); extensiones: `plpgsql` |
| Datos | Solo `migrations` (26) y `permissions` (15, guard `web`). `roles`, concesiones, `users`, estudiantes, cursos, matrículas, avisos, servicios y cotizaciones vacíos |
| Permisos creados | `matriculas.exportar/eliminar`, `cursos.publicar`, `aprobaciones.ver/gestionar`, `respaldo.ver/exportar`, `servicios.ver/crear/editar/publicar`, `cotizaciones.ver/crear/editar/gestionar`. Sin rol `admin`, las migraciones no conceden nada (comportamiento previsto) |
| Propiedad y privilegios | Los 129 objetos de `public` son de `horus_l2_test_runner`; sin concesiones a otros roles; runner sin superusuario, `CREATEROLE`, `CREATEDB`, `BYPASSRLS` ni replicación |
| Aislamiento | Esquemas `horus_test_control` y `public` únicamente; marcador intacto (propiedad de `horus_pgt_owner`) |
| `probe` de cierre | Aprobado; identificador `7702263743609677164`, solo lectura |

Evidencia (fuera del repositorio): `%LOCALAPPDATA%\HorusPgTest55433\evidencia\pgt05\` — `00-diagnostico-solo-lectura.txt`, `01-status-antes.txt`, `01-migrate.txt`, `01-status-despues.txt`, `02-verificacion.txt`, `02-esquema-detalle.txt` (columnas, restricciones e índices), `03-probe-cierre.txt` y los dos scripts de solo lectura usados (`pgt05b1-diagnostico.php`, `pgt05b1-verificacion.php`). No contienen credenciales.

Límite: la verificación prueba el esquema resultante, no el comportamiento funcional ni la concurrencia.

### Consideración para PG-TEST-05B-2

Las pruebas del perfil reconstruyen el esquema por sí mismas: `RefreshDatabase` ejecuta `migrate:fresh` al iniciar cada proceso, y `DatabaseMigrations` (`MatriculaNotificationTest`, `MatriculaNotificationBacklogTest`) ejecuta `migrate:fresh` antes de cada prueba y `migrate:rollback` después. El esquema verificado aquí se borrará y se recreará dentro de `public` de `horus_l2_test` (el marcador no se ve afectado, §6). La autorización de 05B-2 debe cubrir expresamente ese `migrate:fresh`/`migrate:rollback` implícito del arnés. El paso 2 del §10 ya está hecho; 05B-2 parte de los pasos 0, 1, 3 y 4, con `migrate:status` previo (26 `Ran`) como comprobación de estado.

## 12. PG-TEST-05B-2A — primera ronda funcional (29/10/2026, autorizada; detenida en la etapa 1)

Alcance autorizado: etapas 1-7 (las 2-8 del §10) en `horus_l2_test`, con escrituras de prueba, `migrate:fresh`/`migrate:rollback` del arnés y transacciones internas. Excluidos: concurrencia, suite completa, restauraciones, `horus_l2_restore_test`, cambios de HBA, credenciales, roles o servicios, y bases o puertos reales.

### Preparación (solo lectura)

- Selección por filtro, listada con la configuración SQLite (el bootstrap PostgreSQL conecta y exige consentimiento). Etapas: 1 → 131 pruebas en Feature/Unit (la de `TestDatabaseSafetyTest` está excluida del perfil) + 5 de `MatriculaConstraintsPostgresTest`; 2 → 48; 3 → 141 + 6 de `MatriculaRulesPostgresTest`; 4 → 28; 5 → 55; 6 → 60; 7 → 55. **Ningún filtro selecciona `ConcurrencyPostgresTest`.**
- La etapa 1 incluye por el filtro `Migration|Permission` una prueba de Advisor (bloquea HTTP y solo comprueba el rechazo sin permiso), una de verificación de correo (`down()`/`up()` de `000022` dentro de la transacción de la prueba, sin activar la función) y una de aprovisionamiento (siembra roles dentro de la transacción). No activan nada persistente.
- Guardarraíles por etapa (script fuera del repositorio): `pg_isready`, `probe` con base, runner, puerto, `cluster_name`, identificador y solo lectura; consentimiento solo en el perfil (ni en el entorno del proceso ni en `.env`); huella de Git (`git status`, HEAD, stashes e índice) igual a la de referencia.

### Ejecución

| Paso | Resultado |
| --- | --- |
| Guardarraíles previos | OK; consentimiento vacío; huella Git `F74BBA100F6DEF33` |
| Consentimiento + `check` | Activo (ACL sin cambios); `check` correcto |
| Etapa 1 (`MatriculaConstraintsPostgresTest\|MatriculaIntegrityConstraintsTest\|Migration\|Permission`, `--stop-on-failure`) | **Detenida en 11 s: 61 pruebas, 229 aserciones, 1 fallo, 24 «risky»** |
| Tras el fallo | Consentimiento retirado de inmediato (ACL sin cambios); `probe` aprobado; guardarraíles finales OK con la misma huella Git; sin temporales de exportación |
| Etapas 2-7 | **No ejecutadas** (regla de detención al primer fallo) |

### Fallo 1 — `AdminAuthorizationTest::test_exports_are_restricted_to_their_permissions`

- Síntoma: `POST admin.reportes.exportar.excel` como administrador devuelve 500 en lugar de 200. Excepción: `RuntimeException: La exportación requiere una transacción con snapshot consistente.` (`MatriculasExcelExport::rows()`, línea 45).
- Causa: en PostgreSQL, `rows()` abre una transacción `REPEATABLE READ, READ ONLY` si no hay ninguna abierta (nivel 0); si ya existe una, exige que tenga `repeatable read` o `serializable` y, si no, falla cerrado a propósito. `RefreshDatabase` envuelve cada prueba en una transacción `READ COMMITTED`, así que la petición llega con nivel 1 y aislamiento `read committed`.
- Clasificación: **interacción entre el arnés de pruebas y una salvaguarda deliberada de la aplicación**. No es una diferencia de datos ni de esquema. Por la evidencia disponible tampoco es un defecto de la aplicación: en una petición real, el controlador (`ReportController::exportarExcel` → `downloadTemporaryExport`) no abre transacción, y la exportación crea su propio snapshot. Límite: la ruta de nivel 0 en PostgreSQL **no ha quedado demostrada** por esta ejecución.
- SQLite: la misma clase pasa completa (86 pruebas, 260 aserciones), porque la comprobación de snapshot solo se aplica a `pgsql`.
- Impacto previsto: lo mismo ocurrirá en las demás pruebas que exportan Excel con éxito bajo `RefreshDatabase`: `AdminReportConsistencyTest` (parte en la etapa 1), `ReportExcelExportTest` y `ReportExcelScalabilityTest` (fuera de las etapas 1-7, solo en la suite completa).
- Opciones de corrección (**no aplicadas**; requieren decisión): (a) en PostgreSQL, que esas pruebas no usen la transacción envolvente (`DatabaseTruncation` o `DatabaseMigrations`) para que la petición llegue con nivel 0 y ejercite la ruta real; (b) que las pruebas de exportación inicien su transacción con `REPEATABLE READ`; (c) separar la aserción de permisos (403/200) de la de descarga. No se recomienda relajar la salvaguarda de `MatriculasExcelExport`.

### Observación — 24 pruebas «risky» (no afectan al resultado)

- Mensaje: «Test code or tested code removed error handlers other than its own». Aparece en las 24 pruebas de `AdminAuthorizationTest` que llegaron a ejecutarse; no aparece en las 37 de `PostgresTestGuardTest` (que no arrancan Laravel) ni en SQLite.
- Causa probable (por lectura de código, no verificada ejecutando): `tests/bootstrap-pgsql.php` arranca una aplicación antes de PHPUnit, y ese arranque registra manejadores globales. Al terminar cada prueba, `HandleExceptions::flushHandlersState()` retira **todos** los manejadores, también esos. Sería un artefacto del arnés PostgreSQL, no funcional. Tratarlo exige su propia decisión: «risky» no es un fallo, pero ensucia la evidencia.

Evidencia (fuera del repositorio): `%LOCALAPPDATA%\HorusPgTest55433\evidencia\pgt05\b2a\` — `etapa1.txt` (salida original en UTF-16), `etapa1.utf8.txt`, `probe-tras-fallo.txt` y `pgt05b2a-etapa.ps1`. Sin credenciales.

Estado: esquema `public` de `horus_l2_test` reconstruido por `RefreshDatabase` (26 migraciones) con datos de prueba revertidos; consentimiento vacío. No se modificó código, pruebas ni migraciones. Reanudar requiere decidir la corrección y una nueva autorización.

### Corrección aplicada — opción (a) con separación (c) (29/10/2026, autorizada; solo pruebas, solo SQLite)

Sin operaciones PostgreSQL ni consentimiento. `MatriculasExcelExport`, el controlador y las migraciones no cambian: la salvaguarda de snapshot sigue intacta.

| Prueba | Cambio |
| --- | --- |
| `ReportExcelExportTest`, `ReportExcelScalabilityTest`, `AdminReportConsistencyTest` | `RefreshDatabase` → `DatabaseMigrations` (patrón de `MatriculaNotificationTest`) y `assertIsolatedTestDatabase()` en `setUp()`. La petición llega con nivel de transacción 0 y la exportación abre la suya |
| `AdminAuthorizationTest::test_exports_are_restricted_to_their_permissions` | Conserva solo las comprobaciones 403 (sigue con `RefreshDatabase`); la descarga 200 del coordinador pasa a `ReportExcelExportTest::test_coordinador_downloads_the_excel_report_with_the_seeded_permissions` |
| `ReportExcelExportTest::test_excel_export_opens_its_own_snapshot_instead_of_joining_a_test_transaction` (nueva) | Comprueba que la prueba no envuelve la petición (nivel 0), que la primera lectura de matrículas ocurre en el nivel 1 abierto por la exportación y que el snapshot se libera. **En `pgsql`** comprueba además la sentencia `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY` y, dentro del lote, `transaction_isolation = repeatable read` y `transaction_read_only = on`. En SQLite esa parte no se ejecuta: solo PostgreSQL la demuestra |

Por qué no `DatabaseTruncation`: deja el esquema migrado pero vacía `permissions` (las crean las migraciones, no un seeder) y deja datos para las clases `RefreshDatabase` siguientes, que no limpian. `DatabaseMigrations` hace `migrate:fresh` antes y `migrate:rollback` después, y marca `RefreshDatabaseState::$migrated = false`, de modo que la siguiente clase `RefreshDatabase` vuelve a migrar.

Validación (SQLite en memoria, `phpunit.xml`): las cuatro clases, 116 pruebas y 520 aserciones; suite completa, 1138 pruebas y 7602 aserciones; Larastan sin errores; Pint sin cambios de estilo (solo el aviso conocido de CRLF en la copia de trabajo de los dos archivos con CRLF); `git diff --check` limpio.

Efecto en PostgreSQL (pendiente de ejecutar): cada prueba de esas tres clases hará `migrate:fresh` y `migrate:rollback` completos en `horus_l2_test` (unas 30 en total, las de la etapa 1 incluidas). Es la primera vez que los `down()` de las 26 migraciones se ejecutarán en PostgreSQL de forma sistemática; un fallo ahí sería un hallazgo de migraciones, no de la exportación. El filtro de la etapa 1 (`…|Permission`, sin distinguir mayúsculas) selecciona ahora también la nueva prueba del coordinador: 132 Feature/Unit (antes 131) más las 5 de `MatriculaConstraintsPostgresTest`; 5 de ellas usan `DatabaseMigrations` (las 4 variantes de `test_export_buttons_and_endpoints_require_their_own_permission` y la del coordinador).

Reanudación de la etapa 1 (requiere autorización): mismo procedimiento que la primera ronda — activar el consentimiento solo en `.env.pgsql-testing.local`; `pgt05b2a-etapa.ps1 -Etapa 1 -Baseline <huella Git nueva>` (la huella cambió con estos archivos y debe recalcularse con `-Etapa 0` antes de activar el consentimiento); ante el primer fallo, retirar el consentimiento, `probe` y clasificar; al cerrar, consentimiento vacío, `probe` y evidencia fuera del repositorio. Etapas 2-7, concurrencia y suite completa quedan fuera.

### Reanudación de la etapa 1 — segunda ronda (29/10/2026, autorizada; detenida tras la etapa 1)

| Paso | Resultado |
| --- | --- |
| Etapa 0 (consentimiento vacío) | `pg_isready` y `probe` OK: `horus_l2_test` / `horus_l2_test_runner` / 55433 / `horus_pgt_55433` / `7702263743609677164`, solo lectura. Huella Git `F6069FD33667A6CE` = 41 entradas previas + los 4 archivos de prueba revisados; `MatriculasExcelExport` y `ReportController` sin cambios |
| Etapa 1 | 137 pruebas (132 Feature/Unit + 5 `MatriculaConstraintsPostgresTest`), 666 aserciones, 66 s: **136 aprobadas** (37 sin avisos y 99 con aviso «risky»), **1 error**, 0 fallos. PHPUnit cuenta 100 «risky» porque incluye la que dio error |
| Tras el error | Consentimiento retirado (ACL sin cambios); `probe` OK y en solo lectura; guardarraíles de cierre con la misma huella; sin temporales de exportación |

Exportación Excel en PostgreSQL: pasan las 4 variantes de `AdminReportConsistencyTest::test_export_buttons_and_endpoints_require_their_own_permission` (403 y 200 según el permiso) y `ReportExcelExportTest::test_coordinador_downloads_the_excel_report_with_the_seeded_permissions`. La descarga 200 sin transacción envolvente demuestra que la ruta de nivel 0 ya no choca con la salvaguarda. **No** demuestra explícitamente el aislamiento: `test_excel_export_opens_its_own_snapshot_instead_of_joining_a_test_transaction` no coincide con el filtro de la etapa 1 y no se ejecutó. `migrate:fresh`/`migrate:rollback` completos funcionaron en PostgreSQL en las 7 pruebas con `DatabaseMigrations` de la etapa (5 de exportación + `MatriculaNotificationTest` y `MatriculaNotificationBacklogTest`, 1 cada una).

Error — `MatriculaIntegrityConstraintsTest::test_database_rejects_restoring_a_deleted_enrollment_over_an_active_one`: el índice `matriculas_vigente_unica` **sí** rechaza la restauración (se captura la `QueryException`), pero la consulta de comprobación posterior falla con `SQLSTATE[25P02]`: PostgreSQL aborta la transacción de `RefreshDatabase` tras el error, mientras que SQLite sigue aceptando consultas. Clasificación: **incompatibilidad de la prueba con PostgreSQL** (mismo patrón que resolvió PG-TEST-05A con savepoints). No es un defecto de la aplicación ni de la restricción, y no tiene relación con la corrección de Excel. No se corrigió.

Observaciones:
- `--stop-on-failure` no detiene PHPUnit ante *errores*; tras el error (prueba 90) se ejecutaron las 47 restantes de la misma etapa. `scripts/pgsql-test.php` no admite `--stop-on-error`.
- Aviso «risky» («removed error/exception handlers») en todas las pruebas que arrancan Laravel, sin suprimir; misma causa probable que en la primera ronda.

Evidencia: `%LOCALAPPDATA%\HorusPgTest55433\evidencia\pgt05\b2a\` — `etapa1.txt` (UTF-16), `etapa1.utf8.txt`, `probe-tras-fallo.txt`, `guardas-cierre.txt`; la primera ronda está en `ronda1\`.

### Correcciones tras la segunda ronda (29/10/2026, autorizadas; solo pruebas y herramientas, solo SQLite)

Sin conexión PostgreSQL ni consentimiento. `MatriculasExcelExport`, `ReportController`, migraciones e índices no cambian.

| Archivo | Cambio |
| --- | --- |
| `tests/Feature/MatriculaIntegrityConstraintsTest.php` | En `test_database_rejects_restoring_a_deleted_enrollment_over_an_active_one`, la restauración que debe fallar va dentro de `DB::transaction(...)` (savepoint, patrón de PG-TEST-05A). Exige el rechazo: en `pgsql`, SQLSTATE `23505` y el nombre `matriculas_vigente_unica` en el mensaje; en SQLite, `23000`. Después comprueba que el nivel de transacción de la prueba sigue igual (savepoint revertido, transacción de `RefreshDatabase` utilizable) y el estado final: la eliminada sigue con `deleted_at`, 1 vigente y 2 en total |
| `tests/Feature/ReportExcelExportTest.php` | La prueba anterior se divide. `test_excel_export_runs_in_its_own_transaction_and_releases_it` (nueva, cualquier driver) comprueba solo niveles 0 → 1 → 0. `test_excel_export_opens_its_own_snapshot_instead_of_joining_a_test_transaction` pasa a ser **exclusiva de PostgreSQL** (`markTestSkipped` fuera de `pgsql`, para que SQLite no cuente como evidencia) y exige `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY`, `repeatable read` y `transaction_read_only = on` en la primera lectura de matrículas; al terminar, nivel 0 y `transaction_isolation`/`transaction_read_only` de nuevo iguales a los valores por defecto de la sesión (cierre de la transacción) |
| `app/Support/Testing/PostgresTestArguments.php` (nuevo) | Lista cerrada de opciones de `scripts/pgsql-test.php test`: `--filter=…` (no vacío), `--stop-on-failure` y `--stop-on-error`. Esta última se traduce en `--stop-on-error --stop-on-failure` de PHPUnit: parada en el primer error **o** fallo |
| `scripts/pgsql-test.php` | Usa esa clase antes de arrancar Laravel (una opción rechazada nunca llega a la base) y pasa a PHPUnit solo las opciones validadas |
| `tests/Unit/PostgresTestArgumentsTest.php` (nuevo) | Traducción, deduplicación y rechazo de otras opciones (`--stop-on-defect`, `--stop-on-risky`, `--do-not-fail-on-risky`, `--configuration`, `--bootstrap`, filtro vacío, variantes de escritura) |

PHPUnit instalado: 11.5.57. Sus opciones reales: `--stop-on-error` (solo errores), `--stop-on-failure` (solo fallos) y `--stop-on-defect`, que también se detiene ante «risky» y pararía cada etapa en la primera prueba que arranca Laravel; por eso no se usa. No se añade ninguna opción que oculte errores, fallos ni avisos «risky».

Simulación con PHPUnit (fixtures fuera del repositorio, sin base de datos; 3 pruebas por caso):

| Caso | Sin opción | `--stop-on-failure` | `--stop-on-error` del script |
| --- | --- | --- | --- |
| pasa, **error**, pasa | 3 ejecutadas | 3 ejecutadas (reproduce la segunda ronda) | **2 ejecutadas**, salida 2 |
| pasa, **fallo**, pasa | 3 ejecutadas | 2 ejecutadas | **2 ejecutadas**, salida 1 |
| **risky**, pasa, pasa | 3, «Risky: 1» | 3, «Risky: 1» | 3, «Risky: 1»: no se detiene y el aviso se informa |

`php scripts/pgsql-test.php test --stop-on-defect` y `--stop-on-errors` se rechazan con salida 1 antes de arrancar Laravel (sin conexión).

Validación (SQLite en memoria): clases afectadas, 28 aprobadas y 1 omitida (la prueba de snapshot, exclusiva de PostgreSQL), 127 aserciones; suite completa, **1148 aprobadas y 1 omitida**, 7621 aserciones; Larastan sin errores; Pint sin cambios en los archivos nuevos y en `scripts/pgsql-test.php`, y en copias LF de los dos archivos de prueba con CRLF (en la copia de trabajo solo aparece el aviso conocido de finales de línea); `git diff --check` limpio. `--list-tests` confirma que cada filtro de abajo selecciona exactamente una prueba. **SQLite no demuestra** ni el savepoint frente a `25P02` ni `REPEATABLE READ, READ ONLY`: ambas quedan pendientes en PostgreSQL.

Pruebas PostgreSQL pendientes (requieren autorización independiente; `horus_l2_test` con consentimiento solo en `.env.pgsql-testing.local`, etapa 0 previa con consentimiento vacío y huella Git recalculada):

```powershell
php scripts/pgsql-test.php test "--filter=MatriculaIntegrityConstraintsTest::test_database_rejects_restoring_a_deleted_enrollment" --stop-on-error
php scripts/pgsql-test.php test "--filter=ReportExcelExportTest::test_excel_export_opens_its_own_snapshot" --stop-on-error
```

La segunda hace `migrate:fresh` y `migrate:rollback` (`DatabaseMigrations`). Las etapas siguientes deben usar `--stop-on-error` en lugar de `--stop-on-failure`.

### Pruebas pendientes ejecutadas en PostgreSQL (29/10/2026, autorizadas; dos ejecuciones aisladas)

Solo los dos comandos anteriores, en `horus_l2_test`. Sin etapas 2-7, concurrencia, suite completa ni `horus_l2_restore_test`; sin conexión a `horus_group`, `horus_group_ensayo` ni S7. No se modificó código durante las ejecuciones.

| Paso | Resultado |
| --- | --- |
| Etapa 0 (consentimiento vacío) | `pg_isready` y `probe` OK: `horus_l2_test` / `horus_l2_test_runner` / 55433 / `horus_pgt_55433` / `7702263743609677164`, solo lectura. Huella Git recalculada `A9D4127F9CDD0185` (HEAD `0388952`), 2 stashes. `.env.pgsql-testing.local` ignorado por Git |
| Prueba 1 | Consentimiento activado solo en el perfil local; guardarraíles repetidos con la misma huella. **Aprobada**, salida 0, 1 prueba, **7 aserciones**, 0 errores, 0 fallos, **1 «risky»** |
| Tras la prueba 1 | Consentimiento retirado; `probe` OK con la misma huella |
| Prueba 2 | Mismo procedimiento. **Aprobada** (ejecutada, no omitida), salida 0, 1 prueba, **15 aserciones**, 0 errores, 0 fallos, **1 «risky»** |
| Tras la prueba 2 | Consentimiento retirado; `probe` OK con la misma huella |

Qué demuestra cada una en PostgreSQL 18.6:

- **`MatriculaIntegrityConstraintsTest::test_database_rejects_restoring_a_deleted_enrollment_over_an_active_one`**
  - Las 7 aserciones solo son posibles en la rama `pgsql` (en SQLite son 6).
  - **Rechazo:** `matriculas_vigente_unica` rechaza la restauración con SQLSTATE `23505`, y el mensaje nombra el índice.
  - **Recuperación:** el savepoint se revierte y el nivel de transacción vuelve al anterior. La transacción de `RefreshDatabase` sigue utilizable: las consultas posteriores se ejecutan **sin `25P02`**, el error de la segunda ronda.
  - **Estado final:** la matrícula eliminada conserva `deleted_at`; 1 vigente y 2 en total.
- **`ReportExcelExportTest::test_excel_export_opens_its_own_snapshot_instead_of_joining_a_test_transaction`**
  - **Snapshot:** se ejecuta `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY`.
  - **Lectura:** la primera lectura de `matriculas` ocurre en la transacción propia de la exportación: nivel 1, `transaction_isolation = repeatable read` y `transaction_read_only = on`.
  - **Cierre:** tras la respuesta, nivel 0. `transaction_isolation` y `transaction_read_only` vuelven a sus valores por defecto de sesión, es decir, la transacción se cerró.
  - Hizo `migrate:fresh` y `migrate:rollback` solo en `horus_l2_test`.

**Avisos «risky».** Ambas pruebas muestran «Test code or tested code removed error handlers other than its own» y lo mismo para los manejadores de excepciones. Es el mismo aviso de las rondas anteriores, probablemente por `tests/bootstrap-pgsql.php` (manejadores globales retirados por Laravel). **No está resuelto ni suprimido.**

Evidencia (fuera del repositorio): `%LOCALAPPDATA%\HorusPgTest55433\evidencia\pgt05\b2a\pendiente1.txt` y `pendiente2.txt`, con la salida de PHPUnit y la línea de guardarraíles posterior. El envoltorio que activó y retiró el consentimiento (`pgt05b2a-pendiente.ps1`) quedó en el área temporal de la sesión. Sin credenciales.

### PG-TEST-05B-2A.2 — auditoría independiente y preparación endurecida (29/10/2026)

**Preparación local comprobada; etapa 1 NO repetida. PG-TEST-05B-2A permanece pendiente.** Esta sección sustituye la preparación anterior de 138 pruebas/8 entradas con `DatabaseMigrations`: aquel filtro amplio incluía pruebas reservadas a otras etapas. En esta auditoría no se ejecutaron `probe`, `check`, conexiones PostgreSQL, migraciones ni pruebas funcionales PostgreSQL; no se activó ni modificó el consentimiento real.

#### Selección definitiva

El filtro exacto está en `pgt05b2a-etapa.ps1 -Etapa 1 -SoloFiltro`: unión de integridad, migraciones, permisos y snapshot, con exclusión negativa construida desde los filtros de etapas 2–7. `-SoloFiltro` sale antes de las guardas y de cualquier socket.

Enumeración independiente: `phpunit.pgsql.xml` con **bootstrap sustituido explícitamente por `vendor/autoload.php`**, `--list-tests` y la ruta de argumentos PowerShell → PHP → `PostgresTestArguments` → Symfony Process → PHPUnit. No usar el bootstrap PostgreSQL para enumerar: abre una conexión. Resultado: **116 entradas, cero solapamientos con etapas 2–7**, cinco de `MatriculaConstraintsPostgresTest` y snapshot incluido. Los datasets cuentan por separado.

Las seis entradas con `DatabaseMigrations`, contrastadas con el trait usado por las clases seleccionadas:

- `Tests\Feature\AdminReportConsistencyTest::test_export_buttons_and_endpoints_require_their_own_permission"neither"`
- `Tests\Feature\AdminReportConsistencyTest::test_export_buttons_and_endpoints_require_their_own_permission"pdf only"`
- `Tests\Feature\AdminReportConsistencyTest::test_export_buttons_and_endpoints_require_their_own_permission"excel only"`
- `Tests\Feature\AdminReportConsistencyTest::test_export_buttons_and_endpoints_require_their_own_permission"both"`
- `Tests\Feature\ReportExcelExportTest::test_excel_export_opens_its_own_snapshot_instead_of_joining_a_test_transaction`
- `Tests\Feature\ReportExcelExportTest::test_coordinador_downloads_the_excel_report_with_the_seeded_permissions`

`--stop-on-error` llega a PHPUnit y `PostgresTestArguments` añade `--stop-on-failure`: detención ante el primer error o fallo, sin detenerse por «risky». Fixtures PHPUnit aisladas confirmaron 2/3 pruebas ejecutadas ante error y fallo, 3/3 ante «risky». No se promete una cifra de aserciones o «risky» para la ejecución futura.

#### Hallazgos y correcciones

1. **Cierre no acreditado:** el envoltorio original ignoraba la salida de `taskkill`, no esperaba ni comprobaba la terminación y solo actuaba si seguía vivo el padre. Podía dejar PHP descendiente activo mientras retiraba el consentimiento; retirarlo del archivo no revoca una sesión SQL ya abierta.
2. **Contención desde el arranque:** `pgt05b2a2-process.cs` crea el hijo suspendido, lo asigna a un Job Object privado sin permisos de breakaway y con `KILL_ON_JOB_CLOSE`, y después lo reanuda. Si falla la asignación no ejecuta el script. También se comprueba el contador de procesos activos del job cuando sale el padre. Cubre descendientes ordinarios PowerShell/PHP/Symfony creados con CreateProcess; no procesos lanzados mediante brokers externos/WMI, que quedan fuera del procedimiento. Referencia: [Job Objects de Microsoft](https://learn.microsoft.com/en-us/windows/win32/procthread/job-objects).
3. **Terminación verificada:** si quedan procesos, `taskkill /T /F` tiene espera máxima de 5 s y se comprueba su salida. Se termina también el job, se espera al padre hasta 5 s y se exige `ActiveProcesses=0`. Un fallo de taskkill, descendientes vivos tras salir el padre o cierre no verificable causa parada no satisfactoria, incluso si el respaldo termina los procesos. Cerrar el handle por sí solo no acredita terminación. No se usa una fotografía de PID como prueba de ausencia de descendientes.
4. **Retirada prioritaria:** la retirada está en un `finally` independiente; las excepciones de cierre y fallos de consola/disco no la omiten. Se verifica valor vacío y SHA-256 del perfil idéntico al inicial. Solo se permite el probe posterior si retirada y cierre son seguros. Un fallo impide continuar; no hay reintentos automáticos. Fallos de logging también dejan salida no satisfactoria.
5. **Huella Git insuficiente:** la anterior solo comparaba rutas/estados, HEAD y nombres del índice; una edición de contenido pasaba inadvertida. `pgt05b2a2-fingerprint.ps1` incorpora SHA-256 de archivos versionados/no ignorados e índice, rama, HEAD y stashes por SHA. Exige `anderson` y HEAD `038895280000f57d39c698e40d3436fecc0d716f`; referencia obligatoria y comprobada antes de sockets. La antigua `A9D4127F9CDD0185` ya no sirve. Recalcular después de editar documentos.
6. **Destino antes de conectar:** se exige en el perfil host `127.0.0.1`, puerto `55433`, base `horus_l2_test`, runner `horus_l2_test_runner`, conexión `pgsql` y perfil `1`, sin duplicados ni sustituciones de esos campos desde el proceso. La identidad física sigue protegida por las guardas existentes: `horus_pgt_55433`, `7702263743609677164` y solo lectura. No se modificaron guardas funcionales. Se verificaron constantes y dobles; no se revalidó en vivo la versión 18.6 ni la identidad del servidor.

**Límite:** se probó cancelación real de canalización PowerShell; no acredita todas las formas de Ctrl+C de cada host. La terminación forzada del envoltorio, caída del sistema o corte eléctrico puede impedir retirar el consentimiento del archivo; ningún `finally` garantiza esa retirada. Kill-on-close es un respaldo de procesos, no revocación de consentimiento ni prueba de rollback SQL. Esos casos exigen inspección manual antes de otra autorización.

#### Simulaciones sin PostgreSQL

Copias del envoltorio con rutas temporales, stage ficticio, archivo `profile.txt` y marca `SIMULATED_CONSENT` sin validez para la aplicación. No se usó el perfil real para simular activación. El caso de fallo de retirada conserva intencionalmente la marca ficticia para demostrar su detección.

| Caso | Resultado observado |
| --- | --- |
| PHPUnit correcto / error / fallo | Salidas 0 / 2 / 1 conservadas; retirada y cierre comprobados |
| Hijo termina inesperadamente | Salida 99 conservada; retirada y cierre comprobados |
| Excepción con hijo activo | Salida 13; cierre comprobado y retirada |
| Falla taskkill | Launcher sustituido por proceso que devuelve 5 sin matar al hijo; Job Object deja padre terminado/job vacío; salida 15, sin probe posterior |
| Padre sale con descendiente activo | Descendiente real de fixture detectado y terminado; ausencia adicional por PID; salida 15 y retirada |
| Cierre no verificable (inyección) | Salida 15; retirada comprobada; sin probe posterior |
| Interrupción real de canalización | `PowerShell.Stop()` sobre ejecución asíncrona: finally ejecutado, hijo ausente y marca ficticia retirada |
| Falla retirada (inyección) | Salida 10; marca ficticia persistente detectada; sin probe posterior |
| Falla probe posterior ficticio | Salida 11; marca ficticia vacía |
| Cambia evidencia anterior ficticia | Salida 12; SHA-256 distinto detectado |
| Salida ya existe | Salida 20; sin activar marca ni sobrescribir salida |
| Falla logging (inyección) | Salida 16; retirada y cierre no omitidos |

Total: **14 escenarios** (13 casos más cancelación real). Además, **10 casos del bloque local de destino**: identidad exacta aceptada; localhost, remoto, 55432, 5432, habitual, ensayo, restore, otro runner y duplicado rechazados antes de sockets.

**135 pruebas PHP/446 aserciones**, `phpunit.xml`, filtro `PostgresTestGuardTest|ReadOnlyProbeTest|PostgresTestArgumentsTest`, aprobadas con dobles sin sockets PostgreSQL. Parser PowerShell 5.1 y compilación C# aprobados. No se ejecutó suite funcional ni frontend: solo documentación y auxiliares externos.

Originales de los scripts preservados como `.before`. Los tres hashes de entrada coinciden con los informados por Claude. `list-etapa1-ronda3-pgsql.txt` se conserva sin sobrescribir. Evidencias nuevas, fixtures, manifiesto previo, selección y transcripciones: `C:\Users\PCT40T\AppData\Local\Temp\claude\c--Users-PCT40T-Documents-Sistema-Horus-System\9529e627-a47d-4cc4-bddc-2ee309a63534\scratchpad\pgt05b2a2-audit`. La verificación final confirmó las diez evidencias preexistentes (incluidos subdirectorios) intactas por SHA-256; ningún cambio ajeno, índice idéntico byte por byte, HEAD y dos stashes preservados, perfil idéntico y consentimiento vacío, sin revelar valores del entorno.

#### Artefactos finales y siguiente fase pendiente de autorización

Directorio: `C:\Users\PCT40T\AppData\Local\Temp\claude\c--Users-PCT40T-Documents-Sistema-Horus-System\9529e627-a47d-4cc4-bddc-2ee309a63534\scratchpad`.

| Archivo | SHA-256 |
| --- | --- |
| `pgt05b2a-etapa.ps1` | `A52E6F3E0756018D3CEC3CB873F51632B621BB7476FAAE5660E0269E390EF800` |
| `pgt05b2a1-etapa1.ps1` | `07267AD379FD150C93AB3E2575E1B67391C9E116F3747752171EA80A1D927785` |
| `pgt05b2a2-process.cs` | `0DDDD9587078E8AE96C7D3668DA6468F9B455CE62968718AB987BFB729E7FAF9` |
| `pgt05b2a2-fingerprint.ps1` | `054D82E12AB1702387FD76461E07071882A0794692E08DBBBB7D4A7F8292CDCB` |

Los cuatro artefactos son necesarios; conservarlos juntos y verificar sus hashes antes de una ejecución futura. El consentimiento real permanece vacío y el archivo debe seguir idéntico al inicial.

Siguiente fase preparada, **NO ejecutada; requiere autorización independiente**: desde la raíz, cargar `pgt05b2a2-fingerprint.ps1` y llamar `Get-GitFingerprint`; comprobar hashes y que `etapa1-ronda3.txt` y `etapa1-ronda3-envoltorio.txt` no existen; ejecutar únicamente el envoltorio con esa nueva referencia (`-Baseline`). Hará etapa 0, ventana de consentimiento para etapa 1 y cierre comprobado. La autorización debe cubrir expresamente `migrate:fresh`/`migrate:rollback` implícitos de las seis entradas DatabaseMigrations y la preparación RefreshDatabase, solo en la base desechable. Cualquier resultado inesperado detiene la operación; etapas 2–7, concurrencia y suite completa siguen sin autorizar.

#### Listado definitivo de las 116 entradas (enumeradas, no ejecutadas en PostgreSQL)

```text
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"development database"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"unknown test name"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"remote host"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"multi host"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"daily development port"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"privileged user"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"wrong driver"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"connection URL"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"read replica"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"write replica"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"database tunnel"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"PDO override"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"server options override"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"search path"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"empty password"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"environment URL"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"environment mismatch"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"missing profile"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"missing consent"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"wrong environment"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"secondary cache"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"secondary queue"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"secondary session"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"external Redis"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"external OpenAI"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"external mail"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"libpq service override"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"libpq address override"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"libpq options override"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"cached configuration"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"effective local environment"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"persistent cache"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"persistent sessions"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"persistent queue"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"real mail"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"broadcast service"
Tests\Unit\PostgresTestGuardTest::test_rejection_precedes_probe_and_migration"secondary connection"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"dashboard"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"busqueda"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"estudiantes.index"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"estudiantes.create"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"cursos.index"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"cursos.create"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"matriculas.index"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"matriculas.create"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"matriculas.csv"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"usuarios.index"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"usuarios.create"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"roles.index"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"procesos.index"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"procesos.create"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"aprobaciones.index"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"notificaciones.index"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"reportes.index"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"configuracion.index"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"auditoria.index"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"auditoria.exportar"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"respaldo.index"
Tests\Feature\AdminAuthorizationTest::test_admin_pages_follow_the_role_permission_matrix"asistente.index"
Tests\Feature\AdminAuthorizationTest::test_enrollment_destructive_operations_require_specific_permissions
Tests\Feature\AdminAuthorizationTest::test_exports_are_restricted_to_their_permissions
Tests\Feature\AdminAuthorizationTest::test_process_detail_requires_process_permission
Tests\Feature\AdminAuthorizationTest::test_assigning_roles_requires_the_assign_roles_permission
Tests\Feature\AdminAuthorizationTest::test_creating_users_with_roles_requires_the_assign_roles_permission
Tests\Feature\AdminAuthorizationTest::test_dashboard_hides_audit_activity_without_audit_permission
Tests\Feature\AdminProtectionTest::test_the_admin_role_cannot_lose_critical_permissions
Tests\Feature\AdminProtectionTest::test_the_admin_role_may_drop_non_critical_permissions_and_keep_its_name
Tests\Feature\AdminProtectionTest::test_coordinator_and_secretary_permissions_are_not_changed_by_the_protection
Tests\Feature\AdminProtectionTest::test_role_update_forgets_the_permission_cache_after_the_commit
Tests\Feature\AdminProtectionTest::test_role_deletion_forgets_the_permission_cache_after_the_commit
Tests\Feature\AdminProtectionTest::test_a_rejected_admin_role_change_leaves_permissions_and_cache_untouched
Tests\Feature\AdminReportConsistencyTest::test_export_buttons_and_endpoints_require_their_own_permission"neither"
Tests\Feature\AdminReportConsistencyTest::test_export_buttons_and_endpoints_require_their_own_permission"pdf only"
Tests\Feature\AdminReportConsistencyTest::test_export_buttons_and_endpoints_require_their_own_permission"excel only"
Tests\Feature\AdminReportConsistencyTest::test_export_buttons_and_endpoints_require_their_own_permission"both"
Tests\Feature\AdvisorCatalogoTest::test_export_requires_publisher_permission_not_just_authenticated_user
Tests\Feature\AprobacionesRespaldoPermissionsMigrationTest::test_legacy_database_denies_admin_until_the_migration_runs
Tests\Feature\AprobacionesRespaldoPermissionsMigrationTest::test_migration_reproduces_the_seeder_matrix_for_these_permissions
Tests\Feature\AprobacionesRespaldoPermissionsMigrationTest::test_migration_is_idempotent_and_keeps_every_other_assignment
Tests\Feature\AprobacionesRespaldoPermissionsMigrationTest::test_migration_without_roles_only_creates_permissions
Tests\Feature\AprobacionesRespaldoPermissionsMigrationTest::test_fresh_installation_keeps_seeder_grants_and_down_has_no_effect
Tests\Feature\EditorPublicacionPanelTest::test_link_and_backend_follow_permission_not_just_role"coordinador sin permiso"
Tests\Feature\EditorPublicacionPanelTest::test_link_and_backend_follow_permission_not_just_role"secretaria sin permiso"
Tests\Feature\EditorPublicacionPanelTest::test_link_and_backend_follow_permission_not_just_role"coordinador autorizado"
Tests\Feature\MatriculaIntegrityConstraintsTest::test_database_rejects_a_second_active_enrollment_written_outside_the_panel
Tests\Feature\MatriculaIntegrityConstraintsTest::test_history_is_kept_and_re_enrollment_is_allowed_after_annulment_or_soft_delete
Tests\Feature\MatriculaIntegrityConstraintsTest::test_database_rejects_restoring_a_deleted_enrollment_over_an_active_one
Tests\Feature\MatriculaIntegrityConstraintsTest::test_panel_restore_conflict_is_still_a_validation_error_not_a_server_error
Tests\Feature\MatriculaIntegrityConstraintsTest::test_migration_aborts_before_any_change_when_existing_data_violates_the_rule
Tests\Feature\MatriculaIntegrityConstraintsTest::test_migration_reports_seat_and_amount_violations_before_any_change
Tests\Feature\MatriculaIntegrityConstraintsTest::test_migration_is_reversible
Tests\Feature\ReportExcelExportTest::test_excel_export_opens_its_own_snapshot_instead_of_joining_a_test_transaction
Tests\Feature\ReportExcelExportTest::test_coordinador_downloads_the_excel_report_with_the_seeded_permissions
Tests\Feature\SecretsAndExportsTest::test_settings_require_their_own_permissions
Tests\Feature\SecretsAndExportsTest::test_export_routes_keep_their_permission_requirements
Tests\Feature\SecureProvisioningTest::test_seeders_keep_the_existing_permission_matrix
Tests\Feature\Sprint14EmailVerificationTest::test_pending_registration_migration_preserves_existing_seeded_users
Tests\Feature\Sprint17PublicacionTest::test_revision_cannot_be_reused_for_another_course_or_to_bypass_permission
Tests\Feature\Sprint17PublicacionTest::test_permission_revoked_after_request_authorization_is_rechecked_before_write
Tests\Feature\Sprint17PublicacionTest::test_lost_staff_role_blocks_write_even_if_direct_permission_remains
Tests\Feature\Sprint17PublicacionTest::test_withdrawal_rechecks_fresh_permission_after_acquiring_course
Tests\Feature\Sprint17PublicacionTest::test_public_projection_fails_closed_when_publication_permission_is_missing
Tests\Feature\Sprint17PublicacionTest::test_editor_and_mutations_are_inaccessible_without_staff_and_permission"guest GET"
Tests\Feature\Sprint17PublicacionTest::test_editor_and_mutations_are_inaccessible_without_staff_and_permission"guest PUT"
Tests\Feature\Sprint17PublicacionTest::test_editor_and_mutations_are_inaccessible_without_staff_and_permission"guest DELETE"
Tests\Feature\Sprint17PublicacionTest::test_editor_and_mutations_are_inaccessible_without_staff_and_permission"without_staff_role GET"
Tests\Feature\Sprint17PublicacionTest::test_editor_and_mutations_are_inaccessible_without_staff_and_permission"without_staff_role PUT"
Tests\Feature\Sprint17PublicacionTest::test_editor_and_mutations_are_inaccessible_without_staff_and_permission"without_staff_role DELETE"
Tests\Feature\Sprint17PublicacionTest::test_editor_and_mutations_are_inaccessible_without_staff_and_permission"secretaria GET"
Tests\Feature\Sprint17PublicacionTest::test_editor_and_mutations_are_inaccessible_without_staff_and_permission"secretaria PUT"
Tests\Feature\Sprint17PublicacionTest::test_editor_and_mutations_are_inaccessible_without_staff_and_permission"secretaria DELETE"
Tests\PostgresIntegrity\MatriculaConstraintsPostgresTest::test_database_rejects_two_active_enrollments_for_the_same_pair
Tests\PostgresIntegrity\MatriculaConstraintsPostgresTest::test_database_allows_new_enrollment_when_previous_ones_are_annulled_or_deleted
Tests\PostgresIntegrity\MatriculaConstraintsPostgresTest::test_database_rejects_restoring_a_deleted_enrollment_over_an_active_one
Tests\PostgresIntegrity\MatriculaConstraintsPostgresTest::test_database_rejects_negative_or_excess_seats
Tests\PostgresIntegrity\MatriculaConstraintsPostgresTest::test_database_rejects_negative_amounts_and_prices
```

## 13. Consolidación del 30/10/2026

Esta sección distingue las comprobaciones nuevas de los informes históricos anteriores y no autoriza sus comandos operativos.

| Pendiente | Estado | Evidencia y límite |
| --- | --- | --- |
| Destino, marcador y privilegios mínimos | **VALIDADO EN POSTGRESQL** para solo lectura | `pg_isready`, preflight de nueve campos y probe actuales aprobados: 18.6, `127.0.0.1:55433`, `horus_l2_test`, `horus_l2_test_runner`, `horus_pgt_55433`, `7702263743609677164`; sesión y predeterminado `on`. No prueba comportamiento funcional |
| Terminales ambiguos anteriores | **RESUELTO** en la fotografía actual | Diez PID históricos y los otros dos terminales pendientes ausentes; sin cierre automático. Detector admite la inspección directa. Debe repetirse antes de una etapa futura |
| Detector y captura PowerShell | **VALIDADO LOCALMENTE** | Dos defectos del análisis corregidos; 6 regresiones focalizadas, 10 simulaciones y 10 regresiones adicionales pasan. Streams y código separados; detención en primera aserción fallida probada. [Detalle](postgresql-testing-detector-procesos.md) |
| Bootstrap y manejadores PHPUnit/Laravel | **VALIDADO LOCALMENTE** | 258 contabilizadas, 821 aserciones, 1 omitida; suite completa 1.211 aprobadas, 1 omitida, 7.759 aserciones; cero fallos/errores/risky. Larastan nivel 6 aprobado |
| Selección etapa 1 | **VALIDADO LOCALMENTE** | Nueva enumeración: 116 nombres y orden originales, cero duplicados/solapamiento con etapas 2–7; cinco restricciones de matrículas y snapshot Excel incluidos. Enumerar no ejecuta |
| Savepoint e índice de matrículas | **VALIDADO LOCALMENTE** ahora; ejecución PostgreSQL histórica; **revalidado en PostgreSQL el 30/10** (ver «Etapa 1 revalidada») | Se conserva transacción anidada y aserciones `23505`/`matriculas_vigente_unica`. Los logs originales acreditan recuperación sin `25P02`; revalidación conjunta pendiente |
| Snapshot Excel real | **EJECUTADO EL 30/10** (antes: pendiente de autorización para revalidación) | Históricamente ejecutado con 15 aserciones y un risky. Exige snapshot propio nivel 1, `REPEATABLE READ, READ ONLY` y nivel 0 al terminar. SQLite lo omite por diseño |
| 26 migraciones y `down()` | **VALIDADO LOCALMENTE** | Inventario estático de 26 archivos; fixture SQLite observa 26 `up` y 26 `down` completados en orden inverso, tablas retiradas: 8 aserciones. `000023/24/26/28` tienen `down()` vacío deliberado; no revierten sus datos/política/concesiones. Ramas PostgreSQL y restauración no certificadas por SQLite |
| 79 risky originales | **CERO RISKY EN POSTGRESQL el 30/10** (antes: pendiente de autorización) | Log original UTF-16: 116 pruebas, 525 aserciones, 79 risky, 126,241 s. Dos mensajes por cada entrada, en 13 clases. Laravel arrancaba antes de los manejadores PHPUnit; corrección validada localmente sin suprimir avisos |
| Concurrencia, segunda sesión y ETag | **PENDIENTE DE AUTORIZACIÓN** PostgreSQL | Guardas de fábrica/conector/identidad y cierre revisadas; dobles/HTTP SQLite pasan. No demuestran carreras ni bloqueos reales. Se conserva transacción, `lockForUpdate` y comprobación de revisión dentro del bloqueo, así como compatibilidad de clientes históricos sin precondición |
| Pint global | **BLOQUEADO** para afirmar aprobación global | Formato preexistente en `MatriculaIntegrityConstraintsTest` y `ReportExcelExportTest`: finales de línea/espaciado. Bytes preservados. Los otros ocho archivos del arnés comprobados no presentan incidencias |
| Etapas 2–7, suite PostgreSQL y restauración | **PENDIENTE DE AUTORIZACIÓN** | No ejecutadas; `horus_l2_restore_test` reservada. S7 y bases reales fuera de alcance |

### Arquitectura y seguridad

El lanzador valida opciones y ejecuta un worker antes de crear PHPUnit. El bootstrap PostgreSQL solo carga autoload y exige preflight; Laravel nace en el ciclo de cada prueba. `TestDatabaseSafety::install()` exige validación fresca antes de instalar el perfil capaz de escribir y `GuardedPostgresConnector::connect()` exige otra antes de cada conexión/reconexión. Después valida marcador, privilegios e identidad física en el mismo PDO todavía de solo lectura. No hay token reutilizable ni bandera de entorno que sustituya ese flujo.

PHPUnit permite sustituir bootstrap/XML por CLI; su XML no es una frontera universal. Las regresiones prueban las barreras independientes de Laravel/conector. Código arbitrario del mismo usuario con acceso a credenciales queda fuera de esa frontera: se presupone código confiable, acceso OS controlado y privilegios PostgreSQL. No se promete una sandbox ni se debilitan guardas.

La sonda ejecutada ahora es el auxiliar externo de identidad de solo lectura, sin consentimiento. El worker operativo `scripts/pgsql-test-preflight.php` conserva su requisito independiente de consentimiento para una ejecución destructiva: se validó mediante dobles y SQLite, no se ejecutó con consentimiento vacío ni se eludió ese requisito.

Los archivos del clúster aislado siguen configurados con SCRAM, loopback y reglas cerradas. El probe valida marcador/privilegios desde el runner sin consultar verificadores ni utilizar administrador. HBA, servicios, contraseñas y permisos no cambiaron.

### Procedimiento definitivo pendiente

1. Usar la cápsula externa nueva `pgt05-consolidacion-ready`, con componentes SHA-256 y `release.json`: baseline final, HEAD, índice, stashes, perfil, nombres y evidencias históricas. Las cápsulas anteriores conservan su baseline histórica y no deben editarse ni reutilizarse con esta documentación nueva. La diferencia Git autorizada es únicamente estos dos documentos actualizados y el documento nuevo del detector.
2. Con autorización independiente, repetir el verificador offline y exigir cero procesos ambiguos/de pruebas. Si reaparece una terminal, el propietario debe identificarla y cerrarla normalmente solo si procede.
3. Repetir `pg_isready`, los nueve campos y probe de solo lectura. Ante discrepancia, detener antes de consentimiento. Enumerar las 116 entradas exactas y exclusión de etapas 2–7.
4. Solo entonces el wrapper autorizado abre consentimiento temporal en el perfil y ejecuta etapa 1 mediante el lanzador protegido, con `--stop-on-error` y `--stop-on-failure`. La autorización futura debe cubrir expresamente las reconstrucciones de esquema implícitas de Laravel. Sin migraciones manuales adicionales.
5. En finally, retirar consentimiento y demostrar perfil idéntico, root y descendientes cerrados con Job `Active=0`, ausencia de pruebas/ambiguos y hashes históricos intactos. Si falla limpieza, detener sin probe ni otra operación. Con limpieza segura, probe final y evidencia externa de resultados individuales/streams/códigos/SHA-256.
6. Aceptación: 116 ejecutadas, snapshot no omitido, cinco restricciones aprobadas y cero fallos/errores/risky. No corregir ni repetir automáticamente ante resultados inesperados. Detener tras etapa 1.

La copia de etapa conserva stdout, stderr, código nativo y excepción de transporte sin promoción a ErrorRecord. El wrapper preparado mantiene Job Object y retirada en finally y cierra solo su Job, sin `taskkill /PID`. La rama forzosa y la ventana de consentimiento no se ensayaron: las fixtures locales cerraron naturalmente. La cápsula es preparación revisable, no autorización ni ejecución funcional PostgreSQL.

Resultado: **PREPARADO PARA SOLICITAR AUTORIZACIÓN DE REVALIDACIÓN PG-TEST-05B-2A**, con deuda de formato explícita y controles que deben repetirse al comenzar. No se activó consentimiento ni se ejecutó etapa 1.

### Etapa 1 revalidada: `stage1-ronda8` (30/10/2026)

Ejecución autorizada de la etapa 1 de PG-TEST-05B-2A con la cápsula externa `pgt05-consolidacion-ready`, lanzada por el propietario desde una ventana CMD independiente (dato aportado por él; los registros no lo acreditan). Esta subsección recoge el resultado tras contrastarlo con los archivos de evidencia. No autoriza repetir la etapa ni ejecutar las siguientes. El procedimiento descrito arriba como «pendiente» se cumplió en esta ejecución.

#### Entorno

| Elemento | Valor |
| --- | --- |
| Fecha y hora | 30/10/2026; verificación previa 14:10, envoltorio 14:11:11, PHPUnit terminó 14:14:00, cierre 14:14:05 (hora local del equipo); etapa de 163 s |
| Software | PHP 8.4.26, PHPUnit 11.5.57, `phpunit.pgsql.xml`, Windows 11 |
| Clúster | PostgreSQL 18.6, `127.0.0.1:55433`, `cluster_name` `horus_pgt_55433`, `system_identifier` `7702263743609677164`, base `horus_l2_test`, usuario `horus_l2_test_runner`; sesión y predeterminado `transaction_read_only = on` fuera de la etapa |
| Git | `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, huella `15320FD18F8C064B31FDB6BE67F7CBB989F39453EE62F81876F0ABAC8A6795C1`, índice vacío, dos stashes |
| Lanzador | `run-authorized-stage1.ps1 -ExecuteAuthorizedStage1` con `-EvidenceDirectory …\execution\stage1-ronda8`; envoltorio `wrapper-v8.ps1`, que ejecuta `--stop-on-error --stop-on-failure` |

#### Resultado

| Concepto | Valor |
| --- | --- |
| Pruebas | **116** (116/116, sin duplicados) |
| Aserciones | **525** |
| Risky | **0** |
| Errores / fallos | **0 / 0** |
| Omitidas, avisos, obsolescencias | 0 (la línea final es `OK (116 tests, 525 assertions)`, sin «but there were issues») |
| Duración / memoria | 02:40,650 / 80,00 MB |

La salida de PHPUnit contiene 116 puntos de progreso, sin ninguna `S`, `R`, `W`, `E` ni `F`. En la ejecución histórica del 29/10 el mismo conjunto dio 116 pruebas / 525 aserciones / **79 risky**; ahora los totales coinciden y los risky desaparecen. Es coherente con la separación bootstrap/preflight, aunque no se hizo un experimento aislado que demuestre la causa.

Distribución de la selección (verificada contra `selection.native.json`): 37 `PostgresTestGuardTest`, 28 `AdminAuthorizationTest`, 14 `Sprint17PublicacionTest`, 7 `MatriculaIntegrityConstraintsTest`, 6 `AdminProtectionTest`, 5 `AprobacionesRespaldoPermissionsMigrationTest`, 5 `PostgresIntegrity\MatriculaConstraintsPostgresTest`, 4 `AdminReportConsistencyTest`, 3 `EditorPublicacionPanelTest`, 2 `ReportExcelExportTest`, 2 `SecretsAndExportsTest`, 1 `AdvisorCatalogoTest`, 1 `SecureProvisioningTest`, 1 `Sprint14EmailVerificationTest`.

#### Comprobaciones puntuales

- **Snapshot Excel `REPEATABLE READ, READ ONLY`.** `ReportExcelExportTest::test_excel_export_opens_its_own_snapshot_instead_of_joining_a_test_transaction` estaba en la selección. Su código omite la prueba si el driver no es `pgsql` y la salida no registra ninguna omisión, así que ejecutó la rama PostgreSQL. Sus aserciones exigen `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY`, nivel de transacción 1, aislamiento `repeatable read`, `read_only = on` y, al terminar, nivel 0 con los valores por defecto de sesión. La exportación (`MatriculasExcelExport`) no cambió.
- **Recuperación mediante savepoint.** `MatriculaIntegrityConstraintsTest::test_database_rejects_restoring_a_deleted_enrollment_over_an_active_one` encierra la restauración en `DB::transaction()`, que dentro de `RefreshDatabase` es un savepoint. Exige SQLSTATE `23505` con el índice `matriculas_vigente_unica` en `pgsql`, el mismo nivel de transacción después y el estado final correcto (1 vigente, 2 en total). Aprobada: no reaparece `25P02`.
- **Integridad de matrículas.** Las 12 entradas de matrícula (7 de `MatriculaIntegrityConstraintsTest` y las cinco restricciones de `PostgresIntegrity\MatriculaConstraintsPostgresTest`: dos activas, reinscripción tras anulación o borrado, restauración sobre vigente, cupos y montos negativos o excesivos) quedaron aprobadas.
- **Migraciones.** Seis entradas usan `DatabaseMigrations` (4 de `AdminReportConsistencyTest` y 2 de `ReportExcelExportTest`): Laravel ejecuta `migrate:fresh` al empezar cada una y `migrate:rollback` al terminar, solo en `horus_l2_test`. Además, tres pruebas de migración de matrículas (aborta antes de cambiar ante datos inválidos, informa violaciones de cupo y monto, es reversible) quedaron aprobadas. No se ejecutaron migraciones manuales.
- **Avisos risky:** cero.

#### Guardarraíles

| Control | Resultado |
| --- | --- |
| Verificador offline, antes y a mitad | Aprobado: perfil y evidencias fijadas intactos, consentimiento vacío, rama, HEAD, índice, stashes y huella, sin procesos ambiguos ni de pruebas |
| `pg_isready`, identidad (nueve campos) y probe previos | Aprobados |
| Enumeración | 116 nombres únicos, en el orden fijado |
| Consentimiento | Activado solo en el perfil local durante la etapa; «consent withdrawn; profile identical to its initial state» |
| Cierre | Raíz y descendientes cerrados con Job activo = 0 (tres comprobaciones); verificador de cierre (`-AfterStage`) aprobado dos veces |
| Verificaciones finales | `probe` e identidad de nueve campos aprobados; mismo `system_identifier` `7702263743609677164` |
| Git | Huella `15320FD1…` y HEAD `0388952` iguales antes y después; 13 evidencias previas intactas |

#### Evidencia y hashes

Carpeta de la ejecución: `execution\stage1-ronda8` dentro de la cápsula `pgt05-consolidacion-ready` (fuera del repositorio). Salida de PHPUnit y registro del envoltorio: `%LOCALAPPDATA%\HorusPgTest55433\evidencia\pgt05\b2a\`. Verificación posterior: se recalcularon los 56 hashes de `sha256.json` (22 archivos y 34 capturas nativas) y los 56 coinciden.

| Archivo | SHA-256 |
| --- | --- |
| `sha256.json` (manifiesto de 56 entradas) | `1794C61092DD9E52C8F6187546A3571BB77D5A647B4B0768333A39BFAC7E4A55` |
| `etapa1-pgt05-consolidacion-ronda8.txt` (salida de PHPUnit) | `2310A819AFA8D52D1F1F866AE6E075253F9EBC4933A12F3FD9C3CC0EB4AFE640` |
| `etapa1-pgt05-consolidacion-ronda8-envoltorio.txt` | `491B7CE55463F5AEEEE14FF0D5B193D882509F664ADA6974A9B5191AFB104896` |
| `stage-wrapper.native.json` | `EA99F54AB1FE7721A8C84C6AF4673827916A645FCE2B5CD82AA354112AA4431D` |
| `selection.native.json` | `E1717C6116229C427A53C7CA83C7C913C833FAF7DF901508216C3A5CF35ACB3B` |
| `probe-before.native.json` | `97477A728856CD2B324BBF161D53A259DB5C3E1AD947E66D06E21C9C9BA84F40` |
| `probe-after.native.json` | `D84AB6F0394AA0D42929C5673C21FAFA055CB2EBF0E193DF1CD2B4F483B791AC` |
| `identity-before/stdout.txt` e `identity-after/stdout.txt` (idénticos) | `AB2EBA03789D32B40FF85F5302C4632FEB98586A3AA5E70BF979E6DB5AF19FE8` |
| `offline-final.native.json` | `8EF253C74C242CF7839F023E7ECD02E6BDCBCC4AB8E24CA67C1F87449B03C2AF` |

#### Limitaciones y trabajo pendiente

- La salida de PHPUnit solo muestra puntos de progreso: no hay nombre, estado ni aserciones por prueba. La afirmación de que cada prueba concreta se ejecutó se apoya en la selección de 116 nombres, en el resultado `OK` sin omitidas ni risky y en el código fuente de las pruebas. Los recuentos por prueba (7 y 15 aserciones) proceden de las ejecuciones aisladas del 29/10 y no se midieron de nuevo.
- La última línea del envoltorio, «RESULT: PHPUnit exit=0 (0 pass; 1 failure; 2 error)», es una leyenda fija de códigos de salida (`wrapper-v8.ps1`), no un recuento. Además, el registro conserva la etiqueta heredada «stage 1 round 4».
- No se inspeccionó el esquema de `horus_l2_test` después de la ejecución: la auditoría no se conectó a PostgreSQL. `probe-after` solo acredita identidad, marcador y privilegios, y no el estado de las tablas tras los `migrate:rollback`.
- Dos de las comprobaciones de procesos (`process-evidence\b808aca9-…` y `88e69610-…`) las nombra el verificador pero no figuran en `sha256.json`.
- Es una única ejecución: no mide intermitencia. La duración (163 s) es mayor que la del 29/10 (126,241 s) sin causa investigada.
- Pint global sigue bloqueado por el formato preexistente descrito arriba.
- **Siguen pendientes de autorización independiente** (a la fecha de esta ejecución; la etapa 2 se ejecutó después, ver más abajo): etapas 2–7, pruebas de concurrencia, suite completa en PostgreSQL y restauración (`horus_l2_restore_test`). PG-TEST-05B-2A **no está finalizada**: la etapa 1 es solo una parte de la validación.

### Etapa 2 ejecutada: `stage2-ronda1` (30/10/2026)

Ejecución autorizada de la etapa 2 de PG-TEST-05B-2A («estudiantes y cursos») con la cápsula independiente `pgt05b2a-etapa2-capsule` (creada fuera del repositorio; no reutiliza la de la etapa 1). Esta subsección recoge el resultado tras contrastarlo con los archivos de evidencia. No autoriza repetir la etapa ni ejecutar las siguientes. El propietario confirmó que `horus_l2_test` es desechable y autorizó expresamente que `migrate:fresh` reconstruya el esquema `public` (no `horus_test_control` ni otras bases).

#### Entorno

| Elemento | Valor |
| --- | --- |
| Fecha y hora | 30/10/2026; envoltorio 16:16:23, etapa 16:16:25–16:18:01 (95 s), cierre 16:18:04 (hora local del equipo) |
| Software | PHP 8.4.26, PHPUnit 11.5.57, `phpunit.pgsql.xml`, Windows 11 |
| Clúster | PostgreSQL 18.6, `127.0.0.1:55433`, `cluster_name` `horus_pgt_55433`, `system_identifier` `7702263743609677164`, base `horus_l2_test`, usuario `horus_l2_test_runner` |
| Git | `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, huella `FA02004B23F0B2EAC42C75A77098AF25218F4D8E8E8C083F0CF206E9655E31F0`, índice vacío, dos stashes |
| Lanzador | `run-authorized-stage2.ps1 -ExecuteAuthorizedStage2 -Baseline FA02004B…`, con ruta absoluta, desde `cmd.exe` iniciado por el agente (no una ventana abierta por el propietario; sin terminales de VS Code ni ancestros PowerShell) |
| Orden ejecutada | `php scripts/pgsql-test.php test --filter=AdminEstudianteTest\|AdminCursoTest\|CursoPublicacionTest --stop-on-error`; `PostgresTestArguments` lo expande a `--stop-on-error --stop-on-failure` |

#### Resultado

| Concepto | Valor |
| --- | --- |
| Pruebas | **48** (48/48; 31 métodos en 3 clases: `AdminCursoTest` 7, `AdminEstudianteTest` 6, `CursoPublicacionTest` 35, de ellas 21 entradas de datasets) |
| Aserciones | **410** (medidas de la salida real, no predeclaradas) |
| Risky / errores / fallos | **0 / 0 / 0** |
| Omitidas, avisos, obsolescencias | 0 (la línea final es `OK (48 tests, 410 assertions)`; `stderr` de 0 bytes) |
| Duración / memoria | 01:32,478 / 66,00 MB |
| Acumulado etapas 1 y 2 | **164 pruebas / 935 aserciones** |

La enumeración previa (`--list-tests` con `--bootstrap vendor/autoload.php`) reprodujo los 48 nombres en el orden fijado y era byte a byte idéntica al listado de la auditoría (`3825E24FC5DB92C2…`).

#### Migraciones y esquema

- Las tres clases usan solo `RefreshDatabase`: `migrate:fresh` (`db:wipe` + las 26 migraciones) corrió una vez, al empezar el proceso PHP, sobre el esquema `public` de `horus_l2_test`; no hay `DatabaseMigrations` ni `migrate:rollback`. Cada prueba corrió en una transacción revertida. El único DDL explícito es `down()`/`up()` de `2026_10_28_000021` en `CursoPublicacionTest::test_existing_installation_migration_assigns_publication_only_to_admin` (transaccional en PostgreSQL, revertido con la prueba).
- **No se registró cada migración ni se inspeccionó el esquema final:** la salida de PHPUnit no lista migraciones, y `pg_isready`, la identidad y el `probe` no consultan tablas. Que las 48 pruebas pasaran es evidencia indirecta de que el esquema se reconstruyó completo.

#### Guardarraíles

| Control | Resultado |
| --- | --- |
| Antes de lanzar | Energía conectada (`PowerLineStatus=Online`, WMI `PowerOnline=True`; un primer intento se detuvo por batería sin tocar nada); manifiesto, lanzador, release y línea base idénticos a los autorizados; 24 de 24 hashes de la cápsula; 18 evidencias históricas intactas; sin procesos ambiguos |
| Preflight (mismo día, autorizado) | `pg_isready`, identidad de nueve campos y `probe` de solo lectura aprobados; enumeración de 48 nombres idéntica |
| Consentimiento | Activado solo en el perfil local durante la etapa; «consent withdrawn; profile identical to its initial state»; perfil `42372EC6033D008D0BD3A74CC13BAC0EF017C21165371FB9877307494D8767B7` |
| Cierre | Raíz y descendientes cerrados con Job activo = 0 en los tres hijos (guardián previo, etapa, guardián posterior); `offline-closure` y `offline-final` aprobados; ninguna carpeta `timeout-*` |
| Verificaciones finales | `probe` e identidad de nueve campos aprobados; mismo `system_identifier` |
| Git | Huella `FA02004B…`, HEAD, índice `B5E81A5E…` y dos stashes iguales antes y después |

#### Cápsula y endurecimiento de plazos

- Cápsula independiente de 24 archivos (manifiesto `B5235D0D7D0C2730EA8BE4B7B4563252B3A9A486EF31841A678AE078A4F01C4E`; lanzador, raíz de confianza, `427BA953DC73D76A7DADDFDF608562FD687C2AC1B4A9324EC83AC1E2954E07FA`; release `CEBA0E9843D73F2648D63E7BE7115B1EB7C09BA5F9FD2A92E2993A13505B8B4E`). Filtro, 48 nombres, destino y destinos prohibidos (55432, 5432, S7, `horus_l2_restore_test`, `horus_group`, `horus_group_ensayo`) fijados en el código y en `release.json`; criterio de aceptación `OK (48 tests, N assertions)` con N medido.
- Antes de ejecutar se añadieron plazos al envoltorio (guardianes 180 s, etapa 720 s, tope heredado de PHP 900 s, reloj `Stopwatch`) y el código de salida 17 = TIMEOUT, con prioridad de los códigos de limpieza 10-16. Se validó de extremo a extremo con repositorio, perfil y procesos ficticios (43 comprobaciones: finalización normal, timeout de etapa con descendiente, timeout de guardián previo y posterior, rechazo, fallo de cierre simulado, prioridad de limpieza, evidencia parcial y ausencia de reintentos). No se agotó ningún plazo en la ejecución real.
- Referencia de duración para etapas futuras: etapa 1, 163 s para 116 pruebas (1,4 s por prueba); etapa 2, 95 s para 48 pruebas (2,0 s por prueba, con el `migrate:fresh` único).

#### Evidencia y hashes

Carpeta de la ejecución: `execution-etapa2-ronda1` dentro de la cápsula (fuera del repositorio). Salida de PHPUnit y registro del envoltorio: `%LOCALAPPDATA%\HorusPgTest55433\evidencia\pgt05\b2a\`. Verificación posterior: se recalcularon los 52 hashes de `sha256.json` (17 de la carpeta de ejecución, 30 capturas nativas y 5 archivos de evidencia) y los 52 coinciden.

| Archivo | SHA-256 |
| --- | --- |
| `sha256.json` (manifiesto de 52 entradas) | `975759EA90595D42261E995188B353C1A0F40F004AF9ADACD608AC2B31479519` |
| `acceptance.json` (`assertions_measured` 410) | `3713D06CC6C22C408529F1DED5A920B3BB00E665C1B6DB57BB01462FAC929EF8` |
| `etapa2-pgt05-estudiantes-cursos-ronda1.txt` (salida de PHPUnit; idéntico a `.stdout.txt`) | `9DAB83FB11ACB6F49B01BE700FF8AEFFA767682EB67DC58A0BB128B91B5EB179` |
| `etapa2-pgt05-estudiantes-cursos-ronda1.txt.native.json` | `82AF224E199A2C9E60B3631A07EB27F83723B11DE9E6C7D443E03FF1C3D09082` |
| `etapa2-pgt05-estudiantes-cursos-ronda1-envoltorio.txt` | `00725785E1B4B7D6A3036669B3DABA5E7EA0D8E17091EFEE1D21BFEE24E7AE6C` |

#### Limitaciones y trabajo pendiente

- La salida de PHPUnit solo muestra puntos de progreso (48/48): no hay nombre, estado ni aserciones por prueba ni por clase. La afirmación por clase (7, 6 y 35 pruebas) se apoya en la selección enumerada y en el resultado `OK` sin omitidas ni risky.
- No se registró la ejecución individual de las 26 migraciones ni se inspeccionó el esquema final (véase arriba).
- `launcher-stage2.exit` quedó vacío por un defecto del auxiliar CMD (`echo %ERRORLEVEL%>` interpreta `0>` como redirección de un descriptor); el código 0 consta en la salida del lanzador y en los 11 pasos nativos, todos con salida 0 y sin `stderr`.
- La ejecución la lanzó `cmd.exe` iniciado por el agente, y vivió dentro del trabajo de esa sesión; no se acredita una ventana independiente del propietario.
- Es una única ejecución: no mide intermitencia.
- La cápsula reside en una carpeta temporal de sesión (`%LOCALAPPDATA%\Temp\claude\…\scratchpad\pgt05b2a-etapa2-capsule`): conviene copiarla sin modificarla a una ubicación durable.
- La edición de este documento y de `PROJECT_CONTEXT.md` cambia la huella Git: `FA02004B…` queda obsoleta y la etapa 3 necesita una línea base nueva.

### Hoja de ruta pendiente tras la etapa 2

Inventarios obtenidos de la enumeración offline del 30/10 (`--list-tests`, bootstrap `vendor/autoload.php`), sin solapamientos entre etapas. Solo la etapa 3 tiene análisis detallado (siguiente sección); en las demás, los indicadores son un cribado estático preliminar y cada una exigirá su propio plan antes de ejecutarse.

| Etapa | Filtro | Entradas / métodos | Indicadores preliminares |
| --- | --- | --- | --- |
| 1 | (selección de integridad, migraciones y permisos) | 116 | **Aprobada** (116 / 525) |
| 2 | `AdminEstudianteTest\|AdminCursoTest\|CursoPublicacionTest` | 48 / 31 | **Aprobada** (48 / 410) |
| 3 | `AdminMatriculaTest\|MatriculaOperationsTest\|MatriculaRulesPostgresTest\|MatriculaSensitivePermissionsTest` | 147 / 51 | `RefreshDatabase`; sin DDL explícito. Ver plan |
| 4 | `Sprint12AtomicityTest\|BackendRobustnessTest` | 28 / 20 | Atomicidad y robustez; correo desactivado en pruebas |
| 5 | `MatriculaNotificationTest\|MatriculaNotificationBacklogTest` | 55 / 45 | **`DatabaseMigrations` en toda la clase** (`migrate:fresh` y `migrate:rollback` por prueba), `down()` de migración en una prueba y `Http::fake`: riesgo de duración (superar el plazo de 720 s) y esquema vacío al terminar |
| 6 | `ServicioTecnologicoTest\|PanelServiciosTest\|CatalogoWebServiciosTest` | 60 / 43 | Usa `Schema`/migraciones en dos clases |
| 7 | `CotizacionTest\|PanelCotizacionesTest` | 55 / 35 | Correo desactivado en pruebas |

Suma de las etapas 1 a 7: 509 entradas (116 + 48 + 147 + 28 + 55 + 60 + 55). Quedan fuera de las etapas la concurrencia (`ConcurrentTestSession`), la suite completa y la restauración, y tres clases de pruebas están excluidas de `phpunit.pgsql.xml` (`TestDatabaseSafetyTest`, `ConcurrentTestSessionTest`, `PromoverAdminLocalTest`).

### Plan técnico de la etapa 3 (preparado, no ejecutado)

**Estado: PLAN. No se ejecutó PHPUnit, Artisan ni ninguna operación PostgreSQL para elaborarlo.** Se basa en lectura de las clases, de las migraciones y de controladores, y en el listado de la enumeración offline del 30/10.

#### Selección

- Filtro: `AdminMatriculaTest|MatriculaOperationsTest|MatriculaRulesPostgresTest|MatriculaSensitivePermissionsTest`.
- **147 entradas, 51 métodos, 4 clases:** `AdminMatriculaTest` 18 (13 métodos), `MatriculaOperationsTest` 110 (19 métodos), `MatriculaSensitivePermissionsTest` 13 (13 métodos) y `PostgresIntegrity\MatriculaRulesPostgresTest` 6 (6 métodos). 141 son de `tests/Feature` y 6 de `tests/PostgresIntegrity`.
- 13 métodos usan datasets, con 109 entradas: `AdminMatriculaTest` (formulario con entrada en forma de array, 6) y `MatriculaOperationsTest` (matriz de estados y traslados 18, vinculaciones obsoletas 2, padres elegibles 7, restauración con padres 21, reversión ante fallo 5, adquisición bloqueada 4, conflictos tardíos 4, elegibilidad por adquisición 21, correcciones históricas con padres inactivos 6, cambios tardíos de elegibilidad 10, traslado sin ocupación de destino 2 y traslado que libera una ocupación desde un origen no disponible 3); los otros 7 métodos de `MatriculaOperationsTest` no tienen dataset.
- Sin solapamiento con la etapa 1 (su filtro excluye por construcción los nombres de las etapas 2-7; `MatriculaSensitivePermissionsTest` queda en la 3), con la etapa 2 (0 nombres comunes) ni con las etapas 4-7. Listado: SHA-256 `A0A233D410C8D37F…`.
- Resultado esperado: `OK (147 tests, N assertions)`. **N es desconocido** y se medirá; no se conoce el número de aserciones.

#### Tablas, migraciones y operaciones

- **Tablas:** `estudiantes`, `cursos`, `matriculas`, `matricula_notifications` (el observer inserta una intención por cada `Matricula::create`, con el envío desactivado), `users`, las cinco tablas de Spatie (los roles se siembran en cada prueba) y `audit_logs` (exportación CSV). No usan `sessions`, `cache` ni `jobs`.
- **Migraciones relevantes:** `000003` (matrículas), `000013` (índice único parcial `matriculas_vigente_unica` y los `CHECK` de PostgreSQL `cursos_cupos_rango`, `cursos_precio_no_negativo` y `matriculas_monto_no_negativo`), `000020` (notificaciones), `000012` (permisos de exportar y eliminar) y `000006`/`0001_01_01_000000` (permisos y usuarios).
- **Operaciones destructivas:** `migrate:fresh` único por `RefreshDatabase` (las 26 migraciones). No hay `DatabaseMigrations`, `migrate:rollback` ni DDL explícito. `MatriculaSensitivePermissionsTest` ejecuta `up()`/`down()` de `000012`, que solo toca filas de `permissions`, `role_has_permissions` y `model_has_permissions` dentro de `DB::transaction` (un savepoint dentro de la transacción de la prueba), con `insertOrIgnore`.
- **Transacciones y bloqueos:** `DB::transaction` con `lockForUpdate()` sobre cursos, estudiantes y matrículas (`withTrashed`), con el orden fijado por el protocolo de bloqueo. Es la primera ejecución de esas rutas en PostgreSQL con estas pruebas; con una sola sesión no hay esperas. Las pruebas que simulan «conflictos tardíos» lo hacen en una conexión con `DB::listen` y `afterResolving`: **no son concurrencia real** (el propio código lo indica).

#### Riesgos específicos de PostgreSQL

1. **`CHECK` solo de PostgreSQL** (`cursos_cupos_rango` y los otros dos): las pruebas manipulan cupos directamente (`increment`/`decrement` y `update` de `cupos_*`). Revisé a mano las manipulaciones del código y no encontré violaciones, pero con 109 entradas de dataset no puedo descartarlas sin ejecutar. Un fallo sería un `23514`.
2. **`FOR UPDATE` real** con `withTrashed()` y orden por `id`: primera ejecución en PostgreSQL de estas rutas (en la etapa 2 `CursoController` ya la ejercitó con éxito).
3. **`insertOrIgnore`** de la migración `000012`: en PostgreSQL es `ON CONFLICT DO NOTHING`; requiere los índices únicos de Spatie, presentes tras `migrate:fresh`.
4. **Búsquedas `ILIKE`** (`MatriculaRulesPostgresTest`, incluida la búsqueda global por `"id"::text ilike`): SQL de PostgreSQL que SQLite no ejercita.
5. **Comparaciones `assertEquals` de filas `stdClass`** antes y después de una operación fallida: mismos tipos en ambos lados, riesgo bajo.
6. **Omisiones silenciosas:** `MatriculaRulesPostgresTest` se omite si el perfil no es `HORUS_PG_TEST_PROFILE=1` o si el driver no es `pgsql`; el criterio de aceptación rechaza cualquier omitida.
7. **Duración:** 147 pruebas × 1,4–2,0 s medidos en las etapas 1 y 2 = unos 205–295 s (≈ 4–5 min); incluso a 3 s por prueba serían 441 s. El plazo de etapa de 720 s es suficiente (≥ 1,6 veces el peor caso razonable). No se justifica tocar el tope heredado de 900 s.
8. **Huella Git:** esta actualización documental invalida `FA02004B…`; hay que recalcular la línea base **después** de congelar los documentos.
9. **Datos de otras etapas:** ninguno; `migrate:fresh` reconstruye todo y la etapa 3 no depende del esquema que dejó la 2.

#### Criterios de aceptación

`OK (147 tests, N assertions)` con N medido y registrado; cero risky, errores, fallos, omitidas, incompletas y avisos; código de salida 0; consentimiento vacío y perfil idéntico a `42372EC6…`; Job `Active=0`; identidad y `probe` finales correctos con el mismo `system_identifier`; huella Git igual a la nueva línea base; evidencias históricas (incluidas las de las etapas 1 y 2) intactas y nombres de evidencia exclusivos de la etapa 3.

#### Procedimiento propuesto (no ejecutado)

1. Congelar los documentos y **recalcular la línea base Git**.
2. Crear una cápsula nueva e independiente (`pgt05b2a-etapa3-capsule`) a partir de la de la etapa 2, sin modificar esta: `release.json` (etapa 3, 147 nombres, filtro, línea base, evidencias `etapa3-pgt05-matriculas-ronda1`), guardián (`ValidateSet(0,3)` y filtro), envoltorio, lanzador (aceptación 147), verificador (que hoy fija `stage == 2` y 48 pruebas), detector (nombres de control nuevos), verificación estática y README. Misma política de plazos (180/720/900) y mismo código 17.
3. Validación offline (estática, sintética y `validate-offline.ps1` real con `GateClear=true`) y fijación de hashes.
4. Con autorización: preflight (`pg_isready`, nueve campos, `probe`, enumeración de 147 nombres) y, con autorización expresa e independiente, la ejecución desde `cmd.exe`, con energía conectada y sin terminales de VS Code, mediante el lanzador oficial.
5. Detenerse tras la etapa 3; no avanzar a la 4 sin una nueva autorización.

#### Autorizaciones necesarias

(a) Preparar la cápsula de la etapa 3 (trabajo offline). (b) Preflight operativo de solo lectura. (c) Ejecución de la etapa 3, incluida la reconstrucción destructiva de `horus_l2_test` (`migrate:fresh`). Cada una es independiente; la aprobación de la etapa 2 no autoriza ninguna.

> **Nota 10/11/2026:** la migración `2026_11_10_000039_add_check_estados_academicos` (CHECK de estados, solo PostgreSQL) no está en la matriz original. Su prueba `tests/PostgresIntegrity/EstadosCheckPostgresTest.php` está escrita y **sin ejecutar**; pendiente de incluirla en una futura ejecución autorizada del perfil aislado.
