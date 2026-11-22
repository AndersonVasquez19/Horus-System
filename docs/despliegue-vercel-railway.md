# Despliegue en Vercel (web) y Railway (API, chatbot y base de datos)

> **Estado (20/11/2026):** configuración preparada, **no desplegada ni probada en Vercel o Railway**. No hubo Docker
> en la máquina de preparación, así que las imágenes no se construyeron localmente. Lo comprobado: `next build` de
> `apps/web`, `tsc`, ESLint, las pruebas de Horus AI y que las 26 ruedas Linux de `apps/ai/requirements.lock` se
> descargan con sus hashes. Aplicar migraciones o crear usuarios en la base de Railway exige la autorización expresa
> del propietario (`AGENTS.md` §4). Complementa a [`guia-despliegue.md`](guia-despliegue.md), que cubre el servidor propio.

## 1. Arquitectura

```
Navegador ──► Vercel: apps/web (Next.js + BFF)
                 │  cookie __Host-horus-sesion (solo en el dominio de Vercel)
                 │  servidor a servidor, HTTPS + X-Horus-Web-Token
                 ▼
Railway ───► horus-api (Laravel, FrankenPHP) ──► Postgres (Railway)
             horus-worker   (queue:work)    ──┘
             horus-scheduler (schedule:work) ─┘
             horus-api ──► horus-ai (FastAPI) por la red privada (*.railway.internal:8001)
```

- El navegador **solo** habla con Vercel. Las cookies de Laravel nunca llegan al navegador: el BFF las guarda tras
  su propia cookie. Separar los dominios no rompe la sesión.
- El navegador llama directamente a Laravel solo para las imágenes (`/assets/images/*` y `/storage/*`), mediante
  `HORUS_ASSETS_URL`.
- `horus-ai` **no tiene dominio público**: solo lo alcanza `horus-api` por la red privada de Railway.

## 2. Archivos de despliegue

| Archivo | Uso |
| --- | --- |
| [`/.dockerignore`](../.dockerignore) | Contexto común de las dos imágenes de Railway, sin `.env`, `vendor`, el modelo de 477 MB ni registros |
| [`apps/api/Dockerfile`](../apps/api/Dockerfile) | Imagen de Laravel (FrankenPHP 1.13.4, PHP 8.4). Se construye **desde la raíz** porque Laravel lee `apps/ai/data/knowledge.json` |
| [`apps/api/docker/entrypoint.sh`](../apps/api/docker/entrypoint.sh) | Comprueba las variables obligatorias, cachea configuración y rutas, y arranca `web`, `worker` o `scheduler` según `HORUS_PROCESO`. **Nunca migra ni siembra** |
| [`apps/api/docker/Caddyfile`](../apps/api/docker/Caddyfile), [`php.ini`](../apps/api/docker/php.ini) | Servidor HTTP en `$PORT` y ajustes de PHP para producción |
| [`apps/api/railway.json`](../apps/api/railway.json) | Servicio `horus-api`, con chequeo de salud en `/up` |
| [`apps/api/railway.worker.json`](../apps/api/railway.worker.json) | Servicios `horus-worker` y `horus-scheduler` (misma imagen, sin chequeo HTTP) |
| [`apps/ai/Dockerfile`](../apps/ai/Dockerfile), [`apps/ai/railway.json`](../apps/ai/railway.json) | Horus AI en modo léxico (sin PyTorch ni modelo), chequeo de salud en `/health` |
| [`apps/web/vercel.json`](../apps/web/vercel.json) | Next.js en Vercel. Omite el build si el commit no toca `apps/web` y usa la región `iad1` |

Cambio de código asociado: Horus AI acepta ahora nombres de host adicionales con `HORUS_AI_EXTRA_ALLOWED_HOSTS`
(vacío por defecto y sin comodines). Solo surte efecto con `HORUS_AI_ALLOW_NON_LOOPBACK=true`. Sin él,
`TrustedHostMiddleware` rechazaría con 400 las llamadas de Laravel por la red privada y el chequeo de salud de
Railway. El comportamiento local no cambia (pruebas en `apps/ai/tests/test_security.py`).

## 3. Railway

### 3.1 Servicios

Crea un proyecto, conecta el repositorio de GitHub y añade estos servicios. En **ninguno** se fija *Root Directory*:
el contexto es la raíz del repositorio. En cada uno, *Settings → Config-as-code → Railway config file*:

| Servicio | Config file | Dominio público | Extra |
| --- | --- | --- | --- |
| `Postgres` | plantilla PostgreSQL de Railway | No | Activa los respaldos de Railway |
| `horus-ai` | `/apps/ai/railway.json` | **No** | — |
| `horus-api` | `/apps/api/railway.json` | Sí (*Generate Domain*) | Volumen montado en `/srv/horus/api/storage/app/public` |
| `horus-worker` | `/apps/api/railway.worker.json` | No | `HORUS_PROCESO=worker` |
| `horus-scheduler` | `/apps/api/railway.worker.json` | No | `HORUS_PROCESO=scheduler`, **una sola réplica** |

