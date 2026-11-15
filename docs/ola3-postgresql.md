# Ola 3, bloque de Servicios: validación en PostgreSQL

15/11/2026, con autorización expresa del propietario («autorizar postgresql»). Cubre las migraciones 000043 a 000054 (clientes,
prospectos, pipeline, proyectos, soporte y reportes comerciales). Sin push.

## Protocolo seguido

- **Solo** el clúster aislado `horus_pgt_55433` (`127.0.0.1:55433`, base `horus_l2_test`, rol `horus_l2_test_runner`). Los puertos 5432 (PID 5948)
  y 55432 (PID 5984) se comprobaron antes y después y no se tocaron.
- Identidad leída con `pg_controldata` **antes** de arrancar (`system_identifier` `7702263743609677164`, estado «apagado») y con
  `pgsql-test.php probe` antes y después (solo lectura: base, rol, puerto, `cluster_name`, identificador y `UTC`).
- Arranque con `pg_ctl start` como proceso de usuario, sin servicio ni elevación. Consentimiento destructivo
  (`HORUS_PG_TEST_CONFIRM`) **solo como variable de cada comando**; `.env.pgsql-testing.local` sin tocar.
- Limpieza con `pgsql-test.php artisan migrate:fresh` (54 migraciones aplicadas, sin datos de prueba) y cierre con
  `pg_ctl stop -m fast`: estado «apagado», sin listener en 55433 y sin `postmaster.pid`.

## Resultado

| Ejecución | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| `Tests\PostgresIntegrity\CrmPostgresTest` (nueva) | 11 | 83 | OK |
| Pruebas de panel y migración de los seis módulos más el vínculo cotización-cliente, más la anterior | 132 | 1283 | OK |
| Existentes de cotizaciones y panel (`CotizacionPublica*`, `PanelConcurrenciaPostgresTest`, `EstadosCheckPostgresTest`) | 13 | 93 | OK (1 omitida por diseño: el trabajador de proceso real) |

La prueba nueva (`CrmPostgresTest`) comprueba en PostgreSQL real:

- **`CHECK` de clientes, prospectos, oportunidades, proyectos y tickets:** estados, orígenes, prioridades y canales válidos; «motivo si y solo
  si descartado o perdida»; «convertido tiene cliente»; «fecha de cierre si y solo si cerrada»; «fin real si y solo si completado»;
  presupuesto y monto no negativos; probabilidad 0 a 100; fechas coherentes; resuelto y cerrado con su fecha; contador no negativo.
- **Restricciones de borrado:** un cliente con oportunidades, proyectos, tickets o prospectos convertidos no se elimina; una oportunidad con
  historial, un proyecto con hitos y un ticket con notas tampoco (`RESTRICT`, sin cascada).
- **Unicidades:** una solicitud de cotización da un solo prospecto, una oportunidad da un solo proyecto, y los códigos de proyecto y de ticket y
  la secuencia por tipo y año son únicos; los valores nulos conviven.
- **Carreras con bloqueo de fila (dos sesiones):** el contador de códigos serializa dos altas de proyecto (la bloqueada no deja rastro y el
  siguiente número es el 2, nunca se repite el 1); la conversión de un prospecto y el cambio de etapa de una oportunidad esperan el
  bloqueo (55P03) y, al confirmar la otra sesión, deciden con el estado nuevo (412, sin cliente ni historial de más); dos altas de proyecto
  sobre la misma oportunidad dan un solo proyecto sin consumir otro número.
- **Reporte comercial:** los agregados, fechas de Lima, ponderado, tiempo medio y filtro de responsable dan las cifras esperadas y la
  exportación deja constancia.

## Correcciones que salieron de esta ejecución (solo en pruebas)

Ningún control falló. Los 8 errores de la primera pasada eran supuestos de SQLite en mis pruebas de migración, ya corregidos:

- `PRAGMA foreign_keys` solo se ejecuta en SQLite.
- Tras una violación esperada, la transacción de la prueba queda abortada en PostgreSQL: cada intento se envuelve en un punto de guardado.
- El `down()` de una migración con tablas dependientes falla en PostgreSQL fuera de orden (Laravel revierte en orden inverso, así que en la
  práctica no ocurre): `Tests\Support\RevierteCadenaCrm` revierte primero las posteriores y las vuelve a aplicar.
- Una fila de prueba de oportunidad ganada sin `cerrada_at`: el `CHECK` hizo bien su trabajo; se corrigió el dato.
- En `CrmPostgresTest`, el borrado de un padre con hijos da `restrict_violation` (23001), no 23503; se corrigió el código esperado, sin
  cambiar la comprobación.

## Qué no cubre

No se ejecutó la suite completa de PostgreSQL (más de 3900 pruebas), solo los módulos nuevos y las pruebas existentes que tocan lo que
estos módulos modifican. Tampoco se probó el rendimiento con volumen ni los planes de consulta de los listados nuevos.
