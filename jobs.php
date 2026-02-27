<?php
require_once 'config.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_pull') {
        $stmt = $pdo->prepare("INSERT INTO video_jobs (name, api_key_id, bunny_video_url, video_id, type, status, progress) VALUES (?, ?, ?, ?, 'pull', 'pending', 0)");
        $stmt->execute([$_POST['name'], $_POST['api_key_id'], $_POST['bunny_video_url'], $_POST['video_id']]);
        $message = 'Pull job created';
    } elseif ($action === 'create_process') {
        $stmt = $pdo->prepare("INSERT INTO video_jobs (name, api_key_id, type, status, progress) VALUES (?, ?, 'process', 'pending', 0)");
        $stmt->execute([$_POST['name'], $_POST['api_key_id']]);
        $jobId = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("INSERT INTO processing_tasks (job_id, task_type, preset, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$jobId, $_POST['task_type'], $_POST['preset']]);
        $message = 'Processing job created';
    }
}

$stmt = $pdo->query("SELECT * FROM video_jobs ORDER BY created_at DESC");
$jobs = $stmt->fetchAll();

$stmt = $pdo->query("SELECT * FROM api_keys WHERE status = 'active'");
$keys = $stmt->fetchAll();

include 'includes/header.php';
?>

<?php if ($message): ?>
    <script>document.addEventListener('DOMContentLoaded', () => showToast('<?= $message ?>'));</script>
<?php endif; ?>

<div class="flex items-center justify-between mb-6">
    <h2 class="text-xl font-semibold">Video Jobs</h2>
    <div class="flex gap-2">
        <button onclick="document.getElementById('pullModal').classList.remove('hidden')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
            Pull Video
        </button>
        <button onclick="document.getElementById('processModal').classList.remove('hidden')" class="px-4 py-2 bg-green-600 hover:bg-green-700 rounded-lg flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            Process Video
        </button>
    </div>
</div>

<div class="grid gap-4">
    <?php if (empty($jobs)): ?>
        <div class="card rounded-lg p-12 text-center text-gray-400">
            No jobs yet. Create a pull or process job to get started.
        </div>
    <?php else: ?>
        <?php foreach ($jobs as $job): ?>
            <div class="card rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center <?= $job['type'] === 'pull' ? 'bg-indigo-500/10' : 'bg-green-500/10' ?>">
                            <?php if ($job['type'] === 'pull'): ?>
                                <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                            <?php else: ?>
                                <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path></svg>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3 class="font-semibold"><?= htmlspecialchars($job['name']) ?></h3>
                            <div class="text-sm text-gray-400"><?= $job['type'] ?> | <?= date('M d, Y H:i', strtotime($job['created_at'])) ?></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <?php if ($job['status'] === 'processing'): ?>
                            <div class="w-32 h-2 bg-gray-700 rounded-full overflow-hidden">
                                <div class="h-full bg-indigo-500" style="width: <?= $job['progress'] ?>%"></div>
                            </div>
                        <?php endif; ?>
                        <span class="font-mono text-sm"><?= $job['progress'] ?>%</span>
                        <span class="px-2 py-1 rounded text-xs font-medium <?php
                            echo match($job['status']) {
                                'completed' => 'bg-green-500/10 text-green-500',
                                'processing' => 'bg-indigo-500/10 text-indigo-500',
                                'failed' => 'bg-red-500/10 text-red-500',
                                default => 'bg-gray-500/10 text-gray-400'
                            };
                        ?>">
                            <?= $job['status'] ?>
                        </span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Pull Modal -->
<div id="pullModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="card rounded-lg w-full max-w-md m-4">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="text-lg font-semibold">Pull Video from Bunny</h3>
            <button onclick="document.getElementById('pullModal').classList.add('hidden')" class="text-gray-400 hover:text-white">&times;</button>
        </div>
        <form method="POST" class="p-4 space-y-4">
            <input type="hidden" name="action" value="create_pull">
            <div>
                <label class="block text-sm text-gray-400 mb-1">Job Name</label>
                <input type="text" name="name" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">API Key</label>
                <select name="api_key_id" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                    <option value="">Select...</option>
                    <?php foreach ($keys as $key): ?>
                        <option value="<?= $key['id'] ?>"><?= htmlspecialchars($key['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Video ID</label>
                <input type="text" name="video_id" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Bunny Video URL</label>
                <input type="text" name="bunny_video_url" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            </div>
            <button type="submit" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg font-medium">Create Pull Job</button>
        </form>
    </div>
</div>

<!-- Process Modal -->
<div id="processModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="card rounded-lg w-full max-w-md m-4">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="text-lg font-semibold">Process Video with FFmpeg</h3>
            <button onclick="document.getElementById('processModal').classList.add('hidden')" class="text-gray-400 hover:text-white">&times;</button>
        </div>
        <form method="POST" class="p-4 space-y-4">
            <input type="hidden" name="action" value="create_process">
            <div>
                <label class="block text-sm text-gray-400 mb-1">Job Name</label>
                <input type="text" name="name" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">API Key</label>
                <select name="api_key_id" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                    <option value="">Select...</option>
                    <?php foreach ($keys as $key): ?>
                        <option value="<?= $key['id'] ?>"><?= htmlspecialchars($key['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Task Type</label>
                <select name="task_type" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                    <option value="transcode">Transcode</option>
                    <option value="short_conversion">Short Conversion</option>
                    <option value="watermark">Add Watermark</option>
                    <option value="thumbnail">Generate Thumbnail</option>
                    <option value="hls">HLS Packaging</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Preset</label>
                <select name="preset" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                    <option value="720p">720p</option>
                    <option value="1080p">1080p</option>
                    <option value="4k">4K</option>
                    <option value="60s - 9:16">Short 60s Vertical</option>
                    <option value="30s - 1:1">Short 30s Square</option>
                </select>
            </div>
            <button type="submit" class="w-full py-2 bg-green-600 hover:bg-green-700 rounded-lg font-medium">Create Processing Job</button>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
