# Sprint 08: reglas de negocio de matrículas

28 de octubre de 2026. Alcance exclusivo: escrituras administrativas de matrícula, pruebas SQLite en memoria y documentación. Sin commits, push, despliegue, cambios de infraestructura, credenciales, `.env.example`, stashes, Resend ni los módulos Docentes, Sesiones y Certificados. PostgreSQL S7 continúa suspendido.

## Contratos reales y reglas finales

Fuentes: `routes/web.php`, `MatriculaController`, `StoreMatriculaRequest`, `UpdateMatriculaRequest`, los tres modelos con SoftDeletes, `RolesAndPermissionsSeeder`, la restricción `matriculas_vigente_unica` y la matriz documentada en el Sprint 07.

Una matrícula ocupa cupo si no está eliminada y su estado es `pendiente` o `pagada`. No existe un endpoint API de matrículas: traslado y reactivación siguen siendo variantes del PUT administrativo. No se agregan rutas, estados ni permisos. Las solicitudes JSON conservan errores de validación 422 y el formulario web conserva sus redirecciones y errores de sesión.

| Operación | Regla final | Cupos disponibles |
| --- | --- | --- |
| Crear vigente | Estudiante activo; curso activo (excluye inactivo/archivado); ambos sin eliminar; sin duplicado vigente; cupo positivo. Revalidación bajo bloqueo. | Destino −1 |
| Crear anulada | Se mantiene el contrato de relaciones activas no eliminadas, sin exigir cupo ni ausencia de matrícula vigente. Es creación histórica, no anulación de una vigente. | Sin cambio |
| Corregir datos sin nueva ocupación | Permite relaciones inactivas no eliminadas y cursos archivados; conserva validación de campos y duplicados. Incluye `pendiente` ↔ `pagada`, sin exigir cupo libre. | Sin cambio |
| Trasladar vigente | Estudiante activo y destino activo, no archivado ni eliminado; sin duplicado y con cupo. El origen puede estar inactivo, archivado o eliminado. | Origen +1 y destino −1, atómicos |
| Trasladar anulada o trasladar y anular | Se mantiene la corrección histórica con relaciones no eliminadas, aunque estén inactivas/archivadas. Si anula una vigente exige además `matriculas.anular`. | Destino no consume; origen +1 solo desde vigente |
| Anular | PATCH exige `matriculas.anular`; PUT exige además editar. PATCH permite relaciones eliminadas para cerrar historial. Repetir no libera dos veces. | +1 solo desde vigente |
| Reactivar `anulada` → `pendiente/pagada` | Estudiante y curso activos no eliminados; sin duplicado y con cupo. Repetir la solicitud ya vigente no consume nuevamente. | Destino −1 solo al adquirir ocupación |
| Eliminar con SoftDeletes | Conserva estado y relaciones históricas. Repetir devuelve 404. | +1 solo desde vigente |
| Restaurar vigente eliminada | Antes de restaurar exige estudiante y curso activos no eliminados, sin duplicado y cupo. Nunca restaura relaciones automáticamente. Rechazo sin cambios parciales. | −1 una vez |
| Restaurar anulada eliminada | Recupera historial conservando `anulada`, incluso con relaciones eliminadas, destino lleno o matrícula vigente duplicada. No restaura relaciones ni cambia el estado. Repetir devuelve 404. | Sin cambio |

## Diferencias respecto al Sprint 07

Antes, traslado con nueva ocupación, reactivación y restauración vigente admitían relaciones inactivas y cursos archivados; la restauración vigente también admitía relaciones eliminadas. Ahora esas adquisiciones exigen relaciones activas no eliminadas dentro de la transacción. Esta es la concreción de las decisiones de elegibilidad que el Sprint 07 dejó pendientes, autorizada por las reglas del Sprint 08.

Las reglas amplias «sin duplicados/cupo disponible en toda creación» y «activos en todo traslado» entrarían en conflicto con operaciones históricas documentadas. Se preservan las excepciones de creación anulada, traslado sin ocupación de destino y restauración anulada. No se impone silenciosamente una restricción sobre esas operaciones legítimas. Cambiar estas excepciones requiere una decisión funcional posterior.

También se conserva la posibilidad de cambiar solo `estudiante_id` en una matrícula vigente sin cambiar curso ni ocupación: exige relación no eliminada y ausencia de duplicado, pero no actividad. Restringir esa corrección requiere definir la diferencia entre corregir una relación histórica y reasignar una matrícula a otra persona. No se inventa una operación nueva.

