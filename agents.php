<?php
require_once 'config.php';
require_once 'includes/LocalAgentManager.php';

$manager = new LocalAgentManager($pdo);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'regenerate_pairing_token') {
        $newToken = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES ('local_agent_pairing_token', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$newToken]);
        $message = 'Pairing token regenerated';
    } elseif ($action === 'set_agent_status') {
        $agentId = (int)($_POST['agent_id'] ?? 0);
        $status = (string)($_POST['status'] ?? 'offline');
        if ($agentId > 0) {
            $manager->setAgentStatus($agentId, $status);
            $message = 'Agent status updated';
        }
    } elseif ($action === 'save_panel_url') {
        $panelUrl = trim((string)($_POST['panel_public_base_url'] ?? ''));
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES ('panel_public_base_url', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$panelUrl]);
        $message = 'Panel URL saved';
    }
}

$pairingToken = $manager->getPairingToken();
$panelBaseUrl = $manager->getPublicBaseUrl();
$agents = $manager->listAgents();
$jobCounts = $pdo->query("
    SELECT agent_id, status, COUNT(*) AS total
    FROM local_agent_jobs
    GROUP BY agent_id, status
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$jobMap = [];
foreach ($jobCounts as $row) {
    $agentId = (int)$row['agent_id'];
    $status = (string)$row['status'];
    $jobMap[$agentId][$status] = (int)$row['total'];
}

include 'includes/header.php';
?>

<?php if ($message): ?>
    <script>document.addEventListener('DOMContentLoaded', () => showToast('<?= htmlspecialchars($message, ENT_QUOTES) ?>'));</script>
<?php endif; ?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-semibold">Local Agents</h2>
        <p class="text-sm text-gray-400 mt-1">Pair remote PCs with this hosted panel and dispatch local jobs securely.</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="card rounded-lg p-5 space-y-4">
        <div>
            <h3 class="font-semibold">Pairing</h3>
            <p class="text-sm text-gray-400 mt-1">Install the worker on the target PC once, then use this token to pair it.</p>
        </div>
        <div class="bg-gray-900 rounded-lg p-3 font-mono text-sm break-all"><?= htmlspecialchars($pairingToken) ?></div>
        <form method="POST">
            <input type="hidden" name="action" value="regenerate_pairing_token">
            <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg text-sm">Regenerate Token</button>
        </form>
    </div>

    <div class="card rounded-lg p-5 space-y-4">
        <div>
            <h3 class="font-semibold">Hosted Panel URL</h3>
            <p class="text-sm text-gray-400 mt-1">Public URL that agents will use to hit register/poll/report endpoints.</p>
        </div>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="save_panel_url">
            <input type="text" name="panel_public_base_url" value="<?= htmlspecialchars($panelBaseUrl) ?>" placeholder="https://your-domain.com/autoamtion-main" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg text-sm">Save URL</button>
        </form>
    </div>
</div>

<div class="card rounded-lg p-5 mb-6 space-y-4">
    <div>
        <h3 class="font-semibold">Installer Command</h3>
        <p class="text-sm text-gray-400 mt-1">Use repo zip path for local testing, or replace it with your GitHub release/repo zip URL after push.</p>
    </div>
    <pre class="bg-gray-900 rounded-lg p-4 overflow-x-auto text-xs text-green-400"><code>powershell -ExecutionPolicy Bypass -File "scripts\install-agent.ps1" `
  -RepoZipPath "C:\path\to\autoamtion-main.zip" `
  -ServerUrl "<?= htmlspecialchars($panelBaseUrl ?: 'https://your-domain.com/autoamtion-main') ?>" `
  -PairingToken "<?= htmlspecialchars($pairingToken) ?>" `
  -CreateScheduledTask</code></pre>
    <div class="text-xs text-gray-500">Worker script after install: <code>C:\VideoWorkflowAgent\start-agent.ps1</code></div>
</div>

<div class="card rounded-lg overflow-hidden">
    <div class="p-4 border-b border-gray-800">
        <h3 class="font-semibold">Registered Agents</h3>
    </div>
    <div class="p-4">
        <?php if (empty($agents)): ?>
            <div class="text-gray-400">No agents paired yet.</div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($agents as $agent): ?>
                    <?php
                    $agentId = (int)$agent['id'];
                    $counts = $jobMap[$agentId] ?? [];
                    $status = (string)($agent['status'] ?? 'offline');
                    $statusClass = $status === 'online'
                        ? 'bg-green-500/10 text-green-400'
                        : ($status === 'disabled' ? 'bg-red-500/10 text-red-400' : 'bg-yellow-500/10 text-yellow-400');
                    ?>
                    <div class="border border-gray-800 rounded-lg p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="font-medium"><?= htmlspecialchars($agent['display_name'] ?: ('Agent #' . $agentId)) ?></div>
                                <div class="text-sm text-gray-400"><?= htmlspecialchars($agent['machine_name'] ?: '-') ?> | <?= htmlspecialchars($agent['platform'] ?: '-') ?> | last seen <?= htmlspecialchars($agent['last_seen_at'] ?: 'never') ?></div>
                                <div class="text-xs text-gray-500 mt-1 font-mono"><?= htmlspecialchars($agent['agent_key']) ?></div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-1 rounded text-xs font-medium <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="set_agent_status">
                                    <input type="hidden" name="agent_id" value="<?= $agentId ?>">
                                    <input type="hidden" name="status" value="<?= $status === 'disabled' ? 'offline' : 'disabled' ?>">
                                    <button type="submit" class="px-3 py-1 text-xs rounded bg-gray-700 hover:bg-gray-600"><?= $status === 'disabled' ? 'Enable' : 'Disable' ?></button>
                                </form>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-3 mt-4 text-sm">
                            <div class="bg-gray-900 rounded p-3">
                                <div class="text-gray-500 text-xs">Queued</div>
                                <div class="font-mono text-lg"><?= (int)($counts['queued'] ?? 0) ?></div>
                            </div>
                            <div class="bg-gray-900 rounded p-3">
                                <div class="text-gray-500 text-xs">Running</div>
                                <div class="font-mono text-lg"><?= (int)($counts['running'] ?? 0) + (int)($counts['claimed'] ?? 0) ?></div>
                            </div>
                            <div class="bg-gray-900 rounded p-3">
                                <div class="text-gray-500 text-xs">Completed</div>
                                <div class="font-mono text-lg"><?= (int)($counts['completed'] ?? 0) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
