-- Run this in phpMyAdmin to add background progress tracking
-- Only needed if you already have the database and want to upgrade

ALTER TABLE automation_settings 
ADD COLUMN IF NOT EXISTS progress_percent INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS progress_data TEXT,
ADD COLUMN IF NOT EXISTS last_progress_time TIMESTAMP NULL;

ALTER TABLE automation_settings
ADD COLUMN IF NOT EXISTS run_mode ENUM('local', 'github_runner') DEFAULT 'local';

ALTER TABLE automation_settings
ADD COLUMN IF NOT EXISTS manual_video_links LONGTEXT NULL;

ALTER TABLE automation_settings
MODIFY COLUMN video_source ENUM('ftp', 'bunny', 'manual_links', 'youtube_channel') DEFAULT 'ftp';

ALTER TABLE automation_settings
ADD COLUMN IF NOT EXISTS youtube_channel_url VARCHAR(500) NULL AFTER manual_video_links;

ALTER TABLE automation_settings
ADD COLUMN IF NOT EXISTS ai_taglines_enabled TINYINT(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS ai_tagline_prompt TEXT,
ADD COLUMN IF NOT EXISTS video_start_date DATE NULL,
ADD COLUMN IF NOT EXISTS video_end_date DATE NULL,
ADD COLUMN IF NOT EXISTS videos_per_run INT DEFAULT 5,
ADD COLUMN IF NOT EXISTS schedule_every_minutes INT DEFAULT 10,
ADD COLUMN IF NOT EXISTS process_id VARCHAR(20) NULL;

-- Update status enum to include new states (including queue)
ALTER TABLE automation_settings 
MODIFY COLUMN status ENUM('inactive', 'running', 'processing', 'completed', 'error', 'stopped', 'queued', 'paused') DEFAULT 'inactive';

-- For MySQL versions that don't support IF NOT EXISTS in ADD COLUMN:
-- Run each separately and ignore errors for columns that already exist:
-- ALTER TABLE automation_settings ADD COLUMN progress_percent INT DEFAULT 0;
-- ALTER TABLE automation_settings ADD COLUMN progress_data TEXT;
-- ALTER TABLE automation_settings ADD COLUMN last_progress_time TIMESTAMP NULL;

-- Taglines Pool for pre-generated AI taglines (research-based best practice)
-- Stores generated taglines for batch video processing to avoid duplicates
CREATE TABLE IF NOT EXISTS taglines_pool (
    id INT AUTO_INCREMENT PRIMARY KEY,
    automation_id INT NOT NULL,
    top_text VARCHAR(255) NOT NULL,
    bottom_text VARCHAR(255) NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_at TIMESTAMP NULL,
    INDEX idx_automation_id (automation_id),
    INDEX idx_used (used),
    INDEX idx_created (created_at)
);

-- AI Taglines Cache - stores all generated taglines for similarity checking
CREATE TABLE IF NOT EXISTS ai_taglines_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    automation_id INT,
    video_name VARCHAR(255),
    top_text VARCHAR(255) NOT NULL,
    bottom_text VARCHAR(255) NOT NULL,
    provider VARCHAR(50) DEFAULT 'gemini',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_automation (automation_id),
    INDEX idx_created (created_at)
);

-- Post for Me Integration (Unified Social Media API)
-- Add columns to automation_settings for Post for Me
ALTER TABLE automation_settings 
ADD COLUMN IF NOT EXISTS postforme_enabled TINYINT(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS postforme_account_ids TEXT;

-- Post for Me Connected Accounts
CREATE TABLE IF NOT EXISTS postforme_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id VARCHAR(100) NOT NULL UNIQUE,
    platform VARCHAR(50) NOT NULL,
    account_name VARCHAR(255),
    username VARCHAR(255),
    profile_image_url TEXT,
    is_active TINYINT(1) DEFAULT 1,
    last_synced_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Post for Me Posts Log
CREATE TABLE IF NOT EXISTS postforme_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id VARCHAR(100) NOT NULL,
    automation_id INT,
    video_id VARCHAR(255),
    caption TEXT,
    account_ids TEXT,
    status VARCHAR(50) DEFAULT 'pending',
    results TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
