# Sprint 17.2 — Catálogo editorial en la página pública de cursos

## Estado inicial y alcance

Trabajo local del 29 de octubre de 2026 en `anderson`, HEAD `8fc4174790be7d8df38b8858cb8c9825c4e237ea`. Sin commits, staging, cambios de stashes ni despliegue. Línea base comunicada del Sprint 17.1: 931 pruebas y 5873 aserciones. No se presenta como una nueva ejecución inmutable de esa línea base: la primera suite de esta sesión coincidió con la implementación y detectó una expectativa anterior incompatible con la nueva conexión; véase validación.

Antes de editar se registraron 21 archivos versionados modificados y 16 entradas sin seguimiento agrupadas. Incluían el controlador/editor administrativo, servicios de publicación y Advisor, pruebas anteriores, documentación PostgreSQL y `chatbot-python/`. Se tomaron SHA-256 de 579 archivos existentes, incluyendo el trabajo Python sin confirmar. El índice estaba vacío.

La ruta pública era `Route::view('/educacion/cursos', 'pages.educacion.cursos')->name('educacion.cursos')`. El controlador `CursosPublicosController` existía, pero no se utilizaba. La página contiene una introducción y tres tarjetas de **modalidades**, no un listado de cursos particulares. No usa la página de capacitaciones ni una fuente administrativa compartida.

La lista inicial de archivos autorizados no incluía rutas. El propietario autorizó expresamente cambiar únicamente el destino de esta ruta al controlador existente, manteniendo URL, nombre y método. El import del controlador acompaña ese cambio; no se cambió ninguna otra ruta. La advertencia de `CLAUDE.md` sobre no enrutar este controlador describe la protección anterior; esta excepción específica permite la conexión, con el flag todavía apagado.

## Inventario propio

| Archivo | Cambio |
| --- | --- |
| `app/Http/Controllers/CursosPublicosController.php` | Lee `paraWeb()` solo con el flag habilitado; controla infraestructura incompleta, valida campos y limita la presentación |
| `resources/views/pages/educacion/cursos.blade.php` | Añade una sección condicional después de las modalidades; mantiene íntegro el contenido anterior |
| `routes/web.php` | Import y destino del GET existente, autorizados expresamente |
| `tests/Feature/Sprint17PublicacionTest.php` | Solo cambia el nombre de una prueba y su expectativa: con el flag encendido la ficha ahora aparece |
| `tests/Feature/CatalogoWebCursosTest.php` | 20 casos nuevos de integración y regresión |
| `tests/Support/catalogo-web-browser-router.php` | Fixture opt-in exclusiva de SQLite en memoria y loopback |
| `tests/browser/catalogo-web-smoke.mjs` | Smoke local con Edge/CDP, cinco estados y tres anchuras |
| `docs/sprint-17-2-catalogo-web.md` | Este informe |

La comparación final de los 579 archivos anteriores detectó cambios solamente en los cuatro primeros archivos de esta tabla; los otros 575 permanecieron idénticos. Se agregaron los cuatro últimos archivos. El test del Sprint 17 ya estaba sin seguimiento: se preservó todo su contenido salvo las dos líneas indicadas. El inventario detallado antes/después y los hashes están en `storage/app/private/testing/catalogo-web/inventory.json` (evidencia local ignorada por Git).

No se modificaron las vistas administrativas del Sprint 17.1, servicios editoriales, exportador, chatbot PHP, Python, configuraciones, CSS/JS fuente, imágenes, página principal ni otras páginas públicas. La compilación regeneró exclusivamente `public/build/`, ignorado por Git.

## Ruta, lectura y estados

- GET/HEAD `/educacion/cursos`, nombre `educacion.cursos`, sin autenticación administrativa.
- Controlador invocable `CursosPublicosController`, vista `pages.educacion.cursos`.
- Servicio existente `CatalogoPublicoCursos::paraWeb()`, contrato versión `1.0`: `habilitado`, `total`, `cursos`.
- Interruptor existente `catalogo-publico.enabled = false`; su archivo no se modificó. Es independiente de `chatbot.public_catalog_enabled` y `horus-advisor.enabled`, también apagados.
- Respuestas `Cache-Control: no-store, private`; no hay un snapshot/cache de fichas que siga recomendando contenido retirado.

| Condición | Resultado |
| --- | --- |
| Flag apagado | Solo página original; no se invoca el catálogo ni se consulta su esquema |
| Flag apagado, tabla ausente | Página original normal; prueba de invitado con cero consultas SQL |
| Flag encendido, fichas válidas | Sección adicional «CURSOS PUBLICADOS» con las fichas vigentes |
| Flag encendido, catálogo vacío | Se conservan modalidades y aparece un mensaje de ausencia de fichas, con contacto general |
| Borrador o retirada (`publicada=false`) | Excluida |
| Fecha futura o caducada | Excluida; vigencia inclusiva por fecha de Lima |
| Curso inactivo, archivado o eliminado | Excluido |
| Autor ausente, permiso revocado o autorización futura | Excluida |
| Flag encendido, tabla ausente o error SQL | HTTP 200 controlado, sin fichas, aviso público de indisponibilidad y registro técnico seguro |

