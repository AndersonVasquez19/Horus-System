# Sprint 12: seguridad y robustez del backend

## Registro previo a las correcciones

Inventario inicial sobre `anderson`: diez archivos pendientes de los sprints 10–11. Se preservan sus cambios; cualquier ajuste adicional en un archivo compartido se limita al defecto documentado. Sin frontend, credenciales, datos reales, PostgreSQL S7, `horus_group`, envíos, proveedores reales, Git de escritura ni despliegue.

Larastan inicial: nivel 5 en `app/Support` y `app/Services`. La primera ejecución sobre todo `app/` detectó 15 errores en `AuditoriaController`, `MatriculaController` y `ReportController`, relacionados con tipos de relaciones, colecciones y parámetros genéricos. Sin baseline ni supresiones.

| ID | Severidad | Archivo/línea inicial | Evidencia reproducible e impacto | Corrección prevista y regresión |
| --- | --- | --- | --- | --- |
| S12-01 | Alta | `app/Notifications/ResetPasswordNotification.php:12` | POST a recuperación con host ajeno genera el enlace con ese origen; puede filtrar el token si el destinatario sigue ese enlace. Requiere que la petición llegue a Laravel con ese host | Construir el enlace con origen canónico configurado, aislado del generador global. `test_reset_link_uses_canonical_origin_instead_of_untrusted_host`, incluida instalación en subdirectorio |
| S12-02 | Media | `app/Http/Controllers/Auth/ResetPasswordController.php:67` | Un token inválido y contraseña válida guardan contraseña, confirmación, token y otros campos en sesión mediante `withInput()` | Reponer solo email; `test_invalid_reset_never_flashes_passwords_tokens_or_unexpected_fields`. No se demuestra filtración a otro usuario; sí persistencia innecesaria de secretos |
| S12-03 | Media | `app/Http/Controllers/Auth/ForgotPasswordController.php:34` | La segunda petición a una cuenta existente devuelve error de throttle del broker; una ausente conserva la respuesta genérica. Un fallo de transporte también diferencia una cuenta registrada | Reutilizar la respuesta genérica ya existente sin quitar límites. Capturar solo fallos de transporte, registrar únicamente `delivery_unavailable`, sin reintento automático. Dos regresiones de respuesta/repetición/fallo de entrega |
| S12-04 | Media | `app/Http/Controllers/Auth/ResetPasswordController.php:40` | Excepción posterior a guardar contraseña/revocar sesiones deja credenciales cambiadas y token sin consumir, pese a HTTP 500 | Transacción sobre el reset completo en la conexión compartida. `test_reset_failure_rolls_back_password_version_sessions_and_token_consumption` |
| S12-05 | Media | `UserController.php:97`, `RoleController.php:53`, `ConfiguracionController.php:70`; alta API y comando de provisión | Fallo de auditoría produce HTTP 500 pero persisten usuario/roles/permisos o ajustes modificados. También persiste una cuenta cuando falla emitir JWT o asignar su rol en consola | Transacciones que incluyen las operaciones asociadas. Cinco regresiones comprueban rollback de entidades, pivotes, ajustes y altas incompletas |
| S12-06 | Media | `AprobacionController.php:89,123` y eventos de `AprobacionInterna` | Fallo de la notificación interna deja creada la aprobación o actualizado su estado. Un reintento puede ya no emitir el evento de cambio | Transacción en crear/actualizar, incluyendo la escritura síncrona del evento; dos regresiones de aprobación |
| S12-07 | Baja | Paquete `resend-laravel`, `WebhookController::__construct()` y ruta `resend.webhook` | Sin secreto, el endpoint acepta HTTP 200 y puede emitir `EmailDelivered` sin firma. No hay listeners propios ni efecto demostrado sobre estados de la bandeja | Deshabilitar rutas del paquete que esta aplicación no usa. `test_unused_resend_webhook_is_not_exposed_without_verification` espera 404 y ningún evento |
| S12-08 | Baja | Contratos estáticos de exportaciones | El alcance anterior ocultaba 15 errores de análisis; no se considera que los 15 sean vulnerabilidades | Corregir tipos reales de relaciones/colecciones y ampliar el alcance permanente de Larastan |

