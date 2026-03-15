<?php
require_once __DIR__ . '/config.php';

$pdo = new PDO("mysql:host=$host;port=3306;dbname=$dbname", $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'openrouter_api_key'");
$stmt->execute();
$openrouterKey = $stmt->fetchColumn();

echo "Testing via function call...\n\n";

require_once __DIR__ . '/api/ai-tagline-generator.php';

$count = 5;
echo "Requesting $count taglines...\n\n";

$result = generateBulkWithOpenRouter($openrouterKey, 'birthday', 'order now', $count);

echo "Result:\n";
print_r($result);

// Debug: Let me manually call OpenRouter and see what we get
echo "\n\n=== Manual OpenRouter Call ===\n\n";

$instructions = "Generate EXACTLY {$count} UNIQUE pairs of video taglines. This is CRITICAL - you MUST output exactly {$count} items in the array.\n\n";
$instructions .= "TOP (LARGE text at top): Short catchy hook (2-4 words). Examples: Birthday Bash, Love You, Congratulations\n";
$instructions .= "BOTTOM (SMALL text at bottom): Very short CTA (1-3 words). Examples: Order now, Visit us, Prankwish.com\n";
$instructions .= "Theme - TOP: birthday | BOTTOM: order now\n\n";
$instructions .= "STRICT REQUIREMENTS:\n";
$instructions .= "- Output EXACTLY {$count} items in a JSON array\n";
$instructions .= "- Each item must be: {\"top\": \"...\", \"bottom\": \"...\"}\n";
$instructions .= "- Do NOT output anything else - only the JSON array\n";
$instructions .= "- Do NOT wrap in code blocks\n\n";
$instructions .= "Example output format:\n";
$instructions .= "[{\"top\": \"Birthday Bash\", \"bottom\": \"Order Now\"}, {\"top\": \"Celebrate Today\", \"bottom\": \"Shop Here\"}, ...]\n\n";
$instructions .= "Remember: Output exactly {$count} tagline pairs!";

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openrouterKey,
        'HTTP-Referer: http://localhost',
        'X-Title: AI Tagline Generator'
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'openrouter/free',
        'messages' => [
            ['role' => 'user', 'content' => $instructions]
        ],
        'temperature' => 0.95,
        'max_tokens' => min($count * 60, 3000)
    ]),
    CURLOPT_TIMEOUT => 90
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

$data = json_decode($response, true);
$content = $data['choices'][0]['message']['content'] ?? '';

echo "Raw content: " . substr($content, 0, 200) . "...\n\n";

$content = trim($content);
$content = preg_replace('/^```json\s*/i', '', $content);
$content = preg_replace('/\s*```$/i', '', $content);
$content = str_replace(["\\n", "\\r", "\\t"], '', $content);
$content = preg_replace('/\s+/', ' ', $content);

$taglines = json_decode($content, true);

if (!is_array($taglines)) {
    echo "JSON decode failed! Error: " . json_last_error_msg() . "\n";
    echo "Content after cleaning: " . $content . "\n";
} else {
    echo "JSON decode SUCCESS! Count: " . count($taglines) . "\n";
}
