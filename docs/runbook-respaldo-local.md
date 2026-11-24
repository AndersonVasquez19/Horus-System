# Runbook: respaldo local, salud del sistema y restauración

Plan de mejora del panel, Fase 1 (23/11/2026). Decisión del propietario: **los respaldos se guardan en local** (disco privado del servidor) y **quién puede descargarlos depende del rol/permiso**.

> Alcance de la evidencia: el comando, el servicio y los endpoints se probaron con `Process::fake()` y `Storage::fake()` sobre SQLite en memoria. **No se ejecutó `pg_dump` real ni se restauró ninguna copia.** Antes de confiar en esto hay que ensayar una restauración (sección 4).

## 1. Qué hay

| Pieza | Dónde |
| --- | --- |
| Comando | `php artisan horus:respaldo [--simular] [--solo-podar]` ([`RespaldarBaseDatos`](../apps/api/app/Console/Commands/RespaldarBaseDatos.php)) |
| Servicio | [`RespaldoLocal`](../apps/api/app/Services/RespaldoLocal.php): volcado, suma SHA-256, listado, verificación y retención |
| Configuración | [`config/respaldo.php`](../apps/api/config/respaldo.php) |
| API del panel | `GET /api/panel/v1/compartido/respaldo/copias` (listado) y `.../copias/{nombre}` (descarga) |
| Salud | `GET /api/panel/v1/compartido/salud` y `php artisan horus:salud-vigilar` |

Los volcados son `pg_dump --format=custom` guardados como `respaldos/horus-AAAAMMDD-HHMMSS.dump` (hora de Lima) con su `.sha256`, en el disco `local` (`storage/app/private`), nunca bajo `public/`.

## 2. Quién puede qué

- **Listar y descargar copias y ver la salud:** permiso `respaldo.exportar` (por defecto solo el rol `admin`). El coordinador tiene `respaldo.ver` (la exportación administrativa de tablas) pero **no** puede ver ni descargar las copias.
- Un volcado contiene **todos** los datos (incluidos datos personales y hashes). Concede `respaldo.exportar` solo a quien deba tenerlo.
- Cada descarga se audita (`respaldo_copia_descargada`) y una copia cuya suma ya no coincide **no se entrega** (`respaldo_copia_rechazada`, HTTP 409).

## 3. Activación (todo apagado por defecto)

Variables de entorno (se leen en `config/respaldo.php`; no se modificó `.env.example`):

| Variable | Defecto | Efecto |
| --- | --- | --- |
| `RESPALDO_PROGRAMADO_ENABLED` | `false` | Programa el respaldo diario en el scheduler |
| `RESPALDO_HORA` | `02:00` | Hora de Lima del respaldo programado |
| `RESPALDO_RETENCION_DIAS` | `14` | Se borran copias más antiguas que estos días… |
| `RESPALDO_MINIMO` | `3` | …pero siempre se conservan las N más recientes |
| `RESPALDO_CONEXION` | (conexión por defecto) | Conexión PostgreSQL a volcar |
| `RESPALDO_PG_DUMP` | `pg_dump` | Ruta del ejecutable |
| `RESPALDO_TIMEOUT` | `900` | Segundos máximos |

Requisitos del servidor: `pg_dump` instalado con versión **igual o superior** a la del servidor PostgreSQL, y espacio suficiente en `storage/app/private/respaldos`. En Railway el volumen del servicio puede no persistir entre despliegues: si `storage/` es efímero, **las copias locales se pierden al redeplegar**. Verifica que `storage/app/private` esté en un volumen persistente o descarga las copias a un lugar propio con regularidad.

Primera vez:

1. `php artisan horus:respaldo --simular` (no escribe nada).
2. `php artisan horus:respaldo` y comprueba que aparece en el panel y que la descarga funciona.
3. Solo entonces `RESPALDO_PROGRAMADO_ENABLED=true`.

## 4. Restauración (manual y deliberada; el sistema no restaura solo)

Ensáyala **primero en una base desechable**, nunca sobre `horus_group`:

1. Descarga la copia desde el panel (la suma ya se verificó) o tómala del servidor y comprueba: `sha256sum -c horus-AAAAMMDD-HHMMSS.dump.sha256`.
2. Crea una base vacía de prueba y restaura: `pg_restore --no-owner --no-privileges --dbname=<base_vacía> horus-AAAAMMDD-HHMMSS.dump`.
3. Comprueba conteos de tablas clave (`estudiantes`, `matriculas`, `transacciones_pago`, `audit_logs`) y que el libro de pagos cuadre (`php artisan horus:verificar-finanzas` apuntando a esa base).
4. Anota la fecha del ensayo y el tiempo que tomó. Una copia sin restauración ensayada **no cuenta como respaldo**.

Restaurar sobre la base real exige detener la aplicación, un respaldo previo del estado actual y autorización expresa del propietario.

## 4 bis. Volcado manual de producción (Railway, plan Hobby)

Decisión del propietario (24/11/2026): **no se programa respaldo automático** en Railway. Las copias en un bucket se evaluaron y se descartaron (el trabajo quedó guardado en un `git stash` del repositorio, no en `main`). `horus:respaldo` sigue instalado pero **apagado**, y la imagen de Railway no trae `pg_dump`, así que no sirve allí. La protección es este volcado manual, que se hace **antes de cada migración o cambio grande**:

1. Instala los clientes de PostgreSQL en tu equipo, versión **igual o superior** a la del servidor (`pg_dump --version`; el servidor de Railway es 18.x).
2. Abre un túnel cifrado, sin exponer la base: `railway connect Postgres --tunnel-only` (deja la ventana abierta y anota el puerto local).
3. En otra ventana, con la contraseña de las Variables del servicio Postgres en `PGPASSWORD` (no en el comando):
   `pg_dump -h 127.0.0.1 -p <puerto del túnel> -U postgres -d railway -Fc -f horus-produccion-AAAA-MM-DD.dump`
4. Verifica: `pg_restore --list horus-produccion-AAAA-MM-DD.dump` debe listar tablas y entradas `TABLE DATA` (`users`, `matriculas`, `migrations`), y el archivo no debe pesar 0 bytes.
5. Guarda el `.dump` **fuera del repositorio** (contiene datos personales y hashes de contraseña) y cierra el túnel con `Ctrl+C`.

Riesgo asumido: sin copia automática, ante una corrupción solo se puede volver al último volcado manual. Una restauración sin ensayar no cuenta como respaldo (sección 4).

## 5. Salud del sistema

`horus:salud-vigilar` (cada 5 minutos) y el endpoint evalúan: base de datos, latido del scheduler (cada minuto), cola, trabajos fallidos de 24 h, avisos externos atascados o inciertos, espacio en disco y antigüedad del último respaldo. Con una comprobación en alerta o error, cada administrador recibe **como máximo una notificación interna por comprobación y día** (no se envía correo ni WhatsApp).

Límite conocido: si el scheduler está caído, la vigilancia periódica tampoco corre. En ese caso el latido envejece y se ve al abrir el endpoint de salud; para detectarlo sin entrar al panel hace falta un monitor externo (pendiente de decisión).

## 6. Interfaz y límites

- Página **Exportar datos** (`/admin/academico/respaldo`): las secciones «Salud del sistema» y «Copias de la base de datos» solo aparecen con `respaldo.exportar`. La descarga pasa por el BFF (`apps/web/src/lib/panel/operaciones-panel.ts`, operación `GET compartido/respaldo/copias/horus-AAAAMMDD-HHMMSS.dump`, solo ese patrón).
- **Límite del BFF:** `descargarDeLaravel` (`apps/web/src/lib/panel/laravel.ts`) carga el archivo entero en memoria (`arrayBuffer`) y corta a los 30 s. Un volcado grande puede no descargarse por el panel; en ese caso toma la copia directamente del servidor. Hacer la descarga en streaming queda como mejora (afecta al BFF, que es código crítico y hoy sin pruebas unitarias).
- Las pruebas unitarias de la lista blanca del BFF no pudieron escribirse con `node:test` porque `operaciones-panel.ts` importa `server-only`; se hacen con Vitest (aprobado) en la Fase 4.

## 7. Pendiente

- Ensayo real de `pg_dump` y restauración en una base desechable (requiere autorización para usar el clúster aislado).
- Persistencia del volumen de respaldos en el despliegue.
