<?php
declare(strict_types=1);

/**
 * src/community/handlers/my_tasks.php — GET /community/api/my-tasks (정책: community.read)
 *
 * 요구사항: 사이트 Q:F-12 WK-4 · WK-8, 커뮤니티 `../../../../community/ARCHITECTURE.md` 23.4, SECURITY C:R-17,
 *           기획 11장 Q:T-29. 화면 `/community/my-tasks` 는 지시서 D 몫이다.
 *
 * 받는 값 (쿼리)
 *   done    `0`(기본 — 끝나지 않은 것) · `1`(끝난 것). 그 밖은 400
 *   bucket  `overdue`·`today`·`this_week`·`later`·`no_due` 중 하나 — 그 묶음만(「더 보기」). 그 밖은 400
 *   cursor  그 묶음 안에서 이미 받은 줄 수(응답의 `next_cursor` 그대로) — `bucket` 과 함께만. 0~10000
 *
 * 대상(ARCH 23.4): ① 담당이 나 ② 담당 없음 + 내 것(개인 공간 소유자 · 팀·게시판 작성자) + 상태 또는 기한이 있음.
 * ★ **보는 사람의 검색 조건절(`community_search_pages_where`) 안에서만** — Admin 의 관리자 열람 갈래·게시판 관리자 갈래가 꺼진
 *   판이라 남의 비공개 작업은 담당이 나여도, Admin 이어도 없다(C:R-17). 프로젝트 이름·하위 작업 수도 같은 조건절을 지난 것만.
 * ★ 판정이 없다 — 행 단위 허용·거부가 아니라 「볼 수 있는 것 중 내 것」을 고르는 목록이다(감사 없음, 읽기 목록 관례).
 *
 * 응답 200
 *   {"today":"Y-m-d","done":false,
 *    "buckets":[{"key":"overdue","name":"지난 기한","items":[<줄>…],"next_cursor":"100"|null}, …]}
 *   <줄> = {"public_id","title","status","due","project":{"public_id","title","color"}|null,
 *           "doc_space":"문서 · <공간 이름>"|null,"assigned_by":"<표시 이름>"|null,"comments_n","sub_done","sub_total"}
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$rawDone = $_GET['done'] ?? '0';
if (!is_string($rawDone) || !in_array($rawDone, ['0', '1'], true)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$done = $rawDone === '1';

$bucket = null;
if (array_key_exists('bucket', $_GET)) {
    $rawBucket = $_GET['bucket'];
    if (!is_string($rawBucket) || !array_key_exists($rawBucket, COMMUNITY_MY_TASK_BUCKETS)) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $bucket = $rawBucket;
}
$offset = 0;
if (array_key_exists('cursor', $_GET)) {
    $rawCursor = $_GET['cursor'];
    if ($bucket === null || !is_string($rawCursor) || preg_match('/^(0|[1-9][0-9]{0,4})$/', $rawCursor) !== 1
        || (int) $rawCursor > COMMUNITY_MY_TASK_CURSOR_MAX) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $offset = (int) $rawCursor;
}

// 「오늘」·「이번 주(일요일까지)」 — 서버가 Asia/Seoul 날짜로 가른다(요청이 날짜를 정하지 않는다).
$todayAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$today   = $todayAt->format('Y-m-d');
$sunday  = $todayAt->modify('+' . (7 - (int) $todayAt->format('N')) . ' days')->format('Y-m-d');

$db = Db::instance();
[$sql, $bind] = community_my_tasks_sql($user, $done, $today, $sunday, $bucket, $offset);
$rows = $db->run($sql, $bind)->fetchAll();

/* 덧붙일 값 넷 — 줄의 번호(DB 가 준 정수)만 모아 한 번씩 읽는다. 프로젝트·하위 작업은 **같은 검색 조건절** 안에서만. */
$pageIds = [];
$dbIds   = [];
$mineIds = [];   // 담당이 나인 줄(맡긴 사람을 찾는다)
foreach ($rows as $r) {
    $pageIds[] = (int) $r['id'];
    if ($r['database_id'] !== null) {
        $dbIds[(int) $r['database_id']] = true;
    }
    if ($r['assignee_id'] !== null && (int) $r['assignee_id'] === (int) $user['id']) {
        $mineIds[] = (int) $r['id'];
    }
}
$idList = static fn (array $ids): string => implode(',', array_map('intval', $ids));

$projects = [];
if ($dbIds !== []) {
    [$visible, $visBind] = community_search_pages_where($user);
    $sqlProjects = <<<SQL
        SELECT d.id AS db_id, p.public_id, p.title, d.color
          FROM community.databases d
          JOIN community.pages p ON p.id = d.page_id
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
         WHERE d.id = ANY (CAST(string_to_array(CAST(:mt_dbs AS text), ',') AS bigint[]))
           AND p.deleted_at IS NULL
           AND {$visible}
        SQL;
    foreach ($db->run($sqlProjects, array_merge($visBind, [':mt_dbs' => $idList(array_keys($dbIds))]))->fetchAll() as $d) {
        $projects[(int) $d['db_id']] = [
            'public_id' => (string) $d['public_id'],
            'title'     => (string) $d['title'],
            'color'     => $d['color'] === null ? null : (string) $d['color'],
        ];
    }
}

