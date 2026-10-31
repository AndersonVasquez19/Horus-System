# Horus-System — Contexto técnico del proyecto

> **Actualización PG-TEST-05B-2A, 30/10/2026 (tras la etapa 1): etapa 2 completada y aprobada.** La ejecución `stage2-ronda1` (autorizada; 16:16–16:18, hora local del equipo) corrió PHPUnit 11.5.57 sobre PostgreSQL 18.6 (`127.0.0.1:55433`, `horus_l2_test`, `horus_l2_test_runner`, clúster `horus_pgt_55433`, identificador `7702263743609677164`) con el filtro `AdminEstudianteTest|AdminCursoTest|CursoPublicacionTest` y terminó con `OK (48 tests, 410 assertions)`: **48 pruebas aprobadas (31 métodos, 3 clases), 410 aserciones, cero risky, cero errores, cero fallos y cero omitidas**, en 01:32,478 min y 66 MB. `migrate:fresh` (mediante `RefreshDatabase`) reconstruyó el esquema `public` de `horus_l2_test`, con autorización destructiva expresa del propietario (no incluye `horus_test_control` ni otras bases). Consentimiento retirado, perfil idéntico (`42372EC6…`), Job Object con `Active=0`, 18 evidencias históricas intactas y `probe` e identidad finales aprobados. **Acumulado de las etapas 1 y 2: 164 pruebas / 935 aserciones.** Detalle, hashes y límites: [etapa 2 ejecutada](docs/postgresql-testing-pg-test-04.md#etapa-2-ejecutada-stage2-ronda1-30102026). Plan de la etapa 3 (147 pruebas, preparado y **no ejecutado**): [plan técnico](docs/postgresql-testing-pg-test-04.md#plan-técnico-de-la-etapa-3-preparado-no-ejecutado). **PG-TEST-05B-2A no está finalizada:** siguen pendientes las etapas 3–7, la concurrencia, la suite completa y la restauración.
>
> **Actualización PG-TEST-05B-2A, 30/10/2026 (posterior a la consolidación): etapa 1 completada y aprobada.** La ejecución `stage1-ronda8` (autorizada; 14:11–14:14, hora local del equipo) corrió PHPUnit 11.5.57 sobre PostgreSQL 18.6 (`127.0.0.1:55433`, `horus_l2_test`, `horus_l2_test_runner`, clúster `horus_pgt_55433`, identificador `7702263743609677164`) y terminó con `OK (116 tests, 525 assertions)`: **116 pruebas aprobadas, 525 aserciones, cero risky, cero errores y cero fallos**, sin pruebas omitidas ni avisos. La prueba del snapshot Excel (`REPEATABLE READ, READ ONLY`) y las cinco restricciones de matrículas de PostgreSQL estaban en la selección y no se omitieron. Consentimiento retirado, perfil idéntico, ningún proceso de pruebas al cierre y `probe` e identidad finales aprobados. Auditoría posterior: los 56 hashes de `sha256.json` coinciden con los archivos. **Fue la primera etapa; PG-TEST-05B-2A no está finalizada (ver la actualización de la etapa 2, arriba).**
>
> | Fase | Estado |
> | --- | --- |
> | Etapa 0 | Completada |
> | Etapa 1 | Completada y aprobada (30/10/2026) |
> | Etapa 2 | Completada y aprobada (30/10/2026) |
> | Etapas 3–7 | Pendientes (etapa 3 preparada en plan, sin ejecutar) |
> | Pruebas de concurrencia | Pendientes |
> | Suite completa PostgreSQL | Pendiente |
> | Restauración y validaciones adicionales | Pendientes de autorización |
>
> Detalle, hashes y límites: [etapa 1 revalidada](docs/postgresql-testing-pg-test-04.md#etapa-1-revalidada-stage1-ronda8-30102026). La evidencia está fuera del repositorio; los comandos documentados no constituyen autorización para ejecutar otras etapas.
>
> **Actualización PostgreSQL, 30/10/2026 — consolidación PG-TEST-05:** rama actual `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, índice vacío y dos stashes preservados. La tabla siguiente conserva el corte histórico del 29/10; no describe los resultados posteriores.
>
> Comprobado de nuevo: SQLite **1.211 aprobadas, 1 omitida, 7.759 aserciones**, cero fallos/errores/risky; Larastan nivel 6 aprobado. Enumeración local: **116 nombres originales**, sin duplicados ni solapamiento con etapas 2–7. Identidad y probe PostgreSQL **solo lectura** aprobados en `127.0.0.1:55433`, `horus_l2_test`, `horus_l2_test_runner`, PostgreSQL 18.6, `horus_pgt_55433`, identificador `7702263743609677164`; consentimiento vacío y perfil intacto. Los diez PID históricos y los otros dos terminales pendientes ya no aparecen; el detector corregido admite la inspección actual.
>
> El resultado PostgreSQL funcional de **116 pruebas / 525 aserciones / 79 risky** era **histórico** al redactar esta consolidación y fue revalidado después (ver la actualización anterior: 116 / 525 / **0 risky**). La separación bootstrap/preflight se validó localmente y su revalidación funcional PostgreSQL se autorizó y ejecutó el 30/10 solo para la etapa 1. Al redactar la consolidación no se habían ejecutado etapa 1, concurrencia, migraciones PostgreSQL ni restauraciones; **siguen sin ejecutarse la concurrencia, las etapas 2–7, la suite completa y las restauraciones**. Pint detecta formato preexistente en dos pruebas funcionales preservadas; no se atribuye aprobación global de formato.
>
> Estado, pendientes y procedimiento actual: [consolidación PG-TEST-05](docs/postgresql-testing-pg-test-04.md#13-consolidación-del-30102026) y [detector y arnés](docs/postgresql-testing-detector-procesos.md). Los comandos históricos no constituyen autorización y las cápsulas anteriores no deben usarse con una huella Git obsoleta.

| Control de actualización | Estado examinado |
| --- | --- |
| Última actualización | 29 de octubre de 2026, fecha de Lima |
| Rama / HEAD de partida de la consolidaci?n | `anderson` / `306b168e8d8bbfec23301d7c050c7f7c9ae11d2f` (inspección PostgreSQL posterior a la publicación) |
| Último sprint documentado | 19.2; preparación documental de recepción pública, sin implementarla ni habilitar datos reales |
| Publicación web / catálogo del chatbot PHP / Advisor | Predeterminados apagados, en configuraciones separadas |
| Resend / verificación de correo | Envío de matrícula apagado por defecto; verificación `false` y transporte `null` |
| PostgreSQL S7 / migraciones reales | S7 sigue Running/Manual en loopback 55432, aparcado y sin credenciales; la guarda de pruebas lo rechaza y solo acepta `127.0.0.1:55433` (PG-TEST-02a); clúster 55433 creado en PG-TEST-02b; las 26 migraciones aplicadas solo en `horus_l2_test` (PG-TEST-05B-1), sin pruebas con escrituras. Sin SQL, migraciones ni reparación de credenciales en S7 ni en bases reales el 29/10 |
| Última validación funcional documentada | Restricción comercial admin: 1066 pruebas PHP, 7447 aserciones; SQLite en memoria, 36 frontend, build, Larastan 6, Pint acotado y smoke del panel aprobados |

**Naturaleza del documento:** contexto operativo sustentado en código, locks, pruebas, Git local e informes existentes. No es autorización para ejecutar procedimientos. [AGENTS.md](AGENTS.md) conserva la prioridad normativa; [CLAUDE.md](CLAUDE.md) contiene los contratos y comandos. Una implementación disponible no acredita activación, aplicación de migraciones ni despliegue.

La creación original de este contexto no inspeccionó servicios ni bases. La actualización PostgreSQL del 29/10 y su R1 autorizada comprobaron servicios, puertos, configuración aislada, identidad física y registros, e iniciaron exclusivamente Testing, sin SQL ni secretos: véase el [informe de inspección y R1](docs/postgresql-testing-validacion-final.md). Los restantes resultados históricos no se presentan como nuevas ejecuciones.

## Índice

1. [Identificación del proyecto](#identificacion)
2. [Stack tecnológico](#stack)
3. [Arquitectura general](#arquitectura)
4. [Estructura del repositorio](#estructura)
5. [Módulos del sistema](#modulos)
6. [Historial de sprints y fases](#historial)
7. [Estado de Git](#git)
8. [Estado de bases de datos](#bases)
9. [Migraciones importantes](#migraciones)
10. [Chatbot PHP](#chatbot-php)
11. [Horus AI Advisor](#advisor)
12. [Integración Laravel–FastAPI](#integracion)
13. [Publicación administrativa y web](#publicacion)
14. [Servicios y cotizaciones](#comercial)
15. [Seguridad y reglas innegociables](#seguridad)
16. [Calidad y pruebas](#calidad)
17. [Pendientes y roadmap](#roadmap)
18. [LEER ANTES DE COMENZAR CUALQUIER TAREA](#guia-agentes)

<a id="identificacion"></a>
## 1. Identificación del proyecto

**Horus-System**, para **Horus Group SRL**, reúne una web institucional y un sistema administrativo académico. Su propósito es gestionar estudiantes, cursos, matrículas y cupos, organizar operaciones internas y ofrecer orientación pública con información autorizada. El panel utiliza usuarios administrativos con roles; estudiantes y visitantes no reciben acceso al panel por registrarse.

Usuarios y fronteras: visitantes de la web, cuentas registradas/autenticadas y personal con roles `admin`, `coordinador` o `secretaria`. Los permisos efectivos controlan cada acción; pertenecer al personal no concede todos los permisos. No hay un portal estudiantil completo acreditado por la sola existencia del registro.

Principios: monolito Laravel con validación HTTP y autorización en servidor; transacciones para operaciones relacionadas; protección de cupos; separación entre datos administrativos y fichas públicas; IA opcional con respuestas locales y abstención; servicios externos desactivables; preservación del diseño y del trabajo concurrente. Docentes, Sesiones y Certificados están **excluidos como módulos del negocio**: no desarrollarlos ni reintroducirlos. Que existan textos institucionales sobre certificación no implica un módulo de certificados.

<a id="stack"></a>
## 2. Stack tecnológico

Las versiones de locks describen dependencias fijadas, no una certificación de producción. Solo PHP y Python se consultaron mediante `--version`; no se arrancó Laravel, Python ni PostgreSQL.

| Componente | Versión/fuente verificada | Responsabilidad |
| --- | --- | --- |
| Laravel / Blade | `12.70.1`, `composer.lock`; requisito `^12.0` | HTTP, ORM, validación, vistas y jobs |
| PHP | CLI local `8.4.26`; requisito del proyecto `^8.2` | Backend principal; no confundir mínimo admitido con versión local |
| PostgreSQL | `18.6` en el acta histórica de Fase A; sin consulta nueva | Base habitual y copia de ensayo |
| SQLite Testing | `:memory:` en `phpunit.xml`; versión del motor no medida aquí | Regresión aislada; no equivalencia plena con PostgreSQL |
| Alpine.js / Tailwind CSS | `3.17.5` / `3.4.19`, `package-lock.json` | Interacción y estilos; fuentes protegidas |
| Vite | `8.3.5`, `package-lock.json` | Compilación de recursos |
| PHPUnit / Larastan / Pint | `11.5.57` / `3.13.2` / `1.32.1`, `composer.lock` | Tests, análisis PHP nivel 6 y formato acotado |
| Spatie Permission | `6.25.0`, `composer.lock` | Roles y permisos |
| Python | CLI local `3.13.0`; proyecto admite `>=3.12,<3.14` | Servicio de IA independiente |
| FastAPI / Pydantic / Uvicorn | `0.162.0` / `2.14.1` / `0.54.0`, locks Python | API, esquemas estrictos y servidor ASGI |
| Sentence Transformers / PyTorch CPU | `5.7.0` / `2.15.0+cpu`, lock semántico | Inferencia semántica opcional |
| Modelo semántico | `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` | Modelo preentrenado; no entrenamiento desde cero |

Dependencias adicionales: JWT con `firebase/php-jwt`, PDF con Dompdf, Excel con PhpSpreadsheet y transporte Resend. Consultar [composer.json](composer.json), [composer.lock](composer.lock), [package.json](package.json), [package-lock.json](package-lock.json), [pyproject.toml](chatbot-python/pyproject.toml), [lock básico](chatbot-python/requirements.lock) y [lock semántico](chatbot-python/requirements-semantic.lock). No instalar ni actualizar dependencias solo para alinear este documento.

<a id="arquitectura"></a>
## 3. Arquitectura general

```mermaid
flowchart TD
    Web[Web pública Blade] --> Laravel[Backend Laravel]
    Panel[Panel administrativo] --> Laravel
    Laravel --> PG[(PostgreSQL habitual)]
    Laravel --> PHP[Chatbot PHP híbrido]
    Panel --> Editorial[Fichas CursoPublicacion y autorización]
    Editorial --> PG
    Editorial -. lectura web opcional: flag apagado .-> Web
    PHP -. OpenAI selectivo si está configurado .-> OpenAI[OpenAI]
    PHP -. cliente opcional: flag apagado .-> API[Horus AI Advisor / FastAPI]
    API --> KB[Conocimiento verificado y búsqueda léxica/semántica]
    Editorial -. exportador HMAC preparado: sin sincronización activa .-> API
    Laravel -. outbox preparada: envío apagado .-> Resend[Resend]
    Tests[Pruebas PHP aisladas] --> SQLite[(SQLite en memoria)]
```

Las líneas discontinuas representan caminos opcionales o pendientes; el diagrama no acredita procesos funcionando. La web usa sesión/CSRF. La API de autenticación utiliza JWT. El servicio Python no accede a la base administrativa; no administra matrículas, usuarios ni cotizaciones. El catálogo editorial es una proyección pública separada, no un volcado de `cursos`.

Entradas reales: [rutas web](routes/web.php), [rutas API](routes/api.php) y [bootstrap](bootstrap/app.php). No crear rutas equivalentes a las existentes antes de inspeccionarlas.

<a id="estructura"></a>
## 4. Estructura del repositorio

| Ubicación | Responsabilidad |
| --- | --- |
| `app/Http/Controllers/`, `app/Http/Requests/`, `app/Http/Middleware/` | Contratos HTTP, autorización y validación |
| `app/Models/`, `app/Services/`, `app/Support/` | Dominio, operaciones y utilidades; guardas de pruebas en `app/Support/Testing/` |
| `app/Console/`, `app/Jobs/`, `app/Observers/` | Comandos y captura/despacho de avisos de matrícula |
| `routes/`, `config/`, `bootstrap/` | Entradas, configuración y arranque; no leer/imprimir secretos del entorno |
| `database/migrations/`, `database/seeders/`, `database/factories/` | Esquema, permisos iniciales y datos ficticios de pruebas |
| `resources/views/`, `resources/css/`, `resources/js/` | Frontend Blade/Alpine/Tailwind protegido |
| `public/assets/` | Activos institucionales versionados; preservar identidad visual |
| `tests/Feature/`, `tests/Unit/`, `tests/PostgresIntegrity/` | Regresión funcional, unidades y perfil PostgreSQL explícito |
| `tests/frontend/`, `tests/browser/`, `tests/Support/` | Pruebas JavaScript `.mjs`, smokes y fixtures locales |
| `scripts/` | Herramientas acotadas; `pgsql-test.php` exige perfil aislado |
| `docs/` | Informes por sprint y evidencia histórica |
| `chatbot-python/` | Proyecto Python independiente, incorporado a Git; entornos, modelos y artefactos privados permanecen ignorados |
| `storage/`, `bootstrap/cache/`, `public/build/`, `public/hot` | Datos/artefactos locales; no incorporar logs, caches, sesiones ni compilados a Git |

El backend Laravel está en la raíz, no dentro de otro backend anidado. `vendor/`, `node_modules/`, entornos virtuales y modelos descargados son dependencias locales. El antiguo `legacy-html-node/` solo figura en el historial; no se debe reactivar como un segundo backend.

<a id="modulos"></a>
## 5. Módulos del sistema

«Implementado» significa que existen código, rutas y cobertura relacionada; no confirma disponibilidad contra la base real ni validación completa de todos los recorridos de negocio. El grupo `/admin` exige autenticación y rol de personal. Matriz autoritativa: [RolesAndPermissionsSeeder](database/seeders/RolesAndPermissionsSeeder.php); middleware por acción en cada controlador.

| Módulo / estado del código | Componentes y rutas relevantes | Permisos / cobertura / límites |
| --- | --- | --- |
| Autenticación y usuarios — implementado; verificación preparada y apagada | [Auth web](app/Http/Controllers/Admin/AuthController.php), [Auth API](app/Http/Controllers/Api/AuthController.php), [usuarios](app/Http/Controllers/Admin/UserController.php); `/login`, `/register`, recuperación, `/api/auth/register`, `/login`, `/me`, `/logout` bajo el prefijo `/api/auth`; `/admin/usuarios` | `usuarios.*`; roles no se aceptan del registro público. Pruebas `AdminAuthenticationTest`, `AuthHardeningTest`, `AuthEmailNormalizationTest`, `ApiJwtRoundTripTest`, Sprint 12–14. JWT revocable con `jwt_version`; sesiones de BD revocables; no inventar cierre global de sesiones `array` |
| Roles y permisos — implementado | [RoleController](app/Http/Controllers/Admin/RoleController.php), [AdminProtection](app/Services/AdminProtection.php); `/admin/roles` | `roles.*`, `usuarios.asignar-roles`; `AdminProtectionTest`, `AdminAuthorizationTest`. Protege último admin, rol admin y permisos críticos; nuevos permisos requieren migración, no solo seeder |
| Estudiantes — implementado | [Estudiante](app/Models/Estudiante.php), [controlador](app/Http/Controllers/Admin/EstudianteController.php); CRUD `/admin/estudiantes`, PATCH de restauración | `estudiantes.ver/crear/editar/eliminar/restaurar`; `AdminEstudianteTest`, pruebas de matrículas y filtros. Datos privados, borrado lógico; no exportarlos al conocimiento público |
| Cursos administrativos — implementado | [Curso](app/Models/Curso.php), [controlador](app/Http/Controllers/Admin/CursoController.php); CRUD `/admin/cursos`, PATCH de restauración | `cursos.ver/crear/editar/eliminar/restaurar`; `AdminCursoTest`. Estado activo no autoriza publicación ni demuestra cupos disponibles para inscripción pública |
| Matrículas — implementado, condicionado al esquema real | [MatriculaController](app/Http/Controllers/Admin/MatriculaController.php), [Matricula](app/Models/Matricula.php); CRUD `/admin/matriculas`, PATCH anular/restaurar, GET `/admin/matriculas/exportar/csv` | `matriculas.ver/crear/editar/anular/restaurar/exportar/eliminar`; `AdminMatriculaTest`, `MatriculaOperationsTest`, `MatriculaIntegrityConstraintsTest`, `MatriculaSensitivePermissionsTest`. No eliminar historial ni alterar excepciones de operaciones históricas |
| Cupos — implementado dentro de matrículas | Bloqueo y relectura en operaciones de matrícula; índice parcial y restricciones de `000013` | Mismos permisos de la operación, sin endpoint paralelo. Adquisiciones vigentes exigen estudiante/curso elegibles, no duplicado y cupo. `lockForUpdate` no se mide con SQLite; pruebas PostgreSQL pendientes |
| Publicación editorial — backend/editor/web preparados, consumo apagado | [CursoPublicacion](app/Models/CursoPublicacion.php), [controlador editorial](app/Http/Controllers/Admin/CursoPublicacionController.php), [catálogo](app/Services/CatalogoPublicoCursos.php); GET/PUT/DELETE `/admin/cursos/{curso}/publicacion`, GET `/educacion/cursos` | `cursos.publicar`; `CursoPublicacionTest`, `Sprint17PublicacionTest`, `EditorPublicacionPanelTest`, `CatalogoWebCursosTest`. Editor conectado al panel; fichas solo con autorización separada; depende de `000021` |
| Reportes — implementado | [ReportController](app/Http/Controllers/Admin/ReportController.php), [MatriculasExcelExport](app/Services/MatriculasExcelExport.php); GET `/admin/reportes`, POST `/admin/reportes/exportar/excel` y `/pdf` | `reportes.ver`, `reportes.exportar.excel/pdf`; `AdminReportConsistencyTest`, `ReportExcelExportTest`, `ReportExcelScalabilityTest`. Filtros coherentes, PDF por lote y temporales; XLSX no tiene memoria constante |
| Aprobaciones internas — implementado como flujo genérico | [AprobacionInterna](app/Models/AprobacionInterna.php), [controlador](app/Http/Controllers/Admin/AprobacionController.php); GET/POST/PUT/DELETE `/admin/aprobaciones` | `aprobaciones.ver/gestionar`; cobertura de autorización, filtros, atomicidad y `AprobacionesRespaldoPermissionsMigrationTest`. Estados pendiente/aprobado/rechazado; no sustituye autorización editorial ni constituye aprobación de presupuestos |
| Respaldo — exportación administrativa implementada | [RespaldoController](app/Http/Controllers/Admin/RespaldoController.php); GET `/admin/respaldo`, `/admin/respaldo/exportar` | `respaldo.ver/exportar`; `SecretsAndExportsTest`, `AdminAuthorizationTest`, migración de permisos. ZIP de CSV seleccionados con minimización: **no** backup integral ni herramienta de restauración; backup recuperable requiere procedimiento PostgreSQL autorizado |
| Procesos / atención comercial — parcial, sin CRM acreditado | [ProcesoInterno](app/Models/ProcesoInterno.php), [ProcesoController](app/Http/Controllers/Admin/ProcesoController.php), notificaciones internas; `/admin/procesos`, `/admin/notificaciones`, PATCH de lectura | `procesos.ver/gestionar`; `AdminAuthorizationTest`, `AdminListFilterInputTest`, `AdminNotificacionTest`. Tareas internas con responsable/estado/prioridad; no enlazadas a un expediente comercial completo. Permisos `seguimientos.*` existen, sin CRUD de seguimiento dedicado en las rutas actuales |
| Cotizaciones — backend y panel privados locales, Sprints 19/19.1 | SolicitudCotizacion, CotizacionHistorial, GestionCotizaciones y CotizacionController; ocho endpoints, listado HTML/editor exclusivo en /admin/cotizaciones | cotizaciones.ver/crear/editar/gestionar y rol admin obligatorio; recibida → en_revision autorizado, snapshot de servicio, seguimiento, revisión/idempotencia y conciliación manual; sin presupuesto formal o recepción pública; aceptación PostgreSQL pendiente |
| Chatbot PHP — implementado y conservado | [AsistenteVirtualService](app/Services/AsistenteVirtualService.php), [ChatWidgetController](app/Http/Controllers/ChatWidgetController.php); POST `/chat/mensaje`, GET `/admin/asistente`, POST `/admin/asistente/mensaje` | Público con sesión/CSRF/límites; panel `asistente.ver`. `AsistenteVirtualTest`, `ChatbotHybridTest`, pruebas frontend. El permiso `asistente.entrenar` no acredita entrenamiento implementado |
| Horus AI Advisor — independiente; cliente Laravel preparado y apagado | [API Python](chatbot-python/app/api/routes.py), [cliente Laravel](app/Services/HorusAdvisorClient.php) | Token de servicio, no permisos de panel. Tests Python y `HorusAdvisorTest`/`AdvisorCatalogoTest`; sin sincronización operativa de fichas ni acceso a cotizaciones privadas |

Apoyos existentes: [auditoría](app/Http/Controllers/Admin/AuditoriaController.php), [configuración](app/Http/Controllers/Admin/ConfiguracionController.php), dashboard y búsqueda con permisos. Las notificaciones internas no son correos Resend. Las aprobaciones genéricas no permiten publicar un curso omitiendo `cursos.publicar`.

<a id="historial"></a>
## 6. Historial de sprints y fases

Resultados **históricos documentados**, no nuevas ejecuciones ni garantía acumulativa en producción. Algunas etapas se consolidaron después en Git; el orden de commits no equivale al de diseño. No hay informes independientes completos de Sprint 01 y 02: se enlaza la consolidación real, sin inventar documentos.

| Etapa | Resultado técnico y frontera conservada | Evidencia / resultado histórico |
| --- | --- | --- |
| 01 | Preselección de matrícula desde ficha de estudiante/curso; conserva autorizaciones | [Consolidación 01–07](docs/consolidacion-sprints-01-07.md), commit `4fda959`; sin cifra independiente atribuida |
| 02 | Coherencia de filtros, PDF por lote y alcance de exportación; agrupado con 03 | [Consolidación 01–07](docs/consolidacion-sprints-01-07.md), commit conjunto `f9ba081`; no separar cifras por suposición |
| 03 | Excel por lotes, celdas/temporales y reducción de memoria; corrige la idea de que `lazy()` basta | [Sprint 03](docs/sprint-03-backend-excel.md): 466 / 2316; memoria sigue dependiendo del libro |
| 04 | Chatbot híbrido local primero, FAQ y OpenAI selectivo; seguridad de enlaces/cliente | [Sprint 04](docs/sprint-04-chatbot-hibrido.md): 513 / 2499; 7 pruebas JS; sin llamadas reales acreditadas |
| 05 | Outbox transaccional para bienvenida de matrícula, cifrado, idempotencia y colas | [Sprint 05](docs/sprint-05-resend-matriculas.md): 537 / 2647; envío apagado |
| 06 | Robustez de operaciones, transacciones y regresiones | [Sprint 06](docs/sprint-06-robustez-backend.md): 557 / 2818 |
| 07 | Matriz de operaciones vigentes/históricas; corrige rechazo anticipado de cupos | [Sprint 07](docs/sprint-07-consistencia-matriculas.md): 617 / 3491 |
| 08 | Reglas completas de matrícula, elegibilidad, cupos y orden de bloqueo | [Sprint 08](docs/sprint-08-reglas-matriculas.md): 667 / 3925; concurrencia PostgreSQL pendiente |
| 09 | Ficha editorial separada, permiso, publicación/retiro, lectura pública autorizada | [Sprint 09](docs/sprint-09-publicacion-cursos.md): 702 / 4231; consumo dinámico apagado |
| 10 | Endurece captura/despacho Resend, recuperación de reservas y fallos | [Sprint 10](docs/sprint-10-preparacion-resend.md): 724 / 4445; sin activar transporte |
| 11 | Reduce consultas e hidratación innecesaria con mediciones | [Sprint 11](docs/sprint-11-rendimiento-backend.md): 729 / 4496; no extrapolar benchmarks |
| 12 | Recuperación de cuenta, seguridad y atomicidad de escrituras | [Sprint 12](docs/sprint-12-seguridad-robustez-backend.md): 756 / 4647; nivel 6 todavía pendiente entonces |
| 13 | Contratos de registro/normalización y tipos para Larastan 6 | [Sprint 13](docs/sprint-13-registro-larastan.md): 765 / 4761; registro no concede privilegios |
| 14 | Registro pendiente/verificación preparados, correcciones de sesión/recuperación | [Sprint 14](docs/sprint-14-verificacion-registro.md): 793 / 5024; flag y mailer apagados |
| 15 | Diagnóstico de integración real, esquema/permisos pendientes y plan supervisado | [Sprint 15](docs/sprint-15-preparacion-integracion.md); plan no equivale a autorización |
| 15-B | Retiene avisos anteriores a activación y agrega permisos faltantes con migración | [Sprint 15 §8](docs/sprint-15-preparacion-integracion.md): 809 / 5166; `held` no se libera al encender envío |
| Fase A PostgreSQL | Backup/restauración verificados y seis migraciones ensayadas sobre copia; habitual intacta | [Sprint 15 §10](docs/sprint-15-preparacion-integracion.md); no acredita Fase B ni concurrencia |
| PostgreSQL S1–S7 | Aprovisionamiento aislado; S3 reconciliado, S7 incompleto | [Procedimiento](docs/postgresql-l2.2-b-provisioning.md), [cierre S3](docs/postgresql-l2.2-b2-s3-cierre.md), [L2.3](docs/postgresql-l2.3-testing.md); detalle en §8 |
| Advisor fase 1 | FastAPI, conocimiento local, búsqueda y contratos; proyecto separado | [README Python](chatbot-python/README.md); no hay acta de fase 1 independiente en `docs/` |
| Advisor sprint/fase 2 | Léxico + semántico, contexto, recomendaciones explicables y evaluación sintética | [README Python](chatbot-python/README.md): reporta 144 básicas + 11 semánticas; no repetidas aquí |
| 16 | Cliente Laravel, exportador HMAC, minimización y fallback PHP | [Sprint 16](docs/sprint-16-horus-ai-advisor.md): 882 / 5531; Advisor apagado, ingestión activa pendiente |
| 17 | Endurece editor/retiros idempotentes, revisión opcional y contrato `paraWeb()` | [Sprint 17](docs/sprint-17-publicacion-cursos.md): 918 / 5765; catálogo web apagado |
| 17.1 | Acceso desde Panel → Cursos y formularios con revisión conservada frente a conflictos | [Sprint 17.1](docs/sprint-17-1-editor-panel.md): 931 / 5873; visual 390/768/1440 |
| 17.2 | Conecta ruta pública al controlador, fichas condicionales y fallos controlados sin rediseño | [Sprint 17.2](docs/sprint-17-2-catalogo-web.md): 951 / 6030; flag sigue apagado |

Complementos: [consolidación 10–14](docs/consolidacion-sprints-10-14.md), [restauración visual](docs/restauracion-web-publica.md). La restauración del diseño y las excepciones de 17.1/17.2 fueron autorizaciones acotadas; no autorizan nuevos cambios visuales.

<a id="git"></a>
## 7. Estado actual de Git

Inspección PostgreSQL del 29/10: rama `anderson`, HEAD arriba indicado; árbol e índice limpios al comenzar. Los sprints 16–17.2, Python, pruebas PostgreSQL y contexto ya están incorporados a los commits locales. La publicación anterior no autoriza nuevos commits o pushes en esta tarea.

- Dos stashes preservados: `stash@{0}` (`52e2f29`) y `stash@{1}` (`d69d6ca`). No se aplicaron ni modificaron.
- Esta inspección modifica únicamente este contexto y agrega `docs/postgresql-testing-validacion-final.md`; sin cambios funcionales o de infraestructura.
- No hubo `fetch` ni contacto con GitHub durante esta inspección; cualquier igualdad con el remoto debe verificarse de nuevo cuando corresponda.

<a id="bases"></a>
## 8. Estado de bases de datos

La inspección del 29/10 y R1 no abrieron sesiones SQL; comprobaron disponibilidad con `pg_isready`, servicios, archivos y control físico aislados. R1 inició únicamente el servicio Testing y lo dejó disponible. La existencia y estado interno de bases siguen sustentados en actas históricas. No deducir autorización SQL o de reparación de esta tabla.

| Entorno | Última evidencia documentada | Restricciones y pendientes |
| --- | --- | --- |
| Habitual `horus_group`, puerto 5432 | Fase A, 28/10: PostgreSQL 18.6, datos reales de desarrollo; origen preservado sin escrituras, 15 migraciones registradas | Las seis migraciones de la Fase A seguían sin aplicar al origen; `000022` suspendida. No usar para tests ni migraciones sin autorización específica. No se afirma que este estado siga idéntico hoy |
| Ensayo `horus_group_ensayo`, puerto 5432 | Fase A, 28/10: copia restaurada de backup y seis migraciones aplicadas; esquema/permisos y datos comparados, sin fichas ni avisos generados | Contiene datos reales copiados: **no es desechable ni base para pruebas destructivas**. Se conserva con el respaldo; no eliminar, truncar ni reutilizar sin permiso |
| Testing independiente, puerto 55433 | PG-TEST-02b/03, 29/10: clúster `7702263743609677164` en marcha (proceso de usuario, sin servicio), admin sellado; perfil local creado y `probe` de solo lectura aprobado para ambos runners; PG-TEST-05B-1: `horus_l2_test` migrada (26/26, lote 1), consentimiento retirado | Pruebas con escrituras y concurrencia requieren autorización expresa (PG-TEST-05B-2); `horus_l2_restore_test` sin usar; se detiene al cerrar sesión |
| Testing S7, puerto 55432 (ya no es destino del arnés) | R1 29/10: pasó de Stopped a Running/Manual; listener exclusivo 127.0.0.1:55432 del proceso hijo del servicio; clúster físico `7701505916098505688`, estado `in production`; perfil local ausente, aprovisionador activo, S7 incompleto | Se deja disponible por autorización expresa. Sin SQL ni prueba de contraseña actual. R2, perfil, HBA y pruebas con escrituras requieren alcance independiente; no repetir S1–S7 |
| SQLite `:memory:` | `phpunit.xml`; suite de Sprint 17.2 aprobada | Solo datos ficticios, sesión/caché `array`, cola `sync`, correo `array`; no prueba DDL, colación, locks ni concurrencia PostgreSQL |

### Fase A y migraciones en la copia

[Sprint 15 §10](docs/sprint-15-preparacion-integracion.md) acredita backup verificable, restauración con transacción, comparación de metadatos/huellas y aplicación individual de `000012`, `000013`, `000020`, `000021`, `000023`, `000024` en la copia. Ninguna produjo fichas ni envíos. `000022` quedó pendiente. La Fase B sobre la habitual no está acreditada como ejecutada. La ubicación privada del backup y sus contenidos no se reproducen aquí.

Riesgos operativos históricos: la cuenta de aplicación observada tenía privilegios excesivos y la instancia habitual escuchaba fuera de loopback; el backup contenía datos personales y no estaba cifrado, aunque tenía protección de permisos. Son hallazgos registrados, no mediciones nuevas ni autorización para cambiar cuentas, red o archivos.

### PostgreSQL Testing S1–S7

| Etapa | Propósito / evidencia disponible |
| --- | --- |
| S1 | Directorios y ACL; procedimiento registra incidentes y reconciliación. Completada según acta posterior |
| S2 | Servicio y cuenta dedicada, con ACL; completada según acta de cierre S3 |
| S3 | `initdb`; falso positivo de locale reconciliado. Cierre documental, sin presentar la lectura física como prueba SQL completa |
| S4 | Configuración de loopback, puerto 55432, SCRAM y HBA; L2.3 la inspeccionó posteriormente |
| S5 | Hubo arranque previo que permitió validación SQL de S6/S7; L2.3 encontró servicio detenido. R1 del 29/10 inició solo Testing tras verificar identidad y aislamiento, sin rehacer aprovisionamiento |
| S6 | Roles restringidos, bases `horus_l2_test` / `horus_l2_restore_test` y marcador independiente, recogidos por L2.3 |
| S7 | Validación administrativa correcta, fallo al autenticar runner, `S7Complete=false`; aprovisionador aún habilitado. No es una aceptación final |

El último informe atribuye el punto de parada a una credencial de runner que no coincide. Propone recuperación acotada, pero ninguna propuesta sustituye autorización. No repetir S1–S7 ni alterar DPAPI, HBA, ACL o evidencias para «arreglar» una suite.

La [inspección del 29/10 y R1](docs/postgresql-testing-validacion-final.md) confirma `failure.json` en `phase-P-runner-authentication`, sin cierre HBA ni sellado. El log histórico confirma ambos runners autenticados a las 20:12 del 27/10 y un rechazo de contraseña SCRAM del runner principal a las 23:02 bajo la regla HBA correcta. Causa probable: secreto introducido distinto del verificador; origen exacto y validez actual no demostrados. R1 completó un único arranque efectivo (29/10, 10:12:39 Lima), con recuperación automática y checkpoint tras cierre anterior no limpio. Configuración y `.env` conservan sus hashes; servicio cotidiano sin intervención. No hubo sesión SQL ni reparación de credenciales.

Las barreras pasan 96 pruebas/366 aserciones con dobles en la inspección anterior; no se repitieron en R1. Las suites existentes aplicarían `000022` mediante `RefreshDatabase`/`DatabaseTruncation`; su exclusión sigue siendo un requisito previo. `check` exige consentimiento y habilita escritura después de la sonda: no usarlo como preflight sin consentimiento. R2 propuesta: autenticación nativa de solo lectura con secreto conocido y reparación únicamente del rol que falle, si se autoriza expresamente; creación del perfil y cierre HBA por separado. No hay autorización de nuevas escrituras por dejar Testing disponible.

R2-A (29/10, alrededor de 10:21 Lima): identidad física `7701505916098505688`, servicio Running/Manual y listener único `127.0.0.1:55432` revalidados. El procedimiento anterior requiere contraseña interactiva `psql -W`, no capturada; sin credencial disponible en las fuentes seguras inspeccionadas y sin descifrar DPAPI. Ambos runners **no comprobados**, cero intentos y ninguna sesión SQL; versión SQL, privilegios y marcador siguen pendientes. Se solicita entrada local del propietario, nunca por chat. No hay evidencia nueva que justifique restablecer contraseñas ni ejecutar R2-B. Sin reparación, cambios de configuración/perfil, pruebas o reinicios. Véase la sección R2-A del informe.

R2-B preparación (29/10, alrededor de 10:25 Lima): propietario informa pérdida de contraseñas de ambos runners; no implica daño de roles. Identidad física/SCM/listener revalidados sin conectar. Se documenta renovación interactiva por rol mediante el aprovisionador y `\password`, pendiente de autorización nominal y disponibilidad de su contraseña conocida. HBA existente exige SCRAM; no hay acceso sin contraseña acreditado. Si también falta la del aprovisionador, detenerse y preparar otra intervención; no cambiar HBA ni probar alternativas. Ninguna contraseña cambiada, sesión SQL, reparación o prueba ejecutada. Procedimiento y texto de autorización propuestos en el informe; no completan S7.

R2-C planificación (29/10, alrededor de 10:30 Lima): propietario tampoco dispone de contraseña del aprovisionador. Identidad, cuenta/ACL Windows y SCM/listener revalidados sin SQL ni cambios de estado. Se compararon monousuario, HBA temporal y SSPI mediante documentación oficial. Ruta práctica propuesta: ventana HBA mínima bajo exclusividad local explícita, restitución binaria/ACL y SCRAM antes de renovar tres contraseñas con prompts psql; necesita autorización específica de stop/start, contingencia, backup y autenticación temporal. Trust en loopback no protege contra otros procesos locales. Alternativa monousuario evita esa ventana, pero requiere auxiliar de entrada oculta y cuenta/token verificados; no está lista para ejecutar. No se implementó ni ejecutó recuperación alguna. Informe contiene prerrequisitos, secuencia, restauración y límites; S7 sigue pendiente.

R2-C.1 preparación (29/10): propietario selecciona monousuario, sin trust ni cambios HBA. Identidad/SCM/listener revalidados; inspección de 1.615 elementos sin errores de ACL, con entrada FullControl del propietario y sin Deny/reparse observados. Token no elevado sin Administradores/Usuarios avanzados efectivos; candidato legítimo para backend offline, separado de la consola elevada de control SCM. Viabilidad estática favorable, ejecución no probada. Falta auxiliar revisado de secretos por stdin privado y política de logging: no se garantiza ausencia de exposición ni se presenta como listo. Se propone comprobar primero ese auxiliar con proceso simulado; posterior autorización independiente de parada limpia, backup, renovación transaccional nominal de tres roles y validación de lectura. No se implementó auxiliar, abrió SQL ni cambió estado/configuración/contraseñas. Testing continúa Running/Manual; S7 pendiente. Detalle y puntos de parada en la sección R2-C.1 del informe.

PG-TEST-01 (29/10, solo lectura): [clúster independiente](docs/postgresql-testing-cluster-independiente.md). Comprobado: una sola instalación PostgreSQL 18.6 (`initdb`, `pg_ctl`, `postgres`, `psql`, `pg_dump` 18.6), sin binarios en `PATH` ni variables `PG*`; cotidiano Running/Auto en `0.0.0.0`/`::`:5432 y S7 Running/Manual en `127.0.0.1:55432`, 20 procesos atribuidos por PID padre a ambos servicios; 55433 libre y fuera de exclusiones actuales, aunque dentro del rango dinámico; 259 GB libres en `C:`; token del operador no elevado; `%LOCALAPPDATA%\HorusPgTest55433` inexistente. **La guarda fija el puerto `55432` de forma literal**: un clúster en 55433 exige un cambio autorizado de `PostgresTestGuard` y fixtures. Propuesta (no ejecutada): clúster de usuario sin servicio, SCRAM, loopback, mismas bases/runners/marcador, propietario NOLOGIN, administrador sellado y reconstrucción en lugar de recuperación. Sin directorios, procesos, SQL, cambios de S7/HBA/credenciales ni de código.

PG-TEST-02a (29/10, autorizada, solo código de pruebas): la guarda admite **exclusivamente `127.0.0.1:55433`** (`PostgresTestGuard::HOST/PORT`) y rechaza expresamente 55432 de S7 en configuración, entorno e identidad del servidor; misma lista cerrada de bases/runners, consentimiento, marcador y privilegios mínimos. Fixtures, `ConcurrentTestSessionTest`, mensaje de `scripts/pgsql-test.php` y plantilla `.env.pgsql-testing.local.example` alineados. Validación con dobles/SQLite: guarda/seguridad/concurrencia **126/431**, suite completa **1096/7512**, Larastan 6, Pint acotado (finales LF) y diff aprobados; ninguna prueba PostgreSQL. **El clúster 55433 no existe y su creación (PG-TEST-02b) no está autorizada**; tampoco `.env.pgsql-testing.local`. Detalle en §13 del [informe](docs/postgresql-testing-cluster-independiente.md).

PG-TEST-02a.1 (29/10, cierre documental): `CLAUDE.md` §5 corregido a `127.0.0.1:55433` y referencias operativas de este contexto y de L2.3 actualizadas, sin alterar actas históricas. Política Git `core.autocrlf=true` sin `.gitattributes`, índice LF; solo `scripts/pgsql-test.php` y `PostgresTestGuardTest.php` normalizados de CRLF a LF. Pint aprobado en la copia de trabajo de los seis PHP; tres casos nuevos de inyección hacia 55432 (host, base, `PGPORT`) rechazados. Guarda/seguridad/concurrencia **129/434**, suite SQLite **1099/7515**, `git diff --check` aprobado. Clúster 55433 sigue sin crear ni autorizar. Detalle en §14 del informe.

PG-TEST-02b (29/10, autorizada y ejecutada): clúster independiente **`7702263743609677164`**, PostgreSQL 18.6, `postgres.exe` PID 11184 del usuario Windows actual sin servicio, datos en `%LOCALAPPDATA%\HorusPgTest55433\data`, escucha única `127.0.0.1:55433`, SCRAM, checksums, `log_statement=none`. Roles `horus_pgt_owner` (NOLOGIN), `horus_l2_test_runner` y `horus_l2_restore_test_runner` (sin atributos, NOINHERIT, SCRAM, 0 membresías); bases `horus_l2_test`/`horus_l2_restore_test` con marcador y permisos mínimos; `PUBLIC` sin `CONNECT` en `postgres`/plantillas. `horus_pgt_admin` sellado por HBA. Sonda de la guarda en psql correcta para ambos runners; HBA rechaza admin, accesos cruzados y usuarios ajenos; `::1` y las IPv4 de red no conectan. Un primer clúster con contraseña de admin no reproducible se destruyó con autorización expresa antes de recrearlo. S7 y el habitual sin cambios ni conexiones. **Sin `.env.pgsql-testing.local`, `check`, migraciones ni pruebas**: requieren autorización posterior. El servidor se detiene al cerrar sesión. Detalle en §15 del [informe](docs/postgresql-testing-cluster-independiente.md).

PG-TEST-03 (29/10, autorizada): `check` no podía usarse (exige consentimiento destructivo y habilita escritura tras la sonda). Se añadió el modo **`php scripts/pgsql-test.php probe`**, de solo lectura, sin consentimiento y con las demás guardas; se activa solo por indicador de contenedor del script, nunca ejecuta `SET default_transaction_read_only = off` y verifica `system_identifier` `7702263743609677164` y `cluster_name` `horus_pgt_55433`. `.env.pgsql-testing.local` creado (ignorado, ACL usuario+SYSTEM, consentimiento vacío, runner principal). `probe` aprobado para **ambos runners** en su base: puerto 55433, identificador correcto, `transaction_read_only=on`, `search_path=public`, `UTC`, marcador y privilegios mínimos. SQLite: 26 pruebas nuevas, selección **155/512**, suite **1125/7593**, Larastan 6, Pint y diff aprobados. Sin `check`, migraciones, pruebas destructivas ni escrituras. Detalle en §16 del informe.

PG-TEST-04 (29/10, endurecimiento y preparación, sin ejecución PostgreSQL): todas las rutas que pueden habilitar escrituras verifican ahora `system_identifier` `7702263743609677164` y `cluster_name` con la sesión en solo lectura, fallan cerrado si no pueden leerlo y no aceptan sustitución por entorno (11 pruebas nuevas). Auditoría de `000022`–`000028` documentada; **`000022` en `horus_l2_test` solo crea una tabla vacía: la verificación depende de `config('registro-verificacion.enabled') === true` (literal `false`) y ningún código consulta la tabla para activarse**. Bloqueo identificado para PG-TEST-05: `CotizacionTest`, `ServicioTecnologicoTest` y `CatalogoWebServiciosTest` exigen `sqlite` en `setUp()`. Matriz de diez etapas y procedimiento exacto en [PG-TEST-04](docs/postgresql-testing-pg-test-04.md); `horus_l2_restore_test` reservada para restauración. SQLite: selección **166/556**, suite **1136/7637**, Larastan 6, Pint y diff aprobados. Migraciones, consentimiento y pruebas con escrituras requieren autorización independiente.

PG-TEST-05A (29/10, autorizada, solo pruebas): `CotizacionTest`, `ServicioTecnologicoTest` y `CatalogoWebServiciosTest` ya no exigen `sqlite` además de `assertIsolatedTestDatabase()` (que conserva la exigencia de SQLite `:memory:` fuera del perfil). Las escrituras que deben fallar dentro de `RefreshDatabase` se aíslan con savepoint (`DB::transaction`), porque PostgreSQL aborta la transacción tras una violación. SQLite: clases 102/1161, suite **1136/7535** (exactamente 102 aserciones menos: la aserción redundante del driver), Larastan 6, Pint y diff aprobados. Sin consentimiento, migraciones ni conexiones con escritura. Procedimiento exacto de PG-TEST-05B en §10 de [PG-TEST-04](docs/postgresql-testing-pg-test-04.md).

PG-TEST-05B-1 (29/10, autorizada, solo migraciones): primera aplicación real en PostgreSQL 18.6 (`127.0.0.1:55433`, `horus_l2_test`, `horus_l2_test_runner`, identificador `7702263743609677164`). Diagnóstico previo en solo lectura sin consentimiento (`public` vacío, 26 pendientes); consentimiento activado solo en el perfil local, `check`, `migrate` (26/26, lote 1, sin errores, incluida `000022` únicamente en esta base) y consentimiento retirado de inmediato. Verificación en solo lectura: 31 tablas, 259 columnas, 75 índices válidos (54 únicos), 22 FK, 14 CHECK (10 propios + 4 de `enum`), 0 restricciones sin validar, sin triggers, funciones ni tipos propios; solo `migrations` (26) y `permissions` (15, guard `web`) tienen filas, sin roles ni concesiones, estudiantes, matrículas, servicios ni cotizaciones. Runner sin atributos elevados; esquema del marcador intacto. `probe` final aprobado. Sin pruebas funcionales, rollback, `migrate:fresh` ni seeders. Detalle en §11 de [PG-TEST-04](docs/postgresql-testing-pg-test-04.md); evidencia fuera del repositorio. PG-TEST-05B-2 (etapas 2-10) requiere autorización independiente.

PG-TEST-05B-2A (29/10, autorizada; **detenida en la etapa 1 de 7**): guardarraíles por etapa (identidad, consentimiento solo en el perfil, huella Git) correctos. Etapa 1: 61 pruebas, 229 aserciones, **1 fallo** y 24 «risky». Fallo: `AdminAuthorizationTest::test_exports_are_restricted_to_their_permissions` recibe 500 porque `MatriculasExcelExport` exige en PostgreSQL un snapshot `REPEATABLE READ` y `RefreshDatabase` envuelve la prueba en `READ COMMITTED`. Clasificado como interacción entre el arnés y una salvaguarda deliberada, no como defecto demostrado de la aplicación; la ruta real (nivel 0) sigue sin probarse en PostgreSQL. Las «risky» se atribuyen probablemente a `tests/bootstrap-pgsql.php` (manejadores globales retirados por Laravel). Consentimiento retirado, `probe` aprobado y Git sin cambios; etapas 2-7 no ejecutadas; sin cambios de código. Opciones de corrección y evidencia en el §12 de [PG-TEST-04](docs/postgresql-testing-pg-test-04.md).

Corrección del fallo de la etapa 1 (29/10, autorizada; solo pruebas, sin operaciones PostgreSQL): opción (a) con separación (c). `ReportExcelExportTest`, `ReportExcelScalabilityTest` y `AdminReportConsistencyTest` pasan de `RefreshDatabase` a `DatabaseMigrations` para que la exportación Excel abra su propio snapshot desde el nivel 0; `AdminAuthorizationTest` conserva solo los 403 y la descarga del coordinador se comprueba en `ReportExcelExportTest`, que añade una prueba del snapshot (`REPEATABLE READ, READ ONLY` solo verificable en PostgreSQL). `MatriculasExcelExport` y su salvaguarda no cambian. SQLite: suite completa 1138 pruebas / 7602 aserciones; Larastan y `git diff --check` limpios. Pendiente: reanudar la etapa 1 en `horus_l2_test` con nueva autorización (procedimiento en el §12).

PG-TEST-05B-2A, etapa 1, segunda ronda (29/10, autorizada): 137 pruebas, 666 aserciones; 136 aprobadas (99 con aviso «risky», sin suprimir) y **1 error**. Las exportaciones Excel pasan en PostgreSQL (descargas 200 y 403 según permiso, sin transacción envolvente); la prueba explícita del snapshot `REPEATABLE READ, READ ONLY` no entra en el filtro de la etapa y sigue sin ejecutarse. Error: `MatriculaIntegrityConstraintsTest::test_database_rejects_restoring_a_deleted_enrollment_over_an_active_one`. El índice sí rechaza la restauración, pero la consulta posterior falla con `25P02` (transacción abortada en PostgreSQL). Es una incompatibilidad de la prueba que se resolvería con un savepoint y no se ha corregido. Consentimiento vacío, `probe` OK, Git sin cambios durante la ejecución. Detalle en el §12 de [PG-TEST-04](docs/postgresql-testing-pg-test-04.md).

Correcciones posteriores (29/10, autorizadas; solo pruebas, herramientas y documentación; sin PostgreSQL):
- **Error de la segunda ronda:** savepoint en la prueba de `MatriculaIntegrityConstraintsTest`. Exige `23505` con `matriculas_vigente_unica` en `pgsql` y comprueba el estado final.
- **Prueba del snapshot:** ahora es exclusiva de PostgreSQL; SQLite la omite y deja de contar como evidencia. Una prueba separada comprueba en cualquier driver los niveles de transacción.
- **`--stop-on-error` en `scripts/pgsql-test.php`:** nueva clase `App\Support\Testing\PostgresTestArguments`. Pasa a PHPUnit 11.5.57 `--stop-on-error --stop-on-failure`, de modo que la ejecución se detiene en el primer error o fallo, no ante un «risky». Comprobado con casos simulados.
- **Validación en SQLite:** 1148 aprobadas y 1 omitida; Larastan, Pint y `git diff --check` OK.
- **PostgreSQL:** esas dos pruebas se ejecutaron después con autorización independiente; ver el párrafo siguiente.

PG-TEST-05B-2A, pruebas pendientes en PostgreSQL (29/10, autorizadas; dos ejecuciones aisladas en `horus_l2_test`, sin etapas 2-7, concurrencia ni suite completa):
- **`MatriculaIntegrityConstraintsTest::test_database_rejects_restoring_a_deleted_enrollment_over_an_active_one`:** aprobada, 7 aserciones (rama `pgsql`). El índice `matriculas_vigente_unica` rechaza la restauración con SQLSTATE `23505`. El savepoint se revierte sin cerrar la transacción de `RefreshDatabase`. Las consultas posteriores se ejecutan sin `25P02` y el estado final es correcto: 1 vigente y 2 en total.
- **`ReportExcelExportTest::test_excel_export_opens_its_own_snapshot_instead_of_joining_a_test_transaction`:** aprobada y ejecutada (no omitida), 15 aserciones. Se ejecuta `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY`. La lectura de matrículas ocurre en la transacción propia de la exportación (nivel 1, `repeatable read`, solo lectura). Al terminar, nivel 0 y valores de sesión por defecto restaurados. Hizo `migrate:fresh`/`migrate:rollback` solo en `horus_l2_test`.
- **Avisos:** 1 «risky» en cada prueba («removed error/exception handlers»), el mismo de las rondas anteriores. **No está resuelto ni suprimido.** 0 errores y 0 fallos.
- **Guardarraíles:** etapa 0 con consentimiento vacío y huella Git `A9D4127F9CDD0185` (HEAD `0388952`). Identidad `7702263743609677164` / `horus_pgt_55433` / 55433 / `horus_l2_test` comprobada antes y después de cada prueba. Consentimiento activo solo en el perfil local durante cada prueba y retirado después, con `probe` correcto. Huella Git y 2 stashes sin cambios; sin cambios de código.
- **Evidencia (fuera del repositorio):** `%LOCALAPPDATA%\HorusPgTest55433\evidencia\pgt05\b2a\pendiente1.txt` y `pendiente2.txt`.
- **Siguiente paso:** repetir la etapa 1 completa con la prueba de snapshot en el filtro. Requiere autorización independiente (procedimiento en el §12 de [PG-TEST-04](docs/postgresql-testing-pg-test-04.md)). **Cumplido el 30/10/2026** (`stage1-ronda8`, párrafo siguiente).

PG-TEST-05B-2A, etapa 1 revalidada (30/10, autorizada; **aprobada**; sin etapas 2-7, concurrencia ni suite completa): el lanzador protegido ejecutó las 116 entradas de la selección definitiva con `--stop-on-error --stop-on-failure` y PHPUnit informó `OK (116 tests, 525 assertions)`, en 02:40,650 min y 80 MB. Los resultados históricos del 29/10 eran 116 pruebas / 525 aserciones / 79 risky; ahora son 116 / 525 / **0 risky**, 0 errores, 0 fallos y 0 omitidas, coherente con la separación bootstrap/preflight (no se hizo un experimento aislado que demuestre la causa).
- **Snapshot Excel:** `ReportExcelExportTest::test_excel_export_opens_its_own_snapshot_instead_of_joining_a_test_transaction` estaba en la selección. En SQLite se omite, y la salida no registra ninguna omisión; ejecutó la rama `pgsql`. Sus aserciones exigen `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY`, nivel 1, `repeatable read`, solo lectura y nivel 0 con valores por defecto al terminar. El recuento de 15 aserciones es de la ejecución aislada del 29/10: no se midió por prueba en esta ejecución.
- **Integridad de matrículas:** 7 de `MatriculaIntegrityConstraintsTest` y 5 de `PostgresIntegrity\MatriculaConstraintsPostgresTest` (las cinco restricciones), todas aprobadas. La restauración sobre una vigente rechazada con `23505` usa savepoint y no deja la transacción abortada (`25P02`).
- **Migraciones:** seis entradas con `DatabaseMigrations` (4 de `AdminReportConsistencyTest` y 2 de `ReportExcelExportTest`) ejecutan `migrate:fresh` al empezar y `migrate:rollback` al terminar, solo en `horus_l2_test`; tres pruebas de migración de matrículas (aborta ante datos inválidos, informa violaciones, reversible) aprobadas. No se hicieron migraciones manuales. El esquema final de `horus_l2_test` no se inspeccionó después.
- **Guardarraíles:** identidad, consentimiento vacío y huella Git `15320FD18F8C064B31FDB6BE67F7CBB989F39453EE62F81876F0ABAC8A6795C1` (HEAD `0388952`) correctos antes y después; consentimiento activo solo en el perfil local durante la etapa y retirado después con el perfil idéntico (`42372EC6…`); raíz y descendientes cerrados con Job `Active=0`; `probe` e identidad finales aprobados.
- **Evidencia (fuera del repositorio):** `execution\stage1-ronda8` de la cápsula `pgt05-consolidacion-ready` y `%LOCALAPPDATA%\HorusPgTest55433\evidencia\pgt05\b2a\etapa1-pgt05-consolidacion-ronda8*`. Manifiesto `sha256.json` (56 entradas, todas coinciden), SHA-256 `1794C61092DD9E52C8F6187546A3571BB77D5A647B4B0768333A39BFAC7E4A55`. Hashes, límites y diferencias de registro en [PG-TEST-04](docs/postgresql-testing-pg-test-04.md#etapa-1-revalidada-stage1-ronda8-30102026).
- **Límites:** la salida de PHPUnit solo muestra puntos de progreso, sin nombres ni aserciones por prueba; la evidencia por prueba procede de la selección, del resultado `OK` sin omitidas/risky y del código de las pruebas. La línea final del envoltorio «(0 pass; 1 failure; 2 error)» es una leyenda fija de códigos de salida, no un recuento. Entonces seguían pendientes las etapas 2–7, la concurrencia, la suite completa y la restauración; la etapa 2 se ejecutó después (párrafo siguiente).

PG-TEST-05B-2A, etapa 2 ejecutada (30/10, autorizada; **aprobada**; sin etapas 3-7, concurrencia ni suite completa): el lanzador oficial de la cápsula `pgt05b2a-etapa2-capsule` ejecutó las 48 entradas (31 métodos): `AdminCursoTest` 7, `AdminEstudianteTest` 6 y `CursoPublicacionTest` 35 (21 de ellas, entradas de datasets), con `scripts/pgsql-test.php test --filter=… --stop-on-error` (el lanzador de pruebas lo expande a `--stop-on-error --stop-on-failure`) y PHPUnit informó `OK (48 tests, 410 assertions)`, en 01:32,478 min y 66 MB. La etapa 2 no había tenido ejecución PostgreSQL previa.
- **Migraciones y esquema:** las tres clases usan solo `RefreshDatabase`: `migrate:fresh` (`db:wipe` + las 26 migraciones) corrió una vez, al empezar, sobre el esquema `public` de `horus_l2_test`, con la autorización destructiva expresa del propietario. El único DDL explícito es `down()`/`up()` de `2026_10_28_000021` en `CursoPublicacionTest` (transaccional, revertido con la prueba). **No se registró cada migración ni se inspeccionó el esquema final:** la salida de PHPUnit no las lista y los procedimientos autorizados (`pg_isready`, identidad, `probe`) no consultan tablas.
- **Guardarraíles:** huella Git `FA02004B23F0B2EAC42C75A77098AF25218F4D8E8E8C083F0CF206E9655E31F0` (HEAD `0388952`) y consentimiento vacío antes y después; preflight del mismo día (`pg_isready`, identidad de nueve campos, `probe` y enumeración con `--bootstrap vendor/autoload.php`, que reprodujo los 48 nombres en el mismo orden); consentimiento activo solo en el perfil local durante la etapa y retirado con el perfil idéntico (`42372EC6…`); raíz y descendientes cerrados con Job `Active=0` en los tres hijos; `probe` e identidad finales aprobados; 18 evidencias históricas intactas.
- **Plazos añadidos antes de ejecutar:** guardianes 180 s, etapa 720 s (tope heredado de PHP: 900 s), código de salida 17 = TIMEOUT con prioridad de los códigos de limpieza 10-16; probado de extremo a extremo con repositorio, perfil y procesos ficticios (43 comprobaciones). No se agotó ningún plazo.
- **Evidencia (fuera del repositorio):** carpeta `execution-etapa2-ronda1` de la cápsula y `%LOCALAPPDATA%\HorusPgTest55433\evidencia\pgt05\b2a\etapa2-pgt05-estudiantes-cursos-ronda1*`. Manifiesto `sha256.json` (52 entradas, todas coinciden) SHA-256 `975759EA90595D42261E995188B353C1A0F40F004AF9ADACD608AC2B31479519`. Cápsula: manifiesto `B5235D0D7D0C2730EA8BE4B7B4563252B3A9A486EF31841A678AE078A4F01C4E` (24 archivos), lanzador `427BA953DC73D76A7DADDFDF608562FD687C2AC1B4A9324EC83AC1E2954E07FA`, release `CEBA0E9843D73F2648D63E7BE7115B1EB7C09BA5F9FD2A92E2993A13505B8B4E`. La cápsula está en una carpeta temporal de sesión: conviene copiarla, sin modificarla, a una ubicación durable. Hashes y límites en [PG-TEST-04](docs/postgresql-testing-pg-test-04.md#etapa-2-ejecutada-stage2-ronda1-30102026).
- **Límites:** no hay desglose de aserciones por clase; no se registró la ejecución individual de las 26 migraciones; no se inspeccionó el esquema final; `launcher-stage2.exit` quedó vacío por un defecto del auxiliar CMD (`echo %ERRORLEVEL%>`), aunque el código 0 consta en la salida del lanzador y en los 11 pasos nativos. La ejecución la lanzó `cmd.exe` iniciado por el agente (no una ventana abierta por el propietario). Es una única ejecución: no mide intermitencia. Como cualquier edición de archivos del repositorio cambia la huella Git, la línea base `FA02004B…` quedó **obsoleta** para la etapa 3 y debe recalcularse.

Guardarraíles: [PostgresTestGuard](app/Support/Testing/PostgresTestGuard.php), [configuración aislada](config/pgsql-testing-database.php), [runner](scripts/pgsql-test.php), [phpunit.pgsql.xml](phpunit.pgsql.xml), [L2.2-A](docs/postgresql-l2.2-a.md) y [L2.3](docs/postgresql-l2.3-testing.md). Exigen perfil explícito, consentimiento independiente, IP/puerto/base/runner de lista fija y sonda de identidad/privilegios/marcador antes de habilitar escrituras. Rechazan habitual, ensayo, conexiones alternativas y configuraciones inseguras. La preparación de esas guardas no acredita su ejecución destructiva contra PostgreSQL.

<a id="migraciones"></a>
## 9. Migraciones importantes

Estado operativo conocido: las seis filas distintas de `000022` se ensayaron en PostgreSQL **solo en la copia de Fase A**; en la habitual seguían pendientes según esa misma acta. Las suites SQLite posteriores no modifican esa situación. No se consultó `migrations` ahora.

| Migración real | Propósito y dependencias | Estado / precaución |
| --- | --- | --- |
| [000012](database/migrations/2026_10_27_000012_add_matriculas_exportar_eliminar_permissions.php) | Agrega `matriculas.exportar/eliminar`, solo admin; depende de Spatie/roles existentes | Aditiva; no resincroniza la matriz completa |
| [000013](database/migrations/2026_10_27_000013_add_matriculas_integrity_constraints.php) | Índice parcial único de matrícula vigente y tres CHECK de cupos/importes; requiere cursos/matrículas y datos compatibles | Preflight debe abortar ante inconsistencias; CHECK se agrega en PostgreSQL, no se simula su DDL con SQLite |
| [000020](database/migrations/2026_10_28_000020_create_matricula_notifications_table.php) | Outbox de avisos con FK a matrículas, unicidad de matrícula/clave de entrega | No genera avisos retroactivos; el observer necesita esta tabla incluso con envío apagado |
| [000021](database/migrations/2026_10_28_000021_create_curso_publicaciones_table.php) | Fichas, FK a cursos/usuarios, permiso `cursos.publicar` para admin | No crea/publica fichas automáticamente. `--pretend` presentó una limitación al necesitar el ID del permiso; no confundir con migración real fallida |
| [000022](database/migrations/2026_10_28_000022_create_registros_pendientes_table.php) | Registros pendientes y soporte de verificación | **Suspendida** en la habitual y excluida de Fase A. Solo aparece aplicada en esquemas SQLite de pruebas; cualquier uso en Testing real necesita autorización de ese perfil |
| [000023](database/migrations/2026_10_28_000023_hold_matricula_notifications_backlog.php) | Pasa avisos previos `pending` sin intentos a `held`; depende de **000020** | No libera avisos ni reenvía; `down()` no revierte la retención |
| [000024](database/migrations/2026_10_28_000024_add_aprobaciones_respaldo_permissions.php) | Cuatro permisos de Aprobaciones/Respaldo; depende de Spatie | Aditiva y conserva asignaciones. Ver/gestionar según rol; exportar respaldo solo admin |

No ejecutar `migrate` general: arrastraría `000022`. Un plan futuro debe revisar destinos y dependencias, backup, ventana y autorizaciones por separado. No se incluye aquí una receta ejecutable. Documentación: [preparación y ensayo](docs/sprint-15-preparacion-integracion.md), [integridad](docs/postgresql-l2.2-c-integridad.md).

Consecuencia funcional documentada cuando falta `000020`: el alta de matrícula puede fallar y revertirse al capturar el aviso, aunque el transporte esté apagado. Cuando faltan permisos de `000024`, Aprobaciones/Respaldo pueden responder 403. No resolverlo relajando permisos ni activando servicios; verificar el esquema solo cuando se autorice.

<a id="chatbot-php"></a>
## 10. Chatbot PHP existente

El motor [AsistenteVirtualService](app/Services/AsistenteVirtualService.php) utiliza FAQ/intenciones, reconocimiento local y conocimiento institucional de [ChatKnowledge](app/Support/ChatKnowledge.php) y [ChatIntentMatcher](app/Support/ChatIntentMatcher.php). Conserva el contrato público `respuesta`, `intent`, `fuente`. Los diagnósticos y datos del panel no deben convertirse en conocimiento público.

Resuelve localmente cuando hay evidencia; OpenAI es selectivo y requiere configuración. No inventar precios, disponibilidad, fechas, políticas o condiciones. [Sprint 04](docs/sprint-04-chatbot-hibrido.md) describe fuentes, métricas y abstención. La integración nueva no elimina este motor: es el flujo normal con Advisor apagado y el fallback cuando la consulta opcional no produce una respuesta válida.

Ruta pública POST `/chat/mensaje`: sesión, CSRF, throttle 30/minuto, mensaje hasta 2000 caracteres y `session_id` hasta 64. Configuración [chatbot.php](config/chatbot.php): ventana de duplicados 60 s, contexto 900 s, presupuesto OpenAI por minuto/sesión y límites de contexto/respuesta. Los metadatos de conversación no autorizan transmitir registros completos ni datos personales a otros servicios.

El catálogo del chatbot (`chatbot.public_catalog_enabled=false`) es distinto del catálogo web. Su lector heredado `vigentes()` conserva su contrato; `paraWeb()` añade comprobación de procedencia editorial vigente. No intercambiar estos métodos ni activar flags para hacer pasar pruebas.

<a id="advisor"></a>
## 11. Horus AI Advisor

Proyecto independiente en [chatbot-python/README.md](chatbot-python/README.md), versión declarada `0.1.0`. API FastAPI local predeterminada `127.0.0.1:8001`, conocimiento JSON verificado, búsqueda léxica y búsqueda semántica opcional en CPU. Modelo multilingüe preentrenado: inferencia y embeddings, **sin entrenamiento desde cero**.

Capacidades implementadas: comprensión de preguntas en español, contexto reciente limitado, cambio de tema, recomendaciones con razones/evidencia/fuentes, aclaración y abstención; orientación académica, servicios y preparación informativa de una solicitud de cotización. No calcula ni consulta presupuestos privados, estudiantes, matrículas o inventarios reales.

### Endpoints y contratos

Fuente: [routes.py](chatbot-python/app/api/routes.py), [esquemas chat](chatbot-python/app/schemas/chat.py), [configuración](chatbot-python/app/core/config.py) y [seguridad](chatbot-python/app/core/security.py).

| Método y ruta | Acceso / significado |
| --- | --- |
| GET `/health` | Público, estado mínimo; no acredita preparación para responder |
| GET `/ready` | Público; estados de conocimiento, semántica, autenticación y catálogo. Revisar cuerpo, no solo HTTP 200 |
| GET `/api/v1/info` | Token; versión, capacidades, búsqueda y límites |
| POST `/api/v1/chat` | Token; `message`, historial y sesión opcionales según esquema; respuesta estructurada con fuentes/recomendaciones |
| POST `/api/v1/recommend` | Token; solicitud estructurada de orientación según Pydantic; no crea inscripción ni cotización |
| POST `/api/v1/catalog/validate` | Token; validación **en seco** de estructura/firma/privacidad; no carga ni persiste el catálogo |

Las rutas privadas exigen `X-Horus-Service-Token`, secreto de al menos 32 caracteres configurado en servidor. No publicar valores ni moverlo al navegador. Los esquemas rechazan campos extra. Predeterminados: mensaje 2000 caracteres, historial máximo 10 elementos, cuerpo normal 32 KiB, validación de catálogo hasta 1 MiB, concurrencia 4 y timeout interno 5 s. Son configurables dentro de los límites de los esquemas; no equivalen al presupuesto más corto de Laravel.

Catálogo firmado: [schemas/catalog.py](chatbot-python/app/schemas/catalog.py) y [services/catalog.py](chatbot-python/app/services/catalog.py). Versión `1.0`, origen `horus-laravel`, ID de versión, generación/caducidad, hasta 500 ítems con identidad estable, estado y campos públicos; campos extra prohibidos. El contrato admite estados como `vigente`, `no_publicado` y `retirado`; no todos son recomendables. Revisa el enum completo antes de generar nuevos estados.

Hay carga opcional desde archivo al **arranque**, mediante `catalog_path`; no hay endpoint de ingestión/recarga activa. La transformación de registros y caducidad se evalúa con el instante de carga y el estado del catálogo queda asociado al conocimiento cargado. La documentación Python describe caducidad y Laravel futuro en términos más amplios: no acredita invalidación dinámica durante un proceso largo. Además, Python usa fecha UTC para esa transformación y el editor usa Lima; requiere conciliación antes de una sincronización operativa.

### Evaluaciones y memoria

El README reporta 144 pruebas básicas y 11 semánticas, 195 consultas y 12 conversaciones **sintéticas**, comportamiento híbrido en test de 84,4 % y robustez de 160 solicitudes con ocho simultáneas. Son informes locales, no una repetición en esta tarea ni evaluación independiente con usuarios reales. El propio informe reconoce una corrección descubierta en test que hace optimista parte de esa cifra. La ausencia de invenciones reportada en ese corpus no garantiza ausencia en producción.

Memoria reportada: modo léxico alrededor de 50 MB de conjunto de trabajo; híbrido con pico aproximado de 1,0–1,1 GB y 1,85–1,9 GB privados. No confundir métricas ni reutilizar la estimación antigua de 228 MB. No se cargó el modelo ni se midió memoria aquí. El coste justifica timeout corto, fallback y activación gradual.

<a id="integracion"></a>
## 12. Integración Laravel–FastAPI

El [Sprint 16](docs/sprint-16-horus-ai-advisor.md) incorporó [HorusAdvisorClient](app/Services/HorusAdvisorClient.php), [ExportadorCatalogoAdvisor](app/Services/ExportadorCatalogoAdvisor.php), [FirmaCatalogoAdvisor](app/Services/FirmaCatalogoAdvisor.php) y [configuración](config/horus-advisor.php). `horus-advisor.enabled=false` literal: con flag apagado no exige Python ni genera solicitudes al servicio.

- El cliente usa HTTP Laravel; solo permite URL HTTP de IP literal `127.0.0.1` y puerto válido, sin DNS, redirecciones ni destinos externos. Desactiva proxy/cookies y no aplica reintentos automáticos.
- Conexión predeterminada 0,4 s; presupuesto total compartido `/ready` + chat de 2,5 s, acotado a 3 s. Respuesta hasta 64 KiB y validación estructural/fuentes antes de adaptarla.
- Solo se consulta en el contexto público previsto, para intenciones admitidas. Laravel construye preguntas fijas por tema: **no envía el mensaje libre original, historial, sesión ni registros privados**. La capacidad conversacional Python no implica que Laravel ya la utilice.
- Red caída, timeout, autorización fallida, errores HTTP, JSON inválido, vacío o contrato incompatible producen fallback PHP, sin error técnico al visitante ni duplicación innecesaria de OpenAI o acciones comerciales.
- Por seguridad de invalidación, el cliente actual acepta orientación solo cuando `/ready` indica catálogo `none`; rechaza también catálogos cargados, obsoletos o fallidos. No elimina esta barrera para probar la exportación.

El exportador exige actor con `cursos.publicar` y ficha vigente con autor que conserve permiso. Construye un snapshot en memoria; no publica ni transmite por sí mismo. Máximo 500 ítems/1 MiB; falla antes de exportar parcialmente un catálogo excesivo. Usa campos públicos permitidos y filtros adicionales de privacidad; nunca estudiantes, matrículas, clientes o cotizaciones.

Firma exacta: HMAC-SHA256 sobre UTF-8, JSON compacto, claves de objetos ordenadas recursivamente, Unicode y barras sin escapes innecesarios, listas en orden. Campos firmados: `schema_version`, `catalog_version`, `source_system`, `generated_at`, `expires_at`, `items`; `signature` queda fuera del payload y tiene prefijo `hmac-sha256:`. Clave externa, mínima de 32 caracteres; nunca hardcodear valores. El snapshot Laravel dura como máximo cinco minutos y se acorta al límite de vigencia local, aunque el esquema Python admita hasta 90 días.

**Pendiente:** distribución, carga efectiva, renovación, reconciliación de fecha/zona y retirada/invalidez segura en el motor activo. Un TTL firmado no invalida por sí solo registros ya materializados al arranque. `/catalog/validate` devuelve validación, no sincronización. No hay catálogo sincronizado en producción ni autorización para activar Advisor.

<a id="publicacion"></a>
## 13. Publicación administrativa y web

Secuencia de reutilización: Sprint 09 crea base editorial; 17 refuerza backend y lectura; 17.1 conecta el editor al panel; 17.2 conecta la página pública condicional. No crear un segundo editor ni copiar automáticamente datos administrativos.

Acceso Panel → Cursos: acción de publicación visible con `cursos.publicar`, comprobado también en servidor. Admin dispone de ese permiso por defecto; coordinador/secretaría necesitan concesión explícita y mantienen los requisitos del grupo administrativo. GET/DELETE admiten curso eliminado para consultar/retirar; PUT no publica cursos eliminados o inactivos.

El GET editorial entrega ETag fuerte. PUT/DELETE aceptan precondición `If-Match`; los formularios envían `_revision`. Revisión desactualizada: JSON/cabecera recibe 412; formulario recibe error de validación con borrador y revisión antigua conservados y acción explícita «Cargar versión actual». No se sobrescribe silenciosamente ni se actualiza el token para reenviar sin revisar. Formatos ambiguos/invalidos se rechazan. Clientes antiguos **sin** precondición siguen admitidos por compatibilidad: no afirmar protección obligatoria universal. Publicar/retirar repetidamente no duplica cambios ni auditoría cuando es la misma operación.

[CatalogoPublicoCursos::paraWeb()](app/Services/CatalogoPublicoCursos.php) devuelve `version`, `habilitado`, `total`, `cursos`. Proyecta siete campos: título, resumen, modalidad, horario, texto de inscripción y fechas de vigencia. Exige publicación explícita, autorización existente/no futura, autor actualmente autorizado, curso activo no eliminado y fechas vigentes inclusivas en Lima. Retiro/caducidad se releen por petición; no hay snapshot web retenido.

`catalogo-publico.enabled=false` conserva el contenido original y evita consultar esquema/catálogo. Encendido solo en pruebas aisladas: [controlador público](app/Http/Controllers/CursosPublicosController.php) y [vista](resources/views/pages/educacion/cursos.blade.php) añaden «CURSOS PUBLICADOS» después de las tarjetas de modalidades originales, con sus mismas clases. No son cursos estáticos duplicados. No inventa imágenes, precios, duración, cupos ni certificados. `inscripcion` es texto escapado, no enlace ni autorización de matrícula; la consulta usa `/contactos`.

Vacío: aviso sin cursos inventados. Esquema incompleto o `QueryException`: contenido estático y HTTP 200 controlado, con código técnico seguro en logs, sin SQL/credenciales/datos. Fichas heredadas inválidas se omiten. La presentación se limita a 50, con total/rango/orden visibles; el servicio aún obtiene todos los resultados válidos en una consulta, por lo que falta paginación SQL para gran volumen. Sin N+1 en la regresión medida. Respuesta `no-store, private`.

Requiere `000021` en el destino y revisión independiente del contenido antes de activar. No hay prueba de publicación real ni activación por detectar tablas. Referencias: [Sprint 09](docs/sprint-09-publicacion-cursos.md), [17](docs/sprint-17-publicacion-cursos.md), [17.1](docs/sprint-17-1-editor-panel.md), [17.2](docs/sprint-17-2-catalogo-web.md).

<a id="comercial"></a>
## 14. Servicios y cotizaciones

Servicios tecnológicos públicos reales: cableado estructurado, cámaras de seguridad, soporte y mantenimiento, en las rutas `/tecnologias/cableado-estructurado`, `/tecnologias/camaras-seguridad`, `/tecnologias/soporte-mantenimiento`. Son vistas fijas en [pages/tecnologias](resources/views/pages/tecnologias), con contenido/imágenes existentes. Asesoramiento y capacitaciones también son páginas públicas, no módulos editoriales completos.

**Inventario anterior al Sprint 18 (histórico):** No se encontró modelo/migración/CRUD editorial de servicios ni integración panel→web equivalente a cursos. Un Sprint 18 deberá acordar campos, estados, autorización, almacenamiento de imágenes y compatibilidad con el diseño antes de construirlo. No convertir las páginas fijas automáticamente en registros.

Atención actual: contacto público, orientación del chatbot, tareas internas (`ProcesoInterno`), aprobaciones y notificaciones genéricas. La presencia de permisos `seguimientos.*` no demuestra un centro de consultas/seguimiento implementado. El formulario visible de contacto tampoco acredita un backend de leads: las rutas actuales no exponen un POST dedicado de recepción comercial.

**Inventario previo al Sprint 19 (histórico):** No se encontró módulo específico de cotizaciones. Un Sprint 19 propuesto deberá separar solicitud pública, gestión privada, estados/aceptación, importes, permisos, auditoría e idempotencia; definir efectos secundarios y tratamiento de resultados inciertos antes de envíos/reintentos. No hay fechas aprobadas ni autorización de implementación en este documento.

Contenido público autorizado y datos privados comerciales tienen ciclos de vida diferentes. No publicar clientes, documentos, presupuestos internos o precios calculados en catálogos ni enviarlos a Python. Advisor puede orientar sobre cómo solicitar una cotización sin acceder al expediente.

**Sprint 18, backend local del 29/10/2026:** [gestión de servicios tecnológicos](docs/sprint-18-servicios-tecnologicos.md). Nuevos `ServicioTecnologico`/`ServicioPublicacion`, gestión JSON autenticada en `/admin/servicios`, permisos `servicios.ver|crear|editar|publicar` (solo admin por defecto), borrador/autorización/retiro y auditoría transaccional. Revisión monotónica con `If-Match` obligatorio (428 ausente, 412 obsoleto); editar contenido publicado exige retiro previo. Inactivar/archivar retira, reactivar no republica. UUID estable y código único inmutables en API; sin borrado HTTP ni registros importados. Migraciones nuevas `000025`/`000026` únicamente en código y SQLite de pruebas, **pendientes de aceptación PostgreSQL y autorización antes de ejecución real**.

`CatalogoPublicoServicios::pagina()` proyecta siete campos públicos con autor/permiso/rol y vigencia actuales; COUNT + LIMIT/OFFSET SQL, máximo 50. `catalogo-servicios.enabled=false` literal, independiente de cursos/chatbot/Advisor; sin ruta ni consumidor público, sin modificación del panel visual o las páginas fijas. Cotizaciones solo tienen contrato futuro documentado de identidad estable y snapshot histórico privado; no hay tablas comerciales nuevas. Frontend, cursos, chatbot PHP/Python y activaciones permanecen intactos. La reautorización no serializa revocaciones Spatie posteriores; SQLite no acredita contención/DDL/rendimiento PostgreSQL. El estado y pendientes de PostgreSQL Testing/S7 y `000022` se conservan sin intervención.

Validación Sprint 18: **30 pruebas específicas/407 aserciones; suite completa 981/6439** con SQLite `:memory:`; Larastan nivel 6 sin errores, Pint sobre los 14 PHP propios y `git diff --check` aprobados. No se ejecutaron frontend ni PostgreSQL/proveedores reales. Se preservaron los dos cambios documentales preexistentes y 377 archivos protegidos inventariados; sin commits/push ni cambios de stashes. Evidencia y contratos completos en el informe del sprint; aceptación PostgreSQL, migraciones reales, integración visual y activación controlada continúan pendientes.

**Sprint 18.1, panel local del 29/10/2026:** [integración administrativa de servicios](docs/sprint-18-1-panel-servicios.md), bajo autorización frontend específica. GET `/admin/servicios` negocia listado/editor HTML; los clientes JSON conservan estructura y enlaces originales. Navegación condicionada por `servicios.ver`, formularios según crear/editar/publicar, módulo JS/Vite exclusivo y dos vistas nuevas, sin cambios a páginas públicas, CSS global, cursos o chatbot. Reutiliza las escrituras del Sprint 18 con CSRF/If-Match; conflicto conserva texto/revisión, exige comparación y adopción explícita antes de guardar, sin reintentos automáticos. Respuestas inciertas bloquean reenvío; el borrador solo vive en memoria de la página. El listado avisa cuando sus filtros/recuentos requieren actualización tras una operación.

Validación 18.1: **35 pruebas PHP específicas/466 aserciones; suite completa 986/6498** en SQLite `:memory:`, **18 pruebas frontend**, build, Larastan 6 y Pint acotado aprobados. Smoke Edge con listado/editor a **390/768/1440 px**, teclado, creación/edición/publicación/retiro, conflicto y rechazo 403; fixture exclusiva `127.0.0.1:8773`, memoria reconstruida por petición, sin persistencia ni evidencia de concurrencia PostgreSQL. Catálogo de servicios apagado, sin registros reales, migraciones reales, intervención PostgreSQL/S7/000022 o proveedores. Continúan pendientes aceptación PostgreSQL, autorización operativa y conexión visual independiente a la web pública. Se conserva la documentación PostgreSQL previa; sin commits, push ni cambios de stashes.

**Sprint 18.2, conexión web local del 29/10/2026:** [catálogo de servicios en la web](docs/sprint-18-2-servicios-web.md). El GET/HEAD existente `/tecnologias/soporte-mantenimiento` (`tecnologias.soporte`) usa `ServiciosPublicosController` y añade una sección editorial independiente al final de la vista, conservando tarjetas, imágenes, navegación y estilos originales. Cableado y cámaras permanecen intactos; la categoría libre no se interpreta como una taxonomía para asignar fichas a páginas. El lector del Sprint 18 no cambia: COUNT + LIMIT/OFFSET SQL, 12 fichas por página web, orden estable y metadatos visibles. Solo título/resumen/descripción/categoría editoriales escapados; contacto existente, sin flujo de cotizaciones ni campos administrativos.

`catalogo-servicios.enabled=false` sigue intacto: sin sección, lectura o introspección del esquema nuevo cuando está apagado. Encendido solo en pruebas: excluye estados/autores no elegibles; vacío e infraestructura incompleta tienen respuesta pública controlada y logs de códigos sin datos/SQL. Captura únicamente excepciones SQL o de provisión conocidas; no oculta errores de programación. Respuesta `no-store, private`, sin escrituras públicas. Smoke Edge: **15 casos en 390/768/1440 px**, comparaciones de HTML/geometría originales, textos largos, enlaces, foco/Tab y cero excepciones JS/destinos externos; fixture exclusiva `127.0.0.1:8774`, SQLite en memoria reconstruida por petición. Selección PHP servicios/panel/cursos: **80 aprobadas, 866 aserciones**; 18 pruebas frontend, build, Larastan 6 y Pint acotado aprobados. Suite completa SQLite: **1011 aprobadas, 6741 aserciones**. No datos reales, migraciones reales, interacción PostgreSQL/S7/000022, proveedores, commits/push o cambios de stashes. Continúan pendientes aceptación PostgreSQL, autorización de migraciones concretas y activación operativa separada; Sprint 19 conserva el contrato de UUID estable/snapshot histórico privado, sin módulo comercial nuevo.

**Sprint 19, backend privado local del 29/10/2026:** [solicitudes de cotización](docs/sprint-19-cotizaciones.md), sobre `anderson` / `038895280000f57d39c698e40d3436fecc0d716f`. Se reutilizan sesión/rol/CSRF, permisos Spatie y auditoría; nuevos expediente e historial privados, API JSON sin frontend, contacto mínimo, necesidad, responsable y seguimiento. Servicio opcional con snapshot del nombre administrativo y UUID estable; asociación histórica no sustituible y FK RESTRICT, sin efectos por retiro/archivo. El propietario autorizó solo `recibida → en_revision`; no hay cotizada, aceptada, rechazada, cierres, precios o aprobaciones comerciales.

Permisos `cotizaciones.ver|crear|editar|gestionar`, solo admin por defecto, con constante y migración aditiva `000028`; esquema `000027` únicamente probado en SQLite. Escrituras transaccionales con reautorización, bloqueo, ETag/If-Match, historia y auditoría atómicas minimizadas. Claves UUID idempotentes por operador/operación en creación y seguimiento; reserva durante vida del expediente, huella HMAC, sin duplicar por contacto. Mutaciones devuelven recibo mínimo sin conceder lectura; GET no escribe. Retención personal, recepción/consentimiento/antispam públicos, UI, estados comerciales y operación real requieren decisiones posteriores. Catálogos/Resend/verificación/Advisor siguen apagados, sin cambios frontend/Python/cursos/servicios anteriores, PostgreSQL/S7/000022, índice, commits/push o stashes. Se preservan íntegros los documentos PostgreSQL preexistentes; SQLite no valida concurrencia/DDL PostgreSQL.

Validación Sprint 19: **46 pruebas específicas/540 aserciones; suite completa SQLite 1057/7283** (407,62 s); Larastan nivel 6 sin errores, Pint acotado a 11 PHP y diff aprobados. Sin pruebas frontend por ausencia de cambios visuales; evidencia y contratos en el informe. No acredita aceptación PostgreSQL ni uso con datos reales.

**Sprint 19.1, panel privado local del 29/10/2026:** [interfaz de solicitudes de cotización](docs/sprint-19-1-panel-cotizaciones.md), sobre `anderson` / `038895280000f57d39c698e40d3436fecc0d716f`, con autorización frontend específica. Listado HTML en el GET existente, detalle/editor integrado e historial paginado; JSON y ocho rutas previas conservados. Navegación con `cotizaciones.ver`, formularios por crear/editar/gestionar; filtros y asociaciones mediante IDs/UUID sin directorios adicionales. Solo `recibida → en_revision`, responsable y seguimiento privado; sin precios, presupuestos, cierres o recepción pública. Se reutilizan layout, tabla, paginador, sesión/CSRF y backend 19, sin cambiar servicios/cursos/chatbot/Python, migraciones o activaciones.

Cliente exclusivo en memoria, sin persistencia/analítica/logs de datos personales. ETag/If-Match, comparación separada y adopción confirmada conservando borradores; intención UUID con cuerpo/clave retenidos en creación/seguimiento inciertos y conciliación manual, sin reintentos automáticos. PUT/estado/responsable inciertos se revisan sin reenviar. Listado minimizado, detalle autorizado, texto escapado y respuestas sin caché. **7 pruebas PHP nuevas/78 aserciones; suite completa SQLite 1064/7361** (332,18 s), **36 frontend**, build, Larastan 6, Pint acotado y diff aprobados. Smoke Edge 390/768/1440: teclado, creación/edición/gestión, conflicto, lector/403 y red perdida simulada; cero excepciones JS/destinos externos. Fixture exclusiva `127.0.0.1:8775`, SQLite reconstruida por petición: no acredita persistencia ni concurrencia PostgreSQL. S7/000022 y documentos PostgreSQL previos preservados, sin SQL real, cambios del índice, commits/push o stashes. Continúan pendientes aceptación PostgreSQL, privacidad/retención y alcance público separado para 19.2.

**Política comercial inicial vigente (29/10/2026):** [restricción admin](docs/restriccion-acceso-comercial-admin.md). Cotizaciones exige simultáneamente rol `admin` y permiso específico en sus ocho endpoints; coordinador/secretaria quedan excluidos aunque conserven todos los permisos comerciales. Revalidación transaccional con usuario fresco, responsables nuevos admin con lectura, y navegación limitada a admin con `cotizaciones.ver`. No se eliminan concesiones ni asignaciones históricas. Esta decisión sustituye la apertura a personal con permisos descrita históricamente en 19/19.1; cualquier ampliación requiere autorización del propietario. Sin migraciones o cambios en otros módulos.

**Sprint 19.2, análisis documental del 29/10/2026:** [preparación de recepción pública](docs/sprint-19-2-preparacion-recepcion-publica.md), contrastada con código/Git sobre `anderson` / `038895280000f57d39c698e40d3436fecc0d716f`. Contacto abre `mailto:` sin POST comercial ni confirmación de envío; las fichas públicas enlazan al contacto general. La creación privada exige operador y admite servicios históricos: no es una entrada anónima reutilizable retirando middleware. Cursos carecen de UUID público en el modelo/proyección y su código es editable. Se documentan política pública con contenido pendiente de concretar, falta de retención/anonimización, IP en auditoría, riesgo de SQL/bindings en logs y de flash de entrada en errores HTML, distinguiendo mecanismos comprobados de incidencias no demostradas.

Propuesta revisable de entrada separada, flag propio apagado, selección por UUID editorial elegible, idempotencia con ámbito anónimo, confirmación mínima sin buscador público, defensa por capas y plan PostgreSQL con casos/criterios/evidencia; no implementada. Plazos de expediente/logs/backups/reservas son alternativas para aprobación, no políticas adoptadas. Antes de datos reales: decisiones de finalidad/base jurídica/aviso/derechos, retención, responsables y aceptación PostgreSQL independiente, con arnés que excluya `000022` sin debilitar guardas. Solo documentación; no se repiten pruebas funcionales y las cifras de 19.1 siguen históricas. Sin cambios funcionales, SQL, servicios, migraciones reales, proveedores, flags, índice, commits/push o stashes; documentación PostgreSQL previa preservada.

<a id="seguridad"></a>
## 15. Seguridad y reglas innegociables

Fuente normativa: [AGENTS.md](AGENTS.md), contratos de [CLAUDE.md](CLAUDE.md), [seguridad L1.1](docs/seguridad-l1.1.md). Este resumen no sustituye ni amplía sus reglas.

- Backend por defecto. Preservar frontend, diseño, página principal y contenido fijo; excepciones necesitan autorización específica y no son precedentes para otro sprint.
- No tocar bases reales, ensayo ni S7 por inferencia. No ejecutar migraciones generales, limpiezas de datos, aprovisionamiento ni recuperaciones automáticas.
- No modificar `.env`, credenciales, perfiles locales protegidos ni salvaguardas. No exponer secretos, conversaciones o datos personales en código, logs, documentación o navegador.
- No activar Resend, verificación de correo, catálogo dinámico o Advisor para resolver pruebas. No enviar correos ni llamadas reales a OpenAI sin autorización concreta.
- No reanudar S7 ni alterar sus puertos/nombres/ACL/HBA/marcador. Las propuestas de infraestructura son planes, no permisos.
- Preservar cambios sin commit, Python, índice, commits y stashes. No usar limpiezas masivas ni descartar trabajo ajeno.
- No commit, push, merge, rebase, tag o despliegue sin autorización específica. No modificar stashes.
- No reintroducir Docentes, Sesiones o Certificados. No ampliar permisos por conveniencia; permisos nuevos requieren constante del seeder y migración propia.
- Bloquear y releer dentro de transacción las operaciones de cupos/permisos sensibles. Conservar el orden cursos → estudiantes → matrícula y las excepciones históricas documentadas.

### Discrepancias documentales conocidas

`CLAUDE.md` todavía dice que el controlador público no está enrutado y que solo existe la lectura `vigentes()`; el código actual y Sprint 17.2 confirman ruta conectada y `paraWeb()`, con ambos catálogos apagados por separado. La prohibición general de enrutar/modificar contenido no se elimina: hubo una autorización concreta y limitada para 17.2. Esta tarea solo añade enlace al contexto en esa guía, sin reescribir sus reglas previas.

El comentario de `config/catalogo-publico.php` describe la preparación anterior, y el README Python aún presenta la integración Laravel como futura. En Laravel ya existe cliente opcional, pero sigue apagado y sin sincronización operativa. Las afirmaciones de caducidad en ese README deben contrastarse con la carga al arranque. Los encabezados antiguos de L2.2-A/B/S3 describen etapas anteriores; L2.3 aporta la observación posterior de S7. Señalar la discrepancia antes de una tarea cuyo alcance dependa de resolverla; no interpretar este contexto como autorización para sobrepasar las reglas.

<a id="calidad"></a>
## 16. Estado de calidad y pruebas

**Último resultado funcional documentado: restricción comercial admin**, [informe](docs/restriccion-acceso-comercial-admin.md): **1066 pruebas PHP/7447 aserciones** en SQLite; selección cotizaciones/panel **55/704**, 36 frontend, build, Larastan 6, Pint acotado, diff y smoke Edge 390/768/1440 aprobados; sin PostgreSQL. **Resultado previo Sprint 19.1**, [informe](docs/sprint-19-1-panel-cotizaciones.md): 1064 PHP/7361 aserciones, 7 nuevas/78. **Resultado previo Sprint 19**, [informe](docs/sprint-19-cotizaciones.md): 1057 PHP/7283 aserciones, 46 específicas/540, sin frontend. **Resultado previo de Sprint 18.2**, detallado en §14 y en [su informe](docs/sprint-18-2-servicios-web.md): 1011 pruebas PHP/6741 aserciones en SQLite, 18 pruebas frontend, build, Larastan 6, Pint acotado y 15 casos Edge aprobados. La tabla siguiente corresponde al Sprint 17.2.

Actualización PostgreSQL 29/10: se ejecutó únicamente la selección de seguridad `TestDatabaseSafetyTest|ConcurrentTestSessionTest|PostgresTestGuardTest`: **96 aprobadas, 366 aserciones**, 15,49 segundos, con dobles y sin sockets PostgreSQL. No se repitieron suite funcional completa ni Larastan; la integración y concurrencia PostgreSQL continúan sin ejecutar por indisponibilidad y dependencia suspendida `000022`.

| Comprobación histórica | Resultado / límite |
| --- | --- |
| PHP completo, SQLite `:memory:` | 951 aprobadas, 6030 aserciones |
| Selección editorial/web/editor/exportador | 127 aprobadas, 923 aserciones |
| Larastan nivel 6 | Sin errores |
| Pint acotado / `git diff --check` | Aprobados; Pint global tiene formato preexistente ajeno |
| Vite build / pruebas frontend | Aprobado / siete aprobadas |
| Smoke visual de catálogo | 15 casos: cinco estados a 390/768/1440; sin solicitudes externas ni excepciones JS |
| Python | README reporta 144 básicas + 11 semánticas; no ejecutadas por este documento |
| PostgreSQL | Ensayo DDL en copia histórico; suite de concurrencia/perfil aislado pendiente de recuperación y autorización |

Comandos de referencia para tareas futuras autorizadas: `php artisan test --compact --filter=Clase`, suite `php artisan test --compact`, `php vendor/bin/phpstan analyse --no-progress`, Pint sobre archivos propios, `npm test`, `npm run build`, `git diff --check`. [phpunit.xml](phpunit.xml) fija aislamiento SQLite; no usar el perfil PostgreSQL de forma implícita. No ejecutar comandos de setup/dev que generen claves o migren.

Los smokes locales tienen destinos distintos: leer cabecera y fixture antes de ejecutarlos. [Editor](tests/browser/editor-panel-smoke.mjs) usa `127.0.0.1:8771`; [catálogo](tests/browser/catalogo-web-smoke.mjs), `127.0.0.1:8772`. Reconstruyen datos ficticios en SQLite por petición y no prueban persistencia/contención PostgreSQL. No dirigirlos a un servidor cotidiano con datos reales. No declarar validación visual solo por build ni éxito de proveedor por HTTP simulado.

Limitaciones: métricas sintéticas no garantizan experiencia real; SQLite no valida locks/DDL/colación PostgreSQL; HTTP falso no demuestra entrega de correo, disponibilidad de OpenAI/FastAPI ni despliegue. `accepted` en avisos no es confirmación de entrega al destinatario; estados inciertos no permiten reenviar indiscriminadamente.

<a id="roadmap"></a>
## 17. Pendientes y roadmap

Orden propuesto por el propietario para organizar futuras tareas; **no autoriza su ejecución ni asigna fechas**. Decisiones ya vigentes: preservar diseño y PHP, separar publicación/datos privados, mantener activaciones apagadas, `000022` y S7 suspendidos, no modificar módulos excluidos.

| Prioridad propuesta | Dependencia antes de ejecutar |
| --- | --- |
| 1. Consolidar contexto y continuidad | Este documento; mantener solo secciones afectadas cuando esté incluido en el alcance |
| 2. PostgreSQL Testing real | Guarda en 55433 (PG-TEST-02a) y clúster creado (PG-TEST-02b); perfil y `probe` validados (PG-TEST-03); identidad de clúster en todas las rutas y plan de diez etapas (PG-TEST-04); pruebas adaptadas (PG-TEST-05A); `horus_l2_test` migrada y verificada (PG-TEST-05B-1); PG-TEST-05B-2A detenida en la etapa 1 por la salvaguarda de snapshot de la exportación Excel frente a `RefreshDatabase` (corregida; la segunda ronda dejó 1 error de prueba incompatible con PostgreSQL en `MatriculaIntegrityConstraintsTest`, ya corregido con savepoint junto con la prueba de snapshot exclusiva de PostgreSQL y `--stop-on-error`, validados en SQLite; las dos pruebas PostgreSQL pendientes pasan, con 1 «risky» cada una; PG-TEST-05B-2A.2 auditó sin conexiones 116 entradas, cero de etapas 2–7, seis con DatabaseMigrations, las cinco de integridad y snapshot; cierre del envoltorio endurecido y huella Git de contenido; la etapa 1 se repitió el 30/10 (`stage1-ronda8`): 116 pruebas, 525 aserciones, cero risky/errores/fallos, aprobada; las etapas 2–7, la concurrencia, la suite completa y la restauración siguen pendientes de autorización). La recuperación de S7 queda aparcada |
| 3. Migraciones controladas | Autorizar base/ventana/backup/archivos específicos; no activar verificación ni aplicar `000022` a habitual |
| 4. Activación editorial en entorno autorizado | Revisar `000021`, permiso/autores, fichas reales, retiros/caducidad, logs y rollback operativo del flag |
| 5. Sprints 18–18.2, servicios implementados localmente | Backend, panel y conexión web preparados; pendientes aceptación PostgreSQL, migraciones autorizadas y activación controlada |
| 6. Sprints 19/19.1 implementados localmente; 19.2 documental | Backend y panel privados con estados mínimos; propuesta de recepción pública revisable, sin implementar. Pendientes decisiones de privacidad/retención, alcance y autorización de implementación/activación independientes, reglas comerciales adicionales y aceptación PostgreSQL antes de datos reales |
| 7. Distribución/invalidez del catálogo Python | Resolver carga/recarga, retirada, expiración en caliente y zona horaria; no basta validar HMAC |
| 8. Validación independiente del chatbot | Corpus separado, usuarios/casos reales autorizados, privacidad, calidad y consumo de recursos |
| 9. Integración funcional gradual de Advisor | Revisar contrato Python vigente, seguridad, timeout/fallback y observabilidad antes de activar |
| 10. Despliegue y operación | Autorizaciones separadas, privilegios mínimos, backup recuperable, workers/scheduler y control de activaciones |

Deuda adicional acotada: paginación de lectura web para catálogos grandes, imágenes/campos editoriales enriquecidos si se aprueban, actualización de referencias históricas contradictorias y límites de memoria de Excel/semántica. No convertir esta lista en una reimplementación general.

<a id="guia-agentes"></a>
## 18. LEER ANTES DE COMENZAR CUALQUIER TAREA

1. Leer [AGENTS.md](AGENTS.md): alcance y prohibiciones vigentes. La instrucción concreta del propietario define qué trabajo está autorizado.
2. Leer [CLAUDE.md](CLAUDE.md) para contratos técnicos, comandos y validación; preservar sus restricciones aunque una descripción histórica esté desactualizada.
3. Leer este contexto, empezando por la fecha, rama, HEAD y activaciones. No asumir que su instantánea siga siendo exacta.
4. Consultar el informe específico del módulo y contrastarlo con rutas, controlador, request, servicios/modelos y pruebas. No utilizar memoria conversacional como única evidencia.
5. Revisar Git, índice y cambios existentes antes de editar. Inventariar qué pertenece a trabajos anteriores; no tocar stashes ni hacer limpieza.
6. Identificar alcance, entornos y restricciones. Ante discrepancias que cambien el alcance, informarlas y consultar; un plan documental no autoriza escrituras.
7. Reutilizar lo ya implementado. No crear otro editor, cliente, exportador, chatbot, ruta o módulo por no haberlo localizado.
8. Ejecutar solo las comprobaciones adecuadas a la tarea. Diferenciar evidencia histórica de nueva y SQLite/fixtures de PostgreSQL/proveedores reales.
9. Preservar trabajo previo y datos; informar archivos propios, resultados, límites y pendientes. No activar flags ni relajar guardas para conseguir un resultado verde.
10. Al cerrar un sprint, actualizar únicamente las secciones afectadas de este contexto **si está incluido en el alcance autorizado**, con fecha/HEAD, fuente y nivel de evidencia. No reescribirlo entero ni instalar automatizaciones, agentes residentes o procesos de mantenimiento.

Este archivo se mantiene como documentación del repositorio. Su creación no modifica la memoria externa de Codex ni autoriza guardar información fuera del alcance del proyecto.


### PG-TEST-05B-2A.2: preparación, no ejecución (29/10/2026)

[Auditoría, hashes y listado completo](docs/postgresql-testing-pg-test-04.md): selección definitiva de 116 entradas y seis DatabaseMigrations; Job Object desde arranque suspendido, resultado de taskkill comprobado, espera del padre y contador de descendientes a cero; retirada independiente de errores de proceso/logging y parada ante cierre inseguro. Huella Git ahora incluye contenido, índice y SHA de stashes: recalcular referencia antes de la siguiente autorización.

Catorce escenarios de cierre/control en fixtures (13 casos más cancelación real de canalización), diez casos de destino local y 135 pruebas de guardas/argumentos (446 aserciones), sin sockets PostgreSQL. Consentimiento real vacío; perfil, evidencias anteriores, índice y stashes preservados. No se ejecutaron etapa 0/probe/check ni etapa 1; servidor no revalidado en vivo. PG-TEST-05B-2A sigue pendiente. La cancelación probada no acredita terminación forzada del host ni pérdida de energía: requieren inspección manual. Los cuatro auxiliares externos y sus hashes están en el informe; no hay autorización para ejecutarlos contra PostgreSQL.


<a id="sprint-20-fase-2a"></a>
### Sprint 20 — Fase 2a: Auditoría de estudiantes (30/10/2026)

**Objetivo.** Registrar en `audit_logs` las cuatro operaciones del módulo de estudiantes (crear, actualizar, eliminar lógicamente, restaurar) y dejar lista la infraestructura común para cursos y matrículas (fases 2b y 2c, **no implementadas**). Sin migraciones, sin cambios de esquema, modelos, vistas, rutas, `AuditLog` ni `SensitiveData`.

**Arquitectura.**
- [AuditoriaAcademica](app/Support/AuditoriaAcademica.php) (`final`, tipos estrictos): listas permitidas por entidad (`estudiante`, `curso`, `matricula`; las dos últimas solo preparadas y sin uso hasta 2b/2c y pendientes de las decisiones del propietario sobre `precio`/`monto_pagado`), cálculo de diferencias con Eloquent (`getDirty()` antes de `save()`, excluye `updated_at`, `created_at` y `deleted_at`) y registro mediante `AuditLog::record()` con el actor recibido de forma explícita.
- [EstudianteController](app/Http/Controllers/Admin/EstudianteController.php): cada operación va en `DB::transaction` y la auditoría es la **última** escritura. `update`, `destroy` y `restaurar` releen la fila con `lockForUpdate()` (en SQLite se ignora). `destroy` y `restaurar` cambian de firma para recibir `Request` (actor); rutas, nombres, middleware, permisos, redirecciones y mensajes no cambian.
- Formato de `changes`: `{"esquema":1,"campos":[...],"antes":{...},"despues":{...}}` (actualización), `{"esquema":1,"campos":[...],"valores":{...}}` (creación) y `{"esquema":1,"operacion":"eliminacion_logica"|"restauracion"}` (eliminar/restaurar).

**Acciones registradas.** `estudiante_creado`, `estudiante_actualizado`, `estudiante_eliminado`, `estudiante_restaurado`; `model_type` = `App\Models\Estudiante`, `model_id` = id del estudiante, `user_id` = usuario autenticado.

**Privacidad (lista permitida, no solo `SensitiveData`).** Con valor anterior/nuevo: solo `estado`. Solo el nombre del campo: `dni`, `nombres`, `apellidos`, `email`, `telefono`, `fecha_nacimiento`, `direccion`. Cualquier otro campo se ignora (ni valor ni nombre). Nunca se guardan contraseñas, tokens ni datos personales. Pendiente conocido y ajeno a esta fase: `user_created`/`user_updated` guardan el correo en texto plano.

**Política transaccional (fail-closed).** Si falla la inserción en `audit_logs`, la operación se revierte y la respuesta es 500 (patrón `failAudit()` de `Sprint12AtomicityTest`). No hay colas ni auditoría previa a las escrituras. Una actualización sin diferencias reales, un restaurar sobre un estudiante no eliminado y un eliminar sobre un estudiante ya eliminado (404 por el enlace de ruta, o no-op bajo bloqueo) **no generan registro**. Una validación fallida o un 403 tampoco.

**Archivos.** Creados: `app/Support/AuditoriaAcademica.php`, `tests/Feature/AuditoriaEstudiantesTest.php`, `tests/Unit/AuditoriaAcademicaTest.php`. Modificados: `app/Http/Controllers/Admin/EstudianteController.php`, `PROJECT_CONTEXT.md`. No se tocaron `CursoController` ni `MatriculaController`.

**Comprobaciones realmente ejecutadas (SQLite `:memory:`, no prueban PostgreSQL ni concurrencia).**
- Pruebas nuevas: 22 aprobadas, 195 aserciones (17 funcionales + 5 unitarias). Marcadores únicos de DNI, nombres, apellidos, correo, teléfono y dirección: ninguno aparece en `changes`.
- Selección de regresión `AdminEstudianteTest|AdminAuthorizationTest|Sprint12AtomicityTest|AuditoriaEstudiantesTest|AuditoriaAcademicaTest`: 122 aprobadas, 525 aserciones.
- Larastan nivel 6: sin errores (tras corregir un aviso `arrayValues.list` en la primera ejecución).
- Pint `--test` sobre los archivos propios: aprobado para la clase auxiliar y las dos pruebas; para el controlador solo informa `line_ending` (la copia de trabajo está en CRLF por la configuración de Git en Windows, preexistente), y una copia normalizada a LF del controlador pasa.
- `git diff --check`: sin errores (solo las advertencias LF/CRLF conocidas).
- **No** se ejecutó la suite completa en esta fase; el último resultado completo documentado, anterior a ella, fue 1223 aprobadas y 1 omitida (7840 aserciones).

**Estado Git.** Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, índice vacío, dos stashes preservados, nada confirmado. Entradas de `git status --short`: 69 antes y 73 después (1 modificada y 3 sin seguimiento añadidas, más este documento ya contabilizado como modificado de antemano).

**Hashes SHA-256 (30/10/2026).** AuditoriaAcademica `C8790A2C…8C3C`; EstudianteController `25BA2B81…72B8`; AuditoriaEstudiantesTest `F11C47E9…461A`; AuditoriaAcademicaTest `241DA706…A8B0`. Sin cambios: AuditLog `5D64D623…8836`, SensitiveData `A81CE7E7…BD0A18`, CursoController `E868C0B7…F2`, MatriculaController `9A9C1BB8…F1`, routes/web.php `24B3DE9E…58DD`.

**Limitaciones.** Los bloqueos y las carreras (doble restauración, doble eliminación) solo están probados lógicamente; la concurrencia real requiere el perfil PostgreSQL aislado y autorización. `audit_logs` carece de índices y el visor filtra con `LIKE`; la exportación de auditoría usa `get()` sin límite. Login y logout no se auditan.

**Siguiente fase.** Sprint 20, Fase 2b (auditoría de cursos), pendiente de autorización expresa.

<a id="sprint-20-fase-2b"></a>
### Sprint 20 — Fase 2b: Auditoría de cursos (30/10/2026)

**Objetivo.** Auditar las operaciones reales del módulo de cursos reutilizando [AuditoriaAcademica](app/Support/AuditoriaAcademica.php) de la fase 2a, con la misma privacidad, atomicidad y política fail-closed. Sin migraciones, índices, cambios de modelos, rutas, vistas, `AuditLog`, `SensitiveData`, `EstudianteController` ni `MatriculaController`. La fase 2c (matrículas) **no** está implementada.

**Operaciones reales encontradas.** Crear, actualizar, archivar (`destroy` = borrado lógico con `SoftDeletes`, mensaje «se archivó») y restaurar. No existe un endpoint independiente de cambio de estado ni de bloqueo: el estado (`activo|inactivo|archivado`) cambia dentro de la actualización y se audita como un campo más. No se crearon rutas ni funcionalidades.

**Arquitectura.**
- Lista permitida de `curso`: con valor anterior/nuevo solo `precio`, `cupos_maximos` y `cupos_disponibles`; solo nombre del campo: `codigo`, `nombre`, `descripcion`, `categoria`, `duracion_horas`, `modalidad`, `horario`, `estado`. La tabla no tiene una columna «cupos»: la autorización expresa de «cupos» se aplicó a las dos columnas de cupos. `cupos_disponibles` se recalcula en cada actualización, por lo que acompaña a `cupos_maximos` en el registro cuando este cambia.
- `AuditoriaAcademica::actorDe(Request)` (nuevo): identificador explícito del usuario autenticado, mismo contrato que el método privado de `EstudianteController` (que no se modificó; unificarlos queda como limpieza opcional).
- [CursoController](app/Http/Controllers/Admin/CursoController.php): `store`, `update`, `destroy` y `restaurar` van en `DB::transaction` con la auditoría como última escritura. `update` conserva el bloqueo, la comprobación de matrículas activas y el recálculo de cupos, y ahora usa `fill()` → diferencias → `save()`. `destroy` y `restaurar` bloquean la fila (`lockForUpdate()`, ignorado por SQLite) y cambian de firma para recibir `Request`; rutas, nombres, middleware, permisos, redirecciones y mensajes no cambian.

**Acciones registradas.** `curso_creado` (`valores` de precio y cupos), `curso_actualizado` (`campos`, `antes`, `despues`), `curso_archivado` (`{"esquema":1,"operacion":"archivado_logico"}`) y `curso_restaurado` (`{"esquema":1,"operacion":"restauracion"}`); `model_type` = `App\Models\Curso`, `model_id` = id del curso, `user_id` = usuario autenticado. No se audita: actualización sin diferencias reales (p. ej. precio 250 frente a «250.00»), validación fallida, 403, archivar un curso ya archivado (404 por el enlace de ruta o no-op bajo bloqueo), restaurar un curso no archivado ni un id inexistente. La publicación editorial (`curso_publicado`/`curso_despublicado`) es independiente y no se tocó.

**Privacidad.** Ningún valor de los campos «solo nombre» se guarda (probado con marcadores únicos en código, nombre, descripción, horario y duración, y con los valores nuevos de categoría, modalidad y estado). No se guardan snapshots, relaciones, estudiantes ni matrículas.

**Atomicidad.** Si falla `audit_logs` (patrón `failAudit()`), crear, actualizar, archivar y restaurar se revierten con 500.

**Comprobaciones realmente ejecutadas (SQLite `:memory:`; no prueban PostgreSQL ni concurrencia).**
- Pruebas nuevas: 18 funcionales ([AuditoriaCursosTest](tests/Feature/AuditoriaCursosTest.php)) y 1 unitaria añadida; con el resto de la clase unitaria, 24 aprobadas, 203 aserciones.
- Regresión (`AdminCursoTest|AdminAuthorizationTest|Sprint12AtomicityTest|AuditoriaEstudiantesTest|AuditoriaCursosTest|AuditoriaAcademicaTest|BackendRobustnessTest|OldInputRenderingTest|Sprint17PublicacionTest|CursoPublicacion`): 275 aprobadas, 1820 aserciones.
- Larastan nivel 6: sin errores. Pint `--test`: aprobado en clase auxiliar y pruebas; el controlador solo falla por `line_ending` de la copia CRLF de Windows (preexistente) y una copia LF pasa. `git diff --check`: sin errores.
- No se ejecutó la suite completa en esta fase.

**Estado Git.** Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, índice vacío, dos stashes preservados, nada confirmado. `git status --short`: 73 → 75 entradas (CursoController modificado y AuditoriaCursosTest nuevo).

**Hashes SHA-256.** AuditoriaAcademica `27016366…62D0`; CursoController `AFD22130…A4AF4`; AuditoriaCursosTest `BD15A0C7…82B7`; AuditoriaAcademicaTest `04A2C954…62D8`. Sin cambios: EstudianteController `25BA2B81…72B8`, MatriculaController `9A9C1BB8…F1`, AuditLog `5D64D623…8836`, SensitiveData `A81CE7E7…BD0A18`, Curso `03A89AF2…F6F9`, routes/web.php `24B3DE9E…58DD`.

**Limitaciones.** Bloqueos y carreras solo probados lógicamente. Archivar un curso con matrículas activas sigue permitido (comportamiento previo, no modificado). `audit_logs` sin índices; la exportación de auditoría carga todas las filas. Login y logout no se auditan.

**Siguiente fase.** Sprint 20, Fase 2c (auditoría de matrículas), pendiente de autorización expresa.

<a id="auditoria-global-panel"></a>
## AUDITORÍA GLOBAL DEL PANEL ADMINISTRATIVO (30/10/2026)

Auditoría **solo de lectura** (código, migraciones, rutas con `php artisan route:list`, vistas, pruebas y artefactos compilados). No se modificó código, pruebas, configuración, migraciones ni datos; no se ejecutó la suite, PostgreSQL ni el perfil aislado. Evidencia: **C** = comprobado leyendo código/artefacto; **H** = hipótesis pendiente de reproducir. Ningún hallazgo fue reproducido en ejecución. HEAD `038895280000f57d39c698e40d3436fecc0d716f`, rama `anderson`, índice vacío, dos stashes intactos.

**Alcance revisado.** Controladores `Admin/*` (Estudiante, Curso, CursoPublicacion*, Matricula, Proceso, Aprobacion, Cotizacion, ServicioTecnologico, User, Role, Report, Auditoria, Notificacion, Busqueda, Configuracion, Respaldo, Asistente, Dashboard, Auth), `AdminProtection`, `AuditoriaAcademica`, modelos clave, Form Requests de estudiantes/cursos/matrículas, el seeder de roles, `bootstrap/app.php`, 122 rutas, 27 migraciones (índices/FK/CHECK), vistas admin (CSRF, rutas, `{!!`, confirmaciones, Tailwind) y 71 archivos de prueba (inventario). *(*) Solo por inventario/rutas: CursoPublicacion, GestionCotizaciones, GestionServiciosTecnologicos.*

**No inspeccionado a fondo (no declarar libre de errores):** `GestionCotizaciones`/`GestionServiciosTecnologicos` internos, `AsistenteVirtualService`, `HorusAdvisorClient`, `JwtTokenService`/`AuthenticateJwt`, cola/scheduler de Resend, vistas Blade línea a línea, JavaScript de `resources/js/app.js`, accesibilidad, responsive en navegador, Python/FastAPI, comportamiento en PostgreSQL real.

**Módulos.** Dashboard, Estudiantes, Cursos (+ publicación), Matrículas, Procesos, Aprobaciones, Cotizaciones, Servicios tecnológicos, Usuarios, Roles/permisos, Reportes, Auditoría, Notificaciones, Búsqueda, Configuración, Respaldo (exportación administrativa), Asistente. No existen módulos de docentes, certificados, sesiones ni horarios (excluidos).

**Verificado sin hallazgo (INFORMATIVO).** De 122 rutas, las únicas de `/admin` sin permiso ni `role:admin` propios son Dashboard, Búsqueda y las dos de Notificaciones (la Búsqueda filtra por permiso en código; Notificaciones comprueba propietario). Los 90 nombres de ruta usados en vistas existen. Los formularios POST de las vistas admin tienen `@csrf`. Solo hay 3 `{!! !!}` (correo de texto plano con saltos de línea eliminados; JSON-LD estático). No hay SQL crudo con entrada de usuario (`orderByRaw`/`selectRaw` estáticos o con binding).

**Hallazgos (C salvo indicación).**
- ALTO · A-01 `audit_logs.user_id` es `nullOnDelete` y no hay snapshot del actor: al eliminar un usuario, su historial pasa a «Sistema» (vista y exportación). Archivos: migración `000007`, `UserController::destroy`, `AuditoriaController`.
- ALTO (latente) · A-02 `usuarios.editar` sin `usuarios.asignar-roles` permite cambiar correo y contraseña de **cualquier** usuario, incluido un administrador (toma de cuenta); solo `admin` tiene hoy ese permiso, pero un rol personalizado lo reabre. La prueba existente cubre solo un objetivo `secretaria`. (H de explotación, C de la ausencia de regla.)
- MEDIO · M-01 Dashboard sin control por permiso: ingresos del mes, conteo de usuarios y roles y pendientes visibles para los tres roles.
- MEDIO · M-02 Gráfico del dashboard: las clases `h-28`, `h-20` (y `h-1`) se arman en PHP y Tailwind no escanea `app/`; en el CSS compilado local `h-28`/`h-20` no existen (`h-36`/`h-12` existen por usos literales). No verificado en navegador.
- MEDIO · M-03 Aprobaciones sin separación de funciones ni historial: cualquier titular de `aprobaciones.gestionar` (coordinador) cambia estado, solicitante y aprobador, incluso de su propia solicitud, y revierte estados.
- MEDIO · M-04 Procesos, aprobaciones, login/logout y fallos de acceso no se auditan; procesos y aprobaciones se eliminan físicamente.
- MEDIO · M-05 Los selectores y `exists:users,id` de procesos/aprobaciones admiten cualquier cuenta, incluidas las del registro público sin verificación de correo (`VerificacionRegistroService` apagado).
- MEDIO · M-06 Notificaciones: `user_id` es `nullOnDelete` y `NULL` significa difusión; al eliminar un usuario, sus avisos personales pasan a verse por todo el personal, y cualquiera puede marcarlos leídos para todos.
- MEDIO · M-07 `MatriculaController::exportarCsv` audita `filters.busqueda` en texto libre (puede ser DNI o nombre) y audita antes de transmitir.
- MEDIO · M-08 Configuración: los valores guardados no los consume ningún código ni vista (solo la propia pantalla); `moneda`, nombre y contacto no tienen efecto. La auditoría guarda todos los enviados sin diferencia y con `model_type` no FQCN.
- MEDIO · M-09 Actualizaciones «la última gana» en estudiantes, cursos, matrículas, procesos, aprobaciones y configuración (sin revisión/ETag; solo cotizaciones y publicaciones la tienen). H sobre impacto real.
- MEDIO · M-10 Reglas de monto: una matrícula `pagada` puede no tener monto, una `pendiente` puede tenerlo y el monto puede superar el precio; ingresos solo suman `pagada` con monto. Requiere decisión del propietario.
- MEDIO · M-11 Se puede archivar/eliminar un curso o un estudiante con matrículas activas (cupos siguen ocupados); conviven `estado=archivado` y borrado lógico.
- MEDIO · M-12 Memoria: exportación de auditoría con `get()` sin límite; el respaldo arma cada CSV y el ZIP en memoria; listas sin límite en formularios y filtros (estudiantes, cursos, usuarios, `Curso::withTrashed()->get()` en reportes/matrículas).
- MEDIO · M-13 Índices (esquema C, impacto H): `audit_logs` sin índices (fecha, acción, `model_type`+`model_id`, `user_id`); en PostgreSQL las FK no crean índice (`audit_logs.user_id`, `procesos_internos.responsable_id/created_by`, `aprobaciones_internas`, `notificaciones_internas.user_id`); `matriculas.curso_id` no encabeza ningún índice.
- BAJO · B-01 Auditoría y exportación ordenan por `created_at` sin desempate por `id` (paginación inestable). B-02 `model_type` mezcla FQCN y cadenas. B-03 `logout` incrementa `jwt_version` (revoca JWT en todos los dispositivos). B-04 Comodines `%`/`_` sin escapar y diferencias SQLite/PostgreSQL de `LIKE`/`ILIKE` (mayúsculas/acentos no ASCII). B-05 La unicidad se valida fuera del bloqueo (carrera → 500, no 422; la restricción protege los datos, H). B-06 Eliminaciones con `confirm()` nativo aunque existe un modal propio. B-07 ZIP temporal en el directorio temporal del sistema con datos personales. B-08 Permisos sin uso (`seguimientos.*`, `asistente.entrenar`) e inconsistencia `servicios` (solo permiso) frente a `cotizaciones` (`role:admin` + permiso). B-09 `EstudianteController::actorId` duplica `AuditoriaAcademica::actorDe`. B-10 «Usuarios activos» cuenta todas las cuentas y «matrículas del mes» incluye anuladas.
- INFORMATIVO · `SESSION_ENCRYPT=false` y `SESSION_SECURE_COOKIE` sin definir en `.env.example`: fijar en despliegue. No se hallaron problemas CRÍTICOS con evidencia.

**Pruebas (inventario).** Sin pruebas dedicadas a procesos ni aprobaciones (solo autorización y `OldInput`); una sola prueba de dashboard (sin permisos ni gráfico); 4 de notificaciones (sin borrado de usuario); 3 de concurrencia y 11 de integridad en PostgreSQL, ninguna para estudiantes, cursos, restaurar ni auditoría; sin pruebas de navegador para CRUD de estudiantes/cursos/matrículas. SQLite ignora `lockForUpdate()` y los CHECK de PostgreSQL.

**Sprint 20.** 2a y 2b: correctas en privacidad (lista permitida, marcadores), atomicidad (`failAudit`) y sin duplicados; límites: bloqueos solo lógicos, `audit_logs` sin índices. **Diseño 2c (no implementado), con las decisiones aprobadas:** valores de `estado` y `monto_pagado`; solo nombres de `estudiante_id`, `curso_id`, `fecha_matricula`, `observaciones`; `efecto_cupo` (`ocupa`, `libera`, `traslada`, `ninguno`); acciones `matricula_creada`, `matricula_actualizada`, `matricula_anulada`, `matricula_eliminada`, `matricula_restaurada`; `PUT` que anula emite **solo** `matricula_anulada` (con sus diferencias); anular repetido y restaurar no eliminada no auditan; auditoría como última escritura de las transacciones existentes (sin nuevos bloqueos); `anular`, `destroy` y `restaurar` recibirán `Request`; `update` pasa a `fill()` → diferencias → `save()`. Archivos: `MatriculaController`, `AuditoriaAcademica` (lista de matrícula y datos extra), nuevo `AuditoriaMatriculasTest`, ajuste de `MatriculaOperationsTest`, este contexto. Riesgo: sin ids, un traslado de curso no deja el destino en la auditoría. Las pruebas de matrículas revisadas no cuentan filas de `audit_logs`.

**Recomendaciones y próximos pasos (sin autorización de ejecución).** (1) Fase 2c. (2) Gobernanza: auditar procesos, aprobaciones y acceso; quitar texto libre de `filters` del CSV; regla para editar cuentas administradoras; separación de funciones en aprobaciones. (3) Frontend (requiere autorización expresa por ser área protegida): control por permiso en el dashboard y alturas del gráfico. (4) Migraciones con revisión previa: snapshot del actor en auditoría e índices (`audit_logs`, FK, `matriculas.curso_id`). (5) Pruebas de concurrencia reales en el perfil PostgreSQL aislado y pruebas dedicadas de procesos/aprobaciones/notificaciones/dashboard. (6) Decisiones del propietario: reglas de monto, archivar con matrículas activas, uso real de Configuración.

**Limitaciones.** Auditoría estática; ninguna hipótesis reproducida; sin PostgreSQL; sin navegador; módulos listados como no inspeccionados a fondo.

<a id="auditoria-global-panel-complementaria"></a>
### AUDITORÍA COMPLEMENTARIA DEL PANEL ADMINISTRATIVO (30/10/2026)

Segunda fase de la auditoría global, **solo de lectura** (código, configuración, vistas, artefactos compilados y cálculo de contraste; sin ejecutar suites, PostgreSQL, Python ni navegador). Evidencia: **C** = comprobado en código/artefacto; **H** = hipótesis pendiente de reproducir; ningún hallazgo fue reproducido en ejecución. Los identificadores A-/M-/B-/I- de la primera fase se conservan; los nuevos usan **N-**. HEAD `038895280000f57d39c698e40d3436fecc0d716f`, índice vacío, dos stashes intactos.

**Inspeccionado en esta fase.** `GestionCotizaciones`, `GestionServiciosTecnologicos`, `CotizacionController`, `ServicioTecnologicoController`, `GuardarServicioRequest`, modelos de cotización/servicio; `JwtTokenService`, `AuthenticateJwt`, `Api\AuthController`, `config/jwt.php`, limitadores; `AsistenteVirtualService`, `HorusAdvisorClient`, `ChatWidgetController`, `config/chatbot.php` y `config/horus-advisor.php`; chatbot Python (`main.py`, `api/routes.py`, `core/security.py`, `core/config.py`, README §12, inventario de archivos y pruebas); scheduler, comando, job, `MatriculaNotificationService`, observer, reset de contraseña; `resources/js/{app.js,chat-client.js,admin-cotizaciones.mjs,admin-servicios.mjs}`, layout/partials Alpine y métricas estáticas de accesibilidad de 31 vistas admin.

**Verificado sin hallazgo (INFORMATIVO).** Cotizaciones y servicios: bloqueo de fila + ETag fuerte (`If-Match`, 412/428) + `Idempotency-Key` UUID con huella HMAC y reconciliación de carrera por restricción única, historial inmutable con `unique(solicitud_id, revision)`, auditoría sin texto libre, estados limitados; el panel (JS) envía CSRF, `If-Match`, clave e interpreta 401/403/412/428/409/422 de forma coherente con el backend. JWT: HS256 fijado, `exp`/`nbf`/`iss`/`aud` validados, secreto ≥ 32 bytes, revocación por `jwt_version` en logout, cambio de contraseña y recuperación, hash ficticio contra enumeración temporal, límite por IP y por correo. Bandeja de correo: sin HTTP dentro de transacción, `Idempotency-Key`, `lease`, reintentos acotados (4) y ventana de 23 h. JS del panel: `textContent` (sin XSS), `AbortController` 12 s, `redirect: 'error'`, conservación de borradores. Python: token de servicio con `hmac.compare_digest`, falla cerrada sin token, loopback obligatorio, `TrustedHost`, límite de cuerpo, semáforo de concurrencia, sin registrar texto del visitante.

**Hallazgos nuevos y actualizaciones.**
- MEDIO · N-06 (C) `JWT_SECRET` cae a `APP_KEY`, que además cifra sesiones, firma huellas de idempotencia y deriva la clave de sesión del chat: un secreto, cuatro usos, rotación acoplada.
- MEDIO · N-07 (C ausencia / H impacto) No hay `trustProxies` ni `trustHosts`: detrás de un proxy inverso `request()->ip()` sería el del proxy, compartiendo los límites de `login`, registro y chat entre todos los usuarios e inutilizando `audit_logs.ip_address`.
- MEDIO · N-12 (C) Asistente del panel y chatbot público comparten servicio, tabla `chat_logs` (sin columna de ámbito), presupuesto global de OpenAI y bloqueo: las pruebas del panel consumen presupuesto público y la pantalla del asistente mezcla orígenes; el Advisor solo se admite con ámbito `public:`, por lo que el panel no puede probarlo.
- MEDIO · N-28 (C cálculo / H visual) Contraste insuficiente frente a WCAG AA (4,5:1): `text-gold` 2,25:1, `text-gold-dark` 2,78:1 y `text-stone-400` 2,52:1 sobre blanco; unas 33 líneas de `text-gold*` sin fondo oscuro en la misma línea y 6 de `text-stone-400` (pueden ser iconos o texto grande; requiere navegador).
- BAJO · N-01 (C) La idempotencia del seguimiento solo opera si el reintento lleva el `If-Match` vigente; con el original se responde 412 antes de consultar la clave. El panel lo mitiga (comparar → adoptar → conciliar); otros clientes no.
- BAJO · N-02 (C) Los servicios auditan solo revisión y estados, no los campos cambiados (las cotizaciones sí listan nombres de campo). N-03 (C) `cotizaciones.responsable` no revalida que el responsable siga siendo administrador.
- INFORMATIVO · N-04 (C) El flujo de cotización solo admite `recibida → en_revision` (irreversible): sin cierre ni resultado; funcionalidad incompleta, no defecto.
- BAJO · N-08 (C) `jti` se emite pero no se almacena ni verifica; revocación solo por usuario; sin refresh ni rotación (TTL 900 s); `iss` = `aud`. N-09 (C) El registro por API entrega JWT inmediato sin verificación cuando el flag está apagado (amplía M-05); JWT solo protege `/me` y `/logout`. N-10 (C) Login API responde 422 en lugar de 401 y los fallos de token no se registran.
- INFORMATIVO · N-11 (C) `.env.example`: `APP_DEBUG=true`, `APP_ENV=local`, `MAIL_MAILER=log` (con ese transporte el enlace de recuperación queda en el registro), `SESSION_ENCRYPT=false`: incluir en la lista de despliegue.
- BAJO · N-13 (C/H) El tope de OpenAI por sesión usa un `session_id` elegido por el cliente; queda el tope global (10/min) y `throttle:30,1`. N-14 (C) Cada mensaje consulta intenciones, serializa y calcula SHA-256 del conocimiento, y con Advisor activo hace dos llamadas HTTP (ready + chat) sin *circuit breaker* (recomendado en README §12). N-15 (C) El filtro de privacidad solo detecta `@` y secuencias de 7+ dígitos.
- INFORMATIVO · N-16 (C) El cliente Advisor envía consultas fijas por tema, así que Python devuelve siempre la misma orientación; el motor léxico/semántico no se aprovecha (decisión de privacidad). `enabled => false` está escrito en `config/horus-advisor.php`, no en `.env`. Una respuesta con teléfono (7+ dígitos) se descarta. N-17 (C) FastAPI expone `/docs` y `/openapi.json` sin autenticación (solo loopback); el modelo semántico (≈ 470 MB) está fuera de Git; 9 archivos de pruebas Python no ejecutados aquí; el cliente exige `catalog = none`, incompatible hoy con el catálogo firmado.
- BAJO · N-18 (C) `horus:matriculas-notificar` programado cada minuto sin `withoutOverlapping()`/`onOneServer()` (el despacho es seguro por reservas condicionales) y con envío apagado termina en FAILURE cada minuto. N-19 (C) Los registros de correo solo llevan `outcome`, sin identificador del aviso, y no hay pantalla ni alerta de `uncertain`/`failed`. N-20 (C) Worker y scheduler no tienen definición versionada de despliegue (solo documentación Windows). N-21 (C/H) La recuperación de contraseña se envía de forma síncrona por el *mailer* de Laravel (no por la bandeja); una excepción no-Transport solo ocurre si la cuenta existe (posible enumeración, H). Amplía M-03: la notificación de cambio de aprobación va a `solicitante ?? aprobador`, que puede ser el propio actor.
- MEDIO (amplía M-02) Tailwind `content` no incluye `app/` ni `*.mjs`: las clases usadas por el gráfico (PHP) faltan y las de los módulos `.mjs` solo existen porque aparecen también en Blade (comprobado en el CSS compilado); hay además un segundo CSS huérfano en `public/build` fuera del manifiesto.
- BAJO · N-23 (C) El formulario de contacto público es `mailto:` sin backend y `asunto.options[selectedIndex]` falla si no hay selección (H). N-24 (C) `carousel` crea un `setInterval` sin limpieza. N-25 (C) Los módulos `admin-*.mjs` asumen todos sus nodos (`q('[data-comparacion]')`, `fieldset`); un cambio de Blade deja el panel inerte sin mensaje (H).
- BAJO (amplía B-06) 4 vistas usan `confirm()` nativo y el resto el modal propio, que no atrapa ni devuelve el foco. N-29 (C/H) Sin enlace «saltar al contenido» ni `id` en `<main>`; el botón del menú móvil no declara `aria-expanded`/`aria-controls`; la barra lateral cerrada solo se oculta con `-translate-x-full` (sin `inert`), por lo que podría seguir siendo alcanzable por teclado (navegador). N-30 (C) 12 de 15 tablas del panel sin `scope` en `<th>` ni `<caption>`.
- Actualizaciones: **B-03** también ocurre con el logout API (revoca todos los JWT del usuario); **B-08** `servicios` valida permiso + rol de personal y `cotizaciones` además `role:admin`; **M-09** cotizaciones y servicios sí tienen revisión por ETag; **A-01** contraste: `cotizacion_historial` conserva `actor_ambito` (el panel muestra «eliminado» pero el identificador permanece), a diferencia de `audit_logs`.
- Positivo estático: `lang="es"`, `viewport`, etiquetas ≥ campos en todos los formularios, tablas con desplazamiento horizontal, `role=status/alert` en avisos, `role=dialog`+`aria-modal`+Esc en el modal, iconos `aria-hidden`, `prefers-reduced-motion` en el carrusel. La salida de PowerShell mostró acentos corruptos en algunos archivos: es un artefacto de la consola, no un defecto comprobado.

**Integración documentada (no implementada).** Navegador (widget Blade `chatClient`, sin cambios) → `POST /chat/mensaje` en Laravel (sesión, CSRF, validación, `throttle`, privacidad, caché y registro de metadatos) → `AsistenteVirtualService` (local primero) → cliente Advisor servidor a servidor con `X-Horus-Service-Token` → FastAPI en `127.0.0.1` (recuperación/recomendación sobre conocimiento verificado, sin PII, sin BD, sin escritura) → misma forma de respuesta `{respuesta, intent, fuente}`; con `fuente = advisor` el widget no cambia. Antes de activar: flag por `.env`; pruebas de contrato con `Http::fake()` (éxito, timeout, 401, 422, 503); *circuit breaker* en caché; decisión del propietario sobre enviar o no texto libre sanitizado; separar presupuesto y registro por ámbito (público/panel); Laravel y Python en el mismo equipo, servicio supervisado para uvicorn, rotación con dos tokens, `/docs` desactivado y modelo provisionado fuera de Git; catálogo firmado apagado hasta resolver la invalidación.

**Limitaciones.** Auditoría estática; sin navegador, sin PostgreSQL, sin ejecución de pruebas PHP/JS/Python. **No inspeccionado a fondo:** `VerificacionRegistroService`, `ChatKnowledge`/`ChatIntentMatcher`/`ChatCursosPublicos`, `CatalogoPublico{Cursos,Servicios}`, `ExportadorCatalogoAdvisor`/`FirmaCatalogoAdvisor`, motor Python (`chat_engine`, `recommendations`, `catalog`, `semantic_search`), scripts inline de páginas públicas (galería, quiénes somos), accesibilidad dinámica, responsive real y rendimiento bajo carga. La auditoría global **no se declara completa**.

**Próximos pasos propuestos (sin autorización de ejecución).** Fase 2c; fijar `trustProxies` y secreto JWT propio en la configuración de despliegue; separar ámbito del asistente; revisar contraste y foco con navegador; pruebas de contrato Advisor; definición de despliegue de worker/scheduler; completar la inspección de las áreas listadas.

<a id="auditoria-complementaria-final"></a>
## AUDITORÍA COMPLEMENTARIA FINAL DEL SISTEMA (30/10/2026)

Tercera ronda de la auditoría global, **solo de lectura** (código PHP/Python/JS, configuración, vistas y estructura; sin ejecutar pruebas, Python, PostgreSQL ni cargas, sin navegador). Evidencia: **C** = comprobado en código/artefacto; **H** = hipótesis pendiente de reproducir. Ningún hallazgo fue reproducido en ejecución. HEAD `038895280000f57d39c698e40d3436fecc0d716f`, índice vacío, dos stashes intactos. Complementa [la auditoría global](#auditoria-global-panel) y [la complementaria](#auditoria-global-panel-complementaria); no las reemplaza.

**Coherencia de identificadores.** Se conservan A-01..A-02, M-01..M-13, B-01..B-10 y N-01..N-30. Correcciones documentales: (1) el identificador **N-17 designaba dos problemas**; se mantiene para «el cliente PHP exige `catalog = none` frente al catálogo firmado» y se crean **N-31** (FastAPI expone `/docs` y `/openapi.json` sin autenticación, solo loopback) y **N-32** (modelo semántico de ≈ 470 MB fuera de Git y 9 archivos de pruebas Python sin ejecutar: provisión y validación pendientes). (2) N-05, N-22, N-26 y N-27 no se asignaron nunca; quedan **reservados, sin hallazgo**. (3) La mención «MEDIO (amplía M-02)» de la ronda anterior es una ampliación de M-02, no un hallazgo nuevo. (4) Los hallazgos de esta ronda continúan en **N-33..N-55**. Referencias cruzadas: N-33 ↔ M-05/N-09; N-34 ↔ M-13/N-46; N-36 ↔ N-16; N-40 ↔ N-17; N-45 ↔ M-12/M-13; N-47 ↔ N-14/N-17; N-48 ↔ B-04/M-13.

**Inspeccionado en esta ronda.** `VerificacionRegistroService` y su controlador, requests, middleware y correo; `ChatKnowledge`, `ChatIntentMatcher`, `ChatCursosPublicos`; `CatalogoPublicoCursos`, `CatalogoPublicoServicios` y sus controladores; `ExportadorCatalogoAdvisor`, `FirmaCatalogoAdvisor`; Python `chat_engine.py` (completo), `catalog.py` (completo), `semantic_search.py` (completo), `recommendations.py` (primeras 260 líneas), `schemas/chat.py` y `schemas/catalog.py` (campos clave); JS público en `public/assets/js` (inventario completo, lectura parcial de `libro-reclamaciones.js` y `galeria.js`); comprobación de afirmaciones del chatbot contra las páginas públicas.

**Verificado sin hallazgo.** Verificación de registro: código de 256 bits, solo se guarda su SHA-256, correo con HMAC, caducidad de 30 min, un solo uso con actualización condicional, enfriamiento de 60 s por correo, respuestas genéricas, coste de hash constante, y reclamación de cuentas anteriores sin verificar con cambio de contraseña, `jwt_version` y cierre de sesiones. Catálogos: solo fichas publicadas, vigentes y con curso/servicio activo y no eliminado; no exponen precio, cupos, matrículas ni estudiantes; los archivados quedan fuera. Firma: HMAC-SHA256 sobre JSON canónico con `hash_equals`/`compare_digest`, exportación en memoria y todo-o-nada, límite de 500 fichas y 1 MiB. Chatbot: respuestas locales; las afirmaciones de pagos, comprobantes, horarios y dirección coinciden con las páginas públicas; widget con `x-text` (sin XSS). Python: validación pydantic (mensaje ≤ 2000, historial ≤ 10, sesión con patrón), límites de cuerpo, semáforo, errores sin eco, sin registrar el texto. Vistas sin recursos de CDN externos.

**Hallazgos nuevos y actualizaciones.**
- ALTO · N-50 (C; cumplimiento H) El Libro de Reclamaciones no registra nada: `data-endpoint=""` activa un envío simulado con número `SIM-…` generado en el navegador; el aviso «modo de prueba» aparece solo después de enviar y el formulario recoge nombre, documento, correo, teléfono y dirección que se descartan. El requisito legal no lo evalúa esta auditoría técnica.
- MEDIO · N-33 (C) El flujo de verificación no tiene interfaz: solo hay `POST` JSON (reenviar y confirmar), ninguna vista ni ruta `GET`, el correo entrega un código de 64 caracteres hexadecimales y el registro web descarta nombre y contraseña que se vuelven a pedir en la confirmación: con el flag encendido el registro web no puede completarse.
- MEDIO · N-36 (C/H) Tres fuentes de verdad para el mismo contenido (`ChatKnowledge.php`, `chatbot-python/data/knowledge.json`, páginas Blade) y el teléfono/correo literales repetidos en 11 archivos PHP/Python/Blade: riesgo de deriva.
- MEDIO · N-40 (C) `CatalogoPublicoCursos::vigentes()` (usado por el chatbot) no exige que el autorizador conserve `cursos.publicar`; `paraWeb()` y el exportador sí. Una ficha cuyo autor perdió el permiso sigue visible para el chatbot cuando el catálogo está activo; contradice la regla «ficha autorizada» de CLAUDE.md.
- MEDIO · N-45 (C config; H carga) La ruta caliente pública usa la base de datos para sesión, caché, bloqueos y cola (`.env.example`): cada mensaje del chat implica sesión, 2–3 escrituras de caché, bloqueo, inserción en `chat_logs` y lectura de intenciones.
- Actualización **N-17** (C) Causas del bloqueo, por orden: (a) no existe productor del archivo que Python lee al arrancar (`HORUS_AI_CATALOG_PATH`), ni recarga en caliente, ni endpoint de carga: el exportador solo construye en memoria y Laravel solo valida en seco; (b) la caducidad `expires_at` (≤ 5 min con el exportador; el contrato admite hasta 90 días) y la ventana `valid_*` se evalúan **solo al cargar**, de modo que un catálogo cargado nunca vuelve a caducar en el proceso; (c) el cliente PHP exige `catalog = none` y además exige `url_path` en cada fuente, pero el exportador no envía `url_path` (queda nulo). La negativa del cliente es una defensa correcta, no un defecto: catálogo firmado y chat Advisor son hoy excluyentes. No hay protección contra reinstalar un catálogo firmado antiguo no caducado ni `kid` para rotar la clave; `catalog_allow_unsigned` es falso por defecto; el vector de firma fijo existe solo en pruebas PHP (la paridad PHP↔Python de la canonicalización no tiene vector compartido, H).
- BAJO · N-34 (C) `registros_pendientes` sin purga: conserva el correo en claro y su HMAC indefinidamente. N-35 (C/H) El enfriamiento es por correo y el límite por IP, sin tope diario ni CAPTCHA: un tercero puede enviar un correo por minuto a una víctima.
- BAJO · N-37 (C) `bloqueado()` descarta palabras legítimas (`estudiantes`, `panel`, `administrador`, `historia`, `notas`…) con una respuesta genérica; `prioridad` no interviene en la resolución PHP. N-38 (C) «virtual»/«online» activan `consulta_modalidades` incluso en «asistente virtual». N-41 (C) Los catálogos web no se cachean (`no-store`) y cada visita ejecuta introspección de esquema (`Schema::hasTable`), comprobación de permisos y consulta; sin coste con los flags apagados. N-42 (C) La exportación Advisor rechaza todo el catálogo si una ficha contiene `estudiantes`, `clientes`, teléfonos o `@`, y solo exporta cursos. N-44 (C) `hibrido` se exporta como `modalities: []` más un texto, de modo que el catálogo consumido difiere del exportado. N-46 (C) `chat_logs` sin purga ni índice por fecha (la pantalla ordena por `created_at`). N-47 (C) En Python, `wait_for(run_in_threadpool)` no cancela el hilo: el semáforo se libera al expirar mientras el cálculo continúa, y `_focus` re-ejecuta el motor hasta 3 veces por mensaje (latente: Laravel envía historial vacío). N-48 (C) Búsqueda global con `LIKE '%…%'` y `id::text` sin índices trigram. N-49 (C) Dashboard con unas 14 consultas por visita sin caché. N-51 (C) `galeria.js` inserta comentarios con `innerHTML` sin escapar (auto-XSS, sin persistencia) y simula likes/comentarios/compartir en el cliente. N-52 (C) Scripts huérfanos (`animations`, `carousel`, `navigation`, `pantalla-inicio`, `convenios-carousel`, `contactos2`) sin referencia en vistas; `contactos2.js` y el valor por defecto de `libro-reclamaciones.js` apuntan a `http://localhost:3000` (API NestJS descartada). N-53 (C) Tres scripts de políticas duplicados con listeners `scroll` sin `passive`/throttle (H). N-54 (C) El formulario del libro de reclamaciones no usa `aria-live`, `aria-invalid`, `aria-describedby` ni `role="alert"`.
- INFORMATIVO · N-39 El asistente del panel y el público comparten reglas (ver N-12). N-43 (C) CLAUDE.md dice que `CursosPublicosController` no está enrutado; hoy `/educacion/cursos` y `/tecnologias/soporte-mantenimiento` están enrutados a controladores con flags apagados. N-55 Dependencias Python con rangos amplios pero con `requirements.lock`; no se auditaron CVE.

**Rendimiento (evaluación estática, sin cargas).** Cuellos probables: sesión/caché/bloqueos/cola en BD (N-45); `audit_logs`, `chat_logs`, `registros_pendientes` sin purga ni índices (M-13, N-34, N-46); búsquedas `LIKE` (N-48, B-04); listas sin límite y exportaciones en memoria (M-12); Python limitado a CPU con modelo en memoria (N-47). **Plan futuro de pruebas de carga (no ejecutado ni autorizado):** entorno desechable y el clúster PostgreSQL aislado 55433, sin correos reales (`Http::fake`/sandbox) y con límites por IP desactivados solo en la base de pruebas; S1 páginas públicas (línea base); S2 `POST /chat/mensaje` con mezcla local/caché/OpenAI simulado/Advisor simulado y rampa; S3 lecturas del panel con 10 000 y 100 000 registros (dashboard, listados, búsqueda); S4 escrituras concurrentes (matrículas contra un curso con un cupo, restaurar/eliminar, curso contra matrícula) comprobando ausencia de sobreventa y bloqueos; S5 exportaciones (Excel, auditoría, respaldo) con pico de memoria; S6 bandeja de correos con 10 000 avisos y proveedor simulado; S7 Python `/api/v1/chat` con 4/8/16 concurrentes, con y sin semántica, y timeouts. Métricas: p50/p95/p99, errores, conexiones, esperas de bloqueo, `pg_stat_statements`, CPU/memoria y crecimiento de tablas; los umbrales de aceptación los fija el propietario.

**Integración Laravel/Python.** Mantener lo documentado en la ronda anterior; añadir como precondiciones específicas del catálogo: productor que escriba el archivo firmado, recarga o endpoint, evaluación de caducidad y ventana por petición (o recarga programada más corta que `expires_at`), `url_path` en los ítems, resolución de `hibrido`, `kid` para rotar la clave y vector de firma compartido PHP↔Python.

**Accesibilidad.** Panel: ver N-28..N-30. Público: tabla de etiquetas y `lang` correctos en el layout admin; el libro de reclamaciones sin anuncios de error (N-54); contraste y foco públicos no medidos; sin navegador.

**Limitaciones y áreas todavía sin inspección completa.** `recommendations.py` (líneas 261 en adelante), `knowledge.py`, `lexical_search.py`, `understanding.py`, `core/text.py`, esquemas de conocimiento, `scripts/`, datos de evaluación y pruebas Python; `animations.js`, `carousel.js`, `navigation.js`, `pantalla-inicio.js`, `convenios-carousel.js`, `contactos2.js` y los scripts de políticas (solo patrones), `libro-reclamaciones.js` y `galeria.js` (parcial); vistas públicas Blade y su CSS (contraste, foco y responsive reales); plantillas de correo; comportamiento en navegador, PostgreSQL y bajo carga. La auditoría global **no se declara completa**.

**Plan de corrección propuesto (sin autorización de ejecución).** (1) Fase 2c. (2) Decisión del propietario sobre N-50 (libro de reclamaciones operativo o retirada del formulario hasta que lo sea). (3) Configuración de despliegue: `trustProxies`, secreto JWT propio, sesión/caché/cola fuera de la base de datos, `APP_DEBUG` y `MAIL_MAILER` (N-06, N-07, N-11, N-45). (4) Gobernanza del panel (A-01, A-02, M-03, M-04, M-07). (5) Unificar criterios de publicación (N-40) y fuente de verdad del contenido (N-36). (6) Antes de activar la verificación de registro: interfaz y purga (N-33, N-34). (7) Antes de activar el Advisor: pruebas de contrato, *circuit breaker*, ámbito del asistente y, para el catálogo, la lista de precondiciones de N-17. (8) Frontend protegido, con autorización: contraste, foco, `scope`, gráfico (M-02, N-28..N-30, N-54). (9) Migraciones revisadas: índices y purgas (M-13, N-34, N-46, N-48). (10) Limpieza de scripts huérfanos (N-52) y documentación obsoleta (N-43). (11) Ejecutar el plan de carga y completar las inspecciones pendientes.

---

## SPRINT 20 — LIBRO DE RECLAMACIONES: FASE 1, DISEÑO TÉCNICO

**Fecha:** 2026-10-30. **Naturaleza:** auditoría y diseño. No se modificó código, pruebas, rutas, vistas, permisos, configuración ni migraciones; no se ejecutó Artisan, PHPUnit ni PostgreSQL; no se envió ningún correo. Solo se actualizó este archivo. Evidencia: **C** = comprobado en código; **O** = comprobado en fuente oficial; **S** = solo fuente secundaria (por verificar); **P** = propuesta de diseño.

### 1. Hallazgo N-50 y decisión del propietario
- **N-50 (ALTO):** el Libro de Reclamaciones público era un envío simulado (`data-endpoint=""` → número `SIM-…` generado en el navegador; los datos personales se descartaban).
- **Decisión del propietario: alternativa A** = implementar un Libro de Reclamaciones funcional con el stack actual (Laravel, Blade, Alpine, Tailwind, Eloquent, PostgreSQL). React/NestJS/MySQL descartados.

### 2. Diagnóstico del código existente (C)
- Ruta `GET /libro-reclamaciones` es `Route::view` (`legales.reclamaciones`). No existe ruta `POST`, controlador, FormRequest, modelo ni tabla de reclamaciones.
- Vista `pages/legales/libro-reclamaciones.blade.php`: asistente de 3 pasos; el `<form>` no tiene `action`, `method` ni `@csrf`; usa `x-on:submit.prevent`. Promete «15 días hábiles» y un «correo de confirmación con el número de seguimiento» que hoy no existen.
- `public/assets/js/libro-reclamaciones.js`: validación de cliente, resumen escapado, envío JSON a `data-endpoint`; con endpoint vacío simula; sin atributo apunta a `http://localhost:3000/api/reclamaciones` (API NestJS descartada).
- **Campos que el formulario NO recoge y la norma exige (O):** pedido concreto del consumidor, monto del bien o servicio, datos del representante si el consumidor es menor, identificación del proveedor (razón social, RUC, dirección) en la hoja.
- Reutilizable: `AuditLog::record` + `SensitiveData`; patrón de `AuditoriaAcademica` (listas permitidas, auditoría como última escritura, falla cerrada); patrón de cotizaciones (UUID público, `codigo` único, `revision`/ETag, tabla de historial con `revision` única, `lockForUpdate`, clave de idempotencia con huella HMAC, migración de permisos separada, middleware de habilitación por flag); patrón de bandeja transaccional de correo (`matricula_notifications`: carga cifrada, `delivery_key`, lease, reintentos, ventana de primer intento); limitadores nombrados en `AppServiceProvider`; layout admin y sidebar con `@can`.
- **No reutilizable tal cual:** `matricula_notifications` (FK única a `matriculas`, flujo acoplado a Resend y a matrículas); `SolicitudCotizacionPublicaController` (marcador de posición del sprint 19.2 suspendido); `GestionCotizaciones` (acoplado a servicios y a admin).
- Política de privacidad (C): declara finalidades comerciales/publicidad y conservación «hasta que usted decida eliminarlo»; **no menciona reclamaciones**. Choca con la conservación obligatoria y con el principio de finalidad (ver §8).
- No inspeccionado en esta fase: tabla/modelo de notificaciones del panel (`admin/notificaciones`), plantillas de correo existentes en detalle, CSS del libro, contraste/foco reales (requiere navegador).

### 3. Requisitos legales (separar niveles)
**Verificados en fuente oficial (O)** — Indecopi, «Preguntas y respuestas — Obligatoriedad y formato del Libro de Reclamaciones», versión 12.11.2025 (consumidor.gob.pe), que remite al DS 011-2011-PCM:
- Todo establecimiento comercial abierto al público, físico **o virtual (páginas web)**, debe contar con Libro de Reclamaciones. El virtual no obliga a tener físico, pero **debe existir un libro físico de respaldo** para cuando no sea posible usar el virtual.
- Contenido mínimo (Anexo I): numeración correlativa; identificación del proveedor (razón social, RUC, dirección); fecha; identificación del consumidor (nombre, DNI, teléfono, correo; si es menor, datos del padre o representante); descripción y monto del bien o servicio; detalle del reclamo o queja; **pedido concreto del consumidor**; espacio para acciones/observaciones del proveedor; firma solo en el libro físico.
- Libro virtual: accesible en el mismo medio donde se ofrecen los servicios y **debe permitir imprimir o enviar copia al correo del consumidor**. Debe exhibirse el Aviso del Libro de Reclamaciones (Anexo II). No requiere legalización notarial ni registro/certificación ante Indecopi. Un código de identificación por establecimiento, de libre elección.
- Respuesta: plazo máximo de **15 días hábiles, improrrogables**; incumplirlo es infracción administrativa denunciable ante Indecopi. La respuesta es **escrita** (carta o correo indicado por el consumidor), informa las acciones adoptadas y, si no se acepta el pedido, fundamenta la posición. El proveedor debe **conservar el correo enviado y/o la constancia de entrega o lectura** como evidencia.
- Proveedores con ingresos anuales ≥ 3000 UIT deben reportar los reclamos al SIREC de Indecopi.
- Indecopi no responde las hojas presentadas al proveedor; solo supervisa.

**Solo fuente secundaria / pendiente de verificar en texto oficial (S):** DS 101-2022-PCM (16/08/2022) como origen del plazo de 15 días hábiles y del art. 6-B para quejas; suspensión del cómputo hasta 5 días hábiles mientras se espera al consumidor (las fuentes difieren); conservación de las hojas por **2 años** desde el registro; versión vigente de la definición de «queja». El intento de leer el DS 101-2022-PCM en El Peruano no devolvió el texto (visor PDF); la URL probada correspondía a otra norma de 2017. **No usar estas cifras como obligación hasta contrastarlas con el texto consolidado.**

**No verificado en esta fase:** obligaciones de la Ley 29733 (protección de datos personales) y su reglamento; régimen de sanciones/multas; definición de «día hábil» aplicable y feriados; si Horus supera el umbral de 3000 UIT; datos de proveedor (razón social/RUC/dirección) — los debe aportar el propietario.

### 4. Arquitectura propuesta (P)
- **Controlador público** `ReclamacionPublicaController` (`store`) con `StoreReclamacionRequest`; `GET` sigue sirviendo la vista (con `@csrf`, `action`, reposición de entrada con `OldInput`).
- **Servicio** `GestionReclamaciones` (crear, cambiar estado, asignar, nota, responder), transaccional, con auditoría como última escritura.
- **Panel** `Admin\ReclamacionController` con `HasMiddleware` por permiso, ruta con UUID (`reclamaciones/{reclamacion:identificador}`) siguiendo el patrón de cotizaciones.
- **Correo** mediante bandeja propia `reclamacion_notificaciones` (patrón de matrículas), HTTP fuera de transacción, job que lleva solo el ID, deshabilitado por flag; mailer elegido por decisión pendiente (D-6).
- **Modelo** `Reclamacion`, `ReclamacionHistorial`, `ReclamacionNotificacion`; sin `SoftDeletes` ni eliminación desde el panel (conservación legal).
- Flag de habilitación `reclamaciones.enabled` (apagado por defecto durante el desarrollo; **al activarse reemplaza al formulario simulado**, no coexiste con él).

### 5. Diseño de base de datos (P, PostgreSQL con equivalente SQLite)
**`reclamaciones`**: `id` bigserial; `identificador` uuid unique (único dato público/ruta); `numero` varchar(20) unique (`LR-AAAA-NNNNNN`); `tipo` varchar(10) CHECK in (reclamo, queja); `estado` varchar(20) CHECK in (recibido, en_atencion, respondido, cerrado), default recibido; `recibida_at` timestamptz; `fecha_limite` date (cálculo por días hábiles, ver D-4); consumidor: `nombres` varchar(120), `apellidos` varchar(120), `tipo_documento` varchar(12) CHECK, `numero_documento` varchar(15), `email` varchar(254), `telefono` varchar(30), `domicilio` varchar(255) null, `es_menor` boolean, `representante_nombres`/`representante_documento` null (obligatorios por CHECK si `es_menor`); bien/servicio: `area` varchar(30), `descripcion_bien` text, `monto_bien` numeric(12,2) null CHECK ≥ 0, `fecha_incidente` date CHECK ≤ recibida; hechos: `detalle` text (≤ 3000), `pedido` text (≤ 1500, obligatorio); consentimientos: `politica_aceptada_at` timestamptz not null, `acepta_comunicaciones` boolean; respuesta: `respuesta` text null, `respondida_at` timestamptz null, `respondida_por` fk users nullOnDelete, `respuesta_canal` varchar(10) null; gestión: `responsable_id` fk users nullOnDelete; `revision` int ≥ 1; idempotencia: `envio_clave_hash` varchar(64) unique, `envio_huella` varchar(64); `timestampsTz`. CHECK de coherencia: `respuesta` y `respondida_at` ambos nulos o ambos presentes; estado respondido/cerrado exige respuesta.
- Índices: unique(`numero`), unique(`identificador`), (`estado`,`id`), (`fecha_limite`,`id`) parcial para estados abiertos en PostgreSQL, (`responsable_id`,`id`), (`recibida_at`,`id`), (`numero_documento`) para búsqueda exacta. Búsqueda por número exacto o prefijo; sin `LIKE '%…%'` sobre datos personales.
- Se evita deliberadamente: columna «importe reclamado» separada del «monto del bien» (la norma pide monto del bien o servicio), IP almacenada en la fila (queda en `audit_logs`), datos de proveedor por reclamo (viven en configuración).

**`reclamacion_contadores`**: `anio` smallint PK, `ultimo` int. Se incrementa con `lockForUpdate` dentro de la misma transacción que inserta la reclamación → numeración **correlativa sin huecos** (si la transacción revierte, el número no se consume). Se descarta `SEQUENCE` (deja huecos al revertir) y `MAX()+1` (carrera). Equivalente en SQLite (el bloqueo lo da la transacción; la concurrencia real solo se prueba en PostgreSQL).

**`reclamacion_historial`** (patrón `cotizacion_historial`): `id`; `reclamacion_id` fk RESTRICT; `revision` int; `evento` CHECK (registrada, estado_modificado, responsable_asignado, nota_interna, respuesta_registrada, correo_confirmacion_enviado, correo_respuesta_enviado, correo_fallido); `actor_id` fk users nullOnDelete **más `actor_nombre` snapshot** (evita la pérdida de autoría de A-01); `metadatos` json (solo claves y estados, sin datos personales); `nota` text null; `created_at` timestamptz; unique(`reclamacion_id`,`revision`). Solo inserción (sin UPDATE/DELETE desde la aplicación).

**`reclamacion_notificaciones`**: `id`; `reclamacion_id` fk RESTRICT; `tipo` CHECK (confirmacion, aviso_interno, respuesta); `delivery_key` uuid unique; `status`, `attempts`, `uncertain`, `payload` cifrado, `available_at`, `dispatched_until`, `lease`, `provider_id`, `last_error` varchar(40), timestamps; unique(`reclamacion_id`,`tipo`). Dos tablas principales + historial se justifican: el historial es append-only y la bandeja tiene ciclo de vida propio.

**Concurrencia:** numeración con fila de contador bloqueada; creación idempotente por `envio_clave_hash` (nonce de formulario guardado en sesión) con reconciliación ante `UniqueConstraintViolation` como en cotizaciones; cambios de gestión con `lockForUpdate` + `If-Match`/`revision`. **Reversibilidad:** `down()` con `dropIfExists` en orden inverso de dependencias; la migración de permisos conserva asignaciones (como `000028`). Sin migraciones creadas en esta fase.

### 6. Endpoints (P)
Públicos: `GET /libro-reclamaciones` (existente); `POST /libro-reclamaciones` (`reclamaciones.store`, flag, `throttle:reclamaciones`). Respuesta `201` JSON `{numero, tipo, recibida_at, fecha_limite}` o redirección con mensaje; sin consulta pública por número (evita IDOR/enumeración; ver D-7).
Panel (`/admin`, rol staff + permiso): `GET reclamaciones` (lista/búsqueda/filtros), `GET reclamaciones/{uuid}`, `PATCH …/estado`, `PATCH …/responsable`, `POST …/notas`, `POST …/respuesta`, `GET …/historial`.

### 7. Matriz de permisos (P, mínimo privilegio; no se modificó ningún permiso)
Permisos nuevos (constante en el seeder **y** migración que los crea y concede solo a `admin`, sin resincronizar): `reclamaciones.ver`, `reclamaciones.gestionar` (estado, responsable, notas), `reclamaciones.responder`.
| Rol | ver | gestionar | responder |
|---|---|---|---|
| admin | sí | sí | sí |
| coordinador | no (D-3) | no | no |
| secretaria | no | no | no |
Ampliar a otros roles queda como decisión del propietario (D-3), asignable después desde Roles. El responsable debe ser un usuario con `reclamaciones.ver` (no la comprobación por nombre de rol usada en cotizaciones).

### 8. Seguridad y privacidad (P salvo indicación)
- **Servidor:** FormRequest con límites de longitud, listas cerradas (tipo, área, tipo de documento), validación de documento por tipo (DNI 8 dígitos, etc.), fecha de incidente ≤ hoy, `es_menor` ⇒ representante obligatorio, `pedido` obligatorio, aceptación de política obligatoria. Rechazar HTML/URLs en campos cortos.
- **CSRF:** el formulario real debe llevar `@csrf` (hoy no lo tiene, C). **Throttle:** limitador nombrado `reclamaciones` (por IP y por correo normalizado; p. ej. 3/10 min y 10/día por IP, 3/día por correo — cifras a validar). **Spam:** campo trampa (honeypot) + marca de tiempo firmada con tiempo mínimo; CAPTCHA de terceros solo con aprobación (D-8). Recordar N-07: sin `trustProxies` el límite por IP puede ser inexacto tras un proxy.
- **IDOR:** panel con binding por UUID y permiso por acción; el `id` numérico nunca sale del servidor; no hay consulta pública.
- **XSS:** salida Blade escapada; correos en texto plano o HTML escapado; el resumen JS ya escapa. **SQLi:** solo Eloquent/bindings; la búsqueda no concatena SQL.
- **Logs:** no registrar el cuerpo de la petición ni la hoja; auditoría con listas permitidas (solo **nombres** de campos y estados, nunca valores personales ni texto libre), como `AuditoriaAcademica`. `SensitiveData::redact` enmascara solo por nombre de clave: no basta.
- **Auditoría de accesos:** registrar `reclamacion_consultada` al abrir el detalle (decisión D-9 sobre frecuencia), y escrituras `reclamacion_registrada`, `…_estado`, `…_responsable`, `…_nota`, `…_respondida`, con actor explícito (público: `user_id` nulo). Fallo de auditoría revierte la operación.
- **Correo:** destinatario solo el indicado por el consumidor; aviso interno sin datos personales; el copy de la hoja al consumidor es obligatorio (O) y se envía solo a su propio correo; cabeceras sin inyección (rechazar CR/LF).
- **Retención:** conservar las hojas el plazo legal (2 años según fuente secundaria, D-5); sin eliminación desde el panel; purga posterior solo por proceso aprobado. Derechos ARCO por el canal de contacto existente.
- **Privacidad:** la política actual no cubre este tratamiento (finalidad comercial, retención indefinida). Requiere texto específico: finalidad = atención del reclamo, base legal y plazo; separar `acepta_comunicaciones` de cualquier uso comercial. Modificar la política es contenido público protegido: necesita autorización expresa (D-2).
- **Mínimo de datos:** sin IP en la fila, sin dirección obligatoria, documento de identidad solo el exigido por la norma.

### 9. Panel administrativo (P)
Lista paginada (patrón `cotizaciones/index`): búsqueda por número exacto, filtros por estado, tipo y rango de fechas, columna «vence» con semáforo (vencida / ≤ 3 días hábiles / en plazo — umbral a definir), responsable. Detalle con datos del consumidor, bien, hechos, pedido, historial cronológico, formulario de nota interna, asignación y respuesta (texto + confirmación explícita de envío). Estados y transiciones: `recibido → en_atencion → respondido → cerrado`; responder exige respuesta no vacía; `cerrado` solo desde `respondido`; no se retrocede (correcciones como nota). `fecha_limite` visible; la alerta de vencimiento en la UI es informativa. Entrada en sidebar con `@can('reclamaciones.ver')`.

### 10. Correo y notificaciones (P)
1. **Confirmación al consumidor** (obligatoria por la copia de la hoja): número, tipo, fecha, contenido de la hoja, plazo informado, datos del proveedor. Se encola en la misma transacción de creación (bandeja), nunca bloquea el registro.
2. **Aviso interno:** «nueva reclamación LR-… (reclamo/queja)», sin datos personales, a una dirección configurada.
3. **Respuesta:** se registra primero en BD; el correo sale por bandeja con `delivery_key`; se conserva `provider_id`/estado como constancia (O exige conservar el correo/constancia).
4. **Reintentos:** hasta 4, con retroceso, lease y ventana de primer intento (patrón matrículas). Fallo no revierte el registro; `correo_fallido` queda en historial y la UI muestra «correo pendiente/fallido» para que el responsable use otro canal. Sin duplicados por unique(`reclamacion_id`,`tipo`) y `Idempotency-Key`. Si el correo está deshabilitado, las filas quedan retenidas (`held`) como en Sprint 15-B. **No se envió ningún correo.**

### 11. Plan de pruebas (P, no ejecutado)
**SQLite en memoria (suite habitual):** presentación válida (201, persistencia, número `LR-AAAA-NNNNNN`, historial, auditoría, bandeja con correo falso); validaciones (cada regla, menor sin representante, pedido ausente, tipo inválido, fecha futura, longitudes); CSRF; throttle; honeypot/tiempo mínimo; idempotencia por nonce; atomicidad (fallo de auditoría o de bandeja revierte todo; `failAudit()`); permisos por ruta y acción (admin sí; coordinador/secretaria 403; invitado redirige); IDOR (UUID ajeno/inexistente 404; sin fuga del `id`); transiciones de estado válidas e inválidas; revisión/ETag obsoleto 412/409; respuesta (requiere permiso, texto, bandeja, historial); fallos de correo (reintentos, duplicados, deshabilitado ⇒ retenido); privacidad (auditoría y logs sin valores personales, correo interno sin datos, `SensitiveData`); XSS (cargas `<script>` escapadas en panel y correo); `Http::fake` bloqueando HTTP real; migraciones (instalación nueva y base ya sembrada; `down()`); semilla de permisos sin resincronizar.
**Solo PostgreSQL (perfil aislado 55433, con autorización):** generación **concurrente** de números (N hilos ⇒ N números distintos, correlativos, sin huecos); CHECK/índices parciales reales; `lockForUpdate` entre responder y cambiar estado; reconciliación de `UniqueConstraintViolation` bajo carrera; rendimiento de lista con 100 000 filas.
**Navegador (requiere autorización de frontend):** flujo de 3 pasos, accesibilidad (aria-live, foco, errores), contraste, móvil.

### 12. Archivos que se tocarían en la implementación (P)
Nuevos: 2 migraciones, `Reclamacion`, `ReclamacionHistorial`, `ReclamacionNotificacion`, `ReclamacionPublicaController`, `Admin\ReclamacionController`, `StoreReclamacionRequest`, `GestionarReclamacionRequest`, `GestionReclamaciones`, `AuditoriaReclamaciones` (o ampliación de `AuditoriaAcademica`), `ReclamacionNotificationService`, job y mailables, `config/reclamaciones.php`, vistas `admin/reclamaciones/*`, pruebas. Modificados: `routes/web.php`, `AppServiceProvider` (limitador), `RolesAndPermissionsSeeder` (constante), sidebar, `.env.example`, `AGENTS.md`/`CLAUDE.md` si procede, `PROJECT_CONTEXT.md`. **Con autorización específica de frontend/contenido público:** `libro-reclamaciones.blade.php`, `libro-reclamaciones.js`, `libro-reclamaciones.css`, política de privacidad, aviso Anexo II.

### 13. Migraciones previstas (no creadas)
`2026_10_30_000030_create_reclamaciones_tables` (reclamaciones, contadores, historial, notificaciones) y `2026_10_30_000031_add_reclamaciones_permissions`. Los números 000030/000031 estaban reservados al Sprint 19.2 suspendido (no existen en disco); se pueden renumerar sin costo porque ninguna está aplicada. Mientras `000022` siga pendiente, no ejecutar `migrate` sin `--path`. Revisar con revisión previa antes de PostgreSQL real.

### 14. Riesgos
Incumplir el plazo de 15 días hábiles (infracción) si el panel es solo admin y nadie vigila; formulario público actual induce a error mientras siga simulado (hasta el despliegue de la implementación); política de privacidad incoherente con este tratamiento; falta de datos del proveedor en la hoja; dependencia del correo para cumplir la «copia» y la respuesta escrita; spam/mail-bombing vía confirmaciones (N-35); límite por IP impreciso sin `trustProxies` (N-07); modificación de frontend protegido; cifras legales aún no contrastadas con texto oficial.

### 15. Decisiones pendientes del propietario
D-1 Datos del proveedor para la hoja (razón social, RUC, dirección, código de libro) y si supera 3000 UIT (SIREC). D-2 Autorización para tocar la vista/JS/CSS públicos y la política de privacidad. D-3 Qué roles tendrán `reclamaciones.ver/gestionar/responder`. D-4 Calendario de feriados y cálculo de días hábiles. D-5 Plazo de conservación (confirmar texto oficial) y política de purga. D-6 Proveedor de correo (Resend como matrículas u otro) y direcciones remitente/aviso interno. D-7 Si el consumidor podrá consultar el estado (recomendado: no, solo por correo). D-8 CAPTCHA o solo honeypot/throttle. D-9 Frecuencia de auditoría de lecturas. D-10 Libro físico de respaldo y Aviso Anexo II (responsabilidad del negocio). D-11 Revisión legal profesional del texto final.

### 16. Próxima fase
Fase 2 (solo con aprobación): migraciones, modelos y servicio con pruebas en SQLite, sin tocar frontend. Orden sugerido: 2 datos/servicio/auditoría → 3 endpoint público tras flag → 4 panel → 5 correo → 6 frontend (autorización) → 7 pruebas PostgreSQL (autorización). La Fase 2c (auditoría de matrículas) sigue pendiente y no autorizada.


---

## SPRINT 20 — LIBRO DE RECLAMACIONES: FASE 1.1, DISEÑO CORREGIDO

**Fecha:** 2026-10-30. **Naturaleza:** revisión de diseño. No se modificó código, vistas, rutas, migraciones, permisos, seeders ni configuración; no se ejecutó Artisan, PHPUnit ni PostgreSQL; no se envió ningún correo. Solo se actualizó este archivo. **Esta sección corrige y prevalece sobre la «Fase 1, diseño técnico» anterior** donde difieran (la sección anterior se conserva íntegra como historial).
Niveles de evidencia: **O** = texto oficial leído en esta fase; **S** = fuente secundaria; **P** = propuesta de diseño; **C** = comprobado en el código; **NV** = no verificado.

### 1. Fuentes oficiales efectivamente leídas
- **DS 011-2011-PCM** en su texto concordado (cdn.www.gob.pe, Decreto_Supremo_N_011-2011-PCM.pdf), que incorpora las notas de modificación de **DS 006-2014-PCM** (23/01/2014) y **DS 058-2017-PCM** (29/05/2017).
- **DS 101-2022-PCM**: texto de los artículos modificados transcritos en el sumario de normas legales de El Peruano del 16/08/2022 (repositorio SBN, NORMA_LEGAL_3258). **El Anexo I modificado no viene transcrito**: su formato exacto queda NV.
- **Ley 31435** (Congreso, texto consolidado; publicada 22/03/2022): art. 24.1 y art. 152 del Código.
- **Ley 32495** (texto reproducido por Actualidad Civil citando El Peruano 2457203-1; publicada 11/11/2025): art. 150 y 151 del Código.
- **RM 244-2026-PCM** (busquedas.elperuano.pe, publicada 18/07/2026) y nota de El Peruano del 18/07/2026.
- **DS 016-2024-JUS** (Reglamento de la Ley 29733; PDF de gob.pe/ANPD; publicado 30/11/2024; vigente a partir de los 120 días calendario).
- Indecopi, «Preguntas y respuestas — Obligatoriedad y formato del Libro de Reclamaciones», 12/11/2025.
- **NV (no leídos):** texto de la Ley 29733 y su art. 14 (excepciones al consentimiento); Anexos I, II y III vigentes; texto del proyecto de decreto que acompaña la RM 244-2026-PCM; definición de «día hábil» aplicable y feriados; Directiva SIREC.

### 2. Matriz legal corregida (12 puntos)
| # | Tema | Resultado verificado | Nivel |
|---|---|---|---|
| 1 | Conservación | Mínimo **2 años** desde la presentación (art. 12 modificado por DS 006-2014): (i) hojas físicas o virtuales; (ii) información del SIREC cuando corresponda; (iii) **constancia de la respuesta** (la «ampliación del plazo» quedó sin objeto desde la Ley 31435). Es un mínimo; el DS no fija máximo. | O |
| 2 | Suspensión | Solo en el supuesto de **solución ofrecida de manera no presencial** (art. 6-A.2.b, DS 058-2017 mod. DS 101-2022): el envío del ofrecimiento suspende el cómputo por **máximo 5 días hábiles** (antes 10 días calendario). Si el consumidor no acepta expresamente en ese plazo, la propuesta se tiene por rechazada y subsiste la obligación de respuesta formal. La aceptación debe ser expresa («ACUERDO ACEPTADO PARA SOLUCIONAR EL RECLAMO» u otra frase equivalente) por correo u otro medio con constancia documental. Aplica a **reclamos**. | O |
| 3 | Reclamo vs queja | Reclamo (art. 3.3): disconformidad con bienes o servicios. Queja (art. 3.4, texto DS 101-2022): disconformidad no relacionada con el bien o servicio, o malestar con la atención al público; no inicia procedimiento sancionador. Si el detalle evidencia un reclamo aunque se marcó «queja» (o nada), **se tramita como reclamo** (art. 6). Ambos con respuesta en 15 días hábiles (art. 6 y 6-B). | O |
| 4 | Datos del consumidor y del menor | Mínimo (art. 5): nombre, **domicilio**, documento de identidad, teléfono y correo. Menor de edad: nombre, **domicilio, teléfono y correo** de uno de los padres o representantes. Si no se consigna al menos nombre, DNI, domicilio o correo, fecha y detalle, **se consideran no presentados**. | O |
| 5 | Domicilio | Obligatorio (art. 5). **El diseño anterior lo proponía opcional: se corrige a obligatorio.** | O |
| 6 | Monto | «Monto del producto o servicio contratado objeto del reclamo» (art. 5), además de la «identificación del producto o servicio contratado». Para una queja sin bien o servicio asociado el Anexo no se leyó: ver D-5. | O / NV |
| 7 | Pedido concreto | «Pedido concreto del consumidor respecto al hecho que motiva el reclamo o queja» (art. 5). El formulario actual no lo recoge. | O |
| 8 | Copia de la hoja virtual | Art. 4 (DS 006-2014): imprimir gratuitamente la hoja; art. 4-B (proveedores virtuales): al concluir el registro se debe **permitir imprimir y enviar automáticamente** la hoja al correo indicado. **Se corrige:** el envío es automático, no solo «si se solicita». | O |
| 9 | Libro físico de respaldo | Art. 4-A: quien tiene libro virtual debe tener **en sus establecimientos comerciales** un «Libro de Reclamaciones de Respaldo» físico, rotulado así, para cuando no sea posible usar el virtual; su contenido (incl. número de hoja) debe ingresarse al virtual en **1 día calendario** desde que este se habilite. Indecopi (12/11/2025): el virtual no obliga a tener físico propio como sustituto, pero sí el de respaldo. | O |
| 10 | Aviso y enlace | Art. 9 (DS 006-2014): los proveedores virtuales deben colocar **como mínimo un aviso fácilmente identificable en la página de inicio** del portal, con vínculo al libro virtual, usando el formato del **Anexo III** (no leído, NV). Art. 4-B: el libro virtual debe estar **alojado en la página de inicio**. Ley 32495 (art. 151): plataformas digitales de comercio electrónico exhiben **permanentemente** el enlace en lugar visible y accesible. C: hoy existe un enlace «Libro de Reclamaciones» en el pie de página (`components/footer.blade.php`); la página de inicio no se auditó para el aviso del Anexo III. | O / C / NV |
| 11 | Plazo | **15 días hábiles improrrogables** (Ley 31435, art. 24.1 y art. 152; DS 101-2022 arts. 6 y 6-B), por **comunicación escrita** (carta y/o correo según solicitó el consumidor). Ya no existe ampliación. La atención no puede condicionarse a un pago (art. 6). | O |
| 12 | Evidencia de respuesta | Conservar la constancia de la respuesta (art. 12 iii); Indecopi: conservar el correo enviado y/o la constancia de entrega o lectura. «y/o»: el correo enviado basta; la constancia de entrega/lectura es adicional. | O |
Otros hechos verificados: la respuesta informa las acciones adoptadas y fundamenta si no se acepta el pedido (Indecopi); reclamos de proveedores con ingresos ≥ 3000 UIT se reportan al SIREC (Indecopi); Indecopi puede pedir copias de las hojas (Ley 31435 art. 152; DS 011 art. 11); acuerdo aceptado = concluye el reclamo y libera de la respuesta formal (art. 6-A.3, DS 058-2017); pérdida o extravío de hojas: denuncia policial en 48 h (art. 12). Sanciones: art. 6-A.4 remite a los arts. 106.b y 110 del Código; **cuantías NV**.
**Recomendaciones técnicas (no son obligación legal):** número correlativo continuo sin huecos; historial inmutable; auditoría de accesos; plazo de purga posterior a los 2 años; aviso interno en el panel; límites de velocidad y honeypot.

### 3. Actualización normativa 2025-2026
- **Ley 32495 (vigente, publicada 11/11/2025):** el art. 150 del Código obliga a establecimientos comerciales **y a toda plataforma digital de comercio electrónico** a contar con libro de reclamaciones (físico o virtual); art. 151: aviso en establecimientos y **enlace permanente** en plataformas. Disposición final: el Ejecutivo adecuará el reglamento en 30 días calendario (plazo vencido sin reglamento definitivo localizado).
- **RM 244-2026-PCM (18/07/2026):** solo **dispone la publicación de un proyecto** de decreto supremo para recibir comentarios durante 15 días calendario. **No es norma vigente.** Según fuentes secundarias (Gestión, Cuatrecasas, El Peruano) el proyecto propone: definición de plataforma digital, enlace visible y permanente sin exigir registro/autenticación/compra, formulario enviado automáticamente al correo como constancia, estándares mínimos de formulario (móvil/tablet/PC) y posibilidad de adjuntar archivos. **No se encontró norma definitiva posterior** (búsqueda hasta 2026-10-30); debe revisarse de nuevo antes de la implementación y antes de activar el módulo. El diseño debe ser compatible con esas propuestas sin depender de ellas (p. ej. acceso sin login: ya previsto; adjuntos: no se implementan, ver D-9).
- **Ámbito de Horus Group SRL (NV, decisión del propietario):** el DS 011 alcanza a proveedores con RUC que desarrollan venta de bienes o prestación de servicios a consumidores en un establecimiento abierto al público o **a través de un medio virtual** (arts. 1, 3.2, 3.6, 4). Horus tiene RUC (a confirmar), ofrece servicios y cursos y mantiene dirección en Cajamarca; el sitio ofrece información, registro de usuarios, asistente virtual y (suspendida) recepción de cotizaciones, y el asistente indica pagos por transferencia/comprobantes. **Hipótesis razonable: está comprendido** (como mínimo por su establecimiento y por usar el sitio para relacionarse con consumidores). Si no hay venta en línea, la categoría «plataforma digital de comercio electrónico» de la Ley 32495 podría no aplicar, pero el DS 011 sigue vigente. No se asume exención. Requiere confirmación del propietario y, preferiblemente, asesoría legal (D-1).

### 4. Flujo administrativo corregido (P)
Se eliminan `en_atencion` y `cerrado`. Un caso tiene solo **tres estados persistidos**:
- `abierto`: registrado y pendiente de respuesta formal (incluye «en atención» y «con propuesta enviada»; esos matices salen del responsable, de las columnas de plazo y de los eventos del historial).
- `respondido`: se envió la **respuesta formal** escrita (art. 6 / 6-B). Terminal.
- `concluido_por_acuerdo`: el consumidor aceptó expresamente una propuesta de solución (art. 6-A.3). Terminal; **distinto** de `respondido`: no existe respuesta formal ordinaria y las dos cosas nunca se confunden ni se registran con el mismo evento.
Eventos del historial (no son estados): `registrada`, `responsable_asignado`, `tipo_reclasificado` (queja marcada que es reclamo), `nota_interna`, `propuesta_enviada` (solo reclamos; inicia suspensión), `propuesta_aceptada` (el personal registra la constancia del correo del consumidor y su fecha de recepción), `propuesta_rechazada`, `propuesta_vencida` (pasaron 5 días hábiles sin aceptación expresa), `respuesta_formal_registrada`, `correo_enviado`, `correo_fallido`, `hoja_respaldo_registrada`.
Plazos (columnas en `reclamaciones`): `fecha_limite` (recibida + 15 días hábiles), `suspension_desde` y `suspension_hasta` (nulas salvo con propuesta pendiente; `hasta` = envío + 5 días hábiles). Al aceptar → `concluido_por_acuerdo`, sin reanudación. Al rechazar o vencer → se reanuda y `fecha_limite` se desplaza por los días hábiles efectivamente suspendidos (máx. 5). El vencimiento se evalúa de forma perezosa al leer o escribir el caso (más un comando programado opcional, D-8); no hay procesamiento de correo entrante: el personal pega/registra la constancia de aceptación (recomendado: no portal ni lectura de buzón). Transiciones permitidas: `abierto → respondido`, `abierto → concluido_por_acuerdo` (solo con propuesta registrada y aceptada), nada después. Una propuesta a la vez; solo reclamos. El cálculo de días hábiles usa una lista de feriados en configuración; **NV** la definición legal de «día hábil» y los feriados (D-4).
Registrar una respuesta formal exige texto, permiso `reclamaciones.responder` y crea el envío; si el correo falla, el caso **sigue `abierto`**: el estado pasa a `respondido` solo cuando el envío figura `enviado` (la evidencia legal es el correo enviado) y la UI avisa del plazo mientras esté pendiente (D-6 si se prefiere otro criterio).

### 5. Modelo de datos simplificado (P; no se crean tablas ni migraciones)
Se mantienen **cuatro tablas**, con columnas recortadas. Ninguna duplica infraestructura existente (ver §7).
1. **`reclamaciones`** (entidad principal): `id`; `identificador` uuid único (ruta del panel); `numero` único; `canal` (`web` | `respaldo_fisico`) y `numero_hoja_fisica` (solo respaldo, art. 4-A); `tipo` (reclamo/queja); `estado` (3 valores); `recibida_at`; `fecha_limite`; `suspension_desde/hasta`; consumidor: `nombres`, `apellidos`, `tipo_documento`, `numero_documento`, `domicilio` (**obligatorio**), `telefono`, `email`; menor: `es_menor` + `representante_nombre`, `representante_domicilio`, `representante_telefono`, `representante_email` (obligatorios por CHECK si es menor); bien: `bien_identificacion` (obligatorio), `monto_bien` numeric(12,2) (nulo solo con motivo permitido, D-5), `fecha_incidente`; `detalle`; `pedido` (obligatorio); `respuesta`, `respondida_at`; `responsable_id` (users, nullOnDelete); `privacidad_version` y `informado_at`; `token_envio` único (nonce del formulario, idempotencia); `timestampsTz`. **Se eliminan frente al diseño anterior:** `revision`/ETag, `envio_clave_hash/huella`, `respuesta_canal`, `respondida_por` (el actor queda en el historial), `acepta_comunicaciones`, `politica_aceptada_at` como consentimiento comercial, `area` separada (va dentro de `bien_identificacion` o una lista cerrada, a definir), `en_atencion` y `cerrado`. CHECK: tipo, estado, documento por tipo, menor⇒representante completo, `respuesta` nula salvo estado `respondido`, `suspension_hasta ≥ suspension_desde`.
2. **`reclamacion_contadores`**: una sola fila (clave fija), `ultimo` int; se bloquea e incrementa en la misma transacción del alta → numeración correlativa **continua sin huecos** (`LR-000001`; sin reinicio anual, más simple y sin ambigüedad). Alternativas descartadas: `SEQUENCE` (huecos al revertir), `MAX()+1` (carrera), `pg_advisory_lock` (solo PostgreSQL, no probable en SQLite). Se justifica porque la norma exige numeración correlativa.
3. **`reclamacion_historial`**: solo inserción; `reclamacion_id` (RESTRICT), `evento`, `actor_id` (nullOnDelete) + `actor_nombre` (copia, evita A-01), `metadatos` json (solo claves/estados/fechas, sin datos personales), `nota` text, `created_at`. Se justifica: la auditoría general (`audit_logs`) borra el actor al eliminar la cuenta, no admite texto libre ni datos personales y no es la evidencia de caso que exige el art. 12(iii). Se elimina `revision` única: el orden es el `id`; la concurrencia se resuelve con `lockForUpdate` y relectura de estado.
4. **`reclamacion_envios`** (sustituye a `reclamacion_notificaciones`, mucho más simple): `reclamacion_id`, `tipo` (`copia_hoja`, `propuesta`, `respuesta`), `clave` uuid único, `estado` (`pendiente`/`enviado`/`fallido`), `intentos`, `asunto`, `cuerpo` (texto exacto enviado: es la constancia del art. 12), `enviado_at`, `proveedor_id`, `error_codigo` varchar(40), timestamps; única `(reclamacion_id, tipo)` salvo `propuesta` (varias permitidas solo una vigente). Se justifica por ser a la vez bandeja de salida y **evidencia conservable 2 años**; no se puede guardar en el historial (que es inmutable) ni en `matricula_notifications` (ver §7).
**Tablas que NO se crean:** estados de propuesta, adjuntos, feriados (config), tokens de consulta pública, auditoría propia.
**Índices:** únicos en `identificador`, `numero`, `token_envio`; `(estado, fecha_limite)`; `responsable_id`; `recibida_at`; `numero_documento` (búsqueda exacta). Sin `LIKE '%…%'` sobre datos personales. **Reversibilidad:** `down()` con `dropIfExists` en orden inverso.

### 6. Permisos (P; no se modificó ningún permiso ni seeder)
Permisos nuevos (constante en el seeder **y** migración que los crea sin resincronizar): `reclamaciones.ver`, `reclamaciones.gestionar`, `reclamaciones.responder`.
| Operación | Permiso |
|---|---|
| Listar, buscar, ver detalle e historial, imprimir la hoja | `ver` |
| Asignar responsable, reclasificar tipo, notas, registrar hoja de respaldo físico, enviar propuesta, registrar aceptación/rechazo | `gestionar` |
| Registrar y enviar la **respuesta formal**; concluir por acuerdo | `responder` |
| Editar o borrar datos del consumidor, borrar casos, purgar | **nadie** desde el panel (correcciones como nota; purga solo por proceso aprobado) |
| Roles | admin | coordinador | secretaria |
|---|---|---|---|
| **Valor técnico por defecto de la migración (hasta decisión)** | ver, gestionar, responder | ninguno | ninguno |
| **Recomendación** | ver, gestionar, responder | ver, gestionar, responder (continuidad: el plazo de 15 días hábiles es improrrogable y una sola persona es un punto de fallo) | ver únicamente, y solo si atiende el libro físico de respaldo (de lo contrario ninguno): acceso reducido por contener datos personales |
Todo cambio de privilegios es **decisión del propietario** (D-2); la migración concedería solo a `admin`. El responsable asignable debe tener `reclamaciones.ver` (no se reutiliza la comprobación por nombre de rol de cotizaciones). Revisión semestral de privilegios sobre este módulo (DS 016-2024-JUS art. 46).

### 7. Notificaciones: infraestructura existente y arquitectura
Inspección (C): `matricula_notifications` + `MatriculaNotificationService` + `DispatchMatriculaNotifications` + `SendMatriculaWelcome` + `config/matricula-mail.php`: **atados a `matricula_id` único**, con política de retención/ventana de 23-24 h y cliente HTTP de Resend; no son genéricos y están protegidos por pruebas. `NotificacionInterna` (`notificaciones_internas`: `user_id`, `titulo`, `mensaje`, `tipo`, `leida`, `vinculo`) sí es reutilizable para el aviso interno. No existe bandeja genérica de salida ni webhooks de entrega.
Decisión (P): **no clonar** el servicio de matrículas ni generalizarlo (riesgo sobre un flujo ya probado).
- **Aviso interno:** una `NotificacionInterna` por cada usuario con `reclamaciones.ver`, con título «Nueva reclamación LR-…» y enlace; sin datos personales; sin correo interno.
- **Correos al consumidor** (copia de la hoja — obligatoria y automática —, propuesta, respuesta): cada uno es una fila de `reclamacion_envios` creada **en la misma transacción** que el hecho; después de confirmar se despacha un job de la cola de base de datos que lleva solo el ID. El job reserva la fila con `UPDATE … WHERE estado='pendiente'` atómico, envía con el mailer de Laravel (transporte Resend ya instalado), guarda `enviado_at`, `proveedor_id` y el texto enviado, y reintenta con retroceso (máx. 4). Un fallo no revierte el caso; se registra `correo_fallido` y la UI muestra «correo pendiente». Sin duplicados por unicidad `(reclamacion_id, tipo)`; si el proceso muere tras enviar y antes de guardar, podría duplicarse un correo (aceptable: no hay efecto legal adverso; se documenta).
- **Evidencia de entrega:** basta el correo enviado más su texto y el identificador del proveedor (Indecopi: «y/o»). Constancia de entrega/lectura mediante webhook del proveedor: **no** se implementa en la primera versión (D-6).
- **Activación:** flag propio `reclamaciones.enabled`, apagado por defecto; **no puede activarse** mientras el correo no esté configurado de verdad (mailer distinto de `log`/`array`, remitente válido), porque sin la copia automática el libro virtual incumpliría el art. 4-B; en su lugar quedaría el libro físico. Sin envío real durante pruebas (`Http::fake`/`Mail::fake`).
- **Privacidad del proveedor de correo:** Resend es un tercero (encargado, posiblemente fuera del país): ver §8.

### 8. Privacidad (Ley 29733 y DS 016-2024-JUS)
**Verificado (O) en el Reglamento:** el consentimiento debe pedirse antes de la recopilación (art. 4) y ser expreso e inequívoco (art. 5); **publicar una política de privacidad no exonera del consentimiento** (art. 7) salvo que aplique una excepción de la Ley; la carga de la prueba del consentimiento recae en el responsable (art. 9); el titular puede negar o revocar el consentimiento para **finalidades adicionales** sin afectar la relación que originó el tratamiento (art. 10.2); transferencias y flujo transfronterizo exigen consentimiento/garantías y deben informarse (arts. 13, 18); quien cree, modifique o cancele bancos de datos debe **inscribirlos** en el Registro Nacional (art. 42.1; trámite gratuito, art. 43.3); seguridad en medios digitales: control de accesos, revisión de privilegios al menos **semestral**, y registros de interacción lógica conservados **mínimo 2 años** (art. 46); la supresión no procede cuando los datos deban conservarse por obligación legal o contractual, y debe anonimizarse/disociarse cuando sea posible (arts. 84-85).
**Aplicación al libro (P):**
- **Finalidad:** exclusivamente registrar, atender, responder y conservar el reclamo/queja y cumplir el DS 011-2011-PCM (requerimientos de Indecopi). Nada de marketing.
- **Base jurídica:** el DS 011 obliga a recoger y conservar los datos del Anexo I; la excepción de la Ley 29733 aplicable (art. 14) **NV**: no se leyó la Ley. Hasta contar con opinión legal, el formulario mostrará un aviso informativo específico (finalidad, responsable, plazo, derechos, encargados) y **una casilla de aceptación del tratamiento para esta finalidad solo si el asesor lo exige** (D-3). **Nunca** habrá una casilla de comunicaciones comerciales en este formulario ni será requisito para presentar el reclamo (se retira `acepta_comunicaciones`). Cualquier suscripción comercial sería un flujo separado y opcional.
- **Corrección a la política actual (C):** la política de privacidad vigente declara fines comerciales y conservación «hasta que decida eliminarlo»: es incompatible con este tratamiento. Hace falta una sección específica o una política propia del libro (contenido público protegido: requiere autorización, D-7).
- **Datos estrictamente necesarios:** solo los del Anexo I; sin IP en la fila, sin adjuntos, sin datos sensibles; el campo libre «detalle» se limita en longitud y se advierte al usuario de no incluir datos sensibles.
- **Conservación:** mínimo 2 años desde la presentación (O); el máximo y la purga/anonimización posterior los define el propietario (D-4); entre tanto no hay borrado desde el panel. La conservación indeterminada es contraria al principio de calidad (art. 31.2 prohíbe la indeterminada por regla general para encargados; aplicar el criterio por analogía requiere confirmación legal).
- **Derechos del titular:** canal existente de ARCO (correo de contacto); la solicitud de supresión durante el plazo legal se deniega motivadamente (art. 84).
- **Acceso restringido:** permisos mínimos (§6), auditoría de lecturas y escrituras con listas permitidas de nombres de campo (nunca valores personales), revisión semestral de privilegios, registros de acceso conservados 2 años.
- **Registro de bancos de datos:** verificar si Horus ya inscribió el banco de clientes/usuarios y si el libro de reclamaciones debe inscribirse o actualizar el existente **antes del lanzamiento** (D-3). Estado actual: NV.
- **Encargados y transferencia:** el proveedor de correo recibirá datos personales (la copia de la hoja). Si es extranjero, aplican arts. 13 y 18 (nivel adecuado o garantías), contrato de encargo e información al titular. Alternativa: SMTP propio (D-6).

### 9. Seguridad (mantiene lo ya diseñado, con ajustes)
FormRequest con límites y listas cerradas; `@csrf`; limitador nombrado `reclamaciones` (IP y correo normalizado; cifras a validar; recordar N-07 `trustProxies`); honeypot y marca de tiempo firmada; CAPTCHA de terceros solo con aprobación; idempotencia con `token_envio`; panel con UUID y permiso por acción (sin IDOR); salida escapada; solo Eloquent; sin registrar cuerpos de petición; sin consulta pública del estado (decisión recomendada); respuesta de error genérica ante fallo de correo. Acceso al formulario **sin login, sin registro y sin exigir compra** (art. 6 y proyecto de la RM 244). La hoja impresa y el correo contienen la copia completa solo para el propio consumidor.

### 10. Migraciones y repositorio (comprobado)
- En disco hay 27 archivos en `database/migrations`; el último es `2026_10_29_000029_add_origen_to_solicitudes_cotizacion.php`. Los archivos `000027`, `000028` y `000029` del **Sprint 19.2 suspendido están sin seguimiento (`??`) en Git** y **no se tocan**. No existen `000030` ni `000031`.
- Previstas (no creadas): `2026_10_30_000030_create_reclamaciones_tables` (4 tablas, CHECK/triggers equivalentes en SQLite como en `000027`) y `2026_10_30_000031_add_reclamaciones_permissions` (solo `admin`, sin resincronizar). Dos migraciones bastan; no se prevén más. Si el Sprint 19.2 se reanudara y necesitara esos números, se renumerarían las de reclamaciones antes de aplicarlas (ninguna está aplicada). Mientras `000022` siga pendiente, no ejecutar `migrate` sin `--path`; revisión previa antes de PostgreSQL real.

### 11. Pruebas previstas (resumen; sin ejecutar)
SQLite: registro válido (domicilio, monto, pedido, menor/representante), validaciones, tratamiento de queja mal marcada, numeración correlativa, idempotencia, atomicidad con `failAudit()`, permisos por ruta/rol, IDOR, transiciones (abierto→respondido; abierto→concluido_por_acuerdo solo tras propuesta aceptada; propuesta solo para reclamos), suspensión y reanudación (5 días hábiles, rechazo, vencimiento, feriados), respuesta formal solo si hay envío `enviado`, correo (reintentos, fallo, duplicados, flag y mailer inválido), aviso interno sin datos personales, ausencia de datos personales en auditoría/logs/correo interno, XSS. Solo PostgreSQL (puerto 55433, con autorización): números concurrentes sin huecos ni repetidos, carrera de aceptación vs vencimiento, bloqueos entre responder y proponer. Navegador: flujo de 3 pasos, impresión de la hoja y accesibilidad (con autorización de frontend).

### 12. Riesgos
Ámbito de aplicación no confirmado (D-1); Anexos I-III y Ley 29733 art. 14 no leídos; proyecto de reglamento (RM 244) puede cambiar requisitos antes de la implementación; incumplir 15 días hábiles (improrrogables) por falta de personal con permiso; dependencia de correo para la copia automática (si no hay correo real, el libro virtual no debe activarse); transferencia de datos al proveedor de correo; lectura perezosa de vencimientos puede mostrar estado desactualizado sin comando programado; feriados mal configurados alteran plazos; formulario público actual (simulado) sigue engañando hasta la implementación; política de privacidad incompatible; libro físico de respaldo y aviso Anexo III dependen del negocio.

### 13. Decisiones pendientes del propietario
D-1 Confirmar RUC, razón social, dirección, código del libro, si Horus tiene establecimiento abierto al público, si vende en línea y si supera 3000 UIT (SIREC); asesoría legal sobre ámbito. D-2 Roles con `ver/gestionar/responder` (propuesta en §6; por defecto solo admin). D-3 Base jurídica y casilla de aceptación (asesor), inscripción/actualización del banco de datos en el Registro Nacional, designación del responsable del tratamiento. D-4 Feriados y definición de días hábiles; plazo máximo de conservación y purga/anonimización tras 2 años. D-5 Cómo tratar el «monto» en quejas sin bien o servicio. D-6 Proveedor de correo (Resend con contrato/transferencia vs SMTP propio), remitente, y si se exige constancia de entrega por webhook. D-7 Autorización para tocar vista, JS, CSS, aviso Anexo III en la página de inicio y política de privacidad (contenido público protegido). D-8 Comando programado para vencimientos y aviso por vencer. D-9 Adjuntos (no recomendados en v1). D-10 Libro físico de respaldo y su procedimiento (art. 4-A, 1 día). D-11 Revisión legal profesional del texto final. D-12 Repetir la búsqueda de norma definitiva antes de la implementación.

### 14. Próxima fase
Con aprobación expresa: Fase 2 = migraciones, modelos y servicio con pruebas en SQLite, sin tocar frontend. Orden: 2 datos/servicio/auditoría → 3 endpoint público tras flag → 4 panel → 5 correo → 6 frontend y contenido público (autorización) → 7 pruebas PostgreSQL (autorización). La Fase 2c (auditoría de matrículas) sigue pendiente y no autorizada.


---

## SPRINT 20 — LIBRO DE RECLAMACIONES: FASE 1.2, CIERRE DE DISEÑO

**Fecha:** 2026-10-30. **Naturaleza:** cierre de diseño. No se modificó código, vistas, rutas, migraciones, permisos, seeders ni configuración; no se ejecutó Artisan, PHPUnit ni PostgreSQL; no se envió ningún correo. Solo se actualizó este archivo. **Esta sección corrige y prevalece sobre las fases 1 y 1.1** donde difieran (se conservan íntegras como historial).
Niveles: **O** = texto oficial leído; **S** = secundaria; **C** = comprobado en código; **P** = propuesta; **NV** = no verificado.

### 1. Omisión corregida: Decreto Legislativo 1729
Las fases 1 y 1.1 no lo incluyeron. **Corrección registrada.** Texto leído (O): El Peruano, 12/02/2026, normas legales pp. 9-11 (copia LPDerecho del PDF oficial; el sumario cita 2486266-3).
- **Qué es:** DL 1729, «que modifica la Ley 29571 para fortalecer la atención de reclamos y prevenir y sancionar prácticas comerciales coercitivas en el ámbito del comercio electrónico», dictado al amparo de la Ley 32527.
- **Art. 24.4 (nuevo):** los proveedores que ofrezcan bienes o servicios **a través de plataformas, aplicaciones u otros canales digitales de comercio electrónico** deben contar con mecanismos **accesibles, eficaces y permanentemente operativos** para atender reclamos y resolver controversias derivadas de **dichas transacciones**, que permitan reclamar **de manera directa y sin costos o cargas innecesarios**.
- **Art. 24.5 (nuevo):** proveedores **no domiciliados** en el país que ofrezcan por esos canales dirigidos al mercado peruano y no tengan mecanismos de atención: mantener operativa al menos una dirección de correo electrónico para preguntas, reclamos, sugerencias, cambios o devoluciones. **No aplica a Horus** (domiciliada en Cajamarca).
- **Art. 56.1.h (nuevo):** es método comercial coercitivo emplear prácticas, **particularmente en canales digitales de comercio electrónico**, que mediante el diseño, configuración o funcionamiento de interfaces limiten, distorsionen o manipulen la autonomía o la libertad de elección del consumidor, induciéndolo u obligándolo a decisiones de consumo no deseadas que le generen perjuicio.
- **Segunda disposición complementaria final:** el Ejecutivo aprueba disposiciones reglamentarias complementarias en **180 días calendario**. Cálculo propio (NV exacto): desde la publicación (12/02/2026) vence en torno al 11-12/08/2026. La primera disposición encarga la supervisión a Indecopi. El extracto leído no contiene cláusula de vigencia: rige la regla general de entrada en vigor al día siguiente de la publicación (S/NV, no leída de fuente en esta fase). Multas máximas citadas por EY: hasta 450 UIT (S, no verificado).
- **Reglamentación posterior:** en búsquedas hasta 2026-10-30 **no se encontró reglamento definitivo del DL 1729** ni decreto definitivo que adecúe el Reglamento del Libro de Reclamaciones a la Ley 32495; solo existe el **proyecto** publicado por la RM 244-2026-PCM (18/07/2026; 15 días calendario de comentarios, hasta ~02/08/2026). No es norma vigente. Plazo vencido sin reglamento localizado ≠ reglamento existente.
**Fuentes oficiales consultadas en esta fase:** DL 1729 (El Peruano 12/02/2026); DS 011-2011-PCM concordado (gob.pe/SPIJ) con DS 006-2014-PCM (El Peruano 23/01/2014, incluye Anexos I-IV) y DS 058-2017-PCM; DS 101-2022-PCM (sumario de normas legales de El Peruano 16/08/2022); Ley 31435 (Congreso); Ley 32495 (texto citando El Peruano 2457203-1); RM 244-2026-PCM (El Peruano 18/07/2026); DS 016-2024-JUS (gob.pe/ANPD); Indecopi, preguntas y respuestas 12/11/2025. **No se pudo leer:** el Anexo I tal como quedó tras el DS 101-2022-PCM (el sumario no lo transcribe; se usa el Anexo I del DS 006-2014-PCM más las modificaciones textuales de 2022); la Ley 29733; el proyecto de decreto adjunto a la RM 244.

### 2. Impacto en Horus Group SRL según sus canales reales
Canales comprobados en el repositorio (C): sitio institucional informativo; páginas de cursos y servicios estáticas; **sin carrito, pasarela de pago ni checkout**; registro de usuarios sin rol y sin compra; matrícula, cobro y emisión de comprobantes ocurren fuera del sitio y en el panel; contacto por WhatsApp (pie de página +51 927 582 305) y correo; sede en Cajamarca; el asistente y las preguntas frecuentes mencionan pago por transferencia, Yape, Plin, tarjeta y efectivo «en la sede»; la recepción pública de cotizaciones está suspendida. Observaciones: el texto de `politicas-devolucion` describe un «carrito de compras» que el sitio no tiene; las preguntas frecuentes enlazan un WhatsApp distinto (+51 909 999 999) del del pie de página: inconsistencias de contenido, no afectan este diseño.
Análisis:
- **DL 1729, art. 24.4:** exige mecanismos digitales de atención para quien *ofrece bienes o servicios mediante canales digitales de comercio electrónico*. Una página institucional **no equivale automáticamente** a plataforma de comercio electrónico y no se asume. Con el sitio actual no hay transacción en línea (hipótesis sustentada en C). **Pregunta abierta para asesoría:** si cerrar ventas o matrículas por WhatsApp o correo constituye «otros canales digitales de comercio electrónico».
- **Art. 24.5:** no aplica (proveedor domiciliado).
- **Art. 56.1.h:** su ámbito es el comercio electrónico; hoy no aplicaría, pero se adopta como **criterio de diseño** (coste nulo): sin casillas premarcadas, sin obstáculos ni pasos artificiales, sin textos que presionen, lenguaje neutro, la elección reclamo/queja no condiciona ni confunde, cancelar y corregir siempre posibles.
- **Obligación independiente del comercio electrónico:** el DS 011-2011-PCM (art. 4, 4-A, 9) obliga a los establecimientos comerciales abiertos al público y, si además se usan medios virtuales para las relaciones de consumo, al libro virtual alojado en la página de inicio. Con sede abierta al público en Cajamarca (C: referencias a la «sede»), **la hipótesis es que Horus sí debe tener libro** (no se asume exención). Mantener el libro virtual es consistente con 24.4 en cualquier escenario y con la Ley 32495 (enlace permanente).
- Consecuencia de diseño: el libro virtual es **permanentemente operativo** (si el correo falla se registra igual y la hoja se puede imprimir), sin login, sin compra previa, sin costo; enlace permanente en pie de página y aviso en la página de inicio.

### 3. Formulario cerrado (campos del Anexo I)
Base: Anexo I del DS 006-2014-PCM (secciones: encabezado, 1 consumidor, 2 bien contratado, 3 detalle y pedido, 4 observaciones del proveedor) + art. 5 + modificaciones del DS 101-2022-PCM. Correcciones respecto a la fase 1.1 marcadas con ★.
| Bloque | Campo | Regla | Base |
|---|---|---|---|
| Encabezado (impreso por defecto; **no lo escribe el consumidor**) | razón social, RUC, dirección del establecimiento, código de identificación del libro | Configuración (`config/reclamaciones.php` desde entorno); aparecen en pantalla, hoja y correo. Datos de Horus: **no inventados**, los aporta el propietario (D-A1). No se duplican por fila: el correo guardado en `reclamacion_envios.cuerpo` los congela. | art. 5, art. 8, Anexo I |
| Encabezado | número correlativo; fecha | Los asigna el servidor (§5, §7). ★ Formato del Anexo I: `NNNNNNNNN-AAAA` (9 dígitos + año). | Anexo I |
| 1 Consumidor | nombres y apellidos | Obligatorios | art. 5 |
| | tipo y número de documento | Obligatorios (DNI, CE, pasaporte, RUC; validación por tipo) | art. 5 («nombre, DNI…») |
| | **domicilio** | Obligatorio. ★ Si falta nombre, DNI, domicilio o correo, fecha o detalle, la reclamación **se considera no presentada**: el servidor la rechaza con error. | art. 5 |
| | correo electrónico | Obligatorio (copia automática y respuesta) | art. 4-B, 5 |
| | teléfono | Obligatorio por prudencia (art. 5 lo lista; el Anexo dice «teléfono/e-mail»). Si fuera opcional se relaja sin cambiar tablas. NV (D-E3). | art. 5 / Anexo I |
| Menor de edad | casilla «el reclamante es menor de edad» | Opcional (no premarcada) | art. 5 |
| | representante: nombre, domicilio, teléfono, correo | Obligatorios solo si es menor (CHECK + FormRequest). Documento del representante: **no se pide** (no lo exige el art. 5). | art. 5 |
| 2 Bien contratado | ★ tipo: producto / servicio | Obligatorio en **reclamo**; vacío permitido en queja sin bien | Anexo I |
| | descripción del producto o servicio | Obligatoria si hay tipo de bien | Anexo I, art. 5 |
| | ★ **monto** | **Reclamo:** obligatorio, salvo que el consumidor marque «no aplica / sin monto» (p. ej. servicio gratuito o aún sin precio) y entonces se guarda **NULL, nunca 0 ni un valor inventado**. **Queja sin bien o servicio:** el bloque 2 se oculta y el monto queda NULL. **Queja con bien:** monto opcional. El Anexo I lee «monto reclamado»; el art. 5 «monto del producto o servicio contratado objeto del reclamo»: el campo se rotula con la fórmula del art. 5. Que NULL declarado sea aceptado por Indecopi: **NV, asesoría (D-E2)**. | art. 5, Anexo I |
| 3 Detalle | tipo reclamo / queja | Obligatorio; el personal puede reclasificar (art. 6: reclamo marcado como queja se tramita como reclamo) | arts. 3.3, 3.4, 6 |
| | detalle | Obligatorio (máx. 3000 car.) | art. 5 |
| | pedido concreto | Obligatorio (máx. 1500 car.) | art. 5 |
| Preferencia | ★ canal de respuesta: correo (por defecto) o carta | Art. 6: la respuesta es escrita «vía carta y/o correo electrónico, **según haya sido solicitado por el consumidor**»: se le pregunta. Carta exige el domicilio, ya obligatorio. | art. 6 |
| 4 Observaciones | acciones del proveedor; fecha de comunicación de la respuesta | Las completa el panel (respuesta, `respondida_at`) | Anexo I |
| Información | aviso de tratamiento (§8) | Texto informativo, **no** casilla comercial; casilla de aceptación solo si el asesor la exige | DS 016-2024-JUS art. 6 |
Se **eliminan** frente a la fase 1.1: `fecha_incidente` (no figura en el Anexo I ni en el art. 5; el consumidor puede incluirla en el detalle; minimiza datos) y `area` (la identificación del bien la cubre `bien_tipo` + `bien_descripcion`). Se **añaden**: `bien_tipo`, `canal_respuesta`. La firma no se pide (solo libro físico); la sustituye el envío del formulario por el propio consumidor.

### 4. Flujo de atención cerrado
Validación: **tres estados bastan** (`abierto`, `respondido`, `concluido_por_acuerdo`); cualquier matiz restante (en atención, con propuesta, suspendido, vencido por plazo, correo pendiente) es **derivado** de columnas y eventos, no un estado.
**Reconstrucción por eventos** (todos en `reclamacion_historial`; cada fila guarda `created_at` de registro y, en `metadatos`, la **fecha efectiva** del hecho, porque el personal puede registrar un hecho después de ocurrido):
| Hecho a reconstruir | Evento | Metadatos mínimos |
|---|---|---|
| Registro y plazo inicial | `registrada` | `fecha_limite`, `canal` (web/respaldo) |
| Propuesta de solución | `propuesta_enviada` | `envio_id`, `suspension_desde`, `suspension_hasta` (= envío + 5 días hábiles) |
| Suspensión del plazo | implícita en el evento anterior + columnas `suspension_*` | — |
| Aceptación expresa | `propuesta_aceptada` | `fecha_recepcion`, `medio` (correo/otro), constancia pegada en `nota` ⇒ estado `concluido_por_acuerdo` |
| Rechazo | `propuesta_rechazada` | `fecha_recepcion`, `dias_suspendidos`, `fecha_limite_nueva` (reanudación) |
| Vencimiento de los 5 días hábiles | `propuesta_vencida` | `dias_suspendidos` (≤5), `fecha_limite_nueva` (reanudación) |
| Reanudación del plazo | contenida en rechazo o vencimiento (no es un evento aparte) | `fecha_limite_nueva` |
| Respuesta formal | `respuesta_formal_registrada` | `envio_id`, `canal`; ⇒ estado `respondido` solo cuando el envío figura `enviado` |
| Envío y evidencia | `correo_enviado` / `carta_registrada` / `correo_fallido` | `envio_id`, `tipo`, `proveedor_id` o `constancia` |
| Gestión | `responsable_asignado`, `tipo_reclasificado`, `nota_interna`, `hoja_respaldo_registrada` | ids y valores no personales |
La aceptación de una propuesta **nunca** se registra como `respuesta_formal_registrada` ni deja el caso en `respondido`: `concluido_por_acuerdo` es un estado y un evento distintos (art. 6-A.3: el acuerdo concluye el reclamo y libera de la respuesta formal).
**Dos canales de respuesta (carta y correo) y falla de correo:** la respuesta formal se registra como una o varias filas de `reclamacion_envios` (`canal` = `correo` | `carta`). Si el correo falla tras los reintentos, el personal con `reclamaciones.responder` registra el envío por **carta** (texto imprimible de la respuesta; constancia = fecha, medio y cargo/guía) y el caso pasa a `respondido` cuando **cualquier** envío de tipo respuesta figura `enviado`. Así una caída del proveedor de correo no impide cumplir. Lo que **no** está verificado (D-E4): si responder únicamente por carta cuando el consumidor pidió correo (o viceversa) satisface «carta y/o correo según solicitó el consumidor»; el diseño soporta ambos y recomienda, mientras no haya opinión legal, enviar por el canal solicitado y, ante falla, usar el otro **además de** reintentar el primero.
Reglas: propuestas solo para reclamos; una propuesta pendiente a la vez; sin retroceso de estado; vencimiento evaluado perezosamente al leer/escribir y, opcionalmente, con un comando diario (D-B3); el cómputo usa días hábiles con la lista de feriados en configuración (definición legal de «día hábil»: NV, D-E5).

### 5. Modelo de datos definitivo (4 tablas; no se crean tablas ni columnas por anticipado)
Criterio: cada columna cumple un requisito legal, de seguridad o de operación indicado; si no, no está.
**`reclamaciones`** — entidad del caso.
- Identidad: `id` PK; `identificador` uuid UNIQUE (ruta del panel, evita IDOR); `numero` varchar(14) UNIQUE (`NNNNNNNNN-AAAA`, Anexo I); `token_envio` char(64) UNIQUE (nonce del formulario guardado en sesión: idempotencia del envío público); `canal` (`web`|`respaldo`) y `numero_hoja_respaldo` varchar(20) NULL (art. 4-A, **solo si Horus tiene establecimiento abierto**: condicional a D-A2).
- Plazos y estado: `estado` (3 valores, CHECK); `recibida_at` timestamptz; `fecha_limite` date; `suspension_desde`, `suspension_hasta` date NULL (CHECK `hasta ≥ desde`, ambas nulas o ambas presentes).
- Consumidor: `nombres`, `apellidos`, `tipo_documento` (CHECK), `numero_documento`, `domicilio`, `telefono`, `email`; `canal_respuesta` (`correo`|`carta`).
- Menor: `es_menor` bool; `representante_nombre`, `representante_domicilio`, `representante_telefono`, `representante_email` NULL (CHECK: todos presentes si `es_menor`).
- Bien: `tipo` (reclamo/queja); `bien_tipo` NULL (producto/servicio); `bien_descripcion` NULL; `monto_bien` numeric(12,2) NULL (CHECK ≥ 0). CHECK: si `tipo='reclamo'` ⇒ `bien_tipo` y `bien_descripcion` no nulos. El «no aplica» del monto en reclamos es una regla del FormRequest (NULL), no una columna.
- Hechos: `detalle` text, `pedido` text (NOT NULL).
- Respuesta: `respuesta` text NULL, `respondida_at` timestamptz NULL (CHECK: ambas nulas salvo estado `respondido`).
- Gestión: `responsable_id` FK users **nullOnDelete** (se conserva el caso si se borra la cuenta; el historial guarda la copia del nombre).
- Información: `privacidad_version` varchar(20), `informado_at` timestamptz (prueba del deber de información; no es consentimiento comercial).
- `created_at/updated_at` timestamptz.
- Índices: UNIQUE `identificador`, `numero`, `token_envio`; `(estado, fecha_limite)` (bandeja y vencimientos); `responsable_id`; `recibida_at`; `numero_documento` (búsqueda exacta). Sin `LIKE '%…%'` sobre datos personales. Sin índice por correo/nombre (no se busca por ellos).
- No se incluyen: `revision`/ETag, claves de idempotencia dobles, `respondida_por`, `acepta_comunicaciones`, `fecha_incidente`, `area`, IP.
**`reclamacion_contadores`** — `anio` smallint PK, `ultimo` int NOT NULL. ★ Corrección: **numeración por año** para ajustarse al formato `NNNNNNNNN-AAAA` del Anexo I (la fase 1.1 proponía contador único continuo). Alta: dentro de la misma transacción, `INSERT … ON CONFLICT DO NOTHING` de la fila del año y `UPDATE … SET ultimo = ultimo + 1 … RETURNING` (PostgreSQL) o equivalente bloqueado (SQLite); el bloqueo de fila serializa solo los altas del mismo año hasta el commit (el volumen de un libro de reclamaciones lo hace irrelevante). Si la transacción revierte el número no se consume ⇒ **sin huecos**. UNIQUE de `numero` como segunda salvaguarda. Descartados: `SEQUENCE` (huecos), `MAX()+1` (carrera), bloqueo consultivo (solo PostgreSQL, no probable en SQLite). Necesaria porque la norma exige numeración correlativa.
**`reclamacion_historial`** — solo inserción. `id` PK; `reclamacion_id` FK **RESTRICT** (la evidencia no se borra en cascada); `evento` (CHECK con los eventos de §4); `actor_id` FK users nullOnDelete + `actor_nombre` varchar(120) (copia: evita el problema A-01); `metadatos` json (solo ids, fechas y estados, **sin datos personales**); `nota` text NULL (constancias y observaciones: es dato personal del caso, se conserva con él); `created_at` timestamptz; índice `(reclamacion_id, id)` (línea de tiempo). Se elimina `revision` única: el orden lo da `id`; la consistencia, `lockForUpdate` + relectura del estado. Necesaria porque `audit_logs` pierde el actor, no admite texto libre ni datos personales y no es la evidencia del art. 12.iii.
**`reclamacion_envios`** — bandeja de salida **y** evidencia de entrega/constancia (art. 12.iii). `id` PK; `reclamacion_id` FK RESTRICT; `tipo` (`copia_hoja`|`propuesta`|`respuesta`); `canal` (`correo`|`carta`); `clave` uuid UNIQUE (clave de idempotencia por envío); `estado` (`pendiente`|`enviado`|`fallido`); `intentos` smallint; `asunto` varchar(200); `cuerpo` text (texto exacto enviado o imprimible: es la prueba); `enviado_at` timestamptz NULL; `proveedor_id` varchar(100) NULL (id devuelto por el proveedor de correo); `constancia` varchar(255) NULL (carta: fecha, medio y cargo/guía; correo: nota de entrega si existe); `error_codigo` varchar(40) NULL (nunca el mensaje crudo); timestamps. Único `(reclamacion_id, tipo, canal)` para `copia_hoja` y `respuesta` (índice parcial; `propuesta` queda fuera para permitir propuestas sucesivas); índice `(estado, id)` para reintentos. Sin columna de destinatario: se usa el correo/domicilio del caso (evita copiar datos personales).
**Envíos repetidos e idempotencia:** envío público ⇒ `token_envio` (reenvío del mismo formulario devuelve el mismo número); correo ⇒ reserva atómica `UPDATE … SET estado='pendiente→procesando' WHERE estado='pendiente'` (si la reserva afecta 0 filas, otro worker lo tiene) y unicidad por tipo/canal; reintento manual de un `fallido` reutiliza la misma fila. Si el proceso muere tras enviar y antes de guardar, puede duplicarse un correo: aceptado y documentado (la copia/respuesta duplicada no perjudica; el proveedor no aporta idempotencia con el transporte estándar de Laravel).
**Conservación de documentos:** los tres registros del art. 12 (hoja = `reclamaciones` + `cuerpo` de `copia_hoja`; constancia de respuesta = `reclamacion_envios` + historial) se guardan ≥ 2 años desde `recibida_at`; FKs RESTRICT y ausencia de eliminación desde el panel; purga posterior solo por un comando aprobado (D-B2). Respaldo de la base: parte de la infraestructura general (no es respaldo documental del módulo).
**Tablas descartadas:** estados de propuesta, adjuntos, feriados (van en configuración), tokens de consulta pública, auditoría propia, destinatarios.

### 6. Permisos (sin cambios sobre 1.1; ningún permiso ni seeder modificado)
Permisos nuevos: `reclamaciones.ver`, `reclamaciones.gestionar`, `reclamaciones.responder`, creados por migración sin resincronizar y concedidos por defecto **solo a `admin`**.
| Operación | Permiso |
|---|---|
| Listar, buscar, ver detalle e historial, imprimir la hoja | `ver` |
| Asignar responsable, reclasificar tipo, notas, registrar hoja de respaldo, enviar propuesta, registrar aceptación/rechazo, reintentar un correo fallido | `gestionar` |
| Registrar y enviar (o registrar la carta de) la **respuesta formal**; cerrar por acuerdo | `responder` |
| Editar/borrar datos del consumidor, borrar casos, purgar | nadie desde el panel |
Recomendación al propietario (no aplicada): admin y coordinador con los tres permisos (continuidad ante un plazo improrrogable); secretaria solo `ver`, y solo si atiende el libro físico de respaldo. El responsable asignable debe poseer `reclamaciones.ver`. Revisión semestral de privilegios (DS 016-2024-JUS art. 46).

### 7. Seguridad (ajustes sobre 1.1)
Mantiene CSRF, FormRequest, honeypot + marca de tiempo firmada, limitadores por IP y por correo (revisar N-07 `trustProxies`), UUID en rutas, salida escapada, Eloquent, sin cuerpos en logs. Añade: rechazo de reclamaciones sin los datos mínimos del art. 5; **disponibilidad permanente** (el registro no depende del correo; el flag de apagado es la única forma de deshabilitar el módulo y exige alternativa física); sin login, sin compra previa, sin costo; sin consulta pública del estado (recomendado); auditoría con listas de nombres de campo (nunca valores personales) y registro de lecturas del detalle; los registros de acceso a datos personales se conservan al menos 2 años (DS 016-2024-JUS art. 46).

### 8. Protección de datos (qué resuelve el diseño y qué requiere asesoría)
Verificado (O) en el DS 016-2024-JUS (vigente desde 120 días tras el 30/11/2024): **art. 6.1** — información clara, previa a la recopilación, con 10 contenidos mínimos: identidad y domicilio del responsable; finalidades; destinatarios; banco de datos; carácter obligatorio o facultativo de las respuestas; consecuencias de dar o negar los datos; transferencia nacional e internacional; decisiones automatizadas; **plazo de conservación**; mecanismos de derechos. **Art. 7** — publicar la política de privacidad cumple el deber de información pero **no exonera del consentimiento**. Art. 4-5 consentimiento previo, expreso e inequívoco; art. 9 carga de la prueba en el responsable; art. 10.2 se pueden negar finalidades adicionales sin afectar la relación; arts. 13 y 18 transferencia y flujo transfronterizo (consentimiento salvo excepciones de la Ley; nivel adecuado o garantías); art. 31.2 encargados: conservación máxima 2 años tras el último encargo, salvo norma que obligue; art. 42-43 inscripción de bancos de datos (gratuita); art. 46 seguridad digital; arts. 84-85 improcedencia de supresión por obligación legal/contractual y disociación.
| Aspecto | Lo resuelve el diseño técnico | Necesita asesoría legal / decisión |
|---|---|---|
| Base jurídica | Registra solo el Anexo I; separa finalidad; `informado_at` + `privacidad_version`; sin casilla comercial | Cuál excepción del art. 14 de la Ley 29733 ampara el tratamiento por obligación del DS 011 (la Ley no se leyó) y si se requiere además casilla de aceptación (D-E1) |
| Deber de información | Aviso en el formulario con los 10 contenidos del art. 6.1, texto de la hoja y del correo | Redacción final del aviso y de la política del libro (hoy la política declara fines comerciales y retención «hasta que decida eliminarlo»: incompatible; contenido público protegido, D-B1) |
| Banco de datos | Documenta el banco «reclamaciones» (campos, finalidades, destinatarios, plazo) | Inscribirlo o modificar el existente en el Registro Nacional **antes del lanzamiento**; saber si ya hay inscripción (D-A3, D-E1) |
| Responsable del tratamiento | Datos del encabezado de la hoja y del aviso | Identidad/domicilio de Horus y designación del oficial o responsable (D-A1) |
| Encargado | Lista de encargados: proveedor de correo, hosting de la base | Contratos de encargo (art. 31) |
| Transferencia internacional (si Resend) | Minimizar el contenido saliente (solo lo necesario); posibilidad de transporte SMTP propio o proveedor local; `error_codigo` sin datos | **Decisión de infraestructura (D-D1)** y análisis legal: Resend procesaría datos personales (copia de la hoja, respuesta) fuera del país; arts. 13 y 18 exigen garantías/consentimiento e información (D-E1) |
| Conservación y eliminación | ≥2 años (O), FKs RESTRICT, sin borrado en panel, comando de purga/anonimización | Plazo máximo posterior (D-B2) y criterio de anonimización |
| Auditoría de accesos | `audit_logs` de lecturas y escrituras (solo nombres de campo), historial inmutable, retención ≥2 años de registros de acceso | — |
| Revisión semestral de privilegios | Informe de usuarios con `reclamaciones.*` disponible en el panel de Roles/Auditoría (sin código nuevo) y calendario | Responsable de ejecutarla (D-C2) |

### 9. Infraestructura de correo
Inspección (C): no hay bandeja genérica; `matricula_notifications`/servicio/job/`config/matricula-mail.php` están atados a `matricula_id`, con política de retención de 23-24 h y cliente HTTP propio de Resend, y protegidos por pruebas ⇒ **no se reutilizan ni se generalizan**. Sí se reutilizan: la cola de base de datos, el transporte Resend instalado y `NotificacionInterna` (aviso interno a usuarios con `reclamaciones.ver`, solo número y enlace).
Arquitectura: el hecho y su fila `reclamacion_envios` se crean en la misma transacción; tras confirmar, un job (solo el ID, `tries` 4, retroceso) reserva la fila, envía con el mailer de Laravel y guarda `enviado_at`, `proveedor_id` y el texto; el fallo deja `fallido` + historial y no revierte el caso; la UI ofrece «reintentar» y «registrar carta». Evidencia suficiente según Indecopi («correo enviado y/o constancia de entrega o lectura»); webhooks de entrega **no** en v1 (D-D2). El módulo **no se activa** sin mailer real (no `log`/`array`) y remitente válido, porque la copia automática es obligatoria (art. 4-B); mientras tanto rige el libro físico. Sin correos en pruebas (`Mail::fake`/`Http::fake`).

### 10. Decisiones del propietario (lista final, sin duplicados)
**A. Información empresarial (no se inventa nada)**
- A1 Razón social, RUC, domicilio del establecimiento, código de identificación del libro, correo y teléfono oficiales de atención de reclamos.
- A2 Si existe establecimiento abierto al público (sede de Cajamarca) y cómo se atiende allí; si hay venta o cierre de matrícula/servicio por medios digitales y cuáles (sitio, WhatsApp, correo).
- A3 Si ya existe banco de datos inscrito en el Registro Nacional y quién es responsable del tratamiento.
- A4 Ingresos anuales estimados frente a 3000 UIT (reporte al SIREC).
**B. Decisiones operativas**
- B1 Autorizar tocar la vista, JS, CSS, el aviso del Anexo III en la página de inicio y la política de privacidad (contenido público protegido).
- B2 Plazo de conservación máximo y política de purga/anonimización tras los 2 años.
- B3 Comando diario de vencimientos y aviso de plazos por vencer.
- B4 Procedimiento del libro físico de respaldo y del ingreso de hojas en 1 día calendario (art. 4-A).
- B5 Calendario de feriados a cargar en configuración.
**C. Permisos**
- C1 Roles con `ver/gestionar/responder` (propuesta en §6; por defecto solo admin).
- C2 Responsable de la revisión semestral de privilegios.
**D. Infraestructura y correo**
- D1 Proveedor de correo: Resend (con contrato de encargo y análisis de transferencia internacional) o SMTP propio/local; remitente y dirección de aviso.
- D2 Constancia de entrega por webhook (no recomendada en v1).
- D3 Activar o no CAPTCHA de terceros (recomendado: honeypot + límites).
**E. Decisiones legales que requieren asesoría profesional**
- E1 Base jurídica (art. 14 Ley 29733), necesidad de casilla de aceptación, redacción del aviso y política, inscripción del banco, encargo y transferencia internacional.
- E2 Aceptación del «no aplica» del monto (quejas sin bien y reclamos sin monto) y contenido exacto del Anexo I vigente tras el DS 101-2022.
- E3 Si el teléfono puede ser opcional.
- E4 Validez de responder solo por carta cuando se pidió correo, y viceversa.
- E5 Definición de «día hábil» y cómputo de la suspensión; ámbito de aplicación del DL 1729 art. 24.4 a ventas por WhatsApp/correo.
- E6 Reglamentación pendiente (DL 1729; adecuación del reglamento a la Ley 32495): repetir la búsqueda de norma definitiva antes de implementar y antes de activar.

### 11. Estado definitivo del diseño
**Diseño cerrado para implementar** sujeto a: A1, A2, B1 y E1 (bloquean el lanzamiento, no el desarrollo de backend), y a la revisión normativa E6. Cambios finales respecto a 1.1: formato de número del Anexo I por año; `canal_respuesta`; `bien_tipo`; sin `fecha_incidente` ni `area`; monto NULL en lugar de importe inventado; `reclamacion_envios` con `canal` (carta) y `constancia`; tres estados validados con reconstrucción por eventos. No se prevén más tablas. Las migraciones previstas siguen siendo dos: `2026_10_30_000030_create_reclamaciones_tables` y `2026_10_30_000031_add_reclamaciones_permissions`; la numeración 000030-000031 está libre en disco (últimos archivos: 000027-000029 del Sprint 19.2 suspendido, sin seguimiento en Git, no tocados).

### 12. Riesgos
Ámbito legal no confirmado (A2/E5); reglamentos pendientes (DL 1729, Ley 32495) que pueden cambiar el formulario; Anexo I posterior a 2022 no leído; falta de datos del proveedor; transferencia internacional si se usa Resend; incumplir los 15 días hábiles improrrogables si pocos usuarios tienen permiso; evidencia basada en el correo enviado (no en la entrega); política de privacidad incompatible hasta corregirla; formulario público actual simulado hasta el despliegue; inconsistencias de contenido (WhatsApp, «carrito») detectadas.

### 13. Próxima fase
Con autorización expresa: **Fase 2 — backend sin frontend** (2 migraciones, modelos, servicio de gestión, auditoría y pruebas en SQLite, flag apagado). Orden: 2 datos/servicio/auditoría → 3 endpoint público tras flag → 4 panel → 5 correo y carta → 6 frontend/contenido público → 7 pruebas PostgreSQL de concurrencia. La Fase 2c (auditoría de matrículas) sigue pendiente y no autorizada.

---

## SPRINT 20 — LIBRO DE RECLAMACIONES: FASE 2, BACKEND

**Fecha:** 2026-10-31. **Naturaleza:** implementación backend según la Fase 1.2. Sin vistas, JS, CSS, página pública, política de privacidad, chatbot, matrículas ni rutas nuevas. Sin commits, staging ni push. Solo SQLite en memoria; no se tocó PostgreSQL ni ninguna base real; no se envió ningún correo.

### 1. Arquitectura implementada
- **Datos:** 4 tablas (`reclamaciones`, `reclamacion_contadores`, `reclamacion_historial`, `reclamacion_envios`) en `2026_10_30_000030`; permisos en `2026_10_30_000031`. CHECK en PostgreSQL y triggers equivalentes en SQLite (patrón de 000027). Historial de solo inserción por trigger (única excepción: `actor_id` pasa a NULL por `ON DELETE SET NULL`; `actor_nombre` conserva la autoría). Índice parcial único `(reclamacion_id, tipo, canal) WHERE tipo <> 'propuesta'`.
- **Servicio `GestionReclamaciones`:** alta pública (idempotente por `token_envio`, guardado como SHA-256), alta de hoja de respaldo, consulta (audita la lectura), responsable, reclasificación, notas, propuesta (suspende 5 días hábiles), rechazo, aceptación (`concluido_por_acuerdo`), vencimiento perezoso, respuesta formal por correo o carta, reintento manual. Transaccional, `lockForUpdate` y relectura de estado; permiso releído de la base en cada operación (más rol de panel); auditoría como última escritura.
- **Numeración:** `NumeradorReclamaciones`, `NNNNNNNNN-AAAA` por año Lima, bloqueo de la fila del contador dentro de la transacción del alta; el rollback no consume número.
- **Correo:** `EnvioReclamaciones` + job `EnviarReclamacionCorreo` (solo el ID; `tries` 4; retroceso 60/300/900 s). Reserva atómica `pendiente → procesando` con `intentos + 1` (UPDATE condicional), reserva caducable a los 10 min, envío fuera de transacción, `cuerpo` guardado = texto exacto remitido (constancia), códigos de error cortos (`transporte`, `error_envio`) sin mensajes de excepción. Agotados los intentos: `fallido` + `correo_fallido`, sin revertir el caso. `ReclamacionMail` sin vistas Blade (HTML escapado + parte de texto idéntica al cuerpo).
- **Soporte:** `Reclamaciones` (activación), `DiasHabiles`, `AntispamReclamaciones` (marca firmada HMAC), `AuditoriaReclamaciones` (lista cerrada de claves, valores cortos y seguros; lanza excepción ante cualquier otro; sin IP en altas públicas), `HojaReclamacion`.
- **HTTP (sin rutas):** `StoreReclamacionRequest` (honeypot, marca firmada, token UUID, validación por tipo de documento, monto NULL con «no aplica», campos de servidor `prohibited`), `GestionarReclamacionRequest`, middleware `RecepcionReclamacionesHabilitada`, limitador nombrado `reclamaciones` (IP 3/10 min, IP 10/día, correo normalizado en hash 3/día).

### 2. Archivos
**Creados (27):** migraciones 000030 y 000031; `config/reclamaciones.php`; modelos `Reclamacion`, `ReclamacionHistorial`, `ReclamacionEnvio`; servicios `GestionReclamaciones`, `EnvioReclamaciones`, `NumeradorReclamaciones`; soporte `Reclamaciones`, `DiasHabiles`, `AntispamReclamaciones`, `AuditoriaReclamaciones`, `HojaReclamacion`; `EnviarReclamacionCorreo`; `ReclamacionMail`; `RecepcionReclamacionesHabilitada`; `StoreReclamacionRequest`, `GestionarReclamacionRequest`; pruebas `ReclamacionesMigracionTest`, `GestionReclamacionesTest`, `EnvioReclamacionesTest`, `ReclamacionRequestsTest`, `DiasHabilesTest` y el trait `tests/Support/PreparaReclamaciones.php`.
**Modificados (además de este archivo):** `RolesAndPermissionsSeeder` (constante `RECLAMACIONES_PERMISSIONS` y su inclusión en `PERMISSIONS`, es decir solo admin; el archivo ya tenía cambios ajenos de cotizaciones) y `AppServiceProvider` (limitador `reclamaciones`).
**Intactos:** migraciones 000027–000029 (mtime anterior a esta sesión; SHA-256 en §8), `AuditLog`, `SensitiveData`, vistas, JS/CSS, `public/`, `routes/web.php`.

### 3. Relaciones
`Reclamacion` hasMany `ReclamacionHistorial` y `ReclamacionEnvio` (FK RESTRICT); belongsTo `User` como responsable (nullOnDelete). `ReclamacionHistorial` belongsTo `User` actor (nullOnDelete). La reclamación no se elimina (el modelo lanza excepción) y solo admite cambios en `estado, tipo, fecha_limite, suspension_*, responsable_id, respuesta, respondida_at`; el historial no admite UPDATE/DELETE.

### 4. Permisos
`reclamaciones.ver` (listar, detalle, historial), `reclamaciones.gestionar` (responsable, tipo, notas, hoja de respaldo, propuesta, rechazo, reintento), `reclamaciones.responder` (respuesta formal, aceptación/cierre por acuerdo). Concedidos solo a `admin` por la migración 000031, sin resincronizar otros roles. El responsable asignable debe ser usuario de panel con `ver`. Ampliar a otros roles sigue siendo decisión del propietario (C1).

### 5. Seguridad
Flag `reclamaciones.enabled=false` y `correo.enabled=false` (literales en `config/`, sin `env()`); `Reclamaciones::habilitado()` exige además mailer real (no `log/array/null`) y remitente válido que no sea `@example.com`. Apagado: 404 y cero escrituras. CSRF y ruta pública: Fase 3. Honeypot, marca firmada con mínimo/máximo, UUID de ruta (`identificador`), token de envío hasheado y oculto, throttle, datos del consumidor inmutables, sin edición ni borrado, auditoría sin valores personales (el texto libre vive solo en el historial), logs técnicos solo con ID y código de resultado.

### 6. Pruebas reales (SQLite en memoria)
- Dirigidas: 126 pruebas, 667 aserciones, OK (migración/rollback/reaplicación, CHECK, triggers, unicidad, permisos y migración idempotente, numeración y rollback sin huecos, idempotencia, atomicidad por fallo de auditoría, permisos por acción, flujo propuesta/rechazo/vencimiento/aceptación, respuesta por correo y carta, auditoría sin PII, envíos: éxito, reintentos, fallo, agotamiento, reserva vigente/abandonada, duplicados, flag; validaciones y limitador). Usan `Mail::fake()`; los fallos y reintentos simulan el transporte con Mockery. Sin HTTP real.
- Suite completa `php artisan test --compact`: **1397 pasadas, 1 omitida, 8943 aserciones, exit 0**.
- Larastan nivel 6 (`vendor/bin/phpstan analyse`): **sin errores** (todo el proyecto).
- Pint `--test` sobre los 25 archivos PHP propios: **passed** (se aplicó `pint` solo a ellos; seeder y provider no se reformatearon).
- **No medido:** concurrencia real (SQLite ignora `lockForUpdate`), DDL/CHECK/trigger/función PL/pgSQL en PostgreSQL, envío real por Resend/SMTP, cola `database` real.

### 7. Estado Git
Rama `anderson`, sin commits ni staging de esta fase. Todo es cambio sin seguimiento o modificado en el árbol de trabajo, junto al trabajo previo ajeno (cotizaciones, PostgreSQL testing, etc.), conservado.

### 8. SHA-256 (al cierre de la fase, antes de editar este documento)
| Archivo | SHA-256 |
|---|---|
| `database/migrations/2026_10_30_000030_create_reclamaciones_tables.php` | `edf4b47e8d2638cd7001f8254ea9b3d26e38eeaa37bd2092f46f065f482e4ac8` |
| `database/migrations/2026_10_30_000031_add_reclamaciones_permissions.php` | `bfa51f431563b5ab3e57f075f3f651ccfa25bb1558831ea684f1031ab334ebc0` |
| `config/reclamaciones.php` | `aadca0915ee2b93817a6ed4ea3335df0a99dbcde6913e9c43089124d0133be66` |
| `app/Models/Reclamacion.php` | `e2e9a1367c528cd0098f7a52e3dd1816dde75dc692c81e75038ebac66d2a2224` |
| `app/Models/ReclamacionHistorial.php` | `9f8af37a3c18f1974381b9e6f94e73eb7b9cfcc83a587529466e85a6c4af1a7d` |
| `app/Models/ReclamacionEnvio.php` | `b94a1f2931c11f4060258de2d4813230a719a47f3167d8629d0c011b4f0f8b72` |
| `app/Services/GestionReclamaciones.php` | `8f4b8cd4833c69f40bb2ebdb01e85f6e05aafe26fc447384f76127cbf389ced5` |
| `app/Services/EnvioReclamaciones.php` | `a3e6a1aee2cd1e0c9cb23dbcdc34afc192ecd4243a98a168f368e6d726a3647d` |
| `app/Services/NumeradorReclamaciones.php` | `8f25880627d85cd857b944b800ea7a83311e0e11794cc3fc6a89f7432f66bed2` |
| `app/Support/Reclamaciones.php` | `2e6bb06a6e4be453a1ca71ec9e9f9bd3281bde680d2c098f9acaa702065b2181` |
| `app/Support/DiasHabiles.php` | `3c93a6b1938dbf28b61416d28d72fc037fcd12864d0810b0382f314a994a33e8` |
| `app/Support/AntispamReclamaciones.php` | `525ea68dbbe85c5eb0ab46a555218ffc7cb808f386091f925ed16b6c045dcf69` |
| `app/Support/AuditoriaReclamaciones.php` | `83729f27dd8a351f751dc7439284ab5d2cf37f8163469ff30d94d1ba286a2c92` |
| `app/Support/HojaReclamacion.php` | `b9c4e5fc285b59e65701ac626d626edc322d61021c7882471daca94fb0848934` |
| `app/Jobs/EnviarReclamacionCorreo.php` | `8345e3887da2e02d4180e6eca1c1344a58cb882585f038698ddbbce4cebd4dcf` |
| `app/Mail/ReclamacionMail.php` | `6f8b619f6f9a66ca53c4e9675e21e1bd2906278dd5bde2832b085a7b76c2f903` |
| `app/Http/Middleware/RecepcionReclamacionesHabilitada.php` | `34b370add64ad18fa55cb713c0170e489036f5786398f1ec21c066fce441f5d0` |
| `app/Http/Requests/StoreReclamacionRequest.php` | `c07983390f069ef6e6c2cb1af7177562eb6b285f6a0d0fbe1b7d6e602cb7378d` |
| `app/Http/Requests/GestionarReclamacionRequest.php` | `ee52c05b57cc2c3a4f998ccf304bc2b1f3737659213f7f08bae748dae3ce1e93` |
| `database/seeders/RolesAndPermissionsSeeder.php` | `a7c8d52321f27800503b595f23080ba903b474860117c008ef3cdbd025283906` |
| `app/Providers/AppServiceProvider.php` | `5bffcf3b3f7a9182ada532c0e664c409fcd6662032c19aecc39c5ab5fd7b25f3` |
| `tests/Support/PreparaReclamaciones.php` | `cd1049fd6e5cf990277289d8ba84a54059a1478033235ef811666814aab2d78d` |
| `tests/Feature/ReclamacionesMigracionTest.php` | `baf6391dc8728c17e6d8c4cb1f546695e4ccd49faee4a8cd159c03bb7b6af122` |
| `tests/Feature/GestionReclamacionesTest.php` | `a0b3dd14768eafb04b9a58e3971693121b84e045180dd8bf205cfea8694487df` |
| `tests/Feature/EnvioReclamacionesTest.php` | `88d01ae14787847d5037884e4e419b5a653c869b82564636d225535bd3e54231` |
| `tests/Feature/ReclamacionRequestsTest.php` | `daf81ed094d615c91cd7005cd4726b53572edf8b908b293398bcb129ec2fdc6b` |
| `tests/Unit/DiasHabilesTest.php` | `3cc23fcafe60cdcc934b10dfd3c8fe39ec54497098914031eae851e027aa88ce` |
| `database/migrations/2026_10_29_000027_create_solicitudes_cotizacion_tables.php` | `c3e8c961abc7f0b4b06f90f3e97b2052a99250a8554af5c0a1307bee730e0255` |
| `database/migrations/2026_10_29_000028_add_cotizaciones_permissions.php` | `e341315ab98a28a7bea2e08cc929fa7a2cffd9ca2eb181bef9fe89205c55b0d7` |
| `database/migrations/2026_10_29_000029_add_origen_to_solicitudes_cotizacion.php` | `193bdca1c178adeea826323723630c0e004b057308dd8eaf324689f166619aa1` |

### 9. Riesgos pendientes
- DDL de PostgreSQL (incluida la función del trigger de inmutabilidad) no ejecutado: revisión previa y prueba en el perfil aislado antes de cualquier base real. Mientras `000022` siga pendiente, no ejecutar `migrate` sin `--path`.
- Se añadió el estado `procesando` en `reclamacion_envios` (el diseño lo mencionaba en el texto pero no en la enumeración) para la reserva anti-duplicados.
- La suspensión por propuesta empieza al **registrar** la propuesta, no al confirmarse su envío (el diseño dice «envío + 5 días hábiles»); decidir si debe moverse al éxito del correo.
- Si el plazo ya se reanudó por vencimiento perezoso, una aceptación o rechazo posterior con fecha dentro de la ventana devuelve 409 (limitación documentada).
- Si el proceso muere tras enviar y antes de guardar, un correo puede duplicarse tras caducar la reserva (aceptado).
- Sin comando programado de reintento de envíos pendientes ni de vencimientos (decisión B3); un envío cuyo despacho falla queda `pendiente` hasta reintento manual.
- Aceptación exige `responder` y rechazo `gestionar` (interpretación de §6; confirmar con el propietario). «Día hábil», feriados (lista vacía), datos del proveedor (A1), base jurídica, política de privacidad y E1–E6 siguen sin resolver. Teléfono obligatorio y «no aplica» del monto: pendientes de asesoría (E2, E3).

### 10. Todavía deshabilitado / no implementado
Recepción pública (sin ruta `POST`, formulario real, vista, JS, CSS, aviso Anexo III ni política de privacidad: el formulario simulado actual sigue igual); panel `/admin/reclamaciones` (controlador, rutas, vistas, sidebar); envío real de correo; comando de vencimientos; purga/anonimización; pruebas PostgreSQL de concurrencia.

### 11. Siguiente paso recomendado
Fase 3 con autorización expresa: endpoint público tras el flag (`POST` con CSRF, `throttle:reclamaciones`, `RecepcionReclamacionesHabilitada`, token en sesión). Antes, y con autorización, revisar en el perfil PostgreSQL aislado la migración 000030 y la concurrencia de la numeración.

---

## SPRINT 20 — FASE 2.1: PREPARACIÓN DE VALIDACIÓN POSTGRESQL

**Fecha:** 2026-10-31. **Naturaleza:** auditoría estática y preparación de procedimiento, **exclusivamente offline**. No hubo conexión a PostgreSQL, `pg_isready`, `probe`, `check`, Artisan, migraciones ni PHPUnit; no se activó el consentimiento ni se abrió el perfil local; no se modificó ninguna migración, modelo, servicio, configuración, prueba ni frontend; no se enviaron correos; sin staging, commits ni push. **PostgreSQL NO ha sido validado.** Detalle completo, tablas y comandos: `docs/sprint-20-fase-2-1-validacion-postgresql.md` (SHA-256 `e71cbd044a635b86f2d3a4b7688735f8850a6b0016d8c11666e5151d8d1f3d40`).

### 1. Alcance y archivos inspeccionados
Migraciones 000030/000031, modelos, `GestionReclamaciones`, `EnvioReclamaciones`, `NumeradorReclamaciones`, `Reclamaciones`, `AuditoriaReclamaciones`, las cinco clases de prueba nuevas; `phpunit.pgsql.xml`, `scripts/pgsql-test.php`, `config/pgsql-testing-database.php`, plantilla del perfil, `PostgresTestGuard`, `GuardedPostgresConnector`, `PostgresTestPreflight`, `ConcurrentTestSession`, `ConcurrencyPostgresTest`, `RequiresIsolatedPostgres`, `docs/postgresql-testing-*.md`, SQL de aprovisionamiento del clúster y el código instalado de `PostgresBuilder`/`PostgresGrammar`. Niveles: C comprobado en código, R razonado, NV no verificable offline.

### 2. Compatibilidad encontrada
**Veredicto: CONDICIONADO.** Sintaxis de triggers, función PL/pgSQL, 11 CHECK, índice parcial único, `uuid`, FK (`RESTRICT`/`SET NULL`), transacciones y `FOR UPDATE` son compatibles con PostgreSQL 18 (R). La numeración anual es correcta bajo `READ COMMITTED` (el rol del perfil lo fija y el guard lo exige): sin duplicados, sin huecos tras rollback, años distintos sin contención mutua, sin espera circular (R). El `SET NULL` de la FK dispara el trigger de inmutabilidad y la condición lo admite (R).

### 3. Riesgos
- **B1 (ALTA, bloqueante, C):** `000030` usa `CREATE FUNCTION` sin `OR REPLACE`. `migrate:fresh`/`db:wipe` solo eliminan tablas (`PostgresBuilder::dropAllTables`), no funciones; la segunda reconstrucción fallaría con `42723`. Afectaría a todas las etapas del PG-TEST-05B-2A (3-10) que usan `RefreshDatabase`/`DatabaseMigrations`. Corrección propuesta, no aplicada: `CREATE OR REPLACE FUNCTION`.
- **B2 (ALTA, C):** pruebas nuevas no portables (`sqlite_master`, texto `UNIQUE`, varios fallos en una transacción PostgreSQL abortada, `rollBack()` de la transacción de la prueba); la suite «isolated» las incluye en la etapa 10.
- **B3 (MEDIA, R):** `uuid` rechaza cadenas no UUID (`22P02`) donde SQLite devuelve 0 filas; validar con `whereUuid` o `Str::isUuid`.
- **B4 (MEDIA, R):** sin `lock_timeout` en producción, el contador retiene su bloqueo durante toda la transacción del alta.
- **B5 (MEDIA, R):** bajo `REPEATABLE READ`/`SERIALIZABLE` el alta fallaría con `40001` (sin duplicados).
- **B6 (BAJA, R):** el trigger no cubre `TRUNCATE`, ni al propietario ni a un superusuario.
- **B7 (BAJA, C):** `es_menor`/`monto_no_aplica` como cadena (`"false"`) mal interpretado; convertir con `boolean()` en la Fase 3.
- **B8/B9 (INFO):** `000031::down()` es un no-op deliberado; `migrate:rollback` revierte el lote completo; nombres ≤ 63 caracteres.
- Las cápsulas y líneas base Git anteriores están obsoletas por los cambios de la Fase 2; hay que recalcular la huella y crear una cápsula nueva.

### 4. Diseño de las pruebas de concurrencia
Dos sesiones con `ConcurrentTestSession` y `DatabaseTruncation` (no `RefreshDatabase`), protocolo determinista del repositorio: B retiene el bloqueo, A queda bloqueada (`55P03` por `lock_timeout`), B confirma o revierte y A repite. Escenarios C1–C13: mismo año con fila existente e inexistente, años distintos, rollback propio y ajeno, excepción tras asignar (sin bloqueo huérfano), 20 altas alternadas sin duplicados ni huecos, mismo token (bloqueo y carrera tardía `23505`), reserva de envío entre dos trabajadores, orden de bloqueo envío→reclamación sin `40P01`, FK + trigger al borrar el actor y aislamiento `read committed`. DDL D1–D11: catálogo, cada CHECK (`23514`), unicidades, historial inmutable (`P0001`), `SET NULL`, `RESTRICT`, `uuid` inválido, `down()`/`up()`, **dos `migrate:fresh` seguidos**, idempotencia de 000031, `TRUNCATE`. Son diseño: no se escribieron ni ejecutaron.

### 5. Procedimiento preparado
Etapas R0 (energía conectada, Git, perfil por hash, consentimiento vacío, procesos con el detector de la cápsula) → R1 (`pg_isready` y `probe`, nueve campos: versión 18.6, `system_identifier 7702263743609677164`, `horus_pgt_55433`, `127.0.0.1:55433`, `horus_l2_test`, `horus_l2_test_runner`, solo lectura, además `UTC`, `public`, `read committed`) → R2 (diagnóstico de esquema de solo lectura) → R3 (consentimiento, `check`, `migrate`, `migrate:rollback`, `migrate`, dos `migrate:fresh`, retirada en `finally`) → R4 (`--filter` de las dos clases nuevas) → R5 (`probe` final, consentimiento vacío, hash del perfil, procesos, Git, SHA-256 de evidencias). Lectura [L], conexión de solo lectura [C] y escritura [E] están separadas en el documento. Ningún comando muestra contraseñas.

### 6. Comandos que requieren autorización
Todo `pg_isready`/`probe`/diagnóstico (lectura de conexión); arranque del servidor; activar `HORUS_PG_TEST_CONFIRM`, `check`, `artisan migrate|migrate:rollback|migrate:fresh` y `test` (destructivo, por etapa). Corregir B1 y escribir las pruebas de §4 también exige autorización de código.

### 7. Estado Git
Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, índice vacío (0 entradas), 2 stashes intactos, 102 entradas en `git status --short` (101 de antes más este documento). Todo el trabajo pendiente anterior se conserva.

### 8. SHA-256
`docs/sprint-20-fase-2-1-validacion-postgresql.md`: `e71cbd044a635b86f2d3a4b7688735f8850a6b0016d8c11666e5151d8d1f3d40`. El de `PROJECT_CONTEXT.md` actualizado se informa en el informe final de la fase (un archivo no puede contener su propio hash).

### 9. Siguiente paso
Revisión del propietario. Autorizaciones separadas, en orden: (a) corrección de B1 y de las pruebas no portables, y escritura de las clases PostgreSQL; (b) cápsula nueva y línea base Git; (c) preflight de solo lectura; (d) ejecución destructiva por etapas. Hasta entonces el Libro de Reclamaciones permanece deshabilitado y PostgreSQL sin validar.

---

## SPRINT 20 — FASE 2.1: CORRECCIÓN Y PREPARACIÓN POSTGRESQL

**Fecha:** 2026-10-31. **Naturaleza:** corrección offline de los hallazgos de la auditoría de la Fase 2.1, pruebas PostgreSQL preparadas y cápsula nueva. **Ejecutado:** cambios de código y pruebas, PHPUnit en SQLite en memoria, Larastan, Pint, `git diff --check`, enumeración `--list-tests` sin arrancar Laravel y validaciones offline de la cápsula. **No ejecutado:** conexión PostgreSQL de ningún tipo (`pg_isready`, `probe`, `check`), migraciones reales, pruebas del perfil PostgreSQL, cambio del consentimiento, envío de correos. **PostgreSQL NO ha sido validado.** El Libro de Reclamaciones sigue deshabilitado; no hay endpoint público, vistas, JS ni CSS nuevos. Detalle técnico completo: `docs/sprint-20-fase-2-1-validacion-postgresql.md` §12.

### 1. Problemas originales, causas raíz y correcciones
- **B1 (ALTA):** `CREATE FUNCTION` en 000030. Causa raíz comprobada en el Laravel instalado: `PostgresBuilder::dropAllTables()` solo emite `drop table … cascade`, por lo que `migrate:fresh`/`db:wipe` dejan la función en `public`. **Corrección:** `CREATE OR REPLACE FUNCTION`; `down()` mantiene el orden trigger → tablas → función y sin `CASCADE`.
- **B2 (ALTA):** pruebas comunes no portables. **Corrección:** savepoint por cada violación esperada, `UniqueConstraintViolationException` en lugar del texto `UNIQUE`, SQLSTATE exigido en PostgreSQL, catálogo de objetos según el motor; la prueba que revertía la transacción de `RefreshDatabase` pasa a `tests/Unit/NumeradorReclamacionesTest` (sin base de datos). Sin pérdida de cobertura.
- **B3 (MEDIA):** `22P02` en columnas `uuid`. **Corrección mínima:** `GestionReclamaciones::bloquear()` responde «no encontrada» antes de consultar si la cadena no es UUID y normaliza a minúsculas.
- **B4/B5 (MEDIA):** retención del bloqueo del contador y dependencia de `READ COMMITTED`. **Sin cambio de producción** (no se altera el aislamiento ni se añaden bloqueos o `lock_timeout` de aplicación: decisión del propietario). `NumeradorReclamaciones::siguiente()` acepta una conexión opcional para que la segunda sesión de prueba ejecute el código real.
- **B7 (BAJA):** `"false"` interpretado como verdadero. **Corrección:** el servicio interpreta `es_menor` con `FILTER_VALIDATE_BOOLEAN` y rechaza valores ambiguos; `StoreReclamacionRequest::datosReclamacion()` entrega `boolean()` real. Regla para la Fase 3: el controlador usará `datosReclamacion()`.
- **000031:** `down()` no-op **conservado** por decisión justificada (no revocar en silencio concesiones posteriores del propietario ni permisos que el seeder vuelve a definir); `up()` idempotente y probado.

### 2. Archivos
**Modificados:** `database/migrations/2026_10_30_000030_create_reclamaciones_tables.php`, `app/Services/NumeradorReclamaciones.php`, `app/Services/GestionReclamaciones.php`, `app/Http/Requests/StoreReclamacionRequest.php`, `tests/Feature/ReclamacionesMigracionTest.php`, `tests/Feature/GestionReclamacionesTest.php`, `tests/Feature/ReclamacionRequestsTest.php`, `docs/sprint-20-fase-2-1-validacion-postgresql.md`, este archivo. **Creados:** `tests/Unit/NumeradorReclamacionesTest.php`, `tests/PostgresIntegrity/ReclamacionesDdlPostgresTest.php`, `tests/PostgresIntegrity/ReclamacionesConcurrenciaPostgresTest.php`, `tests/PostgresIntegrity/Concerns/ReclamacionesPostgres.php`. **Sin cambios:** 000031 y 000027–000029, modelos, configuración, `AuditLog`, `SensitiveData`, frontend y demás módulos.

### 3. Migraciones
000030: única modificación, `CREATE OR REPLACE FUNCTION` y comentarios. 000031: sin cambios. Ninguna migración se ejecutó fuera de SQLite en memoria.

### 4. Pruebas nuevas
`ReclamacionesDdlPostgresTest` (D1-D11, 24 entradas, `RefreshDatabase`) y `ReclamacionesConcurrenciaPostgresTest` (C1-C13, 16 entradas, `DatabaseTruncation`, dos sesiones con `ConcurrentTestSession`), ambas en `tests/PostgresIntegrity` (fuera de `phpunit.xml`; se omiten antes de arrancar Laravel fuera del perfil aislado). Comunes nuevas: UUID inexistente/malformado/mayúsculas, booleanos textuales, `000031::down()` conservador, objetos residuales tras `down()`.

### 5. Resultados medidos (solo SQLite en memoria)
Reclamaciones con `--filter`: 135 pasadas, 730 aserciones. Suite completa: **1406 pasadas, 1 omitida (preexistente), 9011 aserciones, exit 0**. Larastan nivel 6: sin errores. PHPStan nivel 5 adicional sobre las pruebas nuevas (configuración de scratch): sin errores. Pint `--test` en los 11 archivos PHP tocados: passed. `git diff --check`: sin errores. Enumeración offline de la selección: 175 entradas, 115 métodos, 8 clases. `phpunit.xml` fuerza SQLite `:memory:` y no había variables `DB_*`/`HORUS_PG*` en el proceso.

### 6. Resultados no medidos y compatibilidad PostgreSQL pendiente
Ningún comportamiento en PostgreSQL: DDL, función/trigger, CHECK y nombres de restricciones, SQLSTATE, `uuid`, bloqueos `FOR UPDATE`, `ON CONFLICT`, aislamiento, interbloqueos, `TRUNCATE`. Las 40 entradas PostgreSQL y la ejecución de las 135 comunes en PostgreSQL están **preparadas, no probadas**.

### 7. Diseño de concurrencia
Dos backends reales en el mismo proceso PHP (sin `pcntl` ni procesos adicionales en Windows), protocolo determinista del repositorio: la sesión B retiene el bloqueo con el código real; la principal espera con `lock_timeout` corto **de sesión** (restaurado al valor previo) hasta `55P03`; B confirma o revierte; la principal repite. Cubre contador existente/inexistente, años distintos, rollback del primer y del segundo trabajador, excepción tras asignar, recuperación del bloqueo, 20 altas sin duplicados ni huecos, mismo token (bloqueo y carrera tardía `23505`), reserva de envíos, orden envío → reclamación sin `40P01`, FK + trigger y `READ COMMITTED`. No se cambia ninguna configuración del servidor.

### 8. Cápsula nueva y línea base
`%LOCALAPPDATA%\HorusPgTest55433\capsulas\pgt05b2a-etapa8-reclamaciones-capsule` (durable, fuera del repositorio), derivada de `pgt05b2a-etapa6-capsule-r3` sin modificarla. Conserva identidad de nueve campos, detector de procesos, Job Object, plazos 180/720/900 s, consentimiento explícito con retirada en `finally` e inventario de 150 evidencias históricas; fija con SHA-256 herramientas, migraciones 000030/000031, servicios y las 8 clases + 2 traits de prueba. Validaciones offline: `verify-static` 54/54, `verify-ast` sin errores, detector 12/12, partes del envoltorio 8/8, plazos 43/43. `validate-offline` pasó todo lo previo al detector y **se detuvo correctamente** por un terminal integrado de VS Code abierto; el resto (Git, índice, stashes, variables, huella) se verificó con una réplica de solo lectura. **La línea base Git definitiva, el hash del manifiesto y el del lanzador se calculan después de congelar este documento** (cualquier edición del repositorio los cambia); constan en `release.json` y `preparation/validation-summary.json` de la cápsula y en el informe de entrega.

### 9. SHA-256 completos (repositorio, al congelar el código)
| Archivo | SHA-256 |
|---|---|
| `database/migrations/2026_10_30_000030_create_reclamaciones_tables.php` | `F776EB5E5515BD8F11E738063A3499E88E58B1F42F15A7C702E92F382261DD9D` |
| `database/migrations/2026_10_30_000031_add_reclamaciones_permissions.php` | `BFA51F431563B5AB3E57F075F3F651CCFA25BB1558831EA684F1031AB334EBC0` |
| `app/Services/NumeradorReclamaciones.php` | `FCDA91868A96E44BB75B9E86E10ACE8551B523C84D1CDECBF72FCD09E6676DCF` |
| `app/Services/GestionReclamaciones.php` | `2E0105B2F185C6502FCF7989AFD0F5629167EDFA8A333CEBFAAD25E99635677D` |
| `app/Http/Requests/StoreReclamacionRequest.php` | `FA289D89CD2720E800B39A96F9F70337F054BFCF7D3AEF09F62440EC116ABFC3` |
| `tests/Feature/ReclamacionesMigracionTest.php` | `F3C61757719524D3E17F53612956E9FB0A3AA87364EB2ABA371DF61A16B1472B` |
| `tests/Feature/GestionReclamacionesTest.php` | `33EA07C956C1DD4A480569455185FF6DB02732FC1A28FE226830234166C7223D` |
| `tests/Feature/ReclamacionRequestsTest.php` | `2C86EFF9197D72894D733EB2C1714EB3CBD5CEFC6C23355CB50A25B2121A46EF` |
| `tests/Unit/NumeradorReclamacionesTest.php` | `9DD54DB21557BD565F46303650DD402F90D8AFF4FD438C40B23406C1AE4A314A` |
| `tests/PostgresIntegrity/ReclamacionesDdlPostgresTest.php` | `9C9A99EC4545A0FD4365176A11E16B6B23F6BD325E72FC5BD4781AF9BC272F27` |
| `tests/PostgresIntegrity/ReclamacionesConcurrenciaPostgresTest.php` | `85BA8156B39FAA3FB9A1C91220501702D4F2EAE29B4E462B95F4CF4011B92ED3` |
| `tests/PostgresIntegrity/Concerns/ReclamacionesPostgres.php` | `7F7E2082F6E12509CE1073D32241B645B61F68C54B60C23FD01D6D080560E165` |
| `tests/Support/PreparaReclamaciones.php` | `CD1049FD6E5CF990277289D8BA84A54059A1478033235EF811666814AAB2D78D` |
| `database/migrations/2026_10_29_000027_create_solicitudes_cotizacion_tables.php` | `C3E8C961ABC7F0B4B06F90F3E97B2052A99250A8554AF5C0A1307BEE730E0255` |
| `database/migrations/2026_10_29_000028_add_cotizaciones_permissions.php` | `E341315AB98A28A7BEA2E08CC929FA7A2CFFD9CA2EB181BEF9FE89205C55B0D7` |
| `database/migrations/2026_10_29_000029_add_origen_to_solicitudes_cotizacion.php` | `193BDCA1C178ADEEA826323723630C0E004B057308DD8EAF324689F166619AA1` |

### 10. Estado Git
Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, índice vacío, 2 stashes intactos, sin commits, staging ni push. `git status --short`: 106 entradas (102 anteriores + 4 archivos de prueba nuevos); todo el trabajo previo se conserva.

### 11. Riesgos pendientes
PostgreSQL sin validar; B4/B5 sin mitigación de producción (decisión del propietario); ventana de correo duplicado entre envío y registro; `TRUNCATE` y propietario fuera del alcance del trigger; la cápsula exige cerrar los terminales de VS Code y una huella Git sin cambios; endpoint público (Fase 3) pendiente y con la regla de `datosReclamacion()`.

### 12. Próximo paso
Con autorización exclusiva del propietario: cerrar los terminales de VS Code, repetir `validate-offline.ps1` de la cápsula hasta `OFFLINE READY: stage 8`, y lanzar desde una ventana `cmd.exe` independiente, con energía conectada:
`powershell -NoProfile -ExecutionPolicy Bypass -File "%LOCALAPPDATA%\HorusPgTest55433\capsulas\pgt05b2a-etapa8-reclamaciones-capsule\run-authorized-stage8.ps1" -ExecuteAuthorizedStage8 -Baseline <baseline de release.json>`.
No iniciar la Fase 3.

---

## APLICACIÓN DE MIGRACIONES EN LA POSTGRESQL DE DESARROLLO (`horus_group`)

**Fecha:** 2026-10-31. **Autorización:** expresa del propietario para esta operación concreta («aplicar las migraciones pendientes directamente en la base PostgreSQL que usa Laravel»). Levanta, **solo para esta aplicación**, la prohibición de `AGENTS.md` §4 de migrar `horus_group`. El resto de las restricciones siguen vigentes: S7/55432 suspendido, sin seeders, `migrate:fresh`, `db:wipe` ni `rollback`.

### 1. Base de datos utilizada
Conexión normal del proyecto (`.env`, sin modificar): `pgsql`, `127.0.0.1:5432`, base `horus_group`, usuario `postgres`, PostgreSQL 18.6. **No** se usó el clúster aislado `55433`/`horus_l2_test`, `phpunit.pgsql.xml` ni el puerto `55432`. No se imprimieron credenciales.

### 2. Estado previo y respaldo
- 15 migraciones aplicadas (lotes 1–3, hasta `000011`), 24 tablas.
- Datos: 3 usuarios, 10 estudiantes, 8 cursos, 15 matrículas, 39 permisos.
- 14 migraciones pendientes.
- Respaldo previo con `pg_dump -Fc`: `%LOCALAPPDATA%\HorusBackups\horus_group-pre-migrate-20261031-113430.dump`, 65 373 bytes, SHA-256 `A5798722D782E9F2B479A38248355C79361614317D75C8DFD9F61D81001DB3D8`. Está fuera del repositorio.

### 3. Revisión previa
- Los `up()` solo añaden tablas, columnas, índices, CHECK, trigger, función y permisos (`insertOrIgnore`, sin resincronizar).
- Todo `drop`/`delete` está en los `down()`, que no se ejecutaron.
- `000023` solo actualiza `matricula_notifications`, que estaba vacía: sin efecto.
- `000013` valida antes los datos existentes. No encontró incompatibilidades.

### 4. Migraciones aplicadas (lote 4, un solo comando con `--path` explícitos, exit 0)
`000012`, `000013`, `000020`, `000021`, `000023`, `000024`, `000025`, `000026`, `000027`, `000028`, `000029`, `000030`, `000031`: 13 en total. Después se ejecutó `php artisan permission:cache-reset`.

**`000022` (`registros_pendientes`) se dejó `Pending` a propósito.** La verificación de correo sigue suspendida por decisión del propietario (`docs/sprint-15-preparacion-integracion.md` §3, §5 y §9), y ninguna otra migración depende de ella. Por eso **sigue sin poder ejecutarse `php artisan migrate` sin `--path`**: aplicaría `000022`. Si se reactiva la verificación, basta con `php artisan migrate --path=database/migrations/2026_10_28_000022_create_registros_pendientes_table.php`.

### 5. Verificación posterior (solo lectura)
- `migrate:status`: 28 `Ran` y solo `000022` `Pending`.
- **Tablas nuevas (0 filas):**
  - `matricula_notifications`, `curso_publicaciones`;
  - `servicios_tecnologicos`, `servicio_publicaciones`;
  - `solicitudes_cotizacion` (con la columna `origen`), `cotizacion_historial`;
  - `reclamaciones`, `reclamacion_contadores`, `reclamacion_historial`, `reclamacion_envios`.
  - `registros_pendientes` no existe.
- **Restricciones:**
  - `matriculas_vigente_unica`: índice parcial `WHERE deleted_at IS NULL AND estado <> 'anulada'`.
  - CHECK `cursos_cupos_rango`, `cursos_precio_no_negativo` y `matriculas_monto_no_negativo`.
  - CHECK de servicios y cotizaciones.
  - Los 7 CHECK propios de `reclamaciones`, los 4 de `reclamacion_envios` y los CHECK de enum (36 CHECK en total).
  - Índice parcial `reclamacion_envio_tipo_canal_unico`.
  - Trigger `reclamacion_historial_inmutable`, habilitado (`O`), y su función.
- **FK:**
  - `cascade`: `matricula_notifications` → `matriculas`.
  - `restrict`: `curso_publicaciones` → `cursos`, `servicio_publicaciones` → `servicios_tecnologicos`, `solicitudes_cotizacion` → `servicios_tecnologicos`, `cotizacion_historial` → `solicitudes_cotizacion`, y `reclamacion_historial` / `reclamacion_envios` → `reclamaciones`.
  - `set null` hacia `users`.
- **Permisos:** 57 en total (39 + 18). Por rol: admin 57, coordinador 21, secretaria 9. Incluye `reclamaciones.ver`, `reclamaciones.gestionar` y `reclamaciones.responder`, solo para admin.
- **Módulos existentes intactos:** mismas filas en usuarios, estudiantes, cursos, matrículas, chat, roles y sesiones.
- **Conexión:** Laravel conecta correctamente (`db:show`, consultas de verificación).

### 6. Estado
- **Libro de Reclamaciones:** tablas creadas y vacías, **deshabilitado** (`reclamaciones.enabled=false`), sin ruta pública ni panel. Resend, `matricula-mail` y la verificación siguen desactivados y el catálogo del chatbot apagado. No se envió ningún correo.
- **Etapa 8 PostgreSQL (perfil aislado 55433):** sigue **pendiente y no validada**. La auditoría offline encontró:
  - `23503` → `23001` en `ReclamacionesMigracionTest:218` y en D6;
  - el rediseño de D9 por la desconexión de `db:wipe`;
  - la necesidad de una cápsula nueva.

  Nada de eso se aplicó en esta tarea. Que las migraciones se apliquen sin errores en `horus_group` **no** sustituye las pruebas de concurrencia ni de DDL.
- **Frontend, chatbots, `.env`, migraciones y código:** sin cambios. Solo se modificó este archivo.

### 7. Estado Git
Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, índice vacío, 2 stashes intactos, sin staging, commits ni push. `git status --short`: 106 entradas antes de editar este archivo, que ya figuraba como modificado.

### 8. Advertencias
- La aplicación local contra `horus_group` ya tiene `matricula_notifications`, así que el alta de matrículas desde el panel vuelve a funcionar (S15-02 resuelto). Aprobaciones y Respaldo tienen sus permisos.
- Ante un fallo posterior, se prefiere restaurar el respaldo a usar `migrate:rollback`. Los `down()` de 000012/000021 borran permisos asignados a mano, y los de 000020/000021/000030 eliminan tablas.

---

<a id="auditoria-global-ronda-4"></a>
## AUDITORÍA GLOBAL — RONDA 4 (31/10/2026, tras aplicar 13 migraciones en `horus_group`)

Auditoría y planificación **solo de lectura**. No se modificó código, migraciones, `.env`, vistas, chatbots ni datos; no se ejecutaron migraciones, pruebas, PostgreSQL ni correos; el Libro de Reclamaciones sigue apagado. Evidencia: **C** = comprobado leyendo código/rutas/migraciones en esta ronda; **H** = hipótesis pendiente de reproducir. Ningún hallazgo fue reproducido en ejecución. Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, índice vacío, 2 stashes intactos, 106 entradas en `git status`.

**Método.** Se leyeron `AGENTS.md`, el contexto de las tres rondas previas, `routes/web.php`, `bootstrap/app.php`, `DashboardController`, `UserController`, `MatriculaController` (auditoría), `GestionReclamaciones`, `NumeradorReclamaciones`, `Reclamaciones`, `Reclamacion`, `StoreReclamacionRequest`, migración `000030`, `routes/console.php`, `config/reclamaciones.php`, `.env.example` y la página pública del libro. **No se repitió** la revisión completa de las rondas 1–3 (A-/M-/B-/N-): se comprobó qué sigue vigente. No se leyeron línea a línea las vistas Blade ni se usó navegador.

### 1. Estado global
- **Backend académico y comercial:** sólido en transacciones, bloqueo y permisos (confirmado por las rondas previas). Brecha principal: **sin auditoría en matrículas, procesos, aprobaciones ni acceso** (R-01, M-04).
- **PostgreSQL de desarrollo:** 28 migraciones aplicadas, 000022 pendiente a propósito. **No ejecutar `php artisan migrate` sin `--path`.** Respaldo previo fuera del repo (SHA-256 en la sección de aplicación).
- **Libro de Reclamaciones:** backend completo, apagado (`enabled=false`), sin ruta, sin panel, sin job programado. La página pública sigue simulando el envío (`data-endpoint=""`, número `SIM-…`): **N-50 vigente (C)**.
- **Riesgo de proceso:** el trabajo de los sprints 19–20 (cotizaciones, reclamaciones, ~70 archivos sin seguimiento, 30 modificados) **no está en ningún commit**; solo existe en el árbol de trabajo (R-10).

### 2. Hallazgos nuevos o reconfirmados en esta ronda
- **ALTO · R-01 (C)** Las altas, ediciones, anulaciones, eliminaciones y restauraciones de matrículas no se auditan; solo `matriculas_csv_exported`. Archivo: `MatriculaController.php` (único `AuditLog::record`, línea ~309). Consecuencia: cambios de estado, monto y cupo sin responsable ni rastro. Solución: Fase 2c ya diseñada (`AuditoriaAcademica`, acciones `matricula_*`, última escritura de la transacción). Complejidad media. Sin migración. Pruebas: `MatriculaOperationsTest` ampliada, atomicidad (un fallo de auditoría revierte), sin datos personales en la auditoría.
- **ALTO (latente) · R-05 (C)** `GestionReclamaciones::resolverVencimiento()` no tiene ningún llamador y `routes/console.php` solo programa `horus:matriculas-notificar`. El vencimiento solo se evalúa cuando alguien abre o gestiona el caso: un reclamo olvidado nunca pasa a vencido ni avisa. Latente porque el módulo está apagado. Solución: comando programado de vencimientos (con `withoutOverlapping()`) y aviso interno previo al plazo. Sin migración. Pruebas: reloj simulado y feriados.
- **ALTO · N-50 (C, vigente)** Formulario público simulado. La Fase 3 debe sustituir `data-endpoint` por la ruta real y quitar el modo simulado a la vez; requiere decisión del propietario y autorización de frontend.
- **MEDIO · R-03 (C/H)** `reclamaciones` guarda documento, correo y domicilio en claro, con índice sobre `numero_documento`. La conservación es obligatoria, así que no hay purga; falta decidir cifrado a nivel de aplicación o de disco, quién ve el documento completo y si `admin/respaldo` debe excluir la tabla (no se verificó el comportamiento del respaldo con ella). Requiere asesoría de protección de datos (Ley 29733).
- **MEDIO · R-04 (C)** `consultar()` (lectura) abre transacción, bloquea la fila, puede mutar estado por vencimiento y escribe una auditoría por cada vista: dos usuarios que abren el mismo caso se serializan y la auditoría crece con cada consulta. Aceptable como rastro de acceso; revisar al conectar el panel.
- **MEDIO · M-01 (C, vigente)** `DashboardController` muestra ingresos del mes, total de usuarios y de roles a los tres roles; solo la actividad de auditoría se limita por permiso. La corrección de datos es backend; la vista requiere autorización.
- **MEDIO · A-02 (C, vigente)** `UserController::update` valida `roles` con `usuarios.asignar-roles`, pero cambia correo y contraseña de cualquier usuario con solo `usuarios.editar`, incluido un administrador. Solución: regla en `AdminProtection` (solo `admin` edita cuentas con rol `admin`).
- **MEDIO · N-07 (C, vigente)** Sin `trustProxies`/`trustHosts` en `bootstrap/app.php`. Tras un proxy, los límites (`login`, `reclamaciones` por IP, chat) se comparten y la IP de auditoría es la del proxy; el antispam del libro depende de `request()->ip()`.
- **MEDIO · N-45 / `.env.example` (C)** Sesión, caché, cola y bloqueos en base de datos; `APP_DEBUG=true`, `MAIL_MAILER=log`, `SESSION_ENCRYPT=false`. Lista de despliegue obligatoria.
- **MEDIO · R-10 (C)** Trabajo sin commit: 30 archivos modificados y ~70 nuevos (cotizaciones, reclamaciones, pruebas, documentación) solo en el árbol de trabajo. Riesgo de pérdida por limpieza o fallo de disco. Se registra sin actuar (commits prohibidos sin autorización).
- **BAJO · R-06 (C)** `avisarInternamente` crea una notificación por usuario en un bucle; aceptable con pocos usuarios.
- **Verificado sin hallazgo (C):** rutas `/admin` con `auth` + rol y permisos por acción; el libro no tiene ruta pública (sin exposición); modelo `Reclamacion` inmutable (`updating`/`deleting` bloqueados, `fillable` vacío); numerador transaccional con `insertOrIgnore` + `lockForUpdate`; alta idempotente por `token_envio` hasheado; validación estricta con campos del servidor prohibidos, honeypot y marca temporal; no hay subida de archivos en `app/` ni en las vistas.

### 3. Resumen por módulo
| Módulo | Estado | Hallazgos abiertos |
| --- | --- | --- |
| Dashboard | Funciona | M-01, M-02, N-28 |
| Estudiantes / Cursos | Auditados (2a/2b) | M-11, M-12 |
| Matrículas | Reglas sólidas | R-01, M-07, M-10 |
| Reportes | Exportación segura | M-12 |
| Procesos / Aprobaciones | Sin auditoría ni separación | M-03, M-04, M-05 |
| Usuarios / Roles | Protegidos por `AdminProtection` | A-01, A-02 |
| Auditoría | Sin índices | M-13, B-01 |
| Notificaciones | Funciona | M-06 |
| Búsqueda | Filtra por permiso | B-04 |
| Configuración | Sin efecto real | M-08 |
| Respaldo | Exportación, no respaldo | M-12, B-07 |
| Cotizaciones / Servicios | ETag e historial | N-01..N-04 |
| Libro de Reclamaciones | Backend apagado | N-50, R-03, R-04, R-05 |
| Middleware / Auth | CSRF y JWT correctos | N-06..N-10, A-02 |

### 4. Riesgos PostgreSQL
Las tablas de reclamaciones existen en `horus_group`, pero **la etapa 8 del perfil aislado sigue sin ejecutarse**: no están medidos el trigger de historial inmutable, los CHECK, el índice parcial de envíos ni la concurrencia del numerador. Pendientes anotados: `23503`→`23001` en `ReclamacionesMigracionTest:218` y D6, rediseño de D9 por la desconexión de `db:wipe`, cápsula nueva. FK sin índice en PostgreSQL (M-13), `audit_logs` y `chat_logs` sin índices ni purga. Sesión/caché/cola en base de datos amplifican la contención. Diferencias SQLite/PostgreSQL: `LIKE`/`ILIKE`, `lockForUpdate`, CHECK y `TO_CHAR`/`strftime` (el dashboard ya ramifica por driver).

### 5. Arquitectura futura (solo evaluada, no implementada)
- **Supabase Storage.** Laravel sigue siendo la única autoridad: el panel sube archivos al servidor, que valida MIME real, tamaño y dimensiones y los entrega a un bucket **privado** con la clave de servicio (solo en servidor, nunca en el navegador). Tabla `medios` (bucket, ruta, hash, mime, tamaño, estado `borrador|autorizado|retirado`, autor) vinculada a `curso_publicaciones`/`servicio_publicaciones`. La web pública sirve solo medios `autorizado` de fichas vigentes (URL firmada corta o bucket público de imágenes editoriales). Requiere migración, permiso nuevo con su migración, política de borrado diferido, topes de tamaño y pruebas con `Storage::fake`. Hoy no existe ninguna subida de archivos (C), así que no hay deuda previa.
- **Chatbot Python/FastAPI.** Ya hay cliente (`HorusAdvisorClient`), contrato y servicio, apagados. Antes de activarlo: *circuit breaker*, pruebas de contrato (`Http::fake`), ámbito público/panel separado (N-12) y resolver N-17 (productor del catálogo firmado, caducidad en caliente, `url_path`, `kid`). El retiro del chatbot PHP solo tras aprobación, conservando widget Blade y robot; `POST /chat/mensaje` queda como fachada con la misma forma `{respuesta, intent, fuente}` para no tocar el frontend.
- **Segundo asistente del panel.** Servicio independiente con tabla/ámbito y token de servicio propios, contexto por rol construido en servidor con datos que ese rol puede ver, sin escrituras. Las herramientas tipo MCP, de solo lectura por defecto; toda escritura pasa por los mismos controladores y permisos (nunca acceso directo a la base).
- **Automatización.** Toda tarea programada (vencimientos, purgas, avisos) como comando de Laravel con `withoutOverlapping()`, no como proceso externo con acceso a la BD.

### 6. Mejoras de frontend (requieren autorización; no se tocó nada)
Contraste de `text-gold*`/`text-stone-400` (N-28); alturas del gráfico y `content` de Tailwind (M-02); `scope`/`caption` en tablas (N-30); salto al contenido, `aria-expanded` e `inert` en el menú móvil (N-29); modal propio en lugar de `confirm()` (B-06); anuncio de errores en el libro (N-54); estados vacíos y mensajes consistentes; dashboard por permiso (M-01).

### 7. Plan de sprints propuesto (esperando autorización)
| Sprint | Contenido | Migración | Frontend |
| --- | --- | --- | --- |
| 21 | Fase 2c: auditoría de matrículas (R-01); quitar texto libre del CSV (M-07); regla A-02; auditar procesos, aprobaciones y acceso (M-04) | No | No |
| 22 | Despliegue: `trustProxies`, `JWT_SECRET` propio, sesión/caché/cola, lista `.env` (N-06, N-07, N-11, N-45); comando de vencimientos (R-05); separación de funciones en aprobaciones (M-03) | No | No |
| 23 | Migración revisada: índices (`audit_logs`, FK, `matriculas.curso_id`), snapshot del actor (A-01), purgas (N-34, N-46) | **Sí** | No |
| 24 | Validación PostgreSQL etapa 8 (autorizada aparte): reclamaciones, concurrencia, DDL | No | No |
| 25 | Libro de Reclamaciones Fase 3: ruta pública, panel, correo real; decisiones del propietario sobre N-50 y R-03 | Posible | **Sí (autorizar)** |
| 26 | Pulido de frontend y accesibilidad (sección 6) | No | **Sí (autorizar)** |
| 27 | Supabase Storage (diseño de la sección 5) | **Sí** | Sí |
| 28 | Advisor/FastAPI y asistente del panel | Posible | Mínimo |

Antes de cualquier sprint: consolidar el trabajo sin commit (R-10) cuando el propietario autorice commits.

### 8. Archivos que requerirían cambios
Sprint 21: `MatriculaController`, `AuditoriaAcademica`, `ProcesoController`, `AprobacionController`, `AuthController`, `UserController`, `AdminProtection` y pruebas. Sprint 22: `bootstrap/app.php`, `config/jwt.php`, `routes/console.php`, un comando nuevo, `GestionReclamaciones`, `.env.example` (con autorización). Sprint 23: migraciones nuevas (no editar las aplicadas). Sprint 25: `routes/web.php`, controladores y vistas del libro, `public/assets/js/libro-reclamaciones.js`, `resources/views/pages/legales/libro-reclamaciones.blade.php`.

### 9. Limitaciones y estado
Auditoría estática; sin pruebas ejecutadas en esta ronda (la última validación documentada es la de rondas anteriores); sin navegador ni PostgreSQL de pruebas. No recorridos en esta ronda: vistas Blade, `ReportController`, `RespaldoController` y el interior de `ProcesoController`/`AprobacionController` (se apoya en las rondas 1–3). **La auditoría global no se declara completa.** Siguiente paso: esperar la autorización del propietario para el Sprint 21. Solo se modificó este archivo.

---

<a id="sprint-21-fase-0"></a>
## SPRINT 21 — FASE 0: AUDITORÍA PREVIA Y DISEÑO DE TRAZABILIDAD (31/10/2026)

**Naturaleza:** análisis y diseño. **No se implementó nada**: no se modificó código, pruebas, migraciones, `.env`, vistas ni chatbots; no se ejecutaron migraciones, pruebas ni escrituras en base de datos; no hay staging, commits ni push. Solo se añade esta sección. Evidencia: **C** = comprobado en el código actual (no se asumieron los informes previos); **H** = hipótesis. Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, 2 stashes. La línea base de pruebas **no se re-ejecutó** en esta fase.

**Inspeccionado (completo):** `MatriculaController`, `ProcesoController`, `AprobacionController`, `UserController`, `RoleController`, `Admin\AuthController`, `Api\AuthController` (inicio), `AdminProtection`, `AuditLog`, `AuditoriaAcademica`, `SensitiveData`, modelos `Matricula`, `ProcesoInterno`, `AprobacionInterna`, requests de matrícula, `RolesAndPermissionsSeeder` (matrices por rol), migración `audit_logs`, `DescartarSesionSinVerificar`, limitador `login`, e inventario de pruebas.

### 1. Diagnóstico por módulo

**Matrículas (C).** Cinco operaciones de escritura (`store`, `update`, `anular`, `destroy`, `restaurar`) con bloqueo en orden cursos→estudiantes→matrícula y transacción, **sin ninguna auditoría**; la única llamada es `matriculas_csv_exported` (CSV), que guarda `filters.busqueda` en texto libre (M-07, puede ser un DNI). `AuditoriaAcademica::MATRICULA` ya existe con lista permitida (`valores`: `estudiante_id`, `curso_id`, `fecha_matricula`, `estado`, `monto_pagado`; `nombres`: `observaciones`) pero ningún controlador la usa. `anular`, `destroy` y `restaurar` no reciben `Request` (hay que añadirlo para el actor). `update` usa `$matricula->update($datos)` (sin `fill()` previo, por lo que no hay diferencias que auditar). `Matricula::create` dispara el observer de la bandeja de correo; la auditoría no interfiere. `anular` repetido ya es no-operación (no devuelve cupo dos veces). `restaurar` de una no eliminada responde 404. Permisos: `ver/crear/editar/anular/eliminar/restaurar/exportar`; coordinador tiene `ver, crear, editar, restaurar` (no `anular` ni `eliminar`); secretaria `ver, crear`; admin todos.

**Procesos (C).** `store`, `update`, `destroy` sin transacción, sin bloqueo y sin auditoría; `destroy` es **borrado físico**. Campos: `titulo`, `descripcion`, `responsable_id`, `estado`, `prioridad`, `fecha_vencimiento`, `created_by`, `observaciones` (texto libre: puede contener datos personales). `exists:users,id` admite cualquier cuenta (M-05). Permisos: `procesos.ver` (admin y coordinador; secretaria no) y `procesos.gestionar` (solo admin hoy).

**Aprobaciones (C).** `store` y `update` envueltas en `DB::transaction` pero **sin bloqueo de fila** (dos decisiones simultáneas se pisan, la última gana); `destroy` sin transacción y físico. El modelo crea notificaciones en `created`/`updated` (dentro de la transacción de `store`/`update`). Se puede cambiar `estado`, `solicitante_id` y `aprobador_id` libremente y revertir estados (M-03, fuera del Sprint 21). Permisos: `aprobaciones.ver` (los tres) y `aprobaciones.gestionar` (admin y coordinador).

**Accesos y usuarios (C).** Login (`Auth::attemptWhen`), logout (incrementa `jwt_version`), registro web/API, recuperación de contraseña, emisión de JWT y las denegaciones 403 **no se auditan**. `UserController` audita `user_created/updated/deleted` y `RoleController` `role_*`, con tres defectos: (a) `user_created`, `user_deleted` y `role_*` usan el actor por defecto `auth()->id()` (solo `user_updated` pasa el actor explícito); (b) un cambio de contraseña **no es visible** en `user_updated` (solo nombre, correo y roles) ni la revocación de sesiones; (c) los eventos guardan nombre y correo en claro (criterio distinto del de Sprint 20, que guarda solo nombres de campos). `AuditLog::record` redacta por nombre de clave y `ip_address` depende de `request()->ip()` (N-07).

**A-02 (C, ampliado).** `UserController::update` exige `usuarios.editar` y solo condiciona `roles` a `usuarios.asignar-roles`; **cualquier titular de `usuarios.editar` cambia nombre, correo y contraseña de cualquier cuenta, incluida la de un administrador** (con el correo cambiado y «olvidé mi contraseña» se completa la toma de cuenta; con la contraseña directa, también). Hallazgos hermanos comprobados en la misma lectura: **A-03** quien tenga `usuarios.asignar-roles` (sin ser admin) puede asignar el rol `admin` a cualquier cuenta o a sí mismo (`assertRoleChangeKeepsAdministration` solo protege la *retirada*); **A-04** quien tenga `usuarios.eliminar` sin ser admin puede eliminar a un administrador que no sea el último (`assertUserCanBeDeleted` solo protege al último y a uno mismo). La prueba `test_assigning_roles_requires_the_assign_roles_permission` solo cubre un objetivo `secretaria`.

**Análisis de los tres roles (C, seeder).** Los permisos `usuarios.*`, `roles.*`, `auditoria.*`, `matriculas.anular` y `matriculas.eliminar` pertenecen **solo a `admin`**; `coordinador` y `secretaria` no tienen ningún `usuarios.*`. Por tanto A-02/A-03/A-04 **no son explotables hoy** con los roles base: solo con un rol personalizado o un permiso concedido a mano. La corrección no cambia el comportamiento de ningún rol base y **no requiere modificar permisos**.

**Pruebas existentes (inventario).** Matrículas: `AdminMatriculaTest` (13), `MatriculaOperationsTest` (42), `MatriculaSensitivePermissionsTest`, `MatriculaIntegrityConstraintsTest`, `AdminMatriculaContextTest`; auditoría: `AuditoriaEstudiantesTest`, `AuditoriaCursosTest`, `AuditoriaAcademicaTest` (unidad), `Sprint12AtomicityTest` (atomicidad de `user_*`/`role_*` con `AuditLog::creating` que lanza); usuarios/roles: `AdminProtectionTest`, `AdminAuthorizationTest`; acceso: `AdminAuthenticationTest`, `AuthHardeningTest`, `Sprint12AuthSecurityTest`. **No hay pruebas de auditoría para procesos, aprobaciones, matrículas ni accesos.** El patrón de atomicidad reutilizable es `AuditLog::creating(fn () => throw …)`.

### 2. Operaciones que necesitan auditoría
| # | Módulo | Operación | Evento (`action`) | Condición |
| --- | --- | --- | --- | --- |
| 1 | Matrícula | crear | `matricula_creada` | siempre |
| 2 | Matrícula | editar (sin pasar a anulada) | `matricula_actualizada` | solo si hay diferencias |
| 3 | Matrícula | `PUT` que anula | `matricula_anulada` (con diferencias) | única fila; no emite `actualizada` |
| 4 | Matrícula | `PATCH anular` | `matricula_anulada` | solo si estaba vigente |
| 5 | Matrícula | eliminar (lógico) | `matricula_eliminada` | solo si existía |
| 6 | Matrícula | restaurar | `matricula_restaurada` | solo si estaba eliminada |
| 7 | Matrícula | exportar CSV | `matriculas_csv_exported` (existente) | sin texto libre (M-07) |
| 8 | Proceso | crear / editar / eliminar | `proceso_creado` / `proceso_actualizado` / `proceso_eliminado` | editar solo con diferencias |
| 9 | Aprobación | crear / editar / eliminar | `aprobacion_creada` / `aprobacion_actualizada` / `aprobacion_eliminada` | editar solo con diferencias |
| 10 | Aprobación | cambio de `estado` | `aprobacion_estado_cambiado` (reemplaza a `actualizada` en esa operación) | solo si cambia |
| 11 | Usuario | crear / editar / eliminar | `user_created` / `user_updated` / `user_deleted` (existentes, endurecidos) | actor explícito, marcador de contraseña |
| 12 | Usuario | rol asignado o retirado | dentro de `user_updated` (`roles` antes/después) | ya existe |
| 13 | Rol | crear / editar / eliminar | `role_*` (existentes) | actor explícito |
| 14 | Acceso | inicio de sesión correcto / fallido | `acceso_login_exitoso` / `acceso_login_fallido` | ver §3 |
| 15 | Acceso | cierre de sesión | `acceso_logout` | siempre |
| 16 | Acceso | contraseña restablecida / recuperación solicitada | `acceso_password_restablecida` / `acceso_recuperacion_solicitada` | solo si la cuenta existe |
| 17 | Acceso | registro público | `acceso_registro` | siempre |
| 18 | Autorización | 403 en `/admin` | `acceso_denegado` | deduplicado (§3) |
| 19 | Autorización | bloqueo por la protección A-02 | `acceso_denegado` con `motivo=proteccion_admin` | siempre |

Fuera del sprint (decisión del propietario si se quiere): autenticación JWT de la API (`/api/auth/*`) y las acciones de Reclamaciones, que ya tienen su propia auditoría.

### 3. Diseño de eventos y campos
**Sin cambio de esquema:** `audit_logs(user_id, action, model_type, model_id, changes JSON, ip_address, created_at)`. Todo el detalle va en `changes` con la forma ya usada en Sprint 20: `{esquema, campos[], antes{}, despues{}}` para actualizaciones; `{esquema, campos[], valores{}}` para creaciones; `{esquema, operacion}` para operaciones mínimas.

- **Convenciones:** `model_type` = FQCN (corrige B-02 en lo nuevo); `model_id` = id de la entidad; actor **siempre explícito** y leído con `AuditoriaAcademica::actorDe($request)`; la auditoría es la **última escritura** de la transacción de la operación (un fallo la revierte).
- **Matrícula:** reutiliza `AuditoriaAcademica::MATRICULA`. Se añade `efecto_cupo` ∈ `ocupa | libera | traslada | ninguno` (decisión ya aprobada en la ronda anterior) mediante un parámetro de detalle con lista cerrada. Cálculo: crear vigente → `ocupa`; crear anulada → `ninguno`; anular o eliminar una vigente → `libera`; restaurar una vigente → `ocupa`; editar con cambio de curso estando vigente → `traslada`; reactivar anulada→vigente → `ocupa`; resto → `ninguno`.
- **Proceso** (nueva lista): `valores` = `estado`, `prioridad`, `responsable_id`, `fecha_vencimiento`; `nombres` = `titulo`, `descripcion`, `observaciones`. En `proceso_eliminado` se guarda `operacion=eliminacion_fisica` y el `estado` final (no el título).
- **Aprobación** (nueva lista): `valores` = `estado`, `tipo`, `solicitante_id`, `aprobador_id`; `nombres` = `titulo`, `descripcion`, `comentario`, `referencia`. `aprobacion_estado_cambiado` añade `autoaprobacion` (booleano: actor = solicitante), información que habilita la separación de funciones futura (M-03) sin implementarla ahora.
- **Usuario / rol:** `user_updated` guarda `campos` y, para contraseña, solo el **marcador** `password_cambiada=true` y `sesiones_revocadas`; nunca el valor ni el hash. Roles y permisos de rol sí con valores (son nombres de configuración, no personales).
- **Acceso:** `changes` = `{esquema, categoria: autenticacion|autorizacion|administracion, …}`. Login correcto: `user_id` = cuenta. Login fallido: `user_id` = cuenta si existe; si no, `null` y `email_dominio` (solo el dominio) — **nunca contraseña, token, identificador de sesión ni correo completo**. 403: `ruta` (nombre), `metodo`, `permiso_requerido` o `motivo`; sin parámetros ni cuerpo de la petición.
- **Duplicados:** (1) llamadas explícitas en controlador/servicio, **no observers de modelo** (no conocen al actor, se disparan en cargas masivas y duplicarían los eventos que ya existen); (2) operaciones sin efecto (actualización sin diferencias, anular dos veces, restaurar una no eliminada, eliminar una ya eliminada) no auditan; (3) eventos de acceso fallidos: una fila por (IP, cuenta) por minuto con `Cache::add`, para que un ataque no multiplique las filas; el bloqueo por *throttle* se registra una sola vez por ventana.
- **Política de fallo:** operaciones administrativas = **fail-closed** (si la auditoría falla, la operación se revierte); eventos de autenticación = **fail-open** (un fallo de auditoría no impide iniciar sesión; se registra en el log de aplicación sin datos sensibles). Requiere decisión del propietario (D-3).
- **403 dentro de transacciones:** una excepción 403 lanzada dentro de `runExclusively` revierte la transacción y con ella la fila de auditoría; el registro de denegaciones va, por tanto, en el manejador de excepciones (`withExceptions`, fuera de la transacción revertida).

### 4. Política de privacidad de lo registrado
1. **Lista permitida por entidad**; lo no listado no se guarda ni por nombre. `SensitiveData::redact` queda como segunda barrera, no como la principal.
2. **Valores** solo de campos de estado/identificadores/importes; **solo nombres** de campos de texto libre o personales (`observaciones`, `descripcion`, `comentario`, `titulo`, `referencia`).
3. Identificadores de estudiante o curso (`estudiante_id`, `curso_id`) sí, porque no revelan identidad fuera del sistema; **DNI, nombres, apellidos, correo, teléfono y dirección de estudiantes: nunca**.
4. **Nunca:** contraseñas, hashes, tokens (JWT, reset, verificación), `jwt_version`, identificador de sesión, claves de API, cuerpo completo de la petición, texto libre del usuario en `filters`.
5. Cuentas del personal: se propone **no repetir nombre y correo** en `user_*` nuevos (solo `campos`); los registros históricos ya contienen esos datos y no se modifican.
6. IP: se conserva (`ip_address`), con la salvedad N-07 (detrás de proxy sería la del proxy). Es un dato personal bajo la Ley 29733; la política de conservación del registro de auditoría queda pendiente (Sprint 23, junto con índices y purga).
7. Verificación en pruebas: recorrer `changes` y fallar si aparece cualquier clave o valor prohibido (DNI, correo, texto de `observaciones`, contraseña usada en la prueba).

### 5. Diseño de la protección A-02 (y A-03/A-04)
Centralizada en `AdminProtection` (ya serializa con `runExclusively` y bloquea la fila del rol `admin`), evaluada **bajo ese bloqueo** con el objetivo releído:
- **R1 (A-02):** modificar nombre, correo, contraseña o roles de una cuenta con rol `admin` exige que el actor sea `admin`. Excepción: un administrador sí puede editar a otro administrador y a sí mismo; se mantienen intactas las reglas de último administrador y de no retirarse el rol a uno mismo.
- **R2 (A-03):** asignar el rol `admin` (en `store` y `update`) exige que el actor sea `admin`, además de `usuarios.asignar-roles`.
- **R3 (A-04):** eliminar una cuenta con rol `admin` exige actor `admin`.
- **Respuesta:** 403 (consistente con el rechazo actual de `asignar-roles`), con auditoría `acceso_denegado` / `motivo=proteccion_admin` escrita por el manejador de excepciones.
- **No cambia permisos ni la matriz de roles** (ver análisis de los tres roles). **No exige reautenticación** (requeriría formularios/vistas: frontend protegido). Opcional y solo backend (D-5): avisar al administrador afectado con una `NotificacionInterna` cuando otro administrador cambie su correo o contraseña.
- **Fuera de alcance, anotado:** `roles.editar` permite a un rol personalizado añadirse permisos a sí mismo (escalada indirecta) y editar los del rol `admin` salvo los críticos; requiere decisión aparte (D-6).

### 6. Migraciones
- **Obligatorias: ninguna.** Todos los eventos caben en `audit_logs` actual (`action` string, `changes` JSON, `user_id` nullable); no hay permisos nuevos (`auditoria.ver/exportar` ya existen); A-02 es lógica de servicio.
- **Recomendada, opcional (D-4):** una migración nueva de **índices** de `audit_logs` (`created_at`, `action`+`created_at`, `model_type`+`model_id`, `user_id`). Justificación: el sprint multiplica las filas (login, 403) y la tabla no tiene ningún índice (M-13); en PostgreSQL las FK no crean índice. Es aditiva, sin pérdida de datos, reversible; se aplicaría con `--path` explícito y respaldo previo, según la política vigente. Se puede diferir al Sprint 23 sin bloquear nada, aceptando consultas más lentas de la pantalla de auditoría.
- **Diferida:** snapshot del actor (A-01) y purga/retención: Sprint 23. Consecuencia mientras tanto: al eliminar un usuario, sus eventos pasan a «Sistema».

### 7. Matriz de pruebas
Todas en SQLite en memoria (`phpunit.xml`), aisladas de `horus_group`; reutilizan el patrón `AuditLog::creating` y los helpers de `AuditoriaEstudiantesTest`.
| Área | Funcionales | Autorización | Atomicidad | Privacidad | Regresión |
| --- | --- | --- | --- | --- | --- |
| Matrículas | 5 eventos + `efecto_cupo` en cada caso (crear vigente/anulada, editar, trasladar, reactivar, anular por `PUT` = un solo evento) | permisos existentes sin cambios (secretaria no anula, coordinador no elimina) | fallo de auditoría revierte matrícula **y** contador de cupo en cada operación | sin DNI/nombres/observaciones; `filters` del CSV sin texto libre | `MatriculaOperationsTest`, `AdminMatriculaTest`, `MatriculaSensitivePermissionsTest`, `MatriculaNotificationTest` |
| Procesos | crear/editar/eliminar, sin diferencias no audita, eliminar repetido | `procesos.gestionar` obligatorio | revierte proceso si falla la auditoría | sin título ni observaciones | `AdminAuthorizationTest` |
| Aprobaciones | crear/editar/eliminar, `estado_cambiado` exclusivo, `autoaprobacion` | `aprobaciones.gestionar` | revierte, incluida la notificación | sin comentario ni descripción | notificaciones del modelo |
| Usuarios/roles | actor explícito, marcador `password_cambiada` | R1–R3 con rol personalizado, admin→admin permitido, secretaria/coordinador sin cambios | revierte usuario y roles | ni contraseña ni hash en ninguna fila | `AdminProtectionTest`, `Sprint12AtomicityTest`, `AdminAuthorizationTest`, `ConcurrencyPostgresTest` (solo preparado) |
| Accesos | login ok/fallido/logout/registro/recuperación; 403 deduplicado | 403 genera `acceso_denegado` | login no falla si falla la auditoría (fail-open) | sin contraseña, token, sesión ni correo completo | `AdminAuthenticationTest`, `AuthHardeningTest`, `Sprint12AuthSecurityTest` |
| Transversal | Larastan nivel 6, Pint solo sobre archivos propios, `git diff --check`, suite completa PHP + `npm test` sin cambios | | | | |
**Alcance de la evidencia:** SQLite no mide `lockForUpdate`, CHECK ni concurrencia; para eso hay que autorizar aparte el perfil PostgreSQL aislado.

### 8. División en fases pequeñas
| Fase | Contenido | Archivos principales | Migración | Orden/dependencia |
| --- | --- | --- | --- | --- |
| 21.1 | Protección A-02/A-03/A-04 | `AdminProtection`, `UserController`, pruebas | No | Primero: es seguridad y no depende de nada |
| 21.2 | Auditoría de matrículas (R-01) + CSV sin texto libre (M-07) | `MatriculaController`, `AuditoriaAcademica`, pruebas | No | Usa lista ya preparada |
| 21.3 | Procesos (transacción, bloqueo, auditoría) | `ProcesoController`, nueva clase de auditoría administrativa | No | Tras 21.2 (reutiliza el patrón) |
| 21.4 | Aprobaciones (bloqueo de fila, auditoría, `estado_cambiado`) | `AprobacionController`, auditoría administrativa | No | Tras 21.3 |
| 21.5 | Endurecer `user_*`/`role_*` | `UserController`, `RoleController` | No | Tras 21.1 |
| 21.6 | Eventos de acceso y denegaciones | listeners de autenticación, `AuthController`, `bootstrap/app.php` (manejador), `AppServiceProvider` | No | Tras 21.1 (necesita el 403 de A-02) |
| 21.7 | (Opcional) índices de `audit_logs` | migración nueva | **Sí** | Antes de 21.6 si se quiere el índice cuando haya volumen |
| 21.8 | Cierre: regresión completa, Larastan 6, Pint, `git diff --check`, actualización final del contexto | — | No | Último |
Cada fase termina con su sección en `PROJECT_CONTEXT.md` (archivos, decisiones, pruebas, resultados, hashes SHA-256, estado Git, pendientes).

### 9. Riesgos y dependencias
1. **Volumen de `audit_logs`** sin índices ni purga al añadir eventos de acceso (mitigación: deduplicación por minuto, opción 21.7, Sprint 23).
2. **Atomicidad vs. 403 en transacción** (el registro se pierde por el rollback): se resuelve con el manejador de excepciones; debe probarse.
3. **Bloqueos en PostgreSQL** no medidos por SQLite; añadir `lockForUpdate` en procesos/aprobaciones cambia el patrón de concurrencia (bajo riesgo, sin FK entre ellos).
4. **Compatibilidad hacia atrás:** los nombres `user_*`/`role_*` y los registros históricos con correo en claro se conservan; la pantalla/exportación de auditoría debe seguir leyendo `changes` antiguos y nuevos (`redactedChanges()`).
5. **N-07 (`trustProxies`)**: sin él, la IP de los nuevos eventos de acceso puede ser la del proxy; es de despliegue (Sprint 22), no bloquea.
6. **A-01** (actor al eliminar usuario) deja eventos como «Sistema» hasta el Sprint 23.
7. **Frontend protegido:** ningún cambio requiere vistas; los mensajes de 403 y de error de validación usan los existentes.
8. **Árbol de trabajo sin commit (R-10):** 106 entradas sin versionar; cualquier fase se sumaría a ese riesgo. `MatriculaController`, `UserController`, `AdminProtection`, `AuditoriaAcademica` y `bootstrap/app.php` no figuran hoy como modificados salvo `AppServiceProvider`, `routes/web.php`, `CursoController`, `EstudianteController` y el seeder: no hay colisión de archivos con las fases propuestas, pero `bootstrap/app.php` y `AppServiceProvider` deberán editarse con cuidado.
9. **Dependencia PostgreSQL:** si se aprueba 21.7, se aplica con `--path`, respaldo previo y verificación; nunca `migrate` sin `--path` (000022 sigue pendiente).

### 10. Decisiones que requieren autorización del propietario
- **D-1** Aprobar el diseño de eventos y la política de privacidad (§3–§4), incluida la regla «solo nombres» para texto libre.
- **D-2** Alcance de A-02: ¿solo R1, o R1+R2+R3 (recomendado)?
- **D-3** Política de fallo: operaciones administrativas fail-closed (recomendado) y autenticación fail-open (recomendado), u otra.
- **D-4** Migración de índices de `audit_logs`: ¿dentro del Sprint 21 (recomendado, 21.7) o diferida al Sprint 23?
- **D-5** Aviso interno al administrador afectado cuando otro cambie su correo/contraseña: ¿sí o no?
- **D-6** ¿Abordar en este sprint la escalada vía `roles.editar`, o dejarla registrada para otro?
- **D-7** Eventos de acceso: ¿incluir la API JWT (`/api/auth/*`)? y, en login fallido de cuenta inexistente, ¿guardar solo el dominio (recomendado) o nada?
- **D-8** Orden de fases (21.1 primero, recomendado) y si cada fase requiere autorización separada.
- **D-9** Si se desea una clase nueva `AuditoriaAdministrativa` (recomendado, separada de la «académica») o ampliar `AuditoriaAcademica`.

### 11. Plan de actualización de `PROJECT_CONTEXT.md`
Una sección nueva al final por fase (21.1 … 21.8), nunca editando las anteriores, con: objetivo; archivos modificados y creados con SHA-256; decisiones del propietario aplicadas; eventos/campos finales; pruebas añadidas y resultados medidos (con el alcance: SQLite en memoria); Larastan/Pint/`git diff --check`; estado Git (rama, HEAD, conteo de `git status`, stashes); migraciones (si las hay) con respaldo y verificación; pendientes. El cierre (21.8) añade una tabla consolidada de eventos y la actualización de los hallazgos R-01, M-04, M-07, A-02, N-03 resueltos o pendientes.

**Estado al cierre de esta fase:** solo se modificó `PROJECT_CONTEXT.md`. Esperando autorización expresa; no se implementa nada hasta recibirla.

---

<a id="sprint-21-1-diseno"></a>
## SPRINT 21.1 — PROTECCIÓN DE ADMINISTRADORES Y PREVENCIÓN DE ESCALADA: DECISIONES Y DISEÑO (31/10/2026)

**Naturaleza:** análisis y diseño. **No se implementó nada**: ni código, ni pruebas, ni migraciones, ni vistas; no se ejecutó ninguna prueba ni escritura en base de datos; sin staging, commits ni push. Solo se añade esta sección. Evidencia: **C** = comprobado en el código actual o en `vendor/` en esta fase; **H** = hipótesis. Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, 106 entradas en `git status` (las mismas que antes de la fase), 2 stashes.

### 1. Decisiones confirmadas por el propietario (Sprint 21)
D-1 diseño de auditoría y privacidad aprobados (campos personales y texto libre solo por nombre). D-2 proteger administradores en edición, asignación de roles y eliminación (R1, R2, R3). D-3 operaciones administrativas atómicas con su auditoría; un fallo de auditoría no impide un login legítimo. D-4 índices de `audit_logs` diferidos al Sprint 23. D-5 notificaciones internas diferidas. D-6 incluir la prevención de escalada por `roles.editar` (R4). D-7 la API JWT se incluirá después en los eventos de acceso (no en 21.1). D-8 trabajo por fases con autorización independiente. D-9 clase independiente `AuditoriaAdministrativa`, sin mezclarla con `AuditoriaAcademica`.

### 2. Hallazgos de la inspección actual (C)
- **Ubicación real de `AdminProtection`:** `app/Services/AdminProtection.php` (no `app/Support/`), coherente con `CLAUDE.md`. Se propone **no moverla** (rompería referencias y pruebas) y crear `AuditoriaAdministrativa` en `app/Support/`.
- **Spatie Permission 6.25.0 con configuración por defecto** (no existe `config/permission.php`): sin *teams*, sin comodines, `events_enabled=false`, caché de 24 h en el almacén de caché (base de datos). No hay `Gate::before`/`Gate::after` en `app/`: `admin` **no** es superusuario por Gate, solo tiene todos los permisos asignados. Las excepciones de `PermissionMiddleware`/`RoleMiddleware` son `UnauthorizedException` (HTTP 403).
- **Rutas de asignación de roles y permisos (exhaustivo, `grep` de `app/`, `routes/`, `database/seeders/`):** `UserController::store` (`syncRoles`, línea 106), `UserController::update` (`syncRoles`, línea 187), `RoleController::store` y `update` (`syncPermissions`), `RolesAndPermissionsSeeder` (sincroniza los tres roles base), y dos comandos de consola (`horus:crear-usuario` → `assignRole`, `horus:promover-admin` → `assignRole('admin')`, solo local). No hay `givePermissionTo` ni permisos directos en la aplicación (solo en pruebas); los comandos son de operador y quedan fuera de la protección HTTP.
- **Solo `UserController` y `RoleController`** gestionan cuentas y roles por HTTP; la API (`/api/auth/*`) no tiene gestión de usuarios.
- **Caché de permisos (C, `vendor/`):** `assignRole`/`removeRole`/`syncRoles` llaman a `forgetCachedPermissions()` **dentro de la transacción**, igual que `syncPermissions`. `RoleController` ya difiere la invalidación a *después del commit* (`forgetPermissionCacheAfterCommit`), pero `UserController` **no**: otra petición puede rellenar la caché con los roles anteriores entre el borrado y el commit y mantenerlos hasta 24 h (H de explotación, C de la ausencia). Es relevante porque la caché alimenta `permission:` en los middlewares.
- **Otra vía hacia una cuenta administradora (C, fuera del alcance de 21.1):** `VerificacionRegistroService::confirmar` establece la contraseña de una cuenta existente (incluida una con roles) a quien demuestra controlar el buzón; hoy apagado por `VERIFICACION` y comparable a «olvidé mi contraseña». Se registra, no se modifica.
- **Transacciones:** `UserController::store` y `RoleController::store` usan `DB::transaction` simple; **no** pasan por `AdminProtection::runExclusively()`. `update`/`destroy` de ambos sí.

### 3. Diagnóstico
**R1 — editar (C, vigente).** `UserController::update` solo exige `usuarios.editar`. Cualquier titular cambia nombre, correo y contraseña de una cuenta `admin` (correo + «olvidé mi contraseña», o contraseña directa). Efecto indirecto adicional comprobado por lectura: **no solo los administradores**; un titular de `usuarios.editar` toma también cualquier cuenta cuyos permisos efectivos superen los suyos (p. ej. un rol personalizado de bajo privilegio sobre una cuenta `coordinador`). Esa extensión se trata como **R1+** (§5, decisión D-10).

**R2 — asignar `admin` (C).** `assertRoleChangeKeepsAdministration` solo protege la *retirada* del rol. Un titular de `usuarios.asignar-roles` puede asignar `admin` a cualquier cuenta, **incluida la propia**, tanto en `update` como en `store` (con `usuarios.crear`). Además puede asignar cualquier rol personalizado con más permisos que los suyos (combinación rol + permisos efectivos).

**R3 — eliminar (C).** `assertUserCanBeDeleted` solo protege al propio actor y al último administrador. Un titular de `usuarios.eliminar` que no es `admin` elimina a cualquier otro administrador mientras quede uno.

**R4 — `roles.editar` (C).** `RoleController::update` valida nombre único y existencia de permisos, y para el rol `admin` impide el renombrado y la pérdida de `CRITICAL_PERMISSIONS`. No impide: (a) que el actor edite un rol que él mismo posee y se añada `usuarios.asignar-roles`, `usuarios.editar` o cualquier permiso que no tiene; (b) añadir a cualquier rol permisos que el actor no posee; (c) **quitar permisos no críticos al rol `admin`** (p. ej. `auditoria.ver`, `auditoria.exportar`, `matriculas.*`), lo que permite sabotear al administrador o ocultar evidencia; (d) `roles.crear` con permisos arbitrarios, que luego se asignan con `asignar-roles`; (e) `roles.eliminar` sobre un rol que el propio actor posee. Cadena de escalada mínima: `roles.editar` → editar el rol propio → añadir `usuarios.asignar-roles` → asignar `admin` (R2).

**Conservación de los roles base (C, seeder).** `usuarios.*`, `roles.*`, `auditoria.*`, `matriculas.anular` y `matriculas.eliminar` son solo de `admin`; `coordinador` y `secretaria` no tienen ningún `usuarios.*` ni `roles.*`. Las reglas siguientes **no alteran** el comportamiento de los tres roles base: el administrador conserva todo, y los otros dos no llegan a esos controladores.

### 4. Principios de diseño
1. **Verdad en la base de datos, no en la caché ni en el nombre del rol.** Los permisos efectivos del actor y del objetivo se calculan con consultas directas a `model_has_roles`, `role_has_permissions` y `model_has_permissions` (unión de permisos por roles y directos, guard `web`), **no** con `getAllPermissions()` / `hasRole()` / relaciones cargadas, que pueden estar obsoletas dentro de la ventana de la caché.
2. **«Administrador» = rol `admin` real** (consulta, no relación cargada). Solo se usa para (i) proteger cuentas `admin` y el rol `admin` y (ii) eximir del techo de permisos (un administrador puede re-concederse cualquier permiso de todos modos). Para todo lo demás rige el **techo de permisos**: *nadie concede lo que no posee*.
3. **Todo bajo el mismo bloqueo.** Cada operación de usuarios y roles (incluidas `store`) entra en `AdminProtection::runExclusively()`, que bloquea la fila del rol `admin`; dentro se releen actor, objetivo y roles afectados `lockForUpdate` y se evalúan las reglas sobre ese estado.
4. **Revalidar al actor bajo bloqueo (TOCTOU).** El middleware `permission:` se evaluó antes (con caché); bajo el bloqueo se comprueba de nuevo que el actor sigue existiendo y conserva el permiso exigido por la acción, para que un administrador que acaba de degradarlo no sea esquivado por una petición ya en curso.
5. **Denegar con 403 genérico** («No tienes autorización para realizar esta acción.»), sin decir si el objetivo es administrador ni qué permiso falta. El detalle va solo al registro de auditoría. Las reglas existentes de 422 (último administrador, no eliminarse a uno mismo, no retirarse el rol) se conservan y se evalúan **después** de las de autorización.
6. **Fail-closed:** ante un estado imprevisto (actor sin identificador, rol inexistente, guard distinto) se deniega.

### 5. Reglas de autorización propuestas
Notación: `E(x)` = permisos efectivos de `x` leídos de la base; `P(r)` = permisos del rol `r`; `esAdmin(x)` = tiene el rol `admin` (guard `web`). «Actor no administrador» = `!esAdmin(actor)`.

| Regla | Operación | Condición de denegación (403) |
| --- | --- | --- |
| **R1** | `usuarios.update` | `esAdmin(objetivo)` y actor no administrador |
| **R1+** (D-10) | `usuarios.update` / `destroy` | actor no administrador y `objetivo ≠ actor` y `E(objetivo) ⊄ E(actor)` (no se gestionan cuentas con más poder que el propio) |
| **R2a** | `usuarios.store` / `update` | el conjunto de roles **añadidos** contiene `admin` y el actor no es administrador (incluye la autoasignación) |
| **R2b** | `usuarios.store` / `update` | actor no administrador y algún rol añadido `r` con `P(r) ⊄ E(actor)` (techo de permisos) |
| **R2c** | `usuarios.update` | actor no administrador y `objetivo = actor` y cambian sus roles (nadie modifica sus propios roles sin ser administrador) |
| **R2d** | `usuarios.update` | `roles` presente y el actor no tiene `usuarios.asignar-roles` (ya existente; ahora auditada) |
| **R3** | `usuarios.destroy` | `esAdmin(objetivo)` y actor no administrador (además de R1+) |
| **R4a** | `roles.update` / `destroy` | rol `admin` y actor no administrador (impide quitarle permisos y borrarlo; los 422 existentes se mantienen) |
| **R4b** | `roles.update` / `destroy` | actor no administrador y el actor **posee** el rol |
| **R4c** | `roles.store` / `update` | actor no administrador y algún permiso **añadido** `p ∉ E(actor)` (se compara contra los permisos *añadidos*, no contra los ya presentes, para no impedir editar un rol que contiene permisos que el actor no tiene) |
| **R4d** | `roles.update` | renombrar a `admin` o desde `admin` (ya existente) |

*Los cambios de delta se calculan con la lista final solicitada frente al estado releído bajo bloqueo*, no con la mera presencia del campo (el formulario reenvía todas las casillas aunque no haya cambios).

**Invariantes que las reglas garantizan (a probar):** (I1) una operación de un actor no administrador nunca aumenta los permisos efectivos de ninguna cuenta más allá de `E(actor)`; (I2) nunca aumenta los propios; (I3) nunca altera una cuenta `admin` ni el rol `admin`; (I4) la combinación «rol editado + rol asignado» no supera `E(actor)` porque ambas operaciones están acotadas por la misma `E(actor)` (la **delegación** dentro del techo es intencionada: un rol con `roles.editar` y `asignar-roles` puede repartir lo que tiene); (I5) la cantidad mínima de administradores no baja de uno.

**Decisiones del diseño que necesitan confirmación (D-10…D-13):**
- **D-10** Extender R1 a cuentas **no administradoras** con más poder (R1+). *Recomendado: sí.* Sin ella, A-02 sigue abierto para tomas de cuenta laterales.
- **D-11** Un actor no administrador **sí puede** editar su propio nombre, correo y contraseña (no son privilegios; cambiarlos ya es posible con «olvidé mi contraseña»), pero no sus roles (R2c). *Recomendado: sí.*
- **D-12** Delegación dentro del techo permitida (I4) frente a prohibir que un no administrador asigne roles que él no posee (que ya es lo que hace R2b) o conceda permisos. *Recomendado: permitir dentro del techo.*
- **D-13** Corregir en esta fase la invalidación de caché de permisos tras `syncRoles` (diferirla al commit, como ya hace `RoleController`). *Recomendado: sí*, porque sin ella la lectura «efectiva» del middleware puede quedar obsoleta hasta 24 h.

### 6. Diseño de `AdminProtection` (servicio existente, ampliado)
Archivo: `app/Services/AdminProtection.php`. Métodos nuevos (todos reciben `User $actor`; el actor se obtiene con `AuditoriaAcademica::actorDe($request)` o su equivalente en el controlador, nunca con `auth()` implícito):
- `effectivePermissionNames(User|int $usuario): list<string>` — consulta directa; unión roles + directos; guard `web`.
- `rolePermissionNames(Role $rol): list<string>`; `roleNamesOf(User): list<string>` (consulta, sin caché).
- `isAdminFresh(User): bool` — reutiliza `isAdmin()` (ya consulta `roles()`), expuesto con nombre explícito.
- `assertActorStillAuthorized(User $actor, string $permiso): void` — revalida bajo bloqueo; 403 si ya no lo tiene.
- `assertCanModifyUser(User $actor, User $objetivo): void` (R1, R1+).
- `assertCanAssignRoles(User $actor, ?User $objetivo, list<string> $rolesFinales): void` (R2a–R2c; con `$objetivo = null` en `store`).
- `assertCanDeleteUser(User $actor, User $objetivo): void` (R3 + R1+), previo a `assertUserCanBeDeleted` existente.
- `assertCanStoreRole(User $actor, list<string> $permisos): void` (R4c).
- `assertCanUpdateRole(User $actor, Role $rol, string $nombre, list<string> $permisos): void` (R4a–R4c).
- `assertCanDeleteRole(User $actor, Role $rol): void` (R4a, R4b).
- `deny(string $regla, string $motivo, array $contexto): never` — lanza `AccionAdministrativaDenegada`.
Excepción nueva `App\Exceptions\AccionAdministrativaDenegada extends HttpException(403)` con `regla`, `motivo`, `modelType`, `modelId`, `contexto` (lista cerrada, sin datos personales). Registra la denegación en su método `render()` (se ejecuta **después** de que la excepción haya salido de `DB::transaction` y el rollback la haya deshecho) y devuelve `null` para que Laravel dé la respuesta 403 normal; la escritura de la denegación se protege con `try/catch` (si falla se registra en el log sin datos sensibles y el 403 se mantiene). *Alternativa descartada por ahora:* `withExceptions` en `bootstrap/app.php` (archivo de arranque protegido): el `render()` del propio tipo evita tocarlo. Las comprobaciones de **R2d** y la revalidación del actor, que hoy ocurren antes de la transacción, usan el mismo tipo.
Cambios en controladores (sin tocar vistas ni rutas ni permisos): `UserController` (`store`, `update`, `destroy` dentro de `runExclusively`, llamadas a las reglas, `forgetPermissionCacheAfterCommit()` tras `syncRoles`), `RoleController` (`store` en `runExclusively`, reglas en `update`/`destroy`). Los mensajes y redirecciones existentes no cambian.

### 7. Diseño de `AuditoriaAdministrativa`
Archivo nuevo: `app/Support/AuditoriaAdministrativa.php` (`declare(strict_types=1)`, `final`, sin abrir transacciones), independiente de `AuditoriaAcademica`; reutiliza `AuditLog::record` y `SensitiveData` como segunda barrera. Actor siempre explícito.
- `denegacion(?int $actorId, string $regla, string $motivo, string $modelType, ?int $modelId, array $contexto): AuditLog`. Acción: **`acceso_denegado`**. `changes`: `{esquema:1, categoria:'autorizacion', regla:'R1'|…, motivo:<código>, operacion:'usuarios.update'|…, contexto:{…}}`.
- Códigos de `motivo` (lista cerrada): `admin_protegido_edicion`, `admin_protegido_eliminacion`, `rol_admin_reservado`, `rol_fuera_de_alcance`, `autoasignacion_roles`, `cuenta_fuera_de_alcance`, `sin_permiso_asignar_roles`, `actor_sin_permiso_vigente`, `rol_admin_protegido`, `rol_propio_no_editable`, `permisos_fuera_de_alcance`.
- **Contexto permitido** (lista cerrada): `roles_solicitados` y `permisos_excedidos` (nombres de rol/permiso, que no son datos personales), `objetivo_es_admin` (bool), `objetivo_es_actor` (bool). **Prohibido:** nombre, correo, DNI, contraseñas, hashes, tokens, cookies, identificador de sesión, cuerpo de la petición.
- `model_type` = FQCN (`User::class` o `Role::class`), `model_id` = objetivo. La IP la añade `AuditLog::record`.
- **Operaciones exitosas:** los eventos `user_*` y `role_*` existentes se **conservan** (siguen dentro de la transacción y con el actor explícito en los que hoy usan el implícito). Su endurecimiento (marcador de contraseña, «solo nombres») es la fase 21.5; en 21.1 solo se asegura el actor explícito y que ningún valor sensible aparezca.
- **Sin deduplicación en 21.1:** una denegación por petición; el volumen lo limita que solo personal con permiso de la sección llega a estas rutas.

### 8. Riesgos de concurrencia
1. **Serialización global:** `runExclusively` bloquea una sola fila (`roles.admin`); todas las altas/ediciones/bajas de usuarios y roles se serializan. Aceptable por frecuencia; añadir `store` amplía ligeramente la contención.
2. **Actor degradado en paralelo:** resuelto por la revalidación bajo bloqueo (§4.4). Los cambios de permisos propios del actor solo pueden ocurrir dentro del mismo bloqueo, así que la lectura es consistente.
3. **Dos peticiones cruzadas** (A edita el rol X mientras B asigna X): el bloqueo único las ordena; la segunda evalúa el estado ya confirmado.
4. **Caché de Spatie** (§2): tras `syncRoles` y `syncPermissions` la invalidación debe ser *afterCommit*; en `rollback` no debe ejecutarse (comportamiento ya probado para roles).
5. **SQLite no mide nada de esto** (ignora `lockForUpdate`): la concurrencia solo se puede medir en el perfil PostgreSQL aislado (`ConcurrencyPostgresTest` ya cubre dos casos de roles/administradores), que requiere autorización expresa aparte; hasta entonces es **evidencia no medida**.
6. **Orden de bloqueo propuesto:** fila del rol `admin` → actor → objetivo → roles afectados (por `id` ascendente). No hay otra ruta HTTP que bloquee `users`/`roles` en otro orden.

### 9. Posibles incompatibilidades con Spatie Permission
- `getAllPermissions()`, `hasRole()`, `hasPermissionTo()` y las relaciones cargadas usan la caché/objeto en memoria: **no usarlos** para decidir bajo bloqueo (§4.1). Las consultas directas a las tablas de pivote (`config('permission.table_names')`) evitan el problema; hay que leer los nombres de tabla de la configuración por defecto.
- `syncRoles`/`syncPermissions` aceptan nombres y fallan con `RoleDoesNotExist`/`PermissionDoesNotExist` si no existen; ya se validan con `exists:` antes, pero bajo bloqueo la existencia puede cambiar: se convierte en 403/422 controlado, no en 500.
- `forgetCachedPermissions()` se invoca dentro de las operaciones de Spatie y no se puede desactivar: se complementa con la invalidación `afterCommit` ya existente (la dentro-de-transacción se ejecuta igual).
- Roles y permisos tienen `guard_name`; todas las consultas filtran `web`.
- Sin *teams* ni comodines: las consultas no necesitan columna de equipo. Si algún día se activan, las consultas directas deberían revisarse.
- `syncRoles` con un conjunto vacío elimina todos los roles; ya está contemplado por la lógica de «último administrador».
- Los permisos nuevos de futuras migraciones llegan al rol `admin` por migración; el techo de permisos no requiere mantener listas manuales (se calcula sobre lo asignado).

### 10. Migraciones
**No se necesitan.** Todo cabe en `audit_logs` (`action` string, `changes` JSON, `user_id` nullable); no hay permisos nuevos ni cambios en la matriz de los tres roles; las reglas son lógica de servicio. Los índices de `audit_logs` quedan para el Sprint 23 (D-4). No se ejecutará ninguna migración.

### 11. Archivos que habría que modificar o crear (sin tocar vistas, rutas, permisos, matrículas, procesos ni aprobaciones)
- Modificar: `app/Services/AdminProtection.php`, `app/Http/Controllers/Admin/UserController.php`, `app/Http/Controllers/Admin/RoleController.php`.
- Crear: `app/Support/AuditoriaAdministrativa.php`, `app/Exceptions/AccionAdministrativaDenegada.php`.
- Pruebas nuevas: `tests/Unit/AuditoriaAdministrativaTest.php`, `tests/Feature/ProteccionAdministradoresTest.php`, `tests/Feature/EscaladaPrivilegiosRolesTest.php`. Pruebas existentes que podrían necesitar ajustes mínimos: `AdminProtectionTest`, `AdminAuthorizationTest`, `Sprint12AtomicityTest` (solo si cambian sus supuestos; **no** se debilitará ninguna).
- No se modifican: `bootstrap/app.php`, `routes/web.php`, vistas, `RolesAndPermissionsSeeder`, migraciones, chatbots, matrículas, procesos ni aprobaciones.

### 12. Matriz de pruebas (SQLite en memoria, aisladas de `horus_group`)
| # | Escenario | Resultado esperado | Tipo |
| --- | --- | --- | --- |
| 1 | `admin` edita a otro `admin` (nombre, correo, contraseña) | 302 correcto; auditoría `user_updated` | funcional |
| 2 | Rol personalizado con `usuarios.editar` edita a un `admin` | 403; sin cambios; `acceso_denegado` R1 | negativa |
| 3 | Usuario con `usuarios.editar` cambia su **propio** correo/contraseña | permitido (D-11); sesiones revocadas; no puede cambiar sus roles (403 R2c) | funcional/negativa |
| 4 | Se autoasigna `admin` (con `asignar-roles`) | 403 R2a; sin rol | negativa |
| 5 | Asigna `admin` a otra cuenta (update y store) | 403; cuenta intacta / no creada | negativa |
| 6 | Rol personalizado elimina a un `admin` con otros admins | 403; el admin sigue | negativa |
| 7 | Último administrador: eliminar/retirar rol | 422 existente, sin cambio | regresión |
| 8 | `roles.editar`: se añade a su propio rol `usuarios.asignar-roles` | 403 R4b; permisos sin cambio | negativa |
| 9 | Añade a otro rol permisos que no posee | 403 R4c; con permisos que sí posee: permitido | negativa/funcional |
| 10 | Edita un rol que tiene asignado (renombrar/permisos) | 403 R4b | negativa |
| 11 | Combinaciones: edita rol Y (permitido) y luego se asigna Y; Y con permiso fuera de su techo | primera sí; segunda 403 R2b | negativa |
| 12 | Quita permisos no críticos al rol `admin` siendo no administrador | 403 R4a; `admin` administrador: se mantiene el comportamiento actual | negativa/regresión |
| 13 | Cuenta lateral: rol de bajo privilegio edita a un `coordinador` | 403 R1+ | negativa |
| 14 | Actor degradado entre middleware y bloqueo (simulado quitando el permiso antes de `runExclusively`) | 403 `actor_sin_permiso_vigente` | negativa |
| 15 | Fallo de auditoría (`AuditLog::creating` lanza) en `user_*`/`role_*` exitosos | rollback completo (usuario, roles, permisos, caché no invalidada) | atomicidad |
| 16 | Denegación **persiste** aunque la transacción se revierta; si falla su escritura, sigue el 403 | fila `acceso_denegado` presente / 403 intacto | atomicidad |
| 17 | Mensaje 403 genérico: no incluye correo, nombre, rol ni permiso del objetivo | cuerpo sin esos textos | privacidad |
| 18 | `changes` de las denegaciones y de `user_*`/`role_*` sin contraseña, hash, token, cookie, sesión, DNI, correo ni nombre | recorrido recursivo del JSON | privacidad |
| 19 | Roles base: `admin` conserva todo; `coordinador`/`secretaria` 403 por permiso (sin llegar a las reglas) y su matriz de permisos no cambia | idéntica al seeder | regresión |
| 20 | Caché de permisos: tras `syncRoles` se invalida solo tras el commit; en rollback no se invalida y el error no se oculta | como en roles | regresión |
| 21 | Estado del actor en cambio de roles simultáneo (PostgreSQL aislado) | **no medido en SQLite**; requiere autorización | concurrencia (pendiente) |
Regresión obligatoria: `AdminProtectionTest`, `AdminAuthorizationTest`, `AdminAuthenticationTest`, `Sprint12AtomicityTest`, `AuthHardeningTest`, `SecretsAndExportsTest` y la suite completa con Larastan nivel 6, Pint solo sobre los archivos propios y `git diff --check`.

### 13. Plan de implementación por cambios pequeños (cada paso verde antes del siguiente)
1. `AuditoriaAdministrativa` + `AccionAdministrativaDenegada` + pruebas unitarias (sin tocar controladores).
2. Consultas de permisos efectivos y revalidación del actor en `AdminProtection` + pruebas unitarias de las funciones puras contra la base en memoria.
3. R3 y R1 (`destroy`/`update` de usuarios) + pruebas 1, 2, 6, 7.
4. R2 (`store`/`update` de usuarios, techo de permisos, autoasignación) + pruebas 3, 4, 5, 11.
5. R1+ (alcance lateral) + prueba 13 (si se aprueba D-10).
6. R4 (`RoleController`: `store`, `update`, `destroy`) + pruebas 8, 9, 10, 12.
7. Mover `store` de usuarios y roles a `runExclusively`, invalidación `afterCommit` tras `syncRoles` (D-13) + pruebas 14, 20.
8. Atomicidad y privacidad (15–18, 19) y regresión completa; actualización de `PROJECT_CONTEXT.md` (archivos, SHA-256, resultados medidos, estado Git).

### 14. Estrategia de regresión
Ejecutar tras cada paso la prueba acotada (`--filter=` de la clase tocada), y al terminar la suite completa (`php artisan test --compact`), `npm test`, `vendor/bin/phpstan analyse`, Pint sobre los archivos propios y `git diff --check`. Línea base a comparar: la última documentada (1.211 aprobadas, 1 omitida, 7.759 aserciones en SQLite en memoria; **no re-ejecutada** en esta fase de análisis) más las pruebas nuevas. Ninguna prueba existente se modificará para que pase: si una cambia de supuesto, se documenta el motivo. La evidencia siempre se declarará como «SQLite en memoria»; no demuestra el comportamiento de bloqueos en PostgreSQL.

### 15. Estado y siguiente paso
Solo se modificó este archivo. Esperando autorización expresa para implementar 21.1, junto con las decisiones **D-10, D-11, D-12 y D-13** (§5).


---

## SPRINT 21.1 — Protección de administradores y prevención de escalada de privilegios

> Fecha: 31/10/2026 · Rama `anderson` · HEAD `038895280000f57d39c698e40d3436fecc0d716f` (sin commits nuevos) · Fase **implementada** tras la autorización expresa del propietario. Anclaje: `sprint-21-1-implementacion`. La sección «SPRINT 21.1 — decisiones y diseño» anterior sigue siendo el análisis; esta registra lo construido y las diferencias.

### 1. Decisiones (D-1 a D-13)

| ID | Decisión | Estado |
| --- | --- | --- |
| D-1 | Diseño de auditoría y política de privacidad: datos personales y texto libre solo por nombre/código | Aplicada (lista cerrada, sin correos/nombres/DNI/claves/tokens/cookies/sesión) |
| D-2 | Proteger administradores en edición, asignación y eliminación | Aplicada (R1, R2, R3) |
| D-3 | Operaciones administrativas atómicas con su auditoría; un fallo de auditoría no bloquea un acceso legítimo | Aplicada a las operaciones de usuarios/roles. El caso «login» corresponde a 21.6 |
| D-4 | Índices de auditoría diferidos a Sprint 23 | Sin cambios |
| D-5 | Notificaciones internas diferidas | Sin cambios |
| D-6 | Prevenir la escalada por `roles.editar` | Aplicada (R4) |
| D-7 | Accesos JWT/API en una fase posterior | Pendiente (21.6) |
| D-8 | Trabajo por fases con autorización propia | Respetada: no se tocó 21.2+ |
| D-9 | Clase `AuditoriaAdministrativa` independiente de `AuditoriaAcademica` | Aplicada |
| D-10 | Proteger también cuentas no administradoras con más privilegios que el actor | Aplicada (R1+, también en eliminación) |
| D-11 | Cada usuario puede editar su nombre, correo y contraseña, nunca sus roles | Aplicada (R2c) |
| D-12 | Delegación dentro del techo de permisos efectivos del actor | Aplicada (R2b, R4c) |
| D-13 | Invalidar la caché de Spatie tras el commit | Aplicada con una precisión (ver §6) |

### 2. Arquitectura implementada

- **`App\Services\AdminProtection`** (se queda en `app/Services/`). Se conservan `runExclusively()`, las reglas 422 y `CRITICAL_PERMISSIONS`. Nuevo:
  - `runAdministrative(Closure)`: `runExclusively()` + captura `AccionAdministrativaDenegada`, la registra **después del rollback** y la relanza (403).
  - Autoridad efectiva **desde la base de datos**, sin `can()`, `hasRole()` ni `getAllPermissions()`: `effectivePermissionNames(User)`, `roleNamesOf(User)`, `rolePermissionNames(list<string>)` (consultas a las tablas pivote con los nombres de tabla de `config('permission')`).
  - `actorVigente(actor, requeridos, operacion, modelType, modelId)`: relee al actor **dentro de la transacción y con `lockForUpdate`**, recalcula sus permisos y exige los requeridos; devuelve `{actor, permisos, admin}`. Si el actor fue borrado o degradado → denegación `actor_sin_permiso_vigente`.
  - Reglas: `assertCanModifyUser`, `assertCanDeleteUser`, `assertMayRequestRoles`, `assertCanAssignRoles`, `assertCanCreateRole`, `assertCanUpdateRole`, `assertCanDeleteRoleAuthorized`.
- **`App\Exceptions\AccionAdministrativaDenegada`** (nueva, `final`, extiende `HttpException` 403). Mensaje genérico «No tienes permiso para realizar esta acción.». Método `registrar()` idempotente que escribe la fila de auditoría y **traga cualquier fallo de la auditoría** (deja un `Log::warning` con regla/motivo/clase de error, sin datos del usuario): la respuesta sigue siendo 403.
  - Diferencia con el diseño: el registro **no** está en `render()`, sino en `runAdministrative()`. Así funciona también con `withoutExceptionHandling()` y no depende del pipeline de excepciones. `bootstrap/app.php` sigue intacto.
- **`App\Support\AuditoriaAdministrativa`** (nueva, `final`, `declare(strict_types=1)`): `denegacion(actorId, regla, motivo, operacion, modelType, modelId, contexto)`. Listas cerradas de reglas y motivos (código desconocido → `InvalidArgumentException`); el contexto admite solo `roles_solicitados`, `permisos_excedidos`, `objetivo_es_admin`, `objetivo_es_actor` (listas ≤ 50 elementos, cadenas ≤ 100 caracteres sin caracteres de control).
- **Controladores:** `UserController::{store,update,destroy}` y `RoleController::{store,update,destroy}` pasan por `runAdministrative()`. `store` de ambos ya no queda fuera de la sección exclusiva. Orden bajo bloqueo: fila del rol `admin` → actor (releído y bloqueado) → objetivo (releído y bloqueado) → reglas de autorización (403) → reglas de integridad (422) → cambio → auditoría como última escritura. Las reglas 422 existentes (último administrador, no quitarse el propio `admin`, no eliminarse a uno mismo, `admin` no renombrable/eliminable, permisos críticos) se mantienen y se evalúan **después** de las de autorización.

### 3. Reglas de seguridad (E(x) = permisos efectivos leídos de la BD; «admin» = tiene el rol `admin` en la BD)

| Regla | Efecto (actor no administrador salvo indicación) | Motivo auditado |
| --- | --- | --- |
| ACTOR | El actor debe conservar el permiso del endpoint al tomar el bloqueo | `actor_sin_permiso_vigente` |
| R1 | No modifica una cuenta con rol `admin` | `admin_protegido_edicion` |
| R1+ (D-10) | No modifica/elimina una cuenta cuyo E no está contenido en el suyo | `cuenta_fuera_de_alcance` |
| R2a | No concede el rol `admin` | `rol_admin_reservado` |
| R2b | Solo añade roles cuyos permisos ⊆ E(actor) (techo de delegación) | `rol_fuera_de_alcance` |
| R2c (D-11) | No cambia sus propios roles | `autoasignacion_roles` |
| R2d | Enviar `roles` sin `usuarios.asignar-roles` vigente se deniega (antes era un `abort` sin rastro) | `sin_permiso_asignar_roles` |
| R3 | No elimina un administrador | `admin_protegido_eliminacion` |
| R4a | No edita ni elimina el rol `admin` (incluye quitarle permisos no críticos como `auditoria.*`) | `rol_admin_protegido` |
| R4b | No edita ni elimina un rol que él mismo tiene | `rol_propio_no_editable` |
| R4c | Los permisos que **añade** a un rol (delta) o concede en un rol nuevo ⊆ E(actor) | `permisos_fuera_de_alcance` |
| R4e (ampliación) | No edita ni elimina un rol con permisos fuera de E(actor), ni siquiera para quitárselos | `rol_fuera_de_alcance` |

- Combinaciones de roles: como el objetivo ya está dentro del alcance (R1+) y cada rol añadido ⊆ E(actor), la unión resultante no puede superar al actor; un rol inocuo no «completa» una cuenta que ya excede al actor (R1+ lo bloquea).
- Autoedición de perfil (D-11): un no administrador que edita su propia cuenta **sin** enviar `roles` conserva sus roles (la ausencia del campo no equivale a «quitar todos» en ese caso); si envía roles distintos → 403 R2c.
- Un administrador conserva todas sus capacidades; solo se le aplican las reglas 422 de integridad.
- **R4e es una ampliación mínima** del diseño aprobado, dentro de D-12 («controles contra escaladas indirectas»): impide que un rol menor altere roles de mayor poder. No afecta a los tres roles base.
- Los 403 son genéricos: no revelan si el objetivo es administrador ni qué faltaba. El motivo vive solo en la auditoría.

### 4. Eventos de auditoría

- **Nuevo:** `acceso_denegado` — `user_id` = actor explícito; `model_type` `App\Models\User` o `Spatie\Permission\Models\Role`; `model_id` del objetivo (nulo al crear); `changes = {esquema:1, categoria:'autorizacion', regla, motivo, operacion, contexto}`. Operaciones: `usuario.crear|actualizar|eliminar`, `rol.crear|actualizar|eliminar`. Persiste tras el rollback.
- **Existentes** (`user_created|updated|deleted`, `role_created|updated|deleted`): mismo contenido que antes; **solo se añade el actor explícito** (`user_id`). El endurecimiento de su contenido (p. ej. correos/nombres, cambio de contraseña) queda para 21.5.
- Las denegaciones del middleware `permission:` / `role:` (antes de entrar al controlador) **no** se auditan en esta fase.

### 5. Archivos

**Modificados:** `app/Services/AdminProtection.php`, `app/Http/Controllers/Admin/UserController.php`, `app/Http/Controllers/Admin/RoleController.php`, `tests/Feature/AdminProtectionTest.php`, `PROJECT_CONTEXT.md` (solo anexo).
**Creados:** `app/Support/AuditoriaAdministrativa.php`, `app/Exceptions/AccionAdministrativaDenegada.php` (directorio `app/Exceptions/` nuevo), `tests/Unit/AuditoriaAdministrativaTest.php`, `tests/Feature/ProteccionAdministradoresTest.php`, `tests/Feature/EscaladaPrivilegiosRolesTest.php`.
**No tocados:** migraciones (ninguna creada), vistas/CSS/JS/`public`, `routes/*`, `bootstrap/app.php`, seeder, matrículas, procesos, aprobaciones, chatbots, docentes/sesiones/certificados.

**Cambio en pruebas existentes (justificado).** Cuatro pruebas de `AdminProtectionTest` usaban un `coordinador` con `usuarios.*` para gestionar a administradores, que es exactamente el hueco que cierra este sprint. Se conservó su intención: (a) `the last admin cannot be deleted` y `the last admin cannot lose the admin role` ahora verifican el 403 del no administrador y mantienen la regla 422 llamando a `assertUserCanBeDeleted` / `assertRoleChangeKeepsAdministration` (segunda barrera); (b) `an admin can be deleted when another one remains` y `crossed demotions never leave the system without admins` pasaron a usar actores administradores. No se relajó ningún control. Las 20 restantes de ese archivo y las de `AdminAuthorizationTest` no cambiaron.

### 6. Caché de Spatie (D-13) — precisión

Al revisar `vendor/spatie/laravel-permission` se comprobó que **`assignRole`/`syncRoles` sobre un `User` no vacían la caché** (solo lo hacen si el modelo es un `Permission`); la caché guarda permisos y sus roles, no las asignaciones de usuario. Esto corrige la afirmación del análisis previo. Se aplicó igualmente `forgetPermissionCacheAfterCommit()` tras `syncRoles` en `UserController` (store/update) y se mantuvo en `RoleController`: es inocuo, deja el contrato coherente con los roles y se descarta con el rollback. Las decisiones de autorización no dependen de esa caché (consultas directas a la BD).

### 7. Pruebas ejecutadas (SQLite `:memory:`; nada contra `horus_group`)

| Comprobación | Resultado |
| --- | --- |
| Nuevas: `AuditoriaAdministrativaTest` (7) + `ProteccionAdministradoresTest` (22) + `EscaladaPrivilegiosRolesTest` (13) | **42 passed, 225 aserciones** |
| Regresión dirigida (`AdminProtectionTest`, `AdminAuthorizationTest`, `Sprint12AtomicityTest`, `AuthHardeningTest`) | **129 passed, 476 aserciones** |
| Suite completa `php artisan test --compact` | **1.448 passed, 1 skipped, 9.236 aserciones, 0 fallos** (164 s) |
| `npm test` | 36 pass, 0 fail (no se tocó frontend) |
| Larastan nivel 6 `vendor/bin/phpstan analyse` | **No errors** |
| Pint (solo archivos propios) | Contenido sin diferencias de estilo. Tres archivos (`AdminProtection.php`, `UserController.php`, `AdminProtectionTest.php`) solo reportan `line_ending` porque la copia de trabajo está en CRLF (preexistente; HEAD en LF); normalizados a LF en una copia temporal pasan sin cambios |
| `git diff --check` | Limpio (solo avisos LF/CRLF conocidos) |
| Prueba de mutación manual | Con R1/R1+ y R4a desactivados temporalmente, fallaron 10 pruebas nuevas; archivo restaurado (hash verificado) |

Cobertura de los 21 puntos exigidos: 1 admin→admin; 2 no-admin→admin; 3 cuentas con más privilegios; 4 perfil propio; 5 roles propios; 6 asignar `admin` (update, store, a uno mismo); 7 roles superiores; 8 último administrador; 9 eliminación de administradores; 10 escalada vía `roles.editar`; 11 permisos no poseídos; 12 rol propio; 13 eliminar roles asignados; 14 combinaciones; 15 actor degradado dentro de la sección exclusiva (usuarios y roles; simulado con el evento `retrieved` de la fila `admin` bajo transacción); 16 persistencia de la denegación tras rollback; 17 atomicidad con auditoría fallida (user/role create/update/delete) y denegación que sigue siendo 403 aunque falle la auditoría; 18 caché tras el commit; 19 rollback y denegación sin invalidación; 20 permisos de los tres roles base intactos y flujo legítimo de admin; 21 privacidad (filas sin correo, nombre, clave, hash, token, cookie ni DNI; claves de `changes` cerradas). Extras: rol inexistente (fail-closed), actor borrado, `usuarios.editar` sin `asignar-roles`, `admin` sigue sin poder eliminar el rol `admin`, 422 posteriores a 403.

**Límite de la evidencia:** SQLite ignora `lockForUpdate`. Se demostró el orden de operaciones, las reglas y la atomicidad, **no** la serialización real ni la carrera «actor degradado en paralelo» entre conexiones de PostgreSQL (la prueba 15 simula la degradación dentro de la sección exclusiva). Requiere el perfil aislado (127.0.0.1:55433) con autorización explícita.

### 8. Hashes SHA-256

| Archivo | SHA-256 |
| --- | --- |
| `app/Services/AdminProtection.php` | `e0084ddc6f1f2623a07e5c4edddb539001724ba126f4e9c624552ac877f34523` |
| `app/Support/AuditoriaAdministrativa.php` | `f91264187fe753d9fdfcb11e3ee095b6936ae9cdfa5aa8cde5950ab4360eec21` |
| `app/Exceptions/AccionAdministrativaDenegada.php` | `6047901d398d49086f6940dc46445a987bd64f80355a513184e41040734d648e` |
| `app/Http/Controllers/Admin/UserController.php` | `00b220f9c9413d65f6ce5bc806419cc4918219dcad835ca58857e8795574240a` |
| `app/Http/Controllers/Admin/RoleController.php` | `e10370d145ef59f362b2176e64eddc8eea7ae962aa872bd2e6f0a0aa9f0020bb` |
| `tests/Unit/AuditoriaAdministrativaTest.php` | `d0a0cbd4e5d52299881a0e28bda010095abde56b4c5140c756ddb15e0114e353` |
| `tests/Feature/ProteccionAdministradoresTest.php` | `b1c393a9a3afb35af944192f8660d70d397e9d87c32c44f46d42a57bd9318d9a` |
| `tests/Feature/EscaladaPrivilegiosRolesTest.php` | `8e99af17fac9118cee2777970fd60361d0e3e6531d23b4dd0f78a1e55966d807` |
| `tests/Feature/AdminProtectionTest.php` | `3c2a126be71e823629262863db09a9b055917ff7270303a49ff930ced13f354a` |

Hashes previos (antes de la fase): `AdminProtection.php` `89c82bef…300c`, `UserController.php` `8217031a…f51e`, `RoleController.php` `46c3df61…881cece`.

### 9. Estado Git

Rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, **2 stashes intactos** (`stash@{0}` «before removing oversized Laravel asset copies», `stash@{1}` «before removing oversized image blobs»), índice vacío (0 archivos en stage), sin commits, sin push. `git status --short`: 106 → 115 entradas; la diferencia son exactamente los 9 elementos de esta fase (3 controlador/servicio modificados, 1 prueba modificada, `app/Exceptions/` y 4 archivos nuevos); `PROJECT_CONTEXT.md` ya figuraba modificado. Ninguna de las 106 entradas previas cambió. No se ejecutaron migraciones, seeders ni comandos contra `horus_group`.

### 10. Riesgos residuales

1. **Concurrencia no medida** en PostgreSQL (§7). Pendiente de autorización independiente.
2. **Interfaz sin adaptar** (fuera de alcance: vistas protegidas). Un no administrador con `usuarios.*` seguirá viendo botones de editar/eliminar sobre administradores y recibirá un 403. Ocultarlos es un cambio de vistas pendiente de aprobación.
3. **Denegaciones del middleware** (`permission:`/`role:`) no se auditan; solo las del controlador.
4. **`user_*`/`role_*` conservan nombre y correo** y no registran el cambio de contraseña (21.5, D-1).
5. **Vías ajenas no cubiertas:** `VerificacionRegistroService::confirmar` (hoy desactivado) puede fijar la contraseña de una cuenta existente, incluida una con rol; `horus:promover-admin` / `horus:crear-usuario` (CLI de confianza). Registrado, no modificado.
6. **Un administrador es de plena confianza:** puede degradar a otros administradores (con las protecciones del último administrador) y quitar permisos no críticos al rol `admin`. Es la política aprobada.
7. La auditoría de denegación se escribe tras el rollback fuera de la transacción: un fallo del proceso entre ambos pasos la perdería (aceptado; la respuesta sigue siendo 403).
8. Eliminar un rol asignado a otros usuarios (por un administrador) los deja sin ese rol, como antes.
9. Sprint 19–20 sin commit (R-10) y las 106 entradas previas de Git siguen sin resolver.

### 11. Pendientes para Sprint 21.2

- Requiere autorización propia: auditoría de **matrículas** (crear, actualizar, anular, restaurar, eliminar) con `AuditoriaAcademica` (lista MATRICULA ya preparada), y retirar el texto libre de los filtros del CSV (M-07).
- Después, por fases y con autorización: 21.3 procesos, 21.4 aprobaciones, 21.5 endurecimiento de `user_*`/`role_*`, 21.6 accesos (incl. JWT), 21.8 cierre. Sprint 23: índices de `audit_logs`, snapshot del actor (A-01) y retención.
- Decisión del propietario pendiente: ocultar en la interfaz las acciones que el servidor ya deniega (vistas).
- Recomendado: ejecutar la prueba de concurrencia en el perfil PostgreSQL aislado cuando se autorice.


---

## PLAN DE CONSOLIDACIÓN GIT — SPRINTS 19 A 21.1

> Fecha: 31/10/2026 · Auditoría **de solo lectura**. No se ejecutó `git add/commit/push/reset/restore/clean/stash/checkout`, no se cambió de rama, no se tocó el índice ni se modificó código. Único archivo escrito: este anexo. Anclaje: `plan-consolidacion-git`. Pendiente de autorización del propietario para la consolidación real.

### 1. Estado actual del repositorio

| Elemento | Valor |
| --- | --- |
| Rama / HEAD | `anderson` / `038895280000f57d39c698e40d3436fecc0d716f` (`feat(web): conectar catalogo publico de servicios desactivado`) |
| Índice | Vacío (0 archivos en stage) |
| Stashes | 2 intactos: `stash@{0}` «On main: before removing oversized Laravel asset copies», `stash@{1}` «On main: before removing oversized image blobs» |
| Entradas de `git status --short` | 115 (agrupadas); **120 archivos** con `-uall` |
| Modificados (tracked) | **34** (+2.674 / −160 líneas; 1.454 de ellas en `PROJECT_CONTEXT.md`) |
| Sin seguimiento | **86** archivos (en las 115 entradas aparecen varios como carpetas, p. ej. `app/Exceptions/`) |
| Eliminados / renombrados / en conflicto | 0 |
| Migraciones | 5 nuevas sin seguimiento (`000027`–`000031`); la `000022` sigue pendiente a propósito |
| `app/Models/AuditLog.php` | **No está modificado** (a diferencia de lo supuesto en el encargo) |
| Avisos | `core.autocrlf=true`: la copia de trabajo mezcla CRLF/LF y Git avisa «LF will be replaced by CRLF»; no son fallos |

### 2. Hallazgo principal: el trabajo pendiente NO es solo Sprint 19–21.1

Además de los seis grupos pedidos hay un séptimo bloque grande y anterior: **infraestructura y validación de PostgreSQL Testing** (PG-TEST-02a … 05B-2A, 29–30/10/2026): 42 archivos (21 modificados, 21 nuevos). Sin él, las pruebas del Libro de Reclamaciones en PostgreSQL (`tests/PostgresIntegrity/Reclamaciones*`) y varias pruebas de regresión ajustadas no se pueden construir. Además hay material documental sin sprint propio (auditorías globales, Ronda 4, aplicación de migraciones en `horus_group`, Sprint 21 Fase 0) que vive solo dentro de `PROJECT_CONTEXT.md`.

### 3. Clasificación (conteo exacto; manifiesto con hashes en §11)

| Grupo | Archivos | M | ?? | Contenido |
| --- | --- | --- | --- | --- |
| **PG** PostgreSQL Testing | 42 | 21 | 21 | `app/Support/Testing/*` (3 M + 2 nuevos), `scripts/pgsql-test*.php`, `scripts/testing/NativeCapture.*`, `tests/Unit/Postgres*`, `tests/Fixtures/**`, `tests/Windows/**`, `tests/bootstrap-pgsql.php`, 5 docs `postgresql-testing-*` + `postgresql-l2.3-testing.md`, `CLAUDE.md`, `.env.pgsql-testing.local.example` y ajustes de regresión (`AdminAuthorizationTest`, `AdminReportConsistencyTest`, `BackendRobustnessTest`, `MatriculaIntegrityConstraintsTest`, `MatriculaNotificationTest`, `ReportExcelExportTest`, `ReportExcelScalabilityTest`, `ServicioTecnologicoTest`) |
| **S19** Cotizaciones (backend privado) | 8 | 0 | 8 | 2 modelos, `GestionCotizaciones`, migraciones `000027`/`000028`, `CotizacionTest`, 2 docs (`sprint-19-cotizaciones`, `restriccion-acceso-comercial-admin`) |
| **S19.1** Panel de cotizaciones | 13 | 2 | 11 | `CotizacionController`, 2 requests, vistas `admin/cotizaciones/*`, `admin-cotizaciones.mjs`, `sidebar.blade.php` (M), `vite.config.js` (M), `PanelCotizacionesTest`, humo de navegador, prueba frontend, router de pruebas, doc |
| **S19.2** Recepción pública (**suspendido**) | 7 | 0 | 7 | `SolicitudCotizacionPublicaController`, middleware `RecepcionCotizacionesHabilitada`, `config/recepcion-cotizaciones.php` (`enabled=false`), migración `000029` (columna `origen`), 2 pruebas, doc |
| **S20-A** Auditoría académica | 6 | 2 | 4 | `AuditoriaAcademica`, `CursoController` (M), `EstudianteController` (M), 3 pruebas |
| **S20-R** Libro de Reclamaciones | 31 | 1 | 30 | modelos, servicios, `Support/*Reclamaciones*`, `DiasHabiles`, `HojaReclamacion`, middleware, requests, job, mail, `config/reclamaciones.php` (`enabled=false`), migraciones `000030`/`000031`, pruebas (Feature, Unit, PostgresIntegrity), `AppServiceProvider` (M, limitador), doc de Fase 2.1 |
| **S21.1** Protección de administradores | 9 | 4 | 5 | `AdminProtection`, `UserController`, `RoleController`, `AdminProtectionTest` (M) + `AuditoriaAdministrativa`, `AccionAdministrativaDenegada`, 3 pruebas nuevas |
| **Compartidos** | 3 | 3 | 0 | `routes/web.php`, `RolesAndPermissionsSeeder.php`, `CatalogoWebServiciosTest.php` (ver §5) |
| **Contexto** | 1 | 1 | 0 | `PROJECT_CONTEXT.md` |
| **Total** | **120** | 34 | 86 | |

Verificado archivo a archivo: `app/Exceptions/` solo contiene la excepción de 21.1; `tests/Fixtures/` y `tests/Windows/` son PG; `resources/views/admin/cotizaciones/` es 19.1; `config/recepcion-cotizaciones.php` es de 19.2 (lo declara su cabecera). Ninguna carpeta se asignó entera sin comprobar su contenido.

### 4. Propuesta de commits (orden obligatorio por dependencias)

| # | Commit propuesto | Sprint | Archivos | Descripción | Riesgo |
| --- | --- | --- | --- | --- | --- |
| C1 | `test(postgresql): perfil aislado 55433, guardas y preflight` | PG-TEST | ~27: `app/Support/Testing/*`, `scripts/*`, `tests/Unit/Postgres*`, `tests/Fixtures`, `tests/Windows`, `tests/bootstrap-pgsql.php`, docs, `CLAUDE.md`, `.env…example` | Guardas del perfil aislado, preflight, arnés de captura | **Medio**: toca el arranque de pruebas; no debilitar salvaguardas |
| C2 | `test(postgresql): adaptar pruebas de regresión al perfil aislado` | PG-TEST | ~15: `tests/Feature/*` modificadas (salvo el hunk de cotizaciones), `RequiresIsolatedPostgres`, `ConcurrentTestSession`, `TestDatabaseSafety` | `DatabaseMigrations`, savepoints, UUID de lease | Medio: pasan en SQLite; en PostgreSQL solo con perfil autorizado |
| C3 | `feat(cotizaciones): solicitudes privadas de cotización` | 19 | 8 + hunks (seeder: constante y spread de cotizaciones; `CatalogoWebServiciosTest`: 2 `Schema::drop`) | Modelos, servicio, migraciones, permisos solo admin | Medio: hunks compartidos |
| C4 | `feat(panel): panel privado de solicitudes de cotización` | 19.1 | 13 + hunks de `routes/web.php` (1.º y 4.º) | Controlador, vistas, JS, menú, Vite | Medio: toca frontend ya existente y `routes/web.php` |
| C5 | `feat(cotizaciones): preparar recepción pública (desactivada)` | 19.2 (suspendido) | 7 + hunks de `routes/web.php` (2.º y 3.º) | Flag `enabled=false`, columna `origen` | Medio: sprint suspendido; confirmar si se commitea |
| C6 | `feat(auditoria): auditoría académica de cursos y estudiantes` | 20-A | 6 | Auditoría transaccional fail-closed | **Bajo**: archivos exclusivos |
| C7 | `feat(reclamaciones): Libro de Reclamaciones (desactivado)` | 20-R | 31 + hunk 2 del seeder + `AppServiceProvider` | Backend completo con `enabled=false`; migraciones `000030`/`000031` | **Alto**: el mayor; depende de C1/C2 y del seeder |
| C8 | `feat(seguridad): protección de administradores y escalada` | 21.1 | 9 | R1–R4 y evento `acceso_denegado` | **Bajo**: archivos exclusivos; `AdminProtectionTest` solo contiene cambios de 21.1 |
| C9 | `docs(contexto): registrar sprints 19–21.1 y auditorías` | docs | `PROJECT_CONTEXT.md` | Anexo cronológico completo | Medio (§6) |

Alternativa con menos commits si el propietario lo prefiere: fusionar C1+C2 y C3+C4.

### 5. Archivos compartidos y cómo consolidarlos

| Archivo | Contenido por sprint | Separable | Método seguro propuesto |
| --- | --- | --- | --- |
| `routes/web.php` | 4 hunks independientes: línea 9 (`use CotizacionController`, 19.1) · líneas 28-29 (`use` público + middleware, 19.2) · bloque `Route::middleware(RecepcionCotizacionesHabilitada)` (19.2) · 10 líneas de rutas `cotizaciones.*` (19.1) | **Sí**, por hunks | `git add -p` eligiendo hunks; no hay líneas contiguas entre sprints |
| `database/seeders/RolesAndPermissionsSeeder.php` | Hunk 1: constantes `COTIZACIONES_PERMISSIONS` (19) y `RECLAMACIONES_PERMISSIONS` (20-R) **contiguas**; hunk 2: dos `...self::` contiguos | **Solo con edición manual de hunks** (`git add -p` + `e`, o parche construido a mano con `git apply --cached`) | Las migraciones `000028` y `000031` leen cada constante: C3 debe llevar solo la suya |
| `tests/Feature/CatalogoWebServiciosTest.php` | PG (quitar la aserción `sqlite`) + 19 (`Schema::drop` de `cotizacion_historial`/`solicitudes_cotizacion`) | Sí, 2 hunks | El hunk de cotizaciones va en C3 o después: fallaría sin la migración `000027` |
| `PROJECT_CONTEXT.md` | Ediciones de cabecera y de secciones antiguas (PG, 18.x, 19–19.2) + ~1.330 líneas anexadas (PG-TEST-05B-2A.2, S20-A fases 2a/2b, auditorías, S20-R fases 1–2.1, migraciones en `horus_group`, Ronda 4, S21 F0, S21.1 diseño e implementación) | **No de forma segura por hunks** | Un único commit documental (C9) al final; ver §6 |
| `CLAUDE.md` | 2 hunks, ambos PG (puerto 55433 e índice de docs) | Un solo grupo | C1 |
| `app/Providers/AppServiceProvider.php` | Un hunk, solo S20-R (limitador `reclamaciones`) | Un solo grupo | C7 |
| `AuditoriaAcademica.php` / `AdminProtection.php` / `AuditLog.php` | `AuditoriaAcademica.php` es **nuevo** (20-A); `AdminProtection.php` es 100 % de 21.1 (+383 líneas, sin cambios previos); `AuditLog.php` **no cambió** | Sí, ya aislados | Sin hunks mezclados |
| `UserController.php` / `RoleController.php` / `AdminProtectionTest.php` | Solo cambios de 21.1 respecto a HEAD | Sí | C8 |
| `CursoController.php` / `EstudianteController.php` | Solo 20-A (10 y 8 hunks) | Sí | C6 |

Migraciones: todas nuevas, cada una en su sprint (`000027`/`000028` → C3; `000029` → C5; `000030`/`000031` → C7). Ninguna modifica algo ya commiteado.

### 6. `PROJECT_CONTEXT.md`: opciones

El archivo es un registro cronológico que mezcla sprints; los anexos recientes dependen de los anteriores (p. ej. 21.1 cita Sprint 21 F0 y la Ronda 4). Opciones:
1. **(Recomendada)** Un commit documental único al final (C9). Cero riesgo de perder contenido; el historial de código queda limpio por sprint.
2. Commits documentales escalonados: construir versiones truncadas en los límites de sección (líneas ≈ 522, 598, 705, 1173, 1280, 1347, 1426, 1564, 1722 de la copia previa a este anexo) y aplicarlas con `git update-index --cacheinfo`/`git apply --cached`. Las ediciones de cabecera y de secciones antiguas (hunks en las líneas 3-39, 133-232, 238-301, 409-500) habría que repartirlas a mano. Más fiel al sprint, mucho más trabajo y riesgo.
3. No tocar el archivo hasta decidir; no bloquea el resto.

### 7. Dependencias entre funcionalidades

- C1 → C2 → lo que use `assertIsolatedTestDatabase`/PostgreSQL aislado; C7 (`tests/PostgresIntegrity/Reclamaciones*`) requiere C1/C2.
- C3 → C4 → C5 (tablas `solicitudes_cotizacion`/`cotizacion_historial`, permisos `cotizaciones.*`; `000029` altera la tabla de `000027`).
- C3 → hunk de `CatalogoWebServiciosTest` (tablas de `000027`).
- C7 → hunk 2 del seeder (`RECLAMACIONES_PERMISSIONS`) y `AppServiceProvider` (limitador, sin ruta que lo use).
- C6 ← C7 solo por un `{@see AuditoriaAcademica}` en un docblock de `AuditoriaReclamaciones` (no es dependencia de ejecución).
- C8 es autónomo: `AuditoriaAdministrativa` no depende de `AuditoriaAcademica` ni crea permisos nuevos.
- Los permisos `cotizaciones.*` y `reclamaciones.*` se crean por migración (no solo por el seeder), conforme a `CLAUDE.md`.
- Banderas apagadas por defecto: `recepcion-cotizaciones.enabled=false`, `reclamaciones.enabled=false` y el `enabled=false` anidado de `config/reclamaciones.php`; ningún commit debe cambiarlas.

### 8. Riesgos de separación

1. **Seeder**: líneas añadidas contiguas de dos sprints; un `git add -p` sin edición pondría ambas constantes en el mismo commit y rompería la regla «un permiso = su migración».
2. **Commits intermedios no verificados**: el árbol completo pasa 1.448 pruebas, pero ningún commit intermedio se ha probado. Cada uno debe verificarse en un árbol limpio (p. ej. un `git worktree` con `vendor` y entorno equivalentes), sin `stash`/`reset`/`checkout` sobre este árbol.
3. **`routes/web.php`**: un hunk equivocado deja rutas que apuntan a clases aún no commiteadas (error de arranque).
4. **`PROJECT_CONTEXT.md`**: separarlo por hunks arriesga perder historia; por eso C9 es único.
5. **Sprint 19.2 suspendido**: commitearlo introduce una migración (`origen`) y rutas públicas tras un flag apagado; requiere decisión del propietario.
6. **Bloque PG-TEST** (42 archivos): previo, de alto impacto sobre el arranque de pruebas y fuera de los seis grupos pedidos; no debe mezclarse con ellos.
7. **Fin de línea**: `autocrlf=true` normaliza al commitear; los avisos son esperados. No ejecutar Pint global.
8. **Frontend de 19.1** (`resources/**`, `vite.config.js`) ya está en el árbol; commitearlo no cambia la interfaz, pero conviene confirmarlo por el límite permanente de `AGENTS.md`.
9. La consolidación no cambia la evidencia: sigue sin haber pruebas de concurrencia propias de 21.1 en PostgreSQL.

### 9. Estado de los stashes

`stash@{0}` y `stash@{1}` (origen `main`) intactos; no se aplicaron, listaron con contenido ni eliminaron. Ningún commit propuesto depende de ellos.

### 10. Próximos pasos

1. El propietario decide: (a) incluir o no el bloque PG-TEST (C1/C2) y su granularidad; (b) qué hacer con 19.2 suspendido; (c) opción para `PROJECT_CONTEXT.md` (§6); (d) mensajes de commit y co-autoría.
2. Con autorización expresa: preparar parches por hunk (seeder y rutas) en un directorio temporal fuera del repositorio, validarlos con `git apply --check --cached`, y crear cada commit en el orden C1→C9, ejecutando tras cada uno las pruebas pertinentes en un árbol aislado.
3. Al final: suite completa, Larastan nivel 6, `git diff --check`, comparar el árbol de la rama con el árbol de trabajo actual (debe ser idéntico, sin pérdida) y comprobar que los 2 stashes siguen intactos.
4. Después de consolidar: Sprint 21.2 (auditoría de matrículas) con autorización propia.

### 11. Manifiesto SHA-256 (previo a este anexo)

Formato: `sha256  estado  [grupo]  ruta`. Estado `M` = modificado, `??` = sin seguimiento. Grupos: PG, S19, S191 (19.1), S192 (19.2), S20A, S20R, S211 (21.1), MIX (compartido por hunks), MIXPG19 (PG + 19), CTX. El hash de `PROJECT_CONTEXT.md` es el anterior a este anexo.

```
620fc7a92321fc95985259b917c085f103bd48eefa64a998a8a0633df3b4e2e9   M  [PG]  .env.pgsql-testing.local.example
e7e32928c230e52f8f3cf82330657ecdbdbf9bb821d92f7b67f639d19da411c5   M  [PG]  CLAUDE.md
a43ef5962a5c6b0cd0d069284821ba911608d4312e1a171a73ef9235c3805e3c   M  [PG]  app/Support/Testing/GuardedPostgresConnector.php
25cc8c2572fe608b2ee863cd62e2d3f5e24b5796a16c379c3d265b5a7f1530ec  ??  [PG]  app/Support/Testing/PostgresTestArguments.php
84f7293d265efdd5c1a59ee60b5786bfe9c4275ba50fc71073c45614dd37506e   M  [PG]  app/Support/Testing/PostgresTestGuard.php
d07142269261d77009e7c10530d9360dbff4d004870413dd0afd610fca990068  ??  [PG]  app/Support/Testing/PostgresTestPreflight.php
c0951832e08289291d3a59d9ec507dae4b99cd78ac2d9b6391a18c0149e3aabd   M  [PG]  app/Support/Testing/TestDatabaseSafety.php
5061531ad37408965be837a045829559e92923b7e59bd9a47996dd25d8bc57f1   M  [PG]  docs/postgresql-l2.3-testing.md
0d8db69d69938f3b0d0b5fe14b02cb94684420a82f0a61421ca1ee79ca82f8a1  ??  [PG]  docs/postgresql-testing-cluster-independiente.md
d3cdd4adb39df58853783e4e48dd0d96f4ecce539be371cb2fea53860769d610  ??  [PG]  docs/postgresql-testing-detector-procesos.md
4a08882762df61abb5dba856bd38b9c52edef77504b1d23b3f8fa208c779d266  ??  [PG]  docs/postgresql-testing-estabilizacion-captura-lsp.md
e5d72b724510c187cad87b6195345f0f02c47d7b0da0a215597e44fa9442df6b  ??  [PG]  docs/postgresql-testing-pg-test-04.md
1722bc52bbd1634df7ee1ad158b076dfbedeb272fe14c6f3b535e71a9fbcf7d8  ??  [PG]  docs/postgresql-testing-validacion-final.md
ebd1465223b460e613d9c142c87de7ab6f20b2c0ea920106d1f768e87b71885d  ??  [PG]  scripts/pgsql-test-preflight.php
eb834340c6f7c7eea5676e951594f765deda1d306e4fc11963ffb3da54a645e0   M  [PG]  scripts/pgsql-test.php
c182e926ea061997b098005d80e3b0e3761bb47f5eacc5e4a96d124765efa2dc  ??  [PG]  scripts/testing/NativeCapture.cs
d9ca13a5cc42ed2e3818ce1b13724022e4342bcdd1cebe4bbd0e5512db16c168  ??  [PG]  scripts/testing/NativeCapture.ps1
7f12e4c9158086ddfaac92afb8f771cc2eac39741899777f33e4105e3a276606   M  [PG]  tests/Feature/AdminAuthorizationTest.php
e87bdfd8a79ce69d785aa6dd7e7c4cb37e804058f377d6a1875677521ed6e4c4   M  [PG]  tests/Feature/AdminReportConsistencyTest.php
e530fb39093bbac194c1b5da07d5ee994684ca8e47981a26bb79966d7d159a23   M  [PG]  tests/Feature/BackendRobustnessTest.php
3f66cae74f8135475321da6b99fb847fb4b2d8eda2b9746a6c8bab1fd97afda9   M  [PG]  tests/Feature/MatriculaIntegrityConstraintsTest.php
d0424beaca63c9c42220df19a53a20b5cadab2a28314aacf5ed870fdbb5d6285   M  [PG]  tests/Feature/MatriculaNotificationTest.php
dd2ccd42d8d42a173cf55d26ad3822cb988ca2ece39ec8f48d38d02e4e216ddb   M  [PG]  tests/Feature/ReportExcelExportTest.php
b7dc10e966fa1693a7c508001a520301befddc9743eeaa0caca9c5fdc1d6a69b   M  [PG]  tests/Feature/ReportExcelScalabilityTest.php
b8c8b7489f0243b9cb63b04f583631151f67af25cc278da55c266d69787f711b  ??  [PG]  tests/Fixtures/Handlers/HandlerLifecycleFixtureTest.php
5dea38e442104da58c7be3af209722aedd17b8997125f21e8a065c48b1a9e9f0  ??  [PG]  tests/Fixtures/Handlers/HandlerSignalsFixtureTest.php
9072702430c9ce3677a1415b0d02a60073181bb43516c7d0ebc8d21bc54a3b95  ??  [PG]  tests/Fixtures/Handlers/bootstrap-preflight-double.php
a2bec72af99d0698fd7be05f10198464f04e17d7dcba0fcae69ccd11fdf349b1  ??  [PG]  tests/Fixtures/Preflight/UnauthorizedProfileTest.php
5140c2abcace53ffeccd7fcce932fad424dd79396e8c89941b36143207a4dd1f   M  [PG]  tests/PostgresIntegrity/Concerns/RequiresIsolatedPostgres.php
c734dd89e4197b0945711868075e808211a8c54af63aea806abbf8dadf617c57   M  [PG]  tests/Support/PostgresSafetyFixtures.php
e47ca715e52c4796ca2b44a8965d1dd3fc3dc0036febcddd91c904878fc58011   M  [PG]  tests/Unit/ConcurrentTestSessionTest.php
4604d735be4d4ade7467f8394e5d9a095f47d9c72db76840fc2090f665b4dc10  ??  [PG]  tests/Unit/PostgresBootstrapLifecycleTest.php
6753c42b28a509f34dfb3104b5c488d6834c4faefdf09c7107ab4b2b8d99f3c0  ??  [PG]  tests/Unit/PostgresPreflightBoundaryTest.php
53a85a4dd35c814a0ecdffd3a151a586c582bd87eb44dcbf7175458668a376f3  ??  [PG]  tests/Unit/PostgresTestArgumentsTest.php
a70b9de47074e4eba393e2971b28a998f6555d505356c6f7dce9c58f913e3f42   M  [PG]  tests/Unit/PostgresTestGuardTest.php
7ed42bc6d6c49a4a9f2c674e9f1694374eb79de09f2526e7bb7976469e2e515e  ??  [PG]  tests/Unit/PostgresTestPreflightTest.php
50c58ba5e1df9f303d24020991449db772b6292ed60706c9e50092737c3b06f0  ??  [PG]  tests/Unit/ReadOnlyProbeTest.php
c8555cedd9b89a6aefaa6f2438017e211f7baee8037c9b32091335203cdeb152   M  [PG]  tests/Unit/TestDatabaseSafetyTest.php
9b9e0ae96ca704ac0a2b8ec52386887456893811c6e27f616875ff977eab82db  ??  [PG]  tests/Windows/NativeCaptureFixture.cs
44348b6f902fdbb6112910a05dc23b54ed4b2a0485a11593d7dd855f3802005f  ??  [PG]  tests/Windows/NativeCaptureTest.ps1
6f0a7aec4546ebca6d3a21fd4d524496430a65286b6cee56bf9c5be773e93fbf   M  [PG]  tests/bootstrap-pgsql.php
81cd5fa4b78aeec5de922d3ce8498583bf88bc064db6a8c55df7a2c41b29f02a  ??  [S19]  app/Models/CotizacionHistorial.php
32913f8813033afc80964862ba475b23484d76cc99105e637e5c7db84e4a9631  ??  [S19]  app/Models/SolicitudCotizacion.php
4b9b3d8c1bf11827c2d5a7994db84f96a2cd59800233c945045218601959a38a  ??  [S19]  app/Services/GestionCotizaciones.php
c3e8c961abc7f0b4b06f90f3e97b2052a99250a8554af5c0a1307bee730e0255  ??  [S19]  database/migrations/2026_10_29_000027_create_solicitudes_cotizacion_tables.php
e341315ab98a28a7bea2e08cc929fa7a2cffd9ca2eb181bef9fe89205c55b0d7  ??  [S19]  database/migrations/2026_10_29_000028_add_cotizaciones_permissions.php
ab7b01b07ee331cb149d7e6acf4e7dbb6ea34bb0d12dfc1597f22b50f2d9dd91  ??  [S19]  docs/restriccion-acceso-comercial-admin.md
1b76035ef396e0c4f7728d5298b8fcc9dbeceb7778e4aefadf0c3ea0d298af27  ??  [S19]  docs/sprint-19-cotizaciones.md
3f7de733bd8a3c0da6377561f600588a59a5d333e03d34311902555fd5961eea  ??  [S19]  tests/Feature/CotizacionTest.php
0e8b4e05cc04cdf096e8232b1633fac528de435aa8dd5226a1bbc17f6b681b49  ??  [S191]  app/Http/Controllers/Admin/CotizacionController.php
4f8417c45dd7d9b7d5d0c8bbaf04e70cc50f86d412342990c44dc5583a2ccea4  ??  [S191]  app/Http/Requests/GestionarCotizacionRequest.php
2d732e162732e978dbbe15868d60d6dc0c7bef5025f72c73e7d4cacfc1323fc2  ??  [S191]  app/Http/Requests/GuardarCotizacionRequest.php
402ee6db9a0669e250c0183f6aaa62b63b8026c69840af5aa7e4629fd5aea686  ??  [S191]  docs/sprint-19-1-panel-cotizaciones.md
8dbed3e995af83bcb97d0f24add82a1f858ccafb16e06cccfb7ced918628bcd5  ??  [S191]  resources/js/admin-cotizaciones.mjs
5680e8f64244aa6df92a577255969915f43c8820b9036e6c42aa4149b4970db7  ??  [S191]  resources/views/admin/cotizaciones/_editor.blade.php
f36f969700eaeb9a511095689caddde149a4e9976ddbefa25ba5a2bf85d6985d  ??  [S191]  resources/views/admin/cotizaciones/index.blade.php
8bb44532b9a3c26c1304fb8a8d87561d9485ae24aec9c0be6750e429f8e65928   M  [S191]  resources/views/admin/partials/sidebar.blade.php
07c04ad8471cd06036f2d89ef2b4b89bc8274e57a0723e80abaa69c44ed32f51  ??  [S191]  tests/Feature/PanelCotizacionesTest.php
e11fe4370d3ba829a525412111246367465a8807648f3bbefc056f07bd35fdec  ??  [S191]  tests/Support/cotizaciones-panel-browser-router.php
0814f100d36b0b05b9f8cefcf57df0c217c0d9404e9a2b8cd9ad3e41db3c0064  ??  [S191]  tests/browser/cotizaciones-panel-smoke.mjs
94992feb96dc8d3897772166309b687a799cc892f502a2c04faa305a6de38ae9  ??  [S191]  tests/frontend/admin-cotizaciones.test.mjs
05fdba7721c9ffb28ccdce560615dec9e01f2a803994c5865990838ad3b36d7e   M  [S191]  vite.config.js
6e71a67dfc5642729eb46dd90051b4330b319f9f556dd6e487166156e119c08e  ??  [S192]  app/Http/Controllers/SolicitudCotizacionPublicaController.php
7aca91d60ce7fa843f37437aadc478f217034b42a3f957c5236d035a0e8dea31  ??  [S192]  app/Http/Middleware/RecepcionCotizacionesHabilitada.php
e77829711c530e93ecfd400dd4d91f84e85f66328be2abbeb6f998606d00927a  ??  [S192]  config/recepcion-cotizaciones.php
193bdca1c178adeea826323723630c0e004b057308dd8eaf324689f166619aa1  ??  [S192]  database/migrations/2026_10_29_000029_add_origen_to_solicitudes_cotizacion.php
b47cb724a1a517081ea04fc5f716bcb20a7ba19c593a9a4bb36b8ecdec50e925  ??  [S192]  docs/sprint-19-2-preparacion-recepcion-publica.md
6465afa618648d924cb19ea9c500e1e290199dd85040edc644bfb9f2600d37f0  ??  [S192]  tests/Feature/CotizacionOrigenMigracionTest.php
d2f825f127913ebaf73e33854a6255781de962850ca40a0455122f84a0d4e30d  ??  [S192]  tests/Feature/RecepcionCotizacionesFlagTest.php
afd221301b1edd822125691221648e0207decb98bd0fc03e9a50048bea6a4af4   M  [S20A]  app/Http/Controllers/Admin/CursoController.php
25ba2b81dbb2b18d91f5740228e8a1518269a494b7e1cb703faaef8f8dea72b8   M  [S20A]  app/Http/Controllers/Admin/EstudianteController.php
2701636616cc6fb475800ac28ae5983b5fa2481d709b0be47eefa2a376c362d0  ??  [S20A]  app/Support/AuditoriaAcademica.php
bd15a0c74ec0906d1b7a0c9cba0a70096e91b24d9d1b9da6c931cf8adbb782b7  ??  [S20A]  tests/Feature/AuditoriaCursosTest.php
f11c47e9185f1515470f295aa894acfb102e35d5d692f8898b5879a69901461a  ??  [S20A]  tests/Feature/AuditoriaEstudiantesTest.php
04a2c954db46b313c1d60ab0784ea0a1b82943f1fbea47ad617afcf153ab62d8  ??  [S20A]  tests/Unit/AuditoriaAcademicaTest.php
34b370add64ad18fa55cb713c0170e489036f5786398f1ec21c066fce441f5d0  ??  [S20R]  app/Http/Middleware/RecepcionReclamacionesHabilitada.php
ee52c05b57cc2c3a4f998ccf304bc2b1f3737659213f7f08bae748dae3ce1e93  ??  [S20R]  app/Http/Requests/GestionarReclamacionRequest.php
fa289d89cd2720e800b39a96f9f70337f054bfcf7d3aef09f62440ec116abfc3  ??  [S20R]  app/Http/Requests/StoreReclamacionRequest.php
8345e3887da2e02d4180e6eca1c1344a58cb882585f038698ddbbce4cebd4dcf  ??  [S20R]  app/Jobs/EnviarReclamacionCorreo.php
6f8b619f6f9a66ca53c4e9675e21e1bd2906278dd5bde2832b085a7b76c2f903  ??  [S20R]  app/Mail/ReclamacionMail.php
e2e9a1367c528cd0098f7a52e3dd1816dde75dc692c81e75038ebac66d2a2224  ??  [S20R]  app/Models/Reclamacion.php
b94a1f2931c11f4060258de2d4813230a719a47f3167d8629d0c011b4f0f8b72  ??  [S20R]  app/Models/ReclamacionEnvio.php
9f8af37a3c18f1974381b9e6f94e73eb7b9cfcc83a587529466e85a6c4af1a7d  ??  [S20R]  app/Models/ReclamacionHistorial.php
5bffcf3b3f7a9182ada532c0e664c409fcd6662032c19aecc39c5ab5fd7b25f3   M  [S20R]  app/Providers/AppServiceProvider.php
a3e6a1aee2cd1e0c9cb23dbcdc34afc192ecd4243a98a168f368e6d726a3647d  ??  [S20R]  app/Services/EnvioReclamaciones.php
2e0105b2f185c6502fcf7989afd0f5629167edfa8a333cebfaad25e99635677d  ??  [S20R]  app/Services/GestionReclamaciones.php
fcda91868a96e44bb75b9e86e10ace8551b523c84d1cdecbf72fcd09e6676dcf  ??  [S20R]  app/Services/NumeradorReclamaciones.php
525ea68dbbe85c5eb0ab46a555218ffc7cb808f386091f925ed16b6c045dcf69  ??  [S20R]  app/Support/AntispamReclamaciones.php
83729f27dd8a351f751dc7439284ab5d2cf37f8163469ff30d94d1ba286a2c92  ??  [S20R]  app/Support/AuditoriaReclamaciones.php
3c93a6b1938dbf28b61416d28d72fc037fcd12864d0810b0382f314a994a33e8  ??  [S20R]  app/Support/DiasHabiles.php
b9c4e5fc285b59e65701ac626d626edc322d61021c7882471daca94fb0848934  ??  [S20R]  app/Support/HojaReclamacion.php
2e6bb06a6e4be453a1ca71ec9e9f9bd3281bde680d2c098f9acaa702065b2181  ??  [S20R]  app/Support/Reclamaciones.php
aadca0915ee2b93817a6ed4ea3335df0a99dbcde6913e9c43089124d0133be66  ??  [S20R]  config/reclamaciones.php
f776eb5e5515bd8f11e738063a3499e88e58b1f42f15a7c702e92f382261dd9d  ??  [S20R]  database/migrations/2026_10_30_000030_create_reclamaciones_tables.php
bfa51f431563b5ab3e57f075f3f651ccfa25bb1558831ea684f1031ab334ebc0  ??  [S20R]  database/migrations/2026_10_30_000031_add_reclamaciones_permissions.php
6b006d8853ba093b7d8fe2a90e9979886c0c8f1c57c6f533f4756d584739a419  ??  [S20R]  docs/sprint-20-fase-2-1-validacion-postgresql.md
88d01ae14787847d5037884e4e419b5a653c869b82564636d225535bd3e54231  ??  [S20R]  tests/Feature/EnvioReclamacionesTest.php
33ea07c956c1dd4a480569455185ff6db02732fc1a28fe226830234166c7223d  ??  [S20R]  tests/Feature/GestionReclamacionesTest.php
2c86eff9197d72894d733eb2c1714eb3cbd5cefc6c23355cb50a25b2121a46ef  ??  [S20R]  tests/Feature/ReclamacionRequestsTest.php
f3c61757719524d3e17f53612956e9fb0a3aa87364eb2aba371df61a16b1472b  ??  [S20R]  tests/Feature/ReclamacionesMigracionTest.php
7f7e2082f6e12509ce1073d32241b645b61f68c54b60c23fd01d6d080560e165  ??  [S20R]  tests/PostgresIntegrity/Concerns/ReclamacionesPostgres.php
85ba8156b39faa3fb9a1c91220501702d4f2eae29b4e462b95f4cf4011b92ed3  ??  [S20R]  tests/PostgresIntegrity/ReclamacionesConcurrenciaPostgresTest.php
9c9a99ec4545a0fd4365176a11e16b6b23f6bd325e72fc5bd4781af9bc272f27  ??  [S20R]  tests/PostgresIntegrity/ReclamacionesDdlPostgresTest.php
cd1049fd6e5cf990277289d8ba84a54059a1478033235ef811666814aab2d78d  ??  [S20R]  tests/Support/PreparaReclamaciones.php
3cc23fcafe60cdcc934b10dfd3c8fe39ec54497098914031eae851e027aa88ce  ??  [S20R]  tests/Unit/DiasHabilesTest.php
9dd54db21557bd565f46303650dd402f90d8aff4fd438c40b23406c1ae4a314a  ??  [S20R]  tests/Unit/NumeradorReclamacionesTest.php
6047901d398d49086f6940dc46445a987bd64f80355a513184e41040734d648e  ??  [S211]  app/Exceptions/AccionAdministrativaDenegada.php
e10370d145ef59f362b2176e64eddc8eea7ae962aa872bd2e6f0a0aa9f0020bb   M  [S211]  app/Http/Controllers/Admin/RoleController.php
00b220f9c9413d65f6ce5bc806419cc4918219dcad835ca58857e8795574240a   M  [S211]  app/Http/Controllers/Admin/UserController.php
e0084ddc6f1f2623a07e5c4edddb539001724ba126f4e9c624552ac877f34523   M  [S211]  app/Services/AdminProtection.php
f91264187fe753d9fdfcb11e3ee095b6936ae9cdfa5aa8cde5950ab4360eec21  ??  [S211]  app/Support/AuditoriaAdministrativa.php
3c2a126be71e823629262863db09a9b055917ff7270303a49ff930ced13f354a   M  [S211]  tests/Feature/AdminProtectionTest.php
8e99af17fac9118cee2777970fd60361d0e3e6531d23b4dd0f78a1e55966d807  ??  [S211]  tests/Feature/EscaladaPrivilegiosRolesTest.php
b1c393a9a3afb35af944192f8660d70d397e9d87c32c44f46d42a57bd9318d9a  ??  [S211]  tests/Feature/ProteccionAdministradoresTest.php
d0a0cbd4e5d52299881a0e28bda010095abde56b4c5140c756ddb15e0114e353  ??  [S211]  tests/Unit/AuditoriaAdministrativaTest.php
a7c8d52321f27800503b595f23080ba903b474860117c008ef3cdbd025283906   M  [MIX]  database/seeders/RolesAndPermissionsSeeder.php
24b3de9e81b17136d7ab530971c57bd97ead0b7abdbfa8d2102a4086cc9d58dd   M  [MIX]  routes/web.php
3ed515a7a411c4da6bfd4cff8e8509b325b8452fe7091d050bedd44644670bd2   M  [MIXPG19]  tests/Feature/CatalogoWebServiciosTest.php
44bbb526db80407951854de6a7beeec89c312a91ee4d2db0b0bb60899ab8ee58   M  [PG]  tests/Feature/ServicioTecnologicoTest.php
94010c317858ab459360563aac6f2c5e285055ce2b6a37ece7920e3284744b2f   M  [CTX]  PROJECT_CONTEXT.md
```


---

## PLAN DE EJECUCIÓN DE LA CONSOLIDACIÓN GIT (PREPARACIÓN — NO EJECUTADO)

> Fecha: 31/10/2026 · Complementa «PLAN DE CONSOLIDACIÓN GIT — SPRINTS 19 A 21.1». **Solo preparación**: no se ejecutó `git add`, `commit`, `apply --cached`, `worktree`, `stash`, `checkout`, `reset`, `restore` ni `clean`; el índice sigue vacío (0 archivos), rama `anderson`, HEAD `038895280000f57d39c698e40d3436fecc0d716f`, 2 stashes intactos, 115 entradas / 120 archivos. Los parches se construyeron y comprobaron **fuera del repositorio** (directorio temporal de la sesión). Anclaje: `plan-ejecucion-consolidacion`.

### 1. Decisiones del propietario aplicadas

1. C1 y C2 de PostgreSQL Testing en dos commits separados. 2. Sprint 19.2 se incluye (C5) **conservando el estado suspendido**: `config/recepcion-cotizaciones.php` con `enabled=false`, rutas públicas tras el middleware que responde 404 si el flag no es exactamente `true`, sin cambiar ningún valor por defecto. 3. `PROJECT_CONTEXT.md` íntegro en un único commit final (C9). 4. Conventional Commits. 5. **Sin `Co-Authored-By` ni otras líneas de coautoría**; se respeta la identidad Git configurada (`user.name`/`user.email` ya definidos; no se modificará la configuración). No hay hooks activos en `.git/hooks` ni `commit.gpgsign`.

### 2. Los nueve commits

Mensajes propuestos (Conventional Commits, sin trailers):

| # | Mensaje | Archivos (completos) | Hunks parciales |
| --- | --- | --- | --- |
| C1 | `test(postgresql): perfil aislado, guardas y preflight` | 34 | — |
| C2 | `test(postgresql): adaptar regresión al perfil aislado` | 8 | `CatalogoWebServiciosTest.php`: 2 hunks (-U0) PG |
| C3 | `feat(cotizaciones): solicitudes privadas` | 8 | `RolesAndPermissionsSeeder.php`: constantes + spread de cotizaciones; `CatalogoWebServiciosTest.php`: hunk de 2 `Schema::drop` |
| C4 | `feat(panel): panel de cotizaciones` | 13 | `routes/web.php`: hunks 1.º (`use CotizacionController`) y 4.º (rutas admin `cotizaciones.*`) |
| C5 | `feat(cotizaciones): recepción pública desactivada` | 7 | `routes/web.php`: hunks 2.º (`use` público + middleware) y 3.º (bloque público tras el middleware) |
| C6 | `feat(auditoria): auditoría académica` | 6 | — |
| C7 | `feat(reclamaciones): backend del libro desactivado` | 31 | `RolesAndPermissionsSeeder.php`: constante y spread de reclamaciones |
| C8 | `feat(seguridad): protección de administradores` | 9 | — |
| C9 | `docs(contexto): sprints 19 a 21.1` | 1 | — |

Totales: 34+8+8+13+7+6+31+9+1 = 117 archivos completos + 3 compartidos repartidos por hunks (`routes/web.php`, `RolesAndPermissionsSeeder.php`, `CatalogoWebServiciosTest.php`) = **120**. `AppServiceProvider.php` va entero en C7 (un único hunk, solo Reclamaciones).

#### Listas exactas

**C1 (34)**
```
.env.pgsql-testing.local.example
CLAUDE.md
app/Support/Testing/GuardedPostgresConnector.php
app/Support/Testing/PostgresTestArguments.php
app/Support/Testing/PostgresTestGuard.php
app/Support/Testing/PostgresTestPreflight.php
app/Support/Testing/TestDatabaseSafety.php
docs/postgresql-l2.3-testing.md
docs/postgresql-testing-cluster-independiente.md
docs/postgresql-testing-detector-procesos.md
docs/postgresql-testing-estabilizacion-captura-lsp.md
docs/postgresql-testing-pg-test-04.md
docs/postgresql-testing-validacion-final.md
scripts/pgsql-test-preflight.php
scripts/pgsql-test.php
scripts/testing/NativeCapture.cs
scripts/testing/NativeCapture.ps1
tests/Fixtures/Handlers/HandlerLifecycleFixtureTest.php
tests/Fixtures/Handlers/HandlerSignalsFixtureTest.php
tests/Fixtures/Handlers/bootstrap-preflight-double.php
tests/Fixtures/Preflight/UnauthorizedProfileTest.php
tests/PostgresIntegrity/Concerns/RequiresIsolatedPostgres.php
tests/Support/PostgresSafetyFixtures.php
tests/Unit/ConcurrentTestSessionTest.php
tests/Unit/PostgresBootstrapLifecycleTest.php
tests/Unit/PostgresPreflightBoundaryTest.php
tests/Unit/PostgresTestArgumentsTest.php
tests/Unit/PostgresTestGuardTest.php
tests/Unit/PostgresTestPreflightTest.php
tests/Unit/ReadOnlyProbeTest.php
tests/Unit/TestDatabaseSafetyTest.php
tests/Windows/NativeCaptureFixture.cs
tests/Windows/NativeCaptureTest.ps1
tests/bootstrap-pgsql.php
```
**C2 (8 completos + hunks de `CatalogoWebServiciosTest.php`)**
```
tests/Feature/AdminAuthorizationTest.php
tests/Feature/AdminReportConsistencyTest.php
tests/Feature/BackendRobustnessTest.php
tests/Feature/CatalogoWebServiciosTest.php   [solo hunks de C2]
tests/Feature/MatriculaIntegrityConstraintsTest.php
tests/Feature/MatriculaNotificationTest.php
tests/Feature/ReportExcelExportTest.php
tests/Feature/ReportExcelScalabilityTest.php
tests/Feature/ServicioTecnologicoTest.php
```
**C3 (8 completos + hunks de seeder y `CatalogoWebServiciosTest.php`)**
```
app/Models/CotizacionHistorial.php
app/Models/SolicitudCotizacion.php
app/Services/GestionCotizaciones.php
database/migrations/2026_10_29_000027_create_solicitudes_cotizacion_tables.php
database/migrations/2026_10_29_000028_add_cotizaciones_permissions.php
database/seeders/RolesAndPermissionsSeeder.php   [solo hunks de C3]
docs/restriccion-acceso-comercial-admin.md
docs/sprint-19-cotizaciones.md
tests/Feature/CatalogoWebServiciosTest.php   [solo hunks de C3]
tests/Feature/CotizacionTest.php
```
**C4 (13 completos + hunks de `routes/web.php`)**
```
app/Http/Controllers/Admin/CotizacionController.php
app/Http/Requests/GestionarCotizacionRequest.php
app/Http/Requests/GuardarCotizacionRequest.php
docs/sprint-19-1-panel-cotizaciones.md
resources/js/admin-cotizaciones.mjs
resources/views/admin/cotizaciones/_editor.blade.php
resources/views/admin/cotizaciones/index.blade.php
resources/views/admin/partials/sidebar.blade.php
routes/web.php   [solo hunks de C4]
tests/Feature/PanelCotizacionesTest.php
tests/Support/cotizaciones-panel-browser-router.php
tests/browser/cotizaciones-panel-smoke.mjs
tests/frontend/admin-cotizaciones.test.mjs
vite.config.js
```
**C5 (7 completos + hunks de `routes/web.php`)**
```
app/Http/Controllers/SolicitudCotizacionPublicaController.php
app/Http/Middleware/RecepcionCotizacionesHabilitada.php
config/recepcion-cotizaciones.php
database/migrations/2026_10_29_000029_add_origen_to_solicitudes_cotizacion.php
docs/sprint-19-2-preparacion-recepcion-publica.md
routes/web.php   [solo hunks de C5]
tests/Feature/CotizacionOrigenMigracionTest.php
tests/Feature/RecepcionCotizacionesFlagTest.php
```
**C6 (6)**
```
app/Http/Controllers/Admin/CursoController.php
app/Http/Controllers/Admin/EstudianteController.php
app/Support/AuditoriaAcademica.php
tests/Feature/AuditoriaCursosTest.php
tests/Feature/AuditoriaEstudiantesTest.php
tests/Unit/AuditoriaAcademicaTest.php
```
**C7 (31 + hunk de seeder)**
```
app/Http/Middleware/RecepcionReclamacionesHabilitada.php
app/Http/Requests/GestionarReclamacionRequest.php
app/Http/Requests/StoreReclamacionRequest.php
app/Jobs/EnviarReclamacionCorreo.php
app/Mail/ReclamacionMail.php
app/Models/Reclamacion.php
app/Models/ReclamacionEnvio.php
app/Models/ReclamacionHistorial.php
app/Providers/AppServiceProvider.php
app/Services/EnvioReclamaciones.php
app/Services/GestionReclamaciones.php
app/Services/NumeradorReclamaciones.php
app/Support/AntispamReclamaciones.php
app/Support/AuditoriaReclamaciones.php
app/Support/DiasHabiles.php
app/Support/HojaReclamacion.php
app/Support/Reclamaciones.php
config/reclamaciones.php
database/migrations/2026_10_30_000030_create_reclamaciones_tables.php
database/migrations/2026_10_30_000031_add_reclamaciones_permissions.php
database/seeders/RolesAndPermissionsSeeder.php   [solo hunks de C7]
docs/sprint-20-fase-2-1-validacion-postgresql.md
tests/Feature/EnvioReclamacionesTest.php
tests/Feature/GestionReclamacionesTest.php
tests/Feature/ReclamacionRequestsTest.php
tests/Feature/ReclamacionesMigracionTest.php
tests/PostgresIntegrity/Concerns/ReclamacionesPostgres.php
tests/PostgresIntegrity/ReclamacionesConcurrenciaPostgresTest.php
tests/PostgresIntegrity/ReclamacionesDdlPostgresTest.php
tests/Support/PreparaReclamaciones.php
tests/Unit/DiasHabilesTest.php
tests/Unit/NumeradorReclamacionesTest.php
```
**C8 (9)**
```
app/Exceptions/AccionAdministrativaDenegada.php
app/Http/Controllers/Admin/RoleController.php
app/Http/Controllers/Admin/UserController.php
app/Services/AdminProtection.php
app/Support/AuditoriaAdministrativa.php
tests/Feature/AdminProtectionTest.php
tests/Feature/EscaladaPrivilegiosRolesTest.php
tests/Feature/ProteccionAdministradoresTest.php
tests/Unit/AuditoriaAdministrativaTest.php
```
**C9 (1)**
```
PROJECT_CONTEXT.md
```

Contenido: C1 guardas del perfil `127.0.0.1:55433`, preflight, arnés de captura nativo, pruebas unitarias/fixtures de esas piezas y documentación/`CLAUDE.md`; C2 ajustes de pruebas existentes (`DatabaseMigrations`, savepoints, UUID de lease); C3 modelos, `GestionCotizaciones`, migraciones `000027`/`000028`, `CotizacionTest`, 2 documentos; C4 controlador, requests, vistas, JS, `sidebar`, `vite.config.js`, pruebas del panel; C5 controlador público, middleware, config con flag apagado, migración `000029`, pruebas y documento; C6 `AuditoriaAcademica`, `CursoController`, `EstudianteController`, 3 pruebas; C7 Libro de Reclamaciones con `enabled=false` (modelos, servicios, requests, job, mail, `config/reclamaciones.php`, migraciones `000030`/`000031`, pruebas Feature/Unit/PostgresIntegrity, limitador en `AppServiceProvider`); C8 R1–R4 y evento `acceso_denegado`; C9 contexto.

### 3. Parches de índice para los archivos compartidos

Generados desde HEAD y el contenido de trabajo (hunks `-U0` seleccionados y vueltos a emitir con 3 líneas de contexto). Cada paso se construye sobre el resultado del anterior, de modo que se aplican **en orden exacto**:

| Parche | Contenido | Se aplica en | SHA-256 |
| --- | --- | --- | --- |
| `catalogo.C2.patch` | comentario PG + eliminación de `assertSame('sqlite', …)` | C2 | `190b4ea1044b5f720efcef8ccfee8ad539bfbd2dde6bf590996df9b86ac884cc` |
| `catalogo.C3.patch` | `Schema::drop('cotizacion_historial')` y `('solicitudes_cotizacion')` | C3 | `659923e7a48192b7d177b4265856f3ade0aa5c507727f8327c24bce3befa4b3f` |
| `seeder.C3.patch` | `COTIZACIONES_PERMISSIONS` + `...self::COTIZACIONES_PERMISSIONS` | C3 | `0575fbc1d4b16adb3a61845d0ccc9291fab3f22018849b6ae55d8b2989c830a0` |
| `routes.C4.patch` | `use CotizacionController` + 10 líneas de rutas admin | C4 | `aac2325f1824b3e2a10d2ec8665908b9e9948ade4c3a7d60eb8abd3dc8b514da` |
| `routes.C5.patch` | `use` del controlador público y del middleware + bloque de rutas públicas | C5 | `121f8c4d2ada77fe81cfb480ce48752eeb9c75ab855b9f5f5ca06ccea1cf4af6` |
| `seeder.C7.patch` | `RECLAMACIONES_PERMISSIONS` + `...self::RECLAMACIONES_PERMISSIONS` | C7 | `5bc58089832f5797a3004eb1b5b440e00e17510e34aa0cbffbc2f3275ed1a6c4` |

**Comprobado (sin tocar el repositorio):** los seis parches se aplicaron en cadena sobre copias de los blobs de HEAD en un directorio temporal (`git -c core.autocrlf=false apply`, sin repositorio) y el resultado de cada archivo es **idéntico byte a byte** al contenido de trabajo actual; las versiones intermedias pasan `php -l`; la versión de `seeder.C3` no contiene ninguna mención a «reclamaciones»; la de `routes.C4` no contiene ningún símbolo de 19.2. Los blobs de HEAD y los archivos de trabajo de estos tres archivos son **LF** (los parches también). Hallazgo: `git apply` fuera de un repositorio, con `core.autocrlf=true` global, reescribe a CRLF; por eso toda comprobación usó `-c core.autocrlf=false`. En la ejecución real se usará `git apply --cached` (opera sobre blobs del índice). Los parches y el script generador viven solo en el directorio temporal de la sesión (volátil): **deben regenerarse y re-hashearse** inmediatamente antes de aplicar, y comprobarse con `git apply --check --cached`.

Procedimiento por commit con compartidos: `git apply --check --cached <parche>` → `git apply --cached <parche>` → `git add -- <rutas completas del commit>` (siempre rutas explícitas; nunca `-A`, `.` ni `-u`) → comprobar `git diff --cached --name-only` contra la lista de este plan y `git diff --cached --stat`.

### 4. Dependencias entre commits

- C1 → C2 → resto (infraestructura de pruebas); C7 incluye `tests/PostgresIntegrity/Reclamaciones*` que usan `RequiresIsolatedPostgres` (C1).
- C3 → C4 → C5: tablas de `000027`, permisos `cotizaciones.*`, constante del seeder (C3); `000029` altera la tabla de C3.
- Hunk de `CatalogoWebServiciosTest` de `Schema::drop` → exige la migración `000027` (por eso va en C3, nunca antes).
- C7 ← seeder (hunk 2) y `AppServiceProvider`; C6 y C7 solo comparten una referencia `{@see AuditoriaAcademica}` en un docblock (sin efecto en ejecución).
- C8 es autónomo (no crea permisos ni depende de C3–C7).
- Verificación estática hecha: ninguna importación `use App\…` de los archivos de un commit apunta a una clase de un commit posterior (las dos únicas coincidencias son las del `routes/web.php` completo, precisamente el archivo que se reparte por hunks).

### 5. Estrategia de pruebas por commit (worktree aislado)

No se ha ejecutado nada de esto. Para cada commit, tras crearlo:

1. `git worktree add --detach <ruta fuera del repo> <sha>` (un worktree nuevo por commit; se elimina después con `git worktree remove` y `git worktree prune`). No se usa `checkout` sobre este árbol.
2. Entorno del worktree: copiar `vendor/` (no enlazarlo: el autoload de Composer resuelve rutas relativas a su ubicación real y apuntaría al árbol principal) y ejecutar `php composer.phar dump-autoload --no-scripts` dentro del worktree (sin red); copiar `.env` **sin imprimirlo ni editarlo** (necesario por `APP_KEY`; `phpunit.xml` fuerza SQLite `:memory:`, sesión/caché `array`). Sin PostgreSQL, sin migraciones sobre bases reales.
3. Ejecutar solo las pruebas pertinentes (SQLite en memoria):

| Commit | Pruebas | Notas |
| --- | --- | --- |
| C1 | `--filter="PostgresTestGuard|TestDatabaseSafety|ConcurrentTestSession|PostgresBootstrapLifecycle|PostgresPreflightBoundary|PostgresTestArguments|PostgresTestPreflight|ReadOnlyProbe"` | Los fixtures de `tests/Fixtures` y `tests/Windows/*.ps1` los ejecuta el arnés/PowerShell; no se afirmará su validación sin correrlos |
| C2 | `--filter="AdminAuthorizationTest|AdminReportConsistencyTest|BackendRobustnessTest|MatriculaIntegrityConstraintsTest|MatriculaNotificationTest|ReportExcel|ServicioTecnologicoTest|CatalogoWebServiciosTest"` | `CatalogoWebServiciosTest` sin los `drop` de cotizaciones |
| C3 | `CotizacionTest`, `CatalogoWebServiciosTest`, `AdminAuthorizationTest`, `MatriculaSensitivePermissionsTest` | Instalación nueva ejecuta `000027`/`000028` |
| C4 | `PanelCotizacionesTest`, `CotizacionTest`, `npm test`; `npm run build` solo si se autoriza | Revisar que `vite.config.js` y el JS coincidan |
| C5 | `RecepcionCotizacionesFlagTest`, `CotizacionOrigenMigracionTest`, `CotizacionTest`, `PanelCotizacionesTest` | Confirmar que con el flag apagado la ruta pública no responde |
| C6 | `AuditoriaCursosTest`, `AuditoriaEstudiantesTest`, `AuditoriaAcademicaTest`, `AdminCursoTest`, `AdminEstudianteTest` | |
| C7 | `GestionReclamacionesTest`, `EnvioReclamacionesTest`, `ReclamacionRequestsTest`, `ReclamacionesMigracionTest`, `NumeradorReclamacionesTest`, `DiasHabilesTest`, `AdminAuthorizationTest` | `tests/PostgresIntegrity/Reclamaciones*` **no** se ejecutan (requieren PostgreSQL aislado y autorización) |
| C8 | `ProteccionAdministradoresTest`, `EscaladaPrivilegiosRolesTest`, `AuditoriaAdministrativaTest`, `AdminProtectionTest`, `AdminAuthorizationTest`, `Sprint12AtomicityTest` | |
| C9 | `git diff --check` sobre el commit; sin pruebas funcionales | |

4. Verificación final en el worktree de C9 y en el árbol principal: `php artisan test --compact` (esperado 1.448 pasan, 1 omitida), `vendor/bin/phpstan analyse` (nivel 6) y `npm test`. Un commit se declara «validado» **solo** si sus pruebas de la tabla se ejecutaron y pasaron; el resto se informa como «sin ejecutar».
5. Opcional previo (recomendado): ensayo de las secuencias en directorios temporales (copia de `git archive HEAD` + superposición de archivos y parches) antes de tocar el índice real.

### 6. Riesgos y posibles conflictos

1. **Parches volátiles**: viven fuera del repositorio; regenerar y re-hashear antes de usarlos. 2. **`git apply --cached` falla** si el índice difiere del esperado (p. ej. tras un commit intermedio con contenido distinto): detener y analizar, no forzar con `--reject`. 3. **Orden del seeder**: aplicar `seeder.C7` antes que `seeder.C3` fallaría. 4. **Fin de línea**: algunos archivos de esta sesión están en CRLF en la copia de trabajo (`AdminProtection.php`, `UserController.php`, `AdminProtectionTest.php`); con `core.autocrlf=true` `git add` los normaliza a LF, sin cambiar el contenido. Tras el último commit, `git status` debe quedar vacío; si algún archivo aparece modificado solo por finales de línea, se informará sin «arreglarlo» con restore/checkout. 5. **Sprint 19.2** introduce una migración y rutas públicas tras un flag apagado: la prueba de C5 debe confirmar el cierre por defecto. 6. **C7 es grande (31 archivos)**: ningún test PostgreSQL de Reclamaciones se ejecuta sin autorización; se registrará como pendiente. 7. **C1** modifica el arranque de pruebas: no debilitar guardas. 8. **`PROJECT_CONTEXT.md`** seguirá creciendo hasta C9; su hash cambia con cada anexo. 9. **Pruebas intermedias**: ninguna de las pruebas por commit se ha ejecutado todavía. 10. **Ramas/stashes**: no se hace push ni se cambia de rama; los dos stashes no se tocan.

### 7. Estado final esperado

Rama `anderson` con **9 commits nuevos** sobre `038895280000f57d39c698e40d3436fecc0d716f` (C1…C9, en ese orden y con esos mensajes, sin trailers de coautoría), autor según la identidad Git configurada; índice vacío; `git status --short` vacío (salvo archivos ignorados); `git diff HEAD` vacío (el árbol de HEAD equivale al contenido de trabajo actual, con `PROJECT_CONTEXT.md` ampliado hasta C9); los **2 stashes intactos**; sin push; sin ramas nuevas; los worktrees temporales eliminados y podados; contadores esperados: 34+8+8+13+7+6+31+9+1 archivos completos y 3 compartidos repartidos. Verificación de cierre: comparar los SHA-256 del manifiesto de la sección anterior con los del árbol final (todos iguales, salvo `PROJECT_CONTEXT.md` que se compara con su hash tras este anexo), y recorrer `git show --stat` de cada commit contra las listas de §2.

### 8. Estado de autorización

Esta fase solo prepara el plan. **No se autoriza** `git add`, `git commit` ni modificar el índice hasta instrucción expresa del propietario.

---

## EJECUCIÓN DE LA CONSOLIDACIÓN GIT — SPRINTS 19 A 21.1 (RESULTADOS REALES)

> Fecha: 31/10/2026 · Ejecuta el plan «PLAN DE EJECUCIÓN DE LA CONSOLIDACIÓN GIT» tras autorización expresa del propietario. Rama `anderson`, base `038895280000f57d39c698e40d3436fecc0d716f`. Sin push, sin ramas nuevas, sin migraciones, sin conexión a PostgreSQL, sin cambios de `.env`, 2 stashes intactos. Autor: identidad Git configurada (Anderson); sin líneas de coautoría. Anclaje: `ejecucion-consolidacion-git`.

### 1. Commits creados (orden previsto)

| # | Commit | Árbol | Mensaje | Archivos | Pruebas del commit (SQLite en memoria, worktree aislado) |
| --- | --- | --- | --- | --- | --- |
| C1 | `1f4167a45e8bea624ffa8205e53e60f78a88c355` | `390558e6686f…` | `test(postgresql): perfil aislado, guardas y preflight` | 34 | 239 pasan (guardas, preflight, argumentos, ciclo de arranque, sonda de solo lectura, sesión concurrente) |
| C2 | `cc7e761e76535a35b195f54d8f2336e90457062f` | `010bc58109cc…` | `test(postgresql): adaptar regresión al perfil aislado` | 9 (8 + hunks de `CatalogoWebServiciosTest`) | 243 pasan, 1 omitida |
| C3 | `eb1c39ec2953bcad158cc0b2a1015c7830f35008` | `9bbf5bd892a3…` | `feat(cotizaciones): solicitudes privadas` | 9 (7 + seeder y catálogo por parche) | 124 pasan (`CatalogoWebServiciosTest`, `AdminAuthorizationTest`, `MatriculaSensitivePermissionsTest`) |
| C4 | `455180eef8c5df0dbfc352e671f9f33ad2d089c2` | `433180be56a8…` | `feat(panel): panel de cotizaciones` | 15 (14 + `routes/web.php` por parche) | 166 pasan (incluye `CotizacionTest` y `PanelCotizacionesTest`) y `npm test` 36/36 |
| C5 | `e244bb978478dd6a817a7995453969af99512a56` | `2be6e43d7ffd…` | `feat(cotizaciones): recepción pública desactivada` | 8 (7 + `routes/web.php` por parche) | 74 pasan; `config/recepcion-cotizaciones.php` con `enabled=false` |
| C6 | `d5447f1686a6716cdf1e9114f81836fa4f24a3c3` | `837d41393895…` | `feat(auditoria): auditoría académica` | 6 | 54 pasan |
| C7 | `4c15fea04ddfb05c61f8ead8fda85d18b4e7cb19` | `277d8e271d1f…` | `feat(reclamaciones): backend del libro desactivado` | 32 (31 + seeder por parche) | 221 pasan; `enabled=false` en `config/reclamaciones.php` |
| C8 | `1af88435f4ce448939cb047a5c64bca64b0e8878` | `542770f1a8f0…` | `feat(seguridad): protección de administradores` | 9 | 157 pasan |
| C9 | (este commit; su hash no puede constar dentro de sí mismo, ver `git log`) | — | `docs(contexto): sprints 19 a 21.1` | 1 | `git diff --check` |

Método: por cada commit se preparó el índice, se comprobó que el conjunto de archivos fuera exactamente el previsto y que el contenido de cada blob coincidiera con el archivo de trabajo (normalizando fin de línea); se creó un commit colgante con ese árbol, se probó en un worktree desechable fuera del repositorio y, solo con las pruebas en verde, se hizo el commit real comprobando que su árbol fuera idéntico al probado. Los worktrees se eliminaron y podaron.

### 2. Incidencia: dependencia HTTP entre C3 y C4 (plan corregido)

- Al probar el C3 previsto, `CotizacionTest` falló 46 de sus pruebas con 404 en `/admin/cotizaciones`: la prueba ejercita el sistema por HTTP y las rutas, el controlador y los requests llegan en C4. El análisis estático de dependencias solo cubría `use` entre clases PHP y no detectó la dependencia de ruta.
- No se creó el C3 original. Decisión del propietario (opción 1): **`tests/Feature/CotizacionTest.php` se mueve de C3 a C4**. Se retiró del índice con `git rm --cached` (el archivo se conservó íntegro en el árbol de trabajo, SHA-256 `3f7de733bd8a3c0da6377561f600588a59a5d333e03d34311902555fd5961eea`, sin `reset`/`restore`/`checkout`/`clean`/`stash`), se comprobó que los demás archivos y los dos parches de C3 quedaran idénticos y se rehízo la verificación.
- Nueva distribución: C3 = 7 archivos completos + 2 parches (antes 8 + 2); C4 = 14 archivos completos + 1 parche (antes 13 + 1). Total sin cambios: 120 archivos.
- Lección: en futuras consolidaciones, verificar también dependencias por rutas/configuración/migraciones con pruebas ejecutadas por commit, no solo `use`.

### 3. Incidencias de entorno de verificación (no del código)

- Los worktrees no incluyen archivos ignorados por Git: se copiaron `vendor/` y `public/build/` (generados) y se creó `storage/framework/testing/disks/local/exports`. Se usó un `APP_KEY` aleatorio solo en memoria y un `.env` vacío; **no se copió ni leyó el `.env` real**.
- Un primer intento falló por conversión de rutas de Git Bash en `robocopy` (vendor no copiado); otro por falta de `public/build` (56 fallos de manifest Vite) y otro por falta del directorio `exports`. Ninguno afectó al contenido de los commits.
- Sin `.env` las pruebas emiten avisos `file_get_contents`; con `.env` vacío desaparecen.
- Las advertencias LF/CRLF de Git son las conocidas (`core.autocrlf=true`); los blobs quedaron en LF.

### 4. Verificación final

- Suite completa en el árbol de C8 (idéntico en código al HEAD final; C9 solo cambia documentación): **1 448 pasan, 1 omitida (9 236 aserciones)**. `vendor/bin/phpstan analyse`: **sin errores**.
- SHA-256: los 119 archivos del manifiesto distintos de `PROJECT_CONTEXT.md` coinciden en el árbol de trabajo con el manifiesto del plan y los blobs de `HEAD` coinciden con ellos (idénticos, o idénticos tras normalizar CRLF→LF en los archivos con fin de línea CRLF locales).
- Los tres archivos compartidos (`routes/web.php`, `RolesAndPermissionsSeeder.php`, `CatalogoWebServiciosTest.php`) se repartieron con parches `git apply --cached`; el resultado final es idéntico byte a byte al archivo de trabajo.
- Libro de Reclamaciones y recepción pública de cotizaciones **siguen deshabilitados** (`enabled=false`; la ruta pública responde 404 salvo `true` estricto).

### 5. Limitaciones

- **No ejecutado:** pruebas de PostgreSQL (`tests/PostgresIntegrity/Reclamaciones*` y el resto), concurrencia real con `lockForUpdate` (SQLite la ignora), fixtures `tests/Fixtures/**` y `tests/Windows/*.ps1`, `npm run build` y smokes de navegador.
- Cada commit se validó solo con el subconjunto de pruebas indicado; la suite completa se ejecutó una vez, sobre C8.
- `npm test` se ejecutó en C4 (36 pruebas); no se repitió en commits posteriores.
- Los parches y scripts de apoyo estaban en un directorio temporal de la sesión, fuera del repositorio.

### 6. Pendiente (con autorización aparte)

Sprint 21.2 (auditoría de matrículas), pruebas de concurrencia de PostgreSQL en el perfil aislado 55433, ocultar en la interfaz las acciones que el servidor deniega, y push cuando el propietario lo autorice.

### 7. Nota sobre el diff de C9

El diff de C9 contra el `PROJECT_CONTEXT.md` de `HEAD` (`038895280000…`) contiene 21 líneas reemplazadas en las tablas de estado del encabezado y de secciones antiguas (p. ej. «Último sprint documentado: 18.2», «Cotizaciones — sin módulo específico implementado», «Testing, puerto 55432…»). Fueron actualizadas **en fases documentales anteriores a esta ejecución** (PG-TEST-05B, Sprints 19 a 21.1), no durante la consolidación; esta no reescribió ninguna sección histórica. Las secciones y anexos previos permanecen íntegros y el resto del diff son añadidos.

---

## SPRINT 21.2 — FASE 1: NÚCLEO DE AUDITORÍA DE MATRÍCULAS

> Fecha: 31/10/2026 · Implementa solo el núcleo de la Fase 1 autorizada tras las decisiones D1–D8 de la Fase 0. **Ningún controlador invoca todavía este núcleo**: el comportamiento de las matrículas no cambia. Sin migraciones, sin conexión a bases de datos reales ni a PostgreSQL, sin `.env`, sin staging, commit ni push. Anclaje: `sprint-21-2-fase-1`.

### 1. Objetivo y alcance autorizado

Preparar y probar la lógica central que usarán las fases 2 a 4 para auditar crear, actualizar, anular, eliminar y restaurar matrículas. Archivos autorizados: `app/Support/AuditoriaAcademica.php`, `tests/Unit/AuditoriaAcademicaTest.php` y `PROJECT_CONTEXT.md`. No se tocaron `MatriculaController`, modelos, Form Requests, rutas, vistas, JS/CSS, migraciones, `AuditLog`, `SensitiveData` ni el historial CSV (D6 queda para la Fase 4; D8: el CSV no cambia).

### 2. Archivos modificados

| Archivo | Cambio |
| --- | --- |
| `app/Support/AuditoriaAcademica.php` | +API de efecto sobre cupos, detalle de creación, detalle de eliminación/restauración y parámetro opcional `efectoCupo`; docblock actualizado; `use InvalidArgumentException` |
| `tests/Unit/AuditoriaAcademicaTest.php` | Se conservan los 6 tests existentes sin alterar sus aserciones; se añaden 46 pruebas (con datasets) y el trait `RefreshDatabase` para las que comprueban lo persistido |
| `PROJECT_CONTEXT.md` | Esta sección (anexo; historial intacto) |

### 3. Arquitectura implementada

- `efectoCupo(?string $estadoAntes, ?int $cursoAntes, ?string $estadoDespues, ?int $cursoDespues): string` — **función pura**; solo clasifica, nunca modifica cupos. «Ocupa cupo» = existe y estado `pendiente` o `pagada`; `anulada` y «no existe / eliminada» (`null`) no ocupan. Un estado fuera de `pendiente`/`pagada`/`anulada` lanza `InvalidArgumentException` (sin normalizar mayúsculas).
- `efectosPermitidos()` devuelve la lista cerrada `['ocupa', 'libera', 'traslada', 'ninguno']` (constantes `EFECTO_*`). Cualquier otro valor lanza `InvalidArgumentException` en todos los puntos de entrada.
- `detalleCreacion($modelo, $entidad, ?$efectoCupo)`: lógica de `registrarCreacion` extraída a un constructor puro del detalle (campos con valor solo los autorizados). `registrarCreacion` y `registrarActualizacion` aceptan un `?string $efectoCupo` opcional al final de la firma; **sin él, el formato guardado de estudiantes y cursos es idéntico al anterior** (probado).
- `detalleOperacionMatricula($matricula, $operacion, $efectoCupo)` y `registrarOperacionMatricula(...)` (D7): `operacion` de lista cerrada (`eliminacion_logica`, `restauracion`), `efecto_cupo`, y solo `curso_id` y `estado` como valores. Nada más.
- Se reutilizan `diferencias()`, `actorDe()`, `registrarActualizacion()` y `AuditLog::record()` sin duplicar mecanismos. Para D3 basta `registrarActualizacion('matricula_anulada', …)` con todas las diferencias; para D4, una matrícula ya anulada no produce diferencias y `registrarActualizacion` devuelve `false` (no escribe).
- La auditoría no abre transacciones (contrato heredado): el llamador la invoca como última escritura de la transacción de la operación.

### 4. Campos permitidos y política de privacidad

- **Con valor anterior y nuevo (D1, D2):** `estudiante_id`, `curso_id`, `fecha_matricula` (`Y-m-d`), `estado`, `monto_pagado` (cadena `decimal:2`, p. ej. `100.00`).
- **Solo nombre del campo:** `observaciones` (nunca su contenido, ni el anterior ni el nuevo).
- Todo campo ajeno a la lista (`dni`, `password`, `remember_token`, `session_id`, etc.) se ignora por completo: ni valor ni nombre. Los atributos técnicos `created_at`, `updated_at`, `deleted_at` se excluyen siempre.
- Formatos equivalentes no cuentan como cambio (`monto_pagado` `'100'` vs `'100.00'`, fecha igual en distinto formato, ids como cadena).
- El texto de las excepciones solo contiene el valor técnico rechazado (estado, efecto u operación); `operacion` no admite texto libre.

### 5. Reglas del efecto sobre cupos

| Situación | Entrada (antes → después) | Efecto |
| --- | --- | --- |
| Alta vigente | `null` → `pendiente`/`pagada` | `ocupa` |
| Alta nacida anulada | `null` → `anulada` | `ninguno` |
| Anulación de vigente | `pendiente`/`pagada` → `anulada` (también con cambio de curso) | `libera` |
| Ya anulada | `anulada` → `anulada` (también con cambio de curso) | `ninguno` |
| Reactivación | `anulada` → vigente (mismo u otro curso) | `ocupa` |
| Eliminación de vigente / de anulada | vigente → `null` / `anulada` → `null` | `libera` / `ninguno` |
| Restauración de vigente / de anulada | `null` → vigente / `null` → `anulada` | `ocupa` / `ninguno` |
| Cambio de curso con vigente | vigente → vigente, curso distinto | `traslada` |
| Vigente a vigente, mismo curso (incluye `pendiente`→`pagada`) | — | `ninguno` |
| Nada antes ni después | `null` → `null` | `ninguno` |

Convención fijada por prueba: si ambas versiones ocupan y uno de los cursos es desconocido (`null`) frente a otro conocido, se clasifica como `traslada`.

### 6. Pruebas ejecutadas y resultados reales (SQLite en memoria; ninguna PostgreSQL)

- `php artisan test tests/Unit/AuditoriaAcademicaTest.php`: **52 pasan, 141 aserciones** (6 originales + 46 nuevas). Cubren: lista permitida, diferencias antes/después, campos técnicos y ajenos, sin cambios (formatos equivalentes), identificadores, `monto_pagado`, `observaciones`, nulos, 20 casos de efecto (`ocupa`, `libera`, `traslada`, `ninguno`, anuladas, cambios de curso y de estado), estados desconocidos, efecto/operación inválidos, anulación por `PUT` con otros cambios (un solo evento), anulación repetida (cero eventos), persistencia real en `audit_logs` (actor, esquema, valores, sin contenido privado), y compatibilidad de estudiantes y cursos.
- Pruebas de auditoría existentes (`AuditoriaAcademicaTest`, `AuditoriaCursosTest`, `AuditoriaEstudiantesTest`, `Sprint12AtomicityTest`, `AuditoriaAdministrativaTest`, `ProteccionAdministradoresTest`): **124 pasan, 707 aserciones**.
- **Control de mutación** (cada cambio revertido de inmediato y comprobado por hash): anular la rama `traslada` → 4 pruebas fallan; desactivar la validación de efectos → 1 falla; filtrar `observaciones` al detalle → 3 fallan.
- No se ejecutó la suite completa en esta fase (el alcance son tres archivos y no hay llamadores nuevos).

### 7. Calidad

- **Pint** (`vendor/bin/pint --test` sobre los dos archivos PHP): `passed`.
- **Larastan nivel 6** (`vendor/bin/phpstan analyse`, proyecto completo): `[OK] No errors`.
- **`git diff --check`** sobre los dos archivos PHP: sin errores (solo la advertencia conocida LF/CRLF de `core.autocrlf`).

### 8. SHA-256 de los archivos modificados

| Archivo | SHA-256 |
| --- | --- |
| `app/Support/AuditoriaAcademica.php` | `aec3e0317849d76b89377e902b322836800ef90dafd2b7ff9143dfaf81670919` |
| `tests/Unit/AuditoriaAcademicaTest.php` | `b74218ab40bdba7955130fd9d71f5564c1a8e329a99e6a61381ecc7a0541d2d2` |
| `PROJECT_CONTEXT.md` | no consta aquí (un archivo no puede contener su propio hash); ver el informe de entrega |

Antes de la fase: `AuditoriaAcademica.php` `2701636616cc6fb475800ac28ae5983b5fa2481d709b0be47eefa2a376c362d0` y `AuditoriaAcademicaTest.php` `04a2c954db46b313c1d60ab0784ea0a1b82943f1fbea47ad617afcf153ab62d8`.

### 9. Estado Git

- Antes: rama `anderson`, HEAD `42569608daa7935727af60ad291f210a8ecef118`, índice vacío, 2 stashes; `PROJECT_CONTEXT.md` ya modificado (anexo de la Fase 0, sin commit).
- Después: mismo HEAD y mismos stashes; índice vacío; modificados `PROJECT_CONTEXT.md`, `app/Support/AuditoriaAcademica.php` y `tests/Unit/AuditoriaAcademicaTest.php`. Sin staging, commit ni push; no se usó `reset`, `restore`, `clean`, `checkout` ni `stash`.

### 10. Limitaciones

- Núcleo sin llamadores: la auditoría real de matrículas no existe hasta la Fase 2.
- No se mide concurrencia (SQLite ignora `lockForUpdate`) ni se usa PostgreSQL.
- `RefreshDatabase` en la clase unitaria hace que sus pruebas migren SQLite en memoria; el resto de pruebas siguen siendo puras.
- El detalle de eliminación/restauración depende de que el llamador pase la fila ya bloqueada (su `estado` y `curso_id` vigentes en ese instante).
- La corrección de `efecto_cupo` frente a los cupos reales se probará en las fases 2–3 con el invariante de delta de `cupos_disponibles`.

### 11. Pendientes para la Fase 2

1. Autorización expresa para tocar `MatriculaController`.
2. Cablear `store`, `update` (`fill` → `diferencias` → `save`) y `anular` con auditoría como última escritura de la transacción, `actorDe($request)` y `efectoCupo` calculado sobre la fila bloqueada.
3. Pruebas de integración M01–M08, M12–M14 y M16–M20 de la matriz de la Fase 0 (incluido rollback con `AuditLog::creating` que lanza).
4. Fases 3 (eliminar, restaurar), 4 (CSV, D6) y 5 (cierre) después, cada una con su autorización.
