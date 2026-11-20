# Sprint 09: publicación segura de cursos y chatbot dinámico

> Estado posterior: por instrucción de prioridad crítica del propietario se restauró la web original. Las inserciones públicas, los cambios de contenido fijo y la sustitución del horario descritos abajo ya no están activos. El backend editorial se conserva, el catálogo dinámico del chatbot está apagado por defecto y las pruebas se adaptaron a esa separación. Este documento registra el cierre anterior; el estado vigente y la evidencia están en `docs/restauracion-web-publica.md`. El frontend queda protegido por `AGENTS.md`.

28 de octubre de 2026. Rama `anderson`. Cambios locales sin commit, push ni despliegue. Se preservan los tres archivos del Sprint 08 que ya estaban modificados al comenzar: `MatriculaController.php`, `MatriculaOperationsTest.php` y `docs/sprint-08-reglas-matriculas.md`.

## Inspección y contrato editorial

Antes de este sprint, `Curso` solo contenía información administrativa; `estado=activo` no representaba una autorización editorial. La página `/educacion/cursos` era estática y anunciaba «+10 cursos disponibles» sin una consulta de catálogo. Las modalidades y horarios administrativos no eran fuentes públicas autorizadas. El chatbot del Sprint 04 usaba conocimiento local y una ruta limitada de OpenAI para orientación comparativa, sin consultar directamente cursos administrativos.

Se incorpora `curso_publicaciones`, una ficha editorial separada por curso. No se copia ningún valor administrativo al abrir el formulario ni al publicar. No hay publicación automática ni conversión de los cursos existentes.

| Campo | Uso |
| --- | --- |
| `titulo`, `resumen` | Textos revisados y autorizados expresamente para el público; obligatorios y acotados |
| `modalidad` | Selección pública explícita: presencial, semipresencial, virtual o híbrido. No se deriva de `Curso.modalidad` |
| `horario`, `inscripcion` | Textos públicos opcionales, independientes de `Curso.horario` y de las matrículas. Si faltan se comunica que no están publicados |
| `vigente_desde`, `vigente_hasta` | Fechas obligatorias, inclusivas, por días completos en `America/Lima`; permiten programar y caducar una ficha |
| `publicada` | Autorización editorial explícita, desactivada por defecto |
| `autorizado_por`, `autorizado_at`, ids y timestamps | Metadatos internos de autorización; no forman parte de la proyección pública |

Una ficha es visible únicamente cuando está autorizada, dentro de su vigencia y vinculada a un curso activo no eliminado. Estar activo es una condición adicional, nunca la autorización para publicar. El término de vigencia no puede estar vencido al publicar y debe ser igual o posterior al inicio.

La proyección pública selecciona exclusivamente título, resumen, modalidad, horario, inscripción y vigencia de la ficha editorial. No selecciona precio, cupos, duración, descripción/nombre administrativos, matrículas, estudiantes, datos personales ni metadatos de autorización. Si aún no existe la tabla nueva, el catálogo devuelve vacío; no expone datos administrativos como sustitución.

## Control administrativo

- Nuevo permiso `cursos.publicar`, concedido por defecto solo a `admin`. No se concede automáticamente a coordinador ni secretaría. Puede delegarse mediante la gestión existente de permisos.
- El seeder declara el permiso para instalaciones nuevas. La migración lo incorpora y lo concede al rol admin existente, sin resincronizar otros permisos ni roles. Su reversión retira únicamente esta ficha/permiso nuevos.
- `GET /admin/cursos/{curso}/publicacion`: formulario editorial desde el detalle de curso, con autorización específica. No precarga datos privados.
- `PUT /admin/cursos/{curso}/publicacion`: revisa campos, consentimiento explícito, vigencia y estado del curso; publica o sustituye la ficha autorizada.
- `DELETE /admin/cursos/{curso}/publicacion`: despublica conservando el texto editorial. Puede retirar fichas incluso si el curso está eliminado lógicamente. Repetir no vuelve a publicar.
- Las escrituras bloquean la fila del curso y se ejecutan en transacción junto con el evento de auditoría `curso_publicado` o `curso_despublicado`. Un fallo revierte ficha y auditoría. La elegibilidad se relee después de la validación HTTP.
- `cursos.editar` no concede publicación. Tampoco un campo adicional enviado al formulario normal de cursos. Las escrituras editoriales usan solo la lista validada y asignan el autor en servidor.

No se incorporan notificaciones ni cambios de correo. No se modifica el modelo administrativo `Curso` ni sus reglas de matrícula.

## Web y chatbot

