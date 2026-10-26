<?php
declare(strict_types=1);
require_once __DIR__ . '/../Design.php';

/**
 * src/views/shell_top.php — 공통 셸의 윗부분 (PRD F-20 · 10장 「공통 셸」, ARCH 3장 `src/views/`)
 *
 * 화면 하나하나가 자기 머리말·내비·사용자 칩을 따로 들고 있으면, 언젠가 한 화면만 고쳐진다.
 * 그래서 셸을 한 벌만 두고 모든 화면이 이 파일과 `shell_bottom.php` 사이에 본문만 적는다.
 *
 * 받는 변수 (호출하는 핸들러가 include 전에 정의한다)
 *   · $user      array   라우터가 넘긴 현재 사용자 (DB 에서 읽은 값. 요청 값이 아니다)
 *   · $pageTitle string  <title> 에 들어갈 화면 이름
 *   · $activeNav string  지금 켜 둘 내비 항목 — files|settings|activity|admin
 *                        (trash·users·org·audit·wiki 는 17-1 에서 내비에서 빠졌지만, 그 화면들이
 *                         여전히 값을 넘기므로 허용 목록에는 남겨 둔다 — 맞는 항목이 없으면
 *                         아무 항목도 켜지지 않을 뿐이다. `trash` 는 Q:F-4 U-2b 부터 「파일」 묶음의
 *                         「내 휴지통」과 「관리」 묶음의 「휴지통」이 함께 쓴다 — 둘 중 하나만 열린다)
 *   · $pageKey   string  <body data-page> — app.js 가 어떤 화면인지 고르는 열쇠
 *   · $pageCss   array   이 화면이 더 싣는 스타일시트 이름들 (25단계). **head 에서** 내보낸다 —
 *                        본문 한가운데에서 <link> 를 부르면 첫 그림이 스타일 없이 보였다가
 *                        바뀐다(FOUC). 값은 `public/assets/` 안의 **허용 목록**에 있는
 *                        파일 이름만 통과한다 — 경로를 조립하지 않으므로 `../` 가 섞일 자리가 없다.
 *   · $pageMsgTop bool   참이면 알림 줄(`#msg`)을 화면이 .page-head 바로 아래에 **한 번** 놓고
 *                        shell_bottom 은 그것을 내보내지 않는다 (id 중복 0 — 25단계).
 *   · $pageHelp  string  「사용 방법」 도움말의 키 (PRD F-38, ARCH 5.19). 쓰는 곳은
 *                        `shell_bottom.php` 하나이고 이 파일은 손대지 않는다.
 *
 * ★ HTML 에 들어가는 동적 값은 **사용자명·역할·pageTitle·activeNav·pageKey 다섯 개뿐**이고
 *   전부 htmlspecialchars(ENT_QUOTES, 'UTF-8') 를 거친다 (PRD S-03).
 *   activeNav·pageKey 는 허용 목록 밖 값이면 빈 문자열로 바꾼다 — 핸들러의 오타가
 *   속성값으로 새어 나가지 않게 하는 자리다. (7-D2 팔레트 CSS 버전 `$pagePalette` 는 12자리 16진 글자만 통과한다 — 아래 `$shellPalette`)
 *
 * ★ 이 파일에 **역할 비교를 두지 않는다** (PRD S-01, ARCH 13장). 관리 항목은 `hidden` 인 채로
 *   나가고, `/api/me` 의 `can_admin` 이 참일 때만 app.js 가 연다. 개발자도구로 항목을 되살려도
 *   `/admin` 은 라우터 정책(`role:Admin`)과 재인증 관문이 다시 막는다 — 표시는 편의,
 *   근거는 라우트 정책이다.
 *
 * ★ 17-1 (PRD F-24, ARCH 5.14): 관리 그룹(계정 관리·조직·권한·감사 로그·휴지통·위키)을
 *   일반 셸에서 **없앴다**. 대신 Admin 에게만 「관리자 페이지」 한 항목이 보이고, 세부 화면은
 *   허브(`/admin`)의 카드로만 갔다.
 * ★ 26단계 (PRD F-40, 사용자 지적 2026-09-15): 그 결과 세부 화면에서 **돌아올 길이 뒤로가기뿐**
 *   이었다. 「관리」 묶음에 하위 6화면 링크를 되살린다 — 경로도 정책도 그대로이고, 달라진 것은
 *   내비에 보이느냐뿐이다. 여는 값도 여전히 `can_admin` 하나다(이 파일에 역할 비교 없음).
 * ★ 26단계 (PRD F-39): `$user['acting']` 이 참이면 본문 맨 위에 「역할로 보기」 띠를 그린다.
 *   보는 중에는 관리 묶음이 닫히므로 그 띠의 「원래대로」가 유일한 복귀 경로다 — 그래서 서버가 그린다.
 *
 * ★ 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self', style-src 'self').
 *   아이콘은 shell_bottom.php 의 <template> 을 app.js 가 cloneNode 로 붙인다 (문자열 → HTML 변환 0).
 *
 * ★ Q:F-12 지시서 C (사이트 — WK-2 ① · WK-10 · WK-11 · WK-15, DESIGN 2.18 · 2.19, 커뮤니티 ARCHITECTURE 23.8):
 *   · 세 앱(자료실 · 업무 · 이야기) 공통 **앱 아이콘 줄**(`nav.app-rail`)을 이 파일 **한 곳**에서 그린다 — 목록은 Apps::all() 순서 그대로,
 *     아이콘은 Apps::ICON_PATHS(moa_icon.php), 지금 앱에 `aria-current="page"`. 960px 미만은 CSS 가 그리지 않는다(폰은 윗줄 앱 이름 ▾).
 *   · 커뮤니티 셸을 두 모드로 나눴다 — `$shellModule = 'community'`(업무: 홈 · 내 작업 · 프로젝트 · 문서 …) ·
 *     `'story'`(이야기: 스레드 · 여행 — memories_page.php 하나). 주소·라우트 정책은 그대로다(WK-15 A — 이 파일은 링크만 그린다).
 *   · 옛 사이드바의 세 공간 상시 표시(`nav.moa-space-nav`)는 아이콘 줄이 대신해 없앴다.
 *
 * ★ Q:F-12 U2 (사용감 라운드 — WK-19 A+ⓐ · WK-23 B, DESIGN 2.18 · 2.19 ⑨ · 기획 work-ux-plan.md 14.4 U2-a · U2-c):
 *   · 왼쪽 아이콘 줄(`nav.app-rail`)과 사이드바 `.moa-shell-links` 를 **없앴다**. 앱 바꾸기 · 계정 · 관리자 · 로그아웃은 앱 메뉴
 *     `details.appbar__apps` **하나**가 맡고, 그 요소를 사이드바 브랜드 줄에서 본문 `<main>` 첫 줄 `div.shell-tools`(오른쪽 위 ⋮⋮⋮ 격자)로
 *     **옮겼다**(복제 0 — 문서에 하나). 폰(<960)은 `div.shell-tools` 를 CSS 가 숨기고 윗줄 `#appbar-apps` 가 같은 details 를 연다(app.js).
 *   · 사이드바 머리 = ☰ 접기 단추(`#sidebar-collapse` — ≥960 에서만 보인다 · 누르면 app.js 가 `<body data-sidebar="collapsed">`) + 브랜드 링크.
 *     접힌 사이드바에서 남는 항목의 툴팁 글자는 고정 글자 `data-tip`(동적 값 0).
 *   · 서랍(`#mobile-nav-dialog`)의 「메뉴 / 앱·계정」 전환 단추를 없앴다 — ☰ 는 앱 내비만 연다.
 *   · 세 앱 셸(자료실 · 업무 · 이야기) 모두 같은 마크업 한 벌이다. 역할 비교 0 · 인라인 style·script 0 그대로.
 *
 * ★ 카페 C2 (사이트 Q:F-12 카페 · DESIGN 2.29 「카페 셸」 · 커뮤니티 ARCHITECTURE 25장):
 *   · 받는 변수 `$shellArea`(string) — 허용 목록 한 값 `'cafe'` 만 통과하고 업무 셸(`$shellModule = 'community'`)에서만 뜻이 있다.
 *     넘기는 쪽은 카페 게시판 화면 · 카페 글 화면(**읽기 판정을 지난 때만** — board_view.php · page_view.php)과 관리자 화면 둘(신고함 ·
 *     게시판 요청 — 라우트 정책 role:Admin)뿐이다. 이 파일에는 판정이 없다 — 값이 오면 `<body data-area="cafe">` 와 「우리 공간」 모드 사이드바를 그린다.
 *   · 「우리 공간」 모드: 브랜드 「우리 공간」(아이콘 spaces · 첫 화면 `/community/cafe` — 사이트 H3) · 카페 홈 · 받은 알림 · (공용) 검색 · 카페 게시판 목록
 *     (editor.js 가 `GET /api/boards?area=cafe` 의 답으로 채운다) · 관리 묶음(신고함 · 게시판 요청 — hidden 으로 나가고 `/api/me` 의 can_admin 이
 *     참일 때만 화면이 연다. 막는 것은 라우트 정책 role:Admin + 재인증). 업무 트리 · 프로젝트 · 포트폴리오 · 팀 · 문서 · 즐겨찾기 · 최근 · 더보기 · 「＋ 새로 만들기」는 그리지 않는다.
 */

/** 내비·본문 키의 허용 목록. 여기 없는 값은 빈 문자열이 된다. */
$shellKeys = [
    'files', 'programs', 'trash', 'settings', 'activity', 'users', 'org', 'audit', 'wiki', 'admin',
    // 커뮤니티 3단계 (커뮤니티 ARCHITECTURE.md 21.3) — 모듈 모드의 화면 둘.
    // app.js 는 이 두 키를 만나면 셸만 그리고 물러난다(t-box 화면 JS 가 돌지 않는다).
    'community-home', 'community-page', 'community-today', 'community-ai', 'community-threads', 'community-overview',
    // 커뮤니티 5-C — 게시판 화면
    'community-board',
    // 카페 C2 (DESIGN 2.29) — 관리자 화면 둘(신고함 · 게시판 요청). app.js 는 모르는 키라 셸만 그리고 물러나고 editor.js 가 cafe.js 로 그린다
    'community-reports', 'community-board-requests',
    // 사이트 H3 (DESIGN 2.29 「「우리 공간」 = 카페」) — 카페 홈 화면(/community/cafe). editor.js 가 cafe.js initHome 으로 그린다
    'community-cafe',
];