Antes de modificar producción: 12 escenarios nuevos, **11 fallidos y 1 aprobado**. El aprobado verifica que el registro API ya ignora campos privilegiados inesperados. Estos tests usan SQLite en memoria, notificaciones falsas y bloqueo de HTTP externo. Los fallos anteriores son evidencia deliberada de regresión, no resultados finales.

Se extendió S12-02 a los errores de validación anteriores al broker: Laravel excluía contraseñas, pero todavía guardaba token y campos inesperados. La prueba adicional falló antes de limitar también ese camino a email. En S12-05 se probaron y reprodujeron separadamente las altas API/consola antes de corregirlas. No se considera que un fallo sintético demuestre que una caída ya ocurrió en producción; demuestra la escritura parcial en ese camino ejecutable.

## Alcance real y fronteras de confianza

Inventario revisado por rutas, código, consultas y análisis estático:

| Zona | Inventario y revisión |
| --- | --- |
| HTTP | 23 controladores web/API, 8 FormRequests, 1 middleware JWT; rutas web, API y consola; bootstrap y proveedor de límites de login |
| Dominio | 13 modelos y relaciones, 6 servicios; autorización por sesión, rol administrativo y permisos Spatie por acción. No existen policies propias, Gates personalizados ni directorios propios de Events/Listeners; sí eventos de modelo y dispatch del paquete |
| Procesos diferidos | 3 comandos, 1 job, 1 observer, 1 notificación y 1 Mailable; registro del scheduler, snapshots cifrados, reservas, límite de reintentos y estados terminales |
| Soporte | 20 archivos, incluidas las salvaguardas de pruebas; estas últimas solo se inspeccionan/analizan, sin cambios |
| Datos | 19 migraciones leídas, 10 seeders y 1 factory; relaciones, restricciones, campos permitidos y comportamiento de datos de demostración. No se ejecutan migraciones/seeders sobre bases reales |
| Salidas | CSV, XLSX, PDF, JSON de auditoría, ZIP administrativo, temporales y redacción de datos sensibles |
| Integraciones | HTTP a hosts fijos, timeouts y selección de respuesta del chatbot; Resend saliente y ruta del paquete. Solo mocks/fakes, sin consultas a cuentas, DNS o credenciales reales |

Actores: visitante anónimo, usuario registrado sin rol de panel, personal con distintos permisos, administrador y operador de consola. Los identificadores recibidos se contrastan con middleware/validación/relaciones y con las reglas existentes. Los módulos administrativos son globales; no se inventa un modelo de propietario o tenant que el sistema no tiene. La notificación personal sí comprueba destinatario y los tests existentes rechazan marcar una ajena.

No se encontró un pipeline propio de importación ni endpoints de carga/importación en las rutas inventariadas. Los seeders y las inserciones SQL masivas son otra frontera: no se equiparan al flujo HTTP ni se les atribuye el observer de Eloquent cuando omiten eventos.

## Correcciones implementadas y contratos preservados

