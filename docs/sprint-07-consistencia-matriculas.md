# Sprint 07: consistencia de estados y operaciones de matrícula

28 de octubre de 2026. Trabajo local en `anderson`, sin commit, push ni despliegue. Revisión limitada a las escrituras de matrícula y sus contratos de relaciones, permisos y cupos. Se preservan los cambios de los sprints anteriores. Sin conexiones ni comandos PostgreSQL, S7, migraciones reales, cambios en `horus_group`, activación de Resend o correos reales. No se modifican Docentes, Sesiones ni Certificados.

## Fuentes y operaciones confirmadas

- `routes/web.php`: recurso `matriculas` y dos PATCH específicos, `anular` y `restaurar`. El grupo administrativo exige autenticación y rol `admin|coordinador|secretaria`.
- `MatriculaController::middleware()`: permisos por acción. Los FormRequests autorizan con `true`; la autorización efectiva está en el middleware y, para anular mediante edición, también dentro de la transacción.
- `StoreMatriculaRequest`, `UpdateMatriculaRequest`, `MatriculaController`: creación, edición, traslado mediante cambio de `curso_id`, reactivación mediante cambio de `anulada` a `pendiente|pagada`, anulación, eliminación lógica y restauración. No hay endpoint ni permiso propio para trasladar o reactivar.
- `Matricula`, `Curso`, `Estudiante`: borrado lógico y relaciones históricas. Eliminar un estudiante o curso no anula sus matrículas ni devuelve cupos. Los estados del curso y `deleted_at` son dimensiones independientes: `archivado` no equivale a borrado lógico.
- `RolesAndPermissionsSeeder`: administrador con todos los permisos; coordinador puede crear, editar y restaurar, pero no anular ni eliminar; secretaría puede crear y consultar. No se cambia esta matriz.
- Migración `2026_10_27_000013`: unicidad de matrícula vigente por estudiante/curso. Vigente significa `deleted_at IS NULL AND estado <> 'anulada'`. Los CHECK de rango de cupos son exclusivos de PostgreSQL; SQLite solo verifica el índice y las comprobaciones de aplicación.

## Matriz de elegibilidad

Notación: **V** = `pendiente|pagada`; **A** = `anulada`; **E** = estudiante; **C** = curso. Los efectos se expresan sobre cupos disponibles. Todas las operaciones requieren además acceso al grupo administrativo. «Conservar» significa que no se propone un cambio de negocio en este sprint.

| Operación | Inicial → resultante | Condición actual de E y C | Efecto sobre cupos | Permiso | Comportamiento actual y recomendación |
| --- | --- | --- | --- | --- | --- |
| Crear vigente | Sin fila → V | E y C activos y no eliminados | C −1; rechaza sin cupo o duplicado vigente | `matriculas.crear` | Validación y relectura transaccional. Conservar. |
| Crear anulada | Sin fila → A | E y C activos y no eliminados | 0; admite curso lleno e historial duplicado | `matriculas.crear` | No exige `anular`, porque no anula una fila vigente. Conservar; cualquier restricción adicional necesita decisión. |
| Editar sin cambiar curso/estado | V → V o A → A | E seleccionado y C destino no eliminados; admite E inactivo y C inactivo/archivado | 0 | `matriculas.editar` | Mantiene correcciones administrativas. Conservar. |
| Cambiar estado de pago | `pendiente` ↔ `pagada` | Mismas condiciones de edición | 0 | `matriculas.editar` | No existe entidad ni operación separada de pago. No exige monto positivo ni concilia monto con precio. Conservar sin inventar reglas financieras. |
| Trasladar vigente | V en C1 → V en C2 | E seleccionado y C2 no eliminados; admite inactivos/archivados; C1 puede estar eliminado | C1 +1, C2 −1; exige cupo y ausencia de duplicado en C2 | `matriculas.editar` | Todo dentro de una transacción. Conservar contabilidad; elegibilidad de destino pendiente de decisión. |
| Trasladar anulada | A en C1 → A en C2 | Condiciones de edición | 0 en ambos | `matriculas.editar` | Cambia relación histórica sin ocupar cupo. Conservar; restringirlo requiere decisión. |
| Trasladar y anular | V en C1 → A en C2 | Condiciones de edición | C1 +1; C2 no consume | `matriculas.editar` y `matriculas.anular` | El permiso adicional se decide con la fila releída bajo lock. Conservar. |
| Anular mediante edición | V → A, mismo curso | E seleccionado y C no eliminados; admite inactivos/archivados | C +1 | `matriculas.editar` y `matriculas.anular` | Conservar. Repetir A → A no libera otro cupo y solo requiere editar. |
| Anular por PATCH | V → A; A → A | No exige actividad ni ausencia de borrado lógico de E/C | C +1 solo desde V; repetición 0 | `matriculas.anular` | Permite cerrar matrículas históricas con relaciones eliminadas. Conservar; no igualar automáticamente con el formulario de edición. |
| Reactivar mediante edición | A → V, mismo curso | E y C no eliminados; admite E inactivo y C inactivo/archivado | C −1; exige cupo y ausencia de duplicado | `matriculas.editar` | No hay acción dedicada. Conservar hasta decidir elegibilidad. Repetir V → V no consume otro cupo. |
| Reactivar y trasladar | A en C1 → V en C2 | Condiciones de edición para E y C2 | C1 0, C2 −1; exige cupo y ausencia de duplicado | `matriculas.editar` | Conservar contabilidad; elegibilidad pendiente de decisión. |
| Eliminar lógicamente | V/A → misma fila con `deleted_at` | No comprueba actividad/borrado de E/C | C +1 desde V; 0 desde A | `matriculas.eliminar` | Conserva el estado; repetición HTTP devuelve 404. Conservar. |
| Restaurar vigente | V eliminada → V visible | No comprueba actividad ni borrado lógico de E/C | C −1; rechaza sin cupo o duplicado vigente | `matriculas.restaurar` | Puede restaurar con E/C eliminados. Es política pendiente, no se cambia. Repetición devuelve 404 sin consumir cupo. |
| Restaurar anulada | A eliminada → A visible | No comprueba actividad ni borrado lógico de E/C | 0; no exige cupo ni comprueba duplicado | `matriculas.restaurar` | No reactiva; solo recupera historial. Conservar como alternativa recomendada para historial, sujeta a decisión sobre relaciones eliminadas. |

