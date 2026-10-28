# Sprint 04: chatbot híbrido

Fecha: 28 de octubre de 2026. Cambios locales, sin commit ni push.

## Arquitectura anterior y nueva

Se conservan `POST /chat/mensaje` y `POST /admin/asistente/mensaje`, sesión, CSRF, permisos del panel y el contrato `respuesta`/`intent`. Se añade `fuente` (`local`, `aclaracion`, `respaldo`, `openai`, `ocupado`).

Antes, `AsistenteVirtualService` elegía una intención por coincidencia y prioridad; cualquier consulta desconocida intentaba llamar a OpenAI. El prompt incluía todas las intenciones y se mostraba texto libre generado. `ChatLog` almacenaba el mensaje íntegro; el navegador acumulaba mensajes sin límite y desplazaba siempre al final.

Ahora, `ChatIntentMatcher` normaliza acentos y erratas explícitas, distingue preguntas de menciones incidentales, evita usar saludos incluidos en preguntas como respuesta y pide aclaración ante varios asuntos específicos. El servicio aplica filtros de privacidad y alcance, resuelve localmente y restringe OpenAI a orientación/comparación sobre cursos y servicios. No hay embeddings, paquetes nuevos, migraciones ni cambios de infraestructura.

## Información local y fuentes

`ChatKnowledge::intents()` mantiene las respuestas verificadas y sus variantes. Las incorporadas siguen funcionando sin seeder; las desactivaciones en `chat_intents` se respetan y las intenciones personalizadas activas se conservan. No se ejecutó `ChatIntentSeeder` contra datos persistentes.

Se añadieron respuestas para requisitos, horarios/fechas de cursos y el resumen de servicios. Continúan cursos, matrícula, capacitaciones, modalidades, contacto, ubicación, atención, cotizaciones y las FAQ existentes.

Fuentes contrastadas en el repositorio:

| Información | Fuente pública |
| --- | --- |
| Modalidades de cursos | `resources/views/pages/educacion/cursos.blade.php` |
| Formación empresarial y personalizada | `resources/views/pages/educacion/capacitaciones.blade.php` |
| Servicios | Vistas de `resources/views/pages/tecnologias/` y sus rutas públicas |
| Teléfono, correo, dirección y atención | `resources/views/pages/contactos.blade.php` |
| Diferencia en el horario publicado | `resources/views/components/schedule-location.blade.php` |
| FAQ y políticas existentes | `resources/views/pages/legales/` |

No se publica una lista general de documentos ni un calendario vigente de cursos en esas páginas; se deriva al asesor. El horario de atención tiene dos versiones públicas: `/contactos` indica 9–18 y el componente de ubicación señala una pausa de 13–15. La respuesta explica la diferencia y solicita confirmación.

**No se consulta `Curso`, matrículas, estudiantes ni otros datos del panel.** `Curso.estado=activo` no equivale a autorización de publicación: no existe ese contrato en la página pública. Se evita convertir precios, horarios o cupos administrativos en hechos públicos. Una prueba crea un curso privado únicamente en SQLite en memoria y comprueba que no se divulga.

## Cuándo se usa OpenAI

Solo para peticiones explícitas de comparación u orientación (`compara`, `comparar`, `oriéntame`, `ayúdame a elegir`) dentro de cursos, modalidades, capacitaciones o servicios, con clave configurada, hechos activos y presupuesto disponible. Las FAQ, consultas desconocidas, ambiguas, privadas y fuera de alcance no requieren API.

Las comparaciones de precios y horarios se resuelven con la respuesta local de confirmación; si faltan datos de fechas, cupos o disponibilidad se solicita aclaración sin API.

OpenAI recibe únicamente la consulta normalizada y un conjunto acotado de hechos públicos incorporados. Devuelve JSON con hasta dos identificadores. El servidor valida lista, identificadores y longitud, y compone la respuesta con los textos verificados. Nunca muestra prosa, HTML ni enlaces generados por el modelo. El modelo ayuda a seleccionar información; no crea hechos nuevos ni recibe el historial del panel. Si la selección no es válida o la llamada falla, se devuelve respaldo sin reintentar.

## Controles y métricas

| Control | Valor |
| --- | --- |
| Solicitudes HTTP | 30/minuto; se conserva el límite público y se añade al chat administrativo |
| OpenAI | 10 intentos/minuto globales y 3 por conversación/minuto, incluidos fallos |
| Entrada | 2.000 caracteres; ID del cliente hasta 64 en ambos endpoints |
| Hechos de contexto para OpenAI | Hasta 6.000 caracteres de texto, más instrucciones e identificadores fijos |
| Salida del modelo | 100 tokens; JSON máximo 1.024 bytes; respuesta ensamblada hasta 1.800 caracteres |
| Timeouts | Conexión 3 s, petición 12 s; navegador 18 s |
| Duplicados | Respuesta reutilizada 60 s, con versión del conocimiento e intención previa en la clave |
| Exclusión mutua | Lock de conversación 30 s; reserva de presupuesto bajo lock global |
| Contexto | Una intención, 15 minutos; sin mensajes libres |
| Historial visible | Máximo 41 mensajes, solo en memoria del navegador |

La conversación se identifica mediante HMAC de la sesión del servidor, el ámbito público/administrativo y el ID del cliente. Otro navegador que copie solo ese ID no hereda el contexto. Una desactivación o cambio de conocimiento invalida las respuestas cacheadas por versión.

