<?php
// Test the actual generation function by calling it directly

require_once 'config.php';
require_once 'api/ai-tagline-generator.php';

// Call the function directly
$apiKey = 'RVue6W3S8I240s8sT5yB8aB1w6x6s6r4f3f2f1'; // Test key
$topPrompt = 'birthday';
$bottomPrompt = 'order';
$count = 3;
$model = 'command-a-03-2025';

echo "Calling generateBulkTaglinesWithCohere...\n";
echo "API Key: " . substr($apiKey, 0, 8) . "****\n";
echo "Count: " . $count . "\n";
echo "Model: " . $model . "\n";
echo str_repeat("-", 50) . "\n";

$result = generateBulkTaglinesWithCohere($apiKey, $topPrompt, $bottomPrompt, $count, $model);

echo "Result:\n";
var_dump($result);