<?php
declare(strict_types=1);

/**
 * src/handlers/favorites_files.php — 파일 즐겨찾기 (정책: file.read — 개발 대기열 6 · Q:F-3a 라운드 A)
 *
 *   GET    /api/files/favorites         내 즐겨찾기 파일 목록 — **지금 볼 수 있는 것만**
 *   PUT    /api/files/{id}/favorite     넣기 — 그 순간 읽을 수 있어야 한다(`Authz::decideRead`)
 *   DELETE /api/files/{id}/favorite     빼기 — 내 줄만
 *
 * 요구사항: 지시서 `/var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md` 1.1 · 결정 M-2 · 3장 QF3A-T1·T2,
 *           PRD S-01 · S-02(못 보는 것은 목록에 없다) · S-04, ARCH 5.33.
 *
 * ★ 새 판정 코드 0. 넣을 때는 다운로드·미리보기와 **같은 함수**(`Authz::decideRead`)로 묻고, 목록은 파일 목록과 **같은 조건절**
 *   (`Authz::visibleFilesWhere` — 논리 삭제 제외가 그 안에 있다)을 붙인다. 그래서 등급이 좁아졌거나 휴지통에 간 파일은
 *   목록 SQL 이 애초에 돌려주지 않는다 — 이름도 개수도 나가지 않는다(응답에 전체 개수 칸이 없다).
 * ★ 없는 파일 · 지운 파일 · 못 읽는 파일은 **같은 404 동일 문구**(「찾을 수 없습니다」)다 — 즐겨찾기 넣기가 「그런 파일이 있다」를
 *   알려 주는 창구가 되지 않게(지시서 1.1 「404 같은 문구」).
 * ★ 감사에 남기지 않는다(결정 M-2 — 테마 쿠키 같은 개인 화면 설정이고 판정이 바뀌지 않는다). 거부도 남기지 않는다 —
 *   이 창구는 파일 내용·이름·존재 어느 것도 돌려주지 않고, 새 감사 행동 이름을 만들지 않는다(Audit 문장 표는 그대로).
 * ★ 대상은 언제나 **세션의 본인**이다 — 요청에서 사람을 받지 않는다(쿼리·본문의 어떤 값도 읽지 않는다).
 * ★ 한도: 사람당 200. 가득 찼으면 먼저 **지금 못 보는 내 줄**을 걷어 낸 뒤 다시 센다(보이지 않는 줄 때문에 막히지 않게 —
 *   구현 기록 결정 D-5). 그래도 200 이면 409. 라운드 B: 세기·걷기·넣기는 한 트랜잭션 + 이 사람 advisory 잠금(동시 넣기로
 *   한도를 넘지 않게 — L-2) · 「역할로 보기」 중에는 걷지 않는다(L-1).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params PUT·DELETE: [0] = 파일 public_id (UUID) · GET: 없음
 */

// 사람당 한도(지시서 1.1) — 요청 값이 아니라 코드 상수다.
$favMax = 200;

$db     = Db::instance();
$me     = (int) $user['id'];
$method = Http::method();

