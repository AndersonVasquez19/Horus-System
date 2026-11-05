# Sprint 18.3 — Contenido administrable de las páginas de Servicios Tecnológicos

Ampliación del módulo existente (Sprint 18, 18.1 y 18.2) para que Gerencia administre el contenido de las tres páginas públicas:
**cableado estructurado**, **sistema de videovigilancia** y **soporte y mantenimiento**. No hay un módulo paralelo: se reutilizan
`ServicioTecnologico`, `ServicioPublicacion`, `GestionServiciosTecnologicos`, los permisos `servicios.*`, el control `If-Match`/ETag y la auditoría.

## Estado y autorizaciones pendientes

- Migración `2026_11_02_000033` y seeder **creados pero no ejecutados** sobre ninguna base persistente. Solo se probaron en SQLite `:memory:`.
- `catalogo-servicios.enabled` sigue en `false`: con el catálogo apagado las tres páginas sirven el HTML estático original sin consultar la base.
- `php artisan storage:link` **no se ejecutó** (`public/storage` no existe). Hasta que el propietario lo autorice, las imágenes subidas desde el panel
  se guardan pero el navegador no podrá servirlas. Las imágenes del contenido inicial son rutas `assets/images/...` ya versionadas y no dependen del enlace.
- No se modificó `RolesAndPermissionsSeeder`, `.env` ni ningún permiso. No se concedió nada a Coordinación ni Secretaría.

## Categorías

Identificador estable = `codigo` del servicio (único e inmutable ya en el Sprint 18). `App\Support\PaginasServicios` mapea:

| Código | Etiqueta | Ruta pública |
| --- | --- | --- |
| `CABLEADO` | Cableado estructurado | `/tecnologias/cableado-estructurado` (`tecnologias.cableado`) |
| `VIDEOVIGILANCIA` | Sistema de videovigilancia | `/tecnologias/camaras-seguridad` (`tecnologias.camaras`) |
| `SOPORTE` | Soporte y mantenimiento | `/tecnologias/soporte-mantenimiento` (`tecnologias.soporte`) |

No existía ningún servicio ni identificador equivalente en el código (las tablas solo se exploraron en código y SQLite; **no se consultó PostgreSQL**).
El seeder reutiliza un servicio con el mismo `codigo` si ya existe, de modo que no duplica. Si en la base real hubiera servicios creados a mano con otro código
para estas páginas, Gerencia debe decidir cuál adoptar: la página solo muestra el servicio cuyo código es exactamente uno de los tres.
El listado del panel muestra una insignia con la página pública y su enlace; el JSON añade `pagina_publica`.

## Base de datos (migración `000033`, aditiva)

`servicio_publicaciones` (nuevas columnas, todas nullable): `titulo_tarjetas` (120), `imagen_principal_ruta` (255), `imagen_principal_alt` (160).
El título principal es `titulo` y la descripción introductoria es `descripcion` (varios párrafos separados por línea en blanco).

`servicio_publicacion_elementos` (tarjetas): `id`, `servicio_publicacion_id` (FK → `servicio_publicaciones`, `restrictOnDelete`), `titulo` (120), `descripcion` (text),
`caracteristicas` (json, lista de textos), `icono` (60, clase Font Awesome), `variante_imagen` (`producto`|`escena`, reproduce el encuadre actual de videovigilancia),
`imagen_ruta` (255), `imagen_alt` (160), `orden` (unsigned int, `<= 9999`), `activo` (bool), `timestamps`.
Índice `servicio_elementos_visualizacion (servicio_publicacion_id, activo, orden, id)`. Restricciones `CHECK` en PostgreSQL y triggers equivalentes en SQLite
(`servicio_elementos_orden_rango`, `servicio_elementos_variante`). La estructura es común: lo que cambia entre páginas (qué hace cada campo) lo decide la plantilla.

## Reglas del contenido

- Máximo 30 tarjetas por ficha; hasta 12 características de 200 caracteres. Los textos rechazan `<` y `>`; los íconos solo `fas|far|fab fa-nombre`.
- Operaciones (todas requieren `servicios.editar`, `If-Match` vigente, ficha guardada y **no publicada**; si está publicada, 409 «retira la publicación antes de editar»):

