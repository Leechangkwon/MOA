<?php
declare(strict_types=1);

/**
 * src/handlers/folders_update.php — PATCH /api/folders/{num} (정책: file.update → 핸들러가 다시 판단)
 *
 * ★ 18-1 폴더 이름 변경·이동·**등급 변경**(PRD F-26, 8장 PATCH 행, ARCH 5.16).
 *
 * 받는 값 (JSON, **허용 키 목록 밖의 키가 하나라도 있으면 400**)
 *   name         새 이름 (선택) — trim 1~50자, 제어문자·`/`·`\` 금지
 *   parent_id    새 부모 (선택) — 정수 또는 null(루트로 올리기)
 *   access_level 새 등급 (선택) — 파일과 같은 4종
 *   team_id      새 팀 (선택) — 새 등급이 team 이면 필수
 *   cascade      「하위에도 적용」(선택, bool) — 등급을 **좁힐 때만** 뜻이 있다
 *   reason_text  왜 바꾸는가 (선택) — 500자. 상세에만 남는다
 *
 * 검사 순서 (ARCH 5.16)
 *   [1] 대상 존재 → 없으면 403 동일 문구(+ `folder.update deny not_found`).
 *       폴더 id 는 순차 정수라 404/403 이 갈리면 "몇 번까지 폴더가 있나" 를 세어 볼 수 있다.
 *   [2] 허용 키 → 400 `bad_request`
 *   [권한 ✔ 1] `decideRead` — 못 읽는 폴더는 고칠 수도 없다(파일의 5.7 과 같은 순서)
 *   [권한 ✔ 2] `decideFolderChange` — 만든 사람 · Manager(자기 팀) · Admin
 *   [3] 값 형식(이름·등급·팀·부모·사유·cascade) → 400 `bad_request`
 *   [4] 바뀌는 값이 없으면 400 `bad_request`
 *   [권한 ✔ 3] 등급이 바뀌면 `decideLevelChange`(파일과 **같은 함수**) —
 *              넓히기는 Admin 만(`downgrade_not_permitted`), 새 등급·팀 지정 자격은 업로드와 같다
 *   [불변 ①] 새 부모 아래에서(또는 지금 부모 아래에서) 새 등급이 부모보다 넓으면 `parent_narrower`
 *   [5] 이동이면 자기 자신·자손 아래 금지(`cycle`) · 하위 포함 깊이 ≤ 5(`depth_exceeded`)
 *   [6] 같은 부모 안 이름 중복 → 409 `conflict`
 *   [불변 ②] 등급이 바뀌면 **하위 폴더·살아 있는 파일 전부**가 새 등급에 대해 ①을 만족해야 한다.
 *            `cascade:true` 면 위반한 것들을 **같은 트랜잭션에서 함께 좁히고**(항목마다 감사),
 *            행위자가 고칠 수 없는 항목이 하나라도 있으면 전부 되돌린다(400 `not_owner`).
 *            `cascade` 가 없거나 false 면 400 `children_exceed`(개수만 상세에).
 *   [7] 저장(하위 depth 재계산) → `folder.update` 또는 `folder.level_change` allow
 *
 * 왜 자손 아래로 옮기는 것을 막나
 *   A 를 A 의 자손 B 아래로 옮기면 A→B→…→A 가 되어 트리에서 **떨어져 나온 고리**가 된다.
 *   그 고리는 루트에서 보이지 않으면서도 파일을 계속 붙들고 있어 지울 수도, 찾을 수도 없다.
 *
 * 이 파일이 하지 않는 것
 *   · 역할 문자열을 비교하지 않는다 — 판정은 전부 Authz 함수다 (ARCH 4.1).
 *   · `created_by` 를 바꾸지 않는다 — 요청으로 관리 범위를 옮기는 통로를 만들지 않는다.
 *   · cascade 로 **넓히지** 않는다. 하위를 넓히는 일괄 변경은 노출 범위를 한 번에 키우는
 *     행동이라 이 창구에 두지 않는다 (ARCH 5.16 ③).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다 — PRD S-01)
 * @var list<string>        $params [0] => folders.id (정수 문자열, 라우터가 형태를 이미 좁혔다)
 */

