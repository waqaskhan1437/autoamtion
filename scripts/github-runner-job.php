<?php
/**
 * GitHub Actions entry script to run one automation by ID.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must run in CLI mode.\n");
    exit(1);
}

$automationId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($automationId <= 0) {
    fwrite(STDERR, "Usage: php scripts/github-runner-job.php <automation_id>\n");
    exit(1);
}

$callbackUrl = trim((string)(getenv('RUNNER_CALLBACK_URL') ?: ''));
$callbackSecret = trim((string)(getenv('RUNNER_CALLBACK_SECRET') ?: ''));
$repo = getenv('GITHUB_REPOSITORY') ?: '';
$runId = getenv('GITHUB_RUN_ID') ?: '';
$serverUrl = getenv('GITHUB_SERVER_URL') ?: 'https://github.com';
$runUrl = ($repo !== '' && $runId !== '') ? "{$serverUrl}/{$repo}/actions/runs/{$runId}" : '';

function sendRunnerCallback(string $callbackUrl, string $callbackSecret, int $automationId, string $status, string $message, int $progress, string $runUrl = ''): void
{
    if ($callbackUrl === '' || !function_exists('curl_init')) {
        return;
    }

    $payload = [
        'automation_id' => $automationId,
        'status' => $status,
        'step' => 'github_runner',
        'message' => $message,
        'progress' => $progress,
        'run_url' => $runUrl,
        'secret' => $callbackSecret
    ];

    $ch = curl_init($callbackUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20
    ]);
    curl_exec($ch);
    curl_close($ch);
}

echo "Starting GitHub runner job for automation #{$automationId}\n";
if ($runUrl !== '') {
    echo "Run URL: {$runUrl}\n";
}

sendRunnerCallback($callbackUrl, $callbackSecret, $automationId, 'processing', 'GitHub runner started processing.', 10, $runUrl);

$php = PHP_BINARY;
$script = realpath(__DIR__ . '/../run-background.php');
if ($script === false) {
    fwrite(STDERR, "run-background.php not found.\n");
    sendRunnerCallback($callbackUrl, $callbackSecret, $automationId, 'error', 'run-background.php not found on runner.', 0, $runUrl);
    exit(1);
}

$cmd = '"' . $php . '" "' . $script . '" ' . (int)$automationId;
passthru($cmd, $exitCode);

if ($exitCode === 0) {
    sendRunnerCallback($callbackUrl, $callbackSecret, $automationId, 'completed', 'GitHub runner completed successfully.', 100, $runUrl);
    echo "Automation completed successfully.\n";
    exit(0);
}

sendRunnerCallback($callbackUrl, $callbackSecret, $automationId, 'error', 'GitHub runner job failed.', 0, $runUrl);
fwrite(STDERR, "Automation failed with exit code {$exitCode}.\n");
exit($exitCode);

