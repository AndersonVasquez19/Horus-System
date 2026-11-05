# Sprint 16 — preparación backend de Horus AI Advisor

> Documento histórico. La corrección Fase 4C sustituye la selección conservadora y el fallback PHP descritos aquí: `HORUS_ADVISOR_ENABLED` se lee en `config/horus-advisor.php` y vale `false` por defecto. Al cambiarlo, debe regenerarse la configuración cacheada mediante el procedimiento operativo autorizado; el servicio consume `config()`, nunca `env()` en ejecución. Variables de servidor: `HORUS_ADVISOR_URL` (loopback, puerto 8001), `HORUS_ADVISOR_TOKEN` (secreto de al menos 32 caracteres) y `HORUS_ADVISOR_SIGNING_KEY` (independiente, para validación en seco; no activa sincronización). A-07 actualiza solo `.env.example` con variables Advisor apagadas y secretos vacíos; no modifica `.env` real. El endpoint real es `POST /api/v1/chat` y el modelo local es `paraphrase-multilingual-MiniLM-L12-v2`. Con Advisor apagado o inválido, solo hay contingencia institucional; no FAQ PHP ni OpenAI. El probador administrativo usa ese mismo flujo, con deduplicación aislada del público. Las intenciones históricas no gobiernan Python. No se guarda contexto conversacional. H-11 y H-12 del informe son informativos: la deduplicación identifica ahora texto exacto por HMAC; se conservan los presupuestos existentes (2,5 s Laravel y 5 s Python) sin afirmar cancelación del trabajo Python al abandonar Laravel.

Fecha de verificación local: 28 de octubre de 2026. Integración desactivada. No se publicaron fichas reales, no se inició Python ni se ejecutaron migraciones reales.

## A. Estado inicial y alcance

- Rama `anderson`; HEAD `8fc4174790be7d8df38b8858cb8c9825c4e237ea`.
- Línea base ejecutada antes de editar: `php artisan test --compact`: **809 pruebas, 5166 aserciones**, 169,61 s, SQLite `:memory:`.
- Cambios preexistentes conservados: `CLAUDE.md`, `docs/postgresql-l2.2-c-integridad.md`, `docs/sprint-15-preparacion-integracion.md`, `phpunit.pgsql.xml`, `tests/TestCase.php`, `tests/PostgresIntegrity/Concerns/RequiresIsolatedPostgres.php` y las pruebas Feature `BackendPerformanceTest`, `BackendRobustnessTest`, `CursoPublicacionTest`, `MatriculaNotificationBacklogTest`, `MatriculaNotificationTest`, `MatriculaOperationsTest`, `Sprint12AtomicityTest`, `Sprint12AuthSecurityTest`, `Sprint13RegistrationSecurityTest`, `Sprint14EmailVerificationTest`.
- Sin seguimiento al comenzar: `chatbot-python/` (trabajo de Claude) y `docs/postgresql-l2.3-testing.md`. No se editaron ni añadieron al índice.
- Se leyeron `AGENTS.md`, `CLAUDE.md`, README Python, esquemas, autenticación, rutas, cargador y motor. La verificación funcional fue exclusivamente PHP con HTTP simulado. Se utilizó Python estándar, sin importar el proyecto ni iniciar servicios, para calcular un vector HMAC independiente.
- No se editaron frontend, rutas, modelos administrativos, migraciones, entornos, credenciales ni módulos excluidos. No se hicieron commits, pushes ni operaciones con stashes.

## B. Inventario propio

| Archivo | Función |
| --- | --- |
| `app/Services/ExportadorCatalogoAdvisor.php` | Construcción autorizada de snapshot en memoria, sin escribir archivos ni transmitir |
| `app/Services/FirmaCatalogoAdvisor.php` | Canonización y firma compatibles con Python |
| `app/Services/HorusAdvisorClient.php` | Transporte Laravel, readiness, validación, adaptación y validación en seco del catálogo |
| `config/horus-advisor.php` | Configuración separada; `enabled=false` literal |
| `app/Services/AsistenteVirtualService.php` | Rama opcional de orientación, antes del OpenAI existente |
| `tests/Feature/AdvisorCatalogoTest.php` | Autorización, publicación, privacidad, identidad y firma |
| `tests/Feature/HorusAdvisorTest.php` | Transporte simulado, fallos, controles, fallback y contrato público |
| Este documento | Contratos, diagnóstico, evidencia y pendientes |

