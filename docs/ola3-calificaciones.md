# Ola 3, módulo 8: Calificaciones (panel académico)

15/11/2026. Implementa `docs/ola3-diseno-datos.md` §4.2. Sin push. Depende de Convocatorias (módulo 7).

## API (`/api/panel/v1/academico/convocatorias/{uuid}/…`)

| Método y ruta | Permiso | Notas |
| --- | --- | --- |
| `GET …/calificaciones` | `calificaciones.ver` | Evaluaciones y matriz de notas de las matrículas **vigentes** (sin anuladas ni eliminadas). `ETag` y `no-store` |
| `POST …/evaluaciones` | `calificaciones.corregir` | 201. `_revision` obligatoria (428/412). Avanza la revisión de la convocatoria |
| `PUT …/evaluaciones/{uuid}` | `calificaciones.corregir` | Igual. Cambiar el peso respeta la suma |
| `POST …/evaluaciones/{uuid}/eliminar` | `calificaciones.corregir` | Solo sin notas (409 si las tiene) |
| `PUT …/calificaciones` | `calificaciones.registrar` (nota nueva) o `calificaciones.corregir` (nota existente) | Una nota por llamada; sin revisión de la convocatoria |
| `POST …/acta/cerrar` | `convocatorias.cerrar` | `_revision` obligatoria; idempotente |
| `POST …/acta/exportar` | `calificaciones.exportar` | Excel del acta de una convocatoria `cerrada` |

Roles por defecto (también con la migración de datos `000058`, que no resincroniza roles): `admin` y `coordinador` todos los permisos;
`secretaria`, `calificaciones.ver` y `calificaciones.registrar`. Gestionar la estructura de evaluaciones se agrupa bajo `corregir`:
cambiar cómo se pondera es tan sensible como corregir una nota.

## Reglas (`GestionCalificaciones`, única implementación)

- **Evaluaciones:** hasta 20 por convocatoria, peso `0 < peso ≤ 100` con dos decimales; la **suma no pasa de 100** (se calcula en
  centésimas enteras, sin errores de coma flotante). Se gestionan mientras la convocatoria es `borrador`, `abierta` o `en_curso`.
- **Notas:** escala vigesimal `0 ≤ nota ≤ 20`, hasta dos decimales; **solo con la convocatoria `en_curso`** y solo a matrículas
  vigentes de esa convocatoria (si no, 422 `matricula_id`). Una sola nota por matrícula y evaluación.
- **Corregir** una nota existente exige `calificaciones.corregir`, un `motivo` (hasta 200 caracteres) y la `nota_actual` que el usuario
  ve: si cambió mientras tanto, 409. Corregir a la misma nota no hace nada. La auditoría guarda antes, después y motivo.
- **Cierre de acta:** exige la convocatoria `en_curso`, pesos que sumen exactamente 100 y todas las notas de todas las matrículas
  vigentes. Calcula `nota_final = Σ nota × peso / 100`, redondeada a dos decimales (mitad hacia arriba, en enteros), y
  `resultado = aprobado` si `nota_final ≥ 11` (`desaprobado` si no). Lo guarda en `matriculas.nota_final` y `resultado`, deja la
  convocatoria `cerrada` y avanza su revisión, todo en una transacción con la convocatoria bloqueada. **Repetirlo** sobre una
  convocatoria cerrada no cambia nada (ni exige revisión). Las matrículas anuladas quedan sin resultado.
- **Después del cierre** no se modifican notas, evaluaciones ni estado (409).
- **El estado genérico** (`POST …/estado`) **rechaza `cerrada`** si la convocatoria tiene evaluaciones: «usa el cierre de acta».
- **Orden de bloqueo:** convocatoria → matrícula → calificación (consistente con las matrículas: curso → convocatoria → estudiante →
  matrícula).

## Privacidad

Las notas son datos personales del estudiante. Toda respuesta va con `Cache-Control: no-store, private`. Las evaluaciones se exponen
por `identificador` (uuid), nunca por id interno; las matrículas conservan el id que ya expone su módulo. La auditoría guarda
identificadores internos, valores de nota y el motivo de la corrección, **nunca** el nombre ni el documento del estudiante. El acta
exportada deja constancia de acceso (`acta_exportada`: archivo y cantidad de matrículas, sin notas) y se descarga sin caché; las
celdas se escriben como texto o número literal (`SafeSpreadsheet`). Acciones: `evaluacion_creada|modificada|eliminada`,
`nota_registrada|corregida`, `acta_cerrada`, `acta_exportada`.

## Datos

`000058` (permisos), `000059` (`evaluaciones` y `calificaciones`, sin cascadas; único `(matricula_id, evaluacion_id)`) y `000060`
(`matriculas.nota_final` decimal(5,2) y `resultado`, ambas nulas; `ALTER TABLE` en bruto en SQLite). Los `CHECK` de PostgreSQL (peso,
nota, rango de la nota final, valores de `resultado` y que ambas columnas vayan juntas) **solo se crean en PostgreSQL** y están **sin
ejecutar** hasta nueva autorización; en SQLite los valida el servicio.

## Next

En la ficha de la convocatoria, sección «Calificaciones» (solo la ve quien tiene `calificaciones.ver`): tabla de estudiantes por
evaluación con nota final y resultado; cada nota abre un diálogo para registrar o, si ya existe, corregir con motivo; «Nueva
evaluación» y edición desde el encabezado de cada columna (con eliminar si no tiene notas); «Cerrar acta» (con confirmación, solo con
pesos en 100) y «Exportar acta» una vez cerrada. El BFF suma 6 operaciones. Sin cobertura automática de Next.

## Decisiones y pendientes

- **Nota mínima aprobatoria 11** como constante (`Calificacion::NOTA_APROBATORIA`). El diseño pedía `system_settings`: hacerla editable
  exige ampliar la pantalla de Configuración (Blade) y se deja para la Ola 4.
- **Exportación solo en Excel.** El PDF del acta queda con la decisión general de PDF (necesita plantilla y librería).
- **Sin paginar** la matriz: una convocatoria no pasa de sus cupos máximos.

## Pruebas

`Panel\CalificacionesPanelTest` (13: permisos por rol, suma de pesos y revisión, validaciones, editar y eliminar, estado y matrícula
vigente, rango y decimales, corrección con motivo, cierre con ponderación, resultado e idempotencia, redondeo sin coma flotante,
requisitos del cierre, estado genérico, exportación con constancia y respuesta sin caché ni ids) y `Panel\CalificacionesMigracionTest`
(5: permisos por rol sin resincronizar, forma de las tablas, unicidad y restricciones, `down`). `AcademicoCuposPostgresTest` suma los
`CHECK` de evaluaciones, notas y resultado, el `RESTRICT` y la carrera entre una nota y el cierre (**escritas, sin ejecutar**).
