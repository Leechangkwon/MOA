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
 *   POST    {kind, name, mapping, header_row, options} 저장. 같은 (종류, 이름)이면 **덮어쓴다**.
 *           `options`(9차 보정 2026-09-14)는 그때 켜 둔 스위치와 기본 id 를 함께 기억한다 —
 *           허용 키는 `Importer::OPTION_KEYS`(미리보기가 받는 옵션 키와 같은 목록)뿐이고,
 *           값 타입이 어긋나면 400 이다. 이카운트 품목 파일은 「분류·거래처 함께 등록」 이
 *           꺼져 있으면 8,100행 중 대부분이 오류라, 이 값을 잊으면 두 걸음이 성립하지 않는다.
 *           `header_row`(0~19, 기본 0)를 함께 저장하는 이유: 이카운트처럼 0행이 제목이고
 *           1행이 진짜 머리행인 양식은, 매핑만 복원하고 머리행을 잊으면 매번 다시 골라야 한다
 *           — F-29 의 「같은 양식은 두 걸음으로 끝난다」 가 성립하지 않는다 (1차 보정 2026-09-13).
 *   DELETE  {id} — **행을 지우지 않는다.** `is_active = false` 로 내린다
 *           (p_* 에 DELETE 권한이 없다 — db/grants.sql). 라우트 이름이 DELETE 인 것은
 *           사람에게 "그만 쓴다" 를 뜻하고, 저장소에서는 중단이다(계정·팀과 같은 규칙).
 *
 * 지점 격리 예외 — `p_import_mappings` 는 **지점 무관 마스터**다. 엑셀 양식은 회사 전체가
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
    // lint:no-branch-scope p_import_mappings 는 지점 무관 마스터다 (브랜드·거래처·분류와 같다).
    $sqlList = <<<'SQL'
        SELECT id, kind, name, mapping_json, header_row, options_json, created_at
          FROM p_import_mappings
         WHERE is_active
         ORDER BY kind ASC, name ASC
         LIMIT 100
        SQL;

    $items = [];
    foreach ($db->run($sqlList)->fetchAll() as $row) {
        $decoded = json_decode((string) $row['mapping_json'], true);
        // 9차 보정 — 그때 켜 두었던 옵션도 함께 돌려준다(화면이 체크를 되살린다).
        $options = json_decode((string) $row['options_json'], true);
        $items[] = [
            'id'         => (int) $row['id'],
            'kind'       => (string) $row['kind'],
            'name'       => (string) $row['name'],
            'mapping'    => is_array($decoded) ? $decoded : [],
            'header_row' => (int) $row['header_row'],
            'options'    => (object) (is_array($options) ? $options : []),
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

    // lint:no-branch-scope 같은 표 한 건 읽기 — 지점 축이 없다.
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
if ($body === [] || array_diff(array_keys($body), ['kind', 'name', 'mapping', 'header_row',
                                                   // 9차 보정 — 그때 켜 둔 옵션도 함께 기억한다
                                                   'options']) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

/* 저장할 수 있는 종류는 `items|vendors|locations` 셋뿐이다 (p_import_mappings.kind CHECK).
 * `branches` 는 창고 파일에서 지점을 뽑아 **한 번 쓰고 마는** 흐름이라 저장할 매핑이 없다 —
 * 표의 CHECK 를 넓히는 대신 저장 창구가 막는다 (2차 보정). */
$kind = $body['kind'] ?? '';
if (!is_string($kind) || !in_array($kind, Importer::SAVABLE_KINDS, true)) {
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

/* ─── 옵션 — 매핑과 **함께** 기억한다 (9차 보정 2026-09-14) ───
 *
 * 왜 필요한가: 이카운트 품목 파일은 매핑만 복원해서는 8,100행 중 대부분이 오류다.
 * 「파일의 분류를 함께 등록」·「없는 거래처를 함께 등록」 두 스위치가 켜져 있어야 한다.
 * 그 둘을 기억하지 않으면 사람이 매달 같은 체크를 다시 켜야 하고, 한 번 잊으면 그 달
 * 가져오기가 통째로 오류다 — F-29 의 「같은 양식은 두 걸음으로 끝난다」 가 성립하지 않는다.
 *
 * 받는 것은 **한 겹 객체**이고 키는 `Importer::OPTION_KEYS`(= 미리보기가 받는 옵션 키와
 * 같은 목록)뿐이다. 값 타입은 하나씩 본다 — 모르는 키나 엉뚱한 타입을 저장해 두면 다음
 * 미리보기가 통째로 400 이 되거나(허용 키 검사), 사람이 켰다고 믿는 스위치가 꺼진 채로 돈다.
 */
$options = [];
if (array_key_exists('options', $body) && $body['options'] !== null) {
    $raw = $body['options'];
    if (!is_array($raw) || array_diff(array_keys($raw), Importer::OPTION_KEYS) !== []) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    foreach (['create_brands', 'create_branches', 'create_categories', 'create_vendors'] as $switch) {
        if (!array_key_exists($switch, $raw)) {
            continue;
        }
        if (!is_bool($raw[$switch])) {
            $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
        }
        $options[$switch] = $raw[$switch];
    }
    if (array_key_exists('derive', $raw)) {
        // 파생 모드는 정해진 한 값(또는 빈 값)뿐이다 — 모르는 값은 판정을 바꾸지 못하게 막는다.
        if (!is_string($raw['derive']) || !in_array($raw['derive'], ['', Importer::DERIVE_LOCATION_NAME], true)) {
            $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
        }
        if ($raw['derive'] !== '') {
            $options['derive'] = $raw['derive'];
        }
    }
    foreach (['default_branch', 'default_brand'] as $ref) {
        if (!array_key_exists($ref, $raw)) {
            continue;
        }
        // 값이 아니라 **id** 다. 양의 정수만 — 그 id 로 무엇을 할 수 있는지는 서버가 다시 판단한다.
        $text = is_int($raw[$ref]) ? (string) $raw[$ref] : (is_string($raw[$ref]) ? trim($raw[$ref]) : '');
        if (preg_match('/^[1-9][0-9]{0,8}$/', $text) !== 1) {
            $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
        }
        $options[$ref] = (int) $text;
    }
}

$optionsJson = json_encode((object) $options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($optionsJson === false) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

/* 머리행 위치 — 0~19 정수만. 형식이 틀리면 400 이다(미리보기와 **같은 좁히기 함수**를 쓴다).
 * 여기서는 파일이 없으므로 감지로 되돌아갈 수 없다 → 값이 없으면 0(첫 행)으로 둔다.
 * DB 에도 같은 범위의 CHECK 가 있다(마지막 그물). */
$headerRow = 0;
if (array_key_exists('header_row', $body) && $body['header_row'] !== null && $body['header_row'] !== '') {
    try {
        $headerRow = Importer::headerRowFrom($body['header_row'], $kind, []);
    } catch (RuntimeException $e) {
        // 2차 보정에서 `purchase.master.update|deny|bad_header_row` 를 ARCH 7.4 문장 표에
        // 등록했으므로 이제 **구체적인 사유 그대로** 남길 수 있다.
        $reject('bad_header_row', 400, Http::MSG_BAD_REQUEST);
        exit;
    }
}

// 같은 (종류, 이름)이면 덮어쓴다 — 사람이 「매핑 저장」 을 두 번 누르는 것이 정상 흐름이다.
// UNIQUE 인덱스가 lower(name) 이므로 ON CONFLICT 도 같은 식(expression)을 가리켜야 한다.
$sqlSave = <<<'SQL'
    INSERT INTO p_import_mappings (kind, name, mapping_json, header_row, options_json, created_by)
    VALUES (:kind, :name, CAST(:mapping AS jsonb), CAST(:header_row AS smallint),
            CAST(:options AS jsonb), :created_by)
    ON CONFLICT (kind, lower(name))
    DO UPDATE SET mapping_json = EXCLUDED.mapping_json,
                  header_row   = EXCLUDED.header_row,
                  options_json = EXCLUDED.options_json,
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
        ':options'    => $optionsJson,
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
            'after'     => ['name' => $name, 'mapping' => $mapping, 'header_row' => $headerRow,
                            // 9차 보정 — 켜 둔 스위치와 기본 id 뿐이다(파일 내용이 아니다).
                            'options' => $options],
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
    'options'    => (object) $options,
], 201);