/**
 * 「하위에도 적용」이 한 번에 고칠 수 있는 항목 수의 상한.
 * 한 요청이 감사 로그 수천 행과 긴 트랜잭션을 만들지 않게 하는 안전장치다 —
 * 넘으면 cascade 없이 요청한 것과 같은 `children_exceed` 로 끝난다(개수는 상세에 남는다).
 */
const TBOX_FOLDER_CASCADE_MAX = 500;

$folderId = isset($params[0]) && preg_match('/^[0-9]{1,9}$/', (string) $params[0]) === 1
    ? (int) $params[0]
    : 0;

$db = Db::instance();

// ─── [1] 대상 폴더 ──────────────────────────────────────────────
$sqlFind = <<<'SQL'
    SELECT id, parent_id, name, depth, access_level, team_id, created_by,
           'folder' AS item_kind   -- G-0 종류 표식(개인 등급 판정 — decideRead [P] · decideFolderChange 주인만)
      FROM folders
     WHERE id = CAST(:id AS bigint)
    SQL;

$folder = $folderId > 0 ? $db->run($sqlFind, [':id' => $folderId])->fetch() : false;
if (!is_array($folder)) {
    Audit::deny('folder.update', 'not_found', ['target_type' => 'folder']);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$currentName   = (string) $folder['name'];
$currentParent = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];
$currentDepth  = (int) $folder['depth'];
$currentLevel  = (string) $folder['access_level'];
$currentTeam   = $folder['team_id'] === null ? null : (int) $folder['team_id'];

$auditWhat = [
    'folder_id'   => $folderId,
    'parent_id'   => $currentParent,
    'depth'       => $currentDepth,
    'owner_id'    => (int) $folder['created_by'],
    'target_team' => $currentTeam,
];

/**
 * 거부 기록 후 끝낸다. 어느 action 에 남길지(`folder.update` / `folder.level_change`)와
 * 상태 코드는 부르는 쪽이 정한다 — 등급에 관한 거부는 등급 쪽 action 에 남아야
 * 감사 로그에서 "이름을 고치려다 막힌 것" 과 구별된다 (파일의 file.update / file.level_change 와 같다).
 */
$rejectAs = static function (
    string $action,
    string $reason,
    int $status,
    string $message,
    string $level,
    array $extra = []
) use ($auditWhat, $currentName, $folderId): void {
    Audit::deny($action, $reason, [
        'target_type'  => 'folder',
        'target_id'    => $folderId,
        'target_name'  => $currentName,
        'access_level' => $level,
        'detail'       => $auditWhat + $extra,
    ]);
    Http::error($status, $message);
};

/** 이름·위치에 관한 거부 (등급과 무관한 갈래) */
$reject = static function (string $reason, int $status, string $message) use ($rejectAs, $currentLevel): void {
    $rejectAs('folder.update', $reason, $status, $message, $currentLevel);
};

// ─── [2] 허용 키 검사 (SECURITY R-07) ───────────────────────────
// F-58 — **토큰 요청은 허용 키가 더 좁다** (ARCH 4.5, SECURITY R-90).
//   `X-API-Token` 으로 들어온 요청은 라우터 [2-T] 가 TBOX_AUTH_METHOD 를 세워 둔다. 그 요청에는
//   이름 변경·이동(`name`·`parent_id`)만 열고, 등급·팀·`cascade`·`reason_text` 는 허용 목록에서
//   뺀다 — 등급 넓히기는 Admin 의 결정이고 그 계정은 재인증 관문 [3-2] 대상인데 토큰 경로는
//   그 관문을 지나지 않기 때문이다(4.2). 아래 검사·판정·감사·응답은 한 글자도 갈라지지 않는다.
$allowedFields = defined('TBOX_AUTH_METHOD')
    ? ['name', 'parent_id']
    : ['name', 'parent_id', 'access_level', 'team_id', 'cascade', 'reason_text'];

$body = Http::jsonBody();
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

