# Etapa F — retirada de Blade del panel: matriz de paridad (piloto: reportes)

Fecha: 14/11/2026. Alcance aprobado: Ola 0 + Ola 1 (seis módulos ya cubiertos por Next y por `/api/panel/v1`), empezando por un **piloto con reportes** para medir el coste real antes de seguir. Los 11 módulos que solo existen en Blade (usuarios, roles, aprobaciones, reclamaciones, auditoría, configuración, respaldo, asistente, notificaciones, perfil, búsqueda) se migrarán a Next por olas posteriores, cada una con su plan.

## Regla de la matriz

Una ruta Blade solo se retira si cada comportamiento que probaba una prueba sobre esa ruta tiene su prueba equivalente sobre el endpoint del panel. Las pruebas que solo comprobaban HTML (texto de la vista, botones, enlaces de paginación) se sustituyen por afirmaciones sobre el JSON del panel. Cobertura PostgreSQL: las clases con `DatabaseMigrations` se portan con `DatabaseMigrations`; no se pierde cobertura de exportaciones en PostgreSQL.

## Reportes (primer módulo retirado)

| Ruta Blade retirada | Endpoint del panel | Página Next |
| --- | --- | --- |
| `GET /admin/reportes` (`admin.reportes.index`) | `GET /api/panel/v1/academico/reportes` | `/admin/academico/reportes` |
| `POST /admin/reportes/exportar/excel` | `POST /api/panel/v1/academico/reportes/exportar/excel` | botón de exportación del reporte |
| `POST /admin/reportes/exportar/pdf` | `POST /api/panel/v1/academico/reportes/exportar/pdf` | botón de exportación del reporte |

`GET /admin/reportes` se conserva solo con su nombre (`admin.reportes.index`) y responde 302 a `HORUS_WEB_URL/admin/academico/reportes`, con la misma exigencia de sesión y rol; así los enlaces de la barra lateral y del panel Blade que quedan siguen resolviendo. Las dos exportaciones POST ya no existen en Blade.

Código retirado: `Admin\ReportController`, `admin/reportes/index.blade.php` y `admin/reportes/_alcance.blade.php`. **Se conserva** `admin/reportes/pdf.blade.php` (plantilla del PDF que usa `Api\Panel\Academico\ReporteController`), `ReportesMatriculas`, `MatriculasExcelExport` y `ReportMatriculasRequest`.

| Prueba que tocaba la ruta Blade | Qué probaba | Destino |
| --- | --- | --- |
| `AdminReportConsistencyTest` (8 métodos) | lotes PDF sin solapes, filtros combinados coherentes entre listado/PDF/Excel, eliminados, metadatos de lote, filtros inválidos (6 casos), permisos de exportación (4 casos), lote inexistente | **Portadas** a `Panel\ReportesConsistenciaPanelTest` (`DatabaseMigrations`, 15 pruebas). Las afirmaciones de HTML (campos del formulario, enlaces de paginación, texto «Máximo 50 registros por PDF») pasaron a `meta.filtros`, `meta.lote(s)` y `meta.tamano_lote` del JSON. |
| `AdminReportConsistencyTest::test_student_summary…` | resumen de matrículas en la ficha del estudiante (Blade de estudiantes) | Conservada sin cambios en `AdminEstudianteResumenMatriculasTest`; se portará o retirará con el módulo de estudiantes. |
| `ReportExcelExportTest`, `ReportExcelScalabilityTest` | transacción propia de la exportación, permisos sembrados del coordinador, fórmulas como texto, limpieza del temporal, lotes sin N+1, fallos sin descarga ni auditoría | Misma prueba, llamando al panel mediante `Tests\Support\LlamaReportesDelPanel::exportarReporte()`. |
| `BackendPerformanceTest` (2 métodos) | total filtrado reutilizado por paginación e ingresos; totales en cero y rechazo de lote | Misma prueba sobre el JSON del panel (`resumen`, `meta`). |
| `AdminAuthorizationTest` | reportes en la matriz de páginas Blade; exportaciones sin sesión / sin permiso | Entradas retiradas; la matriz de permisos de exportación vive en `ReportesConsistenciaPanelTest::test_export_endpoints_require_their_own_permission` y `ReportesPanelTest`; el acceso sin sesión de las rutas del panel lo exige `InventarioRutasPanelTest`. |
| (nueva) `AdminModulosRetiradosTest` | la ruta antigua redirige a Next, exige sesión y rol, 404 sin `HORUS_WEB_URL`, y las exportaciones Blade no existen | Cubre todo módulo retirado (se amplía por módulo). |

### Coste real del piloto (para decidir el resto de la Ola 1)

Un módulo pequeño (reportes: 1 controlador de 127 líneas, 2 vistas, 5 archivos de prueba, ~45 pruebas) exigió portar 4 archivos de prueba y crear 3 nuevos, con una sola ruta funcional que sustituir. Los módulos grandes (matrículas ≈ 27 archivos de prueba, cursos ≈ 27, estudiantes ≈ 16) multiplican ese trabajo; la mayoría de sus pruebas verifican reglas de negocio (cupos, bloqueos, auditoría, permisos sensibles) que deben portarse al panel una a una, no borrarse.

## Cotizaciones (segundo módulo retirado)

