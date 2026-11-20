# Consolidación — Fase 4: correcciones y preparación

Fecha: 05/11/2026. Rama `anderson`, HEAD `7914438d59b77b6a5e09a15d371e635cca2d894d`.

Este documento y `consolidacion-fase-4-manifiesto.json` preparan una validación futura. No autorizan
conexiones, migraciones, reconstrucciones, publicación, commits ni sincronización. El manifiesto
contiene los **32 nombres completos**, sus hashes, 28 candidatas y cuatro excluidas, pruebas
relacionadas y 55 dependencias de código/pruebas con SHA-256. No es un ejecutor.

## Correcciones

- `ConcurrencyPostgresTest`: conserva ambas sesiones, bloqueo 55P03, commit y comprobación de un
  único administrador. Después exige 403 al coordinador, incluso con permisos directos, conforme
  a `1af8843`. No cambia `AdminProtection` ni el controlador. La prueba se renombra para describir
  esa política; los escenarios de último administrador de `AdminProtectionTest` siguen presentes.
- Reclamaciones: solo las eliminaciones de padres referenciados mediante `ON DELETE RESTRICT`
  cambian de 23503 a **23001**. Se añade una prueba PostgreSQL de referencia inexistente que exige
  **23503**, para conservar la distinción. No se modifican restricciones ni se aceptan listas de
  errores. Continúan 23505 para únicos, 23514 para CHECK, 23502 para NOT NULL, P0001 para el trigger
  de historial y 55P03 para la espera de bloqueo.
- D9: retirado el cuerpo destructivo de `ReclamacionesDdlPostgresTest`. Su sustituto es una clase
  PHPUnit directa, sin Laravel, traits de reconstrucción ni comandos destructivos, que permanece
  omitida incondicionalmente. **D9 no se ejecutó**, ni siquiera para comprobar su omisión.
- `000033`: el predicado propuesto es `orden >= 0 AND orden <= 9999`, tanto para CHECK PostgreSQL
  como para los triggers SQLite existentes. `down()` permanece intacto: primero elimina la tabla
  hija, con sus triggers/restricciones, y después las tres columnas editoriales del padre. Esto
  conserva su coherencia estructural, pero no recupera contenido ni imágenes. No se ejecutó up/down.

