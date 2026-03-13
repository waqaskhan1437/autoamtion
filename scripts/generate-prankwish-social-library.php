<?php

$rootDir = dirname(__DIR__);
$outputDir = $rootDir . '/content/prankwish-social';
$libraryPath = $outputDir . '/library.json';
$templatePath = $outputDir . '/library.template.json';
$websiteUrl = 'https://prankwish.com';
$brandName = 'PrankWish.com';
$libraryKey = $argv[1] ?? 'prankwish-service-library-v1';

if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Unable to create output directory: {$outputDir}\n");
    exit(1);
}

$platformLimits = [
    'youtube' => ['title' => 100, 'description' => 4200, 'hashtags' => 5, 'tags' => 14],
    'tiktok' => ['title' => 100, 'description' => 280, 'hashtags' => 7, 'tags' => 10],
    'instagram' => ['title' => 100, 'description' => 560, 'hashtags' => 7, 'tags' => 12],
    'facebook' => ['title' => 100, 'description' => 1200, 'hashtags' => 5, 'tags' => 12],
    'twitter' => ['title' => 80, 'description' => 220, 'hashtags' => 3, 'tags' => 8],
    'threads' => ['title' => 90, 'description' => 360, 'hashtags' => 5, 'tags' => 10],
    'linkedin' => ['title' => 120, 'description' => 900, 'hashtags' => 4, 'tags' => 10],
    'pinterest' => ['title' => 100, 'description' => 800, 'hashtags' => 4, 'tags' => 14],
    'bluesky' => ['title' => 90, 'description' => 260, 'hashtags' => 3, 'tags' => 8],
];

$serviceTitleTemplates = [
    'Get a personalized custom video for %s from the PrankWish crew',
    'Order an authentic custom video for %s at PrankWish.com',
    'Create a script-based video gift for %s with PrankWish.com',
    'Get a real crew-recorded custom video for %s from PrankWish.com',
    'Book a made-to-order custom video for %s today at PrankWish.com',
];

$shortTitleTemplates = [
    'Get a custom video for %s | PrankWish',
    'Order a real custom video for %s | PrankWish',
    'Scripted custom video for %s | PrankWish',
    'Custom video gift for %s | PrankWish',
    'Made-to-order video for %s | PrankWish',
];

$professionalTitleTemplates = [
    'Personalized custom video service for %s by PrankWish.com',
    'Custom scripted video solution for %s by PrankWish.com',
    'Crew-recorded custom video service for %s | PrankWish.com',
    'Made-to-order digital video gifting for %s | PrankWish.com',
    'Personalized digital video experience for %s | PrankWish.com',
];

$introVariants = [
    'Get personalized custom video at PrankWish.com.',
    'Get an authentic custom video from the PrankWish crew at PrankWish.com.',
    'Get a made-to-order custom video at PrankWish.com.',
    'Get a script-based personalized video from PrankWish.com.',
    'Get a real crew-recorded custom video at PrankWish.com.',
];

$stepVariants = [
    'Choose a style on PrankWish.com, send your custom script, and receive the finished video digitally on email or WhatsApp.',
    'Pick the style you want on PrankWish.com, share your custom script or brief, and get the final video by email or WhatsApp.',
    'Start on PrankWish.com by choosing a style, send the script you want recorded, and get digital delivery on email or WhatsApp.',
    'Choose the video style on PrankWish.com, send your words or custom script, and receive the finished file on email or WhatsApp.',
    'Go to PrankWish.com, choose a style, send your script, and get your custom video delivered digitally on email or WhatsApp.',
];

$authVariants = [
    'The PrankWish crew records videos for your brief so the message feels personal instead of copied.',
    'This is designed for people who want a custom video from real people, not another generic text template.',
    'PrankWish turns your script into a recorded video that feels human, specific, and made for the person receiving it.',
    'Each order is built around your script so the result feels authentic, direct, and personal.',
    'It works best when you want a custom video gift that sounds like it was actually made for your moment.',
];

$legalVariants = [
    'Digital delivery timing depends on the selected style and production slot.',
    'Delivery is digital and timing can vary by style, crew schedule, and script length.',
    'Your finished file is delivered digitally, and production timing depends on the selected style.',
    'Because every order is custom, delivery timing depends on the style you choose and the production queue.',
    'Production and digital delivery timing depends on the style, script, and available recording slot.',
];

