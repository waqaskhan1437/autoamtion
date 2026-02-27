<?php
/**
 * API endpoint to run automation
 * Can be called manually or via cron job
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/AutomationRunner.php';

// Get automation ID
$automationId = $_GET['id'] ?? $_POST['id'] ?? null;

if (!$automationId) {
    jsonResponse(['error' => 'Automation ID required'], 400);
}

try {
    // Check if automation exists and is enabled
    $stmt = $pdo->prepare("SELECT * FROM automation_settings WHERE id = ?");
    $stmt->execute([$automationId]);
    $automation = $stmt->fetch();
    
    if (!$automation) {
        jsonResponse(['error' => 'Automation not found'], 404);
    }
    
    // Run the automation
    $runner = new AutomationRunner($pdo, $automationId);
    $result = $runner->run();
    
    jsonResponse([
        'success' => true,
        'message' => 'Automation completed',
        'processed' => $result['processed']
    ]);
    
} catch (Exception $e) {
    jsonResponse([
        'error' => $e->getMessage()
    ], 500);
}
?>
