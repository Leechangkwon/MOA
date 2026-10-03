<?php
declare(strict_types=1);
require_once __DIR__ . '/../Design.php';

/**
 * src/community/routes.php — 커뮤니티 모듈의 라우트 표와 관문
 *
 * 요구사항: 커뮤니티 `C:S-01`, `../../../community/ARCHITECTURE.md` 13.3 · 21.1 · 21.2,
 *           `../../docs/launcher/ARCHITECTURE.md` 2장(라우터 한 층).
 *
 * `public/index.php` 의 사이트 층이 `/community…` 를 만나면 이 파일을 부르고, 여기가
 * **t-box 와 같은 순서**로 관문을 돈다. t-box 의 라우트 표(`TBOX_ROUTES`)·판정·감사 문장은
 * 한 글자도 바뀌지 않았다 — 바뀐 것은 「표가 하나 더 있다」뿐이다.
 *
 *   [0-1] 라우트 표 자체 검사   정책이 `Authz::KNOWN_POLICIES` 밖이면 어떤 요청도 처리하지 않는다
 *   [1]   경로 매칭            없으면 404, 경로는 맞고 메서드만 다르면 405
 *   [2-T] 토큰 갈래             표 셋의 라우트 + `X-API-Token` 헤더가 왔을 때만 — 개인 토큰 · 범위 `community.write` (Q:F-8)
 *   [2]   인증                 화면은 302 `/tbox/login?to=community`, `/api/*` 는 401 JSON
 *   [3]   CSRF                 상태를 바꾸는 요청의 토큰 검사 (t-box 와 **같은 토큰**)
 *   [3-1] 최초 로그인 강제 절차 절차가 남았으면 커뮤니티는 통째로 막는다(허용 목록 0)
 *   [4]   인가                 `Authz::authorize(policy, user)` — 실패 403 동일 문구 + 감사
 *   [4-1] 스키마 준비 확인      운영 DB 에 아직 표가 없으면 503 「커뮤니티 준비 중」
 *   [5]   핸들러 실행
 *
 * ★ 프로그램용 토큰(`X-API-Token`)은 커뮤니티에서 **창구 셋에만** 통한다(Q:F-8 · 2026-09-28 —
 *   `COMMUNITY_API_TOKEN_ROUTES` · ARCH 4.8 · SECURITY R-112). 그 헤더를 읽는 곳은 아래 [2-T] **한 곳**이고,
 *   거기 닿는 것은 표의 라우트 패턴과 맞은 요청뿐이다. 나머지 창구는 헤더를 읽지도 않는다 — 세션이
 *   없으면 401 이다(R-82·R-90·R-91 과 같은 원칙). 넓히는 일은 그 표를 고치는 별도의 판단이다.
 * ★ 재인증(`Authz::REAUTH_POLICIES`)은 커뮤니티 정책 둘에 걸리지 않는다. 그래도 관문을
 *   빼지 않고 t-box 와 같은 함수를 부른다 — 나중에 관리 성격의 커뮤니티 정책이 생기면
 *   목록에 이름을 넣는 것만으로 재인증이 따라붙는다.
 */

/*
 * 커뮤니티 클래스 셋. 오토로더를 쓰지 않는 것은 t-box `src/bootstrap.php` 와 같다 —
 * 클래스 이름이 파일 경로로 바뀌는 통로가 존재하지 않는다. 부트스트랩이 아니라 **여기서**
 * 부르는 이유는, t-box 요청 하나하나가 쓰지도 않는 파일을 읽지 않게 하려는 것이다.
 */
require_once __DIR__ . '/Doc.php';
require_once __DIR__ . '/Automation.php';
require_once __DIR__ . '/Suggestion.php';
require_once __DIR__ . '/SaveDoc.php';
require_once __DIR__ . '/suggestion_helpers.php';
require_once __DIR__ . '/DocRender.php';
require_once __DIR__ . '/DocMarkdown.php';
require_once __DIR__ . '/DocExport.php';
require_once __DIR__ . '/export_helpers.php';
require_once __DIR__ . '/CommunityAuthz.php';
require_once __DIR__ . '/DbProps.php';
require_once __DIR__ . '/DbViews.php';
require_once __DIR__ . '/forms_helpers.php';
require_once __DIR__ . '/views_helpers.php';
// 7-D2 — 관리자 팔레트의 순수 계산(배경값 · 버전 · CSS 글자). DB 는 아래 `community_palette_rows()` 가 읽는다
require_once __DIR__ . '/Palette.php';
require_once __DIR__ . '/BriefingText.php';
require_once __DIR__ . '/Briefing.php';
require_once __DIR__ . '/Integrations.php';
require_once __DIR__ . '/../Ai.php';
require_once __DIR__ . '/AiService.php';
require_once __DIR__ . '/AiIndex.php';
require_once __DIR__ . '/ai_helpers.php';
require_once __DIR__ . '/Memories.php';
require_once __DIR__ . '/Messenger.php';
// Q:F-2 — 검색 색인·검색어 토큰 한 벌(사이트 공통 — t-box 파일 색인도 나중에 같은 파일, 지시서 2.1)
require_once __DIR__ . '/../SearchText.php';

/**
 * 라우트 표 — [메서드, **모듈 경로** 패턴, 핸들러 파일명, 정책]
 *
 * 경로는 `/community` 를 **뗀 뒤**의 모듈 경로다(t-box 표가 `/tbox` 를 뗀 경로인 것과 같다).
 * 자리표시자는 `public/index.php` 의 `TBOX_PATH_PATTERNS` 를 그대로 쓴다 — `{id}` 는
 * UUID 36자만 받으므로 순차 정수를 주소로 받는 자리가 없다(열거 방지, t-box S-09-5).
 *
 * 4-B 범위: 화면 둘 · API 열(4-A 의 여덟 + `GET /api/spaces` + `PATCH /api/pages/{id}/visibility`).
 */
const COMMUNITY_ROUTES = [
    ['POST', '/api/pages/{id}/suggestions', 'suggestions_create.php', 'community.write'],
    ['GET', '/api/pages/{id}/suggestions', 'suggestions_list.php', 'community.read'],
    ['GET', '/api/suggestions/{id}', 'suggestion_get.php', 'community.read'],
    ['POST', '/api/suggestions/{id}/accept', 'suggestion_accept.php', 'community.write'],
    ['POST', '/api/suggestions/{id}/reject', 'suggestion_reject.php', 'community.write'],
    ['POST', '/api/suggestions/{id}/withdraw', 'suggestion_withdraw.php', 'community.write'],
    ['GET', '/api/me/suggestions', 'me_suggestions.php', 'community.read'],
    ['GET', '/suggestions', 'home_page.php', 'community.read'],
    ['GET', '/api/databases/{id}/chart', 'database_chart.php', 'community.read'],
    ['GET', '/f/{id}/{view}', 'form_view.php', 'community.read'],
    ['GET', '/api/forms/{id}/{view}', 'form_get.php', 'community.read'],
    ['POST', '/api/forms/{id}/{view}/submit', 'form_submit.php', 'community.read'],
    ['GET','/api/collections','collections.php','community.read'],
    ['POST','/api/collections','collections.php','community.read'],
    ['GET','/api/collections/{num}','collections.php','community.read'],
    ['PATCH','/api/collections/{num}','collections.php','community.read'],
    ['DELETE','/api/collections/{num}','collections.php','community.read'],
    ['PUT','/api/collections/{num}/items/{id}','collections.php','community.read'],
    ['DELETE','/api/collections/{num}/items/{id}','collections.php','community.read'],
    ['GET','/api/groups','groups.php','community.read'],
    ['POST','/api/groups','groups.php','community.write'],
    ['GET','/api/groups/{id}','groups.php','community.read'],
    ['PATCH','/api/groups/{id}','groups.php','community.write'],
    ['DELETE','/api/groups/{id}','groups.php','community.write'],
    ['PUT','/api/pages/{id}/group-shares/{gid}','pages_group_share.php','community.write'],
    ['DELETE','/api/pages/{id}/group-shares/{gid}','pages_group_share.php','community.write'],
    ['POST','/api/pages/{id}/web-publish','web_publish.php','community.write'],
    ['DELETE','/api/pages/{id}/web-publish/{num}','web_publish.php','community.write'],
    ['PATCH','/api/pages/{id}/web-publish/{num}','web_publish.php','role:Admin'],
    ['GET','/api/me/web-publications','web_publish.php','community.read'],
    ['GET','/api/admin/web-publications','web_publish.php','role:Admin'],
    ['PATCH','/api/spaces/{num}/web-publish','space_web_publish.php','role:Admin'],
    ['GET','/pub/{pubtoken}','pub_view.php','public'],
    ['GET','/pub/{pubtoken}/p/{id}','pub_view.php','public'],
    ['GET','/pub/{pubtoken}/form/{viewid}','pub_form.php','public'],
    ['POST','/pub/{pubtoken}/form/{viewid}/submit','pub_form_submit.php','public'],
    ['GET','/api/me/messenger','integration_settings.php','community.read'],
    ['PUT','/api/me/messenger','integration_settings.php','community.read'],
    ['GET','/api/admin/messenger-hooks','integration_settings.php','role:Admin'],
    ['POST','/api/admin/messenger-hooks','integration_settings.php','role:Admin'],
    ['PATCH','/api/admin/messenger-hooks/{num}','integration_settings.php','role:Admin'],
    ['GET','/api/admin/connectors','integration_settings.php','role:Admin'],
    ['POST','/api/admin/connectors','integration_settings.php','role:Admin'],
    ['PATCH','/api/admin/connectors/{id}','integration_settings.php','role:Admin'],
    ['GET','/integrations','integrations_page.php','community.read'],

    ['GET', '/api/messenger', 'messenger_api.php', 'community.write'],
    ['GET', '/api/messenger/members', 'messenger_api.php', 'community.write'],
    ['POST', '/api/messenger/conversations', 'messenger_api.php', 'community.write'],
    ['GET', '/api/messenger/gpt', 'messenger_api.php', 'community.write'],
    ['POST', '/api/messenger/gpt', 'messenger_api.php', 'community.write'],
    ['GET', '/api/messenger/conversations/{id}', 'messenger_api.php', 'community.write'],
    ['POST', '/api/messenger/conversations/{id}/messages', 'messenger_api.php', 'community.write'],
    ['POST', '/api/messenger/conversations/{id}/read', 'messenger_api.php', 'community.write'],
    // Community threads: session routes plus a narrow, read-only link capability.
    ['GET', '/memories', 'memories_page.php', 'community.read'],
    ['GET', '/memories/u/{username}', 'memories_page.php', 'community.read'],
    ['GET', '/memories/p/{id}', 'memories_page.php', 'community.read'],
    ['GET', '/memories/s/{token}', 'memories_shared.php', 'public'],
    ['GET', '/memories/s/{token}/feed', 'memories_shared.php', 'public'],
    ['GET', '/memories/s/{token}/media/{id}', 'memories_shared.php', 'public'],
    ['GET', '/api/memories', 'memories_api.php', 'community.read'],
    ['POST', '/api/memories', 'memories_api.php', 'community.write'],
    ['POST', '/api/memories/media', 'memories_api.php', 'community.write'],
    ['GET', '/api/memories/ai', 'memories_ai.php', 'community.write'],
    ['POST', '/api/memories/{id}/caption', 'memories_ai.php', 'community.write'],
    ['GET', '/api/memories/media/{id}', 'memories_api.php', 'community.read'],
    ['POST', '/api/memories/links', 'memories_api.php', 'community.write'],
    ['DELETE', '/api/memories/links', 'memories_api.php', 'community.write'],
    ['GET', '/api/memories/{id}', 'memories_api.php', 'community.read'],
    ['PATCH', '/api/memories/{id}', 'memories_api.php', 'community.write'],
    ['DELETE', '/api/memories/{id}', 'memories_api.php', 'community.write'],
    ['POST', '/api/memories/{id}/like', 'memories_api.php', 'community.write'],
    ['DELETE', '/api/memories/{id}/like', 'memories_api.php', 'community.write'],
    ['GET', '/api/memories/{id}/comments', 'memories_api.php', 'community.read'],
    ['POST', '/api/memories/{id}/comments', 'memories_api.php', 'community.write'],
    ['GET', '/today', 'today_page.php', 'community.read'],
    ['GET', '/api/briefing', 'briefing.php', 'community.read'],
    // ── 화면 ─────────────────────────────────────────────────
    ['GET',    '/',                          'memories_page.php',   'community.read'],
    ['GET', '/workspace', 'home_page.php', 'community.read'],
    ['GET',    '/p/{id}',                    'page_view.php',       'community.read'],
    ['GET', '/p/{id}/print', 'page_print.php', 'community.write'],
    ['GET', '/api/pages/{id}/export', 'pages_export.php', 'community.write'],

    ['GET', '/automations', 'home_page.php', 'community.read'],
    ['GET', '/api/automations', 'automations_list.php', 'community.read'],
    ['POST', '/api/automations', 'automations_save.php', 'community.write'],
    ['GET', '/api/automations/{id}', 'automations_get.php', 'community.read'],
    ['PATCH', '/api/automations/{id}', 'automations_save.php', 'community.write'],
    ['DELETE', '/api/automations/{id}', 'automations_delete.php', 'community.write'],
    ['POST', '/api/automations/{id}/dry-run', 'automations_dry_run.php', 'community.write'],
    ['POST', '/api/automations/{id}/run', 'automations_run.php', 'community.write'],
    ['GET', '/api/automations/{id}/runs', 'automations_runs.php', 'community.read'],
    ['POST', '/api/automation-runs/{id}/undo', 'automation_runs_undo.php', 'community.write'],
    ['GET', '/api/admin/automations', 'automations_admin.php', 'role:Admin'],
    ['POST', '/api/admin/automations/{id}/disable', 'automations_admin.php', 'role:Admin'],
    // ── API ──────────────────────────────────────────────────
    // 개인 공간 — 없으면 만든다(「시작하기」 페이지와 함께). ARCH 13.3 의 설계 그대로다.
    ['GET',    '/api/me/space',              'space_me.php',        'community.read'],
    ['GET',    '/api/me/draft-key',          'draft_key.php',       'community.read'],   // Q:F-4 B1-2 — 쓰던 글 임시저장 열쇠(본인 것만, 감사 없음)
    // 내가 볼 수 있는 공간들 — 내 개인 공간 + 내 팀 공간(없으면 그 자리에서 만든다, 4-B).
    ['GET',    '/api/spaces',                'spaces_list.php',     'community.read'],
    // 목록은 **한 공간 안**만 본다(`?space=`, 기본 내 개인 공간). 볼 수 없는 행은 SQL 이 거른다 (C:S-02).
    ['GET',    '/api/pages',                 'pages_list.php',      'community.read'],
    // 5-B 검색 (2026-09-23) — 공간을 가리지 않고 행 관문(목록 조건절)만, 관리자 특권 갈래는 끈다
    ['GET',    '/api/procedures',             'procedures_list.php', 'community.read'],
    ['GET',    '/procedures',                 'home_page.php', 'community.read'],
    ['GET', '/ai', 'ai_page.php', 'community.read'],
    ['GET', '/api/ai/status', 'ai_status.php', 'community.read'],
    ['GET', '/api/admin/ai/settings', 'ai_settings_get.php', 'role:Admin'],
    ['PATCH', '/api/admin/ai/settings', 'ai_settings_update.php', 'role:Admin'],
    ['POST', '/api/admin/ai/key', 'ai_key.php', 'role:Admin'],
    ['POST', '/api/admin/ai/health', 'ai_health.php', 'role:Admin'],
    ['GET', '/api/pages/{id}/ai-permission', 'ai_permission_get.php', 'community.read'],
    ['PATCH', '/api/pages/{id}/ai-permission', 'ai_permission_update.php', 'community.write'],
    ['POST', '/api/pages/{id}/ai-summary', 'ai_summary.php', 'community.read'],
    ['POST', '/api/ai/assistant', 'ai_assistant.php', 'community.read'],
    ['POST', '/api/ai/briefing', 'ai_briefing.php', 'community.read'],
    ['POST', '/api/ai/search', 'ai_search.php', 'community.read'],
    ['POST', '/api/ai/transcribe', 'ai_transcribe.php', 'community.read'],
    ['POST', '/api/pages/{id}/ai-insert', 'ai_insert.php', 'community.write'],
    ['GET', '/api/me/ai-log', 'ai_my_log.php', 'community.read'],
    ['GET', '/api/admin/ai-log', 'ai_admin_log.php', 'role:Admin'],
    ['GET',    '/api/search',                'search.php',          'community.read'],
    ['POST',   '/api/pages',                 'pages_create.php',    'community.write'],
    ['GET',    '/api/pages/{id}',            'pages_get.php',       'community.read'],
    ['PATCH',  '/api/pages/{id}',            'pages_update.php',    'community.write'],
    // 트리에서 자리를 바꾼다 — 부모 갈아 끼우기와 형제 순서가 같은 창구다 (4-A).
    ['PATCH',  '/api/pages/{id}/move',       'pages_move.php',      'community.write'],
    // 공개 범위 바꾸기 — 소유자만. 트리 불변 규칙(부모보다 넓을 수 없다)은 여기서 갈린다 (4-B).
    ['PATCH',  '/api/pages/{id}/visibility',  'pages_visibility.php', 'community.write'],
    ['DELETE', '/api/pages/{id}',            'pages_delete.php',    'community.write'],
    ['POST',   '/api/pages/{id}/heartbeat',  'pages_heartbeat.php', 'community.write'],

    /* ── 이력 · 되돌리기 · 휴지통 (4-C, C:F-19 · C:F-20 · C:S-14) ──
     * 읽는 넷은 `community.read`, 바꾸는 둘은 `community.write` 다. 행 단위 판정은 각각
     * `decidePageRead`(이력 읽기) · `decidePageChange`(되돌리기) · `decidePageRestore`(되살리기)이고,
     * 정책 이름만으로 열리는 것은 하나도 없다 (ARCH 4.1).
     * `{num}` 은 `TBOX_PATH_PATTERNS` 의 **숫자 9자리까지**다 — 판 번호 자리에 글자가 오면
     * 라우트에 맞지 않아 404 이고, 핸들러가 받는 값은 이미 숫자 문자열이다. */
    ['GET',    '/api/pages/{id}/versions',               'versions_list.php',   'community.read'],
    ['GET',    '/api/pages/{id}/versions/{num}',         'version_get.php',     'community.read'],
    ['POST',   '/api/pages/{id}/versions/{num}/restore', 'version_restore.php', 'community.write'],
    ['GET',    '/api/pages/{id}/history',                'history.php',         'community.read'],
    ['GET',    '/api/me/trash',                          'trash_list.php',      'community.read'],
    ['POST',   '/api/pages/{id}/restore',                'page_restore.php',    'community.write'],

    /* ── 상태 칸 (4-D, C:F-37) ──
     * 바꾸는 창구는 `community.write` + 행 단위 `decidePageChange`(소유자·편집 공유자·팀원).
     * 5-A — **담당자 지정은 멈췄다**(사용자 결정: 담당자 = 소유자, 바꾸는 일은 인계다).
     * 그래서 `GET /api/assignees` 는 표에서 빠졌고, 이 창구는 `status` 한 칸만 받는다. */
    ['PATCH',  '/api/pages/{id}/properties',             'pages_properties.php', 'community.write'],

    /* ── 데이터베이스 (6-A, PRD F-27) ──
     * DB 는 `POST /api/pages {database:true}` 로 만든다(DB 페이지 + `databases` 한 줄). 「DB 페이지 바로 아래
     * 페이지 = 행」이라 새 행도 `POST /api/pages {parent_id: DB 페이지, properties}` 다 — 만들기 판정은 한 곳이다.
     * 행 목록은 **목록 조건절 안에서만**(볼 수 없는 행은 SQL 이 거른다), 거르기·정렬은 `DbProps` 허용 목록.
     * 스키마 고치기는 DB 페이지의 `decidePageManage`, 행 값 고치기는 그 행의 `decidePageChange`. */
    ['GET',    '/api/databases/{id}/rows',               'database_rows.php',    'community.read'],
    ['PATCH',  '/api/databases/{id}/schema',             'database_schema.php',  'community.write'],
    ['PATCH',  '/api/pages/{id}/values',                 'page_values.php',      'community.write'],
    // 6-B — 저장한 뷰(공용) · 계산(보이는 행만, 수만) · 관계 대상 고르기(같은 공간의 볼 수 있는 DB)
    ['GET',    '/api/databases',                         'databases_list.php',   'community.read'],
    ['PATCH',  '/api/databases/{id}/views',              'database_views.php',   'community.write'],
    ['GET',    '/api/databases/{id}/summary',            'database_summary.php', 'community.read'],
    /* 7-B 템플릿 (C:F-31 · 결정 J-3) — 판정은 `CommunityAuthz::decideTemplate*`. 「템플릿에서 새 페이지」는
       따로 창구가 없다: `POST /api/pages {template_id}` — 만든 페이지의 자리·범위는 그 창구의 규칙 그대로다. */
    ['GET',    '/api/templates',                         'templates_list.php',   'community.read'],
    ['POST',   '/api/templates',                         'templates_create.php', 'community.write'],
    ['GET',    '/api/templates/{id}',                    'templates_get.php',    'community.read'],
    ['DELETE', '/api/templates/{id}',                    'templates_delete.php', 'community.write'],
    /* 7-C 임베드 허용 목록 (C:F-32 · T-33) — 읽기는 누구나(쓰는 사람이 무엇을 넣을 수 있는지 안다), 바꾸기는 Admin(재인증) */
    ['GET',    '/api/embed-domains',                     'embed_domains_list.php',   'community.read'],
    ['POST',   '/api/embed-domains',                     'embed_domains_add.php',    'role:Admin'],
    ['DELETE', '/api/embed-domains/{num}',               'embed_domains_remove.php', 'role:Admin'],
    /* 7-D1 많이 읽힌 문서 (N-34 · 결정 J-6) — 관리자만(재인증), 사내 전체에 열린 페이지만, 수만 */
    ['GET',    '/api/admin/popular',                     'admin_popular.php',        'role:Admin'],
    /* 7-D2 관리자 팔레트 (C:F-33 · 결정 L-2) — 읽기는 누구나(편집기가 이름을 알아야 한다 · 7-C 목록과 같은 까닭), 바꾸기는 Admin(재인증 — 정책이
       `Authz::REAUTH_POLICIES` 안이라 저절로). `/palette.css` 는 서버가 그리는 색 CSS 한 장(`:root` 변수 — 인라인 style 0 · P-6). 새 정책 이름 0 */
    ['GET',    '/palette.css',                           'palette_css.php',          'community.read'],
    ['GET',    '/api/palettes',                          'palettes_list.php',        'community.read'],
    ['POST',   '/api/palettes',                          'palettes_add.php',         'role:Admin'],
    ['PATCH',  '/api/palettes/{num}',                    'palettes_update.php',      'role:Admin'],
    ['DELETE', '/api/palettes/{num}',                    'palettes_remove.php',      'role:Admin'],

    /* ── 사람 지정 공유 · 인계 (5-A, PRD NG-8 해제 · F-28) ──
     * 목록은 `community.read` + 그 페이지의 `decidePageRead`, 바꾸는 셋은 `community.write` +
     * **`decidePageManage`**(소유자·팀 공간 팀원 — 편집 공유로는 안 된다). 예외 하나: 공유받은
     * 사람이 **자기 공유를 빼는 일**은 관리 자격 없이 된다(`pages_share_delete.php`).
     * 인계의 자격은 따로 있다(`decidePageHandover` — 소유자·자기 팀원 것의 Manager·Admin).
     * Admin 의 인계는 **재인증**이 필요하다 — 라우트 정책이 아니라 판정 결과(`admin_all`)에
     * 걸리므로 핸들러가 세션의 `reauth_fresh` 를 본다(소유자 본인의 인계를 15분마다 끊지 않는다).
     * `{username}` 은 `Auth::USERNAME_RE` 와 같은 글자 집합이다(`COMMUNITY_PATH_PATTERNS`). */
    ['GET',    '/api/pages/{id}/shares',                 'pages_shares_list.php',  'community.read'],
    ['PUT',    '/api/pages/{id}/shares/{username}',      'pages_share_put.php',    'community.write'],
    ['DELETE', '/api/pages/{id}/shares/{username}',      'pages_share_delete.php', 'community.read'],
    ['GET',    '/api/share-candidates',                  'share_candidates.php',   'community.read'],
    ['POST',   '/api/pages/{id}/handover',               'pages_handover.php',     'community.write'],

    /* ── 게시판 · 새 글 · 게시 · 태그 · 조회수 (5-C, PRD 4장 · F-18 · F-26 · F-35) ──
     * 게시판 번호는 `{num}`(숫자 9자리까지)이다 — 게시판은 관리자가 만드는 몇 개뿐이라 UUID 로 숨길
     * 까닭이 없고, 볼 수 없는 번호는 **403 동일 문구**라 번호를 넘겨짚어도 얻는 것이 없다(PRD F-04).
     * 만들기·고치기는 `role:Admin` — 이미 `Authz::REAUTH_POLICIES` 에 있으므로 **재인증이 저절로**
     * 따라붙는다(PRD F-09 「재인증」, 새 정책 이름을 만들지 않는다 — `Authz.php` 무변경).
     * 게시·해제·숨김의 자격은 행 단위로 따로 묻는다(`decidePublish`·`decideBoardWrite`·
     * `decideUnpublish`·`decideHide`). 조회수는 `GET /api/pages/{id}` 가 올린다(한 사람 하루 한 번). */
    ['GET',    '/b/{num}',                               'board_view.php',         'community.read'],
    ['GET',    '/api/boards',                            'boards_list.php',        'community.read'],
    ['POST',   '/api/boards',                            'boards_create.php',      'role:Admin'],
    ['PATCH',  '/api/boards/{num}',                      'boards_update.php',      'role:Admin'],
    ['GET',    '/api/boards/{num}/pages',                'board_pages_list.php',   'community.read'],
    ['POST',   '/api/boards/{num}/pages',                'board_pages_create.php', 'community.write'],
    ['GET',    '/api/boards/{num}/tags',                 'board_tags.php',         'community.read'],
    ['POST',   '/api/pages/{id}/publish',                'pages_publish.php',      'community.write'],
    ['DELETE', '/api/pages/{id}/publish',                'pages_unpublish.php',    'community.write'],
    ['POST',   '/api/pages/{id}/hide',                   'pages_hide.php',         'community.write'],
    ['DELETE', '/api/pages/{id}/hide',                   'pages_hide.php',         'community.write'],
    ['PUT',    '/api/pages/{id}/tags',                   'pages_tags_put.php',     'community.write'],

    /* ── 댓글 · 멘션 · 알림 · 공지 읽음 (5-D, PRD F-21 · F-22 · F-30, ARCH 15장) ──
     * 댓글은 **페이지를 따른다** — 창구마다 페이지 관문(`community_history_gate`)을 먼저 지나고, 댓글 창구는
     * `{id}` 가 **댓글의** UUID 다(페이지를 찾아 같은 관문). 알림은 **내 것만**(SQL `user_id = 나`). */
    ['GET',    '/api/pages/{id}/comments',               'comments_list.php',      'community.read'],
    ['POST',   '/api/pages/{id}/comments',               'comments_create.php',    'community.write'],
    ['PATCH',  '/api/comments/{id}',                     'comment_update.php',     'community.write'],
    ['DELETE', '/api/comments/{id}',                     'comment_delete.php',     'community.write'],
    ['POST',   '/api/comments/{id}/resolve',             'comment_resolve.php',    'community.write'],
    ['DELETE', '/api/comments/{id}/resolve',             'comment_resolve.php',    'community.write'],
    ['POST',   '/api/comments/{id}/helpful',             'comment_helpful.php',    'community.write'],
    ['DELETE', '/api/comments/{id}/helpful',             'comment_helpful.php',    'community.write'],
    ['POST',   '/api/comments/{id}/hide',                'comment_hide.php',       'community.write'],
    ['DELETE', '/api/comments/{id}/hide',                'comment_hide.php',       'community.write'],
    ['GET',    '/api/mention-candidates',                'mention_candidates.php', 'community.read'],
    ['GET',    '/api/notifications',                     'notifications_list.php', 'community.read'],
    ['POST',   '/api/notifications/read',                'notifications_read.php', 'community.read'],
    ['GET',    '/api/pages/{id}/reads',                  'page_reads.php',         'community.read'],
    /* 개발 대기열 6 · Q:F-3a 라운드 A — 즐겨찾기 · 최근 (지시서 ../../../docs/qf3a-favorites-recent-bulk-directive.md 1.1 · 1.2,
       커뮤니티 ARCH 21.24). 정책은 전부 `community.read` — 로그인한 사람의 **개인 화면 설정**이고(내 줄만 읽고 쓴다),
       페이지를 넣을 수 있는지는 핸들러가 `CommunityAuthz::decidePageRead` 로, 목록은 검색과 같은 조건절
       `community_search_pages_where`(관리자 특권 갈래 끔)로 다시 판단한다. 새 정책·새 판정 코드 0. 상태를 바꾸는 넷(PATCH·PUT·
       DELETE 둘)은 [3] CSRF 를 지난다. 대상은 언제나 세션의 본인 — 요청에서 사람을 받지 않는다. */
    ['GET',    '/api/me/favorites',                      'favorites.php',          'community.read'],
    ['PATCH',  '/api/me/favorites',                      'favorites.php',          'community.read'],   // 끌어서 순서 바꾸기
    ['PUT',    '/api/pages/{id}/favorite',               'favorites.php',          'community.read'],
    ['DELETE', '/api/pages/{id}/favorite',               'favorites.php',          'community.read'],
    ['GET',    '/api/me/recent',                         'recent_list.php',        'community.read'],
    ['DELETE', '/api/me/recent',                         'recent_clear.php',       'community.read'],   // 「최근 기록 지우기」 — 내 줄만
];

