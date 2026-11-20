# Consolidación local de Sprints 10–14

28 de octubre de 2026. Rama `anderson`; base `743b791b2f6bf4f1bd413db8ca573069b60ca985`.

El propietario autoriza únicamente commits locales de estos sprints. No se implementan funcionalidades adicionales ni se modifican los archivos funcionales durante la consolidación. Se añade únicamente este inventario técnico. Sin push, merge, PR, despliegue ni inicio de otro sprint.

## Validación actual del conjunto final

- `php artisan test --compact`: **793 pruebas, 5024 aserciones**, aprobadas; 108,63 s.
- Larastan nivel 6 sobre el alcance configurado: sin errores.
- Pint `--test` en los 62 archivos PHP de los sprints: aprobado, sin reformatear archivos.
- `git diff --check`: aprobado; las advertencias LF/CRLF conocidas no son errores.
- SQLite `:memory:`, sesión/caché `array`, correo `array` y cola `sync`. Las pruebas del outbox seleccionan su cola aislada dentro de SQLite. Sin PostgreSQL/S7, migraciones reales, correos reales u OpenAI.
- Comprobación del arranque aislado: verificación de registro false, mailer de verificación null, Resend de matrículas false, rutas de Resend false y catálogo público false. No se cambian esos valores ni los entornos.
- Revisión del allowlist, diffs y búsqueda de patrones de claves privadas/tokens: sin archivos sensibles, temporales, credenciales ni datos personales reales identificados. Los valores de pruebas son fixtures sintéticos.

La validación se ejecuta sobre el conjunto final de los cinco sprints; no se presenta como una ejecución nueva de cada suite en cada commit intermedio. Los informes de cada sprint conservan su evidencia histórica y sus límites.

## Separación y preservación

Se utiliza un índice temporal con rutas explícitas para construir las cinco selecciones. Los archivos de trabajo se mantienen intactos. Las versiones de autenticación anteriores al Sprint 14 coinciden con las huellas iniciales guardadas en esa ejecución; no se reconstruye su comportamiento por aproximación. Los cambios compartidos se introducen en el sprint que los originó y se completan en los posteriores.

El cambio de `ConfiguracionController.php` que ya estaba preparado en el índice pertenece a la transacción del Sprint 12 y se conserva dentro de ese commit. Se preservan los dos stashes históricos sin aplicar, modificar ni eliminar ninguno. La alineación final del índice se limita a las rutas autorizadas del inventario.

El inventario contiene **72 rutas únicas**, con repeticiones deliberadas entre commits por archivos compartidos. Los números por commit cuentan esas repeticiones.

## Sprint 10: 3 archivos

Mensaje previsto: `fix(resend): reforzar validaciones y pruebas de notificaciones`.

- `app/Services/MatriculaNotificationService.php`
- `tests/Feature/MatriculaNotificationTest.php`
- `docs/sprint-10-preparacion-resend.md`

## Sprint 11: 7 archivos

Mensaje previsto: `perf(backend): reducir consultas e hidratación innecesaria`.

- `app/Http/Controllers/Admin/DashboardController.php`
- `app/Http/Controllers/Admin/ReportController.php`
- `app/Http/Controllers/Admin/MatriculaController.php`
- `app/Services/AsistenteVirtualService.php`
- `tests/Feature/BackendPerformanceTest.php`
- `tests/benchmarks/BackendQueriesBenchmarkTest.php`
- `docs/sprint-11-rendimiento-backend.md`

## Sprint 12: 22 archivos

Mensaje previsto: `fix(seguridad): asegurar recuperación y escrituras transaccionales`.

- `CLAUDE.md`
- `app/Console/Commands/CrearUsuarioPanel.php`
- `app/Http/Controllers/Admin/AprobacionController.php`
- `app/Http/Controllers/Admin/AuditoriaController.php`
- `app/Http/Controllers/Admin/ConfiguracionController.php`
- `app/Http/Controllers/Admin/MatriculaController.php`
- `app/Http/Controllers/Admin/ReportController.php`
- `app/Http/Controllers/Admin/RoleController.php`
- `app/Http/Controllers/Admin/UserController.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Auth/ForgotPasswordController.php`
- `app/Http/Controllers/Auth/ResetPasswordController.php`
- `app/Models/AuditLog.php`
- `app/Models/Matricula.php`
- `app/Notifications/ResetPasswordNotification.php`
- `database/seeders/MatriculaSeeder.php`
- `docs/sprint-05-resend-matriculas.md`
- `config/resend.php`
- `tests/Feature/Sprint12AtomicityTest.php`
- `tests/Feature/Sprint12AuthSecurityTest.php`
- `docs/sprint-12-seguridad-robustez-backend.md`
- `phpstan.neon.dist`