- El generador del enlace de recuperación se clona y fija a `app.url`, incluidos esquema y subdirectorio. No se modifica el generador global, asunto, vista, HTML ni contenido de la notificación. Una URL canónica ausente, relativa o con credenciales/query/fragmento se rechaza sin usar el host recibido ni imprimir su valor. La configuración canónica correcta debe comprobarse en una futura revisión operativa autorizada; no se cambió `APP_URL` ni ningún `.env`.
- Tanto el rechazo del broker como el error de validación de reset reponen únicamente email. Se conservan reglas, mensajes de validación, CSRF y el formulario existente.
- La recuperación reutiliza su mensaje genérico existente para envío solicitado, cuenta ausente y throttle del broker. Los límites siguen vigentes, y la prueba exige solo una notificación para peticiones repetidas. Los fallos de transporte se registran con un código operativo sin el detalle de la excepción; errores de base de datos o programación no se capturan indiscriminadamente.
- La transacción del reset incluye validación del broker, cambio de credenciales, versión JWT, revocación de sesiones en base de datos, evento y consumo del token. La prueba fuerza un fallo posterior a revocar y confirma rollback. No se promete atomicidad entre conexiones o almacenes diferentes ni rollback de efectos externos de futuros listeners.
- Altas administrativas, roles y ajustes incluyen auditoría en su transacción. Crear un rol también invalida la caché de permisos después del commit, usando el mecanismo existente de `AdminProtection`. El alta API incluye la emisión del JWT y el comando incluye la asignación de rol. No se relajan permisos ni las protecciones del último administrador.
- Crear/actualizar aprobaciones incluye la notificación interna síncrona en la misma transacción; no se cambian estados, destinatarios ni reglas de aprobación.
- `config/resend.php` deshabilita las rutas del paquete, de forma independiente del envío de bienvenida. No se usa esa ruta para recepción de eventos, ni se alteran estados de la bandeja. Se corrige la descripción histórica del Sprint 05 que daba por incondicional la verificación de firma. Habilitar recepción futura requiere un contrato independiente de firma, replay/deduplicación y autorización.
- Los cambios para Larastan describen la relación real `AuditLog::user`, el cast existente de fecha de matrícula, el tipo genérico de la consulta CSV y el parámetro del lote de reportes. `getAttribute()` recupera el alias realmente creado por `withCount()` en el seeder. En expresiones `->propiedad ?? valor`, PHP conserva el fallback aunque el objeto sea null; se elimina solo el `?->` redundante señalado, sin eliminar ese fallback. `created_at` de auditoría y fecha de matrícula son no nulos en las migraciones existentes.

## Controles examinados sin nuevo defecto explotable confirmado

- Registro web/API usa campos explícitos y no asigna roles; un usuario sin rol no entra al panel. Se añade prueba de campos inesperados/privilegiados en API.
- Login conserva límites por IP y correo; JWT se emite con expiración, nbf, issuer, audience y versión. Se revisa revocación al logout y cambio de contraseña, y se amplían casos de rechazo directo del endpoint JWT. No se atribuye a las pruebas una garantía de indistinguibilidad temporal de cuentas.
- Los SQL raw revisados usan valores ligados o expresiones/identificadores controlados por código; no se demostró una ruta de inyección SQL. Los paths de exportación provienen de temporales privados/UUID y tablas permitidas, no de una ruta enviada por el cliente.
- Se mantienen neutralización de fórmulas, redacción de secretos de auditoría/ZIP y controles por formato de exportación; los tests existentes ejercitan fallos de ZIP, auditoría y limpieza de temporales.
- `SpreadsheetCellCache::unserialize()` lee lo que la propia instancia serializó en su temporal privado; no hay entrada de deserialización desde una carga HTTP. No se declara una deserialización explotable basándose solo en el nombre de la función.
- Se conserva el protocolo de bloqueo de cursos/estudiantes/matrículas, el índice vigente y las relecturas de elegibilidad, permisos y cupos. SQLite valida caminos y rollback, no exclusión mutua PostgreSQL.
- El chatbot sigue resolviendo primero contenido local y validando los IDs seleccionados por OpenAI. Conserva ámbitos de sesión, presupuestos, caché y límites. El catálogo público continúa apagado y `CursosPublicosController` continúa sin ruta pública dinámica. No se cambian respuestas públicas.
- Resend mantiene el valor predeterminado deshabilitado, snapshot cifrado, clave estable, cuatro intentos, ventana acotada y manejo conservador de incertidumbre. No se modifica el servicio del Sprint 10 ni se atribuye `accepted` a entrega en buzón.

## Decisiones de negocio y riesgos abiertos

**S12-09, media, decisión pendiente:** el registro público web/API informa que un correo ya está registrado mediante su regla de unicidad. Es una diferencia de respuesta demostrada por los controladores y las pruebas de unicidad existentes, limitada por el throttle de registro. Uniformarla exige decidir el contrato de registro/recuperación y autorizar el cambio del contenido correspondiente. No se modifica ni se afirma que la aplicación impide toda enumeración de cuentas por haber corregido la recuperación.