// ─── [권한 ✔ 1] 볼 수 없는 폴더는 고칠 수도 없다 (파일 5.7 과 같은 순서) ───
[$mayRead, $readCode] = Authz::decideRead($user, $folder);
if (!$mayRead) {
    $reject($readCode, 403, Http::MSG_FORBIDDEN);
}

// ─── [권한 ✔ 2] 만든 사람 · Manager(자기 팀) · Admin (ARCH 5.16) ───
// G-0 — 개인 폴더는 **이름만** 바꾸는 요청이면 「편집」 권한자도 된다(decideFolderRename). 옮기기·등급은 주인만(decideFolderChange).
// 어느 쪽인지는 요청에 실린 **키**로 가른다(값을 보기 전 — 이름 말고 다른 키가 하나라도 있으면 좁은 쪽 판정).
$onlyRename = array_diff(array_keys($body), ['name', 'reason_text']) === [];
[$mayChange, $changeCode] = $onlyRename
    ? Authz::decideFolderRename($user, $folder)
    : Authz::decideFolderChange($user, $folder);
if (!$mayChange) {
    $reject($changeCode, 403, Http::MSG_FORBIDDEN);
}

// ─── [3] 새 이름 ───────────────────────────────────────────────
$newName = $currentName;
if (array_key_exists('name', $body)) {
    $raw = $body['name'];
    if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    $raw = trim((string) $raw);
    if ($raw === ''
        || iconv_strlen($raw, 'UTF-8') > 50
        || preg_match('/\p{C}/u', $raw) === 1
        || strpos($raw, '/') !== false
        || strpos($raw, '\\') !== false
    ) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    $newName = $raw;
}

// ─── [3-2] 새 등급·팀 (파일과 같은 규칙) ───────────────────────
$sqlTeam = <<<'SQL'
    SELECT id FROM teams WHERE id = CAST(:id AS bigint) AND is_active
    SQL;

$newLevel = $currentLevel;
if (array_key_exists('access_level', $body)) {
    if (!in_array($body['access_level'], ['public', 'internal', 'team', 'admin-only', 'private'], true)) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    $newLevel = (string) $body['access_level'];
}

// team 이 아니면 요청에 무엇이 실려 있든 NULL 로 정규화한다
// (DB CHECK folders_team_requires_team_id 와 같은 규칙 — files_update 와 같은 처리).
$newTeam = null;
if ($newLevel === 'team') {
    if (array_key_exists('team_id', $body)) {
        $rawTeam  = $body['team_id'];
        $teamText = is_int($rawTeam) ? (string) $rawTeam : (is_string($rawTeam) ? $rawTeam : '');
        if (preg_match('/^[1-9][0-9]{0,8}$/', $teamText) !== 1) {
            $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
        }
        $wantTeam = (int) $teamText;
        if ($wantTeam === $currentTeam) {
            $newTeam = $currentTeam;   // 바뀌는 것이 없으면 활성 여부를 다시 묻지 않는다
        } else {
            $teamRow = $db->run($sqlTeam, [':id' => $wantTeam])->fetch();
            if (!is_array($teamRow)) {
                $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
            }
            $newTeam = (int) $teamRow['id'];
        }
    } else {
        $newTeam = $currentTeam;
        if ($newTeam === null) {
            $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
        }
    }
}

// ─── [3-3] cascade · 사유 ──────────────────────────────────────
$cascade = false;
if (array_key_exists('cascade', $body)) {
    if (!is_bool($body['cascade'])) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    $cascade = (bool) $body['cascade'];
}

$reasonText = null;
if (array_key_exists('reason_text', $body) && $body['reason_text'] !== null) {
    $rawReason = $body['reason_text'];
    if (!is_string($rawReason) || preg_match('//u', $rawReason) !== 1) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    $rawReason = trim((string) $rawReason);
    if (iconv_strlen($rawReason, 'UTF-8') > 500) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    $reasonText = $rawReason === '' ? null : $rawReason;
}

