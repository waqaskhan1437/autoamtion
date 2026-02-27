<?php
require_once 'config.php';

$stats = [
    'totalJobs' => 0,
    'completedJobs' => 0,
    'processingJobs' => 0,
    'failedJobs' => 0,
    'activeKeys' => 0,
    'automations' => 0,
    'scheduledPosts' => 0,
    'postedPosts' => 0
];

try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM video_jobs");
    $stats['totalJobs'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM video_jobs WHERE status = 'completed'");
    $stats['completedJobs'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM video_jobs WHERE status = 'processing'");
    $stats['processingJobs'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM video_jobs WHERE status = 'failed'");
    $stats['failedJobs'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM api_keys WHERE status = 'active'");
    $stats['activeKeys'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM automation_settings WHERE enabled = 1");
    $stats['automations'] = $stmt->fetch()['count'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM postforme_posts WHERE scheduled_at IS NOT NULL AND status IN ('pending', 'scheduled', 'partial')");
    $stats['scheduledPosts'] = $stmt->fetch()['count'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM postforme_posts WHERE status IN ('posted', 'completed') OR published_at IS NOT NULL");
    $stats['postedPosts'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT * FROM video_jobs ORDER BY created_at DESC LIMIT 10");
    $recentJobs = $stmt->fetchAll();
} catch (PDOException $e) {
    $recentJobs = [];
}

include 'includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-semibold">Dashboard Overview</h2>
        <p class="text-sm text-gray-400 mt-1">Monitor your video workflows and automations</p>
    </div>
    <a href="api/seed-demo.php" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
        Load Demo Data
    </a>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 mb-8">
    <div class="card rounded-lg p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-gray-400">Total Jobs</span>
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
        </div>
        <div class="text-3xl font-bold font-mono"><?= $stats['totalJobs'] ?></div>
    </div>
    
    <div class="card rounded-lg p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-gray-400">Completed</span>
            <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
        </div>
        <div class="text-3xl font-bold font-mono text-green-500"><?= $stats['completedJobs'] ?></div>
    </div>
    
    <div class="card rounded-lg p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-gray-400">Processing</span>
            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        </div>
        <div class="text-3xl font-bold font-mono text-indigo-500"><?= $stats['processingJobs'] ?></div>
    </div>
    
    <div class="card rounded-lg p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-gray-400">Failed</span>
            <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </div>
        <div class="text-3xl font-bold font-mono text-red-500"><?= $stats['failedJobs'] ?></div>
    </div>
    
    <div class="card rounded-lg p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-gray-400">Active Keys</span>
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
        </div>
        <div class="text-3xl font-bold font-mono"><?= $stats['activeKeys'] ?></div>
    </div>
    
    <div class="card rounded-lg p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-gray-400">Active Automations</span>
            <svg class="w-4 h-4 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
        </div>
        <div class="text-3xl font-bold font-mono text-yellow-500"><?= $stats['automations'] ?></div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
    <div class="card rounded-lg p-4 cursor-pointer hover:bg-gray-800/50 transition-colors" onclick="switchTab('scheduled')">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-orange-400 font-medium">Pending Scheduled</span>
            <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
        </div>
        <div class="text-3xl font-bold font-mono text-orange-500"><?= $stats['scheduledPosts'] ?></div>
        <div class="text-xs text-gray-500 mt-1">Click to view upcoming posts</div>
    </div>
    <div class="card rounded-lg p-4 cursor-pointer hover:bg-gray-800/50 transition-colors" onclick="switchTab('scheduled')">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-pink-400 font-medium">Posted Posts</span>
            <svg class="w-4 h-4 text-pink-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
        </div>
        <div class="text-3xl font-bold font-mono text-pink-500"><?= $stats['postedPosts'] ?></div>
        <div class="text-xs text-gray-500 mt-1">Includes published/processed posts</div>
    </div>
</div>

<div class="flex gap-1 mb-4">
    <button onclick="switchTab('jobs')" id="tab-jobs" class="px-5 py-2.5 rounded-lg text-sm font-medium bg-gray-800 text-white transition-colors">
        Recent Jobs
    </button>
    <button onclick="switchTab('scheduled')" id="tab-scheduled" class="px-5 py-2.5 rounded-lg text-sm font-medium text-gray-400 hover:bg-gray-800/50 transition-colors">
        <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            Scheduled Posts
            <?php if ($stats['scheduledPosts'] > 0): ?>
                <span class="bg-orange-500/20 text-orange-400 text-xs px-2 py-0.5 rounded-full font-mono"><?= $stats['scheduledPosts'] ?></span>
            <?php endif; ?>
        </span>
    </button>
</div>

<div id="panel-jobs">
    <div class="card rounded-lg">
        <div class="p-4 border-b border-gray-800">
            <h3 class="text-lg font-semibold">Recent Jobs</h3>
        </div>
        <div class="p-4">
            <?php if (empty($recentJobs)): ?>
                <div class="text-center py-12 text-gray-400">
                    No jobs yet. Click "Load Demo Data" to see sample jobs, or create an automation.
                </div>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($recentJobs as $job): ?>
                        <div class="flex items-center justify-between p-3 border border-gray-800 rounded-lg hover:bg-gray-800/50 transition-colors">
                            <div class="flex-1">
                                <div class="font-medium"><?= htmlspecialchars($job['name']) ?></div>
                                <div class="text-sm text-gray-400 font-mono"><?= $job['type'] ?></div>
                            </div>
                            <div class="flex items-center gap-3">
                                <?php if ($job['status'] == 'processing'): ?>
                                    <div class="w-24 h-2 bg-gray-700 rounded-full overflow-hidden">
                                        <div class="h-full bg-indigo-500" style="width: <?= $job['progress'] ?>%"></div>
                                    </div>
                                <?php endif; ?>
                                <div class="text-sm font-mono w-12 text-right"><?= $job['progress'] ?>%</div>
                                <div class="px-2 py-1 rounded text-xs font-medium <?php
                                    echo match($job['status']) {
                                        'completed' => 'bg-green-500/10 text-green-500',
                                        'processing' => 'bg-indigo-500/10 text-indigo-500',
                                        'failed' => 'bg-red-500/10 text-red-500',
                                        default => 'bg-gray-500/10 text-gray-400'
                                    };
                                ?>">
                                    <?= $job['status'] ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="panel-scheduled" style="display: none;">
    <div class="card rounded-lg">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="text-lg font-semibold flex items-center gap-2">
                <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Scheduled Posts
            </h3>
            <button onclick="loadScheduledPosts()" class="px-3 py-1.5 bg-gray-700 hover:bg-gray-600 rounded-lg text-sm flex items-center gap-1.5 transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                Refresh
            </button>
        </div>
        <div id="scheduled-posts-container" class="p-4">
            <div class="text-center py-12 text-gray-400">
                <svg class="w-8 h-8 mx-auto mb-3 animate-spin text-gray-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Loading scheduled posts...
            </div>
        </div>
    </div>
</div>

<script>
let countdownInterval = null;
let currentTab = 'jobs';
let serverTimeOffset = 0;

function switchTab(tab) {
    currentTab = tab;
    document.getElementById('panel-jobs').style.display = tab === 'jobs' ? 'block' : 'none';
    document.getElementById('panel-scheduled').style.display = tab === 'scheduled' ? 'block' : 'none';
    
    const tabJobs = document.getElementById('tab-jobs');
    const tabScheduled = document.getElementById('tab-scheduled');
    
    if (tab === 'jobs') {
        tabJobs.className = 'px-5 py-2.5 rounded-lg text-sm font-medium bg-gray-800 text-white transition-colors';
        tabScheduled.className = 'px-5 py-2.5 rounded-lg text-sm font-medium text-gray-400 hover:bg-gray-800/50 transition-colors';
        if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
    } else {
        tabScheduled.className = 'px-5 py-2.5 rounded-lg text-sm font-medium bg-gray-800 text-white transition-colors';
        tabJobs.className = 'px-5 py-2.5 rounded-lg text-sm font-medium text-gray-400 hover:bg-gray-800/50 transition-colors';
        loadScheduledPosts();
    }
}

function parseScheduledDate(scheduledAt) {
    if (!scheduledAt) return null;
    if (scheduledAt.indexOf('Z') !== -1 || scheduledAt.indexOf('+') !== -1) {
        return new Date(scheduledAt);
    }
    if (scheduledAt.indexOf('T') !== -1) {
        return new Date(scheduledAt + 'Z');
    }
    return new Date(scheduledAt.replace(' ', 'T') + 'Z');
}

function getNow() {
    return new Date(Date.now() + serverTimeOffset);
}

function getPlatformIcon(platform) {
    const icons = {
        'tiktok': '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1v-3.5a6.37 6.37 0 00-.79-.05A6.34 6.34 0 003.15 15.2a6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.34-6.34V8.63a8.2 8.2 0 004.76 1.52v-3.4a4.85 4.85 0 01-1-.06z"/></svg>',
        'youtube': '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
        'instagram': '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
        'facebook': '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>'
    };
    return icons[platform.toLowerCase()] || '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>';
}

function getPlatformColor(platform) {
    const colors = {
        'tiktok': 'text-pink-400',
        'youtube': 'text-red-500',
        'instagram': 'text-purple-400',
        'facebook': 'text-blue-500'
    };
    return colors[platform.toLowerCase()] || 'text-gray-400';
}

function getCountdown(scheduledAt) {
    const now = getNow();
    const target = parseScheduledDate(scheduledAt);
    const diff = target - now;
    
    if (diff <= 0) {
        return { text: 'Publishing now...', expired: true, percent: 100 };
    }
    
    const days = Math.floor(diff / 86400000);
    const hours = Math.floor((diff % 86400000) / 3600000);
    const minutes = Math.floor((diff % 3600000) / 60000);
    const seconds = Math.floor((diff % 60000) / 1000);
    
    let text = '';
    if (days > 0) text += days + 'd ';
    if (hours > 0 || days > 0) text += hours + 'h ';
    text += minutes + 'm ' + seconds + 's';
    
    return { text: text.trim(), expired: false, days, hours, minutes, seconds };
}

function renderScheduledPosts(posts) {
    const container = document.getElementById('scheduled-posts-container');
    
    if (!posts || posts.length === 0) {
        container.innerHTML = `
            <div class="text-center py-12 text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <p class="text-lg mb-1">No scheduled posts</p>
                <p class="text-sm">Posts scheduled via PostForMe will appear here with countdown timers</p>
            </div>`;
        return;
    }

    // FIX: Keep items visible by status only (case-insensitive), even when scheduled time has passed.
    const upcoming = posts.filter(function(p) {
        const status = String((p && p.status) || '').toLowerCase().trim();
        return status === 'pending' || status === 'scheduled' || status === 'partial';
    });
    const failed = posts.filter(p => p.status === 'failed' && !upcoming.includes(p));
    const past = posts.filter(p => !upcoming.includes(p) && !failed.includes(p));

    let html = '';
    
    if (upcoming.length > 0) {
        html += '<div class="mb-6"><h4 class="text-sm font-medium text-orange-400 uppercase tracking-wider mb-3 flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Upcoming (' + upcoming.length + ')</h4>';
        html += '<div class="space-y-3">';
        upcoming.forEach(function(post) {
            html += renderPostCard(post, true);
        });
        html += '</div></div>';
    }

    if (past.length > 0) {
        html += '<div class="mb-6"><h4 class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>Completed / Past (' + past.length + ')</h4>';
        html += '<div class="space-y-2">';
        past.forEach(function(post) {
            html += renderPostCard(post, false);
        });
        html += '</div></div>';
    }

    if (failed.length > 0) {
        html += '<div><h4 class="text-sm font-medium text-red-400 uppercase tracking-wider mb-3 flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>Failed (' + failed.length + ')</h4>';
        html += '<div class="space-y-2">';
        failed.forEach(function(post) {
            html += renderPostCard(post, false);
        });
        html += '</div></div>';
    }

    container.innerHTML = html;
    startCountdowns();
}

function renderPostCard(post, isUpcoming) {
    const countdown = post.scheduled_at ? getCountdown(post.scheduled_at) : null;
    
    let platformsHtml = '';
    if (post.platforms && post.platforms.length > 0) {
        post.platforms.forEach(function(p) {
            platformsHtml += '<span class="flex items-center gap-1 ' + getPlatformColor(p.platform) + '" title="' + (p.account_name || p.username || p.platform) + '">' + getPlatformIcon(p.platform) + '<span class="text-xs">' + (p.account_name || p.username || p.platform) + '</span></span>';
        });
    }

    let statusBadge = '';
    const statusColors = {
        'pending': 'bg-orange-500/10 text-orange-400 border border-orange-500/20',
        'scheduled': 'bg-orange-500/10 text-orange-400 border border-orange-500/20',
        'partial': 'bg-orange-500/10 text-orange-400 border border-orange-500/20',
        'published': 'bg-green-500/10 text-green-400 border border-green-500/20',
        'completed': 'bg-green-500/10 text-green-400 border border-green-500/20',
        'failed': 'bg-red-500/10 text-red-400 border border-red-500/20',
        'processing': 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20'
    };
    const badgeColor = statusColors[post.status] || 'bg-gray-500/10 text-gray-400 border border-gray-500/20';
    statusBadge = '<span class="px-2 py-0.5 rounded text-xs font-medium ' + badgeColor + '">' + post.status + '</span>';

    let captionText = post.caption || 'No caption';
    if (captionText.length > 80) captionText = captionText.substring(0, 80) + '...';

    let scheduledTime = '';
    if (post.scheduled_at) {
        const d = parseScheduledDate(post.scheduled_at);
        scheduledTime = d.toLocaleString();
    }

    let countdownHtml = '';
    if (isUpcoming && countdown && !countdown.expired) {
        countdownHtml = `
            <div class="flex items-center gap-3 mt-3 bg-gray-800/50 rounded-lg p-3">
                <svg class="w-5 h-5 text-orange-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <div class="flex-1">
                    <div class="text-xs text-gray-500 mb-1">Publishes in</div>
                    <div class="countdown-timer font-mono text-lg font-bold text-orange-400" data-scheduled="${post.scheduled_at}">${countdown.text}</div>
                </div>
                <div class="flex gap-1">
                    ${countdown.days > 0 ? '<div class="text-center bg-gray-900 rounded px-2 py-1"><div class="text-lg font-bold font-mono text-white">' + countdown.days + '</div><div class="text-[10px] text-gray-500 uppercase">days</div></div>' : ''}
                    <div class="text-center bg-gray-900 rounded px-2 py-1"><div class="text-lg font-bold font-mono text-white">${countdown.hours}</div><div class="text-[10px] text-gray-500 uppercase">hrs</div></div>
                    <div class="text-center bg-gray-900 rounded px-2 py-1"><div class="text-lg font-bold font-mono text-white">${countdown.minutes}</div><div class="text-[10px] text-gray-500 uppercase">min</div></div>
                    <div class="text-center bg-gray-900 rounded px-2 py-1"><div class="text-lg font-bold font-mono countdown-sec text-white" data-scheduled="${post.scheduled_at}">${countdown.seconds}</div><div class="text-[10px] text-gray-500 uppercase">sec</div></div>
                </div>
            </div>`;
    } else if (isUpcoming && countdown && countdown.expired) {
        countdownHtml = `
            <div class="flex items-center gap-2 mt-3 bg-green-500/10 rounded-lg p-3">
                <svg class="w-5 h-5 text-green-400 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                <span class="text-green-400 font-medium text-sm">Publishing now...</span>
            </div>`;
    }

    return `
        <div class="border border-gray-800 rounded-lg p-4 hover:bg-gray-800/30 transition-colors ${isUpcoming ? 'border-l-2 border-l-orange-500' : ''}">
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap mb-1">
                        ${statusBadge}
                        ${post.automation_name ? '<span class="text-xs text-gray-500">' + post.automation_name + '</span>' : ''}
                        <span class="text-xs text-gray-600 font-mono">#${post.post_id}</span>
                    </div>
                    <p class="text-sm text-gray-300 mt-1">${captionText.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p>
                    <div class="flex items-center gap-3 mt-2 flex-wrap">
                        ${platformsHtml}
                    </div>
                </div>
                <div class="text-right flex-shrink-0">
                    <div class="text-xs text-gray-500">${isUpcoming ? 'Scheduled for' : (post.status === 'published' || post.status === 'completed' ? 'Published' : 'Was scheduled')}</div>
                    <div class="text-sm text-gray-300 font-mono">${scheduledTime}</div>
                </div>
            </div>
            ${countdownHtml}
            ${post.error_message ? '<div class="mt-2 text-xs text-red-400 bg-red-500/10 rounded p-2">' + post.error_message + '</div>' : ''}
        </div>`;
}

function startCountdowns() {
    if (countdownInterval) clearInterval(countdownInterval);
    
    countdownInterval = setInterval(function() {
        const timers = document.querySelectorAll('.countdown-timer');
        let anyActive = false;
        
        timers.forEach(function(el) {
            const scheduledAt = el.getAttribute('data-scheduled');
            const cd = getCountdown(scheduledAt);
            
            if (cd.expired) {
                el.textContent = 'Publishing now...';
                el.className = el.className.replace('text-orange-400', 'text-green-400');
            } else {
                anyActive = true;
                el.textContent = cd.text;
            }
        });

        document.querySelectorAll('.countdown-sec').forEach(function(el) {
            const scheduledAt = el.getAttribute('data-scheduled');
            const cd = getCountdown(scheduledAt);
            if (!cd.expired) {
                el.textContent = cd.seconds;
                const parent = el.closest('.flex.gap-1');
                if (parent) {
                    const boxes = parent.querySelectorAll('.text-lg.font-bold.font-mono');
                    let idx = 0;
                    if (cd.days > 0) { if (boxes[idx]) boxes[idx].textContent = cd.days; idx++; }
                    if (boxes[idx]) boxes[idx].textContent = cd.hours; idx++;
                    if (boxes[idx]) boxes[idx].textContent = cd.minutes; idx++;
                }
            }
        });

        if (!anyActive && timers.length > 0) {
            clearInterval(countdownInterval);
            setTimeout(function() { loadScheduledPosts(); }, 3000);
        }
    }, 1000);
}

function loadScheduledPosts() {
    const container = document.getElementById('scheduled-posts-container');
    container.innerHTML = '<div class="text-center py-8 text-gray-400"><svg class="w-6 h-6 mx-auto mb-2 animate-spin text-gray-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>Loading...</div>';
    
    fetch('api/scheduled-posts.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (data.server_time) {
                    const serverUTC = new Date(data.server_time).getTime();
                    const clientUTC = Date.now();
                    serverTimeOffset = serverUTC - clientUTC;
                }
                renderScheduledPosts(data.posts);
            } else {
                container.innerHTML = '<div class="text-center py-8 text-red-400">Error loading posts: ' + (data.error || 'Unknown error') + '</div>';
            }
        })
        .catch(function(err) {
            container.innerHTML = '<div class="text-center py-8 text-red-400">Failed to load scheduled posts. Make sure the server is running.</div>';
        });
}
</script>

<?php include 'includes/footer.php'; ?>
