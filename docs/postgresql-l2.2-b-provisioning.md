# L2.2-B1: procedimiento de aprovisionamiento PostgreSQL aislado (revisión 3, actualización D3)

Estado: **preparado, no ejecutado ni autorizado**. Este documento no crea clústeres, servicios, cuentas, roles, bases ni ACL, y no autoriza migraciones ni pruebas contra PostgreSQL. Complementa [docs/postgresql-l2.2-a.md](postgresql-l2.2-a.md); donde ambos difieran en aprovisionamiento (HBA, cuenta de servicio, locale, SQL), **prevalece este documento**. El guard de L2.2-A no se modifica.

**Actualización B2 — incidente S1:** el estado anterior y las referencias a autorización pendiente describen la preparación histórica B1. Posteriormente se concedió autorización A y S1 se ejecutó parcialmente: raíz vacía y evidencia `planned`/`identity.pending`, sin servicio ni clúster nuevo. La ejecución está detenida. Esta corrección documental no autoriza recuperar evidencias ni continuar; el estado parcial y los bloques futuros están delimitados en §7.1.

Esta revisión cierra documentalmente los dos P1, el P2 de comprobaciones y el P3 de concurrencia de la auditoría final. D3 queda cerrada documentalmente con todas las mediciones comunicadas por el operador (§8.1). No acredita ejecución real del aprovisionamiento ni aceptación V16. Sigue pendiente la autorización A; la autorización B para pruebas es posterior e independiente. La correspondencia está en el §14.

## 1. Entorno comprobado (solo lectura, 27/10/2026)

