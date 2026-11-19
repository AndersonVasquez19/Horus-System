# Retiro de Docentes e Infraestructura (19/11/2026)

## Decisión
El panel es de **gestión académica**, no un portal tipo Blackboard para estudiantes ni docentes. Cuando una persona se matricula,
se la agrega a un grupo de WhatsApp según el curso; no hace falta registrar docentes, sedes, aulas, plataformas ni enlaces de clase.

## Qué se retiró
- **Módulos del panel:** Docentes e Infraestructura (sedes, aulas, plataformas virtuales), con sus rutas `/api/panel/v1/academico/...`,
  controladores, servicios, requests, modelos y páginas de `apps/web`.
- **En ediciones:** asignación de docentes, asignación de infraestructura, la exigencia de infraestructura para abrir una edición, la
  regla «cupos ≤ capacidad del aula» y los campos de texto `lugar` y `docente` de cada bloque de horario.
- **En clases (sesiones):** docente, aula, plataforma, enlace virtual, los cruces de aula y de docente y la consulta del enlace.
  Una clase conserva **fecha, horas, tema, número, estado y motivo de cancelación**; la asistencia y las notas no dependían del docente.
- **Permisos:** `docentes.ver|crear|editar|eliminar|restaurar`, `infraestructura.ver|gestionar` y `sesiones.ver-enlace`.
- **Regla de DNI:** ya no existe «un DNI no puede ser estudiante y docente a la vez» (`DniUnico`); el DNI de estudiante sigue siendo único.

## Migraciones
| Migración | Qué hace |
| --- | --- |
| `2026_11_19_000084_retire_docentes_infraestructura_permissions` | Borra los 8 permisos (Spatie elimina las asignaciones) y olvida la caché de permisos. |
| `2026_11_19_000085_drop_docentes_infraestructura` | Se **niega a actuar** si alguna tabla o columna tiene datos. En PostgreSQL suelta las restricciones CHECK y la clave compuesta, luego las columnas y las tablas (`convocatoria_docentes`, `docentes`, `aulas`, `plataformas_virtuales`, `sedes`). En SQLite (solo pruebas) reconstruye `sesiones_clase`. `down()` no restaura nada. |

Las migraciones antiguas (`000062`–`000069`) se conservan como historial; las de permisos llevan ahora su propia lista de permisos
porque el seeder ya no los define. Antes de aplicar a `horus_group`: respaldo `pg_dump`, comprobar `DB_DATABASE`/host/puerto y que las
tablas estén vacías (el 19/11/2026 estaban vacías).

## Qué se conserva
Auditoría histórica: `audit_logs` puede tener acciones `docente_*`, `sede_*`, `aula_*`, `plataforma_*` y `sesion_enlace_consultado`;
`PresentadorAuditoria` conserva sus etiquetas para mostrarlas. El chatbot habla de la «sede» de la empresa (texto institucional) y no cambia.
