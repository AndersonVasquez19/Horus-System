# Fase 5.2 — Validación real de PostgreSQL (12/11/2026)

Línea base: HEAD `8b03c6e`, rama `monorepo/base`. Sin cambios funcionales, sin migraciones nuevas, sin push. Sustituye el estado «no iniciado» de `docs/fase-5-1-multimedia-y-produccion.md` §5.

**Alcance autorizado:** solo el clúster aislado `horus_pgt_55433` (`127.0.0.1:55433`, `system_identifier` `7702263743609677164`), base `horus_l2_test`, rol `horus_l2_test_runner`, datos desechables. Fuera de uso: 5432, 55432, `horus_l2_restore_test` y las migraciones suspendidas 000035/000036. La compatibilidad PostgreSQL **no se declara total**: ver §6.

## 1. Entorno y arranque

- Antes de arrancar: puerto 55433 libre, ningún proceso sobre el directorio, identificador físico leído con `pg_controldata` igual al esperado. `postmaster.pid` obsoleto (PID 7932, del 5/11) y estado `in production`: el arranque hizo recuperación por WAL sin incidencias.
- `pg_ctl start` como proceso de usuario (sin servicio); `php scripts/pgsql-test.php probe` correcto (base, rol, puerto, `cluster_name horus_pgt_55433`, identificador, solo lectura, `UTC`).
- Consentimiento destructivo: solo como variable de proceso en cada comando; `.env.pgsql-testing.local` no se modificó y el consentimiento sigue vacío.
- Cierre: `pg_ctl stop -m fast`, estado `shut down`, sin listener, sin procesos y sin `postmaster.pid`. 5432 (PID 5828) y 55432 (PID 6108) intactos.

## 2. Migraciones

`migrate:status` mostró 28 aplicadas y 8 pendientes. `artisan migrate` aplicó sin errores 000033, 000034, 000037, 000038, 000039, 000040, 000041 y 000042. La 000035 y la 000036 no están en `database/migrations` y no se ejecutaron. Tras las pruebas quedan 37 registradas y 0 pendientes.

## 3. Defecto encontrado: `db:wipe` no borra funciones

`migrate:fresh` (usado por `RefreshDatabase` y `DatabaseTruncation`) no podía repetirse en PostgreSQL: `db:wipe` elimina tablas, vistas y tipos, pero **no funciones**. Sobrevivía `notificaciones_internas_audiencia_coherente()` y la migración `2026_10_31_000032` (up, líneas 98-101) se negaba a continuar por su salvaguarda («la columna audiencia no existe pero ya hay objetos con los nombres de esta migración»), fijada por `test_10_columna_ausente_con_objetos_residuales` (función **y** trigger). La 000032 es posterior a las ejecuciones PostgreSQL anteriores, por eso no se había visto. Todas las pruebas con `RefreshDatabase`/`DatabaseTruncation` fallaban en el `setUp` sin llegar a ninguna aserción.

- Alcance: producción e instalaciones nuevas no se ven afectadas; sí cualquier `migrate:fresh` repetido en PostgreSQL (pruebas y desarrollo). Precedente: la 000030 ya tolera el residuo de su función con `CREATE OR REPLACE`.
- **Mitigación aplicada (solo código de pruebas):** `tests/TestCase.php` sobrescribe `setUpTraits()`; bajo el perfil PostgreSQL, solo si la clase usa esos traits y antes del único `migrate:fresh` del proceso (`RefreshDatabaseState::$migrated` falso), ejecuta `DROP FUNCTION IF EXISTS … CASCADE` de `notificaciones_internas_audiencia_coherente` y `reclamacion_historial_inmutable` tras `assertIsolatedTestDatabase()`. Los ganchos `beforeRefreshingDatabase`/`beforeTruncatingDatabase` no sirven: el método del trait prevalece sobre el de la clase base.
- **Decisión pendiente (no necesaria para validar):** hacer la 000032 tolerante al residuo «solo función» (opción B) solo si se usa `migrate:fresh` en PostgreSQL de desarrollo; implicaría ajustar `test_10` y reprobar en SQLite y PostgreSQL. Hoy queda la opción A: mitigado y documentado.

