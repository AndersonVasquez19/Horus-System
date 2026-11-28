# Plan de mejora del panel · Fase 3 (reglas de negocio) · primer tramo · 23/11/2026

> Alcance de la evidencia: SQLite en memoria. Comprueba reglas y exactitud de importes; **no** mide concurrencia ni el plan de ejecución de PostgreSQL. Las dos migraciones nuevas (`000099`, `000100`) **no se aplicaron a ninguna base real**.

Esta fase se entrega por tramos. Hechos: 3.1 cobranza asistida, 3.2 cierre de caja, 3.3 estudiante 360°, 3.4 riesgo de deserción, 3.5 elegibilidad de certificado, 3.6 reapertura de acta, 3.10 aprobación de reversos por umbral, 3.11 encuesta de satisfacción y 3.12 semáforo de reclamaciones, 3.7 lista de espera y 3.8 matrícula masiva. Pendiente: 3.9 tablero ejecutivo.

## 3.2 Cierre de caja

| Qué | Cómo |
| --- | --- |
| **Reversos** | La caja diaria sumaba solo pagos. Ahora resta los reversos confirmados **del día en que se hicieron** (un reverso de hoy sobre un pago de ayer baja la caja de hoy, no la de ayer). `total` es neto; se añaden `cobrado`, `reversado`, `cantidad_reversos`. El contrato anterior (`cantidad`, `total`, `por_metodo[].metodo|cantidad|total`, `movimientos`) se conserva. |
| **Desglose** | Por método (con `codigo`), por usuario que registró y movimientos con signo (`tipo`, reverso en negativo). Avisa de los pagos pendientes de confirmar del día (no entran). |
| **Cierre** | `POST academico/pagos/caja-diaria/cierre` guarda lo esperado, lo contado por método y la diferencia. Una fecha se cierra una sola vez (restricción única + bloqueo; el segundo intento responde 409). Si hay diferencia, la observación es obligatoria. Se pueden cerrar días sin movimientos. Fechas futuras se rechazan. |
| **Inmutable** | El modelo `CierreCaja` lanza excepción al actualizar o borrar. La corrección de un error se hace con un movimiento posterior, no reescribiendo el cierre. |
| **Permiso** | `pagos.cerrar_caja` (migración `000099` + constante en el seeder), **solo admin por defecto**, igual que confirmar y reversar. Secretaría y coordinación pueden ver los cierres (`pagos.ver`). Se puede conceder a un rol desde la pantalla de roles. |
| **Auditoría** | `caja_cerrada` (fecha, esperado, declarado, diferencia). |
| **Interfaz** | Botón «Cerrar caja» en Pagos (solo con el permiso): muestra lo esperado por método, pide lo contado, calcula la diferencia en vivo y exige el motivo. El PDF de caja indica los reversos. |

Archivos: [`CajaDiaria`](../apps/api/app/Services/CajaDiaria.php), [`CierreCaja`](../apps/api/app/Models/CierreCaja.php), [`CerrarCajaRequest`](../apps/api/app/Http/Requests/CerrarCajaRequest.php), [migración](../apps/api/database/migrations/2026_11_24_000099_create_cierres_caja_table.php), [`CierreCajaTest`](../apps/api/tests/Academic/Panel/CierreCajaTest.php).

**Pendiente de decisión:** si otros roles (por ejemplo secretaría) deben poder cerrar la caja. Hoy no, siguiendo la decisión del 20/11/2026 de que confirmar y reversar son solo de admin.

## 3.1 Cobranza asistida

> **Retirada el 27/11/2026** (ver [`retiro-cobranza-riesgo.md`](retiro-cobranza-riesgo.md)). Lo que sigue es histórico y sus enlaces a código retirado ya no funcionan.

