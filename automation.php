<?php
require_once 'config.php';
require_once 'includes/AutomationRunner.php';

$message = '';
$messageType = 'success';

// Get AI provider settings for display
$aiSettings = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('ai_provider', 'gemini_api_key', 'openai_api_key')")->fetchAll(PDO::FETCH_KEY_PAIR);
$hasGemini = !empty($aiSettings['gemini_api_key']);
$hasOpenAI = !empty($aiSettings['openai_api_key']);
$selectedProvider = $aiSettings['ai_provider'] ?? 'gemini';
$activeAIProvider = ($selectedProvider === 'gemini' && $hasGemini) ? 'Gemini (FREE)' : (($selectedProvider === 'openai' && $hasOpenAI) ? 'OpenAI' : ($hasGemini ? 'Gemini (FREE)' : ($hasOpenAI ? 'OpenAI' : 'Not Configured')));
$hasAnyAI = $hasGemini || $hasOpenAI;

function normalizeManualVideoLinksInput($rawInput) {
    $raw = is_string($rawInput) ? $rawInput : (string)$rawInput;
    $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
    if ($raw === '') {
        return '';
    }

    $tokens = preg_split('/[\n,]+/', $raw) ?: [];
    $seen = [];
    $clean = [];

    foreach ($tokens as $token) {
        $url = trim((string)$token);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $clean[] = $url;
    }

    return implode("\n", $clean);
}

function normalizeYouTubeChannelUrlInput($rawInput) {
    $url = trim(is_string($rawInput) ? $rawInput : (string)$rawInput);
    if ($url === '') {
        return '';
    }

    if (strpos($url, '@') === 0) {
        $url = 'https://www.youtube.com/' . ltrim($url, '/');
    }

    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }

    return $url;
}

function normalizeSourceShortsModeInput($rawInput) {
    $mode = strtolower(trim(is_string($rawInput) ? $rawInput : (string)$rawInput));
    $allowed = ['single', 'duration_based', 'fixed_count'];
    return in_array($mode, $allowed, true) ? $mode : 'single';
}

function normalizeSourceShortsMaxCountInput($rawInput, $mode = 'single') {
    $count = intval($rawInput ?? 1);
    if ($count < 1) {
        $count = 1;
    }
    if ($count > 20) {
        $count = 20;
    }
    if ($mode === 'single') {
        return 1;
    }
    return $count;
}

function normalizePlaybackSpeedInput($rawInput) {
    $speed = is_numeric($rawInput) ? (float)$rawInput : 1.0;
    if ($speed < 0.1) {
        $speed = 0.1;
    }
    if ($speed > 3.0) {
        $speed = 3.0;
    }
    return number_format($speed, 1, '.', '');
}

// Handle POST requests and redirect to prevent form resubmission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirectMsg = '';
    
    if ($action === 'create') {
        $randomWords = array_filter(array_map('trim', explode(',', $_POST['random_words'] ?? '')));
        
        // Post for Me account IDs (as JSON array)
        $postformeAccountIds = isset($_POST['postforme_account_ids']) ? json_encode($_POST['postforme_account_ids']) : '[]';
        
        $stmt = $pdo->prepare("INSERT INTO automation_settings (name, video_source, manual_video_links, youtube_channel_url, run_mode, api_key_id, enabled, video_days_filter, video_start_date, video_end_date, videos_per_run, short_duration, playback_speed, source_shorts_mode, source_shorts_max_count, short_aspect_ratio, ai_taglines_enabled, ai_tagline_prompt, branding_text_top, branding_text_bottom, random_words, whisper_enabled, whisper_language, schedule_type, schedule_hour, schedule_every_minutes, youtube_enabled, youtube_api_key, youtube_channel_id, tiktok_enabled, tiktok_access_token, instagram_enabled, instagram_access_token, facebook_enabled, facebook_access_token, facebook_page_id, postforme_enabled, postforme_account_ids, postforme_schedule_mode, postforme_schedule_datetime, postforme_schedule_timezone, postforme_schedule_offset_minutes, postforme_schedule_spread_minutes, rotation_enabled, rotation_shuffle, rotation_auto_reset, status, next_run_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $status = $enabled ? 'running' : 'inactive';
        
        $scheduleDatetime = !empty($_POST['postforme_schedule_datetime']) ? $_POST['postforme_schedule_datetime'] : null;
        
        // Handle video selection method - explicitly handle date values
        $videoSelectionMethod = $_POST['video_selection_method_hidden'] ?? 'days';
        $videoDaysFilter = ($videoSelectionMethod === 'days') ? intval($_POST['video_days_filter'] ?? 30) : null;
        
        // Get date values - handle empty strings properly and convert empty strings to null
        $videoStartDate = ($videoSelectionMethod === 'date_range' && isset($_POST['video_start_date']) && trim($_POST['video_start_date']) !== '') ? $_POST['video_start_date'] : null;
        $videoEndDate = ($videoSelectionMethod === 'date_range' && isset($_POST['video_end_date']) && trim($_POST['video_end_date']) !== '') ? $_POST['video_end_date'] : null;
        
        $videosPerRun = intval($_POST['videos_per_run'] ?? 5);
        if ($videosPerRun < 1) $videosPerRun = 1;
        if ($videosPerRun > 500) $videosPerRun = 500;
        $playbackSpeed = normalizePlaybackSpeedInput($_POST['playback_speed'] ?? 1.0);
        $sourceShortsMode = normalizeSourceShortsModeInput($_POST['source_shorts_mode'] ?? 'single');
        $sourceShortsMaxCount = normalizeSourceShortsMaxCountInput($_POST['source_shorts_max_count'] ?? 1, $sourceShortsMode);
        
        $nextRunAt = null;
        if ($enabled) {
            $scheduleType = $_POST['schedule_type'] ?? 'daily';
            $scheduleHour = intval($_POST['schedule_hour'] ?? 9);
            $scheduleEveryMinutes = intval($_POST['schedule_every_minutes'] ?? 10);
            $nextRunAt = calculateAutomationNextRunAt($scheduleType, $scheduleHour, $scheduleEveryMinutes);
        }
        
        $videoSource = $_POST['video_source'] ?? 'ftp';
        $manualVideoLinks = ($videoSource === 'manual_links')
            ? normalizeManualVideoLinksInput($_POST['manual_video_links'] ?? '')
            : null;
        $youtubeChannelUrl = ($videoSource === 'youtube_channel')
            ? normalizeYouTubeChannelUrlInput($_POST['youtube_channel_url'] ?? '')
            : null;

        $stmt->execute([
            $_POST['name'],
            $videoSource,
            $manualVideoLinks,
            $youtubeChannelUrl !== '' ? $youtubeChannelUrl : null,
            $_POST['run_mode'] ?? 'local',
            $_POST['api_key_id'] ?: null,
            $enabled,
            $videoDaysFilter,
            $videoStartDate,
            $videoEndDate,
            $videosPerRun,
            $_POST['short_duration'] ?? 60,
            $playbackSpeed,
            $sourceShortsMode,
            $sourceShortsMaxCount,
            $_POST['short_aspect_ratio'] ?? '9:16',
            isset($_POST['ai_taglines_enabled']) ? 1 : 0,
            $_POST['ai_tagline_prompt'] ?? null,
            $_POST['branding_text_top'] ?? null,
            $_POST['branding_text_bottom'] ?? null,
            json_encode($randomWords),
            isset($_POST['whisper_enabled']) ? 1 : 0,
            $_POST['whisper_language'] ?? 'en',
            $_POST['schedule_type'] ?? 'daily',
            $_POST['schedule_hour'] ?? 9,
            intval($_POST['schedule_every_minutes'] ?? 10),
            isset($_POST['youtube_enabled']) ? 1 : 0,
            $_POST['youtube_api_key'] ?? null,
            $_POST['youtube_channel_id'] ?? null,
            isset($_POST['tiktok_enabled']) ? 1 : 0,
            $_POST['tiktok_access_token'] ?? null,
            isset($_POST['instagram_enabled']) ? 1 : 0,
            $_POST['instagram_access_token'] ?? null,
            isset($_POST['facebook_enabled']) ? 1 : 0,
            $_POST['facebook_access_token'] ?? null,
            $_POST['facebook_page_id'] ?? null,
            isset($_POST['postforme_enabled']) ? 1 : 0,
            $postformeAccountIds,
            $_POST['postforme_schedule_mode'] ?? 'immediate',
            $scheduleDatetime,
            $_POST['postforme_schedule_timezone'] ?? 'UTC',
            intval($_POST['postforme_schedule_offset_minutes'] ?? 0),
            intval($_POST['postforme_schedule_spread_minutes'] ?? 0),
            isset($_POST['rotation_enabled']) ? 1 : 0,
            isset($_POST['rotation_shuffle']) ? 1 : 0,
            isset($_POST['rotation_auto_reset']) ? 1 : 0,
            $status,
            $nextRunAt
        ]);
        // Redirect to prevent form resubmission on refresh
        header('Location: automation.php?msg=created');
        exit;
    } elseif ($action === 'toggle') {
        $newStatus = $_POST['current_enabled'] == '1' ? 0 : 1;
        $statusText = $newStatus ? 'running' : 'stopped';
        
        if ($newStatus) {
            // When enabling, calculate next_run_at based on schedule
            $stmt = $pdo->prepare("SELECT schedule_type, schedule_hour, schedule_every_minutes FROM automation_settings WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $automation = $stmt->fetch();
            
            $scheduleType = $automation['schedule_type'] ?? 'daily';
            $scheduleHour = $automation['schedule_hour'] ?? 9;
            $scheduleEveryMinutes = $automation['schedule_every_minutes'] ?? 10;
            $nextRunAt = calculateAutomationNextRunAt($scheduleType, $scheduleHour, $scheduleEveryMinutes);
            
            $stmt = $pdo->prepare("UPDATE automation_settings SET enabled = ?, status = ?, next_run_at = ? WHERE id = ?");
            $stmt->execute([$newStatus, $statusText, $nextRunAt, $_POST['id']]);
        } else {
            // When disabling, clear next_run_at
            $stmt = $pdo->prepare("UPDATE automation_settings SET enabled = ?, status = ?, next_run_at = NULL WHERE id = ?");
            $stmt->execute([$newStatus, $statusText, $_POST['id']]);
        }
        
        header('Location: automation.php?msg=toggled');
        exit;
    } elseif ($action === 'update') {
        $randomWords = array_filter(array_map('trim', explode(',', $_POST['random_words'] ?? '')));
        
        // Post for Me account IDs (as JSON array)
        $postformeAccountIds = isset($_POST['postforme_account_ids']) ? json_encode($_POST['postforme_account_ids']) : '[]';
        
        $stmt = $pdo->prepare("UPDATE automation_settings SET name=?, video_source=?, manual_video_links=?, youtube_channel_url=?, run_mode=?, api_key_id=?, video_days_filter=?, video_start_date=?, video_end_date=?, videos_per_run=?, short_duration=?, playback_speed=?, source_shorts_mode=?, source_shorts_max_count=?, short_aspect_ratio=?, ai_taglines_enabled=?, ai_tagline_prompt=?, branding_text_top=?, branding_text_bottom=?, random_words=?, whisper_enabled=?, whisper_language=?, schedule_type=?, schedule_hour=?, schedule_every_minutes=?, youtube_enabled=?, youtube_api_key=?, youtube_channel_id=?, tiktok_enabled=?, tiktok_access_token=?, instagram_enabled=?, instagram_access_token=?, facebook_enabled=?, facebook_access_token=?, facebook_page_id=?, postforme_enabled=?, postforme_account_ids=?, postforme_schedule_mode=?, postforme_schedule_datetime=?, postforme_schedule_timezone=?, postforme_schedule_offset_minutes=?, postforme_schedule_spread_minutes=?, rotation_enabled=?, rotation_shuffle=?, rotation_auto_reset=?, status=?, enabled=?, next_run_at=? WHERE id=?");
        
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $status = $enabled ? 'running' : 'inactive';
        
        $scheduleDatetime = !empty($_POST['postforme_schedule_datetime']) ? $_POST['postforme_schedule_datetime'] : null;
        
        // Handle video selection method - explicitly handle date values
        $videoSelectionMethod = $_POST['video_selection_method_hidden'] ?? 'days';
        $videoDaysFilter = ($videoSelectionMethod === 'days') ? intval($_POST['video_days_filter'] ?? 30) : null;
        
        // Get date values - handle empty strings properly and convert empty strings to null
        $videoStartDate = ($videoSelectionMethod === 'date_range' && isset($_POST['video_start_date']) && trim($_POST['video_start_date']) !== '') ? $_POST['video_start_date'] : null;
        $videoEndDate = ($videoSelectionMethod === 'date_range' && isset($_POST['video_end_date']) && trim($_POST['video_end_date']) !== '') ? $_POST['video_end_date'] : null;
        
        $videosPerRun = intval($_POST['videos_per_run'] ?? 5);
        if ($videosPerRun < 1) $videosPerRun = 1;
        if ($videosPerRun > 500) $videosPerRun = 500;
        $playbackSpeed = normalizePlaybackSpeedInput($_POST['playback_speed'] ?? 1.0);
        $sourceShortsMode = normalizeSourceShortsModeInput($_POST['source_shorts_mode'] ?? 'single');
        $sourceShortsMaxCount = normalizeSourceShortsMaxCountInput($_POST['source_shorts_max_count'] ?? 1, $sourceShortsMode);
        $scheduleType = $_POST['schedule_type'] ?? 'daily';
        $scheduleHour = intval($_POST['schedule_hour'] ?? 9);
        $scheduleEveryMinutes = intval($_POST['schedule_every_minutes'] ?? 10);
        $nextRunAt = $enabled ? calculateAutomationNextRunAt($scheduleType, $scheduleHour, $scheduleEveryMinutes) : null;
        
        $videoSource = $_POST['video_source'] ?? 'ftp';
        $manualVideoLinks = ($videoSource === 'manual_links')
            ? normalizeManualVideoLinksInput($_POST['manual_video_links'] ?? '')
            : null;
        $youtubeChannelUrl = ($videoSource === 'youtube_channel')
            ? normalizeYouTubeChannelUrlInput($_POST['youtube_channel_url'] ?? '')
            : null;

        $stmt->execute([
            $_POST['name'],
            $videoSource,
            $manualVideoLinks,
            $youtubeChannelUrl !== '' ? $youtubeChannelUrl : null,
            $_POST['run_mode'] ?? 'local',
            $_POST['api_key_id'] ?: null,
            $videoDaysFilter,
            $videoStartDate,
            $videoEndDate,
            $videosPerRun,
            $_POST['short_duration'] ?? 60,
            $playbackSpeed,
            $sourceShortsMode,
            $sourceShortsMaxCount,
            $_POST['short_aspect_ratio'] ?? '9:16',
            isset($_POST['ai_taglines_enabled']) ? 1 : 0,
            $_POST['ai_tagline_prompt'] ?? null,
            $_POST['branding_text_top'] ?? null,
            $_POST['branding_text_bottom'] ?? null,
            json_encode($randomWords),
            isset($_POST['whisper_enabled']) ? 1 : 0,
            $_POST['whisper_language'] ?? 'en',
            $scheduleType,
            $scheduleHour,
            $scheduleEveryMinutes,
            isset($_POST['youtube_enabled']) ? 1 : 0,
            $_POST['youtube_api_key'] ?? null,
            $_POST['youtube_channel_id'] ?? null,
            isset($_POST['tiktok_enabled']) ? 1 : 0,
            $_POST['tiktok_access_token'] ?? null,
            isset($_POST['instagram_enabled']) ? 1 : 0,
            $_POST['instagram_access_token'] ?? null,
            isset($_POST['facebook_enabled']) ? 1 : 0,
            $_POST['facebook_access_token'] ?? null,
            $_POST['facebook_page_id'] ?? null,
            isset($_POST['postforme_enabled']) ? 1 : 0,
            $postformeAccountIds,
            $_POST['postforme_schedule_mode'] ?? 'immediate',
            $scheduleDatetime,
            $_POST['postforme_schedule_timezone'] ?? 'UTC',
            intval($_POST['postforme_schedule_offset_minutes'] ?? 0),
            intval($_POST['postforme_schedule_spread_minutes'] ?? 0),
            isset($_POST['rotation_enabled']) ? 1 : 0,
            isset($_POST['rotation_shuffle']) ? 1 : 0,
            isset($_POST['rotation_auto_reset']) ? 1 : 0,
            $status,
            $enabled,
            $nextRunAt,
            $_POST['id']
        ]);
        
        header('Location: automation.php?msg=updated');
        exit;
    } elseif ($action === 'reset_rotation') {
        $id = intval($_POST['id']);
        $pdo->prepare("UPDATE automation_settings SET rotation_cycle = 1 WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM processed_videos WHERE automation_id = ?")->execute([$id]);
        header('Location: automation.php?msg=rotation_reset');
        exit;
    } elseif ($action === 'stop') {
        // Get the process ID before updating status
        $stmt = $pdo->prepare("SELECT process_id FROM automation_settings WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $automation = $stmt->fetch();
        
        $stmt = $pdo->prepare("UPDATE automation_settings SET status = 'stopped', enabled = 0 WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
        // Attempt to kill the associated process if it exists
        if ($automation && $automation['process_id']) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /PID {$automation['process_id']} 2>NUL", $output, $exitCode);
            } else {
                exec("kill -TERM {$automation['process_id']} 2>/dev/null", $output, $exitCode);
            }
        }
        
        header('Location: automation.php?msg=stopped');
        exit;
    } elseif ($action === 'delete') {
        // Get the process ID before deleting
        $stmt = $pdo->prepare("SELECT process_id FROM automation_settings WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $automation = $stmt->fetch();
        
        $stmt = $pdo->prepare("UPDATE automation_settings SET status = 'stopped' WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        usleep(500000);
        
        // Attempt to kill the associated process if it exists
        if ($automation && $automation['process_id']) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /PID {$automation['process_id']} 2>NUL", $output, $exitCode);
            } else {
                exec("kill -TERM {$automation['process_id']} 2>/dev/null", $output, $exitCode);
            }
        }
        
        $stmt = $pdo->prepare("DELETE FROM automation_settings WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        header('Location: automation.php?msg=deleted');
        exit;
    } elseif ($action === 'run') {
        // Run action is handled via JavaScript/AJAX now
        header('Location: automation.php');
        exit;
    }
}

// Handle query string messages (from redirects)
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'created': $message = 'Automation created'; break;
        case 'toggled': $message = 'Automation updated'; break;
        case 'stopped': $message = 'Process stopped'; break;
        case 'deleted': $message = 'Automation deleted'; break;
    }
}

