# Video Workflow Manager - Automation Setup Guide

This guide explains complete automation setup in simple language.
It covers all automation tabs, all schedule types (minute/hourly/daily/weekly), rotation logic, aspect ratio, taglines, and cron jobs.

---

## 1. Before You Start

Required setup first:

1. Open `Settings` and configure FTP/Bunny source.
2. Configure `Post for Me API key` in Settings.
3. Sync/check Post for Me accounts.
4. Make sure FFmpeg works.
5. If using AI captions/taglines, set AI provider keys.

---

## 2. Create Automation - Tab by Tab

Open `Automation` page and click `Create Automation`.

You will see 4 tabs:

1. Basic
2. Video
3. Taglines
4. Publish

---

## 3. Tab 1 - Basic

Fields:

1. `Automation Name`: Any readable name (example: `Birthday Shorts Daily`).
2. `Video Source`:
   - `FTP Server`
   - `Bunny CDN`
3. `Bunny CDN Connection`: Select key if source is Bunny.
4. `Schedule`:
   - `Every X Minutes`
   - `Hourly`
   - `Daily`
   - `Weekly`
5. `Hour`:
   - Used mainly for daily/weekly.
6. `Every (minutes)`:
   - Used for `Every X Minutes` mode.
7. `Start automation immediately`:
   - If checked, automation starts active.

Recommended:

1. For testing: `Every X Minutes` + `2` or `5`.
2. For production: `Daily`/`Weekly` with stable hour.

---

## 4. Tab 2 - Video

### 4.1 Video selection

Two modes:

1. `Last X days`
2. `Date range` (`From Date` + `To Date`)

### 4.2 Smart Rotation (important)

Rotation options:

1. `Smart Video Rotation` (enable/disable)
2. `Random order (shuffle videos)`
3. `Auto-reset when all videos used`
4. `Videos per run` (1 to 500)

How smart rotation works:

1. It tracks already used videos per cycle.
2. It avoids repeats until all videos are used.
3. It can detect duplicates by file size too.
4. With auto-reset ON, it starts next cycle automatically after all used.

Recommended rotation profile:

1. Rotation enabled
2. Shuffle enabled
3. Auto-reset enabled
4. `Videos per run` small for reliability (example 3 to 10)

### 4.3 Short processing settings

1. `Short Duration (sec)` (example 20s, 60s)
2. `Aspect Ratio`:
   - Crop modes:
     - `9:16`
     - `1:1`
     - `16:9`
   - No-crop fit modes:
     - `9:16-fit`
     - `1:1-fit`
     - `16:9-fit`

Quick rule:

1. Use `9:16` for Shorts/Reels/TikTok standard.
2. Use `*-fit` when you do not want frame crop (black bars allowed).

### 4.4 Captions

1. `Enable Auto-Captions (Whisper AI)`
2. `Caption Language` (`en`, `ur`, `hi`, `ar`, etc.)

---

## 5. Tab 3 - Taglines

Main toggle:

1. `Enable Taglines`

Behavior:

1. If AI key is available and AI works, AI taglines are used.
2. If AI fails, local tagline generator is used as fallback.
3. Local generator may add emoji overlay if emoji assets exist.

Defaults:

1. Prompt and fallback fields are handled internally.
2. You can still edit in advanced flow if needed.

---

## 6. Tab 4 - Publish

Primary recommended method:

1. Enable `Post for Me`
2. Select one or more accounts
3. Choose post scheduling mode

### Post scheduling modes

1. `Immediate`
   - Post as soon as video is processed.
2. `Scheduled`
   - Exact date/time + timezone.
3. `Offset`
   - Delay posting by X minutes after processing.

Spread control:

1. `Spread between posts (minutes)`
   - If multiple videos in one run, each post is delayed by spread amount.

Example:

1. Videos per run = 3
2. Offset = 30 min
3. Spread = 15 min
4. Post times: +30, +45, +60 mins

---

## 7. Edit Automation

Use pencil icon on a card.
All 4 tabs are available again.
Update any setting and save.

Important:

1. If schedule changes, `next_run_at` should recalculate automatically.
2. For minute mode, ensure `Every (minutes)` has valid value.

---

## 8. Automation Card - What Each Metric Means

Card counters:

1. `Fetched`: videos discovered in this run after filters/rotation.
2. `Downloaded`: videos actually downloaded/used.
3. `Processed`: shorts created successfully.
4. `Scheduled`: posts scheduled for future publish.
5. `Posted`: posts published immediately.

Status:

1. `running`: enabled and waiting next run.
2. `processing`: currently executing.
3. `completed`: last run completed.
4. `error`: last run failed.
5. `inactive/stopped`: disabled.

---

## 9. Cron Jobs - Full Definition

Main cron endpoint:

1. `api/cron.php`

Responsibilities:

1. Picks due automations (`next_run_at <= now`).
2. Claims job safely to avoid duplicate workers.
3. Runs automation pipeline.
4. Updates progress/status.
5. Calculates and writes next run time.
6. Syncs post statuses with Post for Me.

Related APIs:

1. `api/check-progress.php` - live card progress polling.
2. `api/sync-postforme-status.php` - scheduled/posted count sync.
3. `api/scheduled-posts.php` - scheduled queue modal data.

---

## 10. Windows Cron Setup (Task Scheduler)

Use `WINDOWS-CRON-SETUP.md` for full steps.

Minimum required:

1. Program: `C:\xampp\php\php.exe`
2. Arguments: `C:\xampp\htdocs\video-workflow\php-version\api\cron.php`
3. Trigger: every 5 minutes (or 1 minute for heavy testing)

Testing command:

1. Browser: open `api/cron.php`
2. CLI: `C:\xampp\php\php.exe C:\xampp\htdocs\video-workflow\php-version\api\cron.php`

---

## 11. Recommended Profiles

### Profile A - Testing (fast feedback)

1. Schedule type: `Every X Minutes`
2. Every minutes: `2` or `5`
3. Videos per run: `1` to `3`
4. Post mode: `Offset` small delay
5. Spread: `2` to `5`

### Profile B - Stable Production

1. Schedule type: `Daily` or `Weekly`
2. Fixed hour
3. Videos per run: controlled batch
4. Rotation ON + auto-reset ON
5. Use timezone-aware scheduled mode for campaign posts

---

## 12. Troubleshooting Checklist

If run starts but card does not update:

1. Check `api/check-progress.php?id=<automation_id>`.
2. Check cron logs in `logs/cron_YYYY-MM-DD.log`.
3. Verify status transitions (`processing` -> `running/completed/error`).

If scheduled count mismatch:

1. Open scheduled modal for that automation.
2. Verify post IDs are saved in local `postforme_posts`.
3. Check `api/sync-postforme-status.php?automation_id=<id>`.

If emoji appears in manual but not cron:

1. Confirm local tagline fallback logs exist.
2. Confirm emoji assets are present.
3. Confirm FFmpeg pipeline receives `emojiPng`.

If automation repeats same videos:

1. Ensure rotation enabled.
2. Enable auto-reset.
3. Check cycle and processed video tracker.

---

## 13. Operational Best Practices

1. Keep `videos_per_run` realistic to avoid overload.
2. Use minute mode only for testing, not heavy production.
3. Keep cron interval consistent with schedule strategy.
4. Monitor logs daily.
5. Use Post for Me as primary publishing path for unified status.

---

## 14. Summary

This automation module is a full workflow engine:

1. source fetch
2. smart rotation
3. processing + overlays + captions
4. AI/local tagline generation
5. social scheduling/publishing
6. live status + cron orchestration

Use this guide as your standard setup SOP for client onboarding and internal team operations.

