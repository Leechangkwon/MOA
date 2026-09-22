<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_get.php — GET /community/api/pages/{id} (정책: community.read)
 *
 * 요구사항: `C:F-15`·`C:F-17`, `C:S-13`, 수락 기준 C:T-15(응답은 JSON 원문)·C:T-17(격리),
 *           `../../../../community/ARCHITECTURE.md` 13.3 · 21.2.
 *
 * 검사 순서
 *   [1] 행 + 공간 한 번에 읽기 → 없으면 **404**
 *   [권한 ✔] `CommunityAuthz::decidePageRead`
 *            · 지워진 페이지(`not_found`) → 404
 *            · 남의 개인 페이지(`not_owner`) → **403** + 감사 `page.read deny not_owner`
 *              (404 가 아니라 403 인 이유: 주소가 UUID 라 존재 여부가 새어도 얻을 것이 없고,
 *               「막혔다」와 「없다」를 가르는 편이 쓰는 사람에게 정직하다 — ARCH 13.2)
 *            · Admin 이 남의 페이지를 열면 200 + 감사 `page.read allow admin_all`
 *   [2] 본문과 곁가지(부모·하위·지금 편집 중인 사람)를 함께 돌려준다
 *
 * ★ 본문은 **JSON 원문 그대로** 나간다. 서버가 HTML 로 바꾸지 않는다 — 그리는 일은 화면의
 *   렌더러가 하고, 그 렌더러는 `createElement`/`textContent` 만 쓴다 (C:T-15).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

$row = community_page_row($pid);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

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
    /* 아직 판정 규칙이 없는 조합이다 — 게시판 공간(5단계) · `published` 범위(5단계) ·
       내가 팀원이 아닌 팀 공간(`not_member`). 판정은 이미 「막는다」로 끝났고, 그 조합의
       감사 문장은 그 단계에 함께 들어온다. 문장 틀이 없는 조합을 억지로 기록하면 요청이
       500 으로 끝나므로(문장 없는 기록은 예외다), 서버 로그에만 남기고 403 동일 문구로 답한다. */
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.read deny code=' . $readCode . ' (문장 없는 조합)');
    Http::error(403, Http::MSG_FORBIDDEN);
}

/* 남의 개인 페이지를 **무엇을 근거로** 열었나를 남긴다 (PRD 4.1, C:T-30).
     `admin_all` 관리자 권한으로 (사용자 결정 2026-09-22 「회사 자료라 관리자는 볼 수 있다」)
     `same_team` 주인과 같은 팀이라서 (4-B)
   자기 페이지를 여는 것은 일상이라 남기지 않는다 — 그것까지 남기면 진짜 사건이 묻힌다.
   `matrix`(사내 공개)도 남기지 않는다: 사내 전원이 대상이라 모든 열람이 행이 된다. */
if ($readCode === CommunityAuthz::CODE_ADMIN_ALL || $readCode === CommunityAuthz::CODE_SAME_TEAM) {
    Audit::allow('page.read', [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => community_target_title((string) $row['title']),
        'detail'      => [
            'granted_by' => $readCode,
            'page_title' => community_target_title((string) $row['title']),
        ],
    ], $readCode);
}

[$mayChange, $changeCode] = CommunityAuthz::decidePageChange($user, $row);

/* 「공개 범위」 단추를 열지 말지 고르는 값 (4-B). 개인 공간의 **주인**일 때만 참이다 —
   팀 공간의 페이지는 자기 범위가 없고(공간 등급을 따른다), 남의 페이지는 볼 수 있어도
   범위를 바꾸지 못한다. 판단 근거가 아니라 **서버가 이미 한 판단의 결과**다: 단추를
   개발자도구로 되살려 창구를 불러도 `pages_visibility.php` 가 같은 함수로 다시 막는다. */
$maySetVisibility = $mayChange
    && $changeCode === CommunityAuthz::CODE_OWNER
    && (string) $row['space_kind'] === 'personal';

