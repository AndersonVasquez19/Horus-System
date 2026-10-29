# Sprint 17 — backend editorial y preparación de publicación

Verificación local: 28 de octubre de 2026. No se activó el catálogo, no se publicó contenido real y no se modificó frontend.

## A. Estado inicial comprobado

- Rama `anderson`, HEAD `8fc4174790be7d8df38b8858cb8c9825c4e237ea`.
- Línea base ejecutada: **882 pruebas PHP, 5531 aserciones**, 95,78 s, SQLite `:memory:`.
- Al comenzar había 17 archivos versionados modificados: `CLAUDE.md`, `AsistenteVirtualService`, documentación de PostgreSQL/Sprint 15, `phpunit.pgsql.xml`, pruebas y salvaguardas. También estaban sin seguimiento los tres servicios, configuración, pruebas y documento del Sprint 16, `chatbot-python/` y `docs/postgresql-l2.3-testing.md`.
- Se registraron hashes SHA-256 de los 76 archivos preexistentes modificados o sin seguimiento, expandiendo el directorio Python. Se compara ese inventario al cerrar; no se incluyen entornos ignorados ni se imprimen sus valores.
- Se leyeron `AGENTS.md`, `CLAUDE.md`, contratos reales y pruebas relacionadas. El documento del Sprint 09 conserva su cierre histórico y advierte al inicio que la conexión pública fue retirada; el estado vigente se comprobó en `routes/web.php` y las vistas.

## B. Inventario funcional dirigido

| Componente | Estado real y reutilización |
| --- | --- |
| `Curso`, tabla `cursos` | Administración académica; datos internos, precio y cupos. `SoftDeletes`. `activo` no autoriza publicación |
| `Matricula`, tabla `matriculas` | Relación por `curso_id`; vigente `pendiente/pagada`, cancelada `anulada`. No se modifica desde el flujo editorial |
| `CursoPublicacion`, tabla `curso_publicaciones` | Ficha pública separada, única por curso. FK restringe borrado físico del curso; autor nullable al eliminar usuario. No se copia automáticamente información administrativa |
| `Admin/CursoController` | CRUD administrativo y restricciones de cupos existentes, sin cambios en este sprint |
| `Admin/CursoPublicacionController` | Editor, autorización y retiro. Se endurecen sus operaciones existentes |
| `PublicarCursoRequest` | Permiso `cursos.publicar`, lista explícita de campos, modalidad, fechas y consentimiento. Se conserva |
| `RolesAndPermissionsSeeder` y migración `000021` | Permiso existente `cursos.publicar`; solo admin por defecto. No se crean permisos ni se ejecutan seeders reales |
| Políticas | No hay Policy editorial dedicada. El control se realiza con middleware de rol del grupo admin, middleware de permiso y autorización del FormRequest |
| `CatalogoPublicoCursos::vigentes()` | Lector del Sprint 09, reutilizado sin cambiar su proyección ni comportamiento del chatbot |
| `CatalogoPublicoCursos::paraWeb()` | Contrato nuevo de servicio, desactivado y sin ruta pública. Comparte consulta y proyección con el lector existente |
| `CursosPublicosController` | Existe pero sigue sin ruta activa. No se modifica. Su implementación anterior utiliza `vigentes()`, no el nuevo interruptor web |
| Exportador, firma y cliente Advisor del Sprint 16 | Permanecen intactos; regresiones HMAC, vigencia y retiro ejecutadas |
| Vistas del panel | Existe `admin/cursos/publicacion.blade.php`; index/show/edit no enlazan al editor. No se modifican |
| Web | `/educacion/cursos` y `/educacion/capacitaciones` siguen siendo `Route::view` estáticas |

## C. Cambios propios de este sprint

1. `app/Http/Controllers/Admin/CursoPublicacionController.php`:
   - Relee al usuario, su rol de acceso al panel y `cursos.publicar` después de bloquear el curso y antes de escribir; no depende de relaciones cargadas antes de una revocación.
   - Repetir el mismo contenido con el mismo autor no cambia `autorizado_at`, timestamps ni auditoría. Un autor distinto sí genera una nueva autorización explícita.
   - Retirar una ficha ausente o ya retirada no crea contenido, no toca marcas y no genera una auditoría ficticia.
   - Ficha y auditoría siguen en la misma transacción, con bloqueo del curso común a publicación y retiro.
   - GET del editor conserva la misma vista y variables, añadiendo `ETag` y `Cache-Control: no-store, private` como metadatos HTTP.
   - PUT/DELETE aceptan opcionalmente una precondición `If-Match`; se compara dentro del bloqueo antes de modificar.
