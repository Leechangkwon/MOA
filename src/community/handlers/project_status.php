<?php
declare(strict_types=1);

/**
 * src/community/handlers/project_status.php — 프로젝트 상태 (사이트 Q:F-12 WK-12 ②, 커뮤니티 ARCHITECTURE 23.6)
 *
 *   POST /community/api/projects/{id}/status {status, note?}   (정책: community.write)
 *   GET  /community/api/projects/{id}/status?limit=            (정책: community.read, limit 1~50 · 기본 20)
 *
 * `{id}` = 프로젝트의 **데이터베이스 페이지** public_id. 그 데이터베이스가 `purpose='project'` 가 아니면 400.
 *
 * 검사 순서
 *   [1] (POST) 허용 키 `status`·`note` 만 · 상태 다섯 값 · 글 280자 이하(제어 문자 0 — 줄바꿈만) → 아니면 400(감사 없음)
 *   [2] 페이지 관문 `community_history_gate` — 못 보는 페이지 403·404(그 관문의 감사 그대로)
 *   [3] (POST) `decidePageChange` — 고칠 수 있는 사람만(403 + 감사 `project.status deny <코드>`)
 *   [4] 프로젝트인가(`databases.purpose='project'`) — 아니면 400
 *   [5] (POST) INSERT 한 줄 + 감사 `project.status allow` — 한 트랜잭션. 알림 없음(받은 알림을 늘리지 않는다)
 *
 * ★ 지울 수 없는 기록 — 앱 계정은 이 표에 SELECT · INSERT 만이다(db/community/grants.sql). 고치기·지우기 창구는 없다.
 *   지금 상태 = 마지막 줄(`at` 내림 · 같은 때면 `id` 내림).
 *
 * 응답
 *   POST 200 {"status": {status, note, at, by:{username, display_name}}}
 *   GET  200 {"current": <줄>|null, "updates": [<줄>, …]}   — 줄 = {status, note, at, by:{username, display_name}|null}
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 프로젝트(데이터베이스 페이지) public_id
 */

$db     = Db::instance();
$me     = (int) $user['id'];
$pid    = (string) $params[0];
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

$statusOut = static function (array $r): array {
    return [
        'status' => (string) $r['status'],
        'note'   => (string) $r['note'],
        'at'     => (string) $r['at'],
        'by'     => $r['by_name'] === null ? null : [
            'username'     => (string) $r['by_name'],
            'display_name' => $r['by_display_name'] === null ? null : (string) $r['by_display_name'],
        ],
    ];
};

// ─── [1] 모양 (POST) · 한도 (GET) ───────────────────────────────
$wantStatus = null;
$wantNote   = '';
$limit      = 20;
if ($method === 'POST') {
    $body = Http::jsonBody();
    if (array_diff(array_keys($body), ['status', 'note']) !== [] || !array_key_exists('status', $body)) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    if (!is_string($body['status']) || !in_array($body['status'], COMMUNITY_PROJECT_STATUSES, true)) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $wantStatus = $body['status'];
    if (array_key_exists('note', $body) && $body['note'] !== null) {
        $note = $body['note'];
        // 줄바꿈 말고는 제어 문자 0 · 글자 수는 DB CHECK(char_length ≤ 280)와 같은 셈(코드 포인트)
        if (!is_string($note) || preg_match('//u', $note) !== 1
            || preg_match('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}]/u', $note) === 1
            || iconv_strlen($note, 'UTF-8') > COMMUNITY_PROJECT_NOTE_MAX) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $wantNote = $note;
    }
} else {
    $limitRaw = Http::queryText('limit', 3);
    if ($limitRaw !== '') {
        if (preg_match('/^[1-9][0-9]?$/', $limitRaw) !== 1 || (int) $limitRaw > 50) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $limit = (int) $limitRaw;
    }
}

// ─── [2] 페이지 관문 — 못 보는 페이지는 여기서 끝난다 ─────────────
$gate      = community_history_gate($user, $pid);
$row       = $gate['row'];
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

// ─── [3] 고칠 수 있나 (POST) ────────────────────────────────────
if ($method === 'POST' && !$gate['may_change']) {
    Audit::deny('project.status', (string) $gate['change_code'], [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'status' => $wantStatus],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [4] 프로젝트인가 ───────────────────────────────────────────
$project = community_project_row($pid, $me);
if ($project === null || ($project['dbself_purpose'] ?? null) !== 'project') {
    Http::json(['error' => '프로젝트에서만 상태를 쓰고 볼 수 있습니다'], 400);
    exit;
}

if ($method === 'POST') {
    // ─── [5] 한 줄 + 감사 ─────────────────────────────────────────
    $sqlInsert = <<<'SQL'
        INSERT INTO community.project_status_updates (page_id, status, note, by_user_id)
        VALUES (CAST(:pid AS bigint), :status, :note, CAST(:by AS bigint))
        RETURNING status, note, at
        SQL;
    $db->begin();
    try {
        $ins = $db->run($sqlInsert, [':pid' => $pageId, ':status' => $wantStatus, ':note' => $wantNote, ':by' => $me])->fetch();
        Audit::allow('project.status', [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => ['granted_by' => (string) $gate['change_code'], 'page_title' => $pageTitle, 'status' => $wantStatus],
        ]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    Http::json(['status' => $statusOut([
        'status' => $ins['status'], 'note' => $ins['note'], 'at' => $ins['at'],
        'by_name' => (string) $user['username'], 'by_display_name' => $user['display_name'] ?? null,
    ])]);
    exit;
}

$sqlList = <<<'SQL'
    SELECT u.status, u.note, u.at, b.username AS by_name, b.display_name AS by_display_name
      FROM community.project_status_updates u
      LEFT JOIN public.users b ON b.id = u.by_user_id
     WHERE u.page_id = CAST(:pid AS bigint)
     ORDER BY u.at DESC, u.id DESC
     LIMIT CAST(:lim AS int)
    SQL;
$updates = array_map($statusOut, $db->run($sqlList, [':pid' => $pageId, ':lim' => $limit])->fetchAll());
Http::json(['current' => $updates[0] ?? null, 'updates' => $updates]);