Se reutiliza el filtro del servicio: publicación explícita, curso activo no eliminado, fechas vigentes, `autorizado_at` no nulo y no futuro, autor que conserve `cursos.publicar`. El editor mantiene sus permisos, auditoría, retiros y precondiciones de revisión. No se cambia ninguno de esos componentes.

La lectura usa una proyección explícita de siete campos: `titulo`, `resumen`, `modalidad`, `horario`, `inscripcion`, `vigente_desde`, `vigente_hasta`. El controlador no consulta estudiantes, matrículas ni información comercial. Los campos administrativos de `Curso` no forman parte de la respuesta del catálogo.

## Presentación y acciones

Las tarjetas nuevas reutilizan `cartas-section`, `container`, `seccion-header`, `carta-grid`, `carta-card` y `carta-features`. No se añadieron hojas de estilo, scripts ni bibliotecas. Las utilidades locales `min-w-0` y `overflow-wrap:anywhere` permiten títulos y resúmenes largos sin ampliar la página; la acción conserva el enlace subrayado y un área mínima de 44 píxeles.

Solo se muestran título, resumen, modalidad y los textos opcionales de horario e inscripción cuando existen. `inscripcion` es **texto**, no una URL ni prueba de elegibilidad para matricularse. No se inventan categoría, precio, duración, imagen, certificado ni cupos. La acción «Consultar curso» apunta a la ruta existente `contactos` (`/contactos`), con nombre accesible que identifica el curso. No crea una matrícula ni presupone que el curso la acepte.

Ejemplos exclusivamente ficticios en la fixture: «Redes de laboratorio» y «Programación de ejemplo», más una ficha con texto largo sin espacios para comprobar el ajuste. No se publicaron fichas reales.

No hay cursos individuales estáticos que deduplicar: las tarjetas anteriores describen modalidades. Se conservan completas y la sección editorial queda separada. No se equipara una modalidad a un curso ni se fusionan publicaciones distintas por compartir título: podrían ser ediciones diferentes. Si se incorporan cursos particulares estáticos en el futuro, hará falta definir una identidad común antes de deduplicarlos.

Con el flag apagado, la nueva sección no existe en el DOM. Al quitar el bloque nuevo, el código de la vista coincide con el original de HEAD, normalizando únicamente CRLF/LF. El smoke compara también el HTML y las dimensiones/posiciones de las dos secciones originales entre los cinco estados.

## Seguridad y fallos

Blade escapa todos los campos, incluidos atributos. Los textos de inscripción nunca se convierten en enlaces. El controlador rechaza fichas heredadas con título/resumen vacío, longitudes fuera del contrato o modalidad desconocida. No valida contenido administrativo ni lo copia a la ficha.

Registros sin SQL, excepciones completas, bindings, IDs, textos de cursos ni credenciales:

- `catalogo_web_esquema_incompleto`: falta la tabla editorial con el flag habilitado.
- `catalogo_web_lectura_fallida`: `QueryException` en la lectura.
- `catalogo_web_ficha_invalida`: campos públicos incompatibles; esa ficha se omite.

Se conservan HTTP 200 y el contenido estático ante fallos de lectura; el aviso no expone nombres de tablas ni configuración. No se captura indiscriminadamente cualquier excepción de programación. Una instalación sin el permiso registrado produce catálogo vacío por el contrato existente del servicio.

El orden lo establece el servicio por título y después ID editorial. Se presentan como máximo 50 fichas y se informa total, rango y orden; no se inventa paginación. **El límite es de presentación:** `paraWeb()` sigue materializando todos los resultados autorizados en una consulta, sin límite SQL. No se modificó ese servicio fuera del alcance autorizado. Antes de manejar volúmenes grandes conviene aprobar paginación en el contrato de lectura. La prueba de 1 frente a 51 fichas confirma ausencia de N+1, no memoria constante ni rendimiento de PostgreSQL.

## Validación

Las pruebas PHP usan `phpunit.xml`: SQLite `:memory:`, sesión/caché `array`, cola `sync`, correo `array`. HTTP externo bloqueado en los casos nuevos. No requieren PostgreSQL, Python, OpenAI ni Resend.

