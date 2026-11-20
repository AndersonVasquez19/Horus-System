# 08 · Plan de migraciones no destructivas

> **Fase 1 · diseño.** No se creó ni ejecutó ninguna migración. Última migración existente: `2026_11_15_000060`
> (55 archivos). Todas las propuestas son **aditivas** y numeradas a continuación de esa secuencia. Aplicar cualquiera a una
> base real exige autorización expresa y específica; PostgreSQL S7 (`55432`, base `horus_group`) sigue suspendido.

## 1. Reglas (todas obligatorias)

1. **Solo aditivas.** Crear tablas/columnas nuevas, nulas al inicio. Nunca `DROP`, `RENAME`, `ALTER TYPE` destructivo ni
   `DELETE` de datos en estas migraciones. Se **conservan** las 55 existentes y sus tablas heredadas.
2. **Una intención por migración** (esquema / permisos / datos van por separado), reversibles con `down()` que solo
   deshace lo que la propia migración creó.
3. **Permisos:** constante en `RolesAndPermissionsSeeder` **y** migración `add_<modulo>_permissions` (no basta el seeder).
4. **PostgreSQL primero:** `CHECK`, índices parciales, exclusión y disparadores solo bajo `DB::getDriverName() === 'pgsql'`;
   los `CHECK` sobre tablas con datos se añaden `NOT VALID` y se validan (`VALIDATE CONSTRAINT`) en una migración propia
   cuando el inventario demuestre cero violaciones. Equivalente en SQLite con disparadores para las reglas de integridad.
5. **SQLite y claves foráneas:** `Schema::table` con FK reconstruye la tabla en SQLite y borra sus disparadores
   (advertencia de `000057`); se usa `ALTER TABLE` en bruto o se reponen los disparadores, como hacen `000057` y `000060`.
6. **Probar dos veces:** instalación nueva **y** base ya sembrada (patrón `*MigracionTest` existente), en SQLite en memoria.
7. **Nunca** `migrate:fresh`, `db:wipe`, `key:generate` ni migraciones contra datos reales. Los comandos de datos tienen
   `--simular` por defecto.
8. **Backfills fuera del DDL:** comandos artisan idempotentes, con informe, ejecutados solo con autorización y respaldo
   restaurable ensayado (la restauración sigue «sin ejecutar» según `CLAUDE.md` §5).

## 2. Secuencia propuesta

| N.º | Fase | Migración (nombre) | Tipo | Contenido | Riesgo |
| --- | --- | --- | --- | --- | --- |
| 000061 | 2 | `add_user_id_to_estudiantes` | Esquema | `estudiantes.user_id` nullable, FK `users` **`ON DELETE SET NULL`**, índice único. **CREADA el 16/11/2026** (`2026_11_16_000061_add_user_id_to_estudiantes_table.php`), probada en SQLite en memoria; **no ejecutada** contra ninguna base real ni contra PostgreSQL. | Bajo (columna nula). SQLite: `ALTER TABLE … ADD COLUMN`, sin reconstruir la tabla. |
| 000062 | 3 | `create_docentes_table` | Esquema | `docentes` (§02). | Bajo |
| 000063 | 3 | `create_periodos_academicos_table` | Esquema | `periodos_academicos`, `convocatorias.periodo_academico_id` nulo. | Bajo |
| 000064 | 3 | `create_sedes_aulas_plataformas_tables` | Esquema | `sedes`, `aulas`, `plataformas_virtuales`. | Bajo |
| 000065 | 3 | `add_infraestructura_to_convocatorias` | Esquema | `sede_id`, `aula_id`, `plataforma_virtual_id` nulas; `convocatoria_docentes`; `CHECK` de modalidad `NOT VALID` (pgsql). | **Medio**: toca `convocatorias`, tabla con datos y con FK; SQLite reconstruye la tabla. |
| 000066 | 3 | `add_docentes_infraestructura_periodos_permissions` | Permisos | Matriz [03](03-matriz-permisos.md) §5. | Bajo |
| 000067 | 4 | `create_sesiones_clase_table` | Esquema | `sesiones_clase`; exclusión por aula y docente (pgsql, requiere `btree_gist`). | Medio (extensión) |
| 000068 | 4 | `create_asistencias_table` | Esquema | `asistencias`. | Bajo |
| 000069 | 4 | `add_sesiones_asistencias_permissions` | Permisos | Incluye `sesiones.ver-enlace`. | Bajo |
| 000070 | 4 | `add_ponderacion_evaluaciones_guard` | Esquema | *Constraint trigger* diferido de suma de pesos ≤ 100 (pgsql) + equivalente SQLite. **No** modifica filas. | Medio: si hoy hay ediciones con suma > 100, el disparador solo actúa en escrituras futuras (inventario previo). |
| 000071 | 5 | `create_finanzas_tables` | Esquema | `metodos_pago`, `obligaciones_pago`, `cuotas_obligacion`, `transacciones_pago`, `asignaciones_pago` con `CHECK`. | Medio |
| 000072 | 5 | `add_finanzas_inmutabilidad_triggers` | Esquema | Disparadores de inmutabilidad (pgsql y SQLite). | Medio |
| 000073 | 5 | `add_pagos_permissions` | Permisos | Matriz §5. | Bajo |
| 000074 | 6 | `create_certificados_table` | Esquema | `certificados`. | Bajo |
| 000075 | 6 | `add_certificados_permissions` | Permisos | | Bajo |

