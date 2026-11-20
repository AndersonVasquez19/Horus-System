# Horus Group SRL — Auditoría independiente final A-01 a A-07

- **Fecha:** 03/11/2026
- **Informe auditado:** `%LOCALAPPDATA%\HorusReports\observaciones-a01-a07-20261103-103239\informe-cierre-a01-a07.md`
- **Modalidad:** solo lectura. Cada afirmación se contrastó con el código, con pruebas repetidas por el auditor y con sondas propias. No se aceptó ningún resultado solo porque figure en el informe de Codex.

## 1. Veredicto

**RECHAZADO** como cierre de A-01 a A-07.

| Hallazgo | Resultado |
|---|---|
| A-04, A-05, A-06, A-07 | Corregidos |
| A-01 | **Sigue abierto (ALTO).** Las 32 respuestas de referencia se aceptan, pero el mismo defecto reaparece en 4 de los 43 registros verificados del conocimiento, y Python los sirve en ambos modos. |
| A-02 | Corrección parcial |
| A-03 | Corrección parcial |

La afirmación del informe «No hay defectos A-01 a A-07 conocidos abiertos» es falsa.

No hay regresión de seguridad. El Advisor sigue apagado por defecto (`HORUS_ADVISOR_ENABLED=false` en `.env.example` y en `config/horus-advisor.php`), así que el estado actual no expone a usuarios reales. No hace falta revertir nada.

**Condición:** no activar Horus AI para usuarios reales hasta cerrar R-01.

## 2. Contraste con el informe de Codex

| Afirmación de Codex | Verificación independiente | Resultado |
|---|---|---|
| HEAD `7914438`, 24 commits locales, 2 stashes, índice sin cambios | HEAD, 0/24 respecto de `origin/anderson`, stashes `52e2f29…` y `d69d6ca…`, 0 archivos en el índice. El SHA-256 del índice coincide (`E92BC6D7…`). | ✅ |
| 62 M, 1 D y 40 sin seguimiento | `git status --porcelain -uall`: 62 M, 1 D, 40 ?? | ✅ |
| Respaldos 135/269/8, más 96 y 3 copias | Recalculados archivo por archivo: 135, 269, 8, 96 y 3 coincidencias, sin fallos ni ausencias. Los manifiestos tienen el SHA-256 declarado. | ✅ |
| 15 rutas propias con su hash final | 14 coinciden y `ChatCursosPublicos.php` está ausente (retirado). | ✅ |
| 88 archivos ajenos sin cambios | Respecto del respaldo previo a la intervención: 88 iguales y 8 distintos, y los 8 son rutas propias de Codex. | ✅ |
| Fixture = artefactos de la auditoría anterior sin alterar | `tests/Fixtures/horus-auditoria-32.json` es idéntico, elemento a elemento, a `probe/replies.json` + `probe/replies2.json` (32/32). | ✅ |
| A-01: 32/32 aceptadas en ambos modos | 32/32 con el fixture. Con respuestas regeneradas por el motor Python real en proceso: léxico 32/32 e híbrido 32/32 (modelo L12 cargado, `mode=hybrid`). | ✅ en esos 32 casos |
| «No hay defectos A-01…A-07 abiertos» | 4 registros verificados se rechazan; Python los devuelve para consultas naturales. | ❌ (R-01) |
| A-02 corregido | Las frases del informe se rechazan, pero se aceptan 29 variantes (ver R-02). | ⚠️ Parcial |
| A-03: los cinco casos llegan a Python | Los cinco pasan, pero variantes cercanas se bloquean (ver R-03). | ⚠️ Parcial |
| A-04 pasa con la variable ausente, `false` y `true` | Repetido: `HorusAiTransicionTest` + `HorusObservacionesTest` dan 115/115 OK en los tres casos. | ✅ |
| PHP 2127 aprobadas + 1 omitida | Suite completa repetida: 2127 aprobadas, 1 omitida, 13555 aserciones. | ✅ |
| Python 165 | 165 aprobadas (todas las marcas). Ruff check y format: 39 archivos OK. Ruff del arnés OK. | ✅ |
| Frontend 46, build | `npm test` 46/46. `vite build` hacia la carpeta temporal OK y produce `app-D54Tl9vg.js`; `public/build` intacto. | ✅ |
| Larastan 0 errores, Pint aprobado | Larastan: `[OK] No errors`. Pint sobre los 7 PHP propios: `passed`. | ✅ |
| Smoke real Edge y arnés FastAPI real | **No repetido**: exigiría iniciar servicios, lo que está prohibido. Solo se ven los JSON resumidos (`real-*.txt`). | No verificable |

