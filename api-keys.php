<?php
require_once 'config.php';

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        try {
            $stmt = $pdo->prepare("INSERT INTO api_keys (name, api_key, library_id, storage_zone, ftp_host, ftp_username, ftp_password, ftp_port, cdn_hostname, pull_zone_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([
                $_POST['name'],
                $_POST['api_key'],
                $_POST['library_id'],
                $_POST['storage_zone'] ?: null,
                $_POST['ftp_host'] ?: null,
                $_POST['ftp_username'] ?: null,
                $_POST['ftp_password'] ?: null,
                $_POST['ftp_port'] ?: 21,
                $_POST['cdn_hostname'] ?: null,
                $_POST['pull_zone_id'] ?: null
            ]);
            $message = 'API key created successfully';
        } catch (PDOException $e) {
            $message = 'Error creating API key';
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM api_keys WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $message = 'API key deleted';
    } elseif ($action === 'toggle') {
        $newStatus = $_POST['current_status'] === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE api_keys SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $_POST['id']]);
        $message = 'Status updated';
    } elseif ($action === 'update') {
        $stmt = $pdo->prepare("UPDATE api_keys SET name=?, api_key=?, library_id=?, storage_zone=?, ftp_host=?, ftp_username=?, ftp_password=?, ftp_port=?, cdn_hostname=?, pull_zone_id=? WHERE id=?");
        $stmt->execute([
            $_POST['name'],
            $_POST['api_key'],
            $_POST['library_id'],
            $_POST['storage_zone'] ?: null,
            $_POST['ftp_host'] ?: null,
            $_POST['ftp_username'] ?: null,
            $_POST['ftp_password'] ?: null,
            $_POST['ftp_port'] ?: 21,
            $_POST['cdn_hostname'] ?: null,
            $_POST['pull_zone_id'] ?: null,
            $_POST['id']
        ]);
        $message = 'Settings saved';
    }
}

$stmt = $pdo->query("SELECT * FROM api_keys ORDER BY created_at DESC");
$keys = $stmt->fetchAll();

include 'includes/header.php';
?>

<?php if ($message): ?>
    <script>document.addEventListener('DOMContentLoaded', () => showToast('<?= $message ?>', '<?= $messageType ?>'));</script>
<?php endif; ?>

<div class="flex items-center justify-between mb-6">
    <h2 class="text-xl font-semibold">Bunny CDN Settings</h2>
    <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
        Add Connection
    </button>
</div>

