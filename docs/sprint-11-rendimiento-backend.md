# Sprint 11: rendimiento y confiabilidad del backend

28 de octubre de 2026. Rama `anderson`. Revisión limitada a los siete flujos solicitados; cuatro problemas demostrados y corregidos. Se preservan los tres archivos pendientes del Sprint 10. Sin cambios de frontend, contenido público, plantillas, dependencias, permisos, estados, reglas de cupos, índices ni migraciones. No se ejecutaron operaciones sobre PostgreSQL S7, `horus_group`, bases reales, Resend u OpenAI reales; sin cambios de credenciales, commits, push ni despliegue.

## Revisión focalizada y evidencia

| Flujo | Evidencia y decisión |
| --- | --- |
| Listado de estudiantes | Dos consultas y 15 modelos tanto con 100 como con 1.000 estudiantes. No se observó N+1; filtros, paginación y controlador intactos |
| Listado de cursos | Dos consultas y 15 modelos en ambos volúmenes. No se observó N+1; se conserva su comportamiento |
| Matrículas y cupos | El listado ya precarga estudiante/curso, pero su selector recuperaba los 15 atributos de todos los cursos, incluidas descripciones no utilizadas. Problema 1: exceso de datos hidratados. Solo se proyectan `id`, `codigo`, `nombre`, `deleted_at` en ese selector; se conservan eliminados, orden, opciones y selección. No cambia el número de opciones ni se tocan consultas de elegibilidad/cupos |
| Reportes | Problema 2: el mismo filtro ejecutaba un conteo para paginar y una suma independiente. Ahora un agregado calcula cantidad e ingresos pagados y proporciona el total al paginador. Se conserva el lote de 50, filtros, orden, enlaces, 404 de lote inexistente, permisos y exportaciones |
| Dashboard | Problema 3: conteo mensual y suma pagada mensual repetían el intervalo de lectura. Se calculan juntos en una consulta; la serie de seis meses sigue separada. Se conserva que el conteo incluye anuladas no eliminadas y que el ingreso solo incluye pagadas |
| Chatbot | Problema 4: se hidrataban todas las intenciones y luego se descartaban las personalizadas inactivas. Se filtran en SQL: activas o incorporadas. Las incorporadas desactivadas siguen cargándose para respetar su desactivación. No se añade caché ni se cambia la versión de respuestas, contenido, presupuestos, catálogo o uso de OpenAI |
| Consultas/transacciones críticas | Se revisaron las lecturas de matrícula/cursos y el protocolo de bloqueo existente. No se eliminan relecturas protectoras ni se cambian transacciones, estados, permisos o contadores; SQLite no acredita concurrencia PostgreSQL |

Cuatro regresiones fallaron en el código anterior: dashboard tenía tres agregados de matrículas frente a dos esperados (incluida la serie mensual), reportes dos frente a uno, el selector incluía atributos innecesarios y el chatbot hidrataba 102 intenciones frente a dos pertinentes. Después de corregir, pasan con el mismo contrato funcional.

## Medición antes y después

Comando optativo, fuera de la suite habitual:

```console
php vendor/bin/phpunit --configuration=phpunit.xml tests/benchmarks/BackendQueriesBenchmarkTest.php
```

Se ejecutó el mismo archivo antes de cambiar producción y después, en procesos PHP separados, PHP 8.4.26, SQLite `:memory:` y datos sintéticos. Cada volumen tiene igual número de estudiantes, cursos, matrículas e intenciones personalizadas inactivas, más una intención personalizada activa. Cada descripción de curso tiene 9.216 bytes; cada respuesta inactiva tiene 1.152 bytes. Esta distribución hace visible el coste de cargar texto no utilizado y no representa una distribución de producción medida.

Los datos se insertan mediante SQL masivo para excluir observers y escrituras de cupos de la medición. Resend y catálogo dinámico deshabilitados, clave OpenAI nula y HTTP externo bloqueado. Las operaciones administrativas atraviesan ruta, middleware, controlador y render de las vistas existentes mediante el cliente de pruebas; el chatbot se mide invocando el servicio, con sesiones nuevas para evitar caché de duplicados. No se mide una petición por red ni navegador.

Dos calentamientos y cinco muestras por operación. Se registra mediana de tiempo, consultas SQL, modelos hidratados y aumento del pico de memoria PHP respecto del inicio de la operación (`memory_get_peak_usage(false) - memory_get_usage(false)`, tras reiniciar el pico). La memoria no es RSS, memoria total de SQLite ni memoria constante; incluye instrumentación y varía con el entorno. No se añaden umbrales de tiempo/memoria a la suite para evitar pruebas inestables.

