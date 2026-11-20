# Sprint 22 — Vencimientos y avisos del Libro de Reclamaciones

> Estado: **implementado y verificado localmente; sin consolidar en Git** (sin staging, commits ni push). Evidencia: SQLite en memoria; **no** se ejecutó nada contra PostgreSQL, correo real ni el scheduler real.
> Historial de decisiones y fases: `PROJECT_CONTEXT.md` (secciones «SPRINT 22 — FASE 0» a «CIERRE»).

## 1. Objetivos y alcance

**Objetivo (vigente):** automatizar el vencimiento de las propuestas de solución y avisar internamente de los plazos legales de las reclamaciones, sin migraciones y reutilizando la arquitectura existente (`GestionReclamaciones`, `AuditoriaReclamaciones`, `NotificacionInterna`, `DiasHabiles`).

**Fuera del sprint por decisión del propietario:** preparación para producción (`.env.example`, `trustProxies`, workers, documentación y configuración de servidores), ejecución de GitHub Actions y toda funcionalidad ajena al libro. Las observaciones de despliegue de la Fase 0 quedan registradas en `PROJECT_CONTEXT.md` para cuando termine el desarrollo.

**Decisiones aprobadas:** (a) procesar propuestas vencidas y avisar el plazo legal sin estados ni eventos nuevos; avisos a 3 días hábiles del vencimiento y el día del vencimiento; destinatario: el responsable o, si no hay, los usuarios con `reclamaciones.ver`; sin margen de gracia.

## 2. Qué existía y qué se añadió

Existía (no se reimplementó): `GestionReclamaciones::resolverVencimiento()` —cierra la ventana de 5 días hábiles de una propuesta de solución—, evaluación perezosa al consultar o escribir, auditoría sin datos personales y notificaciones internas al registrar. No existía: ningún llamador programado, ningún aviso del plazo legal de 15 días hábiles y ninguna deduplicación.

| Fase | Resultado |
| --- | --- |
| 1 | Comando `horus:reclamaciones-vencimientos` (propuestas vencidas) |
| 2 | Avisos internos del plazo legal con deduplicación |
| 3 | Programación diaria 08:00 `America/Lima` con `withoutOverlapping(30)` |
| 4 | Regresión final, este documento y cierre |

## 3. El comando

`php artisan horus:reclamaciones-vencimientos [--dry-run] [--limit=200]`

1. **Módulo desactivado** (`Reclamaciones::habilitado()` falso): mensaje informativo, SUCCESS, sin consultas ni escrituras.
2. **`--limit`** entre 1 y 1000 (si no, FAILURE). Es el tope **por etapa** de **trabajo efectivo**: propuestas resueltas o avisos enviados. Los casos que fallan, los que no tienen destinatarios y los omitidos **no consumen** el tope (ver «Progreso entre ejecuciones»).
3. **Etapa 1 — propuestas vencidas.** Recorre de forma perezosa (`lazyById`) los casos con `estado='abierto'`, propuesta pendiente (`suspension_desde` no nulo) y `suspension_hasta` anterior a hoy en Lima (`whereDate`, equivalente en SQLite y PostgreSQL), por `id`. Para cada caso llama a `resolverVencimiento()`, que conserva la transacción, el `lockForUpdate`, la relectura y la idempotencia, y deja el evento `propuesta_vencida` y su auditoría con actor «Sistema». Sin margen de gracia.
4. **Etapa 2 — avisos del plazo legal.** Corre **después** de la etapa 1 para que un plazo reanudado use su nueva `fecha_limite`. Recorre de forma perezosa los casos abiertos, sin propuesta pendiente y con `fecha_limite` dentro de la cota de aviso; descarta sin bloqueo los ya avisados (`avisoPendiente`, una consulta de existencia) y procesa con `avisarPlazo()` hasta `--limit` avisos enviados. La evaluación previa y el aviso están dentro del mismo aislamiento por caso.
5. **`--dry-run`:** informa «Propuestas vencidas: N (dry-run…)» y «Avisos de plazo pendientes: N (dry-run…)». No resuelve ni escribe, y lo dice: los conteos reflejan el estado actual de los datos; **no se simula la reanudación** de las propuestas vencidas (al resolverlas cambian las fechas límite y, con ellas, los avisos); y si el tope `--limit` se alcanza y quedan más casos, lo indica («puede haber más casos de los mostrados»).
6. **Errores:** un fallo en un caso (incluido un error de consulta en la evaluación previa) se registra (`Log::error`, solo identificador y clase de excepción, nunca el mensaje) y no impide procesar los demás; el comando termina con FAILURE si hubo alguno. No envía correo.

