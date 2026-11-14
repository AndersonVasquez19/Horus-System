# Etapa F, Ola 2: módulos compartidos de `/admin`

Alcance aprobado: notificaciones y perfil/sesiones, auditoría, usuarios y roles, reclamaciones, de menor a mayor riesgo.
Aprobaciones, configuración, respaldo, asistente y búsqueda siguen en Blade hasta decidirlos en la Ola 4.

## Decisión de método: se construye el lado Next y **el Blade se queda** (doble ejecución)

En la Ola 1 cada módulo se retiró de Blade con un 302 a Next. Los módulos compartidos no pueden hacerlo todavía:

- La sesión de Next no vale para Blade ni al revés (cookie propia del BFF; ver `docs/f-ola1-paridad.md`, «Ola 0.3»).
- El shell Blade **consume** estos módulos: la campana de notificaciones (`campana-notificaciones.mjs`, rutas
  `admin.notificaciones.recientes` y `.leidas`), «Mi perfil» y la barra lateral. Redirigirlos mandaría a quien entró por
  Blade a un panel que no reconoce su sesión.

Por eso, para estos módulos la Ola 2 **añade** la API (`/api/panel/v1/compartido/*`), las páginas de Next y pruebas
nuevas contra esa API, y **no toca el Blade**. La retirada del lado Blade (redirección y borrado) se hace junto con el
shell Blade (Ola 4) o antes si se unifica el acceso (decisión pendiente del propietario: dos accesos separados frente a
un acceso único).

## Andamio compartido

- Grupo de rutas `compartido` en `routes/panel.php`: `auth` + `SesionPanelVigente` + permiso de entrada a **cualquiera** de
  los dos paneles (`permission:panel.academico.acceder|panel.servicios.acceder`). Cada módulo declara su permiso; los de la
  cuenta propia no tienen permiso de módulo porque el usuario sale siempre de la sesión.
- `InventarioRutasPanelTest` conoce el grupo: lista blanca de prefijos de cuenta propia (`compartido/notificaciones`,
  `compartido/perfil`) y comprobación del permiso de entrada compuesto.
- Next: sin un tercer «shell». Cada módulo compartido es un componente (`components/compartido/*`) que monta cada panel
  bajo su ruta y con su tono: `/admin/academico/notificaciones` y `/admin/servicios/notificaciones` (igual con `perfil`).
  Ambos paneles añaden el grupo «Mi cuenta» a su navegación.
- BFF: nuevas entradas en la lista cerrada `OPERACIONES` (`lib/panel/operaciones-panel.ts`).

## 2.1 Notificaciones y perfil/sesiones

| Blade (se mantiene) | API del panel | Página Next |
| --- | --- | --- |
| `GET /admin/notificaciones` | `GET /compartido/notificaciones` (`page`, `per_page`, `sort=±fecha`; `meta.pendientes`) | `/admin/{panel}/notificaciones` |
| `GET /admin/notificaciones/recientes` | `GET /compartido/notificaciones/recientes` | (campana, pendiente de la UI) |
| `PATCH …/{id}/leida` | `POST /compartido/notificaciones/{id}/leida` | botón «Marcar como leída» |
| `PATCH …/leidas` | `POST /compartido/notificaciones/leidas` | «Marcar todas como leídas» |
| `GET/PUT /admin/perfil` | `GET/PUT /compartido/perfil` | `/admin/{panel}/perfil` |
| `GET/DELETE /admin/perfil/sesiones[...]` | `GET /compartido/perfil/sesiones`, `POST …/cerrar-otras`, `POST …/{huella}/cerrar` | sesiones activas |

**Reglas intactas** (mismo código y mismas garantías): visibilidad por usuario (`visiblePara`), lectura propia de las
generales, vínculo seguro (solo rutas internas), cambiar correo o contraseña exige la contraseña actual, revocación de
JWT y de las demás sesiones al cambiar la contraseña, auditoría sin contraseñas ni hashes, identificadores de sesión
solo como huella HMAC, cerrar sesiones exige la contraseña actual.

