<?php
declare(strict_types=1);

/**
 * src/community/handlers/notifications_read.php — POST /community/api/notifications/read (정책: community.read)
 *
 * 받는 값: `{all: true}` 또는 `{ids: [번호…]}`(50개까지). **내 알림만** 바뀐다 — 남의 번호를 넣어도
 * `user_id = 나` 조건에 걸려 한 줄도 바뀌지 않는다(바뀐 수로 답한다). 감사에 남기지 않는다(내 표시만 바뀐다).
 * 정책이 `community.read` 인 까닭: Guest 도 알림(인계 등)을 받을 수 있고, 읽음 표시는 쓰기가 아니라 내 상태다.
 *
 * @var array<string,mixed> $user 라우터가 넘긴 현재 사용자
 */

$body = Http::jsonBody();
$keys = array_keys($body);
$me   = (int) $user['id'];
$db   = Db::instance();

if ($keys === ['all'] && $body['all'] === true) {
    $sql = <<<'SQL'
        UPDATE community.notifications SET read_at = now()
         WHERE user_id = CAST(:me AS bigint) AND read_at IS NULL
        SQL;
    $n = $db->run($sql, [':me' => $me])->rowCount();
    Http::json(['changed' => $n]);
    exit;
}
if ($keys !== ['ids'] || !is_array($body['ids']) || !array_is_list($body['ids']) || count($body['ids']) > 50) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$n = 0;
$sql = <<<'SQL'
    UPDATE community.notifications SET read_at = now()
     WHERE id = CAST(:id AS bigint) AND user_id = CAST(:me AS bigint) AND read_at IS NULL
    SQL;
foreach ($body['ids'] as $id) {
    if (!is_int($id) || $id < 1) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
}
foreach ($body['ids'] as $id) {
    $n += $db->run($sql, [':id' => $id, ':me' => $me])->rowCount();
}
Http::json(['changed' => $n]);
