# Formulario sencillo de publicación de cursos

7 de noviembre de 2026, America/Lima. El propietario solicita simplificar el formulario administrativo de publicación, a raíz de una ficha autorizada cuyo inicio futuro impedía verla en la web.

## Comportamiento

- El formulario muestra primero título, resumen y modalidad. Los contenidos editoriales siguen separados de la información administrativa: no se copian nombres, descripciones ni horarios del curso.
- Horario e inscripción quedan en un desplegable opcional. Las fechas se pueden revisar y cambiar en otro desplegable nativo, sin depender de JavaScript.
- Una ficha nueva propone el día actual en Lima y un vencimiento 30 días después. Es una propuesta visible y editable antes de confirmar: no se publica al abrir el formulario. Las fechas siguen siendo obligatorias en el backend y no existe publicación indefinida.
- Una ficha existente conserva sus fechas y contenido. El panel distingue fichas sin autorización, programadas, vencidas, vigentes y cursos inactivos/eliminados. Las fechas se abren automáticamente si están en el futuro, vencidas o tienen errores de validación.
- Se aclara que las fechas controlan la visibilidad web y no representan las clases. La vigencia incluye el último día completo en Lima.
- Los errores de los campos opcionales abren su desplegable; se conserva la entrada previa escapada mediante `OldInput`. La confirmación se vuelve a solicitar en cada envío.
- Se mantiene `cursos.publicar`, la revisión contra cambios simultáneos, la auditoría, las transacciones y la consulta pública existente. Se ofrece un enlace al catálogo en Educación → Cursos.

## Archivos

- `resources/views/admin/cursos/publicacion.blade.php`: formulario y mensajes de estado.
- `tests/Feature/FormularioPublicacionCursoTest.php`: fechas predeterminadas en el límite UTC/Lima, publicación con los valores del formulario, conservación de fechas/contenido, programación, caducidad inclusiva y recuperación de errores.
- `tests/browser/editor-panel-smoke.mjs`: comprueba los tres campos principales, apertura/cierre de opciones y etiquetas, además del guardado, retirada, conflictos y permisos que ya verificaba.

No hay cambios de esquema, requests, controladores, servicios, predeterminados del chatbot ni configuración del entorno. No se modifican ni republican cursos reales. Las publicaciones existentes mantienen sus fechas: para adelantar una ficha programada hay que editar su inicio y confirmar de nuevo.

## Validación

Las pruebas PHP y de navegador usan exclusivamente SQLite `:memory:`, datos ficticios y HTTP externo bloqueado. El smoke está limitado a `http://127.0.0.1:8771`, con opt-in y reconstrucción de la base por petición; no acredita persistencia entre peticiones, PostgreSQL ni despliegue.

| Comprobación | Resultado |
| --- | --- |
| Bloque PHP: formulario, publicación, modalidades, editor y entrada previa | 123 aprobadas, 1078 aserciones |
| Suite PHP general | 3028 aprobadas, 5 fallos, 1 riesgosa, 1 omitida; 15267 aserciones, 402,25 s |
| `npm test` | 50 aprobadas |
| `npm run build` | Aprobado |
| Smoke Edge del panel en 390, 768 y 1440 px | Aprobado: tres campos principales, dos desplegables, etiquetas, sin desbordamiento; guardado, retirada, conflicto con borrador conservado y rechazo por permiso |
| Pint de la prueba PHP nueva | Aprobado |
| `git diff --check` | Aprobado |

Se revisaron las capturas del editor móvil y de escritorio. El smoke cerró sus
procesos y eliminó su perfil temporal. La evidencia local ignorada está en
`storage/app/private/testing/editor-panel/`.

La suite general no queda aprobada: sus cinco fallos y la prueba riesgosa se
corresponden con los mismos fixtures ausentes ya documentados:
`tests/Fixtures/privacidad/desarrollo.txt`, `errores-compartibles.txt` y
`forense-compartible.json`. Afectan a `FiltroPrivacidadCorpusTest`,
`HorusPrivacidadEstabilizacionTest` y `HorusPrivacidadBloqueoCompletoTest`.
Se conserva la omisión exclusiva de PostgreSQL. No se modifican estos archivos
ni se debilitan las pruebas para ocultar el problema.

La colección general se inició antes de añadir el último caso de ficha retirada
con fechas vencidas; ese caso está aprobado en el bloque final de 123 pruebas.
