# Sprint 19.2 — preparación de recepción pública y riesgos comerciales

## 1. Resultado y límites de esta fase

29/10/2026. **Análisis y propuesta documental; recepción pública no implementada ni autorizada para datos reales.** Se mantienen solamente `recibida → en_revision`, con repetición del estado actual sin cambios. Consulta informativa, solicitud privada y presupuesto formal siguen siendo conceptos distintos. No se diseñan precios, impuestos, aceptación, rechazo ni cierre comercial.

Entregables propios: este informe y una actualización puntual de `PROJECT_CONTEXT.md`. Ningún cambio de código, rutas, vistas, JavaScript, migraciones, configuración o credenciales. No se arrancó Laravel, navegador, PostgreSQL o proveedores; no hubo SQL, migraciones, commits, push ni operaciones sobre stashes. Las consultas externas se limitaron a documentación pública de seguridad y referencias normativas.

## 2. Estado inicial y preservación

- Rama `anderson`; HEAD `038895280000f57d39c698e40d3436fecc0d716f`; índice vacío.
- Cinco archivos versionados ya modificados: `PROJECT_CONTEXT.md`, `database/seeders/RolesAndPermissionsSeeder.php`, `resources/views/admin/partials/sidebar.blade.php`, `routes/web.php`, `vite.config.js`.
- Trabajo previo sin seguimiento: controlador, dos requests, dos modelos, servicio, migraciones `000027`/`000028` y prueba del Sprint 19; dos vistas, JS, prueba PHP/frontend y fixture/smoke del panel 19.1; informes 19/19.1 y documento PostgreSQL. Todo es preexistente a esta fase, aunque Git aún no lo versione.
- Stashes preservados: `52e2f296e243cacf777f48d8148b0a72e68fc93c` y `d69d6caa1c7b2a18c9c2aa962bc74a473bbdcc82`.
- Inventario inicial delimitado por NUL y SHA-256 de **629 archivos** versionados o nuevos no ignorados, para comparar al cerrar. El inventario no incluye dependencias, secretos, logs ni artefactos ignorados.
- Se observan categorías ignoradas preexistentes: entorno local, dependencias, cachés, sesiones, compilados, evidencias de pruebas y un backup SQLite. No se abren sus contenidos ni se limpian; su presencia no acredita seguridad, procedencia o recuperabilidad. No se inspecciona el entorno efectivo ni el clúster PostgreSQL.

El estado Running/Manual de Testing y la pérdida de credenciales corresponden al informe histórico PostgreSQL, **no a una medición de este sprint**. Su última decisión R2-C.1 prepara monousuario sin trust; sigue pendiente un auxiliar revisado y autorización independiente. Este informe no retoma esa intervención.

## 3. Fuentes revisadas y flujo comprobado en código

| Área | Archivos revisados / hallazgo |
| --- | --- |
| Normas y evidencia | [AGENTS.md](../AGENTS.md), [CLAUDE.md](../CLAUDE.md), [PROJECT_CONTEXT.md](../PROJECT_CONTEXT.md), [Sprint 19](sprint-19-cotizaciones.md), [Sprint 19.1](sprint-19-1-panel-cotizaciones.md), [PostgreSQL Testing](postgresql-testing-validacion-final.md) |
| Entradas y middleware | `routes/web.php`, `routes/api.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Middleware/DescartarSesionSinVerificar.php`; grupo web estándar de Laravel en `vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php` |
| Contacto y aviso actual | `resources/views/pages/contactos.blade.php`, `resources/js/app.js` (bloque `contactForm`), `resources/views/pages/legales/politicas-privacidad.blade.php` |
| Oferta pública | Vistas de `resources/views/pages/tecnologias/` y `pages/educacion/` revisadas por sus enlaces/formularios; `ServiciosPublicosController`, `CursosPublicosController`, `CatalogoPublicoServicios`, `CatalogoPublicoCursos` |
| Dominio privado | `Admin/CotizacionController`, `GuardarCotizacionRequest`, `GestionarCotizacionRequest`, `GestionCotizaciones`, `SolicitudCotizacion`, `CotizacionHistorial` |
| Identidad y esquema | `Curso`, `Admin/CursoController`, requests de curso; migraciones de cursos `000002`, servicios `000025`, cotizaciones `000027` y permisos `000028`; `RolesAndPermissionsSeeder` |
| Auditoría y errores | `AuditLog`, `Admin/AuditoriaController`, `SensitiveData`, `OldInput`; `Handler` y `QueryException` del framework instalado |
| Configuración declarada | `config/app.php`, `session.php`, `cache.php`, `logging.php`, flags de catálogos/chatbot/Advisor/correo/verificación; sin leer valores de `.env` |
| Evidencia y arnés | Casos de `CotizacionTest`, `PanelCotizacionesTest`, cliente `admin-cotizaciones.mjs`; `phpunit.xml`, `phpunit.pgsql.xml`, referencias de `tests/PostgresIntegrity/`, fixtures y `ConcurrentTestSession` |

### Flujo público actual

GET `/contactos` presenta una vista. El formulario exige nombre, correo, teléfono, asunto y mensaje en cliente; `contactForm.send()` construye un `mailto:` con asunto/cuerpo y lo abre mediante `window.location.href`. **No hace POST comercial a Laravel, no registra expediente, no comprueba envío ni recepción del correo.** El usuario puede enviarlo después desde su aplicación de correo: esa vía tiene almacenamiento y destinatarios ajenos a la base comercial. No se envió ningún mensaje durante esta revisión.

Las tres páginas tecnológicas y las páginas educativas conservan sus contenidos estáticos. Las secciones dinámicas de soporte/mantenimiento y cursos, detrás de sus flags, enlazan a contacto general; no pasan una identidad de ficha ni crean solicitudes. Las tarjetas estáticas/modalidades no son entidades editoriales y no deben importarse automáticamente.

