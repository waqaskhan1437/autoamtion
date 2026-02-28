<?php
/**
 * Check Automation Progress
 * Returns current status for polling.
 */

error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(180);
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

function cpGithubRequest(string $url, string $token, bool $binary = false, int $timeout = 90): array
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

function cpParseMarkersFromText(string $content, int &$autoSeq = 0): array
{
    if ($content === '' || strpos($content, 'VW_PROGRESS_B64:') === false) {
        return [];
    }
    if (!preg_match_all('/VW_PROGRESS_B64:([A-Za-z0-9+\/=]+)/', $content, $matches)) {
        return [];
    }

    $markers = [];
    foreach ($matches[1] as $b64) {
        $raw = base64_decode((string)$b64, true);
        if ($raw === false || $raw === '') {
            continue;
        }
        $marker = json_decode($raw, true);
        if (!is_array($marker)) {
            continue;
        }

        $seq = (int)($marker['sequence'] ?? 0);
        if ($seq <= 0) {
            $autoSeq++;
            $seq = 1000000 + $autoSeq;
        }
        $marker['_seq'] = $seq;
        $marker['_ts'] = (int)($marker['time_unix'] ?? 0);
        $markers[] = $marker;
    }

    return $markers;
}

function cpSortMarkers(array $markers): array
{
    if (empty($markers)) {
        return [];
    }
    usort($markers, function ($a, $b) {
        $sa = (int)($a['_seq'] ?? 0);
        $sb = (int)($b['_seq'] ?? 0);
        if ($sa === $sb) {
            return ((int)($a['_ts'] ?? 0)) <=> ((int)($b['_ts'] ?? 0));
        }
        return $sa <=> $sb;
    });
    return $markers;
}

function cpExtractMarkersFromLogsZip(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return [];
    }

    $autoSeq = 0;
    $markers = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $content = $zip->getFromIndex($i);
        if (!is_string($content) || $content === '') {
            continue;
        }
        $parsed = cpParseMarkersFromText($content, $autoSeq);
        if (!empty($parsed)) {
            foreach ($parsed as $m) {
                $markers[] = $m;
            }
        }
    }

    $zip->close();
    return cpSortMarkers($markers);
}

function cpFetchProgressMarkers(string $owner, string $repo, int $runId, string $token): array
{
    if ($runId <= 0) {
        return [];
    }

    $url = "https://api.github.com/repos/{$owner}/{$repo}/actions/runs/{$runId}/logs";
    $res = cpGithubRequest($url, $token, true, 120);
    if (!$res['success'] || (int)($res['http'] ?? 0) !== 200 || !is_string($res['body']) || $res['body'] === '') {
        return [];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ghlog_');
    if ($tmp === false) {
        return [];
    }

    file_put_contents($tmp, $res['body']);
    $markers = cpExtractMarkersFromLogsZip($tmp);
    @unlink($tmp);
    return $markers;
}

function cpFetchProgressMarkersFromJobLogs(string $owner, string $repo, int $runId, string $token): array
{
    if ($runId <= 0) {
        return [];
    }

    $jobsUrl = "https://api.github.com/repos/{$owner}/{$repo}/actions/runs/{$runId}/jobs?per_page=10";
    $jobsRes = cpGithubRequest($jobsUrl, $token, false, 20);
    if (!$jobsRes['success'] || (int)($jobsRes['http'] ?? 0) !== 200 || !is_array($jobsRes['json'])) {
        return [];
    }

    $jobs = $jobsRes['json']['jobs'] ?? [];
    if (!is_array($jobs) || empty($jobs)) {
        return [];
    }

    $autoSeq = 0;
    $markers = [];
    foreach ($jobs as $job) {
        if (!is_array($job)) {
            continue;
        }
        $jobId = (int)($job['id'] ?? 0);
        if ($jobId <= 0) {
            continue;
        }

        $jobStatus = strtolower(trim((string)($job['status'] ?? '')));
        if (in_array($jobStatus, ['queued', 'waiting', 'requested'], true)) {
            continue;
        }

        $logUrl = "https://api.github.com/repos/{$owner}/{$repo}/actions/jobs/{$jobId}/logs";
        $logRes = cpGithubRequest($logUrl, $token, true, 60);
        if (!$logRes['success'] || (int)($logRes['http'] ?? 0) !== 200 || !is_string($logRes['body']) || $logRes['body'] === '') {
            continue;
        }

        $parsed = cpParseMarkersFromText($logRes['body'], $autoSeq);
        if (!empty($parsed)) {
            foreach ($parsed as $m) {
                $markers[] = $m;
            }
        }
    }

    return cpSortMarkers($markers);
}

function cpNormalizeStats($stats): array
{
    $base = [
        'fetched' => 0,
        'downloaded' => 0,
        'processed' => 0,
        'scheduled' => 0,
        'posted' => 0,
        'errors' => 0
    ];
    if (!is_array($stats)) {
        return $base;
    }
    foreach ($base as $k => $v) {
        if (isset($stats[$k])) {
            $base[$k] = (int)$stats[$k];
        }
    }
    return $base;
}

function cpNormalizeScheduledAt(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $ts);
}

