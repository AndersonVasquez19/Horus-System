# 06 · Contratos OpenAPI

> **Fase 1 · diseño.** Archivo actualizado: `packages/contracts/openapi/horus-panel-api.yaml` (versión
> `1.3.0-propuesta`). Validado con `js-yaml` (96 rutas, 0 referencias `$ref` rotas). **No se escribió código de servidor.**

## 1. Qué cambia en el contrato

| Concepto | Antes (1.2.0) | Ahora (1.3.0-propuesta) |
| --- | --- | --- |
| Rutas documentadas | 45 | 96 (45 previas + 11 rutas **vigentes** que faltaban + 40 **propuestas**) |
| `convocatorias` y `calificaciones` | Existían en `routes/panel.php` pero **no** figuraban en el contrato (el contrato se había verificado el 12/11, antes de la Ola 3) | Documentadas y marcadas `x-estado: vigente`, con el permiso verificado contra los controladores |
| Docentes, infraestructura, períodos, sesiones, asistencias, pagos, certificados | No existían | Documentados como `x-estado: propuesto` |
| Convención `x-estado` | — | `vigente` = existe en `panel.php`; `propuesto` = diseño sin implementar. Al implementar una fase se cambia a `vigente` en el mismo cambio. |

El `servicios` se conserva en el contrato hasta la Fase 7 (la etiqueta lo anota); no se eliminan sus rutas.

## 2. Convenciones heredadas (no cambian)

- Prefijo `/api/panel/v1`, solo para el BFF; token de servicio `X-Horus-Web-Token`, sesión web, `X-CSRF-TOKEN` en mutaciones,
  `SesionPanelVigente`, permiso de entrada y permiso por acción.
- Errores `{ message, code, errors? }` con `code` estable. Listados `{ data, meta }` con `page`, `per_page`, `sort` en lista blanca.
- Control optimista: `revision` / `If-Match` (`428` si falta, `412` si está obsoleta). Operaciones críticas con
  `Idempotency-Key` (el BFF envía `_clave`).
- Mensajes de validación en español. Identificadores públicos UUID en las entidades nuevas.

## 3. Decisiones de contrato propias de esta fase

1. **No se renombra `convocatorias`.** El contrato público conserva `/academico/convocatorias`; la UI dice «Ediciones».
   Un alias `/academico/ediciones` es una decisión pendiente (README).
2. **Prefijo `/academico` se mantiene** para todas las rutas nuevas aunque el panel pase a ser único en la Fase 7: renombrar
   el prefijo rompería el BFF y los permisos de entrada; si se decide, se hace como cambio aislado con redirecciones.
3. **El enlace virtual es de solo escritura** (`writeOnly`) en `SesionEntrada` y solo se lee en `GET /academico/sesiones/{sesion}/enlace`
   con `sesiones.ver-enlace`; ninguna otra respuesta lo contiene (se prueba recorriendo todas las respuestas públicas y de panel).
4. **Pagos:** `POST …/pagos` y `…/reversar` exigen `Idempotency-Key`; los importes viajan como **cadenas decimales**
   (`"150.00"`), nunca como `number`, para no perder precisión. No hay `PUT`/`DELETE` sobre transacciones: el contrato
   mismo expresa la inmutabilidad.
5. **Endpoints de estudiante/portal: ninguno.** No se documenta ni se crea ninguna ruta privada para estudiantes ni docentes.
6. **`monto_pagado` en matrículas** pasa a «obsoleto» cuando el libro esté activo (Fase 5); se anotará como `deprecated: true`
   en `MatriculaEntrada` en ese momento (el esquema de matrículas del contrato actual es genérico).

## 4. API pública (`horus-api.yaml`) — sin cambios en esta fase

Pendiente de decisión del propietario: **verificación pública de certificados** `GET /api/public/v1/certificados/{codigo}`.
Propuesta: devuelve solo `{ valido, estado, numero, curso, fecha_emision }` y el nombre **abreviado** («Ana P.»); nunca DNI,
nota, correo ni identificadores internos; limitada por `throttle` y sin enumeración (código aleatorio de 24 caracteres,
respuesta idéntica para «no existe» y «anulado» salvo un `estado` explícito). Hasta que se decida, no se añade al contrato público.
El catálogo público de cursos y el asistente **no** exponen ediciones con enlace ni sesiones; el asistente podrá consultar
ediciones disponibles solo mediante endpoints controlados y sin acceso directo a PostgreSQL (alcance futuro).

## 5. Cómo se mantiene

- Todo endpoint nuevo se documenta aquí en el mismo cambio que lo implementa; `InventarioRutasPanelTest` ya exige sesión,
  `SesionPanelVigente` y permisos en cada ruta de `panel.php`.
- Se añadirá una prueba de **paridad contrato ↔ rutas** (Fase 2): toda ruta `x-estado: vigente` existe en el enrutador con el
  mismo método y permiso, y toda ruta de `panel.php` aparece en el contrato. Falla la suite si divergen (el CI está suspendido) (hoy diverge: este es el
  defecto que dejó convocatorias y calificaciones sin documentar).
