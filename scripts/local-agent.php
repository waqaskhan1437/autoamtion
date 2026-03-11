<?php

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', [
    'server-url:',
    'pairing-token::',
    'agent-name::',
    'machine-name::',
    'worker-db-name::',
    'worker-base-dir::',
    'poll-interval::',
    'once',
    'register-only'
]);

$baseDataDir = getenv('VW_BASE_DATA_DIR');
if (!is_string($baseDataDir) || trim($baseDataDir) === '') {
    $baseDataDir = PHP_OS_FAMILY === 'Windows'
        ? 'C:/VideoWorkflow'
        : (rtrim((string)(getenv('HOME') ?: sys_get_temp_dir()), '/\\') . '/VideoWorkflow');
}
$runtimeDir = rtrim($baseDataDir, '/\\') . DIRECTORY_SEPARATOR . 'runtime';
if (!is_dir($runtimeDir)) {
    @mkdir($runtimeDir, 0777, true);
}
$configPath = $runtimeDir . DIRECTORY_SEPARATOR . 'local-agent.json';

function agentCliLoadConfig(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $json = file_get_contents($path);
    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : [];
}

function agentCliSaveConfig(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function agentCliApplyCurlResolution($curlHandle, string $url): void
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return;
    }

    $host = (string)$parts['host'];
    $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
    $port = isset($parts['port'])
        ? (int)$parts['port']
        : ($scheme === 'http' ? 80 : 443);

    $ipv4 = gethostbyname($host);
    if (!is_string($ipv4) || $ipv4 === '' || $ipv4 === $host || !filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return;
    }

    curl_setopt($curlHandle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($curlHandle, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $ipv4]);
}

function agentCliPostJson(string $url, array $payload): array
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL extension is required'];
    }

    $ch = curl_init($url);
    agentCliApplyCurlResolution($ch, $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['success' => false, 'error' => $error !== '' ? $error : 'HTTP request failed'];
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        return ['success' => false, 'error' => 'Invalid JSON response', 'http_code' => $httpCode, 'body' => $body];
    }

    $json['http_code'] = $httpCode;
    return $json;
}

function agentCliExec(string $command, ?string $cwd = null): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = proc_open($command, $descriptor, $pipes, $cwd, null);
    if (!is_resource($process)) {
        return ['success' => false, 'error' => 'Unable to start process'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'success' => $exitCode === 0,
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr
    ];
}

$stored = agentCliLoadConfig($configPath);
$serverUrl = rtrim((string)($options['server-url'] ?? ($stored['server_url'] ?? '')), '/');
if ($serverUrl === '') {
    fwrite(STDERR, "Missing --server-url.\n");
    exit(1);
}

$pollInterval = max(3, (int)($options['poll-interval'] ?? ($stored['poll_interval'] ?? 10)));
$agentName = trim((string)($options['agent-name'] ?? ($stored['display_name'] ?? gethostname())));
$machineName = trim((string)($options['machine-name'] ?? gethostname()));
$pairingToken = trim((string)($options['pairing-token'] ?? ''));
$workerDbName = trim((string)($options['worker-db-name'] ?? ($stored['worker_db_name'] ?? 'video_workflow_agent')));
$workerBaseDir = trim((string)($options['worker-base-dir'] ?? ($stored['worker_base_dir'] ?? '')));
if ($workerBaseDir === '') {
    $workerBaseDir = PHP_OS_FAMILY === 'Windows' ? 'C:/VideoWorkflowAgentData' : (rtrim((string)(getenv('HOME') ?: sys_get_temp_dir()), '/\\') . '/VideoWorkflowAgentData');
}

$agentKey = trim((string)($stored['agent_key'] ?? ''));
$agentSecret = trim((string)($stored['agent_secret'] ?? ''));

if (!empty($stored) && (($stored['worker_db_name'] ?? '') !== $workerDbName || ($stored['worker_base_dir'] ?? '') !== $workerBaseDir || ($stored['server_url'] ?? '') !== $serverUrl || (int)($stored['poll_interval'] ?? 0) !== $pollInterval)) {
    $stored['server_url'] = $serverUrl;
    $stored['poll_interval'] = $pollInterval;
    $stored['worker_db_name'] = $workerDbName;
    $stored['worker_base_dir'] = $workerBaseDir;
    agentCliSaveConfig($configPath, $stored);
}

