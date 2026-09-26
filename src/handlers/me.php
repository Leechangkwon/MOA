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

/** 지금 「역할로 보기」 중인가 — 판정은 Auth::currentUser 한 곳에서 이미 끝났다 */
$acting = ($user['acting'] ?? false) === true;

/*
 * F-43 — 「URL 로 가져오기」 (PRD F-43, ARCH 5.22).
 *
 *   can_fetch          그 기능을 쓸 자격이 있나 (Manager 이상, 7단계부터 있던 값)
 *   fetch_enabled      **지금 허용된 사이트가 하나라도 있나**. 목록이 비면 어떤 주소도 가져올 수
 *                      없으므로(전부 host_not_allowed), 눌러도 반드시 실패하는 단추를 보여 주지
 *                      않으려는 값이다. 홈의 단추는 `can_fetch && fetch_enabled` 일 때만 열린다.
 *   fetch_hosts_public 지금 허용된 사이트 이름 목록. 「사용 방법」 도움말이 그대로 보여 준다.
 *                      **자격이 있는 사람(can_fetch)에게만** 내려 준다 — 서버가 어디로 나갈 수
 *                      있는지는 그 기능을 쓸 수 없는 사람이 알아야 할 값이 아니다.
 *
 * ★ 셋 다 **화면 편의 값이며 권한이 아니다**. fetch_enabled 를 true 로 고쳐 단추를 되살려도,
 *   목록에 없는 주소를 보내도, 허용 여부는 요청마다 서버가 Fetch::check 로 다시 판단하고
 *   라우트 정책 file.fetch 가 다시 막는다 (PRD S-01, T-56).
 */
$canFetch   = Authz::canFetch($user);
$fetchHosts = Fetch::publicHosts();

