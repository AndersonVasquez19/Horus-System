# Plan de mejora del panel · Fase 2 (rendimiento) · 23/11/2026

> Alcance de la evidencia: todo se midió con **SQLite en memoria**. Cuenta consultas y comprueba exactitud; **no mide el plan de ejecución ni los tiempos de PostgreSQL**. La migración de índices no se aplicó a ninguna base real.

## Qué se hizo

| Ítem | Resultado | Dónde |
| --- | --- | --- |
| **2.1 Tablero** | Las sumas de dinero se calculan en SQL con céntimos enteros (`CAST(ROUND(col * 100) AS BIGINT)`), sin traer filas a PHP ni coma flotante. `cobradoDelMes()` se calcula una vez por petición (antes, dos). El promedio de asistencia de las ediciones en curso usa tres consultas en total (antes, varias por edición). Consultas por carga del tablero: **36 → 30** con la caché apagada (unas 10 son de sesión, usuario y permisos), y **no crecen** con el número de ediciones. | [`ResumenAcademico`](../apps/api/app/Services/Panel/ResumenAcademico.php), [`GestionAsistencias::promediosPorEdicion`](../apps/api/app/Services/GestionAsistencias.php) |
| **2.1 Caché** | Los bloques costosos (dinero, cuotas, asistencia, inasistencias, matrículas por mes) se guardan `PANEL_RESUMEN_CACHE_SEGUNDOS` segundos (30 por defecto; 0 la apaga). La clave lleva una **versión** que cambia en cada guardado, borrado o restauración de Matrícula, TransaccionPago, ObligacionPago, CuotaObligacion, Convocatoria, SesionClase y Asistencia (tras confirmar la transacción) y el día de Lima. Solo se guardan bloques sin datos por usuario. | [`AppServiceProvider::boot`](../apps/api/app/Providers/AppServiceProvider.php), [`config/panel.php`](../apps/api/config/panel.php) |
| **2.3 Reportes** | Asistencia y calificaciones dejan de consultar por cada edición. Cobranza ya cargaba las transacciones por adelantado: no se tocó. | [`ReportesMatriculas`](../apps/api/app/Services/ReportesMatriculas.php) |
| **2.4 Índices** | Migración `2026_11_23_000098`: `transacciones_pago (estado, fecha_pago)`, `cuotas_obligacion (vence)`, `matriculas (estado, fecha_matricula)`. Aditiva, idempotente y reversible. | [migración](../apps/api/database/migrations/2026_11_23_000098_add_dashboard_performance_indexes.php) |

Pruebas: `TableroRendimientoTest` (cifras calculadas a mano que **no cambian** antes y después, tope de consultas, sin N+1, caché e invalidación) e `IndicesRendimientoTest` (instalación nueva, idempotencia y reversibilidad).

## Límites de la caché (para no sorprenderse)

- Un cambio que **no pasa por Eloquent** (`DB::table(...)->insert/update`, `Modelo::query()->update(...)`) no cambia la versión: el tablero puede mostrar el dato anterior hasta `PANEL_RESUMEN_CACHE_SEGUNDOS`. Los flujos normales del panel guardan modelos y sí invalidan.
- Con el driver de caché `database` (el de `.env.example`), cada lectura de caché también es una consulta; el ahorro es real pero menor que con Redis.
- Las cifras de notificaciones sin leer y la auditoría reciente **no** se cachean.

## Lo que no se hizo, y por qué

- **2.2 Tabla de agregados diarios (`resumen_diario`):** pospuesta. Con las sumas en SQL, los índices y la caché, el tablero actual ya no la necesita; tendría sentido con el tablero ejecutivo de la Fase 3 (series históricas por mes), que es cuando se justifica una migración, un job nocturno y una carga inicial.
- **2.3 Paginación de cobranza en SQL:** el reporte calcula todo y el controlador corta por lotes de 50. No se cambió el contrato con la interfaz. Con muchos miles de obligaciones habría que paginar en SQL.
- **`inasistenciasReiteradas()`** (tablero) lee todas las asistencias de las ediciones en curso para calcular rachas; está cacheada pero no optimizada. Con mucho volumen conviene limitarla a las últimas N sesiones por edición, lo que cambia el máximo de faltas seguidas que se muestra.
- **2.5 Emisión de certificados en lote como job:** pospuesta a la Fase 3. Hoy es idempotente (repetir reutiliza lo emitido) y el tamaño lo acotan los cupos de la edición. Cambia con la regla de elegibilidad con deuda, y ahí conviene rehacerla.
- **Búsqueda con `ILIKE %x%`:** sin tocar; `pg_trgm` solo con una prueba de volumen que lo justifique.

## Cómo comprobarlo

- `php artisan test --filter='TableroRendimientoTest|TableroTest|IndicesRendimientoTest'`.
- Desactivar la caché: `PANEL_RESUMEN_CACHE_SEGUNDOS=0`.
- Aplicar los índices en una base real exige autorización expresa; en PostgreSQL con mucho volumen, `CREATE INDEX` bloquea las escrituras de esa tabla mientras se construye.
