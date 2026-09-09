<?php
declare(strict_types=1);

/**
 * src/handlers/files_list.php — GET /api/files?q=&sort=&dir=&page= (정책: authenticated)
 *
 * ★ 3단계: 권한 검사 없음 — 4단계에서 `Authz::visibleFilesWhere($user)` 의 조건절을
 *   아래 WHERE 에 AND 로 붙인다. 지금 WHERE 는 `deleted_at IS NULL` + 검색 조건뿐이므로
 *   **모든 사용자가 모든 파일(admin-only 포함)을 본다**. 그것이 이 단계의 정상 동작이다
 *   (PRD 13장 3단계 "뚫려야 정상인 단계", 4단계 전후 비교용).
 *
 * 요구사항: PRD F-03 · F-04, 8장 API 표, ARCH 5.1
 *
 * 3단계에도 지키는 것
 *   · 값은 전부 바인딩한다. SQL 문자열에 값을 붙이지 않는다 (PRD S-04)
 *   · ORDER BY 는 바인딩할 수 없으므로 **허용 목록 → 고정 SQL 조각** 매핑만 쓴다 (ARCH 5.1 [3])
 *   · LIKE 값은 와일드카드(`%` `_`)와 이스케이프 문자를 무력화해서 넣는다
 *   · 목록 조회는 감사 로그를 남기지 않는다 (ARCH 5.1 마지막 줄, PRD S-07)
 *   · 내부 `files.id` 는 응답에 넣지 않는다. 외부 ID 는 `public_id`(UUID) 뿐이다 (PRD S-09-5)
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다)
 * @var list<string>        $params 경로 변수 없음
 */

$perPage = 20;

// ─── 입력 좁히기 (거부가 아니라 안전한 기본값으로 대체) ───
$q    = Http::queryText('q', 200);
$sort = Http::queryChoice('sort', ['name', 'created_at', 'size'], 'created_at');
$dir  = Http::queryChoice('dir', ['asc', 'desc'], 'desc');
$page = Http::queryInt('page', 1, 1, 100000);

// ─── SQL 조각 (모두 코드에 적힌 고정 문자열) ───
$sqlList = <<<'SQL'
    SELECT f.public_id,
           f.original_name,
           f.size_bytes,
           f.description,
           f.access_level,
           f.team_id,
           t.name       AS team_name,
           u.username   AS uploader,
           f.created_at
      FROM files f
      JOIN users u ON u.id = f.uploaded_by
      LEFT JOIN teams t ON t.id = f.team_id
     WHERE f.deleted_at IS NULL
    SQL;

$sqlCount = <<<'SQL'
    SELECT count(*) AS total
      FROM files f
     WHERE f.deleted_at IS NULL
    SQL;

// 검색 조건. ESCAPE 문자는 '#' 을 쓴다 — PDO 의 이름 바인딩 파서가 `ESCAPE '\'` 뒤의
// 자리표시자를 놓치는 것을 실측으로 확인했다(같은 문장에 두 번째 :파라미터가 사라진다).
// 값 쪽에서 '#' '%' '_' 를 모두 무력화하므로 와일드카드 주입은 불가능하다.
$sqlSearch = <<<'SQL'

       AND (f.original_name ILIKE :q_name ESCAPE '#'
            OR f.description ILIKE :q_desc ESCAPE '#')
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
$filter = '';
$bind   = [];
if ($q !== '') {
    $pattern           = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $filter            = $sqlSearch;
    $bind[':q_name']   = $pattern;
    $bind[':q_desc']   = $pattern;
}

$db = Db::instance();

// 전체 건수 — 목록과 **같은 조건**으로 센다 (권한 조건이 붙는 4단계에도 같은 구조를 유지한다)
$countRow = $db->run($sqlCount . $filter, $bind)->fetch();
$total    = is_array($countRow) ? (int) $countRow['total'] : 0;

$listBind         = $bind;
$listBind[':lim'] = $perPage;
$listBind[':off'] = ($page - 1) * $perPage;

$rows = $db->run($sqlList . $filter . $orderSql[$sort . '|' . $dir] . $sqlTail, $listBind)->fetchAll();

$files = [];
foreach ($rows as $row) {
    $files[] = [
        'id'          => (string) $row['public_id'],
        'name'        => (string) $row['original_name'],
        'size_bytes'  => (int) $row['size_bytes'],
        'description' => (string) $row['description'],
        'access_level' => (string) $row['access_level'],
        'team_id'     => $row['team_id'] === null ? null : (int) $row['team_id'],
        'team_name'   => $row['team_name'] === null ? null : (string) $row['team_name'],
        'uploaded_by' => (string) $row['uploader'],
        'created_at'  => (string) $row['created_at'],
    ];
}

Http::json([
    'files'    => $files,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
