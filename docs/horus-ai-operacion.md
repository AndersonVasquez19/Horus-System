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

## Métricas del asistente y votos 👍/👎 (25/11/2026)

**Qué se guarda en `chat_logs`** (nunca el texto del visitante):

- `intent_detectado`: la intención de la respuesta aceptada de Horus AI, de la lista cerrada `App\Support\IntencionesAsistente` (lo desconocido queda como `otro`). En contingencia queda vacía.
- `canal`: `web` (sitio público) o `admin` (probador del panel).
- `voto`: `up` o `down`. Solo cuenta el primero.
- `abrio_whatsapp`: verdadero si desde esa respuesta se pulsó un botón de WhatsApp del chat (no que se haya enviado un mensaje). Se anota con `POST /api/public/v1/chat/contacto` (vía `/api/chat/contacto` de Next), con la misma referencia firmada y el mismo límite que el voto. Los botones sin respuesta propia (👎, orientador) se atribuyen a la última respuesta; sin ninguna consulta previa no se registra, y los botones de la web fuera del chat no se miden.

**Mensaje de WhatsApp** (`apps/web/src/lib/chat-whatsapp.ts`): los botones del chat abren WhatsApp con un mensaje ya escrito, que la persona puede cambiar: reclamo, matrícula (con el curso si se conoce), consulta sobre un curso o general; la cotización tiene el suyo. Son textos fijos y, como mucho, llevan el nombre del curso; nunca lo que escribió el visitante. Usa frases de las respuestas oficiales y una prueba comprueba que coinciden con `SenalesAsistente`.

**Etapas de cada conversación** (`App\Support\SenalesAsistente`, única definición para etiquetas, filtro y conteos): interesado, quiere matricularse, pidió contacto, consultó reclamos y abrió WhatsApp. Las cuatro primeras se calculan al leer, con el tema guardado y frases de las respuestas oficiales, así que también cubren el historial (como estimación). Si cambia una de esas frases en `knowledge.json`, revisar la clase: `SenalesAsistenteTest` lo detecta.

**Voto:** `POST /api/public/v1/chat` entrega `respuesta_id`, una referencia firmada ligada a la fila y a la sesión. El navegador la envía a `/api/chat/voto` (Next), que llama a `POST /api/public/v1/chat/voto` con el token de servicio. Límite propio `chat-voto-bff`: 20 por minuto y por visitante. Una referencia alterada o de otra sesión responde 404.

**Panel** (`/admin/academico/asistente`, permiso `asistente.ver`), en tres pestañas con su propia dirección (`?vista=`):

- **Resumen** (7 o 30 días, `MetricasAsistente`): conversaciones, hasta dónde llegan (por etapa), consultas, porcentaje respondido (solo sobre consultas con origen anotado), votos útiles, consultas por día (hora de Lima) y temas. Saludos, gracias y despedidas se agrupan. «Sin dato» se mide solo sobre las consultas con tema registrado.
- **Conversaciones** (`ConversacionesAsistente`, 10 por página): agrupadas por sesión y presentadas como «Visitante» más un código de 4 caracteres; el `session_id` no viaja al panel. La pregunta no se guarda: se muestra el tema y la respuesta. Cada tarjeta muestra sus etapas y el comienzo de la última respuesta, y distingue «Privacidad» (bloqueo por datos personales) de «Contingencia»; el enlace largo de WhatsApp se resume. Filtros por texto de la respuesta, etapa, tema, resultado, voto y origen. El probador queda oculto salvo que se elija ese origen.
- **Probar asistente:** el probador, con preguntas de ejemplo.

La lista de intenciones PHP históricas ya no se muestra (la tabla `chat_intents` no cambia). Las filas anteriores a la migración no tienen canal y cuentan como del sitio público.

**Migraciones `2026_11_25_000104_add_metricas_to_chat_logs` y `000105_add_abrio_whatsapp_to_chat_logs`:** agregan tres columnas nulas (`canal`, `voto`, `abrio_whatsapp`); no modifican filas. Se aplican juntas (en producción, el 25/11/2026, tras un respaldo manual verificado). Probada solo en SQLite en memoria (instalación nueva y base con registros). El despliegue **no** la aplica. Antes de aplicarla, el chat funciona igual, el voto y el clic en WhatsApp responden 503 sin avisar al visitante y el panel muestra los votos como no disponibles e incluye las consultas del probador.

**Aplicación en producción (requiere autorización expresa del propietario):**

1. Respaldo de la base con `pg_dump -Fc` y comprobación de que el archivo se lista con `pg_restore --list`.
2. `railway ssh --service horus-api`, luego `php artisan migrate:status` y confirmar que las únicas pendientes son `000104` y `000105`.
3. `php artisan migrate --force`.
4. Enviar una consulta en la web, votar y comprobar la cifra en el panel.

Reversión: `php artisan migrate:rollback --step=2` revierte las dos últimas migraciones (`000105` y `000104`) y elimina las tres columnas: se pierden votos, canal y clics. Confirmar antes con `migrate:status` que son las dos últimas aplicadas.
