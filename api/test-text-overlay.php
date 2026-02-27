<?php
/**
 * Test Text Overlay - Debug endpoint to verify FFmpeg text overlay works
 * This creates a simple test video with text to verify the pipeline
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/FFmpegProcessor.php';

try {
    $ffmpeg = new FFmpegProcessor();
    
    // Check if FFmpeg is available
    if (!$ffmpeg->isAvailable()) {
        echo json_encode([
            'success' => false,
            'error' => 'FFmpeg not found. Please install FFmpeg.',
            'help' => 'Download from: https://www.gyan.dev/ffmpeg/builds/'
        ]);
        exit;
    }
    
    $results = [
        'ffmpeg_available' => true,
        'tests' => []
    ];
    
    // Get info about the processor
    $reflection = new ReflectionObject($ffmpeg);
    $fontPathProp = $reflection->getProperty('fontPath');
    $fontPathProp->setAccessible(true);
    $fontPath = $fontPathProp->getValue($ffmpeg);
    
    $tempDirProp = $reflection->getProperty('tempDir');
    $tempDirProp->setAccessible(true);
    $tempDir = $tempDirProp->getValue($ffmpeg);
    
    $ffmpegPathProp = $reflection->getProperty('ffmpegPath');
    $ffmpegPathProp->setAccessible(true);
    $ffmpegPath = $ffmpegPathProp->getValue($ffmpeg);
    
    $results['config'] = [
        'ffmpeg_path' => $ffmpegPath,
        'font_path' => $fontPath,
        'font_path_unescaped' => str_replace('\\:', ':', $fontPath),
        'temp_dir' => $tempDir,
        'os' => PHP_OS_FAMILY
    ];
    
    // Check if font exists (unescaped path)
    $unescapedFontPath = str_replace('\\:', ':', $fontPath);
    $results['config']['font_exists'] = file_exists($unescapedFontPath);
    
    // Check temp directory
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    $results['config']['temp_dir_writable'] = is_writable($tempDir);
    
    // Test 1: Create a simple color video with text overlay
    $testOutput = $tempDir . '/test_overlay_' . time() . '.mp4';
    $testTextFile = $tempDir . '/test_text.txt';
    
    // Write test text to file
    file_put_contents($testTextFile, 'Hello World Test');
    
    // Escape paths for FFmpeg on Windows
    $testTextFilePath = str_replace('\\', '/', $testTextFile);
    $testOutputPath = str_replace('\\', '/', $testOutput);
    
    if (PHP_OS_FAMILY === 'Windows') {
        $testTextFilePath = str_replace(':', '\\:', $testTextFilePath);
    }
    
    // Create a simple test: generate color video with text
    $drawtext = "drawtext=textfile='{$testTextFilePath}':fontfile={$fontPath}:fontsize=48:fontcolor=white:x=(w-text_w)/2:y=(h-text_h)/2";
    
    $testCommand = sprintf(
        '"%s" -y -f lavfi -i color=c=blue:s=640x360:d=2 -vf "%s" -c:v libx264 -preset ultrafast -t 2 "%s" 2>&1',
        $ffmpegPath,
        $drawtext,
        $testOutputPath
    );
    
    $results['tests']['text_overlay'] = [
        'command' => $testCommand
    ];
    
    exec($testCommand, $output, $returnCode);
    
    $results['tests']['text_overlay']['return_code'] = $returnCode;
    $results['tests']['text_overlay']['output'] = implode("\n", array_slice($output, -10));
    $results['tests']['text_overlay']['success'] = ($returnCode === 0 && file_exists($testOutput));
    
    if (file_exists($testOutput)) {
        $results['tests']['text_overlay']['file_size'] = filesize($testOutput);
        $results['tests']['text_overlay']['file_path'] = $testOutput;
        // Don't delete so user can check the video
    }
    
    // Cleanup text file
    if (file_exists($testTextFile)) {
        unlink($testTextFile);
    }
    
    // Overall status
    $results['success'] = $results['tests']['text_overlay']['success'];
    
    if ($results['success']) {
        $results['message'] = 'Text overlay is working! Check the test video at: ' . $testOutput;
    } else {
        $results['message'] = 'Text overlay failed. Check the error output above.';
        $results['troubleshooting'] = [
            '1. Make sure the font file exists at: ' . $unescapedFontPath,
            '2. FFmpeg must have libfreetype and libharfbuzz enabled',
            '3. Check FFmpeg version: ffmpeg -version',
            '4. Check drawtext filter: ffmpeg -filters | findstr drawtext'
        ];
    }
    
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
