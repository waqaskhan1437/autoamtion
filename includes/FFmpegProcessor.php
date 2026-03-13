<?php
/**
 * FFmpeg Video Processor
 * Handles video conversion, short creation, and text overlay
 * Supports Windows XAMPP environment
 */

require_once __DIR__ . '/RuntimeBootstrap.php';

class FFmpegProcessor {
    private $ffmpegPath;
    private $ffprobePath;
    private $tempDir;
    private $fontPath;
    
    public function __construct($ffmpegPath = null, $ffprobePath = null) {
        $runtime = new RuntimeBootstrap(isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO ? $GLOBALS['pdo'] : null);
        $runtimePaths = $runtime->discoverFFmpegPaths();

        // Resolve FFmpeg/ffprobe using explicit override, DB settings, runtime bin, then PATH.
        $this->ffmpegPath = $ffmpegPath ?: ($runtimePaths['ffmpeg'] ?? null) ?: $this->findExecutable('ffmpeg');
        $this->ffprobePath = $ffprobePath ?: ($runtimePaths['ffprobe'] ?? null) ?: $this->findExecutable('ffprobe');
        
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
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/TTF/DejaVuSans.ttf',
                '/usr/share/fonts/liberation/LiberationSans-Regular.ttf'
            ];
            
            $this->fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
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
        @exec(escapeshellarg($command) . ' -version 2>&1', $output, $returnCode);
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
        $audioStream = null;
        foreach ($info['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'video') {
                $videoStream = $stream;
                break;
            }
        }
        foreach ($info['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'audio') {
                $audioStream = $stream;
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
            'bitrate' => intval($info['format']['bit_rate'] ?? 0),
            'has_audio' => $audioStream !== null
        ];
    }
    