$mePayload = [
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
    'can_fetch'     => $canFetch,
    // F-43 — 허용된 사이트가 하나라도 있나 (위 주석). 목록이 비면 홈 단추를 열지 않는다.
    'fetch_enabled' => $fetchHosts !== [],
    // 8단계 — "감사 로그" 링크를 보일지 여부 (F-09, Admin 만, PRD 5.4 Q-8).
    // can_fetch 와 똑같은 성격의 **화면 편의 값**이다. 링크를 되살려 /audit 이나
    // /api/audit 을 직접 불러도 라우터 정책 audit.read 가 다시 막는다 (PRD S-01, 10장).
    'can_view_audit' => Authz::canReadAudit($user),
    // 10단계 — "계정 관리" 링크와 휴지통 패널을 보일지 여부 (F-10 · F-11, Admin 만, PRD 5.4).
    // can_fetch·can_view_audit 과 똑같은 **화면 편의 값**이다. 링크를 되살려 /users 나
    // /api/users·/api/files/trash 를 직접 불러도 라우터 정책이 다시 막는다 (PRD S-01).
    'can_manage_users' => Authz::canManageUsers($user),
    // 12단계 — 좌측 내비(관리 묶음)의 「휴지통」(전체) 항목을 보일지 여부 (F-11 · F-20, Admin 만, PRD 5.4).
    // Q:F-4 U-2b(2026-09-24) — 비관리자의 「내 휴지통」 링크는 이 값이 아니라 upload_levels 로 화면이 정한다(app.js).
    // 휴지통은 계정 관리와 **같은 자격**(Authz::canManageUsers = Admin)으로 열린다 —
    // 새 판단 규칙을 만들지 않았다(Authz 에 새 메서드를 추가하지 않는다). 항목을 되살려도
    // U-2b 뒤로 /trash 와 /api/files/trash 는 authenticated 이고, 범위는 핸들러가 Authz::restorableFilesWhere 로 SQL WHERE 에 붙인다 (PRD S-01·S-02).
    'can_view_trash'   => Authz::canManageUsers($user),
    // 12-3 — 좌측 내비의 「조직·권한」 항목을 보일지 여부 (F-19, Admin 만, PRD 5.4 · 10장).
    // can_manage_users 와 **다른 판정 함수**를 쓴다 — 자격은 지금 같지만 라우트 정책 이름이
    // 갈라져 있어(org.manage), 한쪽이 바뀌어도 다른 쪽이 따라 바뀌지 않는다.
    // 다른 can_* 와 똑같은 **화면 편의 값**이며, 항목을 되살려 /org 나 /api/org/* 를 직접
    // 불러도 라우터 정책 org.manage 가 다시 막는다 (PRD S-01, T-27).
    'can_manage_org'   => Authz::canManageOrg($user),
    // 14단계 — 좌측 내비의 「위키」 항목을 보일지 여부 (F-21, Admin 만, SECURITY R-56).
    // 다른 can_* 와 똑같은 **화면 편의 값**이다. 항목을 되살려 /wiki 나 /wiki/{slug} 를
    // 직접 불러도 라우터 정책 role:Admin 이 다시 막는다 (PRD S-01, T-32).
    'can_view_wiki'    => Authz::canReadWiki($user),
    // 17-1 — 일반 셸의 「관리자 페이지」 한 항목을 보일지 여부 (F-24, Admin 만, ARCH 5.14).
    // 17단계에서 셸의 관리 그룹(계정·조직·감사·휴지통·위키)을 없애고 이 항목 하나로 바꿨다 —
    // 세부 화면은 허브(`/admin`)의 카드로만 간다. 다른 can_* 와 똑같은 **화면 편의 값**이며,
    // 항목을 개발자도구로 되살려 /admin 이나 /api/admin/* 를 직접 불러도 라우터 정책
    // role:Admin 과 재인증 관문이 다시 막는다 (PRD S-01, T-38).
    'can_admin'        => Authz::canAdmin($user),
    /*
     * 디버깅 묶음 U H3 (2026-09-26) — 커뮤니티에 **쓸 수 있는 역할인가**(새 페이지·템플릿·선택하기·편집·넘기기·공개 범위·태그 단추를
     * 보일지). 값은 커뮤니티 쓰기 라우트 정책 `community.write` 가 부르는 **그 함수**(`Authz::canCommunityWrite` — 매트릭스의
     * 올리기 자격, Guest 거짓)를 그대로 부른 결과다 — 화면이 역할 문자열을 비교하지 않는다(DESIGN 0장 1).
     * 다른 can_* 와 똑같은 **화면 편의 값**이며 권한이 아니다: 단추를 되살려 POST·PATCH 를 보내도 라우트 정책 `community.write`
     * 가 403 으로, 행 단위 판정(`CommunityAuthz::decidePage*`)이 다시 막는다 (PRD S-01). 페이지마다의 can_edit·can_handover 등은
     * 그대로 창구(`GET /community/api/pages/{id}`)가 준다 — 화면은 두 값이 **둘 다** 참일 때만 단추를 보인다.
     */
    'can_community_write' => Authz::canCommunityWrite($user),
    // 10단계 — 내 2단계 인증이 켜져 있나 (R-05). 권한이 아니라 상태이며,
    // 화면이 "켜기" 를 보일지 "끄기" 를 보일지 고르는 데만 쓴다.
    'mfa_enabled'      => ($user['mfa_enabled'] ?? false) === true,
    /*
     * F-66 — 「프로그램 토큰」 절이 쓰는 값 둘 (PRD F-66 ② ③, ARCH 4.7 「GET /api/me」).
     *
     *   token_scopes     이 역할이 **고를 수 있는** 범위 배열. 발급 폼의 <select> 를 채운다.
     *                    upload_levels 와 **똑같은 성격**이다 — 화면이 고정 목록을 들고 있으면
     *                    역할 판단이 화면에 생기므로 서버가 내려 준다. 목록에 없는 값을 보내도
     *                    발급 핸들러가 Authz::tokenScopesFor 로 다시 막는다 (PRD S-01).
     *   can_issue_tokens 발급 폼을 보일까, 「2단계 인증을 켜야 합니다」 안내를 보일까.
     *                    값은 mfa_enabled 와 같다 — 그래도 **이름을 따로 두는 이유**는
     *                    화면이 「2단계가 켜졌나」가 아니라 「발급할 수 있나」를 묻게 하기
     *                    위해서다(다른 can_* 와 같은 자리). 화면 편의 값이며 권한이 아니다:
     *                    true 로 고쳐 보내도 서버가 요청마다 DB 를 다시 본다.
     *
     * ★ 화면은 이 둘만 본다 — 역할 문자열을 비교하지 않는다 (DESIGN 0장 1, PRD S-01).
     */
    'token_scopes'     => Authz::tokenScopesFor($user),
    'can_issue_tokens' => ($user['mfa_enabled'] ?? false) === true,
    // 11단계 — 최초 로그인 강제 절차 (PRD F-16, ARCH 4.0).
    //   next — 지금 무엇을 해야 하나: 'password_change' → 'mfa_setup' → null(끝).
    //          로그인 200 응답과 **같은 값**이며 계산은 Auth::nextStep 한 곳에서만 한다.
    //   두 플래그는 화면이 상태를 그대로 보여 줄 수 있게 함께 준다. 셋 다 **상태**일 뿐
    //   권한이 아니고, 화면이 이 값을 무시해도 라우터 관문이 서버에서 막는다 (PRD S-01).
    'next'                 => Auth::nextStep($user),
    'must_change_password' => ($user['must_change_password'] ?? false) === true,
    'mfa_setup_required'   => ($user['mfa_setup_required'] ?? false) === true,
    // F-52 (2026-09-16, ARCH 5.26) — 2단계 인증의 **필수 여부**와 **권장 여부**.
    //   mfa_required     실제 역할(real_role)이 Auth::MFA_REQUIRED_ROLES 에 있는가 (Admin)
    //   mfa_recommended  필수는 아닌데 아직 안 켰는가 — 홈의 권장 카드가 이 값만 본다
    // 둘 다 위의 셋과 같은 **상태**이지 권한이 아니다. 판정은 Auth 안에서만 하므로 화면은
    // 역할 문자열을 비교하지 않고, 화면이 이 값을 무시해도 관문은 서버가 막는다 (PRD S-01).
    'mfa_required'         => Auth::mfaRequired($user),
    'mfa_recommended'      => Auth::mfaRecommended($user),
    // 16-1 — 관리 기능 재인증 (PRD F-23, ARCH 4.0). 이 세션의 창이 지금 열려 있나.
    // 다른 can_* 와 같은 **화면 편의 값**이다: 화면이 관리 링크를 누르기 전에 모달을 미리
    // 띄울지 고르는 데만 쓴다. 값이 무엇이든 막는 것은 라우터 관문 [3-2] 이고,
    // 이 값을 true 로 고쳐 보내도 서버는 요청마다 DB(sessions.reauth_at)를 다시 본다 (PRD S-01).
    'reauth_fresh'         => ($user['reauth_fresh'] ?? false) === true,
    /*
     * 26단계 — 「역할로 보기」 (PRD F-39, ARCH 5.20).
     *
     *   acting              지금 다른 역할로 보는 중인가
     *   acting_as           무슨 역할·팀으로 보는 중인가 (**표시용**, 값은 위 유효 값과 같다)
     *   real_role           실제 역할. 보는 중이면 언제나 Admin 이고, 띠가 「실제 계정 …」 에 쓴다
     *   acting_idle_minutes 활동이 없으면 몇 분 뒤 저절로 풀리나 (안내 문구용)
     *   can_stop_acting     「원래대로」 단추를 보일까 — 다른 can_* 와 똑같은 화면 편의 값이다
     *
     * ★ 위의 role·team_id·can_* 는 **유효 역할 기준 그대로**다. 그래야 화면이 그 역할이 보는
     *   화면과 똑같아진다 — 화면만 원래대로 보이면 이 기능은 아무 의미가 없다.
     * ★ 이 값들은 권한이 아니다. acting 을 false 로 고쳐 보내도 서버는 요청마다 sessions.act_*
     *   를 다시 읽고, can_admin 을 true 로 바꿔도 라우트 정책이 다시 막는다 (PRD S-01, T-52).
     */
    'acting'              => $acting,
    'acting_as'           => $acting ? [
        'role'          => (string) $user['role'],
        'team_name'     => $teamName,
    ] : null,
    'real_role'           => (string) ($user['real_role'] ?? $user['role']),
    'acting_idle_minutes' => Auth::ACT_IDLE_MINUTES,
    'can_stop_acting'     => $acting,
];

/* 허용 목록 자체는 자격이 있는 사람에게만 — Employee·Guest 응답에는 **키가 아예 없다**. */
if ($canFetch) {
    $mePayload['fetch_hosts_public'] = $fetchHosts;
}

Http::json($mePayload);
