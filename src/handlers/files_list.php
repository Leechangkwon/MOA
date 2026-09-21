<?php
declare(strict_types=1);

/**
 * src/handlers/files_list.php — GET /api/files?q=&sort=&dir=&page= (정책: file.read)
 *
 * ★ 4단계: 권한 조건은 **SQL WHERE 절**에 들어간다 (PRD S-02, ARCH 5.1).
 *   `Authz::visibleFilesWhere($user)` 가 돌려준 조건절을 목록·건수 두 SQL 에 똑같이 붙인다.
 *   권한 없는 행은 응답에서 지우는 것이 아니라 **DB 가 애초에 돌려주지 않는다** —
 *   화면 필터·애플리케이션 필터는 권한 처리로 인정하지 않는다(PRD 7.2).
 *   `total` 도 같은 조건으로 세므로 전체 건수가 새지 않는다.
 *   논리 삭제 제외(`deleted_at IS NULL`)도 그 조건절 안에 함께 있다(SECURITY 1.3 보완).
 *
 * 요구사항: PRD F-03 · F-04, S-01 · S-02, 8장 API 표, ARCH 5.1
 *
 * 지키는 것
 *   · 값은 전부 바인딩한다. SQL 문자열에 값을 붙이지 않는다 (PRD S-04)
 *   · ORDER BY 는 바인딩할 수 없으므로 **허용 목록 → 고정 SQL 조각** 매핑만 쓴다 (ARCH 5.1 [3])
 *   · LIKE 값은 와일드카드(`%` `_`)와 이스케이프 문자를 무력화해서 넣는다
 *   · 목록 조회는 감사 로그를 남기지 않는다 (ARCH 5.1 마지막 줄, PRD S-07)
 *   · 내부 `files.id` 는 응답에 넣지 않는다. 외부 ID 는 `public_id`(UUID) 뿐이다 (PRD S-09-5)
 *   · 행마다 `can_delete`·`can_edit` 를 함께 내려 준다 — 삭제·수정 버튼을 보일지 정하는
 *     **화면 편의 값**이다. 버튼이 보이든 안 보이든 실제 삭제·수정은 서버가 다시 판단한다
 *     (PRD 10장, ARCH 5.1 메모·5.7 화면 메모)
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다)
 * @var list<string>        $params 경로 변수 없음
 */

/* ─── 26-2 (F-50) — 이 요청이 프로그램용 토큰으로 들어왔나 (ARCH 4.3) ───────────
 * `TBOX_AUTH_METHOD` 는 라우터의 [2-T] 가 토큰 인증에 성공했을 때만 정의한다. 요청이 보낸
 * 값이 아니라 **서버가 세운 상수**이므로 헤더·파라미터로 흉내 낼 수 없다.
 * 이 값이 바꾸는 것은 **페이지 크기와 마지막 투영**뿐이다 — 권한 조건·SQL·검색·폴더 규칙은
 * 세션 요청과 한 글자도 다르지 않다(PRD S-02, ARCH 4.3 「새 판정 코드가 없다」).
 */
$viaToken = defined('TBOX_AUTH_METHOD');

// 웹은 20 고정. 토큰 요청만 limit(1~100, 기본 20)을 받는다 — 형태가 아니거나 범위를
// 벗어나면 queryInt 가 기본값 20 으로 좁힌다(거부하지 않는다, ARCH 5.1 [2]).
$perPage = $viaToken ? Http::queryInt('limit', 20, 1, 100) : 20;

// ─── 입력 좁히기 (거부가 아니라 안전한 기본값으로 대체) ───
$q    = Http::queryText('q', 200);
$sort = Http::queryChoice('sort', ['name', 'created_at', 'size'], 'created_at');
$dir  = Http::queryChoice('dir', ['asc', 'desc'], 'desc');
$page = Http::queryInt('page', 1, 1, 100000);
// 18-1 (F-26) — 어느 폴더를 열었나. **0 = 루트**(`folder_id IS NULL`)이고 기본값도 0 이다.
// 형태가 아니거나 범위를 벗어나면 0 으로 좁힌다 — 거부하지 않는다 (ARCH 5.1 [2]).
$folder = Http::queryInt('folder', 0, 0, 999999999);