## 3. Arquitectura

La cadena es: Robot Blade → `ChatWidgetController` o `Admin/AsistenteController` → `AsistenteVirtualService::procesarMensaje` → `FiltroPrivacidadChat` (entrada) → `HorusAdvisorClient::orientar` → FastAPI `POST /api/v1/chat` con `history: []` → `respuestaValida` (salida) → `{respuesta, intent, fuente}`.

- **Python es el único motor.** `procesarMensaje` es el único consumidor de `orientar()`. No hay llamadas a `ChatIntentMatcher`, `ChatKnowledge` ni OpenAI en la ruta del chat.
- **Restos del motor PHP.** `buscarIntent()` e `intentsDisponibles()` siguen en el servicio sin consumidores. Su docblock dice que es un diagnóstico histórico.
- **Contingencia institucional.** Se mantiene: `?? reply(RESPUESTA_FALLBACK, null, 'respaldo')`.
- **OpenAI.** Ninguna referencia en `app/`, `config/`, `routes/`, `resources/js` ni `.env.example`, salvo la lista de variables vetadas de `PostgresTestGuard` y un comentario del seeder. La única llamada HTTP del chat es la de `HorusAdvisorClient`, limitada por la expresión regular a `http://127.0.0.1:<puerto>`.

## 4. Defectos

### R-01 · ALTO — A-01 sigue abierto: respuestas institucionales verificadas rechazadas

- **Archivos:**
  - `app/Support/FiltroPrivacidadChat.php:148-165`: `contieneDatosPrivadosEnRespuesta`, lista exacta de entidades más la heurística de mayúsculas de la línea 323.
  - `app/Services/HorusAdvisorClient.php:226` (`@`) y `:340` (contexto de petición).
- **Evidencia reproducible.** Motor Python en proceso, modos léxico e híbrido; las salidas reales se pasaron a `respuestaValida` en Laravel arrancado con `.env.example` y SQLite `:memory:`.

| Consulta natural | Registro devuelto | Causa del rechazo |
|---|---|---|
| «¿Tienen convenio con el Colegio de Economistas?» | `convenio_economia` | «Ciencia Económica», tomada por un nombre personal |
| «¿Trabajan con el Colegio de Licenciados en Administración?» / «¿Tienen convenio con CORLAD?» | `convenio_administracion` | «Ciencias Administrativas», tomada por un nombre personal |
| «¿Tienen redes sociales?» / «¿Están en Facebook?» | `redes_sociales` | `@horusgroupsrl` (regex `@`, línea 226, y `enlacesValidos`) |
| «Compré un producto y mi pedido no llega» | `faq_pedido` | «escribe con tu número de pedido», tomada por una petición de dato sensible |

- **Alcance.** Con su texto exacto y su fuente, 39 de los 43 registros del conocimiento se aceptan y 4 se rechazan. Las fechas de los dos convenios sí reciben la excepción local de Codex; el rechazo viene del detector de nombres.
- **Consecuencia.** Respuestas verificadas, servidas por Python, se sustituyen en silencio por la contingencia. Es el mismo defecto de A-01: la corrección añadió una lista exacta ajustada a los 32 casos de referencia y no resolvió la causa (la heurística de mayúsculas aplicada a texto institucional). Cualquier contenido nuevo con dos palabras capitalizadas volverá a fallar.
- **Corrección recomendada (no aplicada):**
  - Validar la salida por procedencia: si `answer` coincide exactamente con un registro verificado y su fuente, no aplicar la heurística de nombres a ese texto, conservando la revisión de enlaces, credenciales y datos bancarios.
  - O bien generar la lista de entidades a partir de `knowledge.json` en lugar de mantenerla a mano.
  - Añadir una prueba que recorra los 43 registros, no solo los 32 casos.

### R-02 · MEDIO — A-02 corregido solo para las frases de referencia

