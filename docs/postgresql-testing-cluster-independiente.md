# PG-TEST-01 — clúster PostgreSQL 18 desechable e independiente: inspección y diseño

29/10/2026. **Solo inspección de lectura y propuesta.** No se creó ningún directorio ni clúster, no se inició ni detuvo PostgreSQL, no se ejecutó SQL ni `psql`, no hubo conexiones a `horus_group`, `horus_group_ensayo` ni S7, y no se tocaron HBA, servicios, contraseñas, `.env`, el perfil local, migraciones ni salvaguardas. Sin commits ni push.

Este documento sustituye como vía preferente la recuperación de S7 (R2-B/R2-C/R2-C.1 de [postgresql-testing-validacion-final.md](postgresql-testing-validacion-final.md)), que queda **aparcada, no ejecutada**. Retoma y actualiza la «Opción B» de [L2.3 §2](postgresql-l2.3-testing.md).

## 1. Estado de partida comprobado

| Elemento | Resultado |
| --- | --- |
| Rama / HEAD | `anderson` / `038895280000f57d39c698e40d3436fecc0d716f` (coincide con el corto `0388952` reportado) |
| Índice | Vacío |
| Árbol | 5 versionados modificados y trabajo sin seguimiento de Sprints 19/19.1/19.2 y restricción comercial, todo preexistente; no se tocó |
| Stashes | `52e2f296e243cacf777f48d8148b0a72e68fc93c`, `d69d6caa1c7b2a18c9c2aa962bc74a473bbdcc82`, intactos |
| Perfil local | `.env.pgsql-testing.local` **no existe**; solo la plantilla `.example` |

## 2. Instalaciones, versiones, procesos y puertos

### Binarios (una sola instalación)

Única carpeta bajo `C:\Program Files\PostgreSQL`: `18`. Ningún binario PostgreSQL está en `PATH` (se deben usar rutas absolutas). No hay variables `PG*` de usuario ni de máquina, ni `pgpass.conf`/`psqlrc.conf` en `%APPDATA%\postgresql`.

