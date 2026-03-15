<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$apiKey = $_POST['api_key'] ?? '';
$model = $_POST['model'] ?? 'command-a-03-2025';

if (empty($apiKey)) {
    echo json_encode(['success' => false, 'error' => 'API key is required']);
    exit;
}

$cohereModels = [
    'command-a-03-2025' => 'command-a-03-2025',
    'command-a-02-2025' => 'command-a-02-2025',
    'command-r-08-2025' => 'command-r-08-2025',
    'command-r7b-01-2025' => 'command-r7b-01-2025'
];

$model = $cohereModels[$model] ?? 'command-a-03-2025';

$url = 'https://api.cohere.com/v2/chat';

$data = [
    'model' => $model,
    'messages' => [
        ['role' => 'user', 'content' => 'Say "OK" if you can read this.']
    ],
    'max_tokens' => 10
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(['success' => false, 'error' => 'Connection error: ' . $error]);
    exit;
}

if ($httpCode === 200) {
    $result = json_decode($response, true);
    $text = $result['message']['content'][0]['text'] ?? '';
    echo json_encode(['success' => true, 'provider' => 'cohere', 'model' => $model, 'message' => 'Cohere API key is valid! (Model: ' . $model . ')']);
} elseif ($httpCode === 401) {
    echo json_encode(['success' => false, 'error' => 'Invalid API key']);
} elseif ($httpCode === 429) {
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Please try again later.']);
} else {
    $data = json_decode($response, true);
    $errorMsg = $data['message'] ?? 'Unknown error (HTTP ' . $httpCode . ')';
    echo json_encode(['success' => false, 'error' => $errorMsg]);
}