## 4. Correcciones de pruebas (no de producto)

| Hallazgo | Causa | Corrección |
| --- | --- | --- |
| Rechazos «preflight refused» en las últimas pruebas de una clase | `max_connections=30` (27 útiles): cada prueba dejaba su PDO abierto hasta el final del proceso | `RequiresIsolatedPostgres::tearDown()` suelta la conexión principal tras `parent::tearDown()` y llama a `gc_collect_cycles()` |
| `RestrictOnDeleteMatriculasPostgresTest` esperaba `23503` | PostgreSQL 18.6 informa `ON DELETE RESTRICT` como `restrict_violation` | Expectativa exacta `23001` (igual que ya hacía `ReclamacionesDdlPostgresTest`) |
| Prueba c9: «isolated runtime required: mail.default» | La segunda sesión se usaba con `mail.default=smtp` dentro de `conRecepcion()`; la guarda exige `array` | La prueba restaura `array` solo durante ese `INSERT`; la guarda no se tocó |

## 5. Resultados

**Pruebas existentes** (`tests/PostgresIntegrity`, una clase por proceso): `AprobacionesConcurrencia` 2 (15 aserciones), `Concurrency` 3 (24), `EstadosCheck` 3 (11), `MatriculaConstraints` 5 (10), `MatriculaOperacionesConcurrencia` 7 (59), `MatriculaRules` 6 (35), `NotificacionAudiencia` 29 (631), `ReclamacionesConcurrencia` 16 (114), `ReclamacionesDdl` 24 (157), `RestrictOnDeleteMatriculas` 2 (7); `ReclamacionesReconstruccionD9` 1 omitida por diseño. Cero fallos y cero errores.

**Pruebas nuevas** (`MatrizFase52PostgresTest`, 14 pruebas, 91 aserciones): idempotencia de creación y seguimiento de cotizaciones (bloqueo `55P03` y después `23505` con el nombre de la restricción; otro ámbito sí se admite); historial 1-2-3 sin repetidas ni huecos y eventos sin clave que no chocan; DNI, código de curso y código de servicio únicos bajo concurrencia; límites numéricos (máximos válidos pasan y un paso más da `22003`); CHECK de la 000041 (rechazo y restricción validada); `RESTRICT` (`23001`) y `SET NULL` en servicios y cotizaciones, y `23503` para referencia inexistente; rollback forzado sin filas ni bloqueos; migraciones registradas e índices de la 000037/000038.

**EXPLAIN** (`ExplainListadosPostgresTest`, 5 000 estudiantes × 20 cursos = 100 000 matrículas sintéticas, `ANALYZE`, TRUNCATE al terminar):

| Consulta | Plan | Tiempo |
| --- | --- | --- |
| Listado por defecto (fecha desc, id desc) | Index Scan Backward `matriculas_fecha_matricula_index` + Incremental Sort | 0,9 ms |
| Filtro por estado / por curso | mismo índice | 0,2 ms / 0,2 ms |
| Papelera | mismo índice | 0,4 ms |
| Matrículas de un estudiante | `matriculas_estudiante_id_curso_id_index` | 0,06 ms |
| Búsqueda por DNI/curso (`EXISTS` con `LIKE '%x%'`) | índice de fecha; `Seq Scan` en `estudiantes` (5 000) y `cursos` | 39 ms |
| Página profunda (offset 50 000) | mismo índice | 34 ms |
| `count(*)` del total | Seq Scan (normal en PostgreSQL) | 13 ms |

Sin `Seq Scan` en la ruta paginada. Las observaciones son orientativas (datos uniformes): con datos sesgados un curso poco frecuente podría degradar el filtro por curso (índice posible `(curso_id, fecha_matricula)`); la búsqueda `LIKE '%x%'` recorre `estudiantes` (con muchas más filas pediría trigram); el offset profundo crece de forma lineal. No se aplicó ningún índice.

**Regresión SQLite:** suite completa 3 912 pasan, 1 omitida (23 721 aserciones), sin cambios. Pint: los archivos nuevos pasan; los tres antiguos modificados siguen marcando `line_ending` y espaciado de `!` (ruido previo del repositorio, no reformateado).

