<?php
declare(strict_types=1);
/** Only these three GET routes may bypass session auth. The capability is checked on EVERY request. */
Memories::requireReady();
$sharedToken = $params[0];
$link = Memories::link($sharedToken);
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
if ($link['post_id'] !== null) { Memories::row($link['post_id'], null, $link); }
if (TBOX_ROUTE === 'GET /community/memories/s/{token}/feed') {
    Http::json(Memories::feed(null, $link, $sharedToken)); exit;
}
if (TBOX_ROUTE === 'GET /community/memories/s/{token}/media/{id}') {
    Memories::stream($params[1], null, $link);
}
$sharedProfile = $link;
require __DIR__ . '/memories_page.php';
