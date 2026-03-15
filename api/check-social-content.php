<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (isset($_GET['all'])) {
    $stmt = $pdo->query("SELECT id, name, social_titles_json, social_descriptions_json, social_hashtags_json, social_rotation_mode, current_social_index FROM automation_settings WHERE social_titles_json IS NOT NULL AND social_titles_json != '' ORDER BY id DESC");
    $automations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($automations, JSON_PRETTY_PRINT);
    exit;
}

$id = $_GET['id'] ?? 12;

$stmt = $pdo->prepare("SELECT id, name, social_titles_json, social_descriptions_json, social_hashtags_json, social_rotation_mode, current_social_index FROM automation_settings WHERE id = ?");
$stmt->execute([$id]);
$automation = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode($automation, JSON_PRETTY_PRINT);