| Qué | Cómo |
| --- | --- |
| **Cola** | `GET academico/cobranza/cola`: obligaciones vigentes con cuotas vencidas sin pagar, de la deuda más vieja a la más reciente. Importes en céntimos desde cuotas y asignaciones del libro (neto de reversos, una consulta por lote, sin N+1). Tramos: reciente 1–7 días, media 8–30, crítica 31 o más. Filtros: tramo, promesa (vigente, incumplida, sin promesa) y búsqueda. Hasta 200 filas (`truncado`); el resumen no cambia con los filtros. |
| **Contactos y promesas** | Bitácora `seguimientos_cobranza` (migración `000100`): canal, resultado, nota y, si hay promesa, fecha (de hoy a 90 días) y monto opcional. Solo se agrega: el modelo impide editar o borrar. Solo sobre obligaciones vigentes. |
| **Permisos** | Ver cola e historial: `pagos.ver`. Registrar contacto: `pagos.registrar` (secretaría ya lo tiene). Sin permisos nuevos. |
| **Auditoría** | `cobranza_contacto` con canal, resultado y fecha de promesa. **Nunca la nota** (texto libre). |
| **Recordatorios** | Corrección: la consulta de cuotas por vencer tenía `limit` sin `orderBy`, por lo que con muchas obligaciones dejaba fuera filas distintas en cada corrida; ahora ordena por `id`. Nuevo resumen diario de cobranza vencida (una notificación interna por usuario y día, solo a quien tiene `pagos.ver` y no desactivó los recordatorios de cuotas). Sin espejo externo (correo/WhatsApp), así que no sale ningún dato hacia terceros. |
| **Interfaz** | Página «Cobranza» (menú y paleta Ctrl+K): cifras por tramo, filtros, tabla, y por fila un panel con historial y formulario de contacto. El enlace de WhatsApp manual sigue en la ficha de la obligación (el nombre del estudiante enlaza allí); la cola no expone teléfonos. |

Archivos: [`CobranzaAsistida`](../apps/api/app/Services/CobranzaAsistida.php), [`CobranzaController`](../apps/api/app/Http/Controllers/Api/Panel/Academico/CobranzaController.php), [`SeguimientoCobranza`](../apps/api/app/Models/SeguimientoCobranza.php), [migración](../apps/api/database/migrations/2026_11_24_000100_create_seguimientos_cobranza_table.php), [`CobranzaAsistidaTest`](../apps/api/tests/Academic/Panel/CobranzaAsistidaTest.php).

### Límites declarados

- La promesa mira solo la **última promesa registrada** y la fecha: vigente hasta su fecha inclusive; pasada la fecha, mientras siga habiendo deuda vencida, «incumplida». No intenta adivinar si un pago concreto cumplió una promesa.
- La cola calcula todo en PHP sobre las obligaciones con cuotas vencidas y corta a 200 filas. Con muchos miles de obligaciones vencidas habría que paginar en SQL.
- El resumen diario no distingue cuánto de la mora es nuevo respecto de ayer.
- No se envía nada automáticamente: WhatsApp Cloud y Resend siguen apagados por decisión del propietario.

## 3.5 Elegibilidad de certificado unificada (segundo tramo)

Una sola regla, [`ElegibilidadCertificado`](../apps/api/app/Services/ElegibilidadCertificado.php), para la emisión individual, el lote y una vista previa (`GET academico/matriculas/{id}/certificado/elegibilidad`, permiso `certificados.ver`).

| Requisito | Regla |
| --- | --- |
| `asistencia` | Porcentaje registrado menor a `academico.asistencia_minima` (80 por defecto; antes había dos valores independientes, uno en el tablero y otro fijo en el servicio). Sin datos de asistencia no bloquea, como antes. |
| `nota` | Nota final menor a `academico.nota_minima` (11 por defecto, `HORUS_NOTA_MINIMA`). Sin nota no bloquea, como antes. |
| `deuda` | **Nuevo.** La obligación vigente tiene saldo sin confirmar. Un pago pendiente de confirmar no cuenta como pagado. Sin obligación no hay deuda. |
| `acta` | **Nuevo, opcional y apagado por defecto** (`HORUS_CERTIFICADO_EXIGE_ACTA=true`): la edición debe tener el acta cerrada. |

