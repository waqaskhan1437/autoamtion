<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

function findLatestCronLog(string $logsDir): ?array
{
    $files = glob($logsDir . '/cron_*.log');
    if (!$files) {
        return null;
    }

    usort($files, static function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    $latest = $files[0];
    return [
        'file' => basename($latest),
        'path' => $latest,
        'modified_at' => gmdate('Y-m-d H:i:s', (int)filemtime($latest)),
    ];
}

try {
    $nowTs = time();
    $nowUtc = gmdate('Y-m-d H:i:s', $nowTs);

    $stmt = $pdo->query("
        SELECT id, name, enabled, status, next_run_at, last_run_at
        FROM automation_settings
        WHERE enabled = 1
        ORDER BY id ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $automations = [];
    $dueNow = 0;

    foreach ($rows as $row) {
        $nextRunAt = $row['next_run_at'] ?? null;
        $nextRunTs = $nextRunAt ? strtotime((string)$nextRunAt) : false;

        $dueInSeconds = null;
        $dueInMinutes = null;
        $state = 'missing_next_run';

        if ($nextRunTs !== false && $nextRunTs !== null) {
            $dueInSeconds = $nextRunTs - $nowTs;
            $dueInMinutes = (int)floor($dueInSeconds / 60);
            $state = $dueInSeconds <= 0 ? 'due' : 'scheduled';
            if ($dueInSeconds <= 0) {
                $dueNow++;
            }
        }

        $automations[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'enabled' => (int)$row['enabled'],
            'status' => (string)$row['status'],
            'next_run_at' => $nextRunAt,
            'last_run_at' => $row['last_run_at'] ?? null,
            'due_state' => $state,
            'due_in_seconds' => $dueInSeconds,
            'due_in_minutes' => $dueInMinutes,
        ];
    }

    $latestLog = findLatestCronLog(__DIR__ . '/../logs');

    echo json_encode([
        'ok' => true,
        'server_time_utc' => $nowUtc,
        'enabled_automations' => count($automations),
        'due_now' => $dueNow,
        'latest_cron_log' => $latestLog,
        'automations' => $automations,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
?>
