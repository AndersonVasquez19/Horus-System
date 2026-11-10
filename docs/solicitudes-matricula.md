# Solicitudes públicas de matrícula

9 de noviembre de 2026, America/Lima. Solicitado por el propietario: formulario
para visitantes, con revisión desde el panel administrativo.

## Flujo

- Las fichas del catálogo ofrecen «Solicitar matrícula», con el curso seleccionado.
  El formulario también está en `GET /solicitar-matricula`.
- Se piden nombres, apellidos, correo, teléfono, curso de interés y autorización
  para atender la solicitud. La consulta adicional es opcional. No se piden DNI,
  documentos, claves, comprobantes ni pagos.
- `POST /solicitar-matricula` recibe la solicitud y muestra una referencia aleatoria
  mediante sesión. No hay un buscador público de solicitudes ni datos personales
  en la respuesta de confirmación.
- `/admin/solicitudes-matricula` permite filtrar por estado, consultar el contacto
  en un modal y guardar la revisión. Requiere personal autenticado con el permiso
  `solicitudes-matricula.ver`; la escritura exige además
  `solicitudes-matricula.gestionar`. Solo admin los recibe por defecto.
- Transiciones: pendiente → en revisión/rechazada; en revisión → atendida/rechazada.
  El rechazo necesita una nota. Atendida y rechazada son estados finales.
  La revisión se guarda en una transacción, con versión comprobada y bloqueo de fila.
  Una versión obsoleta exige recargar; no se sobrescribe otra revisión.

Una solicitud **no confirma una matrícula ni reserva cupo**. No crea estudiantes,
no modifica `matriculas` ni `cursos` y no envía correo. El personal contacta al
interesado y registra una eventual matrícula a través del flujo administrativo
existente. No se añade aprobación automática ni conversión entre módulos.

## Catálogo y privacidad

Las opciones provienen exclusivamente de `CatalogoPublicoCursos::paraWeb()`:
fichas públicas vigentes, autorizadas por personal que conserva el permiso y con
curso activo. Una referencia opaca HMAC evita exponer identificadores internos.
La selección se comprueba de nuevo al recibirla. Si se retira o modifica la ficha,
el visitante debe elegir una opción actual. No se cambian los predeterminados de
publicación ni se crean fichas a partir de cursos administrativos.

La solicitud guarda el título y modalidad públicos como instantánea del interés
expresado, sin enlazarlos a un estudiante o una matrícula. No acredita precios,
fechas o disponibilidad. Nombres, apellidos, correo, teléfono, mensaje y nota de
revisión se cifran con los casts de Laravel y se excluyen de la serialización del
modelo. El HTML privado los muestra escapados a personal autorizado. Los cambios
de revisión dejan auditoría de estado, versión y actor, sin copiar contacto ni
texto libre. Las páginas con datos y marcas usan `Cache-Control: no-store, private`.

Protección de recepción: CSRF del grupo web, consentimiento requerido, honeypot,
marca cifrada (mínimo dos segundos y máximo dos horas) y cinco POST por diez
minutos por el limitador de Laravel. La marca contiene una referencia aleatoria
única: repetir el mismo formulario no crea otra fila; reutilizarlo con otros
datos se rechaza. Los campos administrativos no se toman del visitante.

## Migraciones y activación local autorizada — 09/11/2026

> **10/11/2026:** por decisión del propietario las dos migraciones están **apartadas** en `database/migraciones-suspendidas/` y no se aplicaron en `horus_group`. La función queda inactiva (formulario público 503, panel 403). Para reactivarla: `git mv` de vuelta a `database/migrations/` y autorización expresa para aplicarlas.

1. `database/migraciones-suspendidas/2026_11_09_000035_create_solicitudes_matricula_table.php`
   crea la tabla independiente y el índice único de recepción.
2. `database/migraciones-suspendidas/2026_11_09_000036_add_solicitudes_matricula_permissions.php`
   crea dos permisos y los concede a admin sin resincronizar los demás roles.
   La constante correspondiente también se incorpora a `RolesAndPermissionsSeeder`.

Tras la autorización expresa del propietario en esta conversación se aplicaron
únicamente estas dos migraciones, mediante `migrate` con ambos `--path` explícitos.
Destino configurado y efectivo verificado en transacción de solo lectura:
PostgreSQL `127.0.0.1:5432`, base `horus_group`. Ambas finalizaron correctamente.
No se ejecutó un migrate general, un seeder ni otras migraciones.

