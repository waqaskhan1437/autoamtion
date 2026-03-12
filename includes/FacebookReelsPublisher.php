<?php

class FacebookReelsPublisher
{
    public static function splitPublishTargets(PostForMeAPI $postForMe, array $accountIds): array
    {
        $selectedIds = array_values(array_filter(array_map(static function ($accountId): string {
            return trim((string)$accountId);
        }, $accountIds)));

        if (empty($selectedIds)) {
            return [
                'success' => true,
                'facebook_accounts' => [],
                'postforme_account_ids' => [],
                'warnings' => [],
            ];
        }

        $accountsResult = $postForMe->getAccounts();
        if (empty($accountsResult['success']) || !is_array($accountsResult['accounts'] ?? null)) {
            return [
                'success' => false,
                'error' => (string)($accountsResult['error'] ?? 'Unable to load social accounts for Facebook publishing.'),
                'facebook_accounts' => [],
                'postforme_account_ids' => $selectedIds,
                'warnings' => [],
            ];
        }

        $accountMap = [];
        foreach ($accountsResult['accounts'] as $account) {
            $accountId = trim((string)($account['id'] ?? ''));
            if ($accountId !== '') {
                $accountMap[$accountId] = is_array($account) ? $account : [];
            }
        }

        $facebookAccounts = [];
        $postForMeAccountIds = [];
        $warnings = [];

        foreach ($selectedIds as $accountId) {
            $account = $accountMap[$accountId] ?? null;
            if (!is_array($account)) {
                $postForMeAccountIds[] = $accountId;
                $warnings[] = "Account {$accountId} not found in PostForMe account list.";
                continue;
            }

            $platform = strtolower(trim((string)($account['platform'] ?? '')));
            $token = trim((string)($account['access_token'] ?? ''));
            if ($platform === 'facebook') {
                if ($token === '') {
                    $postForMeAccountIds[] = $accountId;
                    $warnings[] = "Facebook account {$accountId} is missing a page token; using PostForMe fallback.";
                    continue;
                }

                $facebookAccounts[] = $account;
                continue;
            }

            $postForMeAccountIds[] = $accountId;
        }

        return [
            'success' => true,
            'facebook_accounts' => $facebookAccounts,
            'postforme_account_ids' => $postForMeAccountIds,
            'warnings' => $warnings,
        ];
    }

    public static function buildFacebookPayload(string $defaultCaption, array $options = []): array
    {
        $platformOverrides = is_array($options['platform_overrides'] ?? null)
            ? $options['platform_overrides']
            : [];
        $facebookOverride = is_array($platformOverrides['facebook'] ?? null)
            ? $platformOverrides['facebook']
            : [];

        $description = trim((string)($facebookOverride['description'] ?? $facebookOverride['caption'] ?? $defaultCaption));
        $caption = trim((string)($facebookOverride['caption'] ?? $description));
        $title = trim((string)($facebookOverride['title'] ?? ''));

        if ($title === '') {
            $title = self::deriveTitle($description !== '' ? $description : $caption);
        }

        return [
            'title' => $title,
            'caption' => $caption,
            'description' => $description !== '' ? $description : $caption,
        ];
    }

    public static function publishLocalVideo(
        PostForMeAPI $postForMe,
        string $videoPath,
        array $facebookAccounts,
        array $payload = []
    ): array {
        if (empty($facebookAccounts)) {
            return [
                'success' => false,
                'error' => 'No Facebook accounts selected for direct publishing.',
                'results' => [],
                'errors' => [],
            ];
        }

        if (!is_file($videoPath)) {
            return [
                'success' => false,
                'error' => 'Video file not found: ' . $videoPath,
                'results' => [],
                'errors' => [],
            ];
        }

        $upload = $postForMe->uploadMedia($videoPath);
        if (empty($upload['success']) || empty($upload['media_url'])) {
            return [
                'success' => false,
                'error' => (string)($upload['error'] ?? 'Failed to upload Facebook media to PostForMe storage.'),
                'results' => [],
                'errors' => [],
                'upload' => $upload,
            ];
        }

        return self::publishHostedMedia((string)$upload['media_url'], $facebookAccounts, $payload);
    }

    public static function publishHostedMedia(string $mediaUrl, array $facebookAccounts, array $payload = []): array
    {
        $description = trim((string)($payload['description'] ?? $payload['caption'] ?? ''));
        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            $title = self::deriveTitle($description);
        }

        $results = [];
        $errors = [];

        foreach ($facebookAccounts as $account) {
            $result = self::publishSingleAccount($mediaUrl, $account, $title, $description);
            if (!empty($result['success'])) {
                $results[] = $result;
            } else {
                $errors[] = $result;
            }
        }