| Ruta Blade retirada | Endpoint del panel | Página Next |
| --- | --- | --- |
| `GET /admin/cotizaciones` (HTML y JSON) | `GET /api/panel/v1/servicios/cotizaciones` | `/admin/servicios/cotizaciones` |
| `POST /admin/cotizaciones` | `POST /api/panel/v1/servicios/cotizaciones` | alta de solicitud |
| `GET /admin/cotizaciones/{uuid}` y `/historial` | `GET /api/panel/v1/servicios/cotizaciones/{uuid}` (el historial va dentro del detalle, del más reciente al más antiguo, sin `actor_id`: el actor se publica por nombre) | `/admin/servicios/cotizaciones/[id]` |
| `PUT /admin/cotizaciones/{uuid}` | `PUT …/cotizaciones/{uuid}` | edición |
| `PATCH …/estado`, `PATCH …/responsable`, `POST …/seguimientos` | `POST …/estado`, `POST …/responsable`, `POST …/seguimientos` (el verbo de las gestiones pasa a POST) | gestión |

`GET /admin/cotizaciones` se conserva solo con su nombre (`admin.cotizaciones.index`, usado por la barra lateral) y responde 302 a `HORUS_WEB_URL/admin/servicios/cotizaciones`; las demás rutas Blade (JSON) ya no existen. El panel acepta `If-Match` e `Idempotency-Key` como cabeceras y, para el BFF, `_revision` y `_clave` en el cuerpo (`PrecondicionesPanel`).

**Permisos:** idénticos en ambos lados (`role:admin` + `cotizaciones.ver|crear|editar|gestionar`). Diferencias de convención aceptadas: un filtro de listado fuera de contrato se ignora en el panel (en Blade devolvía 422); la paginación del panel usa `page`/`per_page`.

**Brecha de seguridad detectada y cerrada en esta paridad:** el módulo Blade garantizaba que crear, editar o gestionar **no concede lectura** (recibo mínimo `identificador` + `codigo`, también al repetir una creación idempotente), pero el panel devolvía siempre el detalle completo (contacto, necesidad, historial) a quien solo tenía `cotizaciones.crear`/`editar`/`gestionar`. `Api\Panel\Servicios\CotizacionController::respuestaDeEscritura` ahora entrega solo la identidad (con `ETag`) cuando el usuario no tiene `cotizaciones.ver`. Cubierto por `test_creador_sin_lectura_no_recibe_datos_actuales_al_repetir…` y `test_quien_edita_o_gestiona_sin_lectura_recibe_solo_la_identidad`.

Código retirado: `Admin\CotizacionController`, `admin/cotizaciones/index.blade.php` y `_editor.blade.php`, `resources/js/admin-cotizaciones.mjs` (y su entrada en `vite.config.js`), `tests/frontend/admin-cotizaciones.test.mjs`, el smoke `tests/browser/cotizaciones-panel-smoke.mjs` y su router `tests/Support/cotizaciones-panel-browser-router.php`. Se conservan `GestionCotizaciones`, `GuardarCotizacionRequest`, `GestionarCotizacionRequest` y los modelos.

| Prueba que tocaba la ruta Blade | Qué probaba | Destino |
| --- | --- | --- |
| `CotizacionTest` (27 métodos + proveedores) | creación/idempotencia/huellas, validaciones (14 entradas inválidas), `If-Match` estricto (428/412), transiciones, responsables, seguimientos, matriz de permisos por operación (7 casos), revalidación de permisos y rol en la capa de servicio, rollback por fallo de auditoría o de historial, rotación de clave, restricciones SQL, migración de permisos | **Portadas** a `Panel\CotizacionesContratoPanelTest` (49 pruebas con proveedores). Las pruebas de capa de servicio, esquema y migración se copiaron sin cambios de lógica; las HTTP cambian ruta, verbo y forma de respuesta (`data.*`). La paginación del historial (`por_pagina`) ya no existe: el detalle trae hasta 100 eventos. |
| `PanelCotizacionesTest` (8 métodos de HTML) | lista HTML minimizada, filtros, lector sin formularios de escritura, escapado, acciones visibles por permiso, navegación | La parte de **datos y permisos** (lista sin contacto, lector solo lee, `crear`/`servicios.ver` no conceden acceso, exigencia de admin + lectura) pasó a `CotizacionesContratoPanelTest`; la parte puramente de **interfaz** (formularios `data-form`, título, escapado en vista) la cubre ahora la UI de Next y no tiene equivalente de servidor. |
| `ApiPublicaCotizacionesTest` (3 métodos) | las solicitudes públicas se gestionan en la misma bandeja; no hay ruta pública de lectura; sin `gestionar` no se cambia | Portadas a la bandeja del panel (`bandeja()`); el historial se lee del detalle. |
| `CotizacionOrigenMigracionTest`, `RecepcionCotizacionesFlagTest` | origen `administrativo` del alta del panel; inventario de rutas | Portadas al panel; el inventario ahora exige 1 ruta `admin/cotizaciones` (la redirección GET) y 9 del panel. |
| `tests/frontend/admin-cotizaciones.test.mjs`, smoke de navegador | cliente JS del editor Blade | Retirados con el JS. |
| (ampliada) `AdminModulosRetiradosTest` | redirección, sesión/rol, 404 sin `HORUS_WEB_URL`, JSON de cotizaciones inexistente | Añadido `cotizaciones`. |

**Hallazgo de harness:** la sesión del panel tiene vida máxima absoluta (`SesionPanelVigente`): una prueba que avanza el reloj 10 o 180 días debe reabrir la sesión (`reiniciarSesion()`); el control no se relaja.

