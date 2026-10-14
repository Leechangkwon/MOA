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
    ['GET', '/spaces', 'product_spaces_page.php', Authz::POLICY_AUTHENTICATED],
    // Product spaces: session-only, existing CSRF/consent/first-login gates, central per-space authorization.
    ['GET', '/api/me/home-dashboard', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['PATCH', '/api/me/home-dashboard', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/me/product-home', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/product-spaces', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['POST', '/api/product-spaces', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['POST', '/api/product-spaces/join', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/product-spaces/{id}', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['PATCH', '/api/product-spaces/{id}', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/product-spaces/{id}/home', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/product-spaces/{id}/dashboard', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['PATCH', '/api/product-spaces/{id}/dashboard', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/product-spaces/{id}/members', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['PATCH', '/api/product-spaces/{id}/members/{num}', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['DELETE', '/api/product-spaces/{id}/members/{num}', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/product-spaces/{id}/invites', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['POST', '/api/product-spaces/{id}/invites', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['DELETE', '/api/product-spaces/{id}/invites/{id}', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['POST', '/api/product-spaces/{id}/transfer', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/product-spaces/{id}/items', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['POST', '/api/product-spaces/{id}/items', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/product-spaces/{id}/items/{id}', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['PATCH', '/api/product-spaces/{id}/items/{id}', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['DELETE', '/api/product-spaces/{id}/items/{id}', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/product-spaces/{id}/items/{id}/download', 'product_spaces.php', Authz::POLICY_AUTHENTICATED],

    ['GET', '/join', 'member_join_page.php', Authz::POLICY_PUBLIC],
    ['POST', '/api/register', 'member_register.php', Authz::POLICY_PUBLIC],
    ['GET', '/welcome', 'member_welcome_page.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/invites', 'member_invites.php', 'user.manage'],
    ['POST', '/api/invites', 'member_invites.php', 'user.manage'],
    ['DELETE', '/api/invites/{num}', 'member_invites.php', 'user.manage'],
    ['GET', '/request-invite', 'invite_request_page.php', Authz::POLICY_PUBLIC],
    ['GET', '/request-invite/status', 'invite_request_status_page.php', Authz::POLICY_PUBLIC],
    ['POST', '/api/invite-requests', 'invite_requests_create.php', Authz::POLICY_PUBLIC],
    ['POST', '/api/invite-requests/status', 'invite_requests_status.php', Authz::POLICY_PUBLIC],
    ['GET', '/api/invite-requests', 'invite_requests_list.php', 'user.manage'],
    ['POST', '/api/invite-requests/{num}/decide', 'invite_request_decide.php', 'user.manage'],
    ['POST', '/api/member-installer', 'member_installer.php', 'user.manage'],
    ['GET', '/messages', 'member_messages_page.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/messages/ai', 'member_ai.php', Authz::POLICY_AUTHENTICATED],
    ['POST', '/api/messages/ai', 'member_ai.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/messages/assist', 'member_assist.php', Authz::POLICY_AUTHENTICATED],
    ['POST', '/api/messages/assist', 'member_assist.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/messages/attachments/{id}', 'member_assist_image.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/member-ai-settings', 'member_ai_settings.php', 'user.manage'],
    ['POST', '/api/member-ai-settings', 'member_ai_settings.php', 'user.manage'],
    ['GET', '/api/messages', 'member_messages.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/api/messages/{num}', 'member_messages.php', Authz::POLICY_AUTHENTICATED],
    ['POST', '/api/messages/{num}', 'member_messages.php', Authz::POLICY_AUTHENTICATED],
    ['PATCH', '/api/messages/{num}', 'member_messages.php', Authz::POLICY_AUTHENTICATED],
    ['GET',  '/api/assistant/chat',              'assistant_chat.php', 'assistant.run'],
    ['POST', '/api/assistant/chat',              'assistant_chat.php', 'assistant.run'],
    ['POST', '/api/assistant/runs',              'assistant_runs.php', 'assistant.run'],
    ['GET',  '/api/assistant/runs',              'assistant_runs.php', 'assistant.run'],
    ['GET',  '/api/assistant/runs/{id}',         'assistant_runs.php', 'assistant.run'],
    ['GET',  '/api/assistant/runs/{id}/events',  'assistant_runs.php', 'assistant.run'],
    ['POST', '/api/assistant/runs/{id}/cancel',  'assistant_runs.php', 'assistant.run'],
    ['POST', '/api/assistant/runs/{id}/confirm', 'assistant_runs.php', 'assistant.run'],
    ['GET',  '/',            'home.php',       Authz::POLICY_AUTHENTICATED],
    ['GET',  '/login',       'login_page.php', Authz::POLICY_PUBLIC],
    ['POST', '/api/login',   'login.php',      Authz::POLICY_PUBLIC],
    ['POST', '/api/logout',  'logout.php',     Authz::POLICY_AUTHENTICATED],
    ['GET',  '/api/me',      'me.php',         Authz::POLICY_AUTHENTICATED],
    ['GET',  '/api/health',  'health.php',     Authz::POLICY_PUBLIC],
    // F-42 — 오프라인 코드 생성기가 시계를 맞추는 창구. 시각과 주기만 내준다 (ARCH 5.21).
    ['GET',  '/api/time',    'time.php',       Authz::POLICY_PUBLIC],

    /* 사이트 Q:F-13 P1 — 개인정보 처리방침 · 이용약관 (기획 ../docs/privacy-terms-plan.md 5.2 · src/Legal.php).
     *   GET  /privacy · /terms   정책 **public** — 로그인 없이 연다(런처와 같은 공개 화면 규칙: 세션을 읽지 않는다 · 개인 데이터 0 ·
     *                            Set-Cookie 0 · CSP 그대로). 옛 경로 규칙(사이트 층 [1-0])대로 `/privacy` 는 `/tbox/privacy` 로 308.
     *                            이전 판 `?v=YYYY-MM-DD` 는 목록(config/legal.json)에 **있는 판 글자**만 — 그 밖은 404(경로를 요청 값으로 만들지 않는다).
     *   GET  /consent            정책 authenticated — 기존 회원 안내 화면(약관 동의 + 처리방침 확인 · AI 국외 전송 동의·철회).
     *   GET·POST /api/me/consents 정책 authenticated — 지금 동의 상태 · 동의 줄 더하기(추가만). 대상은 언제나 **세션의 본인**.
     *   ★ 동의 관문(Auth::requireLogin → requireConsent)이 열어 두는 창구는 Auth::CONSENT_GATE_ALLOW 한 곳에 적는다(ARCH 4.11 「순서」의 여덟).
     *   ★ `TBOX_API_TOKEN_ROUTES` 에 넣지 않는다 — 동의는 사람이 세션으로 한다(토큰으로 부르면 세션이 없어 401). */
    ['GET',  '/privacy',         'legal_page.php',   Authz::POLICY_PUBLIC],
    ['GET',  '/terms',           'legal_page.php',   Authz::POLICY_PUBLIC],
    ['GET',  '/consent',         'consent_page.php', Authz::POLICY_AUTHENTICATED],
    ['GET',  '/api/me/consents', 'me_consents.php',  Authz::POLICY_AUTHENTICATED],
    ['POST', '/api/me/consents', 'me_consents.php',  Authz::POLICY_AUTHENTICATED],
    /* 사이트 Q:F-13 P2 — 탈퇴 요청(본인) · 처리(관리자) (기획 5.1 · 5.2 「탈퇴」, src/handlers/me_delete_request.php · admin_deletion_requests.php).
     *   GET·POST /api/me/delete-request  정책 authenticated — 대상은 언제나 **세션의 본인**. POST 는 재인증 15분 창을 **핸들러가** 묻는다
     *                                   (정책이 authenticated 라 관문 [3-2] 가 묻지 않는다 — files_restore.php 와 같은 방식 · 같은 403 모양).
     *   GET  /api/admin/deletion-requests · POST /api/admin/deletion-requests/{num}  정책 role:Admin — 재인증 관문 [3-2] 가 자동으로 붙는다.
     *   ★ GET·POST /api/me/delete-request 와 POST /api/me/reauth 는 동의 관문 열린 목록(Auth::CONSENT_GATE_ALLOW)에 있다 — 동의하지 않은 사람도
     *     동의 화면에서 비밀번호 재인증 → 탈퇴 요청을 한다(약관 제3조 · ARCH 4.11 「순서」「탈퇴」 · P2-R1 · P2-R2). 재인증은 권한을 주지 않으므로
     *     다른 창구는 그대로 막힌다. 패스키 재인증 창구와 관리자 처리 창구는 열지 않았다.
     *   ★ `TBOX_API_TOKEN_ROUTES` 에 넣지 않는다 — 사람이 세션으로 한다. */
    ['GET',  '/api/me/delete-request',            'me_delete_request.php',       Authz::POLICY_AUTHENTICATED],
    ['POST', '/api/me/delete-request',            'me_delete_request.php',       Authz::POLICY_AUTHENTICATED],
    ['GET',  '/api/admin/deletion-requests',       'admin_deletion_requests.php', 'role:Admin'],
    ['POST', '/api/admin/deletion-requests/{num}', 'admin_deletion_requests.php', 'role:Admin'],

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
    //   ★ `X-API-Token` 이 통하는 라우트는 아래 `TBOX_API_TOKEN_ROUTES` 의 **열하나뿐**이다
    //     (F-49 의 이 한 줄 + F-50 의 둘 + F-56 의 whoami + F-58 의 폴더·파일 이동 넷
    //      + F-59 의 삭제 둘 + Q:F-9 의 로그인 링크 하나) — 그 밖의 라우트는 그 헤더를 읽지도 않는다.
    //     삭제 둘과 로그인 링크는 그중에서도 `TBOX_API_TOKEN_PERSONAL_ROUTES` 라 **개인 토큰만** 통한다.
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
    // F-65 — 「실행 파일이 든 압축본」 받기 (PRD F-65 ③, ARCH 5.29 「받기」 표, API.md 9-1).
    //   정책은 다운로드와 **같은** file.read 이고, 그 파일을 받을 수 있는지는 핸들러가
    //   decideRead 로 다시 판단한 뒤 **요청마다** 비밀번호·2단계 코드를 확인한다.
    //   ★ `TBOX_API_TOKEN_ROUTES` 에 넣지 않는다 — 프로그램은 2단계 코드를 칠 수 없다.
    //     그래서 X-API-Token 헤더는 이 창구에서 읽히지도 않고, 토큰만 있는 요청은 401 이다.
    ['POST',   '/api/files/{id}/exec-download', 'files_exec_download.php', 'file.read'],
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

    // F-59 → **F-66** — 계정별 개인 프로그램 토큰의 **관리자 창구** (PRD F-59 ② · F-66 ①,
    //   8장 `/api/users/{uid}/api-tokens` 두 행, ARCH 4.6 · 4.7 「관리자 창구」, API.md 13장,
    //   SECURITY R-91). 정책은 계정 관리와 같은 `user.manage` 이고, 그래서 재인증 관문 [3-2]
    //   를 자동으로 지난다.
    //   ★ 남은 것은 **목록과 회수**다. 발급 행(`POST`)은 F-66 으로 **지웠다**(2026-09-22) —
    //     관리자가 남을 대신해 자격을 만드는 길이 사라지는 것이 R-91 의 핵심 완화다.
    //     발급은 본인 창구 `POST /api/me/api-tokens` 하나뿐이다. 옛 주소로 POST 하면, 같은
    //     경로의 목록(GET)이 남아 있어 라우터 [1] 의 「경로는 맞고 메서드만 다르다」 갈래가
    //     먼저 잡아 **405** 다(인증·인가에는 닿지 않는다 — 실측 2026-09-22).
    //   ★ 이 둘은 **세션 창구**다. `TBOX_API_TOKEN_ROUTES` 에 들어가지 않으므로 토큰으로는
    //     부를 수 없다(토큰이 토큰을 낳지 않는다).
    //   `$params[0]` = 대상 계정 id · `$params[1]` = 토큰 id.
    ['GET',    '/api/users/{num}/api-tokens',         'api_tokens_list.php',   'user.manage'],
    ['DELETE', '/api/users/{num}/api-tokens/{num}',   'api_tokens_revoke.php', 'user.manage'],

    // 본인 계정 관리 — 로그인한 사람이면 누구나 자기 것만 (PRD 7.7 "비밀번호 변경", R-05).
    // 대상은 언제나 **세션의 본인**이며 요청 본문에 대상 계정을 받지 않는다.
    ['POST',   '/api/me/password',         'password_change.php', Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/mfa/enroll',       'mfa_enroll.php',      Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/mfa/confirm',      'mfa_confirm.php',     Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/mfa/disable',      'mfa_disable.php',     Authz::POLICY_AUTHENTICATED],
    // 커뮤니티 7-D3 — 내 화면 취향 「고대비 화면」 (지시서 2장 H-2 · DESIGN 2.16). 위 넷과 같은 본인 창구다 —
    //   정책 authenticated · 상태를 바꾸므로 [3] CSRF · 대상은 언제나 **세션의 본인**(본문에 대상 계정 칸이 없다).
    //   본문은 `{high_contrast: bool}` 한 칸뿐(다른 키·문자열 "true"·빈 본문 400) · 감사 `me.settings|allow`.
    //   ★ `TBOX_API_TOKEN_ROUTES` 에 넣지 않는다 — 화면 취향은 사람의 세션으로만 바꾼다(토큰으로 부르면 세션이 없어 401).
    ['PATCH',  '/api/me/settings',         'me_settings.php',     Authz::POLICY_AUTHENTICATED],

    // F-66 — 프로그램 토큰 **본인 창구 셋** (PRD F-66 ① · 8장 `/api/me/api-tokens` 세 행,
    //   ARCH 4.7 「본인 창구 셋」, API.md 13장, SECURITY R-97). 정책은 위 넷과 같은
    //   `authenticated` — 로그인한 사람이면 누구나, 대상은 언제나 **세션의 본인**이다.
    //   발급(`POST`)만 요청마다 비밀번호 + 2단계 코드를 확인한다(핸들러 안에서, 세션에
    //   「확인됨」을 남기지 않는다). 회수는 묻지 않는다 — 닫는 방향은 자격을 줄이는 일이다.
    //   ★ **토큰 집합 밖**이다 — `TBOX_API_TOKEN_ROUTES` 에 넣지 않는다. 토큰이 토큰을
    //     낳지 않는다(개인 토큰으로 이 창구를 부르면 401 「헤더가 없습니다」 가 아니라
    //     세션이 없어 401 로그인 필요다 — 어느 쪽이든 발급되지 않는다).
    //   `$params[0]` = 토큰 id (`{num}`).
    ['GET',    '/api/me/api-tokens',         'me_tokens_list.php',   Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/api-tokens',         'me_tokens_issue.php',  Authz::POLICY_AUTHENTICATED],
    ['DELETE', '/api/me/api-tokens/{num}',   'me_tokens_revoke.php', Authz::POLICY_AUTHENTICATED],

    /* Q:F-9 — 등록 PC 에서 토큰으로 바로 로그인 (t-box F-75 · 사이트 Q:F-9, ARCH 4.9, API.md 20장, SECURITY R-113).
     *   POST /api/me/session-link  정책 `session.open` — 「이 PC 로그인 전용」 토큰(범위 session.open)만 여는 창구 하나.
     *                              토큰 **전용**(아래 세 집합 전부 — ROUTES · ONLY_ROUTES · PERSONAL_ROUTES): 헤더가 없으면
     *                              세션 쿠키가 있어도 401 · 공용(.env) 토큰 403. 범위 관문 [2-S] 가 다른 범위 넷을 403 으로 끝낸다.
     *                              핸들러가 지금 IP ≠ 묶인 IP 면 403 ip_mismatch, 맞으면 30초·1회용 링크 한 줄(코드 sha256 만 저장).
     *   GET  /session/open         정책 **public** — 링크를 여는 화면 주소(브라우저가 연다). 자격은 코드 자체가 대신한다(공유 링크
     *                              `/s/{token}` 과 같은 자리). 로그인·CSRF·강제 절차 관문을 지나지 않는다 — 부작용은 「내 세션 만들기」
     *                              뿐이고 GET 이라 CSRF 대상이 아니다. 판정은 Auth::openSessionFromLink 한 곳, 끝은 언제나 고정 주소 302.
     *                              여기서 만든 세션은 `sessions.origin='token'` — 유휴 10분 · 민감 동작 재인증(아래 [3-2]). */
    ['POST',   '/api/me/session-link',       'me_session_link.php',   'session.open'],
    ['GET',    '/session/open',              'session_open_page.php', Authz::POLICY_PUBLIC],

    // 휴지통 (PRD 5.4 "삭제된 파일 복구(30일 내)" 각주 ¹, F-11).
    // `/api/files/trash` 는 `{id}`(UUID 36자)와 형태가 달라 `/api/files/{id}` 계열과 충돌하지 않지만,
    // 의도를 드러내려고 위에 둔다(7단계 `/api/files/fetch` 와 같은 이유).
    // Q:F-4 U-2b (2026-09-24) — 정책 `role:Admin` → `authenticated`. 올린 사람이 **자기가 지운 자기 파일**을
    //   되살린다. 라우터는 「로그인했나」까지만 보고, 누구의 무엇을 되살릴 수 있나는 핸들러가
    //   `Authz::restorableFilesWhere`(목록 SQL WHERE)·`Authz::decideRestore`(한 건)로 판정한다 —
    //   file.read 가 목록은 SQL WHERE, 다운로드는 decideRead 로 거르는 것과 같은 구조다 (PRD S-02).
    //   ★ 재인증: 정책이 REAUTH_POLICIES 밖이 되어 관문 [3-2] 를 타지 않는다. Admin 의 **전체** 휴지통은
    //     핸들러가 범위 코드 `admin_all` 을 보고 관문과 같은 모양(403 next:reauth · 화면 302)으로 묻는다.
    //     본인 휴지통은 묻지 않는다(본인 자료 — 커뮤니티 휴지통과 같다).
    //   토큰 창구 집합(TBOX_API_TOKEN_ROUTES)에는 넣지 않는다 — 세션으로만 부른다.
    ['GET',    '/api/files/trash',          'files_trash.php',   Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/files/{id}/restore',   'files_restore.php', Authz::POLICY_AUTHENTICATED],

    // 개발 대기열 6 · Q:F-3a 라운드 A — 즐겨찾기 · 최근 (지시서 ../docs/qf3a-favorites-recent-bulk-directive.md 1.1 · 1.2, ARCH 5.33).
    //   정책은 목록·다운로드와 **같은 file.read** 다 — 라우터는 「읽는 종류의 행동인가」까지만 보고, 그 파일·폴더를 읽을 수
    //   있는지는 핸들러가 행을 읽은 뒤 `Authz::decideRead`(넣기)·`visibleFilesWhere`/`visibleWhere(…,'d')`(목록)로 다시 판단한다.
    //   새 정책 이름·새 판정 코드 0. PUT·DELETE 는 [3] CSRF 를 지난다. 대상은 언제나 세션의 본인이다(요청에서 사람을 받지 않는다).
    //   ★ 토큰 창구 집합(TBOX_API_TOKEN_ROUTES)에 넣지 않는다 — 화면 편의 창구라 세션으로만 부른다.
    //   `/api/files/favorites`·`/api/files/recent` 는 `{id}`(UUID 36자)와, `/api/folders/favorites` 는 `{num}`(숫자)과
    //   모양이 달라 겹치지 않는다 — 뜻을 드러내려고 한곳에 모아 둔다.
    ['GET',    '/api/files/favorites',          'favorites_files.php',   'file.read'],
    ['PUT',    '/api/files/{id}/favorite',      'favorites_files.php',   'file.read'],
    ['DELETE', '/api/files/{id}/favorite',      'favorites_files.php',   'file.read'],
    ['GET',    '/api/files/recent',             'files_recent.php',      'file.read'],
    ['GET',    '/api/folders/favorites',        'favorites_folders.php', 'file.read'],
    ['PUT',    '/api/folders/{num}/favorite',   'favorites_folders.php', 'file.read'],
    ['DELETE', '/api/folders/{num}/favorite',   'favorites_folders.php', 'file.read'],
    // Q:F-3a 라운드 C — 고른 파일 여럿을 zip 하나로 받기 (지시서 1.4 · 결정 M-5, ARCH 5.34). 정책은 한 건 받기와 **같은 file.read**,
    //   POST 라 [3] CSRF 를 지난다. 파일마다 한 건 받기와 같은 함수(`_download_common.php` — decideRead · F-65 · F-54 · 무결성)로
    //   거르고 못 받는 파일은 **빼고 개수만** 알린다(새 판정 0). 토큰 창구 집합에 넣지 않는다 — 화면 전용(M-5).
    ['POST',   '/api/files/zip',                'files_zip.php',         'file.read'],

    // 12단계 — 기능별 화면 분리 (PRD F-20, 8장 「화면(HTML)」 행, 10장 화면 요구사항).
    //   한 페이지에 쌓여 있던 기능을 화면 단위로 나눈 것이며 **권한 규칙은 그대로**다:
    //   휴지통 화면은 데이터(`GET /api/files/trash`)와 **같은 정책**을 쓰고,
    //   「내 계정」 화면은 본인 계정 API 와 같은 authenticated 를 쓴다.
    //   화면만 열어 두고 API 를 막지 않거나 그 반대면 그것이 곧 우회 경로다 (PRD S-01).
    //   Q:F-4 U-2b — 데이터 창구를 따라 `authenticated` 로 바뀌었다. Admin 에게는 「휴지통」(전체,
    //   재인증 302 는 핸들러가), 그 밖에는 「내 휴지통」(본인이 올리고 본인이 지운 파일)이다.
    ['GET',    '/trash',                    'trash_page.php',    Authz::POLICY_AUTHENTICATED],
    ['GET',    '/settings',                 'settings_page.php', Authz::POLICY_AUTHENTICATED],
    ['GET', '/programs', 'programs_page.php', 'file.read'],
    ['GET', '/api/programs', 'programs.php', 'file.read'],
    ['POST', '/api/programs', 'programs.php', 'file.upload'],
    ['POST', '/api/programs/{id}/download', 'programs.php', 'file.read'],

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
    //   2026-09-23 — 책 셋(`qaredi`·`tbox`·`community`)으로 나눴다: `/wiki` = 책 고르기,
    //   `/wiki/{book}` = 그 책 차례, `/wiki/{book}/{slug}` = 글. 넷 다 **같은 정책** role:Admin 이다.
    //   ★ 순서가 뜻을 갖는다: 매칭은 위에서부터 첫 줄이 이긴다. `{book}` 은 `{slug}` 정규식에도
    //     맞으므로 `/wiki/{book}` 줄이 **옛 주소 줄보다 위**에 있어야 `/wiki/tbox` 가 차례로 간다.
    //   옛 주소 `/wiki/{slug}`(책이 나뉘기 전) 는 t-box 책에 그 글이 있으면 `/wiki/tbox/{slug}` 로 308,
    //   없으면 404 다 (wiki_legacy.php).
    ['GET',    '/wiki',                          'wiki_books.php',       'role:Admin'],
    ['GET',    '/wiki/{book}',                   'wiki_index.php',       'role:Admin'],
    ['GET',    '/wiki/{book}/{slug}',            'wiki_page.php',        'role:Admin'],
    ['GET',    '/wiki/{slug}',                   'wiki_legacy.php',      'role:Admin'],

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

    /* T:F-72 — 휴대폰 지문·얼굴로 로그인(패스키, WebAuthn) — 지시서 1.8 · ARCH 5.35 · API.md 16장(웹 화면 전용 — 토큰 창구 아님).
     *   로그인 둘은 public(비밀번호 로그인과 같다 — JSON 만 · nginx `tbox_login` 속도 제한 · 로그인 전 쿠키에 묶인 일회용 챌린지).
     *   나머지는 authenticated — 대상은 언제나 세션의 본인이고, 상태를 바꾸는 넷은 [3] CSRF 를 지난다. 강제 절차 허용 목록 **불변**
     *   (절차 중에는 [3-1] 이 등록·재인증 창구를 막는다) · `TBOX_API_TOKEN_ROUTES` **불변**(토큰으로는 못 부른다).
     *   재인증 둘은 라운드 C — 성공이면 비밀번호 재인증과 같은 15분 창(지금 세션만). **권한을 만들지 않는다**(관문 [3-2] 는 인가 뒤).
     *   `{num}` = 열쇠 행 번호(passkeys.id) — 내 것만, 남의 번호는 없는 번호와 같은 404. */
    ['POST',   '/api/login/passkey/options',     'passkey_login_options.php',  Authz::POLICY_PUBLIC],
    ['POST',   '/api/login/passkey',             'passkey_login.php',          Authz::POLICY_PUBLIC],
    ['GET',    '/api/me/passkeys',               'me_passkeys_list.php',       Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/passkeys/options',       'me_passkeys_options.php',    Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/passkeys',               'me_passkeys_register.php',   Authz::POLICY_AUTHENTICATED],
    ['DELETE', '/api/me/passkeys/{num}',         'me_passkeys_revoke.php',     Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/reauth/passkey/options', 'reauth_passkey_options.php', Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/reauth/passkey',         'reauth_passkey.php',         Authz::POLICY_AUTHENTICATED],
    /* T:F-72 라운드 C · 결정 PK-D5 — 홈 화면 「Qaredi」 앱 매니페스트(모든 사람 · 데이터 0 — 고정 글자뿐). 로그인 화면·런처에도
       붙으므로 public 이다(쿠키 없이 받는다). 시작 `/community/` · 범위 `/` · 서비스 워커 없음. */
    ['GET',    '/app.webmanifest',               'site_manifest.php',          Authz::POLICY_PUBLIC],

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
     * F-70 — 휴대폰 경보 (설치형 웹 앱 + 웹 푸시, PRD F-70 · 지시서 docs/f70-push-alerts-directive.md D 표,
     *   구현 기록 docs/f70-push-alerts-implementation.md, ARCH 5.32, API.md 15장, SECURITY R-99).
     *
     * 일곱 줄 전부 정책 `role:Admin` 이다 — 받는 사람은 Admin 만(F-70). `role:Admin` 은 `Authz::REAUTH_POLICIES` 에 있어
     * **재인증 관문 [3-2] 가 자동으로 따라붙는다**(등록·해지뿐 아니라 시험·목록·화면까지 — 새 정책 이름을 만들지 않는
     * 이유는 관리자 영역과 같다, R-04). 상태를 바꾸는 셋(POST 둘 · DELETE)은 [3] CSRF 를 지난다.
     * ★ **웹은 밖으로 한 번도 나가지 않는다**(원칙 1) — 이 핸들러들에 `Fetch::` 보내기 호출이 없고, 「시험 알림」도
     *   대기표 `alert_push_outbox` 에 한 행만 넣는다. 보내는 것은 cron 배치 scripts/alert_push.php(OS 계정 tbox-push) 하나다.
     * ★ 서비스 워커·매니페스트도 PHP 라우트다(구현 기록 1) — 주소가 `/tbox/…` 바로 아래라 등록 범위 `/tbox/alerts-app` 가
     *   Service-Worker-Allowed 없이 되고 nginx 설정을 바꾸지 않는다. 보안 머리는 다른 PHP 응답과 같다(bootstrap).
     *   원본 `src/push/alerts-sw.js` 는 public/ 밖이라 /tbox/assets/ 로는 나가지 않는다.
     * `{num}` 은 받기 행 번호(alert_push_subscriptions.id) — 자기 것만, 남의 번호는 없는 번호와 같은 404.
     */
    ['GET',    '/alerts-app',                    'push_app_page.php',            'role:Admin'],
    ['GET',    '/alerts-sw.js',                  'push_sw.php',                  'role:Admin'],
    ['GET',    '/alerts-app.webmanifest',        'push_manifest.php',            'role:Admin'],
    ['GET',    '/api/admin/push/key',            'push_key.php',                 'role:Admin'],
    ['GET',    '/api/admin/push/subscriptions',  'push_subscriptions.php',       'role:Admin'],
    ['POST',   '/api/admin/push/subscriptions',  'push_subscriptions.php',       'role:Admin'],
    ['DELETE', '/api/admin/push/subscriptions/{num}', 'push_subscription_delete.php', 'role:Admin'],
    ['POST',   '/api/admin/push/test',           'push_test.php',                'role:Admin'],

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

    // G-0 — 「개인」 등급의 사람 지정 권한 · 권한 요청 (t-box PRD F-73, ARCH 5.36).
    //   새 정책 이름 0 — 라우터는 「그 종류의 행동인가」까지만 보고, 그 항목에 대한 판단은 핸들러가 행을 읽은 뒤 Authz 로 한다:
    //     file.read   : 권한 줄 보기 · 받은 목록 → decideRead / canSeeInList
    //     file.update : 권한 주기·빼기 · 사람 후보 · 요청 결정 → decideGrantManage(주인 · Admin 특권은 핸들러가 15분 재인증) ·
    //                   요청의 호스트(주인 · 주인이 꺼지면 Admin). 손님은 여기서 끝난다(무엇의 주인도 될 수 없다)
    //     authenticated : 요청 보내기·목록·거두기 — 손님도 **보기** 권한은 요청할 수 있다(편집 요청은 핸들러가 400)
    //   POST·DELETE 는 [3] CSRF 를 지난다. ★ 토큰 창구 집합(TBOX_API_TOKEN_ROUTES)에 넣지 않는다 — 세션으로만 부른다
    //   (사람에게 권한을 주는 결정은 프로그램 토큰으로 하지 않는다). `{num}`(둘째) = 사람 번호(users.id) · 요청 번호.
    ['GET',    '/api/folders/{num}/grants',          'access_grants.php',   'file.read'],
    ['POST',   '/api/folders/{num}/grants',          'access_grants.php',   'file.update'],
    ['DELETE', '/api/folders/{num}/grants/{num}',    'access_grants.php',   'file.update'],
    ['GET',    '/api/files/{id}/grants',             'access_grants.php',   'file.read'],
    ['POST',   '/api/files/{id}/grants',             'access_grants.php',   'file.update'],
    ['DELETE', '/api/files/{id}/grants/{num}',       'access_grants.php',   'file.update'],
    ['GET',    '/api/access/people',                 'access_people.php',   'file.update'],
    ['GET',    '/api/grants/mine',                   'access_mine.php',     'file.read'],
    ['POST',   '/api/access-requests',               'access_requests.php', Authz::POLICY_AUTHENTICATED],
    ['GET',    '/api/access-requests',               'access_requests.php', Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/access-requests/{num}',         'access_requests.php', 'file.update'],
    ['DELETE', '/api/access-requests/{num}',         'access_requests.php', Authz::POLICY_AUTHENTICATED],
];

/**
 * 재인증 관문이 화면 요청을 돌려보낼 때 쓰는 **고정 표** (16-1, PRD F-23, ARCH 4.0).
 *
 * 왼쪽은 라우트 패턴(= TBOX_ROUTE 와 같은 표기), 오른쪽은 `/reauth?to=` 에 붙일 키다.
 * `GET /trash` 는 Q:F-4 U-2b 부터 관문 [3-2] 가 아니라 trash_page.php 가 이 표를 읽는다(Admin 전체 범위일 때만).
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
    // 2026-09-23 — 위키 책 차례와 글. 화면이 늘어도 키는 `wiki` 하나이고, 확인이 끝나면
    // **책 고르기**(`/wiki`)로 돌아온다 (키를 늘리지 않는 이유는 `admin` 과 같다).
    'GET /wiki/{book}'        => 'wiki',
    'GET /wiki/{book}/{slug}' => 'wiki',
    // 17-1 — 관리자 영역 (PRD F-24, ARCH 5.14). 허브와 매트릭스 편집 화면 둘 다 키는 `admin`
    // 하나이고, 확인이 끝나면 **허브**(`/admin`)로 돌아온다. 화면이 하나 더 늘어도 키를 늘리지
    // 않는 이유는 오픈 리다이렉트 표면을 키 목록만큼으로 묶어 두기 위해서다 — 돌아갈 경로는
    // 아래 TBOX_REAUTH_PATHS 에 적힌 고정 문자열뿐이고, 요청 값은 키를 고르는 데만 쓰인다.
    'GET /admin'              => 'admin',
    'GET /admin/permissions'  => 'admin',
    // F-43 — 허용 사이트 화면도 키는 `admin` 하나다(화면이 늘어도 키를 늘리지 않는다).
    'GET /admin/fetch-hosts'  => 'admin',
    // F-70 — 휴대폰 경보 화면. 설치한 앱(홈 화면)의 시작 화면이라 확인 뒤 **허브가 아니라 이 화면**으로 돌아와야 한다 —
    // 그래서 키를 하나 더한다(`alerts`). 돌아갈 경로는 아래 고정 표의 한 줄이고, app.js REAUTH_PATHS 에도 같은 줄이 있다.
    'GET /alerts-app'         => 'alerts',
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
    // F-70 — 휴대폰 경보 화면 (`/reauth?to=alerts`).
    'alerts' => '/alerts-app',
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
    'GET /welcome',
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
    // 2026-09-23 — 위키 책 이름. **고정 목록 셋**뿐이고 `Wiki::BOOKS` 의 키와 같은 글자여야 한다
    // (늘리려면 둘을 함께 — 한쪽만 늘면 새 책은 404 로 남는다, 닫히는 쪽으로 어긋난다).
    // 핸들러가 `Wiki::bookKey()` 로 목록 쪽 문자열로 한 번 더 바꿔 쓴다(Wiki.php 머리의 ③).
    '{book}' => '(?:qaredi|tbox|community)',
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
 * F-50 에서 셋으로, F-56 에서 넷으로, F-58 에서 여덟으로, F-59 에서 열로, Q:F-9 에서 **열하나로**.
 * 넓히려면 여기 + R-82/R-83/R-89/R-90/R-91/R-113 재검토.
 */
const TBOX_ASSISTANT_API_ROUTES = [
    'GET /api/assistant/chat', 'POST /api/assistant/chat',
    'POST /api/assistant/runs', 'GET /api/assistant/runs',
    'GET /api/assistant/runs/{id}', 'GET /api/assistant/runs/{id}/events',
    'POST /api/assistant/runs/{id}/cancel', 'POST /api/assistant/runs/{id}/confirm',
];

const TBOX_API_TOKEN_ROUTES = [
    ...TBOX_ASSISTANT_API_ROUTES,
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
    // Q:F-9 — 1회용 로그인 링크 (ARCH 4.9, SECURITY R-113). 아래 두 집합(PERSONAL · ONLY)이기도 하다 — 열하나째.
    'POST /api/me/session-link',
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
    ...TBOX_ASSISTANT_API_ROUTES,
    'DELETE /api/files/{id}',
    'DELETE /api/folders/{num}',
    // Q:F-9 — 로그인 링크는 사람 단위 토큰(범위 session.open)으로만. 공용 토큰은 403 shared_token_not_permitted.
    'POST /api/me/session-link',
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
    ...TBOX_ASSISTANT_API_ROUTES,
    'POST /api/upload',
    'GET /api/whoami',
    // Q:F-9 — 화면이 쓰지 않는 프로그램(비서) 전용 창구. 헤더가 없으면 세션 쿠키가 있어도 401 「헤더가 없습니다」.
    'POST /api/me/session-link',
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

/**
 * ─── [1-0] 사이트 층 — 런처 · `/tbox` 접두 · 옛 주소 308 (Qaredi C, ARCH-launcher 2장) ───
 *
 * 관문([2]~[4]) **앞**에 한 층만 둔다. 아래 라우트 표(TBOX_ROUTES)의 경로·정책·핸들러는
 * 한 글자도 바뀌지 않았다 — 이 층이 접두를 떼어 주고, 그 뒤는 전과 똑같은 코드다.
 *
 *   `/`            → Qaredi 런처 (GET·HEAD, 그 밖 405). 세션을 읽기만 하고 만들지 않는다.
 *   `/tbox…`       → 접두를 떼고 **모듈 경로**로 기존 매칭. 아래 `$reqPath` 는 이때부터
 *                    모듈 경로이며, `'/api/'` 접두로 갈리는 판단(401 JSON vs 302 로그인)도
 *                    모듈 경로 기준이다. 감사 로그의 `route` 는 원래대로 라우트 **패턴**이다.
 *   옛 t-box 경로  → 308 `Location: /tbox<REQUEST_URI 원문>` (메서드·본문 보존)
 *   그 밖          → 404 (기존 판정 그대로)
 *
 * ★ 리다이렉트 대상은 **고정 접두 + 요청 경로·쿼리 원문**뿐이다. 요청 헤더(Host·Referer)나
 *   쿼리 값으로 호스트·스킴을 만들지 않으므로 열린 리다이렉트가 생길 자리가 없다.
 *   `REQUEST_URI` 는 `/` 로 시작해야 하고(`//` 로 시작하면 스킴 상대 주소가 된다) 길이
 *   상한 2048 을 넘으면 리다이렉트하지 않고 404 다 (ARCH-launcher 1장).
 */
$reqMethod = Http::method();
$reqPath   = Http::path();

// Remote MCP is a separate, explicit Bearer/OAuth/session boundary, before legacy routing.
if ($reqPath === '/mcp' || strpos($reqPath, '/.well-known/oauth-') === 0
    || strpos($reqPath, '/tbox/oauth/') === 0 || $reqPath === '/tbox/mcp-connect'
    || $reqPath === '/tbox/api/mcp/connections' || strpos($reqPath, '/tbox/api/mcp/connections/') === 0) {
    require __DIR__ . '/../src/mcp/dispatch.php';
    mcp_dispatch($reqPath, $reqMethod);
}

/** 접두 아래 요청이면 그 접두를 뗀 모듈 경로, 아니면 null */
$modulePath = null;
if ($reqPath === TBOX_BASE || strpos($reqPath, TBOX_BASE . '/') === 0) {
    $modulePath = substr($reqPath, strlen(TBOX_BASE));
    if ($modulePath === '' || $modulePath === false) {
        $modulePath = '/';
    }
}

if ($modulePath === null) {
    if ($reqPath === '/') {
        // 런처 — public 이고 세션을 **읽기만** 한다 (ARCH-launcher 5장).
        if ($reqMethod !== 'GET' && $reqMethod !== 'HEAD') {
            if (!headers_sent()) {
                header('Allow: GET, HEAD');
            }
            Http::error(405, '허용되지 않는 메서드입니다');
        }
        require __DIR__ . '/../src/site/launcher_page.php';
        exit;
    }

    /* ─── 커뮤니티 모듈 (커뮤니티 ARCHITECTURE.md 21.1) ─────────────────────────
     *
     * `/community…` 는 **자기 라우트 표**(`src/community/routes.php` 의 COMMUNITY_ROUTES)를
     * 돈다. 위 t-box 갈래와 아래 308·404 판정은 한 글자도 바뀌지 않았다 — 갈래가 하나 늘었을
     * 뿐이다. 접두를 떼는 방식도 t-box 와 같고, 뗀 뒤의 경로만 그쪽 표와 견준다.
     *
     * `community_dispatch()` 는 돌아오지 않는다(언제나 응답을 내보내고 끝난다).
     */
    if ($reqPath === COMMUNITY_BASE || strpos($reqPath, COMMUNITY_BASE . '/') === 0) {
        $communityPath = substr($reqPath, strlen(COMMUNITY_BASE));
        if ($communityPath === '' || $communityPath === false) {
            $communityPath = '/';
        }
        require __DIR__ . '/../src/community/routes.php';
        community_dispatch($communityPath, $reqMethod);
    }

    // 옛 t-box 경로인가 — 라우트 표의 경로 패턴과만 견준다(메서드는 보지 않는다).
    $wasTboxPath = false;
    foreach (TBOX_ROUTES as $route) {
        if (preg_match(tbox_pattern_to_regex($route[1]), $reqPath) === 1) {
            $wasTboxPath = true;
            break;
        }
    }

    $reqUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($wasTboxPath
        && $reqUri !== '' && $reqUri[0] === '/' && strncmp($reqUri, '//', 2) !== 0
        && strlen($reqUri) <= 2048
    ) {
        Http::redirectPermanent(TBOX_BASE . $reqUri);
    }

    // 디버깅 묶음 U3 — 없는 주소: 브라우저 화면 요청이면 「찾을 수 없는 페이지」 한 장, 그 밖은 전과 같은 404 JSON(세션을 읽지 않는다)
    require_once __DIR__ . '/../src/site/not_found_page.php';
    qaredi_not_found(strpos($reqPath, '/api/') === 0);
}

// 이 줄부터 `$reqPath` 는 **모듈 경로**다 (접두를 뗀 뒤의 경로).
$reqPath = $modulePath;

// ─── [1] 라우트 매칭 ───────────────────────────────────────────
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
    // 디버깅 묶음 U3 — 위와 같은 갈래(인증 [2] 보다 앞이라 로그인 전·후가 같은 응답이다)
    require_once __DIR__ . '/../src/site/not_found_page.php';
    qaredi_not_found(strpos($reqPath, '/api/') === 0);
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

        /* ─── [2-S] F-66 범위 관문 (PRD F-66 ③, ARCH 4.7 「창구 → 필요한 범위」 표) ───
         *
         * 자리가 여기인 이유: 범위는 「이 **토큰**이 무엇을 할 수 있나」이고, 바로 아래 [4]
         * 의 정책 인가는 「이 **계정**이 무엇을 할 수 있나」다. 좁은 쪽을 먼저 본다 —
         * 범위 밖이면 계정 권한을 볼 필요가 없고, 감사에도 「범위 때문에 막혔다」가 남는다.
         *
         * 판단은 `Authz::tokenScopeAllows` 한 곳이고 인자는 **범위와 라우트 정책** 둘뿐이다
         * (요청 값도 역할 문자열도 보지 않는다). 창구 이름을 여기에 적지 않으므로 토큰
         * 집합이 넓어져도 이 가지는 그대로다 — 모르는 정책은 닫힌 실패다.
         *
         * 범위는 창구를 좁힐 뿐이다: 통과해도 파일 한 건의 판정(decideRead·decideUploadAs·
         * decideDelete…)은 핸들러가 요청마다 그대로 한다 — 토큰이 계정보다 커질 수 없다.
         */
        if (!Authz::tokenScopeAllows(TBOX_API_TOKEN_SCOPE, $policy)) {
            Audit::deny('auth.token', 'scope_not_permitted', [
                'target_type' => 'system',
                // 실제 경로가 아니라 라우트 패턴이다 (ARCH 7.5 route, Auth::denyApiToken 과 같은 모양).
                'target_name' => TBOX_ROUTE,
                // 값은 코드가 정한 분류어와 행 번호뿐이다 — 토큰 원문은 어디에도 없다.
                'detail'      => ['token_scope' => TBOX_API_TOKEN_SCOPE]
                    + (defined('TBOX_API_TOKEN_ID') ? ['token_id' => constant('TBOX_API_TOKEN_ID')] : []),
            ]);
            // 권한이 모자란 것이 아니라 **범위**가 좁다 — 부르는 쪽(우리 프로그램)이
            // 고칠 수 있는 말을 준다 (ARCH 4.2 「오류 문구 정책」).
            Http::error(403, Http::MSG_TOKEN_SCOPE);
        }
    } else {
    // 미로그인 처리 (ARCH 4.0): API 는 401 JSON + Audit::deny, 화면 경로는 302 /login.
    // 화면 경로에 감사 로그를 남기지 않는 이유 — 세션이 만료된 뒤의 첫 방문은 공격이 아니고,
    // 그런 요청까지 남기면 실제 공격 흔적이 묻힌다 (PRD S-07 취지).
    if (Auth::currentUser() === null && strpos($reqPath, '/api/') !== 0) {
        /* Q:F-7 B1-1 — 보던 화면이 허용 목록(Http::returnPath) 안이면 꼬리표로 싣는다. 요청 주소 원문은 **그 함수만** 지나고,
           Location 에는 그 정규형을 rawurlencode 한 글자만 들어간다(Http::redirect 가 한 번 더 확인). 자료실 첫 화면도 목적지로 보존한다. */
        $tboxReturn = Http::returnPath((string) ($_SERVER['REQUEST_URI'] ?? ''));
        Http::redirect(Http::url(TBOX_LOGIN_PATH)
            . ($tboxReturn !== null ? '?to=' . rawurlencode($tboxReturn) : ''));
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
                Http::redirect(Http::url(TBOX_HOME_PATH));   // 접두는 내보낼 때 (ARCH-launcher 3장)
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
    /* Q:F-9 (ARCH 4.9) — 세션의 **출신**(`sessions.origin` — Auth::currentUser 가 실은 값)과 라우트 패턴을 함께 넘긴다.
       일반 로그인 세션(`login`)이면 Authz 는 REAUTH_POLICIES 하나만 본다(전과 같은 답). 토큰 출신 세션(`token`)이면
       넓은 집합(TOKEN_SESSION_REAUTH_POLICIES ∪ TOKEN_SESSION_REAUTH_ROUTES)을 본다. 막는 모양·감사는 아래 그대로다. */
    if (!$viaApiToken
        && Authz::requiresReauth($policy, (string) ($user['session_origin'] ?? 'login'), TBOX_ROUTE)
        && ($user['reauth_fresh'] ?? false) !== true
    ) {
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
            Http::redirect(Http::url(
                $reauthKey === null ? TBOX_REAUTH_PATH : TBOX_REAUTH_PATH . '?to=' . $reauthKey
            ));
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
