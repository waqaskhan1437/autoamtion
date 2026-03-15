<?php

// Include necessary files
require_once 'api/ai-tagline-generator.php';

// Test case 1: Simple array response
echo "Test 1: Simple array response\n";
$response1 = '[
  {"top": "Birthday Bliss", "bottom": "Order Now"},
  {"top": "Celebrate You", "bottom": "Order Today"},
  {"top": "Party Time", "bottom": "Order Here"}
]';
$test1 = test_parse($response1);
print_result($test1);

echo "\n" . str_repeat("-", 50) . "\n";

// Test case 2: Response with taglines wrapper
echo "Test 2: Response with taglines wrapper\n";
$response2 = '{"taglines": [
  {"top": "Birthday Bliss", "bottom": "Order Now"},
  {"top": "Celebrate You", "bottom": "Order Today"},
  {"top": "Party Time", "bottom": "Order Here"}
]}';
$test2 = test_parse($response2);
print_result($test2);

echo "\n" . str_repeat("-", 50) . "\n";

// Test case 3: Response with items wrapper
echo "Test 3: Response with items wrapper\n";
$response3 = '{"items": [
  {"top": "Birthday Bliss", "bottom": "Order Now"},
  {"top": "Celebrate You", "bottom": "Order Today"},
  {"top": "Party Time", "bottom": "Order Here"}
]}';
$test3 = test_parse($response3);
print_result($test3);

echo "\n" . str_repeat("-", 50) . "\n";

// Test case 4: Response with results wrapper
echo "Test 4: Response with results wrapper\n";
$response4 = '{"results": [
  {"top": "Birthday Bliss", "bottom": "Order Now"},
  {"top": "Celebrate You", "bottom": "Order Today"},
  {"top": "Party Time", "bottom": "Order Here"}
]}';
$test4 = test_parse($response4);
print_result($test4);

echo "\n" . str_repeat("-", 50) . "\n";

// Test case 5: Single tagline as object
echo "Test 5: Single tagline as object\n";
$response5 = '{"top": "Birthday Bliss", "bottom": "Order Now"}';
$test5 = test_parse($response5);
print_result($test5);

// Helper function to test the parsing logic
function test_parse($text) {
    $text = trim($text);
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/\s*```$/i', '', $text);
    $text = str_replace(["\\n", "\\r", "\\t"], '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    
    $taglines = json_decode($text, true);
    
    // Handle various response formats
    if (isset($taglines['top']) && isset($taglines['bottom'])) {
        $taglines = [$taglines];
    }
    if (isset($taglines['taglines']) && is_array($taglines['taglines'])) {
        $taglines = $taglines['taglines'];
    }
    if (isset($taglines['items']) && is_array($taglines['items'])) {
        $taglines = $taglines['items'];
    }
    if (isset($taglines['results']) && is_array($taglines['results'])) {
        $taglines = $taglines['results'];
    }
    
    if (!is_array($taglines)) {
        return false;
    }
    
    $valid = [];
    foreach ($taglines as $t) {
        if (isset($t['top']) && isset($t['bottom'])) {
            $valid[] = [
                'top' => trim($t['top']),
                'bottom' => trim($t['bottom'])
            ];
        }
    }
    
    return ['taglines' => $valid, 'count' => count($valid)];
}

// Helper function to print test results
function print_result($result) {
    if (!$result) {
        echo "❌ Parsing failed\n";
        return;
    }
    
    echo "✓ Success! Found " . $result['count'] . " taglines\n";
    foreach ($result['taglines'] as $i => $t) {
        echo "  " . ($i+1) . ". TOP: '" . $t['top'] . "', BOTTOM: '" . $t['bottom'] . "'\n";
    }
}
