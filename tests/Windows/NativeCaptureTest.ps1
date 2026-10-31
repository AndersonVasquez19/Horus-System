param([Parameter(Mandatory)][string]$EvidenceDirectory)
$ErrorActionPreference='Stop'
$testSource=$PSScriptRoot
if(-not [IO.Path]::IsPathRooted($EvidenceDirectory) -or (Test-Path -LiteralPath $EvidenceDirectory)){throw 'Fresh absolute external evidence directory required'}
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
if($EvidenceDirectory.StartsWith($repo,[StringComparison]::OrdinalIgnoreCase)){throw 'Evidence must remain outside repository'}
[void][IO.Directory]::CreateDirectory($EvidenceDirectory)

. (Join-Path $repo 'scripts\testing\NativeCapture.ps1')
$exe=Join-Path $EvidenceDirectory 'CaptureFixture.exe'
Add-Type -Path (Join-Path $testSource 'NativeCaptureFixture.cs') -OutputAssembly $exe -OutputType ConsoleApplication
$rows=@();$pids=@()
function Check($Name,$Condition){if(-not $Condition){throw ('Regression failed: '+$Name)};$script:rows+=@{Name=$Name;Passed=$true}}
foreach($code in 0,2,37){
 $path=Join-Path $EvidenceDirectory ('case-'+$code)
 $r=Invoke-HorusNativeCapture -Executable $exe -NativeArguments @([string]$code,$path) -WorkingDirectory $repo -EvidenceDirectory $path
 $r|ConvertTo-Json|Set-Content ($path+'.json') -Encoding UTF8
 $pids+=$r.ProcessId
 Check ('native-exit-'+$code) ($r.Outcome -eq 'Exited' -and $r.NativeExitCode -eq $code -and $r.StreamsClosed)
 Check ('unicode-streams-'+$code) ($r.Stdout.Contains([char]0x6f22) -and $r.Stderr.Contains([char]0x03a9) -and -not $r.DecodeExceptionType)
}
$path=Join-Path $EvidenceDirectory 'case-bulk'
$r=Invoke-HorusNativeCapture -Executable $exe -NativeArguments @('bulk',$path) -WorkingDirectory $repo -EvidenceDirectory $path
$pids+=$r.ProcessId
Check 'bulk-both-streams' ($r.NativeExitCode -eq 0 -and @($r.Stdout -split "`n").Count -eq 30001 -and @($r.Stderr -split "`n").Count -eq 30001 -and $r.Stdout.Contains('29999') -and $r.Stderr.Contains('29999'))
$path=Join-Path $EvidenceDirectory 'case-descendant'
$r=Invoke-HorusNativeCapture -Executable $exe -NativeArguments @('spawn',$path) -WorkingDirectory $repo -EvidenceDirectory $path
$r|ConvertTo-Json|Set-Content ($path+'.json') -Encoding UTF8
$pids+=$r.ProcessId
Check 'root-exit-with-live-descendant' ($r.NativeExitCode -eq 37 -and $r.DurationMilliseconds -lt 1800 -and -not $r.StreamsClosed)
$child=[int](Get-Content ($path+'.spawned-pid'));$pids+=$child
$childProcess=[Diagnostics.Process]::GetProcessById($child)
Check 'descendant-continues-after-root' (-not $childProcess.HasExited)
Check 'descendant-natural-exit' ($childProcess.WaitForExit(5000));$childProcess.Dispose()
Check 'complete-late-output-preserved' ([Horus.Testing.NativeCapture]::SnapshotUtf8($r.StdoutPath).Contains('child late') -and [Horus.Testing.NativeCapture]::WritersClosed($r.StdoutPath))
$path=Join-Path $EvidenceDirectory 'case-timeout'
$r=Invoke-HorusNativeCapture -Executable $exe -NativeArguments @('timeout',$path) -WorkingDirectory $repo -EvidenceDirectory $path -TimeoutMilliseconds 50
$r|ConvertTo-Json|Set-Content ($path+'.json') -Encoding UTF8
$pids+=$r.ProcessId
Check 'timeout-distinct-no-false-exit' ($r.Outcome -eq 'TimedOut' -and $null -eq $r.NativeExitCode)
$childProcess=[Diagnostics.Process]::GetProcessById($r.ProcessId)
Check 'timeout-natural-exit-no-kill' ($childProcess.WaitForExit(5000));$childProcess.Dispose()
$path=Join-Path $EvidenceDirectory 'case-wait-root'
$r=Invoke-HorusNativeCapture -Executable $exe -NativeArguments @('timeout',$path) -WorkingDirectory $repo -EvidenceDirectory $path -TimeoutMilliseconds 1 -WaitForRootExit
$pids+=$r.ProcessId
Check 'explicit-wrapper-root-wait-does-not-timeout' ($r.Outcome -eq 'Exited' -and $r.NativeExitCode -eq 0 -and $r.StreamsClosed)
$path=Join-Path $EvidenceDirectory 'case-invalid'
$r=Invoke-HorusNativeCapture -Executable $exe -NativeArguments @('invalid',$path) -WorkingDirectory $repo -EvidenceDirectory $path
$pids+=$r.ProcessId
Check 'invalid-utf8-retains-code-and-raw-bytes' ($r.NativeExitCode -eq 2 -and $r.DecodeExceptionType -and [IO.File]::ReadAllBytes($r.StdoutPath)[0] -eq 255)
Check 'invalid-stdout-does-not-hide-valid-stderr' ($r.Stderr.Contains([char]0x6f22) -and $r.DecodeErrors.ContainsKey('Output') -and -not $r.DecodeErrors.ContainsKey('Error'))
# Hold a writer open: completeness cannot become true based on a later, separate probe.
$pairOut=Join-Path $EvidenceDirectory 'pair-out.bin';$pairErr=Join-Path $EvidenceDirectory 'pair-err.bin'
[IO.File]::WriteAllText($pairOut,'first',[Text.UTF8Encoding]::new($false));[IO.File]::WriteAllText($pairErr,'error',[Text.UTF8Encoding]::new($false))
$writer=[IO.File]::Open($pairOut,[IO.FileMode]::Append,[IO.FileAccess]::Write,[IO.FileShare]::ReadWrite)
try {
 $snapshot=[Horus.Testing.NativeCapture]::ReadPair($pairOut,$pairErr)
 Check 'open-writer-always-reported-partial' (-not $snapshot.StreamsClosed)
 $bytes=[Text.Encoding]::UTF8.GetBytes(' tail');$writer.Write($bytes,0,$bytes.Length);$writer.Flush()
 Check 'snapshot-boundary-frozen-before-late-append' ([Horus.Testing.NativeCapture]::DecodeUtf8($snapshot.Output) -ceq 'first')
}finally{$writer.Dispose()}
$snapshot=[Horus.Testing.NativeCapture]::ReadPair($pairOut,$pairErr)
Check 'complete-pair-read-under-exclusive-locks' ($snapshot.StreamsClosed -and [Horus.Testing.NativeCapture]::DecodeUtf8($snapshot.Output) -ceq 'first tail')
$path=Join-Path $EvidenceDirectory 'case-missing'
$r=Invoke-HorusNativeCapture -Executable (Join-Path $EvidenceDirectory 'missing.exe') -WorkingDirectory $repo -EvidenceDirectory $path
Check 'execution-error-distinct' ($r.Outcome -eq 'ExecutionError' -and $null -eq $r.NativeExitCode -and $r.TransportExceptionType)
$refused=$false;try{Invoke-HorusNativeCapture -Executable $exe -NativeArguments @('0',$path) -WorkingDirectory $repo -EvidenceDirectory $path|Out-Null}catch{$refused=$true}
Check 'evidence-never-overwritten' $refused
# Finite reproduction of the original pipe/EOF bug. No real PostgreSQL involved.
function Invoke-HorusNative17($Executable,$NativeArguments,$WorkingDirectory){
 $p=[Diagnostics.Process]::new();$p.StartInfo.FileName=$Executable
 $p.StartInfo.Arguments=($NativeArguments|ForEach-Object{'"'+$_+'"'}) -join ' '
 $p.StartInfo.WorkingDirectory=$WorkingDirectory;$p.StartInfo.UseShellExecute=$false
 $p.StartInfo.RedirectStandardOutput=$true;$p.StartInfo.RedirectStandardError=$true
 $p.StartInfo.StandardOutputEncoding=[Text.UTF8Encoding]::new($false);$p.StartInfo.StandardErrorEncoding=[Text.UTF8Encoding]::new($false)
 [void]$p.Start();$a=$p.StandardOutput.ReadToEndAsync();$b=$p.StandardError.ReadToEndAsync();$p.WaitForExit()
 try{return [pscustomobject]@{NativeExitCode=$p.ExitCode;Stdout=$a.GetAwaiter().GetResult();Stderr=$b.GetAwaiter().GetResult()}}finally{$p.Dispose()}
}
$oldPath=Join-Path $EvidenceDirectory 'case-old-pipe'
$watch=[Diagnostics.Stopwatch]::StartNew()
$old=Invoke-HorusNative17 -Executable $exe -NativeArguments @('spawn',$oldPath) -WorkingDirectory $repo
$watch.Stop();$pids+=[int](Get-Content ($oldPath+'.spawned-pid'))
Check 'original-bug-reproduced-finite' ($old.NativeExitCode -eq 37 -and $watch.ElapsedMilliseconds -ge 1800 -and $old.Stdout.Contains('child late'))
$active=@(Get-CimInstance Win32_Process|Where-Object {$_.Name -eq 'CaptureFixture.exe' -and $_.ExecutablePath -ceq $exe})
Check 'zero-fixture-processes-after-natural-exits' ($active.Count -eq 0)
@{Tests=$rows;Count=$rows.Count;OldPipeDurationMs=$watch.ElapsedMilliseconds;FixturePids=$pids;ForcedTerminations=0;ActiveFixtures=$active.Count}|ConvertTo-Json -Depth 6|Set-Content (Join-Path $EvidenceDirectory 'capture-results.json') -Encoding UTF8
Write-Output ('PASS '+$rows.Count+' capture regressions; all fixtures exited naturally.')
