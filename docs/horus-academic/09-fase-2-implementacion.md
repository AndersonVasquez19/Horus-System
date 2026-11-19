# 09 · Fase 2 — Fundamentos (implementación)

> **16/11/2026.** Primer incremento funcional de Horus Academic. Sin commits, sin push, sin migraciones ejecutadas, sin
> escrituras en PostgreSQL (ni real ni aislado) y sin backfills. Evidencia: **SQLite en memoria**; no prueba PostgreSQL.

## 1. Alcance (según [08](08-plan-migraciones.md) y [07](07-plan-pruebas.md))

La Fase 2 documentada era «identidad, permisos y auditoría»: de todo lo diseñado, lo único que pertenece a esta fase es el
vínculo estudiante↔usuario; los permisos, tablas y rutas de docentes, infraestructura, sesiones, pagos y certificados son de las
fases 3–6 y **no se tocaron**. No se creó ningún permiso nuevo (cada uno viaja con su módulo).

| Entregable | Estado |
| --- | --- |
| Pest 3 instalado y conviviendo con PHPUnit | Hecho |
| Migración `estudiantes.user_id` (opcional, único) + relaciones de modelo | Hecha (creada, **no ejecutada**) |
| Prueba de la matriz rol × acción del panel existente | Hecha (29 casos) |
| Prueba de paridad contrato OpenAPI ↔ rutas | Hecha (5 casos) |
| Contrato OpenAPI sincronizado | Hecho (1 corrección) |
| Docs y `AGENTS.md` actualizados | Hecho |

## 2. Archivos

**Creados**
- `apps/api/database/migrations/2026_11_16_000061_add_user_id_to_estudiantes_table.php`
- `apps/api/tests/Pest.php`
- `apps/api/tests/Academic/Concerns/LlamaAlPanel.php`
- `apps/api/tests/Academic/Identidad/VinculoEstudianteUsuarioTest.php` (9 casos)
- `apps/api/tests/Academic/Identidad/MatrizRolesAcademicoTest.php` (29 casos)
- `apps/api/tests/Contract/OpenApiParityTest.php` (5 casos)
- `docs/horus-academic/09-fase-2-implementacion.md` (este archivo)

**Modificados**
- `apps/api/composer.json`, `composer.lock`: `pestphp/pest ^3`, `pestphp/pest-plugin-laravel ^3` y `phpunit/phpunit` acotado a `>=11.5.50 <11.5.57`.
- `apps/api/phpunit.xml`: dos suites nuevas (`Academic`, `Contract`); las existentes no cambian.
- `apps/api/app/Models/Estudiante.php`: relación `user()`; `$hidden = ['user_id']`; `user_id` **no** es asignable en masa.
- `apps/api/app/Models/User.php`: relación `estudiante()`.
- `packages/contracts/openapi/horus-panel-api.yaml`: `matriculas.ver|crear|editar` → forma completa de los permisos (la prueba de paridad lo detectó).
- `docs/horus-academic/02`, `07`, `08`, `AGENTS.md`.

## 3. Comportamiento implementado

- **`estudiantes.user_id`:** nulo por defecto, FK a `users` con `ON DELETE SET NULL`, índice único. Eliminar una cuenta no falla
  y conserva el expediente (desvío deliberado del `RESTRICT` general del diseño: mantiene el flujo vigente de Usuarios).
- **Ninguna superficie nueva:** ni portal, ni endpoint de estudiante, ni acción de vincular. La API de estudiantes ignora
  `user_id` en altas y ediciones y no lo devuelve. **El mecanismo para vincular una cuenta queda sin implementar a propósito**
  (decisión D17).
- **SQLite:** `ALTER TABLE … ADD COLUMN` en bruto (no reconstruye la tabla ni pierde disparadores), igual que `000057`.

## 4. Resultados de pruebas (SQLite en memoria)

| Medición | Resultado |
| --- | --- |
| Pest nuevo (`tests/Academic`, `tests/Contract`) | **43 aprobadas, 337 aserciones** |
| Suite completa (`php artisan test`, 596 s) | **4.010 aprobadas, 2 omitidas, 20 fallidas** |
| PHPStan nivel 6 | Sin errores nuevos: 9 previos en `Support/Panel/Listado.php` + 2 por el controlador de Configuración borrado |
| Pint sobre archivos propios | Aprobado (los finales de línea de `Estudiante`/`User` ya eran así) |

> **No comparable con la línea base de 4.012.** Esa cifra se midió con los 38 cambios pendientes del propietario presentes en el
> árbol; hoy el árbol es `HEAD` más los borrados de Configuración y los cambios de esta fase. La referencia válida es esta medición.

### Las 20 fallidas (ninguna depende de los cambios de la Fase 2)

| N.º | Causa | Detalle |
| --- | --- | --- |
| 19 | **Módulo de Configuración descartado** | `routes/web.php` de `HEAD` (líneas 135–136) sigue enrutando a `Admin\ConfiguracionController`, borrado en el índice por tu decisión. Las pruebas que visitan esas rutas reciben 500: `ConfiguracionEfectivaTest` (3), `SecretsAndExportsTest` (4), `OldInputRenderingTest` (5), `AccesibilidadPanelTest` (3), `AdminAuthorizationTest` (2), `PaginasErrorTest` (1), `AuditoriaRobustezPanelFase2Test` (1). |
| 1 | **Runner de Pest** | `MatriculaNotificationBacklogTest::test_backlog_migration…` usa `#[RunInSeparateProcess]`; con Pest falla (`Class "PHPUnit\TextUI\Configuration\Registry" not found`) y con `vendor/bin/phpunit --testsuite=Feature` pasa (10/10). |