$ctaVariants = [
    'Start at https://prankwish.com and choose the style that fits your occasion.',
    'Visit https://prankwish.com to choose a style and place your custom script order.',
    'Go to https://prankwish.com to pick the video style you want and submit your script.',
    'Open https://prankwish.com, select your style, and send the words you want recorded.',
    'Head to https://prankwish.com to choose a style, send your script, and book the video.',
];

$serviceKeywordPool = [
    'personalized custom video',
    'custom video gift',
    'authentic video from real people',
    'scripted video gift',
    'digital video gift',
    'crew recorded custom video',
    'personalized gift idea',
    'custom script video',
];

$serviceHashtagVariants = [
    ['#PrankWish', '#PersonalizedVideo', '#CustomVideoGift'],
    ['#PrankWish', '#UniqueGift', '#DigitalGift'],
    ['#PrankWish', '#CustomScript', '#GiftIdea'],
    ['#PrankWish', '#PersonalizedGift', '#VideoGift'],
    ['#PrankWish', '#MadeToOrder', '#CustomGift'],
];

$themes = [
    [
        'key' => 'family_birthday',
        'name' => 'Family Birthday and Celebration Gift',
        'title_context' => 'family celebrations',
        'short_line' => 'Works for birthdays, family surprises, and celebration videos.',
        'intent_line' => 'birthday gifts, family celebration surprises, and custom video greetings',
        'search_intents' => ['birthday gift video', 'family birthday surprise video', 'personalized celebration video'],
        'keywords' => ['family gift', 'birthday gift', 'custom greeting video'],
        'hashtags' => ['#BirthdayGift', '#FamilyGift', '#Birthday', '#CelebrationGift', '#GiftIdea'],
    ],
    [
        'key' => 'mother_moments',
        'name' => 'Mother Birthday and Mothers Day Gift',
        'title_context' => 'mother and family surprises',
        'short_line' => 'Useful for Mothers Day, birthday gifts for mom, and family tributes.',
        'intent_line' => 'birthday gifts for mother, Mothers Day surprises, and family video tributes',
        'search_intents' => ['birthday gift for mother', 'gift for mom from family', 'mothers day video gift'],
        'keywords' => ['mother gift', 'mothers day gift', 'gift for mom'],
        'hashtags' => ['#MothersDay', '#MotherGift', '#GiftForMom', '#BirthdayGift', '#FamilyGift'],
    ],
    [
        'key' => 'father_moments',
        'name' => 'Father Birthday and Fathers Day Gift',
        'title_context' => 'father and family surprises',
        'short_line' => 'Useful for Fathers Day, birthday gifts for dad, and family shout-outs.',
        'intent_line' => 'birthday gifts for father, Fathers Day surprises, and family appreciation videos',
        'search_intents' => ['birthday gift for father', 'gift for dad from family', 'fathers day video gift'],
        'keywords' => ['father gift', 'fathers day gift', 'gift for dad'],
        'hashtags' => ['#FathersDay', '#FatherGift', '#GiftForDad', '#BirthdayGift', '#FamilyGift'],
    ],
    [
        'key' => 'brother_gifts',
        'name' => 'Brother Birthday and Sibling Gift',
        'title_context' => 'brother and sibling gifts',
        'short_line' => 'Useful for birthday gifts for brother and sibling surprise videos.',
        'intent_line' => 'birthday gifts for brother, sibling surprise videos, and family roast gifts',
        'search_intents' => ['birthday gift for brother', 'unique gift for brother', 'custom video for brother'],
        'keywords' => ['brother gift', 'sibling gift', 'birthday gift for brother'],
        'hashtags' => ['#BrotherGift', '#BirthdayGift', '#FamilyGift', '#SiblingGift', '#UniqueGift'],
    ],
    [
        'key' => 'sister_gifts',
        'name' => 'Sister Birthday and Sibling Gift',
        'title_context' => 'sister and sibling gifts',
        'short_line' => 'Useful for birthday gifts for sister and sibling surprise videos.',
        'intent_line' => 'birthday gifts for sister, sibling surprise videos, and custom celebration clips',
        'search_intents' => ['unique birthday gift for sister', 'gift for sister', 'custom video for sister'],
        'keywords' => ['sister gift', 'sibling gift', 'birthday gift for sister'],
        'hashtags' => ['#SisterGift', '#BirthdayGift', '#FamilyGift', '#SiblingGift', '#UniqueGift'],
    ],
    [
        'key' => 'friend_gifts',
        'name' => 'Best Friend and Birthday Gift',
        'title_context' => 'best friend surprises',
        'short_line' => 'Useful for birthday gifts for friends and funny best friend videos.',
        'intent_line' => 'birthday gifts for friends, best friend surprises, and funny custom video gifts',
        'search_intents' => ['birthday gift for friend', 'best friend gift idea', 'custom video for friend'],
        'keywords' => ['friend gift', 'best friend gift', 'funny birthday gift'],
        'hashtags' => ['#FriendGift', '#BestFriendGift', '#BirthdayGift', '#UniqueGift', '#CelebrationGift'],
    ],
    [
        'key' => 'boyfriend_gifts',
        'name' => 'Boyfriend Gift and Relationship Surprise',
        'title_context' => 'boyfriend and relationship surprises',
        'short_line' => 'Useful for boyfriend gifts, birthdays, and relationship surprise videos.',
        'intent_line' => 'birthday gifts for boyfriend, relationship surprises, and romantic custom videos',
        'search_intents' => ['birthday gift for boyfriend', 'custom video for boyfriend', 'relationship surprise gift'],
        'keywords' => ['boyfriend gift', 'relationship gift', 'romantic video gift'],
        'hashtags' => ['#BoyfriendGift', '#BirthdayGift', '#ValentinesDay', '#RelationshipGift', '#CustomGift'],
    ],
    [
        'key' => 'girlfriend_gifts',
        'name' => 'Girlfriend Gift and Relationship Surprise',
        'title_context' => 'girlfriend and relationship surprises',
        'short_line' => 'Useful for girlfriend gifts, birthdays, and relationship surprise videos.',
        'intent_line' => 'birthday gifts for girlfriend, relationship surprises, and romantic custom videos',
        'search_intents' => ['birthday gift for girlfriend', 'custom video for girlfriend', 'relationship surprise gift'],
        'keywords' => ['girlfriend gift', 'relationship gift', 'romantic video gift'],
        'hashtags' => ['#GirlfriendGift', '#BirthdayGift', '#ValentinesDay', '#RelationshipGift', '#CustomGift'],
    ],
    [
        'key' => 'wife_anniversary',
        'name' => 'Wife Gift and Anniversary Video',
        'title_context' => 'wife and anniversary gifts',
        'short_line' => 'Useful for wife gifts, anniversaries, and birthday surprise videos.',
        'intent_line' => 'anniversary gifts for wife, romantic custom videos, and birthday gifts for spouse',
        'search_intents' => ['anniversary gift for wife', 'birthday gift for wife', 'custom video for wife'],
        'keywords' => ['wife gift', 'anniversary gift', 'romantic gift'],
        'hashtags' => ['#WifeGift', '#AnniversaryGift', '#BirthdayGift', '#ValentinesDay', '#RelationshipGift'],
    ],
    [
        'key' => 'husband_anniversary',
        'name' => 'Husband Gift and Anniversary Video',
        'title_context' => 'husband and anniversary gifts',
        'short_line' => 'Useful for husband gifts, anniversaries, and birthday surprise videos.',
        'intent_line' => 'anniversary gifts for husband, romantic custom videos, and birthday gifts for spouse',
        'search_intents' => ['anniversary gift for husband', 'birthday gift for husband', 'custom video for husband'],
        'keywords' => ['husband gift', 'anniversary gift', 'romantic gift'],
        'hashtags' => ['#HusbandGift', '#AnniversaryGift', '#BirthdayGift', '#ValentinesDay', '#RelationshipGift'],
    ],
    [
        'key' => 'son_celebrations',
        'name' => 'Son Birthday and Family Gift',
        'title_context' => 'son and family celebrations',
        'short_line' => 'Useful for birthday gifts for sons and family milestone videos.',
        'intent_line' => 'birthday gifts for son, family milestone videos, and custom celebration clips',
        'search_intents' => ['birthday gift for son', 'custom video for son', 'family celebration video'],
        'keywords' => ['son gift', 'family celebration', 'birthday gift'],
        'hashtags' => ['#SonGift', '#BirthdayGift', '#FamilyGift', '#CelebrationGift', '#CustomGift'],
    ],
    [
        'key' => 'daughter_celebrations',
        'name' => 'Daughter Birthday and Family Gift',
        'title_context' => 'daughter and family celebrations',
        'short_line' => 'Useful for birthday gifts for daughters and family milestone videos.',
        'intent_line' => 'birthday gifts for daughter, family milestone videos, and custom celebration clips',
        'search_intents' => ['birthday gift for daughter', 'custom video for daughter', 'family celebration video'],
        'keywords' => ['daughter gift', 'family celebration', 'birthday gift'],
        'hashtags' => ['#DaughterGift', '#BirthdayGift', '#FamilyGift', '#CelebrationGift', '#CustomGift'],
    ],
    [
        'key' => 'valentines_day',
        'name' => 'Valentines Day and Couple Gift',
        'title_context' => 'relationship moments',
        'short_line' => 'Useful for Valentines Day, girlfriend gifts, boyfriend gifts, and couple moments.',
        'intent_line' => 'Valentines Day gifts, couple surprises, and relationship custom videos',
        'search_intents' => ['valentines day video gift', 'gift for girlfriend', 'gift for boyfriend'],
        'keywords' => ['Valentines Day gift', 'couple gift', 'romantic video'],
        'hashtags' => ['#ValentinesDay', '#RelationshipGift', '#BoyfriendGift', '#GirlfriendGift', '#CustomGift'],
    ],
    [
        'key' => 'merry_christmas',
        'name' => 'Merry Christmas Gift Video',
        'title_context' => 'holiday surprises',
        'short_line' => 'Useful for Merry Christmas greetings and holiday gift videos.',
        'intent_line' => 'Merry Christmas greetings, holiday gift videos, and family festive surprises',
        'search_intents' => ['merry christmas video gift', 'christmas custom video', 'holiday greeting video'],
        'keywords' => ['Christmas gift', 'holiday greeting', 'festive video'],
        'hashtags' => ['#MerryChristmas', '#HolidayGift', '#FamilyGift', '#CustomGift', '#CelebrationGift'],
    ],
    [
        'key' => 'new_year',
        'name' => 'New Year Greeting Video',
        'title_context' => 'fresh-start celebrations',
        'short_line' => 'Useful for New Year greetings, countdown posts, and fresh-start messages.',
        'intent_line' => 'New Year greetings, celebration posts, and custom digital gift messages',
        'search_intents' => ['new year video greeting', 'happy new year video', 'new year custom gift'],
        'keywords' => ['New Year gift', 'celebration post', 'greeting video'],
        'hashtags' => ['#NewYear', '#CelebrationGift', '#CustomGift', '#GiftIdea', '#FamilyGift'],
    ],
    [
        'key' => 'graduation',
        'name' => 'Graduation and Achievement Gift',
        'title_context' => 'milestone wins',
        'short_line' => 'Useful for graduation gifts, congratulations posts, and milestone videos.',
        'intent_line' => 'graduation gifts, congratulations posts, and achievement celebration videos',
        'search_intents' => ['graduation video gift', 'congratulations custom video', 'graduation surprise video'],
        'keywords' => ['graduation gift', 'congratulations video', 'achievement gift'],
        'hashtags' => ['#GraduationGift', '#Graduation', '#Congratulations', '#CustomGift', '#CelebrationGift'],
    ],
    [
        'key' => 'wedding',
        'name' => 'Wedding and Couple Celebration Gift',
        'title_context' => 'couple milestones',
        'short_line' => 'Useful for wedding gifts, bride and groom surprises, and couple celebrations.',
        'intent_line' => 'wedding gifts, couple celebration videos, and custom bride and groom surprises',
        'search_intents' => ['wedding video gift', 'custom wedding surprise', 'digital wedding gift'],
        'keywords' => ['wedding gift', 'couple celebration', 'bride groom gift'],
        'hashtags' => ['#WeddingGift', '#Wedding', '#CelebrationGift', '#RelationshipGift', '#CustomGift'],
    ],
    [
        'key' => 'eid',
        'name' => 'Eid Greeting and Family Gift',
        'title_context' => 'Eid greetings and family gifts',
        'short_line' => 'Useful for Eid greetings, family gift videos, and festive custom messages.',
        'intent_line' => 'Eid greetings, family gift videos, and festive digital custom messages',
        'search_intents' => ['eid mubarak video gift', 'custom eid greeting', 'family eid video'],
        'keywords' => ['Eid gift', 'family greeting', 'festive video'],
        'hashtags' => ['#EidGift', '#EidMubarak', '#FamilyGift', '#CustomGift', '#CelebrationGift'],
    ],
    [
        'key' => 'friend_roast',
        'name' => 'Funny Roast and Prank Gift',
        'title_context' => 'funny roast and prank gifts',
        'short_line' => 'Useful for roast videos, prank gifts, and funny friend surprises.',
        'intent_line' => 'funny roast videos, prank gifts for friends, and custom reaction-style clips',
        'search_intents' => ['roast video for friend', 'funny prank gift', 'custom roast video'],
        'keywords' => ['prank gift', 'roast video', 'funny friend gift'],
        'hashtags' => ['#PrankGift', '#FunnyGift', '#FriendGift', '#RoastVideo', '#CustomGift'],
    ],
    [
        'key' => 'congratulations',
        'name' => 'Congratulations and Thank You Gift',
        'title_context' => 'congratulations and thank you messages',
        'short_line' => 'Useful for congratulations, thank you gifts, and appreciation videos.',
        'intent_line' => 'congratulations gifts, thank you videos, and appreciation message clips',
        'search_intents' => ['congratulations video gift', 'thank you custom video', 'appreciation message video'],
        'keywords' => ['congratulations gift', 'thank you video', 'appreciation gift'],
        'hashtags' => ['#Congratulations', '#ThankYouGift', '#AppreciationGift', '#CustomGift', '#CelebrationGift'],
    ],
];

