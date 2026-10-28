# Restauración de la web pública y protección del frontend

28 de octubre de 2026. Trabajo local en `anderson`, conforme a la instrucción de prioridad crítica del propietario. Sin commit, push, merge ni despliegue.

## Causa demostrada

El servidor existente en `http://127.0.0.1:8000` devolvía HTTP 200, pero el HTML contenía:

```html
<script type="module" src="http://[::1]:5173/@vite/client"></script>
<link rel="stylesheet" href="http://[::1]:5173/resources/css/app.css">
<script type="module" src="http://[::1]:5173/resources/js/app.js"></script>
```

`public/hot` contenía `http://[::1]:5173`. No había proceso escuchando en 5173/5174 y las solicitudes directas al CSS y JS de esa dirección fallaban por imposibilidad de conexión. Vite da prioridad al marcador hot sobre el manifiesto, por lo que el build existente no se utilizaba.

Los recursos compilados ya existían y respondían correctamente antes de modificar nada:

| Recurso en el servidor 8000 | Estado | Tipo | Tamaño |
| --- | --- | --- | --- |
| `/build/assets/app-Dnv32Dyf.css` | 200 | `text/css` | 65725 bytes |
| `/build/assets/app-CEruRNGB.js` | 200 | `application/javascript` | 58626 bytes |
| `/assets/css/educacion/cursos.css` | 200 | `text/css` | 8558 bytes |

Edge reprodujo el fallo: logo de 588 × 425 px, barra y menú con `display:block`, `Alpine=false` y hoja de estilo apuntando al puerto 5173. Se guardó una captura antes de corregirlo. No era una pérdida del CSS fuente ni un error de compilación.

El marcador es un archivo generado no versionado; no se atribuye su creación a un commit. Su fecha observada era 27 de octubre de 2026, 23:40:57. Tampoco se atribuye la ausencia global de estilos a Blade: las modificaciones públicas del Sprint 09 eran un problema adicional de contenido, independiente de la carga fallida de recursos.

## Restauración aplicada

1. Se guardó el marcador y se retiró únicamente el `public/hot` confirmado como obsoleto. El servidor 8000 pasó a entregar referencias a `/build/assets/*`. No se cambió `@vite`, Vite, dependencias, layouts, CSS ni JS fuente.
2. Tras comparar los fragmentos locales, se restauraron únicamente estas seis vistas desde `1c193c7`:
   - `resources/views/admin/cursos/show.blade.php`.
   - `resources/views/components/schedule-location.blade.php`.
   - `resources/views/pages/contactos.blade.php`.
   - `resources/views/pages/educacion/cursos.blade.php`.
   - `resources/views/pages/legales/politicas-devolucion.blade.php`.
   - `resources/views/pages/legales/preguntas-frecuentes.blade.php`.
3. Se recuperó la ruta estática original de `/educacion/cursos`, sin insertar ni cambiar contenido desde un controlador. Se conservan las rutas administrativas editoriales.
4. `ChatKnowledge.php` recuperó exactamente las FAQ del mismo estado Git. Se conserva el chatbot híbrido del Sprint 04. El catálogo editorial dinámico se conserva en código y pruebas, pero se apaga por defecto con `chatbot.public_catalog_enabled=false`. Con el indicador apagado no se consultan fichas editoriales, y su estado forma parte de la versión de caché para evitar reutilizar respuestas de una activación anterior.
5. Se añadió `AGENTS.md` con la regla permanente del propietario: backend exclusivamente, sin cambios directos ni indirectos de frontend/contenido público sin autorización expresa. La excepción de esta restauración no concede autorización futura.

No se revirtieron commits ni se ejecutaron restauraciones masivas. Las modificaciones visuales retiradas del Sprint 09 no estaban comprometidas. La referencia `1c193c7` contiene el chatbot del commit `6fa80eb`; ese commit y sus recursos se conservaron.

Los horarios fijos vuelven a sus valores originales, incluidas sus discrepancias. No se elige un horario oficial ni se sustituyen los textos por un aviso nuevo. El chatbot conserva la respuesta anterior que advierte sobre la discrepancia y recomienda confirmación.

## Verificación real

`node tests/browser/public-restoration-smoke.mjs` usa Edge contra **el servidor existente en 8000**; no levanta una copia de la aplicación. Solo permite GET/HEAD de ese origen, bloquea destinos externos y rechaza peticiones de escritura. No envía mensajes reales al chatbot, formularios ni operaciones administrativas.

Se recorrieron inicio, cursos, capacitaciones, asesoramiento, cableado, cámaras, soporte, quiénes somos, galería, contactos, FAQ y devoluciones en **390, 768 y 1440 px**: 12 páginas, 36 combinaciones. Se verificaron CSS/JS, logo, navegación horizontal y desplegable de Educación en escritorio, menú móvil, apertura/cierre del chatbot y ausencia de desbordamiento horizontal. Se guardaron capturas de inicio y cursos en los tres anchos.