if ($method === 'GET') {
    [$visibleWhere, $visibleBind] = Authz::visibleFilesWhere($user);

    // 파일 목록(files_list.php)과 같은 칸 — 화면이 같은 표 그리기로 그린다. 끝 칸 둘이 즐겨찾기 것이다.
    $sqlList = <<<'SQL'
        SELECT f.public_id,
               f.original_name,
               f.size_bytes,
               f.description,
               f.access_level,
               f.ext,
               cardinality(f.sensitive_flags) > 0 AS sensitive,
               f.scan_status,
               f.exec_content,
               f.team_id,
               f.folder_id,
               f.uploaded_by,
               t.name       AS team_name,
               u.username   AS uploader,
               f.created_at,
               fav.created_at AS favorited_at,
               -- G-0 — 개인 등급 판정(can_edit·can_delete)이 쓰는 번호·종류 표식(응답에는 싣지 않는다)
               f.id,
               'file' AS item_kind
          FROM file_favorites fav
          JOIN files f ON f.id = fav.file_id
          JOIN users u ON u.id = f.uploaded_by
          LEFT JOIN teams t ON t.id = f.team_id
         WHERE fav.user_id = CAST(:fav_me AS bigint)
           AND
        SQL;
    // 순서는 「추가한 순」(지시서 1.1) — 먼저 넣은 것이 위. 같은 때면 파일 번호로 고정한다.
    $sqlTail = ' ORDER BY fav.created_at ASC, f.id ASC LIMIT 200';

    $bind = $visibleBind;
    $bind[':fav_me'] = $me;
    $rows = $db->run($sqlList . ' ' . $visibleWhere . $sqlTail, $bind)->fetchAll();

    // 폴더 경로(표시용) — G-0: 보이는 폴더 이름만(`_folder_paths.php` — 파일 목록·최근과 같은 함수). 폴더에 든 행이 없으면 묻지 않는다.
    require_once __DIR__ . '/_folder_paths.php';
    $folderMap = [];
    foreach ($rows as $row) {
        if ($row['folder_id'] !== null) {
            $folderMap = tbox_folder_map($user);
            break;
        }
    }

    $files = [];
    foreach ($rows as $row) {
        $files[] = [
            'id'           => (string) $row['public_id'],
            'name'         => (string) $row['original_name'],
            'size_bytes'   => (int) $row['size_bytes'],
            'description'  => (string) $row['description'],
            'access_level' => (string) $row['access_level'],
            'team_id'      => $row['team_id'] === null ? null : (int) $row['team_id'],
            'team_name'    => $row['team_name'] === null ? null : (string) $row['team_name'],
            'folder_id'    => tbox_folder_cells($folderMap, $row['folder_id'] === null ? null : (int) $row['folder_id'])[0],
            'folder_path'  => tbox_folder_cells($folderMap, $row['folder_id'] === null ? null : (int) $row['folder_id'])[1],
            'uploaded_by'  => (string) $row['uploader'],
            'created_at'   => (string) $row['created_at'],
            // 화면 편의 값 — 단추를 보일지. 실제 삭제·수정은 그 창구가 다시 판단한다 (PRD S-01).
            'can_delete'   => Authz::decideDelete($user, $row)[0],
            'can_edit'     => Authz::decideEdit($user, $row)[0],
            'preview_kind' => Preview::kindFor((string) $row['ext']),
            'sensitive'    => $row['sensitive'] === true,
            'scan_status'  => (string) $row['scan_status'],
            'exec_content' => $row['exec_content'] === true,
            'favorite'     => true,
            'favorited_at' => (string) $row['favorited_at'],
        ];
    }

    // ★ 전체 개수 칸이 없다 — 못 보는 즐겨찾기의 수가 새지 않게(지시서 2장 금지 패턴). 보이는 줄의 수는 배열 길이다.
    Http::json(['files' => $files, 'limit' => $favMax]);
    return;
}