| Operación | Filas por entidad | SQL antes → después | Mediana ms antes → después | Aumento de pico PHP MiB antes → después |
| --- | ---: | ---: | ---: | ---: |
| Estudiantes (control sin cambios) | 100 | 2 → 2 | 22,930 → 11,621 | 0,405 → 0,405 |
| Cursos (control sin cambios) | 100 | 2 → 2 | 25,881 → 12,633 | 0,606 → 0,606 |
| Matrículas | 100 | 5 → 5 | 89,798 → 22,247 | 2,271 → 1,045 |
| Reportes | 100 | 8 → 7 | 55,794 → 29,460 | 0,852 → 0,851 |
| Dashboard | 100 | 15 → 14 | 46,689 → 12,485 | 0,495 → 0,494 |
| Chatbot | 100 | 2 → 2 | 5,432 → 2,594 | 0,330 → 0,054 |
| Estudiantes (control sin cambios) | 1.000 | 2 → 2 | 20,373 → 12,322 | 0,424 → 0,424 |
| Cursos (control sin cambios) | 1.000 | 2 → 2 | 25,884 → 14,052 | 0,610 → 0,610 |
| Matrículas | 1.000 | 5 → 5 | 132,655 → 49,752 | 15,350 → 3,096 |
| Reportes | 1.000 | 8 → 7 | 89,741 → 65,882 | 2,712 → 2,711 |
| Dashboard | 1.000 | 15 → 14 | 30,700 → 25,940 | 0,495 → 0,494 |
| Chatbot | 1.000 | 2 → 2 | 25,300 → 3,040 | 2,836 → 0,054 |

**Interpretación:** también bajaron los tiempos de los controles sin cambios, por lo que no se atribuyen causalmente esas diferencias de tiempo a las correcciones ni se promete un porcentaje de aceleración. La evidencia más sólida es una consulta menos en cada resumen, la reducción de datos en el selector y la hidratación del chatbot de 101 → 1 y 1.001 → 1 modelos. El selector sigue hidratando todos los cursos (115 y 1.015 contando la relación del listado): reduce el peso, no elimina su crecimiento O(N).

Las huellas SHA-256 de los resultados seleccionados coinciden antes/después en las doce combinaciones operación/volumen. Incluyen IDs/total del listado, campos utilizados del selector, totales de reportes, indicadores/serie del dashboard y respuesta completa del servicio del chatbot. No comparan el HTML íntegro ni todos los atributos Eloquent; las pruebas de regresión complementan esta comprobación con valores y bordes funcionales.

## Pruebas y validación

`BackendPerformanceTest` añade cinco pruebas:

1. Dashboard con límites mensuales, pagadas/pendientes/anuladas, importes fraccionarios/nulos y borrado lógico; preserva totales y acota agregados.
2. Reporte con búsqueda, curso, estado, lote 2, cinco filas restantes, ingresos y enlaces; reutiliza un único agregado.
3. Dashboard/reportes vacíos mantienen cero y rechazan un lote inexistente.
4. Selector incluye cursos borrados y selección/filtros vigentes, sin recuperar texto no utilizado; mantiene 15 matrículas por página.
5. Chatbot excluye hidratación innecesaria y respeta desactivación/reactivación y cambios de respuesta inmediatos en la misma instancia, sin HTTP.

El benchmark añade dos escenarios optativos (100 y 1.000), 88 aserciones en cada ejecución completa; no forma parte del recuento de la suite habitual.

| Comprobación | Resultado |
| --- | --- |
| `php artisan test --filter=BackendPerformanceTest --compact` | 5 pruebas, 51 aserciones; aprobado |
| Específicas: `BackendPerformanceTest`, `AdminDashboardTest`, `AdminReportConsistencyTest`, `AsistenteVirtualTest`, `AdminListFilterInputTest` | 55 pruebas, 287 aserciones; aprobado (5,43 s) |
| Suite completa `php artisan test --compact` | 729 pruebas, 4.496 aserciones; aprobado (43,65 s), SQLite `:memory:` |
| `php vendor/bin/phpstan analyse --no-progress` | Aprobado, sin errores en `app/Support` y `app/Services`, con entorno SQLite de pruebas |
| Pint acotado a los seis archivos PHP propios | Aprobado |
| `git diff --check` | Aprobado; advertencias conocidas LF/CRLF |

No se ejecutan build ni smokes de navegador: no se modifica frontend. Los tests existentes de reportes confirman concordancia con PDF/XLSX, filtros, eliminados y permisos. La suite completa incluye las reglas transaccionales y de cupos existentes, pero no demuestra su exclusión mutua en PostgreSQL.

## Inventario propio

- `app/Http/Controllers/Admin/DashboardController.php`
- `app/Http/Controllers/Admin/ReportController.php`
- `app/Http/Controllers/Admin/MatriculaController.php` (solo consulta del selector en `index`)
- `app/Services/AsistenteVirtualService.php` (solo selección de intenciones)
- `tests/Feature/BackendPerformanceTest.php`
- `tests/benchmarks/BackendQueriesBenchmarkTest.php`
- `docs/sprint-11-rendimiento-backend.md`

Pendientes previos preservados, fuera del inventario de este sprint: `app/Services/MatriculaNotificationService.php`, `tests/Feature/MatriculaNotificationTest.php`, `docs/sprint-10-preparacion-resend.md`.

## Límites y siguiente mejora funcional

No se mide PostgreSQL real, planes de ejecución, bloqueos, latencia de red, carga concurrente, disponibilidad de proveedores ni despliegue. Menos viajes SQL no garantiza un mejor plan de agregación en PostgreSQL; no hay evidencia suficiente para crear índices. El agregado conjunto hace coherentes cantidad e ingresos entre sí en esa lectura, pero no crea un snapshot común con las lecturas posteriores del paginador.

Los selectores completos aún crecen con el catálogo. Próxima mejora funcional recomendada: selección de curso con búsqueda paginada en matrículas, con contrato de selección definido y autorización expresa para el frontend en otra tarea. No se implementa ahora ni se altera la interfaz existente. La exportación XLSX conserva la solución previa y sus límites de memoria/disk; no se reabre su implementación ni se promete memoria constante.
