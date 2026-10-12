<?php
declare(strict_types=1);

/**
 * src/community/handlers/favorites.php — 페이지 즐겨찾기 (정책: community.read — 개발 대기열 6 · Q:F-3a 라운드 A)
 *
 *   GET    /community/api/me/favorites          내 즐겨찾기 — 사이드바 맨 위 「즐겨찾기」(결정 M-1). **지금 볼 수 있는 것만**
 *   PATCH  /community/api/me/favorites          순서 바꾸기 `{ids:[페이지 id…]}` — 끌어서 놓은 뒤의 순서(내 줄의 position 만)
 *   PUT    /community/api/pages/{id}/favorite   넣기 — 그 순간 읽을 수 있어야 한다(`CommunityAuthz::decidePageRead`)
 *   DELETE /community/api/pages/{id}/favorite   빼기 — 내 줄만
 *
 * 요구사항: 지시서 `/var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md` 1.1 · 결정 M-1 · M-2 · 3장 QF3A-T1~T3,
 *           커뮤니티 C:S-02(못 보는 것은 목록에 없다) · 커뮤니티 ARCH 21.24.
 *
 * ★ 새 판정 코드 0. 넣기는 페이지 읽기와 **같은 함수**(`decidePageRead`), 목록은 **검색과 같은 조건절**
 *   (`community_search_pages_where` — 관리자 특권·게시판 관리자 갈래를 끈 것) + 지운 페이지 제외 + 숨긴 게시판 글 제외(검색과 같다).
 *   그래서 주인이 비공개로 바꾸거나 휴지통에 넣은 페이지는 목록 SQL 이 애초에 돌려주지 않는다 — 제목·id·개수 어느 것도 나가지
 *   않는다(QF3A-T2). 관리자가 `admin_all` 로 연 남의 비공개 페이지는 넣을 수는 있어도 목록에는 나오지 않는다(QF3A-T3).
 * ★ 없는 페이지 · 지운 페이지 · 못 읽는 페이지는 **같은 404 동일 문구**다(지시서 1.1 「404 같은 문구」). 감사에 남기지 않는다
 *   (결정 M-2 — 개인 화면 설정). 거부도 남기지 않는다 — 이 창구는 제목·본문·존재 어느 것도 돌려주지 않는다.
 * ★ 대상은 언제나 세션의 본인이다. 쿼리·본문에서 사람을 받지 않는다(순서 바꾸기의 id 목록은 **내 줄**의 순서일 뿐이다).
 * ★ 한도: 사람당 200. 가득 찼으면 지금 목록에 안 나오는 내 줄을 먼저 걷은 뒤 다시 센다(구현 기록 결정 D-5). 그래도 200 이면 409.
 *   라운드 B: 한 트랜잭션 + 이 사람 advisory 잠금(한도·순서가 동시 넣기에 어긋나지 않게 — L-2) · 「역할로 보기」 중에는
 *   걷지 않는다(L-1).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params PUT·DELETE: [0] = 페이지 public_id · GET·PATCH: 없음
 */

$db     = Db::instance();
$me     = (int) $user['id'];
$method = Http::method();
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

/* 목록 조건 — 별칭 p(페이지)·s(공간)·owner(개인 공간 주인)를 전제로 하는 검색 조건절을 **글자 그대로** 붙인다(search.php 와 같다).
   아래 두 조건은 검색 문장(`community_search_sql`)이 조건절 옆에 붙이는 것과 같은 두 줄이다. */
$sqlListHead = <<<'SQL'
    SELECT p.public_id, p.title, p.icon, s.kind AS space_kind, s.name AS space_name,
           p.author_id, s.owner_user_id AS space_owner_user_id, s.team_id AS space_team_id,
           s.is_active AS space_is_active,
           f.position, f.created_at AS favorited_at
      FROM community.page_favorites f
      JOIN community.pages p ON p.id = f.page_id
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
     WHERE f.user_id = CAST(:fav_me AS bigint)
       AND p.deleted_at IS NULL
       AND NOT (s.kind = 'board' AND p.hidden_at IS NOT NULL)
       AND
    SQL;

