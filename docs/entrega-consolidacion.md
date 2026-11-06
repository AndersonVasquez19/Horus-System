# Entrega técnica de consolidación — 05/11/2026

## Informe consolidado vigente

Corte documental: **05/11/2026, 18:17, America/Lima**. Esta sección resume el estado vigente y
prevalece sobre los estados intermedios conservados más abajo. No constituye una nueva auditoría
general ni autorización para ejecutar los comandos históricos. Las pruebas indicadas como
antecedentes no se repitieron durante esta actualización documental.

### Resultado de la etapa

La consolidación local autorizada está registrada en **17 commits nuevos**, sin reescribir los
24 commits anteriores. Horus AI está habilitado y responde en el entorno local; las migraciones
editorial y de auditoría fueron aplicadas previamente bajo autorización. **La entrega todavía
no está sincronizada**, y la aceptación de producción, privacidad independiente y restauración
ensayada no se consideran completadas.

| Área | Resultado y límite |
| --- | --- |
| Servicios | Editor y contenido editorial consolidados; restricción de orden probada en SQLite y PostgreSQL e instalada localmente. No se publicaron fichas automáticamente. |
| Reclamaciones | Libro y gestión administrativa consolidados; errores de integridad específicos y protecciones conservados. D9 permanece fuera de ejecución. |
| Administración e integración | Responsables, consultas del panel y contratos compartidos incorporados. Política 403 del coordinador sobre administradores preservada. |
| Horus AI | Motor Python, privacidad, catálogo firmado, cliente Laravel, worker, búsqueda semántica, contingencia y supervisor local consolidados. Python recibe contenido público firmado; no consulta datos operativos privados. |
| Migraciones | 31 registradas en la instalación local; 000022 es la única pendiente y continúa suspendida. 000023 preexistente se conservó. |
| Dependencias Python | requirements.txt incorporado; reutiliza el bloqueo completo de 59 paquetes con versiones y hashes, incluido PyTorch CPU. El modelo se descarga por separado. |
| Entrega | Responsabilidades iniciales documentadas; no se afirma recepción o aceptación formal de los compañeros. |

### Comprobaciones realizadas para este informe

Estas son verificaciones nuevas de estado, **no una repetición de las suites**:

- Rama anderson; HEAD **e5dfe127deee74292beadff60349117f1b3fab41**.
- Índice vacío al comenzar; dos stashes preservados, con hashes idénticos a los de la consolidación.
- Git confirma 17 commits desde 7914438d59b77b6a5e09a15d371e635cca2d894d y **202 rutas distintas**
  consolidadas en ese bloque. Las adiciones posteriores al primer bloque de 199 rutas son el helper
  del supervisor, su prueba y requirements.txt. No es un recuento del inventario físico inicial.
- Respecto de la referencia local origin/anderson: **41 delante, 0 detrás**. No se consultó de nuevo
  el servidor remoto en esta actualización; la última consulta real documentada observó
  306b168e8d8bbfec23301d7c050c7f7c9ae11d2f. No hubo fetch ni push.
- `php artisan horus:advisor-operacion status --probe --queue`: enabled=true, ready=true, errors=[],
  health correcto, knowledge/semantic/catalog loaded, autenticación comprobada, versión coincidente,
  worker vivo, pending_queue_total=0 y failed_catalog_jobs=0.
- `php scripts/horus-local.php inspect --runtime=<directorio privado existente>`: cero fichas
  exportables, cero trabajos pendientes y únicamente 2026_10_28_000022_create_registros_pendientes_table
  pendiente. Esta consulta no ejecuta migraciones ni acredita nuevamente toda la integridad física.
- Los cambios concurrentes preexistentes permanecen fuera del alcance. No se declara limpio todo
  el árbol de trabajo. Esta actualización modifica exclusivamente el informe y PROJECT_CONTEXT.md;
  queda sin staging ni commit hasta una autorización posterior.

### Migraciones, respaldo y reversión

| Migración | Decisión y estado final documentado |
| --- | --- |
| 2026_10_28_000022_create_registros_pendientes_table | Suspendida expresamente. No ejecutar ni revertir. Pendiente en la instalación local. |
| 000023 | Transformación irreversible excluida de las ejecuciones de consolidación. Ya estaba registrada en batch 4 en la instalación local; se conservó sin volver a ejecutar ni revertir. |
| 2026_11_02_000033_add_contenido_to_servicio_publicaciones | Probada aisladamente; aplicada previamente en horus_group, batch 6. CHECK orden 0..9999, variante, FK RESTRICT e índice verificados entonces. |
| 2026_11_03_000034_add_fuente_to_chat_logs | Probada aisladamente; aplicada previamente en horus_group, batch 6. fuente nullable varchar(16), sin atribuir fuentes a históricos. |