**Limpieza:** recuentos exactos 0 en estudiantes, cursos, matrículas, cotizaciones, historial, servicios, aprobaciones, usuarios, auditoría y reclamaciones.

## 6. Paso 3 (12/11/2026, autorizado): matriz a nivel servicio / HTTP

`PanelConcurrenciaPostgresTest` (5 pruebas, 47 aserciones; `DatabaseTruncation`, mismo protocolo: la sesión B toma el bloqueo, la petición real espera con `55P03` y no deja rastro, B confirma y se repite):

- ETag de servicios por `PUT /api/panel/v1/servicios/servicios/{id}`: dos escrituras con la misma revisión dan 200 y 412 (sin segunda auditoría ni segundo avance de revisión); una escritura bloqueada por B espera, y tras el cambio de B la revisión obsoleta da 412 y la vigente 200.
- Publicación de curso: publicar, retiro bloqueado por B (sin auditoría), retiro posterior 200 y publicar con la revisión previa 412; una sola ficha por curso y una auditoría por operación efectiva (`curso_publicado`, `curso_despublicado`).
- Creación de cotización con la misma clave en carrera real: la lectura «¿existe esta clave?» no encuentra nada, otra sesión confirma la clave, el `INSERT` termina en `23505` y `GestionCotizaciones::crear` reconcilia con 200 devolviendo la ganadora, sin tercer expediente ni segunda auditoría de creación.
- Imágenes con fallo forzado a mitad de transacción: el `CHECK` de `orden` (000033) rechaza la tarjeta después de guardar el archivo; el archivo recién subido se borra, no hay registro, la revisión no avanza y no hay auditoría; con datos válidos, archivo y registro nacen juntos.
- La auditoría se comprueba en cada caso (una fila por operación efectiva, ninguna por intento bloqueado o rechazado).

Carpeta completa `tests/PostgresIntegrity` en un solo proceso: **118 pruebas, 1 181 aserciones, 1 omitida (D9, por diseño), 0 fallos, 4 min 24 s**. Recuentos 0 en todas las tablas de datos al terminar; clúster 55433 cerrado (`shut down`, sin listener, procesos ni `postmaster.pid`); consentimiento vacío; 5432 y 55432 intactos.

## 6c. Paso 4 (12/11/2026, autorizado): suite «PostgreSQL isolated» por lotes

Misma base (`horus_l2_test`, clúster 55433), consentimiento solo como variable de proceso, un proceso por lote con `--filter` (el script no admite `--testsuite`; el filtro usa `.` como comodín de la barra invertida). Sin `--stop-on-*`: se quería la lista completa de fallos.

**Defecto de arnés (no de producto) hallado y corregido en código de pruebas:** cinco clases usan `DatabaseMigrations`, que ejecuta `migrate:fresh` antes de **cada** prueba; la mitigación del §3 solo limpiaba las funciones antes del primer `migrate:fresh` del proceso, de modo que desde la segunda prueba la 000032 se negaba de nuevo (salvaguarda deliberada) y el esquema roto arrastraba al resto del lote (20 errores en cascada). `tests/TestCase.php::setUpTraits()` limpia ahora las funciones antes de cada prueba de una clase con `DatabaseMigrations` (mismo perfil y misma comprobación de identidad). La migración no se tocó; la opción B del §3 sigue pendiente.

| Lote | Pruebas | Pasan | No pasan |
| --- | --- | --- | --- |
| `Tests\Unit` | 1 192 | 1 192 | 0 |
| Feature A(a–d) | 279 | 275 | 4 |
| Feature A(e–z) | 329 | 305 | 24 |
| Feature B–C | 281 | 266 | 15 |
| Feature E–I | 818 | 818 | 0 |
| Feature L–O | 370 | 344 | 26 |
| Feature P (incluye `Panel`) | 172 | 171 | 1 |
| Feature R | 162 | 161 | 1 |
| Feature S–W | 255 | 197 | 58 |
| **Total** | **3 858** | **3 729** | **129** |

**Clasificación de las 129 (ninguna es defecto de producto):**

