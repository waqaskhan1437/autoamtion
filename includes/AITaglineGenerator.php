<?php
/**
 * AI Tagline Generator
 * Generates unique top and bottom taglines for videos using OpenAI or Google Gemini (FREE)
 */

class AITaglineGenerator {
    private $openaiKey;
    private $geminiKey;
    private $pdo;
    private $provider = 'gemini'; // Default to free Gemini
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadApiKeys();
    }
    
    /**
     * Load API keys from settings
     */
    private function loadApiKeys() {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('openai_api_key', 'gemini_api_key', 'ai_provider')");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $this->openaiKey = $settings['openai_api_key'] ?? (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : null);
            $this->geminiKey = $settings['gemini_api_key'] ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : null);
            $this->provider = $settings['ai_provider'] ?? 'gemini';
        } catch (Exception $e) {
            $this->openaiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : null;
            $this->geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : null;
        }
    }
    
    /**
     * Get current API key based on provider
     */
    private function getApiKey() {
        if ($this->provider === 'gemini' && $this->geminiKey) {
            return $this->geminiKey;
        }
        if ($this->provider === 'openai' && $this->openaiKey) {
            return $this->openaiKey;
        }
        // Fallback: try Gemini first (free), then OpenAI
        return $this->geminiKey ?: $this->openaiKey;
    }
    
    /**
     * Get active provider
     */
    private function getActiveProvider() {
        if ($this->provider === 'gemini' && $this->geminiKey) {
            return 'gemini';
        }
        if ($this->provider === 'openai' && $this->openaiKey) {
            return 'openai';
        }
        // Fallback
        if ($this->geminiKey) return 'gemini';
        if ($this->openaiKey) return 'openai';
        return null;
    }
    
    /**
     * Generate full social media content (title, description, hashtags, tags)
     * For YouTube, TikTok, Instagram, Facebook, X/Twitter
     */
    public function generateSocialContent($prompt, $videoTitle = '', $topText = '') {
        $provider = $this->getActiveProvider();
        $apiKey = $this->getApiKey();
        
        if (!$apiKey || !$provider) {
            // Return defaults if no AI configured
            return $this->getDefaultSocialContent($topText, $videoTitle);
        }
        
        $fullPrompt = "Generate social media content for a short video.\n\n";
        $fullPrompt .= "VIDEO CONTEXT: {$videoTitle}\n";
        if ($topText) {
            $fullPrompt .= "VIDEO TAGLINE: {$topText}\n";
        }
        $fullPrompt .= "USER INSTRUCTIONS: {$prompt}\n\n";
        
        $fullPrompt .= "Generate:\n";
        $fullPrompt .= "1. TITLE: Catchy video title (max 100 chars, for YouTube)\n";
        $fullPrompt .= "2. DESCRIPTION: Engaging description with call-to-action (2-3 sentences)\n";
        $fullPrompt .= "3. HASHTAGS: 5-8 relevant trending hashtags (include #shorts #viral #trending)\n";
        $fullPrompt .= "4. TAGS: 10-15 YouTube SEO tags as comma-separated list\n\n";
        
        $fullPrompt .= "RESPOND ONLY IN JSON:\n";
        $fullPrompt .= "{\"title\": \"...\", \"description\": \"...\", \"hashtags\": [\"#tag1\", \"#tag2\"], \"tags\": [\"tag1\", \"tag2\"]}";
        
        if ($provider === 'gemini') {
            $result = $this->callGeminiForContent($apiKey, $fullPrompt);
        } else {
            $result = $this->callOpenAIForContent($apiKey, $fullPrompt);
        }
        
        // Validate and return with defaults if needed
        if (empty($result['title'])) {
            $result = $this->getDefaultSocialContent($topText, $videoTitle);
        }
        
        return $result;
    }
    
    /**
     * Call Gemini for social content
     */
    private function callGeminiForContent($apiKey, $prompt) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
        
        $data = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.8, 'maxOutputTokens' => 500]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['error' => 'Gemini API failed'];
        }
        
        $result = json_decode($response, true);
        $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        return $this->parseSocialContentResponse($content);
    }
    
    /**
     * Call OpenAI for social content
     */
    private function callOpenAIForContent($apiKey, $prompt) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Generate social media content in JSON format.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.8,
                'max_tokens' => 500
            ]),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['error' => 'OpenAI API failed'];
        }
        
        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        
        return $this->parseSocialContentResponse($content);
    }
    
    /**
     * Parse social content JSON
     */
    private function parseSocialContentResponse($content) {
        $content = trim($content);
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = preg_replace('/^```\s*/', '', $content);
        
        $data = json_decode($content, true);
        
        if (!$data) {
            return ['error' => 'Failed to parse response'];
        }
        
        return [
            'success' => true,
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'hashtags' => $data['hashtags'] ?? [],
            'tags' => $data['tags'] ?? []
        ];
    }
    
    /**
     * Get default social content when AI is not available
     */
    private function getDefaultSocialContent($topText = '', $videoTitle = '') {
        $title = $topText ?: $videoTitle ?: 'Amazing Video';
        $title = substr($title, 0, 100);
        
        $hashtags = ['#shorts', '#viral', '#trending', '#fyp', '#foryou', '#reels', '#tiktok'];
        
        $description = $topText ? "{$topText} " : '';
        $description .= "Watch till the end! " . implode(' ', $hashtags);
        
        $tags = ['shorts', 'viral', 'trending', 'fyp', 'amazing', 'must watch', 'entertainment', 'video'];
        
        return [
            'success' => true,
            'title' => $title,
            'description' => $description,
            'hashtags' => $hashtags,
            'tags' => $tags
        ];
    }
    
    /**
     * Generate unique taglines based on user's instructions
     * Supports: Google Gemini (FREE) and OpenAI
     */
    public function generateTaglines($prompt, $videoTitle = '', $previousTaglines = []) {
        $provider = $this->getActiveProvider();
        $apiKey = $this->getApiKey();
        
        if (!$apiKey || !$provider) {
            return [
                'error' => 'No AI API key configured. Add Gemini (FREE) or OpenAI key in Settings.',
                'top' => '',
                'bottom' => ''
            ];
        }
        
        // Build the prompt
        $fullPrompt = "Generate unique TOP and BOTTOM taglines for a video.\n\n";
        $fullPrompt .= "INSTRUCTIONS: {$prompt}\n\n";
        
        if ($videoTitle) {
            $fullPrompt .= "VIDEO CONTEXT: {$videoTitle}\n\n";
        }
        
        if (!empty($previousTaglines)) {
            $fullPrompt .= "AVOID THESE (already used):\n" . implode("\n", array_slice($previousTaglines, -10)) . "\n\n";
        }
        
        $fullPrompt .= "Generate creative, catchy taglines for YouTube Shorts, TikTok, Reels.\n";
        $fullPrompt .= "RESPOND ONLY IN JSON: {\"top\": \"YOUR TOP TEXT\", \"bottom\": \"YOUR BOTTOM TEXT\"}";
        
        // Call appropriate API
        if ($provider === 'gemini') {
            return $this->callGemini($apiKey, $fullPrompt);
        } else {
            return $this->callOpenAI($apiKey, $fullPrompt);
        }
    }
    
    /**
     * Call Google Gemini API (FREE tier available)
     */
    private function callGemini($apiKey, $prompt) {
        // Use gemini-1.5-flash for most stable free tier limits
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
        
        $data = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.9,
                'maxOutputTokens' => 200
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['error' => 'Connection error: ' . $error, 'top' => '', 'bottom' => ''];
        }
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['error']['message'] ?? 'Gemini API failed';
            return ['error' => $errorMsg, 'top' => '', 'bottom' => ''];
        }
        
        $result = json_decode($response, true);
        $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        return $this->parseTaglineResponse($content);
    }
    
    /**
     * Call OpenAI API
     */
    private function callOpenAI($apiKey, $prompt) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Generate taglines in JSON format only.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.9,
                'max_tokens' => 200
            ]),
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMsg = $errorData['error']['message'] ?? 'OpenAI API failed';
            return ['error' => $errorMsg, 'top' => '', 'bottom' => ''];
        }
        
        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        
        return $this->parseTaglineResponse($content);
    }
    
    /**
     * Parse tagline JSON from AI response
     */
    private function parseTaglineResponse($content) {
        $content = trim($content);
        // Remove markdown code blocks if present
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = preg_replace('/^```\s*/', '', $content);
        
        $taglines = json_decode($content, true);
        
        if (!$taglines || !isset($taglines['top']) || !isset($taglines['bottom'])) {
            return [
                'error' => 'Failed to parse AI response',
                'top' => '',
                'bottom' => '',
                'raw' => $content
            ];
        }
        
        return [
            'success' => true,
            'top' => $taglines['top'],
            'bottom' => $taglines['bottom'],
            'provider' => $this->getActiveProvider()
        ];
    }
    
    /**
     * Generate multiple unique taglines at once (supports Gemini + OpenAI)
     * Pre-generates a LIST of taglines for batch video processing
     */
    public function generateBulkTaglines($prompt, $count = 5, $previousUsed = []) {
        $provider = $this->getActiveProvider();
        $apiKey = $this->getApiKey();
        
        if (!$apiKey || !$provider) {
            return ['error' => 'No AI API key configured. Add Gemini (FREE) or OpenAI key in Settings.'];
        }
        
        // Build prompt for bulk generation
        $userPrompt = "Generate {$count} UNIQUE pairs of TOP and BOTTOM taglines for videos.\n\n";
        $userPrompt .= "INSTRUCTIONS:\n{$prompt}\n\n";
        
        // Include previously used taglines to avoid duplicates
        if (!empty($previousUsed)) {
            $userPrompt .= "AVOID THESE (already used):\n";
            foreach (array_slice($previousUsed, -20) as $used) {
                $userPrompt .= "- Top: \"{$used['top']}\" | Bottom: \"{$used['bottom']}\"\n";
            }
            $userPrompt .= "\n";
        }
        
        $userPrompt .= "RULES:\n";
        $userPrompt .= "1. Each tagline pair must be COMPLETELY UNIQUE and DIFFERENT\n";
        $userPrompt .= "2. Use varied vocabulary and sentence structures\n";
        $userPrompt .= "3. TOP text: 3-6 words, catchy hook\n";
        $userPrompt .= "4. BOTTOM text: 3-8 words, call-to-action\n";
        $userPrompt .= "5. Perfect for YouTube Shorts, TikTok, Instagram Reels\n\n";
        $userPrompt .= "RESPOND ONLY IN THIS JSON FORMAT:\n";
        $userPrompt .= '[{"top": "text1", "bottom": "text1"}, {"top": "text2", "bottom": "text2"}, ...]';
        
        // Call appropriate API
        if ($provider === 'gemini') {
            $result = $this->callGeminiBulk($apiKey, $userPrompt, $count);
        } else {
            $result = $this->callOpenAIBulk($apiKey, $userPrompt, $count);
        }
        
        // Validate and filter duplicates
        if (isset($result['taglines']) && is_array($result['taglines'])) {
            $result['taglines'] = $this->filterDuplicates($result['taglines'], $previousUsed);
        }
        
        return $result;
    }
    
    /**
     * Call Gemini API for bulk taglines (FREE)
     */
    private function callGeminiBulk($apiKey, $prompt, $count) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;
        
        $data = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.95, // High for variation
                'maxOutputTokens' => max(200, $count * 100) // Scale with count
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['error' => 'Connection error: ' . $error];
        }
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            return ['error' => $errorData['error']['message'] ?? 'Gemini API failed'];
        }
        
        $result = json_decode($response, true);
        $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        return $this->parseBulkResponse($content);
    }
    
    /**
     * Call OpenAI API for bulk taglines
     */
    private function callOpenAIBulk($apiKey, $prompt, $count) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a creative social media content expert. Generate unique taglines in JSON format only.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.95,
                'max_tokens' => max(300, $count * 100)
            ]),
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            return ['error' => $errorData['error']['message'] ?? 'OpenAI API failed'];
        }
        
        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        
        return $this->parseBulkResponse($content);
    }
    
    /**
     * Parse bulk taglines response
     */
    private function parseBulkResponse($content) {
        $content = trim($content);
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = preg_replace('/^```\s*/', '', $content);
        
        $taglines = json_decode($content, true);
        
        if (!$taglines || !is_array($taglines)) {
            return ['error' => 'Failed to parse AI response', 'raw' => $content];
        }
        
        // Validate structure
        $valid = [];
        foreach ($taglines as $t) {
            if (isset($t['top']) && isset($t['bottom'])) {
                $valid[] = [
                    'top' => trim($t['top']),
                    'bottom' => trim($t['bottom'])
                ];
            }
        }
        
        return [
            'success' => true,
            'taglines' => $valid,
            'count' => count($valid)
        ];
    }
    
    /**
     * Filter duplicate taglines using similarity check
     */
    private function filterDuplicates($newTaglines, $previousUsed) {
        $unique = [];
        $allUsed = $previousUsed;
        
        foreach ($newTaglines as $tagline) {
            $isDuplicate = false;
            
            // Check similarity with all previous
            foreach ($allUsed as $used) {
                $topSimilarity = $this->calculateSimilarity($tagline['top'], $used['top']);
                $bottomSimilarity = $this->calculateSimilarity($tagline['bottom'], $used['bottom']);
                
                // If more than 70% similar, skip
                if ($topSimilarity > 70 || $bottomSimilarity > 70) {
                    $isDuplicate = true;
                    break;
                }
            }
            
            if (!$isDuplicate) {
                $unique[] = $tagline;
                $allUsed[] = $tagline; // Add to used list
            }
        }
        
        return $unique;
    }
    
    /**
     * Calculate text similarity percentage
     */
    private function calculateSimilarity($text1, $text2) {
        similar_text(strtolower($text1), strtolower($text2), $percent);
        return $percent;
    }
    
    /**
     * Pre-generate taglines for an automation and store in database
     * Call this before starting batch processing
     */
    public function preGenerateForAutomation($automationId, $prompt, $videoCount) {
        // Generate extra taglines (buffer for duplicates)
        $generateCount = min($videoCount + 5, 30); // Max 30 at once
        
        // Get previously used taglines for this automation
        $previousUsed = $this->getUsedTaglines($automationId);
        
        // Generate bulk taglines
        $result = $this->generateBulkTaglines($prompt, $generateCount, $previousUsed);
        
        if (!isset($result['success'])) {
            return $result;
        }
        
        // Store in database for later use
        $this->storeTaglinesPool($automationId, $result['taglines']);
        
        return [
            'success' => true,
            'generated' => count($result['taglines']),
            'needed' => $videoCount,
            'provider' => $this->getActiveProvider()
        ];
    }
    
    /**
     * Get next available tagline from pre-generated pool
     */
    public function getNextFromPool($automationId) {
        try {
            // Get unused tagline from pool
            $stmt = $this->pdo->prepare("
                SELECT id, top_text, bottom_text 
                FROM taglines_pool 
                WHERE automation_id = ? AND used = 0 
                ORDER BY id ASC 
                LIMIT 1
            ");
            $stmt->execute([$automationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                // Mark as used
                $updateStmt = $this->pdo->prepare("UPDATE taglines_pool SET used = 1, used_at = NOW() WHERE id = ?");
                $updateStmt->execute([$row['id']]);
                
                return [
                    'success' => true,
                    'top' => $row['top_text'],
                    'bottom' => $row['bottom_text'],
                    'source' => 'pool'
                ];
            }
            
            return ['error' => 'No taglines in pool'];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Store generated taglines in pool
     */
    private function storeTaglinesPool($automationId, $taglines) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO taglines_pool (automation_id, top_text, bottom_text, used, created_at) 
                VALUES (?, ?, ?, 0, NOW())
            ");
            
            foreach ($taglines as $t) {
                $stmt->execute([$automationId, $t['top'], $t['bottom']]);
            }
            
            return true;
        } catch (Exception $e) {
            // Table might not exist, that's OK
            return false;
        }
    }
    
    /**
     * Get previously used taglines for an automation
     */
    private function getUsedTaglines($automationId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT top_text as top, bottom_text as bottom 
                FROM taglines_pool 
                WHERE automation_id = ? AND used = 1 
                ORDER BY used_at DESC 
                LIMIT 50
            ");
            $stmt->execute([$automationId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Clear taglines pool for automation
     */
    public function clearPool($automationId) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM taglines_pool WHERE automation_id = ?");
            $stmt->execute([$automationId]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Test the AI connection
     */
    public function testConnection() {
        if (!$this->apiKey) {
            return ['error' => 'OpenAI API key not configured'];
        }
        
        $result = $this->generateTaglines('Generate a fun birthday greeting tagline', 'Birthday Video');
        
        if (isset($result['error'])) {
            return $result;
        }
        
        return [
            'success' => true,
            'sample_top' => $result['top'],
            'sample_bottom' => $result['bottom']
        ];
    }
}
?>
