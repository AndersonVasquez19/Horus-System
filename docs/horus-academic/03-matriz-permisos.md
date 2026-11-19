# 03 · Matriz de roles y permisos del panel académico único

> **Fase 1 · diseño.** Los permisos existentes se verificaron en `RolesAndPermissionsSeeder` (HEAD `ce80f8a`). Los nuevos
> son propuestas. Regla vigente del repositorio (`AGENTS.md`/`CLAUDE.md`): **todo permiso nuevo exige constante en el
> seeder _y_ una migración propia** que lo cree y lo conceda sin resincronizar el resto de roles (el seeder resincroniza y
> descartaría permisos concedidos a mano). La autorización se valida siempre en el backend (middleware `permission:` por
> acción); ocultar botones en Next.js no es autorización.

## 1. Entrada al panel único

| Permiso | Hoy | Propuesta |
| --- | --- | --- |
| `panel.academico.acceder` | Entrada a `/admin/academico` | **Se mantiene como única entrada** al panel. No se renombra (rompería sesiones, BFF y migración `000042`). La UI deja de hablar de «académico» cuando exista un único panel. |
| `panel.servicios.acceder` | Entrada a `/admin/servicios` (solo admin) | Se **conserva sin cambios hasta la Fase 7**; entonces deja de concederse. El permiso se retira con una migración propia, sin borrar roles. |

## 2. Roles

| Rol | Estado | Uso |
| --- | --- | --- |
| `admin` | Existente. No se renombra ni elimina (`AdminProtection`). Mantiene `CRITICAL_PERMISSIONS`. | Gerencia y soporte. |
| `coordinador` | Existente | Operación académica completa; sin finanzas sensibles. |
| `secretaria` | Existente | Atención: altas, matrículas, asistencia y cobros. |
| `tesoreria` | **Propuesto, opcional** (decisión pendiente) | Cobros, confirmación y conciliación; sin acceso a notas ni a roles. Si no se aprueba, `admin` conserva `confirmar/reversar/exportar`. |
| `docente` | **No se crea** | El portal de docentes/estudiantes no se habilita en la primera etapa. Docentes y estudiantes no inician sesión. |

## 3. Permisos existentes (resumen, sin cambios)

`estudiantes.{ver,crear,editar,eliminar,restaurar}` · `cursos.{ver,crear,editar,eliminar,restaurar,publicar}` ·
`matriculas.{ver,crear,editar,anular,restaurar,exportar,eliminar}` · `convocatorias.{ver,crear,editar,cerrar}` ·
`calificaciones.{ver,registrar,corregir,exportar}` · `reportes.{ver,exportar.excel,exportar.pdf}` ·
`usuarios.*`, `roles.*`, `auditoria.{ver,exportar}`, `configuracion.{ver,editar}`, `aprobaciones.{ver,gestionar}`,
`reclamaciones.{ver,gestionar,responder}`, `asistente.ver`, `respaldo.{ver,exportar}`.

## 4. Permisos nuevos propuestos

| Módulo | Permisos |
| --- | --- |
| Docentes | `docentes.ver`, `docentes.crear`, `docentes.editar`, `docentes.eliminar`, `docentes.restaurar` |
| Infraestructura (sedes, aulas, plataformas) | `infraestructura.ver`, `infraestructura.gestionar` |
| Períodos | `periodos.ver`, `periodos.gestionar` (crear, editar, cerrar) |
| Sesiones | `sesiones.ver`, `sesiones.crear`, `sesiones.editar`, `sesiones.cancelar`, `sesiones.ver-enlace` |
| Asistencias | `asistencias.ver`, `asistencias.registrar`, `asistencias.corregir`, `asistencias.exportar` |
| Pagos | `pagos.ver`, `pagos.planificar`, `pagos.registrar`, `pagos.confirmar`, `pagos.reversar`, `pagos.exportar` |
| Certificados | `certificados.ver`, `certificados.emitir`, `certificados.anular`, `certificados.descargar` |

`sesiones.ver-enlace` es aparte de `sesiones.ver` a propósito: el enlace privado de una clase virtual no se entrega a
quien solo consulta el calendario.

## 5. Matriz por rol

`●` concedido · `○` no concedido · `◐` concedido con límite indicado. **Admin recibe todos** (como hoy).

