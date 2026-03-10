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

$jobId = (int)($_POST['job_id'] ?? 0);
$claimToken = trim((string)($_POST['claim_token'] ?? ''));

$manager = new LocalAgentManager($pdo);
$job = $manager->authorizeJobClaim($jobId, $claimToken, ['claimed', 'running', 'completed']);
if (!$job) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid job claim']);
    exit;
}

if (empty($_FILES['output_file']) || !is_uploaded_file($_FILES['output_file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing output file upload']);
    exit;
}

$originalName = basename((string)($_FILES['output_file']['name'] ?? 'output.mp4'));
$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
if ($safeName === '') {
    $safeName = 'agent_output_' . $jobId . '.mp4';
}

if (!is_dir(OUTPUT_DIR)) {
    @mkdir(OUTPUT_DIR, 0777, true);
}

$targetName = 'agent_' . $jobId . '_' . $safeName;
$targetPath = rtrim(OUTPUT_DIR, '/\\') . DIRECTORY_SEPARATOR . $targetName;

if (!move_uploaded_file($_FILES['output_file']['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to store uploaded output']);
    exit;
}

echo json_encode([
    'success' => true,
    'filename' => $targetName,
    'path' => $targetPath
]);