No se agregó endpoint de administración del catálogo, comando de publicación, scheduler, CRUD ni permiso nuevo. El contenedor resuelve los servicios automáticamente.

## C. Comunicación y fallback

### Contrato real inspeccionado

| Endpoint Python | Contrato y uso en este sprint |
| --- | --- |
| `GET /health` | `status=ok`; solo vida del proceso, no disponibilidad. Inspeccionado, no usado por la orquestación |
| `GET /ready` | `status`, `knowledge`, `semantic`, `auth`, `catalog`. Se consulta antes de cada orientación no cacheada |
| `GET /api/v1/info` | Versión, capacidades, límites y `catalog_version`. Inspeccionado; no se usa para cargar catálogos |
| `POST /api/v1/chat` | `message`, `session_id` opcional, `history`; devuelve respuesta, fuentes, recomendaciones, contexto y abstención |
| `POST /api/v1/recommend` | Perfil con intereses, objetivo, nivel, modalidad y público. Inspeccionado; no se expone ni consume en este sprint |
| `POST /api/v1/catalog/validate` | Validación en seco autenticada; devuelve `valid`, `signature`, `errors`, `summary`, `loaded=false` |

El límite efectivo por defecto es 2000 caracteres y 10 mensajes de historial; los máximos declarados en Pydantic admiten hasta 4000 y 30, pero sus validadores consultan la configuración. Laravel **no envía historial ni session_id**.

Las rutas `/api/v1/*` exigen `X-Horus-Service-Token`. Sin un token configurado de al menos 32 caracteres, Python responde 503; con token incorrecto, 401. El cliente exige token antes de cualquier HTTP, incluido readiness.

### Configuración sin activación

`config/horus-advisor.php` incorpora:

- `enabled=false`: no hay variable de entorno que lo active accidentalmente.
- `HORUS_ADVISOR_URL`, por defecto `http://127.0.0.1:8001`.
- `HORUS_ADVISOR_TOKEN`, sin valor predeterminado.
- `HORUS_ADVISOR_SIGNING_KEY`, sin valor predeterminado, independiente del token de servicio.
- Conexión 0,4 s; presupuesto total compartido 2,5 s para ready y chat, tope defensivo 3 s; respuesta hasta 65536 bytes.

No se modificó ningún `.env`. En pruebas se usa exclusivamente `config([...])`. La futura configuración de secretos y activación requiere autorización del propietario.

Se acepta exclusivamente `http://127.0.0.1:PUERTO` con puerto válido y sin path, consulta, fragmento, credenciales ni hostname. Se rechazan DNS, destinos externos, otras IP y URL de metadatos. Redirecciones, proxy y cookies deshabilitados; sin reintentos. Se solicita codificación `identity`, no se descomprime contenido y se rechaza compresión en las cabeceras. Los callbacks de cabeceras/progreso abortan descargas excesivas; se comprueba también el cuerpo antes de decodificar JSON. Las pruebas de callbacks son simulaciones, no una medición de memoria del transporte real.

HTTP 3xx, 401, 403, 422, 429, 500, 503 y cualquier otro estado no exitoso, timeout, conexión fallida, JSON malformado, tipo de contenido incorrecto y contratos incompatibles producen `null`. No se registran cuerpos, conversaciones, tokens ni excepciones de transporte.

### Selección conservadora