Resultado observado: logo 44 × 44 px, `navDisplay=flex`, `Alpine=true`, menú de escritorio horizontal, recursos locales sin errores HTTP y sin excepciones JavaScript relevantes. Las fuentes y recursos estáticos siguen siendo locales. Los recursos externos bloqueados por la prueba, como mapas, se distinguen de errores de la aplicación; no se presentan como comprobación de esos proveedores.

`git diff --exit-code HEAD -- resources/views resources/css resources/js public vite.config.js package.json package-lock.json` termina en 0: todas las fuentes frontend **versionadas** coinciden con la referencia Git. La vista editorial administrativa nueva del Sprint 09 se conserva como trabajo previo sin alterar, pero su enlace añadido al detalle original fue retirado y no se inserta contenido editorial en páginas públicas.

No existe una captura histórica verificada de la web sana anterior a la regresión. Se demuestra la igualdad del código fuente original y el resultado visual actual; no se afirma una comparación histórica píxel a píxel.

## Backend y pruebas

| Comprobación | Resultado |
| --- | --- |
| Específicas: publicación editorial, asistente, chatbot híbrido y contenido público | 100 pruebas, 595 aserciones aprobadas; SQLite `:memory:` |
| Suite PHP completa con `phpunit.xml --no-progress` | 703 pruebas, 4241 aserciones aprobadas; 79,404 s; SQLite `:memory:` |
| `npm test` | 7 pruebas aprobadas |
| `npm run build` | Aprobado; se regeneran los mismos nombres de assets existentes |
| Larastan | Sin errores en su alcance configurado, `app/Support` y `app/Services` |
| Pint de PHP afectado en esta restauración | Aprobado |
| `git diff --check` | Aprobado; advertencias LF/CRLF sin errores de espacios |
| Edge sobre 8000 | 36 comprobaciones aprobadas, sin modificación de datos de negocio |
| Smoke editorial sobre fixture SQLite en memoria | Aprobado: contenido original intacto, fichas ocultas por defecto, chatbot funcional, editor administrativo conservado y controles accesibles |

Los casos editoriales se prueban con activación explícita **solo dentro del proceso de pruebas**, además de una regresión que verifica el indicador apagado por defecto, la ausencia de consultas a fichas y la conservación del contenido fijo. Activar ese código en la aplicación requiere una autorización posterior; no se habilita para conseguir que una prueba pase.

Las pruebas fijaron `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL` vacío, `MAIL_MAILER=array` y `MATRICULA_MAIL_ENABLED=false`. No se ejecutó ningún comando sobre PostgreSQL ni bases reales. La suite cubre también autenticación, matrículas, cursos, reportes, outbox y permisos; esto no equivale a evidencia PostgreSQL, Resend u OpenAI reales.

Se guardaron y compararon hashes SHA-256 de 15 archivos protegidos: núcleo de matrículas y sus pruebas, informe del Sprint 08, modelo Curso, modelo/controlador/request/catálogo editorial, respuesta dinámica, migración/roles editoriales, servicio/observer/configuración de notificaciones y `.env.example`. Todos permanecen idénticos al inicio de esta tarea. HEAD y la lista de stashes también permanecen idénticos. No se modificaron `.env`, credenciales, Docentes, Sesiones ni Certificados.

## Evidencia local e inventario de esta tarea

En `storage/app/testing/public-restoration/` (generado, no versionado):

- `before-home-1440.png`, `before.json`: defecto reproducido antes de editar.
- `after-home-{390,768,1440}.png`, `after-courses-{390,768,1440}.png`, `after.json`: estado restaurado y recursos observados.
- `hot-before.txt`: contenido del marcador retirado.
- `reversed-fragments.patch`: copia de los fragmentos analizados antes de restaurarlos.
- `protected-before.json`, `preservation.json`: comparación de archivos, HEAD y stashes.

Además de las seis vistas restauradas y el marcador retirado, esta tarea modifica `routes/web.php`, `app/Services/AsistenteVirtualService.php`, `app/Support/ChatKnowledge.php` (restaurado a Git), `config/chatbot.php`, los tests `CursoPublicacionTest.php` y `AsistenteVirtualTest.php`, y `tests/browser/course-publication-smoke.mjs`. Añade `AGENTS.md`, `tests/browser/public-restoration-smoke.mjs` y este informe; el informe del Sprint 09 recibe una nota de estado posterior. `public/build` se regenera mediante el comando solicitado, sin cambios de fuentes.

El resto del árbol sucio corresponde a los avances previos de los Sprints 08 y 09, conservados. No se crea un commit ni se modifica el índice. PostgreSQL S7 continúa suspendido y no se toca `horus_group`, Resend ni los stashes.
