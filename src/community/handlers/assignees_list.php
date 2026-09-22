<?php
declare(strict_types=1);

/**
 * src/community/handlers/assignees_list.php — GET /community/api/assignees (정책: community.read)
 *
 * 요구사항: `C:F-37`(담당자), `C:S-02`(못 보는 것은 응답에 없다), 커뮤니티 `PRD.md` 4.1,
 *           `../../../../community/ARCHITECTURE.md` 13.2, 수락 기준 C:T-48.
 *
 * 쿼리
 *   page  어느 페이지의 담당자 후보인가 (UUID, **필수**)
 *   q     이름 조각으로 좁히기 (≤ 64자). 없으면 전원(최대 20)
 *
 * ★ **후보는 그 페이지를 볼 수 있는 활성 계정뿐이다.** 「누구를 담당자로 고를 수 있나」와
 *   「누가 이 페이지를 볼 수 있나」는 같은 물음이다 — 후보가 그보다 넓으면 볼 수 없는
 *   사람의 계정명이 새고, 앉혀 봐야 그 사람은 자기 이름이 붙은 페이지를 열 수 없다.
 *   그물은 SQL 조건절(`community_assignee_where`)이 치고, **마지막 판단은 판정 함수**
 *   `CommunityAuthz::decidePageRead` 가 후보를 사용자 자리에 놓고 한 번 더 한다.
 *   비활성 계정은 SQL 이 거르고, Guest 는 `internal` 갈래의 쓰기 자격 역할 목록에 없다.
 *
 * ★ 응답에는 **계정명과 역할**뿐이다. 팀 id·활성 여부·만료일 같은 것은 담당자를 고르는 데
 *   필요하지 않고, 필요하지 않은 값을 내보내면 그만큼이 새는 자리다 (PRD S-02 취지).
 *
 * ★ 목록 조회는 감사에 남기지 않는다 (t-box 파일 목록·커뮤니티 페이지 목록과 같은 취급).
 *   다만 **남의 페이지의 후보를 물은 일**은 페이지 열람과 같아서, `decidePageRead` 가
 *   `admin_all`·`same_team` 으로 열어 준 경우 `pages_get.php` 와 같은 줄이 남는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

/** 한 번에 돌려주는 후보 수 (지시서 「최대 20」). */
$limit = 20;

// ─── [1] 어느 페이지인가 ──────────────────────────────────────
$pageRaw = Http::queryText('page', 36);
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $pageRaw) !== 1) {
    // 형태가 아닌 값은 질의에 넣지 않는다 — `uuid` 칸에 닿으면 DB 가 예외를 던진다.
    Http::error(404, Http::MSG_NOT_FOUND);
}

$row = community_page_row($pageRaw);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ─── [권한 ✔] 그 페이지를 볼 수 있나 ──────────────────────────
/* 후보 목록은 그 페이지에 딸린 정보다. 페이지를 못 보는 사람에게 「이 페이지를 볼 수 있는
   사람들」을 알려 주면, 못 보는 페이지의 공개 범위를 계정명 목록으로 되짚을 수 있다. */
[$mayRead, $readCode] = CommunityAuthz::decidePageRead($user, $row);
if (!$mayRead) {
    if ($readCode === CommunityAuthz::CODE_NOT_FOUND) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    if ($readCode === CommunityAuthz::CODE_NOT_OWNER) {
        Audit::deny('page.read', CommunityAuthz::CODE_NOT_OWNER, [
            'target_type' => 'page',
            'target_id'   => (int) $row['id'],
            'target_name' => community_target_title((string) $row['title']),
            'detail'      => ['page_title' => community_target_title((string) $row['title'])],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.read deny code=' . $readCode . ' (담당자 후보)');
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [2] 후보 ─────────────────────────────────────────────────
$q          = Http::queryText('q', 64);
$candidates = community_assignee_candidates($row, $q, $limit);

// 화면의 「나에게」 단추가 눌러도 되는지를 서버가 답한다 — 화면이 스스로 따지지 않는다
// (DESIGN 원칙 1). 자기 페이지라면 언제나 참이고, 팀 공간에서도 팀원이면 참이다.
$meName = (string) $user['username'];
$meOk   = false;
foreach ($candidates as $c) {
    if ($c['username'] === $meName) {
        $meOk = true;
        break;
    }
}
/* 이름으로 좁힌 목록에 내가 없을 수도 있다(`q` 가 남의 이름 조각이면 그렇다) — 그때도
   「나에게」가 되는지는 따로 답해야 하므로, 좁히지 않은 판정을 한 번 더 한다.
   판정 함수 한 번이고 DB 를 다시 읽지 않는다. */
if (!$meOk) {
    [$meOk] = CommunityAuthz::decidePageRead($user, $row);
}

Http::json([
    'page' => [
        'id'    => (string) $row['public_id'],
        'title' => (string) $row['title'],
    ],
    'assignees' => $candidates,
    'total'     => count($candidates),
    'limit'     => $limit,
    'q'         => $q,
    // 「나에게」 단추를 열지 말지 고르는 값. 되살려 눌러도 `pages_properties.php` 가 다시 막는다.
    'can_assign_me' => $meOk === true,
    'me'            => $meName,
]);
