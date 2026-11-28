# Libro de Reclamaciones virtual: activación y uso

> **Estado (28/11/2026):** preparado en el código y **sin activar**. No se revisaron las variables ni la base de datos
> de producción. Las pruebas usan SQLite en memoria. Esta guía no garantiza el cumplimiento legal.
>
> **Incidente del 28/11/2026:** durante el desarrollo, varias ejecuciones de la suite **enviaron correos reales** de
> prueba (ver §7). Está corregido.

> **La sección del panel está oculta (28/11/2026)** por decisión del propietario: el menú muestra solo «Mensajes de
> contacto». **No actives el libro así**: las hojas se guardarían y llegarían por correo, pero no habría dónde verlas ni
> responderlas. Antes cambia `RECLAMACIONES_EN_PANEL` a `true` en `apps/web/src/lib/funciones.ts` y publica la web.
> Mientras esté oculta, no se muestran la bandeja, los expedientes, la tarjeta de plazos, la casilla «Responder
> reclamaciones» ni la tabla de reclamaciones en «Exportar datos». La API, los permisos y los datos no cambian, y las
> pruebas de `apps/web/e2e/reclamaciones-ocultas.spec.ts` habrá que retirarlas o invertirlas al reactivarla.

## 1. Cómo funciona

1. El consumidor envía el formulario de la web (`/legales/libro-reclamaciones`).
2. La hoja se guarda con número correlativo y fecha límite, y aparece en **Reclamaciones** del panel.
3. La cola envía la **copia al consumidor** y un **aviso a la empresa** con la hoja completa.
4. La respuesta se escribe **desde el panel**. Por correo, el caso pasa a «Respondido» solo cuando el correo sale.

«Responder a»: en los correos al consumidor es el buzón de la empresa; en el aviso a la empresa es el consumidor.
El dominio remitente (`horsz.win`) no tiene registro MX (comprobado el 28/11/2026): no recibe correo.

## 2. Requisitos para que se abra

Si falta cualquiera, el formulario público sigue apagado. Son variables de `horus-api`, `horus-worker` y
`horus-scheduler` en Railway; los tres deben tener los mismos valores.

| Variable | Valor |
| --- | --- |
| `RECLAMACIONES_ENABLED` | `true` |
| `RECLAMACIONES_CORREO_ENABLED` | `true` |
| `RECLAMACIONES_CORREO_EMPRESA` | Buzón de la empresa, p. ej. `horusgroupcajamarca@gmail.com` |
| `RECLAMACIONES_RAZON_SOCIAL` | Razón social registrada |
| `RECLAMACIONES_RUC` | RUC de la empresa |
| `RECLAMACIONES_DIRECCION` | Dirección del establecimiento |
| `RECLAMACIONES_CODIGO_LIBRO` | Código de identificación del libro. Lo define la empresa: no se inventa |
| `MAIL_MAILER` | `resend` |
| `MAIL_FROM_ADDRESS` | Una dirección de `horsz.win` (dominio verificado en Resend) |
| `RESEND_API_KEY` | Una clave **nueva**: la anterior quedó expuesta en una conversación y conviene revocarla |

Además, `QUEUE_CONNECTION=database` y los servicios `horus-worker` (envía) y `horus-scheduler` (plazos y avisos) en marcha.

- `MAIL_MAILER` es el correo por defecto de toda la aplicación: afecta también a la recuperación de contraseña. Los
  correos de matrícula y certificado tienen su propio interruptor y no se encienden con esto.
- Comprueba las tablas con `php artisan migrate:status`. Este cambio **no añade migraciones**. Migrar una base con datos
  exige la autorización del propietario.

## 3. Antes de abrirlo

- [ ] Un asesor revisa la hoja y sus leyendas, el plazo, la política de privacidad y el **libro físico de respaldo**
      (hoy no existe y el panel todavía no permite registrar sus hojas).
- [ ] Confirmar los feriados de `config/reclamaciones.php`. Se tomaron de portales no oficiales (la web oficial rechazó la
      lectura automática). Cubren 2026 y 2027: **hay que añadir 2028 antes de que termine 2027**.
- [ ] Confirmar el texto de privacidad publicado (versión `2026-11-28`).

## 4. Prueba real

Con un correo propio, y sabiendo que la hoja de prueba **queda registrada** (el módulo no permite borrarla y consume un
número correlativo):

1. El formulario muestra el número y la fecha límite.
2. La copia llega al correo indicado y el aviso llega a la empresa (revisar también el correo no deseado).
3. «Responder» desde cada correo apunta al destinatario correcto.
4. El caso aparece en el panel; al responder desde allí, la respuesta llega y el caso pasa a «Respondido».

## 5. Uso diario

- Acceso: por defecto solo `admin`. Los permisos son `reclamaciones.ver`, `.gestionar` y `.responder`.
- **Responde siempre desde el panel.** Si respondes solo desde el correo, el caso sigue abierto y se avisará del plazo.
- Las plantillas son solo una estructura: completa lo que está entre corchetes antes de enviar (el sistema no lo comprueba).
- Si un correo falla del todo, llega un aviso a la campana del panel. En el expediente, «Reintentar» repite la copia o la
  respuesta. El aviso a la empresa no se reintenta desde el panel: el caso ya está en la bandeja.
- Apagar `RECLAMACIONES_CORREO_ENABLED` cierra el formulario. Los casos registrados siguen con sus plazos mientras
  `RECLAMACIONES_ENABLED` y el correo sigan configurados. Una respuesta por correo registrada con el envío apagado queda
  pendiente y **no sale sola** al encenderlo: queda registrar la respuesta como carta. Evita registrarlas en ese estado.

## 6. Qué quedó fuera

- **Propuestas de solución** (y su aceptación o rechazo): apagadas en `reclamaciones.propuestas_habilitadas`, porque
  suspenden el plazo aunque el correo no salga. Antes de activarlas hay que corregir eso.
- Registro en el panel de hojas del libro físico (`GestionReclamaciones::registrarRespaldo` no tiene ruta).
- Leyendas legales de la hoja (pendientes del asesor).
- El chatbot (`apps/ai`) no se modificó. Dirige al libro virtual, lo que será correcto al activarlo, pero su respuesta de
  privacidad todavía dice que los datos no se comparten con terceros: conviene revisarla.

## 7. Incidente: correos reales desde las pruebas (28/11/2026)

- **Qué pasó:** salieron correos de prueba por el SMTP de Gmail configurado en el `.env` local, desde esa cuenta
  personal. Los destinatarios de las hojas eran direcciones ficticias en el dominio reservado `.test`
  (`empresa@horus.test`, `persona.ficticia@example.test`), con datos inventados; los rebotes llegaron al remitente.
- **Causa:** al exigir el envío activo para abrir el libro, la activación simulada de las pruebas empezó a encender el
  envío y seguía apuntando al transporte `smtp`. `phpunit.xml` no anulaba las credenciales del `.env`, y la prueba del
  webhook (`Ola3RecordatoriosYWebhooksTest`) no simula el correo ni la cola, así que la cola `sync` envió de verdad.
- **Corrección:** `phpunit.xml` deja vacías las credenciales de SMTP y Resend y apunta el SMTP a `127.0.0.1:1`; la
  activación simulada usa un transporte en memoria. `CorreoDePruebasAisladoTest` falla si cualquiera de las dos se pierde.
- **Pendiente:** los perfiles de PostgreSQL (`phpunit.pgsql*.xml`, suspendidos y no ejecutados) no se modificaron.