Otras decisiones no convertidas en vulnerabilidades sin contrato: aprobación propia/separación de funciones, destinatarios gestionables por quien tiene `aprobaciones.gestionar`, alcance de los indicadores comunes del dashboard y lectura compartida de notificaciones globales. Imponer nuevos permisos por propietario o transiciones sería una decisión funcional nueva.

Límites técnicos pendientes:

- Sin PostgreSQL real: no se acreditan bloqueos, concurrencia, DDL, planes de ejecución, commits inciertos ni uso simultáneo de un token de recuperación. Envolver operaciones en una transacción no demuestra por sí solo idempotencia concurrente.
- La revocación de sesiones usa el mecanismo existente de `SESSION_DRIVER=database`; el test de rollback usa la misma conexión SQLite para credenciales, token y sesiones. No se garantiza revocación en drivers o conexiones diferentes.
- No se certifican configuración efectiva de producción, HTTPS/proxy/cookies, secreto JWT real, origen canónico real, transporte, correo, OpenAI, DNS ni disponibilidad del worker/scheduler. No se inspeccionan valores secretos ni se inicia una auditoría de infraestructura.
- El mailer general `log` escribe cuerpos de correo, incluidos enlaces de recuperación; el valor predeterminado de `config/mail.php` no debe interpretarse como un transporte apto para datos reales. Verificar posteriormente el transporte autorizado es un requisito operativo; no se cambia el mailer ni se habilita Resend en esta tarea.
- No se ejecuta análisis externo de CVEs ni pruebas ofensivas contra terceros; el inventario de dependencias no equivale a certificar que carecen de vulnerabilidades.
- Los selectores completos y las exportaciones JSON/ZIP todavía pueden crecer con los datos. No se acredita un OOM real ni se rediseñan sin medición y contrato; XLSX conserva sus límites de memoria conocidos. No se agregan índices ni dependencias.
- El webhook queda cerrado; las entregas/rebotes no se convierten en estados propios de la bandeja. Un futuro consumidor firmado requiere diseño y autorización separados.

## Larastan ampliado

Configuración permanente: **nivel 5**, con `app/`, `routes/`, `database/`, `config/`, `bootstrap/app.php` y `bootstrap/providers.php`. Incluye modelos, requests, controladores, middleware, comandos, jobs, observers, notificaciones, servicios, soporte, migraciones, seeders y factory. Se actualiza únicamente la descripción técnica del comando en `CLAUDE.md`; no se amplían permisos operativos.

Resultado: sin errores. No hay `ignoreErrors`, baseline, nuevos silenciamientos ni casts artificiales. Las migraciones se analizan sin modificarlas. El primer análisis ampliado a `database/` identificó además el alias de `withCount()` del seeder, ahora accedido mediante la API real de atributos.

Ensayo optativo de nivel 6 sobre el alcance ampliado: **34 observaciones**, agrupadas en 25 `missingType.iterableValue` y 9 `missingType.generics`. Se conserva nivel 5 porque elevarlo ahora exigiría extender anotaciones a numerosos archivos sin aumentar la corrección de los defectos de riesgo efectivo abordados. No se ocultan esas observaciones ni se presenta el nivel 6 como aprobado.

## Pruebas nuevas y validación

Los escenarios de `Sprint12AuthSecurityTest` y `Sprint12AtomicityTest` cubren los defectos S12-01 a S12-07, configuración canónica inválida, ausencia de cambios en el generador global, campos privilegiados inesperados y rechazo JWT por expiración, nbf futuro, issuer/audience ajenos, versión revocada, usuario inexistente y algoritmo distinto.

Pruebas de seguridad/control que pasan desde el principio no se presentan como bugs corregidos. Los fallos se fuerzan con eventos o excepciones de transporte controlados; no se introducen endpoints de diagnóstico ni flags de bypass en producción. Todo se ejecuta en SQLite `:memory:`, con caché/correo/sesión `array` por defecto, cola `sync`, HTTP externo bloqueado y notificaciones falsas. La prueba específica de revocación usa sesión `database` sobre ese mismo SQLite desechable.