- Casos nuevos: ruta/nombre/métodos originales, flag apagado, ausencia de esquema, catálogo habilitado/vacío, publicaciones no públicas, fechas, autor/permisos, campos opcionales, escape, textos inválidos, errores SQL, orden/límite, ausencia de escrituras/N+1 y compatibilidad editor/exportador/retiro.
- Pruebas específicas: `php artisan test --compact --filter='CatalogoWebCursosTest|Sprint17PublicacionTest|CursoPublicacionTest|EditorPublicacionPanelTest|AdvisorCatalogoTest'`: **127 aprobadas, 923 aserciones**.
- Suite PHP completa: `php artisan test --compact`: **951 aprobadas, 6030 aserciones**, 129,48 segundos.
- Larastan nivel 6: sin errores (`php vendor/bin/phpstan analyse --no-progress`).
- Pint acotado a controlador, ruta, pruebas PHP y fixture: aprobado. No se reformatearon archivos ajenos.
- `npm run build`: aprobado, Vite 8.3.5. `npm test`: siete pruebas aprobadas.
- PHP lint de controlador y fixture: aprobado. Blade y rutas se compilan/renderizan mediante los GET reales en pruebas y navegador.
- `git diff --check`: aprobado; las advertencias conocidas LF/CRLF no son errores.

La primera ejecución completa se inició antes de editar y terminó durante la integración: 930 aprobadas y una expectativa obsoleta del Sprint 17 fallida, que afirmaba que una ficha habilitada nunca aparecía. Se adaptaron únicamente su nombre y la aserción de visibilidad. No se clasifica como fallo preexistente. Los primeros intentos de los tests nuevos corrigieron sus fixtures/aserciones (timestamp no nullable, nombre de acción invocable, artículo del chat y caché de permisos); no se relajaron controles de aplicación.

### Navegador real

`node tests/browser/catalogo-web-smoke.mjs`: **15 comprobaciones aprobadas**, con 390, 768 y 1440 píxeles y estados apagado, habilitado con tres fichas, vacío, esquema ausente habilitado y esquema ausente apagado. Edge headless con CDP; ninguna excepción JavaScript ni petición externa. Capturas revisadas visualmente para las tarjetas originales y nuevas, navegación, imágenes, espaciados y ajuste de textos; sin scroll horizontal de página. Las acciones nuevas reciben foco, son alcanzables y tienen al menos 44 píxeles de alto.

Fixture exclusiva de `http://127.0.0.1:8772`, activación explícita del proceso, configuración no cacheada y conexión verificada SQLite `:memory:`. Cada petición reconstruye solo datos ficticios; no simula persistencia entre peticiones. Atiende únicamente GET/HEAD de cursos y archivos locales de `public/`. Los flags solo se simulan dentro de la petición; no se modifica ningún archivo de configuración ni `.env`. El smoke bloquea destinos externos, verifica puertos libres y elimina solo su perfil temporal, tras comprobar su ruta. Los procesos propios se cierran al finalizar.

Evidencia local ignorada: `storage/app/private/testing/catalogo-web/report.json`, `inventory.json` y capturas `off-*`, `on-*`, `empty-*`, `missing-*`, `off-missing-*`.

SQLite y esta fixture no demuestran bloqueos, DDL, concurrencia o rendimiento de PostgreSQL, ni disponibilidad de servicios externos o despliegue.

## Activación pendiente y próximos pasos

1. **El flag permanece apagado.** Esta entrega prepara código; no publica contenido real ni activa servicios.
2. En una intervención futura autorizada, comprobar el estado de la migración existente `2026_10_28_000021_create_curso_publicaciones_table.php` y su permiso `cursos.publicar`. Este sprint no necesita migraciones nuevas ni ha consultado el estado de bases reales.
3. Autorizar por separado cualquier migración pendiente sobre la base concreta. No ejecutar una migración general: la verificación de correo (`000022`) y PostgreSQL S7 siguen suspendidos.
4. Revisar consentimiento y procedencia de cada ficha real, vigencia de sus datos y el destino de consulta. No importar datos administrativos ni crear fichas automáticamente. Los textos comerciales estáticos anteriores permanecen intactos; no se convierten en garantías particulares de las fichas nuevas.
5. Validar las fichas y sus retiros en un entorno de ensayo autorizado, revisar logs y considerar paginación antes de una activación controlada. Evitar caches externos que ignoren `no-store`.
6. Advisor conserva su exportador HMAC y está desactivado. La presentación web no resuelve la ingestión/recarga/invalidez segura de su conocimiento activo; esa integración debe comprobarse contra el contrato Python vigente en otro alcance.
7. Servicios tecnológicos requieren fuente editorial, administración y autorización propias; no copiar páginas fijas a registros. Cotizaciones requieren un flujo comercial con permisos, datos privados e idempotencia, separado del catálogo público. Ninguno se implementa aquí.

No hubo acceso a PostgreSQL habitual/ensayo/Testing, migraciones reales, correos, llamadas reales a OpenAI/FastAPI, commits, pushes ni despliegue.