1. Se conserva `POST /chat/mensaje`, sesión, CSRF, validación, throttle, bloqueo por sesión, caché, presupuestos y respuestas PHP.
2. Las FAQ se resuelven primero en PHP. También se preserva la prioridad del catálogo PHP cuando se activa explícitamente en pruebas.
3. Solo se intenta Advisor en scope `public:`, con el flag habilitado, todas las intenciones incorporadas activas y una intención de orientación inequívoca del conjunto existente `TEMAS_OPENAI`.
4. Consultas sensibles, bloqueadas, ambiguas, sobre datos cambiantes o sin tema permitido siguen el flujo PHP. El panel administrativo no envía consultas a Python.
5. Laravel construye una frase fija a partir del tema permitido; nunca envía texto libre, nombres, datos de estudiantes, documentos, cotizaciones, matrículas, sesión ni historial. Se sacrifica detalle conversacional deliberadamente en esta preparación.
6. Readiness debe informar `ready`, conocimiento cargado, autenticación configurada y **`catalog=none`**. El modo semántico puede estar cargando, deshabilitado o indisponible: Python dispone de modo léxico.
7. Se acepta respuesta no vacía, de hasta 1800 caracteres, no abstenida, sin solicitud de aclaración, con coincidencia alta/media, modo conocido y fuentes con identificadores y rutas locales admitidas. Se rechazan fuentes `cat_*`, enlaces externos, HTML, datos de contacto libres o marcadores de secretos. Las recomendaciones deben referenciar fuentes/evidencia presentes y tipos conocidos.
8. La adaptación conserva exactamente `respuesta`, `intent`, `fuente`; la fuente opcional es `advisor`, y `intent=null` evita incorporar taxonomía Python al contexto PHP. No se agregan tarjetas ni otros elementos visuales.
9. Si falla o se abstiene, se continúa una sola vez por el OpenAI/fallback PHP existente. No hay acciones comerciales ni escrituras comerciales. Los duplicados utilizan la caché y el bloqueo existentes. La clave de caché distingue la integración habilitada y su configuración, sin guardar secretos en la clave visible.

No se agregó circuit breaker global: cada consulta distinta elegible puede esperar hasta el presupuesto configurado; el bloqueo y la deduplicación existentes limitan repeticiones. Un circuito con métricas y recuperación puede implementarse después de medir demanda, sin afectar el comportamiento predeterminado.

## D. Catálogo editorial y firma

### Fuente y autorización

`ExportadorCatalogoAdvisor::construir(User $actor)` exige `cursos.publicar` mediante Gate. Consulta exclusivamente la ficha editorial y usa el curso administrativo solo para comprobar `activo` y ausencia de borrado lógico.

Exige `publicada=true`, vigencia de fechas inclusiva en Lima, autorización no futura, autor presente y permiso de publicación actual del autor. Si se elimina el autor o pierde el permiso, la ficha deja de exportarse. Este último control es deliberadamente más restrictivo que el lector PHP existente, que no se modificó.

Una ficha sin autorización, borrador/despublicada, retirada, caducada, futura, curso inactivo, archivado o eliminado no genera ítem. El modelo actual no tiene enum editorial ni estado `cancelado`: se permiten solamente cursos `activo`, excluyendo cualquier otro valor.

No se exportan servicios ni capacitaciones como entidades distintas: no existe modelo editorial que las respalde. No se convierten vistas estáticas en registros. Tampoco se deduce el tipo de un curso desde su categoría administrativa.

### Lista de campos permitidos

Cada ítem contiene únicamente `stable_id`, `type=curso`, `publication_status=vigente`, `title`, `summary`, `details`, `modalities`, `schedule_text`, `updated_at`, `valid_from`, `valid_until`.

- Título, resumen, horario e inscripción proceden exclusivamente de la ficha editorial. La inscripción pública se representa en `details`, porque admite 400 caracteres y `inquiry` Python solo 200.
- No se inventa URL de ficha: la página de cursos actual es estática.
- `hibrido` no es un enum de modalidad Python. Se preserva como texto de modalidad publicada en `details`, con `modalities=[]`; no se equipara a semipresencial o virtual.
- Se rechazan fichas con longitudes incompatibles, HTML o patrones de datos privados/contactos/secreto. Esta validación conservadora puede rechazar contenido editorial legítimo: requiere revisión, nunca se relaja automáticamente. No sustituye revisión humana de la autorización editorial.
- No se incluye precio administrativo, duración, código, cupos, nombres internos, autor, IDs de usuarios, estudiantes, matrículas, clientes ni cotizaciones. No hay lista de exclusión como mecanismo de serialización: la selección SQL y la construcción del objeto son explícitas.
- Máximo 500 fichas y 1 MiB. Un exceso no genera exportación parcial. Un catálogo vacío se firma normalmente.