| Aspecto | Hallazgo |
| --- | --- |
| PostgreSQL | Una instalación EDB 18.6 en `C:\Program Files\PostgreSQL\18`. `psql` no está en `PATH`: usar la ruta completa. |
| Laragon | Independiente y **sin PostgreSQL**. Los binarios salen de la instalación EDB; solo cambian directorio de datos, servicio y cuenta. |
| Servicio cotidiano | `postgresql-x64-18`, arranque automático, cuenta `NT AUTHORITY\NetworkService`, comando `"…\bin\pg_ctl.exe" runservice -N "postgresql-x64-18" -D "C:\Program Files\PostgreSQL\18\data" -w`, puerto 5432 en `0.0.0.0` y `::`. **No se toca.** |
| ACL del directorio cotidiano | Concede acceso a `NetworkService`, al operador, a SYSTEM y a Administradores. No contiene *Usuarios*, *Usuarios autentificados* ni *Todos*. |
| `C:\` | Concede a *Usuarios autentificados* permiso de modificación en carpetas nuevas: un directorio creado bajo `C:\pgdata` lo heredaría. **Hay que cortar la herencia.** |
| Sesión | Token administrativo filtrado (sin elevar). `postgres.exe` e `initdb` rechazan un token administrativo activo. |
| Variables `PG*` y `pgpass.conf` | No existen en la sesión comprobada. |
| Nombres de grupo | Windows está en español. Los comandos usan SID conocidos (`S-1-5-18`, `S-1-5-32-544`) y resuelven la identidad del operador en tiempo de ejecución; no contienen SID personales. |
| Sintaxis comprobada con `--help` | `pg_ctl register [-D] [-N] [-U] [-P] [-S auto\|demand] [-e] [-W] [-t] [-o]`; `postgres -C <parámetro>` imprime un parámetro y sale; `initdb --auth`, `--data-checksums`, `--locale-provider`, `--icu-locale`, `--builtin-locale`, `--lc-collate`, `--lc-ctype`, `--pwprompt`. |

Los puertos TCP excluidos de Windows cambian con reinicios o con Hyper-V. Que 55432 esté fuera hoy no se da por válido: se revalida en el Preflight (§5).

## 2. Identidad exacta de los recursos (fuente única)

Aprovisionamiento (§6) y rollback (§7) usan **solo** estos recursos, con estos valores literales. Ningún comando los construye a partir de entrada externa.

| Recurso | Valor literal | Creado en |
| --- | --- | --- |
| Servicio | `postgresql-horus-l2-test` | S2 |
| Cuenta del servicio | `NT SERVICE\postgresql-horus-l2-test` | S2 (la crea Windows) |
| Comando del servicio (esperado) | `"C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe" runservice -N "postgresql-horus-l2-test" -D "C:\pgdata\horus_l2_test"` seguido opcionalmente de ` -w` | S2 |
| Directorio raíz | `C:\pgdata` (solo contendrá `horus_l2_test`) | S1 |
| Directorio de datos | `C:\pgdata\horus_l2_test` | S1 |
| Evidencia externa | `%LOCALAPPDATA%\HorusL2Provisioning\identity.dpapi`, del mismo operador en N/E | S1, antes de crear datos |
| Puerto / dirección | `55432` / `127.0.0.1` | S4 |
| Roles e instancias internas | `horus_l2_provisioner`, `horus_l2_owner`, `horus_l2_test_runner`, `horus_l2_restore_test_runner`; bases `horus_l2_test`, `horus_l2_restore_test` | S6, dentro del clúster nuevo |

Fuera de alcance, intocable: servicio `postgresql-x64-18`, `C:\Program Files\PostgreSQL\18\data`, puerto 5432, base `horus_group`, `.env`, `.pgpass`.

Prohibido en todo el procedimiento y el rollback: comodines en rutas de borrado, `Stop-Process`, `taskkill`, `takeown`, `sc.exe delete`, `Remove-Item` sin `-LiteralPath`, y cualquier comando que nombre `postgresql-x64-18` o el directorio cotidiano salvo para **leer** su configuración.

Consolas: **N** = no elevada (como el operador), **E** = elevada. Las dos deben ser del mismo usuario. Bloque de constantes común (se repite al inicio de cada bloque que lo necesita):

```powershell
$bin  = 'C:\Program Files\PostgreSQL\18\bin'
$svc  = 'postgresql-horus-l2-test'
$root = 'C:\pgdata'
$dir  = 'C:\pgdata\horus_l2_test'
$isElevated = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.SecurityIdentifier]'S-1-5-32-544')
```

### 2.1 Prólogo obligatorio y registro de pertenencia (solo para ejecución futura autorizada)

Antes de cada bloque operativo cargar en **esa consola** las constantes anteriores y las funciones siguientes. Windows PowerShell 5.1 o PowerShell 7 en Windows; no usar otra cuenta con `runas`. No ejecutar ahora. `$ErrorActionPreference` convierte errores de lectura en paradas; no sustituye la comprobación de códigos nativos.

La evidencia está fuera de `C:\pgdata`, protegida con ACL y DPAPI `CurrentUser`. Registra operación, operador, equipo, rutas, servicio, cuenta, etapa e identidades NTFS. La cuenta PostgreSQL no recibe acceso al registro. DPAPI detecta alteraciones del blob; operador y administradores del equipo siguen siendo de confianza: no protege contra un administrador malicioso ni contra sustituciones concurrentes por el propio operador. No trabajar con otro aprovisionador simultáneo ni recrear un registro perdido a partir de los recursos existentes.

```powershell
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false # PS7: códigos comprobados explícitamente; variable inerte en 5.1
Add-Type -AssemblyName System.Security
$bin = 'C:\Program Files\PostgreSQL\18\bin'
$svc = 'postgresql-horus-l2-test'
$root = 'C:\pgdata'
$dir = 'C:\pgdata\horus_l2_test'
$opSid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$evidence = [IO.Path]::GetFullPath((Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'HorusL2Provisioning'))
if ($evidence.StartsWith('C:\pgdata\', [StringComparison]::OrdinalIgnoreCase)) { throw 'STOP: evidencia dentro de la raíz de datos' }
$record = Join-Path $evidence 'identity.dpapi'
function Assert-NoReparse([string]$Path) {
  $p = [IO.Path]::GetFullPath($Path)
  while ($p) {
    if (Test-Path -LiteralPath $p -ErrorAction Stop) {
      if ((Get-Item -LiteralPath $p -Force -ErrorAction Stop).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'STOP: punto de reanálisis' }
    }
    $p = [IO.Path]::GetDirectoryName($p)
  }
}
function Assert-EvidenceAcl([string]$Path) {
  Assert-NoReparse $Path
  $acl = Get-Acl -LiteralPath $Path -ErrorAction Stop
  $allowed = @('S-1-5-18','S-1-5-32-544',$opSid)
  if ($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -notin $allowed) { throw 'STOP: propietario de evidencia inesperado' }
  $seen = @()
  foreach ($r in $acl.Access) {
    $sid = $r.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
    if ($sid -notin $allowed -or $r.AccessControlType -ne 'Allow' -or
        ($r.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -ne [Security.AccessControl.FileSystemRights]::FullControl) { throw 'STOP: ACL de evidencia inesperada' }
    $seen += $sid
  }
  if (@($allowed | Where-Object { $_ -notin $seen }).Count -ne 0) { throw 'STOP: ACL de evidencia incompleta' }
  if ($Path -eq $evidence -and -not $acl.AreAccessRulesProtected) { throw 'STOP: evidencia con herencia activa' }
}
function Get-DirectoryId([string]$Path) {
  Assert-NoReparse $Path
  if (-not (Get-Item -LiteralPath $Path -Force -ErrorAction Stop).PSIsContainer) { throw 'STOP: no es directorio' }
  $output = @(& "$env:SystemRoot\System32\fsutil.exe" file queryfileid $Path 2>&1)
  if ($LASTEXITCODE -ne 0) { throw 'STOP: no se pudo leer identidad NTFS' }
  $ids = [regex]::Matches(($output -join "`n"), '(?i)0x[0-9a-f]{32}\b')
  if ($ids.Count -ne 1) { throw 'STOP: identidad NTFS no reconocida' }
  return $ids[0].Value.ToLowerInvariant()
}
function Save-Identity($Identity, [switch]$First) {
  Assert-EvidenceAcl $evidence
  $tmp = Join-Path $evidence 'identity.pending'
  if (Test-Path -LiteralPath $tmp -ErrorAction Stop) { throw 'STOP: actualización interrumpida; revisión manual' }
  $plain = [Text.Encoding]::UTF8.GetBytes(($Identity | ConvertTo-Json -Depth 4 -Compress))
  $blob = [Security.Cryptography.ProtectedData]::Protect($plain, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
  $f = [IO.File]::Open($tmp, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
  try { $f.Write($blob, 0, $blob.Length); $f.Flush($true) } finally { $f.Dispose() }
  Assert-EvidenceAcl $tmp
  if ($First) { [IO.File]::Move($tmp, $record) } else {
    Assert-EvidenceAcl $record
    [IO.File]::Replace($tmp, $record, [System.Management.Automation.Language.NullString]::Value)
  }
  Assert-EvidenceAcl $record
}
function Read-Identity {
  Assert-EvidenceAcl $evidence
  if (Test-Path -LiteralPath (Join-Path $evidence 'identity.pending') -ErrorAction Stop) { throw 'STOP: registro interrumpido' }
  Assert-EvidenceAcl $record
  $blob = [IO.File]::ReadAllBytes($record)
  $plain = [Security.Cryptography.ProtectedData]::Unprotect($blob, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
  $id = [Text.Encoding]::UTF8.GetString($plain) | ConvertFrom-Json -ErrorAction Stop
  if ($id.Version -ne 1 -or $id.Operator -cne $opSid -or $id.Machine -cne $env:COMPUTERNAME -or
      $id.Root -cne $root -or $id.Directory -cne $dir -or $id.Service -cne $svc -or
      $id.Account -cne 'NT SERVICE\postgresql-horus-l2-test' -or
      $id.Operation -notmatch '^[0-9a-f-]{36}$' -or
      $id.Stage -notin @('planned','root-created','directories-created','service-registered','initdb-starting','initialized','configured','started','sql-starting','sql-complete','sealed','rolled-back')) { throw 'STOP: registro inconsistente' }
  return $id
}
function Assert-Resources($Identity) {
  if ($Identity.RootId -notmatch '^0x[0-9a-f]{32}$' -or (Get-DirectoryId $root) -cne $Identity.RootId) { throw 'STOP: raíz sustituida o no acreditada' }
  if ($Identity.DirectoryId -notmatch '^0x[0-9a-f]{32}$' -or (Get-DirectoryId $dir) -cne $Identity.DirectoryId) { throw 'STOP: datos sustituidos o no acreditados' }
}
function Get-ClusterId {
  # N: leer configuración con -C y pg_control con pg_controldata; sin iniciar ni conectar.
  $value = @(& "$bin\postgres.exe" -D $dir -C data_directory 2>&1)
  if ($LASTEXITCODE -ne 0 -or $value.Count -ne 1 -or $value[0] -cne 'C:/pgdata/horus_l2_test') { throw 'STOP: directorio PostgreSQL inesperado' }
  # LC_MESSAGES evita depender del idioma del campo de pg_controldata.
  $oldLocale = $env:LC_MESSAGES
  $oldAll = $env:LC_ALL
  try {
    $env:LC_ALL = 'C'
    $env:LC_MESSAGES = 'C'
    $output = @(& "$bin\pg_controldata.exe" -D $dir 2>&1)
    if ($LASTEXITCODE -ne 0) { throw 'STOP: pg_controldata falló' }
  } finally { $env:LC_MESSAGES = $oldLocale; $env:LC_ALL = $oldAll }
  $matches = @($output | Where-Object { $_ -match '^Database system identifier:\s+[0-9]+\s*$' })
  if ($matches.Count -ne 1) { throw 'STOP: identificador de clúster no reconocido' }
  return ([regex]::Match($matches[0], '[0-9]+')).Value
}
function Assert-TestPortFree {
  $connections = @(Get-NetTCPConnection -ErrorAction Stop)
  if (@($connections | Where-Object LocalPort -eq 55432).Count -ne 0) { throw 'STOP: 55432 en uso' }
  foreach ($family in 'ipv4','ipv6') {
    $ranges = @(& "$env:SystemRoot\System32\netsh.exe" int $family show excludedportrange protocol=tcp 2>&1)
    if ($LASTEXITCODE -ne 0) { throw 'STOP: no se pudieron consultar las exclusiones TCP' }
    if ($ranges.Count -eq 0) { throw 'STOP: netsh sin salida verificable' }
    foreach ($line in $ranges) {
      if ($line -match '^\s*(\d+)\s+(\d+)\s*\*?\s*$') {
        $first = [int]$Matches[1]; $last = [int]$Matches[2]
        if ($first -lt 1 -or $last -gt 65535 -or $first -gt $last) { throw 'STOP: rango TCP inválido' }
        if ($first -le 55432 -and 55432 -le $last) { throw 'STOP: 55432 excluido' }
      } elseif ($line -match '^\s*\d') {
        throw 'STOP: formato de rango TCP no reconocido'
      }
    }
  }
}
function Get-SafeTree([string]$Path) {
  Assert-NoReparse $Path
  $pending = New-Object 'System.Collections.Generic.Stack[string]'
  $pending.Push($Path)
  while ($pending.Count -gt 0) {
    foreach ($item in @(Get-ChildItem -LiteralPath $pending.Pop() -Force -ErrorAction Stop)) {
      if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'STOP: árbol con punto de reanálisis' }
      $item
      if ($item.PSIsContainer) { $pending.Push($item.FullName) }
    }
  }
}
```

`fsutil queryfileid` se usa solo para leer una identidad NTFS, con salida hexadecimal y código de salida obligatorio. Antes de S1 verificar que `Get-DirectoryId $env:LOCALAPPDATA` funciona desde N: si requiere elevación o la salida no se reconoce, **parar sin crear recursos**, revisar el mecanismo y no omitirlo. [Microsoft: fsutil](https://learn.microsoft.com/en-us/windows-server/administration/windows-commands/fsutil-file), [DPAPI](https://learn.microsoft.com/en-us/dotnet/api/system.security.cryptography.protecteddata).

Cada transición indicada abajo exige leer el registro, contrastar recursos y guardar la nueva etapa **antes de continuar**. Un fallo al guardar impide la siguiente etapa. No promover una etapa manualmente para permitir borrados.

Corrección documental posterior al incidente S1 de L2.2-B2: Windows PowerShell 5.1 convierte `$null` en cadena vacía al enlazar este argumento `string`. `NullString.Value` entrega un null CLR real, admitido por `File.Replace` cuando no se solicita respaldo. No cambia la comprobación de pendientes ni autoriza recuperar uno. La conversión se verificó en memoria; no se ha validado una sustitución real con la llamada corregida. La recuperación excepcional del §7.1 usa un respaldo explícito, no un null. Véanse [NullString](https://learn.microsoft.com/en-us/dotnet/api/system.management.automation.language.nullstring) y [File.Replace](https://learn.microsoft.com/en-us/dotnet/api/system.io.file.replace?view=netframework-4.8.1).

## 3. Decisiones D1–D6

| # | Decisión | Estado |
| --- | --- | --- |
| D1 | Cuenta de servicio: cuenta virtual `NT SERVICE\postgresql-horus-l2-test`. **Sin alternativa automática.** | **Condicionada a la prueba real de S2 y S5.** La compatibilidad es documental (PostgreSQL entrega `-U` a `CreateService` y Windows admite cuentas virtuales sin contraseña) pero **no está demostrada en este equipo**. Si falla: parada, rollback y nueva evaluación (§4). Nunca `NetworkService` ni `LocalSystem`. |
| D2 | Datos en `C:\pgdata\horus_l2_test`, fuera del repositorio y de OneDrive, con herencia NTFS cortada. | Definitiva |
| D3 | UTF8, libc, `Spanish_Spain.1252` para colación y ctype; locale, reglas ICU y versión registrados como NULL por el operador (§8.1). Los checksums **no** necesitan coincidir. | Cerrada documentalmente: A–E recibidas y revisadas; aceptación real V16 posterior |
| D4 | Concurrencia con segunda `Connection` creada con `db.factory` protegida (§10). | `ConcurrentTestSession` implementada y probada con dobles; validación PostgreSQL real pendiente de autorización B |
| D5 | Correcciones de la auditoría final en este documento. | Revisión 3; validación estática, sin aprovisionamiento |
| D6 | La autorización de aprovisionamiento se concede por separado y por escrito, con el alcance del §13. Preparar este documento no la implica. | Pendiente |

### Cuenta virtual: lo que se sabe y lo que no

- Una cuenta virtual no tiene contraseña ni sesión interactiva; la define Windows a partir del nombre del servicio, por lo que `icacls` solo la resuelve **después** de registrar el servicio.
- Es miembro implícito de *Usuarios autentificados*, de modo que puede leer `C:\Program Files\PostgreSQL\18\bin` y **no** puede leer `C:\Program Files\PostgreSQL\18\data` ni el repositorio mientras sus ACL no incluyan esos grupos (comprobado para el directorio cotidiano; el repositorio hereda solo SYSTEM, Administradores y el operador). Se verifica con V6 y V12.
- `postgres.exe` rechaza privilegios administrativos; la cuenta virtual no es administradora y *Iniciar sesión como servicio* le es implícito.
- No se incluye `sc.exe sidtype` en el procedimiento: no se ha demostrado que haga falta. Si S5 falla por acceso denegado, se **detiene** el proceso; no se prueba `sidtype` ni otra cuenta de forma improvisada.

## 4. Condiciones de parada (aplican a todo el documento)

Detenerse y no continuar ante cualquiera de estas condiciones. Luego, ejecutar el rollback que corresponda a la etapa (§7) y reportar; no improvisar.

1. Un resultado no coincide con el esperado de un bloque.
2. `pg_ctl register` devuelve error, o la cuenta o el comando registrados no coinciden con el §2.
3. El servicio no arranca, o arranca con otra cuenta.
4. `postgres -C` no devuelve exactamente puerto 55432, `listen_addresses` 127.0.0.1 y el directorio nuevo.
5. Alguna ACL concede acceso a un principal fuera del conjunto permitido (§6, S3).
6. El servicio nuevo puede leer el directorio cotidiano o el repositorio (V12).
7. Cualquier comando de PostgreSQL muestra puerto, directorio o `cluster_name` distinto de los esperados.
8. Un bloque pide privilegios que la consola no tiene.
9. `initdb` rechaza una combinación de locale (no se prueban variantes automáticamente).

**No hay sustituto automático de cuenta.** Si la cuenta virtual falla, el resultado de L2.2-B1 es «procedimiento no viable tal como está»: se hace rollback y se abre una nueva evaluación con la evidencia (código de error, `sc qc`, registro del servicio). Cualquier cuenta alternativa requiere un anexo revisado y una autorización distinta.

## 5. Fase 0: comprobaciones de solo lectura

### 5.1 Línea base de la instancia cotidiana (N, solo lectura)

```powershell
$bin = 'C:\Program Files\PostgreSQL\18\bin'
$baseline = Join-Path $env:TEMP 'horus_l2_baseline_before.txt'
@(
  'SERVICE'; (Get-CimInstance Win32_Service -Filter "Name='postgresql-x64-18'" -ErrorAction Stop | Format-List Name,State,StartMode,StartName,PathName | Out-String)
  'ACL';     (Get-Acl -LiteralPath 'C:\Program Files\PostgreSQL\18\data' -ErrorAction Stop).Sddl
  'LISTEN';  (Get-NetTCPConnection -LocalPort 5432 -State Listen -ErrorAction Stop | Sort-Object LocalAddress | Format-Table LocalAddress,LocalPort -Auto | Out-String)
) | Set-Content -LiteralPath $baseline -Encoding ASCII
& "$bin\postgres.exe" --version
if ($LASTEXITCODE -ne 0) { throw 'STOP: no se pudo comprobar versión' }
```

El archivo está en `%TEMP%`, fuera del repositorio, y no contiene secretos. Se compara al final (V14).

### 5.2 Preflight 1: antes de S1 (N o E; solo lectura; se ejecuta inmediatamente antes de aprovisionar)

```powershell
$svc  = 'postgresql-horus-l2-test'
$root = 'C:\pgdata'
if (Get-Service | Where-Object Name -eq $svc)              { throw 'STOP: el servicio de pruebas ya existe' }
if (Test-Path -LiteralPath $root)                          { throw 'STOP: C:\pgdata ya existe' }
Assert-TestPortFree
if ((Get-Service postgresql-x64-18).Status -ne 'Running') { throw 'STOP: el servicio cotidiano no está en el estado de la línea base' }
if (Get-ChildItem Env: | Where-Object Name -match '^PG')  { throw 'STOP: hay variables PG* en la sesión' }
if (Test-Path "$env:APPDATA\postgresql\pgpass.conf")       { throw 'STOP: existe pgpass.conf' }
'Preflight 1 correcto'
```

## 6. Procedimiento de aprovisionamiento (fase C; requiere D6)

**Estado histórico B1: todo sin ejecutar. Estado B2 actual: S1 parcial y detenida; S2–S7 sin ejecutar. No repetir S1 completo; ver §7.1.** Orden pensado para que lo más incierto (registrar la cuenta virtual) ocurra **antes** de `initdb`, cuando el rollback es solo borrar un directorio vacío, y para que los archivos de `initdb` hereden la ACL del servicio desde su creación.

| Etapa | Qué crea | Consola |
| --- | --- | --- |
| S1 | `C:\pgdata` y `C:\pgdata\horus_l2_test` vacío, con ACL propia | N |
| S2 | Servicio `postgresql-horus-l2-test` y ACL de su cuenta sobre el directorio vacío | E |
| S3 | `initdb` y verificación de ACL | N |
| S4 | Configuración (`postgresql.conf`, `pg_hba.conf`) y verificación antes de arrancar | N |
| S5 | Arranque | E |
| S6 | Roles, bases y marcadores | N |
| S7 | Verificaciones y desactivación del aprovisionador | N |

### S1: directorios con herencia cortada (N)

```powershell
$root = 'C:\pgdata'
$dir  = 'C:\pgdata\horus_l2_test'
$isElevated = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.SecurityIdentifier]'S-1-5-32-544')
if ($isElevated) { throw 'STOP: S1 se ejecuta sin elevar' }
# Cargar §2.1; el preflight debe haberse completado en esta consola.
Assert-NoReparse $evidence
if (Test-Path -LiteralPath $evidence -ErrorAction Stop) { throw 'STOP: evidencia previa; no sobrescribir' }
$null = Get-DirectoryId $env:LOCALAPPDATA
New-Item -ItemType Directory -Path $evidence -ErrorAction Stop | Out-Null
icacls $evidence /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' "*${opSid}:(OI)(CI)F"
if ($LASTEXITCODE -ne 0) { throw 'STOP: no se pudo proteger la evidencia; no crear datos' }
Assert-EvidenceAcl $evidence
$id = [pscustomobject]@{ Version=1; Operation=[guid]::NewGuid().ToString(); Operator=$opSid;
  Machine=$env:COMPUTERNAME; Root=$root; Directory=$dir; Service=$svc;
  Account='NT SERVICE\postgresql-horus-l2-test'; Stage='planned'; RootId=$null;
  DirectoryId=$null; SystemIdentifier=$null; SqlHashes=@{}; CreatedUtc=[DateTime]::UtcNow.ToString('o') }
Save-Identity $id -First
Assert-NoReparse $root
New-Item -ItemType Directory -Path $root -ErrorAction Stop | Out-Null
$op = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
icacls $root /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' "*${op}:(OI)(CI)F"
if ($LASTEXITCODE -ne 0) { throw 'STOP: ACL raíz falló; si no consta RootId, no ejecutar borrado automático' }
$id.RootId = Get-DirectoryId $root
$id.Stage = 'root-created'; Save-Identity $id
New-Item -ItemType Directory -Path $dir -ErrorAction Stop | Out-Null
$id.DirectoryId = Get-DirectoryId $dir
$id.Stage = 'directories-created'; Save-Identity $id
icacls $root
if ($LASTEXITCODE -ne 0) { throw 'STOP: lectura ACL raíz falló' }
icacls $dir
if ($LASTEXITCODE -ne 0) { throw 'STOP: lectura ACL datos falló' }
```

Esperado: `C:\pgdata` con exactamente tres entradas (SYSTEM, Administradores y el operador) y sin *Usuarios*, *Usuarios autentificados* ni *Todos*; `horus_l2_test` con las mismas entradas heredadas. Si no puede crear `C:\pgdata`, **parar**: no se crea desde una consola elevada.

### S2: registrar el servicio y conceder la ACL (E)

```powershell
$bin = 'C:\Program Files\PostgreSQL\18\bin'
$svc = 'postgresql-horus-l2-test'
$dir = 'C:\pgdata\horus_l2_test'
$isElevated = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.SecurityIdentifier]'S-1-5-32-544')
if (-not $isElevated) { throw 'STOP: S2 requiere consola elevada' }
$id = Read-Identity; Assert-Resources $id
if ($id.Stage -ne 'directories-created') { throw 'STOP: etapa incorrecta para S2' }
if (@(Get-ChildItem -LiteralPath $dir -Force -ErrorAction Stop).Count -ne 0) { throw 'STOP: el directorio de datos no está vacío' }
if (Get-Service | Where-Object Name -eq $svc) { throw 'STOP: el servicio ya existe' }

# -U es obligatorio: sin él pg_ctl registra NetworkService (la cuenta de la instancia cotidiana).
& "$bin\pg_ctl.exe" register -N $svc -D $dir -S demand -U 'NT SERVICE\postgresql-horus-l2-test'
if ($LASTEXITCODE -ne 0) { throw 'STOP: pg_ctl register falló; no hay alternativa automática (§4). Ejecutar R1 y R2.' }

$s = Get-CimInstance Win32_Service -Filter "Name='postgresql-horus-l2-test'"
$re = '^"C:\\Program Files\\PostgreSQL\\18\\bin\\pg_ctl\.exe" runservice -N "postgresql-horus-l2-test" -D "C:\\pgdata\\horus_l2_test"( -w)?$'
if ($s.PathName -notmatch $re)                                { throw 'STOP: comando del servicio inesperado. Ejecutar R1 y R2; no iniciar.' }
if ($s.StartName -ine 'NT SERVICE\postgresql-horus-l2-test')  { throw 'STOP: cuenta del servicio distinta de la prevista. No iniciar. Ejecutar R1 y R2.' }
if ($s.StartMode -ne 'Manual' -or $s.State -ne 'Stopped')      { throw 'STOP: modo de inicio o estado inesperado.' }
sc.exe qc $svc
if ($LASTEXITCODE -ne 0) { throw 'STOP: lectura del servicio falló' }

icacls $dir /grant 'NT SERVICE\postgresql-horus-l2-test:(OI)(CI)F'
if ($LASTEXITCODE -ne 0) { throw 'STOP: no se pudo conceder la ACL a la cuenta del servicio. Ejecutar R1 y R2.' }
icacls $dir
if ($LASTEXITCODE -ne 0) { throw 'STOP: lectura ACL falló' }
$id.Stage = 'service-registered'; Save-Identity $id
```

Esperado: `SERVICE_START_NAME` y `StartName` iguales a `NT SERVICE\postgresql-horus-l2-test`, inicio `Manual`, estado `Stopped`. Mientras la ACL no incluya a la cuenta, ni `NetworkService` ni la cuenta del servicio tienen acceso al directorio.

### S3: `initdb` y verificación de ACL (N)

D3 está resuelta documentalmente con el §8. Los argumentos están definidos con los valores medidos. La confirmación complementaria acredita únicamente esa revisión: no sustituye la autorización A, aún pendiente, ni permite ejecutar ahora el bloque. No hay marcadores de sustitución dentro del comando.

```powershell
$bin = 'C:\Program Files\PostgreSQL\18\bin'
$dir = 'C:\pgdata\horus_l2_test'
$isElevated = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.SecurityIdentifier]'S-1-5-32-544')
if ($isElevated) { throw 'STOP: initdb se ejecuta sin elevar' }
$id = Read-Identity; Assert-Resources $id
if ($id.Stage -ne 'service-registered') { throw 'STOP: etapa incorrecta para S3' }

