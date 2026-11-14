# Fase 5.1 — Gestión multimedia, recuperación, producción y PostgreSQL (12/11/2026)

Sin módulos nuevos, sin migraciones, sin cambios en Horus AI, Blade, formularios públicos ni autenticación. Sustituye la propuesta de imágenes de `docs/fase-5-estabilizacion-paneles.md` §5, que ahora está **implementada**.

## 1. Imagen principal y tarjetas de la ficha de servicios

**Flujo:** navegador → `/api/bff/panel/servicios/servicios/{id}/…` (Next) → `/api/panel/v1/servicios/servicios/{id}/…` (Laravel) → disco `public` de Laravel. El navegador no habla con Laravel y nunca recibe rutas de almacenamiento: la vista previa se sirve por rutas autenticadas.

| Operación | BFF / Laravel | Permiso |
| --- | --- | --- |
| Ver imagen principal / de tarjeta | `GET …/imagen`, `GET …/elementos/{n}/imagen` | `servicios.ver` |
| Subir o reemplazar imagen principal | `POST …/imagen` (multipart: `imagen`, `imagen_principal_alt`, `_revision`) | `servicios.editar` |
| Quitar imagen principal | `POST …/imagen/quitar` | `servicios.editar` |
| Crear / editar tarjeta (con imagen opcional) | `POST …/elementos`, `POST …/elementos/{n}` (multipart) | `servicios.editar` |
| Eliminar tarjeta | `POST …/elementos/{n}/eliminar` | `servicios.editar` |

**Reutilización:** Laravel usa los mismos Form Requests (`SubirImagenPrincipalRequest`, `GuardarServicioElementoRequest`), `ImagenesServicios` (almacenamiento, nombre generado `servicios/<uuid>.<ext>`, validación) y `GestionServiciosTecnologicos::contenido()` (bloqueo, `If-Match`, ficha no publicada, auditoría, limpieza de huérfanos). Archivos nuevos: `ServicioContenidoController`, `DetalleServicio` (detalle compartido, ahora con las tarjetas) y `ImagenesServicios::contenido()` (lectura para la vista previa). Interfaz: `components/servicios/imagenes-ficha-servicio.tsx` (vista previa local, validación previa, indicadores de carga, confirmaciones de quitar y eliminar, errores por campo).

**Seguridad (capa por capa):**
- *BFF* (`leerMultipart`): mismo origen; solo `multipart/form-data`; `Content-Length` obligatorio y cuerpo leído con tope duro de 3 MB + 64 KB (no se fía de la cabecera); solo los campos de texto declarados; una sola imagen llamada `imagen`; firma de bytes real (JPEG, PNG o WebP); la imagen se reenvía con nombre fijo (`imagen.<ext>`), el del cliente se descarta.
- *Laravel* (autoridad): tipo real por contenido (`mimes` + `mimetypes` + `image`), 3 MB, 100–8000 px, `imagen_principal_ruta` y `servicio_publicacion_id` rechazados si llegan del cliente, permiso, revisión (428/412), ficha no publicada (409).
- *Consistencia*: se guarda el archivo, se actualiza el registro en la transacción y, solo tras confirmarla, se borra el reemplazado si nadie más lo usa; si la transacción falla se borra el recién subido. No se re-codifica la imagen (reutiliza las reglas actuales): los metadatos EXIF se conservan; la protección frente a archivos maliciosos es la validación de tipo y decodificación por dimensiones, más servir siempre con `nosniff` y tipo detectado.
- *Respuesta de la vista previa*: tipo detectado por contenido, `Content-Disposition: inline`, `nosniff`, `Cache-Control: no-store, private`; una ruta manipulada en la base (`../`) responde 404.