Destino local acordado: PostgreSQL 18.6, horus_group, 127.0.0.1:5432, identidad física
7701086120026667920 verificada durante la aplicación. Destino de pruebas: horus_l2_test,
127.0.0.1:55433, identidad 7702263743609677164. Las pruebas reversibles utilizaron fixtures
sintéticos, transacciones exteriores y comparación de esquema, secuencias y datos preexistentes.
No se ejecutaron D9, db:wipe ni migrate:fresh durante esta consolidación.

El respaldo privado previo a 000033/000034 conserva su ubicación y SHA-256 en el acta de aplicación
incluida más abajo. Se comprobó su decodificación con pg_restore sin ejecutar SQL; **no se ensayó
una restauración real**. down() elimina contenido editorial o metadatos incorporados después:
tener ese método no garantiza recuperar datos. Cualquier recuperación persistente requiere
destino, respaldo y autorización específicos; no se propone rollback automático.

### Evidencia de validación acumulada

Todas las ejecuciones siguientes son **resultados anteriores a este corte documental**. No se
suman como una única suite: incluyen entornos y selecciones diferentes, con posibles solapamientos.

| Evidencia | Resultado registrado | Alcance y referencia |
| --- | --- | --- |
| Fase 4 inicial | 113 pruebas / 404 aserciones | Guardas con dobles y predicado en SQLite; no era ejecución PostgreSQL. docs/consolidacion-fase-4-postgresql.md. |
| Integridad PostgreSQL controlada | 8 pruebas / 105 aserciones | Bloqueo real 55P03, 403 del coordinador, integridad académica, reclamaciones/notificaciones y preservación del estado previo. Acta inferior. |
| DDL 000033/000034 aislado | 2 pruebas / 45 aserciones | up/down, CHECK, metadatos y registros sintéticos de migrations revertidos; comprobaciones posteriores coincidentes. Acta inferior. |
| Regresión SQLite de consolidación | 3 pruebas / 31 aserciones | Instalador explícito en memoria, sin reconstrucción global ni migraciones excluidas. Acta inferior. |
| Integración real de AI con fixtures | 16 comprobaciones | Laravel en SQLite, publicaciones sintéticas, worker, firma, FastAPI/MiniLM, recomendaciones, retirada y privacidad. No demuestra publicaciones reales. |
| OperacionHorusTest | 11 pruebas / 42 aserciones | Aislamiento de PHPUnit frente a los flags del entorno local; HTTP simulado y sin migraciones. |
| LocalCatalogRenewalTest | 3 pruebas / 11 aserciones | Timeout real de control, supervivencia de proceso sano, ausencia de reintento inmediato y tolerancia al fallo del registro. |
| Chat y navegador locales | Verificación aprobada | Laravel/PostgreSQL → FastAPI real; fuente advisor, cero HTTP para entrada privada, auditoría redactada, contingencia y Edge móvil 390x844/Escape. Acta inferior. |
| Dependencias | 59 paquetes interpretados por pip | Versiones/hashes y dependencias principales presentes; no equivale a instalación limpia en otro equipo. |
| Calidad acotada | Sintaxis, Pint, Ruff y diff aprobados en sus ajustes | Sin reformateo global. PHPStan y compilación no se repitieron por cambios de documentación o creación de commits. |

Los resultados generales anteriores —por ejemplo, fase E con 3.401 pruebas PHP aprobadas/una
omitida y 234 Python— permanecen en PROJECT_CONTEXT.md como evidencia histórica. No certifican
el árbol actual con cambios concurrentes. No se declara aprobada una suite PostgreSQL completa.

### Operación local y corrección final

Aplicación: **http://127.0.0.1:18080**. FastAPI escucha en 127.0.0.1:18081; MiniLM funciona en CPU
con modelo local. El gestor scripts/horus-local.php supervisa API, web y worker y renueva únicamente
el catálogo cada 120 segundos. No instala servicios de Windows ni arranque automático al reiniciar.
Los secretos, entornos, logs y respaldos permanecen fuera de Git. No se activaron correos reales,
OpenAI ni acceso público a Internet.

Después del primer arranque se detectó que un error de control de renovación podía cerrar los
procesos sanos. Se corrigió y probó sin ampliar timeouts ni desactivar caducidad. Con autorización
específica se liberó por propietario el único lock abandonado, tras verificar ausencia de procesos,
identidad y pertenencia temporal. La recuperación y renovación posterior quedaron comprobadas;
el estado operativo se consultó nuevamente para este informe. El detalle se conserva al final.

El catálogo exportable sigue vacío. El funcionamiento del asistente institucional está comprobado,
pero las tarjetas con oferta real requieren fichas publicadas y elegibles por decisión editorial.
La aceptación independiente de privacidad sigue pendiente y no se sustituyó por fixtures ni por
las pruebas locales; no se accedió a los seis archivos restringidos.

