# Seguridad L1.1: administración, secretos, exportaciones y permisos de matrículas

Este documento recoge las decisiones de la fase L1.1 y los **procedimientos manuales** que no se ejecutan desde el código. Ningún paso de aquí se ha ejecutado contra una base real: son instrucciones para que una persona las aplique, con copia previa y verificación.

> Regla general: no pegues valores de claves, hashes ni datos de usuarios en incidencias, chats o registros. Las consultas de abajo devuelven **conteos**, nunca contenidos.

## 1. Protección del administrador

Implementada en `app/Services/AdminProtection.php` y usada por `RoleController` y `UserController`.

| Operación | Regla |
| --- | --- |
| Renombrar el rol `admin` | Rechazada (`name`: «El rol principal "admin" no se puede renombrar.»). |
| Eliminar el rol `admin` | Rechazada (`rol`). |
| Quitar permisos críticos al rol `admin` | Rechazada (`permissions`). Críticos: `usuarios.ver`, `usuarios.editar`, `usuarios.asignar-roles`, `roles.ver`, `roles.editar` (`AdminProtection::CRITICAL_PERMISSIONS`). El resto de permisos de `admin` sigue siendo editable. |
| Eliminar al último usuario con rol `admin` | Rechazada (`usuario`). |
| Quitar el rol `admin` al último administrador (incluido guardar el formulario sin casillas marcadas) | Rechazada (`roles`). |
| Que un administrador se quite a sí mismo el rol `admin` | Rechazada (`roles`), aunque existan otros administradores: debe hacerlo otro administrador. |
| Que un administrador se elimine a sí mismo | Rechazada (`usuario`, ya existía). |

Notas:

- **No existe «desactivar» usuarios** en el modelo actual (la tabla `users` no tiene columna de estado), por lo que «administrador activo» equivale a «usuario existente con el rol `admin`».
- **Concurrencia.** Cada operación que puede reducir administradores se ejecuta dentro de `AdminProtection::runExclusively()`: una transacción que primero bloquea la fila del rol `admin` (`SELECT … FOR UPDATE`) y **después** cuenta administradores. Así, dos peticiones simultáneas («A degrada a B» y «B degrada a A») se serializan y la segunda ve un único administrador. SQLite (pruebas) ignora el bloqueo: la prueba real está pendiente en PostgreSQL (sección 7).
- **Caché de permisos (Spatie 6.25).** Spatie vacía su caché dentro de `syncPermissions()`, es decir, *antes* del commit; no tiene lógica de transacciones y la caché dura 24 h por defecto (no hay `config/permission.php`). Si otra petición la rellenaba entre el cambio y el commit, quedaba con los permisos anteriores. `RoleController` (actualizar y eliminar rol) ahora llama a `AdminProtection::forgetPermissionCacheAfterCommit()`, que registra la invalidación con `DB::afterCommit()`: se ejecuta **una vez, tras el commit real**, y se descarta si la transacción termina en rollback (la excepción sigue propagándose; no se oculta). Si no hay transacción activa se ejecuta de inmediato. Solo los cambios de permisos de **roles** la usan: las ediciones de usuarios no vacían la caché de permisos.
  - *Límite:* entre el commit y la invalidación queda una ventana de milisegundos con la caché anterior (inevitable sin bloquear lecturas). SQLite en memoria reproduce commit y rollback (con `RefreshDatabase`, Laravel trata la transacción de la prueba como raíz y el savepoint del controlador como la transacción real) y las pruebas simulan la petición concurrente escribiendo la caché a mano, pero **no demuestran el aislamiento entre conexiones de PostgreSQL**: eso queda en la sección 7.
- No se han cambiado los permisos de Coordinación ni de Secretaría ni se ha creado ningún rol.
- Riesgo residual conocido: `coordinador` y `secretaria` siguen pudiendo renombrarse o eliminarse desde Roles (no era parte de L1.1); hacerlo dejaría sin acceso al panel a quienes los tienen. Se recomienda protegerlos en una fase posterior.