### Compatibilidad HMAC

Referencia: `chatbot-python/app/services/catalog.py::canonical_bytes/sign`, `schemas/catalog.py`.

- Versión de esquema `1.0`; origen `horus-laravel`.
- Versión de snapshot UTC más sufijo aleatorio; el contrato Python no tiene campo separado de ID global del catálogo.
- ID de ítem `curso:publicacion-{id}`: estable durante la vida de la ficha, sin IDs de personas. La sincronización futura debe prohibir reutilizar o eliminar físicamente estas identidades.
- Timestamps ISO-8601 con zona explícita; vigencia del snapshot de **cinco minutos**, recortada al siguiente inicio de día local si alguna ficha vence antes.
- Campos firmados exactos: `schema_version`, `catalog_version`, `source_system`, `generated_at`, `expires_at`, `items`.
- Claves ordenadas recursivamente; listas conservan orden; JSON compacto UTF-8, sin escape de Unicode, `/` ni separadores Unicode U+2028/U+2029. No se admiten floats/objetos; este exportador no publica importes.
- HMAC-SHA256, prefijo `hmac-sha256:` y 64 hexadecimales minúsculos. Clave externa de al menos 32 caracteres. Verificación con `hash_equals`.
- Prueba con vector independiente de Python estándar que incluye acentos, `/`, U+2028, claves anidadas y listas; alteraciones de cabecera o contenido invalidan la firma. La suite PHP no depende de Python para ejecutarlo.

### Sincronización NO operativa

Python **sí puede ingerir** un archivo firmado al arrancar con `HORUS_AI_CATALOG_PATH` y la clave correspondiente. `/catalog/validate` únicamente valida; no almacena, carga ni refresca. `validarCatalogo()` exige firma local correcta y confirmación remota `signature=valid`, `valid=true`, `loaded=false`, sin errores; no acepta `not_checked` como prueba de autenticidad.

La inspección confirma que `to_records()` decide vigencia/caducidad al cargar, y `KnowledgeBase.catalog_status` consulta el resumen guardado. No se observó reevaluación temporal en cada consulta ni recarga en caliente. **La expiración corta del archivo no garantiza que un proceso que ya lo cargó deje de recomendarlo al vencer.** Tampoco existe confirmación de retirada ni comparación por versión/digest en cada consulta Laravel.

El exportador es un snapshot de vigentes, no un protocolo de sincronización. No emite tombstones `retirado` porque no existe registro persistente de lo previamente distribuido ni snapshot del título autorizado antes del retiro. No se debe transmitir el texto actual de una ficha retirada que pudo haber sido editada. Omitir una ficha en una nueva exportación no invalida copias ya cargadas.

Por ello no se guarda archivo, no se envía automáticamente, no se activa catálogo y la orquestación rechaza todo readiness cuyo catálogo no sea `none`. El soporte de firma/exportación queda listo; **la sincronización activa queda pendiente**.

Antes de habilitar catálogos hacen falta: identidad duradera y no reutilizable, snapshot público anterior, tombstones de retiro, manifiesto de versión/digest, recarga atómica y acuse de versión activa, verificación de caducidad en ejecución e invalidación de respuestas cacheadas. Ante desajuste/retiro o falta de confirmación, Laravel debe bloquear respuestas del catálogo y proporcionar contingencia institucional. Esto requiere trabajo Python autorizado por separado y probablemente migraciones para el registro de distribución; no se implementó aquí.

## E. Diagnóstico administrativo comprobado

