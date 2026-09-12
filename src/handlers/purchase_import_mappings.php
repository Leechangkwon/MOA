<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_import_mappings.php
 *   GET · POST · DELETE /api/purchase/import/mappings
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 19단계 열 매핑 저장 (PRD F-29 「같은 양식은 매핑을 기억해 다음부터 두 단계로」, 시안 ⑯).
 *
 *   GET     저장된 매핑 목록 (종류·이름·매핑)
 *   POST    {kind, name, mapping, header_row} 저장. 같은 (종류, 이름)이면 **덮어쓴다**.
 *           `header_row`(0~19, 기본 0)를 함께 저장하는 이유: 이카운트처럼 0행이 제목이고
 *           1행이 진짜 머리행인 양식은, 매핑만 복원하고 머리행을 잊으면 매번 다시 골라야 한다
 *           — F-29 의 「같은 양식은 두 걸음으로 끝난다」 가 성립하지 않는다 (1차 보정 2026-09-13).
 *   DELETE  {id} — **행을 지우지 않는다.** `is_active = false` 로 내린다
 *           (p_* 에 DELETE 권한이 없다 — db/grants.sql). 라우트 이름이 DELETE 인 것은
 *           사람에게 "그만 쓴다" 를 뜻하고, 저장소에서는 중단이다(계정·팀과 같은 규칙).
 *
 * lint:no-branch-scope `p_import_mappings` 는 **지점 무관 마스터**다 — 엑셀 양식은 회사 전체가
 *   같은 것을 쓰고 지점마다 다르지 않다(브랜드·거래처·분류와 같은 성격). 그리고 이 라우트는
 *   buyer·Admin(전 지점)만 닿는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Sheet.php';
require_once __DIR__ . '/../purchase/Master.php';
require_once __DIR__ . '/../purchase/Importer.php';

$method = Http::method();

$reject = static function (string $reason, int $status, string $message): void {
    Audit::deny('purchase.master.update', $reason, [
        'target_type' => 'purchase_master',
        'target_name' => 'import_mappings',
        'detail'      => ['kind' => 'import_mappings'],
    ]);
    Http::error($status, $message);
};

$db = Db::instance();

