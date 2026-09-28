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

$row = community_page_row($pid, (int) $user['id']);
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
/* 5-C — 게시판 관리자가 **숨긴 게시 페이지**(원래 범위로는 못 보는 남의 개인 페이지)를 연 일도 남긴다. */
if ($readCode === CommunityAuthz::CODE_ADMIN_ALL || $readCode === CommunityAuthz::CODE_SAME_TEAM
    || ($readCode === CommunityAuthz::CODE_BOARD_MODERATOR && (string) $row['space_kind'] === 'personal')) {
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

/* 5-C 조회수 — 읽기 판정을 **지난 뒤에만** 센다(못 보는 사람의 시도는 세지 않는다). 한 사람 하루 한 줄
   (`page_views` 기본키) — 같은 날 여러 번 열어도 1 이다. 세는 일이 실패해도 읽기는 막지 않는다 —
   조회수는 곁가지 수치이고, 그 때문에 페이지를 못 여는 것이 더 나쁘다(서버 로그에만 남긴다). */
try {
    community_record_view((int) $row['id'], $me);
} catch (Throwable $e) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community page view count failed: ' . get_class($e));
}
/* 개발 대기열 6 · Q:F-3a 라운드 A — 「최근」 한 줄(지시서 1.2). 조회수와 **같은 자리**(읽기 판정을 지난 뒤)이고 같은 태도다 —
   실패해도 읽기는 막지 않는다. 5분에 한 번만 시각을 밀고, 사람당 50줄을 넘으면 오래된 것부터 지운다(`community_record_recent`).
   라운드 B — 5분 안이면 읽기만 하고 끝(쓰기·잠금 0) · 「최근」 목록과 같은 조건절 안의 페이지만 적는다(관리자 특권 열람은 안 적힘). */
try {
    community_record_recent((int) $row['id'], $user);
} catch (Throwable $e) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community recent record failed: ' . get_class($e));
}

/* 5-D 공지 읽음 — 공지 게시판에 걸린 글을 연 일(한 사람 한 줄, 마지막 읽은 때만 민다). 숨긴 글을 관리자가
   여는 것은 「읽음」이 아니다(게시판 등급으로 연 사람만 센다). 실패해도 읽기는 막지 않는다. */
$noticeBoard = CommunityAuthz::boardOfRow($row);
if ($noticeBoard !== null && (string) $noticeBoard['board_kind'] === 'notice' && $row['hidden_at'] === null) {
    try {
        community_record_read((int) $row['id'], $me);
    } catch (Throwable $e) {
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community page read receipt failed: ' . get_class($e));
    }
}

[$mayChange, $changeCode] = CommunityAuthz::decidePageChange($user, $row);
/* 5-A — 「관리」(삭제·옮기기·공개 범위·공유)는 따로 묻는다. 편집 공유자는 `can_edit` 은 참이어도
   여기가 거짓이다 — 화면이 삭제·옮기기·공개 범위 단추를 그 값으로 가린다. */
[$mayManage, $manageCode] = CommunityAuthz::decidePageManage($user, $row);
// 인계를 시작할 수 있나 — 소유자 · 자기 팀원 것의 Manager · Admin(재인증은 창구가 다시 묻는다).
[$mayHandover] = CommunityAuthz::decidePageHandover($user, $row);

/* 디버깅 묶음 S2 (보안 검토 낮음) — 손님(Guest)은 커뮤니티 쓰기 창구에 닿지 못한다(라우트 정책 `community.write` →
   `Authz::canCommunityWrite`). 그런데 위 판정은 개인 공간의 **주인**을 역할과 상관없이 참으로 봐서, 손님 자기 공간의
   「시작하기」 페이지에서 `can_edit`·`can_manage` 등이 참으로 나갔다(창구는 403 — 권한 구멍은 아니고 값이 어긋남).
   화면에 내보내는 쓰기 값은 전부 **같은 함수와 AND** 한다 — 판정 함수(CommunityAuthz)는 그대로 두고 창구 값만 맞춘다. */
$mayWrite    = Authz::canCommunityWrite($user);
$mayChange   = $mayChange && $mayWrite;
$mayManage   = $mayManage && $mayWrite;
$mayHandover = $mayHandover && $mayWrite;

