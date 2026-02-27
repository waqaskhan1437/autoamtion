<?php
/**
 * FFmpeg Video Processor
 * Handles video conversion, short creation, and text overlay
 * Supports Windows XAMPP environment
 */

class FFmpegProcessor {
    private $ffmpegPath;
    private $ffprobePath;
    private $tempDir;
    private $fontPath;
    
    public function __construct($ffmpegPath = null, $ffprobePath = null) {
        // Auto-detect FFmpeg path
        $this->ffmpegPath = $ffmpegPath ?: $this->findExecutable('ffmpeg');
        $this->ffprobePath = $ffprobePath ?: $this->findExecutable('ffprobe');
        
        // Set font path based on OS - CRITICAL: proper FFmpeg escaping
        if (PHP_OS_FAMILY === 'Windows') {
            // FFmpeg on Windows: use forward slashes, escape colon with single backslash
            // PRIORITY: Emoji-compatible fonts first, then regular fonts
            // BOLD fonts first for better visibility (like reference screenshot)
            $fontCandidates = [
                'C:/Windows/Fonts/arialbd.ttf',    // Arial Bold - BEST for bold text
                'C:/Windows/Fonts/ARIALBD.TTF',
                'C:/Windows/Fonts/ariblk.ttf',     // Arial Black - Extra bold
                'C:/Windows/Fonts/ARIBLK.TTF',
                'C:/Windows/Fonts/segoeui.ttf',
                'C:/Windows/Fonts/arial.ttf',
                'C:/Windows/Fonts/ARIAL.TTF',
                'C:/Windows/Fonts/tahoma.ttf'
            ];
            
            $this->fontPath = null;
            foreach ($fontCandidates as $font) {
                if (file_exists($font)) {
                    // Escape colon for FFmpeg filter: C\:/Windows/Fonts/arial.ttf
                    $this->fontPath = str_replace(':', '\\:', $font);
                    break;
                }
            }
            
            // Fallback if no font found
            if (!$this->fontPath) {
                $this->fontPath = 'C\\:/Windows/Fonts/arial.ttf';
            }
            
            $this->tempDir = 'C:/VideoWorkflow/temp';
        } else {
            // Linux font paths
            $linuxFonts = [
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/TTF/DejaVuSans.ttf',
                '/usr/share/fonts/liberation/LiberationSans-Regular.ttf'
            ];
            
            $this->fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
            foreach ($linuxFonts as $font) {
                if (file_exists($font)) {
                    $this->fontPath = $font;
                    break;
                }
            }
            
            $this->tempDir = getenv('HOME') . '/VideoWorkflow/temp';
        }
        
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }
    
    /**
     * Find executable in common locations
     */
    private function findExecutable($name) {
        // Common FFmpeg locations
        $paths = [];
        
        if (PHP_OS_FAMILY === 'Windows') {
            $paths = [
                'C:/ffmpeg/bin/' . $name . '.exe',
                'C:/Program Files/ffmpeg/bin/' . $name . '.exe',
                'C:/VideoWorkflow/ffmpeg/bin/' . $name . '.exe',
                getenv('USERPROFILE') . '/ffmpeg/bin/' . $name . '.exe',
                $name . '.exe', // In PATH
                $name
            ];
        } else {
            $paths = [
                '/usr/bin/' . $name,
                '/usr/local/bin/' . $name,
                $name
            ];
        }
        
        foreach ($paths as $path) {
            if (file_exists($path) || $this->testCommand($path)) {
                return $path;
            }
        }
        
        return $name; // Fallback to just the name
    }
    
