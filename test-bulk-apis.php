<?php
require_once __DIR__ . '/config.php';

$pdo = new PDO("mysql:host=$host;port=3306;dbname=$dbname", $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Testing Bulk Tagline Generation ===\n\n";

$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('cohere_api_key', 'openrouter_api_key')");
$stmt->execute();
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$cohereKey = $settings['cohere_api_key'] ?? '';
$openrouterKey = $settings['openrouter_api_key'] ?? '';

echo "Cohere API Key: " . (empty($cohereKey) ? "NOT SET" : substr($cohereKey, 0, 10) . "...") . "\n";
echo "OpenRouter API Key: " . (empty($openrouterKey) ? "NOT SET" : substr($openrouterKey, 0, 10) . "...") . "\n\n";

require_once __DIR__ . '/api/ai-tagline-generator.php';

$count = 5;

if (!empty($cohereKey)) {
    echo "=== Testing Cohere Bulk Taglines ($count count) ===\n";
    $result = generateBulkTaglinesWithCohere($cohereKey, 'birthday', 'order now', $count, 'command-a-03-2025');
    echo "Result: ";
    print_r($result);
    echo "\n";
}

if (!empty($openrouterKey)) {
    echo "=== Testing OpenRouter Bulk Taglines ($count count) ===\n";
    $result = generateBulkWithOpenRouter($openrouterKey, 'birthday', 'order now', $count);
    echo "Result: ";
    print_r($result);
    echo "\n";
}

if (!empty($cohereKey)) {
    echo "=== Testing Cohere Social Content ($count count) ===\n";
    $result = generateSocialContentWithCohere($cohereKey, 'birthday video', 'youtube', $count, 'command-a-03-2025');
    echo "Result: ";
    print_r($result);
    echo "\n";
}

if (!empty($openrouterKey)) {
    echo "=== Testing OpenRouter Social Content ($count count) ===\n";
    $result = generateSocialContentWithOpenRouter($openrouterKey, 'birthday video', 'youtube', $count);
    echo "Result: ";
    print_r($result);
    echo "\n";
}

echo "=== Tests Complete ===\n";