/**
 * 모듈 모드 (커뮤니티 ARCHITECTURE.md 21.3).
 *
 * 화면을 그리는 핸들러가 include 전에 `$shellModule = 'community';` 한 줄을 넘기면 브랜드·
 * 내비·그리드의 현재 앱이 그 모듈 것이 된다. **넘기지 않으면 지금까지와 한 글자도 같은
 * t-box 셸**이다 — 기본값이 t-box 라는 뜻이고, t-box 화면 스무 곳을 고칠 일이 없다.
 * 값은 허용 목록 안의 것만 통과한다(핸들러의 오타가 속성값으로 새지 않는다).
 */
// Q:F-12 C-2 — `story`(이야기 앱 — 스레드 · 여행)를 더했다. 허용 목록 밖 값은 지금처럼 t-box 셸이 된다.
$shellModuleKeys = ['community', 'story'];
$shellMod = isset($shellModule) && is_string($shellModule) && in_array($shellModule, $shellModuleKeys, true)
    ? $shellModule
    : '';

/** 카페 C2 — 「우리 공간」 모드(업무 셸에서만 · 허용 목록 한 값). 판정은 넘기는 핸들러가 끝냈다 — 이 파일은 고정 글자만 그린다 */
$shellCafe = $shellMod === 'community' && isset($shellArea) && $shellArea === 'cafe';

/** 그리드 팝업·아이콘 줄에서 `aria-current="page"` 가 붙는 앱의 key (`config/apps.json` 의 key — 모드 이름과 같다) */
$shellCurrentApp = $shellMod === '' ? 'tbox' : $shellMod;

/*
 * 앱 메뉴·아이콘 줄의 목록 (Qaredi C — ARCH-launcher 6장 · Q:F-12 DESIGN 2.18). 런처와 **같은 한 곳**에서 읽는다.
 * 부트스트랩의 require 목록에 새 줄을 더하지 않고 쓰는 곳에서 읽어 온다(Changelog.php 와 같은 방식).
 * 값은 저장소가 추적하는 `config/apps.json` 이고 요청 값이 한 글자도 섞이지 않는다.
 */
require_once __DIR__ . '/../Apps.php';
$shellApps = Apps::all();

/**
 * 셸이 보이는 이름 — 브랜드·`<title>` 꼬리표·윗줄 앱 전환 단추가 이 값 하나를 따른다.
 * Q:F-12 (WK-10) — 이름·설명·아이콘은 앱 목록의 지금 앱 줄에서 읽는다(「모아 자료실」 · 「모아 업무」 · 「모아 이야기」).
 * 목록에서 그 앱을 빼도 셸은 그려지도록 고정 글자를 기본값으로 둔다(값은 전부 코드·저장소 상수).
 */
$shellHereDefaults = [
    'tbox'      => ['자료실', '파일 보관 · 찾기 · 공유', 'library'],
    'community' => ['업무', '작업 · 프로젝트 · 문서', 'work'],
    'story'     => ['이야기', '스레드 · 여행 — 일상 나누기', 'community'],
];
[$shellHereName, $shellBrandSub, $shellBrandIcon] = $shellHereDefaults[$shellCurrentApp];
foreach ($shellApps as $shellHereRow) {
    if ($shellHereRow['key'] === $shellCurrentApp) {
        $shellHereName  = (string) $shellHereRow['name'];
        $shellBrandSub  = (string) $shellHereRow['desc'];
        $shellBrandIcon = (string) $shellHereRow['icon'];
    }
}
$shellBrandName = '모아 ' . $shellHereName;
if ($shellCafe) {
    // 카페 C2 (DESIGN 2.29 「앱 이름 자리 「우리 공간」」) — 고정 글자 · 아이콘은 Apps::ICON_PATHS 의 spaces(사람 셋)
    [$shellBrandName, $shellBrandSub, $shellBrandIcon] = ['우리 공간', '자유 · 익명게시판', 'spaces'];
}
/** 브랜드 표시의 색 갈래(moa.css 의 `.moa-module-mark--tbox|community` 둘뿐 — 이야기는 옛 커뮤니티 것 그대로) */
$shellMarkKey = $shellCurrentApp === 'tbox' ? 'tbox' : 'community';
// 사이트 H3 — 「우리 공간」 모드면 앱 판의 「우리 공간」 타일(config/apps.json 의 spaces — /community/cafe)에 aria-current
if ($shellCafe) {
    $shellCurrentApp = 'spaces';
}
/** 브랜드를 누르면 가는 그 앱의 첫 화면 — 고정 경로뿐 */
$shellBrandHref = $shellCafe ? Http::communityUrl('/cafe') : ($shellMod === 'community' ? Http::communityUrl('/')
    : ($shellMod === 'story' ? Http::communityUrl('/memories') : Http::url('/')));

$shellUser   = isset($user) && is_array($user) ? $user : [];
// 5-A″ — 「이름 (아이디)」, 이름이 없으면 「아이디」(모양은 bootstrap `tbox_person_label` 한 벌).
$shellName   = htmlspecialchars(tbox_person_label(
    isset($shellUser['display_name']) && is_string($shellUser['display_name']) ? $shellUser['display_name'] : null,
    (string) ($shellUser['username'] ?? '')
), ENT_QUOTES, 'UTF-8');

/*
 * F-47 버전 (PRD F-47, ARCH 5.23) — 사이드바 아래에 보이는 `v1.0.0` 한 줄.
 *
 * 부트스트랩의 require 목록에 새 줄을 더하지 않고 쓰는 곳에서 한 번 읽어 온다
 * (`Wiki.php`·`Sensitive.php` 와 같은 방식). 값은 저장소 루트 `VERSION` 한 줄이며
 * 요청·DB 와 무관하고, 아래에서 htmlspecialchars 를 거쳐 글자로만 나간다 (PRD S-03).
 * **로그인 전 화면(`login_page.php`)은 이 셸을 쓰지 않으므로 버전이 나가지 않는다** (S-09).
 */
require_once __DIR__ . '/../Changelog.php';
$shellVersion = htmlspecialchars(Changelog::version(), ENT_QUOTES, 'UTF-8');

/*
 * 26단계 「역할로 보기」 (PRD F-39, ARCH 5.20) — 셸이 **서버에서** 띠를 그린다.
 *
 * 왜 app.js 가 아니라 서버인가: 보는 중에는 사이드바의 관리 묶음이 닫힌다(유효 역할이 Admin 이
 * 아니므로 can_admin 이 거짓이다). 그러면 원래대로 돌아올 길이 이 띠뿐인데, 그 유일한 길을
 * 스크립트가 못 그리는 상황(오류·차단)에 잃으면 사용자는 30분을 기다려야 한다.
 *
 * ★ 여기에도 **역할 비교는 없다.** `acting` 은 Auth::currentUser 가 판정해 준 불린이고,
 *   이 파일은 그 값이 참일 때 글자를 그릴 뿐이다. 값은 전부 htmlspecialchars 를 거친다 (PRD S-03).
 */
$shellActing = ($shellUser['acting'] ?? false) === true;

/*
 * 디버깅 묶음 U H3 (2026-09-26) — 사이드바 「새 페이지」(커뮤니티)는 **쓸 수 있는 역할**에게만 보인다.
 * 값은 `/api/me` 의 `can_community_write` 와 같은 함수(`Authz::canCommunityWrite` — 라우트 정책 `community.write` 가 부르는 그 함수)를
 * 그대로 부른 결과이고, 숨김은 편의다: 단추를 되살려 눌러도 `POST /community/api/pages` 는 정책이 403 으로 막는다 (PRD S-01).
 * 서버가 처음부터 그리므로 손님 화면에 단추가 잠깐 보였다 사라지는 일이 없다.
 */
$shellCommunityWrite = $shellUser !== [] && Authz::canCommunityWrite($shellUser);

/*
 * 사이트 Q:F-11 (지시서 B · DESIGN 2.17) — 떠 있는 메신저 창을 여는 단추(사이드바 · 폰 윗줄)는 **두 모드 공용**이고,
 * 창(`messenger_window.php`)을 그리는 조건과 같다: 쓰기 자격(판정 함수) · 「역할로 보기」가 아님. 역할 비교 0.
 * 근거는 서버 — `/tbox/api/messages*` 는 `Member::requireMessenger` 가 같은 조건으로 403 을 준다. 숨김은 편의다.
 */
$shellMessenger = $shellCommunityWrite && !$shellActing;

/*
 * 역할의 **화면 표기** — 한글로 보이고 원래 값은 title 로 (DESIGN 5장 「등급·역할」, 디버깅 묶음 U M7 · 2026-09-26).
 * 값(세션·DB·창구의 `role`)은 그대로다 — 여기는 보이는 글자만 바꾼다. 표에 없는 값은 받은 그대로 보인다.
 * 새 PHP 함수를 만들지 않고 이 파일 안의 배열로 둔다(반영 직후 opcache 가 옛 파일을 쥐는 2초 동안 새 함수를 부르는 화면이
 * 500 이 난 적이 있다 — DESIGN 2.12, 2026-09-25). app.js `ROLE_LABELS` 와 같은 표다.
 */
$shellRoleNames = ['Admin' => '관리자', 'Manager' => '팀장', 'Employee' => '직원', 'Guest' => '손님'];
$shellRoleRaw   = (string) ($shellUser['role'] ?? '');

