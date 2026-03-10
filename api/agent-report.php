<?php

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/LocalAgentManager.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Config error']);
    exit;
}

if (!isset($pdo)) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$jobId = (int)($payload['job_id'] ?? 0);
$claimToken = trim((string)($payload['claim_token'] ?? ''));
$progressPayload = isset($payload['payload']) && is_array($payload['payload']) ? $payload['payload'] : $payload;

$manager = new LocalAgentManager($pdo);
$result = $manager->receiveProgress($jobId, $claimToken, $progressPayload, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
if (!$result['success']) {
    http_response_code(400);
}

echo json_encode($result);
