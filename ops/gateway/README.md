# Gateway de Horus System

Un solo comando levanta **Laravel** (API), **Next.js** (interfaz y panel) y **Horus AI** (el chatbot en Python) y los publica detrás de
**un único origen** (`http://127.0.0.1:8080` por defecto). Es una herramienta de **desarrollo y demostración local**, sin dependencias:
solo Node (los módulos que ya trae).

```powershell
npm run gateway                      # desde la raíz del repositorio: modo dev, con chatbot
npm run gateway -- --sin-chatbot     # solo Laravel + Next
npm run gateway -- --modo=prod       # next start (compila antes si falta el build)
npm run gateway:test                 # pruebas del gateway
```

`Ctrl+C` apaga los tres servicios. Si uno se cae, el gateway lo avisa, muestra las últimas líneas de su registro y apaga el resto.

## Qué hace

1. **Comprueba requisitos** (PHP en el PATH, `composer install`, `npm ci` en `apps/web`, `apps/ai/.venv`) y que los puertos estén libres.
2. **Arranca los servicios** conectados entre sí. Genera, por ejecución, dos secretos aleatorios (token de servicio del BFF y token de
   Horus AI) y los pasa por variables de entorno; nunca los imprime, los guarda ni los pone en la línea de comandos. Si defines
   `HORUS_WEB_SERVICE_TOKEN` o `HORUS_AI_SERVICE_TOKEN` (32 caracteres o más), usa esos.
3. **Espera la salud de cada uno** (`/up` de Laravel con el texto «Application up», `/health` de Horus AI, `/` de Next).
4. **Abre el proxy** y reparte las rutas:

| Ruta | Destino |
| --- | --- |
| `/up`, `/api/auth/*`, `/storage/*`, `/assets/images/*` | Laravel |
| todo lo demás (`/`, `/login`, `/admin/*`, `/api/bff/*`, `/api/chat`, …) | Next.js |
| `/api/panel/*`, `/api/public/*` | **404**: solo las llama el BFF de Next, de servidor a servidor |
| Horus AI | no se expone: solo la llama Laravel por loopback (`127.0.0.1:8001`) |

El proxy conserva el encabezado `Host` (el BFF de Next deriva de él su origen propio para la protección CSRF), reenvía las cookies y las
redirecciones tal cual, encadena el WebSocket de la recarga en caliente de `next dev` y responde 502 si un servicio no contesta.
El reparto se decide sobre la ruta decodificada y normalizada (`%2e%2e`, `//`, `%00`), de modo que esas formas no saltan el bloqueo.

## Opciones y variables

| Opción | Efecto |
| --- | --- |
| `--modo=dev\|prod` | `next dev` (por defecto) o `next start` |
| `--build` | fuerza `next build` antes (con `--modo=prod`) |
| `--puerto=N` | puerto del gateway (8080, o `GATEWAY_PORT`) |
| `--sin-chatbot` | no levanta Horus AI; Laravel responde con la contingencia institucional |
| `--semantico` | activa el modo híbrido (embeddings) de Horus AI; necesita ~3 GB libres |

Puertos internos: `HORUS_LARAVEL_PORT` (8000), `HORUS_NEXT_PORT` (3000), `HORUS_AI_PORT` (8001).

## Cosas que conviene saber

- **La base de datos es la de `apps/api/.env`.** El gateway no la cambia y, al arrancar, imprime el destino (sin credenciales). Para una
  demo sin tocar datos reales, exporta antes `DB_CONNECTION=sqlite` y `DB_DATABASE=<archivo>` (más `SESSION_DRIVER=file` y
  `CACHE_STORE=file`): las variables del entorno mandan sobre el `.env`.
- **Catálogo firmado.** Con `HORUS_AI_CATALOG_SYNC_ENABLED=true` (en el entorno o en `apps/api/.env`), Laravel descarta a Horus AI
  mientras este no tenga un catálogo cargado, y el chat respondería con la contingencia. En ese caso el gateway levanta además el
  `worker`, sincroniza el catálogo al arrancar y lo **renueva cada 2 minutos** (el catálogo caduca a los 5). Si una ficha publicada
  no pasa el filtro de privacidad del exportador (p. ej. contiene «DNI»), no se puede exportar ningún catálogo y el chat cae a la
  contingencia: corrige la ficha. Otro escenario con renovación propia es `apps/api/scripts/horus-local.php`.
- **Apagado.** Con `Ctrl+C` se cierran los tres. Si matas el proceso del gateway a la fuerza (`Stop-Process -Force`), Windows no le deja
  limpiar y quedan servicios huérfanos; usa `taskkill /pid <PID> /T /F` para matar el árbol entero.
- **Registros** en `ops/gateway/.runtime/logs/` (`laravel.log`, `next.log`, `ai.log`, `gateway.log`), que se sobrescriben en cada
  ejecución y no se versionan.
- **No es una pasarela de producción.** Sin TLS, sin límites de velocidad ni autenticación propios: los controles viven en Laravel y
  en el BFF de Next. Para producción sigue valiendo el mapa de rutas de `docs/guia-despliegue.md` en el servidor web real.
