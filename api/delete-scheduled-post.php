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

    if (!in_array($row['status'], ['pending', 'scheduled', 'partial'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Only pending/scheduled/partial posts can be deleted']);
        exit;
    }

    $postForMe = new PostForMeAPI($apiKey);
    $resp = $postForMe->cancelOrDeletePost((string)$row['post_id']);

    if (!empty($resp['success'])) {
        $stmt = $pdo->prepare("UPDATE postforme_posts SET status='cancelled' WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true, 'message' => $resp['message'] ?? 'Deleted']);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => $resp['message'] ?? 'Failed']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
