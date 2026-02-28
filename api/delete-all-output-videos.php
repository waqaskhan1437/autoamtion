<?php
/**
 * Delete All Output Videos API
 * Clears:
 * - Local output folder videos
 * - GitHub runner cache files (stream cache)
 * - GitHub output references from automation progress payload
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

function daovDeleteLocalOutputVideos(string $outputDir, array $allowedExt): array
{
    $deleted = 0;
    $failed = [];

    if (!is_dir($outputDir)) {
        return ['deleted' => 0, 'failed' => [], 'remaining' => 0];
    }

    $files = scandir($outputDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $path = $outputDir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            continue;
        }

        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
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
        $path = $outputDir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            continue;
        }
        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExt, true)) {
            $remaining++;
        }
    }

    return ['deleted' => $deleted, 'failed' => $failed, 'remaining' => $remaining];
}

function daovDeleteDirRecursive(string $dir): array
{
    $deletedFiles = 0;
    $deletedDirs = 0;
    $failed = [];

    if (!is_dir($dir)) {
        return ['deleted_files' => 0, 'deleted_dirs' => 0, 'failed' => []];
    }

    $items = @scandir($dir);
    if (!is_array($items)) {
        return ['deleted_files' => 0, 'deleted_dirs' => 0, 'failed' => [$dir]];
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $sub = daovDeleteDirRecursive($path);
            $deletedFiles += (int)$sub['deleted_files'];
            $deletedDirs += (int)$sub['deleted_dirs'];
            if (!empty($sub['failed']) && is_array($sub['failed'])) {
                foreach ($sub['failed'] as $f) {
                    $failed[] = $f;
                }
            }
            if (@rmdir($path)) {
                $deletedDirs++;
            } else {
                $failed[] = $path;
            }
            continue;
        }

        if (@unlink($path)) {
            $deletedFiles++;
        } else {
            $failed[] = $path;
        }
    }

    return ['deleted_files' => $deletedFiles, 'deleted_dirs' => $deletedDirs, 'failed' => $failed];
}

function daovClearGithubOutputReferences(PDO $pdo): array
{
    $automationsUpdated = 0;
    $outputRefsCleared = 0;

    $stmt = $pdo->query("
        SELECT id, progress_data
        FROM automation_settings
        WHERE run_mode = 'github_runner'
          AND progress_data IS NOT NULL
    ");
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $data = json_decode((string)($row['progress_data'] ?? ''), true);
        if (!is_array($data)) {
            continue;
        }

        $beforeCount = 0;
        if (!empty($data['outputs']) && is_array($data['outputs'])) {
            $beforeCount = count($data['outputs']);
        }

        $changed = false;
        if ($beforeCount > 0) {
            $data['outputs'] = [];
            $outputRefsCleared += $beforeCount;
            $changed = true;
        }
        if (!empty($data['current_video'])) {
            $data['current_video'] = '';
            $changed = true;
        }

        if ($changed) {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                continue;
            }
            $up = $pdo->prepare("UPDATE automation_settings SET progress_data = ? WHERE id = ?");
            $up->execute([$json, $id]);
            $automationsUpdated++;
        }
    }

    return [
        'automations_updated' => $automationsUpdated,
        'output_refs_cleared' => $outputRefsCleared
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$mode = strtolower(trim((string)($_POST['mode'] ?? 'all')));
$allowedModes = ['all', 'local', 'github'];
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'all';
}

$baseDir = defined('BASE_DATA_DIR')
    ? BASE_DATA_DIR
    : ((PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : (getenv('HOME') . '/VideoWorkflow'));
$outputDir = rtrim((string)$baseDir, '/\\') . DIRECTORY_SEPARATOR . 'output';
$githubCacheDir = rtrim((string)$baseDir, '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'github-artifacts';
$allowedExt = ['mp4', 'webm', 'mov', 'avi', 'mkv'];

$local = ['deleted' => 0, 'failed' => [], 'remaining' => 0];
$cache = ['deleted_files' => 0, 'deleted_dirs' => 0, 'failed' => []];
$refs = ['automations_updated' => 0, 'output_refs_cleared' => 0];

if ($mode === 'all' || $mode === 'local') {
    $local = daovDeleteLocalOutputVideos($outputDir, $allowedExt);
}
if (($mode === 'all' || $mode === 'github') && is_dir($githubCacheDir)) {
    $cache = daovDeleteDirRecursive($githubCacheDir);
}
if (($mode === 'all' || $mode === 'github') && isset($pdo)) {
    try {
        $refs = daovClearGithubOutputReferences($pdo);
    } catch (Throwable $e) {
    }
}

$totalDeleted = (int)$local['deleted'] + (int)$cache['deleted_files'] + (int)$refs['output_refs_cleared'];
$failed = [];
if (!empty($local['failed'])) {
    foreach ($local['failed'] as $f) {
        $failed[] = $f;
    }
}
if (!empty($cache['failed'])) {
    foreach ($cache['failed'] as $f) {
        $failed[] = $f;
    }
}

echo json_encode([
    'success' => true,
    'mode' => $mode,
    'deleted' => $totalDeleted,
    'local_deleted' => (int)$local['deleted'],
    'local_remaining' => (int)$local['remaining'],
    'github_cache_deleted' => (int)$cache['deleted_files'],
    'github_cache_dirs_deleted' => (int)$cache['deleted_dirs'],
    'github_refs_cleared' => (int)$refs['output_refs_cleared'],
    'github_automations_updated' => (int)$refs['automations_updated'],
    'failed' => $failed
]);
