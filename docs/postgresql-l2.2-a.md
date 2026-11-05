# L2.2-A: infraestructura de pruebas PostgreSQL aisladas

Esta fase prepara código y pruebas simuladas. No crea bases, no se conecta a PostgreSQL y no autoriza L2.2-B. Las pruebas anteriores usan SQLite; ni ellas ni los dobles nuevos demuestran aislamiento real de PostgreSQL.

## Política fija

| Propiedad | Valor admitido |
| --- | --- |
| Entorno | `testing`, sin configuración cacheada |
| Conexión | Solo `pgsql`, sin URL, réplicas ni conexiones secundarias |
| Host/puerto | `127.0.0.1:55432` |
| Base principal / usuario | `horus_l2_test` / `horus_l2_test_runner` |
| Base de restauración / usuario | `horus_l2_restore_test` / `horus_l2_restore_test_runner` |
| Esquema de trabajo | `public` |
| Zona / aislamiento | UTC / READ COMMITTED |
| Caché / sesiones / cola / correo | array / array / sync / array |
| HTTP Laravel | Solicitudes externas sin fake rechazadas |

El puerto 55432 es una elección nueva: se propone una **instancia PostgreSQL nativa exclusiva**, sin Docker. Laragon no incluye PostgreSQL: los binarios (`initdb`, `pg_ctl`, `psql`, `pg_dump`, `pg_restore`) proceden de la instalación EDB 18.x del sistema, con directorio de datos, servicio y cuenta de Windows propios. No reutilizar el servicio cotidiano de 5432 ni modificarlo. Ampliar esta política requiere revisar código y pruebas; una variable no puede ampliar la lista de destinos.

## Rutas protegidas

`bootstrap/app.php` instala la barrera después de cargar configuración y antes de registrar proveedores. No depende de `Tests\TestCase`: aplica también a Artisan y otras clases PHPUnit que arranquen Laravel.

En el perfil PostgreSQL se reemplaza todo el namespace `database` por `config/pgsql-testing-database.php`, antes de resolver conexiones. Laravel fusiona drivers predeterminados del framework; esta sustitución evita conservarlos. Se valida el resultado y las fuentes de entorno. No se usa `.env` de desarrollo como respaldo: se selecciona `.env.pgsql-testing.local`, o variables seguras del proceso.

El conector aplica `default_transaction_read_only=on` mediante opciones de inicio libpq. Antes de devolver el PDO consulta identidad, privilegios y marcador. Solo después habilita escrituras **en ese mismo PDO**. Cada reconexión vuelve a comprobarse. Los errores de conexión/probe no incluyen contraseña, DSN ni excepción original encadenada.

Los conectores bloquean drivers no autorizados incluso cuando `DatabaseManager` resuelve el PDO antes del evento (`::read` / `::write`). `ConnectionEstablished` añade el rechazo de aliases, `DB::build()` y configuraciones modificadas. Capturar un rechazo y volver a pedir la conexión almacenada tampoco permite abrir un driver prohibido. Fuera del perfil PostgreSQL, las pruebas Laravel solo pueden abrir SQLite `:memory:`. El `phpunit.xml` habitual se conserva.

El bootstrap de `phpunit.pgsql.xml` comprueba la conexión antes de cualquier test, incluso si no extiende el TestCase Laravel. En Laravel 12.70.1, `RefreshDatabase` llama internamente a `migrate:fresh`: **ejecutar ese perfil es potencialmente destructivo**.

El perfil instala una `GuardedConnectionFactory` en el contenedor de esa aplicación antes de registrar proveedores. Un extender conserva esa selección cuando `DatabaseServiceProvider` registra `db.factory`; no se modifica el resolver estático de conexiones. Cada conexión PostgreSQL creada por esa fábrica devuelve `GuardedPostgresSchemaState`, cuyo `load()` rechaza la carga antes de construir o ejecutar procesos `psql`/`pg_restore`. La aplicación habitual y la suite SQLite conservan su fábrica normal.

El rechazo cubre el runner oficial, el bootstrap directo de `phpunit.pgsql.xml` y la cadena `RefreshDatabase → migrate:fresh → migrate`. Se aplica a cualquier ruta, extensión y nombre de archivo, incluidos `pgsql-schema.sql`, `pgsql-schema.dump`, nombres alternativos y `--schema-path`; no depende de buscar archivos ni los modifica. Los nombres de conexión alternativos siguen sujetos a la validación existente. No es una transacción que revierta el comando entero: Laravel puede haber realizado operaciones previas, incluida la eliminación de su repositorio de migraciones, antes de llamar a `load()`.

