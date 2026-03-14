<?php

/**
 * Unified API Client Base Class
 * Provides standardized HTTP request handling, error management, and retry logic
 */
abstract class APIClient {
    protected string $baseUrl;
    protected array $config;
    protected array $defaultHeaders = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    protected int $timeout = 30;
    protected int $maxRetries = 3;
    protected int $retryDelay = 1000; // milliseconds
    protected int $rateLimit = 60; // requests per minute
    protected int $rateLimitWindow = 60000; // milliseconds
    protected int $lastRequestTime = 0;
    protected int $requestCount = 0;

    public function __construct(array $config = []) {
        $this->config = $config;
        $this->baseUrl = $config['base_url'] ?? '';
        $this->timeout = $config['timeout'] ?? 30;
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->rateLimit = $config['rate_limit'] ?? 60;
        $this->rateLimitWindow = $config['rate_limit_window'] ?? 60000;
        
        $this->initializeAuth();
    }

    /**
     * Initialize authentication headers
     */
    abstract protected function initializeAuth(): void;

    /**
     * Make HTTP request with retry logic and rate limiting
     */
    protected function request(string $method, string $endpoint, array $data = null, array $headers = []): array {
        $url = $this->baseUrl . $endpoint;
        $fullHeaders = array_merge($this->defaultHeaders, $headers);
        
        // Rate limiting
        $this->applyRateLimiting();
        
        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            try {
                $ch = curl_init();
                
                $options = [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => $fullHeaders,
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                ];

                if ($method === 'POST') {
                    $options[CURLOPT_POST] = true;
                    if ($data) {
                        $options[CURLOPT_POSTFIELDS] = json_encode($data);
                    }
                } elseif ($method === 'GET' && $data) {
                    $url .= '?' . http_build_query($data);
                    $options[CURLOPT_URL] = $url;
                } elseif ($method === 'DELETE') {
                    $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                } elseif ($method === 'PATCH') {
                    $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
                    if ($data) {
                        $options[CURLOPT_POSTFIELDS] = json_encode($data);
                    }
                }

                curl_setopt_array($ch, $options);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($error) {
                    throw new APIException("cURL Error: {$error}", 0, $httpCode);
                }

                $decoded = json_decode($response, true);

                // Handle HTTP errors
                if ($httpCode >= 400) {
                    throw new APIException(
                        "HTTP Error {$httpCode}: " . ($decoded['message'] ?? $response),
                        $httpCode
                    );
                }

                return [
                    'success' => true,
                    'http_code' => $httpCode,
                    'data' => $decoded,
                    'raw' => $response,
                ];

            } catch (APIException $e) {
                // Retry on specific errors
                if ($attempt < $this->maxRetries - 1 && $this->shouldRetry($e)) {
                    usleep($this->retryDelay * 1000);
                    continue;
                }
                
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'http_code' => $e->getHttpCode(),
                    'attempt' => $attempt + 1,
                ];
            }
        }
    }

    /**
     * Check if request should be retried
     */
    protected function shouldRetry(APIException $e): bool {
        $httpCode = $e->getHttpCode();
        
        // Retry on 5xx errors, timeouts, and rate limits
        return $httpCode >= 500 || 
               $httpCode === 429 || 
               $e->getCode() === 0; // cURL errors
    }

    /**
     * Apply rate limiting
     */
    protected function applyRateLimiting(): void {
        $currentTime = microtime(true) * 1000;
        
        if ($this->requestCount >= $this->rateLimit) {
            $elapsed = $currentTime - $this->lastRequestTime;
            if ($elapsed < $this->rateLimitWindow) {
                $sleepTime = $this->rateLimitWindow - $elapsed;
                usleep($sleepTime * 1000);
            }
            $this->requestCount = 0;
        }
        
        $this->lastRequestTime = $currentTime;
        $this->requestCount++;
    }

    /**
     * Test API connection
     */
    public function testConnection(): array {
        $result = $this->request('GET', '/health');
        
        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Connected successfully' : 'Connection failed',
            'http_code' => $result['http_code'] ?? 0,
        ];
    }
}

/**
 * Custom API Exception
 */
class APIException extends Exception {
    private int $httpCode;

    public function __construct(string $message, int $code = 0, int $httpCode = 0) {
        parent::__construct($message, $code);
        $this->httpCode = $httpCode;
    }

    public function getHttpCode(): int {
        return $this->httpCode;
    }
}

/**
 * API Client Factory
 * Creates and manages API client instances
 */
class APIClientFactory {
    private static array $instances = [];
    private static array $config;

    /**
     * Initialize factory with configuration
     */
    public static function initialize(array $config): void {
        self::$config = $config;
    }

    /**
     * Get API client instance
     */
    public static function getClient(string $type): APIClient {
        if (!isset(self::$instances[$type])) {
            self::$instances[$type] = self::createClient($type);
        }
        
        return self::$instances[$type];
    }

