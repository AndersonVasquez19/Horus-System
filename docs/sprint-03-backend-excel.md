# Sprint 03: backend y exportaciones Excel

Fecha: 28 de octubre de 2026. Rama: `anderson`. Sin commit ni push.

## Problema y alcance

Al comenzar el sprint, el Excel ya leía matrículas con `lazy(500)` y relaciones
precargadas. Ese límite solo acotaba los modelos: PhpSpreadsheet 5.10.0 mantenía
las celdas del libro en RAM y después construía tablas de cadenas y XML completos.
Cambiar únicamente `get()` por lotes no resuelve ese consumo.

Se preservaron los cambios previos de filtros, vistas y PDF paginado. No se
modificaron credenciales, despliegue, PostgreSQL S7, horus_group ni los módulos
Docentes, Sesiones o Certificados. Ninguna conexión o migración PostgreSQL real.

## Implementación

- `MatriculasExcelExport` lee lotes de 500 con las relaciones ya precargadas.
  La paginación usa la pareja descendente `(fecha_matricula, id)` en lugar de
  OFFSET. Mantiene los filtros recibidos del controlador, incluidos eliminados.
- La lectura usa una transacción. En PostgreSQL se solicita `REPEATABLE READ,
  READ ONLY` antes de consultar. Si existe una transacción externa PostgreSQL,
  se exige aislamiento repeatable read o serializable. Se libera la transacción
  propia antes de construir/comprimir el ZIP; ante errores se revierte.
  Este comportamiento PostgreSQL tiene revisión estática, no prueba en un servidor real.
- `SpreadsheetCellCache` usa el punto de extensión PSR-16 de PhpSpreadsheet:
  serializa celdas en un solo `tmpfile()` por exportación y conserva un índice
  compacto en RAM. Agrupa escrituras y lecturas en buffers de 1 MiB para evitar
  una operación de disco por celda. No comparte la caché de Laravel ni aplica caducidad por defecto.
  Hereda las operaciones múltiples de la implementación de la propia librería.
  Comprueba escrituras parciales, posicionamiento y lecturas incompletas.
- Se restaura la configuración global de caché inmediatamente después de crear
  la hoja. Un `finally` desconecta las hojas y cierra el recurso temporal.
- Se mantienen XLSX, nombre de descarga, título, seis columnas, cabeceras,
  números y fechas como texto, así como `SafeSpreadsheet`: textos explícitos y
  `quotePrefix` para entradas que parecen fórmulas. No hay cálculo de fórmulas
  durante el guardado. El monto nulo conserva la conversión previa a cero.
- El escritor rechaza superar las 1 048 576 filas de Excel, incluida la cabecera,
  y verifica la consistencia ZIP y la presencia del libro y la hoja antes de entregar.
- El controlador verifica directorio y archivo no vacío y registra auditoría
  solo después de completar la escritura. Los fallos no entregan un adjunto ni
  una auditoría de éxito. Se conservan los permisos y la validación existente.
- `TemporaryExportFile` agrega eliminación al liberar la respuesta y al apagar
  PHP, además de `deleteFileAfterSend`. El helper compartido también protege
  los temporales PDF, sin cambiar su contenido ni paginación.

## Larastan

Los tres errores conocidos eran `booleanOr.alwaysTrue`, `instanceof.alwaysFalse`
y `deadCode.unreachable` en `ConcurrentTestSession`. El análisis infería únicamente
el binding ordinario `MemoryOnlyConnector`, aunque el perfil aislado reemplaza ese
binding y extiende la fábrica durante el arranque.

La resolución se realiza ahora con `get()` de PSR-11, que respeta esos bindings
dinámicos. Se resuelve la fábrica una vez y se comprueban fábrica, conector,
configuración e identidad igual que antes. No se añadieron supresiones, casts para
silenciar errores ni cambios al nivel de análisis. La nueva regresión rechaza una
fábrica sin protección antes de conectar; las pruebas del perfil utilizan dobles.

## Validación

- Pasada acotada inicial: 138 aprobadas, 521 aserciones. Tras añadir los buffers,
  pruebas de caché y Excel: 16 aprobadas, 106 aserciones.
- Suite completa final `php artisan test --compact`: 466 aprobadas, 2316 aserciones,
  213,48 segundos. SQLite `:memory:`; no evidencia de ejecución PostgreSQL.
- La prueba de integración compara los 1203 IDs en orden, con fechas repetidas
  y tres lotes, sin duplicados ni omisiones. Exige como máximo 12 SELECT de datos.
- Regresiones para fórmulas, tipos, filtros, permisos, fallos de consulta y
  escritura, ausencia de auditoría de éxito ante fallos, caché cerrada/truncada,
  caducidad y eliminación de temporales al abandonar/enviar respuestas.
- Larastan: sin errores en el alcance configurado (`app/Support`, `app/Services`).
- Pint global: falla por formato preexistente en archivos ajenos, incluidos
  finales CRLF. Pint de los diez archivos PHP del sprint: aprobado.
- `git diff --check`: aprobado. Sin cambios frontend del sprint; build no requerido.

## Medición reproducible

