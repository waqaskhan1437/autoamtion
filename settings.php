<?php
require_once 'config.php';

$message = '';
$messageType = 'success';

// Load current settings
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Check FFmpeg status
$ffmpegAvailable = isFFmpegAvailable();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_bunny') {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['bunny_api_key', $_POST['bunny_api_key'] ?? '']);
        $stmt->execute(['bunny_library_id', $_POST['bunny_library_id'] ?? '']);
        $stmt->execute(['bunny_storage_zone', $_POST['bunny_storage_zone'] ?? '']);
        $stmt->execute(['bunny_storage_password', $_POST['bunny_storage_password'] ?? '']);
        $message = 'Bunny CDN settings saved';
        
    } elseif ($action === 'save_stream') {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['youtube_api_key', $_POST['youtube_api_key'] ?? '']);
        $stmt->execute(['youtube_client_id', $_POST['youtube_client_id'] ?? '']);
        $stmt->execute(['youtube_client_secret', $_POST['youtube_client_secret'] ?? '']);
        $stmt->execute(['tiktok_client_key', $_POST['tiktok_client_key'] ?? '']);
        $stmt->execute(['tiktok_client_secret', $_POST['tiktok_client_secret'] ?? '']);
        $stmt->execute(['instagram_app_id', $_POST['instagram_app_id'] ?? '']);
        $stmt->execute(['instagram_app_secret', $_POST['instagram_app_secret'] ?? '']);
        $stmt->execute(['facebook_app_id', $_POST['facebook_app_id'] ?? '']);
        $stmt->execute(['facebook_app_secret', $_POST['facebook_app_secret'] ?? '']);
        $message = 'Stream API settings saved';
        
    } elseif ($action === 'save_ftp') {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['ftp_host', $_POST['ftp_host'] ?? '']);
        $stmt->execute(['ftp_username', $_POST['ftp_username'] ?? '']);
        $stmt->execute(['ftp_password', $_POST['ftp_password'] ?? '']);
        $stmt->execute(['ftp_port', $_POST['ftp_port'] ?? '21']);
        $stmt->execute(['ftp_path', $_POST['ftp_path'] ?? '/']);
        $message = 'FTP settings saved';
        
    } elseif ($action === 'save_openai') {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['ai_provider', $_POST['ai_provider'] ?? 'gemini']);
        $stmt->execute(['gemini_api_key', $_POST['gemini_api_key'] ?? '']);
        $stmt->execute(['openai_api_key', $_POST['openai_api_key'] ?? '']);
        $stmt->execute(['default_language', $_POST['default_language'] ?? 'en']);
        $message = 'AI settings saved';
        
    } elseif ($action === 'save_ffmpeg') {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['ffmpeg_path', $_POST['ffmpeg_path'] ?? 'ffmpeg']);
        $message = 'FFmpeg settings saved';
        
    } elseif ($action === 'test_bunny') {
        $apiKey = $_POST['test_api_key'] ?? $settings['bunny_api_key'] ?? '';
        $libraryId = $_POST['test_library_id'] ?? $settings['bunny_library_id'] ?? '';
        
        if (!$apiKey || !$libraryId) {
            $message = 'Please enter API Key and Library ID first';
            $messageType = 'error';
        } else {
            $ch = curl_init("https://video.bunnycdn.com/library/{$libraryId}/videos?page=1&itemsPerPage=1");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['AccessKey: ' . $apiKey]
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                $count = $data['totalItems'] ?? 0;
                $message = "Bunny CDN connected! Found {$count} videos in library.";
            } else {
                $message = 'Bunny CDN connection failed. Check API key and Library ID.';
                $messageType = 'error';
            }
        }
        
    } elseif ($action === 'test_ftp') {
        $host = $_POST['test_host'] ?? $settings['ftp_host'] ?? '';
        $user = $_POST['test_user'] ?? $settings['ftp_username'] ?? '';
        $pass = $_POST['test_pass'] ?? $settings['ftp_password'] ?? '';
        $port = intval($_POST['test_port'] ?? $settings['ftp_port'] ?? 21);
        
        if (!$host || !$user) {
            $message = 'Please enter FTP host and username';
            $messageType = 'error';
        } else {
            $conn = @ftp_connect($host, $port, 10);
            if ($conn && @ftp_login($conn, $user, $pass)) {
                $message = 'FTP connection successful!';
                ftp_close($conn);
            } else {
                $message = 'FTP connection failed. Check credentials.';
                $messageType = 'error';
            }
        }
        
    } elseif ($action === 'test_openai') {
        $apiKey = $_POST['test_api_key'] ?? $settings['openai_api_key'] ?? '';
        
        if (!$apiKey) {
            $message = 'Please enter an API key first';
            $messageType = 'error';
        } else {
            $ch = curl_init('https://api.openai.com/v1/models');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey]
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $message = 'OpenAI API key is valid! Whisper ready.';
            } else {
                $message = 'Invalid API key or API error';
                $messageType = 'error';
            }
        }
        
    } elseif ($action === 'test_ffmpeg') {
        $ffmpegPath = $_POST['test_path'] ?? $settings['ffmpeg_path'] ?? 'ffmpeg';
        exec($ffmpegPath . ' -version 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            $version = isset($output[0]) ? preg_replace('/^ffmpeg version ([^ ]+).*/', '$1', $output[0]) : 'unknown';
            $message = "FFmpeg is working! Version: {$version}";
        } else {
            $message = 'FFmpeg not found at specified path';
            $messageType = 'error';
        }
    } elseif ($action === 'save_postforme') {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['postforme_api_key', $_POST['postforme_api_key'] ?? '']);
        $stmt->execute(['postforme_project_type', $_POST['postforme_project_type'] ?? 'quickstart']);
        $message = 'Post for Me settings saved';
        
    } elseif ($action === 'save_github_runner') {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['github_runner_enabled', isset($_POST['github_runner_enabled']) ? '1' : '0']);
        $stmt->execute(['github_runner_token', $_POST['github_runner_token'] ?? '']);
        $stmt->execute(['github_runner_owner', $_POST['github_runner_owner'] ?? '']);
        $stmt->execute(['github_runner_repo', $_POST['github_runner_repo'] ?? '']);
        $stmt->execute(['github_runner_workflow', $_POST['github_runner_workflow'] ?? 'automation-runner.yml']);
        $stmt->execute(['github_runner_ref', $_POST['github_runner_ref'] ?? 'main']);
        $stmt->execute(['github_runner_inputs_json', $_POST['github_runner_inputs_json'] ?? '']);
        $stmt->execute(['github_runner_callback_secret', $_POST['github_runner_callback_secret'] ?? '']);
        $stmt->execute(['ytdlp_cookies_file', $_POST['ytdlp_cookies_file'] ?? '']);
        $stmt->execute(['ytdlp_cookies_browser', $_POST['ytdlp_cookies_browser'] ?? '']);
        $stmt->execute(['ytdlp_cookies_browser_profile', $_POST['ytdlp_cookies_browser_profile'] ?? '']);
        $message = 'GitHub runner settings saved';
        
    } elseif ($action === 'test_github_runner') {
        require_once 'includes/GitHubRunner.php';
        $runner = new GitHubRunner($pdo);
        $result = $runner->testConnection();
        if ($result['success']) {
            $message = $result['message'] ?? 'GitHub runner connection OK';
        } else {
            $message = $result['error'] ?? 'GitHub runner test failed';
            $messageType = 'error';
        }
        
    } elseif ($action === 'test_postforme') {
        $apiKey = $_POST['test_api_key'] ?? $settings['postforme_api_key'] ?? '';
        
        if (!$apiKey) {
            $message = 'Please enter Post for Me API key first';
            $messageType = 'error';
        } else {
            require_once 'includes/PostForMeAPI.php';
            $api = new PostForMeAPI($apiKey);
            $result = $api->testConnection();
            
            if ($result['success']) {
                $message = "Post for Me connected! Found {$result['accounts_count']} connected social accounts.";
            } else {
                $message = 'Connection failed. Check API key.';
                $messageType = 'error';
            }
        }
        
    } elseif ($action === 'sync_postforme') {
        $apiKey = $settings['postforme_api_key'] ?? '';
        
        if (!$apiKey) {
            $message = 'Please configure Post for Me API key first';
            $messageType = 'error';
        } else {
            require_once 'includes/PostForMeAPI.php';
            $api = new PostForMeAPI($apiKey);
            $result = $api->getAccounts();
            
            if ($result['success']) {
                $synced = 0;
                foreach ($result['accounts'] as $account) {
                    $stmt = $pdo->prepare("INSERT INTO postforme_accounts (account_id, platform, account_name, username, profile_image_url, last_synced_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE account_name = VALUES(account_name), username = VALUES(username), profile_image_url = VALUES(profile_image_url), last_synced_at = NOW()");
                    $stmt->execute([
                        $account['id'],
                        $account['platform'] ?? 'unknown',
                        $account['name'] ?? $account['username'] ?? 'Unknown',
                        $account['username'] ?? '',
                        $account['profile_image_url'] ?? ''
                    ]);
                    $synced++;
                }
                $message = "Synced {$synced} social accounts from Post for Me";
            } else {
                $message = 'Failed to sync accounts: ' . ($result['error'] ?? 'Unknown error');
                $messageType = 'error';
            }
        }
        
    } elseif ($action === 'clear_temp') {
        // Clear temp directory
        $files = glob(TEMP_DIR . '/*');
        $count = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $count++;
            }
        }
        $message = "Cleared {$count} temporary files";
        
    } elseif ($action === 'open_folder') {
        // Generate path for user to copy
        $message = "Output folder: " . OUTPUT_DIR;
    }
    
    // Reload settings
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