### Progreso entre ejecuciones

Un caso que falla siempre sigue siendo candidata (no se escribe estado de seguimiento) y, si ocupara los primeros lugares de un lote con tope, podría impedir indefinidamente el avance de los posteriores. Por eso el tope cuenta solo trabajo efectivo y cada candidata se intenta **una vez por ejecución**, en orden de `id`, continuando tras un fallo: los fallos se reintentan en cada ejecución sin bloquear a los demás. Un caso «sin destinatarios» tampoco consume el tope (ver «`sin_destinatarios`» más abajo). Si un fallo es sistémico (p. ej. la base no responde) el error se registra una vez por caso y por ejecución.

### Alcance real de `--limit`

`--limit` limita únicamente los **resultados efectivos de cada etapa** (propuestas resueltas en la etapa 1; avisos enviados en la etapa 2). **No** limita:

- el número de candidatos **revisados** (el recorrido llega hasta alcanzar el tope de resultados efectivos o agotar las candidatas, y se lee en bloques de 100);
- las **consultas** ejecutadas (cada candidata del paso 2 cuesta al menos una consulta de existencia sobre `audit_logs`, y cada intento abre su propia transacción);
- los **intentos individuales** (cada candidata se intenta una vez por ejecución, incluidas las que fallan o no tienen destinatarios);
- la **duración total** del comando, que crece con el número de candidatas pendientes y no con `--limit`.

El trabajo por ejecución está acotado únicamente por el número de candidatas existentes (cada una se intenta una sola vez), no por `--limit`.

### Reintentos en ejecuciones posteriores

Los fallos persistentes y los casos sin destinatarios **siguen siendo reintentables**: no se les marca ni se guarda estado de seguimiento, siguen cumpliendo la condición de candidata y se vuelven a intentar en cada ejecución (diaria) hasta que la causa desaparezca (se corrige el error, se asigna un destinatario con `reclamaciones.ver` o el caso deja de estar abierto).

## 4. Umbrales y reglas del plazo

Sea `fecha_limite` el plazo legal vigente y «hoy» la fecha en `America/Lima` (`reclamaciones.zona_horaria`):

- **`por_vencer`:** `fecha_limite` está a 3 días hábiles o menos (`reclamaciones.aviso_dias_habiles`, feriados de `reclamaciones.feriados`) y aún no es hoy.
- **`vence`:** hoy es `fecha_limite` o ya pasó.
- **Sin aviso:** casos que no están `abierto` (respondido, concluido por acuerdo) y casos con propuesta pendiente (el plazo está suspendido). Al vencer o rechazarse la propuesta, `fecha_limite` se desplaza y se evalúa de nuevo.
- **Recuperación:** si se perdieron ejecuciones, el aviso previo se envía igual mientras no venza el plazo; un caso ya vencido que nunca recibió aviso recibe el de vencimiento una sola vez (extensión menor a lo aprobado, para que un caso olvidado no quede sin ningún aviso).
- **Textos del umbral `vence`**, según la fecha evaluada: hoy es la fecha límite → «Plazo vence hoy: reclamación N» / «El plazo de respuesta vence hoy, dd/mm/aaaa.»; ya pasó → «Plazo vencido: reclamación N» / «El plazo de respuesta venció el dd/mm/aaaa.». `por_vencer` → «Plazo por vencer: reclamación N» / «El plazo de respuesta vence el dd/mm/aaaa.»

## 5. Destinatarios y deduplicación

