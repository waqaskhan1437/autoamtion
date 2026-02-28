<?php
/**
 * Stream video file processed on GitHub runner directly from artifact.
 * Uses cache directory (not output folder) for efficient playback/seek.
 */

error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

require_once __DIR__ . '/../config.php';

function sgvGithubSettings(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT setting_key, setting_value
        FROM settings
        WHERE setting_key IN ('github_runner_token', 'github_runner_owner', 'github_runner_repo')
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    $token = trim((string)($rows['github_runner_token'] ?? ''));
    $owner = trim((string)($rows['github_runner_owner'] ?? ''));
    $repo = trim((string)($rows['github_runner_repo'] ?? ''));

    return [
        'ready' => ($token !== '' && $owner !== '' && $repo !== ''),
        'token' => $token,
        'owner' => $owner,
        'repo' => $repo
    ];
}

function sgvGithubRequest(string $url, string $token, bool $binary = false, int $timeout = 120): array
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL not available'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: VideoWorkflow-GitHubStream/1.0'
        ]
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['success' => false, 'error' => ($err !== '' ? $err : 'GitHub request failed')];
    }
    if ($binary) {
        return ['success' => true, 'http' => $http, 'body' => $body, 'json' => null];
    }
    $json = json_decode($body, true);
    return ['success' => true, 'http' => $http, 'body' => $body, 'json' => (is_array($json) ? $json : null)];
}

function sgvGithubDownloadToFile(string $url, string $token, string $targetPath, int $timeout = 600): array
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL not available'];
    }

    $fp = @fopen($targetPath, 'wb');
    if (!is_resource($fp)) {
        return ['success' => false, 'error' => 'Unable to open temp file for artifact download'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            // GitHub artifact endpoint expects API accept header and then redirects to blob.
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: VideoWorkflow-GitHubStream/1.0'
        ]
    ]);

    $ok = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($ok === false) {
        @unlink($targetPath);
        return ['success' => false, 'error' => ($err !== '' ? $err : 'Artifact download failed')];
    }
    if ($http < 200 || $http >= 300) {
        @unlink($targetPath);
        return ['success' => false, 'error' => 'Artifact download HTTP ' . $http];
    }
    if (!is_file($targetPath) || (int)filesize($targetPath) <= 0) {
        @unlink($targetPath);
        return ['success' => false, 'error' => 'Artifact zip is empty'];
    }

    return ['success' => true, 'http' => $http];
}

function sgvStreamFile(string $filepath, string $downloadName = '', bool $forceDownload = false): void
{
    if (!is_file($filepath)) {
        http_response_code(404);
        echo 'Video not found';
        exit;
    }

    $size = (int)filesize($filepath);
    $file = basename($filepath);
    $outName = ($downloadName !== '' ? $downloadName : $file);

    if ($forceDownload) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $outName . '"');
    } else {
        header('Content-Type: video/mp4');
        header('Accept-Ranges: bytes');
    }

    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', (string)$_SERVER['HTTP_RANGE'], $m)) {
        $start = ($m[1] !== '') ? (int)$m[1] : 0;
        $end = ($m[2] !== '') ? (int)$m[2] : ($size - 1);
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        $length = $end - $start + 1;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . $length);

        $fp = fopen($filepath, 'rb');
        if ($fp === false) exit;
        fseek($fp, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = fread($fp, min(1024 * 1024, $remaining));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($fp);
        exit;
    }

    header('Content-Length: ' . $size);
    readfile($filepath);
    exit;
}

function sgvExtractFromArtifactZip(string $zipPath, string $targetBaseName, string $cacheTarget): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return false;
    }

    $ok = false;
    $targetBaseName = strtolower($targetBaseName);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string)$zip->getNameIndex($i);
        if ($entry === '' || substr($entry, -1) === '/') {
            continue;
        }
        $base = strtolower(basename($entry));
        if ($base !== $targetBaseName) {
            continue;
        }

        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) {
            continue;
        }
        $fp = @fopen($cacheTarget, 'wb');
        if (!is_resource($fp)) {
            fclose($stream);
            continue;
        }
        stream_copy_to_stream($stream, $fp);
        fclose($fp);
        fclose($stream);
        $ok = is_file($cacheTarget) && filesize($cacheTarget) > 0;
        break;
    }

    $zip->close();
    return $ok;
}