$activeTab = $_GET['tab'] ?? 'bunny';

// Helper function to calculate folder size
function folderSize($dir) {
    $size = 0;
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
    }
    return $size;
}

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

include 'includes/header.php';
?>

<?php if ($message): ?>
    <script>document.addEventListener('DOMContentLoaded', () => showToast('<?= addslashes($message) ?>', '<?= $messageType ?>'));</script>
<?php endif; ?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-semibold">Settings</h2>
        <p class="text-sm text-gray-400 mt-1">Configure all API keys and system settings</p>
    </div>
</div>

<!-- Tab Navigation -->
<div class="flex flex-wrap gap-2 mb-6 border-b border-gray-800 pb-4">
    <a href="?tab=bunny" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $activeTab === 'bunny' ? 'bg-orange-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' ?>">
        <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994.021-.041.001-.09-.041-.106a13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
            Bunny CDN
        </span>
    </a>
    <a href="?tab=stream" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $activeTab === 'stream' ? 'bg-red-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' ?>">
        <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/></svg>
            Stream APIs
        </span>
    </a>
    <a href="?tab=ftp" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $activeTab === 'ftp' ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' ?>">
        <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"></path></svg>
            FTP Server
        </span>
    </a>
    <a href="?tab=openai" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $activeTab === 'openai' ? 'bg-purple-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' ?>">
        <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
            AI Settings
        </span>
    </a>
    <a href="?tab=ffmpeg" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $activeTab === 'ffmpeg' ? 'bg-purple-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' ?>">
        <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
            FFmpeg
        </span>
    </a>
    <a href="?tab=storage" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $activeTab === 'storage' ? 'bg-yellow-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' ?>">
        <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
            Storage
        </span>
    </a>
    <a href="?tab=github_runner" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $activeTab === 'github_runner' ? 'bg-sky-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' ?>">
        <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 .5C5.65.5.5 5.66.5 12.02c0 5.09 3.29 9.39 7.86 10.91.58.11.8-.25.8-.56v-2c-3.2.7-3.87-1.54-3.87-1.54-.52-1.34-1.28-1.69-1.28-1.69-1.05-.72.08-.7.08-.7 1.16.08 1.77 1.2 1.77 1.2 1.03 1.77 2.7 1.26 3.36.97.1-.75.4-1.26.72-1.55-2.55-.3-5.23-1.28-5.23-5.71 0-1.26.45-2.28 1.18-3.08-.12-.3-.51-1.52.11-3.16 0 0 .97-.31 3.18 1.17a10.96 10.96 0 0 1 5.79 0c2.21-1.48 3.18-1.17 3.18-1.17.62 1.64.23 2.86.11 3.16.73.8 1.18 1.82 1.18 3.08 0 4.44-2.69 5.41-5.26 5.7.41.35.78 1.04.78 2.1v3.12c0 .31.21.67.81.56 4.56-1.53 7.85-5.83 7.85-10.91C23.5 5.66 18.35.5 12 .5z"/></svg>
            GitHub Runner
        </span>
    </a>
    <a href="?tab=postforme" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?= $activeTab === 'postforme' ? 'bg-gradient-to-r from-pink-600 to-purple-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white' ?>">
        <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
            Post for Me
        </span>
    </a>
