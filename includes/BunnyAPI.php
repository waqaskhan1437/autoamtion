<?php
/**
 * Bunny CDN Stream API Integration
 * Handles video fetching, downloading, and uploading
 */

class BunnyAPI {
    private $apiKey;
    private $libraryId;
    private $storageZone;
    private $cdnHostname;
    private $apiUrl = 'https://video.bunnycdn.com/library';
    private $storageUrl = 'https://storage.bunnycdn.com';
    
    public function __construct($apiKey, $libraryId, $storageZone = null, $cdnHostname = null) {
        $this->apiKey = $apiKey;
        $this->libraryId = $libraryId;
        $this->storageZone = $storageZone;
        $this->cdnHostname = $cdnHostname;
    }
    
    /**
     * Get list of videos from library
     * @param int $page Page number
     * @param int $perPage Items per page
     * @param string $orderBy Order by field
     */
    public function getVideos($page = 1, $perPage = 100, $orderBy = 'date') {
        $url = "{$this->apiUrl}/{$this->libraryId}/videos?page={$page}&itemsPerPage={$perPage}&orderBy={$orderBy}";
        
        return $this->request('GET', $url);
    }
    
    /**
     * Get videos from last N days
     */
    public function getRecentVideos($days = 30) {
        $allVideos = [];
        $page = 1;
        $cutoffDate = strtotime("-{$days} days");
        
        do {
            $response = $this->getVideos($page, 100, 'date');
            
            if (isset($response['error'])) {
                return $response;
            }
            
            $items = $response['items'] ?? [];
            
            foreach ($items as $video) {
                $videoDate = strtotime($video['dateUploaded']);
                if ($videoDate >= $cutoffDate) {
                    $allVideos[] = $video;
                } else {
                    // Videos are sorted by date, so we can stop
                    break 2;
                }
            }
            
            $page++;
            $hasMore = count($items) === 100;
            
        } while ($hasMore);
        
        return $allVideos;
    }
    
    /**
     * Get videos by date range
     */
    public function getVideosByDateRange($startDate, $endDate) {
        $allVideos = [];
        $page = 1;
        
        // Convert date strings to timestamps for comparison
        $startTimestamp = strtotime($startDate);
        $endTimestamp = strtotime($endDate);
        
        do {
            $response = $this->getVideos($page, 100, 'date');
            
            if (isset($response['error'])) {
                return $response;
            }
            
            $items = $response['items'] ?? [];
            
            foreach ($items as $video) {
                $videoDate = strtotime($video['dateUploaded']);
                // Check if date falls within range (include end date)
                if ($videoDate >= $startTimestamp && $videoDate <= ($endTimestamp + 86400)) {
                    $allVideos[] = $video;
                } else if ($videoDate < $startTimestamp) {
                    // Since videos are sorted by date, if we encounter a video older than start date,
                    // we can break early (assuming descending order)
                    break 2;
                }
            }
            
            $page++;
            $hasMore = count($items) === 100;
            
        } while ($hasMore);
        
        return $allVideos;
    }
    
    /**
     * Get single video details
     */
    public function getVideo($videoId) {
        $url = "{$this->apiUrl}/{$this->libraryId}/videos/{$videoId}";
        return $this->request('GET', $url);
    }
    
    /**
     * Get direct download URL for video
     */
    public function getDownloadUrl($videoId) {
        $video = $this->getVideo($videoId);
        
        if (isset($video['error'])) {
            return null;
        }
        
        // Get the original file URL
        if ($this->cdnHostname) {
            return "https://{$this->cdnHostname}/{$videoId}/original";
        }
        
        // Fallback to storage zone
        if ($this->storageZone) {
            return "{$this->storageUrl}/{$this->storageZone}/{$videoId}/original";
        }
        
        return null;
    }
    
    /**
     * Download video to local path
     */
    public function downloadVideo($videoId, $outputPath) {
        $url = $this->getDownloadUrl($videoId);
        
        if (!$url) {
            return ['error' => 'Could not get download URL'];
        }
        
        $fp = fopen($outputPath, 'w+');
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 3600, // 1 hour for large files
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . $this->apiKey
            ]
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        
        if ($error) {
            unlink($outputPath);
            return ['error' => 'Download failed: ' . $error];
        }
        
        if ($httpCode !== 200) {
            unlink($outputPath);
            return ['error' => 'HTTP Error: ' . $httpCode];
        }
        
        return [
            'success' => true,
            'path' => $outputPath,
            'size' => filesize($outputPath)
        ];
    }
    
    /**
     * Upload video to Bunny Stream
     */
    public function uploadVideo($filePath, $title) {
        // First create a video entry
        $createUrl = "{$this->apiUrl}/{$this->libraryId}/videos";
        $createResponse = $this->request('POST', $createUrl, [
            'title' => $title
        ]);
        
        if (isset($createResponse['error'])) {
            return $createResponse;
        }
        
        $videoId = $createResponse['guid'];
        
        // Upload the file
        $uploadUrl = "{$this->apiUrl}/{$this->libraryId}/videos/{$videoId}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_PUT => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_INFILE => fopen($filePath, 'r'),
            CURLOPT_INFILESIZE => filesize($filePath),
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . $this->apiKey,
                'Content-Type: application/octet-stream'
            ],
            CURLOPT_TIMEOUT => 3600
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['error' => 'Upload failed with code: ' . $httpCode];
        }
        
        return [
            'success' => true,
            'videoId' => $videoId,
            'response' => json_decode($response, true)
        ];
    }
    
    /**
     * Delete video from library
     */
    public function deleteVideo($videoId) {
        $url = "{$this->apiUrl}/{$this->libraryId}/videos/{$videoId}";
        return $this->request('DELETE', $url);
    }
    
    /**
     * Make API request
     */
    private function request($method, $url, $data = null) {
        $ch = curl_init();
        
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ];
        
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if ($data) {
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        } elseif ($method === 'DELETE') {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }
        
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['error' => 'API Error: ' . $error];
        }
        
        $decoded = json_decode($response, true);
        
        if ($httpCode >= 400) {
            return [
                'error' => 'HTTP ' . $httpCode,
                'message' => $decoded['message'] ?? $response
            ];
        }
        
        return $decoded;
    }
}
?>
