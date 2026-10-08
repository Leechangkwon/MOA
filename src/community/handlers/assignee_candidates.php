<?php
declare(strict_types=1);

/**
 * src/community/handlers/assignee_candidates.php — GET /community/api/pages/{id}/assignee-candidates?q= (정책: community.write)
 *
 * 요구사항: 사이트 Q:F-12 WK-3 ② · WK-14 A, 커뮤니티 `../../../../community/ARCHITECTURE.md` 23.3 「창구」, SECURITY C:R-15,
 *           수락 시험 Q:T-25(후보 응답에 고칠 수 없는 사람 0).
 *
 * `mention_candidates.php` 틀 그대로다 — 아이디·이름으로 찾은 **활성** 사용자를 앞에서부터 20명까지 모아(한 요청의 판정 수 상한)
 * 한 명씩 **그 사람 몫으로 다시 읽은 행**에 `CommunityAuthz::decideAssignTarget` 을 묻고, 통과한 사람만 10명까지 돌려준다.
 * 후보 규칙을 SQL 로 한 벌 더 적지 않는다(판정 두 벌 금지).
 *
 *   · 부르는 사람이 그 페이지를 볼 수 있어야 한다(`community_history_gate` — 아니면 403·404, 그 관문의 감사 그대로).
 *   · 부르는 사람이 담당을 정할 수 있어야 한다(`decidePageAssign`) — 아니면 403(목록 조회라 감사 없음, 서버 로그 한 줄).
 *     정할 수 없는 사람이 이 창구로 「그 작업을 고칠 수 있는 사람」의 아이디를 한 글자씩 뽑지 못하게.
 *   · `q` 는 공백을 뗀 1~64자. `%`·`_`·`#` 는 글자로만.
 *   · **빈 `q`(사용감 라운드 U1-a, 2026-10-08 — 기획 `community/work-ux-plan.md` 13장 · ARCH 23.3 「창구」)**: 빈 목록 대신
 *     「열자마자 후보」 — 활성 사용자를 **부르는 사람 먼저 · 아이디 순**으로 **50명까지** 읽어(한 요청의 판정 수 상한) 같은 반복
 *     (대상 기준으로 다시 읽은 행 → `decideAssignTarget`)으로 통과한 10명. 고정 SQL 두 벌 중 하나를 고를 뿐 이어 붙이지 않는다.
 *     새 노출 0 — 같은 호출자가 한 글자 검색을 되풀이해 이미 얻을 수 있던 집합(그 작업을 고칠 수 있는 사람의 아이디·이름).
 *   · 자기 자신도 후보다(「나에게 맡기기」) — 판정은 다른 사람과 같다.
 *   · 돌려주는 것은 아이디·이름뿐(계정 번호·역할·팀 0), 최대 10명.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$pid  = (string) $params[0];
$gate = community_history_gate($user, $pid);
$row  = $gate['row'];

[$mayAssign, $assignCode] = CommunityAuthz::decidePageAssign($user, $row);
if (!$mayAssign) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community assignee-candidates deny code=' . $assignCode);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$q = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', Http::queryText('q', 64));
if ($q === '') {
    // U1-a — 열자마자 후보: 부르는 사람 먼저 · 아이디 순 · 판정 50명까지(아래 반복이 한 명씩 판정한다 — 후보 규칙은 SQL 에 없다)
    $sql = <<<'SQL'
        SELECT u.id, u.username, u.display_name, u.role, u.team_id, u.is_active
          FROM public.users u
         WHERE u.is_active = true
         ORDER BY (u.id = CAST(:me AS bigint)) DESC, u.username
         LIMIT 50
        SQL;
    $bind = [':me' => (int) $user['id']];
} else {
    $like = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $sql = <<<'SQL'
        SELECT u.id, u.username, u.display_name, u.role, u.team_id, u.is_active
          FROM public.users u
         WHERE u.is_active = true
           AND (u.username ILIKE :like ESCAPE '#' OR u.display_name ILIKE :like2 ESCAPE '#')
         ORDER BY u.username
         LIMIT 20
        SQL;
    $bind = [':like' => $like, ':like2' => $like];
}

$out = [];
foreach (Db::instance()->run($sql, $bind)->fetchAll() as $t) {
    $rowFor = community_page_row_nodoc($pid, (int) $t['id']);
    if ($rowFor !== null && CommunityAuthz::decideAssignTarget($t, $rowFor)[0]) {
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