- **Destinatarios:** el responsable, si sigue siendo personal del panel (`STAFF_ROLES`) con `reclamaciones.ver`; si no hay responsable o ya no cumple, todos los usuarios con `reclamaciones.ver`. Si no hay ninguno, `avisarPlazo()` devuelve `sin_destinatarios`: **no se crean notificaciones ni la marca de aviso**, por lo que el caso se reintenta en la siguiente ejecución. Esto **no** significa que la llamada no escriba nada: `avisarPlazo()` aplica primero el vencimiento pendiente (`evaluarVencimiento`), de modo que, si el caso tenía una propuesta vencida, esa resolución (actualización de la reclamación, evento `propuesta_vencida` y su auditoría) se registra y se confirma aunque después no haya destinatarios. En el flujo del comando la etapa 2 solo recorre casos sin propuesta pendiente (la etapa 1 ya resolvió las vencidas), por lo que esta resolución previa solo ocurre en llamadas directas al servicio; la prueba `test_sin_destinatarios_resuelve_antes_la_propuesta_vencida_pero_no_crea_avisos_ni_marca` fija ese comportamiento.
- **Deduplicación sin migración:** la marca es la auditoría `reclamacion_aviso_plazo` con `resultado` (`por_vencer` o `vence`) y `fecha_limite` del plazo avisado. Se comprueba con **una consulta de existencia** (`exists()` con filtros JSON sobre `changes->resultado` y `changes->fecha_limite`), sin cargar el historial de avisos en PHP. Cada umbral se avisa **una vez por plazo**; si una propuesta desplaza la fecha límite, corresponde un aviso nuevo. Las ejecuciones repetidas el mismo día o los siguientes no duplican.
- **Atomicidad:** `avisarPlazo()` abre una transacción, bloquea la reclamación (`lockForUpdate`), aplica el vencimiento pendiente, relee el estado y escribe las notificaciones y, como **última escritura**, la auditoría. Si la auditoría falla, se revierte todo: no queda aviso a medias ni marca, y el siguiente intento avisa. Dos ejecuciones concurrentes se serializan por el bloqueo y la segunda ve la marca.

## 6. Scheduler

`routes/console.php`:

```php
Schedule::command('horus:reclamaciones-vencimientos')
    ->dailyAt('08:00')
    ->timezone('America/Lima')
    ->withoutOverlapping(30);
```

- **Hora:** 08:00 de Lima. `php artisan schedule:list --timezone=America/Lima` muestra `0 8 * * *`; sin esa opción, `schedule:list` convierte a la zona de la app (UTC) y muestra `0 13 * * *` (misma tarea).
- **Bloqueo:** `withoutOverlapping(30)`; la clave caduca a los 30 minutos (el valor por defecto de Laravel es 24 h y un proceso muerto bloquearía el día siguiente). Usa el almacén de caché predeterminado; con `CACHE_STORE=database` necesita `cache_locks` (migración base de Laravel). Con un almacén no compartido entre procesos (p. ej. `array`) no habría exclusión real.
- **No** usa `onOneServer()` (un solo servidor) ni segundo plano. La tarea `horus:matriculas-notificar` no cambia.
- Con el módulo desactivado (estado actual), cada ejecución termina en SUCCESS sin tocar la base.

## 7. Pruebas y resultados

Archivos propios del sprint:

- `tests/Feature/ProcesarVencimientosReclamacionesTest.php` (13): resolución con historial y auditoría, idempotencia, ventana en el límite de Lima, casos ignorados, `--dry-run`, módulo desactivado, fallo aislado sin datos personales, ausencia de notificaciones en esta etapa y validación de `--limit`; H1 (un caso que falla siempre o varios no bloquean a los posteriores y se reintentan) y H4 (aclaraciones del `--dry-run`).
- `tests/Feature/AvisosPlazoReclamacionesTest.php` (33): umbrales, destinatarios (responsable, sin responsable, responsable inelegible), deduplicación, recuperación, plazo suspendido y reanudado, desplazamiento de `fecha_limite`, estados cerrados, atomicidad y reintento tras fallo de auditoría, `sin_destinatarios`, `--dry-run`, módulo desactivado, fallo aislado, `--limit`, textos «vence hoy»/«vencido» y día juzgado en hora de Lima; H1 (aviso que falla siempre y casos sin destinatarios no consumen el tope), H2 (error en la evaluación previa aislado por caso, sin datos personales en el log), H3 (una sola consulta de existencia, sin cargar el historial) y H4 (aclaraciones del `--dry-run`).
- `tests/Feature/SchedulerReclamacionesTest.php` (5): `schedule:list`, expresión y zona, hora de ejecución (08:00 Lima sí; 07:59, 08:01 y 08:00 UTC no), caducidad del bloqueo y tarea de matrículas intacta.

