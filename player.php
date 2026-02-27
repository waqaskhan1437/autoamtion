<?php
require_once 'config.php';
require_once 'includes/PostForMeAPI.php';

$message = '';

$outputDir = (PHP_OS_FAMILY === 'Windows') 
    ? 'C:/VideoWorkflow/output/' 
    : getenv('HOME') . '/VideoWorkflow/output/';

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Get Post for Me accounts for the post modal
$postformeAccounts = [];
$postformeApiKey = '';
try {
    $stmt = $pdo->query("SELECT * FROM postforme_accounts WHERE is_active = 1 ORDER BY platform");
    $postformeAccounts = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key'");
    $stmt->execute();
    $postformeApiKey = $stmt->fetchColumn() ?: '';
} catch (Exception $e) {
    // Tables might not exist yet
}

$videos = [];
if (is_dir($outputDir)) {
    $files = glob($outputDir . '*.mp4');
    foreach ($files as $file) {
        $videos[] = [
            'name' => basename($file),
            'path' => $file,
            'size' => filesize($file),
            'date' => filemtime($file),
            'url' => 'stream.php?file=' . urlencode(basename($file))
        ];
    }
    usort($videos, function($a, $b) {
        return $b['date'] - $a['date'];
    });
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_all'])) {
        $deleted = 0;
        foreach (glob($outputDir . '*.mp4') as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }
        $message = "Deleted {$deleted} video(s)";
        header('Location: player.php');
        exit;
    }

    if (isset($_POST['delete'])) {
        $filename = $_POST['delete'];
        $filepath = $outputDir . basename($filename);
        if (file_exists($filepath)) {
            unlink($filepath);
            $message = 'Video deleted';
            header('Location: player.php');
            exit;
        }
    }
}

include 'includes/header.php';
?>

