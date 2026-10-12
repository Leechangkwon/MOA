<?php
declare(strict_types=1);
// Test-only PHP router: rendered production views, no bootstrap/session/database.
if (PHP_SAPI !== 'cli-server') { exit(1); }
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && str_starts_with($path, '/assets/')) { return false; }
define('TBOX_BASE', '');
define('COMMUNITY_BASE', '/community');
define('TBOX_HOME_PATH', '/community/');
require $root . '/src/Http.php';
require $root . '/src/Apps.php';
final class Auth { public static function currentUser(): ?array { return null; } }
final class Legal { public static function needsConsent(int $id): bool { return false; } }
final class Authz {
    public static function canCommunityWrite(array $user): bool { return ($_GET['guest'] ?? '') !== '1'; }
    public static function canAdmin(array $user): bool { return false; }
    public static function canManageUsers(array $user): bool { return false; }
}
function community_palette_version(): string { return 'fixture'; }
function community_embed_csp(): void {}
function tbox_person_label(?string $name, string $username): string { return $name ? $name . ' (' . $username . ')' : $username; }
$user = ['id'=>1001, 'username'=>'design_review', 'display_name'=>'디자인 검토', 'role'=>'Employee', 'high_contrast'=>($_GET['hc'] ?? '')==='1'];
$params = [];
$screen = $_GET['screen'] ?? 'launcher';
if ($screen === 'launcher' || $screen === 'anonymous') {
    $launcherUser = $screen === 'anonymous' ? null : $user;
    $launcherApps = $launcherUser === null ? [] : Apps::all();
    $launcherLoginUrl = '/?screen=login';
    require $root . '/src/views/launcher.php';
} elseif ($screen === 'login') {
    require $root . '/src/handlers/login_page.php';
} elseif ($screen === 'files') {
    require $root . '/src/handlers/home.php';
} elseif ($screen === 'settings') {
    require $root . '/src/handlers/settings_page.php';
} elseif ($screen === 'community-home') {
    require $root . '/src/community/handlers/home_page.php';
} elseif ($screen === 'community-page') {
    $params = ['10000000-0000-4000-8000-000000000001'];
    require $root . '/src/community/handlers/page_view.php';
} elseif ($screen === 'community') {
    $shellModule = 'community';
    $pageTitle = '업무 공간'; $pageKey = 'community-home'; $activeNav = 'community-home';
    $pageCss = ['community/community.css'];
    require $root . '/src/views/shell_top.php';
    echo '<header class="page-head"><div class="page-head__text"><h1 class="page-head__title">업무 공간</h1><p class="page-head__sub">기록이 모이고, 일이 연결되는 공간</p></div><button type="button" class="btn">새 페이지</button></header>';
    echo '<section class="panel"><h2 class="panel__title">오늘의 기록</h2><article class="doc"><h2>함께하는 일을 더 가볍게</h2><p>아이디어와 업무 기록을 한곳에 모아 보세요.</p><p><mark class="p-bg p-bg-3">문서 팔레트 기준 배경은 유지됩니다.</mark></p></article></section>';
    require $root . '/src/views/shell_bottom.php';
} else { http_response_code(404); }