Para los compañeros, desde chatbot-python:

```powershell
py -3.13 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
.\.venv\Scripts\python.exe -m pip check
.\.venv\Scripts\python.exe scripts\download_model.py
```

La descarga del modelo solo es necesaria si falta localmente. Paquetes y descarga inicial requieren
Internet. La configuración de secretos y el arranque son pasos distintos; consultar README.md del
componente y docs/horus-ai-operacion.md. No copiar perfiles privados del equipo de entrega a Git.

### Git y responsables

El bloque original de 15 commits/199 rutas está detallado en la tabla histórica y en
entrega-consolidacion-commits.json. Sus dos continuaciones autorizadas son:

| Commit | Contenido | Archivos |
| --- | --- | ---: |
| 79b7370 | Cierre documental del bloque original | 3 |
| add5779 | Aislar fallos de renovación del supervisor | 5 |
| e5dfe12 | Facilitar instalación de dependencias del chatbot | 1 |

79b7370 ya forma parte de los primeros 15; no es un commit adicional al total de 17.
El JSON conserva su corte histórico; este informe registra las continuaciones posteriores.
Se usaron rutas explícitas, comparación de blobs y diff del índice antes de cada commit.
DB_PASSWORD está vacío en la versión vigente de .env.example; ello **no borra valores del historial
ni equivale a rotación de credenciales**. Revisar ese riesgo antes de autorizar sincronización o
ampliar acceso. No se reescribió historia ni se incorporaron entornos reales o respaldos.

| Responsable | Responsabilidad inicial y continuación de entrega |
| --- | --- |
| Carlos Manuel Castope Izquierdo | Administración, reclamaciones, procesos internos, aprobaciones y notificaciones; verificar su entorno y aceptar los flujos de su área. |
| Cristopher Pulache Minchan | Académica y comercial: cursos, servicios, cotizaciones, convocatorias y preinscripciones; verificar su entorno y coordinar la publicación editorial autorizada. |
| Propietario | Coordinación, integración, supervisión y autorización de sincronización; resolver aceptación y prioridades con ambos compañeros. |

Esta distribución fue aprobada como propuesta inicial ajustable. No se enviaron comunicaciones ni
se presume aceptación formal de tareas adicionales.

### Pendientes concretos de entrega

1. Revisar e incorporar esta actualización documental cuando se autorice su commit; el commit
   anterior no autoriza automáticamente commits nuevos ni push.
2. Antes de sincronizar, verificar el remoto en solo lectura y resolver el riesgo de credenciales
   históricas; pedir autorización expresa de push. No forzar ni reescribir la historia por defecto.
3. Instalar el componente en los equipos de los compañeros y comprobar pip check, modelo local,
   configuración privada y readiness. La interpretación local del archivo no prueba esos equipos.
4. Mantener 000022 suspendida. No repetir 000023 ni revertir registros existentes.
5. Acordar publicaciones reales y aceptación independiente de privacidad antes de exposición
   pública. La activación local no autoriza despliegue ni configuración de VPS.
6. Ensayar restauración en destino aislado solo con autorización y formalizar recepción de entrega.
   El caso de último administrador con degradación de la cuenta preexistente sigue pendiente;
   no se modificó esa cuenta para obtener un resultado verde.

No se declara cierre total, sincronización ni ausencia universal de errores. Las comprobaciones
aprobadas se conservan cerradas dentro de sus alcances; los puntos anteriores son límites de
entrega concretos y no una propuesta de nueva auditoría general.

## Actas históricas de ejecución

Lo que sigue conserva la secuencia de decisiones, resultados y autorizaciones. Frases como
«sin commits», «AI apagado» o «migraciones pendientes» describen su etapa original; no sustituyen
el corte vigente anterior.

Entorno local operativo. 000033/000034 aplicadas y verificadas en horus_group; Horus AI activado
localmente, con procesos y renovación comprobados. Commits locales autorizados y consolidados;
push pendiente. 000022 permanece suspendida por ratificación del propietario.

## Resultados nuevos

- Identidad real verificada con la sonda protegida: horus_l2_test, 127.0.0.1:55433,
  horus_l2_test_runner, horus_pgt_55433, system_identifier 7702263743609677164.
- El clúster estaba detenido; se arrancó el existente, sin aprovisionamiento.
- Se encontraron 000022 y 000023 ya registradas y una cuenta existente. El propietario autorizó
  expresamente conservar ese estado y ejecutar únicamente pruebas reversibles. No se ejecutaron,
  modificaron ni revirtieron esas migraciones.
