<?php
require_once 'config.php';
require_once 'includes/auth_gate.php';
require_once 'includes/MagicLoginManager.php';

function renderMagicLoginStatus(string $title, string $message, string $type = 'error'): void
{
    $accent = $type === 'success' ? '#10b981' : '#ef4444';

    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title) . '</title></head>';
    echo '<body style="font-family:Arial;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px;">';
    echo '<div style="background:#111827;border:1px solid #374151;border-radius:12px;padding:24px;max-width:520px;width:100%;">';
    echo '<h2 style="margin-top:0;color:' . $accent . ';">' . htmlspecialchars($title) . '</h2>';
    echo '<p style="color:#cbd5e1;line-height:1.5;">' . htmlspecialchars($message) . '</p>';
    echo '<div style="margin-top:20px;"><a href="index.php" style="display:inline-block;padding:10px 14px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;">Go to Login</a></div>';
    echo '</div></body></html>';
    exit;
}

$manager = new MagicLoginManager($pdo);
$token = trim((string)($_GET['token'] ?? ''));
$clientSlug = trim((string)($_GET['client'] ?? ''));
$requestedRedirect = trim((string)($_GET['redirect'] ?? ''));

if ($token === '') {
    renderMagicLoginStatus('Magic Login', 'Magic login token is missing.');
}

if (vwm_is_authenticated()) {
    vwm_logout_user();
}

$result = $manager->consumeMagicToken($token, $clientSlug !== '' ? $clientSlug : null);
if (empty($result['success'])) {
    renderMagicLoginStatus('Magic Link Invalid', (string)($result['error'] ?? 'Unable to use this magic link.'));
}

session_regenerate_id(true);
vwm_store_user_session($result['user']);

$redirectPath = MagicLoginManager::normalizeRedirectPath(
    $requestedRedirect !== '' ? $requestedRedirect : (string)($result['redirect_path'] ?? 'automation.php')
);

header('Location: ' . $redirectPath);
exit;
