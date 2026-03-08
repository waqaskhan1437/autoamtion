<?php
/**
 * Automation Runner
 * Main processor that fetches videos, converts to shorts, adds captions, and posts to social media
 */

require_once __DIR__ . '/BunnyAPI.php';
require_once __DIR__ . '/FTPAPI.php';
require_once __DIR__ . '/FFmpegProcessor.php';
require_once __DIR__ . '/WhisperAPI.php';
require_once __DIR__ . '/SocialMediaUploader.php';
require_once __DIR__ . '/AITaglineGenerator.php';
require_once __DIR__ . '/PostForMeAPI.php';
require_once __DIR__ . '/LocalTaglineGenerator.php';
require_once __DIR__ . '/ShortSegmentPlanner.php';
require_once __DIR__ . '/YouTubeSource.php';

class AutomationRunner {
    private $pdo;
    private $automationId;
    private $automation;
    private $apiKey;
    private $tempDir;
    private $outputDir;
    private $logCallback = null;
    
    public function __construct($pdo, $automationId) {
        $this->pdo = $pdo;
        $this->automationId = $automationId;
        
        // Set directories
        if (PHP_OS_FAMILY === 'Windows') {
            $baseDir = 'C:/VideoWorkflow';
        } else {
            $baseDir = getenv('HOME') . '/VideoWorkflow';
        }
            
        $this->tempDir = $baseDir . '/temp';
        $this->outputDir = $baseDir . '/output';
        
        // Create directories with error handling
        $this->ensureDirectory($this->tempDir);
        $this->ensureDirectory($this->outputDir);
        
        $this->loadAutomation();
    }
    
    /**
     * Ensure directory exists and is writable
     */
    private function ensureDirectory($path) {
        if (!is_dir($path)) {
            if (!@mkdir($path, 0777, true)) {
                throw new Exception("Cannot create directory: {$path}. Check permissions.");
            }
        }
        if (!is_writable($path)) {
            throw new Exception("Directory not writable: {$path}. Check permissions.");
        }
    }
    
    /**
     * Set callback for real-time logging
     */
    public function setLogCallback($callback) {
        $this->logCallback = $callback;
    }
    
    private function loadAutomation() {
        $stmt = $this->pdo->prepare("
            SELECT a.*, k.api_key, k.library_id, k.storage_zone, k.cdn_hostname
            FROM automation_settings a
            LEFT JOIN api_keys k ON a.api_key_id = k.id
            WHERE a.id = ?
        ");
        $stmt->execute([$this->automationId]);
        $this->automation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$this->automation) {
            throw new Exception("Automation not found: " . $this->automationId);
        }
    }
    
