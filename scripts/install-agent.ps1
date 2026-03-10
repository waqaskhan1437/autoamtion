param(
    [string]$RepoZipUrl = "",
    [string]$RepoZipPath = "",
    [Parameter(Mandatory = $true)][string]$ServerUrl,
    [Parameter(Mandatory = $true)][string]$PairingToken,
    [string]$InstallDir = "C:\VideoWorkflowAgent",
    [string]$PhpPath = "C:\xampp\php\php.exe",
    [string]$WorkerDbName = "video_workflow_agent",
    [string]$WorkerBaseDir = "C:\VideoWorkflowAgentData",
    [int]$PollInterval = 10,
    [switch]$CreateScheduledTask
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path $PhpPath)) {
    throw "PHP CLI not found at $PhpPath"
}

if ([string]::IsNullOrWhiteSpace($RepoZipUrl) -and [string]::IsNullOrWhiteSpace($RepoZipPath)) {
    throw "Pass either -RepoZipUrl or -RepoZipPath"
}

$installRoot = Resolve-Path -LiteralPath (Split-Path -Path $InstallDir -Parent) -ErrorAction SilentlyContinue
if (-not $installRoot) {
    $parent = Split-Path -Path $InstallDir -Parent
    if (-not (Test-Path $parent)) {
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
    }
}

New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null
$zipPath = Join-Path $InstallDir "agent-package.zip"
$appDir = Join-Path $InstallDir "app"

if (Test-Path $appDir) {
    Remove-Item -Recurse -Force $appDir
}
New-Item -ItemType Directory -Path $appDir -Force | Out-Null

if (-not [string]::IsNullOrWhiteSpace($RepoZipUrl)) {
    Invoke-WebRequest -Uri $RepoZipUrl -OutFile $zipPath
} else {
    Copy-Item -Path $RepoZipPath -Destination $zipPath -Force
}

Expand-Archive -Path $zipPath -DestinationPath $appDir -Force

$automationFile = Join-Path $appDir "automation.php"
if (-not (Test-Path $automationFile)) {
    $nestedRoot = Get-ChildItem -Path $appDir -Directory | Where-Object { Test-Path (Join-Path $_.FullName "automation.php") } | Select-Object -First 1
    if ($nestedRoot) {
        Get-ChildItem -Path $nestedRoot.FullName -Force | ForEach-Object {
            Move-Item -Path $_.FullName -Destination $appDir -Force
        }
        Remove-Item -Recurse -Force $nestedRoot.FullName
    }
}

$agentScript = Join-Path $appDir "scripts\local-agent.php"
if (-not (Test-Path $agentScript)) {
    throw "local-agent.php not found after extraction"
}

$startScript = Join-Path $InstallDir "start-agent.ps1"
$startScriptContent = @"
param(
    [Parameter(ValueFromRemainingArguments = `$true)]
    [string[]]`$ExtraArgs
)

`$ErrorActionPreference = 'Stop'
`$env:VW_BASE_DATA_DIR = '$WorkerBaseDir'
`$agentArgs = @(
    '--server-url=$ServerUrl',
    '--pairing-token=$PairingToken',
    '--worker-db-name=$WorkerDbName',
    '--worker-base-dir=$WorkerBaseDir',
    '--poll-interval=$PollInterval'
)
if (`$ExtraArgs) {
    `$agentArgs += `$ExtraArgs
}
& '$PhpPath' '$agentScript' @agentArgs
"@
Set-Content -Path $startScript -Value $startScriptContent -Encoding UTF8

if ($CreateScheduledTask) {
    $taskName = "VideoWorkflowLocalAgent"
    $action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-ExecutionPolicy Bypass -File `"$startScript`""
    $trigger = New-ScheduledTaskTrigger -AtLogOn
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Force | Out-Null
}

Write-Host "Agent files installed to $InstallDir"
Write-Host "Start command:"
Write-Host "powershell -ExecutionPolicy Bypass -File `"$startScript`""
