# Sprint 20 · Fase 2.1 — Auditoría offline y preparación de la validación PostgreSQL del Libro de Reclamaciones

31/10/2026. Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`. **Fase exclusivamente offline.** No hubo conexión a PostgreSQL, `pg_isready`, `probe`, `check`, Artisan, migraciones ni PHPUnit. **PostgreSQL NO ha sido validado.** Todo lo que sigue es lectura estática de código, SQL y documentación; las afirmaciones sobre el comportamiento del motor son razonadas, no medidas.

Niveles: **C** = comprobado leyendo código o fuente de Laravel instalado; **R** = razonado a partir de la semántica documentada de PostgreSQL (sin ejecutar); **NV** = no verificable offline.

## 1. Alcance y archivos inspeccionados

`PROJECT_CONTEXT.md` (Fases 1.2 y 2); migraciones `000030`/`000031` y las tres de cotizaciones (solo lectura de patrón); `Reclamacion`, `ReclamacionHistorial`, `ReclamacionEnvio`; `GestionReclamaciones`, `EnvioReclamaciones`, `NumeradorReclamaciones`, `Reclamaciones`, `AuditoriaReclamaciones`; las cinco clases de prueba nuevas; `phpunit.pgsql.xml`, `scripts/pgsql-test.php`, `config/pgsql-testing-database.php`, `.env.pgsql-testing.local.example` (el perfil local real **no** se abrió), `app/Support/Testing/{PostgresTestGuard,GuardedPostgresConnector,GuardedPostgresConnection,PostgresTestPreflight,ConcurrentTestSession}.php`, `tests/PostgresIntegrity/{ConcurrencyPostgresTest,Concerns/RequiresIsolatedPostgres}.php`, `tests/bootstrap-pgsql.php`; `docs/postgresql-testing-*.md`; los SQL de aprovisionamiento de `%LOCALAPPDATA%\HorusPgTest55433\evidencia` (`00-cluster.sql`, `10-main.sql`, `40-runner.sql`); `Illuminate\Database\Schema\PostgresBuilder` y `PostgresGrammar` instalados. No se leyeron cápsulas externas ni el contenido del perfil local.

## 2. Veredicto de compatibilidad estática

**CONDICIONADO — no ejecutar en PostgreSQL hasta resolver el hallazgo B1.** Las dos migraciones son sintácticamente compatibles con PostgreSQL 18 y su diseño de bloqueo es correcto bajo `READ COMMITTED` (R), pero `000030` **no es re-ejecutable tras `migrate:fresh`/`db:wipe`** (C), y el perfil aislado usa `migrate:fresh` en cada proceso PHPUnit con `RefreshDatabase`. Además varias pruebas nuevas son específicas de SQLite y no pueden entrar tal cual en el perfil PostgreSQL.

## 3. Hallazgos

| Id | Sev. | Nivel | Hallazgo |
|---|---|---|---|
| **B1** | **ALTA (bloqueante)** | C | `000030` crea la función con `CREATE FUNCTION reclamacion_historial_inmutable()` (sin `OR REPLACE`). `PostgresBuilder::dropAllTables()` solo ejecuta `drop table … cascade` (y `dropAllTypes`/`dropAllViews` por separado): **nunca elimina funciones**. El trigger muere con la tabla, pero la función permanece en `public`. La segunda `migrate:fresh` (o cualquier `DatabaseMigrations`, que reconstruye por prueba) fallaría con `42723 duplicate_function` y rompería **todas** las etapas que usen `RefreshDatabase` o `DatabaseMigrations` (etapas 3-10 del PG-TEST-05B-2A), no solo esta validación. `down()` sí la elimina, así que `migrate:rollback` + `migrate` funciona. Corrección propuesta (requiere autorización, no aplicada): `CREATE OR REPLACE FUNCTION`. |
| **B2** | ALTA | C | Pruebas nuevas no portables a PostgreSQL y que `phpunit.pgsql.xml` incluye por su suite «PostgreSQL isolated» (`tests/Unit` y `tests/Feature` completos): `ReclamacionesMigracionTest` consulta `sqlite_master`, espera el texto `UNIQUE` (PostgreSQL dice `duplicate key`) y encadena varios fallos en una misma transacción (tras el primer error PostgreSQL aborta la transacción: `25P02`). En `GestionReclamacionesTest`, `test_el_numerador_exige_transaccion_abierta` hace `rollBack()` de la transacción de la prueba. Las etapas con filtros fijos por nombre no las recogen, pero la etapa 10 (suite completa) sí. Deben hacerse sensibles al driver o excluirse antes de esa etapa. |
| **B3** | MEDIA | R | Columnas `uuid` en PostgreSQL: `where identificador = 'no-es-uuid'` lanza `22P02 invalid_text_representation` (en SQLite devuelve 0 filas y el servicio responde 404). `GestionReclamaciones::bloquear()` no valida el formato. La ruta del panel (Fase 4) debe usar `whereUuid` o el servicio debe validar con `Str::isUuid` antes de consultar. PostgreSQL además acepta UUID en mayúsculas; SQLite no. |
| **B4** | MEDIA | R | Sin `lock_timeout` en la aplicación, el contador anual retiene su bloqueo mientras dure toda la transacción del alta (reclamación, historial, envío, avisos internos, auditoría). Una petición colgada dentro de esa transacción bloquea todas las altas del mismo año. El perfil de pruebas impone `lock_timeout=2s` y `statement_timeout=5s`; **producción no**. Recomendación futura: `SET LOCAL lock_timeout` en la transacción de alta. |
| **B5** | MEDIA | R | La numeración es correcta solo en `READ COMMITTED` (el rol del perfil lo fija con `ALTER ROLE … default_transaction_isolation`; el guard exige `read committed`). Bajo `REPEATABLE READ`/`SERIALIZABLE`, el `FOR UPDATE` de una fila modificada tras el snapshot produciría `40001`: sin duplicados, pero la alta fallaría. La aplicación no fija el aislamiento (usa el del servidor). |
| **B6** | BAJA | R | La inmutabilidad es un trigger de fila: no cubre `TRUNCATE`, ni al propietario de la tabla ni a un superusuario (pueden desactivarlo o eliminarlo). Es defensa en profundidad, no garantía absoluta. Las pruebas con `DatabaseTruncation` pasan por `TRUNCATE`, por eso funcionan. |
| **B7** | BAJA | C | `es_menor` y `monto_no_aplica` llegan como cadenas desde `validated()`; en `normalizar()` el texto `"false"` no es `empty()` y se trataría como menor de edad (no rompe el CHECK, pero guardaría representante inexistente). Defecto de la Fase 3: convertir con `$request->boolean()`. No es específico del motor. |
| **B8** | INFO | C | `000031::down()` es intencionalmente un no-op (conserva permisos). No es reversible; aceptable y documentado. El `migrate:rollback` del wrapper revierte el **lote completo** (000027-000031 si se aplican juntas). |
| **B9** | INFO | C | Todas las bases de pruebas están en PostgreSQL 18.6; los nombres de índices y restricciones de ambas migraciones tienen ≤ 63 caracteres (el más largo, `reclamacion_historial_reclamacion_id_id_index`, 45). |

## 4. Compatibilidad PostgreSQL punto por punto

| # | Punto | Resultado | Nivel y notas |
|---|---|---|---|
| 1 | Creación/eliminación de triggers | Compatible, salvo B1 | `CREATE TRIGGER … BEFORE UPDATE OR DELETE … FOR EACH ROW EXECUTE FUNCTION` (PostgreSQL ≥ 11). `DROP TRIGGER IF EXISTS … ON tabla` no falla si la tabla no existe. La tabla elimina su trigger al borrarse. R |
| 2 | Funciones PL/pgSQL | Sintaxis válida | Dollar-quoting `$fn$` dentro de nowdoc PHP (sin interpolación), `TG_OP`, `IS NOT DISTINCT FROM`, `RAISE EXCEPTION`. `metadatos` es `json` (sin operador `=`): el cast a `text` es necesario y está. `plpgsql` es lenguaje de confianza con `USAGE` para `PUBLIC` (el aprovisionamiento no lo revoca); el runner tiene `CREATE` en `public`. R/C |
| 3 | CHECK | Compatibles | 11 CHECK por `ALTER TABLE … ADD CONSTRAINT`; booleanos (`es_menor = false`), fechas y `numeric` válidos; las nulidades se resuelven con `IS NULL` explícito. Los `enum()` de Laravel generan `varchar(255)` + `CHECK … IN (…)`. R |
| 4 | Índices parciales únicos | Compatible | `CREATE UNIQUE INDEX … WHERE tipo <> 'propuesta'` es válido. Con `NULL` no aplica (columnas `NOT NULL`). R |
| 5 | UUID | Compatible con B3 | `uuid()` → `uuid`. `Str::uuid()` genera minúsculas. R |
| 6 | FK y acciones | Compatible | `RESTRICT` (historial/envíos → reclamaciones) y `SET NULL` (actor/responsable → users). El `SET NULL` ejecuta un `UPDATE` interno sobre `reclamacion_historial` que **sí pasa** por el trigger; la condición lo admite (solo `actor_id` de no-nulo a nulo). R |
| 7 | Transacciones y rollback | Compatible | Todo en `DB::transaction`; un error SQL aborta la transacción en PostgreSQL (`25P02`): el código lanza (`abort(409)`, excepciones) tras los `catch` de `UniqueConstraintViolationException`, sin ejecutar más SQL en la transacción abortada. Dentro de una transacción externa Laravel usa `SAVEPOINT`. R |
| 8 | `SELECT … FOR UPDATE` | Compatible | `lockForUpdate()` sobre contador, reclamación y envío. El orden global de bloqueo: contador (solo en alta) → reclamación, y envío → reclamación en el flujo de correo; no hay ruta reclamación → envío existente (no hay ciclo). R |
| 9 | Numeración anual concurrente | Correcta en `READ COMMITTED` | Ver §5. R |
| 10 | Idempotencia | Compatible | `token_envio` UNIQUE más relectura bajo bloqueo; la carrera por el mismo token termina en `23505` → `UniqueConstraintViolationException` → relectura fuera de la transacción. R |
| 11 | Historial inmutable | Compatible, B1/B6 | Ver §6. R |
| 12 | Reversibilidad | `000030`: sí por `down()`; **no** por `migrate:fresh` (B1). `000031`: no (no-op deliberado). C |

Diferencias SQLite ↔ PostgreSQL relevantes: SQLite no aplica `lockForUpdate`; mensajes y códigos de error distintos (`ABORT` vs `23505/23514/P0001`); en PostgreSQL una sentencia fallida aborta la transacción; `ILIKE`/sensibilidad a mayúsculas; uuid estricto (B3); `timestamptz` real (el perfil fija `TimeZone=UTC`, de modo que las cadenas sin zona que Eloquent escribe se interpretan como UTC; fuera del perfil, sin `timezone` en la conexión, dependería de la zona del servidor — NV); `json` conserva el orden de claves (las pruebas con `assertSame` sobre arrays JSON son válidas); `decimal` devuelve cadena con escala (`150.00`).

## 5. Análisis de concurrencia de `NumeradorReclamaciones`

Secuencia (dentro de la transacción del alta): `INSERT … ON CONFLICT DO NOTHING` de la fila del año → `SELECT ultimo … FOR UPDATE` → `UPDATE ultimo = actual + 1` → número `%09d-%04d`.

- **Dos solicitudes simultáneas, mismo año, fila ya existente.** La segunda espera en el `FOR UPDATE` hasta que la primera termina. Tras el `COMMIT`, en `READ COMMITTED` PostgreSQL vuelve a evaluar la fila bloqueada y entrega la **versión confirmada** (`ultimo` ya incrementado). Resultado: números consecutivos, sin duplicados. R
- **Primera alta del año (fila inexistente).** Ambas ejecutan `ON CONFLICT DO NOTHING`; la segunda espera la resolución de la transacción que insertó la fila (índice único). Si la primera confirma, la segunda no inserta y pasa al `FOR UPDATE`; si la primera revierte, la segunda inserta la fila. Sin duplicados ni pérdida. R
- **Años distintos.** Filas distintas: no se bloquean entre sí; ninguna transacción toca dos años, así que no hay ciclo de bloqueo entre contadores. R
- **Rollback durante la asignación.** El `UPDATE` del contador forma parte de la misma transacción: al revertir, `ultimo` vuelve al valor anterior y el siguiente alta reutiliza el número (sin huecos, **sí es recuperable**). La excepción posterior (auditoría, FK, CHECK) revierte todo. La liberación del bloqueo ocurre al finalizar la transacción, incluso por excepción. R
- **Hueco posible solo si** el `COMMIT` del contador ocurre sin la reclamación (imposible: misma transacción) o si se borra manualmente una reclamación (no hay borrado en la aplicación).
- **Misma solicitud duplicada (mismo token).** La segunda se bloquea en el contador, o bien llega al `INSERT` y recibe `23505` (se revierte, incluido su incremento) y devuelve la reclamación ganadora. R
- **Bloqueos permanentes.** No hay espera circular entre contadores ni reclamaciones; la espera máxima es la duración de la transacción en curso. En el perfil de pruebas `lock_timeout=2s` convierte la espera larga en error `55P03` observable; en producción no hay tope (B4).
- **Lo que SQLite no demuestra:** nada de lo anterior. SQLite serializa por el bloqueo de escritura de la base y `lockForUpdate` es un no-op; las pruebas actuales demuestran la lógica de rollback e idempotencia, no la concurrencia.

## 6. Triggers, restricciones, índices y claves foráneas (ficha de auditoría)

| Objeto | Definición | Verificación futura |
|---|---|---|
| Función `reclamacion_historial_inmutable()` | PL/pgSQL; permite solo `UPDATE` con `actor_id` de no-nulo a `NULL` y el resto idéntico; cualquier otro `UPDATE`/`DELETE` → `P0001` | `pg_proc` (existe, propietario = runner), cuerpo; tras `down()` no existe |
| Trigger `reclamacion_historial_inmutable` | `BEFORE UPDATE OR DELETE … FOR EACH ROW`, tabla `reclamacion_historial` | `pg_trigger` (`tgenabled='O'`); sin trigger de `TRUNCATE` |
| CHECK `reclamaciones` (7) | `reclamacion_canal_hoja`, `_suspension_coherente`, `_menor_representante`, `_bien_par`, `_reclamo_con_bien`, `_monto_no_negativo`, `_respuesta_coherente` + enums (`canal`, `estado`, `tipo_documento`, `canal_respuesta`, `tipo`, `bien_tipo`) | `pg_constraint contype='c'`; cada violación → `23514` |
| CHECK `reclamacion_envios` (4) | `_intentos`, `_copia_por_correo`, `_propuesta_por_correo`, `_enviado_fecha` + enums | idem |
| Únicos | `identificador`, `numero`, `token_envio`, `clave`; índice parcial `reclamacion_envio_tipo_canal_unico … WHERE tipo <> 'propuesta'` | `pg_indexes.indexdef` |
| FK | historial/envíos → reclamaciones `RESTRICT` (`confdeltype='r'`); `actor_id`, `responsable_id` → users `SET NULL` (`'n'`) | `pg_constraint contype='f'` |
| Tipos | `uuid`, `char(64)`, `numeric(12,2)`, `timestamptz`, `date`, `json`, `boolean`, `smallint` | `information_schema.columns` |

## 7. Pruebas a ejecutar en la fase de ejecución (diseño; **no escritas ni ejecutadas**)

Se implementarían como clases nuevas en `tests/PostgresIntegrity/` (la carpeta ya está fuera de `phpunit.xml` y solo se ejecuta con el perfil), usando `RequiresIsolatedPostgres`. Las de DDL pueden usar `RefreshDatabase`; **las de concurrencia deben usar `DatabaseTruncation`** (con `RefreshDatabase` todo vive en una transacción sin confirmar y la otra sesión no ve los datos). Protocolo determinista del repositorio (`ConcurrencyPostgresTest`): sesión B toma el bloqueo, la sesión principal ejecuta con `lock_timeout` corto y debe quedar bloqueada (`55P03`), B confirma y la principal se repite.

**Clase `ReclamacionesDdlPostgresTest` (una sola sesión; cada intento fallido envuelto en `SAVEPOINT`):**

| Id | Prueba | Resultado esperado |
|---|---|---|
| D1 | Catálogo tras migrar: 4 tablas, índices, función, trigger, 11 CHECK, FK | coincide con §6 |
| D2 | Cada CHECK recibe una fila violatoria | `SQLSTATE 23514` |
| D3 | Unicidad (`identificador`, `numero`, `token_envio`, `clave`) y índice parcial (propuestas sucesivas sí; copia/respuesta duplicadas no) | `23505` / inserciones válidas |
| D4 | Historial: `UPDATE nota`, `UPDATE actor_nombre`, `DELETE` | `P0001`, mensaje `solo insercion` |
| D5 | Borrar un usuario actor: `actor_id` pasa a `NULL`, `actor_nombre`/`nota` intactos | el borrado tiene éxito |
| D6 | FK `RESTRICT`: borrar reclamación con historial/envíos | `23503` |
| D7 | `identificador = 'no-uuid'` | `22P02` (documenta B3) |
| D8 | `down()` de `000030`, comprobación de que no quedan función/trigger/índice, `up()` de nuevo | sin objetos residuales; reaplicación correcta |
| D9 | **`migrate:fresh` dos veces seguidas** | debe pasar (hoy falla por B1) |
| D10 | `000031` dos veces; roles distintos de admin sin cambios | idempotente |
| D11 | `TRUNCATE reclamacion_historial` | documenta B6 (permitido para el propietario) |

**Clase `ReclamacionesConcurrenciaPostgresTest` (dos sesiones, `DatabaseTruncation`):**

| Id | Escenario | Protocolo | Esperado |
|---|---|---|---|
| C1 | Mismo año, fila existente | B: `BEGIN`, `FOR UPDATE`, `UPDATE ultimo` sin confirmar. A: `siguiente(2026)` con `lock_timeout` 1 s | A: `55P03`. B confirma; A repite: número consecutivo; `ultimo` = 2 |
| C2 | Primera alta del año (fila inexistente) | B inserta la fila del año sin confirmar; A: `insertOrIgnore` | A espera (`55P03`); tras el commit de B, A obtiene el 2.º número |
| C3 | Años distintos | B retiene 2026; A: `siguiente(2027)` | A termina de inmediato: `000000001-2027` |
| C4 | Rollback tras asignar | A: `BEGIN`, `siguiente`, `ROLLBACK` | `ultimo` sin cambio; el siguiente alta reutiliza el número |
| C5 | Rollback de quien bloquea | B retiene y revierte con A bloqueada; A repite | A obtiene el número que B no consumió |
| C6 | Excepción tras asignar (`AuditLog::creating` lanza) | A: `registrarPublica` falla | sin reclamación/historial/envío; contador intacto; B adquiere el bloqueo de inmediato (sin bloqueo huérfano) |
| C7 | Sin duplicados | alternar 10 altas confirmadas por A y 10 por B | 20 números, únicos y contiguos; `numero` UNIQUE intacto |
| C8 | Mismo token, B retiene el contador | A bloqueada; B confirma; A repite | A devuelve `repetida=true` con la misma reclamación; 1 reclamación, 1 envío, contador = 1 |
| C9 | Mismo token, carrera tardía | durante el `INSERT` de A, B inserta y confirma la misma `token_envio` | A recibe `23505`, se revierte, devuelve la ganadora; contador sin hueco |
| C10 | Dos trabajadores sobre el mismo envío | B retiene `FOR UPDATE` del envío; A: `EnvioReclamaciones::enviar` con `Mail::fake()` | A bloqueada, sin correo; tras el commit de B (estado `enviado` o `procesando` reciente): `OMITIDO`, 0 correos |
| C11 | Orden de bloqueos envío → reclamación | B retiene la reclamación; A: `enviar` hasta `exito` | A espera, sin `40P01`; al confirmar B, A completa |
| C12 | FK + trigger | A inserta historial con `actor_id = X` sin confirmar; B borra el usuario X | B espera (`55P03`); tras A, el borrado deja `actor_id` en `NULL` |
| C13 | Aislamiento del rol | `SHOW transaction_isolation` en ambas sesiones | `read committed` |

Dentro de cada escenario las asignaciones se hacen con el código real (`NumeradorReclamaciones`, `GestionReclamaciones`, `EnvioReclamaciones`) y las sentencias de B con SQL equivalente a las de Laravel, nunca con servidores o hilos adicionales. Un estrés con procesos paralelos reales queda como opcional futuro (requiere preflight por proceso) y no sustituye a C1-C13.

## 8. Procedimiento de ejecución futura (preparado, **no autorizado, no ejecutado**)

Marcas: **[L]** solo lectura, no toca el servidor; **[C]** abre conexión PostgreSQL de solo lectura (`probe`), requiere autorización de conexión; **[E]** escribe en `horus_l2_test` (requiere consentimiento y autorización destructiva). Consola PowerShell **no elevada** (o `cmd.exe` del propietario), raíz del repositorio, **energía conectada**, **sin terminales de VS Code** abiertas. Ningún comando imprime contraseñas; el perfil local se manipula solo por hash y ACL.

### 8.0 Precondiciones (sin ejecutar nada de PostgreSQL)
1. **Correcciones previas autorizadas por separado:** B1 (`CREATE OR REPLACE FUNCTION`), y preparación de pruebas PostgreSQL de §7 + marcado de las pruebas SQLite-only (B2). Sin B1 la validación **debe detenerse** en D9.
2. Congelar documentos y código, y **recalcular la línea base Git**: las cápsulas existentes (`pgt05-consolidacion-ready`, `pgt05b2a-etapa2-capsule`) conservan una huella anterior y no deben editarse ni reutilizarse. Crear una cápsula nueva e independiente (p. ej. `pgt05b2a-reclamaciones-capsule`) con su `release.json`, filtro, evidencias y verificador, como en el plan de la etapa 3.
3. Reenumerar offline (`--list-tests`, bootstrap `vendor/autoload.php`, **nunca** el bootstrap PostgreSQL) y comprobar que los filtros de las etapas 1-7 no recogen las clases nuevas de Reclamaciones.

### 8.1 Identidad esperada (nueve campos del preflight + sesión)
| Campo | Valor |
|---|---|
| `server_version` | `18.6` |
| `system_identifier` | `7702263743609677164` |
| `cluster_name` | `horus_pgt_55433` |
| `host` | `127.0.0.1` |
| `port` | `55433` (el `55432` de S7 se rechaza expresamente) |
| `database` | `horus_l2_test` (la de restauración no se usa) |
| `username` | `horus_l2_test_runner` |
| `transaction_read_only` | `on` |
| `default_transaction_read_only` | `on` |
Más, en la sesión de sonda: `timezone = UTC`, `search_path = public`, aislamiento `read committed`, marcador `horus-l2-destructive-tests` presente y no escribible por el runner, rol sin privilegios peligrosos, `application_name = horus_l2_tests`. El conector aplica `statement_timeout=5000` y `lock_timeout=2000` ms.

### 8.2 Etapa R0 — verificaciones previas
```powershell
# [L] energía (la etapa se detiene si es batería)
Add-Type -AssemblyName System.Windows.Forms
[System.Windows.Forms.SystemInformation]::PowerStatus.PowerLineStatus      # debe ser Online
(Get-CimInstance -Namespace root\wmi -ClassName BatteryStatus -ErrorAction SilentlyContinue).PowerOnline   # True