    /**
     * Create specific API client
     */
    private static function createClient(string $type): APIClient {
        switch ($type) {
            case 'postforme':
                return new PostForMeClient(self::$config['postforme'] ?? []);
            case 'ftp':
                return new FTPClient(self::$config['ftp'] ?? []);
            case 'bunny':
                return new FTPClient(self::$config['bunny'] ?? []);
            case 'github':
                return new GitHubClient(self::$config['github'] ?? []);
            case 'social':
                return new SocialMediaClient(self::$config['social'] ?? []);
            default:
                throw new InvalidArgumentException("Unknown API client type: {$type}");
        }
    }

    /**
     * Get configuration for specific client
     */
    public static function getClientConfig(string $type): array {
        return self::$config[$type] ?? [];
    }
}

/**
 * PostForMe API Client
 */
class PostForMeClient extends APIClient {
    protected string $apiKey;

    protected function initializeAuth(): void {
        $this->apiKey = $this->config['api_key'] ?? '';
        if (!$this->apiKey) {
            throw new InvalidArgumentException('PostForMe API key is required');
        }
        
        $this->defaultHeaders[] = 'Authorization: Bearer ' . $this->apiKey;
    }

    /**
     * Test PostForMe connection
     */
    public function testConnection(): array {
        $result = $this->request('GET', '/social-accounts');
        
        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Connected to PostForMe' : 'Connection failed',
            'http_code' => $result['http_code'] ?? 0,
            'accounts_count' => $result['data']['data'] ?? 0,
        ];
    }

    /**
     * Get connected social accounts
     */
    public function getAccounts(?string $platform = null): array {
        $params = [];
        if ($platform) {
            $params['platform'] = $platform;
        }
        
        $result = $this->request('GET', '/social-accounts', $params);
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to get accounts',
                'accounts' => [],
            ];
        }
        
        return [
            'success' => true,
            'accounts' => $result['data']['data'] ?? [],
        ];
    }

    /**
     * Create and publish social post
     */
    public function createPost(array $params): array {
        $required = ['caption', 'social_accounts'];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return [
                    'success' => false,
                    'error' => "Missing required field: {$field}",
                ];
            }
        }
        
        $result = $this->request('POST', '/social-posts', $params);
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to create post',
                'http_code' => $result['http_code'],
                'raw' => $result['raw'],
            ];
        }
        
        return [
            'success' => true,
            'post_id' => $result['data']['id'] ?? null,
            'data' => $result['data'],
        ];
    }

    /**
     * Get post status
     */
    public function getPost(string $postId): array {
        $result = $this->request('GET', "/social-posts/{$postId}");
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to get post',
            ];
        }
        
        return [
            'success' => true,
            'post' => $result['data'],
        ];
    }

    /**
     * List posts with pagination
     */
    public function listPosts(array $params = []): array {
        $result = $this->request('GET', '/social-posts', $params);
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to list posts',
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
            'raw' => $payload,
        ];
    }

    /**
     * Get post results
     */
    public function getPostResults(?string $postId = null): array {
        $endpoint = '/social-post-results';
        if ($postId) {
            $endpoint .= "?post_id={$postId}";
        }
        
        $result = $this->request('GET', $endpoint);
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['data']['message'] ?? 'Failed to get post results',
            ];
        }
        
        return [
            'success' => true,
            'results' => $result['data']['data'] ?? $result['data'],
        ];
    }

    /**
     * Delete post
     */
    public function deletePost(string $postId): array {
        $result = $this->request('DELETE', "/social-posts/{$postId}");
        
        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Post deleted' : ($result['data']['message'] ?? 'Failed to delete'),
        ];
    }

    /**
     * Cancel scheduled post
     */
    public function cancelPost(string $postId): array {
        $result = $this->request('POST', "/social-posts/{$postId}/cancel");
        
        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Post cancelled' : ($result['data']['message'] ?? 'Failed to cancel'),
        ];
    }

    /**
     * Upload video and post
     */
    public function postVideo(string $videoPath, string $caption, array $accountIds, array $options = []): array {
        // Check if video is already hosted (URL provided instead of path)
        if (filter_var($videoPath, FILTER_VALIDATE_URL)) {
            $mediaUrl = $videoPath;
        } else {
            // Check if local video file exists
            if (!file_exists($videoPath)) {
                return [
                    'success' => false,
                    'error' => 'Video file not found: ' . $videoPath,
                ];
            }
            
            // Upload video to Post for Me storage
            $uploadResult = $this->uploadMedia($videoPath);
            
            if (!$uploadResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Upload failed: ' . ($uploadResult['error'] ?? 'Unknown error'),
                    'upload_result' => $uploadResult,
                ];
            }
            
            $mediaUrl = $uploadResult['media_url'];
        }
        
        // Create post
        $postParams = [
            'caption' => $caption,
            'social_accounts' => $accountIds,
            'media' => [
                ['url' => $mediaUrl, 'type' => 'video'],
            ],
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
     * Upload media file
     */
    public function uploadMedia(string $filePath): array {
        // Step 1: Get upload URL
        $urlResult = $this->request('POST', '/media/create-upload-url');
        
        if (!$urlResult['success']) {
            return $urlResult;
        }
        
        $uploadUrl = $urlResult['data']['upload_url'] ?? null;
        $mediaUrl = $urlResult['data']['media_url'] ?? null;
        
        if (!$uploadUrl || !$mediaUrl) {
            return [
                'success' => false,
                'error' => 'Invalid upload URL response',
            ];
        }
        
        $fileSize = filesize($filePath);
        $fileHandle = fopen($filePath, 'r');
        
        if (!$fileHandle) {
            return [
                'success' => false,
                'error' => 'Cannot open file: ' . $filePath,
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
                'Content-Type: ' . $contentType,
            ],
            CURLOPT_TIMEOUT => 3600, // 1 hour for large uploads
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fileHandle);
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'Upload failed: ' . $error,
            ];
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'success' => false,
                'error' => 'Upload failed with HTTP ' . $httpCode,
                'response' => $response,
            ];
        }
        
        return [
            'success' => true,
            'media_url' => $mediaUrl,
        ];
    }
}