/**
 * Q:F-8 — 개인 프로그램 토큰(`X-API-Token`)이 통하는 커뮤니티 창구 **셋** (사이트 PRD 10장 Q:F-8 · t-box PRD F-74,
 * ARCH 4.8, SECURITY R-112).
 *
 * 값은 「메서드 + **모듈** 라우트 패턴」(`/community` 를 뗀 표기 — 위 표와 같은 글자)이다. 아래 [2-T] 가 이 표와만
 * 견주므로 토큰 경로를 넓히려면 여기를 고쳐야 하고, 그것이 곧 검토 지점이다(`TBOX_API_TOKEN_ROUTES` 와 같은 원칙).
 * 무엇이 **아닌지**가 이 표의 뜻이다(Q:F-8 때): 목록·검색·고치기·지우기·댓글·공유·게시는 토큰으로 열리지 않았다
 * (내 개인 공간에 비공개 페이지를 만들고, 그 페이지를 읽는 것까지). 목록·검색은 Q:F-10 에서 열렸다(아래).
 *
 * Q:F-10 (사이트 PRD Q:F-10 · t-box F-76, ARCH 4.10, SECURITY R-114) — **읽기 셋**을 더했다: 검색 · 한 공간의 목록 · 내가 볼 수
 * 있는 공간. 정책은 아래 라우트 표의 값 그대로(`community.read`)이고 핸들러는 한 글자도 바뀌지 않았다 — 행 관문(검색·목록의
 * SQL 조건절 `community_search_pages_where`·`community_visible_pages_where` · 공간 관문 `community_space_gate`)이 세션과 같은 코드다.
 * 여전히 열리지 않는 것: 고치기·지우기·옮기기·댓글·공유·게시·즐겨찾기·최근 기록.
 *
 * ★ 전부 **개인 토큰만**(F-59 인자) · 범위는 `Authz::tokenScopeAllows(범위, 라우트 정책)` 한 곳 — `community.read` 정책은
 *   원자 `community.read`(또는 그것을 품는 `community.write`), `community.write` 정책은 원자 `community.write` 만 참이다.
 * ★ 전부 화면도 쓰는 라우트라, 헤더가 **왔을 때만** 토큰 갈래로 간다 — 헤더가 없으면 세션 경로가 한 글자도 바뀌지 않는다.
 */
const COMMUNITY_API_TOKEN_ROUTES = [
    'GET /api/me/space',
    'POST /api/pages',
    'GET /api/pages/{id}',
    // Q:F-10 — 읽기 셋(정책 community.read)
    'GET /api/search',            // 검색 — 비서 「커뮤니티 검색」 · MCP qaredi_search_pages
    'GET /api/pages',             // 한 공간의 목록(?space=)
    'GET /api/spaces',            // 내가 볼 수 있는 공간
];

/**
 * 커뮤니티 라우트에만 있는 경로 자리표시자 (5-A).
 *
 * `{username}` — 계정명. `Auth::USERNAME_RE`(`[a-z0-9_.-]{3,64}`)와 **같은 글자 집합**이다.
 * t-box 표(`TBOX_PATH_PATTERNS`, `public/index.php`)를 고치지 않고 여기에 둔다 — 그 자리를
 * 쓰는 라우트가 커뮤니티에만 있다. 값은 핸들러에서 **바인딩 값**으로만 쓰이고(계정 찾기),
 * 파일 경로·SQL 조각이 되지 않는다. 형태가 다르면 라우트에 맞지 않아 404 다.
 */
const COMMUNITY_PATH_PATTERNS = [
    '{view}' => 'v[0-9a-f]{8}',
    '{gid}'=>'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
    '{pubtoken}'=>'[A-Za-z0-9_-]{43}',
    '{viewid}'=>'v[0-9a-f]{8}',
    '{username}' => '[a-z0-9_.-]{3,64}',
];

/** 자리표시자 하나의 정규식 — t-box 표 먼저, 없으면 커뮤니티 표. 둘 다 없으면 `null`. */
function community_path_pattern(string $token): ?string
{
    return TBOX_PATH_PATTERNS[$token] ?? COMMUNITY_PATH_PATTERNS[$token] ?? null;
}

/**
 * 커뮤니티 라우트 패턴 → 정규식 (t-box `tbox_pattern_to_regex()` 와 같은 방식, 자리표시자 표만 넓다).
 * 표에 없는 자리표시자는 「무엇과도 맞지 않는다」(`(?!)`)로 바뀐다 — 검사를 건너뛴 경로가
 * 「무엇이든 매칭」이 되지 않게 한다.
 */
function community_pattern_to_regex(string $path): string
{
    $regex = '';
    $rest  = $path;
    while ($rest !== '') {
        if (preg_match('/\{[a-z]+\}/', $rest, $m, PREG_OFFSET_CAPTURE) !== 1) {
            $regex .= preg_quote($rest, '#');
            break;
        }
        $token  = $m[0][0];
        $at     = (int) $m[0][1];
        $regex .= preg_quote(substr($rest, 0, $at), '#');
        $regex .= '(' . (community_path_pattern($token) ?? '(?!)') . ')';
        $rest   = substr($rest, $at + strlen($token));
    }
    return '#^' . $regex . '$#';
}

/**
 * 상태 다섯 값 (4-D, C:F-37). **NULL(없음)이 기본**이고 그것은 이 목록에 없다 —
 * 「없음」은 값이 아니라 값이 없는 상태이므로 허용 목록에 넣지 않는다.
 * 화면 글자는 `public/assets/community/editor.js` 의 `STATUS_LABELS` 가 들고 있다.
 */
const COMMUNITY_STATUSES = ['todo', 'doing', 'review', 'done', 'hold'];

/** 핸들러 디렉터리. 코드 상수이며 요청 값이 경로에 한 글자도 들어가지 않는다. */
const COMMUNITY_HANDLER_DIR = __DIR__ . '/handlers/';

/** 미로그인 화면 요청을 보내는 곳 — t-box 로그인 화면 + 돌아올 곳 꼬리표 (ARCH 21.2). */
const COMMUNITY_LOGIN_TO = 'community';

/**
 * 커뮤니티 라우트 표가 스스로 성립하는지 검사한다 (t-box `tbox_validate_routes()` 와 같은 검사).
 *
 * @return list<string> 문제 목록. 빈 배열이면 정상.
 */
