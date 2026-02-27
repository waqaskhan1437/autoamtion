<?php
/**
 * Optional callback endpoint for GitHub runner jobs.
 * This lets workflows push status/progress back into automation cards.
 */

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
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

function callbackSetting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return ($value === false || $value === null) ? $default : (string)$value;
}

function callbackNextRun(string $scheduleType, int $scheduleHour, int $scheduleEveryMinutes = 10): string
{
    $nextRun = new DateTime();
    $scheduleHour = (int)$scheduleHour;
    $scheduleEveryMinutes = max(1, (int)$scheduleEveryMinutes);

    switch ($scheduleType) {
        case 'minutes':
            $nextRun->modify('+' . $scheduleEveryMinutes . ' minutes');
            break;
        case 'hourly':
            $nextRun->modify('+1 hour');
            break;
        case 'weekly':
            $nextRun->modify('next monday ' . $scheduleHour . ':00');
            break;
        case 'daily':
        default:
            if ((int)$nextRun->format('H') >= $scheduleHour) {
                $nextRun->modify('+1 day');
            }
            $nextRun->setTime($scheduleHour, 0, 0);
            break;
    }

    return $nextRun->format('Y-m-d H:i:s');
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$secretConfigured = callbackSetting($pdo, 'github_runner_callback_secret', '');
$secretIncoming = isset($payload['secret']) ? (string)$payload['secret'] : '';
if ($secretConfigured !== '' && !hash_equals($secretConfigured, $secretIncoming)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid callback secret']);
    exit;
}

$automationId = isset($payload['automation_id']) ? (int)$payload['automation_id'] : 0;
if ($automationId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing automation_id']);
    exit;
}

$status = strtolower(trim((string)($payload['status'] ?? 'running')));
$allowedStatuses = ['running', 'processing', 'queued', 'completed', 'error', 'stopped'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'running';
}

$progress = (int)($payload['progress'] ?? 0);
if ($progress < 0) {
    $progress = 0;
}
if ($progress > 100) {
    $progress = 100;
}

$message = trim((string)($payload['message'] ?? 'GitHub runner update received.'));
$step = trim((string)($payload['step'] ?? 'github_runner'));
$runUrl = trim((string)($payload['run_url'] ?? ''));
$stats = isset($payload['stats']) && is_array($payload['stats']) ? $payload['stats'] : [];

$stmt = $pdo->prepare("
    SELECT enabled, schedule_type, schedule_hour, schedule_every_minutes, next_run_at
    FROM automation_settings
    WHERE id = ?
");
$stmt->execute([$automationId]);
$automation = $stmt->fetch();

if (!$automation) {
    echo json_encode(['success' => false, 'error' => 'Automation not found']);
    exit;
}

$progressData = [
    'step' => $step,
    'status' => $status === 'error' ? 'error' : ($status === 'completed' ? 'success' : 'info'),
    'message' => $message,
    'progress' => $progress,
    'run_url' => ($runUrl !== '' ? $runUrl : null),
    'stats' => $stats,
    'time' => date('H:i:s')
];

$nextRunAt = $automation['next_run_at'] ?? null;
if (in_array($status, ['completed', 'error'], true) && (int)$automation['enabled'] === 1) {
    $currentNextRunTs = !empty($nextRunAt) ? strtotime((string)$nextRunAt) : false;
    if ($currentNextRunTs === false || $currentNextRunTs <= time()) {
        $nextRunAt = callbackNextRun(
            (string)($automation['schedule_type'] ?? 'daily'),
            (int)($automation['schedule_hour'] ?? 9),
            (int)($automation['schedule_every_minutes'] ?? 10)
        );
    }
}

$stmt = $pdo->prepare("
    UPDATE automation_settings
    SET status = ?,
        progress_percent = ?,
        progress_data = ?,
        last_progress_time = NOW(),
        last_run_at = CASE WHEN ? IN ('completed','error') THEN NOW() ELSE last_run_at END,
        next_run_at = ?
    WHERE id = ?
");
$stmt->execute([
    $status,
    $progress,
    json_encode($progressData),
    $status,
    $nextRunAt,
    $automationId
]);

$logStatus = $status === 'error' ? 'error' : ($status === 'completed' ? 'success' : 'info');
$logMessage = $message;
if ($runUrl !== '') {
    $logMessage .= ' (' . $runUrl . ')';
}
$pdo->prepare("
    INSERT INTO automation_logs (automation_id, action, status, message)
    VALUES (?, 'github_callback', ?, ?)
")->execute([$automationId, $logStatus, $logMessage]);

echo json_encode([
    'success' => true,
    'automation_id' => $automationId,
    'status' => $status
]);

