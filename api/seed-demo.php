<?php
require_once '../config.php';

try {
    $stmt = $pdo->query("SELECT id FROM api_keys WHERE name = 'Demo Bunny Account'");
    $existing = $stmt->fetch();
    
    if (!$existing) {
        $stmt = $pdo->prepare("INSERT INTO api_keys (name, api_key, library_id, storage_zone, ftp_host, ftp_username, cdn_hostname, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->execute(['Demo Bunny Account', 'demo-api-key-xxxxx', '12345', 'demo-storage', 'storage.bunnycdn.com', 'demo-user', 'demo.b-cdn.net']);
        $keyId = $pdo->lastInsertId();
    } else {
        $keyId = $existing['id'];
    }
    
    $demoVideos = [
        'Product Launch Video 2024',
        'Customer Testimonial - John',
        'Behind the Scenes Tour',
        'How To Use Our App'
    ];
    
    foreach ($demoVideos as $i => $title) {
        $stmt = $pdo->prepare("INSERT INTO video_jobs (name, api_key_id, video_id, type, status, progress) VALUES (?, ?, ?, 'pull', 'completed', 100)");
        $stmt->execute([$title, $keyId, 'demo-00' . ($i + 1)]);
    }
    
    header('Location: ../index.php?success=1');
} catch (PDOException $e) {
    header('Location: ../index.php?error=' . urlencode($e->getMessage()));
}
?>
