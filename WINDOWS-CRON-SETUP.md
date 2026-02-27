# Windows Task Scheduler Setup - Auto-Trigger Automations

## Quick Setup (Recommended)

### Step 1: Open Task Scheduler
1. Press `Win + R`
2. Type `taskschd.msc` and press Enter

### Step 2: Create New Task
1. Click **"Create Task"** (not Basic Task)
2. **General Tab:**
   - Name: `VideoWorkflow Cron`
   - Check: "Run whether user is logged on or not"
   - Check: "Run with highest privileges"

### Step 3: Set Triggers
1. Click **Triggers** tab → **New**
2. Begin the task: **On a schedule**
3. Settings: **Daily**
4. Repeat task every: **5 minutes** (for duration: **Indefinitely**)
5. Check: **Enabled**
6. Click **OK**

### Step 4: Set Action
1. Click **Actions** tab → **New**
2. Action: **Start a program**
3. Program/script: `C:\xampp\php\php.exe`
4. Add arguments: `C:\xampp\htdocs\video-workflow\php-version\api\cron.php`
5. Click **OK**

### Step 5: Conditions
1. **Uncheck** "Start only if on AC power" (for laptops)

### Step 6: Settings
1. Check: "Allow task to be run on demand"
2. Check: "Run task as soon as possible after scheduled start is missed"
3. Check: "If the task fails, restart every: 10 minutes"
4. Click **OK**
5. Enter your Windows password when prompted

---

## Alternative: Command Line Setup

Run this in **Administrator Command Prompt**:

```batch
schtasks /create /tn "VideoWorkflow Cron" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\video-workflow\php-version\api\cron.php" /sc minute /mo 5 /ru SYSTEM
```

---

## How It Works

1. **Every 5 minutes**, Windows runs `cron.php`
2. `cron.php` checks for enabled automations with `next_run_at <= NOW()`
3. If found, it runs the automation and posts to social media
4. After completion, it calculates and sets the **next run time**
5. Dashboard shows **countdown timer** until next trigger

---

## Schedule Options in Automation Settings

| Schedule Type | How It Works |
|---------------|--------------|
| **Hourly** | Runs every hour from when enabled |
| **Daily** | Runs once per day at the specified hour (9 AM default) |
| **Weekly** | Runs every Monday at the specified hour |

---

## Testing the Cron

### Test in Browser
Visit: `http://localhost/video-workflow/php-version/api/cron.php`

You'll see JSON output like:
```json
{
  "status": "completed",
  "automations_run": [],
  "total_processed": 0,
  "timestamp": "2024-01-15 14:30:00"
}
```

### Test in Command Line
```batch
C:\xampp\php\php.exe C:\xampp\htdocs\video-workflow\php-version\api\cron.php
```

---

## Logs Location

Cron logs are saved daily in:
```
php-version/logs/cron_YYYY-MM-DD.log
```

---

## Troubleshooting

### Cron Not Running?
1. Check Task Scheduler history (right-click task → View History)
2. Verify PHP path is correct
3. Check `logs/cron_*.log` for errors

### Next Run Time Not Showing?
1. Toggle automation OFF then ON
2. Check database: `next_run_at` column in `automation_settings`

### Permission Errors?
1. Run Task Scheduler as Administrator
2. Make sure XAMPP has write permissions to logs folder
