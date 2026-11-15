# Ola 3, módulo 2: Prospectos (panel de servicios)

15/11/2026. Implementa `docs/ola3-diseno-datos.md` §3.2 con las decisiones por defecto aprobadas. Sin push; sin PostgreSQL.

## API (`/api/panel/v1/servicios/prospectos`)

| Método y ruta | Permiso | Notas |
| --- | --- | --- |
| `GET /prospectos` | `prospectos.ver` | Listado `{data, meta}`; filtros `busqueda` (nombre, empresa, correo), `estado`, `origen`, `seguimiento_pendiente`; orden `creado`, `nombre` o `seguimiento`. **Correo enmascarado, sin teléfono**, sin id interno |
| `GET /prospectos/{uuid}` | `prospectos.ver` | Detalle completo y `ETag`; `cliente` solo si además hay `clientes.ver` |
| `POST /prospectos` | `prospectos.crear` | 201. Nace `nuevo`; el responsable inicial es quien lo crea |
| `PUT /prospectos/{uuid}` | `prospectos.editar` | `_revision` obligatoria (428/412). Un convertido no se edita (409). Un prospecto nacido de una cotización conserva su origen |
| `POST /prospectos/{uuid}/estado` | `prospectos.editar` | `estado` y, al descartar, `motivo_descarte` obligatorio |
| `POST /prospectos/{uuid}/responsable` | `prospectos.editar` | Solo un admin con `prospectos.ver` |
| `POST /prospectos/{uuid}/convertir` | `prospectos.convertir` | Crea el cliente o vincula `cliente` (UUID). Idempotente |
| `POST /cotizaciones/{uuid}/prospecto` | `prospectos.crear` + `cotizaciones.ver` | Crea el prospecto de una solicitud (201) o devuelve el que ya tenía (200) |
| `GET /prospectos/opciones/responsables`, `…/servicios` | `prospectos.ver` / crear o editar | Para los selectores |

Además del permiso, todo exige el rol `admin` y `panel.servicios.acceder`. Toda respuesta con datos lleva `Cache-Control: no-store, private`.

## Estados y transiciones (`Prospecto::TRANSICIONES`)

`nuevo → contactado → calificado`; `descartado` desde cualquiera de los tres (exige motivo) y `descartado → nuevo` reabre y borra el
motivo. `convertido` es terminal y **solo** se alcanza con la conversión, nunca con `estado` (422). Repetir el estado actual no cambia
nada; una transición no permitida es 409. No se salta ningún paso.

## Conversión

Desde `calificado` (409 en otro estado), en una sola transacción: crea un cliente **persona** (o **empresa**, si el prospecto trae
empresa, con la persona de contacto como contacto principal) o vincula uno existente, y marca el prospecto `convertido`. Reglas:

- **Idempotente:** sobre un prospecto ya convertido devuelve el mismo resultado sin crear otro cliente ni otra auditoría, aunque
  llegue con una revisión anterior (el reintento de una respuesta perdida).
- **Sin fusiones silenciosas:** si ya existe un cliente con ese correo (también en papelera) responde 422 y pide vincularlo.
- **Todo o nada:** si falta `clientes.crear` (o falla algo) el prospecto sigue `calificado`, sin cliente.
- La oportunidad opcional del diseño llega con el módulo Pipeline.

## Datos

Migración `000046` (permisos `prospectos.ver|crear|editar|convertir`, solo `admin`; no resincroniza roles) y `000047` (tabla
`prospectos`; la solicitud de cotización es `UNIQUE`: una solicitud, un prospecto; sin cascadas). Los `CHECK` de origen, estado, la
coherencia «motivo si y solo si descartado» y «convertido tiene cliente» **solo se crean en PostgreSQL y están sin ejecutar**; en
SQLite los valida el servicio.

**Auditoría sin datos personales:** `prospecto_creado` (revisión y origen), `prospecto_actualizado`, `prospecto_estado_actualizado`
(revisión, estado anterior y nuevo; nunca el motivo), `prospecto_responsable_actualizado` y `prospecto_convertido` (revisión y si se
creó el cliente).

## Next

Listado y ficha en `/admin/servicios/prospectos[/{id}]`, formulario en diálogo, acciones contextuales (avanzar, descartar con motivo,
reabrir, convertir con «cliente nuevo» o «cliente existente», responsable y edición), botón «Crear prospecto» en el expediente de la
cotización y entrada «Prospectos» en el menú. El BFF suma 7 operaciones a su lista cerrada. Sin cobertura automática de Next.

## Pruebas

`Panel\ProspectosPanelTest` (17: permisos por acción, alta y auditoría sin datos personales, validaciones, listado enmascarado y
filtros, revisión 428/412, transiciones y descarte, responsable, conversión —persona, empresa, idempotente, existente, sin fusión,
todo o nada—, cotización idempotente y origen reservado) y `Panel\ProspectosMigracionTest` (4).

## Pendiente de este módulo

- **PostgreSQL:** `CHECK`, la restricción única con la cotización y la conversión concurrente, sin ejecutar.
- **Oportunidad al convertir:** con Pipeline (módulo 3).
- **Next:** sin runner de pruebas; revisar en un navegador con sesión real.
