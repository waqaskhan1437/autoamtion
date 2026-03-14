# DatabaseHelper Migration Guide

This guide shows how to replace existing database calls with the DatabaseHelper class. Follow these steps to migrate your codebase systematically.

## Phase 1: Settings and Configuration Migration

### Replace settings.php calls

**Before:**
```php
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
$stmt->execute([$key]);
$result = $stmt->fetchColumn();
```

**After:**
```php
$result = DatabaseHelper::getSetting($key);
```

**Before:**
```php
$stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
$stmt->execute([$key, $value]);
```

**After:**
```php
DatabaseHelper::setSetting($key, $value);
```

**Before:**
```php
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
```

**After:**
```php
$settings = DatabaseHelper::getAllSettings();
```

### Replace PostForMeAPI.php calls

**Before:**
```php
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('postforme_api_key', 'postforme_project_type')");
$stmt->execute();
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$apiKey = $settings['postforme_api_key'] ?? '';
```

**After:**
```php
try {
    $settings = DatabaseHelper::getPostForMeSettings();
    $apiKey = $settings['api_key'];
} catch (Exception $e) {
    // Handle error
}
```

## Phase 2: API Keys Migration

### Replace api-keys.php calls

**Before:**
```php
$stmt = $pdo->query("SELECT * FROM api_keys ORDER BY created_at DESC");
$keys = $stmt->fetchAll();
```

**After:**
```php
$keys = DatabaseHelper::getActiveApiKeys();
```

**Before:**
```php
$stmt = $pdo->prepare("SELECT * FROM api_keys WHERE id = ?");
$stmt->execute([$id]);
$key = $stmt->fetch();
```

**After:**
```php
try {
    $key = DatabaseHelper::getApiKeyById($id);
} catch (Exception $e) {
    // Handle error
}
```

## Phase 3: Automation Migration

### Replace automation.php calls

**Before:**
```php
$stmt = $pdo->prepare("SELECT a.*, k.name as key_name FROM automation_settings a LEFT JOIN api_keys k ON a.api_key_id = k.id ORDER BY a.created_at DESC");
$stmt->execute();
$automations = $stmt->fetchAll();
```

**After:**
```php
$automations = DatabaseHelper::getActiveAutomations();
```

**Before:**
```php
$stmt = $pdo->prepare("SELECT a.*, k.name as key_name, k.api_key, k.library_id, k.storage_zone, k.ftp_host, k.ftp_username, k.ftp_password 
                       FROM automation_settings a LEFT JOIN api_keys k ON a.api_key_id = k.id 
                       WHERE a.id = ?");
$stmt->execute([$id]);
$automation = $stmt->fetch();
```

**After:**
```php
try {
    $automation = DatabaseHelper::getAutomationSettings($id);
} catch (Exception $e) {
    // Handle error
}
```

### Replace run-automation-ajax.php calls

**Before:**
```php
$stmt = $pdo->prepare("SELECT a.*, k.api_key, k.library_id, k.storage_zone FROM automation_settings a LEFT JOIN api_keys k ON a.api_key_id = k.id WHERE a.id = ?");
$stmt->execute([$id]);
$automation = $stmt->fetch();
```

**After:**
```php
try {
    $automation = DatabaseHelper::getAutomationSettings($id);
} catch (Exception $e) {
    // Handle error
}
```

## Phase 4: Content Management Migration

### Replace run-sync.php calls

**Before:**
```php
$stmt = $pdo->prepare("SELECT v.*, s.name as automation_name FROM processed_videos v LEFT JOIN automation_settings s ON v.automation_id = s.id ORDER BY v.processed_at DESC LIMIT ?");
$stmt->execute([$limit]);
$videos = $stmt->fetchAll();
```

**After:**
```php
$videos = DatabaseHelper::getProcessedVideos(null, $limit);
```

### Replace scheduled-posts.php calls

**Before:**
```php
$stmt = $pdo->prepare("SELECT * FROM facebook_scheduled_posts WHERE status = ? ORDER BY scheduled_at ASC LIMIT ?");
$stmt->execute([$status, $limit]);
$posts = $stmt->fetchAll();
```

**After:**
```php
$posts = DatabaseHelper::getFacebookScheduledPosts($status, $limit);
```

**Before:**
```php
$cutoff = date('Y-m-d H:i:s', time() + ($lookaheadMinutes * 60));
$stmt = $pdo->prepare("SELECT * FROM facebook_scheduled_posts WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at ASC LIMIT ?");
$stmt->execute([$cutoff, $limit]);
$duePosts = $stmt->fetchAll();
```

