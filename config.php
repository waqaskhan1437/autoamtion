<?php
// Set default timezone to Asia/Karachi
date_default_timezone_set('Asia/Karachi');

/**
 * Video Workflow Manager Configuration
 * Edit these settings for your XAMPP environment
 */

// ============================================
// DATABASE SETTINGS
// ============================================
$host = getenv('VW_DB_HOST') ?: 'localhost';
$dbPortFromEnv = getenv('VW_DB_PORT');
$dbPort = ($dbPortFromEnv === false || trim($dbPortFromEnv) === '') ? '3306' : trim($dbPortFromEnv);
$dbname = getenv('VW_DB_NAME') ?: 'video_workflow';
$username = getenv('VW_DB_USER') ?: 'root';
$passwordFromEnv = getenv('VW_DB_PASS');
$password = ($passwordFromEnv === false) ? '' : $passwordFromEnv;

// ============================================
// OPENAI API KEY (for Whisper transcription)
// ============================================
// Get your API key from: https://platform.openai.com/api-keys
$openAiApiKeyFromEnv = getenv('VW_OPENAI_API_KEY');
define('OPENAI_API_KEY', $openAiApiKeyFromEnv !== false ? $openAiApiKeyFromEnv : '');

// ============================================
// FFMPEG SETTINGS
// ============================================
// Path to FFmpeg executable (leave as 'ffmpeg' if in system PATH)
$ffmpegPathFromEnv = getenv('VW_FFMPEG_PATH');
$ffprobePathFromEnv = getenv('VW_FFPROBE_PATH');
define('FFMPEG_PATH', $ffmpegPathFromEnv !== false && trim($ffmpegPathFromEnv) !== '' ? $ffmpegPathFromEnv : 'ffmpeg');
define('FFPROBE_PATH', $ffprobePathFromEnv !== false && trim($ffprobePathFromEnv) !== '' ? $ffprobePathFromEnv : 'ffprobe');

// ============================================
// WEB ACCESS GATE (LIVE ONLY)
// ============================================
// Change this password before production use.
// On local hosts (localhost / 127.0.0.1 / private LAN IPs), auth is bypassed.
$appAccessPasswordFromEnv = getenv('VW_APP_ACCESS_PASSWORD');
define(
    'APP_ACCESS_PASSWORD',
    $appAccessPasswordFromEnv !== false && trim($appAccessPasswordFromEnv) !== ''
        ? $appAccessPasswordFromEnv
        : 'ChangeMe@123'
);
define('DEFAULT_ADMIN_EMAIL', getenv('VW_DEFAULT_ADMIN_EMAIL') ?: 'admin@local');
$defaultAdminPasswordFromEnv = getenv('VW_DEFAULT_ADMIN_PASSWORD');
define(
    'DEFAULT_ADMIN_PASSWORD',
    $defaultAdminPasswordFromEnv !== false && trim($defaultAdminPasswordFromEnv) !== ''
        ? $defaultAdminPasswordFromEnv
        : APP_ACCESS_PASSWORD
);

// ============================================
// FILE PATHS (Outside Code Directory)
// ============================================
// Files will be stored in C:\VideoWorkflow\ on Windows
// This keeps code clean and separates data from application

// Detect OS and set base path
$basePathFromEnv = getenv('VW_BASE_DATA_DIR');
if ($basePathFromEnv !== false && trim($basePathFromEnv) !== '') {
    $basePath = $basePathFromEnv;
} elseif (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows: Use C:\VideoWorkflow\
    $basePath = 'C:\\VideoWorkflow';
} else {
    // Linux/Mac: Use home directory
    $basePath = getenv('HOME') . '/VideoWorkflow';
}

define('BASE_DATA_DIR', $basePath);
define('TEMP_DIR', $basePath . DIRECTORY_SEPARATOR . 'temp');           // Downloaded videos go here
define('OUTPUT_DIR', $basePath . DIRECTORY_SEPARATOR . 'output');       // Processed shorts go here
define('LOGS_DIR', $basePath . DIRECTORY_SEPARATOR . 'logs');           // Log files go here
define('SUBTITLES_DIR', $basePath . DIRECTORY_SEPARATOR . 'subtitles'); // Generated subtitles go here

