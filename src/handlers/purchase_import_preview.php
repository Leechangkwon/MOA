<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_import_preview.php — POST /api/purchase/import/{id}/preview
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 19단계 엑셀 가져오기 **3단계: 미리보기** (PRD F-29 · T-44, 시안 ⑯, SECURITY R-67).
 *
 * 받는 값 (JSON, 허용 키 밖이면 400)
 *   kind        items | vendors | locations
 *   mapping     {엑셀 열 이름 → 우리 칸 이름}   ("" 또는 "skip" 은 건너뜀)
 *   header_row  (선택) 머리행의 위치 0~19. **그 다음 행부터 데이터**다.
 *               생략하면 서버가 `Importer::detectHeaderRow` 로 다시 감지한다 —
 *               화면이 값을 안 보내도 동작이 달라지지 않게 (1차 보정 2026-09-13).
 *
 * 돌려주는 값
 *   counts  {insert, update, review, error}  — **전체** 행 기준
 *   rows    앞 200행의 판정 (전부 내려 주면 1.3만 줄짜리 JSON 이 된다)
 *   errors  오류·확인 행만 따로 (사람이 고칠 목록)
 *
 * **DB 를 읽기만 한다.** 이 창구로는 아무것도 저장되지 않는다 — 확정은 commit 이다.
 *
 * ★ 파일을 고르는 규칙 (정보 노출 차단)
 *   `{id}` 는 `files.public_id` 다. 그런데 이 라우트에 닿는 사람(buyer)은 본체에서 Admin 이
 *   아닐 수 있고, 보관 폴더의 파일은 `admin-only` 다. 아무 UUID 나 넣어 남의 admin-only
 *   문서를 표로 뽑아 가는 창구가 되지 않도록, **「구매/가져오기」 폴더 안의 xlsx·csv 만**
 *   받는다. 폴더가 다르거나 없는 id 는 똑같이 404 다 (PRD S-09-5 — 있고 없고를 알려 주지 않는다).
 *
 * 지점 격리 예외 — 가져오기 파일과 매핑은 지점에 묶이지 않는다. 보관 폴더는 admin-only
 *   한 곳이고, 판정이 읽는 기초정보(거래처·분류·품목)는 지점 무관 마스터다. 장소 가져오기의
 *   지점은 **파일에 적힌 지점 이름**으로 찾으며, 그 쓰기 자격은 buyer·Admin(전 지점)뿐이다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = files.public_id (UUID)
 */

require_once __DIR__ . '/../purchase/Sheet.php';
require_once __DIR__ . '/../purchase/Master.php';
require_once __DIR__ . '/../purchase/Importer.php';

/**
 * 거부 기록 후 끝낸다.
 *
 * ★ 사유 코드는 **ARCH 7.4 문장 표에 등록된 것만** 쓸 수 있다 — 없는 조합을 기록하면
 *   기록 자체가 실패하고 요청이 500 이 된다(그것이 설계다: 새 사유를 만든 사람이 표를
 *   빠뜨리면 첫 요청에서 드러난다). 1차 보정에서 더한 `bad_header_row` 는 아직 표에 없으므로
 *   **등록된 `bad_request` 로 남기고 구체적인 사유는 `detail.why` 에 적는다** —
 *   진단은 되고 문장 표는 깨지지 않는다. (표에 행을 더하려면 src/Audit.php 를 고쳐야 하는데,
 *   1차 보정의 허용 파일이 아니다.)
 */
$reject = static function (string $reason, int $status, string $message, array $detail = []): void {
    $entry = ['target_type' => 'file'];
    if ($detail !== []) {
        $entry['detail'] = $detail;
    }
    Audit::deny('purchase.import.preview', $reason, $entry);
    Http::error($status, $message);
};

/** 표에 등록된 사유만 통과시키고, 나머지는 `bad_request` 로 돌린다 (위 ★ 주석) */
$knownReason = static function (string $why): string {
    return in_array($why, ['bad_kind', 'bad_mapping', 'mapping_incomplete',
                           'too_many_rows', 'parse_failed',
                           // 2차 보정에서 ARCH 7.4 문장 표에 등록한 셋
                           'bad_header_row', 'default_branch_required', 'default_brand_required'],
                     true) ? $why : 'bad_request';
};

