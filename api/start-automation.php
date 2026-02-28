<?php
/**
 * Start Automation
 * - local mode: existing background queue logic
 * - github_runner mode: dispatches GitHub Actions workflow
 */

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/GitHubRunner.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Config error: ' . $e->getMessage()]);
    exit;
}

if (!isset($pdo)) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

function calculateNextRunAtStart(string $scheduleType, int $scheduleHour, int $scheduleEveryMinutes = 10): string
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

$automationId = $_GET['id'] ?? $_POST['id'] ?? null;
if (!$automationId) {
    echo json_encode(['success' => false, 'error' => 'No automation ID']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, name, status, enabled, run_mode, schedule_type, schedule_hour, schedule_every_minutes
    FROM automation_settings
    WHERE id = ?
");
$stmt->execute([$automationId]);
$automation = $stmt->fetch();

if (!$automation) {
    echo json_encode(['success' => false, 'error' => 'Automation not found']);
    exit;
}

if ($automation['status'] === 'processing') {
    echo json_encode(['success' => false, 'error' => 'Already running']);
    exit;
}

if ($automation['status'] === 'queued') {
    echo json_encode(['success' => false, 'error' => 'Already in queue']);
    exit;
}

$runMode = $automation['run_mode'] ?? 'local';

if ($runMode === 'github_runner') {
    $startPayload = json_encode([
        'step' => 'github_runner',
        'status' => 'info',
        'message' => 'Dispatching GitHub workflow...',
        'time' => date('H:i:s')
    ]);
    $pdo->prepare("
        UPDATE automation_settings
        SET status = 'processing',
            progress_percent = 5,
            progress_data = ?,
            last_progress_time = NOW()
        WHERE id = ?
    ")->execute([$startPayload, $automationId]);

    $runner = new GitHubRunner($pdo);
    $dispatch = $runner->dispatchAutomation((int)$automationId, 'manual_run');

    if (!$dispatch['success']) {
        $errorPayload = json_encode([
            'step' => 'github_runner',
            'status' => 'error',
            'message' => $dispatch['error'] ?? 'GitHub dispatch failed',
            'time' => date('H:i:s')
        ]);
        $pdo->prepare("
            UPDATE automation_settings
            SET status = 'error',
                progress_percent = 0,
                progress_data = ?,
                last_progress_time = NOW()
            WHERE id = ?
        ")->execute([$errorPayload, $automationId]);
        $pdo->prepare("INSERT INTO automation_logs (automation_id, action, status, message) VALUES (?, 'github_dispatch', 'error', ?)")
            ->execute([$automationId, $dispatch['error'] ?? 'Dispatch failed']);

        echo json_encode([
            'success' => false,
            'error' => $dispatch['error'] ?? 'GitHub dispatch failed'
        ]);
        exit;
    }

    $nextRunAt = null;
    if ((int)($automation['enabled'] ?? 0) === 1) {
        $nextRunAt = calculateNextRunAtStart(
            (string)($automation['schedule_type'] ?? 'daily'),
            (int)($automation['schedule_hour'] ?? 9),
            (int)($automation['schedule_every_minutes'] ?? 10)
        );
    }

    $successPayload = json_encode([
        'step' => 'github_runner',
        'status' => 'success',
        'message' => 'GitHub workflow dispatched successfully.',
        'progress' => 15,
        'stats' => ['fetched' => 0, 'downloaded' => 0, 'processed' => 0, 'scheduled' => 0, 'posted' => 0],
        'outputs' => [],
        'run_id' => $dispatch['run_id'] ?? null,
        'run_url' => $dispatch['run_url'] ?? null,
        'workflow_url' => $dispatch['workflow_url'] ?? null,
        'time' => date('H:i:s')
    ]);

    $pdo->prepare("
        UPDATE automation_settings
        SET status = 'processing',
            progress_percent = 15,
            progress_data = ?,
            last_progress_time = NOW(),
            next_run_at = COALESCE(?, next_run_at)
        WHERE id = ?
    ")->execute([$successPayload, $nextRunAt, $automationId]);

    $logMsg = 'GitHub workflow dispatched';
    if (!empty($dispatch['run_url'])) {
        $logMsg .= ': ' . $dispatch['run_url'];
    }
    $pdo->prepare("INSERT INTO automation_logs (automation_id, action, status, message) VALUES (?, 'github_dispatch', 'success', ?)")
        ->execute([$automationId, $logMsg]);

    echo json_encode([
        'success' => true,
        'mode' => 'github_runner',
        'status' => 'processing',
        'message' => 'Dispatched to GitHub runner.',
        'run_url' => $dispatch['run_url'] ?? null,
        'workflow_url' => $dispatch['workflow_url'] ?? null,
        'run_id' => $dispatch['run_id'] ?? null,
        'automationId' => $automationId
    ]);
    exit;
}

// Local mode queue system (existing behavior)
$stmt = $pdo->query("SELECT id, name FROM automation_settings WHERE status = 'processing' LIMIT 1");
$runningAutomation = $stmt->fetch();

if ($runningAutomation) {
    $stmt = $pdo->prepare("UPDATE automation_settings SET status = 'queued', progress_percent = 0 WHERE id = ?");
    $stmt->execute([$automationId]);

    echo json_encode([
        'success' => true,
        'mode' => 'local',
        'queued' => true,
        'status' => 'queued',
        'message' => "Added to queue. Waiting for '{$runningAutomation['name']}' to complete.",
        'automationId' => $automationId
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE automation_settings SET status = 'processing', progress_percent = 0, progress_data = NULL WHERE id = ?");
    $stmt->execute([$automationId]);
} catch (Exception $e) {
    $stmt = $pdo->prepare("UPDATE automation_settings SET status = 'processing' WHERE id = ?");
    $stmt->execute([$automationId]);
}

$scriptPath = realpath(__DIR__ . '/../run-background.php');
$logsDir = realpath(__DIR__ . '/../logs') ?: __DIR__ . '/../logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0777, true);
}

if (PHP_OS_FAMILY === 'Windows') {
    $phpPath = PHP_BINARY;

    if (!$phpPath || !file_exists($phpPath)) {
        $possiblePaths = [
            'C:\\xampp\\php\\php.exe',
            'C:\\xampp64\\php\\php.exe',
            'D:\\xampp\\php\\php.exe'
        ];
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $phpPath = $path;
                break;
            }
        }
    }

    $logFile = $logsDir . '\\process-output.log';
    $cmd = 'start /B "" "' . $phpPath . '" "' . $scriptPath . '" ' . $automationId . ' > "' . $logFile . '" 2>&1';
    file_put_contents($logsDir . '/start-cmd.log', date('Y-m-d H:i:s') . " - Command: $cmd\n", FILE_APPEND);
    pclose(popen($cmd, 'r'));
} else {
    $phpPath = PHP_BINARY ?: '/usr/bin/php';
    $cmd = "nohup {$phpPath} \"{$scriptPath}\" {$automationId} > /dev/null 2>&1 &";
    exec($cmd);
}

echo json_encode([
    'success' => true,
    'mode' => 'local',
    'status' => 'processing',
    'message' => 'Automation started in background',
    'automationId' => $automationId
]);
