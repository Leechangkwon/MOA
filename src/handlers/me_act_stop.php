<?php
declare(strict_types=1);

/**
 * src/handlers/me_act_stop.php — DELETE /api/me/act (정책: authenticated)
 *
 * 요구사항: PRD F-39 · 8장 `/api/me/act` 행, ARCH 4.0 · 5.20, SECURITY R-74
 *
 * 「역할로 보기」를 끄고 원래 역할로 돌아온다 — `sessions.act_*` 여섯 열을 NULL 로 되돌린다.
 *
 * 왜 정책이 `authenticated` 인가 (시작은 `role:Admin` 인데)
 *   보는 중에는 **유효 역할이 Admin 이 아니다**(그것이 이 기능의 전부다). 끄기에 `role:Admin` 을
 *   걸면 Employee 로 보는 사람은 자기가 켠 것을 끌 수 없고, 30분을 기다리거나 로그아웃해야 한다 —
 *   돌아올 길이 없는 기능은 아무도 켜지 않는다.
 *   **이 창구는 권한을 만들지 않는다.** 하는 일은 임시로 좁혀 둔 역할을 원래대로 되돌리는 것뿐이고,
 *   되돌아가는 자리는 언제나 `users.role`(실제 역할)이다 — 그 값은 이 파일이 손대지 않는다.
 *   act_* 가 없는 세션에 이 요청이 오면 바꿀 것이 없으므로 **아무것도 하지 않고** 현재 상태를 준다
 *   (감사 행도 남기지 않는다 — 일어나지 않은 일을 기록하지 않는다).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (보는 중이면 유효 역할이 Admin 이 아니다)
 * @var list<string>        $params 경로 변수 없음
 */

if (($user['acting'] ?? false) === true) {
    $db = Db::instance();

    $sqlClear = <<<'SQL'
        UPDATE sessions
           SET act_role          = NULL,
               act_team_id       = NULL,
               act_started_at    = NULL,
               act_last_seen_at  = NULL
         WHERE id = :id
        SQL;

    $db->begin();
    try {
        $db->run($sqlClear, [':id' => (string) $user['session_id']]);

        /*
         * 기록은 **끄기 전 상태**에서 남긴다 — 그래야 autoDetail 이 detail.acting_as 를 붙여
         * "무슨 역할로 보다가 끝냈나" 가 한 줄에 남는다. actor_role 은 여전히 실제 역할이다.
         */
        Audit::allow('act.stop', [
            'target_type' => 'user',
            'target_id'   => (int) $user['id'],
            'target_name' => (string) $user['username'],
        ]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/* 응답은 `/api/me` 와 같은 모양이다. 방금 세션을 바꿨으므로 캐시를 버리고 다시 읽는다. */
$user = Auth::reload();
if ($user === null) {
    Http::error(401, Http::MSG_UNAUTHORIZED);
}
require __DIR__ . '/me.php';
