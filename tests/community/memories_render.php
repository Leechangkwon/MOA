<?php
declare(strict_types=1);
// Real shell/template/URL helpers; identity and palette only are fixture stubs. No DB or network.
const TBOX_BASE = '/tbox';
const COMMUNITY_BASE = '/community';
const TBOX_ROUTE = 'GET /community/';
require __DIR__ . '/../../src/Http.php';
final class Authz { public static function canCommunityWrite(array $u): bool { return true; } }
function tbox_person_label(?string $name, string $username): string { return $name ?: $username; }
function community_palette_version(): string { return ''; }
$params = []; $_GET = []; $_COOKIE = [];
$user = ['id' => 10, 'username' => 'traveler', 'display_name' => '여행자', 'role' => 'Employee'];
$sharedToken = ($argv[1] ?? '') === 'shared' ? str_repeat('A', 43) : '';
ob_start();
require __DIR__ . '/../../src/community/handlers/memories_page.php';
$html = (string) ob_get_clean();
$markup = (string) preg_replace('/<!--.*?-->/s', '', $html);
if (substr_count($markup, '<body') !== 1 || substr_count($markup, 'id="community-threads"') !== 1
    || substr_count($markup, 'id="community-messenger"') !== ($sharedToken === '' ? 1 : 0)) {
    fwrite(STDERR, "FAIL: integrated shell or public reader markup: " . json_encode([substr_count($markup, '<body'),substr_count($markup, 'id="community-threads"'),substr_count($markup, 'id="community-messenger"')]) . "\n"); exit(1);
}
preg_match_all('/\sid="([^"]+)"/', (string) preg_replace('/<!--.*?-->/s', '', $html), $ids);
if (count($ids[1]) !== count(array_unique($ids[1]))) { fwrite(STDERR, "FAIL: duplicate element IDs\n"); exit(1); }
foreach (['/community/?view=mine', '/community/?view=shared'] as $path) {
    if (Http::returnPath($path) !== $path) { fwrite(STDERR, "FAIL: thread login return path\n"); exit(1); }
}
echo $html;