- Integridad PostgreSQL: 8 pruebas y 105 aserciones aprobadas. Incluye bloqueo real 55P03 y
  respuesta 403 al coordinador sobre el administrador existente; cinco escenarios de integridad
  académica; RESTRICT 23001 frente a referencia inexistente 23503; notificaciones, CHECK,
  inmutabilidad, privacidad y SET NULL. El primer intento académico falló por faltar descripción
  en el fixture; se corrigió exclusivamente el fixture y la selección completa pasó.
- DDL PostgreSQL aprobado: 2 pruebas y 45 aserciones aprobadas. 000033 comprueba orden -1/10000
  rechazados en INSERT y UPDATE, límites válidos 0/9999, contenido editorial real, retirada y down.
  000034 comprueba históricos NULL, metadato fuente, redacción y down. Se añadieron registros
  sintéticos de migrations dentro de cada transacción; también se revirtieron.
- SQLite :memory:: 3 pruebas y 31 aserciones aprobadas, reutilizando las regresiones existentes
  de ServiciosContenidoTest y HorusAiTransicionTest mediante un instalador explícito. No usa
  migrate:fresh y excluye 000022/000023. Incluye 000033/000034 por autorización de pruebas aisladas.
- Pint acotado y Ruff del arnés nuevos aprobados. Las 113/404 de la entrega anterior no se repitieron.

## Garantías y límites

La cápsula PostgreSQL no incorpora RefreshDatabase, DatabaseMigrations ni DatabaseTruncation.
Cada escenario abre una transacción exterior y la revierte. Los comandos Artisan se rechazan.
Los escenarios de integridad rechazan DDL; 000033/000034 están en un archivo separado autorizado.
Se usan IDs negativos explícitos para evitar nextval de secuencias preexistentes.

Antes y después de cada escenario se comparan todas las tablas public mediante resúmenes calculados
en el servidor, el catálogo de tablas/columnas/restricciones/índices/triggers/funciones y el estado
de todas las secuencias public. Las filas y secretos preexistentes no se exportan. Todas las
comparaciones posteriores pasaron, incluida migrations y la cuenta existente.

El caso original de último administrador después de degradación confirmada permanece pendiente:
no se elimina ni degrada la cuenta existente para reproducirlo. La prueba nueva acredita bloqueo
y 403 con preservación del administrador, no ese escenario adicional.

No hubo db:wipe, migrate:fresh, D9 ni migraciones sobre la base principal. Los tests de DDL llaman
up/down solamente dentro de transacciones verificadas del destino aislado; no son aplicación persistente.
Las secuencias de las tablas nuevas desaparecen con el rollback; las preexistentes permanecen intactas.

## Comandos reproducibles

```powershell
php scripts/consolidacion-postgres.php inspect
php scripts/consolidacion-postgres.php test --execute
php scripts/consolidacion-postgres.php ddl --execute
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --do-not-cache-result tests/ConsolidacionSqlite --filter='test_la_base_rechaza_orden_fuera_de_rango_en_insert_y_update|test_additive_source_migration_preserves_old_rows_and_unmigrated_chat_works'
chatbot-python/.venv/Scripts/python.exe tests/Support/run-consolidacion-horus-ai.py
```

El último comando es una integración nueva de exportador real, catálogo publicado sintético,
worker database, firma, FastAPI/MiniLM offline, recomendaciones, privacidad y retirada.
Resultado final: **16 comprobaciones aprobadas**, con marcador CONSOLIDACION_OK verificado,
curso y servicio publicados, cero HTTP para la entrada privada, auditoría redactada y retirada
sincronizada de ambos. FastAPI temporal quedó detenido. Evidencia de esta ejecución:
`C:\Users\PCT40T\AppData\Local\Temp\horus-consolidacion-real-vq1rf6_j\integracion.txt`.

Se corrigieron defectos del propio arnés: fixture editorial que intentaba asignar campos protegidos
mediante fillable y detección de errores de Artisan que pueden devolver código cero; ahora se
requiere un marcador final además del exit code. Los fixtures usan forceCreate explícito solo en
SQLite memoria; no se modificó la protección de los modelos de producción. Los timeouts iniciales
se resolvieron impidiendo que las conexiones loopback heredasen proxies externos del proceso,
sin ampliar tiempos ni tocar entornos persistentes. Se usó un puerto temporal libre; no se alteró
el proceso ajeno que ya ocupaba 8001.

Las 53 comprobaciones anteriores, UI/Edge, suite PHP completa, Python y JavaScript conservan su
carácter histórico; no se repitieron bloques aprobados sin cambios. Esta ejecución usa SQLite
memoria para Laravel, no horus_group ni PostgreSQL para el worker. No demuestra activación local
permanente, UI nueva ni aceptación independiente de privacidad. No se sirvió tráfico de visitantes.

