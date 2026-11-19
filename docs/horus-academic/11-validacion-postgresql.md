# 11 — Validación en PostgreSQL aislado (17/11/2026)

Primera ejecución de las migraciones `000061`–`000078` y de las pruebas de Horus Academic en **PostgreSQL 18.6 real**, en el clúster de
pruebas independiente `127.0.0.1:55433` (`cluster_name horus_pgt_55433`, base `horus_l2_test`, rol `horus_l2_test_runner` sin
privilegios). Autorizado expresamente por el propietario el 17/11/2026 para ese clúster y solo para él.

## Qué se hizo y qué NO se tocó

- Se arrancó el clúster con `pg_ctl start` como proceso de usuario (sin servicio ni elevación) y se superó la sonda de solo lectura
  (`php scripts/pgsql-test.php probe`: marcador, mínimo privilegio e identidad del clúster).
- `migrate:fresh` aplicó las 78 migraciones sobre la base desechable `horus_l2_test`, **incluidos los disparadores** de inmutabilidad
  del libro de pagos y de suma de pesos.
- **No se tocó** `horus_group`, ni el clúster S7 (55432), ni el servicio cotidiano (5432), ni ningún dato real. Los salvaguardas de
  `PostgresTestGuard`/`GuardedPostgresConnector` no se modificaron. La contraseña no se imprimió ni se copió.

## Defectos que SQLite no podía ver (corregidos antes de ejecutar nada en una base real)

| # | Defecto | Efecto en PostgreSQL | Corrección |
|---|---|---|---|
| 1 | `asistencias.estado` era `varchar(10)` y «justificada» tiene 11 letras | **No se habría podido registrar ninguna falta justificada** (error 22001) | `string('estado', 12)` en la migración `000070` |
| 2 | Las migraciones `000072` y `000074` usaban `create function` | `db:wipe` no borra funciones: un segundo `migrate:fresh` fallaba («function already exists», 42723) | `create or replace function` |
| 3 | `horus:verificar-finanzas` contaba con `select *` + `group by` | Error 42803 (agrupación inválida) | `select('reversa_de_id')` antes del `group by` |
| 4 | `GestionSesiones::actualizar` comparaba `18:00` con `18:00:00` | Repetir una edición idéntica **avanzaba la revisión** de la sesión | Comparación de valores normalizados; solo se asigna lo que cambia |
| 5 | El disparador de suma de pesos corría antes que el CHECK de la columna | Un peso individual fuera de rango devolvía `P0001` en vez de `23514` y rompía una prueba existente | El disparador deja pasar un peso fuera de `(0, 100]` para que lo rechace el CHECK |

Las correcciones 1–3 y 5 se hicieron sobre migraciones y comandos aún no ejecutados en ninguna base real; no hace falta migración correctiva.

## Resultados

| Prueba | Motor | Resultado |
|---|---|---|
| `tests/PostgresIntegrity/AcademicoFase9PostgresTest.php` (nueva) | PostgreSQL | **10 pruebas, 44 aserciones, OK** |
| Suite `tests/PostgresIntegrity` completa (existentes + nueva) | PostgreSQL | **153 pruebas, 1453 aserciones, OK** (2 omitidas, preexistentes) |
| `tests/PostgresIntegrity/AcademicoConcurrenciaPostgresTest.php` (nueva, dos sesiones) | PostgreSQL | **7 pruebas, 65 aserciones, OK** |
| `tests/Academic/**` (Pest, 216 pruebas) con `--configuration=phpunit.pgsql.xml` | PostgreSQL | **216 aprobadas** tras las correcciones (la corrida completa tardó ≈ 65 min: `RefreshDatabase` por prueba) |
| `tests/Academic` y `tests/Contract` | SQLite | 216 aprobadas |

Lo que la prueba nueva demuestra en PostgreSQL: el libro de pagos rechaza borrados y ediciones de lo ya resuelto (disparador), las
asignaciones de pago son inmutables, los CHECK de transacciones, obligaciones, cuotas, sesiones, asistencias y certificados, un pago con
a lo sumo un reverso, clave de idempotencia única por obligación, una sola obligación y un solo certificado **vigentes** por matrícula
(índice parcial), número de sesión único por edición, y que la suma de pesos de las evaluaciones de una edición **no pasa de 100**
(disparador, también al editar).

## Concurrencia con dos sesiones (17/11/2026)

`AcademicoConcurrenciaPostgresTest` aplica el protocolo del proyecto: la sesión B abre una transacción, toma los bloqueos que tomaría la
otra petición y deja sus escrituras sin confirmar; la petición real (con `lock_timeout` corto) debe quedar **bloqueada** (55P03) sin dejar
rastro, y al confirmar B se repite y decide con el estado ya confirmado. Escenarios: dos pagos que juntos exceden el saldo, la misma
`Idempotency-Key` en carrera, dos confirmaciones del mismo pago (el reparto no se duplica), dos reversos del mismo pago (queda uno),
dos emisiones del certificado (queda un vigente), dos sesiones que se cruzan en la misma aula, y «Matricular con pago» bloqueado (no deja
matrícula, obligación, pago ni archivo, y luego se completa; repetir no duplica).

