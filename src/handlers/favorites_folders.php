<?php
declare(strict_types=1);

/**
 * src/handlers/favorites_folders.php — 폴더 즐겨찾기 (정책: file.read — 개발 대기열 6 · Q:F-3a 라운드 A)
 *
 *   GET    /api/folders/favorites          내 즐겨찾기 폴더 목록 — **지금 열 수 있는 것만**
 *   PUT    /api/folders/{num}/favorite     넣기 — 그 순간 그 폴더를 읽을 수 있어야 한다(`Authz::decideRead` — 폴더 목록과 같은 함수)
 *   DELETE /api/folders/{num}/favorite     빼기 — 내 줄만
 *
 * 요구사항: 지시서 `/var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md` 1.1 · 결정 M-2, PRD S-01 · S-02 · S-04, ARCH 5.16 · 5.33.
 *
 * ★ 새 판정 코드 0 — 넣기는 폴더 트리(`folders_list.php` [권한 ✔ 1])와 같은 `Authz::decideRead`, 목록은 트리와 같은
 *   조건절 `Authz::visibleWhere($user, 'd')` 를 붙인다. 등급이 좁아진 폴더는 목록 SQL 이 돌려주지 않는다.
 * ★ 없는 폴더와 못 읽는 폴더는 **같은 404 동일 문구**다(폴더 번호는 순차 정수라 둘이 갈리면 「몇 번까지 있나」가 샌다).
 *   트리 창구의 403 과 모양이 다른 까닭: 즐겨찾기 넣기의 거부는 「찾을 수 없습니다」 하나로 두 앱을 맞춘다(지시서 1.1).
 * ★ 감사에 남기지 않는다(결정 M-2). 대상은 세션의 본인뿐 — 요청에서 사람을 받지 않는다.
 * ★ 빈 폴더를 지우면(folders_delete) 이 표의 줄은 외래 키 ON DELETE CASCADE 로 함께 사라진다 — 즐겨찾기가 삭제를 막지 않는다.
 * ★ 한도: 사람당 50. 가득 찼으면 먼저 지금 못 여는 내 줄을 걷은 뒤 다시 센다(구현 기록 결정 D-5). 그래도 50 이면 409.
 *   라운드 B: 한 트랜잭션 + 이 사람 advisory 잠금(L-2) · 「역할로 보기」 중에는 걷지 않는다(L-1).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params PUT·DELETE: [0] = 폴더 id(정수 — 라우터가 9자리까지로 좁혔다) · GET: 없음
 */

// 사람당 한도(지시서 1.1) — 요청 값이 아니라 코드 상수다.
$favMax = 50;

$db     = Db::instance();
$me     = (int) $user['id'];
$method = Http::method();

if ($method === 'GET') {
    [$visibleFolders, $folderBind] = Authz::visibleWhere($user, 'd');
    $sqlList = <<<'SQL'
        SELECT d.id,
               d.name,
               d.access_level,
               d.team_id,
               t.name AS team_name,
               fav.created_at AS favorited_at
          FROM folder_favorites fav
          JOIN folders d ON d.id = fav.folder_id
          LEFT JOIN teams t ON t.id = d.team_id
         WHERE fav.user_id = CAST(:fav_me AS bigint)
           AND
        SQL;
    $sqlTail = ' ORDER BY fav.created_at ASC, d.id ASC LIMIT 50';
    $bind = $folderBind;
    $bind[':fav_me'] = $me;
    $rows = $db->run($sqlList . ' ' . $visibleFolders . $sqlTail, $bind)->fetchAll();

    // 사람이 읽는 경로(`A / B`) — 표시용. 읽을 수 있는 폴더의 조상은 언제나 읽을 수 있다(불변 규칙 ①, ARCH 5.16).
    $paths = [];
    if ($rows !== []) {
        $sqlPaths = <<<'SQL'
            WITH RECURSIVE tree AS (
                SELECT d.id, d.name AS path, 1 AS hops
                  FROM folders d
                 WHERE d.parent_id IS NULL
                UNION ALL
                SELECT c.id, tree.path || ' / ' || c.name, tree.hops + 1
                  FROM folders c
                  JOIN tree ON c.parent_id = tree.id
                 WHERE tree.hops < 10
            )
            SELECT tree.id, tree.path FROM tree
            SQL;
        foreach ($db->run($sqlPaths)->fetchAll() as $p) {
            $paths[(int) $p['id']] = (string) $p['path'];
        }
    }

    $folders = [];
    foreach ($rows as $row) {
        $fid = (int) $row['id'];
        $folders[] = [
            'id'           => $fid,
            'name'         => (string) $row['name'],
            'access_level' => (string) $row['access_level'],
            'team_id'      => $row['team_id'] === null ? null : (int) $row['team_id'],
            'team_name'    => $row['team_name'] === null ? null : (string) $row['team_name'],
            'path'         => $paths[$fid] ?? (string) $row['name'],
            'favorited_at' => (string) $row['favorited_at'],
        ];
    }
    // 전체 개수 칸이 없다 — 못 여는 즐겨찾기의 수가 새지 않게.
    Http::json(['folders' => $folders, 'limit' => $favMax]);
    return;
}

