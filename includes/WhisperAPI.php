<?php
/**
 * OpenAI Whisper API Integration
 * Transcribes audio/video files to text for captions
 */

class WhisperAPI {
    private $apiKey;
    private $apiUrl = 'https://api.openai.com/v1/audio/transcriptions';
    
    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }
    
    /**
     * Transcribe audio/video file to text with timestamps
     * @param string $filePath Path to audio/video file
     * @param string $language Language code (e.g., 'en', 'ur', 'hi')
     * @return array Transcription with word-level timestamps
     */
    public function transcribe($filePath, $language = 'en') {
        if (!file_exists($filePath)) {
            return ['error' => 'File not found: ' . $filePath];
        }
        
        // Extract audio from video if needed
        $audioPath = $this->extractAudio($filePath);
        
        $curl = curl_init();
        
        $postFields = [
            'file' => new CURLFile($audioPath, 'audio/mp3', basename($audioPath)),
            'model' => 'whisper-1',
            'language' => $language,
            'response_format' => 'verbose_json',
            'timestamp_granularities' => ['word', 'segment']
        ];
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_TIMEOUT => 300 // 5 minutes for long videos
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        // Clean up temp audio file
        if ($audioPath !== $filePath && file_exists($audioPath)) {
            unlink($audioPath);
        }
        
        if ($error) {
            return ['error' => 'API Error: ' . $error];
        }
        
        if ($httpCode !== 200) {
            return ['error' => 'HTTP Error: ' . $httpCode, 'response' => $response];
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Extract audio from video file using FFmpeg
     */
    private function extractAudio($videoPath) {
        $ext = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));
        
        // If already audio, return as-is
        if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'flac'])) {
            return $videoPath;
        }
        
        // Extract audio to temp file
        $audioPath = sys_get_temp_dir() . '/' . uniqid('audio_') . '.mp3';
        
        $command = sprintf(
            'ffmpeg -i %s -vn -acodec libmp3lame -ab 128k -ar 44100 %s -y 2>&1',
            escapeshellarg($videoPath),
            escapeshellarg($audioPath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0 || !file_exists($audioPath)) {
            // Return original file if extraction fails
            return $videoPath;
        }
        
        return $audioPath;
    }
    
    /**
     * Convert transcription to SRT subtitle format
     */
    public function toSRT($transcription) {
        if (isset($transcription['error'])) {
            return '';
        }
        
        $srt = '';
        $segments = $transcription['segments'] ?? [];
        
        foreach ($segments as $index => $segment) {
            $srt .= ($index + 1) . "\n";
            $srt .= $this->formatTimestamp($segment['start']) . ' --> ' . $this->formatTimestamp($segment['end']) . "\n";
            $srt .= trim($segment['text']) . "\n\n";
        }
        
        return $srt;
    }
    
    /**
     * Convert transcription to ASS subtitle format (for FFmpeg overlay)
     */
    public function toASS($transcription, $style = []) {
        if (isset($transcription['error'])) {
            return '';
        }
        
        $fontName = $style['font'] ?? 'Arial';
        $fontSize = $style['fontSize'] ?? 24;
        $primaryColor = $style['primaryColor'] ?? '&H00FFFFFF'; // White
        $outlineColor = $style['outlineColor'] ?? '&H00000000'; // Black
        $outline = $style['outline'] ?? 2;
        
        $ass = "[Script Info]\n";
        $ass .= "Title: Auto-generated Captions\n";
        $ass .= "ScriptType: v4.00+\n";
        $ass .= "PlayResX: 1080\n";
        $ass .= "PlayResY: 1920\n\n";
        
        $ass .= "[V4+ Styles]\n";
        $ass .= "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $ass .= "Style: Default,{$fontName},{$fontSize},{$primaryColor},&H000000FF,{$outlineColor},&H80000000,1,0,0,0,100,100,0,0,1,{$outline},1,2,50,50,100,1\n\n";
        
        $ass .= "[Events]\n";
        $ass .= "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        
        $segments = $transcription['segments'] ?? [];
        foreach ($segments as $segment) {
            $start = $this->formatASSTime($segment['start']);
            $end = $this->formatASSTime($segment['end']);
            $text = str_replace("\n", "\\N", trim($segment['text']));
            $ass .= "Dialogue: 0,{$start},{$end},Default,,0,0,0,,{$text}\n";
        }
        
        return $ass;
    }
    
    private function formatTimestamp($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        $ms = ($seconds - floor($seconds)) * 1000;
        
        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, floor($secs), $ms);
    }
    
    private function formatASSTime($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        $cs = ($seconds - floor($seconds)) * 100;
        
        return sprintf('%d:%02d:%05.2f', $hours, $minutes, $secs + ($cs / 100));
    }
}
?>
