# Horus AI Advisor (fase 1 + sprint 2)

Motor informativo y de recomendaciones de **Horus Group SRL**, escrito en Python con FastAPI. Es un servicio **independiente**: no modifica ni reemplaza el chatbot PHP de Laravel, no se conecta a PostgreSQL ni llama a OpenAI.

Qué hace:

- Responde preguntas sobre la empresa, sus servicios, la formación, el asesoramiento, los convenios, las FAQ y los canales de contacto, **solo con información pública verificada**.
- Recomienda servicios y formación con motivo, evidencia literal, señales, limitaciones y siguiente paso.
- Entiende conversaciones cortas: «¿y eso es presencial?» se responde sobre la opción anterior, y solo con lo publicado.
- Orienta **solicitudes de cotización** sin calcular precios ni consultar cotizaciones emitidas.
- Pide aclaración cuando la consulta es ambigua y se **abstiene** cuando la información no está confirmada (precios, fechas, cupos, requisitos, promociones, catálogo de cursos).
- Está preparado para recibir en el futuro un **catálogo público firmado** exportado por Laravel (§13). Hoy no hay ninguno conectado.

Qué **no** es: no es un modelo generativo ni un modelo entrenado para Horus. Usa reglas, búsqueda léxica y, de forma opcional, embeddings **preentrenados** (solo inferencia).

### Novedades del sprint 2

| Área | Cambio |
| --- | --- |
| Comprensión | `app/services/understanding.py`: atributos preguntados (modalidad, precio, cupos, fechas, requisitos, certificado, público, plazos), referencias («eso», «¿y…?»), cambios de tema, cotizaciones públicas y privadas |
| Contexto | Pequeño, explícito y derivado: como máximo 3 mensajes del usuario; se devuelve en `context` y no se almacena. El texto `assistant` del cliente nunca se usa como fuente |
| Recomendaciones | `signals`, `limitations`, `evidence_text` (frase literal de la web), `next_step` para cotizar y objetivos (p. ej. «postular al Estado») |
| Seguridad semántica | Una coincidencia solo vectorial cuenta únicamente si el coseno es ≥ 0,72; los topónimos («Perú», «Cajamarca») pesan poco |
| Catálogo futuro | Contrato `schema_version 1.0` con firma HMAC, caducidad y estados `vigente`/`retirado`/`no_publicado`/`desconocido`; `POST /api/v1/catalog/validate` en seco |
| Evaluación | 195 consultas y 12 conversaciones (sintéticas), métricas por lote y origen, memoria, comparación con el PHP y comprobaciones de robustez |

---

## 1. Requisitos

| Requisito | Valor medido en este equipo (8 GB de RAM, sin GPU) |
| --- | --- |
| Python | 3.12 o 3.13 (probado con 3.13.0) |
| Sistema | Windows 10/11 con PowerShell (también funciona en Linux/macOS) |
| Memoria, modo léxico | ≈ 50 MB de conjunto de trabajo |
| Memoria, modo híbrido | Pico ≈ 1,0-1,1 GB de conjunto de trabajo y ≈ 1,85-1,9 GB de memoria privada. Conviene tener ≥ 3 GB libres |
| Disco | ≈ 50 MB en modo básico; ≈ 1,5 GB con PyTorch CPU y el modelo |
| GPU | No necesaria; el proyecto usa solo CPU |

> Corrección respecto de la fase 1: el informe anterior citaba «≈ 228 MB con el modelo». Esa medición era engañosa (Windows recorta el conjunto de trabajo). Las cifras de arriba proceden de `scripts/sysinfo.py`: conjunto de trabajo, pico y memoria privada.

Todo se instala en `chatbot-python\.venv`. El Python global, Composer, Laragon y Laravel no se tocan.

## 2-3. Crear el entorno e instalar dependencias

Desde PowerShell, en la carpeta `chatbot-python`:

```powershell
cd "<ruta-del-repositorio>\chatbot-python"
python -m venv .venv
.venv\Scripts\python.exe -m pip install --upgrade pip
.venv\Scripts\python.exe -m pip install --require-hashes -r requirements.lock
```

Si PowerShell bloquea `Activate.ps1`, no hace falta activar el entorno: basta con usar `.venv\Scripts\python.exe` como en los ejemplos.

