<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_heartbeat.php — POST /community/api/pages/{id}/heartbeat
 *                                              (정책: community.write)
 *
 * 요구사항: `C:F-16`(편집 중 표시 30초), `../../../../community/ARCHITECTURE.md` 13.3.
 *
 * 하는 일 한 가지: 「나는 지금 이 페이지를 편집하고 있다」는 시각 하나를 민다. 돌려주는 것은
 * **나 말고 지금 편집 중인 사람**의 목록이다(2분 안에 민 사람).
 *
 * 왜 이 창구가 필요한가: 같은 페이지를 두 사람이 동시에 고치면 뒤에 저장하는 쪽이 409 를
 * 만난다. 409 는 이미 쓴 글을 앞에 두고 만나는 벽이라, 만나기 **전에** 알려 주는 편이 낫다.
 * 막는 장치는 아니다 — 막는 것은 `pages_update` 의 판 번호다.
 *
 * 받는 값: **없다**. 본문에 키가 하나라도 있으면 400 (허용 키 0).
 * 남기는 것: 감사 없음 — 30초마다 오는 신호이고, 「누가 무엇을 했나」가 아니라 「열어 두었나」다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

$body = Http::jsonBody();
if ($body !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// Q:F-4 P-4b — 본문(`doc`)을 뺀 같은 행. 판정 칸은 `community_page_row()` 와 한 SQL 에서 온다(본문만 빠진다).
// 30초마다 오는 신호가 본문 jsonb 전체를 읽지 않게 한다. 판정 함수는 그대로다.
$row = community_page_row_nodoc($pid, (int) $user['id']);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageId = (int) $row['id'];

// 볼 수 없는 페이지에는 신호를 남기지 못한다. 거부는 조용히(감사 없음) — 이 창구의 거부는
// 「그 페이지를 못 본다」는 사실의 되풀이이고, 그 사실은 페이지를 연 요청에서 이미 남는다.
[$mayRead, $readCode] = CommunityAuthz::decidePageRead($user, $row);
if (!$mayRead) {
    Http::error($readCode === CommunityAuthz::CODE_NOT_FOUND ? 404 : 403,
        $readCode === CommunityAuthz::CODE_NOT_FOUND ? Http::MSG_NOT_FOUND : Http::MSG_FORBIDDEN);
}
[$mayChange] = CommunityAuthz::decidePageChange($user, $row);
if (!$mayChange) {
    Http::error(403, Http::MSG_FORBIDDEN);
}

$sqlTouch = <<<'SQL'
    INSERT INTO community.page_edit_sessions (page_id, user_id, last_seen_at)
    VALUES (CAST(:pid AS bigint), CAST(:me AS bigint), now())
    ON CONFLICT (page_id, user_id) DO UPDATE SET last_seen_at = now()
    SQL;

$sqlOthers = <<<'SQL'
    SELECT u.username, u.display_name, e.last_seen_at
      FROM community.page_edit_sessions e
      JOIN public.users u ON u.id = e.user_id
     WHERE e.page_id = CAST(:pid AS bigint)
       AND e.user_id <> CAST(:me AS bigint)
       AND e.last_seen_at > now() - interval '2 minutes'
     ORDER BY e.last_seen_at DESC
     LIMIT 20
    SQL;

$db->run($sqlTouch, [':pid' => $pageId, ':me' => $me]);

$editors = [];
foreach ($db->run($sqlOthers, [':pid' => $pageId, ':me' => $me])->fetchAll() as $e) {
    $editors[] = [
        'username'     => (string) $e['username'],
        'display_name' => $e['display_name'] === null ? null : (string) $e['display_name'],
        'last_seen_at' => (string) $e['last_seen_at'],
    ];
}

Http::json(['editors' => $editors]);