function calculateAutomationNextRunAt($scheduleType, $scheduleHour, $scheduleEveryMinutes = 10) {
    $nextRun = new DateTime();
    $scheduleHour = (int)$scheduleHour;
    $scheduleEveryMinutes = max(1, (int)$scheduleEveryMinutes);

    switch ($scheduleType) {
        case 'minutes':
            $nextRun->modify('+' . $scheduleEveryMinutes . ' minutes');
            break;
        case 'hourly':
            $nextRun->modify('+1 hour');
            break;
        case 'weekly':
            $nextRun->modify('next monday ' . $scheduleHour . ':00');
            break;
        case 'daily':
        default:
            if ((int)$nextRun->format('H') >= $scheduleHour) {
                $nextRun->modify('+1 day');
            }
            $nextRun->setTime($scheduleHour, 0, 0);
            break;
    }

    return $nextRun->format('Y-m-d H:i:s');
}

$stmt = $pdo->query("SELECT a.*, k.name as key_name FROM automation_settings a LEFT JOIN api_keys k ON a.api_key_id = k.id ORDER BY a.created_at DESC");
$automations = $stmt->fetchAll();

foreach ($automations as &$automation) {
    $isEnabled = !empty($automation['enabled']);
    $nextRunTs = !empty($automation['next_run_at']) ? strtotime((string)$automation['next_run_at']) : false;
    $missingNextRun = empty($automation['next_run_at']) || $nextRunTs === false;
    $hourlyDriftDetected = false;
    $minutesDriftDetected = false;

    if ($isEnabled && !$missingNextRun && (($automation['schedule_type'] ?? 'daily') === 'hourly')) {
        // Hourly schedules should not be several hours ahead; this usually indicates timezone-shifted data.
        $hourlyDriftDetected = ($nextRunTs > (time() + 7200));
    }
    if ($isEnabled && !$missingNextRun && (($automation['schedule_type'] ?? 'daily') === 'minutes')) {
        $intervalMin = max(1, (int)($automation['schedule_every_minutes'] ?? 10));
        // Minutes schedules should stay close to now; large future values indicate stale overwrite.
        $minutesDriftDetected = ($nextRunTs > (time() + (($intervalMin * 60) * 3)));
    }

    if ($isEnabled && ($missingNextRun || $hourlyDriftDetected || $minutesDriftDetected)) {
        $fixedNextRunAt = calculateAutomationNextRunAt(
            $automation['schedule_type'] ?? 'daily',
            $automation['schedule_hour'] ?? 9,
            $automation['schedule_every_minutes'] ?? 10
        );
        $pdo->prepare("UPDATE automation_settings SET next_run_at = ? WHERE id = ?")
            ->execute([$fixedNextRunAt, $automation['id']]);
        $automation['next_run_at'] = $fixedNextRunAt;
    }
}
unset($automation);

$stmt = $pdo->query("SELECT * FROM api_keys WHERE status = 'active'");
$keys = $stmt->fetchAll();

$selectedLogs = [];
$selectedLogAutomationId = isset($_GET['logs']) ? intval($_GET['logs']) : 0;
if ($selectedLogAutomationId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM automation_logs WHERE automation_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$selectedLogAutomationId]);
    $selectedLogs = $stmt->fetchAll();
}

include 'includes/header.php';
?>

<?php if ($message): ?>
    <script>document.addEventListener('DOMContentLoaded', () => showToast('<?= $message ?>'));</script>
<?php endif; ?>

<!-- Live Debug Banner -->
<div id="debug_banner" class="sticky top-2 z-40 mb-4 hidden">
    <div class="px-4 py-3 rounded-lg border border-red-500/30 bg-red-900/20 backdrop-blur">
        <div class="flex items-center justify-between mb-2">
            <div class="text-xs uppercase tracking-wider text-red-300 font-semibold">Live Debug</div>
            <div id="debug_banner_status" class="text-xs text-red-200">Idle</div>
        </div>
        <div id="debug_banner_body" class="text-xs font-mono text-red-100 space-y-1">
            <div>No live debug data yet.</div>
        </div>
    </div>
</div>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-semibold">Video Automations</h2>
        <p class="text-sm text-gray-400 mt-1">Auto-convert videos to shorts and post to social media</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="player.php" class="px-4 py-2 bg-green-600 hover:bg-green-700 rounded-lg flex items-center gap-2" title="View processed videos">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            View Processed Videos
        </a>
        <button onclick="openAllScheduledModal()" class="px-3 py-2 bg-amber-600 hover:bg-amber-700 rounded-lg flex items-center gap-2 text-sm" title="View/Delete scheduled posts across all automations">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            Scheduled Queue
        </button>
        <button onclick="killAllProcesses()" class="px-3 py-2 bg-red-600 hover:bg-red-700 rounded-lg flex items-center gap-2 text-sm" title="Stop all running background processes">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="1"/></svg>
            Stop All
        </button>
        <button onclick="document.getElementById('createModal').classList.remove('hidden')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Create Automation
        </button>
    </div>
</div>

<!-- Output Folder Info -->
<div class="card p-4 mb-6 bg-gradient-to-r from-gray-800 to-gray-900 border border-gray-700">
    <div class="flex flex-wrap items-center gap-4">
        <div class="flex items-center gap-2">
            <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
            <span class="text-gray-400">Output Folder:</span>
            <code class="bg-gray-900 px-3 py-1 rounded text-green-400 text-sm font-mono">C:/VideoWorkflow/output/</code>
        </div>
        <div class="flex items-center gap-2 text-sm text-gray-400">
            <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Processed videos save here with text overlays
        </div>
        <div class="ml-auto flex items-center gap-2">
            <div id="output_video_count">
                <span class="text-gray-500 text-sm">Loading...</span>
            </div>
            <button id="delete_output_videos_btn" onclick="deleteAllOutputVideos()" class="hidden px-3 py-1 bg-red-600 hover:bg-red-700 rounded-lg text-sm">
                Delete All Output Videos
            </button>
        </div>
    </div>
</div>
<script>
function renderOutputVideoCount(data) {
    const countEl = document.getElementById('output_video_count');
    const deleteBtn = document.getElementById('delete_output_videos_btn');

    if (data.success && Number(data.total) > 0) {
        countEl.innerHTML = `<a href="player.php" class="px-3 py-1 bg-green-600 rounded-lg text-sm hover:bg-green-700">${data.total} videos ready to view</a>`;
        deleteBtn.classList.remove('hidden');
    } else {
        countEl.innerHTML = '<span class="text-gray-500 text-sm">No processed videos yet</span>';
        deleteBtn.classList.add('hidden');
    }
}

function refreshOutputVideoCount() {
    fetch('api/list-output-videos.php')
        .then(r => r.json())
        .then(renderOutputVideoCount)
        .catch(() => {
            document.getElementById('output_video_count').innerHTML = '<span class="text-gray-500 text-sm">-</span>';
            document.getElementById('delete_output_videos_btn').classList.add('hidden');
        });
}

async function deleteAllOutputVideos() {
    if (!confirm('Delete all processed videos (local + GitHub output list)? This cannot be undone.')) {
        return;
    }

    const btn = document.getElementById('delete_output_videos_btn');
    const oldText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Deleting...';

    try {
        const formData = new FormData();
        formData.append('mode', 'all');

        const res = await fetch('api/delete-all-output-videos.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            const total = Number(data.deleted || 0);
            const localDeleted = Number(data.local_deleted || 0);
            const githubRefs = Number(data.github_refs_cleared || 0);
            showToast(`Deleted ${total} output item(s) [local: ${localDeleted}, github refs: ${githubRefs}]`);
            refreshOutputVideoCount();
        } else {
            showToast((data && data.error) ? data.error : 'Delete failed');
        }
    } catch (e) {
        showToast('Delete request failed');
    } finally {
        btn.disabled = false;
        btn.textContent = oldText;
    }
}

// Load output video count on page load
refreshOutputVideoCount();
</script>