/** 사용자 칩에 보이는 역할 — 언제나 **유효 역할**이고, 보는 중이면 그 사실을 함께 적는다 */
$shellRoleText = $shellRoleNames[$shellRoleRaw] ?? $shellRoleRaw;
$shellRoleTitle = htmlspecialchars($shellRoleRaw, ENT_QUOTES, 'UTF-8');
if ($shellActing) {
    $shellRoleText .= ' (보기)';
}
$shellRole = htmlspecialchars($shellRoleText, ENT_QUOTES, 'UTF-8');

/*
 * 띠에 쓸 이름들. 보는 중일 때만 읽는다(평소 요청에는 쿼리가 늘지 않는다).
 * 값은 세션이 들고 있는 유효 team_id 이고 요청이 정하지 않는다 (PRD S-01).
 */
$shellActParts  = [];
$shellActActual = '';
if ($shellActing) {
    $shellActParts[] = $shellRoleNames[$shellRoleRaw] ?? $shellRoleRaw;

    $shellActTeamId = $shellUser['team_id'] ?? null;
    if ($shellActTeamId !== null && $shellActTeamId !== '') {
        $shellActRow = Db::instance()->run(
            'SELECT name FROM teams WHERE id = :id',
            [':id' => (int) $shellActTeamId]
        )->fetch();
        if (is_array($shellActRow)) {
            $shellActParts[] = (string) $shellActRow['name'];
        }
    }

    $shellActRealRole = (string) ($shellUser['real_role'] ?? '');
    $shellActActual = htmlspecialchars(
        (string) ($shellUser['username'] ?? '') . '(' . ($shellRoleNames[$shellActRealRole] ?? $shellActRealRole) . ')',
        ENT_QUOTES,
        'UTF-8'
    );
}
$shellActWhat = htmlspecialchars(implode(' · ', array_filter($shellActParts, static function ($v) {
    return $v !== '';
})), ENT_QUOTES, 'UTF-8');
$shellTitle  = htmlspecialchars(isset($pageTitle) && is_string($pageTitle) ? $pageTitle : '', ENT_QUOTES, 'UTF-8');
$shellActive = isset($activeNav) && is_string($activeNav) && in_array($activeNav, $shellKeys, true) ? $activeNav : '';
$shellPage   = isset($pageKey) && is_string($pageKey) && in_array($pageKey, $shellKeys, true) ? $pageKey : '';
$shellActive = htmlspecialchars($shellActive, ENT_QUOTES, 'UTF-8');
$shellPage   = htmlspecialchars($shellPage, ENT_QUOTES, 'UTF-8');

/**
 * 사용자 칩의 동그란 머리글자. **이스케이프 전 원본**에서 한 글자를 떼고 그 뒤에 이스케이프한다 —
 * 이스케이프한 문자열을 자르면 `&amp;` 같은 엔티티가 중간에서 잘릴 수 있다.
 * 값은 여전히 사용자명 하나에서 나온다(새 동적 값이 아니다).
 */
$shellRawName = isset($shellUser['display_name']) && is_string($shellUser['display_name']) && $shellUser['display_name'] !== ''
    ? $shellUser['display_name']   // 5-A″ — 이름이 있으면 이름의 첫 글자
    : (string) ($shellUser['username'] ?? '');
$shellInitial = preg_match('/^./u', $shellRawName, $shellFirst) === 1 ? $shellFirst[0] : '';
if ($shellInitial === '') {
    $shellInitial = '?';
} elseif (preg_match('/^[a-z]$/', $shellInitial) === 1) {
    // 영문 소문자 한 글자만 대문자로. mbstring 확장에 기대지 않는다(설치 대상 늘리기 금지).
    $shellInitial = strtoupper($shellInitial);
}
$shellInitial = htmlspecialchars($shellInitial, ENT_QUOTES, 'UTF-8');

/* DESIGN.md 2.12 — 화면 밝기 취향. 규칙(허용 목록 두 개만 통과, 그 밖은 속성 자체를 안 그린다)은
   셸·로그인·런처 세 화면이 같으므로 Http::themeAttr() 한 벌로 모았다 (ARCH-launcher 3장). */
$themeAttr = Http::themeAttr();

/** 자산 주소에 파일이 마지막으로 바뀐 시각(정수)을 붙여 옛 파일이 남지 않게 한다 (11단계) */
$shellCssVersion = (int) filemtime(__DIR__ . '/../../public/assets/app.css');

/** 본문 오른쪽 위 「내 계정」 아바타(`a.shell-tools__me`, DESIGN 2.18) — aria-label 에 표시 이름(이름이 없으면 아이디). 이스케이프는 여기서 한 번(PRD S-03) */
$shellMeLabel = htmlspecialchars($shellRawName, ENT_QUOTES, 'UTF-8');

/**
 * 25단계 — 화면이 더 싣는 스타일시트. **허용 목록 안의 이름만** 통과한다.
 * 핸들러의 오타나 어떤 요청 값도 `<link href>` 로 새어 나가지 못한다 (PRD S-03).
 * 지금은 더 싣는 스타일시트가 없다 — 목록이 비어 있으므로 `$pageCss` 는 어떤 값이든 버려진다.
 * 구조를 남겨 두는 이유는, 화면별 CSS 가 다시 생길 때 이 허용 목록을 거치게 하기 위해서다.
 */
$shellCssAllowed = [
    // 커뮤니티 3단계 — 문서 토큰·블록 클래스 한 벌 (DESIGN.md 2.14). 이름은 `public/assets/`
    // 기준의 **정확한 상대 경로**이고, 허용 목록 안의 값만 통과하므로 경로가 조립되지 않는다.
    'community/community.css', 'community/memories.css', 'community/overview.css', 'community/travel-plans.css',
];
$shellPageCss    = [];
if (isset($pageCss) && is_array($pageCss)) {
    foreach ($pageCss as $shellCssName) {
        if (is_string($shellCssName) && in_array($shellCssName, $shellCssAllowed, true)
            && !in_array($shellCssName, $shellPageCss, true)
        ) {
            $shellPageCss[] = $shellCssName;
        }
    }
}

/**
 * 7-D2 — 커뮤니티 관리자 팔레트 CSS (`$pagePalette`, 지시서 P-6·P-7). 커뮤니티 화면 셋(홈·페이지·게시판)의 핸들러가
 * `community_palette_version()` 을 넘기면 `<link id="palette-css" rel="stylesheet">` 한 줄을 그린다(주소 = `COMMUNITY_BASE` + `/palette.css?v=<버전>`).
 * **허용 목록 방식** — 값은 12자리 소문자 16진 글자만 통과하고, 아니면(없거나 다른 모양) 링크를 그리지 않는다. 주소는 코드 상수
 * `COMMUNITY_BASE` + 고정 글자라 요청 값이 섞일 자리가 없다. 같은 출처 CSS 파일이라 CSP `style-src 'self'` 그대로(인라인 `<style>` 0).
 * id 는 관리 창(palettes.js)이 더하기·고치기·빼기 뒤 `?v=` 를 바꿔 그 자리에서 다시 읽는 손잡이다.
 */
$shellPalette = isset($pagePalette) && is_string($pagePalette) && preg_match('/^[0-9a-f]{12}$/D', $pagePalette) === 1
    && defined('COMMUNITY_BASE') ? $pagePalette : '';

/**
 * F-70 — 설치형 웹 앱 화면 표시 (`$pageApp`). **허용 목록 한 값**(`alerts` — 휴대폰 경보 화면 push_app_page.php)만 통과하고,
 * 통과하면 머리에 매니페스트·아이폰 홈 화면 메타를, 바닥(shell_bottom)에 alerts-app.js 를 붙인다. 그 밖의 값은 빈 문자열.
 * 이름을 `$shellPwa` 로 둔 까닭: 아래 앱 그리드의 foreach 가 `$shellApp` 을 돌림 변수로 쓴다(겹치면 바닥에서 값이 바뀐다 — F-70 첫 시험에서 발견).
 * 붙는 글자는 전부 고정 문자열이라 요청 값이 섞일 자리가 없다 (PRD S-03).
 */
$shellPwa = isset($pageApp) && $pageApp === 'alerts' ? 'alerts' : '';

/*
 * 카페 C5 (DESIGN 2.19 ⑨ 「2026-10-14 사용자 지적 보정」 — 「페이지에 들어왔을 때 기본은 사이드바가 전부 간소화된 상태」) — ≥960 사이드바 접힘의 **첫 그리기**.
 * 사이트 Q:F-15 S2 (D-9 · Q4 A · DESIGN 2.19 ⑨ 2026-10-26 줄 — 「첫 방문은 펼침, 접으면 기억」) — 기본이 펼침으로 바뀌었다:
 * 쿠키 `qaredi_sidebar` 의 값이 정확히 `collapsed` 일 때만 `<body data-sidebar="collapsed">` 와 ☰ 의 aria 첫 값(접힘)을 그리고, 아니면(없음 · `open` · 다른 값) 펼친 채 —
 * 첫 HTML 부터라 깜빡임 0 · 머리 스크립트 0(CSP 그대로). 쿠키 값은 **비교만** 하고 HTML 에 내보내지 않는다(나가는 글자는 아래 고정 글자 둘뿐).
 * 쓰는 쪽은 app.js initSidebarCollapse(☰ 누름 — 접으면 collapsed · 펼치면 open · 지우지 않음). 모든 셸(업무 · 자료실 · 이야기 · 우리 공간)이 이 한 줄을 읽는다.
 * 폰(<960)은 CSS 가 이 속성을 무시한다(서랍 그대로).
 */
$shellSidebarOpen = !(isset($_COOKIE['qaredi_sidebar']) && $_COOKIE['qaredi_sidebar'] === 'collapsed');

