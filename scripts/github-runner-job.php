<?php
/**
 * GitHub Actions entry script to run one automation by ID.
 * Streams run-sync SSE events, mirrors them into callback payloads,
 * and writes machine-readable markers into workflow logs.
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
$sequence = 0;
$knownOutputs = [];
$terminalSuccess = null;

function normalizeStats(array $stats): array
{
    $defaults = [
        'fetched' => 0,
        'downloaded' => 0,
        'processed' => 0,
        'scheduled' => 0,
        'posted' => 0,
        'errors' => 0
    ];

    foreach ($defaults as $k => $v) {
        $defaults[$k] = isset($stats[$k]) ? (int)$stats[$k] : $v;
    }

    return $defaults;
}

function emitProgressMarker(array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    echo 'VW_PROGRESS_B64:' . base64_encode($json) . PHP_EOL;
}

function sendRunnerCallback(
    string $callbackUrl,
    string $callbackSecret,
    int $automationId,
    string $status,
    string $message,
    int $progress,
    string $runUrl = '',
    string $step = 'github_runner',
    array $stats = [],
    array $outputs = [],
    string $eventStatus = 'info',
    int $sequence = 0
): void {
    if ($callbackUrl === '' || !function_exists('curl_init')) {
        return;
    }

    $payload = [
        'automation_id' => $automationId,
        'status' => $status,
        'step' => $step,
        'message' => $message,
        'progress' => $progress,
        'run_url' => $runUrl,
        'stats' => $stats,
        'outputs' => $outputs,
        'event_status' => $eventStatus,
        'sequence' => $sequence,
        'secret' => $callbackSecret
    ];

    $ch = curl_init($callbackUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function extractOutputName(string $message): ?string
{
    if (preg_match('/Created:\s*([^\r\n]+?\.(mp4|mov|mkv|webm|avi))/i', $message, $m)) {
        return basename(trim((string)$m[1]));
    }
    if (preg_match('/Output:\s*([^\r\n]+?\.(mp4|mov|mkv|webm|avi))/i', $message, $m)) {
        return basename(trim((string)$m[1]));
    }
    return null;
}

function appendOutput(array $outputs, ?string $name): array
{
    if ($name === null || $name === '') {
        return $outputs;
    }
    if (!in_array($name, $outputs, true)) {
        $outputs[] = $name;
    }
    return $outputs;
}

function publishEvent(
    string $callbackUrl,
    string $callbackSecret,
    int $automationId,
    string $runStatus,
    string $eventStatus,
    string $step,
    string $message,
    int $progress,
    string $runUrl,
    array $stats,
    array $outputs,
    int &$sequence
): void {
    $sequence++;
    $progress = max(0, min(100, $progress));
    $marker = [
        'automation_id' => $automationId,
        'status' => $runStatus,
        'event_status' => $eventStatus,
        'step' => $step,
        'message' => $message,
        'progress' => $progress,
        'stats' => $stats,
        'outputs' => $outputs,
        'run_url' => $runUrl,
        'sequence' => $sequence,
        'time' => date('H:i:s'),
        'time_unix' => time()
    ];

    emitProgressMarker($marker);
    sendRunnerCallback(
        $callbackUrl,
        $callbackSecret,
        $automationId,
        $runStatus,
        $message,
        $progress,
        $runUrl,
        $step,
        $stats,
        $outputs,
        $eventStatus,
        $sequence
    );
}

echo "Starting GitHub runner job for automation #{$automationId}\n";
if ($runUrl !== '') {
    echo "Run URL: {$runUrl}\n";
}

$lastStats = normalizeStats([]);
$lastProgress = 10;
$terminalSent = false;

publishEvent(
    $callbackUrl,
    $callbackSecret,
    $automationId,
    'processing',
    'info',
    'github_runner',
    'GitHub runner started processing.',
    $lastProgress,
    $runUrl,
    $lastStats,
    $knownOutputs,
    $sequence
);

$php = PHP_BINARY;
$syncScript = realpath(__DIR__ . '/run-sync-cli.php');
if ($syncScript === false) {
    $msg = 'run-sync-cli.php not found on runner.';
    fwrite(STDERR, $msg . "\n");
    publishEvent(
        $callbackUrl,
        $callbackSecret,
        $automationId,
        'error',
        'error',
        'github_runner',
        $msg,
        0,
        $runUrl,
        $lastStats,
        $knownOutputs,
        $sequence
    );
    exit(1);
}

$cmd = escapeshellarg($php) . ' ' . escapeshellarg($syncScript) . ' ' . (int)$automationId;
$handle = popen($cmd . ' 2>&1', 'r');
if (!is_resource($handle)) {
    $msg = 'Unable to start automation process.';
    fwrite(STDERR, $msg . "\n");
    publishEvent(
        $callbackUrl,
        $callbackSecret,
        $automationId,
        'error',
        'error',
        'github_runner',
        $msg,
        0,
        $runUrl,
        $lastStats,
        $knownOutputs,
        $sequence
    );
    exit(1);
}

while (!feof($handle)) {
    $line = fgets($handle);
    if ($line === false) {
        usleep(30000);
        continue;
    }

    $trim = rtrim($line, "\r\n");
    if ($trim !== '') {
        echo $trim . PHP_EOL;
    }

    if (strpos($trim, 'data: ') !== 0) {
        continue;
    }

    $json = trim(substr($trim, 6));
    if ($json === '') {
        continue;
    }

    $event = json_decode($json, true);
    if (!is_array($event)) {
        continue;
    }

    if (isset($event['stats']) && is_array($event['stats'])) {
        $lastStats = normalizeStats($event['stats']);
    }

    if (isset($event['progress'])) {
        $lastProgress = (int)$event['progress'];
    }

    $message = trim((string)($event['message'] ?? 'Runner update received.'));
    $step = trim((string)($event['step'] ?? 'github_runner'));
    $eventStatus = strtolower(trim((string)($event['status'] ?? 'info')));
    if ($eventStatus === '') {
        $eventStatus = 'info';
    }

    $knownOutputs = appendOutput($knownOutputs, extractOutputName($message));

    if (!empty($event['done'])) {
        $ok = !empty($event['success']);
        $terminalSuccess = $ok;
        publishEvent(
            $callbackUrl,
            $callbackSecret,
            $automationId,
            $ok ? 'completed' : 'error',
            $ok ? 'success' : 'error',
            'complete',
            $message,
            $ok ? 100 : max(0, min(100, $lastProgress)),
            $runUrl,
            $lastStats,
            $knownOutputs,
            $sequence
        );
        $terminalSent = true;
        continue;
    }

    publishEvent(
        $callbackUrl,
        $callbackSecret,
        $automationId,
        'processing',
        $eventStatus,
        $step,
        $message,
        $lastProgress,
        $runUrl,
        $lastStats,
        $knownOutputs,
        $sequence
    );
}

$exitCode = pclose($handle);
if ($exitCode === -1) {
    $exitCode = 1;
}
if ($terminalSent && $terminalSuccess === false) {
    $exitCode = 1;
}

if ($exitCode === 0) {
    if (!$terminalSent) {
        publishEvent(
            $callbackUrl,
            $callbackSecret,
            $automationId,
            'completed',
            'success',
            'complete',
            'GitHub runner completed successfully.',
            100,
            $runUrl,
            $lastStats,
            $knownOutputs,
            $sequence
        );
    }
    echo "Automation completed successfully.\n";
    exit(0);
}

if (!$terminalSent) {
    publishEvent(
        $callbackUrl,
        $callbackSecret,
        $automationId,
        'error',
        'error',
        'github_runner',
        "GitHub runner job failed (exit {$exitCode}).",
        max(0, min(100, $lastProgress)),
        $runUrl,
        $lastStats,
        $knownOutputs,
        $sequence
    );
}
fwrite(STDERR, "Automation failed with exit code {$exitCode}.\n");
exit($exitCode);
