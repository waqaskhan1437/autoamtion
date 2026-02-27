<?php
/**
 * Background Automation Runner
 * Runs completely independent of browser - continues even if page closes
 * Checks for stop signal before each step
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/background-error.log');
set_time_limit(3600); // 1 hour max
ignore_user_abort(true);

// Create logs directory if not exists
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0777, true);
}

// Log startup
file_put_contents(__DIR__ . '/logs/background.log', 
    date('Y-m-d H:i:s') . " - Background process started\n", FILE_APPEND);

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/FTPAPI.php';
    require_once __DIR__ . '/includes/FFmpegProcessor.php';
    require_once __DIR__ . '/includes/AITaglineGenerator.php';
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/logs/background-error.log', 
        date('Y-m-d H:i:s') . " - Failed to load: " . $e->getMessage() . "\n", FILE_APPEND);
    exit(1);
}

$automationId = $argv[1] ?? $_GET['id'] ?? null;

file_put_contents(__DIR__ . '/logs/background.log', 
    date('Y-m-d H:i:s') . " - Processing automation ID: {$automationId}\n", FILE_APPEND);

// Check if process should stop (deleted or stopped by user)
function shouldStop($pdo, $automationId) {
    $stmt = $pdo->prepare("SELECT status, process_id FROM automation_settings WHERE id = ?");
    $stmt->execute([$automationId]);
    $result = $stmt->fetch();
    
    // Stop if automation deleted
    if (!$result) return true;
    
    // Stop if status set to stopped/inactive
    if (in_array($result['status'], ['stopped', 'inactive', 'error'])) return true;
    
    // Optionally, check if the recorded process ID is still running (OS-specific)
    // This is a basic check - in Windows we could check tasklist, in Linux ps
    if ($result['process_id']) {
        if (PHP_OS_FAMILY === 'Windows') {
            // On Windows, check if process exists
            $exitCode = 0;
            exec("tasklist /FI \"PID eq {$result['process_id']}\" 2>NUL | findstr {$result['process_id']} >NUL", $output, $exitCode);
            if ($exitCode !== 0) {
                // Process not found, likely already terminated
                return true;
            }
        } else {
            // On Unix-like systems, check if process exists
            $exitCode = 0;
            exec("ps -p {$result['process_id']} > /dev/null 2>&1", $output, $exitCode);
            if ($exitCode !== 0) {
                // Process not found, likely already terminated
                return true;
            }
        }
    }
    
    return false;
}

function stopProcess($pdo, $automationId, $message = 'Process stopped by user') {
    // Attempt to kill any associated processes
    $stmt = $pdo->prepare("SELECT process_id FROM automation_settings WHERE id = ?");
    $stmt->execute([$automationId]);
    $result = $stmt->fetch();
    
    if ($result && $result['process_id']) {
        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /PID {$result['process_id']} 2>NUL", $output, $exitCode);
        } else {
            exec("kill -TERM {$result['process_id']} 2>/dev/null", $output, $exitCode);
        }
    }
    
    $pdo->prepare("UPDATE automation_settings SET status = 'stopped', progress_data = ? WHERE id = ?")
        ->execute([json_encode(['step' => 'stopped', 'status' => 'info', 'message' => $message, 'time' => date('H:i:s')]), $automationId]);
    exit;
}

function updateProgress($pdo, $automationId, $step, $status, $message, $progress = 0, $stats = []) {
    $data = json_encode([
        'step' => $step,
        'status' => $status,
        'message' => $message,
        'progress' => $progress,
        'stats' => $stats,
        'time' => date('H:i:s')
    ]);
    
    $stmt = $pdo->prepare("UPDATE automation_settings SET 
        progress_data = ?, 
        progress_percent = ?,
        last_progress_time = NOW()
        WHERE id = ?");
    $stmt->execute([$data, $progress, $automationId]);
}

function saveLog($pdo, $automationId, $action, $status, $message) {
    try {
        $stmt = $pdo->prepare("INSERT INTO automation_logs (automation_id, action, status, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$automationId, $action, $status, $message]);
    } catch (Exception $e) {}
}

function calculateNextRunAt($automation) {
    $nextRun = new DateTime();
    $scheduleType = $automation['schedule_type'] ?? 'daily';
    $scheduleHour = (int)($automation['schedule_hour'] ?? 9);
    $scheduleEveryMinutes = max(1, (int)($automation['schedule_every_minutes'] ?? 10));

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

function finalizeAutomationRun($pdo, $automationId, $automation, $progressPercent = 100) {
    $isEnabled = !empty($automation['enabled']);
    if ($isEnabled) {
        $nextRunAt = calculateNextRunAt($automation);
        $stmt = $pdo->prepare("
            UPDATE automation_settings
            SET status = 'running',
                last_run_at = NOW(),
                next_run_at = ?,
                progress_percent = ?,
                process_id = NULL
            WHERE id = ?
        ");
        $stmt->execute([$nextRunAt, $progressPercent, $automationId]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE automation_settings
            SET status = 'inactive',
                last_run_at = NOW(),
                progress_percent = ?,
                process_id = NULL
            WHERE id = ?
        ");
        $stmt->execute([$progressPercent, $automationId]);
    }
}

if (!$automationId) {
    exit('No automation ID');
}

// Mark as running and store process info
$processId = getmypid(); // Get current process ID
$stmt = $pdo->prepare("UPDATE automation_settings SET status = 'processing', progress_percent = 0, progress_data = NULL, process_id = ? WHERE id = ?");
$stmt->execute([$processId, $automationId]);

// Get automation details
$stmt = $pdo->prepare("SELECT a.*, k.api_key, k.library_id, k.storage_zone FROM automation_settings a LEFT JOIN api_keys k ON a.api_key_id = k.id WHERE a.id = ?");
$stmt->execute([$automationId]);
$automation = $stmt->fetch();

if (!$automation) {
    exit('Automation not found');
}

$stats = ['fetched' => 0, 'downloaded' => 0, 'processed' => 0];

updateProgress($pdo, $automationId, 'init', 'info', "Starting: {$automation['name']}", 5, $stats);
saveLog($pdo, $automationId, 'run_started', 'info', 'Background automation started');

// Set up directories
$baseDir = (PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : getenv('HOME') . '/VideoWorkflow';
$tempDir = $baseDir . '/temp';
$outputDir = $baseDir . '/output';

if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);
if (!is_dir($outputDir)) @mkdir($outputDir, 0777, true);

// Step 1: Check FFmpeg
updateProgress($pdo, $automationId, 'ffmpeg_check', 'info', 'Checking FFmpeg...', 10, $stats);
$ffmpeg = new FFmpegProcessor();
if (!$ffmpeg->isAvailable()) {
    updateProgress($pdo, $automationId, 'ffmpeg_check', 'error', 'FFmpeg not installed! Go to Settings', 10, $stats);
    saveLog($pdo, $automationId, 'ffmpeg_error', 'error', 'FFmpeg not available');
    $pdo->prepare("UPDATE automation_settings SET status = 'error', process_id = NULL WHERE id = ?")->execute([$automationId]);
    exit;
}
updateProgress($pdo, $automationId, 'ffmpeg_check', 'success', 'FFmpeg OK', 15, $stats);

// Step 2: Connect and fetch videos
updateProgress($pdo, $automationId, 'fetch', 'info', 'Connecting to video storage...', 20, $stats);

try {
    // Use API key specific FTP or global settings
    $apiKeyId = $automation['api_key_id'] ?? null;
    
    if ($apiKeyId) {
        // Get specific API key settings if provided
        $stmt = $pdo->prepare("SELECT * FROM api_keys WHERE id = ?");
        $stmt->execute([$apiKeyId]);
        $apiKey = $stmt->fetch();
        
        if ($apiKey) {
            $ftp = new FTPAPI(
                $apiKey['ftp_host'] ?? '',
                $apiKey['ftp_username'] ?? '',
                $apiKey['ftp_password'] ?? '',
                $apiKey['ftp_port'] ?? 21,
                $apiKey['ftp_path'] ?? '/',
                $apiKey['ftp_ssl'] ?? false
            );
        } else {
            // Fall back to global settings
            $ftp = FTPAPI::fromSettings($pdo);
        }
    } else {
        // Use global FTP settings
        $ftp = FTPAPI::fromSettings($pdo);
    }
    
    // DEBUG: Log the date values for troubleshooting
    $logDebug = __DIR__ . '/debug_dates.log';
    $startValue = $automation['video_start_date'] ?? null;
    $endValue = $automation['video_end_date'] ?? null;
    $daysFilter = $automation['video_days_filter'] ?? 30;
    
    file_put_contents($logDebug, 
        date('Y-m-d H:i:s') . " - Automation ID: {$automationId}\n" .
        "  video_start_date: " . var_export($startValue, true) . "\n" .
        "  video_end_date: " . var_export($endValue, true) . "\n" .
        "  video_days_filter: " . var_export($daysFilter, true) . "\n" .
        "  hasValidStartDate: " . ($startValue !== null && trim($startValue) !== '' && $startValue !== 'null' && DateTime::createFromFormat('Y-m-d', trim($startValue)) !== false ? 'YES' : 'NO') . "\n" .
        "  hasValidEndDate: " . ($endValue !== null && trim($endValue) !== '' && $endValue !== 'null' && DateTime::createFromFormat('Y-m-d', trim($endValue)) !== false ? 'YES' : 'NO') . "\n",
        FILE_APPEND | LOCK_EX
    );
    
    // Check if both values are set, not empty, not null string, and are valid date formats
    $hasValidStartDate = $startValue !== null && trim($startValue) !== '' && $startValue !== 'null' && DateTime::createFromFormat('Y-m-d', trim($startValue)) !== false;
    $hasValidEndDate = $endValue !== null && trim($endValue) !== '' && $endValue !== 'null' && DateTime::createFromFormat('Y-m-d', trim($endValue)) !== false;
    
    $usingDateRange = $hasValidStartDate && $hasValidEndDate;
    $filterLabel = $usingDateRange
        ? "Date range {$startValue} to {$endValue}"
        : "Last {$daysFilter} days";
    
    if (!$usingDateRange) {
        $hasAnyDateInput = ($startValue !== null && trim($startValue) !== '' && $startValue !== 'null') ||
                           ($endValue !== null && trim($endValue) !== '' && $endValue !== 'null');
        if ($hasAnyDateInput) {
            $filterLabel .= " (date range invalid or incomplete)";
        }
    }
    
    updateProgress($pdo, $automationId, 'fetch', 'info', "Filter: {$filterLabel}", 22, $stats);
    
    if ($usingDateRange) {
        // Use date range filtering
        file_put_contents($logDebug, "  Action: Using date range filtering\n", FILE_APPEND | LOCK_EX);
        $videos = $ftp->getVideosByDateRange($startValue, $endValue);
    } else {
        // Use days filter as fallback
        file_put_contents($logDebug, "  Action: Using days filter ({$daysFilter} days)\n", FILE_APPEND | LOCK_EX);
        $videos = $ftp->getVideos($daysFilter);
    }
    
    $stats['fetched'] = count($videos);
    updateProgress($pdo, $automationId, 'fetch', 'success', "Found {$stats['fetched']} videos", 25, $stats);
    saveLog($pdo, $automationId, 'videos_fetched', 'success', "Found {$stats['fetched']} videos");
} catch (Exception $e) {
    updateProgress($pdo, $automationId, 'fetch', 'error', 'FTP Error: ' . $e->getMessage(), 25, $stats);
    saveLog($pdo, $automationId, 'fetch_error', 'error', $e->getMessage());
    $pdo->prepare("UPDATE automation_settings SET status = 'error', process_id = NULL WHERE id = ?")->execute([$automationId]);
    exit;
}

if (empty($videos)) {
    updateProgress($pdo, $automationId, 'fetch', 'info', 'No videos found to process', 100, $stats);
    saveLog($pdo, $automationId, 'no_videos', 'info', 'No videos found');
    finalizeAutomationRun($pdo, $automationId, $automation, 100);
    exit;
}

// Process videos
$maxVideos = min(5, count($videos));
$progressPerVideo = 70 / $maxVideos;

for ($i = 0; $i < $maxVideos; $i++) {
    // Check if user stopped the process
    if (shouldStop($pdo, $automationId)) {
        saveLog($pdo, $automationId, 'stopped', 'info', 'Process stopped by user');
        stopProcess($pdo, $automationId, 'Stopped by user');
    }
    
    $video = $videos[$i];
    $currentProgress = 25 + ($i * $progressPerVideo);
    $videoName = $video['filename'] ?? $video['name'] ?? $video['title'] ?? 'unknown';
    $remotePath = $video['remotePath'] ?? $video['path'] ?? $videoName;
    
    updateProgress($pdo, $automationId, 'download', 'info', "Downloading: $videoName (" . ($i+1) . "/$maxVideos)", $currentProgress, $stats);
    
    // Download
    try {
        $localPath = $tempDir . '/' . $videoName;
        $downloadResult = $ftp->downloadVideo($remotePath, $localPath);
        
        if ($downloadResult) {
            $stats['downloaded']++;
            updateProgress($pdo, $automationId, 'download', 'success', "Downloaded: $videoName", $currentProgress + 5, $stats);
        }
    } catch (Exception $e) {
        updateProgress($pdo, $automationId, 'download', 'error', "Download failed: " . $e->getMessage(), $currentProgress, $stats);
        continue;
    }
    
    // Check again before FFmpeg (which takes time)
    if (shouldStop($pdo, $automationId)) {
        @unlink($localPath); // Clean up
        saveLog($pdo, $automationId, 'stopped', 'info', 'Process stopped by user');
        stopProcess($pdo, $automationId, 'Stopped by user');
    }
    
    // Process with FFmpeg
    updateProgress($pdo, $automationId, 'ffmpeg', 'info', "Processing: $videoName", $currentProgress + 10, $stats);
    
    try {
        $outputPath = $outputDir . '/short_' . time() . '_' . $videoName;
        
        // Determine overlay text - AI generated or static
        $topText = $automation['branding_text_top'] ?? '';
        $bottomText = $automation['branding_text_bottom'] ?? '';
        
        // Check if AI taglines are enabled
        if (!empty($automation['ai_taglines_enabled'])) {
            updateProgress($pdo, $automationId, 'ai', 'info', "Generating AI taglines...", $currentProgress + 8, $stats);
            
            try {
                $aiGenerator = new AITaglineGenerator($pdo);
                
                // Get previously used taglines to avoid repetition
                $previousTaglines = [];
                try {
                    $prevStmt = $pdo->prepare("SELECT message FROM automation_logs WHERE automation_id = ? AND action = 'ai_tagline' ORDER BY created_at DESC LIMIT 20");
                    $prevStmt->execute([$automationId]);
                    $previousTaglines = array_column($prevStmt->fetchAll(), 'message');
                } catch (Exception $e) {}
                
                // Generate unique taglines for this video
                $prompt = $automation['ai_tagline_prompt'] ?? 'Generate catchy viral taglines';
                $videoTitle = pathinfo($videoName, PATHINFO_FILENAME);
                $aiResult = $aiGenerator->generateTaglines($prompt, $videoTitle, $previousTaglines);
                
                if (!empty($aiResult['success']) && !empty($aiResult['top'])) {
                    $topText = $aiResult['top'];
                    $bottomText = $aiResult['bottom'];
                    updateProgress($pdo, $automationId, 'ai', 'success', "AI: \"{$topText}\" | \"{$bottomText}\"", $currentProgress + 9, $stats);
                    
                    // Log AI taglines
                    saveLog($pdo, $automationId, 'ai_tagline', 'success', "Top: {$topText} | Bottom: {$bottomText}");
                }
            } catch (Exception $e) {
                updateProgress($pdo, $automationId, 'ai', 'warning', "AI error, using default", $currentProgress + 9, $stats);
            }
        }
        
        // Add random word if enabled
        $randomWords = json_decode($automation['random_words'] ?? '[]', true);
        if (!empty($randomWords) && is_array($randomWords)) {
            $topText = trim($topText . ' ' . $randomWords[array_rand($randomWords)]);
        }
        
        $options = [
            'duration' => $automation['short_duration'] ?? 60,
            'aspectRatio' => $automation['short_aspect_ratio'] ?? '9:16',
            'topText' => $topText,
            'bottomText' => $bottomText
        ];
        
        // Check for stop signal before starting FFmpeg processing
        if (shouldStop($pdo, $automationId)) {
            @unlink($localPath); // Clean up
            saveLog($pdo, $automationId, 'stopped', 'info', 'Process stopped by user before FFmpeg');
            stopProcess($pdo, $automationId, 'Stopped by user');
        }
        
        $result = $ffmpeg->createShort($localPath, $outputPath, $options);
        
        // Check for stop signal after FFmpeg processing
        if (shouldStop($pdo, $automationId)) {
            @unlink($localPath); // Clean up
            @unlink($outputPath); // Clean up output if partially created
            saveLog($pdo, $automationId, 'stopped', 'info', 'Process stopped by user after FFmpeg');
            stopProcess($pdo, $automationId, 'Stopped by user');
        }
        
        if ($result['success']) {
            $stats['processed']++;
            $size = round(filesize($outputPath) / 1024 / 1024, 2);
            updateProgress($pdo, $automationId, 'ffmpeg', 'success', "Created short: {$size}MB", $currentProgress + 15, $stats);
            saveLog($pdo, $automationId, 'video_processed', 'success', "Created: " . basename($outputPath));
        } else {
            updateProgress($pdo, $automationId, 'ffmpeg', 'error', "FFmpeg error: " . ($result['error'] ?? 'Unknown'), $currentProgress + 15, $stats);
        }
        
        // Clean up temp file
        @unlink($localPath);
        
    } catch (Exception $e) {
        updateProgress($pdo, $automationId, 'ffmpeg', 'error', "Process error: " . $e->getMessage(), $currentProgress + 15, $stats);
    }
}

// Complete
updateProgress($pdo, $automationId, 'complete', 'success', "Done! Processed {$stats['processed']} videos", 100, $stats);
saveLog($pdo, $automationId, 'run_completed', 'success', "Processed {$stats['processed']} videos");

finalizeAutomationRun($pdo, $automationId, $automation, 100);

// Start next queued automation (if any)
startNextQueued($pdo);

function startNextQueued($pdo) {
    $stmt = $pdo->query("SELECT id FROM automation_settings WHERE status = 'queued' ORDER BY id ASC LIMIT 1");
    $nextAutomation = $stmt->fetch();
    
    if ($nextAutomation) {
        $nextId = $nextAutomation['id'];
        
        // Mark as processing
        $pdo->prepare("UPDATE automation_settings SET status = 'processing', progress_percent = 0 WHERE id = ?")->execute([$nextId]);
        
        // Start background process for next automation
        $phpPath = PHP_OS_FAMILY === 'Windows' ? 'php' : '/usr/bin/php';
        $scriptPath = __DIR__ . '/run-background.php';
        
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "start /B {$phpPath} \"{$scriptPath}\" {$nextId}";
            pclose(popen($cmd, 'r'));
        } else {
            $cmd = "nohup {$phpPath} \"{$scriptPath}\" {$nextId} > /dev/null 2>&1 &";
            exec($cmd);
        }
    }
}