/** 지금 켜 둘 항목에만 is-active 를 붙인다. 값은 위 허용 목록을 통과한 문자열이다. */
$shellNavClass = static function (string $key) use ($shellActive): string {
    return $key === $shellActive ? 'nav__item is-active' : 'nav__item';
};

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
}
?>
<!doctype html>
<html lang="ko"<?= $themeAttr ?><?= Design::attrs() ?><?= /* 7-D3 고대비(DESIGN 2.16) — 계정 칸이 참일 때만 고정 글자, 아니면 속성 없음 · 새 함수 0 */ ($shellUser['high_contrast'] ?? false) === true ? ' data-contrast="high"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= $shellTitle ?> · <?= htmlspecialchars($shellBrandName, ENT_QUOTES, 'UTF-8') ?></title>
<!-- 25단계 — 브라우저가 스스로 부르는 `/favicon.ico` 가 404 로 떨어져 모든 화면의 콘솔에
     오류 한 줄을 남겼다(시각 점검 도구가 세는 console_errors). 아이콘을 문서에 적어 둔다.
     Q:F-15 S1(DESIGN 5장 「브랜드 이름 3층」 · 2.33 D) — 옛 남색 방패 data URI 대신 「모아.」 파비콘 파일 하나(`img-src 'self'` · 고정 주소). -->
<link rel="icon" type="image/svg+xml" href="<?= Http::url('/assets/moa-favicon.svg') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/moa-favicon.svg') ?>">
<link rel="stylesheet" href="<?= Http::url('/assets/app.css') ?>?v=<?= $shellCssVersion ?>">
<?php foreach ($shellPageCss as $shellCssName): ?>
<link rel="stylesheet" href="<?= Http::url('/assets/') ?><?= $shellCssName ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/' . $shellCssName) ?>">
<?php endforeach; ?>
<?php if ($shellPalette !== ''): ?>
<link id="palette-css" rel="stylesheet" href="<?= COMMUNITY_BASE ?>/palette.css?v=<?= $shellPalette ?>">
<?php endif; ?>
<?php
/* Q:F-12 C-2 — 옛 「오늘」 화면(briefing.js 로 따로 시작하던 `community-today`)은 B 에서 308 넘김이 되어 이 셸을 그리지 않는다 —
   그 갈래를 지워, 같은 키를 쓰는 업무 화면도 다른 업무 화면처럼 editor.js 한 벌로 시작한다.
   이야기 셸(`story`)은 editor.js 를 싣지 않는다 — 업무 사이드바(트리·팀·게시판·알림)가 없고, 스레드 화면은 memories.js 가 맡는다. */
if ($shellMod === 'community'): ?>
<!-- 커뮤니티 화면의 동작 한 벌 (커뮤니티 ARCHITECTURE.md 21.3). `type="module"` 은 기본이
     defer 라 <head> 에 두어도 본문이 다 그려진 뒤 돌아간다 — shell_bottom 을 고치지 않고
     모듈 스크립트를 싣는 자리다. CSP 는 `script-src 'self'` 그대로이고 import map 을 쓰지
     않는다(ProseMirror 는 저장소 안 상대 경로로 서로를 부른다).
     ★ 여기서는 진입 파일만 `?v=<파일 시각>` 을 단다. editor.js 가 부르는 부품(우리 것·vendor/pm)은
     import 문 자체에 `?v=<버전>` 이 찍혀 있다 — `scripts/community_asset_version.php`. 진입 파일만
     버전을 달던 때는 7일 캐시된 옛 부품과 새 editor.js 가 섞여 모듈 연결이 실패했다(2026-09-24 핫픽스). -->
<script type="module" src="<?= Http::url('/assets/community/editor.js') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/community/editor.js') ?>"></script>
<?php endif; ?>
<!-- Q:F-2 통합 검색 창 (사이트 PRD Q:F-2 — 두 앱 공통). 사이드바 「검색」·Ctrl+K(⌘K). 창과 동작은 search.js,
     무엇이 나오나는 각 앱의 서버(커뮤니티 search.php · t-box files_list.php 전역 검색). 일반 스크립트(defer). -->
<script defer src="<?= Http::url('/assets/search.js') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/search.js') ?>"></script>
<?php if (in_array($shellPage, ['files','community-home'], true)): ?><script defer src="<?= Http::url('/assets/moa-usage.js') ?>?v=<?= (int) filemtime(__DIR__.'/../../public/assets/moa-usage.js') ?>"></script><?php endif; ?>
<!-- T:F-72 휴대폰 지문·얼굴로 로그인 — 「내 계정」 패널 · 재인증 창·화면의 「지문·얼굴로 확인」 · 권장 카드 · 로그아웃 표식(public/assets/passkey.js).
     defer 라 본문 끝의 app.js 뒤에 돈다(그쪽이 내놓는 qarediMe · qarediOpenConfirm · qarediReauthTarget 를 쓴다). -->
<script defer src="<?= Http::url('/assets/passkey.js') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/passkey.js') ?>"></script>
<?php if ($shellPwa !== 'alerts'): ?>
<!-- T:F-72 라운드 C · 결정 PK-D5 — 홈 화면 「Qaredi」 앱(모든 사람 · 시작 커뮤니티 · 범위 `/`). 경보 화면은 아래 경보 앱 매니페스트 하나만.
     매니페스트는 public 라우트(데이터 0 — 쿠키 없이 받아도 같다) · 서비스 워커 없음. 값은 전부 고정 글자다. -->
<link rel="manifest" href="<?= Http::url('/app.webmanifest') ?>">
<link rel="apple-touch-icon" href="<?= Http::url('/assets/qaredi-icon-192.png') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/qaredi-icon-192.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="모아">
<?php endif; ?>
<?php if ($shellPwa === 'alerts'): ?>
<!-- F-70 「Qaredi 경보」 설치형 웹 앱 (PRD F-70, ARCH 5.32) — 휴대폰 경보 화면(`/alerts-app`)만. 값은 전부 고정 글자다.
     매니페스트는 로그인·재인증 뒤에만 열리는 라우트라 쿠키를 싣는다(use-credentials — 같은 사이트 요청).
     아이폰은 매니페스트와 함께 이 메타·PNG 아이콘으로 홈 화면 앱을 만든다(iOS 16.4+ 웹 푸시는 홈 화면 앱 안에서만). -->
<link rel="manifest" href="<?= Http::url('/alerts-app.webmanifest') ?>" crossorigin="use-credentials">
<link rel="apple-touch-icon" href="<?= Http::url('/assets/alerts-icon-192.png') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/alerts-icon-192.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="모아 경보">
<?php endif; ?>
<?= Design::assets() ?>
</head>
<body data-service="<?= $shellMod === 'community' ? 'work' : ($shellMod === 'story' ? 'story' : 'library') ?>" data-page="<?= $shellPage ?>" data-base="<?= TBOX_BASE ?>"<?= $shellMod === '' ? '' : ' data-community-base="' . COMMUNITY_BASE . '"' ?><?= $shellCafe ? ' data-area="cafe"' : '' ?><?= $shellSidebarOpen ? '' : ' data-sidebar="collapsed"' ?>>
<a class="skip" href="#main">본문으로 건너뛰기</a>
<div class="app">
  <!-- Q:F-12 U2 (DESIGN 2.18 · WK-23 B) — 왼쪽 앱 아이콘 줄은 없앴다. 앱 바꾸기는 본문 오른쪽 위 ⋮⋮⋮ 격자(아래 앱 격자 줄). -->
  <!-- 좁은 화면(960px 미만)에서만 보이는 상단 바. 메뉴 단추가 좌측 내비를 접었다 편다.
       넓은 화면에서는 CSS 가 이 줄을 감추고 내비를 늘 펼쳐 둔다. -->
  <header class="appbar">
    <button id="nav-toggle" class="btn btn--icon" type="button"
            aria-expanded="false" aria-controls="sidebar" aria-label="메뉴 열기">
      <span class="icon" data-icon="menu"></span>
    </button>
    <!-- 사용감 라운드 1 UX-12 → Q:F-12 U2(DESIGN 2.18 「폰」) — 앱 이름이 곧 **앱 전환** 단추다: 누르면 본문의 앱 격자(`div.shell-tools` 안
         `details.appbar__apps` — 문서에 하나)를 열고 닫는다(app.js initMobileSidebar — `aria-expanded` 를 함께 맞춘다). 판은 윗줄 바로 아래 전체 폭.
         스크립트가 없으면 아무 일도 하지 않는 단추일 뿐이다. 글자는 셸 이름 그대로(동적 값 0). -->
    <button id="appbar-apps" class="appbar__brand" type="button" aria-controls="app-grid" aria-expanded="false"
            aria-label="<?= htmlspecialchars($shellBrandName, ENT_QUOTES, 'UTF-8') ?> — 앱 전환"><?= htmlspecialchars($shellBrandName, ENT_QUOTES, 'UTF-8') ?><span class="icon" data-icon="chevron"></span></button>
    <span class="appbar__page"><?= $shellTitle ?></span>

    <!-- 사용감 라운드 1 UX-10 · UX-3 — 폰 윗줄 오른쪽: 검색(누르면 사이드바와 **같은** 검색 창 · 입력 칸 초점 — search.js) ·
         커뮤니티는 알림 종(누르면 사이드바 「알림」과 **같은** 창 · 숫자는 같은 30초 폴링 한 벌이 두 자리에 그린다 — editor.js initNotifications ·
         새 요청 0). 이 줄은 960px 미만에서만 보인다(넓은 화면은 사이드바가 늘 보인다). 아이콘 단추는 DESIGN 4장 터치 ①(따로 떨어진 것 — `::before` 로 44). -->
    <span class="appbar__tools">
      <button id="appbar-search" class="btn btn--icon btn--plain" type="button" aria-haspopup="dialog" aria-controls="search-dialog" aria-label="검색">
        <span class="icon" data-icon="search"></span>
      </button>
<?php if ($shellMessenger): ?>
      <!-- Q:F-11 — 떠 있는 메신저 창(DESIGN 2.17). 두 모드 공용 · 종 옆(사이 12px — 터치 ①). 숫자는 창과 같은 한 벌의 폴링이 그린다(messenger.js). -->
      <button id="appbar-messenger" class="btn btn--icon btn--plain" type="button" data-messenger-open aria-haspopup="dialog" aria-controls="messenger-window" aria-expanded="false" aria-label="메신저">
        <span class="icon" data-icon="messages"></span><span class="badge badge--internal" data-message-unread hidden></span>
      </button>
