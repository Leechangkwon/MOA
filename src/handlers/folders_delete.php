<?php
declare(strict_types=1);

/**
 * src/handlers/folders_delete.php — DELETE /api/folders/{num} (정책: file.delete → 핸들러가 다시 판단)
 *
 * ★ 18-1 빈 폴더 지우기 (PRD F-26, 8장 DELETE 행, ARCH 5.16 "삭제").
 * ★ 26단계 — 휴지통 파일만 남은 폴더는 **확인 뒤** 지울 수 있다 (PRD F-41, ARCH 5.16 "휴지통 파일").
 *
 * 검사 순서
 *   [1] 대상 존재 → 없으면 403 동일 문구(+ `folder.delete deny not_found`).
 *       폴더 id 는 순차 정수라 404/403 이 갈리면 "몇 번까지 폴더가 있나" 를 세어 볼 수 있다.
 *   [권한 ✔ 1] `decideRead` — 못 읽는 폴더는 지울 수도 없다 (파일 5.4 와 같은 순서)
 *   [권한 ✔ 2] `decideFolderChange` — 만든 사람 · Manager(자기 팀) · Admin
 *   [2] **비어 있나** — 세 갈래다 (26단계):
 *       [2-1] 하위 폴더가 있다         → 400 `folder_has_children`
 *       [2-2] 살아 있는 파일이 있다     → 400 `folder_not_empty`
 *       [2-3] 휴지통 파일만 남았다      → 확인(`?move_trashed=1`)이 없으면 400 `folder_has_trashed`
 *                                       + **볼 수 있는 파일의 목록**(R-63 보충), 확인이 있으면
 *                                       같은 트랜잭션에서 루트로 옮기고 폴더를 지운다.
 *       배치가 실제 바이트를 지운 뒤(`purged_at`)에도 행은 남지만 `folder_id` 는 걸려 있으므로
 *       마지막 판단은 FK(ON DELETE RESTRICT)가 한다 — 앱은 같은 것을 먼저 보고 **사유를 기록**한다.
 *   [3] (필요하면 휴지통 파일 이동 →) DELETE → `folder.delete allow`
 *
 * 왜 폴더만 실제로 지우나 (파일은 논리 삭제인데)
 *   폴더에는 내용이 없다. 행을 남겨도 되살릴 것이 없고, "지웠다" 는 사실은 감사 로그
 *   `folder.delete allow` 가 (부모·깊이와 함께) 남긴다. 앱 계정에 DELETE 를 주는 표가
 *   `sessions` 말고 하나 더 생기는 것이므로, 그 자격이 **빈 폴더 한 행**에만 닿도록
 *   위 [2] 와 FK(ON DELETE RESTRICT) 두 겹으로 묶는다 (ARCH 10장, db/grants.sql).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다 — PRD S-01)
 * @var list<string>        $params [0] => folders.id (정수 문자열, 라우터가 형태를 이미 좁혔다)
 */

$folderId = isset($params[0]) && preg_match('/^[0-9]{1,9}$/', (string) $params[0]) === 1
    ? (int) $params[0]
    : 0;

$db = Db::instance();

$sqlFind = <<<'SQL'
    SELECT id, parent_id, name, depth, access_level, team_id, created_by
      FROM folders
     WHERE id = CAST(:id AS bigint)
    SQL;

// ─── [1] 대상 폴더 ──────────────────────────────────────────────
$folder = $folderId > 0 ? $db->run($sqlFind, [':id' => $folderId])->fetch() : false;
if (!is_array($folder)) {
    Audit::deny('folder.delete', 'not_found', ['target_type' => 'folder']);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$name   = (string) $folder['name'];
$level  = (string) $folder['access_level'];
$parent = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];

$auditTarget = [
    'target_type'  => 'folder',
    'target_id'    => $folderId,
    'target_name'  => $name,
    'access_level' => $level,
];
$auditWhat = [
    'folder_id'   => $folderId,
    'parent_id'   => $parent,
    'depth'       => (int) $folder['depth'],
    'owner_id'    => (int) $folder['created_by'],
    'target_team' => $folder['team_id'] === null ? null : (int) $folder['team_id'],
];

/** 거부 기록 후 끝낸다. 상태 코드·문구는 부르는 쪽이 정한다. */
$reject = static function (string $reason, int $status, string $message, array $extra = [])
use ($auditTarget, $auditWhat): void {
    Audit::deny('folder.delete', $reason, $auditTarget + ['detail' => $auditWhat + $extra]);
    Http::error($status, $message);
};

/**
 * 「무엇이 막았나」 를 코드로도 알려 주는 400 (26단계, PRD F-41).
 *
 * 권한 거부(403)에는 **절대 쓰지 않는다** — 그쪽은 대상 정보를 담지 않는 동일 문구여야 한다
 * (PRD S-09-5). 여기 오는 셋은 "지울 자격은 있는데 폴더가 비어 있지 않다" 이고, 화면이
 * 무엇을 안내할지(되묻기·먼저 지우기) 고르려면 문구가 아니라 **코드**를 봐야 한다.
 * 개수도 이름도 담지 않는다 (SECURITY R-63).
 */
