<?php
/**
 * Generate Bulk Taglines API
 * Creates multiple unique taglines at once using Gemini (FREE) or OpenAI
 * Includes rate limit handling with automatic retry
 */
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Config error: ' . $e->getMessage()]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$prompt = $input['prompt'] ?? 'Generate creative taglines for viral videos';
$count = min(max((int)($input['count'] ?? 5), 1), 10);
$existing = $input['existing'] ?? [];

// Get AI settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('gemini_api_key', 'openai_api_key', 'ai_provider')");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$geminiKey = $settings['gemini_api_key'] ?? null;
$openaiKey = $settings['openai_api_key'] ?? null;
$provider = $settings['ai_provider'] ?? 'gemini';

// Determine provider
$apiKey = null;
$useProvider = null;

if ($provider === 'gemini' && $geminiKey) {
    $apiKey = $geminiKey;
    $useProvider = 'gemini';
} elseif ($provider === 'openai' && $openaiKey) {
    $apiKey = $openaiKey;
    $useProvider = 'openai';
} elseif ($geminiKey) {
    $apiKey = $geminiKey;
    $useProvider = 'gemini';
} elseif ($openaiKey) {
    $apiKey = $openaiKey;
    $useProvider = 'openai';
}

if (!$apiKey) {
    echo json_encode([
        'success' => false, 
        'error' => 'No AI API key configured. Add Gemini or OpenAI key in Settings.'
    ]);
    exit;
}

// Build prompt for bulk generation
$existingText = '';
if (!empty($existing)) {
    $existingList = array_map(function($t) {
        return "- " . ($t['top'] ?? '') . " | " . ($t['bottom'] ?? '');
    }, array_slice($existing, -10));
    $existingText = "\n\nALREADY USED (avoid similar):\n" . implode("\n", $existingList);
}

$fullPrompt = "Generate exactly {$count} UNIQUE pairs of TOP and BOTTOM taglines for viral video content.\n\n";
$fullPrompt .= "USER INSTRUCTIONS: {$prompt}{$existingText}\n\n";
$fullPrompt .= "RULES:\n";
$fullPrompt .= "- TOP: 3-6 word catchy headline/greeting\n";
$fullPrompt .= "- BOTTOM: 3-6 word call-to-action\n";
$fullPrompt .= "- Make each pair UNIQUE and creative\n\n";
$fullPrompt .= "RESPOND ONLY WITH JSON ARRAY:\n";
$fullPrompt .= "[{\"top\": \"Text 1\", \"bottom\": \"CTA 1\"}, {\"top\": \"Text 2\", \"bottom\": \"CTA 2\"}, ...]";

// Call API with retry logic for rate limits
$maxRetries = 3;
$retryDelay = 2; // seconds

for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
    $result = callAI($useProvider, $apiKey, $fullPrompt);
    
    if ($result['success']) {
        echo json_encode($result);
        exit;
    }
    
    // Check if it's a rate limit error
    $errorMsg = strtolower($result['error'] ?? '');
    $isRateLimit = (strpos($errorMsg, 'quota') !== false || 
                    strpos($errorMsg, 'rate') !== false || 
                    strpos($errorMsg, 'limit') !== false ||
                    strpos($errorMsg, '429') !== false);
    
    if ($isRateLimit && $attempt < $maxRetries) {
        // Extract wait time if mentioned in error
        if (preg_match('/retry in (\d+\.?\d*)/i', $result['error'], $matches)) {
            $waitTime = min(ceil((float)$matches[1]), 60);
        } else {
            $waitTime = $retryDelay * $attempt; // Exponential backoff
        }
        
        // Wait and retry
        sleep(min($waitTime, 10)); // Max wait 10 seconds
        continue;
    }
    
    // Non-rate-limit error or final attempt - return error
    echo json_encode($result);
    exit;
}

// If we get here, all retries failed
echo json_encode([
    'success' => false,
    'error' => 'Rate limit exceeded. Please wait a minute and try again.',
    'taglines' => []
]);

function callAI($provider, $apiKey, $prompt) {
    if ($provider === 'gemini') {
        return callGemini($apiKey, $prompt);
    } else {
        return callOpenAI($apiKey, $prompt);
    }
}

function callGemini($apiKey, $prompt) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.9, 'maxOutputTokens' => 500]
        ]),
        CURLOPT_TIMEOUT => 45
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['success' => false, 'error' => 'Connection error: ' . $curlError, 'taglines' => []];
    }
    
    if ($httpCode === 429 || $httpCode === 503) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'Rate limit exceeded';
        return ['success' => false, 'error' => $errorMsg, 'taglines' => []];
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'Gemini API error';
        return ['success' => false, 'error' => $errorMsg, 'taglines' => []];
    }
    
    $data = json_decode($response, true);
    $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    
    return parseTaglines($content, 'gemini');
}

function callOpenAI($apiKey, $prompt) {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.9,
            'max_tokens' => 500
        ]),
        CURLOPT_TIMEOUT => 45
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['success' => false, 'error' => 'Connection error: ' . $curlError, 'taglines' => []];
    }
    
    if ($httpCode === 429) {
        return ['success' => false, 'error' => 'OpenAI rate limit exceeded. Please wait.', 'taglines' => []];
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'OpenAI API error';
        return ['success' => false, 'error' => $errorMsg, 'taglines' => []];
    }
    
    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    
    return parseTaglines($content, 'openai');
}

function parseTaglines($content, $provider) {
    $content = trim($content);
    
    // Remove markdown code blocks
    $content = preg_replace('/^```json\s*/i', '', $content);
    $content = preg_replace('/\s*```$/i', '', $content);
    $content = preg_replace('/^```\s*/i', '', $content);
    
    // Try to parse as JSON array
    $taglines = json_decode($content, true);
    
    if (is_array($taglines) && count($taglines) > 0) {
        // Validate each tagline
        $valid = [];
        foreach ($taglines as $t) {
            if (isset($t['top']) && isset($t['bottom']) && 
                is_string($t['top']) && is_string($t['bottom']) &&
                strlen(trim($t['top'])) > 0 && strlen(trim($t['bottom'])) > 0) {
                $valid[] = [
                    'top' => trim($t['top']),
                    'bottom' => trim($t['bottom'])
                ];
            }
        }
        
        if (count($valid) > 0) {
            return [
                'success' => true,
                'taglines' => $valid,
                'count' => count($valid),
                'provider' => $provider
            ];
        }
    }
    
    // Fallback: Try to extract JSON from content
    if (preg_match('/\[\s*\{.*\}\s*\]/s', $content, $matches)) {
        $taglines = json_decode($matches[0], true);
        if (is_array($taglines)) {
            $valid = [];
            foreach ($taglines as $t) {
                if (isset($t['top']) && isset($t['bottom'])) {
                    $valid[] = ['top' => trim($t['top']), 'bottom' => trim($t['bottom'])];
                }
            }
            if (count($valid) > 0) {
                return ['success' => true, 'taglines' => $valid, 'count' => count($valid), 'provider' => $provider];
            }
        }
    }
    
    return [
        'success' => false,
        'error' => 'Failed to parse AI response. Try again.',
        'taglines' => [],
        'raw' => substr($content, 0, 200)
    ];
}
