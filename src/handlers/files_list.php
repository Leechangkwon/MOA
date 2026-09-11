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

$perPage = 20;

// ─── 입력 좁히기 (거부가 아니라 안전한 기본값으로 대체) ───
$q    = Http::queryText('q', 200);
$sort = Http::queryChoice('sort', ['name', 'created_at', 'size'], 'created_at');
$dir  = Http::queryChoice('dir', ['asc', 'desc'], 'desc');
$page = Http::queryInt('page', 1, 1, 100000);

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
           f.team_id,
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
// 권한 바인딩(:lvl0.. · :own_team)과 검색·페이지 바인딩(:q_name · :q_desc · :lim · :off)은
// 이름이 겹치지 않는다 — 겹치면 한쪽이 덮여 조건이 조용히 바뀐다.
$bind   = $visibleBind;
if ($q !== '') {
    $pattern           = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $filter            = $sqlSearch;
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
        'uploaded_by' => (string) $row['uploader'],
        'created_at'  => (string) $row['created_at'],
        'can_delete'  => Authz::decideDelete($user, $row)[0],
        // 5단계: 수정("설명·등급 바꾸기") 버튼을 보일지 정하는 같은 성격의 편의 값이다.
        // 실제 PATCH 는 files_update 가 decideRead → decideEdit → decideLevelChange 로 다시 판단한다.
        'can_edit'    => Authz::decideEdit($user, $row)[0],
    ];
}

Http::json([
    'files'    => $files,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
