# Sprint 18 — gestión backend de servicios tecnológicos

29 de octubre de 2026. Implementación autorizada exclusivamente backend; aceptación PostgreSQL pendiente.

## Estado inicial e inventario (antes de implementar)

- Rama `anderson`, HEAD `306b168e8d8bbfec23301d7c050c7f7c9ae11d2f`.
- Preexistentes: `PROJECT_CONTEXT.md` modificado y `docs/postgresql-testing-validacion-final.md` sin seguimiento. Se conservan. Sin commits, push ni cambios de stashes.
- Lectura: AGENTS, CLAUDE, contexto maestro, Sprints 09, 16, 17, 17.1, 17.2; contraste con rutas, modelos, solicitudes, permisos, auditoría y lector de cursos.
- `/tecnologias/cableado-estructurado`: Blade fijo, UTP/STP, redes LAN y categorías de cableado 1–6.
- `/tecnologias/camaras-seguridad`: Blade fijo, videovigilancia IP, analógica, Full HD, domo, 360°, fija; sensores, alarma por email, visión nocturna y visualización remota. El texto existente no acredita prestaciones nuevas para este módulo.
- `/tecnologias/soporte-mantenimiento`: Blade fijo, hardware/software, mantenimiento preventivo/correctivo; configuración de correo, impresoras/Wi-Fi, equipos, limpieza, actualizaciones/backups y asesoría/capacitación tecnológica.
- Textos e imágenes están directamente en `resources/views/pages/tecnologias/*.blade.php` y `public/assets`; no se modifican ni importan a registros. Redes es parte del cableado/configuración; no se inventa un cuarto servicio.
- No existen modelo, tabla, controlador ni CRUD de servicios/cotizaciones. Contacto/chat y procesos/aprobaciones/notificaciones internos no son expedientes comerciales ni una bandeja de leads.
- Se reutilizan grupo admin con sesión/rol, CSRF, middleware Spatie, Form Requests, `AuditLog::record`, transacciones y patrón editorial separado. Cursos y sus lectores permanecen intactos. CLAUDE contiene referencias históricas anteriores a 17.2 sobre la ruta pública de cursos; el código ya la conecta, sin que eso autorice conexiones nuevas.

## Diseño y plan de ejecución

Se eligen `ServicioTecnologico` y `ServicioPublicacion`: separar información administrativa de texto expresamente autorizado evita filtraciones. Una tabla única simplificaría joins pero mezclaría responsabilidades; generalizar cursos introduciría cambios innecesarios en un módulo terminado.

- [x] Modelo/migraciones: identidad UUID inmutable, código administrativo único inmutable, nombre/categoría/estado; ficha editorial 1:1 con texto, fechas, estado y autor. Sin precios, secretos, imágenes o datos comerciales.
- [x] Gestión JSON en `/admin/servicios`, permisos `servicios.ver|crear|editar|publicar`, solo admin por defecto. Otros roles requieren concesión explícita.
- [x] Revisión monotónica compartida de entidad/ficha, ETag fuerte obligatorio en toda mutación existente; bloqueo y reautorización dentro de transacción. Auditoría atómica minimizada.
- [x] Borrador completo separado de publicación; publicar requiere consentimiento y permiso específico. Para editar una ficha publicada primero debe retirarse. Inactivar/archivar retira para impedir republicación implícita al reactivar.
- [x] Lector con proyección explícita, autorización actual del autor y rol, ventana inclusiva Lima y paginación SQL (máximo 50); flag propio literal apagado, sin endpoint público.
- [x] Pruebas SQLite específicas, regresión de cursos/suite completa, Larastan 6, Pint acotado y diff; completar evidencia y contexto Sprint 18.

Reglas de revisión: ausencia de If-Match → 428; formato ambiguo/débil → 422; revisión obsoleta → 412; transición no permitida → 409. Repetir una operación con revisión actual no incrementa versión ni auditoría. Reintentar con revisión vieja exige releer: no hay promesa de exactly-once después de una respuesta perdida.

No se ejecutan migraciones reales, PostgreSQL Testing, proveedores externos ni cambios frontend. Los resultados ejecutados se registran al final.

## Modelo, migraciones y permisos

`2026_10_29_000025_create_servicios_tecnologicos_tables.php` crea únicamente tablas vacías:

| Tabla/campo | Contrato |
| --- | --- |
| `servicios_tecnologicos.id` | PK interna; orden estable de listados |
| `identificador_publico` | UUID único generado en servidor, estable e inmutable en la API; identidad futura para solicitudes |
| `codigo` | Único, 1–40 caracteres ASCII mayúsculos, números, guion o guion bajo; normalizado antes de validar y no editable |
| `nombre`, `categoria` | Nombre interno hasta 120 y agrupación opcional hasta 80; nunca proyectados al público |
| `estado` | `activo`, `inactivo`, `archivado`; predeterminado `inactivo` |
| `revision` | Entero positivo monotónico compartido por datos administrativos y ficha editorial; empieza en 1 |
| `servicio_publicaciones.servicio_tecnologico_id` | FK única, una ficha por servicio; borrado físico restringido |
| `titulo`, `resumen`, `descripcion`, `categoria` | Texto público explícito; límites 120/500/5000/80; categoría opcional e independiente de la administrativa |
| `estado` editorial | `borrador`, `publicada`, `retirada`; sin publicación automática |
| `vigente_desde`, `vigente_hasta` | Fechas obligatorias ordenadas, inclusivas en Lima; permiten programación y caducidad sin tareas programadas |
| `autorizado_por`, `autorizado_at` | Actor/fecha asignados por servidor al autorizar; FK a users con SET NULL al borrar el autor (excluye la ficha del catálogo) |
| `created_at`, `updated_at` | Trazabilidad temporal de ambas entidades |

La descripción breve/editorial vive exclusivamente en la ficha: no se duplica en el servicio administrativo. No se añaden precios, garantías, plazos, imágenes, URLs, expedientes ni credenciales. Sin borrado HTTP: archivar conserva identidad e historial. Las migraciones reversas de esquema son destructivas y no se autorizan sobre datos reales por este documento.

Unicidad en UUID, código y FK 1:1; enums generan restricciones de estados. CHECK de revisión positiva, fechas ordenadas y fecha de autorización presente en estado publicado: SQL CHECK en PostgreSQL, triggers de INSERT/UPDATE equivalentes para esas tres reglas en SQLite. Se permite autor nulo tras su eliminación; el lector falla cerrado. No hay triggers que publiquen o copien contenido. Índices `(estado,id)` administrativos y `(estado,vigente_hasta,vigente_desde)` editoriales apoyan filtros; claves únicas apoyan joins. El plan de ejecución/eficacia de índices en PostgreSQL requiere medición real posterior.

`000026_add_servicios_permissions.php` provisiona `servicios.ver`, `servicios.crear`, `servicios.editar`, `servicios.publicar` y los concede solo a admin existente. Usa `SERVICIOS_PERMISSIONS` en `RolesAndPermissionsSeeder`; el seeder incluye estos permisos únicamente en la matriz admin. La migración no crea usuarios/roles ni resincroniza concesiones; su `down` conserva permisos deliberadamente, siguiendo la migración `000024`. Las pruebas cubren provisión con permisos ausentes y repetición sobre base sembrada con concesiones ajenas. Coordinador/secretaría no reciben permisos nuevos; la gestión existente puede concederlos explícitamente.

## API administrativa para futura integración visual

Controlador nuevo único: `Admin/ServicioTecnologicoController`. Grupo existente `/admin`, sesión, rol `admin|coordinador|secretaria`, CSRF del grupo web; middleware Spatie por acción y Form Requests. Enviar `Accept: application/json` y CSRF en escrituras desde navegador. No se crean pantallas, botones ni rutas API/JWT adicionales.

| Método/ruta (prefijo `/admin`) | Nombre (`admin.`) | Permiso | Entrada / salida |
| --- | --- | --- | --- |
| GET `/servicios` | `servicios.index` | ver | `estado` opcional; `page` 1–1000000, `por_pagina` 1–50 (15 predeterminado). Paginador Laravel: `data`, `total`, enlaces y metadatos, orden id ascendente |
| POST `/servicios` | `servicios.store` | crear | `codigo`, `nombre`, `categoria?`, `estado?`; 201 con detalle/ETag; no crea ficha |
| GET `/servicios/{servicio}` | `servicios.show` | ver | 200 con detalle y ETag |
| PUT `/servicios/{servicio}` | `servicios.update` | editar | `nombre?`, `categoria?`; solo campos administrativos editables, 200 |
| PATCH `/servicios/{servicio}/estado` | `servicios.estado` | editar | `estado` obligatorio; 200; inactivar/archivar retira la publicación vigente |
| GET `/servicios/{servicio}/publicacion` | `servicios.publicacion.show` | ver | Mismo detalle, ficha y estado editorial; sin concesión implícita de publicación |
| PUT `/servicios/{servicio}/publicacion` | `servicios.publicacion.preparar` | editar | `titulo`, `resumen`, `descripcion`, `categoria?`, `vigente_desde`, `vigente_hasta`; guarda borrador completo |
| POST `/servicios/{servicio}/publicacion/autorizar` | `servicios.publicacion.autorizar` | publicar | `confirmacion_publica=true`; autoriza la ficha guardada, sin aceptar autor/estado/textos enviados |
| DELETE `/servicios/{servicio}/publicacion` | `servicios.publicacion.retirar` | publicar | Retiro explícito; conserva identidad, contenido y procedencia anterior |

