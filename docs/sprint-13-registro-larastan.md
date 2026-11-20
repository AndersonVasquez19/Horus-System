# Sprint 13: registro y Larastan nivel 6

Fecha: 28 de octubre de 2026. Rama: `anderson`.

## Alcance y punto de partida

Se trabaja sobre los cambios locales de sprints anteriores, con autorización expresa del propietario para añadir cambios focalizados en archivos compartidos. Se preserva el índice, incluida la modificación previamente preparada de `ConfiguracionController.php`. El inventario de este documento identifica exclusivamente los cambios añadidos por Sprint 13; el diff total del checkout incluye trabajo anterior.

Sin cambios en frontend, Blade, plantillas de correo, contenido público, rutas, credenciales, entornos, módulos Docentes/Sesiones/Certificados ni valores predeterminados de publicación o transporte. Sin PostgreSQL S7, migraciones sobre datos reales, correos reales, Resend, OpenAI real, commits, push, stashes ni despliegue.

## Enumeración: comportamiento confirmado

Fuentes: `routes/web.php`, `routes/api.php`, ambos `AuthController`, `Email`, `PasswordPolicy`, `User`, migración inicial de usuarios, middleware `ThrottleRequests` del framework instalado y pruebas de autenticación.

| Camino | Cuenta nueva, petición válida | Correo existente | Información observable |
| --- | --- | --- | --- |
| `POST /register`, formulario web | Crea usuario sin rol, HTTP 302 hacia login y mensaje de éxito existente; no inicia sesión | HTTP 302 de regreso, error de email «Ya existe una cuenta con este correo.» | Destino de redirección y error visible al seguirla |
| `POST /register`, solicitando JSON | Mantiene la redirección de éxito del controlador | HTTP 422, `message` y `errors.email` con el mensaje de duplicado | Estado HTTP, estructura y error de email |
| `POST /api/auth/register` | HTTP 201 con `user: {id, name, email}`, `access_token`, `token_type`, `expires_in` | HTTP 422 con `message` y `errors.email` | Estado HTTP, error, presencia de usuario y JWT |

El registro web exige visitante sin sesión mediante `guest`, CSRF y términos aceptados. El registro API no tiene `guest` ni exige términos. Ninguno asigna roles administrativos ni acredita propiedad del buzón. No se modifica esa diferencia contractual.

La normalización hace recorte y minúsculas antes de validar. La consulta de unicidad compara `lower(email)`, también contra filas antiguas con mayúsculas. Por tanto, la señal permite consultar pertenencia a la tabla de usuarios sin distinguir esas variantes. No revela por sí sola contraseña, rol, verificación, actividad ni acceso administrativo. La regla de email se ejecuta aunque otros campos fallen: no debe suponerse que exigir una contraseña válida impide consultar la unicidad.

Ambas rutas mantienen `throttle:5,1`. El middleware instalado usa dominio de ruta e IP para visitantes, sin prefijo distinto entre estos endpoints: comparten el contador. La sexta petición se rechaza con 429 y `Retry-After`; los intentos fallidos cuentan y la ventana expira. Se comprueba con peticiones alternadas y reloj de pruebas, sin esperar ni ampliar límites. Las rutas de recuperación/reset con el mismo throttle también usan esa firma; el login emplea su limitador nombrado, por IP y correo. Los límites no dependen de que la cuenta exista y reducen frecuencia, pero no eliminan enumeración ni demuestran resistencia a consultas distribuidas. No se certifica la identificación efectiva de IP detrás del proxy de producción.

Recuperación conserva la respuesta genérica del Sprint 12 para cuenta ausente, existente, throttle del broker y fallo simulado de transporte. No se cambia ese flujo. Ni esas pruebas ni las nuevas acreditan igualdad temporal de respuestas.

## Alternativas y decisión pendiente