## 2. Secreto de Resend (`api_resend`)

**Confirmado:** la aplicación no usa ese ajuste. El transporte de correo lee `RESEND_API_KEY` del entorno (`config/services.php` → `config/mail.php`). `SystemSetting::value('api_resend')` solo se leía para mostrarlo en el formulario.

Cambios de código:

- El panel ya no muestra ni acepta `api_resend`; `ConfiguracionController` solo persiste las claves de una lista permitida (`nombre_empresa`, `telefono`, `correo_contacto`, `direccion`, `moneda`).
- `SystemSettingsSeeder` ya no crea la fila.
- `AuditLog::record()` enmascara (`[REDACTADO]`) cualquier valor cuya clave parezca un secreto (`password`, `token`, `secret`, `api_*`, `resend`, `jwt`, `remember`…). La pantalla de auditoría, la exportación JSON y la exportación ZIP también enmascaran los registros **históricos**.

**No se ha borrado nada** de ninguna base. Las filas y registros antiguos siguen existiendo hasta que una persona ejecute este procedimiento.

### Procedimiento de rotación y limpieza (manual, por una persona autorizada)

1. **Rotar primero.** Si alguna vez se guardó una clave real en el panel, considérala expuesta (estuvo en texto plano en `system_settings`, en `audit_logs`, en exportaciones y posiblemente en copias de la base). En el panel de Resend, crea una clave nueva y revoca la anterior. Configura la nueva solo en el `.env` del servidor (`RESEND_API_KEY=…`); nunca en el repositorio ni en el panel.
2. **Detectar sin imprimir valores** (PostgreSQL, base de desarrollo o producción según corresponda):

   ```sql
   SELECT count(*) AS filas_api_resend
     FROM system_settings
    WHERE key = 'api_resend' AND value IS NOT NULL AND value <> 'No configurada';

   SELECT count(*) AS auditorias_con_api_resend
     FROM audit_logs
    WHERE changes::text LIKE '%api_resend%';
   ```

3. **Copia previa** de las dos tablas afectadas, guardada fuera del repositorio y con acceso restringido:

   ```powershell
   pg_dump -h 127.0.0.1 -U <usuario> -d <base> -t system_settings -t audit_logs -Fc -f <ruta-segura>\horus_antes_limpieza.dump
   ```

4. **Limpiar** en una transacción, revisando los conteos antes de confirmar:

   ```sql
   BEGIN;

   -- Neutraliza el valor sin eliminar la fila (alternativa: DELETE FROM system_settings WHERE key = 'api_resend';)
   UPDATE system_settings SET value = 'No configurada' WHERE key = 'api_resend';

   -- Elimina la clave de los registros de auditoría (changes es de tipo json)
   UPDATE audit_logs
      SET changes = (changes::jsonb - 'api_resend')::json
    WHERE changes::jsonb ? 'api_resend';

   -- Revisa los conteos de nuevo; si son los esperados:
   COMMIT;   -- o ROLLBACK;
   ```

5. **Archivos y copias que pudieron contener la clave** (no los borres sin autorización; decide con el responsable): exportaciones anteriores en `storage/app/private/exports/` (JSON de auditoría) y `storage/app/exports/` (ZIP de «respaldo»), copias de la base y cualquier descarga que ya hiciera un administrador. Si la clave se rotó en el paso 1, estas copias dejan de ser peligrosas, pero siguen conteniendo datos personales.
6. Verifica de nuevo con las consultas del paso 2 (deben dar 0 en auditoría y 0 en filas con valor).

## 3. Exportación administrativa vs. respaldo completo

`admin/respaldo` genera una **exportación administrativa minimizada**, no una copia de seguridad:

- ZIP de CSV de nueve tablas seleccionadas; sin esquema, sin otras tablas (`roles`, `permissions`, `sessions`, `chat_logs`, `chat_intents`…), sin archivos.
- **No sirve para restaurar** el sistema. El `manifest.json` lo declara (`es_respaldo_restaurable: false`).
- Minimización: se omiten columnas cuyo nombre indica un secreto (`password`, `remember_token`, `jwt_version`…), se enmascaran ajustes secretos en `system_settings` y `audit_logs.changes`, y se neutralizan celdas que empiezan por `= + - @` (inyección de fórmulas en hojas de cálculo).
- **No se conserva ninguna copia en el servidor**: el ZIP se crea en un archivo temporal, se entrega y se elimina. La exportación JSON de auditoría tampoco se guarda ya en disco.
- **Limpieza ante fallos.** `RespaldoController::exportar()` elimina **su propio** temporal (solo ese archivo; nunca borra por patrón ni toca otros) con `try/finally` si falla cualquier paso: consulta de una tabla, escritura de una entrada (`addFromString`), cierre del ZIP (`close()` devuelve `false`) o registro de auditoría. En esos casos no se entrega nada (el usuario vuelve al panel con un mensaje de error), la excepción se informa al manejador de excepciones (queda en el registro del servidor) y **no se descarga un archivo incompleto**. La exportación solo se entrega si pudo quedar registrada en auditoría. En la descarga correcta, el temporal lo elimina la propia respuesta (`deleteFileAfterSend`) al terminar de enviarla.
  - *Límite conocido:* si el cliente corta la conexión durante la descarga, PHP puede terminar antes de ejecutar ese borrado y el temporal (ya minimizado) quedar en el directorio temporal del sistema. No hay barrido automático; en Windows, los temporales de PHP se llaman `hor*.tmp`.
- Cada exportación queda en auditoría: `data_export_generated`, `audit_exported`, `matriculas_csv_exported`.
- **Archivos heredados: qué lista el panel y qué no.** Antes el ZIP «respaldo» se escribía en `storage/app/exports/` (con contraseñas hasheadas, tokens y, si existía, `api_resend`), mientras que el panel solo leía `storage/app/private/exports/` (disco `local`, carpeta `exports/`). Eso **no se ha movido ni "unificado"**: el listado de `admin/respaldo` muestra únicamente lo que hay en `storage/app/private/exports/` (p. ej. el JSON de auditoría de versiones anteriores) y **no incluye los ZIP antiguos de `storage/app/exports/`**, que pueden seguir en el servidor aunque la lista esté vacía. La vista lo advierte siempre, sin mostrar rutas. Para localizarlos use el comando de la sección 4 y decida su destino con el responsable; ninguna parte del código los lista, abre, mueve ni borra.

Los nombres de ruta y de permiso (`admin.respaldo.*`, `respaldo.ver`, `respaldo.exportar`) no cambian.

### Respaldo integral de PostgreSQL (responsabilidad del servidor, pendiente de definir)

El código de la aplicación no lo realiza. Un respaldo restaurable requiere, como mínimo:

```powershell
pg_dump -h <host> -p <puerto> -U <usuario> -d <base> -Fc -f <ruta-segura>\horus_YYYYMMDD.dump
```

más una copia del `.env` por un canal seguro (incluye `APP_KEY`; sin ella los datos cifrados y las sesiones no se recuperan) y de `storage/app/` si se guardan archivos. **Un respaldo no probado no es un respaldo**: restáuralo periódicamente en una base desechable (`createdb horus_restore_test`, `pg_restore -d horus_restore_test …`) y comprueba que la aplicación arranca contra ella.

## 4. Retención — decisión pendiente

No se ha definido ni implementado una política de retención porque es una decisión de la empresa. Qué se resolvió y qué no:

- **Resuelto sin alterar datos:** las exportaciones nuevas no persisten en el servidor, así que ya no se acumulan.
- **Pendiente de decisión:** (a) cuánto tiempo y dónde se guardan las copias de la base; (b) quién autoriza borrar los archivos heredados en `storage/app/private/exports/` y `storage/app/exports/`; (c) si se desea conservar un historial de exportaciones (hoy solo queda su registro en auditoría, no el archivo); (d) cuánto tiempo se conserva `audit_logs` (hoy, indefinidamente).
- Para revisar los archivos heredados (solo nombres, tamaños y fechas):

  ```powershell
  Get-ChildItem storage\app\private\exports, storage\app\exports -ErrorAction SilentlyContinue |
      Select-Object FullName, Length, LastWriteTime
  ```

  Elimínalos únicamente con la aprobación indicada en (b), idealmente tras comprobar si contienen la clave de Resend (sección 2).