Sintaxis de los ocho PHP nuevos, Pint acotado y Ruff del Python nuevo: aprobados. No hubo cambios
en app/Support, app/Services ni interfaz; no se repitieron PHPStan, build o suites completas.

## Migraciones y destino local

- 000022: suspensión conservada; no tocar instalaciones existentes.
- 000023: excluida por decisión del propietario; no revertir instalaciones existentes.
- 000033 y 000034: pruebas aisladas aprobadas; aplicación principal posteriormente autorizada,
  ejecutada y verificada en lote 6 (ver cierre de aplicación al final).
- Destino definitivo confirmado: horus_group, PostgreSQL 18, 127.0.0.1:5432.
- Acceso posterior autorizado: consulta de identidad/esquema y aplicación exclusiva de 000033/000034.
  No se modificaron archivos de entorno.

## Commits y entrega

La propuesta exacta de rutas, hashes y grupos está en entrega-consolidacion-commits.json. No es
autorización de staging ni de commit. Los cambios preexistentes conservan su autoría sin atribuirla
por el autor de un commit. Se separan módulos y la integración compartida; entorno y corpus de
privacidad se excluyen de la propuesta. No se incorpora automáticamente el inventario completo.
Los 24 commits conservan su historia y orden. No hubo commits nuevos, push, reset ni cambios de stash.
DB_PASSWORD de .env.example sigue pendiente de saneamiento autorizado; no se imprime su valor.

Distribución inicial confirmada por el propietario:

| Responsable | Entrega |
| --- | --- |
| Carlos Manuel Castope Izquierdo | Gestión administrativa, reclamaciones, procesos internos, aprobaciones y notificaciones. |
| Cristopher Pulache Minchan | Gestión académica y comercial, cursos, servicios, cotizaciones, convocatorias y preinscripciones. |
| Propietario | Coordinación general, integración y supervisión. |

No se enviaron comunicaciones a los colaboradores. La distribución podrá ajustarse de común acuerdo.

## Acciones sensibles todavía reservadas

Aplicar la migración suspendida, crear commits después de revisar su contenido y realizar push
requieren sus autorizaciones específicas. La activación local y los dos perfiles de entorno fueron
posteriormente autorizados y configurados (sección de operación local). La privacidad
independiente conserva su evidencia pendiente; no se leyeron los seis archivos restringidos.

## Propuesta concreta para los archivos de esta continuación

Estos commits deben ir después de incorporar sus dependencias preexistentes aprobadas; las nuevas
pruebas consumen código y migraciones que todavía están sin commit. No se presentan como paquetes
independientes del resto del árbol de trabajo.

1. `test(postgresql): validar consolidación sin reconstruir la base`: scripts/consolidacion-postgres.php,
   phpunit.pgsql.fase4.xml y los tres archivos de tests/ConsolidacionPostgres/.
2. `test(migraciones): verificar editor y auditoría en memoria`: tests/Support/InstalaMigracionesEnMemoria.php
   y los dos archivos de tests/ConsolidacionSqlite/.
3. `test(horus-ai): integrar catálogo editorial y worker local`: tests/Support/consolidacion-horus-ai.php
   y tests/Support/run-consolidacion-horus-ai.py.
4. `docs(entrega): registrar validación y responsables`: PROJECT_CONTEXT.md, este informe y el JSON de commits.

Cada ruta y hash revisable está en el JSON. Los grupos de cambios anteriores son una propuesta de
organización, no atribución de autoría ni autorización para incluir trabajo de colaboradores.

## Aplicación local autorizada de 000033 y 000034 — 05/11/2026

Destino confirmado antes de escribir y nuevamente en la misma conexión: PostgreSQL 18.6,
horus_group en 127.0.0.1:5432, system_identifier 7701086120026667920. La sesión comenzó en solo lectura.
El propietario autorizó expresamente respaldo privado y aplicación exclusiva de ambas migraciones.

Respaldo completo en formato custom, fuera del repositorio:

`C:\Users\PCT40T\AppData\Local\HorusBackups\horus_group\20261105-164259\horus_group-pre-000033-000034.dump`

SHA-256: `6b52c8fc5b7e0f4e19ac094e6379255b7c598e5397b721387be70cc4766653f2`.
Directorio con herencia de permisos desactivada, acceso únicamente del usuario y SYSTEM.
pg_dump 18.6 terminó correctamente; pg_restore 18.6 leyó y descomprimió todo hacia NUL sin conexión
ni ejecución de SQL. Esto verifica la lectura del archivo, no equivale a una restauración ensayada.
El archivo contiene datos privados y no debe incorporarse a Git ni enviarse a servicios externos.
Junto al respaldo quedan su SHA-256 y resultado-aplicacion.json, sin credenciales.

