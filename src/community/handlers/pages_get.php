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
    /* 3단계 데이터에는 없는 조합(팀 공간·게시판·공개 범위)이다. 판정은 이미 「막는다」로
       끝났고, 그 조합의 감사 문장은 4단계에 함께 들어온다. 문장 틀이 없는 조합을 억지로
       기록하면 요청이 500 으로 끝나므로(문장 없는 기록은 예외다), 여기서는 서버 로그에만
       남기고 같은 403 동일 문구로 답한다. */
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.read deny code=' . $readCode . ' (3단계에 없는 조합)');
    Http::error(403, Http::MSG_FORBIDDEN);
}

/* 사용자 결정 2026-09-22 (커뮤니티 PRD 4.1) — 관리자가 **남의** 개인 페이지를 연 일은
   남긴다. 자기 페이지를 여는 것은 일상이라 남기지 않는다(그것까지 남기면 진짜 사건이 묻힌다). */
if ($readCode === CommunityAuthz::CODE_ADMIN_ALL) {
    Audit::allow('page.read', [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => community_target_title((string) $row['title']),
        'detail'      => [
            'granted_by' => CommunityAuthz::CODE_ADMIN_ALL,
            'page_title' => community_target_title((string) $row['title']),
        ],
    ], CommunityAuthz::CODE_ADMIN_ALL);
}

[$mayChange] = CommunityAuthz::decidePageChange($user, $row);

// 부모 — 화면의 빵부스러기(breadcrumb)에 쓴다. 같은 공간 안이라 같은 판정을 받는다.
$parent = null;
if ($row['parent_page_id'] !== null) {
    $sqlParent = <<<'SQL'
        SELECT public_id, title FROM community.pages
         WHERE id = CAST(:pid AS bigint) AND deleted_at IS NULL
        SQL;
    $parentRow = $db->run($sqlParent, [':pid' => (int) $row['parent_page_id']])->fetch();
    if (is_array($parentRow)) {
        $parent = ['id' => (string) $parentRow['public_id'], 'title' => (string) $parentRow['title']];
    }
}

// 하위 페이지 — 볼 수 있는 페이지의 하위는 같은 공간 안이라 같은 판정을 받는다.
$sqlChildren = <<<'SQL'
    SELECT public_id, title, icon, updated_at
      FROM community.pages
     WHERE parent_page_id = CAST(:pid AS bigint) AND deleted_at IS NULL
     ORDER BY position, id
     LIMIT 200
    SQL;
$children = [];
foreach ($db->run($sqlChildren, [':pid' => (int) $row['id']])->fetchAll() as $c) {
    $children[] = [
        'id'         => (string) $c['public_id'],
        'title'      => (string) $c['title'],
        'icon'       => $c['icon'] === null ? null : (string) $c['icon'],
        'updated_at' => (string) $c['updated_at'],
    ];
}

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
        'created_at'  => (string) $row['created_at'],
        'updated_at'  => (string) $row['updated_at'],
    ],
    'space' => [
        'id'   => (int) $row['space_id'],
        'name' => (string) $row['space_name'],
        'kind' => (string) $row['space_kind'],
    ],
    // 화면이 「편집」 단추를 열지 말지 고르는 값이다 — 판단 근거가 아니다 (DESIGN 원칙 1).
    // 단추를 되살려 PATCH 를 불러도 서버가 같은 함수로 다시 막는다.
    'can_edit'   => $mayChange,
    'granted_by' => $readCode,
    'children'   => $children,
    'editors'    => $editors,
]);
