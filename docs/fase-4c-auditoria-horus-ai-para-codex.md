# Horus Group — Fase 4C: informe de contexto para Codex

**Origen:** auditoría independiente de solo lectura realizada por Claude el 03/11/2026 sobre el repositorio
`C:\Users\PCT40T\Documents\Sistema\Horus System` (rama `anderson`, HEAD `7914438`).
**Destinatario:** Codex, antes de cualquier trabajo posterior sobre Horus AI (Fase 4B/5).
**Estado del documento:** contexto y hallazgos. **No es una autorización para modificar código.** Toda corrección requiere instrucción expresa del propietario.

---

## 0. Cómo usar este informe

1. Lee primero `AGENTS.md`, `CLAUDE.md` y `PROJECT_CONTEXT.md` del repositorio. Sus restricciones prevalecen sobre este informe.
2. Trata las líneas citadas como referencias del 03/11/2026: **vuelve a verificarlas** con `git diff` y lectura del código antes de editar; el árbol de trabajo tiene trabajo de varios colaboradores.
3. Distingue siempre tres niveles de evidencia (este informe los marca):
   - **[CÓDIGO]** lectura directa de archivos o `git diff`.
   - **[REPRODUCIDO]** evaluación pura con `php -r` / `node -e` (sin Laravel, sin BD, sin red, sin escribir archivos).
   - **[NO VERIFICADO]** afirmaciones de informes previos (incluidos los de Codex) que no se repitieron.
4. En la auditoría **no se ejecutó ninguna suite de pruebas**, ni Uvicorn, ni servidores PHP, ni Edge.

---

## 1. Restricciones vigentes (no negociables sin el propietario)

- No `git reset`, `restore`, `checkout` para restaurar, `clean`, `stash pop/apply`, `add`, `commit`, `push`, `merge`, `rebase`.
- Conservar los **24 commits locales** por delante de `origin/anderson`, los **2 stashes** y todo el árbol de trabajo (49 modificados, 26 sin seguimiento).
- **Nunca** usar `origin/anderson` como fuente de restauración.
- No migraciones, seeders, SQL sobre PostgreSQL, instalaciones ni cambios en `.env`. No abrir ni imprimir `.env` reales.
- No habilitar `chatbot.public_catalog_enabled` ni la sincronización de catálogo Laravel → Python.
- No reconstruir el robot visual ni crear un segundo chatbot.
- El chatbot no pide DNI, teléfonos, comprobantes ni datos personales; matrícula y cotización se derivan a WhatsApp `https://wa.me/51927582305` (`+51 927 582 305`).

---

## 2. Estado de Git verificado [CÓDIGO]

| Dato | Valor |
|---|---|
| Rama / HEAD | `anderson` / `7914438` |
| Adelanto respecto a `origin/anderson` | 24 commits, 0 por detrás |
| Stashes | `stash@{0}` «before removing oversized Laravel asset copies»; `stash@{1}` «before removing oversized image blobs» |
| Índice | vacío |
| Cambios de la Fase 4B | **ninguno confirmado en commit**; mezclados en el árbol de trabajo con trabajo de fases 2/3 y de otros módulos (reclamaciones, servicios) |

**Archivos sin seguimiento críticos para el chat:**
- `app/Support/FiltroPrivacidadChat.php` (lo importa `AsistenteVirtualService.php:11`; si se pierde, `/chat/mensaje` falla con 500)
- `tests/Unit/FiltroPrivacidadChatTest.php`
- `chatbot-python/tests/test_phase3b_capacitaciones_whatsapp.py`

El diff de `routes/web.php` **no toca el chat** (pertenece a reclamaciones/servicios). Rutas del chat sin cambios: `routes/web.php:159-160` (admin) y `:163-165` (público, `throttle:30,1`).

---

## 3. Conclusión principal

