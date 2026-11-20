# PG-TEST-05B-2A: captura nativa y estabilidad del editor

30/10/2026, Lima. Esta misión es exclusivamente local: no ejecuta operaciones ni conexiones PostgreSQL, consentimiento, migraciones o la etapa 1. La recuperación anterior y sus probes son evidencia histórica independiente.

## Causa y corrección de la captura

La captura anterior esperaba `ReadToEndAsync()` de pipes después de terminar el proceso raíz. Un descendiente puede conservar los extremos de escritura; la salida del padre no implica EOF. Una fixture finita reproduce la espera adicional sin usar PostgreSQL.

`scripts/testing/NativeCapture.cs` inicia directamente el ejecutable con `CreateProcessW`. Hereda únicamente tres handles explícitos: stdin NUL y archivos independientes de stdout/stderr, creados con `CREATE_NEW`. Espera el handle del proceso raíz y obtiene su código con `GetExitCodeProcess`. No crea Job, no usa shell, no permite breakaway y no termina procesos. La pertenencia al Job de PHPUnit del padre se conserva.

`NativeCapture.ps1` expone `Exited`, `TimedOut` y `ExecutionError`. Un timeout conserva código nulo y no mata el proceso: el llamador debe gestionar su cierre seguro. `-WaitForRootExit` permite esperar expresamente la raíz del wrapper protegido, sin volver a esperar EOF de descendientes. No se debe utilizar un Job con `KILL_ON_JOB_CLOSE` para iniciar el servidor PostgreSQL.

Los binarios originales se conservan. Cada snapshot congela su longitud: un productor que continúa escribiendo no prolonga indefinidamente esa lectura. Para declarar `StreamsClosed=true`, ambos archivos se leen manteniendo locks exclusivos simultáneos. En otro caso el resultado es parcial; los archivos siguen conservando las escrituras posteriores. La decodificación UTF-8 es estricta e independiente por canal. Un error no sustituye caracteres ni borra el código de salida. El límite del snapshot en memoria es `Int32.MaxValue`; no se presenta como captura ilimitada.

Las entradas PowerShell propias declaran UTF-8 antes de cualquier rechazo. La prueba negativa del driver detectó stderr OEM de PowerShell 5.1 y conservó sus bytes originales; tras declarar la codificación, el mismo rechazo conserva también el mensaje Unicode. No se afirma que un ejecutable arbitrario emita UTF-8: si no cumple el contrato, se conservan los binarios y se informa el error de decodificación.

La cápsula externa existente `pgt05-consolidacion-ready` usa este módulo mediante su adaptador `native-process.ps1`. Su driver usa la misma captura y admite un directorio nuevo con `-EvidenceDirectory`; no sobrescribe `execution`, donde quedó registrada la detención anterior. El driver conserva espera de raíz sin timeout para `stage-wrapper`, cierre del consentimiento y rechazo de capturas parciales. Los hashes incluyen los `.bin` y acompañantes de etapa. No se creó otra cadena de wrappers. Los componentes originales corregidos se copiaron byte por byte a `pgt05-estabilizacion/capsule-before` antes de editarlos.

## PID anteriores

Los PID 7592 y 8044 se verificaron por creación, ejecutable, propietario, PPID y script, no solo por número: eran los PowerShell de captura de `recovery-02-20261030/recuperacion.ps1`. Coincidían con los tiempos de la ejecución anterior. El árbol era herramienta → 7592 → 8044; el proceso `pg_ctl` ya había terminado. El código y la reproducción explican la espera del pipe; no se inspeccionaron ni cerraron handles del servidor en producción ni se atribuyen números de handles no observados.

Después de la interrupción y continuación del propietario, ambos PID ya no existían. El agente no ejecutó terminaciones. No se observó directamente el mecanismo de su salida ni se recuperó retrospectivamente el código nativo del arranque histórico. No se afirma un cierre natural observado de esos procesos; sí se verificó su ausencia actual. Las fixtures nuevas terminaron naturalmente y no dejaron procesos propios activos.

## Generador LSP

