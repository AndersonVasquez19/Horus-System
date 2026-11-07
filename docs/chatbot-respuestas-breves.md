# Respuestas breves y fuentes compartidas — 6 de noviembre de 2026

Cambio autorizado por el propietario tras revisar la propuesta. Se conserva la integración local de Gemini y el trabajo previo del árbol; no se cambian entornos, activaciones, datos persistentes ni publicaciones reales.

## Comportamiento

- La consulta general de convenios enumera las cinco instituciones de las tarjetas del inicio, sin añadir certificados, beneficios, fechas ni contactos.
- Las tarjetas y el asistente comparten `chatbot-python/data/knowledge.json`. Cada convenio aporta `title`, `description` y `public_card`; `ConveniosPublicos` los proyecta para el inicio. Se preservan exactamente nombres, orden, textos, fechas, imágenes, logos y posiciones anteriores. La ficha de La Libertad tiene una identidad separada de Cajamarca. ISAM conserva la abstención sobre sus detalles pendientes de confirmación.
- `answer` contiene la respuesta breve; `detail_answer` conserva la ampliación autorizada. Una pregunta por una característica selecciona una frase literal que la contiene; una petición explícita de detalles permite ampliar. La lista de convenios se calcula desde las tarjetas al cargar el conocimiento y Gemini no la sustituye.
- Los cursos y servicios reutilizan el catálogo firmado existente. El resumen omite atributos y metadatos no pedidos; modalidad, duración, horario y requisitos se responden cuando se solicitan. Los detalles se recuperan de los fragmentos publicados.
- Laravel registra las variantes literales y las vincula con sus fuentes. Se rechaza una respuesta institucional conocida si cita otra fuente. Se mantienen los controles de firma, versión, vigencia y permisos editoriales.

Crear un curso administrativo no publica una ficha. No se activa `chatbot.public_catalog_enabled` ni `horus-catalog.sync_enabled`; tampoco se modifica la configuración web. Los cambios de cursos/servicios solo llegan a Python mediante la sincronización existente cuando está configurada y operativa. Los convenios son contenido versionado, sin un editor nuevo en el panel.

## Archivos y validación

Producción: `resources/views/pages/home.blade.php`, `app/Services/ConveniosPublicos.php`, `HorusAdvisorClient.php`, `FuentesCatalogoAdvisor.php`, el conocimiento JSON y los módulos Python `schemas/knowledge`, `services/knowledge`, `chat_engine`, `catalog`, `understanding` y `query_plan`. La palabra de consulta `realizan` se conserva en el vocabulario para que la corrección ortográfica no la convierta en un tema al incorporar las descripciones de convenios.

Regresiones nuevas: `tests/Feature/ChatRespuestasBrevesTest.php`, `chatbot-python/tests/test_respuestas_breves.py` y tres fixtures públicas/sintéticas. Comprueban paridad con las tarjetas originales, brevedad, atributos concretos, Gemini simulado, abstención de ISAM, privacidad de los detalles y transporte Python → fixture → validación Laravel. Las fixtures de contratos anteriores se actualizan únicamente para las respuestas/fuentes que cambiaron; los nuevos resultados de esas entradas proceden del motor léxico actual, sin atribuirlos al modelo semántico histórico.

Validación aislada: SQLite `:memory:`, sesión/caché/correo `array`, cola `sync`, HTTP simulado; Python bloquea conexiones externas. Las pruebas semánticas usan MiniLM local, sin descargas. También se ejecutan frontend, compilación Vite, PHPStan, Ruff, Pint acotado y `git diff --check`. Los resultados y las limitaciones de la suite completa se informan en la entrega.

Resultados finales:

| Comprobación | Resultado |
| --- | --- |
| Regresión PHP focalizada de respuestas, fuentes y observaciones (`phpunit`, PHP 8.4.27) | 481 aprobadas, 782 aserciones |
| Suite PHP completa (`artisan test --compact`, PHP 8.4.27) | 3006 aprobadas, 15007 aserciones; 5 fallos, una riesgosa y una omitida |
| Python sin modelo (`pytest -q`) | 313 aprobadas |
| MiniLM local (`pytest -m semantic -q`) | 24 aprobadas |
| Frontend (`npm test`) | 40 aprobadas |
| Vite (`npm run build`), PHPStan, Ruff, Pint acotado y `git diff --check` | Aprobados |

Los cinco fallos PHP y la prueba riesgosa proceden de los fixtures de privacidad ausentes ya documentados: `desarrollo.txt`, `errores-compartibles.txt` y `forense-compartible.json`. Afectan los proveedores y las métricas del corpus; no se inventan archivos ni se relajan controles para ocultarlos. La suite completa no queda aprobada. No se ejecuta un smoke contra la aplicación/base principal; la paridad de los datos del inicio se comprueba con su fixture original y el render de Laravel aislado.

No se acreditan PostgreSQL, concurrencia, proveedores reales ni despliegue. No se reinicia ni reemplaza el servicio Python que estaba activo al comenzar: ese proceso debe recargar el código y el conocimiento antes de servir el nuevo comportamiento. No se ejecutan migraciones, seeders, publicación de fichas, correo real, llamadas a Google/OpenAI, commits ni push.