| Funcionalidad | Clasificación y evidencia | Trabajo pendiente |
| --- | --- | --- |
| Publicación de cursos desde panel | **Implementada parcialmente**. `CursoPublicacion`, `PublicarCursoRequest`, `Admin/CursoPublicacionController`, GET/PUT/DELETE `/admin/cursos/{curso}/publicacion`, permiso `cursos.publicar` exclusivo de admin por defecto. Confirmación explícita, transacción, bloqueo y auditoría `curso_publicado/curso_despublicado`. Existe `resources/views/admin/cursos/publicacion.blade.php` | La pantalla responde por su URL pero las vistas index/show/edit de cursos no enlazan a ella. Integrar navegación requiere **cambios de frontend autorizados**. No requiere reconstruir backend |
| Publicación de capacitaciones | **No implementada** como entidad editorial diferenciada. Ruta pública `/educacion/capacitaciones` estática; `Curso` y su ficha no tienen tipo editorial curso/capacitación | Definir dominio, permisos y si comparte ficha con discriminador. **Requiere migraciones y frontend** si se administra dinámicamente; no inferirlo desde categoría |
| Gestión de servicios tecnológicos | **No implementada** como CRUD/editorial. Tres rutas públicas estáticas; no se encontró modelo, controlador, tabla ni permisos específicos de servicios | Diseñar fuente editorial y flujo de aprobación; **requiere backend, permisos con migración, migraciones y frontend**. No importar automáticamente texto fijo |
| Cotizaciones | **No implementada** como módulo dedicado en este checkout. No se encontraron modelo, rutas, controlador o migración de cotizaciones | Definir solicitud pública y cotización privada por separado, acceso, estados, consentimiento, idempotencia y auditoría. **Requiere backend/migraciones/frontend**. Ningún precio privado alimentará Advisor |
| Atención comercial | **Implementada parcialmente** como orientación estática/chat, no como bandeja comercial estructurada. `/contactos`, intenciones PHP, `ChatLog`; `/admin/asistente` permite probar/ver registros con `asistente.ver` | `ProcesoInterno`/`ProcesoController` y `procesos.ver/gestionar` son procesos internos genéricos, no evidencia de cotizaciones ni pipeline comercial. Definir bandeja y contrato antes de reutilizarlos |
| Consumo web de cursos publicados | **Preparado pero desactivado/no conectado**. `CatalogoPublicoCursos`, `CursosPublicosController` existente sin ruta activa; `/educacion/cursos` sigue `Route::view`. Chat PHP dinámico con `chatbot.public_catalog_enabled=false` | Conectar controlador y presentación pública requiere autorización por afectar contenido público y **frontend**; conservar diseño. La migración editorial existente `2026_10_28_000021` requiere comprobar aplicación real en una fase autorizada; no se consultó PostgreSQL |
| Conocimiento Advisor desde Laravel | **Preparado pero desactivado** en este sprint. Exportador, firma, cliente y orquestación opcional probados con mocks. Python tiene carga al arranque desde disco y validación en seco | No existe distribución, retirada ni recarga segura de extremo a extremo. **Requiere backend adicional, cambios Python autorizados y probablemente migraciones**. No requiere frontend para resolver el protocolo interno |

La existencia de una migración en disco y su ejecución en SQLite no confirma su aplicación en `horus_group` o ensayo. Este sprint no inspeccionó datos ni esquemas reales.

### Orden recomendado

1. Revisar/autorizar editorialmente fichas individuales y facilitar acceso al editor desde el panel mediante un cambio visual mínimo autorizado; reutilizar permiso, request, controlador y auditoría existentes.
2. Definir cómo se mostrarán las fichas en la web conservando el diseño; solo entonces autorizar la conexión del controlador público y la vista. Mantener apagados ambos catálogos mientras tanto.
3. Resolver protocolo de retirada/versión/caducidad con Python y almacenamiento de distribución. Validarlo con fixtures y reloj controlado antes de distribuir contenido real.
4. Definir capacitación y servicios con aprobación pública separada de la administración interna. Crear migraciones/permisos propios cuando se autorice; no poblar desde páginas automáticamente.
5. Diseñar solicitudes comerciales y cotizaciones privadas con permisos, idempotencia y auditoría. Advisor solo orienta sobre cómo solicitar, sin acciones ni datos internos.
6. Antes de activar FastAPI: aprobación expresa, secretos separados y rotables, destino loopback validado, evaluación humana independiente, revisión de fuentes y salidas, presupuesto de RAM/latencia, observabilidad de metadatos, control operativo de disponibilidad y plan de desactivación. Probar integración real solo contra datos desechables y sin proveedores externos. No retomar S7 como dependencia implícita.

## F. Validación