<div class="grid gap-4">
    <?php if (empty($keys)): ?>
        <div class="card rounded-lg p-12 text-center text-gray-400">
            No connections configured. Add your first Bunny CDN connection to get started.
        </div>
    <?php else: ?>
        <?php foreach ($keys as $key): ?>
            <div class="card rounded-lg">
                <div class="p-4 flex items-center justify-between border-b border-gray-800">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-indigo-500/10 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"></path></svg>
                        </div>
                        <div>
                            <h3 class="font-semibold"><?= htmlspecialchars($key['name']) ?></h3>
                            <div class="text-sm text-gray-400">Library: <?= htmlspecialchars($key['library_id']) ?></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="openEditModal(<?= htmlspecialchars(json_encode($key)) ?>)" class="p-2 hover:bg-gray-700 rounded">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </button>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $key['id'] ?>">
                            <input type="hidden" name="current_status" value="<?= $key['status'] ?>">
                            <button type="submit" class="p-2 hover:bg-gray-700 rounded">
                                <?php if ($key['status'] === 'active'): ?>
                                    <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M11.3 3.3a1 1 0 011.4 0l4 4a1 1 0 010 1.4l-4 4a1 1 0 01-1.4-1.4L13.58 9H3a1 1 0 110-2h10.58l-2.28-2.3a1 1 0 010-1.4z" clip-rule="evenodd"></path></svg>
                                <?php else: ?>
                                    <svg class="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.7 3.3a1 1 0 00-1.4 0l-4 4a1 1 0 000 1.4l4 4a1 1 0 001.4-1.4L6.42 9H17a1 1 0 100-2H6.42l2.28-2.3a1 1 0 000-1.4z" clip-rule="evenodd"></path></svg>
                                <?php endif; ?>
                            </button>
                        </form>
                        <form method="POST" class="inline" onsubmit="return confirmDelete('Delete this API key?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $key['id'] ?>">
                            <button type="submit" class="p-2 hover:bg-gray-700 rounded text-red-500">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                        </form>
                    </div>
                </div>
                <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-gray-400 mb-1">Status</div>
                        <span class="px-2 py-1 rounded text-xs font-medium <?= $key['status'] === 'active' ? 'bg-green-500/10 text-green-500' : 'bg-gray-500/10 text-gray-400' ?>">
                            <?= $key['status'] ?>
                        </span>
                    </div>
                    <?php if ($key['storage_zone']): ?>
                        <div>
                            <div class="text-gray-400 mb-1">Storage Zone</div>
                            <div class="font-mono text-xs"><?= htmlspecialchars($key['storage_zone']) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($key['ftp_host']): ?>
                        <div>
                            <div class="text-gray-400 mb-1">FTP Host</div>
                            <div class="font-mono text-xs"><?= htmlspecialchars($key['ftp_host']) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($key['cdn_hostname']): ?>
                        <div>
                            <div class="text-gray-400 mb-1">CDN</div>
                            <div class="font-mono text-xs"><?= htmlspecialchars($key['cdn_hostname']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add Modal -->
<div id="addModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="card rounded-lg w-full max-w-2xl max-h-[90vh] overflow-y-auto m-4">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="text-lg font-semibold">Add Bunny CDN Connection</h3>
            <button onclick="document.getElementById('addModal').classList.add('hidden')" class="text-gray-400 hover:text-white">&times;</button>
        </div>
        <form method="POST" class="p-4 space-y-4">
            <input type="hidden" name="action" value="create">
            
            <div class="border-b border-gray-800 pb-2 mb-4">
                <span class="text-sm font-medium">API Settings</span>
            </div>
            
            <div>
                <label class="block text-sm text-gray-400 mb-1">Connection Name</label>
                <input type="text" name="name" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">API Key</label>
                <input type="password" name="api_key" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-indigo-500">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Library ID</label>
                    <input type="text" name="library_id" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Pull Zone ID</label>
                    <input type="text" name="pull_zone_id" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Storage Zone</label>
                    <input type="text" name="storage_zone" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">CDN Hostname</label>
                    <input type="text" name="cdn_hostname" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
            </div>
            
            <div class="border-b border-gray-800 pb-2 mb-4 mt-6">
                <span class="text-sm font-medium">FTP Settings</span>
            </div>
            
            <div>
                <label class="block text-sm text-gray-400 mb-1">FTP Host</label>
                <input type="text" name="ftp_host" placeholder="storage.bunnycdn.com" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-indigo-500">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">FTP Username</label>
                    <input type="text" name="ftp_username" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">FTP Port</label>
                    <input type="number" name="ftp_port" value="21" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-indigo-500">
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">FTP Password</label>
                <input type="password" name="ftp_password" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg focus:outline-none focus:border-indigo-500">
            </div>
            
            <button type="submit" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg font-medium">Create Connection</button>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="card rounded-lg w-full max-w-2xl max-h-[90vh] overflow-y-auto m-4">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="text-lg font-semibold">Edit Connection</h3>
            <button onclick="document.getElementById('editModal').classList.add('hidden')" class="text-gray-400 hover:text-white">&times;</button>
        </div>
        <form method="POST" id="editForm" class="p-4 space-y-4">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">
            
            <div>
                <label class="block text-sm text-gray-400 mb-1">Connection Name</label>
                <input type="text" name="name" id="edit_name" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">API Key</label>
                <input type="password" name="api_key" id="edit_api_key" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Library ID</label>
                    <input type="text" name="library_id" id="edit_library_id" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Pull Zone ID</label>
                    <input type="text" name="pull_zone_id" id="edit_pull_zone_id" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Storage Zone</label>
                    <input type="text" name="storage_zone" id="edit_storage_zone" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">CDN Hostname</label>
                    <input type="text" name="cdn_hostname" id="edit_cdn_hostname" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">FTP Host</label>
                <input type="text" name="ftp_host" id="edit_ftp_host" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">FTP Username</label>
                    <input type="text" name="ftp_username" id="edit_ftp_username" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">FTP Port</label>
                    <input type="number" name="ftp_port" id="edit_ftp_port" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">FTP Password</label>
                <input type="password" name="ftp_password" id="edit_ftp_password" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            </div>
            
            <button type="submit" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg font-medium">Save Changes</button>
        </form>
    </div>
</div>

<script>
function openEditModal(key) {
    document.getElementById('edit_id').value = key.id;
    document.getElementById('edit_name').value = key.name;
    document.getElementById('edit_api_key').value = key.api_key;
    document.getElementById('edit_library_id').value = key.library_id;
    document.getElementById('edit_pull_zone_id').value = key.pull_zone_id || '';
    document.getElementById('edit_storage_zone').value = key.storage_zone || '';
    document.getElementById('edit_cdn_hostname').value = key.cdn_hostname || '';
    document.getElementById('edit_ftp_host').value = key.ftp_host || '';
    document.getElementById('edit_ftp_username').value = key.ftp_username || '';
    document.getElementById('edit_ftp_port').value = key.ftp_port || 21;
    document.getElementById('edit_ftp_password').value = key.ftp_password || '';
    document.getElementById('editModal').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