Se ejecutó scripts/aplicar-editorial-auditoria.php con --execute y el directorio privado.
El ejecutor fija destino, identidad física y los dos nombres/hashes del manifiesto ya probado;
rechaza esquemas parciales, migraciones ya registradas, configuración cacheada y destinos alternativos.
Después del respaldo se ejecutaron ambas mediante el migrador Laravel dentro de una transacción
exterior única, con bloqueo de escritura sobre las tablas preexistentes durante la comprobación.

Resultado confirmado: ambas registradas en **batch 6**, 31 migraciones registradas en total.
000022 continúa ausente; 000023 conserva su registro anterior en batch 4 y no se ejecutó ni revirtió.
No hubo seeders, publicación automática, D9, db:wipe ni migrate:fresh.

Comprobaciones antes del commit y mediante una conexión nueva posterior:

- Tabla servicio_publicacion_elementos creada vacía; tres columnas editoriales nullable instaladas.
- CHECK servicio_elementos_orden_rango: orden >= 0 AND orden <= 9999, validado y aplicado.
- CHECK de variante, FK RESTRICT hacia servicio_publicaciones e índice servicio_elementos_visualizacion presentes.
- chat_logs.fuente: varchar(16), nullable, sin atribución de fuente a históricos.
- Resúmenes calculados en el servidor sobre las columnas originales de todas las tablas preexistentes
  coincidentes antes/después, incluida la cuenta y el historial anterior de migrations.
- Secuencias preexistentes sin cambios salvo la de migrations, necesaria para registrar las dos altas.
- Los archivos originales de 000033/000034 conservan exactamente sus hashes probados.

No se insertaron fixtures en la base principal. No se modificó .env ni se activó Horus AI.
Sin commits Git, push o cambios de stash. Se mantiene pendiente la aceptación de activación local
y la creación/sincronización de commits según las autorizaciones anteriores.

## Horus AI local activado y verificado — 05/11/2026

Autorización posterior del propietario: configurar .env y chatbot-python/.env, concordar credenciales,
arrancar FastAPI/worker locales y habilitar el chat solo después del preflight. El propietario ratificó
**no migrar 000022**. Las otras 31 migraciones están aplicadas; no se ejecutó ninguna migración en esta etapa.

- Aplicación local: **http://127.0.0.1:18080**.
- FastAPI: 127.0.0.1:18081, una instancia, MiniLM CPU offline, firma obligatoria.
- Base real de Laravel y de la cola: horus_group, PostgreSQL 18.6, 127.0.0.1:5432.
- Gestor propio: scripts/horus-local.php; PID 14032 al verificar. Hijos registrados: FastAPI 29440,
  worker 27452 y servidor local 17016. No se sustituyeron procesos existentes.
- Directorio privado de operación:
  `C:\Users\PCT40T\AppData\Local\HorusAI\horus-group-local-20261105-165916`.
  Contiene respaldos de ambos entornos, snapshot firmado, caché de vectores, estado y logs redactados.
  Permisos limitados al usuario y SYSTEM; ningún perfil ni respaldo se incorpora a Git.

Primero se mantuvo Advisor apagado mientras el proceso operativo sincronizaba. Solo después de obtener
ready=true, worker vivo, MiniLM loaded, catálogo loaded y versión coincidente se persistió
HORUS_ADVISOR_ENABLED=true. Los tokens permanecen en servidor; Python no recibe DB_*.
Se compararon ambos entornos con sus respaldos: todas las claves ajenas a la configuración autorizada
de Horus AI permanecen iguales, incluidas identidad de base y APP_KEY. .env.example no se modificó.

El gestor renueva exclusivamente el catálogo cada 120 segundos y reutiliza el worker existente.
No se lanzó el programador general de tareas, no se activó verificación de correo ni se enviaron correos.
La renovación se verificó con un snapshot generado después del arranque y firma válida.

Resultados reales sobre PostgreSQL y servicios locales:

- Preflight: enabled=true, errors=[], ready=true; health correcto, auth configured,
  knowledge/semantic/catalog loaded, versión coincidente y autenticación comprobada.
- Worker database vivo; pending_queue_total=0 y failed_catalog_jobs=0.
- scripts/horus-local.php verify: respuesta advisor por HTTP real, cero HTTP para la entrada privada,
  auditoría [contenido omitido por privacidad] con fuente respaldo y contingencia comprobada.
- tests/browser/horus-local-smoke.mjs: Edge real sobre la aplicación y base locales, HTTP 200 con fuente
  advisor, apertura, vista móvil 390x844 y Escape aprobados; destinos externos bloqueados en el smoke.
- El catálogo vigente exportable contiene **0 fichas**. No se publicaron ni inventaron cursos o servicios.
  El asistente mantiene el conocimiento institucional verificado; las tarjetas editoriales requieren
  fichas publicadas y elegibles. Su integración con fichas sintéticas ya tenía evidencia independiente
  de este arranque (16 comprobaciones); no se presentan como publicaciones reales.

