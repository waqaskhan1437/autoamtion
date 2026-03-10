<?php

require_once __DIR__ . '/AutomationSnapshotBuilder.php';
require_once __DIR__ . '/RemoteExecutionHelper.php';

class LocalAgentManager
{
    private PDO $pdo;
    private AutomationSnapshotBuilder $snapshotBuilder;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->snapshotBuilder = new AutomationSnapshotBuilder($pdo);
    }

    public function getPairingToken(): string
    {
        $token = trim($this->getSetting('local_agent_pairing_token'));
        if ($token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(16));
        $this->saveSetting('local_agent_pairing_token', $token);
        return $token;
    }

    public function getPublicBaseUrl(): string
    {
        $configured = trim($this->getSetting('panel_public_base_url'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $scheme = $https ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }

        $basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        if ($basePath === '/' || $basePath === '.') {
            $basePath = '';
        }

        return $scheme . '://' . $host . $basePath;
    }

    public function listAgents(bool $includeDisabled = true): array
    {
        $sql = "SELECT * FROM local_agents";
        if (!$includeDisabled) {
            $sql .= " WHERE status <> 'disabled'";
        }
        $sql .= " ORDER BY display_name ASC, id ASC";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function registerAgent(array $payload, string $ipAddress = ''): array
    {
        $agentKey = trim((string)($payload['agent_key'] ?? ''));
        $agentSecret = trim((string)($payload['agent_secret'] ?? ''));
        $pairingToken = trim((string)($payload['pairing_token'] ?? ''));
        $displayName = trim((string)($payload['display_name'] ?? ''));
        $machineName = trim((string)($payload['machine_name'] ?? ''));
        $hostName = trim((string)($payload['host_name'] ?? ''));
        $platform = trim((string)($payload['platform'] ?? ''));
        $agentVersion = trim((string)($payload['agent_version'] ?? ''));
        $capabilities = isset($payload['capabilities']) && is_array($payload['capabilities']) ? $payload['capabilities'] : [];

        if ($agentKey !== '' && $agentSecret !== '') {
            $agent = $this->authenticateAgent($agentKey, $agentSecret);
            if (!$agent) {
                return ['success' => false, 'error' => 'Agent credentials are invalid.'];
            }

            $this->refreshAgent((int)$agent['id'], [
                'display_name' => $displayName !== '' ? $displayName : ($agent['display_name'] ?? ''),
                'machine_name' => $machineName,
                'host_name' => $hostName,
                'platform' => $platform,
                'agent_version' => $agentVersion,
                'last_ip' => $ipAddress,
                'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]);

            return [
                'success' => true,
                'agent_key' => $agentKey,
                'agent_secret' => $agentSecret,
                'agent' => $this->getAgentById((int)$agent['id'])
            ];
        }

        if ($pairingToken === '' || !hash_equals($this->getPairingToken(), $pairingToken)) {
            return ['success' => false, 'error' => 'Pairing token is invalid.'];
        }

        $agentKey = 'ag_' . bin2hex(random_bytes(12));
        $agentSecret = bin2hex(random_bytes(24));
        $displayName = $displayName !== '' ? $displayName : ($machineName !== '' ? $machineName : 'Local Agent');

        $stmt = $this->pdo->prepare("
            INSERT INTO local_agents (
                agent_key, agent_secret_hash, display_name, machine_name, host_name,
                platform, agent_version, status, last_seen_at, last_ip, capabilities_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'online', NOW(), ?, ?)
        ");
        $stmt->execute([
            $agentKey,
            password_hash($agentSecret, PASSWORD_DEFAULT),
            $displayName,
            $machineName,
            $hostName,
            $platform,
            $agentVersion,
            $ipAddress,
            json_encode($capabilities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);

        $agentId = (int)$this->pdo->lastInsertId();

        return [
            'success' => true,
            'agent_key' => $agentKey,
            'agent_secret' => $agentSecret,
            'agent' => $this->getAgentById($agentId)
        ];
    }

    public function authenticateAgent(string $agentKey, string $agentSecret): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM local_agents WHERE agent_key = ? LIMIT 1");
        $stmt->execute([$agentKey]);
        $agent = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$agent || ($agent['status'] ?? '') === 'disabled') {
            return null;
        }

        $hash = (string)($agent['agent_secret_hash'] ?? '');
        if ($hash === '' || !password_verify($agentSecret, $hash)) {
            return null;
        }

        return $agent;
    }

    public function touchAgent(array $agent, string $ipAddress = ''): void
    {
        $this->refreshAgent((int)$agent['id'], [
            'status' => 'online',
            'last_ip' => $ipAddress !== '' ? $ipAddress : ($agent['last_ip'] ?? '')
        ]);
    }

    public function queueAutomation(int $automationId, string $triggerSource = 'manual'): array
    {
        $stmt = $this->pdo->prepare("
            SELECT a.*, ag.display_name AS agent_name, ag.status AS agent_status
            FROM automation_settings a
            LEFT JOIN local_agents ag ON ag.id = a.local_agent_id
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmt->execute([$automationId]);
        $automation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$automation) {
            return ['success' => false, 'error' => 'Automation not found.'];
        }

        $agentId = (int)($automation['local_agent_id'] ?? 0);
        if ($agentId <= 0) {
            return ['success' => false, 'error' => 'No local agent is assigned to this automation.'];
        }
        if (($automation['agent_status'] ?? '') === 'disabled') {
            return ['success' => false, 'error' => 'Assigned local agent is disabled.'];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO local_agent_jobs (agent_id, automation_id, trigger_source, status, queued_at)
            VALUES (?, ?, ?, 'queued', NOW())
        ");
        $stmt->execute([$agentId, $automationId, $triggerSource]);
        $jobId = (int)$this->pdo->lastInsertId();

        $progressPayload = json_encode([
            'step' => 'local_agent',
            'status' => 'info',
            'message' => 'Queued for local agent ' . ($automation['agent_name'] ?: ('#' . $agentId)),
            'progress' => 5,
            'stats' => ['fetched' => 0, 'downloaded' => 0, 'processed' => 0, 'scheduled' => 0, 'posted' => 0],
            'job_id' => $jobId,
            'time' => date('H:i:s')
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->pdo->prepare("
            UPDATE automation_settings
            SET status = 'queued',
                progress_percent = 5,
                progress_data = ?,
                last_progress_time = NOW()
            WHERE id = ?
        ")->execute([$progressPayload, $automationId]);

        $this->pdo->prepare("
            INSERT INTO automation_logs (automation_id, action, status, message)
            VALUES (?, 'local_agent_queue', 'info', ?)
        ")->execute([$automationId, 'Queued for local agent ' . ($automation['agent_name'] ?: ('#' . $agentId)) . ' via ' . $triggerSource]);

        return [
            'success' => true,
            'job_id' => $jobId,
            'agent_id' => $agentId,
            'agent_name' => $automation['agent_name'] ?: ('Agent #' . $agentId)
        ];
    }

    public function claimNextJob(string $agentKey, string $agentSecret, string $ipAddress = ''): array
    {
        $agent = $this->authenticateAgent($agentKey, $agentSecret);
        if (!$agent) {
            return ['success' => false, 'error' => 'Agent authentication failed.', 'http_status' => 403];
        }

        $this->touchAgent($agent, $ipAddress);

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM local_agent_jobs
            WHERE agent_id = ?
              AND status = 'queued'
            ORDER BY queued_at ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute([(int)$agent['id']]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            return ['success' => true, 'job' => null, 'agent' => $agent];
        }

        $claimToken = bin2hex(random_bytes(16));
        $update = $this->pdo->prepare("
            UPDATE local_agent_jobs
            SET status = 'claimed',
                claim_token = ?,
                claimed_at = NOW(),
                last_heartbeat_at = NOW()
            WHERE id = ?
              AND status = 'queued'
        ");
        $update->execute([$claimToken, (int)$job['id']]);
        if ($update->rowCount() === 0) {
            return ['success' => true, 'job' => null, 'agent' => $agent];
        }

        $payload = $this->snapshotBuilder->buildCompressedPayload((int)$job['automation_id']);
        if (!$payload['success']) {
            $this->pdo->prepare("
                UPDATE local_agent_jobs
                SET status = 'error',
                    error_message = ?,
                    completed_at = NOW()
                WHERE id = ?
            ")->execute([$payload['error'] ?? 'Failed to build automation payload.', (int)$job['id']]);

            return ['success' => false, 'error' => $payload['error'] ?? 'Failed to build automation payload.'];
        }

        return [
            'success' => true,
            'agent' => $agent,
            'job' => [
                'id' => (int)$job['id'],
                'automation_id' => (int)$job['automation_id'],
                'trigger_source' => (string)$job['trigger_source'],
                'claim_token' => $claimToken,
                'payload_gzip_b64' => $payload['payload_gzip_b64'],
                'snapshot_at' => $payload['data']['snapshot_at'] ?? gmdate('c')
            ]
        ];
    }

    public function receiveProgress(int $jobId, string $claimToken, array $payload, string $ipAddress = ''): array
    {
        $job = $this->authorizeJobClaim($jobId, $claimToken, ['claimed', 'running', 'completed']);
        if (!$job) {
            return ['success' => false, 'error' => 'Job claim is invalid.'];
        }

        $this->pdo->prepare("
            UPDATE local_agent_jobs
            SET status = CASE WHEN status = 'claimed' THEN 'running' ELSE status END,
                started_at = CASE WHEN started_at IS NULL THEN NOW() ELSE started_at END,
                last_heartbeat_at = NOW()
            WHERE id = ?
        ")->execute([$jobId]);

        $result = remoteExecutionApplyProgress($this->pdo, (int)$job['automation_id'], $payload, 'local_agent_progress');

        $stmt = $this->pdo->prepare("SELECT * FROM local_agents WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$job['agent_id']]);
        $agent = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($agent) {
            $this->touchAgent($agent, $ipAddress);
        }

        return $result;
    }

    public function completeJob(int $jobId, string $claimToken, array $payload, string $ipAddress = ''): array
    {
        $job = $this->authorizeJobClaim($jobId, $claimToken, ['claimed', 'running']);
        if (!$job) {
            return ['success' => false, 'error' => 'Job claim is invalid.'];
        }

        $status = remoteExecutionNormalizeStatus((string)($payload['status'] ?? 'completed'));
        if (!in_array($status, ['completed', 'error'], true)) {
            $status = 'completed';
            $payload['status'] = $status;
        }

        $result = remoteExecutionApplyProgress($this->pdo, (int)$job['automation_id'], $payload, 'local_agent_complete');

        $this->pdo->prepare("
            UPDATE local_agent_jobs
            SET status = ?,
                completed_at = NOW(),
                last_heartbeat_at = NOW(),
                result_json = ?,
                error_message = ?
            WHERE id = ?
        ")->execute([
            $status,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status === 'error' ? trim((string)($payload['message'] ?? 'Agent execution failed.')) : null,
            $jobId
        ]);

        $stmt = $this->pdo->prepare("SELECT * FROM local_agents WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$job['agent_id']]);
        $agent = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($agent) {
            $this->touchAgent($agent, $ipAddress);
        }

        return $result;
    }

    public function setAgentStatus(int $agentId, string $status): void
    {
        $allowed = ['online', 'offline', 'disabled'];
        $status = in_array($status, $allowed, true) ? $status : 'offline';
        $stmt = $this->pdo->prepare("UPDATE local_agents SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $agentId]);
    }

    public function authorizeJobClaim(int $jobId, string $claimToken, array $statuses = ['claimed', 'running']): ?array
    {
        $statuses = array_values(array_filter(array_map('strval', $statuses)));
        if (empty($statuses)) {
            $statuses = ['claimed', 'running'];
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM local_agent_jobs
            WHERE id = ?
              AND claim_token = ?
              AND status IN ($placeholders)
            LIMIT 1
        ");
        $stmt->execute(array_merge([$jobId, $claimToken], $statuses));
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        return $job ?: null;
    }

    private function refreshAgent(int $agentId, array $fields): void
    {
        $parts = ["status = COALESCE(NULLIF(?, ''), status)", "last_seen_at = NOW()", "updated_at = NOW()"];
        $values = [$fields['status'] ?? ''];

        foreach ([
            'display_name',
            'machine_name',
            'host_name',
            'platform',
            'agent_version',
            'last_ip',
            'capabilities_json'
        ] as $column) {
            $parts[] = "{$column} = COALESCE(NULLIF(?, ''), {$column})";
            $values[] = (string)($fields[$column] ?? '');
        }

        $values[] = $agentId;
        $sql = "UPDATE local_agents SET " . implode(', ', $parts) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    private function getAgentById(int $agentId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM local_agents WHERE id = ? LIMIT 1");
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch(PDO::FETCH_ASSOC);
        return $agent ?: null;
    }

    private function getSetting(string $key): string
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null) ? '' : (string)$value;
    }

    private function saveSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$key, $value]);
    }
}
