<?php
/**
 * Check Automation Progress
 * Returns current status for polling
 */

// Suppress HTML errors
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Config error']);
    exit;
}

if (!isset($pdo)) {
    echo json_encode(['success' => false, 'error' => 'Database not connected']);
    exit;
}

$automationId = $_GET['id'] ?? null;

if (!$automationId) {
    echo json_encode(['success' => false, 'error' => 'No automation ID']);
    exit;
}

// Try with new columns first, fallback to basic query
try {
    $stmt = $pdo->prepare("SELECT status, progress_percent, progress_data, last_progress_time, next_run_at, enabled FROM automation_settings WHERE id = ?");
    $stmt->execute([$automationId]);
    $automation = $stmt->fetch();
} catch (Exception $e) {
    // Columns might not exist yet - use basic query
    try {
        $stmt = $pdo->prepare("SELECT status FROM automation_settings WHERE id = ?");
        $stmt->execute([$automationId]);
        $automation = $stmt->fetch();
        if ($automation) {
            $automation['progress_percent'] = 0;
            $automation['progress_data'] = null;
            $automation['last_progress_time'] = null;
            $automation['next_run_at'] = null;
            $automation['enabled'] = 0;
        }
    } catch (Exception $e2) {
        echo json_encode(['success' => false, 'error' => 'Database query failed: ' . $e2->getMessage()]);
        exit;
    }
}

if (!$automation) {
    echo json_encode(['success' => false, 'error' => 'Automation not found']);
    exit;
}

$progressData = json_decode($automation['progress_data'] ?? '{}', true);
$progressPercent = (int)($automation['progress_percent'] ?? 0);
$nextRunTs = !empty($automation['next_run_at']) ? strtotime((string)$automation['next_run_at']) : false;
$hasFutureNextRun = ($nextRunTs !== false && $nextRunTs > time());

// Treat scheduled-cycle completion as done when cron has already moved to next run.
$cycleCompleted = (
    ($automation['status'] === 'running') &&
    ($progressPercent >= 100) &&
    $hasFutureNextRun
);

// For queued items, show queue position
$queuePosition = 0;
if ($automation['status'] === 'queued') {
    $stmt = $pdo->prepare("SELECT COUNT(*) as pos FROM automation_settings WHERE status = 'queued' AND id < ?");
    $stmt->execute([$automationId]);
    $pos = $stmt->fetch();
    $queuePosition = ($pos['pos'] ?? 0) + 1;
    
    // Also check what's currently processing
    $stmt = $pdo->query("SELECT name FROM automation_settings WHERE status = 'processing' LIMIT 1");
    $processing = $stmt->fetch();
    if ($processing) {
        $progressData['message'] = "Queue position: #{$queuePosition}. Waiting for '{$processing['name']}' to finish.";
        $progressData['step'] = 'queued';
        $progressData['status'] = 'info';
    }
}

// Fetch recent logs if requested
$recentLogs = [];
if (isset($_GET['with_logs']) && $_GET['with_logs']) {
    try {
        $stmt = $pdo->prepare("SELECT action, status, message, created_at FROM automation_logs 
                               WHERE automation_id = ? 
                               ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$automationId]);
        $recentLogs = array_reverse($stmt->fetchAll());
    } catch (Exception $e) {
        // Logs table might not exist
    }
}

echo json_encode([
    'success' => true,
    'status' => $automation['status'],
    'progress' => $progressPercent,
    'data' => $progressData,
    'lastUpdate' => $automation['last_progress_time'] ?? null,
    'nextRunAt' => $automation['next_run_at'] ?? null,
    'nextRunTs' => ($nextRunTs !== false ? $nextRunTs : null),
    'enabled' => (int)($automation['enabled'] ?? 0),
    'queuePosition' => $queuePosition,
    'logs' => $recentLogs,
    'done' => in_array($automation['status'], ['completed', 'error', 'stopped', 'inactive']) || $cycleCompleted
]);
