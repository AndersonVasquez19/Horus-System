# Sprint 17.1 — acceso al editor desde el panel

Fecha: 28 de octubre de 2026. Excepción de frontend limitada al listado de cursos y formulario editorial existentes. La web pública, los catálogos dinámicos y Advisor permanecen desactivados.

## Estado previo y preservación

- Rama `anderson`; HEAD `8fc4174790be7d8df38b8858cb8c9825c4e237ea`, sin cambios de rama, commits, índice o stashes.
- Línea base del Sprint 17: 918 pruebas, 5765 aserciones, verificada en la sesión anterior. La primera ejecución completa de este sprint se solapó con la edición de la vista y terminó con tres fallos por la mezcla temporal entre controlador cargado y Blade nueva; se descarta como medición de línea base. La validación final se ejecuta con los archivos estables.
- Antes de editar: 19 archivos versionados modificados y 12 entradas agrupadas sin seguimiento. Incluyen el backend de Sprints 16–17, pruebas y documentación anteriores, salvaguardas PostgreSQL y todo `chatbot-python/`. No se limpian ni se incorporan al índice.
- Se registraron hashes SHA-256 de 432 archivos: cambios preexistentes y archivos de las zonas protegidas, rutas, pruebas, configuración y Python no ignorado. La comparación posterior identifica exactamente los cambios de esta tarea. Los archivos ignorados de entornos Python no se ejecutan ni modifican.

## Inventario exclusivo

| Archivo | Cambio |
| --- | --- |
| `resources/views/admin/cursos/index.blade.php` | Enlace con icono de publicación junto a las acciones por curso, bajo `@can('cursos.publicar')`. Cabecera local corregida de `top-16` a `top-0` |
| `resources/views/admin/cursos/publicacion.blade.php` | Campo oculto de revisión en guardar y retirar; enlace para cargar versión actual si hay conflicto |
| `app/Http/Controllers/Admin/CursoPublicacionController.php` | Entrega la revisión a la vista, acepta su transporte por formulario y presenta el conflicto nativo conservando el borrador; mantiene If-Match |
| `tests/Feature/EditorPublicacionPanelTest.php` | Pruebas de acceso, revisión, guardado, retiro y preservación del borrador |
| `tests/Support/editor-panel-browser-router.php` | Fixture opt-in, loopback, SQLite en memoria por petición, sin proveedores externos |
| `tests/browser/editor-panel-smoke.mjs` | Edge: permisos, teclado, formularios nativos, capturas y medidas a 390/768/1440 px |
| Este documento | Inventario, evidencia y límites |

Los tres archivos de aplicación modificados son el controlador existente y esas dos vistas. No hay CSS global, JavaScript de aplicación, imágenes, rutas, modelos, requests o migraciones nuevas. Se conserva el trabajo previo del controlador del Sprint 17; se amplía su entrega de revisión y comprobación de precondiciones.

La cabecera `sticky top-16` estaba dentro del contenedor con overflow de la propia tabla, donde desplazaba 64 px la cabecera sobre la fila y ocultaba las acciones. La revisión visual detectó el problema. El cambio local a `top-0` evita cubrir el acceso y conserva la tabla, sus columnas, el scroll horizontal y el diseño del panel. No se modifica ninguna otra cabecera o estilo global.

## Acceso y permisos

En **Panel → Cursos**, cada fila incluye el icono de megáfono, con tooltip «Publicación del curso» y nombre accesible que identifica el código. Usa las mismas clases y tamaño de 36 px de las acciones adyacentes. También aparece en la pestaña de eliminados para poder retirar una ficha; publicar un curso eliminado sigue bloqueado.

Se utiliza exclusivamente `admin.cursos.publicacion.edit` (`GET /admin/cursos/{curso}/publicacion`). No se añade menú global ni segundo editor. La ruta y sus PUT/DELETE conservan sesión, rol del panel y permiso en servidor, además de la relectura de rol/permiso antes de escribir del Sprint 17.

Admin tiene `cursos.publicar` por defecto. Coordinador/secretaría no lo tienen por defecto; pueden acceder solo con concesión explícita y rol válido de panel. Un usuario sin permiso no ve el enlace y recibe 403 si intenta entrar o escribir directamente; un usuario sin autenticar conserva el rechazo existente. `cursos.editar` no otorga publicación.

