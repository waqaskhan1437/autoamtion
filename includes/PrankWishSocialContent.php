<?php
/**
 * PrankWish social content generator.
 *
 * Generates 24 occasion packs x 5 human-style variants = 120 rotating
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
                $variant = (($cycle - 1) % $this->variantsPerOccasion) + 1;
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
        $keywords = $this->buildKeywords($occasion, $platform, $variant, $cycle, $videoTitle);
        $hashtags = $this->buildHashtags($occasion, $platform, $variant, $cycle, (int) ($config['hashtag_limit'] ?? 6), $videoTitle);
        $tags = $this->buildTags($occasion, $keywords, (int) ($config['tag_limit'] ?? 10), $cycle);
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
        $signals = $this->getOccasionSignals($occasion);
        $primaryPhrase = $occasion['search_phrases'][$variant - 1] ?? ($occasion['keywords'][0] ?? $occasion['name']);
        $secondaryPool = array_merge(
            (array) ($occasion['secondary_phrases'] ?? []),
            (array) ($signals['extra_queries'] ?? [])
        );
        $supportPhrases = $this->pickSeededDistinct(
            $secondaryPool,
            2,
            $platform . '|title-support|' . $occasion['key'] . '|' . $variant . '|' . $cycle
        );
        $supportOne = $supportPhrases[0] ?? $primaryPhrase;
        $supportTwo = $supportPhrases[1] ?? ($signals['relation_title'] ?? $occasion['name']);
        $primaryTitle = $this->titleCase($primaryPhrase);
        $supportTitle = $this->titleCase($supportOne);
        $relationTitle = $this->titleCase((string) ($signals['relation_title'] ?? $occasion['name']));
        $eventTitle = $this->titleCase((string) ($signals['event_title'] ?? $occasion['name']));
        $videoHook = $this->extractVideoHook($videoTitle, 52);
        $brand = $this->brandName;
        $seoTemplates = [
            $primaryTitle . ' | Custom Video Gift by ' . $brand,
            $primaryTitle . ' | Personalized ' . $eventTitle . ' by ' . $brand,
            $supportTitle . ' | ' . $primaryTitle . ' | ' . $brand,
            $primaryTitle . ' | ' . $relationTitle . ' Video Idea | ' . $brand,
            $primaryTitle . ' | ' . $this->titleCase($supportTwo) . ' | ' . $brand,
        ];

        $casualTemplates = [
            'A rough human-style ' . $primaryPhrase . ' | ' . $brand,
            'Not a boring template: ' . $primaryTitle . ' | ' . $brand,
            $primaryTitle . ' that feels personal | ' . $brand,
            $supportTitle . ' | ' . $brand,
            $primaryTitle . ' with a pranky edge | ' . $brand,
        ];

        $shortTemplates = [
            $primaryTitle . ' | ' . $brand,
            $supportTitle . ' | ' . $brand,
            $relationTitle . ' gift idea | ' . $brand,
            $primaryTitle . ' video gift | ' . $brand,
            $brand . ' | ' . $primaryTitle,
        ];

        $professionalTemplates = [
            $primaryTitle . ' campaign idea | ' . $brand,
            'Custom ' . $primaryPhrase . ' by ' . $brand,
            $brand . ' example for ' . $supportOne,
            'Personalized ' . $eventTitle . ' concept | ' . $brand,
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

        if ($videoHook !== '' && ($platform === 'tiktok' || $platform === 'instagram' || $platform === 'threads' || $platform === 'twitter' || $platform === 'bluesky')) {
            $templates[] = $this->smartTrim($videoHook, 44) . ' | ' . $primaryTitle . ' | ' . $brand;
            $templates[] = $this->smartTrim($videoHook, 52) . ' | ' . $brand;
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
        $signals = $this->getOccasionSignals($occasion);
        $primaryPhrase = $occasion['search_phrases'][$variant - 1] ?? ($occasion['keywords'][0] ?? $occasion['name']);
        $videoHook = $this->extractVideoHook($videoTitle, 70);

        if ($platform === 'twitter' || $platform === 'bluesky') {
            $shortPool = [
                'Looking for ' . $primaryPhrase . '? ' . $this->brandName . ' makes custom video gifts. ' . $this->websiteUrl,
                'Custom ' . $primaryPhrase . ' by ' . $this->brandName . '. Order at ' . $this->websiteUrl,
                $this->titleCase($primaryPhrase) . ' from ' . $this->brandName . '. ' . $this->websiteUrl,
                'Made-to-order ' . $primaryPhrase . ' from ' . $this->brandName . '. ' . $this->websiteUrl,
            ];

            if ($videoHook !== '') {
                $shortPool[] = $this->smartTrim($videoHook, 42) . ' | ' . $this->titleCase($primaryPhrase) . ' | ' . $this->websiteUrl;
            }

            $shortText = $shortPool[$this->seedIndex($platform . '|short-social|' . $occasion['key'] . '|' . $variant . '|' . $cycle, count($shortPool))];
            return $this->smartTrim($shortText, (int) ($config['description_limit'] ?? 240));
        }

        $queryCount = ($platform === 'youtube' || $platform === 'facebook' || $platform === 'pinterest' || $platform === 'linkedin') ? 5 : 3;
        $priorityQueries = array_merge(
            [$primaryPhrase],
            $this->pickSeededDistinct(
                array_merge((array) ($occasion['secondary_phrases'] ?? []), (array) ($signals['extra_queries'] ?? [])),
                max(1, $queryCount - 2),
                $platform . '|priority-queries|' . $occasion['key'] . '|' . $variant . '|' . $cycle
            )
        );
        $coverageQueries = $this->pickSeededDistinct(
            (array) ($signals['coverage_queries'] ?? []),
            2,
            $platform . '|coverage-queries|' . $occasion['key'] . '|' . $variant . '|' . $cycle
        );
        $searchQueries = array_slice(
            $this->uniqueStrings(array_merge($priorityQueries, $coverageQueries)),
            0,
            $queryCount
        );
        $opener = $this->buildPrimaryHook($platform, $primaryPhrase, $signals, $variant, $cycle, $videoHook);
        $brandLine = $this->buildBrandAwarenessSentence($platform, $signals, $cycle);
        $discovery = $this->buildSearchDiscoverySentence($platform, $searchQueries, $primaryPhrase, $signals, $variant, $cycle);
        $coverage = $this->buildCoverageSentence($platform, $signals, $cycle);
        $orderLine = $this->buildOrderingSentence($platform, $cycle);
        $cta = $this->buildCallToAction($platform);

        $sentences = [$opener, $brandLine];

        if ($platform === 'youtube' || $platform === 'facebook' || $platform === 'pinterest' || $platform === 'linkedin') {
            $sentences[] = $discovery;
            $sentences[] = $coverage;
            $sentences[] = $orderLine;
        } elseif ($platform === 'instagram' || $platform === 'tiktok') {
            $sentences[] = $discovery;
            $sentences[] = $coverage;
        } else {
            $sentences[] = $discovery;
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

    private function getOccasionSignals(array $occasion): array
    {
        $key = (string) ($occasion['key'] ?? '');
        $birthdayCoverage = [
            'birthday gift for brother',
            'unique birthday gift for sister',
            'funny birthday gift for friend',
            'gift for mom from family',
            'gift for dad from family',
            'birthday surprise for girlfriend',
            'birthday wish for boyfriend',
            'birthday gift for wife',
            'birthday gift for husband',
            'birthday surprise for son',
            'birthday surprise for daughter',
            'custom birthday message video',
        ];
        $seasonalCoverage = [
            'merry christmas video gift',
            'happy new year video message',
            'valentines day gift for girlfriend',
            'valentines day gift for boyfriend',
            'eid mubarak video',
            'anniversary gift for wife',
            'anniversary gift for husband',
            'wedding video gift',
            'graduation surprise video',
            'thank you video message',
        ];
        $familyCoverage = [
            'custom video gift for family',
            'personalized video gift',
            'funny gift for friends',
            'family celebration video',
            'custom greeting video',
            'video gift delivered on email',
            'video gift delivered on whatsapp',
        ];

        $birthdayRelationMap = [
            'birthday_mother' => ['mother', 'mom', 'mama', 'ammi', 'family'],
            'birthday_father' => ['father', 'dad', 'abbu', 'baba', 'family'],
            'birthday_brother' => ['brother', 'bhai', 'sibling', 'family'],
            'birthday_sister' => ['sister', 'behen', 'sibling', 'family'],
            'birthday_friend' => ['friend', 'friends', 'buddy'],
            'birthday_best_friend' => ['best friend', 'bestie', 'bff', 'friend'],
            'birthday_girlfriend' => ['girlfriend', 'partner', 'couple'],
            'birthday_boyfriend' => ['boyfriend', 'partner', 'couple'],
            'birthday_wife' => ['wife', 'spouse', 'partner', 'couple'],
            'birthday_husband' => ['husband', 'spouse', 'partner', 'couple'],
            'birthday_son' => ['son', 'boy', 'family'],
            'birthday_daughter' => ['daughter', 'girl', 'family'],
            'birthday_family' => ['mother', 'father', 'brother', 'sister', 'friend', 'girlfriend', 'boyfriend', 'family'],
        ];

        $default = [
            'event_title' => $occasion['name'] ?? 'Custom Video Gift',
            'relation_title' => $occasion['name'] ?? 'occasion gift',
            'relation_terms' => ['family', 'friends', 'custom video gift'],
            'relation_hashtags' => ['family gift', 'friends gift', 'custom video gift', 'personalized gift'],
            'extra_queries' => [],
            'coverage_queries' => array_merge($birthdayCoverage, $seasonalCoverage, $familyCoverage),
            'coverage_hashtags' => [
                'birthday gift for brother',
                'unique birthday gift for sister',
                'funny birthday gift for friend',
                'gift for mom',
                'gift for dad',
                'merry christmas',
                'happy new year',
                'valentines day gift',
            ],
            'coverage_line' => 'mother, father, brother, sister, friend, girlfriend, boyfriend, wife, husband, son, daughter, and family',
            'descriptor' => (string) ($occasion['descriptor'] ?? 'real people and inside jokes'),
        ];

        if (isset($birthdayRelationMap[$key])) {
            $terms = $birthdayRelationMap[$key];
            $relationTitle = $terms[0];
            return array_merge($default, [
                'event_title' => 'Birthday Video Gift',
                'relation_title' => $relationTitle,
                'relation_terms' => array_merge($terms, ['birthday gift', 'birthday surprise', 'custom birthday message']),
                'relation_hashtags' => array_merge($terms, ['birthday gift', 'birthday surprise', 'custom birthday video', 'family birthday']),
                'extra_queries' => [
                    'happy birthday ' . $relationTitle,
                    'birthday gift for ' . $relationTitle,
                    'custom birthday message for ' . $relationTitle,
                    'personalized birthday video for ' . $relationTitle,
                ],
                'coverage_queries' => array_merge($birthdayCoverage, $seasonalCoverage, $familyCoverage),
                'coverage_hashtags' => array_merge(
                    ['happy birthday ' . $relationTitle, 'birthday gift for ' . $relationTitle],
                    ['birthday gift for brother', 'unique birthday gift for sister', 'funny birthday gift for friend', 'gift for mom', 'gift for dad', 'valentines day gift', 'merry christmas', 'happy new year']
                ),
                'coverage_line' => 'mother, father, brother, sister, friend, girlfriend, boyfriend, wife, husband, son, daughter, and family gift searches',
            ]);
        }

        switch ($key) {
            case 'funny_roast_friend':
                return array_merge($default, [
                    'event_title' => 'Funny Roast Video',
                    'relation_title' => 'roast friend',
                    'relation_terms' => ['friend', 'best friend', 'roast friend', 'savage friend', 'prank gift'],
                    'relation_hashtags' => ['roast friend', 'birthday roast', 'funny friend gift', 'prank video', 'best friend roast'],
                    'extra_queries' => ['roast friend birthday video', 'funny roast for friend', 'birthday roast for best friend', 'savage birthday video', 'funny prank video'],
                    'coverage_queries' => array_merge($birthdayCoverage, ['funny gift for best friend', 'roast gift for friend'], $seasonalCoverage),
                    'coverage_hashtags' => ['roast friend', 'funny roast', 'birthday roast', 'best friend roast', 'prank gift', 'funny birthday gift for friend'],
                    'coverage_line' => 'friends, best friends, group chats, birthdays, and roast-style gift searches',
                ]);

            case 'mothers_day':
                return array_merge($default, [
                    'event_title' => 'Mother\'s Day Video Gift',
                    'relation_title' => 'mother',
                    'relation_terms' => ['mother', 'mom', 'mama', 'ammi', 'family', 'mother\'s day gift'],
                    'relation_hashtags' => ['mothers day', 'gift for mom', 'mother day surprise', 'mom tribute', 'family gift'],
                    'extra_queries' => ['mother\'s day gift for mom', 'happy mother\'s day video', 'mother\'s day message for mom', 'custom video for mom'],
                    'coverage_queries' => array_merge(['gift for mom from family', 'happy birthday mother'], $seasonalCoverage, $familyCoverage),
                    'coverage_hashtags' => ['mothers day', 'happy mothers day', 'gift for mom', 'mom tribute', 'family gift'],
                    'coverage_line' => 'mom, mother, family, birthday, Christmas, and thank-you style gift searches',
                ]);

            case 'fathers_day':
                return array_merge($default, [
                    'event_title' => 'Father\'s Day Video Gift',
                    'relation_title' => 'father',
                    'relation_terms' => ['father', 'dad', 'abbu', 'baba', 'family', 'father\'s day gift'],
                    'relation_hashtags' => ['fathers day', 'gift for dad', 'dad tribute', 'family gift', 'father day surprise'],
                    'extra_queries' => ['father\'s day gift for dad', 'happy father\'s day video', 'father\'s day message for dad', 'custom video for dad'],
                    'coverage_queries' => array_merge(['gift for dad from family', 'happy birthday father'], $seasonalCoverage, $familyCoverage),
                    'coverage_hashtags' => ['fathers day', 'happy fathers day', 'gift for dad', 'dad tribute', 'family gift'],
                    'coverage_line' => 'dad, father, family, birthday, Christmas, and thank-you gift searches',
                ]);

            case 'valentines_day':
            case 'anniversary':
            case 'wedding':
                return array_merge($default, [
                    'event_title' => $key === 'wedding' ? 'Wedding Video Gift' : ($key === 'anniversary' ? 'Anniversary Video Gift' : 'Valentine\'s Video Gift'),
                    'relation_title' => $key === 'wedding' ? 'couple' : 'partner',
                    'relation_terms' => ['girlfriend', 'boyfriend', 'wife', 'husband', 'partner', 'couple', 'romantic gift'],
                    'relation_hashtags' => ['gift for girlfriend', 'gift for boyfriend', 'gift for wife', 'gift for husband', 'couple surprise', 'romantic gift'],
                    'extra_queries' => array_merge((array) ($occasion['search_phrases'] ?? []), ['romantic prank video', 'cute couple video gift', 'personalized couple video']),
                    'coverage_queries' => array_merge(['birthday surprise for girlfriend', 'birthday wish for boyfriend'], $seasonalCoverage, $familyCoverage),
                    'coverage_hashtags' => ['valentines day', 'anniversary gift', 'wedding gift', 'gift for girlfriend', 'gift for boyfriend', 'gift for wife', 'gift for husband'],
                    'coverage_line' => 'girlfriend, boyfriend, wife, husband, anniversaries, weddings, Valentine\'s Day, and birthday surprise searches',
                ]);

            case 'christmas':
            case 'new_year':
            case 'eid':
                return array_merge($default, [
                    'event_title' => $key === 'christmas' ? 'Christmas Video Gift' : ($key === 'new_year' ? 'New Year Video Greeting' : 'Eid Video Greeting'),
                    'relation_title' => $key === 'christmas' ? 'Christmas gift' : ($key === 'new_year' ? 'New Year message' : 'Eid greeting'),
                    'relation_terms' => ['family', 'friends', 'brother', 'sister', 'mother', 'father', 'girlfriend', 'boyfriend', 'holiday gift'],
                    'relation_hashtags' => ['merry christmas', 'happy new year', 'eid mubarak', 'holiday gift', 'family greeting', 'friends gift'],
                    'extra_queries' => array_merge((array) ($occasion['search_phrases'] ?? []), ['holiday greeting video', 'gift for family', 'gift for friends']),
                    'coverage_queries' => array_merge($seasonalCoverage, $birthdayCoverage, $familyCoverage),
                    'coverage_hashtags' => ['merry christmas', 'happy new year', 'eid mubarak', 'gift for family', 'gift for friends', 'holiday video'],
                    'coverage_line' => 'family, friends, brothers, sisters, mothers, fathers, girlfriends, and boyfriends during holiday season searches',
                ]);

            case 'graduation':
            case 'thank_you':
                return array_merge($default, [
                    'event_title' => $key === 'graduation' ? 'Graduation Video Gift' : 'Thank You Video Gift',
                    'relation_title' => $key === 'graduation' ? 'graduate' : 'thank you message',
                    'relation_terms' => ['friend', 'family', 'brother', 'sister', 'mother', 'father', 'graduate', 'appreciation gift'],
                    'relation_hashtags' => ['graduation gift', 'thank you video', 'family gift', 'friend gift', 'custom video gift'],
                    'extra_queries' => array_merge((array) ($occasion['search_phrases'] ?? []), ['custom appreciation video', 'graduation surprise for friend', 'thank you gift for mother']),
                    'coverage_queries' => array_merge($familyCoverage, $birthdayCoverage, $seasonalCoverage),
                    'coverage_hashtags' => ['graduation gift', 'thank you video', 'appreciation gift', 'friend gift', 'family gift'],
                    'coverage_line' => 'family, friend, graduation, thank-you, birthday, and holiday gift searches',
                ]);
        }

        return $default;
    }

    private function buildPrimaryHook(
        string $platform,
        string $primaryPhrase,
        array $signals,
        int $variant,
        int $cycle,
        string $videoHook = ''
    ): string {
        $longHooks = [
            'If you are searching for ' . $primaryPhrase . ', this is the kind of custom video people actually send when they want a real reaction.',
            'This sample sits right in the lane of ' . $primaryPhrase . ', but it sounds more like a person than a stock template.',
            'People looking for ' . $primaryPhrase . ' usually want something more human than a plain text wish, and that is where this style works.',
            'When someone needs ' . $primaryPhrase . ', a custom video like this usually lands better than a generic card.',
            'This one is built for people searching ' . $primaryPhrase . ' and wanting a message that feels personal, funny, and slightly rough in a good way.',
        ];
        $shortHooks = [
            'If you need ' . $primaryPhrase . ', this is the sort of human-looking custom video that gets shared.',
            'A rough, personal take on ' . $primaryPhrase . ' from ' . $this->brandName . '.',
            'This is what ' . $primaryPhrase . ' looks like when it does not feel over-polished.',
            'A custom ' . $primaryPhrase . ' idea that sounds closer to a real conversation than a template.',
            'Searching ' . $primaryPhrase . '? This is the type of sample people actually send.',
        ];

        $hooks = ($platform === 'youtube' || $platform === 'facebook' || $platform === 'pinterest' || $platform === 'linkedin')
            ? $longHooks
            : $shortHooks;
        $hook = $hooks[$this->seedIndex($platform . '|hook|' . $primaryPhrase . '|' . $variant . '|' . $cycle, count($hooks))];

        if ($videoHook !== '' && ($platform === 'youtube' || $platform === 'facebook')) {
            $hook .= ' The line "' . $videoHook . '" is just one example of the tone people ask for.';
        }

        return $hook;
    }

    private function buildBrandAwarenessSentence(string $platform, array $signals, int $cycle): string
    {
        $descriptor = (string) ($signals['descriptor'] ?? 'real people and inside jokes');
        $longLines = [
            $this->brandName . ' makes personalized video gifts for birthdays, roasts, Christmas, New Year, Valentine\'s Day, Eid, and family surprises.',
            $this->brandName . ' turns names, scripts, photos, and inside jokes into made-to-order video gifts for ' . $descriptor . '.',
            'People use ' . $this->brandName . ' when they want a custom video that feels more personal than a normal greeting card or copied caption.',
            $this->brandName . ' is basically a made-to-order video gift brand for family, friends, couples, birthdays, and seasonal celebrations.',
        ];
        $shortLines = [
            $this->brandName . ' makes made-to-order video gifts for birthdays, families, couples, and holidays.',
            $this->brandName . ' turns names, scripts, and photos into custom video gifts.',
            $this->brandName . ' is built for rough, funny, human-feeling video greetings.',
        ];

        $pool = ($platform === 'twitter' || $platform === 'bluesky' || $platform === 'threads')
            ? $shortLines
            : $longLines;

        return $pool[$this->seedIndex($platform . '|brand|' . $cycle, count($pool))];
    }

    private function buildSearchDiscoverySentence(
        string $platform,
        array $queries,
        string $primaryPhrase,
        array $signals,
        int $variant,
        int $cycle
    ): string {
        $queries = $this->uniqueStrings(array_values(array_filter(array_map('trim', $queries))));
        if (empty($queries)) {
            $queries = [$primaryPhrase];
        }

        $queryText = $this->humanJoin($queries, 'and');
        $longTemplates = [
            'People usually find this style while searching ' . $queryText . '.',
            'The search intent around this post is usually ' . $queryText . '.',
            'Typical discovery terms here include ' . $queryText . '.',
            'This same audience often shows up from searches like ' . $queryText . '.',
        ];
        $shortTemplates = [
            'Searches around this one: ' . $queryText . '.',
            'People find it through ' . $queryText . '.',
            'Keywords here are ' . $queryText . '.',
        ];

        $pool = ($platform === 'youtube' || $platform === 'facebook' || $platform === 'pinterest' || $platform === 'linkedin')
            ? $longTemplates
            : $shortTemplates;

        return $pool[$this->seedIndex($platform . '|discover|' . $primaryPhrase . '|' . $variant . '|' . $cycle, count($pool))];
    }

    private function buildCoverageSentence(string $platform, array $signals, int $cycle): string
    {
        $coverageLine = (string) ($signals['coverage_line'] ?? 'family and friends');
        $longTemplates = [
            'It also works across searches for ' . $coverageLine . ', plus birthdays, Christmas, New Year, Valentine\'s Day, Eid, and other celebration videos.',
            'That is why the same format keeps showing up in family, friends, couples, holiday, roast, and gift-intent searches around ' . $coverageLine . '.',
            'The wider use case is simple: people want something funny, personal, and searchable for ' . $coverageLine . '.',
            'This format keeps covering family, friends, partners, birthdays, holidays, and relationship gift searches without feeling too robotic.',
        ];
        $shortTemplates = [
            'Works for ' . $coverageLine . ' too.',
            'Same format works for birthdays, holidays, couples, and family gifts.',
            'Also fits family, friends, and relationship gift searches.',
        ];

        $pool = ($platform === 'twitter' || $platform === 'bluesky')
            ? $shortTemplates
            : $longTemplates;

        return $pool[$this->seedIndex($platform . '|coverage|' . $cycle, count($pool))];
    }

    private function buildOrderingSentence(string $platform, int $cycle): string
    {
        $longLines = [
            'Pick the style, send the name, message, script, or photos, and the team records a custom video for that person.',
            'The order flow is simple: choose a PrankWish style, add the occasion details, and get the finished video delivered on email or WhatsApp.',
            'Most PrankWish orders are personalized around your script, photos, and occasion details before the final video is delivered.',
            'Many PrankWish.com listings show 60-minute delivery, while some selected video styles offer 24-hour express turnaround.',
        ];
        $shortLines = [
            'Choose the style, send the details, and get the video on email or WhatsApp.',
            'Pick a style, add the script or photos, and the team records the order.',
            'Many styles on PrankWish.com show fast delivery options too.',
        ];

        $pool = ($platform === 'youtube' || $platform === 'facebook' || $platform === 'pinterest' || $platform === 'linkedin')
            ? $longLines
            : $shortLines;

        return $pool[$this->seedIndex($platform . '|order|' . $cycle, count($pool))];
    }

    private function extractVideoHook(string $videoTitle, int $limit = 60): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $videoTitle));
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/https?:\/\/\S+/i', '', $text);
        $text = preg_replace('/[#@]/', '', $text);
        $text = trim((string) $text, " \t\n\r\0\x0B-_|");

        if ($text === '' || strlen($text) < 8) {
            return '';
        }

        if (preg_match('/^(short_|manual_|clip\s*\d+|part\s*\d+)/i', $text)) {
            return '';
        }

        if (preg_match('/^(sample|test)\s+video$/i', $text)) {
            return '';
        }

        return $this->smartTrim($text, $limit);
    }

    private function pickSeededDistinct(array $items, int $count, string $seed): array
    {
        $items = $this->uniqueStrings($items);
        if ($count <= 0 || empty($items)) {
            return [];
        }

        if (count($items) <= $count) {
            return array_values($items);
        }

        $start = $this->seedIndex($seed, count($items));
        $picked = [];
        for ($offset = 0; $offset < count($items) && count($picked) < $count; $offset++) {
            $picked[] = $items[($start + $offset) % count($items)];
        }

        return $picked;
    }

    private function humanJoin(array $items, string $lastJoiner = 'and'): string
    {
        $items = array_values(array_filter(array_map(static function ($item) {
            return trim((string) $item);
        }, $items)));

        if (empty($items)) {
            return '';
        }
        if (count($items) === 1) {
            return $items[0];
        }
        if (count($items) === 2) {
            return $items[0] . ' ' . $lastJoiner . ' ' . $items[1];
        }

        $last = array_pop($items);
        return implode(', ', $items) . ', ' . $lastJoiner . ' ' . $last;
    }

    private function buildKeywords(array $occasion, string $platform, int $variant, int $cycle, string $videoTitle = ''): array
    {
        $signals = $this->getOccasionSignals($occasion);
        $primaryPhrase = $occasion['search_phrases'][$variant - 1] ?? ($occasion['keywords'][0] ?? $occasion['name']);
        $platformExtras = [
            'youtube' => ['youtube shorts', 'searchable video title', 'custom video gift ideas', 'relationship gift ideas'],
            'tiktok' => ['tiktok gift idea', 'funny video idea', 'gift ideas', 'viral custom video'],
            'instagram' => ['instagram reel idea', 'reel caption idea', 'personalized gift idea', 'video gift idea'],
            'facebook' => ['facebook reels idea', 'shareable family video', 'custom celebration video', 'gift idea post'],
            'twitter' => ['short post idea', 'shareable video clip', 'gift idea keyword'],
            'threads' => ['threads video post', 'gift idea thread', 'celebration post idea'],
            'linkedin' => ['brand storytelling example', 'campaign idea', 'creative social post', 'video gifting brand'],
            'pinterest' => ['gift idea', 'personalized gift idea', 'occasion gift idea', 'search friendly pin'],
            'bluesky' => ['shareable video idea', 'custom gift post', 'occasion gift keyword'],
        ];

        $videoHook = $this->extractVideoHook($videoTitle, 48);
        $keywords = array_merge(
            [$primaryPhrase],
            $occasion['keywords'] ?? [],
            $occasion['secondary_phrases'] ?? [],
            $signals['extra_queries'] ?? [],
            $signals['coverage_queries'] ?? [],
            $signals['relation_terms'] ?? [],
            ['prankwish', 'prankwish.com', 'custom video gift', 'personalized video gift', 'made to order video gift', 'video message gift'],
            $platformExtras[$platform] ?? []
        );

        if ($videoHook !== '') {
            $keywords[] = strtolower($videoHook);
        }

        $keywords = array_merge(
            $keywords,
            $this->pickSeededDistinct(
                (array) ($signals['coverage_queries'] ?? []),
                4,
                $platform . '|keyword-coverage|' . $occasion['key'] . '|' . $variant . '|' . $cycle
            )
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

    private function buildHashtags(array $occasion, string $platform, int $variant, int $cycle, int $limit, string $videoTitle = ''): array
    {
        $signals = $this->getOccasionSignals($occasion);
        $primaryPhrase = $occasion['search_phrases'][$variant - 1] ?? ($occasion['keywords'][0] ?? $occasion['name']);
        $platformExtras = [
            'youtube' => ['youtube shorts', 'short video', 'gift ideas'],
            'tiktok' => ['fyp', 'gift ideas', 'birthday gift', 'gift tok'],
            'instagram' => ['reels', 'gift ideas', 'custom gift', 'celebration ideas'],
            'facebook' => ['facebook reels', 'gift ideas', 'family gifts'],
            'twitter' => ['gift ideas'],
            'threads' => ['gift ideas', 'custom gift', 'celebration ideas'],
            'linkedin' => ['brand storytelling', 'personalized gifting'],
            'pinterest' => ['gift ideas', 'occasion ideas', 'personalized gifts'],
            'bluesky' => ['gift ideas'],
        ];

        $videoHook = $this->extractVideoHook($videoTitle, 32);
        if ($platform === 'twitter' || $platform === 'bluesky') {
            $rawTags = array_merge(
                [$primaryPhrase, 'PrankWish'],
                array_slice((array) ($occasion['hashtags'] ?? []), 0, 2),
                array_slice((array) ($signals['relation_hashtags'] ?? []), 0, 1),
                $platformExtras[$platform] ?? []
            );
        } else {
            $rawTags = array_merge(
                ['PrankWish', 'PrankWishCom', $primaryPhrase],
                $occasion['hashtags'] ?? [],
                array_slice($occasion['secondary_phrases'] ?? [], 0, 4),
                $signals['relation_hashtags'] ?? [],
                $signals['coverage_hashtags'] ?? [],
                $platformExtras[$platform] ?? []
            );
        }

        if ($videoHook !== '') {
            $rawTags[] = $videoHook;
        }

        $hashtags = [];
        foreach ($rawTags as $rawTag) {
            $normalized = $this->normalizeHashtag((string) $rawTag);
            if ($normalized !== '') {
                $hashtags[] = $normalized;
            }
        }

        $hashtags = $this->uniqueStrings($hashtags);
        $priority = array_slice($hashtags, 0, min(4, count($hashtags)));
        $remaining = array_slice($hashtags, count($priority));
        $remaining = $this->pickSeededDistinct(
            $remaining,
            max(0, $limit - count($priority)),
            $platform . '|hashtags|' . $occasion['key'] . '|' . $variant . '|' . $cycle
        );

        return array_slice(array_merge($priority, $remaining), 0, max(1, $limit));
    }

    private function buildTags(array $occasion, array $keywords, int $limit, int $cycle): array
    {
        $signals = $this->getOccasionSignals($occasion);
        $tags = array_merge(
            [$occasion['name']],
            $keywords,
            $signals['relation_terms'] ?? [],
            $signals['coverage_queries'] ?? [],
            ['PrankWish', 'PrankWish.com', 'custom video gift', 'personalized occasion video', 'made to order video gift']
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

        return $this->pickSeededDistinct($cleaned, max(1, $limit), 'tags|' . $occasion['key'] . '|' . $cycle);
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
                'hashtag_limit' => 8,
                'tag_limit' => 18,
            ],
            'tiktok' => [
                'title_limit' => 100,
                'description_limit' => 900,
                'caption_limit' => 2200,
                'hashtag_limit' => 10,
                'tag_limit' => 14,
            ],
            'instagram' => [
                'title_limit' => 100,
                'description_limit' => 900,
                'caption_limit' => 2200,
                'hashtag_limit' => 10,
                'tag_limit' => 14,
            ],
            'facebook' => [
                'title_limit' => 100,
                'description_limit' => 1600,
                'caption_limit' => 1600,
                'hashtag_limit' => 9,
                'tag_limit' => 14,
            ],
            'twitter' => [
                'title_limit' => 80,
                'description_limit' => 180,
                'caption_limit' => 280,
                'hashtag_limit' => 3,
                'tag_limit' => 8,
            ],
            'threads' => [
                'title_limit' => 90,
                'description_limit' => 350,
                'caption_limit' => 500,
                'hashtag_limit' => 8,
                'tag_limit' => 10,
            ],
            'linkedin' => [
                'title_limit' => 120,
                'description_limit' => 1400,
                'caption_limit' => 1400,
                'hashtag_limit' => 4,
                'tag_limit' => 12,
            ],
            'pinterest' => [
                'title_limit' => 100,
                'description_limit' => 900,
                'caption_limit' => 900,
                'hashtag_limit' => 8,
                'tag_limit' => 14,
            ],
            'bluesky' => [
                'title_limit' => 90,
                'description_limit' => 200,
                'caption_limit' => 300,
                'hashtag_limit' => 3,
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
                'birthday_wife',
                'Birthday For Wife',
                'wives and partners who deserve a birthday gift that feels personal instead of copy-paste',
                ['happy birthday wife', 'birthday gift for wife', 'funny birthday video for wife', 'unique birthday gift for wife', 'birthday surprise for wife'],
                ['cute birthday message for wife', 'wife birthday surprise', 'personalized video for wife', 'romantic funny gift for wife'],
                ['happy birthday wife', 'birthday gift for wife', 'wife birthday', 'gift for wife', 'romantic birthday', 'personalized gift'],
                ['birthday wife', 'happy birthday wife', 'wife birthday', 'gift for wife', 'birthday for wife']
            ),
            $make(
                'birthday_husband',
                'Birthday For Husband',
                'husbands and partners who like a birthday surprise with character',
                ['happy birthday husband', 'birthday gift for husband', 'funny birthday video for husband', 'unique birthday gift for husband', 'birthday surprise for husband'],
                ['cute birthday message for husband', 'husband birthday surprise', 'personalized video for husband', 'funny gift for husband'],
                ['happy birthday husband', 'birthday gift for husband', 'husband birthday', 'gift for husband', 'romantic birthday', 'personalized gift'],
                ['birthday husband', 'happy birthday husband', 'husband birthday', 'gift for husband', 'birthday for husband']
            ),
            $make(
                'birthday_son',
                'Birthday For Son',
                'sons, boys, and family moments where a custom video feels bigger than a normal wish',
                ['happy birthday son', 'birthday gift for son', 'funny birthday video for son', 'unique birthday gift for son', 'birthday surprise for son'],
                ['birthday message for son', 'gift for son from family', 'personalized birthday video for son', 'family surprise for son'],
                ['happy birthday son', 'birthday gift for son', 'son birthday', 'gift for son', 'family birthday', 'birthday surprise'],
                ['birthday son', 'happy birthday son', 'son birthday', 'gift for son', 'birthday for son']
            ),
            $make(
                'birthday_daughter',
                'Birthday For Daughter',
                'daughters, girls, and family surprises that need something warmer and more fun than a template',
                ['happy birthday daughter', 'birthday gift for daughter', 'funny birthday video for daughter', 'unique birthday gift for daughter', 'birthday surprise for daughter'],
                ['birthday message for daughter', 'gift for daughter from family', 'personalized birthday video for daughter', 'family surprise for daughter'],
                ['happy birthday daughter', 'birthday gift for daughter', 'daughter birthday', 'gift for daughter', 'family birthday', 'birthday surprise'],
                ['birthday daughter', 'happy birthday daughter', 'daughter birthday', 'gift for daughter', 'birthday for daughter']
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
            if ($contains('wife')) {
                return $this->findOccasion('birthday_wife');
            }
            if ($contains('husband')) {
                return $this->findOccasion('birthday_husband');
            }
            if ($contains('son')) {
                return $this->findOccasion('birthday_son');
            }
            if ($contains('daughter')) {
                return $this->findOccasion('birthday_daughter');
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
            'wife' => 'birthday_wife',
            'birthday_wife' => 'birthday_wife',
            'husband' => 'birthday_husband',
            'birthday_husband' => 'birthday_husband',
            'son' => 'birthday_son',
            'birthday_son' => 'birthday_son',
            'daughter' => 'birthday_daughter',
            'birthday_daughter' => 'birthday_daughter',
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
