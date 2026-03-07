<?php
require_once 'config.php';
require_once 'includes/BunnyAPI.php';
require_once 'includes/FTPAPI.php';
require_once 'includes/YouTubeSource.php';

header('Content-Type: application/json');

$source = $_GET['source'] ?? 'ftp';
$automationId = $_GET['id'] ?? null;

function parseManualLinksForTest($rawLinks) {
    $raw = is_string($rawLinks) ? $rawLinks : (string)$rawLinks;
    $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[\n,]+/', $raw) ?: [];
    $seen = [];
    $links = [];
    foreach ($parts as $part) {
        $url = trim((string)$part);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $links[] = $url;
    }
    return $links;
}

function getYouTubeChannelUrlForTest($automation) {
    $url = trim((string)($automation['youtube_channel_url'] ?? ''));
    if ($url === '') {
        $fallback = trim((string)($automation['manual_video_links'] ?? ''));
        if ($fallback !== '') {
            $parts = preg_split('/[\r\n,]+/', $fallback) ?: [];
            $url = trim((string)($parts[0] ?? ''));
        }
    }
    return $url;
}

try {
    if ($source === 'ftp') {
        // Get FTP settings first to show them
        $settings = [];
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'ftp_%'");
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        if (empty($settings['ftp_host'])) {
            echo json_encode([
                'success' => false,
                'error' => 'FTP not configured',
                'message' => 'Go to Settings → FTP tab and add your Bunny Storage credentials',
                'required' => [
                    'ftp_host' => 'storage.bunnycdn.com (or regional like ny.storage.bunnycdn.com)',
                    'ftp_username' => 'Your Storage Zone Name',
                    'ftp_password' => 'Your Storage Zone Password/Access Key',
                    'ftp_path' => '/ or /folder/'
                ]
            ], JSON_PRETTY_PRINT);
            exit;
        }
        
        // Get automation settings to use date filters
        if ($automationId) {
            $stmt = $pdo->prepare("SELECT video_days_filter, video_start_date, video_end_date FROM automation_settings WHERE id = ?");
            $stmt->execute([$automationId]);
            $automation = $stmt->fetch();
            
            $ftp = FTPAPI::fromSettings($pdo);
            
            // DEBUG: Log the date values for troubleshooting
            $startValue = $automation['video_start_date'] ?? null;
            $endValue = $automation['video_end_date'] ?? null;
            $daysFilter = $automation['video_days_filter'] ?? 30;
            
            // Log to debug file for troubleshooting
            $logDebug = __DIR__ . '/debug_test_fetch.log';
            file_put_contents($logDebug, 
                date('Y-m-d H:i:s') . " - Test Fetch for Automation ID: {$automationId}\n" .
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
            
            if ($automation && $hasValidStartDate && $hasValidEndDate) {
                // Use date range filtering
                file_put_contents($logDebug, "  Action: Using date range filtering\n", FILE_APPEND | LOCK_EX);
                $videos = $ftp->getVideosByDateRange($startValue, $endValue);
            } else {
                // Use days filter as fallback
                file_put_contents($logDebug, "  Action: Using days filter ({$daysFilter} days)\n", FILE_APPEND | LOCK_EX);
                $daysFilter = $automation['video_days_filter'] ?? 30;
                $videos = $ftp->getVideos($daysFilter);
            }
            
            $ftp->disconnect();
        } else {
            // Default behavior when no automation ID provided
            $ftp = FTPAPI::fromSettings($pdo);
            $videos = $ftp->getVideos(30);
            $ftp->disconnect();
        }
        
        echo json_encode([
            'success' => true,
            'source' => 'FTP/Bunny Storage',
            'settings' => [
                'host' => $settings['ftp_host'] ?? 'not set',
                'username' => $settings['ftp_username'] ?? 'not set',
                'path' => $settings['ftp_path'] ?? '/'
            ],
            'count' => count($videos),
            'videos' => array_slice($videos, 0, 10)
        ], JSON_PRETTY_PRINT);
    } elseif ($source === 'manual_links') {
        if (!$automationId) {
            echo json_encode([
                'success' => false,
                'error' => 'Automation ID is required for manual links source'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $stmt = $pdo->prepare("SELECT manual_video_links FROM automation_settings WHERE id = ?");
        $stmt->execute([$automationId]);
        $automation = $stmt->fetch();

        if (!$automation) {
            echo json_encode([
                'success' => false,
                'error' => 'Automation not found'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $links = parseManualLinksForTest($automation['manual_video_links'] ?? '');
        $preview = array_map(function ($url, $idx) {
            $path = parse_url((string)$url, PHP_URL_PATH);
            $name = $path ? basename((string)$path) : ('video_' . ($idx + 1) . '.mp4');
            return [
                'index' => $idx + 1,
                'name' => $name ?: ('video_' . ($idx + 1) . '.mp4'),
                'url' => $url
            ];
        }, $links, array_keys($links));

        echo json_encode([
            'success' => true,
            'source' => 'Manual Links',
            'count' => count($links),
            'videos' => array_slice($preview, 0, 10)
        ], JSON_PRETTY_PRINT);
    } elseif ($source === 'youtube_channel') {
        if (!$automationId) {
            echo json_encode([
                'success' => false,
                'error' => 'Automation ID is required for YouTube channel source'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $stmt = $pdo->prepare("SELECT youtube_channel_url, manual_video_links, video_days_filter, video_start_date, video_end_date FROM automation_settings WHERE id = ?");
        $stmt->execute([$automationId]);
        $automation = $stmt->fetch();

        if (!$automation) {
            echo json_encode([
                'success' => false,
                'error' => 'Automation not found'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $channelUrl = getYouTubeChannelUrlForTest($automation);
        if ($channelUrl === '') {
            echo json_encode([
                'success' => false,
                'error' => 'YouTube channel URL is missing on this automation'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $youtube = new YouTubeSource($channelUrl);
        $daysFilter = intval($automation['video_days_filter'] ?? 30);
        if ($daysFilter < 1) $daysFilter = 30;

        $startDate = trim((string)($automation['video_start_date'] ?? ''));
        $endDate = trim((string)($automation['video_end_date'] ?? ''));
        $useDateRange = ($startDate !== '' && $endDate !== '' && strtotime($startDate) !== false && strtotime($endDate) !== false && strtotime($startDate) <= strtotime($endDate));

        $videos = $youtube->listVideos(
            $daysFilter,
            $useDateRange ? $startDate : null,
            $useDateRange ? $endDate : null,
            15
        );

        echo json_encode([
            'success' => true,
            'source' => 'YouTube Channel',
            'channel_url' => $channelUrl,
            'count' => count($videos),
            'videos' => array_slice($videos, 0, 10)
        ], JSON_PRETTY_PRINT);
    } else {
        if (!$automationId) {
            $stmt = $pdo->query("SELECT a.*, k.api_key, k.library_id, k.storage_zone, k.cdn_hostname 
                                 FROM automation_settings a 
                                 JOIN api_keys k ON a.api_key_id = k.id 
                                 WHERE a.video_source = 'bunny' 
                                 LIMIT 1");
            $automation = $stmt->fetch();
        } else {
            $stmt = $pdo->prepare("SELECT a.*, k.api_key, k.library_id, k.storage_zone, k.cdn_hostname 
                                   FROM automation_settings a 
                                   LEFT JOIN api_keys k ON a.api_key_id = k.id 
                                   WHERE a.id = ?");
            $stmt->execute([$automationId]);
            $automation = $stmt->fetch();
        }
        
        if (!$automation) {
            echo json_encode(['error' => 'No automation with Bunny CDN found. Create one first.']);
            exit;
        }
        
        if (empty($automation['api_key']) || empty($automation['library_id'])) {
            echo json_encode([
                'error' => 'Bunny API key or Library ID is empty',
                'api_key' => $automation['api_key'] ? 'Set (' . strlen($automation['api_key']) . ' chars)' : 'NOT SET',
                'library_id' => $automation['library_id'] ?: 'NOT SET'
            ]);
            exit;
        }
        
        $bunny = new BunnyAPI(
            $automation['api_key'],
            $automation['library_id'],
            $automation['storage_zone'] ?? '',
            $automation['cdn_hostname'] ?? ''
        );
        
        // DEBUG: Log the date values for troubleshooting (Bunny CDN section)
        $startValue = $automation['video_start_date'] ?? null;
        $endValue = $automation['video_end_date'] ?? null;
        $daysFilter = $automation['video_days_filter'] ?? 30;
        
        // Log to debug file for troubleshooting
        $logDebug = __DIR__ . '/debug_test_fetch.log';
        file_put_contents($logDebug, 
            date('Y-m-d H:i:s') . " - Bunny CDN Test Fetch for Automation ID: {$automationId}\n" .
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
        
        if ($automation && $hasValidStartDate && $hasValidEndDate) {
            // Use date range filtering
            file_put_contents($logDebug, "  Action: Using date range filtering (Bunny)\n", FILE_APPEND | LOCK_EX);
            // Check if getVideosByDateRange method exists
            if (method_exists($bunny, 'getVideosByDateRange')) {
                $allVideos = $bunny->getVideosByDateRange($startValue, $endValue);
            } else {
                // Fallback: get all videos and filter by date in PHP
                $allVideos = $bunny->getVideos(1, 100, 'date');
                
                if (!isset($allVideos['error']) && isset($allVideos['items'])) {
                    $startTimestamp = strtotime($startValue);
                    $endTimestamp = strtotime($endValue . ' +1 day'); // Include end date
                    
                    $filteredItems = [];
                    foreach ($allVideos['items'] as $video) {
                        $videoDate = strtotime($video['dateUploaded']);
                        if ($videoDate >= $startTimestamp && $videoDate <= $endTimestamp) {
                            $filteredItems[] = $video;
                        }
                    }
                    
                    $allVideos['items'] = $filteredItems;
                    $allVideos['totalItems'] = count($filteredItems);
                }
            }
        } else {
            // Use days filter as fallback
            file_put_contents($logDebug, "  Action: Using days filter (Bunny) ({$daysFilter} days)\n", FILE_APPEND | LOCK_EX);
            $daysFilter = $automation['video_days_filter'] ?? 30;
            
            // Check if getVideosByDateRange method exists
            if (method_exists($bunny, 'getVideosByDateRange')) {
                // Calculate date range based on days filter
                $endDate = date('Y-m-d');
                $startDate = date('Y-m-d', strtotime("-{$daysFilter} days"));
                
                // Get videos with calculated date range
                $allVideos = $bunny->getVideosByDateRange($startDate, $endDate);
            } else {
                // Use the existing getRecentVideos method
                $allVideos = $bunny->getRecentVideos($daysFilter);
            }
        }
        
        if (isset($allVideos['error'])) {
            echo json_encode([
                'success' => false,
                'error' => $allVideos['error'],
                'message' => $allVideos['message'] ?? null,
                'api_key_length' => strlen($automation['api_key']),
                'library_id' => $automation['library_id']
            ], JSON_PRETTY_PRINT);
            exit;
        }
        
        // Check if it's a valid response
        $items = $allVideos['items'] ?? $allVideos;
        $totalItems = $allVideos['totalItems'] ?? count($items);
        
        echo json_encode([
            'success' => true,
            'source' => 'Bunny CDN',
            'library_id' => $automation['library_id'],
            'totalInLibrary' => $totalItems,
            'count' => is_array($items) ? count($items) : 0,
            'raw_response_type' => gettype($allVideos),
            'has_items_key' => isset($allVideos['items']),
            'date_range_used' => ['start' => $startDate, 'end' => $endDate],
            'videos' => is_array($items) ? array_map(function($v) {
                return [
                    'guid' => $v['guid'] ?? 'N/A',
                    'title' => $v['title'] ?? 'N/A',
                    'dateUploaded' => $v['dateUploaded'] ?? 'N/A',
                    'length' => $v['length'] ?? null,
                    'status' => $v['status'] ?? null
                ];
            }, array_slice($items, 0, 10)) : []
        ], JSON_PRETTY_PRINT);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
