<?php
// Download URLs for the weekly list refresh (Plan section 4). All checked by hand on 2026-11-16.
// HaGeZi moved its plain "domains" format out of the repo; "wildcard/<list>-onlydomains.txt" lists one
// domain per line and each entry also covers its subdomains, which is how Categoriser matches anyway.
// The list names here must match the `hagezi` keys in config/categories.php.
$hagezi = 'https://raw.githubusercontent.com/hagezi/dns-blocklists/main/wildcard/%s-onlydomains.txt';
$lists = ['tif', 'native.samsung', 'native.apple', 'native.winoffice', 'native.tiktok', 'native.amazon',
    'native.huawei', 'native.xiaomi', 'native.lgwebos', 'native.roku', 'native.vivo', 'native.oppo-realme',
    'gambling', 'social'];

return [
    'psl' => 'https://publicsuffix.org/list/public_suffix_list.dat',
    // UT1 (Université Toulouse Capitole), CC BY-SA 4.0. One tarball; blacklists/<category>/domains.
    'ut1' => 'https://dsi.ut-capitole.fr/blacklists/download/blacklists.tar.gz',
    'hagezi' => array_combine($lists, array_map(static fn ($l) => sprintf($hagezi, $l), $lists)),
];
