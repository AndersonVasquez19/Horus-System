# Retiro de «Cobranza» y «Riesgo de deserción» del panel · 27/11/2026

El propietario decidió dejar **Pagos** como única sección del panel para el dinero. Se retiran las fases 3.1 y 3.4 de [`plan-mejora-panel-fase-3-negocio.md`](plan-mejora-panel-fase-3-negocio.md).

## Qué se retiró

- Pantallas, entradas del menú y atajos de Ctrl+K de ambas secciones.
- API: `GET academico/riesgo-desercion`, `GET academico/cobranza/cola` y `GET`/`POST academico/pagos/obligaciones/{obligacion}/seguimientos` (rutas, contrato OpenAPI y lista permitida del BFF).
- `CobranzaController`, `RiesgoController`, `CobranzaAsistida`, `RiesgoDesercion`, `RegistrarSeguimientoCobranzaRequest` y sus pruebas.
- El aviso diario de riesgo de deserción (`recordatorio_riesgo`).

## Qué cambió

- El aviso diario de deuda vencida sigue (tipo `recordatorio_mora`, permiso `pagos.ver`) como «Pagos vencidos: N obligación(es)» y abre `/admin/academico/pagos?estado=vencidas`. La cifra sale de `GestionPagos::resumenVencido()` y excluye matrículas anuladas; el filtro del listado de Pagos no las excluye, así que podrían diferir si hubiera una obligación vigente en una matrícula anulada. Ya no menciona promesas de pago.
- En Reportes, la pestaña y los títulos «Cobranza» pasan a «Pagos». El identificador `tipo=cobranza` y los permisos no cambian.
- `/admin/academico/cobranza` redirige a Pagos filtrado por cuotas vencidas y `/admin/academico/riesgo` al inicio del panel, para no romper avisos ya guardados ni favoritos.

## Qué se conserva

- La tabla `seguimientos_cobranza`, su migración (`2026_11_24_000100`) y el modelo `SeguimientoCobranza` (inmutable). No se borró ningún dato.
- Los contactos ya registrados se siguen viendo, solo lectura, en la ficha 360° del estudiante (permiso `pagos.ver`).
- Las etiquetas de auditoría de esos contactos.
- No hubo migraciones ni cambios de permisos.

## Qué se deja de tener

Registrar contactos y promesas de pago, los tramos de mora, el orden por antigüedad de la deuda y el puntaje de riesgo de deserción (incluida la señal de faltas seguidas; la asistencia sigue en Reportes).

## Recuperación

El código retirado está en el historial de Git (commit anterior a este cambio). Restituirlo requiere decisión expresa del propietario.