/**
 * FTP Client
 */
class FTPClient extends APIClient {
    private string $host;
    private string $username;
    private string $password;
    private int $port;
    private string $remotePath;
    private bool $useSsl;
    private $connection;

    protected function initializeAuth(): void {
        $this->host = $this->config['host'] ?? '';
        $this->username = $this->config['username'] ?? '';
        $this->password = $this->config['password'] ?? '';
        $this->port = $this->config['port'] ?? 21;
        $this->remotePath = $this->config['remote_path'] ?? '/';
        $this->useSsl = $this->config['use_ssl'] ?? false;
        
        if (!$this->host || !$this->username || !$this->password) {
            throw new InvalidArgumentException('FTP configuration is incomplete');
        }
    }

    /**
     * Connect to FTP server
     */
    public function connect(): bool {
        // Increase timeout for slow connections
        $timeout = 60;
        
        // Try SSL first for Bunny, then regular FTP
        if ($this->useSsl || $this->isBunnyStorage()) {
            $this->connection = @ftp_ssl_connect($this->host, $this->port, $timeout);
        }
        
        if (!$this->connection) {
            $this->connection = @ftp_connect($this->host, $this->port, $timeout);
        }
        
        if (!$this->connection) {
            throw new APIException("Could not connect to FTP: {$this->host}:{$this->port}");
        }
        
        // Set timeout for operations
        @ftp_set_option($this->connection, FTP_TIMEOUT_SEC, $timeout);
        
        if (!@ftp_login($this->connection, $this->username, $this->password)) {
            ftp_close($this->connection);
            throw new APIException("FTP login failed. Username: {$this->username}");
        }
        
        // MUST enable passive mode for Bunny CDN
        ftp_pasv($this->connection, true);
        
        return true;
    }

    /**
     * Check if this is Bunny CDN Storage
     */
    private function isBunnyStorage(): bool {
        return strpos($this->host, 'bunnycdn.com') !== false || 
               strpos($this->host, 'bunny.net') !== false ||
               strpos($this->host, 'b-cdn.net') !== false;
    }

    /**
     * Get list of video files
     */
    public function getVideos(int $daysFilter = 30): array {
        // For Bunny CDN, try HTTP API first (more reliable)
        if ($this->isBunnyStorage()) {
            $videos = $this->getVideosViaHTTP($daysFilter);
            if (!empty($videos)) {
                return $videos;
            }
        }
        
        // Fallback to FTP
        return $this->getVideosViaFTP($daysFilter);
    }

    /**
     * Get videos via Bunny HTTP Storage API
     */
    private function getVideosViaHTTP(int $daysFilter = 30): array {
        $storageZone = $this->username;
        $path = ltrim($this->remotePath, '/');
        
        // Determine the correct regional endpoint
        $endpoint = $this->host;
        if (strpos($endpoint, 'storage.bunnycdn.com') === false) {
            $endpoint = 'storage.bunnycdn.com';
        }
        
        $url = "https://{$endpoint}/{$storageZone}/{$path}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . $this->password, // Bunny uses password as access key
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $httpCode !== 200) {
            return []; // Will fall back to FTP
        }
        
        $files = json_decode($response, true);
        if (!is_array($files)) {
            return [];
        }
        