Comprobaciones posteriores: tabla vacía, columna de referencia UUID y tres índices
(primaria, referencia única y estado); dos permisos asignados a admin y ninguno
nuevo en otros roles. Se conservaron las huellas de roles, permisos anteriores,
asignaciones, permisos directos y migraciones ajenas, así como los conteos de
usuarios, estudiantes, cursos, fichas editoriales y matrículas.

GET real `http://127.0.0.1:8000/solicitar-matricula`: HTTP 200, formulario y botón
de envío presentes, `Cache-Control: no-store, private` y una opción del catálogo
público vigente. Se verificó el enlace del menú al renderizar la barra lateral con
la autorización de una cuenta admin existente, sin imprimir datos personales.
Sus permisos de lectura y revisión están activos. No se envió una solicitud de
prueba a la base real: el registro sigue con cero filas.

La evidencia de pre/post quedó en la carpeta local ignorada
`storage/app/private/testing/solicitudes-matricula-activation-20261109-101900.json`.
La autorización queda limitada a esta aplicación local de las dos migraciones;
`AGENTS.md` conserva las restricciones para otras escrituras y entornos. No se
cambiaron `.env`, credenciales, flags, publicaciones ni datos académicos.
Sin la tabla, el modo de recepción no disponible y el POST 503 siguen funcionando
para otras instalaciones que todavía no hayan aplicado estas migraciones.

## Archivos

- Controladores `SolicitudMatriculaPublicaController` y `Admin/SolicitudMatriculaController`.
- Requests `StoreSolicitudMatriculaRequest` y `RevisarSolicitudMatriculaRequest`.
- Modelo `SolicitudMatricula`, servicio `GestionSolicitudesMatricula` y soporte
  `FormularioSolicitudMatricula`.
- `routes/web.php`, seeder de permisos y las dos migraciones anteriores.
- Vistas nuevas `pages/educacion/solicitar-matricula.blade.php` y
  `admin/solicitudes-matricula/index.blade.php`; enlace en el catálogo y navegación
  del panel. Se reutiliza el componente modal existente.

Los cambios locales previos del chat, estilos, modales, correos y otras páginas
se preservan. No se modifican `.env`, credenciales, los módulos excluidos ni
salvaguardas de pruebas. No se hace commit, push ni despliegue.

## Comprobaciones

- `SolicitudMatriculaTest`: 28 pruebas y 226 aserciones, SQLite `:memory:`.
  Incluye publicación elegible, recepción, cifrado, repetición, campos manipulados,
  caducidad, rate limit, permisos, revisión, versión obsoleta, auditoría sin texto
  privado, XSS escapado, retorno de errores y migración aditiva sobre roles sembrados.
- PHPStan nivel 6: aprobado. JavaScript existente: 56 pruebas aprobadas.
- Regresión de solicitudes, catálogo, operaciones y permisos de matrículas:
  178 pruebas aprobadas, 1633 aserciones, SQLite en memoria.
- Compilación Vite aprobada.
- Pint de los archivos propios y `git diff --check`: aprobados.
- Suite PHP completa: 3057 aprobadas, cinco fallos, una riesgosa y una omitida;
  15507 aserciones. Los fallos corresponden a los fixtures de privacidad ausentes
  ya documentados (`desarrollo.txt`, `errores-compartibles.txt` y
  `forense-compartible.json`); no se modifican sus pruebas ni se relajan controles.
- Smoke Edge `tests/browser/solicitudes-matricula-smoke.mjs`: aprobado a 390,
  768 y 1440 px, recepción válida, recuperación tras error y revisión en modal.
  Usa exclusivamente `127.0.0.1:8780`, opt-in y
  `tests/Support/solicitudes-matricula-browser-router.php`, con SQLite en memoria
  reconstruida por petición y HTTP externo bloqueado. Los estados administrativos
  del router son sintéticos; no prueban persistencia entre peticiones.

Las pruebas PHP sí conservan los registros entre operaciones dentro de su conexión
en memoria. Esta evidencia no mide concurrencia PostgreSQL, entregas de correo,
IA ni despliegue. Las capturas e informes quedan en la carpeta local ignorada
`storage/app/private/testing/solicitudes-matricula/`.

La activación local posterior sí verifica DDL y permisos en PostgreSQL y el GET
del servidor principal. No acredita altas reales desde navegador, concurrencia,
envío de correo ni creación de matrículas.