# D3 principal: resultados comunicados por el operador desde pgAdmin 4.
$LocaleArgs = @('--locale-provider=libc','--lc-collate=Spanish_Spain.1252','--lc-ctype=Spanish_Spain.1252')
$D3ComplementariaRevisada = $true # cierre documental; autorización A independiente y pendiente
if (-not $D3ComplementariaRevisada) { throw 'STOP: revisar consultas complementarias D3 antes de S3' }

if ($null -eq $LocaleArgs -or @($LocaleArgs).Count -lt 2) { throw 'STOP: D3 sin resolver' }
if (@($LocaleArgs)[0] -notmatch '^--locale-provider=(libc|icu|builtin)$') { throw 'STOP: el primer argumento debe fijar el proveedor' }
foreach ($a in @($LocaleArgs)) {
  if ($a -notmatch '^--(locale-provider|locale|lc-collate|lc-ctype|icu-locale|builtin-locale)=[A-Za-z0-9_.@-]+$') { throw "STOP: argumento no admitido: $a (reglas ICU u otros valores requieren revisión)" }
}
if (@(Get-ChildItem -LiteralPath $dir -Force -ErrorAction Stop).Count -ne 0) { throw 'STOP: el directorio de datos no está vacío' }

# La contraseña del aprovisionador se pide por consola; no se guarda ni se pasa por argumento.
$id.Stage = 'initdb-starting'; Save-Identity $id
& "$bin\initdb.exe" -D $dir -U horus_l2_provisioner --pwprompt -E UTF8 --auth=scram-sha-256 --data-checksums @LocaleArgs
if ($LASTEXITCODE -ne 0) { throw 'STOP: initdb falló; R1 y R2 solo si acreditan pertenencia; contenido parcial sin ID queda para revisión manual' }
Assert-Resources $id
$id.SystemIdentifier = Get-ClusterId
$id.Stage = 'initialized'; Save-Identity $id
if ((Get-ClusterId) -cne (Read-Identity).SystemIdentifier) { throw 'STOP: identificador no coincide tras guardarlo' }
```

Los checksums se activan siempre de forma explícita: no cambian el comportamiento de la aplicación y no hace falta que coincidan con `horus_group`.

**Verificación de ACL heredadas y permisos efectivos sobre los archivos de `initdb`** (N; solo lectura). Falla si cualquier archivo o carpeta concede acceso a un principal ajeno al conjunto permitido, o carece de control total para la cuenta del servicio:

```powershell
$dir = 'C:\pgdata\horus_l2_test'
$sidOf = { param($n) (New-Object Security.Principal.NTAccount($n)).Translate([Security.Principal.SecurityIdentifier]).Value }
$svcSid = & $sidOf 'NT SERVICE\postgresql-horus-l2-test'
$opSid  = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$allowed = @('S-1-5-18','S-1-5-32-544',$opSid,$svcSid)
if (-not (Get-Acl -LiteralPath 'C:\pgdata' -ErrorAction Stop).AreAccessRulesProtected) { throw 'STOP: C:\pgdata conserva herencia' }
$full = [Security.AccessControl.FileSystemRights]::FullControl
$tree = @(Get-SafeTree $dir) # materializar: cualquier fallo invalida toda la verificación
$problems = foreach ($i in @(Get-Item -LiteralPath $dir -Force -ErrorAction Stop) + $tree) {
  $rules = (Get-Acl -LiteralPath $i.FullName -ErrorAction Stop).Access
  foreach ($r in $rules) {
    try { $sid = $r.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value } catch { $sid = 'IRRESOLUBLE' }
    if ($allowed -notcontains $sid -or $r.AccessControlType -ne 'Allow') { "{0}: principal o regla no permitida {1}" -f $i.FullName, $sid }
  }
  $svcFull = $rules | Where-Object { $_.AccessControlType -eq 'Allow' -and ($_.FileSystemRights -band $full) -eq $full -and ($_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -eq $svcSid) }
  if (-not $svcFull) { "{0}: la cuenta del servicio no tiene control total" -f $i.FullName }
}
if ($problems) { $problems | Select-Object -First 20; throw 'STOP: ACL incorrectas (§4, condición 5)' }
'ACL de initdb correctas'
```

Si la propagación no alcanzó a algún archivo, **parar**: no se aplica `icacls /T` ni `/reset` de forma improvisada.

### S4: configuración antes del primer arranque (N)

Escribe solo en `C:\pgdata\horus_l2_test`. Las líneas añadidas al final de `postgresql.conf` prevalecen sobre las del archivo generado (la última aparición gana). Codificación ASCII, sin BOM.

```powershell
$bin = 'C:\Program Files\PostgreSQL\18\bin'
$dir = 'C:\pgdata\horus_l2_test'
$id = Read-Identity; Assert-Resources $id
if ($id.Stage -ne 'initialized') { throw 'STOP: etapa incorrecta para S4' }
if (-not (Test-Path -LiteralPath "$dir\PG_VERSION")) { throw 'STOP: initdb no se completó' }

Add-Content -LiteralPath "$dir\postgresql.conf" -Encoding ASCII -Value @'

# --- L2.2-B1: instancia de pruebas aislada ---
port = 55432
listen_addresses = '127.0.0.1'
password_encryption = 'scram-sha-256'
ssl = off
cluster_name = 'horus_l2_test'
max_connections = 30
logging_collector = on
log_connections = on
log_disconnections = on
log_lock_waits = on
deadlock_timeout = '500ms'
idle_in_transaction_session_timeout = '120s'
lc_messages = 'C'
log_line_prefix = '%m [%p] %q%u@%d '
'@

