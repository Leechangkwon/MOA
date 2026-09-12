<?php
declare(strict_types=1);

/**
 * src/handlers/audit_list.php — GET /api/audit (정책: audit.read → Admin 만, PRD 5.4 Q-8)
 *
 * 요구사항: PRD F-09 · S-07(7.7 "조회 권한"·"조회 화면 필터"·"개인정보 취급"), 8장 API 표,
 *           12장 T-12, ARCH 7.6(조회 설계) · 7.2(컬럼) · 7.5(detail 키 `filters`) · 5.1(목록 패턴)
 *
 * 지키는 것
 *   · **조회 자체를 기록한다.** SELECT 보다 먼저 `audit.view|allow` 를 남긴다 —
 *     "로그를 본 것" 도 로그다 (PRD 7.7 "감사 로그 조회", ARCH 7.6). 기록에 실패하면
 *     Audit 이 예외를 던지고 요청은 500 으로 끝난다(기록 없는 조회를 만들지 않는다).
 *   · 필터 값은 **전부 바인딩**한다. SQL 문자열에 값을 붙이지 않는다 (PRD S-04).
 *     `action`·`result`·`level` 은 코드에 적힌 **허용 목록**의 값만 통과한다.
 *     `reason`·`granted_by` 는 형태(`^[a-z_]{1,40}$`)를 먼저 검사한 뒤 바인딩한다.
 *   · 형식이 어긋난 값은 **400 이 아니라 무시**한다 (ARCH 5.1 [2][3] 과 같은 원칙).
 *     조회 화면의 파라미터에서 400 을 내면 화면만 깨지고 막아지는 것은 없다.
 *   · 정렬은 고정이다(`happened_at DESC, id DESC`) — 사용자가 정렬 컬럼을 고르지 못한다.
 *   · 응답은 JSON 이며, 화면은 이 값을 `textContent` 로만 그린다.
 *     `summary`·`target_name`·`user_agent`·`detail` 은 공격자가 만들 수 있는 문자열이다
 *     (SECURITY 1.4, ARCH 7.4 "파일명이 문장을 흉내"). 서버는 HTML 을 만들지 않는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다)
 * @var list<string>        $params 경로 변수 없음
 */

$perPage = 20;

/**
 * `action` 허용 목록 — ARCH 7.4 문장 표(= Audit::SUMMARY_TEMPLATES) 키의 **action 부분**이다.
 * 코드에 적힌 고정 목록이며, 여기 없는 값은 필터로 쓰이지 않는다(무시된다).
 * 새 행동을 7.4 표에 추가하면 이 목록에도 함께 넣는다.
 */
$allowedActions = [
    'auth.login', 'auth.logout', 'auth.required',
    'csrf', 'authz.route',
    'file.upload', 'file.fetch', 'file.download', 'file.delete',
    'file.update', 'file.level_change',
    'audit.view',
    // 9단계 운영 배치 (ARCH 7.4 · 11.1) — cron 이 남기는 행. actor_* 가 비어 있어
    // 「누가」 필터로는 찾을 수 없고, 「어떻게(action)」 로 골라야 보인다.
    'file.purge', 'backup.run', 'backup.restore', 'audit.integrity', 'alert.raised',
    // 10단계 계정 관리·휴지통 복구·본인 계정 (ARCH 7.4 10단계 행).
    // 문장 표(Audit::SUMMARY_TEMPLATES)와 이 목록은 **함께** 늘어난다 — 표에만 넣고 여기를
    // 빠뜨리면 그 행동이 조회 화면의 「어떻게」 필터에서 사라진다.
    'file.restore',
    'user.create', 'user.role_change', 'user.expiry_change',
    'user.deactivate', 'user.activate', 'user.expired',
    'auth.password_change', 'auth.mfa_enroll', 'auth.mfa_disable', 'auth.mfa',
    // 11단계 최초 로그인 강제 절차 (F-16) — 관문 거부와 Admin 의 재발급·초기화.
    // `auth.gate` 는 "절차를 마치지 않은 계정이 다른 기능을 두드린 횟수" 라서
    // 조회 화면에서 골라 볼 수 있어야 한다(정상적으로도 몇 건씩 생긴다).
    'auth.gate', 'user.password_reset', 'user.mfa_reset',
    // 15-2 만료 공유 링크 (F-13, ARCH 5.12 · 7.4 share.* 행).
    // `share.download` 는 **actor_* 가 비어 있는 행**이다(받는 쪽에 로그인한 사람이 없다) —
    // 9단계 배치 행과 같은 이유로 「누가」 필터로는 찾을 수 없고 여기(어떻게)로 골라야 보인다.
    // 발급자는 detail.issued_by 에 있다.
    'share.create', 'share.revoke', 'share.download',
];

