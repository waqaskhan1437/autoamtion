<?php
require_once 'config.php';

try {
    // Create dailymotion_accounts table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dailymotion_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id VARCHAR(255) NOT NULL UNIQUE,
            username VARCHAR(255) NOT NULL,
            email VARCHAR(255),
            access_token TEXT,
            refresh_token TEXT,
            token_expires_at DATETIME,
            is_active BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ dailymotion_accounts table created successfully!\n";
    
    // Check if dailymotion columns exist in automation_settings
    $stmt = $pdo->query("DESCRIBE automation_settings");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('dailymotion_enabled', $columns)) {
        $pdo->exec("ALTER TABLE automation_settings ADD COLUMN dailymotion_enabled TINYINT(1) DEFAULT 0");
        echo "✅ Added dailymotion_enabled column!\n";
    }
    
    if (!in_array('dailymotion_account_id', $columns)) {
        $pdo->exec("ALTER TABLE automation_settings ADD COLUMN dailymotion_account_id VARCHAR(255) DEFAULT NULL");
        echo "✅ Added dailymotion_account_id column!\n";
    }
    
    echo "\n✅ DailyMotion integration setup complete!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
