<?php
declare(strict_types=1);

/**
 * public/index.php — 모든 요청의 단일 입구 (ARCHITECTURE 3장 · 4장)
 *
 * nginx 는 index.php 하나만 PHP 로 넘긴다(`location ~ \.php$ { return 404; }`).
 * 따라서 아래 순서를 거치지 않고 애플리케이션 코드에 도달하는 경로가 존재하지 않는다.
 *
 *   [0] bootstrap.php        설정·DB·보안 헤더·오류 은닉
 *   [0-1] 라우트 표 자체 검사  ★ 정책이 없거나 모르는 정책이면 어떤 요청도 처리하지 않고 500
 *   [1] 라우트 매칭           없으면 404, 경로는 맞고 메서드만 다르면 405
 *   [2] Auth::requireLogin()  policy 가 'public' 이 아니면 필수 → 없으면 401
 *   [3] Http::requireCsrf()   상태를 바꾸는 요청의 토큰 검사 (PRD S-09-3)
 *   [3-1] 최초 로그인 관문    ★ 비밀번호 변경·2단계 인증 등록을 마치지 않았으면 403 (11단계 F-16)
 *   [4] Authz::authorize()    ★ 권한 판단. 실패 → Audit::deny + 403(동일 문구)
 *   [3-2] 관리 기능 재인증 관문 ★ 인가를 통과한 관리 영역 요청이라도 15분 안에 비밀번호를
 *                              다시 확인하지 않았으면 403 `next:"reauth"` / 화면은 302 (16-1 F-23)
 *   [5] 핸들러 실행
 *
 * ★ 라우트마다 policy 를 반드시 적는다. "권한 검사를 잊었다"가 코드 리뷰가 아니라
 *   **부팅 실패**로 드러나게 하는 것이 이 파일의 핵심이다 (SECURITY R-04).
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

/**
 * 라우트 표 — [메서드, 경로 패턴, 핸들러 파일명, 정책]
 *
 * 경로 패턴: 정적 문자열 또는 `{id}` 자리표시자.
 *   `{id}` 는 UUID 만 받는다(`[0-9a-f-]{36}`) — 외부 노출 ID 는 UUID 이며(PRD S-09-5),
 *   순차 정수 ID 를 URL 로 받지 않으므로 열거 공격의 표면이 없다.
 * 정책 이름은 Authz::KNOWN_POLICIES 에 있는 것만 쓸 수 있다.
 *
 * 2단계 라우트: 로그인 화면·로그인 API 만 public 이고, 나머지는 전부 로그인이 필요하다.
 * `/` 는 1단계의 public 에서 authenticated 로 바뀌었다 — 로그인 없이는 아무것도 못 본다.
 */