La extensión instalada `laravel.vscode-laravel` 2.0.1 lanza `server-v0.0.32-x64-win32.exe lsp`. La fuente de [`ScriptRunner` v0.0.32](https://github.com/laravel/lsp/blob/v0.0.32/app/Lsp/ScriptRunner.php) fija `storage/framework/lsp-<16 dígitos hex>.php`, escribe helpers y un template de consulta, ejecuta PHP en el proyecto y elimina el script en `finally`. El script puede inicializar Laravel: no debe provocarse su ejecución sobre este proyecto para una simulación sin conexiones.

Las opciones reales de la extensión instalada no permiten trasladar esos scripts. `php.cache.location` de otra extensión y `Laravel.pest.helperFilePath` no controlan esta ruta. Cambiar `Laravel.phpCommand` tampoco evita la escritura previa. No se modificó la extensión, su binario ni su fuente.

El archivo histórico `storage/framework/lsp-4d372f5894f55e49.php` ya estaba ausente al empezar esta misión. Su hash previo era `95195D7ED1E7C6B9037030B0E056F7998458B90AF22702CE4D3B406689BBAE34`. No había bytes disponibles para copiar, inspeccionar o poner en cuarentena; no se inventa su contenido exacto. Una fixture externa de generación, sin ejecutar el script ni Laravel, demuestra el patrón y la ubicación fijados en la fuente.

La alternativa segura elegida por el propietario fue deshabilitar **Laravel** solo en este espacio de trabajo. Tras aplicar el cambio dejó de aparecer su servidor. Los otros servidores PHP pueden seguir activos y se evalúan por su ruta/hash revisados. Mantener VS Code abierto no equivale a mantener esta extensión activa: al habilitarla de nuevo puede reaparecer el problema. No se alteraron `.gitignore`, exclusiones del fingerprint, `.env` ni configuración de producción.

## Validación y límites

- 23 regresiones de captura: códigos 0/2/37, streams abundantes, Unicode, error de ejecución, datos UTF-8 inválidos, heredabilidad, padre que sale antes que el descendiente, snapshots parciales/completos, timeout, espera explícita de raíz y cierre natural. Cero fixtures activas y cero terminaciones forzosas.
- Integración: adaptador conserva códigos 0/37; el Job Object original contiene descendientes vivos tras salir la raíz y llega naturalmente a `Active=0`. No se ejecuta su rama de terminación forzosa.
- 6 regresiones del detector aprobadas. Su fuente y política no se debilitan.
- SQLite acotado: 257 aprobadas, 1 omitida intencionalmente, 821 aserciones, cero fallos/errores/risky; 63,536 segundos. Incluye bootstrap, preflight simulado, guardas, segunda sesión simulada, integridad y Excel. La omisión del snapshot real en SQLite no demuestra ejecución PostgreSQL.
- Enumeración offline: 116 nombres originales en el mismo orden, sin duplicados, cinco restricciones de matrículas y un snapshot Excel; no ejecuta las pruebas. Las etapas 2–7 permanecen excluidas.
- No cambia ningún PHP de aplicación, migración, prueba PHPUnit o configuración. Se conserva por hashes la evidencia previa de suite SQLite completa, Larastan 6 y Pint acotado; no se repite toda la suite ni se declara Pint global aprobado sobre formato preexistente ajeno.
- Revisión independiente: corrigió carreras de snapshot, lectura sin límite de longitud, decodificación dependiente, timeout incidental del wrapper, evidencia binaria omitida y codificación inicial del driver. El parseo PowerShell y las regresiones verifican la versión corregida.

Los informes externos fijan huella inicial/final, delta autorizado, hashes, estado del detector y los dos cálculos consecutivos. La nueva baseline solo incorpora los archivos de esta corrección documentada; no acepta diferencias desconocidas. Las guardas PHP, el perfil, el índice y los dos stashes deben conservar sus bytes. Los controles existentes de variables libpq rechazan los nombres enumerados en `PostgresTestGuard::FORBIDDEN_ENV`; no se presenta esa lista como una prohibición universal de todo nombre que empiece por `PG`.

## Futura ejecución

Solo con autorización independiente nueva, usar el driver existente con `-ExecuteAuthorizedStage1 -EvidenceDirectory <cápsula externa>\execution-estabilizada-01`. El informe externo proporciona la ruta absoluta y los hashes finales. El switch no es autorización. Antes de abrir consentimiento deben pasar de nuevo los controles locales, identidad/probe de lectura y los 116 nombres exactos; la ejecución conserva `--stop-on-error` y `--stop-on-failure`. No iniciar etapas 2–7, concurrencia o restauración.

Esta misión no aporta validación PostgreSQL nueva ni demuestra la desaparición real de los 79 risky allí. Esa comprobación sigue pendiente de la etapa 1 autorizada.
