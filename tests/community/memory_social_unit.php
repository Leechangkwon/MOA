<?php
declare(strict_types=1);
/** Pure authorization/payload/cursor coverage, no bootstrap, server or real user records. */
final class Http
{
    public const MSG_BAD_REQUEST = 'bad request';
    public static array $body = [];
    public static function jsonBody(int $bytes = 0, int $depth = 0): array { return self::$body; }
    public static function error(int $status, string $message): void { throw new RuntimeException($message, $status); }
}
final class Db
{
    public static array $edges = [];
    private array $bind = [];
    public static function instance(): self { return new self(); }
    public function run(string $sql, array $bind = []): self { $this->bind = $bind; return $this; }
    public function fetchColumn(): bool { return in_array([$this->bind[':viewer'], $this->bind[':owner']], self::$edges, true); }
}
require __DIR__ . '/../../src/community/Memories.php';
$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($label); }
    ++$checks; echo 'PASS ' . $label . "\n";
}
function rejects(callable $fn, string $label): void
{
    try { $fn(); } catch (RuntimeException $e) { check($e->getCode() === 400, $label); return; }
    check(false, $label);
}
$id = '11111111-1111-4111-8111-111111111111';
$post = ['id' => $id, 'owner_user_id' => 10, 'visibility' => 'followers'];
$owner = ['id' => 10]; $follower = ['id' => 11]; $outsider = ['id' => 12];
check(Memories::canRead($post, $owner), 'owner reads followers record');
check(!Memories::canRead($post, $follower), 'no relationship denies followers record');
Db::$edges = [[10, 11]];
check(!Memories::canRead($post, $follower), 'following the viewer does not open owner records');
Db::$edges = [[11, 10]];
check(Memories::canRead($post, $follower), 'viewer follows owner, followers record opens');
check(!Memories::canRead($post, $outsider), 'other viewer does not inherit a relationship');
check(!Memories::canRead($post, null), 'anonymous never gets followers record');
check(!Memories::canRead($post, $follower, ['owner_user_id' => 10, 'post_id' => null]), 'capability cannot open followers record even in an authenticated session');
foreach (['private', 'unknown'] as $visibility) {
    $post['visibility'] = $visibility;
    check(!Memories::canRead($post, $follower), 'follow does not open ' . $visibility . ' record');
}
foreach (['community', 'shared'] as $visibility) {
    $post['visibility'] = $visibility;
    check(Memories::canRead($post, $outsider), 'existing ' . $visibility . ' member access preserved');
}
$post['visibility'] = 'followers'; Db::$edges = [];
check(!Memories::canRead($post, $follower), 'unfollow takes effect without a cached authorization grant');
$body = ['caption' => '행복한 하루', 'location' => '', 'trip' => '', 'taken_on' => '2026-10-03', 'visibility' => 'followers', 'media_ids' => []];
Http::$body = $body;
check(Memories::body()['visibility'] === 'followers', 'followers is a supported owner selection');
foreach ([1, 'true', null, []] as $invalid) {
    Http::$body = $body + ['discoverable' => $invalid];
    rejects(static fn () => Memories::body(), 'discovery opt-in requires a boolean');
}
foreach (['private', 'followers'] as $visibility) {
    Http::$body = array_replace($body, ['visibility' => $visibility, 'discoverable' => true]);
    rejects(static fn () => Memories::body(), 'discovery cannot widen ' . $visibility);
}
foreach (['community', 'shared'] as $visibility) {
    Http::$body = array_replace($body, ['visibility' => $visibility, 'discoverable' => true]);
    check(Memories::body()['discoverable'] === true, 'explicit ' . $visibility . ' discovery accepted');
}
Http::$body = $body + ['following' => true];
rejects(static fn () => Memories::body(), 'client cannot assign its relationship');
$scope = hash('sha256', 'synthetic scope');
$row = ['id' => $id, 'created_at' => '2026-10-03 12:34:56.123456+09', 'feed_rank' => 1791000000];
$cursor = MemorySocial::encodeCursor($scope, '2026-10-03 13:00:00.123456+09', $row);
$parsed = MemorySocial::cursor($cursor, $scope);
check($parsed['id'] === $id && $parsed['rank'] === '1791000000', 'cursor preserves exact row and ranking boundary');
check($parsed['created'] === $row['created_at'], 'subsecond ordering survives pagination');
rejects(static fn () => MemorySocial::cursor($cursor, 'different view'), 'cursor rejected after changing filters');
foreach (['%', '%%%', 'e30', str_repeat('x', 1025), ['cursor']] as $invalid) {
    rejects(static fn () => MemorySocial::cursor($invalid, $scope), 'malformed cursor rejected');
}
foreach (['2026-02-30 12:00:00+00', 'infinity', 'now', '2026-10-03; DROP TABLE x', ['date']] as $invalid) {
    check(!MemorySocial::timestamp($invalid), 'invalid cursor timestamp rejected');
}
check(MemorySocial::cursor(null, $scope) === null, 'first page has no cursor');
echo $checks . " checks passed\n";