    /**
     * Convert video to short format (9:16, 1:1, or 16:9)
     */
    public function createShort($inputPath, $outputPath, $options = []) {
        $duration = $options['duration'] ?? 60;
        $startTime = $options['startTime'] ?? 0;
        $aspectRatio = $options['aspectRatio'] ?? '9:16';
        $playbackSpeed = isset($options['playbackSpeed']) ? (float)$options['playbackSpeed'] : 1.0;
        $topText = trim($options['topText'] ?? '');
        $bottomText = trim($options['bottomText'] ?? '');
        $subtitlesPath = $options['subtitlesPath'] ?? null;
        if ($playbackSpeed < 0.1) $playbackSpeed = 0.1;
        if ($playbackSpeed > 3.0) $playbackSpeed = 3.0;
        $playbackSpeedString = number_format($playbackSpeed, 1, '.', '');
        $mediaInfo = $this->getVideoInfo($inputPath);
        $hasAudio = !empty($mediaInfo['has_audio']);
        
        // Debug logging
        $debugLog = $this->tempDir . '/ffmpeg_debug.log';
        file_put_contents($debugLog, date('Y-m-d H:i:s') . " createShort called\n", FILE_APPEND);
        file_put_contents($debugLog, "  topText: '{$topText}'\n", FILE_APPEND);
        file_put_contents($debugLog, "  bottomText: '{$bottomText}'\n", FILE_APPEND);
        file_put_contents($debugLog, "  fontPath: '{$this->fontPath}'\n", FILE_APPEND);
        file_put_contents($debugLog, "  playbackSpeed: '{$playbackSpeedString}'\n", FILE_APPEND);
        
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
        $topFontSize = 74;
        $bottomFontSize = 62;
        $topBoxBorder = 22;
        $bottomBoxBorder = 16;
        $topLine1Y = 125;
        $topLine2Y = 215;
        $bottomSafeMargin = 260;
        $highlightColor = '0xC99700';
        
        if ($topText !== '') {
            // Split text into two lines for separate drawtext filters (avoids Windows newline issues)
            $lines = $this->splitToTwoLines($topText, 28);
            $line1 = $lines[0] ?? '';
            $line2 = $lines[1] ?? '';
            
            // Add spaces at end of emoji line to extend white box for emoji
            // 4 spaces for 48px emoji + padding
            $emojiSpaces = '    ';  // 4 spaces for emoji area
            
            // Calculate emoji position based on text width (before adding spaces)
            // Estimate width using the larger configured font size.
            $emojiLine = ($line2 !== '') ? $line2 : $line1;
            $emojiLineY = ($line2 !== '') ? $topLine2Y : $topLine1Y;
            $textWidth = strlen($emojiLine) * 29; // Approximate text width
            // Emoji x = center of video + half of original text width - small offset to stay inside box
            $emojiXOffset = ($textWidth / 2) - 5;
            
            $topHighlightTarget = ($line2 !== '' && strlen($line2) >= strlen($line1)) ? 'line2' : 'line1';

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

                if ($topHighlightTarget === 'line1') {
                    $topLine1Segments = $this->splitInteriorHighlight($line1);
                    if ($topLine1Segments['highlight'] !== '') {
                        $line1Width = $this->estimateTextWidth($line1WithSpaces, $topFontSize);
                        $line1PrefixWidth = $this->estimateTextWidth($topLine1Segments['prefix'], $topFontSize);
                        $line1HighlightWidth = $this->estimateTextWidth($topLine1Segments['highlight'], $topFontSize);
                        $line1StartX = max(24, (int)round(($width - $line1Width) / 2));

                        $filters[] = "drawtext=textfile='{$line1Path}':fontfile='{$this->fontPath}':fontsize={$topFontSize}:fontcolor=white@0.0:box=1:boxcolor=white@0.95:boxborderw={$topBoxBorder}:x=(w-text_w)/2:y={$topLine1Y}";

                        if ($topLine1Segments['prefix'] !== '') {
                            $line1PrefixFile = $this->tempDir . '/line1_prefix_' . uniqid() . '.txt';
                            file_put_contents($line1PrefixFile, $topLine1Segments['prefix']);
                            $tempFiles[] = $line1PrefixFile;
                            $line1PrefixPath = str_replace('\\', '/', $line1PrefixFile);
                            if (PHP_OS_FAMILY === 'Windows') {
                                $line1PrefixPath = str_replace(':', '\\:', $line1PrefixPath);
                            }
                            $filters[] = "drawtext=textfile='{$line1PrefixPath}':fontfile='{$this->fontPath}':fontsize={$topFontSize}:fontcolor=black:x={$line1StartX}:y={$topLine1Y}";
                        }

                        $line1HighlightFile = $this->tempDir . '/line1_highlight_' . uniqid() . '.txt';
                        file_put_contents($line1HighlightFile, $topLine1Segments['highlight']);
                        $tempFiles[] = $line1HighlightFile;
                        $line1HighlightPath = str_replace('\\', '/', $line1HighlightFile);
                        if (PHP_OS_FAMILY === 'Windows') {
                            $line1HighlightPath = str_replace(':', '\\:', $line1HighlightPath);
                        }
                        $line1HighlightX = $line1StartX + $line1PrefixWidth;
                        $filters[] = "drawtext=textfile='{$line1HighlightPath}':fontfile='{$this->fontPath}':fontsize={$topFontSize}:fontcolor={$highlightColor}:x={$line1HighlightX}:y={$topLine1Y}";

                        if ($topLine1Segments['suffix'] !== '') {
                            $line1SuffixFile = $this->tempDir . '/line1_suffix_' . uniqid() . '.txt';
                            file_put_contents($line1SuffixFile, $topLine1Segments['suffix']);
                            $tempFiles[] = $line1SuffixFile;
                            $line1SuffixPath = str_replace('\\', '/', $line1SuffixFile);
                            if (PHP_OS_FAMILY === 'Windows') {
                                $line1SuffixPath = str_replace(':', '\\:', $line1SuffixPath);
                            }
                            $line1SuffixX = $line1HighlightX + $line1HighlightWidth;
                            $filters[] = "drawtext=textfile='{$line1SuffixPath}':fontfile='{$this->fontPath}':fontsize={$topFontSize}:fontcolor=black:x={$line1SuffixX}:y={$topLine1Y}";
                        }
                    } else {
                        $filters[] = "drawtext=textfile='{$line1Path}':fontfile='{$this->fontPath}':fontsize={$topFontSize}:fontcolor=black:box=1:boxcolor=white@0.95:boxborderw={$topBoxBorder}:x=(w-text_w)/2:y={$topLine1Y}";
                    }
                } else {
                    // First line - white box, black text, centered
                    $filters[] = "drawtext=textfile='{$line1Path}':fontfile='{$this->fontPath}':fontsize={$topFontSize}:fontcolor=black:box=1:boxcolor=white@0.95:boxborderw={$topBoxBorder}:x=(w-text_w)/2:y={$topLine1Y}";
                }
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

                if ($topHighlightTarget === 'line2') {
                    $topLine2Segments = $this->splitInteriorHighlight($line2);
                    if ($topLine2Segments['highlight'] !== '') {
                        $line2Width = $this->estimateTextWidth($line2WithSpaces, $topFontSize);
                        $line2PrefixWidth = $this->estimateTextWidth($topLine2Segments['prefix'], $topFontSize);
                        $line2HighlightWidth = $this->estimateTextWidth($topLine2Segments['highlight'], $topFontSize);
                        $line2StartX = max(24, (int)round(($width - $line2Width) / 2));

                        $filters[] = "drawtext=textfile='{$line2Path}':fontfile='{$this->fontPath}':fontsize={$topFontSize}:fontcolor=white@0.0:box=1:boxcolor=white@0.95:boxborderw={$topBoxBorder}:x=(w-text_w)/2:y={$topLine2Y}";

                        if ($topLine2Segments['prefix'] !== '') {
                            $line2PrefixFile = $this->tempDir . '/line2_prefix_' . uniqid() . '.txt';
                            file_put_contents($line2PrefixFile, $topLine2Segments['prefix']);
                            $tempFiles[] = $line2PrefixFile;
                            $line2PrefixPath = str_replace('\\', '/', $line2PrefixFile);
                            if (PHP_OS_FAMILY === 'Windows') {
                                $line2PrefixPath = str_replace(':', '\\:', $line2PrefixPath);
                            }
                            $filters[] = "drawtext=textfile='{$line2PrefixPath}':fontfile='{$this->fontPath}':fontsize={$topFontSize}:fontcolor=black:x={$line2StartX}:y={$topLine2Y}";
                        }

                        $line2HighlightFile = $this->tempDir . '/line2_highlight_' . uniqid() . '.txt';
                        file_put_contents($line2HighlightFile, $topLine2Segments['highlight']);
                        $tempFiles[] = $line2HighlightFile;
                        $line2HighlightPath = str_replace('\\', '/', $line2HighlightFile);
                        if (PHP_OS_FAMILY === 'Windows') {
                            $line2HighlightPath = str_replace(':', '\\:', $line2HighlightPath);
                        }
                        $line2HighlightX = $line2StartX + $line2PrefixWidth;
                        $filters[] = "drawtext=textfile='{$line2HighlightPath}':fontfile='{$this->fontPath}':fontsize={$topFontSize}:fontcolor={$highlightColor}:x={$line2HighlightX}:y={$topLine2Y}";

                        if ($topLine2Segments['suffix'] !== '') {
                            $line2SuffixFile = $this->tempDir . '/line2_suffix_' . uniqid() . '.txt';
                            file_put_contents($line2SuffixFile, $topLine2Segments['suffix']);
                            $tempFiles[] = $line2SuffixFile;
                            $line2SuffixPath = str_replace('\\', '/', $line2SuffixFile);
                            if (PHP_OS_FAMILY === 'Windows') {
                                $line2SuffixPath = str_replace(':', '\\:', $line2SuffixPath);
                            }
                            $line2SuffixX = $line2HighlightX + $line2HighlightWidth;
                            $filters[] = "drawtext=textfile='{$line2SuffixPath}':fontfile='{$this->fontPath}':fontsize={$topFontSize}:fontcolor=black:x={$line2SuffixX}:y={$topLine2Y}";
                        }
                    } else {
                        $filters[] = "drawtext=textfile='{$line2Path}':fontfile='{$this->fontPath}':fontsize={$topFontSize}:fontcolor=black:box=1:boxcolor=white@0.95:boxborderw={$topBoxBorder}:x=(w-text_w)/2:y={$topLine2Y}";
                    }
                } else {
                    // Second line - white box, black text, centered
                    $filters[] = "drawtext=textfile='{$line2Path}':fontfile='{$this->fontPath}':fontsize={$topFontSize}:fontcolor=black:box=1:boxcolor=white@0.95:boxborderw={$topBoxBorder}:x=(w-text_w)/2:y={$topLine2Y}";
                }
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

            $bottomSegments = $this->splitInteriorHighlight($bottomText);
            if ($bottomSegments['highlight'] !== '') {
                $estimatedFullWidth = $this->estimateTextWidth($bottomText, $bottomFontSize);
                $estimatedPrefixWidth = $this->estimateTextWidth($bottomSegments['prefix'], $bottomFontSize);
                $estimatedHighlightWidth = $this->estimateTextWidth($bottomSegments['highlight'], $bottomFontSize);
                $bottomStartX = max(24, (int)round(($width - $estimatedFullWidth) / 2));
                $bottomYExpr = "h-text_h-{$bottomSafeMargin}";

                // Draw the shared white box with invisible text, then paint colored segments on top.
                $filters[] = "drawtext=textfile='{$bottomTextFilePath}':fontfile='{$this->fontPath}':fontsize={$bottomFontSize}:fontcolor=white@0.0:box=1:boxcolor=white@0.92:boxborderw={$bottomBoxBorder}:x=(w-text_w)/2:y={$bottomYExpr}";

                $prefix = $bottomSegments['prefix'];
                if ($prefix !== '') {
                    $prefixFile = $this->tempDir . '/bottom_prefix_' . uniqid() . '.txt';
                    file_put_contents($prefixFile, $prefix);
                    $tempFiles[] = $prefixFile;
                    $prefixPath = str_replace('\\', '/', $prefixFile);
                    if (PHP_OS_FAMILY === 'Windows') {
                        $prefixPath = str_replace(':', '\\:', $prefixPath);
                    }
                    $filters[] = "drawtext=textfile='{$prefixPath}':fontfile='{$this->fontPath}':fontsize={$bottomFontSize}:fontcolor=black:x={$bottomStartX}:y={$bottomYExpr}";
                }

                $highlightFile = $this->tempDir . '/bottom_highlight_' . uniqid() . '.txt';
                file_put_contents($highlightFile, $bottomSegments['highlight']);
                $tempFiles[] = $highlightFile;
                $highlightPath = str_replace('\\', '/', $highlightFile);
                if (PHP_OS_FAMILY === 'Windows') {
                    $highlightPath = str_replace(':', '\\:', $highlightPath);
                }
                $highlightX = $bottomStartX + $estimatedPrefixWidth;
                $filters[] = "drawtext=textfile='{$highlightPath}':fontfile='{$this->fontPath}':fontsize={$bottomFontSize}:fontcolor={$highlightColor}:x={$highlightX}:y={$bottomYExpr}";

                $suffix = $bottomSegments['suffix'];
                if ($suffix !== '') {
                    $suffixFile = $this->tempDir . '/bottom_suffix_' . uniqid() . '.txt';
                    file_put_contents($suffixFile, $suffix);
                    $tempFiles[] = $suffixFile;
                    $suffixPath = str_replace('\\', '/', $suffixFile);
                    if (PHP_OS_FAMILY === 'Windows') {
                        $suffixPath = str_replace(':', '\\:', $suffixPath);
                    }
                    $suffixX = $highlightX + $estimatedHighlightWidth;
                    $filters[] = "drawtext=textfile='{$suffixPath}':fontfile='{$this->fontPath}':fontsize={$bottomFontSize}:fontcolor=black:x={$suffixX}:y={$bottomYExpr}";
                }

                file_put_contents($debugLog, "  Added highlighted bottom text with dark-yellow segment: {$bottomSegments['highlight']}\n", FILE_APPEND);
            } else {
                // Style: White box background, black bold text, centered (same as top)
                $filters[] = "drawtext=textfile='{$bottomTextFilePath}':fontfile='{$this->fontPath}':fontsize={$bottomFontSize}:fontcolor=black:box=1:boxcolor=white@0.92:boxborderw={$bottomBoxBorder}:x=(w-text_w)/2:y=h-text_h-{$bottomSafeMargin}";
                file_put_contents($debugLog, "  Added bottom text filter with file: {$bottomTextFilePath}\n", FILE_APPEND);
            }
        }

        if (abs($playbackSpeed - 1.0) > 0.0001) {
            $filters[] = 'setpts=PTS/' . $playbackSpeedString;
        }
        
        $filterString = implode(',', $filters);
        $audioFilter = null;
        if ($hasAudio && abs($playbackSpeed - 1.0) > 0.0001) {
            $audioFilter = implode(',', $this->buildAtempoFilterChain($playbackSpeed));
        }
        
        // Convert paths for Windows
        $inputPathSafe = str_replace('\\', '/', $inputPath);
        $outputPathSafe = str_replace('\\', '/', $outputPath);
        
        // Ensure output directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        // Keep output broadly compatible with hosted upload APIs.
        $videoEncodeArgs = '-c:v libx264 -preset fast -crf 23 -profile:v high -level 4.1 -pix_fmt yuv420p -movflags +faststart -r 30';
        $audioEncodeArgs = '-c:a aac -b:a 128k -ar 48000';
        
        // Build FFmpeg command - with colorful PNG emoji overlay
        if ($hasEmoji && isset($this->emojiXOffset)) {
            // Emoji PNG overlay for colorful display
            $emojiPngInput = str_replace('\\', '/', $emojiPng);
            $emojiY = $this->emojiY ?? 140;
            $emojiXOffset = $this->emojiXOffset ?? 100;
            
            // Filter complex: apply video filters, then overlay emoji PNG
            // Emoji size 48x48 to match font size, position at end of text line
            $command = sprintf(
                '"%s" -y -ss %s -t %d -i "%s" -i "%s" -filter_complex "[0:v]%s[text];[1:v]scale=48:48[emoji];[text][emoji]overlay=x=(main_w/2)+%d:y=%d" %s %s %s "%s" 2>&1',
                $this->ffmpegPath,
                $startTime,
                $duration,
                $inputPathSafe,
                $emojiPngInput,
                $filterString,
                (int)$emojiXOffset,
                (int)$emojiY,
                $audioFilter !== null ? '-af "' . $audioFilter . '"' : '',
                $videoEncodeArgs,
                $audioEncodeArgs,
                $outputPathSafe
            );
            file_put_contents($debugLog, "  COLORFUL EMOJI: {$emojiPngInput} at x=w/2+{$emojiXOffset}, y={$emojiY}\n", FILE_APPEND);
        } else {
            // Standard command without emoji
            $command = sprintf(
                '"%s" -y -ss %s -t %d -i "%s" -vf "%s" %s %s %s "%s" 2>&1',
                $this->ffmpegPath,
                $startTime,
                $duration,
                $inputPathSafe,
                $filterString,
                $audioFilter !== null ? '-af "' . $audioFilter . '"' : '',
                $videoEncodeArgs,
                $audioEncodeArgs,
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
            $preparedSubtitlesPath = $this->prepareSegmentSubtitles($subtitlesPath, (float)$startTime, (int)$duration);
            $tempOutput = $this->tempDir . '/' . uniqid('temp_') . '.mp4';

            if (!@rename($outputPath, $tempOutput)) {
                return [
                    'success' => false,
                    'error' => 'Failed to prepare output for subtitle overlay'
                ];
            }

            $subResult = $this->addSubtitles($tempOutput, $preparedSubtitlesPath, $outputPath);

            if ($preparedSubtitlesPath !== $subtitlesPath && file_exists($preparedSubtitlesPath)) {
                @unlink($preparedSubtitlesPath);
            }

            if ($subResult) {
                @unlink($tempOutput);
            } else {
                @unlink($outputPath);
                @rename($tempOutput, $outputPath);
            }
        }
        
        $outputInfo = $this->getVideoInfo($outputPath);
        $outputDuration = isset($outputInfo['duration']) ? (float)$outputInfo['duration'] : (float)$duration;

        return [
            'success' => true,
            'output' => $outputPath,
            'duration' => $outputDuration,
            'width' => $width,
            'height' => $height
        ];
    }

    private function buildAtempoFilterChain(float $playbackSpeed): array {
        $factors = $this->factorTempoForAtempo($playbackSpeed);
        return array_map(static function ($factor): string {
            return 'atempo=' . rtrim(rtrim(number_format((float)$factor, 5, '.', ''), '0'), '.');
        }, $factors);
    }

    private function factorTempoForAtempo(float $tempo): array {
        if ($tempo >= 0.5 && $tempo <= 2.0) {
            return [$tempo];
        }

        $root = sqrt($tempo);
        return array_merge(
            $this->factorTempoForAtempo($root),
            $this->factorTempoForAtempo($root)
        );
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

    private function prepareSegmentSubtitles($subtitlesPath, float $startTime, int $segmentDuration) {
        if ($startTime <= 0) {
            return $subtitlesPath;
        }

        if (strtolower(pathinfo($subtitlesPath, PATHINFO_EXTENSION)) !== 'ass') {
            return $subtitlesPath;
        }

        $lines = @file($subtitlesPath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || empty($lines)) {
            return $subtitlesPath;
        }

        $segmentEnd = $startTime + max(1, $segmentDuration);
        $shifted = [];

        foreach ($lines as $line) {
            if (strpos($line, 'Dialogue:') !== 0) {
                $shifted[] = $line;
                continue;
            }

            $parts = explode(',', $line, 10);
            if (count($parts) < 10) {
                $shifted[] = $line;
                continue;
            }

            $lineStart = $this->parseAssTime($parts[1]);
            $lineEnd = $this->parseAssTime($parts[2]);
            if ($lineStart === null || $lineEnd === null) {
                continue;
            }

            if ($lineEnd <= $startTime || $lineStart >= $segmentEnd) {
                continue;
            }

            $shiftedStart = max(0.0, $lineStart - $startTime);
            $shiftedEnd = min((float)$segmentDuration, $lineEnd - $startTime);
            if ($shiftedEnd <= $shiftedStart) {
                continue;
            }

            $parts[1] = $this->formatAssTime($shiftedStart);
            $parts[2] = $this->formatAssTime($shiftedEnd);
            $shifted[] = implode(',', $parts);
        }

        $tempPath = $this->tempDir . '/' . uniqid('segment_subs_') . '.ass';
        @file_put_contents($tempPath, implode(PHP_EOL, $shifted));

        return file_exists($tempPath) ? $tempPath : $subtitlesPath;
    }

    private function parseAssTime($value) {
        $value = trim((string)$value);
        if (!preg_match('/^(\d+):(\d{2}):(\d{2})\.(\d{2})$/', $value, $m)) {
            return null;
        }

        return (((int)$m[1] * 3600) + ((int)$m[2] * 60) + (int)$m[3]) + (((int)$m[4]) / 100);
    }

    private function formatAssTime(float $seconds): string {
        $seconds = max(0.0, $seconds);
        $hours = (int)floor($seconds / 3600);
        $seconds -= $hours * 3600;
        $minutes = (int)floor($seconds / 60);
        $seconds -= $minutes * 60;
        $wholeSeconds = (int)floor($seconds);
        $centiseconds = (int)round(($seconds - $wholeSeconds) * 100);

        if ($centiseconds >= 100) {
            $wholeSeconds++;
            $centiseconds = 0;
        }
        if ($wholeSeconds >= 60) {
            $minutes++;
            $wholeSeconds -= 60;
        }
        if ($minutes >= 60) {
            $hours++;
            $minutes -= 60;
        }

        return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $wholeSeconds, $centiseconds);
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

    private function splitInteriorHighlight($text) {
        $text = (string)$text;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars) || count($chars) < 4) {
            return ['prefix' => '', 'highlight' => '', 'suffix' => ''];
        }

        $candidates = [];
        $lastIndex = count($chars) - 1;

        for ($i = 1; $i < $lastIndex; $i++) {
            $char = (string)$chars[$i];
            if (trim($char) === '') {
                continue;
            }
            if (!preg_match('/[\p{L}\p{N}]/u', $char)) {
                continue;
            }
            $candidates[] = $i;
        }

        if (empty($candidates)) {
            return ['prefix' => '', 'highlight' => '', 'suffix' => ''];
        }

        $hash = abs(crc32($text));
        $startIndex = (int)$candidates[$hash % count($candidates)];
        $highlightLength = 1;

        if (($hash % 2) === 0 && $startIndex < ($lastIndex - 1)) {
            $nextChar = (string)$chars[$startIndex + 1];
            if (trim($nextChar) !== '' && preg_match('/[\p{L}\p{N}]/u', $nextChar) && ($startIndex + 1) < $lastIndex) {
                $highlightLength = 2;
            }
        }

        $prefix = implode('', array_slice($chars, 0, $startIndex));
        $highlight = implode('', array_slice($chars, $startIndex, $highlightLength));
        $suffix = implode('', array_slice($chars, $startIndex + $highlightLength));

        return [
            'prefix' => $prefix,
            'highlight' => $highlight,
            'suffix' => $suffix,
        ];
    }

    private function estimateTextWidth($text, $fontSize) {
        $text = (string)$text;
        if ($text === '') {
            return 0;
        }

        $charCount = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        $spaceCount = substr_count($text, ' ');
        $punctCount = preg_match_all('/[.:\/_-]/', $text);

        return (int)round(($charCount * $fontSize * 0.52) + ($spaceCount * $fontSize * 0.16) + ($punctCount * $fontSize * 0.05));
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
        return $this->testCommand($this->ffmpegPath);
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
