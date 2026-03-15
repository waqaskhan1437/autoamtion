<?php
require_once 'config.php';

// Simple function to test what's happening
function debug_call_cohere() {
    $apiKey = 'RVue6W3S8I240s8sT5yB8aB1w6x6s6r4f3f2f1'; // From test
    
    $instructions = "Generate 3 UNIQUE pairs of video taglines.\n\n";
    $instructions .= "TOP (LARGE text at top): Short catchy hook (2-4 words). Examples: Birthday Bash, Love You, Congratulations\n";
    $instructions .= "BOTTOM (SMALL text at bottom): Very short CTA (1-3 words). Examples: Order now, Visit us, Prankwish.com\n";
    $instructions .= "Theme - TOP: birthday | BOTTOM: order\n\n";
    $instructions .= "Respond ONLY in JSON array: [{\"top\": \"...\", \"bottom\": \"...\"}, ...]";

    $data = [
        'model' => 'command-a-03-2025',
        'messages' => [
            ['role' => 'user', 'content' => $instructions]
        ],
        'max_tokens' => 500
    ];

    $ch = curl_init('https://api.cohere.com/v2/chat');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'Cohere-Version: 2024-02-15'
        ],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    echo "HTTP Code: " . $httpCode . "\n";
    if ($error) {
        echo "CURL Error: " . $error . "\n";
    }

    echo "Raw Response:\n";
    var_dump($response);

    $json = json_decode($response, true);
    
    if (isset($json['message']['content'][0]['text'])) {
        $text = $json['message']['content'][0]['text'];
        echo "\nExtracted Text:\n";
        var_dump($text);

        $text = trim($text);
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i', '', $text);
        
        echo "\nCleaned Text:\n";
        var_dump($text);
        
        $parsed = json_decode($text, true);
        
        echo "\nParsed JSON:\n";
        var_dump($parsed);
        
        if (is_array($parsed)) {
            $valid = [];
            foreach ($parsed as $t) {
                if (isset($t['top']) && isset($t['bottom'])) {
                    $valid[] = ['top' => trim($t['top']), 'bottom' => trim($t['bottom'])];
                }
            }
            
            echo "\nValid Taglines: " . count($valid) . "\n";
            var_dump($valid);
        }
    }
}

debug_call_cohere();