### Coste real del segundo módulo
`CotizacionTest` (631 líneas) se reescribió casi entera: ~49 pruebas con proveedores, un cambio de producción (recibo mínimo) y 3 archivos de prueba dependientes ajustados. Fue más costoso que reportes (≈ 3×) pese a ser el módulo «pequeño» de la Ola 1; matrículas, cursos y estudiantes serán notablemente mayores.

## Servicios tecnológicos (tercer módulo retirado)

| Ruta Blade retirada | Endpoint del panel | Página Next |
| --- | --- | --- |
| `GET /admin/servicios` (HTML y JSON) | `GET /api/panel/v1/servicios/servicios` | `/admin/servicios/catalogo` |
| `POST /admin/servicios` | `POST …/servicios/servicios` | alta |
| `GET /admin/servicios/{id}` y `/publicacion` | `GET …/servicios/servicios/{id}` (la ficha va dentro del detalle: `data.ficha`) | `/admin/servicios/catalogo/[id]` |
| `PUT /admin/servicios/{id}` | `PUT …/servicios/servicios/{id}` | edición |
| `PATCH …/estado` | `POST …/{id}/estado` | cambio de estado |
| `PUT …/publicacion` | `PUT …/{id}/publicacion` | borrador de la ficha |
| `POST …/publicacion/autorizar` | `POST …/{id}/publicacion/autorizar` | autorización pública |
| `DELETE …/publicacion` | `POST …/{id}/publicacion/retirar` | retiro |
| `POST …/publicacion/imagen` | `POST …/{id}/imagen` (y `…/imagen/quitar`) | imagen principal |
| `POST/POST/DELETE …/elementos[/{id}]` | `POST …/{id}/elementos[/{id}[/eliminar]]` | tarjetas |

`GET /admin/servicios` se conserva solo con su nombre (`admin.servicios.index`, usado por la barra lateral) y responde 302 a `HORUS_WEB_URL/admin/servicios/catalogo`; las demás rutas Blade ya no existen.

**Permisos:** equivalentes. El panel exige además `panel.servicios.acceder` (solo admin por defecto) y cada acción conserva su permiso (`servicios.ver|crear|editar|publicar`; publicar no implica editar contenido y viceversa). Diferencias de convención aceptadas: un filtro de listado fuera de contrato se ignora (en Blade devolvía 422), la paginación usa `page`/`per_page` 1..100 (en Blade `por_pagina` ≤ 50) y la respuesta es `{message?, data}`.

**Seguridad: paridad exacta, sin brecha.** Se comparó la regla «escribir no concede leer» que en cotizaciones sí se perdía: en el catálogo de servicios no existe en ninguno de los dos lados (Blade devolvía el detalle completo a quien creaba o editaba, igual que el panel; son datos de catálogo, no datos personales), así que no hay nada que cerrar. Una mejora menor: el panel nunca publica rutas ni URL de almacenamiento (la imagen se sirve por una ruta autenticada con `nosniff` y `no-store`), mientras Blade devolvía `imagen_ruta` e `imagen_url`.

Código retirado: `Admin\ServicioTecnologicoController`, `admin/servicios/index.blade.php` y `_editor.blade.php`, `resources/js/admin-servicios.mjs` (y su entrada en `vite.config.js`), `tests/frontend/admin-servicios.test.mjs`, el smoke `tests/browser/servicios-panel-smoke.mjs` y su router `tests/Support/servicios-panel-browser-router.php`. Se conservan `GestionServiciosTecnologicos`, los FormRequests, `ImagenesServicios`, `CatalogoPublicoServicios` y los modelos.

| Prueba que tocaba la ruta Blade | Qué probaba | Destino |
| --- | --- | --- |
| `ServicioTecnologicoTest` (22 métodos + proveedor) | CRUD sin publicación implícita, publicación/retiro idempotentes y auditoría, `If-Match` estricto (428/412/422), permisos separados, catálogo público (apagado, exclusiones, paginación, vigencia Lima), rollback por fallo de auditoría, revalidación de permiso tras el bloqueo, restricciones SQL, migración de permisos, normalización del código | **Portada** a `Panel\ServiciosContratoPanelTest` (25 métodos, 33 casos con el proveedor). Las de catálogo público, esquema y migración se copiaron sin cambios de lógica; las HTTP cambian ruta, verbo (`estado` y `retirar` pasan a POST) y forma de respuesta (`data.*`). Se añadió que la revisión también viaja en `_revision` (BFF) y que una cabecera real tiene prioridad. |
| `PanelServiciosTest` (5 métodos de HTML) | HTML y JSON del listado, acciones visibles por permiso, invitado, paginación con texto escapado y sin N+1 | Datos y permisos pasan a `ServiciosContratoPanelTest` (lista vacía, entrada + lectura, editor y publicador independientes, filtros, paginación, 3 consultas, texto literal en JSON). Lo puramente visual (botones `data-form`, «Nuevo servicio») lo cubre ahora la UI de Next. |
| `ServiciosContenidoTest` (tarjetas, imágenes, páginas públicas, semilla) | permisos del contenido, `If-Match`, ficha obligatoria y bloqueo con ficha publicada, validación de 13 campos, límite de 30 tarjetas, archivos huérfanos, imagen segura, reemplazo, imagen compartida/estática, páginas públicas | **Misma prueba**, llamando al panel mediante `Tests\Support\LlamaServiciosDelPanel`; donde se leía `imagen_ruta`/`imagen_url` de la respuesta ahora se lee de la base y se afirma que la respuesta no revela rutas. Retirada solo `test_panel_diferencia_las_tres_categorias` (HTML del listado Blade; `pagina_publica` sigue en el modelo). |
| `ApiPublicaServiciosTest`, `ApiPublicaCotizacionesTest`, `CatalogoAdvisorSincronizacionTest`, `CatalogoWebServiciosTest`, `AuditoriaRobustezPanelFase2Test` | preparaban datos por las rutas Blade | Misma prueba, ahora con el helper del panel. |
| (ampliada) `AdminModulosRetiradosTest` | redirección, sesión/rol, 404 sin `HORUS_WEB_URL` y operaciones Blade inexistentes | Añadido `servicios`. |