**Pruebas:**
- `ServiciosImagenesPanelTest` (7): subir, sustituir y quitar sin huérfanos; 8 archivos peligrosos rechazados (texto, PHP y SVG con extensión de imagen, `.php`, GIF, pesada, pequeña, enorme) sin guardar nada ni cambiar la revisión; rutas del cliente y varios archivos; 428/412/409 sin huérfanos; permisos; tarjetas completas; vista previa autenticada y traversal.
- Navegador real (Laravel real con almacén temporal): imagen no válida (415 en español), 4,3 MB (rechazo previo), subida con vista previa, reemplazo (1 solo archivo, el anterior borrado), tarjeta con imagen, error de campo, eliminación con confirmación, quitar imagen (almacén vacío).
- Matriz hostil contra el BFF (21 comprobaciones): PHP/SVG/GIF disfrazados (415), 4,3 MB y cuerpo de 4 MB (413), dos archivos (422), JSON a ruta multipart (415), `Origin` ajeno (403), sin CSRF (419), revisión obsoleta (412), nombre `../../.env.png` ignorado (se guarda `uuid.png`), la ficha no expone rutas, vista previa con `nosniff`, ruta con traversal (404), almacén vacío al terminar.

## 2. Recuperación de contraseña

Flujo comprobado en el navegador: solicitud (misma respuesta con correo existente o no), apertura del enlace con `#token`, clave débil (error de campo en español), restablecimiento correcto, token ya usado ("no es válido o expiró", no distingue entre inválido y vencido) y login con la clave nueva (por HTTP). **Defecto hallado y corregido:** en desarrollo React ejecuta los efectos dos veces; la primera lectura borraba el `#token` de la URL y la segunda dejaba la página en «enlace no válido», haciendo imposible usar el formulario con `next dev`. Ahora la primera lectura se conserva en una referencia (`formulario-restablecer.tsx`). Producción no se veía afectada. No se tocó el mecanismo de autenticación.

## 3. Seguridad de producción