## Marcador independiente

Se exigen consentimiento local `HORUS_PG_TEST_CONFIRM=AUTHORIZE_HORUS_L2_DESTRUCTIVE_TESTS` y una fila provisionada por el operador en `horus_test_control.authorization`.

La fila contiene nombre y OID real de la base, usuario dedicado y propósito fijo. El usuario de pruebas solo tiene SELECT; no puede crear objetos en ese esquema, modificar el marcador ni asumir su propietario. El esquema queda fuera de `public`, único esquema de trabajo/limpieza. El OID impide aceptar automáticamente un marcador copiado de otra base.

La consulta complementa los permisos de tabla con `has_any_column_privilege(current_user, ..., 'INSERT')`, `'UPDATE'` y `'REFERENCES'`. Estas comprobaciones consideran los privilegios efectivos, incluidos permisos por columna o heredados; abarcan todas las columnas y no requieren enumerarlas mediante `has_column_privilege`. Se conservan las comprobaciones de escritura de tabla, CREATE en el esquema, propiedad/pertenencia al propietario y membresías de roles. Un marcador con permisos excesivos se rechaza antes de habilitar escritura.

Cambiar una variable no crea el marcador ni concede privilegios. Una base inexistente, marcador ausente/modificable, usuario privilegiado, membresías de roles o CONNECT a otra base producen rechazo. El marcador no es una firma criptográfica: aprovisionador e instancia local pertenecen a la frontera de confianza.

## Aprovisionamiento futuro: NO ejecutado

El procedimiento operativo definitivo (cuenta de servicio, ACL, `initdb`, HBA con entrada del aprovisionador, locale, clasificación de verificaciones y checklist) está en [postgresql-l2.2-b-provisioning.md](postgresql-l2.2-b-provisioning.md) y prevalece sobre los pasos siguientes. Estos cuatro pasos quedan como referencia del diseño y **no deben ejecutarse literalmente**: el orden (servicio antes de `initdb`), el SQL con locale, el HBA con aprovisionador, la desactivación posterior del aprovisionador y el rollback están en los §6 y §7 de ese documento.

Revisión independiente y autorización necesarias antes de realizar estos pasos. Usar una instancia nueva/exclusiva en 127.0.0.1:55432. **No aplicar estas revocaciones PUBLIC en un clúster compartido**: afectarían a sus usuarios.

1. Preparar directorio de datos y servicio nativo separado: `listen_addresses='127.0.0.1'`, puerto 55432 y autenticación SCRAM. Confirmar versión, directorio y proceso; no reutilizar datos cotidianos ni iniciar servicios automáticamente desde las pruebas.
2. Conectar como aprovisionador (superusuario de la instancia **nueva**) con host/puerto explícitos, base `postgres` de esa instancia y contraseña interactiva. Las revocaciones de CONNECT a PUBLIC afectan a los runners, no al aprovisionador. El SQL de `CREATE DATABASE` debe fijar además el locale medido en `horus_group` (ver el documento B1). CREATE DATABASE debe ejecutarse fuera de una transacción envolvente:

```sql
CREATE ROLE horus_l2_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE horus_l2_test_runner LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE horus_l2_restore_test_runner LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE DATABASE horus_l2_test OWNER horus_l2_owner TEMPLATE template0 ENCODING 'UTF8';
CREATE DATABASE horus_l2_restore_test OWNER horus_l2_owner TEMPLATE template0 ENCODING 'UTF8';
REVOKE CONNECT, TEMPORARY ON DATABASE postgres, template0, template1,
    horus_l2_test, horus_l2_restore_test FROM PUBLIC;
GRANT CONNECT ON DATABASE horus_l2_test TO horus_l2_test_runner;
GRANT CONNECT ON DATABASE horus_l2_restore_test TO horus_l2_restore_test_runner;
ALTER ROLE horus_l2_test_runner SET default_transaction_isolation = 'read committed';
ALTER ROLE horus_l2_restore_test_runner SET default_transaction_isolation = 'read committed';
```

Asignar contraseñas distintas mediante `\password horus_l2_test_runner` y `\password horus_l2_restore_test_runner` en psql; no incluirlas en SQL, argumentos ni historial. No conceder membresía en `horus_l2_owner`.

