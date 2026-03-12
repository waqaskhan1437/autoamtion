<?php
/**
 * App authentication and access helpers.
 * - If app_users exist, require email/password login for web access.
 * - Otherwise fall back to the legacy shared live-password gate.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function vwm_is_local_host(string $host): bool
{
    $h = strtolower(trim($host));
    if ($h === '' || $h === 'localhost' || $h === '127.0.0.1' || $h === '::1') {
        return true;
    }
    if (preg_match('/^(10\.)/', $h)) return true;
    if (preg_match('/^(192\.168\.)/', $h)) return true;
    if (preg_match('/^(172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $h)) return true;
    if (str_ends_with($h, '.local')) return true;
    return false;
}

function vwm_auth_pdo(?PDO $pdo = null): ?PDO
{
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    return null;
}

function vwm_is_api_request(): bool
{
    $uri = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return str_contains($uri, '/api/')
        || str_contains($accept, 'application/json')
        || $xhr === 'xmlhttprequest';
}

function vwm_render_login_form(?string $error = null, string $email = ''): void
{
    $errorHtml = $error ? '<div style="color:#f87171;margin-bottom:12px;">' . htmlspecialchars($error) . '</div>' : '';
    $safeEmail = htmlspecialchars($email);

    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Login</title></head>';
    echo '<body style="font-family:Arial;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px;">';
    echo '<form method="post" style="background:#111827;border:1px solid #374151;border-radius:12px;padding:24px;width:100%;max-width:420px;">';
    echo '<h2 style="margin:0 0 8px 0;">Video Workflow Manager</h2>';
    echo '<p style="margin:0 0 16px 0;color:#9ca3af;">Login with your account or open your secure magic link.</p>';
    echo $errorHtml;
    echo '<input type="email" name="vwm_email" value="' . $safeEmail . '" placeholder="Email" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #4b5563;background:#0b1220;color:#e5e7eb;margin-bottom:10px;" required>';
    echo '<input type="password" name="vwm_password" placeholder="Password" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #4b5563;background:#0b1220;color:#e5e7eb;" required>';
    echo '<button type="submit" style="width:100%;margin-top:12px;padding:10px 12px;border:none;border-radius:8px;background:#2563eb;color:white;cursor:pointer;">Login</button>';
    echo '</form></body></html>';
    exit;
}

function vwm_render_forbidden(string $message = 'Access denied'): void
{
    if (vwm_is_api_request()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }

    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Access Denied</title></head><body style="font-family:Arial;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px;">';
    echo '<div style="background:#111827;border:1px solid #374151;border-radius:12px;padding:24px;max-width:520px;width:100%;">';
    echo '<h2 style="margin-top:0;">Access Denied</h2>';
    echo '<p style="color:#cbd5e1;">' . htmlspecialchars($message) . '</p>';
    echo '</div></body></html>';
    exit;
}

function vwm_user_auth_table_exists(?PDO $pdo = null): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $pdo = vwm_auth_pdo($pdo);
    if (!$pdo) {
        $cache = false;
        return $cache;
    }

    try {
        $cache = $pdo->query("SHOW TABLES LIKE 'app_users'")->rowCount() > 0;
    } catch (Exception $e) {
        $cache = false;
    }

    return $cache;
}

function vwm_user_auth_enabled(?PDO $pdo = null): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $pdo = vwm_auth_pdo($pdo);
    if (!$pdo || !vwm_user_auth_table_exists($pdo)) {
        $cache = false;
        return $cache;
    }

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM app_users");
        $cache = ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        $cache = false;
    }

    return $cache;
}

function vwm_current_user(): ?array
{
    $user = $_SESSION['vwm_user'] ?? null;
    return is_array($user) ? $user : null;
}

function vwm_is_authenticated(): bool
{
    return vwm_current_user() !== null;
}

function vwm_current_user_id(): int
{
    return (int)(vwm_current_user()['id'] ?? 0);
}

function vwm_is_admin(): bool
{
    return (string)(vwm_current_user()['role'] ?? '') === 'admin';
}

function vwm_can_access_all_outputs(): bool
{
    return vwm_is_admin() || vwm_is_local_automation_bypass();
}

function vwm_current_user_can_use_github_runner(): bool
{
    if (vwm_is_admin() || !empty(vwm_current_user()['can_use_github_runner'])) {
        return true;
    }

    if (vwm_is_local_automation_bypass()) {
        return true;
    }

    return false;
}

function vwm_is_local_automation_bypass(): bool
{
    if (vwm_is_authenticated()) {
        return false;
    }

    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    return vwm_is_local_host((string)$host);
}

function vwm_current_user_assigned_local_agent_id(): int
{
    return (int)(vwm_current_user()['assigned_local_agent_id'] ?? 0);
}

function vwm_store_user_session(array $user): void
{
    $_SESSION['vwm_user'] = [
        'id' => (int)($user['id'] ?? 0),
        'email' => (string)($user['email'] ?? ''),
        'display_name' => (string)($user['display_name'] ?? ''),
        'client_slug' => (string)($user['client_slug'] ?? ''),
        'role' => (string)($user['role'] ?? 'user'),
        'status' => (string)($user['status'] ?? 'active'),
        'can_use_github_runner' => (int)($user['can_use_github_runner'] ?? 0),
        'assigned_local_agent_id' => (int)($user['assigned_local_agent_id'] ?? 0),
    ];
}

function vwm_logout_user(): void
{
    unset($_SESSION['vwm_user'], $_SESSION['vwm_live_auth_ok']);
    session_regenerate_id(true);
}

function vwm_attempt_user_login(PDO $pdo, string $email, string $password): bool
{
    $email = trim($email);
    if ($email === '' || $password === '') {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM app_users
        WHERE LOWER(email) = LOWER(?)
          AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return false;
    }

    $hash = (string)($user['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        return false;
    }

    $pdo->prepare("UPDATE app_users SET last_login_at = NOW() WHERE id = ?")->execute([(int)$user['id']]);
    session_regenerate_id(true);
    vwm_store_user_session($user);
    return true;
}

function vwm_legacy_live_gate(): void
{
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    if (vwm_is_local_host($host)) {
        return;
    }

    $expected = defined('APP_ACCESS_PASSWORD') ? (string)APP_ACCESS_PASSWORD : '';
    if ($expected === '') {
        return;
    }

    if (isset($_GET['logout']) && $_GET['logout'] === '1') {
        unset($_SESSION['vwm_live_auth_ok']);
        session_regenerate_id(true);
    }

    if (!empty($_SESSION['vwm_live_auth_ok']) && $_SESSION['vwm_live_auth_ok'] === true) {
        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $submitted = (string)($_POST['vwm_access_password'] ?? '');
        if (hash_equals($expected, $submitted)) {
            $_SESSION['vwm_live_auth_ok'] = true;
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: ' . strtok($uri, '?'));
            exit;
        }

        $errorHtml = '<div style="color:#f87171;margin-bottom:12px;">Incorrect password</div>';
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Protected Access</title></head>';
        echo '<body style="font-family:Arial;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px;">';
        echo '<form method="post" style="background:#111827;border:1px solid #374151;border-radius:12px;padding:24px;width:100%;max-width:380px;">';
        echo '<h2 style="margin:0 0 8px 0;">Video Workflow Manager</h2>';
        echo '<p style="margin:0 0 16px 0;color:#9ca3af;">Enter password to continue.</p>';
        echo $errorHtml;
        echo '<input type="password" name="vwm_access_password" placeholder="Password" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #4b5563;background:#0b1220;color:#e5e7eb;" required>';
        echo '<button type="submit" style="width:100%;margin-top:12px;padding:10px 12px;border:none;border-radius:8px;background:#4f46e5;color:white;cursor:pointer;">Unlock</button>';
        echo '</form></body></html>';
        exit;
    }

    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Protected Access</title></head>';
    echo '<body style="font-family:Arial;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px;">';
    echo '<form method="post" style="background:#111827;border:1px solid #374151;border-radius:12px;padding:24px;width:100%;max-width:380px;">';
    echo '<h2 style="margin:0 0 8px 0;">Video Workflow Manager</h2>';
    echo '<p style="margin:0 0 16px 0;color:#9ca3af;">Enter password to continue.</p>';
    echo '<input type="password" name="vwm_access_password" placeholder="Password" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #4b5563;background:#0b1220;color:#e5e7eb;" required>';
    echo '<button type="submit" style="width:100%;margin-top:12px;padding:10px 12px;border:none;border-radius:8px;background:#4f46e5;color:white;cursor:pointer;">Unlock</button>';
    echo '</form></body></html>';
    exit;
}

function vwm_require_live_password(?PDO $pdo = null): void
{
    if (php_sapi_name() === 'cli') {
        return;
    }

    // Bypass for localhost/development environments
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    if (vwm_is_local_host($host)) {
        return;
    }

    $pdo = vwm_auth_pdo($pdo);
    if ($pdo && vwm_user_auth_enabled($pdo)) {
        if (isset($_GET['logout']) && $_GET['logout'] === '1') {
            vwm_logout_user();
        }

        if (vwm_is_authenticated()) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['vwm_email'], $_POST['vwm_password'])) {
            $email = trim((string)$_POST['vwm_email']);
            $password = (string)$_POST['vwm_password'];
            if (vwm_attempt_user_login($pdo, $email, $password)) {
                $uri = $_SERVER['REQUEST_URI'] ?? '/';
                header('Location: ' . strtok($uri, '?'));
                exit;
            }

            if (vwm_is_api_request()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Invalid email or password']);
                exit;
            }

            vwm_render_login_form('Invalid email or password', $email);
        }

        if (vwm_is_api_request()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Authentication required']);
            exit;
        }

        vwm_render_login_form();
    }

    vwm_legacy_live_gate();
}

function vwm_require_app_user(?PDO $pdo = null, bool $adminOnly = false): void
{
    vwm_require_live_password($pdo);

    if ($adminOnly && !vwm_is_admin()) {
        vwm_render_forbidden('This page is only available to administrators.');
    }
}

function vwm_get_automation_scope_clause(string $alias = 'automation_settings'): array
{
    if (vwm_is_admin() || vwm_is_local_automation_bypass()) {
        return ['1=1', []];
    }

    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return [$prefix . 'owner_user_id = ?', [vwm_current_user_id()]];
}

function vwm_can_access_automation(array $automation): bool
{
    if (vwm_is_admin() || vwm_is_local_automation_bypass()) {
        return true;
    }

    return (int)($automation['owner_user_id'] ?? 0) === vwm_current_user_id();
}

function vwm_fetch_accessible_automation(PDO $pdo, int $automationId): ?array
{
    [$scopeSql, $scopeParams] = vwm_get_automation_scope_clause('automation_settings');
    $stmt = $pdo->prepare("
        SELECT *
        FROM automation_settings
        WHERE id = ?
          AND {$scopeSql}
        LIMIT 1
    ");
    $stmt->execute(array_merge([$automationId], $scopeParams));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function vwm_collect_accessible_output_names(PDO $pdo): array
{
    if (vwm_can_access_all_outputs()) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT progress_data
        FROM automation_settings
        WHERE owner_user_id = ?
          AND progress_data IS NOT NULL
    ");
    $stmt->execute([vwm_current_user_id()]);

    $allowed = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $data = json_decode((string)$raw, true);
        if (!is_array($data) || empty($data['outputs']) || !is_array($data['outputs'])) {
            continue;
        }

        foreach ($data['outputs'] as $outputName) {
            $name = basename(trim((string)$outputName));
            if ($name !== '') {
                $allowed[strtolower($name)] = $name;
            }
        }
    }

    return $allowed;
}

function vwm_user_can_access_output_file(PDO $pdo, string $filename): bool
{
    if (vwm_can_access_all_outputs()) {
        return true;
    }

    $allowed = vwm_collect_accessible_output_names($pdo);
    return isset($allowed[strtolower(basename($filename))]);
}
