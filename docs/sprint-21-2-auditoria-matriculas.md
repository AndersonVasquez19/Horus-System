# Sprint 21.2 — Auditoría de matrículas

> Estado: **implementado y validado; consolidado en tres commits locales** (sin push, sin despliegue). Evidencia: SQLite en memoria para la suite general y PostgreSQL aislado (`horus_l2_test`, 127.0.0.1:55433) para la concurrencia de las cinco operaciones (sección 13).
> Historial completo de decisiones y fases: `PROJECT_CONTEXT.md` (secciones «SPRINT 21.2 — FASE 0» a «FASE 5»).

## 1. Objetivo

Registrar en `audit_logs` toda escritura de matrículas (crear, actualizar, anular, eliminar lógicamente, restaurar) y la exportación CSV, de forma que cada evento:

- lleve el usuario que actuó (explícito, no inferido);
- describa qué cambió y su **efecto sobre los cupos**;
- no almacene datos personales innecesarios;
- sea atómico con la operación: si la auditoría falla, la operación no ocurre.

Además, sanear la auditoría de la exportación CSV (que guardaba el texto buscado) y proteger los registros históricos ya guardados.

## 2. Diagnóstico inicial

- Las matrículas no se auditaban. Solo existía `matriculas_csv_exported`, con `filters` copiados de la petición.
- Esa auditoría guardaba el texto libre de `busqueda` (DNI, nombres, códigos) y copiaba `estado` y `curso_id` sin validar.
- El enmascarado existente (`SensitiveData::redact`) opera por nombre de clave de secretos y no reconocía `busqueda`.
- `RespaldoController` (ZIP administrativo) leía `audit_logs.changes` sin pasar por el modelo.
- Error latente descubierto en la Fase 2: `POST /admin/matriculas` con identificadores en texto (como los envía un formulario) provocaba un `TypeError` (500) por `declare(strict_types=1)`; se normalizan a entero tras la validación.

## 3. Arquitectura de auditoría

- **Núcleo:** `App\Support\AuditoriaAcademica` (clase final). Listas permitidas por entidad:
  - Matrícula, con valor antes/después: `estudiante_id`, `curso_id`, `fecha_matricula`, `estado`, `monto_pagado`.
  - Matrícula, solo el nombre: `observaciones` (nunca su contenido).
  - Estudiante y curso (Sprint 21.1) comparten el mismo núcleo.
- API: `diferencias()`, `registrarCreacion()`, `registrarActualizacion()`, `registrarOperacionMatricula()`, `registrarOperacion()`, `registrarExportacionMatriculas()`, `efectoCupo()`, `actorDe()`.
- `diferencias()` normaliza valores equivalentes (`100` vs `100.00`, fecha con hora de la misma fecha) para no registrar cambios inexistentes.
- **Modelo:** `AuditLog::record()` persiste; `AuditLog::redactarCambios()` presenta (ver §9).
- Esquema de `changes` de entidad: `{esquema: 1, …}`; la exportación CSV usa una estructura propia plana (§8).

## 4. Eventos implementados

| Acción | Origen | Contenido de `changes` |
| --- | --- | --- |
| `matricula_creada` | `store` | `esquema`, `campos`, `valores`, `efecto_cupo` |
| `matricula_actualizada` | `update` | `esquema`, `campos`, `antes`, `despues`, `efecto_cupo` |
| `matricula_anulada` | `anular`, y `update` que pasa a `anulada` (un único evento, nunca dos) | igual que la actualización |
| `matricula_eliminada` | `destroy` | `esquema`, `operacion`, `efecto_cupo`, `curso_id`, `estado` |
| `matricula_restaurada` | `restaurar` | igual que la eliminación |
| `matriculas_csv_exported` | `exportarCsv` | `filename`, `estado`, `curso_id`, `eliminados`, `busqueda_aplicada` |

