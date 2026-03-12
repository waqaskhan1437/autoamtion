<?php

class YouTubeSource
{
    private string $channelUrl;
    private string $ytDlpPath;
    private ?string $ffmpegPath;
    private ?string $runtimeTempDir = null;

    public function __construct(string $channelUrl, ?string $ffmpegPath = null)
    {
        $channelUrl = $this->normalizeChannelUrl($channelUrl);
        if ($channelUrl === '') {
            throw new InvalidArgumentException('YouTube channel URL is required.');
        }

        $this->channelUrl = $channelUrl;
        $this->ffmpegPath = $this->resolveExistingBinary($ffmpegPath ?: (defined('FFMPEG_PATH') ? (string)FFMPEG_PATH : 'ffmpeg'));
        $this->runtimeTempDir = $this->resolvePreferredWritableDirectory([
            trim((string)(getenv('VW_YTDLP_TEMP_DIR') ?: '')),
            rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'video-workflow-runtime',
            (defined('TEMP_DIR') ? rtrim((string)TEMP_DIR, '/\\') . DIRECTORY_SEPARATOR . 'yt-dlp-runtime' : ''),
            (defined('BASE_DATA_DIR') ? rtrim((string)BASE_DATA_DIR, '/\\') . DIRECTORY_SEPARATOR . 'yt-dlp-runtime' : ''),
        ]);
        $this->ytDlpPath = $this->resolveYtDlpPath();
    }

    public function getChannelUrl(): string
    {
        return $this->channelUrl;
    }

    public function listVideos(?int $daysFilter = 30, ?string $startDate = null, ?string $endDate = null, ?int $resultLimit = null): array
    {
        [$startYmd, $endYmd] = $this->resolveDateWindow($daysFilter, $startDate, $endDate);
        $resultLimit = ($resultLimit !== null && $resultLimit > 0) ? $resultLimit : 25;
        $playlistEnd = min($this->estimatePlaylistEnd($daysFilter, $startDate, $endDate), max($resultLimit * 5, 25));
        $candidates = $this->getCandidateEntries($playlistEnd);

        $videos = [];
        $seen = [];
        $metadataTemplate = "%(id)s\t%(upload_date)s\t%(timestamp)s\t%(duration)s\t%(webpage_url)s\t%(live_status)s\t%(title)s";

        $metadataErrors = [];

        $metadataChunkSize = min(10, max(5, $resultLimit * 3));
        foreach (array_chunk($candidates, $metadataChunkSize) as $candidateChunk) {
            $urls = [];
            foreach ($candidateChunk as $candidate) {
                $url = trim((string)($candidate['url'] ?? ''));
                if ($url !== '') {
                    $urls[] = $url;
                }
            }

            if (empty($urls)) {
                continue;
            }

            $stopAfterRange = false;

            $command = array_merge(
                [
                    $this->ytDlpPath,
                    '--skip-download',
                    '--ignore-errors',
                    '--no-warnings',
                    '--no-playlist',
                    '--print',
                    $metadataTemplate,
                ],
                $this->getCookiesArgs(),
                $this->getYouTubeExtractorArgs(),
                $this->getJsRuntimeArgs(),
                $urls
            );

            $result = $this->runCommandWithCookiesFallback($command);

            if (!empty($result['stderr'])) {
                $stderrLines = preg_split("/\r\n|\n|\r/", $result['stderr']) ?: [];
                foreach ($stderrLines as $line) {
                    if ($this->isVideoUnavailableError($line) && preg_match('/\[youtube\]\s+([a-zA-Z0-9_-]+)/', $line, $matches)) {
                        error_log("[YouTubeSource] Skipped unavailable video: {$matches[1]}");
                    }
                }
            }

            if ($result['exit_code'] !== 0 && trim((string)$result['stdout']) === '') {
                $metadataErrors[] = $this->summarizeError($result['stderr']);
                continue;
            }

            foreach (preg_split("/\r\n|\n|\r/", (string)$result['stdout']) ?: [] as $line) {
                $parsed = $this->parseMetadataLine($line);
                if ($parsed === null) {
                    continue;
                }

                $uploadDate = $parsed['upload_date'];
                if ($uploadDate > $endYmd) {
                    continue;
                }
                if ($uploadDate < $startYmd) {
                    $stopAfterRange = true;
                    continue;
                }
                if ($parsed['live_status'] === 'is_live') {
                    continue;
                }
                if (isset($seen[$parsed['id']])) {
                    continue;
                }

                $seen[$parsed['id']] = true;
                $videos[] = $this->buildVideoEntry($parsed);
                if (count($videos) >= $resultLimit) {
                    return $videos;
                }
            }

            if ($stopAfterRange) {
                break;
            }
        }

        if (empty($videos) && !empty($metadataErrors)) {
            $metadataErrors = array_values(array_unique(array_filter($metadataErrors)));
            throw new RuntimeException('yt-dlp could not fetch video metadata: ' . implode(' | ', array_slice($metadataErrors, 0, 3)));
        }

        return $videos;
    }

