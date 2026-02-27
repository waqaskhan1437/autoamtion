<?php
/**
 * List Output Videos API
 * Returns list of processed videos in the output folder
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$baseDir = (PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : getenv('HOME') . '/VideoWorkflow';
$outputDir = $baseDir . '/output';
$tempDir = $baseDir . '/temp';

$videos = [];
$folderInfo = [
    'output_folder' => $outputDir,
    'temp_folder' => $tempDir,
    'output_exists' => is_dir($outputDir),
    'temp_exists' => is_dir($tempDir)
];

if (is_dir($outputDir)) {
    $files = scandir($outputDir, SCANDIR_SORT_DESCENDING);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filePath = $outputDir . '/' . $file;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        
        if (in_array($ext, ['mp4', 'webm', 'mov', 'avi'])) {
            $fileSize = filesize($filePath);
            $modTime = filemtime($filePath);
            
            $videos[] = [
                'name' => $file,
                'path' => str_replace('\\', '/', $filePath),
                'size' => round($fileSize / 1024 / 1024, 2),
                'size_formatted' => formatSize($fileSize),
                'modified' => date('Y-m-d H:i:s', $modTime),
                'modified_ago' => timeAgo($modTime),
                'url' => 'output/' . $file
            ];
        }
    }
}

function formatSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

function timeAgo($time) {
    $diff = time() - $time;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    return floor($diff / 86400) . ' days ago';
}

echo json_encode([
    'success' => true,
    'folder' => $folderInfo,
    'videos' => $videos,
    'total' => count($videos)
]);