| Afirmación del informe 4B | Resultado |
|---|---|
| Se eliminó `consultarOpenAI()` y la lógica OpenAI del chat | **Correcto** [CÓDIGO] |
| Python es el motor conversacional principal | **Falso en el repositorio** [CÓDIGO] — ver H-01 |
| Se retiró el motor PHP | **Falso**: se duplicó dentro del servicio y sigue respondiendo todo el tráfico [CÓDIGO] |
| Endpoint `http://127.0.0.1:8001/chat` | **Error**: el real es `POST /api/v1/chat` [CÓDIGO] |
| Modelo `MiniLM-L6-v2` | **Error**: el configurado e instalado es `paraphrase-multilingual-MiniLM-L12-v2` [CÓDIGO] |
| 117 / 9 / 42 / 154 / 11 pruebas aprobadas | [NO VERIFICADO] |
| Pruebas reales con Uvicorn a través de Laravel | **No reproducibles desde el repositorio** sin editar código (H-01) |

**Veredicto de la auditoría:** bloquear el cierre de la Fase 4B tal como fue declarada. Podría reclasificarse como «aprobada con observaciones» si el propietario la redefine como «retirada de OpenAI del chat; Advisor preparado y apagado».

---

## 4. Arquitectura real

### Flujo efectivo hoy (Advisor apagado)
Robot → `POST /chat/mensaje` → `ChatWidgetController::mensajePublico` (valida `mensaje` ≤2000, `session_id` ≤64; ámbito `public:<session>`) → `AsistenteVirtualService::procesarMensaje`:
1. Candado de sesión no bloqueante 30 s (`:53-58`), liberado en `finally` (`:85-87`).
2. Caché de deduplicación (`:52`, `:60-66`) — clave basada en texto **normalizado**.
3. `FiltroPrivacidadChat::contieneDatosPersonales` (`:67`).
4. `resolver()` → **motor PHP local**: `ChatKnowledge::intents()` (24 intenciones) + `buscarIntentLocal` (`:153-194`, `:200-249`).
5. `ChatLog::create` con `mensaje_usuario = '[contenido omitido por privacidad]'` (`:76-81`).

### Flujo con Advisor habilitado (solo alcanzable editando código)
Igual hasta el paso 3; luego `:144-151` llama a `HorusAdvisorClient::orientar()`. Si devuelve `null`, responde `RESPUESTA_FALLBACK` (`:18`) — **no** recurre a la FAQ local.

Condiciones (`AsistenteVirtualService.php:47-48`): `config('horus-advisor.enabled')` **y** ámbito `public:` **y** todas las intenciones incorporadas activas en BD.

### Dependencias
- **Activas:** `AsistenteVirtualService`, `FiltroPrivacidadChat`, `ChatKnowledge`, `ChatCursosPublicos` (en `app/Support/`, no en `app/Services/`) → `ChatIntentMatcher::normalizar`, `CatalogoPublicoCursos`, modelos `ChatIntent`/`ChatLog`, `HorusAdvisorClient`.
- **Duplicadas:** `AsistenteVirtualService::normalizarTexto` / `buscarIntentLocal` / `esBloqueado` (`:90-101`, `:200-254`) copian `ChatIntentMatcher::normalizar` / `buscar` / `bloqueado`. Ya divergen (`/u` en `:253`, ausente en `ChatIntentMatcher.php:78`). Lista de 7 temas repetida literal en `:174` y `:178`.
- **Muertas en producción:** `HorusAdvisorClient::INTENTS_PERMITIDOS` (`:15-23`), `HorusAdvisorClient::consultar()` (`:87-90`), parámetro heredado `orientar($mensajeOIntent, ?$mensaje)`, `validarCatalogo()` y `ExportadorCatalogoAdvisor` (solo pruebas), `ChatIntentMatcher::buscar`/`bloqueado`, comprobaciones `$this->cursos !== null` (`:153`, `:173`), `semantic_model_name` en Python (`config.py:53`, nunca leído).

---

## 5. OpenAI [CÓDIGO]

