# Fase 5 — Estabilización de los paneles administrativos (12/11/2026)

Alcance: verificar con navegador real, responsive, contratos, mensajes en español y seguridad de los paneles académico (azul) y de servicios tecnológicos (verde) en `apps/web`, y dejar **diseñados, sin implementar**, la carga multipart de imágenes y la validación en PostgreSQL. No se crearon módulos, no se tocó Horus AI, las zonas públicas, Blade, migraciones ni la autenticación.

## 1. Pruebas con navegador real

Entorno desechable: Laravel real (`php -S`) sobre SQLite en el directorio temporal, Next en modo desarrollo, cuentas sintéticas. Se hicieron interacciones (clics, escritura, diálogos), no solo renderizado.

| Área | Flujos probados | Resultado |
| --- | --- | --- |
| Acceso | Redirección de `/admin/**` a `/login?next=`, clave incorrecta, login, cierre de sesión (la ruta protegida vuelve a pedir login), recuperación (misma respuesta para correo existente e inexistente) | Correcto |
| Acceso (por HTTP al BFF) | Restablecer: token inventado, clave débil, confirmación distinta, sin `Origin`, restablecimiento correcto, token reutilizado (422), login con la clave nueva | 8/8. El navegador de pruebas elimina el fragmento `#token=` de la URL, por lo que ese tramo no pudo ejercitarse en el formulario |
| Permisos | Secretaria: puede listar cursos; no entra al panel de servicios ni a cotizaciones (403); no crea servicios, no publica fichas, no exporta Excel (403); sin sesión 401 | 8/8 |
| Académico | Panel, alta de estudiante (errores de campo y éxito, historial), alta de matrícula con selector remoto, **anulación con diálogo de confirmación y cancelación con Escape** (cupo del curso de 1 a 2 libres, historial «Pendiente → Anulada, liberó un cupo»), edición de curso con error de campo y éxito, autorizar y retirar la ficha pública, reportes y descarga de Excel (6 KB) y PDF (214 KB) | Correcto |
| Servicios | Dashboard, alta de servicio (errores de campo y éxito), cambio de estado, borrador de ficha, autorización con casilla de confirmación, retiro, bandeja con la solicitud web (insignia «Web»), expediente, paso a revisión, responsable, seguimiento con historial | Correcto |

## 2. Responsive

El redimensionado de la ventana no cambia el viewport del navegador de pruebas; se midió con un iframe del mismo origen (las media queries responden a su ancho). 13 pantallas (dashboard, listados y fichas de los dos paneles) a **360, 820 y 1280–1528 px**: ninguna produce desbordamiento horizontal de página; las tablas se desplazan dentro de su contenedor. Revisión visual a 390 px: menú móvil plegable con 5 entradas, filtros apilados, botones a ancho completo en los diálogos, formularios legibles. Observación menor no corregida: en móvil «Cerrar sesión» y el título del encabezado se parten en dos líneas (es legible y no rompe el diseño).

## 3. Defectos hallados y corregidos

1. **Mensajes de validación en inglés y nombres de campo en `snake_case`** (p. ej. «The vigente desde field is required.»). Causa: faltaban atributos en `lang/es/validation.php` y `.env.example` traía `APP_LOCALE=en` (una instalación copiada de la plantilla respondía en inglés). Corrección: 22 atributos del panel y `APP_LOCALE=es` en la plantilla. Prueba nueva `MensajesEspanolPanelTest` (8 endpoints sin nombres técnicos). Las reglas no cambiaron.
2. **Errores de campo obsoletos**: tras un envío fallido, el error seguía visible mientras la persona corregía el campo, y la casilla de confirmación de publicación mantenía su error tras marcarla. Corrección en `campo.tsx` (el error se oculta al editar y reaparece si el servidor lo devuelve tras un nuevo envío) y en las dos casillas (`panel-publicacion.tsx`, `ficha-servicio.tsx`). Verificado en el navegador.

## 4. Contratos OpenAPI

- `horus-panel-api.yaml` pasa de 1.0.0 a **1.1.0**: de 12 a **53 operaciones en 39 rutas** (estudiantes con historial, matrículas con opciones y exportación, cursos con ficha pública, reportes Excel/PDF, servicios con ficha, cotizaciones con estado, responsable y seguimientos). Documenta permiso por acción, parámetros y listas blancas de orden, códigos 401/403/404/409/412/422/428/429, `ETag`, `If-Match`/`_revision`, `Idempotency-Key`/`_clave`, limitadores, paginación y esquemas de entrada.
- `horus-api.yaml` no cambia: la fase no toca la API pública (ya cubre libro de reclamaciones, matrícula y cotización pública). Sigue sin documentar `email-verification/*` (rutas suspendidas, fuera de alcance).
- Verificación: ambos archivos se analizan con `js-yaml` 4.3.2 (ya presente en `apps/web/node_modules`), 0 referencias `$ref` rotas, 0 operaciones sin respuestas.