1. **Compatible con el contrato actual:** mantener mensajes, estructuras, estados HTTP y límites existentes, minimizar la entrada persistida y añadir regresiones. Es lo implementado. No resuelve la enumeración explícita.
2. **Uniformar solo el error de duplicado:** requiere autorización para cambiar copy/respuestas. Es insuficiente si sigue habiendo éxito diferente, JWT únicamente para cuentas nuevas o diferencias de redirección. Incluso uniformar la respuesta completa de registro deja una comprobación activa posterior si el atacante puede crear la cuenta con una contraseña elegida y probar login inmediatamente.
3. **Propuesta recomendada, no implementada:** comprobar propiedad del correo antes de habilitar una cuenta nueva y emitir credenciales. Para solicitudes sintácticamente válidas, tanto con correo conocido como desconocido, web respondería 302 hacia login con un mismo mensaje neutro y API respondería 202 con exactamente `{"message":"Si la solicitud puede continuar, recibirás instrucciones en ese correo."}`, sin `user` ni JWT. Los errores de formato/política seguirían siendo validaciones; los límites seguirían aplicándose. Los detalles de confirmación no revelarían existencia a quien no pueda demostrar propiedad del buzón. No se enviaría ningún JWT de una cuenta existente ni se reemplazaría su contraseña.

La tercera opción rompe el contrato API actual de 201 con JWT inmediato, cambia el flujo de alta web y requiere diseñar confirmación, caducidad/consumo de tokens, controles de abuso del correo, comportamiento para cuentas existentes y migración de clientes. También requiere autorización específica para mensajes, cualquier plantilla/vista afectada y operación de correo. La propuesta no es una autorización para habilitar Resend ni modificar frontend. No se crea ese flujo en este sprint.

Archivos afectados por una futura decisión: ambos `AuthController`, pruebas de registro/JWT, y, si se adopta confirmación, nuevos contratos backend de confirmación y las plantillas expresamente aprobadas. Las rutas y las vistas actuales permanecen intactas.

## Defecto compatible corregido

**S13-01: persistencia innecesaria de campos sensibles inesperados en errores de registro web.** El manejador de validación descartaba las contraseñas habituales, pero guardaba el resto de la petición en `_old_input`. Se reprodujo con campos ficticios `access_token`, `api_key` y un objeto `credentials`, tanto por confirmación incorrecta como por correo duplicado. No demuestra extracción de credenciales del servidor ni acceso a otra sesión: demuestra persistencia evitable de entrada potencialmente sensible enviada por el cliente.

`Admin/AuthController::register()` captura únicamente `ValidationException`, conserva la misma excepción y limita la entrada que recibirá el manejador a `name`, `email` normalizado y `terms`. También vacía la bolsa de query después de conservar esos campos: sustituir solo el cuerpo no bastaba, pues Laravel une parámetros de query al calcular la entrada anterior. Una tercera regresión reprodujo ese caso antes de corregirlo. No se registran valores ni detalles de credenciales.

Se mantienen reglas, mensajes, redirecciones, estados HTTP y JSON, campos legítimos repuestos, hashing y asignación explícita de atributos. La validación de duplicados no modifica la cuenta existente ni envía notificaciones. El alta API conserva la transacción del Sprint 12 que incluye usuario y emisión JWT; sus regresiones de rollback siguen aprobadas. El alta web actual contiene una sola escritura de usuario y no se añaden efectos ni transacciones sin una necesidad adicional demostrada.

## Las 34 observaciones de nivel 6

La ejecución inicial reprodujo exactamente **34** errores: 25 `missingType.iterableValue` y 9 `missingType.generics`. La clasificación primaria es anotación ausente (25) o contrato genérico incompleto (9). Todas limitaban la descripción de tipos; ninguna de esas 34 se clasificó como error real demostrado, problema propio de inferencia, código redundante o posible defecto de ejecución que exigiera modificar lógica. S13-01 fue encontrado por la revisión funcional, no por Larastan.