<?php elseif (!empty($shellUser)): ?>
      <a id="appbar-messenger" class="btn btn--icon btn--plain" href="<?= Http::url('/messages') ?>" aria-label="메신저 이용 안내"><span class="icon" data-icon="messages"></span></a>
<?php endif; ?>
<?php if ($shellMod === 'community'): ?>
      <button id="appbar-bell" class="btn btn--icon btn--plain" type="button" aria-haspopup="dialog" aria-label="알림">
        <span class="icon" data-icon="bell"></span><span id="appbar-bell-count" class="badge badge--internal" hidden></span>
      </button>
<?php endif; ?>
<?php if (!empty($shellUser)): ?>
      <!-- 카페 C6 (DESIGN 2.30 「폰」) — 아바타 32 단추: 본문의 내 계정 판(`details#account-menu` — 문서에 하나)을 연다/닫는다(app.js initMobileSidebar · aria-expanded 함께).
           판은 앱 판의 폰 모양과 같이 윗줄 바로 아래 전체 폭. 서랍 · 앱 판 · 내 계정 판은 하나만 열린다. 이니셜 · 이름은 위에서 이스케이프한 값 그대로 -->
      <button id="appbar-account" class="btn btn--icon btn--plain" type="button" aria-controls="account-menu" aria-expanded="false" aria-label="내 계정 메뉴 — <?= $shellMeLabel ?>"><span aria-hidden="true"><?= $shellInitial ?></span></button>
<?php endif; ?>
    </span>
  </header>

  <!-- 서랍(960px 미만) — ☰ 는 지금 앱의 내비만 연다(Q:F-12 U2 — 「메뉴 / 앱·계정」 전환 단추는 없앴다. 앱·계정은 윗줄 앱 이름 ▾ 의 격자). -->
  <dialog id="mobile-nav-dialog" class="mobile-nav-dialog" aria-label="탐색 메뉴">
    <div class="mobile-nav-dialog__head">
      <button id="mobile-nav-close" class="btn btn--icon btn--plain" type="button" aria-label="메뉴 닫기"><span class="icon" data-icon="x"></span></button>
    </div>
  </dialog>

  <aside id="sidebar" class="sidebar" hidden>
    <!-- 사이드바 머리 (Q:F-12 U2 · DESIGN 2.19 ① · ⑨ · ⑩ 사이트 H5) — ☰ 접기 단추 + 앱 아이콘(링크 — 그 앱의 첫 화면)뿐. 앱 바꾸기 · 계정은 본문 오른쪽 위 격자(2.18).
         ☰(`#sidebar-collapse`)는 ≥960 에서만 보인다(폰 윗줄 `#nav-toggle` 과는 다른 단추). 누르면 app.js 가 `<body data-sidebar="collapsed">` 를 단다/뗀다 —
         속성 하나 · 마크업 재생성 0. 카페 C5 — 첫 값은 이 파일이 쿠키 `qaredi_sidebar`(Q:F-15 S2 — collapsed 면 접힘 · 아니면 펼침이 기본)로 그린다. -->
    <div class="brand moa-brand">
      <button id="sidebar-collapse" class="btn btn--icon btn--plain" type="button" aria-controls="sidebar" aria-expanded="<?= $shellSidebarOpen ? 'true' : 'false' ?>" aria-label="<?= $shellSidebarOpen ? '메뉴 접기' : '메뉴 펼치기' ?>">
        <span class="icon" data-icon="menu"></span>
      </button>
      <?php /* 사이트 H5 (DESIGN 2.19 ⑩ · 2.33 「셸 브랜드」) — 머리 = ☰ + 앱 아이콘(36 · 그 앱 첫 화면 링크)뿐 · 「모아 자료실」 · 「파일 보관 · 찾기 · 공유」 같은 글자는 그리지 않는다
               (지금 앱은 본문 제목 · 앱 판 aria-current · 폰 윗줄 앱 이름 ▾ 가 말한다). 이름은 링크 aria-label 「<앱> 첫 화면으로」 · 같은 글자 풍선(2.8 — app.css) */ ?>
      <a class="moa-brand-home" href="<?= $shellBrandHref ?>" aria-label="<?= htmlspecialchars($shellCafe ? '우리 공간' : $shellHereName, ENT_QUOTES, 'UTF-8') ?> 첫 화면으로">
        <span class="brand__mark moa-module-mark moa-module-mark--<?= $shellMarkKey ?>"><?php $moaIcon=$shellBrandIcon; require __DIR__.'/moa_icon.php'; ?></span>
      </a>
    </div>

<?php if ($shellCafe): ?>
    <!-- ─── 「우리 공간」 모드 사이드바 위쪽 (카페 C2 · DESIGN 2.29 「카페 셸」) — 카페 홈 · 받은 알림 → (아래 공용) 검색.
         「＋ 새로 만들기」 · 홈 · 내 작업은 업무 앱의 것이라 그리지 않는다. 받은 알림은 익명 댓글 · 신고 처리 알림을 받는 길이라 둔다(종 · 창은 업무와 같은 한 벌). -->
    <nav class="nav" aria-label="우리 공간">
      <a id="nav-cafe-home" class="<?= $shellNavClass('community-cafe') ?>" href="<?= Http::communityUrl('/cafe') ?>"<?= $shellActive === 'community-cafe' ? ' aria-current="page"' : '' ?> data-tip="우리 공간"><span class="icon" data-icon="home"></span><span>우리 공간</span></a>
      <button id="nav-community-bell" class="nav__item" type="button" aria-haspopup="dialog" data-tip="받은 알림">
        <span class="icon" data-icon="bell"></span><span>받은 알림</span>
        <span id="nav-community-bell-count" class="badge badge--internal" hidden></span>
      </button>
    </nav>
<?php elseif ($shellMod === 'community'): ?>
    <!-- ─── 업무 앱의 사이드바 위쪽 (Q:F-12 · DESIGN 2.19 ②③ — 시안 v1) ─────────────────────────────
         「＋ 새로 만들기」(셸의 검정 하나 · 쓰기 자격이 없으면 그리지 않는다 — `can_community_write` 와 같은 판정 함수) →
         홈 · 내 작업 · 받은 알림 → (아래 공용) 검색 · 메신저. 「오늘 할 일」은 없어졌다(옛 주소 `/community/today` 는 308 `/community/my-tasks`).
         메뉴는 2.15 `.menu` 틀 그대로 — 여닫기는 <details>, 밖 누르기·Esc·항목 누르기는 app.js initMenus 한 곳.
         「문서」 = 지금의 새 페이지(`#nav-community-new` — editor.js bindNewPage). 「작업」·「프로젝트」는 업무 화면 조각(D)이 동작을 붙이며 열 때까지 hidden 이다
         (2.15 — 보이는 항목이 하나뿐인 것은 괜찮다). 막는 것은 서버다: `POST /community/api/pages` 는 정책 community.write. -->
<?php   if ($shellCommunityWrite): ?>
    <details id="nav-new-menu" class="menu">
      <summary class="btn" data-tip="새로 만들기"><span class="icon" data-icon="plus"></span><span>새로 만들기</span></summary>
      <div class="menu__list">
        <button id="nav-new-task" class="menu__item" type="button" hidden><span class="icon" data-icon="check"></span><span>작업</span></button>
        <button id="nav-new-project" class="menu__item" type="button" hidden><span class="icon" data-icon="work"></span><span>프로젝트</span></button>
        <button id="nav-community-new" class="menu__item" type="button"><span class="icon" data-icon="files"></span><span>문서</span></button>
      </div>
    </details>
<?php   endif; ?>
    <!-- 홈 = 업무의 첫 화면(`/community/` — 업무 홈은 D 가 그린다) · 내 작업 = `/community/my-tasks`(D). 켜는 열쇠는 이 파일의 허용 목록 안 값만 —
         홈은 지금 첫 화면 키(`community-overview`), 내 작업은 옛 「오늘」 키(`community-today` — 그 화면을 잇는다). -->
    <!-- 알림 (5-D, PRD F-22) — 종 아이콘 + 안 읽은 수. 30초마다 `GET /community/api/notifications?unread=1`(editor.js initNotifications).
         누르면 알림 창 — 목록은 창구가 **지금도 볼 수 있는 페이지만** 제목을 준다(아니면 「볼 수 없는 페이지」). Q:F-12 — 이름 「받은 알림」(2.19 ③). -->
    <nav class="nav" aria-label="업무">
      <a id="nav-work-home" class="<?= $shellNavClass('community-overview') ?>" href="<?= Http::communityUrl('/') ?>" data-tip="업무 홈"><span class="icon" data-icon="home"></span><span>업무 홈</span></a>
      <a id="nav-work-my-tasks" class="<?= $shellNavClass('community-today') ?>" href="<?= Http::communityUrl('/my-tasks') ?>" data-tip="내 작업"><span class="icon" data-icon="check"></span><span>내 작업</span></a>
      <button id="nav-community-bell" class="nav__item" type="button" aria-haspopup="dialog" data-tip="받은 알림">
        <span class="icon" data-icon="bell"></span><span>받은 알림</span>
        <span id="nav-community-bell-count" class="badge badge--internal" hidden></span>
      </button>
    </nav>
<?php elseif ($shellMod === 'story'): ?>
    <!-- ─── 이야기 앱의 사이드바 (Q:F-12 · DESIGN 2.19 「이야기」 · WK-2 ① · WK-15 A — 주소는 지금 그대로 `/community/memories*`) ───
         「＋ 새 이야기」(검정 · 지금 스레드 쓰기 창 — 스레드 화면의 `window.MemoryComposer`, app.js 가 이어 준다) → 팔로잉 스레드 · 나의 스레드 ·
         새로운 이야기 발견(켜짐 표시는 memories.js 가 `data-thread-nav` 로) · 여행 계획(지금 여행 창 — travel-plans.js 가 `?travel=1` 에서 연다) → (아래 공용) 검색 · 메신저.
         업무 묶음(프로젝트 · 문서 · 팀 · 게시판)은 그리지 않는다. 쓰는 단추(새 이야기 · 여행 계획)는 쓰기 자격이 있을 때만 — 판정 함수 값, 역할 비교 0
         (숨김은 편의 — 쓰기 창구 `POST /community/api/memories` · 여행 창구는 라우트 정책이 다시 막는다). -->