| Permiso | admin | coordinador | secretaria | tesoreria (si se aprueba) |
| --- | :-: | :-: | :-: | :-: |
| `panel.academico.acceder` | ● | ● | ● | ● |
| `estudiantes.ver/crear` | ● | ● | ● | ○ (`ver` ●) |
| `estudiantes.editar` | ● | ● | ○ | ○ |
| `estudiantes.eliminar/restaurar` | ● | ○ | ○ | ○ |
| `cursos.ver` | ● | ● | ● | ○ |
| `cursos.crear/editar` | ● | ● | ○ | ○ |
| `cursos.eliminar/restaurar/publicar` | ● | ○ | ○ | ○ |
| `convocatorias.ver` | ● | ● | ● | ○ |
| `convocatorias.crear/editar/cerrar` | ● | ● | ○ | ○ |
| `matriculas.ver/crear` | ● | ● | ● | ◐ solo `ver` |
| `matriculas.editar/restaurar` | ● | ● | ○ | ○ |
| `matriculas.anular/exportar/eliminar` | ● | ○ | ○ | ○ |
| `docentes.ver` | ● | ● | ● | ○ |
| `docentes.crear/editar` | ● | ● | ○ | ○ |
| `docentes.eliminar/restaurar` | ● | ○ | ○ | ○ |
| `infraestructura.ver` | ● | ● | ● | ○ |
| `infraestructura.gestionar` | ● | ● | ○ | ○ |
| `periodos.ver` | ● | ● | ● | ○ |
| `periodos.gestionar` | ● | ● | ○ | ○ |
| `sesiones.ver` | ● | ● | ● | ○ |
| `sesiones.crear/editar/cancelar` | ● | ● | ○ | ○ |
| `sesiones.ver-enlace` | ● | ● | ● (necesita el enlace para atender consultas) | ○ |
| `asistencias.ver` | ● | ● | ● | ○ |
| `asistencias.registrar` | ● | ● | ● | ○ |
| `asistencias.corregir/exportar` | ● | ● | ○ | ○ |
| `calificaciones.ver/registrar` | ● | ● | ● | ○ |
| `calificaciones.corregir/exportar` | ● | ● | ○ | ○ |
| `pagos.ver` | ● | ● | ● | ● |
| `pagos.planificar` | ● | ● | ○ | ● |
| `pagos.registrar` | ● | ○ | ● | ● |
| `pagos.confirmar` | ● | ○ | ○ | ● |
| `pagos.reversar` | ● | ○ | ○ | ○ (solo admin) |
| `pagos.exportar` | ● | ○ | ○ | ● |
| `certificados.ver/descargar` | ● | ● | ● | ○ |
| `certificados.emitir` | ● | ● | ○ | ○ |
| `certificados.anular` | ● | ○ | ○ | ○ |
| `reportes.ver` | ● | ● | ● | ◐ solo `ver` |
| `reportes.exportar.*` | ● | ● | ○ | ○ |
| `usuarios.*`, `roles.*`, `configuracion.*`, `respaldo.exportar`, `auditoria.*` | ● | ○ | ○ | ○ |
| `aprobaciones.ver` | ● | ● | ● | ○ |
| `reclamaciones.*` | ● | ○ | ○ | ○ |

### Principios de la matriz

1. **Separación de funciones en dinero:** quien *registra* un cobro (secretaria) no lo *confirma* ni lo *reversa*. El
   servicio impide además que `registrado_por = confirmado_por` salvo `admin` (decisión pendiente: doble control).
2. **Lo destructivo y lo exportable son de `admin`** por defecto, igual que `matriculas.exportar/eliminar` y
   `cursos.publicar` hoy. `cursos.editar` no concede publicar; `calificaciones.corregir` no concede `pagos.*`.
3. **Mínimo privilegio en datos personales:** listados de docentes y estudiantes minimizados; el DNI solo en el detalle.
4. **Admin protegido:** `AdminProtection::runExclusively()` envuelve cualquier operación sobre administradores; el rol
   `admin` conserva `CRITICAL_PERMISSIONS` (se ampliará con `pagos.reversar` y `certificados.anular`).

## 6. Auditoría (acciones nuevas en `audit_logs.action`)

`docente_creado|editado|eliminado|restaurado`, `sede_*`, `aula_*`, `plataforma_*`, `periodo_*`,
`sesion_creada|editada|cancelada`, `sesion_enlace_visto` (sin el enlace), `asistencia_registrada|corregida`,
`obligacion_creada`, `cuota_*`, `pago_registrado|confirmado|rechazado|reversado`, `certificado_emitido|anulado|descargado`.
`changes` pasa por `AuditLog::redactarCambios()` (secretos y enlaces enmascarados) y nunca contiene importes de otras
personas ni datos de tarjeta. El propio libro financiero es el historial primario; `audit_logs` registra quién y desde dónde.

## 7. Migraciones de permisos (una por módulo, no destructivas)

Cada módulo trae su migración `add_<modulo>_permissions` con `firstOrCreate` del permiso y `givePermissionTo` a los roles
de la tabla anterior (patrón de `000055`/`000058`), más su constante en el seeder y una prueba de equivalencia
seeder↔migración (patrón de `ConvocatoriasMigracionTest`). `down()` solo elimina los permisos que la propia migración creó.