        $videos = [];
        $videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'wmv', 'm4v'];
        $cutoffTime = time() - ($daysFilter * 24 * 60 * 60);
        
        foreach ($files as $file) {
            if ($file['IsDirectory'] ?? false) continue;
            
            $filename = $file['ObjectName'] ?? '';
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($extension, $videoExtensions)) {
                $dateCreated = isset($file['DateCreated']) ? strtotime($file['DateCreated']) : time();
                
                if ($dateCreated >= $cutoffTime) {
                    $videos[] = [
                        'guid' => $file['Guid'] ?? md5($file['ObjectName']),
                        'title' => pathinfo($filename, PATHINFO_FILENAME),
                        'filename' => $filename,
                        'remotePath' => $path . $filename,
                        'size' => $file['Length'] ?? 0,
                        'dateUploaded' => $file['DateCreated'] ?? null,
                        'extension' => $extension,
                    ];
                }
            }
        }
        
        return $videos;
    }

    /**
     * Get videos via FTP
     */
    private function getVideosViaFTP(int $daysFilter = 30): array {
        if (!$this->connection) {
            $this->connect();
        }
        
        $files = @ftp_nlist($this->connection, $this->remotePath);
        
        if ($files === false) {
            // Try with -a flag for hidden files
            $files = @ftp_rawlist($this->connection, $this->remotePath);
            if ($files) {
                $files = array_map(function($line) {
                    $parts = preg_split('/\s+/', $line, 9);
                    return isset($parts[8]) ? $this->remotePath . $parts[8] : null;
                }, $files);
                $files = array_filter($files);
            }
        }
        
        if (!$files) {
            return [];
        }
        
        $videos = [];
        $videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'wmv', 'm4v'];
        $cutoffTime = time() - ($daysFilter * 24 * 60 * 60);
        
        foreach ($files as $file) {
            $filename = basename($file);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($extension, $videoExtensions)) {
                $modTime = @ftp_mdtm($this->connection, $file);
                
                if ($modTime === -1 || $modTime >= $cutoffTime) {
                    $size = @ftp_size($this->connection, $file);
                    
                    $videos[] = [
                        'guid' => md5($file),
                        'title' => pathinfo($filename, PATHINFO_FILENAME),
                        'filename' => $filename,
                        'remotePath' => $file,
                        'size' => $size > 0 ? $size : 0,
                        'dateUploaded' => $modTime > 0 ? date('Y-m-d H:i:s', $modTime) : null,
                        'extension' => $extension,
                    ];
                }
            }
        }
        
        return $videos;
    }

    /**
     * Download video
     */
    public function downloadVideo(string $remotePath, ?string $localPath = null): string {
        if (!$localPath) {
            $filename = basename($remotePath);
            $baseDir = (PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : getenv('HOME') . '/VideoWorkflow';
            $localPath = $baseDir . '/temp/' . $filename;
        }
        
        // Ensure directory exists
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        
        // For Bunny, try HTTP download first
        if ($this->isBunnyStorage()) {
            $result = $this->downloadViaHTTP($remotePath, $localPath);
            if ($result) {
                return $localPath;
            }
        }
        
        // Fallback to FTP
        return $this->downloadViaFTP($remotePath, $localPath);
    }

    /**
     * Download via Bunny HTTP API
     */
    private function downloadViaHTTP(string $remotePath, string $localPath): bool {
        $storageZone = $this->username;
        $path = ltrim($remotePath, '/');
        
        $endpoint = $this->host;
        if (strpos($endpoint, 'storage.bunnycdn.com') === false) {
            $endpoint = 'storage.bunnycdn.com';
        }
        
        $url = "https://{$endpoint}/{$storageZone}/{$path}";
        
        $fp = fopen($localPath, 'w+');
        if (!$fp) {
            return false;
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . $this->password,
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 3600, // 1 hour for large files
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        
        if ($httpCode === 200 && filesize($localPath) > 0) {
            return true;
        }
        
        @unlink($localPath);
        return false;
    }

    /**
     * Download via FTP
     */
    private function downloadViaFTP(string $remotePath, string $localPath): string {
        if (!$this->connection) {
            $this->connect();
        }
        
        $result = @ftp_get($this->connection, $localPath, $remotePath, FTP_BINARY);
        
        if (!$result) {
            throw new APIException("Failed to download via FTP: {$remotePath}");
        }
        
        return $localPath;
    }

    /**
     * Upload file to FTP
     */
    public function uploadVideo(string $localPath, ?string $remotePath = null): string {
        if (!$this->connection) {
            $this->connect();
        }
        
        if (!$remotePath) {
            $filename = basename($localPath);
            $remotePath = $this->remotePath . 'processed/' . $filename;
        }
        
        // Create remote directory if needed
        $remoteDir = dirname($remotePath);
        @ftp_mkdir($this->connection, $remoteDir);
        
        $result = @ftp_put($this->connection, $remotePath, $localPath, FTP_BINARY);
        
        if (!$result) {
            throw new APIException("Failed to upload to: {$remotePath}");
        }
        
        return $remotePath;
    }

    /**
     * Check if file exists
     */
    public function fileExists(string $remotePath): bool {
        if (!$this->connection) {
            $this->connect();
        }
        
        $size = @ftp_size($this->connection, $remotePath);
        return $size >= 0;
    }

    /**
     * Get file size
     */
    public function getFileSize(string $remotePath): int {
        if (!$this->connection) {
            $this->connect();
        }
        
        return @ftp_size($this->connection, $remotePath);
    }

    /**
     * Disconnect
     */
    public function disconnect(): void {
        if ($this->connection) {
            ftp_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct() {
        $this->disconnect();
    }
}

/**
 * GitHub Client
 */
class GitHubClient extends APIClient {
    protected string $token;
    protected string $owner;
    protected string $repo;
    protected string $workflow;
    protected string $ref;

    protected function initializeAuth(): void {
        $this->token = $this->config['token'] ?? '';
        $this->owner = $this->config['owner'] ?? '';
        $this->repo = $this->config['repo'] ?? '';
        $this->workflow = $this->config['workflow'] ?? '';
        $this->ref = $this->config['ref'] ?? 'main';
        
        if (!$this->token || !$this->owner || !$this->repo || !$this->workflow) {
            throw new InvalidArgumentException('GitHub configuration is incomplete');
        }
        
        $this->defaultHeaders[] = 'Authorization: Bearer ' . $this->token;
        $this->defaultHeaders[] = 'X-GitHub-Api-Version: 2022-11-28';
        $this->defaultHeaders[] = 'User-Agent: VideoWorkflow-GitHubRunner/1.0';
    }

    /**
     * Test GitHub connection
     */
    public function testConnection(): array {
        $workflow = rawurlencode($this->workflow);
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/actions/workflows/{$workflow}";
        $res = $this->request('GET', $url);

        if (!$res['success']) {
            return $res;
        }

        if ($res['http_code'] !== 200) {
            return [
                'success' => false,
                'error' => "GitHub API error ({$res['http_code']})",
                'details' => $res['data'] ?? '',
            ];
        }

        return [
            'success' => true,
            'message' => 'GitHub workflow is reachable.',
            'workflow' => $res['data']['name'] ?? $this->workflow,
        ];
    }

    /**
     * Dispatch automation to GitHub runner
     */
    public function dispatchAutomation(int $automationId, string $triggerSource = 'manual', array $payload = []): array {
        $workflow = rawurlencode($this->workflow);
        $dispatchUrl = "https://api.github.com/repos/{$this->owner}/{$this->repo}/actions/workflows/{$workflow}/dispatches";
        
        $inputs = [
            'automation_id' => (string)$automationId,
            'trigger_source' => $triggerSource,
        ];
        
        // Merge custom payload
        if (!empty($payload)) {
            $inputs = array_merge($inputs, $payload);
        }
        
        $dispatchPayload = [
            'ref' => $this->ref,
            'inputs' => $inputs,
        ];

        $dispatchStartedAt = time();
        $res = $this->request('POST', $dispatchUrl, $dispatchPayload);
        if (!$res['success']) {
            return $res;
        }

        if (!in_array($res['http_code'], [200, 201, 202, 204], true)) {
            return [
                'success' => false,
                'error' => "Dispatch failed ({$res['http_code']})",
                'details' => $res['data'] ?? '',
            ];
        }

        $workflowUrl = "https://github.com/{$this->owner}/{$this->repo}/actions/workflows/{$this->workflow}";
        // Dispatch API does not return a run id immediately; poll briefly to capture fresh run metadata.
        $runMeta = [];
        for ($i = 0; $i < 5; $i++) {
            $runMeta = $this->findLatestRun($automationId, $dispatchStartedAt - 2, 'workflow_dispatch');
            if (!empty($runMeta['run_id'])) {
                break;
            }
            usleep(1500000);
        }

        return [
            'success' => true,
            'message' => 'GitHub workflow dispatched.',
            'workflow_url' => $workflowUrl,
            'run_id' => $runMeta['run_id'] ?? null,
            'run_url' => $runMeta['run_url'] ?? $workflowUrl,
        ];
    }

    /**
     * Find latest run for automation
     */
    private function findLatestRun(int $automationId, ?int $minCreatedAt = null, ?string $eventType = null): array {
        $encodedWorkflow = rawurlencode($this->workflow);
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/actions/workflows/{$encodedWorkflow}/runs?per_page=10";
        if ($eventType !== null && $eventType !== '') {
            $url .= '&event=' . rawurlencode($eventType);
        }
        
        $res = $this->request('GET', $url);
        if (!$res['success'] || $res['http_code'] !== 200 || !is_array($res['data'])) {
            return [];
        }

        $runs = $res['data']['workflow_runs'] ?? [];
        if (!is_array($runs)) {
            return [];
        }

        $recentRuns = [];
        foreach ($runs as $run) {
            if (!is_array($run)) {
                continue;
            }

            $createdAtTs = isset($run['created_at']) ? strtotime((string)$run['created_at']) : false;
            if ($minCreatedAt !== null && $createdAtTs !== false && $createdAtTs < $minCreatedAt) {
                continue;
            }

            $recentRuns[] = $run;
        }

        foreach ($recentRuns as $run) {
            $runId = $run['id'] ?? null;
            $runUrl = $run['html_url'] ?? null;
            $displayTitle = (string)($run['display_title'] ?? '');
            $name = (string)($run['name'] ?? '');

            if (stripos($displayTitle, (string)$automationId) !== false || stripos($name, (string)$automationId) !== false) {
                return ['run_id' => $runId, 'run_url' => $runUrl];
            }
        }

        if (!empty($recentRuns[0]) && is_array($recentRuns[0])) {
            return [
                'run_id' => $recentRuns[0]['id'] ?? null,
                'run_url' => $recentRuns[0]['html_url'] ?? null,
            ];
        }

        return [];
    }

    /**
     * Get workflow runs
     */
    public function getWorkflowRuns(int $page = 1, int $perPage = 100): array {
        $encodedWorkflow = rawurlencode($this->workflow);
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/actions/workflows/{$encodedWorkflow}/runs";
        $params = [
            'page' => $page,
            'per_page' => $perPage,
        ];
        
        $res = $this->request('GET', $url, $params);
        if (!$res['success']) {
            return $res;
        }
        
        return [
            'success' => true,
            'runs' => $res['data']['workflow_runs'] ?? [],
            'total_count' => $res['data']['total_count'] ?? 0,
        ];
    }

    /**
     * Get run details
     */
    public function getRunDetails(string $runId): array {
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/actions/runs/{$runId}";
        $res = $this->request('GET', $url);
        
        if (!$res['success']) {
            return $res;
        }
        
        return [
            'success' => true,
            'run' => $res['data'],
        ];
    }

    /**
     * Get workflow jobs
     */
    public function getWorkflowJobs(string $runId): array {
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/actions/runs/{$runId}/jobs";
        $res = $this->request('GET', $url);
        
        if (!$res['success']) {
            return $res;
        }
        
        return [
            'success' => true,
            'jobs' => $res['data']['jobs'] ?? [],
        ];
    }
}

/**
 * Social Media Client (abstract)
 */
abstract class SocialMediaClient extends APIClient {
    protected array $supportedPlatforms = [];

    /**
     * Upload video to platform
     */
    abstract public function uploadVideo(string $videoPath, string $caption, array $credentials): array;

    /**
     * Get platform info
     */
    public function getPlatformInfo(string $platform): ?array {
        return $this->supportedPlatforms[$platform] ?? null;
    }

    /**
     * Get supported platforms
     */
    public function getSupportedPlatforms(): array {
        return $this->supportedPlatforms;
    }
}

/**
 * YouTube Client
 */
class YouTubeClient extends SocialMediaClient {
    protected array $supportedPlatforms = [
        'youtube' => [
            'name' => 'YouTube',
            'icon' => 'youtube',
            'color' => 'red',
            'supports_video' => true,
            'supports_image' => false,
            'supports_reels' => true,
        ],
    ];

    protected function initializeAuth(): void {
        $this->defaultHeaders[] = 'Authorization: Bearer ' . ($this->config['access_token'] ?? '');
    }

    public function uploadVideo(string $videoPath, string $title, array $credentials): array {
        $accessToken = $credentials['access_token'] ?? null;
        
        if (!$accessToken) {
            return ['error' => 'No YouTube access token provided'];
        }
        
        // Create video metadata
        $metadata = [
            'snippet' => [
                'title' => $title,
                'description' => $credentials['description'] ?? '',
                'tags' => $credentials['tags'] ?? ['shorts', 'viral'],
                'categoryId' => '22', // People & Blogs
            ],
            'status' => [
                'privacyStatus' => $credentials['privacy'] ?? 'public',
                'selfDeclaredMadeForKids' => false,
            ]
        ];
        
        // Step 1: Initialize upload (get resumable upload URL)
        $initUrl = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';
        
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
                'X-Upload-Content-Length: ' . filesize($videoPath),
            ],
            CURLOPT_POSTFIELDS => json_encode($metadata),
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
                'Content-Type: video/mp4',
            ],
            CURLOPT_TIMEOUT => 3600,
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
            'url' => 'https://youtube.com/shorts/' . ($result['id'] ?? ''),
        ];
    }
}

