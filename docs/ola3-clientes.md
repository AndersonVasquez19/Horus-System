# Ola 3, módulo 1: Clientes (panel de servicios)

15/11/2026. Implementa `docs/ola3-diseno-datos.md` §3.1 con las decisiones por defecto aprobadas. Sin push; sin PostgreSQL.

## API (`/api/panel/v1/servicios/clientes`)

| Método y ruta | Permiso | Notas |
| --- | --- | --- |
| `GET /clientes` | `clientes.ver` | Listado `{data, meta}`; filtros `busqueda` (nombre, correo, documento), `estado`, `tipo`; orden `nombre` o `creado`. **Correo y documento enmascarados**; sin id interno |
| `GET /clientes/{uuid}` | `clientes.ver` | Detalle completo con contactos (principal primero) y `ETag` |
| `POST /clientes` | `clientes.crear` | 201. El responsable inicial es quien lo crea |
| `PUT /clientes/{uuid}` | `clientes.editar` | `_revision` obligatoria (428 sin ella, 412 si cambió). Si el cuerpo trae `contactos`, se **sustituyen todos**; si no, no se tocan |
| `POST /clientes/{uuid}/estado` | `clientes.editar` | `activo` o `inactivo`, idempotente |
| `POST /clientes/{uuid}/responsable` | `clientes.editar` | Solo un admin con `clientes.ver`; `null` quita el responsable |
| `GET /clientes/opciones/responsables` | `clientes.ver` | Para el selector |

Además del permiso, todo exige el rol `admin` y el permiso de entrada `panel.servicios.acceder` (restricción comercial vigente).
Toda respuesta lleva `Cache-Control: no-store, private`.

## Reglas (`GestionClientes`)

- Bloqueo de fila y revisión fuerte `If-Match` (`"cliente-<uuid>-v<n>"`); permiso y rol releídos con datos vigentes.
- Documento único `(tipo, número)` **también frente a clientes inactivos**; sin documento, varios clientes conviven (los nulos
  no colisionan). DNI de 8 dígitos y RUC de 11; una empresa no se identifica con DNI; tipo y número van juntos.
- Correo en minúsculas, documento en mayúsculas y sin espacios, textos sin `<` ni `>`. Hasta 10 contactos y solo uno principal.
- `estado`, `revision`, `identificador` y `responsable_id` no se aceptan en el alta ni en la edición (422).
- Sin cambios reales no avanza la revisión ni se audita.
- **Auditoría sin datos personales:** `cliente_creado`, `cliente_actualizado`, `cliente_estado_actualizado` y
  `cliente_responsable_actualizado` guardan solo revisión y estado, nunca nombre, correo, teléfono ni documento.

## Datos

Migración `000043` (permisos `clientes.ver|crear|editar`, solo `admin`; no resincroniza roles) y `000044` (`clientes` y
`cliente_contactos`; responsable con `ON DELETE SET NULL`, contactos con `RESTRICT`). Los `CHECK` de tipo, estado, documento y
«un solo contacto principal» (índice parcial) **solo se crean en PostgreSQL** y están **sin ejecutar** (requieren autorización y
el clúster aislado); en SQLite los valida el servicio.

## Next

Listado y ficha en `/admin/servicios/clientes[/{id}]`, formulario en diálogo (alta y edición, con contactos), acciones
Responsable / Activar–Inactivar / Editar, y entrada «Clientes» en el menú (permiso `clientes.ver`). El BFF añade siete
operaciones a su lista cerrada; `contactos` se reenvía como lista de objetos acotada (máximo 10, campos de texto de hasta 200
caracteres). Sin cobertura automática de Next: revisar en un navegador con sesión real.

## Pruebas

`Panel\ClientesPanelTest` (13: permisos por acción, alta y normalización, auditoría sin datos personales, listado enmascarado
y filtros, comodines `%`, detalle, validaciones, documento único, revisión 428/412, sin cambios, contactos, estado y
responsable) y `Panel\ClientesMigracionTest` (6: permisos repetibles y reversibles, tablas, unicidad con nulos, sin cascada).

## Vínculo con cotizaciones (hecho)

`POST /api/panel/v1/servicios/cotizaciones/{uuid}/cliente` (permiso `cotizaciones.gestionar`, con `_revision`):

- `cliente`: UUID de un cliente existente (exige además `clientes.ver`), o
- `crear_cliente: true`: crea una **persona** con el nombre, correo y teléfono de la solicitud (exige además `clientes.crear`).

Reglas: el vínculo **no se sustituye ni se quita** (otro cliente o una segunda creación dan 409); repetir el mismo vínculo no
avanza la revisión; **nunca se fusiona** con un cliente existente por coincidencia de correo (se responde 422 pidiendo vincular
ese cliente). El historial de la solicitud reutiliza el evento `informacion_actualizada` con `campos: ['cliente_id']`, porque su
columna `evento` es un enum cerrado y no se altera. El detalle de la cotización entrega `cliente` solo a quien tiene
`clientes.ver`. La migración `000045` añade `solicitudes_cotizacion.cliente_id` (nulo, `RESTRICT`); en SQLite usa
`ALTER TABLE … ADD COLUMN` porque `Schema::table` con clave foránea reconstruye la tabla y borra los triggers que hacen de `CHECK`
(su `down()` en SQLite solo quita el índice: la base de pruebas se recrea entera). Las solicitudes existentes no se tocan.
Pruebas: `Panel\CotizacionClientePanelTest` (7). En Next: botón «Cliente» (buscar y vincular, o crear) y enlace al cliente en
el expediente.

## Pendiente de este módulo

- **Archivado** (`softDeletes` ya está en la tabla): llega con Proyectos y Soporte, cuando existan dependencias que comprobar;
  mientras tanto un cliente se inactiva.
- **PostgreSQL:** `CHECK`, índice parcial, la clave foránea de cotizaciones y el bloqueo de concurrencia, sin ejecutar.
- **Next:** sin runner de pruebas; revisar en un navegador con sesión real.
