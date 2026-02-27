<?php
$outputDir = (PHP_OS_FAMILY === 'Windows') 
    ? 'C:/VideoWorkflow/output/' 
    : getenv('HOME') . '/VideoWorkflow/output/';

$file = isset($_GET['file']) ? basename($_GET['file']) : '';
$filepath = $outputDir . $file;

if (!$file || !file_exists($filepath) || pathinfo($filepath, PATHINFO_EXTENSION) !== 'mp4') {
    http_response_code(404);
    echo 'Video not found';
    exit;
}

$filesize = filesize($filepath);
$download = isset($_GET['download']);

if ($download) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
} else {
    header('Content-Type: video/mp4');
    header('Accept-Ranges: bytes');
}

if (isset($_SERVER['HTTP_RANGE'])) {
    list($unit, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
    
    if ($unit == 'bytes') {
        list($start, $end) = explode('-', $range, 2);
        $start = intval($start);
        $end = $end ? intval($end) : $filesize - 1;
        
        if ($start > $end || $start >= $filesize) {
            http_response_code(416);
            header('Content-Range: bytes */' . $filesize);
            exit;
        }
        
        $length = $end - $start + 1;
        
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $filesize);
        header('Content-Length: ' . $length);
        
        $fp = fopen($filepath, 'rb');
        fseek($fp, $start);
        echo fread($fp, $length);
        fclose($fp);
        exit;
    }
}

header('Content-Length: ' . $filesize);
readfile($filepath);