// ─── PUT · DELETE — 폴더 하나 ───────────────────────────────────
$folderId = (int) ($params[0] ?? 0);
if ($folderId <= 0) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

if ($method === 'DELETE') {
    $sqlDelete = <<<'SQL'
        DELETE FROM folder_favorites
         WHERE user_id = CAST(:me AS bigint)
           AND folder_id = CAST(:fid AS bigint)
        SQL;
    $db->run($sqlDelete, [':me' => $me, ':fid' => $folderId]);
    Http::json(['ok' => true, 'favorite' => false]);
    return;
}

// PUT — 넣기. 폴더 트리와 같은 칸을 읽고 같은 판정으로 묻는다.
$sqlFind = <<<'SQL'
    SELECT id, parent_id, name, depth, access_level, team_id
      FROM folders
     WHERE id = CAST(:id AS bigint)
    SQL;
$folder = $db->run($sqlFind, [':id' => $folderId])->fetch();
if (!is_array($folder) || !Authz::decideRead($user, $folder)[0]) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

/* 라운드 B — 파일 즐겨찾기(favorites_files.php)와 같은 두 고침: 세기·걷기·넣기를 한 트랜잭션 + 이 사람 advisory 잠금으로
   세운다(동시 넣기로 한도를 넘지 않게 — 라운드 A 독립 검토 L-2) · 「역할로 보기」 중에는 걷지 않는다(L-1 — 걷는 조건절이
   흉내 낸 역할로 만들어져 관리자 자신의 줄을 지웠다). 열쇠는 표마다 따로라 파일 넣기와는 부딪히지 않는다. */
$sqlLock = <<<'SQL'
    SELECT pg_advisory_xact_lock(hashtext('tbox.folder_favorites'), CAST(:me_lock AS integer))
    SQL;
$sqlHave = <<<'SQL'
    SELECT 1 FROM folder_favorites WHERE user_id = CAST(:me AS bigint) AND folder_id = CAST(:fid AS bigint)
    SQL;
$sqlCount = <<<'SQL'
    SELECT count(*) AS n FROM folder_favorites WHERE user_id = CAST(:me AS bigint)
    SQL;
$sqlInsert = <<<'SQL'
    INSERT INTO folder_favorites (user_id, folder_id)
    VALUES (CAST(:me AS bigint), CAST(:fid AS bigint))
    ON CONFLICT DO NOTHING
    SQL;
$acting = ($user['acting'] ?? false) === true;

$db->begin();
try {
    $db->run($sqlLock, [':me_lock' => $me % 2147483647]);
    if ($db->run($sqlHave, [':me' => $me, ':fid' => $folderId])->fetch() === false) {
        $count = (int) ($db->run($sqlCount, [':me' => $me])->fetch()['n'] ?? 0);
        if ($count >= $favMax && !$acting) {
            [$visibleFolders, $folderBind] = Authz::visibleWhere($user, 'd');
            $sqlPrune = <<<'SQL'
                DELETE FROM folder_favorites fav
                 WHERE fav.user_id = CAST(:fav_me AS bigint)
                   AND NOT EXISTS (SELECT 1 FROM folders d
                                    WHERE d.id = fav.folder_id
                                      AND
                SQL;
            $pruneBind = $folderBind;
            $pruneBind[':fav_me'] = $me;
            $db->run($sqlPrune . ' ' . $visibleFolders . ')', $pruneBind);
            $count = (int) ($db->run($sqlCount, [':me' => $me])->fetch()['n'] ?? 0);
        }
        if ($count >= $favMax) {
            $db->rollback();
            Http::error(409, '즐겨찾기는 폴더 ' . $favMax . '개까지입니다 — 쓰지 않는 것을 먼저 빼 주세요');
        }
        $db->run($sqlInsert, [':me' => $me, ':fid' => $folderId]);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 처리기 → 500
}

Http::json(['ok' => true, 'favorite' => true]);
