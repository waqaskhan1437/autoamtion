<?php

class AutomationSnapshotBuilder
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function build(int $automationId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM automation_settings WHERE id = ? LIMIT 1");
        $stmt->execute([$automationId]);
        $automation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$automation) {
            return ['success' => false, 'error' => 'Automation not found for payload snapshot.'];
        }

        $apiKey = null;
        $apiKeyId = isset($automation['api_key_id']) ? (int)$automation['api_key_id'] : 0;
        if ($apiKeyId > 0) {
            $k = $this->pdo->prepare("SELECT * FROM api_keys WHERE id = ? LIMIT 1");
            $k->execute([$apiKeyId]);
            $apiKey = $k->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $settingsRows = $this->pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($settingsRows as $row) {
            $key = (string)($row['setting_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $settings[$key] = (string)($row['setting_value'] ?? '');
        }

        return [
            'success' => true,
            'data' => [
                'automation' => $automation,
                'api_key' => $apiKey,
                'settings' => $settings,
                'snapshot_at' => gmdate('c')
            ]
        ];
    }

    public function buildCompressedPayload(int $automationId): array
    {
        $snapshot = $this->build($automationId);
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

        return [
            'success' => true,
            'payload_json' => $snapshotJson,
            'payload_gzip_b64' => base64_encode($gzip),
            'data' => $snapshot['data']
        ];
    }
}