| Archivo de bloqueo | Contenido |
| --- | --- |
| `requirements.lock` | FastAPI, Uvicorn, Pydantic, Pydantic Settings, HTTPX, Pytest, Ruff |
| `requirements-semantic.lock` | Lo anterior más sentence-transformers y **torch CPU** (`2.15.0+cpu`, sin CUDA). Incluye `httpx2`, dependencia de `huggingface_hub`, que el TestClient usa cuando está presente |

El sprint 2 no añadió dependencias. Para regenerar los bloqueos: `.venv\Scripts\uv.exe pip compile pyproject.toml --extra dev --python-version 3.13 --generate-hashes -o requirements.lock`.

## 4. Seleccionar el intérprete en VS Code

1. Abre la carpeta `chatbot-python` en VS Code. Su `.vscode/settings.json` ya apunta al entorno.
2. Si abres la raíz de Horus-System: `Ctrl+Shift+P` → **Python: Select Interpreter** → `chatbot-python\.venv\Scripts\python.exe`.
3. Extensiones recomendadas: *Python* (Microsoft) y *Ruff* (Astral).

## 5. Ejecutar FastAPI

```powershell
Copy-Item .env.example .env
.venv\Scripts\python.exe -c "import secrets; print(secrets.token_urlsafe(48))"   # pégalo en HORUS_AI_SERVICE_TOKEN
.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8001
```

Sin un token de 32 caracteres o más, `/api/v1/*` responde 503 (falla cerrado). `python -m app` arranca igual y además rechaza cualquier host que no sea loopback.

## 6. Swagger y endpoints

Abre <http://127.0.0.1:8001/docs> → **Authorize** → cabecera `X-Horus-Service-Token`.

| Método | Ruta | Autenticación | Uso |
| --- | --- | --- | --- |
| GET | `/health` | Pública | El proceso está vivo |
| GET | `/ready` | Pública | Estado de `knowledge`, `semantic` (`disabled`/`loading`/`loaded`/`unavailable`), `auth` y `catalog` (`none`/`loaded`/`stale`/`error`) |
| GET | `/api/v1/info` | Token | Versión, capacidades, modo, límites y `catalog_version` |
| POST | `/api/v1/chat` | Token | `message`, `session_id` y `history` (≤ 10). Devuelve `answer`, `intent`, `match_level`, `recommendations[]`, `needs_clarification`, `clarification_question`, `abstained`, `missing_data`, `general_guidance`, `sources`, `mode` y `context` |
| POST | `/api/v1/recommend` | Token | `interests[]`, `goal`, `level`, `modality` y `audience` |
| POST | `/api/v1/catalog/validate` | Token | Valida un catálogo **en seco**, hasta 1 MB. No lo carga ni lo guarda (§13) |

Cada elemento de `recommendations[]` incluye `reason`, `evidence`, `evidence_text`, `signals`, `limitations`, `needs_clarification`, `clarification_question` y `next_step`. `match_level` mide la calidad de la coincidencia: **no es una probabilidad**.

Intenciones nuevas del sprint 2:

- `quote_request`: orienta la cotización; también se usa para una cotización privada, que se rechaza.
- `followup_attribute`: responde un atributo de la opción anterior.

## 7. Pruebas

```powershell
.venv\Scripts\python.exe -m pytest                 # 144 básicas: deterministas, sin modelo ni red
.venv\Scripts\python.exe -m pytest -m semantic     # 11 de integración con el modelo real
.venv\Scripts\ruff.exe check . ; .venv\Scripts\ruff.exe format --check .
```

Las pruebas bloquean cualquier conexión de red no local (`tests/conftest.py`). La carga del modelo real también ocurre bajo ese bloqueo, lo que demuestra que funciona sin Internet tras la descarga.

| Archivo | Cubre |
| --- | --- |
| `test_api.py` | Health/ready, estructura, mensajes vacíos o largos, historial excesivo, cuerpo grande, JSON inválido, Swagger |
| `test_knowledge.py` | Solo lo verificado se recomienda, las fuentes existen, no hay datos privados, se rechazan registros incompletos |
| `test_recommendations.py` | Principiante, redes, mantenimiento, empresas, ambigüedad, multi-turno, catálogo ficticio |
| `test_conversation.py` | **Sprint 2**: «eso», cambio de tema, límite de 3 turnos, historial `assistant` no confiable, cotizaciones (sin cifras), cotización privada, atributos no publicados, explicabilidad, objetivos con evidencia, control semántico |
| `test_catalog.py` | **Sprint 2**: estados de publicación, `no_publicado` nunca aparece, retirado y desconocido no se afirman, caducidad, firma, campos prohibidos (cotizaciones, estudiantes), privacidad, validación en seco, catálogo dañado → servicio con la base verificada |
| `test_semantic.py` | Sinónimos, errores ortográficos, reformulaciones, irrelevantes, abstención |
| `test_security.py` | Prompt injection, datos sensibles, campos inesperados, token, host, sin CORS, sin secretos, sin HTTP externo, sin claves de OpenAI ni Resend |
| `test_evaluation_dataset.py` | El dataset no copia ejemplos ni palabras clave; los splits están equilibrados |
| `test_semantic_integration.py` | Modelo real: paráfrasis, sin falsos positivos irrelevantes, carga sin red, modelo dañado → modo léxico |

