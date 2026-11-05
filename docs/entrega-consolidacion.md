# Entrega técnica de consolidación — 05/11/2026

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
