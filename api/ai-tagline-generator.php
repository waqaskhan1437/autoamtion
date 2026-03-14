<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/AITaglineGenerator.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'test_api_key') {
    $apiKey = $_POST['api_key'] ?? '';
    $provider = $_POST['provider'] ?? 'gemini';
    $model = $_POST['model'] ?? 'gemini-2.5-flash';
    
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'API key is required']);
        exit;
    }
    
    if ($provider === 'gemini') {
        $result = testGeminiKey($apiKey, $model);
    } elseif ($provider === 'openai') {
        $result = testOpenAIKey($apiKey);
    } elseif ($provider === 'openrouter') {
        $result = testOpenRouterKey($apiKey);
    } else {
        $result = ['success' => false, 'error' => 'Unsupported provider: ' . $provider];
    }
    
    echo json_encode($result);
    exit;
}

if ($action === 'generate_taglines') {
    $apiKey = $_POST['api_key'] ?? '';
    $provider = $_POST['provider'] ?? 'gemini';
    $model = $_POST['model'] ?? 'gemini-2.5-flash';
    $topPrompt = $_POST['top_prompt'] ?? '';
    $bottomPrompt = $_POST['bottom_prompt'] ?? '';
    
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'API key is required']);
        exit;
    }
    
    if ($provider === 'gemini') {
        $result = generateWithGemini($apiKey, $topPrompt, $bottomPrompt, $model);
    } elseif ($provider === 'openai') {
        $result = generateWithOpenAI($apiKey, $topPrompt, $bottomPrompt);
    } elseif ($provider === 'openrouter') {
        $result = generateWithOpenRouter($apiKey, $topPrompt, $bottomPrompt);
    } else {
        $result = ['success' => false, 'error' => 'Unsupported provider: ' . $provider];
    }
    
    echo json_encode($result);
    exit;
}

if ($action === 'generate_bulk_taglines') {
    $apiKey = $_POST['api_key'] ?? '';
    $provider = $_POST['provider'] ?? 'gemini';
    $model = $_POST['model'] ?? 'gemini-2.5-flash';
    $topPrompt = $_POST['top_prompt'] ?? '';
    $bottomPrompt = $_POST['bottom_prompt'] ?? '';
    $count = min(max(intval($_POST['count'] ?? 10), 1), 100);
    
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => 'API key is required']);
        exit;
    }
    
    if ($provider === 'gemini') {
        $result = generateBulkWithGemini($apiKey, $topPrompt, $bottomPrompt, $count, $model);
    } elseif ($provider === 'openai') {
        $result = generateBulkWithOpenAI($apiKey, $topPrompt, $bottomPrompt, $count);
    } elseif ($provider === 'openrouter') {
        $result = generateBulkWithOpenRouter($apiKey, $topPrompt, $bottomPrompt, $count);
    } else {
        $result = ['success' => false, 'error' => 'Unsupported provider: ' . $provider];
    }
    
    echo json_encode($result);
    exit;
}

function testGeminiKey($apiKey, $model = 'gemini-2.5-flash') {
    // Fallback models if primary fails - latest 2026 models with more options
    $fallbackModels = ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.0-flash', 'gemini-1.5-flash'];
    if (!in_array($model, $fallbackModels)) {
        $fallbackModels = array_merge([$model], $fallbackModels);
    }
    
    $lastError = '';
    foreach ($fallbackModels as $testModel) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$testModel}:generateContent?key=" . $apiKey;
        
        $data = [
            'contents' => [['parts' => [['text' => 'Say "OK" if you can read this.']]]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 10
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $lastError = 'Connection error: ' . $error;
            continue;
        }
        
        if ($httpCode === 200) {
            return ['success' => true, 'provider' => 'gemini', 'model' => $testModel, 'message' => 'Gemini API key is valid! (Model: ' . $testModel . ')'];
        } elseif ($httpCode === 401) {
            return ['success' => false, 'error' => 'Invalid API key'];
        } elseif ($httpCode === 403) {
            return ['success' => false, 'error' => 'API key does not have permission'];
        } elseif ($httpCode === 429) {
            $lastError = 'Rate limit exceeded for ' . $testModel . ', trying fallback...';
            continue;
        } else {
            $data = json_decode($response, true);
            $errorMsg = $data['error']['message'] ?? 'Unknown error';
            if (strpos($errorMsg, ' quota') !== false || strpos($errorMsg, 'rate limit') !== false) {
                $lastError = 'Quota/rate limit for ' . $testModel . ', trying fallback...';
                continue;
            }
            return ['success' => false, 'error' => $errorMsg . ' (Model: ' . $testModel . ')'];
        }
    }
    
    return ['success' => false, 'error' => 'All Gemini models failed. ' . $lastError];
}

