# Horus AI · Estabilización de la barrera de privacidad (3 de noviembre de 2026)

Estado: **implementado y probado localmente; NO aprobado.** La aceptación exige un dictamen independiente favorable (ver §7).
`HORUS_ADVISOR_ENABLED` permanece en `false`; ningún servicio se inició en esta intervención.

## 1. Qué cambió y por qué

La auditoría independiente midió sobre una reserva sellada: cobertura 71 %, especificidad 76,7 %, precisión 83,5 %. La causa raíz no eran
casos aislados sino la **arquitectura de la detección de nombres**: anclas («soy», «me llamo»…), listas de nombres/apellidos y reglas de
forma («dos palabras en mayúscula inicial»). Un nombre nunca visto sin ancla pasaba; una institución, producto o distrito en mayúsculas se
bloqueaba.

La detección de nombres de la **entrada** ahora añade una capa por **complemento del léxico** (`App\Support\FiltroPrivacidadChat`):

| Capa | Idea | Depende de listas de nombres |
| --- | --- | --- |
| Anclas explícitas | «me llamo», «mi nombre es», «a nombre de»… | no |
| Nombres frecuentes adyacentes | lista corta de nombres/apellidos (se conserva) | sí |
| Contexto sin mayúsculas | presentación, matrícula/inscripción de terceros, relación, atención | no |
| **Complemento del léxico** | ≥ 2 palabras seguidas que el vocabulario público **no explica** forman una secuencia con forma de nombre; sin ancla, sin importar mayúsculas ni escritura (latina, CJK, cirílica, árabe) | **no** |

Reglas del complemento (todas locales, sin red, sin modelo): secuencias de palabras no explicadas (admite partículas de/del/la/y);
apellido capitalizado pegado a un nombre; apellido corriente en mayúscula entre palabras no explicadas («Bruno Alcalde Pinedo»); ancla
fuerte + palabra no explicada; nombre aislado de 1–3 palabras; letras separadas («Y A N E T H»); nombres unidos en CamelCase
(`CarlosMendoza`) frente a productos (`MateBook`).

Exenciones **acotadas**: el nombre de una entidad, lugar o producto introducido por su sustantivo («Colegio Pedro Ruiz Gallo», «Río
Chonta») se excluye solo en esa secuencia; lo que quede fuera se examina completo. Numerales romanos, siglas aisladas de 3–5 letras,
siglas de ≤ 2 letras, abreviaturas con punto.

Otros cambios del filtro: frases de rechazo («sin dar mis datos») no cuentan como entrega; preguntas sin cifras sobre trámites propios
(«¿Piden mi DNI?», «¿mi pago?») tampoco; edad con cifra, claves/contraseñas con calificativo y frases de recuperación, sí; los atributos de
una etiqueta HTML ya no se toman por claves.

La **barrera de salida** (`contieneDatosPrivadosEnRespuesta`) conserva la regla anterior de mayúsculas: no cambia.

**Actualización del cierre funcional (05/11/2026; sigue NO aprobado):**

- Barrera de salida: una secuencia de palabras con mayúscula inicial solo cuenta como nombre si contiene un nombre o apellido listado
  o una palabra que el léxico público no explica. Antes cualquier secuencia capitalizada que no figurara en dos listas pequeñas se
  trataba como nombre: el resumen editorial «Aprende Excel con tablas dinámicas…» anulaba en silencio la tarjeta del curso.
  «Isabel Montes», «Rosa Campos», «Bruno Alcalde Pinedo» o «Docente: Rosario Quispilloclla» siguen bloqueándose.
