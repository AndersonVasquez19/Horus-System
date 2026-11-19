# 07 · Plan de pruebas Pest y convivencia con PHPUnit

> **Fase 1 · diseño.** No se instaló Pest ni ninguna dependencia (la instalación es Fase 2). Línea base medida el
> 16/11/2026 sobre `HEAD ce80f8a` con SQLite en memoria: **4.012 pruebas aprobadas, 2 omitidas, 25.180 aserciones**
> (574 s). Esa evidencia **no** prueba PostgreSQL (bloqueos, DDL, concurrencia), Horus AI real, correo real ni despliegue.

## 1. Estado actual verificado

- PHPUnit 11 (`^11.5.50`), `phpunit.xml` con dos suites (`Unit`, `Feature`), SQLite `:memory:`, sesión/caché `array`, cola
  `sync`, correo `array`, servicios externos apagados con `force="true"`.
- ~115 archivos en `tests/Feature` (incluye `tests/Feature/Panel/*` con la base `PanelApiTestCase`), más `tests/Unit`,
  `tests/PostgresIntegrity` (30+ clases para el clúster aislado `55433`), `tests/ConsolidacionPostgres`, `tests/Windows`.
- Salvaguardas que **no se debilitan**: `TestCase::assertIsolatedTestDatabase()`, `app/Support/Testing/*`,
  `config/pgsql-testing-database.php`, `phpunit.pgsql*.xml`, `scripts/pgsql-test.php`.
- `composer.json` ya contiene `config.allow-plugins.pestphp/pest-plugin = true` pero **Pest no está instalado**
  (`vendor/bin` no tiene `pest`).

## 2. Estrategia de convivencia (autorizada, con condiciones del propietario)

| Condición | Cómo se cumple |
| --- | --- |
| No eliminar PHPUnit ni sus ~115 archivos | Pest **ejecuta** clases PHPUnit tal cual. **Verificado en la Fase 2:** `php artisan test` y `vendor/bin/pest` ejecutan todo; `vendor/bin/phpunit` sigue sirviendo para las suites PHPUnit (`--testsuite=Unit|Feature`) y para los perfiles `phpunit.pgsql*.xml` (no listan directorios Pest), pero **falla con `InvalidPestCommand`** si carga `tests/Academic` o `tests/Contract` (o la configuración por defecto con las cuatro suites). Una prueba con `#[RunInSeparateProcess]` (`MatriculaNotificationBacklogTest`) falla bajo el runner de Pest y pasa con `phpunit --testsuite=Feature`. |
| No reescribir masivamente | Ningún archivo existente se convierte «por convertir». Se migra uno solo cuando se toca por otra razón y con revisión. |
| Compatibilidad | Pest 3.x trabaja sobre PHPUnit 11. **Pest 4 exige PHPUnit 12**: no se actualiza PHPUnit. **Verificado en la Fase 2 (16/11/2026):** Pest 3.8.7 declara conflicto con `phpunit/phpunit > 11.5.56`; se instaló bajando PHPUnit de 11.5.57 a **11.5.56** (`composer.json`: `"phpunit/phpunit": ">=11.5.50 <11.5.57"`), sin actualizar `laravel/framework`. Hay que revisar ese tope cuando salga una versión de Pest 3 que lo levante. |
| Pest para lo nuevo | Pruebas de los módulos nuevos en `tests/Academic/**` (Pest). |
| Migrar progresivamente | Regla: solo al **modificar** una prueba existente de forma sustancial se evalúa su paso a Pest. |

### Estructura propuesta

```
apps/api/tests/
  Pest.php                       # extiende TestCase solo para tests/Academic y tests/Contract
  Academic/
    Docentes/ Infraestructura/ Periodos/ Sesiones/ Asistencias/ Pagos/ Certificados/
    Concerns/                    # fábricas y helpers (usuario con rol, edición con infraestructura…)
  Contract/                      # paridad OpenAPI ↔ rutas (ver §5)
  Feature/ Unit/ …               # PHPUnit existente, intacto
```

