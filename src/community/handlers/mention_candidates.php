<?php
declare(strict_types=1);

/**
 * src/community/handlers/mention_candidates.php — GET /community/api/mention-candidates?page=&q= (정책: community.read)
 *
 * 요구사항: 커뮤니티 PRD F-22, 11-1 ⑥ 확정 — 후보 = **그 페이지를 볼 수 있는 활성 사용자 전체(Guest 제외)**.
 * 결정 D-7 기본안 — 아이디·이름으로 찾은 활성 사용자를 앞에서부터 **20명까지** 모아(한 요청의 판정 수 상한 — 독립 검토) **한 명씩** `decideMentionTarget`
 * (그 사람 몫으로 읽은 페이지 행 + `decidePageRead`)으로 거른다. 규칙을 SQL 로 한 벌 더 적지 않는다.
 *
 *   · 부르는 사람이 그 페이지를 볼 수 있어야 한다(`community_history_gate` — 아니면 403·404).
 *   · `q` 는 공백을 뗀 1~64자, 비면 빈 목록(한 글자부터 — 5-A″ 와 같다). `%`·`_`·`#` 는 글자로만.
 *   · 관리자 특권(`admin_all`)·숨긴 글 관리자(`board_moderator`)로만 볼 수 있는 사람은 후보가 아니다(좁은 쪽).
 *   · 돌려주는 것은 아이디·이름뿐, 최대 10명. 자기 자신은 빠진다.
 *
 * @var array<string,mixed> $user 라우터가 넘긴 현재 사용자
 */

$pageRaw = Http::queryText('page', 36);
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $pageRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$gate = community_history_gate($user, $pageRaw);

/* 디버깅 묶음 S2 검토 M-1 과 같은 부류 — 멘션은 댓글 쓰기(`POST …/comments` — community.write)에서만 쓴다. 쓸 수 없는 사람(손님)이
   이 창구로 「그 페이지를 볼 수 있는 사람」의 아이디·이름을 한 글자씩 찾아 뽑지 못하게 같은 함수로 403(감사 없음 — 목록 조회). */
if (!Authz::canCommunityWrite($user)) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community mention-candidates deny code=' . CommunityAuthz::CODE_ROLE_NOT_PERMITTED);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$q = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', Http::queryText('q', 64));
if ($q === '') {
    Http::json(['candidates' => []]);
    exit;
}
$like = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';

$sql = <<<'SQL'
    SELECT u.id, u.username, u.display_name, u.role, u.team_id, u.is_active
      FROM public.users u
     WHERE u.is_active = true
       AND u.id <> CAST(:me AS bigint)
       AND (u.username ILIKE :like ESCAPE '#' OR u.display_name ILIKE :like2 ESCAPE '#')
     ORDER BY u.username
     LIMIT 20
    SQL;

$out = [];
foreach (Db::instance()->run($sql, [':me' => (int) $user['id'], ':like' => $like, ':like2' => $like])->fetchAll() as $t) {
    $rowFor = community_page_row($pageRaw, (int) $t['id']);
    if ($rowFor !== null && CommunityAuthz::decideMentionTarget($t, $rowFor)[0]) {
        $out[] = [
            'username'     => (string) $t['username'],
            'display_name' => $t['display_name'] === null ? null : (string) $t['display_name'],
        ];
        if (count($out) >= 10) {
            break;
        }
    }
}
Http::json(['candidates' => $out]);