## 5. Permisos de matrículas: antes y después

Nuevos permisos: `matriculas.exportar` (exportación CSV) y `matriculas.eliminar` (borrado lógico). Antes, el CSV bastaba con `matriculas.ver` y eliminar con `matriculas.anular`.

| Permiso / acción | admin (antes → después) | coordinador (antes → después) | secretaria (antes → después) |
| --- | --- | --- | --- |
| `matriculas.ver` (listar, ver) | sí → sí | sí → sí | sí → sí |
| Exportar CSV | sí (vía `ver`) → sí (`exportar`) | **sí (vía `ver`) → no** | **sí (vía `ver`) → no** |
| `matriculas.anular` (anular) | sí → sí | no → no | no → no |
| Eliminar (borrado lógico) | sí (vía `anular`) → sí (`eliminar`) | no → no | no → no |
| `matriculas.crear` / `editar` / `restaurar` | sin cambios | sin cambios | sin cambios |

**Decisión para la persona que revise:** por mínimo privilegio y por la instrucción de no conceder permisos nuevos a Coordinación/Secretaría, el CSV de matrículas queda solo para `admin`. Eso **retira** a Coordinación y Secretaría una capacidad que hoy tienen de hecho. Si el negocio quiere conservarla, se concede explícitamente `matriculas.exportar` al rol desde **Admin → Roles** (o añadiéndolo a `COORDINADOR_PERMISSIONS`/`SECRETARIA_PERMISSIONS` en una fase posterior). Se creó el permiso para que esa decisión pueda tomarse rol por rol.

### Aplicación en bases existentes

- Migración `2026_10_27_000012_add_matriculas_exportar_eliminar_permissions.php`: crea los dos permisos y los asigna **solo** a `admin`. Es idempotente, no toca usuarios ni otros permisos, y su `down()` elimina únicamente esos dos. **No se ha ejecutado contra ninguna base real.** Antes de `php artisan migrate`, confirma `DB_CONNECTION`, `DB_HOST`, `DB_PORT` y `DB_DATABASE` (sin imprimir credenciales) y haz copia.
- `RolesAndPermissionsSeeder` también incluye los permisos, para instalaciones nuevas y pruebas. **Atención:** `db:seed` vuelve a sincronizar los tres roles base con la matriz del seeder y **borraría** permisos que hayas concedido a mano a `coordinador`/`secretaria` (por ejemplo `matriculas.exportar`). En una base con datos reales usa la migración, no el seeder.

## 6. Qué no cambia

Sitio público, `legacy-html-node/`, lógica académica de cursos/estudiantes/matrículas, permisos de Coordinación y Secretaría, autenticación web y JWT, chat. Sin Docker, sin dependencias nuevas, sin GitHub Actions ni hosting.

## 7. Pruebas pendientes en PostgreSQL desechable (fase L2)

Las pruebas PHP usan SQLite en memoria y **no** demuestran el comportamiento de bloqueo ni de tipos de PostgreSQL. Pendiente, sobre una base creada solo para esto (`createdb horus_l2_test`, `DB_DATABASE=horus_l2_test`, migrada y con `RolesAndPermissionsSeeder`):

