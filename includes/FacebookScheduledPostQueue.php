<?php

class FacebookScheduledPostQueue
{
    public static function scheduleFromLocalVideo(
        PDO $pdo,
        PostForMeAPI $postForMe,
        int $automationId,
        ?string $videoId,
        string $videoPath,
        array $facebookAccounts,
        array $payload,
        string $scheduledAt
    ): array {
        if (!filter_var($videoPath, FILTER_VALIDATE_URL) && !is_file($videoPath)) {
            return [
                'success' => false,
                'error' => 'Scheduled Facebook video file not found: ' . $videoPath,
            ];
        }

        $mediaUrl = '';
        if (filter_var($videoPath, FILTER_VALIDATE_URL)) {
            $mediaUrl = (string)$videoPath;
        } else {
            $upload = $postForMe->uploadMedia($videoPath);
            if (empty($upload['success']) || empty($upload['media_url'])) {
                return [
                    'success' => false,
                    'error' => (string)($upload['error'] ?? 'Failed to upload scheduled Facebook media.'),
                    'upload' => $upload,
                ];
            }
            $mediaUrl = (string)$upload['media_url'];
        }

        $accountIds = [];
        foreach ($facebookAccounts as $account) {
            $accountId = trim((string)($account['id'] ?? ''));
            if ($accountId !== '') {
                $accountIds[] = $accountId;
            }
        }

        return self::upsertScheduledJob(
            $pdo,
            $automationId,
            $videoId,
            $mediaUrl,
            $accountIds,
            $payload,
            $scheduledAt
        );
    }

    public static function upsertScheduledJob(
        PDO $pdo,
        int $automationId,
        ?string $videoId,
        string $mediaUrl,
        array $accountIds,
        array $payload,
        string $scheduledAt,
        ?string $jobKey = null
    ): array {
        $accountIds = array_values(array_unique(array_filter(array_map(static function ($accountId): string {
            return trim((string)$accountId);
        }, $accountIds))));

        if ($mediaUrl === '' || empty($accountIds) || trim($scheduledAt) === '') {
            return [
                'success' => false,
                'error' => 'Scheduled Facebook queue data is incomplete.',
            ];
        }

        $caption = trim((string)($payload['caption'] ?? ''));
        $title = trim((string)($payload['title'] ?? ''));
        $description = trim((string)($payload['description'] ?? $caption));
        if ($title === '') {
            $title = substr($description !== '' ? $description : $caption, 0, 100);
        }

        $videoId = $videoId !== null ? trim($videoId) : null;
        if ($videoId === '') {
            $videoId = null;
        }

        $jobKey = trim((string)$jobKey);
        if ($jobKey === '') {
            $jobKey = self::buildJobKey($automationId, $videoId, $mediaUrl, $accountIds, $scheduledAt);
        }

        $scheduledAtDb = self::normalizeDateTime($scheduledAt);
        if ($scheduledAtDb === null) {
            return [
                'success' => false,
                'error' => 'Invalid scheduled time for Facebook queue.',
            ];
        }

        $stmt = $pdo->prepare("
            INSERT INTO facebook_scheduled_posts
                (job_key, automation_id, video_id, media_url, caption, title, description, account_ids, status, scheduled_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)
            ON DUPLICATE KEY UPDATE
                automation_id = VALUES(automation_id),
                video_id = VALUES(video_id),
                media_url = VALUES(media_url),
                caption = VALUES(caption),
                title = VALUES(title),
                description = VALUES(description),
                account_ids = VALUES(account_ids),
                scheduled_at = VALUES(scheduled_at),
                status = CASE
                    WHEN facebook_scheduled_posts.status IN ('posted', 'cancelled') THEN facebook_scheduled_posts.status
                    ELSE 'scheduled'
                END,
                error_message = NULL
        ");
        $stmt->execute([
            $jobKey,
            $automationId,
            $videoId,
            $mediaUrl,
            $caption,
            $title,
            $description,
            json_encode($accountIds, JSON_UNESCAPED_SLASHES),
            $scheduledAtDb,
        ]);

        $idStmt = $pdo->prepare("SELECT id, status FROM facebook_scheduled_posts WHERE job_key = ? LIMIT 1");
        $idStmt->execute([$jobKey]);
        $row = $idStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'success' => true,
            'id' => (int)($row['id'] ?? 0),
            'job_key' => $jobKey,
            'status' => (string)($row['status'] ?? 'scheduled'),
            'media_url' => $mediaUrl,
            'scheduled_at' => $scheduledAtDb,
            'account_ids' => $accountIds,
            'payload' => [
                'caption' => $caption,
                'title' => $title,
                'description' => $description,
            ],
        ];
    }

