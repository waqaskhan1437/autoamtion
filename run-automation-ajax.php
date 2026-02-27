<?php
/**
 * Run Automation with Real-Time Logging
 * COMPLETE Post for Me Integration with FULL DEBUG
 * v2.0 - Deep Debug Version
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(600);
ignore_user_abort(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

ob_implicit_flush(true);
if (ob_get_level()) ob_end_clean();

require_once 'config.php';
require_once 'includes/FTPAPI.php';
require_once 'includes/FFmpegProcessor.php';
require_once 'includes/AITaglineGenerator.php';
require_once 'includes/PostForMeAPI.php';

$automationId = $_GET['id'] ?? $_POST['id'] ?? null;

// Global stats
$GLOBALS['stats'] = ['fetched' => 0, 'downloaded' => 0, 'processed' => 0, 'posted' => 0];

function sendLog($step, $status, $message, $progress = 0) {
    $data = [
        'step' => $step,
        'status' => $status,
        'message' => $message,
        'progress' => $progress,
        'time' => date('H:i:s'),
        'stats' => $GLOBALS['stats']
    ];
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

function saveLog($pdo, $automationId, $action, $status, $message, $platform = null) {
    try {
        if ($platform) {
            $stmt = $pdo->prepare("INSERT INTO automation_logs (automation_id, action, status, message, platform) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$automationId, $action, $status, $message, $platform]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO automation_logs (automation_id, action, status, message) VALUES (?, ?, ?, ?)");
            $stmt->execute([$automationId, $action, $status, $message]);
        }
    } catch (Exception $e) {}
}

if (!$automationId) {
    sendLog('error', 'error', 'No automation ID provided');
    exit;
}

// Load automation with ALL fields
$stmt = $pdo->prepare("SELECT a.*, k.api_key, k.library_id, k.storage_zone FROM automation_settings a LEFT JOIN api_keys k ON a.api_key_id = k.id WHERE a.id = ?");
$stmt->execute([$automationId]);
$automation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$automation) {
    sendLog('error', 'error', 'Automation not found');
    exit;
}

// =====================================================
// DEEP DEBUG: Show ALL Post for Me Configuration
// =====================================================
sendLog('debug', 'info', '╔════════════════════════════════════════╗', 1);
sendLog('debug', 'info', '║     POST FOR ME DEBUG v2.0             ║', 2);
sendLog('debug', 'info', '╚════════════════════════════════════════╝', 3);

// Raw database values
$rawEnabled = $automation['postforme_enabled'] ?? 'NULL';
$rawAccountIds = $automation['postforme_account_ids'] ?? 'NULL';

sendLog('debug_raw', 'info', "DB postforme_enabled = [{$rawEnabled}]", 4);
sendLog('debug_raw', 'info', "DB postforme_account_ids = [{$rawAccountIds}]", 4);

// Parse values
$pfEnabled = !empty($automation['postforme_enabled']) && $automation['postforme_enabled'] !== '0';
$pfAccountIds = $automation['postforme_account_ids'] ?? '[]';
$pfAccounts = [];

if (!empty($pfAccountIds) && $pfAccountIds !== '[]' && $pfAccountIds !== 'NULL') {
    $decoded = @json_decode($pfAccountIds, true);
    if (is_array($decoded)) {
        $pfAccounts = array_filter($decoded);
    }
}

sendLog('debug_parsed', 'info', "Parsed pfEnabled = " . ($pfEnabled ? 'TRUE' : 'FALSE'), 5);
sendLog('debug_parsed', 'info', "Parsed pfAccounts count = " . count($pfAccounts), 5);

// Get API Key
$apiKeyStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key'");
$apiKeyStmt->execute();
$postformeApiKey = $apiKeyStmt->fetchColumn();
$hasApiKey = !empty($postformeApiKey);

sendLog('debug_api', 'info', "API Key configured = " . ($hasApiKey ? 'YES' : 'NO'), 6);

// Final check
$willPost = $pfEnabled && !empty($pfAccounts) && $hasApiKey;
sendLog('debug_result', $willPost ? 'success' : 'warning', "WILL POST = " . ($willPost ? 'YES ✓' : 'NO ✗'), 7);

if (!$willPost) {
    $reasons = [];
    if (!$pfEnabled) $reasons[] = "postforme_enabled is FALSE/0/NULL";
    if (empty($pfAccounts)) $reasons[] = "No accounts selected";
    if (!$hasApiKey) $reasons[] = "API key not configured";
    sendLog('debug_reason', 'error', "WHY NOT: " . implode(", ", $reasons), 7);
}

sendLog('debug', 'info', '════════════════════════════════════════', 8);
saveLog($pdo, $automationId, 'debug_postforme', 'info', "Enabled:{$rawEnabled}, Accounts:{$rawAccountIds}, APIKey:" . ($hasApiKey ? 'Yes' : 'No'));

// Continue with automation
sendLog('init', 'info', "Starting: {$automation['name']}", 10);
saveLog($pdo, $automationId, 'run_started', 'info', 'Automation run started');

// Directories
$baseDir = (PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : getenv('HOME') . '/VideoWorkflow';
$tempDir = $baseDir . '/temp';
$outputDir = $baseDir . '/output';

if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);
if (!is_dir($outputDir)) @mkdir($outputDir, 0777, true);

// FFmpeg check
sendLog('ffmpeg_check', 'info', 'Checking FFmpeg...', 15);
$ffmpeg = new FFmpegProcessor();
if (!$ffmpeg->isAvailable()) {
    sendLog('ffmpeg_check', 'error', 'FFmpeg not installed!');
    exit;
}
sendLog('ffmpeg_check', 'success', 'FFmpeg OK', 18);

// Fetch videos
sendLog('fetch', 'info', 'Connecting to FTP...', 20);

$videos = [];
try {
    if (($automation['video_source'] ?? 'ftp') === 'ftp') {
        $ftp = FTPAPI::fromSettings($pdo);
        $videos = $ftp->getVideos($automation['video_days_filter'] ?? 30);
        $ftp->disconnect();
        
        $GLOBALS['stats']['fetched'] = count($videos);
        sendLog('fetch', 'success', 'Found ' . count($videos) . ' videos', 25);
    }
} catch (Exception $e) {
    sendLog('fetch', 'error', 'Fetch Error: ' . $e->getMessage());
    exit;
}

if (empty($videos)) {
    sendLog('complete', 'info', 'No videos to process', 100);
    exit;
}

// Process videos
$processed = 0;
$posted = 0;
$totalVideos = min(count($videos), 5);

foreach (array_slice($videos, 0, $totalVideos) as $index => $video) {
    $videoNum = $index + 1;
    $baseProgress = 30 + (($index / $totalVideos) * 60);
    
    $videoTitle = $video['title'] ?? $video['filename'] ?? 'Unknown';
    $videoFilename = $video['filename'] ?? null;
    $videoRemotePath = $video['remotePath'] ?? null;
    $videoGuid = $video['guid'] ?? md5($videoFilename ?? uniqid());
    
    if (empty($videoFilename) || empty($videoRemotePath)) {
        sendLog('skip', 'warning', "Skipping video {$videoNum}: Missing data", $baseProgress);
        continue;
    }
    
    sendLog('video', 'info', "▶ Video {$videoNum}/{$totalVideos}: {$videoTitle}", $baseProgress);
    
    try {
        // Download
        $localPath = $tempDir . '/' . $videoFilename;
        sendLog('download', 'info', "Downloading: {$videoFilename}", $baseProgress + 5);
        
        $ftp = FTPAPI::fromSettings($pdo);
        $ftp->downloadVideo($videoRemotePath, $localPath);
        $ftp->disconnect();
        
        if (!file_exists($localPath)) {
            throw new Exception("Download failed");
        }
        
        $GLOBALS['stats']['downloaded']++;
        $fileSize = round(filesize($localPath) / 1024 / 1024, 2);
        sendLog('download', 'success', "Downloaded: {$fileSize} MB", $baseProgress + 10);
        
        // AI Taglines
        $topText = $automation['branding_text_top'] ?? '';
        $bottomText = $automation['branding_text_bottom'] ?? '';
        
        if (!empty($automation['ai_taglines_enabled']) && !empty($automation['ai_tagline_prompt'])) {
            sendLog('ai', 'info', 'Generating taglines...', $baseProgress + 15);
            try {
                $ai = new AITaglineGenerator($pdo);
                $taglines = $ai->generateTaglines($automation['ai_tagline_prompt'], $videoTitle);
                if (isset($taglines['success']) && $taglines['success']) {
                    $topText = $taglines['top'];
                    $bottomText = $taglines['bottom'];
                    sendLog('ai', 'success', "Tagline: {$topText}", $baseProgress + 18);
                }
            } catch (Exception $e) {
                sendLog('ai', 'error', 'AI Error: ' . $e->getMessage());
            }
        }
        
        // FFmpeg processing
        sendLog('ffmpeg', 'info', 'Creating short...', $baseProgress + 20);
        
        $safeId = preg_replace('/[^a-zA-Z0-9]/', '', $videoGuid);
        $outputPath = $outputDir . '/short_' . $safeId . '_' . time() . '.mp4';
        
        $result = $ffmpeg->createShort($localPath, $outputPath, [
            'duration' => $automation['short_duration'] ?? 60,
            'aspectRatio' => $automation['short_aspect_ratio'] ?? '9:16',
            'topText' => $topText,
            'bottomText' => $bottomText
        ]);
        
        if ($result['success']) {
            $processed++;
            $GLOBALS['stats']['processed'] = $processed;
            $outSize = round(filesize($outputPath) / 1024 / 1024, 2);
            sendLog('ffmpeg', 'success', "✓ Short created: {$outSize} MB", $baseProgress + 30);
            
            // =====================================================
            // POST TO SOCIAL MEDIA
            // =====================================================
            sendLog('post_check', 'info', '── Checking Post for Me ──', $baseProgress + 32);
            sendLog('post_check', 'info', "pfEnabled={$pfEnabled}, accounts=" . count($pfAccounts) . ", apiKey=" . ($hasApiKey ? 'yes' : 'no'), $baseProgress + 33);
            
            if ($pfEnabled && !empty($pfAccounts) && $hasApiKey) {
                sendLog('posting', 'info', '🚀 POSTING TO SOCIAL MEDIA...', $baseProgress + 35);
                
                try {
                    $postForMe = new PostForMeAPI($postformeApiKey);
                    $caption = $topText ?: ($videoTitle ?: 'Check this out!');
                    
                    sendLog('posting', 'info', "Caption: {$caption}", $baseProgress + 36);
                    sendLog('posting', 'info', "Uploading to " . count($pfAccounts) . " account(s)...", $baseProgress + 37);
                    
                    $postResult = $postForMe->postVideo($outputPath, $caption, $pfAccounts);
                    
                    // Log API response
                    $responseLog = json_encode($postResult);
                    if (strlen($responseLog) > 300) $responseLog = substr($responseLog, 0, 300) . '...';
                    sendLog('post_api', 'info', "API: {$responseLog}", $baseProgress + 38);
                    
                    if ($postResult['success']) {
                        $postId = $postResult['post_id'] ?? 'unknown';
                        $posted++;
                        $GLOBALS['stats']['posted'] = $posted;
                        sendLog('posting', 'success', "✓ POSTED! ID: {$postId}", $baseProgress + 40);
                        saveLog($pdo, $automationId, 'postforme_success', 'success', "Posted: {$postId}", 'postforme');
                        
                        // Get platform results
                        sleep(2);
                        $platformResults = $postForMe->getPostResults($postId);
                        if ($platformResults['success'] && !empty($platformResults['results'])) {
                            foreach ($platformResults['results'] as $pr) {
                                $platform = $pr['platform'] ?? 'unknown';
                                $pStatus = $pr['status'] ?? 'pending';
                                $pUrl = $pr['url'] ?? '';
                                
                                if (in_array($pStatus, ['success', 'published', 'completed'])) {
                                    sendLog('platform', 'success', "✓ {$platform}: Posted" . ($pUrl ? " → {$pUrl}" : ''), $baseProgress + 42);
                                } elseif (in_array($pStatus, ['pending', 'processing'])) {
                                    sendLog('platform', 'info', "○ {$platform}: Processing...", $baseProgress + 42);
                                } else {
                                    $err = $pr['error'] ?? $pr['message'] ?? 'Unknown';
                                    sendLog('platform', 'error', "✗ {$platform}: {$err}", $baseProgress + 42);
                                }
                            }
                        }
                    } else {
                        $err = $postResult['error'] ?? 'Unknown error';
                        sendLog('posting', 'error', "✗ POST FAILED: {$err}", $baseProgress + 40);
                        saveLog($pdo, $automationId, 'postforme_error', 'error', $err, 'postforme');
                    }
                } catch (Exception $e) {
                    sendLog('posting', 'error', "✗ Exception: " . $e->getMessage(), $baseProgress + 40);
                    saveLog($pdo, $automationId, 'postforme_error', 'error', $e->getMessage(), 'postforme');
                }
            } else {
                // Show exactly why not posting
                if (!$pfEnabled) {
                    sendLog('post_skip', 'warning', '⚠ Post for Me NOT ENABLED for this automation', $baseProgress + 35);
                } elseif (empty($pfAccounts)) {
                    sendLog('post_skip', 'warning', '⚠ No social accounts selected', $baseProgress + 35);
                } elseif (!$hasApiKey) {
                    sendLog('post_skip', 'error', '✗ Post for Me API key not configured in Settings!', $baseProgress + 35);
                }
            }
            
        } else {
            sendLog('ffmpeg', 'error', 'FFmpeg failed: ' . ($result['error'] ?? 'Unknown'));
        }
        
        @unlink($localPath);
        sendLog('video', 'success', "✓ Completed: {$videoTitle}", $baseProgress + 45);
        
    } catch (Exception $e) {
        sendLog('error', 'error', "Error: " . $e->getMessage());
        @unlink($localPath ?? '');
    }
}

// Update last run
$stmt = $pdo->prepare("UPDATE automation_settings SET last_run = NOW() WHERE id = ?");
$stmt->execute([$automationId]);

// Final summary
sendLog('summary', 'info', '════════════════════════════════════════', 95);
sendLog('summary', 'success', "✓ PROCESSED: {$processed}/{$totalVideos} videos", 97);
sendLog('summary', $posted > 0 ? 'success' : 'warning', "✓ POSTED: {$posted} videos to social media", 98);
sendLog('complete', 'success', '🎉 AUTOMATION COMPLETE!', 100);
saveLog($pdo, $automationId, 'run_completed', 'success', "Processed: {$processed}, Posted: {$posted}");

echo "data: {\"done\": true}\n\n";
flush();