// ─── [3-4] 새 부모 ─────────────────────────────────────────────
$newParent = $currentParent;
if (array_key_exists('parent_id', $body)) {
    if ($body['parent_id'] === null) {
        $newParent = null;   // 루트로 올리기
    } else {
        $rawParent  = $body['parent_id'];
        $parentText = is_int($rawParent) ? (string) $rawParent : (is_string($rawParent) ? $rawParent : '');
        if (preg_match('/^[1-9][0-9]{0,8}$/', $parentText) !== 1) {
            $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
        }
        $newParent = (int) $parentText;
    }
}

// ─── [4] 바뀌는 것이 하나도 없으면 거부 ───────────────────────
//        "사유만 적은 요청" 이 감사 로그에 변경으로 남지 않게 한다.
$nameChanged   = $newName !== $currentName;
$parentChanged = $newParent !== $currentParent;
$levelChanged  = $newLevel !== $currentLevel || $newTeam !== $currentTeam;
if (!$nameChanged && !$parentChanged && !$levelChanged) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

// 등급이 바뀌는 요청의 거부는 등급 쪽 action 에 남긴다(문장 틀의 {level} = 바꾸려던 등급).
$levelAction = $levelChanged ? 'folder.level_change' : 'folder.update';

// ─── [권한 ✔ 3] 등급 변경 자격 (파일과 같은 함수) ───────────────
$levelCode = null;
if ($levelChanged) {
    [$mayLevel, $levelCode] = Authz::decideLevelChange($user, $folder, $newLevel, $newTeam);
    if (!$mayLevel) {
        $rejectAs($levelAction, $levelCode, 403, Http::MSG_FORBIDDEN, $newLevel, [
            'folder_before' => ['access_level' => $currentLevel, 'team_id' => $currentTeam],
            'folder_after'  => ['access_level' => $newLevel,     'team_id' => $newTeam],
        ]);
    }
}

// ─── [3-5] 새 부모 행 · 새 깊이 · [불변 ①] ────────────────────
$sqlParent = <<<'SQL'
    SELECT id, depth, access_level, team_id
      FROM folders
     WHERE id = CAST(:id AS bigint)
    SQL;

$newDepth  = 1;
$parentRow = null;
if ($newParent !== null) {
    $parentRow = $db->run($sqlParent, [':id' => $newParent])->fetch();
    if (!is_array($parentRow)) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    if ($parentChanged) {
        // 새 부모도 읽을 수 있어야 한다 — 못 읽는 폴더 안으로 밀어 넣을 수 없다.
        // G-0 — 개인 폴더 안으로는 그 주인·「편집」 권한자만 옮겨 넣는다(decideFolderPut — 개인이 아니면 decideRead 그대로).
        [$mayReadParent, $parentReadCode] = Authz::decideFolderPut($user, $parentRow);
        if (!$mayReadParent) {
            $reject($parentReadCode, 403, Http::MSG_FORBIDDEN);
        }
    }
    $newDepth = (int) $parentRow['depth'] + 1;

    // [불변 ①] 최종 등급이 (새) 부모보다 넓으면 안 된다.
    // 부모가 그대로라도 등급을 넓히면 여기서 걸린다 — 그래서 조건 없이 본다.
    [$fits, $fitCode] = Authz::fitsUnderFolder($newLevel, $newTeam, $parentRow);
    if (!$fits) {
        $rejectAs($levelAction, $fitCode, 400, Http::reasonMessage($fitCode), $newLevel, [
            'parent_id' => $newParent,
        ]);
    }
}
if ($newDepth > 5) {
    $reject('depth_exceeded', 400, Http::reasonMessage('depth_exceeded'));
}

// ─── [5] 자기 자신·자손 아래로 이동 금지 + 하위 포함 깊이 ───────
/* 재귀 CTE 로 이 폴더의 **자손 집합**(자기 자신 포함)을 구하고, 새 부모가 그 안에 있으면 거부한다.
 * hops 상한은 데이터가 어떤 이유로든 순환하고 있을 때 무한 재귀에 빠지지 않게 하는 안전장치다. */