POST `/chat/mensaje` tiene sesión, CSRF, validación de texto y `throttle:30,1`; orienta mediante el asistente, sin registrar una solicitud comercial. Login, registro, recuperación y verificación tienen límites específicos. No hay endpoint público de cotizaciones ni protección antispam comercial implementada que se pueda declarar lista. CSRF y límites del chat no se trasladan automáticamente a rutas futuras.

### Flujo privado actual y reutilización

Ocho rutas bajo `/admin/cotizaciones`: listado, alta, detalle, actualización, estado, responsable, seguimiento e historial. Sesión y rol `admin|coordinador|secretaria`; permisos independientes `cotizaciones.ver|crear|editar|gestionar`, solo admin por defecto. El panel requiere lectura; crear o gestionar no la concede. `ver` alcanza todos los expedientes, no solo los asignados. Asignación es organización, no aislamiento por cartera.

Se reutilizan como patrones: Form Requests, proyecciones explícitas, texto escapado, paginación SQL, recibo mínimo, transacciones, reautorización, revisión fuerte, reserva idempotente, historia y auditoría. El cliente privado ya conserva borradores en memoria y exige conciliación deliberada. No se reutiliza su API como entrada anónima.

La creación actual requiere un `User` fresco, rol y permiso; idempotencia por operador; snapshot del **nombre administrativo** de un servicio existente, incluso histórico/inactivo. `creador_ambito` y `actor_ambito` son obligatorios. Que `creado_por`/`actor_id` admitan null para cuentas eliminadas **no proporciona un contrato de creador anónimo**. No usar una cuenta admin, ID cero ni un usuario técnico con permisos para sortearlo.

La auditoría omite valores de contacto/necesidad/notas, pero `AuditLog::record()` guarda IP y las pantallas/exportaciones con `auditoria.ver/exportar` pueden consultar sus metadatos. Por tanto hay acceso a metadatos técnicos fuera de `cotizaciones.ver`; no equivale a exposición del expediente completo ni a auditoría anónima.

## 4. Riesgos y nivel de evidencia

“Comprobado” indica lectura del código/configuración declarada; no prueba explotación o incidencia con datos reales.

| ID / prioridad | Evidencia y consecuencia | Resolución exigida antes de recibir datos reales |
| --- | --- | --- |
| R1 / bloqueante | Comprobado: no hay recepción comercial HTTP; `mailto:` depende de una aplicación y envío posterior | Contrato/entrada nuevos separados, confirmación solo tras commit; no anunciar que el formulario actual registra solicitudes |
| R2 / bloqueante | Comprobado: política con dominio `www……..`, publicidad/finalidades amplias y conservación indeterminada; no hay evidencia de consentimiento específico versionado | Aprobar identidad del responsable, aviso y finalidades; revisión jurídica; no asumir que el texto existente habilita recepción comercial |
| R3 / bloqueante | Comprobado: no existe borrado/anonimización del expediente; CHECK de contacto mínimo e historial con FK RESTRICT; notas y reservas duran sin purga | Aprobar retención y mecanismo nuevo, incluyendo historial, auditoría, reservas y restauraciones; no borrar filas ni alterar restricciones hoy |
| R4 / bloqueante | Comprobado: API y ámbitos actuales requieren operador; snapshots administrativos no prueban elegibilidad pública | Diseñar origen público y ámbito anónimo propios sin quitar controles privados; comprobar ficha vigente antes de primera creación |
| R5 / alto | Comprobado en framework: `QueryException` construye mensaje con SQL/bindings; manejador reporta mensaje/excepción; bootstrap no añade sanitización comercial | Probar errores sintéticos con marcadores personales y excluir mensaje/SQL/bindings/cuerpo de logs/respuestas del camino nuevo. `APP_DEBUG=false` solo protege parte de la respuesta, no sanea logs |
| R6 / alto | Comprobado en framework: validación HTML redirigida usa `withInput`; exclusión estándar solo de contraseñas; sesión declarada `database`, cifrado false por defecto | Entrada pública debe devolver errores controlados sin flash de datos comerciales. Reposición solo en memoria del cliente; no copiar el contrato HTML habitual sin revisar |
| R7 / alto | Comprobado: auditoría guarda IP; `single` no tiene rotación por días declarada, `daily` propone 14 si se elige; canal efectivo no inspeccionado | Acordar mínimos/retención y acceso de logs/IP, servidor web y backups. No prometer que 14 días sea la política real |
| R8 / alto | Comprobado: cursos tienen PK interna, código editable y lector público sin identidad estable proyectada; solicitud solo relaciona servicio | Mantener cursos fuera de primera asociación estructurada o autorizar UUID público y contrato nuevos; nunca asociar por título/código editable |
| R9 / alto | Limitación comprobada: reautorización fresca no serializa revocaciones posteriores; locks ignorados por SQLite; GET/COUNT no prometen snapshot | Plan PostgreSQL de dos sesiones y decisión sobre el punto efectivo de revocación; no afirmar revocación instantánea universal |
| R10 / alto | Hipótesis: bots distribuidos, suplantación de contacto, abuso de claves/sesiones, agotamiento de base/caché | Defensa por capas, cuota global, prueba de carga y procedimiento operativo. No se ha demostrado un ataque real ni una tasa segura |
| R11 / alto | No comprobado en despliegue: TLS, debug/cookies efectivos, proxy/IP fiables, ACL/cifrado de copias, redacción de servidor/proxy/telemetría | Revisión autorizada de configuración operativa antes de abrir; esta lectura no inspecciona secretos ni infraestructura |
| R12 / alto | Comprobado: HMAC ligada a APP_KEY y reserva sin expiración; rotación produce conflicto sin recrear | Diseño de versiones de clave y retención pública; nunca sugerir nueva clave/intención automática tras incertidumbre |

