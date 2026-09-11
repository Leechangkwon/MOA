<?php
declare(strict_types=1);

/**
 * src/handlers/me.php — GET /api/me (정책: authenticated)
 *
 * 요구사항: PRD 8장, S-09-3(CSRF 토큰 전달 통로), ARCH 4.0
 *
 * 화면이 필요한 최소한만 내려 준다.
 *   · username / role / team_id — 화면 표시용. 권한 판단의 근거가 아니다.
 *     역할을 내려 주지만 허용 여부는 매 요청 서버 Authz 가 다시 판단한다 (PRD S-01).
 *   · csrf_token — 상태 변경 요청의 X-CSRF-Token 헤더에 실을 값.
 *     세션 쿠키(HttpOnly)와 달리 JS 가 읽어야 하므로 본문으로 내려간다.
 *     이 값은 세션에 결속되어 있어 다른 세션에서는 쓸 수 없다.
 *   · upload_levels — 업로드 폼의 <select> 를 채울 레벨 목록 (4단계, PRD 8장·10장).
 *     화면이 고정 목록을 들고 있으면 역할 판단이 화면에 생긴다 — 그래서 서버가 내려 준다.
 *     이것은 **선택지일 뿐 권한이 아니다**: 목록에 없는 값을 보내도 서버가 다시 검사한다
 *     (Authz::decideUploadAs, PRD S-01).
 *
 *   · team_name — 「내 계정」 화면의 「내 정보」 카드에 보여 줄 팀 이름 (12단계 F-20).
 *     화면이 team_id 로 팀 이름을 스스로 찾지 않게 서버가 읽어 준다. 팀이 없으면 null 이다.
 *     **표시용 값이며 권한이 아니다** — 팀 판정은 언제나 서버 Authz 가 세션의 team_id 로 한다.
 *
 * 내부 id 는 내지 않는다 — 외부에 노출할 필요가 없는 값이다 (PRD S-09-5 취지).
 * 응답은 Cache-Control: no-store 로 나간다 (Http::json).
 *
 * @var array<string,mixed>      $user   라우터가 넘긴 현재 사용자
 * @var list<string>             $params 경로 변수 없음
 */

/*
 * 팀 이름 조회 (12단계 F-20). team_id 는 **세션에서 읽은 서버 값**이고 요청이 정하지 않는다.
 * SQL 은 Db::run 의 이름 바인딩만 쓴다 — 문자열 결합이 없다 (PRD S-04).
 */
$sqlTeamName = <<<'SQL'
    SELECT name FROM teams WHERE id = :id
    SQL;

$teamName = null;
$myTeamId = $user['team_id'] ?? null;
if ($myTeamId !== null && $myTeamId !== '') {
    $teamRow = Db::instance()->run($sqlTeamName, [':id' => (int) $myTeamId])->fetch();
    if (is_array($teamRow)) {
        $teamName = (string) $teamRow['name'];
    }
}

Http::json([
    'username'   => (string) $user['username'],
    'role'       => (string) $user['role'],
    'team_id'    => $user['team_id'],
    // 12단계 — 「내 계정」 화면이 보여 줄 팀 이름(없으면 null). 표시용이며 권한이 아니다 (F-20).
    'team_name'  => $teamName,
    'csrf_token' => Auth::csrfToken(),
    'upload_levels' => Authz::uploadableLevels($user),
    // 7단계 — "URL 로 가져오기" 패널을 그릴지 여부 (F-14, Manager 이상).
    // 화면은 이 값만 본다. 역할 문자열을 보고 화면이 스스로 판단하지 않는다 (PRD S-01).
    // 감추는 것은 편의일 뿐이고, POST /api/files/fetch 는 라우터 정책 file.fetch 가 다시 막는다.
    'can_fetch'     => Authz::canFetch($user),
    // 8단계 — "감사 로그" 링크를 보일지 여부 (F-09, Admin 만, PRD 5.4 Q-8).
    // can_fetch 와 똑같은 성격의 **화면 편의 값**이다. 링크를 되살려 /audit 이나
    // /api/audit 을 직접 불러도 라우터 정책 audit.read 가 다시 막는다 (PRD S-01, 10장).
    'can_view_audit' => Authz::canReadAudit($user),
    // 10단계 — "계정 관리" 링크와 휴지통 패널을 보일지 여부 (F-10 · F-11, Admin 만, PRD 5.4).
    // can_fetch·can_view_audit 과 똑같은 **화면 편의 값**이다. 링크를 되살려 /users 나
    // /api/users·/api/files/trash 를 직접 불러도 라우터 정책이 다시 막는다 (PRD S-01).
    'can_manage_users' => Authz::canManageUsers($user),
    // 12단계 — 좌측 내비의 「휴지통」 항목을 보일지 여부 (F-11 · F-20, Admin 만, PRD 5.4).
    // 휴지통은 계정 관리와 **같은 자격**(Authz::canManageUsers = Admin)으로 열린다 —
    // 새 판단 규칙을 만들지 않았다(Authz 에 새 메서드를 추가하지 않는다). 항목을 되살려도
    // /trash 와 /api/files/trash 는 라우터 정책 role:Admin 이 다시 막는다 (PRD S-01).
    'can_view_trash'   => Authz::canManageUsers($user),
    // 10단계 — 내 2단계 인증이 켜져 있나 (R-05). 권한이 아니라 상태이며,
    // 화면이 "켜기" 를 보일지 "끄기" 를 보일지 고르는 데만 쓴다.
    'mfa_enabled'      => ($user['mfa_enabled'] ?? false) === true,
    // 11단계 — 최초 로그인 강제 절차 (PRD F-16, ARCH 4.0).
    //   next — 지금 무엇을 해야 하나: 'password_change' → 'mfa_setup' → null(끝).
    //          로그인 200 응답과 **같은 값**이며 계산은 Auth::nextStep 한 곳에서만 한다.
    //   두 플래그는 화면이 상태를 그대로 보여 줄 수 있게 함께 준다. 셋 다 **상태**일 뿐
    //   권한이 아니고, 화면이 이 값을 무시해도 라우터 관문이 서버에서 막는다 (PRD S-01).
    'next'                 => Auth::nextStep($user),
    'must_change_password' => ($user['must_change_password'] ?? false) === true,
    'mfa_setup_required'   => ($user['mfa_setup_required'] ?? false) === true,
]);