function community_validate_routes(): array
{
    $problems = [];
    $methods  = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    $seen     = [];

    foreach (COMMUNITY_ROUTES as $i => $route) {
        $where = 'community route#' . $i;

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
            foreach ($found[0] as $token) {
                if (community_path_pattern($token) === null) {
                    $problems[] = $where . ': 모르는 경로 자리표시자 ' . $token;
                }
            }
        }
        if (!is_string($policy) || !in_array($policy, Authz::KNOWN_POLICIES, true)) {
            $problems[] = $where . ': 정책이 Authz::KNOWN_POLICIES 에 없다 (policy=' . var_export($policy, true) . ')';
        }
        if (!is_string($handler) || preg_match('/^[a-z0-9_]+\.php$/', $handler) !== 1) {
            $problems[] = $where . ': 핸들러 파일명이 허용 형태가 아니다';
        } elseif (!is_file(COMMUNITY_HANDLER_DIR . $handler)) {
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
 * 운영 DB 에 커뮤니티 스키마가 이미 적용됐나 (완료 기준 6).
 *
 * 마이그레이션은 **사용자**가 돌린다(Opus·Fable 은 운영 DB 에 DDL 을 실행하지 않는다).
 * 그 사이에 누가 `/community/` 를 열면 표가 없어 500 이 되는데, 500 은 「서버가 고장났다」는
 * 뜻이라 사실과 다르다. 아직 켜지지 않았을 뿐이므로 **503 + 안내**로 답한다.
 *
 * 판정은 읽기 질의 하나(`to_regclass`)다 — 요청 값이 섞이지 않는 고정 문자열이고,
 * 없으면 NULL 을 돌려줄 뿐 예외를 던지지 않는다.
 */
function community_schema_ready(): bool
{
    $sql = <<<'SQL'
        SELECT to_regclass('community.pages') IS NOT NULL AS ready
        SQL;
    try {
        $row = Db::instance()->run($sql)->fetch();
    } catch (Throwable $e) {
        return false;
    }
    return is_array($row) && ($row['ready'] ?? false) === true;
}

/**
 * 「커뮤니티 준비 중」 응답. `/api/*` 는 JSON, 화면은 같은 뜻의 HTML 한 장이다.
 * 화면 쪽은 셸을 쓰지 않는 독립 화면이고(로그인 화면과 같은 층), 새 CSS 클래스를 만들지
 * 않는다 — `.app`·`.main`·`.page-head`·`.notice` 는 DESIGN.md 2장에 이미 있는 구성 요소다.
 */
function community_not_ready(bool $isApi): void
{
    if ($isApi) {
        Http::error(503, '커뮤니티는 아직 준비 중입니다');
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $css = htmlspecialchars(Http::url('/assets/app.css'), ENT_QUOTES, 'UTF-8');
    echo '<!doctype html>' . "\n"
       . '<html lang="ko"' . Http::themeAttr() . Design::attrs() . '>' . "\n"
       . '<head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="color-scheme" content="light dark">'
       . '<title>커뮤니티 · Qaredi</title>'
       . '<link rel="stylesheet" href="' . $css . '">' . Design::assets(false) . '</head>' . "\n"
       . '<body><div class="app"><main class="main">'
       . '<header class="page-head"><div class="page-head__text">'
       . '<h1 class="page-head__title">커뮤니티</h1>'
       . '<p class="page-head__sub">개인 업무 공간</p></div></header>'
       . '<div class="notice" role="status">'
       . '<p class="notice__title">아직 준비 중입니다</p>'
       . '<p>잠시 뒤에 다시 열어 보세요. 계속 이 화면이면 관리자에게 알려 주세요.</p>'
       . '<p><a href="' . htmlspecialchars(Http::url('/'), ENT_QUOTES, 'UTF-8') . '">t-box 로 가기</a>'
       . ' · <a href="/">Qaredi 홈</a></p>'
       . '</div></main></div></body></html>' . "\n";
    exit;
}

/* ═══════════════════════════════════════════════════════════════
 * 핸들러가 함께 쓰는 것 두 가지
 *
 * 왜 여기인가: 아래 둘은 핸들러 여럿이 **글자 그대로 같아야** 하는 코드다. 페이지를 읽는
 * SQL 이 핸들러마다 조금씩 다르면 판정에 필요한 칸이 하나 빠진 핸들러가 생기고, 파생 표를
 * 다시 쓰는 코드가 세 벌이면 언젠가 한 벌만 고쳐진다. 새 파일을 만들지 않고 모듈의 입구
 * 파일에 둔다 — 모든 커뮤니티 요청이 이미 이 파일을 지난다.
 * ═══════════════════════════════════════════════════════════════ */

/**
 * 공유 등급을 세는 **SQL 조각 하나** (5-A) — 판정과 목록이 **글자 그대로 같은 조각**을 쓴다.
 *
 * 돌려주는 것은 스칼라 부질의다: 페이지 `$pageExpr`(바깥 질의의 열, 예: `p.id`) **와 그 조상**에
 * 바인딩 `$userParam` 의 사람에게 준 공유 중 **가장 강한 등급**의 번호(`edit`=2 · `view`=1),
 * 없으면 NULL.
 *   · 조상은 **같은 공간 안**에서만, **살아 있는 것**만 따라 오른다(지워진 조상 너머의 공유는
 *     효력이 없다 — 경로 줄 `community_breadcrumb()` 가 멈추는 자리와 같다). 깊이는 16 에서
 *     멈춘다(트리 한도 8 의 두 배 — 자료가 어긋나도 질의가 끝없이 돌지 않는다).
 *   · 공유받은 계정이 **비활성**이면 그 줄은 효력 0 이다(`su.is_active = true`).
 *
 * `$pageExpr`·`$userParam` 은 **코드에 적힌 리터럴**만 온다(부르는 곳 — 이 파일의
 * `community_page_row`·`community_visible_pages_where`, 그리고 `pages_list.php` 의 「공유받은 페이지」). 요청 값이 한
 * 글자도 이 조각에 들어가지 않는다 — 들어가는 값은 전부 바인딩이다 (PRD S-04).
 */
const COMMUNITY_SHARE_RANK_SQL = <<<'SQL'
(SELECT max(CASE sh.level WHEN 'edit' THEN 2 WHEN 'view' THEN 1 END)
   FROM (WITH RECURSIVE up(id, parent_page_id, space_id, lvl) AS (
             SELECT a.id, a.parent_page_id, a.space_id, 1
               FROM community.pages a
              WHERE a.id = @PAGE@
             UNION ALL
             SELECT b.id, b.parent_page_id, b.space_id, up.lvl + 1
               FROM community.pages b
               JOIN up ON b.id = up.parent_page_id
              WHERE b.space_id = up.space_id
                AND b.deleted_at IS NULL
                AND up.lvl < 16
         )
         SELECT id FROM up) chain
   JOIN community.page_shares sh ON sh.page_id = chain.id
   JOIN public.users su ON su.id = CAST(@USER@ AS bigint) AND su.is_active = true
  WHERE sh.user_id = su.id OR EXISTS (
    SELECT 1 FROM community.share_group_members gm JOIN community.share_groups sg ON sg.id=gm.group_id
    WHERE gm.group_id=sh.group_id AND gm.user_id=su.id AND sg.deleted_at IS NULL))
SQL;

function community_share_rank_sql(string $pageExpr, string $userParam): string
{
    // 코드 리터럴만 받는다는 약속을 코드로도 지킨다 — 모양이 다르면 부르는 쪽의 실수다.
    if (preg_match('/^[a-z]+\.id$/', $pageExpr) !== 1 || preg_match('/^:[a-z_]+$/', $userParam) !== 1) {
        throw new RuntimeException('community_share_rank_sql: 허용되지 않는 식별자');
    }
    // 조각은 위 nowdoc 상수 **한 벌**이고, 바뀌는 것은 별칭·자리표시자 이름 두 칸뿐이다.
    return strtr(COMMUNITY_SHARE_RANK_SQL, ['@PAGE@' => $pageExpr, '@USER@' => $userParam]);
}

/** 공유 등급 번호 → 이름. 모르는 번호는 `null`(등급 없음)이다 — 모르면 막는다. */
function community_share_level_name($rank): ?string
{
    if ($rank === null) {
        return null;
    }
    $n = (int) $rank;
    return $n === 2 ? 'edit' : ($n === 1 ? 'view' : null);
}

/**
 * 페이지 한 건 + 그 공간을 **한 번에** 읽는다 (ARCH 13.2 가 판정에 요구하는 칸을 전부 싣는다).
 *
 * 지워진 페이지도 돌려준다 — 「없음(404)」과 「지워짐」을 가르는 것은 판정 함수의 몫이다.
 *
 * 5-A — `$viewerId` 를 받는다. 그 사람 몫의 **가장 강한 공유 등급**(이 페이지 + 조상, 활성 계정만)을
 * `share_level` 에, 누구 몫으로 센 값인지를 `share_user_id` 에 싣는다. 판정 함수는 두 값이
 * 지금 묻는 사람과 맞을 때만 그 등급을 쓴다(`CommunityAuthz::shareLevelFor`). 주지 않으면(0)
 * 공유 갈래는 통째로 꺼진다 — 잊은 호출은 **좁은 쪽**으로 틀린다.
 *
 * @return array<string,mixed>|null 없으면 null
 */
function community_page_row(string $publicId, int $viewerId = 0): ?array
{
    return community_page_row_read($publicId, $viewerId, true);
}

/**
 * `community_page_row()` 에서 **본문(`p.doc`) 한 칸만 뺀** 같은 행 (Q:F-4 P-4b).
 *
 * 편집 중 신호(`pages_heartbeat.php`)처럼 판정만 하고 본문을 쓰지 않는 창구가 30초마다 본문 jsonb 전체
 * (실측 최대 99KB)를 읽지 않게 한다. **SQL 은 한 벌**이다(`community_page_row_sql()`) — 판정 칸을 따로
 * 고르지 않고 본문 칸 하나만 빼므로, 판정 함수(`CommunityAuthz`)가 받는 칸은 `doc` 을 뺀 전부가 글자 그대로
 * 같다. 판정 재료가 이 함수에만 빠지는 일이 생기지 않는다(판정은 본문을 보지 않는다 — `CommunityAuthz`
 * 에 `doc` 을 읽는 줄 0). 돌려주는 행에는 `doc` 키가 **없다** — 실수로 읽으면 경고가 예외로 바뀌어 500
 * 으로 드러난다(빈 본문으로 조용히 넘어가지 않는다).
 *
 * @return array<string,mixed>|null 없으면 null
 */
function community_page_row_nodoc(string $publicId, int $viewerId): ?array
{
    return community_page_row_read($publicId, $viewerId, false);
}

/**
 * 위 두 함수의 몸통 — 질의·후처리가 한 벌이다.
 *
 * @return array<string,mixed>|null
 */
function community_page_row_read(string $publicId, int $viewerId, bool $withDoc): ?array
{
    $row = Db::instance()->run(community_page_row_sql($withDoc), [':pid' => $publicId, ':viewer' => $viewerId])->fetch();
    if (!is_array($row)) {
        return null;
    }
    $row['share_level']   = community_share_level_name($row['share_rank']);
    $row['share_user_id'] = $viewerId > 0 ? $viewerId : null;
    unset($row['share_rank']);
    return $row;
}

/**
 * 페이지 한 건 질의 **한 벌** (ARCH 13.2 판정 칸 전부). 바뀌는 것은 본문 칸 하나다 (Q:F-4 P-4b).
 *
 * `$withDoc` 이 거짓이면 `p.doc` 을 SELECT 목록에서 뺀다. 끼워 넣는 조각은 코드에 적힌 리터럴 두 값
 * (`'p.doc, '`·`''`)과 공유 등급 조각(위 상수)뿐이다 — 요청 값이 한 글자도 SQL 에 들어가지 않는다 (PRD S-04).
 * 시험(`tests/qf4b1.sh`)이 이 함수의 결과로 쿼리 계획을 떠 `doc` 열이 없는지 본다.
 */
function community_page_row_sql(bool $withDoc): string
{
    $shareRank = community_share_rank_sql('p.id', ':viewer');
    $docCol    = $withDoc ? 'p.doc, ' : '';
    return <<<SQL
        SELECT p.id, p.public_id, p.space_id, p.parent_page_id, p.position,
               p.title, p.icon, p.font, p.small_text, p.full_width, p.visibility,
               {$docCol}p.doc_version, p.block_count,
               p.author_id, p.last_edited_by, p.created_at, p.updated_at, p.form_view_id, p.form_anonymous, p.public_form_response,
               p.deleted_at, p.deleted_by,
               s.kind          AS space_kind,
               s.owner_user_id AS space_owner_user_id,
               s.access_level  AS space_access_level,
               s.team_id       AS space_team_id,
               s.name          AS space_name, s.web_publish_allowed, p.external_key, p.external_missing,
               -- 4-B: 개인 공간 **주인의 t-box 팀**. `visibility='team'` 판정이 이 값과
               -- 보는 사람의 팀을 견준다(PRD 4.1 「소유자의 t-box 팀」). 팀 판정을 위해
               -- t-box 사용자 표를 읽는 자리는 여기 하나다 — 핸들러가 따로 묻지 않는다.
               owner.team_id   AS space_owner_team_id,
               -- 5-A: 속성 줄의 「소유자」 — 공간 주인의 계정명·활성 여부. 계정 id 는 응답에 나가지 않는다.
               owner.username  AS space_owner_name,
               -- 5-A″: 이름(없으면 NULL). 「이름 (아이디)」로 잇는 일은 화면이 한다.
               owner.display_name AS space_owner_display_name,
               owner.is_active AS space_owner_active,
               -- 4-C: 지금 판을 **마지막으로 쓴 사람의 이름**. 이력 목록의 맨 윗줄(지금 판)이
               -- 이 값을 그대로 보인다 — 화면이 계정 id 를 이름으로 바꾸는 일이 없게 한다.
               editor.username AS last_edited_by_name,
               editor.display_name AS last_edited_by_display_name,
               -- 4-D: 상태 칸 + (멈춘) 담당자 칸의 옛 값. 5-A 부터 담당자는 **읽기만** 한다.
               p.status, p.assignee_id, p.due_date, p.page_kind, p.verified_by, p.verified_at, p.verify_expires_at,
               verifier.display_name AS verified_by_display_name, verifier.username AS verified_by_name,
               CASE WHEN p.verified_at IS NULL THEN 'none' WHEN p.verify_expires_at <= now() THEN 'expired' ELSE 'verified' END AS verify_state,
               assignee.username  AS assignee_name,
               assignee.role      AS assignee_role,
               assignee.is_active AS assignee_active,
               -- 5-A: 이 사람 몫의 가장 강한 공유 등급(번호). 아래에서 이름으로 바꾼다.
               {$shareRank} AS share_rank,
               EXISTS(SELECT 1 FROM community.page_shares fs WHERE fs.page_id=p.id AND (fs.user_id=CAST(:viewer AS bigint) OR EXISTS(
                   SELECT 1 FROM community.share_group_members fm JOIN community.share_groups fg ON fg.id=fm.group_id
                   JOIN public.users fu ON fu.id=fm.user_id AND fu.is_active
                   WHERE fm.group_id=fs.group_id AND fm.user_id=CAST(:viewer AS bigint) AND fg.deleted_at IS NULL))) AS form_direct_share,
               -- 5-C: 게시판 공간이면 그 게시판의 활성·종류(게시판 글의 판정 재료)
               s.is_active     AS space_is_active,
               s.board_kind    AS space_board_kind,
               -- 5-C: 쓴 사람(게시판 글의 「쓴 사람」 — 판정은 `author_id`, 이름은 화면용)
               author.username     AS author_name,
               author.display_name AS author_display_name,
               -- 5-C: 게시 · 숨김 · 해결됨
               p.published_board_id, p.published_from, p.published_at, p.published_by,
               p.hidden_at, p.hidden_by, p.resolved,
               -- 5-C: 게시한 게시판(없으면 전부 NULL). 판정은 `pub_*` 칸으로 게시판 등급을 본다.
               pb.kind         AS pub_kind,
               pb.name         AS pub_name,
               pb.access_level AS pub_access_level,
               pb.team_id      AS pub_team_id,
               pb.is_active    AS pub_is_active,
               pb.board_kind   AS pub_board_kind,
               -- 6-A: 이 페이지가 **DB 페이지**면 그 DB(dbself_*), **행**이면 속한 DB(p.database_id + 스키마)
               dbself.public_id AS dbself_public_id,
               dbself.schema    AS dbself_schema,
               p.database_id, p.properties,
               p.purged_at,
               dbrow.public_id  AS db_public_id,
               dbrow.schema     AS db_schema
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner    ON owner.id    = s.owner_user_id
          LEFT JOIN public.users editor   ON editor.id   = p.last_edited_by
          LEFT JOIN public.users assignee ON assignee.id = p.assignee_id
          LEFT JOIN public.users author   ON author.id   = p.author_id
          LEFT JOIN public.users verifier ON verifier.id = p.verified_by
          LEFT JOIN community.spaces pb   ON pb.id       = p.published_board_id
          LEFT JOIN community.databases dbself ON dbself.page_id = p.id
          LEFT JOIN community.databases dbrow  ON dbrow.id       = p.database_id
         WHERE p.public_id = CAST(:pid AS uuid)
        SQL;
}

/**
 * 파생 표(`page_blocks`·`page_links`)를 **다시 쓴다** (ARCH 12.1 · 13.4).
 *
 * 지우고 다시 넣는다 — 파생값은 `doc` 에서 언제든 다시 만들 수 있고, 차이를 계산하는 코드를
 * 두면 그 코드가 틀렸을 때 조용히 어긋난 색인이 남는다. 반드시 **부르는 쪽의 트랜잭션 안**에서
 * 부른다(본문 저장과 색인이 갈라지지 않게).
 *
 * @param array<string,mixed> $extract `Doc::extract()` 의 결과
 */
function community_rewrite_derived(int $pageId, array $extract): void
{
    $db = Db::instance();

    $sqlDelBlocks = <<<'SQL'
        DELETE FROM community.page_blocks WHERE page_id = CAST(:pid AS bigint)
        SQL;
    $sqlDelLinks = <<<'SQL'
        DELETE FROM community.page_links WHERE from_page_id = CAST(:pid AS bigint)
        SQL;
    $sqlInsBlock = <<<'SQL'
        INSERT INTO community.page_blocks (page_id, block_id, block_type, ord, excerpt)
        VALUES (CAST(:pid AS bigint), CAST(:bid AS uuid), :btype, CAST(:ord AS int), :excerpt)
        SQL;
    $sqlInsLink = <<<'SQL'
        INSERT INTO community.page_links (from_page_id, from_block_id, to_page_id)
        VALUES (CAST(:pid AS bigint), CAST(:bid AS uuid), CAST(:to AS uuid))
        SQL;

    $db->run($sqlDelBlocks, [':pid' => $pageId]);
    $db->run($sqlDelLinks, [':pid' => $pageId]);

    foreach ($extract['blocks'] as $b) {
        $db->run($sqlInsBlock, [
            ':pid'     => $pageId,
            ':bid'     => $b['block_id'],
            ':btype'   => $b['block_type'],
            ':ord'     => $b['ord'],
            ':excerpt' => $b['excerpt'],
        ]);
    }

    // 같은 블록이 같은 페이지를 두 번 가리키면 기본키가 겹친다 — 여기서 한 번 접는다.
    $seen = [];
    foreach ($extract['links'] as $l) {
        $key = $l['from_block_id'] . '|' . $l['to_page_id'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $db->run($sqlInsLink, [
            ':pid' => $pageId,
            ':bid' => $l['from_block_id'],
            ':to'  => $l['to_page_id'],
        ]);
    }
}

/**
 * 감사 문장의 `{target}` 에 쓰는 페이지 제목 — **30자**로 자른다 (ARCH 21.3 「{target} = 페이지 제목 30자」).
 */
function community_target_title(string $title): string
{
    return iconv_strlen($title, 'UTF-8') > 30
        ? (string) iconv_substr($title, 0, 30, 'UTF-8')
        : $title;
}

/* ══════════════════════════════════════════════════════════════════
 * 트리 질의 (4-A) — 전부 **재귀 CTE 한 번**이다
 *
 * 규칙 하나: 트리를 따라가는 일은 **SQL 이 한다.** PHP 가 부모를 한 줄씩 다시 물으면
 * 깊이만큼 질의가 늘고(N+1), 그 사이에 다른 요청이 트리를 바꾸면 화면이 반쯤 옛 트리를
 * 그린다. 아래 세 함수는 각각 SQL 문장 **하나**만 실행한다.
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 뿌리까지의 경로 (breadcrumb). 맨 앞이 뿌리, **맨 뒤가 자기 자신**이다.
 *
 * 왜 자기를 넣나: 화면의 경로 줄이 「일지 › 9월 › 오늘」처럼 끝까지 보여야 하고, 길이가
 * 곧 깊이다(C:T-22 가 1·2·3 을 본다). 끊긴 조상(지워진 페이지)은 CTE 의 `deleted_at IS NULL`
 * 이 걸러 내므로 거기서 멈춘다 — 지워진 부모 너머는 보이지 않는다.
 *
 * ★ 5-A — **볼 수 있는 조상까지만** 보인다. 공유는 한 페이지(와 그 아래)에만 준다: 비공개
 *   부모 아래의 페이지 하나를 공유받은 사람에게 경로 줄이 부모 제목을 보여 주면, 공유하지 않은
 *   페이지의 제목이 새어 나간다. 그래서 `$u` 를 주면 조상마다 **목록과 같은 조건절**
 *   (`community_visible_pages_where`)로 보이는지를 함께 묻고, 자기에서 위로 올라가다 처음
 *   보이지 않는 조상에서 **자른다**(그 위는 보이더라도 잇지 않는다 — 끊긴 경로를 만들지 않는다).
 *
 * @param  array<string,mixed> $u 보는 사람
 * @return list<array{id:string,title:string,icon:?string}>
 */
function community_breadcrumb(int $pageId, int $spaceId, array $u): array
{
    [$where, $visBind] = community_visible_pages_where($u);

    $sql = <<<SQL
        WITH RECURSIVE up AS (
            SELECT p.id, p.parent_page_id, p.public_id, p.title, p.icon, 0 AS lvl
              FROM community.pages p
             WHERE p.id = CAST(:pid AS bigint)
               AND p.space_id = CAST(:space AS bigint)
               AND p.deleted_at IS NULL
            UNION ALL
            SELECT parent.id, parent.parent_page_id, parent.public_id, parent.title, parent.icon, up.lvl + 1
              FROM community.pages parent
              JOIN up ON parent.id = up.parent_page_id
             WHERE parent.space_id = CAST(:space2 AS bigint)
               AND parent.deleted_at IS NULL
        )
        SELECT up.public_id, up.title, up.icon, up.lvl,
               EXISTS (SELECT 1 FROM community.pages p
                         JOIN community.spaces s ON s.id = p.space_id
                         LEFT JOIN public.users owner ON owner.id = s.owner_user_id
                        WHERE p.id = up.id
                          AND {$where}) AS visible
          FROM up ORDER BY up.lvl ASC
        SQL;

    $rows = Db::instance()->run($sql, array_merge(
        [':pid' => $pageId, ':space' => $spaceId, ':space2' => $spaceId],
        $visBind
    ))->fetchAll();

    // 자기(lvl 0)에서 위로 — 처음 보이지 않는 조상에서 멈춘다. 자기 자신은 이미 읽기 판정을 지났다.
    $chain = [];
    foreach ($rows as $r) {
        if ((int) $r['lvl'] > 0 && $r['visible'] !== true) {
            break;
        }
        $chain[] = [
            'id'    => (string) $r['public_id'],
            'title' => (string) $r['title'],
            'icon'  => $r['icon'] === null ? null : (string) $r['icon'],
        ];
    }
    return array_reverse($chain);
}

/**
 * 바로 아래 자식들 — 제목·아이콘·**그 아래 하위 수**까지 한 질의로.
 *
 * `LEFT JOIN` + `GROUP BY` 로 손자 수를 함께 센다. 자식마다 「하위가 있나」를 다시 묻지
 * 않으려는 것이다(N+1 금지).
 *
 * ★ 4-B — **볼 수 있는 자식만** 돌려준다. 자식은 부모보다 넓을 수 없을 뿐 **좁을 수는
 *   있다**(팀 페이지 아래의 비공개 페이지). 그래서 「부모를 볼 수 있으면 자식도 볼 수
 *   있다」가 성립하지 않고, 거르는 자리는 **SQL `WHERE`** 다 (C:S-02 · C:T-37).
 *
 * @param  array<string,mixed> $u 보는 사람
 * @return list<array{id:string,title:string,icon:?string,position:int,child_count:int,updated_at:string}>
 */
function community_children(int $pageId, array $u): array
{
    [$visible, $visBind] = community_visible_pages_where($u);

    // 별칭을 맞춘다 — 조건절은 `p`(페이지)·`s`(공간)·`owner`(공간 주인)를 전제로 쓰였다.
    $sql = <<<SQL
        SELECT p.public_id, p.title, p.icon, p.position, p.updated_at,
               count(g.id) AS child_count
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
          LEFT JOIN community.pages g
                 ON g.parent_page_id = p.id AND g.deleted_at IS NULL AND g.database_id IS NULL
         WHERE p.parent_page_id = CAST(:pid AS bigint)
           AND p.deleted_at IS NULL
           -- 6-A: DB 의 행은 하위 페이지 목록이 아니라 DB 표에 보인다
           AND p.database_id IS NULL
           AND {$visible}
         GROUP BY p.id, p.public_id, p.title, p.icon, p.position, p.updated_at
         ORDER BY p.position, p.id
         LIMIT 500
        SQL;

    $out = [];
    foreach (Db::instance()->run($sql, array_merge([':pid' => $pageId], $visBind))->fetchAll() as $r) {
        $out[] = [
            'id'          => (string) $r['public_id'],
            'title'       => (string) $r['title'],
            'icon'        => $r['icon'] === null ? null : (string) $r['icon'],
            'position'    => (int) $r['position'],
            'child_count' => (int) $r['child_count'],
            'updated_at'  => (string) $r['updated_at'],
        ];
    }
    return $out;
}

/**
 * 그 페이지의 깊이 — **뿌리를 1** 로 센 값 (4-A `pages_create.php` 가 쓴다).
 * 행이 없거나 지워졌으면 0 이다(부르는 쪽이 이미 행을 확인한 뒤에 부른다).
 */
function community_page_depth(int $pageId): int
{
    $sql = <<<'SQL'
        WITH RECURSIVE up AS (
            SELECT p.id, p.parent_page_id, 1 AS lvl FROM community.pages p
             WHERE p.id = CAST(:pid AS bigint) AND p.deleted_at IS NULL
            UNION ALL
            SELECT parent.id, parent.parent_page_id, u.lvl + 1 FROM community.pages parent
              JOIN up u ON parent.id = u.parent_page_id
             WHERE parent.deleted_at IS NULL
        )
        SELECT max(lvl) AS depth FROM up
        SQL;

    $row = Db::instance()->run($sql, [':pid' => $pageId])->fetch();
    return is_array($row) && $row['depth'] !== null ? (int) $row['depth'] : 0;
}

/**
 * 형제 맨 뒤 자리 번호. 새 페이지는 언제나 형제의 **끝**에 붙는다.
 * 부모가 없으면(뿌리) 같은 공간의 뿌리 페이지들 사이에서 센다.
 */
function community_next_position(int $spaceId, ?int $parentId): int
{
    $sql = <<<'SQL'
        SELECT coalesce(max(p.position), -1) + 1 AS next
          FROM community.pages p
         WHERE p.space_id = CAST(:space AS bigint)
           AND p.deleted_at IS NULL
           AND p.parent_page_id IS NOT DISTINCT FROM CAST(:parent AS bigint)
        SQL;

    $row = Db::instance()->run($sql, [':space' => $spaceId, ':parent' => $parentId])->fetch();
    return is_array($row) ? (int) $row['next'] : 0;
}

/**
 * 트리 옮기기에 필요한 값을 **한 문장**으로 모은다 (4-A `pages_move.php`).
 *
 * 돌려주는 것
 *   `subtree_height`   옮길 페이지를 1 로 센 자기 가지의 높이 (잎이면 1)
 *   `subtree_widest`   옮겨 가는 가지 전체에서 **가장 넓은 공개 범위** (4-B). 자기만이면 자기 값
 *   `parent_depth`     새 부모의 깊이 (뿌리를 1 로 센 값). 부모가 없으면 `null`
 *   `parent_ancestors` 새 부모 자신과 그 위 조상들의 `pages.id` 목록. 부모가 없으면 빈 배열
 *
 * 두 갈래(아래로·위로)를 CTE 둘로 나란히 돌리고 마지막에 한 행으로 모은다 — 요청 하나에
 * 트리 질의는 **이 한 번**이다.
 *
 * ★ 「가장 넓은 범위」를 SQL 이 센다 (4-B). PHP 가 가지를 훑어 최대값을 구하면 같은 순서표
 *   (private<team<internal<published)가 두 벌이 된다. 여기서는 순서만 SQL 에 적고, 그 값을
 *   **이름으로 되돌려** 판정은 `CommunityAuthz::fitsUnderParent` 한 곳에서 한다.
 *
 * @return array{subtree_height:int,subtree_widest:string,parent_depth:?int,parent_ancestors:list<int>}
 */
function community_move_shape(int $pageId, ?int $parentId): array
{
    $sql = <<<'SQL'
        WITH RECURSIVE down AS (
            -- 5-C: 넓이는 **기준 범위**로 센다(게시 중이면 게시 전 범위 — `CommunityAuthz::baseVisibility`).
            SELECT p.id, (CASE WHEN p.visibility = 'published' THEN p.published_from ELSE p.visibility END) AS visibility,
                   1 AS lvl FROM community.pages p
             WHERE p.id = CAST(:pid AS bigint) AND p.deleted_at IS NULL
            UNION ALL
            SELECT c.id, (CASE WHEN c.visibility = 'published' THEN c.published_from ELSE c.visibility END),
                   d.lvl + 1 FROM community.pages c
              JOIN down d ON c.parent_page_id = d.id
             WHERE c.deleted_at IS NULL
        ), up AS (
            SELECT p.id, p.parent_page_id, 1 AS lvl FROM community.pages p
             WHERE p.id = CAST(:parent AS bigint) AND p.deleted_at IS NULL
            UNION ALL
            SELECT parent.id, parent.parent_page_id, u.lvl + 1 FROM community.pages parent
              JOIN up u ON parent.id = u.parent_page_id
             WHERE parent.deleted_at IS NULL
        )
        SELECT (SELECT max(lvl) FROM down)      AS subtree_height,
               (SELECT max(CASE visibility WHEN 'private' THEN 0 WHEN 'team' THEN 1
                                           WHEN 'internal' THEN 2 WHEN 'published' THEN 3 END)
                  FROM down)                    AS subtree_widest_rank,
               (SELECT max(lvl) FROM up)        AS parent_depth,
               (SELECT string_agg(CAST(id AS text), ',') FROM up) AS parent_ancestors
        SQL;

    $row = Db::instance()->run($sql, [':pid' => $pageId, ':parent' => $parentId])->fetch();

    $ancestors = [];
    if (is_array($row) && $row['parent_ancestors'] !== null && (string) $row['parent_ancestors'] !== '') {
        foreach (explode(',', (string) $row['parent_ancestors']) as $id) {
            $ancestors[] = (int) $id;
        }
    }

    // 번호를 이름으로 되돌린다. 모르는 번호는 **가장 넓은 값**으로 본다 — 모르면 막는 쪽이다.
    $names  = ['private', 'team', 'internal', 'published'];
    $rank   = is_array($row) && $row['subtree_widest_rank'] !== null ? (int) $row['subtree_widest_rank'] : 0;
    $widest = $names[$rank] ?? 'published';

    return [
        'subtree_height'   => is_array($row) && $row['subtree_height'] !== null ? (int) $row['subtree_height'] : 1,
        'subtree_widest'   => $widest,
        'parent_depth'     => is_array($row) && $row['parent_depth'] !== null ? (int) $row['parent_depth'] : null,
        'parent_ancestors' => $ancestors,
    ];
}

/* ══════════════════════════════════════════════════════════════════
 * 공간 (4-B) — 개인 공간 · 팀 공간
 *
 * 규칙 하나: **목록은 SQL `WHERE` 로 거른다.** 아래 `community_visible_pages_where()` 가
 * 그 조건절이고, `CommunityAuthz::decidePageRead()` 와 **같은 판정**을 SQL 로 적은 것이다.
 * 두 벌이 어긋나면 화면이 볼 수 없는 제목을 내려주게 되므로, 고칠 때는 **둘을 함께** 고친다
 * (C:T-37 이 psql 로 센 수와 API 가 준 수를 대조한다).
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 공간 한 건 + (개인 공간이면) 주인의 팀. 없으면 `null`.
 *
 * @return array<string,mixed>|null
 */
function community_space_row(int $spaceId): ?array
{
    $sql = <<<'SQL'
        SELECT s.id, s.kind, s.name, s.description, s.access_level, s.team_id,
               s.owner_user_id, s.is_active, s.created_at, s.board_kind,
               owner.team_id AS owner_team_id,
               t.name        AS team_name,
               t.is_active   AS team_is_active
          FROM community.spaces s
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
          LEFT JOIN public.teams t     ON t.id = s.team_id
         WHERE s.id = CAST(:sid AS bigint)
        SQL;

    $row = Db::instance()->run($sql, [':sid' => $spaceId])->fetch();
    return is_array($row) ? $row : null;
}

/**
 * 내 개인 공간. 아직 없으면 `null`(`GET /api/me/space` 가 만든다).
 *
 * @return array<string,mixed>|null
 */
function community_my_personal_space(int $userId): ?array
{
    $sql = <<<'SQL'
        SELECT s.id, s.kind, s.name, s.description, s.access_level, s.team_id,
               s.owner_user_id, s.is_active, s.created_at,
               owner.team_id AS owner_team_id,
               NULL::text    AS team_name,
               NULL::boolean AS team_is_active
          FROM community.spaces s
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
         WHERE s.kind = 'personal' AND s.owner_user_id = CAST(:uid AS bigint)
        SQL;

    $row = Db::instance()->run($sql, [':uid' => $userId])->fetch();
    return is_array($row) ? $row : null;
}

/**
 * 이 사람이 **그 공간을 열어 볼 수 있나** (목록 창구의 공간 관문, C:T-35 · C:T-36).
 *
 * 페이지 한 건의 판정(`decidePageRead`)과 다른 물음이다 — 여기서는 「그 그릇을 통째로
 * 훑어도 되나」를 묻는다. 남의 개인 공간은 **통째로 닫혀 있다**: 그 안에 사내로 열어 둔
 * 페이지가 있더라도, 남의 공간 목차를 넘겨보는 일은 공개 범위가 허락한 일이 아니다.
 *
 * @param  array<string,mixed> $u
 * @param  array<string,mixed> $space `community_space_row()` 의 행
 * @return array{0:bool,1:string} [허용 여부, 코드]
 */
function community_space_gate(array $u, array $space): array
{
    $kind = (string) ($space['kind'] ?? '');

    if ($kind === 'personal') {
        $owner = $space['owner_user_id'] ?? null;
        if ($owner !== null && (int) $owner === (int) $u['id']) {
            return [true, CommunityAuthz::CODE_OWNER];
        }
        return [false, CommunityAuthz::CODE_NOT_OWNER];
    }

    if ($kind === 'team') {
        // 판정을 두 벌로 적지 않으려고 페이지 판정 함수를 그대로 부른다 — 팀 공간의
        // 페이지 판정은 「그 팀원인가」 하나뿐이라 공간 판정과 같은 물음이다.
        return CommunityAuthz::decidePageRead($u, [
            'deleted_at'          => null,
            'space_kind'          => 'team',
            'space_team_id'       => $space['team_id'] ?? null,
            'space_owner_user_id' => null,
            'visibility'          => 'private',
        ]);
    }

    /* 5-C 게시판 — 게시판 등급대로(t-box `decideRead`). 목록 창구의 행 관문은 그대로 붙으므로
       숨긴 글·못 보는 글이 목록에 새지 않는다. */
    if ($kind === 'board') {
        return CommunityAuthz::decideBoardRead($u, $space);
    }

    return [false, CommunityAuthz::CODE_ROLE_NOT_PERMITTED];
}

/**
 * 목록 질의에 붙일 **행 단위 조건절** (C:S-02 「못 보는 것은 응답에 없다」, C:T-37).
 *
 * 돌려주는 조건절은 별칭 셋을 전제로 한다 — 부르는 SQL 은 반드시
 * `FROM community.pages p JOIN community.spaces s ON s.id = p.space_id
 *  LEFT JOIN public.users owner ON owner.id = s.owner_user_id` 여야 한다.
 * 별칭은 SQL 식별자 자리라 바인딩할 수 없으므로 **코드에 적힌 리터럴 셋**으로 고정한다
 * (t-box `Authz::visibleWhere` 의 허용 목록과 같은 이유). 게시한 게시판(`pb`)은 조건절 **안의**
 * `EXISTS` 가 스스로 읽는다 — 부르는 SQL 에 별칭을 하나 더 요구하지 않는다(5-C).
 *
 * 조건은 `CommunityAuthz::decidePageRead` 를 한 줄씩 옮긴 것이다:
 *   내 개인 공간 전부 / **나에게 준 공유(이 페이지·조상, 활성 계정 — 개인 공간은 기준 범위 셋만(5-A),
 *   팀 공간은 범위 없이(5-A″))** / 기준 범위 `internal` + 쓰기 자격 / 기준 범위 `team` + 주인과 같은 팀 /
 *   Admin / 내 팀 공간 /
 *   **5-C** 게시된 개인 페이지 — 숨기지 않았고 게시판을 읽을 수 있다(`[4a]`) · 숨겼고 그 게시판의 관리자다 /
 *          게시판 글 — 쓴 사람 · 숨기지 않았고 게시판을 읽을 수 있다 · 숨겼고 그 게시판의 관리자다.
 * 「기준 범위」 = 게시 중이면 게시 전 범위(`published_from`), 아니면 범위 그대로(`CommunityAuthz::baseVisibility`).
 * 게시판 등급 판정은 `community_board_read_sql()`(t-box `decideRead` 를 등급마다 물어 만든 값),
 * 관리자 판정은 `community_board_moderate_sql()`(`decideBoardModerate` 와 짝)이 만든다.
 * 공유 갈래는 **한 줄**에 두 공간 종류를 함께 적는다 — 같은 조각(`community_share_rank_sql`)을 두 번
 * 넣으면 자리표시자 이름이 겹친다(서버측 prepare 는 같은 이름을 두 번 받지 않는다 — `Db.php`).
 *
 * @param  array<string,mixed> $u
 * @return array{0:string,1:array<string,mixed>}
 */
function community_visible_pages_where(array $u): array
{
    // 5-A 공유 갈래 — `community_page_row()` 가 판정에 싣는 값과 **같은 조각**이다.
    $shared = community_share_rank_sql('p.id', ':vis_share');
    // 5-C — 게시판 등급 두 벌(게시판 글의 공간 `s` · 게시한 게시판 `pb`). 자리표시자 이름은 번호로 가른다.
    [$boardS, $boardSBind]   = community_board_read_sql('s', 1, $u);
    [$boardPb, $boardPbBind] = community_board_read_sql('pb', 2, $u);
    [$modS, $modSBind]       = community_board_moderate_sql('s', 1, $u);
    [$modPb, $modPbBind]     = community_board_moderate_sql('pb', 2, $u);
    $base = COMMUNITY_BASE_VISIBILITY_SQL;

    $sql = <<<SQL
(
      (s.kind = 'personal' AND s.owner_user_id = CAST(:vis_me AS bigint))
   OR (    (   (s.kind = 'personal' AND {$base} IN ('private','team','internal'))
            OR s.kind = 'team')
       AND {$shared} IS NOT NULL)
   OR (s.kind = 'personal' AND {$base} = 'internal' AND CAST(:vis_write AS boolean))
   OR (s.kind = 'personal' AND {$base} = 'team'
       AND owner.team_id IS NOT NULL AND owner.team_id = CAST(:vis_team AS bigint))
   OR (s.kind = 'personal' AND {$base} IN ('private','team','internal')
       AND CAST(:vis_admin AS boolean))
   OR (s.kind = 'team' AND s.team_id IS NOT NULL AND s.team_id = CAST(:vis_team AS bigint))
   OR (s.kind = 'team' AND CAST(:vis_admin AS boolean))
   OR (s.kind = 'personal' AND p.visibility = 'published' AND {$base} IN ('private','team','internal')
       AND EXISTS (SELECT 1 FROM community.spaces pb
                    WHERE pb.id = p.published_board_id AND pb.kind = 'board'
                      AND (   (p.hidden_at IS NULL     AND {$boardPb})
                           OR (p.hidden_at IS NOT NULL AND {$modPb}))))
   OR (s.kind = 'board' AND p.author_id = CAST(:vis_author AS bigint))
   OR (s.kind = 'board' AND p.hidden_at IS NULL     AND {$boardS})
   OR (s.kind = 'board' AND p.hidden_at IS NOT NULL AND {$modS})
)
SQL;

    $formScope = <<<SQL
        (p.form_view_id IS NULL OR CAST(:vis_admin AS boolean)
         OR (s.kind='personal' AND s.owner_user_id=CAST(:vis_me AS bigint))
         OR (s.kind='team' AND s.team_id=CAST(:vis_team AS bigint))
         OR (CAST(:vis_write AS boolean) AND {$shared}=2)
         OR EXISTS(SELECT 1 FROM community.page_shares fs WHERE fs.page_id=p.id AND (fs.user_id=CAST(:vis_me AS bigint) OR EXISTS(
                   SELECT 1 FROM community.share_group_members fm JOIN community.share_groups fg ON fg.id=fm.group_id
                   JOIN public.users fu ON fu.id=fm.user_id AND fu.is_active
                   WHERE fm.group_id=fs.group_id AND fm.user_id=CAST(:vis_me AS bigint) AND fg.deleted_at IS NULL))))
        SQL;
    $sql = '(' . $sql . ') AND ' . $formScope;
    return [$sql, array_merge([
        ':vis_me'     => (int) $u['id'],
        ':vis_share'  => (int) $u['id'],
        ':vis_author' => (int) $u['id'],
        // 「쓰기 자격이 있는 역할인가」·「Admin 인가」의 판단은 `Authz` 가 한다 — 이 파일에
        // 역할 문자열 비교가 생기지 않게 참·거짓만 받아 바인딩한다 (ARCH 4.1).
        ':vis_write' => Authz::canCommunityWrite($u) ? 'true' : 'false',
        ':vis_admin' => Authz::canAdmin($u) ? 'true' : 'false',
        // 팀이 없으면 NULL 이고, NULL 과의 `=` 는 참이 되지 않는다(팀 갈래가 통째로 꺼진다).
        ':vis_team'  => $u['team_id'] === null ? null : (int) $u['team_id'],
    ], $boardSBind, $boardPbBind, $modSBind, $modPbBind)];
}

/**
 * 「기준 범위」 SQL 식 (5-C) — 별칭 `p` 의 페이지가 게시 중이면 게시 전 범위, 아니면 범위 그대로.
 * `CommunityAuthz::baseVisibility()` 와 같은 뜻이다(판정 ↔ SQL 짝). 요청 값이 들어가는 자리가 없다.
 */
const COMMUNITY_BASE_VISIBILITY_SQL = "(CASE WHEN p.visibility = 'published' THEN p.published_from ELSE p.visibility END)";

/**
 * 게시판 등급 조건 (5-C) — 별칭 `$alias` 의 공간(게시판)을 이 사람이 **읽을 수 있나**를 SQL 로.
 *
 * 역할 × 등급 표를 새로 적지 않는다: 등급 넷을 하나씩 t-box `Authz::decideRead` 에 **물어서** 답을
 * 바인딩 값으로 만든다(`CommunityAuthz::decideBoardRead` 가 부르는 바로 그 함수 — 판정 ↔ SQL 짝).
 *   · 팀이 아닌 등급(public·internal·admin-only) — 읽을 수 있는 것만 쉼표로 이은 글자(`:vis_blv<n>`)
 *   · 팀 등급 — 「모든 팀」(`all_teams`, 있을 수 없는 팀 번호로 물어 참이면) · 「내 팀만」(`same_team`)
 * 이 파일에는 역할 문자열이 한 글자도 없다(ARCH 4.1). 등급 이름 넷은 DB CHECK 의 고정 목록이다.
 *
 * @param  string $alias `s`(게시판 글의 공간) 또는 `pb`(게시한 게시판) — 코드 리터럴만
 * @param  int    $n     자리표시자 번호(한 문장에 두 벌이 들어가므로 이름을 가른다)
 * @param  array<string,mixed> $u
 * @return array{0:string,1:array<string,mixed>}
 */
function community_board_read_sql(string $alias, int $n, array $u): array
{
    if (!in_array($alias, ['s', 'pb', 'b'], true) || $n < 1 || $n > 9) {
        throw new RuntimeException('community_board_read_sql: 허용되지 않는 식별자');
    }
    $plain = [];
    foreach (['public', 'internal', 'admin-only'] as $level) {
        if (Authz::decideRead($u, ['access_level' => $level, 'team_id' => null])[0]) {
            $plain[] = $level;
        }
    }
    // 있을 수 없는 팀 번호(-1)로 물어 참이면 「모든 팀의 팀 등급을 읽는 역할」이다(t-box `all_teams`).
    $teamAll = Authz::decideRead($u, ['access_level' => 'team', 'team_id' => -1])[0];
    $myTeam  = $u['team_id'] ?? null;
    $teamOwn = !$teamAll && $myTeam !== null
        && Authz::decideRead($u, ['access_level' => 'team', 'team_id' => (int) $myTeam])[0];

    $sql = "({$alias}.access_level = ANY(string_to_array(CAST(:vis_blv{$n} AS text), ','))"
         . " OR ({$alias}.access_level = 'team' AND CAST(:vis_ball{$n} AS boolean))"
         . " OR ({$alias}.access_level = 'team' AND {$alias}.team_id = CAST(:vis_bteam{$n} AS bigint)))";
    return [$sql, [
        ':vis_blv' . $n   => implode(',', $plain),
        ':vis_ball' . $n  => $teamAll ? 'true' : 'false',
        ':vis_bteam' . $n => $teamOwn ? (int) $myTeam : null,
    ]];
}

/**
 * 게시판 **관리자** 조건 (5-C) — `CommunityAuthz::decideBoardModerate` 와 짝.
 *   Admin 이면 참 · Manager 이고 팀 등급 게시판의 그 팀이면 참.
 *
 * @param  string $alias `s` 또는 `pb` — 코드 리터럴만
 * @param  array<string,mixed> $u
 * @return array{0:string,1:array<string,mixed>}
 */
function community_board_moderate_sql(string $alias, int $n, array $u): array
{
    if (!in_array($alias, ['s', 'pb', 'b'], true) || $n < 1 || $n > 9) {
        throw new RuntimeException('community_board_moderate_sql: 허용되지 않는 식별자');
    }
    $admin   = Authz::canAdmin($u);
    $modTeam = !$admin && Authz::authorize('role:Manager+', $u) && ($u['team_id'] ?? null) !== null
        ? (int) $u['team_id'] : null;
    $sql = "(CAST(:vis_madm{$n} AS boolean)"
         . " OR ({$alias}.access_level = 'team' AND {$alias}.team_id = CAST(:vis_mteam{$n} AS bigint)))";
    return [$sql, [
        ':vis_madm' . $n  => $admin ? 'true' : 'false',
        ':vis_mteam' . $n => $modTeam,
    ]];
}

/**
 * 검색(5-B)이 쓰는 조건절 — 목록 조건절과 같되 **특권 갈래를 끈다** (5-C 에서 한 곳으로 모았다).
 *   · 관리자 특권 갈래(`admin_all`) — 남의 비공개 글이 검색 한 번에 한꺼번에 드러나지 않게(5-B)
 *   · 게시판 관리자 갈래(`board_moderator`) — 숨긴 글은 **검색에도 없다**(지시서 완료 기준)
 *
 * @param  array<string,mixed> $u
 * @return array{0:string,1:array<string,mixed>}
 */
function community_search_pages_where(array $u): array
{
    [$sql, $bind] = community_visible_pages_where($u);
    $bind[':vis_admin'] = 'false';
    foreach ([1, 2] as $n) {
        $bind[':vis_madm' . $n]  = 'false';
        $bind[':vis_mteam' . $n] = null;
    }
    return [$sql, $bind];
}

/* ══════════════════════════════════════════════════════════════════
 * Q:F-2 통합 검색 — 색인 글자 · 찾기 SQL · 경로 묶음 (지시서 docs/qf2-unified-search-directive.md 2장)
 *
 * 판정은 **새로 만들지 않는다**(2.6): 페이지는 `community_search_pages_where`(관리자·게시판 관리자 갈래 끔)
 * 그대로, 댓글은 그 조건절로 페이지를 먼저 거른 뒤 그 페이지의 댓글만(삭제·숨김 제외). 이 절이 더하는 것은
 * 「무엇이 맞나」(색인 + ILIKE 다시 확인)뿐이다.
 * ══════════════════════════════════════════════════════════════════ */

/*
 * Q:F-2 — 저장이 태그를 읽기 **전에** 이 페이지 행을 잠근다. 태그 고치기(pages_tags_put)도 같은 행을 먼저 잠그므로
 * 두 쓰기가 한 줄로 선다 — 저장이 커밋 전 옛 태그를 읽어 색인을 쓰고, 그 사이 커밋된 새 태그가 색인에서 빠지는 일이 없다.
 * 잠금은 `FOR NO KEY UPDATE`(키를 바꾸지 않는 UPDATE 가 잡는 것과 같은 세기) — 저장·태그 고치기끼리는 부딪히지만,
 * 이 페이지를 외래 키로 가리키는 쓰기(댓글 · 하위 페이지 · 공유 · 알림)의 KEY SHARE 와는 부딪히지 않는다. `FOR UPDATE`
 * 였으면 자동 저장 트랜잭션이 끝날 때까지 그 쓰기들이 줄을 섰다(검토 N-1).
 */
const COMMUNITY_PAGE_LOCK_SQL = <<<'SQL'
    SELECT 1 FROM community.pages WHERE id = CAST(:pid AS bigint) FOR NO KEY UPDATE
    SQL;

/**
 * 페이지 색인 글자 — 저장하는 다섯 곳(pages_create · pages_update · board_pages_create · space_me ·
 * version_restore)과 태그를 고치는 곳(pages_tags_put)이 **이 함수 하나만** 부른다. SQL 은
 * `strip(to_tsvector('simple', :tsv))`. 제목 + 본문 + 태그 이름(J-4)을 한 벡터로.
 *
 * @param list<string> $tags
 */
function community_search_text(string $title, string $plain, array $tags = [], string $where = ''): string
{
    // 순서는 제목 → 태그 → 본문 — 토큰 상한(20,000)에서 잘릴 때 짧고 중요한 제목·태그가 먼저 들어가 남는다
    return SearchText::indexText(array_merge([$title], array_values($tags), [$plain]), $where);
}

/*
 * 찾기 SQL 의 고정 조각 — 바뀌는 것은 낱말 번호(`@N@`, 정수) 하나뿐이다(`community_share_rank_sql` 과 같은 방식).
 * 요청 글자는 한 글자도 조각에 들어가지 않는다 — 값은 전부 `:s?N` 바인딩(S-04).
 *   TITLE        제목에 이 낱말이 있나(정렬 「제목에 맞은 것 먼저」 — 모든 낱말이 제목에 있을 때 참)
 *   PAGE_RAW     제목·본문·태그 원문 ILIKE (지금 검색과 같은 뜻 — 영문·숫자만인 검색은 이것뿐)
 *   PAGE_NFC     NFC 로 합친 원문 ILIKE — 맥 NFD 로 적힌 글도 조합한 검색어로(한글이 든 검색에서만)
 *   PAGE_HANGUL  한글 음절만인 낱말 — 대소문자가 없어 ILIKE 와 같은 뜻인 글자열 찾기(strpos)로(원문 · NFC 둘 다)
 *   COMMENT_*    댓글 본문에 같은 규칙
 */
const COMMUNITY_SEARCH_TITLE_SQL = <<<'SQL'
p.title ILIKE :st@N@ ESCAPE '#'
SQL;
const COMMUNITY_SEARCH_PAGE_RAW_SQL = <<<'SQL'
p.title ILIKE :sa@N@ ESCAPE '#' OR p.plain_text ILIKE :sb@N@ ESCAPE '#'
 OR EXISTS (SELECT 1 FROM community.page_tags pt WHERE pt.page_id = p.id AND pt.tag ILIKE :sc@N@ ESCAPE '#')
SQL;
const COMMUNITY_SEARCH_PAGE_NFC_SQL = <<<'SQL'
 OR normalize(p.title || E'\n' || p.plain_text, NFC) ILIKE :sd@N@ ESCAPE '#'
 OR EXISTS (SELECT 1 FROM community.page_tags pn WHERE pn.page_id = p.id AND normalize(pn.tag, NFC) ILIKE :se@N@ ESCAPE '#')
SQL;
const COMMUNITY_SEARCH_PAGE_HANGUL_SQL = <<<'SQL'
strpos(p.title, :sa@N@) > 0 OR strpos(p.plain_text, :sb@N@) > 0
 OR EXISTS (SELECT 1 FROM community.page_tags pt WHERE pt.page_id = p.id AND strpos(pt.tag, :sc@N@) > 0)
 OR strpos(normalize(p.title || E'\n' || p.plain_text, NFC), :sd@N@) > 0
 OR EXISTS (SELECT 1 FROM community.page_tags pn WHERE pn.page_id = p.id AND strpos(normalize(pn.tag, NFC), :se@N@) > 0)
SQL;
const COMMUNITY_SEARCH_COMMENT_RAW_SQL = <<<'SQL'
c.body ILIKE :sa@N@ ESCAPE '#'
SQL;
const COMMUNITY_SEARCH_COMMENT_NFC_SQL = <<<'SQL'
 OR normalize(c.body, NFC) ILIKE :sd@N@ ESCAPE '#'
SQL;
const COMMUNITY_SEARCH_COMMENT_HANGUL_SQL = <<<'SQL'
strpos(c.body, :sa@N@) > 0 OR strpos(normalize(c.body, NFC), :sd@N@) > 0
SQL;

/*
 * 페이지 찾기 문장 앞뒤에 거는 계획 설정 — **같은 트랜잭션 안에서만**(SET LOCAL, 끝나면 저절로 풀린다).
 * 페이지 벡터는 평균 8KB 라 TOAST 밖에 산다. 아주 흔한 말(한 글자 「회」「의」 — 문서 절반 넘게)에서 계획기가 순차 읽기 +
 * `@@` 거르기를 고르면 줄마다 벡터를 풀어 1초가 넘었다(S1 실측 1,027ms). 비트맵 색인 읽기는 정확한 줄 번호만 넘겨 벡터를
 * 풀지 않는다(136ms). 계획기의 비용 모형이 「벡터 풀기」를 모르므로 이 문장에서만 순차 읽기를 끈다 — 판정·결과는 같다.
 * 댓글 문장에는 걸지 않는다(댓글 벡터는 작아 표 안에 있고, 거기서는 순차 읽기가 더 빨랐다).
 */
const COMMUNITY_SEARCH_PAGES_PLAN_ON  = 'SET LOCAL enable_seqscan = off';
const COMMUNITY_SEARCH_PAGES_PLAN_OFF = 'SET LOCAL enable_seqscan = on';

/**
 * 찾기 SQL 한 벌 — 화면 창구(search.php)와 성능 시험(tests/perf/qf2_search_perf.sh 의 EXPLAIN)이 **같은 문장**을 쓴다.
 *
 *   $kind  'pages' | 'comments' (코드 리터럴만)
 *   $terms SearchText::queryTerms() 의 결과(비어 있지 않다)
 *
 * 규칙 (지시서 2.2)
 *   · 한글이 든 검색 — `search_tsv @@ to_tsquery('simple', :tq)`(색인) AND 기존 범위 조건절 AND 낱말마다 다시 확인.
 *     다시 확인은 원문이 먼저, 안 맞으면 NFC 로 합친 원문(`normalize(…, NFC)` — PG 13+ 내장, 확장 아님)으로
 *     한 번 더 — 맥 NFD 로 적힌 글도 조합한 검색어로 찾는다(색인도 조합한 글자로 만든다, ①).
 *     한글만 1~2글자인 낱말은 다시 확인하지 않는다(색인 토큰이 곧 그 글자열 — `SearchText::queryTerms`).
 *   · 한글이 없는 검색(영문·숫자만) — tsquery 없이 **지금과 같은 ILIKE**(색인 없음, J-10 ⓑ).
 *   · 여러 낱말은 AND(낱말마다 제목·본문·태그 중 어디든).
 *
 * 문장 모양 — **정렬 먼저, 판정은 필요한 만큼** (S1 실측 — 구현 기록 5장)
 *   ① 후보(색인에 맞은 살아 있는 행)를 싼 칸(id · 제목 · 고친 때)으로만 정렬한다 — 본문을 풀지 않는다.
 *   ② 그 순서대로 한 줄씩 **범위 조건절**(`community_search_pages_where` — 글자 그대로)과 다시 확인을 매기고, 20줄이 차면 멈춘다
 *      (`LATERAL … OFFSET 0` — 줄마다 부르는 안쪽 질의라 먼저 다 매기지 않는다).
 *   판정 조건절 자체는 한 글자도 바꾸지 않는다(새 권한 코드 0) — 매기는 **줄 수**만 준다. 뜻(무엇이 나오고 어떤 순서인가)은
 *   「다 매긴 뒤 정렬 · 20건」과 같다.
 * 결과 전체 개수는 세지 않는다(2.6). 값은 전부 바인딩이고 SQL 조각은 위 고정 글자다(S-04).
 * 같은 자리표시자를 두 번 쓰지 않는다(서버측 prepare — Db.php) — 낱말마다 이름을 번호로 가른다.
 *
 * @param  array<string,mixed> $u
 * @param  list<array{text:string,tokens:list<string>,recheck:bool,hangul?:bool}> $terms
 * @return array{0:string,1:array<string,mixed>}
 */
function community_search_sql(array $u, array $terms, string $kind): array
{
    if (($kind !== 'pages' && $kind !== 'comments') || $terms === [] || count($terms) > 60) {
        throw new RuntimeException('community_search_sql: 허용되지 않는 인자');
    }
    [$visible, $bind] = community_search_pages_where($u);
    $tq = SearchText::tsquery($terms);
    $pages = $kind === 'pages';

    $conds = [];
    $inTitle = [];
    foreach (array_values($terms) as $n => $t) {
        $text = (string) $t['text'];
        $like = SearchText::likePattern($text);
        $num  = ['@N@' => (string) (int) $n];
        if ($pages) {
            $bind[':st' . $n] = $like;
            $inTitle[] = strtr(COMMUNITY_SEARCH_TITLE_SQL, $num);
        }
        if ($tq !== null && !$t['recheck']) {
            continue;
        }
        // 한글 음절만인 낱말(3글자 이상)은 글자열 찾기 — 값은 낱말 그대로, 아니면 ILIKE 무늬
        $hangul = $tq !== null && ($t['hangul'] ?? false) === true;
        $val = $hangul ? $text : $like;
        $bind[':sa' . $n] = $val;
        if ($pages) {
            $bind[':sb' . $n] = $val;
            $bind[':sc' . $n] = $val;
        }
        if ($hangul) {
            $bind[':sd' . $n] = $val;
            if ($pages) {
                $bind[':se' . $n] = $val;
            }
            $c = strtr($pages ? COMMUNITY_SEARCH_PAGE_HANGUL_SQL : COMMUNITY_SEARCH_COMMENT_HANGUL_SQL, $num);
        } else {
            $c = strtr($pages ? COMMUNITY_SEARCH_PAGE_RAW_SQL : COMMUNITY_SEARCH_COMMENT_RAW_SQL, $num);
            if ($tq !== null) {
                $bind[':sd' . $n] = $like;
                if ($pages) {
                    $bind[':se' . $n] = $like;
                }
                $c .= strtr($pages ? COMMUNITY_SEARCH_PAGE_NFC_SQL : COMMUNITY_SEARCH_COMMENT_NFC_SQL, $num);
            }
        }
        $conds[] = '(' . $c . ')';
    }
    $match = $conds === [] ? 'TRUE' : implode("\n               AND ", $conds);
    $idx   = 'TRUE';
    if ($tq !== null) {
        $idx = ($pages ? 'p' : 'c') . ".search_tsv @@ to_tsquery('simple', CAST(:tq AS text))";
        $bind[':tq'] = $tq;
    }

    if ($pages) {
        $titleAll = implode(' AND ', $inTitle);
        // `NOT (board AND hidden)` — 5-C: 숨긴 게시판 글은 검색에도 없다(쓴 사람에게도 — 숨긴 글은 주소로만 연다)
        $sql = <<<SQL
            SELECT v.id, v.public_id, v.title, v.icon, v.space_id, v.space_kind, v.space_name, h.in_title,
                   left(v.plain_text, 20000) AS body
              FROM (SELECT p.id, p.updated_at,
                           ({$titleAll}) AS in_title
                      FROM community.pages p
                     WHERE p.deleted_at IS NULL
                       AND {$idx}
                     ORDER BY 3 DESC, p.updated_at DESC, p.id DESC
                    OFFSET 0) h
             CROSS JOIN LATERAL (
                SELECT p.id, p.public_id, p.title, p.icon, p.space_id, p.plain_text,
                       s.kind AS space_kind, s.name AS space_name
                  FROM community.pages p
                  JOIN community.spaces s ON s.id = p.space_id
                  LEFT JOIN public.users owner ON owner.id = s.owner_user_id
                 WHERE p.id = h.id
                   AND {$visible}
                   AND NOT (s.kind = 'board' AND p.hidden_at IS NOT NULL)
                   AND {$match}
                OFFSET 0) v
             ORDER BY h.in_title DESC, h.updated_at DESC, h.id DESC
             LIMIT 20
            SQL;
        return [$sql, $bind];
    }

    // ─── 댓글 (2.3) — 볼 수 있는 페이지의 살아 있는 · 숨기지 않은 댓글(J-5)
    /* 게시판 등급으로만 보는 사람에게 댓글 목록은 **게시 뒤** 댓글만 준다(comments_list 의 `since` — 5-C #9).
       검색은 그 갈래를 가려낼 판정을 새로 적지 않고 **좁은 쪽**으로 간다: 게시 중인 개인 페이지에서는
       공간 주인이 아니면 게시 전에 쓴 댓글(과 게시 전 댓글에 단 답글)을 검색에서 뺀다. 목록에서는 보이는
       사람(공유·팀)이 검색에서 덜 보는 것은 있어도, 목록에서 못 보는 댓글이 검색에 나오는 일은 없다. */
    $bind[':sme'] = (int) $u['id'];
    $sql = <<<SQL
        SELECT v.comment_id, left(v.body, 2000) AS body, v.created_at, v.author_username, v.author_display_name,
               v.id, v.public_id, v.title, v.icon, v.space_id, v.space_kind, v.space_name
          FROM (SELECT c.id, c.created_at
                  FROM community.comments c
                 WHERE c.deleted_at IS NULL
                   AND c.hidden_at IS NULL
                   AND {$idx}
                 ORDER BY c.created_at DESC, c.id DESC
                OFFSET 0) h
         CROSS JOIN LATERAL (
            SELECT c.public_id AS comment_id, c.body, c.created_at,
                   cu.username AS author_username, cu.display_name AS author_display_name,
                   p.id, p.public_id, p.title, p.icon, p.space_id, s.kind AS space_kind, s.name AS space_name
              FROM community.comments c
              JOIN community.pages p ON p.id = c.page_id
              JOIN community.spaces s ON s.id = p.space_id
              LEFT JOIN public.users owner ON owner.id = s.owner_user_id
              JOIN public.users cu ON cu.id = c.author_id
              LEFT JOIN community.comments par ON par.id = c.parent_comment_id
             WHERE c.id = h.id
               AND p.deleted_at IS NULL
               AND {$visible}
               AND NOT (s.kind = 'board' AND p.hidden_at IS NOT NULL)
               AND NOT (s.kind = 'personal' AND p.visibility = 'published'
                        AND s.owner_user_id IS DISTINCT FROM CAST(:sme AS bigint)
                        AND (c.created_at < p.published_at OR coalesce(par.created_at < p.published_at, false)))
               AND {$match}
            OFFSET 0) v
         ORDER BY h.created_at DESC, h.id DESC
         LIMIT 20
        SQL;
    return [$sql, $bind];
}

/**
 * 여러 페이지의 경로 줄을 **한 문장**으로 (N+1 제거 — 지시서 2.2 ⑥). 뜻은 `community_breadcrumb()` 와 같다:
 * 맨 앞이 뿌리, 맨 뒤가 자기 자신, 자기에서 위로 올라가다 **처음 보이지 않는 조상에서 자른다**
 * (조상마다 목록과 같은 조건절 `community_visible_pages_where` — 한 장씩 부르던 때와 같은 판정).
 *
 * @param  list<array{id:int,space_id:int}> $pages
 * @param  array<string,mixed> $u
 * @return array<int,list<array{id:string,title:string,icon:?string}>> 페이지 번호 → 경로
 */
function community_breadcrumbs_many(array $pages, array $u): array
{
    $ids = [];
    foreach ($pages as $pg) {
        $ids[(int) $pg['id']] = true;
    }
    if ($ids === []) {
        return [];
    }
    [$where, $visBind] = community_visible_pages_where($u);
    $sql = <<<SQL
        WITH RECURSIVE up AS (
            SELECT p.id AS start_id, p.id, p.parent_page_id, p.space_id, p.public_id, p.title, p.icon, 0 AS lvl
              FROM community.pages p
             WHERE p.id = ANY (CAST(:ids AS bigint[]))
               AND p.deleted_at IS NULL
            UNION ALL
            SELECT up.start_id, parent.id, parent.parent_page_id, parent.space_id, parent.public_id,
                   parent.title, parent.icon, up.lvl + 1
              FROM community.pages parent
              JOIN up ON parent.id = up.parent_page_id
             WHERE parent.space_id = up.space_id
               AND parent.deleted_at IS NULL
        )
        SELECT up.start_id, up.public_id, up.title, up.icon, up.lvl,
               EXISTS (SELECT 1 FROM community.pages p
                         JOIN community.spaces s ON s.id = p.space_id
                         LEFT JOIN public.users owner ON owner.id = s.owner_user_id
                        WHERE p.id = up.id
                          AND {$where}) AS visible
          FROM up ORDER BY up.start_id, up.lvl ASC
        SQL;
    $rows = Db::instance()->run($sql, array_merge(
        [':ids' => '{' . implode(',', array_map('intval', array_keys($ids))) . '}'],
        $visBind
    ))->fetchAll();

    $chains = [];
    $cut = [];
    foreach ($rows as $r) {
        $sid = (int) $r['start_id'];
        if (isset($cut[$sid])) {
            continue;
        }
        if ((int) $r['lvl'] > 0 && $r['visible'] !== true) {
            $cut[$sid] = true;
            continue;
        }
        $chains[$sid][] = [
            'id'    => (string) $r['public_id'],
            'title' => (string) $r['title'],
            'icon'  => $r['icon'] === null ? null : (string) $r['icon'],
        ];
    }
    $out = [];
    foreach ($chains as $sid => $chain) {
        $out[$sid] = array_reverse($chain);
    }
    return $out;
}

/**
 * 이 페이지 아래에서 **범위가 새 값과 다른** 페이지 전부 (「하위 페이지까지 함께」, 2026-09-23 정정).
 *
 * 전에는 cascade 가 「더 넓은 하위를 좁히기」에만 쓰여, 넓힐 때 켜도 하위가 그대로였다
 * (사용자 지적 「하위까지 함께를 체크해도 적용되지 않음」). 이제 켜면 넓히든 좁히든 하위 전부가
 * 같은 값이 된다 — 전부 부모와 같으므로 「자식은 부모보다 넓을 수 없다」도 그대로 지켜진다.
 *
 * @return list<array{id:int,public_id:string,title:string,visibility:string}>
 */
function community_descendants_differ(int $pageId, string $newVisibility): array
{
    $sql = <<<'SQL'
        WITH RECURSIVE down AS (
            SELECT p.id FROM community.pages p
             WHERE p.id = CAST(:pid AS bigint) AND p.deleted_at IS NULL
            UNION ALL
            SELECT c.id FROM community.pages c
              JOIN down d ON c.parent_page_id = d.id
             WHERE c.deleted_at IS NULL
        )
        SELECT p.id, p.public_id, p.title,
               (CASE WHEN p.visibility = 'published' THEN p.published_from ELSE p.visibility END) AS visibility,
               (p.visibility = 'published') AS is_published
          FROM community.pages p
          JOIN down ON down.id = p.id
         WHERE p.id <> CAST(:pid2 AS bigint)
           AND p.deleted_at IS NULL
           AND (CASE WHEN p.visibility = 'published' THEN p.published_from ELSE p.visibility END) <> :vis
         ORDER BY p.id
         LIMIT 2000
        SQL;

    if (CommunityAuthz::visibilityRank($newVisibility) === null) {
        return [];
    }

    $out = [];
    foreach (Db::instance()->run($sql, [':pid' => $pageId, ':pid2' => $pageId, ':vis' => $newVisibility])->fetchAll() as $r) {
        $out[] = [
            'id'         => (int) $r['id'],
            'public_id'  => (string) $r['public_id'],
            'title'      => (string) $r['title'],
            // 5-C — 기준 범위(게시 중이면 게시 전 범위). 게시 중인 하위는 게시를 그대로 두고 이 값만 바뀐다.
            'visibility' => (string) $r['visibility'],
            'is_published' => $r['is_published'] === true,
        ];
    }
    return $out;
}

/**
 * 이 페이지 **아래에서 더 넓은** 페이지들 (4-B `children_wider` · `cascade`).
 *
 * 재귀 CTE **한 번**으로 후손 전부를 모으고, 새 범위보다 넓은 것만 돌려준다.
 * 자기 자신은 포함하지 않는다.
 *
 * @return list<array{id:int,public_id:string,title:string,visibility:string}>
 */
function community_descendants_wider(int $pageId, string $newVisibility): array
{
    $sql = <<<'SQL'
        WITH RECURSIVE down AS (
            SELECT p.id FROM community.pages p
             WHERE p.id = CAST(:pid AS bigint) AND p.deleted_at IS NULL
            UNION ALL
            SELECT c.id FROM community.pages c
              JOIN down d ON c.parent_page_id = d.id
             WHERE c.deleted_at IS NULL
        )
        SELECT p.id, p.public_id, p.title,
               (CASE WHEN p.visibility = 'published' THEN p.published_from ELSE p.visibility END) AS visibility
          FROM community.pages p
          JOIN down ON down.id = p.id
         WHERE p.id <> CAST(:pid2 AS bigint)
           AND p.deleted_at IS NULL
           -- 5-C: 기준 범위로 견준다(게시는 트리의 넓이가 아니다 — `CommunityAuthz::baseVisibility`)
           AND (CASE (CASE WHEN p.visibility = 'published' THEN p.published_from ELSE p.visibility END)
                     WHEN 'private' THEN 0 WHEN 'team' THEN 1 WHEN 'internal' THEN 2 END)
               > CAST(:rank AS int)
         ORDER BY p.id
         LIMIT 2000
        SQL;

    $rank = CommunityAuthz::visibilityRank($newVisibility);
    if ($rank === null) {
        return [];
    }

    $out = [];
    foreach (Db::instance()->run($sql, [':pid' => $pageId, ':pid2' => $pageId, ':rank' => $rank])->fetchAll() as $r) {
        $out[] = [
            'id'         => (int) $r['id'],
            'public_id'  => (string) $r['public_id'],
            'title'      => (string) $r['title'],
            'visibility' => (string) $r['visibility'],
        ];
    }
    return $out;
}

/* ══════════════════════════════════════════════════════════════════
 * 기록 (4-C) — 판 스냅샷 · 5분 합치기 · 체크 변화
 *
 * 규칙 하나: **기록은 지울 수 없다** (C:S-14). 앱 계정에는 `page_versions`·`checklist_events`
 * 에 UPDATE·DELETE 가 없고(`db/community/grants.sql`), 아래 함수들도 INSERT 와 SELECT 만 쓴다.
 * 되돌리기는 과거를 고치지 않는다 — 지금 판을 스냅샷으로 남기고 **새 판을 하나 더** 만든다.
 *
 * 시각에 대하여 — `page_versions.edited_at` 은 **그 판이 스냅샷으로 남은 시각**이다
 * (3단계부터 `DEFAULT now()` 로 채워 온 값 그대로다). 5분 합치기가 보는 값도 이것이고,
 * 이력 목록의 「시각」도 이것이다 — 「이 판이 기록에 남은 때」라는 한 가지 뜻이다.
 * 마이그레이션 `2026-09-23_community_version_squash.sql` 이 더하는 `created_at` 은 같은 뜻을
 * **자기 이름으로** 들고 있는 칸이고, 4-C 런타임은 그 칸을 읽지도 쓰지도 않는다(기본값이
 * 스스로 채운다) — 그래서 이 코드는 마이그레이션 **전후 모두 그대로 동작한다**.
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 이 페이지의 **직전 스냅샷** 한 줄 + DB 의 지금 시각 (5분 합치기 판정의 재료).
 *
 * 시각 둘(`snapshot_at`·`now_at`)을 **같은 질의**에서 가져온다 — 웹 서버와 DB 의 시계가
 * 어긋나 있어도 판정이 흔들리지 않게 하려는 것이다. 질의는 `UNIQUE(page_id, version)` 이
 * 만든 btree 를 뒤로 훑는다(`Index Scan Backward`) — 같은 열 짝의 인덱스를 새로 만들지 않았다.
 *
 * @return array{version:int,edited_by:int,snapshot_at:string,now_at:string}|null 스냅샷이 없으면 null
 */
function community_last_snapshot(int $pageId): ?array
{
    $sql = <<<'SQL'
        SELECT v.version, v.edited_by, v.edited_at AS snapshot_at, now() AS now_at
          FROM community.page_versions v
         WHERE v.page_id = CAST(:pid AS bigint)
         ORDER BY v.version DESC
         LIMIT 1
        SQL;

    $row = Db::instance()->run($sql, [':pid' => $pageId])->fetch();
    if (!is_array($row)) {
        return null;
    }
    return [
        'version'     => (int) $row['version'],
        'edited_by'   => (int) $row['edited_by'],
        'snapshot_at' => (string) $row['snapshot_at'],
        'now_at'      => (string) $row['now_at'],
    ];
}

/**
 * **5분 합치기** — 이번 저장에서 스냅샷을 새로 만들지 말아야 하나 (C:F-19, C:T-38).
 *
 * 규칙 한 줄: 직전 스냅샷이 **같은 사람**이고 **5분 안**에 남은 것이면 새 스냅샷을 만들지
 * 않는다. 그 사이의 변화는 다음 스냅샷에 합쳐진다. 판 번호(`doc_version`)는 합치든 말든
 * **매번 오른다** — 낙관적 잠금이 그 번호에 걸려 있기 때문이다(번호를 멈추면 두 창이 서로를
 * 덮어쓴다).
 *
 * 왜 이렇게 나눴나: 이 함수는 **DB 를 읽지 않는다.** 재료(직전 스냅샷 한 줄)는 부르는 쪽이
 * 가져오고 여기서는 판단만 한다 — 그래야 규칙 하나를 `php -r` 한 줄로 시험할 수 있다
 * (`tests/community/stage4c.sh` 의 단위 확인, C:T-38).
 *
 * 모르면 **합치지 않는다**(스냅샷을 남긴다). 기록을 잃는 쪽보다 하나 더 남는 쪽이 낫다.
 *
 * @param array{edited_by?:mixed,snapshot_at?:mixed,now_at?:mixed}|null $last `community_last_snapshot()`
 * @param int $editorId 이번에 스냅샷으로 남을 **내용을 쓴 사람**(`pages.last_edited_by`)
 * @param int $window   합치는 창 (초). 기본 300 = 5분
 */
function community_squash_snapshot(?array $last, int $editorId, int $window = 300): bool
{
    if ($last === null || !isset($last['edited_by'], $last['snapshot_at'], $last['now_at'])) {
        return false;
    }
    if ((int) $last['edited_by'] !== $editorId) {
        return false;          // 사람이 다르면 언제나 새 스냅샷이다
    }

    $then = strtotime((string) $last['snapshot_at']);
    $now  = strtotime((string) $last['now_at']);
    if ($then === false || $now === false) {
        return false;          // 시각을 읽지 못하면 합치지 않는다
    }
    if ($then > $now) {
        return false;          // 앞뒤가 뒤집힌 값 — 모르는 상태다
    }
    return $then > $now - $window;
}

/**
 * 지금의 페이지 내용을 **스냅샷으로 남긴다** (`page_versions` INSERT 하나).
 *
 * 남는 판 번호는 **지금 판**(`doc_version`)이다 — 부르는 쪽이 곧바로 `doc_version + 1` 로
 * 올리므로, 이 행은 「방금 교체된 판」의 사본이 된다. 반드시 부르는 쪽의 트랜잭션 안에서
 * 부른다(본문 갱신과 기록이 갈라지지 않게).
 *
 * 시각 칸(`edited_at`, 그리고 마이그레이션 뒤의 `created_at`)은 **적지 않는다** — DB 의
 * `DEFAULT now()` 가 채운다. 「스냅샷이 남은 시각」은 DB 가 아는 사실이고, 웹 서버의 시계를
 * 그 자리에 적어 넣을 이유가 없다.
 *
 * @param array<string,mixed> $row     `community_page_row()` 의 행 (갱신 **전**의 값)
 * @param string|null         $summary 이 판이 어떻게 끝났나. 되돌리기면 `restore from v<번호>`
 */
function community_insert_snapshot(array $row, ?string $summary = null): void
{
    $sql = <<<'SQL'
        INSERT INTO community.page_versions (page_id, version, title, doc, edited_by, summary)
        VALUES (CAST(:pid AS bigint), CAST(:version AS int), :title, CAST(:doc AS jsonb),
                CAST(:by AS bigint), :summary)
        SQL;

    Db::instance()->run($sql, [
        ':pid'     => (int) $row['id'],
        ':version' => (int) $row['doc_version'],
        ':title'   => (string) $row['title'],
        ':doc'     => (string) $row['doc'],
        // 그 판의 **내용을 쓴 사람**이다(지금 저장 단추를 누른 사람이 아니다).
        ':by'      => (int) $row['last_edited_by'],
        ':summary' => $summary,
    ]);
}

/**
 * 체크 상자의 **변화**만 골라낸다 (C:F-20, C:T-41).
 *
 * 이전 문서의 `to_do` 상태와 견준다. 없던 블록이 **켜진 채로** 생겼으면 그것도 「켰다」로
 * 남긴다 — 지울 수 없는 기록이므로 「어느 순간 켜져 있었다」는 사실이 빠지지 않아야 한다.
 * 지워진 블록은 남기지 않는다(꺼진 것이 아니라 사라진 것이다).
 *
 * 저장(`pages_update`)과 되돌리기(`version_restore`)가 **같은 함수**를 쓴다 — 되돌리기로
 * 체크가 풀린 것도 체크 이력에 남아야 하고, 규칙이 두 벌이 되면 한쪽만 고쳐진다.
 *
 * @param  array<string,mixed> $prevDoc 바뀌기 전 문서
 * @param  array<string,mixed> $nextDoc 바뀐 뒤 문서
 * @return list<array{block_id:string,checked:bool}>
 */
function community_check_changes(array $prevDoc, array $nextDoc): array
{
    $before = [];
    foreach (Doc::extract($prevDoc)['todos'] as $t) {
        $before[$t['block_id']] = $t['checked'];
    }

    $changes = [];
    foreach (Doc::extract($nextDoc)['todos'] as $t) {
        $was = $before[$t['block_id']] ?? false;
        if ($was !== $t['checked']) {
            $changes[] = ['block_id' => $t['block_id'], 'checked' => $t['checked']];
        }
    }
    return $changes;
}

/**
 * 체크 변화를 `checklist_events` 에 **덧붙인다**(INSERT 만 — 고치지도 지우지도 않는다).
 * 부르는 쪽의 트랜잭션 안에서 부른다.
 *
 * @param list<array{block_id:string,checked:bool}> $changes
 */
function community_insert_check_events(int $pageId, array $changes, int $byUserId): void
{
    if ($changes === []) {
        return;
    }
    $sql = <<<'SQL'
        INSERT INTO community.checklist_events (page_id, block_id, checked, by_user_id)
        VALUES (CAST(:pid AS bigint), CAST(:bid AS uuid), CAST(:checked AS boolean), CAST(:by AS bigint))
        SQL;

    $db = Db::instance();
    foreach ($changes as $c) {
        $db->run($sql, [
            ':pid'     => $pageId,
            ':bid'     => $c['block_id'],
            ':checked' => $c['checked'] ? 'true' : 'false',
            ':by'      => $byUserId,
        ]);
    }
}

/**
 * 이력 창구의 **공통 관문** (4-C) — 페이지 한 건을 읽고 「볼 수 있나」까지 끝낸다.
 *
 * 왜 여기인가: `versions_list`·`version_get`·`history`·`version_restore` 넷이 **글자 그대로
 * 같은** 스무 줄로 시작한다. 그 스무 줄이 네 벌이면 언젠가 한 벌만 고쳐지고, 그 한 벌이
 * 남의 이력을 열어 준다 (C:T-42). 새 파일을 만들지 않고 모듈의 입구 파일에 둔다 —
 * `community_page_row()`·`community_rewrite_derived()` 와 같은 자리다.
 *
 * 하는 일
 *   [1] 행 + 공간 → 없으면 **404**
 *   [권한 ✔] `decidePageRead` — 지워짐 404 · 남의 개인 페이지 **403 + 감사 `page.read deny not_owner`**
 *            · 관리자·같은 팀이라서 열렸으면 그 근거를 감사에 남긴다(`pages_get.php` 와 같은 규칙)
 *   [2] 「고칠 수 있나」도 함께 판단해 돌려준다 — 화면의 「되돌리기」 단추가 그 값을 따른다
 *
 * 실패하면 **돌아오지 않는다**(응답을 내보내고 끝난다).
 *
 * @param  array<string,mixed> $u
 * @return array{row:array<string,mixed>,read_code:string,may_change:bool,change_code:string,since:?string}
 */
function community_history_gate(array $u, string $publicId): array
{
    $row = community_page_row($publicId, (int) $u['id']);
    if ($row === null) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $pageTitle = community_target_title((string) $row['title']);

    [$mayRead, $readCode] = CommunityAuthz::decidePageRead($u, $row);
    if (!$mayRead) {
        if ($readCode === CommunityAuthz::CODE_NOT_FOUND) {
            Http::error(404, Http::MSG_NOT_FOUND);
        }
        if ($readCode === CommunityAuthz::CODE_NOT_OWNER) {
            Audit::deny('page.read', CommunityAuthz::CODE_NOT_OWNER, [
                'target_type' => 'page',
                'target_id'   => (int) $row['id'],
                'target_name' => $pageTitle,
                'detail'      => ['page_title' => $pageTitle],
            ]);
            Http::error(403, Http::MSG_FORBIDDEN);
        }
        /* 문장 틀이 없는 조합(게시판 공간·`published`·팀원이 아닌 팀 공간)이다. 판정은 이미
           「막는다」로 끝났고, 문장 없는 기록은 요청을 500 으로 끝내므로 서버 로그에만 남긴다
           (`pages_get.php` 와 같은 처리). */
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID
            . ' community page.read deny code=' . $readCode . ' (문장 없는 조합, 이력)');
        Http::error(403, Http::MSG_FORBIDDEN);
    }

    /* 남의 개인 페이지를 **무엇을 근거로** 열었나 (PRD 4.1, S-07). 이력도 그 페이지의 내용에
       딸린 기록이므로 본문을 여는 것과 같은 무게로 남긴다. 자기 페이지·사내 공개는 남기지
       않는다 — 그것까지 남기면 진짜 사건이 묻힌다. */
    if ($readCode === CommunityAuthz::CODE_ADMIN_ALL || $readCode === CommunityAuthz::CODE_SAME_TEAM
        || ($readCode === CommunityAuthz::CODE_BOARD_MODERATOR && (string) $row['space_kind'] === 'personal')) {
        Audit::allow('page.read', [
            'target_type' => 'page',
            'target_id'   => (int) $row['id'],
            'target_name' => $pageTitle,
            'detail'      => ['granted_by' => $readCode, 'page_title' => $pageTitle],
        ], $readCode);
    }

    [$mayChange, $changeCode] = CommunityAuthz::decidePageChange($u, $row);

    /* 5-C (사용자 기본안 — USER-CHECKS 5-C #9, 2026-09-23): **게시판 등급으로만** 보는 사람(`board`)에게는
       게시한 뒤의 기록만 보인다. 게시 전(비공개 시절) 판·체크·속성 기록은 게시와 함께 드러나지 않는다 —
       초안이 공개되면 되돌릴 수 없으므로 좁은 쪽. 원래 범위·공유·주인·관리자는 전과 같이 전부 본다.
       게시판 글(게시판 공간)은 처음부터 게시판에 있었으므로 자르지 않는다. */
    $since = null;
    if ($readCode === CommunityAuthz::CODE_BOARD && (string) $row['space_kind'] === 'personal'
        && (string) $row['visibility'] === 'published' && $row['published_at'] !== null) {
        $since = (string) $row['published_at'];
    }

    return [
        'row'         => $row,
        'read_code'   => $readCode,
        'may_change'  => $mayChange,
        'change_code' => $changeCode,
        // 이 시각 **이전** 기록은 보이지 않는다(null 이면 자르지 않는다)
        'since'       => $since,
    ];
}

/**
 * 스냅샷의 `summary` 에서 **되돌린 판 번호**를 읽는다 (4-C).
 *
 * 저장 형식은 `restore from v<번호>` 한 가지다. 이 값은 **판 N 이 끝난 사연**이므로, 화면에
 * 보일 때는 **그 다음 판(N+1)**의 줄에 「판 k 에서 되돌림」으로 붙는다 — 되돌리기로 만들어진
 * 것은 다음 판이기 때문이다(`versions_list.php`·`history.php` 가 그렇게 옮겨 붙인다).
 *
 * 모르는 글자는 `null` 이다 — 지어내지 않는다.
 */
function community_restore_source(?string $summary): ?int
{
    if ($summary === null || preg_match('/^restore from v([0-9]{1,9})$/', $summary, $m) !== 1) {
        return null;
    }
    return (int) $m[1];
}

/* ══════════════════════════════════════════════════════════════════
 * 상태 (4-D, C:F-37) · 공유와 인계 (5-A)
 *
 * 규칙 셋.
 *   ① **기록은 지울 수 없다** (C:S-14) — `page_property_events`·`handovers` 에는 앱 계정에
 *      INSERT 와 SELECT 만 있다(`db/community/grants.sql`). 아래 함수도 그 둘만 쓴다.
 *      `page_shares` 는 **바뀌는 값**이라 SIUD 이고, 바뀐 일은 감사(`page.share`)가 남긴다.
 *   ② 역할 문자열은 이 파일에 없다 — 「쓰기 자격 있는 역할」은 `community_role_sets()` 가
 *      역할마다 `Authz` 에 물어 만든다(4-D 교훈).
 *   ③ 4-D 의 담당자 후보 질의(`community_assignee_where`·`community_assignee_candidates`)는
 *      지정을 멈추면서 지웠다. 담당자 칸의 옛 값과 기록은 **읽기만** 한다.
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 역할 이름마다 `Authz` 에 물어 만든 두 묶음 — 「커뮤니티에 쓸 수 있는 역할」·「관리자 역할」.
 *
 * ★ 이 파일에는 역할 문자열(`'Admin'` 같은 것)이 **한 글자도 없다** (ARCH 4.1, CLAUDE 4장
 *   금지 패턴 「역할 판단을 Authz 밖에서 직접 하는 코드」). 역할 **이름**은 DB 에 있는 것을
 *   그대로 읽고, 「그 역할이 쓸 수 있나·관리자인가」의 판단은 전부 `Authz` 가 한다.
 *
 * @return array{write:list<string>,admin:list<string>}
 */
function community_role_sets(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $sql = <<<'SQL'
        SELECT DISTINCT u.role FROM public.users u WHERE u.is_active = true ORDER BY u.role
        SQL;

    $write = [];
    $admin = [];
    foreach (Db::instance()->run($sql)->fetchAll() as $r) {
        $role = (string) $r['role'];
        // 판단은 Authz 가 한다 — 여기서는 답만 받아 적는다.
        if (Authz::canCommunityWrite(['role' => $role])) {
            $write[] = $role;
        }
        if (Authz::canAdmin(['role' => $role])) {
            $admin[] = $role;
        }
    }

    $cache = ['write' => $write, 'admin' => $admin];
    return $cache;
}

/**
 * 공유·인계 받는 사람 **후보**를 찾는다 (5-A `GET /api/share-candidates`).
 * 돌려주는 것은 **계정명·이름·역할**뿐이다(팀·활성 여부·만료일 같은 것은 고르는 데 필요 없다).
 *
 *   · 5-A″ — `$q` 가 **비어 있으면 빈 목록**이다(한 글자부터 찾는다 — 사용자 결정 2026-09-23).
 *     찾기는 **아이디와 이름 둘 다**(ILIKE, 값은 바인딩, `%`·`_`·`#` 이스케이프). 이름이 없는
 *     계정은 이름 쪽이 NULL 이라 아이디로만 걸린다.
 *
 *   · 활성 계정만 — 비활성 계정에게 준 공유는 효력이 없고, 넘길 수도 없다.
 *   · `$writeOnly` 가 참이면(인계) **쓰기 자격 있는 역할만** — Guest 는 넘겨받을 수 없다.
 *     역할 목록은 `community_role_sets()` 가 `Authz` 에 물어 만든 것이고, `IN (…)` 의 자리표시자
 *     이름은 **개수로 만든 코드 문자열**(`:cw0` …)이다 — 요청 값이 SQL 에 들어가지 않는다.
 *   · `$excludeId` — 후보에서 뺄 한 사람(그 페이지의 주인. 주인에게 공유·인계는 뜻이 없다).
 *
 * @return list<array{username:string,display_name:?string,role:string}>
 */
function community_share_candidates(string $q, int $limit, ?int $excludeId, bool $writeOnly): array
{
    if ($q === '') {
        return [];
    }
    $bind  = [];
    $names = [];
    foreach (community_role_sets()['write'] as $i => $role) {
        $name        = ':cw' . (string) $i;
        $names[]     = $name;
        $bind[$name] = $role;
    }
    $roleSql = $names === [] ? 'false' : 'u.role IN (' . implode(', ', $names) . ')';

    // `%`·`_`·`#` 은 ILIKE 의 뜻 있는 글자다. `#` 로 이스케이프하고 값은 바인딩한다 (PRD S-04).
    $like = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';

    $sql = <<<SQL
        SELECT u.username, u.display_name, u.role
          FROM public.users u
         WHERE u.is_active = true
           AND (u.username ILIKE :like ESCAPE '#' OR u.display_name ILIKE :like_name ESCAPE '#')
           AND u.id IS DISTINCT FROM CAST(:exclude AS bigint)
           AND (NOT CAST(:write_only AS boolean) OR {$roleSql})
         ORDER BY u.username
         LIMIT CAST(:lim AS int)
        SQL;

    $out = [];
    foreach (Db::instance()->run($sql, array_merge($bind, [
        ':like'       => $like,
        ':like_name'  => $like,
        ':exclude'    => $excludeId,
        ':write_only' => $writeOnly ? 'true' : 'false',
        ':lim'        => $limit,
    ]))->fetchAll() as $r) {
        $out[] = [
            'username'     => (string) $r['username'],
            'display_name' => $r['display_name'] === null ? null : (string) $r['display_name'],
            'role'         => (string) $r['role'],
        ];
    }
    return $out;
}

/**
 * 계정명 하나로 사람을 찾는다 (5-A 공유·인계 대상). 없으면 `null`.
 *
 * 비활성 계정도 **행을 돌려준다** — 「없는 사람」과 「내려간 계정」을 가르는 것은 판정 함수
 * (`CommunityAuthz::decideShareTarget`·`decideHandoverTarget`)의 몫이다.
 *
 * @return array<string,mixed>|null
 */
function community_user_by_name(string $username): ?array
{
    $sql = <<<'SQL'
        SELECT u.id, u.username, u.display_name, u.role, u.team_id, u.is_active, u.expires_at
          FROM public.users u
         WHERE u.username = :name
        SQL;

    $row = Db::instance()->run($sql, [':name' => $username])->fetch();
    return is_array($row) ? $row : null;
}

/**
 * 한 페이지의 공유 줄 — **이 페이지에 직접 준 것**과 **조상에게서 물려받은 것** (5-A).
 *
 * 조상 쪽은 `community_share_rank_sql()` 과 같은 길(같은 공간·살아 있는 조상)로 오른다.
 * 비활성 계정의 줄도 돌려준다 — 화면이 「(내려간 계정)」이라고 보이고 뺄 수 있어야 한다.
 * 효력이 없다는 사실은 판정이 이미 알고 있다(`su.is_active = true`).
 *
 * @return list<array{username:string,display_name:?string,role:string,is_active:bool,level:string,granted_by:?string,
 *                    created_at:string,inherited:bool,from_id:?string,from_title:?string}>
 */
function community_page_shares(int $pageId): array
{
    $sql = <<<'SQL'
        WITH RECURSIVE up(id, parent_page_id, space_id, lvl) AS (
            SELECT a.id, a.parent_page_id, a.space_id, 0
              FROM community.pages a WHERE a.id = CAST(:pid AS bigint)
            UNION ALL
            SELECT b.id, b.parent_page_id, b.space_id, up.lvl + 1
              FROM community.pages b JOIN up ON b.id = up.parent_page_id
             WHERE b.space_id = up.space_id AND b.deleted_at IS NULL AND up.lvl < 16
        )
        SELECT u.username, u.display_name, u.role, u.is_active, sh.level, gb.username AS granted_by_name,
               gb.display_name AS granted_by_display_name,
               sh.created_at, up.lvl, src.public_id AS from_id, src.title AS from_title
          FROM up
          JOIN community.page_shares sh ON sh.page_id = up.id
          JOIN public.users u ON u.id = sh.user_id
          JOIN community.pages src ON src.id = up.id
          LEFT JOIN public.users gb ON gb.id = sh.granted_by
         ORDER BY up.lvl ASC, u.username ASC
         LIMIT 500
        SQL;

    $out = [];
    foreach (Db::instance()->run($sql, [':pid' => $pageId])->fetchAll() as $r) {
        $inherited = (int) $r['lvl'] > 0;
        $out[] = [
            'username'   => (string) $r['username'],
            'display_name' => $r['display_name'] === null ? null : (string) $r['display_name'],
            'role'       => (string) $r['role'],
            'is_active'  => $r['is_active'] === true,
            'level'      => (string) $r['level'],
            'granted_by' => $r['granted_by_name'] === null ? null : (string) $r['granted_by_name'],
            'granted_by_display_name' => $r['granted_by_display_name'] === null ? null : (string) $r['granted_by_display_name'],
            'created_at' => (string) $r['created_at'],
            'inherited'  => $inherited,
            'from_id'    => $inherited ? (string) $r['from_id'] : null,
            'from_title' => $inherited ? (string) $r['from_title'] : null,
        ];
    }
    return $out;
}

/**
 * 이 페이지와 **살아 있는 하위 전부**의 `pages.id` (5-A 인계 — 무엇이 옮겨 가나).
 * 재귀 CTE 한 번이고, 자기 자신이 맨 앞이다. 지워진 하위는 옮기지 않는다 — 옛 공간의
 * 휴지통에 남아, 지운 사람이 거기서 되살린다(되살리면 부모가 없어 뿌리로 올라간다, 4-C).
 *
 * @return list<int>
 */
function community_subtree_live_ids(int $pageId): array
{
    $sql = <<<'SQL'
        WITH RECURSIVE down AS (
            SELECT p.id, 0 AS lvl FROM community.pages p
             WHERE p.id = CAST(:pid AS bigint) AND p.deleted_at IS NULL
            UNION ALL
            SELECT c.id, d.lvl + 1 FROM community.pages c
              JOIN down d ON c.parent_page_id = d.id
             WHERE c.deleted_at IS NULL AND d.lvl < 16
        )
        SELECT id FROM down ORDER BY lvl, id LIMIT 5000
        SQL;

    $ids = [];
    foreach (Db::instance()->run($sql, [':pid' => $pageId])->fetchAll() as $r) {
        $ids[] = (int) $r['id'];
    }
    return $ids;
}

/**
 * 이 사람의 개인 공간 **그릇 한 줄**을 만든다 — 이미 있으면 아무것도 하지 않는다 (5-A 로 뽑아냈다).
 *
 * `GET /api/me/space`(첫 방문)와 인계(받는 사람에게 아직 공간이 없을 때)가 **같은 문장**을 쓴다 —
 * 공간 이름 규칙(계정명 40자 + 「 의 공간」)과 초기값이 두 벌이 되지 않게 하려는 것이다.
 * 동시에 두 요청이 만들려 하면 부분 UNIQUE 인덱스(`spaces_personal_owner_uidx`)가 한쪽을
 * 걸러 낸다(`ON CONFLICT … DO NOTHING`) — 그때는 `null` 을 돌려주고, 부르는 쪽이 다시 읽는다.
 * 반드시 부르는 쪽의 트랜잭션 안에서 부른다.
 *
 * @return array{id:int,name:string,kind:string,created_at:string}|null 새로 만들었으면 그 줄, 이미 있었으면 null
 */
function community_create_personal_space(int $ownerId, string $ownerName, int $byUserId): ?array
{
    // 표의 상한이 50자라 앞 40자만 쓴다 — 여기서 자르지 않으면 이름이 긴 계정이 DB 제약에 걸린다.
    if (iconv_strlen($ownerName, 'UTF-8') > 40) {
        $ownerName = (string) iconv_substr($ownerName, 0, 40, 'UTF-8');
    }
    $sql = <<<'SQL'
        INSERT INTO community.spaces (kind, owner_user_id, name, description, access_level, created_by)
        VALUES ('personal', CAST(:uid AS bigint), :name, :description, 'admin-only', CAST(:by AS bigint))
        ON CONFLICT (owner_user_id) WHERE kind = 'personal' DO NOTHING
        RETURNING id, name, kind, created_at
        SQL;

    $row = Db::instance()->run($sql, [
        ':uid'         => $ownerId,
        ':name'        => $ownerName . ' 의 공간',
        ':description' => '나만 보는 개인 업무 공간입니다.',
        ':by'          => $byUserId,
    ])->fetch();
    if (!is_array($row)) {
        return null;
    }
    return [
        'id'         => (int) $row['id'],
        'name'       => (string) $row['name'],
        'kind'       => (string) $row['kind'],
        'created_at' => (string) $row['created_at'],
    ];
}

/**
 * 상태가 바뀐 일을 **지울 수 없는 줄 하나**로 남긴다 (4-D, C:S-14). 5-A 부터 담당자 줄은 새로 생기지 않는다.
 *
 * 반드시 부르는 쪽의 트랜잭션 안에서 부른다 — `pages` 갱신과 기록이 갈라지지 않게.
 * `INSERT` 하나뿐이고, 앱 계정에는 이 표에 UPDATE·DELETE 가 없다.
 */
function community_insert_property_event(
    int $pageId,
    string $prop,
    ?string $from,
    ?string $to,
    int $byUserId
): void {
    $sql = <<<'SQL'
        INSERT INTO community.page_property_events (page_id, prop, from_value, to_value, by_user_id)
        VALUES (CAST(:pid AS bigint), :prop, :from_value, :to_value, CAST(:by AS bigint))
        SQL;

    Db::instance()->run($sql, [
        ':pid'        => $pageId,
        ':prop'       => $prop,
        ':from_value' => $from,
        ':to_value'   => $to,
        ':by'         => $byUserId,
    ]);
}

/* ══════════════════════════════════════════════════════════════════
 * 게시판 · 태그 · 조회수 (5-C)
 *
 * 규칙 셋.
 *   ① 게시판 등급 판정은 `CommunityAuthz::decideBoardRead`(→ t-box `Authz::decideRead`) 하나이고,
 *      목록은 그 판정을 SQL 로 옮긴 `community_board_read_sql()` 로 거른다(C:S-02).
 *   ② 태그는 **바뀌는 값**이다(앱 SIUD). 조회는 **한 사람 하루 한 줄**이고 고치지도 지우지도 않는다(SI).
 *   ③ 게시·해제·숨김·해결됨은 `page_property_events` 에 한 줄씩 남는다(지울 수 없는 처리 이력).
 * ══════════════════════════════════════════════════════════════════ */

/** 한 페이지의 태그 수 상한 · 태그 한 개의 글자 수 상한 (지시서 「태그」) */
const COMMUNITY_TAG_MAX_COUNT = 10;
const COMMUNITY_TAG_MAX_CHARS = 30;

/**
 * 게시판 한 건(+ 팀 이름). 게시판이 아니거나 없으면 `null`.
 *
 * @return array<string,mixed>|null 「게시판 행」 모양 + `name`·`description`·`team_name`·`notice`
 */
function community_board_row(int $boardId): ?array
{
    $sql = <<<'SQL'
        SELECT b.id, b.kind, b.name, b.description, b.access_level, b.team_id, b.notice,
               b.board_kind, b.is_active, b.created_at, t.name AS team_name
          FROM community.spaces b
          LEFT JOIN public.teams t ON t.id = b.team_id
         WHERE b.id = CAST(:bid AS bigint) AND b.kind = 'board'
        SQL;
    $row = Db::instance()->run($sql, [':bid' => $boardId])->fetch();
    return is_array($row) ? $row : null;
}

/**
 * 창구가 내보내는 게시판 요약 — 판정 결과(`can_write`·`can_moderate`)를 함께 싣는다.
 * 두 값은 **단추를 보일지** 고르는 화면 편의 값이고, 창구가 같은 함수로 다시 막는다(DESIGN 원칙 1).
 *
 * @param  array<string,mixed> $b `community_board_row()` 모양
 * @param  array<string,mixed> $u
 * @return array<string,mixed>
 */
function community_board_summary(array $b, array $u): array
{
    [$canWrite, $writeCode] = CommunityAuthz::decideBoardWrite($u, $b);
    [$canModerate]          = CommunityAuthz::decideBoardModerate($u, $b);
    return [
        'id'           => (int) $b['id'],
        'name'         => (string) $b['name'],
        'description'  => (string) $b['description'],
        'access_level' => (string) $b['access_level'],
        'team_id'      => $b['team_id'] === null ? null : (int) $b['team_id'],
        'team_name'    => ($b['team_name'] ?? null) === null ? null : (string) $b['team_name'],
        'board_kind'   => (string) $b['board_kind'],
        'is_active'    => $b['is_active'] === true,
        'can_write'    => $canWrite,
        // 쓸 수 없는 까닭 중 「보관 중」만 화면에 알린다(권한 없음은 단추를 숨기는 것으로 충분하다).
        'inactive'     => $writeCode === CommunityAuthz::CODE_BOARD_INACTIVE || $b['is_active'] !== true,
        'can_moderate' => $canModerate,
    ];
}

/**
 * 요청 본문의 태그 목록을 검사한다 (5-C 지시서 「태그」). 어긋나면 `null` — 창구가 400 으로 답한다.
 *   · 배열이고 각 값은 문자열 · 앞뒤 공백을 뗀 뒤 1~30자 · 제어·서식 문자(`\p{C}`) 없음
 *   · 개수는 **보낸 그대로** 10개까지(같은 글자를 겹쳐 보내 상한을 넘기면 그것도 400 이다)
 * 대소문자만 다른 태그는 **한 번**만 남는다 — 그 판단은 DB 의 `lower()` 가 한다(`tag_norm`,
 * mbstring 이 없어 PHP 로는 한글 밖 글자의 대소문자를 고르게 다루지 못한다 — 5-B 실측).
 *
 * @param  mixed $raw
 * @return list<string>|null
 */
function community_parse_tags($raw): ?array
{
    if (!is_array($raw) || !array_is_list($raw) || count($raw) > COMMUNITY_TAG_MAX_COUNT) {
        return null;
    }
    $out = [];
    foreach ($raw as $t) {
        if (!is_string($t) || preg_match('//u', $t) !== 1) {
            return null;
        }
        $t = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $t);
        $n = iconv_strlen($t, 'UTF-8');
        if ($t === '' || $n === false || $n > COMMUNITY_TAG_MAX_CHARS || preg_match('/\p{C}/u', $t) === 1) {
            return null;
        }
        $out[] = $t;
    }
    return $out;
}

/**
 * 한 페이지의 태그(보이는 글자, 가나다·abc 순). 없으면 빈 배열.
 *
 * @return list<string>
 */
function community_page_tags(int $pageId): array
{
    $sql = <<<'SQL'
        SELECT t.tag FROM community.page_tags t
         WHERE t.page_id = CAST(:pid AS bigint)
         ORDER BY t.tag_norm
         LIMIT 20
        SQL;
    $out = [];
    foreach (Db::instance()->run($sql, [':pid' => $pageId])->fetchAll() as $r) {
        $out[] = (string) $r['tag'];
    }
    return $out;
}

/**
 * 조회 한 번 — **한 사람 하루 한 줄**(`ON CONFLICT DO NOTHING`). 새 줄이 생겼으면 참.
 * 날짜는 DB 의 오늘(`current_date` 기본값)이다. 읽기 판정을 **지난 뒤에만** 부른다.
 */
function community_record_view(int $pageId, int $userId): bool
{
    $sql = <<<'SQL'
        INSERT INTO community.page_views (page_id, user_id)
        VALUES (CAST(:pid AS bigint), CAST(:uid AS bigint))
        ON CONFLICT DO NOTHING
        SQL;
    return Db::instance()->run($sql, [':pid' => $pageId, ':uid' => $userId])->rowCount() > 0;
}

/** 조회수 = 조회 기록 줄 수(사람 × 날). 누가 봤는지는 돌려주지 않는다. */
function community_view_count(int $pageId): int
{
    $sql = <<<'SQL'
        SELECT count(*) AS n FROM community.page_views WHERE page_id = CAST(:pid AS bigint)
        SQL;
    $row = Db::instance()->run($sql, [':pid' => $pageId])->fetch();
    return is_array($row) ? (int) $row['n'] : 0;
}

/** 최근 목록의 사람당 줄 수 (개발 대기열 6 · 지시서 1.2 — 넘으면 오래된 것부터 지운다) */
const COMMUNITY_RECENT_MAX = 50;
/** 즐겨찾기의 사람당 줄 수 (지시서 1.1) */
const COMMUNITY_FAVORITES_MAX = 200;

/**
 * 「최근」 한 줄 남기기 (개발 대기열 6 · Q:F-3a 라운드 A — 지시서 1.2 · 결정 M-3).
 *
 * 읽기 판정을 **지난 뒤에만** 부른다(`pages_get.php` — 조회수 `community_record_view` 바로 옆). 쓰기를 줄이려고
 * 마지막 기록이 **5분보다 오래됐을 때만** 시각을 민다(Q:F-4 P-3 과 같은 생각) — 5분 안에 다시 열면 문장은 돌지만 행은
 * 바뀌지 않는다(`ON CONFLICT … DO UPDATE … WHERE` 가 거짓이면 갱신 0). 새 줄이 생겼을 때만 사람당 50줄을 넘는
 * 가장 오래된 줄을 지운다(이 표는 기록이 아니라 화면 편의다 — 앱 계정에 DELETE 가 있다).
 * 실패해도 읽기는 막지 않는다 — 부르는 쪽이 try/catch 로 감싸 서버 로그에만 남긴다.
 * 값은 전부 바인딩이다(자리표시자 이름은 문장마다 한 번씩 — 서버측 prepare).
 *
 * 라운드 B 두 고침
 *   ① (라운드 A 독립 검토 L-3) **먼저 읽기만** 한다 — 내 줄이 있고 마지막 기록이 5분 안이면 여기서 끝(쓰기·행 잠금 0).
 *      전에는 5분 안에도 `ON CONFLICT … DO UPDATE` 가 돌아, WHERE 가 거짓이어도 충돌한 행을 **잠갔다**(같은 사람이 같은 페이지를
 *      빠르게 여러 번 열면 그 줄에서 줄을 섰다). 읽기(MVCC)는 잠그지 않는다. 문턱 5분은 그대로다(지시서 1.2 — Q:F-4 P-3 과 같은 생각).
 *      두 요청이 동시에 「5분 지남」을 읽으면 둘 다 아래 문장으로 가지만, 그 문장의 WHERE 가 두 번째를 거짓으로 만들어 결과는 같다.
 *   ② (독립 검토 권고) **목록과 같은 조건절 안에서만** 적는다 — `community_search_pages_where`(관리자·게시판 관리자 특권 끔) +
 *      지운 것·숨긴 게시판 글 제외. 관리자가 `admin_all` 로 연 남의 비공개 페이지는 「최근」 목록에 나오지 않으므로 줄도 남기지
 *      않는다 — 전에는 목록에 안 나오는 줄이 사람당 50줄 자리를 차지했다. 판정 코드는 새로 만들지 않는다(목록이 쓰는 조건절 그대로).
 *
 * @param array<string,mixed> $user 읽기 판정을 지난 지금 사용자(세션의 본인)
 */
function community_record_recent(int $pageId, array $user): void
{
    $db     = Db::instance();
    $userId = (int) $user['id'];
    $sqlFresh = <<<'SQL'
        SELECT 1
          FROM community.page_recent
         WHERE user_id = CAST(:uid AS bigint)
           AND page_id = CAST(:pid AS bigint)
           AND last_viewed_at >= now() - interval '5 minutes'
        SQL;
    if ($db->run($sqlFresh, [':uid' => $userId, ':pid' => $pageId])->fetch() !== false) {
        return;   // 5분 안에 적었다 — 쓰지 않는다(L-3)
    }
    // 아래 heredoc 은 `AND` 에서 끝나고, 바로 뒤에 목록과 같은 조건절(코드에 적힌 조각 — 요청 값 0)이 붙고, 그 뒤에 ON CONFLICT 꼬리가 온다.
    $sqlUpsertHead = <<<'SQL'
        INSERT INTO community.page_recent AS r (user_id, page_id)
        SELECT CAST(:rec_uid AS bigint), p.id
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
         WHERE p.id = CAST(:rec_pid AS bigint)
           AND p.deleted_at IS NULL
           AND NOT (s.kind = 'board' AND p.hidden_at IS NOT NULL)
           AND
        SQL;
    $sqlUpsertTail = <<<'SQL'
        ON CONFLICT (user_id, page_id) DO UPDATE
           SET last_viewed_at = now()
         WHERE r.last_viewed_at < now() - interval '5 minutes'
        RETURNING (r.xmax = 0) AS inserted
        SQL;
    [$visible, $bind] = community_search_pages_where($user);
    $bind[':rec_uid'] = $userId;
    $bind[':rec_pid'] = $pageId;
    $row = $db->run($sqlUpsertHead . ' ' . $visible . ' ' . $sqlUpsertTail, $bind)->fetch();
    // 갱신했거나(줄 수 그대로) 5분 안이라 건너뛰었으면 지울 것이 없다
    if (!is_array($row) || $row['inserted'] !== true) {
        return;
    }
    $sqlEvict = <<<'SQL'
        DELETE FROM community.page_recent
         WHERE user_id = CAST(:uid AS bigint)
           AND page_id IN (SELECT k.page_id
                             FROM community.page_recent k
                            WHERE k.user_id = CAST(:uid_k AS bigint)
                            ORDER BY k.last_viewed_at DESC, k.page_id DESC
                           OFFSET CAST(:keep AS integer))
        SQL;
    $db->run($sqlEvict, [':uid' => $userId, ':uid_k' => $userId, ':keep' => COMMUNITY_RECENT_MAX]);
}

/**
 * 게시·숨김 창구 넷(`publish`·`unpublish`·`hide`)의 **공통 관문** — 행을 읽고 없거나 지워졌으면 404.
 * 읽기 판정은 부르는 쪽이 목적에 맞게 따로 한다(게시판 관리자는 원래 범위로는 못 보는 글도 내린다).
 *
 * @return array<string,mixed>
 */
function community_page_row_or_404(string $publicId, int $viewerId): array
{
    $row = community_page_row($publicId, $viewerId);
    if ($row === null || $row['deleted_at'] !== null) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    return $row;
}

/* ══════════════════════════════════════════════════════════════════
 * 댓글 · 멘션 · 알림 (5-D)
 * ══════════════════════════════════════════════════════════════════ */

/** 댓글 본문 · 인용의 글자 수 상한 (DB CHECK 과 같은 값) */
const COMMUNITY_COMMENT_MAX_CHARS = 2000;
const COMMUNITY_QUOTE_MAX_CHARS   = 300;
/** 댓글 하나에서 알림을 만드는 멘션 수 상한 — 한 번에 전사 알림을 뿌리지 못하게 */
const COMMUNITY_MENTION_MAX = 10;

/**
 * 평문 한 칸 검사 (결정 D-1 — 댓글은 **평문**). 어긋나면 `null`.
 *   `\r` 은 지운다 · 줄바꿈(`\n`) 말고 제어·서식 문자(`\p{C}`)는 거부 · 앞뒤 공백 제거 뒤 1~$max 자.
 *
 * @param mixed $raw
 */
function community_clean_plain($raw, int $max): ?string
{
    if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
        return null;
    }
    $t = str_replace("\r", '', $raw);
    $t = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $t);
    $n = iconv_strlen($t, 'UTF-8');
    if ($t === '' || $n === false || $n > $max) {
        return null;
    }
    if (preg_match('/[^\P{C}\n]/u', $t) === 1) {
        return null;
    }
    return $t;
}