<style>
.video-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
.video-card { background: #1f2937; border-radius: 0.5rem; overflow: hidden; }
.video-card video { width: 100%; aspect-ratio: 9/16; object-fit: contain; background: #000; }
.video-card.horizontal video { aspect-ratio: 16/9; }
.video-card.square video { aspect-ratio: 1/1; }
</style>

<?php if ($message): ?>
    <script>document.addEventListener('DOMContentLoaded', () => showToast('<?= $message ?>'));</script>
<?php endif; ?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-semibold">Processed Shorts</h2>
        <p class="text-sm text-gray-400 mt-1">View and manage your generated short videos</p>
    </div>
    <div class="flex gap-2">
        <a href="automation.php" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg">
            Back to Automations
        </a>
        <form method="POST" onsubmit="return confirm('Delete all videos from output folder?')">
            <input type="hidden" name="delete_all" value="1">
            <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg">
                Delete All Videos
            </button>
        </form>
        <button onclick="location.reload()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            Refresh
        </button>
    </div>
</div>

<div class="card p-4 mb-6">
    <div class="flex items-center gap-3 text-sm">
        <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        <span class="text-gray-400">Output Directory:</span>
        <code class="bg-gray-800 px-2 py-1 rounded text-xs"><?= htmlspecialchars($outputDir) ?></code>
        <span class="text-gray-400">|</span>
        <span class="text-gray-400">Total Videos:</span>
        <span class="font-bold text-white"><?= count($videos) ?></span>
    </div>
</div>

<?php if (empty($videos)): ?>
    <div class="card p-8 text-center">
        <svg class="w-16 h-16 mx-auto text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
        <h3 class="text-xl font-medium text-gray-400 mb-2">No Videos Yet</h3>
        <p class="text-gray-500 mb-4">Run an automation to process videos and they will appear here.</p>
        <a href="automation.php" class="inline-block px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg">
            Go to Automations
        </a>
    </div>
<?php else: ?>
    <div class="video-grid">
        <?php foreach ($videos as $video): ?>
            <div class="video-card <?= strpos($video['name'], '16x9') !== false ? 'horizontal' : (strpos($video['name'], '1x1') !== false ? 'square' : '') ?>">
                <video controls preload="metadata">
                    <source src="<?= $video['url'] ?>" type="video/mp4">
                    Your browser does not support video playback.
                </video>
                <div class="p-3">
                    <div class="font-medium text-sm truncate mb-1" title="<?= htmlspecialchars($video['name']) ?>">
                        <?= htmlspecialchars($video['name']) ?>
                    </div>
                    <div class="flex items-center justify-between text-xs text-gray-400">
                        <span><?= number_format($video['size'] / 1024 / 1024, 1) ?> MB</span>
                        <span><?= date('M d, H:i', $video['date']) ?></span>
                    </div>
                    <div class="flex gap-2 mt-2">
                        <button onclick="openPostModal('<?= htmlspecialchars($video['name']) ?>', '<?= htmlspecialchars($video['path']) ?>')" class="flex-1 py-1 bg-gradient-to-r from-pink-600 to-purple-600 hover:from-pink-700 hover:to-purple-700 rounded text-xs flex items-center justify-center gap-1" <?= empty($postformeAccounts) ? 'disabled title="Sync accounts in Settings first"' : '' ?>>
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                            Post Now
                        </button>
                    </div>
                    <div class="flex gap-2 mt-2">
                        <a href="<?= $video['url'] ?>&download=1" class="flex-1 text-center py-1 bg-indigo-600 hover:bg-indigo-700 rounded text-xs">
                            Download
                        </a>
                        <form method="POST" class="flex-1" onsubmit="return confirm('Delete this video?')">
                            <input type="hidden" name="delete" value="<?= htmlspecialchars($video['name']) ?>">
                            <button type="submit" class="w-full py-1 bg-red-600 hover:bg-red-700 rounded text-xs">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Post Now Modal -->
<div id="postModal" class="fixed inset-0 bg-black/80 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-900 rounded-xl p-6 max-w-md w-full mx-4 border border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <svg class="w-5 h-5 text-pink-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                Post to Social Media
            </h3>
            <button onclick="closePostModal()" class="text-gray-400 hover:text-white">&times;</button>
        </div>
        
        <form id="postForm" onsubmit="submitPost(event)">
            <input type="hidden" id="postVideoName" name="video_name">
            <input type="hidden" id="postVideoPath" name="video_path">
            
            <div class="mb-4">
                <label class="block text-sm text-gray-400 mb-2">Video</label>
                <div id="postVideoDisplay" class="text-sm bg-gray-800 px-3 py-2 rounded truncate"></div>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm text-gray-400 mb-2">Caption</label>
                <textarea name="caption" id="postCaption" rows="3" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:border-pink-500 text-sm" placeholder="Enter caption for your post..."></textarea>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm text-gray-400 mb-2">Select Accounts to Post</label>
                <div class="space-y-2 max-h-48 overflow-y-auto">
                    <?php if (empty($postformeAccounts)): ?>
                        <div class="text-sm text-yellow-400 bg-yellow-500/10 px-3 py-2 rounded">
                            No accounts synced. Go to Settings → Post for Me → Sync Accounts
                        </div>
                    <?php else: ?>
                        <?php foreach ($postformeAccounts as $acc): 
                            $platformColors = ['youtube' => 'red', 'tiktok' => 'gray', 'instagram' => 'pink', 'facebook' => 'blue', 'twitter' => 'gray', 'linkedin' => 'blue', 'threads' => 'gray', 'pinterest' => 'red'];
                            $color = $platformColors[$acc['platform']] ?? 'gray';
                        ?>
                            <label class="flex items-center gap-2 p-2 bg-gray-800 rounded cursor-pointer hover:bg-gray-700 border border-transparent hover:border-<?= $color ?>-500/30">
                                <input type="checkbox" name="account_ids[]" value="<?= htmlspecialchars($acc['account_id']) ?>" class="w-4 h-4 accent-<?= $color ?>-500">
                                <span class="text-xs px-1.5 py-0.5 bg-<?= $color ?>-500/20 text-<?= $color ?>-400 rounded uppercase"><?= $acc['platform'] ?></span>
                                <span class="text-sm truncate"><?= htmlspecialchars($acc['account_name'] ?? $acc['username']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div id="postResult" class="mb-4 hidden"></div>
            
            <div class="flex gap-3">
                <button type="button" onclick="closePostModal()" class="flex-1 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg">Cancel</button>
                <button type="submit" id="postSubmitBtn" class="flex-1 py-2 bg-gradient-to-r from-pink-600 to-purple-600 hover:from-pink-700 hover:to-purple-700 rounded-lg font-medium" <?= empty($postformeAccounts) ? 'disabled' : '' ?>>
                    Post Now
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openPostModal(videoName, videoPath) {
    document.getElementById('postVideoName').value = videoName;
    document.getElementById('postVideoPath').value = videoPath;
    document.getElementById('postVideoDisplay').textContent = videoName;
    document.getElementById('postCaption').value = videoName.replace(/\.mp4$/i, '').replace(/_/g, ' ');
    document.getElementById('postResult').className = 'mb-4 hidden';
    document.getElementById('postModal').classList.remove('hidden');
}

function closePostModal() {
    document.getElementById('postModal').classList.add('hidden');
}

async function submitPost(e) {
    e.preventDefault();
    
    const btn = document.getElementById('postSubmitBtn');
    const resultDiv = document.getElementById('postResult');
    const form = document.getElementById('postForm');
    const formData = new FormData(form);
    
    // Get selected accounts
    const selectedAccounts = Array.from(form.querySelectorAll('input[name="account_ids[]"]:checked')).map(cb => cb.value);
    if (selectedAccounts.length === 0) {
        resultDiv.className = 'mb-4 p-3 bg-yellow-500/10 border border-yellow-500/30 rounded text-yellow-400 text-sm';
        resultDiv.textContent = 'Please select at least one account';
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin inline mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Posting...';
    
    try {
        const response = await fetch('api/post-video.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            resultDiv.className = 'mb-4 p-3 bg-green-500/10 border border-green-500/30 rounded text-green-400 text-sm';
            resultDiv.innerHTML = '✓ ' + data.message;
            setTimeout(() => closePostModal(), 2000);
        } else {
            resultDiv.className = 'mb-4 p-3 bg-red-500/10 border border-red-500/30 rounded text-red-400 text-sm';
            resultDiv.innerHTML = '✗ ' + (data.error || 'Failed to post');
        }
    } catch (err) {
        resultDiv.className = 'mb-4 p-3 bg-red-500/10 border border-red-500/30 rounded text-red-400 text-sm';
        resultDiv.textContent = 'Error: ' + err.message;
    }
    
    btn.disabled = false;
    btn.innerHTML = 'Post Now';
}
</script>

<?php include 'includes/footer.php'; ?>