$packs = [];
$packNumber = 1;

foreach ($themes as $themeIndex => $theme) {
    for ($variantIndex = 0; $variantIndex < 5; $variantIndex++) {
        $variantNumber = $variantIndex + 1;
        $packId = sprintf('pw-pack-%03d', $packNumber);
        $serviceKeywords = array_merge(
            $theme['search_intents'],
            $theme['keywords'],
            $serviceKeywordPool
        );

        $packPlatforms = [];
        foreach ($platformLimits as $platform => $limits) {
            $title = buildTitleForPlatform(
                $platform,
                $theme['title_context'],
                $variantIndex,
                $serviceTitleTemplates,
                $shortTitleTemplates,
                $professionalTitleTemplates,
                $limits['title']
            );
            $description = buildDescriptionForPlatform(
                $platform,
                $theme,
                $variantIndex,
                $introVariants,
                $stepVariants,
                $authVariants,
                $legalVariants,
                $ctaVariants,
                $limits['description']
            );
            $hashtags = buildHashtagsForPlatform(
                $platform,
                $theme['hashtags'],
                $serviceHashtagVariants[$variantIndex],
                $limits['hashtags']
            );
            $tags = array_slice(uniqueStrings(array_merge(
                $theme['search_intents'],
                $theme['keywords'],
                $serviceKeywordPool
            )), 0, $limits['tags']);

            $packPlatforms[$platform] = [
                'title' => $title,
                'description' => $description,
                'hashtags' => $hashtags,
                'tags' => $tags,
                'keywords' => $serviceKeywords,
                'call_to_action' => $ctaVariants[$variantIndex],
            ];
        }

        $packs[] = [
            'id' => $packId,
            'theme_key' => $theme['key'],
            'theme_name' => $theme['name'],
            'search_intents' => uniqueStrings($theme['search_intents']),
            'keywords' => uniqueStrings($serviceKeywords),
            'platforms' => $packPlatforms,
        ];
        $packNumber++;
    }
}