function cpAutomationAccountIds(PDO $pdo, int $automationId): ?string
{
    static $cache = [];
    if (array_key_exists($automationId, $cache)) {
        return $cache[$automationId];
    }

    try {
        $stmt = $pdo->prepare("SELECT postforme_account_ids FROM automation_settings WHERE id = ? LIMIT 1");
        $stmt->execute([$automationId]);
        $raw = $stmt->fetchColumn();
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded) && !empty($decoded)) {
            $cache[$automationId] = json_encode(array_values(array_unique(array_filter(array_map('strval', $decoded)))));
            return $cache[$automationId];
        }
    } catch (Exception $e) {
    }

    $cache[$automationId] = null;
    return null;
}

function cpUpsertPostForMeFromMarker(PDO $pdo, int $automationId, string $message, ?string $videoName = null): void
{
    if (!preg_match('/Post ID:\s*([A-Za-z0-9_\-]+)/i', $message, $pm)) {
        return;
    }
    $postId = trim((string)$pm[1]);
    if ($postId === '') {
        return;
    }

    $scheduledAt = null;
    if (preg_match('/scheduled:\s*([^)]+)\)/i', $message, $sm)) {
        $scheduledAt = cpNormalizeScheduledAt((string)$sm[1]);
    }

    $status = 'pending';
    if ($scheduledAt !== null || stripos($message, 'SCHEDULED!') !== false) {
        $status = 'scheduled';
    } elseif (stripos($message, 'POSTED!') !== false) {
        $status = 'posted';
    }

    $accountIdsJson = cpAutomationAccountIds($pdo, $automationId);
    $videoId = ($videoName !== null && trim($videoName) !== '') ? trim($videoName) : null;

    try {
        $sel = $pdo->prepare("SELECT id FROM postforme_posts WHERE post_id = ? LIMIT 1");
        $sel->execute([$postId]);
        $existingId = (int)($sel->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $up = $pdo->prepare("
                UPDATE postforme_posts
                SET automation_id = COALESCE(automation_id, ?),
                    video_id = COALESCE(video_id, ?),
                    account_ids = COALESCE(account_ids, ?),
                    status = ?,
                    scheduled_at = COALESCE(scheduled_at, ?),
                    published_at = CASE WHEN ? = 'posted' THEN COALESCE(published_at, NOW()) ELSE published_at END
                WHERE id = ?
            ");
            $up->execute([$automationId, $videoId, $accountIdsJson, $status, $scheduledAt, $status, $existingId]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO postforme_posts (post_id, automation_id, video_id, account_ids, status, scheduled_at, published_at)
                VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ? = 'posted' THEN NOW() ELSE NULL END)
            ");
            $ins->execute([$postId, $automationId, $videoId, $accountIdsJson, $status, $scheduledAt, $status]);
        }
    } catch (Exception $e) {
    }
}

function cpApplyMarkers(PDO $pdo, int $automationId, array &$progressData, int &$progressPercent, array $markers): bool
{
    if (empty($markers)) {
        return false;
    }

    $changed = false;
    $lastSeq = (int)($progressData['sequence'] ?? 0);
    $currentVideo = trim((string)($progressData['current_video'] ?? ''));
    $outputs = [];
    if (!empty($progressData['outputs']) && is_array($progressData['outputs'])) {
        foreach ($progressData['outputs'] as $o) {
            $v = trim((string)$o);
            if ($v !== '' && !in_array($v, $outputs, true)) {
                $outputs[] = $v;
            }
        }
    }
    $stats = cpNormalizeStats($progressData['stats'] ?? []);

    foreach ($markers as $marker) {
        $seq = (int)($marker['_seq'] ?? $marker['sequence'] ?? 0);
        if ($seq <= $lastSeq) {
            continue;
        }

        $message = trim((string)($marker['message'] ?? 'Runner update.'));
        $step = trim((string)($marker['step'] ?? 'github_runner'));
        $eventStatus = trim((string)($marker['event_status'] ?? $marker['status'] ?? 'info'));
        if ($eventStatus === '') {
            $eventStatus = 'info';
        }

        if (preg_match('/Processing:\s*(.+)$/i', $message, $m)) {
            $currentVideo = trim((string)$m[1]);
        } elseif (preg_match('/Downloading:\s*([^()]+)\(/i', $message, $m)) {
            $currentVideo = trim((string)$m[1]);
        }

        if (isset($marker['progress'])) {
            $progressPercent = cpClampPercent($marker['progress']);
        }
        if (!empty($marker['stats']) && is_array($marker['stats'])) {
            $stats = cpNormalizeStats($marker['stats']);
        }
        if (!empty($marker['outputs']) && is_array($marker['outputs'])) {
            foreach ($marker['outputs'] as $ov) {
                $vv = trim((string)$ov);
                if ($vv !== '' && !in_array($vv, $outputs, true)) {
                    $outputs[] = $vv;
                }
            }
        }
        if (preg_match('/(?:Created|Output):\s*([^\r\n]+?\.(mp4|mov|mkv|webm|avi))/i', $message, $om)) {
            $ov = basename(trim((string)$om[1]));
            if ($ov !== '' && !in_array($ov, $outputs, true)) {
                $outputs[] = $ov;
            }
        }

        if (stripos($message, 'Post ID:') !== false) {
            cpUpsertPostForMeFromMarker($pdo, $automationId, $message, $currentVideo !== '' ? $currentVideo : null);
        }

        $progressData['sequence'] = $seq;
        $progressData['step'] = $step;
        $progressData['status'] = $eventStatus;
        $progressData['message'] = $message;
        $progressData['progress'] = $progressPercent;
        $progressData['stats'] = $stats;
        $progressData['outputs'] = $outputs;
        $progressData['time'] = date('H:i:s');
        if (!empty($marker['run_url'])) {
            $progressData['run_url'] = trim((string)$marker['run_url']);
        }
        $changed = true;
        $lastSeq = $seq;
    }

    $progressData['current_video'] = $currentVideo;
    return $changed;
}

function cpBackfillPostForMeFromMarkers(PDO $pdo, int $automationId, array $markers): void
{
    if (empty($markers)) {
        return;
    }

    $currentVideo = '';
    foreach ($markers as $marker) {
        $message = trim((string)($marker['message'] ?? ''));
        if ($message === '') {
            continue;
        }

        if (preg_match('/Processing:\s*(.+)$/i', $message, $m)) {
            $currentVideo = trim((string)$m[1]);
        } elseif (preg_match('/Downloading:\s*([^()]+)\(/i', $message, $m)) {
            $currentVideo = trim((string)$m[1]);
        }

        if (stripos($message, 'Post ID:') !== false) {
            cpUpsertPostForMeFromMarker($pdo, $automationId, $message, $currentVideo !== '' ? $currentVideo : null);
        }
    }
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

if (($automation['run_mode'] ?? 'local') === 'github_runner' && in_array($automation['status'], ['running', 'processing', 'completed'], true)) {
    $gh = cpGithubSettings($pdo);
    $runId = cpExtractRunId($progressData);

    if (!empty($gh['ready']) && $runId > 0) {
        $nowTs = time();
        $runUrl = trim((string)($progressData['run_url'] ?? ''));
        $markerApplied = false;

        $lastLogSync = (int)($progressData['github_log_synced_at'] ?? 0);
        if (($nowTs - $lastLogSync) >= 6) {
            $markers = cpFetchProgressMarkers($gh['owner'], $gh['repo'], $runId, $gh['token']);
            if (empty($markers) && in_array(($automation['status'] ?? ''), ['running', 'processing'], true)) {
                // Workflow run logs can be unavailable until completion; job logs are usually live.
                $markers = cpFetchProgressMarkersFromJobLogs($gh['owner'], $gh['repo'], $runId, $gh['token']);
            }
            $progressData['github_log_synced_at'] = $nowTs;
            if (!empty($markers)) {
                $markerApplied = cpApplyMarkers($pdo, $automationId, $progressData, $progressPercent, $markers);
                if ($markerApplied) {
                    $dataChanged = true;
                }

                if (($automation['status'] ?? '') === 'completed' && empty($progressData['postforme_backfilled'])) {
                    cpBackfillPostForMeFromMarkers($pdo, $automationId, $markers);
                    $progressData['postforme_backfilled'] = 1;
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
                    $newStatus = $isSuccess ? 'completed' : 'error';
                    if ($automation['status'] !== $newStatus) {
                        $automation['status'] = $newStatus;
                        $statusChanged = true;
                    }

                    if ($isSuccess) {
                        $progressPercent = 100;
                        if (!$markerApplied && empty($progressData['message'])) {
                            $progressData['step'] = 'github_runner';
                            $progressData['status'] = 'success';
                            $progressData['message'] = 'GitHub workflow completed successfully.';
                            $progressData['time'] = date('H:i:s');
                        }
                    } else {
                        $progressData['step'] = 'github_runner';
                        $progressData['status'] = 'error';
                        $progressData['message'] = 'GitHub workflow failed: ' . ($ghConclusion !== '' ? $ghConclusion : 'unknown');
                        $progressData['time'] = date('H:i:s');
                    }
                    $progressData['progress'] = $progressPercent;
                    $dataChanged = true;

                    try {
                        $pdo->prepare("
                            INSERT INTO automation_logs (automation_id, action, status, message)
                            VALUES (?, 'github_status_sync', ?, ?)
                        ")->execute([
                            $automationId,
                            $isSuccess ? 'success' : 'error',
                            (string)($progressData['message'] ?? ($isSuccess ? 'GitHub workflow completed.' : 'GitHub workflow failed.'))
                        ]);
                    } catch (Exception $e) {
                    }
                } elseif (in_array($ghStatus, ['queued', 'in_progress', 'waiting', 'requested'], true)) {
                    if ($automation['status'] !== 'processing') {
                        $automation['status'] = 'processing';
                        $statusChanged = true;
                    }

                    // Fallback when log markers are not available yet.
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
                                        if (!is_array($stepItem)) continue;
                                        $name = trim((string)($stepItem['name'] ?? ''));
                                        if ($name === '') continue;
                                        if (stripos($name, 'Post ') === 0 || stripos($name, 'Complete job') === 0) continue;
                                        $steps[] = $stepItem;
                                    }
                                }

                                if (!empty($steps)) {
                                    $total = count($steps);
                                    $completed = 0;
                                    $active = '';
                                    foreach ($steps as $s) {
                                        $st = strtolower(trim((string)($s['status'] ?? '')));
                                        if ($st === 'completed') {
                                            $completed++;
                                        } elseif ($active === '' && $st === 'in_progress') {
                                            $active = trim((string)($s['name'] ?? ''));
                                        }
                                    }

                                    $estimated = (int)round(($completed / max($total, 1)) * 95);
                                    if ($estimated < 15) $estimated = 15;
                                    if ($estimated > 95) $estimated = 95;
                                    if ($estimated > $progressPercent) {
                                        $progressPercent = $estimated;
                                    }
                                    if ($active === '') {
                                        $active = trim((string)($steps[min($completed, $total - 1)]['name'] ?? 'Working...'));
                                    }

                                    $progressData['step'] = 'github_steps';
                                    $progressData['status'] = 'info';
                                    $progressData['message'] = 'GitHub step: ' . $active;
                                    $progressData['progress'] = $progressPercent;
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
