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
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">파일</h1>
        <p class="page-head__sub">내가 볼 수 있는 파일만 표시됩니다. 권한은 서버가 매 요청 확인합니다.</p>
      </div>
      <div class="page-head__actions">
        <!-- 「URL 로 가져오기」는 기본 hidden 이고, /api/me 의 can_fetch 가 참일 때만 app.js 가 연다.
             단추를 되살려도 POST /api/files/fetch 는 라우터 정책 file.fetch 가 다시 막는다. -->
        <button id="fetch-toggle" class="btn btn--quiet" type="button" aria-expanded="false" aria-controls="fetch-panel" hidden>
          <span class="icon" data-icon="link"></span>URL 로 가져오기
        </button>
        <button id="upload-toggle" class="btn" type="button" aria-expanded="false" aria-controls="upload-panel">
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
        <p class="muted">비밀번호는 바뀌었습니다. 마지막으로 인증 앱을 등록하면 서비스를 쓸 수 있습니다. 2단계 인증은 모든 계정에 필요합니다.</p>
        <!-- 「내 계정」과 **같은 파일**을 include 한다(두 벌 금지). -->
        <div id="forced-mfa-slot"><?php require __DIR__ . '/../views/mfa_enroll.php'; ?></div>
      </div>

      <p><button id="forced-logout" class="btn btn--quiet btn--small" type="button">로그아웃</button></p>
    </section>

    <!-- 올리기 패널 — 머리 단추로 접었다 편다(기본은 접힘). 허용 형식·용량 안내가 여기 들어간다. -->
    <section class="panel" id="upload-panel" hidden>
      <div class="panel__head">
        <h2 class="panel__title">파일 올리기</h2>
        <p class="panel__sub">
          허용 형식 <code>pdf docx xlsx pptx hwp hwpx txt csv md png jpg jpeg gif zip</code> ·
          한 파일 최대 100MB · 한 사람이 하루에 올릴 수 있는 총량 2GB
        </p>
      </div>
      <form id="upload-form" class="form form--grid" autocomplete="off">
        <label class="field">
          <span class="field__label">파일</span>
          <input id="upload-file" type="file" required>
        </label>
        <label class="field">
          <span class="field__label">설명 (선택, 500자 이내)</span>
          <input id="upload-description" type="text" maxlength="500">
        </label>
        <label class="field">
          <span class="field__label">접근 레벨 (필수)</span>
          <!-- 선택지는 /api/me 의 upload_levels 로 app.js 가 채운다 (서버가 정한다).
               맨 앞의 "접근 레벨을 선택하세요" 자리표시(value="" disabled)도 app.js 가 넣는다 —
               고르지 않고 올리는 일을 막기 위해서다. -->
          <select id="upload-level" required></select>
        </label>
        <label class="field" id="upload-team-field" hidden>
          <span class="field__label">팀</span>
          <select id="upload-team"></select>
        </label>
        <button id="upload-submit" class="btn" type="submit">올리기</button>
      </form>
    </section>

    <section class="panel" id="fetch-panel" hidden>
      <div class="panel__head">
        <h2 class="panel__title">URL 로 가져오기</h2>
        <p class="panel__sub">허용된 사이트의 https 주소만 받습니다. 주소가 가리키는 곳은 서버가 다시 확인합니다.</p>
      </div>
      <form id="fetch-form" class="form form--grid" autocomplete="off">
        <label class="field">
          <span class="field__label">주소 (https 만 · 허용된 사이트만)</span>
          <input id="fetch-url" type="url" maxlength="2048" required>
        </label>
        <label class="field">
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
    </section>

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

    <p class="muted">내가 볼 수 있는 파일만 · 다운로드는 항상 파일로 저장 · 모든 행동은 감사 로그</p>

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
