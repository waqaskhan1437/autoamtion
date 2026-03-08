<?php

class ShortSegmentPlanner
{
    public const MODE_SINGLE = 'single';
    public const MODE_DURATION_BASED = 'duration_based';
    public const MODE_FIXED_COUNT = 'fixed_count';

    public static function normalizeMode($value): string
    {
        $mode = strtolower(trim((string)$value));
        $allowed = [
            self::MODE_SINGLE,
            self::MODE_DURATION_BASED,
            self::MODE_FIXED_COUNT
        ];

        return in_array($mode, $allowed, true) ? $mode : self::MODE_SINGLE;
    }

    public static function normalizeMaxCount($value): int
    {
        $count = (int)$value;
        if ($count < 1) {
            return 1;
        }
        if ($count > 20) {
            return 20;
        }
        return $count;
    }

    public static function buildPlan(
        float $totalDuration,
        int $shortDuration,
        $mode,
        $maxCount,
        int $singleStartTime = 0
    ): array {
        $mode = self::normalizeMode($mode);
        $maxCount = self::normalizeMaxCount($maxCount);
        $shortDuration = max(5, min(180, (int)$shortDuration));
        $totalDuration = max(0.0, $totalDuration);

        if ($mode === self::MODE_SINGLE) {
            return [
                'mode' => $mode,
                'total_duration' => $totalDuration,
                'segments' => [[
                    'index' => 1,
                    'start' => max(0, (int)$singleStartTime),
                    'duration' => self::resolveSegmentDuration($totalDuration, (int)$singleStartTime, $shortDuration)
                ]]
            ];
        }

        $minTailSeconds = max(15, (int)floor($shortDuration * 0.5));
        $possibleCount = self::calculatePossibleCount($totalDuration, $shortDuration, $minTailSeconds);

        if ($mode === self::MODE_DURATION_BASED) {
            $targetCount = min($possibleCount, $maxCount);
        } else {
            $targetCount = min(max(1, $maxCount), $possibleCount);
        }

        $segments = [];
        for ($i = 0; $i < $targetCount; $i++) {
            $start = $i * $shortDuration;
            $segmentDuration = self::resolveSegmentDuration($totalDuration, $start, $shortDuration);

            if ($segmentDuration < $minTailSeconds && $i > 0) {
                break;
            }

            $segments[] = [
                'index' => count($segments) + 1,
                'start' => $start,
                'duration' => $segmentDuration
            ];
        }

        if (empty($segments)) {
            $segments[] = [
                'index' => 1,
                'start' => max(0, (int)$singleStartTime),
                'duration' => self::resolveSegmentDuration($totalDuration, (int)$singleStartTime, $shortDuration)
            ];
            $mode = self::MODE_SINGLE;
        }

        return [
            'mode' => $mode,
            'total_duration' => $totalDuration,
            'segments' => $segments
        ];
    }

    private static function calculatePossibleCount(float $totalDuration, int $shortDuration, int $minTailSeconds): int
    {
        if ($totalDuration <= 0) {
            return 1;
        }

        $fullSegments = (int)floor($totalDuration / $shortDuration);
        $remainder = $totalDuration - ($fullSegments * $shortDuration);

        if ($fullSegments < 1) {
            return 1;
        }

        if ($remainder >= $minTailSeconds) {
            return $fullSegments + 1;
        }

        return $fullSegments;
    }

    private static function resolveSegmentDuration(float $totalDuration, int $start, int $shortDuration): int
    {
        if ($totalDuration <= 0) {
            return $shortDuration;
        }

        $remaining = (int)floor($totalDuration - $start);
        if ($remaining <= 0) {
            return $shortDuration;
        }

        return max(1, min($shortDuration, $remaining));
    }
}
