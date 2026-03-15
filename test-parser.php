<?php

// Test the robust tagline parser
function extractJsonFromText($text) {
    $text = trim($text);
    
    $jsonStart = strpos($text, '[');
    if ($jsonStart === false) {
        $jsonStart = strpos($text, '{');
        if ($jsonStart === false) {
            return null;
        }
    }
    
    $text = substr($text, $jsonStart);
    
    $bracketCount = 0;
    $jsonEnd = -1;
    
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
        $char = $text[$i];
        
        if ($char === '{' || $char === '[') {
            $bracketCount++;
        } elseif ($char === '}' || $char === ']') {
            $bracketCount--;
            
            if ($bracketCount === 0) {
                $jsonEnd = $i;
                break;
            }
        }
    }
    
    if ($jsonEnd === -1) {
        return null;
    }
    
    return substr($text, 0, $jsonEnd + 1);
}

function parseCohereTaglines($text) {
    $text = trim($text);
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/\s*```$/i', '', $text);
    
    $jsonStr = extractJsonFromText($text);
    if (!$jsonStr) {
        return ['success' => false, 'error' => 'No valid JSON found'];
    }
    
    $jsonStr = preg_replace('/\s*,\s*}/', '}', $jsonStr);
    $jsonStr = preg_replace('/\s*,\s*]/', ']', $jsonStr);
    $jsonStr = preg_replace('/\/\*[\s\S]*?\*\//', '', $jsonStr);
    $jsonStr = preg_replace('/\/\/.*$/m', '', $jsonStr);
    
    $result = json_decode($jsonStr, true);
    
    if (!$result) {
        return ['success' => false, 'error' => 'JSON decode failed: ' . json_last_error_msg()];
    }
    
    if (!is_array($result)) {
        $result = [$result];
    }
    
    $valid = [];
    foreach ($result as $tagline) {
        if (isset($tagline['top']) && isset($tagline['bottom'])) {
            $valid[] = [
                'top' => trim($tagline['top']),
                'bottom' => trim($tagline['bottom'])
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

// Test the parser with different inputs
$testCases = [
    "Here are some taglines for your video: [\n  {\"top\": \"Birthday Bliss\", \"bottom\": \"Order Now\"},\n  {\"top\": \"Celebrate You\", \"bottom\": \"Order Today\"},\n  {\"top\": \"Party Time\", \"bottom\": \"Order Here\"}\n]",
    "```json\n[\n  {\"top\": \"Birthday Bliss\", \"bottom\": \"Order Now\"},\n  {\"top\": \"Celebrate You\", \"bottom\": \"Order Today\"},\n  {\"top\": \"Party Time\", \"bottom\": \"Order Here\"}\n]\n```",
    "{\"top\": \"Happy Birthday\", \"bottom\": \"Click Now\"}"
];

echo "=== Testing Robust Tagline Parser ===\n";
echo str_repeat("-", 60) . "\n";

foreach ($testCases as $i => $text) {
    echo "Test Case " . ($i + 1) . ":\n";
    
    $result = parseCohereTaglines($text);
    
    if ($result['success']) {
        echo "✅ Success! Generated " . $result['count'] . " taglines:\n";
        foreach ($result['taglines'] as $j => $tagline) {
            echo "  " . ($j + 1) . ". TOP: '" . $tagline['top'] . "' | BOTTOM: '" . $tagline['bottom'] . "'\n";
        }
    } else {
        echo "❌ Failed: " . $result['error'] . "\n";
    }
    
    echo str_repeat("-", 60) . "\n";
}