**Hallazgo de harness:** `withHeaders()` deja la cabecera `X-Horus-Web-Token` pegada a la instancia de la prueba y contaminaba las llamadas públicas posteriores (que deben seguir dando 401 sin su token). El helper pasa las cabeceras solo por petición.

### Coste real del tercer módulo
Más ligero que cotizaciones: la lógica ya estaba cubierta por `ServiciosEscrituraPanelTest` y `ServiciosImagenesPanelTest`; el trabajo fue portar ~80 pruebas con un helper común y reajustar 5 archivos que preparaban datos por Blade. Sin cambios de código de producción.

## Estudiantes (cuarto módulo retirado)

| Ruta Blade | Qué pasa | Endpoint del panel | Página Next |
| --- | --- | --- | --- |
| `GET /admin/estudiantes` | 302 (se conserva el nombre `admin.estudiantes.index`) | `GET /api/panel/v1/academico/estudiantes` | `/admin/academico/estudiantes` |
| `GET /admin/estudiantes/{id}` | 302 con el id (nombre `admin.estudiantes.show`, lo usa la búsqueda global Blade); id no alfanumérico → 404 | `GET …/estudiantes/{id}` | `/admin/academico/estudiantes/[id]` |
| `GET …/create` y `…/{id}/edit` | **Desaparecen** (404): en Next son diálogos del listado y de la ficha | — | `formulario-estudiante` |
| `POST /admin/estudiantes` | Desaparece | `POST …/estudiantes` | «Nuevo estudiante» |
| `PUT /admin/estudiantes/{id}` | Desaparece | `PUT …/estudiantes/{id}` | edición |
| `DELETE /admin/estudiantes/{id}` | Desaparece | `DELETE …/estudiantes/{id}` | desactivar |
| `PATCH …/{id}/restaurar` | Desaparece | `POST …/{id}/restaurar` (el verbo pasa a POST) | restaurar |

`RedirigirAWebController` ahora admite parámetros en el destino (`/admin/academico/estudiantes/{estudiante}`): solo sustituye valores `[A-Za-z0-9-]` y los codifica; otro valor responde 404, igual que sin `HORUS_WEB_URL`.

**Permisos:** idénticos (`estudiantes.ver|crear|editar|eliminar|restaurar` + `role`/entrada `panel.academico.acceder`). **Diferencias aceptadas:**
- Desactivar con matrículas vigentes: Blade devolvía 422 (JSON) o redirección con error; el panel responde **409** `conflicto` con el mismo mensaje.
- La ficha del panel puede abrir un estudiante eliminado (la lista «eliminados» lo necesita); Blade respondía 404.
- Listado: 20 por página por defecto y `per_page` hasta 100 (Blade, 15 fijas); un filtro fuera de contrato se ignora; los errores de validación son 422 JSON en vez de redirección con errores.
- Restaurar un estudiante no eliminado responde 200 sin auditar (Blade, redirección con éxito).

**Seguridad: sin brecha; el panel es más estricto.** La ficha Blade mostraba matrículas **con monto** a todo el que tuviera `estudiantes.ver`; el panel solo las incluye con `matriculas.ver` y nunca el monto ni las observaciones (eso es del detalle de la matrícula). Revisión de las otras reglas «escribir no concede leer»: `store` devuelve la ficha sin historial ni matrículas, `update` las incluye solo con `matriculas.ver`.

Código retirado: `Admin\EstudianteController`, las 5 vistas `admin/estudiantes/*` y `AdminEstudianteTest`. Se conservan `GestionEstudiantes`, `StoreEstudianteRequest`, `UpdateEstudianteRequest`, `AuditoriaAcademica` y el modelo. Otros ajustes: el enlace «Nuevo estudiante» del dashboard Blade apunta ahora al listado, el layout deja de listar `estudiantes.create|edit` como formularios modales y el smoke `formularios-modal-smoke.mjs` ya no abre las páginas Blade de estudiantes.

