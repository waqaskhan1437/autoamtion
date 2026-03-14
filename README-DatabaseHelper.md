# DatabaseHelper Class Documentation

## Overview

The DatabaseHelper class provides a centralized, unified interface for all database operations in the Video Workflow Manager. It consolidates PostForMe API key retrieval, FTP/Bunny CDN configuration access, settings management, and common query patterns into a single, maintainable class.

## Features

- **Unified Interface**: Single class for all database operations
- **Error Handling**: Comprehensive exception handling with meaningful error messages
- **Connection Management**: Automatic PDO connection handling
- **Prepared Statements**: Secure query execution with parameter binding
- **Transaction Support**: Easy transaction management for complex operations
- **Static Methods**: No need to instantiate - use static methods directly
- **Backward Compatibility**: Maintains existing functionality without breaking changes
- **Type Safety**: Proper PHPDoc comments for IDE support and type checking

## Installation

1. Include the DatabaseHelper class in your project:
   ```php
   require_once 'includes/DatabaseHelper.php';
   ```

2. Initialize with your existing PDO connection:
   ```php
   DatabaseHelper::initialize($pdo);
   ```

## Usage Examples

### Basic Usage

```php
require_once 'config.php';
require_once 'includes/DatabaseHelper.php';

// Initialize with existing PDO connection
DatabaseHelper::initialize($pdo);

// Get PostForMe API settings
try {
    $postformeSettings = DatabaseHelper::getPostForMeSettings();
    echo "API Key: " . $postformeSettings['api_key'] . "\n";
} catch (Exception $e) {
    echo "PostForMe not configured: " . $e->message . "\n";
}

// Get FTP/Bunny CDN configuration
try {
    $ftpConfig = DatabaseHelper::getFtpBunnyConfig();
    // Use configuration for FTP or Bunny CDN operations
} catch (Exception $e) {
    echo "FTP/Bunny not configured: " . $e->message . "\n";
}

// Get all settings
$allSettings = DatabaseHelper::getAllSettings();

// Get specific setting
$ffmpegPath = DatabaseHelper::getSetting('ffmpeg_path', 'ffmpeg');

// Set setting
DatabaseHelper::setSetting('test_setting', 'test_value');
```

### Advanced Usage

```php
// Get active API keys
$activeKeys = DatabaseHelper::getActiveApiKeys();

// Get automation settings by ID
try {
    $automation = DatabaseHelper::getAutomationSettings(1);
} catch (Exception $e) {
    // Handle error
}

// Get active automations
$activeAutomations = DatabaseHelper::getActiveAutomations();

// Log automation action
DatabaseHelper::logAutomationAction(
    1,
    'video_processing',
    'success',
    'Video processed successfully',
    'video123',
    'youtube'
);

// Get Facebook scheduled posts
$scheduledPosts = DatabaseHelper::getFacebookScheduledPosts();

// Get due scheduled posts (next 10 minutes)
$duePosts = DatabaseHelper::getDueScheduledPosts(10);

// Get Post for Me posts
$postformePosts = DatabaseHelper::getPostformePosts();

// Get local agents
$localAgents = DatabaseHelper::getLocalAgents();

// Get local agent jobs
$agentJobs = DatabaseHelper::getLocalAgentJobs();

// Get processed videos
$processedVideos = DatabaseHelper::getProcessedVideos();

// Get prankwish taglines
$taglines = DatabaseHelper::getPrankwishTaglines();

// Get prankwish settings
$prankwishSettings = DatabaseHelper::getPrankwishSettings();

// Get postforme accounts
$accounts = DatabaseHelper::getPostformeAccounts();

// Using transactions
DatabaseHelper::transaction(function() {
    DatabaseHelper::setSetting('transaction_test', 'value1');
    DatabaseHelper::logAutomationAction(1, 'test', 'info', 'Transaction test');
});

// Custom query with error handling
$stmt = DatabaseHelper::query("SELECT * FROM settings WHERE setting_key = ?", ['ffmpeg_path']);
$result = $stmt->fetch();
```

## Method Reference

### Initialization

```php
/**
 * Initialize the database helper with PDO connection
 * 
 * @param PDO $pdo PDO connection instance
 * @return void
 */
public static function initialize(PDO $pdo): void
```

### Configuration Methods