Decisión del propietario aplicada: la deuda **bloquea y advierte**. Ante cualquier bloqueo solo un admin puede emitir, con un motivo (queda en el certificado y en la auditoría con los códigos de bloqueo). En el panel, «Emitir certificado» consulta primero los requisitos: si hay bloqueos los muestra; el admin puede seguir indicando el motivo y los demás roles ven que no pueden. El lote omite a quien tenga cualquier bloqueo y cuenta aparte los omitidos por deuda (`omitidos_por_deuda`).

Pruebas: [`ElegibilidadCertificadoTest`](../apps/api/tests/Academic/Panel/ElegibilidadCertificadoTest.php).

**Límites:** el valor de `nota_minima` (11) y de asistencia (80) sigue siendo una decisión institucional que conviene confirmar; «sin nota» y «sin asistencia registrada» no bloquean (no se cambió ese comportamiento sin decisión). Emitir con deuda como admin sigue siendo posible, por diseño.

## 3.4 Riesgo de deserción

> **Retirada el 27/11/2026** (ver [`retiro-cobranza-riesgo.md`](retiro-cobranza-riesgo.md)). Lo que sigue es histórico y sus enlaces a código retirado ya no funcionan.

[`RiesgoDesercion`](../apps/api/app/Services/RiesgoDesercion.php) puntúa a los matriculados de ediciones **en curso**: faltas seguidas más recientes (3 o más → 40 puntos, 2 → 25), asistencia acumulada bajo el mínimo (20) y deuda vencida (más de 30 días → 35, 8 a 30 → 25, 1 a 7 → 15). Nivel alto desde 60, medio desde 30; por debajo no se lista. Cada fila muestra los factores que la explican.

- `GET academico/riesgo-desercion` (permiso `matriculas.ver`), página «Riesgo de deserción» en el menú y en Ctrl+K.
- Aviso interno diario (una vez por usuario y día) con la cantidad en riesgo alto, para quien tiene `matriculas.editar` (coordinación y admin), sin nombres. Respeta la preferencia de recordatorios de sesiones y el interruptor general `RECORDATORIOS_INTERNOS_ENABLED`.
- Pruebas con puntajes calculados a mano: [`RiesgoDesercionTest`](../apps/api/tests/Academic/Panel/RiesgoDesercionTest.php).

**Límites:** no incluye «sin nota» (el modelo de evaluaciones no distingue de forma fiable una nota pendiente de una no exigible todavía); los pesos son una primera propuesta para calibrar con casos reales; calcula en PHP sobre las ediciones en curso (con los cupos máximos de 10 por edición el volumen es bajo).

## 3.3 Estudiante 360° (tercer tramo)

- `GET academico/estudiantes/{id}/360` (permiso `estudiantes.ver`): línea de tiempo con matrículas, actas cerradas, pagos y reversos confirmados, faltas (las 30 más recientes), certificados, contactos de cobranza y notas internas, del más reciente al más antiguo (hasta 200). **Cada tipo de evento aparece solo con el permiso de su módulo** (`pagos.ver`, `asistencias.ver`, `calificaciones.ver`, `certificados.ver`), así una ficha no filtra datos financieros a quien no los ve.
- Notas internas de seguimiento: `POST academico/estudiantes/{id}/notas` (permiso `estudiantes.crear` o `estudiantes.editar`), tabla `notas_estudiante` (migración `000101`). Solo se agregan: el modelo impide editar o borrar. 3 a 500 caracteres, sin HTML. La auditoría registra quién y a qué estudiante, **nunca el texto**.
- Interfaz: sección «Seguimiento 360°» en la ficha del estudiante, con el formulario de nota y la línea de tiempo.
- Pruebas: [`Estudiante360Test`](../apps/api/tests/Academic/Panel/Estudiante360Test.php).

**Límites:** las notas internas son texto libre: no deben guardar datos sensibles innecesarios (salud, familia); no hay forma de corregirlas, solo agregar otra. La línea de tiempo no muestra cuotas vencidas ni deuda (eso vive en Cobranza).

