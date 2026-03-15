<?php

require_once 'config.php';

// Get Cohere API key from settings
$stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
$stmt->execute(['cohere_api_key']);
$cohereKey = $stmt->fetchColumn();

if (empty($cohereKey)) {
    echo "Cohere API key not found in settings.\n";
    echo "Please add your Cohere API key in Settings > AI > Cohere API Key\n";
    exit(1);
}

echo "Testing Cohere API with key: " . substr($cohereKey, 0, 8) . "****\n";
echo "=" . str_repeat("-", 50) . "=\n";

// Test 1: Single tagline generation
echo "1. Testing single tagline generation...\n";
$instructions = "Generate taglines for video text overlays.\n";
$instructions .= "TOP (LARGE at top): Short catchy hook (2-4 words). Examples: Birthday Bash, Love You, Congratulations\n";
$instructions .= "BOTTOM (SMALL at bottom): Very short CTA (1-3 words). Examples: Order now, Visit us, Prankwish.com\n";
$instructions .= "Respond ONLY in JSON: {\"top\": \"...\", \"bottom\": \"...\"}\n\n";
$instructions .= "TOP theme: birthday\n";
$instructions .= "BOTTOM theme: order\n";

// Call Cohere API
$ch = curl_init('https://api.cohere.com/v2/chat');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $cohereKey,
        'Cohere-Version: 2024-02-15'
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'command-a-03-2025',
        'messages' => [
            ['role' => 'user', 'content' => $instructions]
        ],
        'max_tokens' => 200
    ]),
    CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
if ($error) {
    echo "CURL Error: " . $error . "\n";
}

echo "Raw Response: " . $response . "\n";

// Parse response
$data = json_decode($response, true);

echo "=" . str_repeat("-", 50) . "=\n";
// Test 2: Bulk tagline generation
echo "2. Testing bulk tagline generation (3 taglines)...\n";

$instructions2 = "Generate 3 UNIQUE pairs of video taglines.\n\n";
$instructions2 .= "TOP (LARGE text at top): Short catchy hook (2-4 words). Examples: Birthday Bash, Love You, Congratulations\n";
$instructions2 .= "BOTTOM (SMALL text at bottom): Very short CTA (1-3 words). Examples: Order now, Visit us, Prankwish.com\n";
$instructions2 .= "Theme - TOP: birthday | BOTTOM: order\n\n";
$instructions2 .= "Respond ONLY in JSON array: [{\"top\": \"...\", \"bottom\": \"...\"}, ...]";

$ch2 = curl_init('https://api.cohere.com/v2/chat');
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $cohereKey,
        'Cohere-Version: 2024-02-15'
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'command-a-03-2025',
        'messages' => [
            ['role' => 'user', 'content' => $instructions2]
        ],
        'max_tokens' => 500
    ]),
    CURLOPT_TIMEOUT => 60
]);

$response2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
$error2 = curl_error($ch2);
curl_close($ch2);

echo "HTTP Code: " . $httpCode2 . "\n";
if ($error2) {
    echo "CURL Error: " . $error2 . "\n";
}

echo "Raw Response: " . $response2 . "\n";
$json2 = json_decode($response2, true);
if (isset($json2['text'])) {
    $content2 = trim($json2['text']);
    $content2 = preg_replace('/^```json\s*/i', '', $content2);
    $content2 = preg_replace('/\s*```$/i', '', $content2);
    echo "\nCleaned Content:\n" . $content2 . "\n";
    
    $taglines = json_decode($content2, true);
    if (is_array($taglines)) {
        echo "\nParsed as array: " . count($taglines) . " taglines\n";
        foreach ($taglines as $i => $t) {
            echo "  " . ($i+1) . ". " . print_r($t, true);
        }
    } else {
        echo "\nJSON decode failed: " . json_last_error_msg() . "\n";
    }
}

?>