if ($method === 'GET') {
    [$visible, $bind] = community_search_pages_where($user);
    $bind[':fav_me'] = $me;
    $sqlTail = ' ORDER BY f.position ASC, f.created_at ASC, p.id ASC LIMIT 200';
    $pages = [];
    foreach ($db->run($sqlListHead . ' ' . $visible . $sqlTail, $bind)->fetchAll() as $r) {
        $pages[] = [
            'id'    => (string) $r['public_id'],
            'title' => (string) $r['title'],
            'icon'  => $r['icon'] === null ? null : (string) $r['icon'],
            'space' => ['kind' => (string) $r['space_kind'], 'name' => (string) $r['space_name']],
            'can_delete' => Authz::canCommunityWrite($user) && CommunityAuthz::decidePageManage($user, $r)[0],
        ];
    }
    // ★ 전체 개수 칸이 없다 — 목록에 안 나오는 즐겨찾기의 수가 새지 않게(지시서 2장 금지 패턴).
    Http::json(['pages' => $pages, 'limit' => COMMUNITY_FAVORITES_MAX]);
    return;
}

if ($method === 'PATCH') {
    // 순서 바꾸기 — `{ids: [...]}` 는 **내 줄**의 새 순서다. 모양이 아니면 400(고칠 수 있는 요청 오류).
    $in  = Http::jsonBody();
    $ids = $in['ids'] ?? null;
    if (!is_array($ids) || !array_is_list($ids) || count($ids) > COMMUNITY_FAVORITES_MAX || array_keys($in) !== ['ids']) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $clean = [];
    foreach ($ids as $id) {
        if (!is_string($id) || preg_match($uuidRe, $id) !== 1 || in_array($id, $clean, true)) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $clean[] = $id;
    }
    if ($clean !== []) {
        /* 목록의 n 번째 페이지 → position n. 값은 쉼표로 이은 글자 하나로 바인딩하고 DB 가 uuid 배열로 바꾼다(각 값은 위에서
           UUID 모양만 통과했다). 내 줄(`user_id = 나`)만 바뀐다 — 목록에 없는 내 줄(지금 못 보는 것)은 그대로 둔다. */
        $sqlOrder = <<<'SQL'
            UPDATE community.page_favorites f
               SET position = CAST(x.ord AS integer)
              FROM (SELECT p.id, t.ord
                      FROM unnest(CAST(string_to_array(CAST(:ids AS text), ',') AS uuid[])) WITH ORDINALITY AS t(pid, ord)
                      JOIN community.pages p ON p.public_id = t.pid) x
             WHERE f.user_id = CAST(:me AS bigint)
               AND f.page_id = x.id
            SQL;
        $db->run($sqlOrder, [':ids' => implode(',', $clean), ':me' => $me]);
    }
    Http::json(['ok' => true]);
    return;
}