Todas llevan `model_type = Matricula`, `model_id` (salvo la exportación, `null`), `user_id` explícito e IP. Una actualización sin diferencias reales no genera evento. Las denegaciones (403), validaciones (422) y 404 no se auditan (D5).

## 5. Operaciones de matrícula

| Operación | Ruta | Permiso | Comportamiento auditado |
| --- | --- | --- | --- |
| Crear | `store` | `matriculas.crear` | vigente: `ocupa`; nacida anulada: `ninguno` |
| Actualizar | `update` | `matriculas.editar` (+ `matriculas.anular` si pasa a anulada) | `ninguno`, `traslada`, `ocupa` (reactivar) o `libera` (anular) |
| Anular | `anular` | `matriculas.anular` | `libera`; repetir no registra nada |
| Eliminar | `destroy` | `matriculas.eliminar` | `libera` si era vigente; `ninguno` si ya estaba anulada |
| Restaurar | `restaurar` | `matriculas.restaurar` | `ocupa` si es vigente; `ninguno` si es anulada |
| Exportar CSV | `exportarCsv` | `matriculas.exportar` | lista cerrada de §8 |

Los permisos, redirecciones, mensajes, validaciones, el orden de bloqueos (cursos por id → estudiantes por id → matrícula) y la lógica de cupos **no cambiaron**. El estado y el curso auditados salen de la **fila bloqueada**, no del binding de la ruta (caso del segundo operador obsoleto).

## 6. Efectos sobre los cupos

`efectoCupo(estadoAntes, cursoAntes, estadoDespues, cursoDespues)` es una función pura:

| Efecto | Significado | Delta de `cupos_disponibles` |
| --- | --- | --- |
| `ocupa` | no ocupaba cupo y pasa a ocuparlo | −1 en el curso |
| `libera` | ocupaba cupo y deja de ocuparlo | +1 en el curso |
| `traslada` | ocupa cupo en otro curso | +1 origen, −1 destino |
| `ninguno` | sin movimiento de cupo | 0 |

Ocupa cupo una matrícula no eliminada en `pendiente` o `pagada`. Un estado desconocido lanza `InvalidArgumentException`. Las pruebas comprueban la invariante: el efecto registrado coincide con el cambio real de `cupos_disponibles`.

## 7. Política de privacidad

- Lista cerrada: solo los campos permitidos; cualquier otro (DNI, nombres, correo, contraseñas, tokens, sesión) se descarta aunque llegue en la petición.
- `observaciones` solo aparece por nombre en `campos`.
- Eliminación y restauración registran únicamente `curso_id` y `estado` (sin `estudiante_id`, monto ni fecha).
- La exportación no registra el texto buscado (§8).
- Las pruebas buscan un marcador de observaciones, el DNI, nombres y correo en **todas las columnas** de `audit_logs`.

## 8. Exportación CSV

Se audita la lista cerrada `filename`, `estado`, `curso_id`, `eliminados`, `busqueda_aplicada`:

- `estado`: `pendiente|pagada|anulada` o `null` (cualquier otro valor se descarta).
- `curso_id`: entero o `null`.
- `eliminados` y `busqueda_aplicada`: booleanos reales (`busqueda_aplicada` es `true` solo si el texto recortado no está vacío; un array o espacios no cuentan).
- Actor explícito. Si la auditoría falla, no se entrega el archivo.
- El contenido del CSV es idéntico al anterior (comprobado byte a byte).

Desviación respecto a la Fase 0 (M15): en lugar de conservar `filters` sin `busqueda`, el registro nuevo no tiene `filters`; sus claves son planas.

## 9. Protección de registros históricos (D6, alternativa B)