## 8. Evaluación

```powershell
.venv\Scripts\python.exe scripts\evaluate.py --mode both --tag final   # cada modo en su propio proceso
```

Genera `reports/evaluation-<modo>-<tag>.{md,json}` (ignorado por Git).

**Datos.** `data/evaluation.json` contiene 195 consultas (99 dev / 96 test) en 21 categorías: información empresarial, cursos, principiantes, recomendación, servicios, necesidades empresariales, solicitudes comerciales, errores ortográficos, coloquial peruano, irrelevantes, inducción a inventar, información insuficiente y otras. `data/conversations.json` contiene 12 escenarios y 28 turnos.

> **Todas las consultas son sintéticas** (`origin: "sintetico_asistente"`): las redactó el mismo asistente de IA que construyó el motor. No hay todavía casos de revisión humana externa (`origin: "revision_humana"`, campo previsto). No son evidencia de usuarios reales.

**Método.**

- Las 86 consultas y los 12 escenarios nuevos se escribieron **antes** de modificar el motor, y la línea base «antes» se midió con el motor sin cambios.
- Los ajustes se hicieron solo con fallos del split `dev`.
- Se revisaron 2 etiquetas de `dev` (k13, k17; campo `label_revised`) porque el sprint pide el comportamiento nuevo. Ninguna etiqueta de `test` se revisó.

**Resultados del 29-10-2026** (comportamiento correcto):

| Conjunto | Léxico antes → después | Híbrido antes → después |
| --- | --- | --- |
| Todos (195) | 0,800 → 0,882 | 0,815 → 0,903 |
| dev (99) | 0,859 → 0,960 | 0,848 → 0,960 |
| **test (96)** | **0,740 → 0,802** | **0,781 → 0,844** |
| test, solo casos del sprint 2 (42) | 0,619 → 0,762 | 0,643 → 0,786 |
| Escenarios completos (12) | 0,583 → 1,000 | 0,500 → 1,000 |
| Turnos de conversación (28) | 0,786 → 1,000 | 0,750 → 1,000 |
| Respuestas inventadas | 0 → 0 | 0 → 0 |

Otras métricas finales en test, léxico / híbrido:

| Métrica | Léxico | Híbrido |
| --- | --- | --- |
| Recuperación hit@1 | 0,604 | 0,667 |
| Precisión@1 de recomendaciones | 0,737 | 0,842 |
| Sensibilidad de abstención | 0,696 | 0,739 |
| Precisión de abstención | 0,889 | 0,926 |

Latencia en proceso: léxico p50 ≈ 45-58 ms; híbrido p50 ≈ 85-170 ms. Varía entre ejecuciones según la carga del equipo.

**Lectura honesta:**

- Una corrección se descubrió en test: las preguntas n08 y s2-i06 («¿el certificado vale en el extranjero?») dejaron de abstenerse por una regla nueva. Se corrigió porque era un defecto de prudencia. El híbrido en test pasó de 0,823 a 0,844 con esa corrección, así que la cifra de test es algo optimista.
- El 100 % en conversaciones se mide sobre 12 escenarios propios: indica que el mecanismo funciona, no que generalice.
- Fallos que persisten en test: paráfrasis sin palabras compartidas, «¿reparan autos?» (recomienda soporte de computadoras), «muchas gracias por todo», coloquialismos como «mi laptop está hecha leña» o «jala señal feo el wifi», y «Quiero capacitar a mi equipo de ventas» (responde con información en vez de recomendar). Lista completa en `reports/evaluation-*-final.md`.

### Comparación con el chatbot PHP (fase G)

```powershell
.venv\Scripts\python.exe scripts\compare_php.py --python-tag final
```

