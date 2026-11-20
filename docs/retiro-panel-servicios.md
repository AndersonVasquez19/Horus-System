# Retiro del panel de servicios tecnológicos (19/11/2026)

## Decisión
El panel es de **gestión académica**. Ya no hay nada de «Servicios tecnológicos» en el panel: se retiró la última parte que quedaba, la
API de edición de fichas (`/api/panel/v1/servicios/*`), junto con sus permisos y los restos de interfaz.

## Qué se retiró
- **API del panel:** el grupo de rutas `servicios/*` de `routes/panel.php`, los controladores `Panel/Servicios/*`, las requests
  `GuardarServicio*`, `PrepararServicioPublicacion*`, `AutorizarServicioPublicacion*` y `SubirImagenPrincipal*`, `GestionServiciosTecnologicos`
  y `Support/Panel/DetalleServicio`.
- **Permisos:** `panel.servicios.acceder`, `servicios.ver`, `servicios.crear` y `servicios.editar` (migración `000086`). La sesión del panel
  ya no informa `paneles.servicios`; la única entrada es `panel.academico.acceder`.
- **Interfaz (`apps/web`):** el tono de color «servicios» (`--color-servicios-*`, mapas por tono, tipos `TonoPanel`/`TonoUi`, icono) y
  las etiquetas de permisos de servicios en Roles y permisos.
- **Contrato:** el tag y las rutas `/servicios/servicios/**` de `horus-panel-api.yaml` y sus esquemas de entrada.
- **Pruebas:** las del panel de servicios (contrato, escritura, imágenes, orden de tarjetas). Las pruebas del catálogo público, de
  las cotizaciones y del sincronizador de Horus AI siguen: preparan sus fichas directamente en la base con
  `tests/Support/FichasDeServiciosParaPruebas`.

## Qué se conserva (no es panel)
- **Catálogo público:** `GET /api/public/v1/servicios` y `/paginas/{pagina}`, las páginas `/tecnologias/*` de `apps/web`, las
  tablas `servicios_tecnologicos`, `servicio_publicaciones` y `servicio_publicacion_elementos` y el seeder del contenido.
- **Cotizaciones públicas** (`/api/public/v1/cotizaciones`), que listan los servicios activos y guardan su referencia.
- **Horus AI:** el exportador del catálogo, el observador y el sincronizador siguen emitiendo los servicios publicados.

## La marca editorial `servicios.publicar`
El catálogo público solo muestra una ficha si quien la autorizó tiene `servicios.publicar` (y un rol del personal); borrar el permiso
dejaría el catálogo vacío. Por eso **se conserva**, solo la tiene `admin`, **no se ofrece en Roles y permisos** (está en
`GestionRoles::PERMISOS_RETIRADOS`) y editar un rol desde la pantalla **conserva** lo oculto (antes `syncPermissions` lo habría borrado).

## Consecuencia
Las fichas del catálogo público ya no se pueden editar desde el panel: se cargan con `ServiciosTecnologicosContenidoSeeder` o
directamente en la base. Si algún día se necesita un editor, debe decidirlo el propietario.

## Migración
`2026_11_19_000086_retire_servicios_panel_permissions`: borra los cuatro permisos (Spatie elimina las asignaciones) y olvida la caché.
Es repetible y su `down()` no recrea nada. No toca tablas.
