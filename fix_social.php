<?php
$file = 'api/ai-tagline-generator.php';
$lines = file($file);

// Find and fix the social content function (around line 1015)
foreach ($lines as $num => $line) {
    // Find the social content function's content extraction
    if (strpos($line, "content'] = \$data['choices'][0]['message']['content']") !== false && $num > 1000 && $num < 1050) {
        echo "Found social content at line " . ($num+1) . ": " . trim($line) . "\n";
        
        // Check if fix already applied
        if (strpos($lines[$num+1], 'reasoning') !== false) {
            echo "Fix already applied!\n";
            break;
        }
        
        // Insert reasoning check after this line
        $new_lines = array_merge(
            array_slice($lines, 0, $num + 1),
            ["\n"],
            ["    if (empty(\$content) && !empty(\$data['choices'][0]['message']['reasoning'])) {\n"],
            ["        \$content = \$data['choices'][0]['message']['reasoning'];\n"],
            ["    }\n"],
            array_slice($lines, $num + 1)
        );
        file_put_contents($file, $new_lines);
        echo "Fix applied!\n";
        break;
    }
}

echo "Done!\n";
