# Sprint 18.1 — panel de servicios tecnológicos

29/10/2026. Rama `anderson`, HEAD `306b168e8d8bbfec23301d7c050c7f7c9ae11d2f`. Al comenzar permanecían sin commit los 16 archivos del Sprint 18 y el informe PostgreSQL previo sin seguimiento. Se conserva su trabajo; no hay operaciones Git de escritura ni intervención en bases reales.

## Autorización, diseño y plan

La autorización explícita de Sprint 18.1 permite la excepción específica al alcance backend de AGENTS §2: navegación y vistas del módulo, formularios conectados a endpoints existentes, mensajes/conflictos y responsive. No autoriza páginas públicas, estilos globales, cursos, chatbot, migraciones ni cambios de dominio.

Se mantiene el layout `admin.layouts.app`, estilos Tailwind actuales, navegación Alpine existente y componentes/convenciones del panel. Se elige una página administrativa de listado con editor integrado (sin modal), que usa JSON para las operaciones existentes. Evita duplicar controladores/rutas de escritura y permite preservar el borrador en memoria ante errores.

Archivos delimitados antes de editar:

- `resources/views/admin/partials/sidebar.blade.php`: enlace condicionado por `servicios.ver`.
- Nuevos `resources/views/admin/servicios/{index,_editor}.blade.php` y `resources/js/admin-servicios.mjs`: listado/editor exclusivo, manejo JSON, CSRF, revisión y accesibilidad.
- `vite.config.js`: entrada del módulo exclusivo; sin cambios a `app.js`, CSS o layout global.
- `app/Http/Controllers/Admin/ServicioTecnologicoController.php`: negociación HTML en GET index, manteniendo JSON anterior; carga editorial de la página en consulta acotada para evitar N+1.
- Nuevas pruebas PHP/frontend/fixture/smoke exclusivos, este informe y adición acotada al contexto maestro.

Plan: pruebas de contrato y estados del cliente; vista HTML/listado/editor; cliente JSON y conflictos; navegador seguro en 390/768/1440; suite SQLite, Larastan, Pint, npm/build, diff y verificación de preservación. Se aplican las habilidades de planificación, pruebas y verificación ya leídas; la especificación del propietario autoriza el desarrollo completo sin una nueva aprobación de diseño.

## Fronteras

`catalogo-servicios.enabled=false` permanece intacto. No publicaciones/datos reales; solo fixtures sintéticas en SQLite `:memory:`. PostgreSQL/S7/000022, entornos, credenciales, Resend, correo, Advisor y proveedores externos quedan sin intervención. No commits, push ni modificaciones de stashes.

## Interfaz y rutas utilizadas

Una página en `/admin/servicios`: tabla con código/nombre, estado administrativo, estado editorial y acción Revisar; filtros de estado, total/rango y paginación HTML de 15 filas (máximo 50 por contrato existente). Estado vacío renderizado en servidor. La tabla tiene desplazamiento horizontal interno y región accesible; el editor fluye en una columna en móvil y aprovecha las columnas disponibles en escritorio. Sin modal nuevo ni CSS global.

`ServicioTecnologicoController::index()` negocia HTML para navegación y mantiene el JSON anterior con `Accept: application/json`. Solo HTML añade `withQueryString()` y carga `publicacion` para las filas de esa página; prueba de 17 servicios mide tres consultas del módulo (COUNT, página y fichas). No hay N+1 ni consultas por ficha desde el navegador para construir el listado. El estado editorial temporal se presenta con las mismas fechas inclusivas Lima del backend; no acredita elegibilidad pública. HTML y JSON responden `no-store, private`.

No se añaden rutas ni se modifican rutas/contratos de escritura. El cliente usa:

