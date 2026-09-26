<?php
declare(strict_types=1);

/**
 * src/handlers/activity_list.php — GET /api/me/activity?page= (정책: authenticated)
 *
 * ★ 15-1: 내 활동 보기 (PRD F-12, 8장 API 표, ARCH 5.11).
 *
 * 이 핸들러가 지키는 것
 *   · **대상은 언제나 세션 본인**이다. 요청에서 대상을 받지 않는다 — 조건절의 `:me` 에는
 *     라우터가 넘긴 $user 의 id 만 들어간다. `?actor=`·`?user_id=` 같은 값은 읽지도 않으므로
 *     주소에 무엇을 붙여도 남의 행이 나오지 않는다 (PRD S-02, T-33).
 *   · 요청 파라미터는 `page` **하나**뿐이고 Http::queryInt 가 형태·범위를 좁힌다.
 *   · 돌려주는 칸은 여섯 개뿐이다. `ip`·`user_agent`·`detail` 은 **SELECT 자체에 넣지 않는다** —
 *     응답에 없으면 새지 않는다(본인 것이라도 최소 노출, ARCH 5.11).
 *   · 행동 목록은 코드에 적힌 고정 배열이고, SQL 에는 값이 아니라 **자리표시자 이름**만 이어 붙인다.
 *     값은 전부 바인딩된다 (PRD S-04).
 *   · **이 조회는 감사 로그에 남기지 않는다** (PRD F-12) — 자기 이력 열람은 보안 사건이 아니다.
 *     (관리자의 `/api/audit` 조회는 남는다. 그쪽은 남의 기록을 보는 행위다.)
 *   · ★ 디버깅 묶음 S-1 (2026-09-26 보안 점검 중간 1) — **거부 행(result=deny)은 대상 이름·등급을 가린다.**
 *     거부된 대상은 그 사람이 **볼 수 없는** 것일 수 있다: Guest 가 남의 UUID·링크 번호로 내려받기·미리보기·
 *     수정·삭제·회수를 찔러 보면 거부 행에 admin-only 파일 이름이 남고, 전에는 그 이름이 여기로 되돌아 나왔다.
 *     그래서 이 한 곳에서 `target_name` 은 `Audit::MASKED_TARGET`, 요약 문장은 `Audit::maskedSummary()`
 *     (같은 문장 틀에 가린 글자를 넣어 다시 만든 것)로 바꿔 내보낸다. 허용·오류 행은 그대로다(본인이 한 일이고
 *     그 대상은 볼 수 있었던 것). 감사 원본(DB·파일 사본·관리자 감사 화면)은 한 글자도 바뀌지 않는다.
 *     가리는 것은 **권한 계열 사유**(role_not_permitted·team_mismatch·not_owner·not_found·not_found_in_trash)일 때만이다(검토 권고 —
 *     그 밖의 사유는 권한 판정을 지난 뒤라 본인이 볼 수 있는 대상이다: 검사 중·격리·형식 오류 …).
 *     예외 둘 — `file.upload`·`file.fetch` 의 거부 행은 가리지 않는다: 대상 이름이 **요청자가 보낸 파일 이름**
 *     (올린 파일·가져온 파일)이라 볼 수 없는 것이 아니고, 가리면 「왜 내 파일이 막혔나」를 본인이 알 수 없다
 *     (구현 뒤 화면 확인에서 「(볼 수 없는 대상)을 올리려 해서 거부」로 보인 것 — 구현 기록 S-1).
 *     `actor_name` 은 가린 문장을 다시 만들 때만 쓰고 응답에 새 칸으로 내보내지 않는다(칸 여섯 그대로).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다 — PRD S-01)
 * @var list<string>        $params 경로 변수 없음
 */

$perPage = 20;

/**
 * 보여 줄 행동 목록 (PRD F-12 고정 목록).
 *
 * 코드에 적힌 상수 배열이며 요청이 고르지 못한다. 여기 없는 행동(예: `authz.route`·`csrf`·
 * `auth.gate`)은 본인 행이라도 이 화면에 나오지 않는다 — 「내 활동」은 "내가 한 일"을 보는
 * 곳이지 관리자용 조사 화면이 아니기 때문이다(그쪽은 F-09 감사 로그 화면이다).
 */
$activityActions = [
    'file.upload', 'file.download', 'file.preview', 'file.update',
    'file.delete', 'file.restore', 'file.fetch',
    'share.create', 'share.revoke',
    'auth.login', 'auth.logout', 'auth.password_change',
    'auth.mfa_enroll', 'auth.mfa_disable',
];