**Condiciones.** El script `scripts/php_compare/php_matcher.php` ejecuta, en solo lectura, `AsistenteVirtualService::resolver()` con las intenciones de `ChatKnowledge`.

- Fuerza SQLite en memoria, caché en arreglo, logs descartados y clave de OpenAI vacía.
- **Aborta** si la configuración efectiva no lo confirma.
- Se comprobó que no cambió ningún archivo de `bootstrap/cache`, `storage/logs` ni `storage/framework`.
- **No** incluye las intenciones de la base de datos, OpenAI ni el catálogo dinámico.

| Motor | test | Todos | Turnos | Escenarios | Latencia p50 |
| --- | --- | --- | --- | --- | --- |
| PHP, motor local | 0,438 | 0,421 | 0,357 | 0,083 | **0,8 ms** |
| Python, léxico | 0,802 | 0,882 | 1,000 | 1,000 | ≈ 50 ms |
| Python, híbrido | 0,844 | 0,903 | 1,000 | 1,000 | ≈ 85-170 ms |

El PHP recurre a su respuesta de respaldo en 139 de 195 consultas. **Sesgo a tener en cuenta:** el dataset se diseñó junto con el motor Python y premia capacidades que el PHP no tiene (aclarar, recomendar, cotizar, seguir el contexto). Para lo que el PHP no puede expresar se usan reglas de equivalencia explícitas (`php_matches`). El PHP es igual o mejor en irrelevantes (1,0 frente a 0,94) y en seguridad (1,0), es mucho más rápido y no necesita ML. Por todo ello la diferencia global exagera la ventaja. Una comparación justa requiere consultas reales y evaluadores externos.

### Robustez (fase F)

Con el servidor iniciado:

```powershell
$env:HORUS_AI_SERVICE_TOKEN = "<token del servidor>"
.venv\Scripts\python.exe scripts\robustness_check.py --requests 160 --concurrency 8
```

La carga está acotada a 16 peticiones simultáneas y 400 en total. Resultados del 29-10-2026 (modo híbrido, `127.0.0.1:8001`):

- `/ready` informó `semantic: loaded`.
- Errores controlados: 401 sin token o con token incorrecto, 422 (vacío, largo, campo inesperado, JSON inválido), 413 cuerpo grande, 404 ruta inexistente y 400 host no confiable.
- Ninguna respuesta contenía el token, rutas ni trazas.
- Los mensajes que piden ejecutar comandos o leer el `.env` se rechazan y no se creó ningún archivo.
- 160 peticiones con 8 simultáneas: todas 200, 11,8 peticiones/s, p50 678 ms y máx. 925 ms (cola del semáforo de 4).
- Memoria del servidor: ≈ 0,83 GB de conjunto de trabajo (pico 1,06 GB) y ≈ 1,9 GB privada.
- **Ninguna conexión externa** del proceso.

## 9. Agregar información autorizada

1. Comprueba el dato en una fuente pública autorizada.
2. Añade un registro a `data/knowledge.json`. Campos obligatorios: `id`, `category`, `kind`, `title`, `description`, `answer`, `status`, `source` y `keywords`. Son opcionales `topics`, `audience`, `modalities`, `levels`, `recommendable`, `related`, `requirements`, `duration`, `schedule`, `public_price` y `superseded_by_catalog`.
3. Usa `status`:
   - `verified`: confirmado;
   - `provisional`: pendiente (nunca se afirma);
   - `unavailable`: no publicado; el asistente se abstiene y deriva.
4. Ejecuta `pytest` y `scripts\evaluate.py`.
5. **Nunca** copies datos de tablas administrativas, estudiantes, matrículas, cotizaciones ni conversaciones.

## 10. Búsqueda semántica (opcional)

Modelo: `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` (Apache-2.0, 118 M de parámetros, ≈ 470 MB, solo inferencia).

```powershell
.venv\Scripts\python.exe -m pip install --require-hashes -r requirements-semantic.lock --extra-index-url https://download.pytorch.org/whl/cpu
.venv\Scripts\python.exe scripts\download_model.py      # único paso con Internet
# .env: HORUS_AI_SEMANTIC_ENABLED=true
```

Comportamiento:

- Carga única en segundo plano (≈ 19-57 s según la caché de disco). Mientras tanto responde en modo léxico.
- Sin descargas en ejecución (`HF_HUB_OFFLINE=1`).
- Si el modelo falta o está dañado, el servicio sigue en modo léxico; está probado.