| Método y ruta | Uso visual | Permiso existente |
| --- | --- | --- |
| GET `/admin/servicios` | Listado HTML; JSON sin cambios de enlaces/estructura | `servicios.ver` |
| POST `/admin/servicios` | Alta inactiva, sin ficha ni autorización | `servicios.crear` |
| GET `/admin/servicios/{id}` | Detalle administrativo, ficha guardada y ETag | `servicios.ver` |
| PUT `/admin/servicios/{id}` | Nombre/categoría administrativos | `servicios.editar` |
| PATCH `/admin/servicios/{id}/estado` | Activar/inactivar/archivar | `servicios.editar` |
| PUT `/admin/servicios/{id}/publicacion` | Preparar borrador completo, vigencia incluida | `servicios.editar` |
| POST `/admin/servicios/{id}/publicacion/autorizar` | Autorizar la ficha guardada, con confirmación requerida | `servicios.publicar` |
| DELETE `/admin/servicios/{id}/publicacion` | Retiro confirmado por el usuario | `servicios.publicar` |

GET editorial existente sigue disponible, pero el panel usa el detalle completo para evitar una segunda consulta HTTP. El enlace lateral requiere `servicios.ver`; cada formulario/botón de acción se renderiza solo con su permiso. Lector no recibe formularios de escritura; publicador sin edición puede revisar/autorizar/retirar, sin editar. Middleware, rol de acceso al panel, Form Requests y reautorización del Sprint 18 permanecen intactos. Ningún permiso se crea o concede en este sprint.

## Flujo y seguridad del cliente

`admin-servicios.mjs` es una entrada Vite propia, cargada únicamente en esta vista; no modifica `app.js`, el chat o sus eventos Alpine. El layout/menú móvil siguen usando el Alpine existente. Las vistas usan los colores, tipografía, tarjetas y controles Tailwind del panel actual.

- Crear: código/nombre/categoría; se envía estado inactivo explícito. Código e identidad se muestran después como lectura, sin campos de edición. Registrar no prepara una publicación.
- Editar: datos administrativos y ficha tienen formularios distintos. La ficha publicada queda visible y deshabilitada para edición hasta retiro autorizado. Inactivar/archivar conserva el comportamiento existente de retiro preventivo; reactivar no publica.
- Publicar/programar: revisar la ficha guardada, guardar antes cambios pendientes y marcar consentimiento inicialmente vacío. La fecha inicial futura programa la publicación. Nunca se toma el texto local sin guardar como si hubiera sido aprobado.
- Retirar: confirmación nativa, petición DELETE con CSRF/ETag. Se conservan borradores locales no guardados.
- Cambiar de servicio con texto pendiente pregunta antes de descartarlo. Salir/recargar con borrador, petición en curso o resultado incierto activa la advertencia nativa del navegador. No se promete recuperar el texto si el usuario confirma abandonar; no se guarda contenido en almacenamiento persistente del navegador.
- Tras una mutación, la fila visible se actualiza y se avisa que filtros/total/rango corresponden a la consulta anterior. No se recarga automáticamente el listado ni se borra el editor. El enlace de actualización es una navegación explícita, con advertencia si existe borrador.
- Mutaciones con cabecera CSRF, `Accept: application/json`, JSON permitido por operación e `If-Match` del detalle; sin autor, identidad u otros campos internos en payloads. `credentials=same-origin`, `cache=no-store`, redirecciones rechazadas, plazo de 12 segundos y ninguna política automática de reintentos.
- Respuestas y textos se insertan con `textContent`/valores de formulario; no `innerHTML`. Nombres/códigos se muestran literalmente; el mapa de etiquetas se aplica solo a estados y rechaza propiedades heredadas como `constructor`/`toString`.

Los controles se bloquean mientras hay petición; doble envío no crea otra. Los formularios conservan sus valores ante 422 y el resumen de errores recibe foco. Los inputs tienen etiquetas, límites nativos, ayudas y controles de al menos 44 px. El encabezado del editor recibe foco al abrirse; no hay trampa de foco. La tabla y comparación tienen región/área desplazable accesible por teclado. Este trabajo no acredita una auditoría WCAG completa ni lectores de pantalla.

## Revisión, conflictos e incertidumbre

Cada escritura sobre una entidad existente toma el ETag actual y no renueva silenciosamente la revisión. 412/428 conservan borradores y revisión antigua, deshabilitan mutaciones y ofrecen «Cargar versión actual para comparar». GET muestra todos los campos administrativos editables/estado y campos editoriales vigentes en un panel de comparación; **no sustituye el borrador ni desbloquea las escrituras**. Solo «Revisé la versión actual; conservar mi borrador» adopta la revisión leída. Después hay que decidir y enviar explícitamente la operación; nunca se reenvía automáticamente.

