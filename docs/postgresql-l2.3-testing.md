# L2.3: PostgreSQL Testing antes de migrar `horus_group`

28 de octubre de 2026. Evaluación no destructiva y preparación. **No se arrancó ni se modificó ningún clúster**, no se ejecutaron pruebas contra PostgreSQL, ni se tocaron `horus_group`, `horus_group_ensayo`, el backup, Resend, la verificación de correo, el frontend o credenciales. Sin commit.

## 1. Estado del PostgreSQL Testing existente (S7)

Inspección de servicio, puertos, archivos y evidencias, sin SQL ni arranque.

| Aspecto | Estado observado |
| --- | --- |
| Servicio | `postgresql-horus-l2-test`, cuenta `NT SERVICE\postgresql-horus-l2-test`, **Manual / Stopped**. `pg_isready 127.0.0.1:55432`: sin respuesta |
| Datos | `C:\pgdata\horus_l2_test`, clúster `7701505916098505688`, checksums activos, ACL solo para la cuenta del servicio (el operador no puede leer `pg_control`, como estaba diseñado) |
| Configuración (S4) | `port = 55432`, `listen_addresses = '127.0.0.1'`, `password_encryption = scram-sha-256`, `cluster_name = 'horus_l2_test'` |
| `pg_hba.conf` | Aprovisionador (`# L2-PROVISIONER`) **aún habilitado**; un runner por base, `scram-sha-256`, solo `127.0.0.1/32`; resto `reject` |
| Bases (S6) | Además de las plantillas y `postgres`: OID 16390 `horus_l2_test` y 16391 `horus_l2_restore_test` |
| Validación administrativa (S7) | Correcta en ambas bases: roles restringidos, 0 membresías, verificadores SCRAM, bases de un propietario sin login, marcador protegido con `purpose = horus-l2-destructive-tests`, acceso restringido, UTF8 / `Spanish_Spain.1252` (libc), igual que `horus_group` |
| Punto de parada | `failure.json`: fase P, **autenticación del runner** `horus_l2_test_runner` (`password authentication failed`, 27/10 23:02). Los runners se habían autenticado bien a las 20:12. `S7Complete = false`, sin escritura en HBA |
| Cierre | El log termina sin apagado limpio; queda un `postmaster.pid` residual (PostgreSQL lo descarta al arrancar si el proceso no existe) |
| Evidencias | `%LOCALAPPDATA%\HorusL2Provisioning\` (identidad DPAPI, SQL de S6/S7, informes de reintentos). No se reejecutó nada |

**Conclusión:** el clúster está aprovisionado y validado salvo **una credencial del runner que no coincide** y la desactivación final del aprovisionador. No hace falta repetir S1–S7.

Hallazgo adicional (instancia cotidiana): `postgresql-x64-18` escucha en `0.0.0.0:5432` y `[::]:5432`, no solo en local. No se cambia; ver §6.

## 2. Alternativa recomendada

**Opción A (recomendada): completar S7 en tres acciones, sin rehacer el procedimiento.**

1. Arrancar el servicio de pruebas (consola elevada).
2. Con el aprovisionador, restablecer la contraseña del runner de forma interactiva (`\password`), sin que aparezca en argumentos ni historial.
3. Comprobar con `php scripts/pgsql-test.php check` y, después, desactivar la línea `# L2-PROVISIONER` y recargar.

Requisito: el propietario conoce la contraseña del aprovisionador.

**Opción B (si esa contraseña se perdió): clúster desechable en modo usuario, sin servicio.** `initdb` como el operador en `%LOCALAPPDATA%\HorusPgTest\data` (UTF8, libc, `Spanish_Spain.1252`, `scram-sha-256`), mismo `port = 55432` y `listen_addresses = '127.0.0.1'`, el mismo `pg_hba.conf`, `pg_ctl start` manual y los SQL ya validados de S6 (`00-cluster.sql`, `10-main.sql`, `20-restore.sql`). La guarda no depende del directorio de datos: valida servidor, base, runner, marcador y privilegios, así que funciona sin cambios. El servicio S7 debe seguir detenido (mismo puerto). Se descarta borrando su carpeta. No requiere elevación.

Se descartan Docker (con mapeo de puertos `inet_server_addr()` no es `127.0.0.1` y obligaría a relajar la guarda) y cualquier base dentro de la instancia cotidiana (allí `PUBLIC` tiene `CONNECT` sobre `horus_group` y la sonda `other_database_access = 0` fallaría sin alterar privilegios de la base real).