```php
/**
 * Get PostForMe API key and project type
 * 
 * @return array{api_key: string, project_type: string}
 * @throws Exception if not configured
 */
public static function getPostForMeSettings(): array

/**
 * Get FTP/Bunny CDN configuration
 * 
 * @return array Configuration with type, credentials, and settings
 * @throws Exception if no configuration found
 */
public static function getFtpBunnyConfig(): array

/**
 * Get all settings as key-value pairs
 * 
 * @return array All settings
 */
public static function getAllSettings(): array

/**
 * Get specific setting value
 * 
 * @param string $key Setting key
 * @param mixed $default Default value if not found
 * @return mixed Setting value or default
 */
public static function getSetting(string $key, $default = null)

/**
 * Set or update setting
 * 
 * @param string $key Setting key
 * @param string $value Setting value
 * @return bool True on success
 */
public static function setSetting(string $key, string $value): bool
```

### API Keys Methods

```php
/**
 * Get active API keys (Bunny CDN connections)
 * 
 * @return array List of active API keys with connection details
 */
public static function getActiveApiKeys(): array

/**
 * Get API key by ID
 * 
 * @param int $id API key ID
 * @return array API key details
 * @throws Exception if not found
 */
public static function getApiKeyById(int $id): array
```

### Automation Methods

```php
/**
 * Get automation settings by ID
 * 
 * @param int $id Automation ID
 * @return array Automation settings with API key info
 * @throws Exception if not found
 */
public static function getAutomationSettings(int $id): array

/**
 * Get all active automations
 * 
 * @return array List of active automations with API key info
 */
public static function getActiveAutomations(): array

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
public static function logAutomationAction(int $automationId, string $action, string $status, string $message, ?string $videoId = null, ?string $platform = null): bool

/**
 * Get automation logs
 * 
 * @param int $automationId Automation ID
 * @param int $limit Number of logs to retrieve
 * @return array List of logs
 */
public static function getAutomationLogs(int $automationId, int $limit = 50): array
```

### Scheduling Methods

```php
/**
 * Get Facebook scheduled posts
 * 
 * @param string|null $status Filter by status
 * @param int $limit Maximum number of posts
 * @return array List of scheduled posts
 */
public static function getFacebookScheduledPosts(?string $status = null, int $limit = 100): array

/**
 * Get scheduled posts due for publishing
 * 
 * @param int $lookaheadMinutes Lookahead time in minutes
 * @return array List of posts ready for publishing
 */
public static function getDueScheduledPosts(int $lookaheadMinutes = 10): array

/**
 * Get Post for Me posts
 * 
 * @param string|null $status Filter by status
 * @param int $limit Maximum number of posts
 * @return array List of Post for Me posts
 */
public static function getPostformePosts(?string $status = null, int $limit = 100): array
```

### Content Management Methods

```php
/**
 * Get local agents
 * 
 * @return array List of local agents
 */
public static function getLocalAgents(): array

/**
 * Get local agent jobs
 * 
 * @param int|null $agentId Agent ID filter
 * @param string|null $status Status filter
 * @param int $limit Maximum number of jobs
 * @return array List of local agent jobs
 */
public static function getLocalAgentJobs(?int $agentId = null, ?string $status = null, int $limit = 50): array

/**
 * Get processed videos
 * 
 * @param int|null $automationId Automation ID filter
 * @param int $limit Maximum number of videos
 * @return array List of processed videos
 */
public static function getProcessedVideos(?int $automationId = null, int $limit = 100): array

/**
 * Get prankwish universal taglines
 * 
 * @param int|null $cycleNumber Cycle number filter
 * @param bool $activeOnly Only active taglines
 * @return array List of taglines
 */
public static function getPrankwishTaglines(?int $cycleNumber = null, bool $activeOnly = true): array

/**
 * Get prankwish settings
 * 
 * @return array Prankwish settings as key-value pairs
 */
public static function getPrankwishSettings(): array

/**
 * Get prankwish social content
 * 
 * @param string $occasionKey Occasion key
 * @param string $platform Platform
 * @return array Social content
 * @throws Exception if not found
 */
public static function getPrankwishSocialContent(string $occasionKey, string $platform): array

/**
 * Get postforme accounts
 * 
 * @param string|null $platform Platform filter
 * @return array List of postforme accounts
 */
public static function getPostformeAccounts(?string $platform = null): array
```

