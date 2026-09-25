<?php
declare(strict_types=1);

/**
 * src/community/handlers/page_view.php — GET /community/p/{id} (정책: community.read)
 *
 * 요구사항: `C:F-15`·`C:F-16`, `DESIGN.md` 2.9 · 2.14 · 6장,
 *           `../../../../community/ARCHITECTURE.md` 12.4 · 13.3 · 14장 · 21.3.
 *
 * 읽기 모드와 편집 모드가 **같은 화면**이다 (ARCH 14.3). 이 파일은 뼈대만 그리고,
 * 본문은 `assets/community/editor.js` 가 `GET /community/api/pages/{id}` 로 받아
 *   · 읽기 → `doc-render.js` 의 `renderDoc()` (createElement/textContent 만)
 *   · 편집 → ProseMirror (우리 스키마의 노드·마크만 등록한 것)
 * 로 그린다.
 *
 * ★ **이 화면은 판단하지 않는다** (PRD S-01). 페이지를 볼 수 있는지·고칠 수 있는지는
 *   창구가 매 요청 `CommunityAuthz` 로 판단하고, 화면은 그 답(403·404·`can_edit`)을 따를 뿐이다.
 *   그래서 여기서 페이지를 읽지도, 권한을 보지도 않는다 — 감사 행이 화면과 창구에서 두 번
 *   남는 일도 없다.
 * ★ HTML 로 나가는 동적 값은 **페이지 id 하나**이고, 그 값은 라우터의 자리표시자 `{id}` 가
 *   UUID 36자만 통과시킨 뒤 여기서 다시 `htmlspecialchars` 를 거친다 (PRD S-03).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$viewPageId = (string) $params[0];

// 7-C 결정 K-5 — 틀을 여는 스위치(코드 상수, 기본 끔)가 켜졌을 때만 이 화면의 CSP 에 허용 목록 호스트를 `frame-src` 로 더한다.
community_embed_csp();

$pageTitle   = '페이지';
$activeNav   = 'community-page';
$pageKey     = 'community-page';
$pageHelp    = 'community-page';
$pageCss     = ['community/community.css'];
$shellModule = 'community';
require __DIR__ . '/../../views/shell_top.php';
?>
    <!-- 경로(breadcrumb) — 뿌리부터 이 페이지까지 (4-A, DESIGN 2.14 `.crumb`).
         줄의 내용은 editor.js 가 createElement/textContent 로 채운다. -->
    <nav id="page-crumb" class="crumb" aria-label="페이지 경로" hidden></nav>

    <header class="page-head">
      <div class="page-head__text">
        <!-- 제목은 창구가 준 값을 editor.js 가 textContent 로 넣는다(편집 모드에서는 입력 칸). -->
        <h1 id="page-title" class="page-head__title">페이지</h1>
        <p id="page-sub" class="page-head__sub"></p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
        <span id="page-editors" class="badge" hidden></span>
        <span id="page-save-state" class="badge" hidden></span>
        <!-- 「이력」 (4-C) — 이 페이지를 **볼 수 있는 사람이면 누구나** 연다(읽기 판정과 같은 자격),
             되돌리기 단추만 고칠 수 있는 사람에게 보인다. 늘 보이는 outline 하나(DESIGN 2.9 — 머리 단추 넷까지). -->
        <button id="page-history" class="btn btn--quiet" type="button" aria-haspopup="dialog">
          <span class="icon" data-icon="log"></span>이력
        </button>
        <!-- 더보기 (DESIGN 2.15, 2026-09-25 — 사용자 지적 「왤케 UI/UX 가 지저분하냐」) — 가끔 쓰는 동작을 담는다.
             항목의 보이고 숨는 것은 전과 같이 editor.js 가 창구의 can_* 로 `hidden` 을 바꾼다 — 모두 숨으면 CSS 가 메뉴째 숨긴다.
             id 는 그대로라 동작 코드는 바뀌지 않는다. 삭제는 맨 아래 구분선 위 빨간 글자. -->
        <details id="page-more" class="menu">
          <summary class="btn btn--quiet"><span class="icon" data-icon="chevron"></span>더보기</summary>
          <div class="menu__list">
            <button id="page-move" class="menu__item" type="button" hidden>
              <span class="icon" data-icon="files"></span>옮기기
            </button>
            <!-- 7-B — 「템플릿으로 저장」. 창구가 준 `template_scopes` 가 비어 있으면 숨은 채다. -->
            <button id="page-save-template" class="menu__item" type="button" aria-haspopup="dialog" hidden>
              <span class="icon" data-icon="download"></span>템플릿으로 저장
            </button>
            <!-- 5-C 게시판 — 「게시」·「게시 해제」·「숨기기」·「숨김 풀기」·「해결됨」은 창구가 준
                 `can_publish`·`can_unpublish`·`can_hide`·`can_resolve` 가 참일 때만 보인다(PRD S-01). -->
            <button id="page-publish" class="menu__item" type="button" aria-haspopup="dialog" hidden>
              <span class="icon" data-icon="upload"></span>게시
            </button>
            <button id="page-unpublish" class="menu__item" type="button" hidden>게시 해제</button>
            <button id="page-hide" class="menu__item" type="button" hidden>숨기기</button>
            <button id="page-resolve" class="menu__item" type="button" hidden>해결됨으로 표시</button>
            <hr class="menu__sep">
            <button id="page-delete" class="menu__item menu__item--danger" type="button" hidden>
              <span class="icon" data-icon="trash"></span>삭제
            </button>
          </div>
        </details>
        <button id="page-edit" class="btn" type="button" hidden>
          <span class="icon" data-icon="edit"></span>편집
        </button>
        <button id="page-done" class="btn" type="button" hidden>
          <span class="icon" data-icon="check"></span>편집 끝
        </button>
      </div>
    </header>

    <!-- 속성 줄 (4-D, C:F-37) — 제목 바로 아래다.
         배지 자체는 **누를 수 없다**(DESIGN 2.2 「눌러도 아무 일도 없다」) — 누르는 것은
         배지를 담은 작은 단추이고, 고칠 수 없는 사람에게는 그 단추가 `disabled` 다.
         값도 잠금도 전부 창구가 준 것(`status`·`owner`·`can_set_properties`·`can_handover`)이고,
         화면에는 소유·역할을 따지는 코드가 없다 (PRD S-01, DESIGN 원칙 1). -->
    <div id="page-props" class="page-props" hidden>
      <span class="page-props__group">
        <span class="muted">상태</span>
        <button id="page-status" class="btn btn--small btn--quiet" type="button" aria-haspopup="dialog">
          <span id="page-status-badge" class="badge">없음</span>
        </button>
      </span>
      <!-- 「소유자」 (5-A — 4-D 의 「담당자」 자리). 이 페이지가 든 **공간의 주인**이고, 팀 공간이면
           「팀 공간」이다. 「넘기기」는 창구가 준 `can_handover` 가 참일 때만 보인다 — 화면이
           소유·역할을 따지지 않는다. 되살려 눌러도 `POST …/handover` 가 같은 함수로 다시 막는다. -->
      <span class="page-props__group">
        <!-- 5-C — 게시판 글에서는 「쓴 사람」으로 바뀐다(글자는 editor.js 가 창구의 `writer` 로 고른다) -->
        <span id="page-owner-label" class="muted">소유자</span>
        <span id="page-owner-name">…</span>
        <button id="page-handover" class="btn btn--small btn--quiet" type="button" aria-haspopup="dialog" hidden>
          <span class="icon" data-icon="users"></span>넘기기
        </button>
      </span>
      <!-- 공개 범위·이력은 머리글에서 이 줄로 옮겼다 (사용자 지시 2026-09-23 — 문서의 속성끼리 한 줄에).
           공개 범위 배지 (4-B, DESIGN 2.2) — 한글 라벨을 보이고 원래 값은 `title` 에 둔다.
           색은 t-box 등급 배지의 뜻 그대로다: 비공개=`--admin-only`(빨강, 가장 좁다) ·
           팀=`--team`(보라) · 사내=`--internal`(파랑). 새 색값을 만들지 않았다.
           「바꾸기」는 창구가 준 `can_set_visibility` 가 참일 때만 보인다 — 화면이 소유·역할을
           따지지 않는다. 되살려 눌러도 서버가 다시 막는다 (PRD S-01). -->
      <span class="page-props__sep" aria-hidden="true"></span>
      <span class="page-props__group">
        <span class="muted">공개 범위</span>
        <span id="page-visibility" class="badge" hidden></span>
        <button id="page-visibility-edit" class="btn btn--small btn--quiet" type="button" aria-haspopup="dialog" hidden>
          <span class="icon" data-icon="shield"></span>바꾸기
        </button>
        <!-- 5-A — 공유받은 사람에게는 받은 권한이 보이고, 같은 창(공유 부분만)을 열어 자기 공유를 뺄 수 있다.
             5-A″ — 팀 공간의 페이지에서는 관리할 수 있는 팀원에게 같은 단추가 「사람 추가」로 보인다
             (팀 공간에는 공개 범위가 없다 — 창에는 공유 부분만 열린다). 글자는 editor.js 가 고른다. -->
        <span id="page-share-mine" class="badge" hidden></span>
        <button id="page-share-open" class="btn btn--small btn--quiet" type="button" aria-haspopup="dialog" hidden>
          <span class="icon" data-icon="users"></span><span id="page-share-open-label">공유 보기</span>
        </button>
      </span>
      <!-- 게시판 (5-C) — 이 페이지가 걸린 게시판(게시판 글이면 그 게시판, 게시했으면 게시한 게시판).
           「게시」·「게시 해제」·「숨기기」·「숨김 풀기」·「해결됨」은 창구가 준 `can_publish`·`can_unpublish`·
           `can_hide`·`can_resolve` 가 참일 때만 보인다 — 화면이 소유·역할을 따지지 않는다(PRD S-01). -->
      <span class="page-props__group" id="page-board-group" hidden>
        <span class="muted">게시판</span>
        <a id="page-board-link" href="#">게시판</a>
        <span id="page-board-hidden" class="badge badge--off" hidden>숨김</span>
        <span id="page-resolved-badge" class="badge" hidden>미해결</span>
        <!-- 5-D 공지 「읽은 사람 n/N」 — 창구가 준 `can_view_reads` 가 참일 때만(쓴 사람·Admin). 수만 보인다(결정 D-8). -->
        <span id="page-reads" class="badge" hidden></span>
      </span>
      <!-- 태그 (5-C) — 칩 + 입력 칸. 추천은 `<datalist>`(브라우저 기능 — 떠 있는 목록을 새로 만들지 않는다,
           DESIGN 2.8 ★). 칩의 ✕ 와 입력 칸은 창구가 준 `can_tag` 가 참일 때만 보인다. -->
      <span class="page-props__group" id="page-tags-group">
        <span class="muted">태그</span>
        <span id="page-tags"></span>
        <input id="page-tag-input" type="text" maxlength="30" autocomplete="off" spellcheck="false"
               list="page-tag-options" placeholder="태그 추가" aria-label="태그 추가 — Enter 로 붙입니다" hidden>
        <datalist id="page-tag-options"></datalist>
      </span>
      <span id="page-views" class="muted" hidden></span>
    </div>

    <p id="msg" class="msg" role="alert" aria-live="polite"></p>

    <!-- 제목 고치기 (편집 모드에서만 보인다) -->
    <label class="field" id="page-title-field" hidden>
      <span class="field__label">제목</span>
      <input id="page-title-input" type="text" maxlength="200" autocomplete="off" spellcheck="false">
    </label>

    <!-- 6-A 행 페이지의 속성 줄 — 이 페이지가 데이터베이스의 행이면 속성 이름과 값(고칠 수 있으면 눌러서 그 자리 편집).
         그리기는 database.js 가 createElement/textContent 로 한다. -->
    <section id="row-props-wrap" class="panel" aria-label="속성" hidden>
      <p id="row-props-msg" class="msg" role="alert" aria-live="polite"></p>
      <dl id="row-props" class="db-props"></dl>
    </section>

    <!-- 읽기 모드의 본문이 들어오는 자리 (renderDoc 의 결과) -->
    <article id="page-doc" class="panel" aria-live="polite"></article>

    <!-- 편집하는 동안 본문 위에 붙어 있는 줄 — 「블록 추가」 + 「색」 「배경」 「링크」 (지시서 1장).
         굵게·기울임·밑줄·취소선·인라인 코드는 만들지 않는다(사용자 결정 2026-09-23).
         「블록 추가」가 머리글이 아니라 여기 있는 것은 긴 글에서 위로 오르내리지 않게 하려는 것이다
         (사용자 지적 2026-09-23). 줄은 sticky 라 스크롤해도 화면 위에 남는다. 단추는 editor.js 가
         줄의 맨 앞으로 옮긴다. -->
    <div id="inline-toolbar" class="panel editor-toolbar" hidden role="toolbar" aria-label="블록 추가와 글자 꾸미기">
      <button id="page-add-block" class="btn btn--small btn--quiet" type="button" hidden>
        <span class="icon" data-icon="plus"></span>블록 추가
      </button>
    </div>

    <!-- 편집 모드의 본문이 들어오는 자리 (ProseMirror 가 자기 DOM 을 그린다) -->
    <div id="page-editor" class="panel" hidden></div>

    <!-- 빈 페이지 안내 (2026-09-23 사용자 요청 「제목·목차를 모르면 못 쓴다 — 기본 블록 구성을 보여 주자」).
         문서가 비어 있을 때만 편집기 **아래**에 보인다 — 위에 두면 첫 글자를 칠 때 사라지며 본문이 밀린다.
         단축키 줄은 editor.js 가 `BLOCK_TYPES` 에서 채운다(창의 표시와 한 곳에서 나온다). -->
    <section id="editor-starter" class="panel editor-starter" hidden aria-labelledby="editor-starter-title">
      <p id="editor-starter-title" class="panel__title">빈 페이지입니다 — 줄마다 블록 하나로 씁니다</p>
      <p class="muted">자주 쓰는 블록은 줄 맨 앞에서 글자를 치면 바뀝니다. 나머지는 위 「블록 추가」에서 고릅니다.</p>
      <ul id="editor-starter-keys" class="editor-starter__keys"></ul>
      <div class="editor-starter__actions">
        <button id="editor-starter-apply" class="btn" type="button">
          <span class="icon" data-icon="plus"></span>기본 구성 넣기
        </button>
        <span class="muted">제목 · 목차 · 개요 · 할 일 · 메모 뼈대를 넣습니다. 글자는 고쳐 쓰면 됩니다.</span>
      </div>
    </section>

    <!-- 하위 페이지 (4-A). 「하위 페이지 만들기」는 언제나 보이고(고칠 수 있을 때),
         목록은 있을 때만 보인다. 카드는 editor.js 가 `.card--link` 로 만든다. -->
    <!-- 6-A 데이터베이스 표 — DB 페이지에서만 보인다. 열 = 제목·상태·속성들·소유자, 칸을 누르면 그 자리에서 고친다.
         거르기는 조건 하나(창구는 열까지 받는다) · 정렬 하나. 속성 더하기·고치기는 아래 창(관리 자격만). -->
    <section id="db-wrap" class="panel" aria-labelledby="db-title" hidden>
      <div class="toolbar">
        <p id="db-title" class="nav-title">데이터베이스</p>
        <span id="db-count" class="muted"></span>
        <button id="db-add-prop" class="btn btn--quiet btn--small" type="button" hidden>
          <span class="icon" data-icon="plus"></span>속성 더하기
        </button>
      </div>
      <!-- 6-B 뷰 줄 — 보는 모양 셋(표·보드·리스트) + 저장한 뷰 + 지금 거르기·정렬을 뷰로 저장(고칠 수 있는 사람) -->
      <div class="toolbar db-viewbar" role="group" aria-label="보는 모양">
        <button class="btn btn--quiet btn--small db-type is-active" type="button" data-db-type="table" aria-pressed="true">표</button>
        <button class="btn btn--quiet btn--small db-type" type="button" data-db-type="board" aria-pressed="false">보드</button>
        <button class="btn btn--quiet btn--small db-type" type="button" data-db-type="list" aria-pressed="false">리스트</button>
        <span class="editor-toolbar__sep" aria-hidden="true"></span>
        <span id="db-saved" class="db-saved"></span>
        <form id="db-view-form" class="db-view-form" hidden>
          <input id="db-view-name" class="input" type="text" maxlength="50" autocomplete="off" placeholder="뷰 이름" aria-label="뷰 이름">
          <button class="btn btn--quiet btn--small" type="submit">뷰 저장</button>
        </form>
      </div>
      <div class="toolbar db-filter">
        <select id="db-f-prop" class="input" aria-label="거를 속성"></select>
        <select id="db-f-op" class="input" aria-label="조건"></select>
        <span class="db-filter__value"><input id="db-f-val" class="input" type="text" aria-label="거를 값"></span>
        <button id="db-f-apply" class="btn btn--quiet btn--small" type="button">거르기</button>
        <button id="db-f-clear" class="btn btn--quiet btn--small" type="button" hidden>거르기 풀기</button>
        <select id="db-sort" class="input" aria-label="정렬"></select>
        <select id="db-sort-dir" class="input" aria-label="정렬 방향">
          <option value="asc">오름차순</option>
          <option value="desc">내림차순</option>
        </select>
      </div>
      <p id="db-msg" class="msg" role="alert" aria-live="polite"></p>
      <div id="db-table-wrap" class="doc-table-wrap">
        <table id="db-table" class="doc-table db-table"></table>
      </div>
      <!-- 6-B 보드 — 상태 열(없음 + 다섯). 카드를 다른 열로 끌거나, 카드를 골라 ← → 로 옮기면 상태가 바뀐다 -->
      <div id="db-board" class="db-board" hidden></div>
      <!-- 6-B 리스트 — 한 줄 = 제목 + 속성 칩 -->
      <ul id="db-list" class="list db-list" hidden></ul>
      <form id="db-add-row" class="toolbar" hidden>
        <input id="db-add-row-title" class="input" type="text" maxlength="200" autocomplete="off" placeholder="새 행 제목" aria-label="새 행 제목">
        <!-- 7-B — 이 DB 의 행 템플릿(없으면 숨은 채). 고르면 제목은 비워도 된다 -->
        <select id="db-add-row-template" aria-label="행 템플릿" hidden></select>
        <button id="db-add-row-template-delete" class="btn btn--small btn--quiet btn--danger" type="button" hidden>템플릿 지우기</button>
        <button class="btn btn--small" type="submit">새 행</button>
      </form>
    </section>

    <section id="page-children-wrap" hidden>
      <div class="toolbar">
        <p class="nav-title">하위 페이지</p>
        <span id="page-children-count" class="muted"></span>
        <button id="page-new-child" class="btn btn--quiet" type="button" hidden>
          <span class="icon" data-icon="plus"></span>하위 페이지 만들기
        </button>
        <!-- 6-A — 표 모양의 하위 데이터베이스. 만든 뒤 그 DB 페이지로 간다(행은 DB 표 안에서 만든다) -->
        <button id="page-new-db" class="btn btn--quiet" type="button" hidden>
          <span class="icon" data-icon="plus"></span>하위 데이터베이스 만들기
        </button>
      </div>
      <div id="page-children" class="card-grid"></div>
      <p id="page-children-empty" class="muted" hidden>아직 하위 페이지가 없습니다.</p>
    </section>

    <!-- 댓글 (5-D, PRD F-21). 목록·입력은 editor.js 가 창구(`GET/POST …/comments`)의 답으로 createElement/textContent 로
         그린다 — 본문은 평문이고 `white-space: pre-wrap` 으로만 보인다(결정 D-1). 답글은 1단, 블록 앵커는 읽기 화면에서
         글자를 고른 뒤 「고른 문구 인용」. `@` 를 치면 아래에 후보가 **칸 안에서** 펼쳐진다(떠 있지 않다 — DESIGN 2.8 ★). -->
    <!-- 「이 페이지를 참조한 곳」 (5-E, PRD F-23). 창구 `backlinks` 는 **보는 사람이 볼 수 있는 페이지만** 준다 —
         가려진 것이 몇 개인지도 말하지 않는다(결정 E-7). 비면 절 전체를 숨긴다. -->
    <section id="page-backlinks-wrap" class="panel" aria-labelledby="page-backlinks-title" hidden>
      <p id="page-backlinks-title" class="nav-title">이 페이지를 참조한 곳</p>
      <ul id="page-backlinks" class="list" aria-label="이 페이지를 참조한 곳"></ul>
    </section>

    <section id="page-comments-wrap" class="panel" aria-labelledby="page-comments-title" hidden>
      <div class="toolbar">
        <p id="page-comments-title" class="nav-title">댓글</p>
        <span id="page-comments-count" class="muted"></span>
      </div>
      <p id="comments-msg" class="msg" role="alert" aria-live="polite"></p>
      <ul id="page-comments" class="list" aria-label="댓글"></ul>
      <p id="page-comments-empty" class="muted" hidden>아직 댓글이 없습니다.</p>
      <form id="comment-form" class="form" hidden>
        <p id="comment-reply-to" class="help" hidden></p>
        <p id="comment-quote" class="help" hidden></p>
        <label class="field field--full">
          <span class="field__label">댓글</span>
          <textarea id="comment-input" rows="3" maxlength="2000" spellcheck="false"
                    placeholder="@아이디 로 사람을 부를 수 있습니다"></textarea>
        </label>
        <ul id="comment-mentions" class="list" aria-label="부를 사람" hidden></ul>
        <div class="form-actions">
          <button id="comment-quote-pick" class="btn btn--quiet btn--small" type="button">고른 문구 인용</button>
          <button id="comment-cancel" class="btn btn--quiet btn--small" type="button" hidden>답글·인용 그만두기</button>
          <button id="comment-submit" class="btn" type="submit">댓글 달기</button>
        </div>
      </form>
    </section>

    <!-- 409 모달 (ARCH 14.3 「다른 곳에서 수정됨」). 내용은 고정 문구뿐이다. -->
    <dialog id="conflict-dialog" class="modal" aria-labelledby="conflict-title">
      <div class="modal__head">
        <h2 id="conflict-title" class="modal__title">다른 곳에서 수정됨</h2>
        <button class="btn btn--icon btn--plain" type="button" data-conflict-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p>이 페이지는 다른 창이나 다른 기기에서 먼저 저장되었습니다. 어떻게 할지 고르세요.</p>
        <p class="muted">「새로 고침」은 지금 서버에 있는 내용을 가져옵니다 — 이 창에서 쓴 내용은 사라집니다.
           「내 것으로 덮어쓰기」는 이 창의 내용을 새 판으로 저장합니다.</p>
      </div>
      <div class="form-actions">
        <button id="conflict-reload" class="btn btn--quiet" type="button">새로 고침</button>
        <button id="conflict-overwrite" class="btn" type="button">내 것으로 덮어쓰기</button>
      </div>
    </dialog>

    <!-- 삭제 확인 (DESIGN 2.7 · 4장 「되돌릴 수 없는 동작은 확인이 뒤따른다」) -->
    <dialog id="delete-dialog" class="modal" aria-labelledby="delete-title">
      <div class="modal__head">
        <h2 id="delete-title" class="modal__title">페이지를 지울까요?</h2>
        <button class="btn btn--icon btn--plain" type="button" data-delete-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p>이 페이지와 하위 페이지가 목록에서 사라집니다.</p>
        <!-- 4-C — 되살리는 길이 생겼다(「내 공간」의 휴지통). 30일은 창구가 정한 값이고
             화면은 그 숫자를 되풀이해 적지 않는다(`GET /api/me/trash` 의 `keep_days`). -->
        <p class="muted">30일 안에는 「내 공간」의 휴지통에서 되살릴 수 있습니다. 하위 페이지도 함께 돌아옵니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-delete-close>그만두기</button>
        <button id="delete-confirm" class="btn btn--danger" type="button">지우기</button>
      </div>
    </dialog>

    <!-- 「옮기기」 창 (4-A, DESIGN 2.7 모달 · 2.14 `.move-tree`).
         내 공간의 트리를 한 번(`GET /api/pages?tree=1`)에 받아 그린다. 자기 자신과 자기
         하위는 고를 수 없게 빼 두지만, **서버가 다시 판단한다**(`decidePageMove` — cycle).
         목록 내용은 전부 editor.js 가 createElement/textContent 로 채운다. -->
    <dialog id="move-dialog" class="modal" aria-labelledby="move-title">
      <div class="modal__head">
        <h2 id="move-title" class="modal__title">페이지 옮기기</h2>
        <button class="btn btn--icon btn--plain" type="button" data-move-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p class="muted">새 상위 페이지를 고르세요. 하위 페이지도 함께 따라갑니다.</p>
        <p id="move-msg" class="msg" role="alert" aria-live="polite"></p>
        <div id="move-tree" class="move-tree" role="listbox" aria-label="새 상위 페이지"></div>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-move-close>그만두기</button>
        <button id="move-confirm" class="btn" type="button">여기로 옮기기</button>
      </div>
    </dialog>

    <!-- 「공개 범위」 창 (4-B, DESIGN 2.7 `.modal--form` 520px · PRD 4.1).
         세 줄 라디오 + 「하위 페이지까지 함께」 체크 + 설명 한 줄이다. 값은 셋뿐이다 —
         게시(5-C)는 이 창이 아니라 속성 줄의 「게시」 단추(게시판 고르기)로 한다.
         t-box 의 「폴더 등급 바꾸기」 창(`src/handlers/home.php`)과 **같은 모양**을 쓴다:
         `.field field--full` 안에 입력을 넣고 설명을 라벨에 붙이는 방식이라 새 클래스가 0 이다. -->
    <dialog id="visibility-dialog" class="modal modal--form" aria-labelledby="visibility-title">
      <div class="modal__head">
        <h2 id="visibility-title" class="modal__title">공개 범위</h2>
        <button class="btn btn--icon btn--plain" type="button" data-visibility-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <div id="visibility-choices">
        <p class="muted">이 페이지를 누가 볼 수 있는지 고릅니다. 고치는 사람은 나와, 아래에서 「편집」으로 고른 사람입니다.</p>
        <p id="visibility-msg" class="msg" role="alert" aria-live="polite"></p>

        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-visibility" value="private" checked>
            비공개 — 나와 관리자만 봅니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-visibility" value="team">
            팀 — 내 팀 사람들이 봅니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-visibility" value="internal">
            사내 — 로그인한 사내 구성원이 봅니다 (손님 제외)
          </span>
        </label>

        <label class="field field--full">
          <span class="field__label">
            <input id="visibility-cascade" type="checkbox">
            하위 페이지까지 함께 (하위 페이지 전부를 같은 공개 범위로 맞춥니다)
          </span>
        </label>

        <p class="muted">하위 페이지는 상위보다 넓게 열 수 없습니다.</p>
        </div>

        <!-- 「사람 추가」 (5-A, PRD NG-8 해제). 공개 범위 위에 **더하는** 권한이다 — 아이디로 찾아
             보기·편집을 고른다. 후보는 창구 `GET /api/share-candidates` 가, 목록은
             `GET /api/pages/{id}/shares` 가 준 것만 그린다(화면이 누가 볼 수 있는지 따지지 않는다).
             추가·등급 바꾸기·빼기는 **누르는 즉시** 창구로 간다(아래 「바꾸기」는 공개 범위만 바꾼다).
             새 클래스 0 — `.field`·`.toolbar`·`.list`·`.badge` 는 DESIGN 2장의 구성 요소다. -->
        <section id="share-section" aria-labelledby="share-title">
          <p id="share-title" class="field__label">사람 추가</p>
          <p id="share-msg" class="msg" role="alert" aria-live="polite"></p>
          <div id="share-add" class="toolbar">
            <input id="share-search" type="search" placeholder="아이디나 이름으로 찾기" maxlength="64"
                   autocomplete="off" spellcheck="false" aria-label="공유할 사람 찾기">
            <select id="share-level" aria-label="줄 권한">
              <option value="view">보기</option>
              <option value="edit">편집</option>
            </select>
          </div>
          <!-- 5-A″ — 후보는 한 글자부터 나온다(창구도 빈 입력에는 빈 목록). 치기 전에는 이 안내만 보인다. -->
          <p id="share-hint" class="muted" hidden>아이디나 이름을 입력하세요</p>
          <ul id="share-candidates" class="list list--scroll" aria-label="찾은 사람" hidden></ul>
          <p id="share-count" class="muted"></p>
          <ul id="share-list" class="list" aria-label="공유한 사람"></ul>
          <p class="muted">공유는 하위 페이지에도 미칩니다. 「편집」으로 받은 사람도 지우기·옮기기·공개 범위·공유·넘기기는 못 합니다.</p>
          <p>
            <button id="share-leave" class="btn btn--quiet btn--small btn--danger" type="button" hidden>내 공유 빼기</button>
          </p>
        </section>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-visibility-close>닫기</button>
        <button id="visibility-save" class="btn" type="button">바꾸기</button>
      </div>
    </dialog>

    <!-- 「소유자 넘기기」 창 (5-A 인계, DESIGN 2.7 `.modal--form` · PRD 4.1 「인계(F-28)」).
         받는 사람 찾기 + 「기존 소유자에게 남길 권한」 라디오 셋. **라디오에 기본 선택이 없다**
         (사용자 결정 2) — 받는 사람과 남길 권한을 둘 다 고르기 전에는 「넘기기」가 눌리지 않는다.
         후보는 창구 `GET /api/share-candidates?for=handover` 가 준 것만이고(손님·쓰지 않는 계정 제외),
         서버가 다시 판단한다(`decidePageHandover`·`decideHandoverTarget`). -->
    <dialog id="handover-dialog" class="modal modal--form" aria-labelledby="handover-title">
      <div class="modal__head">
        <h2 id="handover-title" class="modal__title">소유자 넘기기</h2>
        <button class="btn btn--icon btn--plain" type="button" data-handover-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="handover-lead" class="muted">이 페이지와 하위 페이지가 받는 사람의 공간 맨 위로 옮겨 갑니다.</p>
        <p id="handover-msg" class="msg" role="alert" aria-live="polite"></p>

        <label class="field field--full">
          <span class="field__label">받는 사람</span>
          <input id="handover-search" type="search" placeholder="아이디나 이름으로 찾기" maxlength="64"
                 autocomplete="off" spellcheck="false">
        </label>
        <!-- 5-A″ — 받는 사람도 한 글자부터 찾는다(창을 열자마자 사람 목록을 통째로 보이지 않는다). -->
        <p id="handover-hint" class="muted">아이디나 이름을 입력하세요</p>
        <ul id="handover-candidates" class="list list--scroll" aria-label="받을 수 있는 사람"></ul>
        <p id="handover-picked" class="muted">받는 사람을 아직 고르지 않았습니다.</p>

        <p class="field__label">기존 소유자에게 남길 권한</p>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="handover-keep" value="none"> 없음 — 넘긴 뒤에는 볼 수 없습니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="handover-keep" value="view"> 보기 — 「공유받은 페이지」에서 읽기만 합니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="handover-keep" value="edit"> 편집 — 내용을 계속 고칠 수 있습니다
          </span>
        </label>
        <p class="muted">내용·이력·체크 기록·작성자 이름은 그대로 따라갑니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-handover-close>그만두기</button>
        <button id="handover-confirm" class="btn btn--danger" type="button" disabled>넘기기</button>
      </div>
    </dialog>

    <!-- 「이력」 창 (4-C, DESIGN 2.7 모달 960px · 2.4 표 · 2.13 줄 목록).
         탭 둘은 **단추 둘**이다 — 고른 쪽이 검정(`.btn`), 나머지가 흐림(`.btn--quiet`)이고
         `aria-selected` 로 읽어 주는 기계에도 알린다. 탭용 새 클래스를 만들지 않았다
         (DESIGN 원칙 6).
         · 「버전」 탭 — 판마다 한 줄인 표(`table.files`). 「보기」는 **이 창 안에서** 그 판을
           읽기 렌더러로 그리고, 「되돌리기」는 같은 창 안에서 한 번 더 묻는다
           (모달 안에 모달을 열지 않는다 — DESIGN 2.7).
         · 「처리 이력」 탭 — 판·체크를 시간순 한 줄씩(`.list`). 열이 정해져 있지 않아 표가
           아니라 줄 목록이다 (DESIGN 2.13).
         내용은 전부 editor.js 가 createElement/textContent 로 채운다. 본문 글자는 「보기」를
         눌렀을 때만, 창구 `GET …/versions/{num}` 을 지나 들어온다 (C:T-44). -->
    <!-- 「템플릿으로 저장」 창 (7-B, DESIGN 2.7 `.modal--form` — 공개 범위 창과 같은 라디오 줄 모양, 새 클래스 0).
         범위 항목은 창구가 준 `template_scopes` 뿐이고, 저장 창구가 같은 판정(`decideTemplateSave`)으로 다시 막는다.
         담는 것: 제목 · 아이콘 · 본문(첨부 · 하위 페이지 블록 빼고) · DB 행이면 속성 값(사람 · 관계 · 파일 빼고). -->
    <dialog id="template-save-dialog" class="modal modal--form" aria-labelledby="template-save-title">
      <div class="modal__head">
        <h2 id="template-save-title" class="modal__title">템플릿으로 저장</h2>
        <button class="btn btn--icon btn--plain" type="button" data-template-save-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="template-save-msg" class="msg" role="alert" aria-live="polite"></p>
        <label class="field field--full">
          <span class="field__label">템플릿 이름</span>
          <input id="template-save-name" class="input" type="text" maxlength="60" autocomplete="off">
        </label>
        <div id="template-save-scopes"></div>
        <p class="muted">첨부 파일 · 하위 페이지 · 댓글은 담지 않습니다. 템플릿을 지워도 이미 만든 페이지는 그대로입니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-template-save-close>닫기</button>
        <button id="template-save-confirm" class="btn" type="button">저장</button>
      </div>
    </dialog>

    <dialog id="history-dialog" class="modal" aria-labelledby="history-title">
      <div class="modal__head">
        <h2 id="history-title" class="modal__title">이력</h2>
        <p id="history-meta" class="muted"></p>
        <button class="btn btn--icon btn--plain" type="button" data-history-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <div class="toolbar" role="tablist" aria-label="이력 종류">
          <button id="history-tab-versions" class="btn" type="button" role="tab"
                  aria-selected="true" aria-controls="history-versions">버전</button>
          <button id="history-tab-events" class="btn btn--quiet" type="button" role="tab"
                  aria-selected="false" aria-controls="history-events">처리 이력</button>
        </div>

        <p id="history-msg" class="msg" role="alert" aria-live="polite"></p>

        <!-- 되돌리기 확인 (DESIGN 4장 「되돌릴 수 없는 동작은 확인이 뒤따른다」) -->
        <div id="history-confirm" class="notice" role="status" hidden>
          <p id="history-confirm-text" class="notice__title"></p>
          <p class="muted">지금 내용은 새 판으로 남고, 지난 판도 그대로 남습니다. 지워지는 것은 없습니다.</p>
          <p>
            <button id="history-confirm-cancel" class="btn btn--quiet" type="button">그만두기</button>
            <button id="history-confirm-ok" class="btn btn--danger" type="button">되돌리기</button>
          </p>
        </div>

        <section id="history-versions" role="tabpanel" aria-labelledby="history-tab-versions">
          <div class="table-wrap">
            <table class="files">
              <thead>
                <tr><th>판</th><th>제목</th><th>바꾼 사람</th><th>시각</th><th>블록</th><th></th></tr>
              </thead>
              <tbody id="history-versions-body"></tbody>
            </table>
          </div>
          <p id="history-versions-empty" class="muted" hidden>아직 지난 판이 없습니다.</p>
        </section>

        <section id="history-events" role="tabpanel" aria-labelledby="history-tab-events" hidden>
          <ul id="history-events-list" class="list list--scroll"></ul>
          <p id="history-events-empty" class="muted" hidden>아직 남은 기록이 없습니다.</p>
        </section>

        <!-- 「보기」로 연 판의 본문. 읽기 렌더러(`renderDoc`)가 그리므로 화면과 실제가 같다. -->
        <section id="history-preview" hidden>
          <div class="toolbar">
            <p id="history-preview-title" class="nav-title"></p>
            <button id="history-preview-back" class="btn btn--quiet" type="button">목록으로</button>
          </div>
          <article id="history-preview-doc" class="panel"></article>
        </section>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-history-close>닫기</button>
      </div>
    </dialog>

    <!-- 「블록 추가」 창 (DESIGN 2.7 모달 · 2.14). 왼쪽 목록에서 고르면 오른쪽에 미리보기가
         그려진다 — 미리보기는 읽기 렌더러 `renderDoc` 이 그리므로 견본과 실제가 어긋나지 않는다.
         목록·미리보기 내용은 전부 `editor.js` 가 createElement/textContent 로 채운다. -->
    <dialog id="block-dialog" class="modal" aria-labelledby="block-dialog-title">
      <div class="modal__head">
        <h2 id="block-dialog-title" class="modal__title">블록 추가</h2>
        <button class="btn btn--icon btn--plain" type="button" data-block-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body block-picker">
        <ul id="block-list" class="list" role="listbox" aria-label="블록 종류"></ul>
        <div class="block-picker__detail">
          <p id="block-preview-name" class="panel__title"></p>
          <p id="block-desc" class="muted"></p>
          <p id="block-keys" class="block-picker__keys"></p>
          <div id="block-sample" class="block-picker__sample"></div>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-block-close>닫기</button>
        <button id="block-insert" class="btn" type="button">넣기</button>
      </div>
    </dialog>

    <!-- 6-A 「속성 더하기·고치기」 창. 종류는 더할 때만 고른다(바꾸기는 지우고 더하기). 지우기는 두 번 눌러야 한다. -->
    <dialog id="db-prop-dialog" class="modal modal--form" aria-labelledby="db-prop-title">
      <div class="modal__head">
        <h2 id="db-prop-title" class="modal__title">속성 더하기</h2>
        <button class="btn btn--icon btn--plain" type="button" data-db-prop-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body form">
        <p id="db-prop-msg" class="msg" role="alert" aria-live="polite"></p>
        <label class="field field--full">
          <span class="field__label">이름</span>
          <input id="db-prop-name" type="text" maxlength="50" autocomplete="off" spellcheck="false">
        </label>
        <label id="db-prop-type-field" class="field field--full">
          <span class="field__label">종류</span>
          <select id="db-prop-type"></select>
        </label>
        <label id="db-prop-target-field" class="field field--full" hidden>
          <span class="field__label">관계 맺을 데이터베이스 (같은 공간)</span>
          <select id="db-prop-target"></select>
        </label>
        <label id="db-prop-options-field" class="field field--full" hidden>
          <span class="field__label">선택지 — 한 줄에 하나</span>
          <textarea id="db-prop-options" rows="5" spellcheck="false"></textarea>
        </label>
      </div>
      <div class="form-actions">
        <button id="db-prop-remove" class="btn btn--quiet btn--danger" type="button" hidden>지우기</button>
        <button class="btn btn--quiet" type="button" data-db-prop-close>닫기</button>
        <button id="db-prop-save" class="btn" type="button">더하기</button>
      </div>
    </dialog>

    <!-- 「블록 고치기」 창 (5-E, 결정 E-2). 토글·표·페이지 링크·그림·파일은 편집기 안에서 **덩어리**이고
         값은 이 창에서 고친다 — 넣을 때 먼저 열리고, 편집기에서 카드를 두 번 누르거나(또는 골라서 Enter) 다시 연다.
         다섯 칸 중 그 종류의 칸만 보인다. 표 칸은 진짜 <table> 안의 입력 칸이라 열 수가 바뀌어도 인라인 style 이 없다.
         파일 목록은 t-box 창구 `GET /tbox/api/files`(내 권한 목록)를 그대로 쓴다 — 커뮤니티 창구를 새로 두지 않는다. -->
    <dialog id="atom-dialog" class="modal modal--form" aria-labelledby="atom-dialog-title">
      <div class="modal__head">
        <h2 id="atom-dialog-title" class="modal__title">블록 고치기</h2>
        <button class="btn btn--icon btn--plain" type="button" data-atom-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body form">
        <p id="atom-msg" class="msg" role="alert" aria-live="polite"></p>

        <div id="atom-toggle" class="atom-pane" hidden>
          <label class="field field--full">
            <span class="field__label">제목 줄 (누르면 펼쳐진다)</span>
            <input id="atom-toggle-summary" type="text" maxlength="500" autocomplete="off" spellcheck="false">
          </label>
          <label class="field field--full">
            <span class="field__label">펼치면 보이는 내용 — 한 줄이 한 문단</span>
            <textarea id="atom-toggle-body" rows="6" spellcheck="false"></textarea>
          </label>
        </div>

        <div id="atom-table" class="atom-pane" hidden>
          <label class="field field--full">
            <span class="field__label"><input id="atom-table-hrow" type="checkbox"> 첫 행을 머리로</span>
          </label>
          <label class="field field--full">
            <span class="field__label"><input id="atom-table-hcol" type="checkbox"> 첫 열을 머리로</span>
          </label>
          <div class="doc-table-wrap">
            <table class="doc-table atom-grid"><tbody id="atom-table-grid"></tbody></table>
          </div>
          <div class="toolbar">
            <button id="atom-row-add" class="btn btn--quiet btn--small" type="button">행 더하기</button>
            <button id="atom-row-del" class="btn btn--quiet btn--small" type="button">마지막 행 빼기</button>
            <button id="atom-col-add" class="btn btn--quiet btn--small" type="button">열 더하기</button>
            <button id="atom-col-del" class="btn btn--quiet btn--small" type="button">마지막 열 빼기</button>
          </div>
          <!-- 6-C 칸 합치기 — 칸 하나를 눌러 고른 뒤, 그 칸부터 오른쪽·아래로 몇 칸을 합칠지 -->
          <div class="toolbar atom-merge">
            <label class="atom-merge__field">가로 <input id="atom-merge-cols" class="input" type="number" min="1" max="20" value="2"></label>
            <label class="atom-merge__field">세로 <input id="atom-merge-rows" class="input" type="number" min="1" max="20" value="1"></label>
            <button id="atom-merge" class="btn btn--quiet btn--small" type="button">고른 칸부터 합치기</button>
            <button id="atom-unmerge" class="btn btn--quiet btn--small" type="button">고른 칸 풀기</button>
          </div>
          <p class="help">합칠 칸이 비어 있어야 합칩니다. 칸 글자에는 꾸밈이 붙지 않습니다.</p>
        </div>

        <!-- 6-C 단 나누기 — 단 수(2·3)와 단마다의 문단(한 줄이 한 문단) -->
        <div id="atom-columns" class="atom-pane" hidden>
          <label class="field">
            <span class="field__label">단 수</span>
            <select id="atom-columns-n"><option value="2">2단</option><option value="3">3단</option></select>
          </label>
          <div class="atom-columns">
            <label class="field"><span class="field__label">1단</span><textarea id="atom-col-0" rows="6" spellcheck="false"></textarea></label>
            <label class="field"><span class="field__label">2단</span><textarea id="atom-col-1" rows="6" spellcheck="false"></textarea></label>
            <label id="atom-col-2-field" class="field"><span class="field__label">3단</span><textarea id="atom-col-2" rows="6" spellcheck="false"></textarea></label>
          </div>
        </div>

        <div id="atom-link" class="atom-pane" hidden>
          <label class="field field--full">
            <span class="field__label">걸 페이지 찾기</span>
            <input id="atom-link-q" type="search" maxlength="200" autocomplete="off" spellcheck="false" placeholder="제목이나 본문의 낱말">
          </label>
          <p id="atom-link-chosen" class="help"></p>
          <ul id="atom-link-list" class="list" aria-label="찾은 페이지"></ul>
        </div>

        <!-- 7-C 임베드 — 주소(https)와 설명. 허용된 사이트 안내는 창구 `GET /api/embed-domains` 가 준 목록 그대로이고,
             목록 밖 주소는 **서버가 저장할 때** 400 으로 막는다(여기서 걸러 보이지 않는다). -->
        <div id="atom-embed" class="atom-pane" hidden>
          <label class="field field--full">
            <span class="field__label">주소 (https)</span>
            <input id="atom-embed-url" class="input" type="url" maxlength="2048" autocomplete="off" spellcheck="false" placeholder="https://">
          </label>
          <p id="atom-embed-hosts" class="help"></p>
          <label class="field field--full">
            <span class="field__label">설명 (선택)</span>
            <input id="atom-embed-caption" class="input" type="text" maxlength="2000" autocomplete="off">
          </label>
        </div>

        <div id="atom-file" class="atom-pane" hidden>
          <label class="field field--full">
            <span class="field__label">내 t-box 파일에서 고르기</span>
            <input id="atom-file-q" type="search" maxlength="200" autocomplete="off" spellcheck="false" placeholder="파일 이름 (비우면 맨 위 폴더)">
          </label>
          <p id="atom-file-chosen" class="help"></p>
          <ul id="atom-file-list" class="list" aria-label="고를 수 있는 파일"></ul>
          <div class="toolbar">
            <a id="atom-file-upload" class="btn btn--quiet btn--small" href="#" target="_blank" rel="noopener">t-box 에 올리기</a>
            <button id="atom-file-refresh" class="btn btn--quiet btn--small" type="button">목록 새로 고침</button>
          </div>
          <p class="help">페이지를 읽는 사람이 모두 볼 수 있는 등급의 파일만 붙습니다. 좁으면 저장할 때 알려 드립니다.</p>
          <label class="field field--full">
            <span class="field__label">설명 (선택)</span>
            <input id="atom-file-caption" type="text" maxlength="500" autocomplete="off" spellcheck="false">
          </label>
          <label id="atom-image-width-field" class="field">
            <span class="field__label">그림 폭</span>
            <select id="atom-image-width">
              <option value="small">작게</option>
              <option value="normal">보통</option>
              <option value="wide">넓게</option>
              <option value="full">꽉 차게</option>
            </select>
          </label>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-atom-close>닫기</button>
        <button id="atom-commit" class="btn" type="button">넣기</button>
      </div>
    </dialog>


    <!-- 「상태」 창 (4-D, DESIGN 2.7 `.modal--form` 520px · 2.2 배지).
         라디오 여섯 — 「없음」 + 다섯 값이다. 「없음」이 기본이고 맨 위에 있다:
         모든 페이지가 업무는 아니다. 글자와 배지 색은 DESIGN 2.2 의 뜻 그대로이고
         새 색값을 만들지 않았다(회색·파랑·보라·초록·노랑 토큰을 그대로 쓴다). -->
    <dialog id="status-dialog" class="modal modal--form" aria-labelledby="status-title">
      <div class="modal__head">
        <h2 id="status-title" class="modal__title">상태</h2>
        <button class="btn btn--icon btn--plain" type="button" data-status-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p class="muted">이 페이지가 업무라면 지금 어디까지 왔는지 고릅니다. 업무가 아니면 「없음」으로 둡니다.</p>
        <p id="status-msg" class="msg" role="alert" aria-live="polite"></p>

        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-status" value="" checked> 없음 — 업무가 아닙니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-status" value="todo"> 할 일 — 아직 시작하지 않았습니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-status" value="doing"> 진행 — 하고 있습니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-status" value="review"> 검토 — 다 하고 확인을 기다립니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-status" value="done"> 완료 — 끝났습니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-status" value="hold"> 보류 — 멈춰 두었습니다
          </span>
        </label>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-status-close>그만두기</button>
        <button id="status-save" class="btn" type="button">바꾸기</button>
      </div>
    </dialog>

    <!-- 「게시판에 게시」 창 (5-C, DESIGN 2.7 `.modal--form`). 고를 수 있는 게시판은 창구 `GET /api/boards` 가
         `can_write` 로 답한 것만이다(화면이 등급을 따지지 않는다). 창구가 다시 판단한다(`decideBoardWrite`). -->
    <dialog id="publish-dialog" class="modal modal--form" aria-labelledby="publish-title">
      <div class="modal__head">
        <h2 id="publish-title" class="modal__title">게시판에 게시</h2>
        <button class="btn btn--icon btn--plain" type="button" data-publish-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p class="muted">이 페이지를 고른 게시판 목록에 겁니다. 원본은 내 공간에 그대로 있고, 고치면 게시판에도 그대로 보입니다.
           게시하는 동안은 그 게시판을 볼 수 있는 사람이 이 페이지를 봅니다. 하위 페이지는 따라가지 않습니다.</p>
        <p id="publish-msg" class="msg" role="alert" aria-live="polite"></p>
        <div id="publish-choices"></div>
        <p id="publish-empty" class="muted" hidden>글을 쓸 수 있는 게시판이 없습니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-publish-close>닫기</button>
        <button id="publish-save" class="btn" type="button" disabled>게시</button>
      </div>
    </dialog>

    <!-- 페이지 id 를 전하는 자리. 값은 라우터가 UUID 로만 통과시킨 뒤 이스케이프한 것이다. -->
    <span id="page-meta" hidden data-page-id="<?= htmlspecialchars($viewPageId, ENT_QUOTES, 'UTF-8') ?>"></span>
<?php
$pageMsgTop = true;
require __DIR__ . '/../../views/shell_bottom.php';
