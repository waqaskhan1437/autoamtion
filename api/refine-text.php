<?php
/**
 * AI Text Refinement API
 * Takes user prompt and generates refined overlay text using Gemini (FREE) or OpenAI
 */
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/AITaglineGenerator.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Config error: ' . $e->getMessage()]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$prompt = $input['prompt'] ?? '';
$position = $input['position'] ?? 'top';

if (empty($prompt)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a prompt']);
    exit;
}

// Get AI settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('gemini_api_key', 'openai_api_key', 'ai_provider')");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$geminiKey = $settings['gemini_api_key'] ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : null);
$openaiKey = $settings['openai_api_key'] ?? (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : null);
$provider = $settings['ai_provider'] ?? 'gemini';

// Determine which API to use (prefer Gemini - it's FREE)
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
        'error' => 'No AI API key configured. Add Gemini (FREE) or OpenAI key in Settings → API Keys'
    ]);
    exit;
}

// Create refinement prompt
$positionDesc = $position === 'top' ? 'TOP overlay' : 'BOTTOM overlay';
$positionHint = $position === 'top' ? 
    'catchy headline or greeting (3-7 words)' :
    'call-to-action or engagement prompt (3-7 words)';

$fullPrompt = "Generate ONE {$positionDesc} text for a viral video.\n\n";
$fullPrompt .= "USER'S INSTRUCTION: {$prompt}\n\n";
$fullPrompt .= "REQUIREMENTS: {$positionHint}, engaging, short, punchy.\n\n";
$fullPrompt .= "RESPOND IN JSON ONLY: {\"text\": \"YOUR TEXT\", \"understanding\": \"Brief confirmation\"}";

// Call API based on provider
if ($useProvider === 'gemini') {
    // Google Gemini (FREE) - Using 2.0-flash for better rate limits
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'contents' => [['parts' => [['text' => $fullPrompt]]]],
            'generationConfig' => ['temperature' => 0.8, 'maxOutputTokens' => 150]
        ]),
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'Connection error: ' . $curlError]);
        exit;
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'Gemini API failed';
        echo json_encode(['success' => false, 'error' => $errorMsg]);
        exit;
    }
    
    $data = json_decode($response, true);
    $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    
} else {
    // OpenAI
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
                ['role' => 'user', 'content' => $fullPrompt]
            ],
            'temperature' => 0.8,
            'max_tokens' => 150
        ]),
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'Connection error: ' . $curlError]);
        exit;
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'OpenAI API failed';
        echo json_encode(['success' => false, 'error' => $errorMsg]);
        exit;
    }
    
    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
}

// Clean and parse JSON
$content = trim($content);
$content = preg_replace('/^```json\s*/', '', $content);
$content = preg_replace('/\s*```$/', '', $content);
$content = preg_replace('/^```\s*/', '', $content);

$result = json_decode($content, true);

if (!$result || !isset($result['text'])) {
    // Fallback: try to extract just the text
    echo json_encode([
        'success' => true,
        'text' => trim($content, '"'),
        'understanding' => 'Generated using ' . ucfirst($useProvider),
        'position' => $position,
        'provider' => $useProvider
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'text' => $result['text'],
    'understanding' => $result['understanding'] ?? 'Generated using ' . ucfirst($useProvider),
    'position' => $position,
    'provider' => $useProvider
]);
