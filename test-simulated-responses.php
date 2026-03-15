<?php
// After seeing the Cohere response has JSON without code blocks, let's check parse logic

require_once 'config.php';
require_once 'api/ai-tagline-generator.php';

// Let's create a simulated Cohere response as we saw in tests
$apiKey = 'RVue6W3S8I240s8sT5yB8aB1w6x6s6r4f3f2f1'; // Test key from earlier
$topPrompt = 'birthday';
$bottomPrompt = 'order';
$count = 3;
$model = 'command-a-03-2025';

// Simulate the actual text that Cohere returns (without ```json blocks)
$simulatedText = '[
  {"top": "Birthday Bliss", "bottom": "Order Now"},
  {"top": "Celebrate You", "bottom": "Order Today"},
  {"top": "Party Time", "bottom": "Order Here"}
]';

echo "=== Testing Cohere Response Parsing ===\n";
echo str_repeat("-", 50) . "\n";

// 1. Original parsing method
echo "1. Original Parsing Method:\n";
$text1 = trim($simulatedText);
$text1 = preg_replace('/^```json\s*/i', '', $text1);
$text1 = preg_replace('/\s*```$/i', '', $text1);
$parsed1 = json_decode($text1, true);
echo "Text after cleaning: " . $text1 . "\n";
echo "json_decode result: "; var_dump($parsed1);
echo "Count: " . (is_array($parsed1) ? count($parsed1) : 0) . "\n";
if (is_array($parsed1)) {
    foreach ($parsed1 as $i => $t) {
        echo "  " . ($i+1) . ". ";
        if (isset($t['top']) && isset($t['bottom'])) {
            echo "TOP: '" . $t['top'] . "', BOTTOM: '" . $t['bottom'] . "'";
        } else {
            echo "❌ Invalid: " . print_r($t, true);
        }
        echo "\n";
    }
}
echo "\n" . str_repeat("-", 50) . "\n";

// 2. Now let's test OpenRouter parsing method
echo "2. OpenRouter Parsing Method:\n";
$text2 = $simulatedText;
$text2 = trim($text2);
$text2 = preg_replace('/^```json\s*/i', '', $text2);
$text2 = preg_replace('/\s*```$/i', '', $text2);
$parsed2 = json_decode($text2, true);
echo "Text after cleaning: " . $text2 . "\n";
echo "json_decode result: "; var_dump($parsed2);
echo "Count: " . (is_array($parsed2) ? count($parsed2) : 0) . "\n";
if (is_array($parsed2)) {
    foreach ($parsed2 as $i => $t) {
        echo "  " . ($i+1) . ". ";
        if (isset($t['top']) && isset($t['bottom'])) {
            echo "TOP: '" . $t['top'] . "', BOTTOM: '" . $t['bottom'] . "'";
        } else {
            echo "❌ Invalid: " . print_r($t, true);
        }
        echo "\n";
    }
}

echo "\n=== Testing Cohere Function with Direct Input ===\n";
function test_parse_method($text) {
    $text = trim($text);
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/\s*```$/i', '', $text);
    
    // Try to parse as JSON array
    $taglines = json_decode($text, true);
    
    if (!$taglines || !is_array($taglines)) {
        echo "❌ JSON decode failed: " . json_last_error_msg() . "\n";
        return false;
    }
    
    $valid = [];
    foreach ($taglines as $t) {
        if (isset($t['top']) && isset($t['bottom'])) {
            $valid[] = ['top' => trim($t['top']), 'bottom' => trim($t['bottom'])];
        }
    }
    
    echo "✅ Found " . count($valid) . " valid taglines\n";
    foreach ($valid as $i => $t) {
        echo "  " . ($i+1) . ". TOP: '" . $t['top'] . "', BOTTOM: '" . $t['bottom'] . "'\n";
    }
    
    return ['success' => true, 'taglines' => $valid, 'count' => count($valid)];
}

echo str_repeat("-", 50) . "\n";
echo "Testing with Cohere response without code blocks:\n";
$result1 = test_parse_method($simulatedText);
echo "\n";

// Now test with code blocks
echo str_repeat("-", 50) . "\n";
echo "Testing with code blocks:\n";
$result2 = test_parse_method('```json
[
  {"top": "Birthday Bliss", "bottom": "Order Now"},
  {"top": "Celebrate You", "bottom": "Order Today"},
  {"top": "Party Time", "bottom": "Order Here"}
]
```');