function testOpenAIKey($apiKey) {
    $ch = curl_init('https://api.openai.com/v1/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'Connection error: ' . $error];
    }
    
    if ($httpCode === 200) {
        return ['success' => true, 'provider' => 'openai', 'message' => 'OpenAI API key is valid!'];
    } elseif ($httpCode === 401) {
        return ['success' => false, 'error' => 'Invalid API key'];
    } else {
        $data = json_decode($response, true);
        $errorMsg = $data['error']['message'] ?? 'Unknown error';
        return ['success' => false, 'error' => $errorMsg];
    }
}

function generateWithGemini($apiKey, $topPrompt, $bottomPrompt, $model = 'gemini-2.5-flash') {
    $fallbackModels = ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.0-flash', 'gemini-1.5-flash'];
    if (!in_array($model, $fallbackModels)) {
        $fallbackModels = array_merge([$model], $fallbackModels);
    }
    
    $lastError = '';
    foreach ($fallbackModels as $testModel) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$testModel}:generateContent?key=" . $apiKey;
        
        $instructions = "Generate taglines for video text overlays.\n";
        $instructions .= "TOP tagline: SHORT catchy hook (2-4 words ONLY). This appears LARGE at top of video.\n";
        $instructions .= "BOTTOM tagline: VERY SHORT call-to-action (1-3 words ONLY). This appears SMALL at bottom.\n";
        $instructions .= "Respond ONLY in JSON format: {\"top\": \"...\", \"bottom\": \"...\"}\n\n";
        
        if (!empty($topPrompt)) {
            $instructions .= "TOP tagline theme: {$topPrompt}\n";
        }
        if (!empty($bottomPrompt)) {
            $instructions .= "BOTTOM tagline theme: {$bottomPrompt}\n";
        }
        
        $data = [
            'contents' => [['parts' => [['text' => $instructions]]]],
            'generationConfig' => [
                'temperature' => 0.9,
                'maxOutputTokens' => 200,
                'responseMimeType' => 'application/json'
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $lastError = 'Connection error: ' . $error;
            continue;
        }
        
        if ($httpCode !== 200) {
            $respData = json_decode($response, true);
            $errorMsg = $respData['error']['message'] ?? 'API error';
            if (strpos($errorMsg, 'quota') !== false || strpos($errorMsg, 'rate limit') !== false || $httpCode === 429) {
                $lastError = 'Rate limit for ' . $testModel;
                continue;
            }
            return ['success' => false, 'error' => $errorMsg . ' (Model: ' . $testModel . ')'];
        }
        
        $result = json_decode($response, true);
        $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        $content = trim($content);
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        
        $taglines = json_decode($content, true);
        
        if (!$taglines || !isset($taglines['top']) || !isset($taglines['bottom'])) {
            continue;
        }
        
        return [
            'success' => true,
            'provider' => 'gemini',
            'model' => $testModel,
            'top' => $taglines['top'],
            'bottom' => $taglines['bottom']
        ];
    }
    
    return ['success' => false, 'error' => 'All Gemini models failed. ' . $lastError];
}

