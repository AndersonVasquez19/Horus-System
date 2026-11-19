# 05 · Diseño financiero

> **Fase 1 · diseño.** No existe hoy ninguna tabla financiera: el único dato monetario es `matriculas.monto_pagado`
> (`decimal(10,2)`, nulo) y `cursos.precio`; los reportes calculan «ingresos» sumando `monto_pagado` de matrículas `pagada`
> (verificado en `ResumenAcademico` y `ReportesMatriculas`). Este diseño sustituye esa suma por un **libro mayor inmutable**.
> No se ejecuta nada contra PostgreSQL; las pruebas de concurrencia requieren autorización del clúster aislado.

## 1. Objetivos y no-objetivos

**Objetivos:** pago completo y fraccionado; abonos parciales; varias transacciones por obligación; saldos e historial
consultables; reversos auditados; imposibilidad de alterar en silencio un pago confirmado; sin dobles cobros por reintento
ni por concurrencia.

**No-objetivos de la primera etapa (decisiones pendientes en README):** facturación/boleta electrónica (SUNAT),
pasarela de pago en línea, intereses o mora automática, múltiples monedas, becas/descuentos automáticos, conciliación
bancaria, portal de pago del estudiante.

## 2. Modelo

```
matriculas 1 ─── n obligaciones_pago 1 ─── n cuotas_obligacion
                         │                         ▲
                         │ 1                       │ n
                         ▼ n                       │
                 transacciones_pago 1 ─── n asignaciones_pago
                         │ 0..1
                         └─ reversa_a_id → transacciones_pago   (reverso compensatorio)
metodos_pago 1 ─── n transacciones_pago
```

- **Obligación** (`obligaciones_pago`): lo que se debe por una matrícula (`monto_total`, plan `completo|fraccionado`).
- **Cuota** (`cuotas_obligacion`): tramo con vencimiento. El pago completo tiene **una** cuota.
- **Transacción** (`transacciones_pago`): hecho de dinero (`pago` o `reverso`), inmutable una vez confirmada.
- **Asignación** (`asignaciones_pago`): cuánto de cada transacción cubre cada cuota. Permite abonos parciales y pagos que
  cruzan cuotas.
- **Reverso compensatorio:** nunca se edita ni se borra un pago; se crea otra transacción `tipo = reverso` que apunta al
  pago original y cuyas asignaciones son **negativas**.

Detalle de columnas y `CHECK` en [02](02-diccionario-de-datos.md) §C.

## 3. Invariantes (se prueban; ver [07](07-plan-pruebas.md))

| # | Invariante |
| --- | --- |
| F1 | `cuotas_obligacion.saldo = monto − Σ asignaciones` de esa cuota, siempre `0 ≤ saldo ≤ monto`. |
| F2 | `obligaciones_pago.saldo = Σ saldo de sus cuotas = monto_total − Σ transacciones confirmadas (pagos − reversos)`. |
| F3 | `Σ asignaciones_pago.monto` de una transacción = su `monto` con el signo de su `tipo`. |
| F4 | `Σ cuotas.monto = obligaciones_pago.monto_total` y no cambia tras el primer pago confirmado. |
| F5 | Una transacción `confirmada` no se actualiza ni se borra (disparador); solo se compensa con un reverso. |
| F6 | Un pago admite **a lo sumo un** reverso; un reverso no se reversa; el reverso no puede exceder lo asignado. |
| F7 | `estado` de la obligación: `pendiente` (saldo = total), `parcial` (0 < saldo < total), `pagada` (saldo = 0), `anulada`. Se **deriva**, no se edita. |
| F8 | Reintentar la misma operación (misma `clave_idempotencia`) devuelve la transacción original y no cobra dos veces. |
| F9 | Todo importe es `decimal(12,2)`; se calcula con aritmética de cadenas decimales (BCMath), nunca con `float`. |
| F10 | Cada escritura deja `audit_logs` (quién, qué, cuándo, IP) sin datos de tarjeta ni referencias completas. |

## 4. Máquina de estados de una transacción

