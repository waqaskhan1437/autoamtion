<?php
/**
 * Optional callback endpoint for GitHub runner jobs.
 * This lets workflows push status/progress back into automation cards.
 */

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/RemoteExecutionHelper.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Config error']);
    exit;
}

if (!isset($pdo)) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

function callbackSetting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return ($value === false || $value === null) ? $default : (string)$value;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$secretConfigured = callbackSetting($pdo, 'github_runner_callback_secret', '');
$secretIncoming = isset($payload['secret']) ? (string)$payload['secret'] : '';
if ($secretConfigured !== '' && !hash_equals($secretConfigured, $secretIncoming)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid callback secret']);
    exit;
}

$automationId = isset($payload['automation_id']) ? (int)$payload['automation_id'] : 0;
if ($automationId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing automation_id']);
    exit;
}

$result = remoteExecutionApplyProgress($pdo, $automationId, $payload, 'github_callback');
if (!$result['success']) {
    echo json_encode($result);
    exit;
}

echo json_encode([
    'success' => true,
    'automation_id' => $automationId,
    'status' => $result['status'] ?? 'running'
]);
