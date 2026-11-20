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
| Transporte Laravel | HORUS_ADVISOR_URL, TOKEN, SIGNING_KEY | Loopback HTTP estricto en el preflight; secretos de al menos 32 caracteres |
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
| Contenedores separados | Diferida: loopback de cada contenedor no es el del otro; no cambiar binding/transporte ni añadir una topología sin autorización |
| PaaS o varios hosts | Diferida: requiere definir red privada, TLS interno, persistencia y distribución del catálogo; no se habilita allow_non_loopback |

Propuesta inicial para dimensionar, no garantía: 4 vCPU y 8 GB RAM para evaluar un host conjunto de baja carga, reservando margen para PostgreSQL, PHP y reconstrucción candidata. La medición histórica Python registró alrededor de 1,85–1,9 GB de memoria privada; al construir el candidato coexisten componentes y debe medirse el pico real. El equipo local tiene 8.300.388.352 bytes RAM y 12 procesadores lógicos; no es benchmark de un VPS.

Modelo local inventariado: 499.565.457 bytes bajo models al preparar E, sin cargarlo. Dependencias CPU históricas ~1,5 GB; reservar al menos 5 GB para Python/modelo/releases y dimensionar aparte PostgreSQL, adjuntos, logs y respaldos. El índice no persiste: medir arranque antes de abrir tráfico. D6 tardó aproximadamente 2 minutos desde inicio de proceso hasta disponibilidad MiniLM en esa ejecución; no es tiempo garantizado. La referencia B de 500 documentos (~286 s) es un riesgo de cold start/caducidad de 300 s: no usarla como capacidad productiva aceptada. Se requiere benchmark del catálogo objetivo, sin ampliar timeouts para ocultar fallos.

Sin proveedor, región, carga, tamaño de base, retención y SLA definidos, no existe base suficiente para cotizar infraestructura. No se contrata ni publica nada.

Plantillas en ops/horus-ai/*.service.example: sin sección Install, sin instalación o enable, marcador externo OPERATION_AUTHORIZED requerido, credenciales fuera del repositorio y reinicios limitados por StartLimitBurst/Interval. El worker se recicla cada hora; FastAPI reinicia solo ante fallo. No son unidades validadas en Linux en esta fase. Resolver cuentas/rutas, PCNTL, permisos de storage/config cache, filesystem y soporte de IPAddressDeny antes de usarlas. La unidad Python solo permite loopback; PostgreSQL nunca está en su entorno. No duplicar un Supervisor/systemd existente: elegir un gestor con el operador.

La separación health/readiness y el coste de replicar memoria por proceso se apoyan en [FastAPI, conceptos de despliegue](https://fastapi.tiangolo.com/deployment/concepts/). Los tiempos retry_after/timeout y gestión de workers siguen [Laravel 12, colas](https://laravel.com/docs/12.x/queues). Las políticas de proceso propuestas se contrastaron con [systemd.service](https://www.freedesktop.org/software/systemd/man/latest/systemd.service.html); requieren validación en la distribución elegida.

## Despliegue y habilitación futura: NO ejecutar ahora

1. Obtener autorización de infraestructura y de activación por separado; resolver la aceptación independiente de privacidad pendiente sin confundirla con D6. No leer reservas restringidas salvo autorización específica.
2. Confirmar hardware, catálogo objetivo, métricas, cuentas sin privilegios, HTTPS, firewall, restricciones de FastAPI y respaldo/restauración probados. Preparar release con versiones/lockfiles existentes; no descargar modelos en runtime.
3. Respaldar configuración protegida, código y snapshot firmado. PostgreSQL requiere procedimiento propio y autorización; esta fase no lo conecta ni migra.
4. Suministrar secretos externos y configuración coherente. Mantener web HORUS_ADVISOR_ENABLED=false. Un proceso interno autorizado puede sincronizar con su propia configuración enabled=true sin habilitar al proceso web; el observer web seguirá apagado y la renovación cubrirá publicaciones mientras dure esa separación.
5. Ejecutar check/preflight sin tráfico, iniciar FastAPI bajo gestor autorizado, esperar semantic=loaded. Iniciar wrapper del worker, habilitar scheduler/renovación únicamente por autorización operacional. Despachar sincronización nueva, verificar firma y versión activa coincidente, heartbeat y ausencia de trabajos fallidos. No servir mientras exista pending/stale/error.
6. Repetir un smoke real restringido sobre publicaciones permitidas y regresión de privacidad autorizada. Verificar cache, retirada, tarjetas, fallback y zero HTTP para bloqueados. Si falla privacidad: detener; no relajar filtros.
7. Solo con aprobación pública expresa, preparar canary mediante restricciones del reverse proxy o entorno interno existente. No existe rollout porcentual implementado en el chatbot; diseñar y autorizar su restricción antes de cambiar el flag web. Si se usa config cache, reconstruir/reiniciar procesos afectados con control de secretos.
8. Apertura gradual definida por el operador, con supervisión y responsables. Desactivar inmediatamente por PII transmitida/conservada, fuentes retiradas, enlaces no permitidos, versión no coincidente o pérdida de readiness. Mantener fallback institucional.

## Rollback y recuperación: NO ejecutar ahora

Primero apagar Advisor en el proceso web y aplicar correctamente su configuración cacheada/reinicio; preservar fallback. Detener renovaciones para evitar carreras, drenar/parar worker con timeout y demostrar ausencia de procesos antes de intervenir locks. Conservar evidencias agregadas sin PII. Restaurar release compatible anterior y configuración, sin reset/clean del checkout compartido.

No restaurar automáticamente un catálogo antiguo firmado: puede contener contenido retirado o estar vencido. Conservarlo para diagnóstico, mantener web apagado y pedir a Laravel actual un snapshot nuevo firmado/versionado. Python mantiene la última versión válida ante error, pero no debe servirla si no supera readiness/versión/caducidad. Si un job caducó, crear uno nuevo; evitar queue:retry all. No vaciar caché global ni cola de otros módulos. El cambio de versiones invalida las respuestas según la política existente.

Reiniciar FastAPI de forma controlada bajo el gestor elegido y esperar reconstrucción; reiniciar worker tras resolver configuración/TTL, verificar heartbeat, pendientes/fallidos y confirmación de catálogo. No extender los límites para esconder cargas excesivas. Si no se recupera en la ventana acordada, mantener contingencia y escalar. Reversión de esquema o datos PostgreSQL no forma parte de E y requiere autorización independiente.