- **Archivo:** `app/Services/HorusAdvisorClient.php:217`, `:226`, `:290` y `:324-347`.
- **Evidencia.** Frases añadidas a una respuesta institucional con fuente válida. Se aceptan en `answer` y en `clarification_question`:
  - **Peticiones de datos:** «¿Me pasas tu DNI?», «Pásame tu número de celular», «Dime tu DNI para registrarte», «¿Cuál es tu DNI?», «¿Cuál es tu número de celular?», «¿Me das tu celular?», «Déjame tu número y te llamamos», «Responde con tu DNI y nombres completos», «Para inscribirte necesito el DNI del alumno», «Escribe aquí tus nombres y apellidos», «Sube una foto de tu DNI», «Facilítanos tu correo personal», «¿Me confirmas tu dirección?», «Indícame tu fecha de nacimiento».
  - **Precios:** «Son 150 nuevos soles», «Vale €150», «Son 150 euros», «La inversión es de 150», «Precio: 150», «Tiene un costo de 200 por persona», «Vale ciento cincuenta», «PEN 150».
  - **Fechas y horarios:** «Inicio: 15.03.2027», «Comienza en marzo próximo», «Comienza el lunes 15», «March 15, 2027», «Inicio 2027/03/15», «Atendemos de 8:00 a 18:00 de lunes a viernes».
  - **Disponibilidad:** «Solo hay 3 vacantes».
- **Falsos positivos de la misma regla:**
  - «El curso inicia el proceso de matrícula por WhatsApp», por la regla «inicia el».
  - «Contamos con convenio con la Universidad Nacional de Cajamarca».
  - «Atendemos en San Marcos y Baños del Inca».
- **Consecuencia.** La validación sigue siendo una enumeración de verbos, monedas y formatos. Cada variante no listada pasa, y cada palabra listada genera rechazos legítimos, que es exactamente lo que la auditoría pedía confirmar que no ocurriera. El riesgo práctico está acotado: Python responde desde conocimiento verificado, y el widget renderiza texto plano.
- **Corrección recomendada:** invertir la política para las afirmaciones comerciales. Rechazar cualquier cifra o fecha que no aparezca en el registro fuente citado, en lugar de enumerar formatos prohibidos. Para las peticiones de datos, ampliar los verbos a un léxico normalizado (pasar, dar, dejar, decir, confirmar, subir, facilitar, responder) y eliminar el requisito de «tu/su» cuando el dato es sensible.

### R-03 · MEDIO — A-03 corregido por lista exacta; variantes cercanas siguen bloqueadas

- **Archivos:**
  - `app/Support/FiltroPrivacidadChat.php:282` (lista exacta) y `:323` (heurística de mayúsculas).
  - `app/Services/HorusAdvisorClient.php:321`.
- **Evidencia.** Los cinco casos del informe pasan. Siguen bloqueadas:

| Consulta | Bloqueo |
|---|---|
| «¿Tienen Excel Avanzado?» | `nombre_personal`: «Tienen Excel Avanzado» son tres palabras capitalizadas |
| «Curso de Power Point» | `nombre_personal` |
| «¿Tienen curso de Google Sheets?» | `nombre_personal` |
| «Curso de Base de Datos» | `nombre_personal` |
| «¿Hacen cableado en Baños del Inca?» | `nombre_personal` |
| «¿Dan clases en la Universidad Nacional de Cajamarca?» | `nombre_personal` |
| «¿Necesito DNI para matricularme?» | `documento` (el visitante recibe el aviso de privacidad) |
| «¿Qué instrucciones debo seguir para matricularme?» | `seguridad` |
| «¿Enseñan prompt engineering?» | `seguridad` |

- **Datos privados reales.** Se siguen bloqueando antes de cualquier HTTP: 3 entradas privadas por el servicio completo, con `Http::preventStrayRequests`, generan 0 peticiones. Solo pasa «soy anderson vela» (minúsculas, nombre fuera de la lista), una limitación ya documentada en el propio filtro.
- **Consecuencia.** Consultas legítimas frecuentes (requisitos de matrícula, nombres de productos, distritos de Cajamarca) reciben la contingencia o el aviso de privacidad.
- **Corrección recomendada:**
  - Ampliar `TERMINOS_INSTITUCIONALES` con verbos interrogativos capitalizados al inicio de frase y con productos y topónimos derivados del contenido público.
  - Permitir la mención de «DNI/RUC» sin número cuando no hay dígitos.
  - Acotar `instrucciones`, `prompt` y `system` a patrones de ataque («ignora las instrucciones», «system prompt») en lugar de palabras sueltas.