- Los registros anteriores **no se modifican ni se eliminan**: no hay migración ni consulta de escritura. El texto original **sigue almacenado** en la base.
- `AuditLog::redactarCambios()` enmascara (`[REDACTADO]`) la clave `busqueda` en cualquier nivel y cualquier acción, además de los secretos. Valores `null` o vacíos no se enmascaran.
- Puntos que lo aplican: pantalla de auditoría (`redactedChanges()`), exportación JSON de auditoría (`redactedChanges()`), ZIP administrativo (`RespaldoController`, antes sin pasar por el modelo) y serialización del modelo (`attributesToArray()`).
- El panel de inicio no muestra `changes`.
- Quedan fuera: lectura cruda (`$log->changes`, SQL directo, copias de PostgreSQL).

## 10. Atomicidad y transacciones

- El registro es la **última escritura** dentro de `DB::transaction` de cada operación.
- Si `AuditLog::create` lanza, se revierten la matrícula, los cupos y `matricula_notifications`; no queda ninguna fila de auditoría. Probado con un hook `AuditLog::creating` que lanza, en las cinco operaciones.
- Las operaciones no generan eventos duplicados (probado, incluida la repetición de anular, eliminar y restaurar).
- `MatriculaNotificationObserver` solo inserta una intención en `created` dentro de la misma transacción; eliminar, restaurar y anular no crean avisos.
- Limitación: SQLite ignora `lockForUpdate()`; la serialización real no está medida (§17).

## 11. Matriz de pruebas M01–M22

| ID | Cobertura | Dónde |
| --- | --- | --- |
| M01 | Crear vigente (`pendiente`/`pagada`): evento, valores, `ocupa`, notificación | `AuditoriaMatriculasTest` |
| M02 | Crear nacida anulada: `ninguno` | `AuditoriaMatriculasTest` |
| M03 | Actualizar monto/fecha/estado: `antes`/`despues` exactos | `AuditoriaMatriculasTest` |
| M04 | Traslado de curso: `traslada`, cupos coherentes | `AuditoriaMatriculasTest` |
| M05 | Reactivar anulada: `ocupa` | `AuditoriaMatriculasTest` |
| M06 | `PUT` a anulada: un único `matricula_anulada` | `AuditoriaMatriculasTest` |
| M07 | `anular` por endpoint | `AuditoriaMatriculasTest` |
| M08 | Sin cambios reales (formatos equivalentes, repetidos): 0 eventos | `AuditoriaMatriculasTest`, `AuditoriaAcademicaTest` |
| M09 | Eliminar vigente/anulada | `AuditoriaMatriculasTest` |
| M10 | Restaurar vigente/anulada | `AuditoriaMatriculasTest` |
| M11 | Invariante efecto = delta real de cupos | `AuditoriaMatriculasTest` |
| M12 | Actor explícito (usuarios distintos) y `actorDe` | `AuditoriaMatriculasTest`, `AuditoriaAcademicaTest` |
| M13 | Privacidad en todas las columnas | `AuditoriaMatriculasTest`, `AuditoriaExportacionMatriculasTest` |
| M14 | Lista cerrada; efecto inválido lanza excepción | `AuditoriaMatriculasTest`, `AuditoriaAcademicaTest` |
| M15 | CSV: lista cerrada, `busqueda_aplicada`, filtros inválidos, actor | `AuditoriaExportacionMatriculasTest` |
| M16 | Rollback si falla `AuditLog` en las cinco operaciones y la exportación | `AuditoriaMatriculasTest`, `AuditoriaExportacionMatriculasTest` |
| M17 | Rollback con fallo posterior (pruebas previas intactas) | `MatriculaOperationsTest`, `BackendRobustnessTest` |
| M18 | 403 sin permiso, sin escrituras ni auditoría | `AuditoriaMatriculasTest`, `AuditoriaExportacionMatriculasTest` |
| M19 | 422 sin auditoría ni cupos movidos | `AuditoriaMatriculasTest` |
| M20 | Segundo operador obsoleto (fila bloqueada) | `AuditoriaMatriculasTest` |
| M21 | Regresión global (§13) | suite completa |
| M22 | Mutaciones de control (§12) | copia aislada |