2. `app/Services/CatalogoPublicoCursos.php`: nueva lectura `paraWeb()`, reutilizando los filtros/proyección existentes y añadiendo procedencia editorial actual para la futura web. `vigentes()` conserva su contrato anterior.
3. `config/catalogo-publico.php`: interruptor literal `enabled=false`, independiente de chatbot y Advisor.
4. `tests/Feature/Sprint17PublicacionTest.php`: regresiones de repetición, autorización tardía, precondiciones, lectura, privacidad estructural, integridad académica y rollback.
5. Este documento.

No se modifican modelo, esquema, requests existentes, rutas, vistas, exportador, firma, cliente Advisor ni chatbot PHP. No se incorporan librerías, estados ni servicios editoriales paralelos.

## D. Ciclo editorial y permisos

| Situación | Representación existente | Lectura pública preparada |
| --- | --- | --- |
| Borrador / sin autorización | Curso administrativo sin ficha autorizada; puede existir ficha con `publicada=false` | Excluido |
| Pendiente | No existe workflow de aprobación editorial separado | No se inventa un estado ni cola |
| Programado | `publicada=true`, `vigente_desde` futura | Excluido hasta el inicio |
| Publicado | Curso activo no eliminado, ficha autorizada, ventana vigente | Incluido al habilitar expresamente la lectura |
| Actualización autorizada | PUT con permiso y consentimiento; autor y fecha asignados en servidor | Nueva versión de contenido en la siguiente lectura, sin caché de catálogo |
| Caducado | `vigente_hasta` anterior al día actual de Lima | Excluido sin job ni mutación automática de `publicada` |
| Retirado | DELETE marca `publicada=false` | Excluido; ficha e identidad se conservan |
| Inactivo/archivado/eliminado | Estado administrativo distinto de activo o borrado lógico | Excluido |

La representación de borrador no equivale a un editor de borradores parciales: no hay endpoint de guardado parcial ni columnas nullable para ese flujo. No se añade porque no forma parte del contrato actual de la vista. La fecha de fin es inclusiva por días completos de `America/Lima`; el siguiente inicio de día invalida la lectura. No se cambia el registro académico ni se anulan matrículas.

- Admin: edición administrativa y publicación, por permisos de la matriz actual.
- Coordinador: puede editar cursos administrativos; no puede publicar salvo concesión explícita existente.
- Secretaría: lectura administrativa según la matriz; no publica por defecto.
- `cursos.editar` no implica `cursos.publicar`. Un permiso directo sin rol de acceso al panel tampoco habilita las rutas.
- El editor exige `cursos.publicar`, incluso para GET. No se crea un editor editorial distinto para personal sin ese permiso.
- PUT continúa tomando únicamente los campos validados, ignora IDs/precios/cupos/autor enviados por el cliente y exige consentimiento. Los datos públicos se redactan explícitamente, no se rellenan desde `Curso`.
- Los eventos `curso_publicado`/`curso_despublicado` se registran solo cuando existe cambio efectivo y sin copiar textos ni datos privados en auditoría. Un fallo de auditoría revierte la ficha.

### Endpoints administrativos existentes

| Método y ruta | Nombre | Resultado |
| --- | --- | --- |
| GET `/admin/cursos/{curso}/publicacion` | `admin.cursos.publicacion.edit` | Misma vista `admin.cursos.publicacion`, variables `curso` y `publicacion`; nuevo ETag opaco |
| PUT `/admin/cursos/{curso}/publicacion` | `admin.cursos.publicacion.update` | Publica/actualiza; redirige al editor |
| DELETE `/admin/cursos/{curso}/publicacion` | `admin.cursos.publicacion.destroy` | Retira; redirige al editor |

El grupo conserva sesión y rol `admin|coordinador|secretaria`, y el controlador exige permiso. GET/DELETE conservan binding `withTrashed` para retirar una ficha cuyo curso fue eliminado; PUT no admite cursos eliminados. No se crean ni cambian rutas.

### Precondiciones de actualización

El ETag tiene forma `"<64 hex minúsculos>"`, calculado desde el ID del curso y la ficha completa leída. No incluye valores administrativos en claro. Identifica el estado de esa ficha; no es un token de sesión ni una autorización.