/* ─── 검색의 범위 (18-1 검토 결정 2026-09-13) ───────────────────────────
 * 폴더가 생기면서 목록의 기본값이 "루트만" 이 되었는데, 그러면 **검색이 지금 연 폴더 안으로
 * 갇힌다**. 파일을 찾으려면 폴더를 하나씩 열어 봐야 하므로 찾기 기능이 아니다.
 *
 * 그래서 규칙을 셋으로 나눈다:
 *   q 있음 · folder 파라미터 **없음**  → **전역 검색** (폴더 필터를 붙이지 않는다)
 *   q 있음 · folder 파라미터 있음      → 그 폴더 안에서만
 *   q 없음                              → 지금처럼 folder(기본 0 = 루트) 안에서만
 *
 * 전역 검색이라도 **보이는 것은 하나도 늘지 않는다**: 조건은 여전히 `visibleFilesWhere`
 * 하나뿐이고, 불변 규칙 ①(하위 등급 ≥ 부모 등급)이 "파일이 보이면 그 폴더도 보인다" 를
 * 보장하므로 못 읽는 폴더의 파일이 검색에 뜨는 일은 없다 (ARCH 5.16, PRD S-02).
 *
 * `folder` 를 **보냈는지**를 봐야 하므로 여기서만 원본 배열을 확인한다. 값은 위 queryInt 가
 * 이미 정수로 좁혔고, 이 확인은 값이 아니라 **키의 유무**만 본다.
 */
$folderGiven  = array_key_exists('folder', $_GET);
$globalSearch = $q !== '' && !$folderGiven;

/* ─── [권한 ✔ 폴더] 연 폴더 자체를 읽을 수 있나 (18-1, PRD F-26, ARCH 5.16) ───
 *
 * 폴더도 파일과 같은 등급 체계를 갖는다(사용자 결정 2026-09-13). 못 읽는 폴더의 목록은
 * **비어 보이는 것이 아니라 403 동일 문구**다 — 빈 폴더와 구별되지 않으면 "여기에 내가
 * 못 보는 것이 있다" 를 세어 볼 수 있기 때문이다. 판정은 파일과 **같은 함수**다.
 *
 * 없는 폴더도 같은 403 이다: 폴더 id 는 순차 정수라 404/403 이 갈리면 그 자체가
 * "몇 번까지 폴더가 있나" 를 알려 주는 신호가 된다 (PRD S-09-5). 사유는 로그에만 남는다.
 *
 * 폴더를 읽을 수 있다고 해서 안의 파일이 다 보이는 것은 아니다 — 아래 목록 SQL 의
 * `visibleFilesWhere` 가 파일 등급으로 한 번 더 좁힌다(두 겹, PRD F-26).
 */
$sqlFolderRow = <<<'SQL'
    SELECT id, parent_id, name, depth, access_level, team_id
      FROM folders
     WHERE id = CAST(:id AS bigint)
    SQL;