Adicional (Fase 4): histórico enmascarado en modelo, pantalla, JSON, ZIP, serialización; valor original intacto en la base; otros módulos sin cambios.

## 12. Mutaciones y resultados (M22)

Método: copia aislada del proyecto fuera del repositorio (`vendor` propio, sin `.env` real, clave de aplicación desechable por variable de entorno, SQLite en memoria). Antes: la copia es idéntica al repositorio (SHA-256). Después de cada mutación se restaura y se verifica el hash. Se ejecutan `AuditoriaMatriculasTest`, `AuditoriaExportacionMatriculasTest` y `AuditoriaAcademicaTest` (línea base de la copia: 153 pasan).

**32 de 32 mutaciones detectadas**, todas restauradas, y el repositorio real quedó sin cambios.

| Grupo | Mutaciones (pruebas que fallan) |
| --- | --- |
| Registro de cada operación | store sin auditoría (6), update (12), anular (5), destroy (11), restaurar (11), exportación (6); `PUT` que anula auditado como actualización (1) |
| `efecto_cupo` | store fijo `ocupa` (1), update fijo `ninguno` (4), anular fijo `ninguno` (2), destroy fijo `libera` (2), restaurar fijo `ocupa` (4), traslado como `ninguno` (5), `ocupa`/`libera` intercambiados (23) |
| Actor | actor fijo en 1 en store (1), update (2), anular (1), destroy (1), restaurar (2), exportación (1); `actorDe` sin comprobar el usuario (7) |
| Datos sensibles | `observaciones` con valor (10), `estudiante_id` en eliminar/restaurar (6), exportación con texto de búsqueda (2), `busqueda_aplicada` como texto (8) |
| Enmascarado histórico | sin enmascarar en el modelo (6), serialización sin enmascarar (1), ZIP sin enmascarar (1), enmascarado de un solo nivel (6) |
| Atomicidad y duplicados | auditoría fallida tragada (10), evento duplicado (16), anular repetido auditado de nuevo (3) |

Hallazgos de control: (1) en la Fase 3 y la Fase 4 una prueba de actor era débil porque el administrador tenía id 1; se reforzó. (2) En esta fase, la mutación «`actorDe` sin comprobar» **no se detectaba** porque `actorDe` carecía de pruebas directas; se añadieron 9 pruebas unitarias (usuario ausente, id entero, id numérico en texto, ids no numéricos → 403) y pasó a detectarse.

## 13. Resultados de PHPUnit (M21)

| Verificación | Resultado |
| --- | --- |
| Tanda 1: auditoría de matrículas, exportación, núcleo, cursos, estudiantes, secretos/exportaciones, operaciones, autorización, permisos sensibles | 411 pasan, 2 807 aserciones (antes de añadir las 9 pruebas de `actorDe`) |
| Tanda 2: contexto y listado de matrículas, integridad, notificaciones (2 archivos), atomicidad Sprint 12, protección de administradores, Excel, filtros | 153 pasan, 1 omitida, 992 aserciones |
| **Suite completa** `php artisan test --compact` | **1 595 pasan, 1 omitida, 10 071 aserciones, 0 fallos** |

Línea base de la Fase 4: 1 586 pasan, 1 omitida, 10 062 aserciones. La diferencia (+9 pruebas, +9 aserciones) son las pruebas nuevas de `actorDe`.

### PostgreSQL aislado (perfil `scripts/pgsql-test.php`)

Destino único: `horus_l2_test`, 127.0.0.1:55433, usuario `horus_l2_test_runner`, clúster `horus_pgt_55433`, verificado con `probe` antes de cada ejecución. Las 29 migraciones constaban como `Ran` (`migrate:status`); no se ejecutó ninguna migración. La base es desechable y las pruebas la vacían con `DatabaseTruncation`.