## 3.6 Reapertura de acta con aprobación (tercer tramo)

Antes, un acta cerrada no podía volver a abrirse ni con un error de nota. Ahora es una excepción controlada en tres pasos:

1. **Solicitar** (`POST .../acta/reapertura`, permiso `convocatorias.cerrar`): motivo de 10 a 500 caracteres; solo sobre una edición cerrada; una solicitud pendiente por edición. Crea una aprobación del módulo de Aprobaciones de tipo `reapertura_acta`, cuya autoridad es **Gerencia (rol admin)**; el solicitante nunca decide sobre la suya. Ese tipo no aparece en el selector al crear solicitudes a mano.
2. **Aprobar** en Aprobaciones (flujo existente).
3. **Reabrir** (`POST .../acta/reabrir`, permiso `convocatorias.cerrar`): exige una solicitud aprobada y **sirve una sola vez** (la aprobación se marca como usada). La edición vuelve a `en_curso`, se borran `nota_final` y `resultado` de sus matrículas (se recalculan al cerrar de nuevo, así no se emite un certificado con una nota que ya no vale) y la revisión aumenta. Queda en auditoría (`acta_reapertura_solicitada`, `acta_reabierta`).

**Los certificados ya emitidos no se anulan solos**: llevan su propia copia de la nota. La respuesta informa cuántos hay para que se anulen y reemitan si cambian las notas.

Pruebas: [`ReaperturaActaTest`](../apps/api/tests/Academic/Panel/ReaperturaActaTest.php). Interfaz: botones «Solicitar reapertura» y «Reabrir acta aprobada» en la pestaña de calificaciones de una edición cerrada.

**Límites:** reabrir no avisa por sí mismo al solicitante de que ya está aprobada (le llega la notificación interna de la aprobación, como siempre). Un alumno con nota ya exportada en un Excel de acta anterior no se rastrea.

## 3.10 Aprobación de reversos por umbral (cuarto tramo)

Decisión del propietario: umbral de **S/ 100** (configurable). Desde ese importe (inclusive), un reverso de pago exige la aprobación de Gerencia por el módulo de Aprobaciones.

1. **Solicitar** (`POST academico/pagos/transacciones/{id}/reversar/solicitar`, permiso `pagos.reversar`): motivo de al menos 5 caracteres; solo sobre un pago confirmado que lo requiere y no reversado; una solicitud pendiente por pago. Crea una aprobación de tipo `reverso_pago` con autoridad **admin**.
2. **Aprobar** en Aprobaciones: lo hace **otra persona con rol admin** (nadie decide sobre su propia solicitud).
3. **Reversar** (`POST .../reversar`, como siempre): desde el umbral consume la aprobación dentro de la misma transacción; sin una aprobada responde 409 con el mensaje que indica solicitarla. La aprobación sirve para **un solo reverso**; repetir la petición no duplica el reverso. Una solicitud rechazada no habilita nada y se puede volver a pedir.

Configuración: `PANEL_APROBACION_REVERSO_UMBRAL` en soles (100 por defecto; **0 la apaga**). El detalle del pago devuelve `reverso_aprobacion` (`ninguna`, `pendiente`, `aprobado`, `rechazado`) y la interfaz cambia el botón: «Solicitar reverso», «Aprobación pendiente» o «Reversar». Auditoría: `reverso_aprobacion_solicitada` (importe y aprobación).

Pruebas: [`AprobacionReversoTest`](../apps/api/tests/Academic/Panel/AprobacionReversoTest.php). `PagosTest` y `MatriculaConPagoTest` fijan el umbral en 0 porque prueban el reverso en sí.

**Importante, consecuencia de la regla:** hoy `pagos.reversar` es solo de admin y la aprobación también es de admin. Para reversar S/ 100 o más hace falta **una segunda persona con rol admin**. Si la organización solo tiene un admin, esos reversos quedan bloqueados hasta crear otro admin o poner el umbral en 0. Esto es intencional (separación de funciones) pero hay que decidirlo.

