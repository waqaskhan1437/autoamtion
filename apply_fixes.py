import re

# Read the file
with open("api/ai-tagline-generator.php", "r", encoding="utf-8") as f:
    content = f.read()

# Fix 1: generateBulkWithOpenRouter - add reasoning field check
old_pattern1 = """    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    
    $content = trim($content);
    $content = preg_replace('/^```json\\s*/i', '', $content);
    $content = preg_replace('/\\s*```$/i', '', $content);
    $content = str_replace(["\\\\n", "\\\\r", "\\\\t"], '', $content);
    $content = preg_replace('/\\s+/', ' ', $content);
    
    $taglines = json_decode($content, true);
    
    // Handle various response formats"""

new_pattern1 = """    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    
    // Some models put response in reasoning field
    if (empty($content) && !empty($data['choices'][0]['message']['reasoning'])) {
        $content = $data['choices'][0]['message']['reasoning'];
    }
    
    $content = trim($content);
    $content = preg_replace('/^```json\\s*/i', '', $content);
    $content = preg_replace('/\\s*```$/i', '', $content);
    $content = str_replace(["\\\\n", "\\\\r", "\\\\t"], '', $content);
    $content = preg_replace('/\\s+/', ' ', $content);
    
    $taglines = json_decode($content, true);
    
    // Handle various response formats"""

content = content.replace(old_pattern1, new_pattern1)

# Fix 2: generateSocialContentWithOpenRouter - add reasoning field check
old_pattern2 = """    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    
    $content = trim($content);
    $content = preg_replace('/^```json\\s*/i', '', $content);
    $content = preg_replace('/\\s*```$/i', '', $content);
    
    $items = json_decode($content, true);"""

new_pattern2 = """    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    
    // Some models put response in reasoning field
    if (empty($content) && !empty($data['choices'][0]['message']['reasoning'])) {
        $content = $data['choices'][0]['message']['reasoning'];
    }
    
    $content = trim($content);
    $content = preg_replace('/^```json\\s*/i', '', $content);
    $content = preg_replace('/\\s*```$/i', '', $content);
    $content = str_replace(["\\\\n", "\\\\r", "\\\\t"], '', $content);
    $content = preg_replace('/\\s+/', ' ', $content);
    
    $items = json_decode($content, true);"""

content = content.replace(old_pattern2, new_pattern2)

# Write the file back
with open("api/ai-tagline-generator.php", "w", encoding="utf-8") as f:
    f.write(content)

print("Done! File updated.")