| Archivo / método inicial | Observaciones | Clasificación | Contrato añadido |
| --- | ---: | --- | --- |
| `Api/AuthController::userPayload` | 1 | Anotación ausente | Shape exacta `id: int, name: string, email: string` según el esquema de usuarios |
| `PublicarCursoRequest::rules` | 1 | Anotación ausente | Mapa de listas de strings o regla `In` |
| `ReportMatriculasRequest::rules` | 1 | Anotación ausente | Strings, `In`, `Exists` |
| `StoreCursoRequest::rules` | 1 | Anotación ausente | Strings, `Unique`, `In` |
| `StoreEstudianteRequest::rules` | 1 | Anotación ausente | Strings, `Unique`, `In` |
| `StoreMatriculaRequest::rules`, `after` | 2 | Anotación ausente | Strings, `Exists`, `In`; lista de closures `Validator -> void` |
| `UpdateCursoRequest::rules`, `after` | 2 | Anotación ausente | Strings, `Unique`, `In`; lista de closures `Validator -> void` |
| `UpdateEstudianteRequest::rules` | 1 | Anotación ausente | Strings, `Unique`, `In` |
| `UpdateMatriculaRequest::rules`, `after` | 2 | Anotación ausente | Strings, `Exists`, `In`; lista de closures `Validator -> void` |
| `AprobacionInterna::solicitante`, `aprobador` | 2 | Contrato genérico incompleto | `BelongsTo<User, $this>` |
| `Curso::matriculas`, `estudiantes` | 2 | Contrato genérico incompleto | `HasMany<Matricula, $this>`, `BelongsToMany<Estudiante, $this>` |
| `Estudiante::matriculas`, `cursos` | 2 | Contrato genérico incompleto | `HasMany<Matricula, $this>`, `BelongsToMany<Curso, $this>` |
| `NotificacionInterna::user` | 1 | Contrato genérico incompleto | `BelongsTo<User, $this>` |
| `ProcesoInterno::responsable`, `creador` | 2 | Contrato genérico incompleto | `BelongsTo<User, $this>` |
| `GuardedConnectionFactory::createConnection`, parámetro `config` | 1 | Anotación ausente | Configuración `array<string, mixed>` |
| `GuardedPostgresConnector::connect`, `identity` | 2 | Anotación ausente | Mapas de configuración e identidad PDO |
| `MemoryOnlyConnector::connect` | 1 | Anotación ausente | Mapa de configuración |
| `PostgresTestGuard::laravelConnection`, entrada/salida | 2 | Anotación ausente | Mapas de configuración |
| `PostgresTestGuard::assertConfiguration`, dos entradas y salida | 3 | Anotación ausente | Mapas heterogéneos de configuración/entorno |
| `PostgresTestGuard::assertIdentity`, dos entradas | 2 | Anotación ausente | Mapas de configuración/identidad |
| `PostgresTestGuard::authorize`, parámetro `connection` | 1 | Anotación ausente | Mapa de configuración |
| `TestDatabaseSafety::postgresConfiguration` | 1 | Anotación ausente | Mapa de configuración |
| **Total** | **34** | | |

Los mapas de configuración/entorno usan valores `mixed` porque aceptan estructuras heterogéneas y también entradas inválidas que las salvaguardas deben rechazar. No se finge que llegan validados. Se documentan además las closures de configuración y autorización; el tipo genérico de retorno de `authorize()` conserva el resultado real del callback. No cambia ninguna condición, consulta, allowlist, timeout o autorización de las salvaguardas. Pint solo añade normalización de estilo en los archivos propios.

El nivel permanente sube a **6** con el mismo alcance ampliado: `app/`, `routes/`, `database/`, `config/`, `bootstrap/app.php`, `bootstrap/providers.php`. Sin baseline, `ignoreErrors`, casts añadidos, cambios de ejecución para satisfacer el analizador ni exclusiones de carpetas. `CLAUDE.md` actualiza únicamente la referencia del comando a nivel 6, preservando su edición anterior.

## Pruebas y resultados

Archivo nuevo: `tests/Feature/Sprint13RegistrationSecurityTest.php`, **9 casos** contando datasets:

- Tres regresiones de minimización de entrada: confirmación incorrecta, duplicado y parámetros de query inesperados. Cada camino falló antes de su corrección.
- Dos casos de respuesta JSON exacta para duplicado web/API con variantes de correo y cuenta existente sin mutaciones.
- Dos casos de cinco intentos, rechazo del sexto y expiración del límite, web/API.
- Un caso de contador compartido entre ambos registros.
- Un caso de éxito API: estructura y campos originales, correo normalizado, contraseña hasheada, sin rol y sin notificación.

Los fixtures son ficticios; se bloquea HTTP no simulado y se usa `Notification::fake()`. Las pruebas verifican explícitamente SQLite `:memory:`. La clave JWT se simula solo en la configuración de la prueba; no se consulta ni modifica ninguna credencial.