<?php   if ($shellCommunityWrite): ?>
    <button id="nav-story-new" class="btn" type="button" data-story-compose aria-haspopup="dialog" aria-controls="compose-dialog" data-tip="새 이야기"><span class="icon" data-icon="plus"></span><span>새 이야기</span></button>
<?php   endif; ?>
    <nav class="nav" aria-label="이야기">
      <a class="nav__item" data-thread-nav="feed" href="<?= Http::communityUrl('/memories') ?>" data-tip="팔로잉 스레드"><span class="icon" data-icon="home"></span><span>팔로잉 스레드</span></a>
      <a class="nav__item" data-thread-nav="mine" href="<?= Http::communityUrl('/memories') ?>?view=mine" data-tip="나의 스레드"><span class="icon" data-icon="users"></span><span>나의 스레드</span></a>
      <a class="nav__item" data-thread-nav="discover" href="<?= Http::communityUrl('/memories') ?>?view=discover" data-tip="새로운 이야기 발견"><span class="icon" data-icon="search"></span><span>새로운 이야기 발견</span></a>
<?php   if ($shellCommunityWrite): ?>
      <a id="nav-story-travel" class="nav__item" href="<?= Http::communityUrl('/memories') ?>?travel=1" data-tip="여행 계획"><span class="icon" data-icon="log"></span><span>여행 계획</span></a>
<?php   endif; ?>
    </nav>
<?php endif; ?>

    <!-- 검색 (5-B → Q:F-2 두 앱 공통) — 누르거나 Ctrl+K(⌘K). 창과 동작은 search.js, 무엇이 나오나는 각 앱의 서버
         (커뮤니티 search.php — 페이지·댓글 / t-box files_list.php — 파일 전역 검색). 창에 커뮤니티 기준 주소를 싣는다
         (t-box 화면의 <body> 에는 커뮤니티 기준이 없다). 값은 고정 상수다. -->
    <button id="nav-search" class="nav__item nav-search" type="button" aria-haspopup="dialog" data-tip="검색">
      <span class="icon" data-icon="search"></span><span>검색</span><kbd class="nav-search__key">Ctrl K</kbd>
    </button>
<?php if ($shellMod === 'community'): ?>
    <!-- ─── 업무 앱의 사이드바 아래쪽 (커뮤니티 ARCHITECTURE.md 21.3 → Q:F-12 DESIGN 2.19 ④~⑧) ───────────────────
         즐겨찾기 · 최근(있을 때만) → 프로젝트 → 포트폴리오 → 팀 → 문서 → 공유받은 페이지 → 「더보기」(접힘).
         단추에도 같은 `.nav__item` 을 쓴다 — 화면 전용 클래스를 만들지 않는다(DESIGN 원칙 6).
         t-box 의 파일·설정·관리 묶음과 이야기 묶음은 이 모드에서 **그리지 않는다**: 다른 앱의 내비이고, 앱 사이 이동은 본문 오른쪽 위 앱 격자(2.18)가 맡는다. -->
    <dialog id="notify-dialog" class="modal modal--form modal--stack" aria-labelledby="notify-title">
      <div class="modal__head">
        <h2 id="notify-title" class="modal__title">받은 알림</h2>
        <button class="btn btn--icon btn--plain" type="button" data-notify-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="notify-msg" class="msg" role="alert" aria-live="polite"></p>
        <ul id="notify-list" class="list" aria-label="알림 목록"></ul>
        <p id="notify-empty" class="muted" hidden>알림이 없습니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-notify-close>닫기</button>
        <button id="notify-all-read" class="btn" type="button">모두 읽음</button>
      </div>
    </dialog>
    <!-- 즐겨찾기 · 최근 (개발 대기열 6 · Q:F-3a 라운드 A — 결정 M-1 순서: 즐겨찾기(접을 수 있음) → 최근 → … Q:F-12 2.19 「프로젝트 위에 그대로」).
         항목은 editor.js 가 창구 `GET /community/api/me/favorites` · `GET /community/api/me/recent` 의 답 —
         **지금 볼 수 있는 것만**(검색과 같은 조건절) — 으로 채운다. 이 파일에는 판단이 없다. 없으면 묶음째 숨는다.
         새 클래스 0: 제목은 `.nav-title`, 줄은 사이드바 트리와 같은 `.nav-tree` 줄(5-A′). 접기·펼치기는 <details> 가 한다
         (여닫는 스크립트 0 · 키보드로도 열린다 — DESIGN 2.14 페이지 트리와 같은 방식). 즐겨찾기 순서는 줄을 끌어 놓거나
         Alt+↑/↓ 로 바꾼다(editor.js — `PATCH /community/api/me/favorites`). -->
<?php   if ($shellCafe): ?>
    <!-- 카페 C2 (DESIGN 2.29) — 카페 게시판 목록(editor.js loadBoards 가 `?area=cafe` 의 답 — 볼 수 있는 카페 게시판만) · 관리(신고함 · 게시판 요청).
         관리 묶음은 hidden 으로 나가고 `/api/me` 의 can_admin(표시용)이 참일 때만 cafe.js 가 연다 — 막는 것은 라우트 정책 role:Admin + 재인증(이 파일에 역할 비교 0). -->
    <details id="nav-community-boards-group" data-sidebar-group open>
    <summary id="nav-community-board-title" class="nav-title" hidden>카페 게시판</summary>
    <nav id="nav-community-boards" class="nav" aria-label="카페 게시판" hidden></nav>
    </details>
    <div id="nav-cafe-admin" hidden>
      <p class="nav-title">관리</p>
      <nav class="nav" aria-label="카페 관리">
        <a id="nav-cafe-reports" class="<?= $shellNavClass('community-reports') ?>" href="<?= Http::communityUrl('/reports') ?>" data-tip="신고함"><span class="icon" data-icon="shield"></span><span>신고함</span></a>
        <a id="nav-cafe-requests" class="<?= $shellNavClass('community-board-requests') ?>" href="<?= Http::communityUrl('/board-requests') ?>" data-tip="게시판 요청"><span class="icon" data-icon="plus"></span><span>게시판 요청</span></a>
      </nav>
    </div>
<?php   else: ?>
    <div class="sidebar-document-tools">
      <button type="button" class="btn btn--small btn--quiet" id="sidebar-document-select" aria-pressed="false">문서 선택</button>
      <div id="sidebar-document-selection" hidden>
        <span id="sidebar-document-count" aria-live="polite">0개 선택</span>
        <button type="button" class="btn btn--small btn--quiet" id="sidebar-document-all">보이는 문서 전체 선택</button>
        <button type="button" class="btn btn--small btn--quiet" id="sidebar-document-clear">선택 해제</button>
        <button type="button" class="btn btn--small btn--quiet" id="sidebar-document-trash" disabled>선택 휴지통</button>
        <button type="button" class="btn btn--small btn--quiet" id="sidebar-document-done">완료</button>
        <p id="sidebar-document-note" class="help" role="status">한 번에 100개까지 선택합니다.</p>
      </div>
    </div>
    <details id="nav-community-fav-box" data-sidebar-group open hidden>
      <summary class="nav-title">즐겨찾기</summary>
      <nav id="nav-community-fav" class="nav" aria-label="즐겨찾기"></nav>
    </details>
    <details id="nav-community-recent-group" data-sidebar-group>
    <summary id="nav-community-recent-title" class="nav-title" hidden>최근</summary>
    <nav id="nav-community-recent" class="nav" aria-label="최근 본 페이지" hidden></nav>
    </details>
    <!-- 「최근」 더 보기 창 — 최근 본 페이지 전부(50까지)와 「최근 기록 지우기」(내 줄만 · 두 번 눌러야 — 댓글 지우기와 같은 방식,
         DESIGN 2.14 5-D). 목록은 알림 창과 같은 `.list`(2.13). 좁은 화면에서는 editor.js 가 창을 body 끝으로 옮긴다(알림 창과 같다). -->
    <dialog id="recent-dialog" class="modal modal--form modal--stack" aria-labelledby="recent-title">
      <div class="modal__head">
        <h2 id="recent-title" class="modal__title">최근 본 페이지</h2>
        <button class="btn btn--icon btn--plain" type="button" data-recent-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="recent-msg" class="msg" role="alert" aria-live="polite"></p>
        <p class="help">내가 연 페이지가 최근 것부터 50개까지 남습니다. 지금 볼 수 없게 된 페이지는 목록에 나오지 않습니다. 지워도 조회수·이력은 그대로입니다.</p>
        <ul id="recent-list" class="list" aria-label="최근 본 페이지 목록"></ul>
        <p id="recent-empty" class="muted" hidden>최근 본 페이지가 없습니다.</p>
      </div>
      <div class="form-actions">
        <button id="recent-clear" class="btn btn--danger" type="button">최근 기록 지우기</button>
        <button class="btn btn--quiet" type="button" data-recent-close>닫기</button>
      </div>
    </dialog>
    <!-- 프로젝트 · 포트폴리오 (Q:F-12 DESIGN 2.19 ④⑤ · 조각 D) —
         「프로젝트」는 서버가 여기서 그린다: `community_work_projects()` — 보는 사람의 **검색 조건절 안**(관리자·게시판 관리자 갈래를 끈 판)의
         프로젝트만, 내 즐겨찾기 → 최근 본 것 → 최근 고친 것 순으로 10개(더 있으면 「모든 프로젝트」 = 업무 홈의 목록). 이 파일에는 판단이 없다 —
         목록을 고르는 것은 그 조건절이고, 「＋ 프로젝트」는 쓰기 자격(판정 함수 값)일 때만이다. 값(제목 · id · 색 이름)은 전부 htmlspecialchars,
         색은 허용 목록 넷만 클래스가 된다. 「포트폴리오」는 work.js 가 팀 공간마다 `GET /api/spaces/{num}/portfolios`(decidePortfolioRead)의 답으로 채우고,
         채울 것이 없으면 제목째 숨은 채다. -->
