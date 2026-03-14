<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Check if any automation is currently running (status = 'running' or 'processing')
$stmt = $pdo->prepare("
    SELECT id, name 
    FROM automation_settings 
    WHERE status IN ('running', 'processing') 
    LIMIT 1
");
$stmt->execute();
$running = $stmt->fetch(PDO::FETCH_ASSOC);

if ($running) {
    echo json_encode([
        'running' => true,
        'automation_id' => $running['id'],
        'automation_name' => $running['name']
    ]);
} else {
    echo json_encode(['running' => false]);
}