if ($agentKey === '' || $agentSecret === '') {
    if ($pairingToken === '') {
        fwrite(STDERR, "Agent is not paired. Pass --pairing-token the first time.\n");
        exit(1);
    }

    $register = agentCliPostJson($serverUrl . '/api/agent-register.php', [
        'pairing_token' => $pairingToken,
        'display_name' => $agentName,
        'machine_name' => $machineName,
        'host_name' => gethostname(),
        'platform' => PHP_OS_FAMILY,
        'agent_version' => '1.0.0',
        'capabilities' => [
            'runtime_bootstrap' => true,
            'ffmpeg_auto_install' => true,
            'php_binary' => PHP_BINARY
        ]
    ]);

    if (empty($register['success'])) {
        fwrite(STDERR, "Pairing failed: " . ($register['error'] ?? 'Unknown error') . "\n");
        exit(1);
    }

    $agentKey = (string)$register['agent_key'];
    $agentSecret = (string)$register['agent_secret'];
    $stored = [
        'server_url' => $serverUrl,
        'agent_key' => $agentKey,
        'agent_secret' => $agentSecret,
        'display_name' => $agentName,
        'machine_name' => $machineName,
        'worker_db_name' => $workerDbName,
        'worker_base_dir' => $workerBaseDir,
        'poll_interval' => $pollInterval,
        'paired_at' => date('c')
    ];
    agentCliSaveConfig($configPath, $stored);
    fwrite(STDOUT, "Agent paired successfully.\n");
}

if (isset($options['register-only'])) {
    fwrite(STDOUT, "Pairing/config complete. Config: {$configPath}\n");
    exit(0);
}

$php = PHP_BINARY;
$bootstrapScript = realpath(__DIR__ . '/bootstrap-runner-db.php');
$jobScript = realpath(__DIR__ . '/local-agent-job.php');
if ($bootstrapScript === false || $jobScript === false) {
    fwrite(STDERR, "Required worker scripts are missing.\n");
    exit(1);
}

$runOnce = array_key_exists('once', $options);
fwrite(STDOUT, "Local agent started. Poll interval: {$pollInterval}s\n");

