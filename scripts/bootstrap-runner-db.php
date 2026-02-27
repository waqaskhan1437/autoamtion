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
if (!is_array($automation)) {
    fwrite(STDERR, "Automation payload missing.\n");
    exit(1);
}
if (!is_array($settings)) {
    $settings = [];
}

$dbHost = getenv('VW_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('VW_DB_NAME') ?: 'video_workflow';
$dbUser = getenv('VW_DB_USER') ?: 'root';
$dbPass = getenv('VW_DB_PASS');
if ($dbPass === false) {
    $dbPass = '';
}

try {
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

    $automation = $filterPayload($automation, $fetchCols($pdo, 'automation_settings'));
    $autoCols = array_keys($automation);
    $autoVals = array_values($automation);
    $placeholders = implode(',', array_fill(0, count($autoCols), '?'));
    $columns = implode(',', array_map(static fn($c) => "`{$c}`", $autoCols));
    $sql = "INSERT INTO automation_settings ({$columns}) VALUES ({$placeholders})";
    $pdo->prepare($sql)->execute($autoVals);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Seeding failed: {$e->getMessage()}\n");
    exit(1);
}

$id = isset($automation['id']) ? (int)$automation['id'] : 0;
fwrite(STDOUT, "Seed complete for automation #{$id}\n");
exit(0);
