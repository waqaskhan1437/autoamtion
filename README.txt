==================================================
VIDEO WORKFLOW MANAGER - PHP VERSION FOR XAMPP
Complete Automation System with Whisper & FFmpeg
==================================================

FEATURES:
---------
* Bunny CDN Integration - Fetch videos from your library
* FFmpeg Processing - Convert videos to shorts (9:16, 1:1, 16:9)
* OpenAI Whisper - Auto-generate captions/transcriptions
* Branding Text - Add top/bottom text with random words
* Social Media Posting - YouTube, TikTok, Instagram, Facebook
* Scheduled Automation - Hourly, daily, or weekly runs
* Activity Logs - Track all automation activities

==================================================
SETUP INSTRUCTIONS:
==================================================

STEP 1: COPY FILES
------------------
Copy the entire "php-version" folder to your XAMPP htdocs:
C:\xampp\htdocs\video-workflow\

STEP 2: CREATE DATABASE
-----------------------
1. Open phpMyAdmin: http://localhost/phpmyadmin
2. Click "Import" tab
3. Select file: database.sql
4. Click "Go" to import

STEP 3: INSTALL FFMPEG
----------------------
1. Download from: https://ffmpeg.org/download.html
   (Or https://www.gyan.dev/ffmpeg/builds/ for Windows)
2. Extract to: C:\ffmpeg
3. Add to System PATH:
   - Right-click "This PC" > Properties
   - Advanced system settings > Environment Variables
   - Under "System variables", find "Path"
   - Click Edit > New > C:\ffmpeg\bin
   - Click OK on all dialogs
4. Restart XAMPP
5. Test in CMD: ffmpeg -version

STEP 4: GET OPENAI API KEY (for Whisper)
----------------------------------------
1. Go to: https://platform.openai.com/api-keys
2. Create new API key
3. Copy the key
4. Go to Settings page in the app
5. Paste API key and save

STEP 5: ACCESS THE APP
----------------------
Open browser: http://localhost/video-workflow/
- Dashboard: Overview and statistics
- API Keys: Configure Bunny CDN connections
- Jobs: View processing jobs
- Automation: Create automated workflows
- Settings: Configure OpenAI key and FFmpeg

==================================================
HOW AUTOMATION WORKS:
==================================================

1. FETCH VIDEOS
   - Connects to Bunny CDN using your API key
   - Fetches videos from the last N days

2. PROCESS EACH VIDEO
   - Downloads video to local temp folder
   - Transcribes audio using Whisper (if enabled)
   - Converts to short format (vertical 9:16)
   - Adds branding text overlay
   - Adds random word from your list
   - Burns in captions (if Whisper enabled)

3. POST TO SOCIAL MEDIA
   - Uploads to enabled platforms
   - YouTube Shorts, TikTok, Instagram Reels, Facebook

4. LOGGING
   - All activities logged to database
   - View logs from Automation page

==================================================
BRANDING TEXT + RANDOM WORDS:
==================================================

Example Setup:
- Top Text: "Happy Birthday Video"
- Random Words: "legend, prank, roast, viral, amazing"

Result: Each video gets a different title:
- "Happy Birthday Video legend"
- "Happy Birthday Video prank"
- "Happy Birthday Video roast"

This helps avoid duplicate content detection!

==================================================
WHISPER TRANSCRIPTION:
==================================================

What it does:
- Extracts audio from video
- Sends to OpenAI Whisper API
- Gets word-by-word transcription
- Generates subtitle file
- Burns captions into video

Supported Languages:
- English, Urdu, Hindi, Arabic
- Spanish, French, German
- Chinese, Japanese, Korean
- And many more!

==================================================
SCHEDULE AUTOMATION:
==================================================

Option 1: Manual Run
- Click "Run Now" button on any automation

Option 2: Windows Task Scheduler
1. Open Task Scheduler
2. Create Basic Task
3. Set trigger (hourly/daily)
4. Action: Start a program
5. Program: C:\xampp\php\php.exe
6. Arguments: C:\xampp\htdocs\video-workflow\api\cron.php

Option 3: Keep Browser Tab Open
- The app will check schedules automatically

==================================================
FILE STRUCTURE:
==================================================

video-workflow/
├── index.php           # Dashboard
├── api-keys.php        # Bunny CDN settings
├── jobs.php            # Video jobs list
├── automation.php      # Automation manager
├── settings.php        # OpenAI & FFmpeg settings
├── config.php          # Database configuration
├── database.sql        # MySQL schema
│
├── includes/
│   ├── header.php              # Common header
│   ├── footer.php              # Common footer
│   ├── AutomationRunner.php    # Main automation engine
│   ├── BunnyAPI.php            # Bunny CDN API client
│   ├── FFmpegProcessor.php     # Video processing
│   ├── WhisperAPI.php          # OpenAI Whisper client
│   └── SocialMediaUploader.php # Social media APIs
│
├── api/
│   ├── cron.php         # Scheduled task handler
│   ├── run-automation.php # Manual run endpoint
│   └── seed-demo.php    # Demo data loader
│
├── temp/                # Temporary video files
├── output/              # Processed shorts
└── logs/                # Activity logs

==================================================
TROUBLESHOOTING:
==================================================

"Database connection failed"
- Make sure MySQL is running in XAMPP
- Check database name is "video_workflow"
- Import database.sql in phpMyAdmin

"FFmpeg not found"
- Install FFmpeg and add to PATH
- Restart XAMPP after adding to PATH
- Test with: ffmpeg -version in CMD

"Whisper API error"
- Check OpenAI API key is valid
- Make sure you have API credits
- Check internet connection

"Video download failed"
- Verify Bunny CDN API key
- Check Library ID is correct
- Ensure CDN hostname is set

==================================================
GITHUB RUNNER MODE (NEW)
==================================================

You can now choose runner mode per automation:
- local (existing behavior)
- github_runner (dispatches GitHub Actions workflow)

How to enable:
1. Open Settings > GitHub Runner
2. Add token, owner, repo, workflow file, branch
3. Create/Edit automation and select Runner Mode = GitHub Runner
4. Click Run Now (or let cron trigger) to dispatch workflow

Workflow file included in repo:
- .github/workflows/automation-runner.yml

Optional callback endpoint:
- api/github-runner-callback.php

Required GitHub secrets for hosted runners:
- VW_DB_HOST
- VW_DB_NAME
- VW_DB_USER
- VW_DB_PASS
- GH_RUNNER_CALLBACK_URL (optional)
- GH_RUNNER_CALLBACK_SECRET (optional)

==================================================
REQUIREMENTS:
==================================================

- XAMPP 8.0+ (PHP 8.0+ with MySQL)
- FFmpeg (latest version)
- OpenAI API key (for Whisper)
- Bunny CDN account
- Internet connection

==================================================