401/403/419 bloquean operaciones hasta volver a acceder, conservando texto. 409 muestra rechazo de transición/vigencia y mantiene borrador. Un fallo de lectura (HTTP/JSON/red) deja consultar otra vez; no implica una escritura incierta. Un fallo de transporte, timeout, 500 o respuesta de éxito sin contrato/revisión válidos tras una mutación se trata como resultado **incierto**: conserva texto y bloquea reenvíos. Para ID conocido se permite consulta/comparación explícita; una creación incierta requiere revisar el listado y reconciliar manualmente, sin reenviar automáticamente.

Una consulta GET no demuestra ejecución exactamente una vez ni identifica por sí sola quién causó un cambio coincidente. No hay idempotency-key nueva, reintentos automáticos ni relajación del If-Match obligatorio. La prueba de dos revisiones y la fixture de navegador demuestran el manejo del rechazo, no carreras PostgreSQL. Los límites de revocación concurrente de Spatie y locks de SQLite descritos en Sprint 18 siguen vigentes.

## Pruebas y evidencia visual

- PHP nuevo: `tests/Feature/PanelServiciosTest.php`, cinco pruebas de HTML/JSON, autenticación, roles/permisos independientes, navegación, ocultación de acciones, vacío, filtros/paginación/estado editorial, escape y consultas acotadas. La expectativa de enlaces JSON preserva exactamente la forma anterior, sin aplicar el filtro visual a esos enlaces.
- Frontend nuevo: `tests/frontend/admin-servicios.test.mjs`, once pruebas del cliente de servicios: CSRF/lista de campos, texto literal, fallos de lectura, preservación, doble envío, If-Match, comparación/adopción explícita, incertidumbre, publicación con cambios pendientes y rechazos de acceso. Las siete pruebas del chat existente también se ejecutan sin cambiar su código.
- Fixture nueva: `tests/Support/servicios-panel-browser-router.php`; rechaza configuración cacheada, destino distinto, falta de opt-in/perfil PostgreSQL y cualquier driver/base diferente de SQLite `:memory:`. Carga configuración de ejemplo con overrides seguros y flags apagados, bloquea HTTP externo y usa cuentas/datos sintéticos.
- Smoke nuevo: `tests/browser/servicios-panel-smoke.mjs`, únicamente `127.0.0.1:8773` y Edge headless, CDP `19335`; ambos puertos deben estar libres. No usa el servidor cotidiano. El fixture reconstruye esquema y datos **por petición** y las cabeceras de prueba eligen revisión/estado ficticios: no es una base persistente ni demuestra continuidad transaccional entre peticiones.
- Navegador: listado/editor en **390, 768 y 1440 px**, sin desbordamiento de página, etiquetas correctas, objetivos ≥44 px, apertura con Enter, foco al editor y Tab hacia controles, consentimiento vacío. Además: creación 201 inactiva, edición, borrador, publicación/retiro con un evento de auditoría en cada petición efectiva, 412 sin auditoría y con borrador/revisión protegidos, comparación/adopción explícita y lector sin controles de escritura; intento de publicación de lector devuelve 403. Sin excepciones JavaScript ni destinos externos solicitados durante el recorrido.

Evidencia local ignorada: `storage/app/private/testing/servicios-panel/report.json`; `list-{390,768,1440}.png`, `editor-{390,768,1440}.png` y `conflict-390.png`. Se inspeccionaron visualmente el editor móvil y el listado de escritorio además de las mediciones. No se valida entrega real, dispositivos físicos, otros motores de navegador o lectores de pantalla.

Se corrigieron con regresiones observadas: traducción indebida de nombres y claves heredadas; bloqueo permanente por GET fallido; mensaje de carga retenido tras fallo de red; ausencia de aviso de listado desactualizado y alteración accidental de enlaces JSON al compartir el paginador HTML. También se corrigió la simulación de Enter CDP y se alinearon las versiones ficticias entre peticiones del smoke, sin modificar reglas de dominio para hacerlo pasar.