/**
 * 본문의 `@계정명` — 앞이 계정명 글자가 아닌 자리의 `@` 뒤 3~64자(`Auth::USERNAME_RE` 글자 집합).
 * 중복을 빼고 앞에서부터 최대 `COMMUNITY_MENTION_MAX` 개. 대상 판정은 부르는 쪽이 한다.
 *
 * @return list<string>
 */
function community_mentions_in(string $body): array
{
    if (preg_match_all('/(?<![a-z0-9_.@-])@([a-z0-9_.-]{3,64})/', $body, $m) === false) {
        return [];
    }
    $out = [];
    foreach ($m[1] as $name) {
        $name = rtrim($name, '.');   // 문장 끝 마침표
        if (strlen($name) >= 3 && !in_array($name, $out, true)) {
            $out[] = $name;
        }
        if (count($out) >= COMMUNITY_MENTION_MAX) {
            break;
        }
    }
    return $out;
}

/**
 * 댓글 한 건 + 그 페이지의 public_id. 없으면 `null`(지운 것도 돌려준다 — 판정이 가른다).
 *
 * @return array<string,mixed>|null
 */
function community_comment_row(string $publicId): ?array
{
    $sql = <<<'SQL'
        SELECT c.id, c.public_id, c.page_id, c.parent_comment_id, c.block_id, c.quote, c.body,
               c.author_id, c.created_at, c.updated_at, c.resolved_at, c.hidden_at, c.deleted_at,
               p.public_id AS page_public_id
          FROM community.comments c
          JOIN community.pages p ON p.id = c.page_id
         WHERE c.public_id = CAST(:cid AS uuid)
        SQL;
    $row = Db::instance()->run($sql, [':cid' => $publicId])->fetch();
    return is_array($row) ? $row : null;
}