$file = Importer::findFile($params[0] ?? '');
if ($file === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

/**
 * 화면이 보낸 「기본 지점」·「기본 브랜드」를 **양의 정수 id** 로만 좁힌다 (2차 보정).
 * 형식이 아니면 0(=없음)이다 — 400 을 내지 않는 이유는, 없으면 judge() 가
 * `default_branch_required` 로 한 번에 말해 주기 때문이다(사유가 한 곳에 모인다).
 */
function tbox_import_ref(mixed $raw): int
{
    $text = is_int($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : '');
    return preg_match('/^[1-9][0-9]{0,8}$/', $text) === 1 ? (int) $text : 0;
}

$body = Http::jsonBody();
if ($body === [] || array_diff(array_keys($body), ['kind', 'mapping', 'header_row',
                                          'default_branch', 'default_brand', 'derive',
                                          // 5차 보정 — 없는 브랜드·지점을 함께 만들까
                                          'create_brands', 'create_branches',
                                          // 6차 보정 — 행 목록 페이지·필터 (판정은 전체 행 그대로)
                                          'page', 'q', 'status']) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

$kind = $body['kind'] ?? '';
if (!is_string($kind) || !in_array($kind, Importer::KINDS, true)) {
    $reject('bad_kind', 400, Http::MSG_BAD_REQUEST);
}
$mapping = $body['mapping'] ?? null;
if (!is_array($mapping)) {
    $reject('bad_mapping', 400, Http::MSG_BAD_REQUEST);
}

// ─── 추출 → 매핑 확인 → 판정 ───
try {
    $sheet = Sheet::rows(Storage::pathFor((string) $file['stored_name']), (string) $file['ext']);
} catch (Throwable $e) {
    $reject('parse_failed', 400, Http::MSG_BAD_REQUEST);
    exit;
}

if ($sheet['truncated']) {
    // 상한에서 끊긴 파일은 미리보기는 보여 주되, 확정은 commit 이 400 으로 막는다.
    // 여기서 끊긴 사실을 숨기면 "13,482행이라더니 2만 행만 들어갔다" 가 된다.
    Audit::error('purchase.import.preview', 'truncated', [
        'target_type' => 'file',
        'target_id'   => (int) $file['id'],
        'target_name' => (string) $file['original_name'],
        'detail'      => ['file_id' => (int) $file['id'], 'kind' => $kind],
    ]);
}

try {
    // 머리행 — 요청이 정한 값이 우선이고, 없으면 서버가 감지한다. 0~19 밖은 예외(400).
    $headerRow = Importer::headerRowFrom($body['header_row'] ?? null, $kind, $sheet['rows']);
    /* 파생 모드에서는 창고 매핑에 지점에 없는 칸(「구분」·「사용」)이 섞여 온다 — 같은 화면에서
     * 단추 하나를 누른 것이므로 매핑을 다시 고르게 하지 않고 지점에 있는 칸만 추린다 (2차 보정). */
    $useMapping = (($body['derive'] ?? '') === Importer::DERIVE_LOCATION_NAME)
        ? Importer::mappingForDerive($mapping)
        : $mapping;
    $resolved  = Importer::resolve($kind, $sheet['rows'][$headerRow] ?? [], $useMapping);
    /* 2차 보정 — 화면이 정한 기본값들. **값이 아니라 id 이고 전부 정수로 좁힌다.**
     * 이 셋이 판정을 바꾸지만 권한을 바꾸지는 않는다: 기초정보 쓰기 자격은 이미
     * `purchase.master.write`(buyer·Admin, 전 지점) 이고, 그 둘에게는 지점 범위가 없다. */
    $judged    = Importer::judge($kind, $sheet['rows'], $resolved, $headerRow, [
        'default_branch' => tbox_import_ref($body['default_branch'] ?? null),
        'default_brand'  => tbox_import_ref($body['default_brand'] ?? null),
        'derive'         => is_string($body['derive'] ?? null) ? (string) $body['derive'] : '',
        /* 5차 보정 — 화면의 체크박스 둘. **true 일 때만** 참이다(문자열 "false" 를 참으로
         * 읽지 않는다). 이 둘이 판정을 바꾸지만 권한을 바꾸지는 않는다 — 브랜드·지점을
         * 만드는 자격은 이미 `purchase.master.write`(buyer·Admin) 이고, 그 창구로 손수
         * 만드는 것과 **같은 표·같은 제약**에 들어간다. */
        'create_brands'   => ($body['create_brands'] ?? null) === true,
        'create_branches' => ($body['create_branches'] ?? null) === true,
    ]);
} catch (RuntimeException $e) {
    $why = $e->getMessage();
    $reject($knownReason($why), 400, Http::MSG_BAD_REQUEST, ['why' => $why]);
    exit;
}

$db = Db::instance();

/* 2차 보정 — 미리보기 표에 **어느 지점으로 갈지**를 보여 준다.
 * 창고 파일은 지점이 열·창고명·기본값 셋 중 하나에서 오므로, 저장하기 전에 사람이
 * "이 창고가 저 지점으로 들어가는구나" 를 눈으로 확인할 수 있어야 한다.
 * 지점 이름 표는 여기서 한 번만 읽는다(행마다 조회하지 않는다).
 */
// lint:no-branch-scope 지점 **이름표**를 읽는 조회다 — 좁힐 대상이 아니라 좁힐 때 쓰는 표이고,
// 이 라우트는 buyer·Admin(전 지점)만 닿는다.
$sqlBranchNames = <<<'SQL'
    SELECT id, name FROM p_branches
    SQL;
$branchNames = [];
foreach ($db->run($sqlBranchNames)->fetchAll() as $b) {
    $branchNames[(int) $b['id']] = (string) $b['name'];
}

// 응답용으로 줄인다 — `values` 는 서버 내부 값이라 내려 주지 않는다(화면은 원본 칸만 본다).
$slim = static function (array $row) use ($branchNames): array {
    $out = [
        'row_no' => (int) $row['row_no'],
        'status' => (string) $row['status'],
        'reason' => $row['reason'] === null ? null : (string) $row['reason'],
        'data'   => is_array($row['data'] ?? null) ? $row['data'] : [],
    ];
    // 지점이 정해진 행이면 **어느 지점인지와 무엇을 보고 정했는지**를 함께 준다.
    $branchId = $row['values']['branch_id'] ?? null;
    if ($branchId !== null) {
        $out['branch_id']   = (int) $branchId;
        $out['branch_name'] = $branchNames[(int) $branchId] ?? null;
    }
    if (isset($row['branch_by']) && $row['branch_by'] !== '') {
        $out['branch_by'] = (string) $row['branch_by'];   // column | name | default
    }
    /* 3차 보정 — **무엇이 없어서 막혔나**. 화면이 그 행 옆에 「거래처 등록」 단추를 세우고,
     * 누르면 이 값이 채워진 등록 모달을 연다 (사용자 결정 2026-09-13 「접근한 페이지에서
     * 필요한 정보에 대한 팝업창을 띄워 등록」). 값은 **파일에 적힌 글자 그대로**다. */
    if (isset($row['missing']) && is_array($row['missing'])) {
        $out['missing'] = $row['missing'];
    }
    /* 7차 보정 — 창고명에서 갈라 낸 **장소 상세**(`B1F_의약품창고`). 표시용이며 저장되지 않는다
     * (창고 이름은 파일 원문 그대로 들어간다 — 이카운트 화면과 나란히 놓고 볼 수 있게). */
    if (isset($row['detail'])) {
        $out['detail'] = (string) $row['detail'];
    }
    if (isset($row['vendor_count'])) {
        $out['vendor_count'] = (int) $row['vendor_count'];   // 같은 이름의 거래처 수 (R-72)
    }
    return $out;
};

/* ─── 행 목록은 **50행씩** 내려 준다 (6차 보정 2026-09-13, 사용자 결정) ───
 *
 * 사용자 관찰: "엑셀을 올리면 하단에 정보가 굉장히 길게 나오고 확정은 맨 아래에 나온다."
 * 8,100행짜리 파일의 앞 200행을 한 번에 그리면 화면이 끝없이 길어지고, 확정 단추가
 * 스크롤 저 아래로 밀린다. **판정은 전과 똑같이 전체 행에 대해** 하고(그래야 `counts`·
 * `missing`·`to_create` 가 진실이다), 화면에 보내는 목록만 한 쪽씩 자른다.
 *
 * 거르기도 **서버에서** 한다 — 화면이 50행만 들고 거르면 「오류만 보기」가 그 50행 안에서만
 * 동작해 사람을 속인다.
 *   status  등록·갱신·확인 필요·오류 중 하나 (그 밖은 전체)
 *   q       코드·이름 **부분 일치**. 값은 행의 원본 칸에서만 찾고 SQL 에 닿지 않는다
 *           (판정 결과는 이미 메모리에 있다 — 여기서 DB 를 다시 묻지 않는다).
 */
$pageRaw  = $body['page'] ?? 1;
$pageText = is_int($pageRaw) ? (string) $pageRaw : (is_string($pageRaw) ? trim($pageRaw) : '');
$rowPage  = preg_match('/^[1-9][0-9]{0,5}$/', $pageText) === 1 ? (int) $pageText : 1;

$statusRaw = $body['status'] ?? '';
$rowStatus = is_string($statusRaw) && in_array($statusRaw, [
    Importer::STATUS_INSERT, Importer::STATUS_UPDATE,
    Importer::STATUS_REVIEW, Importer::STATUS_ERROR,
], true) ? $statusRaw : '';

$rowQuery = '';
if (isset($body['q']) && is_string($body['q']) && preg_match('//u', $body['q']) === 1) {
    $rowQuery = trim((string) $body['q']);
    if (iconv_strlen($rowQuery, 'UTF-8') > 100) {
        $cut      = iconv_substr($rowQuery, 0, 100, 'UTF-8');
        $rowQuery = is_string($cut) ? $cut : '';
    }
}
$rowQueryLower = strtolower($rowQuery);

$attention = [];
$matched   = [];
foreach ($judged['rows'] as $row) {
    if (($row['status'] === Importer::STATUS_ERROR || $row['status'] === Importer::STATUS_REVIEW)
        && count($attention) < Importer::PREVIEW_ROWS) {
        $attention[] = $slim($row);
    }

    if ($rowStatus !== '' && $row['status'] !== $rowStatus) {
        continue;
    }
    if ($rowQueryLower !== '') {
        $hit = false;
        foreach (($row['data'] ?? []) as $cell) {
            if (is_string($cell) && $cell !== ''
                && strpos(strtolower($cell), $rowQueryLower) !== false) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            continue;
        }
    }
    $matched[] = $row;
}

$rowTotal  = count($matched);
$perPage   = Importer::PREVIEW_PAGE;
$pageCount = $rowTotal === 0 ? 1 : (int) ceil($rowTotal / $perPage);
if ($rowPage > $pageCount) {
    $rowPage = $pageCount;   // 필터를 좁혀 쪽 수가 줄면 마지막 쪽을 보여 준다(빈 표가 아니라)
}

$rows = [];
foreach (array_slice($matched, ($rowPage - 1) * $perPage, $perPage) as $row) {
    $rows[] = $slim($row);
}

/* ─── 「없어서 막힌 것」 을 값 기준으로 묶는다 (3차 보정) ───
 *
 * 같은 거래처 이름 때문에 막힌 행이 3,000개여도 사람이 등록할 것은 **거래처 하나**다.
 * 그래서 행 목록과 별개로 `{종류, 값, 몇 행}` 묶음을 만들어 준다 — 화면은 이 목록으로
 * 「○○ 등록 (이 값 3,000행)」 단추를 **한 번만** 세운다.
 *
 * 세는 범위는 **응답에 실리는 앞 200행이 아니라 판정한 전체 행**이다. 200행만 세면
 * 「이 값 12행」 이라고 써 놓고 확정하면 3,000행이 살아나는 거짓말이 된다.
 */
$missing = [];
foreach ($judged['rows'] as $row) {
    if (!isset($row['missing']) || !is_array($row['missing'])) {
        continue;
    }
    $kindOf = (string) ($row['missing']['kind'] ?? '');
    $value  = (string) ($row['missing']['value'] ?? '');
    if ($kindOf === '' || $value === '') {
        continue;   // 값이 비어 있으면 등록 모달을 채울 것이 없다(사람이 파일을 고쳐야 한다)
    }
    $key = $kindOf . "\n" . $value;
    if (!isset($missing[$key])) {
        $missing[$key] = [
            'kind'      => $kindOf,
            'value'     => $value,
            'reason'    => (string) ($row['reason'] ?? ''),
            'rows'      => 0,
            'first_row' => (int) $row['row_no'],
        ];
        // 분류는 **몇 단계의 어느 부모 밑**인지까지 있어야 같은 자리에 만들 수 있다.
        if (isset($row['missing']['level'])) {
            $missing[$key]['level'] = (int) $row['missing']['level'];
        }
        if (isset($row['missing']['parent_id'])) {
            $missing[$key]['parent_id'] = $row['missing']['parent_id'] === null
                ? null : (int) $row['missing']['parent_id'];
        }
    }
    $missing[$key]['rows']++;
}
// 많이 막은 것부터 — 사람이 하나만 등록해도 가장 많이 풀리는 순서다.
$missingList = array_values($missing);
usort($missingList, static fn (array $a, array $b): int => $b['rows'] <=> $a['rows']);
$missingList = array_slice($missingList, 0, 200);

Http::json([
    'import_id'  => (string) $file['public_id'],
    'kind'       => $kind,
    // 어느 행을 머리행으로 보고 판정했나 — 화면이 「2행을 머리행으로 봤습니다」 를 보여 준다
    'header_row' => $headerRow,
    'total'      => $judged['total'],
    'truncated' => $sheet['truncated'],
    'counts'    => $judged['counts'],
    'rows'      => $rows,
    // 6차 보정 — 이 목록이 **몇 쪽 중 몇 쪽인지**. counts·missing·to_create 는 전체 기준이다.
    'row_page'    => $rowPage,
    'row_per_page'=> $perPage,
    'row_total'   => $rowTotal,
    'row_pages'   => $pageCount,
    'row_status'  => $rowStatus,
    'row_q'       => $rowQuery,
    'attention' => $attention,
    // 3차 보정 — 「이것만 등록하면 N행이 풀린다」 목록
    'missing'   => $missingList,
    /* 5차 보정 — 이번 확정으로 **새로 생길** 브랜드·지점. 화면이 확정 전에
     * 「새로 만들 브랜드 3 · 지점 5」 로 보여 준다 — 무엇이 생기는지 모르고 누르는 일이 없게. */
    'to_create' => $judged['to_create'] ?? ['brands' => [], 'branches' => []],
]);