`tests/Pest.php` (diseño, no creado todavía):

```php
pest()->extend(Tests\TestCase::class)->use(Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Academic');
pest()->extend(Tests\TestCase::class)->in('Contract');
// beforeEach(fn () => $this->assertIsolatedTestDatabase());   // misma salvaguarda que PHPUnit
```

Cambios que traerá la instalación (Fase 2, con tu autorización de dependencias ya concedida para Pest):
`composer require --dev pestphp/pest:^3 pestphp/pest-plugin-laravel:^3`, `tests/Pest.php`, y añadir una suite
`<testsuite name="Academic">` (y `Contract`) a `phpunit.xml` **sin tocar** las suites existentes. Se verifica que el
resultado de la suite completa sigue siendo ≥ 4.012 aprobadas antes y después de instalar.

Los helpers de panel existentes (`PanelApiTestCase`, `Tests\Support\Llama*DelPanel`) son clases/traits PHPUnit: Pest los
usa con `uses(PanelApiTestCase::class)`; no se duplican.

## 3. Qué se prueba, módulo por módulo (mínimo de aceptación por fase)

| Fase | Módulo | Casos obligatorios |
| --- | --- | --- |
| 2 | Identidad | Registro sin roles; vínculo `estudiantes.user_id` único y nulo por defecto; `AdminProtection` intacto; permisos nuevos: equivalencia seeder↔migración, `down()` no borra permisos ajenos; matriz 3 roles × cada acción (403/200). |
| 3 | Docentes, infraestructura, períodos | CRUD + validación en español; `documento` único y normalizado; sin borrado con ediciones activas; modalidad↔infraestructura (CHECK en PostgreSQL, disparador en SQLite, servicio en ambos); aula de otra sede rechazada; capacidad de aula ≥ cupos; períodos válidos; datos personales minimizados y enmascarados en auditoría. |
| 4 | Sesiones | Sin cruce de aula ni de docente (incl. límites exactos de hora); enlace virtual **cifrado en reposo** (se lee la columna cruda y no coincide); el enlace no aparece en ninguna respuesta salvo `…/enlace` con permiso; `…/enlace` audita sin guardar el enlace; sesión cancelada exige motivo. |
| 4 | Asistencia | Solo matrículas vigentes de la misma edición; idempotente por (sesión, matrícula); corrección exige motivo y audita; sesión cancelada no admite asistencia; porcentaje correcto. |
| 4 | Evaluaciones/calificaciones | **Suma de pesos ≤ 100** (101, 100,00, 99,99 + 0,01, concurrencia en PostgreSQL); nota fuera de rango; corregir exige motivo; acta cerrada inmutable. (Las pruebas actuales de calificaciones se conservan.) |
| 4 | Matrículas y cupos | Se conservan `MatriculaOperationsTest`, `MatriculaRulesPostgresTest` y las de concurrencia; se añaden casos con edición, sede/aula y capacidad. |
| 5 | Finanzas | Invariantes F1–F10 ([05](05-diseno-financiero.md) §3): ver §4. |
| 6 | Certificados | Solo con matrícula aprobada, acta cerrada y asistencia mínima; **uno vigente por matrícula**; código aleatorio no derivable; PDF con huella; anular exige motivo y no borra; verificación pública no filtra datos; descarga audita. |
| 6 | Reportes | Los reportes existentes devuelven los mismos totales antes/después de activar el libro (prueba de equivalencia durante la convivencia). |
| 7 | Frontend | Ver §6. |

## 4. Pruebas de integridad financiera y concurrencia

**SQLite (rápidas, en cada ejecución):** invariantes de cálculo (BCMath, redondeo a la primera cuota), máquina de estados,
sobrepago rechazado, idempotencia secuencial, reverso compensatorio con asignaciones negativas, doble reverso rechazado,
reverso de reverso rechazado, inmutabilidad por disparador, `monto_pagado` = neto del libro, verificación `horus:verificar-finanzas`
sin desvíos. Propiedad: para N operaciones aleatorias con semilla fija, F1–F4 se cumplen siempre.