/**
 * TikTok Client
 */
class TikTokClient extends SocialMediaClient {
    protected array $supportedPlatforms = [
        'tiktok' => [
            'name' => 'TikTok',
            'icon' => 'tiktok',
            'color' => 'black',
            'supports_video' => true,
            'supports_image' => false,
            'supports_reels' => true,
        ],
    ];

    protected function initializeAuth(): void {
        $this->defaultHeaders[] = 'Authorization: Bearer ' . ($this->config['access_token'] ?? '');
    }

    public function uploadVideo(string $videoPath, string $caption, array $credentials): array {
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
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'post_info' => [
                    'title' => $caption,
                    'privacy_level' => 'PUBLIC_TO_EVERYONE',
                    'disable_comment' => false,
                    'disable_duet' => false,
                    'disable_stitch' => false,
                ],
                'source_info' => [
                    'source' => 'FILE_UPLOAD',
                    'video_size' => filesize($videoPath),
                    'chunk_size' => min(filesize($videoPath), 10000000), // 10MB chunks
                ]
            ]),
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
                'Content-Type: video/mp4',
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'success' => $httpCode === 200,
            'platform' => 'tiktok',
            'response' => json_decode($response, true),
        ];
    }
}

/**
 * Instagram Client
 */
class InstagramClient extends SocialMediaClient {
    protected array $supportedPlatforms = [
        'instagram' => [
            'name' => 'Instagram',
            'icon' => 'instagram',
            'color' => 'pink',
            'supports_video' => true,
            'supports_image' => true,
            'supports_reels' => true,
        ],
    ];

