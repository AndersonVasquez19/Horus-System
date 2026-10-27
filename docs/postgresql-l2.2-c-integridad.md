# L2.2-C — Integridad de matrículas en PostgreSQL

Estado: **regla confirmada; migración preparada, sin aplicar**. Nada de este documento se ha
ejecutado contra PostgreSQL.
Las pruebas asociadas están en `tests/PostgresIntegrity/`, que no forma parte de `phpunit.xml`
ni de `phpunit.pgsql.xml`. Las clases se omiten salvo bajo el perfil aislado
(`HORUS_PG_TEST_PROFILE=1`, base `horus_l2_test` en `127.0.0.1:55432`).

## 1. Situación actual

| Regla | Dónde se aplica hoy | Riesgo |
|---|---|---|
| Un estudiante no tiene dos matrículas vigentes en el mismo curso | `StoreMatriculaRequest`, `UpdateMatriculaRequest` y `MatriculaController::assertNoActiveDuplicate()` dentro de `lockForUpdate()` sobre el curso | Solo la app la garantiza; una escritura fuera del panel (seeder, script, consola) puede duplicar |
| `0 <= cupos_disponibles <= cupos_maximos` | Controlador (comprueba `> 0` antes de `decrement`) | En PostgreSQL `unsignedInteger` es `integer` **sin** `CHECK`: admite negativos |
| `precio >= 0`, `monto_pagado >= 0` | Validación de formularios | Igual: la base acepta negativos |

La concurrencia está bien diseñada en el código: alta, edición, anulación, eliminación y restauración
bloquean la fila del curso (y de la matrícula) con `SELECT ... FOR UPDATE` y vuelven a comprobar
duplicados y cupos dentro del bloqueo. SQLite ignora ese bloqueo, por lo que la suite habitual no lo mide;
`ConcurrencyPostgresTest` lo verificará en PostgreSQL.

## 2. Regla de rematrícula (confirmada por el negocio)

> Se admite una nueva matrícula de un estudiante en un curso cuando todas sus matrículas anteriores
> en ese curso están **anuladas** o **eliminadas lógicamente** (`deleted_at` no nulo).

Es exactamente lo que hace hoy el código: `assertNoActiveDuplicate()` excluye `estado = 'anulada'` y,
por `SoftDeletes`, las filas eliminadas. El índice solo traslada esa regla a la base.
Además: los registros históricos se conservan, se rechaza restaurar una matrícula si existe otra
vigente y nunca se supera el máximo de cupos.

## 3. Consultas previas (solo lectura)

Ejecutar en la base de destino **antes** de aplicar la migración. Cualquier fila devuelta bloquea la
migración y debe resolverse a mano, con decisión del responsable de datos:

```sql
-- Duplicados vigentes que harían fallar el índice único parcial.
select estudiante_id, curso_id, count(*) as vigentes, array_agg(id order by id) as ids
from matriculas
where deleted_at is null and estado <> 'anulada'
group by estudiante_id, curso_id
having count(*) > 1;

-- Cupos fuera de rango.
select id, codigo, cupos_maximos, cupos_disponibles
from cursos
where cupos_maximos < 0 or cupos_disponibles < 0 or cupos_disponibles > cupos_maximos;

-- Importes negativos.
select 'curso' as tabla, id, precio as importe from cursos where precio < 0
union all
select 'matricula', id, monto_pagado from matriculas where monto_pagado < 0;
```

## 4. Migración preparada (sin aplicar)

Archivo nuevo, sin modificar ninguna migración existente:
[`database/migrations/2026_10_27_000013_add_matriculas_integrity_constraints.php`](../database/migrations/2026_10_27_000013_add_matriculas_integrity_constraints.php).

| Elemento | Definición | Motores |
|---|---|---|
| `matriculas_vigente_unica` | índice único `(estudiante_id, curso_id) where deleted_at is null and estado <> 'anulada'` | SQLite y PostgreSQL |
| `cursos_cupos_rango` | `cupos_maximos >= 0 and cupos_disponibles >= 0 and cupos_disponibles <= cupos_maximos` | PostgreSQL |
| `cursos_precio_no_negativo` | `precio >= 0` | PostgreSQL |
| `matriculas_monto_no_negativo` | `monto_pagado is null or monto_pagado >= 0` | PostgreSQL |