$subs = [];
$comments = [];
if ($pageIds !== []) {
    [$visible, $visBind] = community_search_pages_where($user);
    $sqlSubs = <<<SQL
        SELECT p.parent_page_id, count(*) AS total, count(*) FILTER (WHERE p.status = 'done') AS done
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
         WHERE p.parent_page_id = ANY (CAST(string_to_array(CAST(:mt_parents AS text), ',') AS bigint[]))
           AND p.deleted_at IS NULL
           AND {$visible}
         GROUP BY p.parent_page_id
        SQL;
    foreach ($db->run($sqlSubs, array_merge($visBind, [':mt_parents' => $idList($pageIds)]))->fetchAll() as $s) {
        $subs[(int) $s['parent_page_id']] = ['done' => (int) $s['done'], 'total' => (int) $s['total']];
    }
    // 댓글 수 — 그 줄을 볼 수 있으면 그 댓글도 보인다(지운·숨긴 댓글은 세지 않는다)
    $sqlComments = <<<'SQL'
        SELECT c.page_id, count(*) AS n
          FROM community.comments c
         WHERE c.page_id = ANY (CAST(string_to_array(CAST(:mt_pages AS text), ',') AS bigint[]))
           AND c.deleted_at IS NULL AND c.hidden_at IS NULL
         GROUP BY c.page_id
        SQL;
    foreach ($db->run($sqlComments, [':mt_pages' => $idList($pageIds)])->fetchAll() as $c) {
        $comments[(int) $c['page_id']] = (int) $c['n'];
    }
}

// 맡긴 사람 — 그 줄의 마지막 `assignee` 사건이 나에게 맡긴 것이고 내가 한 일이 아닐 때만(자기에게 맡긴 것·옛 값은 null)
$assignedBy = [];
if ($mineIds !== []) {
    $sqlAssigned = <<<'SQL'
        SELECT DISTINCT ON (e.page_id) e.page_id, e.by_user_id, e.to_value, u.username, u.display_name
          FROM community.page_property_events e
          LEFT JOIN public.users u ON u.id = e.by_user_id
         WHERE e.prop = 'assignee'
           AND e.page_id = ANY (CAST(string_to_array(CAST(:mt_assigned AS text), ',') AS bigint[]))
         ORDER BY e.page_id, e.id DESC
        SQL;
    foreach ($db->run($sqlAssigned, [':mt_assigned' => $idList($mineIds)])->fetchAll() as $e) {
        if ((int) $e['by_user_id'] !== (int) $user['id'] && (string) $e['to_value'] === (string) $user['username']
            && $e['username'] !== null) {
            $assignedBy[(int) $e['page_id']] = $e['display_name'] !== null && (string) $e['display_name'] !== ''
                ? (string) $e['display_name'] : (string) $e['username'];
        }
    }
}

$grouped = [];
foreach (array_keys(COMMUNITY_MY_TASK_BUCKETS) as $key) {
    $grouped[$key] = [];
}
$more = [];
foreach ($rows as $r) {
    $key = (string) $r['bucket'];
    if ((int) $r['rn'] > $offset + COMMUNITY_MY_TASK_PAGE) {
        $more[$key] = true;   // 한 줄 더 읽어 「더 있음」만 안다 — 그 줄은 내지 않는다
        continue;
    }
    $id    = (int) $r['id'];
    $isRow = $r['database_id'] !== null;
    $grouped[$key][] = [
        'public_id'   => (string) $r['public_id'],
        'title'       => (string) $r['title'],
        'status'      => $r['status'] === null ? null : (string) $r['status'],
        'due'         => $r['due_date'] === null ? null : (string) $r['due_date'],
        'project'     => $isRow ? ($projects[(int) $r['database_id']] ?? null) : null,
        'doc_space'   => $isRow ? null : '문서 · ' . (string) $r['space_name'],
        'assigned_by' => $assignedBy[$id] ?? null,
        'comments_n'  => $comments[$id] ?? 0,
        'sub_done'    => $subs[$id]['done'] ?? 0,
        'sub_total'   => $subs[$id]['total'] ?? 0,
    ];
}

$buckets = [];
foreach (COMMUNITY_MY_TASK_BUCKETS as $key => $name) {
    if ($bucket !== null && $key !== $bucket) {
        continue;
    }
    $buckets[] = [
        'key'         => $key,
        'name'        => $name,
        'items'       => $grouped[$key],
        'next_cursor' => isset($more[$key]) ? (string) ($offset + COMMUNITY_MY_TASK_PAGE) : null,
    ];
}

Http::json(['today' => $today, 'done' => $done, 'buckets' => $buckets]);