`CatalogoPublicoCursos::vigentes()` es la consulta común para el controlador público y `AsistenteVirtualService`. La web muestra las fichas actuales y elimina el contador fijo de oferta. Mantiene la explicación estática de modalidades identificada como referencia general, separada de la modalidad confirmada de cada ficha. La página del catálogo responde con `Cache-Control: no-store, private`.

`ChatCursosPublicos` compone respuestas locales a consultas de cursos, modalidades, inscripción/requisitos y horarios oficialmente publicados. Puede reconocer el título público sin exigir la palabra «curso». Prioriza un título completo; si coinciden varias fichas solicita aclaración. Si no encuentra información publicada no intenta obtenerla de las tablas administrativas ni de OpenAI. Los campos opcionales ausentes se comunican expresamente y se remite a `/educacion/cursos` o `/contactos`.

El listado breve muestra hasta cinco cursos; los detalles genéricos de horario/inscripción muestran hasta tres fichas y avisan cuando hay más. El catálogo web contiene el resto. No se consulta historial de conversación libre ni se intenta resolver un curso mediante su nombre administrativo. El contexto conserva solo el tema previo, tal como en el Sprint 04.

Las FAQ restantes mantienen el flujo local, las intenciones desactivadas siguen respetándose y las respuestas de privacidad/ambigüedad se conservan. Una comparación con fichas públicas resoluble localmente no llama a OpenAI. La ruta previa de OpenAI sigue disponible para orientación general cuando corresponde: conserva presupuestos, tiempos de espera, selección de hechos por identificadores y validación de salida. Las fichas editoriales no se añaden al contexto enviado al proveedor. No se publica texto generado libremente.

La versión de la caché de respuestas incluye la proyección pública vigente además de las intenciones activas. Se consulta la visibilidad en cada petición: edición, retirada, inactivación, borrado lógico, inicio y fin de vigencia cambian la versión cuando cambian los hechos públicos. Así una respuesta repetida no conserva una ficha que ya no está vigente, incluso dentro de los 60 segundos de caché. No se promete revocar texto ya mostrado al usuario ni una respuesta que ya estaba en vuelo antes del cambio editorial.

## Horario de atención inconsistente

Fuentes anteriores encontradas:

- `/contactos`: lunes a viernes 9:00–18:00 y sábados 9:00–13:00.
- Componente `schedule-location`: lunes a viernes 9:00–13:00 y 15:00–18:00; sábados 9:00–13:00.
- `/preguntas-frecuentes`: lunes a viernes 8:00–18:00.
- `/politicas-devolucion`: lunes a viernes 9:00–18:00 y sábados 9:00–13:00.

No se encontró una fuente editorial oficial que permitiera decidir. Se sustituyen solamente esas afirmaciones por `ChatKnowledge::HORARIO_ATENCION`: «El horario de atención está pendiente de confirmación oficial. Confirma la atención al +51 927 582 305 antes de acudir». Web y chatbot usan el mismo texto. No se elige ni inventa una franja y no se cambian las demás condiciones de las páginas.

## Pruebas y resultados

Las pruebas fijan SQLite `:memory:` con `phpunit.xml`, `APP_ENV=testing`, `DB_URL` vacío, correo `array` y notificaciones de matrícula deshabilitadas. HTTP no simulado se bloquea en los casos nuevos. Las pruebas previas de OpenAI conservan sus respuestas simuladas; no se realizaron llamadas reales ni envíos de correo.

| Comprobación | Resultado |
| --- | --- |
| PHPUnit: `CursoPublicacionTest`, `AsistenteVirtualTest`, `ChatbotHybridTest` | 90 pruebas, 521 aserciones aprobadas; 4,645 s |
| Suite completa con `phpunit.xml --no-progress` | 702 pruebas, 4231 aserciones aprobadas; 58,192 s. Incluye las 667 previas y 35 casos adicionales |
| Larastan, configuración existente, nivel 5 | Sin errores; cubre `app/Support` y `app/Services`, no equivale a analizar todos los controladores/modelos |
| Pint de los 15 archivos PHP propios del Sprint 09 | Aprobado |
| Pint global | Falla en 127 archivos ajenos al Sprint 09 por formato preexistente; los 15 PHP propios pasan. No se formatean archivos ajenos al sprint |
| `npm test` | 7 pruebas frontend aprobadas |
| `npm run build` | Aprobado con Vite 8.3.5 |
| `node tests/browser/course-publication-smoke.mjs` | Aprobado en Edge con catálogo y chatbot reales de Laravel sobre una fixture SQLite en memoria; ancho 390 px, panel 374 px, objetivo de envío 44 px, foco conservado, formulario etiquetado y consentimiento requerido sin premarcar |
| `git diff --check` | Aprobado; solo advertencias de futura conversión LF/CRLF |