TDD: las pruebas de cada fase se escribieron primero y fallaron antes de implementar. Mutaciones detectadas: sin deduplicación, umbral 3→2, avisar un plazo suspendido, texto de vencimiento, deduplicación que ignora la fecha, `<`→`<=` en la selección, bloqueo de 24 h, sin zona, hora 09:00, sin `withoutOverlapping`, «hoy» contado como vencido y «nunca vencido».

Resultados de la regresión final (una sola corrida, suite completa en SQLite en memoria), Larastan, Pint, `git diff --check` y SHA-256: ver la sección «CIERRE» de `PROJECT_CONTEXT.md`.

## 8. Seguridad y privacidad

- Las notificaciones llevan **solo número y fecha**; nunca nombres, documento, correo, domicilio ni texto del caso. Sin `vinculo` (aún no existe pantalla del caso).
- La auditoría no registra actor ni IP en las acciones del sistema y solo admite claves y valores de la lista cerrada de `AuditoriaReclamaciones`.
- Los logs del comando contienen conteos, el identificador del caso y la clase de excepción, nunca el mensaje.
- No se tocaron permisos, roles, migraciones, `.env` ni credenciales; el comando no envía correo ni usa HTTP.

## 9. Limitaciones y pendientes

- **PostgreSQL:** todo se probó en SQLite en memoria, que ignora `lockForUpdate()`. La carrera entre el comando y una aceptación o rechazo manual, y entre dos ejecuciones simultáneas, está resuelta por diseño (bloqueo y relectura) pero **no medida**; queda para la validación PostgreSQL pendiente.
- **Marca de deduplicación:** se consulta `audit_logs` por acción, modelo y `model_id` sin índice (M-13, Sprint 23 propuesto), ahora con una consulta de existencia por caso candidato. Aceptable porque solo se consulta para casos abiertos cercanos al plazo; si `audit_logs` crece mucho, un índice sobre `(model_type, model_id, action)` sería la mejora (migración, fuera del alcance). Los filtros JSON se probaron en SQLite; su traducción a PostgreSQL (`->>`) es la estándar de Laravel pero **no se ejecutó** contra PostgreSQL.
- **Aceptación tardía:** como el comando cierra la ventana de la propuesta al día siguiente, una aceptación recibida dentro de la ventana pero registrada después devuelve 409 (limitación ya documentada de la evaluación perezosa).
- **Sin pantalla:** los avisos se ven en las notificaciones internas existentes; no hay enlace al caso hasta que exista el panel del libro (Sprint 25).
- **Módulo apagado:** `reclamaciones.enabled` sigue en `false`; el comando es inocuo hasta que el propietario active el libro.
- **`--limit` no acota el costo:** limita resultados efectivos, no candidatos revisados, consultas, intentos ni duración (ver «Alcance real de `--limit`»). Con muchas candidatas pendientes la ejecución diaria revisa todas.
- **Reintentos diarios:** los fallos persistentes y los casos sin destinatarios se reintentan (y, si fallan, se registran) en cada ejecución hasta que la causa desaparezca; no hay corte ni marca de caso fallido.
- **Despliegue:** el scheduler solo corre si el servidor ejecuta `schedule:run` cada minuto y la caché admite bloqueos; preparar eso queda fuera de este sprint.
- **Feriados:** `reclamaciones.feriados` está vacío; los plazos ignoran feriados hasta que el propietario los cargue.
