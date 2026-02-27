<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/PostForMeAPI.php';

header('Content-Type: application/json');

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
        WHERE status IN ('pending', 'scheduled', 'partial')
          AND scheduled_at IS NOT NULL
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
    $deleted = 0;
    $failed = 0;
    $errors = [];

    foreach ($rows as $row) {
        $resp = $postForMe->cancelOrDeletePost((string)$row['post_id']);
        if (!empty($resp['success'])) {
            $up = $pdo->prepare("UPDATE postforme_posts SET status='cancelled' WHERE id = ?");
            $up->execute([(int)$row['id']]);
            $deleted++;
        } else {
            $failed++;
            if (count($errors) < 10) {
                $errors[] = '#' . $row['post_id'] . ': ' . ($resp['message'] ?? 'Failed');
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => "Processed {$deleted}/" . count($rows) . " scheduled post(s)",
        'total' => count($rows),
        'deleted' => $deleted,
        'failed' => $failed,
        'errors' => $errors
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

