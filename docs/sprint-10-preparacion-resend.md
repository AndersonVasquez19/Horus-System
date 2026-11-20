# Sprint 10: preparación operativa de Resend

28 de octubre de 2026. Alcance exclusivo: backend de bienvenida de matrícula del Sprint 05. No se activa Resend, no se envía correo real, no se consulta su cuenta ni se modifica configuración del entorno. No se ejecutan comandos contra la base real, PostgreSQL S7 o `horus_group`. Sin cambios de frontend, plantillas, infraestructura, dependencias, commits, push ni despliegue.

## Componentes revisados

Se conserva la implementación existente, sin reconstruirla:

- `MatriculaNotificationObserver` y evento `created` de `Matricula`: intención transaccional, incluso con envío deshabilitado; única por matrícula. No captura inserciones masivas ni eventos desactivados. Editar, restaurar o reactivar no genera otra bienvenida.
- `MatriculaNotificationService`: comprobaciones de activación, cola asíncrona y remitente; reserva de despacho de cinco minutos; reserva de envío de dos minutos; HTTP fuera de transacción y snapshot cifrado estable. Recupera trabajo interrumpido y descarta resultados de un propietario antiguo.
- `SendMatriculaWelcome`: solo ID en el job; un intento del worker y timeout de 30 segundos. Los reintentos los administra la bandeja, no `queue:retry`.
- `routes/console.php`: despacho cada minuto. Registrar esta tarea no demuestra que exista un scheduler operativo en el destino.
- `horus:matriculas-notificar --status`: diagnóstico **solo de conteos** por estado, sin escrituras ni encolado, incluso deshabilitado. Si no hay filas, no muestra conteos. No prueba credenciales, DNS, conexión del proveedor ni salud del worker. Sin `--status`, el comando escribe reservas y encola; requiere autorización del destino.
- `MatriculaWelcome`: se mantienen asunto y plantillas HTML/texto existentes; no se modifican.

## Correcciones concretas

1. Se admitía un nombre de cola vacío, nulo o compuesto de espacios. Laravel puede sustituir una cola vacía por la predeterminada; una de espacios puede quedar sin consumidor. Ahora se exige un nombre de cola de tipo string y no vacío tras `trim`, antes de reservar, encolar o enviar.
2. El rechazo de remitentes `@example.com`, `@example.org` y `@example.net` distinguía mayúsculas. Ahora compara sin distinción de mayúsculas, sin alterar el remitente enviado. Este control local no verifica dominios en Resend.

Las pruebas de regresión fallaron antes de corregir el servicio en los seis casos de cola vacía/nula/espacios y dominios de ejemplo con mayúsculas. Se conserva `enabled=false` como valor predeterminado y no se modifica ningún archivo `.env*`.

## Estados y decisiones operativas

| Estado | Significado | Acción |
| --- | --- | --- |
| `pending` | Intención registrada, todavía sin intento | Revisar antigüedad y configuración; se acumula también deshabilitado |
| `sending` | Intento reservado, resultado todavía no persistido | No reenviar manualmente; la reserva puede recuperarse |
| `retry` | Reintento programado con cuerpo y clave originales | Respetar `available_at`, ventana y límite |
| `accepted` | Resend devolvió un ID válido | Comprobar entrega por separado; terminal |
| `failed` | Rechazo definitivo o agotamiento sin incertidumbre | Revisar causa acotada; terminal, sin reenvío automático |
| `uncertain` | No puede asegurarse si fue aceptado | Investigación autorizada en proveedor; terminal, sin reiniciar ni cambiar clave |
| `skipped` | Sin destinatario válido o matrícula/relaciones no elegibles antes del primer intento | Sin HTTP; terminal |

Máximo cuatro intentos persistidos. Backoff: 60, 300 y 900 segundos. `Retry-After` numérico se acota a 3600 segundos y no reduce el backoff. Se reintentan 429, 409, 5xx, fallos de transporte y 2xx sin ID válido. Un 409 se trata conservadoramente como incierto; no se cambia el cuerpo ni la clave para eludir un conflicto. Otros códigos HTTP terminan en `failed`, o `uncertain` si ya existía incertidumbre. La incertidumbre previa no desaparece por recibir un rechazo posterior.