<div class="grid gap-4">
    <?php if (empty($automations)): ?>
        <div class="card rounded-lg p-12 text-center text-gray-400">
            No automations yet. Create your first automation to auto-convert videos to shorts.
        </div>
    <?php else: ?>
        <?php foreach ($automations as $automation): ?>
            <?php $randomWords = json_decode($automation['random_words'] ?? '[]', true) ?: []; ?>
            <div class="card rounded-lg" data-automation-id="<?= $automation['id'] ?>" data-automation-name="<?= htmlspecialchars($automation['name']) ?>">
                <div class="p-4 flex items-center justify-between border-b border-gray-800">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center <?= $automation['status'] === 'running' ? 'bg-green-500/10' : 'bg-gray-700' ?>">
                            <svg class="w-5 h-5 <?= $automation['status'] === 'running' ? 'text-green-500' : 'text-gray-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                        </div>
                        <div>
                            <h3 class="font-semibold"><?= htmlspecialchars($automation['name']) ?></h3>
                            <div class="text-sm text-gray-400">
                                <?= (($automation['run_mode'] ?? 'local') === 'github_runner' ? 'GitHub Runner' : 'Local') ?> |
                                <?= $automation['schedule_type'] ?> | 
                                <?php 
                                if (!empty($automation['video_start_date']) && !empty($automation['video_end_date'])) {
                                    echo "From " . date('M j', strtotime($automation['video_start_date'])) . " to " . date('M j', strtotime($automation['video_end_date']));
                                } else {
                                    echo "Last " . ($automation['video_days_filter'] ?? 30) . " days";
                                }
                                ?>
                                | Process <?= intval($automation['videos_per_run'] ?? 5) ?>/run
                                | <?= (($automation['source_shorts_mode'] ?? 'single') === 'single')
                                    ? '1 short/source'
                                    : ('up to ' . intval($automation['source_shorts_max_count'] ?? 1) . ' shorts/source') ?>
                                | Speed <?= number_format((float)($automation['playback_speed'] ?? 1.0), 1) ?>x
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="?logs=<?= $automation['id'] ?>" class="p-2 hover:bg-gray-700 rounded" title="View Logs">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </a>
                        <button type="button" onclick="testFetch('<?= $automation['id'] ?>', '<?= $automation['video_source'] ?? 'ftp' ?>')" class="p-2 hover:bg-gray-700 rounded text-blue-400" title="Test Fetch Videos">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </button>
                        <?php if ($automation['status'] === 'processing'): ?>
                            <!-- Stop button when processing -->
                            <form method="POST" class="inline" onsubmit="return confirm('Stop this running process?')">
                                <input type="hidden" name="action" value="stop">
                                <input type="hidden" name="id" value="<?= $automation['id'] ?>">
                                <button type="submit" class="p-2 hover:bg-gray-700 rounded text-red-400 animate-pulse" title="Stop Running Process">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="1"/></svg>
                                </button>
                            </form>
                        <?php else: ?>
                            <!-- Run button when not processing -->
                            <form method="POST" class="inline" onsubmit="event.preventDefault(); runAutomationSmart('<?= $automation['id'] ?>', '<?= htmlspecialchars($automation['run_mode'] ?? 'local') ?>'); return false;">
                                <input type="hidden" name="action" value="run">
                                <input type="hidden" name="id" value="<?= $automation['id'] ?>">
                                <button type="submit" class="p-2 hover:bg-gray-700 rounded text-green-400" title="Run Now - Process Videos">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $automation['id'] ?>">
                            <input type="hidden" name="current_enabled" value="<?= $automation['enabled'] ?>">
                            <button type="submit" class="p-2 hover:bg-gray-700 rounded" title="<?= $automation['enabled'] ? 'Disable' : 'Enable' ?>">
                                <?php if ($automation['enabled']): ?>
                                    <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <?php else: ?>
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <?php endif; ?>
                            </button>
                        </form>
                        <?php if (!empty($automation['rotation_enabled'])): ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Reset rotation? This clears all tracking and starts fresh.')">
                            <input type="hidden" name="action" value="reset_rotation">
                            <input type="hidden" name="id" value="<?= $automation['id'] ?>">
                            <button type="submit" class="p-2 hover:bg-gray-700 rounded text-indigo-400" title="Reset Video Rotation (start fresh)">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            </button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this automation?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $automation['id'] ?>">
                            <button type="submit" class="p-2 hover:bg-gray-700 rounded text-red-500">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                        </form>
                        <button type="button" onclick='openEditModal(<?= json_encode($automation, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>)' class="p-2 hover:bg-gray-700 rounded text-blue-400" title="Edit Automation">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        </button>
                    </div>
                </div>
                <?php if ($selectedLogAutomationId === intval($automation['id'])): ?>
                    <div class="p-4 border-b border-gray-800 bg-gray-900/40">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-semibold">Logs: <?= htmlspecialchars($automation['name']) ?></h4>
                            <a href="automation.php" class="text-gray-400 hover:text-white text-sm">Close</a>
                        </div>
                        <div class="max-h-64 overflow-y-auto overflow-x-hidden space-y-2">
                            <?php if (empty($selectedLogs)): ?>
                                <div class="text-center text-gray-400 py-6">No logs yet</div>
                            <?php else: ?>
                                <?php foreach ($selectedLogs as $log): ?>
                                    <?php
                                    $platformIcons = [
                                        'youtube' => '<svg class="w-4 h-4 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/></svg>',
                                        'tiktok' => '<svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-5.2 1.74 2.89 2.89 0 012.31-4.64 2.93 2.93 0 01.88.13V9.4a6.84 6.84 0 00-1-.05A6.33 6.33 0 005 20.1a6.34 6.34 0 0010.86-4.43v-7a8.16 8.16 0 004.77 1.52v-3.4a4.85 4.85 0 01-1-.1z"/></svg>',
                                        'instagram' => '<svg class="w-4 h-4 text-pink-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069z"/></svg>',
                                        'facebook' => '<svg class="w-4 h-4 text-blue-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 8h-3v4h3v12h5v-12h3.642l.358-4h-4v-1.667c0-.955.192-1.333 1.115-1.333h2.885v-5h-3.808c-3.596 0-5.192 1.583-5.192 4.615v3.385z"/></svg>',
                                        'threads' => '<svg class="w-4 h-4 text-gray-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.5 12.186V12c.018-3.724 1.084-6.567 3.168-8.454C6.553 1.706 9.263 1 12 1c2.732 0 5.428.702 7.317 2.548 2.085 1.892 3.152 4.733 3.183 8.452v.186c-.017 3.712-1.079 6.554-3.157 8.446C17.459 22.5 14.778 23.2 12.186 24z"/></svg>',
                                        'postforme' => '<svg class="w-4 h-4 text-pink-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>'
                                    ];
                                    $platform = $log['platform'] ?? '';
                                    $platformIcon = $platformIcons[$platform] ?? '';
                                    $isPostForMe = strpos($log['action'], 'postforme') !== false;
                                    ?>
                                    <div class="p-3 border rounded-lg text-sm overflow-x-hidden <?php
                                        echo match($log['status']) {
                                            'error' => 'border-red-500/30 bg-red-500/5',
                                            'success' => 'border-green-500/30 bg-green-500/5',
                                            default => 'border-gray-700'
                                        };
                                    ?>">
                                        <div class="flex items-start justify-between gap-2 mb-1">
                                            <span class="font-medium flex items-center gap-2 min-w-0 break-all">
                                                <?php if ($platformIcon): ?>
                                                    <?= $platformIcon ?>
                                                <?php endif; ?>
                                                <?php if ($isPostForMe): ?>
                                                    <span class="text-pink-400"><?= htmlspecialchars($log['action']) ?></span>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($log['action']) ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="text-xs px-2 py-0.5 rounded <?php
                                                echo match($log['status']) {
                                                    'error' => 'bg-red-500/10 text-red-500',
                                                    'success' => 'bg-green-500/10 text-green-500',
                                                    default => 'bg-gray-500/10 text-gray-400'
                                                };
                                            ?>"><?= $log['status'] ?></span>
                                        </div>
                                        <div class="text-gray-400 whitespace-pre-wrap break-all overflow-x-hidden"><?= htmlspecialchars($log['message']) ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?= date('M d, H:i:s', strtotime($log['created_at'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="p-4">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div>
                            <div class="text-gray-400 mb-1">Status</div>
                            <?php
                            $statusColors = [
                                'running' => 'bg-green-500/10 text-green-500',
                                'processing' => 'bg-blue-500/10 text-blue-500 animate-pulse',
                                'queued' => 'bg-yellow-500/10 text-yellow-500',
                                'completed' => 'bg-green-500/10 text-green-500',
                                'error' => 'bg-red-500/10 text-red-500',
                                'stopped' => 'bg-orange-500/10 text-orange-500',
                            ];
                            $statusClass = $statusColors[$automation['status']] ?? 'bg-gray-500/10 text-gray-400';
                            ?>
                            <span id="status-badge-<?= $automation['id'] ?>" data-status="<?= htmlspecialchars((string)$automation['status']) ?>" class="px-2 py-1 rounded text-xs font-medium <?= $statusClass ?>">
                                <?= $automation['status'] === 'queued' ? '⏳ queued' : $automation['status'] ?>
                            </span>
                        </div>
                        <div>
                            <div class="text-gray-400 mb-1">Short Duration</div>
                            <div class="font-mono"><?= $automation['short_duration'] ?>s</div>
                        </div>
                        <?php if ($automation['last_run_at']): ?>
                            <div>
                                <div class="text-gray-400 mb-1">Last Run</div>
                                <div class="text-xs"><?= date('M d, H:i', strtotime($automation['last_run_at'])) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($automation['next_run_at'] && $automation['enabled']): ?>
                            <div>
                                <div class="text-gray-400 mb-1">Next Run</div>
                                <div class="text-xs"><?= date('M d, H:i', strtotime($automation['next_run_at'])) ?></div>
                                <div class="countdown-timer text-green-400 font-mono text-sm mt-1" 
                                     data-target="<?= strtotime($automation['next_run_at']) ?>"
                                     data-automation-id="<?= $automation['id'] ?>">
                                    --:--:--
                                </div>
                            </div>
                        <?php elseif ($automation['enabled'] && !$automation['next_run_at']): ?>
                            <div>
                                <div class="text-gray-400 mb-1">Next Run</div>
                                <div class="text-yellow-400 text-xs">Calculating...</div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-wrap gap-2 mt-4">
                        <?php if (($automation['run_mode'] ?? 'local') === 'github_runner'): ?>
                            <span class="px-2 py-1 bg-sky-500/10 rounded text-xs text-sky-300 border border-sky-500/20">
                                GitHub Runner
                            </span>
                        <?php else: ?>
                            <span class="px-2 py-1 bg-emerald-500/10 rounded text-xs text-emerald-300 border border-emerald-500/20">
                                Local Runner
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($automation['postforme_enabled'])): ?>
                            <?php 
                            $postformeAccounts = json_decode($automation['postforme_account_ids'] ?? '[]', true) ?: [];
                            $accountCount = count($postformeAccounts);
                            ?>
                            <span class="px-2 py-1 bg-gradient-to-r from-pink-500/10 to-purple-500/10 rounded text-xs text-pink-400 flex items-center gap-1 border border-pink-500/20">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                                Post for Me (<?= $accountCount ?> account<?= $accountCount !== 1 ? 's' : '' ?>)
                            </span>
                        <?php else: ?>
                            <?php if ($automation['youtube_enabled']): ?>
                                <span class="px-2 py-1 bg-red-500/10 rounded text-xs text-red-500 flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/></svg>
                                    YouTube
                                </span>
                            <?php endif; ?>
                            <?php if ($automation['instagram_enabled']): ?>
                                <span class="px-2 py-1 bg-pink-500/10 rounded text-xs text-pink-500 flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                                    Instagram
                                </span>
                            <?php endif; ?>
                            <?php if ($automation['facebook_enabled']): ?>
                                <span class="px-2 py-1 bg-blue-500/10 rounded text-xs text-blue-500 flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M9 8h-3v4h3v12h5v-12h3.642l.358-4h-4v-1.667c0-.955.192-1.333 1.115-1.333h2.885v-5h-3.808c-3.596 0-5.192 1.583-5.192 4.615v3.385z"/></svg>
                                    Facebook
                                </span>
                            <?php endif; ?>
                            <?php if ($automation['tiktok_enabled']): ?>
                                <span class="px-2 py-1 bg-gray-500/10 rounded text-xs text-gray-400 flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-5.2 1.74 2.89 2.89 0 012.31-4.64 2.93 2.93 0 01.88.13V9.4a6.84 6.84 0 00-1-.05A6.33 6.33 0 005 20.1a6.34 6.34 0 0010.86-4.43v-7a8.16 8.16 0 004.77 1.52v-3.4a4.85 4.85 0 01-1-.1z"/></svg>
                                    TikTok
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($automation['branding_text_top']): ?>
                            <span class="px-2 py-1 bg-purple-500/10 rounded text-xs text-purple-500 flex items-center gap-1">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg>
                                Branding
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($automation['rotation_enabled'])): ?>
                            <span class="px-2 py-1 bg-indigo-500/10 rounded text-xs text-indigo-400 flex items-center gap-1 border border-indigo-500/20">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                Rotation Cycle <?= intval($automation['rotation_cycle'] ?? 1) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($automation['branding_text_top']): ?>
                        <div class="mt-3 p-2 bg-gray-800/50 rounded text-sm">
                            <span class="text-gray-400">Top Text:</span>
                            <span class="font-medium"><?= htmlspecialchars($automation['branding_text_top']) ?></span>
                            <?php if (!empty($randomWords)): ?>
                                <span class="text-gray-400 ml-1">+ <?= count($randomWords) ?> random words</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php
                        $lastStats = ['fetched' => 0, 'downloaded' => 0, 'processed' => 0, 'scheduled' => 0, 'posted' => 0];
                        $lastOutputs = [];
                        if (!empty($automation['progress_data'])) {
                            $pd = json_decode($automation['progress_data'], true);
                            if (!empty($pd['stats'])) {
                                $lastStats = array_merge($lastStats, $pd['stats']);
                            }
                            if (!empty($pd['outputs']) && is_array($pd['outputs'])) {
                                foreach ($pd['outputs'] as $outFile) {
                                    $outFile = trim((string)$outFile);
                                    if ($outFile !== '') {
                                        $lastOutputs[] = $outFile;
                                    }
                                }
                            }
                        }
                    ?>
                    
                    <!-- Stats Row - Always Visible -->
                    <div class="mt-4 grid grid-cols-5 gap-2 text-center text-xs">
                        <div class="p-2 bg-gray-800 rounded">
                            <div class="text-lg font-bold text-blue-400" id="stat-fetched-<?= $automation['id'] ?>"><?= intval($lastStats['fetched']) ?></div>
                            <div class="text-gray-500">Fetched</div>
                        </div>
                        <div class="p-2 bg-gray-800 rounded">
                            <div class="text-lg font-bold text-yellow-400" id="stat-downloaded-<?= $automation['id'] ?>"><?= intval($lastStats['downloaded']) ?></div>
                            <div class="text-gray-500">Downloaded</div>
                        </div>
                        <div class="p-2 bg-gray-800 rounded">
                            <div class="text-lg font-bold text-green-400" id="stat-processed-<?= $automation['id'] ?>"><?= intval($lastStats['processed']) ?></div>
                            <div class="text-gray-500">Processed</div>
                        </div>
                        <div class="p-2 bg-gradient-to-r from-indigo-900/30 to-blue-900/30 rounded border border-indigo-500/20 cursor-pointer hover:bg-indigo-900/50 transition-colors" onclick="openScheduledModal(<?= $automation['id'] ?>, '<?= htmlspecialchars($automation['name'], ENT_QUOTES) ?>')">
                            <div class="text-lg font-bold text-indigo-400" id="stat-scheduled-<?= $automation['id'] ?>"><?= intval($lastStats['scheduled']) ?></div>
                            <div class="text-gray-500 text-xs flex items-center justify-center gap-1">
                                Scheduled <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </div>
                        </div>
                        <div class="p-2 bg-gradient-to-r from-pink-900/30 to-purple-900/30 rounded border border-pink-500/20">
                            <div class="text-lg font-bold text-pink-400" id="stat-posted-<?= $automation['id'] ?>"><?= intval($lastStats['posted']) ?></div>
                            <div class="text-gray-500">Posted</div>
                        </div>
                    </div>

                    <!-- Progress Section (shown during run) -->
                    <div id="progress-<?= $automation['id'] ?>" class="mt-3 p-3 bg-gray-800/30 rounded-lg hidden">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-green-400">Processing...</span>
                            <span class="text-xs text-gray-400" id="progress-percent-<?= $automation['id'] ?>">0%</span>
                        </div>
                        <div class="h-2 bg-gray-700 rounded-full overflow-hidden">
                            <div id="progress-bar-<?= $automation['id'] ?>" class="h-full bg-gradient-to-r from-green-500 to-emerald-400 transition-all duration-300" style="width: 0%"></div>
                        </div>
                        <div id="progress-log-<?= $automation['id'] ?>" class="mt-3 max-h-32 overflow-y-auto text-xs font-mono space-y-1"></div>
                        <div class="mt-3 border-t border-gray-700/60 pt-2">
                            <div class="text-xs text-gray-400 mb-1">Edited Outputs</div>
                            <div id="progress-outputs-<?= $automation['id'] ?>" class="space-y-1 text-xs">
                                <?php if (!empty($lastOutputs)): ?>
                                    <?php foreach (array_slice(array_reverse($lastOutputs), 0, 5) as $outName): ?>
                                        <div class="text-cyan-300 truncate"><?= htmlspecialchars($outName) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-gray-500">No edited output yet</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Create Modal with Tabs -->
<div id="createModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="card rounded-lg w-full max-w-3xl max-h-[90vh] overflow-hidden m-4">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="text-lg font-semibold">Create New Automation</h3>
            <button onclick="document.getElementById('createModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
        </div>
        
        <!-- Tab Navigation -->
        <div class="flex border-b border-gray-800 bg-gray-900/50">
            <button type="button" onclick="showFormTab('basic')" id="tab_basic" class="flex-1 px-4 py-3 text-sm font-medium border-b-2 border-indigo-500 text-white">
                1. Basic
            </button>
            <button type="button" onclick="showFormTab('video')" id="tab_video" class="flex-1 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-gray-400 hover:text-white">
                2. Video
            </button>
            <button type="button" onclick="showFormTab('taglines')" id="tab_taglines" class="flex-1 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-gray-400 hover:text-white">
                3. Taglines
            </button>
            <button type="button" onclick="showFormTab('social')" id="tab_social" class="flex-1 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-gray-400 hover:text-white">
                4. Publish
            </button>
        </div>
        
        <form method="POST" class="overflow-y-auto" style="max-height: calc(90vh - 140px);">
            <input type="hidden" name="action" value="create">
            
            <!-- Tab 1: Basic Settings -->
            <div id="form_basic" class="p-4 space-y-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Automation Name *</label>
                    <input type="text" name="name" required placeholder="e.g., Birthday Shorts" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Video Source *</label>
                    <select name="video_source" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" onchange="toggleVideoSource(this)">
                        <option value="ftp">FTP Server</option>
                        <option value="bunny">Bunny CDN</option>
                        <option value="manual_links">Manual Links (Direct URL)</option>
                        <option value="youtube_channel">YouTube Channel</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm text-gray-400 mb-1">Runner Mode *</label>
                    <select name="run_mode" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <option value="local" selected>Local Runner</option>
                        <option value="github_runner">GitHub Runner</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Local runs on this server. GitHub Runner dispatches workflow in your GitHub repo.</p>
                </div>
                
                <div id="bunny_source_section" class="hidden">
                    <label class="block text-sm text-gray-400 mb-1">Bunny CDN Connection</label>
                    <select name="api_key_id" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <option value="">Select...</option>
                        <?php foreach ($keys as $key): ?>
                            <option value="<?= $key['id'] ?>"><?= htmlspecialchars($key['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="ftp_source_info" class="p-3 bg-blue-500/10 border border-blue-500/20 rounded-lg text-sm text-gray-400">
                    Videos fetch from FTP in <a href="settings.php?tab=ftp" class="text-blue-400">Settings</a>
                </div>

                <div id="manual_source_section" class="hidden space-y-2">
                    <label class="block text-sm text-gray-400 mb-1">Manual Video Links</label>
                    <textarea
                        name="manual_video_links"
                        id="manual_video_links"
                        rows="5"
                        class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg"
                        placeholder="Paste one direct video URL per line"></textarea>
                    <p class="text-xs text-gray-500">Supports direct links (Cloudinary, Fiverr CDN, archive/media URLs). One URL per line.</p>
                </div>

                <div id="youtube_source_section" class="hidden space-y-2">
                    <label class="block text-sm text-gray-400 mb-1">YouTube Channel URL</label>
                    <input
                        type="url"
                        name="youtube_channel_url"
                        id="youtube_channel_url"
                        class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg"
                        placeholder="https://www.youtube.com/@HUMNewsPakistan/videos">
                    <p class="text-xs text-gray-500">Channel videos tab link dein. System last X days ya selected date range ki uploaded videos fetch karega aur active live streams skip karega.</p>
                    <div class="p-3 bg-sky-500/10 border border-sky-500/20 rounded text-xs text-sky-100">
                        Quick test flow: channel `/videos` URL dein, `Date range` choose karein, `From` aur `To` mein same date set karein, aur `Videos per run` ko `1` rakhein.
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Schedule</label>
                        <select name="schedule_type" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            <option value="minutes">Every X Minutes</option>
                            <option value="hourly">Hourly</option>
                            <option value="daily" selected>Daily</option>
                            <option value="weekly">Weekly</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Hour</label>
                        <input type="number" name="schedule_hour" value="9" min="0" max="23" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Every (minutes)</label>
                    <input type="number" name="schedule_every_minutes" value="10" min="1" max="1440" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                
                <div class="flex items-center gap-3 p-3 bg-green-500/10 border border-green-500/20 rounded-lg">
                    <input type="checkbox" name="enabled" id="enabled" checked class="w-4 h-4">
                    <label for="enabled" class="text-sm">Start automation immediately</label>
                </div>
                
                <button type="button" onclick="showFormTab('video')" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg">
                    Next: Video Settings →
                </button>
            </div>
            
            <!-- Tab 2: Video Settings -->
            <div id="form_video" class="hidden p-4 space-y-4">
                <!-- Video Selection Method -->
                <div class="space-y-3">
                    <label class="block text-sm text-gray-400 mb-2">Select videos by:</label>
                    
                    <div class="flex gap-4 mb-3">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="video_selection_method" value="days" class="w-4 h-4 accent-indigo-500" checked onchange="toggleVideoSelectionMethod()">
                            <span>Last X days</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="video_selection_method" value="date_range" class="w-4 h-4 accent-indigo-500" onchange="toggleVideoSelectionMethod()">
                            <span>Date range</span>
                        </label>
                    </div>
                    
                    <!-- Hidden field to store the selection method -->
                    <input type="hidden" name="video_selection_method_hidden" id="video_selection_method_hidden" value="days">
                    
                    <!-- Days Filter Section -->
                    <div id="days_filter_section">
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Fetch videos from last (days)</label>
                            <input type="number" name="video_days_filter" id="video_days_filter" value="30" min="1" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            <p class="text-xs text-gray-500 mt-1">Random ya fresh channel test ke liye `7` ya `30` days common choice hai.</p>
                        </div>
                    </div>
                    
                    <!-- Date Range Section -->
                    <div id="date_range_section" class="hidden">
                        <div class="flex flex-wrap gap-2 mb-3">
                            <button type="button" onclick="applyCreateYouTubePreset('today')" class="px-3 py-1 text-xs bg-sky-500/15 border border-sky-500/30 rounded hover:bg-sky-500/25">Today + 1 video</button>
                            <button type="button" onclick="applyCreateYouTubePreset('yesterday')" class="px-3 py-1 text-xs bg-gray-700 border border-gray-600 rounded hover:bg-gray-600">Yesterday + 1 video</button>
                            <button type="button" onclick="applyCreateYouTubePreset('last7')" class="px-3 py-1 text-xs bg-gray-700 border border-gray-600 rounded hover:bg-gray-600">Last 7 days</button>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">From Date</label>
                                <input type="date" name="video_start_date" id="video_start_date" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">To Date</label>
                                <input type="date" name="video_end_date" id="video_end_date" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Single exact date ke liye `From` aur `To` dono mein same date rakhein.</p>
                    </div>
                </div>
                
                <!-- Video Rotation System -->
                <div class="p-4 bg-gradient-to-r from-indigo-900/20 to-purple-900/20 border border-indigo-500/30 rounded-lg">
                    <div class="flex items-center gap-3 mb-3">
                        <input type="checkbox" name="rotation_enabled" id="rotation_enabled" checked class="w-5 h-5 accent-indigo-500">
                        <label for="rotation_enabled" class="font-medium text-white flex items-center gap-2 cursor-pointer">
                            <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Smart Video Rotation
                        </label>
                    </div>
                    <p class="text-xs text-gray-400 mb-3">Ensures each video is unique - no repeats until all videos are used</p>
                    
                <div id="rotation_options" class="space-y-2">
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                            <input type="checkbox" name="rotation_shuffle" checked class="w-4 h-4 accent-indigo-500">
                            Random order (shuffle videos)
                        </label>
                    </div>
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                            <input type="checkbox" name="rotation_auto_reset" checked class="w-4 h-4 accent-indigo-500">
                            Auto-reset when all videos used (start new cycle)
                        </label>
                    </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Videos per run</label>
                                <input type="number" name="videos_per_run" id="videos_per_run" value="5" min="1" max="500" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                                <p class="text-xs text-gray-500 mt-1">Process/post this many videos each scheduled run</p>
                            </div>
                        <div class="p-2 bg-indigo-500/10 border border-indigo-500/20 rounded text-xs text-indigo-300 flex items-center">
                            Oldest videos are processed first (sequence batches)
                        </div>
                    </div>
                    <div class="p-2 bg-indigo-500/10 border border-indigo-500/20 rounded text-xs text-indigo-300">
                        Videos with same file size are detected as duplicates even if renamed
                    </div>
                </div>
                </div>
                
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Short Duration (sec)</label>
                        <input type="number" name="short_duration" value="60" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <p class="text-xs text-gray-500 mt-1">Each short clip length. Example: `60` means 60-second shorts.</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Playback Speed</label>
                        <input type="number" name="playback_speed" id="playback_speed" value="1.0" min="0.1" max="3.0" step="0.1" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <p class="text-xs text-gray-500 mt-1">Applies to video and audio together. `1.0` normal, `0.8` slow, `1.3` fast.</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Aspect Ratio</label>
                        <select name="short_aspect_ratio" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            <optgroup label="Crop (Fill Frame)">
                                <option value="9:16">9:16 Vertical - Crop</option>
                                <option value="1:1">1:1 Square - Crop</option>
                                <option value="16:9">16:9 Horizontal - Crop</option>
                            </optgroup>
                            <optgroup label="No Crop (Fit with Black Bars)">
                                <option value="9:16-fit">9:16 Vertical - No Crop (Fit)</option>
                                <option value="1:1-fit">1:1 Square - No Crop (Fit)</option>
                                <option value="16:9-fit">16:9 Horizontal - No Crop (Fit)</option>
                            </optgroup>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Crop fills frame, No Crop keeps full video with black bars</p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Shorts Per Source Video</label>
                        <select name="source_shorts_mode" id="source_shorts_mode" onchange="toggleSourceShortsMode(this.value)" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            <option value="single">Single short</option>
                            <option value="duration_based">Auto by duration</option>
                            <option value="fixed_count">Fixed count</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Auto mode makes sequential clips from one source video using the short duration above.</p>
                    </div>
                    <div id="source_shorts_max_count_wrap" class="hidden">
                        <label class="block text-sm text-gray-400 mb-1">Max Shorts Per Source</label>
                        <input type="number" name="source_shorts_max_count" id="source_shorts_max_count" value="5" min="1" max="20" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <p class="text-xs text-gray-500 mt-1">Example: 5-minute video + 60s duration + max 5 = up to 5 shorts.</p>
                    </div>
                </div>
                <div class="p-2 bg-amber-500/10 border border-amber-500/20 rounded text-xs text-amber-200">
                    Research-based default: keep this on <span class="font-semibold">Single short</span> unless the source video has multiple strong moments. Official YouTube guidance favors concise clips with a strong opening hook; exact clip count is an inference, so this setting stays user-controlled.
                </div>
                
                <div class="border-t border-gray-800 pt-4 mt-4">
                    <div class="flex items-center gap-3 p-3 bg-green-500/10 border border-green-500/20 rounded-lg mb-3">
                        <input type="checkbox" name="whisper_enabled" id="whisper_enabled" class="w-4 h-4">
                        <label for="whisper_enabled" class="text-sm">Enable Auto-Captions (Whisper AI)</label>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Caption Language</label>
                        <select name="whisper_language" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            <option value="en">English</option>
                            <option value="ur">Urdu</option>
                            <option value="hi">Hindi</option>
                            <option value="ar">Arabic</option>
                        </select>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <button type="button" onclick="showFormTab('basic')" class="flex-1 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg">← Back</button>
                    <button type="button" onclick="showFormTab('taglines')" class="flex-1 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg">Next: Taglines →</button>
                </div>
            </div>
            
            <!-- Tab 3: Taglines - SUPER SIMPLE -->
            <div id="form_taglines" class="hidden p-4 space-y-4">
                
                <!-- SIMPLE: Just Enable Toggle -->
                <div class="p-6 bg-gradient-to-r from-green-900/30 to-emerald-900/30 border border-green-500/30 rounded-xl">
                    <div class="flex items-center gap-4">
                        <input type="checkbox" name="ai_taglines_enabled" id="ai_taglines_enabled" class="w-8 h-8 rounded-lg accent-green-500" checked>
                        <label for="ai_taglines_enabled" class="flex-1 cursor-pointer">
                            <div class="font-bold text-xl text-white flex items-center gap-2">
                                <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Enable Taglines
                            </div>
                            <div class="text-gray-400 mt-1">Add text overlay to all videos automatically</div>
                        </label>
                    </div>
                </div>
                
                <!-- Preview Box -->
                <div class="p-4 bg-gray-800/50 rounded-lg border border-gray-700">
                    <h4 class="text-sm text-gray-400 mb-3 font-medium">Preview - Each video will have:</h4>
                    <div class="bg-black rounded-lg p-4 text-center space-y-2">
                        <div class="text-yellow-400 font-bold text-lg">"Made With Love"</div>
                        <div class="text-white text-sm">"Get Greeting Video"</div>
                        <div class="text-gray-500 text-xs my-4">[ Your Video ]</div>
                        <div class="text-cyan-400 font-medium">"wishesmadeeasy.com"</div>
                    </div>
                    <p class="text-xs text-gray-500 mt-3 text-center">30+ unique taglines • Each video gets different text • Branding always shown</p>
                </div>
                
                <!-- Hidden fields (keep defaults) -->
                <input type="hidden" name="ai_tagline_prompt" value="Generate universal greeting taglines">
                <input type="hidden" name="random_words" value="">
                <input type="hidden" name="branding_text_top" id="branding_text_top" value="">
                <input type="hidden" name="branding_text_bottom" id="branding_text_bottom" value="">
                <input type="hidden" name="taglines_json" id="taglines_json" value="[]">
                
                <div class="flex gap-3">
                    <button type="button" onclick="showFormTab('video')" class="flex-1 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg">← Back</button>
                    <button type="button" onclick="showFormTab('social')" class="flex-1 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg">Next: Publish →</button>
                </div>
            </div>
            
            <!-- Tab 4: Social Media -->
            <div id="form_social" class="hidden p-4 space-y-4">
                <p class="text-sm text-gray-400 mb-4">Select where to auto-publish shorts:</p>
                
                <!-- Post for Me Integration (Recommended) -->
                <div class="p-4 bg-gradient-to-r from-pink-900/30 to-purple-900/30 border border-pink-500/30 rounded-xl">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="postforme_enabled" id="postforme_enabled" class="w-6 h-6 accent-pink-500" onchange="togglePostForMe(this)">
                        <div class="flex-1">
                            <div class="font-bold text-white flex items-center gap-2">
                                <svg class="w-5 h-5 text-pink-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                                Post for Me (Recommended)
                            </div>
                            <div class="text-gray-400 text-sm">One API for all platforms - No developer apps needed!</div>
                        </div>
                        <?php 
                        $postformeKey = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key'")->fetchColumn();
                        if ($postformeKey): ?>
                            <span class="px-2 py-1 bg-green-500/20 text-green-400 rounded text-xs">Connected</span>
                        <?php else: ?>
                            <a href="settings.php?tab=postforme" class="px-2 py-1 bg-yellow-500/20 text-yellow-400 rounded text-xs hover:bg-yellow-500/30">Setup →</a>
                        <?php endif; ?>
                    </label>
                    
                    <!-- Account Selection (shown when Post for Me enabled) -->
                    <div id="postforme_accounts_section" class="hidden mt-4 space-y-3">
                        <?php
                        $postformeAccounts = [];
                        try {
                            $postformeAccounts = $pdo->query("SELECT * FROM postforme_accounts WHERE is_active = 1 ORDER BY platform")->fetchAll();
                        } catch (Exception $e) {}
                        
                        if (empty($postformeAccounts)): ?>
                            <div class="p-3 bg-gray-800/50 rounded-lg text-center">
                                <p class="text-gray-400 text-sm">No accounts connected yet</p>
                                <a href="settings.php?tab=postforme" class="text-pink-400 text-xs hover:underline">Connect accounts in Settings →</a>
                            </div>
                        <?php else: ?>
                            <p class="text-xs text-gray-400">Select accounts to post to:</p>
                            <div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto">
                                <?php foreach ($postformeAccounts as $acc): 
                                    $platformColors = ['youtube' => 'red', 'tiktok' => 'gray', 'instagram' => 'pink', 'facebook' => 'blue', 'threads' => 'gray', 'twitter' => 'blue', 'linkedin' => 'blue'];
                                    $color = $platformColors[$acc['platform']] ?? 'gray';
                                ?>
                                    <label class="flex items-center gap-2 p-2 bg-gray-800/50 rounded-lg cursor-pointer hover:bg-gray-800 border border-transparent hover:border-<?= $color ?>-500/30">
                                        <input type="checkbox" name="postforme_account_ids[]" value="<?= htmlspecialchars($acc['account_id']) ?>" class="w-4 h-4 accent-<?= $color ?>-500">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm truncate"><?= htmlspecialchars($acc['account_name'] ?? $acc['username']) ?></div>
                                            <div class="text-xs text-<?= $color ?>-400"><?= ucfirst($acc['platform']) ?></div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Post Scheduling Section -->
                        <div class="mt-4 p-4 bg-gray-800/50 rounded-lg border border-gray-700">
                            <h4 class="text-sm font-medium text-white mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Post Scheduling
                            </h4>
                            
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs text-gray-400 mb-1">When to publish posts?</label>
                                    <select name="postforme_schedule_mode" id="postforme_schedule_mode" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm" onchange="toggleScheduleMode(this.value)">
                                        <option value="immediate">Post Immediately</option>
                                        <option value="scheduled">Schedule for Specific Date/Time</option>
                                        <option value="offset">Delay After Processing (Offset)</option>
                                    </select>
                                </div>
                                
                                <!-- Scheduled: Specific Date/Time -->
                                <div id="schedule_datetime_section" class="hidden space-y-2">
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Schedule Date & Time</label>
                                        <input type="datetime-local" name="postforme_schedule_datetime" id="postforme_schedule_datetime" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Your Timezone</label>
                                        <select name="postforme_schedule_timezone" id="postforme_schedule_timezone" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm">
                                            <option value="UTC">UTC</option>
                                            <option value="America/New_York">Eastern Time (US)</option>
                                            <option value="America/Chicago">Central Time (US)</option>
                                            <option value="America/Denver">Mountain Time (US)</option>
                                            <option value="America/Los_Angeles">Pacific Time (US)</option>
                                            <option value="Europe/London">London (GMT/BST)</option>
                                            <option value="Europe/Paris">Paris (CET/CEST)</option>
                                            <option value="Europe/Berlin">Berlin (CET/CEST)</option>
                                            <option value="Asia/Dubai">Dubai (GST)</option>
                                            <option value="Asia/Karachi">Pakistan (PKT)</option>
                                            <option value="Asia/Kolkata">India (IST)</option>
                                            <option value="Asia/Shanghai">China (CST)</option>
                                            <option value="Asia/Tokyo">Japan (JST)</option>
                                            <option value="Australia/Sydney">Sydney (AEST)</option>
                                        </select>
                                    </div>
                                    <div class="p-2 bg-purple-500/10 border border-purple-500/20 rounded-lg">
                                        <p class="text-xs text-purple-300">Post will be scheduled on PostForMe.dev and published at the exact date/time you select.</p>
                                    </div>
                                </div>
                                
                                <!-- Offset: Delay after processing -->
                                <div id="schedule_offset_section" class="hidden space-y-2">
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Delay after video processing (minutes)</label>
                                        <input type="number" name="postforme_schedule_offset_minutes" id="postforme_schedule_offset_minutes" value="0" min="0" max="43200" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm" placeholder="e.g., 60 = 1 hour delay">
                                    </div>
                                    <div class="grid grid-cols-4 gap-1">
                                        <button type="button" onclick="setOffset(30)" class="px-2 py-1 bg-gray-700 hover:bg-gray-600 rounded text-xs">30m</button>
                                        <button type="button" onclick="setOffset(60)" class="px-2 py-1 bg-gray-700 hover:bg-gray-600 rounded text-xs">1h</button>
                                        <button type="button" onclick="setOffset(360)" class="px-2 py-1 bg-gray-700 hover:bg-gray-600 rounded text-xs">6h</button>
                                        <button type="button" onclick="setOffset(1440)" class="px-2 py-1 bg-gray-700 hover:bg-gray-600 rounded text-xs">24h</button>
                                    </div>
                                    <div class="p-2 bg-blue-500/10 border border-blue-500/20 rounded-lg">
                                        <p class="text-xs text-blue-300">Post will be scheduled X minutes after the video is processed. Useful to stagger posts throughout the day.</p>
                                    </div>
                                </div>
                                
                                <!-- Spread: Time between multiple posts -->
                                <div id="schedule_spread_section" class="hidden">
                                    <label class="block text-xs text-gray-400 mb-1">Spread between posts (minutes)</label>
                                    <input type="number" name="postforme_schedule_spread_minutes" id="postforme_schedule_spread_minutes" value="0" min="0" max="1440" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm" placeholder="e.g., 15 = 15 min between each post">
                                    <p class="text-xs text-gray-500 mt-1">If processing multiple videos, space posts apart by this many minutes</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Divider -->
                <div class="flex items-center gap-3 py-2">
                    <div class="flex-1 border-t border-gray-700"></div>
                    <span class="text-xs text-gray-500">OR use individual APIs (requires developer apps)</span>
                    <div class="flex-1 border-t border-gray-700"></div>
                </div>
                
                <!-- Legacy Individual Platform Options -->
                <div id="legacy_platforms_section" class="space-y-3">
                    <label class="flex items-center gap-3 p-3 bg-red-500/10 border border-red-500/20 rounded-lg cursor-pointer opacity-60 hover:opacity-100">
                        <input type="checkbox" name="youtube_enabled" class="w-4 h-4">
                        <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/></svg>
                        <span>YouTube Shorts</span>
                        <span class="ml-auto text-xs text-gray-500">Requires Google API</span>
                    </label>
                    
                    <label class="flex items-center gap-3 p-3 bg-gray-500/10 border border-gray-500/20 rounded-lg cursor-pointer opacity-60 hover:opacity-100">
                        <input type="checkbox" name="tiktok_enabled" class="w-4 h-4">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-5.2 1.74 2.89 2.89 0 012.31-4.64 2.93 2.93 0 01.88.13V9.4a6.84 6.84 0 00-1-.05A6.33 6.33 0 005 20.1a6.34 6.34 0 0010.86-4.43v-7a8.16 8.16 0 004.77 1.52v-3.4a4.85 4.85 0 01-1-.1z"/></svg>
                        <span>TikTok</span>
                        <span class="ml-auto text-xs text-gray-500">Requires TikTok App</span>
                    </label>
                    
                    <label class="flex items-center gap-3 p-3 bg-pink-500/10 border border-pink-500/20 rounded-lg cursor-pointer opacity-60 hover:opacity-100">
                        <input type="checkbox" name="instagram_enabled" class="w-4 h-4">
                        <svg class="w-5 h-5 text-pink-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069z"/></svg>
                        <span>Instagram Reels</span>
                        <span class="ml-auto text-xs text-gray-500">Requires Meta App</span>
                    </label>
                    
                    <label class="flex items-center gap-3 p-3 bg-blue-500/10 border border-blue-500/20 rounded-lg cursor-pointer opacity-60 hover:opacity-100">
                        <input type="checkbox" name="facebook_enabled" class="w-4 h-4">
                        <svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 8h-3v4h3v12h5v-12h3.642l.358-4h-4v-1.667c0-.955.192-1.333 1.115-1.333h2.885v-5h-3.808c-3.596 0-5.192 1.583-5.192 4.615v3.385z"/></svg>
                        <span>Facebook Reels</span>
                        <span class="ml-auto text-xs text-gray-500">Requires Meta App</span>
                    </label>
                </div>
                
                <p class="text-xs text-gray-500">Configure API keys in <a href="settings.php?tab=postforme" class="text-pink-400">Settings → Post for Me</a> or <a href="settings.php?tab=stream" class="text-indigo-400">Settings → Stream APIs</a></p>
                
                <div class="flex gap-3 pt-4 border-t border-gray-800">
                    <button type="button" onclick="showFormTab('taglines')" class="flex-1 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg">← Back</button>
                    <button type="submit" class="flex-1 py-3 bg-green-600 hover:bg-green-700 rounded-lg font-medium">
                        ✓ Create Automation
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal with Tabs -->
<div id="editModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="card rounded-lg w-full max-w-3xl max-h-[90vh] overflow-hidden m-4">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="text-lg font-semibold">Edit Automation</h3>
            <button onclick="document.getElementById('editModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-2xl">&times;</button>
        </div>
        
        <!-- Tab Navigation -->
        <div class="flex border-b border-gray-800 bg-gray-900/50">
            <button type="button" onclick="showEditFormTab('basic')" id="edit_tab_basic" class="flex-1 px-4 py-3 text-sm font-medium border-b-2 border-indigo-500 text-white">
                1. Basic
            </button>
            <button type="button" onclick="showEditFormTab('video')" id="edit_tab_video" class="flex-1 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-gray-400 hover:text-white">
                2. Video
            </button>
            <button type="button" onclick="showEditFormTab('taglines')" id="edit_tab_taglines" class="flex-1 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-gray-400 hover:text-white">
                3. Taglines
            </button>
            <button type="button" onclick="showEditFormTab('social')" id="edit_tab_social" class="flex-1 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-gray-400 hover:text-white">
                4. Publish
            </button>
        </div>
        
        <form method="POST" id="editForm" class="overflow-y-auto" style="max-height: calc(90vh - 140px);">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_automation_id" value="">
            
            <!-- Tab 1: Basic Settings -->
            <div id="edit_form_basic" class="p-4 space-y-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Automation Name *</label>
                    <input type="text" name="name" id="edit_name" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Video Source *</label>
                    <select name="video_source" id="edit_video_source" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" onchange="toggleEditVideoSource(this)">
                        <option value="ftp">FTP Server</option>
                        <option value="bunny">Bunny CDN</option>
                        <option value="manual_links">Manual Links (Direct URL)</option>
                        <option value="youtube_channel">YouTube Channel</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm text-gray-400 mb-1">Runner Mode *</label>
                    <select name="run_mode" id="edit_run_mode" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <option value="local">Local Runner</option>
                        <option value="github_runner">GitHub Runner</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Use GitHub Runner to dispatch a workflow instead of local execution.</p>
                </div>
                
                <div id="edit_bunny_source_section" class="hidden">
                    <label class="block text-sm text-gray-400 mb-1">Bunny CDN Connection</label>
                    <select name="api_key_id" id="edit_api_key_id" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <option value="">Select...</option>
                        <?php foreach ($keys as $key): ?>
                            <option value="<?= $key['id'] ?>"><?= htmlspecialchars($key['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="edit_ftp_source_info" class="p-3 bg-blue-500/10 border border-blue-500/20 rounded-lg text-sm text-gray-400">
                    Videos fetch from FTP in <a href="settings.php?tab=ftp" class="text-blue-400">Settings</a>
                </div>

                <div id="edit_manual_source_section" class="hidden space-y-2">
                    <label class="block text-sm text-gray-400 mb-1">Manual Video Links</label>
                    <textarea
                        name="manual_video_links"
                        id="edit_manual_video_links"
                        rows="5"
                        class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg"
                        placeholder="Paste one direct video URL per line"></textarea>
                    <p class="text-xs text-gray-500">One direct URL per line. These links will be downloaded then processed.</p>
                </div>

                <div id="edit_youtube_source_section" class="hidden space-y-2">
                    <label class="block text-sm text-gray-400 mb-1">YouTube Channel URL</label>
                    <input
                        type="url"
                        name="youtube_channel_url"
                        id="edit_youtube_channel_url"
                        class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg"
                        placeholder="https://www.youtube.com/@HUMNewsPakistan/videos">
                    <p class="text-xs text-gray-500">Channel videos tab link. Active live streams automatically skip hongi.</p>
                    <div class="p-3 bg-sky-500/10 border border-sky-500/20 rounded text-xs text-sky-100">
                        Quick test flow: `Date range` choose karein, same `From/To` date set karein, aur `Videos per run` ko `1` rakhein.
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Schedule</label>
                        <select name="schedule_type" id="edit_schedule_type" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            <option value="minutes">Every X Minutes</option>
                            <option value="hourly">Hourly</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Hour</label>
                        <input type="number" name="schedule_hour" id="edit_schedule_hour" value="9" min="0" max="23" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Every (minutes)</label>
                    <input type="number" name="schedule_every_minutes" id="edit_schedule_every_minutes" value="10" min="1" max="1440" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                
                <div class="flex items-center gap-3 p-3 bg-green-500/10 border border-green-500/20 rounded-lg">
                    <input type="checkbox" name="enabled" id="edit_enabled" class="w-4 h-4">
                    <label for="edit_enabled" class="text-sm">Start automation immediately</label>
                </div>
                
                <button type="button" onclick="showEditFormTab('video')" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg">
                    Next: Video Settings →
                </button>
            </div>
            
            <!-- Tab 2: Video Settings -->
            <div id="edit_form_video" class="hidden p-4 space-y-4">
                <!-- Video Selection Method -->
                <div class="space-y-3">
                    <label class="block text-sm text-gray-400 mb-2">Select videos by:</label>
                    
                    <div class="flex gap-4 mb-3">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="video_selection_method" value="days" id="edit_video_selection_days" class="w-4 h-4 accent-indigo-500" onchange="toggleEditVideoSelectionMethod()">
                            <span>Last X days</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="video_selection_method" value="date_range" id="edit_video_selection_date_range" class="w-4 h-4 accent-indigo-500" onchange="toggleEditVideoSelectionMethod()">
                            <span>Date range</span>
                        </label>
                    </div>
                    
                    <!-- Hidden field to store the selection method -->
                    <input type="hidden" name="video_selection_method_hidden" id="edit_video_selection_method_hidden" value="days">
                    
                    <!-- Days Filter Section -->
                    <div id="edit_days_filter_section">
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Fetch videos from last (days)</label>
                            <input type="number" name="video_days_filter" id="edit_video_days_filter" min="1" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            <p class="text-xs text-gray-500 mt-1">Random ya fresh channel test ke liye `7` ya `30` days common choice hai.</p>
                        </div>
                    </div>
                    
                    <!-- Date Range Section -->
                    <div id="edit_date_range_section" class="hidden">
                        <div class="flex flex-wrap gap-2 mb-3">
                            <button type="button" onclick="applyEditYouTubePreset('today')" class="px-3 py-1 text-xs bg-sky-500/15 border border-sky-500/30 rounded hover:bg-sky-500/25">Today + 1 video</button>
                            <button type="button" onclick="applyEditYouTubePreset('yesterday')" class="px-3 py-1 text-xs bg-gray-700 border border-gray-600 rounded hover:bg-gray-600">Yesterday + 1 video</button>
                            <button type="button" onclick="applyEditYouTubePreset('last7')" class="px-3 py-1 text-xs bg-gray-700 border border-gray-600 rounded hover:bg-gray-600">Last 7 days</button>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">From Date</label>
                                <input type="date" name="video_start_date" id="edit_video_start_date" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">To Date</label>
                                <input type="date" name="video_end_date" id="edit_video_end_date" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Single exact date ke liye `From` aur `To` dono mein same date rakhein.</p>
                    </div>
                </div>
                
                <!-- Video Rotation System -->
                <div class="p-4 bg-gradient-to-r from-indigo-900/20 to-purple-900/20 border border-indigo-500/30 rounded-lg">
                    <div class="flex items-center gap-3 mb-3">
                        <input type="checkbox" name="rotation_enabled" id="edit_rotation_enabled" class="w-5 h-5 accent-indigo-500">
                        <label for="edit_rotation_enabled" class="font-medium text-white flex items-center gap-2 cursor-pointer">
                            <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Smart Video Rotation
                        </label>
                    </div>
                    <p class="text-xs text-gray-400 mb-3">Ensures each video is unique - no repeats until all videos are used</p>
                    
                    <div id="edit_rotation_options" class="space-y-2">
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                                <input type="checkbox" name="rotation_shuffle" id="edit_rotation_shuffle" class="w-4 h-4 accent-indigo-500">
                                Random order (shuffle videos)
                            </label>
                        </div>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                                <input type="checkbox" name="rotation_auto_reset" id="edit_rotation_auto_reset" class="w-4 h-4 accent-indigo-500">
                                Auto-reset when all videos used (start new cycle)
                            </label>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm text-gray-400 mb-1">Videos per run</label>
                                <input type="number" name="videos_per_run" id="edit_videos_per_run" min="1" max="500" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                                <p class="text-xs text-gray-500 mt-1">Process/post this many videos each scheduled run</p>
                            </div>
                            <div class="p-2 bg-indigo-500/10 border border-indigo-500/20 rounded text-xs text-indigo-300 flex items-center">
                                Oldest videos are processed first (sequence batches)
                            </div>
                        </div>
                        <div class="p-2 bg-indigo-500/10 border border-indigo-500/20 rounded text-xs text-indigo-300">
                            Videos with same file size are detected as duplicates even if renamed
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Short Duration (sec)</label>
                        <input type="number" name="short_duration" id="edit_short_duration" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <p class="text-xs text-gray-500 mt-1">Each short clip length. Example: `60` means 60-second shorts.</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Playback Speed</label>
                        <input type="number" name="playback_speed" id="edit_playback_speed" min="0.1" max="3.0" step="0.1" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <p class="text-xs text-gray-500 mt-1">Applies to video and audio together. `1.0` normal, `0.8` slow, `1.3` fast.</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Aspect Ratio</label>
                        <select name="short_aspect_ratio" id="edit_short_aspect_ratio" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            <optgroup label="Crop (Fill Frame)">
                                <option value="9:16">9:16 Vertical - Crop</option>
                                <option value="1:1">1:1 Square - Crop</option>
                                <option value="16:9">16:9 Horizontal - Crop</option>
                            </optgroup>
                            <optgroup label="No Crop (Fit with Black Bars)">
                                <option value="9:16-fit">9:16 Vertical - No Crop (Fit)</option>
                                <option value="1:1-fit">1:1 Square - No Crop (Fit)</option>
                                <option value="16:9-fit">16:9 Horizontal - No Crop (Fit)</option>
                            </optgroup>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Crop fills frame, No Crop keeps full video with black bars</p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Shorts Per Source Video</label>
                        <select name="source_shorts_mode" id="edit_source_shorts_mode" onchange="toggleEditSourceShortsMode(this.value)" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            <option value="single">Single short</option>
                            <option value="duration_based">Auto by duration</option>
                            <option value="fixed_count">Fixed count</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Auto mode makes sequential clips from one source video using the short duration above.</p>
                    </div>
                    <div id="edit_source_shorts_max_count_wrap" class="hidden">
                        <label class="block text-sm text-gray-400 mb-1">Max Shorts Per Source</label>
                        <input type="number" name="source_shorts_max_count" id="edit_source_shorts_max_count" min="1" max="20" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <p class="text-xs text-gray-500 mt-1">Example: 5-minute video + 60s duration + max 5 = up to 5 shorts.</p>
                    </div>
                </div>
                <div class="p-2 bg-amber-500/10 border border-amber-500/20 rounded text-xs text-amber-200">
                    Research-based default: keep this on <span class="font-semibold">Single short</span> unless the source video has multiple strong moments. Official YouTube guidance favors concise clips with a strong opening hook; exact clip count is an inference, so this setting stays user-controlled.
                </div>
                
                <div class="border-t border-gray-800 pt-4 mt-4">
                    <div class="flex items-center gap-3 p-3 bg-green-500/10 border border-green-500/20 rounded-lg mb-3">
                        <input type="checkbox" name="whisper_enabled" id="edit_whisper_enabled" class="w-4 h-4">
                        <label for="edit_whisper_enabled" class="text-sm">Enable Auto-Captions (Whisper AI)</label>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Caption Language</label>
                        <select name="whisper_language" id="edit_whisper_language" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                            <option value="en">English</option>
                            <option value="ur">Urdu</option>
                            <option value="hi">Hindi</option>
                            <option value="ar">Arabic</option>
                        </select>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <button type="button" onclick="showEditFormTab('basic')" class="flex-1 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg">← Back</button>
                    <button type="button" onclick="showEditFormTab('taglines')" class="flex-1 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg">Next: Taglines →</button>
                </div>
            </div>
            
            <!-- Tab 3: Taglines - SUPER SIMPLE -->
            <div id="edit_form_taglines" class="hidden p-4 space-y-4">
                
                <!-- SIMPLE: Just Enable Toggle -->
                <div class="p-6 bg-gradient-to-r from-green-900/30 to-emerald-900/30 border border-green-500/30 rounded-xl">
                    <div class="flex items-center gap-4">
                        <input type="checkbox" name="ai_taglines_enabled" id="edit_ai_taglines_enabled" class="w-8 h-8 rounded-lg accent-green-500">
                        <label for="edit_ai_taglines_enabled" class="flex-1 cursor-pointer">
                            <div class="font-bold text-xl text-white flex items-center gap-2">
                                <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Enable Taglines
                            </div>
                            <div class="text-gray-400 mt-1">Add text overlay to all videos automatically</div>
                        </label>
                    </div>
                </div>
                
                <!-- Preview Box -->
                <div class="p-4 bg-gray-800/50 rounded-lg border border-gray-700">
                    <h4 class="text-sm text-gray-400 mb-3 font-medium">Preview - Each video will have:</h4>
                    <div class="bg-black rounded-lg p-4 text-center space-y-2">
                        <div class="text-yellow-400 font-bold text-lg">"Made With Love"</div>
                        <div class="text-white text-sm">"Get Greeting Video"</div>
                        <div class="text-gray-500 text-xs my-4">[ Your Video ]</div>
                        <div class="text-cyan-400 font-medium">"wishesmadeeasy.com"</div>
                    </div>
                    <p class="text-xs text-gray-500 mt-3 text-center">30+ unique taglines • Each video gets different text • Branding always shown</p>
                </div>
                
                <!-- Hidden fields (keep defaults) -->
                <input type="hidden" name="ai_tagline_prompt" id="edit_ai_tagline_prompt" value="Generate universal greeting taglines">
                <input type="hidden" name="random_words" id="edit_random_words" value="">
                <input type="hidden" name="branding_text_top" id="edit_branding_text_top" value="">
                <input type="hidden" name="branding_text_bottom" id="edit_branding_text_bottom" value="">
                <input type="hidden" name="taglines_json" id="edit_taglines_json" value="[]">
                
                <div class="flex gap-3">
                    <button type="button" onclick="showEditFormTab('video')" class="flex-1 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg">← Back</button>
                    <button type="button" onclick="showEditFormTab('social')" class="flex-1 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg">Next: Publish →</button>
                </div>
            </div>
            
            <!-- Tab 4: Social Media -->
            <div id="edit_form_social" class="hidden p-4 space-y-4">
                <p class="text-sm text-gray-400 mb-4">Select where to auto-publish shorts:</p>
                
                <!-- Post for Me Integration (Recommended) -->
                <div class="p-4 bg-gradient-to-r from-pink-900/30 to-purple-900/30 border border-pink-500/30 rounded-xl">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="postforme_enabled" id="edit_postforme_enabled" class="w-6 h-6 accent-pink-500" onchange="toggleEditPostForMe(this)">
                        <div class="flex-1">
                            <div class="font-bold text-white flex items-center gap-2">
                                <svg class="w-5 h-5 text-pink-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                                Post for Me (Recommended)
                            </div>
                            <div class="text-gray-400 text-sm">One API for all platforms - No developer apps needed!</div>
                        </div>
                        <?php 
                        $postformeKey = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key'")->fetchColumn();
                        if ($postformeKey): ?>
                            <span class="px-2 py-1 bg-green-500/20 text-green-400 rounded text-xs">Connected</span>
                        <?php else: ?>
                            <a href="settings.php?tab=postforme" class="px-2 py-1 bg-yellow-500/20 text-yellow-400 rounded text-xs hover:bg-yellow-500/30">Setup →</a>
                        <?php endif; ?>
                    </label>
                    
                    <!-- Account Selection (shown when Post for Me enabled) -->
                    <div id="edit_postforme_accounts_section" class="hidden mt-4 space-y-3">
                        <?php
                        $postformeAccounts = [];
                        try {
                            $postformeAccounts = $pdo->query("SELECT * FROM postforme_accounts WHERE is_active = 1 ORDER BY platform")->fetchAll();
                        } catch (Exception $e) {}
                        
                        if (empty($postformeAccounts)): ?>
                            <div class="p-3 bg-gray-800/50 rounded-lg text-center">
                                <p class="text-gray-400 text-sm">No accounts connected yet</p>
                                <a href="settings.php?tab=postforme" class="text-pink-400 text-xs hover:underline">Connect accounts in Settings →</a>
                            </div>
                        <?php else: ?>
                            <p class="text-xs text-gray-400">Select accounts to post to:</p>
                            <div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto">
                                <?php foreach ($postformeAccounts as $acc): 
                                    $platformColors = ['youtube' => 'red', 'tiktok' => 'gray', 'instagram' => 'pink', 'facebook' => 'blue', 'threads' => 'gray', 'twitter' => 'blue', 'linkedin' => 'blue'];
                                    $color = $platformColors[$acc['platform']] ?? 'gray';
                                ?>
                                    <label class="flex items-center gap-2 p-2 bg-gray-800/50 rounded-lg cursor-pointer hover:bg-gray-800 border border-transparent hover:border-<?= $color ?>-500/30">
                                        <input type="checkbox" name="postforme_account_ids[]" value="<?= htmlspecialchars($acc['account_id']) ?>" class="edit_postforme_account_check w-4 h-4 accent-<?= $color ?>-500">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm truncate"><?= htmlspecialchars($acc['account_name'] ?? $acc['username']) ?></div>
                                            <div class="text-xs text-<?= $color ?>-400"><?= ucfirst($acc['platform']) ?></div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Post Scheduling Section -->
                        <div class="mt-4 p-4 bg-gray-800/50 rounded-lg border border-gray-700">
                            <h4 class="text-sm font-medium text-white mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Post Scheduling
                            </h4>
                            
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs text-gray-400 mb-1">When to publish posts?</label>
                                    <select name="postforme_schedule_mode" id="edit_postforme_schedule_mode" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm" onchange="toggleEditScheduleMode(this.value)">
                                        <option value="immediate">Post Immediately</option>
                                        <option value="scheduled">Schedule for Specific Date/Time</option>
                                        <option value="offset">Delay After Processing (Offset)</option>
                                    </select>
                                </div>
                                
                                <!-- Scheduled: Specific Date/Time -->
                                <div id="edit_schedule_datetime_section" class="hidden space-y-2">
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Schedule Date & Time</label>
                                        <input type="datetime-local" name="postforme_schedule_datetime" id="edit_postforme_schedule_datetime" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Your Timezone</label>
                                        <select name="postforme_schedule_timezone" id="edit_postforme_schedule_timezone" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm">
                                            <option value="UTC">UTC</option>
                                            <option value="America/New_York">Eastern Time (US)</option>
                                            <option value="America/Chicago">Central Time (US)</option>
                                            <option value="America/Denver">Mountain Time (US)</option>
                                            <option value="America/Los_Angeles">Pacific Time (US)</option>
                                            <option value="Europe/London">London (GMT/BST)</option>
                                            <option value="Europe/Paris">Paris (CET/CEST)</option>
                                            <option value="Europe/Berlin">Berlin (CET/CEST)</option>
                                            <option value="Asia/Dubai">Dubai (GST)</option>
                                            <option value="Asia/Karachi">Pakistan (PKT)</option>
                                            <option value="Asia/Kolkata">India (IST)</option>
                                            <option value="Asia/Shanghai">China (CST)</option>
                                            <option value="Asia/Tokyo">Japan (JST)</option>
                                            <option value="Australia/Sydney">Sydney (AEST)</option>
                                        </select>
                                    </div>
                                    <div class="p-2 bg-purple-500/10 border border-purple-500/20 rounded-lg">
                                        <p class="text-xs text-purple-300">Post will be scheduled on PostForMe.dev and published at the exact date/time you select.</p>
                                    </div>
                                </div>
                                
                                <!-- Offset: Delay after processing -->
                                <div id="edit_schedule_offset_section" class="hidden space-y-2">
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Delay after video processing (minutes)</label>
                                        <input type="number" name="postforme_schedule_offset_minutes" id="edit_postforme_schedule_offset_minutes" value="0" min="0" max="43200" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm" placeholder="e.g., 60 = 1 hour delay">
                                    </div>
                                    <div class="grid grid-cols-4 gap-1">
                                        <button type="button" onclick="setEditOffset(30)" class="px-2 py-1 bg-gray-700 hover:bg-gray-600 rounded text-xs">30m</button>
                                        <button type="button" onclick="setEditOffset(60)" class="px-2 py-1 bg-gray-700 hover:bg-gray-600 rounded text-xs">1h</button>
                                        <button type="button" onclick="setEditOffset(360)" class="px-2 py-1 bg-gray-700 hover:bg-gray-600 rounded text-xs">6h</button>
                                        <button type="button" onclick="setEditOffset(1440)" class="px-2 py-1 bg-gray-700 hover:bg-gray-600 rounded text-xs">24h</button>
                                    </div>
                                    <div class="p-2 bg-blue-500/10 border border-blue-500/20 rounded-lg">
                                        <p class="text-xs text-blue-300">Post will be scheduled X minutes after the video is processed. Useful to stagger posts throughout the day.</p>
                                    </div>
                                </div>
                                
                                <!-- Spread: Time between multiple posts -->
                                <div id="edit_schedule_spread_section" class="hidden">
                                    <label class="block text-xs text-gray-400 mb-1">Spread between posts (minutes)</label>
                                    <input type="number" name="postforme_schedule_spread_minutes" id="edit_postforme_schedule_spread_minutes" value="0" min="0" max="1440" class="w-full px-3 py-2 bg-gray-900 border border-gray-600 rounded-lg text-sm" placeholder="e.g., 15 = 15 min between each post">
                                    <p class="text-xs text-gray-500 mt-1">If processing multiple videos, space posts apart by this many minutes</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Divider -->
                <div class="flex items-center gap-3 py-2">
                    <div class="flex-1 border-t border-gray-700"></div>
                    <span class="text-xs text-gray-500">OR use individual APIs (requires developer apps)</span>
                    <div class="flex-1 border-t border-gray-700"></div>
                </div>
                
                <!-- Legacy Individual Platform Options -->
                <div id="edit_legacy_platforms_section" class="space-y-3">
                    <label class="flex items-center gap-3 p-3 bg-red-500/10 border border-red-500/20 rounded-lg cursor-pointer opacity-60 hover:opacity-100">
                        <input type="checkbox" name="youtube_enabled" id="edit_youtube_enabled" class="w-4 h-4">
                        <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/></svg>
                        <span>YouTube Shorts</span>
                        <span class="ml-auto text-xs text-gray-500">Requires Google API</span>
                    </label>
                    
                    <label class="flex items-center gap-3 p-3 bg-gray-500/10 border border-gray-500/20 rounded-lg cursor-pointer opacity-60 hover:opacity-100">
                        <input type="checkbox" name="tiktok_enabled" id="edit_tiktok_enabled" class="w-4 h-4">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-5.2 1.74 2.89 2.89 0 012.31-4.64 2.93 2.93 0 01.88.13V9.4a6.84 6.84 0 00-1-.05A6.33 6.33 0 005 20.1a6.34 6.34 0 0010.86-4.43v-7a8.16 8.16 0 004.77 1.52v-3.4a4.85 4.85 0 01-1-.1z"/></svg>
                        <span>TikTok</span>
                        <span class="ml-auto text-xs text-gray-500">Requires TikTok App</span>
                    </label>
                    
                    <label class="flex items-center gap-3 p-3 bg-pink-500/10 border border-pink-500/20 rounded-lg cursor-pointer opacity-60 hover:opacity-100">
                        <input type="checkbox" name="instagram_enabled" id="edit_instagram_enabled" class="w-4 h-4">
                        <svg class="w-5 h-5 text-pink-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069z"/></svg>
                        <span>Instagram Reels</span>
                        <span class="ml-auto text-xs text-gray-500">Requires Meta App</span>
                    </label>
                    
                    <label class="flex items-center gap-3 p-3 bg-blue-500/10 border border-blue-500/20 rounded-lg cursor-pointer opacity-60 hover:opacity-100">
                        <input type="checkbox" name="facebook_enabled" id="edit_facebook_enabled" class="w-4 h-4">
                        <svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 8h-3v4h3v12h5v-12h3.642l.358-4h-4v-1.667c0-.955.192-1.333 1.115-1.333h2.885v-5h-3.808c-3.596 0-5.192 1.583-5.192 4.615v3.385z"/></svg>
                        <span>Facebook Reels</span>
                        <span class="ml-auto text-xs text-gray-500">Requires Meta App</span>
                    </label>
                </div>
                
                <p class="text-xs text-gray-500">Configure API keys in <a href="settings.php?tab=postforme" class="text-pink-400">Settings → Post for Me</a> or <a href="settings.php?tab=stream" class="text-indigo-400">Settings → Stream APIs</a></p>
                
                <div class="flex gap-3 pt-4 border-t border-gray-800">
                    <button type="button" onclick="showEditFormTab('taglines')" class="flex-1 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg">← Back</button>
                    <button type="submit" class="flex-1 py-3 bg-green-600 hover:bg-green-700 rounded-lg font-medium">
                        ✓ Update Automation
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function showFormTab(tab) {
    // Hide all tabs
    ['basic', 'video', 'taglines', 'social'].forEach(t => {
        document.getElementById('form_' + t).classList.add('hidden');
        document.getElementById('tab_' + t).classList.remove('border-indigo-500', 'text-white');
        document.getElementById('tab_' + t).classList.add('border-transparent', 'text-gray-400');
    });
    // Show selected tab
    document.getElementById('form_' + tab).classList.remove('hidden');
    document.getElementById('tab_' + tab).classList.add('border-indigo-500', 'text-white');
    document.getElementById('tab_' + tab).classList.remove('border-transparent', 'text-gray-400');
}

function toggleVideoSource(select) {
    const bunnySection = document.getElementById('bunny_source_section');
    const ftpSection = document.getElementById('ftp_source_info');
    const manualSection = document.getElementById('manual_source_section');
    const youtubeSection = document.getElementById('youtube_source_section');
    if (bunnySection) bunnySection.classList.toggle('hidden', select.value !== 'bunny');
    if (ftpSection) ftpSection.classList.toggle('hidden', select.value !== 'ftp');
    if (manualSection) manualSection.classList.toggle('hidden', select.value !== 'manual_links');
    if (youtubeSection) youtubeSection.classList.toggle('hidden', select.value !== 'youtube_channel');
    if (select.value === 'youtube_channel') {
        const videosPerRun = document.getElementById('videos_per_run');
        if (videosPerRun && (!videosPerRun.value || videosPerRun.value === '5')) {
            videosPerRun.value = '1';
        }
    }
}

function togglePostForMe(checkbox) {
    const accountsSection = document.getElementById('postforme_accounts_section');
    const legacySection = document.getElementById('legacy_platforms_section');
    
    if (checkbox.checked) {
        accountsSection.classList.remove('hidden');
        legacySection.classList.add('opacity-40');
        document.querySelectorAll('#legacy_platforms_section input[type="checkbox"]').forEach(cb => cb.checked = false);
    } else {
        accountsSection.classList.add('hidden');
        legacySection.classList.remove('opacity-40');
    }
}

function toggleScheduleMode(mode) {
    document.getElementById('schedule_datetime_section').classList.toggle('hidden', mode !== 'scheduled');
    document.getElementById('schedule_offset_section').classList.toggle('hidden', mode !== 'offset');
    document.getElementById('schedule_spread_section').classList.toggle('hidden', mode === 'immediate');
}

function setOffset(minutes) {
    document.getElementById('postforme_schedule_offset_minutes').value = minutes;
}

function toggleAITaglines(checkbox) {
    document.getElementById('ai_taglines_section').classList.toggle('hidden', !checkbox.checked);
    // Keep manual section visible but show different label based on mode
    const manualSection = document.getElementById('manual_taglines_section');
    const manualLabel = document.getElementById('manual_section_label');
    
    if (checkbox.checked) {
        // AI mode - manual fields are fallback/default
        if (manualLabel) {
            manualLabel.innerHTML = '💡 <span class="text-yellow-400">Fallback Text</span> (used if AI fails or as default):';
        }
    } else {
        // Manual mode - these are the main text overlays
        if (manualLabel) {
            manualLabel.innerHTML = 'Set overlay text for videos:';
        }
    }
}

// Function to toggle video selection method in create form
function toggleVideoSelectionMethod() {
    const form = document.getElementById('form_video');
    const method = form.querySelector('input[name="video_selection_method"]:checked').value;
    document.getElementById('days_filter_section').classList.toggle('hidden', method === 'date_range');
    document.getElementById('date_range_section').classList.toggle('hidden', method === 'days');
    // Update the hidden field to match the selected method
    document.getElementById('video_selection_method_hidden').value = method;
}

function toggleSourceShortsMode(mode) {
    const wrap = document.getElementById('source_shorts_max_count_wrap');
    const input = document.getElementById('source_shorts_max_count');
    const normalized = (mode || 'single').toString();
    const isSingle = normalized === 'single';
    if (wrap) wrap.classList.toggle('hidden', isSingle);
    if (input) {
        input.disabled = isSingle;
        if (isSingle) {
            input.value = '1';
        } else if (!input.value || input.value === '1') {
            input.value = '5';
        }
    }
}

function toLocalDateValue(date) {
    const tzOffsetMs = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - tzOffsetMs).toISOString().slice(0, 10);
}

function applyCreateYouTubePreset(preset) {
    const videosPerRun = document.getElementById('videos_per_run');
    const daysInput = document.getElementById('video_days_filter');
    const startInput = document.getElementById('video_start_date');
    const endInput = document.getElementById('video_end_date');
    const daysRadio = document.querySelector('#form_video input[name="video_selection_method"][value="days"]');
    const dateRangeRadio = document.querySelector('#form_video input[name="video_selection_method"][value="date_range"]');
    const now = new Date();

    if (preset === 'last7') {
        if (daysRadio) daysRadio.checked = true;
        if (daysInput) daysInput.value = '7';
    } else {
        const target = new Date(now);
        if (preset === 'yesterday') {
            target.setDate(target.getDate() - 1);
        }
        const iso = toLocalDateValue(target);
        if (dateRangeRadio) dateRangeRadio.checked = true;
        if (startInput) startInput.value = iso;
        if (endInput) endInput.value = iso;
    }

    if (videosPerRun) videosPerRun.value = '1';
    toggleVideoSelectionMethod();
}

// Edit Form Functions
function showEditFormTab(tab) {
    // Hide all tabs
    ['basic', 'video', 'taglines', 'social'].forEach(t => {
        document.getElementById('edit_form_' + t).classList.add('hidden');
        document.getElementById('edit_tab_' + t).classList.remove('border-indigo-500', 'text-white');
        document.getElementById('edit_tab_' + t).classList.add('border-transparent', 'text-gray-400');
    });
    // Show selected tab
    document.getElementById('edit_form_' + tab).classList.remove('hidden');
    document.getElementById('edit_tab_' + tab).classList.add('border-indigo-500', 'text-white');
    document.getElementById('edit_tab_' + tab).classList.remove('border-transparent', 'text-gray-400');
}

function toggleEditVideoSource(select) {
    const bunnySection = document.getElementById('edit_bunny_source_section');
    const ftpSection = document.getElementById('edit_ftp_source_info');
    const manualSection = document.getElementById('edit_manual_source_section');
    const youtubeSection = document.getElementById('edit_youtube_source_section');
    if (bunnySection) bunnySection.classList.toggle('hidden', select.value !== 'bunny');
    if (ftpSection) ftpSection.classList.toggle('hidden', select.value !== 'ftp');
    if (manualSection) manualSection.classList.toggle('hidden', select.value !== 'manual_links');
    if (youtubeSection) youtubeSection.classList.toggle('hidden', select.value !== 'youtube_channel');
}

function toggleEditPostForMe(checkbox) {
    const accountsSection = document.getElementById('edit_postforme_accounts_section');
    const legacySection = document.getElementById('edit_legacy_platforms_section');
    
    if (checkbox.checked) {
        accountsSection.classList.remove('hidden');
        legacySection.classList.add('opacity-40');
        document.querySelectorAll('#edit_legacy_platforms_section input[type="checkbox"]').forEach(cb => cb.checked = false);
    } else {
        accountsSection.classList.add('hidden');
        legacySection.classList.remove('opacity-40');
    }
}

function toggleEditScheduleMode(mode) {
    document.getElementById('edit_schedule_datetime_section').classList.toggle('hidden', mode !== 'scheduled');
    document.getElementById('edit_schedule_offset_section').classList.toggle('hidden', mode !== 'offset');
    document.getElementById('edit_schedule_spread_section').classList.toggle('hidden', mode === 'immediate');
}

function setEditOffset(minutes) {
    document.getElementById('edit_postforme_schedule_offset_minutes').value = minutes;
}

// Function to toggle video selection method in edit form
function toggleEditVideoSelectionMethod() {
    const form = document.getElementById('edit_form_video');
    const method = form.querySelector('input[name="video_selection_method"]:checked').value;
    document.getElementById('edit_days_filter_section').classList.toggle('hidden', method === 'date_range');
    document.getElementById('edit_date_range_section').classList.toggle('hidden', method === 'days');
    // Update the hidden field to match the selected method
    document.getElementById('edit_video_selection_method_hidden').value = method;
}

function toggleEditSourceShortsMode(mode) {
    const wrap = document.getElementById('edit_source_shorts_max_count_wrap');
    const input = document.getElementById('edit_source_shorts_max_count');
    const normalized = (mode || 'single').toString();
    const isSingle = normalized === 'single';
    if (wrap) wrap.classList.toggle('hidden', isSingle);
    if (input) {
        input.disabled = isSingle;
        if (isSingle) {
            input.value = '1';
        } else if (!input.value || input.value === '1') {
            input.value = '5';
        }
    }
}

function applyEditYouTubePreset(preset) {
    const videosPerRun = document.getElementById('edit_videos_per_run');
    const daysInput = document.getElementById('edit_video_days_filter');
    const startInput = document.getElementById('edit_video_start_date');
    const endInput = document.getElementById('edit_video_end_date');
    const daysRadio = document.getElementById('edit_video_selection_days');
    const dateRangeRadio = document.getElementById('edit_video_selection_date_range');
    const now = new Date();

    if (preset === 'last7') {
        if (daysRadio) daysRadio.checked = true;
        if (daysInput) daysInput.value = '7';
    } else {
        const target = new Date(now);
        if (preset === 'yesterday') {
            target.setDate(target.getDate() - 1);
        }
        const iso = toLocalDateValue(target);
        if (dateRangeRadio) dateRangeRadio.checked = true;
        if (startInput) startInput.value = iso;
        if (endInput) endInput.value = iso;
    }

    if (videosPerRun) videosPerRun.value = '1';
    toggleEditVideoSelectionMethod();
}

// Function to open edit modal and populate with automation data
function openEditModal(automationData) {
    if (!automationData || typeof automationData !== 'object') {
        if (typeof showToast === 'function') {
            showToast('Unable to load automation data for edit', 'error');
        }
        return;
    }

    // Set form values
    document.getElementById('edit_automation_id').value = automationData.id;
    document.getElementById('edit_name').value = automationData.name || '';
    document.getElementById('edit_video_source').value = automationData.video_source || 'ftp';
    document.getElementById('edit_run_mode').value = automationData.run_mode || 'local';
    document.getElementById('edit_api_key_id').value = automationData.api_key_id || '';
    document.getElementById('edit_schedule_type').value = automationData.schedule_type || 'daily';
    document.getElementById('edit_schedule_hour').value = automationData.schedule_hour || 9;
    document.getElementById('edit_schedule_every_minutes').value = automationData.schedule_every_minutes || 10;
    document.getElementById('edit_enabled').checked = automationData.enabled == 1;
    document.getElementById('edit_video_days_filter').value = automationData.video_days_filter || 30;
    document.getElementById('edit_video_start_date').value = automationData.video_start_date || '';
    document.getElementById('edit_video_end_date').value = automationData.video_end_date || '';
    document.getElementById('edit_videos_per_run').value = automationData.videos_per_run || 5;
    document.getElementById('edit_short_duration').value = automationData.short_duration || 60;
    document.getElementById('edit_playback_speed').value = automationData.playback_speed || 1.0;
    document.getElementById('edit_source_shorts_mode').value = automationData.source_shorts_mode || 'single';
    document.getElementById('edit_source_shorts_max_count').value = automationData.source_shorts_max_count || 1;
    document.getElementById('edit_short_aspect_ratio').value = automationData.short_aspect_ratio || '9:16';
    document.getElementById('edit_whisper_enabled').checked = automationData.whisper_enabled == 1;
    document.getElementById('edit_whisper_language').value = automationData.whisper_language || 'en';
    document.getElementById('edit_ai_taglines_enabled').checked = automationData.ai_taglines_enabled == 1;
    document.getElementById('edit_ai_tagline_prompt').value = automationData.ai_tagline_prompt || 'Generate universal greeting taglines';
    document.getElementById('edit_branding_text_top').value = automationData.branding_text_top || '';
    document.getElementById('edit_branding_text_bottom').value = automationData.branding_text_bottom || '';
    document.getElementById('edit_manual_video_links').value = automationData.manual_video_links || '';
    document.getElementById('edit_youtube_channel_url').value = automationData.youtube_channel_url || '';
    document.getElementById('edit_rotation_enabled').checked = automationData.rotation_enabled == 1;
    document.getElementById('edit_rotation_shuffle').checked = automationData.rotation_shuffle == 1;
    document.getElementById('edit_rotation_auto_reset').checked = automationData.rotation_auto_reset == 1;
    document.getElementById('edit_postforme_enabled').checked = automationData.postforme_enabled == 1;
    document.getElementById('edit_youtube_enabled').checked = automationData.youtube_enabled == 1;
    document.getElementById('edit_tiktok_enabled').checked = automationData.tiktok_enabled == 1;
    document.getElementById('edit_instagram_enabled').checked = automationData.instagram_enabled == 1;
    document.getElementById('edit_facebook_enabled').checked = automationData.facebook_enabled == 1;
    document.getElementById('edit_postforme_schedule_mode').value = automationData.postforme_schedule_mode || 'immediate';
    
    // Handle PostForMe account selections
    let accountIds = [];
    try {
        accountIds = JSON.parse(automationData.postforme_account_ids || '[]');
        if (!Array.isArray(accountIds)) accountIds = [];
    } catch (_) {
        accountIds = [];
    }
    document.querySelectorAll('.edit_postforme_account_check').forEach(checkbox => {
        checkbox.checked = accountIds.includes(checkbox.value);
    });
    
    // Set datetime if available
    if (automationData.postforme_schedule_datetime) {
        // Convert MySQL datetime to datetime-local format (YYYY-MM-DDTHH:mm)
        const dateStr = automationData.postforme_schedule_datetime.replace(' ', 'T');
        document.getElementById('edit_postforme_schedule_datetime').value = dateStr.substring(0, 16);
    }
    
    document.getElementById('edit_postforme_schedule_timezone').value = automationData.postforme_schedule_timezone || 'UTC';
    document.getElementById('edit_postforme_schedule_offset_minutes').value = automationData.postforme_schedule_offset_minutes || 0;
    document.getElementById('edit_postforme_schedule_spread_minutes').value = automationData.postforme_schedule_spread_minutes || 0;
    
    // Determine which video selection method to use and update UI
    if (automationData.video_start_date && automationData.video_end_date) {
        document.getElementById('edit_video_selection_date_range').checked = true;
        document.getElementById('edit_video_selection_days').checked = false;
        document.getElementById('edit_video_selection_method_hidden').value = 'date_range';
    } else {
        document.getElementById('edit_video_selection_days').checked = true;
        document.getElementById('edit_video_selection_date_range').checked = false;
        document.getElementById('edit_video_selection_method_hidden').value = 'days';
    }
    
    // Show/hide sections based on current values
    toggleEditVideoSource(document.getElementById('edit_video_source'));
    toggleEditPostForMe(document.getElementById('edit_postforme_enabled'));
    toggleEditScheduleMode(automationData.postforme_schedule_mode || 'immediate');
    toggleEditVideoSelectionMethod(); // Apply the selection method toggle
    toggleEditSourceShortsMode(automationData.source_shorts_mode || 'single');
    
    // Show basic tab initially
    showEditFormTab('basic');
    
    // Show the modal
    document.getElementById('editModal').classList.remove('hidden');
}

// Taglines List Management
let taglinesList = [];

function updateTaglinesDisplay() {
    const container = document.getElementById('taglines_list_container');
    const countEl = document.getElementById('taglines_count');
    const emptyMsg = document.getElementById('taglines_empty_msg');
    const jsonField = document.getElementById('taglines_json');
    
    // Update hidden field
    jsonField.value = JSON.stringify(taglinesList);
    
    // Update count
    countEl.textContent = taglinesList.length + ' items';
    countEl.className = taglinesList.length > 0 ? 'text-xs bg-green-600 px-2 py-1 rounded-full' : 'text-xs bg-gray-600 px-2 py-1 rounded-full';
    
    // Update first tagline as default fallback
    if (taglinesList.length > 0) {
        document.getElementById('branding_text_top').value = taglinesList[0].top;
        document.getElementById('branding_text_bottom').value = taglinesList[0].bottom;
    }
    
    if (taglinesList.length === 0) {
        container.innerHTML = '<p class="text-gray-500 text-sm italic" id="taglines_empty_msg">No taglines added yet. Use AI Generate or add manually below.</p>';
        return;
    }
    
    let html = '';
    taglinesList.forEach((item, index) => {
        html += `
            <div class="flex items-center gap-2 p-2 bg-gray-800 rounded-lg border border-gray-700 group">
                <span class="text-xs text-gray-500 w-6">#${index + 1}</span>
                <div class="flex-1 grid grid-cols-2 gap-2 text-sm">
                    <div class="flex items-center gap-1">
                        <span class="text-green-400 text-xs">TOP:</span>
                        <span class="text-white truncate">${escapeHtml(item.top)}</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="text-blue-400 text-xs">BTM:</span>
                        <span class="text-white truncate">${escapeHtml(item.bottom)}</span>
                    </div>
                </div>
                <button type="button" onclick="removeTagline(${index})" class="opacity-0 group-hover:opacity-100 text-red-400 hover:text-red-300 p-1" title="Remove">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        `;
    });
    container.innerHTML = html;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function addTaglineToList(top, bottom) {
    if (!top.trim() && !bottom.trim()) {
        showToast('Enter at least top or bottom text', 'error');
        return false;
    }
    taglinesList.push({ top: top.trim(), bottom: bottom.trim() });
    updateTaglinesDisplay();
    return true;
}

function removeTagline(index) {
    taglinesList.splice(index, 1);
    updateTaglinesDisplay();
    showToast('Tagline removed', 'info');
}

function clearTaglinesList() {
    if (taglinesList.length === 0) return;
    if (!confirm('Clear all ' + taglinesList.length + ' taglines?')) return;
    taglinesList = [];
    updateTaglinesDisplay();
    showToast('List cleared', 'info');
}

function addManualTagline() {
    const topText = document.getElementById('manual_top_text').value;
    const bottomText = document.getElementById('manual_bottom_text').value;
    
    if (addTaglineToList(topText, bottomText)) {
        document.getElementById('manual_top_text').value = '';
        document.getElementById('manual_bottom_text').value = '';
        showToast('Tagline added to list!', 'success');
    }
}

// AI Refinement for overlay text
function refineWithAI(position) {
    const promptField = document.getElementById('ai_prompt_input');
    const outputField = document.getElementById('manual_' + position + '_text');
    const btn = event.target.closest('button');
    
    const prompt = promptField.value.trim();
    if (!prompt) {
        showToast('Enter an AI prompt first (e.g. "Create birthday greetings")', 'error');
        promptField.focus();
        return;
    }
    
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>';
    btn.disabled = true;
    
    fetch('api/refine-text.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ prompt: prompt, position: position })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.text) {
            outputField.value = data.text;
            outputField.classList.add('ring-2', 'ring-green-500');
            setTimeout(() => outputField.classList.remove('ring-2', 'ring-green-500'), 2000);
            showToast((position === 'top' ? 'Top' : 'Bottom') + ': "' + data.text + '"', 'success');
        } else {
            showToast(data.error || 'AI failed', 'error');
        }
    })
    .catch(err => showToast('Error: ' + err.message, 'error'))
    .finally(() => {
        btn.innerHTML = 'AI';
        btn.disabled = false;
    });
}

function refineAndAdd() {
    const promptField = document.getElementById('ai_prompt_input');
    const prompt = promptField.value.trim();
    
    if (!prompt) {
        showToast('Enter an AI prompt first', 'error');
        promptField.focus();
        return;
    }
    
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Generating...';
    btn.disabled = true;
    
    // Generate both top and bottom
    Promise.all([
        fetch('api/refine-text.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ prompt: prompt, position: 'top' })
        }).then(r => r.json()),
        fetch('api/refine-text.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ prompt: prompt, position: 'bottom' })
        }).then(r => r.json())
    ])
    .then(([topData, bottomData]) => {
        if (topData.success && bottomData.success) {
            addTaglineToList(topData.text, bottomData.text);
            showToast('AI generated and added!', 'success');
        } else {
            showToast('AI generation failed', 'error');
        }
    })
    .catch(err => showToast('Error: ' + err.message, 'error'))
    .finally(() => {
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    });
}

// LOCAL GENERATOR - No API limits! Uses templates + your words
function generateLocalTaglines() {
    const prompt = document.getElementById('ai_prompt_input').value.trim() || 
                   document.querySelector('[name="ai_tagline_prompt"]')?.value.trim() || '';
    const randomWords = document.querySelector('[name="random_words"]')?.value.trim() || '';
    
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Generating...';
    btn.disabled = true;
    
    fetch('api/generate-local-taglines.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ 
            instructions: prompt,
            words: randomWords,
            count: 10  // Generate more since it's free!
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.taglines) {
            data.taglines.forEach(t => {
                addTaglineToList(t.top, t.bottom);
            });
            showToast('Added ' + data.taglines.length + ' taglines (No API used!)', 'success');
        } else {
            showToast(data.error || 'Failed to generate', 'error');
        }
    })
    .catch(err => showToast('Error: ' + err.message, 'error'))
    .finally(() => {
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    });
}