| Comprobación | Resultado |
| --- | --- |
| Filtro de registro, autenticación y salvaguardas: Sprint 13, AuthHardening, AuthEmailNormalization, ApiJwtRoundTrip, Sprint12AuthSecurity, Sprint12Atomicity, PostgresTestGuard, TestDatabaseSafety | **134 pruebas, 712 aserciones**, aprobadas |
| `php artisan test --compact` | **765 pruebas, 4761 aserciones**, aprobadas; 62,05 s |
| `php vendor/phpstan/phpstan/phpstan analyse --level=5 --no-progress` | Sin errores |
| `php vendor/phpstan/phpstan/phpstan analyse --level=6 --no-progress` | Sin errores |
| Pint sobre los 21 archivos PHP propios | Formato aplicado y `--test` final aprobado |
| `git diff --check` | Aprobado; advertencias LF/CRLF conocidas |

Se usan únicamente SQLite en memoria, sesión/caché `array`, cola `sync` y correo `array`. No se ejecutan perfil PostgreSQL, navegador ni compilación de frontend: el frontend no se modifica. Las pruebas de las salvaguardas PostgreSQL usan fixtures/mocks y rechazo previo a conexión, no una base real.

Verificación de cierre: 22 archivos versionados cambiaron respecto del inventario inicial y se añadieron el test y este informe (24 archivos propios). El índice coincide con el inicial. Los archivos versionados de `resources/views`, `resources/css`, `resources/js` y `public` coinciden con HEAD. Se preserva el resto del trabajo previo.

## Archivos modificados por Sprint 13

- `app/Http/Controllers/Admin/AuthController.php`: S13-01.
- `app/Http/Controllers/Api/AuthController.php`: anotación de `userPayload`, sobre la transacción previa preservada.
- `app/Http/Requests/PublicarCursoRequest.php`, `ReportMatriculasRequest.php`, `StoreCursoRequest.php`, `StoreEstudianteRequest.php`, `StoreMatriculaRequest.php`, `UpdateCursoRequest.php`, `UpdateEstudianteRequest.php`, `UpdateMatriculaRequest.php`: contratos de reglas y callbacks.
- `app/Models/AprobacionInterna.php`, `Curso.php`, `Estudiante.php`, `NotificacionInterna.php`, `ProcesoInterno.php`: genéricos de relaciones.
- `app/Support/Testing/GuardedConnectionFactory.php`, `GuardedPostgresConnector.php`, `MemoryOnlyConnector.php`, `PostgresTestGuard.php`, `TestDatabaseSafety.php`: anotaciones de tipos; controles intactos.
- `phpstan.neon.dist`: nivel 6.
- `tests/Feature/Sprint13RegistrationSecurityTest.php`: nuevo.
- `CLAUDE.md`: referencia del nivel de análisis.
- `docs/sprint-13-registro-larastan.md`: este informe.

## Riesgos y límites pendientes

- La enumeración explícita sigue abierta hasta decidir y autorizar el contrato de registro. No se presenta S13-01 como mitigación de esa señal.
- La unicidad previa es una consulta, no una garantía de exclusión mutua. La restricción de la tabla protege duplicados exactos, pero solicitudes simultáneas pueden pasar ambas la validación y una terminar en excepción de unicidad. No se captura indiscriminadamente ni se reintenta. Decidir la traducción de ese fallo a una respuesta pública exige autorización; no se ha probado concurrencia real.
- El índice actual es sobre `email`, no sobre `lower(email)`. La validación cubre diferencias de mayúsculas antes de escribir; no demuestra protección de escrituras externas que omitan esa validación. Un saneamiento de filas históricas o índice funcional requiere alcance propio, revisión de colisiones y autorización para datos reales. El lookup de login continúa usando el correo normalizado con comparación de columna; no se acredita acceso a filas históricas no normalizadas en PostgreSQL.
- SQLite no prueba bloqueos, concurrencia, DDL ni collation de PostgreSQL. Larastan no certifica seguridad, ausencia de errores de ejecución ni invulnerabilidad.
- No se mide indistinguibilidad temporal de registro/login/recuperación, ni se certifica configuración de producción, proxy, TLS, correo, OpenAI o despliegue.
- No se habilita verificación de email ni se modifican políticas de cuentas, permisos o recuperación. Cualquier confirmación de propiedad y sus efectos externos siguen pendientes de decisión independiente.
