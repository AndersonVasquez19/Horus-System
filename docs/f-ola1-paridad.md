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

## Siguiente

Pendiente de decisión del propietario tras el piloto: continuar con cotizaciones → servicios → estudiantes → cursos → matrículas, o pausar la Etapa F aquí. La **Ola 0.3** (acceso `/login`, `/forgot-password`, `/reset-password`) no se ejecutó: mientras existan módulos en Blade, el login Blade es necesario salvo que se demuestre que el login de Next crea una sesión válida también para `/admin` Blade. El enlace del correo de restablecimiento ya apunta a Next (`RestablecerContrasenaWebNotification`, `HORUS_WEB_URL`).
