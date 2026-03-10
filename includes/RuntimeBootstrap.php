<?php

class RuntimeBootstrap
{
    private ?PDO $pdo;
    private string $baseDir;
    private string $runtimeDir;
    private string $binDir;
    private string $downloadsDir;
    private string $extractDir;
    private string $lockFile;
    private ?array $settingsCache = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo instanceof PDO ? $pdo : ((isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) ? $GLOBALS['pdo'] : null);

        if (defined('BASE_DATA_DIR') && trim((string)BASE_DATA_DIR) !== '') {
            $this->baseDir = rtrim((string)BASE_DATA_DIR, '/\\');
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $this->baseDir = 'C:/VideoWorkflow';
        } else {
            $home = trim((string)(getenv('HOME') ?: sys_get_temp_dir()));
            $this->baseDir = rtrim($home, '/\\') . '/VideoWorkflow';
        }

        $this->runtimeDir = $this->baseDir . DIRECTORY_SEPARATOR . 'runtime';
        $this->binDir = $this->runtimeDir . DIRECTORY_SEPARATOR . 'bin';
        $this->downloadsDir = $this->runtimeDir . DIRECTORY_SEPARATOR . 'downloads';
        $this->extractDir = $this->runtimeDir . DIRECTORY_SEPARATOR . 'extract';
        $this->lockFile = $this->runtimeDir . DIRECTORY_SEPARATOR . 'ffmpeg-install.lock';