## Formularios y concurrencia

1. GET entrega el mismo ETag opaco del Sprint 17 y lo pasa como `revision` a la vista.
2. Guardar y retirar envían `_revision` como campo oculto, con el mismo valor fuerte entre comillas. Es el mismo token de estado que `If-Match`, no un formato alternativo de revisión.
3. La comparación se realiza bajo el bloqueo/transacción existentes y después de revalidar autorización. Si se envían cabecera y campo, ambos deben coincidir; no se escoge silenciosamente uno.
4. Un campo presente vacío, nulo, array o malformado es error de validación. No se interpreta como ausencia de precondición.
5. JSON y clientes de cabecera mantienen 412 ante revisión obsoleta. El formulario HTML con campo de revisión redirige al editor con error de sesión, sin escribir.
6. En guardar se informa: «La ficha cambió. Tu texto se conservó y no se guardó. Cópialo antes de cargar la versión actual y revisar de nuevo la publicación». En retirar se explica que no se retiró y debe revisarse la versión actual.
7. Los campos conservan `old()` mediante `OldInput`; el token oculto conserva también **la revisión antigua**, incluso tras otros errores de validación. No se renueva con el ETag de la nueva respuesta ni se reintenta automáticamente.
8. La confirmación editorial queda desmarcada. «Cargar versión actual» realiza un nuevo GET; el usuario debe copiar el borrador que quiera conservar antes de recargar y revisar/autorizar de nuevo.

Las publicaciones sin conflicto siguen las redirecciones existentes. El retiro conserva autor y fecha de autorización históricos, y cambia únicamente la marca pública junto con la auditoría existente. Un retiro obsoleto no altera contenido ni autor. Se mantienen idempotencia, rollback, reglas académicas y ausencia de cambios en matrículas.

Para compatibilidad siguen admitiéndose clientes anteriores que no envían ninguna precondición; los dos formularios del editor ahora siempre la incluyen. Esto detecta conflictos del flujo normal del panel, no convierte la revisión en un permiso ni impide que un cliente autorizado omita deliberadamente la precondición. Los fallos de formato no habilitan ese comportamiento legado.

## Validación visual y funcional

Destino exclusivo del smoke: `http://127.0.0.1:8771`, mediante `tests/Support/editor-panel-browser-router.php`, opt-in `HORUS_EDITOR_BROWSER_FIXTURE=1`. Rechaza configuración cacheada, perfil PostgreSQL, puertos/destinos ajenos y flags públicos/IA/correo activos. Carga configuración de ejemplo con variables aisladas, verifica SQLite `:memory:` antes de migrar su esquema ficticio y bloquea HTTP de Laravel no simulado. No carga `.env` real ni debilita las salvaguardas.

La fixture usa el kernel y rutas reales con usuario ficticio de prueba. Conserva CSRF y cookies de sesión; no se deshabilita middleware para permitir los envíos. El reloj se fija al instante real de arranque de la fixture para que ETags permanezcan deterministas sin caducar cookies artificialmente. No se tocan fechas ni relojes de la aplicación real.

El navegador bloquea destinos externos y usa perfil temporal propio. El servidor y Edge se cierran al terminar. Las cabeceras de evidencia son exclusivas de la fixture y muestran el resultado de cada petición en memoria. No se añaden endpoints a Laravel ni cabeceras a la aplicación real.

| Ancho | Comprobaciones |
| --- | --- |
| 390 px | Sin overflow de página; tabla con scroll horizontal interno; enlace enfocable/visible y sin otro elemento encima; editor en una columna |
| 768 px | Sin overflow de página; scroll interno de tabla; barra lateral compacta; formulario y fechas adaptados |
| 1440 px | Sin overflow de página; tabla completa; navegación y contenedor existentes conservados |

Se comprueban etiquetas, consentimiento requerido sin premarcar, igualdad de revisión en ambos formularios, navegación por Enter y ausencia de excepciones JavaScript. La prueba también verifica guardado y retiro nativos, conflicto con texto/revisión conservados, carga explícita de versión actual y enlace ausente/403 con coordinador sin permiso. Las capturas esperan fuentes y fin de transiciones para no confundir una animación con un defecto visual.

Evidencia local generada e ignorada por Git: `storage/app/private/testing/editor-panel/report.json`, `inventory.json` (hashes anteriores/posteriores y delta), `list-{390,768,1440}.png`, `editor-{390,768,1440}.png` y `conflict-390.png`. Contiene únicamente datos ficticios e inventario de archivos.

