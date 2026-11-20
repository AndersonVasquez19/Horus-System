# Sprint 18.2 — catálogo de servicios en la web

## Inventario y decisión antes de implementar

Rama `anderson`, HEAD `306b168e8d8bbfec23301d7c050c7f7c9ae11d2f`. Se conservan los cambios locales de 18/18.1 y los documentos PostgreSQL. Inventario SHA-256 inicial: 606 archivos; índice sin intervención.

Las tres rutas GET/HEAD existentes son `tecnologias.cableado` (`/tecnologias/cableado-estructurado`), `tecnologias.camaras` (`/tecnologias/camaras-seguridad`) y `tecnologias.soporte` (`/tecnologias/soporte-mantenimiento`). Sus vistas Blade contienen texto e imágenes estáticos: categorías de cable 1–6; tipos de cámaras y funciones complementarias; configuración/instalación, mantenimiento preventivo y asesoría/capacitación. No son identidades editoriales. No se importan a datos ni se sustituyen.

Se incorpora una sección independiente «Catálogo de servicios tecnológicos» al final de soporte/mantenimiento, que ya contiene «Nuestros Servicios». Cableado y cámaras quedan intactos. La categoría editorial es texto libre y no permite asignar automáticamente una ficha a una de las tres páginas; presentar un catálogo general en un único lugar evita esa inferencia y triplicar fichas. No se añade navegación general ni rutas nuevas. Posibles solapamientos semánticos con grupos estáticos se revisarán editorialmente: no se deduplican por título ni se equiparan grupos con fichas específicas.

Archivos previstos: controlador público nuevo, destino de la ruta de soporte existente, bloque condicional en su vista, pruebas/fixture/smoke propios, este documento y adición al contexto. Se reutiliza `CatalogoPublicoServicios::pagina()` sin modificarlo. El flag propio permanece `false`; no cambia configuración, panel, cursos, chatbot, Python, CSS/JS fuente, imágenes ni entornos.

Plan: pruebas de estados/contrato, conexión y sección Blade, errores seguros y paginación, smoke Edge aislado, regresión completa SQLite y calidad; registrar resultados reales al cerrar. No PostgreSQL, migraciones reales, proveedores, commits, push o stashes.

## Implementación y contrato público

`ServiciosPublicosController` invocable atiende exclusivamente el GET/HEAD existente de soporte; conserva URL y nombre. No se añade ruta pública ni cambia cableado, cámaras, contacto o navegación. La vista modificada es únicamente `resources/views/pages/tecnologias/soporte-mantenimiento.blade.php`: sección condicional posterior a las dos secciones originales, con `cartas-section`, `container`, `carta-grid`, `carta-card` existentes. Sin imágenes nuevas, sustitución de tarjetas, CSS/JS fuente o librerías. Ajustes locales `min-w-0`, `overflow-wrap:anywhere` y texto multilínea evitan que contenido largo amplíe las tarjetas. Las acciones son enlaces subrayados de al menos 44 px.

`catalogo-servicios.enabled=false` permanece literal e independiente de cursos/chatbot/Advisor. Apagado no se invoca el lector, no se inspeccionan tablas/permisos y ni siquiera se valida el parámetro nuevo: no requiere `000025`/`000026`. La prueba de invitado sin ambas tablas comprueba cero consultas con sesión/caché de pruebas en memoria; esto no significa que un despliegue con sesiones SQL no consulte sus tablas de sesión existentes.

Encendido solo en pruebas: `CatalogoPublicoServicios::pagina($pagina, 12)` conserva COUNT y LIMIT/OFFSET SQL. `servicios_pagina` acepta enteros 1–1000000; tamaño fijo público de 12, sin permitir al visitante ampliar la carga. Metadatos visibles: total autorizado vigente, página/última página, rango SQL consultado, número presentado y orden de registro estable. Anterior/siguiente apuntan a la misma ruta y ancla; no propagan parámetros arbitrarios. Una página posterior al total muestra ausencia de fichas y acceso a la anterior. El catálogo no materializa el conjunto completo ni hace consultas por tarjeta.

Se consume la proyección existente de siete campos: UUID público, título, resumen, descripción, categoría editorial y fechas de vigencia. **La vista solo presenta título, resumen, descripción y categoría opcional.** No imprime siquiera el UUID; sin ID/código/nombre/categoría administrativos, revisión, autores, timestamps internos o datos comerciales. UUID estable continúa disponible en el contrato backend para una integración comercial futura expresamente autorizada.

El lector sigue exigiendo servicio activo, ficha publicada, vigencia inclusiva Lima, autorización no futura y autor actual con permiso y rol de personal. No se modifica la lógica editorial, sus auditorías, retiros, ETag ni las escrituras del panel. GET relee cada vez; respuesta `Cache-Control: no-store, private`. Una respuesta ya entregada/en vuelo no se revoca retroactivamente.