Un cliente backend puede leer el ETag de GET y enviar **un único ETag fuerte** en `If-Match` para PUT o DELETE. Si la ficha cambió, se responde **412** antes de escribir; formato inválido, ETag débil, wildcard o listas producen **422**. Este contrato conservador admite una única revisión exacta, no todas las variantes de precondiciones HTTP. No se devuelve el contenido actual al cliente obsoleto. También se detecta que otro cliente creó la primera ficha después de abrir un editor sin publicación y que se intenta reutilizar la revisión para otro curso.

**Sin `If-Match` se conserva el formulario actual** y el orden de escritura bajo bloqueo. Eso impide registros duplicados e inconsistencias parciales, pero no detecta que una persona envió un formulario antiguo: prevalece la última escritura autorizada. No se declara que el editor visual ya tenga protección contra pérdida de cambios. Hacer que envíe y gestione la precondición requiere autorización de frontend; no se exige una cabecera que la vista actual no sabe enviar.

Las simulaciones de revocación y ediciones sucesivas son regresiones de lógica y precondiciones; no reproducen procesos PostgreSQL concurrentes. La relectura del permiso no bloquea simultáneamente tablas de roles/permisos ni garantiza atomicidad frente a una revocación que se confirme después de esa comprobación.

## E. Campos disponibles y campos pendientes

| Información solicitada | Situación de la ficha pública |
| --- | --- |
| Nombre | `titulo`, público y explícito; no `Curso.nombre` |
| Descripción | `resumen`, hasta 500 caracteres; no descripción administrativa |
| Categoría | Solo hay categoría administrativa de texto libre; no se exporta ni se interpreta como tipo de oferta |
| Objetivos | No hay campo editorial estructurado |
| Nivel | No hay campo editorial estructurado |
| Modalidad | `modalidad` editorial; presencial, semipresencial, virtual o híbrido |
| Requisitos | Puede haber orientación pública en `inscripcion`; no hay lista estructurada de requisitos |
| Duración | Existe `Curso.duracion_horas` administrativo, sin autorización editorial independiente; no se publica |
| Estado editorial | `publicada` y fechas, combinado con estado/borrado del curso |
| Vigencia | `vigente_desde`, `vigente_hasta`, días inclusivos de Lima |
| Imagen autorizada | No hay campo editorial ni flujo de autorización/almacenamiento de imagen |
| Contacto | `inscripcion` permite orientación pública explícita; no representa un contacto de estudiante ni cliente. Los canales fijos de la web no se importan |

El contrato inicial de título, resumen, modalidad, horario, inscripción y vigencia no necesita una migración adicional. No se crean columnas sin mecanismo aprobado para capturarlas ni datos fiables para llenarlas.

Si se aprueba una ficha enriquecida, la propuesta es **ampliar `curso_publicaciones` con campos públicos opcionales**, sin backfill desde datos internos: categoría pública, objetivos, nivel con vocabulario acordado, requisitos estructurados, duración textual e imagen autorizada. Harán falta migración aditiva compatible con filas existentes, validaciones, permiso editorial, tratamiento seguro de imágenes, campos de formulario y actualización versionada del contrato de lectura. La decisión sobre listas/vocabularios y el origen de imágenes debe preceder a escribir esa migración. No se implementa ni aplica en este sprint.

### Cursos y capacitaciones

No se encontró modelo, tabla o controlador específico de capacitaciones ni entidades de edición/convocatoria. La matrícula actual referencia directamente un `Curso`. La categoría administrativa no establece una distinción contractual entre curso y capacitación, y la modalidad describe entrega, no tipo de oferta.

La página estática de capacitaciones describe programas empresariales y personalizados. Eso no demuestra que todos usen matrícula individual y cupos ni justifica crear un segundo módulo académico. Los cursos de este backend siguen representándose como cursos; no se reetiquetan automáticamente.

Para una capacitación con las mismas reglas de matrícula puede reutilizarse `Curso` y su ficha; si se necesita separarla en la web, acordar un discriminador editorial explícito con valor predeterminado compatible `curso` y autorización independiente para cambiarlo. Una capacitación empresarial cotizada para un grupo requeriría primero definir solicitante, presupuesto, participantes y relación académica; esos datos privados nunca deben incorporarse a la ficha. La evidencia actual no permite implantar esa diferencia sin una decisión funcional. No se crea módulo ni migración de capacitación por suposición.

## F. Contrato de lectura web preparado

Entrada interna: `app(CatalogoPublicoCursos::class)->paraWeb()`. **No es una API pública ni tiene endpoint nuevo.**

Con el valor predeterminado devuelve:

```json
{"version":"1.0","habilitado":false,"total":0,"cursos":[]}
```

En pruebas aisladas, `config(['catalogo-publico.enabled' => true])` habilita la proyección. La lista contiene exactamente:

`titulo`, `resumen`, `modalidad`, `horario` nullable, `inscripcion` nullable, `vigente_desde`, `vigente_hasta`.

`version` versiona el contrato, no es una revisión de distribución. `total` es el número total incluido, sin recorte ni paginación; no se oculta un tope. Orden: título público y después ID editorial para desempate. Sin contenido hay lista vacía y total cero. Las lecturas sucesivas sin cambios devuelven el mismo resultado; no se introduce una caché que conserve retiradas o vencidas.

Además de la vigencia y elegibilidad administrativa existentes, esta lectura exige autorización no futura y un autor todavía existente con `cursos.publicar`. Si falta el permiso del sistema, devuelve vacío. El autor/IDs/metadatos de autorización solo se usan para filtrar y no forman parte de la respuesta. El `vigentes()` anterior conserva exactamente su lista y sus reglas para no cambiar el chatbot; el exportador del Sprint 16 ya aplica la comprobación adicional de autor.

Se excluyen datos académicos, estudiantes, matrículas, precios/cupos/horarios internos, autor, IDs de usuarios, cotizaciones y clientes mediante una proyección explícita. Los textos editoriales son contenido previamente autorizado por una persona; no se promete detectar semánticamente datos privados que alguien escriba dentro de un campo público. La futura vista debe escaparlos como texto, nunca renderizarlos con HTML sin escape. El exportador Advisor conserva sus filtros conservadores de privacidad y puede rechazar texto que necesite revisión adicional.

Los tres interruptores permanecen apagados e independientes:

- `catalogo-publico.enabled=false`: preparación de lectura para web.
- `chatbot.public_catalog_enabled=false`: catálogo del chatbot PHP.
- `horus-advisor.enabled=false`: integración FastAPI.

Cambiar un interruptor no cambia rutas. Incluso habilitar solo la lectura interna no conecta la página actual. No se debe enrutar directamente el controlador público antiguo como si ya aplicara el nuevo interruptor; en la futura conexión deberá adaptarse explícitamente para consumir `paraWeb()` y respetar su activación.

## G. Conexión de frontend pendiente de autorización

| Archivo exacto | Cambio mínimo propuesto y contrato que lo permite |
| --- | --- |
| `resources/views/admin/cursos/index.blade.php` | Añadir acción «Publicación» por fila, bajo `@can('cursos.publicar')`, dirigida a `route('admin.cursos.publicacion.edit', $curso)`. Es indispensable para descubrir el editor desde el listado; no cambia el menú global |
| `resources/views/admin/cursos/show.blade.php` | Opcionalmente, el mismo enlace condicionado desde el detalle. No es necesario modificar simultáneamente listado y detalle para habilitar el primer acceso |
| `resources/views/admin/cursos/publicacion.blade.php` | La vista existente ya funciona por URL y no requiere cambios para publicar. Para precondiciones desde navegador, deberá capturar la revisión y enviarla/gestionar 412 mediante un cambio autorizado de presentación/cliente; hoy no lo hace |
| `resources/views/pages/educacion/cursos.blade.php` | Tras autorización independiente, representar los siete campos de `paraWeb()` con escape y estado vacío, conservando estilos, clases y contenido fijo aprobado |
| `app/Http/Controllers/CursosPublicosController.php` y `routes/web.php` | En esa fase autorizada, adaptar el controlador al contrato/interruptor y conectar la ruta conservando URL/nombre. Aunque sean PHP, cambian contenido visible y requieren autorización específica |
| `resources/views/pages/educacion/capacitaciones.blade.php` | Sin modificación prevista hasta acordar representación de capacitaciones; no sustituirla por cursos por inferencia |

No es necesario modificar CSS, imágenes, tipografías ni el menú global para añadir el primer enlace al editor. Ninguno de estos cambios visuales o de enrutamiento se implementó aquí. Las rutas administrativas existentes ya validan el permiso en servidor; ocultar el enlace futuro no será el control de acceso.

## H. Compatibilidad con Horus AI Advisor

No se modifica ningún archivo del Sprint 16 ni `chatbot-python/`. El exportador sigue construyendo únicamente fichas autorizadas vigentes; conserva ID estable, lista explícita de campos, timestamps, versión y HMAC canónico. Los retiros y vencimientos quedan fuera de una exportación nueva. Las operaciones repetidas sin cambio no renuevan artificialmente la autorización editorial.

La prueba nueva compara lectura web y exportación después de publicar, retirar y vencer; comprueba también la firma. Las pruebas completas del Sprint 16 siguen siendo parte de la regresión.

