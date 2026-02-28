<?php
/**
 * List Output Videos API
 * - Local mode videos from output folder
 * - GitHub runner videos from automation progress payload (direct stream links)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';

function lovFormatSize($bytes): string
{
    if (!is_numeric($bytes) || $bytes <= 0) return '-';
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return (int)$bytes . ' bytes';
}

function lovTimeAgo(int $time): string
{
    $diff = time() - $time;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    return floor($diff / 86400) . ' days ago';
}

$baseDir = defined('BASE_DATA_DIR')
    ? BASE_DATA_DIR
    : ((PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : getenv('HOME') . '/VideoWorkflow');
$outputDir = rtrim((string)$baseDir, '/\\') . '/output';
$tempDir = rtrim((string)$baseDir, '/\\') . '/temp';

$videos = [];
$seen = [];
$localCount = 0;
$githubCount = 0;

if (is_dir($outputDir)) {
    $files = scandir($outputDir, SCANDIR_SORT_DESCENDING);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $filePath = $outputDir . '/' . $file;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!is_file($filePath) || !in_array($ext, ['mp4', 'webm', 'mov', 'avi'], true)) {
            continue;
        }

        $fileSize = (int)@filesize($filePath);
        $modTime = (int)@filemtime($filePath);
        $key = 'local|' . strtolower($file);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $videos[] = [
            'name' => $file,
            'path' => str_replace('\\', '/', $filePath),
            'size' => round($fileSize / 1024 / 1024, 2),
            'size_formatted' => lovFormatSize($fileSize),
            'modified' => date('Y-m-d H:i:s', $modTime),
            'modified_ago' => lovTimeAgo($modTime),
            'modified_ts' => $modTime,
            'url' => 'stream.php?file=' . rawurlencode($file),
            'source' => 'local',
            'automation_id' => null,
            'run_id' => null
        ];
        $localCount++;
    }
}

if (isset($pdo)) {
    try {
        $stmt = $pdo->query("
            SELECT id, name, last_run_at, progress_data
            FROM automation_settings
            WHERE run_mode = 'github_runner'
              AND progress_data IS NOT NULL
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $automationId = (int)($row['id'] ?? 0);
            if ($automationId <= 0) continue;

            $progressData = json_decode((string)($row['progress_data'] ?? ''), true);
            if (!is_array($progressData) || empty($progressData['outputs']) || !is_array($progressData['outputs'])) {
                continue;
            }

            $runId = (int)($progressData['run_id'] ?? 0);
            if ($runId <= 0 && !empty($progressData['run_url']) && preg_match('~/runs/(\d+)~', (string)$progressData['run_url'], $m)) {
                $runId = (int)$m[1];
            }

            $modTs = !empty($row['last_run_at']) ? strtotime((string)$row['last_run_at']) : false;
            if ($modTs === false) $modTs = time();

            foreach ($progressData['outputs'] as $outputNameRaw) {
                $outputName = trim((string)$outputNameRaw);
                if ($outputName === '') continue;
                $ext = strtolower(pathinfo($outputName, PATHINFO_EXTENSION));
                if (!in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv'], true)) continue;

                $key = 'github|' . $automationId . '|' . strtolower($outputName);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $url = 'api/stream-github-video.php?automation_id=' . urlencode((string)$automationId)
                    . '&file=' . rawurlencode($outputName);

                $videos[] = [
                    'name' => $outputName,
                    'path' => 'github://' . $automationId . '/' . $outputName,
                    'size' => null,
                    'size_formatted' => 'remote',
                    'modified' => date('Y-m-d H:i:s', (int)$modTs),
                    'modified_ago' => lovTimeAgo((int)$modTs),
                    'modified_ts' => (int)$modTs,
                    'url' => $url,
                    'source' => 'github',
                    'automation_id' => $automationId,
                    'run_id' => ($runId > 0 ? $runId : null)
                ];
                $githubCount++;
            }
        }
    } catch (Exception $e) {
    }
}

usort($videos, function ($a, $b) {
    return ((int)($b['modified_ts'] ?? 0)) <=> ((int)($a['modified_ts'] ?? 0));
});

$folderInfo = [
    'output_folder' => $outputDir,
    'temp_folder' => $tempDir,
    'output_exists' => is_dir($outputDir),
    'temp_exists' => is_dir($tempDir),
    'local_count' => $localCount,
    'github_count' => $githubCount
];

echo json_encode([
    'success' => true,
    'folder' => $folderInfo,
    'videos' => $videos,
    'total' => count($videos)
]);
