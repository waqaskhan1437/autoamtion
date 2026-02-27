<?php
/**
 * Robust Background Starter
 * Creates a batch file and runs it completely in background
 * Process continues even if browser closes
 */
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Config error']);
    exit;
}

$automationId = $_GET['id'] ?? $_POST['id'] ?? null;

if (!$automationId) {
    echo json_encode(['success' => false, 'error' => 'No automation ID']);
    exit;
}

// Get automation
$stmt = $pdo->prepare("SELECT * FROM automation_settings WHERE id = ?");
$stmt->execute([$automationId]);
$automation = $stmt->fetch();

if (!$automation) {
    echo json_encode(['success' => false, 'error' => 'Automation not found']);
    exit;
}

// Check if already processing
if ($automation['status'] === 'processing') {
    echo json_encode(['success' => true, 'already_running' => true, 'message' => 'Already running']);
    exit;
}

// Mark as processing
$pdo->prepare("UPDATE automation_settings SET status = 'processing', progress_percent = 0, progress_data = ? WHERE id = ?")
    ->execute([json_encode(['step' => 'init', 'status' => 'info', 'message' => 'Starting...', 'time' => date('H:i:s')]), $automationId]);

// Create background runner script
$runnerScript = __DIR__ . '/../run-background.php';
$logsDir = __DIR__ . '/../logs';
if (!is_dir($logsDir)) mkdir($logsDir, 0777, true);

$logFile = $logsDir . '/automation_' . $automationId . '.log';

if (PHP_OS_FAMILY === 'Windows') {
    // Windows: Create and run batch file
    $phpPath = PHP_BINARY;
    $batchFile = $logsDir . '/run_' . $automationId . '.bat';
    
    $batchContent = "@echo off\n";
    $batchContent .= "cd /d \"" . realpath(__DIR__ . '/..') . "\"\n";
    $batchContent .= "\"$phpPath\" \"$runnerScript\" $automationId > \"$logFile\" 2>&1\n";
    $batchContent .= "exit\n";
    
    file_put_contents($batchFile, $batchContent);
    
    // Run batch completely detached using WMI
    $wmiCmd = "wmic process call create \"cmd /c start /min \\\"\\\" \\\"$batchFile\\\"\"";
    exec($wmiCmd, $output, $returnCode);
    
    // Fallback: try popen if WMI fails
    if ($returnCode !== 0) {
        pclose(popen("start /B cmd /c \"$batchFile\"", 'r'));
    }
} else {
    // Linux/Mac: nohup
    $phpPath = PHP_BINARY ?: '/usr/bin/php';
    $cmd = "nohup $phpPath \"$runnerScript\" $automationId > \"$logFile\" 2>&1 &";
    exec($cmd);
}

// Wait a moment and verify process started
usleep(500000); // 0.5 second

$stmt = $pdo->prepare("SELECT status, progress_data FROM automation_settings WHERE id = ?");
$stmt->execute([$automationId]);
$check = $stmt->fetch();

echo json_encode([
    'success' => true,
    'message' => 'Background process started',
    'automationId' => $automationId,
    'status' => $check['status'] ?? 'processing'
]);
