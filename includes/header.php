<?php
require_once __DIR__ . '/auth_gate.php';
vwm_require_app_user(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
$vwmCurrentUser = vwm_current_user();
$vwmIsAdmin = vwm_is_admin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Workflow Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#6366f1',
                        accent: '#22c55e',
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #0f0f0f; color: #e5e5e5; }
        .card { background-color: #1a1a1a; border: 1px solid #2a2a2a; }
    </style>
</head>
<body class="min-h-screen">
    <div class="border-b border-gray-800 bg-gray-900">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <h1 class="text-2xl font-bold">Video Workflow Manager</h1>
            <div class="flex items-center gap-4">
            <nav class="flex gap-2">
                <?php if ($vwmIsAdmin): ?>
                <a href="index.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'bg-gray-800' : '' ?>">Dashboard</a>
                <a href="api-keys.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'api-keys.php' ? 'bg-gray-800' : '' ?>">API Keys</a>
                <a href="jobs.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'jobs.php' ? 'bg-gray-800' : '' ?>">Jobs</a>
                <?php endif; ?>
                <a href="automation.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'automation.php' ? 'bg-gray-800' : '' ?>">Automation</a>
                <?php if ($vwmIsAdmin): ?>
                <a href="agents.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'agents.php' ? 'bg-gray-800' : '' ?>">Agents</a>
                <a href="users.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-gray-800' : '' ?>">Users</a>
                <?php endif; ?>
                <a href="player.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'player.php' ? 'bg-indigo-600' : '' ?>">
                    <span class="flex items-center gap-1">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                        Player
                    </span>
                </a>
                <?php if ($vwmIsAdmin): ?>
                <a href="settings.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'bg-gray-800' : '' ?>">Settings</a>
                <?php endif; ?>
            </nav>
            <div class="flex items-center gap-3 text-sm">
                <div class="text-right">
                    <div class="font-medium text-gray-100"><?= htmlspecialchars($vwmCurrentUser['display_name'] ?: $vwmCurrentUser['email'] ?: 'User') ?></div>
                    <div class="text-xs text-gray-400"><?= $vwmIsAdmin ? 'Admin' : 'User' ?></div>
                </div>
                <a href="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>?logout=1" class="px-3 py-2 rounded bg-gray-800 hover:bg-gray-700 text-gray-200">Logout</a>
            </div>
            </div>
        </div>
    </div>
    <div class="max-w-7xl mx-auto px-6 py-8">