**Sprint 2: menos falsos positivos.** Una coincidencia **sin apoyo léxico** (puntuación léxica < 0,15) solo cuenta si el coseno es ≥ 0,72. Aun así puntúa como máximo 0,5, por debajo del umbral de respuesta: nunca afirma, como mucho pide aclaración. Antes, «¿Cuál es la capital del Perú?» (coseno 0,67) provocaba una aclaración sobre la sede; ahora se abstiene. El caso `xfail` de la fase 1 («billeteras digitales») era un hueco de vocabulario: Yape y Plin no figuraban como billeteras. Se resolvió con palabras clave y ahora es una prueba normal.

## 11. Detener el servidor

`Ctrl+C`, o bien `Get-NetTCPConnection -LocalPort 8001 -State Listen | ForEach-Object { Stop-Process -Id $_.OwningProcess }`.

## 12. Integración futura con Laravel (documentada; **no implementada**)

Flujo: `Visitante → widget existente → Laravel (POST /chat/mensaje) → FastAPI → Laravel → visitante`.

Laravel sigue controlando sesión, CSRF, validación, `throttle:30,1`, permisos, privacidad, registro de metadatos y el **fallback** al chatbot PHP.

| Tema | Cómo |
| --- | --- |
| **Feature flag** | Una clave de configuración Laravel, p. ej. `chatbot.python_advisor_enabled`, **`false` por defecto**, y la URL y el token en el `.env` de Laravel. Sin el flag, el flujo PHP actual no cambia |
| **Autenticación** | Cabecera `X-Horus-Service-Token` con un secreto de ≥ 32 caracteres en ambos `.env`. Solo servidor a servidor, nunca en el navegador. FastAPI escucha en loopback y rechaza hosts no locales. Rotación (pendiente): aceptar dos tokens durante el cambio |
| **Fallback** | Timeout de Laravel de 2-3 s. Ante timeout, error de conexión, 401, 413, 422, 5xx o `/ready` ≠ `ready`, Laravel usa `AsistenteVirtualService` sin cambios visibles. Recomendado: si `abstained` es `true` y el PHP tiene coincidencia local, preferir la del PHP. Un *circuit breaker* simple (p. ej. 5 fallos en 1 min) evita insistir |
| **Contexto** | Laravel envía solo los últimos turnos (≤ 10) sin datos personales. FastAPI usa como máximo 3 mensajes del usuario y no guarda nada |
| **Cotizaciones y estudiantes** | Las cotizaciones se gestionan **solo** en Laravel con sus permisos. FastAPI orienta la solicitud, no recibe cotizaciones, clientes, estudiantes ni matrículas, y rechaza consultarlas (`quote_request` con `abstained`). El contrato del catálogo prohíbe esos campos (`extra="forbid"`) y el cargador busca correos, teléfonos y marcadores privados |
| **Probar antes del frontend** | (1) `pytest` y `scripts\evaluate.py`; (2) servidor + `scripts\robustness_check.py`; (3) `scripts\compare_php.py` con las mismas preguntas; (4) en Laravel, una prueba de feature con `Http::fake()` para FastAPI (éxito, timeout, 503), con el flag activado solo en la prueba; (5) solo después, autorización para tocar el widget |

## 13. Contrato del catálogo público (Laravel → FastAPI)

Laravel exportaría a un archivo JSON firmado **solo información pública autorizada** (cursos, capacitaciones, servicios, asesorías, modalidades, requisitos, canales e información comercial publicada). FastAPI lo lee al arrancar con `HORUS_AI_CATALOG_PATH` y `HORUS_AI_CATALOG_SIGNING_KEY`; desactivado por defecto. No hay conexión a PostgreSQL ni endpoints de Laravel.

```json
{
  "schema_version": "1.0",
  "catalog_version": "2026.11.05",
  "source_system": "horus-laravel",
  "generated_at": "2026-11-05T08:00:00-05:00",
  "expires_at": "2026-12-05T08:00:00-05:00",
  "items": [
    {
      "stable_id": "curso:redes-basico",
      "type": "curso",
      "publication_status": "vigente",
      "title": "…",
      "summary": "…",
      "topics": ["redes"],
      "modalities": ["presencial"],
      "levels": ["principiante"],
      "requirements": ["…"],
      "duration_text": "…",
      "schedule_text": "…",
      "public_price": {"amount": "150.00", "currency": "PEN", "conditions": "…"},
      "url_path": "/educacion/cursos",
      "updated_at": "2026-11-04T10:00:00-05:00",
      "valid_from": "2026-11-10",
      "valid_until": "2026-12-20"
    }
  ],
  "signature": "hmac-sha256:<64 hex>"
}
```