/** 접근 등급 허용 목록 (PRD 4장) */
$allowedLevels = ['public', 'internal', 'team', 'admin-only'];

/** 결과 허용 목록 (ARCH 7.2 CHECK 제약과 같은 3값) */
$allowedResults = ['allow', 'deny', 'error'];

/** 사유 코드·허용 근거 코드의 형태 (ARCH 4.1 판정 코드 · 7.4 사유 등록부) */
$codePattern = '/^[a-z_]{1,40}$/';

/**
 * `from`·`to` 로 받은 값이 실제로 존재하는 시각인지 본다.
 * 형태(`YYYY-MM-DD` 또는 `YYYY-MM-DDTHH:MM`)만 보면 `2026-13-99` 도 통과해 버리고,
 * 그 값을 timestamptz 로 CAST 하면 DB 오류(500)가 난다 — 조회 화면에서 500 이 나지 않게
 * 여기서 달력상 실제 날짜인지까지 확인하고, 아니면 필터를 **무시**한다.
 */
$validMoment = static function (string $raw): string {
    if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:T([0-9]{2}):([0-9]{2}))?$/', $raw, $m) !== 1) {
        return '';
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return '';
    }
    if (isset($m[4]) && ((int) $m[4] > 23 || (int) $m[5] > 59)) {
        return '';
    }
    return $raw;
};

// ─── 입력 좁히기 (거부가 아니라 무시. 값은 아래에서 전부 바인딩된다) ───
$actorRaw  = Http::queryText('actor', 64);
$fromRaw   = $validMoment(Http::queryText('from', 16));
$toRaw     = $validMoment(Http::queryText('to', 16));
$ipRaw     = Http::queryText('ip', 45);
$targetRaw = Http::queryText('target', 200);
$levelRaw  = Http::queryChoice('level', $allowedLevels, '');
$actionRaw = Http::queryChoice('action', $allowedActions, '');
$resultRaw = Http::queryChoice('result', $allowedResults, '');
$reasonRaw = Http::queryText('reason', 40);
$grantRaw  = Http::queryText('granted_by', 40);
$page      = Http::queryInt('page', 1, 1, 100000);

if ($ipRaw !== '' && filter_var($ipRaw, FILTER_VALIDATE_IP) === false) {
    $ipRaw = '';
}
if ($reasonRaw !== '' && preg_match($codePattern, $reasonRaw) !== 1) {
    $reasonRaw = '';
}
if ($grantRaw !== '' && preg_match($codePattern, $grantRaw) !== 1) {
    $grantRaw = '';
}

// ─── 조건 조립 — SQL 조각은 전부 코드에 적힌 고정 문자열, 값은 :이름 으로만 들어간다 ───
$where   = '';
$bind    = [];
$applied = [];   // 감사 기록(detail.filters)에 남길 "실제로 적용된" 필터만

// 누가 — 12자 16진수면 login_ref(로그인 회차, ARCH 7.5), 아니면 계정명 정확 일치
if ($actorRaw !== '') {
    if (preg_match('/^[0-9a-f]{12}$/', $actorRaw) === 1) {
        $where .= " AND detail->>'login_ref' = :login_ref";
        $bind[':login_ref']     = $actorRaw;
        $applied['login_ref']   = $actorRaw;
    } else {
        $where .= ' AND actor_name = :actor';
        $bind[':actor']   = $actorRaw;
        $applied['actor'] = $actorRaw;
    }
}

// 언제 — timestamptz 로 CAST 해서 바인딩한다(문자열 비교가 아니다)
if ($fromRaw !== '') {
    $where .= ' AND happened_at >= CAST(:from AS timestamptz)';
    $bind[':from']   = $fromRaw;
    $applied['from'] = $fromRaw;
}
if ($toRaw !== '') {
    $where .= ' AND happened_at <= CAST(:to AS timestamptz)';
    $bind[':to']   = $toRaw;
    $applied['to'] = $toRaw;
}

// 어디서 — inet 으로 CAST. 형태 검사를 통과한 값만 여기 온다
if ($ipRaw !== '') {
    $where .= ' AND ip = CAST(:ip AS inet)';
    $bind[':ip']   = $ipRaw;
    $applied['ip'] = $ipRaw;
}

// 무엇을 — 대상 이름 부분 일치. ESCAPE 문자는 '#' 이고 값 쪽에서 '#' '%' '_' 를 무력화한다
// (ARCH 5.1 의 패턴 그대로. PDO 의 이름 바인딩 파서가 ESCAPE '\' 를 오해하는 문제를 피한다)
if ($targetRaw !== '') {
    $where .= " AND target_name ILIKE :target ESCAPE '#'";
    $bind[':target']   = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $targetRaw) . '%';
    $applied['target'] = $targetRaw;
}
if ($levelRaw !== '') {
    $where .= ' AND access_level = :level';
    $bind[':level']   = $levelRaw;
    $applied['level'] = $levelRaw;
}

