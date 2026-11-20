# Restricción inicial de acceso comercial: admin y permiso

## Decisión y estado inicial

29/10/2026. El propietario establece que únicamente usuarios con rol `admin` pueden acceder al módulo de cotizaciones. Además deben disponer del permiso específico de la operación. Cualquier ampliación futura requiere autorización del propietario; conceder permisos a otro rol no basta para habilitar acceso.

Rama inicial `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, índice vacío. Se conservan los cambios locales de los Sprints 19, 19.1 y 19.2, el informe PostgreSQL independiente y los dos stashes (`52e2f296e243cacf777f48d8148b0a72e68fc93c`, `d69d6caa1c7b2a18c9c2aa962bc74a473bbdcc82`). Antes de editar se registró un inventario SHA-256 de los archivos versionados y nuevos no ignorados.

Se leen AGENTS, CLAUDE, contexto e informes [19](sprint-19-cotizaciones.md) y [19.1](sprint-19-1-panel-cotizaciones.md), y se contrastan con rutas, controlador, requests, servicio y pruebas. Esos informes describen la política histórica de personal con permisos; este documento registra la restricción posterior que la sustituye. La propuesta documental [19.2](sprint-19-2-preparacion-recepcion-publica.md) tampoco autoriza ampliar acceso ni implementar recepción pública.

## Mecanismo y cambios

`User` emplea `Spatie\Permission\Traits\HasRoles`; roles y permisos son entidades independientes. El grupo `/admin` conserva autenticación de sesión y `role:admin|coordinador|secretaria` para otros módulos. `CotizacionController::middleware()` agrega `role:admin` a todas sus acciones, junto con los middleware de permisos existentes. No hay excepciones implícitas por ser administrador ni cambios globales al acceso de otros módulos.

| Método y ruta | Rol obligatorio | Permiso obligatorio |
| --- | --- | --- |
| GET `/admin/cotizaciones` (HTML/JSON) | admin | cotizaciones.ver |
| POST `/admin/cotizaciones` | admin | cotizaciones.crear |
| GET `/admin/cotizaciones/{uuid}` | admin | cotizaciones.ver |
| PUT `/admin/cotizaciones/{uuid}` | admin | cotizaciones.editar |
| PATCH `/admin/cotizaciones/{uuid}/estado` | admin | cotizaciones.gestionar |
| PATCH `/admin/cotizaciones/{uuid}/responsable` | admin | cotizaciones.gestionar |
| POST `/admin/cotizaciones/{uuid}/seguimientos` | admin | cotizaciones.gestionar |
| GET `/admin/cotizaciones/{uuid}/historial` | admin | cotizaciones.ver |

Los dos Form Requests exigen también rol admin y permiso. `GestionCotizaciones::autorizar()` relee el usuario con `fresh()` dentro de las transacciones y exige ambos: creación, reconciliación tras unicidad, edición, estado, responsable y seguimiento. No se confía en roles precargados en el objeto de sesión. Se mantiene el orden de revisión/If-Match, la idempotencia y la atomicidad de expediente, historia y auditoría.

Un responsable nuevo debe tener rol admin y `cotizaciones.ver`: se rechaza asignar coordinación/secretaría aunque tengan lectura. Las asignaciones históricas no se borran, ni conceden acceso; las existentes pueden desasignarse mediante la operación autorizada. Tampoco se eliminan concesiones de permisos, se modifican roles, seeder o migraciones, ni se conceden permisos en bases reales. Los cambios de permisos dentro de pruebas/fixture son exclusivamente sintéticos y efímeros.

El enlace del módulo en `resources/views/admin/partials/sidebar.blade.php` exige admin y lectura. No cambian textos, estilos o estructura de páginas públicas. Los formularios privados conservan sus permisos particulares. Un admin sin lectura puede tener permiso de creación o gestión y usar la operación correspondiente sin recibir acceso al detalle o a la navegación.

Se conserva el contrato HTTP: solicitudes JSON sin sesión reciben 401 y usuarios autenticados sin rol o permiso reciben 403. La navegación HTML sin sesión mantiene la redirección existente a login. No se amplían respuestas ni se cambia la negociación de contenido.

## Pruebas y límites

La matriz de ocho endpoints prueba admin sin permiso y con permiso, coordinador y secretaria con los cuatro permisos, usuario sin rol con todos los permisos e invitado JSON. Comprueba también que los rechazos no alteren historia/auditoría y que los permisos concedidos continúen presentes. Las pruebas de panel usan ahora administradores con permisos limitados para verificar las capacidades separadas; se añade comprobación de HTML y navegación para admin sin lectura y personal con todos los permisos.

La prueba de revocación precarga admin y luego lo revoca desde otra instancia manteniendo permisos directos. Invoca el servicio sin middleware para comprobar rechazo en alta nueva, replay de creación y las cuatro clases de cambios; conserva exactamente expediente, revisión, historia y auditoría. Las pruebas anteriores de revocación de permisos siguen vigentes. El fixture de navegador conserva el perfil lector como admin con solo lectura, y el perfil rechazado como coordinador con todos los permisos, sin cambiar sus guardas o destinos.

Primer pase: 54 pruebas aprobadas y un fallo por codificación de la cadena nueva de prueba, corregido sin alterar la interfaz. Larastan señaló una expresión nula redundante; se sustituyó por una variable local comprobada, sin supresiones o baseline. Pint normalizó finales de línea solo en archivos propios.

| Comprobación | Resultado |
| --- | --- |
| `php artisan test --compact --filter='CotizacionTest\|PanelCotizacionesTest'` | 55 aprobadas, 704 aserciones; 24,20 s |
| `php artisan test --compact`, SQLite `:memory:` | 1066 aprobadas, 7447 aserciones; 348,37 s |
| `php vendor/bin/phpstan analyse --no-progress` | Nivel 6 sin errores |
| Pint `--test` sobre los siete PHP propios | Aprobado |
| `npm test` | 36 aprobadas |
| `npm run build` | Aprobado; compilados locales ignorados |
| Smoke Edge del panel, fixture exclusiva `127.0.0.1:8775` | Aprobado: 390/768/1440, 29 respuestas, cero errores; lector admin y rechazo de coordinador con permisos |
| `git diff --check` | Aprobado; advertencias LF/CRLF conocidas en archivos previos |

El smoke usa únicamente SQLite en memoria reconstruida por petición: comprueba navegación/controles y rechazo HTTP, no persistencia entre peticiones ni concurrencia. No se atribuye a build una comprobación visual independiente.

Solo SQLite `:memory:`, sesión/caché/correo array, cola sync y bloqueo de HTTP externo. SQLite no valida contención o revocaciones concurrentes PostgreSQL. La reautorización fresca no serializa una revocación posterior al chequeo; continúa pendiente la aceptación PostgreSQL descrita en el Sprint 19. No se contacta PostgreSQL, ejecutan migraciones reales, activan catálogos/proveedores ni realizan commits/push.

## Inventario propio

- `app/Http/Controllers/Admin/CotizacionController.php`.
- `app/Http/Requests/GuardarCotizacionRequest.php` y `GestionarCotizacionRequest.php`.
- `app/Services/GestionCotizaciones.php`.
- `resources/views/admin/partials/sidebar.blade.php`, únicamente condición del enlace comercial.
- `tests/Feature/CotizacionTest.php` y `PanelCotizacionesTest.php`.
- `tests/Support/cotizaciones-panel-browser-router.php`.
- Este informe y `PROJECT_CONTEXT.md`.

Los informes anteriores se conservan como evidencia histórica. La recepción pública continúa sin implementar y las activaciones no cambian. No se modifican cursos, servicios, chatbot PHP/Python, credenciales o páginas públicas.

Comparación del inventario inicial de 630 archivos: 621 idénticos, nueve modificados de la lista propia y este informe nuevo; ninguno eliminado. El informe PostgreSQL, migraciones, rutas, seeder, Vite, cliente JS y páginas públicas conservan sus hashes iniciales. Índice vacío, rama/HEAD y ambos stashes preservados.