Verificaciones hechas sobre el código antes de fijar el SQL:

- Estados válidos (`Store/UpdateMatriculaRequest`): `pendiente`, `pagada` y `anulada`.
  Consumen cupo `pendiente` y `pagada`; `anulada` libera cupo.
- `Matricula` usa `SoftDeletes`. Eliminar una matrícula vigente devuelve su cupo, y las consultas
  de duplicados y de cupos (`Matricula::query()` y `$curso->matriculas()`) excluyen las eliminadas.
- `estado` es `not null`, así que `estado <> 'anulada'` no tiene el problema de los nulos y
  coincide con `where('estado', '!=', 'anulada')`.
- `CursoController::update` recalcula `cupos_disponibles = cupos_maximos - activas` y rechaza bajar
  el máximo por debajo de las activas, por lo que el CHECK de cupos no se dispara desde el panel.

**Comprobación previa integrada**: `up()` ejecuta las consultas de la sección 3 antes de cualquier
DDL. Si encuentra filas incompatibles, lanza una excepción con los IDs (hasta 20 por regla) y no
crea nada. En PostgreSQL, además, Laravel ejecuta la migración en una transacción, porque el DDL
es transaccional.

Pruebas SQLite (`tests/Feature/MatriculaIntegrityConstraintsTest.php`): el índice rechaza un
duplicado vigente escrito fuera del panel; el historial se conserva y se permite rematricular tras
anular o eliminar; la base impide restaurar sobre una vigente; en el panel ese conflicto sigue siendo
un error de validación (no un 500); la migración se detiene sin cambios ante duplicados, cupos o
montos inválidos; y es reversible.

**No ejecutar `php artisan migrate` contra `horus_group`** mientras esta migración no se haya
revisado y probado en `horus_l2_test`. Si se ejecutara por error con datos incompatibles, se
detendría sin aplicar cambios.

Notas:

- Las tablas son pequeñas; aun así, el índice puede crearse con `create unique index concurrently`
  si en producción tuvieran volumen. `concurrently` no puede ejecutarse dentro de una transacción, y
  por eso habría que separarlo en su propia migración con `public $withinTransaction = false;`.
- Bloqueos en PostgreSQL: `create unique index` (sin `concurrently`) toma un bloqueo `SHARE` en
  `matriculas`, que bloquea escrituras mientras se construye el índice; cada `add constraint ... check`
  toma `ACCESS EXCLUSIVE` en su tabla mientras valida las filas. Con tablas pequeñas dura
  milisegundos; aun así, conviene aplicarla en una ventana sin uso del panel.
- Transacción: PostgreSQL ejecuta la migración completa en una transacción (`supportsSchemaTransactions`).
  SQLite no, pero allí solo se crea el índice, después de la comprobación previa, así que no puede
  quedar a medias. `up()` no es idempotente fuera del control de `migrations` (repetirla falla porque
  el índice ya existe); `down()` sí lo es (`if exists`).
- Ningún dato se modifica: la migración solo lee para diagnosticar y crea índice y restricciones.
- Fuera de alcance, porque son decisiones de negocio: un `CHECK` sobre los valores de `estado` y
  sobre `fecha_matricula`.
- Tras aplicarla, un `QueryException` con SQLSTATE `23505` en el alta indicaría una carrera que el
  bloqueo no cubrió. Hoy el bloqueo la evita, así que el índice es una defensa en profundidad.

## 5. Pruebas preparadas

| Clase | Escenario | Requisito para ejecutarse |
|---|---|---|
| `MatriculaRulesPostgresTest` | Duplicado rechazado; rematrícula tras anulación y tras eliminación; restauración rechazada con otra vigente; cupos nunca negativos; búsquedas `ILIKE` (incluida la coincidencia parcial por ID de la búsqueda global, que Laravel compila como `"id"::text ilike`) | Perfil aislado |
| `MatriculaConstraintsPostgresTest` | La base rechaza duplicados vigentes, restauraciones conflictivas, cupos y montos fuera de rango (SQLSTATE 23505/23514) | Perfil aislado **y** migración aplicada (si no existe el índice `matriculas_vigente_unica`, se omite) |
| `ConcurrencyPostgresTest` | Dos altas por el último cupo; dos altas del mismo estudiante; dos degradaciones simultáneas de administradores | Perfil aislado; la segunda sesión la abre `App\Support\Testing\ConcurrentTestSession` (ver sección 5.1) |

