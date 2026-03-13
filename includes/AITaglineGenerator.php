<?php
/**
 * AI Tagline Generator
 * Generates unique top and bottom taglines for videos using OpenAI or Google Gemini (FREE)
 */

require_once __DIR__ . '/OllamaClient.php';

class AITaglineGenerator {
    private $openaiKey;
    private $geminiKey;
    private $pdo;
    private $provider = 'gemini'; // Default to free Gemini
    private $ollamaClient;
    private $lastApiCallTime = 0;
    private $rateLimitDelay = 15; // Gemini free tier: 15 seconds between requests
    private $geminiRetryAttempts = 3;
    private $geminiMaxRetryDelay = 90;
    
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
            $this->ollamaClient = OllamaClient::fromSettings($this->pdo);
        } catch (Exception $e) {
            $this->openaiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : null;
            $this->geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : null;
            $this->ollamaClient = OllamaClient::fromSettings();
        }
    }
    
    /**
     * Get current API key based on provider
     */
    private function getApiKey() {
        $provider = $this->getActiveProvider();
        if ($provider === 'gemini' && $this->geminiKey) {
            return $this->geminiKey;
        }
        if ($provider === 'openai' && $this->openaiKey) {
            return $this->openaiKey;
        }
        return null;
    }
    
    /**
     * Get active provider
     */
    private function getActiveProvider() {
        $providers = $this->getProviderOrder();
        return $providers[0] ?? null;
    }

    private function getProviderOrder() {
        $preferred = strtolower(trim((string)$this->provider));
        $orderMap = [
            'openai' => ['openai', 'ollama', 'gemini'],
            'ollama' => ['ollama', 'gemini', 'openai'],
            'gemini' => ['gemini', 'ollama', 'openai'],
        ];

        $order = $orderMap[$preferred] ?? $orderMap['gemini'];
        $providers = [];
        foreach ($order as $provider) {
            if ($this->isProviderAvailable($provider)) {
                $providers[] = $provider;
            }
        }

        return array_values(array_unique($providers));
    }

    private function isProviderAvailable($provider) {
        if ($provider === 'gemini') {
            return !empty($this->geminiKey);
        }

        if ($provider === 'openai') {
            return !empty($this->openaiKey);
        }

        if ($provider === 'ollama') {
            return $this->ollamaClient instanceof OllamaClient && $this->ollamaClient->isConfigured();
        }

        return false;
    }

    private function addProviderError(array &$errors, $provider, array $result) {
        $message = trim((string)($result['error'] ?? 'Provider failed'));
        $errors[] = strtoupper((string)$provider) . ': ' . ($message !== '' ? $message : 'Provider failed');
    }
    
    /**
     * Generate full social media content (title, description, hashtags, tags)
     * For YouTube, TikTok, Instagram, Facebook, X/Twitter
     */
    public function generateSocialContent($prompt, $videoTitle = '', $topText = '') {
        $providers = $this->getProviderOrder();
        
        if (empty($providers)) {
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

        $errors = [];
        foreach ($providers as $provider) {
            if ($provider === 'gemini') {
                $result = $this->callGeminiForContent($this->geminiKey, $fullPrompt);
            } elseif ($provider === 'openai') {
                $result = $this->callOpenAIForContent($this->openaiKey, $fullPrompt);
            } else {
                $result = $this->callOllamaForContent($fullPrompt);
            }

            if (!empty($result['success']) && !empty($result['title'])) {
                return $result;
            }

            $this->addProviderError($errors, $provider, is_array($result) ? $result : []);
        }

        $fallback = $this->getDefaultSocialContent($topText, $videoTitle);
        if (!empty($errors)) {
            $fallback['fallback_error'] = implode(' | ', $errors);
        }

        return $fallback;
    }
    
    /**
     * Call Gemini for social content
     */
    private function callGeminiForContent($apiKey, $prompt) {
        $data = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.8,
                'maxOutputTokens' => 500,
                'responseMimeType' => 'application/json'
            ]
        ];

        $response = $this->sendGeminiRequest($apiKey, $data, 30);
        if (empty($response['success'])) {
            return ['error' => $response['error'] ?? 'Gemini API failed'];
        }

        $result = $response['data'] ?? [];
        $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        return $this->parseSocialContentResponse($content, 'gemini');
    }
    
    /**
     * Call OpenAI for social content
     */
    private function callOpenAIForContent($apiKey, $prompt) {
        // Enforce rate limiting (respect API limits)
        $this->enforceRateLimit();
        
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
        
        return $this->parseSocialContentResponse($content, 'openai');
    }

    private function callOllamaForContent($prompt) {
        if (!$this->isProviderAvailable('ollama')) {
            return ['error' => 'Ollama is not configured'];
        }

        $response = $this->ollamaClient->generateJson(
            "Return valid JSON only.\n" . $prompt,
            ['temperature' => 0.8, 'max_tokens' => 500],
            90
        );

        if (empty($response['success'])) {
            return ['error' => $response['error'] ?? 'Ollama API failed'];
        }

        return $this->parseSocialContentResponse($response['data'] ?? [], 'ollama');
    }
    
    /**
     * Parse social content JSON
     */
    private function parseSocialContentResponse($content, $provider = null) {
        if (is_array($content)) {
            $data = $content;
        } else {
            $content = $this->cleanAiResponse($content);
            $data = json_decode($content, true);

            if (!$data) {
                $content = $this->extractBalancedJson($content, '{', '}');
                $data = $content !== '' ? json_decode($content, true) : null;
            }
        }

        if (!$data) {
            return ['error' => 'Failed to parse response'];
        }
        
        return [
            'success' => true,
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'hashtags' => $data['hashtags'] ?? [],
            'tags' => $data['tags'] ?? [],
            'provider' => $provider ?: $this->getActiveProvider()
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
            'tags' => $tags,
            'provider' => 'local_default'
        ];
    }
    
    /**
     * Generate unique taglines based on user's instructions
     * Supports: Google Gemini (FREE) and OpenAI
     */
    public function generateTaglines($prompt, $videoTitle = '', $previousTaglines = []) {
        $providers = $this->getProviderOrder();
        
        if (empty($providers)) {
            return [
                'error' => 'No AI provider configured. Add Gemini/OpenAI credentials or enable Ollama fallback.',
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

        $errors = [];
        foreach ($providers as $provider) {
            if ($provider === 'gemini') {
                $result = $this->callGemini($this->geminiKey, $fullPrompt);
            } elseif ($provider === 'openai') {
                $result = $this->callOpenAI($this->openaiKey, $fullPrompt);
            } else {
                $result = $this->callOllama($fullPrompt);
            }

            if (!empty($result['success']) && !empty($result['top'])) {
                return $result;
            }

            $this->addProviderError($errors, $provider, is_array($result) ? $result : []);
        }

        return [
            'error' => implode(' | ', $errors),
            'top' => '',
            'bottom' => ''
        ];
    }

    /**
     * Enforce rate limiting between API calls
     * Gemini free tier requires minimum 15 seconds between requests
     */
    private function enforceRateLimit() {
        $now = microtime(true);
        $timeSinceLast = $now - $this->lastApiCallTime;
        
        if ($timeSinceLast < $this->rateLimitDelay) {
            $sleepTime = $this->rateLimitDelay - $timeSinceLast;
            $sleepSeconds = ceil($sleepTime);
            if ($sleepSeconds > 0) {
                sleep($sleepSeconds);
            }
        }
        
        $this->lastApiCallTime = microtime(true);
    }

    private function sendGeminiRequest($apiKey, array $data, $timeout = 30) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;
        $lastError = 'Gemini API failed';
        $lastHttpCode = 0;
        $lastResponse = '';

        for ($attempt = 1; $attempt <= $this->geminiRetryAttempts; $attempt++) {
            $this->enforceRateLimit();

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_TIMEOUT => $timeout
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $lastHttpCode = $httpCode;
            $lastResponse = (string)$response;

            if ($error) {
                return [
                    'success' => false,
                    'error' => 'Connection error: ' . $error,
                    'http_code' => 0
                ];
            }

            if ($httpCode === 200) {
                return [
                    'success' => true,
                    'http_code' => 200,
                    'data' => json_decode((string)$response, true)
                ];
            }

            $errorData = json_decode((string)$response, true);
            $lastError = $errorData['error']['message'] ?? 'Gemini API failed';

            if ($attempt < $this->geminiRetryAttempts && $this->shouldRetryGeminiRequest($httpCode, $lastError)) {
                sleep($this->getGeminiRetryDelaySeconds($lastError, $attempt));
                continue;
            }

            break;
        }

        return [
            'success' => false,
            'error' => $lastError,
            'http_code' => $lastHttpCode,
            'response' => $lastResponse
        ];
    }

    private function shouldRetryGeminiRequest($httpCode, $message) {
        $message = strtolower(trim((string)$message));
        if (in_array((int)$httpCode, [429, 500, 503], true)) {
            return true;
        }

        return strpos($message, 'quota exceeded') !== false
            || strpos($message, 'rate limit') !== false
            || strpos($message, 'retry in') !== false
            || strpos($message, 'resource has been exhausted') !== false;
    }

    private function getGeminiRetryDelaySeconds($message, $attempt = 1) {
        $message = (string)$message;
        if (preg_match('/retry in\s+([0-9]+(?:\.[0-9]+)?)s/i', $message, $matches)) {
            $delay = (int)ceil((float)$matches[1]) + 1;
            return max(5, min($this->geminiMaxRetryDelay, $delay));
        }

        $fallbackDelay = (int)(15 * max(1, $attempt));
        return max(10, min($this->geminiMaxRetryDelay, $fallbackDelay));
    }

    /**
     * Generate sequential content with timing controls
     * Supports generating taglines, then descriptions with delays
     */
    public function generateSequentialContent($sequence = []) {
        $results = [];
        $totalDelay = 0;

        foreach ($sequence as $index => $item) {
            $type = $item['type'] ?? 'tagline';
            $prompt = $item['prompt'] ?? '';
            $videoTitle = $item['video_title'] ?? '';
            $delay = $item['delay_seconds'] ?? 0;

            // Apply delay before generating (except for first item)
            if ($index > 0 && $delay > 0) {
                sleep($delay);
                $totalDelay += $delay;
            }

            $startTime = microtime(true);

            if ($type === 'tagline') {
                // Generate taglines
                $result = $this->generateTaglines($prompt, $videoTitle);
                $results[] = [
                    'type' => 'tagline',
                    'index' => $index,
                    'delay_applied' => $delay,
                    'total_delay_so_far' => $totalDelay,
                    'generation_time' => microtime(true) - $startTime,
                    'result' => $result
                ];
            } elseif ($type === 'description') {
                // Generate description
                $descResult = $this->generateDescription($prompt, $videoTitle);
                $results[] = [
                    'type' => 'description',
                    'index' => $index,
                    'delay_applied' => $delay,
                    'total_delay_so_far' => $totalDelay,
                    'generation_time' => microtime(true) - $startTime,
                    'result' => $descResult
                ];
            } elseif ($type === 'bulk_descriptions') {
                // Generate multiple descriptions
                $count = $item['count'] ?? 2;
                $bulkResult = $this->generateBulkDescriptions($prompt, $videoTitle, $count);
                $results[] = [
                    'type' => 'bulk_descriptions',
                    'index' => $index,
                    'delay_applied' => $delay,
                    'total_delay_so_far' => $totalDelay,
                    'generation_time' => microtime(true) - $startTime,
                    'count' => $count,
                    'result' => $bulkResult
                ];
            }
        }

        return [
            'success' => true,
            'total_items' => count($results),
            'total_delay' => $totalDelay,
            'sequence' => $results
        ];
    }

    /**
     * Generate a single description
     */
    public function generateDescription($prompt, $videoTitle = '') {
        $providers = $this->getProviderOrder();

        if (empty($providers)) {
            return [
                'error' => 'No AI provider configured.',
                'description' => ''
            ];
        }

        $fullPrompt = "Generate a compelling video description.\n\n";
        $fullPrompt .= "INSTRUCTIONS: {$prompt}\n\n";

        if ($videoTitle) {
            $fullPrompt .= "VIDEO CONTEXT: {$videoTitle}\n\n";
        }

        $fullPrompt .= "Create an engaging, SEO-friendly description for YouTube Shorts, TikTok, or Instagram Reels.\n";
        $fullPrompt .= "Keep it under 150 characters. Make it viral and clickable.\n";
        $fullPrompt .= "RESPOND ONLY WITH THE DESCRIPTION TEXT:";

        $errors = [];
        foreach ($providers as $provider) {
            if ($provider === 'gemini') {
                $result = $this->callGeminiForDescription($this->geminiKey, $fullPrompt);
            } elseif ($provider === 'openai') {
                $result = $this->callOpenAIForDescription($this->openaiKey, $fullPrompt);
            } else {
                $result = $this->callOllamaForDescription($fullPrompt);
            }

            if (!empty($result['success']) && !empty($result['description'])) {
                return $result;
            }

            $this->addProviderError($errors, $provider, is_array($result) ? $result : []);
        }

        return [
            'error' => implode(' | ', $errors),
            'description' => ''
        ];
    }

    /**
     * Generate multiple descriptions
     */
    public function generateBulkDescriptions($prompt, $videoTitle = '', $count = 2) {
        $descriptions = [];

        for ($i = 0; $i < $count; $i++) {
            $result = $this->generateDescription($prompt, $videoTitle);
            if (isset($result['description']) && !empty($result['description'])) {
                $descriptions[] = $result['description'];
            }
            // Small delay between bulk generations
            if ($i < $count - 1) {
                sleep(1);
            }
        }

        return [
            'success' => count($descriptions) > 0,
            'count_requested' => $count,
            'count_generated' => count($descriptions),
            'descriptions' => $descriptions
        ];
    }

    /**
     * Call Gemini for description generation
     */
    private function callGeminiForDescription($apiKey, $prompt) {
        $data = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.8,
                'maxOutputTokens' => 200
            ]
        ];

        $response = $this->sendGeminiRequest($apiKey, $data, 30);
        if (empty($response['success'])) {
            return [
                'error' => 'Gemini API error: ' . ($response['error'] ?? 'Unknown error'),
                'description' => ''
            ];
        }

        $result = $response['data'] ?? [];
        $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Clean the response
        $content = trim((string)$content);

        return [
            'success' => true,
            'description' => $content,
            'provider' => 'gemini'
        ];
    }

    /**
     * Call OpenAI for description generation
     */
    private function callOpenAIForDescription($apiKey, $prompt) {
        // Enforce rate limiting (respect API limits)
        $this->enforceRateLimit();
        
        $url = 'https://api.openai.com/v1/chat/completions';

        $data = [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 200,
            'temperature' => 0.8
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return [
                'error' => 'OpenAI API error: ' . $response,
                'description' => ''
            ];
        }

        $result = json_decode($response, true);
        $content = $result['choices'][0]['message']['content'] ?? '';

        return [
            'success' => true,
            'description' => trim($content),
            'provider' => 'openai'
        ];
    }

    private function callOllamaForDescription($prompt) {
        if (!$this->isProviderAvailable('ollama')) {
            return [
                'error' => 'Ollama is not configured.',
                'description' => ''
            ];
        }

        $response = $this->ollamaClient->generateText(
            "Return only the description text. No quotes. No markdown.\n" . $prompt,
            ['temperature' => 0.8, 'max_tokens' => 200],
            90
        );

        if (empty($response['success'])) {
            return [
                'error' => 'Ollama API error: ' . ($response['error'] ?? 'Unknown error'),
                'description' => ''
            ];
        }

        return [
            'success' => true,
            'description' => trim((string)($response['text'] ?? '')),
            'provider' => 'ollama'
        ];
    }

    /**
     * Call Google Gemini API (FREE tier available)
     */
    private function callGemini($apiKey, $prompt) {
        $data = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.9,
                'maxOutputTokens' => 1000,
                'responseMimeType' => 'application/json'
            ]
        ];

        $response = $this->sendGeminiRequest($apiKey, $data, 30);
        if (empty($response['success'])) {
            return ['error' => $response['error'] ?? 'Gemini API failed', 'top' => '', 'bottom' => ''];
        }

        $result = $response['data'] ?? [];
        $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        return $this->parseTaglineResponse($content, 'gemini');
    }
    
    /**
     * Call OpenAI API
     */
    private function callOpenAI($apiKey, $prompt) {
        // Enforce rate limiting (respect API limits)
        $this->enforceRateLimit();
        
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
        
        return $this->parseTaglineResponse($content, 'openai');
    }

    private function callOllama($prompt) {
        if (!$this->isProviderAvailable('ollama')) {
            return ['error' => 'Ollama is not configured', 'top' => '', 'bottom' => ''];
        }

        $response = $this->ollamaClient->generateJson(
            "Return valid JSON only.\n" . $prompt,
            ['temperature' => 0.9, 'max_tokens' => 300],
            90
        );

        if (empty($response['success'])) {
            return ['error' => $response['error'] ?? 'Ollama API failed', 'top' => '', 'bottom' => ''];
        }

        return $this->parseTaglineResponse($response['data'] ?? [], 'ollama');
    }
    
    /**
     * Parse tagline JSON from AI response
     */
    private function parseTaglineResponse($content, $provider = null) {
        if (is_array($content)) {
            $taglines = $content;
        } else {
            $content = $this->cleanAiResponse($content);
            $taglines = json_decode($content, true);

            if (!$taglines) {
                $content = $this->extractBalancedJson($content, '{', '}');
                $taglines = $content !== '' ? json_decode($content, true) : null;
            }
        }
        
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
            'provider' => $provider ?: $this->getActiveProvider()
        ];
    }
    
    /**
     * Generate multiple unique taglines at once (supports Gemini + OpenAI)
     * Pre-generates a LIST of taglines for batch video processing
     */
    public function generateBulkTaglines($prompt, $count = 5, $previousUsed = []) {
        $providers = $this->getProviderOrder();
        
        if (empty($providers)) {
            return ['error' => 'No AI provider configured.'];
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
        
        $errors = [];
        $result = null;
        foreach ($providers as $provider) {
            if ($provider === 'gemini') {
                $result = $this->callGeminiBulk($this->geminiKey, $userPrompt, $count);
            } elseif ($provider === 'openai') {
                $result = $this->callOpenAIBulk($this->openaiKey, $userPrompt, $count);
            } else {
                $result = $this->callOllamaBulk($userPrompt, $count);
            }

            if (!empty($result['success']) && !empty($result['taglines'])) {
                break;
            }

            $this->addProviderError($errors, $provider, is_array($result) ? $result : []);
        }
        
        // Validate and filter duplicates
        if (isset($result['taglines']) && is_array($result['taglines'])) {
            $result['taglines'] = $this->filterDuplicates($result['taglines'], $previousUsed);
        }

        if (empty($result['success']) && !empty($errors)) {
            $result['error'] = implode(' | ', $errors);
        }
        
        return $result;
    }
    
    /**
     * Call Gemini API for bulk taglines (FREE)
     */
    private function callGeminiBulk($apiKey, $prompt, $count) {
        $data = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.95, // High for variation
                'maxOutputTokens' => max(200, $count * 100), // Scale with count
                'responseMimeType' => 'application/json'
            ]
        ];

        $response = $this->sendGeminiRequest($apiKey, $data, 60);
        if (empty($response['success'])) {
            return ['error' => $response['error'] ?? 'Gemini API failed'];
        }

        $result = $response['data'] ?? [];
        $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        return $this->parseBulkResponse($content, 'gemini');
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
        
        return $this->parseBulkResponse($content, 'openai');
    }

    private function callOllamaBulk($prompt, $count) {
        if (!$this->isProviderAvailable('ollama')) {
            return ['error' => 'Ollama is not configured'];
        }

        $response = $this->ollamaClient->generateJson(
            "Return valid JSON only.\n" . $prompt,
            ['temperature' => 0.95, 'max_tokens' => max(300, $count * 120)],
            120
        );

        if (empty($response['success'])) {
            return ['error' => $response['error'] ?? 'Ollama API failed'];
        }

        return $this->parseBulkResponse($response['data'] ?? [], 'ollama');
    }
    
    /**
     * Parse bulk taglines response
     */
    private function parseBulkResponse($content, $provider = null) {
        if (is_array($content)) {
            $taglines = $content;
        } else {
            $content = $this->cleanAiResponse($content);
            $taglines = json_decode($content, true);

            if (!$taglines) {
                $content = $this->extractBalancedJson($content, '[', ']');
                $taglines = $content !== '' ? json_decode($content, true) : null;
            }
        }
        
        if (!$taglines || !is_array($taglines)) {
            return ['error' => 'Failed to parse AI response', 'raw' => $content];
        }
        
        $valid = [];
        foreach ($taglines as $t) {
            if (isset($t['top']) && isset($t['bottom'])) {
                $valid[] = [
                    'top' => trim((string)$t['top']),
                    'bottom' => trim((string)$t['bottom'])
                ];
            }
        }
        
        return [
            'success' => true,
            'taglines' => $valid,
            'count' => count($valid),
            'provider' => $provider ?: $this->getActiveProvider()
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

    private function cleanAiResponse($content) {
        $content = trim((string)$content);
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        $content = preg_replace('/^```\s*/i', '', $content);
        return trim((string)$content);
    }

    private function extractBalancedJson($content, $openChar = '{', $closeChar = '}') {
        $content = (string)$content;
        $start = strpos($content, $openChar);
        if ($start === false) {
            return '';
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($content);

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($char === '\\') {
                $escape = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === $openChar) {
                $depth++;
            } elseif ($char === $closeChar) {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return '';
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
        $provider = $this->getActiveProvider();
        if (!$provider) {
            return ['error' => 'No AI API key configured'];
        }
        
        $result = $this->generateTaglines('Generate a fun birthday greeting tagline', 'Birthday Video');
        
        if (isset($result['error'])) {
            return $result;
        }
        
        return [
            'success' => true,
            'sample_top' => $result['top'],
            'sample_bottom' => $result['bottom'],
            'provider' => $provider
        ];
    }
}
?>