| Binario (`C:\Program Files\PostgreSQL\18\bin\`) | `--version` |
| --- | --- |
| `initdb.exe`, `pg_ctl.exe`, `postgres.exe`, `psql.exe`, `pg_dump.exe`, `pg_isready.exe`, `pg_restore.exe` | 18.6 |
| `pg_controldata.exe`, `pg_checksums.exe`, `createdb.exe` | presentes |
| Versión de archivo `postgres.exe` / `libpq.dll` | 18.6 / 18.6 |

### Servicios y procesos

| Servicio | Estado | Cuenta | `pg_ctl` → `postmaster` | Datos |
| --- | --- | --- | --- | --- |
| `postgresql-x64-18` (cotidiano) | Running / Automatic | `NT AUTHORITY\NetworkService` | PID 4972 → 5932 (+9 hijos) | `C:\Program Files\PostgreSQL\18\data` |
| `postgresql-horus-l2-test` (S7) | Running / Manual | `NT SERVICE\postgresql-horus-l2-test` | PID 2548 → 19800 (+9 hijos) | `C:\pgdata\horus_l2_test` |

20 procesos `postgres.exe` en total, todos atribuibles por cadena de PID padre a uno de los dos servicios. El token no elevado no puede leer su propietario ni su línea de comandos (no es un error: es el aislamiento esperado).

### Puertos

| Puerto | Escucha | Proceso |
| --- | --- | --- |
| 5432 | `0.0.0.0` y `::` | 5932 (cotidiano). Hallazgo ya conocido: escucha fuera de loopback |
| 55432 | `127.0.0.1` | 19800 (S7) |
| 55433 | **libre** (sin listener; conexión de prueba rechazada) | — |

Rango dinámico TCP de Windows: 49152–65535, que **incluye** 55432 y 55433. Exclusiones vigentes (Hyper-V/WinNAT): 49896–49995, 50000–50059, 50060–50159, 50686–50785, 51186–51285, 51386–51485, 56415–56514, 56777–56876, 62756–62855, 65188. 55433 no está excluido hoy; las exclusiones pueden cambiar al reiniciar, por lo que se comprueba antes de cada arranque.

## 3. Espacio y permisos del usuario actual

- `C:` NTFS: **259,2 GB libres** de 474,7 GB. Un clúster con dos bases de prueba ocupa del orden de 100–300 MB; el espacio no es una restricción.
- Usuario: `laptop-onk47rs6\pct40t`. Miembro de `BUILTIN\Administradores`, pero el token actual es de **integridad media** (UAC filtrado): `IsInRole(Administrator) = False`, solo cinco privilegios básicos. Coincide con R2-C.1.
- `%LOCALAPPDATA%` (`C:\Users\PCT40T\AppData\Local`): Allow FullControl para SYSTEM, Administradores y el propio usuario. El usuario puede crear directorios allí sin elevar.
- `C:\pgdata` contiene solo `horus_l2_test` (S7, ACL protegida). No se usará.

### Viabilidad sin administrador

**Viable según la evidencia estática; no probada en ejecución.**

- `initdb`, `pg_ctl start/stop` y `postgres` se pueden ejecutar como el usuario actual sobre un directorio propio; no requieren servicio ni SCM. PostgreSQL rechaza arrancar con un token administrativo efectivo; el token actual no lo es, y `pg_ctl` además crea un token restringido.
- Escuchar en `127.0.0.1` no necesita elevación ni regla de cortafuegos de entrada.
- Consecuencias de no ser servicio: el servidor se detiene al cerrar la sesión de Windows o reiniciar; se arranca y detiene de forma explícita en cada ventana de pruebas.
- Única acción que siempre requeriría elevación: cualquier operación sobre S7 o el servicio cotidiano. El plan **no** incluye ninguna.

## 4. Restricciones del arnés de pruebas (contraste con código)

[`scripts/pgsql-test.php`](../scripts/pgsql-test.php), [`PostgresTestGuard`](../app/Support/Testing/PostgresTestGuard.php), [`GuardedPostgresConnector`](../app/Support/Testing/GuardedPostgresConnector.php), [`TestDatabaseSafety`](../app/Support/Testing/TestDatabaseSafety.php), [`bootstrap/app.php`](../bootstrap/app.php), [`config/pgsql-testing-database.php`](../config/pgsql-testing-database.php), [`phpunit.pgsql.xml`](../phpunit.pgsql.xml):

| Restricción | Valor fijo en código | Compatible con un clúster nuevo |
| --- | --- | --- |
| Host | `127.0.0.1` literal (configuración, entorno y `inet_server_addr()`) | Sí, con `listen_addresses = '127.0.0.1'` |
| **Puerto** | Inspección PG-TEST-01: **`'55432'` literal** en 3 puntos de `PostgresTestGuard` (configuración, entorno, `inet_server_port()`). **Resuelto en PG-TEST-02a (§13)**: único destino `PostgresTestGuard::PORT = '55433'`, 55432 rechazado expresamente | Sí, tras PG-TEST-02a |
| Bases / runners | `horus_l2_test` → `horus_l2_test_runner`, `horus_l2_restore_test` → `horus_l2_restore_test_runner` | Sí, reutilizando esos nombres en el clúster nuevo |
| Marcador | `horus_test_control.authorization`, `purpose = 'horus-l2-destructive-tests'`, OID de la base, no escribible por el runner | Sí, recreándolo igual que S6 |
| Rol del runner | Sin `SUPERUSER/CREATEDB/CREATEROLE/REPLICATION/BYPASSRLS`, **0 membresías**, sin `CONNECT` a ninguna otra base | Sí, revocando `CONNECT` de `PUBLIC` en `postgres`, `template0/1` |
| Sesión | `search_path=public`, `TimeZone=UTC`, `read committed`, sesión de solo lectura hasta superar la sonda | Sí (`ALTER ROLE ... SET default_transaction_isolation`) |
| Entorno | Prohibidos `DB_URL`, `PG*`, conexiones secundarias y claves externas; caché/sesión `array`, cola `sync`, correo `array`; config no cacheada | Sí |
| Consentimiento | `HORUS_PG_TEST_CONFIRM=AUTHORIZE_HORUS_L2_DESTRUCTIVE_TESTS` | Sí, se activa por separado |
| Fuente de credenciales | `bootstrap/app.php` carga `.env.pgsql-testing.local` solo con `HORUS_PG_TEST_PROFILE=1`; contraseña obligatoria en `DB_PASSWORD` | Sí (archivo ignorado por Git) |

La guarda **no** valida directorio de datos, `cluster_name` ni `system_identifier`: la identidad depende de dirección, puerto, base, runner, marcador y privilegios. Por eso el clúster nuevo es aceptable por diseño, pero también por eso **compartir puerto o reutilizar 55432 no es una opción**: S7 sigue en ese puerto.

Otros literales `55432` en la inspección PG-TEST-01 (todos alineados en PG-TEST-02a/02a.1): [`tests/Support/PostgresSafetyFixtures.php`](../tests/Support/PostgresSafetyFixtures.php) (3), [`tests/Unit/ConcurrentTestSessionTest.php`](../tests/Unit/ConcurrentTestSessionTest.php) (2), mensaje de `scripts/pgsql-test.php` línea 44, docblock de [`RequiresIsolatedPostgres`](../tests/PostgresIntegrity/Concerns/RequiresIsolatedPostgres.php), la plantilla `.env.pgsql-testing.local.example` y `CLAUDE.md` §5.

**Compatibilidad de `phpunit.pgsql.xml` y las pruebas:** sin dependencia del directorio ni del servicio; solo de los valores anteriores. Limitación ya conocida y vigente: 50 archivos de `tests/` usan `RefreshDatabase`/`DatabaseTruncation`, que aplican **todas** las migraciones, incluida `000022` (suspendida) y las no aceptadas `000025`–`000028`, en `horus_l2_test`. En un clúster desechable esto no afecta a datos reales, pero sigue siendo una decisión del propietario (ver §10).

### `.gitignore`

Ignora `.env`, `/.env.pgsql-testing.local`, `*.log`, `horus.db`, `database/database.sqlite*`, cachés, sesiones y logs de `storage`. **No** ignora un directorio de datos dentro del repositorio: por eso el clúster debe vivir fuera del repositorio (no hace falta tocar `.gitignore`).

## 5. Ubicación candidata

| Ruta | Uso | Estado actual |
| --- | --- | --- |
| `C:\Users\PCT40T\AppData\Local\HorusPgTest55433\` | Raíz exclusiva | **No existe** |
| `…\HorusPgTest55433\data` | `PGDATA` (`data_directory = C:/Users/PCT40T/AppData/Local/HorusPgTest55433/data`) | — |
| `…\HorusPgTest55433\log` | Log de `pg_ctl -l` (fuera de `PGDATA`) | — |
| `…\HorusPgTest55433\evidencia` | SQL revisado, identificador del clúster, hashes; **sin secretos** | — |

También se comprobó que no existen `%LOCALAPPDATA%\HorusPgTest`, `…\HorusPgTestIndependiente` ni `C:\pgdata\horus_pg_test_55433`. `%LOCALAPPDATA%\HorusL2Provisioning` (evidencias S7) existe y **no se toca**. La ruta no está bajo OneDrive ni dentro del repositorio. Herencia cortada y ACL solo para el usuario y SYSTEM.

## 6. Arquitectura propuesta

```
Usuario Windows pct40t (no elevado)
 └─ pg_ctl start -D …\HorusPgTest55433\data   (sin servicio)
     └─ postgres.exe  127.0.0.1:55433  cluster_name=horus_pgt_55433
         ├─ postgres               (mantenimiento; solo horus_pgt_admin)
         ├─ horus_l2_test          owner horus_pgt_owner · runner horus_l2_test_runner · marcador
         └─ horus_l2_restore_test  owner horus_pgt_owner · runner horus_l2_restore_test_runner · marcador
S7 (55432) y cotidiano (5432): sin cambios, sin conexiones
```

Ciclo de vida: crear → arrancar → `check` → pruebas → detener. Cualquier mantenimiento o pérdida de credenciales se resuelve **destruyendo y recreando** el clúster, nunca con recuperación de contraseñas, `trust` ni monousuario.

## 7. Plan de roles y bases

| Rol | Atributos | Función |
| --- | --- | --- |
| `horus_pgt_admin` | Superusuario de arranque (`initdb -U`), LOGIN, SCRAM | Solo aprovisionamiento; HBA lo deshabilita al sellar. Nunca lo usa Laravel |
| `horus_pgt_owner` | NOLOGIN, sin atributos especiales | Propietario de las bases y del marcador; nadie es miembro |
| `horus_l2_test_runner` | LOGIN, NOINHERIT, sin atributos, 0 membresías | Pruebas en `horus_l2_test`; crea el esquema en `public` porque el arnés ejecuta `migrate:fresh` |
| `horus_l2_restore_test_runner` | Igual | Reservado para ensayo de restauración |
| `horus_pgt_migrator`, `horus_pgt_app` | Fase posterior opcional | Ensayo del modelo de menor privilegio de L2.3 §6 en una tercera base `horus_pgt_privilegios` (migrador miembro del propietario; aplicación solo DML). **Fuera de la lista de la guarda**: requiere herramienta y autorización propias |

Nota sobre la separación «migraciones / aplicación / pruebas»: el arnés actual hace que el **mismo runner** migre y pruebe dentro de su base; separarlos exigiría cambiar `RefreshDatabase`/la guarda. Dentro de la base de pruebas eso es aceptable porque el runner no es superusuario y no ve otras bases. La separación real migrador/aplicación se ensaya en la base opcional anterior, sin afectar a la guarda. Ningún rol que use la aplicación o las pruebas es superusuario.

Bases: `TEMPLATE template0`, `UTF8`, `LOCALE_PROVIDER libc`, `Spanish_Spain.1252` (igual que `horus_group` según S6/S7), `OWNER horus_pgt_owner`. `REVOKE CONNECT, TEMPORARY ... FROM PUBLIC` en todas, incluidas `postgres`, `template0` y `template1`; `REVOKE ALL ON SCHEMA public FROM PUBLIC`.

## 8. Configuración de seguridad

`postgresql.conf` (líneas añadidas al final; la última gana):

```ini
# --- PG-TEST: cluster desechable independiente ---
port = 55433
listen_addresses = '127.0.0.1'
unix_socket_directories = ''
password_encryption = 'scram-sha-256'
ssl = off
cluster_name = 'horus_pgt_55433'
max_connections = 30
log_connections = on
log_disconnections = on
log_lock_waits = on
log_statement = 'none'
deadlock_timeout = '500ms'
idle_in_transaction_session_timeout = '120s'
lc_messages = 'C'
log_line_prefix = '%m [%p] %q%u@%d '
```

`pg_hba.conf` (reemplaza el generado; el primer match gana; sin `trust` ni `local`):

```
host  postgres,horus_l2_test,horus_l2_restore_test  horus_pgt_admin               127.0.0.1/32  scram-sha-256  # PGT-ADMIN
host  horus_l2_test                                  horus_l2_test_runner          127.0.0.1/32  scram-sha-256
host  horus_l2_restore_test                          horus_l2_restore_test_runner  127.0.0.1/32  scram-sha-256
host  all                                            all                           0.0.0.0/0     reject
host  all                                            all                           ::/0          reject
```

Credenciales:

- `initdb --pwprompt` y `\password` piden las contraseñas por consola oculta. `\password` cifra en el cliente: el servidor solo recibe el verificador SCRAM, nunca el texto.
- Nunca en argumentos, variables de entorno (`PGPASSWORD` está prohibida por la guarda), historial, scripts, documentación, logs ni chat.
- Contraseña del runner: solo en `.env.pgsql-testing.local` (ignorado), con ACL limitada al usuario. La de `horus_pgt_admin` no se guarda en ningún archivo; si se pierde, se reconstruye el clúster.
- `ssl = off` y `sslmode=prefer` son aceptables solo por ser loopback dentro del mismo equipo; SCRAM no envía la contraseña en claro.
- Log del servidor fuera del repositorio, sin sentencias (`log_statement = 'none'`); los errores pueden contener SQL de prueba con datos ficticios, nunca contraseñas porque no se envían en claro.

## 9. Integración necesaria con la guarda

*Realizado en PG-TEST-02a (§13); se conserva la propuesta original.* Para usar 55433 hay que cambiar el literal `'55432'` por `'55433'` en `PostgresTestGuard` (3 puntos) y alinear fixtures, pruebas unitarias, el mensaje del runner, el docblock y la plantilla. Recomendación:

- **Sustituir**, no añadir: un único puerto permitido. Permitir ambos ampliaría la superficie y volvería a hacer alcanzable S7.
- Extraer el valor a una constante `PostgresTestGuard::PORT` para que no vuelva a divergir.
- Opcional (refuerzo, no relajación): añadir a la sonda `current_setting('cluster_name') = 'horus_pgt_55433'`.
- Validación del cambio: `TestDatabaseSafetyTest`, `ConcurrentTestSessionTest` y filtro `PostgresTestGuard` en SQLite (referencia histórica: 96 pruebas, 366 aserciones), Larastan, Pint acotado y `git diff --check`.

Esto modifica una salvaguarda listada en `AGENTS.md` §3/§4 y `CLAUDE.md` §5: **requiere autorización explícita** aunque no la debilita. No se ha hecho en esta fase.

## 10. Comandos previstos para la futura creación (NO ejecutados)

Consola PowerShell **no elevada**, sin transcripción. Cada bloque se detiene ante cualquier `throw`.

**F0 — preflight (solo lectura)**

```powershell
$bin  = 'C:\Program Files\PostgreSQL\18\bin'
$root = Join-Path $env:LOCALAPPDATA 'HorusPgTest55433'
$data = Join-Path $root 'data'
if (([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'STOP: consola elevada' }
if (Test-Path -LiteralPath $root) { throw 'STOP: la raíz ya existe' }
if (Get-NetTCPConnection -LocalPort 55433 -ErrorAction SilentlyContinue) { throw 'STOP: 55433 en uso' }
foreach ($e in 'initdb','pg_ctl','postgres','psql','pg_isready','pg_controldata') { if ((& "$bin\$e.exe" --version) -notmatch ' 18\.6$') { throw "STOP: $e no es 18.6" } }
netsh int ipv4 show excludedportrange protocol=tcp   # revisar que 55433 no esté excluido
Get-Service postgresql-x64-18, postgresql-horus-l2-test | Format-Table Name, Status, StartType   # registrar; no cambiar
```

**F1 — directorios y ACL**

```powershell
$sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
New-Item -ItemType Directory -Path $root, $data, (Join-Path $root 'log'), (Join-Path $root 'evidencia') | Out-Null
icacls $root /inheritance:r /grant:r "*${sid}:(OI)(CI)F" "*S-1-5-18:(OI)(CI)F"
```

**F2 — initdb** (pide la contraseña de `horus_pgt_admin` dos veces, oculta)

```powershell
& "$bin\initdb.exe" -D $data -U horus_pgt_admin --pwprompt -E UTF8 --locale-provider=libc --lc-collate=Spanish_Spain.1252 --lc-ctype=Spanish_Spain.1252 --auth=scram-sha-256 --data-checksums
if ($LASTEXITCODE -ne 0) { throw 'STOP: initdb falló' }
$sysid = ((& "$bin\pg_controldata.exe" -D $data) | Select-String 'system identifier').Line.Split(':')[1].Trim()
Set-Content -LiteralPath (Join-Path $root 'evidencia\system_identifier.txt') -Value $sysid -Encoding ASCII
```

**F3 — configuración**: añadir el bloque de §8 a `$data\postgresql.conf` con `Add-Content -Encoding ASCII` y sustituir `$data\pg_hba.conf` con `Set-Content -Encoding ASCII` por las cinco líneas de §8.

**F4 — verificación sin arrancar**

```powershell
foreach ($k in 'port','listen_addresses','unix_socket_directories','cluster_name','password_encryption') { "{0} = {1}" -f $k, (& "$bin\postgres.exe" -D $data -C $k) }
if (Select-String -LiteralPath "$data\pg_hba.conf" -Pattern 'trust') { throw 'STOP: trust en HBA' }
```

**F5 — arranque**

```powershell
& "$bin\pg_ctl.exe" start -D $data -l (Join-Path $root 'log\server.log') -w -t 60
& "$bin\pg_isready.exe" -h 127.0.0.1 -p 55433
Get-NetTCPConnection -State Listen -LocalPort 55433 | Format-Table LocalAddress, LocalPort, OwningProcess   # solo 127.0.0.1
```

**F6 — roles, bases y marcadores.** Tres archivos en `$root\evidencia`, derivados de S6 ([L2.2-B §S6](postgresql-l2.2-b-provisioning.md)) con estas sustituciones exactas: `55432`→`55433`; `'horus_l2_test'` como `cluster_name` → `'horus_pgt_55433'`; `'C:/pgdata/horus_l2_test'` → `'C:/Users/PCT40T/AppData/Local/HorusPgTest55433/data'`; `horus_l2_owner` → `horus_pgt_owner`. Se conservan nombres de bases, runners, marcador, `REVOKE ... FROM PUBLIC` y los `\password`. Revisión humana y SHA-256 antes de ejecutar:

```powershell
$sysid = Get-Content -LiteralPath (Join-Path $root 'evidencia\system_identifier.txt')
foreach ($s in @(@{f='00-cluster.sql';d='postgres'}, @{f='10-main.sql';d='horus_l2_test'}, @{f='20-restore.sql';d='horus_l2_restore_test'})) {
  & "$bin\psql.exe" -X -W -h 127.0.0.1 -p 55433 -U horus_pgt_admin -d $s.d --set=ON_ERROR_STOP=on "--set=system_identifier=$sysid" -f (Join-Path $root "evidencia\$($s.f)")
  if ($LASTEXITCODE -ne 0) { throw "STOP: $($s.f) falló; destruir y recrear, no reintentar" }
}
```

**F7 — sellado**: comentar la línea `# PGT-ADMIN` de `pg_hba.conf` y `& "$bin\pg_ctl.exe" reload -D $data`; comprobar que `horus_pgt_admin` es rechazado por HBA.

**F8 — guarda**: ya realizado en PG-TEST-02a (§13); no forma parte de PG-TEST-02b. **F9 — perfil**: crear `.env.pgsql-testing.local` desde la plantilla con `DB_PORT=55433`, `DB_PASSWORD` del runner, `APP_KEY` de pruebas propio y `HORUS_PG_TEST_CONFIRM` vacío; ACL solo del usuario. Después:

```powershell
php scripts/pgsql-test.php check
```

Nota ya documentada: `check` exige el consentimiento y habilita escritura tras la sonda; su primera ejecución debe estar dentro de la autorización de pruebas.

**Parada y destrucción**

```powershell
& "$bin\pg_ctl.exe" stop -D $data -m fast -w
# Destrucción: solo si el identificador coincide con la evidencia
$now = ((& "$bin\pg_controldata.exe" -D $data) | Select-String 'system identifier').Line.Split(':')[1].Trim()
if ($now -cne (Get-Content -LiteralPath (Join-Path $root 'evidencia\system_identifier.txt'))) { throw 'STOP: identidad distinta' }
Remove-Item -LiteralPath $root -Recurse -Force
```

## 11. Riesgos y contingencias

| Riesgo | Contingencia |
| --- | --- |
| La guarda sigue fijada a 55432 | Resuelto en PG-TEST-02a (§13); el destino ya es 55433 en código |
| Puerto 55433 ocupado o excluido tras reinicio | Preflight antes de cada arranque; si falla, detenerse y proponer otro puerto (implica otro cambio de guarda) |
| Error en `initdb` o SQL de F6 | No reintentar parcialmente: destruir la raíz y recrear |
| Pérdida de cualquier contraseña | Destruir y recrear; nunca recuperar, ni `trust`, ni monousuario |
| Confusión con S7 | Puerto, `cluster_name`, directorio y propietario de procesos distintos; la guarda nueva solo admite 55433 |
| `migrate:fresh` aplica `000022` y `000025`–`000028` | Solo en `horus_l2_test` desechable; aun así requiere decisión del propietario o una lista de migraciones revisada |
| Arranque no probado como usuario no elevado | Si `pg_ctl` falla por privilegios, detenerse y revisar el log; no elevar la consola |
| Cierre de sesión/reinicio detiene el servidor | Esperado; arrancar de nuevo con F5 |
| Antivirus o indexación sobre `PGDATA` | Observar latencia; solicitar exclusión solo con autorización |
| Fallos esperables de pruebas (colación, `LIKE`, recuentos de consultas) | Son hallazgos que se analizan; no se relajan guardas ni tiempos |

## 12. Acciones que requieren autorización independiente

1. ~~**PG-TEST-02a**: cambio de la guarda a 55433 (§9) y su validación SQLite.~~ Autorizado y realizado; ver §13.
2. **PG-TEST-02b**: creación del clúster (F0–F7), incluido el arranque de un proceso PostgreSQL nuevo y la ejecución de SQL de aprovisionamiento.
3. Creación de `.env.pgsql-testing.local` y la primera ejecución de `check`.
4. Decisión sobre `000022` y `000025`–`000028` en la base desechable, o lista de migraciones del arnés.
5. Activar `HORUS_PG_TEST_CONFIRM` y ejecutar las pruebas destructivas por etapas (L2.3 §5.3, con puerto 55433).
6. Ensayo opcional de menor privilegio (`horus_pgt_migrator`/`horus_pgt_app`).
7. Decidir el destino de S7 (detener, mantener o retirar): requiere consola elevada y autorización propia; este plan no lo necesita.
8. Commit de los cambios.

Siguen fuera de alcance: `horus_group`, `horus_group_ensayo`, el servicio cotidiano, recuperación de credenciales de S7, migraciones reales, Resend, verificación de correo y frontend.

## 13. PG-TEST-02a — guarda adaptada a 127.0.0.1:55433 (29/10/2026)

Autorización del propietario limitada a la infraestructura de pruebas. **La creación del clúster (PG-TEST-02b) sigue sin autorizar**: no se inició PostgreSQL, no se creó el clúster ni `.env.pgsql-testing.local`, no se ejecutó SQL ni hubo conexión a 5432/55432; HBA, servicios, credenciales, `.env`, migraciones, frontend, chatbot y Python sin cambios. Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, índice vacío, stashes intactos.

### Cambios

| Archivo | Cambio |
| --- | --- |
| `app/Support/Testing/PostgresTestGuard.php` | Constantes `HOST = '127.0.0.1'`, `PORT = '55433'` y `RETIRED_PORTS = ['55432']`; las tres comparaciones usan las constantes. Rechazo explícito `retired S7 port is refused.` si la configuración **o** `DB_PORT` contienen 55432 (también entero o con espacios), antes de la comparación general. En la sonda, `server reports the retired S7 port; no writes authorized.` si el servidor informa 55432 |
| `scripts/pgsql-test.php` | El mensaje de `check` toma host y puerto de las constantes |
| `tests/Support/PostgresSafetyFixtures.php` | Entorno, configuración e identidad ficticios en 55433 |
| `tests/Unit/PostgresTestGuardTest.php` | 26 casos nuevos (abajo) |
| `tests/Unit/ConcurrentTestSessionTest.php` | Aserción del puerto a 55433 y 4 casos nuevos: S7 por configuración y por entorno, `localhost`, `horus_group_ensayo` |
| `tests/PostgresIntegrity/Concerns/RequiresIsolatedPostgres.php` | Solo docblock |
| `.env.pgsql-testing.local.example` | `DB_PORT=55433` |

Se conservan sin cambios: lista cerrada de bases/runners, `127.0.0.1` literal, consentimiento literal, marcador, sonda de privilegios mínimos, sesión de solo lectura hasta la sonda, prohibición de `DB_URL`/`PG*`/conexiones secundarias y entorno en memoria. Solo hay **un** puerto permitido; no se amplió la lista de destinos.

### Pruebas nuevas

- Destino permitido exacto: valores literales de las constantes y de `TARGETS`; `127.0.0.1:55433` supera configuración y sonda.
- S7 inalcanzable (6 manipulaciones): 55432 solo en configuración, solo en entorno, coherente en ambos, como entero, con espacios y con el destino de restauración; mensaje exacto y **cero sondas**.
- Conector protegido con 55432: el conector interno nunca se invoca (sin socket).
- Servidor que informa 55432 en la sonda: rechazo y `exec` nunca llamado (sin habilitar escrituras).
- Destinos inseguros con motivo específico: `localhost`, `::1`, `0.0.0.0`, 5432, 55434, puerto entero 55433, `DB_PORT` discordante, `horus_group`, `horus_group_ensayo`, runner cruzado, perfil ausente o distinto de `1`, consentimiento ausente o aproximado.
- Identidad del servidor: puerto 55432, 55434 y dirección `::1`. Se mantienen los casos previos de marcador ausente/incorrecto, rol privilegiado, membresías, acceso a otras bases y marcador escribible.

### Validación (SQLite y dobles; ninguna prueba PostgreSQL)

| Comando | Resultado |
| --- | --- |
| `php artisan test --compact --filter="PostgresTestGuardTest\|TestDatabaseSafetyTest\|ConcurrentTestSessionTest"` | **126 aprobadas, 431 aserciones** (antes 96/366) |
| `php artisan test --compact` | **1096 aprobadas, 7512 aserciones**, 98,55 s |
| `php vendor/bin/phpstan analyse --no-progress` | Nivel 6, sin errores |
| Pint `--test` sobre los 6 PHP tocados | Aprobado con finales LF (los que almacena Git, `i/lf`); en la copia de trabajo CRLF de `scripts/pgsql-test.php` y `PostgresTestGuardTest.php` solo reporta finales de línea, igual que sus versiones de HEAD. Sin reformateo |
| `git diff --check` | Aprobado; solo avisos LF/CRLF conocidos |

Evidencia de rechazo de 55432: los 6 casos de manipulación devuelven exactamente `Horus PostgreSQL test safety: retired S7 port is refused.` antes de cualquier sonda o conector; la sesión concurrente con 55432 en configuración o entorno se rechaza sin llamar al conector; una sonda que informa 55432 no ejecuta `SET default_transaction_read_only = off`. Son pruebas con dobles: no prueban un servidor real.

### Pendientes

- ~~`CLAUDE.md` §5 todavía cita `127.0.0.1:55432`~~ Corregido en PG-TEST-02a.1 (§14). Los informes históricos (L2.2, L2.3, validación final) conservan 55432 como registro de S7.
- PG-TEST-02b (creación y arranque del clúster en 55433) y todas las acciones de §12 desde el punto 2 requieren autorización expresa.
- Con la guarda actual, el arnés ya **no** puede usar S7 aunque se recuperaran sus credenciales.

## 14. PG-TEST-02a.1 — cierre documental y calidad (29/10/2026)

Sin PostgreSQL, SQL, clúster, HBA, servicios, credenciales, `.env` ni bases. HEAD, índice vacío y stashes intactos.

**Referencias operativas corregidas:** `CLAUDE.md` §5 (destino `127.0.0.1:55433`, 55432 rechazado, clúster no creado) y nueva fila en su índice de documentación; `PROJECT_CONTEXT.md` (fila de estado de cabecera, tabla de entornos con fila 55433 y S7 marcado como fuera del arnés, roadmap punto 2); nota inicial en L2.3 indicando que su `.env` y comandos con 55432 ya no son operativos; F8/§9/§4 de este informe marcados como realizados. Sin cambios en referencias históricas de L2.2, validación final, Sprint 15 ni el cuerpo de L2.3. `AGENTS.md` (S7 suspendido, no cambiar puertos de salvaguardas sin autorización) sigue vigente y no se modificó.

**Finales de línea:** Git usa `core.autocrlf=true` (configuración del sistema), sin `.gitattributes` ni `.editorconfig`; el índice guarda LF (`i/lf`) y la copia de trabajo es mixta (144 PHP en LF, 165 en CRLF). Pint (preset Laravel) exige LF. De los seis PHP afectados, solo `scripts/pgsql-test.php` y `tests/Unit/PostgresTestGuardTest.php` estaban en CRLF; se reescribieron a LF sin BOM y sin otro cambio de contenido (ahora `i/lf w/lf`). No se tocó ningún otro archivo ni se añadió `.gitattributes`. Con `autocrlf=true` Git seguirá mostrando avisos «LF will be replaced by CRLF»; son informativos.

**Guardas:** revisión del contrato completo: host, puerto, base, usuario y opciones se comparan de forma exacta; `DB_URL`, `PG*` (incluido `PGPORT`), conexiones secundarias y claves adicionales se rechazan; la sonda exige `inet_server_port() = '55433'` antes de habilitar escritura. Tres casos nuevos: `55432` inyectado en el host, inyectado en el nombre de base y `PGPORT=55432`; todos rechazados.

| Comprobación | Resultado |
| --- | --- |
| Pint `--test` sobre los 6 PHP en la copia de trabajo | **Aprobado** |
| Guarda/seguridad/concurrencia | **129 aprobadas, 434 aserciones** |
| Suite SQLite completa | **1099 aprobadas, 7515 aserciones**, 96,26 s |
| `git diff --check` | Aprobado (solo avisos LF/CRLF informativos) |

Larastan no se repitió: ningún archivo de su alcance cambió desde PG-TEST-02a (solo pruebas y documentación). PG-TEST-02b sigue sin autorizar.

## 15. PG-TEST-02b — creación del clúster 127.0.0.1:55433 (29/10/2026, ejecutada)

Autorización expresa del propietario para crear e iniciar el clúster. Sin conexiones a `horus_group`, `horus_group_ensayo`, S7 (55432) ni al servidor habitual (5432); sin cambios en servicios, HBA ni contraseñas existentes, `.env`, código o configuración Laravel; sin migraciones, PHPUnit contra PostgreSQL ni `.env.pgsql-testing.local`. Sin commits ni push.

### Resultado

| Elemento | Valor verificado |
| --- | --- |
| Identificador del clúster | **`7702263743609677164`** (distinto de S7 `7701505916098505688`) |
| Versión | PostgreSQL 18.6 on x86_64-windows, msvc-19.44.35229 |
| Directorio de datos | `C:/Users/PCT40T/AppData/Local/HorusPgTest55433/data` |
| Proceso | `postgres.exe` PID **11184** (`postmaster.pid` = `ready`), propietario `LAPTOP-ONK47RS6\PCT40T`, sin servicio Windows, arrancado con `pg_ctl start` |
| Escucha | Únicamente `127.0.0.1:55433`; `unix_socket_directories` vacío |
| `cluster_name` | `horus_pgt_55433` |
| Seguridad | `password_encryption=scram-sha-256`, `ssl=off` (solo loopback), `log_statement=none`, `data_checksums=on`, `lc_messages=C`, UTF8 |
| Log | `…\HorusPgTest55433\log\server.log` (fuera de `PGDATA` y del repositorio) |
| ACL raíz | Herencia cortada; solo SYSTEM y el usuario actual |

**Roles** (`pg_authid`, sin imprimir verificadores):

| Rol | super | inherit | createrole | createdb | login | replication | bypassrls | SCRAM | Configuración |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `horus_pgt_admin` | t | t | t | t | t (bloqueado por HBA) | t | t | t | — |
| `horus_pgt_owner` | f | t | f | f | **f** | f | f | sin contraseña | — |
| `horus_l2_test_runner` | f | **f** | f | f | t | f | f | t | `read committed` |
| `horus_l2_restore_test_runner` | f | **f** | f | f | t | f | f | t | `read committed` |

Membresías: **0**.

**Bases:** `horus_l2_test` (OID 16391) y `horus_l2_restore_test` (OID 16392), propietario `horus_pgt_owner`, UTF8, libc `Spanish_Spain.1252`. `CONNECT` de cada runner solo a su base; ningún runner tiene `TEMPORARY` ni `CREATE` de base; `PUBLIC` sin `CONNECT/TEMPORARY` en `postgres`, `template0`, `template1` ni en las bases de prueba. En cada base: `public` sin `PUBLIC`, `USAGE, CREATE` solo para su runner; esquema `horus_test_control` y tabla `authorization` de `horus_pgt_owner`, con `USAGE`/`SELECT` para el runner y una fila `purpose = horus-l2-destructive-tests` con el OID correcto.

**HBA final** (`pg_hba_file_rules` sin errores antes del sellado): línea `# PGT-ADMIN` comentada y recargada (`pg_ctl reload`); activas solo `horus_l2_test`/`horus_l2_test_runner` y `horus_l2_restore_test`/`horus_l2_restore_test_runner` desde `127.0.0.1/32` con `scram-sha-256`, y `reject` para `0.0.0.0/0` y `::/0`. Sin `trust` ni `local`.

### Sonda de la guarda (psql, solo lectura, misma consulta que `GuardedPostgresConnector::identity()`)

Ambos runners autenticados por SCRAM: base y usuario correctos, `server_address=127.0.0.1`, `server_port=55433`, `read_only=on`, `isolation=read committed`, marcador `horus-l2-destructive-tests`, `unsafe_role=0`, `role_memberships=0`, `other_database_access=0`, `writable_marker=0`, 0 tablas en `public`. En psql directo `search_path` aparece `unexpected` y `TimeZone` `America/Bogota` porque son valores de sesión por defecto; la conexión Laravel fija `search_path=public` y `timezone=UTC` en la configuración validada. Comprobarlo forma parte del futuro `check`, no ejecutado.

### Pruebas de aislamiento (sin contraseñas, `psql -w`, solo 55433)

| Caso | Resultado |
| --- | --- |
| `horus_pgt_admin` → `postgres` / `horus_l2_test` | Rechazado por `pg_hba.conf` (sellado efectivo) |
| `horus_l2_test_runner` → `postgres` / `horus_l2_restore_test` | Rechazado por `pg_hba.conf` |
| `horus_l2_restore_test_runner` → `horus_l2_test` | Rechazado por `pg_hba.conf` |
| Usuario `postgres` | Rechazado por `pg_hba.conf` |
| Control: cada runner → su base | HBA lo admite y exige contraseña (`fe_sendauth`) |
| TCP `[::1]:55433` y las cuatro IPv4 del equipo (`192.168.56.1`, `172.30.0.1`, `192.168.100.14`, `172.16.0.2`) | Sin conexión |

Evidencia sin secretos en `…\HorusPgTest55433\evidencia\` (SQL revisados, guiones, salidas `f6-admin.out`, `f6b-*.out`, `f7-aislamiento.txt`, `system_identifier.txt`). Búsqueda de verificadores o contraseñas en log y evidencia: solo el patrón `LIKE 'SCRAM-SHA-256$%'` de las consultas.

### Incidencias y cómo se resolvieron

1. **Ejecución interactiva:** el agente no puede mostrar ventanas ni introducir secretos. Los pasos con contraseña (`initdb --pwprompt`, `psql -W`, `\password`) los ejecutó el propietario en su terminal no elevada mediante guiones revisados sin secretos; el agente esperó sus archivos de estado.
2. **Guion F2 inicial no ejecutable:** el `.ps1` en UTF-8 sin BOM contenía acentos que PowerShell 5.1 interpretó como comillas. El proceso terminó sin ejecutar `initdb` (directorio vacío). Corregido usando solo ASCII.
3. **Primer clúster (`7702261264381348568`) descartado:** la contraseña de `horus_pgt_admin` fue rechazada dos veces en F6, sin que se ejecutara SQL. Con autorización expresa del propietario se detuvo (`pg_ctl stop -m fast`), se verificó su identificador, se archivó su evidencia fuera del repositorio (scratchpad de la sesión) y se borró el directorio. Se recreó con contraseña ASCII tecleada a mano y una comprobación inmediata (F5b), superada al primer intento.
4. **Consulta de verificación errónea:** `rolconfig` no existe en `pg_authid`; la sesión de F6 se detuvo por `ON_ERROR_STOP` **después** de confirmar todo el aprovisionamiento. Se corrigió la consulta y se repitió solo la verificación de lectura (F6b), sin repetir escrituras.

### Estado de servicios preexistentes

`postgresql-x64-18` Running/Auto (PID 4972, escucha `0.0.0.0`/`::`:5432) y `postgresql-horus-l2-test` Running/Manual (PID 2548, `127.0.0.1:55432`): sin cambios ni conexiones. No se creó ningún servicio Windows.

### Limitaciones

- El servidor no es un servicio: se detiene al cerrar sesión o reiniciar. Arranque y parada: `pg_ctl start -D "$env:LOCALAPPDATA\HorusPgTest55433\data" -l "$env:LOCALAPPDATA\HorusPgTest55433\log\server.log" -w` y `pg_ctl stop -D … -m fast -w`.
- `horus_pgt_admin` queda sellado; cualquier mantenimiento se resuelve recreando el clúster (§10) o rehabilitando temporalmente su línea con autorización.
- Los binarios PostgreSQL no tienen firma Authenticode; se identificaron por versión, fabricante, hash y ubicación protegida (escritura solo TrustedInstaller/SYSTEM/Administradores).
- 55433 está dentro del rango dinámico de Windows; las exclusiones pueden cambiar tras reiniciar.
- No se ha ejecutado `php scripts/pgsql-test.php check`, ni migraciones, ni pruebas: la guarda Laravel contra este servidor real sigue **sin comprobarse**.

### Siguiente fase (requiere autorización posterior)

Crear `.env.pgsql-testing.local` (fuera de Git, ACL del usuario) con `DB_PORT=55433`, la contraseña de `horus_l2_test_runner`, un `APP_KEY` de pruebas propio y `HORUS_PG_TEST_CONFIRM` vacío; ejecutar `check`; decidir sobre `000022` y `000025`–`000028`; y después las pruebas destructivas por etapas.

## 16. PG-TEST-03 — perfil local y modo `probe` de solo lectura (29/10/2026)

### Bloqueo detectado y decisión

La revisión del contrato real mostró que `php scripts/pgsql-test.php check` **no** podía cumplir PG-TEST-03: exige `HORUS_PG_TEST_CONFIRM=AUTHORIZE_HORUS_L2_DESTRUCTIVE_TESTS` (rechaza con consentimiento vacío antes de conectar) y, tras la sonda, ejecuta `SET default_transaction_read_only = off`; además no verifica `system_identifier` ni `cluster_name`. No se ejecutó `check`. El propietario autorizó expresamente un modo `probe` de solo lectura, sin consentimiento destructivo.

### Cambios de código

| Archivo | Cambio |
| --- | --- |
| `app/Support/Testing/PostgresTestGuard.php` | Constantes `SYSTEM_IDENTIFIER = '7702263743609677164'` y `CLUSTER_NAME = 'horus_pgt_55433'`. `assertConfiguration(..., bool $requireConsent = true)`: el perfil sigue siendo obligatorio; solo el consentimiento depende del parámetro. Nuevo `assertClusterIdentity()` (cluster, identificador, `transaction_read_only` y `default_transaction_read_only` = `on`) |
| `app/Support/Testing/GuardedPostgresConnector.php` | Parámetro `readOnlyProbe` (por defecto `false`). En modo probe, tras la sonda existente, lee `cluster_name`, `pg_control_system()` y el estado de solo lectura, y devuelve la sesión **sin** ejecutar `SET default_transaction_read_only = off`. Un fallo de esa lectura rechaza sin escalar privilegios. Camino por defecto sin cambios |
| `app/Support/Testing/TestDatabaseSafety.php` | Indicador de contenedor `READ_ONLY_PROBE` (`true` estricto). Solo con él se omite el consentimiento y se instala el conector de solo lectura. No puede activarse desde `.env`, entorno ni configuración |
| `scripts/pgsql-test.php` | Acción `probe` (sin argumentos): registra el indicador antes del arranque, exige el conector de solo lectura, relee en la misma sesión base/usuario/puerto/cluster/identificador/`transaction_read_only`/`search_path`/`TimeZone` con un `SELECT` y lo imprime. `check`, `test` y `artisan` sin cambios |
| `tests/Unit/ReadOnlyProbeTest.php` | 26 pruebas nuevas con dobles |

Pruebas nuevas: constantes; consentimiento omitido solo en probe y aún exigido por defecto; probe conserva el resto de guardas (S7, 5432, `localhost`, `horus_group`, `horus_group_ensayo`, perfil ausente, `PGPORT`, `DB_URL`); probe devuelve el PDO con `exec` nunca llamado; rechazo de otro identificador (S7 y el clúster descartado), identificador ausente, otro `cluster_name` y transacción o predeterminado escribibles; identificador ilegible rechazado sin excepción previa ni secretos; marcador/privilegios/puerto 55432 detienen antes de la consulta de clúster; conector por defecto sigue habilitando escritura solo tras la sonda; el indicador solo funciona con `true` estricto y una clave homónima en configuración no lo activa.

### Perfil local

`.env.pgsql-testing.local` creado por el propietario con el guion `evidencia\pgt03-crear-perfil.ps1` (contraseña por prompt oculto, plantilla comprobada antes de escribir, `APP_KEY` aleatorio, archivo creado vacío con ACL antes del contenido): 25 líneas, idénticas a la plantilla salvo `APP_KEY` y `DB_PASSWORD`; `APP_ENV=testing`, `HORUS_PG_TEST_PROFILE=1`, **`HORUS_PG_TEST_CONFIRM` vacío**, `127.0.0.1:55433`, `horus_l2_test`/`horus_l2_test_runner`, caché/sesión/cola/correo en memoria, claves externas vacías. Ignorado por Git (`.gitignore:17`), ACL protegida solo para SYSTEM y el usuario, finales LF. `.env` habitual intacto.

### Validación de cada runner (`php scripts/pgsql-test.php probe`)

| Campo | `horus_l2_test_runner` | `horus_l2_restore_test_runner` |
| --- | --- | --- |
| Base | `horus_l2_test` | `horus_l2_restore_test` |
| Puerto | 55433 | 55433 |
| `cluster_name` | `horus_pgt_55433` | `horus_pgt_55433` |
| `system_identifier` | `7702263743609677164` | `7702263743609677164` |
| `transaction_read_only` | on | on |
| `search_path` / `TimeZone` | `public` / `UTC` | `public` / `UTC` |
| Marcador, privilegios mínimos, sin acceso a otras bases, marcador no escribible | Validado por la sonda | Validado por la sonda |
| Autenticación (log) | SCRAM, `pg_hba.conf:2` | SCRAM, `pg_hba.conf:3` |
| Salida | `exit=0` | `exit=0` |

El runner de restauración se validó con `evidencia\pgt03-probe-restore.ps1`: la contraseña, por prompt oculto, solo existe en el entorno del proceso `php` hijo; el perfil no se modificó. `pg_control_system()` resultó legible por los runners sin privilegios adicionales. Aislamiento ya probado en PG-TEST-02b (HBA rechaza admin, accesos cruzados y ajenos; `::1` e IPv4 de red sin conexión) y reconfirmado en el log.

### Validación SQLite

| Comprobación | Resultado |
| --- | --- |
| `ReadOnlyProbeTest`, `PostgresTestGuardTest`, `TestDatabaseSafetyTest`, `ConcurrentTestSessionTest` | **155 aprobadas, 512 aserciones** |
| Suite completa | **1125 aprobadas, 7593 aserciones** |
| Larastan nivel 6 | Sin errores |
| Pint sobre los 5 PHP tocados | Aprobado (LF) |
| `php -l scripts/pgsql-test.php`; `probe` sin perfil | Sin errores; rechazo `testing environment required` antes de conectar |
| `git diff --check` | Aprobado |

### Incidencias y limitaciones

- La contraseña elegida para `horus_l2_test_runner` es corta (menos de 12 caracteres). El riesgo queda acotado por loopback y SCRAM, pero se recomienda una más larga si se recrea el clúster.
- `system_identifier` solo lo verifica el modo `probe`; el camino destructivo (`check`/`test`) mantiene su sonda anterior. Añadirlo allí sería un refuerzo que requiere autorización.
- Recrear el clúster cambia el identificador y exige actualizar `SYSTEM_IDENTIFIER` con revisión.
- El servidor sigue siendo un proceso de usuario: se detiene al cerrar sesión.
- No se ejecutaron `check`, migraciones, `RefreshDatabase`, pruebas destructivas o de concurrencia, ni SQL de escritura. Sin cambios en HBA, roles, contraseñas, servicios ni clúster.

## 17. PG-TEST-04 — endurecimiento y preparación (29/10/2026)

Sin ejecución PostgreSQL. La ruta que habilita escrituras (`check`, `test`, `artisan`, `RefreshDatabase`, `ConcurrentTestSession`) exige ahora, igual que `probe`, `cluster_name = horus_pgt_55433` y `system_identifier = 7702263743609677164` con la sesión aún en solo lectura; falla cerrado si no puede leerse y la expectativa no es configurable por entorno. Auditoría de `000022`–`000028`, demostración de que `000022` en la base desechable no activa la verificación de correo, pruebas que necesitan adaptación, matriz de diez etapas y procedimiento exacto de PG-TEST-05: [PG-TEST-04](postgresql-testing-pg-test-04.md). Validación SQLite: 166/556 en la selección de guardas, suite 1136/7637, Larastan 6, Pint y diff aprobados. Consentimiento destructivo sigue vacío.