- **79 no ejecutables por restricción (migraciones suspendidas 000035/000022):** `ApiPublicaMatriculasTest` (23), `SolicitudMatriculaTest` (28), `Sprint14EmailVerificationTest` (28). `InstalaMigracionesSuspendidas` se niega por diseño a instalarlas fuera de SQLite en memoria.
- **49 expectativas propias de SQLite (no se modificaron):**
  - Filas incoherentes que PostgreSQL rechaza con sus `CHECK` (23514): `AdvisorCatalogoTest` 3, `CatalogoWebCursosTest` 2, `CursoPublicacionTest` 1, `Sprint17PublicacionTest` 1.
  - `DROP TABLE` sin `CASCADE` (2BP01): `CatalogoWebServiciosTest` 2, `ServicioTecnologicoTest` 1.
  - Exigen el controlador `sqlite` o una conexión `sqlite` fija: `CotizacionOrigenMigracionTest` 8, `CheckEstadosAcademicosMigracionTest` 1, `RestrictOnDeleteMatriculasMigracionTest` 1, `AdvisorColaRealTest` 1, `OperacionHorusColaTest` 1.
  - Triggers y migraciones parciales de SQLite: `NotificacionAudienciaMigracionTest` 14 (la cobertura PostgreSQL equivalente son las 29 de `NotificacionAudienciaPostgresTest`), `NotificacionAudienciaTest` 1 (PostgreSQL rechaza con `NOT NULL`).
  - `NotificacionAudienciaPuntoRetornoTest` 10 (la prueba crea una tabla en `setUp` y no la borra; en SQLite en memoria desaparece sola).
  - `DELETE` crudo que SQLite deja pasar y PostgreSQL rechaza con `RESTRICT` (23001): `ArchivadoConMatriculasVigentesTest` 1.
  - Ids fijos 1–5 con secuencias que PostgreSQL no reinicia: `BackendPerformanceTest` 1.
  - Exportación Excel dentro de la transacción de `RefreshDatabase` (el servicio exige nivel 0 y falla de forma controlada): `Panel\ReportesPanelTest` 1.
- **1 rechazo transitorio de infraestructura («preflight refused»)** en `CatalogoWebServiciosTest::test_errores_sql_solo_codigo_seguro`: no se reproduce al ejecutar la clase sola (solo fallan las 2 de `DROP TABLE`). Causa no determinada (sospecha: plazas de conexión del clúster de 30, que las clases Feature no liberan).

Un `DROP TABLE` o `CHECK` rechazado por PostgreSQL no es un defecto: es la integridad real que SQLite no aplica. Convertir estas pruebas a PostgreSQL (o marcarlas solo-SQLite) queda como decisión pendiente; no se debilitó ninguna.

**Cierre:** recuentos exactos 0 en todas las tablas salvo `migrations` (37) y `permissions` (20, sembradas por migraciones); sin tablas residuales; clúster 55433 cerrado (`apagado`, sin listener, procesos ni `postmaster.pid`); consentimiento vacío; 5432 y 55432 intactos.

## 6d. Pista 1 (12/11/2026, autorizada): suite aislada sin expectativas propias de SQLite

Solo cambió código de pruebas (`apps/api/tests/`); ni la aplicación ni las migraciones ni las salvaguardas. Mismo clúster 55433, mismo procedimiento que el Paso 4.

**Cambios:** `TestCase::tearDown()` libera la conexión en las clases Feature bajo el perfil PostgreSQL (los primeros 118 de 119 errores de Integridad y los 279 de la clase A salieron de una primera versión que llamaba a `DatabaseManager::disconnect()` con el contenedor ya destruido; se corrigió usando el objeto `Connection`); ayudantes `omitirEnPostgres()` (omisión con motivo escrito) y `aplicarOVerificarCheck()` (en PostgreSQL exige el rechazo `23514` de la fila incoherente); fechas coherentes en las pruebas con `CHECK` de vigencia; borrado de la tabla dependiente antes de `DROP TABLE`; conexión de cola por defecto en lugar de `sqlite` fijo; ids relativos en `BackendPerformanceTest`; limpieza de matrículas eliminadas lógicamente en `ArchivadoConMatriculasVigentesTest`; omisión con motivo de las pruebas cuyo objeto es propio de SQLite; `NotificacionAudienciaTest` acepta el rechazo `NOT NULL` en las tres comprobaciones de audiencia nula; `OperacionHorusTest` se omite en PostgreSQL porque simula `queue.default=database` y la salvaguarda exige `sync`. Cobertura PostgreSQL nueva: `MatrizFase52PostgresTest::test_origen_de_cotizaciones_de_la_000029`.

