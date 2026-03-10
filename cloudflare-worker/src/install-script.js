function psSingleQuoted(value) {
  return String(value ?? '').replace(/'/g, "''");
}

export function renderWindowsInstallScript(config) {
  const serverUrl = psSingleQuoted(config.serverUrl || '');
  const pairingToken = psSingleQuoted(config.pairingToken || '');
  const installDir = psSingleQuoted(config.installDir || 'C:\\VideoWorkflowAgent');
  const workerDbName = psSingleQuoted(config.workerDbName || 'video_workflow_agent');
  const workerBaseDir = psSingleQuoted(config.workerBaseDir || 'C:\\VideoWorkflowAgentData');
  const pollInterval = Number.parseInt(String(config.pollInterval || '10'), 10) || 10;
  const manifestUrl = psSingleQuoted(config.manifestUrl || '');

  return `param(
    [string]$ServerUrl = '${serverUrl}',
    [string]$PairingToken = '${pairingToken}',
    [string]$InstallDir = '${installDir}',
    [string]$WorkerDbName = '${workerDbName}',
    [string]$WorkerBaseDir = '${workerBaseDir}',
    [int]$PollInterval = ${pollInterval},
    [string]$PhpPath = '',
    [switch]$CreateScheduledTask
)

$ErrorActionPreference = 'Stop'

function Ensure-Directory([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Get-Json([string]$Uri) {
    return Invoke-RestMethod -Uri $Uri -Method GET
}

function Resolve-ManifestUrl([string]$BaseUrl, [string]$Token) {
    $configured = '${manifestUrl}'
    if (-not [string]::IsNullOrWhiteSpace($configured)) {
        return $configured
    }
    return $BaseUrl.TrimEnd('/') + '/api/agent/install-manifest?pairing_token=' + [uri]::EscapeDataString($Token)
}

function Resolve-PortablePhpUrl([string]$ManifestPhpUrl) {
    if (-not [string]::IsNullOrWhiteSpace($ManifestPhpUrl)) {
        return $ManifestPhpUrl
    }

    $releases = Get-Json 'https://windows.php.net/downloads/releases/releases.json'
    foreach ($property in $releases.PSObject.Properties) {
        $release = $property.Value
        if ($release -and $release.zip -and $release.zip.path -match 'x64' -and $release.zip.path -match 'nts') {
            return 'https://windows.php.net/downloads/releases/' + $release.zip.path
        }
    }

    throw 'Unable to determine a portable PHP download URL.'
}

function Resolve-PhpPath([string]$PreferredPhpPath, [string]$InstallRoot, [string]$ManifestPhpUrl) {
    if (-not [string]::IsNullOrWhiteSpace($PreferredPhpPath) -and (Test-Path -LiteralPath $PreferredPhpPath)) {
        return $PreferredPhpPath
    }

    foreach ($candidate in @('C:\\xampp\\php\\php.exe', 'C:\\php\\php.exe')) {
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
    $downloadUrl = Resolve-PortablePhpUrl $ManifestPhpUrl
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
        throw 'Portable PHP installation failed.'
    }

    return $phpExe
}

function Expand-RepoPackage([string]$ZipUrl, [string]$DestinationDir) {
    Ensure-Directory $DestinationDir
    $zipPath = Join-Path $DestinationDir 'agent-package.zip'
    $appDir = Join-Path $DestinationDir 'app'

    if (Test-Path -LiteralPath $appDir) {
        Remove-Item -Recurse -Force $appDir
    }
    Ensure-Directory $appDir

    Invoke-WebRequest -Uri $ZipUrl -OutFile $zipPath
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

    $agentScript = Join-Path $appDir 'scripts\\local-agent.php'
    if (-not (Test-Path -LiteralPath $agentScript)) {
        throw 'local-agent.php not found after extraction.'
    }

    return @{
        app_dir = $appDir
        agent_script = $agentScript
    }
}

if ([string]::IsNullOrWhiteSpace($ServerUrl)) {
    throw 'ServerUrl is required.'
}
if ([string]::IsNullOrWhiteSpace($PairingToken)) {
    throw 'PairingToken is required.'
}

$manifestUrl = Resolve-ManifestUrl -BaseUrl $ServerUrl -Token $PairingToken
$manifest = Get-Json $manifestUrl
if (-not $manifest.success) {
    if ($manifest.error) {
        throw [string]$manifest.error
    }
    throw 'Unable to load install manifest.'
}
if ([string]::IsNullOrWhiteSpace([string]$manifest.package_url)) {
    throw 'Manifest does not include package_url.'
}

$effectiveServerUrl = if ($manifest.server_url) { [string]$manifest.server_url } else { $ServerUrl.TrimEnd('/') }
$effectiveInstallDir = if ($manifest.install_dir -and -not $PSBoundParameters.ContainsKey('InstallDir')) { [string]$manifest.install_dir } else { $InstallDir }
$effectiveWorkerDbName = if ($manifest.worker_db_name -and -not $PSBoundParameters.ContainsKey('WorkerDbName')) { [string]$manifest.worker_db_name } else { $WorkerDbName }
$effectiveWorkerBaseDir = if ($manifest.worker_base_dir -and -not $PSBoundParameters.ContainsKey('WorkerBaseDir')) { [string]$manifest.worker_base_dir } else { $WorkerBaseDir }
$effectivePollInterval = if ($manifest.poll_interval -and -not $PSBoundParameters.ContainsKey('PollInterval')) { [int]$manifest.poll_interval } else { $PollInterval }

Ensure-Directory $effectiveInstallDir
$manifestPhpDownloadUrl = ''
if ($manifest.php_download_url) {
    $manifestPhpDownloadUrl = [string]$manifest.php_download_url
}
$resolvedPhpPath = Resolve-PhpPath -PreferredPhpPath $PhpPath -InstallRoot $effectiveInstallDir -ManifestPhpUrl $manifestPhpDownloadUrl
$package = Expand-RepoPackage -ZipUrl ([string]$manifest.package_url) -DestinationDir $effectiveInstallDir

$startScript = Join-Path $effectiveInstallDir 'start-agent.ps1'
$startScriptContent = @"
param(
    [Parameter(ValueFromRemainingArguments = \`$true)]
    [string[]]\`$ExtraArgs
)

\`$ErrorActionPreference = 'Stop'
\`$env:VW_BASE_DATA_DIR = '$effectiveWorkerBaseDir'
\`$agentArgs = @(
    '--server-url=$effectiveServerUrl',
    '--pairing-token=$PairingToken',
    '--worker-db-name=$effectiveWorkerDbName',
    '--worker-base-dir=$effectiveWorkerBaseDir',
    '--poll-interval=$effectivePollInterval'
)
if (\`$ExtraArgs) {
    \`$agentArgs += \`$ExtraArgs
}
& '$resolvedPhpPath' '$($package.agent_script)' @agentArgs
"@
Set-Content -LiteralPath $startScript -Value $startScriptContent -Encoding UTF8

if ($CreateScheduledTask) {
    $taskName = 'VideoWorkflowLocalAgent'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-ExecutionPolicy Bypass -File \`"$startScript\`""
    $trigger = New-ScheduledTaskTrigger -AtLogOn
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Force | Out-Null
}

Write-Host "Agent installed to $effectiveInstallDir"
Write-Host "PHP: $resolvedPhpPath"
Write-Host "Start command:"
Write-Host "powershell -ExecutionPolicy Bypass -File \`"$startScript\`""
`
}