<?php
$shellProjects = function_exists('community_work_projects') && $shellUser !== []
    ? community_work_projects($shellUser, 10) : ['items' => [], 'more' => false];
$shellProjectsShown = $shellProjects['items'] !== [] || $shellCommunityWrite;
?>
    <details id="nav-work-projects-group" data-sidebar-group>
    <summary id="nav-work-projects-title" class="nav-title"<?= $shellProjectsShown ? '' : ' hidden' ?>>프로젝트</summary>
    <nav id="nav-work-projects" class="nav" aria-label="프로젝트"<?= $shellProjectsShown ? '' : ' hidden' ?>>
<?php foreach ($shellProjects['items'] as $shellProject): ?>
      <a class="nav__item" href="<?= Http::communityUrl('/p/') ?><?= htmlspecialchars((string) $shellProject['id'], ENT_QUOTES, 'UTF-8') ?>" data-project-id="<?= htmlspecialchars((string) $shellProject['id'], ENT_QUOTES, 'UTF-8') ?>" data-can-delete="<?= ($shellProject['can_delete'] ?? false) ? 'true' : 'false' ?>" data-project-purpose="<?= $shellProject['purpose'] === 'my_tasks' ? 'my_tasks' : 'project' ?>"><span class="task-color<?= $shellProject['color'] === null ? '' : ' moa-' . htmlspecialchars((string) $shellProject['color'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></span><span><?= htmlspecialchars((string) $shellProject['title'], ENT_QUOTES, 'UTF-8') ?></span></a>
<?php endforeach; ?>
<?php if ($shellProjects['more']): ?>
      <a id="nav-work-all-projects" class="nav__item" href="<?= Http::communityUrl('/') ?>#work-projects"><span class="icon" data-icon="log"></span><span>모든 프로젝트</span></a>
<?php endif; ?>
<?php if ($shellCommunityWrite): ?>
      <button id="nav-work-new-project" class="nav__item" type="button" data-work-new-project aria-haspopup="dialog"><span class="icon" data-icon="plus"></span><span>프로젝트</span></button>
<?php endif; ?>
    </nav>
    </details>
    <details id="nav-work-portfolios-group" data-sidebar-group open>
    <summary id="nav-work-portfolios-title" class="nav-title" hidden>포트폴리오</summary>
    <nav id="nav-work-portfolios" class="nav" aria-label="포트폴리오" hidden></nav>
    </details>
    <!-- 팀 (4-B → Q:F-12 2.19 ⑥) — 항목은 `editor.js` 가 창구 `GET /community/api/spaces` 의 답으로
         채운다. **이 파일에는 팀 판단이 없다**: 내 팀이 무엇인지도, 팀 공간이 있는지도
         서버가 정하고 화면은 받은 것만 그린다 (PRD S-01, DESIGN 원칙 1).
         팀 공간이 없으면 이 자리는 끝까지 비어 있고 제목 줄도 보이지 않는다. -->
    <details id="nav-community-teams-group" data-sidebar-group open>
    <summary id="nav-community-team-title" class="nav-title" hidden>팀</summary>
    <nav id="nav-community-teams" class="nav" aria-label="팀 공간" hidden></nav>
    </details>
    <!-- 문서 (Q:F-12 2.19 ⑦) — 내 문서(`/community/workspace` 그대로) · 내 페이지 트리(5-A′ — editor.js 가 `?tree=1` 의 답으로 채운다) · 절차서,
         이어서 「게시판」 목록(볼 수 있는 게시판만 — 게시판 모음 화면이 따로 없어 목록째 이 묶음 끝에 둔다). -->
    <details id="nav-community-documents-box" data-sidebar-group>
    <summary class="nav-title">문서</summary>
    <nav class="nav" aria-label="문서">
      <a id="nav-community-home" class="<?= $shellNavClass('community-home') ?>" href="<?= Http::communityUrl('/workspace') ?>">
        <span class="icon" data-icon="files"></span><span>내 문서</span>
      </a>
      <div id="nav-community-tree" class="nav-tree-box"></div>
      <a class="nav__item" href="<?= Http::communityUrl('/procedures') ?>"><span class="icon" data-icon="log"></span><span>절차서</span></a>
    </nav>
    </details>
    <!-- 게시판 (5-C) — 항목은 editor.js 가 창구 `GET /community/api/boards` 의 답(볼 수 있는 게시판만)으로
         채운다. 종류마다 아이콘(공지·토론·질문답변)이 다르고, 보관 중인 게시판에는 「보관」이 붙는다.
         볼 수 있는 게시판이 하나도 없으면 제목째 숨는다. 이 파일에는 게시판 판단이 없다. -->
    <details id="nav-community-boards-group" data-sidebar-group open>
    <summary id="nav-community-board-title" class="nav-title" hidden>게시판</summary>
    <nav id="nav-community-boards" class="nav" aria-label="게시판" hidden></nav>
    </details>
    <!-- 공유받은 페이지 (5-A · 5-A′) — 있을 때만 제목째 보인다 -->
    <details id="nav-community-shared-group" data-sidebar-group open>
    <summary id="nav-community-shared-title" class="nav-title" hidden>공유받은 페이지</summary>
    <nav id="nav-community-shared" class="nav" aria-label="공유받은 페이지" hidden></nav>
    </details>
    <!-- 「더보기」 (Q:F-12 DESIGN 2.19 ⑧ — 시안 v1 승인) — 처음엔 접혀 있는 <details>(즐겨찾기 묶음과 같은 틀 · 여닫는 스크립트 0).
         자동 작업 · 내 제안 · 공유·연동 설정 · ~~문서 AI~~ · **내 휴지통**. 2.9 「휴지통 자리 — 두 앱이 같게」를 업무 앱에서는 이렇게 고쳤다(자료실은 그대로).
         「문서 AI」 줄(사이트 Q:F-15 S2 · D-6 · DESIGN 2.19 ⑧)은 hidden — 줄 · 주소 `/community/ai` · 관리자 설정 · 도움말은 그대로(숨김, 지우기 아님).
         「내 휴지통」(디버깅 묶음 U3): 누르면 「내 문서」 화면의 휴지통 창이 열린다(꼬리표 `trash=1` — 고정 글자, 그 화면에서는 이동 없이 창만).
         목록은 전과 같이 창구 `GET /community/api/me/trash` 가 주는 것뿐이다. 쓰기 자격이 없는 사람(손님)은 지울 수 없어 늘 비어 있으므로
         「내 제안」과 같은 값으로 숨긴다(편의 — 창구가 다시 판단한다). -->
    <details id="nav-community-more-box" data-sidebar-group>
      <summary class="nav-title">더보기</summary>
      <nav class="nav" aria-label="더보기">
        <a class="nav__item" href="<?= Http::communityUrl('/automations') ?>"><span class="icon" data-icon="log"></span><span>자동 작업</span></a>
        <a class="nav__item" href="<?= Http::communityUrl('/suggestions') ?>"<?= $shellCommunityWrite ? '' : ' hidden' ?>><span class="icon" data-icon="files"></span><span>내 제안</span></a>
        <a class="nav__item" href="<?= Http::communityUrl('/integrations') ?>"><span class="icon" data-icon="link"></span><span>공유·연동 설정</span></a>
        <a class="nav__item" href="<?= Http::communityUrl('/ai') ?>" hidden><span class="icon" data-icon="assistant"></span><span>문서 AI</span></a>
        <a id="nav-community-trash" class="nav__item" href="<?= Http::communityUrl('/workspace') ?>?trash=1"<?= $shellCommunityWrite ? '' : ' hidden' ?>>
          <span class="icon" data-icon="trash"></span><span>내 휴지통</span>
        </a>
      </nav>
    </details>
<?php   endif; ?>
    <!-- 즐겨찾기 아이콘 둘(빈 별 = 넣기 · 찬 별 = 빼기). DESIGN 2.11 규격(24×24 · stroke 1.8, lucide star) — t-box 파일 화면(home.php)과 같은 글자.
         Q:F-3a 라운드 B — 사이드바 트리 줄의 ☆ · 「내 공간」 선택 막대가 세 화면 모두에서 쓰므로 페이지 화면(page_view.php)에서 여기로 옮겼다
         (같은 id 가 두 번 나오지 않게 한 곳에만). 고정 문자열이고 사용자 입력이 한 글자도 없다 — editor.js 는 cloneNode 로만 붙인다. -->
    <template id="tpl-icon-star"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 2.5 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.3l-5.8 3.1 1.1-6.5-4.7-4.6 6.5-.9z"/></svg></template>
    <template id="tpl-icon-star-fill"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 2.5 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.3l-5.8 3.1 1.1-6.5-4.7-4.6 6.5-.9z"/></svg></template>
<?php elseif ($shellMod === 'story'): ?>
    <!-- 이야기 앱에는 사이드바 아래쪽 묶음이 없다(DESIGN 2.19 「이야기」 — 업무 묶음은 그리지 않는다) -->
