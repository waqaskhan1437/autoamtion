<?php
/**
 * Test Background Process
 * Debug endpoint to check if background PHP works on Windows
 */
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

$results = [];

// Check PHP path
$results['php_binary'] = PHP_BINARY;
$results['php_os'] = PHP_OS_FAMILY;

// Check possible PHP paths on Windows
if (PHP_OS_FAMILY === 'Windows') {
    $possiblePaths = [
        'C:\\xampp\\php\\php.exe',
        'C:\\xampp64\\php\\php.exe',
        'D:\\xampp\\php\\php.exe',
        dirname(PHP_BINARY) . '\\php.exe'
    ];
    
    foreach ($possiblePaths as $path) {
        $results['php_paths'][$path] = file_exists($path) ? 'EXISTS' : 'NOT FOUND';
    }
}

// Check run-background.php exists
$scriptPath = realpath(__DIR__ . '/../run-background.php');
$results['run_background_script'] = $scriptPath ?: 'NOT FOUND';

// Check logs directory
$logsDir = __DIR__ . '/../logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0777, true);
    $results['logs_dir'] = 'Created';
} else {
    $results['logs_dir'] = 'Exists';
}

// Read recent logs if they exist
$logFile = $logsDir . '/background.log';
if (file_exists($logFile)) {
    $results['recent_log'] = array_slice(file($logFile), -10);
} else {
    $results['recent_log'] = 'No logs yet';
}

$errorLogFile = $logsDir . '/background-error.log';
if (file_exists($errorLogFile)) {
    $results['error_log'] = array_slice(file($errorLogFile), -10);
} else {
    $results['error_log'] = 'No errors';
}

// Test simple PHP CLI execution
$testFile = $logsDir . '/cli-test.txt';
$phpPath = PHP_BINARY;
if (PHP_OS_FAMILY === 'Windows') {
    $phpPath = '"' . $phpPath . '"';
    $cmd = 'start /B ' . $phpPath . ' -r "file_put_contents(\'' . addslashes($testFile) . '\', date(\'Y-m-d H:i:s\'));"';
    pclose(popen($cmd, 'r'));
    sleep(1);
    $results['cli_test'] = file_exists($testFile) ? 'CLI WORKS: ' . file_get_contents($testFile) : 'CLI might not work';
} else {
    exec($phpPath . ' -r "file_put_contents(\'' . $testFile . '\', date(\'Y-m-d H:i:s\'));"');
    $results['cli_test'] = file_exists($testFile) ? 'CLI WORKS: ' . file_get_contents($testFile) : 'CLI failed';
}

echo json_encode($results, JSON_PRETTY_PRINT);
