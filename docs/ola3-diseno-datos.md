# Ola 3 — Diseño de datos del dominio nuevo (propuesta para aprobar)

14/11/2026. **Solo diseño: no hay migraciones, código ni cambios en la base.** Lo que sigue es una propuesta; las
decisiones abiertas (§8) llevan un valor por defecto que se aplica si no se corrige. La implementación empieza cuando se
apruebe este documento.

Alcance: los módulos del diagrama que hoy no existen (`docs/arquitectura-paneles.md` §«No existen»):

- **Panel de servicios** (`/admin/servicios`): clientes, prospectos, pipeline, proyectos, soporte técnico y reportes comerciales.
- **Panel académico** (`/admin/academico`): convocatorias con sus horarios y cupos, y calificaciones.

## 1. Principios (iguales a los de los módulos ya construidos)

1. **Separación de dominios.** Ningún controlador de un dominio lee las tablas del otro. Lo único que cruza es lo compartido
   (usuarios, roles, auditoría, notificaciones). Clientes y proyectos no tocan estudiantes ni matrículas, y al revés.
2. **Identificador público opaco.** Cada entidad que sale por la API lleva `identificador` (UUID); el `id` interno no sale
   (patrón de reclamaciones y cotizaciones). Las rutas usan el UUID.
3. **Concurrencia optimista.** Las entidades editables llevan `revision` y ETag (`If-Match` → 412), como servicios y
   cotizaciones. Las transiciones de estado se hacen bajo bloqueo de fila y son idempotentes.
4. **Estados con `CHECK`.** Cada estado se limita en la base con un `CHECK` (patrón de las migraciones 000039 y 000041) y las
   transiciones válidas viven en un servicio (`Gestion*`), no en el controlador.
5. **Integridad referencial.** Claves foráneas con `ON DELETE RESTRICT` (nada se borra en cascada); entidades de negocio con
   `softDeletes` y archivado antes que borrado, como cursos y estudiantes.
6. **Auditoría sin datos personales.** Cada operación efectiva deja una fila en `audit_logs` con nombres técnicos e
   identificadores, nunca correos, teléfonos, documentos, notas ni textos libres.
7. **Privacidad.** Los campos que salen por la API son una lista cerrada; los datos de personas se enmascaran en listados
   cuando el detalle no los necesita; las exportaciones dejan constancia de acceso (como reclamaciones) y llevan
   `Cache-Control: no-store, private`.
8. **Un solo servicio por módulo** (`GestionClientes`, `GestionProspectos`, …) que usa la API del panel; no hay Blade nuevo.
9. **Migraciones reversibles y probadas en SQLite.** Numeración a partir de `2026_11_15_000043`. La validación en PostgreSQL
   (CHECK, claves, bloqueos) solo se ejecuta con autorización explícita, en el clúster aislado 55433.
10. **Códigos legibles** (`PRY-2026-0001`, `SOP-2026-0001`, `CNV-2026-0001`) generados bajo bloqueo desde una tabla
    `secuencias_codigo (clave, anio, ultimo)`: no dependen de secuencias del motor, así que SQLite y PostgreSQL se comportan igual.

## 2. Vista general

```
                         PANEL DE SERVICIOS                                    PANEL ACADÉMICO
 servicios_tecnologicos ◄── prospectos ──► clientes ◄── solicitudes_cotizacion      cursos ◄── convocatorias ──► convocatoria_horarios
                              │              │  ▲                                                   │
                              │              │  └── cliente_contactos                               ├── evaluaciones
                              ▼              ▼                                                      │        │
                          oportunidades ──► proyectos ──► proyecto_hitos           matriculas ◄─────┴── calificaciones
                              │                │                                  (convocatoria_id, nota_final, resultado)
                              └── oportunidad_historial
                                               ▼
                                         tickets_soporte ──► ticket_notas
```

