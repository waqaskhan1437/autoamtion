<?php
/**
 * Direct Run Automation (NOT background)
 * Use this for debugging - runs in foreground, shows all errors
 */
set_time_limit(300);
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain');
echo "=== DIRECT RUN DEBUG MODE ===\n\n";

$automationId = $_GET['id'] ?? null;
if (!$automationId) {
    die("ERROR: No automation ID provided. Use: ?id=1\n");
}

echo "Automation ID: $automationId\n\n";

// Step 1: Load config
echo "1. Loading config...\n";
try {
    require_once __DIR__ . '/../config.php';
    echo "   Config loaded OK\n";
} catch (Exception $e) {
    die("   ERROR: " . $e->getMessage() . "\n");
}

// Step 2: Check database
echo "\n2. Testing database...\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM automation_settings");
    $count = $stmt->fetchColumn();
    echo "   Database OK - Found $count automations\n";
} catch (Exception $e) {
    die("   ERROR: " . $e->getMessage() . "\n");
}

// Step 3: Get automation
echo "\n3. Getting automation details...\n";
try {
    $stmt = $pdo->prepare("SELECT a.*, k.api_key, k.library_id, k.storage_zone, k.ftp_host, k.ftp_username, k.ftp_password FROM automation_settings a LEFT JOIN api_keys k ON a.api_key_id = k.id WHERE a.id = ?");
    $stmt->execute([$automationId]);
    $automation = $stmt->fetch();
    
    if (!$automation) {
        die("   ERROR: Automation not found\n");
    }
    echo "   Found: {$automation['name']}\n";
    echo "   API Key ID: " . ($automation['api_key_id'] ?: 'NONE') . "\n";
    echo "   FTP Host: " . ($automation['ftp_host'] ?: 'NOT SET') . "\n";
} catch (Exception $e) {
    die("   ERROR: " . $e->getMessage() . "\n");
}

// Step 4: Load classes
echo "\n4. Loading classes...\n";
try {
    require_once __DIR__ . '/../includes/FTPAPI.php';
    echo "   FTPAPI loaded\n";
    require_once __DIR__ . '/../includes/FFmpegProcessor.php';
    echo "   FFmpegProcessor loaded\n";
} catch (Exception $e) {
    die("   ERROR: " . $e->getMessage() . "\n");
}

// Step 5: Check FFmpeg
echo "\n5. Checking FFmpeg...\n";
try {
    $ffmpeg = new FFmpegProcessor();
    if ($ffmpeg->isAvailable()) {
        echo "   FFmpeg is available\n";
    } else {
        echo "   WARNING: FFmpeg not found!\n";
    }
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// Step 6: Test FTP connection
echo "\n6. Testing FTP/API connection...\n";
if (!$automation['api_key_id']) {
    echo "   WARNING: No API key selected for this automation!\n";
    echo "   Please edit the automation and select an API key.\n";
} else {
    try {
        $ftp = new FTPAPI($pdo);
        echo "   FTPAPI initialized\n";
        
        $videos = $ftp->getVideos($automation['video_days_filter'] ?? 30);
        echo "   SUCCESS! Found " . count($videos) . " videos\n";
        
        if (count($videos) > 0) {
            echo "\n   First 3 videos:\n";
            foreach (array_slice($videos, 0, 3) as $i => $video) {
                $name = is_array($video) ? ($video['name'] ?? $video['ObjectName'] ?? 'unknown') : $video;
                echo "   " . ($i+1) . ". $name\n";
            }
        }
    } catch (Exception $e) {
        echo "   ERROR: " . $e->getMessage() . "\n";
    }
}

// Step 7: Update progress test
echo "\n7. Testing progress update...\n";
try {
    // Check if columns exist
    $stmt = $pdo->query("SHOW COLUMNS FROM automation_settings LIKE 'progress_percent'");
    if ($stmt->rowCount() > 0) {
        echo "   progress_percent column exists\n";
        
        $stmt = $pdo->prepare("UPDATE automation_settings SET progress_percent = 1, progress_data = ? WHERE id = ?");
        $stmt->execute([json_encode(['test' => 'debug']), $automationId]);
        echo "   Progress update OK\n";
    } else {
        echo "   WARNING: progress_percent column missing! Run upgrade-database.sql\n";
    }
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== DEBUG COMPLETE ===\n";
echo "\nIf everything shows OK above, the background process should work.\n";
echo "Check: php-version/logs/ folder for log files.\n";
