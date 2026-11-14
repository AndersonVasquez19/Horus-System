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

## Siguiente

Pendiente de decisión del propietario tras el piloto: continuar con cotizaciones → servicios → estudiantes → cursos → matrículas, o pausar la Etapa F aquí. La **Ola 0.3** (acceso `/login`, `/forgot-password`, `/reset-password`) no se ejecutó: mientras existan módulos en Blade, el login Blade es necesario salvo que se demuestre que el login de Next crea una sesión válida también para `/admin` Blade. El enlace del correo de restablecimiento ya apunta a Next (`RestablecerContrasenaWebNotification`, `HORUS_WEB_URL`).