| Prueba que tocaba la ruta Blade | Qué probaba | Destino |
| --- | --- | --- |
| `AuditoriaEstudiantesTest` (17) | auditoría de crear/actualizar/eliminar/restaurar: actor, sin datos personales, valores solo para estado, idempotencia, rollback si falla la auditoría, permisos denegados | **Misma prueba**, llamando al panel con `Tests\Support\LlamaEstudiantesDelPanel` (17/17, mismas afirmaciones) |
| `AdminEstudianteTest` (6) | alta y edición, DNI único de 8 dígitos, búsqueda y restaurar eliminados, desactivar es lógico, ficha con matrículas, paginación de 15 | **Retirada**: ya cubierta por `Panel\EstudiantesPanelTest` (listado/búsqueda/orden/paginación, detalle con matrículas solo con permiso, alta con auditoría, validaciones, edición, desactivar y restaurar). Lo exclusivo de la vista (texto «Información personal», monto «S/ 150.00») no tiene equivalente de servidor |
| `AdminAuthorizationTest` | matriz de páginas (index/create), invitado, secretaria sin editar/borrar/restaurar, secretaria sí crea, coordinador no borra | Las dos filas de páginas se retiran (`AdminModulosRetiradosTest` cubre redirección, sesión y rol); invitado, secretaria y coordinador pasan al panel (401/403/201) |
| `AdminListFilterInputTest`, `AuditoriaRobustezPanelFase2Test` | filtros con forma de array no dan 500 | Panel: `test_filtros_con_forma_de_array_o_demasiado_largos_se_ignoran_sin_error` y la afirmación de `meta.filtros` |
| `OldInputRenderingTest` (8 casos + 1 tramo) | un campo enviado como array no rompe el formulario Blade | **Sustituida** por `EstudiantesPanelTest::test_un_campo_con_forma_de_array_se_rechaza_con_422_y_nunca_con_500` (8 casos, alta y edición). El «redibujado» del formulario era solo HTML |
| `BusquedaLikeEscapeTest` (2), `AdminSearchTest` (2) | `%`, `_` y `!` como texto, mayúsculas, parcial por DNI; ausencia de `like` literal | Portadas al JSON del panel (`data.*.apellidos`); la comprobación estática ahora lee el controlador del panel |
| `ArchivadoConMatriculasVigentesTest` | no se desactiva con matrícula vigente; con todas anuladas sí | Panel: 409 sin escribir ni auditar; 200 con anuladas. La parte de cursos sigue en Blade |
| `EstudianteFechaNacimientoLimaTest` (3) | fecha de nacimiento futura según el día de Lima, no UTC | Panel: 422 con `fecha_nacimiento`, mismos instantes |
| `AdminEstudianteResumenMatriculasTest` | tres matrículas, una eliminada excluida; contadores | Panel (`data.matriculas`); se conserva `DatabaseMigrations`. Los contadores los calcula la UI a partir de esa lista |
| `AdminMatriculaContextTest`, `AccesibilidadPanelTest` | enlaces «Nueva matrícula» y `<h1>` de la ficha/formularios de estudiante | Solo HTML de Blade: retirados los tramos de estudiantes; lo cubre la UI de Next. Los de curso siguen |
| `MatriculaRulesPostgresTest::test_searches_are_case_insensitive_on_postgresql` | ILIKE en PostgreSQL | Misma clase y base; ahora llama al panel. **No se ha ejecutado en PostgreSQL** (requiere autorización): solo `php -l` y la ejecución SQLite (omitida por el perfil). Queda pendiente de la próxima pasada PG |
| (ampliada) `AdminModulosRetiradosTest` | redirección, sesión/rol, 404 sin URL | `estudiantes` + ficha con id + operaciones inexistentes |

### Coste real del cuarto módulo
Portar 12 archivos de prueba con un helper y dos scripts; los tests de auditoría pasaron 17/17 sin cambiar una afirmación. Un cambio de código de producción: parámetros en `RedirigirAWebController` (necesario porque otras vistas Blade enlazan a la ficha).

## Cursos y su ficha pública (quinto módulo retirado)

| Ruta Blade | Qué pasa | Endpoint del panel | Página Next |
| --- | --- | --- | --- |
| `GET /admin/cursos` | 302 (se conserva el nombre `admin.cursos.index`) | `GET /api/panel/v1/academico/cursos` | `/admin/academico/cursos` |
| `GET /admin/cursos/{id}` | 302 con el id (nombre `admin.cursos.show`, lo usa la búsqueda global Blade) | `GET …/cursos/{id}` | `/admin/academico/cursos/[id]` |
| `GET …/create` y `…/{id}/edit` | **Desaparecen** (404): en Next son diálogos | — | formulario de curso |
| `POST/PUT/DELETE /admin/cursos[/{id}]` | Desaparecen | `POST/PUT/DELETE …/cursos[/{id}]` | alta, edición, archivar |
| `PATCH …/{id}/restaurar` | Desaparece | `POST …/{id}/restaurar` | restaurar |
| `GET /admin/cursos/{id}/publicacion` (editor, con eliminados) | Desaparece | `GET …/{id}/publicacion` (`data.revision`, `data.ficha`, `data.publicable`, `ETag`) | panel de publicación |
| `PUT …/publicacion` | Desaparece | `PUT …/{id}/publicacion` | guardar y autorizar |
| `DELETE …/publicacion` | Desaparece | `POST …/{id}/publicacion/retirar` | retirar |

**Permisos:** idénticos (`cursos.ver|crear|editar|eliminar|restaurar|publicar`, la ficha con permiso propio). **Diferencias aceptadas:**
- **La revisión de la ficha es obligatoria en el panel.** Blade seguía aceptando clientes sin precondición («compatibilidad con clientes anteriores»); el panel responde 422 `revision` sin `If-Match`/`_revision`, y 412 si está obsoleta. Es más estricto, no más laxo. Las pruebas portadas obtienen la revisión vigente con un helper (`Tests\Support\LlamaCursosDelPanel`) para preparar escenarios, y la precondición se prueba aparte.
- Archivar con matrículas vigentes: 409 en vez de 422.
- El editor del panel nombra el curso (`data.curso`), pero la ficha parte vacía: sigue sin copiar datos administrativos.
- Listado: 20 por página por defecto, `per_page` hasta 100 (Blade, 15); filtro fuera de contrato se ignora.

