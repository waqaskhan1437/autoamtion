param(
    [ValidateSet('start', 'stop', 'status')]
    [string]$Action = 'status',
    [string]$StatePath = '',
    [string]$StdOutPath = '',
    [string]$StdErrPath = '',
    [int]$LocalPort = 80
)

$ErrorActionPreference = 'Stop'

function Emit-Json([hashtable]$Payload) {
    $Payload | ConvertTo-Json -Depth 6 -Compress
}

function Read-State([string]$Path) {
    if ([string]::IsNullOrWhiteSpace($Path) -or -not (Test-Path -LiteralPath $Path)) {
        return [pscustomobject]@{}
    }

    try {
        return (Get-Content -Raw -LiteralPath $Path | ConvertFrom-Json)
    } catch {
        return [pscustomobject]@{}
    }
}

function Write-State([string]$Path, [hashtable]$State) {
    $dir = Split-Path -Parent $Path
    if ($dir -and -not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }

    $json = $State | ConvertTo-Json -Depth 6
    [System.IO.File]::WriteAllText($Path, $json, [System.Text.UTF8Encoding]::new($false))
}

function Is-ProcessAlive([int]$ProcessId) {
    if ($ProcessId -le 0) {
        return $false
    }

    try {
        $null = Get-Process -Id $ProcessId -ErrorAction Stop
        return $true
    } catch {
        return $false
    }
}

function Get-FirstPublicUrl([string[]]$Paths) {
    $lines = New-Object System.Collections.Generic.List[string]

    foreach ($path in $Paths) {
        if ([string]::IsNullOrWhiteSpace($path) -or -not (Test-Path -LiteralPath $path)) {
            continue
        }

        foreach ($line in (Get-Content -LiteralPath $path -ErrorAction SilentlyContinue)) {
            $lines.Add([string]$line)
        }
    }

    foreach ($line in $lines) {
        if ($line -match 'tunneled with tls termination,\s*(https://[A-Za-z0-9.-]+)') {
            return $Matches[1]
        }
    }

    foreach ($line in $lines) {
        if ($line -match '(https://[A-Za-z0-9.-]+)') {
            $candidate = $Matches[1]
            try {
                $host = ([Uri]$candidate).Host.ToLowerInvariant()
                if ($host -match '\.lhr\.life$') {
                    return $candidate
                }
                if ($host -match '\.localhost\.run$' -and $host -ne 'admin.localhost.run') {
                    return $candidate
                }
            } catch {
                continue
            }
        }
    }

    return ''
}

function Get-LogTail([string]$Path, [int]$Count = 12) {
    if ([string]::IsNullOrWhiteSpace($Path) -or -not (Test-Path -LiteralPath $Path)) {
        return @()
    }

    return @(Get-Content -LiteralPath $Path -Tail $Count -ErrorAction SilentlyContinue)
}

if ([string]::IsNullOrWhiteSpace($StatePath)) {
    throw 'StatePath is required.'
}

$state = Read-State $StatePath
$knownStdOut = if ($StdOutPath) { $StdOutPath } elseif ($state.stdout_path) { [string]$state.stdout_path } else { '' }
$knownStdErr = if ($StdErrPath) { $StdErrPath } elseif ($state.stderr_path) { [string]$state.stderr_path } else { '' }

