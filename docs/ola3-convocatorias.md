# Ola 3, módulo 7: Convocatorias y horarios (panel académico)

15/11/2026. Implementa `docs/ola3-diseno-datos.md` §4.1. Sin push. Este documento cubre la **parte A** (convocatorias, horarios y estados);
la **parte B** (matrículas que descuentan de la convocatoria) se documenta al final cuando esté hecha.

## API (`/api/panel/v1/academico/convocatorias`)

| Método y ruta | Permiso | Notas |
| --- | --- | --- |
| `GET /convocatorias` | `convocatorias.ver` | Listado `{data, meta}`; filtros `busqueda` (código o nombre), `estado`, `vigentes`, `curso_id`; orden `inicio`, `codigo`, `creado` |
| `GET /convocatorias/{uuid}` | `convocatorias.ver` | Detalle con `horarios`, `cupos` y `ETag` |
| `POST /convocatorias` | `convocatorias.crear` | 201. Nace `borrador` con código `CNV-AAAA-NNNN` y `cupos_disponibles = cupos_maximos`. `curso_id` obligatorio (curso activo) |
| `PUT /convocatorias/{uuid}` | `convocatorias.editar` | `_revision` obligatoria (428/412). El curso no cambia. Una `cerrada` o `cancelada` no se edita (409) |
| `POST /convocatorias/{uuid}/estado` | `convocatorias.editar`; `cerrada` y `cancelada` exigen `convocatorias.cerrar` | Transiciones cerradas (abajo) |
| `PUT /convocatorias/{uuid}/horarios` | `convocatorias.editar` | Sustituye **todos** los bloques de una vez (atómico); `[]` los quita |

Roles por defecto (también con la migración de datos `000055`, que no resincroniza roles): `admin` y `coordinador` todos los permisos;
`secretaria`, solo `convocatorias.ver`.

## Estados (`Convocatoria::TRANSICIONES`)

`borrador → abierta → en_curso → cerrada`; `cancelada` desde `borrador`, `abierta` o `en_curso`. `cerrada` y `cancelada` son terminales (409).
No se abre una convocatoria cuyo `fecha_fin` ya pasó (día de Lima, 422). Se matricula solo en `abierta` o `en_curso`. Repetir el estado
actual no cambia nada. La auditoría usa `convocatoria_creada|abierta|cerrada|cancelada`, `convocatoria_actualizada`,
`convocatoria_estado_cambiado` (el paso a `en_curso`) y `horario_modificado`, siempre con revisión y estados, nunca nombres ni lugares ni docentes.

## Cupos

`cupos_disponibles` arranca igual que el máximo. Al cambiar `cupos_maximos` los disponibles se mueven con él y **nunca quedan por debajo de
lo ocupado** (`ocupados = máximos − disponibles`): bajar el máximo por debajo de lo ocupado es 422. El descuento por matrícula lo hace la
parte B, bajo bloqueo.

## Horarios

Hasta 20 bloques por convocatoria: `dia_semana` 1 (lunes) a 7 (domingo), `hora_inicio` y `hora_fin` (`HH:MM`, fin posterior al inicio),
`lugar` y `docente` opcionales (texto libre hasta que haya un módulo de docentes). **Dos bloques del mismo día no pueden solaparse**; los
extremos que se tocan (10:00 y 10:00) sí se admiten. Los bloques no tienen revisión propia: el reemplazo exige y avanza la de la
convocatoria. `cursos.horario` (texto) sigue siendo la descripción pública.

## Datos

`000055` (permisos) y `000056` (`convocatorias` y `convocatoria_horarios`; sin cascadas: un curso con convocatorias no se elimina y los
horarios no se borran con su convocatoria). El código sale de `secuencias_codigo` (`CodigoSecuencial`, tipo `CNV`). Las modalidades son las
del catálogo de cursos (`presencial`, `semipresencial`, `virtual`, `hibrido`; el diseño proponía `mixta`, se unificó para no tener dos
vocabularios). Los `CHECK` de estado, modalidad, fechas, cupos (`0 ≤ disponibles ≤ máximos`), día y horas **solo se crean en PostgreSQL**
y están **sin ejecutar** hasta nueva autorización; en SQLite los valida el servicio.

## Next

`/admin/academico/convocatorias` (lista con estado, periodo y cupos) y `/[id]` (datos, cupos y horario). «Nueva convocatoria» busca el curso
activo; las acciones cambian según el estado y los permisos (cerrar y cancelar piden confirmación); «Editar horario» abre un editor de
bloques que se guardan juntos. El BFF suma 5 operaciones (más la búsqueda de cursos y el objeto `horarios`). Entrada «Convocatorias» en el
menú. Sin cobertura automática de Next.

## Pruebas

`Panel\ConvocatoriasPanelTest` (15: permisos por rol, cerrar como permiso aparte, alta y auditoría sin textos, códigos secuenciales,
validaciones, revisión 428/412, cupos y su mínimo, ciclo de estados, periodo vencido, repetición, horarios y su reemplazo atómico,
horarios inválidos o solapados, revisión y convocatoria vigente, listado y filtros) y `Panel\ConvocatoriasMigracionTest` (5: permisos por
rol sin resincronizar, forma de las tablas, unicidad y restricciones, `down`).