</div>

<!-- Bunny CDN Tab -->
<?php if ($activeTab === 'bunny'): ?>
<form method="POST">
    <input type="hidden" name="action" value="save_bunny">
    
    <div class="card rounded-lg mb-6">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="w-5 h-5 text-orange-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
                Bunny CDN Video Library
            </h3>
            <div class="flex items-center gap-2">
                <?php if (!empty($settings['bunny_api_key'])): ?>
                    <span class="px-2 py-1 bg-green-500/20 text-green-400 rounded text-xs">Connected</span>
                <?php else: ?>
                    <span class="px-2 py-1 bg-yellow-500/20 text-yellow-400 rounded text-xs">Not Configured</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="p-4 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">API Key *</label>
                    <input type="password" name="bunny_api_key" value="<?= htmlspecialchars($settings['bunny_api_key'] ?? '') ?>" placeholder="Enter Bunny API Key" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" data-testid="input-bunny-api-key">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Library ID *</label>
                    <input type="text" name="bunny_library_id" value="<?= htmlspecialchars($settings['bunny_library_id'] ?? '') ?>" placeholder="e.g., 123456" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" data-testid="input-bunny-library-id">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Storage Zone (for uploads)</label>
                    <input type="text" name="bunny_storage_zone" value="<?= htmlspecialchars($settings['bunny_storage_zone'] ?? '') ?>" placeholder="e.g., my-storage-zone" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Storage Password</label>
                    <input type="password" name="bunny_storage_password" value="<?= htmlspecialchars($settings['bunny_storage_password'] ?? '') ?>" placeholder="Storage zone password" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
            </div>
            <p class="text-xs text-gray-500">Get credentials from <a href="https://dash.bunny.net" target="_blank" class="text-orange-400 hover:underline">Bunny.net Dashboard</a> → Stream → API</p>
        </div>
    </div>
    
    <div class="flex gap-3">
        <button type="submit" class="flex-1 py-3 bg-orange-600 hover:bg-orange-700 rounded-lg font-medium" data-testid="button-save-bunny">
            Save Bunny Settings
        </button>
        <button type="submit" formaction="?tab=bunny" name="action" value="test_bunny" class="px-6 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg font-medium" data-testid="button-test-bunny">
            Test Connection
        </button>
    </div>
</form>
<?php endif; ?>

<!-- Stream APIs Tab -->
<?php if ($activeTab === 'stream'): ?>
<form method="POST">
    <input type="hidden" name="action" value="save_stream">
    
    <!-- YouTube -->
    <div class="card rounded-lg mb-4">
        <div class="p-4 border-b border-gray-800">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/></svg>
                YouTube API
                <?php if (!empty($settings['youtube_api_key'])): ?>
                    <span class="px-2 py-1 bg-green-500/20 text-green-400 rounded text-xs ml-auto">Configured</span>
                <?php endif; ?>
            </h3>
        </div>
        <div class="p-4 space-y-4">
            <div>
                <label class="block text-sm text-gray-400 mb-1">API Key</label>
                <input type="password" name="youtube_api_key" value="<?= htmlspecialchars($settings['youtube_api_key'] ?? '') ?>" placeholder="YouTube Data API Key" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">OAuth Client ID</label>
                    <input type="text" name="youtube_client_id" value="<?= htmlspecialchars($settings['youtube_client_id'] ?? '') ?>" placeholder="OAuth 2.0 Client ID" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">OAuth Client Secret</label>
                    <input type="password" name="youtube_client_secret" value="<?= htmlspecialchars($settings['youtube_client_secret'] ?? '') ?>" placeholder="OAuth 2.0 Client Secret" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
            </div>
            <p class="text-xs text-gray-500">Get from <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="text-red-400 hover:underline">Google Cloud Console</a></p>
        </div>
    </div>
    
    <!-- TikTok -->
    <div class="card rounded-lg mb-4">
        <div class="p-4 border-b border-gray-800">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-5.2 1.74 2.89 2.89 0 012.31-4.64 2.93 2.93 0 01.88.13V9.4a6.84 6.84 0 00-1-.05A6.33 6.33 0 005 20.1a6.34 6.34 0 0010.86-4.43v-7a8.16 8.16 0 004.77 1.52v-3.4a4.85 4.85 0 01-1-.1z"/></svg>
                TikTok API
                <?php if (!empty($settings['tiktok_client_key'])): ?>
                    <span class="px-2 py-1 bg-green-500/20 text-green-400 rounded text-xs ml-auto">Configured</span>
                <?php endif; ?>
            </h3>
        </div>
        <div class="p-4 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Client Key</label>
                    <input type="text" name="tiktok_client_key" value="<?= htmlspecialchars($settings['tiktok_client_key'] ?? '') ?>" placeholder="TikTok Client Key" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Client Secret</label>
                    <input type="password" name="tiktok_client_secret" value="<?= htmlspecialchars($settings['tiktok_client_secret'] ?? '') ?>" placeholder="TikTok Client Secret" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
            </div>
            <p class="text-xs text-gray-500">Get from <a href="https://developers.tiktok.com" target="_blank" class="text-gray-400 hover:underline">TikTok for Developers</a></p>
        </div>
    </div>
    
    <!-- Instagram -->
    <div class="card rounded-lg mb-4">
        <div class="p-4 border-b border-gray-800">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="w-5 h-5 text-pink-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073z"/></svg>
                Instagram API
                <?php if (!empty($settings['instagram_app_id'])): ?>
                    <span class="px-2 py-1 bg-green-500/20 text-green-400 rounded text-xs ml-auto">Configured</span>
                <?php endif; ?>
            </h3>
        </div>
        <div class="p-4 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">App ID</label>
                    <input type="text" name="instagram_app_id" value="<?= htmlspecialchars($settings['instagram_app_id'] ?? '') ?>" placeholder="Instagram App ID" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">App Secret</label>
                    <input type="password" name="instagram_app_secret" value="<?= htmlspecialchars($settings['instagram_app_secret'] ?? '') ?>" placeholder="Instagram App Secret" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
            </div>
            <p class="text-xs text-gray-500">Get from <a href="https://developers.facebook.com" target="_blank" class="text-pink-400 hover:underline">Meta for Developers</a></p>
        </div>
    </div>
    
    <!-- Facebook -->
    <div class="card rounded-lg mb-6">
        <div class="p-4 border-b border-gray-800">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 8h-3v4h3v12h5v-12h3.642l.358-4h-4v-1.667c0-.955.192-1.333 1.115-1.333h2.885v-5h-3.808c-3.596 0-5.192 1.583-5.192 4.615v3.385z"/></svg>
                Facebook API
                <?php if (!empty($settings['facebook_app_id'])): ?>
                    <span class="px-2 py-1 bg-green-500/20 text-green-400 rounded text-xs ml-auto">Configured</span>
                <?php endif; ?>
            </h3>
        </div>
        <div class="p-4 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">App ID</label>
                    <input type="text" name="facebook_app_id" value="<?= htmlspecialchars($settings['facebook_app_id'] ?? '') ?>" placeholder="Facebook App ID" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">App Secret</label>
                    <input type="password" name="facebook_app_secret" value="<?= htmlspecialchars($settings['facebook_app_secret'] ?? '') ?>" placeholder="Facebook App Secret" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
            </div>
            <p class="text-xs text-gray-500">Get from <a href="https://developers.facebook.com" target="_blank" class="text-blue-400 hover:underline">Meta for Developers</a></p>
        </div>
    </div>
    
    <button type="submit" class="w-full py-3 bg-red-600 hover:bg-red-700 rounded-lg font-medium" data-testid="button-save-stream">
        Save Stream API Settings
    </button>
