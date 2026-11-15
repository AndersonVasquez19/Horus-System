# Ola 3, módulo 6: Reportes comerciales (panel de servicios)

15/11/2026. Implementa `docs/ola3-diseno-datos.md` §3.6. Sin tablas nuevas, sin push, sin PostgreSQL. Cierra el bloque de Servicios.

## API (`/api/panel/v1/servicios/reportes-comerciales`)

| Método y ruta | Permiso | Notas |
| --- | --- | --- |
| `GET /reportes-comerciales` | `reportes-comerciales.ver` | `{data: {periodo, bloques}, meta}`. Filtros `desde`, `hasta` (AAAA-MM-DD) y `responsable_id` |
| `POST /reportes-comerciales/exportar` | `reportes-comerciales.exportar` | XLSX con los mismos filtros (por query). Deja constancia de acceso. `throttle:admin-export` |
| `GET /reportes-comerciales/opciones/responsables` | `reportes-comerciales.ver` | Para el filtro |

Además del permiso, todo exige el rol `admin` y `panel.servicios.acceder`. Toda respuesta con datos lleva `Cache-Control: no-store, private`.
**Cada bloque solo aparece si quien consulta puede ver su módulo** (`prospectos.ver`, `pipeline.ver`, `soporte.ver`, `proyectos.ver`):
tener el reporte no concede ver datos de un módulo sin permiso.

## Periodo

Días completos de **Lima**: `desde` 00:00 y `hasta` 23:59:59 (ambos incluidos) convertidos a UTC para consultar. Por defecto, los últimos
30 días con hoy incluido. Máximo 366 días (422 si se pasa), `hasta ≥ desde` y formato AAAA-MM-DD. El filtro `responsable_id` acota cada
bloque por su campo de responsabilidad (responsable en prospectos, oportunidades y proyectos; asignado en tickets).

## Cifras

| Bloque | Cifras | Base |
| --- | --- | --- |
| Prospectos | creados, por estado, convertidos, **tasa de conversión** | Cohorte: creados en el periodo, por su estado actual |
| Oportunidades | **valor abierto** (total, monto y **monto ponderado** por probabilidad), creadas por etapa, ganadas y perdidas con monto, **tasa de éxito** | Abierto: instantánea de hoy. Ganadas/perdidas: por `cerrada_at` en el periodo. Éxito = ganadas ÷ (ganadas + perdidas) |
| Soporte | creados, por estado y prioridad, resueltos, **tiempo medio de resolución (h)**, **vencidos** | Creados y resueltos por fecha en el periodo. Vencidos: instantánea de hoy |
| Proyectos | por estado (hoy), creados y completados en el periodo, **hitos vencidos** | Hitos vencidos: sin completar, con fecha objetivo anterior a hoy, de proyectos activos |

Las tasas y el tiempo medio son `null` cuando no hay datos (la pantalla dice «Sin datos»). Todos los conteos por estado, prioridad y etapa
salen con las claves completas y cero cuando no hay filas, así la forma de la respuesta y del Excel no cambia.

Pantalla y exportación usan el mismo servicio (`App\Services\ReportesComerciales`), así que **no pueden diferir**; hay una prueba que lo
comprueba leyendo el XLSX.

## Exportación y constancia de acceso

XLSX con filas `Bloque | Indicador | Valor` (las celdas se escriben como texto o número literal, sin interpretar fórmulas). Cada exportación
registra `reporte_comercial_exportado` con quién, cuándo (fecha de la auditoría), IP, periodo, responsable filtrado y bloques incluidos;
**nunca las cifras**. Una exportación que falla (filtros inválidos, error al escribir) no deja constancia. **PDF queda pendiente:** haría falta
una plantilla Blade solo para DomPDF y el plan es retirar Blade; se decidirá con la Ola 4.

## Privacidad

El reporte entrega solo totales, estados y montos agregados: sin nombres, contactos, asuntos, descripciones ni notas. Los montos son
financieros de la empresa, por eso van tras el rol `admin` y el permiso propio.

## Next

`/admin/servicios/reportes-comerciales`: filtros de periodo y responsable, cuatro tarjetas (conversión, valor abierto ponderado, tickets
vencidos, hitos vencidos) y una sección por bloque con su reparto. «Exportar Excel» respeta el periodo en pantalla. El BFF suma 1
operación (la exportación); las lecturas las hace la página de servidor. Entrada «Reportes comerciales» en el menú. Sin cobertura
automática de Next.

## Pruebas

`Panel\ReportesComercialesPanelTest` (14: permisos, ver y exportar separados, bloque oculto sin permiso de su módulo, embudo y conversión del
periodo, valores nulos sin datos, valor ponderado y tasa de éxito, soporte con tiempo medio y vencidos, proyectos e hitos vencidos, periodo de
días completos de Lima, filtro de responsable, validación del periodo, exportación con las mismas cifras y constancia, exportación inválida,
opciones de responsable) y `Panel\ReportesComercialesMigracionTest` (1: permisos).

## Pendiente de este módulo

- **PDF** de los reportes (ver arriba).
- **PostgreSQL:** las consultas agregadas (`whereDate`, sumas y `GROUP BY`) se probaron solo en SQLite; el tiempo medio se calcula en PHP.
- **Next:** sin runner de pruebas; revisar en un navegador con sesión real.
- **Sin gráficos:** solo tarjetas y repartos; si se quieren barras (hay `grafico-barras`), es un añadido de presentación.
- **Defaults aplicados sin consultar:** el valor abierto es de hoy y no del periodo; el periodo por defecto son 30 días; no hay comparación con el periodo anterior.
