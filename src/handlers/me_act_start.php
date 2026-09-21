<?php
declare(strict_types=1);

/**
 * src/handlers/me_act_start.php — POST /api/me/act (정책: role:Admin → 재인증 관문 자동)
 *
 * 요구사항: PRD F-39 · 8장 `/api/me/act` 행 · S-01(서버 판정) · S-07(감사),
 *           ARCH 4.0(요청 흐름) · 5.20(「역할로 보기」) · 7.4 · 7.5,
 *           SECURITY R-74(권한 축소 위임) · R-05 · R-59(재인증 창)
 *
 * 하는 일: **이 세션**에 「어떤 역할로 볼지」를 적는다(`sessions.act_*` 여섯 열). 그 뒤부터
 * `Auth::currentUser()` 가 유효 역할을 그 값으로 만들어 주고, Authz·목록 SQL 이
 * 전부 그 역할로 판정한다 — **화면 흉내가 아니다**. 실제 계정(`users.role`)은 손대지 않는다.
 *
 * 막는 겹 (지나온 순서대로)
 *   ① 라우트 정책 `role:Admin` — 비관리자는 여기 오기 전에 403 이고 `authz.route deny` 로 남는다.
 *      **보는 중에는 유효 역할이 Admin 이 아니므로 이 관문이 「겹쳐 켜기」도 막는다** (F-39).
 *   ② 재인증 15분 창 — `role:Admin` 은 `Authz::REAUTH_POLICIES` 에 있어 관문 [3-2] 가 자동으로
 *      따라붙는다. 훔친 쿠키·자리 비움만으로 다른 역할을 켜지 못한다 (F-23).
 *   ③ 아래 [1] **실제 역할**(`real_role`) 재확인 — ① 이 이미 같은 것을 보지만, 이 창구는 권한의
 *      모양을 바꾸는 자리라 판단 근거를 스스로 한 번 더 확인한다(기본 거부).
 *   ④ 아래 [3] 값 좁히기 — 고를 수 있는 역할은 Manager·Employee·Guest **뿐**이다. 위로 가는
 *      조합(Admin 으로 보기)은 여기서도, DB CHECK(`sessions_act_role_check`)에서도 막힌다.
 *
 * ★ 보는 역할은 **세션 열에서만** 읽는다. 이 요청의 본문은 "무엇을 적을까" 를 정할 뿐이고,
 *   판정에 쓰이는 것은 언제나 다음 요청들이 DB 에서 다시 읽은 `sessions.act_*` 다 (PRD S-01).
 * ★ 이 파일에는 역할 **판정**이 없다. 있는 것은 "표에 넣어도 되는 값인가" 라는 허용 목록 검사뿐이고,
 *   무엇을 할 수 있는지는 요청마다 Authz 가 다시 정한다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin, 재인증 창 열림)
 * @var list<string>        $params 경로 변수 없음
 */

/** 받을 수 있는 키. 하나라도 밖에 있으면 400 (R-07 과 같은 원칙) */
$actAllowedFields = ['role', 'team_id'];

/**
 * 고를 수 있는 t-box 역할 — **아래로만**. Admin 은 목록에 없다 (PRD F-39 「위로 가는 조합 없음」).
 * `db/schema.sql` 의 `sessions_act_role_check` 와 같은 값이며, 두 곳이 어긋나면 DB 가 막는다.
 */
$actAssignableRoles = ['Manager', 'Employee', 'Guest'];

$auditTarget = [
    'target_type' => 'user',
    'target_id'   => (int) $user['id'],
    'target_name' => (string) $user['username'],
];

/** 거부 기록 후 끝낸다 */
$reject = static function (string $reason, int $status) use ($auditTarget): void {
    Audit::deny('act.start', $reason, $auditTarget);
    Http::error($status, $status === 403 ? Http::MSG_FORBIDDEN : Http::MSG_BAD_REQUEST);
};

// ─── [1] 실제 역할이 지금도 Admin 인가 ──────────────────────────
// 보는 중이면 유효 역할이 Admin 이 아니라 라우트 정책이 이미 막았고, 여기 닿았다는 것은
// 보는 중이 아니라는 뜻이다. 그래도 근거를 요청이 아니라 **세션이 읽어 온 값**에서 다시 확인한다.
if ((string) ($user['real_role'] ?? '') !== 'Admin' || ($user['acting'] ?? false) === true) {
    $reject('role_not_permitted', 403);
}

$body = Http::jsonBody();

// ─── [2] 허용 키 검사 ───────────────────────────────────────────
if ($body === [] || array_diff(array_keys($body), $actAllowedFields) !== []) {
    $reject('bad_request', 400);
}

// ─── [3] 값 하나하나 좁히기 ─────────────────────────────────────
$actRole = $body['role'] ?? null;
if (!is_string($actRole) || !in_array($actRole, $actAssignableRoles, true)) {
    $reject('bad_request', 400);
}
$actRole = (string) $actRole;

$db = Db::instance();

/** 팀 — 비었으면 「팀 없음」, 값이 있으면 **활성 팀**이어야 한다 (teams.php 목록과 같은 기준) */
$actTeam = null;
if (array_key_exists('team_id', $body)) {
    $raw = $body['team_id'];
    if ($raw === null || $raw === '') {
        $actTeam = null;
    } elseif (is_int($raw) || (is_string($raw) && preg_match('/^[0-9]{1,9}$/', $raw) === 1)) {
        $sqlTeam = <<<'SQL'
            SELECT id FROM teams WHERE id = CAST(:id AS bigint) AND is_active
            SQL;
        $teamRow = $db->run($sqlTeam, [':id' => (int) $raw])->fetch();
        if (!is_array($teamRow)) {
            $reject('bad_request', 400);
        }
        $actTeam = (int) $raw;
    } else {
        $reject('bad_request', 400);
    }
}

// ─── [4] 세션에 적는다 — 기록이 실패하면 아무것도 바뀌지 않는다 (PRD S-07) ───
$sqlSet = <<<'SQL'
    UPDATE sessions
       SET act_role          = :role,
           act_team_id       = :team_id,
           act_started_at    = now(),
           act_last_seen_at  = now()
     WHERE id = :id
    SQL;

$db->begin();
try {
    $db->run($sqlSet, [
        ':role'          => $actRole,
        ':team_id'       => $actTeam,
        ':id'            => (string) $user['session_id'],
    ]);

    /*
     * 기록은 **바꾸기 전 상태**(아직 acting=false)에서 남긴다 — 그래야 이 한 줄이
     * `actor_role = Admin` 이고 detail.acting_as 없이 「시작했다」는 사실만 담는다.
     * 무엇을 골랐는지는 아래 detail 에 그대로 있다 (ARCH 7.5).
     */
    Audit::allow('act.start', $auditTarget + [
        'detail' => [
            'role'          => $actRole,
            'team_id'       => $actTeam,
            'idle_minutes'  => Auth::ACT_IDLE_MINUTES,
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

/*
 * 응답은 `/api/me` 와 **같은 모양**이다 — 화면이 두 가지 응답 형식을 알 필요가 없게.
 * 세션 행을 방금 바꿨으므로 캐시를 버리고 다시 읽는다(Auth::reload). 값은 전부 DB 에서 온다.
 */
$user = Auth::reload();
if ($user === null) {
    Http::error(401, Http::MSG_UNAUTHORIZED);
}
require __DIR__ . '/me.php';
