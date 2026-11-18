<?php
// The app's own category taxonomy (Plan section 4), and how each source category maps onto it.
//
// `id` is fixed forever (it is stored in `domains` and `daily`); add new categories with new ids.
// Colours: the eight most common categories take the validated categorical hues (fixed order, dark-mode
// steps; they read on both themes). The rest are quieter tones, and Uncategorised is neutral grey.
// Identity never relies on colour alone: the pie legend and the log chips always carry the name.
return [
    'categories' => [
        ['id' => 1,  'slug' => 'ads-tracking',   'name' => 'Ads & Tracking',       'colour' => '#3987e5'],
        ['id' => 2,  'slug' => 'telemetry',      'name' => 'Telemetry',            'colour' => '#d95926'],
        ['id' => 3,  'slug' => 'social',         'name' => 'Social',               'colour' => '#199e70'],
        ['id' => 4,  'slug' => 'video',          'name' => 'Video & Streaming',    'colour' => '#c98500'],
        ['id' => 5,  'slug' => 'gaming',         'name' => 'Gaming',               'colour' => '#d55181'],
        ['id' => 6,  'slug' => 'shopping',       'name' => 'Shopping',             'colour' => '#0ca30c'],
        ['id' => 7,  'slug' => 'news',           'name' => 'News',                 'colour' => '#8a7f6a'],
        ['id' => 8,  'slug' => 'search',         'name' => 'Search & Portals',     'colour' => '#9085e9'],
        ['id' => 9,  'slug' => 'messaging',      'name' => 'Messaging & Mail',     'colour' => '#4fb3c9'],
        ['id' => 10, 'slug' => 'cloud-cdn',      'name' => 'Cloud & CDN',          'colour' => '#6d8fb3'],
        ['id' => 11, 'slug' => 'os-device',      'name' => 'OS & Device Services', 'colour' => '#b07cc6'],
        ['id' => 12, 'slug' => 'infrastructure', 'name' => 'Infrastructure',       'colour' => '#7a9a5c'],
        ['id' => 13, 'slug' => 'finance',        'name' => 'Finance',              'colour' => '#c4a35a'],
        ['id' => 14, 'slug' => 'education',      'name' => 'Education',            'colour' => '#5c9e9a'],
        ['id' => 15, 'slug' => 'adult',          'name' => 'Adult',                'colour' => '#b5651d'],
        ['id' => 16, 'slug' => 'gambling',       'name' => 'Gambling',             'colour' => '#a0522d'],
        ['id' => 17, 'slug' => 'malware',        'name' => 'Malware & Phishing',   'colour' => '#e66767'],
        ['id' => 18, 'slug' => 'uncategorised',  'name' => 'Uncategorised',        'colour' => '#7d7d7d'],
        // Broad buckets, mainly for Cloudflare's general categories (added 2026-11-18).
        ['id' => 19, 'slug' => 'technology',     'name' => 'Technology',           'colour' => '#9aa84a'],
        ['id' => 20, 'slug' => 'business',       'name' => 'Business',             'colour' => '#c0785a'],
        ['id' => 21, 'slug' => 'lifestyle',      'name' => 'Lifestyle & Entertainment', 'colour' => '#d4a73c'],
    ],

    // Source category => app slug. Order matters within a source: when a domain is in several
    // categories of one source, the earlier one wins. Unlisted source categories are skipped.
    'hagezi' => [
        'tif' => 'malware',
        'native.samsung' => 'telemetry', 'native.apple' => 'telemetry', 'native.winoffice' => 'telemetry',
        'native.tiktok' => 'telemetry', 'native.amazon' => 'telemetry', 'native.huawei' => 'telemetry',
        'native.xiaomi' => 'telemetry', 'native.lgwebos' => 'telemetry', 'native.roku' => 'telemetry',
        'native.vivo' => 'telemetry', 'native.oppo-realme' => 'telemetry',
        'gambling' => 'gambling',
        'social' => 'social',
    ],
    'ut1' => [
        'malware' => 'malware', 'phishing' => 'malware', 'cryptojacking' => 'malware',
        'stalkerware' => 'malware', 'ddos' => 'malware',
        'publicite' => 'ads-tracking', 'marketingware' => 'ads-tracking',
        'gambling' => 'gambling', 'arjel' => 'gambling',
        'social_networks' => 'social', 'dating' => 'social', 'forums' => 'social',
        'webmail' => 'messaging', 'chat' => 'messaging',
        'audio-video' => 'video', 'radio' => 'video',
        'games' => 'gaming',
        'shopping' => 'shopping',
        'press' => 'news', 'fakenews' => 'news',
        'bank' => 'finance', 'financial' => 'finance', 'bitcoin' => 'finance',
        'update' => 'os-device',
        'filehosting' => 'cloud-cdn', 'webhosting' => 'cloud-cdn',
        'doh' => 'infrastructure', 'vpn' => 'infrastructure', 'dynamic-dns' => 'infrastructure',
        'remote-control' => 'infrastructure',
        'educational_games' => 'education', 'translation' => 'education',
        'ai' => 'search',
        'lingerie' => 'adult', 'mixed_adult' => 'adult', 'adult' => 'adult',
    ],
    // Cloudflare Intel names (risk types, content categories and the super-category names it also
    // returns) => app slug. Covers Cloudflare's whole published list (checked 2026-11-18) plus 'Ads' and
    // 'Trackers/Analytics', which it returns but doesn't publish. When a domain has several, the one
    // listed EARLIEST here wins, so specific beats broad: security first, then content, and the broad
    // Lifestyle / Business / Technology buckets last. CIPA and Safe for Kids are filter flags, not
    // topics, so they are left out. Unknown new names stay Uncategorised and show on the Status page.
    'cloudflare' => [
        // Security
        'Malware' => 'malware', 'Phishing' => 'malware', 'Command and Control & Botnet' => 'malware',
        'Cryptomining' => 'malware', 'Spyware' => 'malware', 'Compromised Domain' => 'malware',
        'Scam' => 'malware', 'Spam' => 'malware', 'DNS Tunneling' => 'malware',
        'Domain Generation Algorithm' => 'malware', 'Brand Embedding' => 'malware',
        'Potentially unwanted software' => 'malware', 'Deceptive Ads' => 'malware', 'Hacking' => 'malware',
        'Child Abuse' => 'malware', 'Security threats' => 'malware', 'Always blocked' => 'malware',
        // Content
        'Advertisements' => 'ads-tracking', 'Ads' => 'ads-tracking', 'Trackers/Analytics' => 'ads-tracking',
        'Pornography' => 'adult', 'Nudity' => 'adult', 'Adult Themes' => 'adult', 'Lingerie & Bikini' => 'adult',
        'Drugs' => 'adult', 'Profanity' => 'adult', 'Questionable Activities' => 'adult',
        'Militancy, Hate & Extremism' => 'adult', 'Violence' => 'adult', 'Weapons' => 'adult',
        'Questionable Content' => 'adult',
        'Gambling' => 'gambling',
        'Social Networks' => 'social', 'Dating & Relationships' => 'social', 'Forums' => 'social',
        'Photo Sharing' => 'social', 'Personal Blogs' => 'social', 'Professional Networking' => 'social',
        'Digital Postcards' => 'social',
        'Webmail' => 'messaging', 'Messaging' => 'messaging', 'Instant Messengers' => 'messaging',
        'Chat' => 'messaging', 'Internet Phone & VOIP' => 'messaging',
        'Video Streaming' => 'video', 'Audio Streaming' => 'video', 'Movies' => 'video', 'Television' => 'video',
        'Music' => 'video', 'Radio' => 'video', 'Home Video/DVD' => 'video', 'Cartoons & Anime' => 'video',
        'Gaming' => 'gaming',
        'Ecommerce' => 'shopping', 'Shopping' => 'shopping', 'Auctions & Marketplaces' => 'shopping',
        'Coupons' => 'shopping', 'Shopping & Auctions' => 'shopping',
        'News & Media' => 'news', 'Magazines' => 'news', 'Unreliable Information' => 'news',
        'Search Engines' => 'search', 'News, Portal & Search' => 'search', 'Artificial Intelligence' => 'search',
        'Brokerage & Investing' => 'finance', 'Economy & Finance' => 'finance', 'Cryptocurrency' => 'finance',
        'Personal Finance' => 'finance',
        'Education' => 'education', 'Educational Institutions' => 'education', 'Reference' => 'education',
        'Science' => 'education', 'Space & Astronomy' => 'education', 'Translator' => 'education',
        'Content Servers' => 'cloud-cdn', 'APIs' => 'cloud-cdn', 'File Sharing' => 'cloud-cdn', 'P2P' => 'cloud-cdn',
        'Anonymizer' => 'infrastructure', 'Remote Access' => 'infrastructure', 'Private IP Address' => 'infrastructure',
        'URL Alias/Redirect' => 'infrastructure',
        'Internet Communication' => 'messaging',
        // Broad buckets, last
        'Entertainment' => 'lifestyle', 'Arts' => 'lifestyle', 'Fine Art' => 'lifestyle', 'Comic Books' => 'lifestyle',
        'Humor' => 'lifestyle', 'Paranormal' => 'lifestyle', 'Arts & Crafts' => 'lifestyle', 'Astrology' => 'lifestyle',
        'Clothing' => 'lifestyle', 'Fashion' => 'lifestyle', 'Parenting' => 'lifestyle', 'Food & Drink' => 'lifestyle',
        'Hobbies & Interests' => 'lifestyle', 'Home & Garden' => 'lifestyle', 'Lifestyle' => 'lifestyle',
        'Pets' => 'lifestyle', 'Photography' => 'lifestyle', 'Sexuality' => 'lifestyle', 'Swimsuits' => 'lifestyle',
        'Tobacco' => 'lifestyle', 'Body Art' => 'lifestyle', 'Alcohol' => 'lifestyle',
        'Society & Lifestyle' => 'lifestyle', 'Health & Fitness' => 'lifestyle', 'Sex Education' => 'lifestyle',
        'Health' => 'lifestyle', 'Sports' => 'lifestyle', 'Travel' => 'lifestyle', 'Vehicles' => 'lifestyle',
        'Religion & Spirituality' => 'lifestyle', 'Religion' => 'lifestyle', 'Weather' => 'lifestyle',
        'Business' => 'business', 'Business & Economy' => 'business', 'Job Search & Careers' => 'business',
        'Real Estate' => 'business', 'Government/Legal' => 'business', 'Government & Politics' => 'business',
        'Politics, Advocacy, and Government-Related' => 'business', 'Charity and Non-profit' => 'business',
        'Technology' => 'technology', 'Information Technology' => 'technology', 'Information Security' => 'technology',
        'Shareware/Freeware' => 'technology', 'Keep Awake Software' => 'technology', 'Login Screens' => 'technology',
        'Miscellaneous' => 'technology', 'No Content' => 'technology', 'Unreachable' => 'technology',
        'Parked & For Sale Domains' => 'technology', 'New Domains' => 'technology',
        'Newly Seen Domains' => 'technology', 'Security Risks' => 'technology',
    ],
];
