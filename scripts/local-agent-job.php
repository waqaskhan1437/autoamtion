<?php

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../config.php';

$jobId = isset($argv[1]) ? (int)$argv[1] : 0;
$claimToken = isset($argv[2]) ? (string)$argv[2] : '';
$automationId = isset($argv[3]) ? (int)$argv[3] : 0;
$serverBaseUrl = isset($argv[4]) ? rtrim((string)$argv[4], '/') : '';

if ($jobId <= 0 || $claimToken === '' || $automationId <= 0 || $serverBaseUrl === '') {
    fwrite(STDERR, "Usage: php scripts/local-agent-job.php <job_id> <claim_token> <automation_id> <server_base_url>\n");
    exit(1);
}

$reportUrl = $serverBaseUrl . '/api/agent-report.php';
$completeUrl = $serverBaseUrl . '/api/agent-complete.php';
$uploadUrl = $serverBaseUrl . '/api/agent-upload-output.php';

function agentNormalizeStats(array $stats): array
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

function agentPostJson(string $url, array $payload): void
{
    if (!function_exists('curl_init')) {
        return;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function agentUploadOutput(string $url, int $jobId, string $claimToken, string $filePath): ?string
{
    if (!function_exists('curl_init') || !is_file($filePath)) {
        return null;
    }

    $mime = 'application/octet-stream';
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (in_array($ext, ['mp4', 'm4v'], true)) {
        $mime = 'video/mp4';
    } elseif ($ext === 'mov') {
        $mime = 'video/quicktime';
    } elseif ($ext === 'webm') {
        $mime = 'video/webm';
    } elseif ($ext === 'avi') {
        $mime = 'video/x-msvideo';
    } elseif ($ext === 'mkv') {
        $mime = 'video/x-matroska';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'job_id' => $jobId,
            'claim_token' => $claimToken,
            'output_file' => new CURLFile($filePath, $mime, basename($filePath))
        ],
        CURLOPT_TIMEOUT => 120
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $json = json_decode((string)$body, true);
    if (!is_array($json) || empty($json['success'])) {
        return null;
    }

    return (string)($json['filename'] ?? basename($filePath));
}

function agentSendProgress(
    string $url,
    int $jobId,
    string $claimToken,
    string $status,
    string $eventStatus,
    string $step,
    string $message,
    int $progress,
    array $stats = [],
    array $outputs = []
): void {
    agentPostJson($url, [
        'job_id' => $jobId,
        'claim_token' => $claimToken,
        'payload' => [
            'status' => $status,
            'event_status' => $eventStatus,
            'step' => $step,
            'message' => $message,
            'progress' => max(0, min(100, $progress)),
            'stats' => $stats,
            'outputs' => array_values($outputs),
            'time' => date('H:i:s')
        ]
    ]);
}

function agentAppendOutputs(array $existing, array $incoming): array
{
    foreach ($incoming as $item) {
        $item = basename(trim((string)$item));
        if ($item === '') {
            continue;
        }
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v'], true)) {
            continue;
        }
        if ($item !== '' && !in_array($item, $existing, true)) {
            $existing[] = $item;
        }
    }
    return $existing;
}

function agentExtractOutputName(string $message): ?string
{
    if (preg_match('/Created:\s*([^\r\n]+?\.(mp4|mov|mkv|webm|avi|m4v))/i', $message, $m)) {
        return basename(trim((string)$m[1]));
    }
    if (preg_match('/Output:\s*([^\r\n]+?\.(mp4|mov|mkv|webm|avi|m4v))/i', $message, $m)) {
        return basename(trim((string)$m[1]));
    }
    return null;
}

function agentListOutputFiles(): array
{
    $files = [];
    if (!defined('OUTPUT_DIR') || !is_dir(OUTPUT_DIR)) {
        return $files;
    }

    $items = scandir((string)OUTPUT_DIR, SCANDIR_SORT_NONE);
    if (!is_array($items)) {
        return $files;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = rtrim((string)OUTPUT_DIR, '/\\') . DIRECTORY_SEPARATOR . $item;
        if (!is_file($path)) {
            continue;
        }

        $name = basename($item);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v'], true)) {
            continue;
        }

        $files[strtolower($name)] = [
            'name' => $name,
            'path' => $path,
            'mtime' => (int)@filemtime($path)
        ];
    }

    return $files;
}

function agentCollectUploadCandidates(array $knownOutputs, array $existingOutputs, int $jobStartedAt): array
{
    $candidates = [];
    $currentOutputs = agentListOutputFiles();

    foreach ($knownOutputs as $outputName) {
        $key = strtolower(basename((string)$outputName));
        if ($key !== '' && isset($currentOutputs[$key])) {
            $candidates[$key] = $currentOutputs[$key]['path'];
        }
    }

    foreach ($currentOutputs as $key => $info) {
        $isNew = !isset($existingOutputs[$key]);
        $isRecent = (int)($info['mtime'] ?? 0) >= max(0, $jobStartedAt - 5);
        if ($isNew || $isRecent) {
            $candidates[$key] = $info['path'];
        }
    }

    return array_values($candidates);
}