Defectos reproducidos y corregidos en esta etapa:

1. El gestor nuevo imponía 60 s al arranque completo. Medición real: PyTorch 13,26 s,
   sentence-transformers 112,22 s, pesos 5,74 s y codificación 0,37 s (131,6 s total).
   Se corrigió únicamente su presupuesto de arranque a 180 s; error explícito del modelo o salida del
   proceso abortan inmediatamente. No cambiaron límites HTTP, bloqueos, cola ni restricciones de datos.
2. El smoke nuevo no capturaba de forma fiable la respuesta sustituyendo fetch. Se reemplazó esa
   instrumentación por observación Network de DevTools; las mismas aserciones HTTP 200/advisor pasaron.
   No se modificó el código de la interfaz para satisfacer la prueba.
3. PHPUnit heredaba la activación local de .env: se reprodujo un fallo en dos pruebas operativas
   (una falló). phpunit.xml ahora fija flags apagados, URL de prueba y credenciales vacías solo para
   pruebas; los tests siguen pudiendo simular habilitación mediante config(). Regresión OperacionHorusTest:
   **11 pruebas y 42 aserciones aprobadas**, sin migraciones ni HTTP no simulado.

Sintaxis PHP, Pint de scripts propios y sintaxis Node aprobados. Los procesos locales siguen activos;
no se instalaron servicios de Windows ni arranque automático al reiniciar el equipo. Para consultar:

```powershell
php artisan horus:advisor-operacion status --probe --queue
php scripts/horus-local.php inspect --runtime=C:\Users\PCT40T\AppData\Local\HorusAI\horus-group-local-20261105-165916
```

El gestor se puede ejecutar desde el repositorio con `run` y ese mismo --runtime, después de comprobar
ausencia de otra instancia y el estado de sus locks. No borrar locks globalmente ni duplicar consumidores.
La aceptación independiente de privacidad sigue siendo distinta de estas pruebas locales: no se accedió
a los seis archivos restringidos ni se declara autorización para exposición pública o despliegue.

Rama anderson, HEAD 7914438d59b77b6a5e09a15d371e635cca2d894d, índice vacío, 24 commits locales y dos
stashes conservados. En esa etapa no se crearon commits ni se hizo push. La autorización operativa no los sustituye.

## Consolidación local en commits — 05/11/2026

El propietario autorizó vaciar únicamente DB_PASSWORD de .env.example y crear los commits temáticos,
sin push. Se verificó el manifiesto frente al contenido actual antes de staging; las exclusiones se
aplicaron también al índice. El saneamiento conservó las demás variables del archivo de ejemplo.

Se consolidaron **199 rutas autorizadas en 15 commits locales**: los 14 siguientes contienen 196 rutas;
el commit final de documentación contiene PROJECT_CONTEXT.md, este informe y el manifiesto actualizado.
El hash de ese último commit se obtiene con git log -1; no se incluye dentro de su propio contenido.

| Commit | Contenido | Rutas |
| --- | --- | ---: |
| `3606908` | fix(seguridad): retirar contraseña del archivo de ejemplo | 1 |
| `e4694a2` | feat(servicios): consolidar editor y contenido editorial | 30 |
| `84580fa` | feat(reclamaciones): consolidar libro y gestión administrativa | 19 |
| `ee0b9fd` | fix(administracion): consolidar responsables y consultas del panel | 6 |
| `db16a2a` | feat(horus-ai): consolidar motor Python y búsqueda semántica | 29 |
| `6f7f3cd` | fix(privacidad): consolidar filtros y léxico público | 22 |
| `f82adb6` | feat(horus-ai): consolidar catálogo firmado y cliente Laravel | 50 |
| `66a1698` | feat(integracion): consolidar flujos y contratos compartidos | 22 |
| `a320216` | test(postgresql): validar consolidación sin reconstruir la base | 7 |
| `ee7c870` | test(migraciones): verificar editor y auditoría en memoria | 3 |
| `5d5a1e7` | test(horus-ai): integrar catálogo editorial y worker local | 2 |
| `d166943` | chore(migraciones): preparar aplicación local controlada | 1 |
| `6d65b96` | chore(horus-ai): habilitar operación local supervisada | 3 |
| `f39b924` | test(configuracion): aislar PHPUnit de la activación local | 1 |

El último commit se titula `docs(entrega): registrar validación y responsables`.
Los 24 commits anteriores se conservaron como ancestros del bloque, sin rebase, reset ni reescritura.
Rama anderson; índice vacío al cerrar y dos stashes conservados. No hubo push ni fetch.
Consulta remota de solo lectura: origin/anderson seguía en
`306b168e8d8bbfec23301d7c050c7f7c9ae11d2f`; el bloque deja 39 commits locales por sincronizar.