**Defecto de servidor hallado y corregido en esta paridad (anterior a la migración, también en Blade):** enviar `vigente_desde` como array (`vigente_desde[]=x`) provocaba un **500** (`TypeError` en la regla `after_or_equal:vigente_desde`) en `PublicarCursoRequest`, y lo mismo en `PrepararServicioPublicacionRequest` (ficha del catálogo de servicios, ya migrada). Ahora la comparación con `vigente_desde` solo se aplica cuando es texto; si es un array lo rechaza su propia regla con 422. Pruebas de regresión: `CursoPublicacionTest::test_array_shaped_input_is_rejected…` y `ServiciosContratoPanelTest::test_fechas_de_la_ficha_con_forma_de_array_dan_422_y_nunca_500`. No debilita ninguna validación.

**Seguridad:** sin brecha; el panel es igual o más estricto. El detalle del curso lista estudiantes solo con nombre y estado de la matrícula, sin documento, contacto ni montos.

Código retirado: `Admin\CursoController`, `Admin\CursoPublicacionController`, las 6 vistas `admin/cursos/*`, `AdminCursoTest`, `FormularioPublicacionCursoTest`, el smoke `editor-panel-smoke.mjs` y su router. Se conservan `GestionCursos`, `PublicacionCursos`, los Form Requests y los modelos. Ajustes: el layout deja de listar los formularios de cursos como modales; `course-publication-smoke.mjs` ya no abre el editor Blade; `formularios-modal-smoke.mjs` se adaptó a matrículas (modal de alta en 3 anchos, edición con borrador conservado tras un error de validación) y se quitaron sus pasos de cursos, servicios y cotizaciones; verificado con Edge (pasa).

| Prueba que tocaba la ruta Blade | Qué probaba | Destino |
| --- | --- | --- |
| `AuditoriaCursosTest` (18) | auditoría de crear/actualizar/archivar/restaurar: precio y cupos, derivados, sin datos sensibles, idempotencia, rollback, permisos | **Misma prueba** contra el panel (18/18, mismas afirmaciones) |
| `Sprint17PublicacionTest` (16 + proveedores), `CursoPublicacionTest` (18 + proveedores) | publicación/retiro idempotentes, revisión (412, malformada, otro curso), permisos revocados en pleno request, rollback, proyección pública, catálogo del chat | **Portadas** con el helper; se retira solo el `assertSee` del editor HTML y el «old input» (sustituido por 422 con arrays) |
| `EditorPublicacionPanelTest` (8) | enlace del listado, revisión en los dos formularios, borrador conservado en sesión, retiro obsoleto, revisiones inválidas, cabecera + cuerpo, validación sin renovar revisión | **Reescrita** (9 métodos): permiso propio, guardar y retirar con revisión en el cuerpo (BFF), revisión obsoleta no pisa a otro administrador, retiro obsoleto, revisiones inválidas, sin revisión, cabecera ≠ cuerpo, validación sin renovar revisión. El enlace, los campos ocultos y el borrador en sesión son de la UI de Next |
| `FormularioPublicacionCursoTest` (7) | fechas sugeridas, avisos de ficha programada/vencida, opciones abiertas tras un error, detalles opcionales, último día de vigencia en Lima | **Solo HTML de Blade; no tienen equivalente de servidor.** **Brecha de UX cerrada (14/11/2026):** `panel-publicacion.tsx` sugiere hoy (Lima) y +30 días en una ficha nueva, avisa «programada» (con el día de inicio sugerido), «vencida» y «vigente hasta», y repite el aviso de fechas dentro del formulario. Verificado con `tsc`, ESLint y `next build`; Next no tiene runner de pruebas unitarias |
| `AdminCursoTest` (7) | alta/edición, código/precio/cupos, formularios, cupos mínimos y recalculados, filtros y restaurar, detalle con estudiantes | **Retirada**, cubierta por `Panel\CursosPanelTest` (11): alta, validaciones, edición con cupos, archivar/restaurar, historial, permisos, ficha y, nuevas, listado con filtros/eliminados y detalle con estudiantes sin datos personales |
| `OldInputRenderingTest` (11 → 9) | un campo como array no rompe el formulario | Cursos: sustituida por `CursosPanelTest::test_un_campo_con_forma_de_array_se_rechaza_con_422_y_nunca_con_500` (10 casos, alta y edición). El «redibujado» del formulario era solo HTML |
| `AdminAuthorizationTest`, `ArchivadoConMatriculasVigentesTest`, `AdminSearchTest`, `BusquedaLikeEscapeTest`, `AuditoriaRobustezPanelFase2Test`, `AdminListFilterInputTest`, `BackendRobustnessTest`, `LimitesTextoLargoTest`, `AdvisorCatalogoTest`, `CatalogoAdvisorSincronizacionTest`, `CatalogoWebCursosTest`, `CursosPorModalidadTest` | permisos, 409, búsqueda literal de `%` y `_`, filtros con arrays, límites numéricos y de texto, publicar para el catálogo | Portadas al panel; los tramos de HTML (botones, selectores, «Publicación web») retirados |
| `AdminMatriculaContextTest`, `AccesibilidadPanelTest`, `PaginasErrorTest`, `ConfiguracionEfectivaTest` | usaban páginas Blade de cursos como ejemplo de enlace, título, `<h1>`, 404 o moneda | Enlaces «Nueva matrícula» desde cursos: UI de Next. Las demás cambian de página de ejemplo: usuarios (accesibilidad y 404) y matrículas (título, nombre de la empresa y moneda). **Cuando se retire matrículas hay que volver a mover `ConfiguracionEfectivaTest`** |
| (ampliada) `AdminModulosRetiradosTest` | redirección, ficha con id, operaciones Blade inexistentes | `cursos` |