        return [
            'success' => !empty($results),
            'all_success' => empty($errors),
            'results' => $results,
            'errors' => $errors,
            'media_url' => $mediaUrl,
        ];
    }

    private static function publishSingleAccount(string $mediaUrl, array $account, string $title, string $description): array
    {
        $token = trim((string)($account['access_token'] ?? ''));
        $accountId = trim((string)($account['id'] ?? ''));
        $accountName = trim((string)($account['username'] ?? $account['account_name'] ?? $accountId));

        if ($token === '') {
            return [
                'success' => false,
                'account_id' => $accountId,
                'account_name' => $accountName,
                'error' => 'Facebook page token missing.',
            ];
        }

        $start = self::request(
            'POST',
            'https://graph.facebook.com/v20.0/me/video_reels',
            [
                'upload_phase' => 'start',
                'access_token' => $token,
            ]
        );
        if (empty($start['success'])) {
            return self::buildErrorResult($accountId, $accountName, 'Facebook reels start failed', $start);
        }

        $videoId = trim((string)($start['json']['video_id'] ?? ''));
        $uploadUrl = trim((string)($start['json']['upload_url'] ?? ''));
        if ($videoId === '' || $uploadUrl === '') {
            return [
                'success' => false,
                'account_id' => $accountId,
                'account_name' => $accountName,
                'error' => 'Facebook reels start response missing upload URL.',
                'response' => $start['json'] ?? null,
            ];
        }

        $upload = self::request(
            'POST',
            $uploadUrl,
            null,
            [
                'Authorization: OAuth ' . $token,
                'file_url: ' . $mediaUrl,
            ]
        );
        if (empty($upload['success'])) {
            return self::buildErrorResult($accountId, $accountName, 'Facebook reels upload failed', $upload, $videoId);
        }

        $finishPayload = [
            'access_token' => $token,
            'video_id' => $videoId,
            'upload_phase' => 'finish',
            'video_state' => 'PUBLISHED',
        ];
        if ($title !== '') {
            $finishPayload['title'] = $title;
        }
        if ($description !== '') {
            $finishPayload['description'] = $description;
        }

        $finish = self::request(
            'POST',
            'https://graph.facebook.com/v20.0/me/video_reels',
            $finishPayload
        );
        if (empty($finish['success'])) {
            return self::buildErrorResult($accountId, $accountName, 'Facebook reels finish failed', $finish, $videoId);
        }

        return [
            'success' => true,
            'account_id' => $accountId,
            'account_name' => $accountName,
            'video_id' => $videoId,
            'post_id' => (string)($finish['json']['post_id'] ?? ''),
            'response' => $finish['json'] ?? null,
        ];
    }

    private static function request(string $method, string $url, ?array $data = null, array $headers = []): array
    {
        $method = strtoupper($method);
        $ch = curl_init();
        $curlHeaders = array_merge(['Accept: application/json'], $headers);

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if ($data !== null) {
                $options[CURLOPT_POSTFIELDS] = http_build_query($data);
            }
        } elseif ($method === 'GET' && !empty($data)) {
            $options[CURLOPT_URL] = $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($data);
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($data !== null) {
                $options[CURLOPT_POSTFIELDS] = http_build_query($data);
            }
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            return [
                'success' => false,
                'http_code' => 0,
                'error' => $curlError,
                'body' => '',
                'json' => null,
            ];
        }

        $decoded = json_decode((string)$body, true);

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'body' => (string)$body,
            'json' => is_array($decoded) ? $decoded : null,
            'error' => self::extractErrorMessage(is_array($decoded) ? $decoded : null, (string)$body, $httpCode),
        ];
    }

    private static function buildErrorResult(
        string $accountId,
        string $accountName,
        string $prefix,
        array $response,
        string $videoId = ''
    ): array {
        $message = trim($prefix . ': ' . (string)($response['error'] ?? 'Unknown error'));

        return [
            'success' => false,
            'account_id' => $accountId,
            'account_name' => $accountName,
            'video_id' => $videoId,
            'error' => $message,
            'http_code' => (int)($response['http_code'] ?? 0),
            'response' => $response['json'] ?? $response['body'] ?? null,
        ];
    }

    private static function extractErrorMessage(?array $json, string $body, int $httpCode): string
    {
        if (is_array($json['error'] ?? null)) {
            $error = $json['error'];
            $message = trim((string)($error['message'] ?? ''));
            $code = (string)($error['code'] ?? '');
            $subcode = (string)($error['error_subcode'] ?? '');

            $parts = [];
            if ($message !== '') {
                $parts[] = $message;
            }
            if ($code !== '') {
                $parts[] = 'code ' . $code;
            }
            if ($subcode !== '') {
                $parts[] = 'subcode ' . $subcode;
            }

            if (!empty($parts)) {
                return implode(' | ', $parts);
            }
        }

        if (is_string($json['message'] ?? null) && trim((string)$json['message']) !== '') {
            return trim((string)$json['message']);
        }

        if (trim($body) !== '') {
            return trim($body);
        }

        return 'HTTP ' . $httpCode;
    }

    private static function deriveTitle(string $description): string
    {
        $line = trim((string)preg_split('/\R+/', $description, 2)[0]);
        $line = preg_replace('/https?:\/\/\S+/i', '', $line);
        $line = preg_replace('/#[A-Za-z0-9_]+/', '', $line);
        $line = trim(preg_replace('/\s+/', ' ', (string)$line));

        if ($line === '') {
            $line = 'PrankWish.com Video';
        }

        return substr($line, 0, 100);
    }
}