| Lote | Pruebas | Resultado |
| --- | --- | --- |
| Integridad (`tests/PostgresIntegrity`) | 119 | 118 pasan, 1 omitida (D9, por diseño) |
| `Tests\Unit` | 1 192 | 1 192 pasan |
| Feature A(a–d) | 279 | 279 pasan |
| Feature A(e–z) | 329 | 306 pasan, 23 no ejecutables (migraciones suspendidas) |
| Feature B–C | 281 | 273 pasan, 8 omitidas con motivo |
| Feature E–I | 818 | 818 pasan (2 rechazos transitorios de la sonda en `HorusCorreccionR01R05Test` no se reprodujeron al reejecutar la clase) |
| Feature L–O | 370 | 337 pasan, 33 omitidas con motivo (tras reejecutar `OperacionHorusTest` y `NotificacionAudienciaTest`) |
| Feature P | 172 | 171 pasan, 1 omitida |
| Feature R | 162 | 161 pasan, 1 omitida |
| Feature S–W | 255 | 199 pasan, 56 no ejecutables (migraciones suspendidas) |
| **Suite aislada** | **3 858** | **3 736 pasan, 43 omitidas con motivo, 79 no ejecutables (migraciones suspendidas); 0 fallos, 0 defectos de producto** |

Límites: los lotes L–O y E–I se completaron reejecutando solo las clases afectadas (392 pruebas: 384 pasan y 8 omitidas), no una nueva pasada de toda la suite tras la última corrección. Las 79 siguen sin ejecutarse hasta decidir qué hacer con 000022/000035/000036. El rechazo transitorio de la sonda se observó otra vez (2 casos en una clase con cientos de datasets); no se determinó la causa. La compatibilidad PostgreSQL sigue sin declararse total.

**Nota (13/11/2026):** el propietario eliminó la migración `000022`; con ella se retiró `Sprint14EmailVerificationTest` (28 de las 79 no ejecutables). Las cifras de arriba son la medición del 12/11/2026; hoy quedan 51 no ejecutables (`ApiPublicaMatriculasTest` 23 y `SolicitudMatriculaTest` 28, por `000035`/`000036`) y la suite aislada tiene 3 830 pruebas.

**Cierre:** recuentos exactos 0 salvo `migrations` (37) y `permissions` (20); clúster 55433 detenido (`apagado`, mismo identificador, sin listener ni `postmaster.pid`); consentimiento solo como variable de proceso; 5432 y 55432 intactos.

## 6b. No ejecutado (la compatibilidad no es total)

- Suite «PostgreSQL isolated»: ejecutada en el Paso 4 (§6c); 79 pruebas dependen de migraciones suspendidas y no son ejecutables.
- Recepción pública de cotizaciones (`/api/public/v1/cotizaciones`, marca firmada y catálogo publicado): su reconciliación de `23505` usa el mismo patrón que la creación administrativa, pero no se ejecutó por HTTP.
- Solicitudes públicas de matrícula: tabla de la 000035 suspendida.
- Concurrencia: dos sesiones reales de PostgreSQL con bloqueos reales en un proceso; no hay procesos paralelos del sistema operativo.

## 7. Archivos

Modificados: `apps/api/tests/TestCase.php`, `apps/api/tests/PostgresIntegrity/Concerns/RequiresIsolatedPostgres.php`, `…/ReclamacionesConcurrenciaPostgresTest.php`, `…/RestrictOnDeleteMatriculasPostgresTest.php`. Nuevos: `…/MatrizFase52PostgresTest.php`, `…/ExplainListadosPostgresTest.php`, `…/PanelConcurrenciaPostgresTest.php` (paso 3). Documentación: este archivo, `PROJECT_CONTEXT.md` y `CLAUDE.md` §5.