- Chat público y panel: **no pueden** llamar a OpenAI. En `app/` solo existen dos `Http::`: Resend (`MatriculaNotificationService.php:182`) y Advisor (`HorusAdvisorClient.php:139`).
- Python no importa `httpx`, `requests`, `urllib`, `aiohttp` ni `openai`.
- Configuración heredada **sin consumidores**: `config/chatbot.php:9-11` (`openai_per_minute`, `openai_per_session`, `max_context_chars`), `config/services.php:25-26`, `.env.example:78` (`OPENAI_API_KEY`).
- Documentación desfasada: `CLAUDE.md:106` aún describe OpenAI como opcional en el chat.
- Las pruebas siguen fijando `services.openai.*` para afirmar que **no** se llama a OpenAI; es inocuo.

---

## 6. Modelo semántico [CÓDIGO]

- `chatbot-python/app/core/config.py:53-54` y `chatbot-python/.env.example`: `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`, directorio `models/paraphrase-multilingual-MiniLM-L12-v2`.
- Instalado: único directorio en `models/`; `config.json` = `BertModel`, 12 capas, `hidden_size` 384, vocabulario 250 037; pooling media; `max_seq_length` 128 → coincide con L12.
- Carga: `semantic_search.py:58` por **directorio** con `local_files_only=True`; `main.py:114-120` solo si `HORUS_AI_SEMANTIC_ENABLED`.
- **Pendiente:** acreditar el modelo cargado en ejecución (requiere arrancar el servicio o revisar `HORUS_AI_SEMANTIC_MODEL_DIR` en el `.env` real, que no se abrió).

---

## 7. Contrato Laravel ↔ FastAPI [CÓDIGO]

| Aspecto | Detalle |
|---|---|
| Comprobación previa | `GET /ready` en cada mensaje (`HorusAdvisorClient.php:66`); exige `status=ready`, `knowledge=loaded`, `auth=configured`, `catalog=none`, `semantic` ∈ {loaded, loading, disabled, unavailable} |
| Chat | `POST /api/v1/chat` (`routes.py:34` prefijo + `:119`; cliente `:73`) con `{message, history: []}`, sin `session_id` |
| Autenticación | Cabecera `X-Horus-Service-Token`, `hmac.compare_digest` (`security.py:27-41`); sin token configurado → 503 |
| Esquema de petición | Pydantic `extra="forbid"` (`chat.py:19-20`), `message` ≤ `max_message_chars` (2000) |
| Validación de respuesta en Laravel | `respuestaValida()` (`:169-260`): `answer` ≤1800, `intent` `[a-z_]{1,64}`, `mode`, `match_level`, booleanos, `sources` ≤10 con `url_path` en lista blanca (`:25-39`), `recommendations` ≤5 con evidencias cruzadas |
| Fallos | 401/422/503/3xx/timeout/JSON inválido → `null` → fallback; excepciones nunca registradas (`:155-165`) |
| Transporte | Solo `http://127.0.0.1:<puerto>` literal (`:128`); token ≥32 sin CR/LF; sin redirecciones; `proxy: ''`; sin cookies; `Accept-Encoding: identity`; ≤64 KiB por cabecera y progreso; presupuesto compartido 2,5 s (`:133-154`) |
| Python | `TrustedHostMiddleware` loopback (`main.py:157`); cuerpo ≤32 KiB (`:158-162`); 422 sin reflejar la entrada (`:164-174`); host loopback obligatorio (`config.py:86-90`) |
| Tiempos | Python 5 s vs Laravel 2,5 s (Python sigue trabajando tras el abandono de Laravel; informativo) |

---

## 8. Robot visual [CÓDIGO + REPRODUCIDO; sin prueba en Edge]

