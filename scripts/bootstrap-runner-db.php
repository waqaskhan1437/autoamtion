<?php
/**
 * Seeds runner MySQL database from repository_dispatch payload.
 *
 * Usage:
 *   php scripts/bootstrap-runner-db.php payload.json
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$payloadFile = $argv[1] ?? '';
if ($payloadFile === '' || !is_file($payloadFile)) {
    fwrite(STDERR, "Payload file missing.\n");
    exit(1);
}

$json = file_get_contents($payloadFile);
$payload = json_decode((string)$json, true);
if (!is_array($payload)) {
    fwrite(STDERR, "Invalid payload JSON.\n");
    exit(1);
}

$automation = $payload['automation'] ?? null;
$apiKey = $payload['api_key'] ?? null;
$settings = $payload['settings'] ?? [];
$processedVideos = $payload['processed_videos'] ?? [];
if (!is_array($automation)) {
    fwrite(STDERR, "Automation payload missing.\n");
    exit(1);
}
if (!is_array($settings)) {
    $settings = [];
}
if (!is_array($processedVideos)) {
    $processedVideos = [];
}

$envFlag = static function(string $key): bool {
    $value = getenv($key);
    if ($value === false) {
        return false;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
};

$ollamaAutoFallback = $envFlag('VW_OLLAMA_AUTO_FALLBACK') || $envFlag('OLLAMA_AUTO_FALLBACK');
if ($ollamaAutoFallback) {
    $ollamaBaseUrl = trim((string)(getenv('VW_OLLAMA_BASE_URL') ?: getenv('OLLAMA_BASE_URL') ?: 'http://127.0.0.1:11434'));
    $ollamaModel = trim((string)(getenv('VW_OLLAMA_MODEL') ?: getenv('OLLAMA_MODEL') ?: 'qwen2.5:3b'));

    $settings['ai_provider'] = 'ollama';
    $settings['ollama_auto_fallback'] = '1';
    $settings['ollama_base_url'] = $ollamaBaseUrl;
    $settings['ollama_model'] = $ollamaModel;
}

$dbHost = getenv('VW_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('VW_DB_NAME') ?: 'video_workflow';
$dbUser = getenv('VW_DB_USER') ?: 'root';
$dbPass = getenv('VW_DB_PASS');
if ($dbPass === false) {
    $dbPass = '';
}

try {
    $pdoInit = new PDO("mysql:host={$dbHost};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $pdoInit->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: {$e->getMessage()}\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $pdo->exec("DELETE FROM automation_logs");
    $pdo->exec("DELETE FROM processed_videos");
    $pdo->exec("DELETE FROM postforme_posts");
    $pdo->exec("DELETE FROM postforme_accounts");
    $pdo->exec("DELETE FROM processing_tasks");
    $pdo->exec("DELETE FROM video_jobs");
    $pdo->exec("DELETE FROM automation_settings");
    $pdo->exec("DELETE FROM api_keys");

    $userCount = $pdo->query("SELECT COUNT(*) FROM app_users")->fetchColumn();
    if ($userCount == 0) {
        $pdo->exec("INSERT INTO app_users (email, password_hash, display_name, role, status, can_use_github_runner, created_at) VALUES ('runner@local', '\$2y\$10\$placeholder', 'Runner User', 'admin', 'active', 1, NOW())");
    }

    $pdo->prepare("DELETE FROM settings WHERE setting_key NOT IN ('openai_api_key','ffmpeg_path','default_language')")->execute();
    $stmtSetting = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    foreach ($settings as $k => $v) {
        if (!is_string($k)) {
            continue;
        }
        $stmtSetting->execute([$k, (string)$v]);
    }

    $tableCols = [];
    $fetchCols = static function(PDO $pdo, string $table) use (&$tableCols): array {
        if (isset($tableCols[$table])) {
            return $tableCols[$table];
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cols = [];
        foreach ($rows as $r) {
            $cols[] = (string)$r['Field'];
        }
        $tableCols[$table] = $cols;
        return $cols;
    };

    $filterPayload = static function(array $row, array $allowedCols): array {
        $filtered = [];
        foreach ($row as $k => $v) {
            if (in_array((string)$k, $allowedCols, true)) {
                $filtered[(string)$k] = $v;
            }
        }
        return $filtered;
    };

    if (is_array($apiKey) && !empty($apiKey)) {
        $apiKey = $filterPayload($apiKey, $fetchCols($pdo, 'api_keys'));
        $apiCols = array_keys($apiKey);
        $apiVals = array_values($apiKey);
        if (!empty($apiCols)) {
            $placeholders = implode(',', array_fill(0, count($apiCols), '?'));
            $columns = implode(',', array_map(static fn($c) => "`{$c}`", $apiCols));
            $sql = "INSERT INTO api_keys ({$columns}) VALUES ({$placeholders})";
            $pdo->prepare($sql)->execute($apiVals);
        }
    }

    // Runner controls mode itself; force github_runner and non-local status fields.
    $automation['run_mode'] = 'github_runner';
    $automation['status'] = 'inactive';
    $automation['progress_percent'] = 0;
    $automation['progress_data'] = null;
    $automation['last_progress_time'] = null;
    $automation['last_run_at'] = null;
    $automation['next_run_at'] = null;
    $automation['process_id'] = null;

    if (isset($automation['owner_user_id']) && $automation['owner_user_id'] !== null) {
        $stmtCheck = $pdo->prepare("SELECT id FROM app_users WHERE id = ?");
        $stmtCheck->execute([$automation['owner_user_id']]);
        if (!$stmtCheck->fetch()) {
            $automation['owner_user_id'] = null;
        }
    }

    $automation = $filterPayload($automation, $fetchCols($pdo, 'automation_settings'));
    $autoCols = array_keys($automation);
    $autoVals = array_values($automation);
    $placeholders = implode(',', array_fill(0, count($autoCols), '?'));
    $columns = implode(',', array_map(static fn($c) => "`{$c}`", $autoCols));
    $sql = "INSERT INTO automation_settings ({$columns}) VALUES ({$placeholders})";
    $pdo->prepare($sql)->execute($autoVals);

    $processedCols = $fetchCols($pdo, 'processed_videos');
    foreach ($processedVideos as $row) {
        if (!is_array($row) || empty($row)) {
            continue;
        }
        $filtered = $filterPayload($row, $processedCols);
        $cols = array_keys($filtered);
        if (empty($cols)) {
            continue;
        }
        $vals = array_values($filtered);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $columns = implode(',', array_map(static fn($c) => "`{$c}`", $cols));
        $sql = "INSERT INTO processed_videos ({$columns}) VALUES ({$placeholders})";
        $pdo->prepare($sql)->execute($vals);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Seeding failed: {$e->getMessage()}\n");
    exit(1);
}

$id = isset($automation['id']) ? (int)$automation['id'] : 0;
fwrite(STDOUT, "Seed complete for automation #{$id}\n");
exit(0);
