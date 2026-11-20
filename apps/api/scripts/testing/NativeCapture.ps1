# Windows PowerShell 5.1. Caller owns the command, redaction and the fresh evidence directory.
function Invoke-HorusNativeCapture {
    param(
        [Parameter(Mandatory)][string]$Executable,
        [string[]]$NativeArguments=@(),
        [Parameter(Mandatory)][string]$WorkingDirectory,
        [Parameter(Mandatory)][string]$EvidenceDirectory,
        [ValidateRange(1,2147483647)][int]$TimeoutMilliseconds=60000,
        [switch]$WaitForRootExit
    )
    if (-not ('Horus.Testing.NativeCapture' -as [type])) {
        Add-Type -Path (Join-Path $PSScriptRoot 'NativeCapture.cs')
    }
    # An existing directory must never be reused, even if apparently empty.
    if (Test-Path -LiteralPath $EvidenceDirectory) { throw 'Capture evidence already exists; never overwrite or retry automatically.' }
    [void][IO.Directory]::CreateDirectory($EvidenceDirectory)
    $out=Join-Path $EvidenceDirectory 'stdout.bin'
    $err=Join-Path $EvidenceDirectory 'stderr.bin'
    $wait=if($WaitForRootExit){[uint32]::MaxValue}else{[uint32]$TimeoutMilliseconds}
    $result=[Horus.Testing.NativeCapture]::Run($Executable,$NativeArguments,$WorkingDirectory,$out,$err,$wait)
    $stdout=$null;$stderr=$null;$decodeType=$null;$decodeMessage=$null;$closed=$false;$decoding=@{};$readType=$null;$readMessage=$null
    try { if ((Test-Path -LiteralPath $out) -and (Test-Path -LiteralPath $err)) {
        $snapshot=[Horus.Testing.NativeCapture]::ReadPair($out,$err)
        $closed=$snapshot.StreamsClosed
        foreach($stream in 'Output','Error') {
            try {
                $value=[Horus.Testing.NativeCapture]::DecodeUtf8($snapshot.$stream)
                if($stream -eq 'Output'){$stdout=$value}else{$stderr=$value}
            } catch {
                # Decode independently; neither stream can suppress the other. Raw bytes remain intact.
                $decoding[$stream]=@{Type=$_.Exception.GetType().FullName;Message=$_.Exception.Message}
                $decodeType=$_.Exception.GetType().FullName;$decodeMessage=$_.Exception.Message
            }
        }
    } } catch { $readType=$_.Exception.GetType().FullName;$readMessage=$_.Exception.Message }
    [pscustomobject]@{
        Outcome=$result.Outcome; NativeExitCode=$result.ExitCode; ProcessId=$result.ProcessId
        DurationMilliseconds=$result.DurationMilliseconds; Stdout=$stdout; Stderr=$stderr
        StdoutPath=$out; StderrPath=$err
        StreamsClosed=$closed
        TransportExceptionType=$result.ExceptionType;TransportExceptionMessage=$result.ExceptionMessage
        DecodeExceptionType=$decodeType;DecodeExceptionMessage=$decodeMessage;DecodeErrors=$decoding
        SnapshotExceptionType=$readType;SnapshotExceptionMessage=$readMessage
    }
}