    protected function initializeAuth(): void {
        // Instagram uses Facebook Graph API, so we'll need page ID and access token
    }

    public function uploadVideo(string $videoPath, string $caption, array $credentials): array {
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
                'access_token' => $accessToken,
            ]),
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
                'access_token' => $accessToken,
            ]),
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $publishData = json_decode($response, true);
        
        return [
            'success' => isset($publishData['id']),
            'platform' => 'instagram',
            'mediaId' => $publishData['id'] ?? null,
        ];
    }
}

/**
 * Facebook Client
 */
class FacebookClient extends SocialMediaClient {
    protected array $supportedPlatforms = [
        'facebook' => [
            'name' => 'Facebook',
            'icon' => 'facebook',
            'color' => 'blue',
            'supports_video' => true,
            'supports_image' => true,
            'supports_reels' => true,
        ],
    ];

    protected function initializeAuth(): void {
        // Facebook uses page access token
    }

    public function uploadVideo(string $videoPath, string $description, array $credentials): array {
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
                'access_token' => $accessToken,
            ],
            CURLOPT_TIMEOUT => 3600,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        return [
            'success' => isset($data['id']),
            'platform' => 'facebook',
            'videoId' => $data['id'] ?? null,
        ];
    }
}

/**
 * Configuration Management
 */
