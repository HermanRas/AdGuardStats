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
    // Cloudflare Intel content-category names (Phase 8) => app slug; filled in once checked live.
    'cloudflare' => [],
];