**Tipos:** `curso`, `capacitacion`, `servicio`, `asesoria`, `modalidad`, `requisito`, `canal_consulta`, `info_comercial`. El `stable_id` lleva el prefijo del tipo y **nunca se reutiliza**.

**Estados de publicación:**

| Estado | Efecto en el asistente |
| --- | --- |
| `vigente` | Hecho verificado y recomendable, si está dentro de `valid_from`/`valid_until` y el catálogo no caducó |
| `vigente`, fuera de su ventana | «No está vigente en este momento» |
| `retirado` | «Figura como retirado»; nunca se recomienda |
| `no_publicado` | Se **descarta por completo**: nunca se menciona |
| `desconocido` | No se afirma (provisional) |

**Versionado e invalidación:**

- `catalog_version` aparece en `/api/v1/info` y en cada respuesta generada desde el catálogo.
- `expires_at` (≤ 90 días) caduca **todo** el catálogo: nada se afirma y `/ready` informa `catalog: stale` hasta que Laravel exporte uno nuevo.
- Para retirar un ítem, márcalo `retirado` en la siguiente exportación; no lo borres.
- Si hay ítems de formación vigentes, el registro «la web no publica la lista de cursos» se reemplaza automáticamente (`superseded_by_catalog`).

**Firma.** `HMAC-SHA256(clave, JSON canónico)` sobre los campos `schema_version`, `catalog_version`, `source_system`, `generated_at`, `expires_at` e `items`. El JSON canónico usa:

- claves ordenadas recursivamente;
- separadores `,` y `:` sin espacios;
- UTF-8 sin escapar caracteres no ASCII ni `/`.

Los importes van como **cadena** ("150.00") para evitar diferencias de coma flotante. Laravel puede comprobar su firma con `POST /api/v1/catalog/validate`, que devuelve `signature: valid|invalid|missing`.

**Seguridad:**

- Sin clave, el catálogo se rechaza, salvo `HORUS_AI_CATALOG_ALLOW_UNSIGNED=true`, solo para pruebas.
- Un catálogo inválido o alterado no tumba el servicio: se sigue con `data/knowledge.json` y `/ready` informa `catalog: error`.
- Datos de prueba: `tests/fixtures/catalogo_laravel_ficticio.json`, marcado como **[FICTICIO]**.

---

## Estructura

```text
chatbot-python/
├── app/
│   ├── main.py                 # arranque, límites de tamaño (1 MB solo para catalog/validate), errores, catálogo opcional
│   ├── api/routes.py           # /health, /ready, /api/v1/{info,chat,recommend,catalog/validate}
│   ├── core/{config,security,text}.py
│   ├── schemas/{chat,knowledge,catalog}.py
│   └── services/
│       ├── understanding.py    # sprint 2: atributos, referencias, cambio de tema, cotizaciones
│       ├── chat_engine.py      # seguridad → reglas → cotización → seguimiento → atributo no publicado → recomendación/búsqueda
│       ├── recommendations.py  # perfil, objetivos, señales, limitaciones, siguiente paso
│       ├── catalog.py          # sprint 2: firma, caducidad, estados → registros
│       ├── knowledge.py, lexical_search.py, semantic_search.py
├── data/       # knowledge.json, intents.json, evaluation.json (195), conversations.json (12)
├── scripts/    # evaluate.py, compare_php.py, php_compare/php_matcher.php, robustness_check.py, sysinfo.py, download_model.py
├── tests/      # 155 pruebas (144 básicas + 11 semánticas); fixtures marcadas como FICTICIO
└── models/, reports/   # ignorados por Git
```

## Fuentes del conocimiento

Solo vistas públicas de Horus-System (`pages/*`, `components/footer`, `components/schedule-location`); el chatbot PHP se usó como referencia en solo lectura.

Exclusiones deliberadas: nombres de personas, datos administrativos y los módulos Docentes, Sesiones y Certificados.

Registros `provisional`:

- las cifras publicitarias;
- el «reconocimiento internacional» de las certificaciones;
- el convenio con ISAM.

El horario se presenta con su discrepancia. La web **no publica ningún curso con nombre**: el catálogo de §13 es la vía prevista para que eso cambie.
