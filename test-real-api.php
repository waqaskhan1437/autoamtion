<?php
// Let's test actual bulk generation by calling the real API

require_once 'config.php';
require_once 'api/ai-tagline-generator.php';

// Check for API key
$stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
$stmt->execute(['cohere_api_key']);
$apiKey = $stmt->fetchColumn();

if (empty($apiKey)) {
    echo "❌ Cohere API key not found in settings. Please configure it in Settings > AI > Cohere API Key.\n";
    exit(1);
}

echo "✅ Found Cohere API key in settings\n";
echo "Key: " . substr($apiKey, 0, 8) . "****\n\n";

// Test single tagline
echo "=== Testing Single Tagline Generation ===\n";
$singleResult = generateTaglinesWithCohere($apiKey, 'birthday', 'order', 'command-a-03-2025');
if ($singleResult['success']) {
    echo "✅ Success: Top - '" . $singleResult['top'] . "', Bottom - '" . $singleResult['bottom'] . "'\n";
} else {
    echo "❌ Failed: " . $singleResult['error'] . "\n";
}

echo "\n=== Testing Bulk Tagline Generation ===\n";
echo str_repeat("-", 50) . "\n";
$count = 3;
$bulkResult = generateBulkTaglinesWithCohere($apiKey, 'birthday', 'order', $count, 'command-a-03-2025');

if ($bulkResult['success']) {
    echo "✅ Generated " . $bulkResult['count'] . " taglines\n";
    foreach ($bulkResult['taglines'] as $i => $t) {
        echo "  " . ($i+1) . ". TOP: '" . $t['top'] . "', BOTTOM: '" . $t['bottom'] . "'\n";
    }
} else {
    echo "❌ Failed: " . $bulkResult['error'] . "\n";
    if (isset($bulkResult['http_code'])) {
        echo "HTTP Code: " . $bulkResult['http_code'] . "\n";
    }
    if (isset($bulkResult['response'])) {
        echo "Response: " . $bulkResult['response'] . "\n";
    }
}