/**
 * 댓글 창구의 공통 관문 — 댓글을 찾고, **그 페이지의 읽기 관문**(`community_history_gate`)을 지나고,
 * 게시판 독자에게 게시 전 댓글은 없는 것으로 다룬다(5-C #9 와 같은 자르기). 실패하면 돌아오지 않는다.
 *
 * @param  array<string,mixed> $u
 * @return array{comment:array<string,mixed>,gate:array<string,mixed>}
 */
function community_comment_gate(array $u, string $commentPublicId): array
{
    $comment = community_comment_row($commentPublicId);
    if ($comment === null || $comment['deleted_at'] !== null) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $gate = community_history_gate($u, (string) $comment['page_public_id']);
    if ($gate['since'] !== null && strtotime((string) $comment['created_at']) < strtotime((string) $gate['since'])) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    return ['comment' => $comment, 'gate' => $gate];
}

/**
 * 알림 한 줄 — 받는 사람이 **그 페이지를 볼 수 있을 때만** 만든다(볼 수 없는 페이지의 제목이 알림으로 새지 않게).
 * 자기 자신에게는 만들지 않는다. 부르는 쪽의 트랜잭션 안에서 부른다. 만들었으면 참.
 *
 * @param array<string,mixed> $pageRow 아무 사람 몫의 페이지 행(공유 등급은 여기서 받는 사람 몫으로 다시 읽는다)
 */