    /**
     * Run the full automation pipeline
     */
    public function run() {
        $this->log('run_started', 'info', 'Automation run started');
        
        try {
            // Step 1: Fetch videos from Bunny CDN
            $videos = $this->fetchVideos();
            $totalFetched = count($videos);
            $fetchedForStats = $totalFetched;
            $this->log('videos_fetched', 'success', "Fetched {$totalFetched} videos from source");
            
            if (empty($videos)) {
                $this->log('no_videos', 'info', 'No new videos to process');
                return [
                    'fetched' => 0,
                    'downloaded' => 0,
                    'processed' => 0,
                    'scheduled' => 0,
                    'posted' => 0
                ];
            }
            
            // Step 1.5: Apply rotation filter (skip already-processed videos)
            $rotationEnabled = !empty($this->automation['rotation_enabled']);
            if ($rotationEnabled) {
                $videos = $this->applyRotationFilter($videos);
                $fetchedForStats = count($videos);
                $this->log('rotation_filter', 'info', "Rotation: {$totalFetched} total, " . count($videos) . " remaining in cycle");
                
                if (empty($videos)) {
                    $this->log('no_videos', 'info', 'All videos processed in current cycle');
                    return [
                        'fetched' => $totalFetched,
                        'downloaded' => 0,
                        'processed' => 0,
                        'scheduled' => 0,
                        'posted' => 0
                    ];
                }
            }
            
            // Oldest-first batching: process N videos per run
            $videosPerRun = intval($this->automation['videos_per_run'] ?? 5);
            if ($videosPerRun < 1) $videosPerRun = 1;
            if ($videosPerRun > 500) $videosPerRun = 500;
            
            usort($videos, function($a, $b) {
                $ta = $this->getVideoUploadedTimestamp($a);
                $tb = $this->getVideoUploadedTimestamp($b);
                
                if ($ta === $tb) {
                    return strcmp($this->getVideoSortKey($a), $this->getVideoSortKey($b));
                }
                
                return ($ta < $tb) ? -1 : 1;
            });
            
            $availableCount = count($videos);
            $fetchedForStats = $availableCount;
            if ($availableCount > $videosPerRun) {
                $videos = array_slice($videos, 0, $videosPerRun);
            }
            
            $this->log('batch', 'info', "Batch: processing " . count($videos) . " of {$availableCount} (oldest first)");
            
            if ($rotationEnabled && !empty($this->automation['rotation_shuffle']) && count($videos) > 1) {
                shuffle($videos); // Shuffle only within the selected batch
                $this->log('batch_shuffle', 'info', 'Batch shuffled for random order');
            }
            
            $downloaded = 0;
            $processed = 0;
            $scheduled = 0;
            $posted = 0;
            $randomWords = json_decode($this->automation['random_words'] ?? '[]', true) ?: [];
            
            foreach ($videos as $video) {
                try {
                    $result = $this->processVideo($video, $randomWords);
                    if ($result['success']) {
                        $downloaded += (int)($result['downloaded'] ?? 1);
                        $processed += (int)($result['processed'] ?? 1);
                        $scheduled += (int)($result['scheduled'] ?? 0);
                        $posted += (int)($result['posted'] ?? 0);
                        // Mark video as processed in rotation tracker
                        if ($rotationEnabled) {
                            $this->markVideoProcessed($video);
                        }
                    }
                } catch (Exception $e) {
                    $videoId = is_array($video) ? ($video['guid'] ?? $video['filename'] ?? 'unknown') : $video;
                    $this->log('video_error', 'error', 'Error processing video: ' . $e->getMessage(), $videoId);
                }
            }
            
            // Update last run time
            $this->updateLastRun();
            
            $this->log('run_completed', 'success', "Processed {$processed} videos successfully (scheduled: {$scheduled}, posted: {$posted})");
            
            return [
                'fetched' => $fetchedForStats,
                'downloaded' => $downloaded,
                'processed' => $processed,
                'scheduled' => $scheduled,
                'posted' => $posted
            ];
            
        } catch (Exception $e) {
            $this->log('run_failed', 'error', 'Automation failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Apply rotation filter: remove already-processed videos, shuffle remaining
     * Supports auto-reset when all videos are used
     */
    private function applyRotationFilter($videos) {
        $cycleNumber = intval($this->automation['rotation_cycle'] ?? 1);
        $autoReset = !empty($this->automation['rotation_auto_reset']);
        
        // Get already-processed video identifiers/fingerprints for current cycle
        $processedTracker = $this->getProcessedVideoTracker($cycleNumber);
        $processedIds = $processedTracker['ids'];
        $processedHashes = $processedTracker['hashes'];
        
        // Filter out processed videos (match by identifier AND by file size for duplicate detection)
        $remaining = [];
        foreach ($videos as $video) {
            $videoIds = $this->getVideoIdentifierCandidates($video);
            $videoId = $videoIds[0] ?? $this->getVideoIdentifier($video);
            $fileSize = $this->getVideoFileSize($video);
            $videoHash = $this->getVideoFingerprint($video);
            
            // Check by identifier
            $matchedById = false;
            foreach ($videoIds as $candidate) {
                if (isset($processedIds[$candidate])) {
                    $matchedById = true;
                    break;
                }
            }
            if ($matchedById) {
                continue;
            }

            // Check by stable fingerprint (helps when source returns different id format)
            if ($videoHash !== '' && isset($processedHashes[$videoHash])) {
                continue;
            }
            
            // Check by file size (duplicate detection - same content, different name)
            if ($fileSize > 0 && $this->isDuplicateBySize($cycleNumber, $fileSize, $videoId)) {
                $this->log('rotation_skip', 'info', "Skipping duplicate (same size): {$videoId}", $videoId);
                continue;
            }
            
            $remaining[] = $video;
        }
        
        // Auto-reset: if all videos used, start new cycle
        if (empty($remaining) && $autoReset && count($videos) > 0) {
            $newCycle = $cycleNumber + 1;
            $this->log('rotation_reset', 'info', "All {$cycleNumber} cycle videos used. Starting cycle {$newCycle}");
            
            // Update cycle number in database
            $stmt = $this->pdo->prepare("UPDATE automation_settings SET rotation_cycle = ? WHERE id = ?");
            $stmt->execute([$newCycle, $this->automationId]);
            $this->automation['rotation_cycle'] = $newCycle;
            
            // All videos are available again in the new cycle
            $remaining = $videos;
        }
        
        return $remaining;
    }
    
    /**
     * Get video identifier (unique key for tracking)
     */
    private function getVideoIdentifier($video) {
        if (is_array($video)) {
            return $video['guid'] ?? $video['ObjectName'] ?? $video['filename'] ?? $video['name'] ?? md5(json_encode($video));
        }
        return basename($video);
    }

    /**
     * Get all reasonable identifier candidates for robust matching
     */
    private function getVideoIdentifierCandidates($video) {
        $ids = [];

        if (is_array($video)) {
            $candidates = [
                $video['guid'] ?? null,
                $video['ObjectName'] ?? null,
                $video['remotePath'] ?? null,
                $video['path'] ?? null,
                $video['filename'] ?? null,
                $video['name'] ?? null
            ];

            foreach ($candidates as $value) {
                if ($value === null) continue;
                $normalized = trim((string)$value);
                if ($normalized === '') continue;
                $ids[$normalized] = true;
                $ids[strtolower($normalized)] = true;
            }
        } else {
            $raw = trim((string)basename($video));
            if ($raw !== '') {
                $ids[$raw] = true;
                $ids[strtolower($raw)] = true;
            }
        }

        if (empty($ids)) {
            $fallback = (string)$this->getVideoIdentifier($video);
            if ($fallback !== '') {
                $ids[$fallback] = true;
                $ids[strtolower($fallback)] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Build a stable fingerprint for cross-source duplicate matching
     */
    private function getVideoFingerprint($video) {
        $idBase = '';
        $size = $this->getVideoFileSize($video);

        if (is_array($video)) {
            $idBase = (string)($video['remotePath'] ?? $video['path'] ?? $video['ObjectName'] ?? $video['filename'] ?? $video['name'] ?? $video['guid'] ?? '');
        } else {
            $idBase = (string)basename($video);
        }

        $idBase = strtolower(trim($idBase));
        if ($idBase === '' && $size <= 0) return '';

        return hash('sha1', $idBase . '|' . (string)$size);
    }

    /**
     * Redact sensitive tokens/keys before writing debug logs
     */
    private function redactSensitiveData($data) {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $key => $value) {
                $k = strtolower((string)$key);
                if (in_array($k, ['access_token', 'refresh_token', 'api_key', 'authorization'], true)) {
                    $out[$key] = '[REDACTED]';
                } else {
                    $out[$key] = $this->redactSensitiveData($value);
                }
            }
            return $out;
        }
        return $data;
    }
    
    /**
     * Get video file size for duplicate detection
     */
    private function getVideoFileSize($video) {
        if (is_array($video)) {
            return intval($video['Length'] ?? $video['size'] ?? $video['ContentLength'] ?? 0);
        }
        return 0;
    }
    
    private function getVideoUploadedTimestamp($video) {
        if (!is_array($video)) return PHP_INT_MAX;
        $raw = $video['dateUploaded'] ?? $video['DateCreated'] ?? $video['dateCreated'] ?? null;
        if (empty($raw)) return PHP_INT_MAX;
        $ts = strtotime($raw);
        return ($ts === false) ? PHP_INT_MAX : $ts;
    }
    
    private function getVideoSortKey($video) {
        if (!is_array($video)) return (string)$video;
        return (string)($video['filename'] ?? $video['ObjectName'] ?? $video['title'] ?? $video['name'] ?? $video['guid'] ?? md5(json_encode($video)));
    }
    
    /**
     * Get list of already-processed video identifiers for a cycle
     */
    private function getProcessedVideoTracker($cycleNumber) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT video_identifier, content_hash FROM processed_videos 
                WHERE automation_id = ? AND cycle_number = ?
            ");
            $stmt->execute([$this->automationId, $cycleNumber]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ids = [];
            $hashes = [];

            foreach ($rows as $row) {
                $rawId = trim((string)($row['video_identifier'] ?? ''));
                if ($rawId !== '') {
                    $ids[$rawId] = true;
                    $ids[strtolower($rawId)] = true;
                }

                $rawHash = trim((string)($row['content_hash'] ?? ''));
                if ($rawHash !== '') {
                    $hashes[$rawHash] = true;
                }
            }

            return ['ids' => $ids, 'hashes' => $hashes];
        } catch (Exception $e) {
            return ['ids' => [], 'hashes' => []];
        }
    }
    
    /**
     * Check if a video with the same file size already exists (duplicate detection)
     */
    private function isDuplicateBySize($cycleNumber, $fileSize, $excludeId) {
        if ($fileSize <= 0) return false;
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM processed_videos 
                WHERE automation_id = ? AND cycle_number = ? AND file_size = ? AND video_identifier != ?
            ");
            $stmt->execute([$this->automationId, $cycleNumber, $fileSize, $excludeId]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Mark a video as processed in the rotation tracker
     */
    private function markVideoProcessed($video) {
        $cycleNumber = intval($this->automation['rotation_cycle'] ?? 1);
        $videoIds = $this->getVideoIdentifierCandidates($video);
        $videoId = $videoIds[0] ?? $this->getVideoIdentifier($video);
        $filename = is_array($video) ? ($video['title'] ?? $video['filename'] ?? $video['ObjectName'] ?? $videoId) : basename($video);
        $fileSize = $this->getVideoFileSize($video);
        $contentHash = $this->getVideoFingerprint($video);
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO processed_videos (automation_id, video_identifier, video_filename, file_size, cycle_number, processed_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE processed_at = NOW()
            ");

            // Save all identifier aliases so future runs match regardless of source id format.
            foreach ($videoIds as $candidateId) {
                $stmt->execute([$this->automationId, $candidateId, $filename, $fileSize, $cycleNumber]);
            }

            // Persist canonical content hash for fallback matching.
            if ($contentHash !== '') {
                $hashStmt = $this->pdo->prepare("
                    UPDATE processed_videos
                    SET content_hash = ?
                    WHERE automation_id = ? AND cycle_number = ? AND video_identifier = ?
                ");
                $hashStmt->execute([$contentHash, $this->automationId, $cycleNumber, $videoId]);
            }
        } catch (Exception $e) {
            error_log("Failed to mark video as processed: " . $e->getMessage());
        }
    }
    
    /**
     * Get rotation stats for this automation
     */
    public function getRotationStats() {
        $cycleNumber = intval($this->automation['rotation_cycle'] ?? 1);
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as used FROM processed_videos 
                WHERE automation_id = ? AND cycle_number = ?
            ");
            $stmt->execute([$this->automationId, $cycleNumber]);
            $used = $stmt->fetchColumn();
            
            return [
                'cycle' => $cycleNumber,
                'used' => intval($used),
                'rotation_enabled' => !empty($this->automation['rotation_enabled']),
                'auto_reset' => !empty($this->automation['rotation_auto_reset']),
                'shuffle' => !empty($this->automation['rotation_shuffle'])
            ];
        } catch (Exception $e) {
            return ['cycle' => $cycleNumber, 'used' => 0, 'rotation_enabled' => true, 'auto_reset' => true, 'shuffle' => true];
        }
    }

    private function parseManualVideoLinks() {
        $raw = is_string($this->automation['manual_video_links'] ?? null)
            ? (string)$this->automation['manual_video_links']
            : '';
        $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\n,]+/', $raw) ?: [];
        $seen = [];
        $links = [];
        foreach ($parts as $part) {
            $url = trim((string)$part);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $links[] = $url;
        }
        return $links;
    }

    private function buildManualFilename(string $url, int $index): string {
        $path = parse_url($url, PHP_URL_PATH);
        $baseName = $path ? basename((string)$path) : '';
        $baseName = urldecode((string)$baseName);
        $baseName = trim($baseName);

        if ($baseName === '' || strpos($baseName, '.') === false) {
            $baseName = 'video.mp4';
        }

        $baseName = preg_replace('/[^A-Za-z0-9._-]/', '_', $baseName);
        if ($baseName === '' || $baseName === '.' || $baseName === '..') {
            $baseName = 'video.mp4';
        }

        $ext = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));
        $allowed = ['mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v'];
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            $baseName .= '.mp4';
        }

        return 'manual_' . str_pad((string)$index, 3, '0', STR_PAD_LEFT) . '_' . $baseName;
    }

    private function toManualVideoEntries(array $links): array {
        $videos = [];
        foreach ($links as $idx => $url) {
            $videos[] = [
                'guid' => hash('sha1', $url),
                'title' => 'Manual Link ' . ($idx + 1),
                'filename' => $this->buildManualFilename((string)$url, $idx + 1),
                'remotePath' => $url,
                'manual_url' => $url,
                'Length' => 0,
                'size' => 0
            ];
        }
        return $videos;
    }

    private function getYouTubeChannelUrl(): string {
        $url = trim((string)($this->automation['youtube_channel_url'] ?? ''));
        if ($url === '') {
            $fallback = trim((string)($this->automation['manual_video_links'] ?? ''));
            if ($fallback !== '') {
                $parts = preg_split('/[\r\n,]+/', $fallback) ?: [];
                $url = trim((string)($parts[0] ?? ''));
            }
        }

        if ($url === '') {
            throw new Exception('YouTube channel URL is not configured for this automation.');
        }

        return $url;
    }
    
    /**
     * Fetch videos from configured source (FTP or Bunny CDN)
     */
    private function fetchVideos() {
        $source = $this->automation['video_source'] ?? 'ftp';

        $daysFilter = intval($this->automation['video_days_filter'] ?? 30);
        if ($daysFilter < 1) $daysFilter = 30;

        $startValue = $this->automation['video_start_date'] ?? null;
        $endValue = $this->automation['video_end_date'] ?? null;

        $startStr = is_string($startValue) ? trim($startValue) : (is_null($startValue) ? '' : trim((string)$startValue));
        $endStr = is_string($endValue) ? trim($endValue) : (is_null($endValue) ? '' : trim((string)$endValue));

        $hasStart = ($startStr !== '' && strtolower($startStr) !== 'null' && $startStr !== '0000-00-00');
        $hasEnd = ($endStr !== '' && strtolower($endStr) !== 'null' && $endStr !== '0000-00-00');

        $startTs = $hasStart ? strtotime($startStr) : false;
        $endTs = $hasEnd ? strtotime($endStr) : false;

        $usingDateRange = ($startTs !== false && $endTs !== false && $startTs <= $endTs);

        $filterLabel = $usingDateRange
            ? "date range {$startStr} to {$endStr}"
            : "last {$daysFilter} days";

        if (!$usingDateRange && ($hasStart || $hasEnd)) {
            // Explain why date range isn't used (helps debugging in logs)
            if ($startTs === false || $endTs === false) {
                $filterLabel .= " (invalid date format)";
            } elseif ($startTs > $endTs) {
                $filterLabel .= " (end before start)";
            } else {
                $filterLabel .= " (incomplete range)";
            }
        }
        
        if ($source === 'manual_links') {
            $this->log('fetch', 'info', 'Fetching videos from manual direct links');
            $links = $this->parseManualVideoLinks();
            $videos = $this->toManualVideoEntries($links);
            $this->log('fetch', 'success', 'Loaded ' . count($videos) . ' manual links');
            return $videos;
        }

        if ($source === 'youtube_channel') {
            $channelUrl = $this->getYouTubeChannelUrl();
            $this->log('fetch', 'info', "Fetching videos from YouTube channel ({$filterLabel})");
            $youtube = new YouTubeSource($channelUrl);
            $resultLimit = max((int)($this->automation['videos_per_run'] ?? 5) * 3, 10);
            $videos = $youtube->listVideos(
                $daysFilter,
                $usingDateRange ? $startStr : null,
                $usingDateRange ? $endStr : null,
                $resultLimit
            );
            $this->log('fetch', 'success', 'Found ' . count($videos) . ' videos on YouTube');
            return $videos;
        }

        if ($source === 'ftp') {
            // Fetch from FTP
            $this->log('fetch', 'info', "Fetching videos from FTP server ({$filterLabel})");
            try {
                $ftp = FTPAPI::fromSettings($this->pdo);
                $videos = $usingDateRange
                    ? $ftp->getVideosByDateRange($startStr, $endStr)
                    : $ftp->getVideos($daysFilter);
                $ftp->disconnect();
                $this->log('fetch', 'success', 'Found ' . count($videos) . ' videos on FTP');
                return $videos;
            } catch (Exception $e) {
                throw new Exception('FTP Error: ' . $e->getMessage());
            }
        }

        // Fetch from Bunny CDN
        $this->log('fetch', 'info', "Fetching videos from Bunny CDN ({$filterLabel})");
        
        if (empty($this->automation['api_key']) || empty($this->automation['library_id'])) {
            throw new Exception('Bunny API key or Library ID not configured. Please check API Keys settings.');
        }
        
        $bunny = new BunnyAPI(
            $this->automation['api_key'],
            $this->automation['library_id'],
            $this->automation['storage_zone'] ?? '',
            $this->automation['cdn_hostname'] ?? ''
        );
        
        $videos = $usingDateRange
            ? $bunny->getVideosByDateRange($startStr, $endStr)
            : $bunny->getRecentVideos($daysFilter);
        
        if (isset($videos['error'])) {
            $this->log('fetch', 'error', 'Bunny API Error: ' . ($videos['message'] ?? $videos['error']));
            throw new Exception('Bunny API Error: ' . ($videos['message'] ?? $videos['error']));
        }
        
        $this->log('fetch', 'success', 'Found ' . count($videos) . ' videos on Bunny CDN');
        return $videos;
    }
    
    /**
     * Process a single video
     */
    private function processVideo($video, $randomWords) {
        $videoId = is_array($video)
            ? ($video['guid'] ?? $video['remotePath'] ?? $video['filename'] ?? md5(json_encode($video)))
            : (string)$video;
        $videoTitle = is_array($video)
            ? ($video['title'] ?? $video['filename'] ?? basename((string)($video['remotePath'] ?? $videoId)))
            : basename((string)$videoId);
        if ($videoTitle === '') {
            $videoTitle = 'Untitled';
        }
        
        $this->log('processing_video', 'info', "Processing: {$videoTitle}", $videoId);
        
        // Step 1: Download video
        $localPath = $this->downloadVideo($video);
        
        // Step 2: Get branding text (AI or manual)
        $topText = '';
        $bottomText = '';
        $emoji = '';
        $emojiPng = null;
        
        if (!empty($this->automation['ai_taglines_enabled'])) {
            // Use AI to generate unique taglines
            $this->log('ai_tagline', 'info', 'Generating AI taglines for video');
            
            $aiGenerator = new AITaglineGenerator($this->pdo);
            $prompt = $this->automation['ai_tagline_prompt'] ?? 'Generate catchy viral taglines';
            $taglines = $aiGenerator->generateTaglines(
                $prompt,
                $videoTitle,
                $this->getUsedTaglines()
            );
            
            if (isset($taglines['success']) && $taglines['success']) {
                $topText = $taglines['top'];
                $bottomText = $taglines['bottom'];
                $this->saveUsedTagline($topText, $bottomText);
                $this->log('ai_tagline', 'success', "AI Generated: Top='{$topText}' Bottom='{$bottomText}'");
            } else {
                $this->log('ai_tagline', 'error', 'AI tagline failed: ' . ($taglines['error'] ?? 'Unknown error'));
                // Fallback 1: local tagline generator (no external API needed)
                try {
                    $localGen = new LocalTaglineGenerator();
                    $local = $localGen->generate();
                    $topText = $local['top'] ?? '';
                    $bottomText = $local['bottom'] ?? '';
                    $emoji = $local['emoji'] ?? '';
                    $emojiPng = $local['emojiPng'] ?? null;

                    if ($topText !== '' || $bottomText !== '') {
                        $this->saveUsedTagline($topText, $bottomText);
                        $emojiStatus = $emojiPng ? "Emoji: {$emoji}" : "No emoji PNG";
                        $this->log('local_tagline', 'success', "Top: {$topText} | Bottom: {$bottomText} | {$emojiStatus}");
                    } else {
                        throw new Exception('Local generator returned empty result');
                    }
                } catch (Exception $localError) {
                    $this->log('local_tagline', 'error', 'Local tagline failed: ' . $localError->getMessage());
                    // Fallback 2: static manual branding text
                    $topText = $this->automation['branding_text_top'] ?? '';
                    $bottomText = $this->automation['branding_text_bottom'] ?? '';
                }
            }
        } else {
            // Manual branding with random words
            $randomWords = json_decode($this->automation['random_words'] ?? '[]', true) ?: [];
            $randomWord = !empty($randomWords) ? $randomWords[array_rand($randomWords)] : '';
            $topText = $this->automation['branding_text_top'] ?? '';
            if ($topText && $randomWord) {
                $topText .= ' ' . $randomWord;
            }
            $bottomText = $this->automation['branding_text_bottom'] ?? '';
        }
        
        // Step 3: Transcribe with Whisper (if enabled and OpenAI key is set)
        $subtitlesPath = null;
        $whisperEnabled = $this->automation['whisper_enabled'] ?? false;
        $openaiKey = $this->getOpenAIKey();
        if ($whisperEnabled && $openaiKey) {
            $whisperLanguage = $this->automation['whisper_language'] ?? 'en';
            $subtitlesPath = $this->transcribeVideo($localPath, $openaiKey, $whisperLanguage);
        }
        
        $shortPlan = $this->buildShortSegmentPlan($localPath);
        $segmentTotal = count($shortPlan['segments']);
        $this->log(
            'short_plan',
            'info',
            "Short plan: {$segmentTotal} clip(s) using {$shortPlan['mode']} mode",
            $videoId
        );

        $jobIds = [];
        $processedCount = 0;
        $scheduled = 0;
        $posted = 0;

        foreach ($shortPlan['segments'] as $segment) {
            $clipIndex = (int)($segment['index'] ?? ($processedCount + 1));
            $segmentVideoId = $segmentTotal > 1 ? "{$videoId}#clip{$clipIndex}" : $videoId;
            $segmentLabel = $segmentTotal > 1 ? "Clip {$clipIndex}/{$segmentTotal}" : 'Clip 1/1';
            $segmentTitle = $segmentTotal > 1 ? "{$videoTitle} ({$segmentLabel})" : $videoTitle;

            try {
                $shortPath = $this->createShort($localPath, $segmentVideoId, [
                    'duration' => (int)($segment['duration'] ?? ($this->automation['short_duration'] ?? 60)),
                    'startTime' => (int)($segment['start'] ?? 0),
                    'clipIndex' => $clipIndex,
                    'clipTotal' => $segmentTotal,
                    'playbackSpeed' => (float)($this->automation['playback_speed'] ?? 1.0),
                    'aspectRatio' => $this->automation['short_aspect_ratio'] ?? '9:16',
                    'topText' => $topText,
                    'bottomText' => $bottomText,
                    'subtitlesPath' => $subtitlesPath,
                    'emoji' => $emoji,
                    'emojiPng' => $emojiPng
                ]);

                $jobIds[] = $this->createJob($segmentTitle, $segmentVideoId, $topText);

                $caption = $topText ?: $videoTitle;
                if ($segmentTotal > 1) {
                    $caption .= ' Part ' . $clipIndex;
                }
                $postStats = $this->postToSocialMedia($shortPath, $caption, $segmentVideoId);

                $processedCount++;
                $scheduled += (int)($postStats['scheduled'] ?? 0);
                $posted += (int)($postStats['posted'] ?? 0);
                $this->log('video_clip_completed', 'success', "{$segmentLabel} completed", $segmentVideoId);
            } catch (Exception $e) {
                $this->log('video_clip_error', 'error', "{$segmentLabel} failed: " . $e->getMessage(), $segmentVideoId);
            }
        }
        
        // Clean up temp files
        @unlink($localPath);
        if ($subtitlesPath) @unlink($subtitlesPath);

        if ($processedCount < 1) {
            throw new Exception('No short clips were created for this source video.');
        }
        
        $this->log('video_completed', 'success', "Completed: {$videoTitle}", $videoId);
        
        return [
            'success' => true,
            'jobIds' => $jobIds,
            'downloaded' => 1,
            'processed' => $processedCount,
            'scheduled' => $scheduled,
            'posted' => $posted
        ];
    }
    
    /**
     * Download video from source (FTP or Bunny CDN)
     */
    private function downloadVideo($video) {
        $source = $this->automation['video_source'] ?? 'ftp';

        if ($source === 'manual_links') {
            $remoteUrl = is_array($video) ? ($video['manual_url'] ?? $video['remotePath'] ?? '') : (string)$video;
            if ($remoteUrl === '') {
                throw new Exception('Manual video URL is empty.');
            }
            $filename = is_array($video)
                ? ($video['filename'] ?? $this->buildManualFilename($remoteUrl, 1))
                : $this->buildManualFilename($remoteUrl, 1);
            $localPath = $this->tempDir . '/' . $filename;

            $this->log('download', 'info', "Downloading from manual link: {$filename}");
            $this->downloadManualVideoFromUrl($remoteUrl, $localPath);
            return $localPath;
        }

        if ($source === 'youtube_channel') {
            $youtube = new YouTubeSource($this->getYouTubeChannelUrl());
            $videoTitle = is_array($video) ? ($video['title'] ?? $video['filename'] ?? 'video') : 'video';
            $this->log('download', 'info', "Downloading from YouTube: {$videoTitle}");
            return $youtube->downloadVideo((array)$video, $this->tempDir);
        }
        
        if ($source === 'ftp') {
            // Download from FTP
            $remotePath = is_array($video) ? ($video['remotePath'] ?? $video['guid']) : $video;
            $filename = basename($remotePath);
            $localPath = $this->tempDir . '/' . $filename;
            
            $this->log('download', 'info', "Downloading from FTP: {$filename}");
            
            $ftp = FTPAPI::fromSettings($this->pdo);
            $ftp->downloadVideo($remotePath, $localPath);
            $ftp->disconnect();
            
            return $localPath;
        } else {
            // Download from Bunny CDN
            $videoId = is_array($video) ? $video['guid'] : $video;
            
            $bunny = new BunnyAPI(
                $this->automation['api_key'],
                $this->automation['library_id'],
                $this->automation['storage_zone'],
                $this->automation['cdn_hostname']
            );
            
            $localPath = $this->tempDir . '/' . $videoId . '.mp4';
            $result = $bunny->downloadVideo($videoId, $localPath);
            
            if (isset($result['error'])) {
                throw new Exception('Download failed: ' . $result['error']);
            }
            
            return $localPath;
        }
    }

    private function downloadManualVideoFromUrl(string $url, string $localPath): void {
        if (!function_exists('curl_init')) {
            throw new Exception('cURL extension is required for manual link downloads.');
        }

        $directory = dirname($localPath);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true)) {
            throw new Exception('Cannot create temp directory for manual link download.');
        }