const TBOX_ROUTES = [
    ['GET',  '/',            'home.php',       Authz::POLICY_AUTHENTICATED],
    ['GET',  '/login',       'login_page.php', Authz::POLICY_PUBLIC],
    ['POST', '/api/login',   'login.php',      Authz::POLICY_PUBLIC],
    ['POST', '/api/logout',  'logout.php',     Authz::POLICY_AUTHENTICATED],
    ['GET',  '/api/me',      'me.php',         Authz::POLICY_AUTHENTICATED],
    ['GET',  '/api/health',  'health.php',     Authz::POLICY_PUBLIC],
    // F-42 — 오프라인 코드 생성기가 시계를 맞추는 창구. 시각과 주기만 내준다 (ARCH 5.21).
    ['GET',  '/api/time',    'time.php',       Authz::POLICY_PUBLIC],

    // 4단계 라우트 — 파일 관련 라우트는 역할·팀 정책을 쓴다 (PRD S-01, 13장 4단계).
    // 여기(라우터)의 정책은 "이 역할이 그 종류의 행위를 할 수 있는가"까지만 본다 —
    // 대상 파일을 아직 읽지 않았기 때문이다. 특정 파일에 대한 판단은 핸들러가 행을 읽은 뒤
    // Authz::decideRead / decideDelete / decideUploadAs 로 다시 한다 (ARCH 4.1, 5.1~5.4).
    //   file.read   : 목록·다운로드. 라우터 단계는 통과시키고 행 단위로 판정한다
    //                 (목록은 SQL WHERE 가, 다운로드는 decideRead 가 거른다 — PRD S-02)
    //   file.upload : 업로드할 수 있는 역할인가 (Guest 는 여기서 끝 — authz.route deny)
    //   file.delete : 삭제할 수 있는 역할인가 (Guest 는 여기서 끝)
    ['GET',    '/api/files',               'files_list.php',     'file.read'],
    ['POST',   '/api/files',               'files_upload.php',   'file.upload'],
    // F-49 — 프로그램용 업로드 (PRD F-49 · 8장 `/api/upload` 행, ARCH 4.2, SECURITY R-82).
    //   정책은 웹 업로드와 **같은 file.upload** 다. 다른 것은 인증 방법 하나뿐이고(아래 [2] 의
    //   토큰 가지), 인가 [4] 와 등급 권한은 귀속 계정 기준으로 그대로 탄다.
    //   ★ `X-API-Token` 이 통하는 라우트는 아래 `TBOX_API_TOKEN_ROUTES` 의 **열뿐**이다
    //     (F-49 의 이 한 줄 + F-50 의 둘 + F-56 의 whoami + F-58 의 폴더·파일 이동 넷
    //      + F-59 의 삭제 둘) — 그 밖의 라우트는 그 헤더를 읽지도 않는다.
    //     삭제 둘은 그중에서도 `TBOX_API_TOKEN_PERSONAL_ROUTES` 라 **개인 토큰만** 통한다.
    ['POST',   '/api/upload',              'api_upload.php',     'file.upload'],
    // F-56 — 프로그램용 자기 확인 (PRD F-56 · 8장 `/api/whoami` 행, ARCH 4.4, SECURITY R-89).
    //   정책은 `authenticated` — 귀속 계정이 유효하면 누구든이고, 인가 [4] 는 그대로 탄다.
    //   토큰 **전용** 창구라 두 집합(ROUTES · ONLY_ROUTES)에 모두 들어간다: 헤더가 없으면
    //   세션 쿠키가 있어도 401 `missing_header` 다(세션으로 되돌아가지 않는다).
    ['GET',    '/api/whoami',              'api_whoami.php',     Authz::POLICY_AUTHENTICATED],
    // 7단계 — URL 로 파일 가져오기 (PRD F-14, S-05, ARCH 5.6).
    //   file.fetch : Manager 이상만 (PRD 5.4). Employee·Guest 는 여기서 끝난다(authz.route deny).
    //   `{id}` 는 UUID 36자만 받으므로 `/api/files/fetch` 와 충돌하지 않지만,
    //   **의도를 드러내기 위해** `/api/files/{id}` 계열보다 위에 둔다.
    ['POST',   '/api/files/fetch',         'files_fetch.php',    'file.fetch'],
    ['GET',    '/api/files/{id}/download', 'files_download.php', 'file.read'],
    // 12-2 — 미리보기 (PRD F-18, 8장 API 표 `/preview`·`/preview/raw` 행, ARCH 5.8).
    //   정책은 다운로드와 **같은** file.read 다 — 미리보기는 읽기의 다른 모양일 뿐이고,
    //   그 파일을 볼 수 있는지는 핸들러가 decideRead 로 다시 판단한다(다운로드와 같은 코드 경로).
    //   `{id}` 뒤에 고정 세그먼트가 붙는 형태는 `/download` 와 같다.
    ['GET',    '/api/files/{id}/preview',     'files_preview.php',     'file.read'],
    ['GET',    '/api/files/{id}/preview/raw', 'files_preview_raw.php', 'file.read'],
    // 5단계 — 설명·접근 등급 변경 (PRD 8장 PATCH 행, F-08, ARCH 5.7).
    //   file.update : 고칠 수 있는 역할인가 (Guest 는 여기서 끝 — authz.route deny).
    //                 그 파일을 고칠 수 있는가는 핸들러가 decideRead → decideEdit 로 다시 판단한다.
    ['PATCH',  '/api/files/{id}',          'files_update.php',   'file.update'],
    ['DELETE', '/api/files/{id}',          'files_delete.php',   'file.delete'],
    ['GET',    '/api/teams',               'teams.php',          Authz::POLICY_AUTHENTICATED],

    // 8단계 — 감사 로그 조회 (PRD F-09, S-07, 5.4 Q-8, ARCH 7.6).
    //   audit.read : Admin 만 (Authz::canReadAudit). Manager·Employee·Guest 는 여기서 끝나고
    //                그 시도 자체가 `authz.route|deny` 로 남는다 (T-13).
    //   `/audit` 는 화면이고 `/api/audit` 는 데이터다. 둘 다 **같은 정책**을 쓴다 —
    //   화면만 숨기고 API 를 열어 두면 그것이 곧 우회 경로다 (PRD S-01).
    ['GET',    '/api/audit',               'audit_list.php',     'audit.read'],
    ['GET',    '/audit',                   'audit_page.php',     'audit.read'],

    // 10단계 — 계정 관리 (PRD F-10, 5.4, 8장 `/api/users` 행) · 휴지통 복구 (F-11) ·
    //          비밀번호 변경 · 2단계 인증 TOTP (SECURITY R-05 · R-10 · R-12 · R-20 · R-33).
    //   user.manage : Admin 만 (Authz::canManageUsers). 나머지 역할은 여기서 끝나고
    //                 그 시도 자체가 `authz.route|deny` 로 남는다.
    //   `/users` 화면과 `/api/users` 데이터는 **같은 정책**을 쓴다 — 화면만 숨기고 API 를
    //   열어 두면 그것이 곧 우회 경로다 (PRD S-01, 8단계 감사 로그와 같은 원칙).
    //   `{uid}` 는 순차 정수(users.id)라 UUID 인 `{id}` 와 형태가 다르다 → `{num}` 자리표시자.
    //   계정 id 는 파일과 달리 열거해도 새는 정보가 없고(목록 자체가 Admin 전용),
    //   URL 에 UUID 를 따로 두는 것보다 화면·로그가 단순하다.
    ['GET',    '/api/users',               'users_list.php',     'user.manage'],
    ['POST',   '/api/users',               'users_create.php',   'user.manage'],
    ['PATCH',  '/api/users/{num}',         'users_update.php',   'user.manage'],
    ['GET',    '/users',                   'users_page.php',     'user.manage'],

    // 11단계 — 임시 비밀번호 재발급 · 2단계 인증 초기화 (PRD F-16 ⑤, 8장, ARCH 4.0).
    //   정책은 계정 관리와 같은 user.manage (Admin 만). 자기 자신 금지·사유 필수는
    //   핸들러가 Authz::decideUserChange 로 판단한다 — 역할 비교를 핸들러에 복사하지 않는다.
    ['POST',   '/api/users/{num}/password/reset', 'users_password_reset.php', 'user.manage'],
    ['POST',   '/api/users/{num}/mfa/reset',      'users_mfa_reset.php',      'user.manage'],

    // F-59 — 계정별 **개인 프로그램 토큰** (PRD F-59 ② · 8장 `/api/users/{uid}/api-tokens` 세 행,
    //   ARCH 4.6, API.md 13장, SECURITY R-91). 정책은 계정 관리와 같은 `user.manage` 이고,
    //   그래서 재인증 관문 [3-2] 를 자동으로 지난다 — 토큰 발급은 「자격을 만드는」 행동이다.
    //   ★ 이 셋은 **세션 창구**다. `TBOX_API_TOKEN_ROUTES` 에 들어가지 않으므로 토큰으로는
    //     부를 수 없다(토큰이 토큰을 발급하는 길을 만들지 않는다).
    //   `$params[0]` = 대상 계정 id · `$params[1]` = 토큰 id.
    ['GET',    '/api/users/{num}/api-tokens',         'api_tokens_list.php',   'user.manage'],
    ['POST',   '/api/users/{num}/api-tokens',         'api_tokens_issue.php',  'user.manage'],
    ['DELETE', '/api/users/{num}/api-tokens/{num}',   'api_tokens_revoke.php', 'user.manage'],

    // 본인 계정 관리 — 로그인한 사람이면 누구나 자기 것만 (PRD 7.7 "비밀번호 변경", R-05).
    // 대상은 언제나 **세션의 본인**이며 요청 본문에 대상 계정을 받지 않는다.
    ['POST',   '/api/me/password',         'password_change.php', Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/mfa/enroll',       'mfa_enroll.php',      Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/mfa/confirm',      'mfa_confirm.php',     Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/mfa/disable',      'mfa_disable.php',     Authz::POLICY_AUTHENTICATED],

    // 휴지통 — Admin 만 (PRD 5.4 "삭제된 파일 복구(30일 내)", F-11).
    // `/api/files/trash` 는 `{id}`(UUID 36자)와 형태가 달라 `/api/files/{id}` 계열과 충돌하지 않지만,
    // 의도를 드러내려고 위에 둔다(7단계 `/api/files/fetch` 와 같은 이유).
    ['GET',    '/api/files/trash',          'files_trash.php',   'role:Admin'],
    ['POST',   '/api/files/{id}/restore',   'files_restore.php', 'role:Admin'],

    // 12단계 — 기능별 화면 분리 (PRD F-20, 8장 「화면(HTML)」 행, 10장 화면 요구사항).
    //   한 페이지에 쌓여 있던 기능을 화면 단위로 나눈 것이며 **권한 규칙은 그대로**다:
    //   휴지통 화면은 데이터(`GET /api/files/trash`)와 **같은 정책** role:Admin 을 쓰고,
    //   「내 계정」 화면은 본인 계정 API 와 같은 authenticated 를 쓴다.
    //   화면만 열어 두고 API 를 막지 않거나 그 반대면 그것이 곧 우회 경로다 (PRD S-01).
    ['GET',    '/trash',                    'trash_page.php',    'role:Admin'],
    ['GET',    '/settings',                 'settings_page.php', Authz::POLICY_AUTHENTICATED],

    // 12-3 — 조직·권한 (PRD F-19 · 5.4 · 8장 `/api/org/*` 행, ARCH 5.9).
    //   org.manage : Admin 만 (Authz::canManageOrg). 나머지 역할은 여기서 끝나고
    //                그 시도 자체가 `authz.route|deny` 로 남는다 (T-27).
    //   `/org` 화면과 `/api/org/*` 데이터는 **같은 정책**을 쓴다 — 화면만 숨기고 API 를
    //   열어 두면 그것이 곧 우회 경로다 (PRD S-01, 계정 관리·감사 로그와 같은 원칙).
    //   `{num}` 은 순차 정수인 teams.id 다 (계정 id 와 같은 형태).
    //   ★ 팀을 지우는 라우트(DELETE)는 만들지 않는다 — 파일·계정·감사 기록이 팀을 참조하므로
    //     비활성화(`PATCH … {is_active:false}`)까지만 있다 (ARCH 5.9).
    //   ★ 권한 매트릭스는 GET 하나뿐이다. 쓰기 라우트가 없고 DB 권한도 SELECT 만이다
    //     (ARCH 6.2-1 · 10장, SECURITY R-43, PRD Q-12).
    ['GET',    '/org',                           'org_page.php',         'org.manage'],
    ['GET',    '/api/org/teams',                 'org_teams.php',        'org.manage'],
    ['POST',   '/api/org/teams',                 'org_team_create.php',  'org.manage'],
    ['PATCH',  '/api/org/teams/{num}',           'org_team_update.php',  'org.manage'],
    ['GET',    '/api/org/teams/{num}/members',   'org_team_members.php', 'org.manage'],
    ['GET',    '/api/org/permissions',           'org_permissions.php',  'org.manage'],

    // 14단계 — 위키 (PRD F-21 · 8장 `/wiki` 행 · 10장 「위키」, ARCH 5.10, SECURITY R-56).
    //   두 경로 모두 `role:Admin` 이다. 왜 Admin 만인가 — 위키는 네 문서를 초보자 말로 다시 쓴
    //   해설이고, 그 안에는 남은 위험(R-xx)·서버 설정·사고 경위가 풀려 있다. Guest(협력사)·
    //   Employee 가 읽으면 그것이 곧 공격 지도다 (SECURITY R-56). 범위를 넓히려면 이 두 줄과
    //   함께 R-56 을 다시 판단한다(예: 안내 글만 authenticated 로 가르기).
    //   새 정책 이름을 만들지 않고 휴지통과 같은 `role:Admin` 을 쓴다 — 위키에는 "관리하는
    //   행위" 가 없어 감사 로그에서 갈라 볼 이유가 없다(조직 관리의 `org.manage` 와 반대 경우).
    //   `{slug}` 는 글 이름이며 파일 이름이 되는 값이다. 자리표시자 정규식이 1차 관문이고,
    //   Wiki::pathFor() 가 같은 정규식 + realpath 로 한 번 더 본다 (ARCH 5.10).
    //   감사 기록은 남기지 않는다 (PRD F-21) — 문서 열람은 보안 사건이 아니다.
    ['GET',    '/wiki',                          'wiki_index.php',       'role:Admin'],
    ['GET',    '/wiki/{slug}',                   'wiki_page.php',        'role:Admin'],

    // 15-1 — 내 활동 (PRD F-12 · 8장 `/activity` 행, ARCH 5.11).
    //   정책은 `authenticated` 다. "누구의 활동인가" 는 권한이 아니라 **대상**의 문제이고,
    //   대상은 핸들러의 SQL 이 세션 본인으로 고정한다(요청에서 받지 않는다 — PRD S-02).
    //   화면(`/activity`)과 데이터(`/api/me/activity`)가 같은 정책을 쓰는 것은 다른 화면과 같다.
    ['GET',    '/activity',                      'activity_page.php',    Authz::POLICY_AUTHENTICATED],
    ['GET',    '/api/me/activity',               'activity_list.php',    Authz::POLICY_AUTHENTICATED],

    // 15-2 — 만료 공유 링크 (PRD F-13 · Q-5 기본안 · 8장 4행, ARCH 5.12).
    //   POST …/share  : 정책은 수정과 **같은** file.update 다 — 링크를 만들 수 있는 사람은
    //                   그 파일을 고칠 수 있는 사람이기 때문이다(Guest 는 여기서 끝난다).
    //                   특정 파일에 대한 판단은 핸들러가 decideRead → decideEdit 로 다시 한다.
    //   GET  /api/me/shares · DELETE /api/shares/{num} : 내가 만든 링크 목록·회수.
    //                   회수 자격(만든 사람 또는 Admin)은 Authz::decideShareRevoke 가 판단한다.
    //   GET  /s/{token}: **public** 이다 — 로그인·CSRF·강제 절차 관문을 지나지 않는 두 번째
    //                   라우트이며(첫째는 /api/login), 자격은 토큰 자체가 대신한다.
    //                   `{token}` 은 base64url 43자만 받는다(그 밖은 라우터 404).
    ['POST',   '/api/files/{id}/share',          'share_create.php',     'file.update'],
    ['GET',    '/api/me/shares',                 'shares_list.php',      Authz::POLICY_AUTHENTICATED],
    ['DELETE', '/api/shares/{num}',              'share_revoke.php',     Authz::POLICY_AUTHENTICATED],
    ['GET',    '/s/{token}',                     'share_download.php',   Authz::POLICY_PUBLIC],

    // 16-1 — 관리 기능 재인증, sudo 모드 (PRD F-23 · 8장 2행 · 10장 「재인증」, ARCH 4.0).
    //   두 라우트 모두 정책은 `authenticated` 다 — 재인증하러 가는 길에 재인증을 요구하면
    //   들어갈 방법이 없다. **이 두 라우트는 아무 권한도 주지 않는다**: 여기서 비밀번호를
    //   맞혀도 무엇을 할 수 있는지는 관리 라우트의 정책(user.manage·org.manage·audit.read·
    //   role:Admin)이 그대로 정한다. 관문 [3-2] 가 인가 **뒤**에 있기 때문이다 (T-36).
    //   POST /api/me/reauth 는 다른 본인 계정 창구와 같은 자리에 두어야 마땅하지만,
    //   16단계에서 더한 것임을 드러내려고 목록 끝에 둔다(동작은 위치와 무관하다).
    //   nginx 는 이 경로(끝 슬래시 변형 포함)에 `tbox_login` 존을 건다 — 비밀번호를 다시
    //   받는 창구라 무차별 대입의 표적이다 (SECURITY R-59, 13단계와 같은 방식).
    ['POST',   '/api/me/reauth',                 'reauth.php',           Authz::POLICY_AUTHENTICATED],
    ['GET',    '/reauth',                        'reauth_page.php',      Authz::POLICY_AUTHENTICATED],

    /* 26단계 — 「역할로 보기」 (PRD F-39 · 8장 `/api/me/act` 행, ARCH 5.20, SECURITY R-74).
     *
     * 두 줄의 정책이 **일부러 다르다**:
     *   POST   role:Admin     켜기. 새 정책 이름을 만들지 않은 이유는 관리자 영역과 같다 —
     *                         자격이 같고, `role:Admin` 은 이미 `Authz::REAUTH_POLICIES` 에
     *                         있어 **재인증 관문 [3-2] 가 자동으로 따라붙는다**(F-23).
     *                         보는 중에는 유효 역할이 Admin 이 아니므로 이 정책이 「겹쳐 켜기」도
     *                         함께 막는다 — 먼저 꺼야 다시 켤 수 있다 (F-39 「아래로만」).
     *   DELETE authenticated  끄기. 보는 중인 사람의 유효 역할은 Admin 이 아니라, 끄기에도
     *                         role:Admin 을 걸면 **돌아올 길이 없다**. 끄기는 권한을 만들지
     *                         않는다 — 임시로 좁혀 둔 역할을 원래 `users.role` 로 되돌릴 뿐이다.
     *
     * 화면(`/admin` 의 카드)과 데이터가 같은 자격을 쓰는 것은 다른 관리 기능과 같다 (PRD S-01).
     */
    ['POST',   '/api/me/act',                    'me_act_start.php',     'role:Admin'],
    ['DELETE', '/api/me/act',                    'me_act_stop.php',      Authz::POLICY_AUTHENTICATED],

    // 17단계 — 관리자 영역 (PRD F-24 · F-25 · 8장 `/admin`·`/api/admin/*` 행, ARCH 5.14 · 5.15).
    //   여섯 라우트 전부 정책은 `role:Admin` 이다. 새 정책 이름을 만들지 않은 이유는 위키·휴지통과
    //   같다 — 자격이 같고, 무엇보다 `role:Admin` 은 이미 `Authz::REAUTH_POLICIES` 에 들어 있어
    //   **재인증 관문 [3-2] 가 자동으로 따라붙는다**(F-23). 정책을 새로 만들면 그 목록에 넣는 것을
    //   빠뜨릴 수 있고, 빠뜨린 그 하나가 곧 우회 경로다 (SECURITY R-04 와 같은 원칙).
    //   화면(`/admin`·`/admin/permissions`)과 데이터(`/api/admin/*`)가 **같은 정책**을 쓰는 것도
    //   다른 관리 화면과 같다 — 화면만 숨기고 API 를 열어 두면 그것이 곧 우회 경로다 (PRD S-01).
    //   ★ `PATCH /api/admin/permissions` 가 이 저장소에서 `permissions` 표를 바꾸는 **유일한**
    //     라우트다. 쓰기는 별도 DB 롤 `tbox_perm`(Db::perm)으로만 하고, 앱 롤 `tbox_app` 은
    //     여전히 그 표에 SELECT 만 갖는다 (ARCH 5.15 · 10장, SECURITY R-60, PRD Q-12).
    //   ★ 네트워크 겹(관리망 IP 만 허용)은 nginx 두 사이트 파일에 **주석**으로 준비돼 있다.
    //     기본은 꺼짐이다 — 접속 출처가 셋이라 켜면 스스로 잠길 수 있다 (SECURITY R-61, README).
    ['GET',    '/admin',                         'admin_page.php',               'role:Admin'],
    ['GET',    '/admin/permissions',             'admin_permissions_page.php',   'role:Admin'],
    ['GET',    '/api/admin/permissions',         'admin_permissions_get.php',    'role:Admin'],
    ['PATCH',  '/api/admin/permissions',         'admin_permissions_update.php', 'role:Admin'],
    ['GET',    '/api/admin/shares',              'admin_shares.php',             'role:Admin'],
    ['GET',    '/api/admin/alerts',              'admin_alerts.php',             'role:Admin'],

    /*
     * F-43 — URL 가져오기 허용 사이트 (PRD F-43 · 8장 3행, ARCH 5.22, SECURITY R-76).
     *
     * 정책은 위 관리자 영역과 **같은 `role:Admin`** 이다. 새 정책 이름을 만들지 않는 이유도 같다:
     * 자격이 같고, `role:Admin` 은 이미 `Authz::REAUTH_POLICIES` 에 있어 **재인증 관문 [3-2] 가
     * 자동으로 따라붙는다**(F-23). 정책을 새로 만들면 그 목록에 넣는 것을 빠뜨릴 수 있고,
     * 빠뜨린 그 하나가 곧 우회 경로다 (SECURITY R-04).
     *
     * ★ `POST /api/fetch-hosts` 가 이 저장소에서 `fetch_hosts` 표를 바꾸는 **유일한** 라우트다.
     *   DELETE 라우트는 **없다** — 그만 쓰는 것은 `is_active=false` 이고, 앱 DB 계정에도 그 표의
     *   DELETE 권한이 없다 (db/grants.sql, ARCH 10장).
     * ★ 화면(`/admin/fetch-hosts`)과 데이터(`/api/fetch-hosts`)가 같은 정책을 쓴다 —
     *   화면만 숨기고 API 를 열어 두면 그것이 곧 우회 경로다 (PRD S-01).
     */
    ['GET',    '/admin/fetch-hosts',             'admin_fetch_hosts_page.php',   'role:Admin'],
    ['GET',    '/api/fetch-hosts',               'fetch_hosts_list.php',         'role:Admin'],
    ['POST',   '/api/fetch-hosts',               'fetch_hosts_update.php',       'role:Admin'],

    // 18-1 — 폴더 (PRD F-26 · 8장 `/api/folders` 4행, ARCH 5.16).
    //   폴더도 **파일과 같은 등급 체계**를 갖는다(사용자 결정 2026-09-13 「폴더 자체도 권한」).
    //   그래서 정책도 파일의 같은 행동과 **같은 것**을 쓴다 — 새 정책 이름을 만들지 않는다:
    //     file.read   : 트리 조회. 라우터는 "읽는 종류의 행동인가" 까지만 보고, 그 폴더를
    //                   읽을 수 있는지는 핸들러가 Authz::decideRead 로 다시 판단한다
    //                   (자식 목록은 SQL WHERE 가 거른다 — PRD S-02, 목록과 같은 구조)
    //     file.upload : 폴더 만들기. Guest 는 여기서 끝난다(authz.route deny). 그 등급·팀을
    //                   지정할 자격은 핸들러가 decideUploadAs 로 다시 본다(업로드와 같은 표)
    //     file.update : 이름 변경·이동·등급 변경 → 핸들러 decideFolderChange(+ decideLevelChange)
    //     file.delete : 빈 폴더 삭제 → 핸들러 decideFolderChange
    //   `{num}` 은 순차 정수인 folders.id 다 (teams.id·users.id 와 같은 형태).
    //   F-58: DELETE 를 뺀 셋은 `X-API-Token` 으로도 부를 수 있다 (ARCH 4.5).
    ['GET',    '/api/folders',                   'folders_list.php',             'file.read'],
    ['POST',   '/api/folders',                   'folders_create.php',           'file.upload'],
    ['PATCH',  '/api/folders/{num}',             'folders_update.php',           'file.update'],
    ['DELETE', '/api/folders/{num}',             'folders_delete.php',           'file.delete'],
];

/**
 * 재인증 관문이 화면 요청을 돌려보낼 때 쓰는 **고정 표** (16-1, PRD F-23, ARCH 4.0).
 *
 * 왼쪽은 라우트 패턴(= TBOX_ROUTE 와 같은 표기), 오른쪽은 `/reauth?to=` 에 붙일 키다.
 * 요청에서 온 문자열은 이 표의 **키를 고르는 데만** 쓰이고 Location 헤더에는 표에 적힌
 * 다섯 글자(`users`·`org`·`audit`·`trash`·`wiki`) 중 하나만 들어간다 — 오픈 리다이렉트가
 * 생길 자리가 없다. 표에 없는 관리 라우트(예: `PATCH /api/users/{num}`)는 API 이거나
 * 돌아갈 화면이 없는 경우이므로 꼬리표 없이 `/reauth` 로 보낸다.
 */
const TBOX_REAUTH_RETURN = [
    'GET /users'       => 'users',
    'GET /org'         => 'org',
    'GET /audit'       => 'audit',
    'GET /trash'       => 'trash',
    'GET /wiki'        => 'wiki',
    'GET /wiki/{slug}' => 'wiki',
    // 17-1 — 관리자 영역 (PRD F-24, ARCH 5.14). 허브와 매트릭스 편집 화면 둘 다 키는 `admin`
    // 하나이고, 확인이 끝나면 **허브**(`/admin`)로 돌아온다. 화면이 하나 더 늘어도 키를 늘리지
    // 않는 이유는 오픈 리다이렉트 표면을 키 목록만큼으로 묶어 두기 위해서다 — 돌아갈 경로는
    // 아래 TBOX_REAUTH_PATHS 에 적힌 고정 문자열뿐이고, 요청 값은 키를 고르는 데만 쓰인다.
    'GET /admin'              => 'admin',
    'GET /admin/permissions'  => 'admin',
    // F-43 — 허용 사이트 화면도 키는 `admin` 하나다(화면이 늘어도 키를 늘리지 않는다).
    'GET /admin/fetch-hosts'  => 'admin',
];

/**
 * 키 → 돌아갈 **고정 경로**. `/reauth` 화면(reauth_page.php)이 `?to=` 값이 표에 있는지
 * 확인하는 데 쓰고, 실제 이동은 app.js 의 **같은 내용의 맵**이 한다.
 * 경로 문자열이 요청 값에서 만들어지는 곳은 서버에도 화면에도 없다 (PRD F-23).
 */
const TBOX_REAUTH_PATHS = [
    'users' => '/users',
    'org'   => '/org',
    'audit' => '/audit',
    'trash' => '/trash',
    'wiki'  => '/wiki',
    // 17-1 — 관리자 허브 (PRD F-24). `/reauth?to=admin` 은 언제나 이 고정 경로로 돌아온다.
    'admin' => '/admin',
];

/** 재인증 화면의 경로. 코드 상수이며 요청 값이 섞이지 않는다 (TBOX_LOGIN_PATH 와 같다). */
const TBOX_REAUTH_PATH = '/reauth';

/**
 * 최초 로그인 강제 절차의 **허용 목록** (11단계, PRD F-16 ②, ARCH 4.0 「최초 로그인 강제 절차」).
 *
 * 값은 `메서드 라우트패턴` 문자열이며 라우터가 매칭 직후 정의하는 TBOX_ROUTE 와 **정확히** 비교한다.
 * 실제 경로가 아니라 패턴을 쓰는 이유는 감사 로그(detail.route)와 같은 표기를 쓰기 위해서다.
 *
 * `GET /login` 은 정책이 public 이라 관문 자체를 지나지 않는다(그래서 목록에 없다).
 */
const TBOX_GATE_ALLOW_PASSWORD_CHANGE = [
    'GET /',                    // 홈 — 화면이 강제 절차 화면만 그린다
    'GET /api/me',              // 지금 무엇을 해야 하나(`next`)
    'POST /api/logout',         // 절차를 미루고 나가는 길은 언제나 열어 둔다
    'POST /api/me/password',    // 절차 그 자체
];
const TBOX_GATE_ALLOW_MFA_SETUP = [
    'POST /api/me/mfa/enroll',
    'POST /api/me/mfa/confirm',
];

const TBOX_HANDLER_DIR = __DIR__ . '/../src/handlers/';

/**
 * 경로 자리표시자 → 허용 문자.
 *
 *   `{id}`  파일의 외부 ID (UUID 36자). 순차 정수를 URL 로 받지 않으므로 열거 표면이 없다 (PRD S-09-5).
 *   `{num}` 계정 id 같은 순차 정수 (10단계, 최대 9자리). UUID 와 형태가 겹치지 않는다.
 *
 * 자리표시자는 이 표에 있는 것만 쓸 수 있고, 표에 없는 `{…}` 가 경로에 있으면
 * tbox_validate_routes() 가 부팅을 막는다(정규식이 아니라 문자 그대로 매칭되어
 * 어떤 요청과도 맞지 않는 죽은 라우트가 되는 것을 방지).
 */
const TBOX_PATH_PATTERNS = [
    '{id}'  => '[0-9a-f-]{36}',
    '{num}' => '[0-9]{1,9}',
    // 14단계 — 위키 글 이름 (F-21, ARCH 5.10). 소문자·숫자·붙임표 40자까지이고 첫 글자는
    // 붙임표가 아니다. 이 값은 파일 이름이 되므로 `.`·`/`·대문자·%인코딩이 한 글자도
    // 통과하지 못한다 — `/wiki/../x` 나 `/wiki/ZZ` 는 라우트에 맞지 않아 404 다.
    // Wiki::pathFor() 가 같은 정규식을 다시 보고 realpath 로 wiki/ 안인지 확인한다.
    '{slug}' => '[a-z0-9][a-z0-9-]{0,39}',
    // 15-2 — 공유 링크 토큰 (F-13, ARCH 5.12). random_bytes(32) 를 base64url 로 옮긴 43자다.
    // 패딩(`=`)은 떼고 만들므로 여기에도 없다. 길이·문자가 한 글자라도 다르면 라우트에 맞지
    // 않아 **404** 이고, 맞더라도 DB 에는 sha256 만 있어 대조에 실패하면 역시 404 다.
    // nginx 의 `location ~ "^/s/[A-Za-z0-9_-]{43}$"` 속도 제한과 **같은 정규식**이다.
    '{token}' => '[A-Za-z0-9_-]{43}',
];

/** 미로그인 화면 요청을 보내는 곳. 코드 상수이며 요청 값이 섞이지 않는다 (ARCH 4.0). */
const TBOX_LOGIN_PATH = '/login';

/** 이미 로그인한 사용자가 로그인 화면에 왔을 때 보내는 곳 (src/handlers/login_page.php). */
const TBOX_HOME_PATH = '/';

/**
 * 프로그램용 토큰(`X-API-Token`)이 통하는 **라우트 집합** (PRD F-49 ① · F-50 · F-59, ARCH 4.2 · 4.3 · 4.6).
 *
 * 값은 `TBOX_ROUTE`(메서드 + 라우트 **패턴**)와 정확히 같은 표기여야 한다. 아래 [2-T] 가
 * 이 집합과만 견주므로, 토큰 경로를 넓히려면 여기를 고쳐야 하고 그것이 곧 검토 지점이다.
 *
 * F-50 에서 셋으로, F-56 에서 넷으로, F-58 에서 여덟으로, F-59 에서 **열로**.
 * 넓히려면 여기 + R-82/R-83/R-89/R-90/R-91 재검토.
 */
const TBOX_API_TOKEN_ROUTES = [
    'POST /api/upload',
    'GET /api/files',
    'GET /api/files/{id}/download',
    'GET /api/whoami',
    // F-58 — 폴더 탐색·만들기·이름 변경·이동 과 파일 이동 (ARCH 4.5, SECURITY R-90).
    'GET /api/folders',
    'POST /api/folders',
    'PATCH /api/folders/{num}',
    'PATCH /api/files/{id}',
    // F-59 — 삭제 둘 (ARCH 4.6, SECURITY R-91). 아래 PERSONAL_ROUTES 이기도 하다.
    'DELETE /api/files/{id}',
    'DELETE /api/folders/{num}',
];

/**
 * 그중 **개인 토큰만** 통하는 창구 (F-59 ⑤, PRD F-59, ARCH 4.6 판정 순서 표 마지막 줄).
 *
 * 지우는 창구 둘이다. 공용 토큰(`.env`)으로 부르면 403 「이 창구는 개인 토큰으로만 쓸 수
 * 있습니다」 이고 `auth.token deny shared_token_not_permitted` 가 남는다 — 「누가 지웠나」 가
 * 사람 단위로 남지 않는 자격으로는 지울 수 없게 한 것이다(R-82 의 결손이 곧 이 제한의 이유다).
 *
 * ★ 판정은 **여기 한 곳**이다. `requireApiToken($personalOnly)` 이 그 인자를 받아 마지막에
 *   판단하고, 삭제 핸들러에는 토큰 종류를 보는 코드가 한 줄도 없다(세션과 같은 코드다).
 */
const TBOX_API_TOKEN_PERSONAL_ROUTES = [
    'DELETE /api/files/{id}',
    'DELETE /api/folders/{num}',
];

/**
 * 그중 **세션으로는 부를 수 없는** 창구 둘 (F-49 · F-56). 여기 적힌 라우트는 헤더가 없어도 토큰 경로로
 * 판단해 「X-API-Token 헤더가 없습니다」 401 을 돌려준다 — 화면이 쓰지 않는 프로그램 전용 창구라
 * "로그인하라" 는 답이 뜻이 없기 때문이다.
 *
 * 열 중 나머지 여덟(`GET /api/files`·`GET /api/files/{id}/download`·`GET /api/folders`·
 * `POST /api/folders`·`PATCH /api/folders/{num}`·`PATCH /api/files/{id}`·`DELETE /api/files/{id}`·
 * `DELETE /api/folders/{num}`)은 **화면도 쓰는 라우트**다. 그래서
 * 토큰 경로로 가는 조건은 `X-API-Token` 헤더가 실제로 왔을 때뿐이고, 헤더가 없으면 지금까지와
 * 똑같이 세션 경로로 간다(웹 동작 불변). 헤더가 왔는데 값이 틀리면 401 이다 — 세션으로
 * 되돌아가지 않는다(보낸 인증 방법으로만 판정한다).
 */
const TBOX_API_TOKEN_ONLY_ROUTES = [
    'POST /api/upload',
    'GET /api/whoami',
];

/**
 * 라우트 표가 스스로 성립하는지 검사한다. 하나라도 어긋나면 요청을 처리하지 않는다.
 *
 * 검사 항목
 *   1) 항목이 4칸([메서드, 경로, 핸들러, 정책])인가
 *   2) 메서드가 허용 목록에 있나
 *   3) 경로가 '/' 로 시작하고, 허용 문자와 `{id}` 만 쓰나
 *   4) 정책이 Authz::KNOWN_POLICIES 에 있나        ← "정책 미등록 라우트는 부팅 실패"
 *   5) 핸들러 파일명이 안전한 형태이고, src/handlers/ 안에 실제로 존재하나
 *
 * @return list<string> 문제 목록. 빈 배열이면 정상.
 */
function tbox_validate_routes(): array
{
    $problems = [];
    $methods  = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    $seen     = [];

    foreach (TBOX_ROUTES as $i => $route) {
        $where = 'route#' . $i;

        if (!is_array($route) || count($route) !== 4) {
            $problems[] = $where . ': 항목이 [메서드, 경로, 핸들러, 정책] 4칸이 아니다';
            continue;
        }
        [$method, $path, $handler, $policy] = $route;

        if (!is_string($method) || !in_array($method, $methods, true)) {
            $problems[] = $where . ': 알 수 없는 메서드';
        }
        if (!is_string($path) || $path === '' || $path[0] !== '/'
            || preg_match('#^(/|(/(\{[a-z]+\}|[a-z0-9_.-]+))+)$#', $path) !== 1) {
            $problems[] = $where . ': 경로 패턴이 허용 형태가 아니다';
        } elseif (preg_match_all('/\{[a-z]+\}/', $path, $found) > 0) {
            // 표에 없는 자리표시자(`{uid}` 같은 오타)는 정규식이 아니라 글자 그대로 매칭되어
            // 어떤 요청과도 맞지 않는 죽은 라우트가 된다. 그래서 부팅 시점에 막는다.
            foreach ($found[0] as $token) {
                if (!array_key_exists($token, TBOX_PATH_PATTERNS)) {
                    $problems[] = $where . ': 모르는 경로 자리표시자 ' . $token;
                }
            }
        }
        if (!is_string($policy) || !in_array($policy, Authz::KNOWN_POLICIES, true)) {
            $problems[] = $where . ': 정책이 Authz::KNOWN_POLICIES 에 없다 (policy=' . var_export($policy, true) . ')';
        }
        if (!is_string($handler) || preg_match('/^[a-z0-9_]+\.php$/', $handler) !== 1) {
            $problems[] = $where . ': 핸들러 파일명이 허용 형태가 아니다';
        } elseif (!is_file(TBOX_HANDLER_DIR . $handler)) {
            $problems[] = $where . ': 핸들러 파일이 없다 (' . $handler . ')';
        }

        $key = (is_string($method) ? $method : '?') . ' ' . (is_string($path) ? $path : '?');
        if (isset($seen[$key])) {
            $problems[] = $where . ': 같은 메서드+경로가 중복 등록됐다 (' . $key . ')';
        }
        $seen[$key] = true;
    }

    return $problems;
}

/**
 * 경로 패턴을 정규식으로 바꾼다. TBOX_PATH_PATTERNS 에 있는 자리표시자만 인정한다.
 *
 * 패턴 문자열은 라우트 표에 적힌 고정 값이고 요청 값이 아니다. 그래도 자리표시자 **밖**의
 * 모든 조각은 preg_quote 를 거치므로, 경로에 정규식 메타문자가 들어가도 그대로 글자로 다뤄진다.
 * 자리표시자가 여러 개면 왼쪽부터 차례로 캡처 그룹이 되어 $params[0], $params[1] … 이 된다.
 */
function tbox_pattern_to_regex(string $path): string
{
    $regex = '';
    $rest  = $path;

    while ($rest !== '') {
        // 가장 먼저 나오는 자리표시자를 찾는다 (없으면 나머지를 통째로 글자로 처리)
        if (preg_match('/\{[a-z]+\}/', $rest, $m, PREG_OFFSET_CAPTURE) !== 1) {
            $regex .= preg_quote($rest, '#');
            break;
        }
        $token  = $m[0][0];
        $at     = (int) $m[0][1];
        $regex .= preg_quote(substr($rest, 0, $at), '#');
        // 표에 없는 자리표시자는 tbox_validate_routes() 가 부팅에서 막는다.
        // 그래도 여기서 다시 확인해, 검사를 건너뛴 경로가 "무엇이든 매칭" 이 되지 않게 한다.
        $regex .= '(' . (TBOX_PATH_PATTERNS[$token] ?? '(?!)') . ')';
        $rest   = substr($rest, $at + strlen($token));
    }

    return '#^' . $regex . '$#';
}

// ─── [0-1] 라우트 표 자체 검사 (정책 미등록 → 부팅 실패) ─────────
$routeProblems = tbox_validate_routes();
if ($routeProblems !== []) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' 라우트 표가 올바르지 않아 요청을 처리하지 않습니다: ' . implode(' | ', $routeProblems));
    Http::error(500, '서버 오류');
}

