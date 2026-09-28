<?php
declare(strict_types=1);

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
 */

/** 내비·본문 키의 허용 목록. 여기 없는 값은 빈 문자열이 된다. */
$shellKeys = [
    'files', 'trash', 'settings', 'activity', 'users', 'org', 'audit', 'wiki', 'admin',
    // 커뮤니티 3단계 (커뮤니티 ARCHITECTURE.md 21.3) — 모듈 모드의 화면 둘.
    // app.js 는 이 두 키를 만나면 셸만 그리고 물러난다(t-box 화면 JS 가 돌지 않는다).
    'community-home', 'community-page',
    // 커뮤니티 5-C — 게시판 화면
    'community-board',
];

/**
 * 모듈 모드 (커뮤니티 ARCHITECTURE.md 21.3).
 *
 * 화면을 그리는 핸들러가 include 전에 `$shellModule = 'community';` 한 줄을 넘기면 브랜드·
 * 내비·그리드의 현재 앱이 그 모듈 것이 된다. **넘기지 않으면 지금까지와 한 글자도 같은
 * t-box 셸**이다 — 기본값이 t-box 라는 뜻이고, t-box 화면 스무 곳을 고칠 일이 없다.
 * 값은 허용 목록 안의 것만 통과한다(핸들러의 오타가 속성값으로 새지 않는다).
 */
$shellModuleKeys = ['community'];
$shellMod = isset($shellModule) && is_string($shellModule) && in_array($shellModule, $shellModuleKeys, true)
    ? $shellModule
    : '';

/** 셸이 보이는 이름 — 브랜드·`<title>` 꼬리표·그리드의 현재 앱이 이 값 하나를 따른다 */
$shellBrandName = $shellMod === 'community' ? '커뮤니티' : 't-box';
$shellBrandSub  = $shellMod === 'community' ? '개인 업무 공간' : '사내 파일 공유';
$shellBrandIcon = $shellMod === 'community' ? 'grid' : 'shield';
/** 그리드 팝업에서 `aria-current="page"` 가 붙는 앱의 key (`config/apps.json` 의 key) */
$shellCurrentApp = $shellMod === 'community' ? 'community' : 'tbox';

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

/*
 * 앱 그리드 단추의 목록 (Qaredi C — ARCH-launcher 6장). 런처와 **같은 한 곳**에서 읽는다.
 * 부트스트랩의 require 목록에 새 줄을 더하지 않고 쓰는 곳에서 읽어 온다(Changelog.php 와 같은 방식).
 * 값은 저장소가 추적하는 `config/apps.json` 이고 요청 값이 한 글자도 섞이지 않는다.
 */
require_once __DIR__ . '/../Apps.php';
$shellApps = Apps::all();

/**
 * 25단계 — 화면이 더 싣는 스타일시트. **허용 목록 안의 이름만** 통과한다.
 * 핸들러의 오타나 어떤 요청 값도 `<link href>` 로 새어 나가지 못한다 (PRD S-03).
 * 지금은 더 싣는 스타일시트가 없다 — 목록이 비어 있으므로 `$pageCss` 는 어떤 값이든 버려진다.
 * 구조를 남겨 두는 이유는, 화면별 CSS 가 다시 생길 때 이 허용 목록을 거치게 하기 위해서다.
 */
