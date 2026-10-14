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
 *   · `q` 는 공백을 뗀 1~64자. `%`·`_`·`#` 는 글자로만. 아이디가 **정확히 같은 사람을 맨 앞**에 둔다(Q:F-12 U4-4 — 화면의 확인 색이
 *     「정확히 그 아이디」를 묻는다 · 비슷한 아이디가 20명을 넘어도 그 사람이 판정 상한 안에 든다). 그 밖 순서는 아이디 순 그대로.
 *   · **빈 `q`(Q:F-12 U4-4 — 기획 `community/work-ux-plan.md` 16.4 · 16.5, DESIGN 2.27 「후보 목록」)**: 빈 목록 대신 `@` 를 치자마자 보일 후보 —
 *     활성 사용자(부르는 사람 제외)를 **최근 이 문서에 쓴 사람(살아 있는 댓글 · 판 기록의 마지막 때) 먼저 → 아이디 순**으로 **50명까지** 읽어
 *     (한 요청의 판정 수 상한) 같은 반복(그 사람 몫으로 다시 읽은 행 → `decideMentionTarget`)으로 통과한 10명. 고정 SQL 두 벌 중 하나를 고를 뿐
 *     이어 붙이지 않는다. 새 노출 0 — 같은 호출자가 한 글자 검색을 되풀이해 이미 얻을 수 있던 집합(그 문서를 볼 수 있는 쓰기 자격 사람의
 *     아이디 · 이름)이고, 「최근 쓴 사람」은 순서에만 쓰인다(댓글 · 판 기록은 부르는 사람이 이미 볼 수 있는 그 문서의 것). U1-a 담당 후보 빈 q 와 같은 방식.
 *   · 관리자 특권(`admin_all`)·숨긴 글 관리자(`board_moderator`)로만 볼 수 있는 사람은 후보가 아니다(좁은 쪽).
 *   · 돌려주는 것은 아이디·이름뿐, 최대 10명. 자기 자신은 빠진다.
 *   · 7-D4 — 항목에 계정 번호 `id`(users.id 정수) 한 칸을 더한다(지시서 3.5 예외 · 메인 결정 2026-09-27): 글 안 멘션 마크 `{kind: user, id}` 가
 *     이 값을 저장한다. 문서 JSON 의 마크 id 와 `mention_labels` 키(`user:<id>`)로 이미 그 글을 읽는 사람에게 나가는 값이고, 후보 범위·판정·
 *     정렬·개수는 그대로라 새로 드러나는 사람은 없다. 댓글 @ 화면은 이 칸을 쓰지 않는다(`@아이디` 글자로 넣는다).
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

/* 사이트 Q:F-12 카페 C1 (ARCH 25.3) — 익명게시판 글에서는 @ 멘션을 끈다: 후보의 「최근 쓴 사람」 순서가 쓴 사람을 드러낸다.
   화면 단추만이 아니라 창구가 거부한다(comments_create 도 그 글의 @ 를 해석하지 않는다). */
if (community_page_is_anon($gate['row'])) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community mention-candidates deny code=' . CommunityAuthz::CODE_ANON_BOARD);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$q = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', Http::queryText('q', 64));
if ($q === '') {
    // U4-4 — 열자마자 후보: 최근 이 문서에 쓴 사람 먼저 · 아이디 순 · 판정 50명까지(아래 반복이 한 명씩 판정한다 — 후보 규칙은 SQL 에 없다)
    $sql = <<<'SQL'
        WITH pg AS (
            SELECT p.id FROM community.pages p WHERE p.public_id = CAST(:pid AS uuid)
        ), wrote AS (
            SELECT c.author_id AS uid, max(c.created_at) AS at
              FROM community.comments c JOIN pg ON pg.id = c.page_id
             WHERE c.deleted_at IS NULL AND c.hidden_at IS NULL
             GROUP BY c.author_id
            UNION ALL
            SELECT v.edited_by AS uid, max(v.edited_at) AS at
              FROM community.page_versions v JOIN pg ON pg.id = v.page_id
             GROUP BY v.edited_by
        )
        SELECT u.id, u.username, u.display_name, u.role, u.team_id, u.is_active
          FROM public.users u
          LEFT JOIN (SELECT uid, max(at) AS at FROM wrote GROUP BY uid) w ON w.uid = u.id
         WHERE u.is_active = true
           AND u.id <> CAST(:me AS bigint)
         ORDER BY w.at DESC NULLS LAST, u.username
         LIMIT 50
        SQL;
    $bind = [':me' => (int) $user['id'], ':pid' => $pageRaw];
} else {
    $like = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $sql = <<<'SQL'
        SELECT u.id, u.username, u.display_name, u.role, u.team_id, u.is_active
          FROM public.users u
         WHERE u.is_active = true
           AND u.id <> CAST(:me AS bigint)
           AND (u.username ILIKE :like ESCAPE '#' OR u.display_name ILIKE :like2 ESCAPE '#')
         ORDER BY (u.username = :exact) DESC, u.username
         LIMIT 20
        SQL;
    $bind = [':me' => (int) $user['id'], ':like' => $like, ':like2' => $like, ':exact' => $q];
}

$out = [];
foreach (Db::instance()->run($sql, $bind)->fetchAll() as $t) {
    $rowFor = community_page_row($pageRaw, (int) $t['id']);
    if ($rowFor !== null && CommunityAuthz::decideMentionTarget($t, $rowFor)[0]) {
        $out[] = [
            'id'           => (int) $t['id'],
            'username'     => (string) $t['username'],
            'display_name' => $t['display_name'] === null ? null : (string) $t['display_name'],
        ];
        if (count($out) >= 10) {
            break;
        }
    }
}
Http::json(['candidates' => $out]);
