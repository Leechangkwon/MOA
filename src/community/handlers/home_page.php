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
        <!-- 더보기 (DESIGN 2.9 · 2.15, 2026-09-25 — 사용자 폴드 접은 화면 스크린샷: 머리 단추 여섯이 세 줄로 밀림).
             머리에는 「사용 방법」 · 더보기 · 「새 페이지」 셋만 둔다. 항목의 보이고 숨는 것은 전과 같이 각 스크립트가
             창구의 can_manage 로 `hidden` 을 바꾼다 — id 는 그대로라 동작 코드는 바뀌지 않는다. -->
        <details id="community-more" class="menu">
          <summary class="btn btn--quiet"><span class="icon" data-icon="chevron"></span>더보기</summary>
          <div class="menu__list">
            <!-- 「템플릿에서」 (7-B, C:F-31) — 목록은 창구 `GET /api/templates` 가 주는 것만(내 것 · 내 팀 · 사이트 기본). -->
            <button id="community-template-new" class="menu__item" type="button" aria-haspopup="dialog">
              <span class="icon" data-icon="files"></span>템플릿에서 만들기
            </button>
            <!-- 「휴지통」 (4-C). 목록은 창구 `GET /api/me/trash` 가 주는 것만이고, 화면에는
                 「내가 지운 것인가」를 따지는 코드가 한 줄도 없다 (PRD S-01). -->
            <button id="community-trash" class="menu__item" type="button" aria-haspopup="dialog">
              <span class="icon" data-icon="trash"></span>휴지통
            </button>
            <!-- 「많이 읽힌 문서」 (7-D1, N-34) — 창구 `GET /api/boards` 의 `can_manage` 가 참일 때만 보인다(Admin) -->
            <button id="community-popular" class="menu__item" type="button" aria-haspopup="dialog" hidden>
              <span class="icon" data-icon="log"></span>많이 읽힌 문서
            </button>
            <!-- 「임베드 허용 목록」 (7-C, C:F-32) — 창구 `GET /api/embed-domains` 의 `can_manage` 가 참일 때만 보인다(Admin) -->
            <button id="community-embed-admin" class="menu__item" type="button" aria-haspopup="dialog" hidden>
              <span class="icon" data-icon="shield"></span>임베드 허용 목록
            </button>
          </div>
        </details>
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
      <!-- 상태 거르개 (4-D). 거르는 일은 **서버가 SQL 로** 한다 — 이 칸은 창구에 붙일
           꼬리표(`?status=`)를 고를 뿐이고, 화면이 목록에서 줄을 빼지 않는다 (C:S-02). -->
      <label id="community-status-label" class="muted" for="community-status">상태</label>
      <select id="community-status" aria-label="상태로 거르기">
        <option value="">전체</option>
        <option value="none">없음</option>
        <option value="todo">할 일</option>
        <option value="doing">진행</option>
        <option value="review">검토</option>
        <option value="done">완료</option>
        <option value="hold">보류</option>
      </select>
      <span id="community-count" class="muted"></span>
    </div>

    <!-- 「공유받은 페이지」 (5-A — 4-D 「내가 담당인 페이지」 자리). 다른 사람이 나에게 보기·편집으로
         준 페이지다. 언제나 남의 공간에 있으므로 줄마다 어느 공간인지와 받은 권한을 함께 보인다.
         목록은 창구 `GET /api/pages?shared=me` 가 **SQL WHERE 로** 준 것만이고, 「나에게 공유됐나」를
         따지는 코드는 화면에 없다. -->
    <section id="community-shared-wrap" hidden>
      <div class="toolbar">
        <p class="nav-title">공유받은 페이지</p>
        <span id="community-shared-count" class="muted"></span>
      </div>
      <div id="community-shared" class="card-grid"></div>
    </section>

    <!-- 트리가 들어오는 자리 (4-A). editor.js 가 `createElement` 로만 채우고, 접기·펼치기는
         `<details>`/`<summary>` 가 **브라우저 기능으로** 한다 — 여닫는 스크립트가 없다.
         찾기를 켜면 아래 평평한 목록으로 바뀐다(트리 안에서 찾는 것은 뜻이 없다). -->
    <section id="community-tree" class="panel page-tree" aria-live="polite"></section>

    <!-- 찾기 결과가 들어오는 자리. 3단계의 카드 목록 그대로다. -->
    <section id="community-list" class="card-grid" aria-live="polite" hidden></section>
    <p id="community-empty" class="muted" hidden>아직 페이지가 없습니다. 「새 페이지」로 시작하세요.</p>

    <!-- 「휴지통」 창 (4-C, DESIGN 2.7 모달 · 2.4 표). 지운 페이지를 목록에서 떠나지 않고
         되살린다. 한 줄은 **한 번의 삭제**다 — 함께 내려간 하위는 「하위 n」으로만 세고,
         되살리면 함께 돌아온다(창구가 그렇게 돌려준다).
         표 내용은 editor.js 가 createElement/textContent 로 채운다. 본문 글자는 없다. -->
    <dialog id="trash-dialog" class="modal" aria-labelledby="trash-title">
      <div class="modal__head">
        <h2 id="trash-title" class="modal__title">휴지통</h2>
        <p id="trash-meta" class="muted"></p>
        <button class="btn btn--icon btn--plain" type="button" data-trash-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="trash-msg" class="msg" role="alert" aria-live="polite"></p>
        <div class="table-wrap">
          <table class="files">
            <thead>
              <tr><th>제목</th><th>공간</th><th>함께 지운 하위</th><th>지운 때</th><th></th></tr>
            </thead>
            <tbody id="trash-body"></tbody>
          </table>
        </div>
        <p id="trash-empty" class="muted" hidden>휴지통이 비어 있습니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-trash-close>닫기</button>
      </div>
    </dialog>

    <!-- 「많이 읽힌 문서」 창 (7-D1, 결정 J-6 — 휴지통 창과 같은 모양, 새 클래스 0). 목록은 창구가 준 것뿐(사내 전체에 열린 페이지 · 수만).
         내용은 popular.js 가 textContent 로 채운다. -->
    <dialog id="popular-dialog" class="modal" aria-labelledby="popular-title">
      <div class="modal__head">
        <h2 id="popular-title" class="modal__title">많이 읽힌 문서</h2>
        <p id="popular-meta" class="muted"></p>
        <button class="btn btn--icon btn--plain" type="button" data-popular-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="popular-msg" class="msg" role="alert" aria-live="polite"></p>
        <div class="table-wrap">
          <table class="files">
            <thead>
              <tr><th>순위</th><th>제목</th><th>공간</th><th>조회(사람 × 날)</th><th>읽은 사람</th></tr>
            </thead>
            <tbody id="popular-body"></tbody>
          </table>
        </div>
        <p id="popular-empty" class="muted" hidden>지난 30일에 사내 공개 페이지를 읽은 기록이 없습니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-popular-close>닫기</button>
      </div>
    </dialog>

    <!-- 「임베드 허용 목록」 창 (7-C, DESIGN 2.7 모달 · 2.4 표 — 휴지통 창과 같은 모양, 새 클래스 0).
         호스트 이름만 적는다(https·경로·포트·IP·와일드카드 없이) — 모양·한도(20)·재인증은 서버가 막는다.
         내용은 embeds.js 가 textContent 로 채운다. -->
    <dialog id="embed-dialog" class="modal" aria-labelledby="embed-title">
      <div class="modal__head">
        <h2 id="embed-title" class="modal__title">임베드 허용 목록</h2>
        <p id="embed-meta" class="muted"></p>
        <button class="btn btn--icon btn--plain" type="button" data-embed-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="embed-msg" class="msg" role="alert" aria-live="polite"></p>
        <div class="toolbar">
          <label class="muted" for="embed-host">호스트</label>
          <input id="embed-host" class="input" type="text" maxlength="253" autocomplete="off" spellcheck="false" placeholder="www.youtube.com">
          <button id="embed-add" class="btn btn--small" type="button">더하기</button>
        </div>
        <!-- 넣으면 안 되는 사이트 안내(임베드 켜기 독립 검토 정보 7) — 틀에는 스크립트가 돈다. 고정 문장 · 동적 값 0 -->
        <p class="muted">사용자가 HTML·스크립트를 올릴 수 있는 사이트(codepen.io · jsfiddle.net · docs.google.com · sites.google.com 등)는 넣지 마세요 — 페이지를 고칠 수 있는 누구나 그 사이트에 만든, 스크립트가 도는 틀을 넣을 수 있습니다. 영상처럼 내용을 그 사이트가 관리하는 곳만 넣습니다.</p>
        <div class="table-wrap">
          <table class="files">
            <thead>
              <tr><th>호스트</th><th>더한 사람</th><th>더한 때</th><th></th></tr>
            </thead>
            <tbody id="embed-body"></tbody>
          </table>
        </div>
        <p id="embed-empty" class="muted" hidden>허용한 사이트가 없습니다. 임베드를 넣을 수 없습니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-embed-close>닫기</button>
      </div>
    </dialog>

    <!-- 「템플릿에서 새 페이지」 창 (7-B, DESIGN 2.7 모달 · 2.4 표 — 이력 창과 같은 목록 + 미리보기 모양, 새 클래스 0).
         만든 페이지는 **지금 보고 있는 공간**에 생기고, 개인 공간이면 비공개로 시작한다(창구 규칙).
         「지우기」는 창구가 준 `can_delete` 가 참인 줄에만 있다. 내용은 templates.js 가 textContent 로 채운다. -->
    <dialog id="template-dialog" class="modal" aria-labelledby="template-title">
      <div class="modal__head">
        <h2 id="template-title" class="modal__title">템플릿에서 새 페이지</h2>
        <button class="btn btn--icon btn--plain" type="button" data-template-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="template-msg" class="msg" role="alert" aria-live="polite"></p>
        <section id="template-list-wrap">
          <p class="muted">템플릿의 제목·본문으로 새 페이지를 만듭니다. 첨부 파일은 따라오지 않습니다.</p>
          <div class="table-wrap">
            <table class="files">
              <thead>
                <tr><th>이름</th><th>범위</th><th>만든 사람</th><th></th></tr>
              </thead>
              <tbody id="template-body"></tbody>
            </table>
          </div>
          <p id="template-empty" class="muted" hidden>쓸 수 있는 템플릿이 없습니다.</p>
        </section>
        <!-- 「보기」로 연 템플릿의 본문 — 읽기 렌더러(`renderDoc`)가 그린다 -->
        <section id="template-preview" hidden>
          <div class="toolbar">
            <p id="template-preview-title" class="nav-title"></p>
            <button id="template-preview-back" class="btn btn--quiet" type="button">목록으로</button>
            <button id="template-preview-use" class="btn" type="button">이 템플릿으로 만들기</button>
          </div>
          <article id="template-preview-doc" class="panel"></article>
        </section>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-template-close>닫기</button>
      </div>
    </dialog>
<?php
$pageMsgTop = true;
require __DIR__ . '/../../views/shell_bottom.php';
