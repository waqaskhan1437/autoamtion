<?php

function remoteExecutionPersistAutomationLogs(PDO $pdo, int $automationId, array $entries): void
{
    if ($automationId <= 0 || empty($entries)) {
        return;
    }

    $select = $pdo->prepare("
        SELECT id
        FROM automation_logs
        WHERE automation_id = ?
          AND action = ?
          AND status = ?
          AND COALESCE(message, '') = ?
          AND COALESCE(video_id, '') = ?
          AND COALESCE(platform, '') = ?
        LIMIT 1
    ");
    $insert = $pdo->prepare("
        INSERT INTO automation_logs (automation_id, action, status, message, video_id, platform)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $action = trim((string)($entry['action'] ?? 'remote_sync'));
        $status = strtolower(trim((string)($entry['status'] ?? 'info')));
        $message = trim((string)($entry['message'] ?? ''));
        $videoId = trim((string)($entry['video_id'] ?? ''));
        $platform = trim((string)($entry['platform'] ?? ''));

        if ($action === '' || $message === '') {
            continue;
        }
        if (!in_array($status, ['success', 'error', 'info'], true)) {
            $status = 'info';
        }

        try {
            $select->execute([$automationId, $action, $status, $message, $videoId, $platform]);
            if ($select->fetchColumn()) {
                continue;
            }

            $insert->execute([
                $automationId,
                $action,
                $status,
                $message,
                $videoId !== '' ? $videoId : null,
                $platform !== '' ? $platform : null
            ]);
        } catch (Exception $e) {
        }
    }
}

function remoteExecutionPersistProcessedVideos(PDO $pdo, int $automationId, array $records): void
{
    if ($automationId <= 0 || empty($records)) {
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO processed_videos (automation_id, video_identifier, video_filename, file_size, content_hash, cycle_number, processed_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            video_filename = VALUES(video_filename),
            file_size = VALUES(file_size),
            content_hash = COALESCE(NULLIF(VALUES(content_hash), ''), content_hash),
            processed_at = NOW()
    ");

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $identifier = trim((string)($record['video_identifier'] ?? ''));
        if ($identifier === '') {
            continue;
        }

        $filename = trim((string)($record['video_filename'] ?? $identifier));
        $fileSize = max(0, (int)($record['file_size'] ?? 0));
        $contentHash = trim((string)($record['content_hash'] ?? ''));
        $cycleNumber = max(1, (int)($record['cycle_number'] ?? 1));

        try {
            $insert->execute([
                $automationId,
                $identifier,
                $filename !== '' ? $filename : null,
                $fileSize,
                $contentHash !== '' ? $contentHash : null,
                $cycleNumber
            ]);
        } catch (Exception $e) {
        }
    }
}

function remoteExecutionPersistPostForMePosts(PDO $pdo, int $automationId, array $posts): void
{
    if ($automationId <= 0 || empty($posts)) {
        return;
    }

    $select = $pdo->prepare("SELECT id FROM postforme_posts WHERE post_id = ? LIMIT 1");
    $insert = $pdo->prepare("
        INSERT INTO postforme_posts (post_id, automation_id, video_id, video_path, caption, account_ids, status, scheduled_at, published_at, error_message, results)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $update = $pdo->prepare("
        UPDATE postforme_posts
        SET automation_id = ?,
            video_id = COALESCE(?, video_id),
            video_path = COALESCE(?, video_path),
            caption = COALESCE(?, caption),
            account_ids = COALESCE(?, account_ids),
            status = ?,
            scheduled_at = COALESCE(?, scheduled_at),
            published_at = COALESCE(?, published_at),
            error_message = COALESCE(?, error_message),
            results = COALESCE(?, results)
        WHERE id = ?
    ");

    foreach ($posts as $post) {
        if (!is_array($post)) {
            continue;
        }

        $postId = trim((string)($post['post_id'] ?? ''));
        if ($postId === '') {
            continue;
        }

        $videoId = trim((string)($post['video_id'] ?? ''));
        $videoPath = trim((string)($post['video_path'] ?? ''));
        $caption = isset($post['caption']) ? (string)$post['caption'] : null;
        $accountIds = $post['account_ids'] ?? null;
        if (is_array($accountIds)) {
            $accountIds = json_encode(array_values($accountIds), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif ($accountIds !== null) {
            $accountIds = trim((string)$accountIds);
        }

        $status = strtolower(trim((string)($post['status'] ?? 'pending')));
        if (!in_array($status, ['pending', 'scheduled', 'posted', 'failed', 'cancelled'], true)) {
            $status = 'pending';
        }

        $scheduledAt = trim((string)($post['scheduled_at'] ?? ''));
        $publishedAt = trim((string)($post['published_at'] ?? ''));
        $errorMessage = trim((string)($post['error_message'] ?? ''));
        $results = $post['results'] ?? null;
        if (is_array($results)) {
            $results = json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif ($results !== null) {
            $results = trim((string)$results);
        }

        try {
            $select->execute([$postId]);
            $existingId = (int)($select->fetchColumn() ?: 0);

            if ($existingId > 0) {
                $update->execute([
                    $automationId,
                    $videoId !== '' ? $videoId : null,
                    $videoPath !== '' ? $videoPath : null,
                    $caption,
                    ($accountIds !== '' ? $accountIds : null),
                    $status,
                    ($scheduledAt !== '' ? $scheduledAt : null),
                    ($publishedAt !== '' ? $publishedAt : null),
                    ($errorMessage !== '' ? $errorMessage : null),
                    ($results !== '' ? $results : null),
                    $existingId
                ]);
            } else {
                $insert->execute([
                    $postId,
                    $automationId,
                    $videoId !== '' ? $videoId : null,
                    $videoPath !== '' ? $videoPath : null,
                    $caption,
                    ($accountIds !== '' ? $accountIds : null),
                    $status,
                    ($scheduledAt !== '' ? $scheduledAt : null),
                    ($publishedAt !== '' ? $publishedAt : null),
                    ($errorMessage !== '' ? $errorMessage : null),
                    ($results !== '' ? $results : null)
                ]);
            }
        } catch (Exception $e) {
        }
    }
}

function remoteExecutionPersistStructuredPayload(PDO $pdo, int $automationId, array $payload): void
{
    if ($automationId <= 0) {
        return;
    }

    if (!empty($payload['facebook_scheduled_jobs']) && is_array($payload['facebook_scheduled_jobs'])) {
        require_once __DIR__ . '/FacebookScheduledPostQueue.php';
        FacebookScheduledPostQueue::persistCallbackJobs($pdo, $automationId, $payload['facebook_scheduled_jobs']);
    }

    if (!empty($payload['processed_video_records']) && is_array($payload['processed_video_records'])) {
        remoteExecutionPersistProcessedVideos($pdo, $automationId, $payload['processed_video_records']);
    }

    if (!empty($payload['postforme_posts']) && is_array($payload['postforme_posts'])) {
        remoteExecutionPersistPostForMePosts($pdo, $automationId, $payload['postforme_posts']);
    }

    if (!empty($payload['automation_log_entries']) && is_array($payload['automation_log_entries'])) {
        remoteExecutionPersistAutomationLogs($pdo, $automationId, $payload['automation_log_entries']);
    }
}

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
    remoteExecutionPersistStructuredPayload($pdo, $automationId, $payload);

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
