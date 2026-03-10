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

$manager = new LocalAgentManager($pdo);
$result = $manager->registerAgent($payload, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
if (!$result['success']) {
    http_response_code(403);
    echo json_encode($result);
    exit;
}

$baseUrl = $manager->getPublicBaseUrl();
echo json_encode([
    'success' => true,
    'agent_key' => $result['agent_key'],
    'agent_secret' => $result['agent_secret'],
    'agent' => $result['agent'],
    'base_url' => $baseUrl,
    'poll_url' => $baseUrl !== '' ? ($baseUrl . '/api/agent-poll.php') : null,
    'report_url' => $baseUrl !== '' ? ($baseUrl . '/api/agent-report.php') : null,
    'complete_url' => $baseUrl !== '' ? ($baseUrl . '/api/agent-complete.php') : null,
]);
