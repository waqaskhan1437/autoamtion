<?php
/**
 * Emoji Helper - Maps emoji characters to Twemoji PNG files
 * Twemoji PNG files should be in: C:/VideoWorkflow/emojis/72x72/
 * Download from: https://github.com/twitter/twemoji/releases
 * 
 * IMPORTANT: Only emojis with actual PNG files in the folder will be used.
 * This prevents black & white fallback - only colorful Twemoji PNGs are shown.
 */

class EmojiHelper {
    
    private $emojiDir;
    private $verifiedEmojis = [];
    
    private $emojiMap = [
        '😂' => '1f602',
        '🥰' => '1f970',
        '❤️' => '2764',
        '😍' => '1f60d',
        '🔥' => '1f525',
        '✨' => '2728',
        '💯' => '1f4af',
        '🎉' => '1f389',
        '💕' => '1f495',
        '😊' => '1f60a',
        '🙌' => '1f64c',
        '💪' => '1f4aa',
        '🌟' => '1f31f',
        '😎' => '1f60e',
        '💖' => '1f496',
        '🎊' => '1f38a',
        '👏' => '1f44f',
        '💝' => '1f49d',
        '🥳' => '1f973',
        '😘' => '1f618',
        '❤' => '2764',
        '💗' => '1f497',
        '💓' => '1f493',
        '💞' => '1f49e',
        '💘' => '1f498',
        '🤗' => '1f917',
        '😇' => '1f607',
        '🥺' => '1f97a',
        '😭' => '1f62d',
        '🤩' => '1f929',
        '💥' => '1f4a5',
        '😳' => '1f633',
        '👀' => '1f440',
        '🤯' => '1f92f',
        '🎁' => '1f381',
        '🎂' => '1f382',
        '🎈' => '1f388',
        '🌹' => '1f339',
        '💐' => '1f490',
        '🙏' => '1f64f',
        '😢' => '1f622',
        '🥹' => '1f979',
        '💫' => '1f4ab',
        '⭐' => '2b50',
        '🌈' => '1f308',
        '💎' => '1f48e',
        '👑' => '1f451',
        '🏆' => '1f3c6',
        '🎯' => '1f3af',
        '🚀' => '1f680',
        '💡' => '1f4a1',
        '🎵' => '1f3b5',
        '🎶' => '1f3b6',
        '🤝' => '1f91d',
        '👍' => '1f44d',
        '✅' => '2705',
        '❌' => '274c',
        '⚡' => '26a1',
        '🌸' => '1f338',
        '🦋' => '1f98b',
        '🍀' => '1f340',
        '💜' => '1f49c',
        '💙' => '1f499',
        '💚' => '1f49a',
        '🧡' => '1f9e1',
        '🤍' => '1f90d',
        '🖤' => '1f5a4',
        '💛' => '1f49b',
    ];
    
    private $availableEmojis = [];
    
    public function __construct($emojiDir = null) {
        if ($emojiDir) {
            $this->emojiDir = $emojiDir;
        } else {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $this->emojiDir = 'C:/VideoWorkflow/emojis/72x72';
            } else {
                $this->emojiDir = getenv('HOME') . '/VideoWorkflow/emojis/72x72';
            }
        }
        $this->buildVerifiedList();
    }
    
    /**
     * Scan emoji folder and build list of ONLY emojis that have actual PNG files
     * This prevents black & white fallback - only colorful PNGs will be used
     */
    private function buildVerifiedList() {
        $this->verifiedEmojis = [];
        $this->availableEmojis = [];
        
        if (!is_dir($this->emojiDir)) {
            return;
        }
        
        foreach ($this->emojiMap as $emoji => $code) {
            $pngPath = $this->emojiDir . '/' . $code . '.png';
            if (file_exists($pngPath)) {
                $this->verifiedEmojis[$emoji] = $pngPath;
                $this->availableEmojis[] = $emoji;
                continue;
            }
            $pngPath = $this->emojiDir . '/' . strtoupper($code) . '.png';
            if (file_exists($pngPath)) {
                $this->verifiedEmojis[$emoji] = $pngPath;
                $this->availableEmojis[] = $emoji;
            }
        }
    }
    
    /**
     * Get random emoji - ONLY from verified PNGs in folder
     * Returns null if no PNGs available (prevents black & white)
     */
    public function getRandomEmoji() {
        if (empty($this->availableEmojis)) {
            return null;
        }
        return $this->availableEmojis[array_rand($this->availableEmojis)];
    }
    
    /**
     * Get PNG path for emoji character
     * Returns null if PNG doesn't exist (prevents black & white)
     */
    public function getEmojiPngPath($emoji) {
        if (isset($this->verifiedEmojis[$emoji])) {
            return $this->verifiedEmojis[$emoji];
        }
        
        $code = $this->emojiMap[$emoji] ?? null;
        if (!$code) {
            return null;
        }
        
        $pngPath = $this->emojiDir . '/' . $code . '.png';
        if (file_exists($pngPath)) {
            $this->verifiedEmojis[$emoji] = $pngPath;
            return $pngPath;
        }
        
        $pngPath = $this->emojiDir . '/' . strtoupper($code) . '.png';
        if (file_exists($pngPath)) {
            $this->verifiedEmojis[$emoji] = $pngPath;
            return $pngPath;
        }
        
        return null;
    }
    
    /**
     * Check if a specific emoji has a PNG file available
     */
    public function hasEmojiPng($emoji) {
        return isset($this->verifiedEmojis[$emoji]) || $this->getEmojiPngPath($emoji) !== null;
    }
    
    /**
     * Get list of all emojis that have verified PNGs
     */
    public function getAvailableEmojis() {
        return $this->availableEmojis;
    }
    
    /**
     * Get count of available emoji PNGs
     */
    public function getAvailableCount() {
        return count($this->availableEmojis);
    }
    
    /**
     * Check if emoji directory exists and has files
     */
    public function isSetup() {
        return !empty($this->availableEmojis);
    }
    
    /**
     * Get setup instructions
     */
    public function getSetupInstructions() {
        return [
            'step1' => 'Download Twemoji from: https://github.com/twitter/twemoji/releases',
            'step2' => 'Extract the ZIP file',
            'step3' => 'Copy the 72x72 folder to: ' . $this->emojiDir,
            'step4' => 'Verify PNG files exist (e.g., 1f602.png for 😂)',
            'emojiDir' => $this->emojiDir,
            'isSetup' => $this->isSetup(),
            'availableCount' => $this->getAvailableCount()
        ];
    }
    
    /**
     * Get emoji codepoint
     */
    public function getEmojiCode($emoji) {
        return $this->emojiMap[$emoji] ?? null;
    }
}