function generateWithOpenAI($apiKey, $topPrompt, $bottomPrompt) {
    $instructions = "Generate taglines for video text overlays.\n";
    $instructions .= "TOP (LARGE at top): Short catchy hook (2-4 words). Examples: Birthday Bash, Love You, Congratulations\n";
    $instructions .= "BOTTOM (SMALL at bottom): Very short CTA (1-3 words). Examples: Order now, Visit us, Prankwish.com\n";
    $instructions .= "Respond ONLY in JSON: {\"top\": \"...\", \"bottom\": \"...\"}\n\n";
    
    if (!empty($topPrompt)) {
        $instructions .= "TOP theme: {$topPrompt}\n";
    }
    if (!empty($bottomPrompt)) {
        $instructions .= "BOTTOM theme: {$bottomPrompt}\n";
    }
    
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
            'messages' => [
                ['role' => 'user', 'content' => $instructions]
            ],
            'temperature' => 0.9,
            'max_tokens' => 200
        ]),
        CURLOPT_TIMEOUT => 30
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
    
    $content = trim($content);
    $content = preg_replace('/^```json\s*/i', '', $content);
    $content = preg_replace('/\s*```$/i', '', $content);
    
    $taglines = json_decode($content, true);
    
    if (!$taglines || !isset($taglines['top']) || !isset($taglines['bottom'])) {
        return ['success' => false, 'error' => 'Failed to parse AI response'];
    }
    
    return [
        'success' => true,
        'provider' => 'openai',
        'top' => $taglines['top'],
        'bottom' => $taglines['bottom']
    ];
}

function testOpenRouterKey($apiKey) {
    // Test with a simple chat completion request
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: http://localhost',
            'X-Title: Test'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'google/gemma-3-12b-it:free',
            'messages' => [
                ['role' => 'user', 'content' => 'Say OK']
            ],
            'max_tokens' => 10
        ]),
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'Connection error: ' . $error];
    }
    
    if ($httpCode === 200) {
        return ['success' => true, 'provider' => 'openrouter', 'message' => 'OpenRouter API key is valid!'];
    } elseif ($httpCode === 401) {
        return ['success' => false, 'error' => 'Invalid API key'];
    } else {
        $data = json_decode($response, true);
        $errorMsg = $data['error']['message'] ?? 'Unknown error (HTTP ' . $httpCode . ')';
        return ['success' => false, 'error' => $errorMsg];
    }
}

function generateWithOpenRouter($apiKey, $topPrompt, $bottomPrompt) {
    $instructions = "Generate taglines for video text overlays.\n";
    $instructions .= "TOP (LARGE at top): Short catchy hook (2-4 words). Examples: Birthday Bash, Love You, Congratulations\n";
    $instructions .= "BOTTOM (SMALL at bottom): Very short CTA (1-3 words). Examples: Order now, Visit us, Prankwish.com\n";
    $instructions .= "Respond ONLY in JSON: {\"top\": \"...\", \"bottom\": \"...\"}\n\n";
    
    if (!empty($topPrompt)) {
        $instructions .= "TOP theme: {$topPrompt}\n";
    }
    if (!empty($bottomPrompt)) {
        $instructions .= "BOTTOM theme: {$bottomPrompt}\n";
    }
    
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
            'temperature' => 0.9,
            'max_tokens' => 200
        ]),
        CURLOPT_TIMEOUT => 60
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
    
    $content = trim($content);
    $content = preg_replace('/^```json\s*/i', '', $content);
    $content = preg_replace('/\s*```$/i', '', $content);
    
    $taglines = json_decode($content, true);
    
    if (!$taglines || !isset($taglines['top']) || !isset($taglines['bottom'])) {
        return ['success' => false, 'error' => 'Failed to parse AI response'];
    }
    
    return [
        'success' => true,
        'provider' => 'openrouter',
        'top' => $taglines['top'],
        'bottom' => $taglines['bottom']
    ];
}