$automationId = isset($_GET['automation_id']) ? (int)$_GET['automation_id'] : 0;
$requestedFile = isset($_GET['file']) ? basename((string)$_GET['file']) : '';
$download = !empty($_GET['download']);

if ($automationId <= 0 || $requestedFile === '') {
    http_response_code(400);
    echo 'Missing automation_id or file';
    exit;
}

$ext = strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION));
if (!in_array($ext, ['mp4', 'mov', 'mkv', 'webm', 'avi'], true)) {
    http_response_code(400);
    echo 'Invalid video file';
    exit;
}

$stmt = $pdo->prepare("SELECT run_mode, progress_data FROM automation_settings WHERE id = ? LIMIT 1");
$stmt->execute([$automationId]);
$automation = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$automation || (string)($automation['run_mode'] ?? '') !== 'github_runner') {
    http_response_code(404);
    echo 'Automation not found for GitHub stream';
    exit;
}

$progressData = json_decode((string)($automation['progress_data'] ?? ''), true);
if (!is_array($progressData)) {
    http_response_code(404);
    echo 'No GitHub output metadata';
    exit;
}

$outputs = [];
if (!empty($progressData['outputs']) && is_array($progressData['outputs'])) {
    foreach ($progressData['outputs'] as $o) {
        $name = basename(trim((string)$o));
        if ($name !== '') $outputs[] = $name;
    }
}

if (!in_array($requestedFile, $outputs, true)) {
    http_response_code(404);
    echo 'Requested file not in automation outputs';
    exit;
}

$runId = (int)($progressData['run_id'] ?? 0);
if ($runId <= 0 && !empty($progressData['run_url']) && preg_match('~/runs/(\d+)~', (string)$progressData['run_url'], $m)) {
    $runId = (int)$m[1];
}
if ($runId <= 0) {
    http_response_code(404);
    echo 'Run id not available';
    exit;
}

$gh = sgvGithubSettings($pdo);
if (empty($gh['ready'])) {
    http_response_code(500);
    echo 'GitHub runner settings incomplete';
    exit;
}

$baseDir = defined('BASE_DATA_DIR')
    ? BASE_DATA_DIR
    : ((PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : (getenv('HOME') . '/VideoWorkflow'));
$cacheDir = rtrim((string)$baseDir, '/\\') . '/cache/github-artifacts/' . $runId;
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
}
$cacheTarget = $cacheDir . '/' . $requestedFile;

if (!is_file($cacheTarget) || filesize($cacheTarget) <= 0) {
    $artifactsUrl = "https://api.github.com/repos/{$gh['owner']}/{$gh['repo']}/actions/runs/{$runId}/artifacts?per_page=100";
    $listRes = sgvGithubRequest($artifactsUrl, $gh['token'], false, 20);
    if (!$listRes['success'] || (int)($listRes['http'] ?? 0) !== 200 || !is_array($listRes['json'])) {
        http_response_code(502);
        echo 'Failed to list run artifacts';
        exit;
    }

    $artifacts = $listRes['json']['artifacts'] ?? [];
    $pick = null;
    if (is_array($artifacts)) {
        foreach ($artifacts as $artifact) {
            if (!is_array($artifact) || !empty($artifact['expired'])) continue;
            $name = (string)($artifact['name'] ?? '');
            if (strpos($name, 'automation-output-') === 0) {
                $pick = $artifact;
                break;
            }
        }
    }
    if (!$pick || empty($pick['archive_download_url'])) {
        http_response_code(404);
        echo 'Output artifact not found';
        exit;
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'ghv_');
    if ($tmpZip === false) {
        http_response_code(500);
        echo 'Temp file error';
        exit;
    }
    $downloadRes = sgvGithubDownloadToFile((string)$pick['archive_download_url'], $gh['token'], $tmpZip, 900);
    if (!$downloadRes['success']) {
        @unlink($tmpZip);
        http_response_code(502);
        echo 'Failed to download output artifact';
        exit;
    }
    $ok = sgvExtractFromArtifactZip($tmpZip, $requestedFile, $cacheTarget);
    @unlink($tmpZip);

    if (!$ok) {
        http_response_code(404);
        echo 'Requested video not present in artifact';
        exit;
    }
}

sgvStreamFile($cacheTarget, $requestedFile, $download);