### R-04 · BAJO — Evidencia de integración del arnés más débil de lo que se presenta

- **Archivo:** `tests/Support/horus-observaciones-bridge.php:75-79`.
- **Evidencia.** Para las 7 «consultas adicionales de datos/precios/fechas», el arnés solo comprueba `fuente ∈ {advisor, respaldo}` y que no aparezca `12345678`. La condición se cumple siempre y no demuestra ningún bloqueo.
- **Otras limitaciones:**
  - El smoke de Edge envía solo un saludo (lo reconoce el §11 de Codex).
  - Las 32 consultas de referencia no incluyen los convenios de Economistas y CORLAD, las redes sociales ni los pedidos, por eso no detectaron R-01.
- **Corrección recomendada:** aserciones explícitas por caso y un recorrido de todos los registros del conocimiento.

### R-05 · INFORMATIVO

- **Columna `fuente` sin migrar (A-05).** Sin 000034, cada mensaje produce un INSERT fallido y un reintento, es decir, una línea `ERROR` en el log de PostgreSQL por mensaje. Está declarado por Codex. Con la columna presente solo hay la inserción normal.
- **Doble llamada a la privacidad.** `AsistenteVirtualService.php:57` vuelve a ejecutar `contieneDatosPersonales` para obtener el motivo; la comprobación de privacidad interna de `orientar()` es inalcanzable desde el servicio.
- **Lectura de `knowledge.json` por respuesta.** `textoParaValidacion` (`HorusAdvisorClient.php:360-364`) lee y decodifica ese archivo de Python en cada respuesta y acopla Laravel a su ruta.
- **Código PHP histórico.** `buscarIntent()` e `intentsDisponibles()` siguen sin consumidores.

## 5. Verificación de A-04 a A-07

- **A-04.** La prueba sustituye temporalmente el repositorio `Env` por un `ArrayAdapter` y lo restaura en `finally`. Pasa con la variable ausente, con `false` y con `true` en el proceso.
- **A-05.**
  - Ya no hay `Schema::hasColumn`. La prueba con la columna presente → ausente → presente confirma 0 consultas de esquema y 1 INSERT con éxito por mensaje.
  - El reintento solo se produce ante SQLSTATE `42703` con `"fuente"` (PostgreSQL) o `HY000` con «has no column named fuente» (SQLite). Cualquier otro error se relanza.
  - La transacción y el savepoint evitan dejar abortada una transacción de PostgreSQL.
- **A-06.**
  - `chatbot.contingencia` solo lleva `motivo ∈ {privacidad, seguridad, deshabilitado, no_disponible, respuesta_rechazada}`.
  - `mensaje_usuario` sigue siendo el marcador de privacidad.
  - El contrato público sigue siendo exactamente `{respuesta, intent, fuente}`, comprobado en la prueba.
- **A-07.**
  - Se retiraron `INTENTS_PERMITIDOS`, `consultar()` y `ChatCursosPublicos`, sin consumidores restantes (comprobado con `grep`).
  - `.env.example` declara `HORUS_ADVISOR_*` con `ENABLED=false`.
  - No hay configuración ni cliente de OpenAI.

## 6. Pruebas repetidas por el auditor

Distinción por tipo de prueba:

- **Simulada (`Http::fake`):** `HorusObservacionesTest`, `HorusAiTransicionTest` y la suite PHP.
- **Automatizada aislada:**
  - Motor Python real en proceso (léxico e híbrido) más el validador real de Laravel arrancado con `.env.example` y SQLite `:memory:`.
  - pytest, `npm test`.
- **Integración real HTTP o Edge:** no repetida (prohibido iniciar servicios).

| Comprobación | Resultado |
|---|---|
| PHP completa (`php artisan test --compact`, sin la variable en el entorno) | 2127 aprobadas, 1 omitida (requiere PostgreSQL), 13555 aserciones, exit 0. Coincide con Codex. |
| Observaciones + Transición, con la variable ausente / `false` / `true` | 115/115, 202 aserciones, en los tres casos |
| Python (todas las marcas) | 165 aprobadas |
| Ruff check y format | OK, 39 archivos; arnés OK |
| `npm test` | 46/46 |
| Build (salida a la carpeta temporal) | OK; `public/build` intacto; sin `public/hot` |
| Larastan | 0 errores |
| Pint (7 PHP propios) | passed |