**Cambios justificados** (`next.config.ts`): el panel no enviaba ninguna cabecera de seguridad (podía enmarcarse). Se añade, solo a `/admin/**`, `/login`, `/recuperar-contrasena`, `/restablecer-contrasena` y `/api/bff/**`: `Content-Security-Policy: frame-ancestors 'none'`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` y `Referrer-Policy: strict-origin-when-cross-origin`; y se retira `X-Powered-By` globalmente. El sitio público no cambia (verificado). Efecto colateral previsto: el panel ya no se puede cargar en un `<iframe>`, ni siquiera del mismo origen.

**Configuración necesaria en producción (no se despliega nada):**

| Capa | Variable / ajuste | Valor requerido |
| --- | --- | --- |
| Next | `NODE_ENV` | `production` (fija `Secure` en la cookie que el BFF entrega al navegador) |
| Next | `HORUS_WEB_ORIGIN` | origen(es) HTTPS propios, p. ej. `https://panel.horus.example` (control de `Origin` del BFF) |
| Next | `HORUS_API_URL`, `HORUS_WEB_SERVICE_TOKEN` (≥ 32), `HORUS_SESSION_COOKIE` | URL interna de Laravel; el token solo en servidor; el nombre igual a `SESSION_COOKIE` |
| Laravel | `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL` ≠ `debug` | `.env.example` trae `local`/`true`/`debug`: no copiar tal cual |
| Laravel | `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, `SESSION_HTTP_ONLY=true`, `SESSION_DOMAIN` vacío | la cookie de Laravel solo viaja servidor a servidor; el navegador recibe la que reescribe el BFF (`HttpOnly`, `SameSite=Lax`, `Path=/`, host-only, `Secure`) |
| Laravel | `SESSION_DRIVER=database`, `SESSION_ENCRYPT` | `database` (necesario para revocar sesiones al cambiar la clave); cifrar es opcional |
| Laravel | `TRUSTED_PROXIES` | IP(s) del proxy inverso (no `*` salvo red aislada) para que los limitadores vean la IP real |
| Proxy inverso | TLS, HSTS, `X-Forwarded-For`/`X-Real-IP` | terminar HTTPS y emitir HSTS aquí; **sobrescribir** (no añadir) `X-Forwarded-For`; no exponer `/api/panel/v1/*` ni `/api/public/v1/*` a Internet; límite de cuerpo ≥ 3,2 MB en las rutas `/api/bff/panel/…/imagen` y `/elementos` |
| Almacenamiento | `storage/app/public` + `php artisan storage:link` | el enlace público solo sirve imágenes de fichas ya autorizadas; copias de seguridad del directorio `servicios/` junto con la base |

**Etapa E (14/11/2026):** cookie del navegador con prefijo `__Host-` en producción (`COOKIE_NAVEGADOR`, `HORUS_BROWSER_COOKIE`; Laravel conserva su nombre) y CSP del panel ampliada con `object-src 'none'`, `base-uri 'self'`, `form-action 'self'` más `Permissions-Policy`. **CSP con `script-src` y nonces: no implementada, con motivo medido:** `next build` muestra `/login`, `/recuperar-contrasena` y `/restablecer-contrasena` como estáticas (○) y `/admin/*` como prerenderizado parcial (◐); su HTML se genera en la compilación y no puede llevar el nonce de cada petición, así que una política estricta bloquearía sus scripts. Opciones: hacer dinámicas esas rutas (pierde la prerenderización) o Subresource Integrity; es decisión de arquitectura pendiente. Verificado con `next start`: cabeceras en `/login`, `/admin` y `/api/bff/*`; el proxy ignora la cookie con el nombre antiguo y acepta `__Host-horus-sesion`.

**Verificado:** HttpOnly, SameSite=Lax, CSRF 419, `Origin` 403, token de servicio, lista cerrada del BFF y ausencia de rutas de almacenamiento en respuestas (matrices de esta y de la fase anterior). **Pendiente / decisión:** una CSP completa (`script-src`/`style-src`) exige nonces por petición en `proxy.ts` y pruebas con Next 16 y Tailwind; no se activó para no romper el panel sin poder probar producción. Opcional: prefijo `__Host-` para la cookie de sesión (requiere HTTPS y cambiar `SESSION_COOKIE` y `HORUS_SESSION_COOKIE` a la vez).

## 4. Responsive

Medido con iframes del mismo origen (el redimensionado de la ventana no cambia el viewport del navegador de pruebas) en 9 pantallas de los dos paneles a **360, 390, 768 px y escritorio**: sin desbordamiento horizontal de página. **Corregido:** el encabezado móvil partía el título y «Cerrar sesión» en dos líneas: el título se trunca solo si hace falta, la marca «Horus Group ·» se omite bajo 420 px y bajo 400 px el botón de salida muestra solo el icono (el texto queda para lectores de pantalla). La sección de imágenes apila la imagen y las tarjetas en móvil y los diálogos ocupan el ancho disponible.

## 5. PostgreSQL — estado y matriz (nada iniciado ni ejecutado)

**Estado del entorno (solo lectura, 12/11/2026):**

| Elemento | Resultado |
| --- | --- |
| Puerto 5432 (`postgresql-x64-18`, uso cotidiano) | en escucha, servicio Running. **No se toca.** |
| Puerto 55432 (`postgresql-horus-l2-test`, clúster S7 suspendido) | en escucha, servicio Running. **Rechazado expresamente por `PostgresTestGuard`; no se toca.** |
| Puerto 55433 (clúster de pruebas aislado `horus_pgt_55433`) | **sin listener: detenido** |
| Directorio del clúster aislado | existe (`%LOCALAPPDATA%\HorusPgTest55433\data`), fuera del repositorio |
| Perfil `apps/api/.env.pgsql-testing.local` | existe (no versionado; su contenido no se leyó) |
| Perfil `phpunit.pgsql.xml` | `HORUS_PG_TEST_PROFILE=1`, suites «PostgreSQL isolated» y «PostgreSQL integrity» |
| Procesos | dos `pg_ctl` y los `postgres.exe` de los dos servicios en ejecución; ninguno escucha en 55433 |

No se ejecutó la sonda ni ninguna prueba: con 55433 detenido no habría nada que validar y los otros dos puertos no deben usarse. **Compatibilidad PostgreSQL: no validada** (SQLite ignora `lockForUpdate()`).

**Matriz concreta** (columna «Cobertura»: lo que ya existe en `tests/PostgresIntegrity`; «Nuevo»: prueba por escribir al autorizar):

| # | Qué validar | Cobertura | Prueba nueva necesaria | Resultado esperado |
| --- | --- | --- | --- | --- |
| 1 | Último cupo con dos matrículas simultáneas | `MatriculaOperacionesConcurrenciaPostgresTest` | por los endpoints del panel (`POST /academico/matriculas`) | una 201, otra 409; cupos nunca < 0 ni > máximo |
| 2 | Orden de bloqueo curso → estudiante → matrícula; traslados cruzados | mismo archivo | traslado A→B y B→A vía `PUT` del panel | sin `deadlock detected` |
| 3 | Duplicado vigente (`matriculas_vigente_unica`) y rematrícula | `MatriculaConstraintsPostgresTest`, `MatriculaRulesPostgresTest` | — | 409/422, nunca 500 |
| 4 | DNI de estudiante y código de curso/servicio únicos bajo concurrencia | parcial | altas simultáneas de estudiante, curso y servicio | una gana, otra 422 |
| 5 | Revisión (ETag) en servicios, fichas y cotizaciones | no | dos `PUT` con la misma revisión; autorizar/retirar en paralelo; subir imagen y editar a la vez | una 200, otra 412; sin archivo huérfano |
| 6 | Idempotencia (`cotizaciones_creacion_unica`, `cotizacion_seguimiento_unico`) | no | misma `Idempotency-Key` en paralelo; misma clave con otros datos | un registro; segunda 200 o 409 |
| 7 | Historial sin huecos (`cotizacion_historial_revision_unica`) | no | N seguimientos concurrentes | revisiones consecutivas sin repetidas |
| 8 | Publicación de curso (`PublicacionCursos`, bloqueo del curso y ficha) | no | publicar/retirar/editar curso en paralelo | estado final coherente, `If-Match` respetado |
| 9 | Integridad referencial (`RESTRICT` al borrar, archivado lógico, restaurar con elegibilidad releída) | `RestrictOnDeleteMatriculasPostgresTest` | archivar curso con matrículas vigentes vía panel | 409 sin pérdida de datos |
| 10 | `CHECK` de estados y límites numéricos | `EstadosCheckPostgresTest` | precio 99 999 999,99+0,01 y enteros 2 147 483 648 por la API | 422, nunca 500 |
| 11 | Índices de listados | no | `EXPLAIN (ANALYZE)` de los listados con filtros y orden por defecto sobre 100 000 matrículas sintéticas | sin `Seq Scan` en la ruta paginada |
| 12 | Archivo y registro (imágenes) | SQLite | fallo forzado a mitad de transacción con PostgreSQL | archivo recién subido borrado; anterior intacto |

**Plan exacto que necesita tu autorización** (nada de esto se ha hecho): (1) comprobar que 55433 sigue libre y no excluido; (2) `pg_ctl start -D "%LOCALAPPDATA%\HorusPgTest55433\data" -l "…\log\server.log" -w` como usuario normal (sin servicio ni elevación); (3) `pg_isready -h 127.0.0.1 -p 55433` y `php scripts/pgsql-test.php probe` (solo lectura); (4) con el consentimiento destructivo del runner, ejecutar la suite «PostgreSQL integrity» y las pruebas nuevas de la tabla, que solo escriben en la base de pruebas dedicada (migrando su esquema, incluidas las migraciones de `tests/`); (5) `pg_ctl stop`. Quedan fuera 5432 y 55432. Si las pruebas pasan, se registrará en `PROJECT_CONTEXT.md`; si no, se documentan los fallos sin tocar salvaguardas.
