<?php
/**
 * Post for Me API Integration
 * Unified Social Media Posting API
 * https://www.postforme.dev/
 * 
 * Supports: YouTube, TikTok, Instagram, Facebook, Threads, Pinterest, LinkedIn, Bluesky, X (Twitter)
 */

class PostForMeAPI {
    
    private $apiKey;
    private $baseUrl = 'https://api.postforme.dev/v1';
    private $timeout = 120; // 2 minutes for video uploads
    
    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }
    
    /**
     * Make API request
     */
    private function request($method, $endpoint, $data = null) {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'GET' && $data) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'cURL Error: ' . $error,
                'http_code' => 0
            ];
        }
        
        $decoded = json_decode($response, true);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data' => $decoded,
            'raw' => $response
        ];
    }
    
    /**
     * Test API connection
     */
    public function testConnection() {
        $result = $this->request('GET', '/social-accounts');
        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Connected successfully' : 'Connection failed',
            'http_code' => $result['http_code'],
            'accounts_count' => isset($result['data']['data']) ? count($result['data']['data']) : 0
        ];
    }
    
    /**
     * Get all connected social accounts
     * @param string|null $platform Filter by platform (facebook, instagram, youtube, tiktok, etc.)
     */
    public function getAccounts($platform = null) {
        $params = [];
        if ($platform) {
            $params['platform'] = $platform;
        }
        
        $result = $this->request('GET', '/social-accounts', $params);
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to get accounts',
                'accounts' => []
            ];
        }
        
        return [
            'success' => true,
            'accounts' => $result['data']['data'] ?? []
        ];
    }
    
    /**
     * Get OAuth URL for connecting a new social account
     * @param string $platform Platform to connect (facebook, instagram, youtube, tiktok, twitter, linkedin, threads, pinterest, bluesky)
     * @param string|null $redirectUrl Optional redirect URL after OAuth
     */
    public function getAuthUrl($platform, $redirectUrl = null) {
        $data = [
            'platform' => $platform
        ];
        
        if ($redirectUrl) {
            $data['redirect_url'] = $redirectUrl;
        }
        
        $result = $this->request('POST', '/social-accounts/auth-url', $data);
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to get auth URL'
            ];
        }
        
        return [
            'success' => true,
            'url' => $result['data']['url'] ?? null
        ];
    }
    
    /**
     * Disconnect a social account
     * @param string $accountId Social account ID
     */
    public function disconnectAccount($accountId) {
        $result = $this->request('POST', "/social-accounts/{$accountId}/disconnect");
        
        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Account disconnected' : ($result['data']['message'] ?? 'Failed to disconnect')
        ];
    }
    
    /**
     * Create upload URL for media (if video is not publicly hosted)
     * Returns signed upload URL and public media URL
     */
    public function createUploadUrl() {
        $result = $this->request('POST', '/media/create-upload-url');
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to create upload URL'
            ];
        }
        
        return [
            'success' => true,
            'upload_url' => $result['data']['upload_url'] ?? null,
            'media_url' => $result['data']['media_url'] ?? null
        ];
    }
    
    /**
     * Upload file to Post for Me temporary storage
     * @param string $filePath Local path to the video file
     */
    public function uploadMedia($filePath) {
        // Step 1: Get upload URL
        $urlResult = $this->createUploadUrl();
        
        if (!$urlResult['success']) {
            return $urlResult;
        }
        
        $uploadUrl = $urlResult['upload_url'];
        $mediaUrl = $urlResult['media_url'];
        
        // Step 2: Upload file to signed URL
        $fileSize = filesize($filePath);
        $fileHandle = fopen($filePath, 'r');
        
        if (!$fileHandle) {
            return [
                'success' => false,
                'error' => 'Cannot open file: ' . $filePath
            ];
        }
        
        // Detect content type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $contentType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        if (!$contentType) {
            $contentType = 'video/mp4';
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_PUT => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_INFILE => $fileHandle,
            CURLOPT_INFILESIZE => $fileSize,
            CURLOPT_HTTPHEADER => [
                'Content-Type: ' . $contentType
            ],
            CURLOPT_TIMEOUT => 3600 // 1 hour for large uploads
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fileHandle);
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'Upload failed: ' . $error
            ];
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'success' => false,
                'error' => 'Upload failed with HTTP ' . $httpCode,
                'response' => $response
            ];
        }
        
        return [
            'success' => true,
            'media_url' => $mediaUrl
        ];
    }
    
    /**
     * Create and publish a social media post
     * @param array $params Post parameters:
     *   - caption: string (required)
     *   - social_accounts: array of account IDs (required)
     *   - media: array of media objects [['url' => 'https://...', 'type' => 'video']]
     *   - scheduled_at: ISO 8601 datetime for scheduled posts (optional)
     *   - thumbnail_url: URL for video thumbnail (optional)
     */
    public function createPost($params) {
        $requiredFields = ['caption', 'social_accounts'];
        foreach ($requiredFields as $field) {
            if (empty($params[$field])) {
                return [
                    'success' => false,
                    'error' => "Missing required field: {$field}"
                ];
            }
        }
        
        $postData = [
            'caption' => $params['caption'],
            'social_accounts' => $params['social_accounts']
        ];
        
        if (!empty($params['media'])) {
            $postData['media'] = $params['media'];
        }
        
        if (!empty($params['scheduled_at'])) {
            $postData['scheduled_at'] = $params['scheduled_at'];
        }
        
        if (!empty($params['thumbnail_url'])) {
            $postData['thumbnail_url'] = $params['thumbnail_url'];
        }
        
        // Platform-specific overrides
        if (!empty($params['platform_overrides'])) {
            $postData['platform_overrides'] = $params['platform_overrides'];
        }
        
        $result = $this->request('POST', '/social-posts', $postData);
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to create post',
                'http_code' => $result['http_code'],
                'raw' => $result['raw']
            ];
        }
        
        return [
            'success' => true,
            'post_id' => $result['data']['id'] ?? null,
            'data' => $result['data']
        ];
    }
    
    /**
     * Get post status and details
     * @param string $postId Post ID
     */
    public function getPost($postId) {
        $result = $this->request('GET', "/social-posts/{$postId}");
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to get post'
            ];
        }
        
        return [
            'success' => true,
            'post' => $result['data']
        ];
    }

    /**
     * List social posts (supports filtering/pagination when API supports it)
     * @param array $params e.g. ['status' => 'scheduled', 'page' => 1, 'per_page' => 100]
     */
    public function listPosts($params = []) {
        $result = $this->request('GET', '/social-posts', $params);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to list posts'
            ];
        }

        $payload = $result['data'] ?? [];
        $rows = [];

        if (isset($payload['data']) && is_array($payload['data'])) {
            $rows = $payload['data'];
        } elseif (is_array($payload)) {
            $rows = $payload;
        }

        return [
            'success' => true,
            'posts' => $rows,
            'raw' => $payload
        ];
    }
    
    /**
     * Get post results (success/failure per platform)
     * @param string $postId Post ID (optional - if null, gets recent results)
     */
    public function getPostResults($postId = null) {
        $endpoint = '/social-post-results';
        if ($postId) {
            $endpoint .= "?post_id={$postId}";
        }
        
        $result = $this->request('GET', $endpoint);
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to get post results'
            ];
        }
        
        return [
            'success' => true,
            'results' => $result['data']['data'] ?? $result['data']
        ];
    }
    
    /**
     * Delete a scheduled post
     * @param string $postId Post ID
     */
    public function deletePost($postId) {
        $result = $this->request('DELETE', "/social-posts/{$postId}");
        
        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Post deleted' : ($result['data']['message'] ?? 'Failed to delete')
        ];
    }


    /**
     * Cancel a scheduled post (best effort).
     * Some accounts may expose cancel as an action endpoint.
     *
     * @param string $postId Post ID
     * @return array{success:bool,message:string}
     */
    public function cancelPost($postId) {
        $result = $this->request('POST', "/social-posts/{$postId}/cancel");

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Post cancelled' : ($result['data']['message'] ?? 'Failed to cancel')
        ];
    }

    /**
     * Delete or cancel a scheduled post (best effort).
     * Tries DELETE first, then falls back to /cancel if needed.
     *
     * @param string $postId Post ID
     * @return array{success:bool,message:string}
     */
    public function cancelOrDeletePost($postId) {
        $del = $this->deletePost($postId);
        if (!empty($del['success'])) {
            return $del;
        }

        return $this->cancelPost($postId);
    }
    
    /**
     * Get analytics for a social account feed
     * @param string $accountId Social account ID
     * @param bool $includeMetrics Include engagement metrics
     */
    public function getAccountFeed($accountId, $includeMetrics = true) {
        $endpoint = "/social-account-feeds/{$accountId}";
        if ($includeMetrics) {
            $endpoint .= '?expand=metrics';
        }
        
        $result = $this->request('GET', $endpoint);
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to get feed'
            ];
        }
        
        return [
            'success' => true,
            'feed' => $result['data']['data'] ?? $result['data']
        ];
    }
    
    /**
     * Upload video and post to multiple platforms
     * Convenience method that combines upload + post
     * 
     * @param string $videoPath Local path to video file
     * @param string $caption Post caption/description
     * @param array $accountIds Array of social account IDs to post to
     * @param array $options Additional options (scheduled_at, thumbnail_url, etc.)
     */
    public function postVideo($videoPath, $caption, $accountIds, $options = []) {
        // Check if video is already hosted (URL provided instead of path)
        if (filter_var($videoPath, FILTER_VALIDATE_URL)) {
            $mediaUrl = $videoPath;
        } else {
            // Check if local video file exists
            if (!file_exists($videoPath)) {
                return [
                    'success' => false,
                    'error' => 'Video file not found: ' . $videoPath
                ];
            }
            
            // Upload video to Post for Me storage
            $uploadResult = $this->uploadMedia($videoPath);
            
            if (!$uploadResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Upload failed: ' . ($uploadResult['error'] ?? 'Unknown error'),
                    'upload_result' => $uploadResult
                ];
            }
            
            $mediaUrl = $uploadResult['media_url'];
        }
        
        // Create post
        $postParams = [
            'caption' => $caption,
            'social_accounts' => $accountIds,
            'media' => [
                ['url' => $mediaUrl, 'type' => 'video']
            ]
        ];
        
        // Merge additional options
        if (!empty($options['scheduled_at'])) {
            $postParams['scheduled_at'] = $options['scheduled_at'];
        }
        
        if (!empty($options['thumbnail_url'])) {
            $postParams['thumbnail_url'] = $options['thumbnail_url'];
        }
        
        if (!empty($options['platform_overrides'])) {
            $postParams['platform_overrides'] = $options['platform_overrides'];
        }
        
        return $this->createPost($postParams);
    }
    
    /**
     * Get supported platforms list
     */
    public static function getSupportedPlatforms() {
        return [
            'youtube' => [
                'name' => 'YouTube',
                'icon' => 'youtube',
                'color' => 'red',
                'supports_video' => true,
                'supports_image' => false,
                'supports_reels' => true
            ],
            'tiktok' => [
                'name' => 'TikTok',
                'icon' => 'tiktok',
                'color' => 'black',
                'supports_video' => true,
                'supports_image' => false,
                'supports_reels' => true
            ],
            'instagram' => [
                'name' => 'Instagram',
                'icon' => 'instagram',
                'color' => 'pink',
                'supports_video' => true,
                'supports_image' => true,
                'supports_reels' => true
            ],
            'facebook' => [
                'name' => 'Facebook',
                'icon' => 'facebook',
                'color' => 'blue',
                'supports_video' => true,
                'supports_image' => true,
                'supports_reels' => true
            ],
            'threads' => [
                'name' => 'Threads',
                'icon' => 'threads',
                'color' => 'gray',
                'supports_video' => true,
                'supports_image' => true,
                'supports_reels' => false
            ],
            'twitter' => [
                'name' => 'X (Twitter)',
                'icon' => 'twitter',
                'color' => 'gray',
                'supports_video' => true,
                'supports_image' => true,
                'supports_reels' => false
            ],
            'linkedin' => [
                'name' => 'LinkedIn',
                'icon' => 'linkedin',
                'color' => 'blue',
                'supports_video' => true,
                'supports_image' => true,
                'supports_reels' => false
            ],
            'pinterest' => [
                'name' => 'Pinterest',
                'icon' => 'pinterest',
                'color' => 'red',
                'supports_video' => true,
                'supports_image' => true,
                'supports_reels' => false
            ],
            'bluesky' => [
                'name' => 'Bluesky',
                'icon' => 'bluesky',
                'color' => 'blue',
                'supports_video' => true,
                'supports_image' => true,
                'supports_reels' => false
            ]
        ];
    }
}
?>
