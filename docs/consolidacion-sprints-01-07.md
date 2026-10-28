# Consolidación local de los sprints 01–07

28 de octubre de 2026. Rama `anderson`. Base conservada: `d152655d61660465e46ffd92b545d865cc528f99`.

Esta tarea solo ordena y confirma trabajo funcional acumulado. No implementa funcionalidades ni cambia código funcional. Se crearon seis commits funcionales; este informe se registra en un commit documental posterior. No se hizo push, merge, PR, rebase, amend ni despliegue.

## Procedencia y agrupación

El inventario inicial contenía 60 archivos pendientes: 26 modificados y 34 nuevos. La procedencia funcional se determinó por los diffs, contratos, pruebas y documentos existentes; Git no acredita por sí solo la autoría individual de cambios sin commit. Se conservaron los cambios anteriores de Claude y Codex.

La autenticación ya estaba confirmada en `a7dfe48` y `3a1c5f6`; `horus:promover-admin` estaba confirmado en `b97c3ea`. No había cambios pendientes en esos archivos ni se reconstruyeron sus commits.

Se separaron con `git apply --cached` los fragmentos del Sprint 01 en `MatriculaController.php` y en la ficha estudiantil. El resto del controlador se confirmó en Sprint 06 y el resumen de historial estudiantil en el grupo 02–03. No se usó staging masivo.

Los sprints 02–03 se agruparon porque los cambios de filtros/PDF y el escritor Excel comparten imports, firmas, consultas y ciclo de vida de temporales en `ReportController.php`. Dividirlos requeriría reconstruir una versión intermedia que no era el conjunto validado. El ajuste de `ConcurrentTestSession` y su prueba pertenece a la limpieza Larastan del Sprint 03; no incorpora documentos de aprovisionamiento B2 ni evidencias S7.

## Commits funcionales e inventario exacto

### Sprints 01 — `4fda959797904165fe23e2b00959ef047437e9f6`

feat(matriculas): preselect enrollment from student and course details

Código y configuración:

- `app/Http/Controllers/Admin/MatriculaController.php` (solo fragmentos de preselección)
- `resources/views/admin/cursos/show.blade.php`
- `resources/views/admin/estudiantes/show.blade.php` (solo fragmentos de preselección)
- `resources/views/admin/matriculas/_form.blade.php`

Pruebas y medición:

- `tests/Feature/AdminMatriculaContextTest.php`

### Sprints 02-03 — `f9ba081dbf8c1df39e65003a84e62c89dc3dce47`

feat(reportes): align filtered exports and reduce XLSX memory use

Código y configuración:

- `app/Http/Controllers/Admin/ReportController.php`
- `app/Http/Requests/ReportMatriculasRequest.php`
- `app/Services/MatriculasExcelExport.php`
- `app/Support/SpreadsheetCellCache.php`
- `app/Support/TemporaryExportFile.php`
- `app/Support/Testing/ConcurrentTestSession.php`
- `resources/views/admin/estudiantes/show.blade.php`
- `resources/views/admin/reportes/_alcance.blade.php`
- `resources/views/admin/reportes/index.blade.php`
- `resources/views/admin/reportes/pdf.blade.php`

Pruebas y medición:

- `tests/Feature/AdminReportConsistencyTest.php`
- `tests/Feature/ReportExcelScalabilityTest.php`
- `tests/Unit/ConcurrentTestSessionTest.php`
- `tests/Unit/SpreadsheetCellCacheTest.php`
- `tests/Unit/TemporaryExportFileTest.php`
- `tests/benchmarks/report-excel.php`

Documentación:

- `README.md`
- `docs/sprint-03-backend-excel.md`

### Sprints 04 — `6fa80eb622bd9afed9be7648a7cf929c90bfbf92`

feat(chatbot): resolve locally and bound optional OpenAI calls

Código y configuración:

- `app/Http/Controllers/Admin/AsistenteController.php`
- `app/Http/Controllers/ChatWidgetController.php`
- `app/Services/AsistenteVirtualService.php`
- `app/Support/ChatIntentMatcher.php`
- `app/Support/ChatKnowledge.php`
- `config/chatbot.php`
- `resources/css/chatbot.css`
- `resources/js/app.js`
- `resources/js/chat-client.js`
- `resources/views/components/chat/conversation.blade.php`
- `routes/web.php`

Pruebas y medición:

- `tests/Feature/AsistenteVirtualTest.php`
- `tests/Feature/ChatbotHybridTest.php`
- `tests/browser/chat-smoke.mjs`
- `tests/frontend/chat-client.test.mjs`

Documentación:

- `docs/sprint-04-chatbot-hibrido.md`

### Sprints 05 — `8c6434601ef532403ac78b226b19ec010af2f9df`

feat(matriculas): queue welcome notifications through a disabled outbox

Código y configuración:

- `.env.example` (solo plantilla; claves vacías en Git)
- `app/Console/Commands/DispatchMatriculaNotifications.php`
- `app/Jobs/SendMatriculaWelcome.php`
- `app/Mail/MatriculaWelcome.php`
- `app/Models/Matricula.php`
- `app/Models/MatriculaNotification.php`
- `app/Observers/MatriculaNotificationObserver.php`
- `app/Services/MatriculaNotificationService.php`
- `config/matricula-mail.php`
- `database/migrations/2026_10_28_000020_create_matricula_notifications_table.php`
- `resources/views/emails/matricula-welcome-text.blade.php`
- `resources/views/emails/matricula-welcome.blade.php`
- `routes/console.php`