**Diferencias:** respuesta JSON `{message?, data, meta?}`, validación 422, `PATCH`/`DELETE` pasan a `POST`/`PUT`, y la
fecha llega como ISO 8601 más `fecha_local` ya formateada en Lima.

**Pruebas:** `Panel\CompartidoCuentaPanelTest` (18) cubre acceso, visibilidad y lectura por usuario, paginación y orden,
límite de la campana, vínculo seguro, marcar una y todas, perfil sin datos sensibles, contraseña actual obligatoria,
clave débil o sin confirmar, correo duplicado, que el perfil nunca toca roles ni al usuario de la petición, y cierre de
sesiones. Las pruebas Blade existentes (`AdminNotificacionTest`, `NotificacionLecturaPorUsuarioTest`,
`NotificacionPrivacidadUsuarioEliminadoTest`, `PerfilPropioTest`, `SesionesActivasTest`) se conservan porque el Blade sigue.

**Pendiente de este módulo:** la campana de notificaciones en el shell de Next (hoy solo la bandeja) y el aviso de
sin-leer en la navegación.

## 2.2 Auditoría

| Blade (se mantiene) | API del panel | Página Next |
| --- | --- | --- |
| `GET /admin/auditoria` (`auditoria.ver`) | `GET /compartido/auditoria` (`accion`, `modelo`, `usuario`, `desde`, `hasta`, `page`, `per_page`, `sort=±fecha`) | `/admin/{panel}/auditoria` |
| `POST /admin/auditoria/exportar` (`auditoria.exportar`) | `POST /compartido/auditoria/exportar` (mismos filtros; JSON más reciente primero, sin copia en el servidor) | «Exportar JSON (filtros actuales)» |

**Una sola implementación de los filtros:** `App\Support\ConsultaAuditoria` (filtros, días completos en hora de Lima,
fechas inválidas o con forma de array ignoradas, búsqueda sin comodines) la usan el Blade y el panel, así que no pueden
divergir. El controlador Blade se redujo a usarla; sus pruebas (`AuditoriaFiltrosTest`, `SecretsAndExportsTest`,
`ExportacionesPostTest`) siguen en verde sin cambios.

**Seguridad (iguales o más estrictas):** `auditoria.ver` y `auditoria.exportar` son permisos independientes (exportar sin
ver no entra al listado y al revés); los `cambios` salen siempre por `redactedChanges()` (contraseñas, tokens y texto de
búsqueda enmascarados) tanto en el listado como en la exportación; la exportación solo acepta POST, va con
`throttle:admin-export` y `Cache-Control: no-store`, se audita sin guardar el texto buscado por usuario y su tope por
`id` máximo evita que crezca mientras se transmite.

**Pruebas:** `Panel\AuditoriaPanelTest` (11): permisos propios, solo POST para exportar, rango en días completos de Lima,
fechas inválidas, hora de Lima, filtros sin distinguir mayúsculas ni tratar comodines, paginación y orden, cambios
enmascarados en listado y exportación, y registro de los filtros usados en la auditoría de la exportación.

**Next:** la navegación de ambos paneles muestra «Auditoría» solo con `auditoria.ver`; el botón de exportar solo con
`auditoria.exportar` (ayuda visual: Laravel vuelve a comprobar).

## 2.3 Usuarios y roles

| Blade (se mantiene) | API del panel | Página Next |
| --- | --- | --- |
| `GET /admin/usuarios` (`usuarios.ver`) | `GET /compartido/usuarios` (`busqueda`, `page`, `per_page`, `sort=±nombre\|email\|creado`) y `GET /compartido/usuarios/{id}` | `/admin/{panel}/usuarios` |
| `POST /admin/usuarios` (`usuarios.crear`) | `POST /compartido/usuarios` | «Nuevo usuario» (diálogo) |
| `PUT /admin/usuarios/{id}` (`usuarios.editar`) | `PUT /compartido/usuarios/{id}` | «Editar» (diálogo) |
| `DELETE /admin/usuarios/{id}` (`usuarios.eliminar`) | `DELETE /compartido/usuarios/{id}` | «Eliminar» (confirmación) |
| (roles en el formulario) | `GET /compartido/usuarios/roles-asignables` (`usuarios.crear` o `usuarios.editar`) | casillas de roles |
| `GET /admin/roles` (`roles.ver`) | `GET /compartido/roles` (`data` + `meta.permisos`) | `/admin/{panel}/roles` |
| `POST/PUT/DELETE /admin/roles[/{id}]` | `POST /compartido/roles`, `PUT/DELETE /compartido/roles/{id}` | «Nuevo rol», «Editar», «Eliminar» |

