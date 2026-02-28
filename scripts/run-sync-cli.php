<?php
/**
 * CLI bridge for api/run-sync.php
 * Usage: php scripts/run-sync-cli.php <automation_id>
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must run in CLI mode.\n");
    exit(1);
}

$automationId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($automationId <= 0) {
    fwrite(STDERR, "Usage: php scripts/run-sync-cli.php <automation_id>\n");
    exit(1);
}

$_GET['id'] = $automationId;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'text/event-stream';

$runnerApi = realpath(__DIR__ . '/../api/run-sync.php');
if ($runnerApi === false) {
    fwrite(STDERR, "api/run-sync.php not found.\n");
    exit(1);
}

require $runnerApi;
