<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_import_commit.php — POST /api/purchase/import/{id}/commit
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 19단계 엑셀 가져오기 **4단계: 확정** (PRD F-29 · T-44, 시안 ⑯, SECURITY R-67).
 *
 * 받는 값 (JSON, 허용 키 밖이면 400)
 *   kind        items | vendors | locations
 *   mapping     {엑셀 열 이름 → 우리 칸 이름}  — 미리보기에 쓴 것과 같아야 한다
 *   header_row  (선택) 머리행의 위치 0~19 — **미리보기에 쓴 값과 같아야** 같은 결과가 나온다.
 *               생략하면 서버가 다시 감지한다 (1차 보정 2026-09-13).
 *   promote     (선택) 「확인 필요」 행 중 **갱신으로 올릴** 행 번호 목록 (시안 ⑯ 마지막 줄)
 *   exclude_rows(선택) **저장하지 않을** 행 번호 목록 (3차 보정 2026-09-13, 최대 1,000개).
 *               화면의 행 체크박스를 끈 행들이다 — 창고명에서 뽑은 지점 후보 중
 *               「임시」·「상담실」 처럼 지점이 아닌 것을 빼는 용도가 첫 번째다.
 *               **판정을 바꾸지 않고 쓰기만 건너뛴다** — 미리보기에서 본 판정과
 *               확정의 판정이 어긋나면 그 자체가 사고다.
 *
 * ★ **한 트랜잭션**이다 (R-67). 행 하나가 실패하면 앞서 넣은 행까지 전부 되돌아간다 —
 *   1.3만 행 중 7천 행만 들어간 상태가 남지 않는다. 오류 행은 애초에 저장하지 않는다.
 *
 * ★ 상한에서 끊긴 파일(`truncated`)은 **400 이다**. 2만 행에서 잘린 파일을 확정하면
 *   "다 넣었다" 는 응답과 달리 뒤가 비게 되고, 사람은 그것을 알 방법이 없다.
 *
 * ★ 감사는 **한 줄**이다 (지시서 보충 8): `purchase.import.commit allow`,
 *   detail 에 `file_id`·`sha256`·`rows`·`inserted`·`updated`. 행마다 남기면 1.3만 줄이 되어
 *   감사 로그가 쓸모없어진다(그리고 그 자체로 로그를 밀어내는 공격이 된다).
 *
 * lint:no-branch-scope 쓰기 라우트다 — `purchase.master.write` 는 buyer·Admin 만이고 그 둘의
 *   지점 범위는 언제나 전 지점(`1=1`)이다. 파일은 `Importer::findFile` 이 「구매/가져오기」
 *   폴더 하나로 좁힌다(그 함수가 정보 노출 차단의 자리다).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = files.public_id (UUID)
 */

require_once __DIR__ . '/../purchase/Sheet.php';
require_once __DIR__ . '/../purchase/Master.php';
require_once __DIR__ . '/../purchase/Importer.php';

