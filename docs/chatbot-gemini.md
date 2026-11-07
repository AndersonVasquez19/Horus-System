# Gemini: selección opcional de fuentes públicas

La integración usa la API REST de Google mediante HTTPX, que ya está fijado en los locks Python. No requiere instalar otro SDK. La presencia de una `GEMINI_API_KEY` no vacía en el entorno del servicio o en `chatbot-python/.env` habilita el selector; sin clave se conserva el motor local.

Variables del servicio Python:

```dotenv
GEMINI_API_KEY="<clave guardada localmente>"
GEMINI_MODEL="gemini-3.1-flash-lite"
```

`GEMINI_MODEL` es opcional. El modelo predeterminado es `gemini-3.1-flash-lite`; el nombre se valida para impedir destinos arbitrarios. La clave se representa como secreto y solo sale por la cabecera `x-goog-api-key` hacia `https://generativelanguage.googleapis.com`. No se incluye en URL, respuestas ni logs.

## Contrato y privacidad

Laravel conserva sus filtros, su autenticación con Python y la validación de las fuentes. Los saludos, solicitudes sensibles, inyección de instrucciones, cotizaciones y atributos no publicados mantienen las decisiones locales. Gemini ayuda a seleccionar entre hasta tres fuentes verificadas para consultas informativas; recibe conceptos que ya aparecen en el vocabulario público y descripciones públicas acotadas. No recibe el mensaje original, historial, sesión ni datos administrativos.

La respuesta de Google debe ser JSON con un identificador incluido en las opciones enviadas. El visitante recibe el texto literal de la fuente validada, no prosa generada por Google. Los errores, las respuestas incompletas, los identificadores desconocidos, las redirecciones y los cuerpos excesivos conservan la respuesta local. Las conexiones HTTP se reutilizan y se cierran al detener el servicio.

El selector utiliza un presupuesto acotado compatible con el límite existente de Laravel; no modifica sus tiempos de espera. `/api/v1/info` informa `selection_model`, sin exponer credenciales. `generative_model` sigue siendo `none` porque no se publica texto libre generado. Una sincronización del catálogo conserva el selector configurado. Los controles de firma, vigencia, versión y persistencia del catálogo siguen siendo obligatorios: la clave de Google no corrige rutas de almacenamiento inválidas.

## Comprobación

Desde `chatbot-python`:

```powershell
.\.venv\Scripts\python.exe -m pytest tests/test_gemini.py tests/test_security.py -q
.\.venv\Scripts\python.exe -m pytest -q
```

Las pruebas usan credenciales ficticias y transporte simulado; bloquean las conexiones externas. Verifican aislamiento de secretos, contenido saliente, respuesta literal, persistencia del proveedor tras sincronizar, errores y comportamiento sin clave. No acreditan disponibilidad, cuotas, latencia ni facturación real de Google. Las pruebas reales deben usar consultas sintéticas públicas y registrar solo estados o métricas.

Referencias oficiales: [claves de Gemini](https://ai.google.dev/gemini-api/docs/api-key), [GenerateContent REST](https://ai.google.dev/api/generate-content), [salida estructurada](https://ai.google.dev/gemini-api/docs/structured-output), [niveles de pensamiento](https://ai.google.dev/gemini-api/docs/thinking).
