<?php
/**
 * Kill All Background PHP Processes - ROBUST VERSION
 * Stops any orphaned or running background automation processes
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

$killed = 0;
$details = [];
$errors = [];

// Get list of running automations from database first
$runningAutomations = [];
try {
    $stmt = $pdo->query("SELECT id, name, status FROM automation_settings WHERE status IN ('processing', 'running', 'started')");
    $runningAutomations = $stmt->fetchAll();
} catch (Exception $e) {
    $errors[] = 'Could not fetch running automations';
}

// Method 1: Kill PHP processes (Windows specific - robust version)
if (PHP_OS_FAMILY === 'Windows') {
    // Multiple patterns to search for
    $patterns = [
        'run-background.php',
        'run-sync.php',
        'run-automation.php',
        'run-direct.php',
        'start-robust.php',
        'cron.php'
    ];
    
    // Get all PHP processes
    $output = [];
    exec('wmic process where "name=\'php.exe\' or name=\'php-cgi.exe\'" get commandline,processid /format:csv 2>&1', $output);
    
    $foundProcesses = [];
    foreach ($output as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, 'Node') !== false || strpos($line, 'CommandLine') !== false) {
            continue;
        }
        
        // Check if line contains any of our patterns
        foreach ($patterns as $pattern) {
            if (stripos($line, $pattern) !== false) {
                // Extract PID from CSV format (last column is PID)
                $parts = explode(',', $line);
                $pid = trim(end($parts));
                
                if (is_numeric($pid) && $pid > 0) {
                    $foundProcesses[$pid] = $pattern;
                }
                break;
            }
        }
    }
    
    // Kill each found process
    foreach ($foundProcesses as $pid => $pattern) {
        $killOutput = [];
        exec("taskkill /PID {$pid} /F 2>&1", $killOutput, $returnCode);
        
        if ($returnCode === 0) {
            $killed++;
            $details[] = "Killed PID {$pid} ({$pattern})";
        } else {
            // Try alternative kill method
            exec("taskkill /PID {$pid} /T /F 2>&1", $killOutput2, $returnCode2);
            if ($returnCode2 === 0) {
                $killed++;
                $details[] = "Force killed PID {$pid} ({$pattern})";
            }
        }
    }
    
    // Also try PowerShell method as backup
    if ($killed === 0 && !empty($runningAutomations)) {
        $psOutput = [];
        exec('powershell -Command "Get-Process php* | Where-Object { $_.CommandLine -like \'*run-*\' } | Stop-Process -Force" 2>&1', $psOutput, $psReturn);
        if ($psReturn === 0) {
            $details[] = "PowerShell killed PHP processes";
        }
    }
    
} else {
    // Linux/Unix: More robust process killing
    $patterns = [
        'run-background.php',
        'run-sync.php', 
        'run-automation.php',
        'run-direct.php',
        'start-robust.php'
    ];
    
    foreach ($patterns as $pattern) {
        // Find processes
        $pids = [];
        exec("pgrep -f '{$pattern}' 2>/dev/null", $pids);
        
        foreach ($pids as $pid) {
            $pid = trim($pid);
            if (is_numeric($pid) && $pid > 0) {
                exec("kill -9 {$pid} 2>/dev/null", $output, $returnCode);
                if ($returnCode === 0) {
                    $killed++;
                    $details[] = "Killed PID {$pid} ({$pattern})";
                }
            }
        }
    }
    
    // Backup: pkill
    if ($killed === 0) {
        exec("pkill -9 -f 'run-background\|run-sync\|run-automation' 2>/dev/null", $output, $returnCode);
        if ($returnCode === 0) {
            $killed++;
            $details[] = "pkill terminated background processes";
        }
    }
}

// Method 2: Reset database status for all automations
$dbReset = 0;
try {
    $stmt = $pdo->prepare("UPDATE automation_settings SET 
        status = 'inactive', 
        progress_percent = 0, 
        progress_data = NULL,
        current_step = NULL
        WHERE status IN ('processing', 'running', 'started', 'stopping')");
    $stmt->execute();
    $dbReset = $stmt->rowCount();
    
    if ($dbReset > 0) {
        $details[] = "Reset {$dbReset} automation(s) in database";
    }
} catch (Exception $e) {
    $errors[] = 'Database reset failed: ' . $e->getMessage();
}

// Method 3: Clear any progress files
$progressFiles = glob(__DIR__ . '/../temp/progress_*.json');
$clearedFiles = 0;
foreach ($progressFiles as $file) {
    if (unlink($file)) {
        $clearedFiles++;
    }
}
if ($clearedFiles > 0) {
    $details[] = "Cleared {$clearedFiles} progress file(s)";
}

// Method 4: Clear lock files
$lockFiles = glob(__DIR__ . '/../temp/*.lock');
foreach ($lockFiles as $file) {
    @unlink($file);
}

// Build response message
$totalStopped = $killed + $dbReset;
if ($totalStopped === 0 && empty($runningAutomations)) {
    $message = "No running processes found";
} elseif ($killed > 0) {
    $message = "Successfully stopped {$killed} process(es)";
    if ($dbReset > 0) {
        $message .= " and reset {$dbReset} automation(s)";
    }
} elseif ($dbReset > 0) {
    $message = "Reset {$dbReset} automation(s) - no active PHP processes found";
} else {
    $message = "Cleanup complete";
}

echo json_encode([
    'success' => true,
    'message' => $message,
    'killed' => $killed,
    'db_reset' => $dbReset,
    'total_stopped' => $totalStopped,
    'details' => $details,
    'errors' => $errors,
    'previously_running' => count($runningAutomations)
]);