$sqlSubtreeStats = <<<'SQL'
    WITH RECURSIVE sub AS (
        SELECT s.id, s.depth, 1 AS hops
          FROM folders s
         WHERE s.id = CAST(:id AS bigint)
        UNION ALL
        SELECT c.id, c.depth, sub.hops + 1
          FROM folders c
          JOIN sub ON c.parent_id = sub.id
         WHERE sub.hops < 10
    )
    SELECT max(sub.depth) AS max_depth,
           count(*) FILTER (WHERE sub.id = CAST(:target AS bigint)) AS hits
      FROM sub
    SQL;

if ($parentChanged) {
    $statRow = $db->run($sqlSubtreeStats, [
        ':id'     => $folderId,
        ':target' => $newParent,   // NULL(루트)이면 어떤 행과도 같지 않아 hits = 0 이다
    ])->fetch();
    if (!is_array($statRow)) {
        throw new RuntimeException('폴더 트리를 확인하지 못했습니다');
    }
    if ((int) $statRow['hits'] > 0) {
        $reject('cycle', 400, Http::reasonMessage('cycle'));
    }
    // 옮긴 뒤 하위까지 포함한 최대 깊이 — 지금 가장 깊은 폴더는 (max_depth - currentDepth) 만큼 아래다.
    if ($newDepth + ((int) $statRow['max_depth'] - $currentDepth) > 5) {
        $reject('depth_exceeded', 400, Http::reasonMessage('depth_exceeded'));
    }
}

// ─── [6] 같은 부모 안 이름 중복 (자기 자신 제외) ────────────────
$sqlExists = <<<'SQL'
    SELECT id FROM folders
     WHERE parent_id IS NOT DISTINCT FROM CAST(:parent AS bigint)
       AND name = :name
       AND id <> CAST(:id AS bigint)
    SQL;

if (is_array($db->run($sqlExists, [':parent' => $newParent, ':name' => $newName, ':id' => $folderId])->fetch())) {
    $reject('conflict', 409, Http::MSG_CONFLICT);
}

/* ─── [불변 ②] 하위 전부가 새 등급에 대해 ①을 만족하나 ───────────
 *
 * 등급(또는 팀)이 바뀌면 하위 폴더·**살아 있는 파일**(purged_at IS NULL — 휴지통 파일도 이 폴더를
 * 참조한다)을 전부 꺼내 `fitsUnderFolder` 로 본다. 판정은 SQL 에 등급 규칙을 복사해 넣지 않고
 * Authz 함수로 한다 — 규칙이 두 곳에 생기면 언젠가 어긋난다 (ARCH 5.16).
 *
 * 좁히기인데 `cascade:true` 면 위반한 항목을 **함께 좁힌다**(구글 드라이브의 「하위에도 적용」).
 * 그때도 자격은 항목마다 다시 본다: 파일은 `decideEdit`, 폴더는 `decideFolderChange` —
 * 하나라도 고칠 수 없으면 **전부 되돌린다**(부분 적용을 남기지 않는다).
 */
$sqlDescendants = <<<'SQL'
    WITH RECURSIVE sub AS (
        SELECT s.id, 1 AS hops
          FROM folders s
         WHERE s.parent_id = CAST(:id AS bigint)
        UNION ALL
        SELECT c.id, sub.hops + 1
          FROM folders c
          JOIN sub ON c.parent_id = sub.id
         WHERE sub.hops < 10
    ), scope AS (
        SELECT CAST(:id AS bigint) AS id
        UNION ALL
        SELECT sub.id FROM sub
    )
    SELECT 'folder' AS kind, d.id, d.name, d.access_level, d.team_id, d.created_by AS owner_id
      FROM folders d
     WHERE d.parent_id IN (SELECT scope.id FROM scope)
    UNION ALL
    SELECT 'file' AS kind, f.id, f.original_name, f.access_level, f.team_id, f.uploaded_by
      FROM files f
     WHERE f.folder_id IN (SELECT scope.id FROM scope)
       AND f.purged_at IS NULL
    SQL;

/* 좁히기 뒤 **마지막 그물**: 하위 전부가 자기 **실제 부모**에 대해 ①을 만족하나.
 * cascade 로 일부만 바뀌면 (드물게) 바뀐 항목이 안 바뀐 부모보다 넓어질 수 있다 —
 * 그 경우 트랜잭션을 통째로 되돌린다. 규칙은 `LEVEL_RANK`(ARCH 4.1)를 SQL 로 옮긴 CASE 식이다. */