- Único: `resources/views/layouts/app.blade.php:14` lo incluye una vez; el panel usa la variante no pública de `components/chat/conversation.blade.php`.
- Endpoint `route('chat.mensaje')` (`chat-widget.blade.php:55`); `fetch` con `X-CSRF-TOKEN` (`chat-client.js:99-111`). El navegador no conoce URL ni token de FastAPI.
- Texto con `x-text` (`conversation.blade.php:71`); no hay `x-html`, `innerHTML` ni `{!! !!}`.
- Enlaces solo desde constantes (`chat-client.js:6-22`, `:39-48`). [REPRODUCIDO] `wa.me/51927582305?text=Envia%20tu%20DNI`, `wa.me/51927582305.evil.example`, `www.`, `//`, `javascript:` → como máximo el botón canónico `https://wa.me/51927582305`.
- Si la respuesta contiene ambos enlaces de WhatsApp, solo se genera uno (`break` en `:46`).
- Los 4 botones sugeridos (`conversation.blade.php:90-93`) solo rellenan el borrador; ninguno es bloqueado por el filtro [REPRODUCIDO].

---

## 9. Hallazgos

Formato: severidad · archivo:líneas · evidencia · impacto · corrección propuesta · prueba necesaria.
Las correcciones marcadas **[DECISIÓN]** requieren que el propietario elija antes de implementar.

### H-01 · ALTO · Python no es el motor efectivo y no puede activarse por configuración
- **Dónde:** `config/horus-advisor.php:4` (`'enabled' => false` literal, sin `env()`); `AsistenteVirtualService.php:47-48`.
- **Evidencia [CÓDIGO]:** no existe `bootstrap/cache/config.php`; el archivo no cambia desde el commit `9e84d7b`; `.env.example` no contiene ninguna variable `HORUS_ADVISOR_*`.
- **Impacto:** todo el tráfico público lo resuelve el motor PHP local. La afirmación central de la Fase 4B no se sostiene y las pruebas reales con Uvicorn no son reproducibles desde el repositorio.
- **Corrección [DECISIÓN]:** (a) `'enabled' => (bool) env('HORUS_ADVISOR_ENABLED', false)` y documentar `HORUS_ADVISOR_ENABLED`, `HORUS_ADVISOR_URL`, `HORUS_ADVISOR_TOKEN`, `HORUS_ADVISOR_SIGNING_KEY` en `.env.example` sin valores; o (b) mantener el flag en código y redefinir la Fase 4B como «retirada de OpenAI». El proyecto usa el mismo patrón literal para `chatbot.public_catalog_enabled`, así que puede ser una decisión deliberada.
- **Prueba:** el valor por defecto sigue siendo `false` (adaptar `HorusAdvisorTest::test_default_is_disabled_and_php_reply_matches_with_flag_off`, que hoy hace `require` del archivo de configuración); prueba de humo opcional con FastAPI en loopback.

### H-03 · MEDIO · Falsos positivos del filtro de privacidad sobre el motor activo
- **Dónde:** `AsistenteVirtualService.php:67`; `FiltroPrivacidadChat.php` (direcciones `:205`, bancario `:178`, nombres `:300`, ambiguos `:323` y `:337`).
- **Evidencia [REPRODUCIDO]:**

| Consulta legítima | Motivo de bloqueo |
|---|---|
| ¿Cómo hago mi matrícula? | `ambiguo` |
| Quiero información de mi inscripción | `ambiguo` |
| ¿En qué calle están? | `direccion` |
| ¿Atienden en la Av. principal? | `direccion` |
| ¿Aceptan pago con BCP? | `bancario` |
| Buenos Días | `nombre_personal` |
| ¿Tienen cursos de Microsoft Excel? | `nombre_personal` |
| Hola, soy Juan | `nombre_personal` |
| ¿Hay cursos en 2026? | `ambiguo` |
| Necesito mantenimiento de 1500 equipos | `ambiguo` |
| ¿Dan certificado con token de verificación? | `credencial` |

  Permitidas: los 4 botones sugeridos, «Buenas tardes», «¿Tienen el Curso de AutoCAD?», «Necesito cámaras para mi tienda», «Quiero cotizar cableado para 20 computadoras».
  En HEAD el control local era solo `/@|\d[\d\s-]{6,}\d/u`.
