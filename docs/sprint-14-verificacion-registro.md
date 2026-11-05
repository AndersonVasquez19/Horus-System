# Sprint 14: alta pendiente y verificación de correo

Fecha: 28 de octubre de 2026. Rama `anderson`. Implementación backend de la opción 1 autorizada por el propietario: contraseña definitiva después de acreditar control del correo. Sin activación ni integración visual.

## Contrato anterior y compatibilidad

La revisión comprende rutas web/API, controladores de registro/login/recuperación, middleware JWT, servicio JWT, `User`, esquema de usuarios, correo Laravel y pruebas existentes. Se conserva la base local de Sprint 13 y el índice previo.

| Aspecto | Comportamiento previo, conservado con la opción apagada |
| --- | --- |
| Registro web | Valida nombre, email, contraseña confirmada y términos; unicidad por `lower(email)`; crea usuario sin roles; 302 hacia login con mensaje original. No inicia sesión |
| Registro API | Valida nombre, email y contraseña; misma unicidad; transacción de usuario y emisión JWT; 201 con `user`, `access_token`, `token_type`, `expires_in` |
| Sesión web | El login usa credenciales y regenera la sesión; personal con rol accede al panel según sus permisos; otras cuentas van a inicio |
| JWT | Login/registro API emiten tokens; middleware valida firma, expiración y versión de credenciales; los cambios de contraseña revocan JWT |
| Verificación | `email_verified_at` ya existe y tiene cast datetime; la clase base de Laravel ya aporta métodos de verificación, pero el modelo no implementaba el contrato `MustVerifyEmail` ni exigía verificación |
| Autorización | `/admin` exige sesión, roles de personal y permisos por acción; `/api/auth/me` y logout usan `jwt.auth`; no se cambian roles ni permisos |
| Correo | Laravel Mail es reutilizable. El Resend de matrículas tiene bandeja/job/estados propios y no se reutiliza para identidad. La recuperación usa una notificación y plantilla existentes, que permanecen intactas |

`config/registro-verificacion.php` contiene **`enabled => false` y `mailer => null`**. No lee una variable de entorno que pueda activarla inadvertidamente. No se modifican `.env`, credenciales, `config/mail.php` ni la configuración de matrículas. Los endpoints nuevos devuelven 404 cuando la opción está apagada. El resto de la suite conserva los contratos anteriores, incluido el error explícito de correo duplicado.

## Contrato preparado cuando se habilite

| Endpoint | Entrada / respuesta |
| --- | --- |
| `POST /register`, formulario | Valida los campos originales sin consultar unicidad. 302 hacia login con el mismo mensaje neutro para correo nuevo o existente. No crea usuario ni sesión autenticada |
| `POST /register`, solicitando JSON, y `POST /api/auth/register` | 202 con exactamente `{"message":"Si la solicitud puede continuar, recibirás instrucciones en ese correo."}`. Sin usuario, token, indicador de existencia o estado de entrega |
| `POST /email-verification/resend` y `/api/auth/email-verification/resend` | Email normalizado válido; 202 con el mismo cuerpo neutro. Es una solicitud de código incluso si no había una solicitud anterior; nunca crea cuenta |
| `POST /email-verification/confirm` y `/api/auth/email-verification/confirm` | `token` hexadecimal de 64 caracteres, `name`, `password`, `password_confirmation`, `terms` aceptados. Código válido: 200 `{"message":"Correo confirmado. Puedes iniciar sesión o recuperar tu acceso."}`. Inválido/caducado/consumido: 422 con el mismo mensaje de código inválido. No inicia sesión ni emite JWT |

Confirmación y reenvío son endpoints backend, sin GET de consumo, pantallas nuevas ni enlaces desde la web. Las rutas web conservan CSRF y `guest`; las API no dependen de sesión. La confirmación devuelve JSON también desde el grupo web para no guardar códigos/contraseñas en la entrada anterior. Los errores de formato o política son validaciones y no consultan existencia de cuentas.

La contraseña inicial sigue validándose para conservar la entrada de los formularios actuales, pero **no se guarda ni se pasa al servicio de solicitudes**. El nombre definitivo y la contraseña elegida por quien posee el código se reciben en la confirmación. No se persiste un hash de la contraseña inicial ni se envía por correo.