class APIConfigManager {
    private static array $config = [];
    private static string $configPath = 'api-config.json';

    /**
     * Load configuration from file or database
     */
    public static function loadConfig(): void {
        // Try to load from file first
        if (file_exists(self::$configPath)) {
            $config = json_decode(file_get_contents(self::$configPath), true);
            if (is_array($config)) {
                self::$config = $config;
                return;
            }
        }
        
        // Fallback to database
        self::$config = self::loadFromDatabase();
    }

    /**
     * Load configuration from database
     */
    private static function loadFromDatabase(): array {
        // This would query your database for API credentials
        // Example structure:
        return [
            'postforme' => [
                'api_key' => 'your-postforme-api-key',
                'base_url' => 'https://api.postforme.dev/v1',
                'timeout' => 120,
            ],
            'ftp' => [
                'host' => 'ftp.example.com',
                'username' => 'ftp-user',
                'password' => 'ftp-pass',
                'port' => 21,
                'remote_path' => '/videos',
                'use_ssl' => false,
            ],
            'bunny' => [
                'api_key' => 'bunny-api-key',
                'library_id' => 'your-library-id',
                'storage_zone' => 'your-storage-zone',
                'cdn_hostname' => 'cdn.bunny.net',
            ],
            'github' => [
                'token' => 'github-token',
                'owner' => 'your-username',
                'repo' => 'your-repo',
                'workflow' => 'automation-runner.yml',
                'ref' => 'main',
            ],
            'social' => [
                'youtube' => [
                    'access_token' => 'youtube-token',
                ],
                'tiktok' => [
                    'access_token' => 'tiktok-token',
                ],
                'instagram' => [
                    'access_token' => 'instagram-token',
                    'user_id' => 'user-id',
                ],
                'facebook' => [
                    'access_token' => 'facebook-token',
                    'page_id' => 'page-id',
                ],
            ],
        ];
    }

    /**
     * Get configuration for specific client
     */
    public static function getConfig(string $clientType): array {
        return self::$config[$clientType] ?? [];
    }

    /**
     * Update configuration
     */
    public static function updateConfig(string $clientType, array $config): bool {
        self::$config[$clientType] = array_merge(self::$config[$clientType] ?? [], $config);
        return self::saveConfig();
    }

    /**
     * Save configuration to file
     */
    private static function saveConfig(): bool {
        $json = json_encode(self::$config, JSON_PRETTY_PRINT);
        return file_put_contents(self::$configPath, $json) !== false;
    }

    /**
     * Get all configuration
     */
    public static function getAllConfig(): array {
        return self::$config;
    }
}

/**
 * Configuration Helper Functions
 */
