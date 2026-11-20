# Detector Windows y arnés PG-TEST-05: estado consolidado

30/10/2026. Los auxiliares son externos al repositorio; los originales y sus evidencias se preservan. Esta documentación no autoriza ejecutar PostgreSQL funcional, activar consentimiento ni cerrar procesos.

## Frontera de seguridad

El detector interpreta argv mediante `CommandLineToArgvW`, el AST PowerShell y rutas/hash exactos de entradas revisadas. Inspecciona PID, PPID, ejecutable y creación, incluidos los descendientes que no son PHP/PowerShell. Una cadena que menciona PHPUnit no demuestra ejecución. Tampoco ausencia de hijos, bajo consumo o procedencia de VS Code demuestra inactividad.

Las entradas `Ambiguous` y `ActiveTest` bloquean. No hay exclusión global de PowerShell ni por PID propio/ascendencia. La entrada de una inspección específica puede reconocerse por ruta y SHA-256 revisados; sus descendientes se siguen examinando. Las sesiones interactivas persistentes requieren revisión del propietario: Win32_Process no informa sus pipelines/runspaces actuales ni CWD.

La consulta es una fotografía, no una exclusión mutua del sistema operativo. Hay carreras por creación posterior, PID reutilizados y procesos que salen durante la consulta. Cada etapa debe repetir el control inmediatamente antes de operar. El Job Object contiene los descendientes de la ejecución propia: creación suspendida, asignación antes de reanudar, sin breakaway y `KILL_ON_JOB_CLOSE`. No se promete aislar brokers/remotos ni código hostil del mismo usuario que puede acceder a credenciales o sustituir código.

## Correcciones demostradas en la consolidación

1. **Ascendencia/PID reutilizado:** antes se comparaba la creación de la hoja con todos los antecesores. Un abuelo reutilizado podía ser posterior a su hijo pero anterior a la hoja. Ahora se comprueba cada enlace hijo-padre. Una regresión sintética falla antes y pasa después; la propagación a workers válidos permanece.
2. **Invocación de método PowerShell:** una llamada como `Process.Start(...)` junto a `Get-Content` podía resultar `Inspector` porque no genera `CommandAst`. Ahora la presencia de `InvokeMemberExpressionAst` impide esa aceptación y conserva el rechazo. La reproducción solo analiza texto; no ejecuta la llamada.
3. **stderr nativo en PowerShell 5.1:** el patrón `2>&1` con `ErrorActionPreference=Stop` promovía stderr a excepción antes de completar el flujo. La nueva copia de etapa usa `ProcessStartInfo`, drena ambos streams por separado y conserva código nativo y excepción de transporte. La reproducción con salida/error conocidos y exit 7 no carga Laravel.
4. **Primera aserción fallida:** `--stop-on-error` no detiene un fallo de aserción. La copia preparada añade `--stop-on-failure`, sin cambiar el filtro. La fixture local demuestra dos pruebas contabilizadas antes y una después.

La nueva copia del wrapper conserva try/finally y retirada del consentimiento; para cerrar exclusivamente su Job se elimina la llamada redundante a `taskkill /PID`. La identidad del objeto Job evita depender del número PID para cerrar sus descendientes. No se ensayó terminación forzosa en esta intervención: las fixtures terminaron naturalmente, con `Active=0` antes de liberar handles. La rama de ejecución real del wrapper no se ejecutó.

## Codificación y evidencias

- Huella Git sobre bytes de archivos y JSON canónico ASCII, independiente de UTF-8/IBM850 y cultura. Los cambios documentales autorizados producen una baseline nueva; nunca adoptar diferencias inexplicadas.
- Protocolo PHP y capturas nuevas: UTF-8 explícito. PowerShell `EncodedCommand` usa UTF-16LE. Un payload IBM850 que no satisface el protocolo se rechaza; no se declara captura binaria sin pérdida.
- La evidencia original `etapa1-ronda4.txt` tiene BOM UTF-16. Decodificarla como UTF-8 impedía encontrar el resumen: no indica pruebas inexistentes ni un problema del servidor.
- Los streams nuevos se conservan separados y también se genera salida combinada para compatibilidad del informe. Los archivos acompañantes se excluyen de la comparación de evidencias previas solo por sus nombres exactos; no se sobrescriben.
- No registrar contraseñas, verificadores, código inline arbitrario ni valores sensibles de argumentos. Los comandos completos se analizan en memoria; se conserva representación saneada y hash. No ocultar un código nativo no cero mediante un mensaje genérico.

## Resultado local y límites

Seis regresiones focalizadas, diez simulaciones reales de procesos y diez regresiones adicionales aprobadas. El proceso PHPUnit de esas simulaciones usa una fixture externa inerte y autoload, sin Laravel ni PostgreSQL. Sus roots/workers finalizaron naturalmente y los Jobs llegaron a cero. También pasaron las pruebas locales de captura nativa y de detención por fallo.

La arquitectura Laravel/PHPUnit no se volvió a modificar: se verificó mediante 258 pruebas acotadas, 821 aserciones y la suite SQLite completa (1.211 aprobadas, 1 omitida, 7.759 aserciones). Los avisos, excepciones y manipulación indebida de manejadores siguen siendo detectados en fixtures negativas. El snapshot real sigue omitido en SQLite por diseño.

Pint reporta formato preexistente en `MatriculaIntegrityConstraintsTest.php` y `ReportExcelExportTest.php` (finales de línea y espaciado). Se preservan sus bytes; no se presenta un Pint global verde. Los otros ocho archivos del arnés comprobados no presentan incidencias de formato. No hay frontend autorizado modificado, por lo que no corresponde build.

## Cápsula de ejecución preparada

Ubicación externa nueva: `scratchpad/pgt05-consolidacion-ready`, en la sesión que contiene las evidencias anteriores. `ready-sha256.json` fija sus componentes y `release.json` fija la baseline final, HEAD, índice, stashes, perfil, selección y evidencias históricas. El informe externo de consolidación contiene las rutas absolutas y hashes.

Las cápsulas `pgt05b2a12-ready` y `pgt05b2a14-ready` conservan su valor histórico, pero no deben ejecutarse con la nueva huella documental. No editar sus manifiestos para conseguir aceptación.

Antes de una autorización nueva: verificar la cápsula y ejecutar únicamente `validate-offline.ps1`. El driver sin `-ExecuteAuthorizedStage1` rechaza antes de crear `execution`. Ese switch no concede autorización. El driver autorizado, solo en una fase futura, repite inventario, perfil, huella, identidad/probe, enumeración exacta y guardas antes de abrir consentimiento.

La ejecución real de etapa 1, sus reconstrucciones implícitas de esquema, retirada del consentimiento y cierre forzoso si fuera necesario requieren autorización independiente. La aceptación funcional sigue siendo 116 ejecutadas, snapshot no omitido, cinco pruebas de restricciones aprobadas, cero fallos/errores/risky y perfil restaurado exactamente. No continuar con etapas 2–7, concurrencia o restauración.

## Estabilización posterior de captura y editor

La [corrección de captura y LSP del 30/10](postgresql-testing-estabilizacion-captura-lsp.md) sustituye los pipes del adaptador de la cápsula existente por archivos y espera del proceso raíz. Conserva el detector, su política y el Job de PHPUnit. No se crea un Job para el postmaster. El propietario deshabilitó Laravel LSP en este espacio de trabajo como alternativa a su ruta temporal fijada en código; no se excluyen sus archivos del fingerprint. El informe externo distingue las validaciones locales nuevas de la recuperación PostgreSQL histórica y fija los componentes actualizados antes de cualquier autorización futura.
