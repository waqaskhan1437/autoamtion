<?php

class OllamaClient
{
    private string $baseUrl;
    private string $model;

    public function __construct(string $baseUrl, string $model)
    {
        $this->baseUrl = $this->normalizeBaseUrl($baseUrl);
        $this->model = trim($model);
    }

    public static function fromSettings(?PDO $pdo = null): ?self
    {
        $settings = [];

        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('ollama_base_url', 'ollama_model', 'ollama_auto_fallback', 'ai_provider')");
                $settings = $stmt ? ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) : [];
            } catch (Throwable $e) {
                $settings = [];
            }
        }

        $autoFallback = self::readBoolEnv('VW_OLLAMA_AUTO_FALLBACK')
            || self::readBoolEnv('OLLAMA_AUTO_FALLBACK')
            || self::boolValue($settings['ollama_auto_fallback'] ?? null);

        $baseUrl = trim((string)(
            self::readEnv('VW_OLLAMA_BASE_URL')
            ?: self::readEnv('OLLAMA_BASE_URL')
            ?: $settings['ollama_base_url']
            ?? ''
        ));

        if ($baseUrl === '') {
            $host = trim((string)(
                self::readEnv('VW_OLLAMA_HOST')
                ?: self::readEnv('OLLAMA_HOST')
                ?: ''
            ));
            if ($host !== '') {
                $baseUrl = preg_match('#^https?://#i', $host) ? $host : 'http://' . $host;
            }
        }

        $model = trim((string)(
            self::readEnv('VW_OLLAMA_MODEL')
            ?: self::readEnv('OLLAMA_MODEL')
            ?: $settings['ollama_model']
            ?? ''
        ));

        $provider = strtolower(trim((string)($settings['ai_provider'] ?? '')));
        if ($baseUrl === '' && ($autoFallback || $provider === 'ollama' || $model !== '')) {
            $baseUrl = 'http://127.0.0.1:11434';
        }

        if ($model === '' && ($autoFallback || $provider === 'ollama')) {
            $model = 'qwen2.5:3b';
        }

        if ($baseUrl === '' || $model === '') {
            return null;
        }

        return new self($baseUrl, $model);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->model !== '';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function generateJson(string $prompt, array $options = [], int $timeout = 90): array
    {
        $response = $this->request([
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json',
            'options' => $this->buildOptions($options),
        ], $timeout);

        if (empty($response['success'])) {
            return $response;
        }

        $text = trim((string)($response['data']['response'] ?? $response['data']['message']['content'] ?? ''));
        $decoded = $this->decodeJsonPayload($text);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'provider' => 'ollama',
                'model' => $this->model,
                'error' => 'Ollama returned invalid JSON.',
                'text' => $text,
            ];
        }

        return [
            'success' => true,
            'provider' => 'ollama',
            'model' => $this->model,
            'data' => $decoded,
            'text' => $text,
        ];
    }

    public function generateText(string $prompt, array $options = [], int $timeout = 90): array
    {
        $response = $this->request([
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => $this->buildOptions($options),
        ], $timeout);

        if (empty($response['success'])) {
            return $response;
        }

        $text = trim((string)($response['data']['response'] ?? $response['data']['message']['content'] ?? ''));
        if ($text === '') {
            return [
                'success' => false,
                'provider' => 'ollama',
                'model' => $this->model,
                'error' => 'Ollama returned an empty response.',
            ];
        }

        return [
            'success' => true,
            'provider' => 'ollama',
            'model' => $this->model,
            'text' => $text,
        ];
    }

    private function request(array $payload, int $timeout): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'provider' => 'ollama',
                'model' => $this->model,
                'error' => 'Ollama is not configured.',
            ];
        }

        $url = $this->baseUrl . '/api/generate';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            return [
                'success' => false,
                'provider' => 'ollama',
                'model' => $this->model,
                'error' => 'Ollama connection error: ' . $curlError,
            ];
        }

        $decoded = json_decode((string)$body, true);
        if ($httpCode !== 200) {
            $message = is_array($decoded)
                ? trim((string)($decoded['error'] ?? $decoded['message'] ?? ''))
                : '';
            if ($message === '') {
                $message = 'Ollama API failed with HTTP ' . $httpCode;
            }

            return [
                'success' => false,
                'provider' => 'ollama',
                'model' => $this->model,
                'error' => $message,
                'http_code' => $httpCode,
            ];
        }

        if (!is_array($decoded)) {
            return [
                'success' => false,
                'provider' => 'ollama',
                'model' => $this->model,
                'error' => 'Ollama response could not be parsed.',
            ];
        }

        return [
            'success' => true,
            'provider' => 'ollama',
            'model' => $this->model,
            'data' => $decoded,
        ];
    }

    private function buildOptions(array $options): array
    {
        $built = [];

        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $built['temperature'] = (float)$options['temperature'];
        }

        if (isset($options['max_tokens']) && is_numeric($options['max_tokens'])) {
            $built['num_predict'] = max(1, (int)$options['max_tokens']);
        }

        if (isset($options['top_p']) && is_numeric($options['top_p'])) {
            $built['top_p'] = (float)$options['top_p'];
        }

        return $built;
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'http://' . $baseUrl;
        }

        return rtrim($baseUrl, '/');
    }

    private function decodeJsonPayload(string $text): ?array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $objectJson = $this->extractBalancedJson($text, '{', '}');
        if ($objectJson !== '') {
            $decoded = json_decode($objectJson, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $arrayJson = $this->extractBalancedJson($text, '[', ']');
        if ($arrayJson !== '') {
            $decoded = json_decode($arrayJson, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function extractBalancedJson(string $content, string $openChar, string $closeChar): string
    {
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

    private static function readEnv(string $key): string
    {
        $value = getenv($key);
        return $value === false ? '' : trim((string)$value);
    }

    private static function readBoolEnv(string $key): bool
    {
        return self::boolValue(self::readEnv($key));
    }

    private static function boolValue($value): bool
    {
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