## 5. Propuesta: carga de imágenes por BFF (diseño, sin implementar)

Hoy la imagen principal y las tarjetas de la ficha de servicios siguen en el módulo Blade porque el BFF solo reenvía JSON. Implementarlo exige autorización específica; esta es la propuesta para decidir.

**Contrato.** `PUT /api/bff/panel/servicios/servicios/{id}/imagen` y `.../tarjetas/{n}` con `multipart/form-data`: campos `imagen` (archivo), `texto_alternativo`, `_revision`. Respuesta `{ data: { imagen: { url_privada, ancho, alto, bytes } } }` con `ETag`. Retiro: `DELETE` con `_revision`. Lista cerrada en el BFF como el resto de operaciones; nunca un proxy genérico.

**Capas de defensa.**
- *BFF*: `Origin` y CSRF como en las mutaciones; `Content-Type` multipart obligatorio; tamaño máximo de cuerpo (p. ej. 2 MB) rechazado por `Content-Length` y con lectura acotada en flujo; un solo archivo; sin reenviar nombre original ni cabeceras del cliente; descarta cualquier otro campo.
- *Laravel (autoridad)*: permiso `servicios.editar`, revisión `If-Match` (412/428), bloqueo y auditoría como en `GestionServiciosTecnologicos`. Validación del **tipo real por contenido** (firma de bytes con `finfo`, no extensión ni `Content-Type` del cliente), lista blanca JPEG/PNG/WebP (sin SVG, GIF animado ni HEIC), extensión derivada del tipo detectado, dimensiones máximas, relación de píxeles máxima (anti «bomba de descompresión») y rechazo de imágenes que no se decodifican.
- *Re-codificación obligatoria*: decodificar y volver a guardar la imagen (GD/Imagick) elimina EXIF/GPS, cargas polyglot y datos añadidos tras el final del archivo; es la medida principal frente a archivos maliciosos.
- *Almacenamiento*: disco privado fuera de `public/`; nombre generado (UUID v4 + extensión detectada), jamás el original; ruta no derivada de datos del usuario; permisos de archivo restrictivos; se sirve por un endpoint autenticado o URL firmada de vida corta, con `X-Content-Type-Options: nosniff`, `Content-Disposition` controlado y `Cache-Control` privado en el panel (la copia pública solo existe cuando la ficha está autorizada).
- *Integridad del registro*: guardar nuevo archivo → actualizar fila y revisión en una transacción → borrar el archivo anterior **después** del commit (si falla el borrado, queda en una cola de limpieza, nunca un registro roto); si la transacción falla, se elimina el archivo recién escrito. Subir, reemplazar o borrar retira la autorización de la ficha, igual que un cambio de contenido. Auditoría con acción y campos, nunca con el contenido.
- *Respuestas de error*: 413 (tamaño), 415 (tipo), 422 (dimensiones o imagen ilegible, mensajes en español), 412/428 (revisión), 429 (limitador `admin-write`, más uno propio de subidas), siempre con `{ message, code }`; sin rutas ni nombres internos.
- *Pruebas previas a activar*: archivos con extensión falsa, polyglot, SVG con script, EXIF con GPS, gran resolución, truncado, doble extensión, nombre con `../`, reemplazo concurrente, fallo a mitad de la transacción y limpieza de huérfanos.

> **Implementado el 14/11/2026 (Etapa E):** `ImagenesServicios::guardar` decodifica y vuelve a codificar con GD (JPEG/PNG/WebP, calidad 85, orientación EXIF aplicada antes de descartar metadatos); el archivo guardado no conserva EXIF/GPS, chunks de texto ni bytes tras el final del formato. Imagen ilegible o de más de 25 000 000 de píxeles: 422 sin guardar nada. Una imagen truncada pero decodificable por GD se guarda como imagen nueva y válida (parcial). Cubierto en `ServiciosImagenesPanelTest` (EXIF con GPS, polyglot JPEG y PNG, orientación, ilegible, cabecera 8000×8000, doble extensión y `../`). Pendiente: reemplazo concurrente de la misma imagen.

**Decisiones que requiere el propietario**: tamaño y dimensiones máximos, formatos admitidos, disco y política de copias de seguridad de los archivos, y si las URLs públicas pasan por CDN.

## 6. Plan de validación en PostgreSQL (no ejecutado)

Perfil aislado `phpunit.pgsql.xml` + `scripts/pgsql-test.php` (puerto 55433, marcador y consentimiento destructivo). Ninguna migración ni escritura se ejecutó en bases persistentes; SQLite no sustituye esta validación (ignora `lockForUpdate()`).