/* 경로(breadcrumb) — 뿌리부터 **자기 자신까지**. 재귀 CTE **한 번**이다 (4-A).
   조상은 전부 같은 개인 공간의 행이라 이 페이지와 판정이 같다 — 볼 수 있는 페이지의 조상은
   볼 수 있다. 지워진 조상에서 CTE 가 멈추므로 그 너머는 경로에 나오지 않는다. */
$breadcrumb = community_breadcrumb((int) $row['id'], (int) $row['space_id']);

// 바로 위 하나 — 3단계부터 쓰던 칸이라 그대로 둔다(경로의 끝에서 둘째와 같은 값이다).
$parent = count($breadcrumb) >= 2
    ? [
        'id'    => $breadcrumb[count($breadcrumb) - 2]['id'],
        'title' => $breadcrumb[count($breadcrumb) - 2]['title'],
    ]
    : null;

/* 하위 페이지 — 제목·아이콘과 **그 아래 하위 수**까지 한 질의로(N+1 금지).
   4-B: 자식은 부모보다 **좁을 수 있으므로**(팀 페이지 아래의 비공개 페이지) 볼 수 있는
   것만 SQL `WHERE` 로 거른다. 거르는 일을 화면에 미루지 않는다 (C:S-02 · C:T-37). */
$children = community_children((int) $row['id'], $user);

/* 「지금 이 페이지를 편집 중인 사람」 — 30초 heartbeat 가 민 시각이 2분 안이면 보인다.
   나 자신은 빼고 보여 준다(내가 열어 둔 것은 알려 줄 일이 아니다). */
$sqlEditors = <<<'SQL'
    SELECT u.username, e.last_seen_at
      FROM community.page_edit_sessions e
      JOIN public.users u ON u.id = e.user_id
     WHERE e.page_id = CAST(:pid AS bigint)
       AND e.user_id <> CAST(:me AS bigint)
       AND e.last_seen_at > now() - interval '2 minutes'
     ORDER BY e.last_seen_at DESC
     LIMIT 20
    SQL;
$editors = [];
foreach ($db->run($sqlEditors, [':pid' => (int) $row['id'], ':me' => $me])->fetchAll() as $e) {
    $editors[] = ['username' => (string) $e['username'], 'last_seen_at' => (string) $e['last_seen_at']];
}

// `doc` 은 jsonb 라 PDO 가 문자열로 돌려준다. 응답 본문에 **객체 그대로** 싣기 위해 한 번 푼다.
$doc = json_decode((string) $row['doc'], true);
if (!is_array($doc)) {
    throw new RuntimeException('저장된 본문을 읽을 수 없습니다');
}

/* ── `child_page` 블록이 가리키는 페이지들의 제목 (4-A, C:T-28) ──
 *
 * ★ **읽기 판정은 여기서** 한다 — 저장 때가 아니다(`Doc::validate` 는 DB 를 읽지 않는다).
 *   질의에 4-B 의 **볼 수 있는 행 조건절**이 그대로 붙으므로 볼 수 없는 페이지의 제목은
 *   결과에 아예 없다. 없는 id 는 지도에 자리가 없고, 렌더러 두 벌이 그 자리에 「볼 수 없는
 *   페이지」를 그린다. 화면이 판단하지 않고, 서버가 준 지도에 있나 없나만 본다 (DESIGN 원칙 1).
 * ★ `IN` 자리표시자 이름은 **개수로 만든 코드 문자열**(`:c0`, `:c1` …)이고 요청 값이 SQL 에
 *   한 글자도 들어가지 않는다. 값은 전부 바인딩된다 (PRD S-04).
 */
[$visibleWhere, $visibleBind] = community_visible_pages_where($user);

