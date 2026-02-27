<?php
/**
 * Full Workflow Test
 * Tests: Local Tagline Generator, FFmpeg Processor, Output File Creation
 */
header('Content-Type: application/json');

$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'tests' => []
];

// Test 1: Local Tagline Generator
$results['tests']['local_tagline'] = testLocalTaglines();

// Test 2: Check FFmpeg availability
$results['tests']['ffmpeg_check'] = testFFmpegAvailable();

// Test 3: Check directories
$results['tests']['directories'] = testDirectories();

// Test 4: Create sample video and process
$results['tests']['video_process'] = testVideoProcess();

echo json_encode($results, JSON_PRETTY_PRINT);

function testLocalTaglines() {
    try {
        require_once __DIR__ . '/../includes/LocalTaglineGenerator.php';
        
        $generator = new LocalTaglineGenerator(
            'Create birthday greetings, use words like Boss, Legend, King',
            ['Boss', 'Legend', 'King', 'Queen', 'Star']
        );
        
        $taglines = $generator->generateBulk(5);
        
        if (count($taglines) >= 5) {
            return [
                'status' => 'PASS',
                'message' => 'Generated ' . count($taglines) . ' taglines',
                'sample' => $taglines[0]
            ];
        }
        
        return ['status' => 'FAIL', 'message' => 'Not enough taglines generated'];
    } catch (Exception $e) {
        return ['status' => 'ERROR', 'message' => $e->getMessage()];
    }
}

function testFFmpegAvailable() {
    // Check common FFmpeg paths
    $paths = [
        'C:/ffmpeg/bin/ffmpeg.exe',
        'C:/Program Files/ffmpeg/bin/ffmpeg.exe',
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg'
    ];
    
    $ffmpegPath = null;
    foreach ($paths as $path) {
        if (file_exists($path)) {
            $ffmpegPath = $path;
            break;
        }
    }
    
    // Try system PATH
    if (!$ffmpegPath) {
        $output = [];
        exec('ffmpeg -version 2>&1', $output, $code);
        if ($code === 0) {
            $ffmpegPath = 'ffmpeg (in PATH)';
        }
    }
    
    if ($ffmpegPath) {
        return [
            'status' => 'PASS',
            'message' => 'FFmpeg found',
            'path' => $ffmpegPath
        ];
    }
    
    return [
        'status' => 'FAIL',
        'message' => 'FFmpeg NOT found! Install from https://ffmpeg.org',
        'checked_paths' => $paths
    ];
}

function testDirectories() {
    $baseDir = (PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : getenv('HOME') . '/VideoWorkflow';
    $tempDir = $baseDir . '/temp';
    $outputDir = $baseDir . '/output';
    
    $results = [
        'base_dir' => $baseDir,
        'temp_exists' => is_dir($tempDir),
        'output_exists' => is_dir($outputDir),
        'temp_writable' => is_writable($tempDir),
        'output_writable' => is_writable($outputDir)
    ];
    
    // Create directories if needed
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0777, true);
        $results['temp_created'] = is_dir($tempDir);
    }
    if (!is_dir($outputDir)) {
        @mkdir($outputDir, 0777, true);
        $results['output_created'] = is_dir($outputDir);
    }
    
    $allGood = is_dir($tempDir) && is_dir($outputDir) && is_writable($tempDir) && is_writable($outputDir);
    
    return [
        'status' => $allGood ? 'PASS' : 'FAIL',
        'details' => $results
    ];
}

function testVideoProcess() {
    // Check if we have FFmpeg available
    require_once __DIR__ . '/../includes/FFmpegProcessor.php';
    
    try {
        $ffmpeg = new FFmpegProcessor();
        
        if (!$ffmpeg->isAvailable()) {
            return [
                'status' => 'SKIP',
                'message' => 'FFmpeg not available, cannot test video processing'
            ];
        }
        
        // Create a simple test video using FFmpeg (color bars)
        $baseDir = (PHP_OS_FAMILY === 'Windows') ? 'C:/VideoWorkflow' : getenv('HOME') . '/VideoWorkflow';
        $tempDir = $baseDir . '/temp';
        $outputDir = $baseDir . '/output';
        
        if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
        if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);
        
        $testInput = $tempDir . '/test_input.mp4';
        $testOutput = $outputDir . '/test_output_' . time() . '.mp4';
        
        // Create a simple 5-second test video
        $paths = $ffmpeg->getPaths();
        $ffmpegPath = $paths['ffmpeg'];
        $createCmd = sprintf(
            '"%s" -y -f lavfi -i "color=c=blue:s=720x1280:d=5" -c:v libx264 -t 5 "%s" 2>&1',
            $ffmpegPath,
            $testInput
        );
        
        exec($createCmd, $output, $returnCode);
        
        if ($returnCode !== 0 || !file_exists($testInput)) {
            return [
                'status' => 'FAIL',
                'message' => 'Could not create test video',
                'command' => $createCmd,
                'output' => implode("\n", $output)
            ];
        }
        
        // Now process it with text overlay
        $result = $ffmpeg->createShort(
            $testInput,
            $testOutput,
            [
                'duration' => 5,
                'aspectRatio' => '9:16',
                'topText' => 'Test Top Text',
                'bottomText' => 'Test Bottom CTA'
            ]
        );
        
        // Clean up test input
        @unlink($testInput);
        
        if (isset($result['success']) && $result['success'] && file_exists($testOutput)) {
            $size = round(filesize($testOutput) / 1024, 1);
            return [
                'status' => 'PASS',
                'message' => 'Video processed successfully!',
                'output_file' => $testOutput,
                'size_kb' => $size
            ];
        }
        
        return [
            'status' => 'FAIL',
            'message' => 'Video processing failed',
            'result' => $result
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'ERROR',
            'message' => $e->getMessage()
        ];
    }
}
