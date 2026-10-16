<?php
declare(strict_types=1);

/**
 * src/community/handlers/boards_list.php — GET /community/api/boards (정책: community.read)
 *
 * 요구사항: 커뮤니티 PRD 4장(「게시판 목록 · 글 읽기 · 검색 — 매트릭스대로」) · F-03 · F-35 · S-02,
 *           `../../../../community/stage5c-board-publish-directive.md` 「창구」.
 *
 * 하는 일 — **볼 수 있는 게시판만** 돌려준다. 거르는 자리는 SQL `WHERE` 다(C:S-02 — 못 보는 게시판은
 *   응답에 이름도 번호도 없다). 조건은 `community_board_read_sql()` — t-box `Authz::decideRead` 를
 *   등급마다 물어 만든 값이라 `CommunityAuthz::decideBoardRead` 와 한 줄씩 짝이다.
 *
 * 항목: 번호 · 이름 · 설명 · 등급 · 팀(번호·이름) · 종류 · 활성 · 글 수 · `can_write` · `can_moderate`.
 *   글 수 = 그 게시판의 **숨기지 않은** 살아 있는 글(새 글 + 게시된 페이지) — 규모 D2a 부터 `spaces.post_count`
 *   (pages 트리거가 미리 센 값 · db/migrations/2026-10-26_scale_d2a_board_counts.sql). 게시판을 읽을 수 있는
 *   사람에게는 그 글들이 전부 보이므로(게시판 등급 갈래) 수가 목록과 어긋나지 않는다.
 *   비활성(보관) 게시판도 나온다 — 읽기 전용으로 남는다(PRD F-03). 화면이 「보관」 배지를 단다.
 *
 * `can_manage` — 「게시판 관리」 단추를 보일지(관리자). 판단은 `Authz::canAdmin` 이고, 창구
 *   `POST/PATCH /api/boards` 는 라우트 정책 `role:Admin` + 재인증이 다시 막는다(DESIGN 원칙 1).
 *
 * 목록 조회는 감사에 남기지 않는다(t-box 파일 목록과 같은 취급).
 *
 * @var array<string,mixed> $user 라우터가 넘긴 현재 사용자
 */

$db = Db::instance();

[$readable, $readBind] = community_board_read_sql('b', 1, $user);

/* 카페 C1 (ARCH 25.2) — `?area=work|cafe` 로 자리를 고른다(업무 사이드바는 work · 카페 홈은 cafe). 없거나 모르는 값이면 지금처럼
   전부(소비자 moa · popular · palettes · 관리 창 그대로). 조건 조각은 고정 문자열이고 값은 바인딩한다. */
$area = Http::queryChoice('area', ['work', 'cafe'], '');
$areaWhere = '';
if ($area !== '') {
    $areaWhere = ' AND b.area = :area';
    $readBind[':area'] = $area;
}

$sql = <<<SQL
    SELECT b.id, b.kind, b.name, b.description, b.access_level, b.team_id, b.notice,
           b.board_kind, b.is_active, b.created_at, t.name AS team_name, b.area,
           -- 규모 D2a A2 — 미리 센 글 수(트리거 scale_board_post_count · 정의는 옛 하위 질의 그대로:
           -- 지우지 · 숨기지 않은 글 중 이 게시판 글 + 이 게시판에 게시된 페이지, 한 글은 한 번)
           b.post_count
      FROM community.spaces b
      LEFT JOIN public.teams t ON t.id = b.team_id
     WHERE b.kind = 'board'
       AND {$readable}{$areaWhere}
     ORDER BY (b.board_kind = 'notice') DESC, b.is_active DESC, b.id
     LIMIT 200
    SQL;

$boards = [];
foreach ($db->run($sql, $readBind)->fetchAll() as $b) {
    $item               = community_board_summary($b, $user);
    $item['post_count'] = (int) $b['post_count'];
    // 카페 C1 (ARCH 25.2) — 자리 · 익명 여부(익명은 board_kind 하나에서 읽는다 — 화면이 종류 문자열을 견주지 않게)
    $item['area']       = (string) $b['area'];
    $item['anonymous']  = (string) $b['board_kind'] === 'anonymous';
    $boards[]           = $item;
}

Http::json([
    'boards'     => $boards,
    // 화면 편의 값 — 「게시판 관리」 단추. 창구는 `role:Admin` + 재인증이 다시 막는다.
    'can_manage' => Authz::canAdmin($user),
    // 게시할 수 있는 등급 네 값과 종류 셋 — 관리 창의 고르개가 이 목록만 그린다(화면이 지어내지 않는다).
    'levels'     => ['public', 'internal', 'team', 'admin-only'],
    'kinds'      => CommunityAuthz::BOARD_KINDS,
]);
