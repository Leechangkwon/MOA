<?php
declare(strict_types=1);

/**
 * src/handlers/folders_delete.php — DELETE /api/folders/{num} (정책: file.delete → 핸들러가 다시 판단)
 *
 * ★ 18-1 빈 폴더 지우기 (PRD F-26, 8장 DELETE 행, ARCH 5.16 "삭제").
 *
 * 검사 순서
 *   [1] 대상 존재 → 없으면 403 동일 문구(+ `folder.delete deny not_found`).
 *       폴더 id 는 순차 정수라 404/403 이 갈리면 "몇 번까지 폴더가 있나" 를 세어 볼 수 있다.
 *   [권한 ✔ 1] `decideRead` — 못 읽는 폴더는 지울 수도 없다 (파일 5.4 와 같은 순서)
 *   [권한 ✔ 2] `decideFolderChange` — 만든 사람 · Manager(자기 팀) · Admin
 *   [2] **비어 있나** — 하위 폴더 0 이고, 이 폴더를 참조하는 파일이 0 이어야 한다.
 *       파일은 `purged_at IS NULL` 로 센다: **휴지통의 파일도 이 폴더를 참조하므로**(FK) 남아
 *       있으면 지울 수 없다. 배치가 실제 바이트를 지운 뒤(`purged_at`)에도 행은 남지만, 그때는
 *       `folder_id` 가 여전히 걸려 있으므로 사실상 FK 가 마지막 판단을 한다 — 앱은 같은 것을
 *       먼저 보고 **사유를 기록**할 뿐이다.
 *   [3] DELETE → `folder.delete allow`
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

// ─── [2] 비어 있나 (하위 폴더 0 · 참조하는 파일 0) ──────────────
// 파일은 **볼 수 있는 것만** 세지 않는다 — 여기서 묻는 것은 "권한" 이 아니라 "참조가 남았나"
// 이고, 안 보이는 파일이 있다는 이유로 폴더가 조용히 지워지면 FK 가 뒤에서 터진다.
// 그래서 응답은 개수도 파일 이름도 담지 않는다 (SECURITY R-63).
$sqlCounts = <<<'SQL'
    SELECT (SELECT count(*) FROM folders k WHERE k.parent_id = CAST(:id AS bigint)) AS children,
           (SELECT count(*) FROM files  f WHERE f.folder_id = CAST(:id AS bigint)
                                            AND f.purged_at IS NULL)                AS files
    SQL;

$counts = $db->run($sqlCounts, [':id' => $folderId])->fetch();
if (!is_array($counts)) {
    throw new RuntimeException('폴더 내용을 확인하지 못했습니다');
}
if ((int) $counts['children'] !== 0 || (int) $counts['files'] !== 0) {
    $reject('folder_not_empty', 400, Http::MSG_FOLDER_NOT_EMPTY);
}

// ─── [3] 삭제 — 기록이 실패하면 폴더도 남는다 (PRD S-07) ────────
$sqlDelete = <<<'SQL'
    DELETE FROM folders WHERE id = CAST(:id AS bigint)
    SQL;

$db->begin();
try {
    $affected = $db->run($sqlDelete, [':id' => $folderId])->rowCount();
    if ($affected !== 1) {
        // 조회와 삭제 사이에 다른 요청이 먼저 지운 경우 — 없는 것과 같이 취급한다
        $db->rollback();
        Http::error(403, Http::MSG_FORBIDDEN);
    }

    Audit::allow('folder.delete', $auditTarget + [
        'detail' => ['granted_by' => $changeCode] + $auditWhat,
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

Http::json(['ok' => true]);
