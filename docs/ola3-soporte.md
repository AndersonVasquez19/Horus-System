# Ola 3, módulo 5: Soporte técnico (panel de servicios)

15/11/2026. Implementa `docs/ola3-diseno-datos.md` §3.5 con las decisiones por defecto aprobadas. Sin push; sin PostgreSQL.

## API (`/api/panel/v1/servicios/tickets`)

| Método y ruta | Permiso | Notas |
| --- | --- | --- |
| `GET /tickets` | `soporte.ver` | Listado `{data, meta}`; filtros `busqueda` (asunto o código), `estado`, `prioridad`, `abiertos`, `vencidos`, `cliente` (UUID); orden `creado`, `vencimiento`, `codigo`, `asunto`. **Sin descripción ni notas** |
| `GET /tickets/{uuid}` | `soporte.ver` | Detalle con las últimas 100 notas y `ETag` |
| `POST /tickets` | `soporte.crear` + `clientes.ver` | 201. Nace `abierto`, sin asignar, con código `SOP-AAAA-NNNN` y vencimiento por prioridad. `cliente` (UUID, activo) obligatorio; `proyecto` (UUID, **del mismo cliente**, exige `proyectos.ver`) opcional |
| `PUT /tickets/{uuid}` | `soporte.gestionar` | `_revision` obligatoria (428/412). Asunto, descripción, prioridad, canal y proyecto; el cliente no cambia. Un cerrado no se edita (409) |
| `POST /tickets/{uuid}/estado` | `soporte.gestionar` | Transiciones cerradas (abajo) |
| `POST /tickets/{uuid}/asignar` | `soporte.gestionar` | Solo un admin con `soporte.ver`; un cerrado no se reasigna |
| `POST /tickets/{uuid}/notas` | `soporte.gestionar` | 201. `texto` (≤ 2000) e `interna`. **No usa revisión** y no cambia el ticket; un cerrado no admite notas (409) |
| `GET /tickets/opciones/asignados` | `soporte.ver` | Para el selector |

Además del permiso, todo exige el rol `admin` y `panel.servicios.acceder`. Toda respuesta con datos lleva `Cache-Control: no-store, private`.
El nombre del cliente solo sale a quien tiene `clientes.ver`, y el proyecto a quien tiene `proyectos.ver`.

## Estados (`TicketSoporte::TRANSICIONES`)

`abierto → en_progreso → resuelto → cerrado`; `en_espera_cliente` desde `en_progreso` (y vuelve a `en_progreso`). `resuelto → en_progreso`
**reabre solo dentro de 7 días** desde la resolución; pasado ese plazo es 409 («abre otro ticket») aunque todavía se puede cerrar.
`cerrado` es terminal. Se fija `resuelto_at` al resolver (y se borra al reabrir) y `cerrado_at` al cerrar. Repetir el estado actual no
cambia nada.

## SLA

`vence_at` = creación + horas según la prioridad: crítica 4, alta 8, media 24, baja 72 (`TicketSoporte::SLA_HORAS`). Cambiar la
prioridad recalcula desde la **creación**, no desde el cambio. Un ticket es **vencido** si pasó `vence_at` y sigue `abierto`,
`en_progreso` o `en_espera_cliente`; resolver o cerrar lo saca de vencidos. La API entrega `vencido` calculado y el filtro `vencidos`.

## Notas

Solo se crean: no hay rutas para editar ni borrar (405), y se corrige añadiendo otra. Las internas (`interna: true`) no salen de la API
de soporte. La pantalla avisa de no escribir contraseñas ni claves; el servicio **no las detecta**. La auditoría registra solo que se
añadió una nota y si era interna, nunca su texto.

## Datos

Migración `000052` (permisos `soporte.ver|crear|gestionar`, solo `admin`; no resincroniza roles) y `000053` (`tickets_soporte` y
`ticket_notas`; sin cascadas: un cliente con tickets no se elimina y las notas no se borran). El código usa `secuencias_codigo`
(`App\Support\CodigoSecuencial`, compartido con Proyectos). Los `CHECK` de estado, prioridad, canal y la coherencia de
`resuelto_at` y `cerrado_at` **solo se crean en PostgreSQL y están sin ejecutar**; en SQLite los valida el servicio.

**Auditoría sin datos personales:** `ticket_creado` (revisión y prioridad), `ticket_actualizado`, `ticket_estado_cambiado` (revisión,
estado anterior y nuevo), `ticket_asignado` y `ticket_nota_agregada` (solo si es interna): nunca asunto, descripción, notas ni datos del cliente.

## Next

`/admin/servicios/soporte` (lista con prioridad, vencimiento y marca «Vencido») y `/[id]` (datos, descripción, notas y acciones según el
estado: tomar, esperar al cliente, resolver, reabrir, cerrar con confirmación, asignar y edición). El formulario avisa de no escribir
claves. El BFF suma 6 operaciones. Entrada «Soporte técnico» en el menú. Sin cobertura automática de Next.

## Pruebas

`Panel\SoportePanelTest` (15: permisos por acción, alta y auditoría sin datos personales, SLA y recálculo, códigos secuenciales
independientes de proyectos, validaciones, proyecto del mismo cliente, revisión 428/412, ciclo de estados y fechas, reapertura a 7
días, repetición idempotente, asignación, notas sin revisión ni texto en auditoría, validación de notas y cerrado, listado, vencidos y
privacidad) y `Panel\SoporteMigracionTest` (4).

## Pendiente de este módulo

- **PostgreSQL:** `CHECK`, claves foráneas y la concurrencia del contador de códigos, sin ejecutar.
- **SLA configurable:** el diseño lo prevé en `system_settings`; queda como constante hasta decidirlo.
- **Next:** sin runner de pruebas; revisar en un navegador con sesión real.
- **Defaults aplicados sin consultar:** un ticket nace sin asignar; no hay cancelación (un ticket inválido se resuelve con nota); el cliente ve nada: no hay portal.
