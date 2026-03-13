<?php

$rootDir = dirname(__DIR__);
$defaultPath = $rootDir . '/content/prankwish-social/library.json';
$libraryPath = $argv[1] ?? $defaultPath;
$requiredPlatforms = ['youtube', 'tiktok', 'instagram', 'facebook', 'twitter', 'threads', 'linkedin', 'pinterest', 'bluesky'];
$errors = [];
$warnings = [];

if (!is_file($libraryPath)) {
    fwrite(STDERR, "Library file not found: {$libraryPath}\n");
    exit(1);
}

$json = file_get_contents($libraryPath);
$decoded = json_decode((string) $json, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "Invalid JSON in {$libraryPath}\n");
    exit(1);
}

$libraryKey = trim((string) ($decoded['library_key'] ?? ''));
if ($libraryKey === '') {
    $errors[] = 'library_key is required.';
}

$packs = $decoded['packs'] ?? null;
if (!is_array($packs) || empty($packs)) {
    $errors[] = 'packs must be a non-empty array.';
}

$seenIds = [];
foreach ((array) $packs as $index => $pack) {
    $label = 'pack[' . $index . ']';
    if (!is_array($pack)) {
        $errors[] = "{$label} must be an object.";
        continue;
    }

    $packId = trim((string) ($pack['id'] ?? ''));
    if ($packId === '') {
        $errors[] = "{$label}.id is required.";
    } elseif (isset($seenIds[strtolower($packId)])) {
        $errors[] = "{$label}.id '{$packId}' is duplicated.";
    } else {
        $seenIds[strtolower($packId)] = true;
    }

    $platforms = $pack['platforms'] ?? null;
    if (!is_array($platforms)) {
        $errors[] = "{$label}.platforms must be an object.";
        continue;
    }

    foreach ($requiredPlatforms as $platform) {
        $entry = $platforms[$platform] ?? null;
        if (!is_array($entry)) {
            $errors[] = "{$label}.platforms.{$platform} is required.";
            continue;
        }

        $title = trim((string) ($entry['title'] ?? ''));
        $description = trim((string) ($entry['description'] ?? ''));
        $hashtags = $entry['hashtags'] ?? null;

        if ($title === '') {
            $errors[] = "{$label}.platforms.{$platform}.title is required.";
        }
        if ($description === '') {
            $errors[] = "{$label}.platforms.{$platform}.description is required.";
        }
        if (!is_array($hashtags) || empty($hashtags)) {
            $errors[] = "{$label}.platforms.{$platform}.hashtags must be a non-empty array.";
        } else {
            foreach ($hashtags as $tagIndex => $tag) {
                $tag = trim((string) $tag);
                if ($tag === '' || $tag[0] !== '#') {
                    $errors[] = "{$label}.platforms.{$platform}.hashtags[{$tagIndex}] must start with #.";
                }
            }
        }

        if ($title !== '') {
            foreach (['happy birthday', 'mothers day', 'fathers day', 'valentines day', 'merry christmas', 'new year'] as $signal) {
                if (stripos($title, $signal) !== false) {
                    $warnings[] = "{$label}.platforms.{$platform}.title looks occasion-specific; keep titles service-based.";
                    break;
                }
            }
        }
        if ($description !== '' && stripos($description, 'prankwish') === false) {
            $warnings[] = "{$label}.platforms.{$platform}.description should mention PrankWish.com.";
        }
        if ($description !== '' && stripos($description, 'script') === false) {
            $warnings[] = "{$label}.platforms.{$platform}.description should mention the custom script step.";
        }
        if ($description !== '' && stripos($description, 'email or WhatsApp') === false && stripos($description, 'email or whatsapp') === false) {
            $warnings[] = "{$label}.platforms.{$platform}.description should mention digital delivery on email or WhatsApp.";
        }
    }
}

if (!empty($errors)) {
    fwrite(STDERR, "Validation errors in {$libraryPath}:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "Library valid: {$libraryPath}\n";
echo 'Packs: ' . count((array) $packs) . PHP_EOL;
echo 'library_key: ' . $libraryKey . PHP_EOL;

if (!empty($warnings)) {
    echo "Warnings:\n";
    foreach ($warnings as $warning) {
        echo ' - ' . $warning . PHP_EOL;
    }
}
