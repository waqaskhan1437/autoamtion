<?php

function remoteExecutionNextRun(string $scheduleType, int $scheduleHour, int $scheduleEveryMinutes = 10): string
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

function remoteExecutionNormalizeStatus(string $status): string
{
    $status = strtolower(trim($status));
    $allowedStatuses = ['running', 'processing', 'queued', 'completed', 'error', 'stopped'];
    return in_array($status, $allowedStatuses, true) ? $status : 'running';
}

function remoteExecutionVisualStatus(string $status, string $eventStatus = ''): string
{
    if ($status === 'error') {
        return 'error';
    }
    if ($status === 'completed') {
        return 'success';
    }

    $eventStatus = strtolower(trim($eventStatus));
    return in_array($eventStatus, ['success', 'warning', 'error', 'info'], true) ? $eventStatus : 'info';
}

function remoteExecutionApplyProgress(PDO $pdo, int $automationId, array $payload, string $action = 'remote_callback'): array
{
    $status = remoteExecutionNormalizeStatus((string)($payload['status'] ?? 'running'));
    $progress = max(0, min(100, (int)($payload['progress'] ?? 0)));
    $message = trim((string)($payload['message'] ?? 'Remote execution update received.'));
    $step = trim((string)($payload['step'] ?? 'remote_runner'));
    $runUrl = trim((string)($payload['run_url'] ?? ''));
    $stats = isset($payload['stats']) && is_array($payload['stats']) ? $payload['stats'] : [];
    $outputs = isset($payload['outputs']) && is_array($payload['outputs']) ? array_values($payload['outputs']) : [];
    $eventStatus = strtolower(trim((string)($payload['event_status'] ?? '')));
    $sequence = isset($payload['sequence']) ? (int)$payload['sequence'] : 0;

    $stmt = $pdo->prepare("
        SELECT enabled, schedule_type, schedule_hour, schedule_every_minutes, next_run_at
        FROM automation_settings
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$automationId]);
    $automation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$automation) {
        return ['success' => false, 'error' => 'Automation not found'];
    }

    $progressData = [
        'step' => $step,
        'status' => remoteExecutionVisualStatus($status, $eventStatus),
        'message' => $message,
        'progress' => $progress,
        'run_url' => ($runUrl !== '' ? $runUrl : null),
        'stats' => $stats,
        'outputs' => $outputs,
        'sequence' => $sequence,
        'time' => date('H:i:s')
    ];

    $nextRunAt = $automation['next_run_at'] ?? null;
    if (in_array($status, ['completed', 'error'], true) && (int)$automation['enabled'] === 1) {
        $currentNextRunTs = !empty($nextRunAt) ? strtotime((string)$nextRunAt) : false;
        if ($currentNextRunTs === false || $currentNextRunTs <= time()) {
            $nextRunAt = remoteExecutionNextRun(
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
        json_encode($progressData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
        VALUES (?, ?, ?, ?)
    ")->execute([$automationId, $action, $logStatus, $logMessage]);

    return [
        'success' => true,
        'status' => $status,
        'next_run_at' => $nextRunAt,
        'progress_data' => $progressData
    ];
}
