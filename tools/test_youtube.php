<?php

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/YouTubeSource.php';

$source = new YouTubeSource('https://www.youtube.com/@HUMNewsPakistan/videos');
$ref = new ReflectionClass($source);
$method = $ref->getMethod('getCandidateEntries');
$method->setAccessible(true);
$candidates = $method->invoke($source, 25);
echo 'candidates=' . count($candidates) . PHP_EOL;
foreach (array_slice($candidates, 0, 5) as $candidate) {
    echo 'candidate: ' . ($candidate['id'] ?? '') . ' | ' . ($candidate['url'] ?? '') . PHP_EOL;
}

$runCommand = $ref->getMethod('runCommand');
$runCommand->setAccessible(true);
$command = [
    'C:\\VideoWorkflow\\bin\\yt-dlp.exe',
    '--skip-download',
    '--ignore-errors',
    '--no-warnings',
    '--no-playlist',
    '--print',
    "%(id)s\t%(upload_date)s\t%(timestamp)s\t%(duration)s\t%(webpage_url)s\t%(live_status)s\t%(title)s",
    '--js-runtimes',
    'node',
];
foreach (array_slice($candidates, 0, 5) as $candidate) {
    $command[] = $candidate['url'];
}
$raw = $runCommand->invoke($source, $command);
echo "raw_exit=" . ($raw['exit_code'] ?? '') . PHP_EOL;
echo "raw_stdout=" . PHP_EOL . ($raw['stdout'] ?? '') . PHP_EOL;
echo "raw_stderr=" . PHP_EOL . ($raw['stderr'] ?? '') . PHP_EOL;

$parseMetadataLine = $ref->getMethod('parseMetadataLine');
$parseMetadataLine->setAccessible(true);
$firstLine = preg_split("/\r\n|\n|\r/", (string)($raw['stdout'] ?? ''))[0] ?? '';
$parsedFirst = $parseMetadataLine->invoke($source, $firstLine);
echo 'parsed_first=' . var_export($parsedFirst, true) . PHP_EOL;

$videos = $source->listVideos(1, null, null, 10);
echo 'videos=' . count($videos) . PHP_EOL;
foreach (array_slice($videos, 0, 5) as $video) {
    echo 'video: ' . $video['filename'] . ' | ' . $video['upload_date'] . ' | ' . $video['title'] . PHP_EOL;
}