**Una sola implementación de las reglas de protección.** Antes la lógica vivía dentro de los controladores Blade. Se movió,
sin cambiar su comportamiento, a dos servicios que usan el Blade y el panel:

- `App\Services\GestionUsuarios` (crear, actualizar, eliminar).
- `App\Services\GestionRoles` (crear, actualizar, eliminar, catálogo de permisos visibles).

Los controladores Blade quedaron en unas pocas líneas por acción. Así la escalada de privilegios, el último
administrador y la auditoría no pueden divergir entre el Blade y el panel. `AdminProtection` no se tocó.

**Garantías que llegan intactas al panel:** el actor se relee con datos vigentes bajo bloqueo (no con la caché de
Spatie); quien no es administrador no crea ni modifica administradores ni se concede roles que superen sus permisos
(R1 a R3); no se concede un permiso que no se posee (R4c) ni se edita el rol `admin`; el rol `admin` conserva sus
permisos de administración y ni él ni los roles base se renombran o eliminan; nadie se elimina a sí mismo ni al último
administrador; cambiar una contraseña sube `jwt_version` y cierra las demás sesiones (la actual se conserva si es la
propia); un usuario que se edita sin enviar `roles` no pierde los suyos. Las denegaciones son un 403 genérico que se
registra después del rollback como `acceso_denegado`. La auditoría nunca guarda contraseñas ni hashes.

**Diferencias:** respuesta JSON `{message?, data, meta?}`, validación 422, `PUT`/`DELETE` explícitos y representación de
lista cerrada (nunca contraseña, hash, `remember_token` ni `jwt_version`). Los roles añaden `tipo`
(`principal` | `base` | `personalizado`) y el número de usuarios. Los permisos históricos sin módulo se ocultan.

**BFF:** nueva capacidad acotada `listas` en la lista cerrada `OPERACIONES` (hasta 100 textos de 100 caracteres) para
`roles` y `permissions`; el resto de campos sigue siendo escalar. Una lista ausente no se envía, y Laravel distingue
«sin campo» de «lista vacía».

**Pruebas:** `Panel\UsuariosRolesPanelTest` (22): acceso y permisos de cada acción, listado con búsqueda y orden sin
secretos, comodines, detalle y 404, `roles-asignables` sin `roles.ver`, alta con auditoría sin la clave, validaciones,
escalada al crear y al editarse, cambio de clave que revoca tokens, edición propia sin `roles`, administradores
protegidos, último administrador, autoeliminación, listado de roles con tipo, ciclo de vida de un rol, nombre repetido,
roles `admin` y base protegidos, permisos de administración y escalada con `roles.*`. Las pruebas Blade existentes
(`AdminProtectionTest`, `EscaladaPrivilegiosRolesTest`, `ProteccionAdministradoresTest`, `ProteccionRolesBaseTest`,
`PromoverAdminLocalTest` y las que tocan `admin.usuarios` o `admin.roles`) se conservan y siguen en verde: confirman que
la extracción no cambió nada.

**Next:** la navegación de ambos paneles añade «Usuarios» (`usuarios.ver`) y «Roles y permisos» (`roles.ver`) en
«Sistema»; los botones solo aparecen con el permiso correspondiente (ayuda visual: Laravel vuelve a comprobar). La
sección de roles del formulario de usuario aparece con `usuarios.asignar-roles`.