Comprobaciones de esta operación: 198 archivos presentes revisados para credenciales conocidas y
patrones de tokens, cero hallazgos; una eliminación ya presente en el inventario consolidada;
listas cerradas de rutas, SHA-256 antes de staging, igualdad de blobs del índice con los archivos
revisados y git diff --cached --check antes de cada commit. Los seis archivos restringidos de
privacidad no se leyeron ni incorporaron; tampoco entornos reales o respaldos externos.

Se corrigió exclusivamente una línea vacía sobrante al EOF de
chatbot-python/tests/test_catalog_sync.py cuando la detectó Git. No cambió el código de las pruebas;
no se repitieron suites ya aprobadas por ese ajuste de formato ni por la creación de commits.
Las evidencias funcionales anteriores conservan sus fechas y alcances.

DB_PASSWORD queda vacío en la versión actual del ejemplo; esta operación no elimina contenidos
históricos de Git ni rota credenciales de servicios. Los perfiles locales autorizados y los procesos
de Horus AI se mantuvieron operativos. Solo queda autorización de push para sincronizar este bloque;
no se declara la entrega sincronizada mientras esa operación no se realice.

## Recuperación del supervisor local — 05/11/2026

Una comprobación posterior encontró detenidos los servicios del primer arranque. El registro
conservado solo indicaba un error técnico genérico; su horario coincide con el límite de 30 s
del control de renovación, pero no permite afirmar la clase exacta de aquella excepción.
El defecto comprobado en código era que un fallo de ese control alcanzaba el finally global
y cerraba también los procesos sanos.

Se aisló exclusivamente la renovación periódica en scripts/Support/LocalCatalogRenewal.php.
Un fallo registra tipo de excepción, evento y fecha, sin mensajes privados; conserva API, web
y worker, sin reintento inmediato ni ampliación de timeouts. Se mantienen la caducidad del
catálogo, el arranque cerrado ante errores y la parada ante muerte real de los procesos hijos.
El gestor ahora conserva también la clase de los errores fatales.

Regresión ejecutada: tests/Unit/LocalCatalogRenewalTest.php, **3 pruebas y 11 aserciones aprobadas**.
Incluye timeout real de un proceso de control, supervivencia de un proceso independiente,
ausencia de reintento y fallo del registro. Sintaxis de los tres archivos PHP y Pint acotado
aprobados. No se ejecutaron migraciones ni se repitieron las suites cerradas.

El propietario autorizó recuperar únicamente el lock abandonado del worker. Se comprobó la
ausencia de los procesos anteriores, identidad física del clúster local y correspondencia
temporal con aquella instancia; se liberó el lock mediante su propietario, dentro de una
transacción. No se vació la caché ni se intervinieron locks de otros procesos. Un primer intento
falló por el nombre de tabla de locks nulo y revirtió; se corrigió la resolución al valor efectivo
cache_locks y se exigió confirmación explícita antes del reinicio.

La nueva instancia quedó lista a las 23:02:47 UTC: gestor 10508, FastAPI 22604, worker 22032 y
web 24360. Comprobación real posterior: enabled=true, ready=true, errors=[], semantic=loaded,
catalog=loaded, versión coincidente, autenticación correcta, worker vivo, cero pendientes y cero
trabajos fallidos del catálogo. Acceso local: http://127.0.0.1:18080. El catálogo exportable tiene
cero fichas publicadas; no se publicaron contenidos automáticamente. 000022 sigue siendo la
única migración pendiente. Estos resultados sustituyen la afirmación de continuidad de los
procesos del primer arranque, sin cambiar sus evidencias históricas de pruebas.
Comprobación de continuidad a las 23:09:12 UTC: los cuatro procesos seguían vivos y el snapshot
se había renovado a las 23:08:53 UTC, después del reinicio. Esto confirma una renovación posterior;
no constituye garantía de disponibilidad indefinida ni instalación como servicio de Windows.

Los primeros 15 commits autorizados terminan en 79b7370. El propietario autorizó incorporar
la corrección y este registro en un commit adicional: fix(horus-ai): aislar fallos de renovación
del supervisor. Contiene exclusivamente scripts/horus-local.php,
scripts/Support/LocalCatalogRenewal.php, tests/Unit/LocalCatalogRenewalTest.php,
PROJECT_CONTEXT.md y docs/entrega-consolidacion.md. El bloque suma 16 commits nuevos y
201 rutas distintas respecto del HEAD inicial; el manifiesto JSON previo conserva el inventario
histórico de los primeros 15 commits. El último hash se consulta en Git, sin autorreferencia.
No hubo push. Los cambios concurrentes ajenos se conservaron fuera de este ajuste.
