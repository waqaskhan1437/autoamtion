<?php
/**
 * Synchronous Automation Runner
 * Runs in foreground with real-time progress updates via output buffering
 * More reliable on Windows XAMPP than background processes
 */
set_time_limit(3600);
ignore_user_abort(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Flush output immediately
ob_implicit_flush(true);
while (ob_get_level()) ob_end_clean();

// Global PDO reference for updateDatabase
$globalPdo = null;
$globalAutomationId = null;

function sendProgress($step, $status, $message, $percent = 0, $stats = []) {
    global $globalPdo, $globalAutomationId;
    
    $data = json_encode([
        'step' => $step,
        'status' => $status,
        'message' => $message,
        'progress' => $percent,
        'stats' => $stats,
        'time' => date('H:i:s')
    ]);
    echo "data: {$data}\n\n";
    @ob_flush();
    @flush();
    
    // Also update database for fallback polling
    if ($globalPdo && $globalAutomationId) {
        try {
            $stmt = $globalPdo->prepare("UPDATE automation_settings SET progress_percent = ?, progress_data = ?, last_progress_time = NOW() WHERE id = ?");
            $stmt->execute([$percent, $data, $globalAutomationId]);
        } catch (Exception $e) {}
    }
}

function sendPing() {
    echo ": ping\n\n";
    @ob_flush();
    @flush();
}

function sendDone($success, $message, $stats = []) {
    $data = json_encode([
        'done' => true,
        'success' => $success,
        'message' => $message,
        'stats' => $stats
    ]);
    echo "data: {$data}\n\n";
    flush();
    exit;
}

/**
 * Compute schedule date for PostForMe API based on automation settings
 * Returns ISO 8601 UTC datetime string or null for immediate posting
 */
function computeScheduleDateForSync($automation, $postIndex = 0) {
    $mode = $automation['postforme_schedule_mode'] ?? 'immediate';
    
    if ($mode === 'immediate') {
        return null;
    }
    
    if ($mode === 'scheduled') {
        $datetime = $automation['postforme_schedule_datetime'] ?? null;
        if (empty($datetime)) {
            return null;
        }
        
        $timezone = $automation['postforme_schedule_timezone'] ?? 'UTC';
        
        try {
            $userTz = new DateTimeZone($timezone);
            $utcTz = new DateTimeZone('UTC');
            
            $dt = new DateTime($datetime, $userTz);
            $dt->setTimezone($utcTz);
            
            $now = new DateTime('now', $utcTz);
            if ($dt <= $now) {
                return null;
            }
            
            $spreadMinutes = intval($automation['postforme_schedule_spread_minutes'] ?? 0);
            if ($spreadMinutes > 0 && $postIndex > 0) {
                $totalSpread = $postIndex * $spreadMinutes;
                $dt->modify("+{$totalSpread} minutes");
            }
            
            return $dt->format('Y-m-d\TH:i:s\Z');
        } catch (Exception $e) {
            error_log("Schedule date computation failed: " . $e->getMessage());
            return null;
        }
    }
    
    if ($mode === 'offset') {
        $offsetMinutes = intval($automation['postforme_schedule_offset_minutes'] ?? 0);
        if ($offsetMinutes <= 0) {
            return null;
        }
        
        $spreadMinutes = intval($automation['postforme_schedule_spread_minutes'] ?? 0);
        if ($spreadMinutes > 0) {
            $offsetMinutes += ($postIndex * $spreadMinutes);
        }
        
        try {
            $dt = new DateTime('now', new DateTimeZone('UTC'));
            $dt->modify("+{$offsetMinutes} minutes");
            
            return $dt->format('Y-m-d\TH:i:s\Z');
        } catch (Exception $e) {
            error_log("Offset schedule computation failed: " . $e->getMessage());
            return null;
        }
    }
    
    return null;
}

$automationId = $_GET['id'] ?? null;

if (!$automationId) {
    sendDone(false, 'No automation ID provided');
}

sendProgress('init', 'info', 'Loading configuration...', 5);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/FTPAPI.php';
    require_once __DIR__ . '/../includes/FFmpegProcessor.php';
    require_once __DIR__ . '/../includes/AITaglineGenerator.php';
    require_once __DIR__ . '/../includes/PostForMeAPI.php';
    
    // Set global references for database updates
    $globalPdo = $pdo;
    $globalAutomationId = $automationId;
} catch (Exception $e) {
    sendDone(false, 'Failed to load: ' . $e->getMessage());
}