`chatbot.metric` registra solo `outcome`: `local`, `aclaracion`, `respaldo`, `openai`, `openai_call`, `duplicate`, `busy`, `budget_exhausted`, `openai_invalid` o `openai_connection_failed`. `openai_call` mide intentos de transporte y `openai` respuestas aceptadas; no deben sumarse como consultas independientes. No se registran texto libre, tokens, IP, DNI ni IDs de sesión en esas métricas. No se añadió un panel analítico ni un contador de facturación.

Las nuevas filas de `chat_logs` contienen `[contenido omitido por privacidad]` en lugar del mensaje y un identificador HMAC. Conservan la respuesta verificada y la intención para diagnóstico. **Los registros históricos no se modificaron ni eliminaron.**

El corpus reproducible `test_simulated_corpus_metrics_distinguish_local_answers_ai_calls_and_duplicates` verifica:

| Resultado | Consultas |
| --- | ---: |
| Local | 20 |
| Aclaración | 4 |
| Respaldo | 7 |
| Selección asistida aceptada | 1 |
| Duplicado reutilizado | 1 |
| Total | 33 |

Hubo **1 llamada HTTP simulada**, 32 consultas sin llamada y 32 filas de diagnóstico. Las 20 FAQ produjeron cero llamadas. Estos datos describen el corpus de prueba; no estiman ahorro monetario ni tráfico de producción.

## Experiencia y seguridad

Se conserva la identidad visual. Hay acciones con rutas internas de una lista cerrada, renderizado con `x-text`, estados de carga y mensajes diferenciados para 419/429 y fallos de conexión. Se conserva el borrador sin reenvíos automáticos, se bloquea doble envío y se tolera almacenamiento del navegador deshabilitado. La respuesta comienza visible y se respeta al lector que retrocede en el historial durante la espera.

Pruebas de seguridad: instrucciones maliciosas, salida inventada o identificadores ajenos del modelo, datos administrativos, correos y secuencias numéricas personales, aislamiento/expiración del contexto, límites, timeout, desactivación de intenciones, duplicados y conservación del propietario del lock. Los filtros de palabras son conservadores y no constituyen un detector exhaustivo de datos personales; la defensa principal frente a contenido inventado es mostrar únicamente hechos permitidos.

## Validación

- Chatbot: 55 pruebas, 217 aserciones, aprobadas.
- Suite completa SQLite en memoria: 513 pruebas, 2.499 aserciones, aprobadas (177,61 s).
- JavaScript: 7 pruebas aprobadas con `npm test`.
- Larastan: sin errores en el alcance configurado (`app/Support`, `app/Services`, nivel 5).
- Pint: aprobado sobre los PHP de este sprint; no se reformateó el trabajo ajeno.
- `npm run build`: aprobado.
- Edge: respuesta local, respaldo, móvil 390×844, enlaces internos, Enter, Escape, foco y 429 simulado con borrador conservado.
- `git diff --check`: aprobado.

La suite PHP utiliza SQLite `:memory:`. El smoke de Edge utilizó una SQLite desechable fuera del repositorio, sin lectura de `.env`, con OpenAI desactivado, HTTP saliente bloqueado y sin otras conexiones configuradas. Los mocks HTTP no consumen créditos. La evidencia no prueba PostgreSQL, disponibilidad de OpenAI ni despliegue.

## Inventario exclusivo del sprint

- `app/Services/AsistenteVirtualService.php`
- `app/Support/ChatIntentMatcher.php` (nuevo)
- `app/Support/ChatKnowledge.php`
- `config/chatbot.php` (nuevo)
- `app/Http/Controllers/ChatWidgetController.php`
- `app/Http/Controllers/Admin/AsistenteController.php`
- `routes/web.php` (solo límite del chat administrativo)
- `resources/js/app.js`
- `resources/js/chat-client.js` (nuevo)
- `resources/css/chatbot.css`
- `resources/views/components/chat/conversation.blade.php`
- `tests/Feature/AsistenteVirtualTest.php`
- `tests/Feature/ChatbotHybridTest.php` (nuevo)
- `tests/frontend/chat-client.test.mjs` (nuevo)
- `tests/browser/chat-smoke.mjs`
- `docs/sprint-04-chatbot-hibrido.md` (nuevo)

Se preservaron los cambios preexistentes de reportes, matrículas, vistas administrativas y pruebas concurrentes.

## Límites y próxima mejora

Los locks, presupuesto y deduplicación requieren un almacén de caché compartido con soporte de locks entre los procesos de la aplicación. `array` solo sirve como evidencia aislada de pruebas. El límite por conversación puede evadirse cambiando IDs del cliente; el presupuesto global continúa acotando llamadas. No hay garantía de ejecución exactamente una vez después de expirar la caché o ante una caída del proceso.

El vocabulario de erratas es explícito y las reglas pueden pedir aclaraciones adicionales. No se validó la calidad de selección de un modelo real. El texto de respuestas incorporadas sigue requiriendo mantenimiento cuando cambie el sitio; las intenciones personalizadas deben contener únicamente información aprobada para publicación.

Próxima mejora recomendada: definir un contrato editorial de publicación y vigencia de cursos/horarios, resolver el horario institucional contradictorio y solo después conectar esa fuente pública autorizada al chatbot. Así podrá responder con oferta dinámica sin exponer el panel.