| Grupo | Resultado |
| --- | --- |
| `MatriculaConstraintsPostgresTest` | OK, 5 pruebas, 7 aserciones |
| `MatriculaRulesPostgresTest` | OK, 6 pruebas, 32 aserciones |
| `ConcurrencyPostgresTest` | 2 de 3 OK (último cupo y alta duplicada); **falla** `test_two_concurrent_demotions_cannot_remove_every_admin` |
| `MatriculaOperacionesConcurrenciaPostgresTest` (nuevo) | **OK, 7 pruebas, 56 aserciones** |

- **Prueba nueva:** siete escenarios con una segunda sesión que toma el bloqueo del curso. En los seis con sesión B la petición real queda bloqueada con `55P03`; tras confirmar B decide con el estado confirmado. Resultado: sin cupo devuelto dos veces, sin eventos de auditoría duplicados, sin rastro de intentos revertidos y, ante un fallo de `AuditLog`, reversión de matrícula, cupo y notificación.
- **Fallo preexistente (ajeno a matrículas):** la prueba de degradación concurrente de administradores se escribió el 27/10/2026 y quedó obsoleta con el Sprint 21.1: un coordinador que modifica a un administrador recibe 403 por la regla R1 (`AdminProtection::assertObjetivoEnAlcance`), no el 422 que espera. Diagnóstico por lectura de código; se trata en una tarea independiente y no se modificó.
- **No cubierto:** deadlock real por orden de bloqueo (`40P01`); la mutación de control sin `lockForUpdate()` queda pospuesta.

## 14. Larastan y Pint

- **Larastan nivel 6:** `[OK] No errors`.
- **Pint sobre los archivos propios** (modelo, controlador de matrículas, núcleo, las tres pruebas del sprint): `passed`.
- **Pint en `RespaldoController.php` y `SecretsAndExportsTest.php`:** fallan en la copia de trabajo, pero **no por regresión del sprint**. Con el preset por defecto (el repositorio no tiene `pint.json`): la versión de HEAD y la actual pasan en LF; ambas fallan, con los mismos fixers, al convertirlas a CRLF. La causa es `core.autocrlf=true`, que deja la copia de trabajo en CRLF. No se reformatearon.
- **Pint global:** 88 archivos fallan; 87 incluyen el fixer `line_ending` (CRLF) y 1 es un script ajeno (`chatbot-python/scripts/php_compare/php_matcher.php`). Ninguno se modificó.
- **`git diff --check`:** sin errores (solo la advertencia LF/CRLF conocida).

## 15. Archivos modificados y creados

| Archivo | Cambio |
| --- | --- |
| `app/Support/AuditoriaAcademica.php` | núcleo: efectos, operaciones de matrícula, exportación, `actorDe` |
| `app/Http/Controllers/Admin/MatriculaController.php` | auditoría en `store`, `update`, `anular`, `destroy`, `restaurar`, `exportarCsv`; identificadores enteros |
| `app/Models/AuditLog.php` | `redactarCambios()` y serialización enmascarada |
| `app/Http/Controllers/Admin/RespaldoController.php` | usa `AuditLog::redactarCambios` (1 línea) |
| `tests/Unit/AuditoriaAcademicaTest.php` | pruebas del núcleo (67) |
| `tests/Feature/AuditoriaMatriculasTest.php` (nuevo) | 67 pruebas de integración |
| `tests/Feature/AuditoriaExportacionMatriculasTest.php` (nuevo) | 19 pruebas de exportación e histórico |
| `tests/Feature/SecretsAndExportsTest.php` | una aserción actualizada al formato nuevo |
| `tests/PostgresIntegrity/MatriculaOperacionesConcurrenciaPostgresTest.php` (nuevo) | 7 escenarios de concurrencia en PostgreSQL aislado |
| `docs/sprint-21-2-auditoria-matriculas.md` (nuevo) | este documento |
| `PROJECT_CONTEXT.md` | secciones de las fases 2 a 5 |

Sin migraciones, rutas, vistas, permisos, `.env` ni módulos ajenos.

## 16. SHA-256