$folderRow = null;
if ($folder !== 0 && !$globalSearch) {
    $folderRow = Db::instance()->run($sqlFolderRow, [':id' => $folder])->fetch();
    if (!is_array($folderRow)) {
        Audit::deny('folder.read', 'not_found', ['target_type' => 'folder']);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    [$mayReadFolder, $folderCode] = Authz::decideRead($user, $folderRow);
    if (!$mayReadFolder) {
        Audit::deny('folder.read', $folderCode, [
            'target_type'  => 'folder',
            'target_id'    => (int) $folderRow['id'],
            'target_name'  => (string) $folderRow['name'],
            'access_level' => (string) $folderRow['access_level'],
            'detail'       => [
                'folder_id'   => (int) $folderRow['id'],
                'target_team' => $folderRow['team_id'] === null ? null : (int) $folderRow['team_id'],
            ],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
}

// ─── 권한 조건 (PRD S-02) ───
// 목록·건수 두 SQL 의 WHERE 첫 조건으로 그대로 들어간다. 값은 바인딩으로만 붙는다.
// 조건절은 테이블 별칭 `f` 를 전제로 하며, 논리 삭제 제외도 이 안에 있다.
[$visibleWhere, $visibleBind] = Authz::visibleFilesWhere($user);

// ─── SQL 조각 (모두 코드에 적힌 고정 문자열) ───
// 아래 두 heredoc 은 `WHERE` 에서 끝나고, 바로 뒤에 위 권한 조건절이 붙는다.
$sqlList = <<<'SQL'
    SELECT f.public_id,
           f.original_name,
           f.size_bytes,
           f.description,
           f.access_level,
           f.ext,
           -- 15-3 (F-22) — 「민감」 배지용 **불리언 하나**. 패턴 이름은 목록에 내리지 않는다
           -- (어떤 패턴이 몇 건 걸렸는지는 감사 로그에만 남는다, ARCH 5.13).
           cardinality(f.sensitive_flags) > 0 AS sensitive,
           -- F-54 (ARCH 5.28) — 「검사 중」·「격리됨」 배지와 단추 비활성에 쓰는 상태 하나.
           -- 세 값('pending'·'clean'·'infected')뿐이고 파일 이름·시그니처는 내리지 않는다.
           f.scan_status,
           -- F-65 (ARCH 5.29) — 「실행 파일 포함」 배지·확인 다운로드 창·공유 단추 숨김·
           -- 등급 셀렉트 잠금에 쓰는 **불리언 하나**. 어떤 항목이 들어 있었는지(이름·개수)는
           -- 목록에 내리지 않는다 — 그 값은 감사 로그에만 있다 (ARCH 7.5 `exec_entries`).
           f.exec_content,
           f.team_id,
           f.folder_id,
           f.uploaded_by,
           t.name       AS team_name,
           u.username   AS uploader,
           f.created_at
      FROM files f
      JOIN users u ON u.id = f.uploaded_by
      LEFT JOIN teams t ON t.id = f.team_id
     WHERE
    SQL;

$sqlCount = <<<'SQL'
    SELECT count(*) AS total
      FROM files f
     WHERE
    SQL;

// 검색 조건. ESCAPE 문자는 '#' 을 쓴다 — PDO 의 이름 바인딩 파서가 `ESCAPE '\'` 뒤의
// 자리표시자를 놓치는 것을 실측으로 확인했다(같은 문장에 두 번째 :파라미터가 사라진다).
// 값 쪽에서 '#' '%' '_' 를 모두 무력화하므로 와일드카드 주입은 불가능하다.
$sqlSearch = <<<'SQL'

       AND (f.original_name ILIKE :q_name ESCAPE '#'
            OR f.description ILIKE :q_desc ESCAPE '#')
    SQL;

// 18-1 (F-26) — 폴더 필터. `IS NOT DISTINCT FROM` 은 NULL(루트)과 정수를 한 조건으로 다룬다
// (`= NULL` 은 언제나 UNKNOWN 이라 루트를 고를 수 없다). 목록과 건수에 **똑같이** 붙는다.
$sqlFolder = <<<'SQL'

       AND f.folder_id IS NOT DISTINCT FROM CAST(:folder AS bigint)
    SQL;

// ORDER BY 는 바인딩할 수 없다 → 허용 목록의 키로만 고정 조각을 고른다 (PRD S-04).
// 두 번째 정렬 키(f.id)를 붙여 같은 값이 여러 개일 때 페이지 사이에서 순서가 흔들리지 않게 한다.
$orderSql = [
    'name|asc'        => ' ORDER BY f.original_name ASC, f.id DESC',
    'name|desc'       => ' ORDER BY f.original_name DESC, f.id DESC',
    'created_at|asc'  => ' ORDER BY f.created_at ASC, f.id ASC',
    'created_at|desc' => ' ORDER BY f.created_at DESC, f.id DESC',
    'size|asc'        => ' ORDER BY f.size_bytes ASC, f.id DESC',
    'size|desc'       => ' ORDER BY f.size_bytes DESC, f.id DESC',
];
$sqlTail = ' LIMIT :lim OFFSET :off';

// ─── 조건·바인딩 조립 ───
// 전역 검색이면 폴더 조각을 붙이지 않는다(위 규칙). 그 밖에는 목록·건수에 똑같이 붙는다.
$filter = $globalSearch ? '' : $sqlFolder;
// 권한 바인딩(:lvl0.. · :own_team)과 검색·페이지 바인딩(:q_name · :q_desc · :lim · :off)은
// 이름이 겹치지 않는다 — 겹치면 한쪽이 덮여 조건이 조용히 바뀐다.
$bind   = $visibleBind;
if (!$globalSearch) {
    $bind[':folder'] = $folder === 0 ? null : $folder;
}
if ($q !== '') {
    $pattern           = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $filter            = $filter . $sqlSearch;
    $bind[':q_name']   = $pattern;
    $bind[':q_desc']   = $pattern;
}

$db = Db::instance();

// 전체 건수 — 목록과 **같은 조건**(권한 + 검색)으로 센다. 내가 볼 수 있는 개수만 나간다 (PRD S-02)
$countRow = $db->run($sqlCount . ' ' . $visibleWhere . $filter, $bind)->fetch();
$total    = is_array($countRow) ? (int) $countRow['total'] : 0;

$listBind         = $bind;
$listBind[':lim'] = $perPage;
$listBind[':off'] = ($page - 1) * $perPage;

$rows = $db->run(
    $sqlList . ' ' . $visibleWhere . $filter . $orderSql[$sort . '|' . $dir] . $sqlTail,
    $listBind
)->fetchAll();

/* ─── 행마다 「어느 폴더에 있나」 를 사람이 읽을 수 있는 경로로 (18-1 검토 결정) ───
 *
 * 전역 검색에서는 결과가 여러 폴더에 흩어져 있으므로 `folder_id` 숫자만으로는 쓸모가 없다.
 * 루트부터 이어 붙인 이름(`A / B / C`)을 함께 내려 준다.
 *
 * 못 읽는 폴더의 경로가 새어 나갈 걱정은 없다 — 여기 온 행은 이미 `visibleFilesWhere` 를
 * 통과한 파일이고, 불변 규칙 ①이 "그 파일이 보이면 그 폴더도 보인다" 를 보장한다 (ARCH 5.16).
 * 경로는 **표시용**이며 권한 판단에 쓰이지 않는다. 화면은 이 값을 textContent 로만 그린다.
 *
 * 한 문장으로 모든 폴더의 경로를 만든다(깊이는 최대 5, 폴더 수가 적다). 폴더가 하나도
 * 걸리지 않은 목록에서는 아예 묻지 않는다.
 */
$sqlFolderPaths = <<<'SQL'
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

$needPaths = false;
foreach ($rows as $row) {
    if ($row['folder_id'] !== null) {
        $needPaths = true;
        break;
    }
}

$folderPaths = [];
if ($needPaths) {
    foreach ($db->run($sqlFolderPaths)->fetchAll() as $pathRow) {
        $folderPaths[(int) $pathRow['id']] = (string) $pathRow['path'];
    }
}

$files = [];
foreach ($rows as $row) {
    // 여기 온 행은 위 조건절을 통과했으므로 이미 읽기 가능하다(decideDelete 의 전제).
    // can_delete 는 화면 편의 값이고, 실제 삭제 요청은 files_delete 가 다시 판단한다.
    $files[] = [
        'id'          => (string) $row['public_id'],
        'name'        => (string) $row['original_name'],
        'size_bytes'  => (int) $row['size_bytes'],
        'description' => (string) $row['description'],
        'access_level' => (string) $row['access_level'],
        'team_id'     => $row['team_id'] === null ? null : (int) $row['team_id'],
        'team_name'   => $row['team_name'] === null ? null : (string) $row['team_name'],
        // 18-1 (F-26) — 이 파일이 든 폴더. null 이면 루트다.
        'folder_id'   => $row['folder_id'] === null ? null : (int) $row['folder_id'],
        // 사람이 읽는 경로(`A / B`). 루트면 null. 표시용이며 권한 판단에 쓰이지 않는다.
        'folder_path' => $row['folder_id'] === null
            ? null
            : ($folderPaths[(int) $row['folder_id']] ?? null),
        'uploaded_by' => (string) $row['uploader'],
        'created_at'  => (string) $row['created_at'],
        'can_delete'  => Authz::decideDelete($user, $row)[0],
        // 5단계: 수정("설명·등급 바꾸기") 버튼을 보일지 정하는 같은 성격의 편의 값이다.
        // 실제 PATCH 는 files_update 가 decideRead → decideEdit → decideLevelChange 로 다시 판단한다.
        'can_edit'    => Authz::decideEdit($user, $row)[0],
        // 12-2 (F-18): 이 파일을 미리보기로 열면 어떤 모양이 나오나 — 서버가 DB `ext` 로
        // 판정한 갈래다(Preview::kindFor). 화면은 확장자를 보지 않는다(ARCH 5.8).
        // 값이 'none' 이어도 미리보기는 열 수 있고, 정보 카드만 나온다.
        'preview_kind' => Preview::kindFor((string) $row['ext']),
        // 15-3 (F-22): 민감정보 패턴이 걸린 파일인가 — 화면의 「민감」 배지에만 쓰는 표시용
        // 값이다. 배지가 보이든 안 보이든 등급 규칙은 서버가 판단한다 (PRD 10장).
        'sensitive'    => $row['sensitive'] === true,
        /* F-54 (PRD F-54 ③, ARCH 5.28): 악성코드 검사 상태 — 'pending'·'clean'·'infected'.
         * 화면은 이 값만 보고 「검사 중」·「격리됨」 배지를 붙이고 다운로드·미리보기 단추를
         * 비활성으로 둔다. 단추가 보이든 안 보이든 실제 차단은 서버가 다시 한다
         * (files_download·files_preview·files_preview_raw·share_download 의 403, PRD 10장).
         * ★ 아래 F-50 토큰 응답(간결한 배열 다섯 칸)에는 **넣지 않는다** — API.md 의
         *   안정성 약속(키를 늘리지 않는다)을 지킨다. 토큰 다운로드도 서버가 똑같이 막는다. */
        'scan_status'  => (string) $row['scan_status'],
        /* F-65 (PRD F-65 ③, ARCH 5.29 「목록·화면」): 실행 파일이 든 압축본인가.
         * 화면은 이 값 **하나만** 보고 배지를 붙이고, 다운로드 단추를 확인 창으로 바꾸고,
         * 공유 단추를 만들지 않고, 등급을 잠근다. 표시는 편의이고 근거는 서버다 (PRD S-01) —
         * 단추를 되살려도 여섯 관문이 다시 막는다.
         * ★ 아래 F-50 토큰 응답에는 **넣는다**(scan_status 와 다른 판단이다) — API.md 9-1 이
         *   「목록의 exec_content: true 인 파일은 토큰으로 받을 수 없다」 를 약속하므로,
         *   프로그램이 받기 전에 그 값으로 걸러 낼 수 있어야 한다. */
        'exec_content' => $row['exec_content'] === true,
    ];
}

/* ─── breadcrumb (루트 → 지금 연 폴더) ─────────────────────────
 * 조상들은 따로 권한을 보지 않는다: 불변 규칙 ①(하위 등급 ≥ 부모 등급)이 지켜지는 한
 * **읽을 수 있는 폴더의 조상은 언제나 읽을 수 있다** (ARCH 5.16, F-25 불변 규칙 (f)).
 * hops 상한은 데이터가 어떤 이유로든 순환할 때 무한 재귀를 막는 안전장치다(깊이는 최대 5).
 */
$sqlBreadcrumb = <<<'SQL'
    WITH RECURSIVE up AS (
        SELECT b.id, b.parent_id, b.name, b.depth, b.access_level, 1 AS hops
          FROM folders b
         WHERE b.id = CAST(:id AS bigint)
        UNION ALL
        SELECT p.id, p.parent_id, p.name, p.depth, p.access_level, up.hops + 1
          FROM folders p
          JOIN up ON p.id = up.parent_id
         WHERE up.hops < 10
    )
    SELECT up.id, up.name, up.access_level FROM up ORDER BY up.depth ASC
    SQL;

$breadcrumb = [];
if ($folder !== 0) {
    foreach ($db->run($sqlBreadcrumb, [':id' => $folder])->fetchAll() as $step) {
        $breadcrumb[] = [
            'id'           => (int) $step['id'],
            'name'         => (string) $step['name'],
            'access_level' => (string) $step['access_level'],
        ];
    }
}

/* ─── 26-2 (F-50) — 프로그램용 응답은 **간결한 배열** (PRD F-50, ARCH 4.3) ──────
 * 토큰 요청이면 최상위가 배열이고 원소 키는 다섯뿐이다: id·name·level·size·uploaded_at.
 * 화면 편의 값(can_delete·can_edit·preview_kind·sensitive)과 폴더 경로·팀 이름은 넣지 않는다 —
 * 프로그램에는 쓸모가 없고, 적게 내보내는 쪽이 정찰 재료도 적다(PRD S-09-5).
 * **보이는 파일 집합은 세션 응답과 똑같다** — 위 `visibleFilesWhere` 가 이미 SQL 에서 걸렀고
 * 여기서 무엇을 지우거나 더하지 않는다. 빈 결과는 `[]` 다.
 */
if ($viaToken) {
    Http::json(array_map(static fn (array $f): array => [
        'id'          => $f['id'],
        'name'        => $f['name'],
        'level'       => $f['access_level'],
        'size'        => $f['size_bytes'],
        'uploaded_at' => $f['created_at'],
        // F-65 (API.md 9-1) — 이 값이 참이면 `GET …/download` 는 403 이다. 프로그램이
        // 받기 전에 거를 수 있도록 여섯 번째 칸으로 내린다.
        'exec_content' => $f['exec_content'],
    ], $files));
    return;
}

Http::json([
    'files'    => $files,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
    // 18-1 검토 결정 — 이번 응답이 **폴더를 가리지 않은 전역 검색**인가. 화면은 이 값이
    // 참일 때만 「폴더」 경로 열을 보여 준다(표시용이며 권한과 무관하다).
    'scope'    => $globalSearch ? 'all' : 'folder',
    // 18-1 (F-26) — 지금 연 폴더. 루트는 id 0 · 이름 없음 · breadcrumb 빈 배열이다.
    'folder'   => [
        'id'           => $folder,
        'name'         => $folderRow === null ? null : (string) $folderRow['name'],
        'access_level' => $folderRow === null ? null : (string) $folderRow['access_level'],
        'team_id'      => $folderRow === null || $folderRow['team_id'] === null
            ? null
            : (int) $folderRow['team_id'],
        'breadcrumb'   => $breadcrumb,
    ],
]);