$sqlIntegrity = <<<'SQL'
    WITH RECURSIVE sub AS (
        SELECT s.id, 1 AS hops
          FROM folders s
         WHERE s.parent_id = CAST(:id AS bigint)
        UNION ALL
        SELECT c.id, sub.hops + 1
          FROM folders c
          JOIN sub ON c.parent_id = sub.id
         WHERE sub.hops < 10
    ), scope AS (
        SELECT CAST(:id AS bigint) AS id
        UNION ALL
        SELECT sub.id FROM sub
    ), pairs AS (
        SELECT d.access_level AS lv, d.team_id AS tm, p.access_level AS plv, p.team_id AS ptm
          FROM folders d
          JOIN folders p ON p.id = d.parent_id
         WHERE d.parent_id IN (SELECT scope.id FROM scope)
        UNION ALL
        SELECT f.access_level, f.team_id, p.access_level, p.team_id
          FROM files f
          JOIN folders p ON p.id = f.folder_id
         WHERE f.folder_id IN (SELECT scope.id FROM scope)
           AND f.purged_at IS NULL
    )
    SELECT count(*) AS bad
      FROM pairs
     WHERE (CASE pairs.lv  WHEN 'public' THEN 0 WHEN 'internal' THEN 1
                           WHEN 'team' THEN 2 WHEN 'admin-only' THEN 3 WHEN 'private' THEN 4 ELSE 9 END)
         < (CASE pairs.plv WHEN 'public' THEN 0 WHEN 'internal' THEN 1
                           WHEN 'team' THEN 2 WHEN 'admin-only' THEN 3 WHEN 'private' THEN 4 ELSE 9 END)
        OR (pairs.plv = 'team' AND (pairs.tm IS NULL OR pairs.tm <> pairs.ptm))
    SQL;

$newFolderShape = ['access_level' => $newLevel, 'team_id' => $newTeam];
$violations     = [];
$narrowing      = $levelChanged
    && Authz::LEVEL_RANK[$newLevel] > Authz::LEVEL_RANK[$currentLevel];

if ($levelChanged) {
    foreach ($db->run($sqlDescendants, [':id' => $folderId])->fetchAll() as $child) {
        $childTeam = $child['team_id'] === null ? null : (int) $child['team_id'];
        if (Authz::fitsUnderFolder((string) $child['access_level'], $childTeam, $newFolderShape)[0]) {
            continue;
        }
        $violations[] = [
            'kind'       => (string) $child['kind'],
            'id'         => (int) $child['id'],
            'name'       => (string) $child['name'],
            'level'      => (string) $child['access_level'],
            'team_id'    => $childTeam,
            'owner_id'   => (int) $child['owner_id'],
        ];
    }
}

$doCascade = $cascade && $narrowing && $violations !== [];

if ($violations !== [] && !$doCascade) {
    // cascade 를 쓰지 않았거나(또는 넓히기라 쓸 수 없거나) 위반이 남았다 → 거부. 개수만 남긴다.
    $rejectAs($levelAction, 'children_exceed', 400, Http::reasonMessage('children_exceed'), $newLevel, [
        'violations'    => count($violations),
        'folder_before' => ['access_level' => $currentLevel, 'team_id' => $currentTeam],
        'folder_after'  => ['access_level' => $newLevel,     'team_id' => $newTeam],
    ]);
}
if ($doCascade && count($violations) > TBOX_FOLDER_CASCADE_MAX) {
    $rejectAs($levelAction, 'children_exceed', 400, Http::reasonMessage('children_exceed'), $newLevel, [
        'violations'    => count($violations),
        'folder_before' => ['access_level' => $currentLevel, 'team_id' => $currentTeam],
        'folder_after'  => ['access_level' => $newLevel,     'team_id' => $newTeam],
    ]);
}

/* cascade 로 고칠 항목의 **자격을 먼저 전부 본다** — 하나라도 안 되면 아무것도 바꾸지 않는다.
 * 파일은 파일 규칙(`decideEdit`), 폴더는 폴더 규칙(`decideFolderChange`)이다. */
