# 02 · Diccionario de datos (en español)

> **Fase 1 · diseño.** Tipos y restricciones propuestos, pensados para PostgreSQL (destino) y SQLite en memoria (pruebas).
> En SQLite hay dos precedentes: disparadores equivalentes al `CHECK` (migraciones `000025`, `000027`, `000030`, `000032`, `000033`) o solo validación en el servicio (`000039`, `000041`, `000056`). Para reglas **de integridad financiera** se usan disparadores también en SQLite, de modo que la suite habitual las ejercite.
> Todas las tablas nuevas llevan `created_at` / `updated_at`. Las claves foráneas son `RESTRICT`, salvo `estudiantes.user_id` (`SET NULL`, ver §A).
> Los importes son `decimal(12,2)` (nunca `float`); la moneda inicial es `PEN`.

Leyenda: **PK** clave primaria · **FK** clave foránea · **UQ** único · **NN** no nulo · **ID** identificador público (UUID).

## A. Cambios en tablas existentes

| Tabla | Cambio | Detalle |
| --- | --- | --- |
| `estudiantes` | `+ user_id` | `unsignedBigInteger` NULL, FK `users.id` **`ON DELETE SET NULL`**, **UQ** (un usuario ↔ un expediente). Sin endpoint de portal. **Implementado en la Fase 2** (migración `000061`, sin ejecutar en bases reales). `SET NULL` y no `RESTRICT` para no cambiar el flujo vigente de eliminación de usuarios: el expediente académico se conserva y queda sin cuenta. |
| `convocatorias` | `+ periodo_academico_id` | FK NULL → `periodos_academicos.id`. |
| `convocatorias` | `+ sede_id`, `+ aula_id`, `+ plataforma_virtual_id` | FK NULL. Ver reglas de modalidad abajo. |
| `convocatorias` | `modalidad` | Se conserva `presencial|semipresencial|virtual|hibrido` (compatibilidad); el servicio solo admite **crear** `presencial` y `virtual`. |
| `convocatoria_horarios` | `docente`, `lugar` | Se **conservan** (texto libre heredado, solo lectura tras la normalización) y se marcan obsoletos. |
| `matriculas` | `monto_pagado` | Pasa a ser caché derivada del libro de pagos (ver [05](05-diseno-financiero.md)). |
| `evaluaciones` | restricción de pesos | Regla nueva: suma de `peso` por `convocatoria_id` **≤ 100,00** (ver [Reglas](#e-reglas-transversales)). |
| `audit_logs` | — | Sin cambios de esquema. Se añaden acciones nuevas (ver [03](03-matriz-permisos.md) §Auditoría). |

> **Fase 3 (16/11/2026) — diseño ajustado:** el `CHECK … NOT VALID` descrito abajo **no se implementó así**: en PostgreSQL un `CHECK` se evalúa en cada `UPDATE` de la fila aunque sea `NOT VALID`, y las matrículas actualizan `cupos_disponibles` de ediciones heredadas sin infraestructura, que fallarían. Se implementó con un indicador `convocatorias.exige_infraestructura` (false en lo existente; el servicio lo pone en true en las ediciones nuevas) y tres `CHECK` válidos desde el inicio (excluyente por modalidad, aula⇒sede, completa solo si el indicador es true y la edición no está en borrador/cancelada), más la clave foránea compuesta (sede, aula) → aulas. Ver migración `000063`.

**CHECK de modalidad en `convocatorias` (diseño original, superado por la nota anterior)** (`NOT VALID` en PostgreSQL: no revalida filas heredadas, pero toda escritura
posterior sobre una fila debe cumplirlo):

```sql
CHECK (
  (modalidad = 'presencial' AND sede_id IS NOT NULL AND aula_id IS NOT NULL AND plataforma_virtual_id IS NULL)
  OR (modalidad = 'virtual'  AND plataforma_virtual_id IS NOT NULL AND sede_id IS NULL AND aula_id IS NULL)
  OR (modalidad IN ('semipresencial','hibrido'))   -- heredadas: sin exigencias hasta decisión (README §Pendientes)
)
```

Además, `aula_id` debe pertenecer a `sede_id` (validado en el servicio y con FK compuesta `(sede_id, aula_id)` →
`aulas(sede_id, id)` en PostgreSQL).

## B. Tablas académicas e infraestructura (nuevas)

### `docentes`
| Columna | Tipo | Restricciones | Descripción |
| --- | --- | --- | --- |
| `id` | bigint | PK | |
| `identificador` | uuid | UQ, NN, ID | Identificador público. |
| `user_id` | bigint | NULL, FK `users`, UQ | Vínculo futuro con cuenta (opcional y único). |
| `documento` | varchar(12) | UQ, NN | DNI/CE. Mismo patrón de validación que `estudiantes.dni`. |
| `nombres`, `apellidos` | varchar(100) | NN | |
| `email` | varchar(150) | UQ, NULL | Normalizado (minúsculas, sin espacios) con `Support\Email`. |
| `telefono` | varchar(20) | NULL | |
| `especialidad` | varchar(150) | NULL | |
| `estado` | varchar(12) | NN, CHECK `activo|inactivo` | |
| `deleted_at` | timestamp | NULL | Soft delete (historial de ediciones dictadas). |

### `periodos_academicos`
| Columna | Tipo | Restricciones | Descripción |
| --- | --- | --- | --- |
| `id` / `identificador` | bigint / uuid | PK / UQ | |
| `codigo` | varchar(20) | UQ, NN | Ej. `2027-I`. |
| `nombre` | varchar(100) | NN | |
| `fecha_inicio`, `fecha_fin` | date | NN, CHECK `fecha_fin >= fecha_inicio` | |
| `estado` | varchar(10) | NN, CHECK `abierto|cerrado` | Un período cerrado no admite ediciones nuevas. |
| — | — | Sin solape | `EXCLUDE USING gist (daterange(fecha_inicio, fecha_fin, '[]') WITH &&)` en PostgreSQL (requiere `btree_gist`; servicio en SQLite). **Decisión pendiente:** ¿pueden solaparse períodos? |

### `sedes`
`id`, `identificador` (UQ), `codigo` varchar(20) UQ NN, `nombre` varchar(120) NN, `direccion` varchar(200) NN,
`ciudad` varchar(80) NN default `Cajamarca`, `estado` `activo|inactivo`.

### `aulas`
`id`, `identificador` (UQ), `sede_id` FK NN, `codigo` varchar(20) NN, `nombre` varchar(120) NN, `capacidad`
smallint NN CHECK `> 0`, `estado` `activo|inactivo`. **UQ** `(sede_id, codigo)`; **UQ** `(sede_id, id)` (apoya la FK compuesta).

### `plataformas_virtuales`
`id`, `identificador` (UQ), `nombre` varchar(80) UQ NN (Google Meet, Zoom…), `tipo` varchar(20) NN CHECK
`meet|zoom|teams|otra`, `estado`. **No guarda credenciales ni claves de API**: solo cataloga la herramienta externa.

### `convocatoria_docentes`
`id`, `convocatoria_id` FK NN, `docente_id` FK NN, `rol` CHECK `principal|apoyo`, **UQ** `(convocatoria_id, docente_id)`.
Una edición tiene como máximo un docente `principal` (índice único parcial sobre `convocatoria_id WHERE rol = 'principal'`).

### `sesiones_clase`
| Columna | Tipo | Restricciones | Descripción |
| --- | --- | --- | --- |
| `id` / `identificador` | bigint / uuid | PK / UQ | |
| `convocatoria_id` | bigint | FK NN | |
| `numero` | smallint | NN, **UQ** `(convocatoria_id, numero)` | Orden dentro de la edición. |
| `fecha` | date | NN | |
| `hora_inicio`, `hora_fin` | time | NN, CHECK `hora_fin > hora_inicio` | |
| `tema` | varchar(200) | NULL | |
| `docente_id` | bigint | NULL, FK | |
| `aula_id` | bigint | NULL, FK | Obligatoria si la edición es presencial. |
| `plataforma_virtual_id` | bigint | NULL, FK | Obligatoria si la edición es virtual. |
| `enlace_virtual` | text | NULL, **cifrado** (`encrypted` cast) | Nunca en listados, catálogo público, chatbot, auditoría ni logs. Solo con `sesiones.ver-enlace`. |
| `estado` | varchar(12) | NN, CHECK `programada|realizada|cancelada` | `reprogramada` no es estado: reprogramar edita fecha/hora y audita el cambio. |
| `motivo_cancelacion` | varchar(200) | NULL | NN si `estado = cancelada` (CHECK). |
| — | — | Sin solape | `EXCLUDE` por aula y por docente en PostgreSQL; el servicio lo valida bajo bloqueo en SQLite. |

### `asistencias`
`id`, `sesion_id` FK NN, `matricula_id` FK NN, `estado` CHECK `presente|ausente|tardanza|justificada` NN,
`observacion` varchar(200) NULL, `registrado_por` FK `users` NN, `registrado_at` timestamp NN. **UQ** `(sesion_id, matricula_id)`.
La matrícula debe estar vigente (`pendiente|pagada`) y pertenecer a la misma edición que la sesión (validado en el servicio
bajo bloqueo). Toda corrección (cambio de `estado`) exige motivo y se audita (`asistencia_corregida`).

## C. Tablas financieras (nuevas) — detalle en [05](05-diseno-financiero.md)

### `metodos_pago`
`id`, `codigo` varchar(20) UQ NN (`efectivo`, `transferencia`, `yape`, `plin`, `tarjeta`, `deposito`, y `apertura`
reservado al saldo inicial migrado, no seleccionable en el panel), `nombre` varchar(60) NN, `requiere_referencia` boolean
NN, `estado` `activo|inactivo`. Los métodos concretos de uso real son una decisión pendiente del propietario.

### `obligaciones_pago`
| Columna | Tipo | Restricciones |
| --- | --- | --- |
| `id` / `identificador` | bigint / uuid | PK / UQ |
| `matricula_id` | bigint | FK NN |
| `concepto` | varchar(120) | NN |
| `plan` | varchar(12) | NN, CHECK `completo|fraccionado` |
| `monto_total` | decimal(12,2) | NN, CHECK `> 0` |
| `moneda` | char(3) | NN default `PEN`, CHECK `= 'PEN'` (ampliable) |
| `saldo` | decimal(12,2) | NN, CHECK `saldo >= 0 AND saldo <= monto_total` — **caché** del libro |
| `estado` | varchar(12) | NN, CHECK `pendiente|parcial|pagada|anulada` — **derivado** |
| `revision` | integer | NN default 1 (control optimista, como `convocatorias`) |
| — | — | Una obligación **vigente** por matrícula y concepto: UQ parcial `(matricula_id, concepto) WHERE estado <> 'anulada'`. |

### `cuotas_obligacion`
`id`, `obligacion_id` FK NN, `numero` smallint NN, `monto` decimal(12,2) NN CHECK `> 0`, `vence` date NN,
`saldo` decimal(12,2) NN CHECK `0 <= saldo <= monto`. **UQ** `(obligacion_id, numero)`. Regla: `SUM(monto)` de las
cuotas = `monto_total` (se valida en el servicio al crear el plan; el plan no cambia tras el primer pago confirmado).
Una obligación `completo` tiene exactamente una cuota.

### `transacciones_pago` (inmutable)
| Columna | Tipo | Restricciones |
| --- | --- | --- |
| `id` / `identificador` | bigint / uuid | PK / UQ |
| `obligacion_id` | bigint | FK NN |
| `metodo_pago_id` | bigint | FK NN |
| `tipo` | varchar(10) | NN, CHECK `pago|reverso` |
| `monto` | decimal(12,2) | NN, CHECK `> 0` (el signo lo da `tipo`) |
| `referencia` | varchar(80) | NULL (operación, voucher); NN si el método la exige |
| `fecha_pago` | date | NN, CHECK `<= fecha de registro` |
| `estado` | varchar(10) | NN, CHECK `pendiente|confirmada|rechazada` |
| `reversa_a_id` | bigint | NULL, FK `transacciones_pago.id`, **UQ** (a lo sumo un reverso por pago) |
| `motivo` | varchar(200) | NN si `tipo = reverso` (CHECK) |
| `numero_comprobante` | varchar(20) | UQ NULL (correlativo interno; no es comprobante electrónico) |
| `clave_idempotencia` | varchar(64) | NN, **UQ** `(obligacion_id, clave_idempotencia)` |
| `registrado_por`, `confirmado_por` | bigint | FK `users` |
| `confirmada_at` | timestamp | NULL |

CHECK: `(tipo = 'reverso') = (reversa_a_id IS NOT NULL)`. Un reverso no se reversa.

### `asignaciones_pago` (inmutable)
`id`, `transaccion_id` FK NN, `cuota_id` FK NN, `monto` decimal(12,2) NN CHECK `<> 0`. Para `pago` el monto es positivo; para
`reverso` es negativo (CHECK por tipo). `SUM(monto)` de una transacción = ± `transacciones_pago.monto`.
**UQ** `(transaccion_id, cuota_id)`.

## D. Certificados (nueva) — [03](03-matriz-permisos.md), [05](05-diseno-financiero.md)

### `certificados`
| Columna | Tipo | Restricciones |
| --- | --- | --- |
| `id` / `identificador` | bigint / uuid | PK / UQ |
| `matricula_id` | bigint | FK NN; UQ parcial `WHERE estado = 'emitido'` (un certificado vigente por matrícula) |
| `numero` | varchar(20) | UQ NN (correlativo `CERT-AAAA-nnnnnn`, generado bajo bloqueo) |
| `codigo_verificacion` | varchar(24) | UQ NN, aleatorio criptográfico (no derivable del DNI ni del id) |
| `estado` | varchar(10) | NN, CHECK `emitido|anulado` |
| `emitido_por`, `emitido_at` | FK `users` / timestamp | NN |
| `anulado_por`, `anulado_at`, `motivo_anulacion` | | NN los tres si `estado = anulado` (CHECK) |
| `datos` | json | Instantánea (nombre, curso, fechas, nota final, horas) al emitir: el certificado no cambia aunque cambie el expediente. |
| `huella` | char(64) | SHA-256 del PDF generado. |

## E. Reglas transversales

1. **Ponderaciones ≤ 100 %.** Un `CHECK` de fila no puede sumar filas: se impone (a) en `GestionCalificaciones`, bajo
   `lockForUpdate()` de la `convocatoria`, y (b) en PostgreSQL con un *constraint trigger* diferido que recalcula
   `SUM(peso)` por edición y rechaza `> 100`. Es la regla académica que necesita un disparador para operar entre filas (las financieras y de solape se tratan aparte).
2. **Capacidad sin carreras.** Se conserva el protocolo vigente (`CLAUDE.md` §4): cursos → estudiantes → matrícula, con
   `convocatorias.cupos_disponibles` como contador y `CHECK cupos_disponibles BETWEEN 0 AND cupos_maximos`. Para
   asistencia y pagos se añade un nivel al final del orden de bloqueo (ver [05](05-diseno-financiero.md) §6).
3. **Inmutabilidad.** `transacciones_pago` y `asignaciones_pago` no admiten `UPDATE` (salvo la transición
   `pendiente → confirmada|rechazada` de `estado`) ni `DELETE`: disparadores `BEFORE UPDATE OR DELETE`.
4. **Datos personales.** `documento`, `email`, `telefono` de docentes son datos personales: minimizados en listados y
   enmascarados en auditoría con `SensitiveData`, igual que estudiantes.
5. **Enlaces virtuales no públicos.** Nunca en `/api/public/v1/*`, en el catálogo del asistente (`apps/ai`), en
   `curso_publicaciones`, en `audit_logs.changes` ni en exportaciones.
