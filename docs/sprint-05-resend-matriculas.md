# Sprint 05: bienvenida de matrícula con Resend

28 de octubre de 2026. Cambios locales en `anderson`, sin commit, push ni despliegue. S7 y PostgreSQL real no se tocaron. No se enviaron correos reales ni se leyeron o modificaron archivos `.env` reales mediante las herramientas de inspección.

## Estado inicial y decisión

`resend/resend-laravel` ya figura en Composer y está instalado. `config/mail.php` declara el transporte `resend`; `config/services.php` toma la clave del entorno. `.env.example` usa correo `log`, remitente de ejemplo y cola `database`. El paquete registra su transporte y puede registrar una ruta de webhook cuya firma solo verifica cuando hay secreto configurado; el Sprint 12 deshabilita esas rutas no utilizadas mediante `config/resend.php`. No había bienvenida de matrícula, Mailables de matrícula ni procesamiento propio de sus eventos de entrega. No se consultó la cuenta de Resend, DNS, dominio verificado, destinatarios ni credenciales reales. No hay evidencia de entregabilidad en producción.

El contrato vigente en requests/controlador es `pendiente`, `pagada`, `anulada`. Una matrícula nueva persistida en estado `pendiente` o `pagada` merece confirmación del **registro**, sin exigir pago. Se excluye la creación anulada. Editar, pagar, restaurar o reactivar una matrícula no genera otra bienvenida. Una rematrícula legítima con una nueva fila sí constituye otro registro. No se modificaron permisos ni reglas de cupos, duplicados o transacciones del controlador.

Se consideraron tres mecanismos: enviar desde el controlador bloquea la respuesta y mezcla fallos; despachar solamente después del commit deja una ventana de pérdida entre commit y cola; una bandeja transaccional permite recuperar el aviso. Se implementó esta última, sin paquetes nuevos ni cambios de infraestructura. Se evitó editar el controlador de matrículas porque tenía cambios preexistentes.

## Arquitectura

1. El evento Eloquent `created` de `Matricula` inserta una intención de envío en `matricula_notifications`, usando la misma conexión y transacción. No hace HTTP ni encola. El controlador existente crea la matrícula dentro de `DB::transaction`; un rollback elimina también la intención.
2. La columna única `matricula_id` impide dos bienvenidas para la misma matrícula. No se importan ni notifican matrículas históricas. La captura sigue activa con el envío deshabilitado: los avisos nuevos se acumulan para su posterior procesamiento.
3. El scheduler ejecuta `horus:matriculas-notificar` cada minuto. Solo observa datos confirmados. Reserva el despacho durante cinco minutos y encola un job con **solo el ID del aviso**. Una caída entre reserva y cola se recupera al vencer la reserva; una excepción de cola libera la reserva y espera un minuto.
4. El job comprueba configuración y transacciones abiertas; reclama la fila bajo lock SQL. Solo una reserva de envío vigente puede realizar el intento. Una reserva huérfana puede recuperarse tras dos minutos. El HTTP ocurre después de cerrar la transacción.
5. Antes del primer intento se verifican matrícula y relaciones no eliminadas, estado vigente y email válido. La eliminación/anulación previa al primer envío produce `skipped`. Después de un intento se conserva `uncertain` si ya no se puede continuar. No se pueden deshacer correos aceptados durante una anulación concurrente.
6. Se ensambla y persiste cifrado un snapshot del destinatario, remitente, asunto, HTML y texto. Los reintentos reutilizan exactamente ese cuerpo, aunque cambien datos o plantillas. Una edición no sustituye destinatarios silenciosamente dentro de un intento incierto. El payload se elimina al alcanzar un estado terminal y está oculto en la serialización del modelo.

El observer no se ejecuta en inserciones SQL masivas, `insert`, `withoutEvents` o importaciones que omitan Eloquent. El flujo autorizado del panel sí usa Eloquent y transacción. La atomicidad fuera de ese flujo exige que el llamador también use una transacción; no se extendieron otros módulos ni importadores.

La migración es necesaria antes de usar el código. Si la escritura de la intención falla dentro del flujo del panel, la transacción revierte: no se confirma una matrícula dejando su aviso perdido. Los fallos del proveedor y de cola ocurren posteriormente y no revierten matrículas confirmadas.

## Transporte, reintentos e idempotencia

Esta bienvenida usa un adaptador acotado con el cliente HTTP de Laravel contra `https://api.resend.com/emails`, reutilizando `services.resend.key` y `mail.from`. Permite controlar timeouts, status HTTP, payload estable y errores sin registrar mensajes del proveedor. El transporte general instalado y el correo de recuperación de contraseña no se cambian. **Esta integración no depende de `MAIL_MAILER`: solo se activa con `MATRICULA_MAIL_ENABLED=true`.**

