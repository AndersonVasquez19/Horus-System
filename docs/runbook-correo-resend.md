# Runbook · Activar el correo con Resend y el dominio horsz.win (Cloudflare)

> Alcance: guía de activación del correo de **bienvenida de matrícula** y del **certificado por correo**. Lo que hace el código está probado en SQLite en memoria con correo simulado; **no se envió ningún correo real** ni se tocó ninguna base real. Los pasos 1 a 3 los haces tú (cuentas y DNS); yo no tengo acceso a Resend ni a Cloudflare.

## Qué se enciende

| Correo | Variable | Cómo sale | Estado hoy |
| --- | --- | --- | --- |
| Bienvenida de matrícula | `MATRICULA_MAIL_ENABLED` | Bandeja `matricula_notifications` + scheduler `horus:matriculas-notificar` + worker → API HTTPS de Resend con idempotencia | Apagado |
| Certificado por correo | `CERTIFICADO_MAIL_ENABLED` (y opcional `CERTIFICADO_MAIL_AUTO`) | Botón «Enviar por correo» en el certificado (permiso `certificados.emitir`), o al emitir si `AUTO=true`. Cola + mailer de Laravel (`MAIL_MAILER=resend`) | Apagado |
| Recuperación de contraseña | (ya existe) | Mailer por defecto | Con `MAIL_MAILER=resend` también sale por Resend |

El certificado lleva el **enlace de verificación** `HORUS_WEB_URL/verificar/CÓDIGO` (sin adjuntos). El QR del PDF ahora apunta a esa misma página. La página muestra solo estado, código, curso, fecha y nombre abreviado del titular.

## 1. Resend: dominio y clave
1. Crea cuenta en Resend y agrega el dominio `horsz.win` (Domains → Add Domain). Región cercana a tus usuarios.
2. Resend te muestra registros DNS (SPF/TXT, DKIM/CNAME o TXT y, opcional, MX para rebotes). Cópialos tal cual.
3. En **Cloudflare → horsz.win → DNS → Records** crea cada registro con el tipo y valor exactos que da Resend. Los CNAME deben ir **sin proxy** (nube gris, «DNS only»).
4. Vuelve a Resend → **Verify DNS records**. Puede tardar de minutos a unas horas. El dominio debe quedar en «Verified».
5. Crea una **API key** con permiso «Sending access», limitada al dominio. Guárdala solo en tu `.env` o en las variables del servidor; nunca en el repositorio ni en el chat.

Recomendado en Cloudflare, aparte: un registro DMARC (`_dmarc` TXT, por ejemplo `v=DMARC1; p=none; rua=mailto:horusgroupcajamarca@gmail.com`) para empezar a monitorear.

## 2. Variables (local o servidor)

```
HORUS_WEB_URL=https://<tu dominio público de la web>   # obligatoria: arma el enlace del certificado y de recuperar contraseña
MAIL_MAILER=resend
MAIL_FROM_ADDRESS=matriculas@horsz.win
MAIL_FROM_NAME="Horus Group"
RESEND_API_KEY=<tu clave>
QUEUE_CONNECTION=database
MATRICULA_MAIL_ENABLED=true
CERTIFICADO_MAIL_ENABLED=true
CERTIFICADO_MAIL_AUTO=false      # recomendado al empezar: el personal envía con el botón
```

Luego `php artisan config:clear`. El remitente debe ser de un dominio verificado en Resend; un `@gmail.com` o `@example.*` hace que el sistema considere el envío «no configurado» y no envíe (a propósito).

## 3. Procesos que deben estar corriendo
El correo **no sale** sin ellos:
- `php artisan queue:work` (o el worker del servidor).
- `php artisan schedule:work` (o cron cada minuto con `schedule:run`): despacha la bandeja de matrículas cada minuto.

## 4. Migraciones pendientes (no aplicadas a ninguna base real)
Antes de encender el certificado por correo hay que aplicar `2026_11_24_000103_add_correo_to_certificados_table` (columnas `correo_enviado_at` y `correo_error`). Las demás pendientes siguen la lista del plan. Apliques lo que apliques, primero respaldo; yo no las ejecuto sin tu autorización expresa.

## 5. Orden de encendido recomendado
1. Dominio verificado en Resend + registros en Cloudflare (pasos 1 a 3 arriba).
2. Variables **solo en local**, con `CERTIFICADO_MAIL_ENABLED=true` y tu propio correo como estudiante de prueba. Emite o usa un certificado de prueba y pulsa «Enviar por correo». Comprueba: llega, el enlace abre la página de verificación, el QR del PDF abre la misma página.
3. Matrícula de prueba con tu correo (`MATRICULA_MAIL_ENABLED=true`): comprueba que llega la bienvenida y que la fila queda en `accepted` en `matricula_notifications`.
4. Solo entonces producción (misma lista de variables en el servidor, worker y scheduler corriendo).
5. `CERTIFICADO_MAIL_AUTO=true` solo cuando el envío manual lleve unos días sin problemas.

Un aviso capturado mientras el envío estaba apagado queda `held` y **nunca** se despacha al encender después: la bienvenida solo sale para matrículas creadas con el envío ya activo.

## 6. Antes de producción: privacidad y acuerdos
Lo pide el propio proyecto (`CLAUDE.md` y `docs/activacion-canales-externos.md`): publicar el aviso de privacidad vigente (hoy `2026-10-30-borrador`) que mencione a Resend como encargado del tratamiento y aceptar el acuerdo de tratamiento de datos de Resend (DPA). La redacción conviene que la revise un abogado (Ley N.º 29733). El correo del estudiante y su nombre viajan a Resend en ambos envíos.

## Cómo apagarlo
Poner `MATRICULA_MAIL_ENABLED=false` y/o `CERTIFICADO_MAIL_ENABLED=false` y `php artisan config:clear`. Los trabajos ya encolados del certificado no se envían si el envío está apagado cuando les toca; la bienvenida pendiente sigue las reglas de retención.

## Qué comprobar si algo no llega
- `php artisan queue:failed` y `storage/logs`: errores de cola o del proveedor (el sistema no registra la dirección ni el cuerpo).
- Certificado: columna `correo_error` (`fallo_envio` tras 4 intentos, `sin_correo`) y auditoría `certificado_correo_fallido`.
- Matrícula: tabla `matricula_notifications` (`status`, `last_error`).
- Resend → Logs: estado de entrega y rebotes.
