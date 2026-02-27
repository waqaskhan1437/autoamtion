<?php
header('Content-Type: application/json');

require_once '../config.php';
require_once '../includes/PostForMeAPI.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST method required');
    }
    
    $videoPath = $_POST['video_path'] ?? '';
    $caption = $_POST['caption'] ?? '';
    $accountIds = $_POST['account_ids'] ?? [];
    
    if (empty($videoPath)) {
        throw new Exception('Video path is required');
    }
    
    if (empty($accountIds)) {
        throw new Exception('Please select at least one account');
    }
    
    if (!file_exists($videoPath)) {
        throw new Exception('Video file not found: ' . basename($videoPath));
    }
    
    // Get API key from settings
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key'");
    $stmt->execute();
    $apiKey = $stmt->fetchColumn();
    
    if (!$apiKey) {
        throw new Exception('Post for Me API key not configured. Go to Settings → Post for Me tab');
    }
    
    // Initialize Post for Me API
    $postForMe = new PostForMeAPI($apiKey);
    
    // Build options (scheduling support)
    $options = [];
    $scheduledAt = $_POST['scheduled_at'] ?? null;
    $scheduleTimezone = $_POST['schedule_timezone'] ?? 'UTC';
    
    if (!empty($scheduledAt)) {
        try {
            $userTz = new DateTimeZone($scheduleTimezone);
            $utcTz = new DateTimeZone('UTC');
            $dt = new DateTime($scheduledAt, $userTz);
            $dt->setTimezone($utcTz);
            $options['scheduled_at'] = $dt->format('Y-m-d\TH:i:s\Z');
        } catch (Exception $e) {
            throw new Exception('Invalid schedule date: ' . $e->getMessage());
        }
    }
    
    // Post video
    $result = $postForMe->postVideo($videoPath, $caption, $accountIds, $options);
    
    if ($result['success']) {
        $postId = $result['post_id'] ?? 'unknown';
        $isScheduled = !empty($options['scheduled_at']);
        
        // Log to database if table exists
        try {
            $status = $isScheduled ? 'scheduled' : 'pending';
            $stmt = $pdo->prepare("INSERT INTO postforme_posts (post_id, video_id, caption, account_ids, status, scheduled_at) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $postId,
                basename($videoPath),
                $caption,
                json_encode($accountIds),
                $status,
                $options['scheduled_at'] ?? null
            ]);
        } catch (Exception $e) {
            // Table might not exist
        }
        
        if ($isScheduled) {
            echo json_encode([
                'success' => true,
                'message' => "Post scheduled! Post ID: {$postId}\nScheduled for: {$options['scheduled_at']}",
                'post_id' => $postId,
                'scheduled_at' => $options['scheduled_at'],
                'results' => []
            ]);
        } else {
            // Wait and get results for immediate posts
            sleep(3);
            $postResults = $postForMe->getPostResults($postId);
            
            $platformResults = [];
            if ($postResults['success'] && !empty($postResults['results'])) {
                foreach ($postResults['results'] as $r) {
                    $platform = $r['platform'] ?? 'unknown';
                    $status = $r['status'] ?? 'pending';
                    $url = $r['url'] ?? '';
                    $platformResults[] = "{$platform}: {$status}" . ($url ? " ({$url})" : '');
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => "Posted successfully! Post ID: {$postId}" . (!empty($platformResults) ? "\n" . implode("\n", $platformResults) : ''),
                'post_id' => $postId,
                'results' => $postResults['results'] ?? []
            ]);
        }
    } else {
        $error = $result['error'] ?? 'Unknown error';
        $details = '';
        
        if (isset($result['http_code'])) {
            $details .= " (HTTP {$result['http_code']})";
        }
        
        if (isset($result['raw']) && strlen($result['raw']) < 200) {
            $details .= " - " . $result['raw'];
        }
        
        throw new Exception($error . $details);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