**Verificación por mutación** (se quitó temporalmente cada bloqueo y se confirmó que la prueba lo detecta; el código se restauró):

| Se quitó | ¿La prueba falla? |
|---|---|
| bloqueo consultivo del aula (`pg_advisory_xact_lock`) en `GestionSesiones` | **Sí** — el cruce de aula ya no queda bloqueado |
| `lockForUpdate` de la obligación en `GestionPagos` | **Sí** — el sobrepago ya no queda bloqueado |
| `lockForUpdate` de la obligación (idempotencia) y de la matrícula (certificado) | No: la barrera de fondo es el **índice único** (clave por obligación; un certificado vigente por matrícula). Sin el bloqueo la petición igual espera en el índice y, al confirmar B, fallaría con un duplicado (23505) en vez de devolver lo ya creado. El bloqueo da la respuesta limpia; el índice garantiza que nunca haya duplicados |

La primera versión de estas pruebas usaba `FOR UPDATE` en B y **no detectaba** la mutación del sobrepago (el INSERT de la petición se
bloqueaba igual en la comprobación de la clave foránea); por eso B usa `FOR NO KEY UPDATE`, que choca con el bloqueo del servicio pero no
con la clave foránea.

## Suite `Feature` completa sobre PostgreSQL (17/11/2026)

Primera vez que las 4.076 pruebas de `tests/Feature` corren sobre PostgreSQL real (≈ 2 h): **7 errores y 3 fallas**, que quedaron así:

| Causa | Pruebas | Origen | Resolución |
|---|---|---|---|
| Las pruebas de `down()` de las migraciones del CRM y de convocatorias no conocían mis tablas nuevas, que dependen de `convocatorias`, `matriculas` y `evaluaciones` (PostgreSQL impide soltar una tabla con dependientes; SQLite no) | `ConvocatoriasMigracionTest`, `ClientesMigracionTest`, `OportunidadesMigracionTest`, `ProspectosMigracionTest`, `ProyectosMigracionTest`, `SoporteMigracionTest` | **Mía** | Mis migraciones se añadieron a `RevierteCadenaCrm::CADENA_CRM`; las 92 pruebas `*MigracionTest` pasan |
| Soltaba `solicitudes_cotizacion` con `prospectos` y `oportunidades` apuntando a ella (tablas anteriores a este trabajo) | `CatalogoWebServiciosTest` | Previa | `DROP … CASCADE` solo en PostgreSQL |
| Suponía ids de matrícula que empiezan en 1; PostgreSQL no reinicia sus secuencias entre pruebas | `AdminSearchTest` | Previa | La cuenta exacta (3) solo se exige en SQLite |
| Esperaban la forma antigua del resumen y no conocían la ruta de búsqueda (se habían ajustado después de lanzar la corrida) | `AcademicoPanelTest`, `InventarioRutasPanelTest` | Mía (N4) | Ya ajustadas; pasan en ambos motores |

Tras las correcciones se repitieron en PostgreSQL todas las afectadas y pasaron; SQLite sigue en 4.195 aprobadas, 2 omitidas y 1 fallida
(`MatriculaNotificationBacklogTest`, conocida). **Hallazgo:** estas pruebas nunca se habían ejecutado juntas sobre PostgreSQL; por eso
sus fragilidades previas no se habían visto.

## Límites (siguen «no medido»)

- **Carreras sin sincronizar:** las pruebas de concurrencia son deterministas (B retiene el bloqueo). No se lanzaron N procesos
  simultáneos sin coordinación ni pruebas de estrés; lo medido es que cada bloqueo y cada índice cumple su función.
- **Tras las correcciones**, la suite `Feature` no se repitió entera sobre PostgreSQL: se repitieron las pruebas afectadas.
- **Restauración de respaldo** y **carga de datos reales:** sin ejecutar.
- Las migraciones siguen **sin ejecutarse en la base real**; esta validación fue solo sobre la base desechable.

## Cómo repetirlo

```powershell
# 1. Arrancar el clúster (proceso de usuario, sin servicio)
$root = "$env:LOCALAPPDATA\HorusPgTest55433"
& "C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe" start -D "$root\data" -l "$root\log\server.log" -w -t 60
# 2. Sonda de solo lectura
php scripts/pgsql-test.php probe
# 3. Con consentimiento destructivo (solo base desechable): migraciones y pruebas
$env:HORUS_PG_TEST_CONFIRM = 'AUTHORIZE_HORUS_L2_DESTRUCTIVE_TESTS'
php scripts/pgsql-test.php artisan migrate:fresh
php scripts/pgsql-test.php test --filter=AcademicoFase9PostgresTest
$env:HORUS_PG_TEST_PROFILE = '1'; vendor/bin/pest --configuration=phpunit.pgsql.xml tests/Academic
# 4. Detener el clúster (queda detenido al terminar cada sesión de pruebas)
& "C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe" stop -D "$root\data" -m fast
```
