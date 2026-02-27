<?php
/**
 * Local Tagline Generator API
 * Generates unique taglines WITHOUT calling AI API
 * Uses templates + word combinations - UNLIMITED, NO RATE LIMITS!
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/LocalTaglineGenerator.php';

$input = json_decode(file_get_contents('php://input'), true);
$instructions = $input['prompt'] ?? $input['instructions'] ?? '';
$customWords = $input['words'] ?? $input['random_words'] ?? '';
$count = min(max((int)($input['count'] ?? 5), 1), 50); // Up to 50 at once!

// Parse custom words
$wordsArray = [];
if (!empty($customWords)) {
    if (is_string($customWords)) {
        $wordsArray = array_map('trim', explode(',', $customWords));
    } else {
        $wordsArray = $customWords;
    }
}

try {
    $generator = new LocalTaglineGenerator($instructions, $wordsArray);
    $taglines = $generator->generateBulk($count);
    
    echo json_encode([
        'success' => true,
        'taglines' => $taglines,
        'count' => count($taglines),
        'words_used' => $generator->getWords(),
        'provider' => 'local',
        'message' => 'Generated locally - no API limits!'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Generation error: ' . $e->getMessage()
    ]);
}