3. Antes de reglas generales de `pg_hba.conf`, permitir cada pareja y rechazar el resto (el documento B1 añade la entrada del aprovisionador, necesaria para ejecutar SQL conectado a cada base):

```text
host horus_l2_test         horus_l2_test_runner         127.0.0.1/32 scram-sha-256
host horus_l2_restore_test horus_l2_restore_test_runner 127.0.0.1/32 scram-sha-256
host all horus_l2_test_runner,horus_l2_restore_test_runner 0.0.0.0/0 reject
host all horus_l2_test_runner,horus_l2_restore_test_runner ::/0 reject
```

`horus_group` no debe existir en esta instancia ni concederse a estos usuarios. Si se crea otra base con CONNECT para PUBLIC, la barrera rechazará las pruebas hasta revisar y limitar sus privilegios.

4. Como aprovisionador, **conectado a `horus_l2_test`**, preparar esquema y marcador:

```sql
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO horus_l2_test_runner;
CREATE SCHEMA horus_test_control AUTHORIZATION horus_l2_owner;
CREATE TABLE horus_test_control.authorization (
    database_name name PRIMARY KEY,
    database_oid oid NOT NULL,
    role_name name NOT NULL,
    purpose text NOT NULL
);
ALTER TABLE horus_test_control.authorization OWNER TO horus_l2_owner;
INSERT INTO horus_test_control.authorization
SELECT current_database(), oid, 'horus_l2_test_runner', 'horus-l2-destructive-tests'
FROM pg_database WHERE datname = current_database();
REVOKE ALL ON SCHEMA horus_test_control FROM PUBLIC;
REVOKE ALL ON horus_test_control.authorization FROM PUBLIC;
GRANT USAGE ON SCHEMA horus_test_control TO horus_l2_test_runner;
GRANT SELECT ON horus_test_control.authorization TO horus_l2_test_runner;
```

Repetir en `horus_l2_restore_test`, conectado explícitamente a ella y sustituyendo el usuario por `horus_l2_restore_test_runner`. Comprobar privilegios reales: los usuarios no deben poseer bases ni marcador; solo los objetos de aplicación que creen en `public`, suficiente para migrarlos y limpiarlos.

## Configuración y ejecución futura

Después de revisión, copiar `.env.pgsql-testing.local.example` a `.env.pgsql-testing.local`, ignorado por Git. Completar contraseña y una APP_KEY exclusiva. El consentimiento queda vacío hasta autorizar pruebas destructivas. También se admiten variables seguras del proceso sin archivo. No reutilizar credenciales o APP_KEY de desarrollo.

Usar consola nueva sin variables DB, de servicios externos o libpq heredadas. `$_ENV`, `$_SERVER`, `getenv()` y configuración efectiva deben coincidir. No modificar `.env` ni `phpunit.xml` para seleccionar PostgreSQL.

Solo cuando se autorice L2.2-B:

```powershell
php scripts/pgsql-test.php check
php scripts/pgsql-test.php artisan migrate:status
php scripts/pgsql-test.php test --filter=AdminMatriculaTest
php scripts/pgsql-test.php test
```

`check` verifica una sesión real sin DDL/DML; exige igualmente marcador y consentimiento. El runner tiene una lista cerrada de comandos Artisan y rechaza opciones para sustituir conexión, bootstrap o configuración. Los futuros `migrate`, `migrate:rollback`, `migrate:fresh`, `db:wipe` y `db:seed` mediante `artisan <comando>` **escriben o destruyen datos**: no ejecutarlos en L2.2-A.

`php vendor/bin/phpunit --configuration phpunit.pgsql.xml` usa el mismo bootstrap obligatorio. Evitar `php artisan test --configuration=phpunit.pgsql.xml`: arranca un padre antes de seleccionar el perfil. `composer test` limpia configuración y no es el punto de entrada PostgreSQL.

El runner no admite `pg_restore`, comandos arbitrarios ni destinos alternativos. Además, `GuardedPostgresSchemaState::load()` bloquea la restauración automática que Laravel podría intentar desde los comandos de migración permitidos, antes del proceso externo. Una fase posterior debe revisar la restauración: dump solo de `public`, con datos ficticios, sin propietarios/ACL heredados; verificación de la base de restauración con su usuario y conservación del marcador. No restaurar un dump completo que reemplace el esquema de control. L2.2-A no implementa ni acredita una restauración.

## Recuperación ante rechazo