**Sobre «descuentos»:** el sistema no tiene descuentos (las cuentas son «solo PEN, monto fijo, sin descuentos ni mora», `docs` de finanzas), así que la regla cubre los reversos. Anular una obligación exige que no tenga pagos, así que no mueve dinero y no entra en el umbral. Si más adelante se crean descuentos, el mismo servicio (`AprobacionReverso`) se puede reutilizar.

## 3.12 Semáforo de reclamaciones y 3.11 Encuesta de satisfacción (quinto tramo)

| Qué | Cómo |
| --- | --- |
| **3.12 Semáforo** | El tablero muestra la tarjeta «Plazos de reclamaciones»: vencidas, vencen hoy y por vencer (a 3 días hábiles o menos), solo de casos abiertos con el plazo no suspendido. Reutiliza `ConsultaReclamaciones::plazos()`; solo conteos, sin datos personales, y únicamente con `reclamaciones.ver`. Enlaza a la bandeja filtrada por abiertas. |
| **3.11 Emisión** | Al cerrar el acta se crea una encuesta por cada matrícula no anulada (`SatisfaccionEncuestas::emitirParaEdicion`, idempotente, en la misma transacción). Así la tasa de respuesta tiene denominador. El enlace público y la respuesta no cambian. |
| **3.11 KPI** | `SatisfaccionEncuestas::resumen`: emitidas, respondidas, tasa de respuesta, promedio (1 triste a 3 feliz) y % de felices. El tablero muestra «Satisfacción (90 días)» con `convocatorias.ver`; el resumen por edición (`GET convocatorias/{id}/encuestas`) añade los mismos campos sin quitar los anteriores. Se marca en alerta si los felices son menos del 60 %. |

Límites: la tarjeta de satisfacción cuenta encuestas creadas en los últimos 90 días (emitidas), no por fecha de respuesta; no se envía la encuesta por ningún canal (el enlace se comparte a mano, como ya ocurría); el umbral de alerta del 60 % es una propuesta. Pruebas: `SemaforoReclamacionesTableroTest` y `CalificacionesPanelTest`.

## 3.7 Lista de espera por edición (sexto tramo)

| Qué | Cómo |
| --- | --- |
| **Cuándo** | Solo se anota a alguien en una edición **llena** y que admita matrículas (abierta o en curso); con cupo se matricula directo. Rechaza (409) estudiantes inactivos, ya matriculados o ya esperando, y pasa de 100 personas por edición. |
| **Orden** | De llegada, con la posición calculada al listar. Quien sale puede volver a anotarse (al final). |
| **No reserva cupo** | La lista no toma ni bloquea cupos: al liberarse uno, se matricula por el flujo normal. Quien queda matriculado sale solo de la lista (se sincroniza al listar y en el aviso diario). |
| **Aviso** | El aviso interno diario (junto a cobranza y riesgo) avisa a quien tiene `matriculas.crear` cuando una edición tiene cupo libre y gente esperando: una notificación por usuario y día, solo códigos y cantidades. Respeta `recordatorios.internos_enabled`. |
| **Permisos** | Sin permisos nuevos: ver con `convocatorias.ver`; agregar y quitar con `matriculas.crear`. Auditoría: `lista_espera_agregada` y `lista_espera_quitada`. |
| **Interfaz** | Pestaña «Lista de espera» en la edición, con contacto rápido, nota opcional y aviso si ya hay cupo. |

Datos: migración `000102` (tabla `lista_espera`; la columna `activa` con restricción única evita duplicados, portable a PostgreSQL), **sin aplicar a ninguna base real**. Rutas `GET|POST convocatorias/{id}/lista-espera` y `DELETE …/{entrada}`. Pruebas: `ListaEsperaTest`.

Límites: no hay matrícula automática del primero de la lista ni aviso al estudiante (los canales externos siguen apagados); el contacto es manual (WhatsApp/teléfono). Si la edición cambia de estado a cerrada o cancelada, quienes esperaban quedan sin atender y la lista deja de mostrarlos como oferta (no se limpian solos).