do {
    $poll = agentCliPostJson($serverUrl . '/api/agent-poll.php', [
        'agent_key' => $agentKey,
        'agent_secret' => $agentSecret
    ]);

    if (empty($poll['success'])) {
        fwrite(STDERR, '[' . date('H:i:s') . '] Poll failed: ' . ($poll['error'] ?? 'Unknown error') . "\n");
        sleep($pollInterval);
        continue;
    }

    $job = $poll['job'] ?? null;
    if (!is_array($job) || empty($job['id'])) {
        if ($runOnce) {
            fwrite(STDOUT, "No queued job.\n");
            break;
        }
        sleep($pollInterval);
        continue;
    }

    $jobId = (int)$job['id'];
    $automationId = (int)($job['automation_id'] ?? 0);
    $claimToken = (string)($job['claim_token'] ?? '');
    $payloadB64 = (string)($job['payload_gzip_b64'] ?? '');

    if ($automationId <= 0 || $claimToken === '' || $payloadB64 === '') {
        fwrite(STDERR, "Invalid job payload received.\n");
        sleep($pollInterval);
        continue;
    }

    $payloadFile = $runtimeDir . DIRECTORY_SEPARATOR . 'agent-job-' . $jobId . '.json';
    $decoded = base64_decode($payloadB64, true);
    if ($decoded === false) {
        fwrite(STDERR, "Unable to decode job payload.\n");
        sleep($pollInterval);
        continue;
    }

    $jsonPayload = gzdecode($decoded);
    if ($jsonPayload === false) {
        fwrite(STDERR, "Unable to unzip job payload.\n");
        sleep($pollInterval);
        continue;
    }

    file_put_contents($payloadFile, $jsonPayload);

    $seedCmd = escapeshellarg($php) . ' ' . escapeshellarg($bootstrapScript) . ' ' . escapeshellarg($payloadFile);
    $previousDbName = getenv('VW_DB_NAME');
    $previousBaseDir = getenv('VW_BASE_DATA_DIR');
    putenv('VW_DB_NAME=' . $workerDbName);
    putenv('VW_BASE_DATA_DIR=' . $workerBaseDir);
    $initCmd = escapeshellarg($php) . ' -r ' . escapeshellarg("require 'config.php'; echo 'worker db ready', PHP_EOL;");
    $init = agentCliExec($initCmd, realpath(__DIR__ . '/..') ?: null);
    if (!$init['success']) {
        fwrite(STDERR, "Worker schema init failed for job {$jobId}: " . trim($init['stderr'] ?: $init['stdout']) . "\n");
        agentCliPostJson($serverUrl . '/api/agent-complete.php', [
            'job_id' => $jobId,
            'claim_token' => $claimToken,
            'payload' => [
                'status' => 'error',
                'event_status' => 'error',
                'step' => 'local_agent',
                'message' => 'Worker schema init failed: ' . trim($init['stderr'] ?: $init['stdout']),
                'progress' => 0,
                'stats' => ['fetched' => 0, 'downloaded' => 0, 'processed' => 0, 'scheduled' => 0, 'posted' => 0],
                'outputs' => [],
                'time' => date('H:i:s')
            ]
        ]);
        putenv($previousDbName === false ? 'VW_DB_NAME' : ('VW_DB_NAME=' . $previousDbName));
        putenv($previousBaseDir === false ? 'VW_BASE_DATA_DIR' : ('VW_BASE_DATA_DIR=' . $previousBaseDir));
        if ($runOnce) {
            break;
        }
        sleep($pollInterval);
        continue;
    }
    $seed = agentCliExec($seedCmd, realpath(__DIR__ . '/..') ?: null);
    if (!$seed['success']) {
        fwrite(STDERR, "DB bootstrap failed for job {$jobId}: " . trim($seed['stderr'] ?: $seed['stdout']) . "\n");
        agentCliPostJson($serverUrl . '/api/agent-complete.php', [
            'job_id' => $jobId,
            'claim_token' => $claimToken,
            'payload' => [
                'status' => 'error',
                'event_status' => 'error',
                'step' => 'local_agent',
                'message' => 'Local DB bootstrap failed: ' . trim($seed['stderr'] ?: $seed['stdout']),
                'progress' => 0,
                'stats' => ['fetched' => 0, 'downloaded' => 0, 'processed' => 0, 'scheduled' => 0, 'posted' => 0],
                'outputs' => [],
                'time' => date('H:i:s')
            ]
        ]);
        if ($runOnce) {
            putenv($previousDbName === false ? 'VW_DB_NAME' : ('VW_DB_NAME=' . $previousDbName));
            putenv($previousBaseDir === false ? 'VW_BASE_DATA_DIR' : ('VW_BASE_DATA_DIR=' . $previousBaseDir));
            break;
        }
        putenv($previousDbName === false ? 'VW_DB_NAME' : ('VW_DB_NAME=' . $previousDbName));
        putenv($previousBaseDir === false ? 'VW_BASE_DATA_DIR' : ('VW_BASE_DATA_DIR=' . $previousBaseDir));
        sleep($pollInterval);
        continue;
    }

    $jobCmd = escapeshellarg($php) . ' ' . escapeshellarg($jobScript) . ' ' . $jobId . ' ' . escapeshellarg($claimToken) . ' ' . $automationId . ' ' . escapeshellarg($serverUrl);
    $jobExec = agentCliExec($jobCmd, realpath(__DIR__ . '/..') ?: null);
    putenv($previousDbName === false ? 'VW_DB_NAME' : ('VW_DB_NAME=' . $previousDbName));
    putenv($previousBaseDir === false ? 'VW_BASE_DATA_DIR' : ('VW_BASE_DATA_DIR=' . $previousBaseDir));
    fwrite(STDOUT, '[' . date('H:i:s') . '] Job ' . $jobId . ' exit code: ' . (int)$jobExec['exit_code'] . "\n");

    @unlink($payloadFile);

    if ($runOnce) {
        break;
    }

    sleep(2);
} while (true);

exit(0);
