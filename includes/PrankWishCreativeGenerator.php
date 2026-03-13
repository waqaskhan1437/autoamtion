<?php

require_once __DIR__ . '/PrankWishSocialContent.php';
require_once __DIR__ . '/OllamaClient.php';

class PrankWishCreativeGenerator
{
    private ?PDO $pdo;
    private ?string $geminiKey = null;
    private ?string $openaiKey = null;
    private ?OllamaClient $ollamaClient = null;
    private string $provider = 'gemini';
    private string $geminiModel = 'gemini-2.5-flash';
    private int $geminiRetryAttempts = 3;
    private int $geminiMaxRetryDelay = 90;
    private string $websiteUrl = 'https://prankwish.com';
    private string $brandName = 'PrankWish.com';
    private string $taglineLibraryPath;
    private array $taglineLibrary = [];
    private array $platformConfigs = [
        'youtube' => ['title_limit' => 100, 'description_limit' => 4500, 'caption_limit' => 4500, 'tag_limit' => 15],
        'tiktok' => ['title_limit' => 100, 'description_limit' => 600, 'caption_limit' => 600, 'tag_limit' => 10],
        'instagram' => ['title_limit' => 100, 'description_limit' => 1200, 'caption_limit' => 1200, 'tag_limit' => 10],
        'facebook' => ['title_limit' => 100, 'description_limit' => 1400, 'caption_limit' => 1400, 'tag_limit' => 10],
        'twitter' => ['title_limit' => 90, 'description_limit' => 220, 'caption_limit' => 280, 'tag_limit' => 8],
        'threads' => ['title_limit' => 100, 'description_limit' => 600, 'caption_limit' => 600, 'tag_limit' => 8],
        'linkedin' => ['title_limit' => 120, 'description_limit' => 1200, 'caption_limit' => 1200, 'tag_limit' => 12],
        'pinterest' => ['title_limit' => 100, 'description_limit' => 900, 'caption_limit' => 900, 'tag_limit' => 12],
        'bluesky' => ['title_limit' => 90, 'description_limit' => 260, 'caption_limit' => 300, 'tag_limit' => 8],
    ];
    private array $coverageThemes = [
        'birthday gift for brother',
        'birthday gift for sister',
        'happy birthday mother',
        'happy birthday father',
        'funny birthday gift for friend',
        'roast video for best friend',
        'birthday surprise for girlfriend',
        'birthday surprise for boyfriend',
        'mother\'s day custom video',
        'father\'s day gift video',
        'valentine\'s day custom video',
        'merry christmas video gift',
        'new year surprise video',
        'graduation celebration video',
        'wedding surprise video',
        'anniversary video gift',
        'congratulations video message',
        'custom video for wife',
        'custom video for husband',
        'custom video for son',
        'custom video for daughter',
        'eid greeting video',
        'family celebration video',
        'friends celebration video',
    ];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
        $this->taglineLibraryPath = dirname(__DIR__) . '/content/prankwish-social/tagline-library.json';
        $this->loadSettings();
        $this->taglineLibrary = $this->loadTaglineLibrary();
    }

    public function saveGeminiKey(string $apiKey): bool
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '' || !$this->pdo) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute(['gemini_api_key', $apiKey]);
            $stmt->execute(['ai_provider', 'gemini']);
            $this->loadSettings();
            return true;
        } catch (Exception $e) {
            error_log('PrankWishCreativeGenerator saveGeminiKey failed: ' . $e->getMessage());
            return false;
        }
    }

    public function buildNextCreativePackage(
        int $automationId,
        string $videoTitle = '',
        ?string $forcedOccasion = null,
        ?string $videoFilename = null
    ): array {
        $social = new PrankWishSocialContent($this->pdo);
        $basePackage = $social->getNextPostPackage(
            $automationId,
            $videoTitle,
            $forcedOccasion,
            $videoFilename
        );

        if (empty($basePackage['success'])) {
            return $basePackage;
        }

        $generated = null;
        if ($this->hasActiveAi()) {
            $generated = $this->generateWithAi($automationId, $videoTitle, $videoFilename ?? $videoTitle, $basePackage);
        }

        if (!empty($generated['success'])) {
            return $generated;
        }

        if ($this->pdo && !empty($generated['error'])) {
            $this->insertLog(
                $automationId,
                'creative_ai_fallback',
                'error',
                [
                    'provider' => (string)($generated['provider'] ?? $this->provider),
                    'source' => (string)($generated['source'] ?? 'prankwish_ai'),
                    'error' => (string)$generated['error'],
                ],
                $videoFilename ?? $videoTitle,
                'overlay'
            );
        }

        return $this->buildFallbackPackage($basePackage, $videoTitle);
    }

    public function logAppliedPackage(int $automationId, string $videoId, array $package): void
    {
        if (!$this->pdo) {
            return;
        }

        try {
            $taglinePayload = [
                'source' => (string)($package['source'] ?? 'unknown'),
                'provider' => (string)($package['provider'] ?? 'fallback'),
                'model' => (string)($package['model'] ?? ''),
                'cycle' => (int)($package['cycle'] ?? 1),
                'occasion_key' => (string)($package['occasion_key'] ?? ''),
                'occasion_name' => (string)($package['occasion_name'] ?? ''),
                'top' => (string)($package['top'] ?? ''),
                'bottom' => (string)($package['bottom'] ?? ''),
            ];
            $this->insertLog($automationId, 'creative_tagline_applied', 'success', $taglinePayload, $videoId, 'overlay');

            foreach ((array)($package['platforms'] ?? []) as $platform => $content) {
                if (!is_array($content)) {
                    continue;
                }

                $payload = [
                    'source' => (string)($package['source'] ?? 'unknown'),
                    'provider' => (string)($package['provider'] ?? 'fallback'),
                    'model' => (string)($package['model'] ?? ''),
                    'cycle' => (int)($package['cycle'] ?? 1),
                    'occasion_key' => (string)($package['occasion_key'] ?? ''),
                    'occasion_name' => (string)($package['occasion_name'] ?? ''),
                    'title' => (string)($content['title'] ?? ''),
                    'description' => (string)($content['description'] ?? ''),
                    'caption' => (string)($content['caption'] ?? ''),
                    'hashtags' => array_values((array)($content['hashtags'] ?? [])),
                    'tags' => array_values((array)($content['tags'] ?? [])),
                ];
                $this->insertLog($automationId, 'creative_platform_applied', 'success', $payload, $videoId, (string)$platform);
            }
        } catch (Exception $e) {
            error_log('PrankWishCreativeGenerator logAppliedPackage failed: ' . $e->getMessage());
        }
    }

    private function loadSettings(): void
    {
        if (!$this->pdo) {
            return;
        }

        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('gemini_api_key', 'openai_api_key', 'ai_provider')");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            $this->geminiKey = $this->cleanSetting($settings['gemini_api_key'] ?? null);
            $this->openaiKey = $this->cleanSetting($settings['openai_api_key'] ?? null);
            $this->provider = trim((string)($settings['ai_provider'] ?? 'gemini')) ?: 'gemini';
            $this->ollamaClient = OllamaClient::fromSettings($this->pdo);
        } catch (Exception $e) {
            $this->geminiKey = null;
            $this->openaiKey = null;
            $this->provider = 'gemini';
            $this->ollamaClient = OllamaClient::fromSettings();
        }
    }

    private function cleanSetting($value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function hasActiveAi(): bool
    {
        return !empty($this->getProviderOrder());
    }

    private function getProviderOrder(): array
    {
        $preferred = strtolower(trim($this->provider));
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

    private function isProviderAvailable(string $provider): bool
    {
        if ($provider === 'gemini') {
            return $this->geminiKey !== null;
        }

        if ($provider === 'openai') {
            return $this->openaiKey !== null;
        }

        if ($provider === 'ollama') {
            return $this->ollamaClient instanceof OllamaClient && $this->ollamaClient->isConfigured();
        }

        return false;
    }

    private function buildProviderFailure(string $provider, string $message): string
    {
        $message = trim($message);
        return strtoupper($provider) . ': ' . ($message !== '' ? $message : 'AI generation failed.');
    }

    private function generateWithAi(int $automationId, string $videoTitle, string $videoFilename, array $basePackage): array
    {
        $history = $this->getRecentHistory($automationId);
        $prompt = $this->buildPrompt($videoTitle, $videoFilename, $basePackage, $history);
        $errors = [];

        foreach ($this->getProviderOrder() as $provider) {
            if ($provider === 'openai') {
                $raw = $this->callOpenAiJson($prompt);
            } elseif ($provider === 'ollama') {
                $raw = $this->callOllamaJson($prompt);
            } else {
                $raw = $this->callGeminiJson($prompt);
            }

            if (empty($raw['success']) || !is_array($raw['data'] ?? null)) {
                $errors[] = $this->buildProviderFailure(
                    (string)($raw['provider'] ?? $provider),
                    (string)($raw['error'] ?? 'AI generation failed.')
                );
                continue;
            }

            $normalized = $this->normalizeGeneratedPackage($basePackage, $raw['data'], $videoTitle, [
                'source' => $raw['source'] ?? ('prankwish_' . $provider),
                'provider' => $raw['provider'] ?? $provider,
                'model' => $raw['model'] ?? ($provider === 'gemini' ? $this->geminiModel : ''),
            ]);

            if (($normalized['provider'] ?? '') === 'fallback' || ($normalized['source'] ?? '') === 'prankwish_fallback_library') {
                $errors[] = $this->buildProviderFailure(
                    (string)($raw['provider'] ?? $provider),
                    'AI response was incomplete or invalid for PrankWish content.'
                );
                continue;
            }

            return $normalized;
        }

        return [
            'success' => false,
            'provider' => $this->provider,
            'source' => 'prankwish_ai',
            'error' => !empty($errors) ? implode(' | ', $errors) : 'AI generation failed.',
        ];
    }

    private function buildPrompt(string $videoTitle, string $videoFilename, array $basePackage, array $history): string
    {
        $cycle = (int)($basePackage['cycle'] ?? 1);
        $coverageTerms = $this->pickCoverageTerms($cycle);
        $seedPlatforms = [];
        foreach ((array)($basePackage['platforms'] ?? []) as $platform => $content) {
            if (!is_array($content)) {
                continue;
            }
            $seedPlatforms[$platform] = [
                'title' => (string)($content['title'] ?? ''),
                'description' => (string)($content['description'] ?? ''),
                'hashtags' => array_values(array_slice((array)($content['hashtags'] ?? []), 0, 6)),
            ];
        }

        $recentTitles = array_slice(array_values(array_unique(array_filter($history['titles'] ?? []))), 0, 12);
        $recentDescriptions = array_slice(array_values(array_unique(array_filter($history['descriptions'] ?? []))), 0, 8);
        $recentTaglines = array_slice(array_values(array_unique(array_filter($history['taglines'] ?? []))), 0, 12);

        $prompt = [
            'task' => 'Create unique but meaning-preserving social copy and overlay taglines for a PrankWish.com custom video post.',
            'brand' => [
                'name' => $this->brandName,
                'website' => $this->websiteUrl,
                'core_offer' => 'Get personalized custom video from real people or real teams.',
                'order_flow' => [
                    'Choose a style on PrankWish.com.',
                    'Send your custom script, name, brief, or photos.',
                    'Receive the finished video digitally on email or WhatsApp.',
                ],
                'tone' => 'roughly human, natural, direct, not robotic',
            ],
            'video_context' => [
                'video_title' => $videoTitle,
                'video_filename' => $videoFilename,
                'occasion_key' => (string)($basePackage['occasion_key'] ?? ''),
                'occasion_name' => (string)($basePackage['occasion_name'] ?? ''),
                'primary_keyword' => (string)($basePackage['primary_keyword'] ?? ''),
                'coverage_terms' => $coverageTerms,
            ],
            'must_follow' => [
                'Generate fresh wording for every field. Keep the meaning but do not copy the seed package or recent history.',
                'Titles must stay service-led and brand-led, not occasion-led.',
                'Never start any title with phrases like Happy Birthday, Merry Christmas, Happy New Year, Mother\'s Day, Father\'s Day, Valentine\'s Day, Wedding, Graduation, Congratulations, Brother, Sister, Mom, Dad, Boyfriend, or Girlfriend.',
                'Descriptions must mention PrankWish.com and explain the 3-step order flow in natural wording.',
                'Descriptions may naturally mention occasion and relationship search intent like mother, father, brother, sister, friend, boyfriend, girlfriend, wedding, graduation, Christmas, New Year, Valentine\'s Day, and birthday gift.',
                'Top tagline must be 4 to 8 words, punchy, human, no hashtags, no emojis.',
                'Bottom tagline must be 3 to 6 words, must contain prankwish.com, no hashtags.',
                'Do not include quotation marks around the output text.',
                'Do not invent pricing, guarantees, or unsupported delivery promises.',
            ],
            'seed_package' => [
                'cycle' => $cycle,
                'seed_platforms' => $seedPlatforms,
            ],
            'avoid_recent_titles' => $recentTitles,
            'avoid_recent_descriptions' => $recentDescriptions,
            'avoid_recent_taglines' => $recentTaglines,
            'output_format' => [
                'top_tagline' => 'string',
                'bottom_tagline' => 'string',
                'platforms' => [
                    'youtube' => ['title' => 'string', 'description' => 'string'],
                    'tiktok' => ['title' => 'string', 'description' => 'string'],
                    'instagram' => ['title' => 'string', 'description' => 'string'],
                    'facebook' => ['title' => 'string', 'description' => 'string'],
                    'twitter' => ['title' => 'string', 'description' => 'string'],
                    'threads' => ['title' => 'string', 'description' => 'string'],
                    'linkedin' => ['title' => 'string', 'description' => 'string'],
                    'pinterest' => ['title' => 'string', 'description' => 'string'],
                    'bluesky' => ['title' => 'string', 'description' => 'string'],
                ],
            ],
        ];

        return "Return valid JSON only.\n" . json_encode($prompt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function callGeminiJson(string $prompt): array
    {
        if (!$this->geminiKey) {
            return ['success' => false, 'error' => 'Gemini API key not configured.'];
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->geminiModel) . ':generateContent';
        $body = [
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => 1.0,
                'topP' => 0.95,
                'maxOutputTokens' => 4096,
                'responseMimeType' => 'application/json',
            ],
        ];

        $response = $this->sendGeminiRequest($url, $body);
        if (empty($response['success'])) {
            return [
                'success' => false,
                'provider' => 'gemini',
                'source' => 'prankwish_gemini',
                'error' => (string)($response['error'] ?? 'Gemini API failed'),
            ];
        }

        $decoded = $response['data'] ?? [];
        $text = trim((string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        $data = $this->decodeJsonPayload($text);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Gemini JSON response could not be parsed.'];
        }

        return [
            'success' => true,
            'source' => 'prankwish_gemini',
            'provider' => 'gemini',
            'model' => $this->geminiModel,
            'data' => $data,
        ];
    }

    private function callOllamaJson(string $prompt): array
    {
        if (!$this->isProviderAvailable('ollama')) {
            return ['success' => false, 'error' => 'Ollama is not configured.', 'provider' => 'ollama', 'source' => 'prankwish_ollama'];
        }

        $response = $this->ollamaClient->generateJson(
            "Return valid JSON only.\n" . $prompt,
            ['temperature' => 1.0, 'top_p' => 0.95, 'max_tokens' => 4096],
            120
        );

        if (empty($response['success'])) {
            return [
                'success' => false,
                'provider' => 'ollama',
                'source' => 'prankwish_ollama',
                'error' => (string)($response['error'] ?? 'Ollama API failed'),
            ];
        }

        return [
            'success' => true,
            'source' => 'prankwish_ollama',
            'provider' => 'ollama',
            'model' => (string)($response['model'] ?? $this->ollamaClient->getModel()),
            'data' => (array)($response['data'] ?? []),
        ];
    }

    private function sendGeminiRequest(string $url, array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $lastError = 'Gemini API failed';

        for ($attempt = 1; $attempt <= $this->geminiRetryAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $this->geminiKey,
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => 60,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return ['success' => false, 'error' => $curlError, 'http_code' => 0];
            }

            if ($httpCode === 200) {
                return [
                    'success' => true,
                    'http_code' => 200,
                    'data' => json_decode((string)$response, true),
                ];
            }

            $decoded = json_decode((string)$response, true);
            $lastError = (string)($decoded['error']['message'] ?? 'Gemini API failed');

            if ($attempt < $this->geminiRetryAttempts && $this->shouldRetryGeminiRequest($httpCode, $lastError)) {
                sleep($this->getGeminiRetryDelaySeconds($lastError, $attempt));
                continue;
            }

            return ['success' => false, 'error' => $lastError, 'http_code' => $httpCode];
        }

        return ['success' => false, 'error' => $lastError, 'http_code' => 429];
    }

    private function shouldRetryGeminiRequest(int $httpCode, string $message): bool
    {
        $message = strtolower(trim($message));
        if (in_array($httpCode, [429, 500, 503], true)) {
            return true;
        }

        return str_contains($message, 'quota exceeded')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'retry in')
            || str_contains($message, 'resource has been exhausted');
    }

    private function getGeminiRetryDelaySeconds(string $message, int $attempt): int
    {
        if (preg_match('/retry in\s+([0-9]+(?:\.[0-9]+)?)s/i', $message, $matches)) {
            $delay = (int)ceil((float)$matches[1]) + 1;
            return max(5, min($this->geminiMaxRetryDelay, $delay));
        }

        $fallbackDelay = (int)(15 * max(1, $attempt));
        return max(10, min($this->geminiMaxRetryDelay, $fallbackDelay));
    }

    private function callOpenAiJson(string $prompt): array
    {
        if (!$this->openaiKey) {
            return ['success' => false, 'error' => 'OpenAI API key not configured.'];
        }

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Return valid JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 1.0,
                'max_tokens' => 2500,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => $curlError];
        }

        if ($httpCode !== 200) {
            $decoded = json_decode((string)$response, true);
            return ['success' => false, 'error' => (string)($decoded['error']['message'] ?? 'OpenAI API failed')];
        }

        $decoded = json_decode((string)$response, true);
        $text = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
        $data = $this->decodeJsonPayload($text);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'OpenAI JSON response could not be parsed.'];
        }

        return [
            'success' => true,
            'source' => 'prankwish_openai',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'data' => $data,
        ];
    }

    private function decodeJsonPayload(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```json\s*/i', '', $text) ?? $text;
        $text = preg_replace('/^```\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeGeneratedPackage(array $basePackage, array $data, string $videoTitle, array $meta): array
    {
        $top = $this->normalizeOverlayText((string)($data['top_tagline'] ?? ''), 48);
        $bottom = $this->normalizeBottomTagline((string)($data['bottom_tagline'] ?? ''));

        if ($top === '' || $bottom === '') {
            return $this->buildFallbackPackage($basePackage, $videoTitle);
        }

        $platforms = [];
        foreach ((array)($basePackage['platforms'] ?? []) as $platform => $baseContent) {
            if (!is_array($baseContent)) {
                continue;
            }

            $generated = is_array($data['platforms'][$platform] ?? null) ? $data['platforms'][$platform] : [];
            $platforms[$platform] = $this->normalizePlatformContent($platform, $generated, $baseContent);
        }

        if (empty($platforms)) {
            return $this->buildFallbackPackage($basePackage, $videoTitle);
        }

        return [
            'success' => true,
            'source' => (string)($meta['source'] ?? 'prankwish_gemini'),
            'provider' => (string)($meta['provider'] ?? 'gemini'),
            'model' => (string)($meta['model'] ?? $this->geminiModel),
            'cycle' => (int)($basePackage['cycle'] ?? 1),
            'variant' => (int)($basePackage['variant'] ?? 1),
            'occasion_key' => (string)($basePackage['occasion_key'] ?? ''),
            'occasion_name' => (string)($basePackage['occasion_name'] ?? ''),
            'primary_keyword' => (string)($basePackage['primary_keyword'] ?? ''),
            'top' => $top,
            'bottom' => $bottom,
            'platforms' => $platforms,
            'platform_overrides' => $this->buildPlatformOverrides($platforms),
            'caption' => $this->buildDefaultCaption($platforms),
        ];
    }

    private function normalizePlatformContent(string $platform, array $generated, array $base): array
    {
        $config = $this->platformConfigs[$platform] ?? $this->platformConfigs['instagram'];
        $fallbackTitle = (string)($base['title'] ?? '');
        $fallbackDescription = (string)($base['description'] ?? $base['caption'] ?? '');

        $title = $this->normalizeTitle(
            trim((string)($generated['title'] ?? '')),
            $fallbackTitle,
            (int)$config['title_limit']
        );

        $description = $this->normalizeDescription(
            trim((string)($generated['description'] ?? $generated['caption'] ?? '')),
            $fallbackDescription,
            $platform,
            (int)$config['description_limit']
        );

        $hashtags = array_values((array)($base['hashtags'] ?? []));
        $caption = $description;
        if (!empty($hashtags)) {
            $caption .= "\n\n" . implode(' ', $hashtags);
        }

        return [
            'platform' => $platform,
            'title' => $title,
            'description' => $description,
            'caption' => $this->smartTrim($caption, (int)$config['caption_limit']),
            'hashtags' => $hashtags,
            'tags' => array_values(array_slice((array)($base['tags'] ?? []), 0, (int)$config['tag_limit'])),
            'keywords' => array_values((array)($base['keywords'] ?? [])),
            'call_to_action' => (string)($base['call_to_action'] ?? ('Start at ' . $this->websiteUrl . '.')),
        ];
    }

    private function normalizeTitle(string $generated, string $fallback, int $limit): string
    {
        $generated = $this->cleanText($generated);
        $fallback = $this->cleanText($fallback);
        if ($generated === '' || $this->isOccasionLedTitle($generated)) {
            $generated = $fallback;
        }

        if ($generated === '') {
            $generated = 'Get a personalized custom video from PrankWish.com';
        }

        if (stripos($generated, 'prankwish') === false) {
            $brandSuffix = str_contains($generated, '|') ? ' PrankWish.com' : ' | PrankWish.com';
            $generated = $this->smartTrim($generated . $brandSuffix, $limit);
        }

        return $this->smartTrim($generated, $limit);
    }

    private function normalizeDescription(string $generated, string $fallback, string $platform, int $limit): string
    {
        $text = $this->cleanText($generated);
        if ($text === '') {
            $text = $this->cleanText($fallback);
        }

        if ($text === '') {
            $text = 'Get personalized custom video at PrankWish.com.';
        }

        if (stripos($text, 'prankwish.com') === false) {
            $text .= ' Get personalized custom video at ' . $this->websiteUrl . '.';
        }

        $needsChoose = stripos($text, 'choose a style') === false;
        $needsScript = stripos($text, 'custom script') === false && stripos($text, 'send your script') === false;
        $needsDelivery = stripos($text, 'email') === false || stripos($text, 'whatsapp') === false;

        $append = [];
        if ($needsChoose) {
            $append[] = 'Choose a style on PrankWish.com.';
        }
        if ($needsScript) {
            $append[] = 'Send your custom script, name, brief, or photos.';
        }
        if ($needsDelivery) {
            $append[] = 'Receive the finished video digitally on email or WhatsApp.';
        }

        if (!empty($append)) {
            $text = rtrim($text, " \t\n\r\0\x0B.") . '. ' . implode(' ', $append);
        }

        if (($platform === 'twitter' || $platform === 'bluesky') && strlen($text) > $limit) {
            $text = 'Get personalized custom video at PrankWish.com. Choose a style, send your script, and get delivery on email or WhatsApp.';
        }

        return $this->smartTrim($text, $limit);
    }

    private function buildFallbackPackage(array $basePackage, string $videoTitle): array
    {
        $pair = $this->pickFallbackTaglinePair((int)($basePackage['cycle'] ?? 1), $videoTitle, $basePackage);
        $platforms = [];

        foreach ((array)($basePackage['platforms'] ?? []) as $platform => $content) {
            if (!is_array($content)) {
                continue;
            }
            $platforms[$platform] = $this->normalizePlatformContent($platform, [], $content);
        }

        return [
            'success' => true,
            'source' => 'prankwish_fallback_library',
            'provider' => 'fallback',
            'model' => '',
            'cycle' => (int)($basePackage['cycle'] ?? 1),
            'variant' => (int)($basePackage['variant'] ?? 1),
            'occasion_key' => (string)($basePackage['occasion_key'] ?? ''),
            'occasion_name' => (string)($basePackage['occasion_name'] ?? ''),
            'primary_keyword' => (string)($basePackage['primary_keyword'] ?? ''),
            'top' => $pair['top'],
            'bottom' => $pair['bottom'],
            'platforms' => $platforms,
            'platform_overrides' => $this->buildPlatformOverrides($platforms),
            'caption' => $this->buildDefaultCaption($platforms),
        ];
    }

    private function buildPlatformOverrides(array $platforms): array
    {
        $overrides = [];

        foreach ($platforms as $platform => $content) {
            if (!is_array($content)) {
                continue;
            }

            switch ($platform) {
                case 'youtube':
                    $overrides['youtube'] = [
                        'title' => (string)($content['title'] ?? ''),
                        'description' => (string)($content['caption'] ?? $content['description'] ?? ''),
                        'tags' => array_values((array)($content['tags'] ?? [])),
                        'privacy' => 'public',
                        'shorts' => true,
                    ];
                    break;
                case 'tiktok':
                    $overrides['tiktok'] = [
                        'caption' => (string)($content['caption'] ?? ''),
                        'allow_comments' => true,
                        'allow_duet' => true,
                        'allow_stitch' => true,
                    ];
                    break;
                case 'instagram':
                    $overrides['instagram'] = [
                        'caption' => (string)($content['caption'] ?? ''),
                        'share_to_feed' => true,
                    ];
                    break;
                case 'facebook':
                    $overrides['facebook'] = [
                        'caption' => (string)($content['caption'] ?? ''),
                        'description' => (string)($content['caption'] ?? $content['description'] ?? ''),
                    ];
                    break;
                case 'twitter':
                    $overrides['twitter'] = ['caption' => $this->smartTrim((string)($content['caption'] ?? ''), 280)];
                    $overrides['x'] = $overrides['twitter'];
                    break;
                case 'threads':
                    $overrides['threads'] = ['caption' => (string)($content['caption'] ?? '')];
                    break;
                case 'linkedin':
                    $overrides['linkedin'] = [
                        'caption' => (string)($content['caption'] ?? ''),
                        'title' => (string)($content['title'] ?? ''),
                    ];
                    break;
                case 'pinterest':
                    $overrides['pinterest'] = [
                        'title' => (string)($content['title'] ?? ''),
                        'description' => (string)($content['caption'] ?? $content['description'] ?? ''),
                        'link' => $this->websiteUrl,
                    ];
                    break;
                case 'bluesky':
                    $overrides['bluesky'] = ['caption' => $this->smartTrim((string)($content['caption'] ?? ''), 300)];
                    break;
            }
        }

        return $overrides;
    }

    private function buildDefaultCaption(array $platforms): string
    {
        foreach (['instagram', 'tiktok', 'facebook', 'youtube', 'twitter'] as $platform) {
            if (!empty($platforms[$platform]['caption'])) {
                return (string)$platforms[$platform]['caption'];
            }
        }

        return 'Get personalized custom video at ' . $this->websiteUrl . '.';
    }

    private function loadTaglineLibrary(): array
    {
        if (!is_file($this->taglineLibraryPath)) {
            return [];
        }

        $json = @file_get_contents($this->taglineLibraryPath);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($decoded) || !is_array($decoded['pairs'] ?? null)) {
            return [];
        }

        $pairs = [];
        foreach ($decoded['pairs'] as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $top = $this->normalizeOverlayText((string)($pair['top'] ?? ''), 48);
            $bottom = $this->normalizeBottomTagline((string)($pair['bottom'] ?? ''));
            if ($top === '' || $bottom === '') {
                continue;
            }

            $pairs[] = ['top' => $top, 'bottom' => $bottom];
        }

        return [
            'library_key' => trim((string)($decoded['library_key'] ?? 'prankwish-tagline-library')),
            'pairs' => $pairs,
        ];
    }

    private function pickFallbackTaglinePair(int $cycle, string $videoTitle, array $basePackage): array
    {
        $pairs = (array)($this->taglineLibrary['pairs'] ?? []);
        if (!empty($pairs)) {
            $index = max(0, ($cycle - 1) % count($pairs));
            return $pairs[$index];
        }

        $fallbackTop = $this->cleanText((string)($videoTitle ?: ($basePackage['occasion_name'] ?? 'This one feels personal')));
        $fallbackTop = $this->normalizeOverlayText($fallbackTop !== '' ? $fallbackTop : 'This one feels personal', 48);
        if ($fallbackTop === '') {
            $fallbackTop = 'This one feels personal';
        }

        return [
            'top' => $fallbackTop,
            'bottom' => 'Order on prankwish.com',
        ];
    }

    private function getRecentHistory(int $automationId): array
    {
        $result = [
            'titles' => [],
            'descriptions' => [],
            'taglines' => [],
        ];

        if (!$this->pdo) {
            return $result;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT action, message
                FROM automation_logs
                WHERE automation_id = ?
                  AND action IN ('creative_tagline_applied', 'creative_platform_applied')
                ORDER BY id DESC
                LIMIT 80
            ");
            $stmt->execute([$automationId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $payload = json_decode((string)($row['message'] ?? ''), true);
                if (!is_array($payload)) {
                    continue;
                }

                if (($row['action'] ?? '') === 'creative_tagline_applied') {
                    $top = trim((string)($payload['top'] ?? ''));
                    $bottom = trim((string)($payload['bottom'] ?? ''));
                    if ($top !== '') {
                        $result['taglines'][] = $top;
                    }
                    if ($bottom !== '') {
                        $result['taglines'][] = $bottom;
                    }
                    continue;
                }

                $title = trim((string)($payload['title'] ?? ''));
                $description = trim((string)($payload['description'] ?? $payload['caption'] ?? ''));
                if ($title !== '') {
                    $result['titles'][] = $title;
                }
                if ($description !== '') {
                    $result['descriptions'][] = $description;
                }
            }
        } catch (Exception $e) {
            error_log('PrankWishCreativeGenerator getRecentHistory failed: ' . $e->getMessage());
        }

        return $result;
    }

    private function pickCoverageTerms(int $cycle): array
    {
        $count = count($this->coverageThemes);
        if ($count === 0) {
            return [];
        }

        $terms = [];
        $start = ($cycle - 1) % $count;
        for ($i = 0; $i < 6; $i++) {
            $terms[] = $this->coverageThemes[($start + $i) % $count];
        }

        return $terms;
    }

    private function normalizeOverlayText(string $text, int $limit): string
    {
        $text = $this->cleanText($text);
        $text = preg_replace('/[#@]/', '', $text) ?? $text;
        $text = trim((string)$text, " \t\n\r\0\x0B-_|");
        return $this->smartTrim($text, $limit);
    }

    private function normalizeBottomTagline(string $text): string
    {
        $text = $this->normalizeOverlayText($text, 36);
        if ($text === '') {
            return 'Order on prankwish.com';
        }

        if (stripos($text, 'prankwish.com') === false) {
            $text = 'Order on prankwish.com';
        }

        return $this->smartTrim($text, 36);
    }

    private function isOccasionLedTitle(string $title): bool
    {
        return (bool)preg_match('/^(happy|merry|congrats|congratulations|birthday|mother\'?s|father\'?s|valentine|new year|eid|wedding|graduation|brother|sister|mom|dad|boyfriend|girlfriend)\b/i', $title);
    }

    private function cleanText(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
        $text = str_replace(["\r", "\n"], ' ', $text);
        return trim($text);
    }

    private function smartTrim(string $text, int $limit): string
    {
        $text = $this->cleanText($text);
        if (strlen($text) <= $limit) {
            return $text;
        }

        $trimmed = substr($text, 0, max(1, $limit - 3));
        $lastSpace = strrpos($trimmed, ' ');
        if ($lastSpace !== false && $lastSpace > (int)($limit * 0.55)) {
            $trimmed = substr($trimmed, 0, $lastSpace);
        }

        return rtrim($trimmed, " \t\n\r\0\x0B,.-") . '...';
    }

    private function insertLog(int $automationId, string $action, string $status, array $payload, string $videoId, string $platform): void
    {
        if (!in_array($status, ['success', 'error', 'info'], true)) {
            $status = 'info';
        }

        $message = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($message === false) {
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO automation_logs (automation_id, action, status, message, video_id, platform)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$automationId, $action, $status, $message, $videoId, $platform]);
    }
}
