<?php
/**
 * DatabaseHelper Usage Examples
 * Demonstrates how to replace existing database calls with DatabaseHelper
 */

require_once 'config.php';
require_once 'includes/DatabaseHelper.php';

// Initialize the DatabaseHelper with the existing PDO connection
DatabaseHelper::initialize($pdo);

try {
    // Example 1: Get PostForMe API key (replacing PostForMeAPI.php usage)
    try {
        $postformeSettings = DatabaseHelper::getPostForMeSettings();
        echo "Post for Me API Key: " . substr($postformeSettings['api_key'], 0, 10) . "...\n";
        echo "Project Type: " . $postformeSettings['project_type'] . "\n";
    } catch (Exception $e) {
        echo "Post for Me not configured: " . $e->getMessage() . "\n";
    }

    // Example 2: Get FTP/Bunny CDN configuration (replacing FTPAPI.php usage)
    try {
        $ftpConfig = DatabaseHelper::getFtpBunnyConfig();
        echo "FTP/Bunny Config Type: " . $ftpConfig['type'] . "\n";
        
        if ($ftpConfig['type'] === 'ftp') {
            echo "FTP Host: " . $ftpConfig['host'] . ":" . $ftpConfig['port'] . "\n";
            echo "FTP Path: " . $ftpConfig['path'] . "\n";
        } else {
            echo "Bunny API Key: " . substr($ftpConfig['api_key'], 0, 10) . "...\n";
            echo "Library ID: " . $ftpConfig['library_id'] . "\n";
        }
    } catch (Exception $e) {
        echo "FTP/Bunny not configured: " . $e->getMessage() . "\n";
    }

    // Example 3: Get all settings (replacing settings.php usage)
    $allSettings = DatabaseHelper::getAllSettings();
    echo "Total settings: " . count($allSettings) . "\n";
    echo "YouTube API Key: " . ($allSettings['youtube_api_key'] ?? 'Not configured') . "\n";

    // Example 4: Get specific setting (replacing config.php usage)
    $ffmpegPath = DatabaseHelper::getSetting('ffmpeg_path', 'ffmpeg');
    echo "FFmpeg Path: " . $ffmpegPath . "\n";

    // Example 5: Set setting (replacing settings.php usage)
    $success = DatabaseHelper::setSetting('test_setting', 'test_value');
    echo "Setting saved: " . ($success ? 'Yes' : 'No') . "\n";

    // Example 6: Get active API keys (replacing api-keys.php usage)
    $activeKeys = DatabaseHelper::getActiveApiKeys();
    echo "Active API Keys: " . count($activeKeys) . "\n";
    
    if (!empty($activeKeys)) {
        echo "First key name: " . $activeKeys[0]['name'] . "\n";
    }

    // Example 7: Get automation settings (replacing automation.php usage)
    try {
        $automation = DatabaseHelper::getAutomationSettings(1);
        echo "Automation Name: " . $automation['name'] . "\n";
        echo "Status: " . $automation['status'] . "\n";
        echo "API Key Name: " . ($automation['key_name'] ?? 'None') . "\n";
    } catch (Exception $e) {
        echo "Automation not found: " . $e->getMessage() . "\n";
    }

    // Example 8: Get active automations (replacing automation.php usage)
    $activeAutomations = DatabaseHelper::getActiveAutomations();
    echo "Active Automations: " . count($activeAutomations) . "\n";

    // Example 9: Log automation action (replacing automation.php usage)
    $logSuccess = DatabaseHelper::logAutomationAction(
        1, 
        'video_processing', 
        'success', 
        'Video processed successfully',
        'video123',
        'youtube'
    );
    echo "Log saved: " . ($logSuccess ? 'Yes' : 'No') . "\n";

    // Example 10: Get Facebook scheduled posts (replacing scheduled-posts.php usage)
    $scheduledPosts = DatabaseHelper::getFacebookScheduledPosts();
    echo "Scheduled Posts: " . count($scheduledPosts) . "\n";

    // Example 11: Get due scheduled posts (replacing scheduled-posts.php usage)
    $duePosts = DatabaseHelper::getDueScheduledPosts(10);
    echo "Due Posts (next 10 min): " . count($duePosts) . "\n";

    // Example 12: Get Post for Me posts (replacing PostForMeAPI.php usage)
    $postformePosts = DatabaseHelper::getPostformePosts();
    echo "Post for Me Posts: " . count($postformePosts) . "\n";

    // Example 13: Get local agents (replacing local-agent.php usage)
    $localAgents = DatabaseHelper::getLocalAgents();
    echo "Local Agents: " . count($localAgents) . "\n";

    // Example 14: Get local agent jobs (replacing local-agent-job.php usage)
    $agentJobs = DatabaseHelper::getLocalAgentJobs();
    echo "Local Agent Jobs: " . count($agentJobs) . "\n";

    // Example 15: Get processed videos (replacing run-sync.php usage)
    $processedVideos = DatabaseHelper::getProcessedVideos();
    echo "Processed Videos: " . count($processedVideos) . "\n";

    // Example 16: Get prankwish taglines (replacing prankwish.php usage)
    $taglines = DatabaseHelper::getPrankwishTaglines();
    echo "Prankwish Taglines: " . count($taglines) . "\n";

    // Example 17: Get prankwish settings (replacing prankwish.php usage)
    $prankwishSettings = DatabaseHelper::getPrankwishSettings();
    echo "Prankwish Settings: " . count($prankwishSettings) . "\n";

    // Example 18: Get postforme accounts (replacing PostForMeAPI.php usage)
    $accounts = DatabaseHelper::getPostformeAccounts();
    echo "Post for Me Accounts: " . count($accounts) . "\n";

    // Example 19: Using transaction (replacing multiple operations)
    try {
        DatabaseHelper::transaction(function() {
            // Multiple operations in a single transaction
            DatabaseHelper::setSetting('transaction_test', 'value1');
            DatabaseHelper::logAutomationAction(1, 'test', 'info', 'Transaction test');
        });
        echo "Transaction completed successfully\n";
    } catch (Exception $e) {
        echo "Transaction failed: " . $e->getMessage() . "\n";
    }

    // Example 20: Error handling with custom exception
    try {
        // This will throw an exception
        DatabaseHelper::getAutomationSettings(99999); // Non-existent ID
    } catch (DatabaseHelperException $e) {
        echo "DatabaseHelper error: " . $e->getMessage() . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

/**
 * Example: Replacing existing database calls
 * 
 * Here are examples of how to replace existing database calls with DatabaseHelper:
 * 
 * 1. From api-keys.php:
 *    Before: $stmt = $pdo->query("SELECT * FROM api_keys ORDER BY created_at DESC");
 *    After:  $keys = DatabaseHelper::getActiveApiKeys();
 * 
 * 2. From settings.php:
 *    Before: $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
 *    After:  $value = DatabaseHelper::getSetting($key);
 * 
 * 3. From automation.php:
 *    Before: $stmt = $pdo->prepare("SELECT a.*, k.name as key_name FROM automation_settings a LEFT JOIN api_keys k ON a.api_key_id = k.id ORDER BY a.created_at DESC");
 *    After:  $automations = DatabaseHelper::getActiveAutomations();
 * 
 * 4. From run-sync.php:
 *    Before: $stmt = $pdo->prepare("SELECT v.*, s.name as automation_name FROM processed_videos v LEFT JOIN automation_settings s ON v.automation_id = s.id ORDER BY v.processed_at DESC LIMIT ?");
 *    After:  $videos = DatabaseHelper::getProcessedVideos(null, $limit);
 * 
 * 5. From PostForMeAPI.php:
 *    Before: $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('postforme_api_key', 'postforme_project_type')");
 *    After:  $settings = DatabaseHelper::getPostForMeSettings();
 */

/**
 * Additional benefits of using DatabaseHelper:
 * 
 * - Centralized error handling with meaningful exceptions
 * - Consistent query patterns and prepared statements
 * - Automatic connection management
 * - Transaction support for complex operations
 * - Type safety with proper PHPDoc comments
 * - Easy to add new methods for common operations
 * - Single point of maintenance for database operations
 * 
 * This class provides a complete replacement for all existing database operations
 * while maintaining backward compatibility and adding better error handling.
 */
?>