param(
    [string]$RepoZipUrl = "",
    [string]$RepoZipPath = "",
    [Parameter(Mandatory = $true)][string]$ServerUrl,
    [Parameter(Mandatory = $true)][string]$PairingToken,
    [string]$InstallDir = "C:\VideoWorkflowAgent",
    [string]$PhpPath = "",
    [string]$WorkerDbName = "video_workflow_agent",
    [string]$WorkerBaseDir = "C:\VideoWorkflowAgentData",
    [int]$PollInterval = 10,
    [switch]$CreateScheduledTask
)

$ErrorActionPreference = "Stop"

function Ensure-Directory([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Get-Json([string]$Uri) {
    return Invoke-RestMethod -Uri $Uri -Method GET
}

function Resolve-PortablePhpUrl([string]$PreferredUrl) {
    if (-not [string]::IsNullOrWhiteSpace($PreferredUrl)) {
        return $PreferredUrl
    }

    $releases = Get-Json 'https://windows.php.net/downloads/releases/releases.json'
    foreach ($property in $releases.PSObject.Properties) {
        $release = $property.Value
        if ($release -and $release.zip -and $release.zip.path -match 'x64' -and $release.zip.path -match 'nts') {
            return 'https://windows.php.net/downloads/releases/' + $release.zip.path
        }
    }

    throw "Unable to determine a portable PHP download URL."
}

function Resolve-PhpPath([string]$PreferredPhpPath, [string]$InstallRoot, [string]$PreferredDownloadUrl) {
    if (-not [string]::IsNullOrWhiteSpace($PreferredPhpPath) -and (Test-Path -LiteralPath $PreferredPhpPath)) {
        return $PreferredPhpPath
    }

    foreach ($candidate in @('C:\xampp\php\php.exe', 'C:\php\php.exe')) {
        if (Test-Path -LiteralPath $candidate) {
            return $candidate
        }
    }

    $phpInPath = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($phpInPath -and (Test-Path -LiteralPath $phpInPath.Source)) {
        return $phpInPath.Source
    }

    $phpDir = Join-Path $InstallRoot 'php'
    $phpExe = Join-Path $phpDir 'php.exe'
    if (Test-Path -LiteralPath $phpExe) {
        return $phpExe
    }

    Ensure-Directory $phpDir
    $zipPath = Join-Path $InstallRoot 'php-portable.zip'
    $downloadUrl = Resolve-PortablePhpUrl $PreferredDownloadUrl
    Invoke-WebRequest -Uri $downloadUrl -OutFile $zipPath
    Expand-Archive -Path $zipPath -DestinationPath $phpDir -Force

    $iniProduction = Join-Path $phpDir 'php.ini-production'
    $iniPath = Join-Path $phpDir 'php.ini'
    if (Test-Path -LiteralPath $iniProduction) {
        Copy-Item -LiteralPath $iniProduction -Destination $iniPath -Force
        $iniContent = Get-Content -LiteralPath $iniPath -Raw
        $iniContent = $iniContent -replace '; extension_dir = "ext"', 'extension_dir = "ext"'
        foreach ($extension in @('curl', 'mbstring', 'mysqli', 'openssl', 'pdo_mysql', 'zip')) {
            $iniContent = $iniContent -replace (';extension=' + [regex]::Escape($extension)), ('extension=' + $extension)
        }
        Set-Content -LiteralPath $iniPath -Value $iniContent -Encoding ASCII
    }

    if (-not (Test-Path -LiteralPath $phpExe)) {
        throw "Portable PHP installation failed."
    }

    return $phpExe
}

function Resolve-AgentManifest([string]$BaseUrl, [string]$Token) {
    $url = $BaseUrl.TrimEnd('/') + '/api/agent/install-manifest?pairing_token=' + [uri]::EscapeDataString($Token)
    try {
        return Get-Json $url
    } catch {
        return $null
    }
}

function Resolve-RepoZip([string]$ProvidedUrl, [string]$ProvidedPath, $Manifest) {
    if (-not [string]::IsNullOrWhiteSpace($ProvidedPath)) {
        if (-not (Test-Path -LiteralPath $ProvidedPath)) {
            throw "Repo zip path not found: $ProvidedPath"
        }
        return @{
            type = 'file'
            value = $ProvidedPath
        }
    }

    if (-not [string]::IsNullOrWhiteSpace($ProvidedUrl)) {
        return @{
            type = 'url'
            value = $ProvidedUrl
        }
    }

    if ($Manifest -and -not [string]::IsNullOrWhiteSpace([string]$Manifest.package_url)) {
        return @{
            type = 'url'
            value = [string]$Manifest.package_url
        }
    }

    throw "Pass either -RepoZipUrl or -RepoZipPath, or expose package_url from the server manifest."
}

function Expand-AgentPackage($Source, [string]$DestinationDir) {
    Ensure-Directory $DestinationDir
    $zipPath = Join-Path $DestinationDir 'agent-package.zip'
    $appDir = Join-Path $DestinationDir 'app'

    if (Test-Path -LiteralPath $appDir) {
        Remove-Item -Recurse -Force $appDir
    }
    Ensure-Directory $appDir

    if ($Source.type -eq 'file') {
        Copy-Item -LiteralPath $Source.value -Destination $zipPath -Force
    } else {
        Invoke-WebRequest -Uri $Source.value -OutFile $zipPath
    }

    Expand-Archive -Path $zipPath -DestinationPath $appDir -Force

    $automationFile = Join-Path $appDir 'automation.php'
    if (-not (Test-Path -LiteralPath $automationFile)) {
        $nestedRoot = Get-ChildItem -Path $appDir -Directory | Where-Object { Test-Path (Join-Path $_.FullName 'automation.php') } | Select-Object -First 1
        if ($nestedRoot) {
            Get-ChildItem -Path $nestedRoot.FullName -Force | ForEach-Object {
                Move-Item -Path $_.FullName -Destination $appDir -Force
            }
            Remove-Item -Recurse -Force $nestedRoot.FullName
        }
    }

    $agentScript = Join-Path $appDir 'scripts\local-agent.php'
    if (-not (Test-Path -LiteralPath $agentScript)) {
        throw "local-agent.php not found after extraction"
    }

    return @{
        app_dir = $appDir
        agent_script = $agentScript
    }
}

$serverRoot = $ServerUrl.TrimEnd('/')
$manifest = Resolve-AgentManifest -BaseUrl $serverRoot -Token $PairingToken

if ($manifest -and $manifest.server_url) {
    $serverRoot = [string]$manifest.server_url
}
if ($manifest -and $manifest.worker_db_name -and -not $PSBoundParameters.ContainsKey('WorkerDbName')) {
    $WorkerDbName = [string]$manifest.worker_db_name
}
if ($manifest -and $manifest.worker_base_dir -and -not $PSBoundParameters.ContainsKey('WorkerBaseDir')) {
    $WorkerBaseDir = [string]$manifest.worker_base_dir
}
if ($manifest -and $manifest.poll_interval -and -not $PSBoundParameters.ContainsKey('PollInterval')) {
    $PollInterval = [int]$manifest.poll_interval
}
if ($manifest -and $manifest.install_dir -and -not $PSBoundParameters.ContainsKey('InstallDir')) {
    $InstallDir = [string]$manifest.install_dir
}

Ensure-Directory $InstallDir
$manifestPhpDownloadUrl = ""
if ($manifest -and $manifest.php_download_url) {
    $manifestPhpDownloadUrl = [string]$manifest.php_download_url
}
$resolvedPhp = Resolve-PhpPath -PreferredPhpPath $PhpPath -InstallRoot $InstallDir -PreferredDownloadUrl $manifestPhpDownloadUrl
$repoZip = Resolve-RepoZip -ProvidedUrl $RepoZipUrl -ProvidedPath $RepoZipPath -Manifest $manifest
$package = Expand-AgentPackage -Source $repoZip -DestinationDir $InstallDir

$startScript = Join-Path $InstallDir 'start-agent.ps1'
$startScriptContent = @"
param(
    [Parameter(ValueFromRemainingArguments = `$true)]
    [string[]]`$ExtraArgs
)

`$ErrorActionPreference = 'Stop'
`$env:VW_BASE_DATA_DIR = '$WorkerBaseDir'
`$agentArgs = @(
    '--server-url=$serverRoot',
    '--pairing-token=$PairingToken',
    '--worker-db-name=$WorkerDbName',
    '--worker-base-dir=$WorkerBaseDir',
    '--poll-interval=$PollInterval'
)
if (`$ExtraArgs) {
    `$agentArgs += `$ExtraArgs
}
& '$resolvedPhp' '$($package.agent_script)' @agentArgs
"@
Set-Content -LiteralPath $startScript -Value $startScriptContent -Encoding UTF8

if ($CreateScheduledTask) {
    $taskName = 'VideoWorkflowLocalAgent'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-ExecutionPolicy Bypass -File `"$startScript`""
    $trigger = New-ScheduledTaskTrigger -AtLogOn
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Force | Out-Null
}

Write-Host "Agent files installed to $InstallDir"
Write-Host "PHP: $resolvedPhp"
Write-Host "Start command:"
Write-Host "powershell -ExecutionPolicy Bypass -File `"$startScript`""
