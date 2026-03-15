/* === Generated 0 taglines! (Model: command-a-03-2025) Fix === */

/* Problem Analysis: 
1. The Cohere API response may contain JSON arrays with quoted keys containing spaces or special characters
2. The current parsing method is too strict and fails to handle various valid JSON formats
3. The JSON may have trailing commas, inconsistent whitespace, or comments that cause json_decode to fail
*/

/* Solution: Implement more robust JSON extraction and parsing */

function extractJsonFromText($text) {
    $text = trim($text);
    
    // Step 1: Remove any surrounding text before and after JSON
    // Find the first '[' or '{' indicating JSON start
    $jsonStart = strpos($text, '[');
    if ($jsonStart === false) {
        $jsonStart = strpos($text, '{');
        if ($jsonStart === false) {
            return null; // No JSON found
        }
    }
    
    $text = substr($text, $jsonStart);
    
    // Step 2: Find the matching closing bracket
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
        return null; // Matching closing bracket not found
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
    
    // Clean up JSON
    $jsonStr = preg_replace('/\s*,\s*}/', '}', $jsonStr);
    $jsonStr = preg_replace('/\s*,\s*]/', ']', $jsonStr);
    $jsonStr = preg_replace('/\/\*[\s\S]*?\*\//', '', $jsonStr);
    $jsonStr = preg_replace('/\/\/.*$/m', '', $jsonStr);
    
    $result = json_decode($jsonStr, true);
    
    if (!$result) {
        return ['success' => false, 'error' => 'JSON decode failed: ' . json_last_error_msg()];
    }
    
    if (!is_array($result)) {
        $result = [$result]; // If single object, wrap in array
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

/* Test function */
function testCohereTaglineParser() {
    $testCases = [
        "Here are some taglines for your video: [\n  {\"top\": \"Birthday Bliss\", \"bottom\": \"Order Now\"},\n  {\"top\": \"Celebrate You\", \"bottom\": \"Order Today\"},\n  {\"top\": \"Party Time\", \"bottom\": \"Order Here\"}\n]",
        "```json\n[\n  {\"top\": \"Birthday Bliss\", \"bottom\": \"Order Now\"},\n  {\"top\": \"Celebrate You\", \"bottom\": \"Order Today\"},\n  {\"top\": \"Party Time\", \"bottom\": \"Order Here\"}\n]\n```",
        "{\"top\": \"Happy Birthday\", \"bottom\": \"Click Now\"}"
    ];
    
    foreach ($testCases as $i => $text) {
        echo "=== Test Case " . ($i + 1) . " ===\n";
        
        $result = parseCohereTaglines($text);
        
        if ($result['success']) {
            echo "✅ Success! Generated " . $result['count'] . " taglines:\n";
            foreach ($result['taglines'] as $j => $tagline) {
                echo "  " . ($j + 1) . ". TOP: '" . $tagline['top'] . "' | BOTTOM: '" . $tagline['bottom'] . "'\n";
            }
        } else {
            echo "❌ Failed: " . $result['error'] . "\n";
        }
        
        echo "\n";
    }
}

/* === Applying fix to Cohere and OpenRouter functions === */

function generateBulkTaglinesWithCohere($apiKey, $topPrompt, $bottomPrompt, $count, $model = 'command-a-03-2025') {
    $cohereModels = [
        'command-a-03-2025' => 'command-a-03-2025',
        'command-a-02-2025' => 'command-a-02-2025',
        'command-r-08-2025' => 'command-r-08-2025',
        'command-r7b-01-2025' => 'command-r7b-01-2025'
    ];
    
    $model = $cohereModels[$model] ?? 'command-a-03-2025';
    
    $instructions = "Generate {$count} UNIQUE pairs of video taglines.\n\n";
    $instructions .= "TOP (LARGE text at top): Short catchy hook (2-4 words). Examples: Birthday Bash, Love You, Congratulations\n";
    $instructions .= "BOTTOM (SMALL text at bottom): Very short CTA (1-3 words). Examples: Order now, Visit us, Prankwish.com\n";
    $instructions .= "Theme - TOP: " . (!empty($topPrompt) ? $topPrompt : "celebration") . " | BOTTOM: " . (!empty($bottomPrompt) ? $bottomPrompt : "website") . "\n\n";
    $instructions .= "Respond ONLY in JSON array: [{\"top\": \"...\", \"bottom\": \"...\"}, ...]";
    
    $result = callCohereAPI($apiKey, $instructions, $model, min($count * 50, 2000), true);
    
    if (!$result['success']) {
        return $result;
    }
    
    $content = $result['text'];
    
    // Use our robust parser instead of simple preg_replace
    $parseResult = parseCohereTaglines($content);
    
    if (!$parseResult['success']) {
        return ['success' => false, 'error' => 'Failed to parse AI response: ' . $parseResult['error'], 'text' => $content];
    }
    
    return [
        'success' => true,
        'provider' => 'cohere',
        'model' => $model,
        'taglines' => $parseResult['taglines'],
        'count' => $parseResult['count']
    ];
}

function generateBulkWithOpenRouter($apiKey, $topPrompt, $bottomPrompt, $count) {
    $instructions = "Generate {$count} UNIQUE pairs of video taglines.\n\n";
    $instructions .= "TOP (LARGE text at top): Short catchy hook (2-4 words). Examples: Birthday Bash, Love You, Congratulations\n";
    $instructions .= "BOTTOM (SMALL text at bottom): Very short CTA (1-3 words). Examples: Order now, Visit us, Prankwish.com\n";
    $instructions .= "Theme - TOP: " . (!empty($topPrompt) ? $topPrompt : "celebration") . " | BOTTOM: " . (!empty($bottomPrompt) ? $bottomPrompt : "website") . "\n\n";
    $instructions .= "Respond ONLY in JSON array: [{\"top\": \"...\", \"bottom\": \"...\"}, ...]";
    
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: http://localhost',
            'X-Title: AI Tagline Generator'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'openrouter/free',
            'messages' => [
                ['role' => 'user', 'content' => $instructions]
            ],
            'temperature' => 0.95,
            'max_tokens' => min($count * 50, 2000)
        ]),
        CURLOPT_TIMEOUT => 90
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'Connection error: ' . $error];
    }
    
    if ($httpCode !== 200) {
        $data = json_decode($response, true);
        $errorMsg = $data['error']['message'] ?? 'API error';
        return ['success' => false, 'error' => $errorMsg];
    }
    
    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    
    $parseResult = parseCohereTaglines($content);
    
    if (!$parseResult['success']) {
        return ['success' => false, 'error' => 'Failed to parse AI response: ' . $parseResult['error']];
    }
    
    return [
        'success' => true,
        'provider' => 'openrouter',
        'taglines' => $parseResult['taglines'],
        'count' => $parseResult['count']
    ];
}