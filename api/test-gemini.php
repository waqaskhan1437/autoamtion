<?php
/**
 * Test Gemini API Connection
 * Run this to verify your Gemini API key works
 */
header('Content-Type: application/json');

// Get API key from query string or config
$apiKey = $_GET['key'] ?? '';

if (empty($apiKey)) {
    echo json_encode([
        'success' => false,
        'error' => 'Please provide API key: test-gemini.php?key=YOUR_GEMINI_API_KEY'
    ]);
    exit;
}

// Test with gemini-2.5-flash (FREE tier)
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;

$data = [
    'contents' => [
        ['parts' => [['text' => 'Say "Hello, Gemini is working!" in exactly those words.']]]
    ],
    'generationConfig' => [
        'temperature' => 0.1,
        'maxOutputTokens' => 50
    ]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode([
        'success' => false,
        'error' => 'Connection error: ' . $error,
        'tip' => 'Make sure curl is enabled and you have internet connection'
    ]);
    exit;
}

$result = json_decode($response, true);

if ($httpCode !== 200) {
    $errorMsg = $result['error']['message'] ?? 'Unknown error';
    
    // Provide helpful tips based on error
    $tip = '';
    if (strpos($errorMsg, 'API key') !== false) {
        $tip = 'Get a FREE API key from: https://aistudio.google.com/apikey';
    } elseif (strpos($errorMsg, 'quota') !== false) {
        $tip = 'Your API key quota is exceeded. Create a new API key.';
    } elseif (strpos($errorMsg, 'not found') !== false) {
        $tip = 'Model not available. Your region may not support this model yet.';
    }
    
    echo json_encode([
        'success' => false,
        'http_code' => $httpCode,
        'error' => $errorMsg,
        'tip' => $tip,
        'model_used' => 'gemini-2.5-flash',
        'endpoint' => 'v1beta'
    ]);
    exit;
}

$content = $result['candidates'][0]['content']['parts'][0]['text'] ?? 'No response';

echo json_encode([
    'success' => true,
    'message' => 'Gemini API is working!',
    'response' => $content,
    'model' => 'gemini-2.5-flash',
    'endpoint' => 'v1beta',
    'tip' => 'Now copy the updated files to your XAMPP folder'
]);