// ─── [1] 라우트 매칭 ───────────────────────────────────────────
$reqMethod = Http::method();
$reqPath   = Http::path();

$matched       = null;    // [핸들러, 정책, 경로변수]
$pathMatched   = false;   // 경로는 맞았는데 메서드가 달랐다 → 405
$allowedMethods = [];

foreach (TBOX_ROUTES as $route) {
    [$method, $path, $handler, $policy] = $route;
    if (preg_match(tbox_pattern_to_regex($path), $reqPath, $m) !== 1) {
        continue;
    }
    $pathMatched      = true;
    $allowedMethods[] = $method;
    if ($method !== $reqMethod) {
        continue;
    }
    array_shift($m);
    $matched = [$handler, $policy, array_values($m), $path];
    break;
}

if ($matched === null) {
    if ($pathMatched) {
        if (!headers_sent()) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
        }
        Http::error(405, '허용되지 않는 메서드입니다');
    }
    Http::error(404, Http::MSG_NOT_FOUND);
}

[$handlerFile, $policy, $params, $routePattern] = $matched;

/**
 * 이 요청이 어느 라우트였나 — 감사 로그의 `detail.route` (ARCH 7.5, PRD S-07 "요청 경로").
 *
 * 값은 **라우트 표의 패턴**(`DELETE /api/files/{id}`)이며 실제 UUID 가 아니다.
 * 실제 경로를 남기면 감사 로그가 파일 ID 목록이 되어, 로그를 읽을 수 있는 사람에게
 * 열거 재료를 주게 된다. 404/405 로 끝난 요청에는 라우트가 없으므로 정의되지 않는다.
 * 인증 검사보다 **먼저** 정의한다 — 401·403 으로 끝나는 행에도 경로가 남아야 한다.
 */