**PostgreSQL aislado (`tests/PostgresIntegrity`, solo con autorización explícita y perfil `HORUS_PG_TEST_PROFILE`):**
- dos cobros simultáneos del último saldo → exactamente uno confirma;
- dos reversos simultáneos del mismo pago → exactamente uno;
- mismo `Idempotency-Key` en paralelo → una sola transacción;
- cobro concurrente con anulación de la matrícula → sin saldo negativo ni obligación huérfana;
- matrículas concurrentes por el último cupo con edición y aula (extiende `AcademicoCuposPostgresTest`);
- ponderaciones de evaluación concurrentes sin superar 100;
- `CHECK`, disparadores y `EXCLUDE` rechazan lo que el servicio ya filtraba (prueba de «defensa en profundidad»);
- los disparadores de inmutabilidad rechazan `UPDATE`/`DELETE` ejecutados por SQL directo.

Los archivos nuevos de este perfil se escriben **como clases PHPUnit** que reutilizan los *concerns* existentes
(`tests/PostgresIntegrity/Concerns`) hasta verificar que Pest respeta `RequiresIsolatedPostgres` y
`TestDatabaseSafety` (riesgo en §7). Nunca se cambian puertos, nombres ni salvaguardas para que una prueba «pase».

## 5. Pruebas de contrato

`tests/Contract/OpenApiParityTest` (Pest): carga `packages/contracts/openapi/horus-panel-api.yaml` y comprueba que
(a) toda ruta `x-estado: vigente` existe en el enrutador con el mismo método, (b) toda ruta de `routes/panel.php` figura en el
contrato, (c) el permiso declarado en la descripción coincide con el middleware del controlador. Usa `symfony/yaml`, que ya está en `vendor/` como
dependencia transitiva de Laravel (verificado 16/11/2026); si dejara de estarlo, añadirlo requiere aprobación. Hoy (a)/(b) **fallarían** para convocatorias y calificaciones antes de la actualización de esta fase.

## 6. Frontend y E2E

`apps/web` **no tiene ningún framework de pruebas** (solo `eslint` y un script `verify:assets`). Hasta ahora el frontend se
valida con `npm run build`/lint. Propuesta (**requiere tu aprobación de dependencias, no autorizada todavía**):

| Herramienta | Uso | Estado |
| --- | --- | --- |
| Vitest + Testing Library | Lógica de formularios Zod, utilidades de `lib/panel`, componentes críticos | Pendiente de aprobación |
| Playwright | Un E2E por flujo crítico (login, matrícula, pago + reverso, certificado) contra servidor local con base desechable | Pendiente de aprobación |

Mientras tanto: `npm run lint`, `npm run build` y las pruebas de contrato del BFF desde PHP.

## 7. Riesgos de la estrategia

| Riesgo | Mitigación |
| --- | --- |
| Pest 3 y la versión exacta de PHPUnit 11 instalada no coinciden | Probar en una rama/copia: suite completa debe dar ≥ 4.012 aprobadas y ≤ 2 omitidas. |
| Pest + perfil PostgreSQL aislado no respeta las guardas | Mantener esas pruebas como clases PHPUnit hasta demostrar lo contrario con una prueba de «guarda activa». |
| Dos estilos de prueba confunden al equipo | Regla escrita en `AGENTS.md`: lo nuevo en Pest dentro de `tests/Academic`; lo antiguo no se convierte en bloque. |
| La suite tarda ~10 min | Las nuevas pruebas usan fábricas mínimas; `--parallel` queda **fuera** de alcance (SQLite en memoria y salvaguardas lo desaconsejan sin análisis). |
| Evidencia sobrestimada | Cada informe de fase separa «SQLite en memoria» de «PostgreSQL aislado» y de «no medido». |
