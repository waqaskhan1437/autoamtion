<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$automationId = $_POST['automation_id'] ?? null;

if (!$automationId) {
    echo json_encode(['success' => false, 'message' => 'Automation ID required']);
    exit;
}

$stmt = $pdo->prepare("SELECT status FROM automation_settings WHERE id = ?");
$stmt->execute([$automationId]);
$automation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$automation) {
    echo json_encode(['success' => false, 'message' => 'Automation not found']);
    exit;
}

if ($automation['status'] !== 'running' && $automation['status'] !== 'processing') {
    echo json_encode(['success' => false, 'message' => 'Automation is not currently running']);
    exit;
}

$updateStmt = $pdo->prepare("UPDATE automation_settings SET status = 'stopped' WHERE id = ?");
$updateStmt->execute([$automationId]);

echo json_encode(['success' => true, 'message' => 'Automation stopped successfully']);