$library = [
    'library_key' => $libraryKey,
    'library_name' => 'PrankWish Refillable Social Library',
    'generated_at' => gmdate('c'),
    'notes' => [
        'Titles stay service-based and brand-aware; occasion coverage lives in descriptions, keywords, and hashtags.',
        'When you replace this file with a fresh queue, change library_key so the app restarts from pack 1.',
    ],
    'packs' => $packs,
];

$template = [
    'library_key' => 'change-this-key-when-you-refill',
    'library_name' => 'PrankWish Custom Refill',
    'generated_at' => gmdate('c'),
    'packs' => [
        [
            'id' => 'your-pack-001',
            'theme_key' => 'custom_theme',
            'theme_name' => 'Custom Theme Name',
            'search_intents' => ['custom video gift', 'personalized video', 'digital gift'],
            'keywords' => ['custom video gift', 'personalized custom video', 'digital gift'],
            'platforms' => [
                'youtube' => [
                    'title' => 'Get a personalized custom video from PrankWish.com',
                    'description' => 'Get personalized custom video at PrankWish.com. Choose a style, send your custom script, and receive the finished video digitally on email or WhatsApp.',
                    'hashtags' => ['#PrankWish', '#CustomVideoGift', '#GiftIdea'],
                    'tags' => ['custom video gift', 'personalized video', 'digital gift'],
                ],
                'tiktok' => [
                    'title' => 'Get a custom video that feels real | PrankWish',
                    'description' => 'Get personalized custom video at PrankWish.com. Choose a style, send your custom script, and get digital delivery on email or WhatsApp.',
                    'hashtags' => ['#PrankWish', '#UniqueGift', '#CustomGift'],
                    'tags' => ['custom video gift', 'personalized video'],
                ],
                'instagram' => [
                    'title' => 'Order a custom video from the PrankWish crew | PrankWish',
                    'description' => 'Get personalized custom video at PrankWish.com. Choose a style, send your custom script, and receive the finished file on email or WhatsApp.',
                    'hashtags' => ['#PrankWish', '#PersonalizedGift', '#GiftIdea'],
                    'tags' => ['custom video gift', 'gift idea'],
                ],
                'facebook' => [
                    'title' => 'Get a personalized custom video from the PrankWish crew',
                    'description' => 'Get personalized custom video at PrankWish.com. Choose a style, send your custom script, and receive the finished video digitally on email or WhatsApp.',
                    'hashtags' => ['#PrankWish', '#CustomVideoGift', '#GiftIdea'],
                    'tags' => ['custom video gift', 'personalized video'],
                ],
                'twitter' => [
                    'title' => 'Get a custom video | PrankWish',
                    'description' => 'Get personalized custom video at PrankWish.com. Choose a style, send your script, and get digital delivery on email or WhatsApp.',
                    'hashtags' => ['#PrankWish', '#CustomGift', '#GiftIdea'],
                    'tags' => ['custom video gift'],
                ],
                'threads' => [
                    'title' => 'Get a personalized video gift | PrankWish',
                    'description' => 'Get personalized custom video at PrankWish.com. Choose a style, send your custom script, and receive the finished video digitally on email or WhatsApp.',
                    'hashtags' => ['#PrankWish', '#CustomGift', '#PersonalizedGift'],
                    'tags' => ['custom video gift', 'personalized video'],
                ],
                'linkedin' => [
                    'title' => 'Personalized custom video service by PrankWish.com',
                    'description' => 'Get personalized custom video at PrankWish.com. Choose a style, send your custom script, and receive the finished video digitally on email or WhatsApp.',
                    'hashtags' => ['#PrankWish', '#DigitalGift', '#CustomGift'],
                    'tags' => ['custom video service', 'digital gift'],
                ],
                'pinterest' => [
                    'title' => 'Personalized custom video gift idea by PrankWish.com',
                    'description' => 'Get personalized custom video at PrankWish.com. Choose a style, send your custom script, and receive the finished video digitally on email or WhatsApp.',
                    'hashtags' => ['#PrankWish', '#GiftIdea', '#CustomGift'],
                    'tags' => ['custom video gift', 'gift idea', 'personalized video'],
                ],
                'bluesky' => [
                    'title' => 'Get a custom video | PrankWish',
                    'description' => 'Get personalized custom video at PrankWish.com. Choose a style, send your script, and get digital delivery on email or WhatsApp.',
                    'hashtags' => ['#PrankWish', '#CustomGift', '#GiftIdea'],
                    'tags' => ['custom video gift'],
                ],
            ],
        ],
    ],
];

