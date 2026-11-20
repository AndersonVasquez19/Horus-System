# Sprint 19 — solicitudes privadas de cotización

## Inventario previo y decisión autorizada

Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`. Índice inicialmente vacío. Preexistentes: contexto modificado y documento PostgreSQL sin seguimiento; se preservan junto a ambos stashes. Snapshot SHA-256 de 611 archivos iniciales fuera del repositorio.

Se contrastan AGENTS, CLAUDE, contexto y Sprints 18/18.1/18.2 con código. No había modelo, tabla ni ruta de cotizaciones/contactos comerciales. `/contactos` es GET de una vista con formulario cliente sin POST comercial. `ChatKnowledge` orienta hacia contacto sin registrar expedientes. `ProcesoInterno` es una tarea interna, no un cliente o solicitud. Los permisos `seguimientos.*` no acreditan entidad comercial. Usuarios son operadores, no clientes; estudiantes no se reutilizan como contactos.

Consulta: orientación inicial sin expediente ni oferta. Solicitud de cotización: expediente privado de necesidad y contacto, sin importe ni compromiso. Presupuesto formal: documento/condiciones/precios y aceptación que este sprint no modela.

El propietario autorizó expresamente solo `recibida → en_revision`; repetición del estado actual es no-op. Cotizada, aceptada, rechazada, cancelada y cierres se posponen hasta reglas aprobadas. No se generan aprobaciones, importes, PDF ni obligaciones comerciales.

Se reutilizan grupo privado web/sesión/CSRF, middleware Spatie, Form Requests, patrón de transacción/revisión y `AuditLog::record`. No se alteran servicios/editorial/catálogos, frontend, cursos, chat, Python o configuraciones. Nuevos permisos solo admin por defecto, con constante y migración aditiva. Desarrollo y migraciones únicamente mediante PHPUnit SQLite en memoria.

Plan: dos tablas privadas (expediente e historial), API JSON acotada, snapshot administrativo del servicio opcional, revisiones e idempotencia, pruebas de permisos/rollback/privacidad, suite completa y calidad. Ninguna migración real, conexión PostgreSQL, proveedor, activación, commit o push.

## Modelo y migraciones

`SolicitudCotizacion` / `solicitudes_cotizacion`: PK interna; UUID estable generado en servidor; código único `SOL-` más UUID en mayúsculas (40 caracteres); recepción y creador asignados por servidor; asociación opcional con servicio; necesidad privada (5000), nombre de contacto (120), correo (254) y teléfono (30), al menos uno de los dos últimos; estado, responsable opcional, fecha del último seguimiento, próxima fecha opcional, revisión y timestamps. No existe cliente maestro ni se duplica un estudiante/usuario como cliente. No se solicitan documentos de identidad, dirección, archivos o secretos. Fecha de recepción significa registro privado, no una supuesta recepción pública anterior.

La asociación conserva FK `servicio_id`, `servicio_uuid` y `servicio_nombre_historico` del **nombre administrativo**, capturado bajo bloqueo. Puede agregarse al alta o posteriormente si no existía. Una vez asociada no se sustituye ni se elimina por API; reenviar el mismo UUID no refresca el nombre. Retiro, archivo, inactivación o cambio de nombre del servicio no alteran la solicitud. Se admite alta privada sobre servicio histórico/inactivo: no promete disponibilidad. No se consulta catálogo ni se modifica/publica el servicio. La corrección de una asociación requiere un contrato histórico futuro explícito.

`CotizacionHistorial` / `cotizacion_historial`: expediente, revisión única, evento, operador, metadatos mínimos, nota privada opcional (solo seguimiento, 3000), fecha y reserva idempotente de seguimiento oculta. Sin endpoints de eliminación/edición de historia ni borrado de expedientes. No hay duplicación histórica de contactos o necesidad; sus ediciones registran nombres de campos, no valores anteriores. Esto conserva trazabilidad de operaciones, no un archivo completo de versiones personales.

`2026_10_29_000027_create_solicitudes_cotizacion_tables.php` crea tablas vacías. UUID/código y ámbito creador/clave únicos; historial con unicidad expediente/revisión y expediente/operador/clave. Enum restringe estados/eventos. CHECK PostgreSQL para revisión positiva, contacto mínimo y snapshot completo; triggers INSERT/UPDATE equivalentes en SQLite. FK RESTRICT desde historial a expediente y desde expediente a servicio impiden borrados históricos. Usuarios usan SET NULL; ámbitos técnicos permanecen aunque se elimine una cuenta. No se afirma inmutabilidad ante SQL privilegiado.

Índices estado/id, responsable/id, servicio_uuid/id, recepción/id y próxima fecha/id apoyan filtros; servicio_id/creado_por/actor_id apoyan FK, y unicidad expediente/revisión apoya el historial. Eficacia/planes requieren PostgreSQL. `down` del esquema es destructivo y no está autorizado en bases reales.

## Permisos y privacidad

`COTIZACIONES_PERMISSIONS` en el seeder: `cotizaciones.ver`, `.crear`, `.editar`, `.gestionar`, únicamente admin por defecto. `000028_add_cotizaciones_permissions.php` los agrega al admin existente sin crear usuarios/roles ni resincronizar concesiones. Repetible; down conserva permisos como migraciones anteriores. Coordinación/secretaría requieren concesión explícita. Servicios y `seguimientos.*` no dan acceso comercial. Ver permite todos los expedientes, no solo los asignados; responsable es filtro y no frontera de autorización.

Crear/editar/gestionar no conceden ver. Las mutaciones devuelven solo `{solicitud:{identificador,codigo}}` y ETag: un creador sin lectura no recibe contacto/nota/necesidad añadidos por otro operador al reconciliar. Detalle/listado/historial tienen proyecciones explícitas y `Cache-Control: no-store, private`. Los identificadores internos, ámbitos, hashes y huellas no salen en HTTP. La exportación administrativa existente tiene tablas permitidas fijas y excluye estas nuevas tablas. No se agregó exportación comercial.

Asignar responsable exige personal actual con `cotizaciones.ver`, sin concederlo automáticamente. Revocar acceso impide leer aun siendo responsable; no reasigna ni cambia estados. El texto libre debe contener solo datos necesarios: no hay detector semántico de secretos. Operación real requiere protección de almacenamiento, backups, logs y configuración de errores/HTTPS, además de permisos.

## API privada

Grupo `/admin`: sesión, rol de personal, CSRF y middleware Spatie por operación. Consumir JSON con `Accept: application/json`; `{uuid}` es UUID del expediente. No hay rutas públicas ni pantallas nuevas.

| Método / ruta | Permiso | Contrato |
| --- | --- | --- |
| GET `/admin/cotizaciones` | ver | Paginador mínimo y filtros, orden id ascendente |
| POST `/admin/cotizaciones` | crear | Contacto/necesidad/servicio_uuid opcional; Idempotency-Key; 201 alta, 200 repetición |
| GET `/admin/cotizaciones/{uuid}` | ver | Detalle privado y ETag |
| PUT `/admin/cotizaciones/{uuid}` | editar | Cambios parciales de contacto/necesidad y primera asociación; If-Match |
| PATCH `/admin/cotizaciones/{uuid}/estado` | gestionar | Estado autorizado; If-Match |
| PATCH `/admin/cotizaciones/{uuid}/responsable` | gestionar | responsable_id entero o null; If-Match |
| POST `/admin/cotizaciones/{uuid}/seguimientos` | gestionar | nota y proximo_seguimiento opcional; If-Match e Idempotency-Key |
| GET `/admin/cotizaciones/{uuid}/historial` | ver | Historial paginado, revisión ascendente |

Listado: filtros exactos `estado`, `responsable_id`, `servicio_uuid`, `codigo`; `recibida_desde`/`recibida_hasta` como días inclusivos Lima convertidos a intervalo UTC, individualmente opcionales. No busca datos personales. Omite contacto/necesidad/notas. Listado e historial: `page` 1–1000000, `por_pagina` 1–50 (15 por defecto), COUNT y LIMIT/OFFSET SQL. Vacío: total cero y `data=[]`; no materializa todo ni consulta por tarjeta.

Form Requests validan listas permitidas; snapshot, identidad, revisión, creador y estado/responsable en alta/PUT deben estar ausentes incluso null. Otros campos desconocidos no se asignan ni entran a huella. HTML se rechaza en texto libre; frontend futuro debe escapar texto. SQL enlazado. Invitado JSON 401, permiso/rol 403, UUID desconocido o ID numérico 404, validación 422, transición/asociación/clave incompatible 409, If-Match ausente 428, revisión vieja 412. Error inesperado conserva 500 del manejador existente, sin captura general. Configuración HMAC ausente falla cerrada con 503 al generar huella.

## Estados, transacciones e idempotencia

Solo recibida → en_revision. Regresar devuelve 409; cotizada/aceptada/rechazada/cancelada/cerrada no son estados válidos (422). Repetición del estado actual, información idéntica o mismo responsable no modifica versión, fechas, historial ni auditoría. No existe cierre comercial ni su evento por falta de reglas aprobadas.

`GestionCotizaciones` revalida actor fresco, rol y permiso dentro de transacción. Mutación existente bloquea/relee expediente y exige ETag fuerte `"cotizacion-{uuid}-v{revision}"`, sin wildcard, listas o ETag débil. Revisión positiva, monotónica, máximo 2147483647. Historial/auditoría se guardan atómicamente; fallo revierte todo, incluida reserva idempotente.

Creación usa `Idempotency-Key` UUID por intención; almacena SHA-256 de clave y HMAC-SHA256 de datos validados ordenados con opcionales normalizados a null. APP_KEY solo en servidor; no segunda copia del cuerpo. Ámbito operador + operación crear, protegido por índice único. Misma clave/cuerpo devuelve misma identidad y ETag actual sin escribir; cuerpo distinto 409; nueva clave admite otra solicitud del mismo contacto. Unicidad accidental se captura solo fuera de transacción fallida y reconcilia en otra transacción con bloqueo, reautorización y comparación. Si no existe la clave, conflicto de identidad. No reintenta crear otra identidad.

Seguimiento: ámbito expediente + operador + operación seguimiento, UUID obligatorio, índice único adicional y bloqueo del expediente. Misma clave/cuerpo con revisión actual es no-op; distinto cuerpo 409. **If-Match se comprueba antes del replay:** retransmisión con versión antigua devuelve 412. Releer y decidir antes de repetir con la misma clave; no adoptar versión nueva automáticamente. Claves distintas permiten notas legítimas iguales. Omitir próxima fecha conserva la vigente; null borra; fecha pasada es válida para pendientes vencidos, sin avisos/jobs automáticos.

Retención técnica: reserva y huella durante toda la vida del expediente/historial, sin purga/expiración automática. No es una política legal de retención personal: pendiente antes de datos reales/recepción pública. Rotar APP_KEY conserva la reserva y evita duplicados, pero huellas antiguas dejan de comparar: 409 y conciliación manual. No usar nueva clave automáticamente ni modificar APP_KEY para operar este sprint.

Tras resultado incierto, creación puede reconciliarse deliberadamente con misma clave/cuerpo; cambios existentes exigen consulta/comparación. GET no identifica por sí mismo al causante de un cambio coincidente. No hay reintentos automáticos, correos, HTTP externo, jobs, avisos o documentos comerciales.

## Auditoría e historial

Eventos `creada`, `informacion_actualizada`, `estado_modificado`, `responsable_asignado`, `seguimiento_registrado`; auditoría con prefijo `cotizacion_`. Desasignar también usa responsable_asignado. Una fila de historia y una auditoría por cambio efectivo; notas solo en historia privada. Metadatos: revisión, nombres de campos modificados, estados o responsable ID según operación; nunca valores de contacto/necesidad/notas. Lecturas no escriben ni auditan.

Eliminar usuario conserva expediente/historia y nulifica FK de creador/responsable/actor. Ese SET NULL no genera evento comercial ni revisión: ETag protege escrituras de este módulo, no efectos administrativos externos sobre usuarios. La historia conserva ámbitos técnicos internos e IDs en metadatos de asignación; no se publica. No hay política de borrado legal implementada.

## Pruebas

`tests/Feature/CotizacionTest.php`: SQLite `:memory:` y guardas existentes, contacto sintético `example.test`, sesión/caché/correo array, cola sync, HTTP no simulado bloqueado y Mail/Queue falsos. Cobertura: alta/validación, permisos separados/invitados/roles, reautorización, listas/filtros, historia, asociación/snapshot/archivo/FK, duplicados/retención/rotación, revisiones obsoletas/cruzadas, no-op, transiciones rechazadas, rollback, privacidad y lectura sin escrituras/efectos externos.

Primer pase: 39 pruebas/442 aserciones; ampliación: 45/532. Larastan detectó casts de fechas no inferidos por la configuración actual del método casts; se adoptó propiedad casts como modelos existentes, sin supresiones, baseline ni cambios de configuración. El pase posterior aprobó. No frontend/build/smoke porque no cambió frontend.

| Comprobación final | Resultado |
| --- | --- |
| `php artisan test --compact --filter=CotizacionTest` | **46 aprobadas, 540 aserciones**, 33,46 s |
| `php artisan test --compact` | **1057 aprobadas, 7283 aserciones**, 407,62 s; exclusivamente SQLite `:memory:` |
| `php vendor/bin/phpstan analyse --no-progress` | **Nivel 6, sin errores** |
| Pint aplicado y `--test` sobre 11 PHP propios | **Aprobado**, sin reformateo global |
| `git diff --check` | **Aprobado**, avisos LF/CRLF conocidos |
| Revisión de secretos | Sin coincidencias de claves privadas/tokens de proveedor/URI con credenciales; pruebas solo sintéticas |

La suite completa incluye la convivencia con servicios/cursos y las salvaguardas existentes; no se repitieron pruebas contra PostgreSQL ni frontend. No se suprimieron pruebas ni se relajaron controles para aprobar.

## Riesgos y aceptación PostgreSQL

SQLite no demuestra locks, DDL, UUID nativo, colación, contención, aislamiento ni planes PostgreSQL 18. Pendientes: revisión/autorización de 000027/000028 y dependencias (usuarios, Spatie, servicios 000025/000026), destino/backup/ventana; aceptar FK/CHECK/enums/índices/timestampTz y límites, planes y OFFSET grande. No ejecutar migración general que arrastre 000022 suspendida.

Ensayar en destino desechable autorizado carreras por misma clave/cuerpo y por cuerpos distintos, dos operadores con igual ETag, seguimiento simultáneo, conflicto de identidad, asociación durante renombrado/retiro, rollback, asignación y revocación concurrentes. Alta bloquea servicio y después inserta expediente; edición bloquea expediente y después servicio si primera asociación. Medir deadlocks y restricciones, sin reintentos automáticos. La ruta de recuperación de unicidad no demuestra por SQLite una carrera real.

Reautorización fresca no serializa revocaciones Spatie posteriores al chequeo. COUNT y página son lecturas separadas, sin snapshot garantizado. Eliminación de usuario puede nulificar vínculos sin revisión comercial. Sin control por cartera/empresa, cifrado de campos o política legal de retención: requieren diseño/aceptación operativa. No se abrió SQL ni conexión a habitual/ensayo/Testing, no se ejecutó 000022 ni otra migración real y no se reanudó S7.

## Integraciones futuras y Sprint 19.1

- Panel: aprobación visual independiente; listado/filtros/detalle/historial y acciones según permisos/estado, CSRF, ETag, borradores conservados ante 412, UUID de intención y conciliación explícita de incertidumbre.
- Web: contrato público separado con consentimiento/finalidades/retención, validación/tamaño, antispam/rate limit y confirmación de recepción sin precio/aprobación. No reutilizar endpoints privados ni exponer búsqueda/historial por código.
- Negocio: aprobar transiciones/cierres, importes/impuestos/condiciones/documentos/aceptación antes de ofertas formales. Las aprobaciones internas genéricas no son aprobación comercial.
- Chatbot PHP/Advisor: orientación con catálogo autorizado y futura derivación; no enviar contacto/necesidad/notas/expedientes a FastAPI/OpenAI sin diseño y autorización específicos. Sin conexión/exportación/ingestión comercial en este sprint.

## Inventario de entrega

13 archivos propios: modelos SolicitudCotizacion/CotizacionHistorial, servicio GestionCotizaciones, controlador CotizacionController, requests GuardarCotizacionRequest/GestionarCotizacionRequest, migraciones 000027/000028, prueba CotizacionTest, este informe, rutas web, seeder de permisos y contexto. Sin frontend, cursos, servicios previos, chatbot/Python o configuración sensible. Catálogos, Resend, verificación y Advisor permanecen apagados. Informe PostgreSQL y stashes preservados; sin operaciones en el índice Git, commits ni push.

Verificación SHA-256 final sobre los 611 archivos iniciales: **608 idénticos**; únicamente difieren contexto, seeder y rutas. Diez archivos nuevos propios, ninguno inicial eliminado. Las secciones PostgreSQL/migraciones previas del contexto coinciden íntegramente con la instantánea inicial y el informe PostgreSQL conserva su hash. Índice vacío, HEAD/rama y hashes de ambos stashes intactos. Se revisaron también los archivos nuevos contra archivo vacío, reconociendo CRLF como final de línea sin excluir espacios finales o errores de EOF.
