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
        <p class="page-head__sub">내 파일과 팀 파일을 올리고 내려받습니다.</p>
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

    <!-- ─── 최초 로그인 강제 절차 (PRD F-16, ARCH 4.0) ────────────────────────
         임시 비밀번호로 처음 들어온 사람은 **비밀번호 변경 → 2단계 인증 등록**을 마칠 때까지
         다른 화면을 보지 못한다. app.js 가 /api/me 의 `next` 를 보고 이 상자만 남긴다.
         ★ 감추는 것은 **편의**일 뿐 막는 것이 아니다 — 화면을 되살려 API 를 직접 불러도
           라우터 관문(public/index.php)이 403 으로 막고 감사 로그에 남긴다 (PRD S-01). -->
    <section class="gate" id="forced-gate" hidden>
      <div id="forced-password" hidden>
        <h2>비밀번호를 변경해 주세요</h2>
        <p class="muted">임시 비밀번호로 처음 로그인했거나 관리자가 재발급했습니다. 새 비밀번호를 정한 뒤 2단계 인증을 설정하면 서비스를 쓸 수 있습니다</p>
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
        <h2>2단계 인증을 설정해 주세요</h2>
        <p class="muted">비밀번호는 바뀌었습니다. 마지막으로 인증 앱을 등록하면 서비스를 쓸 수 있습니다.</p>
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
      <aside class="foldertree" id="folder-pane" aria-label="폴더">
        <div class="foldertree__head">
          <h2 class="foldertree__title">폴더</h2>
          <button id="folder-new" class="btn btn--quiet btn--small" type="button" hidden>새 폴더</button>
        </div>
        <!-- 폴더 이름은 **읽을 수 있는 사람 모두**에게 보이는 메타데이터다 (SECURITY R-63). -->
        <p class="foldertree__note">폴더 이름에는 민감한 말(사람 이름·금액·사건명)을 넣지 마세요. 이름은 그 폴더를 볼 수 있는 사람 모두에게 보입니다.</p>
        <nav class="crumbs" id="folder-crumbs" aria-label="폴더 경로"></nav>
        <ul class="foldertree__list" id="folder-list"></ul>
        <p id="folder-empty" class="muted" hidden>하위 폴더가 없습니다.</p>
        <div class="foldertree__actions" id="folder-actions" hidden>
          <button id="folder-rename" class="btn btn--quiet btn--small" type="button">이름 변경</button>
          <button id="folder-level" class="btn btn--quiet btn--small" type="button">등급 변경</button>
          <button id="folder-delete" class="btn btn--quiet btn--small" type="button">삭제</button>
        </div>
        <p id="folder-msg" class="msg"></p>
      </aside>

      <div class="workspace__main">
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

    <section class="card card--flush">
      <div class="table-wrap">
        <table class="files">
          <thead>
            <tr>
              <th scope="col">이름</th>
              <th scope="col">크기</th>
              <th scope="col">레벨</th>
              <th scope="col">팀</th>
              <!-- 18-1 — 검색 결과일 때만 보이는 「폴더」 경로 열. 값은 서버가 준
                   folder_path 를 app.js 가 textContent 로 넣는다 (PRD S-03). -->
              <th scope="col" id="files-folder-head" hidden>폴더</th>
              <th scope="col">올린 사람</th>
              <th scope="col">올린 시각</th>
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
        <button id="upload-dialog-x" class="btn btn--icon" type="button" title="닫기" aria-label="닫기">
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
        <p class="panel__sub">민감정보(주민등록번호·카드번호·급여 등)가 보이는 파일은 <code>team</code>·<code>admin-only</code> 로만 올릴 수 있습니다.</p>
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
            <span class="field__label">접근 레벨 (필수)</span>
            <!-- 선택지는 /api/me 의 upload_levels 로 app.js 가 채운다 (서버가 정한다).
                 맨 앞의 "접근 레벨을 선택하세요" 자리표시(value="" disabled)도 app.js 가 넣는다 —
                 고르지 않고 올리는 일을 막기 위해서다.
                 18-1: **지금 연 폴더**보다 넓은 등급은 목록에서 빠지고 기본값도 그 폴더 등급이다
                 (서버가 400 으로 막는 것을 화면이 미리 알려 주는 편의일 뿐이다). -->
            <select id="upload-level" required></select>
          </label>
          <label class="field" id="upload-team-field" hidden>
            <span class="field__label">팀</span>
            <select id="upload-team"></select>
          </label>
          <button id="upload-submit" class="btn" type="submit">올리기</button>
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
        <button id="fetch-dialog-x" class="btn btn--icon" type="button" title="닫기" aria-label="닫기">
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
            <span class="field__label">접근 레벨 (필수)</span>
            <!-- 업로드 폼과 같다 — 선택지·자리표시·기본값을 전부 app.js 가 서버 값으로 만든다 -->
            <select id="fetch-level" required></select>
          </label>
          <label class="field" id="fetch-team-field" hidden>
            <span class="field__label">팀</span>
            <select id="fetch-team"></select>
          </label>
          <button id="fetch-submit" class="btn" type="submit">가져오기</button>
        </form>
      </div>
      <div class="modal__foot">
        <p id="fetch-msg" class="msg" role="alert" aria-live="polite"></p>
        <button id="fetch-dialog-close" class="btn btn--quiet" type="button">닫기</button>
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
        <button id="folder-dialog-x" class="btn btn--icon" type="button" title="닫기" aria-label="닫기">
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
            <span class="field__label">접근 등급</span>
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
          <button id="folder-submit" class="btn" type="submit">만들기</button>
        </form>
      </div>
      <div class="modal__foot">
        <p id="folder-dialog-msg" class="msg"></p>
        <button id="folder-dialog-close" class="btn" type="button">닫기</button>
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
        <button id="move-x" class="btn btn--icon" type="button" title="닫기" aria-label="닫기">
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
        <button id="move-here" class="btn" type="button">여기로 이동</button>
        <button id="move-close" class="btn btn--quiet" type="button">닫기</button>
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
        <button id="share-close-x" class="btn btn--icon" type="button" title="닫기" aria-label="닫기">
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
          <button id="share-submit" class="btn" type="submit">링크 만들기</button>
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
        <button id="share-close" class="btn" type="button">닫기</button>
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
        <button id="preview-close-x" class="btn btn--icon" type="button" title="닫기" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>

      <div class="preview__main">
        <div class="modal__body preview__body" id="preview-body"></div>
        <aside class="preview__info">
          <div class="preview__label">형식</div>
          <div class="preview__value" id="preview-kind"></div>
          <div class="preview__label">sha256</div>
          <div class="preview__value mono" id="preview-sha"></div>
          <div class="preview__label">올린 시각</div>
          <div class="preview__value" id="preview-created"></div>
          <div class="preview__label">권한 근거</div>
          <div class="preview__value">서버 Authz 가 허용한 파일입니다</div>
          <p class="preview__warn">미리보기 내용은 글자 그대로만 표시됩니다. 스크립트·서식은 실행되지 않습니다.</p>
        </aside>
      </div>

      <div class="modal__foot">
        <p id="preview-msg" class="msg preview__msg"></p>
        <a id="preview-download" class="btn btn--quiet" href="/">
          <span class="icon" data-icon="download"></span>다운로드
        </a>
        <!-- rel="noopener noreferrer": 새 탭이 이 창(window.opener)에 손대지 못하게 한다 -->
        <a id="preview-open" class="btn btn--quiet" href="/" target="_blank" rel="noopener noreferrer" hidden>
          <span class="icon" data-icon="link"></span>새 탭에서 열기
        </a>
        <button id="preview-close" class="btn" type="button">닫기</button>
      </div>
    </dialog>
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