**Límite de la fixture:** reconstruye SQLite en memoria en cada petición. Las cabeceras verifican escrituras dentro de esa petición; no demuestran persistencia entre navegaciones. El cambio concurrente se simula inicializando otra versión editorial en la petición siguiente. Las pruebas PHP sí conservan la base en memoria entre peticiones del mismo caso y usan dos administradores ficticios para comprobar la protección. Ninguna de estas pruebas demuestra bloqueo o concurrencia real de PostgreSQL.

## Resultados finales

| Comprobación | Resultado |
| --- | --- |
| PHP específico: `EditorPublicacionPanelTest|Sprint17PublicacionTest|CursoPublicacionTest` | **84 pruebas, 646 aserciones**, aprobadas |
| Suite PHP completa final, después del ajuste local de cabecera | **931 pruebas, 5873 aserciones**, 96,49 s, aprobadas |
| Incremento respecto del cierre del Sprint 17 | **13 pruebas y 108 aserciones nuevas** |
| Larastan, configuración existente nivel 6 | **Sin errores** |
| Pint acotado: controlador y dos PHP de pruebas/fixture propios | **Aprobado** |
| Lint PHP del controlador y router de fixture | **Sin errores de sintaxis** |
| `npm run build` | **Aprobado**, Vite 8.3.5, recursos generados ignorados |
| `npm test` | **7 aprobadas** |
| `node tests/browser/editor-panel-smoke.mjs` | **Aprobado** a 390/768/1440, enlace pulsable sin superposición, guardado/retiro/conflicto nativos y permisos |
| Inspección de capturas | Listado/editor en los tres anchos y conflicto móvil revisados; sin overflow de página. Se esperaron fuentes y transiciones |
| `git diff --check` | **Aprobado**, solo avisos LF/CRLF conocidos |
| Comparación SHA-256 contra inicio | **429 de 432 idénticos**. Solo cambian las dos vistas autorizadas y el controlador editorial |

La comparación del controlador confirma que la lógica previa de publicación, retiro, transacciones, rol y permiso se conserva; las diferencias son la entrega de `revision` a la vista y la ampliación de `comprobarRevision()` descrita arriba. Todos los demás archivos preexistentes registrados, incluidos Sprints 16–17 y Python, permanecen idénticos.

Estado Git posterior: 21 archivos versionados modificados y 16 entradas agrupadas sin seguimiento, frente a 19 y 12 antes. El delta exclusivo es de dos vistas modificadas y cuatro archivos añadidos de prueba/documentación; el controlador ya estaba modificado por Sprint 17 y recibe el cambio adicional registrado. **Inventario propio: siete archivos**, los de la tabla inicial. Índice vacío; rama y HEAD conservados.

El smoke final informó cero excepciones JavaScript y cero solicitudes externas. Las primeras ejecuciones de la fixture devolvieron 419 por el reloj de prueba y la inicialización de sesión previos; se corrigió exclusivamente el arnés, sin desactivar CSRF ni cambiar autenticación de la aplicación. No hay fallos pendientes en los comandos finales ejecutados.

Blade se compila y renderiza mediante GET reales de listado/editor en las pruebas y el smoke. Se prueban las rutas existentes, sin ejecutar `view:cache` global ni modificar rutas públicas. El build solo genera recursos ignorados en `public/build`.

## Pendientes y límites

- El catálogo web, el catálogo del chatbot y Advisor siguen apagados. No se modifica el chatbot PHP ni ningún archivo Python.
- No se escribe en PostgreSQL habitual/ensayo/Testing, no se retoma S7 ni se ejecutan migraciones reales. Tampoco Resend, verificación de correo, commits, push, despliegue o stashes.
- Para mostrar cursos en la web principal hace falta autorización separada: adaptar `CursosPublicosController` para consumir `paraWeb()`, conectar la ruta pública existente respetando su URL/nombre y representar los campos editoriales conservando el diseño. Revisar fichas y disponibilidad de la migración editorial en un entorno autorizado antes de habilitarlo.
- La distribución e invalidación de catálogos hacia Python siguen pendientes del protocolo documentado en Sprints 16–17. Este sprint no activa ni simula esa sincronización.