    public static function persistCallbackJobs(PDO $pdo, int $automationId, array $jobs): array
    {
        $saved = 0;
        $errors = [];

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            $result = self::upsertScheduledJob(
                $pdo,
                $automationId,
                isset($job['video_id']) ? (string)$job['video_id'] : null,
                trim((string)($job['media_url'] ?? '')),
                is_array($job['account_ids'] ?? null) ? $job['account_ids'] : [],
                [
                    'caption' => (string)($job['caption'] ?? ''),
                    'title' => (string)($job['title'] ?? ''),
                    'description' => (string)($job['description'] ?? ''),
                ],
                (string)($job['scheduled_at'] ?? ''),
                isset($job['job_key']) ? (string)$job['job_key'] : null
            );

            if (!empty($result['success'])) {
                $saved++;
            } else {
                $errors[] = (string)($result['error'] ?? 'Failed to persist scheduled Facebook callback job.');
            }
        }

        return [
            'success' => empty($errors),
            'saved' => $saved,
            'errors' => $errors,
        ];
    }

    public static function publishDue(PDO $pdo, PostForMeAPI $postForMe, ?callable $logger = null, int $limit = 10): array
    {
        $limit = max(1, $limit);
        $stmt = $pdo->prepare("
            SELECT *
            FROM facebook_scheduled_posts
            WHERE status IN ('scheduled', 'queued')
              AND scheduled_at IS NOT NULL
              AND scheduled_at <= UTC_TIMESTAMP()
            ORDER BY scheduled_at ASC, id ASC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $processed = 0;
        $posted = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $claim = $pdo->prepare("
                UPDATE facebook_scheduled_posts
                SET status = 'processing',
                    error_message = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND status IN ('scheduled', 'queued')
            ");
            $claim->execute([$id]);
            if ($claim->rowCount() === 0) {
                continue;
            }

            $processed++;
            $accountIds = self::decodeAccountIds((string)($row['account_ids'] ?? ''));
            $targets = FacebookReelsPublisher::splitPublishTargets($postForMe, $accountIds);
            $facebookAccounts = is_array($targets['facebook_accounts'] ?? null)
                ? $targets['facebook_accounts']
                : [];

            if (empty($facebookAccounts)) {
                $failed++;
                $message = 'No active Facebook accounts available for scheduled direct publish.';
                self::markFailed($pdo, $id, $message);
                self::log($logger, "Scheduled Facebook post #{$id} failed: {$message}");
                continue;
            }

            $result = FacebookReelsPublisher::publishHostedMedia(
                (string)($row['media_url'] ?? ''),
                $facebookAccounts,
                [
                    'caption' => (string)($row['caption'] ?? ''),
                    'title' => (string)($row['title'] ?? ''),
                    'description' => (string)($row['description'] ?? ''),
                ]
            );

            $status = 'failed';
            $errorMessage = null;
            $publishedAt = null;
            if (!empty($result['success']) && !empty($result['all_success'])) {
                $status = 'posted';
                $publishedAt = gmdate('Y-m-d H:i:s');
                $posted++;
                self::log($logger, "Scheduled Facebook post #{$id} published successfully.");
            } elseif (!empty($result['success'])) {
                $status = 'partial';
                $publishedAt = gmdate('Y-m-d H:i:s');
                $posted++;
                $failed++;
                $errorMessage = self::summarizeErrors((array)($result['errors'] ?? []));
                self::log($logger, "Scheduled Facebook post #{$id} published partially: {$errorMessage}");
            } else {
                $failed++;
                $errorMessage = trim((string)($result['error'] ?? self::summarizeErrors((array)($result['errors'] ?? [])) ?: 'Facebook direct publish failed.'));
                self::log($logger, "Scheduled Facebook post #{$id} failed: {$errorMessage}");
            }

            $update = $pdo->prepare("
                UPDATE facebook_scheduled_posts
                SET status = ?,
                    published_at = ?,
                    error_message = ?,
                    result_json = ?
                WHERE id = ?
            ");
            $update->execute([
                $status,
                $publishedAt,
                $errorMessage,
                json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $id,
            ]);
        }

        return [
            'processed' => $processed,
            'posted' => $posted,
            'failed' => $failed,
        ];
    }

    public static function cancelById(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare("
            UPDATE facebook_scheduled_posts
            SET status = 'cancelled',
                error_message = NULL
            WHERE id = ?
              AND status IN ('scheduled', 'queued', 'processing', 'partial')
        ");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            return [
                'success' => false,
                'error' => 'Facebook scheduled post not found or cannot be cancelled.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Facebook scheduled post cancelled.',
        ];
    }

    public static function cancelAll(PDO $pdo, ?int $automationId = null): array
    {
        $sql = "
            UPDATE facebook_scheduled_posts
            SET status = 'cancelled',
                error_message = NULL
            WHERE status IN ('scheduled', 'queued', 'processing', 'partial')
        ";
        $params = [];
        if ($automationId !== null && $automationId > 0) {
            $sql .= " AND automation_id = ? ";
            $params[] = $automationId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'success' => true,
            'cancelled' => (int)$stmt->rowCount(),
        ];
    }

    public static function fetchCounts(PDO $pdo, int $automationId): array
    {
        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN status IN ('scheduled', 'queued', 'processing', 'partial') THEN 1 ELSE 0 END) AS scheduled,
                SUM(CASE WHEN status = 'posted' OR published_at IS NOT NULL THEN 1 ELSE 0 END) AS posted,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                COUNT(*) AS tracked_total
            FROM facebook_scheduled_posts
            WHERE automation_id = ?
        ");
        $stmt->execute([$automationId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'scheduled' => (int)($row['scheduled'] ?? 0),
            'posted' => (int)($row['posted'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
            'tracked_total' => (int)($row['tracked_total'] ?? 0),
        ];
    }

    private static function buildJobKey(int $automationId, ?string $videoId, string $mediaUrl, array $accountIds, string $scheduledAt): string
    {
        return sha1($automationId . '|' . ($videoId ?? '') . '|' . $mediaUrl . '|' . implode(',', $accountIds) . '|' . $scheduledAt);
    }

    private static function decodeAccountIds(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('strval', $decoded))));
    }

    private static function normalizeDateTime(string $value): ?string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function summarizeErrors(array $errors): string
    {
        if (empty($errors)) {
            return '';
        }

        $parts = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $accountName = trim((string)($error['account_name'] ?? $error['account_id'] ?? 'Facebook'));
            $message = trim((string)($error['error'] ?? 'Unknown error'));
            if ($accountName !== '' && $message !== '') {
                $parts[] = $accountName . ': ' . $message;
            } elseif ($message !== '') {
                $parts[] = $message;
            }
        }

        return implode(' | ', $parts);
    }

    private static function markFailed(PDO $pdo, int $id, string $message): void
    {
        $stmt = $pdo->prepare("
            UPDATE facebook_scheduled_posts
            SET status = 'failed',
                error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$message, $id]);
    }

    private static function log(?callable $logger, string $message): void
    {
        if ($logger !== null) {
            $logger($message);
        }
    }
}
