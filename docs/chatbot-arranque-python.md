# Optimización del arranque Python — 12 de noviembre de 2026

## Cambio

`SentenceTransformerEmbedder` reconoce la composición local de MiniLM: Transformer BERT,
128 tokens y mean pooling de 384 dimensiones. En ese caso carga los mismos pesos y
tokenizer con `AutoModel` y `AutoTokenizer`, ejecuta inferencia en CPU y conserva la
normalización y los lotes de 16 del adaptador anterior. Evita importar las herramientas
de entrenamiento/evaluación de sentence-transformers al arrancar.

`app/services/minilm_inference.py` verifica los archivos de configuración antes de
seleccionar esa ruta. Un pipeline distinto, prompts, módulos adicionales, adaptadores,
otra longitud o configuración no reconocida conservan el cargador sentence-transformers.
Los errores de carga mantienen la contingencia existente; no se cambian sus límites.

No se reemplazan ni cuantizan los pesos. No cambia el conocimiento, los filtros de
privacidad, la firma/vigencia del catálogo ni sus predeterminados. No se modifican
dependencias, perfiles de entorno, credenciales, el widget o el lanzador externo.

El cambio se carga en el **siguiente arranque del proceso Python**. Se mantiene el comando
`Iniciar-Chatbot.ps1` que ya utiliza el propietario. Un proceso que continúa activo
conserva sus módulos anteriores; las comprobaciones no reiniciaron el servicio real.

## Medición reproducible

Desde `chatbot-python`:

```powershell
.venv\Scripts\python.exe scripts\benchmark_startup.py --runs 3
```

El comando usa procesos separados, bloquea conexiones de red, no carga `.env`, no abre
servidores y utiliza el modelo y conocimiento públicos locales. Crea cachés temporales
independientes y las elimina al terminar. Alterna el primer método para reducir el sesgo
de la caché de Windows. Las dos primeras muestras construyen el índice sin caché de
vectores; las siguientes reutilizan su caché correspondiente.

Equipo actual: Python 3.12.10, CPU, dos hilos; 318 pasajes públicos. Cada tiempo incluye
importaciones del motor, carga de pesos/tokenizer, índice y primera búsqueda:

| Muestra | Original | Optimizado | Caché de vectores |
| --- | ---: | ---: | --- |
| 1 | 11,1421 s | 9,2930 s | Sin caché previa |
| 2 | 8,4561 s | 6,9681 s | Existente |
| 3 | 8,2131 s | 7,4030 s | Existente |
| **Mediana** | **8,4561 s** | **7,4030 s** | Tres muestras por método |

Reducción de la mediana: **12,5 %**. En las tres muestras originales se importó el
entrenador de sentence-transformers; en las tres optimizadas no se importó.

Las cifras históricas de más de dos minutos no son la línea base actual: el perfil
inicial de esta tarea dio 15,80 s, con instrumentación de importaciones, modelo y
primera codificación; la comparación repetida anterior usa el mismo procedimiento
para ambos métodos. No se atribuye una reducción de dos minutos a siete segundos.

Esta evidencia no mide un arranque en frío de Windows ni el tiempo completo hasta
la disponibilidad pública: faltan la apertura HTTP, el gestor Laravel y su renovación
del catálogo. Tampoco es un SLA; la carga del equipo y la caché del sistema afectan
los tiempos. El catálogo vencido sigue rechazándose hasta su renovación válida.

## Comprobaciones

- Prueba focalizada Python: 56 aprobadas, dos semánticas deseleccionadas.
- Archivo de regresión final `test_minilm_inference.py`: 17 aprobadas, incluidas
  las dos integraciones con MiniLM real. FastAPI en proceso alcanza `ready`,
  rechaza una petición sin token y responde a «Hola» en modo híbrido con token
  ficticio; sin servidores reales ni renovación del catálogo PostgreSQL.
- Comparación con MiniLM real: 26 textos sintéticos, incluidos Unicode, vacíos,
  espacios, más de 128 tokens y varios lotes; vectores equivalentes con tolerancia
  absoluta de `1e-6` y relativa de `1e-5`.
- Comparación de los 318 vectores del índice y de respuestas completas a siete
  consultas sintéticas; compatibilidad exacta de la caché creada por el cargador
  anterior. Inferencia bajo el bloqueo de red de las pruebas.
- Suite Python completa, incluyendo pruebas semánticas: 365 aprobadas y un fallo
  en `test_respuestas_breves.py`, caso `chat-breve-catalogo.json`. Se reprodujo el
  mismo fallo usando el módulo `semantic_search.py` original de `HEAD` solo en
  memoria, sin sobrescribir el árbol: una aprobada y una fallida. El caso compara
  un fixture con el motor léxico y no carga el modelo optimizado.
  Esta ejecución precede a la última prueba adicional de FastAPI, aprobada en
  el archivo de regresión final anterior.
- Pruebas PHP de los archivos `AsistenteVirtualTest.php` y `HorusAdvisorTest.php`:
  132 aprobadas, 684 aserciones, SQLite en memoria y HTTP simulado. La selección
  global por filtro también descubrió dos proveedores de datos inválidos por
  fixtures de privacidad ausentes; no se modificaron esos controles ni fixtures.
- Frontend: 56 aprobadas. Ruff de los archivos Python propios aprobado.
- Suite PHP completa: 3058 aprobadas, cinco fallidas, una riesgosa y una omitida;
  15511 aserciones, 307,36 s. Los cinco fallos corresponden a los corpus de
  privacidad ausentes: proveedores vacíos, lectura fallida, tamaño cero y división
  por cero al calcular sus métricas. No se cambiaron esos archivos ni salvaguardas.
  Se utilizó PHP 8.4.27 de Laragon: la primera invocación con `php` del PATH
  encontró PHP 8.2.12 y Composer rechazó la ejecución antes de iniciar las pruebas.
- `git diff --check` aprobado, con las advertencias LF/CRLF conocidas.

No se ejecutan migraciones ni escrituras de estas comprobaciones sobre bases PostgreSQL reales,
envíos de correo o consultas a proveedores externos. El trabajo local preexistente
se conserva; los fallos ajenos no se corrigen dentro de esta optimización.