## 7. Migración 000034 y PostgreSQL

- **Migración.** Sin cambios: `string('fuente', 16)->nullable()` y `dropColumn` en `down`. En PostgreSQL es un ADD COLUMN anulable sin valor por defecto, es decir, solo de metadatos y sin reescribir la tabla.
- **Aplicación.** Debe aplicarse con `--path` mientras 000022 siga pendiente. No se ejecutó ni se autoriza aquí.
- **Conexiones.** No hubo ninguna a PostgreSQL. Escuchan 5432 y 55432 (preexistentes; 55432 es el clúster S7 suspendido); 55433 no está activo.
- **Puertos de servicios.** 8001, 8765 y 19322 están cerrados.

## 8. Salvaguardas de esta auditoría

- **Estado de Git.** `git status --porcelain -uall`, el índice, HEAD y los stashes son idénticos antes y después.
- **Cambios en el repositorio.** Ninguno: sin archivos modificados, migraciones, seeders, servicios, commits ni push. `.env` no se abrió ni se modificó.
- **Efectos laterales redirigidos:**
  - Vistas compiladas, build y sondas en la carpeta temporal; `LOG_CHANNEL=null`; `--do-not-cache-result`.
  - Python sin bytecode ni caché de pytest y con el modelo sin conexión.
  - Las sondas PHP cargaron `.env.example`, no `.env`.
- **Sondas reproducibles:** `scratchpad\probe2\` (`gen2.py`, `gen3.py`, `val2.php`, `val3.php`, `why.php`, `hashes.py`) y sus salidas (`py-lexical.json`, `py-hybrid.json`, `py-extra3.json`, `val2.txt`, `why.txt`).

**No se inició la Fase 5 ni se activó Horus AI. La auditoría queda a la espera de instrucciones.**

## 9. Estado posterior: corrección de R-01 a R-05 (03/11/2026)

Corrección autorizada por el propietario después de esta auditoría. Detalle técnico en `docs/sprint-16-horus-ai-advisor.md` («Corrección R-01 a R-05»). Copia previa de los archivos editados, con hashes: `%LOCALAPPDATA%\HorusBackups\correccion-r01-r05-20261103-105336`.

| Defecto | Estado | Evidencia |
|---|---|---|
| R-01 | Corregido | Los 34 registros verificados se aceptan como respuesta propia; las 198 respuestas reales de Python (léxico e híbrido; todas las preguntas de ejemplo del conocimiento, más Economistas, CORLAD, redes sociales, pedidos y cupos) se aceptan; un texto verificado con cualquier añadido, o citado con otra fuente, vuelve a la validación completa y se rechaza si contiene contenido prohibido. |
| R-02 | Corregido | Las 31 variantes se rechazan en `answer` y en `clarification_question`; las 7 frases legítimas se aceptan. Cifras, numerales y fechas se contrastan con la evidencia de las fuentes citadas, en lugar de enumerar formatos. |
| R-03 | Corregido | Las 13 consultas legítimas llegan a Python; 15 entradas privadas o de ataque se bloquean con 0 peticiones HTTP. Límite conocido: un nombre poco frecuente escrito en minúsculas sin contexto («soy anderson vela») sigue sin detectarse. |
| R-04 | Corregido | El script de integración comprueba explícitamente las consultas prohibidas y recorre el fixture nuevo. No se ejecutó en esta corrección, porque requiere iniciar FastAPI. |
| R-05 | Parcial, por diseño | La privacidad se evalúa una vez y el conocimiento se cachea por proceso. Mientras 000034 no se aplique, el intento fallido en el log de PostgreSQL es inherente a no consultar el esquema. `buscarIntent()` se conserva porque lo usa `BackendPerformanceTest`. |

Validación tras la corrección:

- **PHP completa:** 2491 aprobadas, 1 omitida (requiere PostgreSQL), 14117 aserciones.
- **Suites del chat:** 575/575.
- **Larastan:** 0 errores.
- **Pint (archivos propios):** passed.
- **`git diff --check`:** limpio.
- **Python y frontend:** sin cambios en esta corrección.

HEAD, stashes e índice no cambiaron, y no se ejecutaron migraciones, servicios ni commits. Horus AI sigue desactivado por defecto. Su activación para usuarios reales y la Fase 5 siguen pendientes de autorización expresa.
