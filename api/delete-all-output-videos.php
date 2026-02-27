<?php
/**
 * Delete All Output Videos API
 * Removes all video files from output folder.
 */
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

$baseDir = (PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : getenv('HOME') . '/VideoWorkflow';
$outputDir = $baseDir . '/output';
$allowedExt = ['mp4', 'webm', 'mov', 'avi'];

if (!is_dir($outputDir)) {
    echo json_encode([
        'success' => true,
        'deleted' => 0,
        'remaining' => 0,
        'message' => 'Output directory does not exist yet'
    ]);
    exit;
}

$files = scandir($outputDir);
$deleted = 0;
$failed = [];

foreach ($files as $file) {
    if ($file === '.' || $file === '..') {
        continue;
    }

    $path = $outputDir . '/' . $file;
    if (!is_file($path)) {
        continue;
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        continue;
    }

    if (@unlink($path)) {
        $deleted++;
    } else {
        $failed[] = $file;
    }
}

$remaining = 0;
$filesAfter = scandir($outputDir);
foreach ($filesAfter as $file) {
    if ($file === '.' || $file === '..') {
        continue;
    }
    $path = $outputDir . '/' . $file;
    if (!is_file($path)) {
        continue;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($ext, $allowedExt, true)) {
        $remaining++;
    }
}

echo json_encode([
    'success' => true,
    'deleted' => $deleted,
    'remaining' => $remaining,
    'failed' => $failed
]);