$file = Importer::findFile($params[0] ?? '');
if ($file === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$auditTarget = [
    'target_type' => 'file',
    'target_id'   => (int) $file['id'],
    'target_name' => (string) $file['original_name'],
];

/**
 * 거부 기록 후 끝낸다.
 *
 * ★ 사유 코드는 **ARCH 7.4 문장 표에 등록된 것만** 쓸 수 있다(없는 조합 = 기록 실패 = 500).
 *   1차 보정에서 더한 `bad_header_row` 는 표에 없으므로 등록된 `bad_request` 로 남기고
 *   구체적인 사유는 `detail.why` 에 적는다 (미리보기 핸들러와 같은 방식).
 */
$reject = static function (string $reason, int $status, string $message, array $detail = []) use ($auditTarget, $file): void {
    Audit::deny('purchase.import.commit', $reason, $auditTarget + [
        'detail' => ['file_id' => (int) $file['id'], 'sha256' => (string) $file['sha256']] + $detail,
    ]);
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
if ($body === [] || array_diff(array_keys($body), ['kind', 'mapping', 'promote', 'header_row',
                                          'default_branch', 'default_brand', 'derive',
                                          // 5차 보정 — 없는 브랜드·지점을 함께 만들까
                                          'create_brands', 'create_branches',
                                          // 9차 보정 — 파일의 분류·없는 거래처를 함께 만들까(품목)
                                          'create_categories', 'create_vendors',
                                          'exclude_rows',
                                          /* 6차 보정의 표시용 키 셋 — 화면은 미리보기·확정에 **같은
                                           * 본문 함수**를 쓴다. 확정에서는 뜻이 없어 **받아서 무시**한다
                                           * (판정·저장 범위는 언제나 전체 행). 이 셋이 목록에 없어
                                           * 2026-09-13 20:05 확정이 전부 `bad_request` 로 막혔다. */
                                          'page', 'q', 'status']) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

/* 9차 보정 — 「함께 만들기」 스위치는 **참·거짓만** 받는다 (미리보기와 같은 규칙).
 * 미리보기와 확정이 같은 값을 같은 방식으로 읽어야 판정이 어긋나지 않는다. */
foreach (['create_categories', 'create_vendors'] as $switch) {
    if (array_key_exists($switch, $body) && !is_bool($body[$switch])) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST, ['why' => 'bad_option']);
    }
}

$kind = $body['kind'] ?? '';
if (!is_string($kind) || !in_array($kind, Importer::KINDS, true)) {
    $reject('bad_kind', 400, Http::MSG_BAD_REQUEST);
}
$mapping = $body['mapping'] ?? null;
if (!is_array($mapping)) {
    $reject('bad_mapping', 400, Http::MSG_BAD_REQUEST);
}

// 승격 목록 — 정수 행 번호만. 목록이 길어도 판정이 review 가 아닌 행에는 아무 영향이 없다.
$promote = [];
if (array_key_exists('promote', $body)) {
    if (!is_array($body['promote'])) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    foreach ($body['promote'] as $raw) {
        $text = is_int($raw) ? (string) $raw : (is_string($raw) ? $raw : '');
        if (preg_match('/^[0-9]{1,7}$/', $text) !== 1) {
            $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
        }
        $promote[] = (int) $text;
    }
}

/* 제외 행 — 정수 목록만, 최대 1,000개 (3차 보정).
 * 상한을 두는 이유: 목록 길이에 비례해 요청 본문과 비교 비용이 늘고, 1,000개를 넘겨 빼야 할
 * 정도면 **파일이나 매핑이 잘못된 것**이라 사람이 다시 봐야 한다. 형식이 아니면 400 이다
 * (승격 목록 `promote` 와 같은 규칙).
 */
$excludeRows = [];
if (array_key_exists('exclude_rows', $body)) {
    if (!is_array($body['exclude_rows']) || count($body['exclude_rows']) > 1000) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    foreach ($body['exclude_rows'] as $raw) {
        $text = is_int($raw) ? (string) $raw : (is_string($raw) ? $raw : '');
        if (preg_match('/^[0-9]{1,7}$/', $text) !== 1) {
            $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
        }
        $excludeRows[] = (int) $text;
    }
}

// ─── 추출 ───
try {
    $sheet = Sheet::rows(Storage::pathFor((string) $file['stored_name']), (string) $file['ext']);
} catch (Throwable $e) {
    $reject('parse_failed', 400, Http::MSG_BAD_REQUEST);
    exit;
}

if ($sheet['truncated']) {
    // 끊긴 파일은 확정하지 않는다 — 반쯤 들어간 기초정보가 가장 고치기 어렵다.
    $reject('truncated', 400, Http::MSG_BAD_REQUEST);
}

// ─── 판정 (미리보기와 **같은 함수·같은 입력** → 같은 결과) ───
try {
    // 미리보기와 **같은 입력**(파일 + 매핑 + 머리행)이면 같은 결과가 나온다 — 그래서
    // 중간 판정을 표에 저장하지 않는다. 머리행이 어긋나면 판정도 어긋나므로 화면은
    // 미리보기에 쓴 값을 그대로 보낸다.
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
        /* 9차 보정 — 품목 파일의 분류·없는 거래처. 판정이 세운 계획(`$judged['plan']`)을
         * 아래 확정이 **같은 트랜잭션에서** 만든다. */
        'create_categories' => ($body['create_categories'] ?? null) === true,
        'create_vendors'    => ($body['create_vendors'] ?? null) === true,
    ]);
} catch (RuntimeException $e) {
    $why = $e->getMessage();
    $reject($knownReason($why), 400, Http::MSG_BAD_REQUEST, ['why' => $why]);
    exit;
}

$db = Db::instance();

/* 감사에 적을 **앞 5개 이름** (9차 보정). 판정이 준 목록의 앞부분만 쓴다 — 사람이 로그에서
 * "아, 그때 이 분류들이 생겼구나" 를 알아보기에 충분하고, detail 이 커지지 않는다. */
$newCategoryNames = [];
foreach (array_slice($judged['to_create']['categories'] ?? [], 0, 5) as $cat) {
    $newCategoryNames[] = (string) ($cat['name'] ?? '');
}
$newVendorNames = [];
foreach (array_slice($judged['to_create']['vendors'] ?? [], 0, 5) as $ven) {
    $newVendorNames[] = (string) $ven;
}