Despliega todos en la misma región (por defecto, US East). Si eliges otra, cambia `regions` en `apps/web/vercel.json`
a la región de Vercel más cercana, porque cada página del BFF hace llamadas a Laravel.

El volumen guarda las imágenes que se suben desde el panel. Las que ya existen en local
(`apps/api/storage/app/public/cursos`) no están en Git y hay que copiarlas al volumen aparte. Railway reinicia con
una breve interrupción los servicios que tienen volumen.

### 3.2 Variables compartidas (*Project Settings → Shared Variables*)

Genera cada secreto con `openssl rand -base64 48` (o `python -c "import secrets; print(secrets.token_urlsafe(48))"`)
y no lo reutilices entre entornos.

| Variable | Valor |
| --- | --- |
| `APP_KEY` | `base64:` + 32 bytes aleatorios en base64 (p. ej. `echo "base64:$(openssl rand -base64 32)"`). Solo para una base **nueva**: si se cambia después, invalida los datos cifrados |
| `JWT_SECRET` | Aleatorio, de 32 bytes o más |
| `HORUS_WEB_SERVICE_TOKEN` | Aleatorio, de 32 caracteres o más. **El mismo valor** va en Vercel |
| `HORUS_AI_SERVICE_TOKEN` | Aleatorio, de 32 caracteres o más |

### 3.3 `horus-api`, `horus-worker` y `horus-scheduler`

Las mismas variables en los tres (puedes usar *Copy variables*). Solo cambia `HORUS_PROCESO`.

| Variable | Valor |
| --- | --- |
| `HORUS_PROCESO` | `web` / `worker` / `scheduler` |
| `APP_NAME` | `Horus Group` |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_KEY` / `JWT_SECRET` | `${{shared.APP_KEY}}` / `${{shared.JWT_SECRET}}` |
| `APP_URL` | `https://${{horus-api.RAILWAY_PUBLIC_DOMAIN}}` |
| `HORUS_WEB_URL` | URL de Vercel, con `https` y sin barra final (p. ej. `https://horus.vercel.app` o el dominio propio). **Obligatoria** |
| `HORUS_WEB_SERVICE_TOKEN` | `${{shared.HORUS_WEB_SERVICE_TOKEN}}` |
| `DB_CONNECTION` / `DB_URL` | `pgsql` / `${{Postgres.DATABASE_URL}}` (red privada) |
| `SESSION_DRIVER` / `SESSION_SECURE_COOKIE` | `database` / `true` |
| `CACHE_STORE` / `QUEUE_CONNECTION` | `database` / `database` |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stderr` / `info` |
| `CSP_MODE` | `report-only` |
| `HORUS_ADVISOR_ENABLED` | `true` para activar el chatbot. Con `false`, o si Horus AI falla, responde la contingencia institucional |
| `HORUS_ADVISOR_URL` | `http://${{horus-ai.RAILWAY_PRIVATE_DOMAIN}}:8001` |
| `HORUS_ADVISOR_ALLOWED_HOSTS` | `${{horus-ai.RAILWAY_PRIVATE_DOMAIN}}`. Obligatoria para usar esa URL: el cliente solo acepta `127.0.0.1` y los hostnames exactos de esta lista (separados por comas; nunca IP literales, `localhost` ni hosts de metadatos). Sin ella, cada mensaje cae a la contingencia con causa `configuracion` en `chatbot.advisor_fallo` |
| `HORUS_ADVISOR_TOKEN` | `${{shared.HORUS_AI_SERVICE_TOKEN}}` |
| `HORUS_AI_CATALOG_SYNC_ENABLED`, `HORUS_OPS_ENABLED` | `false` (sin catálogo firmado: ver `horus-ai-operacion.md`) |
| `MATRICULA_MAIL_ENABLED`, `RECORDATORIOS_*_ENABLED`, `WHATSAPP_CLOUD_ENABLED`, `SEGURIDAD_*_ENABLED` | `false`. No los actives sin cumplir `activacion-canales-externos.md` |
| `JSONPE_TOKEN` | Opcional (consulta de DNI). Solo en el servidor |
| `TRUSTED_PROXIES` | **Vacío** (ver §6) |

`horus-worker` acepta además `HORUS_COLAS` (por defecto `default`, la única cola que usan hoy matrículas,
recordatorios y reclamaciones).

### 3.4 `horus-ai`

| Variable | Valor |
| --- | --- |
| `PORT` | `8001` (debe coincidir con `HORUS_ADVISOR_URL`) |
| `HORUS_AI_SERVICE_TOKEN` | `${{shared.HORUS_AI_SERVICE_TOKEN}}` |
| `HORUS_AI_EXTRA_ALLOWED_HOSTS` | `${{RAILWAY_PRIVATE_DOMAIN}},healthcheck.railway.app` |
| `HORUS_AI_LOG_LEVEL` | `INFO` |
| `GEMINI_API_KEY` | Opcional. Vacío = desactivado (`chatbot-gemini.md`) |