### Coste real del quinto módulo
El más grande hasta ahora: 20 archivos de prueba tocados, un conversor automático de llamadas (con análisis de paréntesis) para no reescribir a mano y un helper con revisión automática. Un cambio de producción de endurecimiento (los dos Form Requests de fechas).

## Matrículas (sexto módulo retirado)

| Ruta Blade | Qué pasa | Endpoint del panel | Página Next |
| --- | --- | --- | --- |
| `GET /admin/matriculas` | 302 (se conserva el nombre `admin.matriculas.index`) | `GET /api/panel/v1/academico/matriculas` | `/admin/academico/matriculas` |
| `GET /admin/matriculas/{id}` | 302 con el id (nombre `admin.matriculas.show`, lo usa la búsqueda global Blade) | `GET …/matriculas/{id}` (con historial minimizado) | `/admin/academico/matriculas/[id]` |
| `GET …/create` y `…/{id}/edit` | **Desaparecen** (404): en Next son páginas `nueva` y `[id]/editar` | — | formulario de matrícula |
| `POST/PUT/DELETE /admin/matriculas[/{id}]` | Desaparecen | `POST/PUT/DELETE …/matriculas[/{id}]` | alta, edición, eliminar |
| `PATCH …/{id}/anular` y `…/{id}/restaurar` | Desaparecen | `POST …/{id}/anular` y `POST …/{id}/restaurar` | acciones de la ficha |
| `POST /admin/matriculas/exportar/csv` | Desaparece | `POST …/matriculas/exportar` (mismo CSV, BOM, `csvSafe`, auditoría sin el texto buscado, `Cache-Control: no-store`) | botón exportar |
| `GET …/opciones/estudiantes` y `…/opciones/cursos` | Desaparecen | `GET …/matriculas/opciones/{estudiantes,cursos}` (respuesta en `data`, no en `opciones`; mismo límite de 20 y mismo límite de velocidad) | selectores del formulario |

**Reglas de negocio:** no se tocaron. El panel ya escribía por `GestionMatriculas` (transacción, protocolo de bloqueo cursos → estudiantes → matrícula, elegibilidad releída, duplicado vigente y cupo bajo bloqueo, auditoría como última escritura) y usaba los mismos Form Requests y permisos (`matriculas.ver|crear|editar|anular|eliminar|restaurar|exportar`). Las pruebas portadas prueban cada una de esas reglas contra el panel.

**Diferencias aceptadas:**
- Sin sesión el panel responde **401** (Blade redirigía a `/login`); sin permiso, 403.
- Alta: 201; validación: 422 con `errors`; anular y restaurar son POST.
- Listado: 20 por página por defecto, `per_page` hasta 100 (Blade, 15); filtro fuera de contrato se ignora.
- La exportación acepta `curso_id` también como número en un cuerpo JSON (**endurecimiento**: antes un número se ignoraba y el CSV salía sin acotar al curso).
- Crear una matrícula directamente como `anulada` sigue permitido con `matriculas.crear` (como en Blade: no ocupa cupo); solo **editar** hacia `anulada` exige `matriculas.anular`.

**Seguridad:** sin brecha; el panel es igual o más estricto. Las opciones de los selectores siguen exigiendo el permiso de su contexto (crear → `matriculas.crear`, editar → `matriculas.editar`, filtro → `matriculas.ver`) y devuelven solo `id`, `texto` y `eliminado`, sin contacto ni descripciones.

Código retirado: `Admin\MatriculaController` (284 líneas), las 6 plantillas `admin/matriculas/*` (formulario y selector incluidos), `resources/js/selector-asincrono.mjs` y sus 19 pruebas JS, y los pasos de matrículas del smoke de modales. Se conservan `GestionMatriculas`, `OpcionesMatricula`, los Form Requests y los modelos. La constante `ESTADOS_VALIDOS` pasa al controlador del panel.