No se encontró exposición pública de contacto/necesidad/historial en los lectores revisados. Esto no es una prueba de penetración de toda la aplicación. Tampoco se observó una filtración en logs reales: R5 acredita el mecanismo de riesgo, no que ya se haya almacenado un expediente real.

## 5. Propuesta de privacidad para aprobación

### Datos y finalidad limitada

| Dato propuesto | Finalidad | Mínimo / regla propuesta |
| --- | --- | --- |
| Nombre de contacto | Dirigirse a quien consulta | Texto hasta 120; no exigir nombre legal completo o documento |
| Correo o teléfono | Responder sobre esta necesidad | Al menos uno; límites existentes 254/30. No obligar ambos ni validar existencia mediante proveedores |
| Necesidad | Entender alcance para primera atención | Hasta 5000 caracteres; aviso de no incluir contraseñas, DNI, datos sensibles ni terceros innecesarios; sin archivos |
| Servicio opcional | Contextualizar la consulta | UUID público validado y título editorial capturado por servidor; tema general si no se selecciona |
| Recepción/origen | Trazabilidad | Servidor, UTC; presentación Lima. No aceptar timestamps, responsable o estado del visitante |
| Aviso/manifestación | Acreditar qué se informó y qué se aceptó cuando corresponda | Versión del texto y fecha servidor, finalidad y manifestación explícita; no copiar otra vez todo el cuerpo personal |
| Identificador de intención | Evitar retransmisión duplicada | Aleatorio opaco; hash/huella internos, nunca email como deduplicador |
| Señales de abuso | Limitar automatización | Mínimas y temporales; no añadir IP/UA completos al expediente por comodidad. Revisar IP ya capturada por auditoría y servidor |

Nombre, necesidad, contacto, notas y cualquier reserva vinculable siguen siendo privados. Un hash/HMAC o UUID no convierte automáticamente la información en anónima. No usar datos de estudiantes/usuarios como padrón de clientes, ni correo para fusionar personas.

### Aviso y manifestación propuestos, no texto legal aprobado

Propuesta de finalidad: **atender esta solicitud y contactar al solicitante sobre ella**, sin marketing, suscripciones o envío a IA. Antes del envío deben ser visibles responsable real, canal de derechos confirmado, datos obligatorios/opcionales, finalidad, destinatarios/encargados pertinentes y plazo aprobado. El propietario debe confirmar la identidad jurídica y el canal; no copiar automáticamente el correo o la firma de asesoría de la plantilla como validación de recepción de derechos.

Borrador de copy para revisión: «Usaremos los datos que indiques para atender esta solicitud y contactarte sobre ella. No es un presupuesto aprobado. Evita incluir información sensible o contraseñas. Consulta el aviso de privacidad [versión y enlace aprobados]». Si se decide usar consentimiento como base aplicable, proponer control separado, no premarcado, con finalidad concreta y versión verificable; no tratar un simple enlace o navegación como aceptación. La base jurídica, alcance del consentimiento y tratamiento de menores requieren evaluación competente antes de fijar el contrato final. No se incorporará marketing en la primera versión.