# El primer match gana. La línea del aprovisionador lleva una marca para poder desactivarla (S7).
Set-Content -LiteralPath "$dir\pg_hba.conf" -Encoding ASCII -Value @'
host  all                    horus_l2_provisioner          127.0.0.1/32  scram-sha-256  # L2-PROVISIONER
host  horus_l2_test          horus_l2_test_runner          127.0.0.1/32  scram-sha-256
host  horus_l2_restore_test  horus_l2_restore_test_runner  127.0.0.1/32  scram-sha-256
host  all                    all                           0.0.0.0/0     reject
host  all                    all                           ::/0          reject
'@
```

`lc_messages = 'C'` solo estabiliza el texto de los errores en las pruebas (que deben comparar `SQLSTATE`); no afecta a los datos.

**Verificación previa al arranque** (N; no inicia el servidor). Falla si el puerto, la escucha o el directorio no son los previstos:

```powershell
$bin = 'C:\Program Files\PostgreSQL\18\bin'
$dir = 'C:\pgdata\horus_l2_test'
$v = @{}
foreach ($k in 'port','listen_addresses','data_directory','cluster_name','hba_file') {
  $out = @(& "$bin\postgres.exe" -D $dir -C $k 2>&1)
  if ($LASTEXITCODE -ne 0 -or $out.Count -ne 1) { throw 'STOP: no se pudo leer parámetro PostgreSQL' }
  $v[$k] = [string]$out[0]
}
$v.GetEnumerator() | Sort-Object Name | Format-Table -Auto
if ($v.port -ne '55432' -or $v.listen_addresses -ne '127.0.0.1' -or $v.cluster_name -ne 'horus_l2_test' `
    -or $v.data_directory -ne 'C:/pgdata/horus_l2_test' -or $v.hba_file -ne 'C:/pgdata/horus_l2_test/pg_hba.conf') { throw 'STOP: configuración inesperada (§4, condición 4)' }
$hba = Get-Content -LiteralPath "$dir\pg_hba.conf" | Where-Object { $_ -match '\S' -and $_ -notmatch '^\s*#' }
if (@($hba).Count -ne 5 -or ($hba -match 'trust')) { throw 'STOP: pg_hba.conf inesperado' }
if ((Get-ClusterId) -cne $id.SystemIdentifier) { throw 'STOP: clúster sustituido' }
$id.Stage = 'configured'; Save-Identity $id
'Configuración verificada'
```

### Preflight 2: inmediatamente antes de arrancar (E; solo lectura)

Repite el §5.2 adaptado: el servicio y el directorio **deben** existir y el puerto **debe** seguir libre.

```powershell
$svc = 'postgresql-horus-l2-test'
Assert-TestPortFree
$s = Get-CimInstance Win32_Service -Filter "Name='postgresql-horus-l2-test'"
if (-not $s -or $s.State -ne 'Stopped' -or $s.StartName -ine 'NT SERVICE\postgresql-horus-l2-test') { throw 'STOP: servicio no está en el estado previsto' }
if ((Get-Service postgresql-x64-18).Status -ne 'Running') { throw 'STOP: el servicio cotidiano cambió de estado' }
'Preflight 2 correcto'
```

### S5: arranque (E)

```powershell
$bin = 'C:\Program Files\PostgreSQL\18\bin'
$id = Read-Identity; Assert-Resources $id
if ($id.Stage -ne 'configured' -or (Get-ClusterId) -cne $id.SystemIdentifier) { throw 'STOP: identidad o etapa incorrecta para S5' }
Start-Service -Name 'postgresql-horus-l2-test' -ErrorAction Stop
& "$bin\pg_isready.exe" -h 127.0.0.1 -p 55432
if ($LASTEXITCODE -ne 0) { throw 'STOP: servidor no disponible; no continuar hacia S6' }
$id.Stage = 'started'; Save-Identity $id
```

`Assert-TestPortFree` acredita ausencia de conexiones TCP observadas y consulta correcta de exclusiones; no reserva el puerto. `pg_isready` con salida 0 acredita disponibilidad del servidor, **no autenticación** ni identidad. Cualquier salida distinta de 0 detiene S5. La autenticación e identidad se verifican después mediante `psql -f` en S6. Si el servicio no arranca, parar, revisar logs/eventos y aplicar R1/R2 solo con evidencia válida. No cambiar cuenta ni ejecutar `sc.exe sidtype`.

### S6: roles, bases y marcadores (N, archivos revisados con `psql -f`)

Solo con autorización A. Preparar **fuera del repositorio**, dentro de `$evidence`, los tres archivos siguientes, copiando exactamente sus bloques SQL. Heredan la ACL protegida de §2.1; verificarla antes de ejecutarlos. No contienen secretos. No usar sesión de pegado, entrada redirigida, `-1`/`--single-transaction`, `--echo-all` ni `--echo-queries`.

**`00-cluster.sql`**: `dbloc` ya contiene los valores principales medidos de §8.1; si cambian, detenerse y revisar los parámetros, sin sustituciones automáticas. No hay transacción envolvente: `CREATE DATABASE` no la admite. `\password` solicita las contraseñas por terminal sin incluirlas en el archivo, historial ni argumentos. Ejecutar desde una consola interactiva propia sin transcripción ni captura de teclado; no automatizar respuestas. La contraseña administrativa también se solicita con `-W`; no se reutiliza la cotidiana. Un fallo o cancelación detiene la etapa.

```sql
-- 0. Destino y locale (aborta si algo no coincide)
SELECT current_setting('port') = '55432'
   AND current_setting('cluster_name') = 'horus_l2_test'
   AND current_setting('data_directory') = 'C:/pgdata/horus_l2_test'
   AND current_database() = 'postgres'
   AND (SELECT system_identifier::text FROM pg_control_system()) = :'system_identifier' AS destino_correcto \gset
\if :destino_correcto
\else
  \echo 'DESTINO INCORRECTO: no continuar'
  SELECT 1/0; -- error deliberado: ON_ERROR_STOP hace salir con estado distinto de cero
\endif

-- Valores medidos por el operador; solo se ejecuta tras S3 y con autorización A.
\set dbloc 'LOCALE_PROVIDER libc LC_COLLATE ''Spanish_Spain.1252'' LC_CTYPE ''Spanish_Spain.1252'''
\if :{?dbloc}
\else
  \echo 'D3 sin resolver: defina dbloc con los valores del apartado 8'
  SELECT 1/0;
\endif

-- 1. Roles
CREATE ROLE horus_l2_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE horus_l2_test_runner LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE horus_l2_restore_test_runner LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

-- 2. Bases (fuera de una transacción envolvente)
CREATE DATABASE horus_l2_test OWNER horus_l2_owner TEMPLATE template0 ENCODING 'UTF8' :dbloc;
CREATE DATABASE horus_l2_restore_test OWNER horus_l2_owner TEMPLATE template0 ENCODING 'UTF8' :dbloc;

-- 3. Privilegios de conexión
REVOKE CONNECT, TEMPORARY ON DATABASE postgres, template0, template1, horus_l2_test, horus_l2_restore_test FROM PUBLIC;
GRANT CONNECT ON DATABASE horus_l2_test TO horus_l2_test_runner;
GRANT CONNECT ON DATABASE horus_l2_restore_test TO horus_l2_restore_test_runner;
ALTER ROLE horus_l2_test_runner SET default_transaction_isolation = 'read committed';
ALTER ROLE horus_l2_restore_test_runner SET default_transaction_isolation = 'read committed';
\password horus_l2_test_runner
\password horus_l2_restore_test_runner
SELECT 1 / (count(*) = 2)::integer FROM pg_authid
WHERE rolname IN ('horus_l2_test_runner','horus_l2_restore_test_runner') AND rolpassword IS NOT NULL;
```

**`10-main.sql`**, ejecutado con `-d horus_l2_test`. La validación inicial ocurre antes de cualquier modificación; los errores revierten este archivo completo.

```sql
BEGIN;
-- 4. Marcador en horus_l2_test
SELECT 1 / (current_setting('port') = '55432' AND current_database() = 'horus_l2_test'
  AND current_setting('data_directory') = 'C:/pgdata/horus_l2_test'
  AND current_setting('cluster_name') = 'horus_l2_test'
  AND (SELECT system_identifier::text FROM pg_control_system()) = :'system_identifier')::integer;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO horus_l2_test_runner;
CREATE SCHEMA horus_test_control AUTHORIZATION horus_l2_owner;
CREATE TABLE horus_test_control.authorization (
    database_name name PRIMARY KEY,
    database_oid oid NOT NULL,
    role_name name NOT NULL,
    purpose text NOT NULL
);
ALTER TABLE horus_test_control.authorization OWNER TO horus_l2_owner;
INSERT INTO horus_test_control.authorization
SELECT current_database(), oid, 'horus_l2_test_runner', 'horus-l2-destructive-tests'
FROM pg_database WHERE datname = current_database();
REVOKE ALL ON SCHEMA horus_test_control FROM PUBLIC;
REVOKE ALL ON horus_test_control.authorization FROM PUBLIC;
GRANT USAGE ON SCHEMA horus_test_control TO horus_l2_test_runner;
GRANT SELECT ON horus_test_control.authorization TO horus_l2_test_runner;
COMMIT;
```

**`20-restore.sql`**, ejecutado con `-d horus_l2_restore_test`:

```sql
BEGIN;
-- 5. Marcador en horus_l2_restore_test (mismo patrón, su propio runner)
SELECT 1 / (current_setting('port') = '55432' AND current_database() = 'horus_l2_restore_test'
  AND current_setting('data_directory') = 'C:/pgdata/horus_l2_test'
  AND current_setting('cluster_name') = 'horus_l2_test'
  AND (SELECT system_identifier::text FROM pg_control_system()) = :'system_identifier')::integer;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO horus_l2_restore_test_runner;
CREATE SCHEMA horus_test_control AUTHORIZATION horus_l2_owner;
CREATE TABLE horus_test_control.authorization (
    database_name name PRIMARY KEY,
    database_oid oid NOT NULL,
    role_name name NOT NULL,
    purpose text NOT NULL
);
ALTER TABLE horus_test_control.authorization OWNER TO horus_l2_owner;
INSERT INTO horus_test_control.authorization
SELECT current_database(), oid, 'horus_l2_restore_test_runner', 'horus-l2-destructive-tests'
FROM pg_database WHERE datname = current_database();
REVOKE ALL ON SCHEMA horus_test_control FROM PUBLIC;
REVOKE ALL ON horus_test_control.authorization FROM PUBLIC;
GRANT USAGE ON SCHEMA horus_test_control TO horus_l2_restore_test_runner;
GRANT SELECT ON horus_test_control.authorization TO horus_l2_restore_test_runner;
COMMIT;
```

**Ejecución secuencial** tras revisar los tres archivos y D3. Los hashes se guardan en la evidencia protegida para identificar el guion aceptado; no autentican por sí solos su contenido. No editar los archivos durante la ejecución.

```powershell
$id = Read-Identity; Assert-Resources $id
if ($id.Stage -ne 'started' -or (Get-ClusterId) -cne $id.SystemIdentifier) { throw 'STOP: identidad o etapa incorrecta para S6' }
$steps = @(
  @{ File='00-cluster.sql'; Database='postgres' },
  @{ File='10-main.sql'; Database='horus_l2_test' },
  @{ File='20-restore.sql'; Database='horus_l2_restore_test' }
)
$hashes = @{}
foreach ($step in $steps) {
  $path = Join-Path $evidence $step.File
  Assert-EvidenceAcl $path
  $hashes[$step.File] = (Get-FileHash -LiteralPath $path -Algorithm SHA256 -ErrorAction Stop).Hash
}
$id.SqlHashes = $hashes
$id.Stage = 'sql-starting'; Save-Identity $id
foreach ($step in $steps) {
  Assert-Resources $id
  $path = Join-Path $evidence $step.File
  Assert-EvidenceAcl $path
  if ((Get-FileHash -LiteralPath $path -Algorithm SHA256 -ErrorAction Stop).Hash -cne $hashes[$step.File]) { throw 'STOP: guion modificado' }
  $psqlArgs = @('-X','-W','-h','127.0.0.1','-p','55432','-U','horus_l2_provisioner',
    '-d',$step.Database,'--set=ON_ERROR_STOP=on',"--set=system_identifier=$($id.SystemIdentifier)",'-f',$path)
  & "$bin\psql.exe" @psqlArgs
  $sqlExit = $LASTEXITCODE
  if ($sqlExit -ne 0) { throw "STOP: S6 falló (código $sqlExit); no ejecutar S7 ni repetir el guion" }
}
$id.Stage = 'sql-complete'; Save-Identity $id
```

`psql -f` devuelve 3 ante error SQL con `ON_ERROR_STOP`, 1 ante fallo propio y 2 ante pérdida de conexión no interactiva: **cualquier valor distinto de 0 detiene el bloque**. Las conexiones separadas exigen contraseña administrativa por terminal. [PostgreSQL: psql](https://www.postgresql.org/docs/18/app-psql.html).

Revisión sin ejecutar PostgreSQL: el array `$psqlArgs` mantiene cada opción y valor como argumento separado, incluida la ruta de `-f` con espacios; no usa `Invoke-Expression` ni una cadena de comando recompuesta. La subexpresión `$($id.SystemIdentifier)` produce el valor de evidencia como texto dentro de un solo argumento `--set=system_identifier=…`. En SQL, `:'system_identifier'` lo cita como literal. `\set dbloc` conserva las comillas SQL interiores mediante comillas simples duplicadas, según las reglas de metacomandos de psql; `:dbloc` inserta el fragmento revisado sin citarlo entero. No cambiarlo por `:'dbloc'`, que produciría un único literal, ni usar `':system_identifier'`, que no interpola. `-X` evita psqlrc y `-f` procesa SQL y metacomandos; no sustituirlo por `-c`. Son comprobaciones documentales y de argumentos en memoria, no una prueba del cliente nativo ni de SQL real.

Un fallo deja `sql-starting`: los objetos confirmados de `00-cluster.sql` y los archivos anteriores pueden existir; no se asume atomicidad de S6 ni se reintenta. Detenerse y usar R0–R3 con la identidad ya registrada, o dejar los recursos para revisión manual si falla cualquier comprobación. Si el proceso termina después de confirmar SQL pero antes de guardar `sql-complete`, tampoco se pasa a S7. No se añaden `IF NOT EXISTS` para ocultar estados parciales.

No se concede membresía en `horus_l2_owner` a nadie. `TEMPORARY` queda revocado también a los runners: si una migración futura necesita tablas temporales, se evaluará por separado. La instancia no debe contener `horus_group` ni datos reales.

### S7: verificaciones y desactivación del aprovisionador

1. Leer la evidencia: exigir `Stage = sql-complete`, `Assert-Resources` e identificador coincidente. Si S6 falló o no consta su finalización, **S7 está prohibida**. Ejecutar las verificaciones de fase P (§11) con el aprovisionador activo. Para la recarga, abrir una sesión administrativa propia con `psql -X -W`, destino explícito 127.0.0.1:55432 y base `postgres`, y comprobar allí el `system_identifier` registrado antes de modificar HBA.
2. Desactivar su acceso. En una consola N, con la sesión del aprovisionador aún abierta:

```powershell
$dir = 'C:\pgdata\horus_l2_test'
$id = Read-Identity; Assert-Resources $id
if ($id.Stage -ne 'sql-complete' -or (Get-ClusterId) -cne $id.SystemIdentifier) { throw 'STOP: S6 no acreditada o identidad incorrecta' }
$lines = Get-Content -LiteralPath "$dir\pg_hba.conf"
$new = foreach ($l in $lines) { if ($l -match '# L2-PROVISIONER\s*$' -and $l -notmatch '^\s*#') { '#' + $l } else { $l } }
if (@($new | Where-Object { $_ -match '\S' -and $_ -notmatch '^\s*#' }).Count -ne 4) { throw 'STOP: pg_hba.conf inesperado' }
Set-Content -LiteralPath "$dir\pg_hba.conf" -Encoding ASCII -Value $new
```

   y, en la sesión de `psql` del aprovisionador, `SELECT pg_reload_conf();`: exigir `t`, consultar `pg_hba_file_rules` sin errores y cerrar con `\q`. Un `t` solo confirma el envío de la señal; no acredita que HBA se haya aplicado.
3. Comprobar V13: exigir rechazo específico por HBA, no aceptar contraseña incorrecta, timeout ni servidor caído como prueba de desactivación. Comprobar además acceso autenticado de los runners a sus bases. Solo después guardar `$id.Stage = 'sealed'; Save-Identity $id` en N. Si falla, parar sin registrar éxito.

**Mantenimiento controlado** (por ejemplo, para recrear una base): requiere autorización escrita y un plan específico que verifique la evidencia, detalle los guiones y las transiciones desde `sealed`, y termine con desactivación y V13. La única vía prevista es parar el servicio desde E, habilitar temporalmente la línea marcada `# L2-PROVISIONER`, iniciar, efectuar el trabajo autorizado y volver a desactivarla. No reutilizar literalmente S6/S7 ni editar la etapa para superar sus controles; sus precondiciones pertenecen al alta inicial.

## 7. Rollback (reversión acotada)

El rollback solo toca los recursos del §2. Se aplica por **instancia completa**: como la instancia es nueva y exclusiva, no se documentan `DROP` sueltos de roles o bases (S6 se revierte eliminando la instancia). Nada de lo que sigue nombra el servicio, el directorio ni la base cotidianos.

| Fallo tras… | Qué hay creado | Rollback |
| --- | --- | --- |
| S1 (directorios) | `C:\pgdata` y/o `horus_l2_test` vacíos | R2 |
| S2 (servicio, ACL) | Servicio registrado, directorio vacío | R1 y R2 |
| S3 (`initdb`) | Archivos de datos | R1 (si el servicio existe) y R2 |
| S4 (configuración) | Igual que S3 | R1 y R2 |
| S5 (arranque) | Servicio posiblemente en ejecución | R1 (lo detiene) y R2 |
| S6 (roles, bases, marcadores) | Datos del clúster nuevo | R1 y R2 |
| S7 (HBA modificado) | Igual que S6 | R1 y R2 |

La tabla indica el recorrido previsto, no concede permiso incondicional para borrar: la matriz de estados parciales inferior y las comprobaciones de evidencia de R1/R2 prevalecen.

Fuera del alcance de este rollback: `.env.pgsql-testing.local` se crea en una fase posterior y se borra por nombre literal solo en ella; no se guardan contraseñas en ningún otro lugar. Los runners PHP no se ejecutan en B1.

### R0: inspección (solo lectura; N o E)

Siempre antes de cualquier R1/R2. Anotar el resultado:

```powershell
$svc = 'postgresql-horus-l2-test'; $root = 'C:\pgdata'; $dir = 'C:\pgdata\horus_l2_test'
$id = Read-Identity
$id | Select-Object Operation,Stage,Root,RootId,Directory,DirectoryId,Service,Account,SystemIdentifier
'--- servicio'
Get-CimInstance Win32_Service -Filter "Name='postgresql-horus-l2-test'" | Format-List Name,State,StartMode,StartName,PathName
'--- directorios'
Test-Path -LiteralPath $root; Test-Path -LiteralPath $dir
if (Test-Path -LiteralPath $root) { Get-ChildItem -LiteralPath $root -Force -ErrorAction Stop | Format-Table Name,Attributes -Auto }
if (Test-Path -LiteralPath $dir) {
  'entradas en el directorio de datos: ' + @(Get-ChildItem -LiteralPath $dir -Force -ErrorAction Stop).Count
  'PG_VERSION: ' + (Test-Path -LiteralPath "$dir\PG_VERSION")
  'postmaster.pid: ' + (Test-Path -LiteralPath "$dir\postmaster.pid")
}
'--- puerto 55432'
Get-NetTCPConnection -ErrorAction Stop | Where-Object LocalPort -eq 55432 | Format-Table LocalAddress,LocalPort,OwningProcess -Auto
```

No hay forma fiable de asociar procesos hijo de `postgres.exe` con un directorio de datos por su línea de comandos (en Windows los backends no repiten `-D`). La ausencia de procesos se acredita así: servicio `Stopped`, `postmaster.pid` ausente o con un PID inexistente, y el borrado no encuentra archivos bloqueados. Si no se cumple, **parar**; no se terminan procesos.

### R1: detener y desregistrar el servicio (E; destructivo)

Requiere que el operador fije conscientemente la confirmación en esa consola. Se identifica el servicio por nombre **y** por comando; si no coinciden, no se toca nada.

```powershell
$ConfirmRollback = 'ELIMINAR-SOLO-HORUS-L2-TEST'   # el operador lo escribe a mano, tras revisar R0
$bin = 'C:\Program Files\PostgreSQL\18\bin'
$svc = 'postgresql-horus-l2-test'
$isElevated = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.SecurityIdentifier]'S-1-5-32-544')
if (-not $isElevated)                                      { throw 'STOP: R1 requiere consola elevada' }
if ($ConfirmRollback -cne 'ELIMINAR-SOLO-HORUS-L2-TEST')   { throw 'STOP: falta la confirmación explícita' }
$id = Read-Identity
Assert-Resources $id
$s = Get-CimInstance Win32_Service -Filter "Name='postgresql-horus-l2-test'"
if (-not $s) { 'El servicio ya no existe; nada que hacer en R1' } else {
  $re = '^"C:\\Program Files\\PostgreSQL\\18\\bin\\pg_ctl\.exe" runservice -N "postgresql-horus-l2-test" -D "C:\\pgdata\\horus_l2_test"( -w)?$'
  if ($s.PathName -notmatch $re) { throw 'STOP: el servicio no coincide con el comando previsto; no se modifica' }
  if ($s.State -ne 'Stopped') {
    Stop-Service -Name 'postgresql-horus-l2-test' -ErrorAction Stop
    (Get-Service -Name 'postgresql-horus-l2-test').WaitForStatus('Stopped', [TimeSpan]::FromSeconds(60))
  }
  if ((Get-Service -Name 'postgresql-horus-l2-test').Status -ne 'Stopped') { throw 'STOP: el servicio no se detuvo' }
  & "$bin\pg_ctl.exe" unregister -N 'postgresql-horus-l2-test'
  if ($LASTEXITCODE -ne 0) { throw 'STOP: pg_ctl unregister falló' }
}
sc.exe query 'postgresql-horus-l2-test'   # esperado: error 1060
if ($LASTEXITCODE -ne 1060) { throw 'STOP: no se acreditó ausencia del servicio' }
```

Se verifica solo el nombre y el comando; la cuenta registrada no importa, de modo que también retira un servicio registrado con una cuenta equivocada.

### R2: eliminar el directorio de datos (N; destructivo)

Elimina **únicamente** `C:\pgdata\horus_l2_test` y después `C:\pgdata` si queda vacío. Si cualquier comprobación falla, no borra nada.

```powershell
$ConfirmRollback = 'ELIMINAR-SOLO-HORUS-L2-TEST'   # el operador lo escribe a mano, tras revisar R0
$root = 'C:\pgdata'
$dir  = 'C:\pgdata\horus_l2_test'
if ($ConfirmRollback -cne 'ELIMINAR-SOLO-HORUS-L2-TEST') { throw 'STOP: falta la confirmación explícita' }
$id = Read-Identity # falla ante evidencia ausente, DPAPI inválido, ACL o etapa inconsistente
if ($id.Stage -in @('planned','rolled-back')) { throw 'STOP: no hay recursos borrables acreditados; revisión manual' }
if (Get-Service -ErrorAction Stop | Where-Object Name -eq 'postgresql-horus-l2-test') { throw 'STOP: ejecutar R1 primero' }
Assert-TestPortFree
if ([IO.Path]::GetFullPath($root) -cne 'C:\pgdata' -or [IO.Path]::GetFullPath($dir) -cne 'C:\pgdata\horus_l2_test') { throw 'STOP: rutas incorrectas' }
if ($id.RootId -notmatch '^0x[0-9a-f]{32}$' -or (Get-DirectoryId $root) -cne $id.RootId) { throw 'STOP: raíz no acreditada o sustituida' }
$children = @(Get-ChildItem -LiteralPath $root -Force -ErrorAction Stop)
if (@($children | Where-Object Name -ne 'horus_l2_test').Count -ne 0) { throw 'STOP: raíz con recursos ajenos' }
if (Test-Path -LiteralPath $dir -ErrorAction Stop) {
  Assert-Resources $id
  $items = @(Get-SafeTree $dir)
  if ($id.SystemIdentifier) {
    if ($id.SystemIdentifier -notmatch '^[0-9]+$' -or (Get-ClusterId) -cne $id.SystemIdentifier) { throw 'STOP: identificador de clúster distinto' }
  } elseif ($items.Count -ne 0 -or $id.Stage -notin @('directories-created','service-registered','initdb-starting')) {
    throw 'STOP: contenido parcial sin identificador acreditado; no borrar'
  }
  if (Test-Path -LiteralPath "$dir\postmaster.pid" -ErrorAction Stop) {
    $pidLine = Get-Content -LiteralPath "$dir\postmaster.pid" -TotalCount 1 -ErrorAction Stop
    if ($pidLine -notmatch '^[1-9][0-9]*$') { throw 'STOP: PID no interpretable' }
    if (Get-Process -ErrorAction Stop | Where-Object { $_.Id -eq [long]$pidLine }) { throw 'STOP: postmaster aún vivo' }
  }
  # Revalidar justo antes del borrado; no hay otro operador modificando los recursos.
  Assert-Resources $id
  Remove-Item -LiteralPath $dir -Recurse -Force -ErrorAction Stop
} elseif ($id.Stage -ne 'root-created' -or $id.DirectoryId -or $children.Count -ne 0) {
  throw 'STOP: directorio desaparecido o reversión interrumpida; revisión manual'
}
if ((Get-DirectoryId $root) -cne $id.RootId -or @(Get-ChildItem -LiteralPath $root -Force -ErrorAction Stop).Count -ne 0) { throw 'STOP: raíz modificada o no vacía' }
Remove-Item -LiteralPath $root -ErrorAction Stop
$id.Stage = 'rolled-back'; Save-Identity $id
```

Si `Remove-Item` falla por acceso o por archivos bloqueados, **parar** y reportar; no se usa `takeown`, `/grant` adicional ni se termina ningún proceso.

### Estados parciales y evidencia: decisión obligatoria antes de R1/R2

| Interrupción o discrepancia | Decisión |
| --- | --- |
| Falla crear/proteger el almacén o guardar `planned` | No se crean datos. Conservar cualquier resto del almacén para revisión; no sobreescribirlo. |
| Raíz creada pero falla su ACL, lectura NTFS o guardado de `root-created` | No consta pertenencia de la raíz: ninguna eliminación automática. |
| `root-created` guardado, datos todavía inexistentes | R2 permite retirar solo esa raíz, con NTFS coincidente y vacía. |
| Datos creados pero falla guardar `directories-created` | No consta `DirectoryId`: detenerse, aunque estén vacíos. |
| Falla S2 antes o después del registro | R1 solo con registro válido, NTFS coincidente y comando exacto; R2 solo tras acreditar ausencia del servicio. Directorios vacíos y acreditados pueden retirarse. |
| `initdb-starting`, sin contenido | R1/R2 permiten retirar directorios vacíos con sus identidades NTFS acreditadas. |
| `initdb` parcial o completo, pero sin `SystemIdentifier` guardado | Si hay contenido, **no borrar**. No inferir pertenencia de `PG_VERSION` ni del comentario; revisión manual. |
| `initialized` o etapas S4–S7 | R2 exige identidad NTFS **y** `system_identifier` coincidentes; cualquier fallo de lectura detiene el borrado. |
| Registro ausente, DPAPI inválido, ACL alterada, `identity.pending` residual o usuario/equipo distinto | Detener R1/R2. No reconstruir ni editar la evidencia para superar el control. |
| Directorio sustituido, enlace/junction, contenido ajeno en la raíz o ID de clúster distinto | No borrar; revisión manual. Mismo nombre o misma configuración no bastan. |
| R2 interrumpida tras eliminar datos o raíz, antes de guardar `rolled-back` | No repetir automáticamente. Inspección manual de lo que persista; nunca recrear rutas para hacer coincidir el registro. |

El registro y los guiones se conservan **fuera del clúster** después de R3 como evidencia, sin contraseñas. Su retirada o archivo requiere una tarea explícita; R2 no los elimina. No empezar una operación nueva reutilizando el registro anterior. La comprobación por NTFS no sustituye la exclusión de operadores concurrentes; una sustitución por un administrador malicioso queda fuera de la frontera de confianza.

### R3: verificación posterior al rollback (solo lectura)

```powershell
sc.exe query 'postgresql-horus-l2-test'    # error 1060
if ($LASTEXITCODE -ne 1060) { throw 'STOP: servicio no acreditado como ausente' }
Test-Path -LiteralPath 'C:\pgdata'         # False
if (Test-Path -LiteralPath 'C:\pgdata' -ErrorAction Stop) { throw 'STOP: persiste la raíz' }
Assert-TestPortFree
$id = Read-Identity
if ($id.Stage -ne 'rolled-back') { throw 'STOP: reversión no completada' }
# y la comparación del V14 con la línea base de §5.1
```

### 7.1 Anexo: recuperación manual del incidente S1 de L2.2-B2 (27/10/2026)

**PREPARADO, NO EJECUTADO.** La autorización actual permite exclusivamente corregir este documento y revisar sintaxis/estado mediante lectura. No permite ejecutar los bloques operativos siguientes, crear el archivo del incidente, modificar evidencias, reanudar S1 ni ejecutar rollback o S2–S7. Su ejecución requerirá autorización nueva que nombre expresamente recuperación y, por separado, continuación de S1. No modifica la prohibición general de `identity.pending` residual en `Read-Identity`, R0, R1 ni R2. Esta excepción solo admite el par binario y la raíz identificados abajo; no se extrapola a otros incidentes.

Diagnóstico: falló la primera actualización de `planned` a `root-created` en `Save-Identity`, al normalizar como ruta de respaldo la cadena vacía resultante de `$null`. La sustitución nativa no llegó a ejecutarse. Quedaron el registro original de 646 bytes, el pendiente de 694 bytes y la raíz vacía. No existe directorio hijo, servicio ni clúster de pruebas. El diagnóstico confirmó DPAPI válido en ambos, identidad común, diferencia exclusivamente en `Stage`/`RootId` e identidad NTFS coincidente de la raíz. Esto no convierte el pendiente en registro activo.

Identidades de este incidente (literales; hashes SHA-256 de los blobs cifrados, sin contenido descifrado):

| Elemento | Valor exigido |
| --- | --- |
| Operador | `LAPTOP-ONK47RS6\PCT40T`, SID `S-1-5-21-374778135-3788025079-1797161911-1001` |
| Equipo | `LAPTOP-ONK47RS6` |
| Almacén | `C:\Users\PCT40T\AppData\Local\HorusL2Provisioning` |
| `identity.dpapi` | 646 bytes; SHA-256 `7EA6517B494AE214086F30E02BC94127465944B3D636DF8D53FCEFAB38B40549`; NTFS `0x0000000000000000000e0000000d0a49` |
| `identity.pending` | 694 bytes; SHA-256 `F170D1A7ABF1F34AAF6126A8DE3D99362988ED894DC4FA3DC6588814507B7034`; NTFS `0x0000000000000000004300000014445e` |
| Raíz vacía | `C:\pgdata`, NTFS `0x000000000000000000080000000d0a50` |
| Archivo nuevo del incidente | `C:\Users\PCT40T\AppData\Local\HorusL2Provisioning\incident-s1-20261027` |
| Copias originales, sin sobrescritura | Dentro del archivo: `identity.original.dpapi`, `identity.original.pending` |
| Respaldo de la promoción | Dentro del archivo: `identity.before-promotion.dpapi` |

**Frontera de confianza y paradas.** Un solo operador, sin otro aprovisionador ni modificaciones concurrentes durante toda la recuperación. DPAPI detecta alteraciones del blob, pero no impide que el propio operador/admin cree otro blob o restaure uno anterior válido. Los hashes fijados y las identidades NTFS refuerzan la identificación; no prueban ausencia de un administrador malicioso ni eliminan carreras frente a ese adversario. `Flush(true)` y `File.Replace` no constituyen una transacción con los directorios ni garantizan recuperación automática tras pérdida de energía. Algunos errores nativos pueden dejar estados parciales: ante cualquier excepción, inspeccionar sin escribir y no repetir. No usar `Move-Item`, `Copy-Item`, `Remove-Item` ni una eliminación manual de originales como sustituto de la promoción. No reconstruir/reproteger JSON para recuperar la evidencia.

#### I0: validadores de solo lectura (N, cargar después del prólogo corregido §2.1)

Estos validadores no llaman a `Read-Identity` sobre un pendiente residual. Descifran únicamente en memoria para comparar; nunca imprimen blobs ni JSON. No autorizan por sí mismos las etapas I1 o I2. La comparación cotidiana usa la línea base previa sin sobrescribirla.

```powershell
$incidentArchive = Join-Path $evidence 'incident-s1-20261027'
$incidentRecordHash = '7EA6517B494AE214086F30E02BC94127465944B3D636DF8D53FCEFAB38B40549'
$incidentPendingHash = 'F170D1A7ABF1F34AAF6126A8DE3D99362988ED894DC4FA3DC6588814507B7034'
$incidentRootId = '0x000000000000000000080000000d0a50'
function Assert-IncidentOperator {
  $elevated = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.SecurityIdentifier]'S-1-5-32-544')
  if ($elevated -or $opSid -cne 'S-1-5-21-374778135-3788025079-1797161911-1001' -or
      [Security.Principal.WindowsIdentity]::GetCurrent().Name -ine 'LAPTOP-ONK47RS6\PCT40T' -or
      $env:COMPUTERNAME -cne 'LAPTOP-ONK47RS6' -or
      $evidence -cne 'C:\Users\PCT40T\AppData\Local\HorusL2Provisioning' -or
      $record -cne (Join-Path $evidence 'identity.dpapi') -or
      $incidentArchive -cne (Join-Path $evidence 'incident-s1-20261027') -or
      $incidentRecordHash -cne '7EA6517B494AE214086F30E02BC94127465944B3D636DF8D53FCEFAB38B40549' -or
      $incidentPendingHash -cne 'F170D1A7ABF1F34AAF6126A8DE3D99362988ED894DC4FA3DC6588814507B7034' -or
      $incidentRootId -cne '0x000000000000000000080000000d0a50' -or
      $root -cne 'C:\pgdata' -or $dir -cne 'C:\pgdata\horus_l2_test' -or
      $svc -cne 'postgresql-horus-l2-test') { throw 'STOP: identidad o consola del incidente distinta' }
}
function Assert-IncidentDailyBaseline {
  $baseline = Join-Path $env:TEMP 'horus_l2_baseline_before.txt'
  Assert-NoReparse $baseline
  $current = @(
    'SERVICE'; (Get-CimInstance Win32_Service -Filter "Name='postgresql-x64-18'" -ErrorAction Stop | Format-List Name,State,StartMode,StartName,PathName | Out-String)
    'ACL'; (Get-Acl -LiteralPath 'C:\Program Files\PostgreSQL\18\data' -ErrorAction Stop).Sddl
    'LISTEN'; (Get-NetTCPConnection -LocalPort 5432 -State Listen -ErrorAction Stop | Sort-Object LocalAddress | Format-Table LocalAddress,LocalPort -Auto | Out-String)
  )
  $text = ($current | ForEach-Object { [string]$_ + [Environment]::NewLine }) -join ''
  if ([IO.File]::ReadAllText($baseline) -cne $text -or
      (Get-Service -Name 'postgresql-x64-18' -ErrorAction Stop).Status -ne 'Running') { throw 'STOP: cotidiana distinta de la línea base' }
}
function Get-IncidentFileId([string]$Path) {
  Assert-NoReparse $Path
  if ((Get-Item -LiteralPath $Path -Force -ErrorAction Stop).PSIsContainer) { throw 'STOP: evidencia no es archivo' }
  $out = @(& "$env:SystemRoot\System32\fsutil.exe" file queryfileid $Path 2>&1)
  if ($LASTEXITCODE -ne 0) { throw 'STOP: identidad NTFS de evidencia ilegible' }
  $ids = [regex]::Matches(($out -join "`n"), '(?i)0x[0-9a-f]{32}\b')
  if ($ids.Count -ne 1) { throw 'STOP: formato NTFS no reconocido' }
  return $ids[0].Value.ToLowerInvariant()
}
function Assert-IncidentHash([string]$Path, [string]$Hash, [long]$Length) {
  Assert-EvidenceAcl $Path
  $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
  if ($item.PSIsContainer -or $item.Length -ne $Length -or
      (Get-FileHash -LiteralPath $Path -Algorithm SHA256 -ErrorAction Stop).Hash -cne $Hash) { throw 'STOP: evidencia distinta del incidente' }
}
function Read-IncidentBlob([string]$Path) {
  Assert-EvidenceAcl $Path
  $plain = [Security.Cryptography.ProtectedData]::Unprotect([IO.File]::ReadAllBytes($Path), $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
  try { return ([Text.Encoding]::UTF8.GetString($plain) | ConvertFrom-Json -ErrorAction Stop) }
  finally { [Array]::Clear($plain, 0, $plain.Length) }
}
function Assert-IncidentBinaryCopy([string]$Source, [string]$Copy) {
  Assert-EvidenceAcl $Source; Assert-EvidenceAcl $Copy
  $left = [IO.File]::ReadAllBytes($Source); $right = [IO.File]::ReadAllBytes($Copy)
  if ($left.Length -ne $right.Length -or [Convert]::ToBase64String($left) -cne [Convert]::ToBase64String($right)) { throw 'STOP: copia binaria distinta' }
}
function Assert-IncidentRoot {
  Assert-IncidentOperator
  Assert-EvidenceAcl $evidence; Assert-EvidenceAcl $root
  $acl = Get-Acl -LiteralPath $root -ErrorAction Stop
  if (-not $acl.AreAccessRulesProtected -or @($acl.Access).Count -ne 3 -or
      (Get-DirectoryId $root) -cne $incidentRootId) { throw 'STOP: raíz o ACL distintas' }
  if (Get-Service -ErrorAction Stop | Where-Object Name -eq $svc) { throw 'STOP: servicio de pruebas presente' }
  Assert-TestPortFree
  Assert-IncidentDailyBaseline
}
function Assert-S1Incident {
  Assert-IncidentRoot
  if ((Test-Path -LiteralPath $dir -ErrorAction Stop) -or
      @(Get-ChildItem -LiteralPath $root -Force -ErrorAction Stop).Count -ne 0) { throw 'STOP: raíz no vacía o hijo presente' }
  $pending = Join-Path $evidence 'identity.pending'
  Assert-IncidentHash $record $incidentRecordHash 646
  Assert-IncidentHash $pending $incidentPendingHash 694
  if ((Get-IncidentFileId $record) -cne '0x0000000000000000000e0000000d0a49' -or
      (Get-IncidentFileId $pending) -cne '0x0000000000000000004300000014445e') { throw 'STOP: archivo de evidencia sustituido' }
  $before = Read-IncidentBlob $record; $after = Read-IncidentBlob $pending
  $fields = @('Version','Operation','Operator','Machine','Root','Directory','Service','Account','Stage','RootId','DirectoryId','SystemIdentifier','SqlHashes','CreatedUtc')
  foreach ($obj in @($before, $after)) {
    if (@(Compare-Object ($fields | Sort-Object) (@($obj.PSObject.Properties.Name) | Sort-Object)).Count -ne 0 -or
        $obj.Version -ne 1 -or $obj.Operator -cne $opSid -or $obj.Machine -cne $env:COMPUTERNAME -or
        $obj.Root -cne $root -or $obj.Directory -cne $dir -or $obj.Service -cne $svc -or
        $obj.Account -cne 'NT SERVICE\postgresql-horus-l2-test' -or $obj.Operation -notmatch '^[0-9a-f-]{36}$' -or
        $null -ne $obj.DirectoryId -or $null -ne $obj.SystemIdentifier -or
        @($obj.SqlHashes.PSObject.Properties).Count -ne 0) { throw 'STOP: registro DPAPI inconsistente' }
  }
  foreach ($field in ($fields | Where-Object { $_ -notin @('Stage','RootId') })) {
    $a = ConvertTo-Json -InputObject $before.$field -Depth 4 -Compress
    $b = ConvertTo-Json -InputObject $after.$field -Depth 4 -Compress
    if ($a -cne $b) { throw 'STOP: diferencia no permitida entre registros' }
  }
  if ($before.Stage -cne 'planned' -or $null -ne $before.RootId -or
      $after.Stage -cne 'root-created' -or $after.RootId -cne $incidentRootId) { throw 'STOP: transición distinta del incidente' }
}
```

#### I1: preservar y promover una sola vez (N; NO AUTORIZADO TODAVÍA)

Tras nueva autorización, cargar §2.1 corregido e I0 en la misma consola N. Ejecutar este bloque completo como un script con parada terminante, nunca pegar sus líneas una a una después de un error. `incident-s1-20261027` debe estar ausente: su creación funciona como barrera persistente contra repetir esta recuperación. Si existe, incluso vacío, detenerse para otra revisión manual; no borrarlo, reutilizarlo ni elegir un sufijo alternativo. Las copias conservan los bytes originales; sus NTFS propios no sustituyen las identidades originales fijadas en la tabla. Conservar esa tabla y la salida de diagnóstico como inventario del incidente.

```powershell
& {
  $ErrorActionPreference = 'Stop'
  Assert-S1Incident
  Assert-NoReparse $incidentArchive
  if (Test-Path -LiteralPath $incidentArchive -ErrorAction Stop) { throw 'STOP: recuperación previa o interrumpida' }
  New-Item -ItemType Directory -Path $incidentArchive -ErrorAction Stop | Out-Null
  icacls $incidentArchive /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' "*${opSid}:(OI)(CI)F"
  if ($LASTEXITCODE -ne 0) { throw 'STOP: protección del archivo de incidente falló' }
  Assert-EvidenceAcl $incidentArchive
  if (-not (Get-Acl -LiteralPath $incidentArchive).AreAccessRulesProtected) { throw 'STOP: archivo con herencia activa' }
  $pending = Join-Path $evidence 'identity.pending'
  $originalRecord = Join-Path $incidentArchive 'identity.original.dpapi'
  $originalPending = Join-Path $incidentArchive 'identity.original.pending'
  $backup = Join-Path $incidentArchive 'identity.before-promotion.dpapi'
  foreach ($pair in @(@($record, $originalRecord), @($pending, $originalPending))) {
    $bytes = [IO.File]::ReadAllBytes($pair[0])
    $f = [IO.File]::Open($pair[1], [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    try { $f.Write($bytes, 0, $bytes.Length); $f.Flush($true) } finally { $f.Dispose() }
    Assert-IncidentBinaryCopy $pair[0] $pair[1]
  }
  Assert-IncidentHash $originalRecord $incidentRecordHash 646
  Assert-IncidentHash $originalPending $incidentPendingHash 694
  $null = Read-IncidentBlob $originalRecord; $null = Read-IncidentBlob $originalPending
  # Última revalidación antes de consumir el pendiente, sin editarlo ni regenerarlo.
  Assert-S1Incident
  Assert-EvidenceAcl $incidentArchive
  Assert-NoReparse $backup
  if (Test-Path -LiteralPath $backup -ErrorAction Stop) { throw 'STOP: respaldo ya existe; no sobrescribir' }
  [IO.File]::Replace($pending, $record, $backup)
  # No catch con reintento. Toda excepción exige inspección de los tres destinos.
  if (Test-Path -LiteralPath $pending -ErrorAction Stop) { throw 'STOP: pendiente sigue presente después de promoción' }
  Assert-IncidentBinaryCopy $originalPending $record
  Assert-IncidentBinaryCopy $originalRecord $backup
  Assert-IncidentHash $record $incidentPendingHash 694
  Assert-IncidentHash $backup $incidentRecordHash 646
  $null = Read-IncidentBlob $backup
  $id = Read-Identity
  if ($id.Stage -cne 'root-created' -or $id.RootId -cne $incidentRootId -or
      $null -ne $id.DirectoryId -or $null -ne $id.SystemIdentifier) { throw 'STOP: estado promovido incorrecto' }
  Assert-IncidentRoot
  if ((Test-Path -LiteralPath $dir) -or @(Get-ChildItem -LiteralPath $root -Force).Count -ne 0) { throw 'STOP: recursos cambiaron durante promoción' }
  'I1 verificada: root-created recuperado; detenerse antes de continuar S1'
}
```

La promoción conserva el registro anterior tanto en la copia verificada como en el respaldo creado por `File.Replace`. No se borra ningún original manualmente: la API consume el pendiente al convertirlo en registro activo. Cualquier archivo del incidente se conserva también tras un eventual rollback. La API puede dejar estados parciales ante ciertos errores nativos; consultar [ReplaceFileW](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-replacefilew). Una excepción no acredita éxito ni permite volver a ejecutar I1. El archivo persistente impide esa repetición desde este bloque.

#### I2: continuación desde root-created (N; autorización separada, NO EJECUTAR S1 completo)

Solo después de I1 aceptada y una autorización que incluya esta continuación. Cargar §2.1 corregido e I0; mantener exactamente el archivo del incidente anterior. El hash del registro activo debe seguir siendo el del pendiente original: si ya avanzó o existen restos, no repetir I2. Este bloque no recrea la raíz ni el almacén, no vuelve a guardar `planned` y no modifica servicio alguno. Solo añade el hijo, verifica su identidad NTFS y registra la transición normal; esta serialización nueva pertenece a la continuación de S1, no a la recuperación de los blobs originales.

```powershell
& {
  $ErrorActionPreference = 'Stop'
  Assert-IncidentRoot
  Assert-EvidenceAcl $incidentArchive
  if (-not (Get-Acl -LiteralPath $incidentArchive).AreAccessRulesProtected) { throw 'STOP: archivo con herencia activa' }
  $originalRecord = Join-Path $incidentArchive 'identity.original.dpapi'
  $originalPending = Join-Path $incidentArchive 'identity.original.pending'
  $backup = Join-Path $incidentArchive 'identity.before-promotion.dpapi'
  Assert-IncidentHash $originalRecord $incidentRecordHash 646
  Assert-IncidentHash $originalPending $incidentPendingHash 694
  Assert-IncidentHash $backup $incidentRecordHash 646
  Assert-IncidentBinaryCopy $originalRecord $backup
  Assert-IncidentHash $record $incidentPendingHash 694
  Assert-IncidentBinaryCopy $originalPending $record
  $id = Read-Identity
  if ($id.Stage -cne 'root-created' -or $id.RootId -cne $incidentRootId -or
      $null -ne $id.DirectoryId -or $null -ne $id.SystemIdentifier -or
      (Test-Path -LiteralPath $dir) -or @(Get-ChildItem -LiteralPath $root -Force).Count -ne 0) { throw 'STOP: no corresponde continuar S1' }
  Assert-NoReparse $dir
  New-Item -ItemType Directory -Path $dir -ErrorAction Stop | Out-Null
  $id.DirectoryId = Get-DirectoryId $dir
  Assert-EvidenceAcl $dir
  if (@((Get-Acl -LiteralPath $dir).Access).Count -ne 3 -or
      @(Get-ChildItem -LiteralPath $dir -Force).Count -ne 0) { throw 'STOP: ACL o contenido del hijo inesperados' }
  $id.Stage = 'directories-created'
  Save-Identity $id
  $verified = Read-Identity
  Assert-Resources $verified
  if ($verified.Stage -cne 'directories-created' -or $verified.RootId -cne $incidentRootId -or
      $verified.DirectoryId -cne $id.DirectoryId -or $null -ne $verified.SystemIdentifier) { throw 'STOP: transición no acreditada' }
  Assert-IncidentRoot
  Assert-EvidenceAcl $dir
  if (@((Get-Acl -LiteralPath $dir).Access).Count -ne 3 -or
      @(Get-ChildItem -LiteralPath $dir -Force).Count -ne 0 -or
      @(Get-ChildItem -LiteralPath $root -Force).Count -ne 1) { throw 'STOP: directorios no exclusivos o ACL inesperadas' }
  'I2 verificada: directories-created; detenerse antes de S2'
}
```

**Puerta de S2:** registro activo DPAPI válido en `directories-created`, ningún `identity.pending`, `RootId`/`DirectoryId` coincidentes por NTFS, raíz con exclusivamente el hijo vacío, ACL limitadas a los tres principales previstos, copias/respaldo del incidente conservados y verificados, servicio ausente, puerto libre/no excluido y cotidiana igual a la línea base. Antes de S2 confirmar nuevamente operador y elevación de E y cargar allí §2.1 corregido. No inferir los permisos de E desde N. Este anexo termina antes de S2; su ejecución posterior necesita una instrucción expresa para continuar dentro de la autorización aplicable.

**Reversión alternativa:** no se incluye ningún borrado en I0–I2. Si el propietario decide abandonar después de recuperar `root-created`, R0 y R2 solo pueden evaluarse con autorización expresa, registro válido, raíz NTFS coincidente y vacía y servicio ausente. Si I1/I2 se interrumpen, no deducir pertenencia del nombre o contenido; se exige diagnóstico nuevo. Nunca borrar evidencias válidas, elegir rutas alternativas, ampliar ACL ni editar el registro para franquear las comprobaciones.

## 8. Consultas de solo lectura sobre `horus_group` (las ejecuta el operador)

El agente no ejecutó estas consultas ni abrió conexiones. El operador comunicó todos los resultados D3 de §8.1, obtenidos manualmente en pgAdmin 4, sin compartir contraseñas. Nada toca `.env`, `.pgpass` ni el guard.

Revisión estática D3: `datlocprovider`, `datcollate`, `datctype`, `datlocale`, `daticurules` y `datcollversion` corresponden al catálogo de PostgreSQL 18. **D3 está cerrada documentalmente**; la reproducción y comparación V16 quedan pendientes de B2 autorizado. [Catálogo PostgreSQL 18](https://www.postgresql.org/docs/18/catalog-pg-database.html), [soporte de locale](https://www.postgresql.org/docs/18/locale.html).

### 8.1 Medición recibida y parámetros definitivos propuestos

Fuente: resultados que el operador confirmó manualmente desde pgAdmin 4, comunicados el 27/10/2026; no son una medición del agente.

| Campo | Valor comunicado |
| --- | --- |
| Versión / base | PostgreSQL 18.6 / `horus_group` |
| Codificación / proveedor | `UTF8` / `c` (`libc`) |
| `datcollate` / `datctype` | `Spanish_Spain.1252` / `Spanish_Spain.1252` |
| `datlocale` / `daticurules` / `datcollversion` | `NULL` / `NULL` / `NULL` |
| Identidad confirmada por el operador | `destino_correcto = true` (transcripción comunicada) |
| `data_checksums` / `TimeZone` | `on` / `America/Bogota` |
| `standard_conforming_strings` / `default_text_search_config` | `on` / `pg_catalog.spanish` |

Verificación local sin conexión: `postgres.exe --version` devuelve 18.6 y los metadatos de archivo de `postgres.exe` e `initdb.exe` indican 18.6, en `C:\Program Files\PostgreSQL\18\bin`. No se ejecutó `initdb`, ni siquiera como prueba de locale.

**Compatibilidad documental:** PostgreSQL 18 admite UTF8 con cualquier locale válido en Windows. `.1252` forma parte del nombre del locale Windows y **no cambia la codificación de la base a WIN1252**. Mantener el nombre exacto medido, sin convertirlo a `es_ES.UTF-8` ni cambiar de proveedor. La combinación es documentalmente compatible; la validez efectiva del nombre para estos binarios y esta cuenta solo se confirmará en S3 autorizado. No existe en `initdb` una opción documentada de validación de locale sin inicialización. [Codificaciones](https://www.postgresql.org/docs/18/multibyte.html), [initdb](https://www.postgresql.org/docs/18/app-initdb.html), [CREATE DATABASE](https://www.postgresql.org/docs/18/sql-createdatabase.html).

S3 usa `$LocaleArgs = @('--locale-provider=libc','--lc-collate=Spanish_Spain.1252','--lc-ctype=Spanish_Spain.1252')`, junto con `-E UTF8` ya explícito. S6 define `dbloc` como `LOCALE_PROVIDER libc LC_COLLATE 'Spanish_Spain.1252' LC_CTYPE 'Spanish_Spain.1252'`, con `TEMPLATE template0 ENCODING 'UTF8'` en ambas bases. La forma exacta de `\set` está en S6. No usar opciones ICU/builtin, `ICU_RULES` ni `COLLATION_VERSION`; el NULL medido se conserva como evidencia, no como argumento para forzar una versión.

**Colaciones recibidas (resultado completo):** una fila: esquema `pg_catalog`, colación `default`, tipo `predeterminada`, proveedor `d`, determinista `true`, 75 columnas; `collctype`, `colllocale`, `collcollate`, `collversion` y `collicurules`, todos NULL. Colaciones no predeterminadas: `[]`. El proveedor `d` de ese objeto remite al predeterminado libc de la base; no contradice `datlocprovider = c`. El inventario cubre columnas de tablas y particiones de `public`, según la consulta, no expresiones arbitrarias de índices o consultas.

**Extensiones recibidas y necesidad del perfil:** `plpgsql 1.0` y `uuid-ossp 1.1`. Los archivos de control locales de EDB anuncian esas mismas versiones; eso no demuestra instalación en una base nueva. `plpgsql` se espera en las plantillas estándar y se comprobará en ambas bases durante fase P. No se encontraron llamadas `uuid_generate_*`, DDL de extensiones ni dependencia de `uuid-ossp` en `app/`, `database/` y `tests/PostgresIntegrity/` revisados; un UUID almacenado como cadena o generado por PHP no exige esta extensión. Se registra como instalación histórica, no como requisito demostrado. No se añade `CREATE EXTENSION` a S6 ni se concede CREATE sobre la base al runner. Si aparece una dependencia, detenerse y revisar explícitamente su instalación por el aprovisionador en cada base; no hacer que el runner la instale. [PostgreSQL: uuid-ossp](https://www.postgresql.org/docs/18/uuid-ossp.html).

**Referencia sintética exacta de V16:**

| Campo comunicado | Valor |
| --- | --- |
| `orden_hex` | `61,41,c3a1,62,42,6e,c3b1,5a` |
| `like_coincide` | `false` |
| `ilike_coincide` | `true` |
| `lower_acentos_hex` | `c3a1c3a9c3b1` |
| `ilike_ignora_acentos` | `false` |
| `minuscula_antes_que_mayuscula` | `true` |

La consulta E conservada abajo denomina `like_distingue_mayusculas` al resultado comunicado como `like_coincide` e `ilike_ignora_mayusculas` al comunicado como `ilike_coincide`: son las mismas expresiones, con valores `false` y `true`, respectivamente. Comparar expresiones/valores, no confundir el nombre de la columna con una afirmación booleana sobre sensibilidad.

**Configuración histórica frente al runner:** `America/Bogota` pertenece a la sesión cotidiana medida. El perfil fijo del runner conserva `timezone = UTC`, `search_path = public`, charset UTF8 y READ COMMITTED, conforme a L2.2-A y `config/pgsql-testing-database.php`; no copiar la zona histórica ni modificar el guard. Los checksums serán `on` por S3, que en este caso coincide. `standard_conforming_strings = on` se comprobará en las sesiones nuevas. `pg_catalog.spanish` se registra como configuración de búsqueda de texto de la sesión consultada; no determina LIKE/ILIKE ni el locale de la base. No se halló uso de búsqueda de texto completo en el alcance revisado; no se cambia configuración global ni del runner para copiarlo. Un uso futuro requiere revisar esa dependencia.

No quedan consultas D3 pendientes al operador. La aceptación de `initdb`, la identidad y locale efectivos de las dos bases nuevas, las extensiones esperadas, la cuenta virtual/ACL y V16 solo pueden acreditarse durante B2 autorizado. No se exige una prueba de creación previa para pedir autorización. El valor comunicado `destino_correcto = true` acredita la confirmación del operador; el script entregado originalmente devolvía `1` como resultado satisfactorio de esa expresión, sin que esa diferencia de representación cambie el locale registrado.

### 8.2 Consultas preparadas (solo ejecución manual del operador)

Antes de conectar, fijar UTF-8 en la consola (la salida de `María` o `ÁÉÑ` puede corromperse con otra página de códigos). Las consultas usan escapes Unicode para no depender de lo que se teclee:

```powershell
chcp 65001
$ReadUser = $null   # el operador asigna aquí su usuario de solo lectura
if ([string]::IsNullOrWhiteSpace($ReadUser) -or $ReadUser -notmatch '^[A-Za-z0-9_]+$') { throw 'STOP: defina $ReadUser' }
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -X `
    "host=127.0.0.1 port=5432 dbname=horus_group user=$ReadUser options='-c default_transaction_read_only=on -c statement_timeout=5000'"
```

La contraseña se escribe a mano en el aviso de `psql`. Dentro de `psql`: `\encoding UTF8`. Luego:

```sql
-- A. Servidor
SELECT version();
SHOW server_encoding;  SHOW lc_collate;  SHOW lc_ctype;  SHOW data_checksums;
SHOW TimeZone;  SHOW standard_conforming_strings;  SHOW default_text_search_config;

-- B. Base (PostgreSQL 17+: datlocale reemplaza a daticulocale)
SELECT d.datname, pg_encoding_to_char(d.encoding) AS encoding, d.datlocprovider,
       d.datcollate, d.datctype, d.datlocale, d.daticurules, d.datcollversion
FROM pg_database d WHERE d.datname = current_database();

-- C. Colaciones que usan las columnas de la aplicación (incluidas particiones)
SELECT cn.nspname AS esquema_colacion, c.collname, c.collprovider,
       c.collisdeterministic, c.collcollate, c.collctype, c.colllocale,
       c.collicurules, c.collversion, count(*) AS columnas
FROM pg_attribute a
JOIN pg_class t ON t.oid = a.attrelid
JOIN pg_namespace n ON n.oid = t.relnamespace
JOIN pg_collation c ON c.oid = a.attcollation
JOIN pg_namespace cn ON cn.oid = c.collnamespace
WHERE n.nspname = 'public' AND t.relkind IN ('r','p') AND a.attnum > 0
  AND NOT a.attisdropped AND a.attcollation <> 0
GROUP BY 1, 2, 3, 4, 5, 6, 7, 8, 9 ORDER BY 1, 2;

-- D. Extensiones instaladas
SELECT extname, extversion FROM pg_extension ORDER BY 1;

-- E. Comportamiento de LIKE y del orden (escapes Unicode: í = U+00ED, Á = U+00C1, É = U+00C9, Ñ = U+00D1, á = U+00E1, ñ = U+00F1)
SELECT 'a' < 'B'                                              AS minuscula_antes_que_mayuscula,
       U&'Mar\00EDa' LIKE  '%mar%'                            AS like_distingue_mayusculas,
       U&'Mar\00EDa' ILIKE '%mar%'                            AS ilike_ignora_mayusculas,
       U&'Mar\00EDa' ILIKE '%maria%'                          AS ilike_ignora_acentos,
       encode(convert_to(lower(U&'\00C1\00C9\00D1'), 'UTF8'), 'hex') AS lower_acentos_hex,
       (SELECT string_agg(encode(convert_to(x, 'UTF8'), 'hex'), ',' ORDER BY x)
          FROM unnest(ARRAY['b','A',U&'\00E1','a','B','Z',U&'\00F1','n']) AS x) AS orden_hex;
```

### 8.3 Qué debe coincidir y qué no

Para que las pruebas de L2.2-B sean representativas, lo que determina el resultado de `ORDER BY`, `LIKE`, `lower/upper`, unicidad y comparaciones debe coincidir:

| Parámetro | ¿Debe coincidir? | Cómo se fija |
| --- | --- | --- |
| Codificación (`server_encoding`) | Sí | `initdb -E UTF8` y `CREATE DATABASE … ENCODING 'UTF8'` |
| Proveedor (`datlocprovider`) | Sí | Ver tabla siguiente |
| `datcollate` y `datctype` | Sí, con `libc`; también con `icu`, porque PostgreSQL los conserva y los usa para el entorno libc | `--locale`/`--lc-collate`/`--lc-ctype` y `LC_COLLATE`/`LC_CTYPE` |
| `datlocale` (ICU o builtin) y `daticurules` | Sí, si no son nulos | `--icu-locale` o `--builtin-locale`; las reglas ICU requieren revisión aparte (el §6 S3 las rechaza) |
| Colaciones no deterministas en columnas (consulta C) | Sí, si existen | Se anota como requisito de las migraciones |
| Extensiones (consulta D) | Las que usen las migraciones | Se anotan; se evalúa antes de aprovisionar |
| `data_checksums` | **No** | Se fija siempre `--data-checksums`. No cambia el resultado de las consultas. Limitación: no representa el coste ni el comportamiento ante corrupción de una base sin checksums |
| `TimeZone` | No | El guard exige UTC |
| `lc_messages`, `lc_monetary`, `lc_numeric`, `lc_time` | No | Los tests comparan `SQLSTATE`. `lc_messages` queda en `C` para mensajes estables |

| `datlocprovider` | Argumentos de `initdb` (el operador los asigna a `$LocaleArgs`) | Variable `dbloc` de S6 |
| --- | --- | --- |
| `c` (libc) | `--locale-provider=libc`, `--lc-collate=<datcollate>`, `--lc-ctype=<datctype>` | `LOCALE_PROVIDER libc LC_COLLATE '<datcollate>' LC_CTYPE '<datctype>'` |
| `i` (ICU) | `--locale-provider=icu`, `--icu-locale=<datlocale>`, `--lc-collate=<datcollate>`, `--lc-ctype=<datctype>` | `LOCALE_PROVIDER icu ICU_LOCALE '<datlocale>' LC_COLLATE '<datcollate>' LC_CTYPE '<datctype>'` |
| `b` (builtin) | `--locale-provider=builtin`, `--builtin-locale=<datlocale>`, `--lc-collate=<datcollate>`, `--lc-ctype=<datctype>` | `LOCALE_PROVIDER builtin BUILTIN_LOCALE '<datlocale>' LC_COLLATE '<datcollate>' LC_CTYPE '<datctype>'` |

Los `<…>` de estas tablas son una referencia general, nunca texto ejecutable: para la medición actual prevalecen los valores concretos de §8.1, S3 y S6. La evidencia complementaria ya está revisada; la autorización A sigue siendo obligatoria. Si `initdb` o `CREATE DATABASE` rechazan una combinación (por ejemplo, el builtin con ciertos valores de ctype), se **detiene** el proceso (§4, condición 9); no se prueban variantes. Si `daticurules` no es nulo, se detiene y se revisa por separado.

Aceptación de D3 (fase P, solo lectura en la instancia nueva): ejecutar las consultas A (`server_encoding`, `lc_collate`, `lc_ctype`), B y E en `horus_l2_test` con el aprovisionador y comparar con las de `horus_group`. Los resultados B (proveedor, collate, ctype, locale, reglas) y E (booleanos y `orden_hex`) deben ser idénticos. Si `horus_group` usa una colación sensible a la plataforma, registrar la diferencia como limitación; no ajustar las pruebas para ocultarla.

## 9. Marcador y separación de bases

- Cada base tiene su marcador: nombre, OID real, rol y propósito fijo. Los runners solo tienen `SELECT`; no son propietarios, no crean objetos en `horus_test_control` ni pueden modificar el marcador.
- El runner de una base no puede conectar a la otra (HBA y privilegios) ni a `postgres`, `template0` ni `template1`.
- Supervivencia del marcador ante limpiezas: por lectura de código de Laravel 12, `dropAllTables` y `DatabaseTruncation` recorren solo el esquema de trabajo; además, un `DROP` o `TRUNCATE` del marcador falla por falta de propiedad o privilegio. **Hipótesis aún sin demostrar en vivo**; se confirmará en L2.2-B con una prueba real autorizada.

## 10. Pruebas de concurrencia (implementación existente; ejecución real pendiente)

### Regla

Cada conexión, la principal y la auxiliar, debe pasar por `GuardedPostgresConnector`. Ninguna usa `new PDO`, `DB::build()`, `DB::connectUsing()` ni otro nombre de conexión, y no se modifica `config('database')` ni la conexión por defecto.

### Mecanismo

`app/Support/Testing/ConcurrentTestSession.php` ya está implementada. Sus pruebas con dobles, descritas en L2.2-C §5.1, cubren apertura, rechazos y cierre sin sockets; no acreditan PostgreSQL real. Obtiene la configuración validada y crea una `Connection` adicional con la fábrica protegida:

```php
$config = PostgresTestGuard::laravelConnection(TestDatabaseSafety::postgresConfiguration(app()));
$second = app('db.factory')->make($config, 'pgsql'); // GuardedConnectionFactory → GuardedPostgresConnection
```

- `db.factory` está extendido por `TestDatabaseSafety::install()` y devuelve `GuardedConnectionFactory`; `GuardedPostgresConnector::connect()` compara la configuración con la validada, abre en solo lectura, repite la identidad completa y solo entonces habilita escritura. El PDO se abre de forma perezosa en la primera consulta, y cada conexión nueva repite la comprobación.
- La conexión auxiliar no se registra en `DatabaseManager`, que es quien despacha `ConnectionEstablished`: la garantía la da el conector, no el listener.
- La clase verifica nombre `pgsql`, configuración exacta y fábrica/conector protegidos, sin aceptar destinos externos; `RequiresIsolatedPostgres` cierra la auxiliar en `tearDown`. Cada apertura de PDO repite el guard.

### Qué demuestran y qué no

`GuardedPostgresConnector` impone `lock_timeout=2000` y `statement_timeout=5000`. En un solo proceso PHP, una sesión bloqueada detiene al propio test, por lo que el diseño no espera: una sesión **retiene** un bloqueo y la otra debe fallar con `55P03` tras ≈2 s (o de inmediato con `NOWAIT`).

**Cuando se ejecuten con autorización B**, estas pruebas deberán demostrar que el código adquiere el bloqueo previsto. Los dobles actuales no lo demuestran. La prueba de bloqueo tampoco demuestra por sí sola serialización completa: un `55P03` podría deberse a otro recurso. La concurrencia real entre procesos sigue pendiente (ver «Fase posterior»). La autorización A de infraestructura no autoriza estas pruebas ni la suite PostgreSQL aislada.

### Requisitos de cada prueba

1. **Caso de control sin bloqueo**: la misma operación, sin que la auxiliar retenga nada, tiene éxito (o es rechazada por la regla de negocio prevista). Sin control, la prueba no vale.
2. **`SQLSTATE` exacto**: `55P03` para `lock_timeout`; distinguir `57014` (`statement_timeout`), `40P01` (deadlock) y `23505` (unicidad). Comprobar un tiempo de espera cercano a 2 s cuando no se usa `NOWAIT`.
3. **Recurso bloqueado**: la causa se atribuye con el control anterior y, en el servidor, con `log_lock_waits` (activado en S4).
4. **Limpieza**: la auxiliar termina con `rollBack()` y `disconnect()` en `tearDown`, incluso si la prueba falla; `idle_in_transaction_session_timeout` (S4) limita una transacción olvidada.
5. **Datos confirmados**: cualquier prueba con la auxiliar requiere `DatabaseTruncation` o `DatabaseMigrations`; `RefreshDatabase` (transacción de la principal) hace invisibles sus datos a la otra sesión. En PostgreSQL, una sentencia fallida aborta la transacción de `RefreshDatabase`; un test que capture la `QueryException` y siga debe usar `DB::transaction()` (savepoint).

### Escenarios

| # | Escenario | Mecánica | Resultado esperado |
| --- | --- | --- | --- |
| C1 | Cupo de curso (`MatriculaController::store` bloquea la fila de `cursos`) | La auxiliar toma `SELECT … FROM cursos WHERE id=? FOR UPDATE`; la principal ejecuta la operación real | Falla con `55P03`; con rollback de la auxiliar, la misma operación tiene éxito (control) |
| C2 | Último administrador (`AdminProtection::runExclusively` bloquea la fila del rol `admin`) | La auxiliar retiene ese bloqueo; la principal intenta quitar o desactivar al último admin | `55P03`; sin retención, la regla de negocio lo rechaza (control) |
| C3 | Matrícula duplicada | La auxiliar inserta sin confirmar; la principal inserta lo mismo | `55P03` mientras está retenida; `23505` tras confirmar la auxiliar |
| C4 | Visibilidad | La auxiliar no ve filas sin confirmar de la principal y sí las confirmadas | Confirma el límite con `RefreshDatabase` |

### Fase posterior: serialización real entre dos procesos

Fuera de B1. Requiere un punto de entrada fijo, revisado, que arranque por el mismo bootstrap guardado; no se acepta un comando arbitrario ni un destino nuevo. Debe probar que dos peticiones simultáneas sobre el último cupo o el último administrador producen exactamente un resultado válido, con cada proceso usando una conexión protegida propia.

## 11. Verificaciones

Clasificación:

- **R** Solo lectura, sin instancia nueva (puede hacerse ya).
- **P** Solo lectura sobre la instancia nueva, después de aprovisionarla y **antes** de desactivar el aprovisionador. Las cubre D6; no usan el guard ni `HORUS_PG_TEST_CONFIRM`.
- **G** Usan el guard y el consentimiento `HORUS_PG_TEST_CONFIRM`; solo después de una **autorización posterior y distinta** (§13).
- **N** Intento de escritura que debe ser rechazado, dentro de `BEGIN … ROLLBACK`; solo con autorización posterior.

| # | Verificación | Tipo | Esperado |
| --- | --- | --- | --- |
| V0 | §5.1 y Preflight 1 | R | Valores indicados |
| V1 | `SHOW data_directory; SHOW cluster_name; SHOW port; SHOW listen_addresses;` | P | `C:/pgdata/horus_l2_test`, `horus_l2_test`, `55432`, `127.0.0.1` |
| V2 | Servicio nuevo, cuenta y procesos (`Get-CimInstance Win32_Process` con `Invoke-CimMethod -MethodName GetOwner`; puede requerir consola elevada) | P | Servicio nuevo, cuenta `NT SERVICE\postgresql-horus-l2-test`, procesos distintos de los del 5432 |
| V3 | `pg_controldata` del directorio nuevo frente a `SELECT system_identifier FROM pg_control_system()` de la cotidiana (lo ejecuta el operador, con su permiso) | P | `system_identifier` distinto |
| V4 | La tabla del marcador existe y se lee con nombre calificado (`authorization` es palabra reservada) | P | Una fila por base con nombre, OID y rol correctos |
| V5 | Como cada runner, atributos `rolsuper/rolcreatedb/rolcreaterole/rolreplication/rolbypassrls`; `pg_auth_members` | P | Todo `f`; 0 membresías |
| V6 | `has_database_privilege(<runner>, <db>, 'CONNECT')` para `postgres`, `template0`, `template1` y la otra base de pruebas | P | `f` en todas |
| V7 | Como runner, `INSERT/UPDATE/DELETE/CREATE TABLE` sobre `horus_test_control` | N | Todas rechazadas |
| V8 | Cada runner conectando a la base ajena | P | Rechazo por HBA o privilegio |
| V9 | Conexión de un runner desde `::1` o a otra dirección | P | Rechazada |
| V10 | `php scripts/pgsql-test.php check` con consentimiento y contraseña en `.env.pgsql-testing.local` | G | «Authorized isolated PostgreSQL session…» |
| V11 | Contraprueba: con `DB_PORT=5432` o `DB_DATABASE=horus_group`, `check` debe rechazar antes de conectar | G | «unexpected connection setting» / «not on the fixed test allowlist» |
| V12 | **Aislamiento NTFS entre clústeres**, como aprovisionador (ejecuta con la identidad del servicio nuevo). Ver más abajo | P | Todas fallan con acceso denegado; ninguna lee |
| V13 | El aprovisionador ya no puede conectar tras S7 | P | Rechazo por `pg_hba.conf` |
| V14 | Cotidiana intacta: repetir §5.1 en `horus_l2_baseline_after.txt` y comparar con `Compare-Object` | R | Sin diferencias en servicio, ACL (SDDL) ni escucha |
| V15 | ACL heredadas y permisos efectivos (S3, bloque de verificación) | R | `ACL de initdb correctas` |
| V16 | D3: consultas A, B y E del §8 en `horus_l2_test` frente a las medidas de `horus_group` | P | Idénticas en los campos que deben coincidir |

### V12: aislamiento NTFS

El servidor nuevo se ejecuta con la cuenta del servicio, de modo que `pg_stat_file` (solo superusuarios) realiza el acceso con esa identidad. Cada llamada debe fallar con acceso denegado; si **alguna tiene éxito**, parar (§4, condición 6) y ejecutar el rollback.

```powershell
$repo = (git rev-parse --show-toplevel)
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($repo)) { throw 'STOP: ruta del repositorio no comprobada' }
foreach ($target in @('C:/Program Files/PostgreSQL/18/data/PG_VERSION', "$repo/artisan")) {
  $literal = $target.Replace("'", "''")
  $previousPreference = $ErrorActionPreference
  try {
    # Solo esta contraprueba espera stderr; no esconder fallos de autenticación o transporte.
    $ErrorActionPreference = 'Continue'
    $result = @(& 'C:\Program Files\PostgreSQL\18\bin\psql.exe' -X -W -h 127.0.0.1 -p 55432 -U horus_l2_provisioner -d postgres `
      --set=VERBOSITY=verbose --set=ON_ERROR_STOP=on -c "SELECT pg_stat_file('$literal');" 2>&1)
    $probeExit = $LASTEXITCODE
  } finally { $ErrorActionPreference = $previousPreference }
  $detail = $result -join "`n"
  if ($probeExit -ne 1 -or $detail -notmatch '42501:' -or $detail -notmatch 'Permission denied') { throw 'STOP: aislamiento NTFS no acreditado; revisar el resultado sin secretos' }
}
```

Además se comprueba que la ACL del directorio nuevo no incluye a `NetworkService` (SID `S-1-5-20`) y que la del cotidiano no incluye a la cuenta del servicio nuevo (V14 y V15). La ausencia de entradas en la ACL no equivale a una prueba general de aislamiento; V12 es la prueba efectiva.

## 12. Checklists

### A. Para solicitar la autorización de aprovisionamiento (D6)

| # | Condición | Estado |
| --- | --- | :---: |
| A1 | Línea base de la cotidiana capturada (§5.1) y Preflight 1 correcto | Pendiente de ejecutar |
| A2 | D3 resuelta: parámetros principales y complementos A/C/D/E revisados (§8.1) | Completa documentalmente; `$LocaleArgs`/`dbloc` definidos; V16 posterior |
| A3 | Rollback R0–R3 con evidencia externa, estados parciales y contraste NTFS/clúster | Corregido documentalmente; ejecución pendiente |
| A4 | Coincidencia con guard y separación A/B | Verificación estática; sin prueba PostgreSQL real |
| A5 | Autorización escrita (D6) que enumera §6 y §7 y los destinos | Pendiente |

Checklist definitivo para la solicitud A: adjuntar §8.1, ya completo, y confirmar los argumentos literales de S3/S6; capturar §5.1 y revalidar Preflight 1 inmediatamente antes de crear recursos, incluida la lectura NTFS desde N; enumerar todos los recursos y el rollback de §13 en la autorización escrita. No marcar A1 por una lectura histórica. Puede solicitarse A condicionada al preflight inmediato satisfactorio; no puede iniciarse S1 sin cumplir A1 y A5. La cuenta virtual y V16 son condiciones de aceptación durante la ejecución, no resultados ya obtenidos. La autorización A cubre infraestructura S1–S7 y R0–R3; no autoriza migraciones, seeders, limpiezas ni pruebas de L2.2-C, que requieren autorización B independiente.

### B. Para solicitar la autorización de las pruebas (G/N)

| # | Condición | Se comprueba con |
| --- | --- | --- |
| B1 | Instancia cotidiana intacta | V14 |
| B2 | Directorio de datos exclusivo y ACL sin *Usuarios* | V1, V15 |
| B3 | Identificador de clúster distinto | V3 |
| B4 | Cuenta Windows independiente | V2 y `sc qc`: no `NetworkService` |
| B5 | Puerto exclusivo | V1, V9 |
| B6 | Roles limitados a su base | V5, V6, V8 |
| B7 | Aislamiento NTFS | V12 |
| B8 | Aprovisionador desactivado | V13 |
| B9 | D3 aceptada | V16 |
| B10 | Credenciales separadas; APP_KEY exclusiva; nada reutilizado de `.env` | Revisión |
| B11 | Sin datos reales; `\dt` vacío antes de las migraciones | Revisión |
| B12 | Autorización escrita para pruebas destructivas y `HORUS_PG_TEST_CONFIRM` | Pendiente |

## 13. Alcance que debe nombrar la autorización (D6)

Una autorización de aprovisionamiento debe indicar, como mínimo:

- creación de `C:\pgdata` y `C:\pgdata\horus_l2_test`, su ACL y el registro del servicio `postgresql-horus-l2-test` con la cuenta virtual `NT SERVICE\postgresql-horus-l2-test`;
- creación del almacén externo `%LOCALAPPDATA%\HorusL2Provisioning`, su ACL, registro DPAPI y guiones SQL sin secretos; conservación de la evidencia tras rollback;
- `initdb` y edición de configuración solo bajo `C:\pgdata\horus_l2_test`;
- creación de los roles y bases del §6 S6 solo en esa instancia;
- la desactivación del aprovisionador (S7) y las condiciones del mantenimiento controlado;
- el rollback R0–R3, con los mismos recursos del §2;
- que **no** se toca el servicio `postgresql-x64-18`, su directorio ni la base `horus_group`;
- que, si la cuenta virtual falla, el proceso se detiene y no se usa otra cuenta sin una nueva autorización;
- que `migrate`, `migrate:fresh`, `db:wipe`, `db:seed`, `RefreshDatabase`, restauraciones y la suite PHPUnit PostgreSQL requieren una autorización posterior, aparte, y la variable `HORUS_PG_TEST_CONFIRM`.

## 14. Correspondencia con la auditoría

Correcciones de la **auditoría final**, revisión 3:

| Hallazgo | Corrección documental | Validación pendiente |
| --- | --- | --- |
| P1-1 Identidad antes de borrar | §2.1, S1–S7 y R0–R3: registro DPAPI externo con ACL, NTFS, etapas e identificador del clúster; sin evidencia no se borra | Ejecución autorizada de registro/lecturas/recuperación |
| P1-2 Continuación SQL | S6: tres guiones por base con `psql -f`, `ON_ERROR_STOP=on`, códigos comprobados y prohibición de S7 tras fallo | Ejecución PostgreSQL real con autorización A |
| P2 Comprobaciones incompletas | Prólogo obligatorio, errores de lectura terminantes, enumeración sin seguir reanálisis, códigos nativos, IPv4/IPv6 y `pg_isready` | Permisos y comportamiento reales en B2 |
| P3 Concurrencia | D4, §10 y §16: clase existente, dobles y concurrencia real separados; autorización B independiente | Suite real autorizada |

Correspondencia histórica de la revisión 2 (no son hallazgos nuevos):

| Hallazgo | Respuesta |
| --- | --- |
| P1-1 Reversión | §7 (R0–R3), tabla por etapa y recursos idénticos del §2 |
| P1-2 Cuenta de servicio | Respaldo local eliminado; `-U` obligatorio con verificación de cuenta y comando; parada en lugar de alternativa; D1 condicionada |
| P2-1 Locale | §8: tabla completa por proveedor, qué debe coincidir y qué no |
| P2-2 Codificación de consola | `chcp 65001`, `\encoding UTF8` y escapes Unicode en la consulta E |
| P2-3 Aprovisionador | S7 y mantenimiento controlado; V13 |
| P2-4 Puerto/escucha y ACL | `postgres -C` antes de arrancar, Preflight 2, verificación de ACL heredadas (V15) |
| P2-5 Aislamiento NTFS | V12 y V14 |
| P2-6 `sc.exe sidtype` | Retirado del procedimiento; ver §3 |
| P2-7 Autorización vs guard | Tipos R/P/G/N y checklists A/B separadas |
| P2-8 `lock_timeout` | §10: alcance real y requisitos de cada prueba |
| P3 SID personales y placeholders | Resolución en tiempo de ejecución; bloques que abortan si D3 no está definida |
| P3 Rangos excluidos | Revalidación en Preflight 1 y 2 |

## 15. Warnings de PHPUnit (origen confirmado y corrección independiente)

`php artisan test --compact` informa 265 pruebas aprobadas y **3 warnings**; `php vendor/bin/phpunit` informa 268 aprobadas y 0 warnings.

- **Origen:** `tests/Unit/TestDatabaseSafetyTest.php` líneas 40, 62 y 236 llaman a `$app->loadEnvironmentFrom('.env.nonexistent-safety-fixture')`. El archivo no existe a propósito, para que ninguna prueba cargue el `.env` real. `vlucas/phpdotenv` lo lee con `@file_get_contents` (`Store/File/Reader.php:73`); PHP emite un warning suprimido, PHPUnit lo registra como suprimido, el impresor de PHPUnit lo oculta y el de `artisan test` (Collision) lo muestra como WARN.
- **Clasificación:** warning del impresor de `artisan test`. No es un fallo de configuración, ni una prueba incompleta, ni un riesgo de seguridad, y no afecta al aprovisionamiento. Las pruebas pasan por la razón prevista.
- **No se modificó el guard, el bootstrap ni las pruebas en esta tarea.**
- **Corrección propuesta (tarea independiente):** sustituir el archivo inexistente por un **archivo `.env` vacío y real** con otro nombre (por ejemplo, un fixture `tests/Fixtures/empty.env.fixture` o un archivo temporal creado en la prueba y borrado al terminar), seleccionado con `useEnvironmentPath()` y `loadEnvironmentFrom()`. Debe conservar el aislamiento: nunca se lee `.env`, el fixture no contiene valores y la prueba sigue exigiendo el rechazo del guard. No usar `ignoreSuppressionOfWarnings` en `phpunit.xml`, porque ocultaría warnings legítimos.
- Aparte, higiene: los dos primeros tests no restauran los manejadores de errores tras un bootstrap completo (el tercero sí).

## 16. Limitaciones y garantías no probadas

- La cuenta virtual con `pg_ctl register` **no se ha probado** en este equipo; D1 queda condicionada hasta S2 y S5.
- La propagación de ACL, el aislamiento NTFS efectivo (V12) y la desactivación del aprovisionador no se han ejecutado.
- Todos los resultados D3 de `horus_group` fueron comunicados por el operador (§8.1); D3 está cerrada documentalmente y V16 sigue pendiente. No se ha probado `initdb` con estos argumentos.
- El agente no ha abierto conexiones ni ejecutado SQL PostgreSQL; solo comprobaciones de versión/ayuda sin conexión. Las pruebas de L2.2-A son SQLite y dobles, y no acreditan PostgreSQL real.
- `ConcurrentTestSession` está implementada y probada con dobles. Siguen pendientes el paralelismo real entre procesos y la supervivencia del marcador ante `migrate:fresh`, `db:wipe` y `DatabaseTruncation`, con autorización B independiente.
- La instancia cotidiana escucha en todas las interfaces (`0.0.0.0` y `::`). No se modifica aquí; conviene revisarlo aparte.
- Los buscadores actuales usan `whereLike` sin sensibilidad a mayúsculas explícita: Laravel lo compila a `ILIKE` en PostgreSQL, de acuerdo con L2.2-C. Eso no garantiza equivalencia completa con SQLite para acentos y orden; se conserva la medición E y V16.
- Este documento no es evidencia de que el procedimiento funcione; solo describe lo que debe revisarse y ejecutarse con autorización.
