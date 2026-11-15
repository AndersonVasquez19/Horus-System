# Ola 3, módulo 4: Proyectos (panel de servicios)

15/11/2026. Implementa `docs/ola3-diseno-datos.md` §3.4 con las decisiones por defecto aprobadas. Sin push; sin PostgreSQL.

## API (`/api/panel/v1/servicios/proyectos`)

| Método y ruta | Permiso | Notas |
| --- | --- | --- |
| `GET /proyectos` | `proyectos.ver` | Listado `{data, meta}`; filtros `busqueda` (nombre o código), `estado`, `activos`, `cliente` (UUID); orden `creado`, `nombre`, `codigo`, `fin`. Cada fila trae el avance de hitos |
| `GET /proyectos/{uuid}` | `proyectos.ver` | Detalle, `hitos_detalle` y `ETag` |
| `POST /proyectos` | `proyectos.crear` | 201. Nace `planificado` con código `PRY-AAAA-NNNN`; el responsable inicial es quien lo crea. `cliente` (UUID, activo, exige `clientes.ver`) **o** `oportunidad` (UUID, ganada, exige `pipeline.ver`); las dos a la vez son 422 |
| `PUT /proyectos/{uuid}` | `proyectos.editar` | `_revision` obligatoria (428/412). Un proyecto cerrado no se edita (409); cliente y oportunidad no cambian |
| `POST /proyectos/{uuid}/estado` | `proyectos.editar` | Transiciones cerradas (abajo) |
| `POST /proyectos/{uuid}/responsable` | `proyectos.editar` | Solo un admin con `proyectos.ver` |
| `POST /proyectos/{uuid}/hitos` | `proyectos.editar` | 201. Hasta 50 por proyecto |
| `PUT /proyectos/{uuid}/hitos/{hito}` | `proyectos.editar` | Nombre y fecha objetivo |
| `POST /proyectos/{uuid}/hitos/{hito}/completar` | `proyectos.editar` | `completado` true o false; repetir no cambia nada |
| `POST /proyectos/{uuid}/hitos/{hito}/eliminar` | `proyectos.editar` | |
| `GET /proyectos/opciones/responsables` | `proyectos.ver` | Para el selector |

Además del permiso, todo exige el rol `admin` y `panel.servicios.acceder`. Toda respuesta con datos lleva `Cache-Control: no-store, private`.
El nombre del cliente solo sale a quien tiene `clientes.ver`; el enlace a la oportunidad, a quien tiene `pipeline.ver`. El detalle de
una oportunidad muestra su `proyecto` (código) a quien tiene `proyectos.ver`.

## Estados (`Proyecto::TRANSICIONES`)

`planificado → en_curso → completado`; `pausado` desde `en_curso` (y vuelve a `en_curso`); `cancelado` desde `planificado`, `en_curso`
o `pausado`. `completado` y `cancelado` son terminales (409 al intentar salir). Al pasar a `en_curso` sin fecha de inicio se toma
el día de Lima; al `completado` se fija `fecha_fin_real` (día de Lima) y en cualquier otro estado queda vacía. Repetir el estado
actual no cambia nada.

## Código y oportunidad

- **Código** `PRY-AAAA-NNNN` (año de Lima): se toma de `secuencias_codigo` con la fila bloqueada dentro de la transacción del alta;
  nunca se reutiliza, ni siquiera si el proyecto va a la papelera.
- **Desde una oportunidad ganada** copia cliente, título (como nombre) y monto (como presupuesto) salvo que el alta indique otros
  valores. Una oportunidad da **un solo proyecto**: repetir devuelve el mismo (200) sin otro código ni auditoría, y una carrera
  entre dos peticiones la resuelve el `UNIQUE`. Una oportunidad no ganada es 409.

## Hitos y avance

El avance (`hitos.avance`, % entero; `null` sin hitos) **se calcula** con los hitos completados, no se guarda. Los hitos no tienen
revisión propia: cada cambio exige y avanza la `If-Match` del proyecto, así que dos personas editando a la vez reciben 412. Sobre un
proyecto cerrado no se tocan hitos (409). Los hitos se identifican por UUID; el id interno no sale.

## Datos

Migración `000050` (permisos `proyectos.ver|crear|editar`, solo `admin`; no resincroniza roles) y `000051` (`secuencias_codigo`,
`proyectos` y `proyecto_hitos`; sin cascadas: un cliente con proyectos no se elimina). Los `CHECK` de estado, presupuesto ≥ 0, fechas
coherentes y «fin real si y solo si completado» **solo se crean en PostgreSQL y se validaron allí el 15/11/2026** (`docs/ola3-postgresql.md`); en SQLite los valida el
servicio. La moneda es `PEN`.

**Auditoría sin datos personales:** `proyecto_creado` (revisión e indicador de origen), `proyecto_actualizado`,
`proyecto_estado_cambiado` (revisión, estado anterior y nuevo), `proyecto_responsable_actualizado`, `hito_agregado`, `hito_actualizado`,
`hito_completado`, `hito_reabierto` y `hito_eliminado` (solo revisión): nunca nombres, descripciones ni datos del cliente.

## Archivado de clientes

Los clientes ya se **inactivan** (`cliente_estado`); un cliente inactivo no admite proyectos ni oportunidades nuevas y conserva el
historial, y ninguno se elimina. No se añade un estado «archivado» distinto: la anonimización a los 5 años queda para Ola 4
(decisión 7 del diseño).

## Next

`/admin/servicios/proyectos` (lista con avance) y `/[id]` (datos, origen, hitos con casilla, y acciones según el estado: iniciar,
pausar, reanudar, completar, cancelar con confirmación, responsable y edición). «Nuevo proyecto» ofrece partir de un cliente o de una
oportunidad ganada; la ficha de una oportunidad ganada sin proyecto trae «Crear proyecto». El BFF suma 10 operaciones (y la lectura
acotada del listado de oportunidades para el selector). Entrada «Proyectos» en el menú. Sin cobertura automática de Next.

## Pruebas

`Panel\ProyectosPanelTest` (16: permisos por acción, alta y auditoría sin datos personales, códigos secuenciales sin reutilización,
validaciones, cliente inactivo, revisión 428/412, estados y fechas automáticas, responsable, hitos con avance y auditoría, hitos con
revisión, proyecto cerrado y límite, proyecto desde oportunidad idempotente, oportunidad no ganada, permiso del pipeline, listado y
privacidad) y `Panel\ProyectosMigracionTest` (4: permisos, forma de las tablas, unicidades, `down`).

## Pendiente de este módulo

- **PostgreSQL:** validado el 15/11/2026 en el clúster aislado; ver `docs/ola3-postgresql.md`.
- **Next:** sin runner de pruebas; revisar en un navegador con sesión real.
- **Defaults aplicados sin consultar:** moneda única `PEN`; completar un proyecto no exige hitos terminados; los hitos no se reordenan.