Blade escapa todos los textos y atributos. Una cadena HTML heredada se muestra como texto, no HTML ejecutable. No se convierten descripciones/categorías en URL. «Consultar servicio» apunta únicamente a `/contactos`, nombre accesible por título. El contacto existente no acredita un receptor de solicitudes ni crea cotización, precio, disponibilidad, garantía o presupuesto aprobado. Los textos comerciales estáticos se conservan y no se extrapolan a cada ficha editorial.

## Vacío, infraestructura y errores

| Estado | Resultado |
| --- | --- |
| Apagado, con o sin tablas | Página original sin sección ni consultas del módulo |
| Encendido y vacío | Secciones originales y aviso de ausencia, con contacto general |
| Publicado y elegible | Ficha textual adicional dentro de su página SQL |
| Borrador, retirado, caducado, futuro, inactivo, archivado, autor ausente/revocado | Excluido por el lector existente |
| Tabla ausente | HTTP 200 estático y aviso genérico de indisponibilidad; log `servicios_web_esquema_incompleto` |
| Permiso o rol base sin provisionar | Mismo aviso; log `servicios_web_provision_incompleta`; no autoprovisión |
| `QueryException`, incluidas columnas incompletas | Mismo aviso; log `servicios_web_lectura_fallida` |
| Texto requerido vacío o excesivo/categoría excesiva heredados | Omite solo la ficha; log `servicios_web_ficha_invalida` |
| Error de programación | Propaga al manejador de errores; no se captura `Throwable` indiscriminadamente |

Los registros técnicos contienen únicamente el código fijo, sin SQL, bindings, credenciales, IDs, textos o excepción original. Las comprobaciones de esquema/provisión están detrás del flag. La comprobación de tablas Spatie respeta los nombres configurados. La primera selección reprodujo el caso real `RoleDoesNotExist` en una instalación migrada sin roles: se acotó la captura al consumidor web, sin alterar el servicio del Sprint 18 ni crear registros desde GET. Se comprueba también permiso ausente.

Las fichas inválidas se filtran **dentro de la página obtenida**, sin buscar más filas para rellenarla. El total/rango reflejan elegibilidad SQL y el contador presentado refleja las fichas válidas de esa página. Por tanto pueden diferir si hay contenido heredado defectuoso; no se publica ese contenido ni se recorren todas las páginas para contar fichas válidas. COUNT/página son lecturas separadas y no prometen snapshot ante escrituras simultáneas. OFFSET grande e índices requieren medición PostgreSQL posterior.

## Pruebas y evidencia visual

Nuevo `tests/Feature/CatalogoWebServiciosTest.php`: 25 casos sobre flag/ruta original, cero SQL sin tablas apagado, proyección pública, lectura sin escrituras/auditoría, estados y permisos, vacío, escape/URL, campos opcionales, infraestructura/errores con logs minimizados, propagación de errores de programación, texto heredado inválido, 25 fichas en tres páginas SQL, límite, consulta constante tras calentar caché de roles, entrada inválida y convivencia con otras páginas. El recorrido integrado usa los endpoints reales: crear → borrador oculto → autorizar → visible en GET → retirar → excluido, con cuatro auditorías de escritura y ninguna de lectura.

El primer recuento de consultas comparaba caché fría con caliente (17 frente a 15). Se igualó la condición de caché para medir el crecimiento por tarjetas; se mantiene además la aserción de un SELECT proyectado con `limit 12 offset 0`. No se suprimieron controles ni se modificaron pruebas previas.

Fixture nueva `tests/Support/servicios-web-browser-router.php`, exclusiva de **127.0.0.1:8774** y opt-in `HORUS_SERVICIOS_WEB_BROWSER_FIXTURE=1`. Rechaza configuración cacheada/perfil PostgreSQL/destino inesperado; carga `.env.example` con overrides seguros, comprueba SQLite `:memory:` y flags apagados antes de migrar/sembrar **solo memoria**. Sesión/caché/correo `array`, cola `sync`, HTTP externo bloqueado. El reloj ISO explícito corresponde al inicio del smoke; no se modifica el reloj del entorno habitual, CSRF ni duraciones. Reconstruye datos sintéticos por petición y solo atiende GET/HEAD de la página y activos locales. No demuestra persistencia entre peticiones, PostgreSQL o proveedores.

`tests/browser/servicios-web-smoke.mjs` reutiliza el patrón CDP de cursos en un archivo independiente, sin modificarlo. Edge headless, puerto CDP 19336, puertos libres exigidos, perfil temporal propio verificado antes de eliminarlo, helpers cerrados al terminar. **15 casos aprobados**: apagado, encendido con tres fichas/texto largo, vacío, falta de tabla encendido y apagado, en **390/768/1440 px**. Compara HTML/geometría de las dos secciones originales entre estados; imágenes cargadas, sin desbordamiento horizontal, fichas contenidas, contacto correcto, foco/área ≥44 px y Tab entre acciones. Cero excepciones JavaScript y cero solicitudes externas. No es auditoría WCAG, prueba de lectores de pantalla o de dispositivos físicos.

