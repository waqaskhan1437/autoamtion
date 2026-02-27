<?php
/**
 * Debug Automation Settings - All-in-One View
 * Shows EVERYTHING on one page
 */
require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');

echo "<style>
body { font-family: monospace; background: #1a1a2e; color: #00ff88; padding: 20px; }
.box { border: 2px solid #00ff88; padding: 15px; margin: 10px 0; background: #16213e; }
.error { border-color: #ff4444; color: #ff4444; }
.success { border-color: #00ff88; color: #00ff88; }
.warning { border-color: #ffaa00; color: #ffaa00; }
h1, h2, h3 { color: #fff; }
pre { white-space: pre-wrap; word-break: break-all; }
.auto-box { border: 1px solid #444; padding: 10px; margin: 10px 0; background: #0a0a1a; }
</style>";

echo "<h1>🔧 Complete Debug Tool</h1>";

// =====================================================
// 1. GLOBAL SETTINGS
// =====================================================
echo "<h2>1️⃣ Global Settings (Post for Me)</h2>";

$apiKey = '';
try {
    $apiKey = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key'")->fetchColumn();
} catch (Exception $e) {}

echo "<div class='box " . (!empty($apiKey) ? 'success' : 'error') . "'>";
echo "<strong>Post for Me API Key:</strong> ";
if (!empty($apiKey)) {
    $masked = substr($apiKey, 0, 15) . '...' . substr($apiKey, -5);
    echo "✓ {$masked}";
} else {
    echo "✗ NOT CONFIGURED - Go to Settings and add your API key!";
}
echo "</div>";

// =====================================================
// 2. SYNCED ACCOUNTS
// =====================================================
echo "<h2>2️⃣ Synced Social Accounts</h2>";

$accounts = [];
try {
    $accounts = $pdo->query("SELECT * FROM postforme_accounts ORDER BY platform")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "<div class='box error'>⚠ postforme_accounts table not found. Run upgrade-database.sql!</div>";
}

if (empty($accounts)) {
    echo "<div class='box warning'>⚠ No accounts synced. Go to Settings → Sync Accounts</div>";
} else {
    echo "<div class='box success'>";
    foreach ($accounts as $acc) {
        echo "✓ <strong>{$acc['platform']}</strong>: {$acc['account_name']} <code style='color:#888'>(ID: {$acc['account_id']})</code><br>";
    }
    echo "</div>";
}

// =====================================================
// 3. ALL AUTOMATIONS WITH POSTFORME STATUS
// =====================================================
echo "<h2>3️⃣ All Automations - PostForMe Status</h2>";

$automations = [];
try {
    $automations = $pdo->query("SELECT * FROM automation_settings ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "<div class='box error'>Error loading automations: " . $e->getMessage() . "</div>";
}

if (empty($automations)) {
    echo "<div class='box warning'>No automations found</div>";
} else {
    foreach ($automations as $a) {
        echo "<div class='auto-box'>";
        echo "<h3 style='margin:0 0 10px 0; color:#00aaff'>{$a['name']} (ID: {$a['id']})</h3>";
        
        // Raw values
        $rawEnabled = $a['postforme_enabled'] ?? 'NULL';
        $rawAccIds = $a['postforme_account_ids'] ?? 'NULL';
        
        echo "<strong>Raw DB Values:</strong><br>";
        echo "<code>postforme_enabled = [{$rawEnabled}]</code><br>";
        echo "<code>postforme_account_ids = [{$rawAccIds}]</code><br><br>";
        
        // Parse
        $pfEnabled = !empty($a['postforme_enabled']) && $a['postforme_enabled'] !== '0';
        $pfAccounts = [];
        if (!empty($rawAccIds) && $rawAccIds !== '[]' && $rawAccIds !== 'NULL') {
            $decoded = @json_decode($rawAccIds, true);
            if (is_array($decoded)) {
                $pfAccounts = array_filter($decoded);
            }
        }
        
        echo "<strong>Parsed:</strong><br>";
        echo "pfEnabled = <span style='color:" . ($pfEnabled ? '#00ff88' : '#ff4444') . "'>" . ($pfEnabled ? 'TRUE ✓' : 'FALSE ✗') . "</span><br>";
        echo "pfAccounts = " . (empty($pfAccounts) ? '<span style="color:#ff4444">EMPTY ✗</span>' : '<span style="color:#00ff88">' . count($pfAccounts) . ' account(s) ✓</span>') . "<br>";
        
        if (!empty($pfAccounts)) {
            echo "<small style='color:#888'>IDs: " . implode(', ', $pfAccounts) . "</small><br>";
        }
        
        // Will post?
        $willPost = $pfEnabled && !empty($pfAccounts) && !empty($apiKey);
        echo "<br><strong style='font-size:1.2em;color:" . ($willPost ? '#00ff88' : '#ff4444') . "'>WILL POST: " . ($willPost ? 'YES ✓' : 'NO ✗') . "</strong>";
        
        if (!$willPost) {
            echo "<br><span style='color:#ff4444'>Missing: ";
            $missing = [];
            if (!$pfEnabled) $missing[] = "postforme_enabled=0";
            if (empty($pfAccounts)) $missing[] = "no accounts selected";
            if (empty($apiKey)) $missing[] = "API key not set";
            echo implode(", ", $missing);
            echo "</span>";
        }
        
        echo "</div>";
    }
}

// =====================================================
// 4. DATABASE COLUMNS CHECK
// =====================================================
echo "<h2>4️⃣ Database Columns Check</h2>";
echo "<div class='box'>";

try {
    $columns = $pdo->query("SHOW COLUMNS FROM automation_settings")->fetchAll(PDO::FETCH_COLUMN);
    
    $required = ['postforme_enabled', 'postforme_account_ids'];
    foreach ($required as $col) {
        if (in_array($col, $columns)) {
            echo "✓ <code>{$col}</code> exists<br>";
        } else {
            echo "<span style='color:#ff4444'>✗ <code>{$col}</code> MISSING - Run upgrade-database.sql!</span><br>";
        }
    }
} catch (Exception $e) {
    echo "<span style='color:#ff4444'>Error: " . $e->getMessage() . "</span>";
}

echo "</div>";

// =====================================================
// 5. QUICK FIX COMMANDS
// =====================================================
echo "<h2>5️⃣ Quick Fix SQL (if needed)</h2>";
echo "<div class='box'>";
echo "<p>Copy and run in phpMyAdmin if columns missing:</p>";
echo "<pre style='background:#000;padding:10px;color:#0f0'>
ALTER TABLE automation_settings 
ADD COLUMN IF NOT EXISTS postforme_enabled TINYINT(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS postforme_account_ids TEXT;
</pre>";
echo "</div>";

echo "<br><br><a href='automation.php' style='color: #00aaff; font-size: 1.2em'>← Back to Automations</a>";
?>
