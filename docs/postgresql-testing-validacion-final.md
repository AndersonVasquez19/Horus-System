# PostgreSQL Testing — inspección y validación de barreras

29 de octubre de 2026. **R1 completada: servicio aislado disponible; autenticación e integración PostgreSQL pendientes.** La inspección inicial encontró el entorno detenido; la sección R1 registra el arranque posteriormente autorizado. No se repararon credenciales ni se completó S7. Esta acta no autoriza procedimientos posteriores.

## Estado inicial y alcance

- Rama `anderson`, HEAD `306b168e8d8bbfec23301d7c050c7f7c9ae11d2f`.
- Árbol e índice limpios al comenzar. Dos stashes: `52e2f296e243cacf777f48d8148b0a72e68fc93c` y `d69d6caa1c7b2a18c9c2aa962bc74a473bbdcc82`; preservados.
- Leídos `AGENTS.md`, `CLAUDE.md`, `PROJECT_CONTEXT.md`, L2.2-A/B, acta S3, L2.2-C, L2.3 y Fase A de Sprint 15 §10. Contrastados con código, servicios, configuración local y evidencias existentes.
- La autorización de inspección inicial permitía pruebas descartables solo si el aislamiento estaba demostrado y exigía detenerse antes de iniciar servicios o cambiar credenciales. Posteriormente el propietario autorizó exclusivamente R1 (identificación, arranque y diagnóstico de solo lectura); `000022` sigue excluida también en Testing.
- Cambios de esta tarea: esta acta y actualización puntual del contexto. Sin cambios funcionales, frontend, Python, perfiles locales ni scripts.

## Observaciones de la inspección inicial, sin SQL PostgreSQL

| Comprobación | Resultado actual |
| --- | --- |
| Servicio aislado | `postgresql-horus-l2-test`, `Stopped`, `Manual`, cuenta `NT SERVICE\postgresql-horus-l2-test` |
| Servicio cotidiano | `postgresql-x64-18`, `Running`, `Auto`, cuenta `NT AUTHORITY\NetworkService`; no se intervino |
| Escucha | Sin listener en 55432. En 5432 se observaron `0.0.0.0` y `::`; no se abrió conexión a ese puerto |
| Disponibilidad aislada | `pg_isready -h 127.0.0.1 -p 55432 -U horus_l2_test_runner -d horus_l2_test -t 2`: `no response`. No comprueba contraseña, bases ni privilegios |
| Binario / PHP | `pg_isready.exe` versión de archivo 18.6; PHP CLI 8.4.26, extensión `pdo_pgsql` disponible. No acredita versión de servidor en ejecución |
| Configuración aislada en disco | `port=55432`, `listen_addresses='127.0.0.1'`, `password_encryption='scram-sha-256'`, `cluster_name='horus_l2_test'` |
| HBA aislado | Cinco reglas activas; la identificada `# L2-PROVISIONER` sigue habilitada. No se editó ni recargó |
| Perfil local | `.env.pgsql-testing.local` ausente; configuración Laravel no cacheada. No se creó archivo ni clave |
| Entorno heredado | Sin las variables de proceso inspeccionadas de selección, conexión, consentimiento o credenciales externas. No se imprimieron valores secretos ni se cargó el `.env` cotidiano para conectar |

No se verificaron en vivo existencia de bases, OID, filas, migraciones, autenticación, ACL efectivas desde el servidor ni marcador. No se intentó autenticar ningún rol: sin servicio disponible, no aporta evidencia y repetir S7 sería improcedente.

## S7: punto de parada y herramientas recuperables

El almacén externo `%LOCALAPPDATA%\HorusL2Provisioning` conserva identidad DPAPI y herramientas de S3–S7. Se inspeccionaron archivos como datos; no se ejecutaron, descifraron credenciales ni modificaron evidencias.

- `s7-20261027/failure.json`: `Success=false`, `S7Complete=false`, fase `phase-P-runner-authentication`, UTC `2026-10-28T04:02:40.4316662Z`; sin intención de escritura HBA.
- `runner-pre-main.json` tiene cero bytes. Existen informes administrativos de ambas bases y V16; su existencia es evidencia histórica, no una nueva validación de su resultado en servidor.
- No existen allí `phase-P-passed.json`, `hba-write-intent.json`, `hba-written.json` ni `seal-intent.json`.
- L2.3 registra `password authentication failed` del runner principal. El fallo pertenece a infraestructura/autenticación, antes de migraciones o aplicación. **No está demostrado por qué dejó de coincidir la contraseña**: no atribuirlo a corrupción, cambio de rol o error del operador sin más evidencia. Su validez actual es desconocida.
- `s7-supervised-20261027.ps1` comprueba ambas bases, runners, V16 y accesos; rechaza reutilizar un intento ya existente. No reejecutarlo con su directorio de salida ocupado.
- `s7-disable-hba-20261027.ps1` exige fase P acreditada y verifica identidad, hashes, ACL y archivo antes de retirar la regla del aprovisionador. No ejecutarlo de forma aislada ni falsificar su evidencia de precondición.
- `s7-common-20261027.ps1`, `s7-validate-admin-20261027.sql`, `s7-validate-runner-20261027.sql` y `s7-locale-20261027.sql` permiten reutilizar comprobaciones. Una recuperación deberá preparar un intento nuevo sin sobrescribir el fallido, conservando las salvaguardas.

## Barreras revisadas y comprobadas sin sockets

| Entrada / componente | Protección y destino |
| --- | --- |
| `bootstrap/app.php`, `TestDatabaseSafety` | Perfil explícito selecciona exclusivamente `.env.pgsql-testing.local`; sin fallback al `.env` cotidiano. Guarda anterior a proveedores y `RefreshDatabase` |
| `config/pgsql-testing-database.php` | Una sola conexión `pgsql`, sin URL, réplicas ni drivers alternativos |
| `PostgresTestGuard::TARGETS` | Solo `horus_l2_test` / `horus_l2_test_runner` y `horus_l2_restore_test` / `horus_l2_restore_test_runner`, siempre `127.0.0.1:55432` |
| Configuración y entorno | `APP_ENV=testing`, perfil `1`, consentimiento literal, contraseña dedicada no vacía, coincidencia de fuentes; rechazo de caché de configuración, libpq alternativo y credenciales de proveedores |
| `GuardedPostgresConnector` | Inicio en solo lectura, sonda de identidad/puerto/base/rol, UTC y READ COMMITTED, marcador con OID y privilegios limitados. Solo después habilita escrituras sobre el mismo PDO |
| Marcador | `horus_test_control.authorization`, propósito `horus-l2-destructive-tests`, no modificable por runner; `public` es esquema de trabajo. Su supervivencia real ante limpieza aún no se ha probado en esta sesión |
| Proveedores | Correo `array`, sesiones/caché `array`, cola `sync`, broadcast nulo; Laravel HTTP bloquea solicitudes sin fake en perfil PostgreSQL. No es aislamiento de red de todo el sistema operativo |
| Esquemas y concurrencia | `GuardedPostgresSchemaState` rechaza cargas `psql`/`pg_restore`; `ConcurrentTestSession` abre otra sesión con la misma fábrica/conector protegidos y revierte/cierra al terminar |

El runner `scripts/pgsql-test.php` tiene lista cerrada: `check`, `test` con filtro/stop-on-failure y comandos Artisan aprobados (`migrate:status`, `migrate`, `migrate:rollback`, `migrate:fresh`, `db:wipe`, `db:seed`). No aprovisiona servicios, bases, roles ni secretos. Todos usan la base/runner seleccionados del perfil; la suite de integridad documentada espera la base principal `horus_l2_test`.

**Advertencias de alcance:** la lista Artisan protege destinos, pero no excluye migraciones suspendidas. `check` tampoco es un preflight independiente del consentimiento: arranca la misma guarda, exige `HORUS_PG_TEST_CONFIRM` y su conector habilita escritura tras validar la sonda, aunque el comando no ejecute DDL/DML. La secuencia L2.3 que deja el consentimiento vacío y luego invoca `check` no funciona tal como está escrita. Para el primer diagnóstico se propone una sesión nativa explícitamente de solo lectura, sin modificar esta guarda.

El perfil no filtra por versión mayor de servidor: la identidad segura no sustituye comprobar `server_version_num` (18.x) cuando se autorice conectar. No se propone debilitar ninguna barrera ni modificar el runner para eludir el bloqueo actual.

## Validación ejecutada

```powershell
php artisan test --compact --filter='TestDatabaseSafetyTest|ConcurrentTestSessionTest|PostgresTestGuardTest'
```

**96 pruebas aprobadas, 366 aserciones, 15,49 segundos.** Perfil ordinario de `phpunit.xml`, sin conexiones PostgreSQL; dobles de PDO, kernel y procesos en las comprobaciones destructivas. Incluyen rechazo previo a conexión/migración, identidad falsa, marcador inseguro, configuración alterada, HTTP y sesiones independientes protegidas. No se ejecutó ninguna migración PostgreSQL ni `000022` en esta selección.