/* 「공개 범위」 단추를 열지 말지 고르는 값 (4-B). 개인 공간의 **주인**일 때만 참이다 —
   팀 공간의 페이지는 자기 범위가 없고(공간 등급을 따른다), 남의 페이지는 볼 수 있어도
   범위를 바꾸지 못한다. 판단 근거가 아니라 **서버가 이미 한 판단의 결과**다: 단추를
   개발자도구로 되살려 창구를 불러도 `pages_visibility.php` 가 같은 함수로 다시 막는다. */
$maySetVisibility = $mayManage
    && $manageCode === CommunityAuthz::CODE_OWNER
    && (string) $row['space_kind'] === 'personal'
    // 5-C — 게시 중에는 범위를 바꾸지 않는다(먼저 해제 — `pages_visibility.php` [3-1]).
    && (string) $row['visibility'] !== 'published';

/* 5-C 게시판 — 이 페이지가 걸린 게시판(게시판 글이면 그 공간, 게시된 개인 페이지면 게시한 게시판)과
   화면 단추 네 개의 값. 전부 서버가 이미 한 판단의 결과이고, 창구가 같은 함수로 다시 막는다. */
$boardOfPage   = CommunityAuthz::boardOfRow($row);
/* 게시판 이름·등급·번호는 **그 게시판을 볼 수 있는 사람**(또는 쓴 사람·주인·그 게시판 관리자)에게만 싣는다 —
   원래 범위나 공유로 게시된 페이지를 여는 사람에게 못 읽는 게시판의 이름이 새지 않게(독립 검토 2026-09-23). */
if ($boardOfPage !== null
    && !CommunityAuthz::decideBoardRead($user, $boardOfPage)[0]
    && !CommunityAuthz::decideBoardModerate($user, $boardOfPage)[0]
    && !CommunityAuthz::decideUnpublish($user, $row)[0]
    && $readCode !== CommunityAuthz::CODE_OWNER
) {
    $boardOfPage = null;
}
[$mayPublish]  = CommunityAuthz::decidePublish($user, $row);
[$mayUnpublish] = (string) $row['visibility'] === 'published'
    ? CommunityAuthz::decideUnpublish($user, $row) : [false, ''];
[$mayHide]     = $boardOfPage === null ? [false, ''] : CommunityAuthz::decideHide($user, $row);
$isQa          = $boardOfPage !== null && (string) $boardOfPage['board_kind'] === 'qa';
[$mayResolveBase, $resolveCode] = CommunityAuthz::decidePageManage($user, $row);
$mayResolve    = $isQa && $mayResolveBase && $resolveCode === CommunityAuthz::CODE_OWNER;
// S2 — 게시·게시 해제·숨기기·해결됨도 쓰기 창구다(위 $mayWrite 와 같은 AND)
$mayPublish    = $mayPublish && $mayWrite;
$mayUnpublish  = $mayUnpublish && $mayWrite;
$mayHide       = $mayHide && $mayWrite;
$mayResolve    = $mayResolve && $mayWrite;

/* 5-A 인계 창의 「하위 페이지 n개도 함께 옮겨 갑니다」 — 넘길 수 있는 사람에게만 센다.
   재귀 CTE 한 번(`community_subtree_live_ids`)이고, 자기 자신을 뺀 수다. */
$subtreeCount = $mayHandover ? max(0, count(community_subtree_live_ids((int) $row['id'])) - 1) : null;

/* 경로(breadcrumb) — 뿌리부터 **자기 자신까지**. 재귀 CTE **한 번**이다 (4-A).
   5-A — 공유받은 사람은 조상을 못 볼 수 있다(비공개 부모 아래의 페이지 하나만 받았을 때).
   그래서 보는 사람을 넘겨 **볼 수 있는 조상까지만** 잇는다 — 공유하지 않은 제목이 새지 않는다. */
$breadcrumb = community_breadcrumb((int) $row['id'], (int) $row['space_id'], $user);

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
    SELECT u.username, u.display_name, e.last_seen_at
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
    $editors[] = [
        'username'     => (string) $e['username'],
        'display_name' => $e['display_name'] === null ? null : (string) $e['display_name'],
        'last_seen_at' => (string) $e['last_seen_at'],
    ];
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
$docExtract = Doc::extract($doc);   // 5-E — 파일·링크 지도에 쓴다(아래 하위 페이지 지도와 같은 한 번의 추출)

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

