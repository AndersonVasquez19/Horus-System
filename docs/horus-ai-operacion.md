# Horus AI — preparación operativa E

Estado histórico de esta preparación: 04/11/2026. Activación local autorizada el 05/11/2026:
aplicación en http://127.0.0.1:18080, FastAPI en 127.0.0.1:18081 y worker activo; sin despliegue.
Evidencia y operación actual: [informe único de entrega](entrega-consolidacion.md#horus-ai-local-activado-y-verificado--05112026).
Este procedimiento no es autorización para ejecutarlo en infraestructura real.

## Arquitectura comprobada y límites

Laravel es la autoridad editorial y la única capa que consulta PostgreSQL. Exporta únicamente cursos y servicios publicados, firma snapshots y usa el transporte A1/A2 existente. El job SincronizarCatalogoAdvisor conserva sus límites, generación y confirmación diferida. Python recibe snapshots, valida firma/versión, construye el candidato y lo activa atómicamente. MiniLM y el índice están en memoria; el snapshot firmado persiste en disco, **los vectores no** salvo que se configure la caché opcional `HORUS_AI_SEMANTIC_CACHE_DIR` (05/11/2026), que conserva los vectores de pasajes públicos para no recodificarlos. Reiniciar reconstruye el índice (con caché, sin recodificar lo ya conocido); no basta con que /health responda.

A2, B7, C6 y D6 son evidencia histórica de integración local. E no inicia servicios, instala gestores ni valida producción. Las pruebas E de supervisión emplean dobles y SQLite memoria; no certifican systemd, Linux, PostgreSQL, red productiva o una carga real. La aceptación independiente de privacidad sigue pendiente; D6 y los corpus conocidos no constituyen certificación ni garantía universal.

## Diagnóstico E0

- Arranque actual de Python: python -m app, o Uvicorn directo. La entrada histórica valida host, pero un operador puede omitirla invocando Uvicorn; usar la nueva entrada operativa en las plantillas.
- Laravel dispone de /up; FastAPI de /health (vida) y /ready (base, auth, semántica y catálogo). /ready puede declarar status=ready mientras semantic=loading: el nuevo preflight exige expresamente semantic=loaded.
- Worker database y job con 60 intentos, tres excepciones, backoff 5/30/90 s, plazo absoluto de 300 s, timeout 45 s. Debe mantenerse retry_after mayor que timeout; no se modifican esos valores.
- Snapshots con vigencia máxima de cinco minutos; los eventos editoriales por sí solos no renuevan un catálogo sin cambios. Se añade renovación cada dos minutos, opt-in, con heartbeat del worker para no acumular jobs durante su caída.
- Logs existentes: resultados agregados y códigos de contingencia; no mensajes originales. No se añade un endpoint público de métricas ni se instala otra plataforma.
- No existe un despliegue Linux/systemd validado; se entregan plantillas que no se instalan ni arrancan.

## Configuración segura

| Ámbito | Variables/decisiones | Predeterminado y contrato |
| --- | --- | --- |
| Operación Laravel/Python | HORUS_OPS_ENABLED | false; no inicia nada al cargar configuración |
| Renovación Laravel | HORUS_OPS_RENEW_CATALOG | false; solo scheduler configurado y worker con heartbeat vigente |
| Advisor Laravel | HORUS_ADVISOR_ENABLED | false, sin cambios |
| Transporte Laravel | HORUS_ADVISOR_URL, TOKEN, SIGNING_KEY | HTTP sin ruta ni credenciales, hacia `127.0.0.1` o, desde el 22/11/2026, hacia un host exacto de `HORUS_ADVISOR_ALLOWED_HOSTS` con puerto (la misma regla del cliente: nunca `localhost`, metadatos ni IP literales; es lo que permite la renovación en Railway, con Laravel y Horus AI en contenedores distintos); secretos de al menos 32 caracteres |
| Sincronización | HORUS_AI_CATALOG_SYNC_ENABLED | false, sin cambios; requiere también Advisor en el proceso que sincroniza |
| Worker | QUEUE_CONNECTION | Driver database existente; retry_after >45 s; caché compartida persistente |
| FastAPI | HORUS_AI_SERVICE_TOKEN, CATALOG_SIGNING_KEY, CATALOG_PATH | Token y clave correspondientes a Laravel; archivo activo persistente fuera del release |
| Modelo | HORUS_AI_SEMANTIC_ENABLED, SEMANTIC_MODEL_DIR, ML_THREADS | Modelo local aprobado, semántica habilitada solo por configuración autorizada, dos hilos como punto de partida |
| Caché de vectores (05/11/2026) | HORUS_AI_SEMANTIC_CACHE_DIR | Vacío = desactivada. Directorio persistente fuera del release, escribible solo por la cuenta de FastAPI. Guarda vectores de pasajes públicos (clave SHA-256, huella del modelo), nunca consultas. `app.operacion check` exige que exista (`semantic_cache_storage`). Reduce la recodificación al reiniciar; no la carga del modelo |
| Red Python | HORUS_AI_HOST, PORT, ALLOW_NON_LOOPBACK | La entrada operativa exige 127.0.0.1 y rechaza allow_non_loopback |

No se añaden valores a .env o .env.example. Python operativo lee solo el entorno del proceso (_env_file=None). Configuración del servicio desactivada y credenciales ausentes son estados normales de preparación; no copiar secretos a repositorio, argumentos CLI o navegador.

Entregar secretos por gestor existente o archivos de entorno externos al release, permisos mínimos (por ejemplo root:root 0600 para EnvironmentFile de systemd). No incluir valores reales en ejemplos. Distinguir el token de servicio de la clave de firma; rotar coordinadamente, sin períodos con firmas sin validar. Los workers no reciben claves OpenAI o Resend. Python no recibe DB_* ni credenciales PostgreSQL.

Con config:cache, Laravel usa el snapshot de configuración ya compilado: cambiar una variable del servicio no invalida ese archivo. Preparar/verificar la configuración del release bajo la autorización futura y proteger el archivo cacheado, que puede contener secretos. No ejecutar config:show ni volcar toda la configuración. El web, worker y scheduler deben compartir la misma política de versión/caché y las credenciales correspondientes.

Si se separa web apagado de operación interna habilitada, **no compartir el mismo archivo config:cache con flags contradictorios**. Preparar perfiles externos separados mediante APP_CONFIG_CACHE, por ejemplo /var/lib/horus/web-config.php y /var/lib/horus/ops-config.php, con permisos por cuenta y sin incluirlos en el release. El scheduler debe heredar el perfil operativo autorizado del worker, incluidos ambos interruptores y la renovación; heredar el perfil web apagado impediría renovar. La caché de datos/versión y el APP_KEY usados para validarla sí deben ser coherentes entre procesos. Estos archivos y perfiles no se crean en esta fase.

## Comandos implementados

```text
php artisan horus:advisor-operacion
php artisan horus:advisor-operacion status --probe
php artisan horus:advisor-operacion status --probe --queue
php artisan horus:advisor-operacion sync
php artisan horus:advisor-operacion sync --execute
php artisan horus:advisor-operacion worker --execute
python -m app.operacion check
python -m app.operacion run --execute
```

El primer comando y los dry-runs no consultan red, caché o cola. --probe es opt-in: lee heartbeat/versión de caché y hace GET /health, /ready y /api/v1/info, sin enviar mensajes. Solo /api/v1/info recibe la cabecera del token existente, nunca se imprime ni registra. Timeout de un segundo por endpoint, conexión 0,4 s, sin redirects; lectura limitada a 4096 bytes, descartando valores libres. Imprime códigos y estados permitidos, nunca cuerpos/excepciones completos. La readiness operativa exige versión coincidente, catálogo válido, MiniLM cargado, autenticación aceptada y heartbeat vigente. No se confunde con /up, ni sustituye la validación completa de respuestas del cliente existente.

--queue exige --probe y añade conteos explícitos en la base de cola: total pendiente de la cola seleccionada y fallidos que identifican el job de catálogo. No devuelve payloads, excepciones ni IDs. Esos conteos son diagnóstico, no una prueba de que el worker está consumiendo al ritmo necesario. Si hay fallidos, investigar y mantener la habilitación suspendida, aunque el resto del preflight sea correcto. No ejecutar esta opción contra PostgreSQL en este equipo sin autorización expresa.

Python check valida forma de configuración, rutas/modelo presentes y firma obligatoria sin cargar Torch/MiniLM ni abrir sockets. No certifica integridad completa del modelo, permisos efectivos de escritura o carga exitosa: /ready y la prueba funcional deben comprobarlo después de un arranque autorizado.

La entrada `app.operacion run --execute` instala además una lista positiva de registros: códigos de carga/sincronización y métricas numéricas permitidas (duración y modo). Elimina argumentos originales, exc_info, exc_text y stack_info; no conserva valores libres de errores de validación. Así se evita que una excepción de conocimiento mal formado vuelque su contenido durante el arranque. No se cambia la detección de privacidad ni el RAG. Invocar Uvicorn directamente o la entrada histórica omite esta protección adicional; las plantillas usan exclusivamente la entrada operativa. No conectar colectores externos que registren cuerpos/cabeceras ni agregar handlers sin el filtro.

## Worker, métricas y renovación

El wrapper usa queue:work existente: sleep=3, timeout=45, max-time=3600 y memoria PHP de 256 MB. El job mantiene sus propios intentos/retryUntil; no hay un segundo procesador de catálogos. La memoria es un umbral del worker entre trabajos, no una cota dura del proceso Python.

Lock compartido de worker con TTL 3700 s, heartbeat con TTL 90 s actualizado como máximo cada 15 s en espera y en eventos de procesamiento/fallo. Contadores attempts y failures describen intentos del worker; JobProcessed **no** equivale a catálogo confirmado cuando el job se libera como pending. La versión esperada confirmada sigue siendo la autoridad. El cierre normal elimina heartbeat y libera lock; muerte forzada deja heartbeat/lock hasta sus TTL. No borrar locks globalmente. Tras una caída, un reinicio inmediato puede quedar bloqueado por el lock antiguo; esperar su caducidad o autorizar una liberación específica solo después de demostrar ausencia del proceso anterior.

El wrapper procesa la cola database seleccionada, actualmente compartida: no se inventa una cola dedicada que el observer existente no utilice. Si se requiere separación física de otros trabajos, eso necesita una decisión posterior de enrutamiento y pruebas. Cuentas y permisos deben contemplar esa cola compartida; no ejecutar como root. El límite de tiempo real del worker necesita PCNTL en Linux; no se atribuye esa garantía a Windows.

El scheduler Laravel existente se reutiliza: renovación cada dos minutos, interruptores false, sin ejecutar nada en esta fase. Solo despacha con heartbeat vigente; TTL de 110 s agrupa ticks duplicados y withoutOverlapping limita el despacho simultáneo. Un error de despacho libera el lock para un reintento posterior. Cada job tiene reintentos finitos; no se reintentan automáticamente todas las filas failed_jobs. Recuperar un fallo caducado despachando un job nuevo que relea el catálogo actual, no reproduciendo un snapshot viejo.

Una caída del worker suprime renovaciones automáticas, evita crecimiento ilimitado por ese mecanismo y terminará produciendo stale/fallback. Si el worker está vivo pero FastAPI falla repetidamente, habrá nuevos intentos periódicos: alertar, pausar renovación y reparar; no es un circuit breaker implementado. El intervalo de dos minutos no garantiza freshness si la construcción o la espera en cola superan el presupuesto restante del snapshot.

Supervisar sin datos personales:

- CLI agregado cada 30–60 s como propuesta; lectura de cola menos frecuente, por ejemplo cada 2–5 minutos. No hay colector automático instalado.
- Estado de proceso/exit/restarts, CPU/RSS/memoria privada y disco con el gestor del host; no se inventan valores actuales de recursos de procesos apagados.
- Alertas: heartbeat >90 s, semantic distinto de loaded, catálogo stale/error, versión distinta, fallidos de catálogo >0, crecimiento de pendientes o disco insuficiente. Umbrales de latencia/CPU requieren medición productiva autorizada; no se presenta un SLA.
- Usar códigos de logs existentes chatbot.metric, chatbot.contingencia y advisor_catalog.sync; retención/rotación con el gestor existente. No habilitar debug HTTP/SQL, trazas de payloads, access logs con query strings o grabación de conversaciones.

## Infraestructura propuesta, no desplegada

Un solo host Linux inicialmente: HTTPS/reverse proxy y PHP-FPM para Laravel; PostgreSQL solo accesible a Laravel/worker; FastAPI en loopback, una instancia y un proceso con MiniLM CPU. Disco de modelos de solo lectura y directorio persistente de snapshots escribible únicamente por horus-ai. Releases de código inmutables y almacenamiento Laravel compartido fuera del release. No exponer FastAPI/docs/ready públicamente. Una réplica adicional requiere diseñar distribución de snapshots y caché compartida; no añadir workers Uvicorn indiscriminadamente, pues multiplican modelo/memoria.

| Opción evaluada | Decisión de preparación |
| --- | --- |
| Un host Linux con gestor existente/systemd | Propuesta inicial; conserva HTTP loopback y evita herramientas nuevas de orquestación |
| Windows con servicios administrados | Entorno local actual, no validado como producción; falta gestor elegido y garantía de timeout de worker con PCNTL |
| Contenedores separados | Diferida: loopback de cada contenedor no es el del otro; no cambiar binding/transporte ni añadir una topología sin autorización. **22/11/2026:** el propietario autorizó en el cliente PHP una lista blanca de hostnames exactos de red privada (`HORUS_ADVISOR_ALLOWED_HOSTS`, vacía por defecto); sigue siendo HTTP sin TLS interno, sin IP literales ni hosts de metadatos (ver `despliegue-vercel-railway.md`) |
| PaaS o varios hosts | Diferida: requiere definir red privada, TLS interno, persistencia y distribución del catálogo; no se habilita allow_non_loopback |

Propuesta inicial para dimensionar, no garantía: 4 vCPU y 8 GB RAM para evaluar un host conjunto de baja carga, reservando margen para PostgreSQL, PHP y reconstrucción candidata. La medición histórica Python registró alrededor de 1,85–1,9 GB de memoria privada; al construir el candidato coexisten componentes y debe medirse el pico real. El equipo local tiene 8.300.388.352 bytes RAM y 12 procesadores lógicos; no es benchmark de un VPS.

Modelo local inventariado: 499.565.457 bytes bajo models al preparar E, sin cargarlo. Dependencias CPU históricas ~1,5 GB; reservar al menos 5 GB para Python/modelo/releases y dimensionar aparte PostgreSQL, adjuntos, logs y respaldos. El índice no persiste: medir arranque antes de abrir tráfico. D6 tardó aproximadamente 2 minutos desde inicio de proceso hasta disponibilidad MiniLM en esa ejecución; no es tiempo garantizado. La referencia B de 500 documentos (~286 s) es un riesgo de cold start/caducidad de 300 s: no usarla como capacidad productiva aceptada. Se requiere benchmark del catálogo objetivo, sin ampliar timeouts para ocultar fallos.

Sin proveedor, región, carga, tamaño de base, retención y SLA definidos, no existe base suficiente para cotizar infraestructura. No se contrata ni publica nada.

Plantillas en ops/horus-ai/*.service.example: sin sección Install, sin instalación o enable, marcador externo OPERATION_AUTHORIZED requerido, credenciales fuera del repositorio y reinicios limitados por StartLimitBurst/Interval. El worker se recicla cada hora; FastAPI reinicia solo ante fallo. No son unidades validadas en Linux en esta fase. Resolver cuentas/rutas, PCNTL, permisos de storage/config cache, filesystem y soporte de IPAddressDeny antes de usarlas. La unidad Python solo permite loopback; PostgreSQL nunca está en su entorno. No duplicar un Supervisor/systemd existente: elegir un gestor con el operador.

La separación health/readiness y el coste de replicar memoria por proceso se apoyan en [FastAPI, conceptos de despliegue](https://fastapi.tiangolo.com/deployment/concepts/). Los tiempos retry_after/timeout y gestión de workers siguen [Laravel 12, colas](https://laravel.com/docs/12.x/queues). Las políticas de proceso propuestas se contrastaron con [systemd.service](https://www.freedesktop.org/software/systemd/man/latest/systemd.service.html); requieren validación en la distribución elegida.

## Catálogo: datos académicos y caducidad (22/11/2026)

Cambio de código autorizado por el propietario; no activa la sincronización (`HORUS_AI_CATALOG_SYNC_ENABLED` sigue en `false`) ni toca infraestructura.

- **Campos nuevos del contrato:** cada curso exporta `duration_text` (`duracion`), `start_date` (`fecha_inicio`, ISO), `certification_text` (`certificacion`) y `url_path=/educacion/cursos` (no hay página por curso). El `temario` va al final de `details`, recortado por palabra si no cabe en 1500 caracteres; si parece contener un dato personal se omite solo el temario. Nunca precio interno, cupos, matrículas ni estudiantes.
- **Frases espejo:** Python responde con frases fijas (`«Título»: inicia el 5 de diciembre de 2026; el horario es …`, `«Título»: certificación: …`, `«Título»: dura …`) y `FuentesCatalogoAdvisor` reconstruye exactamente las mismas para validar procedencia. Las pruebas `apps/ai/tests/test_catalogo_campos_academicos.py` y `apps/api/tests/Feature/AdvisorCamposAcademicosTest.php` comparten los literales: cambiar una frase exige cambiarla en ambos lados.
- **Orden de despliegue:** el esquema de Python rechaza campos desconocidos (`extra=forbid`). Desplegar Horus AI **antes** que Laravel; al revés, toda sincronización se rechaza y el catálogo queda en la versión anterior.
- **Caducidad:** un snapshot vencido ya no apaga el chatbot. Python responde `status=ready, catalog=stale`, descarta las fichas vencidas y sigue con el conocimiento institucional; pedir la lista de cursos remite a un asesor sin afirmar que no hay cursos. Laravel acepta `stale` solo con `status=ready` y trata el catálogo como vacío, de modo que cualquier respuesta que cite un curso se rechaza (prueba de mutación en `HorusAdvisorResilienciaTest`). `pending` y `error` siguen bloqueando.

## Contexto conversacional y varias opciones (22/11/2026)

- **Estado en lugar de historial:** Laravel sigue enviando `history: []` y no guarda el texto de la conversación. Horus AI devuelve en cada respuesta un `state` (`ConversationState`): claves de tema, objetivo, nivel, modalidad y público del vocabulario de `intents.json`, hasta dos identificadores de registros publicados en foco, si el turno anterior quedó fuera de alcance o fue una cotización, y la edad de cada dato. Cada dato caduca a los 5 turnos, como el historial (`MAX_CONTEXT_TURNS`, desde el 25/11/2026; antes 3); «otra cosa…» lo descarta.
- **Laravel** valida el estado (`HorusAdvisorClient::estadoValido`): cualquier clave ajena, texto con espacios, lista larga o registro que no exista en el conocimiento o en el catálogo vigente lo descarta entero. Lo guarda 10 minutos en caché (`MEMORIA_SEGUNDOS`, desde el 25/11/2026; antes 5), firmado con HMAC (`chat:state:*`), y lo reenvía en el turno siguiente. Una contingencia lo borra. Con estado, el seguimiento («¿cuánto dura?», «¿y eso es virtual?») lo resuelve Python; PHP solo conserva «sí» y «la primera/la segunda». El estado entra en la clave de deduplicación, y la respuesta también se guarda con el estado resultante para que un doble envío inmediato no repita la consulta.
- **Python** depura el estado recibido (solo claves conocidas y registros verificados) y, si llega historial de texto, lo ignora. `scripts/evaluate.py` corre cada diálogo de dos formas (con historial y solo con estado, `conv-state_only`).
- **Varias opciones:** cuando una ficha publicada gana con claridad y otras del mismo tipo también coinciden, se recomiendan hasta tres (la mejor como respuesta y dos alternativas en `clarification_question`), cada una con su motivo y enlace. Una consulta ambigua sigue preguntando cuál. Para validar seguimientos sobre dos cursos, Laravel toma como evidencia el texto de las fichas vigentes citadas con su título y ruta exactos.
- **Coste del catálogo en Laravel:** construir las fichas vigentes (`FuentesCatalogoAdvisor::actuales()`: consulta, permisos, privacidad y firma) midió ~28 ms con 50 fichas y ~165 ms con 500 (SQLite en memoria). `HorusAdvisorClient` las memoriza solo dentro de un mensaje (por ejemplo, «sí» las usa para el título y para validar) y las descarta al empezar el siguiente y al terminar `orientar()`. No hay caché entre mensajes, para que una ficha retirada deje de citarse de inmediato.
- **Compatibilidad:** Laravel solo envía `state` si Horus AI lo devolvió antes, así que este cambio funciona en cualquier orden de despliegue. El orden del apartado anterior (Python antes que Laravel) sigue valiendo por los campos del catálogo.
- **Pruebas:** `apps/ai/tests/test_estado_conversacional.py`, `test_recomendaciones_varias.py` y `apps/api/tests/Feature/AsistenteEstadoConversacionalTest.php`, `AsistenteRecomendacionesVariasTest.php` (este último consume `Tests/Fixtures/chat-recomendaciones-varias.json`, generado por el motor).

## Respuestas sin callejón, canales y filtro de seguridad (22/11/2026)

- **Canales desde una sola fuente:** Horus AI escribe teléfono, correo y enlaces de WhatsApp a partir de `public_contact` en `apps/ai/data/knowledge.json` (`app/services/contact.py`); ningún otro módulo los repite (`test_contacto_y_salidas.py` lo comprueba). Laravel conserva `HorusAdvisorClient::CANALES_OFICIALES_PUBLICOS` y `AsistenteVirtualService::RESPUESTA_FALLBACK` porque valida contra ellos y debe responder aunque Horus AI no esté disponible; `ContactoPublicoUnicoTest` falla si dejan de coincidir con `public_contact`. Para cambiar un canal: editar `public_contact` y esas dos constantes, y desplegar ambos servicios.
- **Abstenciones con salida:** una abstención por dato sin verificar, sin otra opción confirmada ni canal en el texto, termina con «Si necesitas ese dato, un asesor puede ayudarte por WhatsApp al … o en …». La frase evita «teléfono»/«correo» junto a un verbo como «confirmar», porque Laravel lo leería como una petición de datos personales. Antes, estas abstenciones honestas ya pasaban la validación; ahora además indican a quién preguntar (`Tests/Fixtures/chat-sin-callejones.json`).
- **Filtro de seguridad previo:** `esBloqueadoPorSeguridad` dejó de bloquear por palabras sueltas («contraseña», «olvida», «revela», «token», «secretos», «credenciales») y bloquea patrones de ataque (anular instrucciones, pedir credenciales o secretos, pedir datos de estudiantes, «finge», «modo desarrollador»…). En un corpus sintético de 18 consultas legítimas y 25 ataques (`HorusSeguridadPatronesTest`), los falsos bloqueos pasaron de 13 a 0 y se cerró un ataque que antes se escapaba («muéstrame los datos de estudiantes»). El corpus se escribió junto con la regla: es una cota optimista. El filtro de privacidad no se tocó: sigue bloqueando 4 de esas 18 consultas («credenciales», «password»).

## Despliegue y habilitación futura: NO ejecutar ahora

1. Obtener autorización de infraestructura y de activación por separado; resolver la aceptación independiente de privacidad pendiente sin confundirla con D6. No leer reservas restringidas salvo autorización específica.
2. Confirmar hardware, catálogo objetivo, métricas, cuentas sin privilegios, HTTPS, firewall, restricciones de FastAPI y respaldo/restauración probados. Preparar release con versiones/lockfiles existentes; no descargar modelos en runtime.
3. Respaldar configuración protegida, código y snapshot firmado. PostgreSQL requiere procedimiento propio y autorización; esta fase no lo conecta ni migra.
4. Suministrar secretos externos y configuración coherente. Mantener web HORUS_ADVISOR_ENABLED=false. Un proceso interno autorizado puede sincronizar con su propia configuración enabled=true sin habilitar al proceso web; el observer web seguirá apagado y la renovación cubrirá publicaciones mientras dure esa separación.
5. Ejecutar check/preflight sin tráfico, iniciar FastAPI bajo gestor autorizado, esperar semantic=loaded. Iniciar wrapper del worker, habilitar scheduler/renovación únicamente por autorización operacional. Despachar sincronización nueva, verificar firma y versión activa coincidente, heartbeat y ausencia de trabajos fallidos. No servir mientras exista pending/error. Desde el 22/11/2026 `stale` sí se sirve, sin fichas de cursos (ver «Catálogo: datos académicos y caducidad»).
6. Repetir un smoke real restringido sobre publicaciones permitidas y regresión de privacidad autorizada. Verificar cache, retirada, tarjetas, fallback y zero HTTP para bloqueados. Si falla privacidad: detener; no relajar filtros.
7. Solo con aprobación pública expresa, preparar canary mediante restricciones del reverse proxy o entorno interno existente. No existe rollout porcentual implementado en el chatbot; diseñar y autorizar su restricción antes de cambiar el flag web. Si se usa config cache, reconstruir/reiniciar procesos afectados con control de secretos.
8. Apertura gradual definida por el operador, con supervisión y responsables. Desactivar inmediatamente por PII transmitida/conservada, fuentes retiradas, enlaces no permitidos, versión no coincidente o pérdida de readiness. Mantener fallback institucional.

## Rollback y recuperación: NO ejecutar ahora

Primero apagar Advisor en el proceso web y aplicar correctamente su configuración cacheada/reinicio; preservar fallback. Detener renovaciones para evitar carreras, drenar/parar worker con timeout y demostrar ausencia de procesos antes de intervenir locks. Conservar evidencias agregadas sin PII. Restaurar release compatible anterior y configuración, sin reset/clean del checkout compartido.

No restaurar automáticamente un catálogo antiguo firmado: puede contener contenido retirado o estar vencido. Conservarlo para diagnóstico, mantener web apagado y pedir a Laravel actual un snapshot nuevo firmado/versionado. Python mantiene la última versión válida ante error, pero no debe servirla si no supera readiness/versión/caducidad. Si un job caducó, crear uno nuevo; evitar queue:retry all. No vaciar caché global ni cola de otros módulos. El cambio de versiones invalida las respuestas según la política existente.

Reiniciar FastAPI de forma controlada bajo el gestor elegido y esperar reconstrucción; reiniciar worker tras resolver configuración/TTL, verificar heartbeat, pendientes/fallidos y confirmación de catálogo. No extender los límites para esconder cargas excesivas. Si no se recupera en la ventana acordada, mantener contingencia y escalar. Reversión de esquema o datos PostgreSQL no forma parte de E y requiere autorización independiente.

## Conversación fluida (24/11/2026)

Mensajes cortos que solo tienen sentido dentro de una conversación se resuelven en `apps/ai/app/services/conversation_glue.py` y `AdvisorEngine._glue`, sin librerías nuevas ni texto del usuario en el estado:

- «ok / vale / entiendo», «no / así está bien», «jaja», «cómo estás», «perdón», mensajes vacíos o solo emojis y «no entendí / repite»: respuesta de cortesía con variante estable (hash del mensaje), intención `acknowledgement`, sin fuentes. Con un curso en foco, «ok» ofrece el siguiente paso.
- «sí», «dale», «cuéntame», «repite»: con un curso en foco se reescriben a «dame más detalles» y siguen el flujo verificado de seguimiento; sin foco reconducen con la lista de temas.
- «el primero / la segunda / el último» eligen de la lista que dejó «qué cursos tienen»; «el otro» elige el segundo de dos opciones comparadas o el único otro curso publicado.
- «más barato / más corto / más temprano» se reescriben a la pregunta de precio, duración u horario: el precio sigue derivándose a un asesor.
- Laravel acepta `acknowledgement` como cortesía sin fuentes (`HorusAdvisorClient::respuestaValida`); las frases no llevan cifras, enlaces ni nombres (prueba `test_every_conversational_glue_phrase_is_accepted_as_courtesy_without_sources`).
- El widget ya no reescribe las consultas con «del curso X» (`contextualizarConsulta` se retiró): el foco lo lleva solo el estado firmado del servidor (600 s desde el 25/11/2026).

## Preparación de la sustentación (25/11/2026)

- **Cobertura conversacional:** identidad del asistente («¿eres una IA?», «¿quién te creó?»), fuera de tema con tres variantes, insultos, inglés básico, hora, pedido de ayuda, pedidos creativos y datos institucionales sin registro (fundación, significado del nombre). Todas usan intenciones que Laravel ya aceptaba (`capabilities`, `acknowledgement`, `out_of_scope`, `unavailable_info`).
- **Banco del jurado:** `apps/api/tests/Fixtures/chat-jurado-replies.json` (126 preguntas) sale del motor actual (prueba en `test_conversacion_fluida.py`) y `HorusJuradoTest` comprueba que ninguna pregunta se toma por dato personal y que ninguna respuesta legítima cae en contingencia.
- **Negativas de seguridad:** Laravel acepta `sensitive_request` y `prompt_injection` de Horus AI; su propio bloqueo previo responde `RESPUESTA_SEGURIDAD` en vez de la contingencia «no disponible».
- **Varias preguntas en un mensaje:** `AdvisorEngine._several_questions` divide por signos de pregunta, punto y coma o «y / también / además» seguidos de una pregunta (hasta tres partes), resuelve cada una y une solo textos exactos de fichas verificadas. Si una parte no tiene respuesta verificada (p. ej. el precio), se conserva la respuesta original. Laravel (`esTextoVerificado`) acepta de 2 a 3 bloques separados por una línea en blanco cuando cada bloque es el texto exacto de una ficha citada.
- **Memoria:** 5 turnos (edades de 0 a 4) y 10 minutos. Orden de despliegue: Laravel primero (acepta edades hasta 4), Horus AI después; con el orden inverso solo se perdería la memoria de esos turnos, sin errores visibles.
- **Gemini apagado (25/11/2026):** el selector opcional (`app/services/gemini.py`), que elegía entre las tres mejores fichas sin redactar texto, se desactiva quitando `GEMINI_API_KEY` en Railway (`horus-ai`) y en el `.env` local. Así el motor es determinista, local y coherente con «sin modelos generativos». El código se conserva y vuelve a activarse con la variable.
- **Rendimiento (25/11/2026):** `contains_phrase` compila cada frase una sola vez (`lru_cache`); en la evaluación léxica la mediana bajó de unos 88 ms a unos 9 ms por consulta y el p95 de 215 ms a 30 ms, con los mismos resultados.

## Cortesía y botones propios (25/11/2026)

Saludos, agradecimientos y despedidas reconocen más variantes (`intents.json`) y admiten palabras de cierre («listo, gracias») sin cambiar sus tres textos. Una consulta acompañada de cortesía sigue tratándose como consulta (`apps/ai/tests/test_cortesia_y_cursos_inexistentes.py`). El mensaje pasa antes por el filtro de privacidad de Laravel, que tomaba «holaa» y «holaaa» como un nombre escrito solo; esas dos formas exactas están ahora en su lista de saludos, y un saludo seguido de un nombre sigue bloqueado. `FiltroPrivacidadChatTest` recorre los saludos, agradecimientos y despedidas de `intents.json` para detectar futuros desajustes. Otras formas más alargadas («holaaaa») siguen bloqueadas. Los botones de WhatsApp de mensajes propios del chat (orientador, 👎, cotización) se conservan al recargar: de lo guardado en la pestaña solo se recupera el mensaje y la dirección se vuelve a armar con el número oficial (`whatsappGuardado`). Guion para la defensa: [sustentacion-asistente.md](sustentacion-asistente.md).

**Duración o inicio sin curso elegido.** «¿Cuánto dura?» o «¿cuándo empiezan?» muestran ese dato de los cursos publicados (hasta cinco), en lugar de «No tengo un calendario vigente…», que contradecía a las fichas. Solo se aplica si **todas** las palabras son de ese tipo de pregunta (`BARE_DATE_WORDS`): con cualquier otra («soporte», «garantía», «atender») o con «horario», que también es el de atención, nada cambia. Con un curso en foco se responde solo ese; sin catálogo vigente o sin el dato se mantiene el texto anterior. El formato es el que Laravel ya validaba.

**Lo que se recuerda tras una lista.** `focus_ids` admite dos entradas. Si una respuesta de seguimiento lista **todos** los cursos (más de dos) en el orden del catálogo, se guarda `cat_listado_formacion`; antes se guardaban los dos primeros y «¿cuándo empiezan?» omitía el resto. Las fechas y horarios conservan ese listado como foco (antes «¿Qué cursos ofrecen?» → «¿Cuál es el horario?» daba el horario de atención). Limitación: tras una lista parcial u ordenada por duración se siguen recordando solo dos.

**Otro asunto con un curso en foco.** Si la pregunta nombra soporte, mantenimiento, instalación, cámaras, garantía, convenio, asesoría, servicio u otra palabra de `NOT_A_COURSE_WORDS`, sin decir «curso», «clases» o «taller» y sin que esté en el título del curso, no se responde con ese curso. Es una lista cerrada: un asunto que no figure en ella todavía puede responderse con el curso en foco.

## Banco de frases de visitantes (26/11/2026)

`apps/api/tests/Fixtures/chat-frases-visitante.json` reúne frases y conversaciones de varios mensajes. Lo prueban las dos partes: `ChatFrasesVisitanteTest` (que el filtro de privacidad no las bloquee) y `apps/ai/tests/test_banco_visitante.py` (qué responde el motor, arrastrando el estado). Las respuestas reales se guardan en `chat-frases-replies.json` (`REGENERAR_BANCO=1` las regenera) y `HorusJuradoTest` comprueba que Laravel las acepte. Una frase nueva se añade al banco, no solo a una de las capas.

El filtro bloqueaba como nombre 20 de 171 frases comunes elegidas para la prueba (no es una tasa de uso real). `MENSAJES_COMPLETOS_SEGUROS` y `SALUDO_ALARGADO` las dejan pasar **solo si son el mensaje completo**; con cualquier letra o cifra adicional deciden las reglas de siempre. Siguen bloqueadas a propósito «dale», «aja» y «comu», que escritas solas pueden ser un nombre. También sigue bloqueado «¿certificado a nombre de quién?»: al desbloquearlo, la única respuesta posible tenía coincidencia «baja» y hacerlo reconocible empeoraba la evaluación. Tampoco pasan combinaciones no listadas («wenas bro»). Es un cambio en la barrera de privacidad, todavía sin auditoría independiente.

Laravel rechaza una respuesta afirmativa con coincidencia «baja»: el visitante vería la contingencia. Al desbloquear una frase hay que comprobar también su respuesta en `HorusJuradoTest`.

### Elegir un curso y respuestas corregidas (26/11/2026)

- **Elegir sin escribir el título:** «quiero ese», «mejor redes», «opción 1», «2» o «y el otro» (`_choose_course`). La posición se toma de los cursos mostrados antes. Si no está claro, se pregunta «¿Te refieres a…?» con los nombres (hasta cinco); nunca se elige al azar. Si se venía hablando de convenios u otro tema, no se ofrece un curso.
- La pregunta no puede pedir que la persona «escriba el nombre»: la barrera de datos personales de Laravel la rechaza, y debe seguir haciéndolo.
- **«Información» sola** lleva a los canales de contacto (criterio de la evaluación). «Quiero información» o «información porfa» muestran los temas disponibles.
- **Grabación de clases:** se responde con el texto de «Modalidades de los cursos».
- **Nivel:** ninguna ficha lo publica; se dice que no está confirmado.
- «¿Quiénes son?» ya no se recorta a «Más información en la página Quiénes somos»; «¿con quiénes?» tras los convenios muestra la lista.
- Horario, modalidad y matrícula solo se ofrecen tras «ok» o «chévere» si se venía hablando de cursos.

### Cómo se estudia y se matricula, según Horus Group (26/11/2026)

Lo confirmó el propietario el 26/11/2026 y se añadió a las fichas, sin quitar nada de lo que publica la web:

- **Virtual:** clases en vivo por Google Meet, que quedan grabadas (`cursos_modalidades`, `alcance_nacional_virtual`). No se afirma quién envía el enlace ni cuánto tiempo duran las grabaciones.
- **Presencial:** se indica a cada estudiante qué días son las clases teóricas y las prácticas.
- **Semipresencial:** se conserva el texto de la web, por decisión del propietario, para que chat y web coincidan. Si se deja de ofrecer, hay que cambiar ambos a la vez.
- **Matrícula** (`proceso_matricula_pasos`, quinto paso nuevo): la persona envía su comprobante y sus datos por WhatsApp, el personal registra la matrícula en el curso pedido y la agrega al grupo de WhatsApp del curso. La primera línea no cambia: la usan el panel (`SenalesAsistente`) y las pruebas.

Preguntas que ahora se reconocen (`conversation_glue.py`, en `_about` y `_glue`):

| Preguntan | Responde |
|---|---|
| Plataforma, Zoom, Meet, clases en vivo, «¿cómo son las clases?» | Modalidades |
| Grupo de WhatsApp, «¿qué pasa después de matricularme?», «¿cómo ingreso a las clases?» | Pasos de matrícula |
| «¿Qué días son las clases presenciales?» sin curso elegido | Modalidades; con un curso elegido, su horario publicado |
| Edad mínima, niños, adultos mayores, laptop o computadora, turnos | «No tengo ese dato», con asesor |

Cada regla tiene pruebas de que no captura otras preguntas («curso de computación», «no me agregaron al grupo», «turno de atención»). «Mi hijo de 10 años puede estudiar» sigue bloqueada por el filtro de privacidad (dato de un menor) y queda en `bloqueadas_a_proposito` del banco.

### Oraciones largas: cómo se mide (26/11/2026)

La evaluación de `data/evaluation.json` no tiene consultas de 15 palabras o más (mediana: 6). Para medir oraciones como las que escribe un visitante hay dos archivos separados:

- `apps/ai/data/oraciones_desarrollo.json`: con estas oraciones se buscan y prueban las correcciones. `test_oraciones_largas.py` exige que **ninguna** reciba una respuesta equivocada.
- `apps/ai/data/oraciones_examen.json`: **solo mide**. No se usa en pruebas ni para elegir reglas, y no se modifica para aprobar. `test_oraciones_examen.py` solo comprueba que esté bien formado.

`scripts/medir_oraciones.py [--archivo …] [--filtro] [--detalle]` clasifica cada respuesta en **acierta**, **aclara** (pide aclaración), **equivocada** (responde otra cosa) o **bloqueada** (con `--filtro`, pasa cada oración por `FiltroPrivacidadChat` con el PHP local). Usa el catálogo de prueba (`chat-jurado-catalogo.json`) y no guarda nada. Las oraciones son sintéticas, escritas por el equipo. El examen con preguntas de otras personas, cuando exista, se informa aparte.

| Examen (120 oraciones) | Acierta | Aclara | Equivocada | Bloqueada |
|---|---|---|---|---|
| Antes (26/11/2026) | 47 % | 30 % | 17 % | 7 % |
| Tras corregir respuestas equivocadas, horario y solo educación | 57 % | 24 % | 12 % | 7 % |

Cambios de esa etapa:

- **Horario confirmado** por el propietario: lunes a viernes de 9:00 a 13:00 y de 15:00 a 18:00; sábados de 9:00 a 13:00. La web (Contactos, preguntas frecuentes, `contactos.ts`) y `horario_atencion` dicen lo mismo, y el chat ya no advierte de discrepancias.
- **Solo educación:** pedir instalar, reparar o cotizar cámaras, cableado o equipos recibe el mensaje de fuera de alcance, sin nombrar esos servicios (`asks_discarded_service`). Querer *aprender* esos temas sigue siendo una consulta educativa.
- «Mi oficina», «principiante», «personal» o «empresa» ya no se toman como el nombre de un curso (`TITLE_COMMON_WORDS`): «me acerco a su oficina» respondía sobre «Ofimática para oficinas».
- Nuevas reglas con textos verificados: pasos de matrícula aunque se nombre un curso, práctica perdida, validez de certificados (no en el extranjero: eso sigue sin confirmar), privacidad de la información enviada, reclamos por falta de respuesta, «desde hace cuánto existen» (no publicado), hablar con una persona y despedidas como «lo voy a pensar».

### Fase 3: entender la oración larga (26/11/2026)

Tras la prueba del propietario en producción («¿cuál es la dirección?» → «¿Tu consulta es sobre «Ubicación de la sede»?» → «sí» → la misma pregunta otra vez) se simularon 225 turnos de visitante: 178 preguntas sueltas y 12 conversaciones. Quedaron en `apps/ai/data/oraciones_visitante.json` como **banco de ajuste**, no como examen; `medir_oraciones.py --archivo data/oraciones_visitante.json` mide también las conversaciones, reenviando el estado de cada turno como la web. Se corrigieron 5 calificaciones mal puestas de ese banco, todas con respuestas válidas («¿me dan certificado físico o digital?» respondido con el certificado de cada curso, por ejemplo).

| Medición (con el filtro de Laravel) | Acierta | Aclara | Equivocada | Bloqueada |
|---|---|---|---|---|
| Examen, antes de la fase 3 | 57 % | 24 % | 12 % | 7 % |
| **Examen, después** | **70 %** | 15 % | **8 %** | 7 % |
| Visitante (225), antes | 64 % | 20 % | 14 % | 3 % |
| Visitante (225), después | 85 % | 8 % | 4 % | 3 % |
| Desarrollo (73), después | 88 % | 12 % | 0 % | 0 % |

La evaluación de `data/evaluation.json` no bajó: comportamiento 0,981 → 0,987, intención 0,981 → 0,987, primer resultado 0,688, recomendación 1,0, 0 sin sustento y conversaciones 1,0 (también solo con estado). La meta del plan (80 % de aciertos y como máximo 5 % de equivocadas en el examen) **todavía no se alcanza**; 8 de las 120 oraciones las bloquea el filtro de privacidad (fase 5).

Qué cambió:

- **El «sí» confirma la aclaración.** El estado guarda como foco la opción propuesta («¿Tu consulta es sobre «Galería…»?» → «sí» → la ficha de la galería). Con dos opciones, «el segundo» elige la segunda. También valen «sí, eso», «exacto» y «correcto».
- **Ficha que gana con holgura** (`_dominant`). Se responde sin aclarar cuando la primera ficha coincide por frase clave (o por tres términos), supera 0,45 y ninguna otra llega al umbral de aclaración (o queda a 0,15 o más). No basta una palabra suelta compartida: «¿venden celulares de segunda?» sigue sin ir a contacto. También vale cuando «necesito», «me gustaría» o «ayudar» suenan a pedir una recomendación («Estoy haciendo mi tesis y necesito asesoría…»).
- **Ubicación y horario de atención** con reglas cerradas: «¿por qué calle están?», «¿en qué parte de Cajamarca están ubicados?» y «Quisiera acercarme en persona a su oficina…» van a la sede, ya no a un curso presencial. «¿Dónde queda la plaza de armas?» no. «¿En qué horario responden por WhatsApp?» o «¿atienden los domingos?» responden con el texto ampliado del horario. Si la pregunta junta ubicación y otro dato, se responden las dos partes.
- **Matrícula:** «Quiero inscribirme en el curso de Comunicación» responde con los 5 pasos verificados, que incluyen el WhatsApp (la web muestra el botón), y deja el curso en foco. Antes daba solo el enlace.
- **Recomendación abierta en una oración:** «Soy secretaria…, ¿qué curso me recomiendan?» o «No sé cuál curso elegir, ¿me ayudas a decidir…?» ofrecen los cursos publicados para elegir. Si nombra un tema («…de redes»), se recomienda ese curso.
- **Preguntas frecuentes antes que datos de todos los cursos:** «Perdí mi certificado del curso…» o «¿puedo cambiarme a otra fecha o me devuelven?» responden la ficha correspondiente, no el certificado ni la fecha de cada curso.
- **Corrector ortográfico:** «tarea» ya no se corrige a «tarjeta» ni «pasado» a «pagado».
- **Cortesía:** «perfecto, entendido», «Hola, ¿qué tal? Quería hacer unas consultas», «¿qué cosas puedo preguntarte?» y «mañana paso por la oficina, gracias». «¿Con quién hablo para coordinar?» deriva a un asesor; «¿con quién hablo?» a secas sigue preguntando por el asistente.
- **Frases clave** añadidas a fichas existentes, sin cambiar sus textos: devoluciones («me arrepiento»), cambios de fecha, certificado perdido, entrega del certificado, capacitación en las instalaciones del cliente, envío del comprobante, modalidades de estudio y dirección de correo.

### Pruebas de un compañero en producción (26/11/2026)

Un compañero hizo 60 pruebas en la web antes de `ca10b51`. Se repitieron en local, con el código actual y un catálogo parecido al publicado (Matemática, Comunicación y Ciencias Sociales). Lo que seguía fallando se corrigió así:

- «Compara los tres cursos» y «¿En qué se diferencian los cursos?» dan la ficha comparativa del listado. «¿Qué diferencia hay entre presencial y semipresencial?» explica cada modalidad con el texto publicado.
- «¿Qué modalidades tienen?» ya no manda al asesor: la modalidad de cada curso está en su ficha.
- Un curso con horario y sin fecha de inicio publicada lo dice: «…el horario es X; la fecha de inicio la confirma un asesor.». Laravel reconstruye la misma frase en `FuentesCatalogoAdvisor`.
- «Dame los detalles del curso X» da la ficha completa (modalidad, duración, horario y certificado). El temario sigue saliendo si se pregunta por él.
- «¿El certificado es físico o digital?» responde lo que dice cada curso publicado.
- El saludo usa el mismo nombre que la ventana: «Horus, el asistente virtual».
- **Datos confirmados por el propietario:**
  - Las fechas de los convenios son las de la web Next.js: CR XIII hasta el 20/11/2026 y CORLAD hasta el 10/12/2026.
  - Los medios de pago son transferencias, Yape, Plin, tarjetas y efectivo, y así lo dicen ahora los pasos de matrícula.
  - Si la vigencia publicada de un convenio ya pasó (`_date_passed`, hora de Perú UTC−5), el chat no lo da por vigente: responde la frase verificada «la vigencia de este convenio fue hasta…, y si se renovó lo confirma un asesor».
- **A revisar cuando venza un convenio:** el del CORLAD (10/12/2026) necesita una frase como la de CR XIII en su `detail_answer`, porque la suya dice «está vigente hasta…».

Siguen sin resolverse:
- «Quiero matricularme, ¿te paso mi DNI?» lo bloquea el filtro de privacidad (fase 5).
- «¿Con el certificado gano seguro la plaza?» pide confirmar el tema antes de responder.
- La web todavía muestra «Vigente hasta 20 de noviembre del 2026» en el convenio CR XIII.
- «quiero estudiar algo de computación» dice que no hay ese curso: el catálogo no usa esa palabra.
- «¿Esta conversación queda guardada?» no tiene una ficha publicada que lo responda.
- Algunas preguntas con dos fichas parecidas siguen pidiendo elegir («¿hay examen final?»).

## Recomendación por objetivo y clasificador de respaldo (27/11/2026)

- **Recomendación por objetivo** (`AdvisorEngine._goal_course` y `glue.goal_subjects`):
  - Ejemplos: «me cuesta hablar en público» → curso de comunicación; «quiero ser técnico de redes» → curso de redes; «no sé nada de computadoras» → curso de ofimática.
  - El léxico de metas es cerrado y cada tema se busca en el título y la descripción de los cursos **publicados**. Si ningún curso vigente lo menciona, o lo mencionan varios, se sigue el flujo general.
  - No actúa cuando el mensaje pide inscribirse, pide información de un curso que nombra o busca capacitación para una empresa.
  - Medición: banco de 870 casos, de 731 a 747 aciertos y de 64 a 57 equivocadas. En la comparación caso por caso de los cuatro bancos, 13 mejoran y ninguno empeora.
- **Clasificador de respaldo** (`app/services/intent_classifier.py`, entrenado con `scripts/entrenar_clasificador.py`):
  - Modelo TF-IDF con SVM lineal, exportado a `data/clasificador.json` e inferido en Python puro (~5,5 MB de RAM, ~1,5 ms).
  - Solo actúa cuando el motor iba a pedir aclaración: con confianza ≥ 0,4 y margen ≥ 0,2 responde el texto exacto de una ficha de `knowledge.json`.
  - Nunca se entrena con `oraciones_examen`, `oraciones_examen_sellado` ni la parte `test` de `evaluation.json`.
  - **Apagado** (`HORUS_AI_CLASSIFIER_ENABLED=false`, valor por defecto). Por decisión del propietario se decide al medir el examen sellado al final. Para medirlo: `scripts/medir_oraciones.py --clasificador`.
  - Medición en el examen nunca usado para entrenar: 99 → 101 aciertos, con las mismas 7 equivocadas.

## Publicar cursos nuevos para que el asistente los recomiende (guía para quien publica en el panel)

Al publicar, cambiar o retirar un curso en el panel, Laravel envía al asistente el catálogo vigente (`ExportadorCatalogoAdvisor`): título, resumen, temario (junto con los detalles), modalidad, horario, duración, certificación y fecha de inicio.
- No hace falta tocar código.
- Un curso retirado deja de aparecer en el chat.
- Un curso nuevo se puede recomendar desde que está vigente.

**Cómo redactar la ficha para que el asistente la encuentre.** Hoy el asistente busca por palabras, no por significado: recomienda un curso cuando el visitante usa palabras que están en su ficha.
- **Título claro y con el tema:** «Repostería básica», «Inglés para principiantes», «Primeros auxilios».
- **Resumen que diga para quién es y qué logra, con las palabras que usaría un visitante.** Por ejemplo: «Para quienes quieren aprender a preparar postres y tortas, emprender un negocio de repostería o mejorar en la cocina de casa».
- **Temario con los temas concretos:** «tortas, bizcochos, decoración con fondant, costos para vender». Cada tema es una palabra más que el asistente reconoce.
- **No escribir datos personales** (nombres de docentes o alumnos, teléfonos, correos): el exportador rechaza la ficha. El temario y los detalles admiten hasta 1500 caracteres.

**Límites que hay que conocer:**
- **Metas dichas con otras palabras.** «Quiero poner una pastelería» no encuentra «Repostería básica» si la ficha no dice «pastelería» o «negocio». Lo que entendería el significado sin depender de las palabras es la búsqueda semántica (embeddings), que ya está programada (`semantic_search.py`) pero apagada. Es trabajo futuro: hay que usar un modelo liviano, medir la RAM en Railway y recalibrar.
- **Las metas del léxico cerrado** (hablar en público, redes, ofimática) solo cubren esas áreas. Un área nueva se recomienda por las palabras de su ficha, no por frases de meta.
- **Filtro de privacidad.** El filtro trata como posible nombre de persona las palabras que no conoce, y su léxico se genera con archivos del repositorio, no con el catálogo publicado (`scripts/privacidad-generar-lexico.php`).
  - Prueba del 27/11/2026: «Repostería», «Soldadura», «quiero aprender repostería peruana» o «tienen gasfitería domiciliaria» se bloquean como `nombre_personal`.
  - En cambio, «quiero el curso de barbería», «curso de soldadura eléctrica», «quiero aprender inglés básico» o «marketing digital» pasan.
  - Al publicar cursos de un área nueva: agregar sus palabras a `apps/api/app/Support/Privacidad/lexico-general.txt`, regenerar el léxico, correr las pruebas de privacidad y desplegar.
  - Incorporar automáticamente las palabras del catálogo al léxico es posible, pero debilitaría la barrera: un nombre escrito en una ficha pasaría a ser «conocido». Requiere una decisión del propietario.

## Métricas del asistente y votos 👍/👎 (25/11/2026)

**Qué se guarda en `chat_logs`** (nunca el texto del visitante):

- `intent_detectado`: la intención de la respuesta aceptada de Horus AI, de la lista cerrada `App\Support\IntencionesAsistente` (lo desconocido queda como `otro`). En contingencia queda vacía.
- `canal`: `web` (sitio público) o `admin` (probador del panel).
- `voto`: `up` o `down`. Solo cuenta el primero.
- `abrio_whatsapp`: verdadero si desde esa respuesta se pulsó un botón de WhatsApp del chat (no que se haya enviado un mensaje). Se anota con `POST /api/public/v1/chat/contacto` (vía `/api/chat/contacto` de Next), con la misma referencia firmada y el mismo límite que el voto. Los botones sin respuesta propia (👎, orientador) se atribuyen a la última respuesta; sin ninguna consulta previa no se registra, y los botones de la web fuera del chat no se miden.

**Cursos en el chat:** las tarjetas de curso llevan la portada de su ficha pública, solo si la web la mostraría en ese momento (ficha autorizada y vigente, catálogo web encendido, mismo título y archivo que sea de verdad una imagen). El listado detallado de dos o más cursos se muestra como comparación (`chat-comparacion.ts`): lo común una vez y las diferencias en cada tarjeta; si una línea no se puede interpretar, se muestra el texto original. El curso de la barra «Consultando» sigue a las respuestas (`chat-foco.ts`) y, ante la duda (incluida una petición de matrícula que pueda ser de otro curso), no se nombra ninguno. Si se pide un dato (horario, precio, duración, modalidad…) de un «curso o taller de X», o la consulta iba a pedir una aclaración, y ningún **curso publicado** menciona X, Horus AI responde que no lo tiene (`_names_unpublished_course`; solo mira el contenido de los cursos, porque en el índice completo «contabilidad» coincidía con «contacto»). Esa respuesta borra el curso en foco del estado; la web ofrece «Ver cursos disponibles» y WhatsApp. Sin la palabra «curso» o «taller» («¿tienen contabilidad?») se sigue pidiendo una aclaración. Si cambia el formato de las fichas en `catalog.py` (`_answer`), revisar la comparación.

**Mensaje de WhatsApp** (`apps/web/src/lib/chat-whatsapp.ts`): los botones del chat abren WhatsApp con un mensaje ya escrito, que la persona puede cambiar: reclamo, matrícula (con el curso si se conoce), consulta sobre un curso o general; la cotización tiene el suyo. Son textos fijos y, como mucho, llevan el nombre del curso; nunca lo que escribió el visitante. Usa frases de las respuestas oficiales y una prueba comprueba que coinciden con `SenalesAsistente`.

**Etapas de cada conversación** (`App\Support\SenalesAsistente`, única definición para etiquetas, filtro y conteos): interesado, quiere matricularse, pidió contacto, consultó reclamos y abrió WhatsApp. Las cuatro primeras se calculan al leer, con el tema guardado y frases de las respuestas oficiales, así que también cubren el historial (como estimación). Si cambia una de esas frases en `knowledge.json`, revisar la clase: `SenalesAsistenteTest` lo detecta.

**Voto:** `POST /api/public/v1/chat` entrega `respuesta_id`, una referencia firmada ligada a la fila y a la sesión. El navegador la envía a `/api/chat/voto` (Next), que llama a `POST /api/public/v1/chat/voto` con el token de servicio. Límite propio `chat-voto-bff`: 20 por minuto y por visitante. Una referencia alterada o de otra sesión responde 404.

**Panel** (`/admin/academico/asistente`, permiso `asistente.ver`), en tres pestañas con su propia dirección (`?vista=`):

- **Resumen** (7 o 30 días, `MetricasAsistente`): conversaciones, hasta dónde llegan (por etapa), consultas, porcentaje respondido (solo sobre consultas con origen anotado), votos útiles, consultas por día (hora de Lima) y temas. Saludos, gracias y despedidas se agrupan. «Sin dato» se mide solo sobre las consultas con tema registrado.
- **Conversaciones** (`ConversacionesAsistente`, 10 por página): agrupadas por sesión y presentadas como «Visitante» más un código de 4 caracteres; el `session_id` no viaja al panel. La pregunta no se guarda: se muestra el tema y la respuesta. Cada tarjeta muestra sus etapas y el comienzo de la última respuesta, y distingue «Privacidad» (bloqueo por datos personales) de «Contingencia»; el enlace largo de WhatsApp se resume. Filtros por texto de la respuesta, etapa, tema, resultado, voto y origen. La etapa se aplica a la conversación completa (dentro del periodo y origen elegidos); los demás, a cada respuesta. El probador queda oculto salvo que se elija ese origen.
- **Probar asistente:** el probador, con preguntas de ejemplo.

La lista de intenciones PHP históricas ya no se muestra (la tabla `chat_intents` no cambia). Las filas anteriores a la migración no tienen canal y cuentan como del sitio público.

**Migraciones `2026_11_25_000104_add_metricas_to_chat_logs` y `000105_add_abrio_whatsapp_to_chat_logs`:** agregan tres columnas nulas (`canal`, `voto`, `abrio_whatsapp`); no modifican filas. Se aplican juntas (en producción, el 25/11/2026, tras un respaldo manual verificado). Probada solo en SQLite en memoria (instalación nueva y base con registros). El despliegue **no** la aplica. Antes de aplicarla, el chat funciona igual, el voto y el clic en WhatsApp responden 503 sin avisar al visitante y el panel muestra los votos como no disponibles e incluye las consultas del probador.

**Aplicación en producción (requiere autorización expresa del propietario):**

1. Respaldo de la base con `pg_dump -Fc` y comprobación de que el archivo se lista con `pg_restore --list`.
2. `railway ssh --service horus-api`, luego `php artisan migrate:status` y confirmar que las únicas pendientes son `000104` y `000105`.
3. `php artisan migrate --force`.
4. Enviar una consulta en la web, votar y comprobar la cifra en el panel.

Reversión: `php artisan migrate:rollback --step=2` revierte las dos últimas migraciones (`000105` y `000104`) y elimina las tres columnas: se pierden votos, canal y clics. Confirmar antes con `migrate:status` que son las dos últimas aplicadas.