</form>
<?php endif; ?>

<!-- FTP Tab -->
<?php if ($activeTab === 'ftp'): ?>
<form method="POST">
    <input type="hidden" name="action" value="save_ftp">
    
    <div class="card rounded-lg mb-6">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"></path></svg>
                FTP Server Settings
            </h3>
            <?php if (!empty($settings['ftp_host'])): ?>
                <span class="px-2 py-1 bg-green-500/20 text-green-400 rounded text-xs">Configured</span>
            <?php endif; ?>
        </div>
        <div class="p-4 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">FTP Host *</label>
                    <input type="text" name="ftp_host" value="<?= htmlspecialchars($settings['ftp_host'] ?? '') ?>" placeholder="ftp.example.com" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" data-testid="input-ftp-host">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Port</label>
                    <input type="number" name="ftp_port" value="<?= htmlspecialchars($settings['ftp_port'] ?? '21') ?>" placeholder="21" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Username *</label>
                    <input type="text" name="ftp_username" value="<?= htmlspecialchars($settings['ftp_username'] ?? '') ?>" placeholder="ftp_user" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Password</label>
                    <input type="password" name="ftp_password" value="<?= htmlspecialchars($settings['ftp_password'] ?? '') ?>" placeholder="FTP password" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Remote Path</label>
                <input type="text" name="ftp_path" value="<?= htmlspecialchars($settings['ftp_path'] ?? '/') ?>" placeholder="/public_html/videos/" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            </div>
        </div>
    </div>
    
    <div class="flex gap-3">
        <button type="submit" class="flex-1 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg font-medium" data-testid="button-save-ftp">
            Save FTP Settings
        </button>
        <button type="submit" formaction="?tab=ftp" name="action" value="test_ftp" class="px-6 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg font-medium" data-testid="button-test-ftp">
            Test Connection
        </button>
    </div>
</form>
<?php endif; ?>

