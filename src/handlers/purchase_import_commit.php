<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_import_commit.php — POST /api/purchase/import/{id}/commit
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 19단계 엑셀 가져오기 **4단계: 확정** (PRD F-29 · T-44, 시안 ⑯, SECURITY R-67).
 *
 * 받는 값 (JSON, 허용 키 밖이면 400)
 *   kind     items | vendors | locations
 *   mapping  {엑셀 열 이름 → 우리 칸 이름}  — 미리보기에 쓴 것과 같아야 한다
 *   promote  (선택) 「확인 필요」 행 중 **갱신으로 올릴** 행 번호 목록 (시안 ⑯ 마지막 줄)
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

$reject = static function (string $reason, int $status, string $message) use ($auditTarget, $file): void {
    Audit::deny('purchase.import.commit', $reason, $auditTarget + [
        'detail' => ['file_id' => (int) $file['id'], 'sha256' => (string) $file['sha256']],
    ]);
    Http::error($status, $message);
};

$body = Http::jsonBody();
if ($body === [] || array_diff(array_keys($body), ['kind', 'mapping', 'promote']) !== []) {
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
    $resolved = Importer::resolve($kind, $sheet['rows'][0] ?? [], $mapping);
    $judged   = Importer::judge($kind, $sheet['rows'], $resolved);
} catch (RuntimeException $e) {
    $reject($e->getMessage(), 400, Http::MSG_BAD_REQUEST);
    exit;
}

$db = Db::instance();

// ─── 확정: 한 트랜잭션 (쓰기 + 감사 한 줄) ───
$db->begin();
try {
    $result = Importer::commit($kind, $judged['rows'], $promote, (int) $user['id']);

    Audit::allow('purchase.import.commit', $auditTarget + [
        'detail' => [
            'kind'     => $kind,
            'file_id'  => (int) $file['id'],
            'sha256'   => (string) $file['sha256'],
            'rows'     => $judged['total'],
            'inserted' => $result['inserted'],
            'updated'  => $result['updated'],
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    // 23505 = unique_violation — 같은 (거래처, 코드)가 파일 안에 또 있거나, 판정과 확정 사이에
    // 다른 요청이 같은 행을 먼저 넣었다. 전부 롤백됐으므로 기초정보는 확정 전 상태 그대로다.
    if ($e->getCode() === '23505') {
        $reject('conflict', 409, Http::MSG_CONFLICT);
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
    'import_id' => (string) $file['public_id'],
    'kind'      => $kind,
    'rows'      => $judged['total'],
    'counts'    => $judged['counts'],
    'inserted'  => $result['inserted'],
    'updated'   => $result['updated'],
    'skipped'   => $result['skipped'],
]);