function generateBulkWithGemini($apiKey, $topPrompt, $bottomPrompt, $count, $model = 'gemini-2.5-flash') {
    $fallbackModels = ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.0-flash', 'gemini-1.5-flash'];
    if (!in_array($model, $fallbackModels)) {
        $fallbackModels = array_merge([$model], $fallbackModels);
    }
    
    $lastError = '';
    foreach ($fallbackModels as $testModel) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$testModel}:generateContent?key=" . $apiKey;
        
        $instructions = "Generate {$count} UNIQUE pairs of video taglines.\n\n";
        $instructions .= "TOP (LARGE text at top): Short catchy hook (2-4 words). Examples: Birthday Bash, Love You, Congratulations\n";
        $instructions .= "BOTTOM (SMALL text at bottom): Very short CTA (1-3 words). Examples: Order now, Visit us, Prankwish.com\n";
        $instructions .= "Theme - TOP: " . (!empty($topPrompt) ? $topPrompt : "celebration") . " | BOTTOM: " . (!empty($bottomPrompt) ? $bottomPrompt : "website") . "\n\n";
        $instructions .= "Respond ONLY in JSON array: [{\"top\": \"...\", \"bottom\": \"...\"}, ...]";
        
        $data = [
            'contents' => [['parts' => [['text' => $instructions]]]],
            'generationConfig' => [
                'temperature' => 0.95,
                'maxOutputTokens' => min($count * 50, 2000),
                'responseMimeType' => 'application/json'
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $lastError = 'Connection error: ' . $error;
            continue;
        }
        
        if ($httpCode !== 200) {
            $respData = json_decode($response, true);
            $errorMsg = $respData['error']['message'] ?? 'API error';
            if (strpos($errorMsg, 'quota') !== false || strpos($errorMsg, 'rate limit') !== false || $httpCode === 429) {
                $lastError = 'Rate limit for ' . $testModel;
                continue;
            }
            return ['success' => false, 'error' => $errorMsg . ' (Model: ' . $testModel . ')'];
        }
        
        $result = json_decode($response, true);
        $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        $content = trim($content);
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        
        $taglines = json_decode($content, true);
        
        if (!is_array($taglines)) {
            continue;
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
        
        return [
            'success' => true,
            'provider' => 'gemini',
            'model' => $testModel,
            'taglines' => $valid,
            'count' => count($valid)
        ];
    }
    
    return ['success' => false, 'error' => 'All Gemini models failed. ' . $lastError];
}

function generateBulkWithOpenAI($apiKey, $topPrompt, $bottomPrompt, $count) {
    $instructions = "Generate {$count} UNIQUE pairs of video taglines.\n\n";
    $instructions .= "TOP (LARGE text at top): Short catchy hook (2-4 words). Examples: Birthday Bash, Love You, Congratulations\n";
    $instructions .= "BOTTOM (SMALL text at bottom): Very short CTA (1-3 words). Examples: Order now, Visit us, Prankwish.com\n";
    $instructions .= "Theme - TOP: " . (!empty($topPrompt) ? $topPrompt : "celebration") . " | BOTTOM: " . (!empty($bottomPrompt) ? $bottomPrompt : "website") . "\n\n";
    $instructions .= "Respond ONLY in JSON array: [{\"top\": \"...\", \"bottom\": \"...\"}, ...]";
    
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
            'messages' => [
                ['role' => 'user', 'content' => $instructions]
            ],
            'temperature' => 0.95,
            'max_tokens' => min($count * 50, 2000)
        ]),
        CURLOPT_TIMEOUT => 60
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
    
    $content = trim($content);
    $content = preg_replace('/^```json\s*/i', '', $content);
    $content = preg_replace('/\s*```$/i', '', $content);
    
    $taglines = json_decode($content, true);
    
    if (!is_array($taglines)) {
        return ['success' => false, 'error' => 'Failed to parse AI response'];
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
    
    return [
        'success' => true,
        'provider' => 'openai',
        'taglines' => $valid,
        'count' => count($valid)
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
    
    $content = trim($content);
    $content = preg_replace('/^```json\s*/i', '', $content);
    $content = preg_replace('/\s*```$/i', '', $content);
    
    $taglines = json_decode($content, true);
    
    if (!is_array($taglines)) {
        return ['success' => false, 'error' => 'Failed to parse AI response'];
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
    
    return [
        'success' => true,
        'provider' => 'openrouter',
        'taglines' => $valid,
        'count' => count($valid)
    ];
}