- **Impacto:** fallo seguro (no expone datos), pero las intenciones `consulta_matricula`, `consulta_ubicacion` y `consulta_pagos` quedan parcialmente inalcanzables y se degrada la experiencia, también en el probador del panel.
- **Corrección [DECISIÓN]:** afinar reglas (p. ej., «mi matrícula/inscripción» sin identificadores, nombres de bancos sin número de cuenta, saludos capitalizados, años aislados, «calle/Av.» sin número) o aplicar el filtro estricto solo antes de enviar a Python (el cliente ya tiene su propia barrera en `HorusAdvisorClient.php:61`) y uno más ligero para la FAQ local. No relajar la detección de datos reales.
- **Prueba:** nuevo proveedor de consultas legítimas que no deben bloquearse en `tests/Unit/FiltroPrivacidadChatTest.php`, manteniendo íntegro el proveedor `mensajesBloqueadosPorPrivacidad` de `HorusAdvisorTest.php:267-286`.

### H-04 · MEDIO · El validador de respuestas del Advisor se puede eludir
- **Dónde:** `HorusAdvisorClient.php:194-205` (`str_ireplace(self::CANALES_OFICIALES_PUBLICOS, '', ...)` y regex sobre el residuo).
- **Evidencia [REPRODUCIDO]** (respuesta afirmativa con fuente válida):

| Respuesta | Resultado |
|---|---|
| `https://wa.me/51927582305` | aceptada (correcto) |
| `https://wa.me/51999999999` | rechazada (correcto) |
| `https://wa.me/51927582305?text=Envia%20tu%20DNI%20y%20voucher` | **aceptada** |
| `https://wa.me/51927582305/../evil` | **aceptada** |
| `https://wa.me/51927582305.evil.example` | **aceptada** |
| `Visita evil.example/pago` | **aceptada** |
| `Visita www.evil.example` | **aceptada** |
| `Visita //evil.example/x` | **aceptada** |
| `Pulsa javascript:alert(1)` | **aceptada** |
| `El curso cuesta ciento cincuenta soles` | **aceptada** |
| `Inicia el 15 de diciembre de 2026` | **aceptada** |
| `Para avanzar envíame tu DNI y tu número de celular por aquí` | **aceptada** |
| `S/ 150`, `150 soles`, `15/12/2026`, `<b>` | rechazadas (correcto) |

- **Mitigación actual:** el navegador muestra esos textos con `x-text` (no clicables) y solo crea botones con destinos constantes; el conocimiento actual de Python solo emite las dos URL canónicas (`chat_engine.py`, `knowledge.json`).
- **Impacto:** hueco de defensa en profundidad; explotable solo si se altera el conocimiento o el servicio Python. Contradice la decisión 2.5 (no pedir datos personales) si ocurriera.
- **Corrección:** extraer cada token con forma de URL o dominio (`https?://`, `//`, `www.`, `[a-z0-9-]+\.[a-z]{2,}`, esquemas `javascript:`/`data:`/`tel:`) y exigir **igualdad exacta** con un canal canónico (tolerando solo puntuación final); rechazar peticiones de datos personales (`envía|envíame|indica|dame` + `DNI|celular|teléfono|comprobante|voucher|datos`) y precios/fechas en letras si el propietario lo considera necesario. Aplicar igual a `clarification_question` (`:194-197`).
- **Prueba:** proveedor con las cadenas de la tabla en `HorusAdvisorTest` (hoy `:225-229` solo cubre número ajeno y dominio con esquema).

### H-05 · MEDIO · El panel no refleja ni permite supervisar el motor público
- **Dónde:** `Admin/AsistenteController.php:70` (ámbito `admin:`) + `AsistenteVirtualService.php:47` (solo `public:` activa el Advisor; probado como intencional en `HorusAdvisorTest.php:397`); `HorusAdvisorClient.php:83` (`intent => null`); `ChatLog` sin columna `fuente`; «Intenciones configuradas» lista solo `chat_intents` (`AsistenteController.php:33-35`).
- **Impacto:** con el Advisor activo, el probador administrativo seguiría usando PHP y los registros no distinguirían Advisor, local o respaldo.
- **Corrección [DECISIÓN]:** probador con ámbito seleccionable (o etiqueta clara de «motor local»), registrar `fuente` (requiere **migración** → autorización previa, no aplicar a PostgreSQL real) y, opcionalmente, el `intent` devuelto por Python validado con `[a-z_]{1,64}`.
- **Prueba:** feature tests del panel; `AdminAuthorizationTest` debe seguir pasando sin cambios.