| Prueba que tocaba la ruta Blade | Qué probaba | Destino |
| --- | --- | --- |
| `AuditoriaMatriculasTest` (60 llamadas), `MatriculaOperationsTest`, `MatriculaNotificationTest`, `MatriculaIntegrityConstraintsTest`, `BackendRobustnessTest`, `LimitesTextoLargoTest`, `SecretsAndExportsTest` | auditoría de cada operación, atomicidad y reversión, notificación de bienvenida, restricciones de integridad, límites de texto, CSV sin secretos | **Misma prueba** contra el panel (mismas afirmaciones; 201/200/422 en vez de redirección/sesión) |
| `AdminMatriculaTest` | cupos al crear/editar/anular/eliminar/restaurar, duplicados, CSV filtrado, listado filtrado, campos como array | **Portada**; se retiran «los formularios se dibujan» y «se redibuja el borrador» (HTML) |
| `AdminMatriculaContextTest` | preselección de estudiante y curso, contexto inválido, borrador | Solo-HTML → UI de Next (que ahora **también preselecciona el curso**, ver abajo). Quedan: registrar con el contexto ocupa un cupo, el personal de solo lectura no puede registrar ni pedir opciones, un contexto eliminado no se acepta |
| `MatriculaOpcionesTest` (41 llamadas) | opciones por contexto y permiso, límite de 20, comodines, arrays, límite de velocidad | Portada (respuesta en `data`); se retiran las 4 pruebas del HTML del combobox y se sustituyen por el detalle con padres eliminados y «las listas completas no viajan» |
| `MatriculaSensitivePermissionsTest`, `AdminAuthorizationTest`, `AuditoriaRobustezPanelFase2Test`, `ExportacionesPostTest`, `ExportacionesPanelRobustezTest` | matriz de permisos, sin sesión, exportación POST y auditada | Portadas; sin sesión → 401; la matriz de roles del listado pasa a una prueba del panel; la exportación del panel tiene su prueba de «solo POST + límite» y de acceso |
| `AdminListFilterInputTest`, `AdminSearchTest`, `BusquedaLikeEscapeTest`, `BackendPerformanceTest`, `HoraLocalPanelTest`, `SelectorMatriculaColumnasTest` | filtros con arrays, búsqueda sin distinguir mayúsculas, curso eliminado filtrable, hora de Lima, selector sin datos de contacto | Portadas a JSON; la hora de Lima se prueba como instante exacto (formatearlo es de Next) |
| `AccesibilidadPanelTest`, `ConfiguracionEfectivaTest`, `PermisosRetiradosTest` | usaban páginas Blade de matrículas como ejemplo | Cambian a usuarios / `ConfiguracionSistema::monto`; la moneda del panel la muestra Next |
| `MatriculaRulesPostgresTest`, `ConcurrencyPostgresTest`, `MatriculaOperacionesConcurrenciaPostgresTest` | reglas y concurrencia **en PostgreSQL** | **Adaptadas al panel y sin ejecutar** (necesitan tu autorización); `php -l` limpio |
| (ampliada) `AdminModulosRetiradosTest` | redirección, ficha con id, operaciones Blade inexistentes | `matriculas` |

**UX en Next (cierre de brechas):** la página `matriculas/nueva` ahora acepta también `?curso_id=` (curso activo, no archivado y con cupos) además de `?estudiante_id=`, y la ficha del curso enlaza «Matricular en este curso»; Blade lo hacía desde la ficha del curso.

### Coste real del sexto módulo
22 archivos de prueba tocados con un conversor automático (el mismo de cursos, ampliado) más 5 pasadas manuales para lo que era HTML, y un helper `LlamaMatriculasDelPanel`. Una mejora de producción (filtro `curso_id` numérico) y una pequeña de Next.

## Dashboard `/admin` y Ola 0.3 (sesión de Next frente a Blade)

**Ola 0.3, resultado (14/11/2026): la sesión de Next NO vale para Blade, ni al revés.** El BFF de Next guarda en el navegador su propia cookie (`horus-sesion`, en producción `__Host-horus-sesion`) y reenvía a Laravel la de sesión (`laravel-session`, `HORUS_SESSION_COOKIE`) solo del lado servidor (`apps/web/src/lib/panel/laravel.ts`, `bff.ts`). Blade lee únicamente la cookie de Laravel. Por tanto iniciar sesión en Next no abre `/admin` Blade y un inicio de sesión Blade tampoco autentica a Next. Se verificó por código y por diseño de cookies; no se cambió ninguna configuración de despliegue (`SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `TRUSTED_PROXIES`).

**Consecuencia para el dashboard:** `/admin` (Blade) es el centro de entrada de lo que sigue en Blade (usuarios, roles, aprobaciones, notificaciones, perfil, configuración, auditoría, respaldo, asistente, reclamaciones) y muestra datos de esos módulos (usuarios y roles, aprobaciones pendientes, notificaciones, actividad de auditoría) que no existen aún en Next. Además `AuthController` lleva tras iniciar sesión a `admin.dashboard`: redirigirlo a Next mandaría a quien entró por Blade a un panel que no reconoce su sesión. **Se mantiene el dashboard Blade hasta cerrar la Ola 2 (módulos compartidos) o hasta unificar el acceso.** Lo académico del dashboard (estudiantes y cursos activos, matrículas del mes, ingresos solo para `admin`, matrículas por mes y últimas matrículas minimizadas) ya lo sirve `Services\Panel\ResumenAcademico` en `/admin/academico`, con los mismos criterios V-03.

## Siguiente

Ola 1 en curso (plan maestro acelerado): retirados reportes, cotizaciones, servicios, estudiantes, cursos y matrículas. El **dashboard `/admin`** y el login Blade se **mantienen** hasta cerrar la Ola 2: la Ola 0.3 demostró que el login de Next no crea una sesión válida para `/admin` (ver la sección anterior). El enlace del correo de restablecimiento ya apunta a Next (`RestablecerContrasenaWebNotification`, `HORUS_WEB_URL`).

Pendientes anotados: las tres pruebas PostgreSQL de matrículas adaptadas al panel (`MatriculaRulesPostgresTest`, `ConcurrencyPostgresTest`, `MatriculaOperacionesConcurrenciaPostgresTest`) **sin ejecutar** (próxima pasada PostgreSQL, con autorización). Resueltos: smoke de modales con Edge y brecha de UX de la ficha pública de cursos.