        $this->ensureDirectory($this->runtimeDir);
        $this->ensureDirectory($this->binDir);
        $this->ensureDirectory($this->downloadsDir);
    }

    public function getStatus(): array
    {
        $paths = $this->discoverFFmpegPaths();
        $ffmpegOk = $this->testBinary($paths['ffmpeg'] ?? null);
        $ffprobeOk = $this->testBinary($paths['ffprobe'] ?? null);

        return [
            'available' => $ffmpegOk && $ffprobeOk,
            'ffmpeg_path' => $paths['ffmpeg'] ?? '',
            'ffprobe_path' => $paths['ffprobe'] ?? '',
            'auto_install_enabled' => $this->isAutoInstallEnabled(),
            'can_auto_install' => $this->canAutoInstall(),
            'download_url' => $this->getDownloadUrl(),
            'runtime_dir' => $this->runtimeDir,
            'bin_dir' => $this->binDir,
        ];
    }

    public function discoverFFmpegPaths(): array
    {
        $ffmpeg = $this->resolveBinary('ffmpeg', 'ffmpeg_path');
        $ffprobe = $this->resolveBinary('ffprobe', 'ffprobe_path', $ffmpeg);

        return [
            'ffmpeg' => $ffmpeg,
            'ffprobe' => $ffprobe,
        ];
    }

    public function ensureFFmpegAvailable(bool $autoInstall = true): array
    {
        $status = $this->getStatus();
        if ($status['available']) {
            return [
                'success' => true,
                'installed' => false,
                'message' => 'FFmpeg runtime is ready.',
                'ffmpeg_path' => $status['ffmpeg_path'],
                'ffprobe_path' => $status['ffprobe_path'],
            ];
        }

        if (!$autoInstall || !$status['auto_install_enabled']) {
            return [
                'success' => false,
                'error' => 'FFmpeg is missing and automatic local runtime install is disabled.',
                'ffmpeg_path' => $status['ffmpeg_path'],
                'ffprobe_path' => $status['ffprobe_path'],
            ];
        }

        if (!$status['can_auto_install']) {
            return [
                'success' => false,
                'error' => 'Automatic FFmpeg install is only supported on Windows and Linux.',
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'error' => 'cURL extension is required to download FFmpeg automatically.',
            ];
        }

        $lockHandle = @fopen($this->lockFile, 'c+');
        if ($lockHandle === false) {
            return [
                'success' => false,
                'error' => 'Unable to create FFmpeg install lock file.',
            ];
        }

        try {
            if (!@flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock FFmpeg installer.');
            }

            $status = $this->getStatus();
            if ($status['available']) {
                return [
                    'success' => true,
                    'installed' => false,
                    'message' => 'FFmpeg runtime is ready.',
                    'ffmpeg_path' => $status['ffmpeg_path'],
                    'ffprobe_path' => $status['ffprobe_path'],
                ];
            }

            $downloadUrl = $this->getDownloadUrl();
            if ($downloadUrl === '') {
                throw new RuntimeException('No FFmpeg download URL is configured.');
            }

            $archivePath = $this->downloadsDir . DIRECTORY_SEPARATOR . $this->getArchiveFileName($downloadUrl);
            $this->downloadFile($downloadUrl, $archivePath);

            $this->wipeDirectory($this->extractDir);
            $this->ensureDirectory($this->extractDir);
            $this->extractPackage($archivePath, $this->extractDir);

            $ffmpegSource = $this->findBinaryInDirectory($this->extractDir, $this->binaryName('ffmpeg'));
            $ffprobeSource = $this->findBinaryInDirectory($this->extractDir, $this->binaryName('ffprobe'));

            if ($ffmpegSource === null || $ffprobeSource === null) {
                throw new RuntimeException('Downloaded package did not contain ffmpeg and ffprobe binaries.');
            }

            $ffmpegTarget = $this->binDir . DIRECTORY_SEPARATOR . $this->binaryName('ffmpeg');
            $ffprobeTarget = $this->binDir . DIRECTORY_SEPARATOR . $this->binaryName('ffprobe');

            $this->copyBinary($ffmpegSource, $ffmpegTarget);
            $this->copyBinary($ffprobeSource, $ffprobeTarget);

            $this->persistSetting('ffmpeg_path', $ffmpegTarget);
            $this->persistSetting('ffprobe_path', $ffprobeTarget);

            if (!$this->testBinary($ffmpegTarget) || !$this->testBinary($ffprobeTarget)) {
                throw new RuntimeException('FFmpeg downloaded, but the installed binaries did not start correctly.');
            }

            return [
                'success' => true,
                'installed' => true,
                'message' => 'FFmpeg runtime installed locally.',
                'ffmpeg_path' => $ffmpegTarget,
                'ffprobe_path' => $ffprobeTarget,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    private function resolveBinary(string $binary, string $settingKey, ?string $pairedPath = null): ?string
    {
        $binaryFile = $this->binaryName($binary);
        $candidates = [];

        $storedPath = trim($this->getSetting($settingKey));
        if ($storedPath !== '') {
            $candidates[] = $storedPath;
        }

        if ($pairedPath !== null && $pairedPath !== '') {
            $candidates[] = dirname($pairedPath) . DIRECTORY_SEPARATOR . $binaryFile;
        }

        if ($binary === 'ffprobe') {
            $storedFfmpeg = trim($this->getSetting('ffmpeg_path'));
            if ($storedFfmpeg !== '') {
                $candidates[] = dirname($storedFfmpeg) . DIRECTORY_SEPARATOR . $binaryFile;
            }
        }

        $candidates[] = $this->binDir . DIRECTORY_SEPARATOR . $binaryFile;

        if ($binary === 'ffmpeg' && defined('FFMPEG_PATH')) {
            $candidates[] = (string)FFMPEG_PATH;
        }
        if ($binary === 'ffprobe' && defined('FFPROBE_PATH')) {
            $candidates[] = (string)FFPROBE_PATH;
        }

        foreach ($this->commonBinaryCandidates($binaryFile) as $candidate) {
            $candidates[] = $candidate;
        }

        foreach ($this->uniqueCandidates($candidates) as $candidate) {
            if ($this->testBinary($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function commonBinaryCandidates(string $binaryFile): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $userProfile = trim((string)(getenv('USERPROFILE') ?: ''));
            $candidates = [
                'C:/ffmpeg/bin/' . $binaryFile,
                'C:/Program Files/ffmpeg/bin/' . $binaryFile,
                $this->baseDir . '/ffmpeg/bin/' . $binaryFile,
            ];
            if ($userProfile !== '') {
                $candidates[] = str_replace('\\', '/', $userProfile) . '/ffmpeg/bin/' . $binaryFile;
            }
            $candidates[] = $binaryFile;
            $candidates[] = pathinfo($binaryFile, PATHINFO_FILENAME);
            return $candidates;
        }

        return [
            '/usr/bin/' . $binaryFile,
            '/usr/local/bin/' . $binaryFile,
            $this->binDir . '/' . $binaryFile,
            pathinfo($binaryFile, PATHINFO_FILENAME),
        ];
    }

    private function uniqueCandidates(array $candidates): array
    {
        $unique = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }

            $key = strtolower(str_replace('\\', '/', $candidate));
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    private function getSetting(string $key): string
    {
        $settings = $this->loadSettings();
        return isset($settings[$key]) ? trim((string)$settings[$key]) : '';
    }

    private function loadSettings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $this->settingsCache = [];
        if (!$this->pdo instanceof PDO) {
            return $this->settingsCache;
        }

        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM settings");
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if (is_array($rows)) {
                foreach ($rows as $key => $value) {
                    if (is_string($key)) {
                        $this->settingsCache[$key] = (string)$value;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->settingsCache = [];
        }

        return $this->settingsCache;
    }

    private function isAutoInstallEnabled(): bool
    {
        $value = strtolower($this->getSetting('auto_install_local_runtime'));
        if ($value === '') {
            return true;
        }

        return !in_array($value, ['0', 'false', 'off', 'no'], true);
    }

    private function canAutoInstall(): bool
    {
        return in_array(PHP_OS_FAMILY, ['Windows', 'Linux'], true);
    }

    private function getDownloadUrl(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $platformUrl = trim($this->getSetting('ffmpeg_auto_download_url_windows'));
            if ($platformUrl !== '') {
                return $platformUrl;
            }
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $platformUrl = trim($this->getSetting('ffmpeg_auto_download_url_linux'));
            if ($platformUrl !== '') {
                return $platformUrl;
            }
        }

        $sharedUrl = trim($this->getSetting('ffmpeg_auto_download_url'));
        if ($sharedUrl !== '') {
            return $sharedUrl;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return 'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip';
        }

        if (PHP_OS_FAMILY === 'Linux') {
            return 'https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz';
        }

        return '';
    }

    private function getArchiveFileName(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $basename = is_string($path) ? basename($path) : '';
        if ($basename === '' || $basename === '.' || $basename === '..') {
            $basename = PHP_OS_FAMILY === 'Windows' ? 'ffmpeg-runtime.zip' : 'ffmpeg-runtime.tar.xz';
        }
        return $basename;
    }

    private function downloadFile(string $url, string $targetPath): void
    {
        $this->ensureDirectory(dirname($targetPath));

        $fp = @fopen($targetPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Unable to open FFmpeg archive target for writing: ' . $targetPath);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FAILONERROR => false,
            CURLOPT_USERAGENT => 'VideoWorkflow-RuntimeBootstrap/1.0',
        ]);

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        clearstatcache(true, $targetPath);
        if (!$ok || $httpCode >= 400 || !is_file($targetPath) || filesize($targetPath) <= 0) {
            @unlink($targetPath);
            $detail = $error !== '' ? $error : ('HTTP ' . $httpCode);
            throw new RuntimeException('Failed to download FFmpeg package: ' . $detail);
        }
    }

    private function extractPackage(string $archivePath, string $targetDir): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('ZipArchive extension is required to extract FFmpeg on Windows.');
            }

            $zip = new ZipArchive();
            $open = $zip->open($archivePath);
            if ($open !== true) {
                throw new RuntimeException('Unable to open FFmpeg zip archive.');
            }

            if (!$zip->extractTo($targetDir)) {
                $zip->close();
                throw new RuntimeException('Unable to extract FFmpeg zip archive.');
            }

            $zip->close();
            return;
        }

        $output = [];
        $returnCode = 0;
        $command = 'tar -xf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($targetDir) . ' 2>&1';
        exec($command, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new RuntimeException('Unable to extract FFmpeg archive: ' . trim(implode("\n", $output)));
        }
    }

    private function findBinaryInDirectory(string $directory, string $binaryName): ?string
    {
        if (!is_dir($directory)) {
            return null;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (strcasecmp($file->getFilename(), $binaryName) === 0) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function copyBinary(string $source, string $target): void
    {
        $this->ensureDirectory(dirname($target));
        if (!@copy($source, $target)) {
            throw new RuntimeException('Unable to copy binary into runtime directory: ' . basename($target));
        }

        @chmod($target, 0755);
    }

    private function persistSetting(string $key, string $value): void
    {
        if (!$this->pdo instanceof PDO) {
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$key, $value]);
        $this->settingsCache = null;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . $directory);
        }
    }

    private function wipeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    private function testBinary(?string $candidate): bool
    {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            return false;
        }

        $output = [];
        $returnCode = 1;
        $command = escapeshellarg($candidate) . ' -version 2>&1';
        @exec($command, $output, $returnCode);
        return $returnCode === 0;
    }

    private function binaryName(string $binary): string
    {
        return PHP_OS_FAMILY === 'Windows' ? $binary . '.exe' : $binary;
    }
}