file_put_contents(
    $libraryPath,
    json_encode($library, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
);
file_put_contents(
    $templatePath,
    json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
);

echo 'Generated ' . count($packs) . " PrankWish social packs into {$libraryPath}" . PHP_EOL;
echo 'Template written to ' . $templatePath . PHP_EOL;

function buildTitleForPlatform(
    string $platform,
    string $titleContext,
    int $variantIndex,
    array $serviceTitleTemplates,
    array $shortTitleTemplates,
    array $professionalTitleTemplates,
    int $limit
): string {
    if ($platform === 'twitter' || $platform === 'bluesky' || $platform === 'threads') {
        $title = sprintf($shortTitleTemplates[$variantIndex], $titleContext);
    } elseif ($platform === 'linkedin' || $platform === 'pinterest') {
        $title = sprintf($professionalTitleTemplates[$variantIndex], $titleContext);
    } else {
        $title = sprintf($serviceTitleTemplates[$variantIndex], $titleContext);
    }

    return trimText($title, $limit);
}

function buildDescriptionForPlatform(
    string $platform,
    array $theme,
    int $variantIndex,
    array $introVariants,
    array $stepVariants,
    array $authVariants,
    array $legalVariants,
    array $ctaVariants,
    int $limit
): string {
    $themeLine = 'This works well for ' . $theme['intent_line'] . '.';

    if ($platform === 'twitter' || $platform === 'bluesky') {
        $shortTarget = $theme['keywords'][$variantIndex % max(1, count($theme['keywords']))] ?? $theme['title_context'];
        $shortIntroVariants = [
            'Get personalized custom video at PrankWish.com for %s.',
            'Order an authentic custom video from the PrankWish crew at PrankWish.com for %s.',
            'Create a script-based custom video at PrankWish.com for %s.',
            'Book a real crew-recorded custom video at PrankWish.com for %s.',
            'Get a made-to-order custom video from PrankWish.com for %s.',
        ];
        $shortStepVariants = [
            'Choose a style, send your script, and get delivery on email or WhatsApp.',
            'Pick your style, share the script, and receive the file by email or WhatsApp.',
            'Choose the style, send your words, and get digital delivery on email or WhatsApp.',
            'Start with a style, send the script, and get the finished file by email or WhatsApp.',
            'Choose your style, send your script, and receive digital delivery on email or WhatsApp.',
        ];
        $parts = [
            sprintf($shortIntroVariants[$variantIndex], $shortTarget),
            $shortStepVariants[$variantIndex],
            $theme['short_line'],
            'https://prankwish.com',
        ];
        return trimText(implode(' ', uniqueStrings($parts)), $limit);
    }

    if ($platform === 'tiktok' || $platform === 'instagram' || $platform === 'threads') {
        $parts = [
            $introVariants[$variantIndex],
            $stepVariants[$variantIndex],
            $themeLine,
            $authVariants[$variantIndex],
            $ctaVariants[$variantIndex],
        ];
        return trimText(implode(' ', uniqueStrings($parts)), $limit);
    }

    $parts = [
        $introVariants[$variantIndex],
        $stepVariants[$variantIndex],
        $themeLine,
        $authVariants[$variantIndex],
        $legalVariants[$variantIndex],
        $ctaVariants[$variantIndex],
    ];

    return trimText(implode(' ', uniqueStrings($parts)), $limit);
}

function buildHashtagsForPlatform(string $platform, array $themeHashtags, array $serviceHashtags, int $limit): array
{
    if ($platform === 'twitter' || $platform === 'bluesky') {
        $hashtags = uniqueStrings([
            $serviceHashtags[0] ?? '',
            $themeHashtags[0] ?? '',
            $themeHashtags[1] ?? ($serviceHashtags[1] ?? ''),
            $serviceHashtags[1] ?? '',
        ]);
        return array_slice($hashtags, 0, min($limit, 3));
    }

    $hashtags = uniqueStrings(array_merge($serviceHashtags, $themeHashtags));
    if ($platform === 'linkedin') {
        return array_slice($hashtags, 0, min($limit, 4));
    }

    return array_slice($hashtags, 0, $limit);
}

function trimText(string $text, int $limit): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
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

function uniqueStrings(array $items): array
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