`tests/benchmarks/report-excel.php` no arranca Laravel ni conecta a una base.
Compara el escritor anterior con el nuevo usando generadores de datos sintéticos
iguales, en procesos PHP separados. El tiempo y el pico PHP se capturan antes de
volver a leer el XLSX. La verificación posterior recorre todas las filas y compara
SHA-256 de sus seis valores, orden y cantidad; comprueba además el tipo literal
de todos los nombres, incluidos nombres que comienzan por `=`.

Ejecutar secuencialmente, evitando otras cargas de prueba durante las muestras:

```powershell
php tests/benchmarks/report-excel.php before 10000
php tests/benchmarks/report-excel.php after 10000
php tests/benchmarks/report-excel.php before 25000
php tests/benchmarks/report-excel.php after 25000
```

Entorno local: Windows, PHP 8.4.26 ZTS x64 y PhpSpreadsheet 5.10.0. Son muestras
sintéticas del componente de generación; no miden consulta, HTTP, concurrencia,
memoria RSS del proceso ni un entorno de producción representativo. Los tiempos
dependen de E/S, cachés y carga del equipo; no constituyen un SLA.

Una muestra intermedia sin buffers tardó 114,224 segundos para 10 000 filas,
frente a 12,661 del control; se descartó esa implementación por el coste de las
operaciones pequeñas de disco. No se presenta esa muestra como una mejora.
Resultados de la implementación final con buffers, una muestra por caso,
ejecutadas secuencialmente sin otras validaciones del agente en paralelo:

| Filas | Pico PHP antes | Pico PHP después | Tiempo antes | Tiempo después |
| ---: | ---: | ---: | ---: | ---: |
| 10 000 | 78 MiB | 60 MiB | 27,080 s | 31,571 s |
| 25 000 | 146 MiB | 96 MiB | 21,171 s | 24,838 s |

Reducción observada del pico PHP: 23,1% y 34,2%, respectivamente. En estas muestras
el tiempo aumentó aproximadamente 16,6% y 17,3%. No se afirma mayor velocidad.
El control de 25 000 filas no resultó proporcional al de 10 000; no se aisló
el efecto de carga y cachés del equipo. Estas muestras aisladas no caracterizan
throughput estable ni tiempos de producción.

Ambos modos produjeron el mismo SHA-256 de datos por volumen:

- 10 000: `e8ac074fa6e421b7fe040be633f3f38332e88f28eaa9daee292ec19e75368e90`.
- 25 000: `e90c0ae21c26db67d3907b20d5406d4375979bdda857447de98b1bd18c729196`.

Tamaños finales: 314 730 bytes y 791 666 bytes, respectivamente, en ambos modos
de esta última ejecución. No se exige identidad binaria del ZIP entre ejecuciones
porque las propiedades del libro pueden incorporar la hora de creación.

## Límites y siguiente tarea

La memoria sigue siendo O(N): tanto el índice de celdas como las cadenas y partes
XML del escritor crecen con el reporte. El temporal de celdas también crece en
disco. No hay garantía de exportar un millón de filas bajo cualquier memory_limit,
timeout o cuota de disco. Un cierre forzado del proceso/sistema puede impedir los
destructores y callbacks de apagado; no se atribuye a estas pruebas esa garantía.

La extensión PSR-16 es el mecanismo oficial para reducir memoria conservando
PhpSpreadsheet y sus estilos: [documentación de caché](https://phpspreadsheet.readthedocs.io/en/latest/topics/memory_saving/).
La semántica del snapshot procede de [PostgreSQL 18, SET TRANSACTION](https://www.postgresql.org/docs/18/sql-set-transaction.html).

Si el volumen requerido supera el margen de esta solución, la siguiente tarea
recomendada es una sustitución explícita del escritor por XLSX en streaming.
[OpenSpout](https://github.com/openspout/openspout) es un candidato para evaluar;
no se ha instalado ni se le atribuyen métricas propias de este proyecto.
Antes de sustituirlo deben probarse PHP 8.4, tipos, fechas, cabeceras, quotePrefix,
estilos y neutralización de fórmulas con las mismas regresiones. Mantener ruta,
permisos, filtros, MIME y extensión XLSX; no sustituir silenciosamente por CSV.
Esa migración no se inicia en este sprint.

## Archivos del sprint

- `app/Http/Controllers/Admin/ReportController.php`: integración Excel y ciclo de vida del temporal; conserva los cambios previos del controlador.
- `app/Services/MatriculasExcelExport.php`: lectura y escritura XLSX.
- `app/Support/SpreadsheetCellCache.php`: caché temporal con buffers.
- `app/Support/TemporaryExportFile.php`: limpieza de las descargas.
- `app/Support/Testing/ConcurrentTestSession.php`: resolución dinámica y controles existentes.
- `tests/Feature/ReportExcelScalabilityTest.php`: lotes, integridad, N+1 y fallos.
- `tests/Unit/SpreadsheetCellCacheTest.php`: recursos, buffers e integridad de caché.
- `tests/Unit/TemporaryExportFileTest.php`: descarga y abandono de la respuesta.
- `tests/Unit/ConcurrentTestSessionTest.php`: rechazo de fábrica sin protección.
- `tests/benchmarks/report-excel.php`: comparación reproducible sin base de datos.
- `docs/sprint-03-backend-excel.md`: evidencia y límites del sprint.

Los demás cambios mostrados por Git ya existían al empezar; no forman parte de
esta entrega. No se modificó el índice de Git.