$childTitles = [];
$names       = [];
$bind        = array_merge([':space' => (int) $row['space_id']], $visibleBind);
foreach (array_slice(Doc::extract($doc)['child_pages'], 0, 200) as $uuid) {
    // 형태가 아닌 값은 질의에 넣지 않는다 — `uuid` 칸에 닿으면 DB 가 예외를 던진다.
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) !== 1) {
        continue;
    }
    $name        = ':c' . (string) count($names);
    $names[]     = 'CAST(' . $name . ' AS uuid)';
    $bind[$name] = $uuid;
}
if ($names !== []) {
    $sqlTitles = 'SELECT p.public_id, p.title, p.icon'
        . ' FROM community.pages p'
        . ' JOIN community.spaces s ON s.id = p.space_id'
        . ' LEFT JOIN public.users owner ON owner.id = s.owner_user_id'
        . ' WHERE p.space_id = CAST(:space AS bigint)'
        . ' AND p.deleted_at IS NULL'
        . ' AND ' . $visibleWhere
        . ' AND p.public_id IN (' . implode(', ', $names) . ')';
    foreach ($db->run($sqlTitles, $bind)->fetchAll() as $t) {
        $childTitles[(string) $t['public_id']] = [
            'title' => (string) $t['title'],
            'icon'  => $t['icon'] === null ? null : (string) $t['icon'],
        ];
    }
}

Http::json([
    'page' => [
        'id'          => (string) $row['public_id'],
        'title'       => (string) $row['title'],
        'icon'        => $row['icon'] === null ? null : (string) $row['icon'],
        'doc'         => $doc,
        'doc_version' => (int) $row['doc_version'],
        'block_count' => (int) $row['block_count'],
        'visibility'  => (string) $row['visibility'],
        'font'        => (string) $row['font'],
        'small_text'  => $row['small_text'] === true,
        'full_width'  => $row['full_width'] === true,
        'parent'      => $parent,
        'position'    => (int) $row['position'],
        'created_at'  => (string) $row['created_at'],
        'updated_at'  => (string) $row['updated_at'],
        /* 4-D 고정 속성 둘 (C:F-37). 둘 다 **없음(null)이 기본**이다.
           담당자는 계정 id 가 아니라 **계정명·역할**로 나간다 — 화면이 id 를 이름으로
           바꾸는 일이 없고, 순차 정수가 응답에 실리지도 않는다. */
        'status'      => $row['status'] === null ? null : (string) $row['status'],
        'assignee'    => $row['assignee_name'] === null ? null : [
            'username' => (string) $row['assignee_name'],
            'role'     => (string) $row['assignee_role'],
            // 담당자였던 사람이 그 뒤 계정이 내려갔을 수 있다 — 그 사실을 숨기지 않는다.
            'is_active' => $row['assignee_active'] === true,
        ],
    ],
    // 공간 요약 (4-B) — 화면의 공간 고르개·배지가 「여기가 어느 공간인가」를 아는 값이다.
    'space' => [
        'id'      => (int) $row['space_id'],
        'name'    => (string) $row['space_name'],
        'kind'    => (string) $row['space_kind'],
        'team_id' => $row['space_team_id'] === null ? null : (int) $row['space_team_id'],
    ],
    // 화면이 「편집」 단추를 열지 말지 고르는 값이다 — 판단 근거가 아니다 (DESIGN 원칙 1).
    // 단추를 되살려 PATCH 를 불러도 서버가 같은 함수로 다시 막는다.
    'can_edit'   => $mayChange,
    // 4-B — 「공개 범위」 단추를 보일지. 값은 위 판정의 결과이고 화면은 그대로 따른다.
    'can_set_visibility' => $maySetVisibility,
    /* 4-D — 상태 배지·담당자 칩을 **누를 수 있게** 할지. 고칠 수 있는 사람과 같은 자격이다
       (`decidePageChange`) — 창구 `PATCH …/properties` 가 같은 함수로 다시 막는다 (C:T-51). */
    'can_set_properties' => $mayChange,
    'granted_by' => $readCode,
    // 4-A — 뿌리부터 자기 자신까지의 경로. 길이가 곧 깊이다(뿌리는 1).
    'breadcrumb' => $breadcrumb,
    'children'   => $children,
    /* 4-A — 본문의 `child_page` 블록이 가리키는 페이지들의 제목 지도.
       여기에 없는 id 는 렌더러가 「볼 수 없는 페이지」로 그린다 (C:T-28). */
    'child_titles' => $childTitles === [] ? new stdClass() : $childTitles,
    'editors'    => $editors,
]);
