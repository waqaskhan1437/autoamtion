<?php
// Read the file
$content = file_get_contents('api/ai-tagline-generator.php');

// Fix 1: generateBulkWithOpenRouter - add reasoning field check
$search1 = "        return ['success' => false, 'error' => \$errorMsg];
    }
    
    \$data = json_decode(\$response, true);
    \$content = \$data['choices'][0]['message']['content'] ?? '';
    
    \$content = trim(\$content);
    \$content = preg_replace('/^```json\\s*/i', '', \$content);
    \$content = preg_replace('/\\s*```\$/i', '', \$content);
    \$content = str_replace([\"\\\\n\", \"\\\\r\", \"\\\\t\"], '', \$content);
    \$content = preg_replace('/\\s+/', ' ', \$content);
    
    \$taglines = json_decode(\$content, true);
    
    // Handle various response formats
    if (isset(\$taglines['top']) && isset(\$taglines['bottom'])) {
        \$taglines = [\$taglines];";

$replace1 = "        return ['success' => false, 'error' => \$errorMsg];
    }
    
    \$data = json_decode(\$response, true);
    \$content = \$data['choices'][0]['message']['content'] ?? '';
    
    // Some models put response in reasoning field
    if (empty(\$content) && !empty(\$data['choices'][0]['message']['reasoning'])) {
        \$content = \$data['choices'][0]['message']['reasoning'];
    }
    
    \$content = trim(\$content);
    \$content = preg_replace('/^```json\\s*/i', '', \$content);
    \$content = preg_replace('/\\s*```\$/i', '', \$content);
    \$content = str_replace([\"\\\\n\", \"\\\\r\", \"\\\\t\"], '', \$content);
    \$content = preg_replace('/\\s+/', ' ', \$content);
    
    \$taglines = json_decode(\$content, true);
    
    // Handle various response formats
    if (isset(\$taglines['top']) && isset(\$taglines['bottom'])) {
        \$taglines = [\$taglines];";

$content = str_replace($search1, $replace1, $content);

// Fix 2: generateSocialContentWithOpenRouter - add reasoning field check
$search2 = "        return ['success' => false, 'error' => \$errorMsg];
    }
    
    \$data = json_decode(\$response, true);
    \$content = \$data['choices'][0]['message']['content'] ?? '';
    
    \$content = trim(\$content);
    \$content = preg_replace('/^```json\\s*/i', '', \$content);
    \$content = preg_replace('/\\s*```\$/i', '', \$content);
    
    \$items = json_decode(\$content, true);
    
    if (!is_array(\$items)) {
        return ['success' => false, 'error' => 'Failed to parse AI response'];
    }";

$replace2 = "        return ['success' => false, 'error' => \$errorMsg];
    }
    
    \$data = json_decode(\$response, true);
    \$content = \$data['choices'][0]['message']['content'] ?? '';
    
    // Some models put response in reasoning field
    if (empty(\$content) && !empty(\$data['choices'][0]['message']['reasoning'])) {
        \$content = \$data['choices'][0]['message']['reasoning'];
    }
    
    \$content = trim(\$content);
    \$content = preg_replace('/^```json\\s*/i', '', \$content);
    \$content = preg_replace('/\\s*```\$/i', '', \$content);
    \$content = str_replace([\"\\\\n\", \"\\\\r\", \"\\\\t\"], '', \$content);
    \$content = preg_replace('/\\s+/', ' ', \$content);
    
    \$items = json_decode(\$content, true);
    
    if (!is_array(\$items)) {
        return ['success' => false, 'error' => 'Failed to parse AI response'];
    }";

$content = str_replace($search2, $replace2, $content);

// Write the file back
file_put_contents('api/ai-tagline-generator.php', $content);

echo "Done!\n";