<!-- OpenAI Tab -->
<?php if ($activeTab === 'openai'): ?>
<form method="POST">
    <input type="hidden" name="action" value="save_openai">
    
    <div class="card rounded-lg mb-6">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="w-5 h-5 text-purple-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                AI Settings (Taglines & Transcription)
            </h3>
            <?php if (!empty($settings['gemini_api_key']) || !empty($settings['openai_api_key'])): ?>
                <span class="px-2 py-1 bg-green-500/20 text-green-400 rounded text-xs">Configured</span>
            <?php endif; ?>
        </div>
        <div class="p-4 space-y-4">
            <!-- AI Provider Selection -->
            <div class="p-3 bg-purple-900/20 border border-purple-500/30 rounded-lg">
                <label class="block text-sm text-gray-400 mb-2">AI Provider for Taglines</label>
                <select name="ai_provider" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" data-testid="select-ai-provider">
                    <option value="gemini" <?= ($settings['ai_provider'] ?? 'gemini') === 'gemini' ? 'selected' : '' ?>>Google Gemini (FREE - Recommended)</option>
                    <option value="openai" <?= ($settings['ai_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI GPT-4o-mini (Paid)</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">Gemini is FREE with generous limits. Use OpenAI only if you have credits.</p>
            </div>
            
            <!-- Gemini API Key (FREE) -->
            <div class="p-3 bg-blue-900/20 border border-blue-500/30 rounded-lg">
                <label class="block text-sm text-gray-400 mb-1">Google Gemini API Key (FREE)</label>
                <input type="password" name="gemini_api_key" value="<?= htmlspecialchars($settings['gemini_api_key'] ?? '') ?>" placeholder="AIza..." class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" data-testid="input-gemini-key">
                <p class="text-xs text-gray-500 mt-1">Get FREE from <a href="https://aistudio.google.com/apikey" target="_blank" class="text-blue-400 hover:underline">aistudio.google.com/apikey</a> - No credit card needed!</p>
            </div>
            
            <!-- OpenAI API Key -->
            <div>
                <label class="block text-sm text-gray-400 mb-1">OpenAI API Key (for Whisper transcription)</label>
                <input type="password" name="openai_api_key" value="<?= htmlspecialchars($settings['openai_api_key'] ?? '') ?>" placeholder="sk-..." class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" data-testid="input-openai-key">
                <p class="text-xs text-gray-500 mt-1">Get from <a href="https://platform.openai.com/api-keys" target="_blank" class="text-green-400 hover:underline">platform.openai.com/api-keys</a></p>
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Default Transcription Language</label>
                <select name="default_language" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                    <option value="en" <?= ($settings['default_language'] ?? 'en') === 'en' ? 'selected' : '' ?>>English</option>
                    <option value="ur" <?= ($settings['default_language'] ?? '') === 'ur' ? 'selected' : '' ?>>Urdu</option>
                    <option value="hi" <?= ($settings['default_language'] ?? '') === 'hi' ? 'selected' : '' ?>>Hindi</option>
                    <option value="ar" <?= ($settings['default_language'] ?? '') === 'ar' ? 'selected' : '' ?>>Arabic</option>
                    <option value="es" <?= ($settings['default_language'] ?? '') === 'es' ? 'selected' : '' ?>>Spanish</option>
                    <option value="fr" <?= ($settings['default_language'] ?? '') === 'fr' ? 'selected' : '' ?>>French</option>
                    <option value="de" <?= ($settings['default_language'] ?? '') === 'de' ? 'selected' : '' ?>>German</option>
                    <option value="zh" <?= ($settings['default_language'] ?? '') === 'zh' ? 'selected' : '' ?>>Chinese</option>
                    <option value="ja" <?= ($settings['default_language'] ?? '') === 'ja' ? 'selected' : '' ?>>Japanese</option>
                    <option value="ko" <?= ($settings['default_language'] ?? '') === 'ko' ? 'selected' : '' ?>>Korean</option>
                </select>
            </div>
            
            <div class="p-4 bg-gray-800/50 rounded-lg">
                <h4 class="font-medium mb-2 flex items-center gap-2">
                    <svg class="w-4 h-4 text-green-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                    Whisper Transcription
                </h4>
                <p class="text-sm text-gray-400">Whisper AI will automatically transcribe audio from videos and generate subtitles in ASS format that can be burned into the video.</p>
            </div>
        </div>
    </div>
    
    <div class="flex gap-3">
        <button type="submit" class="flex-1 py-3 bg-green-600 hover:bg-green-700 rounded-lg font-medium" data-testid="button-save-openai">
            Save OpenAI Settings
        </button>
        <button type="submit" formaction="?tab=openai" name="action" value="test_openai" class="px-6 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg font-medium" data-testid="button-test-openai">
            Test API Key
        </button>
    </div>
</form>
<?php endif; ?>

<!-- Storage Tab -->
<?php if ($activeTab === 'storage'): ?>
<form method="POST">
    <input type="hidden" name="action" value="save_storage">
    
    <div class="card rounded-lg mb-6">
        <div class="p-4 border-b border-gray-800">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                Storage Locations
            </h3>
            <p class="text-sm text-gray-400 mt-1">All files are stored outside code directory to keep it clean</p>
        </div>
        <div class="p-4 space-y-4">
            <div>
                <label class="block text-sm text-gray-400 mb-1">Base Storage Path</label>
                <input type="text" name="storage_base_path" value="<?= htmlspecialchars($settings['storage_base_path'] ?? BASE_DATA_DIR) ?>" placeholder="C:\VideoWorkflow" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" data-testid="input-storage-path">
                <p class="text-xs text-gray-500 mt-1">Default: C:\VideoWorkflow (Windows) or ~/VideoWorkflow (Mac/Linux)</p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 bg-gray-800/50 rounded-lg">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path></svg>
                        <span class="font-medium">Downloads (temp)</span>
                    </div>
                    <code class="text-xs text-gray-400 break-all"><?= TEMP_DIR ?></code>
                    <?php $tempSize = is_dir(TEMP_DIR) ? folderSize(TEMP_DIR) : 0; ?>
                    <div class="text-xs text-gray-500 mt-1"><?= formatBytes($tempSize) ?> used</div>
                </div>
                
                <div class="p-4 bg-gray-800/50 rounded-lg">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>
                        <span class="font-medium">Output (shorts)</span>
                    </div>
                    <code class="text-xs text-gray-400 break-all"><?= OUTPUT_DIR ?></code>
                    <?php $outputSize = is_dir(OUTPUT_DIR) ? folderSize(OUTPUT_DIR) : 0; ?>
                    <div class="text-xs text-gray-500 mt-1"><?= formatBytes($outputSize) ?> used</div>
                </div>
                
                <div class="p-4 bg-gray-800/50 rounded-lg">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path></svg>
                        <span class="font-medium">Subtitles</span>
                    </div>
                    <code class="text-xs text-gray-400 break-all"><?= SUBTITLES_DIR ?></code>
                    <?php $subSize = is_dir(SUBTITLES_DIR) ? folderSize(SUBTITLES_DIR) : 0; ?>
                    <div class="text-xs text-gray-500 mt-1"><?= formatBytes($subSize) ?> used</div>
                </div>
                
                <div class="p-4 bg-gray-800/50 rounded-lg">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        <span class="font-medium">Logs</span>
                    </div>
                    <code class="text-xs text-gray-400 break-all"><?= LOGS_DIR ?></code>
                    <?php $logSize = is_dir(LOGS_DIR) ? folderSize(LOGS_DIR) : 0; ?>
                    <div class="text-xs text-gray-500 mt-1"><?= formatBytes($logSize) ?> used</div>
                </div>
            </div>
            
            <div class="p-4 bg-yellow-500/10 border border-yellow-500/20 rounded-lg">
                <h4 class="font-medium flex items-center gap-2 mb-2">
                    <svg class="w-4 h-4 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                    Path Change Note
                </h4>
                <p class="text-sm text-gray-400">Changing storage path requires editing config.php file directly. Existing files won't be moved automatically.</p>
            </div>
        </div>
    </div>
    
    <div class="flex gap-3">
        <button type="submit" formaction="?tab=storage" name="action" value="clear_temp" class="flex-1 py-3 bg-red-600 hover:bg-red-700 rounded-lg font-medium" data-testid="button-clear-temp" onclick="return confirm('Delete all temporary files?')">
            Clear Temp Files
        </button>
        <button type="submit" formaction="?tab=storage" name="action" value="open_folder" class="flex-1 py-3 bg-yellow-600 hover:bg-yellow-700 rounded-lg font-medium" data-testid="button-open-folder">
            Open Output Folder
        </button>
    </div>
</form>
<?php endif; ?>

<!-- FFmpeg Tab -->
<?php if ($activeTab === 'ffmpeg'): ?>
<form method="POST">
    <input type="hidden" name="action" value="save_ffmpeg">
    
    <!-- Status Card -->
    <div class="card rounded-lg p-4 mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-lg flex items-center justify-center <?= $ffmpegAvailable ? 'bg-green-500/10' : 'bg-red-500/10' ?>">
                    <svg class="w-6 h-6 <?= $ffmpegAvailable ? 'text-green-500' : 'text-red-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                </div>
                <div>
                    <h3 class="font-semibold">FFmpeg Status</h3>
                    <div class="text-sm <?= $ffmpegAvailable ? 'text-green-400' : 'text-red-400' ?>">
                        <?= $ffmpegAvailable ? 'Installed and working' : 'Not found - install FFmpeg' ?>
                    </div>
                </div>
            </div>
            <button type="submit" formaction="?tab=ffmpeg" name="action" value="test_ffmpeg" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 rounded-lg font-medium" data-testid="button-test-ffmpeg">
                Test FFmpeg
            </button>
        </div>
    </div>
    
    <div class="card rounded-lg mb-6">
        <div class="p-4 border-b border-gray-800">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="w-5 h-5 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                FFmpeg Configuration
            </h3>
        </div>
        <div class="p-4 space-y-4">
            <div>
                <label class="block text-sm text-gray-400 mb-1">FFmpeg Path</label>
                <input type="text" name="ffmpeg_path" value="<?= htmlspecialchars($settings['ffmpeg_path'] ?? 'ffmpeg') ?>" placeholder="ffmpeg" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" data-testid="input-ffmpeg-path">
                <p class="text-xs text-gray-500 mt-1">Leave as "ffmpeg" if in PATH, otherwise full path like "C:\ffmpeg\bin\ffmpeg.exe"</p>
            </div>
            
            <div class="p-4 bg-purple-500/10 border border-purple-500/20 rounded-lg">
                <h4 class="font-medium mb-3 flex items-center gap-2">
                    <svg class="w-4 h-4 text-purple-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                    FFmpeg Installation Guide (Windows)
                </h4>
                <ol class="text-sm text-gray-400 space-y-2 list-decimal list-inside">
                    <li>Download from <a href="https://www.gyan.dev/ffmpeg/builds/" target="_blank" class="text-purple-400 hover:underline">gyan.dev/ffmpeg/builds</a> (ffmpeg-release-essentials.zip)</li>
                    <li>Extract to <code class="bg-gray-800 px-1 rounded">C:\ffmpeg</code></li>
                    <li>Add <code class="bg-gray-800 px-1 rounded">C:\ffmpeg\bin</code> to System PATH:
                        <ul class="ml-5 mt-1 space-y-1 list-disc">
                            <li>Search "Environment Variables" in Windows</li>
                            <li>Edit "Path" under System Variables</li>
                            <li>Add new: C:\ffmpeg\bin</li>
                        </ul>
                    </li>
                    <li>Restart XAMPP and browser</li>
                    <li>Click "Test FFmpeg" to verify</li>
                </ol>
            </div>
        </div>
    </div>
    
    <button type="submit" class="w-full py-3 bg-purple-600 hover:bg-purple-700 rounded-lg font-medium" data-testid="button-save-ffmpeg">
        Save FFmpeg Settings
    </button>
</form>
<?php endif; ?>

<!-- Post for Me Tab -->
<?php if ($activeTab === 'github_runner'): ?>
<form method="POST">
    <input type="hidden" name="action" value="save_github_runner">

    <div class="card rounded-lg mb-6">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="w-5 h-5 text-sky-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 .5C5.65.5.5 5.66.5 12.02c0 5.09 3.29 9.39 7.86 10.91.58.11.8-.25.8-.56v-2c-3.2.7-3.87-1.54-3.87-1.54-.52-1.34-1.28-1.69-1.28-1.69-1.05-.72.08-.7.08-.7 1.16.08 1.77 1.2 1.77 1.2 1.03 1.77 2.7 1.26 3.36.97.1-.75.4-1.26.72-1.55-2.55-.3-5.23-1.28-5.23-5.71 0-1.26.45-2.28 1.18-3.08-.12-.3-.51-1.52.11-3.16 0 0 .97-.31 3.18 1.17a10.96 10.96 0 0 1 5.79 0c2.21-1.48 3.18-1.17 3.18-1.17.62 1.64.23 2.86.11 3.16.73.8 1.18 1.82 1.18 3.08 0 4.44-2.69 5.41-5.26 5.7.41.35.78 1.04.78 2.1v3.12c0 .31.21.67.81.56 4.56-1.53 7.85-5.83 7.85-10.91C23.5 5.66 18.35.5 12 .5z"/></svg>
                GitHub Runner Dispatch
            </h3>
            <?php if (!empty($settings['github_runner_enabled']) && !empty($settings['github_runner_token']) && !empty($settings['github_runner_owner']) && !empty($settings['github_runner_repo'])): ?>
                <span class="px-2 py-1 bg-green-500/20 text-green-400 rounded text-xs">Configured</span>
            <?php else: ?>
                <span class="px-2 py-1 bg-yellow-500/20 text-yellow-400 rounded text-xs">Needs Setup</span>
            <?php endif; ?>
        </div>
        <div class="p-4 space-y-4">
            <label class="flex items-center gap-2">
                <input type="checkbox" name="github_runner_enabled" value="1" class="w-4 h-4" <?= !empty($settings['github_runner_enabled']) ? 'checked' : '' ?>>
                <span class="text-sm">Enable GitHub Runner mode</span>
            </label>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">GitHub Owner *</label>
                    <input type="text" name="github_runner_owner" value="<?= htmlspecialchars($settings['github_runner_owner'] ?? '') ?>" placeholder="e.g. waqaskhan1437" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Repository *</label>
                    <input type="text" name="github_runner_repo" value="<?= htmlspecialchars($settings['github_runner_repo'] ?? '') ?>" placeholder="e.g. autoamtion" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Workflow File *</label>
                    <input type="text" name="github_runner_workflow" value="<?= htmlspecialchars($settings['github_runner_workflow'] ?? 'automation-runner.yml') ?>" placeholder="automation-runner.yml" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Branch / Ref *</label>
                    <input type="text" name="github_runner_ref" value="<?= htmlspecialchars($settings['github_runner_ref'] ?? 'main') ?>" placeholder="main" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-400 mb-1">GitHub Token *</label>
                <input type="password" name="github_runner_token" value="<?= htmlspecialchars($settings['github_runner_token'] ?? '') ?>" placeholder="PAT with actions/workflow access" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                <p class="text-xs text-gray-500 mt-1">Token is stored in app settings DB. Prefer fine-grained token with repo Actions permissions.</p>
            </div>

            <div>
                <label class="block text-sm text-gray-400 mb-1">Extra Inputs JSON (optional)</label>
                <textarea name="github_runner_inputs_json" rows="3" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" placeholder='{"env":"prod"}'><?= htmlspecialchars($settings['github_runner_inputs_json'] ?? '') ?></textarea>
                <p class="text-xs text-gray-500 mt-1">These values are merged into workflow_dispatch inputs.</p>
            </div>

            <div>
                <label class="block text-sm text-gray-400 mb-1">Callback Secret (optional)</label>
                <input type="text" name="github_runner_callback_secret" value="<?= htmlspecialchars($settings['github_runner_callback_secret'] ?? '') ?>" placeholder="Used by /api/github-runner-callback.php" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            </div>

            <div>
                <label class="block text-sm text-gray-400 mb-1">Local yt-dlp Cookies File (optional)</label>
                <input type="text" name="ytdlp_cookies_file" value="<?= htmlspecialchars($settings['ytdlp_cookies_file'] ?? '') ?>" placeholder="C:\Users\YourName\Downloads\youtube-cookies.txt" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                <p class="text-xs text-gray-500 mt-1">Ye sirf local machine par use hota hai. GitHub runner ke liye same cookies file ko repo secret <code>YTDLP_COOKIES_B64</code> ke naam se add karein.</p>
            </div>

            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Local yt-dlp Browser (optional)</label>
                    <input type="text" name="ytdlp_cookies_browser" value="<?= htmlspecialchars($settings['ytdlp_cookies_browser'] ?? '') ?>" placeholder="chrome" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                    <p class="text-xs text-gray-500 mt-1">Trusted local ya self-hosted Windows runner par direct browser cookies use karne ke liye. Example: <code>chrome</code>.</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Browser Profile (optional)</label>
                    <input type="text" name="ytdlp_cookies_browser_profile" value="<?= htmlspecialchars($settings['ytdlp_cookies_browser_profile'] ?? '') ?>" placeholder="Default" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                    <p class="text-xs text-gray-500 mt-1">Chrome profile folder name. Example: <code>Default</code> ya <code>Profile 1</code>.</p>
                </div>
            </div>

            <div class="p-3 bg-sky-500/10 border border-sky-500/20 rounded text-xs text-sky-200">
                Create automations with <strong>Runner Mode = GitHub Runner</strong>. Manual Run and Cron will dispatch the GitHub workflow.
            </div>
            <div class="p-3 bg-amber-500/10 border border-amber-500/20 rounded text-xs text-amber-100">
                <strong>YouTube cookies setup:</strong> browser se <code>cookies.txt</code> export karein, local path upar save karein, aur GitHub repo Settings → Secrets and variables → Actions mein <code>YTDLP_COOKIES_B64</code> secret set karein.
                Base64 banane ke liye PowerShell:
                <code>[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes((Get-Content -Raw 'C:\path\youtube-cookies.txt')))</code>
            </div>
            <div class="p-3 bg-emerald-500/10 border border-emerald-500/20 rounded text-xs text-emerald-100">
                <strong>Local/self-hosted shortcut:</strong> trusted Windows machine par <code>Local yt-dlp Browser</code> mein <code>chrome</code> aur profile mein <code>Default</code> save karein. Is surat mein exported <code>cookies.txt</code> ki zarurat nahi hoti, lekin Chrome login tumhare account se hi hoga.
            </div>
        </div>
    </div>

    <div class="flex gap-3">
        <button type="submit" class="flex-1 py-3 bg-sky-600 hover:bg-sky-700 rounded-lg font-medium">
            Save GitHub Runner Settings
        </button>
        <button type="submit" formaction="?tab=github_runner" name="action" value="test_github_runner" class="px-6 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg font-medium">
            Test Connection
        </button>
    </div>
</form>
<?php endif; ?>

<?php if ($activeTab === 'postforme'): ?>
<?php
// Get connected accounts from database
$connectedAccounts = [];
try {
    $stmt = $pdo->query("SELECT * FROM postforme_accounts WHERE is_active = 1 ORDER BY platform, account_name");
    $connectedAccounts = $stmt->fetchAll();
} catch (Exception $e) {
    // Table might not exist yet
}

// Get supported platforms
require_once 'includes/PostForMeAPI.php';
$platforms = PostForMeAPI::getSupportedPlatforms();
?>
<form method="POST">
    <input type="hidden" name="action" value="save_postforme">
    
    <!-- Main Info Card -->
    <div class="card rounded-lg mb-6 bg-gradient-to-r from-pink-900/30 to-purple-900/30 border border-pink-500/30">
        <div class="p-4 border-b border-pink-500/30 flex items-center justify-between">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="w-5 h-5 text-pink-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                Post for Me - Unified Social Media API
            </h3>
            <div class="flex items-center gap-2">
                <?php if (!empty($settings['postforme_api_key'])): ?>
                    <span class="px-2 py-1 bg-green-500/20 text-green-400 rounded text-xs">Connected</span>
                <?php else: ?>
                    <span class="px-2 py-1 bg-yellow-500/20 text-yellow-400 rounded text-xs">Not Configured</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="p-4 space-y-4">
            <div class="p-3 bg-blue-900/30 border border-blue-500/30 rounded-lg">
                <h4 class="font-medium text-blue-400 mb-2">Why Post for Me?</h4>
                <ul class="text-sm text-gray-300 space-y-1">
                    <li>✅ <strong>No Developer Apps Required</strong> - Use Quickstart mode to post immediately</li>
                    <li>✅ <strong>One API for All Platforms</strong> - YouTube, TikTok, Instagram, Facebook, Threads, Pinterest, LinkedIn</li>
                    <li>✅ <strong>$10/month for 1000 posts</strong> - Affordable pricing with no hidden fees</li>
                    <li>✅ <strong>Handles OAuth & Tokens</strong> - No need to manage refresh tokens yourself</li>
                </ul>
            </div>
            
            <div>
                <label class="block text-sm text-gray-400 mb-1">API Key *</label>
                <input type="password" name="postforme_api_key" value="<?= htmlspecialchars($settings['postforme_api_key'] ?? '') ?>" placeholder="Enter your Post for Me API Key" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:border-pink-500" data-testid="input-postforme-api-key">
                <p class="text-xs text-gray-500 mt-1">Get from <a href="https://app.postforme.dev" target="_blank" class="text-pink-400 hover:underline">app.postforme.dev</a> → Create Project → Copy API Key</p>
            </div>
            
            <div>
                <label class="block text-sm text-gray-400 mb-1">Project Type</label>
                <select name="postforme_project_type" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                    <option value="quickstart" <?= ($settings['postforme_project_type'] ?? 'quickstart') === 'quickstart' ? 'selected' : '' ?>>Quickstart (No developer apps needed - RECOMMENDED)</option>
                    <option value="whitelabel" <?= ($settings['postforme_project_type'] ?? '') === 'whitelabel' ? 'selected' : '' ?>>White Label (Your own developer apps)</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">Quickstart uses Post for Me's credentials - start posting immediately without platform approval</p>
            </div>
        </div>
    </div>
    
    <!-- Connected Accounts Card -->
    <div class="card rounded-lg mb-6">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="font-semibold flex items-center gap-2">
                <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                Connected Social Accounts
                <?php if (count($connectedAccounts) > 0): ?>
                    <span class="px-2 py-0.5 bg-green-500/20 text-green-400 rounded text-xs"><?= count($connectedAccounts) ?></span>
                <?php endif; ?>
            </h3>
            <button type="submit" formaction="?tab=postforme" name="action" value="sync_postforme" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 rounded text-sm">
                Sync Accounts
            </button>
        </div>
        <div class="p-4">
            <?php if (empty($connectedAccounts)): ?>
                <div class="text-center py-8">
                    <svg class="w-12 h-12 mx-auto text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <p class="text-gray-400 mb-3">No social accounts connected yet</p>
                    <a href="https://app.postforme.dev" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-pink-600 hover:bg-pink-700 rounded-lg text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                        Connect Accounts in Post for Me Dashboard
                    </a>
                    <p class="text-xs text-gray-500 mt-3">After connecting, click "Sync Accounts" to import them here</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php foreach ($connectedAccounts as $account): ?>
                        <?php $platformInfo = $platforms[$account['platform']] ?? ['name' => ucfirst($account['platform']), 'color' => 'gray']; ?>
                        <div class="p-3 bg-gray-800/50 rounded-lg border border-gray-700 flex items-center gap-3">
                            <?php if (!empty($account['profile_image_url'])): ?>
                                <img src="<?= htmlspecialchars($account['profile_image_url']) ?>" alt="" class="w-10 h-10 rounded-full">
                            <?php else: ?>
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-pink-500 to-purple-500 flex items-center justify-center text-white font-bold">
                                    <?= strtoupper(substr($account['account_name'] ?? 'U', 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium truncate"><?= htmlspecialchars($account['account_name'] ?? 'Unknown') ?></div>
                                <div class="text-xs text-gray-400 flex items-center gap-1">
                                    <span class="px-1.5 py-0.5 rounded bg-<?= $platformInfo['color'] ?>-500/20 text-<?= $platformInfo['color'] ?>-400"><?= $platformInfo['name'] ?></span>
                                    <?php if (!empty($account['username'])): ?>
                                        <span class="truncate">@<?= htmlspecialchars($account['username']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-500 mt-3">To connect more accounts, visit <a href="https://app.postforme.dev" target="_blank" class="text-pink-400 hover:underline">Post for Me Dashboard</a> and then sync again</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Supported Platforms -->
    <div class="card rounded-lg mb-6">
        <div class="p-4 border-b border-gray-800">
            <h3 class="font-semibold">Supported Platforms</h3>
        </div>
        <div class="p-4">
            <div class="grid grid-cols-3 md:grid-cols-5 gap-3">
                <div class="p-3 bg-red-500/10 rounded-lg text-center">
                    <svg class="w-8 h-8 mx-auto text-red-500 mb-1" fill="currentColor" viewBox="0 0 24 24"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/></svg>
                    <div class="text-xs">YouTube</div>
                </div>
                <div class="p-3 bg-gray-700/50 rounded-lg text-center">
                    <svg class="w-8 h-8 mx-auto mb-1" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-5.2 1.74 2.89 2.89 0 012.31-4.64 2.93 2.93 0 01.88.13V9.4a6.84 6.84 0 00-1-.05A6.33 6.33 0 005 20.1a6.34 6.34 0 0010.86-4.43v-7a8.16 8.16 0 004.77 1.52v-3.4a4.85 4.85 0 01-1-.1z"/></svg>
                    <div class="text-xs">TikTok</div>
                </div>
                <div class="p-3 bg-pink-500/10 rounded-lg text-center">
                    <svg class="w-8 h-8 mx-auto text-pink-500 mb-1" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069z"/></svg>
                    <div class="text-xs">Instagram</div>
                </div>
                <div class="p-3 bg-blue-500/10 rounded-lg text-center">
                    <svg class="w-8 h-8 mx-auto text-blue-500 mb-1" fill="currentColor" viewBox="0 0 24 24"><path d="M9 8h-3v4h3v12h5v-12h3.642l.358-4h-4v-1.667c0-.955.192-1.333 1.115-1.333h2.885v-5h-3.808c-3.596 0-5.192 1.583-5.192 4.615v3.385z"/></svg>
                    <div class="text-xs">Facebook</div>
                </div>
                <div class="p-3 bg-gray-500/10 rounded-lg text-center">
                    <svg class="w-8 h-8 mx-auto text-gray-400 mb-1" fill="currentColor" viewBox="0 0 24 24"><path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.5 12.186V12c.018-3.724 1.084-6.567 3.168-8.454C6.553 1.706 9.263 1 12 1c2.732 0 5.428.702 7.317 2.548 2.085 1.892 3.152 4.733 3.183 8.452v.186c-.017 3.712-1.079 6.554-3.157 8.446C17.459 22.5 14.778 23.2 12.186 24z"/></svg>
                    <div class="text-xs">Threads</div>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-3 text-center">Also supports: X (Twitter), LinkedIn, Pinterest, Bluesky</p>
        </div>
    </div>
    
    <div class="flex gap-3">
        <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-pink-600 to-purple-600 hover:from-pink-700 hover:to-purple-700 rounded-lg font-medium" data-testid="button-save-postforme">
            Save Post for Me Settings
        </button>
        <button type="submit" formaction="?tab=postforme" name="action" value="test_postforme" class="px-6 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg font-medium" data-testid="button-test-postforme">
            Test Connection
        </button>
    </div>
</form>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