class ConfigHelper {
    /**
     * Get API key from configuration
     */
    public static function getAPIKey(string $service): ?string {
        $config = APIConfigManager::getConfig($service);
        return $config['api_key'] ?? null;
    }

    /**
     * Get FTP configuration
     */
    public static function getFTPConfig(): array {
        return APIConfigManager::getConfig('ftp');
    }

    /**
     * Get Bunny CDN configuration
     */
    public static function getBunnyConfig(): array {
        return APIConfigManager::getConfig('bunny');
    }

    /**
     * Get GitHub configuration
     */
    public static function getGitHubConfig(): array {
        return APIConfigManager::getConfig('github');
    }

    /**
     * Get social media credentials
     */
    public static function getSocialCredentials(string $platform): array {
        $socialConfig = APIConfigManager::getConfig('social');
        return $socialConfig[$platform] ?? [];
    }
}

/**
 * Usage Examples - How existing code would be refactored
 */
class APIClientUsageExamples {
    /**
     * Example: PostForMe usage
     */
    public static function examplePostForMe(): array {
        // Old way:
        // require_once 'includes/PostForMeAPI.php';
        // $postForMe = new PostForMeAPI($apiKey);
        // $result = $postForMe->createPost($params);
        
        // New way:
        $client = APIClientFactory::getClient('postforme');
        $result = $client->createPost([
            'caption' => 'Test post',
            'social_accounts' => ['account-id-1', 'account-id-2'],
            'media' => [
                ['url' => 'https://example.com/video.mp4', 'type' => 'video'],
            ],
        ]);
        
        return $result;
    }

    /**
     * Example: FTP usage
     */
    public static function exampleFTP(): array {
        // Old way:
        // require_once 'includes/FTPAPI.php';
        // $ftp = new FTPAPI($host, $username, $password);
        // $ftp->connect();
        // $videos = $ftp->getVideos();
        
        // New way:
        $client = APIClientFactory::getClient('ftp');
        try {
            $client->connect();
            $videos = $client->getVideos();
            $client->disconnect();
            
            return [
                'success' => true,
                'videos' => $videos,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Example: GitHub usage
     */
    public static function exampleGitHub(): array {
        // Old way:
        // require_once 'includes/GitHubRunner.php';
        // $github = new GitHubRunner($pdo);
        // $result = $github->dispatchAutomation($automationId);
        
        // New way:
        $client = APIClientFactory::getClient('github');
        $result = $client->dispatchAutomation(123);
        
        return $result;
    }

    /**
     * Example: Social media usage
     */
    public static function exampleSocialMedia(): array {
        // Old way:
        // require_once 'includes/SocialMediaUploader.php';
        // $result = SocialMediaUploader::uploadToYouTube($videoPath, $title, $credentials);
        
        // New way:
        $youtubeClient = APIClientFactory::getClient('social');
        $result = $youtubeClient->uploadVideo(
            '/path/to/video.mp4',
            'Test YouTube Video',
            ConfigHelper::getSocialCredentials('youtube')
        );
        
        return $result;
    }

    /**
     * Example: Using configuration manager
     */
    public static function exampleConfigManagement(): array {
        // Load configuration
        APIConfigManager::loadConfig();
        
        // Get specific configuration
        $postformeConfig = APIConfigManager::getConfig('postforme');
        $ftpConfig = APIConfigManager::getConfig('ftp');
        
        // Update configuration
        $newConfig = [
            'api_key' => 'new-api-key',
        ];
        $updateSuccess = APIConfigManager::updateConfig('postforme', $newConfig);
        
        return [
            'success' => true,
            'postforme_config' => $postformeConfig,
            'ftp_config' => $ftpConfig,
            'update_success' => $updateSuccess,
        ];
    }
}

// Initialize the API client factory with configuration
APIConfigManager::loadConfig();
APIClientFactory::initialize(APIConfigManager::getAllConfig());

// Example usage
if (php_sapi_name() === 'cli') {
    echo "API Client Consolidation System Initialized\n";
    
    // Test PostForMe connection
    try {
        $postforme = APIClientFactory::getClient('postforme');
        $test = $postforme->testConnection();
        echo "PostForMe: " . ($test['success'] ? "Connected" : "Failed: " . ($test['message'] ?? 'Unknown error')) . "\n";
        
        // Test FTP connection
        $ftp = APIClientFactory::getClient('ftp');
        try {
            $ftp->connect();
            echo "FTP: Connected\n";
            $ftp->disconnect();
        } catch (Exception $e) {
            echo "FTP: Failed to connect - " . $e->getMessage() . "\n";
        }
        
        // Test GitHub connection
        $github = APIClientFactory::getClient('github');
        $githubTest = $github->testConnection();
        echo "GitHub: " . ($githubTest['success'] ? "Connected" : "Failed: " . ($githubTest['error'] ?? 'Unknown error')) . "\n";
        
    } catch (Exception $e) {
        echo "Initialization error: " . $e->getMessage() . "\n";
    }
}
?>