Comprobación adicional directa de `assertConfiguration()`, utilizando fixtures ficticias y sin construir conectores:

| Destino intentado en configuración | Rechazo |
| --- | --- |
| `horus_group:5432` | Base fuera de lista fija |
| `horus_group_ensayo:5432` | Base fuera de lista fija |
| `horus_l2_test:5432` | Puerto inesperado |
| `horus_group_ensayo:55432` | Base fuera de lista fija |

No se repitieron la suite funcional SQLite, Larastan, Pint, build ni pruebas frontend: no cambió código. Los resultados 951/6030 y nivel 6 limpio siguen siendo **históricos de Sprint 17.2**. Validación documental y `git diff --check` completan esta tarea.

## Pruebas no ejecutadas y dependencias

| Área | Cobertura disponible / pendiente |
| --- | --- |
| Migraciones e integridad | `MatriculaConstraintsPostgresTest`, `MatriculaRulesPostgresTest` preparados. Ninguna ejecución actual PostgreSQL; sin validación nueva de FK, índices, CHECK, repetibilidad o rollback |
| Auth, permisos, cursos, matrículas, reportes | Suites Feature incluidas en `phpunit.pgsql.xml`; no ejecutadas |
| Último cupo, duplicado y último admin | Tres casos en `ConcurrencyPostgresTest`: sesiones independientes con bloqueo mantenido, petición real espera y falla por `55P03`, luego decide tras commit. Es contención real si se ejecuta en PostgreSQL, pero la segunda operación se prepara por SQL; no son dos peticiones HTTP paralelas completas |
| Edición editorial | Pruebas de revisión/idempotencia y catálogo existentes; faltan casos PostgreSQL de dos publicaciones y retiro/actualización bajo contención entre sesiones independientes. No declarar que el arnés actual ya los cubre |
| Restauración | Existe destino `horus_l2_restore_test`, pero no se probó restauración ni se copió información real |

`RefreshDatabase` y `DatabaseTruncation` llaman a `migrate:fresh` sin lista de migraciones por defecto; un `--filter=PostgresTest` **no** evita `000022`. `Sprint14EmailVerificationTest` además la invoca directamente. Por la instrucción vigente, se detuvo antes de estas suites. No se cambió ni retiró físicamente la migración.

Alternativas para una fase futura: autorizar específicamente `000022` **solo** en la base descartable, o preparar un recorrido de migraciones por lista explícita sin `000022`, y separar las pruebas que la requieren. No se ha implementado ni ejecutado ninguna alternativa; no es válido anunciar una suite completa aprobada simplemente excluyendo tests dependientes.

`000021` editorial no tiene nueva comprobación en Testing. Su aplicación en la copia durante Fase A es histórica; no prueba el estado actual de la habitual. No se consultaron sus tablas ni `migrations` en ninguna base real.

## Recuperación propuesta: tres autorizaciones separadas

**No ejecutada.** Aprovechar el clúster existente, sin reconstruir S1–S7, eliminarlo ni cambiar la instancia cotidiana.

1. **R1: disponibilidad y diagnóstico de solo lectura.** Solicitar permiso para iniciar únicamente `postgresql-horus-l2-test` (consola elevada, `Start-Service -Name postgresql-horus-l2-test`). Verificar comando registrado/directorio, cuenta, PID y escucha exclusiva `127.0.0.1:55432` antes de SQL. Si no arranca, detener diagnóstico y conservar logs; no repetir arranques ni borrar `postmaster.pid`. Con permiso y credencial conocida, autenticar una sola vez cada runner, sin probar contraseñas alternativas. Usar `psql -X -W -h 127.0.0.1 -p 55432 -U <runner> -d <base-autorizada>`, contraseña solo en prompt, con `PGOPTIONS=-c default_transaction_read_only=on` exclusivo de ese proceso nativo. Comprobar versión 18, identidad y privilegios mediante las sondas existentes, sin DDL/DML. Esa variable no debe heredarse por Laravel: su guarda la prohíbe. No crear todavía el perfil ni consentimiento destructivo.
2. **R2: reparación condicional y cierre de S7.** Solo si R1 acredita fallo de contraseña, solicitar autorización separada para el rol afectado. Con `horus_l2_provisioner`, conexión fijada a 55432, verificar clúster/base antes de usar `\password horus_l2_test_runner` (o el runner de restauración únicamente si también falla). Afecta un rol del clúster Testing y su base correspondiente; no roles globales de la instancia 5432. Sin contraseñas en SQL guardado, argumentos, historial o informe. Actualizar un perfil aislado con APP_KEY exclusiva y secretos protegidos solo si se autoriza crearlo. Reacreditar ambos runners, V12/V16 y restricciones de acceso; después, con autorización de edición HBA/recarga, desactivar exclusivamente la regla `# L2-PROVISIONER` preservando DPAPI, ACL y evidencias anteriores. Reutilizar las comprobaciones, no volver a ejecutar el intento fallido ni fabricar un cierre `S7Complete=true`.
3. **R3: integración descartable.** Resolver primero la exclusión de `000022`, verificar que no hay datos reales ni esquemas ajenos y que el marcador queda protegido. Solo después usar consentimiento y runner seguro para etapas acotadas: migraciones permitidas, restricciones, reglas, concurrencia y suites funcionales compatibles. Añadir casos editoriales de contención. Ante primer fallo, distinguir infraestructura/aplicación y detener esa etapa; no ampliar timeouts ni debilitar validaciones.

La cuenta, el servicio, las dos bases y la configuración de `horus_group`/ensayo en 5432 quedan fuera de las tres intervenciones. No se propone usar ensayo como sustituto. No se aplican cambios de privilegios mínimos de la aplicación cotidiana durante esta recuperación.

## Cierre de la inspección inicial (anterior a R1)

No hubo conexiones SQL PostgreSQL ni escrituras en habitual, ensayo o Testing. No se iniciaron/detuvieron servicios, cambiaron contraseñas, editaron HBA/ACL/DPAPI, ejecutaron migraciones, enviaron correos o consultaron FastAPI/OpenAI/Resend. Sin commits, push, despliegue ni modificaciones de stashes. Los flags de catálogo web, catálogo PHP, Advisor y verificación conservan sus valores predeterminados apagados; no se alteró la configuración de envío de matrícula.

Antes de intervenir en la habitual: finalizar Testing, revisar resultados reales, autorizar base/ventana/backup nuevo y migraciones individuales excluyendo `000022`. Los ensayos SQLite o el acta Fase A no sustituyen esta aceptación.

**Sprint 18:** puede diseñarse su contrato sin tocar infraestructura; la validación PostgreSQL solicitada no está terminada ni sirve aún como criterio de aceptación para ese sprint. En ese momento se solicitó autorización R1; su ejecución posterior se registra a continuación. Ninguna reparación de contraseña ni cambio HBA está implícita en R1.

## R1 autorizada — arranque y diagnóstico del 29/10

El propietario autorizó expresamente comprobar identidad, iniciar únicamente `postgresql-horus-l2-test`, inspeccionar configuración/registros y dejarlo disponible si se conserva el aislamiento. Prohibió reparaciones, cambios de perfil/HBA/roles, migraciones y pruebas con escrituras. Los dos cambios documentales de la inspección anterior ya estaban pendientes al comenzar R1 y se preservaron.

### Identidad comprobada antes del arranque

- Registro SCM exacto: `"C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe" runservice -N "postgresql-horus-l2-test" -D "C:\pgdata\horus_l2_test" -w`.
- Cuenta exclusiva `NT SERVICE\postgresql-horus-l2-test`, inicio manual, PID inicial 0. Directorio resuelto sin punto de reanálisis y `PG_VERSION=18`.
- `postgres.exe -D C:\pgdata\horus_l2_test -C <parámetro>` ejecutado sin elevación: directorio de datos, archivo de configuración y HBA pertenecen a ese directorio; puerto efectivo 55432, escucha efectiva `127.0.0.1`, `cluster_name=horus_l2_test`, SCRAM. No se inició PostgreSQL con esos comandos `-C`.
- Puerto libre antes de arrancar. Se tomaron hashes SHA-256 de `postgresql.conf`, `postgresql.auto.conf` y `pg_hba.conf`, además de `.env` y `bootstrap/app.php`, sin imprimir secretos.

La consola no estaba elevada: el primer `Start-Service` fue rechazado por SCM al abrir el servicio. Un primer auxiliar elevado abortó en la revalidación de configuración, antes de `Start-Service`; esa comprobación no se consideró satisfactoria ni inició el motor. Se mantuvo la lectura efectiva en la consola sin elevación y un segundo auxiliar elevado comprobó la identidad SCM, el puerto y los hashes exactos de los archivos ya validados antes de arrancar. No se modificaron ACL ni configuración para resolver la elevación. Hubo un único arranque efectivo, confirmado a `2026-10-29T15:12:39.1232441Z` (10:12:39, Lima).