- Entrada: un fragmento técnico sin espacios (URI, `${…}`, `#{…}`, `{{…}}`) no se trata como «nombre aislado» (regla d/d'); las
  reglas de secuencia siguen examinando el mensaje completo y lo que queda fuera del fragmento se examina como siempre, así que
  anteponer `${x}` no exime un nombre y `…/in/carlos-mendoza-ruiz` sigue bloqueado.
- Léxico: nueva categoría de ocupaciones que la morfología de oficio no reconoce (auxiliar, fiscal, concejal, aprendiz…), sin
  apellidos homónimos. Regenerado con `scripts/privacidad-generar-lexico.php` (+37 palabras, ninguna retirada).
- Seguridad: Laravel (`HorusAdvisorClient::esBloqueadoPorSeguridad`) y Python (`detect_prompt_injection`) reconocen sondas de
  inyección de código (plantillas, JNDI, HTML activo, SQL); Laravel las detiene sin llamar a Python (motivo `seguridad`).
- Regresiones: `tests/Unit/FiltroPrivacidadCierreFuncionalTest.php` y `tests/Feature/HorusCierreFuncionalTest.php`, redactadas por
  el implementador; **no son una validación independiente**. Queda una discrepancia de política, no de código: «Mi hijo de 12 años…»
  se bloquea porque la edad con cifra vinculada a una persona cuenta como dato personal (§1), aunque un holdout interno la etiquetó
  como legítima. Cambiarla requiere decisión del propietario.

## 2. Léxico público

- `app/Support/Privacidad/LexicoPublico.php` + `lexico-publico.php` (≈ 36 700 palabras, **generado**).
- Fuentes: `lexico-base.txt`, `lexico-general.txt`, `lexico-verbos.txt` (curadas por categorías semánticas, sin nombres de pila ni apellidos
  ni homónimos), texto en minúscula de las vistas públicas (sin `admin/`), `knowledge.json`, `intents.json`, `conversations.json` y las
  consultas del split `dev` de `evaluation.json`.
- Regenerar: `php scripts/privacidad-generar-lexico.php`. No editar `lexico-publico.php` a mano.
- Se carga una vez por proceso como arreglo PHP (OPcache lo comparte). Sin base de datos, sin red.
- Las formas verbales y funcionales no admiten el cambio de género (`daría` no vuelve «conocido» a `Darío`).

## 3. Límites que se conocen y se declaran

1. Un nombre de pila suelto y desconocido en minúsculas dentro de una frase («hola kirana») no se detecta.
2. Un nombre cuyas palabras son vocabulario común («isabel montes») puede pasar; con mayúsculas y una palabra no explicada, se bloquea.
3. Una entidad con nombre de persona queda exenta tras su sustantivo («empresa Juan Pérez»); la parte fuera de la secuencia se sigue examinando.
4. Dos palabras legítimas poco frecuentes seguidas (productos o términos técnicos no incluidos en el léxico) se bloquean por prudencia: el
   visitante recibe la aclaración y puede reformular.
5. El léxico es finito: la especificidad real depende de su cobertura. Ampliarlo es regenerar, no tocar código.
6. No se afirma riesgo cero.

## 4. Política de bloqueo y contrato

Bloqueo completo: Laravel detecta → no se llama a `HorusAdvisorClient` → ningún fragmento ni la aclaración viajan a Python → `ChatLog`
guarda `[contenido omitido por privacidad]` → el log solo registra `chatbot.contingencia {motivo: privacidad}` → la respuesta es
`AsistenteVirtualService::RESPUESTA_PRIVACIDAD`, que nunca repite el dato:

> Parece que incluiste un nombre o dato personal. Por seguridad, evita compartir información personal. Si deseas consultar sobre un curso o
> servicio, puedes reformular tu pregunta sin esos datos.

El formato de respuesta (`respuesta`, `intent`, `fuente = respaldo`) no cambia. No se envía `session_id` ni historial a Python.

## 5. Pruebas y corpus

| Archivo | Uso |
| --- | --- |
| `tests/Fixtures/privacidad/desarrollo.txt` | 384 mensajes nuevos de **desarrollo** (se usó para ajustar) |
| `tests/Fixtures/privacidad/errores-compartibles.txt` | los 38 errores compartibles de la auditoría (regresión) |
| `tests/Fixtures/privacidad/holdout-interno*.txt` | tres verificaciones internas escritas después de congelar versiones del filtro; **no son independientes** |
| `tests/Unit/FiltroPrivacidadCorpusTest.php` | cada caso con su etiqueta; umbrales sobre el corpus de desarrollo |
| `tests/Unit/LexicoPublicoTest.php` | el léxico explica vocabulario y no explica nombres de control |
| `tests/Feature/HorusPrivacidadBloqueoCompletoTest.php` | 0 HTTP, 0 contenido en `ChatLog`/logs, respuesta sin eco |

La reserva sellada de 160 mensajes de la auditoría anterior **no se leyó ni se usó**. El corpus institucional de 403 mensajes no pudo
ubicarse ni verificarse en el repositorio, por lo que no se usó (el repositorio contiene `evaluation.json` con 195 consultas; solo se
tomó vocabulario de su split `dev`).

Evaluador reproducible: `php scripts/privacidad-evaluar.php <corpus.txt>... [--errores] [--json]`.

## 6. Resultados (resumen; detalle en el informe externo)

El conjunto de desarrollo y los dos primeros holdouts quedaron **contaminados** al diagnosticarse. La cifra honesta de generalización es la
**primera pasada** sobre el holdout 3, medido una sola vez: cobertura con contexto 100 % (53/53), nombres sin contexto 93,3 % (14/15),
especificidad 84,8 %, precisión 87,0 %. **La especificidad y la precisión no alcanzan el 95 % exigido.** Tras las mitigaciones posteriores
las cifras sobre ese mismo conjunto mejoran, pero ya no son evidencia.

## 7. Auditoría independiente

Pasos para el auditor (Codex u otro distinto del implementador):

1. Congelar y verificar los SHA-256 del informe externo.
2. Preparar una reserva **nueva**, etiquetada y sellada antes de evaluar; no reutilizar la anterior ni compartirla con el implementador.
3. Evaluar con `scripts/privacidad-evaluar.php` o el flujo de la reserva; medir por separado nombres con contexto, sin contexto, legítimas y
   seguridad; reportar matriz, intervalos y errores sin reetiquetar.
4. Ejecutar la integración real (`tests/Support/run-privacidad-integracion.py`, opt-in `HORUS_PRIVACIDAD_INTEGRACION=1`) con autorización
   expresa: loopback, token efímero, SQLite en memoria, puerto 8001 libre. Criterio: 0 peticiones a FastAPI para los bloqueados.
5. No ajustar el código con la reserva; si se ajusta, queda contaminada.
