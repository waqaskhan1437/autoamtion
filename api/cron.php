<?php
/**
 * Cron job handler
 * Run this file periodically to process scheduled automations AND sync status
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/AutomationRunner.php';
require_once __DIR__ . '/../includes/PostForMeAPI.php';
require_once __DIR__ . '/../includes/GitHubRunner.php';

// Force DB session timezone to match PHP timezone for consistent TIMESTAMP behavior.
try {
    $cronTzOffset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
    $pdo->exec("SET time_zone = " . $pdo->quote($cronTzOffset));
} catch (Exception $e) {
    // Keep going with DB default timezone if this fails.
}

$logFile = __DIR__ . '/../logs/cron_' . date('Y-m-d') . '.log';
$logsDir = dirname($logFile);
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0777, true);
}

function cronLog($message)
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    if (php_sapi_name() === 'cli') {
        echo "[{$timestamp}] {$message}\n";
    }
}

cronLog("=== Cron job started ===");

function calculateNextRunAtForCron($scheduleType, $scheduleHour, $scheduleEveryMinutes = 10)
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

function mapCronProgressPercentFromAction(string $action): int
{
    $map = [
        'run_started' => 3,
        'fetch' => 12,
        'videos_fetched' => 20,
        'rotation_filter' => 25,
        'batch' => 30,
        'batch_shuffle' => 32,
        'processing_video' => 45,
        'download' => 50,
        'ai_tagline' => 56,
        'local_tagline' => 58,
        'whisper_start' => 55,
        'whisper_complete' => 62,
        'ffmpeg_start' => 68,
        'ffmpeg_segment' => 72,
        'short_created' => 78,
        'posting' => 90,
        'video_completed' => 85,
        'postforme_start' => 88,
        'postforme_schedule' => 90,
        'postforme_success' => 94,
        'run_completed' => 100,
    ];

    return $map[$action] ?? 40;
}

function mapCronStepFromAction(string $action): string
{
    $map = [
        'run_started' => 'init',
        'fetch' => 'fetch',
        'videos_fetched' => 'fetch',
        'rotation_filter' => 'rotation',
        'batch' => 'rotation',
        'batch_shuffle' => 'rotation',
        'processing_video' => 'process',
        'download' => 'download',
        'ai_tagline' => 'ai',
        'local_tagline' => 'ai',
        'whisper_start' => 'whisper',
        'whisper_complete' => 'whisper',
        'ffmpeg_start' => 'ffmpeg',
        'ffmpeg_segment' => 'ffmpeg',
        'short_created' => 'process',
        'posting' => 'posting',
        'postforme_start' => 'posting',
        'postforme_schedule' => 'posting',
        'postforme_success' => 'posting',
        'run_completed' => 'complete',
    ];

    return $map[$action] ?? 'cron';
}

try {
    $resetStmt = $pdo->query("
        UPDATE automation_settings
        SET status = 'inactive', progress_percent = 0, progress_data = NULL
        WHERE status IN ('running', 'processing')
        AND last_run_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    if ($resetStmt->rowCount() > 0) {
        cronLog("FIXED: Reset " . $resetStmt->rowCount() . " stuck automations.");
    }
} catch (Exception $e) {
    cronLog("Fix Error: " . $e->getMessage());
}

try {
    cronLog("Checking for completed posts...");
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key'");
    $apiKey = $stmt->fetchColumn();

    if ($apiKey) {
        $pfApi = new PostForMeAPI($apiKey);
        $stmt = $pdo->query("
            SELECT id, post_id, status
            FROM postforme_posts
            WHERE status IN ('pending', 'scheduled', 'partial')
            AND post_id IS NOT NULL
            ORDER BY created_at ASC
            LIMIT 20
        ");
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($posts as $post) {
            $res = $pfApi->getPost($post['post_id']);
            if ($res['success'] && isset($res['post']['status'])) {
                $remoteStatus = strtolower($res['post']['status']);
                $newStatus = null;

                if (in_array($remoteStatus, ['published', 'posted', 'completed', 'success', 'processed'], true)) {
                    $newStatus = 'posted';
                } elseif (in_array($remoteStatus, ['failed', 'error', 'cancelled'], true)) {
                    $newStatus = 'failed';
                }

                if ($newStatus && $newStatus !== $post['status']) {
                    $pdo->prepare("UPDATE postforme_posts SET status = ?, published_at = NOW() WHERE id = ?")
                        ->execute([$newStatus, $post['id']]);
                    cronLog("Synced Post #{$post['post_id']}: {$post['status']} -> {$newStatus}");
                }
            }
        }
    }
} catch (Exception $e) {
    cronLog("Sync Error: " . $e->getMessage());
}

$processedCount = 0;
$automationsRun = [];

try {
    $githubRunner = new GitHubRunner($pdo);

    $stmt = $pdo->query("
        SELECT id, name, run_mode, schedule_type, schedule_hour, schedule_every_minutes, next_run_at
        FROM automation_settings
        WHERE enabled = 1
        AND status NOT IN ('processing', 'queued')
    ");
    $automations = $stmt->fetchAll();

    foreach ($automations as $automation) {
        // Timezone-safe due check in PHP (avoids MySQL NOW()/session timezone mismatch)
        $nextRunRaw = $automation['next_run_at'] ?? null;
        $nextRunTs = !empty($nextRunRaw) ? strtotime((string)$nextRunRaw) : false;
        if ($nextRunTs !== false && $nextRunTs > time()) {
            continue;
        }

        cronLog("Running automation: {$automation['name']} (#{$automation['id']})");
        try {
            // Atomic claim to prevent duplicate runs from concurrent cron hits.
            $claimStmt = $pdo->prepare("
                UPDATE automation_settings
                SET status = 'processing',
                    progress_percent = 0,
                    progress_data = ?,
                    last_progress_time = NOW()
                WHERE id = ?
                  AND enabled = 1
                  AND status NOT IN ('processing', 'queued')
            ");
            $claimPayload = json_encode([
                'step' => 'cron',
                'status' => 'info',
                'message' => 'Cron picked this automation for processing',
                'time' => date('H:i:s')
            ]);
            $claimStmt->execute([$claimPayload, $automation['id']]);

            if ($claimStmt->rowCount() === 0) {
                cronLog("SKIP: Automation #{$automation['id']} already claimed by another worker.");
                continue;
            }

            if (($automation['run_mode'] ?? 'local') === 'github_runner') {
                $dispatch = $githubRunner->dispatchAutomation((int)$automation['id'], 'cron_schedule');
                if (!$dispatch['success']) {
                    throw new Exception($dispatch['error'] ?? 'GitHub dispatch failed in cron');
                }

                $nextRunAt = calculateNextRunAtForCron(
                    $automation['schedule_type'] ?? 'daily',
                    $automation['schedule_hour'] ?? 9,
                    $automation['schedule_every_minutes'] ?? 10
                );

                $progressPayload = json_encode([
                    'step' => 'github_runner',
                    'status' => 'success',
                    'message' => 'Dispatched to GitHub runner from cron.',
                    'progress' => 15,
                    'stats' => ['fetched' => 0, 'downloaded' => 0, 'processed' => 0, 'scheduled' => 0, 'posted' => 0],
                    'run_id' => $dispatch['run_id'] ?? null,
                    'run_url' => $dispatch['run_url'] ?? null,
                    'workflow_url' => $dispatch['workflow_url'] ?? null,
                    'time' => date('H:i:s')
                ]);

                $pdo->prepare("
                    UPDATE automation_settings
                    SET status = 'running',
                        next_run_at = ?,
                        last_run_at = NOW(),
                        progress_percent = 15,
                        progress_data = ?,
                        last_progress_time = NOW()
                    WHERE id = ?
                ")->execute([$nextRunAt, $progressPayload, $automation['id']]);

                $logMsg = 'Cron dispatched GitHub workflow';
                if (!empty($dispatch['run_url'])) {
                    $logMsg .= ': ' . $dispatch['run_url'];
                }
                $pdo->prepare("
                    INSERT INTO automation_logs (automation_id, action, status, message)
                    VALUES (?, 'github_dispatch', 'success', ?)
                ")->execute([$automation['id'], $logMsg]);

                $automationsRun[] = [
                    'id' => $automation['id'],
                    'name' => $automation['name'],
                    'processed' => 0,
                    'mode' => 'github_runner'
                ];
                continue;
            }

            $runner = new AutomationRunner($pdo, $automation['id']);
            $liveStats = [
                'fetched' => 0,
                'downloaded' => 0,
                'processed' => 0,
                'scheduled' => 0,
                'posted' => 0
            ];
            $runner->setLogCallback(function ($action, $status, $message) use ($pdo, $automation, &$liveStats) {
                try {
                    $action = (string)$action;
                    $status = (string)$status;
                    $message = (string)$message;

                    if ($action === 'videos_fetched') {
                        if (preg_match('/(\d+)/', $message, $m)) {
                            $liveStats['fetched'] = (int)$m[1];
                        }
                    } elseif ($action === 'rotation_filter') {
                        if (preg_match('/,\s*(\d+)\s+remaining/i', $message, $m)) {
                            $liveStats['fetched'] = (int)$m[1];
                        }
                    } elseif ($action === 'short_created') {
                        $liveStats['downloaded']++;
                        $liveStats['processed']++;
                    } elseif ($action === 'postforme_success') {
                        if (stripos($message, 'scheduled:') !== false) {
                            $liveStats['scheduled']++;
                        } else {
                            $liveStats['posted']++;
                        }
                    }

                    $percent = mapCronProgressPercentFromAction((string)$action);
                    $payload = json_encode([
                        'step' => mapCronStepFromAction($action),
                        'status' => $status,
                        'message' => $message,
                        'progress' => $percent,
                        'stats' => $liveStats,
                        'time' => date('H:i:s')
                    ]);
                    $stmt = $pdo->prepare("
                        UPDATE automation_settings
                        SET progress_percent = ?,
                            progress_data = ?,
                            last_progress_time = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$percent, $payload, $automation['id']]);
                } catch (Exception $e) {
                    // Non-fatal; cron run should continue even if progress write fails.
                }
            });
            $result = $runner->run();

            $processedCount += $result['processed'] ?? 0;
            $automationsRun[] = [
                'id' => $automation['id'],
                'name' => $automation['name'],
                'processed' => $result['processed'] ?? 0
            ];

            $nextRunAt = calculateNextRunAtForCron(
                $automation['schedule_type'] ?? 'daily',
                $automation['schedule_hour'] ?? 9,
                $automation['schedule_every_minutes'] ?? 10
            );

            $runStats = [
                'fetched' => (int)($result['fetched'] ?? 0),
                'downloaded' => (int)($result['downloaded'] ?? 0),
                'processed' => (int)($result['processed'] ?? 0),
                'scheduled' => (int)($result['scheduled'] ?? 0),
                'posted' => (int)($result['posted'] ?? 0)
            ];
            $completeProgress = json_encode([
                'step' => 'complete',
                'status' => 'success',
                'message' => "Cron run completed. Processed {$runStats['processed']} video(s).",
                'progress' => 100,
                'stats' => $runStats,
                'time' => date('H:i:s')
            ]);

            $pdo->prepare("
                UPDATE automation_settings
                SET status = 'running',
                    next_run_at = ?,
                    last_run_at = NOW(),
                    progress_percent = 100,
                    progress_data = ?,
                    last_progress_time = NOW()
                WHERE id = ?
            ")->execute([$nextRunAt, $completeProgress, $automation['id']]);
        } catch (Exception $e) {
            cronLog("ERROR running automation: " . $e->getMessage());
            $errorNextRunAt = calculateNextRunAtForCron(
                $automation['schedule_type'] ?? 'daily',
                $automation['schedule_hour'] ?? 9,
                $automation['schedule_every_minutes'] ?? 10
            );
            $errorProgress = json_encode([
                'step' => 'cron',
                'status' => 'error',
                'message' => 'Automation failed in cron: ' . $e->getMessage(),
                'time' => date('H:i:s')
            ]);
            try {
                $pdo->prepare("
                    UPDATE automation_settings
                    SET status = 'error',
                        next_run_at = ?,
                        last_run_at = NOW(),
                        progress_data = ?,
                        last_progress_time = NOW()
                    WHERE id = ?
                ")->execute([$errorNextRunAt, $errorProgress, $automation['id']]);
                $pdo->prepare("
                    INSERT INTO automation_logs (automation_id, action, status, message)
                    VALUES (?, 'cron_error', 'error', ?)
                ")->execute([$automation['id'], $e->getMessage()]);
            } catch (Exception $inner) {
                cronLog("ERROR writing cron failure state: " . $inner->getMessage());
            }
        }
    }
} catch (Exception $e) {
    cronLog("FATAL ERROR: " . $e->getMessage());
}

cronLog("=== Cron job completed ===\n");

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'completed',
        'automations_run' => $automationsRun,
        'total_processed' => $processedCount,
        'timestamp' => date('Y-m-d H:i:s'),
        'log' => basename($logFile)
    ]);
}
?>
