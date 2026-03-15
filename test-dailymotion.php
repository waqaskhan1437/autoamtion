<?php
/**
 * DailyMotion API Test Script
 * Tests the DailyMotion API with stored credentials
 */

require_once 'config.php';

echo "=== DailyMotion API Test ===\n\n";

// Get credentials from settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'dailymotion%'");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$apiKey = $settings['dailymotion_api_key'] ?? '';
$apiSecret = $settings['dailymotion_api_secret'] ?? '';
$username = $settings['dailymotion_username'] ?? '';
$password = $settings['dailymotion_password'] ?? '';

echo "API Key: " . ($apiKey ? substr($apiKey, 0, 10) . '...' : 'NOT SET') . "\n";
echo "API Secret: " . ($apiSecret ? substr($apiSecret, 0, 10) . '...' : 'NOT SET') . "\n";
echo "Username: " . ($username ? $username : 'NOT SET') . "\n";
echo "Password: " . ($password ? '***SET***' : 'NOT SET') . "\n\n";

if (empty($apiKey) || empty($apiSecret)) {
    echo "❌ ERROR: DailyMotion API credentials not found in settings!\n";
    echo "Please add your API Key and Secret in Settings → AI Settings\n";
    exit(1);
}

require_once 'includes/DailyMotionAPI.php';

$dmAPI = new DailyMotionAPI($apiKey, $apiSecret);
$dmAPI->setPDO($pdo);

echo "=== Testing client_credentials (Private API Key) ===\n";

// Test client_credentials
$ch = curl_init('https://partner.api.dailymotion.com/oauth/v1/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $apiKey,
        'client_secret' => $apiSecret,
        'scope' => 'read write delete manage_videos'
    ]),
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n\n";

$data = json_decode($response, true);

if ($httpCode === 200 && isset($data['access_token'])) {
    echo "✅ client_credentials SUCCESS!\n";
    echo "   Access Token: " . substr($data['access_token'], 0, 30) . "...\n";
    
    // Test /me endpoint
    echo "\n=== Testing /me endpoint ===\n";
    $ch = curl_init('https://api.dailymotion.com/me?fields=id,username,email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $data['access_token']],
        CURLOPT_TIMEOUT => 30
    ]);
    $userResponse = curl_exec($ch);
    $userHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: $userHttpCode\n";
    echo "Response: $userResponse\n\n";
    
    $userData = json_decode($userResponse, true);
    if ($userHttpCode === 200) {
        echo "✅ USER INFO SUCCESS!\n";
        echo "   User ID: " . ($userData['id'] ?? 'N/A') . "\n";
        echo "   Username: " . ($userData['username'] ?? 'N/A') . "\n";
    }
    
    // Test upload URL
    echo "\n=== Testing /file/upload endpoint ===\n";
    $ch = curl_init('https://api.dailymotion.com/file/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $data['access_token']],
        CURLOPT_TIMEOUT => 30
    ]);
    $uploadResponse = curl_exec($ch);
    $uploadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: $uploadHttpCode\n";
    echo "Response: $uploadResponse\n\n";
    
    $uploadData = json_decode($uploadResponse, true);
    if ($uploadHttpCode === 200 && isset($uploadData['upload_url'])) {
        echo "✅ UPLOAD URL SUCCESS!\n";
        echo "   Upload URL: " . substr($uploadData['upload_url'], 0, 60) . "...\n\n";
        echo "🎉 DAILYMOTION API IS FULLY WORKING!\n";
    } else {
        echo "❌ UPLOAD URL FAILED!\n";
    }
    
} else {
    echo "❌ client_credentials FAILED!\n";
    echo "Error: " . ($data['error_description'] ?? $data['error'] ?? 'Unknown') . "\n";
    echo "Error Code: " . ($data['error_code'] ?? 'N/A') . "\n\n";
    
    // Try password grant if available
    if (!empty($username) && !empty($password)) {
        echo "=== Trying password grant (fallback) ===\n";
        
        $ch = curl_init('https://api.dailymotion.com/oauth/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'password',
                'client_id' => $apiKey,
                'client_secret' => $apiSecret,
                'username' => $username,
                'password' => $password,
                'scope' => 'read write delete manage_videos'
            ]),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "HTTP Code: $httpCode\n";
        echo "Response: $response\n\n";
        
        $data = json_decode($response, true);
        
        if ($httpCode === 200 && isset($data['access_token'])) {
            echo "✅ password grant SUCCESS!\n";
            echo "   Access Token: " . substr($data['access_token'], 0, 30) . "...\n";
            echo "   Refresh Token: " . (isset($data['refresh_token']) ? 'YES' : 'NO') . "\n";
            echo "\n💡 To use password grant, please add your DailyMotion Username and Password in Settings.\n";
        } else {
            echo "❌ password grant also FAILED!\n";
            echo "Error: " . ($data['error_description'] ?? $data['error'] ?? 'Unknown') . "\n";
        }
    } else {
        echo "💡 Your API key appears to be a Public API key, which doesn't support client_credentials.\n";
        echo "   Please use Password Grant flow by adding Username and Password in Settings.\n";
    }
}

echo "\n=== Test Complete ===\n";
