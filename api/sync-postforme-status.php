<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/PostForMeAPI.php';

header('Content-Type: application/json');

function vwm_now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function vwm_sync_info_log(array $payload): void
{
    $logFile = __DIR__ . '/../logs/sync_postforme_info_' . date('Y-m-d') . '.log';
    $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function vwm_extract_account_ids_from_remote_post(array $post): array
{
    $socialAccounts = $post['social_accounts'] ?? $post['socialAccounts'] ?? $post['accounts'] ?? [];
    $ids = [];
    if (is_array($socialAccounts)) {
        foreach ($socialAccounts as $acc) {
            if (is_array($acc)) {
                $aid = $acc['id'] ?? $acc['account_id'] ?? $acc['social_account_id'] ?? null;
                if (!empty($aid)) {
                    $ids[] = (string)$aid;
                }
            } elseif (!empty($acc)) {
                $ids[] = (string)$acc;
            }
        }
    }
    return array_values(array_unique($ids));
}

try {
    $automationId = isset($_GET['automation_id']) ? (int)$_GET['automation_id'] : 0;
    if ($automationId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing automation_id']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key' LIMIT 1");
    $stmt->execute();
    $apiKey = (string)($stmt->fetchColumn() ?: '');

    if ($apiKey === '') {
        echo json_encode(['ok' => false, 'error' => 'PostForMe API key not configured']);
        exit;
    }

    $postForMe = new PostForMeAPI($apiKey);

    // Build a strict tracked-post set for this automation.
    // We use this for authoritative remote scheduled counts to avoid
    // cross-automation contamination when accounts overlap.
    $trackedPostIds = [];
    $stmtTracked = $pdo->prepare("
        SELECT post_id
        FROM postforme_posts
        WHERE automation_id = ?
          AND post_id IS NOT NULL
    ");
    $stmtTracked->execute([$automationId]);
    foreach ($stmtTracked->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $pid = (string)$pid;
        if ($pid !== '') {
            $trackedPostIds[$pid] = true;
        }
    }

    // Fallback: older runs may have missed postforme_posts inserts.
    // Recover known post IDs from automation logs.
    if (empty($trackedPostIds)) {
        try {
            $stmtLog = $pdo->prepare("
                SELECT message
                FROM automation_logs
                WHERE automation_id = ?
                  AND action IN ('postforme_success', 'postforme_debug')
                ORDER BY created_at DESC
                LIMIT 300
            ");
            $stmtLog->execute([$automationId]);
            foreach ($stmtLog->fetchAll(PDO::FETCH_COLUMN) as $msg) {
                $text = (string)$msg;
                if (preg_match_all('/sp_[A-Za-z0-9]+/', $text, $m)) {
                    foreach (($m[0] ?? []) as $pid) {
                        $trackedPostIds[(string)$pid] = true;
                    }
                }
            }
        } catch (Throwable $e) {
            // keep empty fallback
        }
    }

    $stmt = $pdo->prepare("
        SELECT id, post_id, status, scheduled_at
        FROM postforme_posts
        WHERE automation_id = ?
          AND post_id IS NOT NULL
          AND status IN ('pending', 'scheduled', 'partial')
          AND scheduled_at IS NOT NULL
          AND scheduled_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 12 HOUR)
        ORDER BY scheduled_at ASC
        LIMIT 50
    ");
    $stmt->execute([$automationId]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    $checked = count($posts);

    foreach ($posts as $row) {
        $postId = (string)$row['post_id'];
        if ($postId === '') {
            continue;
        }

        $postResp = $postForMe->getPost($postId);
        $resultsResp = $postForMe->getPostResults($postId);
        $hasResults = is_array($resultsResp) && !empty($resultsResp['success']) && is_array($resultsResp['data']) && count($resultsResp['data']) > 0;

        $isPosted = false;
        $publishedAt = null;
        $errorMessage = null;
        $remoteOverallStatus = '';
        $hasPostedLikeResult = false;
        $hasFailedLikeResult = false;

        if ($hasResults) {
            foreach ($resultsResp['data'] as $resultRow) {
                if (!is_array($resultRow)) {
                    continue;
                }

                $publishedAt = $resultRow['published_at'] ?? $resultRow['publishedAt'] ?? $publishedAt;
                $errorMessage = $resultRow['error'] ?? $resultRow['error_message'] ?? $errorMessage;
                $resultStatus = strtolower((string)($resultRow['status'] ?? $resultRow['state'] ?? ''));

                if (in_array($resultStatus, ['posted', 'published', 'completed', 'success', 'processed'], true) || !empty($resultRow['published_at']) || !empty($resultRow['publishedAt'])) {
                    $hasPostedLikeResult = true;
                }
                if (in_array($resultStatus, ['failed', 'error', 'cancelled', 'canceled'], true)) {
                    $hasFailedLikeResult = true;
                }
            }
        }

        if (is_array($postResp) && !empty($postResp['success']) && is_array($postResp['data'])) {
            $remoteOverallStatus = strtolower((string)($postResp['data']['status'] ?? $postResp['data']['state'] ?? ''));
            $publishedAt = $postResp['data']['published_at'] ?? $postResp['data']['publishedAt'] ?? $publishedAt;
            $errorMessage = $postResp['data']['error'] ?? $postResp['data']['error_message'] ?? $errorMessage;

            if (in_array($remoteOverallStatus, ['posted', 'published', 'completed', 'success', 'processed'], true) || !empty($publishedAt)) {
                $isPosted = true;
            } elseif (in_array($remoteOverallStatus, ['failed', 'error', 'cancelled', 'canceled'], true)) {
                $stmtUp = $pdo->prepare("UPDATE postforme_posts SET status='failed', error_message=COALESCE(error_message, ?) WHERE id=?");
                $stmtUp->execute([$errorMessage, (int)$row['id']]);
                $updated++;
                continue;
            }
        } else {
            if ($hasPostedLikeResult) {
                $isPosted = true;
            } elseif ($hasFailedLikeResult) {
                $stmtUp = $pdo->prepare("UPDATE postforme_posts SET status='failed', error_message=COALESCE(error_message, ?) WHERE id=?");
                $stmtUp->execute([$errorMessage, (int)$row['id']]);
                $updated++;
                continue;
            }
        }

        if ($isPosted) {
            $stmtUp = $pdo->prepare("UPDATE postforme_posts SET status='posted', published_at = COALESCE(published_at, ?) WHERE id=?");
            $stmtUp->execute([vwm_now_utc(), (int)$row['id']]);
            $updated++;
        }
    }

    $stmtCounts = $pdo->prepare("
        SELECT
          SUM(CASE WHEN status IN ('pending','scheduled','partial','queued','processing') THEN 1 ELSE 0 END) AS scheduled,
          SUM(CASE WHEN status='posted' OR published_at IS NOT NULL THEN 1 ELSE 0 END) AS posted,
          SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
          COUNT(*) AS tracked_total
        FROM postforme_posts
        WHERE automation_id = ?
    ");
    $stmtCounts->execute([$automationId]);
    $counts = $stmtCounts->fetch(PDO::FETCH_ASSOC) ?: ['scheduled' => 0, 'posted' => 0, 'failed' => 0, 'tracked_total' => 0];

    $remoteScheduled = null;
    if (!empty($trackedPostIds)) {
        try {
            $remoteScheduled = 0;
            foreach ([1, 2, 3] as $page) {
                $listResp = $postForMe->listPosts([
                    'status' => 'scheduled',
                    'page' => $page,
                    'per_page' => 100
                ]);
                if (empty($listResp['success']) || empty($listResp['posts']) || !is_array($listResp['posts'])) {
                    break;
                }

                foreach ($listResp['posts'] as $rp) {
                    if (!is_array($rp)) {
                        continue;
                    }
                    $status = strtolower((string)($rp['status'] ?? $rp['state'] ?? ''));
                    if (!in_array($status, ['scheduled', 'pending', 'partial', 'queued', 'processing'], true)) {
                        continue;
                    }
                    $remotePostId = (string)($rp['id'] ?? $rp['post_id'] ?? '');
                    if ($remotePostId !== '' && isset($trackedPostIds[$remotePostId])) {
                        $remoteScheduled++;
                    }
                }
            }
        } catch (Throwable $e) {
            $remoteScheduled = null;
        }
    } else {
        // No tracked posts for this automation yet -> authoritative remote scheduled is 0 for this card.
        $remoteScheduled = 0;
    }

    // Scheduled count should be authoritative from PostForMe live API.
    // If API is unavailable, return source as unavailable and let UI keep current value.
    $finalScheduled = ($remoteScheduled !== null) ? (int)$remoteScheduled : 0;
    $scheduledSource = ($remoteScheduled !== null) ? 'remote' : 'unavailable';

    $response = [
        'ok' => true,
        'updated' => (int)$updated,
        'counts' => [
            'scheduled' => $finalScheduled,
            'scheduled_db' => (int)($counts['scheduled'] ?? 0),
            'scheduled_remote' => $remoteScheduled,
            'scheduled_source' => $scheduledSource,
            'api_ok' => ($remoteScheduled !== null),
            'posted' => (int)($counts['posted'] ?? 0),
            'failed' => (int)($counts['failed'] ?? 0),
            'tracked_total' => (int)($counts['tracked_total'] ?? 0),
        ],
    ];

    vwm_sync_info_log([
        'automation_id' => $automationId,
        'checked' => $checked,
        'updated' => (int)$updated,
        'counts' => $response['counts'],
    ]);

    echo json_encode($response);
} catch (Throwable $e) {
    vwm_sync_info_log([
        'automation_id' => isset($automationId) ? (int)$automationId : 0,
        'error' => $e->getMessage(),
    ]);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>