// 어떻게 — 허용 목록 안의 값만 여기 온다
if ($actionRaw !== '') {
    $where .= ' AND action = :action';
    $bind[':action']   = $actionRaw;
    $applied['action'] = $actionRaw;
}

// 왜 — 결과·거부 사유·허용 근거
if ($resultRaw !== '') {
    $where .= ' AND result = :result';
    $bind[':result']   = $resultRaw;
    $applied['result'] = $resultRaw;
}
if ($reasonRaw !== '') {
    $where .= ' AND reason = :reason';
    $bind[':reason']   = $reasonRaw;
    $applied['reason'] = $reasonRaw;
}
if ($grantRaw !== '') {
    $where .= " AND detail->>'granted_by' = :granted_by";
    $bind[':granted_by']   = $grantRaw;
    $applied['granted_by'] = $grantRaw;
}

/**
 * ★ 조회 자체를 먼저 기록한다 (PRD 7.7 "관리 / 감사 로그 조회", ARCH 7.6 두 번째 행).
 * SELECT 보다 앞에 두는 이유: 조회가 실패하든 성공하든 "봤다는 사실" 은 남아야 한다.
 * 같은 요청 안의 INSERT → SELECT 이므로 이 행이 자기 조회 결과에 들어올 수 있는데,
 * 그래도 문제가 되지 않는다(거짓이 아니라 사실이다).
 * detail.filters 에는 **실제로 적용된 필터만** 넣는다 (ARCH 7.5 `filters` 키).
 * 하나도 적용되지 않았으면 키 자체를 넣지 않는다 — 빈 PHP 배열은 JSON 에서 객체가 아니라
 * `[]` 로 직렬화되어 "키·값 객체" 라는 등록부의 약속이 깨지기 때문이다.
 */
$auditCtx = [
    'target_type' => 'system',
    'target_name' => 'audit_logs',
];
if ($applied !== []) {
    $auditCtx['detail'] = ['filters' => $applied];
}
Audit::allow('audit.view', $auditCtx);

$sqlList = <<<'SQL'
    SELECT id,
           happened_at,
           actor_name,
           actor_role,
           action,
           target_type,
           target_name,
           access_level,
           result,
           reason,
           ip,
           user_agent,
           request_id,
           detail,
           summary
      FROM audit_logs
     WHERE TRUE
    SQL;

$sqlCount = <<<'SQL'
    SELECT count(*) AS total
      FROM audit_logs
     WHERE TRUE
    SQL;

// 정렬은 고정이다 — 사용자가 고르는 값이 아니므로 허용 목록조차 필요 없다 (ARCH 7.6).
// audit_time_idx (happened_at DESC) 를 그대로 쓴다.
$sqlTail = ' ORDER BY happened_at DESC, id DESC LIMIT :lim OFFSET :off';

$db = Db::instance();

// 전체 건수 — 목록과 **같은 조건**으로 센다
$countRow = $db->run($sqlCount . $where, $bind)->fetch();
$total    = is_array($countRow) ? (int) $countRow['total'] : 0;

$listBind         = $bind;
$listBind[':lim'] = $perPage;
$listBind[':off'] = ($page - 1) * $perPage;

$rows = $db->run($sqlList . $where . $sqlTail, $listBind)->fetchAll();

$out = [];
foreach ($rows as $row) {
    // detail 은 JSONB 문자열로 온다 → 객체로 돌려준다. 깨진 값이면 null 로 둔다(추측하지 않는다).
    $detail = null;
    if (is_string($row['detail']) && $row['detail'] !== '') {
        $decoded = json_decode($row['detail'], true);
        $detail  = is_array($decoded) ? $decoded : null;
    }

    $out[] = [
        'id'           => (int) $row['id'],
        'happened_at'  => (string) $row['happened_at'],
        'actor_name'   => $row['actor_name']   === null ? null : (string) $row['actor_name'],
        'actor_role'   => $row['actor_role']   === null ? null : (string) $row['actor_role'],
        'action'       => (string) $row['action'],
        'target_type'  => $row['target_type']  === null ? null : (string) $row['target_type'],
        'target_name'  => $row['target_name']  === null ? null : (string) $row['target_name'],
        'access_level' => $row['access_level'] === null ? null : (string) $row['access_level'],
        'result'       => (string) $row['result'],
        'reason'       => $row['reason']       === null ? null : (string) $row['reason'],
        'ip'           => $row['ip']           === null ? null : (string) $row['ip'],
        'user_agent'   => $row['user_agent']   === null ? null : (string) $row['user_agent'],
        'request_id'   => (string) $row['request_id'],
        'detail'       => $detail,
        'summary'      => (string) $row['summary'],
    ];
}

Http::json([
    'rows'     => $out,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
