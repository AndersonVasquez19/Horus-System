# Canales de aviso: qué está activo y cómo cambiarlo (19/11/2026)

Decisión del propietario: usar solo canales simples y sin cobro por mensaje. **WhatsApp Cloud (Meta) queda apagado**: exige verificación de empresa, plantillas aprobadas y se cobra por mensaje. El código se conserva, apagado por defecto.

## Estado

| Canal | Estado | Cómo funciona |
| --- | --- | --- |
| Avisos internos (campana del panel) | **Activo en local** (`RECORDATORIOS_INTERNOS_ENABLED=true`) | Recordatorios de cuotas, sesiones y reclamaciones sin salir del sistema. |
| WhatsApp manual (`wa.me`) | Activo, sin configuración | Botón «WhatsApp» por fila: abre el chat con el mensaje escrito y lo envía la persona de secretaría. No usa ninguna API. |
| Correo manual (`mailto:`) | Activo, sin configuración | Botón «Correo» por fila: abre el cliente de correo de quien lo pulsa. |
| Correo automático de recordatorios (Resend) | **Apagado** (`RECORDATORIOS_MAIL_ENABLED`) | Se enciende tras una prueba con un destinatario autorizado; ver abajo. |
| WhatsApp Cloud | **Apagado** (`WHATSAPP_CLOUD_ENABLED`) | Sin cuenta de Meta, token ni plantillas. No se usa. |
| Bloqueo automático / alertas de acceso | **Apagado** (`SEGURIDAD_*`) | 2FA de administradores no es obligatorio; los administradores nunca se bloquean automáticamente. |

El `.env` de desarrollo es local (`APP_ENV=local`). Nada de esto está activo en producción.

**Correo saliente por SMTP (local, probado el 19/11/2026):** el mailer por defecto de Laravel (`MAIL_MAILER=smtp`, `smtp.gmail.com:587`, contraseña de aplicación de Google; el remitente debe ser la misma cuenta) envió correctamente un correo de prueba. Sirve para lo que usa el mailer de Laravel, como la recuperación de contraseña. **No** lo usan los recordatorios automáticos: `EnvioAvisosExternosService` llama directo a la API de Resend (`RESEND_API_KEY`), que no admite remitentes `@gmail.com` sin un dominio propio verificado. Por eso `RECORDATORIOS_MAIL_ENABLED` sigue apagado. Opciones futuras: dominio propio verificado en Resend, o adaptar ese servicio al mailer de Laravel (se perderían la clave de idempotencia y los estados del webhook).

## Cómo apagar un canal
Quitar la variable o ponerla en `false` y ejecutar `php artisan config:clear`. Los avisos pendientes no se envían al reactivar.

## Correo automático (cuando se decida activarlo)
1. Requiere `RESEND_API_KEY` válida y un `MAIL_FROM_ADDRESS` de un dominio verificado en Resend (SPF/DKIM); los recordatorios usan la API de Resend directamente, no el mailer SMTP de Laravel. Usar SMTP (Gmail, Brevo…) para los recordatorios exige adaptar `EnvioAvisosExternosService`.
2. Probar primero con un destinatario autorizado por el propietario (perfil con «correo» activado) y comprobar en `avisos_externos` el estado del aviso.
3. El texto hacia Resend es genérico: sin nombre del estudiante ni montos.
4. `RESEND_WEBHOOK_SECRET` es opcional: solo añade los estados «entregado», «rebotado» y «queja». Sin él los avisos quedan en «enviado».
5. Para la **producción**: publicar antes el aviso de privacidad vigente (hoy `2026-10-30-borrador`) que mencione a Resend como encargado del tratamiento y aceptar su acuerdo de tratamiento de datos. La redacción debe revisarla un abogado (Ley N.º 29733).
6. Requiere `queue:work` y `schedule:work` (o cron) corriendo: el scheduler ejecuta `horus:recordatorios-diarios` a las 07:00 de Lima y `horus:avisos-despachar` cada minuto.

## Comprobación manual
`php artisan horus:recordatorios-diarios --dry-run` muestra los conteos sin escribir nada. Sin `--dry-run` crea las notificaciones internas.

Ver también `docs/auditoria-olas-1-3-correcciones.md` (seguridad de cuentas y avisos).