Este contrato es incompatible con clientes API que esperan 201/JWT inmediato y con un registro web que finaliza en un solo formulario. Está implementado detrás de la opción apagada; no es autorización para activarlo. El código se entrega exclusivamente por un correo nuevo e independiente: no aparece en respuestas, querystrings ni URLs. El cliente futuro deberá permitir introducir ese código y elegir la contraseña definitiva.

## Estado, seguridad y cuentas existentes

- `registros_pendientes` guarda un HMAC SHA-256 del correo normalizado como índice único, correo cifrado por el encrypter de Laravel, SHA-256 del código, caducidad, última solicitud y consumo. No tiene columnas de contraseña o nombre inicial. El modelo oculta todos sus atributos al serializar.
- El código contiene 256 bits obtenidos de `random_bytes(32)`, codificados en hexadecimal; caduca a los **30 minutos**. Su resumen permite buscarlo sin almacenar el valor enviado. No se reutiliza el repositorio de recuperación para verificar registro.
- `solicitar()` no consulta `users`: las cuentas nuevas, verificadas y sin verificar siguen la misma escritura de solicitud y el mismo envío. `firstOrCreate`, bloqueo de fila y transacción protegen la reserva; un cooldown de **60 segundos por correo normalizado** abarca tanto registro como reenvío. Una repetición durante ese plazo conserva el código y la respuesta neutra. Un reenvío posterior sustituye el resumen e invalida el anterior.
- Registro y reenvío conservan/comparten `throttle:5,1` para visitantes por dominio/IP. Confirmación web/API comparte un limitador nombrado de **10 por minuto por IP**. No se deshabilitan límites en las pruebas de esos escenarios ni se amplían timeouts.
- `confirmar()` bloquea la solicitud, comprueba caducidad/consumo y ejecuta una actualización condicional de un único consumo dentro de la transacción. La creación/habilitación, invalidación de recuperación y evento `Verified` forman parte de esa transacción. Las pruebas comprueban rollback ante fallo del listener.
- **Cuenta nueva:** se crea al confirmar, con nombre y contraseña definitivos, email normalizado y `email_verified_at`. No recibe rol alguno.
- **Cuenta existente ya verificada:** se consume el código y responde lo mismo; no se cambian contraseña, nombre, roles, verificación ni tokens de recuperación. La solicitud no es un mecanismo para reemplazar credenciales de una cuenta verificada: la recuperación existente sigue disponible.
- **Cuenta existente sin verificar:** la prueba de control del correo permite establecer contraseña definitiva y verificar. Se conserva nombre/roles, se incrementa `jwt_version`, rota el remember token y se revocan sesiones soportadas. Es una recuperación explícita basada en posesión del código, no la activación de la contraseña que envió otra persona al iniciar el registro.
- Al establecer contraseña se eliminan también tokens de recuperación anteriores, incluidos los que pudieran sobrevivir al borrado de otra cuenta con el mismo correo. No se deja un token huérfano capaz de restablecer la cuenta recién creada.

Se usa el contrato nativo `MustVerifyEmail`, `hasVerifiedEmail()`, `markEmailAsVerified()`, evento `Verified`, Mail y hashing de Laravel. Se sustituye `sendEmailVerificationNotification()` por una delegación al servicio propio solo si la opción está habilitada. No se usa la notificación nativa de enlace firmado: presupone un usuario y una autenticación previos y no cubre por sí sola este alta pendiente con contraseña posterior. No se introducen paquetes.

## Sesiones, JWT y otros canales

Con la política habilitada, el login web condiciona la autenticación a email verificado usando `attemptWhen`; una cuenta ausente, contraseña incorrecta o cuenta sin verificar recibe el error genérico existente. Un login correcto regenera la sesión y guarda ID y `jwt_version` como marcador servidor. El middleware web exige verificación y coincidencia de ambos valores: descarta sesiones anteriores, sin marcador o con versión antigua, sin depender del driver de sesión. Devuelve la redirección a login o 401 JSON, antes de permitir continuar.

Esta protección cubre una sesión que permanezca inactiva durante la verificación y reaparezca después. Borrar solo sesiones con driver `database` no bastaba para file/redis/cookie; la regresión reprodujo ese fallo antes de añadir el marcador. **La activación obligará a reautenticarse también a los usuarios verificados que tengan sesiones anteriores sin marcador.**

