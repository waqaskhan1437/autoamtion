<?php
require_once 'config.php';
require_once 'includes/auth_gate.php';
require_once 'includes/MagicLoginManager.php';

vwm_require_app_user($pdo, true);

function usersGeneratePassword(int $length = 14): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

$magicLoginManager = new MagicLoginManager($pdo);
$currentUserId = vwm_current_user_id();
$message = '';
$messageType = 'success';
$flashMagicLink = '';
$flashMagicSlug = '';
$flashMagicExpiresAt = '';
if (!empty($_SESSION['users_flash']) && is_array($_SESSION['users_flash'])) {
    $message = (string)($_SESSION['users_flash']['message'] ?? '');
    $messageType = (string)($_SESSION['users_flash']['type'] ?? 'success');
    $flashMagicLink = (string)($_SESSION['users_flash']['magic_link'] ?? '');
    $flashMagicSlug = (string)($_SESSION['users_flash']['client_slug'] ?? '');
    $flashMagicExpiresAt = (string)($_SESSION['users_flash']['expires_at'] ?? '');
    unset($_SESSION['users_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'create') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $clientSlugInput = trim((string)($_POST['client_slug'] ?? ''));
            $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $status = ($_POST['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active';
            $canUseGithubRunner = isset($_POST['can_use_github_runner']) ? 1 : 0;
            $assignedLocalAgentId = !empty($_POST['assigned_local_agent_id']) ? (int)$_POST['assigned_local_agent_id'] : null;
            $passwordPlain = trim((string)($_POST['password_plain'] ?? ''));
            $generateMagicLink = isset($_POST['generate_magic_link']);
            $magicExpiryHours = (int)($_POST['magic_expiry_hours'] ?? 72);
            if ($passwordPlain === '') {
                $passwordPlain = usersGeneratePassword();
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Valid email is required.');
            }

            $clientSlug = $magicLoginManager->generateUniqueSlug(
                $clientSlugInput !== '' ? $clientSlugInput : ($displayName !== '' ? $displayName : $email)
            );

            $stmt = $pdo->prepare("
                INSERT INTO app_users (
                    email, password_hash, display_name, client_slug, role, status, can_use_github_runner, assigned_local_agent_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $email,
                password_hash($passwordPlain, PASSWORD_DEFAULT),
                $displayName !== '' ? $displayName : null,
                $clientSlug,
                $role,
                $status,
                $canUseGithubRunner,
                $assignedLocalAgentId
            ]);

            $newUserId = (int)$pdo->lastInsertId();
            $flash = [
                'type' => 'success',
                'message' => 'User created. Login: ' . $email . ' | Password: ' . $passwordPlain . ' | Client Slug: ' . $clientSlug
            ];
            if ($generateMagicLink) {
                $magic = $magicLoginManager->createMagicLinkForUser($newUserId, [
                    'expiry_hours' => $magicExpiryHours,
                    'redirect_path' => 'automation.php',
                    'created_by_user_id' => $currentUserId
                ]);
                if (!empty($magic['success'])) {
                    $flash['message'] .= ' | Magic link generated.';
                    $flash['magic_link'] = (string)$magic['magic_url'];
                    $flash['client_slug'] = (string)$magic['client_slug'];
                    $flash['expires_at'] = (string)$magic['expires_at'];
                }
            }

            $_SESSION['users_flash'] = $flash;
            header('Location: users.php');
            exit;
        }

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid user.');
            }

            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $clientSlugInput = trim((string)($_POST['client_slug'] ?? ''));
            $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $status = ($_POST['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active';
            $canUseGithubRunner = isset($_POST['can_use_github_runner']) ? 1 : 0;
            $assignedLocalAgentId = !empty($_POST['assigned_local_agent_id']) ? (int)$_POST['assigned_local_agent_id'] : null;

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Valid email is required.');
            }

            $clientSlug = $magicLoginManager->generateUniqueSlug(
                $clientSlugInput !== '' ? $clientSlugInput : ($displayName !== '' ? $displayName : $email),
                $id
            );

            $stmt = $pdo->prepare("
                UPDATE app_users
                SET email = ?,
                    display_name = ?,
                    client_slug = ?,
                    role = ?,
                    status = ?,
                    can_use_github_runner = ?,
                    assigned_local_agent_id = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $email,
                $displayName !== '' ? $displayName : null,
                $clientSlug,
                $role,
                $status,
                $canUseGithubRunner,
                $assignedLocalAgentId,
                $id
            ]);

            if ($id === $currentUserId) {
                $refresh = $pdo->prepare("SELECT * FROM app_users WHERE id = ? LIMIT 1");
                $refresh->execute([$id]);
                $userRow = $refresh->fetch(PDO::FETCH_ASSOC);
                if ($userRow) {
                    vwm_store_user_session($userRow);
                }
            }

            $_SESSION['users_flash'] = ['type' => 'success', 'message' => 'User updated.'];
            header('Location: users.php');
            exit;
        }

        if ($action === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid user.');
            }

            $passwordPlain = trim((string)($_POST['password_plain'] ?? ''));
            if ($passwordPlain === '') {
                $passwordPlain = usersGeneratePassword();
            }

            $stmt = $pdo->prepare("UPDATE app_users SET password_hash = ? WHERE id = ?");
            $stmt->execute([password_hash($passwordPlain, PASSWORD_DEFAULT), $id]);

            $_SESSION['users_flash'] = [
                'type' => 'success',
                'message' => 'Password reset complete. New password: ' . $passwordPlain
            ];
            header('Location: users.php');
            exit;
        }

        if ($action === 'generate_magic_link') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid user.');
            }

            $redirectPath = trim((string)($_POST['redirect_path'] ?? 'automation.php'));
            $magicExpiryHours = (int)($_POST['magic_expiry_hours'] ?? 72);
            $magic = $magicLoginManager->createMagicLinkForUser($id, [
                'expiry_hours' => $magicExpiryHours,
                'redirect_path' => $redirectPath,
                'created_by_user_id' => $currentUserId
            ]);
            if (empty($magic['success'])) {
                throw new RuntimeException((string)($magic['error'] ?? 'Failed to generate magic link.'));
            }

            $_SESSION['users_flash'] = [
                'type' => 'success',
                'message' => 'Magic login link generated.',
                'magic_link' => (string)$magic['magic_url'],
                'client_slug' => (string)$magic['client_slug'],
                'expires_at' => (string)$magic['expires_at']
            ];
            header('Location: users.php');
            exit;
        }

        if ($action === 'revoke_magic_links') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid user.');
            }

            $revoked = $magicLoginManager->revokeActiveTokensForUser($id);
            $_SESSION['users_flash'] = [
                'type' => 'success',
                'message' => 'Revoked ' . $revoked . ' active magic link(s).'
            ];
            header('Location: users.php');
            exit;
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$localAgents = $pdo->query("
    SELECT id, display_name, machine_name, status
    FROM local_agents
    WHERE status <> 'disabled'
    ORDER BY display_name ASC, machine_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$users = $pdo->query("
    SELECT u.*, ag.display_name AS local_agent_name, ag.machine_name AS local_agent_machine
    FROM app_users u
    LEFT JOIN local_agents ag ON ag.id = u.assigned_local_agent_id
    ORDER BY FIELD(u.role, 'admin', 'user'), u.created_at ASC, u.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
$tokenStats = $magicLoginManager->getActiveTokenStatsByUser();

include 'includes/header.php';
?>

<?php if ($message !== ''): ?>
    <script>document.addEventListener('DOMContentLoaded', () => showToast('<?= htmlspecialchars($message, ENT_QUOTES) ?>'));</script>
<?php endif; ?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-semibold">Users</h2>
        <p class="text-sm text-gray-400 mt-1">Create customer logins, assign a client slug, and issue one-time magic links for passwordless access.</p>
    </div>
</div>

<?php if ($flashMagicLink !== ''): ?>
    <div class="card rounded-lg p-5 mb-6 border border-emerald-500/30 bg-emerald-500/5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold text-emerald-300">Magic Login Ready</h3>
                <p class="text-sm text-gray-300 mt-1">One-time link generated for client slug <code class="bg-gray-900 px-2 py-1 rounded"><?= htmlspecialchars($flashMagicSlug) ?></code>.</p>
                <?php if ($flashMagicExpiresAt !== ''): ?>
                    <p class="text-xs text-gray-400 mt-2">Expires: <?= htmlspecialchars($flashMagicExpiresAt) ?></p>
                <?php endif; ?>
            </div>
            <a href="<?= htmlspecialchars($flashMagicLink) ?>" target="_blank" rel="noopener" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 rounded-lg text-sm">Open Link</a>
        </div>
        <div class="mt-4 flex gap-2">
            <input id="latestMagicLink" type="text" readonly value="<?= htmlspecialchars($flashMagicLink) ?>" class="flex-1 px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg text-sm text-gray-200">
            <button type="button" onclick="copyMagicLink('latestMagicLink')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg text-sm">Copy Link</button>
        </div>
    </div>
<?php endif; ?>

<div class="card rounded-lg p-5 mb-6">
    <h3 class="text-lg font-semibold mb-4">Create User</h3>
    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <input type="hidden" name="action" value="create">
        <div>
            <label class="block text-sm text-gray-400 mb-1">Email</label>
            <input type="email" name="email" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" required>
        </div>
        <div>
            <label class="block text-sm text-gray-400 mb-1">Display Name</label>
            <input type="text" name="display_name" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
        </div>
        <div>
            <label class="block text-sm text-gray-400 mb-1">Client Slug</label>
            <input type="text" name="client_slug" placeholder="auto from name/email" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
        </div>
        <div>
            <label class="block text-sm text-gray-400 mb-1">Role</label>
            <select name="role" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                <option value="user">User</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-400 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                <option value="active">Active</option>
                <option value="disabled">Disabled</option>
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-400 mb-1">Password</label>
            <input type="text" name="password_plain" placeholder="Leave blank to auto-generate" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
        </div>
        <div>
            <label class="block text-sm text-gray-400 mb-1">Assigned Local Agent</label>
            <select name="assigned_local_agent_id" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                <option value="">None</option>
                <?php foreach ($localAgents as $agent): ?>
                    <option value="<?= (int)$agent['id'] ?>"><?= htmlspecialchars($agent['display_name'] ?: ($agent['machine_name'] ?: ('Agent #' . $agent['id']))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-400 mb-1">Magic Link Expiry (hours)</label>
            <input type="number" name="magic_expiry_hours" value="72" min="1" max="720" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
        </div>
        <div class="flex items-end">
            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox" name="can_use_github_runner" value="1" class="rounded border-gray-600 bg-gray-800">
                Allow GitHub Runner
            </label>
        </div>
        <div class="flex items-end">
            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox" name="generate_magic_link" value="1" checked class="rounded border-gray-600 bg-gray-800">
                Generate Magic Link
            </label>
        </div>
        <div class="flex items-end">
            <button type="submit" class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg">Create User</button>
        </div>
    </form>
    <div class="text-xs text-gray-500 mt-3">
        Local-only customer profile: leave `Allow GitHub Runner` unchecked and assign their local agent. Magic links are one-time and expire automatically.
    </div>
</div>

<div class="space-y-4">
    <?php foreach ($users as $user): ?>
        <?php
        $userId = (int)$user['id'];
        $activeMagicCount = (int)($tokenStats[$userId]['active_count'] ?? 0);
        $nextMagicExpiry = (string)($tokenStats[$userId]['next_expiry_at'] ?? '');
        ?>
        <div class="card rounded-lg p-5">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <div class="font-semibold"><?= htmlspecialchars($user['display_name'] ?: $user['email']) ?></div>
                    <div class="text-sm text-gray-400"><?= htmlspecialchars($user['email']) ?></div>
                    <div class="text-xs text-gray-500 mt-1">Client Slug: <code class="bg-gray-900 px-2 py-1 rounded"><?= htmlspecialchars((string)($user['client_slug'] ?? '')) ?></code></div>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <span class="px-2 py-1 rounded <?= $user['role'] === 'admin' ? 'bg-indigo-500/15 text-indigo-300' : 'bg-gray-700 text-gray-200' ?>"><?= htmlspecialchars($user['role']) ?></span>
                    <span class="px-2 py-1 rounded <?= $user['status'] === 'active' ? 'bg-green-500/15 text-green-300' : 'bg-red-500/15 text-red-300' ?>"><?= htmlspecialchars($user['status']) ?></span>
                    <span class="px-2 py-1 rounded <?= !empty($user['can_use_github_runner']) ? 'bg-amber-500/15 text-amber-300' : 'bg-blue-500/15 text-blue-300' ?>">
                        <?= !empty($user['can_use_github_runner']) ? 'Runner + Local' : 'Local Only' ?>
                    </span>
                </div>
            </div>

            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" required>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Display Name</label>
                    <input type="text" name="display_name" value="<?= htmlspecialchars((string)($user['display_name'] ?? '')) ?>" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Client Slug</label>
                    <input type="text" name="client_slug" value="<?= htmlspecialchars((string)($user['client_slug'] ?? '')) ?>" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Role</label>
                    <select name="role" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="disabled" <?= $user['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Assigned Local Agent</label>
                    <select name="assigned_local_agent_id" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                        <option value="">None</option>
                        <?php foreach ($localAgents as $agent): ?>
                            <option value="<?= (int)$agent['id'] ?>" <?= (int)($user['assigned_local_agent_id'] ?? 0) === (int)$agent['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($agent['display_name'] ?: ($agent['machine_name'] ?: ('Agent #' . $agent['id']))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" name="can_use_github_runner" value="1" class="rounded border-gray-600 bg-gray-800" <?= !empty($user['can_use_github_runner']) ? 'checked' : '' ?>>
                        Allow GitHub Runner
                    </label>
                </div>
                <div class="flex items-end text-sm text-gray-400">
                    Agent:
                    <?= htmlspecialchars($user['local_agent_name'] ?: $user['local_agent_machine'] ?: 'Not assigned') ?>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg">Save User</button>
                </div>
            </form>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                <div class="px-3 py-2 bg-gray-800/60 rounded-lg text-sm text-gray-300">
                    Active Magic Links: <span class="font-semibold text-white"><?= $activeMagicCount ?></span>
                </div>
                <div class="px-3 py-2 bg-gray-800/60 rounded-lg text-sm text-gray-300">
                    Next Expiry: <span class="font-semibold text-white"><?= htmlspecialchars($nextMagicExpiry !== '' ? $nextMagicExpiry : 'None') ?></span>
                </div>
                <div class="px-3 py-2 bg-gray-800/60 rounded-lg text-sm text-gray-300">
                    Redirect Target: <span class="font-semibold text-white">automation.php</span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[2fr,auto] gap-3 mb-4">
                <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <input type="hidden" name="action" value="generate_magic_link">
                    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Magic Link Expiry (hours)</label>
                        <input type="number" name="magic_expiry_hours" value="72" min="1" max="720" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Redirect Path</label>
                        <input type="text" name="redirect_path" value="automation.php" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full px-4 py-2 bg-emerald-600 hover:bg-emerald-700 rounded-lg">Generate Magic Link</button>
                    </div>
                </form>

                <form method="POST" class="flex items-end">
                    <input type="hidden" name="action" value="revoke_magic_links">
                    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                    <button type="submit" class="w-full px-4 py-2 bg-red-700 hover:bg-red-800 rounded-lg" onclick="return confirmDelete('Revoke all active magic links for this user?')">Revoke Active Links</button>
                </form>
            </div>

            <form method="POST" class="flex flex-col md:flex-row gap-3">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                <input type="text" name="password_plain" placeholder="Leave blank to auto-generate new password" class="flex-1 px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 rounded-lg">Reset Password</button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<script>
function copyMagicLink(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.select();
    input.setSelectionRange(0, input.value.length);
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(input.value)
            .then(() => showToast('Magic link copied'))
            .catch(() => showToast('Copy failed', 'error'));
        return;
    }

    try {
        document.execCommand('copy');
        showToast('Magic link copied');
    } catch (e) {
        showToast('Copy failed', 'error');
    }
}
</script>

<?php include 'includes/footer.php'; ?>
