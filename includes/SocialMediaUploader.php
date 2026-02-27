<?php
/**
 * Social Media Upload Integration
 * Handles uploads to YouTube, TikTok, Instagram, and Facebook
 */

class SocialMediaUploader {
    
    /**
     * Upload to YouTube Shorts
     */
    public static function uploadToYouTube($videoPath, $title, $description, $credentials) {
        $accessToken = $credentials['access_token'] ?? null;
        
        if (!$accessToken) {
            return ['error' => 'No YouTube access token provided'];
        }
        
        // Create video metadata
        $metadata = [
            'snippet' => [
                'title' => $title,
                'description' => $description . "\n\n#Shorts",
                'tags' => ['shorts', 'viral'],
                'categoryId' => '22' // People & Blogs
            ],
            'status' => [
                'privacyStatus' => 'public', // or 'private', 'unlisted'
                'selfDeclaredMadeForKids' => false
            ]
        ];
        
        // Step 1: Initialize upload (get resumable upload URL)
        $initUrl = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';
        
        $responseHeaders = [];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $initUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'X-Upload-Content-Type: video/mp4',
                'X-Upload-Content-Length: ' . filesize($videoPath)
            ],
            CURLOPT_POSTFIELDS => json_encode($metadata)
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['error' => 'Failed to initialize YouTube upload', 'response' => $body];
        }
        
        // Extract Location header for resumable upload URL
        $uploadUrl = null;
        foreach (explode("\r\n", $headers) as $header) {
            if (stripos($header, 'Location:') === 0) {
                $uploadUrl = trim(substr($header, 9));
                break;
            }
        }
        
        if (!$uploadUrl) {
            return ['error' => 'No upload URL received from YouTube'];
        }
        
        // Step 2: Upload video file
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_PUT => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_INFILE => fopen($videoPath, 'r'),
            CURLOPT_INFILESIZE => filesize($videoPath),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: video/mp4'
            ],
            CURLOPT_TIMEOUT => 3600
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['error' => 'YouTube upload failed', 'response' => $response];
        }
        
        $result = json_decode($response, true);
        
        return [
            'success' => true,
            'platform' => 'youtube',
            'videoId' => $result['id'] ?? null,
            'url' => 'https://youtube.com/shorts/' . ($result['id'] ?? '')
        ];
    }
    
    /**
     * Upload to TikTok
     */
    public static function uploadToTikTok($videoPath, $caption, $credentials) {
        $accessToken = $credentials['access_token'] ?? null;
        
        if (!$accessToken) {
            return ['error' => 'No TikTok access token provided'];
        }
        
        // TikTok Content Posting API
        // Step 1: Initialize upload
        $initUrl = 'https://open.tiktokapis.com/v2/post/publish/inbox/video/init/';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $initUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'post_info' => [
                    'title' => $caption,
                    'privacy_level' => 'PUBLIC_TO_EVERYONE',
                    'disable_comment' => false,
                    'disable_duet' => false,
                    'disable_stitch' => false
                ],
                'source_info' => [
                    'source' => 'FILE_UPLOAD',
                    'video_size' => filesize($videoPath),
                    'chunk_size' => min(filesize($videoPath), 10000000) // 10MB chunks
                ]
            ])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['error' => 'TikTok init failed', 'response' => $response];
        }
        
        $initData = json_decode($response, true);
        $uploadUrl = $initData['data']['upload_url'] ?? null;
        
        if (!$uploadUrl) {
            return ['error' => 'No upload URL received from TikTok'];
        }
        
        // Step 2: Upload video
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_PUT => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_INFILE => fopen($videoPath, 'r'),
            CURLOPT_INFILESIZE => filesize($videoPath),
            CURLOPT_HTTPHEADER => [
                'Content-Type: video/mp4'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'success' => $httpCode === 200,
            'platform' => 'tiktok',
            'response' => json_decode($response, true)
        ];
    }
    
    /**
     * Upload to Instagram Reels
     */
    public static function uploadToInstagram($videoPath, $caption, $credentials) {
        $accessToken = $credentials['access_token'] ?? null;
        $igUserId = $credentials['user_id'] ?? null;
        
        if (!$accessToken || !$igUserId) {
            return ['error' => 'Missing Instagram credentials'];
        }
        
        // Instagram Graph API for Reels
        // Step 1: Create container
        $containerUrl = "https://graph.facebook.com/v18.0/{$igUserId}/media";
        
        // First upload video to a public URL (required by Instagram)
        // For local files, you need to host them temporarily
        $videoUrl = $videoPath; // This should be a public URL
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $containerUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'media_type' => 'REELS',
                'video_url' => $videoUrl,
                'caption' => $caption,
                'access_token' => $accessToken
            ])
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $containerData = json_decode($response, true);
        $creationId = $containerData['id'] ?? null;
        
        if (!$creationId) {
            return ['error' => 'Failed to create Instagram container', 'response' => $response];
        }
        
        // Step 2: Wait for processing and publish
        sleep(5); // Wait for processing
        
        $publishUrl = "https://graph.facebook.com/v18.0/{$igUserId}/media_publish";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $publishUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'creation_id' => $creationId,
                'access_token' => $accessToken
            ])
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $publishData = json_decode($response, true);
        
        return [
            'success' => isset($publishData['id']),
            'platform' => 'instagram',
            'mediaId' => $publishData['id'] ?? null
        ];
    }
    
    /**
     * Upload to Facebook Reels
     */
    public static function uploadToFacebook($videoPath, $description, $credentials) {
        $accessToken = $credentials['access_token'] ?? null;
        $pageId = $credentials['page_id'] ?? null;
        
        if (!$accessToken || !$pageId) {
            return ['error' => 'Missing Facebook credentials'];
        }
        
        // Facebook Graph API for Video Upload
        $uploadUrl = "https://graph-video.facebook.com/v18.0/{$pageId}/videos";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => [
                'source' => new CURLFile($videoPath, 'video/mp4'),
                'description' => $description,
                'access_token' => $accessToken
            ],
            CURLOPT_TIMEOUT => 3600
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        return [
            'success' => isset($data['id']),
            'platform' => 'facebook',
            'videoId' => $data['id'] ?? null
        ];
    }
}
?>
