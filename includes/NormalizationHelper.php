<?php
/**
 * NormalizationHelper Class
 * Centralized normalization functions for the Video Workflow Manager
 * 
 * Consolidates all duplicate normalization functions from automation.php
 * to reduce code duplication and improve maintainability.
 * 
 * @author Kilo
 * @version 1.0
 */

class NormalizationHelper {
    /**
     * Normalize manual video links input
     * 
     * Converts various input formats into a clean, deduplicated list of URLs
     * 
     * @param mixed $rawInput Raw input from user
     * @return string Normalized URLs separated by newlines
     */
    public static function normalizeManualVideoLinksInput($rawInput): string {
        $raw = is_string($rawInput) ? $rawInput : (string)$rawInput;
        $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
        if ($raw === '') {
            return '';
        }

        $tokens = preg_split('/[\n,]+/', $raw) ?: [];
        $seen = [];
        $clean = [];

        foreach ($tokens as $token) {
            $url = trim((string)$token);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $clean[] = $url;
        }

        return implode("\n", $clean);
    }

    /**
     * Normalize YouTube channel URL input
     * 
     * Converts @username format to full YouTube channel URL
     * 
     * @param mixed $rawInput Raw input from user
     * @return string Normalized YouTube channel URL or empty string
     */
    public static function normalizeYouTubeChannelUrlInput($rawInput): string {
        $url = trim(is_string($rawInput) ? $rawInput : (string)$rawInput);
        if ($url === '') {
            return '';
        }

        if (strpos($url, '@') === 0) {
            $url = 'https://www.youtube.com/' . ltrim($url, '/');
        }

        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }

        return $url;
    }

    /**
     * Normalize source shorts mode input
     * 
     * Validates and normalizes the shorts mode setting
     * 
     * @param mixed $rawInput Raw input from user
     * @return string Valid shorts mode ('single', 'duration_based', or 'fixed_count')
     */
    public static function normalizeSourceShortsModeInput($rawInput): string {
        $mode = strtolower(trim(is_string($rawInput) ? $rawInput : (string)$rawInput));
        $allowed = ['single', 'duration_based', 'fixed_count'];
        return in_array($mode, $allowed, true) ? $mode : 'single';
    }

    /**
     * Normalize source shorts max count input
     * 
     * Validates and normalizes the shorts max count setting
     * 
     * @param mixed $rawInput Raw input from user
     * @param string $mode Current shorts mode
     * @return int Valid max count (1-20, or 1 for single mode)
     */
    public static function normalizeSourceShortsMaxCountInput($rawInput, string $mode = 'single'): int {
        $count = intval($rawInput ?? 1);
        if ($count < 1) {
            $count = 1;
        }
        if ($count > 20) {
            $count = 20;
        }
        if ($mode === 'single') {
            return 1;
        }
        return $count;
    }

    /**
     * Normalize playback speed input
     * 
     * Validates and normalizes playback speed
     * 
     * @param mixed $rawInput Raw input from user
     * @return string Normalized playback speed (formatted to 1 decimal place)
     */
    public static function normalizePlaybackSpeedInput($rawInput): string {
        $speed = is_numeric($rawInput) ? (float)$rawInput : 1.0;
        if ($speed < 0.1) {
            $speed = 0.1;
        }
        if ($speed > 3.0) {
            $speed = 3.0;
        }
        return number_format($speed, 1, '.', '');
    }

    /**
     * Normalize custom prompt input
     * 
     * Validates and sanitizes custom prompt text
     * 
     * @param mixed $rawInput Raw input from user
     * @param int $maxLength Maximum length (default: 600)
     * @return string|null Normalized prompt text or null if empty
     */
    public static function normalizeCustomPromptInput($rawInput, int $maxLength = 600): ?string {
        $text = trim(is_string($rawInput) ? $rawInput : (string)$rawInput);
        if ($text === '') {
            return null;
        }

        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        $blockedPatterns = [
            '/ignore\s+(all\s+)?(previous|above|earlier|system)\s+instructions?/iu',
            '/disregard\s+(all\s+)?(previous|above|earlier|system)\s+instructions?/iu',
            '/override\s+(the\s+)?(rules|guardrails|limits|constraints)/iu',
            '/system\s+prompt/iu',
        ];
        foreach ($blockedPatterns as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, $maxLength);
        } else {
            $text = substr($text, 0, $maxLength);
        }

        return trim($text) !== '' ? trim($text) : null;
    }

    /**
     * Normalize schedule hour input
     * 
     * Validates and normalizes schedule hour
     * 
     * @param mixed $rawInput Raw input from user
     * @return int Valid hour (0-23)
     */
    public static function normalizeScheduleHourInput($rawInput): int {
        $hour = is_numeric($rawInput) ? (int)$rawInput : 9;
        if ($hour === 24) {
            return 0;
        }
        if ($hour < 0) {
            return 0;
        }
        if ($hour > 23) {
            return 23;
        }
        return $hour;
    }

    /**
     * Normalize video days filter input
     * 
     * Validates and normalizes video days filter
     * 
     * @param mixed $rawInput Raw input from user
     * @return int Valid days filter (1-365)
     */
    public static function normalizeVideoDaysFilterInput($rawInput): int {
        $days = intval($rawInput ?? 30);
        if ($days < 1) {
            $days = 1;
        }
        if ($days > 365) {
            $days = 365;
        }
        return $days;
    }

    /**
     * Normalize videos per run input
     * 
     * Validates and normalizes videos per run setting
     * 
     * @param mixed $rawInput Raw input from user
     * @return int Valid videos per run (1-500)
     */
    public static function normalizeVideosPerRunInput($rawInput): int {
        $count = intval($rawInput ?? 5);
        if ($count < 1) {
            $count = 1;
        }
        if ($count > 500) {
            $count = 500;
        }
        return $count;
    }

    /**
     * Normalize schedule every minutes input
     * 
     * Validates and normalizes schedule every minutes setting
     * 
     * @param mixed $rawInput Raw input from user
     * @return int Valid minutes (1-1440)
     */
    public static function normalizeScheduleEveryMinutesInput($rawInput): int {
        $minutes = intval($rawInput ?? 10);
        if ($minutes < 1) {
            $minutes = 1;
        }
        if ($minutes > 1440) {
            $minutes = 1440;
        }
        return $minutes;
    }

    /**
     * Normalize random words input
     * 
     * Processes and validates random words input
     * 
     * @param mixed $rawInput Raw input from user
     * @return array List of random words
     */
    public static function normalizeRandomWordsInput($rawInput): array {
        if (is_array($rawInput)) {
            return array_filter(array_map('trim', $rawInput));
        }
        
        $raw = is_string($rawInput) ? $rawInput : (string)$rawInput;
        $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
        
        if ($raw === '') {
            return [];
        }
        
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    /**
     * Normalize boolean input
     * 
     * Converts various input formats to boolean
     * 
     * @param mixed $rawInput Raw input from user
     * @return bool True if enabled, false otherwise
     */
    public static function normalizeBooleanInput($rawInput): bool {
        if (is_bool($rawInput)) {
            return $rawInput;
        }
        
        if (is_numeric($rawInput)) {
            return $rawInput != 0;
        }
        
        if (is_string($rawInput)) {
            $lower = strtolower(trim($rawInput));
            return in_array($lower, ['1', 'true', 'yes', 'on', 'enabled']);
        }
        
        return false;
    }
}