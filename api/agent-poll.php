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

$agentKey = trim((string)($payload['agent_key'] ?? ($_SERVER['HTTP_X_AGENT_KEY'] ?? '')));
$agentSecret = trim((string)($payload['agent_secret'] ?? ($_SERVER['HTTP_X_AGENT_SECRET'] ?? '')));

$manager = new LocalAgentManager($pdo);
$result = $manager->claimNextJob($agentKey, $agentSecret, (string)($_SERVER['REMOTE_ADDR'] ?? ''));

$httpStatus = (int)($result['http_status'] ?? 200);
http_response_code($httpStatus);
echo json_encode([
    'success' => (bool)($result['success'] ?? false),
    'error' => $result['error'] ?? null,
    'job' => $result['job'] ?? null,
    'agent' => $result['agent'] ?? null,
]);
