function normalizeManualVideoLinksInput($rawInput) {
    return NormalizationHelper::normalizeManualVideoLinksInput($rawInput);
}

function normalizeYouTubeChannelUrlInput($rawInput) {
    return NormalizationHelper::normalizeYouTubeChannelUrlInput($rawInput);
}

function normalizeSourceShortsModeInput($rawInput) {
    return NormalizationHelper::normalizeSourceShortsModeInput($rawInput);
}

function normalizeSourceShortsMaxCountInput($rawInput, $mode = 'single') {
    return NormalizationHelper::normalizeSourceShortsMaxCountInput($rawInput, $mode);
}

function normalizePlaybackSpeedInput($rawInput) {
    return NormalizationHelper::normalizePlaybackSpeedInput($rawInput);
}

function normalizeCustomPromptInput($rawInput) {
    return NormalizationHelper::normalizeCustomPromptInput($rawInput);
}

function normalizeScheduleHourInput($rawInput) {
    return NormalizationHelper::normalizeScheduleHourInput($rawInput);
}