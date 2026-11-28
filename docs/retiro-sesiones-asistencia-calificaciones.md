# Retiro de sesiones de clase, asistencia y calificaciones · 27/11/2026

El propietario decidió retirar del panel las sesiones de clase, la asistencia y las calificaciones porque no las usa. **No se borró ningún dato** ni hubo migraciones.

No se confunden con esto, y siguen igual: las sesiones de inicio de sesión (perfil y «cerrar sesiones»), las notas internas del estudiante, el horario semanal de cada edición, la lista de espera, las encuestas y los certificados.

## Qué se retiró

- Pestañas «Sesiones y asistencia» y «Calificaciones y acta» del detalle de la edición, y el botón «Calendario .ics».
- 19 rutas del panel (contrato OpenAPI y lista permitida del BFF), sus 3 controladores, 6 servicios, el `GuardarSesionRequest` y los modelos `SesionClase`, `Asistencia`, `Evaluacion` y `Calificacion`.
- Reportes «Asistencia» y «Calificaciones» (la API responde 422; una dirección antigua en el panel muestra Matrículas).
- En el tablero, la asistencia por edición y las inasistencias reiteradas. En la ficha 360°, las faltas y las notas finales.
- El aviso diario de clases del día.
- La lista de sesiones de la página pública `/edicion/{codigo}` (cambio de zona pública autorizado para esta tarea).

## Reglas que cambian

- **Certificados:** solo los bloquea la deuda. Sigue el interruptor `HORUS_CERTIFICADO_EXIGE_ACTA` (apagado), que ahora significa «la edición debe estar cerrada». Los certificados nuevos guardan asistencia y nota vacías; los emitidos conservan sus valores y su PDF. `HORUS_ASISTENCIA_MINIMA` y `HORUS_NOTA_MINIMA` dejan de usarse.
- **Cierre de edición:** una edición con evaluaciones antiguas ya se puede cerrar con el cambio de estado normal.
- **Preferencia `recordatorio_sesiones`:** la clave se conserva porque controla el aviso de lista de espera; en el perfil se muestra como «Lista de espera con cupo».

## Qué se conserva

- Tablas `sesiones_clase`, `asistencias`, `evaluaciones` y `calificaciones`, las columnas `matriculas.nota_final` y `resultado`, y todas las migraciones. Siguen incluidas en «Exportar datos».
- Las constantes `SESIONES_PERMISSIONS`, `ASISTENCIAS_PERMISSIONS` y `CALIFICACIONES_PERMISSIONS` del seeder, porque migraciones ya aplicadas las leen. Esas migraciones siguen creando las 12 filas de permisos en una instalación nueva; el seeder no las concede a ningún rol y Roles y permisos las oculta (`GestionRoles::PERMISOS_RETIRADOS`). En una base existente, las asignaciones que ya había permanecen sin efecto.
- Las etiquetas de auditoría y el tipo de aprobación `reapertura_acta`: una solicitud antigua sigue visible, pero resolverla ya no reabre ningún acta.
- La columna «Firma / Asistencia» de la lista de aula en PDF, que sirve para firmar en papel.

## Límites de la evidencia

Probado con SQLite en memoria y el servidor de prueba de `apps/web`. Las pruebas de `tests/PostgresIntegrity` se ajustaron y solo se revisó su sintaxis: **no se ejecutaron**. No se consultó la base de producción, así que no se sabe cuántos datos históricos hay.

## Recuperación

El código retirado está en el historial de Git (commit anterior a este cambio). Restituirlo requiere decisión expresa del propietario.
