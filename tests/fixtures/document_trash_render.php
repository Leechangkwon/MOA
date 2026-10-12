<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 2);
define('TBOX_BASE', '/tbox'); define('COMMUNITY_BASE', '/community'); define('TBOX_HOME_PATH', '/');
require $root . '/src/Http.php';
final class Auth { public static function currentUser(): ?array { return $GLOBALS['user']; } }
final class Authz {
    public static function canCommunityWrite(array $u): bool { return ($u['role'] ?? '') !== 'Guest'; }
    public static function canAdmin(array $u): bool { return false; }
    public static function canManageUsers(array $u): bool { return false; }
}
final class Legal { public static function needsConsent(int $id): bool { return false; } }
function community_embed_csp(): void {}
function community_palette_version(): string { return 'synthetic'; }
function community_work_projects(array $u, int $limit, bool $a = false, bool $b = false): array {
    return ['items' => [['id'=>'00000000-0000-4000-8000-000000000001','title'=>'합성 부모','icon'=>'','color'=>null,'purpose'=>'project','favorite'=>false,'can_delete'=>true,'progress'=>null]], 'more'=>false];
}
function tbox_person_label(?string $name, string $username): string { return $name ?: $username; }
$user = ['id'=>1001,'username'=>'synthetic_trash','display_name'=>'합성 검토','role'=>($argv[2] ?? '') === 'guest' ? 'Guest' : 'Employee'];
$_COOKIE['tbox_theme'] = 'light'; $_COOKIE['qaredi_design'] = 'moa';
$params = [($argv[3] ?? '00000000-0000-4000-8000-000000000002')];
$mode = $argv[1] ?? 'home';
require $root.'/src/community/handlers/'.($mode === 'page' ? 'page_view.php' : 'home_page.php');