## 3. Configuración exacta propuesta

Archivo `.env.pgsql-testing.local` (ignorado por Git, creado a partir de `.env.pgsql-testing.local.example`, ACL solo del operador). El `.env` habitual no se toca: `bootstrap/app.php` carga este archivo únicamente con `HORUS_PG_TEST_PROFILE=1`.

```dotenv
APP_ENV=testing
APP_KEY=base64:<32 bytes aleatorios exclusivos de pruebas>
HORUS_PG_TEST_PROFILE=1
HORUS_PG_TEST_CONFIRM=            # AUTHORIZE_HORUS_L2_DESTRUCTIVE_TESTS solo tras autorización
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=55432
DB_DATABASE=horus_l2_test
DB_USERNAME=horus_l2_test_runner
DB_PASSWORD=<contraseña nueva del runner>
DB_URL=
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
BROADCAST_CONNECTION=null
OPENAI_API_KEY=
RESEND_API_KEY=
POSTMARK_API_KEY=
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
SLACK_BOT_USER_OAUTH_TOKEN=
```

`APP_KEY` de pruebas: `php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"` (nunca `key:generate`). Correo `array`, `Http::preventStrayRequests()` en las pruebas que tocan proveedores, y claves externas vacías y prohibidas por la guarda: sin correo real ni OpenAI. Datos exclusivamente ficticios (factories y helpers de las pruebas).

## 4. Guardarraíles

Ya existentes (`app/Support/Testing/`, `config/pgsql-testing-database.php`), sin cambios:

- Configuración sin caché, `APP_ENV=testing`, perfil `1` y consentimiento literal `AUTHORIZE_HORUS_L2_DESTRUCTIVE_TESTS`.
- Una sola conexión `pgsql`; lista fija `horus_l2_test` → `horus_l2_test_runner` y `horus_l2_restore_test` → `horus_l2_restore_test_runner`; `127.0.0.1:55432` literal; contraseña obligatoria; `DB_URL` y opciones alternativas prohibidas; entorno y configuración deben coincidir.
- Caché, sesión, cola, correo y broadcast en memoria; prohibidas variables `PG*`, conexiones secundarias y credenciales externas.
- Sonda de solo lectura en el servidor antes de habilitar escrituras: base, usuario, dirección, puerto, `search_path`, zona UTC, aislamiento, marcador `horus-l2-destructive-tests` en `horus_test_control.authorization`, rol sin privilegios especiales, sin membresías, sin acceso a otras bases y marcador no escribible.
- Pruebas ordinarias: solo SQLite `:memory:`; cualquier otra conexión se rechaza.

Comprobado en esta fase:

| Comprobación | Resultado |
| --- | --- |
| Pruebas unitarias de la barrera (`TestDatabaseSafetyTest`, `ConcurrentTestSessionTest`, filtro `PostgresTestGuard`) | 96 pruebas, 366 aserciones, aprobadas (dobles, sin sockets) |
| `scripts/pgsql-test.php check` sin perfil | Rechazo: `testing environment required` |
| Destino `horus_group:5432` | Rechazo: `database is not on the fixed test allowlist` |
| Destino `horus_group_ensayo:5432` | Rechazo: `database is not on the fixed test allowlist` |
| `horus_l2_test` en el puerto 5432 | Rechazo: `unexpected connection setting: port` |
| Sin consentimiento destructivo | Rechazo: `explicit isolated profile and destructive-test consent required` |

Todos los rechazos ocurren al validar la configuración, antes de abrir conexión. Contraseñas usadas en los rechazos: valores ficticios.

## 5. Plan de pruebas PostgreSQL

### 5.1 Cambios de infraestructura de pruebas (hechos, sin ejecutar contra PostgreSQL)

- `phpunit.pgsql.xml`: nueva suite `PostgreSQL integrity` (`tests/PostgresIntegrity`). Se excluyen del perfil PostgreSQL las pruebas SQLite por diseño: `tests/Unit/TestDatabaseSafetyTest.php`, `tests/Unit/ConcurrentTestSessionTest.php`, `tests/Feature/PromoverAdminLocalTest.php`. Validado contra el XSD de PHPUnit.
- `tests/TestCase.php`: `assertIsolatedTestDatabase()`. Fuera del perfil exige SQLite `:memory:` (igual que antes); dentro, `GuardedPostgresConnection` sobre una base de `PostgresTestGuard::TARGETS`. Sustituye la aserción fija de SQLite en 10 clases de Feature (`BackendPerformance`, `BackendRobustness`, `CursoPublicacion`, `MatriculaNotification`, `MatriculaNotificationBacklog`, `MatriculaOperations`, `Sprint12Atomicity`, `Sprint12AuthSecurity`, `Sprint13RegistrationSecurity`, `Sprint14EmailVerification`), que solo la usaban como defensa y fallarían en `setUp()` bajo PostgreSQL.
- Suite SQLite tras el cambio: **809 pruebas, 5166 aserciones**, aprobadas. Larastan nivel 6 sin errores, Pint aprobado en los 12 archivos PHP tocados, `git diff --check` limpio.

