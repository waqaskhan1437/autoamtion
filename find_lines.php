<?php
$file = 'api/ai-tagline-generator.php';
$lines = file($file);

// Find line numbers to modify
foreach ($lines as $num => $line) {
    // generateBulkWithOpenRouter function
    if (strpos($line, '$data[\'choices\'][0][\'message\'][\'content\']') !== false && $num > 680 && $num < 720) {
        echo "Found at line " . ($num+1) . ": " . trim($line) . "\n";
    }
}

echo "\nDone finding!\n";