```
(registrar) ─► pendiente ─(confirmar)─► confirmada ─(reversar)─► [nuevo registro: reverso confirmado]
                  └─(rechazar)─► rechazada
```

- `pendiente` no mueve saldos (permite registrar un depósito por verificar). `confirmada` aplica las asignaciones.
- `rechazada` es terminal y no mueve saldos. No hay transición `confirmada → pendiente|rechazada`.
- Registrar y confirmar pueden ser una sola acción si el rol tiene ambos permisos (efectivo en caja), pero quedan dos
  eventos de auditoría distintos.

## 5. Operaciones y reglas

| Operación | Permiso | Reglas |
| --- | --- | --- |
| Crear obligación (plan) | `pagos.planificar` | Matrícula vigente, con edición, sin obligación vigente del mismo concepto; cuotas con resto del redondeo en la **primera** cuota; vencimientos crecientes. |
| Registrar pago | `pagos.registrar` | Obligación no `anulada` ni `pagada`; `monto ≤ saldo` (no se admiten sobrepagos en esta etapa); referencia obligatoria si el método la exige; clave de idempotencia obligatoria (header `Idempotency-Key`, como las cotizaciones). Asigna a las cuotas en orden de vencimiento salvo indicación explícita. |
| Confirmar pago | `pagos.confirmar` | Solo `pendiente`; `confirmado_por ≠ registrado_por` salvo `admin` (**decisión pendiente**). |
| Rechazar pago | `pagos.confirmar` | Solo `pendiente`; motivo obligatorio. |
| Reversar pago | `pagos.reversar` | Solo `confirmada` y sin reverso; motivo ≥ 10 caracteres; crea reverso con asignaciones negativas a las **mismas** cuotas; recalcula saldos; la obligación puede volver a `parcial`/`pendiente`. |
| Anular obligación | `pagos.planificar` + `matriculas.anular` | Solo con saldo = monto (sin pagos confirmados netos). Si hay pagos, primero se reversan. |
| Consultar saldos / historial | `pagos.ver` | Historial completo e inmutable, orden cronológico, pagos y reversos enlazados. |

Relación con matrícula: al llegar el saldo a 0 la matrícula pasa a `pagada` y `monto_pagado` toma el neto confirmado;
si un reverso reabre saldo, la matrícula vuelve a `pendiente` **siempre que** siga vigente (reglas de elegibilidad de
`docs/sprint-08-reglas-matriculas.md`). Anular una matrícula con pagos netos > 0 exige antes reversar (decisión pendiente:
¿o permitir anular y dejar saldo a favor?). Todo ocurre en la misma transacción de base de datos que el libro.

**Numeración:** `numero_comprobante` (pagos) y el `numero` de certificados se generan con `Support\CodigoSecuencial`
(tabla `secuencias_codigo`, contador bloqueado; la misma pieza que ya usa `convocatorias` para `CNV-AAAA-NNNN`), con
prefijos nuevos (`REC`, `CERT`). No se escribe un numerador nuevo. Esa tabla es compartida (ver [08](08-plan-migraciones.md) §5).

## 6. Concurrencia y orden de bloqueo

Se extiende el protocolo vigente (`CLAUDE.md` §4: cursos → estudiantes → matrícula; `lockForUpdate()` siempre dentro de
transacción) con dos niveles **al final**:

```
convocatoria? (solo si la operación toca cupos) → … → matrícula → obligación (FOR UPDATE) → cuotas (ORDER BY numero, FOR UPDATE)
```

- Registrar/confirmar/reversar leen la obligación y sus cuotas **bajo bloqueo**, recalculan saldos desde el libro y luego
  escriben; los contadores solo cambian tras comprobar permisos, estado, saldo e idempotencia; una excepción revierte todo.
- Idempotencia: `UNIQUE (obligacion_id, clave_idempotencia)`; ante colisión se devuelve la transacción existente (no 500).
- PostgreSQL protege además con los `CHECK` de saldo y los disparadores de inmutabilidad: aunque falle la lógica de
  servicio, la base rechaza saldos negativos, doble reverso o actualización de pagos confirmados.