switch ($Action) {
    'status' {
        $processId = 0
        if ($state.pid) {
            $processId = [int]$state.pid
        }

        $running = Is-ProcessAlive $processId
        $url = Get-FirstPublicUrl @($knownStdOut, $knownStdErr)
        if (-not $url -and $state.public_origin) {
            $url = [string]$state.public_origin
        }

        if ($running -or $url) {
            $updated = @{
                pid = $processId
                running = $running
                public_origin = $url
                local_port = if ($state.local_port) { [int]$state.local_port } else { $LocalPort }
                stdout_path = $knownStdOut
                stderr_path = $knownStdErr
                started_at = if ($state.started_at) { [string]$state.started_at } else { (Get-Date).ToString('o') }
            }
            Write-State $StatePath $updated
        }

        Emit-Json @{
            ok = $true
            running = $running
            pid = $processId
            public_origin = $url
            stdout_path = $knownStdOut
            stderr_path = $knownStdErr
        }
        exit 0
    }

    'stop' {
        $processId = if ($state.pid) { [int]$state.pid } else { 0 }
        $stopped = $false
        if (Is-ProcessAlive $processId) {
            Stop-Process -Id $processId -Force -ErrorAction Stop
            $stopped = $true
            Start-Sleep -Milliseconds 500
        }

        $lastUrl = Get-FirstPublicUrl @($knownStdOut, $knownStdErr)
        Write-State $StatePath @{
            pid = 0
            running = $false
            public_origin = $lastUrl
            local_port = if ($state.local_port) { [int]$state.local_port } else { $LocalPort }
            stdout_path = $knownStdOut
            stderr_path = $knownStdErr
            stopped_at = (Get-Date).ToString('o')
        }

        Emit-Json @{
            ok = $true
            stopped = $stopped
            public_origin = $lastUrl
        }
        exit 0
    }

    'start' {
        $currentPid = if ($state.pid) { [int]$state.pid } else { 0 }
        if (Is-ProcessAlive $currentPid) {
            $url = Get-FirstPublicUrl @($knownStdOut, $knownStdErr)
            if (-not $url -and $state.public_origin) {
                $url = [string]$state.public_origin
            }

            Emit-Json @{
                ok = $true
                running = $true
                reused = $true
                pid = $currentPid
                public_origin = $url
                stdout_path = $knownStdOut
                stderr_path = $knownStdErr
            }
            exit 0
        }

        if (-not $knownStdOut -or -not $knownStdErr) {
            throw 'stdout/stderr log paths are required for start.'
        }

        foreach ($path in @($knownStdOut, $knownStdErr)) {
            $dir = Split-Path -Parent $path
            if ($dir -and -not (Test-Path -LiteralPath $dir)) {
                New-Item -ItemType Directory -Path $dir -Force | Out-Null
            }
            if (Test-Path -LiteralPath $path) {
                Remove-Item -LiteralPath $path -Force
            }
            New-Item -ItemType File -Path $path -Force | Out-Null
        }

        $sshCommand = Get-Command ssh.exe -ErrorAction Stop
        $args = @(
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'ServerAliveInterval=30',
            '-o', 'ExitOnForwardFailure=yes',
            '-R', ('80:localhost:' + $LocalPort),
            'nokey@localhost.run'
        )

        $proc = Start-Process -FilePath $sshCommand.Source `
            -ArgumentList $args `
            -RedirectStandardOutput $knownStdOut `
            -RedirectStandardError $knownStdErr `
            -PassThru `
            -WindowStyle Hidden

        $url = ''
        for ($i = 0; $i -lt 30; $i++) {
            Start-Sleep -Seconds 1
            $url = Get-FirstPublicUrl @($knownStdOut, $knownStdErr)
            if ($url) {
                break
            }
            if ($proc.HasExited) {
                break
            }
        }

        if (-not $url) {
            if (-not $proc.HasExited) {
                Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
            }

            Emit-Json @{
                ok = $false
                error = 'Failed to establish localhost.run tunnel.'
                stdout_tail = Get-LogTail $knownStdOut
                stderr_tail = Get-LogTail $knownStdErr
            }
            exit 1
        }

        $newState = @{
            pid = $proc.Id
            running = $true
            public_origin = $url
            local_port = $LocalPort
            stdout_path = $knownStdOut
            stderr_path = $knownStdErr
            started_at = (Get-Date).ToString('o')
        }
        Write-State $StatePath $newState

        Emit-Json @{
            ok = $true
            running = $true
            reused = $false
            pid = $proc.Id
            public_origin = $url
            stdout_path = $knownStdOut
            stderr_path = $knownStdErr
        }
        exit 0
    }
}
