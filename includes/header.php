<?php
require_once __DIR__ . '/auth_gate.php';
vwm_require_live_password();
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
            <nav class="flex gap-2">
                <a href="index.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'bg-gray-800' : '' ?>">Dashboard</a>
                <a href="api-keys.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'api-keys.php' ? 'bg-gray-800' : '' ?>">API Keys</a>
                <a href="jobs.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'jobs.php' ? 'bg-gray-800' : '' ?>">Jobs</a>
                <a href="automation.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'automation.php' ? 'bg-gray-800' : '' ?>">Automation</a>
                <a href="player.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'player.php' ? 'bg-indigo-600' : '' ?>">
                    <span class="flex items-center gap-1">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                        Player
                    </span>
                </a>
                <a href="settings.php" class="px-4 py-2 rounded hover:bg-gray-800 <?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'bg-gray-800' : '' ?>">Settings</a>
            </nav>
        </div>
    </div>
    <div class="max-w-7xl mx-auto px-6 py-8">