1. **Serialización real.** Con dos administradores A y B, abre dos sesiones `psql`: en la primera `BEGIN; SELECT * FROM roles WHERE name='admin' FOR UPDATE;` y, sin cerrarla, lanza desde la aplicación una degradación de A→B; debe quedar esperando el bloqueo. Después, dos peticiones HTTP simultáneas («A degrada a B» y «B degrada a A»): exactamente una debe prosperar y el recuento final de administradores debe ser 1.
2. **Migración de permisos** (`migrate`, `migrate:rollback --step=1`, `migrate`) sobre una base ya sembrada: dos permisos nuevos, asignados solo a `admin`, sin duplicados.
3. **Exportación ZIP** con volumen realista: tiempo, memoria (`cursor()`), columnas `json` de `audit_logs` y valores numéricos/negativos intactos.
4. **Limpieza de `api_resend`** (sección 2) ejecutada primero sobre una copia, comprobando que `changes::jsonb - 'api_resend'` conserva el resto de claves.
5. **Caché de permisos tras el commit.** Con `CACHE_STORE=database`, dos sesiones: en una, actualizar los permisos de un rol y, antes del commit (por ejemplo con un fallo forzado o una pausa), leer permisos desde otra petición; tras el commit la caché debe reflejar el cambio y, tras un rollback, no haberse vaciado.
6. **Restauración** de un `pg_dump` en otra base desechable y arranque de la aplicación contra ella.

## 8. Antes del hosting

1. Rotar y retirar cualquier clave de Resend que haya pasado por el panel (sección 2) y configurar `RESEND_API_KEY` solo en el entorno.
2. Aplicar la migración de permisos (sección 5) en el destino correcto, con copia previa, y decidir qué roles reciben `matriculas.exportar`.
3. Definir y probar el respaldo integral de PostgreSQL y la política de retención (secciones 3 y 4).
4. Decidir qué hacer con los archivos heredados de `storage/app/private/exports/` y `storage/app/exports/`.
5. Ejecutar las pruebas de la sección 7.
6. Mantener `APP_DEBUG=false`, `.env` fuera del repositorio y, si hay `config:cache`, recompilarlo tras los cambios de entorno.
7. Configurar en el entorno de producción `SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES` (IPs del balanceador, separadas por comas) y un `JWT_SECRET` propio de al menos 32 bytes (sección 9).

## 9. Endurecimiento de autenticación (web y API/JWT)

Auditoría de login, registro, logout, restablecimiento de contraseña, JWT y limitadores. Evidencia: pruebas PHPUnit en SQLite en memoria (`tests/Feature/AuthHardeningSprint22Test.php` y las existentes de Sprint 12–14) y PHPStan; **no** miden latencias reales de hash, PostgreSQL ni correo real.

| Cambio | Detalle |
| --- | --- |
| Limitadores con nombre | `register`, `password-recovery`, `verification-resend`, `matricula-publica` y `chat` (`AppServiceProvider`). Antes, `throttle:N,M` firmaba solo por dominio e IP y todas esas rutas compartían un único contador. Mismos límites que antes; registro web y API siguen compartiendo cupo. |
| Proxies de confianza | `TRUSTED_PROXIES` (lista separada por comas, o `*`) activa `trustProxies`. Vacío conserva el comportamiento anterior. Sin esto, detrás de un balanceador todos los limitadores por IP compartirían un cubo. |
| Tiempo de login | `CredentialTiming` hace siempre una única comprobación de hash, exista o no la cuenta (web y API), para no revelar correos por latencia. |
| Restablecimiento | El error es el mismo para correo inexistente, token inválido o limitación. «Olvidé mi contraseña» responde igual ante cualquier fallo del proveedor de correo. La página del token se sirve con `Cache-Control: no-store, private` y `Referrer-Policy: no-referrer`. |
| Secreto JWT | Sin `JWT_SECRET`, la clave se deriva de `APP_KEY` con HMAC (etiqueta `horus-jwt-v1`) en lugar de usarla en crudo. **Efecto al desplegar sin `JWT_SECRET`:** los JWT vigentes dejan de validar (TTL por defecto 15 min). |
| Límites JWT | TTL entre 60 y 86400 s; tolerancia de reloj entre 0 y 60 s, restaurada tras cada decodificación (`JWT::$leeway` es estático). |
| Middleware JWT | Los errores de token siguen dando 401; un fallo interno (secreto inválido, base de datos) también, pero ahora se registra con `report()`. |
| Cambio de correo | Revoca siempre los JWT (antes solo con la verificación de registro activa), sin sumar dos veces si la misma operación ya cambió la contraseña. |
| Edición de usuarios | Panel y consola dejan el correo de las cuentas que crean como verificado; quien cambia su propia clave con la verificación activa conserva su sesión. Ambos efectos solo importan si esa verificación se activa. |
| Contraseñas | Máximo 72 caracteres (bcrypt trunca más allá y no avisa). |
| Cabeceras | `SecurityHeaders`: `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy` y, en HTTPS, `Strict-Transport-Security`. |
| Content-Security-Policy | `config/csp.php`, variable `CSP_MODE`. **Por defecto `report-only`**: cabecera `Content-Security-Policy-Report-Only`, el navegador avisa en la consola de lo que bloquearía y **no bloquea nada**. `enforce` envía `Content-Security-Policy` y **sí bloquea**. `off`, o cualquier valor desconocido o mal escrito (incluidos `Enforce`/`ENFORCE`), no envía nada: un error al escribirlo nunca bloquea. Solo en respuestas HTML. Fuera de producción añade el origen de Vite leído de `public/hot`. Una cabecera CSP que ya traiga la respuesta no se sobrescribe. |

