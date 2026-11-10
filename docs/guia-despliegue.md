# Guía de despliegue (panel y sitio de Horus Group)

> **Estado:** guía de preparación, redactada a partir del código y de los documentos del repositorio. **No se ha probado en un servidor real.** Las comprobaciones locales (suite en SQLite, PHPStan, `npm test`, `npm run build`, `route:cache` y `view:cache`) no prueban PostgreSQL, concurrencia, correo real ni Horus AI. Este documento no concede autorización para aplicar nada: cada paso sobre una base real necesita la autorización expresa del propietario.

No duplica lo ya escrito; lo enlaza:

- Seguridad, secretos, CSP y sesiones: [`seguridad-l1.1.md`](seguridad-l1.1.md) (§8 y §9).
- Migraciones, correo con Resend y Libro de Reclamaciones: [`sprint-15-preparacion-integracion.md`](sprint-15-preparacion-integracion.md) (§8.4 es la lista explícita de migraciones).
- Operación de Horus AI: [`horus-ai-operacion.md`](horus-ai-operacion.md).
- Registro de cambios del panel y migraciones aplicadas: [`auditoria-panel-2026-11.md`](auditoria-panel-2026-11.md).

## 1. Requisitos

| Componente | Detalle |
| --- | --- |
| PHP | `^8.2` (se desarrolla y prueba con 8.4) con `dom`, `gd`, `mbstring`, `openssl`, `xml`, `zip` (la exportación de datos lo exige) y `pdo_pgsql`. Comprueba con `php composer.phar check-platform-reqs` (ver README) |
| Base de datos | PostgreSQL. SQLite solo sirve para pruebas |
| Node | Solo para compilar (`npm ci` y `npm run build`); no se necesita en ejecución |
| Procesos | Servidor web sobre `public/`, cron del scheduler y un worker de cola (ver §4) |

## 2. Variables de entorno

`.env.example` es una plantilla de desarrollo, no de producción. El `.env` real queda **fuera del repositorio**. Nunca ejecutes `php artisan key:generate` en una instalación existente: invalida los datos cifrados y las sesiones.

| Variable | Valor en producción |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | `production`, `false` |
| `APP_URL` | URL pública con `https` |
| `APP_KEY`, `JWT_SECRET` | Propios; `JWT_SECRET` de al menos 32 bytes |
| `DB_*` | Conexión PostgreSQL con un usuario sin privilegios de superusuario |
| `SESSION_DRIVER` | `database` (la revocación de sesiones solo funciona con este controlador) |
| `SESSION_SECURE_COOKIE` | `true` |
| `TRUSTED_PROXIES` | IPs del balanceador, separadas por comas, si hay proxy |
| `QUEUE_CONNECTION` | Cola asíncrona (`database`); el correo de matrículas rechaza `sync` |
| `LOG_STACK` | `daily` |
| `CSP_MODE` | `report-only` al inicio; pasar a `enforce` solo con el procedimiento de `seguridad-l1.1.md` |
| `MATRICULA_MAIL_ENABLED`, `RESEND_API_KEY`, `MAIL_FROM_*` | Apagado por defecto; ver sprint 15 antes de activar |
| `HORUS_ADVISOR_*` | Apagado por defecto; ver `horus-ai-operacion.md` |

Tras cambiar el entorno con cachés activas: `php artisan config:clear` y luego `php artisan config:cache`.

## 3. Orden de despliegue