Una repetición posterior devolvió 419 al vencer cookies generadas con el mediodía fijo de la fixture frente al reloj real del navegador. Se identificó la causa en la fecha de expiración y se utilizó el instante ISO del inicio del smoke, validado como entrada opt-in, para el reloj de la fixture y sus vigencias sintéticas. No se aumentaron sesiones/timeouts ni se deshabilitó CSRF. El recorrido completo posterior aprobó con la configuración corregida y su `report.json` final no contiene fallos.

| Comando / comprobación final | Resultado |
| --- | --- |
| `php artisan test --compact --filter='PanelServiciosTest|ServicioTecnologicoTest'` | **35 aprobadas, 466 aserciones**, 10,55 s |
| `php artisan test --compact` | **986 aprobadas, 6498 aserciones**, 127,82 s |
| `npm test` | **18 aprobadas**: 11 de servicios y siete existentes |
| `npm run build` | **Aprobado**, Vite 8.3.5, entrada exclusiva emitida; solo `public/build` ignorado se regenera |
| `php vendor/bin/phpstan analyse --no-progress` | **Nivel 6, sin errores** |
| Pint `--test` sobre controlador, prueba PHP y fixture propios | **Aprobado**, sin Pint global |
| `node tests/browser/servicios-panel-smoke.mjs` | **Aprobado**, tres anchuras y flujos descritos, cero excepciones JS; helpers cerrados al terminar |
| `git diff --check` | **Aprobado**, avisos LF/CRLF conocidos |

Revisión estática separada: se atendieron los hallazgos de texto literal, lectura recuperable y listado desactualizado con regresiones fallidas antes de corregir. Sin cambios en modelos, migraciones, requests, servicio de dominio, lector público ni permisos del Sprint 18.

## Estado Git e inventario final

Rama/HEAD iniciales conservados, índice vacío. **12 archivos de Sprint 18.1:**

| Archivo | Cambio |
| --- | --- |
| `app/Http/Controllers/Admin/ServicioTecnologicoController.php` | Solo negociación/carga HTML de index; resto del controlador y escrituras previas intactos |
| `resources/views/admin/partials/sidebar.blade.php` | Enlace autorizado del módulo |
| `vite.config.js` | Una entrada nueva, sin alterar las anteriores |
| `resources/views/admin/servicios/index.blade.php` | Nuevo listado y contenedor del cliente |
| `resources/views/admin/servicios/_editor.blade.php` | Nuevos formularios y revisión/comparación |
| `resources/js/admin-servicios.mjs` | Cliente JSON exclusivo |
| `tests/Feature/PanelServiciosTest.php` | Cinco pruebas PHP nuevas |
| `tests/frontend/admin-servicios.test.mjs` | Once pruebas de cliente nuevas |
| `tests/Support/servicios-panel-browser-router.php` | Fixture segura SQLite por petición |
| `tests/browser/servicios-panel-smoke.mjs` | Smoke del módulo |
| Este documento | Contratos y evidencia |
| `PROJECT_CONTEXT.md` | Solo dos párrafos de Sprint 18.1 |

Se inventariaron **598 archivos previos** por SHA-256. Al cerrar, los únicos cambios sobre ese inventario son controlador, sidebar, entrada Vite y las adiciones autorizadas al contexto; el resto del Sprint 18, documentos PostgreSQL, páginas públicas, migraciones, Python y archivos protegidos se conservan. El contenido previo del contexto se conserva íntegro, comprobado al excluir únicamente los dos párrafos nuevos de 18.1. Los archivos nuevos del sprint son los ocho indicados en la tabla; no se añadieron al índice. No se borraron archivos preexistentes ni se tocaron stashes.

## Pendientes y activación

No se conecta a la web pública. `catalogo-servicios.enabled=false`, los interruptores de cursos/chatbot/Advisor y los de correo permanecen sin activar. Antes de operar con datos reales: aceptación PostgreSQL independiente, revisión/autorización de migraciones concretas y activación operativa controlada. El enlace y las vistas están preparados en código; su presencia no constituye autorización para escribir en las bases habituales.

La futura web debe aprobar su integración visual, consumir solo la proyección pública, escapar contenido, gestionar vacíos/fallos/paginación y revisar autorizaciones/vigencia. No hay cambios en catálogo, cotizaciones, distribución Advisor, cursos o publicación editorial de otros módulos.
