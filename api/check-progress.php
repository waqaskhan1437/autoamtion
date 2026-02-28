<?php
/**
 * Check Automation Progress
 * Returns current status for polling.
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

if (!isset($pdo)) {
    echo json_encode(['success' => false, 'error' => 'Database not connected']);
    exit;
}

function cpClampPercent($value): int
{
    $v = (int)$value;
    if ($v < 0) return 0;
    if ($v > 100) return 100;
    return $v;
}

function cpDecodeProgressData($raw): array
{
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function cpEncodeProgressData(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return ($json === false) ? '{}' : $json;
}

function cpGithubSettings(PDO $pdo): array
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

function cpGithubRequest(string $url, string $token, bool $binary = false, int $timeout = 25): array
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL extension required'];
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
            'User-Agent: VideoWorkflow-GitHubStatus/1.0'
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
    return ['success' => true, 'http' => $http, 'body' => $body, 'json' => is_array($json) ? $json : null];
}

function cpExtractRunId(array $progressData): int
{
    $runId = (int)($progressData['run_id'] ?? 0);
    if ($runId > 0) {
        return $runId;
    }
    $runUrl = (string)($progressData['run_url'] ?? '');
    if ($runUrl !== '' && preg_match('~/runs/(\d+)~', $runUrl, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function cpPickLatestMarkerFromLogsZip(string $zipPath): ?array
{
    if (!class_exists('ZipArchive')) {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return null;
    }

    $best = null;
    $bestSeq = -1;
    $bestTs = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if ($name === '') {
            continue;
        }
        $content = $zip->getFromIndex($i);
        if (!is_string($content) || $content === '' || strpos($content, 'VW_PROGRESS_B64:') === false) {
            continue;
        }

        if (preg_match_all('/VW_PROGRESS_B64:([A-Za-z0-9+\/=]+)/', $content, $matches)) {
            foreach ($matches[1] as $b64) {
                $json = base64_decode((string)$b64, true);
                if ($json === false || $json === '') {
                    continue;
                }
                $event = json_decode($json, true);
                if (!is_array($event)) {
                    continue;
                }
                $seq = (int)($event['sequence'] ?? 0);
                $ts = (int)($event['time_unix'] ?? 0);
                if ($seq > $bestSeq || ($seq === $bestSeq && $ts >= $bestTs)) {
                    $best = $event;
                    $bestSeq = $seq;
                    $bestTs = $ts;
                }
            }
        }
    }

    $zip->close();
    return $best;
}

function cpFetchLatestProgressMarker(string $owner, string $repo, int $runId, string $token): ?array
{
    if ($runId <= 0) {
        return null;
    }

    $url = "https://api.github.com/repos/{$owner}/{$repo}/actions/runs/{$runId}/logs";
    $res = cpGithubRequest($url, $token, true, 30);
    if (!$res['success'] || (int)$res['http'] !== 200 || !is_string($res['body']) || $res['body'] === '') {
        return null;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ghlog_');
    if ($tmp === false) {
        return null;
    }
    file_put_contents($tmp, $res['body']);
    $event = cpPickLatestMarkerFromLogsZip($tmp);
    @unlink($tmp);
    return $event;
}

function cpImportRunArtifacts(array $gh, int $runId, array $progressData): array
{
    $result = [
        'progressData' => $progressData,
        'imported' => [],
        'errors' => []
    ];

    if (empty($gh['ready']) || $runId <= 0 || !class_exists('ZipArchive')) {
        return $result;
    }

    $artifactsUrl = "https://api.github.com/repos/{$gh['owner']}/{$gh['repo']}/actions/runs/{$runId}/artifacts?per_page=100";
    $listRes = cpGithubRequest($artifactsUrl, $gh['token'], false, 25);
    if (!$listRes['success'] || (int)$listRes['http'] !== 200 || !is_array($listRes['json'])) {
        return $result;
    }

    $artifacts = $listRes['json']['artifacts'] ?? [];
    if (!is_array($artifacts) || empty($artifacts)) {
        return $result;
    }

    $downloaded = [];
    if (!empty($progressData['downloaded_artifacts']) && is_array($progressData['downloaded_artifacts'])) {
        foreach ($progressData['downloaded_artifacts'] as $id) {
            $downloaded[] = (int)$id;
        }
    }
    $downloaded = array_values(array_unique(array_filter($downloaded)));

    $outDir = defined('OUTPUT_DIR')
        ? OUTPUT_DIR
        : ((PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow/output' : (getenv('HOME') . '/VideoWorkflow/output'));
    if (!is_dir($outDir)) {
        @mkdir($outDir, 0777, true);
    }

    foreach ($artifacts as $artifact) {
        if (!is_array($artifact)) {
            continue;
        }
        $artifactId = (int)($artifact['id'] ?? 0);
        $artifactName = (string)($artifact['name'] ?? '');
        $expired = !empty($artifact['expired']);
        $downloadUrl = (string)($artifact['archive_download_url'] ?? '');

        if ($artifactId <= 0 || $expired || $downloadUrl === '') {
            continue;
        }
        if (strpos($artifactName, 'automation-output-') !== 0) {
            continue;
        }
        if (in_array($artifactId, $downloaded, true)) {
            continue;
        }

        $zipRes = cpGithubRequest($downloadUrl, $gh['token'], true, 60);
        if (!$zipRes['success'] || (int)$zipRes['http'] !== 200 || !is_string($zipRes['body']) || $zipRes['body'] === '') {
            $result['errors'][] = "Artifact download failed: {$artifactName}";
            continue;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ghart_');
        if ($tmp === false) {
            $result['errors'][] = "Temp file allocation failed: {$artifactName}";
            continue;
        }
        file_put_contents($tmp, $zipRes['body']);

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            $result['errors'][] = "Invalid artifact zip: {$artifactName}";
            @unlink($tmp);
            continue;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if ($entry === '' || substr($entry, -1) === '/') {
                continue;
            }

            $base = basename($entry);
            $ext = strtolower((string)pathinfo($base, PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp4', 'mov', 'mkv', 'webm', 'avi'], true)) {
                continue;
            }

            $stream = $zip->getStream($entry);
            if (!is_resource($stream)) {
                continue;
            }

            $target = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . $base;
            if (file_exists($target)) {
                fclose($stream);
                continue;
            }

            $fp = @fopen($target, 'wb');
            if (!is_resource($fp)) {
                fclose($stream);
                continue;
            }
            stream_copy_to_stream($stream, $fp);
            fclose($fp);
            fclose($stream);

            $result['imported'][] = basename($target);
        }

        $zip->close();
        @unlink($tmp);
        $downloaded[] = $artifactId;
    }

    $downloaded = array_values(array_unique(array_filter(array_map('intval', $downloaded))));
    $progressData['downloaded_artifacts'] = $downloaded;

    $existingOutputs = [];
    if (!empty($progressData['outputs']) && is_array($progressData['outputs'])) {
        foreach ($progressData['outputs'] as $o) {
            $v = trim((string)$o);
            if ($v !== '') {
                $existingOutputs[] = $v;
            }
        }
    }
    foreach ($result['imported'] as $file) {
        if (!in_array($file, $existingOutputs, true)) {
            $existingOutputs[] = $file;
        }
    }
    $progressData['outputs'] = $existingOutputs;
    $result['progressData'] = $progressData;
    return $result;
}

$automationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($automationId <= 0) {
    echo json_encode(['success' => false, 'error' => 'No automation ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT status, progress_percent, progress_data, last_progress_time, next_run_at, enabled, run_mode FROM automation_settings WHERE id = ?");
    $stmt->execute([$automationId]);
    $automation = $stmt->fetch();
} catch (Exception $e) {
    try {
        $stmt = $pdo->prepare("SELECT status FROM automation_settings WHERE id = ?");
        $stmt->execute([$automationId]);
        $automation = $stmt->fetch();
        if ($automation) {
            $automation['progress_percent'] = 0;
            $automation['progress_data'] = null;
            $automation['last_progress_time'] = null;
            $automation['next_run_at'] = null;
            $automation['enabled'] = 0;
            $automation['run_mode'] = 'local';
        }
    } catch (Exception $e2) {
        echo json_encode(['success' => false, 'error' => 'Database query failed']);
        exit;
    }
}

if (!$automation) {
    echo json_encode(['success' => false, 'error' => 'Automation not found']);
    exit;
}

$progressPercent = cpClampPercent($automation['progress_percent'] ?? 0);
$progressData = cpDecodeProgressData((string)($automation['progress_data'] ?? ''));
$statusChanged = false;
$dataChanged = false;

if (($automation['run_mode'] ?? 'local') === 'github_runner' && in_array($automation['status'], ['running', 'processing'], true)) {
    $gh = cpGithubSettings($pdo);
    $runId = cpExtractRunId($progressData);

    if (!empty($gh['ready']) && $runId > 0) {
        $nowTs = time();
        $runUrl = trim((string)($progressData['run_url'] ?? ''));
        $markerApplied = false;

        $lastLogSync = (int)($progressData['github_log_synced_at'] ?? 0);
        if (($nowTs - $lastLogSync) >= 6) {
            $marker = cpFetchLatestProgressMarker($gh['owner'], $gh['repo'], $runId, $gh['token']);
            $progressData['github_log_synced_at'] = $nowTs;
            if (is_array($marker)) {
                $markerSeq = (int)($marker['sequence'] ?? 0);
                $lastSeq = (int)($progressData['sequence'] ?? 0);
                if ($markerSeq === 0 || $markerSeq >= $lastSeq) {
                    $progressData['sequence'] = $markerSeq;
                    $progressData['step'] = trim((string)($marker['step'] ?? ($progressData['step'] ?? 'github_runner')));
                    $progressData['status'] = trim((string)($marker['event_status'] ?? ($marker['status'] ?? ($progressData['status'] ?? 'info'))));
                    $progressData['message'] = trim((string)($marker['message'] ?? ($progressData['message'] ?? 'GitHub runner update.')));
                    if (isset($marker['progress'])) {
                        $progressPercent = cpClampPercent($marker['progress']);
                        $progressData['progress'] = $progressPercent;
                    }
                    if (!empty($marker['stats']) && is_array($marker['stats'])) {
                        $progressData['stats'] = $marker['stats'];
                    }
                    if (!empty($marker['outputs']) && is_array($marker['outputs'])) {
                        $progressData['outputs'] = array_values(array_filter(array_map('strval', $marker['outputs'])));
                    }
                    if (!empty($marker['run_url'])) {
                        $runUrl = trim((string)$marker['run_url']);
                        $progressData['run_url'] = $runUrl;
                    }
                    $progressData['run_id'] = $runId;
                    $progressData['time'] = date('H:i:s');
                    $markerApplied = true;
                    $dataChanged = true;
                }
            }
        }

        $lastRunSync = (int)($progressData['github_run_synced_at'] ?? 0);
        if (($nowTs - $lastRunSync) >= 5) {
            $runUrlApi = "https://api.github.com/repos/{$gh['owner']}/{$gh['repo']}/actions/runs/{$runId}";
            $runRes = cpGithubRequest($runUrlApi, $gh['token'], false, 15);
            $progressData['github_run_synced_at'] = $nowTs;

            if ($runRes['success'] && (int)$runRes['http'] === 200 && is_array($runRes['json'])) {
                $run = $runRes['json'];
                $ghStatus = strtolower((string)($run['status'] ?? ''));
                $ghConclusion = strtolower((string)($run['conclusion'] ?? ''));
                $runUrl = trim((string)($run['html_url'] ?? $runUrl));
                if ($runUrl !== '') {
                    $progressData['run_url'] = $runUrl;
                }
                $progressData['run_id'] = $runId;

                if ($ghStatus === 'completed') {
                    $isSuccess = in_array($ghConclusion, ['success', 'neutral', 'skipped'], true);
                    $automation['status'] = $isSuccess ? 'completed' : 'error';
                    $statusChanged = true;
                    $progressPercent = $isSuccess ? 100 : max(0, $progressPercent);

                    $progressData['step'] = 'github_runner';
                    $progressData['status'] = $isSuccess ? 'success' : 'error';
                    $progressData['message'] = $isSuccess
                        ? 'GitHub workflow completed successfully.'
                        : ('GitHub workflow failed: ' . ($ghConclusion !== '' ? $ghConclusion : 'unknown'));
                    $progressData['progress'] = $progressPercent;
                    $progressData['time'] = date('H:i:s');

                    if ($isSuccess) {
                        $import = cpImportRunArtifacts($gh, $runId, $progressData);
                        $progressData = $import['progressData'];
                        if (!empty($import['imported'])) {
                            $progressData['message'] .= ' Imported ' . count($import['imported']) . ' output video(s).';
                        }
                    }

                    try {
                        $pdo->prepare("
                            INSERT INTO automation_logs (automation_id, action, status, message)
                            VALUES (?, 'github_status_sync', ?, ?)
                        ")->execute([
                            $automationId,
                            $isSuccess ? 'success' : 'error',
                            $progressData['message']
                        ]);
                    } catch (Exception $e) {}
                    $dataChanged = true;
                } elseif (in_array($ghStatus, ['queued', 'in_progress', 'waiting', 'requested'], true)) {
                    if ($automation['status'] !== 'processing') {
                        $automation['status'] = 'processing';
                        $statusChanged = true;
                    }

                    // Fallback progress: estimate from workflow steps when detailed log markers are not yet available.
                    if (!$markerApplied) {
                        $jobsUrl = "https://api.github.com/repos/{$gh['owner']}/{$gh['repo']}/actions/runs/{$runId}/jobs?per_page=10";
                        $jobsRes = cpGithubRequest($jobsUrl, $gh['token'], false, 15);
                        if ($jobsRes['success'] && (int)$jobsRes['http'] === 200 && is_array($jobsRes['json'])) {
                            $jobs = $jobsRes['json']['jobs'] ?? [];
                            if (is_array($jobs) && !empty($jobs[0]) && is_array($jobs[0])) {
                                $stepsRaw = $jobs[0]['steps'] ?? [];
                                $steps = [];
                                if (is_array($stepsRaw)) {
                                    foreach ($stepsRaw as $stepItem) {
                                        if (!is_array($stepItem)) {
                                            continue;
                                        }
                                        $name = trim((string)($stepItem['name'] ?? ''));
                                        if ($name === '') {
                                            continue;
                                        }
                                        // Ignore auto post-steps so progress stays intuitive.
                                        if (stripos($name, 'Post ') === 0 || stripos($name, 'Complete job') === 0) {
                                            continue;
                                        }
                                        $steps[] = $stepItem;
                                    }
                                }

                                if (!empty($steps)) {
                                    $totalSteps = count($steps);
                                    $completedSteps = 0;
                                    $activeStepName = '';
                                    foreach ($steps as $s) {
                                        $stepStatus = strtolower(trim((string)($s['status'] ?? '')));
                                        if ($stepStatus === 'completed') {
                                            $completedSteps++;
                                        } elseif ($activeStepName === '' && $stepStatus === 'in_progress') {
                                            $activeStepName = trim((string)($s['name'] ?? ''));
                                        }
                                    }

                                    $estimated = (int)round(($completedSteps / max($totalSteps, 1)) * 95);
                                    if ($estimated < 15) $estimated = 15;
                                    if ($estimated > 95) $estimated = 95;
                                    if ($estimated > $progressPercent) {
                                        $progressPercent = $estimated;
                                        $progressData['progress'] = $progressPercent;
                                    }

                                    if ($activeStepName === '') {
                                        $activeStepName = trim((string)($steps[min($completedSteps, $totalSteps - 1)]['name'] ?? 'Working...'));
                                    }

                                    $progressData['step'] = 'github_steps';
                                    $progressData['status'] = 'info';
                                    $progressData['message'] = 'GitHub step: ' . $activeStepName;
                                    $progressData['time'] = date('H:i:s');
                                    $dataChanged = true;
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($statusChanged || $dataChanged) {
            $payload = cpEncodeProgressData($progressData);
            $pdo->prepare("
                UPDATE automation_settings
                SET status = ?,
                    progress_percent = ?,
                    progress_data = ?,
                    last_progress_time = NOW(),
                    last_run_at = CASE WHEN ? IN ('completed','error') THEN NOW() ELSE last_run_at END
                WHERE id = ?
            ")->execute([$automation['status'], $progressPercent, $payload, $automation['status'], $automationId]);
            $automation['last_progress_time'] = date('Y-m-d H:i:s');
        }
    }
}

// For completed GitHub runs, retry artifact import on poll until artifacts become available.
if (($automation['run_mode'] ?? 'local') === 'github_runner' && $automation['status'] === 'completed') {
    $runIdCompleted = cpExtractRunId($progressData);
    $ghCompleted = cpGithubSettings($pdo);
    if (!empty($ghCompleted['ready']) && $runIdCompleted > 0) {
        $lastImportTry = (int)($progressData['artifact_import_try'] ?? 0);
        if ((time() - $lastImportTry) >= 10) {
            $beforePayload = cpEncodeProgressData($progressData);
            $import = cpImportRunArtifacts($ghCompleted, $runIdCompleted, $progressData);
            $progressData = $import['progressData'];
            $progressData['artifact_import_try'] = time();

            if (!empty($import['imported'])) {
                $progressData['status'] = 'success';
                $progressData['step'] = 'artifact_import';
                $progressData['message'] = 'Imported ' . count($import['imported']) . ' output video(s) from GitHub artifacts.';
                $progressData['time'] = date('H:i:s');
            }

            $afterPayload = cpEncodeProgressData($progressData);
            if ($afterPayload !== $beforePayload) {
                $pdo->prepare("
                    UPDATE automation_settings
                    SET progress_data = ?,
                        last_progress_time = NOW()
                    WHERE id = ?
                ")->execute([$afterPayload, $automationId]);
                $automation['last_progress_time'] = date('Y-m-d H:i:s');
            }
        }
    }
}

$nextRunTs = !empty($automation['next_run_at']) ? strtotime((string)$automation['next_run_at']) : false;
$hasFutureNextRun = ($nextRunTs !== false && $nextRunTs > time());
$cycleCompleted = (
    ($automation['status'] === 'running') &&
    ($progressPercent >= 100) &&
    $hasFutureNextRun
);

$queuePosition = 0;
if ($automation['status'] === 'queued') {
    $stmt = $pdo->prepare("SELECT COUNT(*) as pos FROM automation_settings WHERE status = 'queued' AND id < ?");
    $stmt->execute([$automationId]);
    $pos = $stmt->fetch();
    $queuePosition = ((int)($pos['pos'] ?? 0)) + 1;

    $stmt = $pdo->query("SELECT name FROM automation_settings WHERE status = 'processing' LIMIT 1");
    $processing = $stmt->fetch();
    if ($processing) {
        $progressData['message'] = "Queue position: #{$queuePosition}. Waiting for '{$processing['name']}' to finish.";
        $progressData['step'] = 'queued';
        $progressData['status'] = 'info';
    }
}

$recentLogs = [];
if (!empty($_GET['with_logs'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT action, status, message, created_at
            FROM automation_logs
            WHERE automation_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$automationId]);
        $recentLogs = array_reverse($stmt->fetchAll());
    } catch (Exception $e) {
        $recentLogs = [];
    }
}

echo json_encode([
    'success' => true,
    'status' => $automation['status'],
    'progress' => $progressPercent,
    'data' => $progressData,
    'lastUpdate' => $automation['last_progress_time'] ?? null,
    'nextRunAt' => $automation['next_run_at'] ?? null,
    'nextRunTs' => ($nextRunTs !== false ? $nextRunTs : null),
    'enabled' => (int)($automation['enabled'] ?? 0),
    'queuePosition' => $queuePosition,
    'logs' => $recentLogs,
    'done' => in_array($automation['status'], ['completed', 'error', 'stopped', 'inactive'], true) || $cycleCompleted
]);