| Método y ruta | Acción |
| --- | --- |
| `POST /admin/servicios/{id}/publicacion/imagen` | Sube o reemplaza la imagen principal (multipart) |
| `POST /admin/servicios/{id}/elementos` | Crea tarjeta (JSON o multipart con fotografía) |
| `POST /admin/servicios/{id}/elementos/{elemento}` | Edita tarjeta, cambia o quita su fotografía |
| `DELETE /admin/servicios/{id}/elementos/{elemento}` | Elimina tarjeta |

  El título de sección y el texto alternativo de la portada viajan por el `PUT .../publicacion` existente. Una tarjeta de otra ficha responde 404.
- Cada operación bloquea el servicio, aumenta `revision`, rota el ETag y audita `servicio_elemento_creado|actualizado|eliminado` y `servicio_imagen_principal_actualizada`
  (sin textos ni rutas en la auditoría). Si la ficha estaba «retirada», el cambio la devuelve a «borrador» y exige autorizar de nuevo.

## Imágenes (solo Laravel Storage, disco `public`)

`App\Services\ImagenesServicios`: validación `image` + `mimes` + `mimetypes` (tipo real por contenido; **sin SVG**), JPG/PNG/WebP, 3 MB máximo, 100–8000 px.
Nombre `servicios/<uuid>.<ext>` generado por el servidor; el nombre del cliente se ignora; la base guarda solo la ruta relativa y nunca acepta rutas del usuario
(`imagen_ruta` está prohibido en los requests). Un reemplazo guarda el archivo nuevo dentro de la transacción y borra el anterior **después** de confirmarla,
y solo si ninguna ficha o tarjeta lo referencia. Si la operación falla, el archivo recién subido se elimina. Las rutas `assets/images/...` (contenido inicial) jamás se borran.
Sin Supabase ni almacenamiento externo.

## Contenido inicial

`Database\Seeders\ServiciosTecnologicosContenidoSeeder` (no está en `DatabaseSeeder`): importa 6 tarjetas de cableado, 10 de videovigilancia y 3 de soporte con sus
textos, especificaciones, íconos, orden, encuadre e imágenes originales. Es idempotente (no toca un servicio que ya tiene ficha, así que no pisa ediciones),
crea el servicio **inactivo** y la ficha en **borrador** con vigencia de 5 años a editar: nada se publica solo. Uso futuro autorizado:
`php artisan db:seed --class=ServiciosTecnologicosContenidoSeeder`, y luego Gerencia activa y autoriza cada ficha desde el panel.

## Páginas públicas

`ContenidoPublicoServicios::pagina($codigo)` devuelve el contenido solo con catálogo encendido, servicio activo, ficha `publicada`, vigente (`America/Lima`),
`autorizado_at` no futuro y autor aún con `servicios.publicar` y rol de personal; solo tarjetas `activo` ordenadas por `orden, id`. Con el catálogo apagado, esquema
incompleto o error SQL/permisos, devuelve `null` y la vista usa su HTML estático (que no se modificó: cada página envuelve el original en `@if/@else`). Cableado y
videovigilancia pasan a `PaginaTecnologicaController`; soporte conserva `ServiciosPublicosController`. Los parciales `pages/tecnologias/partials/dinamico-*.blade.php`
reutilizan las clases y los CSS existentes. El catálogo genérico de fichas (Sprint 18.2) deja de listar las tres categorías, que ya tienen su página.
Si solo están publicadas las tres categorías y el catálogo se activa, la sección genérica de soporte mostrará su mensaje vacío existente («No hay fichas de servicios publicadas vigentes»);
retirarla o no es una decisión de activación del propietario.

## Pruebas y evidencia

- PHP, SQLite `:memory:`: `tests/Feature/ServiciosContenidoTest.php` (roles, ETag, validación, orden, activación, vigencia, imágenes válidas e inválidas, reemplazo, huérfanos,
  imágenes compartidas, IDOR entre fichas, auditoría, rutas, borradores ausentes, escape, semilla idempotente y **paridad exacta** entre el HTML dinámico del contenido inicial y el estático).
  Se ajustó un filtro de `CatalogoWebServiciosTest` (la página de soporte realiza ahora una lectura propia adicional).
- Frontend (`npm test`): `tests/frontend/admin-servicios.test.mjs` cubre multipart con `If-Match`, edición, borrado, portada, borradores pendientes, 412, 413 y URLs seguras.
- Edge (headless): `tests/browser/servicios-contenido-smoke.mjs` (fixture `tests/Support/servicios-contenido-browser-router.php`, `127.0.0.1:8775`, SQLite en memoria):
  3 páginas × 390/768/1440 px, dinámico idéntico al estático en texto, imágenes y geometría, sin desbordes con textos extremos, sin excepciones JS ni destinos externos.
