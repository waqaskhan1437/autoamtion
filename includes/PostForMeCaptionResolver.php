<?php

class PostForMeCaptionResolver
{
    public static function resolvePrimaryCaption(array $accounts, string $defaultCaption, array $platformOverrides = []): string
    {
        $defaultCaption = trim($defaultCaption);
        if ($defaultCaption === '' || empty($accounts) || empty($platformOverrides)) {
            return $defaultCaption;
        }

        $selectedPlatforms = [];
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $platform = self::normalizePlatform((string)($account['platform'] ?? ''));
            if ($platform !== '') {
                $selectedPlatforms[$platform] = true;
            }
        }

        if (empty($selectedPlatforms)) {
            return $defaultCaption;
        }

        foreach (['twitter', 'bluesky'] as $platform) {
            if (!empty($selectedPlatforms[$platform])) {
                $caption = self::extractCaptionForPlatform($platform, $platformOverrides);
                if ($caption !== '') {
                    return $caption;
                }
            }
        }

        if (count($selectedPlatforms) === 1) {
            $platform = (string) array_key_first($selectedPlatforms);
            $caption = self::extractCaptionForPlatform($platform, $platformOverrides);
            if ($caption !== '') {
                return $caption;
            }
        }

        return $defaultCaption;
    }

    private static function extractCaptionForPlatform(string $platform, array $platformOverrides): string
    {
        $platform = self::normalizePlatform($platform);
        if ($platform === '') {
            return '';
        }

        $override = $platformOverrides[$platform] ?? null;
        if (!is_array($override) && $platform === 'twitter') {
            $override = $platformOverrides['x'] ?? null;
        }

        if (!is_array($override)) {
            return '';
        }

        $caption = trim((string)($override['caption'] ?? $override['description'] ?? ''));
        if ($caption !== '') {
            return $caption;
        }

        return trim((string)($override['title'] ?? ''));
    }

    private static function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if ($platform === 'x' || $platform === 'tweet') {
            return 'twitter';
        }

        return $platform;
    }
}