$rejectFolder = static function (string $code, string $message, array $extra = [])
use ($auditTarget, $auditWhat): void {
    Audit::deny('folder.delete', $code, $auditTarget + ['detail' => $auditWhat + $extra]);
    Http::json(['error' => $message, 'code' => $code], 400);
    exit;
};

// ─── [권한 ✔ 1] 볼 수 없는 폴더는 지울 수도 없다 ────────────────
[$mayRead, $readCode] = Authz::decideRead($user, $folder);
if (!$mayRead) {
    $reject($readCode, 403, Http::MSG_FORBIDDEN);
}

// ─── [권한 ✔ 2] 만든 사람 · Manager(자기 팀) · Admin ────────────
[$mayChange, $changeCode] = Authz::decideFolderChange($user, $folder);
if (!$mayChange) {
    $reject($changeCode, 403, Http::MSG_FORBIDDEN);
}

/*
 * ─── [2] 비어 있나 — **세 갈래** (26단계, PRD F-41, 사용자 지적 2026-09-15) ──────────
 *
 * 18-1 에는 갈래가 하나였다: 하위 폴더나 파일(휴지통 포함)이 하나라도 있으면 400 한 문구.
 * 그 규칙은 옳게 동작했지만 **빠져나갈 길이 없는 상태**를 만들었다 — 휴지통의 파일은 사용자가
 * 없앨 방법이 없고(30일 뒤 배치가 지운다), 그동안 폴더도 지울 수 없었다(qa-18r-T 실측).
 *
 *   [2-1] 하위 폴더가 있다        → 400 `folder_has_children`  — 먼저 그 폴더를 지워라
 *   [2-2] **살아 있는** 파일이 있다 → 400 `folder_not_empty`    — 파일을 먼저 옮기거나 지워라
 *   [2-3] 휴지통 파일만 남았다     → 확인이 없으면 400 `folder_has_trashed` + 그 목록,
 *                                  `?move_trashed=1` 이면 **같은 트랜잭션**에서 그 파일들의
 *                                  folder_id 를 NULL(루트)로 옮긴 뒤 폴더를 지운다.
 *
 * 왜 옮기기만 하고 지우지 않나: 휴지통은 「30일 안에 되살릴 수 있다」는 약속이다. 폴더와 함께
 * 파일을 purge 하면 그 약속이 깨진다. 루트로 옮겨 두면 `deleted_at` 이 그대로라 휴지통 목록에
 * 남고, 복구하면 루트에 살아난다. **살아 있는 파일은 절대 옮기지 않는다** — 사용자가 모르는
 * 사이에 위치가 바뀌면 안 되기 때문이고, 그것이 [2-2] 가 먼저 오는 이유다.
 */
$sqlCounts = <<<'SQL'
    SELECT (SELECT count(*) FROM folders k WHERE k.parent_id = CAST(:id AS bigint)) AS children,
           (SELECT count(*) FROM files f WHERE f.folder_id = CAST(:id AS bigint)
                                           AND f.purged_at IS NULL
                                           AND f.deleted_at IS NULL)        AS alive,
           (SELECT count(*) FROM files f WHERE f.folder_id = CAST(:id AS bigint)
                                           AND f.purged_at IS NULL
                                           AND f.deleted_at IS NOT NULL)    AS trashed
    SQL;

$counts = $db->run($sqlCounts, [':id' => $folderId])->fetch();
if (!is_array($counts)) {
    throw new RuntimeException('폴더 내용을 확인하지 못했습니다');
}

// [2-1] 하위 폴더 — 개수도 이름도 응답에 담지 않는다 (R-63 그대로)
if ((int) $counts['children'] !== 0) {
    $rejectFolder('folder_has_children', Http::MSG_FOLDER_HAS_CHILDREN);
}

// [2-2] 살아 있는 파일 — 역시 개수도 이름도 담지 않는다. 볼 수 없는 파일일 수 있다 (R-63)
if ((int) $counts['alive'] !== 0) {
    $rejectFolder('folder_not_empty', Http::MSG_FOLDER_NOT_EMPTY);
}

$trashedCount = (int) $counts['trashed'];

/**
 * 확인 꼬리표 — `?move_trashed=1` 하나뿐이다.
 * 값은 **정확히 '1'** 일 때만 참이다(그 밖은 전부 거짓). 이 값은 "지워도 되나" 를 정하지 않는다 —
 * 지울 자격은 위 권한 검사 두 개가 이미 정했고, 이것은 "되물음에 예라고 답했나" 일 뿐이다.
 */
$confirmMove = Http::queryChoice('move_trashed', ['1'], '') === '1';

