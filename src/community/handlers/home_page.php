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
        <!-- 제목·설명은 고른 공간에 따라 editor.js 가 textContent 로 바꾼다 (4-B). -->
        <h1 id="community-space-title" class="page-head__title">내 공간</h1>
        <p id="community-space-sub" class="page-head__sub">나만 보는 개인 업무 공간입니다. 페이지를 만들어 적어 두세요.</p>
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

    <!-- 공간 고르개 (4-B) — 왼쪽 첫 자리다. 목록은 `<select>` 한 칸이고 항목은 창구
         `GET /community/api/spaces` 가 준 것만 들어간다(화면이 공간 목록을 알고 있지 않다).
         공간이 하나뿐이면(팀이 없는 계정) 통째로 숨는다 — 고를 것이 없는 고르개는 군더더기다.
         `.toolbar select` 는 DESIGN 2.4 도구줄에 이미 있는 구성 요소다(새 클래스 0). -->
    <div class="toolbar">
      <label id="community-space-label" class="muted" for="community-space" hidden>공간</label>
      <select id="community-space" hidden aria-label="공간 고르기"></select>
      <input id="community-search" type="search" placeholder="제목·내용으로 찾기" maxlength="100"
             autocomplete="off" spellcheck="false">
      <span id="community-count" class="muted"></span>
    </div>

    <!-- 트리가 들어오는 자리 (4-A). editor.js 가 `createElement` 로만 채우고, 접기·펼치기는
         `<details>`/`<summary>` 가 **브라우저 기능으로** 한다 — 여닫는 스크립트가 없다.
         찾기를 켜면 아래 평평한 목록으로 바뀐다(트리 안에서 찾는 것은 뜻이 없다). -->
    <section id="community-tree" class="panel page-tree" aria-live="polite"></section>

    <!-- 찾기 결과가 들어오는 자리. 3단계의 카드 목록 그대로다. -->
    <section id="community-list" class="card-grid" aria-live="polite" hidden></section>
    <p id="community-empty" class="muted" hidden>아직 페이지가 없습니다. 「새 페이지」로 시작하세요.</p>
<?php
$pageMsgTop = true;
require __DIR__ . '/../../views/shell_bottom.php';
