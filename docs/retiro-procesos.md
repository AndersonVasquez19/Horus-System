# Retirada de la sección Procesos

6 de noviembre de 2026, America/Lima. El propietario solicita retirar la sección Procesos del panel administrativo. La autorización comprende el menú y los indicadores administrativos relacionados; no incluye eliminar registros históricos ni ejecutar cambios en bases reales.

## Comportamiento

- Se retiran el enlace de navegación, la tarjeta de procesos pendientes y la consulta de su contador en el inicio.
- Se eliminan las rutas de `/admin/procesos`, su controlador y sus tres vistas. Las URL anteriores responden 404, incluso para cuentas con permisos históricos.
- Los permisos `procesos.ver` y `procesos.gestionar` dejan de formar parte de la matriz inicial y de los selectores de Roles. Los permisos y concesiones ya existentes no se eliminan al consultar el panel. No se ejecuta el seeder sobre bases existentes.
- Las notificaciones históricas siguen visibles según su audiencia y pueden marcarse como leídas, pero no ofrecen enlaces a Procesos. Sus datos no se reescriben.
- El seeder de ejemplo conserva su nombre histórico `ProcesosInternosSeeder`, pero solo genera avisos para módulos vigentes; ya no crea procesos.

## Conservación

Se mantienen el modelo `ProcesoInterno`, la migración histórica y la tabla `procesos_internos`. La exportación administrativa de Respaldo sigue incluyendo estos datos. No hay migración de eliminación, purga ni intervención en PostgreSQL.

Las aprobaciones y el resto de módulos conservan sus flujos. No se modifican `.env`, configuración pública, correo ni proveedores de IA. `PROJECT_CONTEXT.md` tenía cambios previos del propietario y se conserva sin editar; sus referencias al módulo deben leerse como antecedentes frente a esta retirada.

## Archivos principales

- `routes/web.php`: retirada de las rutas del recurso.
- `resources/views/admin/partials/sidebar.blade.php` y `resources/views/admin/dashboard.blade.php`: retirada del menú y del indicador; las dos tarjetas restantes comparten el espacio.
- `app/Http/Controllers/Admin/DashboardController.php`: retirada del contador.
- `app/Http/Controllers/Admin/RoleController.php` y `database/seeders/RolesAndPermissionsSeeder.php`: permisos retirados del catálogo visible y de la matriz inicial.
- `resources/views/admin/notificaciones/index.blade.php`: conserva avisos históricos sin enlaces a la sección retirada.
- `database/seeders/ProcesosInternosSeeder.php` y `DemoDataSeeder.php`: datos de ejemplo ajustados.
- Se eliminan `ProcesoController.php`, las tres vistas de `resources/views/admin/procesos/` y `ProcesoResponsableTest.php`, cuyas operaciones ya no existen.
- Se ajustan pruebas compartidas de autorización, filtros, formularios y avisos; se añade `RetiroProcesosTest.php`. README y CLAUDE reflejan la retirada.

## Validación

`RetiroProcesosTest` comprueba los accesos HTTP retirados, preservación de registros, inicio sin consultas a procesos, permisos históricos ocultos sin borrado, avisos antiguos sin enlaces rotos y datos de ejemplo sin nuevos procesos. Se actualizan las pruebas de autorización, filtros, formularios e idempotencia que dependían de la sección retirada.

| Comprobación | Resultado |
| --- | --- |
| Pruebas acotadas: retirada, autorización, inicio, filtros, formularios, notificaciones y exportación | 202 aprobadas, 1239 aserciones |
| Suite completa `php artisan test --compact` | 2988 aprobadas, 5 fallos, 1 riesgosa, 1 omitida; 14855 aserciones, 484,32 s |
| `npm test` | 37 aprobadas |
| `npm run build` | Aprobado |
| PHPStan, nivel 6 | Sin errores |
| Pint sobre los once archivos PHP propios | Aprobado |
| `git diff --check` | Sin errores; avisos LF/CRLF conocidos |

Se usa PHP 8.4.27 instalado en Laragon, con PATH ajustado exclusivamente para los comandos de validación. El PHP 8.2.12 predeterminado no satisface las dependencias y se rechaza antes de arrancar Laravel; no se cambia la configuración del equipo.

La suite completa no queda aprobada: faltan `tests/Fixtures/privacidad/desarrollo.txt`, `errores-compartibles.txt` y `forense-compartible.json`. Esto produce los cinco fallos en `FiltroPrivacidadCorpusTest`, `HorusPrivacidadEstabilizacionTest` y `HorusPrivacidadBloqueoCompletoTest`. La prueba riesgosa es `FiltroPrivacidadCorpusTest::test_casos_criticos_de_privacidad_se_bloquean_al_cien_por_cien`, que no ejecuta aserciones al estar vacío el corpus. Esos archivos no se eliminaron ni se modificaron en esta tarea. No se inventa un corpus ni se relajan las comprobaciones para obtener un resultado verde.

Las comprobaciones funcionales se ejecutan únicamente con SQLite `:memory:`, sesión/caché/correo `array` y cola `sync`. No acreditan comportamiento en PostgreSQL, correo real ni despliegue. Se preserva la omisión de la comprobación exclusiva de PostgreSQL; no se atribuye evidencia de concurrencia a SQLite.