# [L] estado Git y evidencias
git rev-parse --abbrev-ref HEAD; git rev-parse HEAD                      # anderson / 038895280000f57d39c698e40d3436fecc0d716f
git status --short; git stash list; git diff --cached --name-only        # índice vacío; dos stashes intactos
$ev = Join-Path $env:LOCALAPPDATA 'HorusPgTest55433\evidencia\pgt05\reclamaciones-20261031'
New-Item -ItemType Directory -Force $ev | Out-Null

# [L] perfil local: solo hash y consentimiento vacío (no se imprime el contenido)
Get-FileHash .env.pgsql-testing.local -Algorithm SHA256 | Select-Object Hash
(Select-String -Path .env.pgsql-testing.local -Pattern '^HORUS_PG_TEST_CONFIRM=').Line   # debe ser 'HORUS_PG_TEST_CONFIRM='

# [L] procesos: usar el detector de la cápsula (Ambiguous y ActiveTest bloquean)
#     .\validate-offline.ps1   (de la cápsula nueva)  — sin -ExecuteAuthorizedStage1
Get-CimInstance Win32_Process | Where-Object { $_.Name -match '^(php|phpunit|postgres|pg_ctl|node)' } |
    Select-Object ProcessId, ParentProcessId, Name, CreationDate        # solo el postmaster 55433 si está vivo
