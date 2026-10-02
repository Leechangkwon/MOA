<?php
declare(strict_types=1);
/** php tests/community/memories_unit.php — no DB or production bootstrap. */
final class Http
{
    public const MSG_BAD_REQUEST = 'bad request';
    public static array $body = [];
    public static function jsonBody(int $bytes = 0, int $depth = 0): array { return self::$body; }
    public static function error(int $status, string $message): void { throw new RuntimeException($message, $status); }
}
require __DIR__ . '/../../src/community/Memories.php';
$count = 0;
function check(bool $result, string $label): void
{
    global $count;
    if (!$result) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    $count++; echo "PASS: $label\n";
}
function invalid(array $value, string $label): void
{
    Http::$body = $value;
    try { Memories::body(); } catch (RuntimeException $e) { check($e->getCode() === 400, $label); return; }
    check(false, $label);
}
$post = ['id' => '11111111-1111-4111-8111-111111111111', 'owner_user_id' => 10, 'visibility' => 'private'];
$owner = ['id' => 10]; $other = ['id' => 11];
check(Memories::canRead($post, $owner), 'owner can read private record');
check(!Memories::canRead($post, $other), 'another member cannot read private record');
check(!Memories::canRead($post, null), 'anonymous UUID is not a capability');
$link = ['owner_user_id' => 10, 'post_id' => null];
check(!Memories::canRead($post, null, $link), 'profile capability does not open private record');
$post['visibility'] = 'community';
check(Memories::canRead($post, $other), 'member can read community record');
check(!Memories::canRead($post, null, $link), 'profile capability does not open community-only record');
$post['visibility'] = 'shared';
check(Memories::canRead($post, null, $link), 'matching profile capability opens shared record');
check(!Memories::canRead($post, $owner, ['owner_user_id' => 11, 'post_id' => null]), 'invalid link cannot fall back to authenticated owner');
check(!Memories::canRead($post, null, ['owner_user_id' => 10, 'post_id' => '22222222-2222-4222-8222-222222222222']), 'post-specific capability cannot access another post');
check(Memories::canRead($post, null, ['owner_user_id' => 10, 'post_id' => $post['id']]), 'post capability opens exact shared record');
$post['visibility'] = 'private';
check(!Memories::canRead($post, null, $link), 'narrowed visibility immediately closes a previous capability');
check(!Memories::uuid('../../secret'), 'path traversal is not a media identifier');
check(!Memories::uuid(str_repeat('-', 36)), 'broad router UUID is revalidated');
$valid = ['caption' => '바닷가의 아침', 'location' => '제주', 'trip' => '연휴', 'taken_on' => '2026-10-03', 'visibility' => 'private', 'media_ids' => []];
Http::$body = $valid; check(Memories::body()['caption'] === $valid['caption'], 'Korean record accepted');
invalid($valid + ['owner_user_id' => 11], 'mass assignment of owner rejected');
invalid(array_replace($valid, ['taken_on' => '2026-02-30']), 'impossible date rejected');
invalid(array_replace($valid, ['taken_on' => '0000-01-01']), 'Postgres-invalid zero year rejected');
invalid(array_replace($valid, ['visibility' => 'public']), 'unknown sharing mode rejected');
invalid(array_replace($valid, ['caption' => '', 'media_ids' => []]), 'empty record rejected');
invalid(array_replace($valid, ['caption' => str_repeat('a', 5001)]), 'oversized caption rejected');
invalid(array_replace($valid, ['media_ids' => [$post['id'], $post['id']]]), 'duplicate attachments rejected');
invalid(array_replace($valid, ['media_ids' => [['id' => $post['id']]]]), 'nested media identifiers rejected');
invalid(array_replace($valid, ['media_ids' => ['bad']]), 'invalid attachment identifier rejected');
invalid(array_replace($valid, ['caption' => "bad\0caption"]), 'null byte rejected');
echo "$count checks passed\n";