No hay nuevos intentos desde las 23 horas del primero. Se conserva el margen respecto de las 24 horas de retención documentadas por [Resend para idempotencia](https://resend.com/docs/dashboard/emails/idempotency-keys); clasificación contrastada con sus [errores de API](https://resend.com/docs/api-reference/errors). No hay garantía de ejecución exactamente una vez en fallos distribuidos.

El snapshot se cifra con la `APP_KEY` existente y se elimina al terminar; nunca cambiar esa clave como preparación del correo. La cola no contiene destinatario ni cuerpo. Los logs propios guardan solo un resultado acotado; no mensajes de excepción, credenciales, cuerpos ni respuestas del proveedor. No hay endpoint público de envío ni estados propios de entrega/rebote por webhook.

## Configuración pendiente en el destino autorizado

No se inspeccionaron valores reales ni se certifica que el entorno esté listo. El operador debe comprobar posteriormente:

| Requisito | Fuente y comprobación |
| --- | --- |
| Activación deliberada | `MATRICULA_MAIL_ENABLED` → `config/matricula-mail.php`; predeterminado `false` |
| Credencial solo de servidor | `RESEND_API_KEY` → `services.resend.key`; permisos de envío y restricción al dominio aprobado, sin mostrar el valor |
| Remitente aprobado | `MAIL_FROM_ADDRESS` y `MAIL_FROM_NAME` → `mail.from`; dominio verificado y DNS de autenticación en Resend |
| Cola asíncrona | `MATRICULA_MAIL_QUEUE_CONNECTION`: conexión existente con driver `database`, `redis`, `sqs` o `beanstalkd`; se rechazan `sync`, `deferred`, `background`, `null` y `failover` |
| Cola concreta | `MATRICULA_MAIL_QUEUE`: nombre no vacío; el worker debe escuchar exactamente esa cola en esa conexión |
| Datos y cifrado | Tabla `matricula_notifications` del Sprint 05 y tablas del driver instaladas mediante procedimiento autorizado; misma base y `APP_KEY` estable para scheduler y worker |
| Procesos | Scheduler cada minuto y worker supervisado, misma configuración efectiva; HTTP: conexión 3 s/total 12 s, job: 30 s; `retry_after` o visibilidad de la cola debe superar el timeout del job (el predeterminado del driver database en código es 90 s) |
| Caché de configuración | Aplicar el procedimiento de configuración del destino y renovar procesos persistentes cuando corresponda; no asumir que cambiar una variable actualiza un worker existente |
| Acumulación y monitorización | Revisar pendientes antes de cualquier activación general; vigilar retrasos, `queue_unavailable`, `processing_unavailable`, `failed` y `uncertain` |

Esta bienvenida usa HTTP directo a Resend y **no depende de `MAIL_MAILER`**. Cambiar el mailer general no es un interruptor de seguridad para ella. No se ejecutaron migraciones ni se comprobó infraestructura en esta tarea.

## Procedimiento futuro para un único envío real

**Preparado, no ejecutado.** Requiere autorización expresa independiente que identifique destinatario controlado, remitente, instalación/base desechable, activación temporal y exactamente un intento HTTP. No utilizar PostgreSQL S7, `horus_group` ni una base de usuarios reales.

1. Preparar por el procedimiento autorizado una instalación aislada con datos ficticios, migraciones ya aprobadas y una cola asíncrona dedicada `resend-prueba-unica`. Mantener el envío deshabilitado. No copiar datos personales reales. Mantener detenidos el scheduler y los workers de esa instalación durante la prueba; no detener servicios de otros destinos.
2. El operador comprueba el destino efectivo sin imprimir secretos; confirma en Resend el dominio, remitente y credencial autorizados. Configura exclusivamente ese entorno mediante el mecanismo autorizado, sin trasladar claves al navegador, historial de comandos o documentación.
3. Crear una sola matrícula ficticia vigente usando el backend existente, en transacción, con el único buzón autorizado. Comprobar por acceso operativo privado el destinatario y el contenido de las plantillas existentes. La bandeja debe contener exactamente `pending: 1`, con cero intentos, y ningún otro aviso activo; la cola dedicada debe estar vacía. `--limit=1` **no filtra destinatarios** ni garantiza que el primer pendiente sea el de prueba.
4. Solo después de la autorización, habilitar temporalmente la bienvenida en ese entorno con conexión `database` y cola `resend-prueba-unica`. Verificar la configuración efectiva de los procesos. No arrancar el scheduler. Ejecutar una sola vez:

   ```console
   php artisan horus:matriculas-notificar --status
   php artisan horus:matriculas-notificar --limit=1
   php artisan queue:work database --queue=resend-prueba-unica --once --stop-when-empty --tries=1 --timeout=30
   ```

   Exigir `Notificaciones encoladas: 1` antes del worker. Ante cualquier resultado inesperado, detener la prueba. Estos comandos con escritura **no se ejecutaron durante el Sprint 10**.

5. Deshabilitar de inmediato el envío en ese entorno y mantener detenidos scheduler/workers; confirmar que no queda ningún proceso con configuración de activación antigua. Consultar `--status`. Un `retry` o `sending` no autoriza otra ejecución: tampoco ejecutar `queue:retry`, otro despacho, una nueva matrícula, ni cambiar estados o claves para repetir el envío.
6. Si queda `accepted`, registrar de forma privada el ID del proveedor y comprobar recepción, carpeta de spam, remitente, HTML y texto en el buzón autorizado. `accepted` no acredita entrega. Si hay `uncertain`, timeout, rechazo o falta de recepción, conservar evidencia mínima y revisar el proveedor con autorización; no reenviar automáticamente. Registrar solo resultado, hora e ID del proveedor, sin destinatario, cuerpo, claves ni respuestas completas en logs o informes compartidos.

## Validación y límites

Las pruebas específicas usan SQLite `:memory:`, correo/sesión/caché `array` y HTTP simulado con `Http::preventStrayRequests()`. El perfil general mantiene cola `sync`; las pruebas del outbox seleccionan el driver `database` dentro de SQLite en memoria para ejercitar el worker sin servicios externos.

Se comprueban transacción y rollback, permisos, duplicados, snapshot cifrado, clave estable, ambas partes del correo, límites de reintento, backoff exacto, 409 y tope de `Retry-After`, resultados inciertos, recuperación de reservas, propietario tardío, configuración inválida, estados terminales, diagnóstico sin escrituras, registro del scheduler y despacho/consumo en una cola dedicada sin PII en el job.

Resultados finales:

| Comprobación | Resultado |
| --- | --- |
| `php artisan test --filter=MatriculaNotificationTest --compact` | 45 pruebas, 352 aserciones; aprobado (13,62 s) |
| `php artisan test --compact` | 724 pruebas, 4.445 aserciones; aprobado (281,44 s), SQLite en memoria |
| `php vendor/bin/phpstan analyse --no-progress` | Sin errores en el alcance configurado (`app/Support` y `app/Services`); ejecutado con entorno de pruebas SQLite en memoria |
| `php vendor/bin/pint --test app/Services/MatriculaNotificationService.php tests/Feature/MatriculaNotificationTest.php` | Aprobado; solo archivos PHP propios |
| `git diff --check` | Aprobado; únicamente advertencias conocidas LF/CRLF |
| Revisión del diff y estado de Git | Solo los tres archivos propios; sin cambios en zonas frontend protegidas ni en el índice |

La evidencia local no demuestra locks/concurrencia PostgreSQL, DDL real, DNS, validez de credenciales, operación del scheduler/worker del destino, disponibilidad de Resend ni entregabilidad. No se ejecutan smokes de navegador ni build porque el frontend y las plantillas permanecen intactos.

Archivos propios: `app/Services/MatriculaNotificationService.php`, `tests/Feature/MatriculaNotificationTest.php` y este documento.