| Comprobación | Resultado final |
| --- | --- |
| Pruebas nuevas `php artisan test --filter=Sprint12 --compact` | 27 pruebas, 151 aserciones, aprobadas (44,99 s) |
| Suite completa SQLite `php artisan test --compact` | **756 pruebas, 4.647 aserciones, aprobadas (89,59 s)**, incluidos todos los escenarios nuevos |
| `php vendor/bin/phpstan analyse --no-progress` | Aprobado, sin errores, con el alcance permanente ampliado y entorno de pruebas SQLite |
| Pint | Aprobado, acotado a los 18 archivos PHP propios, incluidos los últimos tests |
| `git diff --check` | Aprobado, advertencias LF/CRLF conocidas |
| Frontend, migraciones, salvaguardas e índice | Diff vacío en esos paths y en el índice; rama `anderson` conservada |

No se ejecutan build ni smokes de navegador: no hay cambios de frontend. La suite existente comprende permisos por rol, recursos ajenos en notificaciones, IDs inexistentes, validaciones, matrícula/cupos, rollback, JSON/CSV/PDF/XLSX/ZIP, JWT y fallos simulados de OpenAI/Resend. No se han enviado correos reales ni consumido servicios externos.

## Inventario exclusivo del Sprint 12

Código/configuración (16 PHP y un archivo de análisis):

- `app/Console/Commands/CrearUsuarioPanel.php`
- `app/Http/Controllers/Admin/AprobacionController.php`
- `app/Http/Controllers/Admin/AuditoriaController.php`
- `app/Http/Controllers/Admin/ConfiguracionController.php`
- `app/Http/Controllers/Admin/MatriculaController.php` (tipos CSV y fecha, sobre el cambio previo del selector)
- `app/Http/Controllers/Admin/ReportController.php` (tipo del parámetro, sobre el agregado del Sprint 11)
- `app/Http/Controllers/Admin/RoleController.php`
- `app/Http/Controllers/Admin/UserController.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Auth/ForgotPasswordController.php`
- `app/Http/Controllers/Auth/ResetPasswordController.php`
- `app/Models/AuditLog.php`
- `app/Models/Matricula.php`
- `app/Notifications/ResetPasswordNotification.php`
- `config/resend.php` (nuevo)
- `database/seeders/MatriculaSeeder.php` (acceso al alias, sin cambio funcional de cupos)
- `phpstan.neon.dist`

Pruebas/documentación:

- `tests/Feature/Sprint12AuthSecurityTest.php` (nuevo)
- `tests/Feature/Sprint12AtomicityTest.php` (nuevo)
- `docs/sprint-12-seguridad-robustez-backend.md` (nuevo)
- `docs/sprint-05-resend-matriculas.md` (precisión sobre la firma condicional del webhook)
- `CLAUDE.md` (solo alcance técnico del comando de Larastan)

Total: **22 archivos propios**, con correcciones funcionales, contratos estáticos necesarios, pruebas y documentación. No se formatea globalmente el repositorio. Los diez archivos pendientes iniciales se preservan; solo `MatriculaController` y `ReportController` reciben ajustes adicionales de tipos, manteniendo las optimizaciones anteriores. Se verificaron las huellas SHA-256 de los ocho restantes contra la captura inicial: todas coinciden.

## Siguiente sprint recomendado

Primero decidir la política anti-enumeración del registro y el origen canónico autorizado; no cambiar textos o configuración real automáticamente. Después, completar los tipos pendientes para evaluar nivel 6 en un bloque acotado y medible. La validación de concurrencia PostgreSQL requiere una autorización futura específica que no reanude S7 implícitamente. Ninguna aprobación de este sprint autoriza publicación, envío real, migración real o despliegue.

Este informe no declara al backend libre de vulnerabilidades ni listo para producción basándose en los tests. Identifica qué rutas de fallo se reprodujeron y corrigieron y qué garantías siguen sin medirse.