Evidencia local ignorada: `storage/app/private/testing/servicios-web/report.json`, capturas `off-*`, `on-*`, `empty-*`, `missing-*`, `off-missing-*`. Inspección visual de la captura móvil habilitada: tarjetas originales completas y nueva sección al final; el texto largo permanece contenido y extiende la página verticalmente, sin truncar silenciosamente la descripción.

| Validación | Resultado |
| --- | --- |
| `php artisan test --compact --filter=CatalogoWebServiciosTest` | 25 aprobadas, 243 aserciones |
| Selección servicios + panel + catálogo de cursos | 80 aprobadas, 866 aserciones |
| `php artisan test --compact` (SQLite `:memory:`) | **1011 aprobadas, 6741 aserciones**, 431,78 s |
| `npm test` | 18 aprobadas; código frontend previo intacto |
| `npm run build` | Aprobado; solo regeneración local ignorada en `public/build` |
| Larastan nivel 6 | Sin errores |
| Pint acotado a los tres PHP nuevos y `routes/web.php` | Aprobado |
| Smoke Edge | 15 casos aprobados, cero excepciones/destinos externos |
| `git diff --check` | Aprobado; avisos LF/CRLF conocidos |

## Inventario y preservación

Ocho archivos propios: controlador, vista pública de soporte, `routes/web.php` (solo destino de ese GET), prueba PHP, fixture PHP, smoke `.mjs`, este informe y adición a la sección de servicios del contexto. No se modifica lector, configuración, modelos, migraciones, permisos, controlador/vistas/JS administrativos, cursos, Python/chatbot, imágenes, CSS/JS fuente o entorno. Se conserva el informe PostgreSQL previo y el contenido anterior del contexto. Sin operaciones sobre el índice, commits, push, stashes o despliegue.

Comprobación SHA-256 sobre los 606 archivos iniciales: 603 idénticos; únicamente cambian vista de soporte, ruta (destino más import del controlador) y contexto. Se agregan cinco archivos. El contenido anterior del contexto coincide íntegramente al excluir los dos párrafos nuevos; al excluir el bloque condicional de la vista, su código original coincide con HEAD normalizando finales de línea. En el navegador las secciones originales mantienen HTML/geometría entre estados. Pint pidió importar la clase del controlador en rutas; se hizo ese ajuste localizado y su comprobación posterior pasa, sin reordenar rutas ajenas.

| Archivo propio | Acción |
| --- | --- |
| `app/Http/Controllers/ServiciosPublicosController.php` | Nuevo consumidor público acotado |
| `routes/web.php` | Destino e import del GET existente |
| `resources/views/pages/tecnologias/soporte-mantenimiento.blade.php` | Solo sección condicional adicional |
| `tests/Feature/CatalogoWebServiciosTest.php` | 25 pruebas nuevas |
| `tests/Support/servicios-web-browser-router.php` | Fixture opt-in en memoria |
| `tests/browser/servicios-web-smoke.mjs` | Smoke Edge exclusivo |
| `docs/sprint-18-2-servicios-web.md` | Inventario, contratos y evidencia |
| `PROJECT_CONTEXT.md` | Dos párrafos en sección de servicios |

## Activación pendiente y Sprint 19

La conexión Panel → publicación → lectura web está preparada y validada en SQLite; **la publicación dinámica sigue apagada**, sin registros reales. Ninguna tabla detectada activa el catálogo. Antes de datos reales: aceptación PostgreSQL independiente, revisión de DDL/FK/CHECK/UUID/índices/planes y concurrencia/revocación, autorización nominal de migraciones `000025`/`000026` y dependencias sobre destino y backup concretos. No ejecutar migración general, `000022` ni reanudar S7 por esta entrega. No se consultaron ni modificaron `horus_group`, ensayo o Testing.

Después: revisión humana de fichas/duplicidades/procedencia/vigencia y ausencia de secretos, destino de contacto, pruebas de retiro/caducidad y observabilidad en entorno autorizado, activación separada y reversión operativa mediante flag. Una clasificación futura por página necesita una taxonomía acordada; no se infiere del texto libre actual.

Para Sprint 19 se mantiene el contrato del Sprint 18: identidad UUID estable más nombre histórico, necesidad del cliente y estado del expediente privado, con permisos/auditoría/idempotencia propios. Retiro/archivo del servicio no borrará solicitudes históricas. Falta definir consentimiento, recepción comercial, estados, validación y resultados inciertos; no hay tablas, formulario ni flujo de cotizaciones nuevo. Advisor/exportación/ingestión de servicios continúa pendiente y apagado; esta integración web no cambia su conocimiento ni accede a expedientes.
