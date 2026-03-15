<?php
/**
 * DailyMotion API Integration
 * Handles OAuth authentication and video posting to DailyMotion
 * Supports automatic token refresh using client_credentials or password grant
 */

class DailyMotionAPI {
    private $apiKey;
    private $apiSecret;
    private $baseUrl = 'https://api.dailymotion.com';
    private $partnerUrl = 'https://partner.api.dailymotion.com';
    private $pdo;
    private $cachedToken;
    private $tokenExpiry;
    
    public function __construct($apiKey = null, $apiSecret = null, $pdo = null) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->pdo = $pdo;
    }
    
    public function setPDO($pdo) {
        $this->pdo = $pdo;
    }
    
    public function setCredentials($apiKey, $apiSecret) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }
    
    /**
     * Get new access token using client_credentials grant (Private API keys)
     */
    private function getNewTokenClientCredentials() {
        if (empty($this->apiKey) || empty($this->apiSecret)) {
            return ['success' => false, 'error' => 'Missing API credentials'];
        }
        
        // Use partner API endpoint for client_credentials
        $ch = curl_init($this->partnerUrl . '/oauth/v1/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $this->apiKey,
                'client_secret' => $this->apiSecret,
                'scope' => 'read write delete manage_videos'
            ]),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => 'cURL error: ' . $error];
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode === 200 && isset($data['access_token'])) {
            $this->cachedToken = $data['access_token'];
            $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600);
            
            return [
                'success' => true,
                'access_token' => $data['access_token'],
                'expires_in' => $data['expires_in'] ?? 3600
            ];
        }
        
        return [
            'success' => false,
            'error' => $data['error_description'] ?? $data['error'] ?? 'Client credentials failed',
            'details' => $data,
            'http_code' => $httpCode
        ];
    }
    
    /**
     * Get new access token using password grant (requires username/password)
     */
    public function getNewTokenPasswordGrant($username, $password) {
        if (empty($this->apiKey) || empty($this->apiSecret) || empty($username) || empty($password)) {
            return ['success' => false, 'error' => 'Missing credentials for password grant'];
        }
        
        $ch = curl_init($this->baseUrl . '/oauth/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'password',
                'client_id' => $this->apiKey,
                'client_secret' => $this->apiSecret,
                'username' => $username,
                'password' => $password,
                'scope' => 'read write delete manage_videos'
            ]),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => 'cURL error: ' . $error];
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode === 200 && isset($data['access_token'])) {
            $this->cachedToken = $data['access_token'];
            $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600);
            
            return [
                'success' => true,
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_in' => $data['expires_in'] ?? 3600
            ];
        }
        
        return [
            'success' => false,
            'error' => $data['error_description'] ?? $data['error'] ?? 'Password grant failed',
            'details' => $data
        ];
    }
    
    /**
     * Get valid access token - tries to get new token if needed
     */
    public function getValidToken() {
        // Check if we have a valid cached token
        if ($this->cachedToken && $this->tokenExpiry && time() < ($this->tokenExpiry - 60)) {
            return $this->cachedToken;
        }
        
        // Try client_credentials first (for private API keys)
        $result = $this->getNewTokenClientCredentials();
        
        if ($result['success']) {
            return $result['access_token'];
        }
        
        // If client_credentials fails, try password grant if we have stored credentials
        if ($this->pdo) {
            try {
                $stmt = $this->pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'dailymotion_username'");
                $username = $stmt->fetchColumn();
                
                $stmt = $this->pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'dailymotion_password'");
                $password = $stmt->fetchColumn();
                
                if ($username && $password) {
                    $result = $this->getNewTokenPasswordGrant($username, $password);
                    if ($result['success']) {
                        return $result['access_token'];
                    }
                }
            } catch (Exception $e) {
                error_log("Error getting stored credentials: " . $e->getMessage());
            }
        }
        
        return null;
    }
    
    /**
     * Get current user info
     */
    public function getUser() {
        $token = $this->getValidToken();
        if (!$token) {
            return ['success' => false, 'error' => 'No valid token available'];
        }
        
        $ch = curl_init($this->baseUrl . '/me?fields=id,username,email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return ['success' => true, 'data' => json_decode($response, true)];
        }
        
        return ['success' => false, 'error' => 'Failed to get user info'];
    }
    
    /**
     * Upload video to DailyMotion
     */
    public function uploadVideo($videoPath, $title, $description = '', $tags = [], $isPrivate = false) {
        $token = $this->getValidToken();
        if (!$token) {
            return ['success' => false, 'error' => 'Failed to get valid access token. Check API Key and Secret.'];
        }
        
        // Validate video file
        if (!file_exists($videoPath)) {
            return ['success' => false, 'error' => 'Video file not found: ' . $videoPath];
        }
        
        $fileSize = filesize($videoPath);
        if ($fileSize === 0) {
            return ['success' => false, 'error' => 'Video file is empty'];
        }
        
        // First, get upload URL
        $ch = curl_init($this->baseUrl . '/file/upload');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'Failed to get upload URL (HTTP ' . $httpCode . '): ' . $response];
        }
        
        $uploadData = json_decode($response, true);
        $uploadUrl = $uploadData['upload_url'];
        
        if (empty($uploadUrl)) {
            return ['success' => false, 'error' => 'No upload URL returned from DailyMotion'];
        }
        
        // Upload using POST with file contents
        $fileContent = file_get_contents($videoPath);
        if ($fileContent === false) {
            return ['success' => false, 'error' => 'Failed to read video file'];
        }
        
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fileContent,
            CURLOPT_HTTPHEADER => [
                'Content-Type: video/mp4',
                'Content-Length: ' . strlen($fileContent),
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ]);
        
        $uploadResponse = curl_exec($ch);
        $uploadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($uploadHttpCode !== 200 || $error) {
            return ['success' => false, 'error' => 'Upload failed: ' . ($error ? $error : 'HTTP ' . $uploadHttpCode)];
        }
        
        $uploadResult = json_decode($uploadResponse, true);
        
        // Create video metadata
        $videoData = [
            'url' => $uploadResult['url'],
            'title' => substr($title, 0, 255),
            'description' => substr($description, 0, 2000),
            'tags' => implode(',', array_slice($tags, 0, 20)),
            'private' => $isPrivate ? 'true' : 'false'
        ];
        
        // Create the video
        $ch = curl_init($this->baseUrl . '/me/videos');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_POSTFIELDS => http_build_query($videoData),
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return [
                'success' => true,
                'video_id' => $result['id'],
                'video_url' => $result['url'] ?? 'https://www.dailymotion.com/video/' . $result['id']
            ];
        }
        
        return ['success' => false, 'error' => 'Failed to create video: ' . $response];
    }
    
    /**
     * Post video (simplified method)
     */
    public function postVideo($videoPath, $title, $description = '', $tags = [], $isPrivate = false) {
        return $this->uploadVideo($videoPath, $title, $description, $tags, $isPrivate);
    }
    
    /**
     * Test authentication - tries both methods
     */
    public function testCredentials($apiKey, $apiSecret) {
        // Store temporarily
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        
        // Try client_credentials first
        $result = $this->getNewTokenClientCredentials();
        
        if ($result['success']) {
            return $result;
        }
        
        // Return failure info
        return $result;
    }
}
