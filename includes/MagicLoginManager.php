<?php

class MagicLoginManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getPublicBaseUrl(): string
    {
        $configured = trim($this->getSetting('panel_public_base_url'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $scheme = $https ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }

        $basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        if ($basePath === '/' || $basePath === '.') {
            $basePath = '';
        }

        return $scheme . '://' . $host . $basePath;
    }

    public function ensureUserSlug(int $userId, ?string $preferred = null): string
    {
        $stmt = $this->pdo->prepare("SELECT id, email, display_name, client_slug FROM app_users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $preferred = trim((string)$preferred);
        $current = trim((string)($user['client_slug'] ?? ''));
        if ($preferred === '' && $current !== '') {
            return $current;
        }

        $base = $preferred !== ''
            ? $preferred
            : ((string)($user['display_name'] ?? '') !== '' ? (string)$user['display_name'] : (string)$user['email']);
        $slug = $this->generateUniqueSlug($base, $userId);

        if ($slug !== $current) {
            $up = $this->pdo->prepare("UPDATE app_users SET client_slug = ? WHERE id = ?");
            $up->execute([$slug, $userId]);
        }

        return $slug;
    }

    public function generateUniqueSlug(string $source, ?int $ignoreUserId = null): string
    {
        $base = $this->slugify($source);
        if ($base === '') {
            $base = 'client';
        }

        $slug = $base;
        $suffix = 2;

        while ($this->slugExists($slug, $ignoreUserId)) {
            $slug = substr($base, 0, 70) . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    public function createMagicLinkForUser(int $userId, array $options = []): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM app_users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }
        if (($user['status'] ?? 'active') !== 'active') {
            return ['success' => false, 'error' => 'Magic login can only be generated for active users.'];
        }

        $clientSlug = $this->ensureUserSlug($userId, (string)($options['client_slug'] ?? ''));
        $expiryHours = (int)($options['expiry_hours'] ?? 72);
        if ($expiryHours < 1) {
            $expiryHours = 1;
        }
        if ($expiryHours > 24 * 30) {
            $expiryHours = 24 * 30;
        }

        $redirectPath = self::normalizeRedirectPath((string)($options['redirect_path'] ?? 'automation.php'));
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $expiryHours . ' hours')->format('Y-m-d H:i:s');
        $createdBy = (int)($options['created_by_user_id'] ?? 0);

        $ins = $this->pdo->prepare("
            INSERT INTO magic_login_tokens (
                user_id, token_hash, redirect_path, expires_at, one_time, created_by_user_id
            ) VALUES (?, ?, ?, ?, 1, ?)
        ");
        $ins->execute([
            $userId,
            $tokenHash,
            $redirectPath,
            $expiresAt,
            $createdBy > 0 ? $createdBy : null
        ]);

        $url = $this->buildMagicLoginUrl($clientSlug, $token, $redirectPath);

        return [
            'success' => true,
            'magic_url' => $url,
            'client_slug' => $clientSlug,
            'expires_at' => $expiresAt,
            'redirect_path' => $redirectPath,
            'token_id' => (int)$this->pdo->lastInsertId()
        ];
    }

    public function buildMagicLoginUrl(string $clientSlug, string $token, string $redirectPath = 'automation.php'): string
    {
        $query = http_build_query([
            'client' => $clientSlug,
            'token' => $token,
            'redirect' => self::normalizeRedirectPath($redirectPath)
        ]);

        $path = 'magic-login.php?' . $query;
        $baseUrl = $this->getPublicBaseUrl();

        return $baseUrl !== '' ? ($baseUrl . '/' . $path) : $path;
    }

    public function consumeMagicToken(string $token, ?string $expectedClientSlug = null): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['success' => false, 'error' => 'Magic token is missing.'];
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $this->pdo->prepare("
            SELECT
                t.id AS magic_token_id,
                t.user_id,
                t.redirect_path,
                t.expires_at,
                t.used_at,
                t.revoked_at,
                t.one_time,
                u.*
            FROM magic_login_tokens t
            INNER JOIN app_users u ON u.id = t.user_id
            WHERE t.token_hash = ?
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['success' => false, 'error' => 'Magic link is invalid.'];
        }
        if (($row['status'] ?? 'active') !== 'active') {
            return ['success' => false, 'error' => 'This account is disabled.'];
        }
        if (!empty($row['revoked_at'])) {
            return ['success' => false, 'error' => 'This magic link has been revoked.'];
        }
        if (!empty($row['used_at']) && !empty($row['one_time'])) {
            return ['success' => false, 'error' => 'This magic link has already been used.'];
        }
        if (strtotime((string)$row['expires_at']) !== false && strtotime((string)$row['expires_at']) < time()) {
            return ['success' => false, 'error' => 'This magic link has expired.'];
        }

        $actualSlug = trim((string)($row['client_slug'] ?? ''));
        $expectedClientSlug = trim((string)$expectedClientSlug);
        if ($expectedClientSlug !== '' && $actualSlug !== '' && !hash_equals($actualSlug, $expectedClientSlug)) {
            return ['success' => false, 'error' => 'Magic link does not match this client URL.'];
        }

        $this->pdo->prepare("UPDATE app_users SET last_login_at = NOW() WHERE id = ?")->execute([(int)$row['user_id']]);
        if (!empty($row['one_time'])) {
            $this->pdo->prepare("UPDATE magic_login_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL")
                ->execute([(int)$row['magic_token_id']]);
        }

        return [
            'success' => true,
            'user' => [
                'id' => (int)($row['id'] ?? 0),
                'email' => (string)($row['email'] ?? ''),
                'display_name' => (string)($row['display_name'] ?? ''),
                'role' => (string)($row['role'] ?? 'user'),
                'status' => (string)($row['status'] ?? 'active'),
                'can_use_github_runner' => (int)($row['can_use_github_runner'] ?? 0),
                'assigned_local_agent_id' => (int)($row['assigned_local_agent_id'] ?? 0),
                'client_slug' => $actualSlug
            ],
            'redirect_path' => self::normalizeRedirectPath((string)($row['redirect_path'] ?? 'automation.php')),
            'client_slug' => $actualSlug
        ];
    }

    public function revokeActiveTokensForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare("
            UPDATE magic_login_tokens
            SET revoked_at = NOW()
            WHERE user_id = ?
              AND revoked_at IS NULL
              AND used_at IS NULL
              AND expires_at > NOW()
        ");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    public function getActiveTokenStatsByUser(): array
    {
        $rows = $this->pdo->query("
            SELECT user_id, COUNT(*) AS active_count, MIN(expires_at) AS next_expiry_at
            FROM magic_login_tokens
            WHERE revoked_at IS NULL
              AND used_at IS NULL
              AND expires_at > NOW()
            GROUP BY user_id
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int)$row['user_id']] = [
                'active_count' => (int)($row['active_count'] ?? 0),
                'next_expiry_at' => (string)($row['next_expiry_at'] ?? '')
            ];
        }

        return $stats;
    }

    public static function normalizeRedirectPath(string $path, string $fallback = 'automation.php'): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return $fallback;
        }
        if (preg_match('#^(https?:)?//#i', $path)) {
            return $fallback;
        }
        if (str_contains($path, "\n") || str_contains($path, "\r")) {
            return $fallback;
        }

        return ltrim($path, '/');
    }

    private function slugify(string $value): string
    {
        $value = trim(strtolower($value));
        if ($value === '') {
            return 'client';
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = strtolower($converted);
            }
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        if ($value === '') {
            $value = 'client';
        }

        return substr($value, 0, 80);
    }

    private function slugExists(string $slug, ?int $ignoreUserId = null): bool
    {
        if ($ignoreUserId !== null && $ignoreUserId > 0) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM app_users WHERE client_slug = ? AND id <> ?");
            $stmt->execute([$slug, $ignoreUserId]);
            return ((int)$stmt->fetchColumn()) > 0;
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM app_users WHERE client_slug = ?");
        $stmt->execute([$slug]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    private function getSetting(string $key): string
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        return trim((string)($stmt->fetchColumn() ?: ''));
    }
}