<?php else: ?>
    <p class="nav-title">파일</p>
    <nav class="nav" aria-label="파일">
      <a id="nav-files" class="<?= $shellNavClass('files') ?>" href="<?= Http::url('/') ?>" data-tip="파일">
        <span class="icon" data-icon="files"></span><span>파일</span>
      </a>
      <!-- Q:F-4 U-2b (PRD 5.4 각주 ¹) — 「내 휴지통」: 내가 올리고 내가 지운 파일을 30일 안에 되살린다.
           기본은 hidden 이고 app.js 가 `/api/me` 의 값으로 연다 — 관리 묶음의 「휴지통」(전체)이 열리는
           사람(can_view_trash)에게는 열지 않고, 올릴 수 있는 등급이 없는 사람(upload_levels 비어 있음)에게도
           열지 않는다. **이 파일에는 역할 비교가 없다.** 되살려 열어도 보이는 행은 데이터 창구의
           SQL WHERE(Authz::restorableFilesWhere)가 정한다 (PRD S-01·S-02).
           `data-no-reauth` — 본인 휴지통은 재인증 대상이 아니므로 D-9 재인증 모달(armReauthLinks)을 걸지 않는다. -->
      <a id="nav-programs" class="<?= $shellNavClass('programs') ?>" href="<?= Http::url('/programs') ?>"><span class="icon" data-icon="files"></span><span>PC 비서 받기</span></a>
      <a id="nav-my-trash" class="<?= $shellNavClass('trash') ?>" href="<?= Http::url('/trash') ?>" data-no-reauth hidden>
        <span class="icon" data-icon="trash"></span><span>내 휴지통</span>
      </a>
      <!-- G-0 (t-box PRD F-73) — 「공유받음」: 나에게 준 권한으로 여는 개인 폴더·파일(`GET /api/grants/mine`) + 받은·보낸 권한 요청.
           권한은 폴더 하나에만 받을 수 있어 트리를 내려가서는 닿지 못한다 — 받은 자리를 한곳에 모은다(커뮤니티 「공유받은 페이지」와 같은 까닭).
           파일 화면의 보기 단추 줄에 두면 폰 폭(412)에서 줄이 두 줄로 꺾여 사이드바 항목으로 둔다. 목록은 창구가 정한다(PRD S-02). -->
      <a id="nav-shared" class="nav__item" href="<?= Http::url('/') ?>?view=shared">
        <span class="icon" data-icon="users"></span><span>공유받음</span>
      </a>
      <!-- G-0 (t-box PRD F-73 ④) — 「권한 요청 n」: 내 개인 폴더·파일에 온 **기다리는** 요청이 있을 때만 app.js 가 연다
           (`/api/me` 의 access_requests_pending — 알림 종과 같은 값 · 새 요청 0). 누르면 파일 화면의 받은 요청 목록.
           숫자 배지는 알림 종과 같은 `.badge` — 새 클래스 0. 목록·허락·거절은 창구가 요청마다 다시 판단한다(PRD S-01). -->
      <a id="nav-access-requests" class="nav__item" href="<?= Http::url('/') ?>?requests=in" hidden>
        <span class="icon" data-icon="users"></span><span>권한 요청</span>
        <span id="nav-access-requests-count" class="badge badge--internal"></span>
      </a>
    </nav>

    <p class="nav-title">설정</p>
    <nav class="nav" aria-label="설정">
      <a id="nav-settings" class="<?= $shellNavClass('settings') ?>" href="<?= Http::url('/settings') ?>">
        <span class="icon" data-icon="settings"></span><span>내 계정</span>
      </a>
      <!-- 「내 활동」은 15-1 에서 더한 항목이며 **모든 로그인 사용자에게 보인다** (PRD F-12).
           can_* 플래그로 여닫지 않는다 — 자기 이력은 역할과 무관하게 누구나 볼 수 있고,
           보이는 내용은 서버가 세션 본인으로 고정하기 때문이다 (ARCH 5.11). -->
      <a id="nav-activity" class="<?= $shellNavClass('activity') ?>" href="<?= Http::url('/activity') ?>">
        <span class="icon" data-icon="log"></span><span>내 활동</span>
      </a>
    </nav>

    <!-- 관리자 영역 (17-1 PRD F-24 · 26단계 F-40, ARCH 5.14).
         기본은 전부 hidden 이고, `/api/me` 의 can_admin 이 참일 때만 app.js 가 **함께** 연다.
         **이 파일에는 역할 비교가 없다** — 항목을 개발자도구로 되살려도 각 경로의 라우트 정책
         (user.manage·org.manage·audit.read·role:Admin)과 재인증 관문이 다시 막는다 (PRD S-01, T-38).

         ★ 26단계 (사용자 지적 2026-09-15 「계정·조직 화면에서 뒤로가기로만 돌아온다」):
           17-1 에서 이 묶음을 「관리자 페이지」 한 항목으로 줄였더니, 허브에서 카드로 들어간 뒤에는
           사이드바에 돌아올 길이 없었다. 하위 6화면을 다시 항목으로 둔다 — **경로·정책은 그대로**이고
           달라진 것은 내비에 보이느냐뿐이다. -->
    <div id="nav-admin-group" hidden>
      <p class="nav-title">관리</p>
      <nav class="nav" aria-label="관리">
        <a id="nav-admin" class="<?= $shellNavClass('admin') ?>" href="<?= Http::url('/admin') ?>" hidden>
          <span class="icon" data-icon="lock"></span><span>관리자 페이지</span>
        </a>
        <a id="nav-users" class="<?= $shellNavClass('users') ?>" href="<?= Http::url('/users') ?>" hidden>
          <span class="icon" data-icon="users"></span><span>계정 관리</span>
        </a>
        <a id="nav-org" class="<?= $shellNavClass('org') ?>" href="<?= Http::url('/org') ?>" hidden>
          <span class="icon" data-icon="org"></span><span>조직 · 권한</span>
        </a>
        <a id="nav-admin-permissions" class="nav__item" href="<?= Http::url('/admin/permissions') ?>" hidden>
          <span class="icon" data-icon="lock"></span><span>권한 매트릭스</span>
        </a>
        <!-- F-43 — URL 가져오기 허용 사이트 (PRD F-43, ARCH 5.22). 위 항목들과 **같은 값
             하나**(can_admin)로 함께 열린다. 항목을 개발자도구로 되살려도 /admin/fetch-hosts 는
             라우트 정책 role:Admin 과 재인증 관문이 다시 막는다 (PRD S-01, T-56). -->
        <a id="nav-admin-fetch-hosts" class="nav__item" href="<?= Http::url('/admin/fetch-hosts') ?>" hidden>
          <span class="icon" data-icon="link"></span><span>가져오기 허용 사이트</span>
        </a>
        <a id="nav-audit" class="<?= $shellNavClass('audit') ?>" href="<?= Http::url('/audit') ?>" hidden>
          <span class="icon" data-icon="log"></span><span>감사 로그</span>
        </a>
        <a id="nav-trash" class="<?= $shellNavClass('trash') ?>" href="<?= Http::url('/trash') ?>" hidden>
          <span class="icon" data-icon="trash"></span><span>휴지통</span>
        </a>
        <a id="nav-wiki" class="<?= $shellNavClass('wiki') ?>" href="<?= Http::url('/wiki') ?>" hidden>
          <span class="icon" data-icon="shield"></span><span>위키</span>
        </a>
      </nav>
    </div>
<?php endif; ?>

    <div class="sidebar__spacer"></div>
    <button type="button" class="btn btn--quiet q-design-open" data-design-open aria-haspopup="dialog" aria-controls="q-design-dialog"><span class="q-design-dot" aria-hidden="true"></span><span data-design-name>Astra</span><span>테마</span></button>

    <!-- F-47 「변경 이력」 여는 단추 (PRD F-47, ARCH 5.23).
         누르면 shell_bottom.php 가 그린 #changelog-dialog 가 열린다 — 요청도 권한 판단도 없고,
         내용은 이미 이 응답 안에 들어 있다. 「사용 방법」 단추(5.19)와 같은 뼈대다. -->
    <!-- D-5 (DESIGN.md 2.9 · 2.12) — 버전 표기와 해/달 단추를 한 줄(.sidebar__foot)에 둔다.
         해와 달을 **둘 다** 그려 두고 어느 것을 보일지는 CSS 가 정한다
         (:root[data-theme] · prefers-color-scheme) — app.js 는 아이콘을 갈아 끼우지 않는다. -->
    <div class="sidebar__foot">
      <button type="button" class="sidebar__version" data-changelog-open title="변경 이력"
              aria-haspopup="dialog" aria-controls="changelog-dialog">v<?= $shellVersion ?></button>
      <button type="button" class="btn btn--icon btn--plain theme-toggle" data-theme-toggle
              aria-label="어두운 화면으로">
        <span class="icon icon--moon" data-icon="moon"></span><span class="icon icon--sun" data-icon="sun"></span>
      </button>
    </div>
    <!-- 사이트 Q:F-13 P1 — 약관 링크 줄(셸 사이드바 바닥 · 버전 줄 아래 · 처리방침은 굵게). 고정 주소뿐이다. -->
    <p class="legal-links"><a href="<?= Http::url('/privacy') ?>"><strong>개인정보 처리방침</strong></a> · <a href="<?= Http::url('/terms') ?>">이용약관</a></p>

    <div class="userchip">
      <span class="userchip__avatar" aria-hidden="true"><?= $shellInitial ?></span>
      <span class="userchip__text">
        <span class="userchip__name"><?= $shellName ?></span>
        <span class="userchip__role" title="<?= $shellRoleTitle ?>"><?= $shellRole ?></span>
      </span>
    </div>
  </aside>

  <main id="main" class="main">
<?php require __DIR__ . '/shell_tools.php'; /* 사이트 H1 (DESIGN 2.31) — 오른쪽 위 셋 · 통합 검색 창은 셸과 모아 홈이 같은 한 파일 */ ?>
<?php if ($shellActing): ?>
    <!-- 26단계 「역할로 보기」 띠 (PRD F-39, ARCH 5.20).
         본문(.page-head)보다 **위**에 있어 어느 화면에서든 첫 줄에 보인다. 보는 중에는 사이드바의
         관리 묶음이 닫히므로 이 「원래대로」 단추가 유일한 복귀 경로다 — 그래서 서버가 그린다.
         값은 전부 위에서 htmlspecialchars 를 거쳤고, 이 줄에는 역할 비교도 인라인 style·script 도 없다. -->
    <div class="act-banner notice" role="status">
      <span>지금 <b><?= $shellActWhat ?></b> 으로 보는 중 — 실제 계정 <?= $shellActActual ?></span>
      <button type="button" data-act-stop class="btn btn--quiet btn--small">원래대로</button>
    </div>
<?php endif; ?>