// Create directories if they don't exist
foreach ([BASE_DATA_DIR, TEMP_DIR, OUTPUT_DIR, LOGS_DIR, SUBTITLES_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

// ============================================
// DATABASE CONNECTION (AUTO-INSTALL)
// ============================================
try {
    // First connect without database to check/create it
    $pdoInit = new PDO("mysql:host=$host;port=$dbPort;charset=utf8mb4", $username, $password);
    $pdoInit->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if not exists
    $pdoInit->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Now connect to the database
    $pdo = new PDO("mysql:host=$host;port=$dbPort;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Keep MySQL NOW()/TIMESTAMP comparisons aligned with PHP timezone
    // so scheduler conditions like next_run_at <= NOW() stay consistent.
    try {
        $tzOffset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
        $pdo->exec("SET time_zone = " . $pdo->quote($tzOffset));
    } catch (Exception $e) {
        // Continue with DB defaults if timezone set fails
    }
    
    // Auto-create tables if they don't exist
    $tablesExist = $pdo->query("SHOW TABLES LIKE 'api_keys'")->rowCount() > 0;
    
    if ($tablesExist) {
        // Add missing columns to api_keys table
        $apiKeyColumns = $pdo->query("SHOW COLUMNS FROM api_keys")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('ftp_host', $apiKeyColumns)) {
            $pdo->exec("ALTER TABLE api_keys ADD COLUMN ftp_host VARCHAR(255)");
        }
        if (!in_array('ftp_username', $apiKeyColumns)) {
            $pdo->exec("ALTER TABLE api_keys ADD COLUMN ftp_username VARCHAR(255)");
        }
        if (!in_array('ftp_password', $apiKeyColumns)) {
            $pdo->exec("ALTER TABLE api_keys ADD COLUMN ftp_password VARCHAR(255)");
        }
        if (!in_array('ftp_port', $apiKeyColumns)) {
            $pdo->exec("ALTER TABLE api_keys ADD COLUMN ftp_port INT DEFAULT 21");
        }
        if (!in_array('cdn_hostname', $apiKeyColumns)) {
            $pdo->exec("ALTER TABLE api_keys ADD COLUMN cdn_hostname VARCHAR(255)");
        }
        if (!in_array('pull_zone_id', $apiKeyColumns)) {
            $pdo->exec("ALTER TABLE api_keys ADD COLUMN pull_zone_id VARCHAR(255)");
        }

        // Ensure video_jobs has completed_at for compatibility with newer runners
        try {
            $videoJobColumns = $pdo->query("SHOW COLUMNS FROM video_jobs")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('completed_at', $videoJobColumns)) {
                $pdo->exec("ALTER TABLE video_jobs ADD COLUMN completed_at TIMESTAMP NULL");
            }
        } catch (Exception $e) {}
        
        // Add missing columns to automation_settings table
        $columns = $pdo->query("SHOW COLUMNS FROM automation_settings")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('video_source', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN video_source VARCHAR(20) DEFAULT 'ftp'");
        }
        if (!in_array('manual_video_links', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN manual_video_links LONGTEXT NULL");
        }
        if (!in_array('youtube_channel_url', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN youtube_channel_url VARCHAR(500) NULL");
        }
        if (!in_array('run_mode', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN run_mode ENUM('local', 'github_runner') DEFAULT 'local'");
        }
        // Custom Taglines columns
        if (!in_array('top_taglines_json', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN top_taglines_json TEXT");
        }
        if (!in_array('bottom_taglines_json', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN bottom_taglines_json TEXT");
        }
        if (!in_array('tagline_rotation_mode', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN tagline_rotation_mode ENUM('sequential', 'random') DEFAULT 'sequential'");
        }
        if (!in_array('current_tagline_index', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN current_tagline_index INT DEFAULT 0");
        }
        if (!in_array('last_top_index', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN last_top_index INT DEFAULT -1");
        }
        if (!in_array('last_bottom_index', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN last_bottom_index INT DEFAULT -1");
        }
        
        // Social Media Content columns (titles, descriptions, hashtags)
        if (!in_array('social_titles_json', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN social_titles_json TEXT");
        }
        if (!in_array('social_descriptions_json', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN social_descriptions_json TEXT");
        }
        if (!in_array('social_hashtags_json', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN social_hashtags_json TEXT");
        }
        if (!in_array('social_rotation_mode', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN social_rotation_mode ENUM('sequential', 'random') DEFAULT 'sequential'");
        }
        if (!in_array('current_social_index', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN current_social_index INT DEFAULT 0");
        }
        
        // Add progress tracking columns
        if (!in_array('progress_percent', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN progress_percent INT DEFAULT 0");
        }
        if (!in_array('progress_data', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN progress_data TEXT");
        }
        if (!in_array('last_progress_time', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN last_progress_time TIMESTAMP NULL");
        }
        
        // Update status ENUM to include all needed values
        try {
            $pdo->exec("ALTER TABLE automation_settings MODIFY COLUMN status ENUM('inactive', 'running', 'processing', 'completed', 'error', 'stopped', 'queued', 'paused') DEFAULT 'inactive'");
        } catch (Exception $e) {
            // Ignore if already correct
        }
        
        // Add Post for Me integration columns
        if (!in_array('postforme_enabled', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN postforme_enabled TINYINT(1) DEFAULT 0");
        }
        if (!in_array('postforme_account_ids', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN postforme_account_ids JSON");
        }
        
        // Add rotation columns
        if (!in_array('rotation_enabled', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN rotation_enabled TINYINT(1) DEFAULT 1");
        }
        if (!in_array('rotation_cycle', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN rotation_cycle INT DEFAULT 1");
        }
        if (!in_array('rotation_auto_reset', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN rotation_auto_reset TINYINT(1) DEFAULT 1");
        }
        if (!in_array('rotation_shuffle', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN rotation_shuffle TINYINT(1) DEFAULT 1");
        }
        
        // Add date filtering columns
        if (!in_array('video_start_date', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN video_start_date DATE NULL");
        }
        if (!in_array('video_end_date', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN video_end_date DATE NULL");
        }
        if (!in_array('videos_per_run', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN videos_per_run INT DEFAULT 5");
        }
        if (!in_array('playback_speed', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN playback_speed DECIMAL(3,1) DEFAULT 1.0");
        }
        if (!in_array('source_shorts_mode', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN source_shorts_mode ENUM('single', 'duration_based', 'fixed_count') DEFAULT 'single'");
        }
        if (!in_array('source_shorts_max_count', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN source_shorts_max_count INT DEFAULT 1");
        }
        if (!in_array('schedule_every_minutes', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN schedule_every_minutes INT DEFAULT 10");
        }
        if (!in_array('process_id', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN process_id VARCHAR(20) NULL");
        }
        if (!in_array('local_agent_id', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN local_agent_id INT NULL");
        }
        if (!in_array('owner_user_id', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN owner_user_id INT NULL");
        }

        // Ensure schedule_type supports minutes testing mode
        try {
            $pdo->exec("ALTER TABLE automation_settings MODIFY COLUMN schedule_type ENUM('minutes', 'hourly', 'daily', 'weekly') DEFAULT 'daily'");
        } catch (Exception $e) {
            // Ignore if already correct
        }

        // Ensure new source option exists for direct URL pipelines
        try {
            $pdo->exec("ALTER TABLE automation_settings MODIFY COLUMN video_source ENUM('ftp', 'bunny', 'manual_links', 'youtube_channel') DEFAULT 'ftp'");
        } catch (Exception $e) {
            // Ignore if enum already supports manual links
        }
        
        // Create processed_videos table if not exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS processed_videos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                automation_id INT NOT NULL,
                video_identifier VARCHAR(255) NOT NULL,
                video_filename VARCHAR(255),
                file_size BIGINT DEFAULT 0,
                content_hash VARCHAR(64),
                cycle_number INT DEFAULT 1,
                processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                posted_at TIMESTAMP NULL,
                FOREIGN KEY (automation_id) REFERENCES automation_settings(id) ON DELETE CASCADE,
                UNIQUE KEY unique_video_per_cycle (automation_id, video_identifier, cycle_number)
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS used_taglines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tagline_text VARCHAR(1000) NOT NULL,
                tagline_type ENUM('top', 'bottom') NOT NULL,
                automation_id INT,
                video_identifier VARCHAR(500),
                used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_tagline (tagline_text(500))
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS used_social_content (
                id INT AUTO_INCREMENT PRIMARY KEY,
                content_type ENUM('title', 'description', 'hashtag') NOT NULL,
                content_text VARCHAR(2000) NOT NULL,
                automation_id INT,
                video_identifier VARCHAR(500),
                used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_social_content (content_text(1000))
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS local_agents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agent_key VARCHAR(80) NOT NULL UNIQUE,
                agent_secret_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                machine_name VARCHAR(255) NULL,
                host_name VARCHAR(255) NULL,
                platform VARCHAR(80) NULL,
                agent_version VARCHAR(50) NULL,
                status ENUM('online', 'offline', 'disabled') DEFAULT 'offline',
                last_seen_at TIMESTAMP NULL,
                last_ip VARCHAR(64) NULL,
                capabilities_json LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS local_agent_jobs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                agent_id INT NOT NULL,
                automation_id INT NOT NULL,
                trigger_source VARCHAR(50) DEFAULT 'manual',
                status ENUM('queued', 'claimed', 'running', 'completed', 'error', 'cancelled') DEFAULT 'queued',
                claim_token VARCHAR(64) NULL,
                queued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                claimed_at TIMESTAMP NULL,
                started_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                last_heartbeat_at TIMESTAMP NULL,
                result_json LONGTEXT NULL,
                error_message TEXT NULL,
                FOREIGN KEY (agent_id) REFERENCES local_agents(id) ON DELETE CASCADE,
                FOREIGN KEY (automation_id) REFERENCES automation_settings(id) ON DELETE CASCADE
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(255) NULL,
                client_slug VARCHAR(120) NULL,
                role ENUM('admin', 'user') DEFAULT 'user',
                status ENUM('active', 'disabled') DEFAULT 'active',
                can_use_github_runner TINYINT(1) DEFAULT 0,
                assigned_local_agent_id INT NULL,
                last_login_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        try {
            $userColumns = $pdo->query("SHOW COLUMNS FROM app_users")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('client_slug', $userColumns, true)) {
                $pdo->exec("ALTER TABLE app_users ADD COLUMN client_slug VARCHAR(120) NULL");
            }
            $slugIndexExists = $pdo->query("SHOW INDEX FROM app_users WHERE Key_name = 'uniq_app_users_client_slug'")->rowCount() > 0;
            if (!$slugIndexExists) {
                $pdo->exec("CREATE UNIQUE INDEX uniq_app_users_client_slug ON app_users (client_slug)");
            }
        } catch (Exception $e) {
            // Continue even if slug migration fails.
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS magic_login_tokens (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL UNIQUE,
                redirect_path VARCHAR(255) NULL,
                expires_at DATETIME NOT NULL,
                one_time TINYINT(1) DEFAULT 1,
                used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by_user_id) REFERENCES app_users(id) ON DELETE SET NULL,
                INDEX idx_magic_user_active (user_id, expires_at),
                INDEX idx_magic_active_lookup (revoked_at, used_at, expires_at)
            )
        ");
        
        // Add Post for Me scheduling columns
        if (!in_array('postforme_schedule_mode', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN postforme_schedule_mode VARCHAR(20) DEFAULT 'immediate'");
        }
        if (!in_array('postforme_schedule_datetime', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN postforme_schedule_datetime DATETIME NULL");
        }
        if (!in_array('postforme_schedule_timezone', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN postforme_schedule_timezone VARCHAR(100) DEFAULT 'UTC'");
        }
        if (!in_array('postforme_schedule_offset_minutes', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN postforme_schedule_offset_minutes INT DEFAULT 0");
        }
        if (!in_array('postforme_schedule_spread_minutes', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN postforme_schedule_spread_minutes INT DEFAULT 0");
        }
        if (!in_array('dailymotion_enabled', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN dailymotion_enabled TINYINT(1) DEFAULT 0");
        }
        if (!in_array('dailymotion_account_id', $columns)) {
            $pdo->exec("ALTER TABLE automation_settings ADD COLUMN dailymotion_account_id VARCHAR(255) DEFAULT NULL");
        }
        
        // Create dailymotion_accounts table if not exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dailymotion_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id VARCHAR(255) NOT NULL UNIQUE,
                username VARCHAR(255) NOT NULL,
                email VARCHAR(255),
                access_token TEXT,
                refresh_token TEXT,
                token_expires_at DATETIME,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Create postforme_accounts table if not exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS postforme_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id VARCHAR(100) NOT NULL UNIQUE,
                platform VARCHAR(50) NOT NULL,
                account_name VARCHAR(255),
                username VARCHAR(255),
                profile_image_url TEXT,
                is_active TINYINT(1) DEFAULT 1,
                last_synced_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        
        // Create postforme_posts table if not exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS postforme_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                post_id VARCHAR(100) NOT NULL,
                automation_id INT,
                video_path TEXT,
                caption TEXT,
                account_ids TEXT,
                status VARCHAR(50) DEFAULT 'pending',
                scheduled_at DATETIME NULL,
                published_at DATETIME NULL,
                error_message TEXT,
                results JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS facebook_scheduled_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_key VARCHAR(64) NOT NULL UNIQUE,
                automation_id INT NULL,
                video_id VARCHAR(255) NULL,
                media_url TEXT NOT NULL,
                caption TEXT NULL,
                title VARCHAR(255) NULL,
                description TEXT NULL,
                account_ids TEXT NOT NULL,
                status VARCHAR(50) DEFAULT 'scheduled',
                scheduled_at DATETIME NOT NULL,
                published_at DATETIME NULL,
                error_message TEXT NULL,
                result_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_fb_sched_due (status, scheduled_at),
                INDEX idx_fb_sched_automation (automation_id)
            )
        ");


        
        // Add missing columns to postforme_posts for existing installs
        try {
            $ppColumns = $pdo->query("SHOW COLUMNS FROM postforme_posts")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('video_id', $ppColumns)) {
                $pdo->exec("ALTER TABLE postforme_posts ADD COLUMN video_id VARCHAR(255) NULL");
            }
            if (!in_array('video_path', $ppColumns)) {
                $pdo->exec("ALTER TABLE postforme_posts ADD COLUMN video_path TEXT NULL");
            }
            if (!in_array('scheduled_at', $ppColumns)) {
                $pdo->exec("ALTER TABLE postforme_posts ADD COLUMN scheduled_at DATETIME NULL");
            }
            if (!in_array('published_at', $ppColumns)) {
                $pdo->exec("ALTER TABLE postforme_posts ADD COLUMN published_at DATETIME NULL");
            }
            if (!in_array('error_message', $ppColumns)) {
                $pdo->exec("ALTER TABLE postforme_posts ADD COLUMN error_message TEXT");
            }
            if (!in_array('account_ids', $ppColumns)) {
                $pdo->exec("ALTER TABLE postforme_posts ADD COLUMN account_ids TEXT");
            }
            // Update status to VARCHAR to support 'scheduled' status
            try {
                $pdo->exec("ALTER TABLE postforme_posts MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");
            } catch (Exception $e) {}
        } catch (Exception $e) {}
    }
    
    if (!$tablesExist) {
        // Create all tables automatically
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(255) NULL,
                client_slug VARCHAR(120) NULL,
                role ENUM('admin', 'user') DEFAULT 'user',
                status ENUM('active', 'disabled') DEFAULT 'active',
                can_use_github_runner TINYINT(1) DEFAULT 0,
                assigned_local_agent_id INT NULL,
                last_login_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_app_users_client_slug (client_slug)
            );

            CREATE TABLE IF NOT EXISTS magic_login_tokens (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL UNIQUE,
                redirect_path VARCHAR(255) NULL,
                expires_at DATETIME NOT NULL,
                one_time TINYINT(1) DEFAULT 1,
                used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by_user_id) REFERENCES app_users(id) ON DELETE SET NULL,
                INDEX idx_magic_user_active (user_id, expires_at),
                INDEX idx_magic_active_lookup (revoked_at, used_at, expires_at)
            );

            CREATE TABLE IF NOT EXISTS api_keys (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                api_key VARCHAR(255) NOT NULL,
                library_id VARCHAR(255),
                storage_zone VARCHAR(255),
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            );
            
            CREATE TABLE IF NOT EXISTS video_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                api_key_id INT,
                video_id VARCHAR(255),
                type ENUM('pull', 'process') DEFAULT 'pull',
                status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
                progress INT DEFAULT 0,
                error_message TEXT,
                output_path VARCHAR(500),
                completed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL
            );
            
            CREATE TABLE IF NOT EXISTS processing_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_id INT NOT NULL,
                task_type VARCHAR(100) NOT NULL,
                status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
                progress INT DEFAULT 0,
                error_message TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (job_id) REFERENCES video_jobs(id) ON DELETE CASCADE
            );
            
            CREATE TABLE IF NOT EXISTS automation_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                video_source ENUM('ftp', 'bunny', 'manual_links', 'youtube_channel') DEFAULT 'ftp',
                manual_video_links LONGTEXT NULL,
                youtube_channel_url VARCHAR(500) NULL,
                run_mode ENUM('local', 'github_runner') DEFAULT 'local',
                local_agent_id INT NULL,
                owner_user_id INT NULL,
                api_key_id INT,
                enabled TINYINT(1) DEFAULT 1,
                video_days_filter INT DEFAULT 30,
                video_start_date DATE NULL,
                video_end_date DATE NULL,
                videos_per_run INT DEFAULT 5,
                playback_speed DECIMAL(3,1) DEFAULT 1.0,
                source_shorts_mode ENUM('single', 'duration_based', 'fixed_count') DEFAULT 'single',
                source_shorts_max_count INT DEFAULT 1,
                process_id VARCHAR(20) NULL,
                short_duration INT DEFAULT 60,
                short_aspect_ratio VARCHAR(10) DEFAULT '9:16',
                -- Custom Taglines
                top_taglines_json TEXT,
                bottom_taglines_json TEXT,
                tagline_rotation_mode ENUM('sequential', 'random') DEFAULT 'sequential',
                current_tagline_index INT DEFAULT 0,
                last_top_index INT DEFAULT -1,
                last_bottom_index INT DEFAULT -1,
                -- Legacy Branding (for backward compat)
                branding_text_top VARCHAR(255),
                branding_text_bottom VARCHAR(255),
                whisper_enabled TINYINT(1) DEFAULT 0,
                whisper_language VARCHAR(10) DEFAULT 'en',
                schedule_type ENUM('minutes', 'hourly', 'daily', 'weekly') DEFAULT 'daily',
                schedule_hour INT DEFAULT 9,
                schedule_every_minutes INT DEFAULT 10,
                youtube_enabled TINYINT(1) DEFAULT 0,
                youtube_api_key VARCHAR(255),
                youtube_channel_id VARCHAR(255),
                tiktok_enabled TINYINT(1) DEFAULT 0,
                tiktok_access_token TEXT,
                instagram_enabled TINYINT(1) DEFAULT 0,
                instagram_access_token TEXT,
                facebook_enabled TINYINT(1) DEFAULT 0,
                facebook_access_token TEXT,
                facebook_page_id VARCHAR(255),
                dailymotion_enabled TINYINT(1) DEFAULT 0,
                dailymotion_api_key VARCHAR(255),
                dailymotion_api_secret VARCHAR(255),
                dailymotion_username VARCHAR(255),
                dailymotion_password VARCHAR(255),
                dailymotion_access_token TEXT,
                postforme_enabled TINYINT(1) DEFAULT 0,
                postforme_account_ids JSON,
                postforme_schedule_mode VARCHAR(20) DEFAULT 'immediate',
                postforme_schedule_datetime DATETIME NULL,
                postforme_schedule_timezone VARCHAR(100) DEFAULT 'UTC',
                postforme_schedule_offset_minutes INT DEFAULT 0,
                postforme_schedule_spread_minutes INT DEFAULT 0,
                rotation_enabled TINYINT(1) DEFAULT 1,
                rotation_cycle INT DEFAULT 1,
                rotation_auto_reset TINYINT(1) DEFAULT 1,
                rotation_shuffle TINYINT(1) DEFAULT 1,
                status ENUM('inactive', 'running', 'processing', 'completed', 'error', 'stopped', 'queued', 'paused') DEFAULT 'inactive',
                progress_percent INT DEFAULT 0,
                progress_data TEXT,
                last_progress_time TIMESTAMP NULL,
                last_run_at TIMESTAMP NULL,
                next_run_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (owner_user_id) REFERENCES app_users(id) ON DELETE SET NULL,
                FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL
            );
            
            CREATE TABLE IF NOT EXISTS automation_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                automation_id INT,
                action VARCHAR(100) NOT NULL,
                status ENUM('success', 'error', 'info') DEFAULT 'info',
                message TEXT,
                video_id VARCHAR(255),
                platform VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (automation_id) REFERENCES automation_settings(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS local_agents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agent_key VARCHAR(80) NOT NULL UNIQUE,
                agent_secret_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                machine_name VARCHAR(255) NULL,
                host_name VARCHAR(255) NULL,
                platform VARCHAR(80) NULL,
                agent_version VARCHAR(50) NULL,
                status ENUM('online', 'offline', 'disabled') DEFAULT 'offline',
                last_seen_at TIMESTAMP NULL,
                last_ip VARCHAR(64) NULL,
                capabilities_json LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS local_agent_jobs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                agent_id INT NOT NULL,
                automation_id INT NOT NULL,
                trigger_source VARCHAR(50) DEFAULT 'manual',
                status ENUM('queued', 'claimed', 'running', 'completed', 'error', 'cancelled') DEFAULT 'queued',
                claim_token VARCHAR(64) NULL,
                queued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                claimed_at TIMESTAMP NULL,
                started_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                last_heartbeat_at TIMESTAMP NULL,
                result_json LONGTEXT NULL,
                error_message TEXT NULL,
                FOREIGN KEY (agent_id) REFERENCES local_agents(id) ON DELETE CASCADE,
                FOREIGN KEY (automation_id) REFERENCES automation_settings(id) ON DELETE CASCADE
            );
            
            CREATE TABLE IF NOT EXISTS postforme_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id VARCHAR(100) NOT NULL UNIQUE,
                platform VARCHAR(50) NOT NULL,
                account_name VARCHAR(255),
                username VARCHAR(255),
                profile_image_url TEXT,
                is_active TINYINT(1) DEFAULT 1,
                last_synced_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            );
            
            CREATE TABLE IF NOT EXISTS postforme_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                post_id VARCHAR(100) NOT NULL,
                automation_id INT,
                video_path TEXT,
                caption TEXT,
                account_ids TEXT,
                status VARCHAR(50) DEFAULT 'pending',
                scheduled_at DATETIME NULL,
                published_at DATETIME NULL,
                error_message TEXT,
                results JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (automation_id) REFERENCES automation_settings(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS facebook_scheduled_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_key VARCHAR(64) NOT NULL UNIQUE,
                automation_id INT NULL,
                video_id VARCHAR(255) NULL,
                media_url TEXT NOT NULL,
                caption TEXT NULL,
                title VARCHAR(255) NULL,
                description TEXT NULL,
                account_ids TEXT NOT NULL,
                status VARCHAR(50) DEFAULT 'scheduled',
                scheduled_at DATETIME NOT NULL,
                published_at DATETIME NULL,
                error_message TEXT NULL,
                result_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            );
            
            CREATE TABLE IF NOT EXISTS processed_videos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                automation_id INT NOT NULL,
                video_identifier VARCHAR(255) NOT NULL,
                video_filename VARCHAR(255),
                file_size BIGINT DEFAULT 0,
                content_hash VARCHAR(64),
                cycle_number INT DEFAULT 1,
                processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                posted_at TIMESTAMP NULL,
                FOREIGN KEY (automation_id) REFERENCES automation_settings(id) ON DELETE CASCADE,
                UNIQUE KEY unique_video_per_cycle (automation_id, video_identifier, cycle_number)
            );
        ");

    }
    
} catch (PDOException $e) {
    // Check if this is an API request
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
    }
    die('<div style="padding:20px;background:#fee;border:1px solid #f00;margin:20px;">
        <h2>Database Connection Error</h2>
        <p>Could not connect to MySQL. Please check:</p>
        <ul>
            <li>MySQL is running in XAMPP Control Panel</li>
            <li>Username and password are correct in config.php</li>
        </ul>
        <p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
    </div>');
}

if (isset($pdo)) {
    try {
        $defaultSettings = [
            'openai_api_key' => '',
            'cohere_api_key' => '',
            'ffmpeg_path' => 'ffmpeg',
            'ffprobe_path' => '',
            'default_language' => 'en',
            'auto_install_local_runtime' => '1',
            'ffmpeg_auto_download_url' => '',
            'ffmpeg_auto_download_url_windows' => 'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip',
            'ffmpeg_auto_download_url_linux' => 'https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz',
            'local_agent_pairing_token' => '',
            'panel_public_base_url' => '',
        ];

        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = setting_value
        ");
        foreach ($defaultSettings as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    } catch (Exception $e) {
        // Continue with existing settings if default seeding fails.
    }

    try {
        $userTableExists = $pdo->query("SHOW TABLES LIKE 'app_users'")->rowCount() > 0;
        if ($userTableExists) {
            $userCount = (int)$pdo->query("SELECT COUNT(*) FROM app_users")->fetchColumn();
            if ($userCount === 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO app_users (
                        email, password_hash, display_name, client_slug, role, status, can_use_github_runner
                    ) VALUES (?, ?, ?, ?, 'admin', 'active', 1)
                ");
                $stmt->execute([
                    DEFAULT_ADMIN_EMAIL,
                    password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
                    'Administrator',
                    'administrator'
                ]);
            }

            $slugRows = $pdo->query("
                SELECT id, email, display_name
                FROM app_users
                WHERE client_slug IS NULL OR TRIM(client_slug) = ''
                ORDER BY id ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($slugRows as $slugRow) {
                $base = trim((string)($slugRow['display_name'] ?? ''));
                if ($base === '') {
                    $base = (string)($slugRow['email'] ?? '');
                }
                $base = strtolower($base);
                if (function_exists('iconv')) {
                    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
                    if (is_string($converted) && $converted !== '') {
                        $base = strtolower($converted);
                    }
                }
                $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
                $base = trim($base, '-');
                if ($base === '') {
                    $base = 'client';
                }
                $base = substr($base, 0, 80);
                $slug = $base;
                $suffix = 2;

                while (true) {
                    $check = $pdo->prepare("SELECT COUNT(*) FROM app_users WHERE client_slug = ? AND id <> ?");
                    $check->execute([$slug, (int)$slugRow['id']]);
                    if ((int)$check->fetchColumn() === 0) {
                        break;
                    }
                    $slug = substr($base, 0, 70) . '-' . $suffix;
                    $suffix++;
                }

                $updateSlug = $pdo->prepare("UPDATE app_users SET client_slug = ? WHERE id = ?");
                $updateSlug->execute([$slug, (int)$slugRow['id']]);
            }

            $firstAdminId = (int)$pdo->query("SELECT id FROM app_users ORDER BY CASE WHEN role = 'admin' THEN 0 ELSE 1 END, id ASC LIMIT 1")->fetchColumn();
            if ($firstAdminId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE automation_settings
                    SET owner_user_id = ?
                    WHERE owner_user_id IS NULL
                ");
                $stmt->execute([$firstAdminId]);
            }
        }
    } catch (Exception $e) {
        // Continue even if user bootstrap fails.
    }
}

$ytdlpCookiesFile = trim((string)(getenv('VW_YTDLP_COOKIES_FILE') ?: ''));
if ($ytdlpCookiesFile === '' && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ytdlp_cookies_file' LIMIT 1");
        $stmt->execute();
        $dbCookies = $stmt->fetchColumn();
        if (is_string($dbCookies)) {
            $ytdlpCookiesFile = trim($dbCookies);
        }
    } catch (Exception $e) {
        // Ignore optional cookies setting failures.
    }
}
define('YTDLP_COOKIES_FILE', $ytdlpCookiesFile);

$ytdlpPath = trim((string)(getenv('VW_YTDLP_PATH') ?: ''));
if ($ytdlpPath === '' && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ytdlp_path' LIMIT 1");
        $stmt->execute();
        $dbYtdlpPath = $stmt->fetchColumn();
        if (is_string($dbYtdlpPath)) {
            $ytdlpPath = trim($dbYtdlpPath);
        }
    } catch (Exception $e) {
        // Ignore optional yt-dlp path failures.
    }
}
define('YTDLP_PATH', $ytdlpPath);

$ytdlpCookiesBrowser = trim((string)(getenv('VW_YTDLP_COOKIES_BROWSER') ?: ''));
$ytdlpCookiesBrowserProfile = trim((string)(getenv('VW_YTDLP_COOKIES_BROWSER_PROFILE') ?: ''));
if (($ytdlpCookiesBrowser === '' || $ytdlpCookiesBrowserProfile === '') && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('ytdlp_cookies_browser', 'ytdlp_cookies_browser_profile')");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($ytdlpCookiesBrowser === '' && is_array($rows) && isset($rows['ytdlp_cookies_browser'])) {
            $ytdlpCookiesBrowser = trim((string)$rows['ytdlp_cookies_browser']);
        }
        if ($ytdlpCookiesBrowserProfile === '' && is_array($rows) && isset($rows['ytdlp_cookies_browser_profile'])) {
            $ytdlpCookiesBrowserProfile = trim((string)$rows['ytdlp_cookies_browser_profile']);
        }
    } catch (Exception $e) {
        // Ignore optional browser cookies setting failures.
    }
}
define('YTDLP_COOKIES_BROWSER', $ytdlpCookiesBrowser);
define('YTDLP_COOKIES_BROWSER_PROFILE', $ytdlpCookiesBrowserProfile);

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Send JSON response and exit
 */
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get random word from array
 */
function getRandomWord($words) {
    if (empty($words)) return '';
    return $words[array_rand($words)];
}

/**
 * Check if FFmpeg is installed
 */
function isFFmpegAvailable() {
    require_once __DIR__ . '/includes/FFmpegProcessor.php';
    $ffmpeg = new FFmpegProcessor();
    return $ffmpeg->isAvailable();
}

/**
 * Log message to file
 */
function logMessage($message, $level = 'info') {
    $logFile = LOGS_DIR . '/app_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$timestamp}] [{$level}] {$message}\n", FILE_APPEND);
}
?>