1. **Antes de empezar:** copia verificable de la base de datos (el panel de «Respaldo» exporta tablas minimizadas y **no** es una copia de seguridad), revisar `php artisan migrate:status` y confirmar que existe el rol `admin`.
2. `php artisan down` y detener workers y scheduler.
3. Actualizar el código (`git pull` o el artefacto que se use).
4. `composer install --no-dev --optimize-autoloader --no-interaction`.
5. `npm ci && npm run build`. `public/build` es un artefacto generado y no se versiona.
6. **Migraciones, con `--path` por prudencia.** Desde el 10/11/2026 `2026_10_28_000022` (verificación de registro), `000035` y `000036` (solicitudes de matrícula) están apartadas en `database/migraciones-suspendidas/` y `migrate` no las ve, así que `php artisan migrate` plano ya no las arrastra; aun así, en una base real aplica una a una, en orden, las que muestre `migrate:status` como pendientes, con `php artisan migrate --force --path=database/migrations/<archivo>.php`. Recientes y su efecto:

   | Migración | Efecto |
   | --- | --- |
   | `2026_11_10_000037` | Índices de consultas del panel |
   | `2026_11_10_000038` | Crea `notificacion_lecturas` (lectura por usuario de las generales) |
   | `2026_11_10_000039` | **Solo PostgreSQL.** `CHECK` de `estado`/`modalidad` en `estudiantes`, `cursos`, `matriculas` y `curso_publicaciones`. Comprueba antes los datos y se detiene si alguno queda fuera del conjunto. Toma `ACCESS EXCLUSIVE` mientras valida cada tabla: ventana de poco uso y copia reciente. `down()` borra las restricciones |
   | `2026_11_10_000040` | **Solo PostgreSQL. Aplicada en `horus_group` el 10/11/2026; falta en otros entornos.** Pasa `matriculas.estudiante_id` y `curso_id` de `ON DELETE CASCADE` a `RESTRICT` (no toca datos; `matricula_notifications` sigue en `CASCADE`). Bloqueo breve en `matriculas` y la tabla padre: copia reciente y `--path`. `down()` restaura `CASCADE` |

   Las migraciones de permisos o integridad (`000012`, `000013`, `000021`) se aplican con revisión previa y copia reciente. Tras ellas: `php artisan permission:cache-reset`.
7. `php artisan config:cache && php artisan route:cache && php artisan view:cache` (las tres funcionan con las rutas y vistas actuales).
8. `php artisan queue:restart` (reinicia los workers con el código nuevo).
9. `php artisan up`.

## 4. Procesos permanentes

| Proceso | Comando | Nota |
| --- | --- | --- |
| Scheduler | cron cada minuto: `* * * * * cd /ruta && php artisan schedule:run` | Ejecuta `horus:matriculas-notificar` (cada minuto), `horus:reclamaciones-vencimientos` (08:00 de Lima) y, si `horus-ops` está activo, la sincronización de Horus AI |
| Worker de cola | `php artisan queue:work <conexión> --queue=<cola>` bajo un supervisor (systemd, Supervisor) | Necesario para el correo de bienvenida y para la sincronización de Horus AI; los reintentos los define cada job. Con `MATRICULA_MAIL_ENABLED=false` no envía correo |

## 5. Verificación posterior

1. `GET /up` responde 200.
2. Inicio de sesión del panel, listado de matrículas y la campana de notificaciones.
3. Una exportación desde su botón (son `POST` con CSRF) y comprobar que queda el registro de auditoría correspondiente.
4. `php artisan migrate:status` sin pendientes. Las apartadas (`000022`, `000035`, `000036`) no aparecen y no se aplican sin autorización expresa.
5. `php artisan schedule:list` muestra las tareas esperadas.
6. Revisar `storage/logs` en busca de errores nuevos.

## 6. Reversa

- **Código:** volver al commit anterior, repetir los pasos 4 a 9.
- **Migraciones:** `000037` y `000038` tienen `down()` (borran el índice y la tabla; los avisos generales vuelven a considerarse leídos o no según su columna `leida`). Las de permisos y las de integridad **no se revierten a ciegas**: restaura la copia previa. No reintentes una migración fallida sin leer el mensaje.
- **CSP:** `CSP_MODE=report-only` (o `off`) y `config:clear`.

## 7. Lo que esta guía no cubre

- Una receta probada de servidor web, TLS, firewall o balanceador.
- Respaldo programado de PostgreSQL (`pg_dump`) y su retención: es una decisión pendiente del propietario.
- Monitorización y alertas.
- La activación de Resend, del Libro de Reclamaciones y de Horus AI, que tienen procedimientos propios en los documentos enlazados.
