<?php
$file = 'api/ai-tagline-generator.php';
$lines = file($file);

// Insert reasoning check after line 690 (index 689)
$insert_after = 689; // Line 690 = index 689

// Check if this is the right place
if (trim($lines[$insert_after]) == "\$content = \$data['choices'][0]['message']['content'] ?? '';") {
    $new_lines = array_merge(
        array_slice($lines, 0, $insert_after + 1),
        ["\n"],
        ["    if (empty(\$content) && !empty(\$data['choices'][0]['message']['reasoning'])) {\n"],
        ["        \$content = \$data['choices'][0]['message']['reasoning'];\n"],
        ["    }\n"],
        array_slice($lines, $insert_after + 1)
    );
    file_put_contents($file, $new_lines);
    echo "Fix 1 applied!\n";
} else {
    echo "Line not found at expected position\n";
}

// Now find and fix the social content function (around line 1015)
$lines = file($file);
foreach ($lines as $num => $line) {
    if (strpos($line, "content'] = \$data['choices'][0]['message']['content']") !== false && $num > 1000 && $num < 1050) {
        echo "Found social content at line " . ($num+1) . "\n";
        
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
        echo "Fix 2 applied!\n";
        break;
    }
}

echo "Done!\n";
