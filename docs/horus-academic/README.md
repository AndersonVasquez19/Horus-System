# Horus Academic — Fase 1: diseño técnico y documentación

> **16/11/2026 · Fase 1 de 9 · solo documentación.** No se modificó código funcional, no se ejecutaron migraciones, no se
> escribió en PostgreSQL, no se instaló ninguna dependencia, no se retiró ningún módulo y no se hizo commit ni push.
> Archivos tocados: esta carpeta, `packages/contracts/openapi/horus-panel-api.yaml` y `AGENTS.md`.
> **Espera tu revisión y aprobación antes de iniciar la Fase 2.**

## Entregables

| # | Entregable | Documento |
| --- | --- | --- |
| 1 | ERD actualizado y coherente con el esquema existente | [01-erd.md](01-erd.md) |
| 2 | Diccionario de datos en español | [02-diccionario-de-datos.md](02-diccionario-de-datos.md) |
| 3 | Matriz de roles y permisos del panel único | [03-matriz-permisos.md](03-matriz-permisos.md) |
| 4 | Plan de normalización de docentes, sedes, aulas y plataformas | [04-normalizacion-infraestructura-docentes.md](04-normalizacion-infraestructura-docentes.md) |
| 5 | Diseño financiero completo | [05-diseno-financiero.md](05-diseno-financiero.md) |
| 6 | Contratos OpenAPI actualizados | [06-contratos-openapi.md](06-contratos-openapi.md) + `packages/contracts/openapi/horus-panel-api.yaml` (v1.3.0-propuesta) |
| 7 | Plan de pruebas Pest y convivencia con PHPUnit | [07-plan-pruebas.md](07-plan-pruebas.md) |
| 8 | Plan de migraciones no destructivas (y plan de retiro del panel de servicios) | [08-plan-migraciones.md](08-plan-migraciones.md) |
| 9 | Actualización de documentación técnica y `AGENTS.md` | `AGENTS.md` §2 «Autorización del 16/11/2026», §4, §5, §7 |
| 10 | **Fase 2 (implementación):** Pest, vínculo `estudiantes.user_id`, matriz de roles y paridad de contrato | [09-fase-2-implementacion.md](09-fase-2-implementacion.md) |

## Resumen del diseño

- **Se reutiliza lo existente:** `Convocatoria` = Edición, `Matricula`, `Evaluacion`/`Calificacion` (ya con pesos y acta
  cerrada), `AuditLog`. Se **añaden** 14 tablas nuevas (`docentes`, `convocatoria_docentes`, `periodos_academicos`, `sedes`,
  `aulas`, `plataformas_virtuales`, `sesiones_clase`, `asistencias`, `metodos_pago`, `obligaciones_pago`,
  `cuotas_obligacion`, `transacciones_pago`, `asignaciones_pago`, `certificados`) y 5 columnas nulas en tablas existentes.
- **Finanzas = libro mayor inmutable:** pago, abono, reverso compensatorio, saldo derivado, idempotencia y orden de
  bloqueo ampliado. `matriculas.monto_pagado` pasa a ser caché derivada.
- **Panel único:** se mantiene `panel.academico.acceder` como entrada; `panel.servicios.acceder` se retira en la Fase 7.
- **Seguridad:** enlaces virtuales cifrados y con permiso propio; separación registrar/confirmar/reversar; permisos por
  migración; contrato OpenAPI sin endpoints de estudiante.

## Hallazgos de la Fase 1 que cambian el plan (verificados en el código)

1. **El contrato OpenAPI estaba desfasado:** `convocatorias` y `calificaciones` existían en `routes/panel.php` pero no en
   `horus-panel-api.yaml`. Corregido y se propone una prueba de paridad contrato ↔ rutas.
2. **`monto_pagado` es escribible** desde `StoreMatriculaRequest`/`UpdateMatriculaRequest` y lo leen reportes, dashboard,
   Excel y acta: activar el libro financiero **cambia el contrato de matrículas** ([05](05-diseno-financiero.md) §9).
3. **`secuencias_codigo` es compartida:** nació con el CRM (migración `000049`) pero genera los códigos de convocatorias
   (`CodigoSecuencial`). Retirar el CRM sin cuidado rompería las ediciones.
4. **Las reglas de CHECK no tienen un patrón único en SQLite:** unas migraciones crean disparadores (`000030`, `000032`…) y
   otras solo validan en el servicio (`000039`, `000041`, `000056`). Las reglas financieras usarán disparadores en ambos motores.
5. **Matrículas heredadas sin edición:** `matriculas.convocatoria_id` es nullable; asistencia, certificado y pago exigirán edición.
6. **`docente` y `lugar` son texto libre por bloque de horario** y solo los leen 4 archivos; la normalización es de bajo
   acoplamiento pero necesita revisión humana de nombres.
7. **`apps/web` no tiene pruebas** y `shadcn/TanStack` no están instalados; Pest tampoco (solo su permiso en `composer.json`).

## Decisiones pendientes (necesito tu respuesta para cerrar el diseño)