El login API habilitado usa la misma respuesta 422 genérica para ausencia, contraseña incorrecta y cuenta sin verificar; genera y comprueba un hash también en el caso ausente. `JwtTokenService::issue()` rechaza cuentas sin verificar aunque se invoque directamente; `AuthenticateJwt` tampoco acepta sus JWT emitidos antes de habilitar la política. La confirmación no emite JWT; el usuario debe iniciar sesión con la contraseña definitiva.

Un cambio de email por Eloquent mientras la política está habilitada retira verificación, incrementa versión de credenciales y rota remember token. No cambia roles ni permisos. Las escrituras masivas/directas que omitan eventos de modelo necesitan revisión operativa independiente.

La recuperación mantiene el flujo existente y no verifica automáticamente cuentas. Se reprodujo otra diferencia observable: un token arbitrario generaba `INVALID_USER` para correo ausente e `INVALID_TOKEN` para existente. Solo con la nueva opción habilitada se traduce todo rechazo del broker al mensaje de token inválido ya existente. Apagada, conserva su contrato anterior. No se atribuye a este cambio uniformidad temporal del broker o transporte de recuperación.

## Correo y fallos de entrega

`VerificarRegistroMail` es un Mailable nuevo con contenido independiente en backend; no utiliza ni modifica las plantillas HTML existentes, Blade, CSS o JS. El código es privado en el objeto, no se encola y no se añade a metadatos. Las pruebas usan `Mail::fake()` y mocks; no se envían correos reales.

Debe seleccionarse explícitamente un mailer existente autorizado. El servicio **no cae al mailer general**, cuyo valor podría ser `log`. Acepta transportes directos conocidos (`smtp`, `resend`, `ses`, `ses-v2`, `postmark`, `mailgun`); `array` solo se admite en testing. Rechaza `log`, sendmail, failover, roundrobin y transportes no reconocidos para evitar registrar o derivar códigos por una ruta no aprobada. Esta allowlist no configura ni habilita esos proveedores; no se activa Resend.

La solicitud y el resumen del código se guardan antes de llamar al mailer; el envío ocurre fuera de transacción. Una excepción en esa frontera devuelve la misma respuesta neutra y registra únicamente `auth.registration_verification` con `outcome=delivery_unavailable`, sin excepción original, destinatario, token ni respuesta del proveedor. Un transporte ausente/no admitido produce `transport_not_configured`, también sin valores sensibles. No hay reintento automático: una excepción puede representar entrega incierta. El usuario puede solicitar un código nuevo tras el cooldown, sujeto a los límites existentes.

La aceptación HTTP no confirma que el correo haya sido enviado o entregado. Si el proveedor no está disponible, la cuenta nueva permanece sin crear y no hay sesión ni JWT; la solicitud caduca normalmente. Una activación sin transporte validado impediría completar altas, por lo que queda prohibida sin la validación operativa posterior.

## Pruebas y revisión

`tests/Feature/Sprint14EmailVerificationTest.php` contiene 28 casos contando datasets, todos en SQLite `:memory:` con sesión/caché `array`, cola `sync`, correo simulado y HTTP no simulado bloqueado. Cubren:

- Registro nuevo/existente indistinguible en ambos canales, errores de otros campos sin señal de unicidad, redirección web neutra y ausencia de cuentas/credenciales previas.
- Contraseña inicial no persistida, email cifrado, código solo resumido, serialización oculta y normalización.
- Confirmación válida, inválida, caducidad exacta, consumo repetido y rotación por reenvío; contraseña definitiva y ausencia de roles.
- Cuentas verificadas intactas; cuentas sin verificar bloqueadas antes y habilitadas después; JWT anterior rechazado y emisión directa bloqueada.
- Sesión web antigua, login web posterior, cambio de email y versión de credenciales.
- Reenvío/cooldown compartidos, límites de solicitudes/confirmación y expiración del límite.
- Fallo simulado de transporte, mailer no seleccionado y rechazo del transporte log.
- Recuperación sin bypass de verificación, errores de broker uniformes y eliminación de tokens huérfanos.
- Validación sin flash de secretos, rollback de cuenta/consumo, opción apagada, notificación nativa apagada y migración sobre usuarios/roles ya sembrados.

La primera ejecución de los 16 casos iniciales falló antes de implementar la funcionalidad. Las regresiones de error de broker, sesión antigua y recuperación huérfana se reprodujeron separadamente antes de su corrección. La revisión independiente fue de solo lectura y confirmó el cierre de ambos hallazgos, con 2 pruebas/16 aserciones ejecutadas independientemente.

