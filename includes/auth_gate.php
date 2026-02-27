<?php
/**
 * Simple live-only access gate.
 * - Local environments bypass auth.
 * - Public/live hosts require password once per session.
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

function vwm_render_login_form(?string $error = null): void
{
    $errorHtml = $error ? '<div style="color:#f87171;margin-bottom:12px;">' . htmlspecialchars($error) . '</div>' : '';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Protected Access</title></head>';
    echo '<body style="font-family:Arial;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px;">';
    echo '<form method="post" style="background:#111827;border:1px solid #374151;border-radius:12px;padding:24px;width:100%;max-width:380px;">';
    echo '<h2 style="margin:0 0 8px 0;">Video Workflow Manager</h2>';
    echo '<p style="margin:0 0 16px 0;color:#9ca3af;">Enter password to continue (live mode).</p>';
    echo $errorHtml;
    echo '<input type="password" name="vwm_access_password" placeholder="Password" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #4b5563;background:#0b1220;color:#e5e7eb;" required>';
    echo '<button type="submit" style="width:100%;margin-top:12px;padding:10px 12px;border:none;border-radius:8px;background:#4f46e5;color:white;cursor:pointer;">Unlock</button>';
    echo '</form></body></html>';
    exit;
}

function vwm_require_live_password(): void
{
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    if (vwm_is_local_host($host)) {
        return;
    }

    $expected = defined('APP_ACCESS_PASSWORD') ? (string)APP_ACCESS_PASSWORD : '';
    if ($expected === '') {
        // If password is empty in config, keep app open.
        return;
    }

    if (isset($_GET['logout']) && $_GET['logout'] === '1') {
        unset($_SESSION['vwm_live_auth_ok']);
        session_regenerate_id(true);
    }

    if (!empty($_SESSION['vwm_live_auth_ok']) && $_SESSION['vwm_live_auth_ok'] === true) {
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submitted = (string)($_POST['vwm_access_password'] ?? '');
        if (hash_equals($expected, $submitted)) {
            $_SESSION['vwm_live_auth_ok'] = true;
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: ' . strtok($uri, '?'));
            exit;
        }
        vwm_render_login_form('Incorrect password');
    }

    vwm_render_login_form();
}