La referencia normativa localizada es el [DS 016-2024-JUS, ficha oficial ANPD](https://www.gob.pe/institucion/anpd/normas-legales/6554453-16-2024-jus), reglamento de la Ley 29733. La ficha se localizó mediante búsqueda; su apertura directa devolvió error del sitio. No se hizo dictamen de vigencia consolidada ni análisis artículo por artículo. Deben verificarse normativa aplicable, base jurídica, banco de datos/encargados/transferencias, derechos, seguridad y obligaciones ante incidentes con asesoría competente. Este documento no declara cumplimiento ni asigna plazos legales.

### Retención: alternativas explícitas, ninguna aplicada

| Conjunto | Alternativas para decidir, sin valor predeterminado | Evento que iniciaría el plazo propuesto |
| --- | --- | --- |
| Contacto, necesidad y notas de una consulta sin contrato | Comparar 90 o 180 días según necesidad comercial y evaluación jurídica; propietario puede definir otro plazo justificado | Última interacción comercial sustantiva documentada; recepción si nunca hubo respuesta. No `updated_at`, replay, asignación o lectura |
| Evidencia mínima de aviso y actuaciones | Plazo separado a justificar por finalidad/base aplicable | Atención de la solicitud o evento de privacidad definido; no crear un cierre comercial ficticio |
| Logs técnicos y señales antispam | Comparar 7 o 30 días; agregados sin vínculo solo tras verificar desidentificación | Fecha del evento; rotación no prolongada por acceso |
| Copias de seguridad | Comparar ventanas de 30 o 90 días con recuperabilidad y borrado diferido documentado | Creación de cada copia; no prometer eliminación física inmediata de todas las copias |
| Reservas idempotentes y huellas vinculables | Definir duración compatible con ventana de replay, prevención de recreación y retención personal | Emisión/último uso según decisión explícita; ningún replay debe extender indefinidamente datos personales |

Estas cifras son opciones de evaluación, **no una decisión de retención** ni máximos legales. Sin plazos aprobados para cada conjunto no se abre recepción real. No hay estado cerrado: la selección de candidatos a revisión de retención no debe cambiar estados comerciales ni iniciar una purga automática. Distinguir contacto efectivo de una nota técnica, y registrar el evento de inicio de plazo en una estructura futura verificable. Retenciones excepcionales requieren motivo, responsable y revisión; no conservación indefinida por defecto.

### Acceso, rectificación y eliminación

Proponer un circuito privado humano: recibir por canal validado, verificar identidad de modo proporcional sin pedir documentos rutinariamente, acotar expedientes y tramitar por personal autorizado. UUID/código o conocer un email no acredita identidad. No publicar un buscador para ejercer derechos; no enviar listados por canales sin autenticar al destinatario. Plazos de respuesta y excepciones deben aprobarse tras la revisión jurídica.

Acceso: entregar solo información del titular por un canal seguro, sin notas de terceros innecesarias. Rectificación: reutilizar actualizaciones autorizadas cuando proceda, anotando campos modificados sin conservar valores personales anteriores innecesarios; corregir también notas/copias derivadas identificadas. La API actual no permite editar notas históricas: necesita diseño específico, no una edición oculta por SQL.

Eliminación/anonimización: hoy no existe y vaciar contactos viola el CHECK de contacto mínimo; borrar expediente viola FK de historia. Diseñar migración **nueva** y operación privilegiada con revisión, autorización y trazabilidad mínima: tratar contacto/necesidad/notas, vínculos e identificadores reidentificables, reservas/huellas, IP de auditoría y exportaciones/copias. Un evento técnico de privacidad no será un estado comercial nuevo. No relajar `000027` ni borrar auditorías generales indiscriminadamente.

Conservar solo eventos mínimos bajo una finalidad y plazo separados aprobados; si quedan enlaces a identidad, llamarlo seudonimización, no anonimización. Para evitar recreación al retirar datos, coordinar un marcador técnico mínimo de intención consumida o caducada, sin cuerpo/HMAC personal innecesario, con el vencimiento del token público. Su conservación también requiere justificación/plazo. No prometer a la vez replay perpetuo y eliminación completa de todas las huellas.

Backups: cifrado y acceso mínimo, claves fuera de Git, copias con expiración controlada y prueba de recuperación. Mantener un registro mínimo protegido de supresiones aplicables; tras restauración autorizada, volver a aplicarlas antes de habilitar lectura/recepción. Copias históricas aisladas no deben reintroducir datos eliminados a operación. No se crea ni restaura ningún backup aquí.

## 6. Defensa por capas de la futura entrada

Propuesta de primera versión: navegador del mismo origen, sesión web y CSRF; sin API anónima para terceros, sin CORS amplio ni autenticación administrativa para visitantes. CSRF reduce solicitudes cruzadas, **no** prueba identidad ni detiene bots que obtienen sesión/token legítimos.

| Capa | Diseño propuesto / criterio verificable |
| --- | --- |
| Activación | Flag propio de recepción, apagado por defecto, independiente de ambos catálogos/correo/Advisor. Apagado: no mostrar formulario ni procesar/guardar campos comerciales o acceder a sus tablas, incluso si faltan. Una petición maliciosa puede llegar al servidor: rechazarla temprano y sin guardar su cuerpo |
| Transporte y servidor | HTTPS, cookie Secure/HttpOnly/SameSite adecuado, origen/referrer comprobado sin tomarlo como autenticación; proxy confiable explícito. Rechazar cuerpo antes del parseo costoso en proxy/servidor y en aplicación |
| Tamaño | Candidato técnico: 32 KiB por cuerpo UTF-8, sin archivos, profundidad/número de campos acotados. Los 5000 caracteres pueden ocupar varios bytes; probar límites reales de JSON y escaping. `ValidatePostSize` de PHP no acredita ese límite comercial |
| Frecuencia | Candidatos para pruebas: 5 intentos/min por ámbito de intención/sesión, 20/h por IP y presupuesto global a dimensionar con capacidad real. No son tasas aprobadas o medidas. Emisión de tokens/GET también limitada; 429 genérico con Retry-After |
| Contadores | Almacén compartido con incrementos atómicos entre workers; IP procedente de proxy validado, claves HMAC temporales cuando proceda; no email público para bloqueo global. Probar NAT/IPv6, caché caída y cardinalidad máxima. Caída del control de abuso: 503 sin aceptar, no bypass silencioso |
| Automatización | Señuelo accesible no visible para usuarios y señal de tiempo firmada, sin considerarlos prueba única. Supervisión agregada/colas acotadas. CAPTCHA solo si luego se autoriza proveedor, privacidad y alternativa accesible; ninguno ahora |
| Validación | Form Request público propio y lista cerrada; rechazar campos internos/desconocidos, arrays donde se espera escalar, HTML y tamaños inválidos. Al menos correo o teléfono; no cargar URLs, resolver adjuntos ni llamar verificadores externos |
| Salida | Texto escapado con Blade/textContent, SQL con bindings; errores por campos permitidos sin reflejar entrada. No transformar necesidad en HTML/URL/comando ni usarla como prompt de IA |
| Privacidad de errores | JSON controlado/no-store, sin flash del cuerpo en sesión, sin SQL, trazas, cookies, tokens, contactos o excepciones crudas en logs. Registrar códigos de evento, correlación aleatoria no identificativa y conteos acotados |
| Operación | Cuota y alerta agregada con responsable humano y apagado del flag. Sin SMS, email, jobs comerciales o llamadas a FastAPI/OpenAI/Resend. No afirmar protección ante DDoS volumétrico por tener throttle Laravel |

El orden concreto del pipeline deberá probarse: límite perimetral/cuerpo → flag temprano → controles web/abuso e intención → validación → transacción. Sesiones web pueden consultar sus tablas aun con el módulo apagado; no prometer cero SQL global. La recepción apagada no debe validar campos comerciales, reportar cuerpos o persistirlos como entrada previa. El límite temprano de intentos también debe contar validaciones fallidas y replays para no facilitar agotamiento.

El enfoque de validación barata primero, límites de cuerpo y capas distintas de aplicación/red se apoya en [OWASP DoS Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Denial_of_Service_Cheat_Sheet.html). Los valores numéricos anteriores son hipótesis locales para ensayar. La minimización y pruebas de fallos de logs se apoyan en [OWASP Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html); no sustituye diseñar la redacción específica de errores SQL.

## 7. Contrato público propuesto, todavía inexistente

### Entrada y respuesta

Nombre/URL candidatos, sujetos a autorización posterior: GET `/solicitar-cotizacion` para presentación/emisión de intención sin datos personales y POST `/solicitudes-cotizacion` para recepción. **No añadirlos en este sprint.** No habrá GET/PUT/PATCH público por UUID, código, contacto o clave, ni ruta de historia/estado del expediente. No adaptar `/admin/cotizaciones` o el chat para visitantes.

Cuerpo de negocio permitido: `contacto_nombre`, `contacto_email` y/o `contacto_telefono`, `necesidad_cliente`, `servicio_uuid` opcional y manifestación/versionado del aviso según decisión aprobada. Versión esperada se fija por servidor y se contrasta con lo presentado; fecha de aceptación del cliente no es confiable. Señales antispam y token de intención se procesan aparte, no se copian al expediente ni se mezclan con campos comerciales.

Estado `recibida`, revisión inicial, identidad/código, origen y timestamps los asigna el servidor; sin responsable inicial impuesto por visitante. Rechazar `estado`, `responsable_id`, `creado_por`, IDs internos, snapshots, revisiones, fechas, notas y metadatos de auditoría. La relación futura debe conservar el título **editorial visto**, sin enviar el nombre administrativo al cliente. No presentar una solicitud como inscripción, cupo reservado o presupuesto.

Propuesta de respuesta después de commit efectivo: 201 primera recepción, 200 replay válido, mismo cuerpo mínimo `{"recibida":true,"mensaje":"Solicitud recibida para revisión; no constituye un presupuesto aprobado."}`. No devolver UUID/código privado, ETag, nombre/contacto/necesidad, estado del expediente ni links de consulta. No emitir 202 sin haber diseñado una cola duradera; no simular éxito para un fallo de almacenamiento o un envío descartado como spam.

Rechazos candidatos: 413 cuerpo excesivo, 415 formato, 422 validación con campos permitidos, 419 sesión/CSRF, 429 cuota, 409 intención incompatible o selección no disponible, 410 intención vencida, 503 recepción/infraestructura indisponible; 500 inesperado saneado, conservando diagnóstico técnico seguro. Servicio desconocido/inactivo/retirado/caducado debe producir el mismo mensaje de selección no disponible, sin revelar existencia privada. No confirmar ni negar otras solicitudes por email/teléfono. Clasificación final se prueba también en proxy, no solo en controlador.

### Idempotencia anónima e incertidumbre

UUID de cliente por sí solo no es autenticación ni sustituye el ámbito por operador actual. Proponer intención aleatoria de alta entropía emitida/firmada por servidor, ligada a operación/versión/origen y un ámbito anónimo aleatorio, con caducidad. Token en cuerpo/cabecera del mismo origen, nunca URL, logs o almacenamiento persistente del navegador. Sesión y CSRF se verifican además; renovar sesión no debe permitir reutilizar una intención consumida como si fuera nueva.

Reserva duradera única por ámbito de intención/operación, hash del secreto y HMAC de campos validados canonizados; versión de huella/clave explícita. El ámbito no depende de IP, correo, un ID de operador inventado ni exclusivamente de sesión efímera. La validación criptográfica/expiración debe impedir que un token antiguo vuelva a crear después de purgar reservas. La duración exacta de token/reserva y estrategia de claves se aprueban junto con retención; no se fija un TTL aquí. No almacenar una segunda copia del cuerpo para comparar.

En transacción: para intención ya consumida y mismo cuerpo, responder el recibo mínimo tras controles de seguridad, sin nueva historia/auditoría; cuerpo distinto 409. Para intención nueva: revalidar selección pública bajo protocolo de bloqueos acordado, insertar expediente + historia + auditoría + reserva atómicamente. No exigir que un servicio siga publicado para confirmar un replay ya recibido: su retiro posterior no debe convertirlo en un alta distinta. Sí exigir vigencia en la **primera** creación. Capturar unicidad tras rollback y reconciliar en otra transacción; no continuar una transacción PostgreSQL abortada.

La nueva vía necesita diseñar explícitamente origen público/actor nulo y sus ámbitos técnicos; el servicio privado actual no los implementa. Revisar además auditoría para no atribuir una solicitud al admin que casualmente tenga sesión abierta en el mismo navegador. No reutilizar `auth()->id()` implícito como identidad del visitante.

Mientras se envía, bloquear doble clic y conservar cuerpo/clave en memoria. Timeout, 5xx o recibo ilegible: resultado incierto, no limpiar texto, no crear intención nueva ni reintentar automáticamente. Reconciliar deliberadamente mediante el mismo POST/cuerpo/token cuando siga válido, siempre con límites y respuesta mínima; no agregar buscador público como recuperación. Tras caducidad o recarga que perdió el borrador, informar incertidumbre y derivar a atención humana definida; no declarar que no se recibió ni generar otra solicitud automáticamente. El mecanismo no promete exactly-once indefinido ni evita que una persona envíe deliberadamente otra intención legítima.

## 8. Relación con servicios y cursos

**Servicios:** `identificador_publico` UUID es el candidato existente; nunca nombre/código de texto como vínculo. En la entrada pública nueva, resolver puntualmente UUID con la misma elegibilidad editorial: servicio activo, ficha publicada, vigencia inclusiva Lima, autorización no futura y autor actual con permiso/rol. No recorrer todo el catálogo para buscar un UUID. Respetar flags: si no está habilitado el catálogo de servicios no ofrecer selección estructurada; puede diseñarse solicitud general sin asociación solo si el propietario autoriza recepción independiente.

Guardar FK y UUID con snapshot del título editorial disponible al recibir; fuente del snapshot debe quedar definida, porque hoy la entrada privada guarda nombre administrativo. Renombrar/retirar/inactivar después no altera historia. No borrar servicio vinculado ni solicitudes; no publicar/reactivar por crear una consulta. Revalidar bajo bloqueos y documentar límite temporal de revocación del autor. No prometer disponibilidad, precio o plazo por la mera asociación.

**Cursos:** PK interna es estable dentro de la base, pero no hay UUID público en `Curso` ni en la proyección del catálogo; `codigo` es editable. Las modalidades estáticas de la página no identifican cursos concretos. Proponer primera versión limitada a servicios o consulta general. Si se aprueban cursos: migración aditiva nueva con UUID público único/inmutable, mapeo FK histórico y título editorial, restricciones de borrado/soft-delete y consulta pública puntual autorizada. Requiere alcance específico sobre cursos/catálogos y revisión de consumidores; **no se implementa ni se incluye como dependencia imprescindible de servicios**. No pasar PK privada o título como sustituto provisional.

## 9. Plan verificable de pruebas

### Dependencias antes de cualquier PostgreSQL

1. Autorización independiente para recuperación de credenciales/S7 conforme al acta R2-C.1; no reejecutar aprovisionamiento, no cambiar HBA a trust. No se aporta una receta de recuperación nueva.
2. Destinos exclusivamente desechables autorizados, sin datos reales: principal `horus_l2_test` y restauración `horus_l2_restore_test`, runners y loopback/puerto de la lista fija de guardas existentes. Nunca habitual ni ensayo con datos copiados. Reacreditar identidad, versión 18, marcador, privilegios y consentimiento antes de escrituras.
3. Resolver el arnés: `RefreshDatabase`/`DatabaseTruncation` y suites del perfil migran por defecto e incluirían `000022`. Un filtro de tests no la excluye. Preparar y revisar una lista explícita de migraciones/dependencias, sin `000022`, antes de ejecutar. No mover/borrar la migración ni debilitar guardas; no ejecutar `check` como diagnóstico inocuo, pues su conector habilita escritura tras la sonda.
4. Manifestar dependencias exactas por nombre/hash: usuarios, Spatie y auditoría; `000025`/`000026` servicios; `000027`/`000028` comerciales. Para servicios, distinguir dependencia estructural de permisos/editorial. Revisar las migraciones base transitivas con sus DDL; no asumir que basta aplicar cuatro archivos sobre una base vacía. Separar migración, provisión sintética y prueba funcional. Permisos `000028` no crean admin si falta: ensayar ambas situaciones.
5. Autorizar base, ventana, archivos, backup/evidencia y contingencia por separado. Proveedores bloqueados y fixtures ficticias. Ninguna autorización se infiere de este plan.

### Matriz de aceptación futura

| Caso | Qué puede probar SQLite | Prueba adicional PostgreSQL 18 y resultado esperado | Evidencia mínima |
| --- | --- | --- | --- |
| P1 instalación y permisos | Flujo de instalación y migración aditiva repetible, sin resincronización | DDL real de dependencias/000027/000028 en base vacía y previamente provisionada; solo admin recibe concesión por defecto, concesiones previas intactas | Manifest/hash, versión/identidad permitida, migraciones aplicadas y matriz antes/después |
| P2 tipos/restricciones | Comportamiento de enums/triggers SQLite | UUID nativo, varchar/límites, timestampTz/UTC y fechas Lima, CHECK positivos/contacto/snapshot, unicidades/FK RESTRICT y SET NULL; rechazo de estado inválido | Catálogo de restricciones/tipos y SQLSTATE, sin bindings personales; savepoints para cada violación esperada |
| P3 índices/consulta | COUNT/LIMIT/OFFSET y proyecciones | Planes con volumen sintético representativo para estado/responsable/servicio/recepción/próximo seguimiento/historia y páginas grandes; latencia/memoria y presupuesto definido antes del ensayo | EXPLAIN/mediciones saneadas, cantidad de filas/consultas y criterio de aceptación acordado |
| P4 misma intención/cuerpo | Replay secuencial y auditoría única | Dos creaciones reales en sesiones/procesos independientes, barrera antes de insert; una 201 y otra 200 con misma identidad privada, una solicitud/historia/auditoría | Cronología A/B, commits/rollback y conteos; no solo sleep o un mock de unicidad |
| P5 misma clave/cuerpos distintos | 409 secuencial | Carrera real: solo un cuerpo gana, otro 409; un expediente y ningún efecto parcial. Otros operadores/ámbitos siguen independientes | Barrera, resultados y huellas comparadas por booleanos, sin imprimir cuerpo/hash secreto |
| P6 misma revisión | 412 secuencial | Dos operadores con mismo ETag: primero cambia, segundo espera/relee y recibe 412; no pierde cambios | Orden de locks, resultados HTTP y revisión/historia finales |
| P7 seguimientos | Replay/fecha/nota/revisión | Misma clave + versión antigua retorna 412 antes del replay; reconciliación con revisión actual no duplica. Claves distintas con revisión obsoleta también 412 hasta decisión explícita | Historial ordenado, conteos y claves representadas por etiquetas ficticias |
| P8 rollback | Fallos inyectados de historia/auditoría | Fallo real o inyección controlada antes de commit revierte expediente/revisión/nota/reserva/auditoría; recuperación de unicidad solo fuera de transacción fallida | Estado previo/posterior y transacción cerrada; no continuar una sesión abortada |
| P9 permisos concurrentes | Revocación antes de operar | Sesión B revoca antes del chequeo fresco: A debe rechazar. B revoca después: medir resultado y acordar punto de corte, sin afirmar serialización existente | Barreras en ambos puntos, permiso fresco y commit/rechazo documentados |
| P10 servicio e historia | Retiro/renombrado secuencial/snapshot/FK | Creación privada vs rename/retiro; primera asociación vs cambios; FK de servicio protegido. Futura entrada pública vs retiro/caducidad/revocación al insertar | Snapshot coherente con orden serial observado; nunca borrado histórico ni alta pública con ficha no elegible al punto acordado |
| P11 responsables/usuarios | Permiso del responsable y SET NULL | Asignación concurrente con revocación/eliminación; observar ausencia de revisión comercial por SET NULL y decidir si contrato futuro la requiere | Resultado, FK y revisión; registrar limitación sin “arreglarla” en prueba |
| P12 contención/deadlock | No acredita locks | Alta bloquea servicio y después inserta; primera asociación bloquea expediente y luego servicio. Ensayar combinaciones e índices únicos; timeout/deadlock no debe duplicar ni activar retry automático | Orden, SQLSTATE 55P03/40P01 si ocurren, transacciones finalizadas; límites de espera del ensayo, sin ampliarlos para ocultar fallos |
| P13 restauración | Solo contrato/fakes | Backup sintético consistente del destino autorizado y restauración en el destino restore protegido; filas/FK/índices/revisiones/reservas/historia/auditoría y permisos comparados | Integridad de copia, inventario y comparación; procedimiento compatible con guardas antes de usar herramientas externas |
| P14 pérdida de confirmación | Simular UI/red y no retry | Interrumpir respuesta tras commit, conciliar misma intención sin duplicar; caída antes de commit revierte. Resultado ambiguo permanece ambiguo hasta evidencia suficiente | Punto de fallo, conteos, respuestas y ninguna retransmisión automática |
| P15 privacidad/borrado futuro | Marcadores sintéticos y flujo de derechos una vez implementado | Migración nueva de retención aceptada, limpieza coordinada, no recreación por replay, restauración que reaplica supresiones | Sin marcadores personales en copias operativas/logs/sesiones; reservas mínimas conforme a política aprobada |
| P16 guardas/proveedores | Dobles rechazan destino/HTTP indebidos | Todos los procesos concurrentes usan fábrica/conector protegido; marcador sobrevive; no conexión alternativa, roles ni correo/IA externos | Resultados de barreras y cierre de sesiones/servicios conforme al alcance autorizado |

P4–P14 requieren una base **realmente desechable autorizada** y varias sesiones cuando corresponda. SQLite acredita lógica secuencial, no esos locks, carreras, SQLSTATE o planes. `ConcurrentTestSession` es un apoyo protegido, pero revierte al cerrar; las pruebas que necesitan un segundo commit real requieren un arnés explícito revisado, sin añadir conexiones desprotegidas. Los casos existentes de concurrencia no acreditan ya cotizaciones. Restaurar mediante `psql/pg_restore` está restringido por el perfil: acordar un procedimiento protegido específico, no saltar la guarda.

### Pruebas de la futura recepción, separadas de la aceptación del backend actual

- SQLite: flag apagado sin tablas nuevas, entrada válida, contacto mínimo, campos internos/desconocidos rechazados, scalars/tamaños/UTF-8, servicio elegible/no elegible indistinguibles, aviso válido/versionado, error de esquema y rollback sin efectos externos.
- Idempotencia pública: token falso/caducado/operación distinta, replay mismo cuerpo sin repetir auditoría, cuerpo distinto, cambio de sesión, pérdida de respuesta, rotación de clave, purga de reserva con token vencido y retiro posterior del servicio. PostgreSQL debe repetir las carreras del ámbito anónimo, no solo las del operador privado.
- Seguridad/privacidad: CSRF **habilitado**, origen incorrecto, límites a intentos fallidos/replays y emisión de tokens, NAT/IPv6, caché caída, cuota global/cuerpo rechazado antes de costo alto. Inspeccionar respuestas, sesiones y logs con marcadores sintéticos; probar también Accept HTML o ausente para impedir flash accidental. No hay garantía antispam solo por un honeypot.
- Aislamiento: sin búsquedas públicas, no acceso a detalle/historial ni ETag/UUID del expediente, sin asignación de roles, sin correo/SMS/HTTP externo ni escrituras de lectura. Mantener pruebas privadas 19/19.1 y catálogos existentes intactas.
- Navegador desechable futuro: 390/768/1440, teclado/etiquetas/errores/consentimiento, doble clic, 422/419/429, timeout y pérdida de respuesta, borrador conservado, conciliación manual, sin almacenamiento/telemetría/red externa. Un fixture reconstruido por petición no prueba persistencia; añadir recorrido persistente solo en base descartable autorizada.

Conservar por ejecución: revisión de código y manifest de archivos, plataforma/versión real, identidad no secreta del destino, guardas/consentimiento, casos/tiempos/contadores/cronologías y resultado; artefactos sintéticos con acceso limitado fuera de Git. No cuerpos personales, contraseñas, cookies, tokens de intención, SQL con bindings ni dumps de datos reales. Primer fallo: clasificar y detener esa etapa, preservar evidencia; sin reparación/rollback destructivo/reintento de escritura automático.

## 10. Cambios futuros que requerirán autorización

Inventario orientativo, **no archivos creados ni autorización para editarlos**:

| Área | Archivos candidatos / alcance futuro |
| --- | --- |
| Entrada exclusiva | Nueva vista `resources/views/pages/solicitar-cotizacion.blade.php`, JS exclusivo `.mjs` y entrada en `vite.config.js`; rutas GET/POST separadas en `routes/web.php`. No sustituir contacto ni tocar principal/cursos/servicios sin nueva delimitación visual |
| HTTP y abuso | Nuevo `SolicitudCotizacionPublicaController`, request público, middleware de flag/errores y limiter nombrado en `AppServiceProvider`; eventual registro acotado en bootstrap. Ninguna relajación global de auth, CSRF o errores privados |
| Dominio/transacciones | Nuevo coordinador de recepción con adaptaciones mínimas y delimitadas a `GestionCotizaciones`, modelos y proyecciones privadas si se aprueban origen/snapshot/actor anónimo; conservar API y permisos existentes, sin duplicar todo el dominio |
| Esquema | Migración aditiva posterior a las existentes para origen/ámbito público, reserva idempotente, evidencia de aviso y actor; otra para retención/privacidad si el diseño lo exige. Verificar numeración al implementar; no editar 000027/000028 ni elegir un número que pueda colisionar con trabajo futuro |
| Control y claves | Nueva configuración independiente `config/recepcion-cotizaciones.php`, false por defecto; secretos solo servidor mediante gestión autorizada posterior. No reutilizar switches de catálogo o APP_KEY sin estrategia de rotación definida |
| Aviso y derechos | Aviso revisado con identidad/canal/plazos aprobados y mecanismo privado para tramitar derechos/retención. Cambiar `pages/legales/politicas-privacidad.blade.php` necesita autorización pública específica; copy de este informe no debe copiarse como política final |
| Selección de servicios | Resolver UUID puntual y revalidar elegibilidad; cambios al lector/editorial solo si estrictamente necesarios y delimitados previamente, preservando defaults y respuesta privada |
| Cursos opcionales | Nuevo UUID/mapeo/contrato público de cursos y relación histórica, solo con autorización separada; no es dependencia de una solicitud general/servicio |
| Pruebas/operación | Feature/frontend/smoke/fixtures exclusivos, arnés PostgreSQL protegido por lista de migraciones y recuperación/retención bajo alcance independiente |

## 11. Decisiones concretas del propietario antes de implementar/activar

1. ¿Primera versión de recepción para consulta general y servicios publicados, dejando cursos estructurados fuera? ¿Página exclusiva o cambio posterior del formulario de contacto? Nada se modifica todavía.
2. ¿Quién es responsable operativo de atender/reconciliar y quiénes tendrán lectura de todos los expedientes? Confirmar si basta acceso global actual o se requiere cartera. No repartir permisos por conveniencia.
3. ¿Qué identidad jurídica y canal efectivo de privacidad se aprueban, y quién valida base jurídica, aviso/manifestación y tratamiento de menores? Confirmar que marketing permanece excluido.
4. ¿Qué plazos se aprueban para expediente/notas, evidencia de aviso, señales/logs, backups y reservas, con qué evento de inicio y excepciones? Las opciones 90/180, 7/30 y 30/90 no están seleccionadas.
5. ¿Quién tramita acceso/rectificación/eliminación, cómo verifica identidad y qué operación nueva se autoriza para historia/auditoría/reservas sin perder integridad? No existe mecanismo de privacidad completo hoy.
6. ¿Se aprueba el contrato sin UUID/código/consulta pública y con conciliación manual del mismo token? Definir duración de intención/replay y canal humano si la intención vence con resultado incierto.
7. ¿Qué capacidad, cuotas y contingencia antispam son aceptables para usuarios de una red compartida? Acordar criterios medibles y operación ante abuso. Proveedores de CAPTCHA u otros siguen fuera del alcance.
8. ¿Qué criterio de revocación concurrente se exige y qué ventana residual se acepta? ¿Quién autoriza después recuperación/S7, destino desechable, lista de migraciones sin 000022 y pruebas PostgreSQL? Son autorizaciones distintas de construir frontend.

No es necesario resolverlas para entregar este análisis. Sí deben quedar respondidas por escrito antes de sus implementaciones dependientes, y aceptación PostgreSQL/privacidad/operación antes de recibir datos reales. Ninguna respuesta se presume por el paso del tiempo o por la existencia de tablas.

## 12. Validación y cierre documental

La evidencia funcional de Sprint 19.1 permanece **histórica**: 1064 pruebas PHP/7361 aserciones en SQLite, 36 frontend, Larastan nivel 6, build/Pint y smoke reportados. No se repiten suites, build, Pint, Larastan o navegador porque solo cambia documentación. No se declara validada la recepción pública ni la concurrencia PostgreSQL.

Validación de esta fase: contraste estático de contratos y hallazgos con los archivos anteriores, revisión de enlaces locales del informe, blancos/final de archivo nuevo, `git diff --check`, comparación SHA-256 con inventario inicial y verificación de rama/HEAD/índice/stashes. Las consultas a OWASP son referencias de diseño; la ficha normativa localizada requiere revisión jurídica posterior. Ningún dato personal real, secreto o registro de sesión se incorpora al informe.

La preparación queda lista para revisión; los riesgos están identificados, **no corregidos ni aceptados operativamente** por documentarlos. Recepción pública, política final y retención no están habilitadas. Se mantienen los valores declarados: catálogos web/PHP y Advisor `false`, verificación `false`/mailer `null`, envío de matrícula predeterminado `false` dependiente del entorno. No se leyó ni modificó el valor efectivo del entorno.

Comprobaciones de cierre: `git diff --check` aprobado (avisos LF/CRLF conocidos en archivos previos); enlaces locales del informe existentes, sin blancos finales ni errores de EOF; revisión de patrones de claves privadas/tokens de proveedor/URI con credenciales sin coincidencias en los dos documentos propios. Esto último es una comprobación acotada, no un escaneo de secretos de todo el repositorio.

Sobre los 629 archivos iniciales: **628 idénticos**, solo cambia el contexto y se agrega este informe; ninguno eliminado. Al excluir las dos filas ajustadas y los dos párrafos nuevos, el contexto recupera el SHA-256 inicial con sus finales CRLF, preservando todo el contenido previo, incluidas las secciones PostgreSQL/migraciones. El informe PostgreSQL conserva su hash. Rama/HEAD, índice vacío y ambos hashes de stashes permanecen intactos. Los archivos ignorados se dejaron sin intervención; no se verifica su contenido como parte de esta entrega.
