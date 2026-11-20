# Recuperación local del chatbot — 6 de noviembre de 2026

El propietario autorizó reiniciar Python y restablecer la renovación del catálogo, con las escrituras necesarias en la caché y cola del chatbot.

## Diagnóstico y recuperación

La instancia de `127.0.0.1:8001` declaraba `catalog=stale`: el snapshot había vencido a las 20:20 de Lima. Laravel rechazaba ese estado y devolvía la contingencia, incluso para «hola».

La primera inspección no identificó el gestor porque buscaba las entradas operativas del repositorio. La revisión de procesos encontró el gestor externo `operar-chatbot.php`, que seguía despachando renovaciones a su cola exclusiva `horus-ai-chatbot`. Las sincronizaciones estaban fallando; no faltaba el despacho periódico. Se corrigió el diagnóstico antes de intervenir y se reutilizó ese gestor.

Se verificaron en lectura el destino configurado y efectivo `horus_group`, `127.0.0.1:5432`, y su identidad física `7618442163358976784`. Esta identidad corresponde al entorno actual; difiere de la referencia histórica de noviembre 5 en `PROJECT_CONTEXT.md`. Las credenciales de servicio y firma concordaban entre Laravel y Python; no se imprimieron sus valores.

Se detuvo únicamente la instancia Uvicorn identificada por el listener de 8001 y su relación de procesos (PID 33004). Se inició la entrada local existente `iniciar-python.py`, con un proceso, loopback, registro filtrado, MiniLM local, firma obligatoria y la integración Gemini conservada. No se modificaron los archivos de entorno. Laravel y el gestor existente permanecieron activos.

## Comprobaciones

- Python activo con conocimiento `2026-11-06.2`, búsqueda `hybrid`, semántica y catálogo `loaded`; `/api/v1/info` autenticado devolvió HTTP 200.
- Snapshot nuevo confirmado a las 20:43:00 de Lima, con vencimiento cinco minutos después y versión coincidente con la esperada por Laravel.
- Segunda renovación automática confirmada a las **20:45:00**, con vencimiento a las 20:50:00. El servicio continuaba `ready=true`, el trabajador vivo y la cola sin pendientes; el contador de fallidos permaneció en 30, sin nuevos fallos en esos ciclos.
- Cinco consultas sintéticas mediante el cliente y validador reales de Laravel hacia FastAPI: «hola», «que cámaras tienen», «quiero saber sobre las cámaras de seguridad», «si» y «qué incluye». Todas devolvieron `fuente=advisor`; el seguimiento se resolvió con los identificadores públicos del tema. No se crearon registros de chat para estas comprobaciones.
- Las consultas de cámaras devolvieron la descripción pública de tipos, con indicación de confirmar modelos y disponibilidad. «Qué incluye» devolvió los detalles de videovigilancia sin otra aclaración del tema.
- Quince pruebas de operación y nueve de conversación/respuestas breves aprobadas: **24 pruebas, 126 aserciones**, con SQLite en memoria y transporte simulado. La primera invocación con filtro global también descubrió dos proveedores de datos fallidos por fixtures de privacidad ausentes; la selección explícita de los archivos anteriores pasó sin modificar esos controles.
- Hashes de ambos `.env` conservados; `git diff --check` aprobado, con las advertencias LF/CRLF conocidas.

## Operación y límites

Directorio operativo reutilizado: `%LOCALAPPDATA%/HorusAI/chatbot-8001-0d636643e71c434da3d0d9806f9c5fc5`. Contiene `Iniciar-Chatbot.ps1`, `Detener-Chatbot.ps1`, `estado.json` y los registros operativos filtrados. No se instaló un servicio ni inicio automático de Windows.

Los nuevos snapshots siguen leyendo las fuentes editoriales actuales y mantienen su vigencia máxima de cinco minutos. El catálogo real contiene **cero fichas exportables**; las respuestas generales de cámaras proceden de la información institucional verificada. No se publicaron fichas, habilitaron catálogos ni ejecutaron migraciones.

Las escrituras operativas quedan en la caché del chatbot, su cola `horus-ai-chatbot` y el snapshot/caché local de Python. Al recuperar disponibilidad se observaron 30 trabajos de catálogo fallidos, que se conservaron sin reproducir ni eliminar. No se consumieron otras colas, ejecutó el scheduler general, envió correo ni llamó a OpenAI. Estas comprobaciones locales no acreditan un despliegue ni entrega de servicios externos.

Al cierre seguían activos Laravel PID 19304, el gestor PID 29988 y Python PID 33964 (lanzador 22076). Son identificadores de esta comprobación, no valores permanentes. Evidencia auxiliar sin secretos en `%LOCALAPPDATA%/HorusAI/chatbot-recuperacion-20261106-203803`.
