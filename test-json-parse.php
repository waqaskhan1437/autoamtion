<?php

// Test 1: Bulk tagline response
echo "Testing bulk tagline response parsing...\n";
echo "=" . str_repeat("-", 50) . "=\n";

$response = '{"id":"55e054f7-fe80-48c5-a82f-055cfba34b5a","message":{"role":"assistant","content":[{"type":"text","text":"[\n  {\"top\": \"Birthday Bliss\", \"bottom\": \"Order Now\"},\n  {\"top\": \"Celebrate You\", \"bottom\": \"Order Today\"},\n  {\"top\": \"Party Time\", \"bottom\": \"Order Here\"}\n]"}],"finish_reason":"COMPLETE","usage":{"billed_units":{"input_tokens":97,"output_tokens":48},"tokens":{"input_tokens":592,"output_tokens":50},"cached_tokens":0}}';

$data = json_decode($response, true);
$text = $data['message']['content'][0]['text'];
echo "Extracted text: \n\"" . $text . "\"\n";

$text = trim($text);
echo "Trimmed text: \n\"" . $text . "\"\n";

$text = preg_replace('/^```json\s*/i', '', $text);
$text = preg_replace('/\s*```$/i', '', $text);
echo "Code-block removed: \n\"" . $text . "\"\n";

$taglines = json_decode($text, true);
echo "json_decode result: ";
var_dump($taglines);

echo "\nType: " . gettype($taglines) . "\n";

if (is_array($taglines)) {
    echo "Count: " . count($taglines) . "\n";
    foreach ($taglines as $i => $t) {
        echo "\nTagline " . ($i+1) . ":\n";
        var_dump($t);
        if (isset($t['top']) && isset($t['bottom'])) {
            echo "✓ Valid tagline\n";
        } else {
            echo "✗ Invalid tagline (missing fields)\n";
        }
    }
}