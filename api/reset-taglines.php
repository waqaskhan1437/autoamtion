<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$automationId = $_POST['automation_id'] ?? null;

if ($automationId) {
    $stmt = $pdo->prepare("DELETE FROM used_taglines WHERE automation_id = ?");
    $stmt->execute([$automationId]);
    echo json_encode(['success' => true, 'message' => 'Taglines reset for this automation']);
} else {
    $pdo->exec("DELETE FROM used_taglines");
    echo json_encode(['success' => true, 'message' => 'All taglines have been reset']);
}