- **Honestidad de evidencia:** SQLite ignora `lockForUpdate()`; la suite habitual no mide concurrencia. Los tests de
  carrera (dos cobros simultáneos del último saldo, doble reverso, mismo `Idempotency-Key` en paralelo) viven en
  `tests/PostgresIntegrity` y solo corren en el clúster aislado `127.0.0.1:55433` con autorización.

## 7. Inmutabilidad (implementación propuesta)

- PostgreSQL: `BEFORE UPDATE OR DELETE` en `transacciones_pago` y `asignaciones_pago` que lanza excepción, salvo la
  transición permitida `estado: pendiente → confirmada|rechazada` (más `confirmada_at`/`confirmado_por`).
- SQLite: disparadores equivalentes (mismo mecanismo que las migraciones de `CHECK`).
- Sin `SoftDeletes`, sin `updated_at` significativo en el libro: el historial es el propio registro.
- Las tablas de saldo (`obligaciones_pago.saldo/estado`, `cuotas_obligacion.saldo`) **sí** se actualizan (son derivadas),
  protegidas por `CHECK`, bloqueo y una consulta de verificación (§8).

## 8. Verificación de integridad

Comando de solo lectura `horus:verificar-finanzas` (Fase 5): recalcula saldos desde `asignaciones_pago` y compara con las
cachés (`F1–F4`), busca reversos huérfanos o dobles y `monto_pagado` ≠ neto del libro. Se ejecuta en pruebas y como control
operativo programable. Un desvío es un incidente, no se «corrige» automáticamente.

## 9. Datos heredados (`matriculas.monto_pagado`)

Las matrículas actuales con `monto_pagado > 0` no tienen libro. Propuesta (decisión pendiente, requiere autorización y
simulación previa): crear por cada una una obligación `completo` con `monto_total = max(precio del curso, monto_pagado)` y una
transacción `tipo = pago`, método `apertura`, `estado = confirmada`, `referencia = 'SALDO-INICIAL'`, para que
`monto_pagado` = neto del libro desde el primer día. Nunca se reescribe `monto_pagado` histórico sin el libro que lo respalde.
Hasta ese backfill, los reportes siguen leyendo `monto_pagado` (convivencia por interruptor de configuración).

**Hallazgo (verificado 16/11/2026):** `monto_pagado` hoy es **escribible** por la API: lo aceptan `StoreMatriculaRequest`
y `UpdateMatriculaRequest` (`nullable|numeric|min:0`), y lo leen `ReportesMatriculas` (suma de ingresos de matrículas
`pagada`), `ResumenAcademico`, `MatriculasExcelExport` y `ActaExcel`. Al activar el libro (Fase 5) esos dos *requests*
dejan de aceptar `monto_pagado` (se ignora y se documenta como obsoleto en OpenAPI) y la columna solo la escribe el
servicio financiero. Mientras el libro no esté activo para una matrícula, el comportamiento actual se conserva sin cambios.
Esto es un cambio de contrato de matrículas: requiere actualizar `tests/Feature/MatriculaOperationsTest.php` y
`docs/sprint-08-reglas-matriculas.md`, como exige `CLAUDE.md` §4.

## 10. Riesgos

| Riesgo | Mitigación |
| --- | --- |
| Backfill incorrecto de importes históricos | Modo `--simular`, informe de diferencias, aprobación del propietario, ejecución solo tras respaldo restaurable ensayado. |
| Dos fuentes de verdad (`monto_pagado` y libro) | Caché derivada escrita en la misma transacción + comando de verificación + pruebas. |
| Sobrepago/pago duplicado por reintento del navegador | Idempotencia obligatoria, sin sobrepagos, bloqueo de obligación. |
| Fraude interno (cobrar y borrar) | Libro inmutable, separación registrar/confirmar/reversar, auditoría, `pagos.reversar` solo admin. |
| Alcance fiscal (SUNAT) no definido | Fuera de alcance; el `numero_comprobante` es interno y no sustituye comprobante electrónico. Decisión del propietario. |