Detalle JSON: `{servicio, publicacion, estado_editorial}`. `publicacion=null` si no existe; `estado_editorial` distingue `sin_ficha`, `borrador`, `publicada`, `retirada`, `programada`, `caducada`. Los dos últimos se calculan sin escribir; este resumen temporal no acredita autorización actual del autor ni elegibilidad pública. El lector vuelve a comprobar todas las condiciones. Metadatos administrativos solo en estas respuestas autenticadas. Respuestas de listado/detalle/mutación: `Cache-Control: no-store, private`.

GET y mutaciones retornan ETag `"servicio-{id}-v{revision}"`. Toda mutación sobre entidad existente debe enviar el ETag actual en `If-Match`; no se admite `_revision`, wildcard, listas o ETag débil como sustituto. Los campos inmutables deben estar ausentes incluso si su valor es null/vacío. Desconocidos no entran a la lista validada ni se asignan al modelo. PUT administrativo permite modificar solo los campos presentes; no cambia estado. PATCH raíz no existe (405).

Fallos: invitado JSON 401, permiso/rol 403, ID ausente 404, transición incompatible 409, revisión vieja/de otro servicio 412, validación 422, cabecera ausente 428. No se devuelve un borrador actual al cliente con revisión obsoleta. En errores de validación y precondición gana el primer control alcanzado: un payload inválido puede responder 422 antes de comprobar If-Match.

## Escritura y flujo editorial

`GestionServiciosTecnologicos` centraliza mutaciones. Relee/bloquea el servicio dentro de transacción, relee actor/rol/permiso y compara revisión antes de escribir. Toda escritura HTTP del módulo pasa por este servicio. La ficha única y la revisión comparten ese bloqueo. La creación captura también el conflicto SQL de unicidad y lo traduce a validación de código; no hay reintentos automáticos.

1. Crear servicio (predeterminado inactivo) no crea ni publica contenido.
2. Editor guarda ficha explícita en borrador, sin autor/fecha. Se puede preparar ficha de un servicio inactivo y programar fechas futuras.
3. Publicador revisa el detalle/ETag y confirma. Se revalidan texto, fechas guardadas, estado activo y fin no vencido dentro de la transacción. Autor/fecha provienen del servidor.
4. No se edita texto publicado: respuesta 409; primero retirar con permiso de publicación. Después se guarda borrador y se requiere nueva autorización. No hay cambios del texto público por un editor sin publicar.
5. Retirar una ficha en estado `publicada` cambia a retirada, incluso si el autor perdió su permiso. Retirar sin ficha o una ficha que no está en estado `publicada` es no-op. Republicar retirada sin modificar texto requiere confirmación/permiso/revisión actuales.
6. Inactivar/archivar retira la ficha publicada en la misma operación administrativa; reactivar no la republica. Este retiro preventivo está permitido a quien edita el estado y queda reflejado en auditoría; no concede autorización editorial.
7. Caducar excluye de lectura sin mutar registros. Una republicación caducada responde 409 y exige retirar/revisar fechas antes de autorizar de nuevo.

Acciones `servicio_creado`, `servicio_actualizado`, `servicio_estado_actualizado`, `servicio_borrador_guardado`, `servicio_publicado`, `servicio_retirado`: un registro por cambio efectivo, mismo commit transaccional. Solo ID del modelo, actor, IP y metadatos de revisión/estado; no se copian textos, datos personales o comerciales a `changes`. La inactivación con retiro es un solo evento de estado con el estado editorial resultante. Fallar auditoría revierte ficha, autorización y revisión.

Repetir el mismo borrador, edición, estado, publicación por el mismo actor o retiro con revisión actual no cambia fechas/revisión ni duplica auditoría. Otro publicador que confirma genera nueva autorización atribuida a él. Una revisión vieja se rechaza incluso si el resultado deseado coincide: releer antes de decidir un reintento. POST de creación no tiene clave idempotente; código único evita duplicados pero no confirma por sí mismo un resultado incierto. No auto-reintentar.

## Lector público preparado, apagado y sin ruta

`CatalogoPublicoServicios::pagina(int $pagina=1, int $porPagina=20)` devuelve:

```json
{"version":"1.0","habilitado":false,"total":0,"pagina":1,"por_pagina":20,"servicios":[]}
```