## 5. Compatibilidad comprobada

- Las 3.967 pruebas existentes (4.010 − 43 nuevas) que no dependen de Configuración siguen aprobando bajo el runner de Pest.
- `vendor/bin/phpunit` sigue funcionando para `--testsuite=Unit|Feature` y para `phpunit.pgsql*.xml` (que `PostgresTestPreflight` y
  `scripts/consolidacion-postgres.php` invocan): no listan directorios Pest. **No se ejecutó ninguno de esos perfiles** (requieren PostgreSQL).
  Falla con `InvalidPestCommand` si carga `tests/Academic` o `tests/Contract`.
- Contratos existentes de estudiantes, matrículas y convocatorias sin cambios (cubiertos por las suites existentes y la matriz).

## 6. Hallazgos

1. **Pest y la versión de PHPUnit:** Pest 3.8.7 declara conflicto con `phpunit/phpunit > 11.5.56`; se bajó PHPUnit de 11.5.57 a
   11.5.56 (parche, dentro de tu rango) y se actualizaron solo dependencias transitivas de Pest (`symfony/*`, `termwind`). No se
   tocó `laravel/framework`. `composer audit` informa **9 avisos de seguridad en 6 paquetes** (anteriores a esta fase; sin revisar).
2. **404 antes que 403 en rutas con identificador:** el *route model binding* se resuelve antes del middleware de permisos de
   los controladores. Un usuario del panel sin permiso recibe 404 si el id no existe y 403 si existe, lo que revela existencia de
   registros (fuga menor, solo para personal autenticado). Comportamiento vigente; **no se cambió**. Decisión pendiente (D18).
3. **El contrato estaba desfasado:** la prueba de paridad detectó una abreviatura de permisos y confirmó que `convocatorias`/
   `calificaciones` ya estaban corregidas. Hoy el contrato y `routes/panel.php` coinciden para `auth/` y `academico/`.
4. **Artefactos generados que yo había borrado:** en la sesión anterior borré `apps/api/public/build` y `apps/api/node_modules`
   (ignorados por Git, no restaurados por el reinicio del árbol); 167 pruebas fallaban por `Vite manifest not found`. Los regeneré
   con `npm ci` (lockfile existente, 97 paquetes, sin añadir dependencias) y `npm run build`. Siguen sin versionarse.
5. **Sin restos de mi trabajo de Blade/Vite de la sesión anterior:** el árbol de `app/`, `routes/` y `resources/` coincide con `HEAD`
   salvo lo listado en §2 y los borrados de Configuración.

## 7. Estado de Git

Rama `monorepo/base`, `HEAD ce80f8a`, índice con **solo** los dos borrados de Configuración (tuyos, sin tocar), sin commits nuevos, stashes intactos (2).
Modificados: `AGENTS.md`, `Estudiante.php`, `User.php`, `composer.json`, `composer.lock`, `phpunit.xml`, `horus-panel-api.yaml`.
Sin seguimiento: migración `000061`, `tests/Pest.php`, `tests/Academic/`, `tests/Contract/`, `docs/horus-academic/`.
Los cambios de la Fase 1 siguen intactos.

## 8. Decisiones y riesgos pendientes

| # | Asunto | Recomendación |
| --- | --- | --- |
| D17 | ¿Quién puede vincular una cuenta con un expediente y con qué condiciones (¿solo cuentas sin rol?, ¿coincidencia de correo?) | Posponer hasta autorizar el portal; no inventar la regla. |
| D18 | 404 antes que 403 por *route model binding* | Mover la comprobación de permiso antes del binding (middleware de ruta) en una fase de endurecimiento; es un cambio transversal. |
| D19 | Limpiar lo que quedó apuntando al módulo de Configuración descartado: rutas de `web.php` (135–136), enlaces en la barra lateral Blade y ~7 archivos de prueba | Es la continuación natural de tu decisión, pero toca rutas públicas, vistas y retira pruebas: lo hago solo con tu confirmación. Hasta entonces `/admin/configuracion` responde 500. |
| D20 | Prueba con `#[RunInSeparateProcess]` bajo Pest | Reescribirla con un subproceso explícito (como hacen los scripts PG) o ejecutarla por la suite PHPUnit; elijo la primera si lo autorizas. |
| D21 | ¿Seguir exigiendo `php artisan test` como comando estándar? | Sí (corre PHPUnit y Pest); documentar que `vendor/bin/phpunit` es solo para suites PHPUnit y perfiles PG. |
| R1 | Migración `000061` no probada en PostgreSQL | La prueba real exige tu autorización del clúster aislado `55433`. |
| R2 | `composer audit`: 9 avisos en 6 paquetes | Revisar antes de desplegar; no se tocaron versiones. |
| R3 | Decisiones D1–D16 de la Fase 1 siguen abiertas | Bloquean sobre todo finanzas (D1, D2, D6–D9) y certificados (D10, D11). |

## 9. Próximo incremento recomendado — Fase 3, primer módulo

**Infraestructura académica** (`sedes`, `aulas`, `plataformas_virtuales`) **+ asignación a ediciones**, porque desbloquea todo lo
demás (sesiones, asistencia, la regla de modalidad) y casi no depende de decisiones abiertas. Orden: migraciones `000062–000066`
(docentes, períodos, infraestructura, columnas en `convocatorias` con `CHECK … NOT VALID`, permisos), servicios `Gestion*`,
controladores y contrato (pasando las rutas de `propuesto` a `vigente`), pruebas Pest. **Necesita de ti antes de empezar:**
D3 (modalidades heredadas), D4 (documento de docente), D5 (solape de períodos) y que resuelvas D19.