/* 7-B — 이 페이지를 **어느 범위의 템플릿으로** 저장할 수 있나(「템플릿으로 저장」 창의 고르개). 판정은 저장 창구와 같은
   `decideTemplateSave` 한 곳이고, 여기서는 되는 범위의 이름만 싣는다 — 화면이 역할·공간을 따지지 않는다. */
$tplDbPage = null;
if ($row['database_id'] !== null && $row['db_public_id'] !== null) {
    $tplDbInfo = community_db_row((string) $row['db_public_id']);
    $tplDbPage = $tplDbInfo === null ? null : community_page_row((string) $tplDbInfo['page_public_id'], $me);
}
$templateScopes = [];
foreach (CommunityAuthz::TEMPLATE_SCOPES as $tplScope) {
    // S2 — 템플릿 저장(`POST /api/templates`)도 쓰기 창구다
    if ($mayWrite && CommunityAuthz::decideTemplateSave($user, $row, $tplScope, $tplDbPage)[0]) {
        $templateScopes[] = $tplScope;
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
        /* 5-A 「소유자」 — 이 페이지가 든 **공간의 주인**이다(새 칸이 아니다). 팀 공간은 사람이
           아니라 「팀」이 주인이므로 `kind: team` 과 공간 이름만 보인다. 계정 id 는 나가지 않는다. */
        'owner'       => (string) $row['space_kind'] === 'personal'
            ? [
                'kind'      => 'personal',
                'username'  => (string) $row['space_owner_name'],
                // 5-A″ — 이름(없으면 null). 「이름 (아이디)」는 화면이 만든다.
                'display_name' => $row['space_owner_display_name'] === null ? null : (string) $row['space_owner_display_name'],
                'is_active' => $row['space_owner_active'] === true,
            ]
            : ['kind' => (string) $row['space_kind'], 'name' => (string) $row['space_name']],
        // 4-D 담당자 칸의 **옛 값**(읽기만). 5-A 부터 새로 지정하지 않는다 — 화면은 이 값을 그리지 않는다.
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
    /* 5-A — 「관리」 단추(삭제·옮기기)·「사람 추가」·「넘기기」를 보일지. 셋 다 서버가 이미 한
       판단의 결과이고, 되살려 눌러도 창구가 같은 함수로 다시 막는다 (C:T-53). */
    'can_manage'   => $mayManage,
    // 5-A″ — 팀 공간도 공유한다(관리 자격 = 그 팀원). 공개 범위는 여전히 개인 공간만이다.
    // 5-C — 게시판 글은 공유가 없다(`decidePageShare` — 게시판 등급이 곧 범위).
    'can_share'    => $mayWrite && CommunityAuthz::decidePageShare($user, $row)[0],
    'can_handover' => $mayHandover,
    // 인계 창의 안내 — 함께 옮겨 갈 하위 페이지 수(넘길 수 없는 사람에게는 null).
    'subtree_count' => $subtreeCount,
    // 나에게 준 공유 등급(`view`·`edit`, 이 페이지나 조상에서 가장 강한 것). 없으면 null.
    'share_level'  => CommunityAuthz::shareLevelFor($user, $row),
    'granted_by' => $readCode,
    // 4-A — 뿌리부터 자기 자신까지의 경로. 길이가 곧 깊이다(뿌리는 1).
    'breadcrumb' => $breadcrumb,
    'children'   => $children,
    /* 4-A — 본문의 `child_page` 블록이 가리키는 페이지들의 제목 지도.
       여기에 없는 id 는 렌더러가 「볼 수 없는 페이지」로 그린다 (C:T-28). */
    'child_titles' => $childTitles === [] ? new stdClass() : $childTitles,
    'editors'    => $editors,
    /* 5-C 게시판 · 태그 · 조회수.
       `board` — 이 페이지가 걸린 게시판(없으면 null). `kind` 는 `post`(게시판에서 쓴 글)·`published`(게시한 개인 페이지).
       `writer` — 게시판 글의 쓴 사람(아이디·이름). 개인 페이지는 `page.owner` 가 곧 쓴 사람이다. */
    'board' => $boardOfPage === null ? null : [
        'id'           => (int) $boardOfPage['id'],
        'name'         => (string) $row['space_kind'] === 'board' ? (string) $row['space_name'] : (string) ($row['pub_name'] ?? ''),
        'access_level' => (string) $boardOfPage['access_level'],
        'board_kind'   => (string) $boardOfPage['board_kind'],
        'is_active'    => $boardOfPage['is_active'] === true,
        'kind'         => (string) $row['space_kind'] === 'board' ? 'post' : 'published',
        'published_at' => $row['published_at'] === null ? null : (string) $row['published_at'],
        'hidden'       => $row['hidden_at'] !== null,
    ],
    'writer' => (string) $row['space_kind'] === 'board' && $row['author_name'] !== null ? [
        'username'     => (string) $row['author_name'],
        'display_name' => $row['author_display_name'] === null ? null : (string) $row['author_display_name'],
    ] : null,
    'published_from' => $row['published_from'] === null ? null : (string) $row['published_from'],
    'tags'           => community_page_tags((int) $row['id']),
    'view_count'     => community_view_count((int) $row['id']),
    'resolved'       => ($row['resolved'] ?? false) === true,
    'can_tag'        => $mayChange,
    'can_publish'    => $mayPublish && (string) $row['visibility'] !== 'published' && $row['dbself_public_id'] === null && $row['database_id'] === null,
    'can_unpublish'  => $mayUnpublish,
    'can_hide'       => $mayHide,
    'can_resolve'    => $mayResolve,
    /* 5-E — 본문의 그림·파일 지도(보는 사람이 t-box 에서 볼 수 있는 파일만) · 페이지 링크 제목 지도(볼 수 있는 페이지만) ·
       역참조(이 페이지를 가리키는 볼 수 있는 페이지). 지도에 없는 것은 렌더러가 「볼 수 없는 …」 한 장으로 그린다. */
    'file_titles' => ($ft = community_file_titles($docExtract['files'], $user)) === [] ? new stdClass() : $ft,
    'link_titles' => ($lt = community_link_titles(array_map(static fn (array $l): string => $l['to_page_id'], $docExtract['links']), $user)) === [] ? new stdClass() : $lt,
    'backlinks'   => community_backlinks((string) $row['public_id'], (int) $row['id'], $user),
    /* 7-D4 — 글 안 멘션의 이름 지도(볼 수 있는 것만 · 사람은 쓰기 자격 있는 보는 사람에게만 — `community_mention_labels`). 없으면 {} */
    'mention_labels' => ($ml = community_mention_labels($docExtract['mentions'], $user)) === [] ? new stdClass() : $ml,
    // 5-D — 댓글을 쓸 수 있나 · 공지 「읽은 사람 n/N」을 볼 수 있나(둘 다 창구가 같은 함수로 다시 막는다)
    /* 6-A — DB 페이지면 `database`(스키마·자격 — 행 목록은 `GET /api/databases/{id}/rows`),
       행이면 `row`(속한 DB · 스키마 · 값 · 고칠 수 있나). 둘 다 아니면 null. */
    'database' => $row['dbself_public_id'] === null ? null : [
        'id'                => (string) $row['dbself_public_id'],
        'schema'            => community_db_schema_of($row['dbself_schema']),
        'can_add_rows'      => $mayChange,
        'can_manage_schema' => $mayManage,
    ],
    'row' => $row['database_id'] === null ? null : [
        'database_id' => (string) $row['db_public_id'],
        'schema'      => ($rowSchema = community_db_schema_of($row['db_schema'])),
        'values'      => ($rowVals = community_db_values_out($rowSchema, $row['properties'])),
        'can_edit'    => $mayChange,
        // 6-B — 관계·파일 값의 제목(보는 사람 권한으로 거른 것만)
        'relation_titles' => (($rt = community_db_ref_titles($rowSchema, [$rowVals], $user))[0] === [] ? new stdClass() : $rt[0]),
        'file_titles'     => ($rt[1] === [] ? new stdClass() : $rt[1]),
    ],
    'can_comment'    => $mayWrite && CommunityAuthz::decideCommentCreate($user, $row)[0],
    'can_view_reads' => CommunityAuthz::decideReadsView($user, $row)[0],
    'template_scopes' => $templateScopes,
    // 7-C — 렌더러 두 벌의 임베드 재료: 지금 허용 목록 · 틀을 여는가(코드 상수 — 끄면 모든 임베드가 「허용 대기」)
    'embeds' => community_embed_ctx(),
]);
