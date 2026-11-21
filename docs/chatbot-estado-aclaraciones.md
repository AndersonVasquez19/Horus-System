# Estado visible y aclaraciones precisas — 17 de noviembre de 2026

## Alcance

El propietario autorizó empezar por el indicador de disponibilidad del widget público
y las aclaraciones de consultas ambiguas. Se conserva el nombre «Diegox» y el resto de
los cambios locales preexistentes. No se implementan paneles, votos ni otras mejoras
de la propuesta anterior.

## Disponibilidad

`GET /chat/estado` devuelve exclusivamente `{"estado":"listo"}`, `cargando` o
`no_disponible`, con `Cache-Control: private, no-store`. Tiene un límite propio de
30 peticiones por minuto, separado del límite existente de mensajes.

Laravel consulta Python mediante `HorusAdvisorClient`, reutilizando las restricciones
de URL loopback, autenticación, tamaño, redirecciones y presupuesto HTTP existentes.
El navegador no recibe tokens, rutas internas, versiones, errores ni estados de cola.
El sondeo no envía mensajes ni crea registros de conversación.

- **Cargando:** conocimiento y autenticación configurados, modelo aún cargando y
  estado de catálogo reconocido. No significa que se pueda enviar una consulta.
- **Listo:** modelo cargado, conocimiento y catálogo admitidos por la política actual,
  versión confirmada cuando hay sincronización y autenticación comprobada en `/info`.
- **No disponible:** desactivación, fallo de transporte, respuesta desconocida,
  autenticación rechazada o catálogo no apto después de cargar el modelo.

Un catálogo ausente solo es aceptable cuando la sincronización está desactivada,
igual que en el cliente conversacional existente. No se habilita ningún catálogo.
El indicador es una comprobación puntual, no una reserva de disponibilidad para
la siguiente consulta ni un contador con un tiempo de arranque garantizado.

`resources/js/chat-status.js` consulta únicamente mientras el widget está abierto y
la pestaña visible. Comprueba cada cinco segundos durante la espera y cada quince
cuando está listo; ante HTTP 429 espera treinta segundos. Cancela peticiones al cerrar,
al ocultar la pestaña o al destruir el componente e ignora respuestas tardías.

La vista pública añade una fila con texto accesible y punto de color. Se permite
escribir mientras carga, pero enviar queda deshabilitado hasta «Listo». El borrador
no se borra ni se envía automáticamente al recuperar disponibilidad. Los errores
ofrecen «Reintentar». El probador administrativo conserva su presentación y envío.

## Aclaraciones

Necesidades breves sin objetivo, como «quiero cámaras», «busco cableado estructurado»
o «quisiera soporte técnico», preguntan si se desea información sobre características,
consultar instalación/servicio o saber si hay capacitación publicada.

La pregunta se construye solo para temas institucionales conocidos y verificados.
No afirma que haya cursos, equipos o plazas disponibles. Una ficha editorial pertinente
conserva prioridad. Preguntas explícitas como «qué cámaras tienen» o «me interesa instalar
cámaras» siguen respondiéndose directamente. También se conserva la corrección básica:
«camaras de seguridda» no se convierte en una nueva pregunta innecesaria.

El seguimiento en Laravel entiende «características», «instalación» y «capacitación»
cuando hay un único tema tecnológico público válido. No elige entre dos opciones ni
arrastra un tema de pagos, una referencia desconocida o una sesión sin foco. Aprender
se transforma en una búsqueda de cursos, nunca en una recomendación de instalación.

Se reutilizan los identificadores públicos, separación de sesiones, HMAC y vencimiento
existentes. No se añade almacenamiento de texto libre. Los filtros de privacidad y
los validadores de respuesta se mantienen. La fixture compartida de cuatro respuestas
procede del motor Python real y se comprueba también desde Laravel.

## Archivos principales

- Backend: `routes/web.php`, `ChatWidgetController.php`, `HorusAdvisorClient.php`.
- Cliente: `chat-status.js`, integración focalizada en `chat-client.js`,
  `components/chat/conversation.blade.php` y estilos de la fila en `chatbot.css`.
- Comprensión: `chatbot-python/app/services/chat_engine.py`.
- Regresión: `ChatEstadoTest.php`, `test_aclaraciones_precisas.py`,
  `chat-status.test.mjs`, `chat-aclaraciones-replies.json` y smoke de estado.
- El smoke de historial existente espera ahora «Listo» antes de su primer envío,
  preservando los cambios previos de nombre y sus límites de espera.

## Validación y límites

- PHP relacionado: 150 aprobadas y 823 aserciones, SQLite `:memory:` y HTTP simulado.
- Python relacionado: 47 aprobadas y dos semánticas deseleccionadas.
- Frontend: 65 aprobadas, incluidas nueve pruebas nuevas de sondeo, borrador,
  cancelación, errores, pestaña oculta y ausencia de envío automático.
- `npm run build` aprobado; aviso no fatal de temporización del plugin CSS.
- PHPStan aprobado; Pint de los archivos PHP propios y Ruff aprobados.
- Edge real en `http://127.0.0.1:8765`, exclusivamente con
  `tests/Support/chat-estado-browser-router.php`: cargando → listo, borrador,
  Enter, pregunta validada, caída/reintento, escritorio 1366×900 y móvil 390×844,
  accesibilidad del estado, Escape y devolución de foco.
- `node tests/browser/chat-smoke.mjs --history` aprobado: Inicio → Cursos,
  recarga, enlaces, tarjetas, sesión, cierre/reapertura y vencimiento.

La fixture de navegador es opt-in y loopback, SQLite en memoria por petición,
sesión/caché/correo `array`, cola `sync` y Python simulado. Mantiene la comprobación
CSRF con un nonce ficticio fijo para esa sesión de prueba no persistente. No migra
bases reales, no utiliza perfiles privados y bloquea HTTP no simulado. El navegador
solo permite recursos de la fixture. Las capturas se inspeccionaron visualmente.

La comprobación de navegador no acredita el servicio Python activo ni producción.
Las aclaraciones se cargarán en el siguiente arranque del proceso Python; no se
reinició el servicio real. No se modifican `.env`, credenciales, filtros, límites
HTTP existentes, predeterminados de catálogos ni datos editoriales.

Suites generales nuevas:

- PHP: **3077 aprobadas, cinco fallidas, una riesgosa y una omitida**,
  15657 aserciones. Los fallos siguen correspondiendo a los fixtures de privacidad
  ausentes, con sus proveedores vacíos y métricas sin corpus. No se alteran los
  controles ni se inventan archivos para ocultarlos.
- Python, incluidas las pruebas semánticas con MiniLM local: **379 aprobadas y
  una fallida**. El fallo de `test_respuestas_breves.py` corresponde a
  `chat-breve-catalogo.json`. Se reprodujo con la copia de `chat_engine.py`
  anterior a esta tarea, cargada solo en memoria: una aprobada y una fallida.
- En la primera pasada Python se detectó además una regresión propia en
  «camaras de seguridda». Se corrigió exigiendo un verbo de necesidad antes de
  pedir el objetivo; la nueva suite completa ya no presenta esa regresión.
- `git diff --check` aprobado, con las advertencias LF/CRLF conocidas.

La suite general no se presenta como completamente aprobada. El servidor de la
fixture y los navegadores temporales se cerraron; 8765, 19322 y 19329 quedaron sin
listeners propios. Los informes y capturas auxiliares están en
`tmp/chat-estado-aclaraciones-20261117/`; no se incorporan artefactos generados a Git.
