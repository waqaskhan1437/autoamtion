<?php

class GitHubRunner
{
    private PDO $pdo;
    private array $settings = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->settings = $this->loadSettings();
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

        $workflow = rawurlencode($config['workflow']);
        $dispatchUrl = "https://api.github.com/repos/{$config['owner']}/{$config['repo']}/actions/workflows/{$workflow}/dispatches";

        $inputs = [
            'automation_id' => (string)$automationId,
            'trigger_source' => (string)$triggerSource
        ];

        $extraInputs = $this->decodeExtraInputs($config['inputs_json']);
        foreach ($extraInputs as $key => $value) {
            if (!array_key_exists($key, $inputs)) {
                $inputs[$key] = (string)$value;
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

        if (!in_array($res['status'], [201, 202, 204], true)) {
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
                $dispatchStartedAt - 2
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
            'github_runner_callback_secret'
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
        $workflow = trim((string)($this->settings['github_runner_workflow'] ?? 'automation-runner.yml'));
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

    private function findLatestRun(string $owner, string $repo, string $workflow, string $token, int $automationId, ?int $minCreatedAt = null): array
    {
        $encodedWorkflow = rawurlencode($workflow);
        $url = "https://api.github.com/repos/{$owner}/{$repo}/actions/workflows/{$encodedWorkflow}/runs?event=workflow_dispatch&per_page=5";
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
