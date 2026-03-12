<?php

class ManualVideoDownloader
{
    public function download(string $url, string $localPath): string
    {
        $directory = dirname($localPath);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true)) {
            throw new Exception('Unable to create temp directory for manual link download.');
        }

        if ($this->shouldPreferExtractor($url)) {
            return $this->downloadWithYtDlp($url, $localPath);
        }

        $direct = $this->downloadDirect($url, $localPath);
        if ($direct['success'] && !$this->looksLikeHtmlResponse($direct['content_type'], $localPath)) {
            return $localPath;
        }

        if ($direct['success']) {
            $embeddedUrl = $this->extractEmbeddedMediaUrl($localPath);
            if ($embeddedUrl !== '') {
                @unlink($localPath);
                $embedded = $this->downloadDirect($embeddedUrl, $localPath);
                if ($embedded['success'] && !$this->looksLikeHtmlResponse($embedded['content_type'], $localPath)) {
                    return $localPath;
                }
                @unlink($localPath);
            }
        }

        @unlink($localPath);
        return $this->downloadWithYtDlp($url, $localPath, $direct['error']);
    }

    private function shouldPreferExtractor(string $url): bool
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        foreach ([
            'drive.google.com',
            'dropbox.com',
        ] as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private function downloadDirect(string $url, string $localPath): array
    {
        if (!function_exists('curl_init')) {
            throw new Exception('cURL extension is required for manual link downloads.');
        }

        $fp = @fopen($localPath, 'wb');
        if (!$fp) {
            throw new Exception('Unable to open temp output file for manual link download.');
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
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($fp);

        clearstatcache(true, $localPath);
        if (!$ok || $httpCode >= 400 || !is_file($localPath) || filesize($localPath) <= 0) {
            @unlink($localPath);
            $statusMessage = $httpCode > 0 ? "HTTP {$httpCode}" : 'connection error';
            $errorMessage = $error !== '' ? $error : $statusMessage;
            return [
                'success' => false,
                'content_type' => $contentType,
                'error' => 'Manual direct download failed: ' . $errorMessage,
            ];
        }

        return [
            'success' => true,
            'content_type' => $contentType,
            'error' => '',
        ];
    }

    private function looksLikeHtmlResponse(string $contentType, string $localPath): bool
    {
        $contentType = strtolower(trim($contentType));
        if ($contentType !== '' && (
            str_contains($contentType, 'text/html')
            || str_contains($contentType, 'text/plain')
            || str_contains($contentType, 'application/xhtml')
            || str_contains($contentType, 'application/json')
        )) {
            return true;
        }

        $head = @file_get_contents($localPath, false, null, 0, 512);
        if (!is_string($head) || $head === '') {
            return false;
        }

        $head = ltrim(strtolower($head));
        return str_starts_with($head, '<!doctype html')
            || str_starts_with($head, '<html')
            || str_starts_with($head, '<?xml');
    }

    private function extractEmbeddedMediaUrl(string $localPath): string
    {
        $html = @file_get_contents($localPath);
        if (!is_string($html) || $html === '') {
            return '';
        }

        $patterns = [
            '#https://video-downloads\.googleusercontent\.com/[^"\'\s<>]+#i',
            '#https://lh3\.googleusercontent\.com/[^"\'\s<>]+#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return html_entity_decode((string)$matches[0], ENT_QUOTES | ENT_HTML5);
            }
        }

        if (preg_match('/<meta[^>]+property=["\']og:video(?:secure_url)?["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            return html_entity_decode((string)$matches[1], ENT_QUOTES | ENT_HTML5);
        }

        return '';
    }

    private function downloadWithYtDlp(string $url, string $localPath, string $priorError = ''): string
    {
        $ytDlp = $this->resolveYtDlpPath();
        if ($ytDlp === null) {
            $suffix = $priorError !== '' ? ' ' . $priorError : '';
            throw new Exception('Manual download requires yt-dlp for share/page URLs, but yt-dlp is not available.' . $suffix);
        }

        $directory = dirname($localPath);
        $baseName = pathinfo($localPath, PATHINFO_FILENAME);
        if ($baseName === '') {
            $baseName = 'manual_' . time();
        }

        $template = $directory . DIRECTORY_SEPARATOR . $baseName . '.%(ext)s';
        $command = array_merge(
            [
                $ytDlp,
                '--no-playlist',
                '--no-warnings',
                '--ignore-errors',
                '--output',
                $template,
                '--merge-output-format',
                'mp4',
            ],
            $this->getCookiesArgs(),
            [$url]
        );

        $result = $this->runCommand($command, $directory);
        $matches = glob($directory . DIRECTORY_SEPARATOR . $baseName . '.*') ?: [];
        $matches = array_values(array_filter($matches, static function (string $path): bool {
            return is_file($path) && !str_ends_with(strtolower($path), '.part');
        }));

        if (empty($matches)) {
            $suffix = $priorError !== '' ? ' | ' . $priorError : '';
            throw new Exception('Manual extractor download failed: ' . $this->summarizeError((string)($result['stderr'] ?? '')) . $suffix);
        }

        usort($matches, static function (string $a, string $b): int {
            return filesize($b) <=> filesize($a);
        });

        return $matches[0];
    }

    private function getCookiesArgs(): array
    {
        $cookiesFile = trim((string)(getenv('VW_YTDLP_COOKIES_FILE') ?: ''));
        if ($cookiesFile === '' && defined('YTDLP_COOKIES_FILE')) {
            $cookiesFile = trim((string)YTDLP_COOKIES_FILE);
        }

        if ($this->isUsableFile($cookiesFile)) {
            return ['--cookies', $cookiesFile];
        }

        $projectCookies = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cookies.txt';
        if ($this->isUsableFile($projectCookies)) {
            return ['--cookies', $projectCookies];
        }

        return [];
    }

    private function isUsableFile(string $path): bool
    {
        return $path !== '' && is_file($path) && filesize($path) > 0;
    }

    private function resolveYtDlpPath(): ?string
    {
        $candidates = [
            trim((string)(getenv('VW_YTDLP_PATH') ?: '')),
            defined('YTDLP_PATH') ? trim((string)YTDLP_PATH) : '',
            'yt-dlp',
            DIRECTORY_SEPARATOR === '\\' ? 'C:\\VideoWorkflow\\bin\\yt-dlp.exe' : '',
            defined('BASE_DATA_DIR')
                ? rtrim((string)BASE_DATA_DIR, '/\\') . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'yt-dlp.exe' : 'yt-dlp')
                : '',
        ];

        foreach ($candidates as $candidate) {
            $resolved = $this->resolveCommandBinary($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function resolveCommandBinary(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        if (strpos($candidate, DIRECTORY_SEPARATOR) !== false || preg_match('/^[A-Za-z]:[\/\\\\]/', $candidate)) {
            return is_file($candidate) ? $candidate : null;
        }

        $finder = DIRECTORY_SEPARATOR === '\\' ? 'where.exe' : 'which';
        $result = $this->runCommand([$finder, $candidate], null, true);
        if (($result['exit_code'] ?? 1) !== 0) {
            return null;
        }

        foreach (preg_split("/\r\n|\n|\r/", (string)($result['stdout'] ?? '')) ?: [] as $line) {
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
            throw new Exception('proc_open is required to run yt-dlp.');
        }

        $runtimeTempDir = rtrim((string)(defined('TEMP_DIR') ? TEMP_DIR : sys_get_temp_dir()), '/\\')
            . DIRECTORY_SEPARATOR . 'yt-dlp-runtime';
        if (!is_dir($runtimeTempDir)) {
            @mkdir($runtimeTempDir, 0777, true);
        }

        $restoreTemp = getenv('TEMP');
        $restoreTmp = getenv('TMP');
        putenv('TEMP=' . $runtimeTempDir);
        putenv('TMP=' . $runtimeTempDir);

        try {
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = @proc_open($command, $descriptorSpec, $pipes, $cwd, null, [
                'suppress_errors' => true,
                'bypass_shell' => DIRECTORY_SEPARATOR === '\\',
            ]);

            if (!is_resource($process)) {
                throw new Exception('Unable to start yt-dlp process.');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $result = [
                'stdout' => (string)$stdout,
                'stderr' => (string)$stderr,
                'exit_code' => (int)$exitCode,
            ];

            if (!$allowFailure && $result['exit_code'] !== 0 && trim((string)$result['stdout']) === '') {
                throw new Exception($this->summarizeError((string)$result['stderr']));
            }

            return $result;
        } finally {
            if ($restoreTemp !== false) {
                putenv('TEMP=' . $restoreTemp);
            }
            if ($restoreTmp !== false) {
                putenv('TMP=' . $restoreTmp);
            }
        }
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

        return !empty($lines)
            ? implode(' | ', array_slice($lines, 0, 3))
            : $stderr;
    }
}
