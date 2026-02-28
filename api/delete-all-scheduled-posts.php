<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/PostForMeAPI.php';

header('Content-Type: application/json');

function vwmBulkIsActiveScheduledStatus(string $status): bool
{
    $status = strtolower(trim($status));
    return in_array($status, ['pending', 'scheduled', 'partial', 'queued', 'processing'], true);
}

function vwmBulkFetchRemotePostStatusMap(PostForMeAPI $postForMe, int $maxPages = 8): array
{
    $map = [];
    $anySuccess = false;

    for ($page = 1; $page <= $maxPages; $page++) {
        $resp = $postForMe->listPosts([
            'page' => $page,
            'per_page' => 100
        ]);

        if (empty($resp['success'])) {
            break;
        }
        $anySuccess = true;

        $rows = $resp['posts'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $postId = (string)($row['id'] ?? $row['post_id'] ?? '');
            if ($postId === '') {
                continue;
            }
            $status = strtolower(trim((string)($row['status'] ?? $row['state'] ?? '')));
            $map[$postId] = ($status !== '' ? $status : 'unknown');
        }

        if (count($rows) < 100) {
            break;
        }
    }

    return ['ok' => $anySuccess, 'map' => $map];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $automationId = isset($_POST['automation_id']) ? (int)$_POST['automation_id'] : 0;

    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key' LIMIT 1");
    $stmt->execute();
    $apiKey = (string)($stmt->fetchColumn() ?: '');
    if ($apiKey === '') {
        echo json_encode(['ok' => false, 'error' => 'PostForMe API key not configured']);
        exit;
    }

    $sql = "
        SELECT id, post_id, status
        FROM postforme_posts
        WHERE status IN ('pending', 'scheduled', 'partial', 'queued', 'processing')
    ";
    $params = [];
    if ($automationId > 0) {
        $sql .= " AND automation_id = ?";
        $params[] = $automationId;
    }
    $sql .= " ORDER BY scheduled_at ASC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo json_encode([
            'ok' => true,
            'message' => 'No scheduled posts found',
            'total' => 0,
            'deleted' => 0,
            'failed' => 0
        ]);
        exit;
    }

    $postForMe = new PostForMeAPI($apiKey);
    $remoteScan = vwmBulkFetchRemotePostStatusMap($postForMe, 8);
    $remoteMap = (is_array($remoteScan['map'] ?? null)) ? $remoteScan['map'] : [];
    $remoteScanOk = !empty($remoteScan['ok']);

    $deleted = 0;
    $failed = 0;
    $fallbackCleared = 0;
    $errors = [];

    foreach ($rows as $row) {
        $postId = (string)$row['post_id'];
        $resp = $postForMe->cancelOrDeletePost($postId);
        if (!empty($resp['success'])) {
            $up = $pdo->prepare("UPDATE postforme_posts SET status='cancelled' WHERE id = ?");
            $up->execute([(int)$row['id']]);
            $deleted++;
        } else {
            if ($remoteScanOk) {
                $remoteStatus = $remoteMap[$postId] ?? null;
                if ($remoteStatus === null || !vwmBulkIsActiveScheduledStatus((string)$remoteStatus)) {
                    $up = $pdo->prepare("UPDATE postforme_posts SET status='cancelled' WHERE id = ?");
                    $up->execute([(int)$row['id']]);
                    $deleted++;
                    $fallbackCleared++;
                    continue;
                }
            }

            $failed++;
            if (count($errors) < 10) {
                $errors[] = '#' . $postId . ': ' . ($resp['message'] ?? 'Failed');
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => "Processed {$deleted}/" . count($rows) . " scheduled post(s)",
        'total' => count($rows),
        'deleted' => $deleted,
        'failed' => $failed,
        'fallback_cleared' => $fallbackCleared,
        'errors' => $errors
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