| Archivo | SHA-256 |
| --- | --- |
| `app/Support/AuditoriaAcademica.php` | `910b36b03d839308e39b3ed98d7c8fdb9a988f9e521cdabd0e877543c1729bf1` |
| `app/Http/Controllers/Admin/MatriculaController.php` | `052a0e51e771e3a37a2745d1975156e03be10330d67548c34a2b97e8545bfb77` |
| `app/Models/AuditLog.php` | `bd0c8f339aae928461396652490c562e94559259410410cddcf39a4aefe49b5c` |
| `app/Http/Controllers/Admin/RespaldoController.php` | `1e04a219d4763749fc10c89ce01d3f2ea808faaba84fe1c1be36311462483c8c` |
| `tests/Unit/AuditoriaAcademicaTest.php` | `f22319c5131acaac76ac89c743b98afaecb40dc772da595687c8c09598782440` |
| `tests/Feature/AuditoriaMatriculasTest.php` | `2baefe12217d522d64aae1efa8466b622eaf499d059af4553a5eca690be7f97a` |
| `tests/Feature/AuditoriaExportacionMatriculasTest.php` | `4a3c9b2d1bef5116525a7555aa868db5f7f7db2aba2f59abfedc1326a4e85543` |
| `tests/Feature/SecretsAndExportsTest.php` | `e7b259a88375a8313d04116c10ccc3da98a91c6da0bc947ba6979af40b843f65` |
| `tests/PostgresIntegrity/MatriculaOperacionesConcurrenciaPostgresTest.php` | `ef68c4f15a14bea0d155d3722ff0dd2f044789ca4d7524d27a5f00d15fd9254d` |

El hash de este documento y el de `PROJECT_CONTEXT.md` constan en el informe de entrega y en la sección de la Fase 5 de `PROJECT_CONTEXT.md` (un archivo no puede contener su propio hash).

## 17. Riesgos y limitaciones

- **Concurrencia en PostgreSQL:** medida con una segunda sesión determinista (sección 13). No cubre deadlocks reales (`40P01`) ni carga con muchos operadores; la suite general (SQLite) sigue sin medir concurrencia.
- **Prueba obsoleta de administradores** (`ConcurrencyPostgresTest`): falla por el Sprint 21.1; pendiente de una tarea independiente.
- **Históricos almacenados:** el texto de búsqueda antiguo sigue en `audit_logs`; solo se enmascaran las salidas. Lectura cruda, SQL y copias de PostgreSQL lo muestran. Depurarlo exigiría una decisión aparte (descartada por D6-B).
- **Enmascarado por nombre de clave:** un texto sensible bajo otra clave no se detectaría; hoy ninguna otra auditoría usa `busqueda`.
- **Estructura nueva de la exportación:** los registros nuevos no tienen `filters`; los antiguos sí. No hay consumidores en el código.
- **Sin auditoría de denegaciones, validaciones ni 404** (D5).
- **Firmas de `destroy` y `restaurar`** ahora reciben `Request`; ninguna ruta ni prueba las invocaba directamente.
- **Comprobación manual pendiente:** conviene probar en el navegador la creación de matrículas (corrección de ids en texto) antes de publicar.
- **Pint** sigue fallando en 88 archivos de la copia de trabajo por CRLF/ajenos; no se tocaron.
- **Sin push:** los commits son locales; el desarrollo continúa y producción queda reservada para el final del proyecto.

## 18. Estado final del sprint

**Cerrado técnicamente** respecto a los criterios de aceptación de la Fase 5: pruebas globales verdes, 32/32 mutaciones detectadas, Larastan nivel 6 limpio, Pint verificado e incidencias explicadas, `git diff --check` limpio, documentación y SHA-256 completos, dos stashes intactos y sin staging, commits ni push.

**Consolidado localmente, no desplegado:** tres commits en la rama `anderson`, sin push. La concurrencia de las cinco operaciones quedó validada en PostgreSQL aislado; la carga real y los deadlocks siguen sin medirse.