    /**
     * Check if error is about video not being available
     */
    private function isVideoUnavailableError(string $error): bool
    {
        $unavailablePatterns = [
            'video is not available',
            'video unavailable',
            'private video',
            'removed by the uploader',
            'removed by user',
            'deleted video',
            'content unavailable',
            'sign in',
            'age-restricted',
            'region',
            'blocked',
            'copyright',
            'terminated',
        ];
        
        $errorLower = strtolower($error);
        foreach ($unavailablePatterns as $pattern) {
            if (strpos($errorLower, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
    
    public function downloadVideo(array $video, string $targetDirectory): string
    {
        $videoUrl = trim((string)($video['remotePath'] ?? $video['webpage_url'] ?? ''));
        if ($videoUrl === '') {
            throw new InvalidArgumentException('YouTube video URL is missing.');
        }

        $filename = trim((string)($video['filename'] ?? ''));
        if ($filename === '') {
            $filename = $this->buildFilename(
                (string)($video['upload_date'] ?? ''),
                (string)($video['video_id'] ?? ''),
                (string)($video['title'] ?? '')
            );
        }

        $dir = rtrim($targetDirectory, '/\\');
        if ($dir === '') {
            throw new InvalidArgumentException('Target directory is required for YouTube downloads.');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new RuntimeException('Unable to create directory for YouTube downloads: ' . $dir);
        }

        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        if ($baseName === '') {
            $baseName = 'youtube_' . date('Ymd_His');
        }

        $outputTemplate = $dir . DIRECTORY_SEPARATOR . $baseName . '.%(ext)s';
        $format = 'b[ext=mp4]/best[ext=mp4]/best';

        $command = array_merge(
            [
                $this->ytDlpPath,
                '--no-playlist',
                '--ignore-errors',
                '--no-warnings',
                '--format',
                $format,
                '--output',
                $outputTemplate,
            ],
            $this->getCookiesArgs(),
            $this->getYouTubeExtractorArgs(),
            $this->getJsRuntimeArgs(),
            $this->ffmpegPath !== null ? ['--ffmpeg-location', $this->ffmpegPath] : [],
            [$videoUrl]
        );

        $result = $this->runCommandWithCookiesFallback($command, $dir);
        if ($result['exit_code'] !== 0) {
            $errorMessage = $this->summarizeError($result['stderr']);
            
            // Check if video is unavailable (private, deleted, region-restricted, etc.)
            if ($this->isVideoUnavailableError($errorMessage)) {
                throw new RuntimeException('VIDEO_UNAVAILABLE: ' . $errorMessage);
            }
            
            throw new RuntimeException('yt-dlp download failed: ' . $errorMessage);
        }

        $matches = glob($dir . DIRECTORY_SEPARATOR . $baseName . '.*') ?: [];
        if (empty($matches)) {
            throw new RuntimeException('yt-dlp finished but no output file was created.');
        }

        usort($matches, static function (string $a, string $b): int {
            return filesize($b) <=> filesize($a);
        });

        return $matches[0];
    }

    private function resolveDateWindow(?int $daysFilter, ?string $startDate, ?string $endDate): array
    {
        $startDate = trim((string)$startDate);
        $endDate = trim((string)$endDate);

        if ($startDate !== '' && $endDate !== '') {
            $startTs = strtotime($startDate);
            $endTs = strtotime($endDate);
            if ($startTs !== false && $endTs !== false && $startTs <= $endTs) {
                return [date('Ymd', $startTs), date('Ymd', $endTs)];
            }
        }

        $days = (int)$daysFilter;
        if ($days < 1) {
            $days = 30;
        }

        $today = strtotime('today');
        $startTs = strtotime('-' . $days . ' days', $today);

        return [date('Ymd', $startTs), date('Ymd', $today)];
    }

    private function estimatePlaylistEnd(?int $daysFilter, ?string $startDate, ?string $endDate): int
    {
        $days = 0;

        $startDate = trim((string)$startDate);
        $endDate = trim((string)$endDate);
        if ($startDate !== '' && $endDate !== '') {
            $startTs = strtotime($startDate);
            $endTs = strtotime($endDate);
            if ($startTs !== false && $endTs !== false && $startTs <= $endTs) {
                $days = (int)floor(($endTs - $startTs) / 86400) + 1;
            }
        }

        if ($days < 1) {
            $days = max(1, (int)$daysFilter);
        }

        $limit = $days * 250;
        if ($limit < 200) {
            $limit = 200;
        }
        if ($limit > 5000) {
            $limit = 5000;
        }

        return $limit;
    }

    private function normalizeChannelUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (strpos($url, '@') === 0) {
            $url = 'https://www.youtube.com/' . ltrim($url, '/');
        }

        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }

        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = trim((string)($parts['path'] ?? ''));

        if ($host === '' || strpos($host, 'youtube.com') === false) {
            return $url;
        }

        $trimmedPath = trim($path, '/');
        if ($trimmedPath !== '' && !preg_match('#/(videos|shorts|streams|live)$#i', '/' . $trimmedPath)) {
            $url = rtrim($url, '/') . '/videos';
        }

        return $url;
    }

    private function buildFilename(string $uploadDate, string $videoId, string $title): string
    {
        $prefix = preg_match('/^\d{8}$/', $uploadDate)
            ? $uploadDate
            : date('Ymd');

        $idPart = $this->sanitizeFilenameSegment($videoId !== '' ? $videoId : substr(sha1($title), 0, 10), 32);
        $titlePart = $this->sanitizeFilenameSegment($title, 80);

        return $prefix . '_' . $idPart . ($titlePart !== '' ? '_' . $titlePart : '') . '.mp4';
    }

    private function sanitizeFilenameSegment(string $value, int $maxLength = 80): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value)) ?? '';
        $value = trim($value, '._-');
        if ($value === '') {
            return 'video';
        }
        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        return rtrim($value, '._-');
    }

    private function formatUploadDate(string $uploadDate): string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $uploadDate, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3] . ' 00:00:00';
        }

        return date('Y-m-d H:i:s');
    }

    private function getCandidateEntries(int $playlistEnd): array
    {
        $template = "%(id)s\t%(url)s\t%(title)s";
        $command = array_merge(
            [
                $this->ytDlpPath,
                '--flat-playlist',
                '--lazy-playlist',
                '--ignore-errors',
                '--no-warnings',
                '--print',
                $template,
                '--playlist-end',
                (string)$playlistEnd,
            ],
            $this->getCookiesArgs(),
            $this->getYouTubeExtractorArgs(),
            $this->getJsRuntimeArgs(),
            [$this->channelUrl]
        );

        $result = $this->runCommandWithCookiesFallback($command);
        if ($result['exit_code'] !== 0 && trim($result['stdout']) === '') {
            throw new RuntimeException('yt-dlp could not list channel videos: ' . $this->summarizeError($result['stderr']));
        }

        $entries = [];
        foreach (preg_split("/\r\n|\n|\r/", (string)$result['stdout']) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 3);
            if (count($parts) < 2) {
                continue;
            }

            $id = trim((string)$parts[0]);
            $url = trim((string)$parts[1]);
            $title = trim((string)($parts[2] ?? ''));
            if ($id === '') {
                continue;
            }
            if ($url === '' || stripos($url, 'http') !== 0) {
                $url = 'https://www.youtube.com/watch?v=' . rawurlencode($id);
            }

            $entries[] = [
                'id' => $id,
                'url' => $url,
                'title' => $title,
            ];
        }

        return $entries;
    }

    private function parseMetadataLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $parts = explode("\t", $line, 7);
        if (count($parts) < 7) {
            return null;
        }

        $id = trim((string)$parts[0]);
        $uploadDate = trim((string)$parts[1]);
        $url = trim((string)$parts[4]);
        if ($id === '' || $uploadDate === '' || $url === '') {
            return null;
        }

        return [
            'id' => $id,
            'upload_date' => $uploadDate,
            'timestamp' => trim((string)$parts[2]),
            'duration' => trim((string)$parts[3]),
            'url' => $url,
            'live_status' => strtolower(trim((string)$parts[5])),
            'title' => trim((string)$parts[6]),
        ];
    }

    private function buildVideoEntry(array $parsed): array
    {
        $uploadedAt = ctype_digit((string)$parsed['timestamp']) && (int)$parsed['timestamp'] > 0
            ? gmdate('Y-m-d H:i:s', (int)$parsed['timestamp'])
            : $this->formatUploadDate((string)$parsed['upload_date']);

        $title = trim((string)($parsed['title'] ?? ''));
        $id = (string)($parsed['id'] ?? '');
        $url = (string)($parsed['url'] ?? '');

        return [
            'guid' => 'yt:' . $id,
            'video_id' => $id,
            'title' => $title !== '' ? $title : ('YouTube Video ' . $id),
            'filename' => $this->buildFilename((string)$parsed['upload_date'], $id, $title),
            'remotePath' => $url,
            'webpage_url' => $url,
            'dateUploaded' => $uploadedAt,
            'DateCreated' => $uploadedAt,
            'dateCreated' => $uploadedAt,
            'upload_date' => (string)$parsed['upload_date'],
            'uploaded_at' => $uploadedAt,
            'duration' => is_numeric($parsed['duration']) ? (int)round((float)$parsed['duration']) : 0,
            'size' => 0,
            'Length' => 0,
            'source' => 'youtube_channel',
            'live_status' => (string)($parsed['live_status'] ?? ''),
        ];
    }

    private function resolveYtDlpPath(): string
    {
        $envPath = trim((string)(getenv('VW_YTDLP_PATH') ?: (defined('YTDLP_PATH') ? (string)YTDLP_PATH : '')));
        if ($envPath !== '') {
            $resolved = $this->resolveExistingBinary($envPath);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        foreach ($this->getDefaultYtDlpCandidates() as $candidate) {
            $resolved = $this->resolveExistingBinary($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->downloadYtDlpBinary();
    }

    private function getDefaultYtDlpCandidates(): array
    {
        $candidates = ['yt-dlp'];
        $filename = $this->isWindows() ? 'yt-dlp.exe' : 'yt-dlp';

        $preferredBinDir = $this->resolvePreferredWritableDirectory([
            trim((string)(getenv('VW_YTDLP_BIN_DIR') ?: '')),
            rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'video-workflow-bin',
            (defined('TEMP_DIR') ? rtrim((string)TEMP_DIR, '/\\') . DIRECTORY_SEPARATOR . 'bin' : ''),
            (defined('BASE_DATA_DIR') ? rtrim((string)BASE_DATA_DIR, '/\\') . DIRECTORY_SEPARATOR . 'bin' : ''),
        ]);

        if ($preferredBinDir !== null) {
            $candidates[] = $preferredBinDir . DIRECTORY_SEPARATOR . $filename;
        }

        return $candidates;
    }

    private function downloadYtDlpBinary(): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('yt-dlp is missing and cURL is not available to download it.');
        }

        $binDir = $this->resolvePreferredWritableDirectory([
            trim((string)(getenv('VW_YTDLP_BIN_DIR') ?: '')),
            rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'video-workflow-bin',
            (defined('TEMP_DIR') ? rtrim((string)TEMP_DIR, '/\\') . DIRECTORY_SEPARATOR . 'bin' : ''),
            (defined('BASE_DATA_DIR') ? rtrim((string)BASE_DATA_DIR, '/\\') . DIRECTORY_SEPARATOR . 'bin' : ''),
        ]);

        if ($binDir === null) {
            throw new RuntimeException('Unable to create a writable yt-dlp cache directory.');
        }

        $filename = $this->isWindows() ? 'yt-dlp.exe' : 'yt-dlp';
        $target = $binDir . DIRECTORY_SEPARATOR . $filename;
        $downloadUrl = $this->isWindows()
            ? 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp.exe'
            : 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp';

        $fp = @fopen($target, 'wb');
        if (!$fp) {
            throw new RuntimeException('Unable to open yt-dlp target file for writing: ' . $target);
        }

        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_USERAGENT => 'VideoWorkflow/1.0',
            CURLOPT_FAILONERROR => false,
        ]);

        $ok = curl_exec($ch);
        $error = $ok ? '' : curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        clearstatcache(true, $target);
        if (!$ok || $status >= 400 || !is_file($target) || filesize($target) < 1024) {
            @unlink($target);
            $detail = $error !== '' ? $error : ('HTTP ' . $status);
            throw new RuntimeException('Failed to download yt-dlp: ' . $detail);
        }

        if (!$this->isWindows()) {
            @chmod($target, 0755);
        }

        return $target;
    }

    private function getJsRuntimeArgs(): array
    {
        $nodePath = $this->resolveExistingBinary('node');
        if ($nodePath !== null) {
            return ['--js-runtimes', 'node'];
        }

        return [];
    }

    private function getCookiesArgs(): array
    {
        foreach ($this->getCookieArgSets() as $argSet) {
            if ($argSet !== []) {
                return $argSet;
            }
        }

        return [];
    }

    private function getCookieArgSets(): array
    {
        $sets = [];

        $cookiesFile = trim((string)(getenv('VW_YTDLP_COOKIES_FILE') ?: ''));
        if ($cookiesFile === '' && defined('YTDLP_COOKIES_FILE')) {
            $cookiesFile = trim((string)YTDLP_COOKIES_FILE);
        }

        if ($this->isUsableCookiesFile($cookiesFile)) {
            $sets[] = ['--cookies', $cookiesFile];
        }

        $browser = trim((string)(getenv('VW_YTDLP_COOKIES_BROWSER') ?: ''));
        if ($browser === '' && defined('YTDLP_COOKIES_BROWSER')) {
            $browser = trim((string)YTDLP_COOKIES_BROWSER);
        }

        $profile = trim((string)(getenv('VW_YTDLP_COOKIES_BROWSER_PROFILE') ?: ''));
        if ($profile === '' && defined('YTDLP_COOKIES_BROWSER_PROFILE')) {
            $profile = trim((string)YTDLP_COOKIES_BROWSER_PROFILE);
        }

        if ($browser !== '') {
            $browserSpec = $browser;
            if ($profile !== '' && strpos($browserSpec, ':') === false) {
                $browserSpec .= ':' . $profile;
            }
            $sets[] = ['--cookies-from-browser', $browserSpec];
        }

        $projectCookiesFile = $this->resolveProjectCookiesFile();
        if ($projectCookiesFile !== '') {
            $sets[] = ['--cookies', $projectCookiesFile];
        }

        foreach ($this->getAutomaticBrowserCookieArgSets() as $argSet) {
            $sets[] = $argSet;
        }

        $sets[] = [];

        $unique = [];
        $seen = [];
        foreach ($sets as $argSet) {
            $signature = $this->buildCookieArgSetSignature($argSet);
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $unique[] = $argSet;
        }

        return $unique;
    }

    private function getAutomaticBrowserCookieArgSets(): array
    {
        if (!$this->isWindows()) {
            return [];
        }

        return [
            ['--cookies-from-browser', 'chrome:Default'],
            ['--cookies-from-browser', 'chrome'],
            ['--cookies-from-browser', 'edge:Default'],
            ['--cookies-from-browser', 'edge'],
        ];
    }

    private function resolveProjectCookiesFile(): string
    {
        $candidate = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cookies.txt';
        return $this->isUsableCookiesFile($candidate) ? $candidate : '';
    }

    private function isUsableCookiesFile(?string $path): bool
    {
        $path = trim((string)$path);
        return $path !== '' && is_file($path) && filesize($path) > 0;
    }

    private function getYouTubeExtractorArgs(): array
    {
        return [
            '--extractor-args',
            'youtube:player-client=tv,mweb;player-skip=webpage,configs;formats=incomplete',
            '--extractor-args',
            'youtube:skip=hls,dash',
        ];
    }

    private function runCommandWithCookiesFallback(array $command, ?string $workingDirectory = null): array
    {
        $result = $this->runCommand($command, $workingDirectory, true);
        if (!$this->shouldRetryWithoutCookies($result)) {
            return $result;
        }

        $tried = [];
        $currentCookiesArgs = $this->getCommandCookiesArgs($command);
        $tried[$this->buildCookieArgSetSignature($currentCookiesArgs)] = true;

        foreach ($this->getCookieArgSets() as $cookieArgs) {
            $signature = $this->buildCookieArgSetSignature($cookieArgs);
            if (isset($tried[$signature])) {
                continue;
            }
            $tried[$signature] = true;

            $retryCommand = $this->replaceCookiesArgs($command, $cookieArgs);
            if ($retryCommand === $command) {
                continue;
            }

            $retry = $this->runCommand($retryCommand, $workingDirectory, true);
            if ($this->isUsableYtDlpResult($retry)) {
                return $retry;
            }
        }

        return $result;
    }

    private function shouldRetryWithoutCookies(array $result): bool
    {
        if (count($this->getCookieArgSets()) < 2) {
            return false;
        }

        $stderr = strtolower(trim((string)($result['stderr'] ?? '')));
        if ($stderr === '') {
            return false;
        }

        foreach ([
            'the page needs to be reloaded',
            'sign in to confirm you\'re not a bot',
            'use --cookies',
        ] as $needle) {
            if (strpos($stderr, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function stripCookiesArgs(array $command): array
    {
        $clean = [];
        $skipNext = false;

        foreach ($command as $part) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            if ($part === '--cookies' || $part === '--cookies-from-browser') {
                $skipNext = true;
                continue;
            }

            $clean[] = $part;
        }

        return $clean;
    }

    private function getCommandCookiesArgs(array $command): array
    {
        $count = count($command);
        for ($i = 0; $i < $count - 1; $i++) {
            $part = (string)$command[$i];
            if ($part !== '--cookies' && $part !== '--cookies-from-browser') {
                continue;
            }

            return [$part, (string)$command[$i + 1]];
        }

        return [];
    }

    private function replaceCookiesArgs(array $command, array $cookieArgs): array
    {
        $clean = $this->stripCookiesArgs($command);
        if ($cookieArgs === []) {
            return $clean;
        }

        $insertAt = count($clean);
        foreach ($clean as $index => $part) {
            if ($this->isLikelyUrlArgument((string)$part)) {
                $insertAt = $index;
                break;
            }
        }

        array_splice($clean, $insertAt, 0, $cookieArgs);
        return $clean;
    }

    private function isLikelyUrlArgument(string $value): bool
    {
        return preg_match('#^https?://#i', $value) === 1;
    }

    private function buildCookieArgSetSignature(array $cookieArgs): string
    {
        return implode("\x1F", $cookieArgs);
    }

    private function isUsableYtDlpResult(array $result): bool
    {
        if ((int)($result['exit_code'] ?? 1) === 0) {
            return true;
        }

        return trim((string)($result['stdout'] ?? '')) !== '';
    }

    private function resolveExistingBinary(?string $candidate): ?string
    {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            return null;
        }

        if (strpos($candidate, DIRECTORY_SEPARATOR) !== false || preg_match('/^[A-Za-z]:[\/\\\\]/', $candidate)) {
            return is_file($candidate) ? $candidate : null;
        }

        $finder = $this->isWindows() ? 'where.exe' : 'which';
        $result = $this->runCommand([$finder, $candidate], null, true);
        if ($result['exit_code'] !== 0) {
            return null;
        }

        foreach (preg_split("/\r\n|\n|\r/", (string)$result['stdout']) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }

    private function runCommand(array $command, ?string $cwd = null, bool $allowFailure = false): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open is required to use yt-dlp.');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $result = $this->runProcCommand($command, $descriptorSpec, $cwd, [
            'suppress_errors' => true,
            'bypass_shell' => $this->isWindows(),
        ]);

        if ($result === null && $this->isWindows()) {
            $result = $this->runProcCommand($this->buildWindowsCommandString($command), $descriptorSpec, $cwd, [
                'suppress_errors' => true,
                'bypass_shell' => true,
            ]);
        }

        if ($result === null) {
            $commandLabel = (string)($command[0] ?? 'command');
            throw new RuntimeException('Unable to start external command: ' . basename(str_replace('\\', '/', $commandLabel)));
        }

        if (!$allowFailure && $result['exit_code'] !== 0 && trim((string)$result['stdout']) === '') {
            throw new RuntimeException($this->summarizeError((string)$result['stderr']));
        }

        return $result;
    }

    private function summarizeError(string $stderr): string
    {
        $stderr = trim($stderr);
        if ($stderr === '') {
            return 'Unknown yt-dlp error.';
        }

        $lines = preg_split("/\r\n|\n|\r/", $stderr) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static function (string $line): bool {
            return $line !== '' && stripos($line, 'WARNING:') !== 0;
        }));

        if (!empty($lines)) {
            return implode(' | ', array_slice($lines, 0, 3));
        }

        return $stderr;
    }

    private function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    private function resolvePreferredWritableDirectory(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }

            $candidate = rtrim($candidate, '/\\');
            if (!$this->ensureDirectoryWritable($candidate)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private function ensureDirectoryWritable(string $path): bool
    {
        if (!is_dir($path) && !@mkdir($path, 0777, true)) {
            return false;
        }

        if (!is_dir($path)) {
            return false;
        }

        $probe = $path . DIRECTORY_SEPARATOR . '.write-test-' . bin2hex(random_bytes(4));
        $ok = @file_put_contents($probe, 'ok') !== false;
        if ($ok) {
            @unlink($probe);
        }

        return $ok;
    }

    private function buildWindowsCommandString(array $command): string
    {
        $parts = [];
        foreach ($command as $argument) {
            $parts[] = $this->quoteWindowsArgument((string)$argument);
        }

        return implode(' ', $parts);
    }

    private function quoteWindowsArgument(string $argument): string
    {
        if ($argument === '') {
            return '""';
        }

        if (!preg_match('/[\s"]/u', $argument)) {
            return $argument;
        }

        $escaped = preg_replace('/(\\\\*)"/', '$1$1\\"', $argument) ?? $argument;
        $escaped = preg_replace('/(\\\\+)$/', '$1$1', $escaped) ?? $escaped;
        return '"' . $escaped . '"';
    }

    private function runProcCommand($command, array $descriptorSpec, ?string $cwd, array $options): ?array
    {
        $restoreTemp = null;
        $restoreTmp = null;

        if ($this->runtimeTempDir !== null && $this->commandNeedsWritableTemp($command)) {
            $restoreTemp = getenv('TEMP');
            $restoreTmp = getenv('TMP');
            putenv('TEMP=' . $this->runtimeTempDir);
            putenv('TMP=' . $this->runtimeTempDir);
        }

        try {
            $pipes = [];
            $process = @proc_open($command, $descriptorSpec, $pipes, $cwd, null, $options);
            if (!is_resource($process)) {
                return null;
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            return [
                'stdout' => (string)$stdout,
                'stderr' => (string)$stderr,
                'exit_code' => (int)$exitCode,
            ];
        } finally {
            if ($restoreTemp !== null) {
                putenv('TEMP=' . $restoreTemp);
            }
            if ($restoreTmp !== null) {
                putenv('TMP=' . $restoreTmp);
            }
        }
    }

    private function commandNeedsWritableTemp($command): bool
    {
        if (is_array($command)) {
            if (empty($command)) {
                return false;
            }

            $first = strtolower(basename(str_replace('\\', '/', (string)$command[0])));
            return str_starts_with($first, 'yt-dlp');
        }

        if (is_string($command) && $command !== '') {
            return stripos($command, 'yt-dlp') !== false;
        }

        return false;
    }
}
