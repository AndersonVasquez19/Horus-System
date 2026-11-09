# Formularios administrativos en modal

7 de noviembre de 2026, America/Lima. Cambio visual solicitado por el propietario.

Los formularios de estudiantes, cursos, matrículas y usuarios (alta y edición),
publicación de cursos, configuración, roles, aprobaciones, servicios y cotizaciones
utilizan ventanas centradas con fondo sombreado, encabezado fijo y desplazamiento
interno. Búsquedas, filtros, exportaciones directas y conversación de prueba
conservan su presentación de consulta. Las confirmaciones existentes ya eran
modales y se mantienen.

Los enlaces de alta, edición y publicación abren el formulario sobre la pantalla
actual mediante un GET del mismo origen. La URL cambia a la del formulario; los
envíos siguen siendo nativos y Laravel conserva CSRF, validación, entrada previa,
permisos y redirecciones. Cerrar devuelve la URL, los filtros y el foco originales;
Atrás cierra y Adelante carga la página completa del formulario. El acceso directo
también abre el modal. Sin JavaScript los formularios Blade permanecen visibles.

Se utiliza `dialog.showModal()` para restringir el foco y la interacción al modal.
Escape, el botón de cierre y un clic en el fondo permiten salir. Los editores de
servicios y cotizaciones conservan su estado en memoria al cerrar y bloquean el
cierre mientras una petición está en curso. Sus errores y estados se muestran
dentro del diálogo. Los formularios de roles y de decisiones de aprobación se
reabren ante errores sin mezclar la entrada previa de otros formularios.

Archivos principales:

- `resources/views/admin/layouts/app.blade.php`: integración de páginas de formulario.
- `resources/views/components/admin/form-modal.blade.php`: componente para formularios internos.
- `resources/js/admin-modals.mjs`, `resources/js/app.js` y `resources/css/app.css`: interacción y presentación comunes.
- Vistas de roles, aprobaciones y editores de servicios/cotizaciones; sus dos módulos JavaScript conservan el flujo de escritura existente.

No se modifica el contenido local pendiente de `cursos/publicacion.blade.php`,
el chat, su documentación ni sus pruebas. No hay cambios de controladores,
requests, permisos, esquema, configuración del entorno ni datos reales.

Validación:

- Bloque PHP relacionado: 229 aprobadas, 1266 aserciones; SQLite `:memory:`.
- Suite PHP completa: 3029 aprobadas, cinco fallos, una riesgosa y una omitida;
  15275 aserciones. Los fallos siguen vinculados a los fixtures de privacidad
  ausentes `desarrollo.txt`, `errores-compartibles.txt` y `forense-compartible.json`,
  ya documentados en `docs/cursos-formulario-sencillo.md`.
- JavaScript: 52 pruebas aprobadas. Compilación Vite, Pint del router de prueba
  y `git diff --check` aprobados.
- El smoke `tests/browser/formularios-modal-smoke.mjs` usa exclusivamente
  `http://127.0.0.1:8778` con opt-in y el router
  `tests/Support/formularios-modal-browser-router.php`. Reconstruye SQLite en
  memoria por petición y bloquea HTTP externo. Comprueba navegación, foco,
  retorno de errores, envío nativo y presentación en 390, 768 y 1440 px.

Los datos del navegador son ficticios y no persisten entre peticiones. Esta
evidencia no acredita PostgreSQL, concurrencia, correo real, IA ni despliegue.
Las capturas y el informe local se guardan en la carpeta ignorada
`storage/app/private/testing/formularios-modal/`.
