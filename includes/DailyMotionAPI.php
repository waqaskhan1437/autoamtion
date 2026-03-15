<?php
/**
 * DailyMotion API Integration
 * Handles OAuth authentication and video posting to DailyMotion
 */

class DailyMotionAPI {
    private $apiKey;
    private $apiSecret;
    private $baseUrl = 'https://api.dailymotion.com';
    
    public function __construct($apiKey, $apiSecret) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }
    
    /**
     * Get OAuth authorization URL
     */
    public function getAuthUrl($redirectUrl) {
        $url = $this->baseUrl . '/oauth/authorize?client_id=' . urlencode($this->apiKey) 
              . '&redirect_uri=' . urlencode($redirectUrl) 
              . '&response_type=code';
        return $url;
    }
    
    /**
     * Exchange authorization code for access token
     */
    public function getAccessToken($code, $redirectUrl) {
        $ch = curl_init($this->baseUrl . '/oauth/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'authorization_code',
                'client_id' => $this->apiKey,
                'client_secret' => $this->apiSecret,
                'code' => $code,
                'redirect_uri' => $redirectUrl
            ]),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        
        return ['success' => false, 'error' => 'Failed to get access token'];
    }
    
    /**
     * Get current user info
     */
    public function getUser($accessToken) {
        $ch = curl_init($this->baseUrl . '/me?fields=id,username,email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
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
    public function uploadVideo($accessToken, $videoPath, $title, $description = '', $tags = [], $isPrivate = false) {
        // First, get upload URL
        $ch = curl_init($this->baseUrl . '/file/upload');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'Failed to get upload URL'];
        }
        
        $uploadData = json_decode($response, true);
        $uploadUrl = $uploadData['upload_url'];
        
        // Upload the video file
        if (!file_exists($videoPath)) {
            return ['success' => false, 'error' => 'Video file not found'];
        }
        
        $fileSize = filesize($videoPath);
        $fileHandle = fopen($videoPath, 'r');
        
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $fileHandle,
            CURLOPT_INFILESIZE => $fileSize,
            CURLOPT_HTTPHEADER => [
                'Content-Type: video/mp4',
                'Authorization: Bearer ' . $accessToken
            ],
            CURLOPT_TIMEOUT => 3600 // 1 hour for large uploads
        ]);
        
        $uploadResponse = curl_exec($ch);
        $uploadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fileHandle);
        
        if ($uploadHttpCode !== 200 || $error) {
            return ['success' => false, 'error' => 'Upload failed: ' . $error];
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
                'Authorization: Bearer ' . $accessToken,
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
    public function postVideo($videoPath, $title, $description = '', $tags = [], $isPrivate = false, $accessToken = null) {
        if (!$accessToken) {
            // Try to get stored token from database
            return ['success' => false, 'error' => 'No access token available'];
        }
        
        return $this->uploadVideo($accessToken, $videoPath, $title, $description, $tags, $isPrivate);
    }
}