        $fp = @fopen($localPath, 'wb');
        if (!$fp) {
            throw new Exception('Cannot open temp output file for manual link download.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_CONNECTTIMEOUT => 25,
            CURLOPT_TIMEOUT => 900,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/123.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR => false
        ]);

        $ok = curl_exec($ch);
        $error = $ok ? '' : curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        clearstatcache(true, $localPath);
        if (!$ok || $httpCode >= 400 || !file_exists($localPath) || filesize($localPath) <= 0) {
            @unlink($localPath);
            $statusMessage = $httpCode > 0 ? "HTTP {$httpCode}" : 'connection error';
            $errorMessage = $error !== '' ? $error : $statusMessage;
            throw new Exception('Manual download failed: ' . $errorMessage);
        }
    }
    
    /**
     * Transcribe video using Whisper
     */
    private function transcribeVideo($videoPath, $openaiKey, $language = 'en') {
        $whisper = new WhisperAPI($openaiKey);
        
        $this->log('whisper_start', 'info', 'Starting Whisper transcription');
        
        $transcription = $whisper->transcribe($videoPath, $language);
        
        if (isset($transcription['error'])) {
            $this->log('whisper_error', 'error', 'Whisper failed: ' . $transcription['error']);
            return null;
        }
        
        // Save as ASS file for FFmpeg overlay
        $assContent = $whisper->toASS($transcription, [
            'font' => 'Arial',
            'fontSize' => 32,
            'primaryColor' => '&H00FFFFFF',
            'outline' => 3
        ]);
        
        $subtitlesPath = $this->tempDir . '/' . uniqid('subs_') . '.ass';
        file_put_contents($subtitlesPath, $assContent);
        
        $this->log('whisper_complete', 'success', 'Transcription completed with ' . count($transcription['segments'] ?? []) . ' segments');
        
        return $subtitlesPath;
    }
    
    /**
     * Create short video with FFmpeg
     */
    private function createShort($inputPath, $videoId, $options) {
        $ffmpeg = new FFmpegProcessor();
        
        // Check FFmpeg availability with detailed error
        if (!$ffmpeg->isAvailable()) {
            $paths = $ffmpeg->getPaths();
            $this->log('ffmpeg_error', 'error', 'FFmpeg not found. Tried: ' . $paths['ffmpeg']);
            throw new Exception('FFmpeg not installed. Please install FFmpeg and add to PATH. See Settings → System tab.');
        }
        
        // Verify input file exists
        if (!file_exists($inputPath)) {
            throw new Exception("Input video not found: {$inputPath}");
        }
        
        $this->log('ffmpeg_start', 'info', 'Starting FFmpeg processing. Aspect: ' . $options['aspectRatio']);
        
        // Create safe filename (alphanumeric only)
        $safeId = preg_replace('/[^a-zA-Z0-9]/', '', $videoId);
        $clipIndex = max(1, (int)($options['clipIndex'] ?? 1));
        $clipTotal = max(1, (int)($options['clipTotal'] ?? 1));
        $clipSuffix = $clipTotal > 1 ? '_part' . str_pad((string)$clipIndex, 2, '0', STR_PAD_LEFT) : '';
        $uniqueSuffix = str_replace('.', '', (string)microtime(true));
        $outputPath = $this->outputDir . '/short_' . $safeId . $clipSuffix . '_' . $uniqueSuffix . '.mp4';
        
        // Find best segment to use when caller did not already plan a segment.
        $startTime = isset($options['startTime'])
            ? max(0, (int)$options['startTime'])
            : $ffmpeg->findBestSegment($inputPath, $options['duration']);
        $this->log('ffmpeg_segment', 'info', "Using segment starting at {$startTime}s");
        
        $result = $ffmpeg->createShort($inputPath, $outputPath, [
            'duration' => $options['duration'],
            'startTime' => $startTime,
            'playbackSpeed' => (float)($options['playbackSpeed'] ?? 1.0),
            'aspectRatio' => $options['aspectRatio'],
            'topText' => $options['topText'],
            'bottomText' => $options['bottomText'],
            'subtitlesPath' => $options['subtitlesPath'],
            'emoji' => $options['emoji'] ?? '',
            'emojiPng' => $options['emojiPng'] ?? null
        ]);
        
        if (!$result['success']) {
            $errorDetails = $result['error'] ?? 'Unknown error';
            if (isset($result['output'])) {
                $errorDetails .= "\n" . substr($result['output'], -500);
            }
            $this->log('ffmpeg_failed', 'error', $errorDetails);
            throw new Exception('FFmpeg processing failed: ' . $result['error']);
        }
        
        $fileSize = round(filesize($outputPath) / 1024 / 1024, 2);
        $this->log('short_created', 'success', "Short created: " . basename($outputPath) . " ({$fileSize} MB)");
        
        return $outputPath;
    }

    private function buildShortSegmentPlan($inputPath) {
        $ffmpeg = new FFmpegProcessor();
        $videoInfo = $ffmpeg->getVideoInfo($inputPath);
        $shortDuration = (int)($this->automation['short_duration'] ?? 60);
        $singleStartTime = $ffmpeg->findBestSegment($inputPath, $shortDuration);

        return ShortSegmentPlanner::buildPlan(
            (float)($videoInfo['duration'] ?? 0),
            $shortDuration,
            $this->automation['source_shorts_mode'] ?? 'single',
            $this->automation['source_shorts_max_count'] ?? 1,
            $singleStartTime
        );
    }
    
    /**
     * Create database job entry
     */
    private function createJob($title, $videoId, $brandingText) {
        $jobName = "Short: {$title}" . ($brandingText ? " - {$brandingText}" : '');

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO video_jobs (name, api_key_id, video_id, type, status, progress, completed_at)
                VALUES (?, ?, ?, 'process', 'completed', 100, NOW())
            ");
            $stmt->execute([
                $jobName,
                $this->automation['api_key_id'],
                $videoId
            ]);
        } catch (Exception $e) {
            // Backward-compatibility for older databases missing completed_at
            if (strpos($e->getMessage(), "Unknown column 'completed_at'") === false) {
                throw $e;
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO video_jobs (name, api_key_id, video_id, type, status, progress)
                VALUES (?, ?, ?, 'process', 'completed', 100)
            ");
            $stmt->execute([
                $jobName,
                $this->automation['api_key_id'],
                $videoId
            ]);
        }
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Post video to enabled social media platforms
     */
    private function postToSocialMedia($videoPath, $caption, $videoId) {
        $stats = ['scheduled' => 0, 'posted' => 0];

        // Debug: Log Post for Me settings
        $pfEnabled = $this->automation['postforme_enabled'] ?? 'not_set';
        $pfAccounts = $this->automation['postforme_account_ids'] ?? 'not_set';
        $this->log('social_debug', 'info', "Post for Me check: enabled={$pfEnabled}, accounts={$pfAccounts}", $videoId, 'debug');
        
        // Post for Me Integration (Unified API - Recommended)
        if (!empty($this->automation['postforme_enabled']) && !empty($this->automation['postforme_account_ids'])) {
            return $this->postViaPostForMe($videoPath, $caption, $videoId); // Post for Me handles all platforms
        } else {
            $this->log('social_skip', 'info', 'Post for Me not enabled or no accounts selected, skipping social posting', $videoId, 'debug');
        }
        
        // Legacy individual platform posting (only if Post for Me not enabled)
        
        // YouTube
        if ($this->automation['youtube_enabled'] && $this->automation['youtube_api_key']) {
            try {
                $result = SocialMediaUploader::uploadToYouTube($videoPath, $caption, $caption, [
                    'access_token' => $this->automation['youtube_api_key']
                ]);
                
                $status = $result['success'] ? 'success' : 'error';
                $message = $result['success'] ? 'Posted to YouTube: ' . ($result['url'] ?? '') : ($result['error'] ?? 'Failed');
                $this->log('post_youtube', $status, $message, $videoId, 'youtube');
                if (!empty($result['success'])) $stats['posted']++;
            } catch (Exception $e) {
                $this->log('post_youtube', 'error', 'YouTube error: ' . $e->getMessage(), $videoId, 'youtube');
            }
        }
        
        // TikTok
        if ($this->automation['tiktok_enabled'] && $this->automation['tiktok_access_token']) {
            try {
                $result = SocialMediaUploader::uploadToTikTok($videoPath, $caption, [
                    'access_token' => $this->automation['tiktok_access_token']
                ]);
                
                $status = $result['success'] ? 'success' : 'error';
                $this->log('post_tiktok', $status, $status === 'success' ? 'Posted to TikTok' : 'TikTok failed', $videoId, 'tiktok');
                if (!empty($result['success'])) $stats['posted']++;
            } catch (Exception $e) {
                $this->log('post_tiktok', 'error', 'TikTok error: ' . $e->getMessage(), $videoId, 'tiktok');
            }
        }
        
        // Instagram
        if ($this->automation['instagram_enabled'] && $this->automation['instagram_access_token']) {
            try {
                $result = SocialMediaUploader::uploadToInstagram($videoPath, $caption, [
                    'access_token' => $this->automation['instagram_access_token']
                ]);
                
                $status = $result['success'] ? 'success' : 'error';
                $this->log('post_instagram', $status, $status === 'success' ? 'Posted to Instagram' : 'Instagram failed', $videoId, 'instagram');
                if (!empty($result['success'])) $stats['posted']++;
            } catch (Exception $e) {
                $this->log('post_instagram', 'error', 'Instagram error: ' . $e->getMessage(), $videoId, 'instagram');
            }
        }
        
        // Facebook
        if ($this->automation['facebook_enabled'] && $this->automation['facebook_access_token']) {
            try {
                $result = SocialMediaUploader::uploadToFacebook($videoPath, $caption, [
                    'access_token' => $this->automation['facebook_access_token'],
                    'page_id' => $this->automation['facebook_page_id']
                ]);
                
                $status = $result['success'] ? 'success' : 'error';
                $this->log('post_facebook', $status, $status === 'success' ? 'Posted to Facebook' : 'Facebook failed', $videoId, 'facebook');
                if (!empty($result['success'])) $stats['posted']++;
            } catch (Exception $e) {
                $this->log('post_facebook', 'error', 'Facebook error: ' . $e->getMessage(), $videoId, 'facebook');
            }
        }

        return $stats;
    }
    
    /**
     * Post video using Post for Me unified API
     */
    private function postViaPostForMe($videoPath, $caption, $videoId) {
        $stats = ['scheduled' => 0, 'posted' => 0];
        $this->log('postforme_start', 'info', 'Starting Post for Me upload for: ' . basename($videoPath), $videoId, 'postforme');
        
        try {
            // Check if video file exists
            if (!filter_var($videoPath, FILTER_VALIDATE_URL) && !file_exists($videoPath)) {
                $this->log('postforme_error', 'error', 'Video file not found: ' . $videoPath, $videoId, 'postforme');
                return $stats;
            }
            
            // Get Post for Me API key from settings
            $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key'");
            $stmt->execute();
            $apiKey = $stmt->fetchColumn();
            
            if (!$apiKey) {
                $this->log('postforme_error', 'error', 'Post for Me API key not configured in Settings', $videoId, 'postforme');
                return $stats;
            }
            
            // Get selected account IDs
            $accountIds = json_decode($this->automation['postforme_account_ids'] ?? '[]', true);
            if (empty($accountIds)) {
                $this->log('postforme_error', 'error', 'No Post for Me accounts selected for this automation', $videoId, 'postforme');
                return $stats;
            }
            
            $this->log('postforme_info', 'info', 'Uploading to ' . count($accountIds) . ' account(s): ' . implode(', ', $accountIds), $videoId, 'postforme');
            
            // Initialize Post for Me API
            $postForMe = new PostForMeAPI($apiKey);
            
            // Compute scheduled_at based on automation scheduling settings
            $options = [];
            $scheduledAt = $this->computeScheduleDate();
            if ($scheduledAt) {
                $options['scheduled_at'] = $scheduledAt;
                $this->log('postforme_schedule', 'info', "Post scheduled for: {$scheduledAt}", $videoId, 'postforme');
            }
            
            // Post video to all selected accounts
            $result = $postForMe->postVideo($videoPath, $caption, $accountIds, $options);
            
            // Log detailed result for debugging
            $safeResult = $this->redactSensitiveData($result);
            $this->log('postforme_debug', 'info', 'API Response: ' . json_encode($safeResult), $videoId, 'postforme');
            
            if ($result['success']) {
                $postId = $result['post_id'] ?? 'unknown';
                $scheduleInfo = $scheduledAt ? " (scheduled: {$scheduledAt})" : ' (immediate)';
                $this->log('postforme_success', 'success', "Posted via Post for Me (ID: {$postId}) to " . count($accountIds) . " accounts" . $scheduleInfo, $videoId, 'postforme');
                if ($scheduledAt) {
                    $stats['scheduled']++;
                    $this->log('posting', 'success', "SCHEDULED! Post ID: {$postId} (scheduled: {$scheduledAt})", $videoId, 'postforme');
                } else {
                    $stats['posted']++;
                    $this->log('posting', 'success', "POSTED! Post ID: {$postId}", $videoId, 'postforme');
                }
                
                // Log the post to postforme_posts table
                $this->logPostForMePost($postId, $videoId, $caption, $accountIds, $scheduledAt);
                
                // Get and log individual results per platform (skip polling for scheduled posts)
                if (!empty($result['post_id']) && !$scheduledAt) {
                    $this->pollPostForMeResults($postForMe, $result['post_id'], $videoId);
                }
            } else {
                $error = $result['error'] ?? 'Unknown error';
                $rawResponse = isset($result['raw']) ? ' | Raw: ' . substr($result['raw'] ?? '', 0, 500) : '';
                $httpCode = isset($result['http_code']) ? ' | HTTP: ' . $result['http_code'] : '';
                $this->log('postforme_error', 'error', 'Post for Me failed: ' . $error . $httpCode . $rawResponse, $videoId, 'postforme');
            }
            
        } catch (Exception $e) {
            $this->log('postforme_error', 'error', 'Post for Me exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), $videoId, 'postforme');
        }

        return $stats;
    }
    
    /**
     * Compute the UTC scheduleDate for PostForMe API based on automation settings
     * Returns ISO 8601 UTC datetime string or null for immediate posting
     */
    private function computeScheduleDate() {
        $mode = $this->automation['postforme_schedule_mode'] ?? 'immediate';
        
        if ($mode === 'immediate') {
            return null;
        }
        
        if ($mode === 'scheduled') {
            // Use specific date/time set by user, convert from their timezone to UTC
            $datetime = $this->automation['postforme_schedule_datetime'] ?? null;
            if (empty($datetime)) {
                return null;
            }
            
            $timezone = $this->automation['postforme_schedule_timezone'] ?? 'UTC';
            
            try {
                $userTz = new DateTimeZone($timezone);
                $utcTz = new DateTimeZone('UTC');
                
                $dt = new DateTime($datetime, $userTz);
                $dt->setTimezone($utcTz);
                
                $now = new DateTime('now', $utcTz);
                if ($dt <= $now) {
                    return null;
                }
                
                $spreadMinutes = intval($this->automation['postforme_schedule_spread_minutes'] ?? 0);
                if ($spreadMinutes > 0) {
                    $postIndex = $this->getPostIndexInCurrentRun();
                    if ($postIndex > 0) {
                        $totalSpread = $postIndex * $spreadMinutes;
                        $dt->modify("+{$totalSpread} minutes");
                    }
                }
                
                return $dt->format('Y-m-d\TH:i:s\Z');
            } catch (Exception $e) {
                error_log("Schedule date computation failed: " . $e->getMessage());
                return null;
            }
        }
        
        if ($mode === 'offset') {
            // Delay from now by X minutes
            $offsetMinutes = intval($this->automation['postforme_schedule_offset_minutes'] ?? 0);
            if ($offsetMinutes <= 0) {
                return null;
            }
            
            // Add spread time for multiple videos in same run
            $spreadMinutes = intval($this->automation['postforme_schedule_spread_minutes'] ?? 0);
            if ($spreadMinutes > 0) {
                $postIndex = $this->getPostIndexInCurrentRun();
                $offsetMinutes += ($postIndex * $spreadMinutes);
            }
            
            try {
                $utcTz = new DateTimeZone('UTC');
                $dt = new DateTime('now', $utcTz);
                $dt->modify("+{$offsetMinutes} minutes");
                
                return $dt->format('Y-m-d\TH:i:s\Z');
            } catch (Exception $e) {
                error_log("Offset schedule computation failed: " . $e->getMessage());
                return null;
            }
        }
        
        return null;
    }
    
    /**
     * Track how many posts have been made in current automation run (for spread calculation)
     */
    private $postIndexCounter = 0;
    
    private function getPostIndexInCurrentRun() {
        return $this->postIndexCounter++;
    }
    
    /**
     * Log Post for Me post to database
     */
    private function logPostForMePost($postId, $videoId, $caption, $accountIds, $scheduledAt = null) {
        try {
            $status = $scheduledAt ? 'scheduled' : 'pending';

            // Normalize ISO/UTC schedule string into MySQL DATETIME.
            $dbScheduledAt = null;
            if (!empty($scheduledAt)) {
                $ts = strtotime((string)$scheduledAt);
                if ($ts !== false) {
                    $dbScheduledAt = gmdate('Y-m-d H:i:s', $ts);
                }
            }

            $accountsJson = json_encode($accountIds);
            $stmt = $this->pdo->prepare("
                INSERT INTO postforme_posts (post_id, automation_id, video_id, caption, account_ids, status, scheduled_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $postId,
                $this->automationId,
                $videoId,
                $caption,
                $accountsJson,
                $status,
                $dbScheduledAt
            ]);
        } catch (Exception $e) {
            // Backward compatibility for older installs that still use `video_path` instead of `video_id`.
            try {
                $status = $scheduledAt ? 'scheduled' : 'pending';
                $dbScheduledAt = null;
                if (!empty($scheduledAt)) {
                    $ts = strtotime((string)$scheduledAt);
                    if ($ts !== false) {
                        $dbScheduledAt = gmdate('Y-m-d H:i:s', $ts);
                    }
                }
                $accountsJson = json_encode($accountIds);
                $stmt = $this->pdo->prepare("
                    INSERT INTO postforme_posts (post_id, automation_id, video_path, caption, account_ids, status, scheduled_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $postId,
                    $this->automationId,
                    $videoId,
                    $caption,
                    $accountsJson,
                    $status,
                    $dbScheduledAt
                ]);
            } catch (Exception $e2) {
                error_log("Failed to log Post for Me post: " . $e2->getMessage());
            }
        }
    }
    
    /**
     * Poll Post for Me for posting results
     */
    private function pollPostForMeResults($postForMe, $postId, $videoId) {
        // Wait a bit for platforms to process
        sleep(5);
        
        try {
            $results = $postForMe->getPostResults($postId);
            
            if ($results['success'] && !empty($results['results'])) {
                foreach ($results['results'] as $result) {
                    $platform = $result['platform'] ?? 'unknown';
                    $status = $result['status'] ?? 'unknown';
                    $platformUrl = $result['url'] ?? '';
                    
                    if ($status === 'success' || $status === 'published') {
                        $this->log('post_' . $platform, 'success', "Posted to {$platform}" . ($platformUrl ? ": {$platformUrl}" : ''), $videoId, $platform);
                    } else {
                        $error = $result['error'] ?? $result['message'] ?? 'Unknown error';
                        $this->log('post_' . $platform, 'error', "{$platform} failed: {$error}", $videoId, $platform);
                    }
                }
                
                // Update post status in database
                $stmt = $this->pdo->prepare("UPDATE postforme_posts SET status = 'completed', results = ? WHERE post_id = ?");
                $stmt->execute([json_encode($results['results']), $postId]);
            }
        } catch (Exception $e) {
            error_log("Failed to poll Post for Me results: " . $e->getMessage());
        }
    }
    
    /**
     * Get OpenAI API key from settings table, config, or environment
     */
    private function getOpenAIKey() {
        // First check settings table
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'openai_api_key'");
            $stmt->execute();
            $result = $stmt->fetch();
            if ($result && !empty($result['setting_value'])) {
                return $result['setting_value'];
            }
        } catch (Exception $e) {
            // Settings table might not exist
        }
        
        // Check if defined in config
        if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) {
            return OPENAI_API_KEY;
        }
        
        // Check environment variable
        return getenv('OPENAI_API_KEY') ?: null;
    }
    
    /**
     * Get previously used taglines to avoid duplicates
     */
    private function getUsedTaglines() {
        $taglines = [];
        try {
            $stmt = $this->pdo->prepare("SELECT message FROM automation_logs WHERE automation_id = ? AND action = 'ai_tagline' AND status = 'success' ORDER BY created_at DESC LIMIT 20");
            $stmt->execute([$this->automationId]);
            while ($row = $stmt->fetch()) {
                $taglines[] = $row['message'];
            }
        } catch (Exception $e) {}
        return $taglines;
    }
    
    /**
     * Save used tagline to avoid future duplicates
     */
    private function saveUsedTagline($top, $bottom) {
        // This is logged automatically via the log() method
        // The getUsedTaglines() will read from automation_logs
    }
    
    /**
     * Log automation activity
     */
    private function log($action, $status, $message, $videoId = null, $platform = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO automation_logs (automation_id, action, status, message, video_id, platform)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$this->automationId, $action, $status, $message, $videoId, $platform]);
        } catch (Exception $e) {
            // Log to error_log if database fails
            error_log("AutomationRunner Log: [{$status}] {$action}: {$message}");
        }
        
        // Call callback if set
        if ($this->logCallback) {
            call_user_func($this->logCallback, $action, $status, $message);
        }
    }
    
    /**
     * Update last run timestamp
     */
    private function updateLastRun() {
        $nextRun = new DateTime();
        $scheduleEveryMinutes = max(1, (int)($this->automation['schedule_every_minutes'] ?? 10));
        
        switch ($this->automation['schedule_type']) {
            case 'minutes':
                $nextRun->modify('+' . $scheduleEveryMinutes . ' minutes');
                break;
            case 'hourly':
                $nextRun->modify('+1 hour');
                break;
            case 'weekly':
                $nextRun->modify('+1 week');
                break;
            default:
                $nextRun->modify('+1 day');
        }
        
        $stmt = $this->pdo->prepare("
            UPDATE automation_settings 
            SET last_run_at = NOW(), next_run_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$nextRun->format('Y-m-d H:i:s'), $this->automationId]);
    }
}
?>
