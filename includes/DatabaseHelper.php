<?php
/**
 * DatabaseHelper Class
 * Centralized database operations for the Video Workflow Manager
 * 
 * Provides a unified interface for all database operations to reduce code duplication
 * and improve maintainability.
 * 
 * @author Kilo
 * @version 1.0
 */

class DatabaseHelper {
    /**
     * Execute a query with prepared statement
     * 
     * @param string $sql The SQL query to execute
     * @param array $params Array of parameters for prepared statement
     * @return PDOStatement The PDO statement object
     */
    public static function query($sql, $params = []) {
        global $pdo;
        if (!isset($pdo)) {
            throw new Exception("Database connection not established");
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Fetch all rows from a query
     * 
     * @param string $sql The SQL query to execute
     * @param array $params Array of parameters for prepared statement
     * @return array Array of all rows
     */
    public static function fetchAll($sql, $params = []) {
        return self::query($sql, $params)->fetchAll();
    }
    
    /**
     * Fetch a single row from a query
     * 
     * @param string $sql The SQL query to execute
     * @param array $params Array of parameters for prepared statement
     * @return array|null Single row or null if no results
     */
    public static function fetch($sql, $params = []) {
        return self::query($sql, $params)->fetch();
    }
    
    /**
     * Fetch a single column value from a query
     * 
     * @param string $sql The SQL query to execute
     * @param array $params Array of parameters for prepared statement
     * @return mixed Single column value or null
     */
    public static function fetchColumn($sql, $params = []) {
        return self::query($sql, $params)->fetchColumn();
    }
    
    /**
     * Insert a new record and return the inserted ID
     * 
     * @param string $table The table name
     * @param array $data Associative array of column => value pairs
     * @return int The ID of the inserted record
     */
    public static function insert($table, $data) {
        global $pdo;
        
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
        
        return $pdo->lastInsertId();
    }
    
    /**
     * Update existing records
     * 
     * @param string $table The table name
     * @param array $data Associative array of column => value pairs
     * @param string $where The WHERE clause (without WHERE keyword)
     * @param array $whereParams Array of parameters for WHERE clause
     * @return int Number of affected rows
     */
    public static function update($table, $data, $where, $whereParams = []) {
        global $pdo;
        
        $setParts = [];
        $params = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = "$column = ?";
            $params[] = $value;
        }
        
        $sql = "UPDATE $table SET " . implode(', ', $setParts);
        if ($where) {
            $sql .= " WHERE $where";
        }
        
        $params = array_merge($params, $whereParams);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount();
    }
    
    /**
     * Delete records
     * 
     * @param string $table The table name
     * @param string $where The WHERE clause (without WHERE keyword)
     * @param array $params Array of parameters for WHERE clause
     * @return int Number of affected rows
     */
    public static function delete($table, $where, $params = []) {
        global $pdo;
        
        $sql = "DELETE FROM $table WHERE $where";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount();
    }
    
    /**
     * Get a single record by ID
     * 
     * @param string $table The table name
     * @param int $id The ID of the record to fetch
     * @param string $idColumn The ID column name (default: 'id')
     * @return array|null The record or null if not found
     */
    public static function getById($table, $id, $idColumn = 'id') {
        $sql = "SELECT * FROM $table WHERE $idColumn = ?";
        return self::fetch($sql, [$id]);
    }
    
    /**
     * Get all records from a table
     * 
     * @param string $table The table name
     * @param string $orderBy Optional ORDER BY clause
     * @return array Array of all records
     */
    public static function getAll($table, $orderBy = '') {
        $sql = "SELECT * FROM $table";
        if ($orderBy) {
            $sql .= " ORDER BY $orderBy";
        }
        return self::fetchAll($sql);
    }
    
    /**
     * Count records in a table
     * 
     * @param string $table The table name
     * @param string $where Optional WHERE clause
     * @param array $params Array of parameters for WHERE clause
     * @return int Number of records
     */
    public static function count($table, $where = '', $params = []) {
        $sql = "SELECT COUNT(*) FROM $table";
        if ($where) {
            $sql .= " WHERE $where";
        }
        return (int)self::fetchColumn($sql, $params);
    }
    
    /**
     * Check if a record exists
     * 
     * @param string $table The table name
     * @param string $where The WHERE clause (without WHERE keyword)
     * @param array $params Array of parameters for WHERE clause
     * @return bool True if record exists, false otherwise
     */
    public static function exists($table, $where, $params = []) {
        $sql = "SELECT 1 FROM $table WHERE $where LIMIT 1";
        return (bool)self::fetchColumn($sql, $params);
    }
    
    /**
     * Execute a transaction
     * 
     * @param callable $callback Callback function that performs database operations
     * @return mixed The result of the callback function
     * @throws Exception If any operation fails
     */
    public static function transaction(callable $callback) {
        global $pdo;
        
        try {
            $pdo->beginTransaction();
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get database connection
     * 
     * @return PDO The PDO database connection
     */
    public static function getConnection() {
        global $pdo;
        if (!isset($pdo)) {
            throw new Exception("Database connection not established");
        }
        return $pdo;
    }
    
    /**
     * Get database driver name
     * 
     * @return string Database driver name
     */
    public static function getDriver() {
        global $pdo;
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
    
    /**
     * Quote a value for SQL
     * 
     * @param mixed $value The value to quote
     * @return string Quoted value
     */
    public static function quote($value) {
        global $pdo;
        return $pdo->quote($value);
    }
    
    /**
     * Get last insert ID
     * 
     * @return string Last insert ID
     */
    public static function lastInsertId() {
        global $pdo;
        return $pdo->lastInsertId();
    }
    
    /**
     * Check if a table exists
     * 
     * @param string $table The table name
     * @return bool True if table exists, false otherwise
     */
    public static function tableExists($table) {
        $sql = "SHOW TABLES LIKE ?";
        return (bool)self::fetchColumn($sql, [$table]);
    }
    
    /**
     * Get table columns
     * 
     * @param string $table The table name
     * @return array Array of column names
     */
    public static function getTableColumns($table) {
        $sql = "SHOW COLUMNS FROM $table";
        $result = self::fetchAll($sql);
        return array_column($result, 'Field');
    }
}
?>

    /**
     * Check if database helper is initialized
     * 
     * @return bool True if initialized, false otherwise
     */
    public static function isInitialized(): bool {
        return self::$initialized;
    }

    /**
     * Get PostForMe API key and settings
     * 
     * @return array{api_key: string, project_type: string} API key and project type
     * @throws Exception if not configured
     */
    public static function getPostForMeSettings(): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("
            SELECT setting_key, setting_value 
            FROM settings 
            WHERE setting_key IN ('postforme_api_key', 'postforme_project_type')
        ");
        $stmt->execute();
        
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (empty($settings['postforme_api_key'])) {
            throw new Exception('Post for Me API key not configured');
        }

        return [
            'api_key' => $settings['postforme_api_key'] ?? '',
            'project_type' => $settings['postforme_project_type'] ?? 'quickstart'
        ];
    }

    /**
     * Get FTP/Bunny CDN configuration from settings
     * 
     * @return array FTP and CDN configuration
     * @throws Exception if no FTP settings found
     */
    public static function getFtpBunnyConfig(): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("
            SELECT setting_key, setting_value 
            FROM settings 
            WHERE setting_key LIKE 'ftp_%' 
            OR setting_key LIKE 'bunny_%'
        ");
        $stmt->execute();
        
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Check for FTP settings first
        if (empty($settings['ftp_host']) || empty($settings['ftp_username'])) {
            // Check for Bunny CDN settings
            if (empty($settings['bunny_api_key']) || empty($settings['bunny_library_id'])) {
                throw new Exception('No FTP or Bunny CDN configuration found');
            }
            
            return [
                'type' => 'bunny',
                'api_key' => $settings['bunny_api_key'] ?? '',
                'library_id' => $settings['bunny_library_id'] ?? '',
                'storage_zone' => $settings['bunny_storage_zone'] ?? '',
                'storage_password' => $settings['bunny_storage_password'] ?? '',
                'cdn_hostname' => $settings['bunny_cdn_hostname'] ?? ''
            ];
        }

        return [
            'type' => 'ftp',
            'host' => $settings['ftp_host'] ?? '',
            'username' => $settings['ftp_username'] ?? '',
            'password' => $settings['ftp_password'] ?? '',
            'port' => $settings['ftp_port'] ?? 21,
            'path' => $settings['ftp_path'] ?? '/'
        ];
    }

    /**
     * Get all settings from database
     * 
     * @return array All settings as key-value pairs
     */
    public static function getAllSettings(): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->query("SELECT setting_key, setting_value FROM settings");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Get specific setting value
     * 
     * @param string $key Setting key
     * @param mixed $default Default value if not found
     * @return mixed Setting value or default
     */
    public static function getSetting(string $key, $default = null) {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        
        return $result !== false ? $result : $default;
    }

    /**
     * Set or update setting
     * 
     * @param string $key Setting key
     * @param string $value Setting value
     * @return bool True on success
     */
    public static function setSetting(string $key, string $value): bool {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("
            INSERT INTO settings (setting_key, setting_value) 
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        
        return $stmt->execute([$key, $value]);
    }

    /**
     * Get active API keys (Bunny CDN connections)
     * 
     * @return array List of active API keys with connection details
     */
    public static function getActiveApiKeys(): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("
            SELECT * FROM api_keys 
            WHERE status = 'active'
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Get API key by ID
     * 
     * @param int $id API key ID
     * @return array API key details
     * @throws Exception if not found
     */
    public static function getApiKeyById(int $id): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("SELECT * FROM api_keys WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        
        if (!$result) {
            throw new Exception("API key not found: {$id}");
        }

        return $result;
    }

    /**
     * Get automation settings by ID
     * 
     * @param int $id Automation ID
     * @return array Automation settings
     * @throws Exception if not found
     */
    public static function getAutomationSettings(int $id): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("
            SELECT a.*, k.name as key_name, k.api_key, k.library_id, k.storage_zone, k.ftp_host, k.ftp_username, k.ftp_password 
            FROM automation_settings a 
            LEFT JOIN api_keys k ON a.api_key_id = k.id 
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        
        if (!$result) {
            throw new Exception("Automation settings not found: {$id}");
        }

        return $result;
    }

    /**
     * Get all active automations
     * 
     * @return array List of active automations
     */
    public static function getActiveAutomations(): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("
            SELECT a.*, k.name as key_name, k.api_key, k.library_id, k.storage_zone, k.ftp_host, k.ftp_username, k.ftp_password 
            FROM automation_settings a 
            LEFT JOIN api_keys k ON a.api_key_id = k.id 
            WHERE a.enabled = 1 AND a.status IN ('inactive', 'running', 'queued', 'paused')
            ORDER BY a.created_at DESC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Get automation logs
     * 
     * @param int $automationId Automation ID
     * @param int $limit Number of logs to retrieve
     * @return array List of logs
     */
    public static function getAutomationLogs(int $automationId, int $limit = 50): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("
            SELECT * FROM automation_logs 
            WHERE automation_id = ?
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$automationId, $limit]);
        
        return $stmt->fetchAll();
    }

    /**
     * Log automation action
     * 
     * @param int $automationId Automation ID
     * @param string $action Action name
     * @param string $status Status (success/error/info)
     * @param string $message Log message
     * @param string|null $videoId Video ID (optional)
     * @param string|null $platform Platform (optional)
     * @return bool True on success
     */
    public static function logAutomationAction(int $automationId, string $action, string $status, string $message, ?string $videoId = null, ?string $platform = null): bool {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("
            INSERT INTO automation_logs (automation_id, action, status, message, video_id, platform)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([$automationId, $action, $status, $message, $videoId, $platform]);
    }

    /**
     * Get Facebook scheduled posts
     * 
     * @param string $status Filter by status (optional)
     * @param int $limit Maximum number of posts
     * @return array List of scheduled posts
     */
    public static function getFacebookScheduledPosts(?string $status = null, int $limit = 100): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $sql = "SELECT * FROM facebook_scheduled_posts WHERE 1=1";
        $params = [];
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY scheduled_at ASC LIMIT ?";
        $params[] = $limit;
        
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    /**
     * Get scheduled posts due for publishing
     * 
     * @param int $lookaheadMinutes Lookahead time in minutes
     * @return array List of posts ready for publishing
     */
    public static function getDueScheduledPosts(int $lookaheadMinutes = 10): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $cutoff = date('Y-m-d H:i:s', time() + ($lookaheadMinutes * 60));
        
        $stmt = self::$pdo->prepare("
            SELECT * FROM facebook_scheduled_posts 
            WHERE status = 'scheduled' AND scheduled_at <= ?
            ORDER BY scheduled_at ASC
            LIMIT 50
        ");
        $stmt->execute([$cutoff]);
        
        return $stmt->fetchAll();
    }

    /**
     * Get Post for Me posts
     * 
     * @param string $status Filter by status (optional)
     * @param int $limit Maximum number of posts
     * @return array List of Post for Me posts
     */
    public static function getPostformePosts(?string $status = null, int $limit = 100): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $sql = "SELECT * FROM postforme_posts WHERE 1=1";
        $params = [];
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    /**
     * Get local agents
     * 
     * @return array List of local agents
     */
    public static function getLocalAgents(): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->query("SELECT * FROM local_agents ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    /**
     * Get local agent jobs
     * 
     * @param int $agentId Agent ID (optional)
     * @param string $status Filter by status (optional)
     * @param int $limit Maximum number of jobs
     * @return array List of local agent jobs
     */
    public static function getLocalAgentJobs(?int $agentId = null, ?string $status = null, int $limit = 50): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $sql = "SELECT j.*, a.display_name as agent_name, s.name as automation_name 
                FROM local_agent_jobs j 
                LEFT JOIN local_agents a ON j.agent_id = a.id 
                LEFT JOIN automation_settings s ON j.automation_id = s.id 
                WHERE 1=1";
        $params = [];
        
        if ($agentId) {
            $sql .= " AND j.agent_id = ?";
            $params[] = $agentId;
        }
        
        if ($status) {
            $sql .= " AND j.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY j.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    /**
     * Get processed videos
     * 
     * @param int $automationId Automation ID (optional)
     * @param int $limit Maximum number of videos
     * @return array List of processed videos
     */
    public static function getProcessedVideos(?int $automationId = null, int $limit = 100): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $sql = "SELECT v.*, s.name as automation_name 
                FROM processed_videos v 
                LEFT JOIN automation_settings s ON v.automation_id = s.id 
                WHERE 1=1";
        $params = [];
        
        if ($automationId) {
            $sql .= " AND v.automation_id = ?";
            $params[] = $automationId;
        }
        
        $sql .= " ORDER BY v.processed_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    /**
     * Get prankwish universal taglines
     * 
     * @param int $cycleNumber Cycle number (optional)
     * @param bool $activeOnly Only active taglines
     * @return array List of taglines
     */
    public static function getPrankwishTaglines(?int $cycleNumber = null, bool $activeOnly = true): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $sql = "SELECT * FROM prankwish_universal_taglines WHERE 1=1";
        $params = [];
        
        if ($cycleNumber) {
            $sql .= " AND cycle_number = ?";
            $params[] = $cycleNumber;
        }
        
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        
        $sql .= " ORDER BY cycle_number ASC";
        
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    /**
     * Get prankwish settings
     * 
     * @return array Prankwish settings
     */
    public static function getPrankwishSettings(): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("
            SELECT setting_key, setting_value 
            FROM prankwish_settings 
            ORDER BY setting_key
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Get social content for prankwish
     * 
     * @param string $occasionKey Occasion key
     * @param string $platform Platform
     * @return array Social content
     * @throws Exception if not found
     */
    public static function getPrankwishSocialContent(string $occasionKey, string $platform): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("
            SELECT * FROM prankwish_social_content 
            WHERE occasion_key = ? AND platform = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$occasionKey, $platform]);
        $result = $stmt->fetch();
        
        if (!$result) {
            throw new Exception("Social content not found for occasion: {$occasionKey}, platform: {$platform}");
        }

        return $result;
    }

    /**
     * Get postforme accounts
     * 
     * @param string|null $platform Filter by platform
     * @return array List of postforme accounts
     */
    public static function getPostformeAccounts(?string $platform = null): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $sql = "SELECT * FROM postforme_accounts WHERE is_active = 1";
        $params = [];
        
        if ($platform) {
            $sql .= " AND platform = ?";
            $params[] = $platform;
        }
        
        $sql .= " ORDER BY platform, account_name";
        
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    /**
     * Execute query with proper error handling
     * 
     * @param string $sql SQL query
     * @param array $params Parameters
     * @return PDOStatement|
     * @throws Exception if query fails
     */
    public static function query(string $sql, array $params = []) {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        try {
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new Exception("Database query failed: " . $e->getMessage() . "\nSQL: " . $sql);
        }
    }

    /**
     * Execute transaction with proper error handling
     * 
     * @param callable $callback Callback function that performs operations
     * @return mixed Result of callback function
     * @throws Exception if transaction fails
     */
    public static function transaction(callable $callback) {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        try {
            self::$pdo->beginTransaction();
            $result = $callback();
            self::$pdo->commit();
            return $result;
        } catch (Exception $e) {
            self::$pdo->rollBack();
            throw new Exception("Transaction failed: " . $e->getMessage());
        }
    }

    /**
     * Get last inserted ID
     * 
     * @return string Last insert ID
     */
    public static function lastInsertId(): string {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }
        return self::$pdo->lastInsertId();
    }

    /**
     * Check if table exists
     * 
     * @param string $tableName Table name
     * @return bool True if table exists
     */
    public static function tableExists(string $tableName): bool {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get table columns
     * 
     * @param string $tableName Table name
     * @return array List of columns
     */
    public static function getTableColumns(string $tableName): array {
        if (!self::$initialized) {
            throw new Exception('DatabaseHelper not initialized');
        }

        $stmt = self::$pdo->prepare("SHOW COLUMNS FROM ?");
        $stmt->execute([$tableName]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

/**
 * DatabaseHelperException
 * Custom exception for DatabaseHelper errors
 */
class DatabaseHelperException extends Exception {
    public function __construct($message, $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}