| Control | Comportamiento |
| --- | --- |
| Activación | Deshabilitada por defecto; requiere clave, remitente válido y conexión de cola asíncrona admitida |
| Colas | `database`, `redis`, `sqs`, `beanstalkd`; rechaza `sync`, `null`, `deferred`, `background` y fallbacks que podrían enviar en la petición |
| HTTP | Conexión 3 s, total 12 s, sin redirecciones ni retry interno |
| Job | Timeout 30 s, un intento del worker; los reintentos los controla la bandeja |
| Proveedor | Máximo 4 intentos persistidos por aviso, incluso al recuperar jobs huérfanos |
| Backoff | 60, 300 y 900 segundos entre los cuatro intentos; 429 respeta `Retry-After` numérico, acotado a 3600 s |
| Reintentables | 429, 409, 5xx, respuesta 2xx sin ID válido y fallo de transporte |
| Rechazo definitivo | Otros errores HTTP; sin reintento automático |
| Clave | UUID aleatorio por aviso, cabecera `Idempotency-Key`; no contiene IDs internos ni datos personales |
| Ventana | Ningún intento nuevo después de 23 h desde el primero; pasa a revisión `uncertain` |
| Propietario | Token de reserva; un worker antiguo no sobrescribe el resultado de otro |

Resend documenta una retención de idempotencia de 24 horas: [claves de idempotencia](https://resend.com/docs/dashboard/emails/idempotency-keys). Se usa un margen de una hora y un payload congelado. Clasificación contrastada con [errores de la API](https://resend.com/docs/api-reference/errors). No se promete ejecución exactamente una vez ante fallos distribuidos. Un resultado incierto nunca se reinicia automáticamente fuera de la ventana ni mediante `queue:retry`.

Los reintentos de **transporte** están acotados. Las indisponibilidades de cola o infraestructura local se recuperan mediante barridos periódicos; no consumen intentos HTTP. Requieren monitorización si persisten. Un fallo de preparación del job se registra con un código genérico, sin serializar la excepción, y la reserva permite recuperarlo.

## Correo y privacidad

`MatriculaWelcome` define asunto, HTML responsive y parte de texto plano independiente. Se usa tabla de presentación con ancho máximo 600 px, tipografía de sistema, fondo azul institucional y enlaces explícitos. El HTML escapa los nombres; el texto plano conserva caracteres legibles y elimina saltos de línea en los nombres.

Incluye nombre del estudiante, nombre del curso, confirmación del registro, aclaración de que no confirma pago y próximos pasos con el equipo. Los contactos se contrastaron en `resources/views/pages/contactos.blade.php`: +51 927 582 305 y horusgroupcajamarca@gmail.com. No incluye DNI, IDs internos, observaciones, monto, certificados, fechas, cupos, modalidad ni horarios administrativos. La versión de texto es un cuerpo MIME de texto, no HTML.

El snapshot cifrado contiene datos personales mínimos necesarios para reintentos y requiere conservar `APP_KEY`; no cambiar esa clave. Se elimina al terminar. La tabla conserva estado, número de intentos, fechas, código acotado de error e ID del proveedor. Los logs `matricula.mail` contienen solo `outcome`; no almacenan destinatario, cuerpo, tokens, excepciones ni respuestas HTTP. No se añadió ruta pública de envío ni interfaz administrativa nueva.

## Diagnóstico y activación pendiente

Los estados son `pending`, `sending`, `retry`, `accepted`, `failed`, `uncertain` y `skipped`. `accepted` indica que Resend devolvió un ID válido; **no acredita entrega al buzón**. `uncertain` requiere comprobar el ID, la clave y los registros del proveedor desde acceso operativo autorizado, sin reenviar a ciegas. No hay reenvío manual arbitrario ni botón que reinicie estados terminales.

El comando `php artisan horus:matriculas-notificar --status` solo muestra conteos por estado. No lo ejecutamos contra la base configurada real. El comando sin `--status` escribe reservas y encola; no ejecutarlo en un destino real sin la autorización correspondiente.

Pendientes antes de activar:

- Revisar y autorizar la migración aditiva en el destino correcto. Aquí solo se ejecutó mediante pruebas SQLite en memoria, incluyendo su reversión.
- Confirmar clave y permisos de Resend, dominio verificado, DNS de autenticación y remitente aprobado. La validación sintáctica local no verifica un dominio ni su reputación.
- Configurar `MATRICULA_MAIL_ENABLED`, `MATRICULA_MAIL_QUEUE_CONNECTION` y `MATRICULA_MAIL_QUEUE`; las variables están documentadas en `.env.example`, sin alterar `.env` real.
- Operar scheduler y worker existentes con la misma base y configuración; el worker debe escuchar la conexión/cola elegida. Su timeout y tiempo de visibilidad/reintento deben superar la petición HTTP y respetar el timeout del job. El valor `retry_after` del driver database existente es 90 s, superior a 30 s del job.
- Revisar los avisos acumulados mientras estaba desactivado antes de habilitar el despacho general.
- Monitorizar `pending` atrasado, `queue_unavailable`, `processing_unavailable`, `failed` y `uncertain`. El campo `provider_id` permite investigar aceptación en Resend; no se implementaron estados de entrega por webhook.

### Prueba manual de entregabilidad preparada, no ejecutada

Requiere autorización explícita para **un destinatario controlado**, remitente aprobado y un solo envío. Usar una instalación/base desechable autorizada, sin conectar bases reales ni importar estudiantes; comprobar que únicamente existe la matrícula ficticia destinada al buzón autorizado. Mantener envío deshabilitado mientras se verifica el destinatario, contenido, remitente, dominio y ausencia de otros pendientes. Autorizar entonces activación temporal, un único despacho y ejecución del worker; registrar solo ID del proveedor y resultado, comprobar HTML/texto en el buzón y volver a deshabilitar. No probar fallos provocando reenvíos reales. No se creó un endpoint público ni un comando que acepte un destinatario arbitrario.

## Validación

Las pruebas usan SQLite `:memory:` y `Http::preventStrayRequests()` con respuestas simuladas. La prueba de cola ejecuta un job real del driver database **dentro de SQLite en memoria**, con HTTP simulado. No hay evidencia PostgreSQL, concurrencia multiproceso real, DNS, disponibilidad de Resend ni entrega a Gmail/Outlook.

- Pruebas específicas finales: 24 pruebas, 148 aserciones, aprobadas; commit/rollback, panel y permisos, duplicados/ediciones, estados, email inválido/ausente, HTTP fuera de transacciones, ambas partes del correo, cifrado, cola caída, recuperación de reservas, exclusión de worker repetido y propietario tardío, backoff/429, timeout, 5xx, respuesta inválida, ventana vencida y configuración incompleta.
- Suite completa final: 537 pruebas, 2.647 aserciones, aprobadas (50,402 s), exclusivamente SQLite en memoria.
- Larastan: sin errores en el alcance configurado de servicios y soporte.
- `npm run build`: aprobado; no se modificó el frontend de la aplicación.
- Pint: aprobado, limitado al inventario PHP propio.
- JavaScript: 7 pruebas aprobadas con `npm test`.
- `git diff --check`: aprobado.

## Inventario exclusivo

- `.env.example` (solo variables de ejemplo).
- `app/Models/Matricula.php` (observer y tipos de relaciones).
- `app/Models/MatriculaNotification.php` (nuevo).
- `app/Observers/MatriculaNotificationObserver.php` (nuevo).
- `app/Services/MatriculaNotificationService.php` (nuevo).
- `app/Jobs/SendMatriculaWelcome.php` (nuevo).
- `app/Mail/MatriculaWelcome.php` (nuevo).
- `app/Console/Commands/DispatchMatriculaNotifications.php` (nuevo).
- `config/matricula-mail.php` (nuevo).
- `database/migrations/2026_10_28_000020_create_matricula_notifications_table.php` (nuevo).
- `routes/console.php` (solo tarea programada).
- `resources/views/emails/matricula-welcome.blade.php` (nuevo).
- `resources/views/emails/matricula-welcome-text.blade.php` (nuevo).
- `tests/Feature/MatriculaNotificationTest.php` (nuevo).
- `docs/sprint-05-resend-matriculas.md` (nuevo).

Se preserva el trabajo preexistente de chatbot, reportes, matrículas, README y pruebas concurrentes. No se modifican Docentes, Sesiones, Certificados, horus_group, infraestructura ni salvaguardas PostgreSQL.

## Límites y siguiente mejora

La recuperación requiere scheduler y worker operativos, y almacenamiento persistente compartido. Las pruebas de locks SQLite no acreditan exclusión mutua PostgreSQL. Un error de infraestructura local sostenido necesita intervención; no se descartan avisos silenciosamente para esconderlo. Los borrados físicos de matrícula eliminan su aviso por FK. No se reconstruyen avisos históricos ni se reenvían automáticamente estados terminales. La vista HTML se verificó con render y contenido, sin validación en clientes de correo reales.

Siguiente mejora recomendada: después de la activación y prueba manual autorizadas, consumir eventos firmados de Resend de entrega/rebote para distinguir aceptación de entrega y definir alertas operativas. No es necesario para desacoplar la bienvenida y no se amplió este sprint a esa infraestructura.
