<?php

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$outputPath = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($outputPath === '') {
    fwrite(STDERR, "Usage: php scripts/build-package.php <output_zip_path>\n");
    exit(1);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive extension is required.\n");
    exit(1);
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(1);
}

$outputPath = str_replace('\\', '/', $outputPath);
$outputDir = dirname($outputPath);
if (!is_dir($outputDir) && !@mkdir($outputDir, 0777, true)) {
    fwrite(STDERR, "Unable to create output directory: {$outputDir}\n");
    exit(1);
}

if (is_file($outputPath) && !@unlink($outputPath)) {
    fwrite(STDERR, "Unable to overwrite existing file: {$outputPath}\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($outputPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Unable to open zip archive: {$outputPath}\n");
    exit(1);
}

$skipPrefixes = [
    '.git/',
    '.vs/',
    '.idea/',
    'cloudflare-worker/node_modules/',
    'cloudflare-worker/.wrangler/',
    'node_modules/'
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $fullPath = str_replace('\\', '/', $file->getPathname());
    $relativePath = ltrim(str_replace('\\', '/', substr($fullPath, strlen(str_replace('\\', '/', $root)))), '/');
    if ($relativePath === '') {
        continue;
    }

    $skip = false;
    foreach ($skipPrefixes as $prefix) {
        if (strpos($relativePath, $prefix) === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    if (!$zip->addFile($fullPath, $relativePath)) {
        $zip->close();
        @unlink($outputPath);
        fwrite(STDERR, "Failed to add file to zip: {$relativePath}\n");
        exit(1);
    }
}

$zip->close();
fwrite(STDOUT, $outputPath . PHP_EOL);