$cascadeCodes = [];
if ($doCascade) {
    $denied = 0;
    foreach ($violations as $i => $v) {
        $row = $v['kind'] === 'file'
            ? ['uploaded_by' => $v['owner_id'], 'team_id' => $v['team_id']]
            : ['created_by'  => $v['owner_id'], 'team_id' => $v['team_id']];
        if ($newLevel === Authz::LEVEL_PRIVATE) {
            /* G-0 — 하위를 「개인」 으로 함께 좁히는 것은 **그 항목을 올린·만든 사람**일 때만이다(decideLevelChange 의 개인 규칙 —
               남의 파일을 개인으로 돌리면 팀이 못 보게 된다). 하나라도 남의 것이면 전부 되돌린다(아래 not_owner). */
            $row += ['item_kind' => $v['kind'], 'id' => $v['id'], 'access_level' => $v['level']];
            [$mayEditChild, $childCode] = Authz::decideLevelChange($user, $row, Authz::LEVEL_PRIVATE, null);
        } else {
            [$mayEditChild, $childCode] = $v['kind'] === 'file'
                ? Authz::decideEdit($user, $row)
                : Authz::decideFolderChange($user, $row);
        }
        if (!$mayEditChild) {
            $denied++;
            continue;
        }
        $cascadeCodes[$i] = $childCode;
    }
    if ($denied > 0) {
        $rejectAs($levelAction, 'not_owner', 400, Http::reasonMessage('not_owner'), $newLevel, [
            'violations'    => count($violations),
            'cascade'       => true,
            'folder_before' => ['access_level' => $currentLevel, 'team_id' => $currentTeam],
            'folder_after'  => ['access_level' => $newLevel,     'team_id' => $newTeam],
        ]);
    }
}

/* ─── [7] 저장 ─────────────────────────────────────────────────
 * 미리 적어 둔 문장뿐이다. SQL 을 문자열로 이어 붙이지 않는다 (PRD S-04).
 */
$sqlShiftDepth = <<<'SQL'
    WITH RECURSIVE sub AS (
        SELECT s.id, 1 AS hops
          FROM folders s
         WHERE s.id = CAST(:id AS bigint)
        UNION ALL
        SELECT c.id, sub.hops + 1
          FROM folders c
          JOIN sub ON c.parent_id = sub.id
         WHERE sub.hops < 10
    )
    UPDATE folders f
       SET depth = f.depth + CAST(:delta AS int), updated_at = now()
     WHERE f.id IN (SELECT sub.id FROM sub)
    SQL;

$sqlApply = <<<'SQL'
    UPDATE folders
       SET name         = :name,
           parent_id    = CAST(:parent AS bigint),
           access_level = :level,
           team_id      = CAST(:team AS bigint),
           updated_at   = now()
     WHERE id = CAST(:id AS bigint)
    RETURNING id, name, parent_id, depth, access_level, team_id, updated_at
    SQL;

$sqlCascadeFolder = <<<'SQL'
    UPDATE folders
       SET access_level = :level, team_id = CAST(:team AS bigint), updated_at = now()
     WHERE id = CAST(:id AS bigint)
    SQL;

$sqlCascadeFile = <<<'SQL'
    UPDATE files
       SET access_level = :level, team_id = CAST(:team AS bigint)
     WHERE id = CAST(:id AS bigint)
       AND deleted_at IS NULL
    SQL;

$before = [];
$after  = [];
if ($nameChanged) {
    $before['name'] = $currentName;
    $after['name']  = $newName;
}
if ($parentChanged) {
    $before['parent_id'] = $currentParent;
    $after['parent_id']  = $newParent;
    $before['depth']     = $currentDepth;
    $after['depth']      = $newDepth;
}
if ($levelChanged) {
    $before['access_level'] = $currentLevel;
    $after['access_level']  = $newLevel;
    $before['team_id']      = $currentTeam;
    $after['team_id']       = $newTeam;
}

$delta = $newDepth - $currentDepth;

