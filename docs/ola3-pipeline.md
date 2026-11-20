# Ola 3, módulo 3: Pipeline de oportunidades (panel de servicios)

> **Documento histórico (módulo retirado el 19/11/2026).** El panel de servicios tecnológicos y sus módulos CRM/soporte fueron retirados de Horus System; este documento se conserva únicamente como referencia previa. Ver [`retiro-panel-servicios.md`](retiro-panel-servicios.md).

15/11/2026. Implementa `docs/ola3-diseno-datos.md` §3.3 con las decisiones por defecto aprobadas. Sin push; sin PostgreSQL.

## API (`/api/panel/v1/servicios/oportunidades`)

| Método y ruta | Permiso | Notas |
| --- | --- | --- |
| `GET /oportunidades` | `pipeline.ver` | Listado `{data, meta}`; filtros `busqueda` (título), `etapa`, `abiertas`, `cliente` (UUID); orden `creado`, `titulo`, `monto`, `cierre` |
| `GET /oportunidades/tablero` | `pipeline.ver` | Una columna por etapa: `total`, `monto` y las 25 tarjetas más recientes (con su `revision`) |
| `GET /oportunidades/{uuid}` | `pipeline.ver` | Detalle completo, `historial` de etapas y `ETag` |
| `POST /oportunidades` | `pipeline.gestionar` + `clientes.ver` | 201. Nace en `nueva` con probabilidad 10; el responsable inicial es quien la crea. `cliente` (UUID, activo) obligatorio; `servicio` y `cotizacion` (UUID, esta última exige `cotizaciones.ver`) opcionales |
| `PUT /oportunidades/{uuid}` | `pipeline.gestionar` | `_revision` obligatoria (428/412). Título, monto, probabilidad y cierre; el cliente no cambia. Una cerrada no se edita (409) |
| `POST /oportunidades/{uuid}/etapa` | `pipeline.gestionar` | `etapa` y, al perder, `motivo_perdida` obligatorio. Es lo que usa el tablero |
| `POST /oportunidades/{uuid}/responsable` | `pipeline.gestionar` | Solo un admin con `pipeline.ver` |
| `POST /prospectos/{uuid}/convertir` | `prospectos.convertir` | Con `oportunidad {titulo, monto_estimado?, fecha_cierre_estimada?}` abre además la oportunidad (exige `pipeline.gestionar`) |
| `GET /oportunidades/opciones/responsables` | `pipeline.ver` | Para el selector |

Además del permiso, todo exige el rol `admin` y `panel.servicios.acceder`. Toda respuesta con datos lleva `Cache-Control: no-store, private`.
El nombre del cliente solo sale a quien además tiene `clientes.ver`; la cotización, a quien tiene `cotizaciones.ver`.

## Etapas y transiciones (`Oportunidad::TRANSICIONES`)

`nueva → calificada → propuesta → negociacion → ganada`; `perdida` desde cualquiera de las cuatro abiertas (exige motivo). Se avanza
de a una etapa: no se salta ni se retrocede (409). `ganada` y `perdida` son terminales salvo **reabrir**, que las lleva a `nueva`,
borra el motivo y la fecha de cierre y queda en el historial. Repetir la etapa actual no cambia nada. La probabilidad toma el valor
de la etapa (10, 25, 50, 75, 100 y 0) al entrar en ella y se puede ajustar con `PUT` mientras la oportunidad esté abierta.

Cada alta y cada cambio de etapa añade una fila a `oportunidad_historial` (etapas, autor y fecha; **sin textos libres**).

## Conversión de prospecto con oportunidad

El diálogo «Convertir en cliente» ofrece «Abrir también una oportunidad en el pipeline» a quien tiene `pipeline.gestionar`. Todo ocurre
en la transacción de la conversión: cliente (nuevo o vinculado), oportunidad `nueva` del cliente (con el servicio de interés y la
cotización de origen del prospecto, si la hay) y prospecto `convertido`. Si falta el permiso del pipeline o algo falla, no queda
cliente, oportunidad ni cambio de estado. Repetir la conversión no abre una segunda oportunidad.

## Datos

Migración `000048` (permisos `pipeline.ver|gestionar`, solo `admin`; no resincroniza roles) y `000049` (`oportunidades` y
`oportunidad_historial`; sin cascadas: un cliente con oportunidades no se elimina). Los `CHECK` de etapa, probabilidad 0–100, monto
≥ 0, «motivo si y solo si perdida» y «`cerrada_at` si y solo si cerrada» **solo se crean en PostgreSQL y se validaron allí el 15/11/2026** (`docs/ola3-postgresql.md`); en
SQLite los valida el servicio. La moneda es `PEN`.

**Auditoría sin datos personales:** `oportunidad_creada`, `oportunidad_actualizada`, `oportunidad_etapa_cambiada`, `oportunidad_ganada`,
`oportunidad_perdida` y `oportunidad_reabierta` (revisión, etapa anterior y nueva; nunca título, motivo ni datos del cliente),
`oportunidad_responsable_actualizado`, y `prospecto_convertido` suma el indicador `oportunidad_creada`.

## Next

`/admin/servicios/oportunidades` muestra el **tablero Kanban** (vista por defecto) o la **lista** (`?vista=lista`); la ficha
`/[id]` trae datos, motivo de pérdida, origen e historial. Una tarjeta se mueve con su selector «Mover a…» (accesible con teclado) o
arrastrándola a otra columna; la pérdida abre un diálogo de motivo y el cambio viaja a `POST …/etapa` con la revisión de la tarjeta,
así que Laravel valida la transición y los desfases (412). El BFF suma 5 operaciones y el objeto anidado `oportunidad` de la
conversión (solo escalares, texto acotado). Entrada «Pipeline» en el menú. Sin cobertura automática de Next.

## Pruebas

`Panel\OportunidadesPanelTest` (16: permisos por acción, alta y auditoría sin datos personales, validaciones, cliente inactivo,
revisión 428/412, recorrido completo con historial, sin saltos ni retrocesos, pérdida con motivo y reapertura, repetición idempotente,
responsable, listado y privacidad del cliente, tablero, y la conversión de prospecto con oportunidad: atómica, idempotente, sin
permiso y con validación) y `Panel\OportunidadesMigracionTest` (4: permisos, forma de las tablas, claves foráneas sin cascada, `down`).

## Pendiente de este módulo

- **PostgreSQL:** validado el 15/11/2026 en el clúster aislado; ver `docs/ola3-postgresql.md`.
- **Next:** sin runner de pruebas; revisar el arrastre y el tablero en un navegador con sesión real.
- **Sin decidir (defaults aplicados):** moneda única `PEN`; no se retrocede de etapa; reabrir vuelve a `nueva`.