`config/catalogo-servicios.php` contiene `enabled=false` literal, sin variable de entorno; no reutiliza interruptores de cursos/chatbot/Advisor. Apagado devuelve vacío sin SQL ni introspección de tablas. Encendido sin esquema o sin permiso provisionado devuelve vacío; otros errores SQL se propagan al consumidor (todavía no existe controlador público que deba convertirlos a respuesta web).

Con activación simulada solo en pruebas: exige servicio activo, ficha publicada, fechas vigentes inclusivas en Lima, autorización no futura y autor existente que conserve rol administrativo y permiso de publicación (directo o por rol). No utiliza estado activo como autorización ni consulta cursos/clientes. Relee por llamada, sin caché retenida.

Proyección explícita SQL: `identificador_publico`, `titulo`, `resumen`, `descripcion`, `categoria` editorial, `vigente_desde`, `vigente_hasta`. Sin ID interno, código/nombre/categoría administrativos, revisión, autor, timestamps internos, precios o expedientes. El texto autorizado sigue requiriendo revisión humana: ningún filtro garantiza ausencia semántica de información privada escrita manualmente en una ficha pública. Se rechazan etiquetas en entradas HTTP; el futuro frontend debe escapar todo como texto, nunca HTML libre.

Orden id administrativo ascendente (identidad inmutable), tamaño 1–50, página 1–1000000; inválidos lanzan `InvalidArgumentException`. COUNT SQL calcula total y SELECT utiliza LIMIT/OFFSET; no se materializan todas las fichas. La prueba con 55 comprueba 50+5, ausencia de intersección, límite SQL y consultas acotadas, no tiempo/plan PostgreSQL. COUNT y SELECT son consultas separadas: ante escrituras simultáneas puede variar el total respecto a la página; no se promete snapshot consistente. OFFSET grande puede ser costoso; paginación por cursor queda para necesidad medida.

No hay endpoint público, consumidor web/chatbot/Advisor, importador, seeder de contenido, distribución de snapshots ni tareas programadas de servicios. Catálogo vacío no inventa sustitutos ni utiliza contenido administrativo.

## Integración pendiente y cotizaciones

- Panel: consumir API JSON, gestionar CSRF y ETag, conservar borrador ante 412 y exigir recarga/revisión explícita; separar acciones editar/publicar. Formularios, navegación y botones requieren autorización visual futura.
- Web: aprobar diseño/ubicación, escapar textos, informar total/rango/página, controlar indisponibilidad y caché antes de crear ruta o consumidor. No hay URLs/imágenes de ficha en este contrato.
- Cotizaciones: futuro expediente privado con `servicio_identificador_publico` estable, `servicio_nombre_historico` (snapshot del título autorizado o nombre administrativo según origen claramente registrado), `necesidad_cliente` y `estado_solicitud` bajo su propia máquina de estados/permisos. Contactos y necesidad son privados. No se fija ahora un enum comercial inexistente. No hay tabla/endpoint de cotizaciones ni dependencia indispensable para este sprint.
- La relación futura debe conservar snapshot e identidad aunque el servicio sea retirado/archivado; nunca ON DELETE CASCADE desde servicio a solicitudes. Definir FK RESTRICT o SET NULL más UUID histórico al diseñar la migración comercial. El módulo actual no ofrece borrado físico HTTP.
- Horus AI Advisor: permanece apagado; no cambia exportador, firma, chatbot PHP o Python. Un futuro contrato de exportación de servicios deberá resolver autorización, retirada, caducidad e invalidación del conocimiento sin datos comerciales, con aprobación independiente.

## Seguridad, límites y aceptación PostgreSQL

Sin servicios reales creados, proveedores conectados ni entorno/credenciales modificados. Validación en Form Requests, identificación numérica por binding (404 si no existe), SQL enlazado, lista permitida de entrada/modelos y estados finitos. La API no detecta secretos escritos manualmente como prosa: revisión editorial obligatoria. No modifica AdminProtection ni salvaguardas de pruebas.

SQLite demuestra contratos, integridad referencial, restricciones equivalentes, transacciones, rollback y secuencias de revisiones. **No demuestra locks, contención, DDL, colación, aislamiento o rendimiento PostgreSQL 18.** Releer permiso dentro del bloqueo del servicio no bloquea tablas Spatie: una revocación que se confirme después del chequeo no se serializa con esta operación. El lector reevalúa autorización en la siguiente llamada; no revoca respuestas ya entregadas o en vuelo.