$db->begin();
try {
    if ($delta !== 0) {
        $db->run($sqlShiftDepth, [':id' => $folderId, ':delta' => $delta]);
    }

    $row = $db->run($sqlApply, [
        ':name'   => $newName,
        ':parent' => $newParent,
        ':level'  => $newLevel,
        ':team'   => $newTeam,
        ':id'     => $folderId,
    ])->fetch();

    if (!is_array($row)) {
        // 조회와 수정 사이에 다른 요청이 먼저 지운 경우 — 없는 것과 같이 취급한다
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }

    // ── 「하위에도 적용」 — 항목마다 자기 감사 행을 남긴다 ──
    foreach ($doCascade ? $violations : [] as $i => $v) {
        $sql = $v['kind'] === 'file' ? $sqlCascadeFile : $sqlCascadeFolder;
        $db->run($sql, [':level' => $newLevel, ':team' => $newTeam, ':id' => $v['id']]);

        Audit::allow($v['kind'] === 'file' ? 'file.level_change' : 'folder.level_change', [
            'target_type'  => $v['kind'],
            'target_id'    => $v['id'],
            'target_name'  => $v['name'],
            'access_level' => $newLevel,
            'detail'       => [
                'granted_by'   => $cascadeCodes[$i] ?? null,
                'before'       => ['access_level' => $v['level'],  'team_id' => $v['team_id']],
                'after'        => ['access_level' => $newLevel,    'team_id' => $newTeam],
                'changed'      => ['access_level', 'team_id'],
                // 어느 폴더의 「하위에도 적용」으로 바뀐 것인가 (ARCH 7.5 cascade_from)
                'cascade_from' => $folderId,
                'owner_id'     => $v['owner_id'],
                'target_team'  => $newTeam,
            ],
        ]);
    }

    // ── 마지막 그물: 하위 전부가 **자기 실제 부모**에 대해 ①을 만족하나 ──
    if ($levelChanged) {
        $bad = $db->run($sqlIntegrity, [':id' => $folderId])->fetch();
        if (!is_array($bad) || (int) $bad['bad'] !== 0) {
            $db->rollback();
            $rejectAs($levelAction, 'children_exceed', 400, Http::reasonMessage('children_exceed'), $newLevel, [
                'violations'    => is_array($bad) ? (int) $bad['bad'] : -1,
                'folder_before' => ['access_level' => $currentLevel, 'team_id' => $currentTeam],
                'folder_after'  => ['access_level' => $newLevel,     'team_id' => $newTeam],
            ]);
        }
    }

    Audit::allow($levelAction, [
        'target_type'  => 'folder',
        'target_id'    => $folderId,
        // 바뀐 뒤의 이름으로 남긴다 — 이전 이름은 detail.folder_before 에 그대로 있다.
        'target_name'  => (string) $row['name'],
        'access_level' => $newLevel,
        'detail'       => [
            'granted_by'    => $levelChanged ? $levelCode : $changeCode,
            'folder_id'     => $folderId,
            'parent_id'     => $newParent,
            'depth'         => $newDepth,
            'folder_before' => $before,
            'folder_after'  => $after,
            'owner_id'      => (int) $folder['created_by'],
            'target_team'   => $newTeam,
        ]
        + ($doCascade ? ['cascade' => true, 'cascaded' => count($violations)] : [])
        + ($reasonText === null ? [] : ['reason_text' => $reasonText]),
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    // 23505 = unique_violation. 위 [6] 과 저장 사이에 다른 요청이 같은 이름을 먼저 쓴 경우다.
    if ($e->getCode() === '23505') {
        $reject('conflict', 409, Http::MSG_CONFLICT);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'folder' => [
        'id'           => (int) $row['id'],
        'parent_id'    => $row['parent_id'] === null ? null : (int) $row['parent_id'],
        'name'         => (string) $row['name'],
        'depth'        => (int) $row['depth'],
        'access_level' => (string) $row['access_level'],
        'team_id'      => $row['team_id'] === null ? null : (int) $row['team_id'],
        'updated_at'   => (string) $row['updated_at'],
    ],
    'cascaded' => $doCascade ? count($violations) : 0,
]);