// ─── 확정: 한 트랜잭션 (쓰기 + 감사 한 줄) ───
$db->begin();
try {
    // 9차 보정 — 판정이 세운 「새로 만들 분류·거래처」 계획을 함께 넘긴다. 만드는 것도 이 안이다.
    $result = Importer::commit($kind, $judged['rows'], $promote, (int) $user['id'], $excludeRows,
                               is_array($judged['plan'] ?? null) ? $judged['plan'] : []);

    Audit::allow('purchase.import.commit', $auditTarget + [
        'detail' => [
            'kind'     => $kind,
            'file_id'  => (int) $file['id'],
            'sha256'   => (string) $file['sha256'],
            'rows'       => $judged['total'],
            'header_row' => $headerRow,
            'inserted'   => $result['inserted'],
            'updated'    => $result['updated'],
            // 3차 보정 — 사람이 **일부러 뺀** 행 수. "왜 90행 중 88행만 들어갔나" 의 답이다.
            'excluded'   => $result['excluded'],
            /* 5차 보정 — 창고 가져오기가 **덤으로 만든** 브랜드·지점 수.
             * 기초정보가 언제 어떻게 늘었는지를 이 한 줄로 되짚을 수 있어야 한다
             * (브랜드·지점은 손으로 만들 때 건건이 감사에 남는 값이다). */
            'brands_created'     => $result['brands_created'],
            'branches_created'   => $result['branches_created'],
            'locations_inserted' => $kind === 'locations' ? $result['inserted'] : 0,
            /* 9차 보정 — 품목 가져오기가 **함께 만든** 분류·거래처 수와 **앞 5개 이름**.
             * 전부 나열하지 않는 이유: 실제 파일은 분류가 157개다. 기록은 "언제 얼마나 늘었나"
             * 를 되짚게 하는 것이 목적이고, 무엇이 늘었는지는 p_categories 를 보면 된다
             * (감사 detail 이 커지면 로그 자체가 쓸모없어진다 — 행마다 안 남기는 것과 같은 판단). */
            'categories_created' => $result['categories_created'],
            'vendors_created'    => $result['vendors_created'],
            'new_category_names' => $newCategoryNames,
            'new_vendor_names'   => $newVendorNames,
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    // 23505 = unique_violation — 같은 (거래처, 코드)가 파일 안에 또 있거나, 판정과 확정 사이에
    // 다른 요청이 같은 행을 먼저 넣었다. 전부 롤백됐으므로 기초정보는 확정 전 상태 그대로다.
    if ($e->getCode() === '23505') {
        /* 2차 보정 — **여기 오지 않는 것이 목표다.** 겹치는 행은 판정 단계에서 「확인 필요」로
         * 걸러지므로(Importer::judgeVendor 의 name_exists·duplicate_name_in_file), 확정에서
         * 유일 제약에 부딪히는 것은 "판정과 확정 사이에 다른 요청이 같은 값을 넣었다" 뿐이다.
         *
         * 응답은 고정 문구 409 그대로다(어느 행·어느 값인지 응답에 담지 않는다 — PRD S-09-5).
         * 대신 **어느 제약이 막았는지** 를 감사 detail 에 남긴다 — 이름은 규칙 이름이지 값이
         * 아니라 비밀이 없고, 이것이 없으면 2026-09-13 처럼 "409 인데 왜인지 모른다" 가 된다. */
        $info       = $e->errorInfo[2] ?? '';
        $constraint = preg_match('/"([a-z0-9_]+)"/i', is_string($info) ? $info : '', $m) === 1
            ? $m[1] : 'unknown';
        $reject('conflict', 409, Http::MSG_CONFLICT, ['constraint' => $constraint]);
    }
    if ($e->getCode() === '23503') {
        $reject('bad_reference', 400, Http::MSG_BAD_REQUEST);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'import_id'  => (string) $file['public_id'],
    'kind'       => $kind,
    'header_row' => $headerRow,
    'rows'       => $judged['total'],
    'counts'    => $judged['counts'],
    'inserted'  => $result['inserted'],
    'updated'   => $result['updated'],
    'skipped'   => $result['skipped'],
    'excluded'  => $result['excluded'],
    'brands_created'   => $result['brands_created'],
    'branches_created' => $result['branches_created'],
    // 9차 보정 — 화면이 결과 줄에 「함께 만든 분류 N · 거래처 N」 을 적는다
    'categories_created' => $result['categories_created'],
    'vendors_created'    => $result['vendors_created'],
]);
