# Horus Group SRL

Sitio público y panel administrativo de Horus Group SRL. La aplicación principal de este directorio es la **API y el backend en Laravel 12** (sin interfaz propia: la interfaz es Next.js, en `apps/web`). Usa PostgreSQL según `.env.example`. Incluye gestión de estudiantes, cursos, matrículas, usuarios, roles, aprobaciones, reportes y un asistente virtual. La sección Procesos fue retirada del panel; se conserva su histórico sin borrar datos ([alcance](docs/retiro-procesos.md)).

> **Identifica la aplicación antes de trabajar.** Laravel vive en `apps/api/` (desde la etapa 8); la raíz del repositorio solo contiene `apps/`, `packages/`, `docs/`, `ops/`, `.github/` y los documentos de contexto. **Next.js (`apps/web`) es la única interfaz web**: Blade y Vite se retiraron de Laravel el 18/11/2026 (ver «Frontend: Next.js»). El sitio HTML y su servidor Node históricos (`legacy-html-node/`) se eliminaron y siguen disponibles solo en el historial de Git.

## Contenido

- [Mapa del proyecto](#mapa-del-proyecto)
- [Requisitos](#requisitos)
- [Instalación local](#instalación-local)
- [Configuración](#configuración)
- [Base de datos y datos iniciales](#base-de-datos-y-datos-iniciales)
- [Acceso y funciones](#acceso-y-funciones)
- [Asistente virtual](#asistente-virtual)
- [Pruebas y comprobaciones](#pruebas-y-comprobaciones)
- [Problemas frecuentes](#problemas-frecuentes)
- [Seguridad y alcance](#seguridad-y-alcance)

## Mapa del proyecto

Las rutas de la tabla son relativas a `apps/api/` salvo `apps/web/`, `apps/ai/`, `packages/` y `.github/`, que cuelgan de la raíz del repositorio.

| Ruta | Responsabilidad |
| --- | --- |
| `artisan`, `bootstrap/app.php` | Entrada y configuración de Laravel; registra rutas API, el panel y `/up`. |
| `routes/api.php`, `routes/panel.php` | `/api/auth/*` (JWT), `/api/public/v1/*` (BFF público) y `/api/panel/v1/*` (panel). `routes/web.php` está vacío a propósito: conserva el grupo `web` (sesión y CSRF) que usa el panel. |
| `app/Http/Controllers/`, `app/Http/Requests/` | Acciones HTTP y validación de formularios. |
| `app/Models/`, `app/Services/`, `app/Support/` | Modelos, lógica de JWT/chat y conocimiento local del asistente. |
| `resources/views/` | Solo lo que Laravel sigue generando: PDF (`pdf/`), correos (`emails/`, `auth/emails/`) y páginas de error (`errors/`). |
| `database/migrations/`, `database/seeders/` | Esquema y datos iniciales. |
| `tests/Feature/`, `tests/Unit/`, `tests/Academic/`, `tests/Contract/` | Pruebas PHP (Pest/PHPUnit). No hay pruebas JavaScript en la API. |
| `public/` | Raíz pública del servidor web. Ya no hay compilación de recursos (`public/build` no existe). |
| `public/assets/images/` | Imágenes versionadas que Next sirve desde Laravel (catálogo). Ya no hay `css/` ni `js/`. |
| `apps/web/` | Frontend **Next.js** (React 19, Tailwind 4), proyecto Node independiente con su propio `package.json`. Atajos desde la raíz: `npm run web:dev`, `web:build` y `web:verify`. |
| `apps/ai/` | **Horus AI** (Python, FastAPI): servicio del asistente. Su `.venv`, `.env` y `models/` no se versionan; ver su `README.md`. |
| `packages/contracts/` | Contratos HTTP existentes (OpenAPI), documentados a partir del código. Sin clientes generados. |
| `.github/workflows/` | `ci-api.yml`, `ci-web.yml` y `ci-ai.yml`, todos **suspendidos** (solo ejecución manual). |
| `ops/gateway/` | **Gateway local**: `npm run gateway` levanta Laravel, Next.js y Horus AI a la vez detrás de un solo origen (`http://127.0.0.1:8080`). Ver `ops/gateway/README.md`. |

**Monorepo.** `apps/api` (Laravel), `apps/web` (Next.js) y `apps/ai` (Horus AI) son proyectos independientes. No hay workspaces de npm: `apps/api` (Composer) y `apps/web` (npm) mantienen sus dependencias y lockfiles separados; la API no usa Node. Detalle y decisiones en `PROJECT_CONTEXT.md`.

### Frontend: Next.js

**Next.js (`apps/web`) es la única interfaz web.** Blade, Alpine, Tailwind 3 y Vite se retiraron de `apps/api` el 18/11/2026, en cinco fases con autorización expresa en cada una:

| Fase | Qué salió de Laravel |
| --- | --- |
| 1 | Páginas públicas con formulario o datos de Laravel (tecnologías, cursos, libro de reclamaciones, solicitud de cotización) y `POST /chat/mensaje` |
| 2 | Panel `/admin` de Blade (tablero, búsqueda, respaldo, asistente, notificaciones) y las redirecciones a Next |
| 3 | Login, registro y recuperación de contraseña web |
| 4 | Vite, Tailwind, Alpine, `resources/js` y `resources/css`, `public/assets/{css,js}`, pruebas JavaScript y smokes de navegador, y el paso de Node del CI |
| 5 | Documentación |

Los enlaces antiguos (`/admin/matriculas`, `/libro-reclamaciones`, `/register`, …) los redirige Next con `redirects()` en `apps/web/next.config.ts`.

**`HORUS_WEB_URL` es obligatoria** (URL pública de Next, sin barra final): con ella Laravel arma el enlace de recuperación de contraseña, el destino de `redirectGuestsTo` y los botones de las páginas de error. Sin una URL http/https válida no hay enlace de recuperación y los botones apuntan a `/`.

`apps/api` ya no tiene `package.json`. El `package.json` de la raíz es mínimo y privado, sin dependencias ni lockfile: solo atajos `api:test`, `web:dev`, `web:build` y `web:verify`.

Laravel conserva solo las vistas de PDF (`resources/views/pdf`), correos (`emails`, `auth/emails`) y páginas de error (`errors`). `public/assets/images/` se versiona y Next sirve desde ahí las imágenes del catálogo.

## Requisitos

- PHP **8.2 o superior** y Composer 2. Existe un `composer.phar` local para equipos sin el comando global `composer`.
- Extensiones PHP requeridas por dependencias: entre otras, `dom`, `gd`, `mbstring` o su polyfill, `openssl`, `xml` y `zip`. Para la configuración PostgreSQL necesitas `pdo_pgsql`; para las pruebas, `pdo_sqlite`. Comprueba el entorno con `php composer.phar check-platform-reqs` una vez instaladas las dependencias.
- PostgreSQL accesible y una base de desarrollo creada antes de migrar.
- Node.js y npm solo para `apps/web` (Next.js); `apps/api` no usa Node.

## Instalación local

Ejecuta estos comandos en **PowerShell**, desde `apps/api` (Laravel):

```powershell
cd apps/api
php composer.phar install
if (-not (Test-Path -LiteralPath .env)) { Copy-Item -LiteralPath .env.example -Destination .env }
```

La copia no sobrescribe un `.env` existente. Configura `.env` como se indica abajo y confirma que `DB_HOST`, `DB_PORT` y `DB_DATABASE` apuntan a tu **base local de desarrollo**. Después, **solo en una instalación nueva**:

```powershell
php artisan key:generate
php artisan migrate
php artisan serve
```

`php artisan serve` expone la **API** (normalmente `http://127.0.0.1:8000`); no hay interfaz en esa URL. La interfaz se levanta aparte con `npm run web:dev` (desde la raíz) y se configura con `HORUS_API_URL` y `HORUS_WEB_SERVICE_TOKEN`; en Laravel, `HORUS_WEB_URL` y el mismo token. La raíz web es `apps/api/public/`.

No ejecutes `php artisan key:generate` al actualizar una instalación existente: cambiar `APP_KEY` invalida datos cifrados y sesiones. `php artisan migrate` aplica cambios al destino configurado; revisa ese destino antes de ejecutarlo. El script `composer setup` del manifiesto genera una clave y ejecuta migraciones automáticamente, así que este README usa pasos explícitos.

### Desarrollo

Mantén abiertos dos procesos en terminales separadas: `php artisan serve` (en `apps/api`) y `npm run web:dev` (desde la raíz). Visita la URL de **Next**; Laravel solo responde a la API.

## Configuración

`.env.example` es la plantilla de **nombres y valores de desarrollo**, no una configuración de producción. Ajusta al menos:

| Variables | Uso |
| --- | --- |
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY` | Entorno, errores, URL pública y cifrado de Laravel. En un entorno expuesto, desactiva `APP_DEBUG`. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL por defecto; comprueba el destino antes de migrar o sembrar. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | La plantilla usa `database`; las tablas correspondientes se crean con las migraciones. |
| `MAIL_MAILER`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `log` registra correos en vez de enviarlos. Para envío real configura un transporte y remitente válidos. |
| `RESEND_API_KEY` | Solo si eliges el transporte Resend configurado en `config/mail.php`. Es la **única** fuente de la clave: el panel de Configuración no la guarda ni la muestra. |
| `HORUS_ADVISOR_ENABLED`, `HORUS_ADVISOR_URL`, `HORUS_ADVISOR_TOKEN` | Advisor apagado por defecto; URL loopback y token solo en servidor. No hay proveedor OpenAI en el chat. |
| `HORUS_ADVISOR_SIGNING_KEY` | Clave independiente para validar catálogos en seco; no activa su publicación ni sincronización. |
| `JWT_SECRET`, `JWT_TTL`, `JWT_LEEWAY` | Autenticación de `/api/auth/*`. `JWT_SECRET` debe tener al menos 32 bytes; si está vacío, `config/jwt.php` intenta usar `APP_KEY`. |

No publiques `.env` ni pegues secretos en incidencias, registros o documentación. Cuando cambies variables en una aplicación con configuración cacheada, revisa esa caché antes de atribuir el resultado al código.

## Base de datos y datos iniciales

Las migraciones incluyen usuarios, sesiones, caché y trabajos, estudiantes, cursos, matrículas, permisos, ajustes, procesos, auditoría e intenciones/registros del chat. La aplicación puede mostrar páginas públicas sin sembrar datos, pero el chat escribe auditoría anonimizada en `chat_logs`: **necesita una base migrada y disponible**.

`php artisan db:seed` ejecuta `database/seeders/DatabaseSeeder.php`: roles, permisos, configuración inicial e intenciones del chat. **No crea usuarios ni contraseñas** y puede repetirse: no modifica cuentas existentes ni sobrescribe la configuración editada desde el panel. Sí sincroniza los permisos de los roles base (`admin`, `coordinador`, `secretaria`) con la matriz de `RolesAndPermissionsSeeder`, por lo que **descarta permisos concedidos a mano** a esos roles; en una base con datos reales, los permisos nuevos se aplican con migraciones (p. ej. `2026_10_27_000012_add_matriculas_exportar_eliminar_permissions.php`), no con el seeder.

```powershell
php artisan db:seed
```

Las cuentas del panel se crean con un comando que pide la contraseña de forma oculta (mínimo 12 caracteres con mayúsculas, minúsculas, números y símbolos), no la imprime y se niega a modificar un correo ya registrado:

```powershell
php artisan horus:crear-usuario --email=correo@ejemplo.com --name="Nombre" --rol=admin
```

`--rol` acepta `admin`, `coordinador` o `secretaria`.

**Acceso al panel en tu propia base local.** El registro público (`/register` y `/api/auth/register`) nunca asigna roles. Cada desarrollador, con su base PostgreSQL local ya migrada y con los roles sembrados (`php artisan db:seed --class=RolesAndPermissionsSeeder`), se registra normalmente en `/register` y luego promueve su propia cuenta desde su terminal:

```powershell
php artisan horus:promover-admin --email=tu@correo.com --base=NOMBRE_DE_TU_BASE
```

El comando solo promueve una cuenta que ya existe; no crea cuentas, no ejecuta seeders ni migraciones y no tiene ruta HTTP. Se niega a ejecutarse fuera de `local`/`development` (en `testing` solo con SQLite en memoria), si el host de la base no es de esta máquina o si `--base` no coincide con la base conectada. Si falta algún rol base, lo informa y termina sin cambiar nada. Repetirlo no duplica la asignación. Cada promoción queda en auditoría como `admin_role_granted_local`.

Los datos de ejemplo (cursos, estudiantes, matrículas, notificaciones y aprobaciones) están separados en `DemoDataSeeder`, no crean usuarios y se niegan a ejecutarse con `APP_ENV=production`. Úsalos **solo en una base local desechable**:

```powershell
php artisan db:seed --class=DemoDataSeeder
```

Las versiones anteriores sembraban cuentas `admin@`, `coordinador@` y `secretaria@horusgroup.com` con una contraseña publicada en el repositorio. Si tu base se sembró así, cambia esas contraseñas o elimina esas cuentas.

Para tareas parciales, inspecciona el seeder correspondiente antes de ejecutarlo. En particular, `ChatIntentSeeder` usa `updateOrCreate` y puede reemplazar campos de intenciones existentes; no es un mecanismo inocuo para actualizar producción. `migrate:fresh` y `db:wipe` eliminan datos y no forman parte del arranque normal.

## Acceso y funciones

- **Público:** las páginas, el catálogo, el libro de reclamaciones, la solicitud de cotización y el chat los sirve `apps/web`; Laravel solo entrega los datos por `/api/public/v1/*` (con el token de servicio del BFF). El login, el registro y la recuperación de contraseña están en `/api/panel/v1/auth/*`.
- **Panel:** `/api/panel/v1` (que consume `/admin/*` de Next) requiere sesión y rol `admin`, `coordinador` o `secretaria`. Cada controlador del panel exige además un permiso de Spatie por acción mediante `HasMiddleware`; las operaciones sin permiso responden 403. Asignar roles a una cuenta requiere `usuarios.asignar-roles`. La inscripción pública no concede rol administrativo. El rol `admin` no se puede renombrar ni eliminar ni perder sus permisos críticos, y no se puede eliminar ni quitar el rol al último administrador (ni a uno mismo). La exportación CSV de matrículas (`matriculas.exportar`) y su eliminación (`matriculas.eliminar`) son permisos propios, solo de `admin` por defecto.
- **Gestión:** estudiantes, cursos y matrículas; usuarios/roles; aprobaciones; notificaciones; configuración; auditoría; reportes CSV, Excel y PDF. Consulta las rutas y permisos reales antes de cambiar una operación.
- **Exportación de datos (`compartido/respaldo`, «Exportar datos» en el panel):** genera un ZIP de CSV con nueve tablas seleccionadas; requiere extensión ZIP y el permiso `respaldo.exportar`. Es una **exportación administrativa minimizada, no un respaldo**: omite contraseñas, tokens y claves, enmascara secretos, no se puede usar para restaurar y no se conserva ninguna copia en el servidor (queda un registro en auditoría). La copia integral de PostgreSQL (`pg_dump`) y la política de retención son responsabilidad del servidor; véase [`docs/seguridad-l1.1.md`](docs/seguridad-l1.1.md).
- **API:** `routes/api.php` expone registro, login, usuario actual y logout bajo `/api/auth/*`. `me` y `logout` requieren token Bearer JWT; el login del panel usa sesión y CSRF por `/api/panel/v1/auth/*`.
- **Correo:** el restablecimiento de contraseña depende del transporte configurado. Con `MAIL_MAILER=log` el mensaje queda en los registros locales y no llega a una bandeja externa.

### Publicar cursos por modalidad

En **Panel → Cursos → Nuevo curso**, selecciona Presencial, Virtual o Semipresencial y guarda el curso. Desde su detalle, **Publicación web** abre la ficha editorial (requiere `cursos.publicar`). Escribe los textos públicos, confirma la modalidad y vigencia, marca la autorización y pulsa **Publicar ficha revisada**.

El catálogo web está habilitado en **Educación → Cursos**. Cada botón **Ver cursos** filtra las fichas vigentes de su modalidad. Crear un curso no publica sus datos administrativos. Despublicar, desactivar o eliminar el curso, o vencer la vigencia, lo retira del catálogo. Se conserva Híbrido para los registros existentes, sin reclasificarlos. Detalle y validación: [`docs/cursos-publicacion-modalidades.md`](docs/cursos-publicacion-modalidades.md).

### Reportes de matrículas

`GET /api/panel/v1/academico/reportes` admite `busqueda` (DNI, nombres, apellidos, código o nombre de curso), `curso_id`, `estado` (`pendiente`, `pagada`, `anulada`) y `eliminados` (`0`: sin eliminadas; `1`: solo eliminadas). Los mismos filtros se envían por POST a las exportaciones Excel y PDF. Al aplicar filtros desde el panel se vuelve al primer lote.

El PDF mantiene un máximo explícito de **50 matrículas por descarga** (`ReportController::PDF_BATCH_SIZE`). El parámetro `lote`, desde 1, permite recorrer todas las coincidencias desde el panel. Panel y PDF muestran total coincidente, cantidad incluida, rango, filtros y lote actual. Se ordena por fecha de matrícula descendente e ID descendente para resolver empates; un lote fuera del rango devuelve 404. Sin coincidencias, el primer lote muestra cero registros. Dompdf recibe como máximo 50 filas con solo los campos necesarios y relaciones precargadas; no se genera un PDF ilimitado.

Excel incluye todas las coincidencias de los filtros, independientemente del lote PDF. Lee modelos en lotes de 500; PhpSpreadsheet todavía conserva el documento completo en memoria, por lo que no garantiza memoria constante para exportaciones grandes. Los datos pueden cambiar entre descargas: los lotes no constituyen una instantánea congelada. Los permisos `reportes.exportar.pdf` y `reportes.exportar.excel` se exigen tanto en los botones como en los endpoints.

Los contadores de matrículas e ingresos del panel respetan los filtros; ingresos suma únicamente matrículas `pagada`. Los contadores generales de estudiantes y cursos excluyen eliminados y no dependen de esos filtros. En la ficha estudiantil, activas significa `pendiente` más `pagada`; las `anulada` se cuentan por separado y las eliminadas no forman parte del historial visible.

## Asistente virtual

El chat público lo muestra `apps/web`, que envía el mensaje a su BFF y este a `POST /api/public/v1/chat` con el token de servicio (sin sesión ni CSRF de navegador). La ruta valida `mensaje` (máximo 2000 caracteres) y `session_id` (8 a 64 caracteres), y tiene límite `throttle:chat-bff` (20 por minuto y por visitante).

`app/Services/AsistenteVirtualService.php` aplica privacidad, bloqueo por sesión y deduplicación. Con activación explícita, `HorusAdvisorClient` consulta el servicio Python en loopback (`POST /api/v1/chat`, puerto 8001, `history: []`) y valida su respuesta. El modelo local es `paraphrase-multilingual-MiniLM-L12-v2`. Con el servicio desactivado o ante un fallo, responde mediante contingencia institucional. No participa un motor conversacional PHP ni OpenAI. El registro `chat_logs` omite el mensaje original.

El asistente del panel (`compartido/asistente`) permite probar el mismo flujo y consultar hasta 50 registros recientes. La tabla de intenciones PHP es histórica; no entrena ni controla Python. Las clases de diagnóstico, el seeder y las migraciones históricas se conservan. Nunca ejecutes seeders para actualizar respuestas en una base real sin autorización.

La plantilla `.env.example` declara `HORUS_ADVISOR_ENABLED=false`, URL loopback y secretos vacíos. El flag continúa apagado por defecto; no habilitarlo para usuarios reales sin autorización. El token de servicio solo vive en el servidor. Laravel usa `config()`; cualquier cambio operativo requiere regenerar la configuración cacheada mediante el procedimiento autorizado. No modificar `.env` para ejecutar pruebas.

## Pruebas y comprobaciones

Los comandos de esta sección se ejecutan desde `apps/api`. Las comprobaciones de `apps/web` (`npm run web:verify`, `npm run web:build`) se lanzan desde la raíz.

El perfil PostgreSQL aislado y sus salvaguardas están en [docs/postgresql-l2.2-a.md](docs/postgresql-l2.2-a.md); el procedimiento de aprovisionamiento preparado (no ejecutado) está en [docs/postgresql-l2.2-b-provisioning.md](docs/postgresql-l2.2-b-provisioning.md). Su preparación no autoriza ejecutar pruebas destructivas; la suite habitual conserva SQLite en memoria.

```powershell
php artisan test --compact
```

`apps/api` ya no tiene pruebas JavaScript ni smokes de navegador. Las comprobaciones de interfaz se hacen en `apps/web` (`npx tsc --noEmit`, ESLint, `npm run build`).

Para un cambio acotado de PHP, puedes ejecutar `php artisan test --filter=AsistenteVirtualTest` u otra clase pertinente. `phpunit.xml` configura SQLite en memoria, sesión y caché aisladas, cola síncrona y correo simulado. Las pruebas PHP no demuestran PostgreSQL ni servicios externos reales.


## Problemas frecuentes

| Síntoma | Comprobación inicial |
| --- | --- |
| `401` o `404` desde el BFF de Next | Comprueba que `HORUS_WEB_SERVICE_TOKEN` (32 caracteres o más) es el mismo en Laravel y en Next; sin token la API pública no existe. |
| Chat sin respuesta o error `500` | Revisa `storage/logs/`, migraciones y conexión a DB; `chat_logs` se escribe sin guardar el mensaje original; comprobar también la configuración del Advisor. |
| No llega el enlace de recuperación de contraseña | Comprueba `HORUS_WEB_URL` (obligatoria y válida) y `MAIL_MAILER`. |
| Imágenes del catálogo rotas | Comprueba que exista `public/assets/images/` o `storage` enlazado (`php artisan storage:link`) y que Next apunte a `HORUS_API_URL`. |
| `could not find driver` | Activa `pdo_pgsql` para PostgreSQL o `pdo_sqlite` para las pruebas, según el comando. |
| No llega un correo | Comprueba `MAIL_MAILER`; con `log` no se envían mensajes reales. |

## Seguridad y alcance

Este repositorio no incluye una receta verificada de despliegue, monitorización ni restauración integral. Existe una guía de preparación, **no probada en un servidor real**, en [`docs/guia-despliegue.md`](docs/guia-despliegue.md), y una integración continua básica en `.github/workflows/ci.yml` (SQLite en memoria: no prueba PostgreSQL). Antes de publicar, configura secretos y remitente fuera del repositorio, desactiva depuración, revisa permisos y destinos de datos, prepara copias verificables y comprueba el recorrido con los servicios reales del entorno. Las pruebas locales y el build son comprobaciones de desarrollo, no una certificación de producción.

Las instrucciones para asistentes que editan este proyecto están en [`CLAUDE.md`](CLAUDE.md).