Relaciones nuevas con tablas existentes: `solicitudes_cotizacion.cliente_id` (opcional), `prospectos.solicitud_cotizacion_id`
(opcional), `oportunidades.cotizacion_id` (opcional), `matriculas.convocatoria_id` (opcional).

## 3. Panel de servicios

### 3.1 `clientes`

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id`, `identificador` | bigint, uuid único | |
| `tipo` | varchar(10) | `persona` \| `empresa` (`CHECK`) |
| `nombre` | varchar(160) | Razón social o nombre completo |
| `documento_tipo`, `documento_numero` | varchar(10), varchar(20) null | `dni`, `ruc`, `ce`, `otro`; único `(documento_tipo, documento_numero)` cuando existe |
| `email`, `telefono`, `direccion` | null | |
| `estado` | varchar(10) | `activo` \| `inactivo` (`CHECK`) |
| `responsable_id` | FK `users` null | Cuenta que lleva la relación |
| `revision`, `timestamps`, `deleted_at` | | |

`cliente_contactos` (`cliente_id`, `nombre`, `cargo`, `email`, `telefono`, `principal`): una empresa tiene varios contactos y
solo uno principal (índice parcial en PostgreSQL; la regla también la hace el servicio para SQLite).

Reglas: un cliente con proyectos, oportunidades o tickets no se elimina, solo se inactiva o archiva. El correo se normaliza
(minúsculas, sin espacios). Las cotizaciones existentes **no se reescriben**: conservan sus datos de contacto embebidos y
ganan `cliente_id` nulo; una acción «vincular o crear cliente» enlaza la cotización con un cliente (sin duplicar por
documento o correo) y deja constancia en el historial de la cotización.

### 3.2 `prospectos`

`identificador`, `nombre_contacto`, `empresa` (null), `email`, `telefono`, `origen` (`web`, `cotizacion`, `referido`, `evento`,
`llamada`, `otro`), `servicio_interes_id` (FK `servicios_tecnologicos` null), `estado`, `motivo_descarte` (null),
`responsable_id`, `proximo_seguimiento` (date null), `solicitud_cotizacion_id` (FK null, único), `cliente_id` (FK null),
`revision`, `timestamps`, `deleted_at`.

Estados y transiciones: `nuevo → contactado → calificado → convertido`; `descartado` desde `nuevo`, `contactado` y `calificado`
(exige `motivo_descarte`); `descartado → nuevo` reabre. `convertido` es terminal: **convertir** crea (o vincula) el cliente y,
opcionalmente, una oportunidad, en una sola transacción e idempotente (una segunda llamada devuelve el mismo resultado).

### 3.3 `oportunidades` y `oportunidad_historial` (pipeline)

`oportunidades`: `identificador`, `cliente_id` (FK, obligatorio), `prospecto_id` (null), `servicio_id` (null), `cotizacion_id`
(null), `titulo`, `etapa`, `monto_estimado` decimal(12,2), `moneda` char(3) (de la configuración del sistema), `probabilidad`
smallint 0–100 (por defecto según etapa, editable), `fecha_cierre_estimada` (date null), `motivo_perdida` (null),
`responsable_id`, `cerrada_at` (null), `revision`, `timestamps`, `deleted_at`.

Etapas: `nueva → calificada → propuesta → negociacion → ganada`, y `perdida` desde cualquier etapa abierta (exige
`motivo_perdida`). `ganada` y `perdida` son terminales; reabrir una oportunidad cerrada exige permiso `pipeline.gestionar` y
queda en el historial. Probabilidad por defecto: 10, 25, 50, 75, 100 y 0.

`oportunidad_historial` (`oportunidad_id`, `etapa_anterior`, `etapa_nueva`, `user_id`, `created_at`): una fila por cambio de
etapa, sin textos libres. El tablero Kanban de Next mueve tarjetas con `POST …/etapa` (no con `PUT` completo), que valida la
transición y la revisión.

### 3.4 `proyectos` y `proyecto_hitos`

`proyectos`: `identificador`, `codigo` (`PRY-AAAA-NNNN`), `cliente_id` (FK), `oportunidad_id` (FK null, único: una oportunidad
da un solo proyecto), `nombre`, `descripcion`, `estado`, `fecha_inicio`, `fecha_fin_estimada`, `fecha_fin_real` (null),
`presupuesto` decimal(12,2) null, `moneda`, `responsable_id`, `revision`, `timestamps`, `deleted_at`.

Estados: `planificado → en_curso → completado`; `pausado` desde `en_curso` (vuelve a `en_curso`); `cancelado` desde
`planificado`, `en_curso` o `pausado`. `completado` y `cancelado` son terminales. Crear un proyecto desde una oportunidad
`ganada` copia cliente, nombre y monto, y es idempotente.

`proyecto_hitos` (`proyecto_id`, `nombre`, `orden`, `fecha_objetivo`, `completado_at` null): el avance se calcula (hitos
completados / hitos), no se guarda.

### 3.5 `tickets_soporte` y `ticket_notas`

`tickets_soporte`: `identificador`, `codigo` (`SOP-AAAA-NNNN`), `cliente_id` (FK), `proyecto_id` (FK null), `asunto`,
`descripcion`, `prioridad` (`baja`, `media`, `alta`, `critica`), `estado`, `canal` (`telefono`, `correo`, `presencial`,
`web`), `asignado_a` (FK `users` null), `vence_at` (calculado por prioridad), `resuelto_at`, `cerrado_at`, `revision`,
`timestamps`, `deleted_at`.

Estados: `abierto → en_progreso → resuelto → cerrado`; `en_espera_cliente` desde `en_progreso` (vuelve a `en_progreso`);
`resuelto → en_progreso` reabre dentro de 7 días, después hay que abrir otro ticket. `cerrado` es terminal. SLA por defecto
(horas hasta `vence_at`): crítica 4, alta 8, media 24, baja 72; configurable en `system_settings`.

`ticket_notas` (`ticket_id`, `autor_id`, `interna` bool, `texto`, `created_at`): las notas internas no salen de la API de
soporte. Las notas son de solo lectura una vez creadas (se corrige añadiendo otra). La pantalla avisa de no escribir
contraseñas ni claves; el servicio no las detecta.

### 3.6 Reportes comerciales (sin tablas nuevas)

Consultas agregadas sobre lo anterior, con el mismo patrón de `Reportes` académicos (consistencia entre tarjetas, gráficos y
exportación):

- **Embudo:** prospectos por estado, oportunidades por etapa, conversión prospecto → cliente y oportunidad → ganada, valor
  abierto (suma ponderada por probabilidad) y valor ganado por periodo.
- **Soporte:** tickets por estado y prioridad, tiempo medio de resolución, tickets vencidos.
- **Proyectos:** por estado, hitos vencidos.

Filtros: rango de fechas de Lima (días completos), responsable. Exportación a Excel/PDF con constancia de acceso. Va **después**
del resto del CRM porque depende de sus datos.

## 4. Panel académico

### 4.1 `convocatorias` y `convocatoria_horarios`

Una **convocatoria** es una edición abierta de un curso (fechas, cupos, horario). El curso sigue siendo el catálogo.

`convocatorias`: `identificador`, `codigo` (`CNV-AAAA-NNNN`), `curso_id` (FK), `nombre` (null: por defecto, el del curso),
`modalidad` (`presencial`, `virtual`, `mixta`), `fecha_inicio`, `fecha_fin` (`CHECK fecha_fin >= fecha_inicio`),
`cupos_maximos`, `cupos_disponibles` (`CHECK 0 <= disponibles <= maximos`), `estado`, `revision`, `timestamps`, `deleted_at`.

Estados: `borrador → abierta → en_curso → cerrada`; `cancelada` desde `borrador`, `abierta` o `en_curso`. `cerrada` y
`cancelada` son terminales. Se matricula solo en `abierta` o `en_curso`.

`convocatoria_horarios` (`convocatoria_id`, `dia_semana` 1–7, `hora_inicio`, `hora_fin` con `CHECK hora_fin > hora_inicio`,
`lugar` null, `docente` texto null): sin solapamiento del mismo día dentro de la convocatoria (lo valida el servicio).

**Cupos y compatibilidad.** Hoy el cupo vive en `cursos` y las matrículas lo descuentan. Se propone `matriculas.convocatoria_id`
**nullable**: si trae convocatoria, el cupo se descuenta de la convocatoria; si no, del curso como hasta ahora. Las matrículas
existentes no se tocan ni se reasignan. El descuento sigue siendo atómico y bajo bloqueo (mismo patrón que
`MatriculaOperacionesConcurrencia`). La columna `cursos.horario` (texto) queda como descripción pública hasta que la web
pública lea los horarios de la convocatoria.

### 4.2 Calificaciones

`evaluaciones`: `convocatoria_id` (FK), `nombre`, `peso` decimal(5,2) (`CHECK 0 < peso <= 100`), `fecha` (null), `orden`. La
suma de pesos de una convocatoria no puede pasar de 100 y debe ser exactamente 100 para cerrar la convocatoria (lo valida el
servicio).

`calificaciones`: `matricula_id` (FK), `evaluacion_id` (FK), `nota` decimal(5,2) (`CHECK 0 <= nota <= 20`, escala vigesimal),
`observacion` (null, corta), `registrado_por` (FK `users`), `timestamps`; único `(matricula_id, evaluacion_id)`.

Reglas: solo se califica a matrículas **vigentes** de esa convocatoria; con la convocatoria `cerrada` no se modifica nada;
cambiar una nota ya registrada exige un motivo, que va a auditoría (valor anterior y nuevo, sin nombre del estudiante).
**Cierre de acta:** calcula la nota final ponderada de cada matrícula, la guarda en `matriculas.nota_final` (decimal(5,2) null)
y `matriculas.resultado` (`aprobado` \| `desaprobado`, `CHECK`), y pasa la convocatoria a `cerrada`. Nota mínima aprobatoria
11 (en `system_settings`). Es una única transacción, idempotente.

Privacidad: las notas son datos personales del estudiante. La API devuelve solo lo necesario por pantalla; el acta exportada
(PDF/Excel) deja constancia de acceso y no se cachea.

## 5. Permisos nuevos

Formato `modulo.accion`. Son necesarios, no suficientes: cada operación de la API del panel exige además su permiso de entrada
(`panel.servicios.acceder` o `panel.academico.acceder`).

| Panel | Permisos | Admin | Coordinador | Secretaria |
| --- | --- | --- | --- | --- |
| Servicios | `clientes.ver\|crear\|editar`, `prospectos.ver\|crear\|editar\|convertir`, `pipeline.ver\|gestionar`, `proyectos.ver\|crear\|editar`, `soporte.ver\|crear\|gestionar`, `reportes-comerciales.ver\|exportar` | todos | ninguno | ninguno |
| Académico | `convocatorias.ver\|crear\|editar\|cerrar`, `calificaciones.ver\|registrar\|corregir\|exportar` | todos | todos | `convocatorias.ver`, `calificaciones.ver`, `calificaciones.registrar` |

Se mantiene la restricción comercial vigente (`docs/restriccion-acceso-comercial-admin.md`): el panel de servicios es solo de
administración hasta que se decida un rol propio. Los permisos se crean con una migración de datos que no resincroniza roles ni
quita permisos concedidos a mano (como la 000042). Los permisos de entrada a paneles no cambian.

## 6. Auditoría (acciones nuevas, sin datos personales)

`cliente_creado|actualizado|archivado`, `prospecto_creado|actualizado|descartado|convertido`, `oportunidad_creada|etapa_cambiada|
ganada|perdida|reabierta`, `proyecto_creado|estado_cambiado|hito_completado`, `ticket_creado|estado_cambiado|asignado|nota_agregada`,
`convocatoria_creada|abierta|cerrada|cancelada`, `horario_modificado`, `evaluacion_creada|modificada`, `nota_registrada|corregida`,
`acta_cerrada`, `reporte_comercial_exportado`, `acta_exportada`. Los cambios guardan identificadores y estados, nunca el contenido
de campos de texto ni datos de contacto.

## 7. Orden de implementación y criterios de aceptación

| # | Módulo | Depende de | Migraciones |
| --- | --- | --- | --- |
| 1 | Clientes (+ contactos, vínculo con cotizaciones) | — | 000043 permisos, 000044 `clientes` y `cliente_contactos` (**hecho**); `solicitudes_cotizacion.cliente_id` pendiente; `secuencias_codigo` pasa a Proyectos |
| 2 | Prospectos | 1 | 000046 permisos y 000047 tabla (**hecho**) |
| 3 | Pipeline | 1, 2 | 000048 permisos y 000049 tablas (**hecho**) |
| 4 | Proyectos | 1, 3 | 000050 permisos y 000051 `secuencias_codigo`, `proyectos`, `proyecto_hitos` (**hecho**) |
| 5 | Soporte técnico | 1, 4 (opcional) | 000052 |
| 6 | Reportes comerciales | 1 a 5 | ninguna |
| 7 | Convocatorias y horarios | — (independiente del CRM) | 000053, `matriculas.convocatoria_id` |
| 8 | Calificaciones | 7 | 000054, `matriculas.nota_final` y `resultado` |
| 9 | Permisos nuevos | con cada módulo | datos, una por panel |

Un commit por módulo (más el de permisos al inicio de cada panel). **Cada módulo se da por hecho solo si** tiene: migración
reversible probada en SQLite; servicio `Gestion*` con las reglas de §3 y §4; endpoints bajo `/api/panel/v1/<panel>/...` con
permisos, ETag y 422/409/412 coherentes; pruebas de reglas, permisos, privacidad, auditoría y concurrencia lógica; entrada en
la lista cerrada `OPERACIONES` del BFF y en el inventario de rutas; páginas de Next con estados vacío, error y prohibido, y
formularios accesibles; documento de paridad y de seguridad actualizados. Las pruebas de PostgreSQL (CHECK, claves, bloqueos de
transición y cupos) se escriben junto con el módulo y se ejecutan solo con autorización.

## 8. Decisiones abiertas (valor por defecto entre paréntesis)

1. **Docentes:** ¿entidad propia? (No: texto libre en el horario hasta que haya un módulo de docentes.)
2. **Cupos:** ¿por convocatoria con `convocatoria_id` opcional en matrículas? (Sí, como en §4.1; lo existente no se migra.)
3. **Escala de notas y nota mínima:** (0–20 y 11, configurable en `system_settings`.)
4. **Moneda:** (la configurada en el sistema, hoy PEN; se guarda por registro para no reescribir importes antiguos si cambia.)
5. **Origen de prospectos:** ¿las solicitudes de cotización de la web crean prospecto automático? (No: se crean a mano o con
   «convertir cotización»; evita llenar el CRM de contactos sin revisar.)
6. **Cliente y estudiante:** ¿se relacionan si es la misma persona? (No: dominios separados; no hay clave compartida.)
7. **Retención y derecho de eliminación:** cliente archivado se **anonimiza** (nombre, documento y contacto) pasados 5 años sin
   actividad, conservando importes y estados para los reportes. (Sí; se implementa como comando programado en la Ola 4, no ahora.)
8. **Quién ve soporte y CRM:** (solo administración, como hoy; un rol propio del panel de servicios se decide después.)
9. **SLA de soporte:** (horas de §3.5, configurables.)
10. **Reportes comerciales:** ¿solo lectura y exportación, sin tablero configurable? (Sí.)
