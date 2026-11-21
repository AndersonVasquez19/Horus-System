# Visibilidad de Tecnologías

20 de noviembre de 2026, America/Lima. El propietario solicita ocultar la sección Tecnologías en la página, el chatbot y el panel de control.

`config/tecnologias.php` define `visible=false`. La misma opción controla:

- Menús públicos de escritorio y móvil, diapositiva tecnológica del inicio, metadatos del inicio y asunto Tecnologías del contacto.
- Descripción y sugerencias del widget público; accesos a Servicios tecnológicos y Solicitudes de cotización en el menú administrativo.
- Respuestas de `AsistenteVirtualService`, compartido por el chat público y el probador administrativo. El contenido tecnológico, las recomendaciones de servicios y su foco conversacional se sustituyen por orientación educativa, antes de almacenarlos en caché o registrar la respuesta. Se conserva el filtro de privacidad previo. La opción y la política forman parte de la versión de caché y del foco.
- Historial de la pestaña: el modo de visibilidad se conserva junto al historial. Un historial de otro modo o anterior a esta política se descarta; el historial nuevo sigue funcionando entre páginas. La acción de cotización tampoco se inicia desde el cliente cuando la sección está oculta.

Es una ocultación reversible. Las rutas públicas tecnológicas y las rutas administrativas siguen existiendo y conservan sus controles de acceso; se pueden abrir directamente. No se eliminan registros, permisos, fichas ni imágenes. El conocimiento interno de Python se conserva; la política se aplica en Laravel a los dos canales de chat de la aplicación. No se modifica el catálogo dinámico del asistente ni se publica contenido editorial.

Las pruebas anteriores que necesitan respuestas tecnológicas o sus enlaces activan `config(['tecnologias.visible' => true])` únicamente dentro de ese escenario. Esto permite conservar sus comprobaciones de privacidad, validación, permisos y seguimiento. Las nuevas pruebas comprueban el valor predeterminado oculto y la reversibilidad de la opción.

No se modifican entornos, no se ejecutan escrituras sobre bases reales ni se consulta a proveedores externos para validar. El smoke `tests/browser/tecnologias-smoke.mjs` exige la fixture aislada de su cabecera en `127.0.0.1:8765`, con SQLite en memoria y transporte Python simulado. No debe ejecutarse contra una instancia normal de la aplicación.

## Archivos principales

| Área | Archivos |
| --- | --- |
| Opción compartida | `config/tecnologias.php` |
| Web | `resources/views/components/navbar.blade.php`, `resources/views/components/carousel.blade.php`, `resources/views/pages/home.blade.php`, `resources/views/pages/contactos.blade.php` |
| Chat | `app/Support/VisibilidadTecnologias.php`, `app/Services/AsistenteVirtualService.php`, `app/Services/HorusAdvisorClient.php`, `resources/js/chat-client.js`, `resources/views/components/chat/conversation.blade.php`, `resources/views/components/public/chat-widget.blade.php` |
| Panel | `resources/views/admin/partials/sidebar.blade.php` |
| Pruebas nuevas | `tests/Feature/VisibilidadTecnologiasTest.php`, `tests/browser/tecnologias-smoke.mjs`; regresión adicional en `tests/frontend/chat-client.test.mjs` |

## Validación local

| Comprobación | Resultado |
| --- | --- |
| Pruebas nuevas de visibilidad, incluida caché con cliente real y HTTP simulado | 7 aprobadas, 60 aserciones |
| Regresión de web, panel, comprensión y estado | 54 aprobadas, 465 aserciones |
| Regresión adicional de contratos, privacidad y catálogo | 648 aprobadas, 1672 aserciones |
| `npm test` | 66 aprobadas |
| `npm run build` | Aprobado |
| `chat-smoke.mjs --history` | Aprobado: navegación, recarga e historial en escritorio y móvil |
| `tecnologias-smoke.mjs` | Aprobado: navegación, carrusel, sugerencias, respuesta y ausencia de tarjetas tecnológicas en Edge a 1440 y 390 px; sin desbordamiento horizontal |
| PHPStan y Pint sobre archivos PHP editados | Aprobados |
| `git diff --check` | Aprobado; avisos LF/CRLF conocidos |
| Suite completa PHP | 3083 aprobadas, 5 fallos, 1 riesgosa y 1 omitida; 15711 aserciones |

La suite completa no queda aprobada: sus cinco fallos y la prueba riesgosa corresponden a los fixtures de privacidad ausentes `desarrollo.txt`, `errores-compartibles.txt` y `forense-compartible.json`, ya documentados en `docs/retiro-procesos.md` y `docs/chatbot-comprension-contexto.md`. No se modifican esas pruebas ni se inventa el corpus. La última comprobación focalizada añade la prueba de deduplicación de respuestas ocultas con el cliente real de Laravel y transporte simulado.

PHP 8.4.27 se selecciona únicamente en los comandos de comprobación. Las pruebas usan SQLite `:memory:`, sesión/caché/correo `array` y cola `sync`; no acreditan PostgreSQL, concurrencia, Python operativo, proveedores reales ni despliegue. Los smokes usan exclusivamente el servidor temporal de la fixture, que queda detenido al terminar. Los recursos generados de `public/build` permanecen sin versionar y se conserva el trabajo local previo.
