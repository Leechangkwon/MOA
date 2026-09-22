<?php
declare(strict_types=1);

/**
 * src/community/handlers/home_page.php — GET /community/ (정책: community.read)
 *
 * 요구사항: `C:F-17` 최소(개인 공간 첫 화면), `DESIGN.md` 2.3 · 2.9 · 2.14 · 6장,
 *           `../../../../community/ARCHITECTURE.md` 21.3.
 *
 * 이 파일은 **뼈대만 그린다.** 목록·새 페이지 만들기는 `assets/community/editor.js` 가
 * 창구(`GET /community/api/me/space` · `GET /community/api/pages`)를 불러 채운다.
 *
 * ★ 이 화면에는 권한 로직도, 사용자 값이 들어가는 자리도 **없다** (PRD S-01 · S-03).
 *   무엇이 보이는지는 서버 창구가 정하고, 글자는 전부 `textContent` 로 들어간다.
 * ★ 새 클래스를 만들지 않는다 (DESIGN 원칙 6) — `.page-head`·`.toolbar`·`.card-grid`·
 *   `.card--link`·`.badge`·`.muted` 는 2장에 이미 있는 구성 요소다.
 * ★ 인라인 `<script>`·`style=`·`on*` 0 (CSP `script-src 'self'`).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle   = '내 공간';
$activeNav   = 'community-home';
$pageKey     = 'community-home';
$pageHelp    = 'community-home';
$pageCss     = ['community/community.css'];
$shellModule = 'community';
require __DIR__ . '/../../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">내 공간</h1>
        <p class="page-head__sub">나만 보는 개인 업무 공간입니다. 페이지를 만들어 적어 두세요.</p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
        <button id="community-new" class="btn" type="button">
          <span class="icon" data-icon="plus"></span>새 페이지
        </button>
      </div>
    </header>

    <p id="msg" class="msg" role="alert" aria-live="polite"></p>

    <div class="toolbar">
      <input id="community-search" type="search" placeholder="제목·내용으로 찾기" maxlength="100"
             autocomplete="off" spellcheck="false">
      <span id="community-count" class="muted"></span>
    </div>

    <!-- 목록이 들어오는 자리. editor.js 가 `createElement` 로만 채운다. -->
    <section id="community-list" class="card-grid" aria-live="polite"></section>
    <p id="community-empty" class="muted" hidden>아직 페이지가 없습니다. 「새 페이지」로 시작하세요.</p>
<?php
$pageMsgTop = true;
require __DIR__ . '/../../views/shell_bottom.php';