Pruebas y medición:

- `tests/Feature/MatriculaNotificationTest.php`

Documentación:

- `docs/sprint-05-resend-matriculas.md`

### Sprints 06 — `868c984412b8fa444bbddc3f7f68da569398163f`

fix(backend): recheck enrollment eligibility and enforce numeric limits

Código y configuración:

- `app/Http/Controllers/Admin/MatriculaController.php`
- `app/Http/Requests/StoreCursoRequest.php`
- `app/Http/Requests/UpdateCursoRequest.php`

Pruebas y medición:

- `tests/Feature/BackendRobustnessTest.php`

Documentación:

- `docs/sprint-06-robustez-backend.md`

### Sprints 07 — `6f802015eb913a4b8009424d6585c448f9ce4e01`

fix(matriculas): check seat acquisition against the locked current row

Código y configuración:

- `app/Http/Requests/UpdateMatriculaRequest.php`

Pruebas y medición:

- `tests/Feature/MatriculaOperationsTest.php`

Documentación:

- `docs/sprint-07-consistencia-matriculas.md`

## Validación repetida

Las comprobaciones se ejecutaron sobre el conjunto consolidado completo antes de confirmar. No se ejecutó una suite independiente en cada checkout intermedio. Antes de cada commit se volvió a comprobar rama, HEAD esperado, índice, allowlist, diff cacheado, patrones de secretos y hashes SHA-256 de todos los archivos locales originales.

| Comprobación | Resultado |
| --- | --- |
| PHPUnit completo, `php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml` | 617 pruebas, 3491 aserciones; aprobadas; 66,883 s, pico PHP 100 MiB |
| Larastan, `php vendor/phpstan/phpstan/phpstan analyse --no-progress` | Sin errores; nivel 5, alcance configurado `app/Support` y `app/Services` |
| Pint, `php vendor/laravel/pint/builds/pint --test` con allowlist explícita | 39 archivos PHP del inventario, excluyendo Blade; aprobado, sin reformatear |
| `npm test` | 7 pruebas aprobadas |
| `npm run build` | Aprobado, Vite 8.3.5; archivos generados ignorados, no incluidos |
| `git diff --check` y `git diff --cached --check` por grupo | Aprobados; avisos LF/CRLF sin errores de espacios |

Los procesos PHP fijaron `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL` vacío, `MATRICULA_MAIL_ENABLED=false` y claves OpenAI/Resend vacías. PHPUnit usa correo `array`, cola `sync`, sesión/caché `array`. No existía configuración cacheada. Las migraciones que ejecuta `RefreshDatabase` pertenecen únicamente al SQLite efímero de la suite; no se ejecutó ninguna migración operativa. Las pruebas de notificaciones usan servicios simulados y no prueban entregabilidad real. No se abrió PostgreSQL ni se ejecutó S7 o el perfil aislado.

## Secretos y cambios que permanecen locales

Se detectaron valores con formato de claves de proveedor en `OPENAI_API_KEY` y `RESEND_API_KEY` de `.env.example`. No se verificó su validez contra los proveedores. Se excluyeron del índice mediante una plantilla saneada con ambos valores vacíos, conservando el archivo local byte a byte durante los commits funcionales. Las claves no se reproducen en este informe ni en los commits. Durante la inspección se mostraron en una salida de herramienta: deben revocarse y reemplazarse desde las cuentas correspondientes; esta tarea no accedió a esas cuentas.

Mientras se preparaba este informe se detectó una edición concurrente de `.env.example`: ambas claves ya quedaron vacías y existe un cambio local en `OPENAI_MODEL`. Se preserva esa edición sin añadirla al índice; no se publica su valor ni se atribuye a un sprint. Ese cambio de modelo es la única diferencia sin commit al cierre. `.env` y `.env.pgsql-testing.local` están ignorados y no se incluyeron ni se inspeccionó su contenido con las herramientas.

La revisión de los candidatos y de cada índice no encontró otros patrones de claves de proveedor, claves privadas, JWT completos ni URLs con credenciales. Es una revisión de los archivos candidatos, no una certificación de todo el historial del repositorio.

## Integridad y estado de Git

Los SHA-256 de los 60 archivos pendientes iniciales permanecieron iguales durante los seis commits funcionales. Antes del commit documental se detectó y registró por separado la edición concurrente de `.env.example`; los otros 59 hashes permanecen iguales. Las herramientas de consolidación nunca escribieron el archivo local `.env.example`. El índice queda vacío; no se eliminó ni reescribió trabajo ajeno. Los dos stashes iniciales se preservaron. La referencia local `origin/anderson` no cambió; no se hizo fetch ni push. Se mantuvo la rama y el único worktree.

No hubo conflictos ni fallos en las validaciones. El único riesgo detectado en la consolidación son las claves locales descritas arriba. Persisten los límites funcionales y operativos documentados en cada sprint: SQLite no prueba concurrencia PostgreSQL, Resend sigue sin activación operativa y las decisiones de elegibilidad de matrícula no se cambian aquí.

El commit documental posterior incluye únicamente este archivo. Su hash se puede consultar con `git log -1 --format=%H -- docs/consolidacion-sprints-01-07.md`. No se inicia otro sprint automáticamente.