## 3.8 Matrícula masiva por convenio (séptimo tramo)

| Qué | Cómo |
| --- | --- |
| **Planilla** | Excel `.xlsx`, primera hoja, encabezados `dni` (obligatorio), `nombres`, `apellidos`, `telefono`, `email`. Hasta 200 filas y 1 MB. Hay planilla modelo descargable. Un DNI guardado como número recupera los ceros de la izquierda. |
| **Vista previa** | `POST convocatorias/{id}/matricula-masiva/vista-previa`: lee y valida sin escribir nada. Cada fila queda en: matricular, crear y matricular (DNI nuevo con nombres y apellidos válidos), omitir (ya matriculado en el curso), sin cupo o error (DNI inválido o repetido, estudiante inactivo o eliminado, datos inválidos, email repetido). |
| **Confirmación** | `POST convocatorias/{id}/matricula-masiva`: vuelve a analizar el archivo (no confía en la vista previa) y procesa fila por fila, cada una en su transacción, con las reglas de `GestionMatriculas` (cupo, duplicado, bloqueos). Matrícula pendiente; opcionalmente crea la cuenta por cobrar con el precio del curso. Una fila con error no frena a las demás. |
| **Cupos** | Las filas que no caben quedan «sin cupo», en el orden del archivo. |
| **Repetir** | Subir el mismo archivo otra vez no duplica nada: lo ya matriculado sale como «omitir». |
| **Permisos** | `matriculas.crear`; crear estudiantes nuevos exige además `estudiantes.crear` (si falta, esas filas son error); la cuenta por cobrar exige `pagos.planificar`. Sin permisos nuevos. |
| **Auditoría** | Una entrada `matricula_masiva` con conteos (filas, matriculadas, creados, omitidas, sin cupo, errores); sin DNIs ni nombres. Cada matrícula y estudiante creados dejan además su auditoría normal. |
| **Seguridad del archivo** | El BFF acepta solo `.xlsx` (firma ZIP) de hasta 1 MB y reenvía con nombre fijo. Laravel revisa la estructura ZIP, el tamaño descomprimido (máx. 20 MB, 200 entradas), lee solo datos (sin fórmulas) y cualquier texto se valida antes de guardarse. |
| **Interfaz** | Botón «Matrícula masiva» en la edición: subir, vista previa fila por fila, confirmar y resultado. |

Límites: no se crea la matrícula como pagada ni con descuentos (el sistema no tiene descuentos); no hay vista previa guardada (se vuelve a subir el archivo al confirmar); no hay prueba automática del BFF con el archivo real (la cubre la prueba del backend con XLSX reales; el BFF solo se revisó con tipos y ESLint). Pruebas: `MatriculaMasivaTest`.

## Pendiente de este grupo

- 3.9 tablero ejecutivo.

## Otros cambios de este tramo

- **Pruebas con fecha UTC corregidas:** `AdminMatriculaTest`, `MatriculaNotificationTest` y `LimitesTextoLargoTest` armaban la fecha de matrícula con `today()`/`now()` (UTC) mientras la validación usa la fecha de Lima. Entre las 19:00 y la medianoche de Lima fallaban (4 pruebas) sin que hubiera un defecto del producto. Ahora usan `HoraLocal::hoy()`.
- **Permiso en la pantalla de roles:** etiqueta «Cerrar la caja del día» para `pagos.cerrar_caja`.
- **BFF:** nuevo tipo de campo `mapas` (código → texto corto, hasta 20 entradas, claves `[a-z0-9_-]`); un mapa mal formado se descarta entero y Laravel responde 422. Rutas nuevas añadidas a la lista cerrada.
- **OpenAPI:** documentadas las 5 rutas nuevas y el parámetro `fecha` del resumen de caja.

## Cómo comprobarlo

- `php artisan test --filter='CierreCajaTest|CobranzaAsistidaTest|Ola3|InventarioRutasPanelTest'`.
- Las migraciones `000099` y `000100` se aplican a una base real solo con autorización expresa; ambas son aditivas y reversibles.
