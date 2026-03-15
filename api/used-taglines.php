<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$automationId = $_GET['automation_id'] ?? null;

if ($automationId) {
    $stmt = $pdo->prepare("
        SELECT tagline_text, tagline_type, used_at, video_identifier 
        FROM used_taglines 
        WHERE automation_id = ?
        ORDER BY used_at DESC
        LIMIT 50
    ");
    $stmt->execute([$automationId]);
} else {
    $stmt = $pdo->query("
        SELECT tagline_text, tagline_type, used_at, video_identifier, automation_id
        FROM used_taglines 
        ORDER BY used_at DESC
        LIMIT 50
    ");
}

$taglines = $stmt->fetchAll(PDO::FETCH_ASSOC);

$topCount = 0;
$bottomCount = 0;
foreach ($taglines as $t) {
    if ($t['tagline_type'] === 'top') $topCount++;
    else $bottomCount++;
}

echo json_encode([
    'success' => true,
    'taglines' => $taglines,
    'total_top' => $topCount,
    'total_bottom' => $bottomCount,
    'total' => count($taglines)
]);