SQLite sigue siendo la suite rápida y por defecto (`php artisan test`); nada de lo anterior la redirige a PostgreSQL.

### 5.2 Qué es seguro y qué lo protege

Todas las pruebas del perfil escriben (`RefreshDatabase` → `migrate:fresh`, `DatabaseTruncation`) y por eso **solo** pueden alcanzar `horus_l2_test`: la guarda rechaza cualquier otro destino antes de conectar, y el runner carece de `CONNECT` a otras bases y de privilegios sobre el marcador. `migrate:fresh` elimina solo objetos del esquema `public` propiedad del runner; el marcador vive en `horus_test_control`, propiedad de `horus_l2_owner`. `horus_l2_restore_test` no se usa por ahora.

| Área | Pruebas | Filtro (`pgsql-test.php test --filter=...`) |
| --- | --- | --- |
| Concurrencia (conexiones independientes) | `ConcurrencyPostgresTest` (3): último cupo, mismo estudiante, degradación simultánea de administradores; segunda sesión por `ConcurrentTestSession` | `PostgresTest` (14 en total) |
| Índices y `CHECK` | `MatriculaConstraintsPostgresTest` (5): SQLSTATE 23505/23514 | (incluido) |
| Duplicados, restauración, anulación, cupos, `ILIKE` | `MatriculaRulesPostgresTest` (6) | (incluido) |
| Migraciones (incluidas `000023`/`000024`) y permisos | `Migration`, `Permission`, `AprobacionesRespaldo`, `MatriculaSensitivePermissions` | `Migration\|Permission` |
| Estudiantes y cursos | `AdminEstudianteTest`, `AdminCursoTest`, `CursoPublicacionTest` | `Estudiante\|Curso` |
| Matrículas | `AdminMatriculaTest`, `MatriculaOperationsTest`, `MatriculaIntegrityConstraintsTest` | `Matricula` |
| Transacciones y rollback | `Sprint12AtomicityTest`, `BackendRobustnessTest` | `Atomicity\|Robustness` |
| Resto | Suite `PostgreSQL isolated` completa (41 archivos) | sin filtro |

Nota: `RefreshDatabase` aplica **todas** las migraciones, incluida `000022`, **solo en `horus_l2_test`**. Es necesario para las pruebas del Sprint 14 y no afecta a la suspensión de `000022` en `horus_group`; requiere confirmación explícita.

Fallos esperables que serán hallazgos, no riesgos para datos: conteos de consultas de `BackendPerformanceTest`, diferencias de ordenación por colación `Spanish_Spain.1252` y comparaciones sensibles a mayúsculas (`LIKE` distingue mayúsculas en PostgreSQL).

### 5.3 Ejecución (cuando se autorice)

```powershell
# E0 (consola elevada): arrancar el servicio de pruebas
Start-Service postgresql-horus-l2-test
& 'C:\Program Files\PostgreSQL\18\bin\pg_isready.exe' -h 127.0.0.1 -p 55432      # aceptando conexiones

# E1 (consola del operador, sin transcripción): nueva contraseña del runner
& 'C:\Program Files\PostgreSQL\18\bin\psql.exe' -X -W -h 127.0.0.1 -p 55432 -U horus_l2_provisioner -d postgres
#   postgres=# select current_setting('cluster_name'), inet_server_port();      -- horus_l2_test | 55432
#   postgres=# \password horus_l2_test_runner
#   postgres=# \q

# E2: crear .env.pgsql-testing.local (§3) con HORUS_PG_TEST_CONFIRM vacío; ACL solo del operador
# E3: identidad y privilegios, sin DDL/DML
php scripts/pgsql-test.php check
#   Authorized isolated PostgreSQL session: horus_l2_test on 127.0.0.1:55432; no DDL/DML executed.

# E4: cerrar S7 (elevada): comentar la línea "# L2-PROVISIONER" de pg_hba.conf y recargar
#     (pg_ctl reload -D C:\pgdata\horus_l2_test, o SELECT pg_reload_conf() antes de cerrar E1)

# E5: con autorización destructiva, fijar HORUS_PG_TEST_CONFIRM=AUTHORIZE_HORUS_L2_DESTRUCTIVE_TESTS y ejecutar por etapas
php scripts/pgsql-test.php test --filter=PostgresTest --stop-on-failure
php scripts/pgsql-test.php test --filter="Migration|Permission" --stop-on-failure
php scripts/pgsql-test.php test --filter="Estudiante|Curso|Matricula" --stop-on-failure
php scripts/pgsql-test.php test --filter="Atomicity|Robustness"
php scripts/pgsql-test.php test

# E6: vaciar HORUS_PG_TEST_CONFIRM y detener el servicio (Stop-Service postgresql-horus-l2-test)
```