function community_notify(int $userId, string $kind, array $pageRow, ?int $commentId, ?int $actorId, ?int &$notificationId = null): bool
{
    $notificationId = null;
    if ($userId === $actorId || $userId <= 0) {
        return false;
    }
    $target = community_user_by_id($userId);
    $row    = community_page_row((string) $pageRow['public_id'], $userId);
    if ($row === null || !CommunityAuthz::decideMentionTarget($target, $row)[0]) {
        return false;
    }
    $sql = <<<'SQL'
        INSERT INTO community.notifications (user_id, kind, page_id, comment_id, actor_id)
        VALUES (CAST(:uid AS bigint), :kind, CAST(:pid AS bigint), CAST(:cid AS bigint), CAST(:aid AS bigint)) RETURNING id
        SQL;
    $notificationId = (int) Db::instance()->run($sql, [
        ':uid' => $userId, ':kind' => $kind, ':pid' => (int) $row['id'], ':cid' => $commentId, ':aid' => $actorId,
    ])->fetchColumn();
    return true;
}

/**
 * 계정 번호로 사람 한 줄(`community_user_by_name` 과 같은 칸). 없으면 `null`.
 *
 * @return array<string,mixed>|null
 */
function community_user_by_id(int $userId): ?array
{
    $sql = <<<'SQL'
        SELECT u.id, u.username, u.display_name, u.role, u.team_id, u.is_active, u.expires_at
          FROM public.users u
         WHERE u.id = CAST(:id AS bigint)
        SQL;
    $row = Db::instance()->run($sql, [':id' => $userId])->fetch();
    return is_array($row) ? $row : null;
}

/**
 * 공지 읽음 한 번 — 사람마다 한 줄(처음 읽은 때는 그대로, 마지막 읽은 때만 민다). 읽기 판정을 지난 뒤에만 부른다.
 */
function community_record_read(int $pageId, int $userId): void
{
    $sql = <<<'SQL'
        INSERT INTO community.page_reads (page_id, user_id)
        VALUES (CAST(:pid AS bigint), CAST(:uid AS bigint))
        ON CONFLICT (page_id, user_id) DO UPDATE SET last_read_at = now()
        SQL;
    Db::instance()->run($sql, [':pid' => $pageId, ':uid' => $userId]);
}

/* ══════════════════════════════════════════════════════════════════
 * 첨부 · 페이지 링크 · 역참조 (5-E)
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 본문의 t-box 파일 참조가 **모두 붙일 수 있는 것인가** (5-E, 결정 E-3·E-5). 아니면 거짓 — 창구가 400 `file_ref` 로 답한다.
 *   파일마다: 지워지지 않았고 · **저장하는 사람이 t-box 에서 볼 수 있고**(`Authz::decideRead`) · 읽는 범위 전부에 들어맞는다.
 *   없는 파일·못 보는 파일·좁은 파일은 **같은 거짓**이다(어느 쪽인지 응답이 가르지 않는다 — 파일 UUID 탐지기가 되지 않게).
 *
 * @param list<string>                              $fileIds   UUID 들(`Doc::extract()['files']`)
 * @param list<array{level:string,team_id:?int}>    $audiences `CommunityAuthz::pageAudiences()`
 * @param array<string,mixed>                       $u
 */
function community_file_refs_ok(array $fileIds, array $audiences, array $u): bool
{
    if ($fileIds === []) {
        return true;
    }
    if (count($fileIds) > 200) {
        return false;
    }
    $names = [];
    $bind  = [];
    foreach (array_values($fileIds) as $i => $fid) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $fid) !== 1) {
            return false;
        }
        $names[]           = 'CAST(:f' . $i . ' AS uuid)';
        $bind[':f' . $i]   = (string) $fid;
    }
    $in  = implode(', ', $names);   // 개수로 만든 자리표시자 이름뿐 — 값은 전부 바인딩
    $sql = <<<SQL
        SELECT f.public_id, f.access_level, f.team_id,
               -- G-0 — 개인 등급: 판정(decideRead [P])이 쓰는 번호·종류 표식 + 주인(fitsFileRef — 파일 주인 = 페이지 주인일 때만)
               f.id, f.uploaded_by, 'file' AS item_kind
          FROM public.files f
         WHERE f.deleted_at IS NULL
           AND f.public_id IN ({$in})
        SQL;
    $found = [];
    foreach (Db::instance()->run($sql, $bind)->fetchAll() as $f) {
        $found[(string) $f['public_id']] = $f;
    }
    foreach ($fileIds as $fid) {
        $f = $found[(string) $fid] ?? null;
        if ($f === null || !Authz::decideRead($u, $f)[0]) {
            return false;
        }
        foreach ($audiences as $aud) {
            if (!CommunityAuthz::fitsFileRef($f, $aud)) {
                return false;
            }
        }
    }
    return true;
}

/**
 * G-0 — 붙이기가 거부된 까닭이 **내가 볼 수 있는 t-box 개인 파일**인가(거부 문장을 고르는 데만 쓴다 — 판정은 위 함수다).
 * 내가 못 보는 파일은 여기서도 거짓이다 — 「개인 파일이다」를 못 보는 사람에게 알리지 않는다(파일 UUID 탐지기가 되지 않게).
 *
 * @param list<string>        $fileIds
 * @param array<string,mixed> $u
 */
function community_file_refs_private_readable(array $fileIds, array $u): bool
{
    $ids = array_values(array_filter($fileIds, static fn ($f): bool =>
        preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $f) === 1));
    if ($ids === [] || count($ids) > 200) {
        return false;
    }
    $names = [];
    $bind  = [];
    foreach ($ids as $i => $fid) {
        $names[]         = 'CAST(:pf' . $i . ' AS uuid)';
        $bind[':pf' . $i] = (string) $fid;
    }
    $in  = implode(', ', $names);
    $sql = <<<SQL
        SELECT f.id, f.access_level, f.team_id, f.uploaded_by, 'file' AS item_kind
          FROM public.files f
         WHERE f.deleted_at IS NULL
           AND f.access_level = 'private'
           AND f.public_id IN ({$in})
        SQL;
    foreach (Db::instance()->run($sql, $bind)->fetchAll() as $f) {
        if (Authz::canSeeInList($u, $f)) {
            return true;
        }
    }
    return false;
}

/** G-0 — 개인 파일을 비공개 페이지 밖에 붙이려 할 때의 400 문장(창구 넷이 같은 글자) */
const COMMUNITY_PRIVATE_FILE_REFUSED = 't-box 의 「개인」 파일은 지금은 내 비공개 페이지에만 붙일 수 있습니다. 다른 사람이 보는 페이지에 붙이려면 t-box 에서 그 파일의 등급을 넓히세요';

/**
 * 본문의 그림·파일 카드에 쓸 **파일 지도** — 보는 사람이 t-box 에서 볼 수 있는 파일만(`Authz::visibleFilesWhere`).
 * 지도에 없으면 렌더러가 「볼 수 없는 파일」을 그린다. 이름·크기·그림인가·검사 상태만(올린 사람·등급은 싣지 않는다 — E-8).
 *
 * @param  list<string>        $fileIds
 * @param  array<string,mixed> $u
 * @return array<string,array{name:string,size:int,image:bool}>
 */
function community_file_titles(array $fileIds, array $u): array
{
    $fileIds = array_values(array_filter($fileIds, static fn ($f): bool =>
        preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $f) === 1));
    if ($fileIds === []) {
        return [];
    }
    [$vis, $visBind] = Authz::visibleFilesWhere($u);
    $names = [];
    $bind  = $visBind;
    foreach (array_slice($fileIds, 0, 200) as $i => $fid) {
        $names[]              = 'CAST(:ft' . $i . ' AS uuid)';
        $bind[':ft' . $i]     = (string) $fid;
    }
    $in  = implode(', ', $names);
    $sql = <<<SQL
        SELECT f.public_id, f.original_name, f.size_bytes, f.mime, f.scan_status, f.exec_content
          FROM files f
         WHERE
               {$vis}
           AND f.public_id IN ({$in})
        SQL;
    $out = [];
    foreach (Db::instance()->run($sql, $bind)->fetchAll() as $f) {
        $out[(string) $f['public_id']] = [
            'name'  => (string) $f['original_name'],
            'size'  => (int) $f['size_bytes'],
            // 그림으로 그릴 수 있나 — 검사를 마친(깨끗한) 그림 파일만
            // 실행 내용이 든 파일은 미리보기가 403 이다(F-65) — 그림으로 그리지 않고 카드로
            'image' => strncmp((string) $f['mime'], 'image/', 6) === 0 && (string) $f['scan_status'] === 'clean' && $f['exec_content'] !== true,
        ];
    }
    return $out;
}

/**
 * 페이지 링크 블록(`link_to_page`)의 제목 지도 — **볼 수 있는 페이지만**(목록 조건절). 없는 id 는 「볼 수 없는 페이지」.
 *
 * @param  list<string>        $pageIds
 * @param  array<string,mixed> $u
 * @return array<string,array{title:string,icon:?string}>
 */
function community_link_titles(array $pageIds, array $u): array
{
    $pageIds = array_values(array_unique(array_filter($pageIds, static fn ($p): bool =>
        preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $p) === 1)));
    if ($pageIds === []) {
        return [];
    }
    [$visible, $visBind] = community_visible_pages_where($u);
    $names = [];
    $bind  = $visBind;
    foreach (array_slice($pageIds, 0, 200) as $i => $pid) {
        $names[]          = 'CAST(:lp' . $i . ' AS uuid)';
        $bind[':lp' . $i] = (string) $pid;
    }
    $in  = implode(', ', $names);
    $sql = <<<SQL
        SELECT p.public_id, p.title, p.icon
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
         WHERE p.deleted_at IS NULL
           AND {$visible}
           AND p.public_id IN ({$in})
        SQL;
    $out = [];
    foreach (Db::instance()->run($sql, $bind)->fetchAll() as $t) {
        $out[(string) $t['public_id']] = ['title' => (string) $t['title'], 'icon' => $t['icon'] === null ? null : (string) $t['icon']];
    }
    return $out;
}

/**
 * 7-D4 글 안 멘션의 **이름 지도** (지시서 M-3·M-4 · 결정 L-4) — 페이지 응답(`GET /api/pages/{id}`)과 판 보기(`…/versions/{num}`)의 `mention_labels`.
 * 키는 `user:<id>`·`page:<uuid>`, 값은 `{label}`(페이지는 `{label, icon}`). **볼 수 있는 것만** 담는다 — 없는 것과 못 보는 것은 똑같이
 * 지도에 자리가 없고(응답 모양·상태·문구가 같다), 렌더러 두 벌이 그 자리에 「@볼 수 없음」을 그린다. 라벨에 「@」 는 없다(렌더러가 붙인다).
 *
 *   · 사람 — 보는 사람이 **쓰기 자격**(`Authz::canCommunityWrite` — 댓글 @ 후보·공유 후보와 같은 함수 · S2 M-1 「손님에게 사람 목록 0」)이고
 *     대상이 **활성 계정**이며 대상도 **쓰기 자격**(같은 함수 — 손님 계정 제외 · 댓글 @ 후보 `decideMentionTarget`·편집기 후보와 같은 범위)일 때만
 *     「이름 (아이디)」(`tbox_person_label` — 이름이 없으면 아이디). 보는 사람이 쓰기 자격이 없으면 **SQL 을 부르지 않는다**.
 *     새 판정·새 정책 이름은 만들지 않는다(있는 함수 하나를 보는 사람·대상에 한 번씩) — SQL 은 활성 조건만, 대상 자격은 PHP 한 줄.
 *     손님 계정은 없는 계정과 똑같이 지도에 자리가 없다(「@볼 수 없음」). 보는 사람이 대상 자신이어도 같은 규칙.
 *   · 페이지 — `community_link_titles`(5-E 페이지 링크 블록 제목 지도 · 목록 조건절 `community_visible_pages_where`) **그대로**. 관리자
 *     특권 갈래도 그 조건절이 주는 대로다(규칙을 한 벌 더 적지 않는다 — 판정 ↔ SQL 짝).
 *   · 날짜 — 지도에 없다(판정할 것이 없다 — 렌더러가 id 를 그대로 그린다).
 * id 모양은 `Doc::mentionShapeOk` 로 한 번 더 보고(검증기와 같은 함수), 최대 `Doc::MENTIONS_MAX`(200)개만 본다.
 * 감사 행은 남기지 않는다(읽기 응답의 곁가지 — `link_titles` 와 같다).
 *
 * @param  list<mixed>         $mentions `Doc::extract()['mentions']`
 * @param  array<string,mixed> $u        보는 사람
 * @return array<string,array{label:string,icon?:?string}>
 */
function community_mention_labels(array $mentions, array $u): array
{
    $userIds = [];
    $pageIds = [];
    foreach (array_slice($mentions, 0, Doc::MENTIONS_MAX) as $m) {
        if (!Doc::mentionShapeOk($m)) {
            continue;
        }
        if ($m['kind'] === 'user' && !in_array((int) $m['id'], $userIds, true)) {
            $userIds[] = (int) $m['id'];
        } elseif ($m['kind'] === 'page' && !in_array((string) $m['id'], $pageIds, true)) {
            $pageIds[] = (string) $m['id'];
        }
    }

    $out = [];
    // 사람 — 쓰기 자격이 없으면 여기서 끝(표를 읽지 않는다). 값은 `bigint[]` 한 값으로 바인딩(정수만 — 위에서 모양을 봤다).
    //   대상의 쓰기 자격은 판정 함수에 묻는다(역할 이름을 여기 적지 않는다 — `decideMentionTarget` 과 같은 모양의 대상 줄 id·role·team_id).
    if ($userIds !== [] && Authz::canCommunityWrite($u)) {
        $sql = <<<'SQL'
            SELECT id, username, display_name, role, team_id
              FROM public.users
             WHERE id = ANY(CAST(:ids AS bigint[]))
               AND is_active
            SQL;
        $idArray = '{' . implode(',', array_map('intval', $userIds)) . '}';
        foreach (Db::instance()->run($sql, [':ids' => $idArray])->fetchAll() as $r) {
            if (!Authz::canCommunityWrite(['id' => (int) $r['id'], 'role' => (string) $r['role'], 'team_id' => $r['team_id'] === null ? null : (int) $r['team_id']])) {
                continue;
            }
            $out['user:' . (string) (int) $r['id']] = [
                'label' => tbox_person_label($r['display_name'] === null ? null : (string) $r['display_name'], (string) $r['username']),
            ];
        }
    }
    // 페이지 — 5-E 제목 지도 그대로(볼 수 있는 것만 · 지운 것 없음)
    foreach (community_link_titles($pageIds, $u) as $pid => $t) {
        $out['page:' . (string) $pid] = ['label' => (string) $t['title'], 'icon' => $t['icon']];
    }
    return $out;
}

/**
 * 역참조 (F-23) — 이 페이지를 가리키는(링크 마크 `page:` · `link_to_page`) **볼 수 있는** 페이지들. 최대 50, 최근 고친 순.
 * 볼 수 없는 것은 목록에 없고, 몇 개가 가려졌는지도 말하지 않는다(결정 E-7).
 *
 * @param  array<string,mixed> $u
 * @return list<array{id:string,title:string,icon:?string}>
 */
function community_backlinks(string $pagePublicId, int $pageId, array $u): array
{
    [$visible, $visBind] = community_visible_pages_where($u);
    $sql = <<<SQL
        SELECT DISTINCT p.public_id, p.title, p.icon, p.updated_at
          FROM community.page_links l
          JOIN community.pages p ON p.id = l.from_page_id
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
         WHERE l.to_page_id = CAST(:target AS uuid)
           AND p.id <> CAST(:self AS bigint)
           AND p.deleted_at IS NULL
           AND {$visible}
         ORDER BY p.updated_at DESC
         LIMIT 50
        SQL;
    $out = [];
    foreach (Db::instance()->run($sql, array_merge([':target' => $pagePublicId, ':self' => $pageId], $visBind))->fetchAll() as $r) {
        $out[] = ['id' => (string) $r['public_id'], 'title' => (string) $r['title'], 'icon' => $r['icon'] === null ? null : (string) $r['icon']];
    }
    return $out;
}

/**
 * 커뮤니티 요청 하나를 처리한다. 돌아오지 않는다(언제나 응답을 내보내고 끝난다).
 *
 * @param string $modulePath `/community` 를 뗀 경로 (`/`, `/api/pages`, `/p/<uuid>` …)
 * @param string $method     요청 메서드
 */
/** 8-A: shared verification metadata for API and export. IDs never leave this result. */
function community_verify_info(array $row): array
{
    $at = $row['verified_at'] ?? null;
    $expires = $row['verify_expires_at'] ?? null;
    $state = $row['verify_state'] ?? ($at === null ? 'none' : ($expires !== null && strtotime((string) $expires) <= time() ? 'expired' : 'verified'));
    return ['state' => $state,
        'by' => $at === null ? null : ['name' => (string) ($row['verified_by_display_name'] ?: $row['verified_by_name'] ?? '')],
        'at' => $at, 'expires_at' => $expires,
        'edited_after' => $at !== null && strtotime((string) $row['updated_at']) > strtotime((string) $at)];
}

function community_procedure_count(int $pageId): int
{
    $sql = <<<'SQL'
        WITH RECURSIVE branch AS (
          SELECT id, page_kind FROM community.pages WHERE id=CAST(:id AS bigint) AND deleted_at IS NULL
          UNION ALL SELECT p.id,p.page_kind FROM community.pages p JOIN branch b ON p.parent_page_id=b.id WHERE p.deleted_at IS NULL
        ) SELECT count(*) FROM branch WHERE page_kind='procedure'
        SQL;
    return (int) Db::instance()->run($sql, [':id' => $pageId])->fetchColumn();
}