La imagen ya fija `HORUS_AI_HOST=::` y `HORUS_AI_ALLOW_NON_LOOPBACK=true`: dentro del contenedor tiene que escuchar
fuera de loopback para que la red privada lo alcance. El token de servicio sigue siendo obligatorio: sin él,
`/api/v1/*` responde 503.

## 4. Vercel

1. *Add New → Project*, importa el repositorio y en **Root Directory** elige `apps/web` (Vercel lee `vercel.json` de ahí).
2. Variables, en el entorno **Production**. Son solo de servidor: ninguna lleva `NEXT_PUBLIC_`.

| Variable | Valor |
| --- | --- |
| `HORUS_API_URL` | `https://<dominio de horus-api>.up.railway.app`, sin barra final |
| `HORUS_ASSETS_URL` | El mismo valor (imágenes servidas por Laravel) |
| `HORUS_WEB_SERVICE_TOKEN` | El mismo valor que el secreto compartido de Railway |
| `HORUS_WEB_ORIGIN` | Orígenes exactos de la web separados por comas, p. ej. `https://horus.vercel.app,https://www.tudominio.pe` |

3. **Despliegues de vista previa:** el BFF rechaza los orígenes que no están en `HORUS_WEB_ORIGIN`, así que el inicio
   de sesión no funciona en las URL de *preview*. Para probar ahí, añade ese origen a la variable del entorno
   *Preview* o usa solo *Production*.
4. Si usas un dominio propio, añádelo a `HORUS_WEB_ORIGIN` (Vercel) y a `HORUS_WEB_URL` (Railway), y redespliega ambos.

## 5. Primera puesta en marcha (orden)

1. Railway: crea `Postgres` y las variables compartidas.
2. Despliega `horus-ai` y espera a que `/health` pase el chequeo.
3. Despliega `horus-api`. `/up` responde sin tablas, pero el resto de la API necesita las migraciones.
4. **Requiere autorización expresa del propietario.** Desde `railway ssh --service horus-api`:
   ```sh
   php artisan migrate:status
   php artisan migrate --force
   php artisan db:seed --force          # RolesAndPermissionsSeeder, SystemSettingsSeeder, ChatIntentSeeder
   php artisan horus:crear-usuario --email=<correo> --name="<nombre>" --rol=admin   # pide la contraseña oculta
   ```
   Es una base nueva y vacía. **No ejecutes `db:seed` en una base con datos:** el seeder de roles resincroniza los
   permisos. Para trasladar datos existentes usa `pg_dump`/`pg_restore` con su propio plan y su copia de seguridad.
5. Despliega `horus-worker` y `horus-scheduler`.
6. Vercel: despliega `apps/web`. Copia su URL final en `HORUS_WEB_URL` (Railway) y `HORUS_WEB_ORIGIN` (Vercel), y
   redespliega los dos.

Cada despliegue posterior que traiga migraciones nuevas repite el paso 4 (solo `migrate:status` y, con autorización,
`migrate --force`). La imagen no migra sola a propósito.

## 6. Decisiones abiertas y límites

- **`TRUSTED_PROXIES` se deja vacío.** En la API del panel, la IP del visitante llega desde el BFF en
  `X-Horus-Client-Ip`, y `AutenticarBffPanel` solo la acepta tras validar el token del BFF. Con `TRUSTED_PROXIES=*`, cualquiera podría falsear
  `X-Forwarded-For` y saltarse los límites de `/api/auth/*`. Cambiarlo requiere conocer las IP del borde de Railway.
- **Respaldos:** activa los respaldos de Postgres en Railway. El volumen de imágenes no tiene respaldo automático.
- **Modo semántico de Horus AI:** fuera de esta imagen (modelo de 477 MB y unos 3 GB de RAM).
- **Correo, WhatsApp y catálogo firmado del chatbot:** siguen apagados. Cada uno tiene su propio procedimiento.
- **Sin verificar:** construcción de las imágenes, arranque de FrankenPHP con este `Caddyfile`, red privada IPv4/IPv6,
  permisos del volumen y rendimiento entre Vercel y Railway. Valídalo en el primer despliegue con la lista del §7.

## 7. Verificación tras el despliegue

1. `https://<horus-api>/up` responde 200 («Application up»). `https://<horus-api>/.env` y `/.htaccess` responden 404.
2. En los registros de `horus-api` no aparece `[horus] Faltan variables obligatorias`.
3. La portada de Vercel carga con imágenes y el catálogo de cursos muestra las fichas publicadas.
4. Inicio de sesión en `/login`, luego el panel (`/admin/academico`), un PDF y un Excel.
5. El chatbot responde. Si responde la contingencia, revisa `HORUS_ADVISOR_*`, el token y los registros de `horus-ai`.
6. `php artisan schedule:list` (por `railway ssh`) muestra las tareas, y en los registros de `horus-worker` no hay errores.
