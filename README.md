# Horus Group SRL

Sitio público y panel administrativo de Horus Group SRL. La aplicación principal de este directorio está hecha con **Laravel 12**, Blade, Alpine.js, Tailwind CSS y Vite. Usa PostgreSQL según `.env.example`. Incluye gestión de estudiantes, cursos, matrículas, usuarios, roles, procesos internos, reportes y un asistente virtual.

> **Identifica la aplicación antes de trabajar.** Laravel ocupa la raíz de este repositorio. El sitio HTML y su servidor Node están aislados en [`legacy-html-node/`](legacy-html-node/README.md), con sus propios recursos, dependencias y configuración. El chatbot descrito aquí está en las páginas Blade servidas por Laravel.

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

| Ruta | Responsabilidad |
| --- | --- |
| `artisan`, `bootstrap/app.php` | Entrada y configuración de Laravel; registra rutas web, API y `/up`. |
| `routes/web.php`, `routes/api.php` | Rutas públicas, autenticación, panel, chat y autenticación API. |
| `app/Http/Controllers/`, `app/Http/Requests/` | Acciones HTTP y validación de formularios. |
| `app/Models/`, `app/Services/`, `app/Support/` | Modelos, lógica de JWT/chat y conocimiento local del asistente. |
| `resources/views/`, `resources/js/app.js`, `resources/css/app.css` | Vistas Blade, comportamiento del navegador y estilos. |
| `database/migrations/`, `database/seeders/` | Esquema y datos iniciales. |
| `tests/Feature/`, `tests/Unit/`, `tests/frontend/`, `tests/browser/` | Pruebas PHP, frontend y comprobación de chat en Edge. |
| `public/` | Raíz pública del servidor web; Vite escribe en `public/build/`. |
| `legacy-html-node/` | Sitio HTML y API Node/MySQL históricos, con su propio `package.json` y `.env`. |

El `package.json` de la raíz contiene únicamente los comandos y dependencias de **Vite de Laravel**. El proyecto histórico tiene su propio manifiesto en `legacy-html-node/package.json`. `npm run dev` desde la raíz inicia Vite. Para trabajar en el sitio histórico, entra en `legacy-html-node/` y sigue su README; su servidor ejecuta `sequelize.sync({ alter: true })` contra MySQL al arrancar.

Las vistas Laravel también usan imágenes, estilos y scripts estáticos de `legacy-html-node/assets/`. `npm run dev` y `npm run build` copian esos recursos a `public/assets/` antes de iniciar o compilar Vite. Esta carpeta generada no se versiona: inclúyela junto con `public/build/` al publicar la aplicación. Para restaurarla sin compilar, ejecuta `npm run assets:sync` desde la raíz. No es necesario iniciar el servidor Node histórico.

## Requisitos

- PHP **8.2 o superior** y Composer 2. Existe un `composer.phar` local para equipos sin el comando global `composer`.
- Extensiones PHP requeridas por dependencias: entre otras, `dom`, `gd`, `mbstring` o su polyfill, `openssl`, `xml` y `zip`. Para la configuración PostgreSQL necesitas `pdo_pgsql`; para las pruebas, `pdo_sqlite`. Comprueba el entorno con `php composer.phar check-platform-reqs` una vez instaladas las dependencias.
- PostgreSQL accesible y una base de desarrollo creada antes de migrar.
- Node.js compatible con Vite 8 (`^20.19.0` o `>=22.12.0`) y npm.
- Microsoft Edge solo si vas a ejecutar `tests/browser/chat-smoke.mjs`.

## Instalación local

Ejecuta estos comandos en **PowerShell**, desde la raíz de este proyecto:

```powershell
php composer.phar install
npm ci
if (-not (Test-Path -LiteralPath .env)) { Copy-Item -LiteralPath .env.example -Destination .env }
```

La copia no sobrescribe un `.env` existente. Configura `.env` como se indica abajo y confirma que `DB_HOST`, `DB_PORT` y `DB_DATABASE` apuntan a tu **base local de desarrollo**. Después, **solo en una instalación nueva**:

```powershell
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

Abre la URL que imprime `php artisan serve` (normalmente `http://127.0.0.1:8000`). La raíz web es `public/`; no abras las vistas Blade ni el `index.html` histórico con `file://` como sustituto de Laravel. Si prefieres recarga de estilos y JavaScript, deja `php artisan serve` en una terminal y ejecuta `npm run dev` en otra. Para usar los recursos compilados, `npm run build` genera `public/build/`.