## Transacciones y bloqueos

Todas las escrituras de matrícula mantienen transacción. El protocolo de bloqueo pasa a ser: cursos afectados ordenados por id, estudiantes afectados ordenados por id y finalmente matrícula. Creación bloquea curso y estudiante antes de insertar. Edición, anulación, eliminación y restauración comparten descubrimiento de relaciones y relectura de la matrícula bloqueada.

La lectura inicial de matrícula solo descubre qué relaciones bloquear. Si curso o estudiante cambian antes de obtener el bloqueo de matrícula, se devuelve validación y la transacción revierte, sin escribir sobre relaciones que no fueron bloqueadas ni reintentar automáticamente. Estado y ocupación se calculan con la matrícula bloqueada, conservando las regresiones del binding obsoleto del Sprint 07. Las relecturas posteriores solo vuelven a consultar relaciones ya bloqueadas.

Los contadores solo se modifican después de comprobar permisos, relaciones, duplicado y cupo. Las excepciones posteriores al cambio de contadores revierten todo. Se mantiene el índice único vigente y los CHECK existentes; no se crean migraciones. No se añaden notificaciones ni se cambia el mecanismo de bienvenida existente.

## Pruebas y límites

`tests/Feature/MatriculaOperationsTest.php` amplía la matriz previa con elegibilidad por operación, relaciones activas/inactivas/archivadas/eliminadas, correcciones históricas sin cupos libres, cambios de elegibilidad después de validar, rollback de traslado de una vigente, traslado desde origen no disponible y restauración anulada con relaciones eliminadas, cupo agotado y duplicado vigente. Conserva pruebas de repetición, permisos, errores y comparación del contador contra el número real de matrículas vigentes.

La prueba de relaciones cambiadas durante el descubrimiento de bloqueos introduce una intercalación determinista en una sola conexión SQLite y verifica rechazo y rollback. No demuestra exclusión mutua, ausencia de deadlocks ni concurrencia real PostgreSQL. No se ejecuta el perfil PostgreSQL ni se conecta a `horus_group`.

Los procesos de validación fijan `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL` vacío, `MATRICULA_MAIL_ENABLED=false` y `MAIL_MAILER=array`. Las migraciones de RefreshDatabase solo se ejecutan en SQLite desechable. Los casos de operaciones bloquean HTTP no simulado y comprueban que no se envía nada.

| Comprobación | Resultado |
| --- | --- |
| PHPUnit con `phpunit.xml`, `tests/Feature/MatriculaOperationsTest.php --no-progress` | 110 pruebas y 1107 aserciones aprobadas, 13,185 s |
| PHPUnit con `phpunit.xml --no-progress`, suite completa | 667 pruebas y 3925 aserciones aprobadas, 80,333 s; incluye las 617 previas con expectativas de elegibilidad actualizadas y 50 casos adicionales |
| `php vendor/phpstan/phpstan/phpstan analyse --no-progress` | Sin errores en el alcance configurado: `app/Support` y `app/Services`, nivel 5. No equivale a analizar el controlador completo. |
| `php vendor/laravel/pint/builds/pint --test app/Http/Controllers/Admin/MatriculaController.php tests/Feature/MatriculaOperationsTest.php` | Aprobado para ambos archivos PHP modificados |
| `php vendor/laravel/pint/builds/pint --test` | Falla en archivos ajenos al sprint, principalmente por finales de línea y formato preexistente; no se modifican esos archivos |
| `git diff --check` | Aprobado; Git avisa sobre futura conversión LF/CRLF, sin errores de espacios |
| Build frontend | No corresponde: sin cambios de Blade, JavaScript ni CSS |

## Archivos y seguimiento

- `app/Http/Controllers/Admin/MatriculaController.php`: elegibilidad transaccional y protocolo de bloqueo.
- `tests/Feature/MatriculaOperationsTest.php`: nuevas regresiones y actualización de expectativas de elegibilidad.
- `docs/sprint-08-reglas-matriculas.md`: reglas, excepciones, evidencia y límites del sprint.

Pendiente funcional: decidir si las excepciones históricas deben restringirse y definir el cambio exclusivo de estudiante; hasta entonces se conserva su contrato. Próxima tarea funcional recomendada: definir y probar un contrato específico de corrección de estudiante en una matrícula existente, distinguiendo error administrativo y reasignación, antes de cambiar validaciones o permisos. La verificación concurrente real permanece como trabajo separado, sujeto a autorización PostgreSQL; no implica reanudar S7.