No se distribuyen snapshots ni se activa FastAPI. Python puede cargar un archivo al arrancar, pero la validación HTTP no lo ingiere. Siguen pendientes tombstones, registro de distribución/versiones, acuse de recarga e invalidación temporal en ejecución. Una exportación nueva no revoca copias ya cargadas; el cliente Laravel sigue rechazando un catálogo cargado en Python mientras ese protocolo no sea seguro.

## I. Validación y límites

- Pruebas nuevas ejecutadas primero contra el código anterior: fallos reproducidos en repetición/auditoría y autorización tardía, además de ausencia de los contratos nuevos. Se implementaron las correcciones y se repitieron las regresiones.
- Perfil: `phpunit.xml`, SQLite `:memory:`, sesión/caché `array`, cola `sync`, correo `array`. Las pruebas nuevas comprueban el destino aislado y bloquean HTTP no simulado.
- Pruebas específicas: `php artisan test --compact --filter='Sprint17PublicacionTest|CursoPublicacionTest|AdvisorCatalogoTest|HorusAdvisorTest|AsistenteVirtualTest|ChatbotHybridTest|AdminAuthorizationTest|Sprint12AuthSecurityTest'`: **305 aprobadas, 1501 aserciones**, 39,07 s.
- Suite completa: `php artisan test --compact`: **918 aprobadas, 5765 aserciones**, 99,87 s. Son **36 pruebas y 234 aserciones nuevas** respecto de la línea base; sin fallos pendientes en los comandos ejecutados.
- `php vendor/bin/phpstan analyse --no-progress`: **nivel 6, sin errores**. Se corrigieron los hallazgos introducidos durante la implementación, sin supresiones ni cambios de configuración.
- `php vendor/bin/pint --test app/Http/Controllers/Admin/CursoPublicacionController.php app/Services/CatalogoPublicoCursos.php config/catalogo-publico.php tests/Feature/Sprint17PublicacionTest.php`: **aprobado**, tras formatear únicamente esos cuatro PHP.
- `git diff --check`: **aprobado**; solo avisos LF/CRLF conocidos.
- Comparación SHA-256: **76 de 76 archivos preexistentes idénticos**; incluye el trabajo del Sprint 16 y los archivos Python no ignorados. El índice permanece vacío y rama/HEAD no cambiaron.
- Revisión de rutas y zonas protegidas: `git diff --name-only -- resources/views resources/css resources/js public routes` **sin resultados**. Inventario propio final: los cinco archivos de la sección C.

SQLite no demuestra el bloqueo ni concurrencia de PostgreSQL. No se ejecutaron migraciones, consultas ni escrituras sobre PostgreSQL habitual, ensayo o S7. La migración editorial existente `2026_10_28_000021` se ejecutó únicamente como parte del esquema SQLite de pruebas; su presencia en producción/desarrollo real no fue consultada ni se presume aplicada. No hay migraciones nuevas de este sprint.

No se ejecutaron navegador, build o pruebas JavaScript porque no se modificó frontend ni se autorizó conectarlo. No se hicieron llamadas reales a FastAPI, OpenAI o Resend, no se activó verificación de correo y no se modificaron módulos excluidos. No se hicieron commits, pushes, despliegues ni operaciones con stashes.

## J. Orden posterior propuesto

1. Autorizar específicamente el enlace condicionado en `resources/views/admin/cursos/index.blade.php`; reutilizar GET y permiso existentes. No hace falta rediseño ni módulo nuevo.
2. Autorizar por separado el consumo de fichas en la página de cursos, preservando diseño y contenido fijo. Antes de activarlo, revisar fichas y confirmar la migración existente en un entorno autorizado, sin aplicar todas las migraciones pendientes de forma genérica.
3. Integrar el manejo visual de precondiciones para evitar sobrescrituras desde editores antiguos; probar conflicto y conservación del contenido escrito por el usuario.
4. Acordar si la capacitación usa matrícula/cupos existentes o una solicitud comercial; después definir tipo editorial y campos opcionales, con migración aditiva sin poblar/publicar automáticamente.
5. Resolver el protocolo de retirada/recarga con Python bajo autorización propia antes de activar catálogos Advisor.
6. Servicios tecnológicos: requieren modelo y autorización editorial, permisos con migración, validaciones, almacenamiento y captura en panel; no importar automáticamente las páginas fijas. Cotizaciones: requieren separar solicitud pública de presupuesto privado, autorización, estados, idempotencia y auditoría. Ninguno de esos módulos ni sus datos se añade a este sprint.
