# Sprint 19.1 — panel privado de solicitudes de cotización

## Estado inicial y alcance

29/10/2026. Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`; índice vacío y dos stashes preservados. El Sprint 19 estaba implementado sin commit: modelos, requests, gestión transaccional, migraciones, controlador, permisos, rutas, pruebas e informe; contexto modificado y documento PostgreSQL sin seguimiento. Se toma snapshot SHA-256 fuera del repositorio antes de editar.

Se leen AGENTS, CLAUDE, contexto e informe 19 y se contrastan controlador, rutas, requests, servicio, modelos y pruebas. Se inspeccionan listado de cursos y panel de servicios 18.1: layout, navegación, tabla desplazable, paginador, formularios, errores, conservación de borradores y fixture/CDP. La autorización específica del propietario permite únicamente la interfaz administrativa de cotizaciones; no autoriza cambios públicos, de dominio, migraciones o activaciones.

Archivos delimitados antes de implementar: controlador de cotizaciones (negociación HTML del listado), enlace lateral administrativo, dos vistas exclusivas, cliente JS y entrada Vite, pruebas PHP/frontend, fixture/smoke propios, este informe y actualización acotada del contexto. Se aplica `codex-dev-workflows:feature-development`. No framework nuevo, dependencias, CSS global, layout global o rutas nuevas.

## Pantallas y contratos reutilizados

GET `/admin/cotizaciones` presenta listado HTML para navegación; `Accept: application/json` conserva estructura, proyección y enlaces del paginador JSON del Sprint 19. HTML agrega parámetros validados a sus propios enlaces; no cambia los JSON. Ambos responden `Cache-Control: no-store, private`.

Listado: código, recepción en Lima, estado, nombre histórico del servicio, responsable por ID y acción Consultar. No incluye nombre/correo/teléfono del contacto, necesidad ni notas en HTML inicial. Total, rango y orden visibles, paginación SQL existente. Tabla con scroll horizontal interno y región accesible. Filtros exactos por estado, ID responsable, UUID servicio, código y fechas inclusivas Lima. El cliente elimina campos vacíos antes de navegar; sin JavaScript el listado y sus enlaces permanecen disponibles, pero filtros/editor requieren JS, como indica el aviso.

Editor integrado en la misma página: detalle guardado con etiquetas y fechas legibles, contacto/necesidad editables, asociación opcional por UUID, responsable, estado y seguimiento privado. El UUID asociado se muestra de solo lectura una vez establecido: el backend conserva el nombre histórico y rechaza sustitución/eliminación. La interfaz no selecciona servicios por texto ni convierte la asociación en promesa de disponibilidad.

Se emplean IDs/UUID explícitos conforme al contrato existente. No se consulta ni exporta un directorio de personal/servicios, ni se exige `usuarios.ver` o `servicios.ver` para gestionar expedientes. Asignación valida personal autorizado en servidor. Los nombres de operadores no forman parte del contrato de historia: se muestran ID o cuenta eliminada. Un selector con búsqueda minimizada requeriría un contrato posterior autorizado.

Las ocho rutas privadas del Sprint 19 permanecen iguales:

| Ruta | Uso | Permiso |
| --- | --- | --- |
| GET `/admin/cotizaciones` | Listado HTML/JSON | `cotizaciones.ver` |
| POST `/admin/cotizaciones` | Nueva solicitud | `cotizaciones.crear` |
| GET `/admin/cotizaciones/{uuid}` | Detalle y ETag | `cotizaciones.ver` |
| PUT `/admin/cotizaciones/{uuid}` | Información/primera asociación | `cotizaciones.editar` |
| PATCH `/admin/cotizaciones/{uuid}/estado` | Recibida → en revisión | `cotizaciones.gestionar` |
| PATCH `/admin/cotizaciones/{uuid}/responsable` | Asignar/desasignar | `cotizaciones.gestionar` |
| POST `/admin/cotizaciones/{uuid}/seguimientos` | Nota y próxima fecha | `cotizaciones.gestionar` |
| GET `/admin/cotizaciones/{uuid}/historial` | Historia paginada | `cotizaciones.ver` |

El enlace lateral requiere lectura. Los formularios se renderizan por permisos y el cliente también comprueba sus capacidades; middleware, requests y reautorización transaccional siguen siendo la protección efectiva. Crear/editar/gestionar sin lectura no habilita esta pantalla: esos clientes conservan su API y recibos mínimos anteriores. No se conceden permisos nuevos.

Solo estados `recibida` y `en_revision`, con transición hacia revisión. Al estar en revisión desaparece esa acción. No hay botones de aceptar, rechazar, cancelar, cerrar o emitir presupuesto. La nota y próxima fecha usan el contrato existente: fecha vacía envía null y elimina la previa; fechas vencidas son válidas. No se generan recordatorios, jobs, correo o documentos.

## Concurrencia, incertidumbre y privacidad

Cliente exclusivo `admin-cotizaciones.mjs`, sin Alpine nuevo: fetch del mismo origen, credenciales de sesión, CSRF, `Accept: application/json`, caché desactivada y redirecciones rechazadas; timeout de transporte de 12 segundos. No se reintenta ninguna escritura automáticamente. Doble envío bloqueado mientras hay operación en curso; controles deshabilitados durante escritura o conciliación pendiente.

Mutaciones existentes envían ETag fuerte por `If-Match`. 412/428 conservan borrador y revisión anterior; Comparar carga una copia separada sin reemplazar campos. Adoptar exige confirmación y conserva borradores; solo entonces usa la revisión comparada para una decisión posterior. 409 conserva texto y solicita revisar incompatibilidad; 422 conserva campos y permite corregir tras rechazo conocido. 401/403/419/404 bloquean por acceso/disponibilidad; errores de lectura permiten nueva consulta y no se interpretan como escritura incierta.

Creación y seguimiento asignan UUID a cada intención nueva. Antes de enviar se fija el cuerpo serializado, clave, destino y revisión. Timeout, fallo de red, 5xx o recibo ilegible producen incertidumbre y bloquean nuevas escrituras. Conciliar es una acción deliberada con confirmación: conserva exactamente cuerpo y clave originales, incluso si el objeto borrador cambiara. No se genera una clave nueva para eludir incertidumbre. Una respuesta 422 posterior a una intención incierta tampoco libera su reserva local.

Para seguimiento el backend verifica revisión antes del replay. Si la conciliación recibe 412, comparar/adoptar actualiza solo la revisión del intento original; mantiene cuerpo/clave y el bloqueo de nueva intención. El siguiente replay sigue siendo manual. Para PUT/estado/responsable inciertos, que no tienen clave idempotente, se ofrece comparar y confirmar la revisión del resultado **sin reenviar**. GET no prueba quién realizó un cambio coincidente. Conflictos irresolubles de clave requieren conciliación operativa, no una intención nueva automática.

Tras recibo mínimo válido se consulta detalle/historia para presentar datos guardados; esa lectura conserva los demás borradores. Listado avisa que filtros/recuentos requieren actualización. Abrir otro expediente/nuevo, filtrar o seguir enlaces pregunta antes de descartar cambios; beforeunload avisa con borrador, petición o resultado incierto. No se conserva el borrador al cerrar/recargar deliberadamente la página.

Todos los textos Blade se escapan; cliente inserta texto mediante `textContent`, nunca HTML de respuestas. Título genérico; URLs solo filtros técnicos/código e identidad, sin contacto/necesidad/notas. No hay consola con datos, analítica, almacenamiento local/sessionStorage/IndexedDB, exportación ni transmisión a Advisor/FastAPI/OpenAI. Notas permanecen en historia privada; no se altera auditoría general. Esto no acredita cumplimiento legal: privacidad, finalidades y retención siguen pendientes antes de operación real.

## Pruebas y evidencia

`PanelCotizacionesTest`: siete pruebas nuevas sobre negociación y JSON previo, listado minimizado sin escrituras, filtros/estado/paginación, permisos lector/editor/gestor, navegación rechazada sin lectura, título/privacidad, escape de snapshot heredado y ausencia de efectos externos. SQLite en memoria con guardas, HTTP bloqueado y Mail/Queue falsos.

`admin-cotizaciones.test.mjs`: dieciocho pruebas nuevas de CSRF/If-Match/cuerpo permitido, conflictos con adopción explícita, clave/cuerpo retenidos tras incertidumbre, seguimiento incierto seguido de 412, revisión sin reenvío de PUT, rechazos 401/403/419/404/409/422/428/500, respuesta 2xx ilegible, capacidades del lector, doble envío, 422 posterior a incertidumbre y fallo de lectura recuperable.

Fixture opt-in `tests/Support/cotizaciones-panel-browser-router.php`, únicamente `127.0.0.1:8775`, perfil PostgreSQL rechazado, sin configuración cacheada. Usa `.env.example` con overrides sintéticos; comprueba SQLite `:memory:` y flags apagados antes de migrar en memoria. Sesión/caché/correo array, cola sync, HTTP externo bloqueado, CSRF sintético estable de fixture sin desactivar middleware. Solo rutas del módulo y activos locales permitidos; `/index.php` y otros destinos quedan rechazados. Reconstruye datos en cada petición: UUID de ruta puede identificar una ficha sintética reconstruida. No acredita persistencia entre peticiones ni revocación/concurrencia PostgreSQL.

Smoke Edge independiente con CDP 19337, puertos libres exigidos, procesos ocultos, perfil temporal propio verificado antes de borrarlo y cierre de helpers. Bloquea destinos externos. Evidencia ignorada en `storage/app/private/testing/cotizaciones-panel/`: reporte y capturas de listado/detalle/conflicto/incertidumbre. Se verifican 390/768/1440, ausencia de scroll horizontal de página, etiquetas, objetivos de al menos 44 px y foco/Tab. Escrituras reales del backend solo dentro de la memoria efímera; pérdida de conexión simulada mediante CDP no demuestra un commit de resultado incierto. No es auditoría WCAG completa ni prueba de dispositivos físicos.

| Validación final | Resultado |
| --- | --- |
| `php artisan test --compact --filter=PanelCotizacionesTest` | **7 aprobadas, 78 aserciones**, 10,73 s |
| Selección inicial panel + backend 19 | 51 aprobadas, 598 aserciones; anterior a los dos casos PHP añadidos |
| `php artisan test --compact` | **1064 aprobadas, 7361 aserciones**, 332,18 s; SQLite `:memory:` |
| `php vendor/bin/phpstan analyse --no-progress` | **Nivel 6 sin errores** |
| Pint `--test`, controlador, prueba y fixture propios | **Aprobado** |
| `npm test` | **36 aprobadas**, incluidas 18 nuevas |
| `npm run build` | **Aprobado**, salida local ignorada en `public/build` |
| Smoke Edge | **Aprobado** en 390/768/1440; 29 respuestas del fixture, cero excepciones JS y destinos externos |
| `git diff --check` y revisión de blancos de archivos nuevos | **Aprobados**, avisos LF/CRLF conocidos |
| Revisión de patrones de secretos | Sin coincidencias de claves privadas/tokens de proveedor/URI con credenciales; solo datos sintéticos |

El smoke verifica listado/detalle, foco/Tab, controles, estado, responsable, seguimiento, creación, edición, conflicto/conservación/comparación/adopción explícita, lector sin formularios y 403 real del servidor. Simula pérdida de red mediante CDP y comprueba nota retenida y bloqueo. Se inspeccionó visualmente la captura móvil; se mejoraron etiquetas y fechas sin ampliar el alcance. Un pase ampliado falló por esperar el retorno de un clic que abrió una confirmación: se corrigió la coordinación CDP para aceptar el diálogo mientras el clic estaba pendiente, sin cambiar la aplicación ni ampliar timeouts. Las pruebas anteriores no se debilitaron.

## Límites y siguiente fase

PostgreSQL/S7/000022 permanecen sin intervención. No se editan migraciones ni se ejecutan en bases persistentes. SQLite no acredita locks, carreras de idempotencia, aislamiento, DDL/UUID/índices o rendimiento PostgreSQL 18; continúan los requisitos de aceptación del Sprint 19 y la autorización nominal de migraciones/destino/backup.

Para Sprint 19.2: definir alcance de recepción pública, consentimiento/finalidades/retención, protección antispam/rate limit, confirmación sin precio ni aprobación y un contrato público separado. No reutilizar API privada ni ofrecer búsqueda pública por código. Selectores administrativos minimizados y reglas comerciales adicionales requieren diseño/autorización propios. No se envían expedientes personales a PHP chatbot/Python/Advisor.

Catálogos públicos, Resend, verificación y Advisor siguen apagados. No se alteran cursos, servicios existentes, páginas públicas/contacto/principal, chatbot, Python, configuración sensible o proveedores. Sin commits, push, despliegues, modificaciones del índice o stashes.

## Archivos propios

Doce archivos: `app/Http/Controllers/Admin/CotizacionController.php`, `resources/views/admin/partials/sidebar.blade.php`, `resources/views/admin/cotizaciones/index.blade.php`, `resources/views/admin/cotizaciones/_editor.blade.php`, `resources/js/admin-cotizaciones.mjs`, `vite.config.js`, `tests/Feature/PanelCotizacionesTest.php`, `tests/frontend/admin-cotizaciones.test.mjs`, `tests/Support/cotizaciones-panel-browser-router.php`, `tests/browser/cotizaciones-panel-smoke.mjs`, este informe y `PROJECT_CONTEXT.md`. El controlador y contexto ya tenían trabajo previo; las adiciones 19.1 se comparan con la instantánea inicial, sin atribuirse ni sobrescribir el Sprint 19.

Preservación final: sobre 618 archivos cubiertos por el snapshot inicial, 614 idénticos y cuatro cambios previstos (controlador, contexto, navegación y Vite); ocho nuevos propios y ninguno inicial eliminado. Tres imágenes versionadas con nombres Unicode quedaron fuera de ese snapshot por el formato citado de Git: se comprobaron adicionalmente, idénticas a HEAD y sin modificaciones locales iniciales/finales. La revisión final usa inventario Git delimitado por NUL. Se conservan byte a byte informe PostgreSQL, servicios, backend comercial restante y migraciones; las secciones PostgreSQL/migraciones del contexto coinciden con la instantánea inicial. Rama/HEAD, índice vacío y hashes de ambos stashes permanecen intactos.