### Estado después del arranque

| Aspecto | Resultado verificado |
| --- | --- |
| Servicio aislado | `Running / Manual`, PID SCM `2548`, misma cuenta y comando |
| Proceso que escucha | `postgres.exe` PID `19800`, hijo directo del `pg_ctl.exe` PID `2548` del servicio aislado |
| Puerto | Único listener observado en 55432: `127.0.0.1:55432`, PID `19800`; sin listener IPv6 ni en otras interfaces de ese puerto |
| Disponibilidad | `pg_isready` con destino explícito 127.0.0.1:55432: `accepting connections`; no prueba autenticación |
| Archivo `postmaster.pid` | PID `19800`, directorio `C:/pgdata/horus_l2_test`, puerto `55432`, escucha `127.0.0.1`, estado `ready` |
| Identidad física | `pg_controldata -D C:\pgdata\horus_l2_test`: identificador `7701505916098505688`, estado `in production`; coincide con el acta histórica S3. Lectura física, no SQL |
| Servicio cotidiano | `postgresql-x64-18` permaneció `Running / Auto`, mismo PID `4972`; no se conectó a sus bases ni se intervino |
| Integridad de archivos protegidos | Los cinco hashes previos siguen idénticos. `.env.pgsql-testing.local` continúa ausente |

Se dejó Testing **en ejecución**, conforme a la autorización. La regla del aprovisionador sigue activa solo en el HBA aislado; es deuda previa de cierre S7, no una ampliación de acceso realizada aquí. No se observó exposición fuera de loopback en 55432.

### Registros y autenticación: evidencia y límites

Lectura directa del log histórico `postgresql-2026-10-27_195253.log` (SHA-256 `3c0cf8bd110bdd605ff2c5c91bd71728089120f6a5d86efeed0a43456db6d3fb`):

| Líneas / hora local del 27/10 | Evidencia |
| --- | --- |
| 31–32, 20:12:29.866 | Runner principal autenticado y conexión autorizada |
| 35–36, 20:12:35.240 | Runner de restauración autenticado y conexión autorizada |
| 61, 23:02:40.418 | `password authentication failed` para `horus_l2_test_runner` en `horus_l2_test` |
| 62, misma hora | La conexión coincidió con la línea 2 de HBA: pareja base/runner principal, `127.0.0.1/32`, `scram-sha-256` |

Esto sitúa el fallo S7 en la comprobación de contraseña SCRAM, después de seleccionar la regla correcta; no fue una caída de servicio ni un rechazo por falta de regla HBA. **Causa probable:** la contraseña introducida en S7 no correspondía al verificador del runner. El registro no demuestra si se introdujo otra contraseña, si hubo un cambio previo del secreto o un problema de entrada interactiva. El éxito anterior de ambos runners no acredita sus contraseñas actuales. No se ensayaron contraseñas, no se leyó `pg_authid` ni se extrajeron secretos del almacén DPAPI.

El log actual `postgresql-2026-10-29_101233.log` registra cierre anterior no limpio, recuperación automática, fin del redo y checkpoint de fin de recuperación. El mensaje `invalid record length ... got 0` está seguido de redo y checkpoint completados; por sí solo no se presenta como corrupción. No se encontraron eventos `FATAL`/`PANIC` en el tramo de arranque inspeccionado; servicio y listener quedaron disponibles. No se borró manualmente el PID residual ni se ejecutó recuperación forzada. El arranque implica las escrituras internas normales de recuperación/log/checkpoint de PostgreSQL, autorizadas implícitamente por arrancar el servicio: **no** hubo DDL/DML ni escrituras de pruebas o aplicación.

No se abrió sesión SQL autenticada: no hay perfil local ni contraseña de runner disponible mediante el entorno autorizado. No se consultaron bases habituales ni ensayo. La existencia/estado interno de las dos bases Testing, sus privilegios, marcador y versión SQL del servidor quedan pendientes de una sesión nativa de solo lectura con una credencial conocida. No se ejecutó `pgsql-test.php check`, pues su conector habilita escrituras tras la sonda.

### Propuesta R2 mínima, pendiente de aprobación

1. Con Testing ya disponible, realizar una única autenticación interactiva por runner con contraseña conocida, destino fijo y sesión nativa de solo lectura; no enviar contraseñas en chat ni argumentos. Verificar versión, identidad, bases, privilegios y marcador mediante las sondas existentes. Si la credencial funciona, **no restablecerla**.
2. Si el runner principal falla y el propietario autoriza expresamente su reparación, conectar como aprovisionador solo al clúster `7701505916098505688` en 55432 y usar `\password horus_l2_test_runner`. No modificar el runner de restauración si no se ha acreditado también su fallo. Sin crear bases, modificar permisos globales ni tocar 5432.
3. Revalidar ambos runners y las condiciones S7. La creación del perfil local y el cierre de la regla del aprovisionador con recarga son acciones posteriores que necesitan autorización explícita; no están incluidas en este diagnóstico ni se proponen como reparación automática.

La exclusión de `000022` del recorrido de pruebas sigue sin resolverse. Ni R1 ni esta propuesta autorizan suites PostgreSQL, migraciones, seeders o truncamientos. No se repitieron tests en R1; las 96 pruebas/366 aserciones corresponden a la inspección anterior. Solo se actualizan esta acta y el contexto; sin cambios funcionales, commits, push, despliegue o activaciones de funcionalidades.

## R2-A — identidad revalidada; entrada interactiva pendiente

29/10/2026, comprobaciones locales alrededor de las 10:21 (Lima). Autorización limitada a autenticación nativa de los dos runners y consultas de solo lectura; no incluye reparación ni integración. Al comenzar permanecían pendientes únicamente este informe y `PROJECT_CONTEXT.md`, de la inspección/R1 anterior. Rama `anderson`, HEAD `306b168e8d8bbfec23301d7c050c7f7c9ae11d2f`; índice y dos stashes preservados.

### Preflight actual

- SCM: `postgresql-horus-l2-test`, `Running / Manual`, cuenta `NT SERVICE\postgresql-horus-l2-test`, PID 2548; comando registrado con `pg_ctl.exe` de PostgreSQL 18 y `-D C:\pgdata\horus_l2_test`.
- Único listener observado en 55432: `127.0.0.1`, PID 19800 (`postgres.exe`), hijo del PID 2548 (`pg_ctl.exe`). No se abrió conexión al puerto 5432 ni se realizó acción de servicio.
- `postmaster.pid`: mismo PID/directorio/puerto, escucha loopback y estado `ready`.
- `pg_controldata` sobre ese directorio: identificador físico `7701505916098505688`, estado `in production`. Esta evidencia física no sustituye una sonda SQL de versión, base, usuario o privilegios.

### Disponibilidad legítima de credenciales

Se inspeccionó como texto, sin ejecutar, el procedimiento previo: `s7-supervised-20261027.ps1` líneas 7–11 y `s6-supervised-20261027.ps1` líneas 77–81 usan `psql -X -W` con destino explícito. El resultado previsto de S6, línea 174, declara contraseñas interactivas no capturadas. La mera existencia de `identity.dpapi` no demuestra que contenga contraseñas recuperables; no se abrió ni descifró.

No estaban presentes las variables de proceso `PGPASSWORD`, `PGPASSFILE`, `PGSERVICE`, `PGSERVICEFILE` ni `HORUS_PG_TEST_PASSWORD` en la inspección de nombres. `.env.pgsql-testing.local` y el archivo predeterminado `%APPDATA%\postgresql\pgpass.conf` no existen. No se buscó un secreto en el `.env` cotidiano, archivos SQL de aprovisionamiento, historiales o registros, ni se intentó recuperar contraseñas cifradas. No se afirma ausencia absoluta de credenciales en todo el equipo: no hay una disponible mediante las fuentes seguras inspeccionadas y el procedimiento exige entrada local del propietario.

| Runner / destino exclusivo | Autenticación R2-A | Intentos | Versión SQL, privilegios y marcador |
| --- | --- | --- | --- |
| `horus_l2_test_runner` → `horus_l2_test` | No comprobada | 0 | No comprobados actualmente |
| `horus_l2_restore_test_runner` → `horus_l2_restore_test` | No comprobada | 0 | No comprobados actualmente |

Se detiene antes de conectar para solicitar entrada interactiva del propietario en el prompt local oculto de `psql -W`; nunca por chat, argumentos, historial o archivo temporal. Cuando esté disponible, revalidar identidad y puerto y permitir como máximo un intento por runner, conexión explícita `127.0.0.1:55432`, `-X`, `ON_ERROR_STOP`, sin destinos alternativos y `default_transaction_read_only=on` desde el inicio del proceso nativo. Mantener esa configuración limitada al proceso de `psql`, sin heredarla por Laravel. Reutilizar las consultas revisadas de `s7-validate-runner-20261027.sql` (solo lectura), añadir versión de servidor y mostrar únicamente resultados técnicos permitidos. No ejecutar el script S7 completo: contiene acciones fuera del alcance R2-A.