// ─── PUT · DELETE — 페이지 하나 ─────────────────────────────────
$pid = (string) ($params[0] ?? '');
if (preg_match($uuidRe, $pid) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

if ($method === 'DELETE') {
    $sqlDelete = <<<'SQL'
        DELETE FROM community.page_favorites f
         USING community.pages p
         WHERE p.id = f.page_id
           AND p.public_id = CAST(:pid AS uuid)
           AND f.user_id = CAST(:me AS bigint)
        SQL;
    $db->run($sqlDelete, [':pid' => $pid, ':me' => $me]);
    Http::json(['ok' => true, 'favorite' => false]);
    return;
}

// PUT — 넣기. 페이지 읽기(`pages_get.php`)와 같은 행·같은 판정. 본문은 읽지 않는다(`_nodoc`).
$row = community_page_row_nodoc($pid, $me);
if ($row === null || !CommunityAuthz::decidePageRead($user, $row)[0]) {
    // 없음·지움(not_found)·못 읽음 — 같은 404 동일 문구. 감사 없음(결정 M-2).
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageId = (int) $row['id'];

/* 라운드 B (라운드 A 독립 검토 L-2) — 「있나 → 몇 개·마지막 순서 → 걷기 → 넣기」를 **한 사람 한 줄로** 세운다. 동시에 넣으면
   둘 다 199 를 세고 둘 다 넣어 한도를 넘거나, 둘 다 같은 「마지막 순서 + 1」을 받아 순서가 겹쳤다(일괄 즐겨찾기 · 탭 둘).
   트랜잭션 안에서 이 사람·이 표의 advisory 잠금을 먼저 잡는다(t-box 즐겨찾기와 같은 방식 — 표마다 열쇠가 다르다). */
$sqlLock = <<<'SQL'
    SELECT pg_advisory_xact_lock(hashtext('community.page_favorites'), CAST(:me_lock AS integer))
    SQL;
$sqlHave = <<<'SQL'
    SELECT 1 FROM community.page_favorites WHERE user_id = CAST(:me AS bigint) AND page_id = CAST(:page AS bigint)
    SQL;
$sqlCount = <<<'SQL'
    SELECT count(*) AS n, coalesce(max(position), 0) AS last_pos
      FROM community.page_favorites
     WHERE user_id = CAST(:me AS bigint)
    SQL;
// 새 줄은 맨 뒤(지금 가장 큰 순서 + 1). 상한은 표 CHECK(0~100000) 안에서 멈춘다.
$sqlInsert = <<<'SQL'
    INSERT INTO community.page_favorites (user_id, page_id, position)
    VALUES (CAST(:me AS bigint), CAST(:page AS bigint), CAST(:pos AS integer))
    ON CONFLICT DO NOTHING
    SQL;
/* 라운드 B (L-1) — 「역할로 보기」 중이면 걷지 않는다. 걷는 조건절(`community_search_pages_where`)은 흉내 낸 역할로 만들어지므로
   관리자 자신의 줄(그 역할로는 목록에 안 나오는 페이지)을 지웠다. 그 동안은 세기만 하고 가득이면 409. */
$acting = ($user['acting'] ?? false) === true;

$db->begin();
try {
    $db->run($sqlLock, [':me_lock' => $me % 2147483647]);
    if ($db->run($sqlHave, [':me' => $me, ':page' => $pageId])->fetch() === false) {
        $stat = $db->run($sqlCount, [':me' => $me])->fetch();
        if ((int) ($stat['n'] ?? 0) >= COMMUNITY_FAVORITES_MAX && !$acting) {
            // 가득 찼다 — 지금 목록에 안 나오는 내 줄(비공개로 바뀜·휴지통·숨긴 글)을 먼저 걷는다. 조건은 목록과 같은 조건절이다.
            [$visible, $pruneBind] = community_search_pages_where($user);
            $pruneBind[':fav_me'] = $me;
            $sqlPrune = <<<'SQL'
                DELETE FROM community.page_favorites f
                 WHERE f.user_id = CAST(:fav_me AS bigint)
                   AND NOT EXISTS (SELECT 1
                                     FROM community.pages p
                                     JOIN community.spaces s ON s.id = p.space_id
                                     LEFT JOIN public.users owner ON owner.id = s.owner_user_id
                                    WHERE p.id = f.page_id
                                      AND p.deleted_at IS NULL
                                      AND NOT (s.kind = 'board' AND p.hidden_at IS NOT NULL)
                                      AND
                SQL;
            $db->run($sqlPrune . ' ' . $visible . ')', $pruneBind);
            $stat = $db->run($sqlCount, [':me' => $me])->fetch();
        }
        if ((int) ($stat['n'] ?? 0) >= COMMUNITY_FAVORITES_MAX) {
            $db->rollback();
            Http::error(409, '즐겨찾기는 ' . COMMUNITY_FAVORITES_MAX . '개까지입니다 — 쓰지 않는 것을 먼저 빼 주세요');
        }
        $db->run($sqlInsert, [':me' => $me, ':page' => $pageId, ':pos' => min(100000, (int) ($stat['last_pos'] ?? 0) + 1)]);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 처리기 → 500
}

/* `listed` — 이 페이지가 **목록에 나오는가**(검색과 같은 조건절). 관리자가 `admin_all` 로 연 남의 비공개 페이지처럼 넣었어도
   목록에 안 나오는 경우를 화면이 알려 줄 수 있게(QF3A-T3). 값은 방금 읽기 판정을 지난 이 한 페이지에 대한 참·거짓뿐이다. */
[$visible, $bind] = community_search_pages_where($user);
$bind[':listed_page'] = $pageId;
$sqlListed = <<<'SQL'
    SELECT 1
      FROM community.pages p
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
     WHERE p.id = CAST(:listed_page AS bigint)
       AND p.deleted_at IS NULL
       AND NOT (s.kind = 'board' AND p.hidden_at IS NOT NULL)
       AND
    SQL;
$listed = $db->run($sqlListed . ' ' . $visible, $bind)->fetch() !== false;

Http::json(['ok' => true, 'favorite' => true, 'listed' => $listed]);