- HTTP exclusivamente simulado con `Http::preventStrayRequests()` en las pruebas nuevas; SQLite `:memory:`, sesión/caché `array`, correo `array`, cola `sync`, según `phpunit.xml` y la salvaguarda efectiva de `Tests/TestCase`.
- Pruebas específicas ejecutadas: `AdvisorCatalogoTest|HorusAdvisorTest|AsistenteVirtualTest|ChatbotHybridTest|CursoPublicacionTest|Sprint12AuthSecurityTest|Sprint13RegistrationSecurityTest`: **192 aprobadas, 1121 aserciones**.
- `php vendor/bin/phpstan analyse --no-progress`: nivel 6, **sin errores**.
- Pint aplicado y comprobado solo en los siete archivos PHP propios: **aprobado**.
- `git diff --check`: **aprobado**, únicamente avisos LF/CRLF conocidos.
- Suite completa posterior: `php artisan test --compact`: **882 aprobadas, 5531 aserciones**, 107,73 s. Son **73 pruebas y 365 aserciones nuevas** respecto de la línea base; sin fallos pendientes en los comandos de validación ejecutados.
- Revisión final: sin cambios en `resources/`, `public/` ni rutas; índice vacío; se conservan los cambios preexistentes y `chatbot-python/` sin seguimiento. El inventario propio consta de ocho archivos, siete PHP y este documento.

Esta evidencia no demuestra concurrencia/bloqueos de PostgreSQL, comunicación real con FastAPI, comportamiento semántico, entrega de correo, OpenAI ni despliegue. No se ejecutaron los smokes, frontend build o pruebas JavaScript porque no se modificó frontend. Los resultados sintéticos reportados por Claude no se convirtieron en garantías de producción.

## Corrección A-01 a A-07 (03/11/2026)

El filtro de entrada y la barrera de salida tienen políticas distintas. La salida admite entidades institucionales exactas verificadas, conserva detección de datos privados y rechaza solicitudes de datos dentro del chat. Las fechas de convenios solo se admiten si el texto completo y la fuente coinciden con un registro local verificado de `chatbot-python/data/knowledge.json`; un id suministrado por Python no basta. No hay evidencia autorizada para precios o fechas comerciales. Las reglas contextuales no garantizan comprender toda formulación posible en lenguaje natural.

`chatbot.contingencia` registra categorías cerradas sin mensajes ni excepciones. La columna `fuente` conserva sus valores y compatibilidad con instalaciones sin 000034; no se requiere otra migración. Sin esa columna se reintenta solo la inserción de auditoría dentro de una transacción, sin consulta al catálogo ni caché que sobreviva a una migración. Las métricas categóricas requieren conservar los logs operativos del servidor.

Se retiraron `INTENTS_PERMITIDOS`, `consultar()`, `ChatCursosPublicos` y la configuración OpenAI sin consumidores de producción. `buscarIntent`, `ChatKnowledge`, `ChatIntentMatcher`, `ChatIntent`, seeders y migraciones históricas se conservan por sus consumidores administrativos o de diagnóstico. No participan en las respuestas públicas. El resto de este documento describe evidencia histórica, no instrucciones de activación ni contratos vigentes.

Regresiones: `tests/Feature/HorusObservacionesTest.php`, fixture original de 32 respuestas de Claude, `HorusAiTransicionTest`; integración temporal reproducible con `tests/Support/run-horus-observaciones.py` y el smoke Edge existente `tests/Support/run-horus-ai-smoke.py`. Ambos exigen aislamiento, loopback y cierre de sus procesos. La integración no demuestra PostgreSQL ni publicación.

## Corrección R-01 a R-05 de la auditoría independiente (03/11/2026)

Origen: `docs/auditoria-independiente-a01-a07.md`. Sustituye la lista exacta de entidades y la excepción de fechas de convenios del apartado anterior.