El rechazo histórico del 27/10 no prueba un fallo actual. **No hay evidencia nueva que justifique restablecer ninguna contraseña, por lo que no se propone ejecutar R2-B.** Si el único intento autorizado de un runner falla por autenticación, conservar su resultado y solicitar una autorización independiente y específica antes de proponer reparación de ese rol. Un error de privilegios o marcador después de autenticar tampoco acredita una contraseña inválida.

Sin sesiones SQL, cambios de credenciales/configuración/HBA/ACL/perfiles, migraciones, `000022`, suites, escrituras de aplicación, reinicios, commits ni push en R2-A. No se usó `pgsql-test.php check`. El servicio queda en ejecución; esta pausa no es aceptación de S7 ni de integración PostgreSQL. Solo se actualizan los dos documentos; `git diff --check` se verifica al cerrar.
## R2-B — preparación de recuperación por pérdida de credenciales

29/10/2026, alrededor de 10:25 Lima. El propietario comunica que no dispone de las contraseñas originales de los runners y autoriza **solo preparar/verificar el procedimiento**. Esto introduce una causa operativa de renovación distinta de un fallo de autenticación probado: pérdida de disponibilidad de credenciales. No se atribuye daño a ninguno de los roles. Las conclusiones de R2-A sobre cero intentos siguen vigentes.

### Verificaciones actuales y mecanismo recuperable

Servicio aislado `Running / Manual`, PID 2548, cuenta dedicada y comando SCM apuntando a `C:\pgdata\horus_l2_test`. Único listener en 55432: `127.0.0.1`, PID 19800, `postgres.exe` hijo del servicio. Control físico: `7701505916098505688`, `in production`. Servicio cotidiano `postgresql-x64-18` permanece Running/Auto, PID 4972, directorio distinto; no se conectó a 5432.

El HBA aislado conserva la regla `# L2-PROVISIONER`: rol `horus_l2_provisioner`, solo `127.0.0.1/32`, autenticación **scram-sha-256**, no `trust`. El procedimiento S6 usa contraseña administrativa interactiva `psql -W` y `\password` para los runners. No se ejecutaron S6/S7 ni se descifró DPAPI; no se deben repetir `00-cluster.sql`, `initdb`, creación de roles/bases o scripts completos para renovar contraseñas.

**Dependencia bloqueante para ejecutar:** el propietario debe disponer de la contraseña conocida del aprovisionador. Su autenticación actual no está comprobada. Si no la conoce o un único intento falla, detenerse: no existe una entrada sin contraseña demostrada por esta inspección; HBA alternativo, modo monousuario y recuperación del aprovisionador exigirían otro análisis y autorización. No intentar contraseñas alternativas ni cambiar la cotidiana.

| Rol | Diagnóstico actual | Propuesta futura |
| --- | --- | --- |
| `horus_l2_test_runner` | Contraseña no disponible; rechazo SCRAM histórico, validez actual desconocida | Renovar solo si se autoriza expresamente, para recuperar el destino principal |
| `horus_l2_restore_test_runner` | Contraseña no disponible; sin fallo actual demostrado | Renovación independiente si se necesita recuperar también restauración; no automática por renovar el principal |
| `horus_l2_provisioner` | Disponibilidad de contraseña y autenticación actuales no comprobadas | Solo autenticar con credencial conocida; no renovar en este procedimiento |
| `horus_l2_owner` | Sin evidencia de incidencia; no necesita contraseña para este procedimiento | No modificar |

Para recuperar operativamente **ambos** runners cuyas contraseñas se perdieron, se propone autorizar nominalmente ambas renovaciones. Si solo se autoriza el principal, el segundo permanece pendiente. Los roles son globales dentro del clúster Testing, no particulares de una base: cambiar su contraseña afecta todas sus futuras autenticaciones en ese clúster, sin alterar permisos ni roles homónimos de otra instancia.

### Procedimiento exacto propuesto, NO ejecutado

Requiere autorización posterior que nombre el rol o los dos roles a renovar y permita autenticar al aprovisionador contra la base de mantenimiento `postgres` **del clúster Testing**, consultar identidad y renovar exclusivamente sus contraseñas. La autorización R2-A anterior solo permitía conexiones de runners: no cubre esta sesión administrativa.

1. En una terminal local privada, sin transcripción, grabación, redirección de salida o captura de teclado, revalidar inmediatamente SCM, directorio, árbol de procesos, listener único y `pg_controldata` con los valores anteriores. Si alguno difiere, detenerse. Comprobar por nombre, sin imprimir valores, que no se heredan variables `PG*` ni configuraciones de conexión alternativas. No cambiar servicios. No cargar `.env` ni perfil Laravel.
2. Con credencial conocida del aprovisionador, ejecutar **una vez**, solo tras la autorización futura, este comando PowerShell. El parámetro `options` fija solo lectura desde el inicio; `HISTFILE=NUL` evita guardar el historial de psql. La contraseña solo se introduce en su prompt oculto:

```powershell
& 'C:\Program Files\PostgreSQL\18\bin\psql.exe' -X -W `
  --dbname="host=127.0.0.1 port=55432 dbname=postgres user=horus_l2_provisioner connect_timeout=3 application_name=horus-r2b options='-c default_transaction_read_only=on'" `
  --set=ON_ERROR_STOP=on --set=HISTFILE=NUL
```

Si falla, cancelar la fase, sin reintentos ni cambios de HBA. Si conecta, pegar solo la siguiente sonda y revisar `identidad_ok=t` y los datos técnicos. No contiene ni consulta contraseñas/verificadores:

```sql
BEGIN READ ONLY;
SELECT version(), inet_server_addr(), inet_server_port(),
       current_database(), current_user, session_user,
       current_setting('cluster_name'), current_setting('data_directory'),
       current_setting('transaction_read_only'),
       (SELECT system_identifier::text FROM pg_control_system()) AS system_identifier;
SELECT COALESCE(
  inet_server_addr() = '127.0.0.1'::inet
  AND inet_server_port() = 55432
  AND current_database() = 'postgres'
  AND current_user = 'horus_l2_provisioner'
  AND session_user = current_user
  AND current_setting('server_version_num')::int BETWEEN 180000 AND 189999
  AND current_setting('cluster_name') = 'horus_l2_test'
  AND replace(current_setting('data_directory'), chr(92), '/') = 'C:/pgdata/horus_l2_test'
  AND current_setting('listen_addresses') = '127.0.0.1'
  AND current_setting('transaction_read_only') = 'on'
  AND current_setting('password_encryption') = 'scram-sha-256'
  AND (SELECT system_identifier::text FROM pg_control_system()) = '7701505916098505688'
  AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname=current_user AND rolsuper)
  AND (SELECT count(*) FROM pg_roles WHERE rolname IN
      ('horus_l2_test_runner','horus_l2_restore_test_runner') AND rolcanlogin) = 2,
  false) AS identidad_ok \gset
\echo identidad_ok=:identidad_ok
```

Si hay error o no se obtiene `t`, ejecutar `\q` y detenerse. La sonda no certifica los permisos completos de runners ni su marcador. No usar `pg_authid` ni copiar logs crudos.

3. Solo con la identidad aceptada y autorización explícita de renovación, pegar el siguiente bloque para **el principal**. La condición vuelve a impedir el cambio cuando la sonda no fue satisfactoria. El cambio de solo lectura es únicamente de esta sesión, necesario para renovar la contraseña; no modifica configuración persistente:

```sql
\if :identidad_ok
COMMIT;
SET default_transaction_read_only = off;
\password horus_l2_test_runner
SET default_transaction_read_only = on;
\else
\quit
\endif
```

El propietario introduce y confirma la nueva contraseña únicamente en los prompts de `\password`. No escribir `ALTER ROLE ... PASSWORD '...'`, no copiar el secreto a comandos, variables, archivos, informes o chat. Custodiarlo mediante el gestor de contraseñas del propietario, fuera del repositorio; no crear perfil/archivo de credenciales. No prometer atomicidad entre renovaciones: cada `\password` es una operación independiente; ante error o cancelación detener y conservar solo el resultado técnico.

4. **Solo si también se autorizó nominalmente restauración**, y la misma sesión/identidad siguen válidas, ejecutar después:

```sql
\if :identidad_ok
SET default_transaction_read_only = off;
\password horus_l2_restore_test_runner
SET default_transaction_read_only = on;
\else
\quit
\endif
\q
```

Si no se autorizó el segundo rol, cerrar con `\q` tras el paso 3. No usar `\connect`, no cambiar de destino ni ejecutar otros comandos administrativos. No cambiar contraseña del aprovisionador ni de `horus_l2_owner`.