### CSP: estado y pasos

La política actual permite `'self'`, `'unsafe-eval'` en scripts (Alpine.js evalúa expresiones), `'unsafe-inline'` en estilos (atributos `style=""`), imágenes y fuentes locales o `data:`, y `https://www.google.com` solo para los iframes de Google Maps (Contactos y bloque de ubicación). **Recorrido en navegador (solo reporte).** Páginas públicas, login, registro y recuperación revisadas con la cabecera activa; el panel `/admin` solo por análisis estático de las vistas (no se inició sesión). Una limitación a tener en cuenta: la consola capturada por la herramienta de automatización **no muestra los avisos de CSP**, así que el contraste se hizo listando recursos cargados, manejadores y scripts en línea, y confirmando con una infracción provocada que la política sí se evalúa. Resultado:

- Sin orígenes externos salvo Google Maps (permitido). Sin scripts en línea ejecutables, sin enlaces `javascript:` ni CDN.
- **Manejadores `on*=` en línea (bloquearían con la política aplicada, porque no se permite `'unsafe-inline'` en scripts):**
  - `pages/quienes-somos.blade.php`: 4 × `onclick="toggleCard(this)"`.
  - `admin/aprobaciones/index.blade.php`: 4 × `onsubmit="return confirm(...)"`; `admin/usuarios/index.blade.php` y `admin/roles/index.blade.php`: 1 cada uno (confirmar eliminación).
  - `admin/reclamaciones/hoja.blade.php`: `onclick="window.print()"`.
- **Riesgo si se bloqueara sin corregir:** los formularios con `onsubmit="return confirm(...)"` se enviarían **sin pedir confirmación** (aprobar, rechazar, reabrir o eliminar), porque el manejador no se ejecutaría.
- **Corregido (manejadores en línea retirados):**
  - Confirmaciones: `<form data-confirm="...">` más un único escuchador delegado en `resources/js/confirm-forms.mjs`, iniciado desde `app.js` (prueba: `tests/frontend/confirm-forms.test.mjs`). Cubre también formularios insertados después, como los de los modales del panel.
  - Imprimir: `data-imprimir` y `public/assets/js/hoja-imprimir.js` (la hoja es una página independiente, sin Vite).
  - Tarjetas de Quiénes somos: escuchador delegado en `public/assets/js/quienes-somos.js`.
  - Quedan sin revisar en navegador las pantallas del panel que requieren sesión.

**Activación del bloqueo (`CSP_MODE=enforce`) — procedimiento:**

1. Primero en local. Recorrer panel y sitio público, **incluidos el chat enviando un mensaje, el botón Imprimir de la hoja de reclamación y los modales**, con la consola filtrada por `Content Security Policy` y **Preserve log** activado. En Edge/Chrome el aviso dice «violates the following Content Security Policy directive» y no lleva el texto `[Report Only]`: no filtrar por ese texto.
2. Prueba de control: `new Image().src = 'https://example.com/prueba.png'` debe aparecer como violación (con `enforce`, además se bloquea).
3. Si algo falla: volver a `report-only` (o `off`) y avisar con la directiva y el recurso.
4. Solo después, producción. Con `config:cache`, ejecutar `php artisan config:clear` tras cambiar el valor.