**After:**
```php
$duePosts = DatabaseHelper::getDueScheduledPosts($lookaheadMinutes);
```

## Phase 5: Logging Migration

### Replace automation logging

**Before:**
```php
$stmt = $pdo->prepare("INSERT INTO automation_logs (automation_id, action, status, message, video_id, platform) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([$automationId, $action, $status, $message, $videoId, $platform]);
```

**After:**
```php
DatabaseHelper::logAutomationAction($automationId, $action, $status, $message, $videoId, $platform);
```

## Phase 6: Error Handling Migration

### Replace error handling

**Before:**
```php
if ($stmt->execute([$id]) === false) {
    // Handle error
}
```

**After:**
```php
try {
    $result = DatabaseHelper::getApiKeyById($id);
} catch (Exception $e) {
    // Handle error with meaningful message
    echo "Error retrieving API key: " . $e->message;
}
```

### Replace transaction handling

**Before:**
```php
try {
    $pdo->beginTransaction();
    // Multiple operations
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    // Handle error
}
```

**After:**
```php
try {
    DatabaseHelper::transaction(function() {
        // Multiple operations
    });
} catch (Exception $e) {
    // Handle error
}
```

## Phase 7: Utility Methods

### Replace table existence checks

**Before:**
```php
$stmt = $pdo->prepare("SHOW TABLES LIKE ?");
$stmt->execute([$tableName]);
$tableExists = $stmt->rowCount() > 0;
```

**After:**
```php
$tableExists = DatabaseHelper::tableExists($tableName);
```

### Replace column checks

**Before:**
```php
$stmt = $pdo->prepare("SHOW COLUMNS FROM ?");
$stmt->execute([$tableName]);
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
```

**After:**
```php
$columns = DatabaseHelper::getTableColumns($tableName);
```

## Complete Migration Example

Here's a complete example of migrating a file from direct PDO to DatabaseHelper:

### Before (api-keys.php):
```php
<?php
require_once 'config.php';

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        try {
            $stmt = $pdo->prepare("INSERT INTO api_keys (name, api_key, library_id, storage_zone, ftp_host, ftp_username, ftp_password, ftp_port, cdn_hostname, pull_zone_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$name, $apiKey, $libraryId, $storageZone, $ftpHost, $ftpUsername, $ftpPassword, $ftpPort, $cdnHostname, $pullZoneId]);
            $message = 'API key created successfully';
        } catch (PDOException $e) {
            $message = 'Error creating API key';
            $messageType = 'error';
        }
    }
    // ... more actions
}

$stmt = $pdo->query("SELECT * FROM api_keys ORDER BY created_at DESC");
$keys = $stmt->fetchAll();

// ... rest of the file
```

### After:
```php
<?php
require_once 'config.php';
require_once 'includes/DatabaseHelper.php';

DatabaseHelper::initialize($pdo);

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        try {
            DatabaseHelper::query("
                INSERT INTO api_keys (name, api_key, library_id, storage_zone, ftp_host, ftp_username, ftp_password, ftp_port, cdn_hostname, pull_zone_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ", [$name, $apiKey, $libraryId, $storageZone, $ftpHost, $ftpUsername, $ftpPassword, $ftpPort, $cdnHostname, $pullZoneId]);
            $message = 'API key created successfully';
        } catch (Exception $e) {
            $message = 'Error creating API key: ' . $e->message;
            $messageType = 'error';
        }
    }
    // ... more actions
}

$keys = DatabaseHelper::getActiveApiKeys();

// ... rest of the file
```

## Testing After Migration

After migrating all files, test the application thoroughly:

1. **Unit Tests**: Test each DatabaseHelper method independently
2. **Integration Tests**: Test complete workflows that use database operations
3. **Error Scenarios**: Test error handling and exception cases
4. **Performance**: Test database performance and query optimization
5. **Security**: Verify SQL injection prevention and data validation

## Benefits After Migration

1. **Reduced Code Duplication**: Eliminated repetitive database code
2. **Better Error Handling**: Consistent, meaningful error messages
3. **Improved Security**: All queries use prepared statements
4. **Easier Maintenance**: Single class to maintain for all database operations
5. **Better Performance**: Optimized queries and connection management
6. **Enhanced Debugging**: Centralized logging and error tracking
7. **Future-Proof**: Easy to add new methods and features

The DatabaseHelper class provides a solid foundation for all database operations in the Video Workflow Manager, making the codebase more maintainable, secure, and efficient.