<?php

// Test parsing of actual Cohere bulk response
$response = '{"id":"55e054f7-fe80-48c5-a82f-055cfba34b5a","message":{"role":"assistant","content":[{"type":"text","text":"[
  {\"top\": \"Birthday Bliss\", \"bottom\": \"Order Now\"},
  {\"top\": \"Celebrate You\", \"bottom\": \"Order Today\"},
  {\"top\": \"Party Time\", \"bottom\": \"Order Here\"}
]"}],"finish_reason":"COMPLETE","usage":{"billed_units":{"input_tokens":97,"output_tokens":48},"tokens":{"input_tokens":592,"output_tokens":50},"cached_tokens":0}}}';

$text = '[
  {"top": "Birthday Bliss", "bottom": "Order Now"},
  {"top": "Celebrate You", "bottom": "Order Today"},
  {"top": "Party Time", "bottom": "Order Here"}
]';

echo "Test 1: Parsing raw response text...\n";
$taglines = json_decode($text, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "✓ json_decode succeeded\n";
    echo "Count: " . count($taglines) . "\n";
    var_dump($taglines);
} else {
    echo "✗ json_decode failed: " . json_last_error_msg() . "\n";
}

echo "\n" . str_repeat("-", 50) . "\n";

// Now let's call generateBulkTaglinesWithCohere directly with this text
echo "Test 2: Calling with cleaned text...\n";

function test_parse($text) {
    $text = trim($text);
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/\s*```$/i', '', $text);
    echo "Text after processing: \n" . $text . "\n";
    
    $taglines = json_decode($text, true);
    if (!$taglines) {
        echo "❌ json_decode failed: " . json_last_error_msg() . "\n";
        return false;
    }
    
    $valid = [];
    foreach ($taglines as $t) {
        if (isset($t['top']) && isset($t['bottom'])) {
            $valid[] = [
                'top' => trim($t['top']),
                'bottom' => trim($t['bottom'])
            ];
        } else {
            echo "⚠️ Invalid tagline: " . print_r($t, true) . "\n";
        }
    }
    
    echo "Found " . count($valid) . " valid taglines\n";
    
    return ['taglines' => $valid, 'count' => count($valid)];
}

echo "\nCalling parser...\n";
$result = test_parse($text);
if (!$result) {
    echo "Failed to parse\n";
} else {
    echo "✓ Success! Found " . $result['count'] . " taglines\n";
    foreach ($result['taglines'] as $i => $t) {
        echo "  " . ($i+1) . ". TOP: '" . $t['top'] . "', BOTTOM: '" . $t['bottom'] . "'\n";
    }
}