La edición también permite cambiar `estudiante_id`. La unicidad se comprueba para el estudiante de destino; cambiar solo estudiante no cambia cupos si la matrícula sigue vigente en el mismo curso. No se añadió una función nueva de transferencia de estudiante.

## Inconsistencia reproducida y corrección

`UpdateMatriculaRequest::after()` calculaba la necesidad de cupo usando la matrícula del route binding. El controlador ya relee esa matrícula bajo lock y calcula la necesidad real dentro de la transacción.

Dos intercalaciones deterministas reprodujeron un 422 incorrecto:

1. Binding de una matrícula anulada; antes de validar ya fue reactivada y ocupó el último cupo. Cambiarla a pagada debe conservar su cupo, pero el request seguía interpretándolo como reactivación.
2. Binding de una matrícula en C1; antes de validar ya fue trasladada a C2 y ocupó el último cupo. Editarla en C2 no requiere otro cupo, pero el request todavía interpretaba un traslado.

Con fixtures válidas, la ejecución previa a corregir producción tuvo **18 casos aprobados y 2 fallidos**, ambos con «El curso no tiene cupos disponibles». La corrección elimina exclusivamente el cálculo anticipado de cupos en el request y su import de `Curso`. Conserva validación de campos, relaciones y duplicados. `MatriculaController::update()` sigue rechazando adquisiciones sin cupo dentro de la transacción, usando matrícula y cursos bloqueados; no se modifica ese controlador ni sus cambios previos.

Se conserva el 422 y el mensaje de falta de cupos para una adquisición real. Si el request detecta un duplicado, puede devolver ese error antes de llegar a comprobar cupos. No se cambia la regla ni se añade reintento.

No se reprodujeron pérdidas o duplicación de cupos ni otra elusión de permisos en las combinaciones verificadas. Esto no constituye una garantía global de concurrencia.

## Decisiones de negocio pendientes

| Decisión | Alternativas | Recomendación para aprobación del propietario |
| --- | --- | --- |
| Destino de traslado/reactivación con E inactivo o C inactivo/archivado | Mantener edición administrativa amplia; exigir activos solo cuando se adquiere cupo; bloquear toda edición con inactivos | Evaluar exigir activos al adquirir cupo, conservando correcciones sin nueva ocupación y operaciones de cierre. Definir aparte traslado con estudiante inactivo. No implementado. |
| Restauración vigente con E/C eliminados | Mantener actual; exigir relaciones no eliminadas; exigir además ambas activas | Evaluar impedir nueva ocupación con relaciones eliminadas y recuperar primero E/C. La exigencia adicional de actividad requiere decisión separada. No implementado. |
| Restauración de anuladas con relaciones eliminadas | Recuperar historial sin ocupar cupos; exigir recuperación previa de E/C | Permitir recuperación histórica sin reactivación puede ser útil; el propietario debe confirmar la política. No implementado. |
| Diferencia entre anulación por edición y PATCH con relaciones eliminadas | Conservar PATCH para cierre histórico; permitir también edición limitada para anular; bloquear ambas | Conservar la operación dedicada de cierre; no ampliar edición de relaciones eliminadas sin definición explícita. |
| Estado pagada, monto y precio | Mantener independencia actual; imponer conciliación; registrar pagos separados | Definir en un trabajo financiero propio; no deducir deuda, pago completo o devolución a partir de `estado`. |