- **Procedencia (R-01).** Si `answer` coincide byte a byte con un registro `verified` de `chatbot-python/data/knowledge.json` y Python lo cita con el mismo `id` y `url_path`, el texto se trata como contenido institucional local: solo se exige ausencia de HTML y de enlaces ajenos a los canales oficiales. Cualquier alteración, una fuente distinta o un registro no verificado devuelven el texto a la validación completa. Para texto compuesto, las entidades capitalizadas se derivan de los registros verificados en lugar de mantenerse a mano. El conocimiento se carga una vez por proceso y se invalida por fecha y tamaño del archivo.
- **Evidencia (R-02).** En texto no verificado, toda cifra (salvo marcadores de lista), numeral escrito o referencia de calendario debe constar en los registros citados como fuente. Cualquier símbolo o nombre de moneda se rechaza siempre, igual que afirmar disponibilidad de cupos o vacantes (negarla es una abstención válida). Las peticiones de datos se detectan con un léxico normalizado de verbos e interrogativas, sin exigir posesivo, con excepción para advertencias («no compartas…») y derivación a personal humano fuera del chat.
- **Entrada (R-03).** La heurística de mayúsculas ignora palabras de inicio de frase, productos, lugares de Cajamarca e instituciones (`PALABRAS_NO_PERSONALES`). Nombrar un documento sin aportarlo («¿Necesito DNI?») ya no bloquea; aportarlo sí (dígitos, posesivo o atribución). La barrera de seguridad bloquea patrones de ataque («ignora…», «system prompt», «tus instrucciones», «instrucciones internas») en lugar de las palabras sueltas `instrucciones`, `prompt`, `system` y `dni`.
- **Evidencia de integración (R-04).** `horus-observaciones-bridge.php` comprueba explícitamente las consultas prohibidas y recorre las consultas reales de `tests/Fixtures/horus-auditoria-r01.json`.
- **R-05.** La privacidad se evalúa una sola vez por mensaje. Sin la migración 000034, cada inserción sigue generando un intento fallido en el log de PostgreSQL hasta aplicarla; es inherente a no consultar el esquema.

Regresión: `tests/Feature/HorusCorreccionR01R05Test.php`, con los 34 registros verificados, 198 respuestas reales de Python en modo léxico e híbrido (todas las preguntas de ejemplo del conocimiento y los casos R-01), 31 variantes prohibidas en `answer` y `clarification_question`, y entradas legítimas y privadas. Esta evidencia usa `Http::fake` y respuestas reales capturadas del motor en proceso; no demuestra PostgreSQL, concurrencia ni despliegue.

## Corrección de privacidad: nombres completos en minúsculas (03/11/2026)

**Causa raíz.** `FiltroPrivacidadChat` reconocía nombres por tres vías: frases explícitas («me llamo», «mi nombre es»), listas de nombres y apellidos frecuentes, y secuencias con mayúscula inicial. Un nombre en minúsculas con palabras fuera de esas listas («soy anderson vela», «brayan cuzco tello necesita soporte») no activaba ninguna, y llegaba a Python.

**Corrección.** Nuevo `detectaNombreContextual`, aplicado solo a la entrada del visitante (la barrera de salida no cambia). Bloquea dos o más palabras seguidas fuera del vocabulario común e institucional (o presentes en las listas de nombres) cuando aparecen tras:

- una presentación (`soy`, `soi`, `me presento`, `les saluda`, `le escribe`, `habla`);
- una matrícula, inscripción o registro (`matric…`, `inscri…`, `registr…`, `anot…`), admitiendo relleno como «a la señorita» o «a mi hijo»;
- una relación con una persona (`mi hijo`, `el alumno`, `mi sobrina`…);
- una atención solicitada para alguien (`soporte para`, `atención a nombre de`…);
- o al inicio del mensaje, seguidas de un verbo de sujeto (`necesita`, `quiere`, `desea`…).

El texto se normaliza (NFKD, sin marcas combinantes, espacios Unicode como separadores), de modo que tildes, formas descompuestas y espacios variables no evaden la regla. Una sola palabra desconocida no bloquea. Además, se retiraron falsos positivos previos de la heurística de mayúsculas: «Wi-Fi», «Facebook», «Yape», «Libro de Reclamaciones», «Colegio de Economistas», «Colegio de Licenciados» y los títulos de convenios de ciencias administrativas y económicas.