No ejecutes `php artisan key:generate` al actualizar una instalación existente: cambiar `APP_KEY` invalida datos cifrados y sesiones. `php artisan migrate` aplica cambios al destino configurado; revisa ese destino antes de ejecutarlo. El script `composer setup` del manifiesto genera una clave y ejecuta migraciones automáticamente, así que este README usa pasos explícitos.

### Cambios visibles mientras desarrollas

Con las dependencias instaladas y `.env` configurado, mantén abiertos estos dos procesos en terminales separadas:

```powershell
php artisan serve
```

```powershell
npm run dev
```

Visita la URL de **Laravel** que muestra la primera terminal, normalmente `http://127.0.0.1:8000`; no abras directamente la URL de Vite ni los archivos HTML de `legacy-html-node/` para probar Laravel. La directiva `@vite` de las vistas conecta el navegador con el servidor Vite. Los cambios en CSS se actualizan en caliente; los cambios en vistas Blade, rutas y archivos PHP de `app/` provocan una **recarga automática completa** de la página. El JavaScript se actualiza mediante HMR cuando es posible y puede provocar una recarga completa según el módulo. No necesitas ejecutar `npm run build` después de cada edición: ese comando genera archivos para uso sin el servidor de desarrollo.

Blade genera HTML en el servidor; una vista o respuesta PHP nueva necesita otra petición para verse. La recarga automática evita pulsar F5, pero no conserva necesariamente el estado de formularios o del chat. Si necesitas actualizar partes de la página sin recarga completa, se requiere implementar interactividad específica (por ejemplo, peticiones AJAX o componentes reactivos) para esas partes.

## Configuración

`.env.example` es la plantilla de **nombres y valores de desarrollo**, no una configuración de producción. Ajusta al menos:

| Variables | Uso |
| --- | --- |
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY` | Entorno, errores, URL pública y cifrado de Laravel. En un entorno expuesto, desactiva `APP_DEBUG`. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL por defecto; comprueba el destino antes de migrar o sembrar. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | La plantilla usa `database`; las tablas correspondientes se crean con las migraciones. |
| `MAIL_MAILER`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `log` registra correos en vez de enviarlos. Para envío real configura un transporte y remitente válidos. |
| `RESEND_API_KEY` | Solo si eliges el transporte Resend configurado en `config/mail.php`. Es la **única** fuente de la clave: el panel de Configuración no la guarda ni la muestra. |
| `OPENAI_API_KEY`, `OPENAI_MODEL` | Opcionales; únicamente para consultas que no coinciden con reglas locales del chat. |
| `JWT_SECRET`, `JWT_TTL`, `JWT_LEEWAY` | Autenticación de `/api/auth/*`. `JWT_SECRET` debe tener al menos 32 bytes; si está vacío, `config/jwt.php` intenta usar `APP_KEY`. |

No publiques `.env` ni pegues secretos en incidencias, registros o documentación. Cuando cambies variables en una aplicación con configuración cacheada, revisa esa caché antes de atribuir el resultado al código.

## Base de datos y datos iniciales

Las migraciones incluyen usuarios, sesiones, caché y trabajos, estudiantes, cursos, matrículas, permisos, ajustes, procesos, auditoría e intenciones/registros del chat. La aplicación puede mostrar páginas públicas sin sembrar datos, pero el chat consulta `chat_intents` y escribe en `chat_logs`: **necesita una base migrada y disponible**.

`php artisan db:seed` ejecuta `database/seeders/DatabaseSeeder.php`: roles, permisos, configuración inicial e intenciones del chat. **No crea usuarios ni contraseñas** y puede repetirse: no modifica cuentas existentes ni sobrescribe la configuración editada desde el panel. Sí sincroniza los permisos de los roles base (`admin`, `coordinador`, `secretaria`) con la matriz de `RolesAndPermissionsSeeder`, por lo que **descarta permisos concedidos a mano** a esos roles; en una base con datos reales, los permisos nuevos se aplican con migraciones (p. ej. `2026_10_27_000012_add_matriculas_exportar_eliminar_permissions.php`), no con el seeder.

```powershell
php artisan db:seed
```

Las cuentas del panel se crean con un comando que pide la contraseña de forma oculta (mínimo 12 caracteres con mayúsculas, minúsculas, números y símbolos), no la imprime y se niega a modificar un correo ya registrado:

```powershell
php artisan horus:crear-usuario --email=correo@ejemplo.com --name="Nombre" --rol=admin
```

`--rol` acepta `admin`, `coordinador` o `secretaria`. Los datos de ejemplo (cursos, estudiantes, matrículas, procesos y aprobaciones) están separados en `DemoDataSeeder`, no crean usuarios y se niegan a ejecutarse con `APP_ENV=production`. Úsalos **solo en una base local desechable**:

```powershell
php artisan db:seed --class=DemoDataSeeder
```

Las versiones anteriores sembraban cuentas `admin@`, `coordinador@` y `secretaria@horusgroup.com` con una contraseña publicada en el repositorio. Si tu base se sembró así, cambia esas contraseñas o elimina esas cuentas.

Para tareas parciales, inspecciona el seeder correspondiente antes de ejecutarlo. En particular, `ChatIntentSeeder` usa `updateOrCreate` y puede reemplazar campos de intenciones existentes; no es un mecanismo inocuo para actualizar producción. `migrate:fresh` y `db:wipe` eliminan datos y no forman parte del arranque normal.

## Acceso y funciones

- **Público:** `/`, páginas de tecnologías, educación, mercado, galería, contacto y textos legales definidos en `routes/web.php`; `/register`, `/login` y recuperación de contraseña.
- **Panel:** `/admin` requiere sesión y rol `admin`, `coordinador` o `secretaria`. Cada controlador del panel exige además un permiso de Spatie por acción mediante `HasMiddleware`; las operaciones sin permiso responden 403. Asignar roles a una cuenta requiere `usuarios.asignar-roles`. La inscripción pública no concede rol administrativo. El rol `admin` no se puede renombrar ni eliminar ni perder sus permisos críticos, y no se puede eliminar ni quitar el rol al último administrador (ni a uno mismo). La exportación CSV de matrículas (`matriculas.exportar`) y su eliminación (`matriculas.eliminar`) son permisos propios, solo de `admin` por defecto.
- **Gestión:** estudiantes, cursos y matrículas; usuarios/roles; procesos y aprobaciones; notificaciones; configuración; auditoría; reportes CSV, Excel y PDF. Consulta las rutas y permisos reales antes de cambiar una operación.
- **Exportación de datos (`admin/respaldo`):** genera un ZIP de CSV con nueve tablas seleccionadas; requiere extensión ZIP y el permiso `respaldo.exportar`. Es una **exportación administrativa minimizada, no un respaldo**: omite contraseñas, tokens y claves, enmascara secretos, no se puede usar para restaurar y no se conserva ninguna copia en el servidor (queda un registro en auditoría). La copia integral de PostgreSQL (`pg_dump`) y la política de retención son responsabilidad del servidor; véase [`docs/seguridad-l1.1.md`](docs/seguridad-l1.1.md).
- **API:** `routes/api.php` expone registro, login, usuario actual y logout bajo `/api/auth/*`. `me` y `logout` requieren token Bearer JWT; el login web usa sesión y CSRF.
- **Correo:** el restablecimiento de contraseña depende del transporte configurado. Con `MAIL_MAILER=log` el mensaje queda en los registros locales y no llega a una bandeja externa.

## Asistente virtual

El widget público se muestra en `resources/views/components/public/chat-widget.blade.php`; la conversación reutilizable está en `resources/views/components/chat/conversation.blade.php`. `resources/js/app.js` envía el mensaje a `POST /chat/mensaje` con token CSRF. La ruta valida `mensaje` (máximo 2000 caracteres) y `session_id` (máximo 64), y tiene límite `throttle:30,1`.

`app/Services/AsistenteVirtualService.php` busca coincidencias por palabras y prioridad. Las respuestas locales verificadas están en `app/Support/ChatKnowledge.php`; también admite intenciones personalizadas activas en `chat_intents` y respeta las intenciones incorporadas desactivadas allí. Para intenciones incorporadas activas, el servicio usa las definiciones del código. Una consulta sin coincidencia puede llamar a OpenAI si hay clave; si falta la clave o la llamada no da respuesta utilizable, ofrece una respuesta de respaldo. Cada interacción se guarda en `chat_logs`.

El área `admin/asistente` permite probar respuestas y ver hasta 50 consultas recientes. La tabla de intenciones se muestra para consulta; esa pantalla **no tiene un formulario de entrenamiento**. Para ajustar respuestas incorporadas, revisa el contenido público verificable, modifica `ChatKnowledge.php` y añade o actualiza pruebas de regresión. Para cambios de datos en `chat_intents`, evalúa el efecto del seeder antes de ejecutarlo.

La clave OpenAI se lee en el servidor desde `config/services.php`. No la envíes al cliente. El chat local y las pruebas simuladas no verifican conectividad real con OpenAI.

## Pruebas y comprobaciones

El perfil PostgreSQL aislado y sus salvaguardas están en [docs/postgresql-l2.2-a.md](docs/postgresql-l2.2-a.md). Su preparación no autoriza ejecutar pruebas destructivas; la suite habitual conserva SQLite en memoria.

```powershell
php artisan test --compact
npm test
npm run build
```

`npm test` ejecuta las pruebas unitarias del frontend con el runner nativo de Node. Los tests y smokes JavaScript dentro de `tests/frontend/` y `tests/browser/` deben usar siempre `.mjs`; el comando falla si encuentra allí `.js`, `.cjs`, TypeScript u otra extensión. Nombra las pruebas unitarias `*.test.mjs` o `*.spec.mjs`. Los smokes de navegador que requieren un servidor se ejecutan por separado.

Para un cambio acotado de PHP, puedes ejecutar `php artisan test --filter=AsistenteVirtualTest` u otra clase pertinente. `phpunit.xml` configura SQLite en memoria, sesión y caché aisladas, cola síncrona y correo simulado. Las pruebas PHP no demuestran PostgreSQL ni servicios externos reales. Si cambias el chat del navegador, comprueba también la interfaz, CSRF y las respuestas visibles.

`tests/browser/chat-smoke.mjs` abre Edge sin interfaz y envía una pregunta conocida y otra desconocida. Requiere **un servidor Laravel de prueba ya activo** en `http://127.0.0.1:8765/`, con base desechable migrada y `OPENAI_API_KEY` vacía. Ejecuta desde otra terminal:

```powershell
node tests/browser/chat-smoke.mjs
```

`CHAT_SMOKE_URL` cambia la URL objetivo y `EDGE_PATH` la ruta del ejecutable. La prueba envía solicitudes reales y guarda registros en la base conectada: verifica la URL y el destino antes de correrla. El perfil temporal de Edge se limpia al terminar y ante interrupciones habituales. Esta comprobación no acredita un despliegue ni el funcionamiento de PostgreSQL u OpenAI.

## Problemas frecuentes

| Síntoma | Comprobación inicial |
| --- | --- |
| `419` al enviar el chat | Comprueba que cargas la página y envías a la misma aplicación/host, que la sesión funciona y que la página tiene un token CSRF vigente; recarga la página. |
| Chat sin respuesta o error `500` | Revisa `storage/logs/`, migraciones y conexión a DB; `chat_intents` se consulta y `chat_logs` se escribe en cada mensaje. |
| Página sin estilos o interacción | Ejecuta `npm run build` o inicia `npm run dev`; confirma que visitas Laravel mediante `php artisan serve`. |
| Imágenes rotas o secciones sin su diseño propio | Ejecuta `npm run assets:sync` y comprueba que exista `public/assets/`. Después recarga la página. |
| Laravel intenta cargar Vite después de cerrarlo | Confirma que Vite ya no está activo y elimina el marcador temporal `public/hot`; luego usa `npm run build` o reinicia `npm run dev`. |
| `could not find driver` | Activa `pdo_pgsql` para PostgreSQL o `pdo_sqlite` para las pruebas, según el comando. |
| No llega un correo | Comprueba `MAIL_MAILER`; con `log` no se envían mensajes reales. |
| El smoke de Edge no conecta | Revisa el servidor en el puerto 8765, `CHAT_SMOKE_URL`, `EDGE_PATH` y que la base sea de prueba. |

## Seguridad y alcance

Este repositorio no incluye en esta guía una receta verificada de despliegue, monitorización ni restauración integral. Antes de publicar, configura secretos y remitente fuera del repositorio, desactiva depuración, revisa permisos y destinos de datos, prepara copias verificables y comprueba el recorrido con los servicios reales del entorno. Las pruebas locales y el build son comprobaciones de desarrollo, no una certificación de producción.

Las instrucciones para asistentes que editan este proyecto están en [`CLAUDE.md`](CLAUDE.md).