| # | Decisión | Opciones / recomendación |
| --- | --- | --- |
| D1 | ¿Se crea el rol `tesoreria`? | Recomiendo **no** al inicio: `admin` confirma y reversa; se crea si hay un cajero distinto. |
| D2 | Doble control en pagos: ¿`registrar` y `confirmar` por personas distintas? | Recomiendo **sí**, con excepción de `admin`. |
| D3 | Modalidades heredadas `semipresencial` e `hibrido` | Recomiendo **conservarlas** como datos heredados sin exigencias de infraestructura y no permitir crear nuevas; reclasificar solo con tu decisión. |
| D4 | ¿Se acepta `documento` provisional (NULL) al normalizar docentes? | Recomiendo **no**: se completa antes de aplicar. |
| D5 | ¿Pueden solaparse los períodos académicos? | Recomiendo **no** (exclusión por rango). |
| D6 | Anular una matrícula con pagos netos > 0 | Recomiendo **exigir reverso previo**; la alternativa «saldo a favor» añade complejidad contable. |
| D7 | Descuentos, becas, mora e intereses, otras monedas | Recomiendo **fuera de la primera etapa** (solo `PEN`, monto fijo). |
| D8 | Métodos de pago reales y comprobante electrónico (SUNAT) | Necesito la lista de métodos y si el comprobante legal queda fuera del sistema. |
| D9 | Backfill de `monto_pagado` al libro | Recomiendo **sí**, con `--simular`, informe de diferencias y tu aprobación; requiere respaldo restaurable ensayado. |
| D10 | Certificados: umbral de asistencia mínima, firmantes y formato | Recomiendo umbral en `system_settings` (p. ej. 80 %) y PDF con la plantilla Blade-PDF existente. |
| D11 | Verificación pública de certificados: datos visibles | Recomiendo solo `{válido, estado, número, curso, fecha}` y nombre abreviado ([06](06-contratos-openapi.md) §4). |
| D12 | Alias `/academico/ediciones` y prefijo único del panel | Recomiendo **no cambiar** prefijos; solo la UI dice «Ediciones». |
| D13 | Pruebas de frontend: Vitest + Testing Library y Playwright | Requieren tu aprobación de dependencias. |
| D14 | Tras retirar el panel de servicios (F7): ¿qué pasa con `tecnologias/*`, el catálogo de servicios y la cotización pública? | Dependen de las tablas del CRM; el retiro del panel **no** los retira. Necesito tu decisión explícita. |
| D15 | Pedido anterior: «Laravel backend puro» (quitar Vite/Blade/frontend del backend) | Ver §Estado del árbol. Recomiendo tratarlo como parte de la **Fase 8** con inventario previo; los correos y PDF Blade se conservan como salida de backend. |
| D16 | Portal de estudiantes/docentes | Fuera de la primera etapa; el modelo ya deja el vínculo opcional y único. |

## Riesgos identificados

| Riesgo | Gravedad | Mitigación |
| --- | --- | --- |
| **Pérdida de trabajo local:** el árbol de trabajo volvió a `HEAD` (ver §Estado del árbol) | **Alta** | Revisar de inmediato; no se restauró nada. |
| Integridad financiera sin pruebas de concurrencia reales (SQLite ignora bloqueos) | Alta | Pruebas en el clúster aislado `55433`, con autorización; disparadores e invariantes en la base. |
| Backfill de importes históricos incorrecto | Alta | `--simular`, informe, aprobación, respaldo restaurable ensayado (hoy «sin ejecutar»). |
| Modificar `convocatorias` (tabla con datos y FK) en SQLite reconstruye la tabla y borra disparadores | Media | Regla 5 de [08](08-plan-migraciones.md); pruebas de instalación nueva y sembrada. |
| `btree_gist` no disponible en el clúster | Media | Verificar `CREATE EXTENSION` en el clúster aislado; alternativa de servicio + consulta de control. |
| Dependencias ocultas del CRM (`secuencias_codigo`, notificaciones, aprobaciones, auditoría) | Media | Mapa de dependencias antes de la Fase 7; tablas no se borran. |
| Pest 3 vs versión instalada de PHPUnit 11; Pest vs guardas PostgreSQL | Media | Probar en copia; pruebas PG como clases PHPUnit hasta demostrar lo contrario. |
| `apps/web` sin pruebas al introducir shadcn/TanStack | Media | Introducción progresiva; D13. |
| Documentación del repositorio desactualizada (`PROJECT_CONTEXT.md` 4.899 líneas, cabecera del 05/11) | Baja | Este directorio es la fuente vigente de Horus Academic; contrastar siempre con el código. |
| Datos personales de docentes (DNI, correo) | Media | Minimización, enmascarado en auditoría, permisos por detalle. |

## Estado del árbol de trabajo (importante)

- **Al iniciar esta conversación** había 38 entradas sin commit (trabajo de «Configuración» en `apps/api` y `apps/web`).
- **Durante la sesión** realicé, por una petición anterior tuya («Laravel backend puro»), cambios de prueba en `apps/api`
  (rutas JSON de búsqueda/respaldo/asistente, borrado de Blade/Vite, adaptación de ~25 pruebas). Esa petición **no estaba
  autorizada por la Fase 1** y la dejé en pausa al recibir tu mensaje.
- **Al comprobarlo antes de empezar la Fase 1**, `git status` mostraba solo 2 entradas (`D` indexadas de
  `Admin/ConfiguracionController.php` y su vista), `HEAD` sin cambios (`ce80f8a`), sin stash nuevo y los archivos de ambos
  conjuntos (los 38 pendientes y mis cambios) ya **no estaban en disco**. Yo no ejecuté ningún comando de restauración ni
  de limpieza. Si fuiste tú, puedes ignorar esto; si no, conviene revisar `git reflog`, la papelera de Windows y cualquier
  otra herramienta que haya tocado el repositorio.
- No hay nada de esta Fase 1 mezclado con código: solo los archivos listados arriba.
