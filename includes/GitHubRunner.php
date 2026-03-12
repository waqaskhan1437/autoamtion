<?php

require_once __DIR__ . '/AutomationSnapshotBuilder.php';

class GitHubRunner
{
    private PDO $pdo;
    private array $settings = [];
    private AutomationSnapshotBuilder $snapshotBuilder;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->settings = $this->loadSettings();
        $this->snapshotBuilder = new AutomationSnapshotBuilder($pdo);
    }

    public function testConnection(): array
    {
        $config = $this->getConfig();
        if (!$config['success']) {
            return $config;
        }

        $workflow = rawurlencode($config['workflow']);
        $url = "https://api.github.com/repos/{$config['owner']}/{$config['repo']}/actions/workflows/{$workflow}";
        $res = $this->apiRequest($url, $config['token']);

        if (!$res['success']) {
            return $res;
        }

        if ($res['status'] !== 200) {
            return [
                'success' => false,
                'error' => "GitHub API error ({$res['status']})",
                'details' => $res['body']
            ];
        }

        return [
            'success' => true,
            'message' => 'GitHub workflow is reachable.',
            'workflow' => $res['json']['name'] ?? $config['workflow']
        ];
    }

    public function dispatchAutomation(int $automationId, string $triggerSource = 'manual'): array
    {
        $config = $this->getConfig();
        if (!$config['success']) {
            return $config;
        }

        $snapshot = $this->buildAutomationSnapshot($automationId);
        if (!$snapshot['success']) {
            return $snapshot;
        }

        $snapshotJson = json_encode($snapshot['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($snapshotJson === false) {
            return ['success' => false, 'error' => 'Failed to encode automation payload.'];
        }

        $gzip = gzencode($snapshotJson, 9);
        if ($gzip === false) {
            return ['success' => false, 'error' => 'Failed to compress automation payload.'];
        }

        $payloadB64 = base64_encode($gzip);
        if (strlen($payloadB64) > 60000) {
            return ['success' => false, 'error' => 'Automation payload too large for GitHub dispatch.'];
        }

        $dispatchUrl = "https://api.github.com/repos/{$config['owner']}/{$config['repo']}/actions/workflows/" . rawurlencode($config['workflow']) . "/dispatches";
        $inputs = [
            'automation_id' => (string)$automationId,
            'payload_gzip_b64' => $payloadB64
        ];
        foreach ($this->decodeExtraInputs($config['inputs_json']) as $key => $value) {
            if (!array_key_exists($key, $inputs)) {
                $inputs[$key] = $value;
            }
        }
        $payload = [
            'ref' => $config['ref'],
            'inputs' => $inputs
        ];

        $dispatchStartedAt = time();
        $res = $this->apiRequest($dispatchUrl, $config['token'], 'POST', $payload);
        if (!$res['success']) {
            return $res;
        }

        if (!in_array($res['status'], [200, 201, 202, 204], true)) {
            return [
                'success' => false,
                'error' => "Dispatch failed ({$res['status']})",
                'details' => $res['body']
            ];
        }

        $workflowUrl = "https://github.com/{$config['owner']}/{$config['repo']}/actions/workflows/{$config['workflow']}";
        // Dispatch API does not return a run id immediately; poll briefly to capture fresh run metadata.
        $runMeta = [];
        for ($i = 0; $i < 5; $i++) {
            $runMeta = $this->findLatestRun(
                $config['owner'],
                $config['repo'],
                $config['workflow'],
                $config['token'],
                $automationId,
                $dispatchStartedAt - 2,
                'workflow_dispatch'
            );
            if (!empty($runMeta['run_id'])) {
                break;
            }
            usleep(1500000);
        }

        return [
            'success' => true,
            'message' => 'GitHub workflow dispatched.',
            'workflow_url' => $workflowUrl,
            'run_id' => $runMeta['run_id'] ?? null,
            'run_url' => $runMeta['run_url'] ?? $workflowUrl
        ];
    }

    private function buildAutomationSnapshot(int $automationId): array
    {
        return $this->snapshotBuilder->build($automationId);
    }

    private function loadSettings(): array
    {
        $keys = [
            'github_runner_enabled',
            'github_runner_token',
            'github_runner_owner',
            'github_runner_repo',
            'github_runner_workflow',
            'github_runner_ref',
            'github_runner_inputs_json',
            'github_runner_callback_secret',
            'panel_public_base_url',
            'ytdlp_cookies_file'
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return is_array($rows) ? $rows : [];
    }

    private function getConfig(): array
    {
        $enabled = trim((string)($this->settings['github_runner_enabled'] ?? '0')) === '1';
        if (!$enabled) {
            return [
                'success' => false,
                'error' => 'GitHub runner mode is disabled in Settings.'
            ];
        }

        $token = trim((string)($this->settings['github_runner_token'] ?? ''));
        $owner = trim((string)($this->settings['github_runner_owner'] ?? ''));
        $repo = trim((string)($this->settings['github_runner_repo'] ?? ''));
        $workflow = $this->normalizeWorkflowName((string)($this->settings['github_runner_workflow'] ?? 'automation-runner.yml'));
        $ref = trim((string)($this->settings['github_runner_ref'] ?? 'main'));
        $inputsJson = (string)($this->settings['github_runner_inputs_json'] ?? '');

        $missing = [];
        if ($token === '') {
            $missing[] = 'token';
        }
        if ($owner === '') {
            $missing[] = 'owner';
        }
        if ($repo === '') {
            $missing[] = 'repo';
        }
        if ($workflow === '') {
            $missing[] = 'workflow';
        }
        if ($ref === '') {
            $missing[] = 'ref';
        }

        if (!empty($missing)) {
            return [
                'success' => false,
                'error' => 'GitHub runner settings are incomplete: ' . implode(', ', $missing)
            ];
        }

        return [
            'success' => true,
            'token' => $token,
            'owner' => $owner,
            'repo' => $repo,
            'workflow' => $workflow,
            'ref' => $ref,
            'inputs_json' => $inputsJson
        ];
    }

    private function normalizeWorkflowName(string $workflow): string
    {
        $workflow = trim($workflow);
        if ($workflow === '' || strcasecmp($workflow, 'automation-runner-self-hosted.yml') === 0) {
            return 'automation-runner.yml';
        }

        return $workflow;
    }

    private function decodeExtraInputs(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $flat = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && (is_scalar($v) || $v === null)) {
                $flat[$k] = $v === null ? '' : (string)$v;
            }
        }

        return $flat;
    }

    private function findLatestRun(
        string $owner,
        string $repo,
        string $workflow,
        string $token,
        int $automationId,
        ?int $minCreatedAt = null,
        ?string $eventType = null
    ): array
    {
        $encodedWorkflow = rawurlencode($workflow);
        $url = "https://api.github.com/repos/{$owner}/{$repo}/actions/workflows/{$encodedWorkflow}/runs?per_page=10";
        if ($eventType !== null && $eventType !== '') {
            $url .= '&event=' . rawurlencode($eventType);
        }
        $res = $this->apiRequest($url, $token);
        if (!$res['success'] || $res['status'] !== 200 || !is_array($res['json'])) {
            return [];
        }

        $runs = $res['json']['workflow_runs'] ?? [];
        if (!is_array($runs)) {
            return [];
        }

        $recentRuns = [];
        foreach ($runs as $run) {
            if (!is_array($run)) {
                continue;
            }

            $createdAtTs = isset($run['created_at']) ? strtotime((string)$run['created_at']) : false;
            if ($minCreatedAt !== null && $createdAtTs !== false && $createdAtTs < $minCreatedAt) {
                continue;
            }

            $recentRuns[] = $run;
        }

        foreach ($recentRuns as $run) {
            $runId = $run['id'] ?? null;
            $runUrl = $run['html_url'] ?? null;
            $displayTitle = (string)($run['display_title'] ?? '');
            $name = (string)($run['name'] ?? '');

            if (stripos($displayTitle, (string)$automationId) !== false || stripos($name, (string)$automationId) !== false) {
                return ['run_id' => $runId, 'run_url' => $runUrl];
            }
        }

        if (!empty($recentRuns[0]) && is_array($recentRuns[0])) {
            return [
                'run_id' => $recentRuns[0]['id'] ?? null,
                'run_url' => $recentRuns[0]['html_url'] ?? null
            ];
        }

        return [];
    }

    public function syncYtdlpCookiesSecret(?string $sourceFile = null): array
    {
        $config = $this->getConfig();
        if (!$config['success']) {
            return $config;
        }

        $sourceFile = $this->resolveLocalCookiesFile($sourceFile);
        if ($sourceFile === '') {
            return [
                'success' => false,
                'error' => 'No local cookies.txt file found. Save Local yt-dlp Cookies File or place cookies.txt in project root.'
            ];
        }

        $scriptPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'sync-github-secret.mjs';
        if (!is_file($scriptPath)) {
            return [
                'success' => false,
                'error' => 'GitHub secret sync helper script is missing.'
            ];
        }

        $nodeBinary = $this->resolveCommandBinary([
            trim((string)(getenv('VW_NODE_PATH') ?: '')),
            'node',
            'C:\\Program Files\\nodejs\\node.exe',
        ]);
        if ($nodeBinary === null) {
            return [
                'success' => false,
                'error' => 'Node.js is required to sync GitHub cookies secret.'
            ];
        }

        $process = $this->runLocalProcess(
            [$nodeBinary, $scriptPath],
            dirname(__DIR__),
            [
                'GH_OWNER' => $config['owner'],
                'GH_REPO' => $config['repo'],
                'GH_TOKEN' => $config['token'],
                'GH_SECRET_NAME' => 'YTDLP_COOKIES_B64',
                'GH_SECRET_FILE' => $sourceFile,
                'GH_SECRET_DELETE_CHUNKED' => '1',
                'GH_SECRET_CHUNK_PREFIX' => 'YTDLP_COOKIES_B64_',
            ]
        );

        if (!$process['success']) {
            return $process;
        }

        $stdout = trim((string)($process['stdout'] ?? ''));
        $stderr = trim((string)($process['stderr'] ?? ''));
        if (($process['exit_code'] ?? 1) !== 0) {
            return [
                'success' => false,
                'error' => $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'GitHub cookies secret sync failed.')
            ];
        }

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            return [
                'success' => false,
                'error' => $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'GitHub cookies secret sync returned an invalid response.')
            ];
        }

        return [
            'success' => true,
            'message' => !empty($decoded['mode']) && $decoded['mode'] === 'chunked'
                ? 'cookies.txt synced to chunked GitHub cookies secrets.'
                : 'cookies.txt synced to GitHub secret YTDLP_COOKIES_B64.',
            'mode' => (string)($decoded['mode'] ?? 'single'),
            'source_file' => (string)($decoded['source_file'] ?? $sourceFile),
            'updated_secrets' => is_array($decoded['updated_secrets'] ?? null) ? $decoded['updated_secrets'] : [],
            'deleted_secrets' => is_array($decoded['deleted_secrets'] ?? null) ? $decoded['deleted_secrets'] : [],
        ];
    }

    public function syncHostedCallbackConfig(?string $publicBaseUrl = null, ?string $callbackSecret = null): array
    {
        $config = $this->getConfig();
        if (!$config['success']) {
            return $config;
        }

        $publicBaseUrl = trim((string)($publicBaseUrl ?? ($this->settings['panel_public_base_url'] ?? '')));
        if (!$this->isUsablePublicBaseUrl($publicBaseUrl)) {
            return [
                'success' => false,
                'error' => 'Public panel base URL is missing or still local-only.'
            ];
        }

        $callbackUrl = rtrim($publicBaseUrl, '/') . '/api/github-runner-callback.php';
        $callbackSecret = trim((string)($callbackSecret ?? ($this->settings['github_runner_callback_secret'] ?? '')));
        if ($callbackSecret === '') {
            return [
                'success' => false,
                'error' => 'Callback secret is empty.'
            ];
        }

        $variableSync = $this->upsertActionsVariable($config, 'GH_RUNNER_CALLBACK_URL', $callbackUrl);
        if (!$variableSync['success']) {
            return $variableSync;
        }

        $secretSync = $this->syncActionsSecretValue($config, 'GH_RUNNER_CALLBACK_SECRET', $callbackSecret);
        if (!$secretSync['success']) {
            return $secretSync;
        }

        return [
            'success' => true,
            'message' => 'GitHub callback URL and secret synced.',
            'callback_url' => $callbackUrl,
        ];
    }

    public function clearHostedCallbackConfig(): array
    {
        $config = $this->getConfig();
        if (!$config['success']) {
            return $config;
        }

        $urlDelete = $this->deleteActionsVariable($config, 'GH_RUNNER_CALLBACK_URL');
        if (!$urlDelete['success']) {
            return $urlDelete;
        }

        $secretDelete = $this->deleteActionsSecret($config, 'GH_RUNNER_CALLBACK_SECRET');
        if (!$secretDelete['success']) {
            return $secretDelete;
        }

        return [
            'success' => true,
            'message' => 'GitHub callback URL and secret cleared.'
        ];
    }

    private function resolveLocalCookiesFile(?string $sourceFile = null): string
    {
        $candidates = [
            $sourceFile,
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cookies.txt',
            $this->settings['ytdlp_cookies_file'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '' || !is_file($candidate) || filesize($candidate) < 1) {
                continue;
            }

            $resolved = realpath($candidate);
            return $resolved !== false ? $resolved : $candidate;
        }

        return '';
    }

    private function isUsablePublicBaseUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }

        $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return false;
        }

        return !$this->isLocalHost($host);
    }

    private function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }
        if (preg_match('/^(10\.)/', $host)) {
            return true;
        }
        if (preg_match('/^(192\.168\.)/', $host)) {
            return true;
        }
        if (preg_match('/^(172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $host)) {
            return true;
        }
        return str_ends_with($host, '.local');
    }

    private function upsertActionsVariable(array $config, string $name, string $value): array
    {
        $baseUrl = "https://api.github.com/repos/{$config['owner']}/{$config['repo']}/actions/variables";
        $payload = ['name' => $name, 'value' => $value];

        $res = $this->apiRequest($baseUrl . '/' . rawurlencode($name), $config['token'], 'PATCH', $payload);
        if ($res['success'] && in_array($res['status'], [204], true)) {
            return ['success' => true];
        }

        if ($res['success'] && $res['status'] !== 404) {
            return [
                'success' => false,
                'error' => "Failed to update GitHub Actions variable {$name} ({$res['status']})",
                'details' => $res['body'] ?? ''
            ];
        }

        $create = $this->apiRequest($baseUrl, $config['token'], 'POST', $payload);
        if (!$create['success'] || !in_array($create['status'], [201, 204], true)) {
            return [
                'success' => false,
                'error' => "Failed to create GitHub Actions variable {$name}" . (!empty($create['status']) ? " ({$create['status']})" : ''),
                'details' => $create['body'] ?? ''
            ];
        }

        return ['success' => true];
    }

    private function deleteActionsVariable(array $config, string $name): array
    {
        $url = "https://api.github.com/repos/{$config['owner']}/{$config['repo']}/actions/variables/" . rawurlencode($name);
        $res = $this->apiRequest($url, $config['token'], 'DELETE');
        if (!$res['success']) {
            return $res;
        }
        if (!in_array($res['status'], [204, 404], true)) {
            return [
                'success' => false,
                'error' => "Failed to delete GitHub Actions variable {$name} ({$res['status']})",
                'details' => $res['body'] ?? ''
            ];
        }
        return ['success' => true];
    }

    private function syncActionsSecretValue(array $config, string $secretName, string $value): array
    {
        $scriptPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'sync-github-secret.mjs';
        if (!is_file($scriptPath)) {
            return [
                'success' => false,
                'error' => 'GitHub secret sync helper script is missing.'
            ];
        }

        $nodeBinary = $this->resolveCommandBinary([
            trim((string)(getenv('VW_NODE_PATH') ?: '')),
            'node',
            'C:\\Program Files\\nodejs\\node.exe',
        ]);
        if ($nodeBinary === null) {
            return [
                'success' => false,
                'error' => 'Node.js is required to sync GitHub callback secret.'
            ];
        }

        $process = $this->runLocalProcess(
            [$nodeBinary, $scriptPath],
            dirname(__DIR__),
            [
                'GH_OWNER' => $config['owner'],
                'GH_REPO' => $config['repo'],
                'GH_TOKEN' => $config['token'],
                'GH_SECRET_NAME' => $secretName,
                'GH_SECRET_VALUE' => $value,
                'GH_SECRET_DELETE_CHUNKED' => '1',
                'GH_SECRET_CHUNK_PREFIX' => $secretName . '_',
            ]
        );

        if (!$process['success']) {
            return $process;
        }

        $stdout = trim((string)($process['stdout'] ?? ''));
        $stderr = trim((string)($process['stderr'] ?? ''));
        if (($process['exit_code'] ?? 1) !== 0) {
            return [
                'success' => false,
                'error' => $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'GitHub callback secret sync failed.')
            ];
        }

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            return [
                'success' => false,
                'error' => $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'GitHub callback secret sync returned an invalid response.')
            ];
        }

        return ['success' => true];
    }

    private function deleteActionsSecret(array $config, string $secretName): array
    {
        $url = "https://api.github.com/repos/{$config['owner']}/{$config['repo']}/actions/secrets/" . rawurlencode($secretName);
        $res = $this->apiRequest($url, $config['token'], 'DELETE');
        if (!$res['success']) {
            return $res;
        }
        if (!in_array($res['status'], [204, 404], true)) {
            return [
                'success' => false,
                'error' => "Failed to delete GitHub Actions secret {$secretName} ({$res['status']})",
                'details' => $res['body'] ?? ''
            ];
        }
        return ['success' => true];
    }

    private function resolveCommandBinary(array $candidates): ?string
    {
        $finder = DIRECTORY_SEPARATOR === '\\' ? 'where.exe' : 'which';

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }

            if (strpos($candidate, DIRECTORY_SEPARATOR) !== false || preg_match('/^[A-Za-z]:[\/\\\\]/', $candidate)) {
                if (is_file($candidate)) {
                    return $candidate;
                }
                continue;
            }

            $lookup = $this->runLocalProcess([$finder, $candidate], dirname(__DIR__));
            if (!$lookup['success'] || ($lookup['exit_code'] ?? 1) !== 0) {
                continue;
            }

            foreach (preg_split("/\r\n|\n|\r/", (string)($lookup['stdout'] ?? '')) ?: [] as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    return $line;
                }
            }
        }

        return null;
    }

    private function runLocalProcess(array $command, ?string $cwd = null, array $env = []): array
    {
        if (!function_exists('proc_open')) {
            return ['success' => false, 'error' => 'proc_open is required to run local helper commands.'];
        }

        $parts = [];
        foreach ($command as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        if (empty($parts)) {
            return ['success' => false, 'error' => 'Local helper command is empty.'];
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $commandString = implode(' ', array_map('escapeshellarg', $parts));
        $pipes = [];
        $process = @proc_open(
            $commandString,
            $descriptorSpec,
            $pipes,
            $cwd ?: dirname(__DIR__),
            $this->buildProcessEnvironment($env)
        );

        if (!is_resource($process)) {
            return ['success' => false, 'error' => 'Failed to start local helper command.'];
        }

        fwrite($pipes[0], '');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'success' => true,
            'exit_code' => (int)$exitCode,
            'stdout' => (string)$stdout,
            'stderr' => (string)$stderr,
        ];
    }

    private function buildProcessEnvironment(array $overrides = []): array
    {
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }

        foreach (['PATH', 'SystemRoot', 'ComSpec', 'PATHEXT', 'TEMP', 'TMP', 'HOME', 'USERPROFILE'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $env[$key] = (string)$value;
            }
        }

        foreach ($_ENV as $key => $value) {
            if (is_string($key) && $key !== '' && (is_scalar($value) || $value === null)) {
                $env[$key] = $value === null ? '' : (string)$value;
            }
        }

        foreach ($overrides as $key => $value) {
            if (is_string($key) && $key !== '') {
                $env[$key] = (string)$value;
            }
        }

        return $env;
    }

    private function apiRequest(string $url, string $token, string $method = 'GET', ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'cURL extension is required for GitHub API.'];
        }

        $ch = curl_init($url);
        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: VideoWorkflow-GitHubRunner/1.0'
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];

        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return [
                'success' => false,
                'error' => 'GitHub API request failed: ' . $curlErr
            ];
        }

        $json = json_decode($body, true);
        return [
            'success' => true,
            'status' => (int)$status,
            'body' => (string)$body,
            'json' => is_array($json) ? $json : null
        ];
    }
}
