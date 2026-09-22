<?php
declare(strict_types=1);

/**
 * src/handlers/admin_fetch_hosts_page.php — GET /admin/fetch-hosts
 *                                            (정책: role:Admin + 재인증, PRD F-43)
 *
 * 요구사항: PRD F-43 · 8장 `/admin/fetch-hosts` 행 · 5장 「허용 사이트 관리 = Admin」 ·
 *           10장 · 12.2 T-56, ARCH 5.22, SECURITY R-76
 *
 * 「URL 로 가져오기」가 갈 수 있는 곳을 화면에서 넣고 빼는 자리다. `.env` 에 적혀 있던 목록을
 * 표로 옮기면서 생긴 화면이며, 조건은 권한 매트릭스 편집(F-25)과 **같은 급**이다 —
 * Admin · 재인증 15분 창 · 사유 · 요청마다 인증 앱 코드 · 감사 로그 · 경보.
 *
 * 화면 구성
 *   ① 표 — 호스트 · 메모 · 허용(체크상자) · 넣은 사람 · 마지막 변경
 *   ② 「사이트 추가」 모달 (D-10) — 화면 머리 단추가 연다. 호스트·메모를 받아 표에 임시 행으로
 *      얹히고 저장할 때 함께 나간다. 만들기·추가 폼은 화면에 펼쳐 두지 않는다 (DESIGN 2.7)
 *   ③ 「변경 미리보기」 — 무엇이 무엇으로 바뀌는지. 잘못 누른 체크 하나가 곧 서버가 나갈 수
 *      있는 곳의 변경이므로, 보내기 전에 사람이 한 번 더 읽게 한다
 *   ④ 「저장」 → 사유·인증 앱 코드 모달 (권한 매트릭스와 같은 모양)
 *
 * ★ **지우는 단추가 없다.** 그만 쓰려면 「허용」을 끈다 — 허용에서 뺀 호스트도 "언제부터
 *   언제까지 열려 있었나" 가 조사에 필요하고, 앱 DB 계정에 이 표의 DELETE 권한 자체가 없다.
 *
 * ★ 화면이 막지 않는다. 체크상자를 개발자도구로 고쳐 보내도, 코드를 비워 보내도, 와일드카드를
 *   적어 보내도 서버가 400 으로 막고 `fetch_host.change deny …` 로 남긴다 (PRD S-01, T-56).
 *   실패 문구는 **서버가 준 문구 그대로** 보여 준다.
 *
 * ★ HTML 에 들어가는 동적 값은 공통 셸의 다섯 개뿐이다 (shell_top.php). 표의 값·규칙 설명·
 *   미리보기는 app.js 가 API 응답을 **textContent 로만** 그린다 (PRD S-03).
 *
 * ★ 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self', style-src 'self').
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = 'URL 가져오기 허용 사이트';
$activeNav = 'admin';
$pageKey   = 'admin';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19). */
$pageHelp  = 'fetch-hosts';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">URL 가져오기 허용 사이트</h1>
        <p class="page-head__sub">「URL 로 가져오기」가 접속할 수 있는 사이트를 정합니다. <a href="<?= Http::url('/admin') ?>">관리자 페이지로 돌아가기</a></p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
        <button id="fetch-hosts-add-open" class="btn" type="button"
                aria-haspopup="dialog" aria-controls="fetch-hosts-add-dialog">
          <span class="icon" data-icon="plus"></span>사이트 추가
        </button>
      </div>
    </header>

    <p class="warn">여기에 넣은 사이트로는 서버가 직접 접속합니다. 꼭 필요한 곳만, 정확한 이름으로 넣으세요. 저장에는 사유와 인증 앱 코드가 필요합니다.</p>

    <section class="card card--pad perm-edit">
      <div class="table-wrap">
        <table class="files perm-table">
          <thead>
            <tr>
              <th scope="col">호스트</th>
              <th scope="col">메모</th>
              <th scope="col">허용</th>
              <th scope="col">넣은 사람</th>
              <th scope="col">마지막 변경</th>
            </tr>
          </thead>
          <tbody id="fetch-hosts-table"></tbody>
        </table>
      </div>
      <p id="fetch-hosts-empty" class="muted" hidden>등록된 사이트가 없습니다. 오른쪽 위 「사이트 추가」 로 넣으세요. 목록이 비어 있으면 「URL 로 가져오기」 단추 자체가 보이지 않습니다.</p>

      <div class="perm-preview">
        <p class="field__label">변경 미리보기</p>
        <ul id="fetch-hosts-preview" class="list list--scroll"></ul>
        <p id="fetch-hosts-preview-empty" class="muted">바꾼 것이 없습니다. 「허용」을 켜고 끄거나 사이트를 추가하면 여기에 나옵니다.</p>
      </div>

      <div class="perm-form__actions">
        <button id="fetch-hosts-save" class="btn" type="button">저장</button>
        <button id="fetch-hosts-reset" class="btn btn--quiet" type="button">되돌리기</button>
      </div>
      <p id="fetch-hosts-msg" class="msg" role="alert" aria-live="polite"></p>
    </section>

    <!-- ─── 「사이트 추가」 모달 (D-10, DESIGN 2.7 · 2.1) ──────────────────────
         만들기·추가 폼은 화면에 펼쳐 두지 않는다 — 화면 머리의 「사이트 추가」 단추가 이 모달을
         열고, 폼과 규칙 설명(#fetch-hosts-rules)이 그 안에 있다. 화면에는 표 · 미리보기 · 저장
         줄만 남는다. 여기서 「목록에 추가」 를 눌러도 **요청은 나가지 않는다** — 표에 임시 행을
         얹고 미리보기에 한 줄을 더할 뿐이고, 서버로는 「저장」 의 사유·코드 모달에서만 나간다.
         제출 단추가 폼 밖(.modal__foot)에 있으므로 form="fetch-hosts-add" 로 잇는다. -->
    <dialog id="fetch-hosts-add-dialog" class="modal modal--form" aria-labelledby="fetch-hosts-add-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="plus"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="fetch-hosts-add-title">사이트 추가</h2>
          <div class="modal__meta"><span>정확한 호스트 이름만, https 로만 나갑니다</span></div>
        </div>
        <button id="fetch-hosts-add-dialog-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <form id="fetch-hosts-add" class="form form--grid" autocomplete="off">
          <label class="field">
            <span class="field__label">호스트 (예: <code>drive.google.com</code>)</span>
            <input id="fetch-hosts-new" type="text" maxlength="253" placeholder="example.com">
          </label>
          <label class="field">
            <span class="field__label">메모 (선택, 200자 이내)</span>
            <input id="fetch-hosts-new-note" type="text" maxlength="200">
          </label>
        </form>
        <ul id="fetch-hosts-rules" class="notice__list muted"></ul>
      </div>
      <div class="modal__foot">
        <p id="fetch-hosts-add-msg" class="msg" role="alert" aria-live="polite"></p>
        <button id="fetch-hosts-add-dialog-close" class="btn btn--quiet" type="button">닫기</button>
        <button id="fetch-hosts-add-btn" class="btn" type="submit" form="fetch-hosts-add">목록에 추가</button>
      </div>
    </dialog>

    <!-- ─── 사유 · 인증 앱 코드 모달 (권한 매트릭스와 같은 모양) ────────────────
         여기서 받은 값은 POST /api/fetch-hosts 로 그대로 간다. 코드는 **1회성**이라
         한 번 쓴 코드로 다시 저장하면 서버가 거부한다 (SECURITY R-45). -->
    <dialog id="fetch-hosts-dialog" class="modal modal--form" aria-labelledby="fetch-hosts-dialog-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="lock"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="fetch-hosts-dialog-title">허용 사이트 변경 확인</h2>
          <div class="modal__meta"><span id="fetch-hosts-dialog-count"></span></div>
        </div>
        <button id="fetch-hosts-dialog-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <ul id="fetch-hosts-dialog-list" class="list list--scroll"></ul>
        <form id="fetch-hosts-form" class="form form--grid" autocomplete="off">
          <label class="field field--full">
            <span class="field__label">사유 (필수 · 500자)</span>
            <input id="fetch-hosts-reason" type="text" maxlength="500" required>
          </label>
          <label class="field">
            <span class="field__label">인증 앱의 6자리 코드</span>
            <input id="fetch-hosts-otp" type="text" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]{6}" maxlength="6" required>
          </label>
          <button id="fetch-hosts-submit" class="btn" type="submit">적용</button>
        </form>
      </div>
      <div class="modal__foot">
        <p id="fetch-hosts-dialog-msg" class="msg" role="alert" aria-live="polite"></p>
        <button id="fetch-hosts-dialog-close" class="btn btn--quiet" type="button">닫기</button>
      </div>
    </dialog>
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