### H-10 · MEDIO · Componentes críticos fuera de Git y Fase 4B no aislable
- **Dónde:** archivos sin seguimiento del §2; cambios 4B sin commit y mezclados en `AsistenteVirtualService.php`, `HorusAdvisorClient.php` y pruebas.
- **Impacto:** una pérdida del árbol (p. ej., `git clean`) rompe `/chat/mensaje`; no hay parche 4B independiente para revertir.
- **Corrección [DECISIÓN]:** respaldo externo inmediato (ver §11) y, cuando el propietario lo autorice, commit dedicado con solo los archivos del chat.
- **Prueba:** `git ls-files app/Support/FiltroPrivacidadChat.php` tras el commit; ejecución de las suites del §10.

### H-02 · BAJO · Duplicación en lugar de retirada
- **Dónde:** `AsistenteVirtualService.php:90-101`, `:200-254` vs `app/Support/ChatIntentMatcher.php`; nulos muertos `:153`, `:173`; se eliminaron comentarios de diseño (p. ej., sobre intenciones incorporadas desactivadas).
- **Corrección:** una única implementación (volver a inyectar `ChatIntentMatcher` o moverla a una clase dedicada), constante para la lista de temas, restaurar comentarios.
- **Prueba:** suites actuales del motor local sin cambios de resultado.

### H-06 · BAJO · El Advisor depende de las intenciones PHP
- **Dónde:** `AsistenteVirtualService.php:48`.
- **Evidencia:** desactivar en BD una sola intención incorporada apaga el Advisor en silencio (probado como intencional en `HorusAdvisorTest.php:398-400`).
- **Corrección:** documentar la regla o desacoplarla **[DECISIÓN]**.

### H-07 · BAJO · Contingencia degradada y latencia
- **Dónde:** `AsistenteVirtualService.php:144-151`; `HorusAdvisorClient.php:66`, `:73`.
- **Evidencia:** con Python caído, toda consulta (incluida «¿dónde están?») recibe el texto genérico; con el flag apagado se respondería la FAQ local. Cada mensaje hace dos llamadas en serie (hasta 2,5 s) con el candado tomado; con `php artisan serve` (monoproceso) bloquea el sitio.
- **Corrección [DECISIÓN]:** si `orientar()` devuelve `null`, continuar con el motor local en vez del texto genérico; opcionalmente cachear `/ready` unos segundos.
- **Prueba:** adaptar `test_every_remote_failure_returns_to_php_once_without_leaking_errors` y `test_contingency_*` al comportamiento elegido.

### H-08 · BAJO · Errores documentales del informe 4B
- Endpoint `/chat` → real `POST /api/v1/chat`. Modelo L6 → real L12. `semantic_model_name` es configuración muerta.

### H-09 · BAJO · Restos de OpenAI y variables no documentadas
- Ver §5; además `.env.example` sin variables `HORUS_ADVISOR_*`. Retirar configuración solo con autorización y sin tocar `.env` reales.

### H-11 · INFORMATIVO · Deduplicación sobre texto normalizado
- `AsistenteVirtualService.php:52`, `:60-66`: un acierto de caché («buenos dias» → «Buenos Días») devuelve la respuesta previa sin reevaluar el filtro. No hay HTTP ni registro en ese camino, por lo que no hay exposición.

### H-12 · INFORMATIVO · Tiempos desalineados
- Python `request_timeout_seconds=5` vs Laravel `timeout=2.5`.

---

## 10. Pruebas: estado y cómo ejecutarlas