| Validación | Resultado |
| --- | --- |
| Filtro Sprint 14, autenticación anterior y salvaguardas | 166 pruebas, 991 aserciones, aprobadas |
| Larastan nivel 6, alcance ampliado sin supresiones | Sin errores |
| Pint sobre los 21 archivos PHP propios | `--test` aprobado |
| Suite completa SQLite | **793 pruebas, 5024 aserciones**, aprobadas; 67,33 s |
| `git diff --check` | Aprobado; advertencias LF/CRLF conocidas |

## Archivos propios

Modificados: `app/Http/Controllers/Admin/AuthController.php`, `app/Http/Controllers/Api/AuthController.php`, `app/Http/Controllers/Auth/ResetPasswordController.php`, `app/Http/Middleware/AuthenticateJwt.php`, `app/Models/User.php`, `app/Services/JwtTokenService.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `routes/api.php`, `routes/web.php`.

Nuevos: `app/Http/Controllers/Auth/VerificacionRegistroController.php`, `app/Http/Middleware/DescartarSesionSinVerificar.php`, `app/Http/Middleware/VerificacionRegistroHabilitada.php`, `app/Http/Requests/ConfirmarRegistroRequest.php`, `app/Http/Requests/ReenviarRegistroRequest.php`, `app/Mail/VerificarRegistroMail.php`, `app/Models/RegistroPendiente.php`, `app/Services/VerificacionRegistroService.php`, `config/registro-verificacion.php`, `database/migrations/2026_10_28_000022_create_registros_pendientes_table.php`, `tests/Feature/Sprint14EmailVerificationTest.php`, `docs/superpowers/plans/2026-10-28-sprint-14.md` y este informe.

Total: 23 archivos propios (21 PHP y 2 documentos). El índice coincide con el inicial y el frontend versionado coincide con HEAD. Se preservan los demás cambios locales anteriores. No se modifica `phpstan.neon.dist`: continúa en nivel 6.

## Requisitos y riesgos de activación

1. Autorización expresa para activar el contrato nuevo y adaptar clientes API. **No basta desplegar el código.** La configuración permanece false/null.
2. Autorizar e implementar posteriormente el recorrido web de código y contraseña definitiva. No se modifican ahora formularios, pantallas ni contenido público; el backend no constituye por sí solo una experiencia web completa de confirmación.
3. Validar un mailer autorizado, entrega real, remitente, errores, cuotas y ausencia de cuerpos/códigos en logs. Esta validación y cualquier correo real requieren autorización independiente; no forman parte de esta ejecución.
4. Revisar y autorizar por separado la aplicación de la migración en una base real, sin tocar S7 suspendido. La migración solo crea la tabla independiente; no publica cursos, modifica usuarios reales ni activa la función.
5. Revisar usuarios históricos: cuentas con email sin normalizar, cuentas administrativas sin verificar y recuperación de acceso. No se verifican masivamente ni se normalizan datos reales. La comparación de login anterior continúa siendo por columna/email normalizado, por lo que no se garantiza acceso de filas históricas con mayúsculas en una base con comparación sensible a mayúsculas.
6. Preparar comunicación de reautenticación de sesiones anteriores, supervisión operativa y un procedimiento autorizado de desactivación. Apagar la opción restablece los contratos previos, incluida su enumeración explícita y aceptación de cuentas antiguas sin verificar; no equivale a revertir las cuentas ya confirmadas.
7. Definir retención y limpieza de solicitudes caducadas. La caducidad impide consumir códigos, pero no elimina las filas automáticamente. IPs distribuidas/correos distintos pueden incrementar almacenamiento y coste de correo; los límites locales no certifican protección contra abuso distribuido.

No se declara resuelta la enumeración: mientras la opción está apagada permanece el contrato anterior. Habilitada, las pruebas acreditan igualdad de respuestas de registro y rechazos relevantes, no indistinguibilidad temporal de todos los canales, costes históricos de hash, recuperación ni transporte real. La recuperación sigue enviando solo a cuentas conocidas y su latencia puede diferir. Tampoco se han probado PostgreSQL real, bloqueos/concurrencia, DDL real, entrega, infraestructura o despliegue. Un evento síncrono externo añadido en el futuro no sería reversible por la transacción local.

Sin frontend, plantillas existentes, credenciales, PostgreSQL/S7, matrículas Resend, OpenAI, módulos protegidos, commits, push ni despliegue.