**Regresión.** `tests/Unit/FiltroPrivacidadNombresMinusculasTest.php` cubre los cuatro fallos originales, los cinco casos ya bloqueados, variantes con errores, Unicode y nombres compuestos, consultas legítimas y todos los títulos y ejemplos de `knowledge.json`. `tests/Feature/HorusPrivacidadNombresTest.php` comprueba que las entradas bloqueadas no producen HTTP, devuelven la respuesta de privacidad y no dejan el dato en `ChatLog` ni en los logs, y que las consultas legítimas llegan a Python con `history: []` y sin `session_id`.

**Límite.** La detección es léxica y contextual. Un nombre sin ningún contexto («anderson vela» solo) no se detecta, y frases legítimas con dos palabras poco comunes tras un ancla («soy mecánico automotriz») se bloquean de forma preventiva con la respuesta de privacidad.

## Corrección de privacidad H-1 a H-7 (03/11/2026) — intervención parcial

**Causa raíz.** La detección contextual descartaba cualquier palabra terminada en `-ado`, `-ida`, `-ista`, `-ando` o `-ción`, de modo que apellidos y nombres frecuentes (Hurtado, Maldonado, Bautista, Orlando, Concepción) cortaban la secuencia (H-1). Solo reconocía relleno gramatical tras «soy», no títulos («soy el ingeniero …»), ni variantes («zoy», «soyy») o apodos («me dicen …») (H-2). La puntuación no delimitaba secuencias y los oficios desconocidos se tomaban por nombres (H-3). El posesivo «su» en una pregunta sobre el RUC de la empresa se trataba como un documento personal (H-4).

**Cambios en `FiltroPrivacidadChat`.**

- La primera palabra de la secuencia (nombre de pila) se descarta si tiene forma de oficio, adjetivo o participio (`-ero`, `-ista`, `-ico`, `-logo`, `-or`, `-ante`, `-ente`, `-ario`, `-ado`, `-ido`…), salvo nombres con esa terminación (`NOMBRES_MORFOLOGIA_AMBIGUA`: Víctor, Mónica, Vicente, Rosario, Concepción…).
- Las palabras siguientes (apellidos) admiten `-ado`, `-ida`, `-ista` y `-ando`; solo se descartan adjetivos claros (`-ico`, `-ivo`, `-dor` de seis o más letras) y verbos terminados en «ó».
- Tras un ancla se saltan relleno, tratamientos, títulos (`TITULOS`) y oficios, para encontrar el nombre que sigue («soy el mecánico walter sangay»); un oficio solo no bloquea.
- Se reconocen `[sz]o+(y+|i+)` y «me dicen / conocen / llaman / apodan» (con estos, basta una palabra). La puntuación delimita secuencias.
- En `detectaDocumento`, una pregunta sin cifras con «su/sus» se considera dirigida a la empresa. Con cifras, con posesivo propio («mi») o atribuida a una persona, sigue bloqueándose.
- «registrar / marcar mi asistencia» deja de ser una referencia ambigua a un expediente; «ver mi asistencia» sigue bloqueado.

**Evaluación.** Muestra nueva de 318 mensajes etiquetados antes de modificar el detector: 159 de desarrollo y 159 de reserva, con plantillas y nombres disjuntos, sellada con SHA-256. La reserva se evaluó una sola vez al final, sin ajustes posteriores.

| Conjunto | Cobertura con contexto | Especificidad |
|---|---|---|
| Reserva, antes | 62,9 % | 79,6 % |
| Reserva, después | **98,4 %** (61/62) | **93,5 %** (87/93) |

El objetivo de especificidad (≥95 %) **no se cumple** en la reserva. Siguen pendientes:

- **Falsos positivos:** «soy chef pastelero», «soy fotógrafo freelance», «soy policía retirado», «¿tienen RUC 20?», «batería interna necesita cambio» y «antena sectorial necesita alineación».
- **Falso negativo:** «visita técnica para teodoro idrogo».

Regresión en `tests/Unit/FiltroPrivacidadH1H7Test.php`.

**Límite H-7 (inherente).** Un nombre sin ningún contexto que lo introduzca («yessenia alcalde chingay», «para dilmer aquino») no se detecta con reglas léxicas: 0 de 4 en la reserva. No se promete detección perfecta de nombres aislados. Cuando se detecta, el mensaje se bloquea completo; nunca se redacta parcialmente ni se envía un fragmento.