```
Detener si hay procesos PHP/PHPUnit ajenos, energía en batería, consentimiento no vacío o hash de perfil distinto del registrado.

### 8.3 Etapa R1 — disponibilidad e identidad (sin consentimiento) [C]
```powershell
$bin = 'C:\Program Files\PostgreSQL\18\bin'
& "$bin\pg_isready.exe" -h 127.0.0.1 -p 55433                           # [C] "accepting connections"
php scripts/pgsql-test.php probe *> "$ev\R1-probe.txt"                   # [C] solo lectura; exit 0
# Verificar en R1-probe.txt: database, username, port 55433, cluster_name, system_identifier, transaction_read_only=on, timezone=UTC
```
Si el servidor no responde, el arranque (`pg_ctl`/servicio `postgresql-horus-l2-test`) es una operación **independiente** y requiere su propia autorización.

### 8.4 Etapa R2 — diagnóstico previo del esquema (solo lectura) [C]
Usar el patrón de diagnóstico de solo lectura ya empleado (`pgt05b1-diagnostico.php`, sondas del conector en modo `READ_ONLY_PROBE`; no `artisan`, que exige consentimiento): listar `migrations` aplicadas, `pg_proc` de `reclamacion_historial_inmutable`, `pg_class` de las cuatro tablas y `permissions LIKE 'reclamaciones.%'`. Registrar en `$ev\R2-esquema-previo.txt`. Esperado antes de migrar: sin tablas ni función de reclamaciones.

### 8.5 Etapa R3 — consentimiento y migraciones [E]
```powershell
# [E] activar el consentimiento SOLO en el perfil local (valor no secreto)
(Get-Content .env.pgsql-testing.local) -replace '^HORUS_PG_TEST_CONFIRM=$','HORUS_PG_TEST_CONFIRM=AUTHORIZE_HORUS_L2_DESTRUCTIVE_TESTS' | Set-Content .env.pgsql-testing.local -Encoding ascii
try {
  php scripts/pgsql-test.php check                                       # [E] habilita escritura; sin DDL/DML
  php scripts/pgsql-test.php artisan migrate:status *> "$ev\R3-status-0.txt"
  php scripts/pgsql-test.php artisan migrate        *> "$ev\R3-migrate.txt"     # aplica las pendientes incl. 000030/000031
  php scripts/pgsql-test.php artisan migrate:status *> "$ev\R3-status-1.txt"
  php scripts/pgsql-test.php artisan migrate:rollback *> "$ev\R3-rollback.txt"   # revierte el LOTE completo (000027-000031 si fueron juntas)
  php scripts/pgsql-test.php artisan migrate        *> "$ev\R3-migrate-2.txt"
  php scripts/pgsql-test.php artisan migrate:fresh  *> "$ev\R3-fresh-1.txt"
  php scripts/pgsql-test.php artisan migrate:fresh  *> "$ev\R3-fresh-2.txt"     # prueba B1: debe terminar con éxito
} finally {
  # [E→L] retirada del consentimiento, siempre
  (Get-Content .env.pgsql-testing.local) -replace '^HORUS_PG_TEST_CONFIRM=.*$','HORUS_PG_TEST_CONFIRM=' | Set-Content .env.pgsql-testing.local -Encoding ascii
}
```
En la práctica la ejecución real debe lanzarse desde la **cápsula** con su envoltorio, Job Object, detector de procesos y retirada en `finally` verificada, no con este bloque suelto; el bloque documenta la secuencia y los comandos permitidos (`artisan` solo acepta el nombre de comando sin opciones). Detenerse ante el primer fallo; no reintentar ni relajar guardas.

### 8.6 Etapa R4 — pruebas de integración y concurrencia [E]
Con el consentimiento activo mediante la misma cápsula (otra ventana de consentimiento separada si el propietario lo decide):
```powershell
php scripts/pgsql-test.php test --filter=ReclamacionesDdlPostgresTest --stop-on-failure *> "$ev\R4-ddl.txt"
php scripts/pgsql-test.php test --filter=ReclamacionesConcurrenciaPostgresTest --stop-on-failure *> "$ev\R4-concurrencia.txt"
```
(`--filter` y `--stop-on-error/--stop-on-failure` son las únicas opciones que admite `PostgresTestArguments`.) `DatabaseTruncation` deja las tablas vacías al terminar; `RefreshDatabase` deja el esquema reconstruido.

### 8.7 Etapa R5 — verificación posterior y cierre
```powershell
php scripts/pgsql-test.php probe *> "$ev\R5-probe-final.txt"             # [C] de nuevo solo lectura, misma identidad
(Select-String -Path .env.pgsql-testing.local -Pattern '^HORUS_PG_TEST_CONFIRM=').Line   # vacío
Get-FileHash .env.pgsql-testing.local -Algorithm SHA256                  # idéntico al inicial
Get-CimInstance Win32_Process | Where-Object { $_.Name -match '^(php|phpunit)' }          # ninguno; Job Active=0 en el registro de la cápsula
git status --short; git stash list; git rev-parse HEAD                   # huella Git igual a la línea base
Get-ChildItem $ev -File | Get-FileHash -Algorithm SHA256 | Select-Object Hash, Path |
    Export-Csv "$ev\sha256-evidencia.csv" -NoTypeInformation             # evidencias SHA-256 (sin secretos)