> Los **números reales se asignan al implementar** (otros cambios pueden intercalarse). Lo vinculante es el orden y la
> regla de agrupar los permisos con su módulo (no se conceden permisos de módulos que aún no existen).

## 3. Comandos de datos (no son migraciones; cada uno con autorización específica)

| Comando | Fase | Función | Modo seguro |
| --- | --- | --- | --- |
| `horus:inventario-academico` | 3 | Ejecuta las consultas de §04 (solo lectura) y escribe un informe fuera del repositorio. | Solo lectura |
| `horus:normalizar-infraestructura` | 3 | Docentes/sedes/aulas/plataformas desde textos heredados con mapeo humano. | `--simular` por defecto |
| `horus:validar-restricciones` | 3–5 | `VALIDATE CONSTRAINT` de los `CHECK … NOT VALID` cuando el inventario da cero violaciones. | Informa antes de validar |
| `horus:saldo-inicial-pagos` | 5 | Backfill del libro desde `matriculas.monto_pagado` ([05](05-diseno-financiero.md) §9). | `--simular` por defecto, informe de diferencias |
| `horus:verificar-finanzas` | 5 | Recalcula saldos y detecta desvíos. | Solo lectura |
| `horus:asignar-edicion-a-matriculas` | 4 | Propone edición para matrículas heredadas sin `convocatoria_id`. | `--simular`, revisión humana |

## 4. Matrículas heredadas sin edición

`matriculas.convocatoria_id` es nullable (migración `000057`) y hay matrículas por curso anteriores a las ediciones. Estrategia:

1. Inventario (`COUNT … WHERE convocatoria_id IS NULL`).
2. Sin tocar nada: asistencia, certificado y obligación de pago **exigen** edición; la UI ofrece «Asignar edición» a esas matrículas.
3. Asignación solo manual o con propuesta aprobada (no se adivina la edición); el cupo de la edición elegida se valida con el protocolo de bloqueo vigente.
4. Hasta decidir lo contrario las matrículas heredadas siguen siendo válidas para lo ya existente (reportes, acta de curso).

## 5. Retiro del panel de servicios tecnológicos (Fase 7) — plan, no ejecución

Autorizado por el propietario **para la Fase 7, después de comprobar el panel académico y la intactitud de lo compartido**.

| Se retira (código) | Se **conserva** (no se toca) |
| --- | --- |
| `routes/panel.php` grupo `servicios`; controladores `Api/Panel/Servicios/*` (10); servicios `Gestion{Clientes,Prospectos,Oportunidades,Proyectos,Soporte,Cotizaciones,ServiciosTecnologicos}`, `ReportesComerciales`, `ReporteComercialExcel`; páginas `apps/web/src/app/(admin)/admin/servicios/**` y componentes `components/servicios/**`; permiso `panel.servicios.acceder` (dejar de concederlo). | Libro de Reclamaciones; autenticación; usuarios y roles; auditoría; notificaciones compartidas; `AprobacionInterna` y `NotificacionInterna` (**hasta verificar dependencias**); chatbot Horus AI; web pública; catálogo público de cursos; todo `app/Http/Controllers/Api/Panel/Compartido/*`. |

Reglas del retiro:
1. **Antes**, mapa de dependencias real: qué usa `Compartido/*`, notificaciones y aprobaciones de las clases de servicios
   (p. ej. enlaces `vinculo` guardados en `notificaciones_internas`, tipos de aprobación, `AuditLog` con `model_type` de servicios).
2. Las **tablas** (`servicios_tecnologicos`, `servicio_publicaciones`, `solicitudes_cotizacion`, `cotizacion_historial`,
   `clientes`, `cliente_contactos`, `prospectos`, `oportunidades`, `oportunidad_historial`, `proyectos`, `proyecto_hitos`,
   `tickets_soporte`, `ticket_notas`) **no se eliminan** en esta modernización: se retira el código y quedan archivadas.
   Eliminarlas es una decisión posterior, con respaldo restaurable ensayado y migración propia.
   **Trampa verificada:** `secuencias_codigo` se creó en la migración de oportunidades (`000049`) pero también genera los
   códigos `CNV-AAAA-NNNN` de `convocatorias` (comentario de `000056`): es **compartida y no se retira nunca** con el CRM.
   Debe auditarse el mismo tipo de dependencia oculta para cualquier otra tabla o servicio antes de retirar nada.
3. La **web pública `tecnologias/*`**, el catálogo de servicios y el formulario de cotización pública dependen de esas
   tablas: el retiro del panel **no** implica retirarlos; hacerlo es una decisión aparte del propietario (README §Pendientes).
4. Pruebas de servicios: se retiran junto al código que cubren, **una por una y con registro**, nunca para «poner verde» la
   suite (`CLAUDE.md`/prompt maestro). La línea base de 4.012 pruebas baja solo por pruebas de código retirado.

## 6. Plan de verificación por migración

Para cada migración: (1) `php artisan test --filter=<MigracionTest>` en SQLite; (2) instalación nueva y base sembrada;
(3) `vendor/bin/phpstan analyse`; (4) `git diff --check`; (5) **si y solo si** hay autorización, el equivalente en el clúster
aislado `55433` con `php scripts/pgsql-test.php probe` antes de cualquier escritura. Nunca se declara una migración
«validada en PostgreSQL» por haber pasado en SQLite.