| Tema | Qué probar | Cómo |
| --- | --- | --- |
| Cupos | Dos matrículas simultáneas sobre el último cupo: una gana, la otra recibe 409; el contador del curso nunca supera `cupos_maximos` ni baja de 0 | Dos conexiones con barrera (patrón de `MatriculaOperacionesConcurrenciaPostgresTest`) a través de los servicios del panel |
| Orden de bloqueo | Cursos por id → estudiantes por id → matrícula; traslado cruzado A→B y B→A sin interbloqueo | Hilos cruzados con tiempo máximo; no debe aparecer `deadlock detected` |
| Duplicados | `matriculas_vigente_unica` (una vigente por estudiante y curso), DNI de estudiante, código de curso y de servicio | Inserción concurrente + violación del índice traducida a 409/422, no a 500 |
| Revisión (ETag) | Dos ediciones con la misma revisión: una 200 y otra 412; autorizar y retirar ficha en paralelo | Servicios de cursos, servicios y cotizaciones |
| Idempotencia | Misma `Idempotency-Key` en paralelo: un solo registro (`cotizaciones_creacion_unica`, `cotizacion_seguimiento_unico`); misma clave con otros datos: 409 | Barrera de arranque simultáneo |
| Historial | `cotizacion_historial_revision_unica`: sin huecos ni repeticiones bajo concurrencia | Comprobación del orden de revisiones |
| Cascada lógica | Archivar curso con matrículas vigentes, `RESTRICT` al borrar, restauración con elegibilidad releída bajo bloqueo | `RestrictOnDeleteMatriculasPostgresTest` ampliado a los endpoints del panel |
| Constraints | `CHECK` de estados, límites numéricos (precio ≤ 99 999 999,99; enteros ≤ 2 147 483 647) y tipos de columna | `EstadosCheckPostgresTest`, y pruebas límite vía API |
| Índices | `EXPLAIN` de los listados con filtros y orden por defecto sobre un volumen sintético (p. ej. 100 000 matrículas): sin escaneo secuencial en la ruta de listado paginado | Plan de consulta guardado como evidencia |
| Reportes | Exportación bajo carga de escritura; memoria acotada y coherencia con el listado filtrado | Excel y PDF con datos sintéticos grandes |

Criterio de aceptación: todas las pruebas en verde en PostgreSQL sin tocar los resguardos, con el volcado de planes y el registro de bloqueos adjuntos. Pendiente de autorización expresa, como indica `CLAUDE.md` §5.

## 7. Verificación de seguridad

24 comprobaciones HTTP reales contra Laravel y el BFF (todas correctas; una aserción propia mal planteada se corrigió):

- **Token de servicio**: sin token o con token incorrecto, Laravel responde 401; con token correcto pero sin sesión, 401. El token no aparece en ninguna respuesta al navegador.
- **Sesión y cookies**: `laravel-session` es `HttpOnly`, `SameSite=Lax`, vida de 2 h; el atributo `Secure` depende de `SESSION_SECURE_COOKIE` en producción (a confirmar en el despliegue). Tras cerrar sesión, la cookie anterior da 401.
- **CSRF y origen**: mutación sin `Origin` 403, con `Origin` ajeno 403, sin token CSRF 419, con token falso 419.
- **Superficie del BFF**: recorrido de directorios, métodos no declarados, rutas ajenas al panel y módulos no expuestos responden 404. Los campos no declarados se descartan: un alta con `id`, `revision` e `identificador_publico` enviados por el cliente no los asigna.
- **Aislamiento de paneles y permisos**: la secretaria no accede al panel de servicios ni a cotizaciones, ni publica ni exporta (la matriz de fases 3 y 4 y `InventarioRutasPanelTest` cubren el resto de rutas).
- **Exposición de datos**: la bandeja de cotizaciones no incluye correo ni teléfono; la ficha de estudiante no contiene claves, tokens ni IP; las respuestas del panel llevan `Cache-Control: no-store, private`.
- **Validación y errores**: SQL y HTML en los campos terminan en 422 o dato inerte, nunca 500; los errores no incluyen trazas ni rutas internas. La recuperación de contraseña responde igual exista o no la cuenta.
- **Auditoría**: las operaciones de las fases 3 y 4 escriben auditoría y el historial es mínimo (acción, fecha, usuario y nombres de campos, nunca valores ni IP).

Pendientes y riesgos conocidos (sin cambios de autenticación en esta fase): confirmar `SESSION_SECURE_COOKIE=true` y `SESSION_DOMAIN` en producción; las cabeceras de seguridad HTTP de Next (CSP, `frame-ancestors`) no se auditaron en esta fase; la validación en PostgreSQL sigue pendiente.

## 8. Límites

Entorno de desarrollo (Next en modo dev, SQLite desechable); no se midió rendimiento ni PostgreSQL; el flujo de restablecimiento con fragmento no se pudo ejercitar en el formulario por una limitación del navegador de pruebas; el responsive se verificó con iframes, no en un dispositivo físico.