Registrar por etapa: pruebas, aserciones, fallos con su clase y mensaje (sin datos), y la duración. Un fallo se analiza antes de seguir; no se relajan guardas ni tiempos de espera.

## 6. Control de acceso: menor privilegio para la aplicación (propuesta, sin aplicar)

Hoy el usuario de la aplicación es superusuario y dueño de `horus_group`. Propuesta de roles en la instancia cotidiana:

| Rol | Atributos | Uso |
| --- | --- | --- |
| `horus_owner` | `NOLOGIN`, dueño de la base, del esquema `public` y de todos los objetos | Propiedad; nadie inicia sesión con él |
| `horus_migrator` | `LOGIN`, miembro de `horus_owner`, sin `SUPERUSER`/`CREATEDB`/`CREATEROLE` | Solo en ventanas de migración (`DB_USERNAME` temporal) |
| `horus_app` | `LOGIN`, `NOINHERIT`, sin atributos especiales | Aplicación: `CONNECT`; `USAGE` en `public`; `SELECT, INSERT, UPDATE, DELETE` en tablas; `USAGE, SELECT` en secuencias; `ALTER DEFAULT PRIVILEGES FOR ROLE horus_owner` para objetos futuros |
| `horus_backup` | `LOGIN`, miembro de `pg_read_all_data` | `pg_dump` sin superusuario |

Orden seguro:

1. Ensayarlo primero en `horus_group_ensayo` (copia): roles, `ALTER ... OWNER TO horus_owner` objeto por objeto, nunca `REASSIGN OWNED` desde un superusuario, porque afecta a todas las bases.
2. Ejecutar allí la suite PostgreSQL y una sesión de la aplicación con `horus_app`.
3. Repetir en `horus_group` en una ventana con backup previo.
4. Cambiar `DB_USERNAME`/`DB_PASSWORD` en el `.env` real.
5. Mantener el superusuario actual sin uso hasta confirmar.

Además, valorar `listen_addresses = 'localhost'` y revisar `pg_hba.conf` de la instancia cotidiana si no se necesita acceso remoto. Ninguno de estos pasos se ha ejecutado.

## 7. Integridad de los datos reales en esta fase

Comprobación de solo lectura posterior a todos los cambios de esta fase:

- `horus_group`: instantánea idéntica a la de la Fase A, incluidas las huellas de contenido; contador de escrituras de `pg_stat_user_tables` 152 → 152.
- `horus_group_ensayo`: conteos idénticos al cierre de la Fase A; permisos admin 46, coordinador 21, secretaria 9; datos de dominio idénticos al origen; 3 `CHECK` validados; 0 avisos y 0 fichas.
- Backup: SHA-256 sin cambios (`72D0CBD9…3B571276`).

## 8. Acciones que requieren autorización

1. Retomar S7 en su forma reducida: arrancar `postgresql-horus-l2-test` (consola elevada).
2. Restablecer la contraseña de `horus_l2_test_runner` con el aprovisionador (Opción A), o, si esa contraseña no está disponible, crear el clúster desechable de la Opción B.
3. Crear `.env.pgsql-testing.local` y, por separado, activar `HORUS_PG_TEST_CONFIRM`.
4. Ejecutar las pruebas destructivas en `horus_l2_test`, incluida la aplicación de todas las migraciones (también `000022`) en esa base de pruebas.
5. Desactivar la línea del aprovisionador en `pg_hba.conf` (cierre de S7).
6. Ensayar los roles de menor privilegio en `horus_group_ensayo` y, más adelante, en `horus_group`.
7. Commit de los cambios de infraestructura de pruebas de esta fase.

Siguen sin autorizar: Fase B, Resend, verificación de correo, `000022` en `horus_group`, frontend y módulos excluidos.
