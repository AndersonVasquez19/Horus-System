# Ola 3, módulo 7: Convocatorias y horarios (panel académico)

15/11/2026. Implementa `docs/ola3-diseno-datos.md` §4.1. Sin push. Este documento cubre la **parte A** (convocatorias, horarios y estados)
y, al final, la **parte B** (matrículas que descuentan de la convocatoria).

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
y están **validados en PostgreSQL** (`docs/ola3-postgresql.md`); en SQLite los valida el servicio.

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

# Parte B: matrículas con convocatoria

Migración `000057`: `matriculas.convocatoria_id`, **opcional**, FK `RESTRICT` e índice. Las matrículas existentes y las que se registran sin
convocatoria no cambian: siguen descontando del cupo del curso. En SQLite se añade con `ALTER TABLE … ADD COLUMN` en bruto (como la
`000045`), porque `Schema::table` reconstruiría la tabla y perdería los triggers que emulan los `CHECK`.

## Reglas (`GestionMatriculas`, única implementación)

- **Alta.** `POST /matriculas` acepta `convocatoria` (identificador público, uuid). Debe pertenecer al curso elegido y estar `abierta` o
  `en_curso`; si es así, el cupo se descuenta de la convocatoria y **no** del curso. La validación HTTP lo comprueba y el servicio lo vuelve a
  comprobar bajo bloqueo (carrera: 422 `convocatoria`). Una matrícula que nace `anulada` solo exige que la convocatoria sea del curso.
- **Fija desde el alta.** `PUT /matriculas/{id}` **rechaza** `convocatoria` (422) y, si la matrícula tiene una, tampoco deja cambiar de curso.
- **Anular, eliminar y restaurar** devuelven u ocupan el cupo de la convocatoria (repetir la anulación no lo devuelve dos veces). Restaurar o
  reactivar una `anulada` exige que la convocatoria siga siendo matriculable y tenga cupo.
- **Orden de bloqueo:** cursos → convocatoria → estudiantes → matrícula.
- **Cancelar una convocatoria con matrículas vigentes es 409** (`cupos_maximos − cupos_disponibles > 0`): primero se anulan. `cerrada`
  sí se admite con matrículas, y la anulación posterior sigue devolviendo el cupo.
- **Auditoría.** `convocatoria_id` entra en los valores auditados de la matrícula (id interno, igual que `curso_id`).
- **API.** El resumen y el detalle de la matrícula traen `convocatoria: {identificador, codigo}` o `null`.

## Next

El formulario de registro ofrece «Convocatoria (opcional)» al elegir curso, con las convocatorias `abierta` o `en_curso` con cupos
(`GET academico/convocatorias?curso_id&vigentes=1`; sin permiso `convocatorias.ver` no se ofrece y el cupo es el del curso). En la edición se
muestra fija y de solo lectura. La ficha de la matrícula enlaza a su convocatoria. El BFF acepta `convocatoria` solo en el alta.

## Pruebas (parte B)

`Panel\MatriculasConvocatoriaPanelTest` (9): descuento de la convocatoria y no del curso, sin convocatoria sigue con el curso, convocatoria
ajena, en borrador, cerrada, agotada o inexistente, carrera con cupo agotado bajo bloqueo, anular y eliminar devuelven el cupo una sola vez,
restaurar respeta la disponibilidad, edición sin cambio de curso ni de convocatoria (anular y reactivar por `PUT`), reactivación en una
convocatoria cerrada y cancelación con matrículas vigentes (409). `ConvocatoriasMigracionTest` suma la columna opcional, su `RESTRICT` y el
`down` (este último solo en PostgreSQL; `RevierteCadenaCrm` incluye `000056` y `000057`).

**PostgreSQL: validado** el 15/11/2026 (ver `docs/ola3-postgresql.md`): los `CHECK` de cupos (`0 ≤ disponibles ≤ máximos`), el `RESTRICT` de
`matriculas.convocatoria_id` y la carrera por el último cupo (protocolo 55P03), sin fallos en las pruebas de este módulo.
