<?php
/**
 * Test Automation Run - Comprehensive Debug Tool
 * Tests: FTP/Bunny, Download, FFmpeg, AI Taglines, Full Pipeline
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300); // 5 minutes max

require_once 'config.php';
require_once 'includes/FTPAPI.php';
require_once 'includes/FFmpegProcessor.php';
require_once 'includes/AITaglineGenerator.php';

header('Content-Type: text/html; charset=utf-8');

// Helper functions
function status($ok) {
    return $ok ? '<span style="color:#4ade80">✓ PASS</span>' : '<span style="color:#ef4444">✗ FAIL</span>';
}

function logLine($msg, $type = 'info') {
    $colors = ['info' => '#94a3b8', 'success' => '#4ade80', 'error' => '#ef4444', 'warn' => '#fbbf24'];
    echo "<div style='color:{$colors[$type]}'>[" . date('H:i:s') . "] {$msg}</div>";
    flush();
    ob_flush();
}

$automationId = $_GET['id'] ?? null;
$testType = $_GET['test'] ?? 'all';

// Page header
echo "<!DOCTYPE html><html><head><title>Video Workflow Test</title>";
echo "<style>
body { background:#1a1a2e; color:#fff; font-family:'Consolas',monospace; padding:20px; }
.section { background:#16213e; border-radius:8px; padding:15px; margin:15px 0; }
.section h3 { margin-top:0; color:#4ade80; }
a { color:#60a5fa; }
.btn { display:inline-block; background:#4ade80; color:#000; padding:10px 20px; border-radius:5px; text-decoration:none; margin:5px; }
.btn:hover { background:#22c55e; }
.btn-danger { background:#ef4444; }
.btn-danger:hover { background:#dc2626; }
pre { background:#0f0f23; padding:10px; border-radius:5px; overflow-x:auto; }
</style></head><body>";

echo "<h1>🎬 Video Workflow Test Panel</h1>";

// No automation selected - show list
if (!$automationId) {
    $stmt = $pdo->query("SELECT id, name, video_source, status FROM automation_settings ORDER BY id DESC");
    $autos = $stmt->fetchAll();
    
    echo "<div class='section'><h3>Select Automation to Test</h3>";
    if (empty($autos)) {
        echo "<p>No automations found. <a href='automation.php'>Create one first</a>.</p>";
    } else {
        echo "<table style='width:100%;'><tr><th>Name</th><th>Source</th><th>Status</th><th>Actions</th></tr>";
        foreach ($autos as $a) {
            echo "<tr>";
            echo "<td>{$a['name']}</td>";
            echo "<td>{$a['video_source']}</td>";
            echo "<td>{$a['status']}</td>";
            echo "<td>";
            echo "<a class='btn' href='?id={$a['id']}&test=check'>Check System</a> ";
            echo "<a class='btn' href='?id={$a['id']}&test=fetch'>Test Fetch</a> ";
            echo "<a class='btn' href='?id={$a['id']}&test=process'>Full Test</a>";
            echo "</td></tr>";
        }
        echo "</table>";
    }
    echo "</div>";
    
    // Quick System Check
    echo "<div class='section'><h3>Quick System Status</h3>";
    
    // FFmpeg
    $ffmpeg = new FFmpegProcessor();
    $ffmpegOk = $ffmpeg->isAvailable();
    echo "<p>FFmpeg: " . status($ffmpegOk) . " ";
    if ($ffmpegOk) {
        echo $ffmpeg->getVersion();
    } else {
        $paths = $ffmpeg->getPaths();
        echo " (Tried: {$paths['ffmpeg']})";
    }
    echo "</p>";
    
    // FTP Settings
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'ftp_%'");
        $ftpSettings = [];
        while ($row = $stmt->fetch()) {
            $ftpSettings[$row['setting_key']] = $row['setting_value'];
        }
        $ftpOk = !empty($ftpSettings['ftp_host']);
        echo "<p>FTP Config: " . status($ftpOk) . " ";
        if ($ftpOk) {
            echo "Host: {$ftpSettings['ftp_host']}";
        } else {
            echo " <a href='settings.php'>Configure FTP</a>";
        }
        echo "</p>";
    } catch (Exception $e) {
        echo "<p>FTP Config: " . status(false) . " Error: {$e->getMessage()}</p>";
    }
    
    // OpenAI
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'openai_api_key'");
    $openaiKey = $stmt->fetchColumn();
    $openaiOk = !empty($openaiKey) && strlen($openaiKey) > 20;
    echo "<p>OpenAI API: " . status($openaiOk) . " ";
    if (!$openaiOk) {
        echo " <a href='settings.php'>Configure OpenAI</a>";
    } else {
        echo " Key configured (" . strlen($openaiKey) . " chars)";
    }
    echo "</p>";
    
    // Directories
    $baseDir = (PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : getenv('HOME') . '/VideoWorkflow';
    $tempDir = $baseDir . '/temp';
    $outputDir = $baseDir . '/output';
    
    echo "<p>Temp Dir: " . status(is_writable($tempDir) || @mkdir($tempDir, 0777, true)) . " {$tempDir}</p>";
    echo "<p>Output Dir: " . status(is_writable($outputDir) || @mkdir($outputDir, 0777, true)) . " {$outputDir}</p>";
    
    echo "</div>";
    
    echo "</body></html>";
    exit;
}

// Get automation details
$stmt = $pdo->prepare("SELECT a.*, k.api_key, k.library_id, k.storage_zone FROM automation_settings a LEFT JOIN api_keys k ON a.api_key_id = k.id WHERE a.id = ?");
$stmt->execute([$automationId]);
$automation = $stmt->fetch();

if (!$automation) {
    echo "<p style='color:#ef4444'>Automation ID {$automationId} not found!</p>";
    exit;
}

echo "<div class='section'>";
echo "<h3>Testing: {$automation['name']}</h3>";
echo "<p>Source: {$automation['video_source']} | Duration: {$automation['short_duration']}s | Aspect: {$automation['short_aspect_ratio']}</p>";
echo "<p><a href='?'>← Back to List</a></p>";
echo "</div>";

// System Check
if ($testType === 'check' || $testType === 'all') {
    echo "<div class='section'><h3>1. System Check</h3><pre>";
    
    // FFmpeg
    $ffmpeg = new FFmpegProcessor();
    if ($ffmpeg->isAvailable()) {
        logLine("FFmpeg: " . $ffmpeg->getVersion(), 'success');
        $paths = $ffmpeg->getPaths();
        logLine("  Path: {$paths['ffmpeg']}");
        logLine("  Font: {$paths['font']}");
    } else {
        logLine("FFmpeg: NOT FOUND! Please install FFmpeg.", 'error');
        logLine("Download from: https://ffmpeg.org/download.html", 'info');
        logLine("For Windows: Extract to C:\\ffmpeg\\ and add to PATH", 'info');
    }
    
    // Directories
    $baseDir = (PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : getenv('HOME') . '/VideoWorkflow';
    $tempDir = $baseDir . '/temp';
    $outputDir = $baseDir . '/output';
    
    if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);
    if (!is_dir($outputDir)) @mkdir($outputDir, 0777, true);
    
    logLine("Temp Dir: {$tempDir} - " . (is_writable($tempDir) ? 'Writable' : 'NOT WRITABLE'), is_writable($tempDir) ? 'success' : 'error');
    logLine("Output Dir: {$outputDir} - " . (is_writable($outputDir) ? 'Writable' : 'NOT WRITABLE'), is_writable($outputDir) ? 'success' : 'error');
    
    echo "</pre></div>";
}

// Fetch Test
if ($testType === 'fetch' || $testType === 'all' || $testType === 'process') {
    echo "<div class='section'><h3>2. Video Fetch Test</h3><pre>";
    
    $videos = [];
    
    try {
        if (($automation['video_source'] ?? 'ftp') === 'ftp') {
            logLine("Connecting to Bunny Storage/FTP...");
            $ftp = FTPAPI::fromSettings($pdo);
            
            logLine("Fetching video list (last {$automation['video_days_filter']} days)...");
            $videos = $ftp->getVideos($automation['video_days_filter'] ?? 30);
            $ftp->disconnect();
            
            logLine("Found " . count($videos) . " videos", count($videos) > 0 ? 'success' : 'warn');
            
            if (!empty($videos)) {
                foreach (array_slice($videos, 0, 5) as $i => $v) {
                    $size = isset($v['size']) ? number_format($v['size'] / 1024 / 1024, 2) . ' MB' : 'Unknown';
                    logLine("  " . ($i+1) . ". {$v['title']} ({$size})");
                }
                if (count($videos) > 5) {
                    logLine("  ... and " . (count($videos) - 5) . " more");
                }
            }
        } else {
            logLine("Bunny CDN API selected (not FTP)", 'info');
        }
    } catch (Exception $e) {
        logLine("Fetch Error: " . $e->getMessage(), 'error');
    }
    
    echo "</pre></div>";
}

// Full Process Test
if ($testType === 'process' && isset($_GET['confirm'])) {
    echo "<div class='section'><h3>3. Full Processing Test</h3><pre>";
    
    $videos = [];
    try {
        if (($automation['video_source'] ?? 'ftp') === 'ftp') {
            $ftp = FTPAPI::fromSettings($pdo);
            $videos = $ftp->getVideos($automation['video_days_filter'] ?? 30);
        }
    } catch (Exception $e) {
        logLine("Cannot fetch videos: " . $e->getMessage(), 'error');
    }
    
    if (empty($videos)) {
        logLine("No videos to process", 'warn');
    } else {
        $video = $videos[0]; // Test with first video only
        logLine("Testing with: {$video['title']}", 'info');
        
        // Step 1: Download
        logLine("Step 1: Downloading video...");
        $baseDir = (PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : getenv('HOME') . '/VideoWorkflow';
        $localPath = $baseDir . '/temp/' . $video['filename'];
        
        try {
            $ftp = FTPAPI::fromSettings($pdo);
            $ftp->downloadVideo($video['remotePath'], $localPath);
            $ftp->disconnect();
            
            if (file_exists($localPath)) {
                $size = number_format(filesize($localPath) / 1024 / 1024, 2);
                logLine("Downloaded: {$localPath} ({$size} MB)", 'success');
            } else {
                logLine("Download failed - file not found", 'error');
            }
        } catch (Exception $e) {
            logLine("Download Error: " . $e->getMessage(), 'error');
        }
        
        if (file_exists($localPath)) {
            // Step 2: AI Taglines (if enabled)
            $topText = $automation['branding_text_top'] ?? 'Test Top';
            $bottomText = $automation['branding_text_bottom'] ?? 'Test Bottom';
            
            if (!empty($automation['ai_taglines_enabled']) && !empty($automation['ai_tagline_prompt'])) {
                logLine("Step 2: Generating AI Taglines...");
                try {
                    $ai = new AITaglineGenerator($pdo);
                    $taglines = $ai->generateTaglines($automation['ai_tagline_prompt'], $video['title']);
                    
                    if (isset($taglines['success']) && $taglines['success']) {
                        $topText = $taglines['top'];
                        $bottomText = $taglines['bottom'];
                        logLine("AI Top: {$topText}", 'success');
                        logLine("AI Bottom: {$bottomText}", 'success');
                    } else {
                        logLine("AI Error: " . ($taglines['error'] ?? 'Unknown'), 'error');
                    }
                } catch (Exception $e) {
                    logLine("AI Exception: " . $e->getMessage(), 'error');
                }
            } else {
                logLine("Step 2: AI Taglines disabled, using manual text", 'info');
            }
            
            // Step 3: FFmpeg Processing
            logLine("Step 3: Processing with FFmpeg...");
            $outputPath = $baseDir . '/output/test_short_' . time() . '.mp4';
            
            $ffmpeg = new FFmpegProcessor();
            if (!$ffmpeg->isAvailable()) {
                logLine("FFmpeg not available!", 'error');
            } else {
                logLine("Aspect ratio: {$automation['short_aspect_ratio']}", 'info');
                logLine("Duration: {$automation['short_duration']}s", 'info');
                logLine("Top text: {$topText}", 'info');
                logLine("Bottom text: {$bottomText}", 'info');
                
                $result = $ffmpeg->createShort($localPath, $outputPath, [
                    'duration' => $automation['short_duration'] ?? 60,
                    'aspectRatio' => $automation['short_aspect_ratio'] ?? '9:16',
                    'topText' => $topText,
                    'bottomText' => $bottomText
                ]);
                
                if ($result['success']) {
                    $size = number_format(filesize($outputPath) / 1024 / 1024, 2);
                    logLine("Short created: {$outputPath} ({$size} MB)", 'success');
                    logLine("<a href='player.php' target='_blank'>View in Player →</a>", 'success');
                } else {
                    logLine("FFmpeg Error: " . ($result['error'] ?? 'Unknown'), 'error');
                    if (isset($result['output'])) {
                        logLine("Output: " . substr($result['output'], -300), 'error');
                    }
                }
            }
            
            // Cleanup temp file
            @unlink($localPath);
        }
    }
    
    echo "</pre></div>";
} elseif ($testType === 'process') {
    echo "<div class='section'><h3>3. Ready to Process</h3>";
    echo "<p>This will download the first video and process it as a test.</p>";
    echo "<p><a class='btn' href='?id={$automationId}&test=process&confirm=1'>Start Processing Test</a></p>";
    echo "<p style='color:#fbbf24'>⚠️ This may take a few minutes depending on video size.</p>";
    echo "</div>";
}

// Automation Logs
echo "<div class='section'><h3>Recent Logs</h3><pre>";
$stmt = $pdo->prepare("SELECT * FROM automation_logs WHERE automation_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$automationId]);
$logs = $stmt->fetchAll();

if (empty($logs)) {
    logLine("No logs yet for this automation", 'info');
} else {
    foreach ($logs as $log) {
        $type = $log['status'] === 'error' ? 'error' : ($log['status'] === 'success' ? 'success' : 'info');
        logLine("[{$log['status']}] {$log['action']}: {$log['message']}", $type);
    }
}
echo "</pre></div>";

echo "<div class='section'>";
echo "<a class='btn' href='automation.php'>← Back to Automations</a> ";
echo "<a class='btn' href='player.php'>View Processed Videos</a> ";
echo "<a class='btn' href='settings.php'>Settings</a>";
echo "</div>";

echo "</body></html>";