```
Limpieza de procesos: si quedara algún descendiente, solo el cierre verificado de la cápsula (`taskkill /T /F` con espera y comprobación de `ActiveProcesses=0`); el cierre de la ventana o el retiro del consentimiento **no** revocan una sesión SQL abierta.

## 9. Criterios de aceptación de la ejecución futura

1. Precondiciones R0 cumplidas: energía conectada, consentimiento vacío al inicio, perfil con hash registrado, sin procesos ajenos, huella Git igual a la línea base nueva.
2. Nueve campos de identidad exactos (§8.1) en R1, R2 y R5.
3. `migrate` aplica 000030 y 000031 sin error; `migrate:rollback` + `migrate` correctos; **dos `migrate:fresh` consecutivos correctos** (B1 resuelto).
4. Catálogo (D1) idéntico a §6: 4 tablas, 11 CHECK, función y trigger únicos, índices y FK con acciones esperadas, sin objetos residuales tras `down()`.
5. D1-D11 y C1-C13 con `OK` sin omitidas, riesgosas, incompletas ni advertencias; número de pruebas y aserciones **medido y registrado** (hoy desconocido).
6. Ningún número duplicado ni hueco en C4-C9; ningún `40P01` (deadlock) ni espera > `lock_timeout` no prevista.
7. Consentimiento retirado, hash del perfil idéntico, `probe` final correcto, Job `Active=0`, sin procesos PHP, evidencias con SHA-256 registrados.
8. Cualquier discrepancia se clasifica como diferencia de motor, defecto de prueba o defecto de aplicación **antes** de continuar; ningún criterio se relaja para que pase.

## 10. Comandos que requieren autorización independiente

| Comando / acción | Marca | Autorización |
|---|---|---|
| Corregir B1 y preparar pruebas §7 (cambia migración y archivos de prueba) | — | Fase de corrección de código |
| `pg_isready`, `php scripts/pgsql-test.php probe`, diagnóstico R2 | [C] | Preflight operativo de solo lectura |
| Arranque del servidor (servicio/`pg_ctl`) | — | Operación separada |
| Activar `HORUS_PG_TEST_CONFIRM`, `check`, `artisan migrate / migrate:rollback / migrate:fresh`, `test` | [E] | Ejecución destructiva (por etapa) |
| Retirada del consentimiento y limpieza de procesos | [E→L] | Incluida en la ejecución, siempre |

## 11. Estado y límites de esta fase

- Solo se crearon este documento y la sección de `PROJECT_CONTEXT.md`. No se modificaron migraciones, modelos, servicios, configuración, pruebas, frontend ni otros módulos; sin staging, commits ni push.
- **No se afirma ninguna validación en PostgreSQL.** B1 es una conclusión de lectura del código de Laravel instalado y de la semántica de PostgreSQL; debe confirmarse en la ejecución (D9).
- La evidencia SQLite (126 pruebas dirigidas de la Fase 2, 1397 en la suite) no cubre concurrencia, DDL ni triggers de PostgreSQL.

---

## 12. Resultado de la corrección offline (31/10/2026)

**Ejecutado:** cambios de código y pruebas, PHPUnit en SQLite en memoria, Larastan, Pint, `git diff --check`, enumeración `--list-tests` (bootstrap `vendor/autoload.php`, sin arrancar Laravel) y validaciones offline de la cápsula nueva. **No ejecutado:** ninguna conexión PostgreSQL (`pg_isready`, `probe`, `check`), migración real, prueba del perfil PostgreSQL ni cambio del consentimiento. **PostgreSQL sigue sin validar**; las 40 entradas exclusivas de PostgreSQL están preparadas, no probadas.

### 12.1 Hallazgos resueltos

| Id | Causa raíz | Corrección | Cobertura |
|---|---|---|---|
| B1 | `PostgresBuilder::dropAllTables()` (Laravel instalado) solo emite `drop table … cascade`; las funciones de `public` sobreviven a `migrate:fresh`/`db:wipe`, y 000030 usaba `CREATE FUNCTION` | `CREATE OR REPLACE FUNCTION` en 000030; `down()` documentado: trigger → tablas → función, sin `CASCADE` (si algo dependiera de la función, el rollback falla en lugar de borrarlo) | D1, D8, D9 (`db:wipe` conserva la función; dos `migrate:fresh` seguidos; una sola función y un solo trigger; inmutabilidad activa) + prueba común de rollback/reaplicación por catálogo |
| B2 | Pruebas comunes con `sqlite_master`, texto `UNIQUE`, varios fallos en una transacción y `rollBack()` de la transacción de la prueba | Cada violación esperada en su propio SAVEPOINT; `UniqueConstraintViolationException` en vez del texto; SQLSTATE exigido en PostgreSQL (`23505`, `23514`, `23503`, `P0001`); catálogo de objetos por motor; la prueba del numerador sin transacción pasa a `tests/Unit/NumeradorReclamacionesTest` (sin base de datos) | Ninguna aserción eliminada; `ReclamacionesMigracionTest` gana comprobaciones (objetos residuales, inmutabilidad tras reaplicar, `000031::down()`) |
| B3 | Columna `uuid`: un literal no UUID produce `22P02` y aborta la transacción en PostgreSQL | `GestionReclamaciones::bloquear()` rechaza con `ModelNotFoundException` antes de consultar si `! Str::isUuid()`, y normaliza a minúsculas | Comunes: UUID inexistente, cadenas malformadas sin ninguna consulta a `reclamaciones`, mayúsculas; D7: `22P02` en SQL crudo y servicio sin error |
| B4/B5 | El contador retiene su bloqueo durante la transacción del alta; la corrección depende de `READ COMMITTED` | Sin cambio de producción (decisión: no se altera el aislamiento ni se añaden bloqueos globales; un `lock_timeout` de aplicación cambiaría la disponibilidad del libro y queda como decisión del propietario). `NumeradorReclamaciones` acepta una conexión opcional solo para ejecutar el mismo código en la segunda sesión | C1-C13 preparadas (dos sesiones reales) |
| B7 | `empty("false")` es falso en PHP | Servicio: `es_menor` con `FILTER_VALIDATE_BOOLEAN` y rechazo de valores no booleanos; `StoreReclamacionRequest::datosReclamacion()` entrega `boolean()` real. Para la Fase 3: el controlador debe pasar `datosReclamacion()` (nunca `all()`/`input()`) | Comunes: `"false"`, `"0"`, `"true"`, valor ambiguo rechazado sin escribir; validación de `"si"`, `"false"`, `"2"` |
| 000031 | `down()` no-op | **Se conserva** por decisión justificada: borrar permisos revocaría en silencio concesiones hechas después por el propietario (decisión C1) y el seeder los vuelve a definir; `up()` es idempotente | Común y D10: `up` ×2, `down`, `up` sin duplicados ni recreaciones; concesión manual a `coordinador` preservada |

### 12.2 Pruebas PostgreSQL preparadas

- `tests/PostgresIntegrity/ReclamacionesDdlPostgresTest.php` (24 entradas, `RefreshDatabase`): D1 catálogo (tablas, función única, trigger `tgtype=27` sin TRUNCATE, 11 CHECK con nombre y los de enum, índice parcial, únicos, FK `r`/`n`, tipos, `TimeZone=UTC`); D2 13 violaciones CHECK con su nombre + CHECK de envíos; D3 unicidades e índice parcial; D4/D5 inmutabilidad y `SET NULL`; D6 `RESTRICT`; D7 `uuid`; D8 `down`/`up`; D9 B1; D10 permisos; D11 `TRUNCATE` (límite documentado).
- `tests/PostgresIntegrity/ReclamacionesConcurrenciaPostgresTest.php` (16 entradas, `DatabaseTruncation`, `ConcurrentTestSession`): C1 contador existente; C2 contador inexistente; C3 años distintos; C4 rollback propio; C5 rollback del primer y del segundo trabajador y recuperación del bloqueo; C6 excepción tras asignar sin bloqueo huérfano; C7 20 altas alternadas sin duplicados ni huecos; C8 mismo token con contador retenido; C9 carrera tardía `23505` → `repetida`; C10 dos trabajadores sobre el mismo envío (sin correo duplicado); C11 orden envío → reclamación sin `40P01` y responder por carta sin esperar; C12 FK + trigger al borrar el actor; C13 `read committed`, `lock_timeout=2s`, `statement_timeout=5s` y dos backends.
- `tests/PostgresIntegrity/Concerns/ReclamacionesPostgres.php`: savepoints con SQLSTATE, espera con `lock_timeout` corto **de sesión** restaurado al valor previo, `conRecepcion()` (activa el flag y el correo solo durante la llamada y restaura la configuración; necesario porque el guard del perfil exige `mail.default=array` al abrir conexiones).
- **Coordinación:** dos sesiones en el mismo proceso PHP, protocolo determinista del repositorio (B retiene; A espera hasta `55P03`; B confirma o revierte; A repite). Son bloqueos reales entre dos backends; no hace falta `pcntl` ni procesos adicionales en Windows. Un estrés con N procesos no se preparó (requeriría un preflight por proceso) y no es necesario para estas garantías.
- **Escenario no reproducible tal cual:** C11 en producción no fallaría (sin `lock_timeout` esperaría); en el perfil termina en `55P03` después de enviar el correo y deja el envío `procesando`. La prueba lo registra como la ventana documentada de posible duplicado tras caducar la reserva (10 min).

### 12.3 Resultados medidos (SQLite en memoria)

| Comprobación | Resultado |
|---|---|
| Pruebas de reclamaciones (`--filter`), tras los cambios | 135 pasadas, 730 aserciones |
| Suite completa `php artisan test --compact` | **1406 pasadas, 1 omitida (preexistente), 9011 aserciones, exit 0** |
| Larastan nivel 6 (`phpstan.neon.dist`) | Sin errores |
| PHPStan nivel 5 extra sobre las pruebas nuevas (configuración de scratch, fuera del repositorio) | Sin errores tras corregir 3 avisos de tipos en pruebas |
| Pint `--test` (11 archivos tocados) | Passed |
| `git diff --check` | Sin errores (solo avisos LF/CRLF conocidos) |
| Enumeración offline de la selección | 175 entradas, 115 métodos, 8 clases (135 comunes + 40 PostgreSQL) |

**No medido:** cualquier comportamiento en PostgreSQL (DDL, función, trigger, CHECK, SQLSTATE, bloqueos, aislamiento, concurrencia), correo real, cola real.

### 12.4 Cápsula nueva

`%LOCALAPPDATA%\HorusPgTest55433\capsulas\pgt05b2a-etapa8-reclamaciones-capsule` (fuera del repositorio y de carpetas temporales). Derivada de `pgt05b2a-etapa6-capsule-r3` (comprobado: sin cambios en el origen). Conserva detector de procesos, Job Object, plazos 180/720/900 s, consentimiento explícito con retirada en `finally`, nueve campos de identidad, inventario de evidencias (150 archivos históricos) y pines SHA-256 de herramientas, migraciones 000030/000031, servicios y las 8 clases + 2 traits de prueba. Validaciones offline: ver §12.5. **La línea base Git definitiva, el hash del manifiesto y el del lanzador se fijaron después de congelar este documento y `PROJECT_CONTEXT.md`** (cualquier edición del repositorio cambia la huella): constan en `release.json`, en `preparation/validation-summary.json` de la cápsula y en el informe de entrega.

### 12.5 Validaciones offline de la cápsula

| Validación (offline) | Resultado |
|---|---|
| `tools/verify-static.py` (selección, parseo estático de las 8 clases y datasets, filtro, operaciones destructivas permitidas, sin configuración permanente del servidor, scripts, plazos, escalera de salida, manifiesto) | 54/54 |
| `tools/verify-ast.ps1` (parseo de los 7 scripts PowerShell) | 0 errores de parseo |
| `tools/test-detector.ps1` (política de procesos con registros fabricados) | 12/12 |
| `tools/test-wrapper-parts.ps1` (consentimiento byte a byte en perfiles **falsos**; Job Object) | 8/8 |
| `tools/test-wrapper-timeout.ps1` (envoltorio real con guardián falso, plazos y precedencia de códigos) | 43/43, tras corregir en la prueba 3 textos esperados de la etapa 6 (`kind=etapa-6`) que el renombrado no había cubierto; el envoltorio no cambió |
| `validate-offline.ps1` con la línea base provisional (calculada después de congelar el código y antes de este documento) | Manifiesto, pines internos y externos, `release.json`, selección, hash del perfil, consentimiento vacío, PHP y 150 evidencias históricas: **OK**. Después **se detuvo, como debe**, en el detector de procesos: un terminal integrado de VS Code (`powershell.exe` hijo de `Code.exe`) abierto por el propietario → «STOP; no probe allowed». No se cerró ningún proceso |
| Réplica de solo lectura de las comprobaciones posteriores al detector | Rama, HEAD, índice vacío y bytes, stashes, sin variables de proceso, `.env` sin consentimiento, huella = línea base, sin evidencias propias: **OK** |

**Condición previa a cualquier autorización:** cerrar los terminales de VS Code y repetir `validate-offline.ps1 -ManifestSha256 <hash>` hasta obtener `OFFLINE READY: stage 8; … 175 tests pinned.`

### 12.6 Procedimiento de ejecución (requiere autorización exclusiva del propietario)

Ventana `cmd.exe` independiente, energía conectada, sin terminales de VS Code, consentimiento vacío:

```
powershell -NoProfile -ExecutionPolicy Bypass -File "%LOCALAPPDATA%\HorusPgTest55433\capsulas\pgt05b2a-etapa8-reclamaciones-capsule\run-authorized-stage8.ps1" -ExecuteAuthorizedStage8 -Baseline <baseline de release.json>
```

El lanzador ejecuta, en este orden y deteniéndose ante el primer rechazo: manifiesto y pines → `validate-offline` (perfil, consentimiento vacío, procesos, evidencias, Git, huella) → `pg_isready` [C] → identidad de nueve campos [C] → `probe` [C] → enumeración offline de los 175 nombres → `validate-offline` → envoltorio [E] (consentimiento, guardas, `php scripts/pgsql-test.php test --filter=… --stop-on-error`, retirada del consentimiento en `finally`) → cierre offline, `probe` e identidad finales, preservación de evidencias y `sha256.json`. Aceptación: `OK (175 tests, N assertions)` con N medido, sin risky/omitidas/fallos/errores, consentimiento vacío, perfil idéntico, Job `Active=0`, huella igual a la línea base.

### 12.7 Riesgos pendientes

- PostgreSQL sin validar: las 40 entradas PostgreSQL pueden revelar diferencias de motor no previstas (p. ej. nombres de restricciones de enum, mensajes, orden de catálogo); clasificar cualquier fallo antes de corregir.
- B4 (sin `lock_timeout` de aplicación) y B5 (dependencia de `READ COMMITTED`) quedan como decisiones del propietario; están cubiertos por C1-C13, no mitigados en producción.
- Ventana de duplicado del correo si el proceso muere entre el envío y su registro (C11).
- B6: la inmutabilidad no cubre `TRUNCATE` ni al propietario de la tabla (D11).
- La huella Git de la cápsula se invalida con cualquier edición del repositorio; una ejecución futura exige recalcularla en una cápsula nueva si algo cambia.
- El endpoint público (Fase 3) debe usar `datosReclamacion()` y validar `boolean`; sigue sin implementar y el flag sigue apagado.

### 12.8 SHA-256 de los archivos del repositorio afectados (al congelar el código)

| Archivo | SHA-256 |
|---|---|
| `database/migrations/2026_10_30_000030_create_reclamaciones_tables.php` | `F776EB5E5515BD8F11E738063A3499E88E58B1F42F15A7C702E92F382261DD9D` |
| `database/migrations/2026_10_30_000031_add_reclamaciones_permissions.php` | `BFA51F431563B5AB3E57F075F3F651CCFA25BB1558831EA684F1031AB334EBC0` |
| `app/Services/NumeradorReclamaciones.php` | `FCDA91868A96E44BB75B9E86E10ACE8551B523C84D1CDECBF72FCD09E6676DCF` |
| `app/Services/GestionReclamaciones.php` | `2E0105B2F185C6502FCF7989AFD0F5629167EDFA8A333CEBFAAD25E99635677D` |
| `app/Http/Requests/StoreReclamacionRequest.php` | `FA289D89CD2720E800B39A96F9F70337F054BFCF7D3AEF09F62440EC116ABFC3` |
| `tests/Feature/ReclamacionesMigracionTest.php` | `F3C61757719524D3E17F53612956E9FB0A3AA87364EB2ABA371DF61A16B1472B` |
| `tests/Feature/GestionReclamacionesTest.php` | `33EA07C956C1DD4A480569455185FF6DB02732FC1A28FE226830234166C7223D` |
| `tests/Feature/ReclamacionRequestsTest.php` | `2C86EFF9197D72894D733EB2C1714EB3CBD5CEFC6C23355CB50A25B2121A46EF` |
| `tests/Unit/NumeradorReclamacionesTest.php` | `9DD54DB21557BD565F46303650DD402F90D8AFF4FD438C40B23406C1AE4A314A` |
| `tests/PostgresIntegrity/ReclamacionesDdlPostgresTest.php` | `9C9A99EC4545A0FD4365176A11E16B6B23F6BD325E72FC5BD4781AF9BC272F27` |
| `tests/PostgresIntegrity/ReclamacionesConcurrenciaPostgresTest.php` | `85BA8156B39FAA3FB9A1C91220501702D4F2EAE29B4E462B95F4CF4011B92ED3` |
| `tests/PostgresIntegrity/Concerns/ReclamacionesPostgres.php` | `7F7E2082F6E12509CE1073D32241B645B61F68C54B60C23FD01D6D080560E165` |
| `tests/Support/PreparaReclamaciones.php` | `CD1049FD6E5CF990277289D8BA84A54059A1478033235EF811666814AAB2D78D` |
| `database/migrations/2026_10_29_000027_create_solicitudes_cotizacion_tables.php` | `C3E8C961ABC7F0B4B06F90F3E97B2052A99250A8554AF5C0A1307BEE730E0255` |
| `database/migrations/2026_10_29_000028_add_cotizaciones_permissions.php` | `E341315AB98A28A7BEA2E08CC929FA7A2CFFD9CA2EB181BEF9FE89205C55B0D7` |
| `database/migrations/2026_10_29_000029_add_origen_to_solicitudes_cotizacion.php` | `193BDCA1C178ADEEA826323723630C0E004B057308DD8EAF324689F166619AA1` |
