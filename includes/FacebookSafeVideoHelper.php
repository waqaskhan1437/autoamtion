<?php

class FacebookSafeVideoHelper
{
    public static function prepareVideoForAccounts(
        PostForMeAPI $postForMe,
        string $videoPath,
        array $accountIds,
        ?PDO $pdo = null,
        array $options = []
    ): array {
        $platforms = self::resolvePlatforms($postForMe, $accountIds, $pdo);
        if (!in_array('facebook', $platforms, true)) {
            return [
                'success' => true,
                'path' => $videoPath,
                'cleanup' => false,
                'transcoded' => false,
                'platforms' => $platforms,
                'reason' => 'facebook_not_selected',
            ];
        }

        if (filter_var($videoPath, FILTER_VALIDATE_URL)) {
            return [
                'success' => true,
                'path' => $videoPath,
                'cleanup' => false,
                'transcoded' => false,
                'platforms' => $platforms,
                'reason' => 'remote_url_source',
            ];
        }

        if (!is_file($videoPath)) {
            return [
                'success' => false,
                'error' => 'Video file not found: ' . $videoPath,
                'platforms' => $platforms,
            ];
        }

        $ffprobePath = (string)($options['ffprobe_path'] ?? FFPROBE_PATH);
        $ffmpegPath = (string)($options['ffmpeg_path'] ?? FFMPEG_PATH);
        $probe = self::probeMedia($videoPath, $ffprobePath);

        if (!empty($probe['success']) && self::isAlreadyFacebookSafe($probe)) {
            return [
                'success' => true,
                'path' => $videoPath,
                'cleanup' => false,
                'transcoded' => false,
                'platforms' => $platforms,
                'reason' => 'already_safe',
                'probe' => $probe,
            ];
        }

        $targetPath = self::buildTargetPath($videoPath);
        $videoFilter = 'scale=720:1280:force_original_aspect_ratio=decrease:flags=lanczos,pad=720:1280:(ow-iw)/2:(oh-ih)/2:black,fps=30,format=yuv420p';
        $audioArgs = (empty($probe['success']) || !empty($probe['has_audio']))
            ? '-c:a aac -ar 44100 -ac 2 -b:a 128k'
            : '-an';

        $command = sprintf(
            '%s -y -i %s -vf %s -c:v libx264 -preset fast -crf 23 -profile:v high -level 4.1 -pix_fmt yuv420p -movflags +faststart -r 30 %s %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($videoPath),
            escapeshellarg($videoFilter),
            $audioArgs,
            escapeshellarg($targetPath)
        );

        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($targetPath) || filesize($targetPath) < 1024) {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }

            return [
                'success' => false,
                'error' => 'Facebook-safe transcode failed: ' . trim(implode("\n", array_slice($output, -8))),
                'platforms' => $platforms,
                'probe' => $probe,
            ];
        }

        return [
            'success' => true,
            'path' => $targetPath,
            'cleanup' => true,
            'transcoded' => true,
            'platforms' => $platforms,
            'reason' => 'facebook_safe_copy_created',
            'probe' => $probe,
        ];
    }

    public static function cleanupPreparedVideo(array $prepared): void
    {
        $path = (string)($prepared['path'] ?? '');
        if (!empty($prepared['cleanup']) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    public static function describePlatforms(array $platforms): string
    {
        $platforms = array_values(array_unique(array_filter(array_map(static function ($platform): string {
            return strtolower(trim((string)$platform));
        }, $platforms))));

        return implode(', ', $platforms);
    }

    private static function resolvePlatforms(PostForMeAPI $postForMe, array $accountIds, ?PDO $pdo = null): array
    {
        $accountIds = array_values(array_filter(array_map(static function ($accountId): string {
            return trim((string)$accountId);
        }, $accountIds)));

        if (empty($accountIds)) {
            return [];
        }

        $map = [];

        if ($pdo !== null) {
            try {
                $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
                $stmt = $pdo->prepare("SELECT account_id, platform FROM postforme_accounts WHERE account_id IN ($placeholders)");
                $stmt->execute($accountIds);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $accountId = trim((string)($row['account_id'] ?? ''));
                    $platform = strtolower(trim((string)($row['platform'] ?? '')));
                    if ($accountId !== '' && $platform !== '') {
                        $map[$accountId] = $platform;
                    }
                }
            } catch (Throwable $e) {
            }
        }

        if (count($map) < count($accountIds)) {
            $accountsResult = $postForMe->getAccounts();
            if (!empty($accountsResult['success']) && is_array($accountsResult['accounts'] ?? null)) {
                foreach ($accountsResult['accounts'] as $account) {
                    $accountId = trim((string)($account['id'] ?? ''));
                    $platform = strtolower(trim((string)($account['platform'] ?? '')));
                    if ($accountId !== '' && $platform !== '') {
                        $map[$accountId] = $platform;
                    }
                }
            }
        }

        $platforms = [];
        foreach ($accountIds as $accountId) {
            if (!empty($map[$accountId])) {
                $platforms[] = $map[$accountId];
            }
        }

        return array_values(array_unique($platforms));
    }

    private static function probeMedia(string $videoPath, string $ffprobePath): array
    {
        $command = sprintf(
            '%s -v error -show_entries format=duration,size:stream=codec_name,codec_type,width,height,pix_fmt,avg_frame_rate,sample_rate,channels -of json %s 2>&1',
            escapeshellarg($ffprobePath),
            escapeshellarg($videoPath)
        );

        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            return ['success' => false];
        }

        $decoded = json_decode(implode("\n", $output), true);
        if (!is_array($decoded)) {
            return ['success' => false];
        }

        $info = [
            'success' => true,
            'has_audio' => false,
            'width' => 0,
            'height' => 0,
            'pix_fmt' => '',
            'fps' => 0.0,
            'sample_rate' => 0,
        ];

        foreach ($decoded['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                $info['width'] = (int)($stream['width'] ?? 0);
                $info['height'] = (int)($stream['height'] ?? 0);
                $info['pix_fmt'] = (string)($stream['pix_fmt'] ?? '');
                $info['fps'] = self::parseFrameRate((string)($stream['avg_frame_rate'] ?? '0/0'));
            } elseif (($stream['codec_type'] ?? '') === 'audio') {
                $info['has_audio'] = true;
                $info['sample_rate'] = (int)($stream['sample_rate'] ?? 0);
            }
        }

        return $info;
    }

    private static function isAlreadyFacebookSafe(array $probe): bool
    {
        if (empty($probe['success'])) {
            return false;
        }

        $hasSafeAudio = empty($probe['has_audio']) || (int)($probe['sample_rate'] ?? 0) === 44100;

        return (int)($probe['width'] ?? 0) === 720
            && (int)($probe['height'] ?? 0) === 1280
            && strtolower((string)($probe['pix_fmt'] ?? '')) === 'yuv420p'
            && (float)($probe['fps'] ?? 0.0) <= 30.01
            && $hasSafeAudio;
    }

    private static function buildTargetPath(string $videoPath): string
    {
        $dir = dirname($videoPath);
        $name = pathinfo($videoPath, PATHINFO_FILENAME);

        return $dir . DIRECTORY_SEPARATOR . $name . '_facebook_safe_' . uniqid() . '.mp4';
    }

    private static function parseFrameRate(string $raw): float
    {
        $parts = explode('/', $raw, 2);
        if (count($parts) === 2) {
            $den = (float)$parts[1];
            if ($den > 0) {
                return (float)$parts[0] / $den;
            }
        }

        return (float)$raw;
    }
}