// ─── PUT · DELETE — 파일 하나 ───────────────────────────────────
$publicId = (string) ($params[0] ?? '');
if (!Storage::isValidUuid($publicId)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

if ($method === 'DELETE') {
    // 내 줄만 지운다. 못 보게 된 파일의 줄도 지울 수 있다(내 설정이다) — 응답은 있든 없든 같다.
    $sqlDelete = <<<'SQL'
        DELETE FROM file_favorites fav
         USING files f
         WHERE f.id = fav.file_id
           AND f.public_id = CAST(:pid AS uuid)
           AND fav.user_id = CAST(:me AS bigint)
        SQL;
    $db->run($sqlDelete, [':pid' => $publicId, ':me' => $me]);
    Http::json(['ok' => true, 'favorite' => false]);
    return;
}

// PUT — 넣기. 행을 읽고 **다운로드와 같은 판정**으로 묻는다. 지운 파일(`deleted_at`)은 없는 것과 같다.
$sqlFind = <<<'SQL'
    SELECT f.id, f.access_level, f.team_id,
           'file' AS item_kind   -- G-0 종류 표식(개인 등급 판정 — Authz::decideRead [P])
      FROM files f
     WHERE f.public_id = CAST(:pid AS uuid)
       AND f.deleted_at IS NULL
    SQL;
$file = $db->run($sqlFind, [':pid' => $publicId])->fetch();
if (!is_array($file) || !Authz::decideRead($user, $file)[0]) {
    // 없음·지움·못 읽음 — 같은 404 동일 문구(존재 여부가 갈리지 않는다). 감사 없음(결정 M-2).
    Http::error(404, Http::MSG_NOT_FOUND);
}
$fileId = (int) $file['id'];

/* 라운드 B (라운드 A 독립 검토 L-2) — 「있나 → 몇 개 → 걷기 → 넣기」를 **한 사람 한 줄로** 세운다. 같은 사람의 넣기가 동시에
   여러 개 오면(일괄 즐겨찾기 · 탭 둘) 둘 다 199 를 세고 둘 다 넣어 한도를 넘었다. 트랜잭션 안에서 **이 사람·이 표**의
   advisory 잠금을 먼저 잡는다 — 다른 사람·다른 표의 넣기와는 부딪히지 않고, 잠금은 트랜잭션이 끝나면 저절로 풀린다(xact).
   열쇠 둘 = (표 이름의 hashtext, 사람 번호) — 값은 코드 상수와 세션의 내 번호뿐이다. 사람 번호는 int4 범위로 접는다
   (접혀 겹치는 번호끼리는 줄을 설 뿐 결과는 같다). */
$sqlLock = <<<'SQL'
    SELECT pg_advisory_xact_lock(hashtext('tbox.file_favorites'), CAST(:me_lock AS integer))
    SQL;
$sqlHave = <<<'SQL'
    SELECT 1 FROM file_favorites WHERE user_id = CAST(:me AS bigint) AND file_id = CAST(:fid AS bigint)
    SQL;
$sqlCount = <<<'SQL'
    SELECT count(*) AS n FROM file_favorites WHERE user_id = CAST(:me AS bigint)
    SQL;
$sqlInsert = <<<'SQL'
    INSERT INTO file_favorites (user_id, file_id)
    VALUES (CAST(:me AS bigint), CAST(:fid AS bigint))
    ON CONFLICT DO NOTHING
    SQL;
/* 라운드 B (L-1) — 「역할로 보기」 중이면 걷지 않는다. 걷는 조건절은 **지금 유효한 역할**로 만들어지므로, 관리자가 직원으로
   보는 동안 넣기가 한도에 닿으면 관리자 **자신의** 줄(직원 역할로는 못 보는 파일)을 지워 버렸다. 그 동안은 세기만 하고
   가득이면 409 — 원래 역할로 돌아오면 전과 같이 걷는다. */
$acting = ($user['acting'] ?? false) === true;

$db->begin();
try {
    $db->run($sqlLock, [':me_lock' => $me % 2147483647]);
    if ($db->run($sqlHave, [':me' => $me, ':fid' => $fileId])->fetch() === false) {
        $count = (int) ($db->run($sqlCount, [':me' => $me])->fetch()['n'] ?? 0);
        if ($count >= $favMax && !$acting) {
            // 가득 찼다 — 지금 못 보는 내 줄(등급이 좁아졌거나 휴지통에 간 파일)을 먼저 걷는다. 조건절은 목록과 같은 함수다.
            [$visibleWhere, $visibleBind] = Authz::visibleFilesWhere($user);
            $sqlPrune = <<<'SQL'
                DELETE FROM file_favorites fav
                 WHERE fav.user_id = CAST(:fav_me AS bigint)
                   AND NOT EXISTS (SELECT 1 FROM files f
                                    WHERE f.id = fav.file_id
                                      AND
                SQL;
            $pruneBind = $visibleBind;
            $pruneBind[':fav_me'] = $me;
            $db->run($sqlPrune . ' ' . $visibleWhere . ')', $pruneBind);
            $count = (int) ($db->run($sqlCount, [':me' => $me])->fetch()['n'] ?? 0);
        }
        if ($count >= $favMax) {
            $db->rollback();
            Http::error(409, '즐겨찾기는 파일 ' . $favMax . '개까지입니다 — 쓰지 않는 것을 먼저 빼 주세요');
        }
        $db->run($sqlInsert, [':me' => $me, ':fid' => $fileId]);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 처리기 → 500
}

Http::json(['ok' => true, 'favorite' => true]);