// Get automation details
try {
    // Try with FTP columns first
    $stmt = $pdo->prepare("SELECT a.*, k.api_key, k.library_id, k.storage_zone, k.ftp_host, k.ftp_username, k.ftp_password 
                           FROM automation_settings a 
                           LEFT JOIN api_keys k ON a.api_key_id = k.id 
                           WHERE a.id = ?");
    $stmt->execute([$automationId]);
    $automation = $stmt->fetch();
} catch (Exception $e) {
    // Fallback without FTP columns (for older databases)
    try {
        $stmt = $pdo->prepare("SELECT a.*, k.api_key, k.library_id, k.storage_zone
                               FROM automation_settings a 
                               LEFT JOIN api_keys k ON a.api_key_id = k.id 
                               WHERE a.id = ?");
        $stmt->execute([$automationId]);
        $automation = $stmt->fetch();
        if ($automation) {
            $automation['ftp_host'] = null;
            $automation['ftp_username'] = null;
            $automation['ftp_password'] = null;
        }
    } catch (Exception $e2) {
        sendDone(false, 'Database error: ' . $e2->getMessage());
    }
}

if (!$automation) {
    sendDone(false, 'Automation not found');
}

// API key is optional if FTP is configured in Settings
$useFtpSettings = false;
if (!$automation['api_key_id']) {
    // Check if FTP is configured in global settings
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ftp_host'");
    $ftpHost = $stmt->fetchColumn();
    if (!$ftpHost) {
        sendDone(false, 'No API key selected and FTP not configured in Settings! Please either select an API key or configure FTP in Settings → FTP Server.');
    }
    $useFtpSettings = true;
}

// Update status to processing
$pdo->prepare("UPDATE automation_settings SET status = 'processing', progress_percent = 0 WHERE id = ?")->execute([$automationId]);

$stats = ['fetched' => 0, 'downloaded' => 0, 'processed' => 0, 'scheduled' => 0, 'posted' => 0, 'errors' => 0];
$videosPerRun = intval($automation['videos_per_run'] ?? 5);
if ($videosPerRun < 1) $videosPerRun = 1;
if ($videosPerRun > 500) $videosPerRun = 500;

// =====================================================
// POST FOR ME CONFIGURATION CHECK
// =====================================================
$pfEnabled = !empty($automation['postforme_enabled']) && $automation['postforme_enabled'] !== '0';
$pfAccountIds = $automation['postforme_account_ids'] ?? '[]';
$pfAccounts = [];

if (!empty($pfAccountIds) && $pfAccountIds !== '[]') {
    $decoded = @json_decode($pfAccountIds, true);
    if (is_array($decoded)) {
        $pfAccounts = array_filter($decoded);
    }
}

// Get Post for Me API Key
$postformeApiKey = '';
try {
    $apiKeyStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key'");
    $apiKeyStmt->execute();
    $postformeApiKey = $apiKeyStmt->fetchColumn() ?: '';
} catch (Exception $e) {}

$willPost = $pfEnabled && !empty($pfAccounts) && !empty($postformeApiKey);

sendProgress('init', 'success', "Starting: {$automation['name']}", 10, $stats);

// Show Post for Me status
if ($pfEnabled) {
    if ($willPost) {
        sendProgress('postforme', 'success', "✓ Post for Me: ENABLED (" . count($pfAccounts) . " account(s))", 11, $stats);
    } else {
        $missing = [];
        if (empty($pfAccounts)) $missing[] = 'no accounts selected';
        if (empty($postformeApiKey)) $missing[] = 'API key not set';
        sendProgress('postforme', 'warning', "⚠ Post for Me enabled but: " . implode(', ', $missing), 11, $stats);
    }
} else {
    sendProgress('postforme', 'info', "○ Post for Me: Not enabled for this automation", 11, $stats);
}

// Check FFmpeg
sendProgress('ffmpeg', 'info', 'Checking FFmpeg...', 15, $stats);
$ffmpeg = new FFmpegProcessor();
if (!$ffmpeg->isAvailable()) {
    $pdo->prepare("UPDATE automation_settings SET status = 'error' WHERE id = ?")->execute([$automationId]);
    sendDone(false, 'FFmpeg not installed! Go to Settings page to configure.');
}
sendProgress('ffmpeg', 'success', 'FFmpeg OK', 20, $stats);

// Fetch videos
sendProgress('fetch', 'info', 'Connecting to Bunny CDN storage...', 25, $stats);

try {
    // Use FTP from Settings or from API key
    if ($useFtpSettings) {
        sendProgress('fetch', 'info', 'Using FTP settings from Settings page...', 26, $stats);
        $ftp = FTPAPI::fromSettings($pdo);
    } else {
        // Try global FTP settings first if available
        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ftp_host'");
        $globalFtpHost = $stmt->fetchColumn();
        
        if ($globalFtpHost) {
            // Use global FTP settings (more reliable)
            sendProgress('fetch', 'info', 'Using FTP from Settings (recommended)...', 26, $stats);
            $ftp = FTPAPI::fromSettings($pdo);
        } else {
            // Fallback to API key credentials
            sendProgress('fetch', 'info', 'Using API key credentials...', 26, $stats);
            // For Bunny CDN: username = storage zone name, password = access key
            $ftpUsername = $automation['storage_zone'] ?? '';
            $ftpPassword = $automation['api_key'] ?? '';
            
            $ftp = new FTPAPI(
                'storage.bunnycdn.com',
                $ftpUsername,
                $ftpPassword,
                21,
                '/',
                true
            );
        }
    }
    
    // Determine whether to use date range or "last X days"
    $daysFilter = intval($automation['video_days_filter'] ?? 30);
    if ($daysFilter < 1) $daysFilter = 30;

    $startValue = $automation['video_start_date'] ?? null;
    $endValue = $automation['video_end_date'] ?? null;

    $startStr = is_string($startValue) ? trim($startValue) : (is_null($startValue) ? '' : trim((string)$startValue));
    $endStr = is_string($endValue) ? trim($endValue) : (is_null($endValue) ? '' : trim((string)$endValue));

    $hasStart = ($startStr !== '' && strtolower($startStr) !== 'null' && $startStr !== '0000-00-00');
    $hasEnd = ($endStr !== '' && strtolower($endStr) !== 'null' && $endStr !== '0000-00-00');

    $startTs = $hasStart ? strtotime($startStr) : false;
    $endTs = $hasEnd ? strtotime($endStr) : false;

    $usingDateRange = ($startTs !== false && $endTs !== false && $startTs <= $endTs);

    $filterLabel = $usingDateRange
        ? "Date range {$startStr} to {$endStr}"
        : "Last {$daysFilter} days";

    if (!$usingDateRange && ($hasStart || $hasEnd)) {
        // Explain why date range is not being used (helps debugging)
        if ($startTs === false || $endTs === false) {
            $filterLabel .= " (invalid date format)";
        } elseif ($startTs > $endTs) {
            $filterLabel .= " (end date before start date)";
        } else {
            $filterLabel .= " (incomplete date range)";
        }
    }

    sendProgress('fetch', 'info', "Filter: {$filterLabel} (limit {$videosPerRun}/run)", 27, $stats);
    sendProgress('fetch', 'info', 'Fetching video list (this may take a moment)...', 28, $stats);

    $videos = $usingDateRange
        ? $ftp->getVideosByDateRange($startStr, $endStr)
        : $ftp->getVideos($daysFilter);
    $stats['fetched'] = count($videos);
    
    if ($stats['fetched'] === 0) {
        $pdo->prepare("UPDATE automation_settings SET status = 'completed', progress_percent = 100 WHERE id = ?")->execute([$automationId]);
        $emptyMsg = $usingDateRange
            ? "No videos found between {$startStr} and {$endStr}"
            : "No videos found in the last {$daysFilter} days";
        sendDone(true, $emptyMsg, $stats);
    }
    
    sendProgress('fetch', 'success', "Found {$stats['fetched']} videos (will process up to {$videosPerRun} this run)", 30, $stats);
} catch (Exception $e) {
    $pdo->prepare("UPDATE automation_settings SET status = 'error' WHERE id = ?")->execute([$automationId]);
    sendDone(false, 'FTP/API Error: ' . $e->getMessage());
}

// =====================================================
// VIDEO ROTATION FILTER
// =====================================================
$rotationEnabled = !empty($automation['rotation_enabled']);
$shuffleEnabled = false;
$getRotationIdentifierCandidates = function($video) {
    $ids = [];

    if (is_array($video)) {
        $candidates = [
            $video['guid'] ?? null,
            $video['ObjectName'] ?? null,
            $video['remotePath'] ?? null,
            $video['path'] ?? null,
            $video['filename'] ?? null,
            $video['name'] ?? null
        ];
        foreach ($candidates as $value) {
            if ($value === null) continue;
            $raw = trim((string)$value);
            if ($raw === '') continue;
            $ids[$raw] = true;
            $ids[strtolower($raw)] = true;
        }
    } else {
        $raw = trim((string)basename($video));
        if ($raw !== '') {
            $ids[$raw] = true;
            $ids[strtolower($raw)] = true;
        }
    }

    if (empty($ids)) {
        $fallback = is_array($video)
            ? ($video['guid'] ?? $video['ObjectName'] ?? $video['filename'] ?? $video['name'] ?? md5(json_encode($video)))
            : basename($video);
        $fallback = trim((string)$fallback);
        if ($fallback !== '') {
            $ids[$fallback] = true;
            $ids[strtolower($fallback)] = true;
        }
    }

    return array_keys($ids);
};
$getRotationFingerprint = function($video) {
    $size = is_array($video) ? intval($video['Length'] ?? $video['size'] ?? $video['ContentLength'] ?? 0) : 0;
    $idBase = is_array($video)
        ? (string)($video['remotePath'] ?? $video['path'] ?? $video['ObjectName'] ?? $video['filename'] ?? $video['name'] ?? $video['guid'] ?? '')
        : (string)basename($video);

    $idBase = strtolower(trim($idBase));
    if ($idBase === '' && $size <= 0) return '';
    return hash('sha1', $idBase . '|' . (string)$size);
};
if ($rotationEnabled) {
    $cycleNumber = intval($automation['rotation_cycle'] ?? 1);
    $autoReset = !empty($automation['rotation_auto_reset']);
    $shuffleEnabled = !empty($automation['rotation_shuffle']);
    
    sendProgress('rotation', 'info', "Rotation: Checking cycle {$cycleNumber}...", 31, $stats);
    
    // Get already-processed video identifiers for current cycle
    $processedIds = [];
    $processedHashes = [];
    $processedSizes = [];
    try {
        $rstmt = $pdo->prepare("SELECT video_identifier, file_size, content_hash FROM processed_videos WHERE automation_id = ? AND cycle_number = ?");
        $rstmt->execute([$automationId, $cycleNumber]);
        while ($row = $rstmt->fetch()) {
            $rawId = trim((string)($row['video_identifier'] ?? ''));
            if ($rawId !== '') {
                $processedIds[$rawId] = true;
                $processedIds[strtolower($rawId)] = true;
            }
            $rawHash = trim((string)($row['content_hash'] ?? ''));
            if ($rawHash !== '') {
                $processedHashes[$rawHash] = true;
            }
            if ($row['file_size'] > 0) {
                $processedSizes[(string)intval($row['file_size'])] = true;
            }
        }
    } catch (Exception $e) {}
    
    $totalBeforeFilter = count($videos);
    $remaining = [];
    foreach ($videos as $video) {
        $videoIds = $getRotationIdentifierCandidates($video);
        $videoId = $videoIds[0] ?? (is_array($video) ? ($video['guid'] ?? $video['ObjectName'] ?? $video['filename'] ?? $video['name'] ?? md5(json_encode($video))) : basename($video));
        $fileSize = is_array($video) ? intval($video['Length'] ?? $video['size'] ?? $video['ContentLength'] ?? 0) : 0;
        $videoHash = $getRotationFingerprint($video);
        
        // Skip if identifier already processed
        $matchedById = false;
        foreach ($videoIds as $candidate) {
            if (isset($processedIds[$candidate])) {
                $matchedById = true;
                break;
            }
        }
        if ($matchedById) {
            continue;
        }

        // Skip if fingerprint already processed (cross-source id changes)
        if ($videoHash !== '' && isset($processedHashes[$videoHash])) {
            continue;
        }
        
        // Skip if same file size (duplicate detection)
        if ($fileSize > 0 && isset($processedSizes[(string)$fileSize])) {
            sendProgress('rotation', 'info', "Skipping duplicate (same size): " . (is_array($video) ? ($video['filename'] ?? $videoId) : $videoId), 31, $stats);
            continue;
        }
        
        $remaining[] = $video;
    }
    
    // Auto-reset: if all videos used, start new cycle
    if (empty($remaining) && $autoReset && $totalBeforeFilter > 0) {
        $newCycle = $cycleNumber + 1;
        sendProgress('rotation', 'success', "All videos used in cycle {$cycleNumber}! Starting cycle {$newCycle}", 32, $stats);
        $pdo->prepare("UPDATE automation_settings SET rotation_cycle = ? WHERE id = ?")->execute([$newCycle, $automationId]);
        $cycleNumber = $newCycle;
        $automation['rotation_cycle'] = $newCycle;
        $remaining = $videos;
    } elseif (empty($remaining)) {
        $pdo->prepare("UPDATE automation_settings SET status = 'completed', progress_percent = 100 WHERE id = ?")->execute([$automationId]);
        sendDone(true, "All {$totalBeforeFilter} videos already processed in cycle {$cycleNumber}. Enable auto-reset to start new cycle.", $stats);
    }
    
    $videos = $remaining;
    $stats['fetched'] = count($videos);
    sendProgress('rotation', 'success', "Rotation: {$totalBeforeFilter} total, {$stats['fetched']} remaining in cycle {$cycleNumber}", 33, $stats);
}

$queueCount = count($videos);
if ($queueCount > 1) {
    usort($videos, function($a, $b) {
        $ra = is_array($a) ? ($a['dateUploaded'] ?? $a['DateCreated'] ?? $a['dateCreated'] ?? null) : null;
        $rb = is_array($b) ? ($b['dateUploaded'] ?? $b['DateCreated'] ?? $b['dateCreated'] ?? null) : null;
        
        $ta = !empty($ra) ? strtotime($ra) : false;
        $tb = !empty($rb) ? strtotime($rb) : false;
        
        if ($ta === false) $ta = PHP_INT_MAX;
        if ($tb === false) $tb = PHP_INT_MAX;
        
        if ($ta === $tb) {
            $ka = is_array($a) ? ($a['filename'] ?? $a['ObjectName'] ?? $a['name'] ?? $a['title'] ?? $a['guid'] ?? '') : (string)$a;
            $kb = is_array($b) ? ($b['filename'] ?? $b['ObjectName'] ?? $b['name'] ?? $b['title'] ?? $b['guid'] ?? '') : (string)$b;
            return strcmp((string)$ka, (string)$kb);
        }
        
        return ($ta < $tb) ? -1 : 1;
    });
}

if ($queueCount > $videosPerRun) {
    $videos = array_slice($videos, 0, $videosPerRun);
}

sendProgress('rotation', 'info', "Batch: processing " . count($videos) . " of {$queueCount} (oldest first)", 34, $stats);

if ($shuffleEnabled && count($videos) > 1) {
    shuffle($videos); // Shuffle only within the selected batch
    sendProgress('rotation', 'info', 'Batch shuffled for random order', 35, $stats);
}

// Set up directories (honor configurable BASE_DATA_DIR from config.php when available)
if (defined('BASE_DATA_DIR') && BASE_DATA_DIR) {
    $baseDir = BASE_DATA_DIR;
} else {
    $baseDir = (PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : getenv('HOME') . '/VideoWorkflow';
}
$tempDir = $baseDir . '/temp';
$outputDir = $baseDir . '/output';

if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);
if (!is_dir($outputDir)) @mkdir($outputDir, 0777, true);

// Process each video
$totalVideos = count($videos);
$progressPerVideo = 60 / max($totalVideos, 1); // 60% of progress for video processing

foreach ($videos as $index => $video) {
    // Check if stopped
    $stmt = $pdo->prepare("SELECT status FROM automation_settings WHERE id = ?");
    $stmt->execute([$automationId]);
    $check = $stmt->fetch();
    if ($check && in_array($check['status'], ['stopped', 'inactive'])) {
        sendDone(false, 'Process stopped by user', $stats);
    }
    
    $videoName = is_array($video) ? ($video['filename'] ?? $video['ObjectName'] ?? $video['name'] ?? $video['title'] ?? 'unknown') : basename($video);
    $currentProgress = 30 + ($index * $progressPerVideo);
    
    // Check if already downloaded - skip download only, but always process
    $localPath = $tempDir . '/' . $videoName;
    $outputPath = $outputDir . '/short_' . pathinfo($videoName, PATHINFO_FILENAME) . '_' . time() . '.mp4';
    
    try {
    
    if (file_exists($localPath) && filesize($localPath) > 0) {
        // Video already downloaded - skip download but still process
        sendProgress('skip', 'info', "Using cached file: $videoName", $currentProgress, $stats);
        $stats['downloaded']++;
    } else {
        sendProgress('download', 'info', "Downloading: $videoName (" . ($index + 1) . "/$totalVideos)", $currentProgress, $stats);
        
        $remotePath = is_array($video) ? ($video['remotePath'] ?? $video['filename'] ?? $videoName) : $video;
        
        // Send ping before download to keep connection alive
        sendPing();
        
        // Get file size if available
        $fileSize = is_array($video) ? ($video['size'] ?? 0) : 0;
        if ($fileSize > 0) {
            $sizeMB = round($fileSize / 1024 / 1024, 1);
            sendProgress('download', 'info', "Downloading: $videoName ({$sizeMB}MB)...", $currentProgress, $stats);
        }
        
        $downloadResult = $ftp->downloadVideo($remotePath, $localPath);
        
        // Send ping after download
        sendPing();
        
        if (!$downloadResult || !file_exists($localPath)) {
            $stats['errors']++;
            sendProgress('download', 'warning', "Failed to download: $videoName", $currentProgress, $stats);
            continue;
        }
        
        $stats['downloaded']++;
        $downloadedSize = round(filesize($localPath) / 1024 / 1024, 1);
        sendProgress('download', 'success', "Downloaded: $videoName ({$downloadedSize}MB)", $currentProgress + ($progressPerVideo * 0.3), $stats);
    }
        
    // Process video with FFmpeg
    sendProgress('process', 'info', "Processing: $videoName", $currentProgress + ($progressPerVideo * 0.5), $stats);
    
    // Determine overlay text - AI generated or static
    $topText = $automation['branding_text_top'] ?? '';
    $bottomText = $automation['branding_text_bottom'] ?? '';
    $emojiPng = null; // Emoji PNG path for colorful overlay
    
    // Check if AI taglines are enabled
    if (!empty($automation['ai_taglines_enabled'])) {
        sendProgress('ai', 'info', "Generating unique tagline for: $videoName", $currentProgress + ($progressPerVideo * 0.4), $stats);
        
        $prompt = $automation['ai_tagline_prompt'] ?? 'Generate catchy viral taglines';
        $videoTitle = pathinfo($videoName, PATHINFO_FILENAME);
        
        $taglineGenerated = false;
        
        // Try AI first (Gemini/OpenAI)
        try {
            require_once __DIR__ . '/../includes/AITaglineGenerator.php';
            $aiGenerator = new AITaglineGenerator($pdo);
            
            // Get previously used taglines to avoid repetition
            $previousTaglines = [];
            try {
                $prevStmt = $pdo->prepare("SELECT message FROM automation_logs WHERE automation_id = ? AND action = 'ai_tagline' ORDER BY created_at DESC LIMIT 20");
                $prevStmt->execute([$automationId]);
                $previousTaglines = array_column($prevStmt->fetchAll(), 'message');
            } catch (Exception $e) {}
            
            // Generate unique taglines for this video
            $aiResult = $aiGenerator->generateTaglines($prompt, $videoTitle, $previousTaglines);
            
            if (!empty($aiResult['success']) && !empty($aiResult['top'])) {
                $topText = $aiResult['top'];
                $bottomText = $aiResult['bottom'];
                $taglineGenerated = true;
                
                sendProgress('ai', 'success', "AI: \"{$topText}\" | \"{$bottomText}\"", $currentProgress + ($progressPerVideo * 0.45), $stats);
                
                // Log AI taglines
                try {
                    $logStmt = $pdo->prepare("INSERT INTO automation_logs (automation_id, action, status, message, video_id) VALUES (?, 'ai_tagline', 'success', ?, ?)");
                    $logStmt->execute([$automationId, "Top: {$topText} | Bottom: {$bottomText}", $videoName]);
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            sendProgress('ai', 'warning', "AI error: " . $e->getMessage(), $currentProgress + ($progressPerVideo * 0.42), $stats);
        }
        
        // Fallback: Use LOCAL generator if AI failed (NO API LIMIT!)
        if (!$taglineGenerated) {
            sendProgress('ai', 'info', "Using local generator (no API limit)...", $currentProgress + ($progressPerVideo * 0.43), $stats);
            
            try {
                require_once __DIR__ . '/../includes/LocalTaglineGenerator.php';
                // No random words - use universal taglines only
                $localGen = new LocalTaglineGenerator();
                $localResult = $localGen->generate();
                
                $topText = $localResult['top'];
                $bottomText = $localResult['bottom'];
                $emojiPng = $localResult['emojiPng'] ?? null;
                $emoji = $localResult['emoji'] ?? '';
                $taglineGenerated = true;
                
                $emojiStatus = $emojiPng ? "Emoji: {$emoji}" : "No emoji PNG";
                sendProgress('ai', 'success', "Local: \"{$topText}\" | {$emojiStatus}", $currentProgress + ($progressPerVideo * 0.45), $stats);
                
                // Log local taglines
                try {
                    $logStmt = $pdo->prepare("INSERT INTO automation_logs (automation_id, action, status, message, video_id) VALUES (?, 'local_tagline', 'success', ?, ?)");
                    $logStmt->execute([$automationId, "Top: {$topText} | Bottom: {$bottomText}", $videoName]);
                } catch (Exception $e) {}
            } catch (Exception $e) {
                sendProgress('ai', 'warning', "Local gen failed: " . $e->getMessage(), $currentProgress + ($progressPerVideo * 0.45), $stats);
            }
        }
    }
    
    $processResult = $ffmpeg->createShort(
        $localPath,
        $outputPath,
        [
            'duration' => $automation['short_duration'] ?? 60,
            'aspectRatio' => $automation['short_aspect_ratio'] ?? '9:16',
            'topText' => $topText,
            'bottomText' => $bottomText,
            'emoji' => $emoji ?? '',  // Emoji character to add at end of text
            'emojiPng' => $emojiPng   // Colorful emoji PNG overlay (fallback)
        ]
    );
    
    // Check if processing was actually successful (result is array with success key)
    $processSuccess = is_array($processResult) ? ($processResult['success'] ?? false) : (bool)$processResult;
    
    if ($processSuccess && file_exists($outputPath)) {
        $stats['processed']++;
        $outputSize = round(filesize($outputPath) / 1024 / 1024, 1);
        sendProgress('process', 'success', "Created: " . basename($outputPath) . " ({$outputSize}MB)", $currentProgress + $progressPerVideo, $stats);
        
        // Mark video as processed in rotation tracker
        if ($rotationEnabled) {
            $rotCycle = intval($automation['rotation_cycle'] ?? 1);
            $rotVideoIds = $getRotationIdentifierCandidates($video);
            $rotVideoId = $rotVideoIds[0] ?? (is_array($video) ? ($video['guid'] ?? $video['ObjectName'] ?? $video['filename'] ?? $video['name'] ?? md5(json_encode($video))) : basename($video));
            $rotFileSize = is_array($video) ? intval($video['Length'] ?? $video['size'] ?? $video['ContentLength'] ?? 0) : 0;
            $rotHash = $getRotationFingerprint($video);
            try {
                $rotStmt = $pdo->prepare("INSERT INTO processed_videos (automation_id, video_identifier, video_filename, file_size, cycle_number, processed_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE processed_at = NOW()");
                foreach ($rotVideoIds as $candidateId) {
                    $rotStmt->execute([$automationId, $candidateId, $videoName, $rotFileSize, $rotCycle]);
                }
                if ($rotHash !== '') {
                    $rotHashStmt = $pdo->prepare("UPDATE processed_videos SET content_hash = ? WHERE automation_id = ? AND cycle_number = ? AND video_identifier = ?");
                    $rotHashStmt->execute([$rotHash, $automationId, $rotCycle, $rotVideoId]);
                }
            } catch (Exception $e) {}
        }
        
        // Log to database with output path
        try {
            $stmt = $pdo->prepare("INSERT INTO automation_logs (automation_id, action, status, message, video_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$automationId, 'video_processed', 'success', "Output: " . basename($outputPath), $videoName]);
        } catch (Exception $e) {}
        
        // =====================================================
        // POST TO SOCIAL MEDIA VIA POST FOR ME
        // =====================================================
        if ($willPost) {
            sendProgress('posting', 'info', "🚀 Posting to social media...", $currentProgress + $progressPerVideo + 1, $stats);
            
            try {
                $postForMe = new PostForMeAPI($postformeApiKey);
                
                // Generate AI-powered social content (title, description, hashtags, tags)
                sendProgress('posting', 'info', "Generating social content...", $currentProgress + $progressPerVideo + 1.5, $stats);
                
                $aiPrompt = $automation['ai_tagline_prompt'] ?? 'Create engaging social media content';
                $socialContent = [];
                
                try {
                    require_once __DIR__ . '/../includes/AITaglineGenerator.php';
                    $aiGen = new AITaglineGenerator($pdo);
                    $socialContent = $aiGen->generateSocialContent($aiPrompt, $videoName, $topText);
                } catch (Exception $e) {
                    // Use defaults
                    $socialContent = [
                        'title' => $topText ?: $videoName,
                        'description' => ($topText ?: 'Check this out!') . ' #shorts #viral #trending',
                        'hashtags' => ['#shorts', '#viral', '#trending', '#fyp'],
                        'tags' => ['shorts', 'viral', 'trending']
                    ];
                }
                
                // Build caption with hashtags
                $hashtagStr = implode(' ', $socialContent['hashtags'] ?? []);
                $caption = ($socialContent['description'] ?? $topText) . "\n\n" . $hashtagStr;
                
                sendProgress('posting', 'info', "Title: " . ($socialContent['title'] ?? 'N/A'), $currentProgress + $progressPerVideo + 2, $stats);
                sendProgress('posting', 'info', "Hashtags: " . $hashtagStr, $currentProgress + $progressPerVideo + 2.5, $stats);
                sendProgress('posting', 'info', "Uploading to " . count($pfAccounts) . " account(s)...", $currentProgress + $progressPerVideo + 3, $stats);
                
                // Platform-specific overrides for ALL platforms
                $fullDescription = ($socialContent['description'] ?? $topText) . "\n\n" . $hashtagStr;
                $shortCaption = substr($caption, 0, 280); // For platforms with char limits
                $youtubeTitle = $socialContent['title'] ?? ($topText ?: $videoName);
                $youtubeTags = $socialContent['tags'] ?? ['shorts', 'viral', 'trending'];
                
                $platformOverrides = [
                    // YouTube Shorts - Title, Description, Tags
                    'youtube' => [
                        'title' => substr($youtubeTitle, 0, 100),
                        'description' => $fullDescription,
                        'tags' => $youtubeTags,
                        'privacy' => 'public',
                        'shorts' => true
                    ],
                    // TikTok - Caption with hashtags
                    'tiktok' => [
                        'caption' => $caption,
                        'allow_comments' => true,
                        'allow_duet' => true,
                        'allow_stitch' => true
                    ],
                    // Instagram Reels - Caption with hashtags
                    'instagram' => [
                        'caption' => $caption,
                        'share_to_feed' => true
                    ],
                    // Facebook Reels - Caption with hashtags
                    'facebook' => [
                        'caption' => $caption,
                        'description' => $fullDescription
                    ],
                    // X/Twitter - Short caption (280 char limit)
                    'twitter' => [
                        'caption' => $shortCaption
                    ],
                    // Threads - Caption with hashtags
                    'threads' => [
                        'caption' => $caption
                    ],
                    // LinkedIn - Professional caption
                    'linkedin' => [
                        'caption' => $caption,
                        'title' => $youtubeTitle
                    ],
                    // Pinterest - Pin with description
                    'pinterest' => [
                        'title' => $youtubeTitle,
                        'description' => $fullDescription,
                        'link' => ''
                    ],
                    // Bluesky - Short caption
                    'bluesky' => [
                        'caption' => $shortCaption
                    ]
                ];
                
                sendProgress('posting', 'info', "Platforms: YouTube, TikTok, Instagram, FB, X, Threads, LinkedIn, Pinterest", $currentProgress + $progressPerVideo + 2.7, $stats);
                
                $postOptions = [
                    'platform_overrides' => $platformOverrides
                ];
                
                $scheduledAt = computeScheduleDateForSync($automation, $index);
                if ($scheduledAt) {
                    $postOptions['scheduled_at'] = $scheduledAt;
                    sendProgress('posting', 'info', "Scheduled for: {$scheduledAt}", $currentProgress + $progressPerVideo + 2.8, $stats);
                }
                
                $postResult = $postForMe->postVideo($outputPath, $caption, $pfAccounts, $postOptions);
                
                if ($postResult['success']) {
                    $postId = $postResult['post_id'] ?? 'unknown';
                    $dbScheduledAt = null;
                    if (!empty($scheduledAt)) {
                        $ts = strtotime((string)$scheduledAt);
                        if ($ts !== false) {
                            $dbScheduledAt = gmdate('Y-m-d H:i:s', $ts);
                        }
                    }

                    // Persist post locally so dashboard counts survive refresh.
                    try {
                        $existingStmt = $pdo->prepare("SELECT id FROM postforme_posts WHERE post_id = ? LIMIT 1");
                        $existingStmt->execute([$postId]);
                        $existingPostId = $existingStmt->fetchColumn();

                        $localStatus = $dbScheduledAt ? 'scheduled' : 'pending';
                        $accountsJson = json_encode(array_values($pfAccounts));

                        if ($existingPostId) {
                            $upStmt = $pdo->prepare("
                                UPDATE postforme_posts
                                SET automation_id = ?,
                                    video_id = ?,
                                    caption = ?,
                                    account_ids = ?,
                                    status = ?,
                                    scheduled_at = ?
                                WHERE id = ?
                            ");
                            $upStmt->execute([$automationId, $videoName, $caption, $accountsJson, $localStatus, $dbScheduledAt, (int)$existingPostId]);
                        } else {
                            $insStmt = $pdo->prepare("
                                INSERT INTO postforme_posts (post_id, automation_id, video_id, caption, account_ids, status, scheduled_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $insStmt->execute([$postId, $automationId, $videoName, $caption, $accountsJson, $localStatus, $dbScheduledAt]);
                        }
                    } catch (Exception $e) {
                        error_log('run-sync postforme_posts save failed: ' . $e->getMessage());
                    }

                    if ($scheduledAt) {
                        $stats['scheduled']++;
                        sendProgress('posting', 'success', "✓ SCHEDULED! Post ID: {$postId} (scheduled: {$scheduledAt})", $currentProgress + $progressPerVideo + 4, $stats);
                    } else {
                        $stats['posted']++;
                        sendProgress('posting', 'success', "✓ POSTED! Post ID: {$postId}", $currentProgress + $progressPerVideo + 4, $stats);
                    }
                    
                    // Log success
                    try {
                        $stmt = $pdo->prepare("INSERT INTO automation_logs (automation_id, action, status, message, video_id, platform) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$automationId, 'postforme_success', 'success', "Posted: {$postId}", $videoName, 'postforme']);
                    } catch (Exception $e) {}
                    
                    // Get platform results (skip polling for scheduled posts - they haven't posted yet)
                    if (!$scheduledAt) {
                        sleep(2);
                        try {
                            $platformResults = $postForMe->getPostResults($postId);
                            if ($platformResults['success'] && !empty($platformResults['results'])) {
                                foreach ($platformResults['results'] as $pr) {
                                    $platform = $pr['platform'] ?? 'unknown';
                                    $pStatus = $pr['status'] ?? 'pending';
                                    $pUrl = $pr['url'] ?? '';
                                    
                                    if (in_array($pStatus, ['success', 'published', 'completed'])) {
                                        $msg = "✓ " . ucfirst($platform) . ": Posted" . ($pUrl ? " → {$pUrl}" : '');
                                        sendProgress('platform', 'success', $msg, $currentProgress + $progressPerVideo + 5, $stats);
                                    } elseif (in_array($pStatus, ['pending', 'processing'])) {
                                        sendProgress('platform', 'info', "○ " . ucfirst($platform) . ": Processing...", $currentProgress + $progressPerVideo + 5, $stats);
                                    } else {
                                        $errMsg = $pr['error'] ?? $pr['message'] ?? 'Unknown error';
                                        sendProgress('platform', 'warning', "✗ " . ucfirst($platform) . ": {$errMsg}", $currentProgress + $progressPerVideo + 5, $stats);
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            sendProgress('platform', 'info', "Platform results pending...", $currentProgress + $progressPerVideo + 5, $stats);
                        }
                    } else {
                        sendProgress('platform', 'info', "Post is scheduled - will be published at {$scheduledAt}", $currentProgress + $progressPerVideo + 5, $stats);
                    }
                } else {
                    $errMsg = $postResult['error'] ?? 'Unknown error';
                    sendProgress('posting', 'error', "✗ Post failed: {$errMsg}", $currentProgress + $progressPerVideo + 4, $stats);
                    
                    // Log error
                    try {
                        $stmt = $pdo->prepare("INSERT INTO automation_logs (automation_id, action, status, message, video_id, platform) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$automationId, 'postforme_error', 'error', $errMsg, $videoName, 'postforme']);
                    } catch (Exception $e) {}
                }
            } catch (Exception $e) {
                sendProgress('posting', 'error', "✗ Posting error: " . $e->getMessage(), $currentProgress + $progressPerVideo + 4, $stats);
            }
        }
    } else {
        $stats['errors']++;
        $errorMsg = is_array($processResult) ? ($processResult['error'] ?? 'Unknown error') : 'FFmpeg failed';
        sendProgress('process', 'warning', "Failed: $videoName - $errorMsg", $currentProgress + $progressPerVideo, $stats);
        
        // Log error
        try {
            $stmt = $pdo->prepare("INSERT INTO automation_logs (automation_id, action, status, message, video_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$automationId, 'video_processed', 'error', "Error: $errorMsg", $videoName]);
        } catch (Exception $e) {}
    }
    
    // Clean up temp file
    @unlink($localPath);
        
    } catch (Exception $e) {
        $stats['errors']++;
        sendProgress('error', 'warning', "Error with $videoName: " . $e->getMessage(), $currentProgress, $stats);
    }
    
    // Update progress in database
    $pdo->prepare("UPDATE automation_settings SET progress_percent = ? WHERE id = ?")->execute([$currentProgress, $automationId]);
}

// Complete
$pdo->prepare("UPDATE automation_settings SET status = 'completed', progress_percent = 100, last_run_at = NOW() WHERE id = ?")->execute([$automationId]);

// Summary
sendProgress('summary', 'success', "✓ Processed: {$stats['processed']}/{$stats['fetched']} videos", 98, $stats);
if ($willPost) {
    $postSummary = [];
    if ($stats['scheduled'] > 0) $postSummary[] = "Scheduled: {$stats['scheduled']}";
    if ($stats['posted'] > 0) $postSummary[] = "Posted: {$stats['posted']}";
    $summaryText = implode(', ', $postSummary) ?: 'No posts made';
    sendProgress('summary', ($stats['posted'] > 0 || $stats['scheduled'] > 0) ? 'success' : 'warning', "✓ {$summaryText}", 99, $stats);
}
sendProgress('complete', 'success', 'Automation Complete!', 100, $stats);
sendDone(true, "Completed! Processed {$stats['processed']}, Scheduled {$stats['scheduled']}, Posted {$stats['posted']} videos", $stats);
