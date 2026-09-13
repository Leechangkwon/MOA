<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_close_create.php — POST /api/purchase/close
 *   (정책: purchase.close → buyer·Admin, **재인증 대상** `Authz::REAUTH_POLICIES`)
 *
 * ★ 22단계 **월 마감** (PRD F-35 · Q-18, ARCH 5.18 「마감」, 완료 기준 T-47 (c)).
 *
 * 본문 { month: 'YYYY-MM', branch_id?: int|null, reason_text?: string }
 *   branch_id 를 적지 않거나 null 이면 **전사 마감**이다 — 그 달의 모든 지점이 잠긴다.
 *
 * 마감은 "그 달의 전표를 더는 고치지 않는다" 는 선언이다. 그래서
 *   ① 그 달에 **미확정 전표**(unconfirmed·in_progress·partial)가 하나라도 있으면
 *      400 `open_docs_exist` — 진행 중인 요청·발주가 잠겨 버리는 것을 막는다.
 *   ② 마감하면 그 달 전표의 `p_docs.closed_month` 를 채운다(UPDATE, 앱 권한 안).
 *      그 표식과 `p_month_close` 행 둘 다 `Doc::closeGuard` 가 본다.
 *   ③ 마감 뒤의 수정·취소는 `purchase.close` + **사유**가 있어야 하고, 감사 detail 에
 *      `after_close = true` 가 남는다(경보 AL-14 가 그 행을 본다).
 *
 * 되돌리는 창구는 **없다**. 앱 계정에 `p_month_close` 의 DELETE 권한이 없고(마이그레이션
 * GRANT), 이 파일에도 지우는 코드가 없다 — 잘못 마감했으면 사유를 적고 고치는 길(③)로 간다.
 *
 * ★ 재인증: 라우터 관문 [3-2] 가 `purchase.close` 를 `REAUTH_POLICIES` 에서 보고 먼저 막는다.
 *   창이 닫혀 있으면 이 파일은 **실행되지 않는다**(403 `next:reauth`).
 *   다시 묻는 것은 **비밀번호 하나**다 — 2단계 인증 코드는 로그인의 순차 2단계에서 이미 거쳤으므로
 *   재인증 창구가 다시 묻지 않는다는 것이 본체 규칙이다(PRD F-23, src/handlers/reauth.php).
 *   ARCH 5.18 의 「재인증 + TOTP」 문구는 그 원칙에 맞춰 **「재인증」으로 고쳤다**
 *   (사용자·Fable 결정 2026-09-13). 겹으로 두는 것은 실패 5회 잠금·세션 회수와 nginx 속도 제한이다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Doc.php';

$db   = Db::instance();
$body = Http::jsonBody();

$monthForLog = '';
$reject = static function (string $reason, int $status = 400) use (&$monthForLog): void {
    Audit::deny('purchase.close.create', $reason, [
        'target_type' => 'purchase_close',
        'target_name' => $monthForLog !== '' ? $monthForLog : '?',
        'detail'      => ['month' => $monthForLog],
    ]);
    Http::error($status, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), ['month', 'branch_id', 'reason_text']) !== []) {
    $reject('bad_request');
}

$month = $body['month'] ?? null;
if (!is_string($month) || preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
    $reject('bad_request');
}
$monthForLog = $month;

$branchRaw = $body['branch_id'] ?? null;
$branchId  = null;
if ($branchRaw !== null && $branchRaw !== '') {
    if (!is_int($branchRaw) && !(is_string($branchRaw) && preg_match('/^[0-9]{1,9}$/', $branchRaw) === 1)) {
        $reject('bad_request');
    }
    $branchId = (int) $branchRaw;
    if ($branchId < 1) {
        $reject('bad_request');
    }
}

$reasonText = $body['reason_text'] ?? null;
if ($reasonText !== null && !is_string($reasonText)) {
    $reject('bad_request');
}
if (is_string($reasonText)
    && (preg_match('//u', $reasonText) !== 1 || iconv_strlen($reasonText, 'UTF-8') > 300)
) {
    $reject('bad_request');
}
$reasonText = ($reasonText === '' ? null : $reasonText);

/*
 * 지점 범위 — buyer·Admin 만 이 창구에 닿으므로 조각은 언제나 「조건 없음」이지만, 그래도
 * `purchaseScopeWhere` 를 지나게 둔다: 나중에 `purchase.close` 를 가진 역할이 늘어나는 날
 * 이 한 줄이 없으면 남의 지점을 마감할 수 있다 (판정은 언제나 Authz 한 곳 — ARCH 4.1).
 */