/**
 * S-1 — 거부 행이라도 가리지 않는 행동. 대상 이름이 요청자가 **보낸** 파일 이름이다(남의 것이 아니다).
 * 그 밖의 거부 행(내려받기·미리보기·수정·삭제·복구·공유 만들기·회수 · 인증 계열)은 전부 가린다.
 */
$ownInputActions = ['file.upload', 'file.fetch'];

/**
 * S-1 · 검토 권고 — 가리는 것은 **권한 계열 사유**의 거부 행뿐이다: 그 대상을 볼(고칠) 자격이 없어서 막힌 행.
 * 사유가 이 밖이면(검사 중·격리·형식 오류·폴더 규칙·이미 회수됨 …) 권한 판정을 **지난 뒤**라 요청자가 볼 수 있는 대상이고,
 * 가리면 본인 파일이 왜 막혔는지를 본인이 모른다. 코드는 Authz 의 거부 코드와 존재를 숨기는 코드(`not_found*`)다.
 */
$maskedReasons = [
    Authz::CODE_ROLE_NOT_PERMITTED, Authz::CODE_TEAM_MISMATCH, Authz::CODE_NOT_OWNER,
    Authz::CODE_NOT_FOUND_IN_TRASH, 'not_found',
];

$page = Http::queryInt('page', 1, 1, 100000);

/*
 * 행동 목록을 `IN (:act0, :act1, …)` 로 바꾼다.
 * 이어 붙이는 것은 **자리표시자 이름**이고 값이 아니다 — 값은 아래 $bind 로만 들어간다.
 */
$actionPlaceholders = [];
$bind = [':me' => (int) $user['id']];
foreach ($activityActions as $i => $name) {
    $ph                  = ':act' . $i;
    $actionPlaceholders[] = $ph;
    $bind[$ph]            = $name;
}
$inList = implode(', ', $actionPlaceholders);

// ─── SQL 조각 (전부 코드에 적힌 고정 문자열) ───────────────────
// ip·user_agent·detail 은 어느 조각에도 없다 (ARCH 5.11).
$sqlRowsHead = <<<'SQL'
    SELECT happened_at,
           action,
           result,
           reason,
           actor_name,
           target_name,
           summary
      FROM audit_logs
     WHERE actor_id = :me
       AND action IN (
    SQL;

$sqlRowsTail = <<<'SQL'
    )
     ORDER BY id DESC
     LIMIT :lim OFFSET :off
    SQL;

$sqlCountHead = <<<'SQL'
    SELECT count(*) AS total
      FROM audit_logs
     WHERE actor_id = :me
       AND action IN (
    SQL;

$sqlCountTail = <<<'SQL'
    )
    SQL;

$db = Db::instance();

$countRow = $db->run($sqlCountHead . $inList . $sqlCountTail, $bind)->fetch();
$total    = is_array($countRow) ? (int) $countRow['total'] : 0;

$rowsBind         = $bind;
$rowsBind[':lim'] = $perPage;
$rowsBind[':off'] = ($page - 1) * $perPage;

$rows = $db->run($sqlRowsHead . $inList . $sqlRowsTail, $rowsBind)->fetchAll();

$out = [];
foreach ($rows as $row) {
    $reason     = $row['reason'] === null ? null : (string) $row['reason'];
    $targetName = $row['target_name'] === null ? null : (string) $row['target_name'];
    $summary    = (string) $row['summary'];

    // S-1 — 권한 계열로 막힌 행은 대상 이름·등급을 가린다(위 머리 주석 — 올리기·가져오기는 예외). 재료는 DB 의 result·action·reason 뿐이다.
    if ((string) $row['result'] === 'deny'
        && !in_array((string) $row['action'], $ownInputActions, true)
        && in_array($reason, $maskedReasons, true)
    ) {
        $targetName = $targetName === null ? null : Audit::MASKED_TARGET;
        $summary    = Audit::maskedSummary(
            (string) $row['action'],
            'deny',
            $reason,
            $row['actor_name'] === null ? null : (string) $row['actor_name']
        );
    }

    $out[] = [
        'happened_at' => (string) $row['happened_at'],
        'action'      => (string) $row['action'],
        'result'      => (string) $row['result'],
        'reason'      => $reason,
        'target_name' => $targetName,
        'summary'     => $summary,
    ];
}

Http::json([
    'rows'     => $out,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