Reversa inmediata: `CSP_MODE=report-only` (o `off`).

**Vite en desarrollo y CSP.** `vite.config.js` fija `server.host = '127.0.0.1'` para que `public/hot` contenga `http://127.0.0.1:5173`, una fuente válida de CSP que se añade con su origen exacto. Una dirección IPv6 entre corchetes (`http://[::1]:5173`, el valor por defecto de Vite) **no** es una fuente válida: el navegador la descarta y, con `enforce`, bloquea el CSS y el JS de Vite (la página se ve sin estilos). Como red de seguridad, `SecurityHeaders` permite el esquema completo (`http:`/`ws:`) solo si `public/hot` aún trae una IPv6 literal, y solo fuera de producción; **en ese caso la política de desarrollo es más permisiva y no detecta recursos externos**, así que para validar el bloqueo hay que reiniciar `npm run dev` y comprobar que `public/hot` empieza por `http://127.0.0.1`. Tras cambiar `vite.config.js` hay que reiniciar `npm run dev`.

Estado de la revisión previa: panel recorrido con el reporte limpio (el único aviso fue la prueba de control). Quedan sin cubrir en navegador el chat público enviando un mensaje, el botón Imprimir y los modales no abiertos.

Para endurecer más: recorrer el sitio público y el panel con la consola abierta, corregir o permitir lo legítimo, y solo con el reporte limpio añadir un modo bloqueante. Retirar `'unsafe-eval'` exige migrar a `@alpinejs/csp`; retirar `'unsafe-inline'` de estilos exige quitar los `style=""`.

### Perfil propio (`/admin/perfil`)

`PerfilController` (rutas `admin.perfil.edit` y `admin.perfil.update`, disponible para `admin|coordinador|secretaria` sin permiso adicional). El usuario se toma siempre de la sesión: la petición no puede apuntar a otra cuenta ni tocar roles o `jwt_version`.

- Nombre: se cambia sin más. **Correo o contraseña**: exigen la contraseña actual (regla `current_password`).
- Cambiar la contraseña revoca los JWT, cierra las demás sesiones y renueva el identificador de la sesión actual, que se conserva. Cambiar el correo revoca los JWT.
- Limitador `perfil`: 5 intentos por minuto y por usuario, para que una sesión robada no sirva para adivinar la contraseña actual.
- Auditoría `perfil_actualizado`: solo qué campos cambiaron y si se modificó la clave (`clave_modificada`); nunca contraseñas ni hashes.
- Enlace «Mi perfil» en el menú lateral del panel.

### Contratos que se conservan

- El restablecimiento de contraseña **no** verifica el correo (prueba de Sprint 14).
- Cerrar sesión en la web o en la API sube `jwt_version` y revoca los JWT de **todos** los dispositivos del usuario. Es el diseño actual (el `jti` se emite pero no se almacena); una revocación por dispositivo exigiría persistirlo.

### Riesgos abiertos

- **Enumeración de correos en el registro** (web y API): con la verificación de registro apagada, un correo existente devuelve «Ya existe una cuenta con este correo.» (límite de 5 por minuto y por IP). Una respuesta neutra depende de poder confirmar el correo por email, así que queda ligada a la verificación de registro, **suspendida por ahora**.
- Con la verificación apagada, la API emite un JWT al registrarse sin comprobar el correo.
- El límite de login por correo permite que quien conozca un correo mantenga lleno su cupo y bloquee al titular.
- El cambio de contraseña de **terceros** desde `admin/usuarios` sigue sin pedir una reautenticación (lo decide quien tiene `usuarios.editar`).
- El login API responde 422 (no 401) ante credenciales inválidas; se conserva por compatibilidad de contrato.