$scopeB = Authz::purchaseScopeWhere($user, 'b');

/*
 * 지점 자신에 조건을 걸 때는 `id AS branch_id` 를 내주는 CTE 로 연다 —
 * `Authz::PURCHASE_ALIASES` 의 `b` 가 그렇게 쓰이도록 적혀 있고,
 * 19단계 `purchase_masters_list.php` 도 같은 모양이다(조건 모양을 표마다 가르지 않는다).
 */
$sqlBranchHead = <<<'SQL'
    WITH b AS (SELECT p.*, p.id AS branch_id FROM p_branches p)
    SELECT b.id, b.name FROM b WHERE b.id = CAST(:id AS bigint) AND b.is_active AND
    SQL;

$branchName = '전사';
if ($branchId !== null) {
    $row = $db->run($sqlBranchHead . ' ' . $scopeB['sql'], [':id' => $branchId] + $scopeB['params'])->fetch();
    if (!is_array($row)) {
        $reject('not_in_scope');
    }
    $branchName = (string) $row['name'];
}

/** 그 달에 아직 확정되지 않은 전표 — 지점 마감이면 그 지점만, 전사 마감이면 전부 */
$sqlOpenDocs = <<<'SQL'
    SELECT count(*) AS n
      FROM p_docs d
     WHERE to_char(d.doc_date, 'YYYY-MM') = CAST(:month AS char(7))
       AND d.status IN ('unconfirmed', 'in_progress', 'partial')
       AND (CAST(:branch AS bigint) IS NULL OR d.branch_id = CAST(:branch AS bigint))
    SQL;

/** 마감 대상 전표에 표식을 찍는다 (이미 찍힌 것은 건드리지 않는다) */
$sqlMarkDocs = <<<'SQL'
    UPDATE p_docs
       SET closed_month = CAST(:month AS char(7)), updated_at = now()
     WHERE to_char(doc_date, 'YYYY-MM') = CAST(:month AS char(7))
       AND closed_month IS NULL
       AND (CAST(:branch AS bigint) IS NULL OR branch_id = CAST(:branch AS bigint))
    SQL;

$sqlInsertClose = <<<'SQL'
    INSERT INTO p_month_close (branch_id, month, closed_by, reason_text)
    VALUES (CAST(:branch AS bigint), CAST(:month AS char(7)), CAST(:by AS bigint), :reason)
    RETURNING id
    SQL;

$db->begin();
try {
    $openRow = $db->run($sqlOpenDocs, [':month' => $month, ':branch' => $branchId])->fetch();
    $open    = is_array($openRow) ? (int) $openRow['n'] : 0;
    if ($open > 0) {
        $db->rollback();
        Audit::deny('purchase.close.create', 'open_docs_exist', [
            'target_type' => 'purchase_close',
            'target_name' => $month,
            'detail'      => ['month' => $month, 'branch_id' => $branchId, 'lines' => $open],
        ]);
        Http::error(400, Http::MSG_BAD_REQUEST);
    }

    $closeRow = $db->run($sqlInsertClose, [
        ':branch' => $branchId,
        ':month'  => $month,
        ':by'     => (int) $user['id'],
        ':reason' => $reasonText,
    ])->fetch();
    if (!is_array($closeRow)) {
        throw new RuntimeException('월 마감을 기록하지 못했습니다');
    }
    $closeId = (int) $closeRow['id'];

    $marked = $db->run($sqlMarkDocs, [':month' => $month, ':branch' => $branchId])->rowCount();

    Audit::allow('purchase.close.create', [
        'target_type' => 'purchase_close',
        'target_id'   => $closeId,
        'target_name' => $month,
        'detail'      => [
            'month'       => $month,
            'branch_id'   => $branchId,
            'lines'       => (int) $marked,
            'reason_text' => $reasonText,
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    if ($e->getCode() === '23505') {
        // 같은 지점·같은 달을 두 번 마감할 수는 없다 (UNIQUE · 부분 UNIQUE 인덱스).
        $reject('conflict', 409);
    }
    if ($e->getCode() === '23503') {
        $reject('bad_reference');
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'id'          => $closeId,
    'month'       => $month,
    'branch_id'   => $branchId,
    'branch_name' => $branchName,
    'docs_closed' => (int) $marked,
], 201);