// ─── GET — 목록 ───
if ($method === 'GET') {
    $sqlList = <<<'SQL'
        SELECT id, kind, name, mapping_json, header_row, created_at
          FROM p_import_mappings
         WHERE is_active
         ORDER BY kind ASC, name ASC
         LIMIT 100
        SQL;

    $items = [];
    foreach ($db->run($sqlList)->fetchAll() as $row) {
        $decoded = json_decode((string) $row['mapping_json'], true);
        $items[] = [
            'id'         => (int) $row['id'],
            'kind'       => (string) $row['kind'],
            'name'       => (string) $row['name'],
            'mapping'    => is_array($decoded) ? $decoded : [],
            'header_row' => (int) $row['header_row'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    Http::json(['items' => $items, 'targets' => Importer::TARGETS, 'required' => Importer::REQUIRED]);
    exit;
}

$body = Http::jsonBody();

// ─── DELETE — 중단 (행은 남는다) ───
if ($method === 'DELETE') {
    if ($body === [] || array_diff(array_keys($body), ['id']) !== []) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    try {
        $id = Master::id($body['id'] ?? null);
    } catch (RuntimeException $e) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
        exit;
    }

    $sqlFind = <<<'SQL'
        SELECT id, kind, name, is_active FROM p_import_mappings WHERE id = CAST(:id AS bigint)
        SQL;
    $row = $db->run($sqlFind, [':id' => $id])->fetch();
    if (!is_array($row) || $row['is_active'] !== true) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }

    $sqlStop = <<<'SQL'
        UPDATE p_import_mappings SET is_active = false, updated_at = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    $db->begin();
    try {
        $db->run($sqlStop, [':id' => $id]);
        Audit::allow('purchase.master.deactivate', [
            'target_type' => 'purchase_master',
            'target_id'   => $id,
            'target_name' => 'import_mappings:' . (string) $row['name'],
            'detail'      => [
                'kind'      => 'import_mappings',
                'target_id' => $id,
                'before'    => ['is_active' => true],
                'after'     => ['is_active' => false],
            ],
        ]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    Http::json(['id' => $id, 'is_active' => false]);
    exit;
}

// ─── POST — 저장(같은 종류·이름이면 덮어쓴다) ───
if ($body === [] || array_diff(array_keys($body), ['kind', 'name', 'mapping', 'header_row']) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

$kind = $body['kind'] ?? '';
if (!is_string($kind) || !in_array($kind, Importer::KINDS, true)) {
    $reject('bad_kind', 400, Http::MSG_BAD_REQUEST);
}

try {
    $name = Master::name($body['name'] ?? null, 80);
} catch (RuntimeException $e) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    exit;
}

/**
 * 매핑은 **한 겹 문자열 객체**만 받는다: {엑셀 열 이름 → 우리 칸 이름}.
 * 오른쪽 값은 `Importer::TARGETS` 안이거나 빈 값/`skip`(건너뜀)이어야 한다 —
 * 모르는 이름을 저장해 두면 다음 미리보기가 통째로 400 이 된다.
 * 열 이름은 파일마다 다르므로 형태(길이·제어문자)만 본다.
 */
$mappingRaw = $body['mapping'] ?? null;
if (!is_array($mappingRaw) || $mappingRaw === [] || count($mappingRaw) > 200) {
    $reject('bad_mapping', 400, Http::MSG_BAD_REQUEST);
}

$mapping = [];
foreach ($mappingRaw as $column => $target) {
    if (!is_string($column) || !is_string($target)) {
        $reject('bad_mapping', 400, Http::MSG_BAD_REQUEST);
    }
    $column = trim($column);
    if ($column === '' || preg_match('//u', $column) !== 1
        || iconv_strlen($column, 'UTF-8') > 100 || preg_match('/\p{C}/u', $column) === 1) {
        $reject('bad_mapping', 400, Http::MSG_BAD_REQUEST);
    }
    if ($target !== '' && $target !== 'skip' && !in_array($target, Importer::TARGETS[$kind], true)) {
        $reject('bad_mapping', 400, Http::MSG_BAD_REQUEST);
    }
    $mapping[$column] = $target;
}

$json = json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    $reject('bad_mapping', 400, Http::MSG_BAD_REQUEST);
}

/* 머리행 위치 — 0~19 정수만. 형식이 틀리면 400 이다(미리보기와 **같은 좁히기 함수**를 쓴다).
 * 여기서는 파일이 없으므로 감지로 되돌아갈 수 없다 → 값이 없으면 0(첫 행)으로 둔다.
 * DB 에도 같은 범위의 CHECK 가 있다(마지막 그물). */
$headerRow = 0;
if (array_key_exists('header_row', $body) && $body['header_row'] !== null && $body['header_row'] !== '') {
    try {
        $headerRow = Importer::headerRowFrom($body['header_row'], $kind, []);
    } catch (RuntimeException $e) {
        // `bad_header_row` 는 ARCH 7.4 문장 표에 없다 → 등록된 `bad_request` 로 남긴다
        // (없는 조합을 기록하면 기록이 실패해 요청이 500 이 된다).
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
        exit;
    }
}

// 같은 (종류, 이름)이면 덮어쓴다 — 사람이 「매핑 저장」 을 두 번 누르는 것이 정상 흐름이다.
// UNIQUE 인덱스가 lower(name) 이므로 ON CONFLICT 도 같은 식(expression)을 가리켜야 한다.
$sqlSave = <<<'SQL'
    INSERT INTO p_import_mappings (kind, name, mapping_json, header_row, created_by)
    VALUES (:kind, :name, CAST(:mapping AS jsonb), CAST(:header_row AS smallint), :created_by)
    ON CONFLICT (kind, lower(name))
    DO UPDATE SET mapping_json = EXCLUDED.mapping_json,
                  header_row   = EXCLUDED.header_row,
                  is_active    = true,
                  updated_at   = now()
    RETURNING id
    SQL;

$db->begin();
try {
    $row = $db->run($sqlSave, [
        ':kind'       => $kind,
        ':name'       => $name,
        ':mapping'    => $json,
        ':header_row' => $headerRow,
        ':created_by' => (int) $user['id'],
    ])->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('매핑을 저장하지 못했습니다');
    }
    $id = (int) $row['id'];

    Audit::allow('purchase.master.update', [
        'target_type' => 'purchase_master',
        'target_id'   => $id,
        'target_name' => 'import_mappings:' . $name,
        'detail'      => [
            'kind'      => 'import_mappings',
            'target_id' => $id,
            // 매핑은 열 이름 목록일 뿐 파일 내용이 아니다 — 그대로 남겨도 된다.
            'after'     => ['name' => $name, 'mapping' => $mapping, 'header_row' => $headerRow],
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'id'         => $id,
    'kind'       => $kind,
    'name'       => $name,
    'mapping'    => $mapping,
    'header_row' => $headerRow,
], 201);
