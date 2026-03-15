<?php
// Let's test the parser with actual Cohere response text
require_once 'config.php';
require_once 'api/ai-tagline-generator.php';

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

// Test the parser on the Cohere bulk tagline generation
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

echo "Calling Cohere API for bulk taglines...\n";
$callResult = callCohereAPI($apiKey, $instructions, $model, min($count * 50, 2000), true);

echo "API Response Status: " . ($callResult['success'] ? 'SUCCESS' : 'FAILED') . "\n";

if ($callResult['success']) {
    echo str_repeat("-", 50) . "\n";
    echo "Raw Text Response:\n";
    echo $callResult['text'] . "\n";
    echo str_repeat("-", 50) . "\n";
    
    $parseResult = parseCohereTaglines($callResult['text']);
    
    if ($parseResult['success']) {
        echo "✅ Parse Success! Found " . $parseResult['count'] . " taglines:\n";
        foreach ($parseResult['taglines'] as $i => $tagline) {
            echo "  " . ($i+1) . ". TOP: '" . $tagline['top'] . "' | BOTTOM: '" . $tagline['bottom'] . "'\n";
        }
    } else {
        echo "❌ Parse Failed: " . $parseResult['error'] . "\n";
        
        $jsonStr = extractJsonFromText($callResult['text']);
        echo "Extracted JSON:\n" . $jsonStr . "\n";
        
        $result = json_decode($jsonStr, true);
        if (!$result) {
            echo "JSON Decode Error: " . json_last_error_msg() . "\n";
        }
    }
} else {
    echo "❌ API Call Failed: " . $callResult['error'] . "\n";
}