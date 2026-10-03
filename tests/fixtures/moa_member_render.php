<?php
declare(strict_types=1);
// Synthetic view renderer only: no login, database, network or production credentials.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TBOX_BASE', '/tbox');
final class Http {
    public const MSG_FORBIDDEN = 'forbidden';
    public static function url(string $path): string { return TBOX_BASE . $path; }
    public static function communityUrl(string $path): string { return '/community' . $path; }
    public static function themeAttr(): string { return ' data-theme="light"'; }
    public static function error(int $status, string $message): never { throw new RuntimeException($message, $status); }
}
final class Authz {
    public static function canManageUsers(array $user): bool { return ($user['fixture_admin'] ?? false) === true; }
    public static function canCommunityWrite(array $user): bool { return true; }
}
final class Env {
    public static function get(string $key): string { return 'https://moa.example'; }
}
final class Db {
    public static function instance(): self { return new self(); }
    public function run(string $sql): self { return $this; }
    public function fetch(): array { return ['value' => '']; }
}
$page = $argv[1] ?? 'messages';
if (!in_array($page, ['messages', 'welcome'], true)) throw new RuntimeException('Unknown fixture');
$user = ['id'=>1001, 'username'=>'claude_employee01', 'fixture_admin'=>($argv[2] ?? '') === 'admin'];
require dirname(__DIR__, 2) . '/src/handlers/member_' . $page . '_page.php';