Cobertura nueva: publicación explícita, ausencia de publicación, estados inactivo/archivado, borrado lógico, vigencia futura/vencida y límites de medianoche de Lima; despublicación y revalidación de caché; exclusión de datos administrativos; horario/inscripción ausentes; títulos ambiguos; consultas resueltas sin OpenAI; permisos y delegación explícita; migración de permisos en una instalación ya sembrada; consentimiento/fechas/campos inválidos; cambio de estado después de validar; rollback; HTML escapado; rutas JSON/web y entrada previa manipulada.

El smoke usa `tests/Support/publication-browser-router.php`, habilitado solo con opt-in explícito y servidor loopback. Reconstruye esquema y datos ficticios en SQLite en memoria por petición, carga `.env.example` con variables de prueba explícitas, rechaza configuración cacheada y bloquea HTTP externo. No usa usuarios ni bases reales. Ignora el marcador Vite `public/hot` únicamente dentro de la fixture y usa el build, sin modificar el marcador existente. El perfil de Edge bloquea destinos externos; servidor y navegador se cierran al terminar. Esta fixture no es un servidor de desarrollo ni un entorno persistente de pruebas de publicación.

## Inventario exclusivo del Sprint 09

Backend y rutas:

1. `app/Http/Controllers/Admin/CursoPublicacionController.php` (nuevo).
2. `app/Http/Controllers/CursosPublicosController.php` (nuevo).
3. `app/Http/Requests/PublicarCursoRequest.php` (nuevo).
4. `app/Models/CursoPublicacion.php` (nuevo).
5. `app/Services/CatalogoPublicoCursos.php` (nuevo).
6. `app/Services/AsistenteVirtualService.php`.
7. `app/Support/ChatCursosPublicos.php` (nuevo).
8. `app/Support/ChatIntentMatcher.php`.
9. `app/Support/ChatKnowledge.php`.
10. `database/migrations/2026_10_28_000021_create_curso_publicaciones_table.php` (nuevo).
11. `database/seeders/RolesAndPermissionsSeeder.php`.
12. `routes/web.php`.

Vistas:

13. `resources/views/admin/cursos/publicacion.blade.php` (nuevo).
14. `resources/views/admin/cursos/show.blade.php`.
15. `resources/views/components/schedule-location.blade.php`.
16. `resources/views/pages/contactos.blade.php`.
17. `resources/views/pages/educacion/cursos.blade.php`.
18. `resources/views/pages/legales/politicas-devolucion.blade.php`.
19. `resources/views/pages/legales/preguntas-frecuentes.blade.php`.

Pruebas y documentación:

20. `tests/Feature/CursoPublicacionTest.php` (nuevo).
21. `tests/Feature/AsistenteVirtualTest.php`.
22. `tests/Support/publication-browser-router.php` (nuevo).
23. `tests/browser/course-publication-smoke.mjs` (nuevo).
24. `docs/sprint-09-publicacion-cursos.md` (nuevo).

## Límites y siguiente tarea

La migración existe solo en código y se ha probado exclusivamente en SQLite en memoria. No está aplicada a PostgreSQL, no hay cursos reales publicados y no se validaron concurrencia, bloqueo ni DDL PostgreSQL. S7 continúa suspendido; no se conectó a `horus_group`, ni se cambiaron Resend, credenciales, `.env.example`, stashes, Docentes, Sesiones o Certificados.

La publicación autoriza texto editorial libre y requiere revisión humana: el sistema evita copiar o consultar información administrativa para responder, pero no puede certificar que un operador haya redactado información veraz o que no haya pegado datos indebidos. Los títulos similares o idénticos pueden requerir aclaración o intervención del asesor. No hay flujo de doble aprobación, historial de versiones del contenido ni contexto conversacional persistente por curso.

Al volver a activar/restaurar un curso, una ficha que siga autorizada y vigente puede volver a ser visible. Para retirarla de forma permanente se usa despublicar. La vigencia es de días completos, no de horas o sesiones; no representa inicio de clases, precio ni disponibilidad.

Próxima tarea funcional recomendada: confirmar con el responsable editorial el horario oficial de atención y revisar una primera ficha real de curso (título, modalidad, inscripción, horario y vigencia). Su activación en una base real requiere autorización independiente de la migración; este sprint no la concede ni reanuda S7.
