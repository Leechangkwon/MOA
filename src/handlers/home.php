<?php
declare(strict_types=1);

/**
 * src/handlers/home.php — GET / (정책: authenticated)
 *
 * 12단계(F-20)부터 이 화면은 **파일 화면 하나**다. 내 계정은 `/settings`, 휴지통은 `/trash`,
 * 계정 관리는 `/users`, 감사 로그는 `/audit` 로 나뉘었고, 공통 셸(`src/views/shell_*.php`)이
 * 좌측 내비·사용자 칩·로그아웃을 맡는다. 한 페이지에 모든 기능이 쌓여 있던 구조를 나눈 것이며,
 * **서버 규칙·권한·API 는 한 글자도 바뀌지 않았다** (PRD F-20).
 *
 * ★ 화면 문구에 "N단계" 같은 개발 진행 단계 번호를 쓰지 않는다 (사용자 피드백 ①).
 *
 * ★ 이 파일에도 권한 로직을 두지 않는다 (PRD S-01, ARCH 13장).
 *   · 삭제·수정 버튼은 서버가 행마다 내려 준 `can_delete`·`can_edit` 로만 만든다.
 *   · 업로드 레벨 선택지는 서버가 내려 준 `upload_levels` 로만 채운다 — 그래서 <select> 를
 *     여기서 **비워 둔다**. 화면이 레벨 목록을 알고 있으면 그것이 곧 화면에 심은 권한 판단이다.
 *   · 「URL 로 가져오기」 단추·패널은 `hidden` 으로 두고 `/api/me` 의 `can_fetch` 가 참일 때만 열린다.
 *   버튼을 숨기는 것은 편의일 뿐이고, 허용 여부는 서버 Authz 가 매 요청 다시 판단한다.
 *
 * ★ 최초 로그인 강제 절차(F-16)는 **이 화면에만** 있다. 절차가 남은 계정이 다른 화면을 열면
 *   라우터 관문이 302 로 여기로 돌려보낸다(API 는 기존대로 403 + next).
 *   2단계 인증 등록 마크업은 `/settings` 와 **같은 파일**(`src/views/mfa_enroll.php`)을 쓴다.
 *
 * 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self', style-src 'self').
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '파일';
$activeNav = 'files';
$pageKey   = 'files';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19). */
$pageHelp  = 'files';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">파일</h1>
        <p class="page-head__sub">내 파일과 팀 파일을 올리고 받습니다.</p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
        <!-- 18-3 — 머리에는 단추 둘만 둔다. 폼은 아래 <dialog class="modal"> 로 열린다
             (펼침 패널이 늘 화면을 차지하지 않게 — 사용자 요청 2026-09-13).
             「URL 로 가져오기」는 기본 hidden 이고, /api/me 의 can_fetch 가 참일 때만 app.js 가 연다.
             단추를 되살려도 POST /api/files/fetch 는 라우터 정책 file.fetch 가 다시 막는다. -->
        <button id="fetch-toggle" class="btn btn--quiet" type="button" aria-haspopup="dialog" aria-controls="fetch-dialog" hidden>
          <span class="icon" data-icon="link"></span>URL 로 가져오기
        </button>
        <button id="upload-toggle" class="btn" type="button" aria-haspopup="dialog" aria-controls="upload-dialog">
          <span class="icon" data-icon="upload"></span>파일 올리기
        </button>
      </div>
    </header>

    <!-- ─── 2단계 인증 권장 카드 (PRD F-52 ③, ARCH 5.26) ──────────────────────
         **필수가 아닌 역할**이 아직 켜지 않았을 때만 나온다. app.js 가 /api/me 의
         `mfa_recommended`(boolean) 하나만 보고 연다 — 화면은 역할 문자열을 비교하지 않는다.
         「나중에」는 이 브라우저의 **이 세션**(sessionStorage)에만 남고 서버는 아무것도
         저장하지 않는다 — 탭을 닫고 다시 로그인하면 또 나온다. 그것이 「권장」의 강도다. -->
    <section class="notice notice--recommend" id="mfa-recommend" hidden>
      <p><strong>2단계 인증을 켜 두는 것을 권장합니다.</strong> 비밀번호가 새어도 인증 앱의 6자리 코드가 없으면 계정에 들어올 수 없습니다.</p>
      <p class="notice__actions">
        <a class="btn btn--small" href="<?= Http::url('/settings') ?>">지금 켜기</a>
        <button id="mfa-recommend-later" class="btn btn--quiet btn--small" type="button">나중에</button>
      </p>
    </section>

    <!-- ─── 최초 로그인 강제 절차 (PRD F-16, ARCH 4.0) ────────────────────────
         임시 비밀번호로 처음 들어온 사람은 **비밀번호 변경 → 2단계 인증 등록**을 마칠 때까지
         다른 화면을 보지 못한다. app.js 가 /api/me 의 `next` 를 보고 이 상자만 남긴다.
         ★ 감추는 것은 **편의**일 뿐 막는 것이 아니다 — 화면을 되살려 API 를 직접 불러도
           라우터 관문(public/index.php)이 403 으로 막고 감사 로그에 남긴다 (PRD S-01). -->
    <section class="panel" id="forced-gate" hidden>
      <div id="forced-password" hidden>
        <div class="panel__head">
          <h2 class="panel__title">비밀번호를 변경해 주세요</h2>
          <p class="panel__sub">임시 비밀번호로 처음 로그인했거나 관리자가 재발급했습니다. 새 비밀번호를 정한 뒤 2단계 인증을 설정하면 서비스를 쓸 수 있습니다</p>
        </div>
        <form id="forced-password-form" class="form form--grid" autocomplete="off">
          <label class="field">
            <span class="field__label">현재 비밀번호 (받은 임시 비밀번호)</span>
            <input id="forced-pw-current" type="password" maxlength="1024" required>
          </label>
          <label class="field">
            <span class="field__label">새 비밀번호 (10자 이상 · 임시 비밀번호와 달라야 합니다)</span>
            <input id="forced-pw-new" type="password" maxlength="1024" minlength="10" required>
          </label>
          <label class="field">
            <span class="field__label">새 비밀번호 확인</span>
            <input id="forced-pw-confirm" type="password" maxlength="1024" minlength="10" required>
          </label>
          <button id="forced-pw-submit" class="btn" type="submit">비밀번호 바꾸기</button>
        </form>
      </div>

      <div id="forced-mfa" hidden>
        <div class="panel__head">
          <h2 class="panel__title">2단계 인증을 설정해 주세요</h2>
          <!-- F-52 — 이 걸음에 닿는 것은 **관리자 권한이 있는 계정**뿐이다(ARCH 5.26).
               다른 역할은 여기 대신 위쪽 권장 카드를 본다. -->
          <p class="panel__sub">관리자 권한이 있는 계정은 2단계 인증이 필수입니다. 인증 앱을 등록하면 서비스를 쓸 수 있습니다.</p>
        </div>
        <!-- 「내 계정」과 **같은 파일**을 include 한다(두 벌 금지). -->
        <div id="forced-mfa-slot"><?php require __DIR__ . '/../views/mfa_enroll.php'; ?></div>
      </div>

      <p><button id="forced-logout" class="btn btn--quiet btn--small" type="button">로그아웃</button></p>
    </section>

    <!-- ─── 폴더 (18-1 — PRD F-26, ARCH 5.16) ────────────────────────────────
         좌측은 **읽을 수 있는 폴더만** 담긴 트리다. 못 읽는 폴더는 감춰진 것이 아니라
         서버 응답에 아예 없다 (PRD S-02). 이름·등급 배지는 전부 textContent 로 들어간다.
         트리는 `GET /api/folders?parent=` 로 **한 층씩** 받는다. -->
    <div class="workspace">
      <aside class="panel panel--tight foldertree" id="folder-pane" aria-label="폴더">
        <div class="foldertree__head">
          <h2 class="panel__title">폴더</h2>
          <button id="folder-new" class="btn btn--quiet btn--small" type="button" hidden>새 폴더</button>
        </div>
        <!-- 폴더 이름은 **읽을 수 있는 사람 모두**에게 보이는 메타데이터다 (SECURITY R-63). -->
        <p class="panel__sub">폴더 이름에는 민감한 말(사람 이름·금액·사건명)을 넣지 마세요. 이름은 그 폴더를 볼 수 있는 사람 모두에게 보입니다.</p>
        <nav class="crumbs" id="folder-crumbs" aria-label="폴더 경로"></nav>
        <ul class="foldertree__list" id="folder-list"></ul>
        <p id="folder-empty" class="muted" hidden>하위 폴더가 없습니다.</p>
        <div class="foldertree__actions" id="folder-actions" hidden>
          <!-- 개발 대기열 6 (Q:F-3a 라운드 A) — 지금 연 폴더를 즐겨찾기에 넣고 뺀다. 눌린 상태(aria-pressed)와 글자는 app.js 가
               `GET /api/folders/favorites` 의 답으로 정한다 — 넣을 수 있는지는 창구가 `Authz::decideRead` 로 다시 판단한다(PRD S-01). -->
          <button id="folder-fav" class="btn btn--quiet btn--small" type="button" aria-pressed="false">
            <span class="icon" data-icon="star"></span><span id="folder-fav-label">즐겨찾기에 넣기</span>
          </button>
          <!-- G-0 — 개인 폴더의 「권한」(받은 사람 목록 · 주인은 사람 추가·빼기). 개인 폴더를 열었을 때만 app.js 가 연다.
               누가 볼 수 있는지·고칠 수 있는지는 창구(`GET·POST·DELETE /api/folders/{num}/grants`)가 판단한다(PRD S-01). -->
          <button id="folder-access" class="btn btn--quiet btn--small" type="button" aria-haspopup="dialog" hidden>
            <span class="icon" data-icon="users"></span><span id="folder-access-label">권한</span>
          </button>
          <button id="folder-rename" class="btn btn--quiet btn--small" type="button">이름 바꾸기</button>
          <button id="folder-level" class="btn btn--quiet btn--small" type="button">등급 바꾸기</button>
          <button id="folder-delete" class="btn btn--quiet btn--small" type="button">지우기</button>
        </div>
        <p id="folder-msg" class="msg"></p>
        <!-- G-0 — 개인 폴더에 **보기** 권한만 있을 때(올리기·새 폴더가 막힌다) 한 줄 + 편집 권한 요청 단추. app.js 가 창구의 `can_put` 을 보고 연다. -->
        <div id="folder-put-note" class="help" hidden>
          <p>이 폴더에는 보기 권한만 있습니다 — 올리려면 주인에게 편집 권한을 요청하세요.</p>
          <button id="folder-put-request" class="btn btn--quiet btn--small" type="button">편집 권한 요청</button>
        </div>
      </aside>

      <div class="workspace__main">
    <!-- 개발 대기열 6 (Q:F-3a 라운드 A · 지시서 1.1 · 1.2) — 목록 위 보기 셋: 「파일」(폴더·검색) · 「즐겨찾기」 · 「최근」.
         새 클래스 0 — 고른 것은 outline 작은 단추(`.btn--quiet.btn--small`), 나머지는 테두리를 걷은 같은 단추(`.btn--plain`) ·
         `aria-pressed` 로 화면 읽기에도 알린다(DESIGN 2.1 · 커뮤니티 DB 보기 단추와 같은 모양). 무엇이 나오나는 서버가 정한다 —
         즐겨찾기·최근 모두 **지금 볼 수 있는 파일만** 창구가 돌려준다(PRD S-02). -->
    <div class="toolbar" id="files-views" role="group" aria-label="목록 보기">
      <button id="files-view-files" class="btn btn--quiet btn--small" type="button" aria-pressed="true" data-view="files">
        <span class="icon" data-icon="files"></span>파일
      </button>
      <button id="files-view-favorites" class="btn btn--quiet btn--small btn--plain" type="button" aria-pressed="false" data-view="favorites">
        <span class="icon" data-icon="star"></span>즐겨찾기
      </button>
      <button id="files-view-recent" class="btn btn--quiet btn--small btn--plain" type="button" aria-pressed="false" data-view="recent">
        <span class="icon" data-icon="log"></span>최근
      </button>
    </div>
    <!-- G-0 — 주소로 연 폴더·파일을 볼 수 없을 때(403 · 없는 번호도 **같은 화면**) — 이름을 보이지 않고 요청만 받는다.
         보기/편집 고르기 + 한 줄 메모(200자). 보내면 창구가 **없는 항목이어도 같은 답**(「보냈습니다」)을 준다(존재가 갈리지 않게). -->
    <section class="panel" id="access-request" aria-labelledby="access-request-title" hidden>
      <div class="panel__head">
        <h2 class="panel__title" id="access-request-title" tabindex="-1">이 항목을 볼 권한이 없습니다</h2>
        <p class="panel__sub">주인에게 권한을 요청할 수 있습니다. 주인이 허락하면 알림으로 알려 드립니다.</p>
      </div>
      <form id="access-request-form" class="form form--grid" autocomplete="off">
        <p class="field__label field--full">어떤 권한이 필요하세요?</p>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="access-want" value="view" checked> 보기 — 열고 받기
          </span>
        </label>
        <label class="field field--full" id="access-want-write-label">
          <span class="field__label">
            <input type="radio" name="access-want" value="write"> 편집 — 올리기·이름·설명 고치기까지
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">주인에게 한 줄 (선택, 200자)</span>
          <input id="access-request-message" type="text" maxlength="200" placeholder="왜 필요한지 적어 주세요">
        </label>
        <div class="form-actions field--full">
          <button id="access-request-send" class="btn" type="submit">요청 보내기</button>
        </div>
      </form>
      <p id="access-request-msg" class="msg" role="alert" aria-live="polite"></p>
      <div id="access-request-done" class="ok-note" role="status" aria-live="polite" hidden></div>
    </section>
    <!-- G-0 — 「공유받음」 보기의 폴더 묶음(즐겨찾기 폴더와 같은 줄 모양) · 받은 권한 요청 · 보낸 권한 요청. 줄은 app.js 가 만든다(textContent). -->
    <section class="panel panel--tight" id="shared-folders-wrap" aria-labelledby="shared-folders-title" hidden>
      <div class="panel__head">
        <h2 class="panel__title" id="shared-folders-title">공유받은 폴더</h2>
      </div>
      <ul class="foldertree__list" id="shared-folders"></ul>
    </section>
    <section class="panel panel--tight" id="access-in-wrap" aria-labelledby="access-in-title" hidden>
      <div class="panel__head">
        <h2 class="panel__title" id="access-in-title" tabindex="-1">받은 권한 요청</h2>
        <p class="panel__sub">내 개인 폴더·파일을 보려는 요청입니다. 허락하면 그 사람이 곧바로 볼 수 있습니다.</p>
      </div>
      <p id="access-in-msg" class="msg" role="alert" aria-live="polite"></p>
      <ul class="list" id="access-in-list" aria-label="받은 권한 요청"></ul>
      <p id="access-in-empty" class="muted" hidden>받은 요청이 없습니다.</p>
    </section>
    <section class="panel panel--tight" id="access-out-wrap" aria-labelledby="access-out-title" hidden>
      <div class="panel__head">
        <h2 class="panel__title" id="access-out-title">보낸 권한 요청</h2>
        <p class="panel__sub">주인이 결정한 요청과, 볼 수 있는 항목에 보낸 요청만 보입니다. 결정되면 알림으로도 알려 드립니다.</p>
      </div>
      <ul class="list" id="access-out-list" aria-label="보낸 권한 요청"></ul>
      <p id="access-out-empty" class="muted" hidden>보낸 요청이 없습니다.</p>
    </section>
    <!-- 「즐겨찾기」 보기의 폴더 묶음 — 이름을 누르면 그 폴더를 「파일」 보기로 연다. 줄은 app.js 가 createElement/textContent 로
         만든다(폴더 트리와 같은 줄 모양 `.foldertree__item`). 없으면 묶음째 숨는다. -->
    <section class="panel panel--tight" id="fav-folders-wrap" aria-labelledby="fav-folders-title" hidden>
      <div class="panel__head">
        <h2 class="panel__title" id="fav-folders-title">즐겨찾기 폴더</h2>
      </div>
      <ul class="foldertree__list" id="fav-folders"></ul>
    </section>
    <!-- Q:F-4 U-2a — 지운 뒤 결과 한 줄(DESIGN 4장 「성공」 = `.ok-note`). 기본 hidden 이고,
         app.js 가 확인 창을 거쳐 서버가 200 을 준 뒤에만 textContent 로 채워 연다.
         `<p>` 가 아니라 `<div>` — 문단 기본 여백이 열 간격에 더해져 옆 폴더 카드와 윗줄이 어긋났다(스크린샷 확인). -->
    <div id="files-ok" class="ok-note" role="status" aria-live="polite" hidden></div>
    <form id="search-form" class="toolbar" autocomplete="off">
      <input id="search-q" type="search" maxlength="200" placeholder="파일명 · 설명 검색">
      <select id="search-sort" aria-label="정렬 기준">
        <option value="created_at">올린 시각</option>
        <option value="name">이름</option>
        <option value="size">크기</option>
      </select>
      <select id="search-dir" aria-label="정렬 방향">
        <option value="desc">내림차순</option>
        <option value="asc">오름차순</option>
      </select>
      <button class="btn btn--quiet" type="submit">검색</button>
    </form>
    <!-- 개발 대기열 6 · Q:F-3a 라운드 B — 일괄 작업(지시서 1.3). 줄 앞 체크로 고르면 **검색 줄 자리**에 이 막대가 온다(고른 동안은
         검색·정렬을 바꾸지 않는다 — 바꾸면 고른 것이 풀리는 것과 같은 뜻이다). 동작 단추는 행 동작과 **같은 아이콘 단추**
         (`.btn--icon` + aria-label = 툴팁 글 · 순서 고치기 → 이동 → ☆ → 삭제, DESIGN 2.1)라 폰 폭에서도 한 줄이다.
         ★ 새 서버 창구 0 — 누르면 app.js 가 **한 건 작업과 같은 창구**(`PATCH·DELETE /api/files/{id}` · `PUT·DELETE …/favorite`)를
           항목마다 한 번씩, 차례로 부른다. 무엇을 할 수 있는지는 창구가 항목마다 판단하고, 거부 문장·감사도 한 건과 같다(PRD S-01).
         라운드 C — 맨 앞 「zip 으로 받기」(행 동작 순서 보기 → **받기** → 고치기 → 이동 → 삭제, DESIGN 2.1 · 받기 = 파랑)만 창구 하나
           (`POST /api/files/zip`)에 고른 id 를 **한 번에** 보낸다 — 파일 하나로 받아야 해서다. 넣을지는 서버가 파일마다 한 건 받기와
           같은 판정으로 정하고, 뺀 파일은 개수만 알린다(결과 한 줄 `#files-ok`). -->
    <div class="toolbar" id="files-bulk-bar" role="group" aria-label="고른 파일로 할 일" hidden>
      <span id="files-bulk-count" aria-live="polite"></span>
      <button id="files-bulk-zip" class="btn btn--icon" type="button" aria-label="zip 으로 받기">
        <span class="icon" data-icon="download"></span>
      </button>
      <button id="files-bulk-level" class="btn btn--icon" type="button" aria-label="등급·팀 바꾸기" aria-haspopup="dialog">
        <span class="icon" data-icon="edit"></span>
      </button>
      <button id="files-bulk-move" class="btn btn--icon" type="button" aria-label="폴더로 이동" aria-haspopup="dialog">
        <span class="icon" data-icon="files"></span>
      </button>
      <button id="files-bulk-fav" class="btn btn--icon" type="button" aria-label="즐겨찾기에 넣기">
        <span class="icon" data-icon="star"></span>
      </button>
      <button id="files-bulk-trash" class="btn btn--icon" type="button" aria-label="지우기" aria-haspopup="dialog">
        <span class="icon" data-icon="trash"></span>
      </button>
      <!-- 「선택 풀기」도 아이콘 단추(✕) — 글자 단추면 360px 에서 막대가 두 줄로 꺾였다(스크린샷 확인). Esc 와 같다. -->
      <button id="files-bulk-clear" class="btn btn--icon" type="button" aria-label="선택 풀기" aria-keyshortcuts="Escape">
        <span class="icon" data-icon="x"></span>
      </button>
    </div>
    <!-- 막대 아래 한 줄(필요할 때만) — 100개에 닿았을 때 · 앞 작업이 도는 중일 때. 막대 안에 쓰면 폰 폭에서 막대가 두 줄로 꺾인다. -->
    <p id="files-bulk-note" class="help" aria-live="polite" hidden></p>
    <!-- 라운드 C — 막대 동작(zip 으로 받기)이 거부된 까닭 한 줄. 화면 아래 `#msg` 는 긴 목록 끝이라 막대에서 누른 사람에게 안 보인다(스크린샷 확인).
         모양은 `#msg` 와 같은 `.msg`(빨강 · 비면 높이 0) — 새 클래스 0. 글자는 서버 문장 그대로(textContent), 고른 것을 모두 풀면 비운다. -->
    <p id="files-bulk-msg" class="msg" role="alert" aria-live="polite"></p>
    <!-- 일괄 진행 표 — 올리기 창의 진행 표(F-27 `.upload-status`)와 같은 칸·같은 상태 글자(대기 · 하는 중 · 완료 · 서버가 준 거부 문장).
         줄은 app.js 가 createElement + textContent 로만 만든다. 100줄까지라 표 칸은 줄 목록과 같은 높이(`.list--scroll` 480px)에서 스크롤한다.
         로그인이 끝나면(401) 멈추고 남은 줄을 그대로 둔 채 「이어서 하기」를 보인다(지시서 1.3 · QF3A-T7). -->
    <section class="panel panel--tight" id="files-bulk-panel" aria-labelledby="files-bulk-title" hidden>
      <div class="panel__head">
        <h2 class="panel__title" id="files-bulk-title" tabindex="-1"></h2>
        <p class="panel__sub" id="files-bulk-sub">한 번에 하나씩 차례로 보냅니다. 할 수 없는 파일은 그 파일만 거부되고 이유가 여기 남습니다.</p>
      </div>
      <div class="table-wrap list--scroll">
        <table class="files upload-status">
          <thead>
            <tr>
              <th scope="col">파일</th>
              <th scope="col">상태</th>
            </tr>
          </thead>
          <tbody id="files-bulk-body"></tbody>
        </table>
      </div>
      <div id="files-bulk-stop" class="notice" role="alert" hidden>
        <p class="notice__title" id="files-bulk-stop-text"></p>
        <div class="toolbar">
          <!-- 새 탭에서 로그인한다 — 이 화면(남은 목록)은 그대로 둔다. 쿠키는 같은 사이트라 두 탭이 함께 쓴다. -->
          <a id="files-bulk-login" class="btn btn--quiet btn--small" href="<?= Http::url('/login') ?>" target="_blank" rel="noopener noreferrer">로그인 화면 열기</a>
          <button id="files-bulk-resume" class="btn btn--quiet btn--small" type="button">이어서 하기</button>
        </div>
      </div>
      <div class="table-foot">
        <span id="files-bulk-summary" role="status" aria-live="polite"></span>
        <div class="form-actions">
          <button id="files-bulk-undo" class="btn btn--quiet btn--small" type="button" hidden>되돌리기</button>
          <button id="files-bulk-close" class="btn btn--quiet btn--small" type="button">닫기</button>
        </div>
      </div>
    </section>

    <section class="card card--flush">
      <div class="table-wrap">
        <table class="files">
          <thead>
            <tr>
              <!-- 라운드 B — 머리 체크는 **이 쪽(지금 보이는 줄) 전부**를 고르고 푼다. 줄 체크는 이름 칸 맨 앞(☆ 앞)에 app.js 가 넣는다 —
                   열을 새로 만들지 않아 `#files-body td:nth-child(n)` 폭 규칙(DESIGN 2.4)이 그대로다.
                   열 머리의 이름은 「이름」 글자만(aria-labelledby) — 체크의 aria-label 이 열 이름에 섞여 칸마다 읽히지 않게. -->
              <th scope="col" aria-labelledby="files-name-head"><input type="checkbox" id="files-check-all" data-bulk aria-label="이 쪽 파일 모두 고르기"><span id="files-name-head">이름</span></th>
              <th scope="col">크기</th>
              <th scope="col">등급</th>
              <th scope="col">팀</th>
              <!-- 18-1 — 검색 결과일 때만 보이는 「폴더」 경로 열. 값은 서버가 준
                   folder_path 를 app.js 가 textContent 로 넣는다 (PRD S-03). -->
              <th scope="col" id="files-folder-head" hidden>폴더</th>
              <th scope="col">올린 사람</th>
              <!-- 「최근」 보기에서는 app.js 가 글자를 「마지막으로 연 때」로 바꾼다(값은 서버가 준 last_used_at). -->
              <th scope="col" id="files-when-head">올린 시각</th>
              <th scope="col">설명</th>
              <th scope="col">작업</th>
            </tr>
          </thead>
          <tbody id="files-body"></tbody>
        </table>
      </div>
      <p id="files-empty" class="muted" hidden>표시할 파일이 없습니다.</p>
      <div class="table-foot">
        <span id="page-info" class="muted"></span>
        <div class="pager">
          <button id="page-prev" class="btn btn--quiet btn--small" type="button">이전</button>
          <button id="page-next" class="btn btn--quiet btn--small" type="button">다음</button>
        </div>
      </div>
    </section>

    
      </div>
    </div>

    <!-- ─── 파일 올리기 대화상자 (18-3 — PRD F-26·F-27, ARCH 5.16·5.17) ──────
         **올릴 위치는 고르지 않는다.** 지금 열어 둔 폴더에 올라가고, 그 경로는 서버가
         `GET /api/files` 로 내려 준 `folder.breadcrumb` 를 app.js 가 textContent 로 적는다.
         서버 계약은 그대로다 — 파일 하나 = 요청 하나(`POST /api/files`)이고, 어디에 올릴 수
         있는지는 서버가 매 요청 다시 판단한다 (PRD S-01, ARCH 5.16 불변 규칙 ①). -->
    <dialog id="upload-dialog" class="modal modal--form modal--stack modal--upload" aria-labelledby="upload-dialog-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="upload"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="upload-dialog-title">파일 올리기</h2>
          <!-- 「올릴 위치: …」 — app.js 가 서버가 준 경로로만 채운다(읽기 전용 글자). -->
          <div class="modal__meta"><span id="upload-where"></span></div>
        </div>
        <button id="upload-dialog-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p class="panel__sub">
          허용 형식 <code>pdf docx xlsx pptx hwp hwpx txt csv md png jpg jpeg gif zip</code> ·
          한 파일 최대 100MB · 한 사람이 하루에 올릴 수 있는 총량 2GB
        </p>
        <!-- 15-3 민감정보 검출 안내 (PRD F-22 「한계」, SECURITY R-58).
             검출은 **보조 장치**라는 것과 책임이 올리는 사람에게 있다는 것을 같은 자리에서 알린다. -->
        <p class="panel__sub">민감정보(주민등록번호·카드번호·급여 등)가 보이는 파일은 「팀」·「관리자」 등급으로만 올릴 수 있습니다.</p>
        <form id="upload-form" class="form form--grid" autocomplete="off">
          <!-- 18-2 일괄 업로드 (F-27, ARCH 5.17) — 여러 개를 고르거나 끌어 놓는다.
               **서버 계약은 그대로**다: 파일 하나 = 요청 하나이고, app.js 가 순서대로 보낸다. -->
          <div class="field field--full">
            <span class="field__label">파일 (여러 개 선택 가능 · 한 번에 최대 50개)</span>
            <div id="upload-drop" class="dropzone">
              <p class="dropzone__text">여기에 파일을 끌어 놓거나 아래에서 고르세요</p>
              <input id="upload-file" type="file" multiple required>
            </div>
            <p id="upload-picked" class="muted"></p>
          </div>
          <label class="field field--full">
            <span class="field__label">설명 (선택, 500자 이내 · 고른 파일 전부에 같은 설명)</span>
            <input id="upload-description" type="text" maxlength="500">
          </label>
          <label class="field">
            <span class="field__label">등급 (필수)</span>
            <!-- 선택지는 /api/me 의 upload_levels 로 app.js 가 채운다 (서버가 정한다).
                 맨 앞의 "등급을 고르세요" 자리표시(value="" disabled)도 app.js 가 넣는다 —
                 고르지 않고 올리는 일을 막기 위해서다.
                 18-1: **지금 연 폴더**보다 넓은 등급은 목록에서 빠지고 기본값도 그 폴더 등급이다
                 (서버가 400 으로 막는 것을 화면이 미리 알려 주는 편의일 뿐이다). -->
            <select id="upload-level" required></select>
          </label>
          <label class="field" id="upload-team-field" hidden>
            <span class="field__label">팀</span>
            <select id="upload-team"></select>
          </label>
        </form>

        <!-- 파일별 진행 표 (F-27). 행은 app.js 가 createElement + textContent 로만 만든다.
             거부 사유는 **서버가 준 문구 그대로** 넣는다 — 브라우저가 사유를 추측하지 않는다. -->
        <div id="upload-progress" class="table-wrap" hidden>
          <table class="files upload-status">
            <thead>
              <tr>
                <th scope="col">파일</th>
                <th scope="col">크기</th>
                <th scope="col">상태</th>
              </tr>
            </thead>
            <tbody id="upload-status-body"></tbody>
          </table>
        </div>
      </div>
      <div class="modal__foot">
        <p id="upload-msg" class="msg" role="alert" aria-live="polite"></p>
        <!-- 올리는 중에는 app.js 가 이 단추를 비활성으로 두고 ESC·바깥 누르기도 막는다
             (진행 표가 사라지면 무엇이 거부됐는지 알 수 없다). -->
        <button id="upload-dialog-close" class="btn btn--quiet" type="button">닫기</button>
        <!-- 디버깅 묶음 U2 M3 — 제출 단추는 바닥 오른쪽 끝 하나(DESIGN 2.7 「창 바닥 단추」). 폼 밖이라 form 속성으로 잇는다 -->
        <button id="upload-submit" class="btn" type="submit" form="upload-form">올리기</button>
      </div>
    </dialog>

    <!-- ─── URL 로 가져오기 대화상자 (18-3 — PRD F-14) ────────────────────────
         올리기와 같다: 저장 위치는 지금 열어 둔 폴더이고, 화면은 고르지 않는다.
         주소가 가리키는 곳이 허용된 사이트인지는 서버(src/Fetch.php)가 다시 확인한다. -->
    <dialog id="fetch-dialog" class="modal modal--form modal--stack" aria-labelledby="fetch-dialog-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="link"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="fetch-dialog-title">URL 로 가져오기</h2>
          <div class="modal__meta"><span id="fetch-where"></span></div>
        </div>
        <button id="fetch-dialog-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p class="panel__sub">허용된 사이트의 https 주소만 받습니다.</p>
        <form id="fetch-form" class="form form--grid" autocomplete="off">
          <label class="field field--full">
            <span class="field__label">주소 (https 만 · 허용된 사이트만)</span>
            <input id="fetch-url" type="url" maxlength="2048" required>
          </label>
          <label class="field field--full">
            <span class="field__label">설명 (선택, 500자 이내)</span>
            <input id="fetch-description" type="text" maxlength="500">
          </label>
          <label class="field">
            <span class="field__label">등급 (필수)</span>
            <!-- 업로드 폼과 같다 — 선택지·자리표시·기본값을 전부 app.js 가 서버 값으로 만든다 -->
            <select id="fetch-level" required></select>
          </label>
          <label class="field" id="fetch-team-field" hidden>
            <span class="field__label">팀</span>
            <select id="fetch-team"></select>
          </label>
        </form>
      </div>
      <div class="modal__foot">
        <p id="fetch-msg" class="msg" role="alert" aria-live="polite"></p>
        <button id="fetch-dialog-close" class="btn btn--quiet" type="button">닫기</button>
        <button id="fetch-submit" class="btn" type="submit" form="fetch-form">가져오기</button>
      </div>
    </dialog>

    <!-- ─── 새 폴더 / 등급 변경 모달 (18-1) ─────────────────────────────────
         등급 선택지는 app.js 가 **서버가 준 upload_levels** 와 **부모 폴더의 등급**으로만
         채운다 — 화면이 등급 목록을 알고 있으면 그것이 곧 화면에 심은 권한 판단이다.
         무엇을 만들 수 있는지는 서버가 매 요청 다시 판단한다 (PRD S-01). -->
    <dialog id="folder-dialog" class="modal" aria-labelledby="folder-dialog-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="files"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="folder-dialog-title">새 폴더</h2>
          <div class="modal__meta"><span id="folder-dialog-where"></span></div>
        </div>
        <button id="folder-dialog-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <form id="folder-form" class="form form--grid" autocomplete="off">
          <label class="field field--full" id="folder-name-field">
            <span class="field__label">이름 (1~50자 · <code>/</code> <code>\</code> 는 쓸 수 없습니다)</span>
            <input id="folder-name" type="text" maxlength="50">
          </label>
          <label class="field">
            <span class="field__label">등급</span>
            <select id="folder-level-select" required></select>
          </label>
          <label class="field" id="folder-team-field" hidden>
            <span class="field__label">팀</span>
            <select id="folder-team"></select>
          </label>
          <label class="field field--full" id="folder-cascade-field" hidden>
            <span class="field__label">
              <input id="folder-cascade" type="checkbox">
              하위 폴더·파일에도 이 등급을 적용합니다 (더 넓은 것만 함께 좁힙니다)
            </span>
          </label>
          <label class="field field--full" id="folder-reason-field" hidden>
            <span class="field__label">사유 (선택, 500자 이내)</span>
            <input id="folder-reason" type="text" maxlength="500">
          </label>
        </form>
      </div>
      <div class="modal__foot">
        <p id="folder-dialog-msg" class="msg"></p>
        <button id="folder-dialog-close" class="btn btn--quiet" type="button">닫기</button>
        <button id="folder-submit" class="btn" type="submit" form="folder-form">만들기</button>
      </div>
    </dialog>

    <!-- ─── 파일 이동 모달 (18-1) ───────────────────────────────────────────
         **읽을 수 있는 폴더만** 나온다(서버 응답에 그것만 있다). 고른 폴더로 옮길 수 있는지는
         서버가 다시 판단하고(파일 `decideEdit` + 폴더 읽기 + 불변 규칙 ①), 거부 문구는
         서버가 준 것을 그대로 보여 준다 — 화면이 사유를 추측하지 않는다. -->
    <dialog id="move-dialog" class="modal" aria-labelledby="move-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="files"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="move-title">폴더로 이동</h2>
          <div class="modal__meta"><span id="move-file"></span></div>
        </div>
        <button id="move-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <nav class="crumbs" id="move-crumbs" aria-label="폴더 경로"></nav>
        <ul class="foldertree__list" id="move-list"></ul>
        <p id="move-empty" class="muted" hidden>하위 폴더가 없습니다.</p>
      </div>
      <div class="modal__foot">
        <p id="move-msg" class="msg"></p>
        <button id="move-close" class="btn btn--quiet" type="button">닫기</button>
        <button id="move-here" class="btn" type="button">여기로 이동</button>
      </div>
    </dialog>

    <!-- ─── 고른 파일의 등급·팀 바꾸기 (개발 대기열 6 · Q:F-3a 라운드 B — 지시서 1.3) ─────────
         선택지는 **올리기 창과 같은 목록**이다 — app.js 가 서버가 준 `upload_levels` 와 팀 목록(`GET /api/teams`)으로만 채운다.
         「바꾸기」를 누르면 창을 닫고 고른 파일마다 `PATCH /api/files/{id} {access_level, team_id}` 를 한 번씩 보낸다 —
         바꿀 자격·폴더 등급·실행 파일 압축본 규칙은 그 창구가 **파일마다** 다시 판단한다(한 건 수정과 같은 거부 문장, PRD S-01). -->
    <dialog id="bulk-level-dialog" class="modal modal--form" aria-labelledby="bulk-level-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="edit"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="bulk-level-title">등급·팀 바꾸기</h2>
          <div class="modal__meta"><span id="bulk-level-count"></span></div>
        </div>
        <button id="bulk-level-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p class="panel__sub">고른 파일마다 따로 바꿉니다. 등급을 넓히는 변경(예: 팀 → 사내)은 관리자만 할 수 있습니다. 내가 바꿀 수 없는 파일 · 실행 파일이 든 압축본 · 폴더보다 넓은 등급은 그 파일만 거부되고 이유가 진행 표에 남습니다.</p>
        <form id="bulk-level-form" class="form form--grid" autocomplete="off">
          <label class="field">
            <span class="field__label">등급 (필수)</span>
            <select id="bulk-level-select" required></select>
          </label>
          <label class="field" id="bulk-team-field" hidden>
            <span class="field__label">팀</span>
            <select id="bulk-team-select"></select>
          </label>
        </form>
      </div>
      <div class="modal__foot">
        <p id="bulk-level-msg" class="msg" role="alert" aria-live="polite"></p>
        <button id="bulk-level-close" class="btn btn--quiet" type="button">닫기</button>
        <button id="bulk-level-submit" class="btn" type="submit" form="bulk-level-form">바꾸기</button>
      </div>
    </dialog>

    <!-- ─── 파일 한 건 「수정」 창 (디버깅 묶음 U H2 · 2026-09-26, DESIGN 2.7 `.modal--form`) ─────────────────
         예전엔 브라우저 기본 입력 창(window.prompt)이 세 번 떴다 — 설명 → 등급을 **영어 값으로 직접 입력** → 팀 **번호**.
         이제 창 하나: 설명 칸 + 등급 고르개 + 팀 고르개(이름). 선택지는 **서버가 준 것만**이다 — 등급은 /api/me 의
         upload_levels(올리기 창·등급 바꾸기 창과 같은 목록)에 이 파일의 지금 등급을 더한 것, 팀은 /api/teams 의 이름.
         보내는 것은 전과 같이 **바뀐 키만**(description · access_level · team_id)이고, 바꿀 자격·낮추는 변경인지·폴더 등급은
         서버(`Authz::decideEdit` · `decideLevelChange`)가 다시 판단한다 — 거부 문구는 서버가 준 것을 이 창 아래 줄에 그대로(PRD S-01).
         실행 파일이 든 압축본은 등급을 묻지 않는다(F-65 — 서버가 access_level 키를 400 으로 막는다). 값은 전부 app.js 가 textContent·value 로. -->
    <dialog id="file-edit-dialog" class="modal modal--form" aria-labelledby="file-edit-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="edit"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="file-edit-title">파일 고치기</h2>
          <div class="modal__meta"><span id="file-edit-name"></span></div>
        </div>
        <button id="file-edit-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <form id="file-edit-form" class="form form--grid" autocomplete="off">
          <label class="field field--full">
            <span class="field__label">설명 (500자 이내)</span>
            <input id="file-edit-description" type="text" maxlength="500">
          </label>
          <label class="field" id="file-edit-level-field">
            <span class="field__label">등급</span>
            <select id="file-edit-level"></select>
          </label>
          <label class="field" id="file-edit-team-field" hidden>
            <span class="field__label">팀</span>
            <select id="file-edit-team"></select>
          </label>
          <p id="file-edit-level-note" class="help field--full" hidden>실행 파일이 든 압축본은 등급을 바꿀 수 없습니다 — 받는 사람이 언제나 관리자여야 합니다. 설명은 바꿀 수 있습니다.</p>
          <p class="help field--full">등급을 넓히는 변경(예: 팀 → 사내)은 관리자만 할 수 있습니다. 바꿀 수 없으면 이유가 아래에 나옵니다.</p>
        </form>
      </div>
      <div class="modal__foot">
        <p id="file-edit-msg" class="msg" role="alert" aria-live="polite"></p>
        <button id="file-edit-close" class="btn btn--quiet" type="button">닫기</button>
        <button id="file-edit-submit" class="btn" type="submit" form="file-edit-form">저장하기</button>
      </div>
    </dialog>

    <!-- ─── 공유 링크 모달 (15-2 — PRD F-13, ARCH 5.12) ─────────────────────
         **여기에도 값이 하나도 적혀 있지 않다.** 파일명·만들어진 주소는 app.js 가
         서버 응답에서 받아 textContent 로만 넣는다 (PRD S-03).

         만들어진 주소는 **이 화면에 한 번만** 나타난다 — 서버는 토큰의 sha256 만 갖고 있어
         다시 보여 줄 수 없다(ARCH 5.12). 그래서 안내 문구를 결과 상자에 붙박이로 둔다. -->
    <dialog id="share-dialog" class="modal" aria-labelledby="share-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="link"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="share-title">공유 링크 만들기</h2>
          <div class="modal__meta"><span id="share-file"></span></div>
        </div>
        <button id="share-close-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>

      <div class="modal__body">
        <p class="muted">
          로그인하지 않은 사람도 이 주소만 있으면 파일을 받습니다.
          꼭 필요한 만큼만 열어 주고, 다 쓰면 「내 계정」의 「내 공유 링크」에서 회수하세요.
        </p>
        <form id="share-form" class="form form--grid" autocomplete="off">
          <label class="field">
            <span class="field__label">유효 시간 (1~168시간, 기본 24)</span>
            <input id="share-hours" type="number" min="1" max="168" step="1" value="24" required>
          </label>
          <label class="field">
            <span class="field__label">받을 수 있는 횟수 (1~10, 기본 1)</span>
            <input id="share-max" type="number" min="1" max="10" step="1" value="1" required>
          </label>
          <label class="field field--full">
            <span class="field__label">사유 (선택, 500자 이내)</span>
            <input id="share-reason" type="text" maxlength="500" placeholder="누구에게 왜 보내나">
          </label>
        </form>

        <div id="share-result" class="notice" hidden>
          <p><strong>이 주소는 지금 한 번만 보입니다 — 창을 닫으면 다시 볼 수 없습니다.</strong></p>
          <p class="mono" id="share-url"></p>
          <p id="share-meta" class="muted"></p>
          <button id="share-copy" class="btn btn--quiet btn--small" type="button">복사</button>
        </div>
      </div>

      <div class="modal__foot">
        <p id="share-msg" class="msg"></p>
        <button id="share-close" class="btn btn--quiet" type="button">닫기</button>
        <!-- 주소를 만든 뒤에는 app.js 가 폼과 함께 이 단추도 숨긴다(실수로 또 만들지 않게) -->
        <button id="share-submit" class="btn" type="submit" form="share-form">링크 만들기</button>
      </div>
    </dialog>

    <!-- ─── 확인 다운로드 모달 (F-65 — PRD F-65 ③, ARCH 5.29 「받기」 표) ───────
         「실행 파일이 든 압축본」(목록의 exec_content)을 받는 유일한 길이다. 보통 다운로드는
         그 파일에 대해 403 이고, 여기서 **요청마다** 비밀번호와 2단계 코드를 다시 넣는다.

         **여기에도 값이 하나도 적혀 있지 않다** — 파일명·올린 사람·올린 날짜는 app.js 가
         목록 응답에서 받아 textContent 로만 넣는다 (PRD S-03).

         비밀번호·코드는 이 폼에만 있고 어디에도 저장되지 않는다: 창이 닫히면 app.js 가
         값을 지우고, 서버는 읽은 직후 unset 한다(PRD 7.7). autocomplete="off" 와
         `name` 없는 input 이라 브라우저 저장소에도 남지 않는다. -->
    <dialog id="exec-download-dialog" class="modal modal--form" aria-labelledby="exec-dl-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="download"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="exec-dl-title">실행 파일이 든 압축본 받기</h2>
          <div class="modal__meta"><span id="exec-dl-file"></span></div>
        </div>
        <button id="exec-dl-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>

      <div class="modal__body">
        <p class="upload-exec__warn">
          이 압축본 안에는 <strong>실행 파일</strong>이 들어 있습니다. 관리자가 내용을 확인하고
          올린 파일이지만, 받은 뒤 여는 것은 여러분의 PC 에서 일어나는 일입니다 —
          보낸 사람과 용도를 알고 있을 때만 받으세요.
        </p>
        <dl class="exec-dl__meta">
          <dt>올린 사람</dt><dd id="exec-dl-uploader"></dd>
          <dt>올린 날짜</dt><dd id="exec-dl-created"></dd>
        </dl>
        <form id="exec-dl-form" class="form form--grid" autocomplete="off">
          <label class="field">
            <span class="field__label">비밀번호</span>
            <input id="exec-dl-password" type="password" maxlength="1024" autocomplete="off" required>
          </label>
          <label class="field">
            <span class="field__label">2단계 인증 코드 (6자리)</span>
            <input id="exec-dl-otp" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required>
          </label>
        </form>
      </div>

      <div class="modal__foot">
        <p id="exec-dl-msg" class="msg" role="alert" aria-live="polite"></p>
        <button id="exec-dl-close" class="btn btn--quiet" type="button">닫기</button>
        <button id="exec-dl-submit" class="btn" type="submit" form="exec-dl-form">확인하고 받기</button>
      </div>
    </dialog>

    <!-- ─── 미리보기 모달 (PRD F-18, ARCH 5.8) ──────────────────────────────
         **여기에는 값이 하나도 적혀 있지 않다.** 파일명·크기·등급·팀·올린 사람·시각·형식·
         sha256·본문은 전부 app.js 가 서버 응답(GET /api/files/{id}/preview)에서 받아
         textContent·setAttribute 로만 넣는다 — 파일 이름·내용에 어떤 문자가 있어도
         글자로만 들어간다(PRD S-03 — 문자열을 HTML 로 바꾸는 API 금지).

         본문(#preview-body)은 갈래마다 다르다: 이미지는 <img>, PDF 는 <iframe sandbox>,
         나머지는 <pre>·<table> 의 글자다. <img>·<iframe> 의 src 는 서버가 준 값이
         `/api/files/` 로 **시작할 때만** 넣는다 (ARCH 5.8 화면 규칙, R-41).

         「새 탭에서 열기」는 이미지·PDF 일 때만 보인다 — 브라우저가 sandbox iframe 안에서
         PDF 를 그리지 못하는 경우의 대안이며, 그 응답에도 같은 CSP 가 그대로 붙는다. -->
    <!-- ─── 권한 — 개인 폴더·파일의 받은 사람 (G-0, t-box PRD F-73 ②) ───────────────
         목록은 `GET …/grants` 가 준 것만(직접 준 것 + 위 개인 폴더에서 이어받은 것 — 이어받은 것은 여기서 못 뺀다).
         주인(또는 관리자 특권)에게만 「사람 추가」·보기/편집 바꾸기·빼기가 열린다(`can_manage`). 후보는 `GET /api/access/people`
         (한 글자부터 — 커뮤니티 공유 창과 같은 모양). 누르는 즉시 창구로 간다. 새 클래스 0(.field·.toolbar·.list·.badge). -->
    <dialog id="grants-dialog" class="modal modal--form" aria-labelledby="grants-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="users"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="grants-title">권한</h2>
          <div class="modal__meta"><span id="grants-item"></span></div>
        </div>
        <button id="grants-close-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="grants-lead" class="muted">「개인」 등급은 주인과 여기 적힌 사람만 봅니다. 폴더에 준 권한은 안의 폴더·파일에도 이어집니다.</p>
        <p id="grants-msg" class="msg" role="alert" aria-live="polite"></p>
        <section id="grants-add-wrap" aria-labelledby="grants-add-title" hidden>
          <p id="grants-add-title" class="field__label">사람 추가</p>
          <div id="grants-add" class="toolbar">
            <input id="grants-search" type="search" placeholder="아이디나 이름으로 찾기" maxlength="64"
                   autocomplete="off" spellcheck="false" aria-label="권한을 줄 사람 찾기">
            <select id="grants-level" aria-label="줄 권한">
              <option value="view">보기</option>
              <option value="write">편집</option>
            </select>
          </div>
          <p id="grants-hint" class="muted">아이디나 이름을 한 글자 이상 입력하세요</p>
          <ul id="grants-candidates" class="list list--scroll" aria-label="찾은 사람" hidden></ul>
        </section>
        <p id="grants-count" class="muted"></p>
        <ul id="grants-list" class="list" aria-label="권한을 받은 사람"></ul>
        <p class="muted">「편집」을 받은 사람은 올리기·이름·설명 고치기까지 합니다 — 지우기·옮기기·등급·공유 링크·권한 주기는 주인만 합니다. 손님 계정은 보기만 받습니다.</p>
      </div>
      <div class="modal__foot">
        <button id="grants-close" class="btn btn--quiet" type="button">닫기</button>
      </div>
    </dialog>

    <dialog id="preview-dialog" class="modal" aria-labelledby="preview-name">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="eye"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="preview-name"></h2>
          <div class="modal__meta">
            <span id="preview-size"></span>
            <span id="preview-level"></span>
            <span id="preview-team"></span>
            <span id="preview-uploader"></span>
          </div>
        </div>
        <button id="preview-close-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>

      <div class="preview__main">
        <div class="modal__body preview__body" id="preview-body"></div>
        <aside class="preview__info">
          <div class="preview__label">형식</div>
          <div class="preview__value" id="preview-kind"></div>
          <!-- 디버깅 묶음 U M17 (2026-09-26) — 사용자 말로: 「sha256」 → 「파일 지문 (sha256)」(약어는 한글 뜻을 먼저, DESIGN 원칙 5) -->
          <div class="preview__label">파일 지문 (sha256)</div>
          <div class="preview__value mono" id="preview-sha"></div>
          <div class="preview__label">올린 시각</div>
          <div class="preview__value" id="preview-created"></div>
          <!-- 디버깅 묶음 U M17 — 「권한 근거: 서버 Authz 가 허용한 파일입니다」(개발자 말) → 사용자 말. 뜻은 같다: 열 때마다 서버가 다시 판단한다 -->
          <div class="preview__label">볼 수 있는 까닭</div>
          <div class="preview__value">내가 볼 수 있는 등급의 파일입니다. 열 때마다 다시 확인합니다.</div>
          <p class="preview__warn">미리보기 내용은 글자 그대로만 표시됩니다. 스크립트·서식은 실행되지 않습니다.</p>
        </aside>
      </div>

      <div class="modal__foot">
        <p id="preview-msg" class="msg preview__msg"></p>
        <a id="preview-download" class="btn btn--quiet" href="<?= Http::url('/') ?>">
          <span class="icon" data-icon="download"></span>받기
        </a>
        <!-- rel="noopener noreferrer": 새 탭이 이 창(window.opener)에 손대지 못하게 한다 -->
        <a id="preview-open" class="btn btn--quiet" href="<?= Http::url('/') ?>" target="_blank" rel="noopener noreferrer" hidden>
          <span class="icon" data-icon="link"></span>새 탭에서 열기
        </a>
        <button id="preview-close" class="btn btn--quiet" type="button">닫기</button>
      </div>
    </dialog>
<!-- 개발 대기열 6 — 즐겨찾기 아이콘 둘(빈 별 = 넣기 · 찬 별 = 빼기). DESIGN 2.11 규격(24×24 · stroke 1.8, lucide star) — 이 화면만 쓴다.
     고정 문자열이고 사용자 입력이 한 글자도 없다. app.js 는 cloneNode 로만 붙인다. -->
<template id="tpl-icon-star"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 2.5 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.3l-5.8 3.1 1.1-6.5-4.7-4.6 6.5-.9z"/></svg></template>
<template id="tpl-icon-star-fill"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 2.5 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.3l-5.8 3.1 1.1-6.5-4.7-4.6 6.5-.9z"/></svg></template>
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
