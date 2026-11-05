# Sprint 06: robustez del backend

28 de octubre de 2026. Trabajo local en `anderson`, sin commit, push ni despliegue. Se preservan los cambios anteriores de chatbot, reportes, matrículas y Resend. No se ejecutaron comandos sobre PostgreSQL, S7 ni `horus_group`; no se inspeccionaron credenciales ni archivos `.env` reales con las herramientas. No se activó Resend ni se enviaron correos reales.

## Inspección y problemas corregidos

Se acotó la inspección a requests, modelos, controladores y pruebas de estudiantes, cursos y matrículas; rutas, autenticación web/API y protecciones de permisos administrativos. Se seleccionaron tres problemas, sin ampliar el sprint a otros módulos.

| Prioridad | Evidencia anterior | Corrección |
| --- | --- | --- |
| Alta: validación obsoleta antes de escribir matrícula | `StoreMatriculaRequest` comprobaba relaciones activas, pero `store()` solo bloqueaba el curso y verificaba cupos/duplicados. Tras validar podía cambiar el estado o eliminarse el estudiante; un curso inactivo también seguía admitiendo creación. `update()` admitía relaciones eliminadas después de validar. | La creación bloquea curso y estudiante y comprueba sus estados y borrado lógico dentro de la transacción, antes de consumir cupos. La edición bloquea el estudiante y comprueba el borrado lógico de ambas relaciones antes de modificar datos o contadores. Devuelve validación 422 en JSON o el error habitual del formulario. |
| Alta: anulación sin permiso específico | El coordinador tiene `matriculas.editar`, pero no `matriculas.anular`. `PUT matriculas/{id}` permitía pasar de pendiente/pagada a anulada, aunque la ruta específica de anulación devolvía 403. | La transición a anulada exige `matriculas.anular` también en `update()`, usando el estado releído bajo lock. Se mantienen el middleware de edición y la matriz existente de permisos. |
| Media: números fuera del rango SQL | Ambos requests de cursos solo establecían mínimos. Aceptaban precios superiores a `decimal(10,2)` y duración/cupos superiores al entero de PostgreSQL generado por la migración. SQLite permitía persistirlos. | Precio máximo `99999999.99`; duración y cupos máximos `2147483647`, en creación y edición. No se cambian columnas ni mínimos. |

La primera ejecución de regresión reprodujo 12 fallos en 17 casos antes de corregir producción; los restantes casos ya pasaban. El caso de curso eliminado durante creación ya impedía escribir mediante 404, pero ahora informa el mismo error de validación que las demás relaciones no disponibles. Los casos posteriores de estado obsoleto para permisos y rollback de traslado refuerzan las correcciones finales.

## Contratos preservados

- Estados `pendiente`, `pagada`, `anulada`; sin relación nueva entre pago y matrícula.
- Una matrícula vigente por estudiante y curso; cupos y unicidad existentes, sin nueva migración.
- Crear requiere estudiante y curso activos, incluso si se registra una matrícula anulada, como ya exigía el request.
- Editar mantiene su contrato existente: permite relaciones inactivas no eliminadas. No se equipara automáticamente edición, reactivación y restauración a creación. Cualquier cambio de elegibilidad de esos flujos requiere una decisión de negocio.
- Anular reiteradamente no devuelve otro cupo. El permiso adicional se exige al realizar la transición, no por editar una fila que ya permanece anulada.
- El aviso de bienvenida sigue transaccional y deshabilitado. El rollback de creación elimina matrícula y aviso y restituye el cupo; editar no crea otra bienvenida.
- Se conserva la preselección contextual preexistente en `MatriculaController::create()`. No se editaron otras partes de ese trabajo.

## Pruebas

`tests/Feature/BackendRobustnessTest.php` añade 20 casos con 171 aserciones:

- Cinco cambios de elegibilidad entre validación y creación: estudiante inactivo/eliminado, curso inactivo/archivado/eliminado.
- Borrado de estudiante o curso entre validación y edición.
- Coordinador sin permiso de anulación: rechaza ambas rutas, permite pasar a pagada y mantiene cupos ante rechazo.
- Administrador autorizado: anulación desde edición repetida sin liberar dos veces.
- Cambio de estado después del route binding: el permiso depende de la fila actual, no del modelo obsoleto.
- Precio excesivo, exponente excesivo, duración y cupos fuera de rango, en ambos requests; ausencia de persistencia parcial.
- Límites numéricos válidos, incluido precio cero y mínimos de duración/cupos.
- Duplicado y último cupo ocupados después de validar.
- Excepción al crear: rollback de matrícula, cupo y outbox; excepción al trasladar: rollback de ambos contadores y del curso de la matrícula.
- Edición administrativa con relaciones inactivas conservada.

La intercalación se simula con callbacks después de resolver/validar el FormRequest y antes del controlador. No usa dos procesos ni dos conexiones y **no demuestra concurrencia real ni exclusión mutua PostgreSQL**. Las pruebas comprueban explícitamente SQLite `:memory:`, bloquean HTTP no simulado y deshabilitan el envío de matrículas en la configuración de prueba.

## Validación

- Regresión final específica: **20 pruebas, 171 aserciones, aprobadas** (15,497 s).
- Selección ampliada previa a los tres últimos casos nuevos: **158 pruebas, 772 aserciones, aprobadas** (97,382 s); incluye matrículas, cursos, estudiantes, matriz de permisos y bienvenida de matrículas.
- Suite completa SQLite `:memory:`: **557 pruebas, 2818 aserciones, aprobadas** (129,042 s). Incluye los 20 casos nuevos; sin fallos en esta ejecución.
- Larastan: **sin errores**, nivel 5 en el alcance configurado `app/Support` y `app/Services`; no se presenta como análisis completo de controladores y requests.
- Pint: **aprobado**, exclusivamente sobre los cuatro archivos PHP propios.
- `git diff --check`: **aprobado**; Git advierte conversión futura LF/CRLF en archivos del checkout, sin errores de espacios.
- Build frontend: no corresponde; no se cambiaron Blade, CSS ni JavaScript.

## Inventario exclusivo de este sprint

1. `app/Http/Controllers/Admin/MatriculaController.php`: comprobaciones transaccionales y permiso de anulación en edición; conserva cambios previos.
2. `app/Http/Requests/StoreCursoRequest.php`: rangos numéricos SQL.
3. `app/Http/Requests/UpdateCursoRequest.php`: mismos rangos.
4. `tests/Feature/BackendRobustnessTest.php`: nuevo.
5. `docs/sprint-06-robustez-backend.md`: nuevo.

No se modificaron estudiantes, autenticación, roles, modelos, migraciones, salvaguardas PostgreSQL, Docentes, Sesiones ni Certificados. Su cobertura existente se ejecuta con la suite; esto no equivale a una auditoría exhaustiva de esos módulos.

## Riesgos y próximo trabajo

Los locks dependen del motor real. Sigue pendiente validar las intercalaciones y el orden de locks mediante dos conexiones PostgreSQL en el perfil aislado autorizado; S7 queda fuera de este sprint. No se asegura ausencia global de deadlocks ni ejecución exactamente una vez. Un fallo SQL todavía puede abortar una petición: se conserva el rollback y no se añaden reintentos indiscriminados ni capturas genéricas que oculten errores.

Recomendación funcional siguiente: acordar y probar una matriz explícita de elegibilidad para traslado, reactivación y restauración cuando estudiante o curso estén inactivos, archivados o eliminados. El contrato de edición actual permite inactivos; cambiarlo aquí podría impedir correcciones administrativas legítimas. La activación y entregabilidad de Resend siguen siendo un trabajo operativo separado y pendiente de autorización.