Las pruebas de concurrencia son deterministas: la segunda sesión toma el mismo bloqueo que el código y
la petición real se ejecuta con `lock_timeout`. Debe quedar bloqueada (SQLSTATE `55P03`) y, al
repetirse tras el commit, decidir con el estado ya confirmado. Usan `DatabaseTruncation`, porque con
`RefreshDatabase` la segunda sesión no vería los datos.

### 5.1 Segunda sesión para concurrencia (`ConcurrentTestSession`)

No se añadió un nombre de conexión `pgsql_concurrent`. Un nombre nuevo habría obligado a ampliar
la lista permitida de la barrera, y la configuración validada lleva `name => 'pgsql'`, que
`GuardedPostgresConnector` compara exactamente. En su lugar,
[`app/Support/Testing/ConcurrentTestSession.php`](../app/Support/Testing/ConcurrentTestSession.php):

- solo funciona con `HORUS_PG_TEST_PROFILE=1` y con la fábrica y el conector protegidos instalados;
- no acepta parámetros: revalida entorno y configuración con `TestDatabaseSafety::postgresConfiguration()`
  en cada apertura y rechaza cualquier host, puerto, base, usuario, opción, URL o nombre distintos;
- crea otra instancia de la misma conexión `pgsql` con la fábrica protegida, sin registrarla en
  `DatabaseManager` y sin tocar `config('database')`;
- su PDO pasa por `GuardedPostgresConnector`: arranca en solo lectura, verifica la identidad del
  servidor y el marcador independiente, y solo entonces habilita escrituras;
- `close()` revierte toda transacción y savepoint abiertos (nunca confirma) y desconecta.
  `RequiresIsolatedPostgres` lo llama en `tearDown()`, aunque la prueba falle.

Pruebas con dobles (`tests/Unit/ConcurrentTestSessionTest.php`, 17 casos, sin sockets): autoriza una
segunda sesión independiente; rechaza otro host, puerto, base (`horus_group`), usuario, opción, nombre
lógico o URL, y cambios de entorno posteriores, todo antes de conectar; rechaza fuera del perfil y con
un conector no protegido; ante una identidad incorrecta nunca habilita escrituras; y `close()` revierte
savepoint y transacción sin `commit`.

### Cómo están aisladas

- `php artisan test` y `phpunit.xml` (SQLite) no descubren la carpeta. Si se apuntan a ella a mano,
  cada prueba se omite en `setUp()` **antes** de arrancar Laravel, así que no se migra, trunca ni
  conecta nada. Se informan como *skipped*, nunca como aprobadas.
- `scripts/pgsql-test.php test` solo ejecuta las suites de `phpunit.pgsql.xml` (`tests/Unit` y
  `tests/Feature`) y únicamente acepta `--filter`. Para ejecutar esta carpeta habrá que añadir una
  suite `tests/PostgresIntegrity` a `phpunit.pgsql.xml` (cambio de infraestructura pendiente).
- Con `HORUS_PG_TEST_PROFILE=1`, `TestDatabaseSafety` sustituye la configuración de base de datos
  por el perfil validado y rechaza cualquier otra conexión o identidad antes de abrirla.

## 6. Autorizaciones pendientes

1. ~~Confirmación empresarial de la regla de rematrícula~~ (confirmada).
2. Aprovisionamiento de la instancia de pruebas (L2.2-B).
3. ~~Segunda conexión de pruebas~~: preparada como `ConcurrentTestSession` (sección 5.1), sin commit
   y pendiente de revisión independiente.
4. Aplicar la migración preparada, primero en `horus_l2_test`, y nunca en `horus_group` sin
   revisar antes las consultas previas de la sección 3.