$php = PHP_BINARY;
$syncScript = realpath(__DIR__ . '/run-sync-cli.php');
if ($syncScript === false) {
    $msg = 'run-sync-cli.php not found.';
    fwrite(STDERR, $msg . "\n");
    agentSendProgress($completeUrl, $jobId, $claimToken, 'error', 'error', 'local_agent', $msg, 0);
    exit(1);
}

$lastStats = agentNormalizeStats([]);
$knownOutputs = [];
$lastProgress = 5;
$terminalSent = false;
$jobStartedAt = time();
$existingOutputs = agentListOutputFiles();

agentSendProgress($reportUrl, $jobId, $claimToken, 'processing', 'info', 'local_agent', 'Local agent started job.', $lastProgress, $lastStats, $knownOutputs);

$cmd = escapeshellarg($php) . ' ' . escapeshellarg($syncScript) . ' ' . $automationId;
$handle = popen($cmd . ' 2>&1', 'r');
if (!is_resource($handle)) {
    $msg = 'Unable to start local automation process.';
    fwrite(STDERR, $msg . "\n");
    agentSendProgress($completeUrl, $jobId, $claimToken, 'error', 'error', 'local_agent', $msg, 0, $lastStats, $knownOutputs);
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

    $lastStats = agentNormalizeStats(isset($event['stats']) && is_array($event['stats']) ? $event['stats'] : []);
    $lastProgress = max(0, min(100, (int)($event['progress'] ?? $lastProgress)));
    $knownOutputs = agentAppendOutputs($knownOutputs, isset($event['outputs']) && is_array($event['outputs']) ? $event['outputs'] : []);
    $extractedOutput = agentExtractOutputName((string)($event['message'] ?? ''));
    if ($extractedOutput !== null) {
        $knownOutputs = agentAppendOutputs($knownOutputs, [$extractedOutput]);
    }

    $payload = [
        'status' => !empty($event['done']) ? (!empty($event['success']) ? 'completed' : 'error') : 'processing',
        'event_status' => (string)($event['status'] ?? 'info'),
        'step' => (string)($event['step'] ?? 'local_agent'),
        'message' => (string)($event['message'] ?? ''),
        'progress' => $lastProgress,
        'stats' => $lastStats,
        'outputs' => $knownOutputs,
        'time' => date('H:i:s')
    ];

    if (!empty($event['done'])) {
        $uploadedOutputs = [];
        foreach (agentCollectUploadCandidates($knownOutputs, $existingOutputs, $jobStartedAt) as $localPath) {
            $uploadedName = agentUploadOutput($uploadUrl, $jobId, $claimToken, $localPath);
            if ($uploadedName !== null) {
                $uploadedOutputs[] = $uploadedName;
            }
        }
        if (!empty($uploadedOutputs)) {
            $knownOutputs = $uploadedOutputs;
            $payload['outputs'] = $knownOutputs;
        }

        $terminalSent = true;
        agentPostJson($completeUrl, [
            'job_id' => $jobId,
            'claim_token' => $claimToken,
            'payload' => $payload
        ]);
    } else {
        agentPostJson($reportUrl, [
            'job_id' => $jobId,
            'claim_token' => $claimToken,
            'payload' => $payload
        ]);
    }
}

$exitCode = pclose($handle);
if (!$terminalSent) {
    $uploadedOutputs = [];
    foreach (agentCollectUploadCandidates($knownOutputs, $existingOutputs, $jobStartedAt) as $localPath) {
        $uploadedName = agentUploadOutput($uploadUrl, $jobId, $claimToken, $localPath);
        if ($uploadedName !== null) {
            $uploadedOutputs[] = $uploadedName;
        }
    }
    if (!empty($uploadedOutputs)) {
        $knownOutputs = $uploadedOutputs;
    }

    $finalStatus = ($exitCode === 0) ? 'completed' : 'error';
    $finalMessage = ($exitCode === 0)
        ? 'Local agent finished processing.'
        : ('Local agent process exited with code ' . $exitCode . '.');
    agentSendProgress(
        $completeUrl,
        $jobId,
        $claimToken,
        $finalStatus,
        $finalStatus === 'completed' ? 'success' : 'error',
        'local_agent',
        $finalMessage,
        $finalStatus === 'completed' ? 100 : max(0, $lastProgress),
        $lastStats,
        $knownOutputs
    );
}

exit($exitCode);
