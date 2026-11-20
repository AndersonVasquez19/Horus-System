# Comprensión de servicios y seguimiento — 6 de noviembre de 2026

Corrección autorizada a partir de las consultas «que cámaras tienen», «quiero saber sobre las cámaras de seguridad» y la repetición de aclaraciones. Se mejora la selección de información y el seguimiento; no se entrenan nuevos pesos de MiniLM ni se incorpora un generador de texto libre.

## Causas y correcciones

1. Un snapshot firmado vacío con `managed_types=[curso, servicio]` retiraba también las explicaciones públicas de los servicios. `general_information` conserva únicamente la información institucional marcada y verificada en la web como `kind=info`, sin recomendaciones ni atributos comerciales. Los modelos, disponibilidad, precios y fichas vigentes siguen sujetos al catálogo editorial. No se recrean cursos ni ofertas retiradas.
2. «Me interesa» podía imponer un objetivo de aprendizaje a una consulta de servicios. El plan de la consulta actual determina el objetivo; las fichas publicadas pertinentes conservan prioridad. Una pregunta sobre cursos de cámaras no recibe el servicio de videovigilancia como si fuera un curso.
3. Laravel no enviaba historial, por lo que «sí» no resolvía la aclaración anterior. Ahora conserva durante cinco minutos como máximo dos identificadores públicos validados. El estado se autentica con HMAC, se separa por ámbito/sesión y por versión de conocimiento/configuración/catálogo, y se revalida antes de interpretar una referencia. No contiene mensajes, historial ni datos personales.
4. La fuente clara de información institucional no vuelve a pasar por el selector opcional de Gemini. Se conserva el selector para los otros recorridos existentes y se evita que reemplace una respuesta ya determinada.

## Seguimiento y privacidad

Una confirmación sobre una única opción se convierte en una consulta canónica construida con el título público. «Más detalles», «qué incluye» y consultas cortas de atributos retoman el tema. Con dos opciones, «la primera» o «la segunda» seleccionan explícitamente; «sí» no elige arbitrariamente. Una consulta con un tema nuevo se procesa íntegramente. Sin contexto válido o después de su vencimiento, no se infiere un tema anterior.

Las respuestas duplicadas restauran su propio tema mediante metadatos autenticados; así, volver a una consulta de cámaras después de hablar de soporte no deja el tema equivocado. El filtro de privacidad sigue ejecutándose antes de consultar caché o contexto. Python recibe `history: []` y ningún identificador de sesión. Los registros siguen omitiendo el mensaje original. La expiración o retirada de una ficha invalida su uso para seguir la conversación.

## Alcance

Cambios en `AsistenteVirtualService`, `HorusAdvisorClient`, el esquema/cargador/motor Python y `knowledge.json` (`2026-11-06.2`). Nuevas pruebas en `ChatComprensionTest` y `test_comprension_servicios`, con una fixture de respuestas generadas por el motor actual. Se actualiza el contexto de candidatos de la fixture anterior de cierre funcional.

Sin cambios en vistas, JavaScript, CSS, entornos, permisos, rutas, esquemas, publicación real de fichas, activación de catálogos o procesos existentes. El contrato público del widget se conserva. No se almacena historial y no se accede a PostgreSQL real, correo o proveedores de IA para probar.

## Comprobaciones

- Regresión PHP focalizada: 161 pruebas / 835 aserciones aprobadas con SQLite `:memory:` y HTTP simulado; incluye contexto por sesión, vencimiento, cambio de tema con caché, privacidad y selección explícita.
- Python: 324 pruebas aprobadas sin modelo; 26 con MiniLM local, incluidas las dos consultas de las capturas sobre un catálogo vacío. Sin descargas ni proveedores reales.
- HTTP real entre Laravel y FastAPI temporales: seis comprobaciones aprobadas sobre SQLite `:memory:`, caché/sesión/correo `array` y cola `sync`. Incluye ambas consultas, «sí», «qué incluye», separación de sesiones y omisión del texto en registros. Catálogo vacío firmado; proceso temporal cerrado al terminar, sin intervenir en el servicio activo. No es una prueba de navegador ni de producción.
- Frontend: 40 pruebas aprobadas. PHPStan, Ruff, Pint acotado y `git diff --check` aprobados. No se requiere recompilar recursos: el frontend no cambia.

La suite PHP completa terminó con **3011 aprobadas, 15036 aserciones, cinco fallos, una riesgosa y una omitida**. Los cinco fallos y la prueba riesgosa corresponden a los fixtures de privacidad ausentes ya documentados (`desarrollo.txt`, `errores-compartibles.txt`, `forense-compartible.json`); no se relajan controles ni se inventan archivos para ocultarlos. La suite completa no queda aprobada.

Los resultados aislados no acreditan concurrencia/DDL PostgreSQL, proveedores reales o despliegue. El servicio Python activo debe recargar el código y el conocimiento antes de ofrecer este comportamiento. No se detiene ni reemplaza ese proceso.