## Sprint 13: 24 archivos

Mensaje previsto: `refactor(tipos): completar contratos y adoptar Larastan nivel 6`.

- `CLAUDE.md`
- `phpstan.neon.dist`
- `app/Http/Controllers/Admin/AuthController.php`
- `app/Http/Controllers/Api/AuthController.php`
- `docs/sprint-13-registro-larastan.md`
- `tests/Feature/Sprint13RegistrationSecurityTest.php`
- `app/Http/Requests/PublicarCursoRequest.php`
- `app/Http/Requests/ReportMatriculasRequest.php`
- `app/Http/Requests/StoreCursoRequest.php`
- `app/Http/Requests/StoreEstudianteRequest.php`
- `app/Http/Requests/StoreMatriculaRequest.php`
- `app/Http/Requests/UpdateCursoRequest.php`
- `app/Http/Requests/UpdateEstudianteRequest.php`
- `app/Http/Requests/UpdateMatriculaRequest.php`
- `app/Models/AprobacionInterna.php`
- `app/Models/Curso.php`
- `app/Models/Estudiante.php`
- `app/Models/NotificacionInterna.php`
- `app/Models/ProcesoInterno.php`
- `app/Support/Testing/GuardedConnectionFactory.php`
- `app/Support/Testing/GuardedPostgresConnector.php`
- `app/Support/Testing/MemoryOnlyConnector.php`
- `app/Support/Testing/PostgresTestGuard.php`
- `app/Support/Testing/TestDatabaseSafety.php`

## Sprint 14: 24 archivos

Mensaje previsto: `feat(auth): preparar verificación de correo desactivada`.

- `app/Http/Controllers/Admin/AuthController.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Auth/ResetPasswordController.php`
- `app/Http/Middleware/AuthenticateJwt.php`
- `app/Models/User.php`
- `app/Services/JwtTokenService.php`
- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`
- `routes/api.php`
- `routes/web.php`
- `app/Http/Controllers/Auth/VerificacionRegistroController.php`
- `app/Http/Middleware/DescartarSesionSinVerificar.php`
- `app/Http/Middleware/VerificacionRegistroHabilitada.php`
- `app/Http/Requests/ConfirmarRegistroRequest.php`
- `app/Http/Requests/ReenviarRegistroRequest.php`
- `app/Mail/VerificarRegistroMail.php`
- `app/Models/RegistroPendiente.php`
- `app/Services/VerificacionRegistroService.php`
- `config/registro-verificacion.php`
- `database/migrations/2026_10_28_000022_create_registros_pendientes_table.php`
- `tests/Feature/Sprint14EmailVerificationTest.php`
- `docs/superpowers/plans/2026-10-28-sprint-14.md`
- `docs/sprint-14-verificacion-registro.md`
- `docs/consolidacion-sprints-10-14.md`

## Límites y pendientes conservados

- La verificación de correo permanece apagada. Su integración web, contrato nuevo de clientes y activación requieren autorización posterior.
- Resend permanece apagado. Validación de entrega real e infraestructura no realizada.
- No se modifican frontend, Blade, CSS, JavaScript, public, plantillas existentes ni contenido público. La optimización del chatbot conserva respuestas y catálogo apagado; las ramas nuevas de autenticación permanecen desactivadas.
- SQLite no demuestra concurrencia/bloqueos ni DDL PostgreSQL; Larastan no certifica invulnerabilidad. No se declara resuelta la enumeración en todos los canales ni su indistinguibilidad temporal.
- Los informes `sprint-10` a `sprint-14` contienen el detalle técnico y requisitos de activación. Sus referencias a ausencia de commits describen el cierre histórico de cada implementación, anterior a esta autorización de consolidación.