## Pruebas y límites

Nuevo `tests/Feature/MatriculaOperationsTest.php`: **60 casos, 673 aserciones**, aprobados en 7,710 s.

- 18 combinaciones de estado inicial/final y cambio de curso, ejecutando dos veces cada operación y comprobando el contador contra las matrículas vigentes reales.
- Dos regresiones de binding obsoleto.
- Seis condiciones de relaciones para traslado con reactivación: activos, estudiante inactivo, curso inactivo, combinación estudiante inactivo/curso archivado y borrado lógico de cada relación.
- 18 restauraciones cruzando los tres estados y esas seis condiciones; preservación del estado, contador y bienvenida, y rechazo de repetición. Documentan comportamiento existente, no una política nueva aprobada.
- Cuatro excepciones provocadas en anulación, eliminación, restauración y traslado con reactivación: rollback de estado, relaciones, contadores y conservación de outbox.
- Permisos de coordinador y secretaría, traslado junto con anulación sin permiso, reactivación autorizada y restauración por coordinador.
- Duplicado y curso lleno en reactivación/restauración; cuatro conflictos introducidos después de validar en traslado/reactivación; anulación repetida con ambas relaciones eliminadas.

Las intercalaciones usan callbacks antes o después de validar en una sola conexión SQLite. No prueban dos conexiones, exclusión mutua, deadlocks ni PostgreSQL. Los nuevos tests comprueban explícitamente SQLite `:memory:`, deshabilitan el envío y bloquean HTTP no simulado. La suite de notificaciones previa usa sus simulaciones; no envía correos reales.

Validación final:

| Comprobación | Resultado |
| --- | --- |
| `php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml tests/Feature/MatriculaOperationsTest.php` | 60 pruebas, 673 aserciones; aprobadas, 7,710 s |
| `php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml` | 617 pruebas, 3491 aserciones; aprobadas, 61,934 s; incluye las 557 previas y los 60 casos nuevos |
| `php vendor/phpstan/phpstan/phpstan analyse --no-progress` | Sin errores; alcance configurado `app/Support` y `app/Services`, nivel 5; no constituye análisis completo del request |
| `php vendor/laravel/pint/builds/pint --test app/Http/Requests/UpdateMatriculaRequest.php tests/Feature/MatriculaOperationsTest.php` | Aprobado; solo los dos PHP propios |
| `git diff --check` | Aprobado; advertencias de futura conversión LF/CRLF, sin errores de espacios |
| Build frontend | No corresponde: sin cambios de Blade, CSS o JavaScript |

Los procesos de pruebas fijaron explícitamente `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL` vacío y `MATRICULA_MAIL_ENABLED=false` antes de invocar PHPUnit con `phpunit.xml`. No existía configuración cacheada. No se leyeron archivos `.env` reales con las herramientas ni se ejecutó el perfil PostgreSQL. Las migraciones de `RefreshDatabase` se ejecutaron exclusivamente en SQLite desechable en memoria, como parte de la suite habitual. El arranque normal de Laravel puede cargar su entorno local; esto no se presenta como una inspección de secretos.

## Inventario exclusivo

1. `app/Http/Requests/UpdateMatriculaRequest.php`: elimina comprobación anticipada basada en binding obsoleto.
2. `tests/Feature/MatriculaOperationsTest.php`: nuevo.
3. `docs/sprint-07-consistencia-matriculas.md`: nuevo.

Siguiente tarea recomendada: decidir la elegibilidad al adquirir cupo mediante traslado, reactivación y restauración. Después implementar únicamente la alternativa aprobada con esta matriz como contrato. La validación concurrente real necesita autorización independiente del perfil PostgreSQL; S7 no forma parte de este sprint. El orden actual de locks sigue siendo un riesgo por verificar: creación toma curso y estudiante; edición toma matrícula, cursos ordenados por id y estudiante; anulación/eliminación/restauración toman matrícula y, cuando corresponde, curso. No se afirma ausencia de deadlocks ni se reestructura aquí ese protocolo sin evidencia concurrente.
