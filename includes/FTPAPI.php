<?php
/**
 * FTP API Client
 * Fetches and downloads videos from FTP server (supports Bunny CDN Storage)
 */

class FTPAPI {
    private $host;
    private $username;
    private $password;
    private $port;
    private $remotePath;
    private $connection;
    private $useSsl;
    private $accessKey; // For Bunny HTTP API fallback
    
    public function __construct($host, $username, $password, $port = 21, $remotePath = '/', $useSsl = false) {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->port = intval($port);
        $this->remotePath = '/' . ltrim(rtrim($remotePath, '/'), '/');
        if ($this->remotePath !== '/') $this->remotePath .= '/';
        $this->useSsl = $useSsl;
        $this->accessKey = $password; // Bunny uses password as access key
    }
    
    /**
     * Create from database settings
     */
    public static function fromSettings($pdo) {
        $settings = [];
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'ftp_%'");
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        if (empty($settings['ftp_host'])) {
            throw new Exception('FTP not configured. Go to Settings → FTP tab and add your Bunny Storage credentials.');
        }
        
        return new self(
            $settings['ftp_host'],
            $settings['ftp_username'] ?? '',
            $settings['ftp_password'] ?? '',
            $settings['ftp_port'] ?? 21,
            $settings['ftp_path'] ?? '/',
            !empty($settings['ftp_ssl'])
        );
    }
    
    /**
     * Check if this is Bunny CDN Storage
     */
    private function isBunnyStorage() {
        return strpos($this->host, 'bunnycdn.com') !== false || 
               strpos($this->host, 'bunny.net') !== false ||
               strpos($this->host, 'b-cdn.net') !== false;
    }
    
    /**
     * Connect to FTP server
     */
    public function connect() {
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
            throw new Exception("Could not connect to FTP: {$this->host}:{$this->port}. Check hostname and internet connection.");
        }
        
        // Set timeout for operations
        @ftp_set_option($this->connection, FTP_TIMEOUT_SEC, $timeout);
        
        if (!@ftp_login($this->connection, $this->username, $this->password)) {
            ftp_close($this->connection);
            throw new Exception("FTP login failed. Username: {$this->username}. For Bunny: username = storage zone name, password = access key");
        }
        
        // MUST enable passive mode for Bunny CDN
        ftp_pasv($this->connection, true);
        
        return true;
    }
    
    /**
     * Get list of video files - tries HTTP API for Bunny, falls back to FTP
     */
    public function getVideos($daysFilter = 30) {
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
     * Get videos by date range
     */
    public function getVideosByDateRange($startDate, $endDate) {
        // For Bunny CDN, try HTTP API first (more reliable)
        if ($this->isBunnyStorage()) {
            $videos = $this->getVideosViaHTTPByDateRange($startDate, $endDate);
            if (!empty($videos)) {
                return $videos;
            }
        }
        
        // Fallback to FTP
        return $this->getVideosViaFTPByDateRange($startDate, $endDate);
    }
    
    /**
     * Get videos via Bunny HTTP Storage API
     */
    private function getVideosViaHTTP($daysFilter = 30) {
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
                'AccessKey: ' . $this->accessKey,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30
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
                        'extension' => $extension
                    ];
                }
            }
        }
        
        return $videos;
    }
    
    /**
     * Get videos via Bunny HTTP Storage API by date range
     */
    private function getVideosViaHTTPByDateRange($startDate, $endDate) {
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
                'AccessKey: ' . $this->accessKey,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30
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
        
        // Convert date strings to timestamps for comparison
        $startTimestamp = strtotime($startDate);
        $endTimestamp = strtotime($endDate);
        
        foreach ($files as $file) {
            if ($file['IsDirectory'] ?? false) continue;
            
            $filename = $file['ObjectName'] ?? '';
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($extension, $videoExtensions)) {
                $dateCreated = isset($file['DateCreated']) ? strtotime($file['DateCreated']) : time();
                
                // Check if date falls within range
                if ($dateCreated >= $startTimestamp && $dateCreated <= ($endTimestamp + 86400)) { // Add 1 day to include end date
                    $videos[] = [
                        'guid' => $file['Guid'] ?? md5($file['ObjectName']),
                        'title' => pathinfo($filename, PATHINFO_FILENAME),
                        'filename' => $filename,
                        'remotePath' => $path . $filename,
                        'size' => $file['Length'] ?? 0,
                        'dateUploaded' => $file['DateCreated'] ?? null,
                        'extension' => $extension
                    ];
                }
            }
        }
        
        return $videos;
    }
    
    /**
     * Get videos via FTP by date range
     */
    private function getVideosViaFTPByDateRange($startDate, $endDate) {
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
        
        // Convert date strings to timestamps for comparison
        $startTimestamp = strtotime($startDate);
        $endTimestamp = strtotime($endDate);
        
        foreach ($files as $file) {
            $filename = basename($file);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($extension, $videoExtensions)) {
                $modTime = @ftp_mdtm($this->connection, $file);
                
                // Check if date falls within range
                if ($modTime !== -1 && $modTime >= $startTimestamp && $modTime <= ($endTimestamp + 86400)) { // Add 1 day to include end date
                    $size = @ftp_size($this->connection, $file);
                    
                    $videos[] = [
                        'guid' => md5($file),
                        'title' => pathinfo($filename, PATHINFO_FILENAME),
                        'filename' => $filename,
                        'remotePath' => $file,
                        'size' => $size > 0 ? $size : 0,
                        'dateUploaded' => $modTime > 0 ? date('Y-m-d H:i:s', $modTime) : null,
                        'extension' => $extension
                    ];
                }
            }
        }
        
        return $videos;
    }
    
    /**
     * Get videos via FTP
     */
    private function getVideosViaFTP($daysFilter = 30) {
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
                        'extension' => $extension
                    ];
                }
            }
        }
        
        return $videos;
    }
    
    /**
     * Download video - tries HTTP API for Bunny, falls back to FTP
     */
    public function downloadVideo($remotePath, $localPath = null) {
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
    private function downloadViaHTTP($remotePath, $localPath) {
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
                'AccessKey: ' . $this->accessKey
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 3600 // 1 hour for large files
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
    private function downloadViaFTP($remotePath, $localPath) {
        if (!$this->connection) {
            $this->connect();
        }
        
        $result = @ftp_get($this->connection, $localPath, $remotePath, FTP_BINARY);
        
        if (!$result) {
            throw new Exception("Failed to download via FTP: {$remotePath}");
        }
        
        return $localPath;
    }
    
    /**
     * Upload file to FTP
     */
    public function uploadVideo($localPath, $remotePath = null) {
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
            throw new Exception("Failed to upload to: {$remotePath}");
        }
        
        return $remotePath;
    }
    
    /**
     * Check if file exists on FTP
     */
    public function fileExists($remotePath) {
        if (!$this->connection) {
            $this->connect();
        }
        
        $size = @ftp_size($this->connection, $remotePath);
        return $size >= 0;
    }
    
    /**
     * Get file size
     */
    public function getFileSize($remotePath) {
        if (!$this->connection) {
            $this->connect();
        }
        
        return @ftp_size($this->connection, $remotePath);
    }
    
    /**
     * Close connection
     */
    public function disconnect() {
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
?>