/** Lazy expiry notification, once per verification cycle, serialized per recipient. */
function community_expiry_notifications(int $userId): void
{
    $db = Db::instance();
    $db->begin();
    try {
        $db->run('SELECT pg_advisory_xact_lock(8301, CAST(:uid AS integer))',
        [':uid' => $userId]);
        $u=community_user_by_id($userId);
        if ($u===null) { $db->commit(); return; }
        [$visible,$bind]=community_search_pages_where($u);
        $bind[':expiry_uid']=$userId;
        $sql = <<<SQL
          SELECT p.public_id FROM community.pages p JOIN community.spaces s ON s.id=p.space_id
          LEFT JOIN public.users owner ON owner.id=s.owner_user_id
          WHERE p.deleted_at IS NULL AND p.verify_expires_at <= now() AND
            {$visible}
            AND CASE WHEN s.kind='personal' THEN s.owner_user_id WHEN s.kind='team' THEN p.verified_by ELSE p.author_id END = CAST(:expiry_uid AS bigint)
            AND NOT EXISTS (SELECT 1 FROM community.notifications n WHERE n.user_id=CAST(:expiry_uid AS bigint) AND n.page_id=p.id AND n.kind='verify_expired' AND n.created_at >= p.verified_at)
          ORDER BY p.verify_expires_at,p.id LIMIT 20
          SQL;
        foreach ($db->run($sql, $bind)->fetchAll() as $r) {
            $page = community_page_row((string) $r['public_id'], $userId);
            if ($page !== null) { community_notify($userId, 'verify_expired', $page, null, null); }
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}

function community_dispatch(string $modulePath, string $method): void
{
    $decodedPublic=rawurldecode(rawurldecode($modulePath));
    $publicPrefix=$decodedPublic==='/pub' || str_starts_with($decodedPublic,'/pub/');
    if ($publicPrefix) { define('COMMUNITY_PUBLIC_REQUEST',true); community_public_headers(); }
    // ─── [0-1] 라우트 표 자체 검사 ─────────────────────────────
    $problems = community_validate_routes();
    if ($problems !== []) {
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID
            . ' 커뮤니티 라우트 표가 올바르지 않아 요청을 처리하지 않습니다: ' . implode(' | ', $problems));
        Http::error(500, '서버 오류');
    }

    // ─── [1] 경로 매칭 ─────────────────────────────────────────
    $matched        = null;
    $pathMatched    = false;
    $allowedMethods = [];

    foreach (COMMUNITY_ROUTES as $route) {
        [$routeMethod, $path, $handler, $policy] = $route;
        if (preg_match(community_pattern_to_regex($path), $modulePath, $m) !== 1) {
            continue;
        }
        $pathMatched      = true;
        $allowedMethods[] = $routeMethod;
        if ($routeMethod !== $method) {
            continue;
        }
        array_shift($m);
        $matched = [$handler, $policy, array_values($m), $path];
        break;
    }

    if ($matched === null) {
        if ($publicPrefix && !$pathMatched) { community_public_missing(); }
        if ($pathMatched) {
            if (!headers_sent()) {
                header('Allow: ' . implode(', ', array_unique($allowedMethods)));
            }
            Http::error(405, '허용되지 않는 메서드입니다');
        }
        // 디버깅 묶음 U3 — t-box 라우터와 같은 갈래(브라우저 화면 요청이면 「찾을 수 없는 페이지」, 그 밖은 전과 같은 404 JSON)
        require_once __DIR__ . '/../site/not_found_page.php';
        qaredi_not_found(strpos($modulePath, '/api/') === 0);
    }

    [$handlerFile, $policy, $params, $routePattern] = $matched;

    /* 감사 로그의 `detail.route` — **전체 경로의 라우트 패턴**이다(`GET /community/api/pages/{id}`).
       실제 UUID 가 아니라 패턴을 적는 것은 t-box 와 같고, 접두를 붙이는 것은 런처 설계 2장의
       「감사에는 전체 경로를 그대로 적는다」 규칙이다. 인증 검사보다 먼저 정의한다 —
       401·403 으로 끝나는 행에도 경로가 남아야 한다. */
    define('TBOX_ROUTE', $method . ' ' . COMMUNITY_BASE . $routePattern);

    $isApi = strpos($modulePath, '/api/') === 0;
    // Public routes deliberately precede every session, CSRF and personalized shell call.
    if ($policy === Authz::POLICY_PUBLIC) {
        if (!defined('COMMUNITY_PUBLIC_REQUEST')) { define('COMMUNITY_PUBLIC_REQUEST', true); }
        community_public_headers();
        (static function(array $params,string $file): void { require $file; })($params,COMMUNITY_HANDLER_DIR.$handlerFile);
        exit;
    }

    // Public access is restricted to this handler, GET, and the three literal patterns.
    // memory_links + post visibility + active owner are checked inside the handler.
    if ($method === 'GET' && $handlerFile === 'memories_shared.php' && $policy === 'public'
        && in_array($routePattern, ['/memories/s/{token}', '/memories/s/{token}/feed', '/memories/s/{token}/media/{id}'], true)) {
        require COMMUNITY_HANDLER_DIR . 'memories_shared.php';
        exit;
    }


    /* ─── [2-T] 개인 프로그램 토큰 갈래 (Q:F-8, ARCH 4.8 — `public/index.php` [2-T]·[2-S] 와 같은 순서·같은 함수) ──
     *
     * ★ 들어오는 조건은 둘 다 참일 때뿐이다: 라우터가 매칭한 **라우트 패턴**이 `COMMUNITY_API_TOKEN_ROUTES` 안이고,
     *   `X-API-Token` 헤더가 실제로 왔다. 어느 라우트인가는 요청 값이 아니라 매칭 결과로만 판단한다.
     *   헤더가 없으면 아래 세션 경로 그대로다(웹 동작 불변). 헤더가 왔는데 값이 틀리면 401 이고 세션으로
     *   되돌아가지 않는다(보낸 인증 방법으로만 판정한다). 표 밖 라우트는 헤더를 읽지 않는다 → 세션 경로 → 401.
     * ★ `requireApiToken(true)` — 개인 토큰만(공용 `.env` 토큰은 403 `shared_token_not_permitted`, F-59 ⑤).
     *   만료 401 · 회수·틀림 401 · 계정 상태 403 은 그 함수 안에서 끝난다(감사도 그 안).
     * ★ 범위는 `Authz::tokenScopeAllows(범위, 라우트 정책)` 한 곳 — Q:F-10 부터 원자 집합: `community.read` 정책은
     *   원자 `community.read` 또는 `community.write`, `community.write` 정책은 `community.write` 만 참이다.
     *   밖이면 index.php [2-S] 와 같은 403 · 같은 감사 한 줄.
     * 건너뛰는 것: 세션 조회 · CSRF([3]) · 최초 로그인 강제 절차([3-1]) · 재인증 — 까닭은 index.php [2-T] 주석 그대로
     *   (토큰은 브라우저가 자동으로 싣지 않는다 · 절차 중인 계정은 requireApiToken 이 이미 403 으로 끊는다).
     * 그대로 타는 것: 아래 [4] 인가 · [4-1] 준비 확인 · 핸들러의 행 단위 판정 — 세션과 **같은 코드**다.
     */
    $viaApiToken = false;
    if (in_array($method . ' ' . $routePattern, COMMUNITY_API_TOKEN_ROUTES, true)
        && Http::apiTokenHeader() !== null
    ) {
        $user        = Auth::requireApiToken(true);   // 실패 시 401·403 + Audit::deny (Auth 안에서)
        $viaApiToken = true;
        // 감사 로그가 모든 행에 자동으로 붙이는 분류어 (Audit::autoDetail — index.php [2-T] 와 같은 자리·같은 값).
        define('TBOX_AUTH_METHOD', 'api_token');

        if (!Authz::tokenScopeAllows(TBOX_API_TOKEN_SCOPE, $policy)) {
            Audit::deny('auth.token', 'scope_not_permitted', [
                'target_type' => 'system',
                'target_name' => TBOX_ROUTE,
                'detail'      => ['token_scope' => TBOX_API_TOKEN_SCOPE]
                    + (defined('TBOX_API_TOKEN_ID') ? ['token_id' => constant('TBOX_API_TOKEN_ID')] : []),
            ]);
            Http::error(403, Http::MSG_TOKEN_SCOPE);
        }
    } else {
    // ─── [2] 인증 ─────────────────────────────────────────────
    // 화면 요청은 t-box 로그인 화면으로 보내고 돌아올 곳을 꼬리표로 남긴다 (ARCH 21.2).
    // 꼬리표 값은 **코드 상수**이고 요청에서 온 문자열이 아니다 — 오픈 리다이렉트가 생길 자리가 없다.
    if (Auth::currentUser() === null && !$isApi) {
        /* Q:F-7 B1-1 — 보던 화면(페이지·게시판·공간)이 허용 목록(Http::returnPath) 안이면 그 정규형을, 아니면 옛 키를 싣는다.
           요청 주소 원문은 그 함수만 지나고 Location 에는 정규형의 rawurlencode 글자만 들어간다(Http::redirect 가 한 번 더 확인). */
        $communityReturn = Http::returnPath((string) ($_SERVER['REQUEST_URI'] ?? ''));
        Http::redirect(Http::url(TBOX_LOGIN_PATH) . '?to='
            . ($communityReturn !== null && $communityReturn !== COMMUNITY_BASE . '/' ? rawurlencode($communityReturn) : COMMUNITY_LOGIN_TO));
    }
    $user = Auth::requireLogin();          // 실패 시 401 + Audit::deny (Auth 안에서)

    // ─── [3] CSRF ─────────────────────────────────────────────
    Http::requireCsrf();

    // ─── [3-1] 최초 로그인 강제 절차 관문 ──────────────────────
    // 커뮤니티에는 허용 목록이 **없다**. 비밀번호 변경·2단계 인증 등록은 t-box 쪽 창구에서만
    // 할 수 있으므로, 절차가 남은 계정에게 커뮤니티는 통째로 닫혀 있다(화면은 t-box 홈으로).
    $gateNext = Auth::nextStep($user);
    if ($gateNext !== null) {
        Audit::deny('auth.gate', $gateNext === 'password_change' ? 'password_change_required' : 'mfa_setup_required', [
            'target_type' => 'user',
            'target_id'   => (int) $user['id'],
            'target_name' => (string) $user['username'],
            'detail'      => ['next' => $gateNext],
        ]);
        if (!$isApi) {
            Http::redirect(Http::url(TBOX_HOME_PATH));
        }
        Http::json([
            'error' => $gateNext === 'password_change'
                ? Http::MSG_PASSWORD_CHANGE_REQUIRED
                : Http::MSG_MFA_SETUP_REQUIRED,
            'next'  => $gateNext,
        ], 403);
        exit;
    }
    }   // ← [2-T] 토큰 갈래 / 세션 경로의 갈림 끝 (Q:F-8). 아래 [4] 인가부터는 **둘 다** 탄다.

    // ─── [4] 인가 ─────────────────────────────────────────────
    if (!Authz::authorize($policy, $user)) {
        if (in_array($handlerFile, ['pages_export.php','page_print.php'], true)) { Audit::deny('page.export','role_not_permitted',['target_type'=>'page']); Http::error(403,Http::MSG_FORBIDDEN); }
        Audit::deny('authz.route', 'role_not_permitted', [
            'target_type' => 'system',
            'target_name' => TBOX_ROUTE,
            'detail'      => ['policy' => $policy],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }

    // 관리 성격의 커뮤니티 정책이 생기면 `Authz::REAUTH_POLICIES` 에 이름을 넣는 것만으로
    // 재인증이 따라붙는다. 지금 정책 둘은 그 목록에 없으므로 이 가지는 지나간다.
    // 토큰 갈래는 세션이 없어 「15분 안에 다시 확인했나」가 성립하지 않는다(index.php 와 같은 `!$viaApiToken`).
    if (!$viaApiToken && Authz::requiresReauth($policy) && ($user['reauth_fresh'] ?? false) !== true) {
        Audit::deny('auth.gate', 'reauth_required', [
            'target_type' => 'user',
            'target_id'   => (int) $user['id'],
            'target_name' => (string) $user['username'],
            'detail'      => ['next' => 'reauth', 'policy' => $policy],
        ]);
        if (!$isApi) {
            Http::redirect(Http::url(TBOX_REAUTH_PATH));
        }
        Http::json(['error' => Http::MSG_REAUTH_REQUIRED, 'next' => 'reauth'], 403);
        exit;
    }

    // ─── [4-1] 스키마 준비 확인 ────────────────────────────────
    // 인가 **뒤**에 둔다 — 로그인하지 않은 사람에게 「준비 중」을 알려 줄 이유가 없고,
    // 미로그인 302·401 이 이 안내보다 앞서야 한다(완료 기준 6의 실측 순서).
    if (!community_schema_ready()) {
        community_not_ready($isApi);
    }

    // ─── [5] 핸들러 실행 ───────────────────────────────────────
    // 파일명은 라우트 표에 적힌 고정 문자열이고 위 검사에서 형태와 존재를 확인했다.
    (static function (array $user, array $params, string $file): void {
        require $file;
    })($user, $params, COMMUNITY_HANDLER_DIR . $handlerFile);
    exit;
}

/* ───────────── 6-A 데이터베이스 도우미 ───────────── */

/**
 * JSON 글자(스키마 칸) → 속성 목록. 모르는 모양의 줄은 버린다(판정·거르기는 `DbProps` 가 아는 줄만 다룬다).
 *
 * @return list<array<string,mixed>>
 */
function community_db_schema_of($json): array
{
    $list = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : null);
    if (!is_array($list)) {
        return [];
    }
    return array_values(array_filter($list, static fn ($p): bool => DbProps::propShapeOk($p)));
}

/**
 * 행 값(`properties` 칸) → 지금 스키마에 있는 키만. 지운 속성의 값은 칸에 남아 있어도(결정 G-4) 내보내지 않는다.
 *
 * @return array<string,mixed>|stdClass
 */
function community_db_values_out(array $schema, $propsJson)
{
    $vals = is_string($propsJson) ? json_decode($propsJson, true) : (is_array($propsJson) ? $propsJson : null);
    $out  = [];
    if (is_array($vals)) {
        foreach ($schema as $p) {
            $k = (string) $p['key'];
            if (array_key_exists($k, $vals) && $vals[$k] !== null) {
                $out[$k] = $vals[$k];
            }
        }
    }
    return $out === [] ? new stdClass() : $out;
}

/** Response-only projection: never return relationship UUIDs without current read access.
 * Passing the batch title map avoids one query per row; stored values are not rewritten.
 */
function community_db_values_visible(array $schema, $propsJson, array $user, ?array $relationTitles = null)
{
    $values = (array) community_db_values_out($schema, $propsJson);
    if ($relationTitles === null) {
        [$relationTitles] = community_db_ref_titles($schema, [$values], $user);
    }
    foreach ($schema as $prop) {
        $key = (string) $prop['key'];
        if ($prop['type'] === 'relation' && isset($values[$key]) && is_array($values[$key])) {
            $values[$key] = array_values(array_filter($values[$key],
                static fn(string $id): bool => isset($relationTitles[$id])));
        }
    }
    return $values === [] ? new stdClass() : $values;
}

/**
 * 사람 속성 값으로 넣을 수 있나 — 있는 활성 계정이고 **그 페이지를 볼 수 있어야** 한다(특권만으로 보는 사람 제외 —
 * 4-D 담당자·5-D 멘션과 같은 `decideMentionTarget`). 없는 사람과 못 보는 사람은 같은 답(거짓)이다.
 */
function community_db_person_ok(string $username, string $pagePublicId): bool
{
    $t = community_user_by_name($username);
    if ($t === null || ($t['is_active'] ?? false) !== true) {
        return false;
    }
    $rowFor = community_page_row($pagePublicId, (int) $t['id']);
    return $rowFor !== null && CommunityAuthz::decideMentionTarget($t, $rowFor)[0];
}

/**
 * DB 한 줄 + 그 DB 페이지의 public_id. 없으면 null.
 *
 * @return array<string,mixed>|null
 */
function community_db_row(string $dbPublicId): ?array
{
    $sql = <<<'SQL'
        SELECT d.id, d.public_id, d.page_id, d.schema, d.views, d.updated_at, p.public_id AS page_public_id
          FROM community.databases d
          JOIN community.pages p ON p.id = d.page_id
         WHERE d.public_id = CAST(:id AS uuid)
        SQL;
    $row = Db::instance()->run($sql, [':id' => $dbPublicId])->fetch();
    return is_array($row) ? $row : null;
}

/** 이 DB 의 살아 있는 행 수 (한도 G-11) */
function community_db_row_count(int $dbId): int
{
    $sql = <<<'SQL'
        SELECT count(*) AS n FROM community.pages p WHERE p.database_id = CAST(:db AS bigint) AND p.deleted_at IS NULL
        SQL;
    $r = Db::instance()->run($sql, [':db' => $dbId])->fetch();
    return is_array($r) ? (int) $r['n'] : 0;
}

/**
 * 「DB 페이지 바로 아래 = 행」을 맞춘다 — 옮기기·되살리기·인계가 부모를 바꾼 뒤 부른다(부르는 쪽 트랜잭션 안).
 * 새 부모가 DB 페이지면 그 DB 의 행(값이 없으면 빈 객체), 아니면 행이 아니다(값은 버린다 — 결정 G-3,
 * 버린 값은 처리 이력에 한 줄씩 남는다).
 */
function community_db_sync_row(int $pageId, int $byUserId): void
{
    $sql = <<<'SQL'
        SELECT p.database_id, p.properties, d.id AS want_db, d.schema AS old_schema
          FROM community.pages p
          LEFT JOIN community.databases d ON d.page_id = p.parent_page_id
         WHERE p.id = CAST(:pid AS bigint)
        SQL;
    $r = Db::instance()->run($sql, [':pid' => $pageId])->fetch();
    if (!is_array($r)) {
        return;
    }
    $have = $r['database_id'] === null ? null : (int) $r['database_id'];
    $want = $r['want_db'] === null ? null : (int) $r['want_db'];
    if ($have === $want) {
        return;
    }
    if ($have !== null) {
        // 옛 DB 의 값은 버린다 — 무엇이 사라졌는지는 이력에 남긴다
        $oldSchemaSql = <<<'SQL'
            SELECT schema FROM community.databases WHERE id = CAST(:db AS bigint)
            SQL;
        $os     = Db::instance()->run($oldSchemaSql, [':db' => $have])->fetch();
        $schema = community_db_schema_of(is_array($os) ? $os['schema'] : null);
        $vals   = json_decode((string) $r['properties'], true);
        foreach ($schema as $p) {
            $k = (string) $p['key'];
            if (is_array($vals) && array_key_exists($k, $vals) && $vals[$k] !== null) {
                community_insert_property_event($pageId, 'db:' . $k, DbProps::display($p, $vals[$k]), null, $byUserId);
            }
        }
    }
    $upd = <<<'SQL'
        UPDATE community.pages
           SET database_id = CAST(:db AS bigint),
               properties  = CASE WHEN CAST(:db2 AS bigint) IS NULL THEN NULL ELSE '{}'::jsonb END
         WHERE id = CAST(:pid AS bigint)
        SQL;
    Db::instance()->run($upd, [':db' => $want, ':db2' => $want, ':pid' => $pageId]);
}

/* ───────────── 6-B 관계 · 파일 속성 도우미 ───────────── */

/**
 * DB 의 저장한 뷰 목록(모양이 틀린 줄은 버린다 — 스키마가 바뀌어 거르기가 더는 맞지 않는 뷰도 뺀다).
 *
 * @return list<array<string,mixed>>
 */
function community_db_views_of($json, array $schema): array
{
    $list = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : null);
    $out  = [];
    foreach (is_array($list) ? $list : [] as $v) {
        if (!is_array($v) || !is_string($v['id'] ?? null) || preg_match('/^v[0-9a-f]{8}$/', $v['id']) !== 1) {
            continue;
        }
        $clean = DbProps::cleanView(array_diff_key($v, ['id' => 1]), $schema);
        if ($clean !== null) {
            $out[] = ['id' => $v['id']] + $clean;
        }
    }
    return $out;
}

/**
 * 뷰 + 요청의 거르기·정렬 → 실제로 쓸 거르기·정렬. 거르기는 AND 로 잇고(합쳐 ≤ 10), 정렬은 요청이 있으면 요청.
 *
 * @return array{0:mixed,1:mixed}
 */
function community_db_effective(?array $view, $reqFilter, $reqSort): array
{
    $filter = $reqFilter;
    if ($view !== null && isset($view['filter'])) {
        $a = $view['filter']['and'] ?? [];
        $b = is_array($reqFilter) && isset($reqFilter['and']) && is_array($reqFilter['and']) ? $reqFilter['and'] : [];
        if ($reqFilter !== null && (!is_array($reqFilter) || array_keys($reqFilter) !== ['and'])) {
            throw new InvalidArgumentException('filter shape');
        }
        $filter = ['and' => array_merge($a, $b)];
    }
    $sort = $reqSort !== null ? $reqSort : ($view['sort'] ?? null);
    return [$filter, $sort];
}

/**
 * 관계·파일 값이 들어갈 수 있나 — 넣는 사람 기준. 틀리면 거짓(없는·못 보는·좁은 것은 **같은 답**).
 *   관계: 값의 행마다 — 있고 지워지지 않았고 **대상 DB 의 행**이고 넣는 사람이 읽을 수 있다
 *   파일: **새로 붙인** 파일만 5-E 첨부 규칙(`community_file_refs_ok`) — 넣는 사람이 볼 수 있고 `$audiences` 보다 좁지 않다
 *
 * @param array<string,mixed> $prop       속성 한 줄
 * @param mixed               $value      `DbProps::cleanValue` 를 지난 값(null 아님)
 * @param mixed               $before     지금 값(파일은 새로 붙인 것만 본다)
 * @param list<array>         $audiences  행을 읽는 범위(`CommunityAuthz::pageAudiences`)
 */
function community_db_refs_ok(array $prop, $value, $before, array $audiences, array $u): bool
{
    if ($prop['type'] === 'relation') {
        // 새로 넣은 행만 본다(독립 검토 6-B L-3 — 이미 있던 대상이 나중에 지워지거나 못 보게 돼도 칸의 다른 값은 고칠 수 있게)
        foreach (array_diff((array) $value, is_array($before) ? $before : []) as $rid) {
            $r = community_page_row((string) $rid, (int) $u['id']);
            if ($r === null || $r['deleted_at'] !== null || (string) ($r['db_public_id'] ?? '') !== (string) $prop['target']
                || !CommunityAuthz::decidePageRead($u, $r)[0]) {
                return false;
            }
        }
        return true;
    }
    if ($prop['type'] === 'file') {
        $new = array_values(array_diff((array) $value, is_array($before) ? $before : []));
        $ids = array_map(static fn (string $f): string => substr($f, 5), $new);
        return community_file_refs_ok($ids, $audiences, $u);
    }
    return true;
}

/**
 * 행들의 관계·파일 값 → 보는 사람 권한으로 거른 제목 지도 둘(`relation_titles` · `file_titles`).
 * 못 보는 행·파일은 지도에 없다 — 화면이 「볼 수 없는 행/파일」로 그린다.
 *
 * @param list<array<string,mixed>> $schema
 * @param list<mixed>               $valuesList 행마다의 값 객체
 * @return array{0:array<string,mixed>,1:array<string,mixed>}
 */
function community_db_ref_titles(array $schema, array $valuesList, array $u): array
{
    $rel   = [];
    $files = [];
    foreach ($schema as $p) {
        if ($p['type'] !== 'relation' && $p['type'] !== 'file') {
            continue;
        }
        foreach ($valuesList as $vals) {
            $v = is_array($vals) ? ($vals[$p['key']] ?? null) : (is_object($vals) ? ($vals->{$p['key']} ?? null) : null);
            foreach (is_array($v) ? $v : [] as $x) {
                if ($p['type'] === 'relation') {
                    $rel[] = (string) $x;
                } else {
                    $files[] = substr((string) $x, 5);
                }
            }
        }
    }
    return [community_link_titles(array_slice(array_values(array_unique($rel)), 0, 200), $u),
            community_file_titles(array_slice(array_values(array_unique($files)), 0, 200), $u)];
}

/**
 * 행 하나의 파일 속성 값 전부(첨부 규칙 재검사용 — 범위 넓히기). `tbox:` 를 뗀 UUID 목록.
 *
 * @return list<string>
 */
function community_db_row_files($propsJson, $schemaJson): array
{
    $schema = community_db_schema_of($schemaJson);
    $vals   = is_string($propsJson) ? json_decode($propsJson, true) : (is_array($propsJson) ? $propsJson : null);
    $out    = [];
    foreach ($schema as $p) {
        if ($p['type'] === 'file' && is_array($vals) && is_array($vals[$p['key']] ?? null)) {
            foreach ($vals[$p['key']] as $f) {
                $out[] = substr((string) $f, 5);
            }
        }
    }
    return $out;
}

/* ─── 7-B 템플릿 (C:F-31 · 결정 J-3) ─────────────────────────────
 * 판정은 `CommunityAuthz::decideTemplate*` 한 곳이다. 여기는 읽기·모양 맞추기만 한다. */

/** 범위마다 둘 수 있는 템플릿 수 (만든 사람 · 팀 · 사이트 · DB 하나) */
const COMMUNITY_TEMPLATE_LIMITS = ['personal' => 50, 'team' => 100, 'site' => 50, 'database' => 20];

/** DB 행 템플릿에 담는 속성 종류 — 사람·관계·파일은 **담지 않는다**(값마다 「볼 수 있는가」가 따로 있어,
    템플릿을 쓰는 사람에게 맞는지 알 수 없다 — 좁은 쪽) */
const COMMUNITY_TEMPLATE_PROP_TYPES = ['text', 'number', 'checkbox', 'url', 'email', 'phone', 'date', 'select', 'multi_select'];

/**
 * 템플릿 한 줄(지운 것 포함 — 판정이 `not_found` 로 가른다). 없으면 null.
 *
 * @return array<string,mixed>|null
 */
function community_template_row(string $publicId): ?array
{
    $sql = <<<'SQL'
        SELECT t.id, t.public_id, t.scope, t.owner_user_id, t.team_id, t.database_id,
               t.name, t.title, t.icon, t.doc, t.properties, t.seed_key,
               t.created_by, t.created_at, t.deleted_at,
               u.username      AS created_by_name,
               u.display_name  AS created_by_display_name,
               tm.name         AS team_name,
               d.public_id     AS db_public_id,
               dp.public_id    AS db_page_public_id
          FROM community.templates t
          LEFT JOIN public.users u       ON u.id  = t.created_by
          LEFT JOIN public.teams tm      ON tm.id = t.team_id
          LEFT JOIN community.databases d ON d.id = t.database_id
          LEFT JOIN community.pages dp    ON dp.id = d.page_id
         WHERE t.public_id = CAST(:id AS uuid)
        SQL;
    $row = Db::instance()->run($sql, [':id' => $publicId])->fetch();
    return is_array($row) ? $row : null;
}

/**
 * DB 행 템플릿이면 그 DB 페이지 행(보는 사람 몫), 아니면 null.
 *
 * @param  array<string,mixed> $tpl
 * @return array<string,mixed>|null
 */
function community_template_db_page(array $tpl, int $viewerId): ?array
{
    if ((string) $tpl['scope'] !== 'database' || ($tpl['db_page_public_id'] ?? null) === null) {
        return null;
    }
    return community_page_row((string) $tpl['db_page_public_id'], $viewerId);
}

/**
 * 속성 값 → 템플릿에 담을 수 있는 것만(지금 스키마에 있고 · 담는 종류이고 · 모양이 맞는 값).
 * 저장할 때도, 템플릿으로 행을 만들 때도(그 사이 스키마가 바뀌었을 수 있다) 이 함수를 지난다.
 *
 * @param  list<array<string,mixed>> $schema
 * @param  mixed                     $props  JSON 글자 또는 배열
 * @return array<string,mixed>
 */
function community_template_props(array $schema, $props): array
{
    $vals = is_string($props) ? json_decode($props, true) : (is_array($props) ? $props : null);
    $out  = [];
    foreach (is_array($vals) ? $vals : [] as $k => $v) {
        $prop = DbProps::find($schema, (string) $k);
        if ($prop === null || !in_array((string) $prop['type'], COMMUNITY_TEMPLATE_PROP_TYPES, true)) {
            continue;
        }
        [$ok, $clean] = DbProps::cleanValue($prop, $v);
        if ($ok && $clean !== null) {
            $out[(string) $k] = $clean;
        }
    }
    return $out;
}

/** 이 범위(그 주인)에 살아 있는 템플릿 수 — 한도 검사. 부르는 쪽 트랜잭션 안에서 잠근 뒤 다시 센다. */
function community_template_count(string $scope, ?int $key): int
{
    $col = ['personal' => 'owner_user_id', 'team' => 'team_id', 'database' => 'database_id'][$scope] ?? null;
    if ($scope === 'site') {
        $sql = <<<'SQL'
            SELECT count(*) AS n FROM community.templates t WHERE t.scope = 'site' AND t.deleted_at IS NULL
            SQL;
        $r = Db::instance()->run($sql)->fetch();
        return is_array($r) ? (int) $r['n'] : 0;
    }
    if ($col === null || $key === null) {
        return 0;
    }
    $sqls = [
        'owner_user_id' => "SELECT count(*) AS n FROM community.templates t WHERE t.scope = 'personal' AND t.deleted_at IS NULL AND t.owner_user_id = CAST(:k AS bigint)"
,
        'team_id'       => "SELECT count(*) AS n FROM community.templates t WHERE t.scope = 'team' AND t.deleted_at IS NULL AND t.team_id = CAST(:k AS bigint)"
,
        'database_id'   => "SELECT count(*) AS n FROM community.templates t WHERE t.scope = 'database' AND t.deleted_at IS NULL AND t.database_id = CAST(:k AS bigint)"
,
    ];
    $r = Db::instance()->run($sqls[$col], [':k' => $key])->fetch();
    return is_array($r) ? (int) $r['n'] : 0;
}

/**
 * 템플릿 한 줄 → 응답 모양. 계정 id·팀 id·DB 내부 번호는 나가지 않는다.
 *
 * @param  array<string,mixed>      $tpl
 * @param  array<string,mixed>      $u
 * @param  array<string,mixed>|null $dbPage
 * @return array<string,mixed>
 */
function community_template_out(array $tpl, array $u, ?array $dbPage, bool $withBody): array
{
    $by = null;
    if (($tpl['created_by_name'] ?? null) !== null) {
        $by = ['username' => (string) $tpl['created_by_name'],
               'display_name' => $tpl['created_by_display_name'] === null ? null : (string) $tpl['created_by_display_name']];
    }
    $out = [
        'id'         => (string) $tpl['public_id'],
        'name'       => (string) $tpl['name'],
        'title'      => (string) $tpl['title'],
        'icon'       => $tpl['icon'] === null ? null : (string) $tpl['icon'],
        'scope'      => (string) $tpl['scope'],
        'team'       => (string) $tpl['scope'] === 'team' ? (string) ($tpl['team_name'] ?? '') : null,
        'database'   => (string) $tpl['scope'] === 'database' ? (string) ($tpl['db_public_id'] ?? '') : null,
        'created_by' => $by,
        'created_at' => (string) $tpl['created_at'],
        // 디버깅 묶음 S2 검토 L-1 — 지우기는 쓰기 창구(`DELETE /api/templates/{id}` — community.write). 창구 값도 같은 함수와 AND
        'can_delete' => Authz::canCommunityWrite($u) && CommunityAuthz::decideTemplateDelete($u, $tpl, $dbPage)[0],
    ];
    if ($withBody) {
        $doc = is_string($tpl['doc']) ? json_decode((string) $tpl['doc'], true) : $tpl['doc'];
        $out['doc'] = is_array($doc) ? $doc : ['schema' => 1, 'blocks' => []];
        $props = is_string($tpl['properties']) ? json_decode((string) $tpl['properties'], true) : $tpl['properties'];
        $out['properties'] = is_array($props) && $props !== [] ? $props : new stdClass();
    }
    return $out;
}

/**
 * 볼 수 없는 템플릿에 닿은 일 — 403 + 감사 `template.read deny <code>` 한 줄 (템플릿 읽기·쓰기 창구가 같이 쓴다).
 * 사유 코드는 문장 표에 있는 셋으로만 남긴다(DB 행 템플릿의 판정 코드는 `not_owner` 로 모은다).
 *
 * @param array<string,mixed> $tpl
 */
function community_template_refuse(array $tpl, string $code, string $action = 'template.read'): void
{
    $known = $action === 'template.delete'
        ? [CommunityAuthz::CODE_NOT_OWNER, CommunityAuthz::CODE_ROLE_NOT_PERMITTED]
        : [CommunityAuthz::CODE_NOT_OWNER, CommunityAuthz::CODE_NOT_MEMBER, CommunityAuthz::CODE_ROLE_NOT_PERMITTED];
    $name = community_target_title((string) $tpl['name']);
    Audit::deny($action, in_array($code, $known, true) ? $code : CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'template',
        'target_id'   => (int) $tpl['id'],
        'target_name' => $name,
        'detail'      => ['template_name' => $name, 'scope' => (string) $tpl['scope']],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

/* ─── 7-C 임베드 허용 목록 (C:F-32 · T-33 · 결정 J-4 · K-1~K-6) ─────────────── */

/** 살아 있는 허용 호스트 수의 상한 (K-1) */
const COMMUNITY_EMBED_MAX_DOMAINS = 20;

/**
 * 지금 허용 목록의 호스트들(정렬). 한 요청 안에서는 한 번만 읽는다.
 *
 * @return list<string>
 */
function community_embed_hosts(): array
{
    static $hosts = null;
    if ($hosts === null) {
        $sql = <<<'SQL'
            SELECT e.host FROM community.embed_domains e WHERE e.removed_at IS NULL ORDER BY e.host
            SQL;
        /* 표를 읽지 못하면(마이그레이션·권한이 코드보다 늦은 반영 등) **빈 목록**으로 닫는다 — 페이지 열기가 500 이 되지 않고,
           모든 임베드가 「허용 목록 밖」 카드 · 새 임베드는 400 이다(독립 검토 7-C M-2). 부르는 쪽 트랜잭션 밖에서만 읽힌다. */
        try {
            $hosts = array_map(static fn (array $r): string => (string) $r['host'], Db::instance()->run($sql)->fetchAll());
        } catch (Throwable $e) {
            error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community embed_domains unreadable — 빈 목록으로 닫음');
            $hosts = [];
        }
        /* 이 사이트 자신·상위·하위 도메인은 목록에 있어도 쓰지 않는다 — 틀이 스크립트 + 같은 출처를 가지므로(COMMUNITY_EMBED_SANDBOX).
           등록 창구가 막지만, 설정(APP_URL)이 나중에 바뀌어도 여기서 한 번 더 닫는다. */
        $hosts = array_values(array_filter($hosts, static fn (string $h): bool => !community_embed_host_is_self($h)));
    }
    return $hosts;
}

/**
 * 이 호스트가 이 사이트 자신이거나 그 상위·하위 도메인인가 (사용자 결정 2026-09-24 — 틀에 스크립트 + 같은 출처를 주므로).
 * 사이트 주소는 `.env` 의 `APP_URL`. 주소를 못 읽거나 모르는 모양이면 **참**(닫힌 쪽 — 어떤 호스트도 틀로 그리지 않는다).
 * 견주기 전에 맞춘다(임베드 켜기 독립 검토 권고 5): 소문자 · 끝 점 떼기(`changkwon.mso.so.` 도 같은 이름) ·
 * 국제 도메인은 punycode(`idn_to_ascii` — intl 이 없거나 바꾸지 못하면 닫힌 쪽).
 * `APP_URL` 이 IP 면 이름으로 견줄 수 없으므로 **요청 머리글 Host 도** 자기 주소로 함께 본다 — 닫힌 쪽만 넓힌다
 * (Host 는 요청이 준 값이라 `APP_URL` 이 이름일 때는 쓰지 않는다 — 남의 Host 로 판정을 바꿀 수 없게).
 */
function community_embed_host_is_self(string $host): bool
{
    try {
        $raw = (string) parse_url(Env::get('APP_URL'), PHP_URL_HOST);
    } catch (Throwable $e) {
        $raw = '';
    }
    $self = community_embed_norm_host($raw);
    if ($self === null) {
        return true;
    }
    $names = [$self];
    if (filter_var(trim($self, '[]'), FILTER_VALIDATE_IP) !== false) {
        $given = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($given !== '') {
            $reqHost = community_embed_norm_host((string) preg_replace('/:\d+$/D', '', $given));
            if ($reqHost === null) {
                return true;
            }
            $names[] = $reqHost;
        }
    }
    $host = community_embed_norm_host($host);
    if ($host === null) {
        return true;
    }
    foreach ($names as $name) {
        if ($host === $name || str_ends_with($host, '.' . $name) || str_ends_with($name, '.' . $host)) {
            return true;
        }
    }
    return false;
}

/**
 * 호스트 글자를 견줄 모양으로 — 앞뒤 빈칸 뗌 · 소문자 · 끝 점 뗌 · 비 ASCII(국제 도메인)는 punycode.
 * 빈 값 · 제어 문자·빈칸이 든 값 · intl 이 없는데 비 ASCII · 바꾸지 못한 값은 null(부르는 쪽이 닫힌 쪽으로 판정한다).
 */
function community_embed_norm_host(string $h): ?string
{
    $h = rtrim(strtolower(trim($h)), '.');
    if ($h === '' || preg_match('/[\x00-\x20\x7f]/', $h) === 1) {
        return null;
    }
    if (preg_match('/[\x80-\xff]/', $h) === 1) {
        if (!function_exists('idn_to_ascii')) {
            return null;
        }
        $a = idn_to_ascii($h, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (!is_string($a) || $a === '') {
            return null;
        }
        $h = rtrim(strtolower($a), '.');
    }
    return $h === '' ? null : $h;
}

/**
 * 이 임베드 주소들이 모두 허용 목록 안인가 (K-3 — **새로 넣은** 임베드만 넘긴다: 목록에서 빠진 호스트의 옛 임베드 때문에
 * 자기 페이지를 못 고치게 되지 않게). 주소의 모양은 검증기가 이미 보았다.
 *
 * @param list<string> $urls
 */
function community_embeds_ok(array $urls): bool
{
    if ($urls === []) {
        return true;
    }
    $allowed = community_embed_hosts();
    foreach ($urls as $u) {
        $host = Doc::embedHost((string) $u);
        if ($host === null || !in_array($host, $allowed, true)) {
            return false;
        }
    }
    return true;
}

/**
 * 렌더러 두 벌에 넘기는 임베드 재료 — 지금 허용 목록과 「틀을 여는가」(코드 상수 `COMMUNITY_EMBED_FRAMES`).
 *
 * @return array{hosts:list<string>,frames:bool}
 */
function community_embed_ctx(): array
{
    return ['hosts' => community_embed_hosts(), 'frames' => COMMUNITY_EMBED_FRAMES];
}

/** 400 — 목록 밖 임베드 (창구 셋이 같은 문구) */
const COMMUNITY_EMBED_REFUSED = '허용 목록에 없는 주소는 임베드할 수 없습니다. 관리자가 등록한 사이트(https)만 넣을 수 있습니다';

/**
 * 틀을 여는 스위치(`COMMUNITY_EMBED_FRAMES`)가 켜졌을 때만 — 이 응답의 CSP 에 **허용 목록 호스트만** `frame-src` 로 더한다 (K-5).
 * 사이트 기본 CSP(`bootstrap.php`)는 그대로 두고, 이미 나간 머리글을 읽어 뒤에 한 조각을 붙여 **이 응답만** 바꾼다.
 * 호스트는 DB CHECK · `Doc::isEmbedHost`(끝 줄바꿈까지 막는 `D`) · `header()` 의 CR/LF 거부 세 겹을 지난다. 목록이 비면 아무것도 하지 않는다.
 * ★ **nginx 도 CSP 머리글을 따로 붙인다**(`/etc/nginx/sites-enabled/tbox-v2` server 블록) — 브라우저는 두 정책을 모두 적용하므로,
 *   이 함수만으로는 틀이 열리지 않는다. 켜는 절차는 상수 + nginx 두 단계다(USER-CHECKS 7-C · 독립 검토 7-C M-1). nginx 쪽은
 *   이 화면 경로(`^/community/p/<UUID>$`)만 받는 location 이 CSP 를 뺀 보안 머리글을 다시 적는다(Qaredi `../scripts/apply-nginx-embed-csp-2026-09-26.sh`)
 *   — 그래서 이 함수가 부르는 화면은 **이 경로 하나**여야 한다(다른 화면에서 부르면 nginx CSP 가 남아 틀이 빈다).
 */
function community_embed_csp(): void
{
    if (!COMMUNITY_EMBED_FRAMES || headers_sent()) {
        return;
    }
    $hosts = array_values(array_filter(community_embed_hosts(), static fn (string $h): bool => Doc::isEmbedHost($h)));
    if ($hosts === []) {
        return;
    }
    $base = null;
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Security-Policy:') === 0) {
            $base = trim(substr($h, strlen('Content-Security-Policy:')));
        }
    }
    if ($base === null || stripos($base, 'frame-src') !== false) {
        return;   // 기본 정책을 모르면(또는 이미 있으면) 넓히지 않는다 — 닫힌 쪽
    }
    $value = $base . '; frame-src ' . implode(' ', array_map(static fn (string $h): string => 'https://' . $h, $hosts));
    /* nginx 의 FastCGI 머리글 버퍼(기본 4KB)를 넘기면 502 가 된다 — 너무 길면 넓히지 않는다(독립 검토 7-C L-4, 닫힌 쪽) */
    if (strlen($value) > 3000) {
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community embed csp too long — frame-src 를 더하지 않음');
        return;
    }
    header('Content-Security-Policy: ' . $value);
}

/* ─── 7-D2 관리자 팔레트 (C:F-33 · 결정 L-2 · 지시서 1.1 P-6·P-7·P-9·P-17) ─────────────── */

/** 400 — 등록되지 않은(또는 뺀) 팔레트 이름을 **새로** 넣은 저장 (창구 넷이 같은 문구 · 7-C `COMMUNITY_EMBED_REFUSED` 와 짝) */
const COMMUNITY_PALETTE_REFUSED = '등록되지 않은 회사 색이 들어 있습니다. 관리자가 팔레트에 등록한 색만 새로 쓸 수 있습니다';

/** 503 — 관리 창구 셋(더하기·고치기·빼기)이 팔레트 표를 쓸 수 없는 창(표 없음 · 앱 계정 권한 없음 — 반영 순서가 어긋남) */
const COMMUNITY_PALETTE_UNAVAILABLE = '지금은 팔레트를 고칠 수 없습니다 (서버 쪽 반영 전 — DB 작업이 끝나면 다시 하세요)';

/**
 * 관리 창구 셋이 **팔레트 표 문장**에서 받은 예외가 SQLSTATE 42P01(표 없음)·42501(권한 없음)이면
 * 503 `palette_unavailable` 로 닫고 끝낸다(`error_log` 한 줄 · 감사 0 — 쓰기 전에 실패했다 · P-17 과 같은 닫힌 쪽).
 * 그 밖의 예외는 아무것도 하지 않고 돌아온다 — 부르는 쪽이 그대로 던진다(넓게 삼키지 않는다).
 * 부르는 쪽은 트랜잭션을 되돌린 **뒤**, 감사 쓰기 **전** 문장의 예외에만 부른다(감사 표 쪽 문제를 이 문구로 가리지 않게).
 */
function community_palette_unavailable(PDOException $e): void
{
    $state = (string) $e->getCode();
    if ($state !== '42P01' && $state !== '42501') {
        return;
    }
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community palettes unavailable (SQLSTATE ' . $state . ') — 관리 창구 503');
    Http::json(['error' => COMMUNITY_PALETTE_UNAVAILABLE, 'reason' => 'palette_unavailable'], 503);
    exit;
}

/**
 * 살아 있는 팔레트 줄(이름순 — 바이트 순) `[{name, light, dark}]`. 한 요청 안에서는 한 번만 읽는다(`$fresh` 가 참이면 다시).
 * ★ 표를 읽지 못하면(반영 순서가 어긋난 창 · 권한) **빈 목록으로 닫는다** — 페이지 열기는 500 이 아니라 빈 CSS · 새 `pal:` 저장 400 ·
 *   편집기는 기본 9색(P-17 · 7-C M-2). 부르는 쪽 트랜잭션 **밖**에서만 부른다(실패한 SELECT 가 트랜잭션을 깨지 않게).
 * ★ 그릴 때 한 번 더 모양을 본다 — 모양 밖 줄(DB 를 손으로 고친 경우 등)은 건너뛰고 **요청당** `error_log` 한 줄
 *   (건너뛴 줄 수와 번호만 — 닫힌 쪽 · 값은 적지 않는다 · 한 요청에서 다시 읽어도 두 번 적지 않는다).
 *
 * @return list<array{name:string,light:string,dark:string}>
 */
function community_palette_rows(bool $fresh = false): array
{
    static $rows = null;
    static $skipLogged = false;
    if ($rows !== null && !$fresh) {
        return $rows;
    }
    $sql = <<<'SQL'
        SELECT p.id, p.name, p.light, p.dark FROM community.palettes p WHERE p.removed_at IS NULL
        SQL;
    try {
        $raw = Db::instance()->run($sql)->fetchAll();
    } catch (Throwable $e) {
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community palettes unreadable — 빈 목록으로 닫음');
        $rows = [];
        return $rows;
    }
    $out = [];
    $skipped = [];
    foreach ($raw as $r) {
        $one = ['name' => (string) $r['name'], 'light' => (string) $r['light'], 'dark' => (string) $r['dark']];
        if (!Palette::rowOk($one)) {
            $skipped[] = (int) $r['id'];
            continue;
        }
        $out[] = $one;
    }
    if ($skipped !== [] && !$skipLogged) {
        $skipLogged = true;
        sort($skipped);
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community palette rows skipped (모양 밖) n=' . count($skipped) . ' id=' . implode(',', $skipped));
    }
    $rows = Palette::sorted($out);
    return $rows;
}

/**
 * 살아 있는 팔레트 이름들 (저장 창구가 견준다 — P-9).
 *
 * @return list<string>
 */
function community_palette_names(): array
{
    return array_map(static fn (array $r): string => $r['name'], community_palette_rows());
}

/**
 * 이 팔레트 이름들이 모두 살아 있는가 — 저장 창구는 **새로 넣은** 이름만 넘긴다(이미 든 이름은 막지 않는다 — 뺀 이름이 있는 글도
 * 다른 곳을 고쳐 저장할 수 있게 · K-3 와 같은 태도). 빈 목록이면 표를 읽지 않고 참.
 *
 * @param list<string> $names
 */
function community_palettes_ok(array $names): bool
{
    if ($names === []) {
        return true;
    }
    $live = community_palette_names();
    foreach ($names as $n) {
        if (!in_array((string) $n, $live, true)) {
            return false;
        }
    }
    return true;
}

/**
 * 지금 팔레트의 버전(12자리) — 셸 `<link id="palette-css" … ?v=>` · CSS 창구 `ETag` · 목록 창구 `version` 이 같은 값(P-7 — 내용에서).
 * 표를 못 읽으면 빈 목록의 버전(`e3b0c44298fc`)이다.
 */
function community_palette_version(bool $fresh = false): string
{
    return Palette::version(community_palette_rows($fresh));
}

/** 9-A common rule loader. Only public page UUIDs leave the API. */
/** Shared query: owner list and single rule receive the same latest real-run metadata. */
function community_automation_select_sql(): string
{
    return <<<'SQL'
        SELECT a.*,p.public_id AS scope_page_uuid,
               latest.status AS last_status,latest.started_at AS last_started_at,
               latest.undone_at AS last_undone_at,latest.slot AS last_slot
        FROM community.automations a
        LEFT JOIN community.pages p ON p.id=a.scope_page_id
        LEFT JOIN LATERAL (
            SELECT status,started_at,undone_at,slot FROM community.automation_runs
            WHERE automation_id=a.id AND NOT dry_run ORDER BY id DESC LIMIT 1
        ) latest ON true
        SQL;
}
function community_automation_row(string $id): ?array
{
    $sql=community_automation_select_sql() . ' WHERE a.public_id=CAST(:id AS uuid)';
    $r=Db::instance()->run($sql, [':id'=>$id])->fetch();
    return is_array($r) ? $r : null;
}
function community_automation_rule(array $r): array
{
    return ['kind'=>$r['kind'],'name'=>$r['name'],'scope_page_id'=>$r['scope_page_uuid'],
        'scope_space_id'=>$r['scope_space_id'] === null ? null : (int)$r['scope_space_id'],
        'trigger'=>json_decode($r['trigger'],true), 'actions'=>json_decode($r['actions'],true), 'press_scope'=>$r['press_scope']];
}
function community_automation_out(array $r): array
{
    $rule=community_automation_rule($r); $next=null;
    $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul'));
    if ($r['is_enabled'] && $r['kind']==='recurring') {
        $candidate=Automation::latestSlot($rule['trigger'],$now);
        $pending=$candidate>=new DateTimeImmutable($r['created_at'])
            && (($r['last_slot'] ?? null)===null || $candidate>new DateTimeImmutable($r['last_slot']));
        $next=($pending ? $candidate : Automation::nextSlot($rule['trigger'],$now))->format(DATE_ATOM);
    } elseif ($r['is_enabled'] && $r['kind']==='due_reminder') {
        $candidate=$now->setTime(9,0);
        $next=($candidate>$now ? $candidate : $candidate->modify('+1 day'))->format(DATE_ATOM);
    }
    $last=($r['last_status'] ?? null)===null ? null :
        ['status'=>$r['last_status'],'started_at'=>$r['last_started_at'],'undone_at'=>$r['last_undone_at']];
    return array_merge($rule, ['id'=>$r['public_id'],'is_enabled'=>$r['is_enabled'],
        'last_dry_run_at'=>$r['last_dry_run_at'],'updated_at'=>$r['updated_at'],'denied_streak'=>(int)$r['denied_streak'],
        'next_run_at'=>$next,'last_result'=>$last]);
}
/** Only a source currently readable without privilege may appear as a repeat-page link. */
function community_automation_repeat_info(int $pageId, array $u): ?array
{
    $sql=<<<'SQL'
        SELECT a.name,r.steps,p.public_id AS created_page_uuid
        FROM community.automation_runs r JOIN community.automations a ON a.id=r.automation_id
        JOIN community.pages p ON p.id=ANY(r.created_page_ids)
        WHERE p.id=CAST(:id AS bigint) AND NOT r.dry_run ORDER BY r.id DESC LIMIT 1
        SQL;
    $row=Db::instance()->run($sql,[':id'=>$pageId])->fetch();
    if (!is_array($row)) { return null; }
    $source=null;
    // The saved successful step is immutable; editing the rule must not rewrite history.
    foreach (json_decode($row['steps'],true) as $step) {
        if (($step['type'] ?? '')!=='create_page' || ($step['status'] ?? '')!=='ok'
            || ($step['created_page'] ?? null)!==$row['created_page_uuid']) { continue; }
        $snapshot=$step['source'] ?? [];
        if (isset($snapshot['page'])) {
            $p=community_page_row($snapshot['page'],(int)$u['id']);
            if ($p!==null && CommunityAuthz::decideMentionTarget($u+['is_active'=>true],$p)[0]) {
                $source=['kind'=>'page','id'=>$p['public_id'],'title'=>$p['title'],
                    'url'=>Http::communityUrl('/p/'.$p['public_id'])];
            }
        } elseif (isset($snapshot['template'])) {
            $t=community_template_row($snapshot['template']);
            if ($t!==null && CommunityAuthz::decideTemplateRead($u,$t,community_template_db_page($t,(int)$u['id']))[0]) {
                $source=['kind'=>'template','id'=>$t['public_id'],'title'=>$t['name'],
                    'url'=>Http::communityUrl('/?template='.$t['public_id'])];
            }
        }
        break;
    }
    return ['name'=>$row['name'],'source'=>$source];
}
function community_automation_owned(string $id, array $u): array
{
    $row = community_automation_row($id);
    if ($row === null || !CommunityAuthz::decideAutomationManage($u,$row)[0]) { Http::error(404,Http::MSG_NOT_FOUND); }
    return $row;
}
/** Ordinary source/parent gates; no administrator-only read may drive copied pages. */
function community_automation_plan(array $a, array $u, DateTimeImmutable $now): array
{
    $me = (int)$u['id']; $template=isset($a['source']['template']);
    $source=$template ? community_template_row($a['source']['template']) : community_page_row($a['source']['page'],$me);
    $templateDb=$template && $source!==null ? community_template_db_page($source,$me) : null;
    if ($source===null || ($template
        ? !CommunityAuthz::decideTemplateRead($u,$source,$templateDb)[0]
        : (!CommunityAuthz::decideMentionTarget($u+['is_active'=>true],$source)[0] || $source['page_kind']!=='procedure'))) {
        throw new InvalidArgumentException('source_denied');
    }
    $parentUuid=$a['parent'];
    // 7-B: database templates always create rows in their own database, including omitted parents.
    if ($template && $source['scope']==='database') {
        if ($templateDb===null || ($parentUuid!==null && $parentUuid!==$templateDb['public_id'])) { throw new InvalidArgumentException('template_db_mismatch'); }
        $parentUuid=$templateDb['public_id'];
    }
    $parent=$parentUuid===null ? null : community_page_row($parentUuid,$me);
    if ($parentUuid!==null && ($parent===null || !CommunityAuthz::decidePageChange($u,$parent)[0])) { throw new InvalidArgumentException('parent_denied'); }
    if ($parent!==null && ($parent['space_kind']==='board' || $parent['database_id']!==null || community_page_depth((int)$parent['id'])>=CommunityAuthz::MAX_DEPTH)) { throw new InvalidArgumentException('parent_shape'); }
    if (!$template && ($parent===null || !in_array((int)$parent['id'],community_subtree_live_ids((int)$source['id']),true))) { throw new InvalidArgumentException('source_scope'); }
    $space=$parent===null ? community_my_personal_space($me) : community_space_row((int)$parent['space_id']);
    if ($space===null || $space['kind']==='board') { throw new InvalidArgumentException('space_denied'); }
    // C:T-405: an editable parent in a different team does not widen a team template.
    if ($template && $source['scope']==='team' && $space['kind']==='team' && (int)$space['team_id']!==(int)$source['team_id']) { throw new InvalidArgumentException('template_scope'); }
    $rowDbId=null; $rowProps=null;
    if ($parent!==null && $parent['dbself_public_id']!==null) {
        $dbInfo=community_db_row($parent['dbself_public_id']);
        if ($dbInfo===null) { throw new InvalidArgumentException('parent_denied'); }
        $rowDbId=(int)$dbInfo['id'];
        if (community_db_row_count($rowDbId)>=DbProps::MAX_ROWS) { throw new InvalidArgumentException('too_many_rows'); }
        $rowProps=$template && $source['scope']==='database'
            ? community_template_props(community_db_schema_of($dbInfo['schema']),$source['properties']) : [];
    }
    [$doc]=Doc::templateBody(json_decode($source['doc'],true));
    $doc=Doc::freshIds($doc);
    if (!Doc::validate($doc,COMMUNITY_DOC_STAGE)['ok']) { throw new InvalidArgumentException('document_invalid'); }
    return ['title'=>Automation::title($a['title_pattern'],$source['title'],$now),'parent_title'=>$parent['title'] ?? null,
        'parent'=>$parent,'space'=>$space,'doc'=>$doc,'status'=>$a['status'] ?? null,
        'database_id'=>$rowDbId,'properties'=>$rowProps,'icon'=>$template ? $source['icon'] : null,
        'due'=>isset($a['due_in_days']) ? $now->modify('+' . $a['due_in_days'] . ' days')->format('Y-m-d') : null];
}
function community_automation_scope(array $rule, array $u, bool $validateActions=true): array
{
    $page = null; $spaceId = null;
    if (($rule['scope_page_id'] ?? null) !== null) {
        $page = community_page_row($rule['scope_page_id'],(int)$u['id']);
        if ($page === null || !CommunityAuthz::decidePageChange($u,$page)[0]) { throw new InvalidArgumentException('scope_denied'); }
        if (in_array($rule['kind'],['on_status','due_reminder'],true)) {
            if (!(($page['space_kind']==='personal' && (int)$page['space_owner_user_id']===(int)$u['id'])
                || ($page['space_kind']==='team' && (int)$page['space_team_id']===(int)($u['team_id'] ?? 0)))) { throw new InvalidArgumentException('scope_owner_required'); }
        }
    }
    if (($rule['scope_space_id'] ?? null) !== null) {
        $space = community_space_row((int)$rule['scope_space_id']);
        if ($space === null || !(($space['kind']==='personal' && (int)$space['owner_user_id']===(int)$u['id'])
            || ($space['kind']==='team' && (int)$space['team_id']===(int)($u['team_id'] ?? 0)))) { throw new InvalidArgumentException('scope_denied'); }
        $spaceId = (int)$space['id'];
    }
    foreach ($validateActions ? $rule['actions'] : [] as $a) {
        if ($a['type']==='create_page') { community_automation_plan($a,$u,new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul'))); }
        if (in_array($rule['kind'],['on_status','due_reminder'],true) && ($a['type']!=='notify' || $a['text_key']!==($rule['kind']==='on_status' ? 'review_requested':'due_tomorrow'))) { throw new InvalidArgumentException('fixed_rule'); }
    }
    return [$page === null ? null : (int)$page['id'],$spaceId];
}
function community_automation_create_page(array $plan, array $u, string $automationId, string $runId): array
{
    $db = Db::instance(); $uuid = tbox_uuid_v4(); $space=$plan['space']; $parent=$plan['parent']; $extract=Doc::extract($plan['doc']);
    if ($plan['database_id']!==null) {
        $currentSchema=$db->run('SELECT schema FROM community.databases WHERE id=CAST(:db AS bigint) FOR UPDATE',
        [':db'=>$plan['database_id']])->fetchColumn();
        $plan['properties']=community_template_props(community_db_schema_of($currentSchema),$plan['properties']);
        if (community_db_row_count($plan['database_id'])>=DbProps::MAX_ROWS) { throw new InvalidArgumentException('too_many_rows'); }
    }
    $visibility = $space['kind']==='personal' && $parent !== null ? CommunityAuthz::baseVisibility($parent) : 'private';
    $sql=<<<'SQL'
      INSERT INTO community.pages(public_id,space_id,parent_page_id,position,title,visibility,doc,plain_text,block_count,author_id,last_edited_by,status,due_date,database_id,properties,icon,search_tsv)
      VALUES(CAST(:uuid AS uuid),:space,:parent,:pos,:title,:visibility,CAST(:doc AS jsonb),:plain,:blocks,:user,:editor,:status,CAST(:due AS date),CAST(:dbid AS bigint),CAST(:props AS jsonb),:icon,strip(to_tsvector('simple',:search))) RETURNING id
      SQL;
    $pid=(int)$db->run($sql,[':uuid'=>$uuid,':space'=>(int)$space['id'],':parent'=>$parent['id'] ?? null,
        ':pos'=>community_next_position((int)$space['id'],$parent===null ? null:(int)$parent['id']),':title'=>$plan['title'],':visibility'=>$visibility,
        ':doc'=>json_encode($plan['doc'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),':plain'=>$extract['plain_text'],':blocks'=>count($extract['blocks']),
        ':user'=>(int)$u['id'],':editor'=>(int)$u['id'],':status'=>$plan['status'],':due'=>$plan['due'],
        ':dbid'=>$plan['database_id'],':props'=>$plan['properties']===null ? null : json_encode($plan['properties']===[] ? new stdClass() : $plan['properties'],JSON_THROW_ON_ERROR),':icon'=>$plan['icon'],
        ':search'=>community_search_text($plan['title'],$extract['plain_text'])])->fetchColumn();
    community_rewrite_derived($pid,$extract);
    Audit::allow('page.create',['target_type'=>'page','target_id'=>$pid,'target_name'=>community_target_title($plan['title']),
        'detail'=>['automation_id'=>$automationId,'run_id'=>$runId,'space_kind'=>$space['kind']]]);
    return community_page_row($uuid,(int)$u['id']);
}
/** Same executor for dry-run, HTTP button presses and CLI. All writes, including run log, commit together. */
function community_automation_execute(array $initial, int $executorId, bool $dry, ?DateTimeImmutable $slot=null, ?array $eventPage=null, ?int $eventActor=null, ?array $requestUser=null, ?DateTimeImmutable $clock=null, ?int $pageBudget=null, ?string $expectedKind=null): array
{
    $db=Db::instance(); $db->begin();
    try {
        $db->run('SELECT id FROM community.automations WHERE id=CAST(:id AS bigint) FOR UPDATE',
        [':id'=>(int)$initial['id']]);
        $r=community_automation_row($initial['public_id']);
        if ($r===null || $r['deleted_at']!==null || (!$dry && !$r['is_enabled'])) { throw new InvalidArgumentException('disabled'); }
        if ($expectedKind!==null && $r['kind']!==$expectedKind) { $db->commit(); return ['skipped'=>true,'reason'=>'trigger_changed']; }
        $u=$db->run('SELECT id,username,display_name,role,team_id,is_active,expires_at FROM users WHERE id=CAST(:id AS bigint) FOR SHARE',
        [':id'=>$executorId])->fetch();
        if (!is_array($u)) { throw new InvalidArgumentException('executor_missing'); }
        if ($requestUser !== null) {
            if ($u['role'] !== ($requestUser['real_role'] ?? $requestUser['role'])) { throw new InvalidArgumentException('executor_changed'); }
            $u['role']=$requestUser['role'];
        }
        if (PHP_SAPI==='cli') { Audit::automationActor($executorId); }
        $now=($clock ?? new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->setTimezone(new DateTimeZone('Asia/Seoul'));
        if ($slot!==null && !$dry && $db->run('SELECT 1 FROM community.automation_runs WHERE automation_id=:aid AND slot=CAST(:slot AS timestamptz) AND NOT dry_run',
        [':aid'=>(int)$r['id'],':slot'=>$slot->format(DATE_ATOM)])->fetchColumn()) { $db->commit(); return ['skipped'=>true]; }
        if ($r['kind']==='button' && !$dry && $db->run("SELECT 1 FROM community.automation_runs WHERE automation_id=:aid AND run_by=:uid AND NOT dry_run AND started_at>now()-interval '10 seconds'",
        [':aid'=>(int)$r['id'],':uid'=>$executorId])->fetchColumn()) { throw new InvalidArgumentException('duplicate_press'); }
        $active=$u['is_active'] && ($u['expires_at']===null || strtotime($u['expires_at'])>time()) && Authz::canCommunityWrite($u);
        $runId=tbox_uuid_v4(); $steps=[]; $pages=[]; $publicPages=[]; $notices=[]; $wouldCreate=[]; $wouldNotify=[]; $denied=0;
        $rule=community_automation_rule($r);
        if (!$dry && $pageBudget!==null && count(array_filter($rule['actions'],static fn(array $a): bool => $a['type']==='create_page'))>$pageBudget) {
            $db->commit(); return ['skipped'=>true,'deferred'=>true];
        }
        // Recheck scope for non-button triggers on every invocation. Button caller separately gates its bound page.
        $scopeAllowed=true;
        if ($r['kind']!=='button') {
            try { community_automation_scope($rule,$u,false); } catch (InvalidArgumentException $e) { $scopeAllowed=false; }
        }
        if ($r['kind']==='button' && !$dry) {
            $bound=community_page_row($r['scope_page_uuid'], $executorId);
            if ($bound===null || !CommunityAuthz::decidePageRead($u,$bound)[0] || !($r['press_scope']==='owner' ? (int)$r['owner_user_id']===$executorId : CommunityAuthz::decidePageChange($u,$bound)[0])) { throw new InvalidArgumentException('button_denied'); }
        }
        $eventValid=true;
        if ($eventPage!==null) {
            $db->run('SELECT id FROM community.pages WHERE public_id=CAST(:uuid AS uuid) FOR SHARE',
        [':uuid'=>$eventPage['public_id']]);
            $eventPage=community_page_row($eventPage['public_id'], $executorId);
            $eventValid=$eventPage!==null && CommunityAuthz::decideMentionTarget($u,$eventPage)[0]
                && ((int)($r['scope_page_id'] ?? 0)===(int)$eventPage['id'] || (int)($r['scope_space_id'] ?? 0)===(int)$eventPage['space_id']);
            if ($eventValid && $r['kind']==='on_status') { $eventValid=$eventPage['status']==='review'; }
            if ($eventValid && $r['kind']==='due_reminder') { $eventValid=$eventPage['due_date']===$now->modify('+1 day')->format('Y-m-d') && !in_array($eventPage['status'],['done','hold'],true); }
        }
        $lastPage=$eventPage;
        foreach ($rule['actions'] as $a) {
            try {
                if (!$active || !$scopeAllowed || !$eventValid) { throw new InvalidArgumentException('executor_denied'); }
                if ($a['type']==='create_page') {
                    if (count($pages)>=50 || count($wouldCreate)>=50) { throw new InvalidArgumentException('page_limit'); }
                    $plan=community_automation_plan($a,$u,$slot ?? $now);
                    $wouldCreate[]=['title'=>$plan['title'],'parent_title'=>$plan['parent_title']];
                    if (!$dry) { $lastPage=community_automation_create_page($plan,$u,$r['public_id'],$runId); $pages[]=(int)$lastPage['id']; $publicPages[]=['id'=>$lastPage['public_id'],'title'=>$lastPage['title']]; }
                } else {
                    $targetPage=$lastPage;
                    if ($targetPage===null && $r['scope_page_uuid']!==null) { $targetPage=community_page_row($r['scope_page_uuid'],$executorId); }
                    $recipient=$r['owner_user_id'];
                    if ($eventPage!==null) { $recipient=$eventPage['space_kind']==='personal' ? $eventPage['space_owner_user_id']:$eventPage['author_id']; }
                    $recipientUser=community_user_by_id((int)$recipient);
                    if ($targetPage!==null && ($recipientUser===null || !CommunityAuthz::decideMentionTarget($recipientUser,community_page_row($targetPage['public_id'],(int)$recipient) ?? [])[0])) { throw new InvalidArgumentException('recipient_denied'); }
                    $wouldNotify[]=(string)($recipientUser['display_name'] ?: $recipientUser['username']);
                    if (!$dry && $targetPage!==null) {
                        if ($r['kind']==='due_reminder' && $db->run("SELECT 1 FROM community.notifications WHERE user_id=:uid AND page_id=:pid AND kind='due_tomorrow' AND created_at >= CAST(:day AS date)::timestamptz",
        [':uid'=>(int)$recipient,':pid'=>(int)$targetPage['id'],':day'=>$now->format('Y-m-d')])->fetchColumn()) { $steps[]=['type'=>$a['type'],'status'=>'skipped','reason'=>'already_notified']; continue; }
                        $notificationId=null;
                        if (community_notify((int)$recipient,$a['text_key'],$targetPage,null,$eventActor,$notificationId)) {
                            $notices[]=$notificationId;
                        }
                    }
                }
                $step=['type'=>$a['type'],'status'=>'ok'];
                if ($a['type']==='create_page' && !$dry) { $step['source']=$a['source']; $step['created_page']=$lastPage['public_id']; }
                $steps[]=$step;
            } catch (InvalidArgumentException $e) { $denied++; $steps[]=['type'=>$a['type'],'status'=>'denied','reason'=>$e->getMessage()]; }
        }
        $status=$denied===0 ? 'ok':($denied===count($rule['actions']) ? 'denied':'partial');
        $sql=<<<'SQL'
          INSERT INTO community.automation_runs(public_id,automation_id,run_by,trigger_kind,slot,dry_run,status,steps,created_page_ids,notification_ids)
          VALUES(CAST(:uuid AS uuid),:aid,:uid,:kind,CAST(:slot AS timestamptz),CAST(:dry AS boolean),:status,CAST(:steps AS jsonb),CAST(:pages AS bigint[]),CAST(:notifications AS bigint[]))
          SQL;
        $db->run($sql,[':uuid'=>$runId,':aid'=>(int)$r['id'],':uid'=>$executorId,':kind'=>$r['kind'],':slot'=>$slot?->format(DATE_ATOM),':dry'=>$dry?'true':'false',':status'=>$status,
            ':steps'=>json_encode($steps,JSON_THROW_ON_ERROR),':pages'=>'{'.implode(',',$pages).'}',':notifications'=>'{'.implode(',',$notices).'}']);
        if ($dry) { $db->run('UPDATE community.automations SET last_dry_run_at=clock_timestamp() WHERE id=:id',
        [':id'=>(int)$r['id']]); }
        else {
            $streak=$denied>0 ? (int)$r['denied_streak']+1:0;
            $disable=!$active || $streak>=3;
            $db->run('UPDATE community.automations SET denied_streak=:streak,is_enabled=CASE WHEN CAST(:disable AS boolean) THEN false ELSE is_enabled END WHERE id=:id',
        [':streak'=>$streak,':disable'=>$disable?'true':'false',':id'=>(int)$r['id']]);
            if ($denied>0) {
                $kind=$disable?'automation_disabled':'automation_denied';
                $already=$db->run("SELECT 1 FROM community.automation_runs WHERE automation_id=:id AND NOT dry_run AND public_id<>CAST(:uuid AS uuid) AND status IN ('partial','denied') AND started_at>=CURRENT_DATE",
        [':id'=>(int)$r['id'],':uuid'=>$runId])->fetchColumn();
                if (!$already || $disable) {
                    // The owner can inspect their rule even after losing the source. No source ID/title is copied.
                    $db->run('INSERT INTO community.notifications(user_id,kind,page_id,comment_id,actor_id) VALUES(:uid,:kind,NULL,NULL,NULL)',
        [':uid'=>(int)$r['owner_user_id'],':kind'=>$kind]);
                }
            }
        }
        $ctx=['target_type'=>'automation','target_id'=>(int)$r['id'],'target_name'=>community_target_title($r['name']),'detail'=>['automation_id'=>$r['public_id'],'run_id'=>$runId]];
        if ($status==='denied') { Audit::deny('automation.run','denied',$ctx); }
        else { Audit::allow('automation.run',$ctx,$status==='partial'?'partial':null); }
        $db->commit();
        return ['id'=>$runId,'status'=>$status,'dry_run'=>$dry,'steps'=>$steps,'would_create'=>$wouldCreate,'would_notify'=>$wouldNotify,'created_pages'=>$publicPages];
    } catch (Throwable $e) { $db->rollback(); throw $e; }
    finally { if (PHP_SAPI==='cli') { Audit::automationActor(null); } }
}

/** Button references retain immutable binding semantics across body edits. */
function community_doc_buttons(array $doc): array
{
    $out=[];
    $walk=static function(array $blocks) use (&$walk,&$out): void {
        foreach ($blocks as $b) {
            if (!is_array($b)) { continue; }
            if (($b['type'] ?? '')==='button') { $out[(string)$b['id']]=(string)$b['automation_id']; }
            if (isset($b['children']) && is_array($b['children'])) { $walk($b['children']); }
        }
    };
    $walk($doc['blocks'] ?? []); return $out;
}
function community_buttons_ok(array $doc, array $old, string $pageId, array $u): bool
{
    $existing=community_doc_buttons($old);
    foreach (community_doc_buttons($doc) as $blockId=>$id) {
        if (($existing[$blockId] ?? null)===$id) { continue; }
        $r=community_automation_row($id);
        if ($r===null || !CommunityAuthz::decideAutomationManage($u,$r)[0] || $r['kind']!=='button' || $r['scope_page_uuid']!==$pageId) { return false; }
    }
    return true;
}
function community_button_map(array $doc, array $page, array $u): array
{
    $out=[];
    foreach (array_unique(array_values(community_doc_buttons($doc))) as $id) {
        $r=community_automation_row($id);
        $can=$r!==null && $r['kind']==='button' && $r['deleted_at']===null && $r['is_enabled'] && $r['scope_page_uuid']===$page['public_id'] && Authz::canCommunityWrite($u)
            && ($r['press_scope']==='owner' ? (int)$r['owner_user_id']===(int)$u['id'] : CommunityAuthz::decidePageChange($u,$page)[0]);
        $out[$id]=['can_press'=>$can,'reason'=>$can?null:'누를 수 없음'];
    }
    return $out===[] ? []:$out;
}