Detenerse; no quitar la barrera, ampliar allowlists, introducir credenciales privilegiadas ni probar otro destino. Revisar solo metadatos de perfil/entorno, host/puerto, base, usuario, presencia de caché y fuentes de variables. No imprimir contraseñas, URLs ni archivos locales.

Si hay caché, identificar el archivo efectivo y su uso por desarrollo; no eliminarlo automáticamente. El operador debe resolverlo por separado y abrir una consola limpia. Si faltan marcador o privilegios, volver al aprovisionador: la aplicación no los repara. Una base inexistente nunca se crea automáticamente.

## Evidencia y límites

Los tests simulan configuración, PDO, identidad y comandos. Verifican ausencia de apertura del conector tras configuración inválida, de habilitación de escrituras tras identidad inválida y de despacho de migraciones/limpieza. Ejercitan el hook de bootstrap antes de proveedores y rechazan conexiones dinámicas sin sockets.

Pendiente L2.2-B: identidad real, autenticación/HBA, migraciones/tipos PostgreSQL, supervivencia del marcador durante limpiezas, concurrencia, caché compartida y restauración. `array` no demuestra invalidación entre procesos; probar caché `database` requerirá revisar esta política explícitamente.

Esta barrera cubre las rutas Laravel/runner previstas, no comandos arbitrarios con privilegios: PDO directo, sustitución del conector/listeners, herramientas SQL, clientes HTTP ajenos a Laravel o un operador que manipule el servidor están fuera de la garantía. Autenticación y mínimo privilegio de PostgreSQL son barreras independientes indispensables.

La prohibición de carga cubre el `SchemaState` devuelto por las conexiones del perfil. Crear manualmente otro `PostgresSchemaState`, sustituir la fábrica o invocar herramientas externas directamente queda fuera de ese recorrido. No se afirma aislamiento absoluto ni identidad inequívoca del clúster por su OID; el aprovisionamiento y sus cambios concurrentes siguen dentro de la frontera de confianza.

## Validación local de L2.2-A

Ejecutada el 27 de octubre de 2026, con PHP CLI 8.4.26, sin conexiones PostgreSQL:

| Comprobación | Resultado |
| --- | --- |
| PHPUnit, dos clases de seguridad con dobles | 66 pruebas, 245 aserciones |
| PHPUnit completo con `phpunit.xml` / SQLite en memoria | 258 pruebas, 1075 aserciones |
| Pint sobre los 11 archivos PHP afectados | Aprobado |
| Sintaxis PHP y `git diff --check` | Sin errores |

Revalidación tras corregir los dos P2, el mismo día y sin PostgreSQL real:

| Comprobación | Resultado |
| --- | --- |
| P2-1: SQL de permisos por columna y rechazo antes de habilitar escritura | 3 pruebas, 30 aserciones |
| P2-2: rutas SQL/dump/alternativas, bootstrap compartido y flujo indirecto de migración con dobles | 7 pruebas, 33 aserciones |
| Dos clases de seguridad, incluidos los 66 casos originales | 76 pruebas, 308 aserciones |
| Suite PHP completa con `phpunit.xml` / SQLite en memoria | 268 pruebas, 1138 aserciones |
| Pint en modo comprobación sobre los 14 archivos PHP de L2.2-A | Aprobado |
| Sintaxis PHP de esos 14 archivos y `git diff --check` | Sin errores; Git avisa conversión LF/CRLF en `bootstrap/app.php` |

Las pruebas de entrada verifican el enlace de ambos archivos al bootstrap común, la selección de `tests/bootstrap-pgsql.php` en el XML y el arranque real de Laravel con entorno ficticio y conectores bloqueados. No ejecutan el runner ni el perfil PostgreSQL contra un servidor. La prueba indirecta usa los comandos reales de Laravel con repositorio/migrador simulados y un archivo temporal vacío fuera del repositorio; no ejecuta migraciones ni borrados reales.

Durante la primera ejecución de regresión, una prueba omitió el doble de procesos e intentó invocar `psql`; Windows lo rechazó porque no está instalado. No hubo conexión ni restauración. Se corrigió la prueba para bloquear la propia fábrica de procesos antes de continuar la validación.

No se ejecutó el perfil PostgreSQL ni el runner contra un servidor. No se crearon bases, usuarios PostgreSQL, marcadores ni archivos locales con secretos. El aprovisionamiento y toda evidencia PostgreSQL real quedan pendientes de revisión independiente y autorización de L2.2-B.
