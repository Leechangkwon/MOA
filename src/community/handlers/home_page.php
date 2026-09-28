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

/*
 * 디버깅 묶음 U H3 (2026-09-26) — 쓰는 단추(「새 페이지」 · 「템플릿에서 만들기」 · 「선택하기」)는 **쓸 수 있는 역할**에게만 그린다.
 * 값은 `/api/me` 의 `can_community_write` 와 같은 함수(`Authz::canCommunityWrite` — 라우트 정책 `community.write` 가 부르는 그 함수)를
 * 그대로 부른 결과다. 숨김은 편의일 뿐 막는 것은 서버다: 단추를 되살려 눌러도 쓰는 창구는 전부 `community.write` 정책이 403 으로 막는다
 * (PRD S-01). 손님(Guest)에게는 누르면 「권한 없음」만 나오던 단추들이다(디자인 점검 2026-09-26 12:13).
 */
$homeWriteHidden = Authz::canCommunityWrite($user) ? '' : ' hidden';

$pageTitle   = '내 공간';
$activeNav   = 'community-home';
$pageKey     = 'community-home';
$pageHelp    = 'community-home';
$pageCss     = ['community/community.css'];
$shellModule = 'community';
// 7-D2 — 관리자 팔레트 CSS 의 버전(내용 해시 12자리). 셸이 `<link id="palette-css" … ?v=>` 를 그린다(P-6·P-7 — 표를 못 읽으면 빈 목록의 버전)
$pagePalette = community_palette_version();
require __DIR__ . '/../../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <!-- 제목·설명은 고른 공간에 따라 editor.js 가 textContent 로 바꾼다 (4-B). -->
        <h1 id="community-space-title" class="page-head__title">내 공간</h1>
        <!-- 디버깅 묶음 U3 — 손님(쓰기 자격 없음 — 「새 페이지」를 숨기는 것과 같은 값 Authz::canCommunityWrite)에게는 읽기 안내(editor.js GUEST_SPACE_SUB 와 같은 글자) -->
        <p id="community-space-sub" class="page-head__sub"><?= $homeWriteHidden === '' ? '나만 보는 개인 업무 공간입니다. 페이지를 만들어 적어 두세요.' : '손님 계정은 읽기만 합니다. 공유받은 페이지와 볼 수 있는 게시판 글을 읽을 수 있습니다.' ?></p>
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
            <button id="community-template-new" class="menu__item" type="button" aria-haspopup="dialog"<?= $homeWriteHidden ?>>
              <span class="icon" data-icon="files"></span>템플릿에서 만들기
            </button>
            <!-- 「휴지통」 (4-C). 목록은 창구 `GET /api/me/trash` 가 주는 것만이고, 화면에는
                 「내가 지운 것인가」를 따지는 코드가 한 줄도 없다 (PRD S-01). -->
            <button id="community-trash" class="menu__item" type="button" aria-haspopup="dialog">
              <span class="icon" data-icon="trash"></span>내 휴지통
            </button>
            <!-- 「많이 읽힌 문서」 (7-D1, N-34) — 창구 `GET /api/boards` 의 `can_manage` 가 참일 때만 보인다(Admin) -->
            <button id="community-popular" class="menu__item" type="button" aria-haspopup="dialog" hidden>
              <span class="icon" data-icon="log"></span>많이 읽힌 문서
            </button>
            <!-- 「임베드 허용 목록」 (7-C, C:F-32) — 창구 `GET /api/embed-domains` 의 `can_manage` 가 참일 때만 보인다(Admin) -->
            <button id="community-embed-admin" class="menu__item" type="button" aria-haspopup="dialog" hidden>
              <span class="icon" data-icon="shield"></span>임베드 허용 목록
            </button>
            <!-- 「팔레트 관리」 (7-D2, C:F-33) — 창구 `GET /api/boards` 의 `can_manage` 가 참일 때만 보인다(Admin — 7-D1 과 같은 자리) -->
            <button id="community-palettes" class="menu__item" type="button" aria-haspopup="dialog" hidden>
              <span class="icon" data-icon="settings"></span>팔레트 관리
            </button>
          </div>
        </details>
        <button id="community-new" class="btn" type="button"<?= $homeWriteHidden ?>>
          <span class="icon" data-icon="plus"></span>새 페이지
        </button>
      </div>
    </header>

    <!-- T:F-72 라운드 C · 결정 PK-D5(사용자 d71 「앱 + 즐겨찾기로 접근」) — 좁은 폭(960px 미만) 첫 화면 맨 위에 **즐겨찾기 먼저**.
         폰에서는 사이드바가 ☰ 안이라 즐겨찾기가 두 번 눌러야 보였다. 항목은 사이드바와 같은 창구(`GET /community/api/me/favorites`)의
         같은 답 · 같은 줄 모양(`.nav-tree`)을 editor.js 가 한 번 더 그린다(새 창구·새 클래스 0). 넓은 폭·즐겨찾기 0 이면 숨는다. -->
    <section class="panel panel--tight" id="community-home-fav" aria-labelledby="community-home-fav-title" hidden>
      <div class="panel__head">
        <h2 class="panel__title" id="community-home-fav-title">즐겨찾기</h2>
      </div>
      <div id="community-home-fav-list"></div>
    </section>

    <!-- 사용감 라운드 1 UX-4 — 「최근」 다섯 줄(폰·넓은 폭 둘 다) — 어제 쓰던 글로 곧바로(전에는 ☰ 안의 사이드바에만 있었다).
         항목은 사이드바 「최근」과 **같은 창구 · 같은 답**(`GET /community/api/me/recent` — 첫 화면이 이미 부른다, 새 요청 0)을
         editor.js 가 같은 줄 모양(`.nav-tree` — ☆ 포함)으로 한 번 더 그린다. 줄을 누르면 그 페이지. 비었으면 판째 숨는다.
         자리는 즐겨찾기 판 **다음**(결정 PK-D5 「좁은 폭 첫 화면 맨 위는 즐겨찾기 먼저」) · 공유받은 페이지·트리 **위**. 새 클래스 0. -->
    <section class="panel panel--tight" id="community-home-recent" aria-labelledby="community-home-recent-title" hidden>
      <div class="panel__head">
        <h2 class="panel__title" id="community-home-recent-title">최근</h2>
      </div>
      <div id="community-home-recent-list"></div>
    </section>

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
      <!-- 개발 대기열 6 · Q:F-3a 라운드 B — 「선택」 모드(지시서 1.3). 누르면 트리 줄마다 체크가 붙고(누른 상태 aria-pressed), 다시 누르거나
           Esc 면 끝난다. 찾기(카드 목록)에서는 쓰지 않는다 — 찾기를 시작하면 모드가 끝난다. 공간을 바꾸면 화면이 다시 열려 풀린다(M-4). -->
      <button id="community-select" class="btn btn--quiet" type="button" aria-pressed="false" aria-controls="community-tree"<?= $homeWriteHidden ?>>
        <span class="icon" data-icon="check"></span><span id="community-select-label">선택하기</span>
      </button>
    </div>

    <!-- 선택 막대 — t-box 파일 화면의 막대와 같은 모양(개수 + 행 동작과 같은 아이콘 단추 · 폰 폭에서도 한 줄). 동작은 editor.js 가
         **한 건 작업과 같은 창구**(`PATCH /api/pages/{id}/move` · `PUT …/favorite` · `DELETE /api/pages/{id}`)를 항목마다 한 번씩 부른다 —
         새 서버 창구 0, 판정·감사·거부 문장은 한 건과 같다. 공개 범위 일괄 바꾸기는 1차에 없다(결정 M-6). -->
    <div class="toolbar" id="community-bulk-bar" role="group" aria-label="고른 페이지로 할 일" hidden>
      <span id="community-bulk-count" aria-live="polite"></span>
      <button id="community-bulk-move" class="btn btn--icon" type="button" aria-label="옮기기" aria-haspopup="dialog">
        <span class="icon" data-icon="files"></span>
      </button>
      <button id="community-bulk-fav" class="btn btn--icon" type="button" aria-label="즐겨찾기에 넣기">
        <span class="icon" data-icon="star"></span>
      </button>
      <button id="community-bulk-trash" class="btn btn--icon" type="button" aria-label="지우기" aria-haspopup="dialog">
        <span class="icon" data-icon="trash"></span>
      </button>
      <button id="community-bulk-done" class="btn btn--icon" type="button" aria-label="선택 끝내기" aria-keyshortcuts="Escape">
        <span class="icon" data-icon="x"></span>
      </button>
    </div>
    <!-- 막대 아래 안내 한 줄(모드 동안) — 막대 안에 쓰면 폰 폭에서 막대가 두 줄로 꺾인다(스크린샷 확인). -->
    <p id="community-bulk-note" class="help" aria-live="polite" hidden></p>

    <!-- 일괄 진행 표 — t-box 올리기 창·파일 화면과 같은 진행 표(`.upload-status` — 대기 · 하는 중 · 완료 · 서버가 준 거부 문장).
         부모와 그 하위를 함께 골랐으면 하위는 보내지 않고 「부모와 함께 …」로 적는다(지시서 1.3). 로그인이 끝나면(401) 멈추고
         남은 줄을 그대로 둔 채 「이어서 하기」를 보인다. 줄은 editor.js 가 createElement + textContent 로만 만든다. -->
    <section class="panel panel--tight" id="community-bulk-panel" aria-labelledby="community-bulk-title" hidden>
      <div class="panel__head">
        <h2 class="panel__title" id="community-bulk-title" tabindex="-1"></h2>
        <p class="panel__sub">한 번에 하나씩 차례로 보냅니다. 할 수 없는 페이지는 그 페이지만 거부되고 이유가 여기 남습니다.</p>
      </div>
      <div class="table-wrap list--scroll">
        <table class="files upload-status">
          <thead><tr><th scope="col">페이지</th><th scope="col">상태</th></tr></thead>
          <tbody id="community-bulk-body"></tbody>
        </table>
      </div>
      <div id="community-bulk-stop" class="notice" role="alert" hidden>
        <p class="notice__title" id="community-bulk-stop-text"></p>
        <div class="toolbar">
          <a id="community-bulk-login" class="btn btn--quiet btn--small" href="<?= Http::url('/login?to=community') ?>" target="_blank" rel="noopener noreferrer">로그인 화면 열기</a>
          <button id="community-bulk-resume" class="btn btn--quiet btn--small" type="button">이어서 하기</button>
        </div>
      </div>
      <div class="table-foot">
        <span id="community-bulk-summary" role="status" aria-live="polite"></span>
        <div class="form-actions">
          <button id="community-bulk-undo" class="btn btn--quiet btn--small" type="button" hidden>되돌리기</button>
          <button id="community-bulk-close" class="btn btn--quiet btn--small" type="button">닫기</button>
        </div>
      </div>
    </section>

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

    <!-- 라운드 B — 고른 페이지 옮기기 창. 페이지 화면의 「옮기기」 창(page_view.php)과 **같은 모양**(`.move-tree` — 4-A)이다.
         이 공간의 트리를 그리되 고른 페이지와 그 하위는 새 부모 후보에서 뺀다 — 그래도 **막는 것은 서버**다(`decidePageMove` —
         cycle · depth_exceeded · wider_than_parent 가 페이지마다 갈린다). 목록 내용은 editor.js 가 createElement/textContent 로 채운다. -->
    <dialog id="bulk-move-dialog" class="modal" aria-labelledby="bulk-move-title">
      <div class="modal__head">
        <h2 id="bulk-move-title" class="modal__title">고른 페이지 옮기기</h2>
        <p id="bulk-move-meta" class="muted"></p>
        <button class="btn btn--icon btn--plain" type="button" data-bulk-move-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p class="muted">새 상위 페이지를 고르세요. 하위 페이지도 함께 따라갑니다. 부모와 그 하위를 함께 골랐으면 하위는 부모와 함께 옮겨집니다.</p>
        <p id="bulk-move-msg" class="msg" role="alert" aria-live="polite"></p>
        <div id="bulk-move-tree" class="move-tree" role="listbox" aria-label="새 상위 페이지"></div>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-bulk-move-close>닫기</button>
        <button id="bulk-move-confirm" class="btn" type="button">여기로 옮기기</button>
      </div>
    </dialog>

    <!-- 라운드 B — 고른 페이지 지우기 확인 창. 페이지 화면의 「페이지를 지울까요?」 창과 같은 모양 · 같은 빨간 단추. -->
    <dialog id="bulk-delete-dialog" class="modal" aria-labelledby="bulk-delete-title">
      <div class="modal__head">
        <h2 id="bulk-delete-title" class="modal__title">고른 페이지를 지울까요?</h2>
        <button class="btn btn--icon btn--plain" type="button" data-bulk-delete-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="bulk-delete-lead"></p>
        <p class="muted">30일 안에는 휴지통에서 되살릴 수 있습니다. 하위 페이지도 함께 지워지고 함께 돌아옵니다. 지울 수 없는 페이지는 그 페이지만 거부되고 이유가 진행 표에 남습니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-bulk-delete-close>닫기</button>
        <button id="bulk-delete-confirm" class="btn btn--danger" type="button">지우기</button>
      </div>
    </dialog>

    <!-- 「휴지통」 창 (4-C, DESIGN 2.7 모달 · 2.4 표). 지운 페이지를 목록에서 떠나지 않고
         되살린다. 한 줄은 **한 번의 삭제**다 — 함께 내려간 하위는 「하위 n」으로만 세고,
         되살리면 함께 돌아온다(창구가 그렇게 돌려준다).
         표 내용은 editor.js 가 createElement/textContent 로 채운다. 본문 글자는 없다. -->
    <dialog id="trash-dialog" class="modal" aria-labelledby="trash-title">
      <div class="modal__head">
        <h2 id="trash-title" class="modal__title">내 휴지통</h2>
        <p id="trash-meta" class="muted"></p>
        <button class="btn btn--icon btn--plain" type="button" data-trash-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="trash-msg" class="msg" role="alert" aria-live="polite"></p>
        <!-- 라운드 B — 여러 줄 되살리기(지시서 1.3). 줄 앞 체크로 고르면 이 막대가 보이고, 「되살리기」는 고른 줄마다
             `POST /api/pages/{id}/restore` 를 한 번씩 보낸다(한 줄 되살리기와 같은 창구 · 같은 판정). 진행은 아래 표. -->
        <div class="toolbar" id="trash-bulk-bar" role="group" aria-label="고른 페이지로 할 일" hidden>
          <span id="trash-bulk-count" aria-live="polite"></span>
          <button id="trash-bulk-restore" class="btn btn--quiet btn--small" type="button">
            <span class="icon" data-icon="restore"></span>되살리기
          </button>
        </div>
        <section id="trash-bulk-panel" aria-labelledby="trash-bulk-title" hidden>
          <p class="nav-title" id="trash-bulk-title" tabindex="-1"></p>
          <div class="table-wrap">
            <table class="files upload-status">
              <thead><tr><th scope="col">페이지</th><th scope="col">상태</th></tr></thead>
              <tbody id="trash-bulk-body"></tbody>
            </table>
          </div>
          <div id="trash-bulk-stop" class="notice" role="alert" hidden>
            <p class="notice__title" id="trash-bulk-stop-text"></p>
            <div class="toolbar">
              <a id="trash-bulk-login" class="btn btn--quiet btn--small" href="<?= Http::url('/login?to=community') ?>" target="_blank" rel="noopener noreferrer">로그인 화면 열기</a>
              <button id="trash-bulk-resume" class="btn btn--quiet btn--small" type="button">이어서 하기</button>
            </div>
          </div>
          <p id="trash-bulk-summary" class="muted" role="status" aria-live="polite"></p>
        </section>
        <div class="table-wrap">
          <table class="files">
            <thead>
              <tr><th aria-labelledby="trash-title-head"><input type="checkbox" id="trash-check-all" data-bulk aria-label="휴지통의 페이지 모두 고르기"><span id="trash-title-head">제목</span></th><th>공간</th><th>함께 지운 하위</th><th>지운 때</th><th></th></tr>
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
          <button id="embed-add" class="btn btn--quiet btn--small" type="button">더하기</button>
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

    <!-- 「팔레트 관리」 창 (7-D2 — DESIGN 2.7 `.modal--form.modal--stack` · 2.13 `.list` · 2.5 `.form--grid` — 게시판 관리 창과 같은 모양, 새 클래스 0).
         위: 등록한 색 목록(줄마다 이름 · 견본 둘 · 값 · 대비 경고 · 라이트·다크 고르개 · 「고치기」 · 「빼기」 두 번 누르기) · 아래: 더하기 칸.
         견본의 색은 서버가 만든 CSS(`#palette-css` — 클래스 `pal-<이름>`·`pal-<이름>-bg`)가 준다 — 인라인 style 0.
         이름·한도(12)·값 모양·재인증은 서버가 막는다. 내용은 palettes.js 가 textContent 로 채운다. -->
    <dialog id="palette-dialog" class="modal modal--form modal--stack" aria-labelledby="palette-title">
      <div class="modal__head">
        <h2 id="palette-title" class="modal__title">팔레트 관리</h2>
        <button class="btn btn--icon btn--plain" type="button" data-palette-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="palette-msg" class="msg" role="alert" aria-live="polite"></p>
        <p class="muted">회사 색을 이름으로 12개까지 등록합니다. 글에는 이름이 저장되어, 색을 고치면 그 색을 쓴 글이 모두 새 색으로 보이고, 빼면 색 없이 보입니다. 이름은 나중에 바꿀 수 없습니다.</p>
        <ul id="palette-list" class="list list--scroll" aria-label="등록한 색" hidden></ul>
        <p id="palette-empty" class="muted" hidden>등록한 색이 없습니다.</p>
        <form id="palette-form" class="form form--grid" novalidate>
          <label class="field">
            <span class="field__label">이름 (필수 — 영어 소문자·숫자·붙임표)</span>
            <input id="palette-name" type="text" pattern="[a-z0-9-]{1,20}" maxlength="20" autocomplete="off" spellcheck="false" autocapitalize="off" placeholder="brand-blue">
          </label>
          <label class="field">
            <span class="field__label">밝은 화면 색</span>
            <input id="palette-light" type="color">
          </label>
          <label class="field">
            <span class="field__label">어두운 화면 색</span>
            <input id="palette-dark" type="color">
          </label>
          <p id="palette-form-warn" class="field--full"></p>
          <p id="palette-count" class="muted field--full"></p>
        </form>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-palette-close>닫기</button>
        <button id="palette-add" class="btn" type="submit" form="palette-form">더하기</button>
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