### Conteo estático (no ejecutado)
| Archivo | HEAD → árbol | Nota |
|---|---|---|
| `tests/Feature/AsistenteVirtualTest.php` | 9 → 9 métodos | 1 prueba OpenAI sustituida por «nunca llama a OpenAI» |
| `tests/Feature/ChatbotHybridTest.php` | 13 → 13 | 5 pruebas OpenAI sustituidas; deduplicación conservada |
| `tests/Feature/HorusAdvisorTest.php` | 13 → 29 | con proveedores de datos; «117» sin verificar |
| `tests/frontend/chat-client.test.mjs` | 6 → 7 | añade lista blanca de WhatsApp |
| Python (`chatbot-python/tests`) | 124 `def test_` en 10 archivos | «154 + 11» incluyen parametrizaciones; sin verificar |

- Retirar pruebas de OpenAI es coherente con la retirada del comportamiento; no se hallaron aserciones de privacidad debilitadas.
- Todo usa `Http::fake` con `Http::preventStrayRequests()` (`HorusAdvisorTest.php:31`, `ChatbotHybridTest.php:23`). **No existen pruebas entre procesos reales.**
- Huecos: variantes de H-04; consultas legítimas de H-03.

### Comandos para un entorno aislado (cuando se autorice)
```powershell
php artisan test --filter='HorusAdvisorTest|AsistenteVirtualTest|ChatbotHybridTest|AdvisorCatalogoTest|AdminAuthorizationTest|FiltroPrivacidadChatTest'
php artisan test --compact          # antes de afirmar estabilidad
vendor/bin/phpstan analyse          # cambios en app/Services o app/Support
vendor/bin/pint --test <solo archivos tocados>
npm test
git diff --check
# Python (escribe .pytest_cache):
cd chatbot-python
.venv\Scripts\python -m pytest -m "not semantic"
.venv\Scripts\python -m pytest -m semantic
```
`phpunit.xml` usa SQLite en memoria: no prueba PostgreSQL, concurrencia real, OpenAI ni FastAPI real.

---

## 11. Reversión (ninguna opción ejecutada)

**Paso previo obligatorio:** respaldo fuera del repositorio, sin tocar índice ni árbol:
```powershell
git diff --binary > <ruta externa>\fase4b-wt.patch
# y copiar a <ruta externa> los archivos sin seguimiento del chat (§2)
```

| Opción | Estado |
|---|---|
| **A. Desactivar el Advisor** | **Acreditada y vigente** (es el estado actual). Resultado: FAQ PHP local + filtro nuevo (con H-03) |
| **B. Revertir solo la Fase 4B** | **No acreditada.** Sin commit aislado; requiere un parche inverso por bloques sobre `AsistenteVirtualService.php` y las 3 pruebas, probado primero en una copia o worktree. Reintroduciría OpenAI |
| **C. Recuperar el motor anterior** | Solo como referencia: `git show HEAD:app/Services/AsistenteVirtualService.php` (versión previa a 4B, con OpenAI), exportada a ruta externa y comparada. Nunca `checkout`/`restore`; nunca `origin/anderson` |

---

## 12. Orden de trabajo sugerido (si el propietario lo autoriza)

1. Respaldo externo (§11) y decisión sobre commit dedicado (H-10).
2. Decisión sobre H-01 (flag por entorno o redefinir 4B).
3. H-04 (validador estricto) con su proveedor de pruebas.
4. H-03 (afinar filtro) con consultas legítimas y sin perder detecciones existentes.
5. H-07 y H-05 (contingencia y supervisión); H-05 requiere migración autorizada.
6. Limpieza: H-02, H-08, H-09.
7. Ejecutar las suites del §10 y, solo entonces, prueba de humo con FastAPI en loopback y validación en Microsoft Edge.

No avanzar a la Fase 5 hasta completar al menos los pasos 1–4.

## 13. Entrega esperada de Codex tras cualquier cambio

Según `CLAUDE.md` §8: qué cambió y por qué, rutas afectadas, comandos ejecutados con su resultado real, límites de la evidencia (SQLite ≠ PostgreSQL, `Http::fake` ≠ FastAPI real) y estado final de `git status` confirmando que los 24 commits, los 2 stashes y el trabajo ajeno siguen intactos.
