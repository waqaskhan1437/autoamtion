-- Video Workflow Manager Database Schema
-- Import this in phpMyAdmin

CREATE DATABASE IF NOT EXISTS video_workflow;
USE video_workflow;

-- API Keys Table (Bunny CDN connections)
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    api_key VARCHAR(255) NOT NULL,
    library_id VARCHAR(100) NOT NULL,
    storage_zone VARCHAR(255),
    ftp_host VARCHAR(255),
    ftp_username VARCHAR(255),
    ftp_password VARCHAR(255),
    ftp_port INT DEFAULT 21,
    cdn_hostname VARCHAR(255),
    pull_zone_id VARCHAR(100),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Video Jobs Table
CREATE TABLE IF NOT EXISTS video_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    api_key_id INT NOT NULL,
    bunny_video_url TEXT,
    video_id VARCHAR(255),
    type ENUM('pull', 'process') NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT,
    progress INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
);

-- Processing Tasks Table
CREATE TABLE IF NOT EXISTS processing_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    task_type VARCHAR(100) NOT NULL,
    preset VARCHAR(255),
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    output_url TEXT,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (job_id) REFERENCES video_jobs(id) ON DELETE CASCADE
);

-- Automation Settings Table
CREATE TABLE IF NOT EXISTS automation_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    video_source ENUM('ftp', 'bunny', 'manual_links', 'youtube_channel') DEFAULT 'ftp',
    manual_video_links LONGTEXT NULL,
    youtube_channel_url VARCHAR(500) NULL,
    api_key_id INT NOT NULL,
    run_mode ENUM('local', 'github_runner') DEFAULT 'local',
    enabled BOOLEAN DEFAULT FALSE,
    
    -- Video Filter Settings
    video_days_filter INT DEFAULT 30,
    
    -- Short Conversion Settings
    short_duration INT DEFAULT 60,
    short_aspect_ratio VARCHAR(20) DEFAULT '9:16',
    
    -- Branding Settings
    branding_text_top VARCHAR(255),
    branding_text_bottom VARCHAR(255),
    random_words TEXT,
    
    -- Whisper/Caption Settings
    whisper_enabled BOOLEAN DEFAULT FALSE,
    whisper_language VARCHAR(10) DEFAULT 'en',
    caption_style VARCHAR(50) DEFAULT 'default',
    
    -- Schedule Settings
    schedule_type ENUM('minutes', 'hourly', 'daily', 'weekly') DEFAULT 'daily',
    schedule_hour INT DEFAULT 9,
    schedule_every_minutes INT DEFAULT 10,
    
    -- YouTube Settings
    youtube_enabled BOOLEAN DEFAULT FALSE,
    youtube_api_key VARCHAR(255),
    youtube_channel_id VARCHAR(255),
    youtube_access_token TEXT,
    youtube_refresh_token TEXT,
    
    -- TikTok Settings
    tiktok_enabled BOOLEAN DEFAULT FALSE,
    tiktok_access_token TEXT,
    tiktok_refresh_token TEXT,
    
    -- Instagram Settings
    instagram_enabled BOOLEAN DEFAULT FALSE,
    instagram_access_token TEXT,
    instagram_user_id VARCHAR(100),
    
    -- Facebook Settings
    facebook_enabled BOOLEAN DEFAULT FALSE,
    facebook_access_token TEXT,
    facebook_page_id VARCHAR(255),
    
    -- Post for Me Settings (Unified Social Media API)
    postforme_enabled BOOLEAN DEFAULT FALSE,
    postforme_account_ids TEXT,
    
    -- Post for Me Scheduling Settings
    postforme_schedule_mode ENUM('immediate', 'scheduled', 'offset') DEFAULT 'immediate',
    postforme_schedule_datetime DATETIME NULL,
    postforme_schedule_timezone VARCHAR(100) DEFAULT 'UTC',
    postforme_schedule_offset_minutes INT DEFAULT 0,
    postforme_schedule_spread_minutes INT DEFAULT 0,
    
    -- Video Rotation Settings
    rotation_enabled TINYINT(1) DEFAULT 1,
    rotation_cycle INT DEFAULT 1,
    rotation_auto_reset TINYINT(1) DEFAULT 1,
    rotation_shuffle TINYINT(1) DEFAULT 1,
    
    -- Status & Timestamps
    last_run_at TIMESTAMP NULL,
    next_run_at TIMESTAMP NULL,
    status ENUM('inactive', 'running', 'processing', 'completed', 'error', 'stopped', 'queued') DEFAULT 'inactive',
    
    -- Background Progress Tracking
    progress_percent INT DEFAULT 0,
    progress_data TEXT,
    last_progress_time TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
);