$shellCssAllowed = [
    // 커뮤니티 3단계 — 문서 토큰·블록 클래스 한 벌 (DESIGN.md 2.14). 이름은 `public/assets/`
    // 기준의 **정확한 상대 경로**이고, 허용 목록 안의 값만 통과하므로 경로가 조립되지 않는다.
    'community/community.css',
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
<html lang="ko"<?= $themeAttr ?><?= /* 7-D3 고대비(DESIGN 2.16) — 계정 칸이 참일 때만 고정 글자, 아니면 속성 없음 · 새 함수 0 */ ($shellUser['high_contrast'] ?? false) === true ? ' data-contrast="high"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= $shellTitle ?> · <?= htmlspecialchars($shellBrandName, ENT_QUOTES, 'UTF-8') ?></title>
<!-- 25단계 — 브라우저가 스스로 부르는 `/favicon.ico` 가 404 로 떨어져 모든 화면의 콘솔에
     오류 한 줄을 남겼다(시각 점검 도구가 세는 console_errors). 아이콘을 **문서 안에** 둔다:
     파일을 새로 만들지 않고, CSP 의 `img-src 'self' data:` 안에서 끝난다. 고정 문자열이라
     동적 값이 한 글자도 들어가지 않는다. -->
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%2314213d' d='M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5z'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= Http::url('/assets/app.css') ?>?v=<?= $shellCssVersion ?>">
<?php foreach ($shellPageCss as $shellCssName): ?>
<link rel="stylesheet" href="<?= Http::url('/assets/') ?><?= $shellCssName ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/' . $shellCssName) ?>">
<?php endforeach; ?>
<?php if ($shellPalette !== ''): ?>
<link id="palette-css" rel="stylesheet" href="<?= COMMUNITY_BASE ?>/palette.css?v=<?= $shellPalette ?>">
<?php endif; ?>
<?php if ($shellMod === 'community'): ?>
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
<meta name="apple-mobile-web-app-title" content="Qaredi">
<?php endif; ?>
<?php if ($shellPwa === 'alerts'): ?>
<!-- F-70 「Qaredi 경보」 설치형 웹 앱 (PRD F-70, ARCH 5.32) — 휴대폰 경보 화면(`/alerts-app`)만. 값은 전부 고정 글자다.
     매니페스트는 로그인·재인증 뒤에만 열리는 라우트라 쿠키를 싣는다(use-credentials — 같은 사이트 요청).
     아이폰은 매니페스트와 함께 이 메타·PNG 아이콘으로 홈 화면 앱을 만든다(iOS 16.4+ 웹 푸시는 홈 화면 앱 안에서만). -->
<link rel="manifest" href="<?= Http::url('/alerts-app.webmanifest') ?>" crossorigin="use-credentials">
<link rel="apple-touch-icon" href="<?= Http::url('/assets/alerts-icon-192.png') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/alerts-icon-192.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Qaredi 경보">
<?php endif; ?>
</head>
<body data-page="<?= $shellPage ?>" data-base="<?= TBOX_BASE ?>"<?= $shellMod === '' ? '' : ' data-community-base="' . COMMUNITY_BASE . '"' ?>>
<a class="skip" href="#main">본문으로 건너뛰기</a>
<div class="app">
  <!-- 좁은 화면(960px 미만)에서만 보이는 상단 바. 메뉴 단추가 좌측 내비를 접었다 편다.
       넓은 화면에서는 CSS 가 이 줄을 감추고 내비를 늘 펼쳐 둔다. -->
  <header class="appbar">
    <button id="nav-toggle" class="btn btn--icon" type="button"
            aria-expanded="false" aria-controls="sidebar" aria-label="메뉴 열기">
      <span class="icon" data-icon="menu"></span>
    </button>
    <span class="appbar__brand"><?= htmlspecialchars($shellBrandName, ENT_QUOTES, 'UTF-8') ?></span>
    <span class="appbar__page"><?= $shellTitle ?></span>
  </header>

  <aside id="sidebar" class="sidebar" hidden>
    <div class="brand">
      <!-- ─── 앱 메뉴 (사용자 지시 2026-09-23) ───────────────────────────────────
           「로그아웃은 Qaredi 홈·t-box·커뮤니티로 전환하는 곳에 — 관리자 페이지·설정도 거기에 —
           박스가 조그매서 눈에 안 띄니 더 직관적이고 큰 것으로」. 전에는 브랜드 줄 오른쪽의 작은
           그리드 단추(⋮⋮⋮)가 앱 목록만 열었고, 로그아웃은 사이드바 맨 아래 아이콘이라 툴팁이
           칸을 밀었다. 이제 **브랜드 덩어리 전체가 단추**이고 한 메뉴에 앱 · 계정 · 관리 · 로그아웃.
           스크립트 0 으로 열린다(`<details>`), 인라인 style·on* 0 (CSP). 클래스 `appbar__apps` 는
           시험(Q:T-8)·문서가 쓰는 이름이라 유지한다. 앱 목록은 런처와 **같은** `Apps::all()` 이다.
           관리자 페이지 줄의 열고 닫음은 사이드바 관리 링크와 같은 표시용 값(`can_admin`)을
           app.js 가 쓰고, 막는 것은 여전히 라우트 정책·재인증 관문이다 (PRD S-01). -->
      <details class="appbar__apps">
        <summary class="brand__switch" aria-label="앱 전환과 계정 메뉴">
          <span class="brand__mark"><span class="icon" data-icon="<?= htmlspecialchars($shellBrandIcon, ENT_QUOTES, 'UTF-8') ?>"></span></span>
          <span class="brand__text">
            <span class="brand__name"><?= htmlspecialchars($shellBrandName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="brand__sub"><?= htmlspecialchars($shellBrandSub, ENT_QUOTES, 'UTF-8') ?></span>
          </span>
          <span class="brand__chev icon" data-icon="chevron"></span>
        </summary>
        <div class="panel app-menu">
          <p class="app-menu__label">앱</p>
          <ul class="app-menu__apps">
            <li><a class="app-menu__app" href="/"><span class="app-menu__icon icon" data-icon="home"></span><span class="app-menu__text"><span class="app-menu__name">Qaredi 홈</span><span class="app-menu__desc">앱 고르는 첫 화면</span></span></a></li>
<?php foreach ($shellApps as $shellApp): ?>
<?php   $shellAppName = htmlspecialchars((string) $shellApp['name'], ENT_QUOTES, 'UTF-8'); ?>
<?php   $shellAppDesc = htmlspecialchars((string) ($shellApp['desc'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
<?php   $shellAppIcon = htmlspecialchars((string) ($shellApp['icon'] ?? 'grid'), ENT_QUOTES, 'UTF-8'); ?>
<?php   if (($shellApp['status'] ?? '') === 'soon'): ?>
            <li><span class="app-menu__app is-soon"><span class="app-menu__icon icon" data-icon="<?= $shellAppIcon ?>"></span><span class="app-menu__text"><span class="app-menu__name"><?= $shellAppName ?> <span class="badge">준비 중</span></span><span class="app-menu__desc"><?= $shellAppDesc ?></span></span></span></li>
<?php   elseif ($shellApp['key'] === $shellCurrentApp): ?>
            <li><a class="app-menu__app" href="<?= htmlspecialchars((string) $shellApp['path'], ENT_QUOTES, 'UTF-8') ?>" aria-current="page"><span class="app-menu__icon icon" data-icon="<?= $shellAppIcon ?>"></span><span class="app-menu__text"><span class="app-menu__name"><?= $shellAppName ?></span><span class="app-menu__desc"><?= $shellAppDesc ?></span></span><span class="app-menu__check icon" data-icon="check"></span></a></li>
<?php   else: ?>
            <li><a class="app-menu__app" href="<?= htmlspecialchars((string) $shellApp['path'], ENT_QUOTES, 'UTF-8') ?>"><span class="app-menu__icon icon" data-icon="<?= $shellAppIcon ?>"></span><span class="app-menu__text"><span class="app-menu__name"><?= $shellAppName ?></span><span class="app-menu__desc"><?= $shellAppDesc ?></span></span></a></li>
<?php   endif; ?>
<?php endforeach; ?>
          </ul>
          <p class="app-menu__label">계정</p>
          <!-- 사이드바 아래 `.userchip` 과 클래스를 나눠 쓴다 — 같은 클래스가 두 벌이면 시험·스크립트가
               닫힌 메뉴 쪽을 먼저 읽는다(stage26 「원래대로 뒤 칩 역할」 실측 2026-09-23). -->
          <div class="app-menu__me">
            <span class="app-menu__avatar" aria-hidden="true"><?= $shellInitial ?></span>
            <span class="app-menu__text">
              <span class="app-menu__name"><?= $shellName ?></span>
              <span class="app-menu__desc" title="<?= $shellRoleTitle ?>"><?= $shellRole ?></span>
            </span>
          </div>
          <nav class="nav" aria-label="계정">
            <a id="menu-settings" class="nav__item" href="<?= Http::url('/settings') ?>"><span class="icon" data-icon="settings"></span><span>내 계정</span></a>
            <a id="menu-activity" class="nav__item" href="<?= Http::url('/activity') ?>"><span class="icon" data-icon="log"></span><span>내 활동</span></a>
            <a id="menu-admin" class="nav__item" href="<?= Http::url('/admin') ?>" hidden><span class="icon" data-icon="lock"></span><span>관리자 페이지</span></a>
            <button id="logout" class="nav__item app-menu__logout" type="button"><span class="icon" data-icon="logout"></span><span>로그아웃</span></button>
          </nav>
        </div>
      </details>
    </div>

    <!-- 검색 (5-B → Q:F-2 두 앱 공통) — 누르거나 Ctrl+K(⌘K). 창과 동작은 search.js, 무엇이 나오나는 각 앱의 서버
         (커뮤니티 search.php — 페이지·댓글 / t-box files_list.php — 파일 전역 검색). 창에 커뮤니티 기준 주소를 싣는다
         (t-box 화면의 <body> 에는 커뮤니티 기준이 없다). 값은 고정 상수다. -->
    <button id="nav-search" class="nav__item nav-search" type="button" aria-haspopup="dialog">
      <span class="icon" data-icon="search"></span><span>검색</span><kbd class="nav-search__key">Ctrl K</kbd>
    </button>
    <dialog id="search-dialog" class="modal modal--form search-dialog" aria-label="통합 검색" data-community-base="<?= COMMUNITY_BASE ?>">
      <div class="search-dialog__bar">
        <span class="icon" data-icon="search"></span>
        <input id="search-input" type="search" maxlength="100" autocomplete="off" spellcheck="false"
               placeholder="찾기 — 페이지·댓글·파일" aria-label="찾을 말" aria-controls="search-results">
        <button class="btn btn--small btn--quiet" type="button" data-search-close>닫기</button>
      </div>
      <ul id="search-results" class="search-results" role="listbox" aria-label="검색 결과"></ul>
      <p id="search-note" class="search-dialog__note muted" role="status"></p>
    </dialog>
<?php if ($shellMod === 'community'): ?>
    <!-- ─── 커뮤니티 모듈의 내비 (커뮤니티 ARCHITECTURE.md 21.3) ───────────────────
         항목 둘이다. 「내 공간」은 링크, 「새 페이지」는 만들자마자 그 페이지로 가야 해서
         단추다(누르면 editor.js 가 `POST /community/api/pages` 를 부르고 이동한다).
         단추에도 같은 `.nav__item` 을 쓴다 — 화면 전용 클래스를 만들지 않는다(DESIGN 원칙 6).
         t-box 의 파일·설정·관리 묶음은 이 모드에서 **그리지 않는다**: 다른 앱의 내비이고,
         앱 사이 이동은 위 그리드 단추가 맡는다. -->
    <!-- 알림 (5-D, PRD F-22) — 종 아이콘 + 안 읽은 수. 30초마다 `GET /community/api/notifications?unread=1`.
         누르면 알림 창 — 목록은 창구가 **지금도 볼 수 있는 페이지만** 제목을 준다(아니면 「볼 수 없는 페이지」). -->
    <nav class="nav" aria-label="알림">
      <button id="nav-community-bell" class="nav__item" type="button" aria-haspopup="dialog">
        <span class="icon" data-icon="bell"></span><span>알림</span>
        <span id="nav-community-bell-count" class="badge badge--internal" hidden></span>
      </button>
    </nav>
    <dialog id="notify-dialog" class="modal modal--form modal--stack" aria-labelledby="notify-title">
      <div class="modal__head">
        <h2 id="notify-title" class="modal__title">알림</h2>
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
    <!-- 즐겨찾기 · 최근 (개발 대기열 6 · Q:F-3a 라운드 A — 결정 M-1 순서: 즐겨찾기(접을 수 있음) → 최근 → 내 공간 → 팀 공간 →
         공유받은 페이지). 항목은 editor.js 가 창구 `GET /community/api/me/favorites` · `GET /community/api/me/recent` 의 답 —
         **지금 볼 수 있는 것만**(검색과 같은 조건절) — 으로 채운다. 이 파일에는 판단이 없다. 없으면 묶음째 숨는다.
         새 클래스 0: 제목은 `.nav-title`, 줄은 사이드바 트리와 같은 `.nav-tree` 줄(5-A′). 접기·펼치기는 <details> 가 한다
         (여닫는 스크립트 0 · 키보드로도 열린다 — DESIGN 2.14 페이지 트리와 같은 방식). 즐겨찾기 순서는 줄을 끌어 놓거나
         Alt+↑/↓ 로 바꾼다(editor.js — `PATCH /community/api/me/favorites`). -->
    <details id="nav-community-fav-box" open hidden>
      <summary class="nav-title">즐겨찾기</summary>
      <nav id="nav-community-fav" class="nav" aria-label="즐겨찾기"></nav>
    </details>
    <p id="nav-community-recent-title" class="nav-title" hidden>최근</p>
    <nav id="nav-community-recent" class="nav" aria-label="최근 본 페이지" hidden></nav>
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
    <p class="nav-title">내 공간</p>
    <nav class="nav" aria-label="커뮤니티">
      <a id="nav-community-home" class="<?= $shellNavClass('community-home') ?>" href="<?= Http::communityUrl('/') ?>">
        <span class="icon" data-icon="files"></span><span>내 공간</span>
      </a>
      <!-- 내 페이지 트리 (5-A′) — editor.js 가 `?tree=1` 의 답으로 채운다 -->
      <div id="nav-community-tree" class="nav-tree-box"></div>
      <button id="nav-community-new" class="nav__item" type="button"<?= $shellCommunityWrite ? '' : ' hidden' ?>>
        <span class="icon" data-icon="plus"></span><span>새 페이지</span>
      </button>
      <!-- 디버깅 묶음 U3 — 「내 휴지통」: t-box 사이드바와 **같은 이름 · 같은 자리**(첫 묶음의 끝, DESIGN 2.9). 전에는 커뮤니티 첫 화면
           「더보기 → 휴지통」에만 있었다. 누르면 커뮤니티 첫 화면의 휴지통 창이 열린다(꼬리표 `trash=1` — 고정 글자, 첫 화면에서는 이동 없이 창만).
           목록은 전과 같이 창구 `GET /community/api/me/trash` 가 주는 것뿐이다. 쓰기 자격이 없는 사람(손님)은 지울 수 없어 늘 비어 있으므로
           「새 페이지」와 같은 값으로 숨긴다(편의 — 창구가 다시 판단한다). -->
      <a id="nav-community-trash" class="nav__item" href="<?= Http::communityUrl('/') ?>?trash=1"<?= $shellCommunityWrite ? '' : ' hidden' ?>>
        <span class="icon" data-icon="trash"></span><span>내 휴지통</span>
      </a>
      <!-- 팀 공간 (4-B) — 항목은 `editor.js` 가 창구 `GET /community/api/spaces` 의 답으로
           채운다. **이 파일에는 팀 판단이 없다**: 내 팀이 무엇인지도, 팀 공간이 있는지도
           서버가 정하고 화면은 받은 것만 그린다 (PRD S-01, DESIGN 원칙 1).
           팀 공간이 없으면 이 자리는 끝까지 비어 있고 제목 줄도 보이지 않는다. -->
    </nav>
    <p id="nav-community-team-title" class="nav-title" hidden>팀 공간</p>
    <nav id="nav-community-teams" class="nav" aria-label="팀 공간" hidden></nav>
    <!-- 게시판 (5-C) — 항목은 editor.js 가 창구 `GET /community/api/boards` 의 답(볼 수 있는 게시판만)으로
         채운다. 종류마다 아이콘(공지·토론·질문답변)이 다르고, 보관 중인 게시판에는 「보관」이 붙는다.
         볼 수 있는 게시판이 하나도 없으면 제목째 숨는다. 이 파일에는 게시판 판단이 없다. -->
    <p id="nav-community-board-title" class="nav-title" hidden>게시판</p>
    <nav id="nav-community-boards" class="nav" aria-label="게시판" hidden></nav>
    <!-- 공유받은 페이지 (5-A · 5-A′) — 있을 때만 제목째 보인다 -->
    <p id="nav-community-shared-title" class="nav-title" hidden>공유받은 페이지</p>
    <nav id="nav-community-shared" class="nav" aria-label="공유받은 페이지" hidden></nav>
    <!-- 즐겨찾기 아이콘 둘(빈 별 = 넣기 · 찬 별 = 빼기). DESIGN 2.11 규격(24×24 · stroke 1.8, lucide star) — t-box 파일 화면(home.php)과 같은 글자.
         Q:F-3a 라운드 B — 사이드바 트리 줄의 ☆ · 「내 공간」 선택 막대가 세 화면 모두에서 쓰므로 페이지 화면(page_view.php)에서 여기로 옮겼다
         (같은 id 가 두 번 나오지 않게 한 곳에만). 고정 문자열이고 사용자 입력이 한 글자도 없다 — editor.js 는 cloneNode 로만 붙인다. -->
    <template id="tpl-icon-star"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 2.5 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.3l-5.8 3.1 1.1-6.5-4.7-4.6 6.5-.9z"/></svg></template>
    <template id="tpl-icon-star-fill"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 2.5 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.3l-5.8 3.1 1.1-6.5-4.7-4.6 6.5-.9z"/></svg></template>
<?php else: ?>
    <p class="nav-title">파일</p>
    <nav class="nav" aria-label="파일">
      <a id="nav-files" class="<?= $shellNavClass('files') ?>" href="<?= Http::url('/') ?>">
        <span class="icon" data-icon="files"></span><span>파일</span>
      </a>
      <!-- Q:F-4 U-2b (PRD 5.4 각주 ¹) — 「내 휴지통」: 내가 올리고 내가 지운 파일을 30일 안에 되살린다.
           기본은 hidden 이고 app.js 가 `/api/me` 의 값으로 연다 — 관리 묶음의 「휴지통」(전체)이 열리는
           사람(can_view_trash)에게는 열지 않고, 올릴 수 있는 등급이 없는 사람(upload_levels 비어 있음)에게도
           열지 않는다. **이 파일에는 역할 비교가 없다.** 되살려 열어도 보이는 행은 데이터 창구의
           SQL WHERE(Authz::restorableFilesWhere)가 정한다 (PRD S-01·S-02).
           `data-no-reauth` — 본인 휴지통은 재인증 대상이 아니므로 D-9 재인증 모달(armReauthLinks)을 걸지 않는다. -->
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

    <div class="userchip">
      <span class="userchip__avatar" aria-hidden="true"><?= $shellInitial ?></span>
      <span class="userchip__text">
        <span class="userchip__name"><?= $shellName ?></span>
        <span class="userchip__role" title="<?= $shellRoleTitle ?>"><?= $shellRole ?></span>
      </span>
    </div>
  </aside>

  <main id="main" class="main">
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
