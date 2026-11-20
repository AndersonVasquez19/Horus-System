# Libro de Reclamaciones — E4: gestión desde el panel

Fecha: 11/11/2026. Rama `anderson`. **Sin commits ni despliegue.** Primera fase del plan para completar el panel administrativo, aprobado por el propietario.

## 1. Qué cambió

Hasta E3, `/admin/reclamaciones` solo permitía consultar e imprimir. La lógica de gestión ya existía en `App\Services\GestionReclamaciones` y estaba probada, pero ninguna ruta la exponía. E4 la expone sin cambiar el servicio: sus reglas, bloqueos (`lockForUpdate`), historial y auditoría siguen siendo la autoridad.

| Pieza | Cambio |
| --- | --- |
| `routes/web.php` | `POST admin/reclamaciones/{uuid}/gestion` (`admin.reclamaciones.gestionar`): `whereUuid`, `throttle:admin-write`, dentro del grupo `/admin` (sesión y rol de personal) |
| `Admin/ReclamacionController::gestionar` | Delega cada operación en el servicio. Un 409 del servicio vuelve al detalle con el error en `gestion` (o 409 JSON). `show()` añade `responsables` y `correoActivo` |
| `GestionarReclamacionRequest` | Ya existía sin rutas. Ahora exige `confirmar_envio` cuando la operación enviaría un correo real, y tiene mensajes y atributos en español |
| `admin/reclamaciones/show` | Nueva sección «H. Gestión del caso» (parcial `_gestion`) y botón «Reintentar» en los envíos por correo fallidos |

## 2. Operaciones

Hay una sola ruta; el campo `operacion` elige la acción. El permiso se comprueba tres veces: middleware del controlador (`gestionar|responder`), `authorize()` del request por operación y `GestionReclamaciones::autorizar()`, que relee al usuario.

| `operacion` | Servicio | Permiso | Disponible en la vista cuando… |
| --- | --- | --- | --- |
| `responsable` | `asignarResponsable` | gestionar | siempre; vacío = quitar |
| `tipo` | `reclasificarTipo` | gestionar | caso abierto (reclamo solo si hay bien identificado) |
| `nota` | `agregarNota` | gestionar | siempre |
| `propuesta` | `enviarPropuesta` | gestionar | abierto, reclamo, sin propuesta pendiente |
| `rechazo` | `registrarRechazo` | gestionar | propuesta pendiente |
| `aceptacion` | `registrarAceptacion` | responder | propuesta pendiente (pide confirmación: concluye el caso) |
| `respuesta` | `registrarRespuesta` | responder | abierto y sin propuesta pendiente |
| `reintento` | `reintentarEnvio` | gestionar | envío por correo `fallido`; el envío debe pertenecer a la reclamación de la URL (si no, 404) |

La vista solo *propone*: si el estado cambió entre la carga y el envío, el servicio responde 409 y el mensaje aparece en la sección H. Una operación desconocida responde 403.

**Permisos:** sin cambios. `reclamaciones.gestionar` y `reclamaciones.responder` existen desde la migración `000031` y solo los tiene `admin`. No hay permisos, migraciones ni seeders nuevos.

## 3. Correo real

El envío de reclamaciones depende de `RECLAMACIONES_CORREO_ENABLED` y permanece apagado por defecto cuando no está definido (en el `.env` local del 11/11/2026 no está definido). Cuando se active, y siempre que el correo esté configurado, una propuesta, una respuesta por correo o un reintento **llegan al consumidor**.

- Con el envío activo (`Reclamaciones::envioHabilitado()`), esos formularios muestran un aviso visible y una casilla `confirmar_envio`, que el servidor exige (`GestionarReclamacionRequest::enviaCorreoReal()`). En la respuesta formal solo aplica al canal correo.
- Con el envío apagado, el aviso informa de que el correo queda pendiente sin remitirse (comportamiento del servicio).
- Ninguna prueba envía correo: la cola y `Mail` están simulados.

## 4. Pruebas

- Nueva `tests/Feature/ReclamacionGestionPanelTest.php` (21 pruebas): acceso (visitante, coordinador y secretaria sin permiso, solo `gestionar` sin `responder`, operación desconocida), 404 por UUID malformado o inexistente, 405 por `GET`, cada operación por HTTP con historial y auditoría, 409 convertido en error de formulario o JSON, confirmación del correo real, reintento cruzado entre reclamaciones, la sección H según estado y permiso, escape de notas y reapertura del modal tras un error.
- Actualizadas a propósito: `GestionReclamacionesTest` (el inventario de rutas incluye la de gestión, solo `POST`, con `auth`, rol de personal y `throttle:admin-write`) y `AdminReclamacionesTest` (la prueba que fijaba «sin acciones E4» admite ahora solo los formularios `POST` del detalle hacia la ruta de gestión; el resto sigue siendo `GET` y los demás métodos sobre detalle e impresión siguen en 405).

**Resultados:** `--filter=Reclamacion` 277 aprobadas (1895 aserciones); suite completa 3705 aprobadas y 1 omitida (la preexistente solo PostgreSQL); PHPStan completo presenta 11 errores preexistentes en módulos de solicitudes de matrícula y verificación de registro; los archivos de E4 pasan sin errores; `npm test` 97 aprobadas; `npm run build` correcto; `git diff --check` limpio.

**Límites:** SQLite en memoria. No mide bloqueos ni concurrencia de PostgreSQL (la prueba aislada de concurrencia de reclamaciones existe y no se ejecutó), ni la entrega real de correos, ni lectores de pantalla. Sin smoke de navegador para la sección H.

## 5. Fuera de alcance

- Registro de hojas del libro físico de respaldo (`registrarRespaldo`): queda para después por decisión del propietario.
- Enlace desde los avisos internos al caso, purga o anonimización y las decisiones legales E1–E6 siguen pendientes.