    /**
     * Test if a command works
     */
    private function testCommand($command) {
        $output = [];
        $returnCode = 0;
        @exec($command . ' -version 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }
    
    /**
     * Get video information
     */
    public function getVideoInfo($inputPath) {
        $command = sprintf(
            '"%s" -v quiet -print_format json -show_format -show_streams "%s" 2>&1',
            $this->ffprobePath,
            str_replace('\\', '/', $inputPath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            return ['error' => 'Failed to get video info', 'command' => $command];
        }
        
        $info = json_decode(implode('', $output), true);
        
        $videoStream = null;
        foreach ($info['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'video') {
                $videoStream = $stream;
                break;
            }
        }
        
        // Parse frame rate
        $fps = 30;
        if (isset($videoStream['r_frame_rate'])) {
            $parts = explode('/', $videoStream['r_frame_rate']);
            if (count($parts) === 2 && $parts[1] != 0) {
                $fps = intval($parts[0]) / intval($parts[1]);
            }
        }
        
        return [
            'duration' => floatval($info['format']['duration'] ?? 0),
            'width' => intval($videoStream['width'] ?? 0),
            'height' => intval($videoStream['height'] ?? 0),
            'fps' => $fps,
            'codec' => $videoStream['codec_name'] ?? 'unknown',
            'bitrate' => intval($info['format']['bit_rate'] ?? 0)
        ];
    }
    
    /**
     * Convert video to short format (9:16, 1:1, or 16:9)
     */
    public function createShort($inputPath, $outputPath, $options = []) {
        $duration = $options['duration'] ?? 60;
        $startTime = $options['startTime'] ?? 0;
        $aspectRatio = $options['aspectRatio'] ?? '9:16';
        $topText = trim($options['topText'] ?? '');
        $bottomText = trim($options['bottomText'] ?? '');
        $subtitlesPath = $options['subtitlesPath'] ?? null;
        
        // Debug logging
        $debugLog = $this->tempDir . '/ffmpeg_debug.log';
        file_put_contents($debugLog, date('Y-m-d H:i:s') . " createShort called\n", FILE_APPEND);
        file_put_contents($debugLog, "  topText: '{$topText}'\n", FILE_APPEND);
        file_put_contents($debugLog, "  bottomText: '{$bottomText}'\n", FILE_APPEND);
        file_put_contents($debugLog, "  fontPath: '{$this->fontPath}'\n", FILE_APPEND);
        
        // Calculate output dimensions based on aspect ratio
        $noCrop = false;
        switch ($aspectRatio) {
            case '9:16':
                $width = 1080;
                $height = 1920;
                $cropFilter = 'crop=ih*9/16:ih'; // Crop from center for vertical
                break;
            case '9:16-fit':
                // NO CROP - Fit video in frame with black bars
                $width = 1080;
                $height = 1920;
                $noCrop = true;
                break;
            case '1:1':
                $width = 1080;
                $height = 1080;
                $cropFilter = 'crop=min(iw\\,ih):min(iw\\,ih)'; // Center square crop
                break;
            case '1:1-fit':
                // NO CROP - Fit video in square with black bars
                $width = 1080;
                $height = 1080;
                $noCrop = true;
                break;
            case '16:9':
                $width = 1920;
                $height = 1080;
                $cropFilter = 'crop=iw:iw*9/16'; // Keep width, crop height
                break;
            case '16:9-fit':
                // NO CROP - Fit video in frame with black bars
                $width = 1920;
                $height = 1080;
                $noCrop = true;
                break;
            default:
                $width = 1080;
                $height = 1920;
                $cropFilter = 'crop=ih*9/16:ih';
        }
        
        // Build filter chain
        $filters = [];
        $tempFiles = []; // Track temp files to clean up
        
        if ($noCrop) {
            // NO CROP MODE: Scale to fit inside frame, then pad with black bars
            // This keeps the entire video visible without cropping
            $filters[] = "scale={$width}:{$height}:force_original_aspect_ratio=decrease:flags=lanczos";
            $filters[] = "pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2:black";
        } else {
            // CROP MODE: Crop to aspect ratio, then scale
            $filters[] = $cropFilter;
            $filters[] = "scale={$width}:{$height}:flags=lanczos";
        }
        
        // BEST PRACTICE: Use textfile instead of text parameter to avoid escaping issues
        // Add top branding text - WHITE BACKGROUND + BLACK TEXT (like screenshot)
        // Style: 2-line wrapped text, centered
        // Emoji will be overlaid as PNG for colorful display
        $emojiPng = $options['emojiPng'] ?? null;
        $hasEmoji = !empty($emojiPng) && file_exists($emojiPng);
        
        if ($topText !== '') {
            // Split text into two lines for separate drawtext filters (avoids Windows newline issues)
            $lines = $this->splitToTwoLines($topText, 28);
            $line1 = $lines[0] ?? '';
            $line2 = $lines[1] ?? '';
            
            // Add spaces at end of emoji line to extend white box for emoji
            // 4 spaces for 48px emoji + padding
            $emojiSpaces = '    ';  // 4 spaces for emoji area
            
            // Calculate emoji position based on text width (before adding spaces)
            // Font size 48, average char width ~24px (more accurate)
            $emojiLine = ($line2 !== '') ? $line2 : $line1;
            $emojiLineY = ($line2 !== '') ? 140 : 70;
            $textWidth = strlen($emojiLine) * 24; // Approximate text width
            // Emoji x = center of video + half of original text width - small offset to stay inside box
            $emojiXOffset = ($textWidth / 2) - 5;
            
            // Line 1
            if ($line1 !== '') {
                $line1WithSpaces = $line1;
                // Add spaces only if this is the emoji line (no line2)
                if ($line2 === '' && $hasEmoji) {
                    $line1WithSpaces = $line1 . $emojiSpaces;
                }
                
                $line1File = $this->tempDir . '/line1_' . uniqid() . '.txt';
                file_put_contents($line1File, $line1WithSpaces);
                $tempFiles[] = $line1File;
                
                $line1Path = str_replace('\\', '/', $line1File);
                if (PHP_OS_FAMILY === 'Windows') {
                    $line1Path = str_replace(':', '\\:', $line1Path);
                }
                
                // First line - white box, black text, centered
                $filters[] = "drawtext=textfile='{$line1Path}':fontfile='{$this->fontPath}':fontsize=48:fontcolor=black:box=1:boxcolor=white@0.95:boxborderw=18:x=(w-text_w)/2:y=70";
            }
            
            // Line 2
            if ($line2 !== '') {
                // Add spaces at end for emoji area
                $line2WithSpaces = $hasEmoji ? $line2 . $emojiSpaces : $line2;
                
                $line2File = $this->tempDir . '/line2_' . uniqid() . '.txt';
                file_put_contents($line2File, $line2WithSpaces);
                $tempFiles[] = $line2File;
                
                $line2Path = str_replace('\\', '/', $line2File);
                if (PHP_OS_FAMILY === 'Windows') {
                    $line2Path = str_replace(':', '\\:', $line2Path);
                }
                
                // Second line - white box, black text, centered
                $filters[] = "drawtext=textfile='{$line2Path}':fontfile='{$this->fontPath}':fontsize=48:fontcolor=black:box=1:boxcolor=white@0.95:boxborderw=18:x=(w-text_w)/2:y=140";
            }
            
            // Store emoji position for PNG overlay
            $this->emojiXOffset = $emojiXOffset;
            $this->emojiY = $emojiLineY;
            
            file_put_contents($debugLog, "  Line 1: {$line1}\n", FILE_APPEND);
            file_put_contents($debugLog, "  Line 2: {$line2}\n", FILE_APPEND);
            file_put_contents($debugLog, "  Emoji position: x=w/2+{$emojiXOffset}, y={$emojiLineY}\n", FILE_APPEND);
        }
        
        // Add bottom branding text
        if ($bottomText !== '') {
            $bottomTextFile = $this->tempDir . '/bottom_text_' . uniqid() . '.txt';
            file_put_contents($bottomTextFile, $bottomText);
            $tempFiles[] = $bottomTextFile;
            
            $bottomTextFilePath = str_replace('\\', '/', $bottomTextFile);
            if (PHP_OS_FAMILY === 'Windows') {
                $bottomTextFilePath = str_replace(':', '\\:', $bottomTextFilePath);
            }
            
            // Style: White box background, black bold text, centered (same as top)
            $filters[] = "drawtext=textfile='{$bottomTextFilePath}':fontfile='{$this->fontPath}':fontsize=40:fontcolor=black:box=1:boxcolor=white@0.9:boxborderw=12:x=(w-text_w)/2:y=h-120";
            file_put_contents($debugLog, "  Added bottom text filter with file: {$bottomTextFilePath}\n", FILE_APPEND);
        }
        
        $filterString = implode(',', $filters);
        
        // Convert paths for Windows
        $inputPathSafe = str_replace('\\', '/', $inputPath);
        $outputPathSafe = str_replace('\\', '/', $outputPath);
        
        // Ensure output directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
        
        // Build FFmpeg command - with colorful PNG emoji overlay
        if ($hasEmoji && isset($this->emojiXOffset)) {
            // Emoji PNG overlay for colorful display
            $emojiPngInput = str_replace('\\', '/', $emojiPng);
            $emojiY = $this->emojiY ?? 140;
            $emojiXOffset = $this->emojiXOffset ?? 100;
            
            // Filter complex: apply video filters, then overlay emoji PNG
            // Emoji size 48x48 to match font size, position at end of text line
            $command = sprintf(
                '"%s" -y -ss %s -i "%s" -i "%s" -t %d -filter_complex "[0:v]%s[text];[1:v]scale=48:48[emoji];[text][emoji]overlay=x=(main_w/2)+%d:y=%d" -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k "%s" 2>&1',
                $this->ffmpegPath,
                $startTime,
                $inputPathSafe,
                $emojiPngInput,
                $duration,
                $filterString,
                (int)$emojiXOffset,
                (int)$emojiY,
                $outputPathSafe
            );
            file_put_contents($debugLog, "  COLORFUL EMOJI: {$emojiPngInput} at x=w/2+{$emojiXOffset}, y={$emojiY}\n", FILE_APPEND);
        } else {
            // Standard command without emoji
            $command = sprintf(
                '"%s" -y -ss %s -i "%s" -t %d -vf "%s" -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k "%s" 2>&1',
                $this->ffmpegPath,
                $startTime,
                $inputPathSafe,
                $duration,
                $filterString,
                $outputPathSafe
            );
            file_put_contents($debugLog, "  No emoji PNG available\n", FILE_APPEND);
        }
        
        // Log command for debugging
        file_put_contents($debugLog, "  Command: {$command}\n", FILE_APPEND);
        
        exec($command, $output, $returnCode);
        
        // Log output
        file_put_contents($debugLog, "  Return code: {$returnCode}\n", FILE_APPEND);
        file_put_contents($debugLog, "  Output: " . implode("\n", array_slice($output, -10)) . "\n\n", FILE_APPEND);
        
        // Clean up temp text files
        foreach ($tempFiles as $tempFile) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
        
        if ($returnCode !== 0) {
            return [
                'success' => false,
                'error' => 'FFmpeg processing failed',
                'output' => implode("\n", array_slice($output, -20)), // Last 20 lines
                'command' => $command
            ];
        }
        
        // Verify output file exists
        if (!file_exists($outputPath) || filesize($outputPath) < 1000) {
            return [
                'success' => false,
                'error' => 'Output file not created or too small',
                'command' => $command
            ];
        }
        
        // Add subtitles if provided
        if ($subtitlesPath && file_exists($subtitlesPath)) {
            $tempOutput = $this->tempDir . '/' . uniqid('temp_') . '.mp4';
            rename($outputPath, $tempOutput);
            
            $subResult = $this->addSubtitles($tempOutput, $subtitlesPath, $outputPath);
            @unlink($tempOutput);
            
            if (!$subResult) {
                // If subtitles fail, restore original
                rename($tempOutput, $outputPath);
            }
        }
        
        return [
            'success' => true,
            'output' => $outputPath,
            'duration' => $duration,
            'width' => $width,
            'height' => $height
        ];
    }
    
    /**
     * Add subtitles overlay to video
     */
    public function addSubtitles($inputPath, $subtitlesPath, $outputPath) {
        $ext = strtolower(pathinfo($subtitlesPath, PATHINFO_EXTENSION));
        
        // Escape path for FFmpeg
        $subsPathEscaped = str_replace([':', '\\'], ['\\:', '/'], $subtitlesPath);
        
        if ($ext === 'ass') {
            $filter = "ass=" . $subsPathEscaped;
        } else {
            $filter = "subtitles=" . $subsPathEscaped;
        }
        
        $command = sprintf(
            '"%s" -y -i "%s" -vf "%s" -c:v libx264 -preset fast -crf 23 -c:a copy "%s" 2>&1',
            $this->ffmpegPath,
            str_replace('\\', '/', $inputPath),
            $filter,
            str_replace('\\', '/', $outputPath)
        );
        
        exec($command, $output, $returnCode);
        
        return $returnCode === 0 && file_exists($outputPath);
    }
    
    /**
     * Extract thumbnail from video
     */
    public function extractThumbnail($inputPath, $outputPath, $time = 5) {
        $command = sprintf(
            '"%s" -y -ss %d -i "%s" -vframes 1 -q:v 2 "%s" 2>&1',
            $this->ffmpegPath,
            $time,
            str_replace('\\', '/', $inputPath),
            str_replace('\\', '/', $outputPath)
        );
        
        exec($command, $output, $returnCode);
        
        return $returnCode === 0 && file_exists($outputPath);
    }
    
    /**
     * Extract audio from video (for Whisper transcription)
     */
    public function extractAudio($inputPath, $outputPath = null) {
        if (!$outputPath) {
            $outputPath = $this->tempDir . '/' . pathinfo($inputPath, PATHINFO_FILENAME) . '.mp3';
        }
        
        // Extract audio, mono, 16kHz (optimal for Whisper)
        $command = sprintf(
            '"%s" -y -i "%s" -ar 16000 -ac 1 -c:a mp3 "%s" 2>&1',
            $this->ffmpegPath,
            str_replace('\\', '/', $inputPath),
            str_replace('\\', '/', $outputPath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($outputPath)) {
            return $outputPath;
        }
        
        return null;
    }
    
    /**
     * Get best segment for short (based on video duration)
     */
    public function findBestSegment($inputPath, $duration = 60) {
        $videoInfo = $this->getVideoInfo($inputPath);
        $totalDuration = $videoInfo['duration'] ?? 0;
        
        if ($totalDuration <= $duration) {
            return 0; // Use from beginning
        }
        
        // Start from 10% into the video to skip intros
        $startTime = min($totalDuration * 0.1, $totalDuration - $duration);
        
        return max(0, floor($startTime));
    }
    
    /**
     * Split text into exactly two lines (for separate drawtext filters)
     * @param string $text Input text
     * @param int $maxCharsPerLine Max characters per line
     * @return array Array with line1 and line2
     */
    private function splitToTwoLines($text, $maxCharsPerLine = 28) {
        $words = explode(' ', $text);
        $line1 = '';
        $line2 = '';
        $onLine2 = false;
        
        foreach ($words as $word) {
            if (!$onLine2) {
                // Building line 1
                $testLine = $line1 === '' ? $word : $line1 . ' ' . $word;
                if (strlen($testLine) <= $maxCharsPerLine) {
                    $line1 = $testLine;
                } else {
                    // Start line 2
                    $onLine2 = true;
                    $line2 = $word;
                }
            } else {
                // Building line 2
                $line2 .= ' ' . $word;
            }
        }
        
        return [trim($line1), trim($line2)];
    }
    
    /**
     * Wrap text to multiple lines for 2-line display (like screenshot)
     * @param string $text Input text
     * @param int $maxCharsPerLine Max characters per line (default 30 for longer taglines)
     * @return string Text with newlines for wrapping
     */
    private function wrapText($text, $maxCharsPerLine = 30) {
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';
        
        foreach ($words as $word) {
            // If adding this word exceeds limit, start new line
            if (strlen($currentLine . ' ' . $word) > $maxCharsPerLine && $currentLine !== '') {
                $lines[] = trim($currentLine);
                $currentLine = $word;
            } else {
                $currentLine .= ($currentLine === '' ? '' : ' ') . $word;
            }
        }
        
        // Add remaining text
        if ($currentLine !== '') {
            $lines[] = trim($currentLine);
        }
        
        // Limit to 2 lines max
        if (count($lines) > 2) {
            $lines = array_slice($lines, 0, 2);
        }
        
        // Use just newline for FFmpeg text files (not CRLF)
        // FFmpeg drawtext handles \n properly
        return implode("\n", $lines);
    }
    
    /**
     * Escape text for FFmpeg drawtext filter
     */
    private function escapeFFmpegText($text) {
        // Escape special FFmpeg characters
        $text = str_replace("\\", "\\\\\\\\", $text); // Backslash
        $text = str_replace("'", "'\\\\\\''", $text); // Single quote
        $text = str_replace(":", "\\:", $text); // Colon
        $text = str_replace("[", "\\[", $text); // Brackets
        $text = str_replace("]", "\\]", $text);
        $text = str_replace("%", "\\%", $text); // Percent
        return $text;
    }
    
    /**
     * Check if FFmpeg is available
     */
    public function isAvailable() {
        return $this->testCommand('"' . $this->ffmpegPath . '"');
    }
    
    /**
     * Get FFmpeg version info
     */
    public function getVersion() {
        $output = [];
        exec('"' . $this->ffmpegPath . '" -version 2>&1', $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output)) {
            return $output[0];
        }
        
        return 'FFmpeg not found';
    }
    
    /**
     * Get paths (for debugging)
     */
    public function getPaths() {
        return [
            'ffmpeg' => $this->ffmpegPath,
            'ffprobe' => $this->ffprobePath,
            'font' => $this->fontPath,
            'temp' => $this->tempDir
        ];
    }
}
?>