define('TBOX_ROUTE', $reqMethod . ' ' . $routePattern);

// ─── [2] 인증 · [3] CSRF · [4] 인가 ────────────────────────────
$user = null;

/* F-49 — 이 요청이 **프로그램용 업로드 토큰**으로 들어왔나 (PRD F-49, ARCH 4.2).
 * 아래 [2] 에서만 참이 되고, [3-2] 재인증 관문을 건너뛰는 판단에 쓴다.
 * 토큰 경로는 세션이 없으므로 "15분 안에 비밀번호를 다시 확인했나" 라는 물음 자체가 성립하지 않는다.
 * (file.upload 는 Authz::REAUTH_POLICIES 에 없어 어차피 해당 없음이지만, 코드 구조로도 지나지 않게 한다.) */
$viaApiToken = false;

if ($policy !== Authz::POLICY_PUBLIC) {
    /* ─── [2-T] 프로그램용 토큰 경로 (F-49 · F-50 · F-56 · F-58 · F-59, ARCH 4.2 · 4.3 · 4.4 · 4.5 · 4.6) ──
     *
     * ★ 이 가지에 들어오는 라우트는 위 집합의 **열뿐**이다. 그래서 `X-API-Token` 은 그 창구 밖에서
     *   아무것도 열지 못한다 — 다른 라우트는 그 헤더를 읽는 코드에 닿지도 않는다(PRD F-49 ①).
     *   **어느 라우트인가**는 요청이 보낸 값이 아니라 라우터가 매칭한 **라우트 패턴**으로만 판단한다.
     *
     * ★ F-50 에서 넓힌 둘과 F-58 의 넷(여섯)은 화면도 쓰는 라우트라, 조건이 하나 더 있다: `X-API-Token` 헤더가
     *   실제로 왔을 때만 이 가지로 온다. 헤더가 없으면 아래 세션 경로로 그대로 간다 —
     *   그러지 않으면 브라우저의 목록·다운로드가 전부 「헤더가 없습니다」 401 이 된다.
     *   헤더의 **유무**만 갈림길이고, 값의 옳고 그름은 여전히 `requireApiToken` 이 판정한다
     *   (틀린 값이면 401 이고 세션으로 되돌아가지 않는다).
     *
     * 건너뛰는 것: 세션 조회 · CSRF([3]) · 최초 로그인 강제 절차([3-1]) · 재인증([3-2]).
     *   CSRF 는 "브라우저가 쿠키를 자동으로 싣는 것" 을 막는 장치인데 토큰은 자동으로 실리지
     *   않으므로 대상이 아니다. 강제 절차·재인증은 **사람**의 절차이고, 귀속 계정이 그 절차 중이면
     *   requireApiToken 이 아예 503(`user_invalid`)으로 끊는다.
     * 그대로 타는 것: 아래 [4] 인가와, 핸들러 안의 등급 권한·폴더 규칙·형식 검사 — 웹과 같은 코드다.
     */
    if (in_array(TBOX_ROUTE, TBOX_API_TOKEN_ROUTES, true)
        && (Http::apiTokenHeader() !== null
            || in_array(TBOX_ROUTE, TBOX_API_TOKEN_ONLY_ROUTES, true))
    ) {
        /* F-59 — 「개인 토큰만 통하는 창구인가」 도 **라우트 패턴으로만** 판단해서 넘긴다.
         * 이 인자 하나가 그 판정의 전부이고, 삭제 핸들러는 토큰 종류를 보지 않는다(ARCH 4.6). */
        $user = Auth::requireApiToken(
            in_array(TBOX_ROUTE, TBOX_API_TOKEN_PERSONAL_ROUTES, true)
        );                                        // 실패 시 401·403·503 + Audit::deny (Auth 안에서)
        $viaApiToken = true;
        // 감사 로그가 모든 행에 자동으로 붙이는 분류어 (Audit::autoDetail, ARCH 4.2 「감사의 구분」).
        define('TBOX_AUTH_METHOD', 'api_token');
    } else {
    // 미로그인 처리 (ARCH 4.0): API 는 401 JSON + Audit::deny, 화면 경로는 302 /login.
    // 화면 경로에 감사 로그를 남기지 않는 이유 — 세션이 만료된 뒤의 첫 방문은 공격이 아니고,
    // 그런 요청까지 남기면 실제 공격 흔적이 묻힌다 (PRD S-07 취지).
    if (Auth::currentUser() === null && strpos($reqPath, '/api/') !== 0) {
        Http::redirect(TBOX_LOGIN_PATH);
    }
    $user = Auth::requireLogin();          // 실패 시 401 + Audit::deny (Auth 안에서)
    Http::requireCsrf();                   // 상태 변경 요청의 토큰 검사 (PRD S-09-3)

    /* ─── [3-1] 최초 로그인 강제 절차 관문 (11단계, PRD F-16, ARCH 4.0) ───────────
     *
     * 임시 비밀번호로 처음 들어온 사람(또는 Admin 이 재발급·초기화한 사람)은
     * **비밀번호 변경 → 2단계 인증 등록**을 마치기 전에는 다른 기능을 쓸 수 없다.
     *
     * 왜 여기 한 곳인가: 핸들러마다 검사하면 언젠가 하나를 빠뜨리고, 빠뜨린 그 하나가
     * 우회 경로가 된다 (4단계 서버 인가와 같은 원칙, SECURITY R-04). 화면(app.js)이
     * 절차 화면만 보여 주는 것은 **편의**일 뿐이고, 막는 것은 이 관문이다.
     *
     * 인가(authorize)보다 **앞**에 둔다: 절차를 마치지 않은 계정에는 역할이 무엇이든
     * "먼저 절차를 끝내라" 가 답이기 때문이다. 판단 근거(must_change_password·
     * mfa_setup_required)는 매 요청 DB 에서 읽은 값이고 요청이 정하지 않는다 (PRD S-01).
     */
    $gateNext = Auth::nextStep($user);
    if ($gateNext !== null) {
        $gateAllowed = $gateNext === 'mfa_setup'
            ? array_merge(TBOX_GATE_ALLOW_PASSWORD_CHANGE, TBOX_GATE_ALLOW_MFA_SETUP)
            : TBOX_GATE_ALLOW_PASSWORD_CHANGE;

        if (!in_array(TBOX_ROUTE, $gateAllowed, true)) {
            $gateReason = $gateNext === 'password_change'
                ? 'password_change_required'
                : 'mfa_setup_required';
            Audit::deny('auth.gate', $gateReason, [
                // 막힌 사람이 곧 대상이다 (ARCH 7.4 문장 틀의 {target}).
                'target_type' => 'user',
                'target_id'   => (int) $user['id'],
                'target_name' => (string) $user['username'],
                // route 패턴은 Audit 이 TBOX_ROUTE 에서 자동으로 넣는다 (ARCH 7.5 route).
                'detail'      => ['next' => $gateNext],
            ]);
            /* 12단계 (PRD F-20, 8장 「화면(HTML)」 행) — 막힌 것이 **화면 요청**이면
             * 403 JSON 대신 절차 화면이 있는 `/` 로 302 를 보낸다. 강제 절차 화면은 `/` 에만
             * 있으므로, 다른 화면에서 403 JSON 을 그대로 받으면 사용자는 "아무것도 안 되는
             * 빈 화면" 을 보게 된다. **막는 판단·감사 기록은 위와 똑같고**(Audit::deny 는 이미
             * 남았다) 응답 형태만 바뀐다. API(`/api/*`)는 기존 403 + next 그대로다 —
             * 화면 편의를 위해 API 의 계약을 바꾸지 않는다.
             */
            if (strpos($reqPath, '/api/') !== 0 && $reqPath !== TBOX_HOME_PATH) {
                Http::redirect(TBOX_HOME_PATH);
            }
            // 다른 403 과 달리 `next` 를 함께 준다 — 막힌 사람이 본인이고,
            // 자기 계정의 다음 걸음을 모르면 절차를 끝낼 수 없기 때문이다 (ARCH 4.0).
            Http::json([
                'error' => $gateNext === 'password_change'
                    ? Http::MSG_PASSWORD_CHANGE_REQUIRED
                    : Http::MSG_MFA_SETUP_REQUIRED,
                'next'  => $gateNext,
            ], 403);
            exit;
        }
    }
    }   // ← [2-T] 토큰 경로 / 세션 경로의 갈림 끝 (F-49). 아래 [4] 인가부터는 **둘 다** 탄다.

    if (!Authz::authorize($policy, $user)) {
        Audit::deny('authz.route', 'role_not_permitted', [
            'target_type' => 'system',
            // 실제 경로가 아니라 라우트 패턴을 남긴다 (ARCH 7.5 route, PRD S-07 "요청 경로")
            'target_name' => TBOX_ROUTE,
            'detail'      => ['policy' => $policy],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);   // 대상 정보를 담지 않는 동일 문구 (PRD S-09-5)
    }

    /* ─── [3-2] 관리 기능 재인증 관문 — sudo 모드 (16-1, PRD F-23, ARCH 4.0) ──────
     *
     * 관리 영역(계정·조직/권한·감사 로그·휴지통·위키)은 세션이 있어도 **최근 15분 안에
     * 비밀번호를 다시 확인한 세션**만 쓸 수 있다. 훔친 쿠키·자리 비움만으로 관리자 콘솔에
     * 닿지 못하게 하는 겹이다 (GitHub·AWS 의 sudo 모드와 같은 원리).
     *
     * ★ 인가(authorize) **뒤**에 둔다. 순서가 뒤바뀌면 "권한이 없는 사람에게 먼저 비밀번호를
     *   묻는" 모양이 되어, 재인증이 마치 권한을 만들어 주는 것처럼 보인다. 지금 순서에서는
     *   Employee 가 `/api/users` 를 부르면 재인증 여부와 무관하게 `authz.route deny` 403 이고,
     *   재인증에 성공한 뒤에도 똑같다 — **재인증은 권한을 만들지 않는다** (PRD T-36).
     *
     * ★ 강제 절차([3-1]) 중에는 관리 라우트가 그 앞에서 이미 막히므로 여기 닿지 않는다.
     *   순서만 지키면 두 관문이 서로를 방해하지 않는다.
     *
     * 판단 근거(`reauth_fresh`)는 매 요청 DB 가 계산한 값이고(Auth::SQL_LOOKUP, DB 시계
     * 하나), 요청이 정하지 않는다 (PRD S-01). 대상 정책 목록은 Authz 한 곳에 있다 —
     * 라우터가 정책 이름을 직접 비교하지 않는다 (ARCH 4.1).
     */
    if (!$viaApiToken && Authz::requiresReauth($policy) && ($user['reauth_fresh'] ?? false) !== true) {
        Audit::deny('auth.gate', 'reauth_required', [
            // 막힌 사람이 곧 대상이다 (ARCH 7.4 문장 틀의 {target}, 강제 절차 관문과 같은 모양).
            'target_type' => 'user',
            'target_id'   => (int) $user['id'],
            'target_name' => (string) $user['username'],
            // route 패턴은 Audit 이 TBOX_ROUTE 에서 자동으로 넣는다 (ARCH 7.5 route).
            'detail'      => ['next' => 'reauth', 'policy' => $policy],
        ]);

        /* 화면 요청이면 재인증 화면으로 302 — 403 JSON 을 그대로 받으면 사용자는 "아무것도
         * 안 되는 빈 화면" 을 본다(강제 절차 관문의 302 와 같은 판단). 꼬리표(`?to=`)에
         * 들어가는 값은 **고정 표에서 고른 키**뿐이고 요청 문자열이 아니다. 표에 없는 화면은
         * 꼬리표 없이 보낸다 — 그러면 확인 뒤 홈으로 간다.
         */
        if (strpos($reqPath, '/api/') !== 0) {
            $reauthKey = TBOX_REAUTH_RETURN[TBOX_ROUTE] ?? null;
            Http::redirect(
                $reauthKey === null ? TBOX_REAUTH_PATH : TBOX_REAUTH_PATH . '?to=' . $reauthKey
            );
        }

        // API 는 강제 절차 관문과 **같은 모양**의 403 이다 — 화면이 한 가지 처리(모달)로
        // 받을 수 있게. `next` 를 주는 이유도 같다: 막힌 사람이 본인이고, 다음 걸음을
        // 모르면 관리 화면을 열 수 없다 (ARCH 4.0).
        Http::json([
            'error' => Http::MSG_REAUTH_REQUIRED,
            'next'  => 'reauth',
        ], 403);
        exit;
    }
}

// ─── [5] 핸들러 실행 ───────────────────────────────────────────
// 핸들러 파일명은 라우트 표에 적힌 고정 문자열이며, 위 검사에서 형태와 존재를 확인했다.
// 사용자 입력은 경로에 한 글자도 들어가지 않는다.
(static function (?array $user, array $params, string $file): void {
    require $file;
})($user, $params, TBOX_HANDLER_DIR . $handlerFile);