// AI GENERATOR - Uses Gemini/OpenAI API (has limits)
function generateBulkTaglines() {
    const prompt = document.getElementById('ai_prompt_input').value.trim() || 
                   document.querySelector('[name="ai_tagline_prompt"]')?.value.trim() ||
                   'Generate creative, catchy taglines for viral videos';
    
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> AI...';
    btn.disabled = true;
    
    fetch('api/generate-bulk-taglines.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ 
            prompt: prompt, 
            count: 5,
            existing: taglinesList
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.taglines) {
            data.taglines.forEach(t => {
                addTaglineToList(t.top, t.bottom);
            });
            showToast('Added ' + data.taglines.length + ' AI taglines!', 'success');
        } else {
            showToast(data.error || 'Failed to generate', 'error');
        }
    })
    .catch(err => showToast('Error: ' + err.message, 'error'))
    .finally(() => {
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    });
}

function killAllProcesses() {
    if (!confirm('Stop ALL running background processes?\n\nThis will:\n- Kill any video processing in progress\n- Reset all automation status\n- Clear progress data')) {
        return;
    }
    
    // Show loading state
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Stopping...';
    btn.disabled = true;
    
    fetch('api/kill-all-processes.php')
        .then(r => r.json())
        .then(data => {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
            
            if (data.success) {
                // Build detailed message
                let msg = data.message;
                if (data.details && data.details.length > 0) {
                    msg += '\n\nDetails:\n' + data.details.join('\n');
                }
                
                // Show toast with appropriate color
                if (data.killed > 0 || data.db_reset > 0) {
                    showToast('✅ ' + data.message, 'success');
                } else {
                    showToast('ℹ️ ' + data.message, 'info');
                }
                
                // Show detailed alert if processes were killed
                if (data.killed > 0) {
                    alert('Stopped ' + data.killed + ' process(es)\n\n' + (data.details || []).join('\n'));
                }
                
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('❌ Error: ' + (data.error || 'Failed to stop processes'), 'error');
            }
        })
        .catch(err => {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

function testFetch(automationId, source) {
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
    btn.disabled = true;
    
    fetch(`test-fetch.php?id=${automationId}&source=${source}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(`Found ${data.count} videos from ${data.source}!`);
                console.log('Videos:', data.videos);
            } else {
                showToast(`Error: ${data.error}`, 'error');
                console.error('Fetch error:', data);
            }
        })
        .catch(err => {
            showToast('Fetch failed: ' + err.message, 'error');
        })
        .finally(() => {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        });
}

function confirmRun(form) {
    event.preventDefault();
    const automationId = form.querySelector('input[name="id"]').value;
    runAutomationSmart(automationId, 'local');
    return false;
}

function setCardStatus(automationId, status) {
    const badge = document.getElementById('status-badge-' + automationId);
    if (!badge) return;

    const statusClasses = {
        running: 'bg-green-500/10 text-green-500',
        processing: 'bg-blue-500/10 text-blue-500 animate-pulse',
        queued: 'bg-yellow-500/10 text-yellow-500',
        completed: 'bg-green-500/10 text-green-500',
        error: 'bg-red-500/10 text-red-500',
        stopped: 'bg-orange-500/10 text-orange-500'
    };

    badge.className = 'px-2 py-1 rounded text-xs font-medium ' + (statusClasses[status] || 'bg-gray-500/10 text-gray-400');
    badge.dataset.status = status;
    badge.textContent = (status === 'queued') ? 'queued' : status;
}

function runAutomationSmart(automationId, runMode) {
    if (runMode === 'github_runner') {
        runAutomationGithub(automationId);
        return;
    }
    runAutomationLive(automationId);
}

function runAutomationGithub(automationId) {
    const progressSection = document.getElementById('progress-' + automationId);
    const progressBar = document.getElementById('progress-bar-' + automationId);
    const progressPercent = document.getElementById('progress-percent-' + automationId);
    const progressLog = document.getElementById('progress-log-' + automationId);
    const progressOutputs = document.getElementById('progress-outputs-' + automationId);

    if (progressSection) {
        progressSection.classList.remove('hidden');
    }
    if (progressBar) {
        progressBar.style.width = '5%';
    }
    if (progressPercent) {
        progressPercent.textContent = '5%';
    }
    if (progressLog) {
        progressLog.innerHTML = '';
        addCardLog(progressLog, 'Dispatching to GitHub runner...', 'info');
    }
    if (progressOutputs) {
        progressOutputs.innerHTML = '<div class="text-gray-500">Waiting for edited outputs...</div>';
    }

    setCardStatus(automationId, 'processing');

    fetch('api/start-automation.php?id=' + encodeURIComponent(automationId), { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                throw new Error((data && data.error) ? data.error : 'GitHub dispatch failed');
            }

            const status = data.status || 'running';
            setCardStatus(automationId, status);
            if (progressLog) {
                addCardLog(progressLog, data.message || 'Dispatched to GitHub runner.', 'success');
                if (data.run_url) {
                    addCardLog(progressLog, data.run_url, 'info');
                }
            }

            if (typeof showToast === 'function') {
                showToast(data.message || 'GitHub runner dispatched');
            }

            resumePolling(automationId);
        })
        .catch(err => {
            setCardStatus(automationId, 'error');
            if (progressLog) {
                addCardLog(progressLog, err.message || 'GitHub dispatch failed', 'error');
            }
            if (typeof showToast === 'function') {
                showToast(err.message || 'GitHub dispatch failed', 'error');
            }
        });
}

function runAutomationLive(automationId) {
    console.log('Starting automation with SSE:', automationId);
    
    // Show progress section on card
    const progressSection = document.getElementById('progress-' + automationId);
    const progressBar = document.getElementById('progress-bar-' + automationId);
    const progressPercent = document.getElementById('progress-percent-' + automationId);
    const progressLog = document.getElementById('progress-log-' + automationId);
    const progressOutputs = document.getElementById('progress-outputs-' + automationId);
    const statFetched = document.getElementById('stat-fetched-' + automationId);
    const statDownloaded = document.getElementById('stat-downloaded-' + automationId);
    const statProcessed = document.getElementById('stat-processed-' + automationId);
    const statScheduled = document.getElementById('stat-scheduled-' + automationId);
    const statPosted = document.getElementById('stat-posted-' + automationId);
    
    // Also show modal
    const modal = document.getElementById('liveLogModal');
    const modalLogContainer = document.getElementById('liveLogContainer');
    const modalProgressBar = document.getElementById('liveProgressBar');
    const modalProgressText = document.getElementById('liveProgressText');
    
    if (!progressSection || !modal) {
        alert('Error: UI elements not found');
        return;
    }
    
    // Reset and show
    progressSection.classList.remove('hidden');
    progressBar.style.width = '0%';
    progressPercent.textContent = '0%';
    progressLog.innerHTML = '';
    if (progressOutputs) {
        progressOutputs.innerHTML = '<div class="text-gray-500">No edited output yet</div>';
    }
    statFetched.textContent = '0';
    statDownloaded.textContent = '0';
    statProcessed.textContent = '0';
    if (statScheduled) statScheduled.textContent = '0';
    statPosted.textContent = '0';
    
    modal.classList.remove('hidden');
    modalLogContainer.innerHTML = '<div class="text-yellow-400">Connecting to server...</div>';
    modalProgressBar.style.width = '0%';
    modalProgressText.textContent = '0%';
    
    processComplete = false;
    setCardStatus(automationId, 'processing');
    
    // Use Server-Sent Events for real-time progress
    const eventSource = new EventSource('api/run-sync.php?id=' + automationId);
    
    eventSource.onmessage = function(event) {
        try {
            const data = JSON.parse(event.data);
            
            // Update progress bar
            const progress = data.progress || 0;
            progressBar.style.width = progress + '%';
            progressPercent.textContent = progress + '%';
            modalProgressBar.style.width = progress + '%';
            modalProgressText.textContent = progress + '%';
            
            // Update stats if available
            if (data.stats) {
                statFetched.textContent = data.stats.fetched || 0;
                statDownloaded.textContent = data.stats.downloaded || 0;
                statProcessed.textContent = data.stats.processed || 0;
                if (statScheduled) statScheduled.textContent = data.stats.scheduled || 0;
                statPosted.textContent = data.stats.posted || 0;
            }
            if (Array.isArray(data.outputs)) {
                renderCardOutputs(automationId, data.outputs);
            }
            
            // Add log entry
            if (data.message) {
                const statusIcon = data.status === 'success' ? '✓' : 
                                   data.status === 'error' ? '✗' : 
                                   data.status === 'warning' ? '⚠' : '→';
                addLogEntry(modalLogContainer, data.step, data.status, `[${data.time}] ${statusIcon} ${data.message}`);
                addCardLog(progressLog, data.message, data.status);
            }
            
            // Check if done
            if (data.done) {
                processComplete = true;
                eventSource.close();
                
                if (data.success) {
                    setCardStatus(automationId, 'completed');
                    if (typeof loadOutputVideoCount === 'function') {
                        loadOutputVideoCount();
                    }
                    addLogEntry(modalLogContainer, 'complete', 'success', '✅ ' + data.message);
                    addCardLog(progressLog, '✅ Complete!', 'success');
                } else {
                    setCardStatus(automationId, 'error');
                    addLogEntry(modalLogContainer, 'error', 'error', '❌ ' + data.message);
                    addCardLog(progressLog, data.message, 'error');
                }
            }
        } catch (e) {
            console.error('Parse error:', e, event.data);
        }
    };
    
    eventSource.onerror = function(error) {
        console.error('SSE Error:', error);
        eventSource.close();
        
        if (!processComplete) {
            addLogEntry(modalLogContainer, 'warn', 'warning', '⚠ Connection interrupted - checking progress from database...');
            
            // Fall back to polling from database
            pollDatabaseProgress(automationId, modalLogContainer, modalProgressBar, modalProgressText, 
                                 progressBar, progressPercent, progressLog, 
                                 statFetched, statDownloaded, statProcessed, statPosted);
        }
    };
    
    // Store eventSource globally so we can close it if user stops
    window.currentEventSource = eventSource;
}

// Poll database for progress updates (works even if popup closes)
function pollDatabaseProgress(automationId, modalLogContainer, modalProgressBar, modalProgressText, 
                               cardProgressBar, cardProgressPercent, progressLog,
                               statFetched, statDownloaded, statProcessed, statPosted) {
    let lastMessage = '';
    let pollInterval = 1000; // Start with 1 second polling
    let noChangeCount = 0;
    
    function poll() {
        fetch('api/check-progress.php?id=' + automationId + '&with_logs=1')
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    // Retry a few times before giving up
                    noChangeCount++;
                    if (noChangeCount < 5) {
                        setTimeout(poll, 2000);
                    } else {
                    setCardStatus(automationId, 'error');
                    addLogEntry(modalLogContainer, 'error', 'error', '❌ Lost connection to process');
                    }
                    return;
                }
                
                const progress = data.progress || 0;
                cardProgressBar.style.width = progress + '%';
                cardProgressPercent.textContent = Math.round(progress) + '%';
                modalProgressBar.style.width = progress + '%';
                modalProgressText.textContent = Math.round(progress) + '%';
                
                if (data.data && data.data.stats) {
                    statFetched.textContent = data.data.stats.fetched || 0;
                    statDownloaded.textContent = data.data.stats.downloaded || 0;
                    statProcessed.textContent = data.data.stats.processed || 0;
                    statPosted.textContent = data.data.stats.posted || 0;
                }
                if (data.data && Array.isArray(data.data.outputs)) {
                    renderCardOutputs(automationId, data.data.outputs);
                }
                
                // Show new log entries
                if (data.data && data.data.message && data.data.message !== lastMessage) {
                    lastMessage = data.data.message;
                    noChangeCount = 0;
                    const statusIcon = data.data.status === 'success' ? '✓' : 
                                       data.data.status === 'error' ? '✗' : '→';
                    addLogEntry(modalLogContainer, data.data.step || 'info', data.data.status || 'info', 
                               `[${data.data.time || new Date().toLocaleTimeString()}] ${statusIcon} ${data.data.message}`);
                    addCardLog(progressLog, data.data.message, data.data.status || 'info');
                } else {
                    noChangeCount++;
                }
                
                // Handle end states
                if (data.status === 'completed') {
                    setCardStatus(automationId, 'completed');
                    addLogEntry(modalLogContainer, 'complete', 'success', '✅ Process completed!');
                    addCardLog(progressLog, '✅ Complete!', 'success');
                    if (typeof loadOutputVideoCount === 'function') {
                        loadOutputVideoCount();
                    }
                    processComplete = true;
                    return;
                } else if (data.status === 'error') {
                    setCardStatus(automationId, 'error');
                    addLogEntry(modalLogContainer, 'error', 'error', '❌ Process failed');
                    processComplete = true;
                    return;
                } else if (data.status === 'stopped') {
                    setCardStatus(automationId, 'stopped');
                    addLogEntry(modalLogContainer, 'stopped', 'info', '⏹ Process stopped by user');
                    processComplete = true;
                    return;
                }
                
                // Adaptive polling - slower if no changes
                pollInterval = noChangeCount > 3 ? 2000 : 1000;
                
                // Continue polling for processing or idle state
                setTimeout(poll, pollInterval);
            })
            .catch(err => {
                console.error('Poll error:', err);
                setTimeout(poll, 3000);
            });
    }
    
    poll();
}

function renderCardOutputs(automationId, outputs) {
    const container = document.getElementById('progress-outputs-' + automationId);
    if (!container) return;

    const unique = [];
    if (Array.isArray(outputs)) {
        outputs.forEach((item) => {
            const name = (item || '').toString().trim();
            if (name && !unique.includes(name)) {
                unique.push(name);
            }
        });
    }

    if (unique.length === 0) {
        container.innerHTML = '<div class="text-gray-500">No edited output yet</div>';
        return;
    }

    const html = unique.slice(-5).reverse().map((name) => {
        const safe = escapeHtml(name);
        const href = 'api/stream-github-video.php?automation_id=' + encodeURIComponent(String(automationId)) + '&file=' + encodeURIComponent(name);
        return `<a href="${href}" target="_blank" class="block text-cyan-300 hover:text-cyan-200 truncate" title="${safe}">${safe}</a>`;
    }).join('');

    container.innerHTML = html;
}

function addCardLog(container, message, status) {
    const colors = {
        'success': 'text-green-400',
        'error': 'text-red-400',
        'info': 'text-gray-400'
    };
    const div = document.createElement('div');
    div.className = colors[status] || 'text-gray-400';
    div.textContent = message.substring(0, 80) + (message.length > 80 ? '...' : '');
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function addLogEntry(container, step, status, message) {
    const colors = {
        'success': 'text-green-400',
        'error': 'text-red-400',
        'info': 'text-gray-300',
        'warn': 'text-yellow-400'
    };
    
    const div = document.createElement('div');
    div.className = `py-1 whitespace-pre-wrap break-all overflow-x-hidden ${colors[status] || 'text-gray-300'}`;
    div.textContent = message;
    container.appendChild(div);
    
    // Auto-scroll to bottom
    container.scrollTop = container.scrollHeight;
}

// Track current EventSource globally
let currentEventSource = null;
let processComplete = false;
const activeResumePollers = new Set();

function closeLiveLogModal() {
    document.getElementById('liveLogModal').classList.add('hidden');
    // Process continues in background - no reload needed
}

// Check for running processes on page load
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach ($automations as $auto): ?>
        <?php if (in_array($auto['status'], ['processing', 'running'])): ?>
        // Found running automation - resume polling
        resumePolling(<?= $auto['id'] ?>);
        <?php endif; ?>
    <?php endforeach; ?>
    const sourceShortsMode = document.getElementById('source_shorts_mode');
    if (sourceShortsMode) {
        toggleSourceShortsMode(sourceShortsMode.value || 'single');
    }
    startLiveDebugBanner();
});

function resumePolling(automationId) {
    const pollerKey = String(automationId);
    if (activeResumePollers.has(pollerKey)) return;
    activeResumePollers.add(pollerKey);

    const progressSection = document.getElementById('progress-' + automationId);
    const progressBar = document.getElementById('progress-bar-' + automationId);
    const progressPercent = document.getElementById('progress-percent-' + automationId);
    const progressLog = document.getElementById('progress-log-' + automationId);
    const statFetched = document.getElementById('stat-fetched-' + automationId);
    const statDownloaded = document.getElementById('stat-downloaded-' + automationId);
    const statProcessed = document.getElementById('stat-processed-' + automationId);
    const statScheduled = document.getElementById('stat-scheduled-' + automationId);
    const statPosted = document.getElementById('stat-posted-' + automationId);

    if (!progressSection) {
        activeResumePollers.delete(pollerKey);
        return;
    }

    progressSection.classList.remove('hidden');
    let lastMessage = '';

    function poll() {
        fetch('api/check-progress.php?id=' + automationId)
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    activeResumePollers.delete(pollerKey);
                    return;
                }

                const progress = data.progress || 0;
                progressBar.style.width = progress + '%';
                progressPercent.textContent = progress + '%';

                if (data.data && data.data.stats) {
                    statFetched.textContent = data.data.stats.fetched || 0;
                    statDownloaded.textContent = data.data.stats.downloaded || 0;
                    statProcessed.textContent = data.data.stats.processed || 0;
                    if (statScheduled) statScheduled.textContent = data.data.stats.scheduled || 0;
                    if (statPosted) statPosted.textContent = data.data.stats.posted || 0;
                }
                if (data.data && Array.isArray(data.data.outputs)) {
                    renderCardOutputs(automationId, data.data.outputs);
                }

                if (data.data && data.data.message && data.data.message !== lastMessage) {
                    lastMessage = data.data.message;
                    addCardLog(progressLog, data.data.message, data.data.status);
                }

                if (data.status === 'completed' || data.status === 'error') {
                    setCardStatus(automationId, data.status);
                    addCardLog(progressLog, data.status === 'completed' ? 'Complete!' : 'Error', data.status === 'completed' ? 'success' : 'error');
                    if (data.status === 'completed' && typeof loadOutputVideoCount === 'function') {
                        loadOutputVideoCount();
                    }
                    activeResumePollers.delete(pollerKey);
                    return;
                }

                if (data.status === 'processing' || data.status === 'running') {
                    setCardStatus(automationId, 'processing');
                    setTimeout(poll, 1000);
                } else if (data.status === 'queued') {
                    setCardStatus(automationId, 'queued');
                    setTimeout(poll, 1500);
                } else {
                    activeResumePollers.delete(pollerKey);
                }
            })
            .catch(() => {
                activeResumePollers.delete(pollerKey);
                setTimeout(() => resumePolling(automationId), 2000);
            });
    }

    poll();
}
// ============================================
// LIVE DEBUG BANNER - Always-on top status
// ============================================
function startLiveDebugBanner() {
    const banner = document.getElementById('debug_banner');
    const bannerStatus = document.getElementById('debug_banner_status');
    const bannerBody = document.getElementById('debug_banner_body');
    if (!banner || !bannerBody) return;

    const debugEnabled = window.location.search.indexOf('live_debug=1') !== -1;
    if (!debugEnabled) {
        banner.classList.add('hidden');
        return;
    }
    banner.classList.remove('hidden');
    
    const items = Array.from(document.querySelectorAll('[data-automation-id]')).map(el => ({
        id: el.getAttribute('data-automation-id'),
        name: el.getAttribute('data-automation-name') || 'Automation'
    }));
    
    if (items.length === 0) {
        bannerStatus.textContent = 'No automations';
        bannerBody.innerHTML = '<div>No automations to monitor.</div>';
        return;
    }
    
    let lastSnapshot = '';
    const poll = () => {
        Promise.all(items.map(item =>
            fetch('api/check-progress.php?id=' + item.id + '&with_logs=1')
                .then(r => r.json())
                .then(data => ({ item, data }))
                .catch(() => ({ item, data: null }))
        )).then(results => {
            const lines = [];
            let hasError = false;
            let hasActive = false;
            
            results.forEach(({ item, data }) => {
                if (!data || !data.success) return;
                
                const status = data.status || 'unknown';
                const msg = (data.data && data.data.message) ? data.data.message : '';
                const time = (data.data && data.data.time) ? data.data.time : '';
                
                if (['processing', 'running', 'queued'].includes(status) || msg) {
                    hasActive = true;
                    const statusLabel = status.toUpperCase();
                    lines.push(`[${time || '-'}] ${item.name} :: ${statusLabel} :: ${msg || '...'}`);
                }
                
                if (status === 'error') {
                    hasError = true;
                    lines.push(`[${time || '-'}] ${item.name} :: ERROR :: ${msg || 'Process failed'}`);
                }
            });
            
            const snapshot = lines.join('\n');
            if (snapshot && snapshot === lastSnapshot) return;
            lastSnapshot = snapshot;
            
            if (lines.length === 0) {
                bannerStatus.textContent = 'Idle';
                bannerBody.innerHTML = '<div>No live debug data yet.</div>';
                return;
            }
            
            bannerStatus.textContent = hasError ? 'Errors' : (hasActive ? 'Active' : 'Idle');
            bannerBody.innerHTML = '';
            lines.slice(0, 6).forEach(line => {
                const div = document.createElement('div');
                div.textContent = line;
                bannerBody.appendChild(div);
            });
        });
    };
    
    poll();
    setInterval(poll, 2000);
}

// ============================================
// COUNTDOWN TIMER - Real-time countdown to next automation run
// ============================================
function updateCountdownTimers() {
    const timers = document.querySelectorAll('.countdown-timer');
    const now = Math.floor(Date.now() / 1000);
    
    timers.forEach(timer => {
        const target = parseInt(timer.dataset.target);
        const automationId = timer.dataset.automationId;
        let remaining = target - now;
        
        if (remaining <= 0) {
            // Client timer is overdue; actual execution depends on server-side scheduler.
            timer.innerHTML = '<span class="text-yellow-400 animate-pulse">Overdue - checking scheduler...</span>';

            // Keep checking for next_run_at refresh and recover countdown without page reload.
            const lastCheck = parseInt(timer.dataset.lastCheck || '0', 10);
            if (!lastCheck || (now - lastCheck) >= 10) {
                timer.dataset.lastCheck = String(now);
                fetch('api/check-progress.php?id=' + automationId)
                    .then(r => r.json())
                    .then(data => {
                        const nextTs = parseInt((data && data.nextRunTs) ? data.nextRunTs : '0', 10);
                        if (nextTs && nextTs > now) {
                            timer.dataset.target = String(nextTs);
                            timer.dataset.triggered = '';
                            timer.innerHTML = '<span class="text-green-400">Rescheduled...</span>';
                            return;
                        }

                        if (data && data.status === 'processing') {
                            timer.innerHTML = '<span class="text-blue-400 animate-pulse">Processing...</span>';
                            resumePolling(automationId);
                        } else if (data && data.status === 'queued') {
                            timer.innerHTML = '<span class="text-yellow-400">Queued...</span>';
                            resumePolling(automationId);
                        }
                    })
                    .catch(() => {});
            }

            // Auto-trigger cron check (retry-safe every 60s while overdue)
            const lastTrigger = parseInt(timer.dataset.lastTrigger || '0', 10);
            if (!timer.dataset.triggered || !lastTrigger || (now - lastTrigger) >= 60) {
                timer.dataset.triggered = 'true';
                timer.dataset.lastTrigger = String(now);
                fetch('api/cron.php', { cache: 'no-store' })
                    .then(async (r) => {
                        const text = await r.text();
                        console.log('Cron trigger response:', r.status, text.substring(0, 120));
                    })
                    .catch((err) => {
                        console.warn('Cron trigger failed:', err);
                    });
            }
            return;
        }
        
        // Calculate hours, minutes, seconds
        const hours = Math.floor(remaining / 3600);
        const minutes = Math.floor((remaining % 3600) / 60);
        const seconds = remaining % 60;
        
        // Format with leading zeros
        const pad = (n) => n.toString().padStart(2, '0');
        
        if (hours > 24) {
            const days = Math.floor(hours / 24);
            const remHours = hours % 24;
            timer.innerHTML = `<span class="text-green-400">${days}d ${remHours}h ${pad(minutes)}m</span>`;
        } else if (hours > 0) {
            timer.innerHTML = `<span class="text-green-400">${hours}h ${pad(minutes)}m ${pad(seconds)}s</span>`;
        } else if (minutes > 0) {
            timer.innerHTML = `<span class="text-green-400">${pad(minutes)}m ${pad(seconds)}s</span>`;
        } else {
            timer.innerHTML = `<span class="text-yellow-400 animate-pulse">${pad(seconds)}s</span>`;
        }
    });
}

// Update every second
setInterval(updateCountdownTimers, 1000);
updateCountdownTimers(); // Initial call
</script>

<!-- Live Log Modal -->
<div id="liveLogModal" class="fixed inset-0 bg-black/80 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-900 rounded-xl w-full max-w-3xl mx-4 max-h-[90vh] flex flex-col">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="font-semibold text-lg">🎬 Automation Running</h3>
            <button onclick="closeLiveLogModal()" class="text-gray-400 hover:text-white text-2xl">&times;</button>
        </div>
        
        <!-- Progress Bar -->
        <div class="px-4 py-3 bg-gray-800/50">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm text-gray-400">Progress</span>
                <span id="liveProgressText" class="text-sm font-medium">0%</span>
            </div>
            <div class="h-2 bg-gray-700 rounded-full overflow-hidden">
                <div id="liveProgressBar" class="h-full bg-green-500 transition-all duration-300" style="width: 0%"></div>
            </div>
        </div>
        
        <!-- Log Container -->
        <div id="liveLogContainer" class="flex-1 p-4 overflow-y-auto overflow-x-hidden break-words font-mono text-sm" style="max-height: 400px; min-height: 300px;">
            <div class="text-gray-400">Waiting...</div>
        </div>
        
        <div class="p-4 border-t border-gray-800 flex justify-between">
            <a href="player.php" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg">View Processed Videos</a>
            <button onclick="closeLiveLogModal()" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg">Close</button>
        </div>
    </div>
</div>

<div id="scheduledPostsModal" class="hidden fixed inset-0 bg-black/80 flex items-center justify-center z-50">
    <div class="bg-gray-900 rounded-xl w-full max-w-2xl max-h-[80vh] flex flex-col m-4 border border-gray-700">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between bg-gray-800/50 rounded-t-xl">
            <h3 class="font-semibold text-lg text-white">Scheduled Posts: <span id="scheduledModalTitle" class="text-indigo-400"></span></h3>
            <button onclick="document.getElementById('scheduledPostsModal').classList.add('hidden')" class="text-gray-400 hover:text-white">&times;</button>
        </div>
        <div id="scheduledPostsList" class="flex-1 overflow-y-auto p-4 space-y-3"></div>
        <div class="p-3 border-t border-gray-800 flex items-center justify-between">
            <button onclick="deleteAllScheduledPosts()" class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded text-sm text-white">Delete All Scheduled</button>
            <button onclick="document.getElementById('scheduledPostsModal').classList.add('hidden')" class="px-4 py-2 bg-gray-700 rounded text-sm text-white">Close</button>
        </div>
    </div>
</div>

<script>
let modalInterval = null;
let currentScheduledAutomationId = null;

function notifyToast(message, type) {
    if (typeof showToast === 'function') {
        showToast(message, type || 'info');
        return;
    }
    alert(message);
}

function openScheduledModal(id, name) {
    currentScheduledAutomationId = id;
    document.getElementById('scheduledModalTitle').textContent = name;
    document.getElementById('scheduledPostsModal').classList.remove('hidden');
    document.getElementById('scheduledPostsList').innerHTML = '<div class="text-center text-gray-500">Loading...</div>';
    loadScheduledPosts();
}

function openAllScheduledModal() {
    currentScheduledAutomationId = null;
    document.getElementById('scheduledModalTitle').textContent = 'All Automations';
    document.getElementById('scheduledPostsModal').classList.remove('hidden');
    document.getElementById('scheduledPostsList').innerHTML = '<div class="text-center text-gray-500">Loading...</div>';
    loadScheduledPosts();
}

function loadScheduledPosts() {
    const qs = currentScheduledAutomationId
        ? ('?automation_id=' + encodeURIComponent(currentScheduledAutomationId))
        : '';

    fetch('api/scheduled-posts.php' + qs, { cache: 'no-store' })
        .then(async (r) => {
            const text = await r.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error('Invalid JSON response from scheduled-posts API');
            }
        })
        .then(data => {
            if (!data || data.success === false) {
                document.getElementById('scheduledPostsList').innerHTML = '<div class="text-center text-red-400 py-4">Failed to load scheduled posts: ' + escapeHtml((data && data.error) ? data.error : 'Unknown error') + '</div>';
                return;
            }
            renderModalPosts(Array.isArray(data.posts) ? data.posts : []);
        })
        .catch(() => {
            document.getElementById('scheduledPostsList').innerHTML = '<div class="text-center text-red-400 py-4">Failed to load scheduled posts.</div>';
        });
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderPostCard(post, isUpcoming) {
    const status = String(post.status || '').toLowerCase().trim();
    const statusClass = status === 'scheduled' ? 'bg-indigo-900 text-indigo-300' : 'bg-amber-900 text-amber-300';
    const statusBadge = `<span class="text-[11px] px-2 py-1 rounded ${statusClass}">${escapeHtml(status.toUpperCase())}</span>`;
    const captionText = escapeHtml(post.caption || 'No caption');
    const scheduledTime = post.scheduled_at ? escapeHtml(post.scheduled_at) : 'N/A';
    const countdownHtml = isUpcoming
        ? `<div class="mt-2 flex items-center gap-2 bg-black/30 p-2 rounded"><div class="text-xs text-gray-400">Live Countdown:</div><div class="font-mono font-bold text-green-400 modal-countdown" data-time="${escapeHtml(post.scheduled_at || '')}">Calculating...</div></div>`
        : '';

    let platformsHtml = '';
    if (Array.isArray(post.platforms) && post.platforms.length > 0) {
        platformsHtml = post.platforms.map(platform => {
            const p = escapeHtml(platform.platform || 'unknown');
            const account = escapeHtml(platform.account_name || platform.username || platform.account_id || '');
            return `<span class="text-[11px] bg-gray-700 text-gray-200 px-2 py-1 rounded">${p}: ${account}</span>`;
        }).join('');
    }

    const postLocalId = Number(post.id) || 0;
    const deleteBtn = isUpcoming && postLocalId > 0
        ? `<button onclick="deleteScheduledPost(${postLocalId})" class="text-gray-400 hover:text-red-500 p-2 transition-colors" title="Delete Scheduled Post"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>`
        : '';

    return `<div class="border border-gray-800 rounded-lg p-4 hover:bg-gray-800/30 transition-colors ${isUpcoming ? 'border-l-2 border-l-orange-500' : ''}">
        <div class="flex items-start justify-between gap-4">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap mb-1">
                    ${statusBadge}
                    <span class="text-xs text-gray-600 font-mono">#${escapeHtml(post.post_id || '')}</span>
                </div>
                <p class="text-sm text-gray-300 mt-1">${captionText}</p>
                <div class="flex items-center gap-3 mt-2 flex-wrap">${platformsHtml}</div>
            </div>
            <div class="flex items-center gap-2">
                <div class="text-right flex-shrink-0">
                    <div class="text-xs text-gray-500">${isUpcoming ? 'Scheduled for' : 'Status'}</div>
                    <div class="text-sm text-gray-300 font-mono">${scheduledTime}</div>
                </div>
                ${deleteBtn}
            </div>
        </div>
        ${countdownHtml}
    </div>`;
}

function renderModalPosts(posts) {
    const list = document.getElementById('scheduledPostsList');
    if (!posts || posts.length === 0) {
        list.innerHTML = '<div class="text-center text-gray-500 py-4">No upcoming scheduled posts.</div>';
        return;
    }

    const scoped = posts.filter(post => {
        if (!currentScheduledAutomationId) return true;
        return Number(post.automation_id || 0) === Number(currentScheduledAutomationId);
    });

    const upcoming = scoped.filter(post => {
        const status = String(post.status || '').toLowerCase().trim();
        return status === 'pending' || status === 'scheduled' || status === 'partial' || status === 'queued';
    });

    if (upcoming.length === 0) {
        list.innerHTML = '<div class="text-center text-gray-500 py-4">No upcoming scheduled posts.</div>';
        return;
    }

    list.innerHTML = upcoming.map(post => renderPostCard(post, true)).join('');

    if (modalInterval) clearInterval(modalInterval);
    modalInterval = setInterval(() => {
        document.querySelectorAll('.modal-countdown').forEach(el => {
            let t = (el.dataset.time || '').replace(' ', 'T');
            if (!t) return;
            if (!t.includes('Z') && !t.includes('+')) t += 'Z';

            const diff = new Date(t).getTime() - new Date().getTime();
            if (diff <= 0) {
                el.textContent = 'Publishing Now...';
                el.className = 'text-yellow-400 font-bold modal-countdown';
            } else {
                const h = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                const s = Math.floor((diff % (1000 * 60)) / 1000);
                el.textContent = `${Math.floor(diff / 86400000)}d ${h}h ${m}m ${s}s`;
            }
        });
    }, 1000);
}

function deleteScheduledPost(id) {
    if (!id) return;
    if (!confirm('Are you sure? This will delete the schedule from PostForMe as well.')) return;

    const formData = new FormData();
    formData.append('id', id);

    fetch('api/delete-scheduled-post.php', {
        method: 'POST',
        body: formData
    })
        .then(r => r.json())
        .then(data => {
            if (data && data.ok) {
                notifyToast('Scheduled post deleted successfully', 'success');
                loadScheduledPosts();
            } else {
                notifyToast('Error: ' + ((data && data.error) ? data.error : 'Delete failed'), 'error');
            }
        })
        .catch(() => {
            notifyToast('Network Error', 'error');
        });
}

function deleteAllScheduledPosts() {
    const scopeLabel = currentScheduledAutomationId ? 'this automation' : 'all automations';
    if (!confirm(`Delete/cancel all scheduled posts for ${scopeLabel}?`)) return;

    const formData = new FormData();
    if (currentScheduledAutomationId) {
        formData.append('automation_id', String(currentScheduledAutomationId));
    }

    fetch('api/delete-all-scheduled-posts.php', {
        method: 'POST',
        body: formData
    })
        .then(r => r.json())
        .then(data => {
            if (data && data.ok) {
                notifyToast(`Deleted ${data.deleted || 0} scheduled post(s)`, 'success');
                loadScheduledPosts();
            } else {
                notifyToast('Error: ' + ((data && data.error) ? data.error : 'Bulk delete failed'), 'error');
            }
        })
        .catch(() => {
            notifyToast('Network Error', 'error');
        });
}
</script>

<?php include 'includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var scheduledEls = document.querySelectorAll('[id^="stat-scheduled-"]');
  if (!scheduledEls.length) return;

  scheduledEls.forEach(function (scheduledEl) {
    var automationId = (scheduledEl.id || '').replace('stat-scheduled-', '');
    if (!automationId) return;

    var url = 'api/sync-postforme-status.php?automation_id=' + encodeURIComponent(automationId);
    fetch(url, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.counts) return;

        var trackedTotal = Number(data.counts.tracked_total ?? 0);
        var nextScheduled = Number(data.counts.scheduled ?? 0);
        var nextPosted = Number(data.counts.posted ?? 0);
        var scheduledSource = String(data.counts.scheduled_source || 'db');
        var apiOk = Boolean(data.counts.api_ok);

        var scheduledNode = document.getElementById('stat-scheduled-' + automationId);
        var currentScheduled = scheduledNode ? Number(scheduledNode.textContent || 0) : 0;
        var postedNode = document.getElementById('stat-posted-' + automationId);
        var currentPosted = postedNode ? Number(postedNode.textContent || 0) : 0;

        // If live PostForMe API is unavailable, keep current values.
        if (!apiOk) {
          return;
        }

        // Avoid flicker-to-zero when DB tracking has not been populated yet.
        if (scheduledSource === 'db' && trackedTotal === 0 && currentScheduled > 0 && nextScheduled === 0 && nextPosted <= currentPosted) {
          return;
        }

        if (scheduledNode) scheduledNode.textContent = String(nextScheduled);

        if (postedNode) postedNode.textContent = String(nextPosted);
      })
      .catch(function (e) { console.error(e); });
  });
});
</script>
