<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/PostForMeAPI.php';

header('Content-Type: application/json');

function vwmIsActiveScheduledStatus(string $status): bool
{
    $status = strtolower(trim($status));
    return in_array($status, ['pending', 'scheduled', 'partial', 'queued', 'processing'], true);
}

function vwmFetchRemotePostStatusMap(PostForMeAPI $postForMe, int $maxPages = 5): array
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

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing id']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key' LIMIT 1");
    $stmt->execute();
    $apiKey = (string)($stmt->fetchColumn() ?: '');
    if ($apiKey === '') {
        echo json_encode(['ok' => false, 'error' => 'PostForMe API key not configured']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, post_id, status FROM postforme_posts WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Post not found']);
        exit;
    }

    if (!vwmIsActiveScheduledStatus((string)$row['status'])) {
        echo json_encode(['ok' => false, 'error' => 'Only pending/scheduled/partial/queued posts can be deleted']);
        exit;
    }

    $postId = (string)$row['post_id'];
    $postForMe = new PostForMeAPI($apiKey);
    $resp = $postForMe->cancelOrDeletePost($postId);

    if (!empty($resp['success'])) {
        $stmt = $pdo->prepare("UPDATE postforme_posts SET status='cancelled' WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true, 'message' => $resp['message'] ?? 'Deleted']);
        exit;
    }

    // Fallback: if post is not active on remote anymore, clear local stale row.
    $scan = vwmFetchRemotePostStatusMap($postForMe, 5);
    if (!empty($scan['ok'])) {
        $remoteStatus = $scan['map'][$postId] ?? null;
        if ($remoteStatus === null || !vwmIsActiveScheduledStatus((string)$remoteStatus)) {
            $stmt = $pdo->prepare("UPDATE postforme_posts SET status='cancelled' WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode([
                'ok' => true,
                'message' => 'Local schedule cleared (post not active on PostForMe).',
                'fallback' => true,
                'remote_status' => $remoteStatus
            ]);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => $resp['message'] ?? 'Failed']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
