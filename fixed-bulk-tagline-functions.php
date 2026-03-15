/* === Fix for 'Generated 0 taglines! (Model: command-a-03-2025)' on Cohere and OpenRouter === */

// Function to extract balanced JSON with robustness
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

// Robust tagline parser
function robustParseTaglines($text) {
    $text = trim($text);
    
    $text = preg_replace('/^```json\s*/i', '', $text);
    $text = preg_replace('/\s*```$/i', '', $text);
    
    $jsonStr = extractBalancedJson($text, '[', ']');
    if (!$jsonStr) {
        $jsonStr = extractBalancedJson($text, '{', '}');
    }
    
    if (!$jsonStr) {
        return ['success' => false, 'error' => 'No valid JSON found'];
    }
    
    $jsonStr = trim($jsonStr);
    $jsonStr = preg_replace('/\s*,\s*}/', '}', $jsonStr);
    $jsonStr = preg_replace('/\s*,\s*]/', ']', $jsonStr);
    $jsonStr = preg_replace('/\/\*[\s\S]*?\*\//', '', $jsonStr);
    $jsonStr = preg_replace('/\/\/.*$/m', '', $jsonStr);
    
    $result = json_decode($jsonStr, true);
    
    if (!$result) {
        return ['success' => false, 'error' => 'JSON decode failed: ' . json_last_error_msg()];
    }
    
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

// Apply the fix to generateBulkTaglinesWithCohere
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
    
    $parseResult = robustParseTaglines($result['text']);
    
    if (!$parseResult['success']) {
        return ['success' => false, 'error' => 'Failed to parse AI response: ' . $parseResult['error']];
    }
    
    return [
        'success' => true,
        'provider' => 'cohere',
        'model' => $model,
        'taglines' => $parseResult['taglines'],
        'count' => $parseResult['count']
    ];
}

// Apply the fix to generateBulkWithOpenRouter
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
    
    $parseResult = robustParseTaglines($content);
    
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