5. En una fase posterior que incluya esa validación, hacer una sola autenticación por runner renovado, `127.0.0.1:55432` y su base exacta, solo lectura desde el inicio, para comprobar versión, identidad, permisos y marcador con las sondas R2-A existentes. Si falla, detener ese rol. Un resultado de `\password` no acredita por sí solo una conexión nueva ni completa S7. Perfil Laravel, cierre HBA, recarga y migraciones siguen fuera del procedimiento.

Según la [documentación oficial de psql 18](https://www.postgresql.org/docs/18/app-psql.html), `\password` evita que la nueva contraseña aparezca en texto claro en historial y registros del servidor. No garantiza ausencia de registro de un verificador cifrado ante configuraciones de auditoría distintas: antes de ejecutar, revisar configuración efectiva de logging de la sesión y módulos de auditoría; si pudiera registrar material de credenciales, detenerse y revisar el plan, sin desactivar auditoría por iniciativa propia. Lectura offline actual: `log_statement=none`, `log_min_error_statement=error`, `shared_preload_libraries` vacío; no demuestra configuración efectiva por rol/base ni auditoría en una sesión no abierta. No activar eco SQL (`-a`/`-e`), `\o` ni logging cliente.

### Autorización posterior sugerida

> Autorizo ejecutar la recuperación preparada únicamente en el clúster `7701505916098505688`, `127.0.0.1:55432`, usando una credencial conocida de `horus_l2_provisioner` contra su base de mantenimiento `postgres`, validación de identidad y renovación interactiva mediante `\password` de `horus_l2_test_runner` [y, si se desea recuperar también restauración, `horus_l2_restore_test_runner`]. Autorizo la comprobación posterior de cada runner renovado mediante una única sesión nativa de solo lectura contra su base permitida. No autorizo HBA, ACL, perfiles, reinicios, cambios de permisos/otros roles, migraciones, `000022` ni pruebas destructivas. Si la credencial del aprovisionador no está disponible o falla, detenerse sin reparación alternativa.

La frase debe nombrar explícitamente los roles elegidos, sin conservar el texto entre corchetes como autorización ambigua. Esta propuesta no es autorización ni comando ejecutado. No se realizaron sesiones SQL ni cambios persistentes de PostgreSQL en esta preparación. Servicio cotidiano, índice, stashes y trabajo previo conservados; solo se amplía este informe y la nota de contexto. Sin ejecución de suites; validación documental y `git diff --check` al cierre.
## R2-C — recuperación administrativa sin contraseñas: plan, NO ejecutado

29/10/2026, alrededor de 10:30 Lima. El propietario confirma pérdida de las contraseñas de ambos runners **y del aprovisionador**. La autorización actual permite únicamente inspección y planificación. Por tanto R2-B ya no es ejecutable tal como estaba: exige una contraseña administrativa conocida. Ningún bloque de esta sección está autorizado para ejecución todavía.

### Estado comprobado y permisos Windows

- Rama `anderson`, HEAD `306b168e8d8bbfec23301d7c050c7f7c9ae11d2f`; permanecen únicamente los dos documentos pendientes, índice vacío y dos stashes intactos.
- Servicio Testing `Running / Manual`, cuenta `NT SERVICE\postgresql-horus-l2-test`, SCM PID 2548. Comando: `pg_ctl.exe runservice -N postgresql-horus-l2-test -D C:\pgdata\horus_l2_test -w`, binarios de `C:\Program Files\PostgreSQL\18\bin`.
- Único listener observado en 55432: `127.0.0.1`, PID 19800, proceso hijo directo del servicio. `postmaster.pid` coincide. `pg_controldata`: `7701505916098505688`, `in production`.
- Propietario NTFS del directorio: cuenta local del propietario del proyecto (distinta de la cuenta virtual del servicio). ACL visibles del directorio y HBA: FullControl para dicha cuenta, cuenta virtual Testing, Administradores y SYSTEM. No aparecen entradas amplias para Users/Everyone en esas ACL. El directorio hijo y HBA heredan; `C:\pgdata` tiene DACL protegida. No se modificaron propietario ni ACL; esta lectura no es un examen exhaustivo de cada archivo ni prueba de todas las autorizaciones efectivas.
- Directorio sin reparse point; `pg_wal` es directorio normal y `pg_tblspc` no mostró entradas. Comprobar de nuevo antes de cualquier backup frío; no asumir ausencia futura de enlaces o tablespaces.
- Consola actual de integridad media, Administradores como deny-only: **no elevada**. El control SCM requiere una consola realmente elevada; eso no convierte al operador en la cuenta virtual del servicio ni acredita ejecución de `postgres --single` bajo cualquier token.
- Servicio habitual `postgresql-x64-18` Running/Auto, PID 4972, cuenta NetworkService, directorio distinto. Solo se leyó su metadata SCM; sin conexión ni acción en 5432.

Hashes originales actuales para la restauración posterior (no secretos):

| Archivo en `C:\pgdata\horus_l2_test` | SHA-256 |
| --- | --- |
| `pg_hba.conf` | `B8A55054AD0D1C018B25A28C15D170D12B09562DEB7733B0F2D7DC4B37E6DB30` |
| `postgresql.conf` | `923E2430C0EB80A4F230D8B16C474DDF36F5C2AFD84D4F63731B49D7C869B9BF` |
| `postgresql.auto.conf` | `EF0843FCE52217A6815D36E76A88F618494C851EFC3EB9E1A794FE0D8DCCDD81` |

### Alternativas oficiales y recomendación condicionada

| Mecanismo | Ventaja | Riesgo / límite en este equipo |
| --- | --- | --- |
| `postgres --single` fuera de línea | Poder administrativo local sin contraseña ni autenticación de red; no necesita cambiar HBA | Requiere exclusividad física y apagado completo. No es psql: `\password` no existe y pegar un `ALTER ROLE ... PASSWORD` mostraría el secreto. Token Windows/cuenta de ejecución y entrada secreta necesitan un auxiliar revisado; viabilidad operacional no probada aquí |
| HBA temporal, regla exacta de mantenimiento + `psql` | Reutiliza prompts ocultos oficiales `\password`, sin generar/guardar SQL con secretos ni un auxiliar criptográfico | Durante la ventana `trust`, otro proceso/usuario local puede asumir ese superusuario. Loopback y ACL del archivo **no** autentican al cliente TCP. Solo admisible bajo exclusividad local efectiva y aceptación expresa de ese riesgo |
| SSPI temporal con mapa Windows | Autentica identidad Windows, evita `trust` | Necesita comprobar SSPI, identidad/realm y mapa real; cambia HBA y pg_ident. No se ha probado y no hay una configuración existente acreditada para reutilizar. No dar como listo un mapa supuesto |

**Recomendación:** para mantener prompts oficiales sin desarrollar manejo nuevo de secretos, preparar una ventana supervisada de HBA temporal extremadamente restringida, restaurándolo **antes de introducir las nuevas contraseñas**. Es la alternativa práctica de menor complejidad para este equipo, **condicionada** a que el propietario pueda garantizar exclusividad local durante la ventana. No se declara más segura universalmente que monousuario: si hay otros usuarios/procesos no confiables o no se acepta esa ventana, no usar `trust`; preparar primero el auxiliar de entrada oculta para monousuario o verificar SSPI en un alcance separado. No ejecutar ninguno como fallback automático.

PostgreSQL documenta que `trust` acepta al usuario indicado sin contraseña; las ACL del directorio no restringen clientes TCP. En Windows los cambios HBA se aplican a nuevas conexiones sin esperar recarga: no hay que confundir «no ejecutar reload» con «el cambio todavía no afecta». Fuentes: [trust](https://www.postgresql.org/docs/18/auth-trust.html), [HBA y particularidad Windows](https://www.postgresql.org/docs/18/auth-pg-hba-conf.html).

### Prerrequisitos de la ruta HBA temporal

Autorizar expresamente: parada/arranque **solo** de Testing, backup frío completo protegido del clúster aislado y copias de configuración/ACL, sustitución temporal y restitución exacta del HBA, sesión administrativa contra `postgres` del clúster Testing, renovación de las tres contraseñas nominales y validaciones positivas/negativas acotadas. No incluye crear rol, modificar privilegios, perfil Laravel, cerrar S7, migrar ni ejecutar tests.

El propietario debe estar presente en una terminal local privada, sin transcripción/captura de teclado/grabación ni redirección de entrada/salida. Mantener libre la ventana de otros usuarios/aplicaciones locales, sin asumir que cerrar Laravel impide a cualquier proceso del equipo acceder a loopback. Si no se puede garantizar esa condición, detenerse antes de cambiar HBA. No cambiar firewall ni desconectar otras sesiones Windows por iniciativa propia.

Antes de editar, usar un directorio de evidencia nuevo, protegido por ACL, fuera del repositorio y de carpetas sincronizadas. Guardar bytes y ACL originales, hashes e identidad; nunca contraseñas, verificadores, logs SQL crudos o un backup de la instancia habitual. Una copia completa del clúster contiene verificadores existentes y debe protegerse como material sensible: si la prohibición de «archivos con contraseñas» incluye también todo backup con verificadores, no hacerla sin aclaración/consentimiento específico. No basta copiar archivos de un clúster activo como supuesto backup consistente. Véase [backup físico](https://www.postgresql.org/docs/18/backup-file.html).

### Secuencia exacta propuesta para autorización posterior

**0. Revalidación inmediata, sin mutaciones.** Contrastar nombre/cuenta/comando SCM/directorio/PG_VERSION=18/control físico/listener/PID-hijo contra el estado anterior, y baseline SCM de la habitual. Confirmar hashes y ACL. Inspeccionar solo nombres de variables `PG*`; no heredar destinos, servicios ni credenciales alternativos. No cargar `.env`. Cualquier discrepancia detiene la fase.

**1. Apagado correcto del aislado.** En consola con derechos comprobados, el comando propuesto es:

```powershell
& 'C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe' stop `
  -D 'C:\pgdata\horus_l2_test' -m fast -w -t 60
```

`fast` revierte transacciones abiertas y realiza apagado limpio; no es `immediate`. Tras el comando comprobar código 0, SCM Stopped, desaparición del proceso y de sus hijos, puerto 55432 sin listener, ausencia de `postmaster.pid`, log de apagado terminado y `pg_controldata` con `shut down`. Un timeout puede dejar una parada aún en curso: inspeccionar y detener el procedimiento, no repetir/forzar ni borrar PID. Si SCM no converge, no editar ni arrancar otro proceso. No usar Stop-Process, taskkill, immediate ni acciones en la habitual. El comando aún **no se ha probado** bajo elevación en esta fase. Fuentes: [pg_ctl](https://www.postgresql.org/docs/18/app-pg-ctl.html), [apagado](https://www.postgresql.org/docs/18/server-shutdown.html).

**2. Protección previa, con servicio detenido.** Crear el backup frío completo autorizado con metadata/ACL y verificar su inventario/huellas; incluir WAL y cualquier tablespace/enlace que la nueva inspección detecte, sin seguir destinos ajenos. No restaurar ni sobrescribir datos durante esta operación. Preparar una copia binaria independiente del HBA y su descriptor de seguridad para restitución; validar que los archivos de configuración siguen iguales. No dejar una copia SQL ni credenciales nuevas en archivos.

**3. Única modificación temporal HBA.** Sustituir exclusivamente la primera regla del aprovisionador por esta regla, conservando íntegros el resto y las reglas de rechazo:

```text
host  postgres  horus_l2_provisioner  127.0.0.1/32  trust  # R2-C TEMPORAL
```

No añadir `host all all`, IPv6, otras bases, otros roles o redes. No cambiar las dos reglas SCRAM de runners. Realizar sustitución controlada con comprobación del original y preservación de ACL; no escribir un HBA parcial. No usar la regla temporal si no se dispone de restitución binaria verificada. No tocar `postgresql.conf`, `postgresql.auto.conf` ni `pg_ident.conf`.

**4. Arranque aislado y conexión de recuperación.** Autorizar/ejecutar únicamente:

```powershell
Start-Service -Name 'postgresql-horus-l2-test'
```

Comprobar Running/Manual, cuenta/comando originales, nuevo PID y padre SCM correctos, listener solo 127.0.0.1:55432, control físico idéntico y ausencia de errores de arranque. Si falla, restaurar HBA con el servicio detenido, preservar evidencia y parar; no reintentar. Abrir una única sesión local, sin contraseña y con solo lectura inicial:

```powershell
& 'C:\Program Files\PostgreSQL\18\bin\psql.exe' -X -w `
  --dbname="host=127.0.0.1 port=55432 dbname=postgres user=horus_l2_provisioner connect_timeout=3 application_name=horus-r2c options='-c default_transaction_read_only=on'" `
  --set=ON_ERROR_STOP=on --set=HISTFILE=NUL
```

No guardar secretos en variables `PGPASSWORD`/pgpass/argumentos. Usar las dos consultas de identidad de R2-B (BEGIN READ ONLY, SELECT técnicos y `identidad_ok`), que comprueban dirección/puerto, servidor 18, base, usuario/sesión, clúster físico y ruta exactos, SCRAM y roles existentes. Verificar `identidad_ok=t`. Error o discrepancia: no renovar nada; ejecutar restitución de seguridad y detener. No conectar a otras bases de mantenimiento ni a 5432.

**5. Restituir seguridad ANTES de cambiar contraseñas.** Conservar abierta esa sesión de recuperación. Desde la consola de archivos, restituir los bytes originales de HBA y su ACL; comprobar el hash exacto anterior. En Windows las nuevas conexiones volverán a usar SCRAM. La sesión ya autenticada continúa abierta; restaurar HBA no revoca sesiones existentes.

Desde la sesión de recuperación revisar `pg_hba_file_rules` (cero errores, cinco reglas, sin trust y con las tres reglas SCRAM originales) y `pg_stat_activity` para el rol aprovisionador. Si se detecta otra sesión privilegiada inesperada o no se logra restaurar, detener limpiamente solo Testing y cerrar la fase; no dejar el servidor aceptando `trust`. No terminar sesiones selectivamente ni asumir ausencia de intrusión solo por un hash.

Como comprobación adicional expresamente autorizada, desde otro proceso limpio y sin fuentes de contraseñas, intentar **una sola** conexión `psql -X -w` como aprovisionador al destino fijo: debe fallar por requerir contraseña bajo SCRAM. No introducir una contraseña alternativa. El resultado esperado se registra como control negativo, no como incidencia del rol. Si conecta sin contraseña, no continuar: preservar/restaurar configuración y dejar Testing detenido para revisión. No avanzar solo porque un reload devolvió true; en esta ruta no es necesario ejecutar reload.

**6. Renovación nominal, con HBA original y sesión administrativa existente.** Comprobar en esa misma sesión `password_encryption=scram-sha-256`, `log_statement=none`, configuración efectiva de auditoría/logging y ausencia de eco SQL. No desactivar auditoría para sortear una discrepancia: detenerse si pudiera registrar material de credenciales. La lectura offline actual de `log_statement=none`, collector on y shared_preload_libraries vacío no certifica valores por rol/base.

Finalizar la transacción de solo lectura con `COMMIT;`, habilitar escritura **solo en la sesión** con `SET default_transaction_read_only=off;` y ejecutar uno por uno, solo tras la autorización nominal de los tres roles:

```text
\password horus_l2_provisioner
\password horus_l2_test_runner
\password horus_l2_restore_test_runner
```

Cada comando se introduce y completa por separado: dos prompts ocultos para su nueva contraseña. No pegar contraseñas ni automatizar respuestas. Después de cada operación, verificar éxito antes de continuar. No hay atomicidad conjunta; si falla alguna, registrar únicamente qué roles quedaron renovados y detener, sin repetir ni restaurar contraseñas desconocidas. La nueva credencial administrativa no desconecta la sesión actual. Terminar con `SET default_transaction_read_only=on;` y `\q`. No tocar `horus_l2_owner` ni ningún atributo/permiso de roles. Guardar secretos en el gestor privado del propietario, nunca archivos del proyecto. [psql y \password](https://www.postgresql.org/docs/18/app-psql.html).

**7. Validación posterior sin escrituras.** Con HBA/ACL originales verificados, abrir como máximo una conexión con `-W`, solo lectura desde el inicio, por cada credencial renovada: aprovisionador→postgres (solo identidad/admin), runner principal→horus_l2_test y runner restore→horus_l2_restore_test. Reutilizar consultas R2-A de versión, usuario, base, permisos efectivos y marcador. No reejecutar scripts S6/S7 completos; no leer/verificar contraseñas mediante `pg_authid`. Si una validación falla, distinguir autenticación de permisos y detener ese rol, sin reintento ni corrección automática. Registrar S7 todavía incompleto aunque autentiquen los tres.

Cerrar todas las sesiones propias y comprobar estado final Running/Manual, identidad física igual, listener loopback único, hashes/ACL de las tres configuraciones idénticos al inicio, servicio habitual sin intervención y Git/stashes intactos. No se requiere segundo reinicio si HBA original y autenticación SCRAM quedan acreditados. La regla original del aprovisionador permanece como estaba: retirarla para cerrar S7 necesita otro alcance, no confundir restitución con cierre de esa deuda.

### Evaluación específica del modo monousuario

Comando estructural oficial, **solo informativo y nunca ejecutado aquí**:

```powershell
& 'C:\Program Files\PostgreSQL\18\bin\postgres.exe' --single `
  -D 'C:\pgdata\horus_l2_test' postgres
```

`--single` debe ser el primer argumento y la base el último. No usa una conexión al puerto 55432; su aislamiento se demuestra por directorio/control físico y servicio parado, no por un supuesto listener monousuario. Deben cumplirse los pasos de apagado completo y protección anteriores; no ejecutarlo junto al servicio. Confiere superusuario implícito, por lo que una equivocación de directorio es crítica. [Referencia oficial](https://www.postgresql.org/docs/18/app-postgres.html).

La cuenta virtual del servicio no tiene una contraseña ordinaria para `runas`. El propietario local tiene permisos NTFS visibles, pero no se probó que ese binario acepte su token y acceso completo a todos los archivos. No cambiar cuenta del servicio, propietario/ACL ni usar SYSTEM para sortearlo. La ejecución elevada de PostgreSQL puede tener restricciones de token; no asumir que elevar es la solución. Si la cuenta apropiada no puede acreditarse, no ejecutar.

No existe `\password` en el intérprete monousuario. Para respetar la prohibición de secretos visibles sería necesario **preparar y revisar antes** un auxiliar que: recoja/compare dos entradas ocultas, no use historial/argumentos/archivos, lance el binario con stdin privado, envíe únicamente la sentencia nominal para el aprovisionador, no haga eco, no persista ni devuelva SQL/verificadores/errores que los contengan, limite registros sensibles, cierre stdin con EOF real y compruebe salida limpia. No inventar un generador SCRAM casero ni pegar un secreto literal en la consola. El manejo en memoria no permite prometer borrado perfecto de todas las copias. Este auxiliar **no existe como herramienta verificada en esta inspección**; por tanto no se entrega esa ruta como lista para ejecutar ni se propone un experimento sobre el clúster. Tras recuperar solo el aprovisionador, arrancar el servicio original y usar psql para runners según R2-B. HBA quedaría idéntico durante toda esa alternativa.

### Restauración, fallos y autorizaciones necesarias

- HBA temporal solo se admite con una restitución exacta preparada de antemano. Ante fallo, restaurar HBA/ACL y verificar hash; si no puede comprobarse seguridad, dejar **solo Testing detenido**, informando el estado. No reiniciar a ciegas ni cambiar a trust más amplio. El apagado de contingencia debe incluirse expresamente en la autorización.
- No deshacer credenciales parcialmente renovadas con valores desconocidos ni restaurar el clúster completo automáticamente: una restauración física es otra operación destructiva, con autorización aparte. El backup protege recuperabilidad; no habilita rollback automático.
- Ningún cambio permanente de autenticación se propone. HBA/configuración y cuenta SCM finales deben coincidir exactamente con los originales. No habilitar servicios de aplicación/Advisor/catálogos/correo ni crear perfiles Laravel.
- Solicitar aprobación **de una sola ruta**: (A) ventana HBA temporal acotada con exclusividad local, backup protegido, stop/start y contingencia de Testing, renovación nominal de tres roles y validaciones de solo lectura; o (B) preparación/revisión del auxiliar monousuario antes de autorizar su ejecución. La ruta A requiere aceptación explícita del riesgo de suplantación por procesos locales durante trust. Si ese riesgo no es aceptable, elegir B/SSPI y no improvisar.

**Resultado actual:** únicamente investigación oficial, inspección local de lectura y documentación. Cero sesiones SQL, cero cambios de estado de servicios, contraseñas, HBA, ACL o datos en R2-C. No backup creado, no monousuario, migraciones, `000022`, suites, commits ni push. Procedimiento propuesto, no validación de su ejecución. Los resultados 96/366 y 951/6030 siguen siendo históricos de sus fases anteriores.

## R2-C.1 — monousuario seleccionado; preparación sin ejecución

29/10/2026. El propietario selecciona monousuario y mantiene la autorización limitada a inspección y planificación. Esta elección reemplaza la recomendación condicionada de HBA temporal de R2-C: **no se propone ejecutar trust ni modificar HBA**. La pérdida de las tres contraseñas justifica preparar su renovación por disponibilidad; no demuestra daño de roles ni un nuevo rechazo de autenticación.

### Viabilidad Windows comprobada y límites

| Comprobación nueva | Resultado |
| --- | --- |
| Identidad SCM | Testing Running/Manual, cuenta `NT SERVICE\postgresql-horus-l2-test`, PID 2548; comando y directorio originales |
| Listener y procesos | Único listener 55432 en `127.0.0.1`, `postgres.exe` 19800, hijo de `pg_ctl.exe` 2548 |
| Control físico | `7701505916098505688`, `in production`; PID/directorio/puerto/ready de `postmaster.pid` coinciden |
| Binarios | Versiones de archivo de `postgres.exe` y `libpq.dll`: 18.6; no equivalen a una consulta SQL de versión |
| Token del operador | Integridad media; Administradores solo para denegar. `WindowsPrincipal.IsInRole` devuelve false para Administradores y Usuarios avanzados |
| ACL del árbol | 1.615 elementos enumerados, cero errores de enumeración/lectura de ACL; todos tienen entrada Allow FullControl para el SID del propietario; cero entradas Deny observadas |
| Destinos físicos | Cero puntos de reanálisis en el árbol enumerado; `pg_tblspc` vacío |
| Control de servicios | DACL SCM consultada: usuarios interactivos sin derechos de inicio/parada; administración del servicio debe hacerse en consola elevada separada |
| Configuración | Los tres SHA-256 de R2-C siguen idénticos; no se editaron HBA, configuración ni ACL |
| Servicio cotidiano | Running/Auto, PID 4972, cuenta original; solo metadata SCM, sin conexiones ni acciones en 5432 |

**Dictamen:** técnicamente viable según identidad, token y ACL inspeccionados; **ejecución monousuario y protección del secreto aún no acreditadas**. Las ACL son una inspección estática, no una apertura con escritura ni una prueba de acceso efectivo a cada archivo bajo carga. No se inició un proceso de recuperación para completar esa evidencia.

La vía legítima propuesta es la cuenta Windows del propietario, **sin elevar**, que ya dispone de permisos explícitos en los archivos. No requiere conocer una contraseña de PostgreSQL ni ejecutar como la cuenta virtual del servicio. PostgreSQL 18 comprueba pertenencia efectiva a Administradores/Usuarios avanzados y rechaza tokens administrativos; por ello no lanzar `postgres --single` desde la consola elevada usada para SCM. No cambiar cuenta del servicio, ACL o propietario ni recurrir a SYSTEM, suplantación o nuevas cuentas como alternativa automática. Fuentes: [comprobación de inicio](https://github.com/postgres/postgres/blob/REL_18_STABLE/src/backend/main/main.c), [comprobación del token Windows](https://github.com/postgres/postgres/blob/REL_18_STABLE/src/port/win32security.c).

### Entrada de secretos: mecanismo propuesto y bloqueo pendiente

El intérprete monousuario acepta SQL por stdin y termina al recibir EOF; no proporciona `\password`. Su opción `-E` activa eco de consultas. Un prompt oculto por sí solo no protege una sentencia que posteriormente aparezca en mensajes de error, logging, una transcripción o un volcado. Fuentes: [postgres 18](https://www.postgresql.org/docs/18/app-postgres.html), [entrada interactiva en el código oficial](https://github.com/postgres/postgres/blob/REL_18_STABLE/src/backend/tcop/postgres.c).

No se encontró un auxiliar monousuario revisado en `scripts/` ni en el soporte de pruebas inspeccionado. Los scripts históricos S6/S7 usan prompts de psql y no sustituyen esa herramienta. **No se implementa ni ejecuta un auxiliar improvisado en esta fase.** Para considerarlo preparado debe cumplir y demostrar este contrato:

1. Capturar y confirmar localmente las tres entradas con eco deshabilitado; nunca recibir secretos por chat, argumentos, variables de entorno, portapapeles automatizado o archivos. Requerir consola privada sin transcripción/grabación. Custodia por el propietario fuera del repositorio.
2. Arrancar únicamente el binario absoluto validado, con lista fija de argumentos no secretos, sin shell intermedio, sin elevación y con stdin/stdout/stderr privados. Heredar solo los handles necesarios; vaciar fuentes alternativas de conexión en el entorno del hijo sin alterar el entorno persistente. Ninguna salida cruda puede llegar a terminal, archivos o herramientas del agente.
3. Antes de enviar secretos, verificar en esa sesión identidad física/directorio, versión 18, base de mantenimiento `postgres`, ausencia de red y existencia de los tres roles. Leer atributos, membresías y ACL relevantes **sin leer contraseñas/verificadores**. No inferir el usuario SQL a partir del nombre Windows: monousuario tiene identidad administrativa implícita.
4. Resolver el logging del proceso antes de cualquier sentencia sensible. No basta `log_statement=none`: errores, planes de depuración, muestreo/duración y extensiones también pueden revelar SQL. Revisar destinos, preloads/hooks y capturar salidas solo en memoria. Cualquier opción `-c` temporal de privacidad necesitaría autorización expresa y revisión; no se modifica ahora ningún parámetro ni se oculta un conflicto desactivando auditoría. Si no puede demostrarse una ruta sin persistencia de SQL sensible, detenerse antes de solicitar el secreto.
5. Construir únicamente tres `ALTER ROLE <nombre-fijo> PASSWORD <literal>` dentro de **una transacción explícita**, sin alterar atributos, membresías, propietarios o grants. La herramienta debe escapar correctamente literales con una política de codificación verificada, impedir NUL y delimitadores de entrada CR/LF, y probar comillas, barras y Unicode. No basta interpolar `SecureString`, ni pasar un secreto a una línea PowerShell. No generar SCRAM con criptografía casera.
6. Confirmar primero las tres contraseñas; enviar COMMIT solo tras comprobar éxito de las tres operaciones y conservación de atributos/permisos. Un error SQL puede dejar el proceso vivo y la transacción abortada: código de salida cero **no prueba commit**. Se necesita confirmación no secreta del estado transaccional y del resultado; si falla antes del commit, rollback y EOF limpio. No afirmar atomicidad del auxiliar hasta probar su control de errores.
7. El parser de salida debe usar una lista cerrada de resultados técnicos y descartar el resto, sin intentar sanear SQL secreto con expresiones regulares. Probar rechazo, error sintáctico, fallo en la segunda operación, EOF, cancelación y pérdida de confirmación con un proceso simulado y secretos ficticios; sin sockets ni acceso al clúster. Evitar bloqueos al drenar stdout/stderr simultáneamente y no matar automáticamente el backend ante un timeout.

La documentación oficial aconseja cifrar la contraseña antes de enviarla en SQL; `PQencryptPasswordConn` necesita un objeto de conexión y no se presenta aquí como generador SCRAM offline listo para usar. No pasar NULL ni fabricar una conexión fallida para eludir su contrato. El diseño de stdin privado con SQL sensible necesita revisión específica de fugas y logging; aún no ofrece la garantía solicitada. Véanse [libpq](https://www.postgresql.org/docs/18/libpq-misc.html) y [logging](https://www.postgresql.org/docs/18/runtime-config-logging.html).

**No puede garantizarse hoy la ausencia de exposición con una herramienta que no está implementada/probada.** Tampoco se puede prometer ausencia absoluta de copias en memoria, paginación, volcados o captura por un administrador del equipo. La contraseña de PostgreSQL se almacena necesariamente como verificador persistente en el clúster y WAL; la prohibición se refiere a no generar archivos adicionales con secretos ni registrar SQL sensible. Si tampoco se admite ese almacenamiento interno, no es posible renovar una contraseña PostgreSQL. Un backup físico contendría verificadores existentes y necesita autorización/protección separadas.

### Secuencia corta para una autorización posterior, con paradas obligatorias

| Paso | Acción futura, NO ejecutada | Verificación / punto de parada |
| --- | --- | --- |
| 0 | Preparar/revisar el auxiliar y sus pruebas sintéticas sin PostgreSQL | No parar Testing hasta aprobar aislamiento, token, manejo de errores y ausencia de fugas; esta dependencia sigue pendiente |
| 1 | Revalidar SCM, árbol de procesos, configuración/ACL e identificador exacto; preparar backup frío protegido autorizado | Cualquier diferencia, archivo fuera del destino o falta de autorización del backup detiene la fase |
| 2 | Parada limpia solo Testing mediante `pg_ctl stop -D C:\pgdata\horus_l2_test -m fast -w -t 60`, con derechos administrativos acreditados | SCM Stopped, sin procesos del clúster/listener 55432, PID retirado por PostgreSQL, control `shut down` y cierre limpio. Timeout no autoriza repetir ni forzar |
| 3 | Completar copia fría consistente y comprobada, fuera de Git/sincronización; revalidar token no elevado del auxiliar | No seguir con backup incompleto, servicio en ejecución, PID residual o token administrativo; no borrar PID ni cambiar ACL |
| 4 | Ejecutar monousuario con el auxiliar aprobado; identidad primero, luego transacción nominal de las tres contraseñas | Sin listener, HBA intacto, sin SQL libre del operador. Error previo a commit: rollback/EOF. Resultado ambiguo: detener la fase sin repetir cambios |
| 5 | Cerrar stdin con EOF, esperar salida limpia y comprobar nuevamente estado físico `shut down` | No arrancar el servicio mientras exista el backend. No usar taskkill, immediate ni restauración automática |
| 6 | Arrancar únicamente `postgresql-horus-l2-test` con SCM y su cuenta/configuración originales | Nuevo árbol PID válido, listener único loopback 55432, control físico idéntico, hashes/ACL originales. Fallo de arranque: detener fase, no reintentar |
| 7 | Una autenticación nativa por cada credencial nueva, solo lectura inicial, con prompts ocultos | Aprovisionador→postgres; principal→horus_l2_test; restauración→horus_l2_restore_test. Sin Laravel/check/migraciones. Registrar cada resultado y cerrar sesiones |

La estructura del comando del paso 4 es `postgres.exe --single -D C:\pgdata\horus_l2_test postgres`, con ruta absoluta del binario, `--single` primero y base al final. **No se entrega como instrucción para ejecutarlo manualmente**, porque faltan el auxiliar revisado y su política de privacidad. Las opciones de proceso pendientes no deben improvisarse durante la intervención. La parada fast revierte operaciones en curso y termina limpiamente; un timeout no demuestra que haya terminado. [pg_ctl 18](https://www.postgresql.org/docs/18/app-pg-ctl.html), [backup físico consistente](https://www.postgresql.org/docs/18/backup-file.html).

### Validación y contingencias

Después del arranque usar `psql -X -W`, conexión fija con `options='-c default_transaction_read_only=on'`, `ON_ERROR_STOP=on` e `HISTFILE=NUL`. No admitir fuentes de contraseña alternativas. Comprobar identidad SQL/versión, usuario/base y HBA original SCRAM; una conexión exitosa debe correlacionarse con su regla/método de autenticación y evidencia técnica del servidor, no con la mera aceptación del prompt. No consultar ni exportar verificadores.

Para runners, reutilizar las sondas de lectura R2-A: flags de rol, ausencia de membresías elevadoras, acceso limitado a su base, permisos de `public`/`horus_test_control`, marcador único, propósito y OID/base/runner correctos; comparar con la foto no sensible previa y expectativas existentes. Si falta marcador, cambian permisos o falla autenticación, no reparar automáticamente ni completar S7. La sesión del aprovisionador comprueba identidad, no sustituye la del runner. La renovación no autoriza cierre HBA del aprovisionador, perfil Laravel ni pruebas de integración.

Si se cancela antes de commit y el backend confirma rollback/salida limpia, las contraseñas anteriores continúan, aunque no se conozcan. Si se pierde la confirmación después de COMMIT, el resultado es **desconocido**: no volver a ejecutar cambios ni asumir rollback; la validación de una sola autenticación posterior por rol, si está autorizada y el arranque es seguro, resuelve lo comprobable. Si no hay salida limpia, conservar Testing detenido y solicitar intervención; no usar el backup como rollback automático.

Una única transacción reduce renovaciones parciales en catálogos, pero no hace atómicas la custodia de secretos, la confirmación al operador y las comprobaciones posteriores. Recuperar una contraseña no debe ampliar ningún privilegio. HBA nunca cambia: no hay restitución de trust que ejecutar ni una vía de red nueva que pueda quedar abierta ante fallo. Si la validación posterior es incompleta, mantener configuración original, informar exactamente qué rol fue comprobado y no continuar con suites. Si no puede acreditarse seguridad de arranque, dejar Testing detenido con autorización de contingencia expresa. No intervenir sobre la instancia cotidiana.

**Siguiente autorización mínima propuesta:** preparar e inspeccionar el auxiliar de entrada privada y probarlo exclusivamente contra un proceso simulado con datos ficticios, sin iniciar PostgreSQL ni modificar servicios/configuración. Después de revisar esa evidencia, solicitar por separado stop/start y contingencia de Testing, backup protegido, ejecución monousuario con opciones de privacidad concretas, renovación nominal de los tres roles en una transacción y validación nativa de lectura. Si el auxiliar no supera la revisión, la alternativa es asistencia administrativa local especializada; no volver automáticamente a trust ni cambiar cuentas/ACL.

**Cierre R2-C.1:** solo lectura de metadata, documentación oficial y ampliación de este informe/contexto. Cero sesiones SQL, intentos de autenticación, modos monousuario, cambios de servicio/configuración/ACL/contraseñas, backups, migraciones, `000022` o suites. Sin cambios funcionales, commits ni push; índice y dos stashes preservados. Servicio Testing permanece Running/Manual. Validación documental y `git diff --check`; las cifras de pruebas anteriores siguen siendo históricas.