-- Add progress columns to existing table (run if upgrading)
-- ALTER TABLE automation_settings ADD COLUMN progress_percent INT DEFAULT 0;
-- ALTER TABLE automation_settings ADD COLUMN progress_data TEXT;
-- ALTER TABLE automation_settings ADD COLUMN last_progress_time TIMESTAMP NULL;
-- ALTER TABLE automation_settings MODIFY COLUMN status ENUM('inactive', 'running', 'processing', 'completed', 'error') DEFAULT 'inactive';

-- Automation Logs Table
CREATE TABLE IF NOT EXISTS automation_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    automation_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    status VARCHAR(50) NOT NULL,
    message TEXT,
    video_id VARCHAR(255),
    platform VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (automation_id) REFERENCES automation_settings(id) ON DELETE CASCADE
);

-- Settings Table (for global settings)
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO settings (setting_key, setting_value) VALUES
('openai_api_key', ''),
('ffmpeg_path', 'ffmpeg'),
('default_language', 'en')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- Post for Me Connected Accounts
CREATE TABLE IF NOT EXISTS postforme_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id VARCHAR(100) NOT NULL UNIQUE,
    platform VARCHAR(50) NOT NULL,
    account_name VARCHAR(255),
    username VARCHAR(255),
    profile_image_url TEXT,
    is_active BOOLEAN DEFAULT TRUE,
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
    scheduled_at DATETIME NULL,
    published_at DATETIME NULL,
    error_message TEXT,
    results TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (automation_id) REFERENCES automation_settings(id) ON DELETE SET NULL
);

-- Processed Videos Rotation Tracking
CREATE TABLE IF NOT EXISTS processed_videos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    automation_id INT NOT NULL,
    video_identifier VARCHAR(500) NOT NULL,
    video_filename VARCHAR(500),
    file_size BIGINT DEFAULT 0,
    content_hash VARCHAR(64),
    cycle_number INT DEFAULT 1,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    posted_at TIMESTAMP NULL,
    FOREIGN KEY (automation_id) REFERENCES automation_settings(id) ON DELETE CASCADE,
    UNIQUE KEY unique_video_per_cycle (automation_id, video_identifier, cycle_number)
);

-- Rotation Settings columns are added to automation_settings via ALTER below
-- ALTER TABLE automation_settings ADD COLUMN rotation_enabled TINYINT(1) DEFAULT 1;
-- ALTER TABLE automation_settings ADD COLUMN rotation_cycle INT DEFAULT 1;
-- ALTER TABLE automation_settings ADD COLUMN rotation_auto_reset TINYINT(1) DEFAULT 1;
-- ALTER TABLE automation_settings ADD COLUMN rotation_shuffle TINYINT(1) DEFAULT 1;

-- Insert Demo Data
INSERT INTO api_keys (name, api_key, library_id, storage_zone, ftp_host, ftp_username, cdn_hostname, status) VALUES
('Demo Bunny Account', 'demo-api-key-xxxxx', '12345', 'demo-storage', 'storage.bunnycdn.com', 'demo-user', 'demo.b-cdn.net', 'active');

INSERT INTO video_jobs (name, api_key_id, video_id, type, status, progress) VALUES
('Product Launch Video 2024', 1, 'demo-001', 'pull', 'completed', 100),
('Customer Testimonial', 1, 'demo-002', 'pull', 'completed', 100),
('Behind the Scenes Tour', 1, 'demo-003', 'pull', 'completed', 100),
('How To Use Our App', 1, 'demo-004', 'pull', 'completed', 100);
