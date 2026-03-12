<?php
/**
 * PrankWish social content generator.
 *
 * Generates 20 occasion packs x 5 human-style variants = 100 rotating
 * title/description/keyword/hashtag bundles for PostForMe social posting.
 */

class PrankWishSocialContent
{
    private ?PDO $pdo;
    private string $websiteUrl = 'https://prankwish.com';
    private string $brandName = 'PrankWish.com';
    private int $variantsPerOccasion = 5;
    private array $platformConfigs = [];
    private array $occasionCatalog = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
        $this->platformConfigs = $this->buildPlatformConfigs();
        $this->occasionCatalog = $this->buildOccasionCatalog();
    }

    public function getTotalCycles(): int
    {
        return count($this->occasionCatalog) * $this->variantsPerOccasion;
    }

    public function getNextPostPackage(
        int $automationId,
        string $videoTitle = '',
        ?string $forcedOccasion = null,
        ?string $videoFilename = null
    ): array {
        $bundle = $this->getNextContentBundle($automationId, $videoTitle, $forcedOccasion);
        if (empty($bundle['success'])) {
            return $bundle;
        }

        $this->logUsage($automationId, $bundle, $videoFilename ?: $videoTitle);

        return [
            'success' => true,
            'cycle' => $bundle['cycle'],
            'variant' => $bundle['variant'],
            'occasion_key' => $bundle['occasion_key'],
            'occasion_name' => $bundle['occasion_name'],
            'primary_keyword' => $bundle['primary_keyword'],
            'caption' => $this->buildDefaultCaption($bundle),
            'platform_overrides' => $this->buildPlatformOverridesFromBundle($bundle),
            'platforms' => $bundle['platforms'],
            'source' => $bundle['source'],
        ];
    }

    public function getNextContentBundle(
        int $automationId,
        string $videoTitle = '',
        ?string $forcedOccasion = null
    ): array {
        $cycle = $this->getNextCycleNumber($automationId);
        return $this->getContentBundleByCycle($cycle, $videoTitle, $forcedOccasion);
    }

    public function getContentBundleByCycle(
        int $cycle,
        string $videoTitle = '',
        ?string $forcedOccasion = null
    ): array {
        if (empty($this->occasionCatalog)) {
            return ['success' => false, 'error' => 'PrankWish occasion catalog is empty.'];
        }

        $totalCycles = $this->getTotalCycles();
        $cycle = max(1, min($totalCycles, $cycle));
        $occasionCount = count($this->occasionCatalog);
        $variant = intdiv($cycle - 1, $occasionCount) + 1;
        $occasionIndex = ($cycle - 1) % $occasionCount;
        $occasion = $this->occasionCatalog[$occasionIndex];

        if ($forcedOccasion !== null) {
            $forced = $this->findOccasion($forcedOccasion);
            if ($forced !== null) {
                $occasion = $forced;
            }
        }

        $platforms = [];
        foreach (array_keys($this->platformConfigs) as $platform) {
            $platforms[$platform] = $this->buildPlatformContent($platform, $occasion, $variant, $cycle, $videoTitle);
        }

        return [
            'success' => true,
            'cycle' => $cycle,
            'variant' => $variant,
            'occasion_key' => $occasion['key'],
            'occasion_name' => $occasion['name'],
            'primary_keyword' => $occasion['search_phrases'][$variant - 1] ?? ($occasion['keywords'][0] ?? ''),
            'platforms' => $platforms,
            'source' => 'prankwish_generated',
        ];
    }

    public function getContent(string $platform, ?string $occasion = null, ?string $videoTitle = null): array
    {
        $occasionData = $occasion !== null
            ? $this->findOccasion($occasion)
            : $this->detectOccasionObject((string) $videoTitle);

        if ($occasionData === null) {
            $occasionData = $this->findOccasion('birthday_family');
        }

        $bundle = $this->getContentBundleByCycle(1, (string) $videoTitle, $occasionData['key'] ?? null);
        if (empty($bundle['success'])) {
            return $bundle;
        }

        $platform = $this->normalizePlatform($platform);
        return $bundle['platforms'][$platform] ?? $bundle['platforms']['youtube'];
    }

    public function getAllPlatformsContent(?string $occasion = null, ?string $videoTitle = null): array
    {
        $occasionData = $occasion !== null
            ? $this->findOccasion($occasion)
            : $this->detectOccasionObject((string) $videoTitle);

        if ($occasionData === null) {
            $occasionData = $this->findOccasion('birthday_family');
        }

        $bundle = $this->getContentBundleByCycle(1, (string) $videoTitle, $occasionData['key'] ?? null);
        if (empty($bundle['success'])) {
            return [];
        }

        return $bundle['platforms'];
    }

    public function getAvailableOccasions(): array
    {
        $result = [];
        foreach ($this->occasionCatalog as $occasion) {
            $result[$occasion['key']] = $occasion['name'];
        }
        return $result;
    }

    public function detectOccasion(string $text): ?string
    {
        $occasion = $this->detectOccasionObject($text);
        return $occasion['key'] ?? null;
    }

    public function saveContent(array $data): bool
    {
        if (!$this->pdo) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO prankwish_social_content
                    (occasion_key, occasion_name, platform, title, description, hashtags, call_to_action)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    occasion_name = VALUES(occasion_name),
                    title = VALUES(title),
                    description = VALUES(description),
                    hashtags = VALUES(hashtags),
                    call_to_action = VALUES(call_to_action)
            ");

            $stmt->execute([
                (string) ($data['occasion_key'] ?? ''),
                (string) ($data['occasion_name'] ?? ''),
                $this->normalizePlatform((string) ($data['platform'] ?? 'youtube')),
                (string) ($data['title'] ?? ''),
                (string) ($data['description'] ?? ''),
                is_array($data['hashtags'] ?? null)
                    ? implode(' ', $data['hashtags'])
                    : (string) ($data['hashtags'] ?? ''),
                (string) ($data['call_to_action'] ?? ('Create yours at ' . $this->websiteUrl . '.')),
            ]);

            return true;
        } catch (Exception $e) {
            error_log('PrankWishSocialContent save failed: ' . $e->getMessage());
            return false;
        }
    }

    public function logUsage(int $automationId, array $bundle, ?string $videoFilename = null): bool
    {
        if (!$this->pdo) {
            return false;
        }

        $message = sprintf(
            'Cycle %d | Occasion=%s | Variant=%d | Keyword=%s',
            (int) ($bundle['cycle'] ?? 1),
            (string) ($bundle['occasion_key'] ?? 'unknown'),
            (int) ($bundle['variant'] ?? 1),
            (string) ($bundle['primary_keyword'] ?? '')
        );

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO automation_logs (automation_id, action, status, message, video_id, platform)
                VALUES (?, 'prankwish_social_selected', 'success', ?, ?, 'postforme')
            ");
            $stmt->execute([$automationId, $message, $videoFilename]);
            return true;
        } catch (Exception $e) {
            error_log('PrankWishSocialContent logUsage failed: ' . $e->getMessage());
            return false;
        }
    }

    private function buildDefaultCaption(array $bundle): string
    {
        $platforms = $bundle['platforms'] ?? [];
        $preferredOrder = ['instagram', 'tiktok', 'facebook', 'youtube', 'twitter'];

        foreach ($preferredOrder as $platform) {
            if (!empty($platforms[$platform]['caption'])) {
                return (string) $platforms[$platform]['caption'];
            }
        }

        return (string) ($bundle['primary_keyword'] ?? $this->brandName);
    }

    private function buildPlatformOverridesFromBundle(array $bundle): array
    {
        $platforms = $bundle['platforms'] ?? [];
        $overrides = [];

        if (!empty($platforms['youtube'])) {
            $youtube = $platforms['youtube'];
            $overrides['youtube'] = [
                'title' => $youtube['title'],
                'description' => $youtube['caption'],
                'tags' => $youtube['tags'],
                'privacy' => 'public',
                'shorts' => true,
            ];
        }

        if (!empty($platforms['tiktok'])) {
            $tiktok = $platforms['tiktok'];
            $overrides['tiktok'] = [
                'caption' => $tiktok['caption'],
                'allow_comments' => true,
                'allow_duet' => true,
                'allow_stitch' => true,
            ];
        }

        if (!empty($platforms['instagram'])) {
            $instagram = $platforms['instagram'];
            $overrides['instagram'] = [
                'caption' => $instagram['caption'],
                'share_to_feed' => true,
            ];
        }

        if (!empty($platforms['facebook'])) {
            $facebook = $platforms['facebook'];
            $overrides['facebook'] = [
                'caption' => $facebook['caption'],
                'description' => $facebook['caption'],
            ];
        }

        if (!empty($platforms['twitter'])) {
            $twitter = $platforms['twitter'];
            $twitterOverride = [
                'caption' => $twitter['caption'],
            ];
            $overrides['twitter'] = $twitterOverride;
            $overrides['x'] = $twitterOverride;
        }

        if (!empty($platforms['threads'])) {
            $threads = $platforms['threads'];
            $overrides['threads'] = [
                'caption' => $threads['caption'],
            ];
        }

        if (!empty($platforms['linkedin'])) {
            $linkedin = $platforms['linkedin'];
            $overrides['linkedin'] = [
                'caption' => $linkedin['caption'],
                'title' => $linkedin['title'],
            ];
        }

        if (!empty($platforms['pinterest'])) {
            $pinterest = $platforms['pinterest'];
            $overrides['pinterest'] = [
                'title' => $pinterest['title'],
                'description' => $pinterest['caption'],
                'link' => $this->websiteUrl,
            ];
        }

        if (!empty($platforms['bluesky'])) {
            $bluesky = $platforms['bluesky'];
            $overrides['bluesky'] = [
                'caption' => $bluesky['caption'],
            ];
        }

        return $overrides;
    }

    private function getNextCycleNumber(int $automationId): int
    {
        if (!$this->pdo) {
            return 1;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT message
                FROM automation_logs
                WHERE automation_id = ? AND action = 'prankwish_social_selected'
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$automationId]);
            $message = (string) $stmt->fetchColumn();

            if ($message !== '' && preg_match('/Cycle\s+(\d+)/i', $message, $matches)) {
                $next = ((int) $matches[1]) + 1;
                $total = $this->getTotalCycles();
                return $next > $total ? 1 : $next;
            }
        } catch (Exception $e) {
            error_log('PrankWishSocialContent getNextCycleNumber failed: ' . $e->getMessage());
        }

        return 1;
    }

    private function buildPlatformContent(
        string $platform,
        array $occasion,
        int $variant,
        int $cycle,
        string $videoTitle
    ): array {
        $platform = $this->normalizePlatform($platform);
        $config = $this->platformConfigs[$platform] ?? $this->platformConfigs['instagram'];
        $keywords = $this->buildKeywords($occasion, $platform, $variant);
        $hashtags = $this->buildHashtags($occasion, $platform, $variant, (int) ($config['hashtag_limit'] ?? 6));
        $tags = $this->buildTags($occasion, $keywords, (int) ($config['tag_limit'] ?? 10));
        $title = $this->buildTitle($platform, $occasion, $variant, $cycle, $videoTitle);
        $description = $this->buildDescription($platform, $occasion, $variant, $cycle, $videoTitle);
        $hashtagString = implode(' ', $hashtags);
        $caption = $description;

        if ($hashtagString !== '') {
            $caption .= "\n\n" . $hashtagString;
        }

        return [
            'platform' => $platform,
            'occasion_key' => $occasion['key'],
            'occasion_name' => $occasion['name'],
            'cycle' => $cycle,
            'variant' => $variant,
            'title' => $title,
            'description' => $description,
            'caption' => $this->smartTrim($caption, (int) ($config['caption_limit'] ?? 2200)),
            'call_to_action' => $this->buildCallToAction($platform),
            'keywords' => $keywords,
            'hashtags' => $hashtags,
            'tags' => $tags,
            'primary_keyword' => $occasion['search_phrases'][$variant - 1] ?? ($keywords[0] ?? ''),
        ];
    }

    private function buildTitle(
        string $platform,
        array $occasion,
        int $variant,
        int $cycle,
        string $videoTitle
    ): string {
        $config = $this->platformConfigs[$platform] ?? $this->platformConfigs['youtube'];
        $primaryPhrase = $occasion['search_phrases'][$variant - 1] ?? ($occasion['keywords'][0] ?? $occasion['name']);
        $primaryTitle = $this->titleCase($primaryPhrase);
        $brand = $this->brandName;
        $seoTemplates = [
            $primaryTitle . ' | ' . $brand,
            'Need a ' . $primaryPhrase . '? ' . $brand . ' made this',
            $primaryTitle . ' idea that feels personal | ' . $brand,
            'Funny ' . $primaryPhrase . ' video by ' . $brand,
            $primaryTitle . ' surprise from ' . $brand,
        ];

        $casualTemplates = [
            'A funny ' . $primaryPhrase . ' from ' . $brand,
            'Not a boring card: ' . $primaryTitle . ' | ' . $brand,
            $primaryTitle . ', but a bit messier and funnier | ' . $brand,
            'If you need a ' . $primaryPhrase . ', try this | ' . $brand,
            $primaryTitle . ' with a pranky twist | ' . $brand,
        ];

        $shortTemplates = [
            $primaryTitle . ' | ' . $brand,
            'Funny ' . $primaryPhrase . ' | ' . $brand,
            'Quick ' . $primaryPhrase . ' idea | ' . $brand,
            $primaryTitle . ' video | ' . $brand,
            $brand . ': ' . $primaryTitle,
        ];

        $professionalTemplates = [
            $primaryTitle . ' campaign idea | ' . $brand,
            'Custom ' . $primaryPhrase . ' from ' . $brand,
            $brand . ' video idea for ' . $primaryPhrase,
            'Personalized ' . $primaryPhrase . ' concept | ' . $brand,
            $primaryTitle . ' creative example | ' . $brand,
        ];

        if ($platform === 'linkedin') {
            $templates = $professionalTemplates;
        } elseif ($platform === 'twitter' || $platform === 'bluesky') {
            $templates = $shortTemplates;
        } elseif ($platform === 'tiktok' || $platform === 'instagram' || $platform === 'threads') {
            $templates = $casualTemplates;
        } else {
            $templates = $seoTemplates;
        }

        $templateIndex = $this->seedIndex($platform . '|title|' . $occasion['key'] . '|' . $variant . '|' . $cycle, count($templates));
        $title = $templates[$templateIndex];

        return $this->smartTrim($title, (int) ($config['title_limit'] ?? 100));
    }

    private function buildDescription(
        string $platform,
        array $occasion,
        int $variant,
        int $cycle,
        string $videoTitle
    ): string {
        $config = $this->platformConfigs[$platform] ?? $this->platformConfigs['instagram'];
        $primaryPhrase = $occasion['search_phrases'][$variant - 1] ?? ($occasion['keywords'][0] ?? $occasion['name']);
        $secondary = $occasion['secondary_phrases'] ?? [];
        $supportOne = $secondary[$this->seedIndex($platform . '|support1|' . $occasion['key'] . '|' . $variant, count($secondary))] ?? $primaryPhrase;
        $supportTwo = $secondary[$this->seedIndex($platform . '|support2|' . $occasion['key'] . '|' . ($variant + 2), count($secondary))] ?? $occasion['name'];
        $descriptor = (string) ($occasion['descriptor'] ?? 'real people and real inside jokes');
        $longOpeners = [
            'If you are searching for a ' . $primaryPhrase . ', this is the kind of custom video people actually send.',
            'This one sits right in the lane of ' . $primaryPhrase . ', but it feels more personal than a plain text wish.',
            'Somebody out there is looking for a ' . $primaryPhrase . ', and this is exactly the sort of rough, human video that works.',
            'When a card feels too flat, a ' . $primaryPhrase . ' like this lands better.',
            'This is for people who want a ' . $primaryPhrase . ' that sounds like a real person made it.',
        ];

        $shortOpeners = [
            'A rough little ' . $primaryPhrase . ' idea from ' . $this->brandName . '.',
            'Funny, slightly chaotic, and still personal: ' . $primaryPhrase . '.',
            'This is what a ' . $primaryPhrase . ' looks like when it is not polished to death.',
            'If you need a ' . $primaryPhrase . ', this is a solid start.',
            'A human-feeling ' . $primaryPhrase . ' from ' . $this->brandName . '.',
        ];

        $middleLines = [
            $this->brandName . ' turns photos, names, and inside jokes into custom videos for ' . $descriptor . '.',
            'We make pranky, heartfelt, and slightly mischievous video gifts for ' . $descriptor . '.',
            'PrankWish keeps it personal, funny, and a bit imperfect in the best way for ' . $descriptor . '.',
            'This is the kind of custom video gift people use when they want family and friends to actually react.',
            'It works well when you want a message that feels closer to a real conversation than a template.',
        ];

        $discoveryLines = [
            'People usually find us while searching for ' . $primaryPhrase . ', ' . $supportOne . ', or ' . $supportTwo . '.',
            'Common searches around this one include ' . $primaryPhrase . ', ' . $supportOne . ', and ' . $supportTwo . '.',
            'Search intent here is simple: ' . $primaryPhrase . ', ' . $supportOne . ', and ' . $supportTwo . '.',
            'This also fits people looking up ' . $supportOne . ' or ' . $supportTwo . '.',
            'The same kind of audience often searches ' . $primaryPhrase . ' and ' . $supportOne . ' before landing here.',
        ];

        $relationLines = [
            'People order these for mother, father, brother, sister, friend, girlfriend, boyfriend, family, and the occasional roast friend too.',
            'Good for family and friends searches as well, especially brother, sister, mom, dad, girlfriend, boyfriend, and best friend gift ideas.',
            'It is also in the same orbit as birthday gift for brother, unique birthday gift for sister, funny birthday gift for friend, and surprise videos for family.',
            'The wider use case is still the same: family, friends, partners, and anyone who needs a funny occasion video that feels personal.',
            'That is why it works across family, friends, couples, birthdays, Christmas, New Year, Valentine\'s Day, and other celebrations.',
        ];

        $shortRelationLines = [
            'Works for family, friends, brothers, sisters, girlfriends, and boyfriends too.',
            'Also good when someone is searching gifts for mom, dad, brother, sister, or friend.',
            'Fits family and friends occasions without sounding too corporate.',
            'Same vibe for birthdays, roasts, Christmas, New Year, and Valentine\'s Day.',
            'Useful for brother, sister, friend, girlfriend, boyfriend, and family gift ideas.',
        ];

        $openerSet = ($platform === 'youtube' || $platform === 'facebook' || $platform === 'linkedin' || $platform === 'pinterest')
            ? $longOpeners
            : $shortOpeners;
        $relationSet = ($platform === 'twitter' || $platform === 'bluesky' || $platform === 'threads')
            ? $shortRelationLines
            : $relationLines;

        $opener = $openerSet[$this->seedIndex($platform . '|opener|' . $occasion['key'] . '|' . $variant, count($openerSet))];
        $middle = $middleLines[$this->seedIndex($platform . '|middle|' . $occasion['key'] . '|' . $cycle, count($middleLines))];
        $discovery = $discoveryLines[$this->seedIndex($platform . '|discover|' . $occasion['key'] . '|' . ($variant + 7), count($discoveryLines))];
        $relation = $relationSet[$this->seedIndex($platform . '|relation|' . $occasion['key'] . '|' . ($cycle + 11), count($relationSet))];
        $cta = $this->buildCallToAction($platform);

        $sentences = [$opener, $middle];

        if ($platform === 'youtube' || $platform === 'facebook' || $platform === 'pinterest' || $platform === 'linkedin') {
            $sentences[] = $discovery;
            $sentences[] = $relation;
        } elseif ($platform === 'instagram' || $platform === 'tiktok') {
            $sentences[] = $relation;
        }

        $sentences[] = $cta;

        $description = implode(' ', $this->uniqueStrings($sentences));
        return $this->smartTrim($description, (int) ($config['description_limit'] ?? 800));
    }

    private function buildCallToAction(string $platform): string
    {
        $calls = [
            'youtube' => 'Create your own at ' . $this->websiteUrl . '.',
            'tiktok' => 'Build your own version at ' . $this->websiteUrl . '.',
            'instagram' => 'Make your own at ' . $this->websiteUrl . '.',
            'facebook' => 'See more custom video ideas at ' . $this->websiteUrl . '.',
            'twitter' => 'Try your own: ' . $this->websiteUrl,
            'threads' => 'Make one at ' . $this->websiteUrl . '.',
            'linkedin' => 'See the full product at ' . $this->websiteUrl . '.',
            'pinterest' => 'Save the idea and create yours at ' . $this->websiteUrl . '.',
            'bluesky' => 'Make yours at ' . $this->websiteUrl . '.',
        ];

        return $calls[$platform] ?? ('Create yours at ' . $this->websiteUrl . '.');
    }

    private function buildKeywords(array $occasion, string $platform, int $variant): array
    {
        $primaryPhrase = $occasion['search_phrases'][$variant - 1] ?? ($occasion['keywords'][0] ?? $occasion['name']);
        $platformExtras = [
            'youtube' => ['youtube shorts', 'funny shorts', 'short video', 'video gift ideas'],
            'tiktok' => ['tiktok video idea', 'funny video idea', 'viral birthday idea'],
            'instagram' => ['instagram reel idea', 'funny reel caption', 'gift reel idea'],
            'facebook' => ['facebook reels idea', 'shareable birthday video', 'family video gift'],
            'twitter' => ['short post idea', 'funny birthday post', 'shareable video clip'],
            'threads' => ['threads video post', 'shareable custom video'],
            'linkedin' => ['brand content idea', 'campaign example', 'creative social post'],
            'pinterest' => ['gift idea', 'birthday gift ideas', 'personalized gift idea'],
            'bluesky' => ['shareable video idea', 'custom birthday post'],
        ];

        $keywords = array_merge(
            [$primaryPhrase],
            $occasion['keywords'] ?? [],
            $occasion['secondary_phrases'] ?? [],
            ['prankwish', 'prankwish.com', 'custom video gift', 'personalized video gift'],
            $platformExtras[$platform] ?? []
        );

        $keywords = $this->uniqueStrings($keywords);
        $cleaned = [];
        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword === '') {
                continue;
            }
            $cleaned[] = $this->smartTrim($keyword, 60);
        }

        return $cleaned;
    }

    private function buildHashtags(array $occasion, string $platform, int $variant, int $limit): array
    {
        $primaryPhrase = $occasion['search_phrases'][$variant - 1] ?? ($occasion['keywords'][0] ?? $occasion['name']);
        $platformExtras = [
            'youtube' => ['youtube shorts', 'short video'],
            'tiktok' => ['fyp', 'funny video', 'gift ideas'],
            'instagram' => ['reels', 'reelitfeelit', 'gift ideas'],
            'facebook' => ['facebook reels', 'share this'],
            'twitter' => ['shorts', 'gift ideas'],
            'threads' => ['reels', 'gift ideas'],
            'linkedin' => ['social video', 'brand storytelling'],
            'pinterest' => ['gift ideas', 'birthday ideas', 'occasion ideas'],
            'bluesky' => ['video ideas', 'gift ideas'],
        ];

        $rawTags = array_merge(
            ['PrankWish', 'PrankWishCom', $primaryPhrase],
            $occasion['hashtags'] ?? [],
            array_slice($occasion['secondary_phrases'] ?? [], 0, 3),
            $platformExtras[$platform] ?? []
        );

        $hashtags = [];
        foreach ($rawTags as $rawTag) {
            $normalized = $this->normalizeHashtag((string) $rawTag);
            if ($normalized !== '') {
                $hashtags[] = $normalized;
            }
        }

        $hashtags = $this->uniqueStrings($hashtags);
        return array_slice($hashtags, 0, max(1, $limit));
    }

    private function buildTags(array $occasion, array $keywords, int $limit): array
    {
        $tags = array_merge(
            [$occasion['name']],
            $keywords,
            ['PrankWish', 'PrankWish.com', 'custom video gift', 'personalized occasion video']
        );

        $tags = $this->uniqueStrings($tags);
        $cleaned = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }
            $cleaned[] = $this->smartTrim($tag, 60);
        }

        return array_slice($cleaned, 0, max(1, $limit));
    }

    private function normalizeHashtag(string $tag): string
    {
        $tag = preg_replace('/https?:\/\/\S+/i', '', trim($tag));
        $tag = preg_replace('/[^a-z0-9\s]+/i', ' ', $tag);
        $parts = preg_split('/\s+/', strtolower(trim((string) $tag))) ?: [];
        $parts = array_values(array_filter($parts, static function ($value) {
            return $value !== '';
        }));
        $parts = array_slice($parts, 0, 5);

        if (empty($parts)) {
            return '';
        }

        $specialParts = [
            'prankwish' => 'PrankWish',
            'prankwishcom' => 'PrankWishCom',
            'fyp' => 'FYP',
            'youtube' => 'YouTube',
        ];

        $hashtag = '#';
        foreach ($parts as $part) {
            if (isset($specialParts[$part])) {
                $hashtag .= $specialParts[$part];
            } else {
                $hashtag .= ucfirst($part);
            }
        }

        return strlen($hashtag) > 2 ? $hashtag : '';
    }

    private function buildPlatformConfigs(): array
    {
        return [
            'youtube' => [
                'title_limit' => 100,
                'description_limit' => 4200,
                'caption_limit' => 4200,
                'hashtag_limit' => 5,
                'tag_limit' => 15,
            ],
            'tiktok' => [
                'title_limit' => 100,
                'description_limit' => 900,
                'caption_limit' => 2200,
                'hashtag_limit' => 8,
                'tag_limit' => 10,
            ],
            'instagram' => [
                'title_limit' => 100,
                'description_limit' => 900,
                'caption_limit' => 2200,
                'hashtag_limit' => 10,
                'tag_limit' => 10,
            ],
            'facebook' => [
                'title_limit' => 100,
                'description_limit' => 1600,
                'caption_limit' => 1600,
                'hashtag_limit' => 6,
                'tag_limit' => 10,
            ],
            'twitter' => [
                'title_limit' => 80,
                'description_limit' => 220,
                'caption_limit' => 280,
                'hashtag_limit' => 4,
                'tag_limit' => 8,
            ],
            'threads' => [
                'title_limit' => 90,
                'description_limit' => 350,
                'caption_limit' => 500,
                'hashtag_limit' => 6,
                'tag_limit' => 8,
            ],
            'linkedin' => [
                'title_limit' => 120,
                'description_limit' => 1400,
                'caption_limit' => 1400,
                'hashtag_limit' => 5,
                'tag_limit' => 10,
            ],
            'pinterest' => [
                'title_limit' => 100,
                'description_limit' => 900,
                'caption_limit' => 900,
                'hashtag_limit' => 6,
                'tag_limit' => 10,
            ],
            'bluesky' => [
                'title_limit' => 90,
                'description_limit' => 240,
                'caption_limit' => 300,
                'hashtag_limit' => 4,
                'tag_limit' => 8,
            ],
        ];
    }

    private function buildOccasionCatalog(): array
    {
        $make = function (
            string $key,
            string $name,
            string $descriptor,
            array $searchPhrases,
            array $secondaryPhrases,
            array $hashtags,
            array $triggers
        ): array {
            return [
                'key' => $key,
                'name' => $name,
                'descriptor' => $descriptor,
                'search_phrases' => $searchPhrases,
                'secondary_phrases' => $secondaryPhrases,
                'keywords' => $this->uniqueStrings(array_merge(
                    $searchPhrases,
                    $secondaryPhrases,
                    ['personalized video gift', 'funny custom video', 'prankwish.com']
                )),
                'hashtags' => $hashtags,
                'triggers' => $triggers,
            ];
        };

        return [
            $make(
                'birthday_mother',
                'Birthday For Mother',
                'moms, mothers, ammis, and the person holding the family together',
                ['happy birthday mother', 'birthday gift for mother', 'funny birthday video for mom', 'unique birthday wish for mom', 'birthday surprise for mother'],
                ['gift for mom from family', 'custom birthday message for mom', 'funny mom birthday gift', 'personalized birthday video for mother'],
                ['happy birthday mother', 'birthday gift for mother', 'mom birthday', 'gift for mom', 'funny birthday video', 'family birthday'],
                ['birthday mother', 'happy birthday mom', 'mom birthday', 'mother birthday', 'mama birthday', 'ammi birthday']
            ),
            $make(
                'birthday_father',
                'Birthday For Father',
                'dads, fathers, abbus, and the guy who acts tough but still laughs first',
                ['happy birthday father', 'birthday gift for father', 'funny birthday video for dad', 'unique birthday wish for dad', 'birthday surprise for father'],
                ['gift for dad from family', 'custom birthday message for dad', 'funny dad birthday gift', 'personalized birthday video for father'],
                ['happy birthday father', 'birthday gift for father', 'dad birthday', 'gift for dad', 'funny birthday video', 'family birthday'],
                ['birthday father', 'happy birthday dad', 'dad birthday', 'father birthday', 'abbu birthday', 'baba birthday']
            ),
            $make(
                'birthday_brother',
                'Birthday For Brother',
                'brothers, bhai, and the sibling who deserves a proper funny gift',
                ['happy birthday brother', 'birthday gift for brother', 'funny birthday video for brother', 'unique birthday gift for brother', 'birthday surprise for brother'],
                ['gift for brother from sister', 'gift for brother from family', 'brother birthday roast', 'personalized birthday video for brother'],
                ['happy birthday brother', 'birthday gift for brother', 'brother birthday', 'funny brother gift', 'family gift', 'birthday surprise'],
                ['birthday brother', 'happy birthday brother', 'brother birthday', 'bhai birthday', 'gift for brother']
            ),
            $make(
                'birthday_sister',
                'Birthday For Sister',
                'sisters, behen, and the sibling who needs a funny but sweet surprise',
                ['happy birthday sister', 'birthday gift for sister', 'funny birthday video for sister', 'unique birthday gift for sister', 'birthday surprise for sister'],
                ['gift for sister from brother', 'gift for sister from family', 'sister birthday surprise', 'personalized birthday video for sister'],
                ['happy birthday sister', 'birthday gift for sister', 'sister birthday', 'gift for sister', 'funny birthday video', 'family birthday'],
                ['birthday sister', 'happy birthday sister', 'sister birthday', 'behen birthday', 'gift for sister']
            ),
            $make(
                'birthday_friend',
                'Birthday For Friend',
                'friends who can take a joke and still share the video back',
                ['happy birthday friend', 'birthday gift for friend', 'funny birthday video for friend', 'unique birthday gift for friend', 'birthday surprise for friend'],
                ['birthday roast for friend', 'best birthday message for friend', 'friend birthday prank', 'personalized birthday video for friend'],
                ['happy birthday friend', 'birthday gift for friend', 'friend birthday', 'funny friend gift', 'birthday prank', 'friends birthday'],
                ['birthday friend', 'happy birthday friend', 'friend birthday', 'gift for friend', 'birthday for friend']
            ),
            $make(
                'birthday_best_friend',
                'Birthday For Best Friend',
                'best friends, besties, and the people who know every bad story already',
                ['happy birthday best friend', 'birthday gift for best friend', 'funny birthday video for best friend', 'unique birthday gift for best friend', 'birthday surprise for best friend'],
                ['birthday gift for bestie', 'best friend birthday roast', 'best friend prank video', 'personalized video for best friend'],
                ['happy birthday best friend', 'birthday gift for best friend', 'bestie birthday', 'best friend gift', 'funny birthday video', 'friendship gift'],
                ['birthday best friend', 'happy birthday bestie', 'best friend birthday', 'bff birthday', 'gift for best friend']
            ),
            $make(
                'birthday_girlfriend',
                'Birthday For Girlfriend',
                'girlfriends and partners who deserve a playful but thoughtful surprise',
                ['happy birthday girlfriend', 'birthday gift for girlfriend', 'funny birthday video for girlfriend', 'unique birthday gift for girlfriend', 'birthday surprise for girlfriend'],
                ['cute birthday message for girlfriend', 'girlfriend birthday surprise', 'personalized video for girlfriend', 'romantic funny gift for girlfriend'],
                ['happy birthday girlfriend', 'birthday gift for girlfriend', 'girlfriend birthday', 'cute gift for girlfriend', 'romantic birthday', 'funny couple gift'],
                ['birthday girlfriend', 'happy birthday girlfriend', 'girlfriend birthday', 'gift for girlfriend', 'birthday for girlfriend']
            ),
            $make(
                'birthday_boyfriend',
                'Birthday For Boyfriend',
                'boyfriends and partners who like funny gifts more than cheesy lines',
                ['happy birthday boyfriend', 'birthday gift for boyfriend', 'funny birthday video for boyfriend', 'unique birthday gift for boyfriend', 'birthday surprise for boyfriend'],
                ['cute birthday message for boyfriend', 'boyfriend birthday surprise', 'personalized video for boyfriend', 'funny couple gift for boyfriend'],
                ['happy birthday boyfriend', 'birthday gift for boyfriend', 'boyfriend birthday', 'gift for boyfriend', 'romantic birthday', 'funny couple gift'],
                ['birthday boyfriend', 'happy birthday boyfriend', 'boyfriend birthday', 'gift for boyfriend', 'birthday for boyfriend']
            ),
            $make(
                'funny_roast_friend',
                'Funny Roast For Friend',
                'friends, roast buddies, and chaotic group chats that live for savage jokes',
                ['roast friend birthday video', 'funny roast for friend', 'birthday roast for best friend', 'savage birthday video for friend', 'funny prank video for friend'],
                ['roast gift for friend', 'funny birthday roast', 'best friend roast idea', 'personalized roast video'],
                ['roast friend', 'funny roast for friend', 'birthday roast', 'savage friend video', 'prank video', 'funny birthday gift'],
                ['roast friend', 'funny roast', 'birthday roast', 'savage friend', 'prank friend']
            ),
            $make(
                'birthday_family',
                'Birthday For Family',
                'family, friends, brothers, sisters, parents, and whoever is getting celebrated next',
                ['birthday gift for family', 'funny birthday video for family', 'happy birthday family', 'unique birthday gift idea', 'birthday surprise video'],
                ['birthday gift for brother', 'unique birthday gift for sister', 'happy birthday mother', 'funny birthday gift for friend'],
                ['birthday family', 'funny birthday video', 'birthday surprise', 'family gift', 'birthday gift ideas', 'custom birthday video'],
                ['birthday', 'birthday video', 'family birthday', 'birthday gift', 'happy birthday']
            ),
            $make(
                'mothers_day',
                'Mother\'s Day',
                'mothers, moms, and family tributes that need more warmth than a stock message',
                ['mother\'s day gift for mom', 'happy mother\'s day video', 'funny mother\'s day gift', 'unique mother\'s day surprise', 'mother\'s day message for mom'],
                ['mother\'s day gift from daughter', 'mother\'s day gift from son', 'custom video for mom', 'family mother\'s day surprise'],
                ['mothers day', 'happy mothers day', 'gift for mom', 'mother day surprise', 'family gift', 'mom tribute'],
                ['mothers day', 'mother day', 'happy mothers day', 'gift for mom', 'mom tribute']
            ),
            $make(
                'fathers_day',
                'Father\'s Day',
                'fathers, dads, and family messages that work better with real humor',
                ['father\'s day gift for dad', 'happy father\'s day video', 'funny father\'s day gift', 'unique father\'s day surprise', 'father\'s day message for dad'],
                ['father\'s day gift from son', 'father\'s day gift from daughter', 'custom video for dad', 'family father\'s day surprise'],
                ['fathers day', 'happy fathers day', 'gift for dad', 'father day surprise', 'family gift', 'dad tribute'],
                ['fathers day', 'father day', 'happy fathers day', 'gift for dad', 'dad tribute']
            ),
            $make(
                'valentines_day',
                'Valentine\'s Day',
                'girlfriends, boyfriends, wives, husbands, and couples who want funny romance not stiff romance',
                ['valentine\'s day gift for girlfriend', 'valentine\'s day gift for boyfriend', 'funny valentine video', 'unique valentine surprise', 'romantic prank video'],
                ['valentine gift for wife', 'valentine gift for husband', 'cute couple video gift', 'personalized valentine message'],
                ['valentines day', 'valentine gift', 'gift for girlfriend', 'gift for boyfriend', 'couple surprise', 'romantic funny gift'],
                ['valentines day', 'valentine day', 'gift for girlfriend', 'gift for boyfriend', 'romantic prank']
            ),
            $make(
                'anniversary',
                'Anniversary',
                'couples, husbands, wives, girlfriends, boyfriends, and long-running inside jokes',
                ['anniversary gift for wife', 'anniversary gift for husband', 'funny anniversary video', 'unique anniversary surprise', 'anniversary message video'],
                ['wedding anniversary gift', 'personalized anniversary video', 'couple gift idea', 'romantic funny surprise'],
                ['anniversary gift', 'funny anniversary video', 'couple gift', 'gift for wife', 'gift for husband', 'romantic surprise'],
                ['anniversary', 'anniversary gift', 'couple anniversary', 'gift for wife', 'gift for husband']
            ),
            $make(
                'wedding',
                'Wedding',
                'brides, grooms, couples, siblings, and family who want a wedding post with character',
                ['wedding gift video', 'funny wedding surprise', 'unique wedding message video', 'wedding gift for couple', 'personalized wedding video'],
                ['bride and groom video gift', 'wedding day surprise', 'custom wedding message', 'family wedding tribute'],
                ['wedding gift', 'wedding video', 'bride groom gift', 'couple surprise', 'family wedding', 'funny wedding'],
                ['wedding', 'wedding gift', 'wedding video', 'bride groom', 'marriage celebration']
            ),
            $make(
                'christmas',
                'Christmas',
                'family, friends, couples, and holiday groups who want a more personal Merry Christmas video',
                ['merry christmas video', 'christmas gift for family', 'funny christmas video for friends', 'unique christmas gift idea', 'christmas surprise video'],
                ['christmas gift for brother', 'christmas gift for sister', 'christmas gift for girlfriend', 'family christmas greeting'],
                ['merry christmas', 'christmas gift', 'christmas for family', 'christmas for friends', 'holiday video', 'funny christmas'],
                ['christmas', 'merry christmas', 'holiday gift', 'christmas video', 'christmas family']
            ),
            $make(
                'new_year',
                'New Year',
                'family, friends, teams, and groups that want a Happy New Year message with life in it',
                ['happy new year video', 'new year gift for family', 'funny new year video for friends', 'unique new year greeting', 'new year surprise video'],
                ['new year message for family', 'new year message for friends', 'new year gift for girlfriend', 'holiday greeting video'],
                ['happy new year', 'new year gift', 'new year for family', 'new year for friends', 'holiday video', 'celebration video'],
                ['new year', 'happy new year', 'new year video', 'holiday greeting', 'new year family']
            ),
            $make(
                'graduation',
                'Graduation',
                'graduates, brothers, sisters, sons, daughters, and proud family groups',
                ['graduation gift video', 'funny graduation video', 'graduation surprise for friend', 'unique graduation gift', 'graduation message video'],
                ['graduation gift for sister', 'graduation gift for brother', 'graduation surprise for son', 'graduation tribute video'],
                ['graduation gift', 'graduation video', 'graduate surprise', 'family graduation', 'friend graduation', 'funny graduation'],
                ['graduation', 'graduate', 'graduation gift', 'graduation video', 'graduate surprise']
            ),
            $make(
                'thank_you',
                'Thank You',
                'friends, family, coworkers, and the people you owe something real to',
                ['thank you video message', 'funny thank you gift', 'thank you video for friend', 'unique thank you surprise', 'custom appreciation video'],
                ['thank you gift for mother', 'thank you gift for father', 'thank you message for friend', 'personalized appreciation video'],
                ['thank you video', 'thank you gift', 'appreciation video', 'friend thank you', 'family thank you', 'custom video gift'],
                ['thank you', 'thank you video', 'appreciation gift', 'gratitude video', 'custom thank you']
            ),
            $make(
                'eid',
                'Eid',
                'family, friends, siblings, and loved ones sharing Eid wishes with a warmer touch',
                ['eid mubarak video', 'eid gift for family', 'funny eid video for friends', 'unique eid greeting', 'eid surprise video'],
                ['eid mubarak for mother', 'eid mubarak for father', 'eid gift for brother', 'eid gift for sister'],
                ['eid mubarak', 'eid gift', 'eid for family', 'eid for friends', 'holiday greeting', 'custom eid video'],
                ['eid', 'eid mubarak', 'eid video', 'eid gift', 'eid family']
            ),
        ];
    }

    private function detectOccasionObject(string $text): ?array
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        $direct = $this->findOccasion($text);
        if ($direct !== null) {
            return $direct;
        }

        $contains = static function (string $needle) use ($text): bool {
            return strpos($text, strtolower($needle)) !== false;
        };

        if ($contains('mother\'s day') || $contains('mothers day')) {
            return $this->findOccasion('mothers_day');
        }
        if ($contains('father\'s day') || $contains('fathers day')) {
            return $this->findOccasion('fathers_day');
        }
        if ($contains('valentine')) {
            return $this->findOccasion('valentines_day');
        }
        if ($contains('anniversary')) {
            return $this->findOccasion('anniversary');
        }
        if ($contains('wedding') || $contains('marriage') || $contains('nikah')) {
            return $this->findOccasion('wedding');
        }
        if ($contains('christmas') || $contains('merry christmas')) {
            return $this->findOccasion('christmas');
        }
        if ($contains('new year')) {
            return $this->findOccasion('new_year');
        }
        if ($contains('graduation') || $contains('graduate')) {
            return $this->findOccasion('graduation');
        }
        if ($contains('thank you') || $contains('thanks')) {
            return $this->findOccasion('thank_you');
        }
        if ($contains('eid')) {
            return $this->findOccasion('eid');
        }
        if ($contains('roast') || $contains('savage')) {
            return $this->findOccasion('funny_roast_friend');
        }

        if ($contains('birthday')) {
            if ($contains('mom') || $contains('mother') || $contains('mama') || $contains('ammi')) {
                return $this->findOccasion('birthday_mother');
            }
            if ($contains('dad') || $contains('father') || $contains('abbu') || $contains('baba')) {
                return $this->findOccasion('birthday_father');
            }
            if ($contains('brother') || $contains('bhai')) {
                return $this->findOccasion('birthday_brother');
            }
            if ($contains('sister') || $contains('behen')) {
                return $this->findOccasion('birthday_sister');
            }
            if ($contains('best friend') || $contains('bestie') || $contains('bff')) {
                return $this->findOccasion('birthday_best_friend');
            }
            if ($contains('girlfriend')) {
                return $this->findOccasion('birthday_girlfriend');
            }
            if ($contains('boyfriend')) {
                return $this->findOccasion('birthday_boyfriend');
            }
            if ($contains('friend')) {
                return $this->findOccasion('birthday_friend');
            }

            return $this->findOccasion('birthday_family');
        }

        $bestScore = 0;
        $bestOccasion = null;
        foreach ($this->occasionCatalog as $occasion) {
            $score = 0;
            foreach ($occasion['triggers'] as $trigger) {
                if (strpos($text, strtolower($trigger)) !== false) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestOccasion = $occasion;
            }
        }

        return $bestOccasion;
    }

    private function findOccasion(string $occasionKey): ?array
    {
        $normalized = strtolower(trim($occasionKey));
        $normalized = str_replace(['&', '/', '-', '  '], ['and', '_', '_', ' '], $normalized);
        $normalized = preg_replace('/[^a-z0-9_ ]+/', '', $normalized);
        $normalized = str_replace(' ', '_', (string) $normalized);
        $normalized = preg_replace('/_+/', '_', (string) $normalized);

        $aliases = [
            'birthday' => 'birthday_family',
            'general' => 'birthday_family',
            'birthday_general' => 'birthday_family',
            'mother' => 'birthday_mother',
            'mom' => 'birthday_mother',
            'birthday_mom' => 'birthday_mother',
            'father' => 'birthday_father',
            'dad' => 'birthday_father',
            'birthday_dad' => 'birthday_father',
            'brother' => 'birthday_brother',
            'sister' => 'birthday_sister',
            'friend' => 'birthday_friend',
            'best_friend' => 'birthday_best_friend',
            'bestie' => 'birthday_best_friend',
            'girlfriend' => 'birthday_girlfriend',
            'boyfriend' => 'birthday_boyfriend',
            'roast_friend' => 'funny_roast_friend',
            'roast' => 'funny_roast_friend',
            'mothers_day' => 'mothers_day',
            'mothersday' => 'mothers_day',
            'mothers' => 'mothers_day',
            'fathers_day' => 'fathers_day',
            'fathersday' => 'fathers_day',
            'fathers' => 'fathers_day',
            'valentines' => 'valentines_day',
            'valentine' => 'valentines_day',
            'valentines_day' => 'valentines_day',
            'valentine_day' => 'valentines_day',
            'wedding_anniversary' => 'anniversary',
            'promotion' => 'thank_you',
        ];

        if (isset($aliases[$normalized])) {
            $normalized = $aliases[$normalized];
        }

        foreach ($this->occasionCatalog as $occasion) {
            if ($occasion['key'] === $normalized) {
                return $occasion;
            }
        }

        return null;
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        $aliases = [
            'x' => 'twitter',
            'tweet' => 'twitter',
            'youtube_shorts' => 'youtube',
            'yt' => 'youtube',
            'ig' => 'instagram',
            'insta' => 'instagram',
        ];

        return $aliases[$platform] ?? $platform;
    }

    private function seedIndex(string $seed, int $count): int
    {
        if ($count <= 1) {
            return 0;
        }

        $hash = abs((int) crc32($seed));
        return $hash % $count;
    }

    private function titleCase(string $text): string
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $words = array_map(static function ($word) {
            return ucfirst(strtolower($word));
        }, $words);

        return implode(' ', $words);
    }

    private function smartTrim(string $text, int $limit): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($limit <= 0 || strlen($text) <= $limit) {
            return $text;
        }

        $trimmed = substr($text, 0, $limit - 3);
        $spacePos = strrpos($trimmed, ' ');
        if ($spacePos !== false && $spacePos > (int) ($limit * 0.6)) {
            $trimmed = substr($trimmed, 0, $spacePos);
        }

        return rtrim($trimmed, " .,;:-") . '...';
    }

    private function uniqueStrings(array $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }

            $key = strtolower($item);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $item;
        }

        return $result;
    }
}