if ($trashedCount !== 0 && !$confirmMove) {
    /*
     * 26단계 (사용자 결정 2026-09-15 「리스트를 반환해 진짜 삭제하겠냐 경고」) —
     * 되물을 때는 **무엇이 남았는지** 보여 준다. 그러지 않으면 사용자는 "무엇을 지우는지 모른 채
     * 예를 누르는" 상태가 된다.
     *
     * 다만 보여 주는 것은 **요청한 사람이 볼 수 있는 파일뿐**이다(`Authz::canRead`, 파일 목록과
     * 같은 판정). 볼 수 없는 파일이 하나라도 있으면 `others: true` 만 내보내고 개수도 이름도
     * 담지 않는다 — R-63 이 지키려는 것은 「안 보이는 파일의 존재·개수·이름」이고, 이미 볼 수
     * 있는 파일의 이름을 다시 보여 주는 것은 새로 새는 것이 아니다 (SECURITY R-63 보충).
     * 50건에서 끊는 이유는 응답 크기 때문이며, 끊겼는지 여부도 `others` 가 함께 말해 준다.
     */
    $sqlTrashed = <<<'SQL'
        SELECT f.public_id, f.original_name, f.deleted_at,
               f.access_level, f.team_id, f.uploaded_by
          FROM files f
         WHERE f.folder_id  = CAST(:id AS bigint)
           AND f.purged_at  IS NULL
           AND f.deleted_at IS NOT NULL
         ORDER BY f.deleted_at DESC, f.id DESC
        SQL;

    $trashedRows = $db->run($sqlTrashed, [':id' => $folderId])->fetchAll();
    $visible = [];
    $others  = false;
    foreach ($trashedRows as $row) {
        if (!Authz::canRead($user, $row)) {
            $others = true;
            continue;
        }
        if (count($visible) >= 50) {
            $others = true;      // 잘렸다는 사실도 같은 깃발로 알린다
            continue;
        }
        $visible[] = [
            'id'         => (string) $row['public_id'],
            'name'       => (string) $row['original_name'],
            'deleted_at' => $row['deleted_at'] === null ? null : (string) $row['deleted_at'],
        ];
    }

    // 감사에는 개수만 남긴다(이름은 남기지 않는다 — 로그가 파일 이름 목록이 되지 않게).
    Audit::deny('folder.delete', 'folder_has_trashed', $auditTarget + [
        'detail' => $auditWhat + ['trashed' => $trashedCount],
    ]);
    Http::json([
        'error'   => Http::MSG_FOLDER_HAS_TRASHED,
        'code'    => 'folder_has_trashed',
        'trashed' => $visible,
        'others'  => $others,
    ], 400);
    exit;
}

// ─── [3] 삭제 — 기록이 실패하면 폴더도 남는다 (PRD S-07) ────────
/*
 * 휴지통 파일을 **루트로** 옮긴다(folder_id = NULL). `deleted_at`·`purged_at` 은 건드리지 않으므로
 * 휴지통 목록(`GET /api/files/trash`)에 그대로 남고 복구도 그대로 된다. 조건에 `deleted_at IS NOT
 * NULL` 이 있어 살아 있는 파일은 **한 행도** 옮겨지지 않는다 — 위 [2-2] 가 이미 0 을 확인했지만,
 * 그 사이에 들어온 파일까지 옮겨 버리지 않게 조건을 SQL 에도 둔다.
 * 옮기기와 삭제가 **한 트랜잭션**이라, 삭제가 실패하면 파일도 제자리에 남는다.
 */
$sqlMoveTrashed = <<<'SQL'
    UPDATE files
       SET folder_id = NULL
     WHERE folder_id  = CAST(:id AS bigint)
       AND deleted_at IS NOT NULL
       AND purged_at  IS NULL
    RETURNING public_id
    SQL;

$sqlDelete = <<<'SQL'
    DELETE FROM folders WHERE id = CAST(:id AS bigint)
    SQL;

$movedIds = [];

$db->begin();
try {
    if ($trashedCount !== 0) {
        foreach ($db->run($sqlMoveTrashed, [':id' => $folderId])->fetchAll() as $movedRow) {
            $movedIds[] = (string) $movedRow['public_id'];
        }
    }

    $affected = $db->run($sqlDelete, [':id' => $folderId])->rowCount();
    if ($affected !== 1) {
        // 조회와 삭제 사이에 다른 요청이 먼저 지운 경우 — 없는 것과 같이 취급한다
        $db->rollback();
        Http::error(403, Http::MSG_FORBIDDEN);
    }

    $movedDetail = $movedIds === []
        ? []
        : ['moved_trashed' => count($movedIds), 'moved_file_ids' => $movedIds];

    Audit::allow('folder.delete', $auditTarget + [
        'detail' => ['granted_by' => $changeCode] + $auditWhat + $movedDetail,
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    // 23503 = foreign_key_violation. 위 [2] 와 DELETE 사이에 파일이 들어온 경우다 —
    // FK(ON DELETE RESTRICT)가 마지막으로 막는다 (ARCH 5.16 "앱 검사의 마지막 그물").
    if ($e->getCode() === '23503') {
        $reject('folder_not_empty', 400, Http::MSG_FOLDER_NOT_EMPTY);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json(['ok' => true, 'moved_trashed' => count($movedIds)]);
