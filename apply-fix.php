<?php
// Now let's apply the fix to the actual code

require_once 'config.php';
require_once 'api/ai-tagline-generator.php';

// Function to extract balanced JSON from response
function extractBalancedJson($text, $openChar, $closeChar) {
    $start = strpos($text, $openChar);
    if ($start === false) {
        return null;
    }
    
    $stack = 1;
    $pos = $start + 1;
    $length = strlen($text);
    
    while ($stack > 0 && $pos < $length) {
        if ($text[$pos] === $openChar) {
            $stack++;
        } elseif ($text[$pos] === $closeChar) {
            $stack--;
        }
        $pos++;
    }
    
    if ($stack === 0) {
        return substr($text, $start, $pos - $start);
    }
    
    return null;
}

// Function to clean and parse taglines with robustness
function robustParseTaglines($text) {
    $text = trim($text);
    
    // Remove markdown code blocks
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/\s*```$/i', '', $text);
    
    // First, try to extract JSON
    $jsonStr = extractBalancedJson($text, '[', ']');
    if (!$jsonStr) {
        $jsonStr = extractBalancedJson($text, '{', '}');
    }
    
    if (!$jsonStr) {
        return ['success' => false, 'error' => 'No valid JSON object or array found'];
    }
    
    // Fix common JSON issues
    $jsonStr = trim($jsonStr);
    
    // Remove trailing commas
    $jsonStr = preg_replace('/\s*,\s*}/', '}', $jsonStr);
    $jsonStr = preg_replace('/\s*,\s*]/', ']', $jsonStr);
    
    // Remove comments
    $jsonStr = preg_replace('/\/\*[\s\S]*?\*\//', '', $jsonStr);
    $jsonStr = preg_replace('/\/\/.*$/m', '', $jsonStr);
    
    $result = json_decode($jsonStr, true);
    
    if (!$result) {
        return ['success' => false, 'error' => 'JSON decode failed: ' . json_last_error_msg()];
    }
    
    // Handle single object vs array
    if (!is_array($result) || isset($result['top'])) {
        $result = [$result];
    }
    
    $valid = [];
    foreach ($result as $item) {
        if (is_array($item) && isset($item['top']) && isset($item['bottom'])) {
            $valid[] = [
                'top' => trim($item['top']),
                'bottom' => trim($item['bottom'])
            ];
        }
    }
    
    if (empty($valid)) {
        return ['success' => false, 'error' => 'No valid taglines found in response'];
    }
    
    return [
        'success' => true,
        'taglines' => $valid,
        'count' => count($valid)
    ];
}

// Test the function
function testFix() {
    // Try with real Cohere response
    echo "=== Testing Cohere Fix ===\n";
    echo "Calling Cohere API...\n";
    
    $apiKey = 'RVue6W3S8I240s8sT5yB8aB1w6x6s6r4f3f2f1';
    $topPrompt = 'birthday';
    $bottomPrompt = 'order';
    $count = 3;
    $model = 'command-a-03-2025';
    
    $instructions = "Generate {$count} UNIQUE pairs of video taglines.\n\n";
    $instructions .= "TOP (LARGE text at top): Short catchy hook (2-4 words). Examples: Birthday Bash, Love You, Congratulations\n";
    $instructions .= "BOTTOM (SMALL text at bottom): Very short CTA (1-3 words). Examples: Order now, Visit us, Prankwish.com\n";
    $instructions .= "Theme - TOP: " . (!empty($topPrompt) ? $topPrompt : "celebration") . " | BOTTOM: " . (!empty($bottomPrompt) ? $bottomPrompt : "website") . "\n\n";
    $instructions .= "Respond ONLY in JSON array: [{\"top\": \"...\", \"bottom\": \"...\"}, ...]";
    
    $callResult = callCohereAPI($apiKey, $instructions, $model, min($count * 50, 2000), true);
    
    if (!$callResult['success']) {
        echo "❌ API Call Failed: " . $callResult['error'] . "\n";
        return;
    }
    
    echo "\nAPI Response Received:\n";
    echo $callResult['text'] . "\n";
    
    $parseResult = robustParseTaglines($callResult['text']);
    
    if ($parseResult['success']) {
        echo "\n✅ Success! Generated " . $parseResult['count'] . " taglines:\n";
        foreach ($parseResult['taglines'] as $i => $tagline) {
            echo "  " . ($i + 1) . ". TOP: '" . $tagline['top'] . "' | BOTTOM: '" . $tagline['bottom'] . "'\n";
        }
    } else {
        echo "\n❌ Parsing Failed: " . $parseResult['error'] . "\n";
    }
}

// Test the fix
testFix();