- Límites: SQLite en memoria no prueba PostgreSQL (DDL, `CHECK`, bloqueos, concurrencia); no se probó el enlace `storage:link` ni la entrega real de imágenes subidas por HTTP.

## Auditoría correctiva del 02/11/2026: diseño funcional vigente

El propietario definió que las tres páginas siguen siendo informativas y que la solicitud de cotización abrirá el chatbot Python con el servicio como contexto. El personal no deberá reconstruir estas páginas mediante tarjetas. Esta decisión sustituye la propuesta editorial de este documento; se conserva su descripción anterior como evidencia histórica. No se retiró ni ejecutó la migración `000033` ni el seeder, y no se activó ningún catálogo.

### Reutilización y candidatos, sin eliminarlos

| Componente | Evaluación |
| --- | --- |
| `ServicioTecnologico`, código estable, permisos, auditoría, ETag y bloqueo del módulo confirmado | Reutilizables como identidad y controles del servicio. Preservar los contratos de los commits anteriores. |
| `PaginasServicios` | Reutilizable como mapa cerrado entre `CABLEADO`, `VIDEOVIGILANCIA`, `SOPORTE` y las rutas informativas. |
| Migración `000033`, `ServicioPublicacionElemento`, requests de elementos/portada, `ImagenesServicios` | Candidatos a retirada o adaptación. Son estructuras editoriales; una tarjeta no equivale a un concepto cotizable ni contiene por sí sola cantidades, unidades, precios, impuestos o vigencia comercial. No trasladarlos automáticamente a Cotizaciones. |
| Seeder de contenido y parciales `dinamico-*` | Candidatos a retirada tras decidir la separación. Mantener los originales estáticos. No importar ni publicar contenido. |
| `ContenidoPublicoServicios`, controladores públicos y exclusión de tres códigos del catálogo genérico | Revisar conjuntamente al retirar la edición de páginas: cambiar solo el lector o el filtro puede ocultar fichas o dejar referencias rotas. |
| Ampliaciones en `admin-servicios.mjs`, editor, respuestas JSON y rutas de elementos/imágenes | Candidatas a retirada coordinada con modelos, pruebas y esquema; conservar el panel de Servicios ya confirmado. |
| Pruebas y smokes de contenido | Conservar como evidencia del comportamiento actual. Adaptarlos al diseño definitivo cuando se autorice su implementación; no convertir la ausencia del editor en un fallo ni retirar controles de seguridad para obtener un resultado verde. |

### Apertura del chatbot Python: contrato pendiente

La ruta pública de chat actual recibe `mensaje` y `session_id` en `ChatWidgetController` y usa `AsistenteVirtualService` (motor PHP). `HorusAdvisorClient` es una integración opcional que envía consultas predefinidas, sin texto libre, historial ni sesión. No es todavía un proxy conversacional completo. Python acepta `message`, `session_id` e `history` en `ChatRequest` y rechaza campos adicionales; no acepta un campo arbitrario `servicio`.

La adaptación requiere acordar un evento de apertura y preselección en el cliente, validar el código del servicio en servidor y traducirlo a un contexto autorizado para Python. El token del servicio debe permanecer en Laravel. Abrir el widget no debe registrar una cotización ni enviar automáticamente un mensaje. Deben verificarse servicio correcto, cambio de contexto, doble clic, indisponibilidad del motor y conservación del borrador. La retirada del motor PHP queda para su fase independiente.

Los archivos de cliente del chat, `ChatWidgetController`, `AsistenteVirtualService`, `HorusAdvisorClient` y los contratos Python están fuera de los 61 autorizados: no se modificaron. La futura adaptación deberá revisar también las tres vistas informativas y sus pruebas. No se inventó ni habilitó un endpoint nuevo.

### Corrección acotada comprobada

Una excepción al comprobar el esquema escapaba al respaldo estático porque esas consultas estaban fuera del `try`. Una prueba de regresión reprodujo el fallo y ahora verifica respuesta 200 con contenido estático y un registro de incidente sin SQL ni detalles privados. Se movieron únicamente las comprobaciones de esquema dentro del manejo existente de errores. La evidencia sigue limitada a SQLite en memoria.

Pendientes operativos: entrega HTTP de imágenes subidas (`public/storage` ausente), aceptación PostgreSQL y comprobación del límite inferior de `orden` en el DDL de `000033`. Ninguno se resolvió mediante cambios de entorno o conexiones reales.