La corrección de SQLSTATE se apoya en la observación histórica de PROJECT_CONTEXT.md (sección de
aplicación PostgreSQL del 31/10) y en la rama oficial PostgreSQL 18:
[ri_triggers.c](https://github.com/postgres/postgres/blob/REL_18_STABLE/src/backend/utils/adt/ri_triggers.c)
distingue `ERRCODE_RESTRICT_VIOLATION` de `ERRCODE_FOREIGN_KEY_VIOLATION`.
[Códigos oficiales](https://www.postgresql.org/docs/18/errcodes-appendix.html).
No se volvió a observar el error en una conexión real durante esta fase.

## Selección exacta de migraciones

La lista ordenada y verificable está en el JSON. Se conservan ambos nombres completos `000009` y
los saltos de numeración. Las candidatas NO significan pendientes reales ni ejecución aprobada.

| Selección | Migraciones |
| --- | --- |
| Candidatas: 28 | Tres de infraestructura `0001_01_01`; `2026_10_26_000001`–`000005`; `2026_10_27_000006`–`000013`, contando los dos archivos `000009`; `000020`, `000021`, `000024`–`000032` |
| Excluida | `2026_10_28_000022_create_registros_pendientes_table.php`: suspensión vigente |
| Excluida | `2026_10_28_000023_hold_matricula_notifications_backlog.php`: transformación irreversible, sin autorización |
| Excluida | `2026_11_02_000033_add_contenido_to_servicio_publicaciones.php`: corrección y continuidad editorial pendientes de aprobación |
| Excluida | `2026_11_03_000034_add_fuente_to_chat_logs.php`: incorporación independiente pendiente; AI apagado |

Si alguna excluida ya figura aplicada, no se elimina del registro ni se revierte: detenerse y
contrastar su esquema/datos con el alcance autorizado. Tampoco se marca como aplicada una excluida
para sortear la selección. Si `000033` ya existe en un destino, esta edición del archivo no modifica
su CHECK instalado: se necesitaría una nueva corrección hacia adelante autorizada, sin reescribir
historia aplicada. No se presume que está ausente en todos los entornos.

## Matriz de pruebas

El JSON asocia cada archivo de migración con rutas reales de pruebas. Las asociaciones de tablas
base son cobertura indirecta de consumidores; no se presentan como pruebas específicas de rollback.

| Área / migraciones | Pruebas principales | Estado y límites |
| --- | --- | --- |
| Usuarios, permisos, JWT y auditoría | AdminAuthenticationTest, AdminAuthorizationTest, AdminProtectionTest, ProteccionAdministradoresTest; ConcurrencyPostgresTest | Preparadas; carrera corregida pendiente PostgreSQL |
| Cache/colas/configuración | Consumidores de autenticación, MatriculaNotificationTest, BackendPerformanceTest | Indirectas; faltan comprobaciones específicas de catálogo/recuperación |
| Estudiantes, cursos y matrículas | AdminEstudianteTest, AdminCursoTest, AdminMatriculaTest, MatriculaOperationsTest | SQLite integrada pendiente en esta fase |
| `000012` / `000013` | MatriculaSensitivePermissionsTest, MatriculaIntegrityConstraintsTest; MatriculaConstraintsPostgresTest, MatriculaRulesPostgresTest, MatriculaOperacionesConcurrenciaPostgresTest | CHECK de 000013 no instalado en SQLite; evidencia PG anterior no se renueva aquí |
| `000020` | MatriculaNotificationTest | Revisar métodos dependientes del backlog excluido antes de seleccionar |
| `000021` | CursoPublicacionTest, Sprint17PublicacionTest | Sin publicación real ni generación editorial automática |
| Procesos, aprobaciones, `000024` | ProcesoResponsableTest, AprobacionesRespaldoPermissionsMigrationTest, AprobacionesConcurrenciaPostgresTest | Roles existentes y concesiones manuales deben conservarse |
| `000025` / `000026` | ServicioTecnologicoTest | Seleccionar métodos sin editor 000033; no ejecutar indiscriminadamente su archivo |
| `000027` / `000028` | CotizacionTest | Concurrencia PostgreSQL no demostrada por SQLite |
| `000029` | CotizacionOrigenMigracionTest | Exclusiva SQLite; hace falta variante PostgreSQL |
| `000030` / `000031` | ReclamacionesMigracionTest, ReclamacionesDdlPostgresTest, ReclamacionesConcurrenciaPostgresTest | DDL/concurrencia nueva pendiente; D9 separada y bloqueada |
| `000032` | NotificacionAudienciaMigracionTest, NotificacionAudienciaInventarioPostgresTest, NotificacionAudienciaPuntoRetornoTest; NotificacionAudienciaPostgresTest | Evidencia histórica PG: 29 pruebas, 631 aserciones; no repetida |
| `000022` / `000023` | Sprint14EmailVerificationTest / MatriculaNotificationBacklogTest | Fuera del manifiesto de ejecución |
| `000033` | ServicioElementosOrdenPreparacionTest; nuevos casos de ServiciosContenidoTest | Predicado en fixtures SQLite probado; integración y aplicación DDL no ejecutadas |
| `000034` | HorusAiTransicionTest | Fuera del manifiesto de ejecución |

`phpunit.pgsql.xml` y las suites con RefreshDatabase/DatabaseTruncation **no aplican este manifiesto
automáticamente**. Pueden reconstruir las 32 migraciones aunque se filtre el nombre de una prueba.
Antes de usarlas se necesita preparar/revisar una cápsula específica que seleccione esquema y
métodos compatibles, sin añadir tablas excluidas ni modificar las salvaguardas actuales.

## Aislamiento y dependencias

1. Mantener S7/55432 suspendido, PostgreSQL compartido fuera de las pruebas y todos los predeterminados
   de AI/correo/publicación apagados. No reutilizar `horus_group_ensayo`: contiene copia de datos reales.
2. El futuro perfil aislado exige `127.0.0.1:55433`, identidad física y roles de la lista fija de
   `PostgresTestGuard`, marcador, versión y permisos mínimos válidos. No basta el nombre de base.
   No cambiar estos valores para acomodar un destino que no pasa la barrera.
3. Permisos: tablas Spatie antes de sus migraciones de datos. Roles antes de verificar concesiones.
   RolesAndPermissionsSeeder solo en instalación sintética expresamente autorizada; no resincronizar
   una base compartida. Congelar sus constantes usadas por 000026/000028/000031.
4. Orden: estudiantes/cursos → matrículas → 000013/000020; cursos/usuarios/permisos → 000021;
   usuarios → 000025 → 000027 → 000029; usuarios → 000030 → 000031; 000010 → 000032.
   000026/000028 dependen de Spatie y funcionalmente de sus módulos. 000023 no es requisito DDL
   de las posteriores. 000033 depende de 000025; 000034 de chat_logs; ambas siguen excluidas.
5. El código actual del editor consume 000033: no habilitar esas operaciones sobre el esquema reducido.
   ServiciosTecnologicosContenidoSeeder es importación opcional excluida, no requisito DDL.
6. Sesión/caché/correo array, cola sync, sin HTTP externo. Guardas de destino y privacidad intactas.

## Clasificación de operaciones de prueba

- **Sin persistencia externa:** análisis, generación/lectura de manifiesto, dobles de la barrera PG,
  predicado extraído a fixtures SQLite `:memory:`. No se invoca la migración para probar el predicado.
- **Con DDL/DML transaccional:** integridad/rollback de 000030 y 000032; requieren autorización PG
  aunque pretendan revertir. El recorrido de inventario de 000032 también realiza DDL temporal.
- **Destructivas para el destino:** RefreshDatabase (invoca migrate:fresh), DatabaseTruncation,
  pruebas con down/up y concurrencia que confirman fixtures. Nunca ejecutar sobre bases compartidas.
  Los down/up conservan como máximo lo que su implementación permite: no garantizan recuperación.
- **D9:** reconstrucción global, además desconecta conexiones. No está contenida por una transacción
  exterior y no tiene ejecutor habilitado en esta entrega.

## Contrato obligatorio para recuperar D9 en una fase posterior

No basta activar el perfil PG ni una variable genérica. Antes de introducir o ejecutar código
destructivo se requiere aprobación exclusiva, con destino, identidad física, alcance, responsable,
ventana y evidencia de que el contenido puede perderse. Propuesta de destino: exclusivamente
`horus_l2_restore_test` con su rol dedicado en la identidad ya fijada, una vez certificado desechable.
No se ha comprobado ahora que exista o sea desechable.

El ejecutor futuro deberá ser independiente de RefreshDatabase, negar por defecto y validar la
autorización específica y el destino ANTES de preparar esquema. Debe comprobar identidad física,
marcador, base, rol, host, puerto, versión y privilegios en cada conexión/reconexión; rechazar
configuración cacheada o destinos alternativos; volver a validar antes de cada borrado. Ni siquiera
una autorización permitirá reconstruir fuera de la lista física fija existente. Sin eso, abortar.

Conservar estas comprobaciones del D9 original: después del borrado, tabla/trigger ausentes y función
residual identificada; dos reconstrucciones controladas sin duplicar función/trigger; trigger con
función correcta; historial protegido con P0001; 000030/000031 registradas una vez. La selección debe
respetar las cuatro exclusiones del manifiesto, en lugar de migrar todo. Ensayar fallos antes de cada
operación y después de desconectar. Conservar evidencia y no limpiar automáticamente un destino
cuya identidad haya cambiado. Estos escenarios **no están aprobados ni ejecutados**.

## Validación realizada en Fase 4

- PHPUnit directo con `phpunit.xml`, sin caché de resultados: **113 pruebas, 404 aserciones, exit 0**.
  Archivos: PostgresTestGuardTest, PostgresTestArgumentsTest y ServicioElementosOrdenPreparacionTest.
  Las primeras dos usan dobles y no conectan a PostgreSQL. La última aporta cuatro casos y 36
  aserciones sobre INSERT/UPDATE con -1, 0, 9999 y 10000, predicado extraído del AST y triggers en
  una tabla sintética SQLite en memoria. No acredita instalación de 000033 ni CHECK PostgreSQL.
- Ejecución acotada previa del predicado: 4 pruebas, 36 aserciones; incluidas en las 113, no sumar.
- Sintaxis PHP: siete archivos aprobados. Pint acotado: aprobado después de ordenar dos imports y
  normalizar finales de línea del archivo de concurrencia afectado. Sin reformateo global.
- Sin prueba PostgreSQL nueva. `000032` 29/631 y demás resultados previos son históricos.
- Sin D9, servicios, migraciones aplicadas, seeders ni llamadas reales a proveedores.
- No suite Feature/completa: RefreshDatabase invoca el migrate:fresh prohibido en esta fase.
  Las nuevas regresiones integradas quedan preparadas, no aprobadas por ejecución.
- No build/frontend/Python: no hubo cambios de esas áreas. No cambios en app/Support o app/Services.

## Continuación

Revisar las correcciones y decidir 000033/000034/000023 por separado. Preparar la cápsula de pruebas
con las exclusiones antes de autorizar validación integrada. Luego ejecutar solo las pruebas y
destinos aprobados, con respaldo/restauración ensayados e inspección del catálogo real. No usar
`migrations` como única evidencia del esquema. Revisar de nuevo los 24 commits antes de cualquier
sincronización: esta fase no cambia su contenido ni resuelve la revisión pendiente de DB_PASSWORD
en .env.example. No se imprimen valores. No declarar consolidación ni entrega operativa finalizadas.
