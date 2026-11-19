# Correcciones tras la verificación de las Olas 1–3 (19/11/2026)

Resumen de lo corregido después de revisar la ejecución del plan maestro. Todo vive **sin commit** y **sin aplicar a `horus_group`**: las migraciones `000087`–`000090` siguen pendientes.

## Qué cambió

| Tema | Cambio | Dónde |
| --- | --- | --- |
| Matrícula con pago | La web redirigía a `/matriculas/undefined`; ahora usa `matricula_id`. | `formulario-matricula.tsx` |
| Alineación de pagos | La migración `000088` ya **no modifica datos**: solo crea `matriculas_monto_respaldo`. La alineación es el comando `horus:reconciliar-pagos` (simulación por defecto, `--informe=`, `--aplicar --confirmo` con respaldo y auditoría, `--revertir=<lote>`). | `ReconciliarPagos.php` |
| Bloqueo automático | Ventana fija (no se renueva), contador por cuenta + IP, bloquea solo al alcanzar el umbral, **no bloquea administradores**. Sigue apagado (`SEGURIDAD_BLOQUEO_AUTO_ENABLED=false`). Recuperación: `horus:desbloquear-usuario {email}`. | `SeguridadCuentas.php` |
| 2FA | Un código TOTP no se reutiliza (`two_factor_last_step`, columna en `000090`); límite propio de 5 fallos/5 min en el login del panel; `horus:reiniciar-2fa {email}` para recuperación por consola. | `Totp.php`, `AutenticacionController` |
| Login JWT | `/api/auth/login` rechaza (403) cuentas con 2FA o con cambio de contraseña pendiente. | `AuthController` |
| WhatsApp / avisos | Consentimiento guardado con fecha y versión; al enviar se relee cuenta (baja/suspensión), preferencias, consentimiento y teléfono. Los avisos hacia Resend/Meta son **genéricos** (sin nombre del estudiante ni montos; la plantilla `horus_recordatorio_cuota` pasa a 2 parámetros: n.º de cuota y fecha). | `EnvioAvisosExternosService`, `RecordatoriosService` |
| Webhook Resend | Un evento atrasado no degrada el estado (retrasado < entregado < rebote/queja) y un `svix-id` repetido se ignora. | `ResendWebhookController` |
| Exportar datos | Se omiten `recovery`, `two_factor`, `idempotencia`, `creacion_huella`/`huella` y los datos personales de `reclamaciones` y `mensajes_contacto`. | `SensitiveData`, `GeneraExportacionAdministrativa` |
| Enlace del grupo | Solo lo ven quienes tienen `matriculas.crear` o `cursos.editar`. | `Curso::enlaceGrupoVisible` |
| Constancia | El resumen económico solo se imprime con `pagos.ver`. | `constancia-matricula.blade.php` |
| Acta borrador | Hoja «BORRADOR», aviso «NO OFICIAL» y resultado «PROVISIONAL». | `ActaExcel` |
| Contacto | `mailto:` codificado y solo para direcciones simples; WhatsApp solo para móviles peruanos. | `contacto.ts` |
| Sesión | El botón pasa a «Comprobar sesión» (no extiende la vida absoluta). | `renovador-sesion.tsx` |
| Límites | `throttle` en constancia, recibo, listado de matrículas y conteos de notificaciones. | `routes/panel.php` |
| Contrato | OpenAPI con las 54 operaciones `compartido/*`; `MatriculaEntrada` sin `monto_pagado`; la prueba de paridad cubre `compartido/`. | `horus-panel-api.yaml` |
| Migración 000087 | Idempotente (`hasColumn`) y con prueba de `up`/`down`. | `Ola2MigracionesYCursosTest` |

## Decisiones y límites

- El formulario de roles no pierde permisos: el servidor ya rechaza editar roles con permisos que el actor no posee (`assertRolGestionable`).
- La baja de usuarios sigue siendo manual (`deleted_at` sin `SoftDeletes`); los middleware `SesionPanelVigente` y `AuthenticateJwt` revalidan baja y suspensión en cada petición, por lo que `SessionRevoker` es solo defensa adicional.
- Las descripciones de `compartido/*` en el contrato son mínimas (permiso, parámetros y códigos); solo 8 escrituras sensibles tienen esquema de cuerpo (preferencias, 2FA, lotes, suspensión, asistente).
- `contacto.ts` tiene pruebas con el ejecutor integrado de Node (`npm run test:unit` en `apps/web`). `MenuFila` y los componentes React siguen sin prueba automática. La búsqueda global tiene pruebas en `BusquedaGlobalTest`.
- `phpstan` (nivel 6) informa 29 avisos, ninguno en el código nuevo de esta corrección: comprobaciones defensivas (`?->` sobre relaciones con borrado lógico, que no se quitaron para no causar errores reales) y código previo (`SolicitudCotizacion`, `JsonPeService`).

## Antes de activar los canales externos

No activar `RECORDATORIOS_*`, `WHATSAPP_CLOUD_*` ni `SEGURIDAD_BLOQUEO_AUTO_ENABLED` hasta tener: aviso de privacidad vigente (hoy borrador), acuerdos de tratamiento con Resend y Meta, y plantillas de WhatsApp aprobadas con los parámetros nuevos.

## Evidencia

Suite PHP completa en SQLite en memoria: 3647 pasan, 2 omitidas. No se midió PostgreSQL, correo ni WhatsApp reales.
