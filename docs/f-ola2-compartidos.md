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