Antes de datos reales: recuperación/autorización PostgreSQL independiente; revisión de migraciones específicas, destino, backup y salvaguardas; no ejecutar migración general que incluya `000022` suspendida. Validar creación/reversión de esquema en base desechable autorizada, enums/CHECK/FK/UUID e índices/EXPLAIN; carreras publicar-retirar-editar-inactivar, primer borrador concurrente, unicidad de códigos, rollback de auditoría, dos clientes con el mismo ETag y comportamiento de revocación concurrente. Validar zona horaria de autorización y medianoche Lima. No se interactuó con el clúster Testing ni se ejecutó migración real en este sprint.

## Evidencia de ejecución

Solo SQLite `:memory:` mediante `phpunit.xml`; correo/sesión/caché `array`, cola `sync`, HTTP no simulado bloqueado en las pruebas nuevas. No se ejecutan npm/build/smokes porque no hay cambios frontend.

| Comprobación ejecutada | Resultado final |
| --- | --- |
| `php artisan test --compact --filter=ServicioTecnologicoTest` | **30 aprobadas, 407 aserciones**, 16,27 s |
| `php artisan test --compact` | **981 aprobadas, 6439 aserciones**, 125,32 s; incluye regresiones de cursos y esquema existente |
| `php vendor/bin/phpstan analyse --no-progress` | **Nivel 6, sin errores** |
| Pint aplicado y `--test` sobre los 14 PHP propios | **Aprobado**; sin reformateo global |
| `git diff --check` | **Aprobado**; solo avisos conocidos de conversión LF/CRLF |
| Preservación | **377 archivos protegidos inventariados idénticos** por SHA-256; informe PostgreSQL previo idéntico; contenido anterior del contexto conservado al retirar las adiciones de Sprint 18 para comparar |

Las pruebas específicas cubren creación/listado/detalle/edición, permisos por rol/operación y delegación, preparación/publicación/retiro/caducidad/programación, ETag obligatorio/malformado/obsoleto/cruzado, idempotencia, autor y auditoría/rollback, duplicados normalizados, estados inválidos HTTP/SQL, inmutables nulos, mass assignment, catálogo apagado/vacío/sin esquema/autorizado, exclusión de borradores/retiradas/autores inválidos, proyección pública, paginación SQL de 55 fichas sin N+1, medianoche Lima, convivencia de cursos, páginas fijas sin conexión nueva, ausencia de rutas públicas/escrituras de lectura y provisión de permisos en base nueva/sembrada. La simulación de permiso revocado comprueba relectura del actor antes de escribir; no es una carrera PostgreSQL real.

Se observó fallo inicial por ausencia de módulo (16 pruebas), luego fallo de revalidación por objetos Carbon frente a `date_format:Y-m-d`; se corrigió serializando fechas del modelo antes de revalidar. Una regresión adicional reprodujo `estado=null` aceptado por `prohibited` con error SQL 500; `missing` exige ausencia incluso vacía y lo convierte en 422. Se delimitó edición a PUT para evitar el PATCH genérico de resource. Todos estos casos pasan con la implementación final, sin relajar controles.

La revisión estática separada detectó la ambigüedad PATCH y la falta de una prueba de provisión de permisos ausentes; ambas quedaron corregidas y verificadas en el segundo pase. Sin hallazgos funcionales importantes pendientes en ese alcance. Quedan las limitaciones de aceptación PostgreSQL, recuento/página no atómicos y revocación concurrente ya descritas.

### Inventario propio final (16 archivos)

- Nuevos: `app/Models/{ServicioTecnologico,ServicioPublicacion}.php`; `app/Http/Controllers/Admin/ServicioTecnologicoController.php`; `app/Http/Requests/{GuardarServicioRequest,PrepararServicioPublicacionRequest,AutorizarServicioPublicacionRequest}.php`.
- Nuevos: `app/Services/{GestionServiciosTecnologicos,CatalogoPublicoServicios}.php`; `config/catalogo-servicios.php`; migraciones `000025` y `000026`; `tests/Feature/ServicioTecnologicoTest.php`; este informe.
- Modificados: `database/seeders/RolesAndPermissionsSeeder.php`, `routes/web.php` (solo nuevas rutas administrativas/import) y sección Sprint 18 de `PROJECT_CONTEXT.md`, preservando los cambios anteriores.

Índice vacío, rama y HEAD iniciales conservados; sin commits, push, cambios de stashes, eliminación de archivos sin seguimiento ni despliegue. Sin modificaciones en frontend/Python, chatbot PHP, cursos, entorno, credenciales, salvaguardas o módulos excluidos. No se crearon servicios ni publicaciones reales. **Entrega backend implementada y validada en SQLite; pendiente de aceptación PostgreSQL antes de utilizar datos reales.**
