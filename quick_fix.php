<?php
$file = 'api/ai-tagline-generator.php';
$content = file_get_contents($file);

// Fix 1: generateBulkWithOpenRouter - add reasoning field check after line: $content = $data['choices'][0]['message']['content'] ?? '';
$search1 = "\$content = \$data['choices'][0]['message']['content'] ?? '';\n    \n    \$content = trim(\$content);\n    \$content = preg_replace('/^```json";
$replace1 = "\$content = \$data['choices'][0]['message']['content'] ?? '';\n    if (empty(\$content) && !empty(\$data['choices'][0]['message']['reasoning'])) {\n        \$content = \$data['choices'][0]['message']['reasoning'];\n    }\n    \n    \$content = trim(\$content);\n    \$content = preg_replace('/^```json";

if (strpos($content, $search1) !== false) {
    $content = str_replace($search1, $replace1, $content);
    echo "Fix 1 applied!\n";
} else {
    echo "Fix 1 pattern not found\n";
}

// Fix 2: Add str_replace for social content function
$search2 = "\$content = \$data['choices'][0]['message']['content'] ?? '';\n    \n    \$content = trim(\$content);\n    \$content = preg_replace('/^```json\\s*/i', '', \$content);\n    \$content = preg_replace('/\\s*```\$/i', '', \$content);\n    \n    \$items = json_decode(\$content, true);";
$replace2 = "\$content = \$data['choices'][0]['message']['content'] ?? '';\n    if (empty(\$content) && !empty(\$data['choices'][0]['message']['reasoning'])) {\n        \$content = \$data['choices'][0]['message']['reasoning'];\n    }\n    \n    \$content = trim(\$content);\n    \$content = preg_replace('/^```json\\s*/i', '', \$content);\n    \$content = preg_replace('/\\s*```\$/i', '', \$content);\n    \$content = str_replace([\"\\\\n\", \"\\\\r\", \"\\\\t\"], '', \$content);\n    \$content = preg_replace('/\\s+/', ' ', \$content);\n    \n    \$items = json_decode(\$content, true);";

if (strpos($content, $search2) !== false) {
    $content = str_replace($search2, $replace2, $content);
    echo "Fix 2 applied!\n";
} else {
    echo "Fix 2 pattern not found\n";
}

file_put_contents($file, $content);
echo "Done!\n";