### Utility Methods

```php
/**
 * Execute query with proper error handling
 * 
 * @param string $sql SQL query
 * @param array $params Parameters
 * @return PDOStatement|
 * @throws Exception if query fails
 */
public static function query(string $sql, array $params = [])

/**
 * Execute transaction with proper error handling
 * 
 * @param callable $callback Callback function that performs operations
 * @return mixed Result of callback function
 * @throws Exception if transaction fails
 */
public static function transaction(callable $callback)

/**
 * Get last inserted ID
 * 
 * @return string Last insert ID
 */
public static function lastInsertId(): string

/**
 * Check if table exists
 * 
 * @param string $tableName Table name
 * @return bool True if table exists
 */
public static function tableExists(string $tableName): bool

/**
 * Get table columns
 * 
 * @param string $tableName Table name
 * @return array List of columns
 */
public static function getTableColumns(string $tableName): array
```

### Error Handling

The DatabaseHelper class throws exceptions for error conditions:

```php
// Custom exception for DatabaseHelper errors
class DatabaseHelperException extends Exception {
    public function __construct($message, $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

// Example error handling
try {
    $settings = DatabaseHelper::getPostForMeSettings();
} catch (DatabaseHelperException $e) {
    // Handle specific database helper errors
    echo "Database error: " . $e->message;
} catch (Exception $e) {
    // Handle other errors
    echo "General error: " . $e->message;
}
```

## Replacing Existing Code

Here are examples of how to replace existing database calls:

### Before (api-keys.php):
```php
$stmt = $pdo->prepare("SELECT * FROM api_keys WHERE id = ?");
$stmt->execute([$id]);
$result = $stmt->fetch();
```

### After:
```php
$result = DatabaseHelper::getApiKeyById($id);
```

### Before (settings.php):
```php
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
$stmt->execute([$key]);
$result = $stmt->fetchColumn();
```

### After:
```php
$result = DatabaseHelper::getSetting($key);
```

### Before (automation.php):
```php
$stmt = $pdo->prepare("SELECT a.*, k.name as key_name FROM automation_settings a LEFT JOIN api_keys k ON a.api_key_id = k.id ORDER BY a.created_at DESC");
$stmt->execute();
$results = $stmt->fetchAll();
```

### After:
```php
$results = DatabaseHelper::getActiveAutomations();
```

## Benefits

1. **Centralized Error Handling**: All database errors are caught and converted to meaningful exceptions
2. **Consistent Query Patterns**: All queries use prepared statements with parameter binding
3. **Type Safety**: PHPDoc comments provide IDE support and type checking
4. **Maintainability**: Single class to maintain for all database operations
5. **Backward Compatibility**: Maintains existing functionality without breaking changes
6. **Security**: Prevents SQL injection through prepared statements
7. **Transaction Support**: Easy transaction management for complex operations
8. **Code Reduction**: Eliminates repetitive database code throughout the codebase

## Performance Considerations

- The class uses prepared statements which are cached by PDO
- Connection is established once and reused throughout the application
- Queries are optimized for common use cases
- Transaction support reduces database round trips for complex operations

## Testing

```php
// Test DatabaseHelper functionality
require_once 'includes/DatabaseHelper.php';

// Initialize with test PDO connection
$pdo = new PDO("mysql:host=localhost;port=3306;dbname=test;charset=utf8mb4", 'root', '');
DatabaseHelper::initialize($pdo);

try {
    // Test basic functionality
    $settings = DatabaseHelper::getAllSettings();
    echo "Test passed: Retrieved " . count($settings) . " settings\n";
    
    // Test transaction
    DatabaseHelper::transaction(function() {
        DatabaseHelper::setSetting('test_key', 'test_value');
    });
    echo "Test passed: Transaction completed successfully\n";
    
    echo "All tests passed!\n";
    
} catch (Exception $e) {
    echo "Test failed: " . $e->message . "\n";
}
```

## Migration Strategy

1. **Phase 1**: Add DatabaseHelper class to project
2. **Phase 2**: Replace simple queries (settings, API keys)
3. **Phase 3**: Replace automation-related queries
4. **Phase 4**: Replace content management queries
5. **Phase 5**: Update error handling throughout the codebase
6. **Phase 6**: Add transaction support where needed

This phased approach ensures minimal disruption while improving code quality and maintainability.