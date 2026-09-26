<?php
declare(strict_types=1);

/**
 * src/handlers/admin_permissions_page.php — GET /admin/permissions
 *                                           (정책: role:Admin + 재인증, PRD F-25)
 *
 * 요구사항: PRD F-25 · 8장 `/admin/permissions` 행 · 10장 「권한 매트릭스 편집」 ·
 *           12.2 T-39 · 14장 Q-12, ARCH 5.15, SECURITY R-60
 *
 * 역할 × 접근 등급 4×4 표를 **화면에서 바꾸는** 자리다. 사용자 결정 Q-12 로 열렸고,
 * "보안 확보" 는 다섯 조건(F-25 ①~⑤)으로 구체화돼 있다 — 셋은 라우터가(Admin·재인증·CSRF),
 * 둘은 핸들러가(코드·사유·불변 규칙·별도 DB 롤) 지킨다.
 *
 * 화면 구성
 *   ① 4×4 표 두 벌이 아니라 **한 표 안에 칸마다 「읽기」·「올리기」 체크상자** — 미리보기와
 *      대조하기 쉽게 한 줄에 모은다.
 *   ② 잠긴 칸(관리자 행·손님의 관리자 등급)은 `disabled` 이고 이유가 툴팁으로 붙는다.
 *      이유 문구도 **서버가 내려 준 것**(`locked_reason`)을 쓴다 — 화면이 규칙을 지어내지 않는다.
 *   ③ 「변경 미리보기」 — 무엇이 무엇으로 바뀌는지 목록으로 보여 준다. 잘못 누른 토글 하나가
 *      곧 보안 정책 변경이므로, 보내기 전에 사람이 한 번 더 읽게 한다 (SECURITY R-60 ⑤).
 *   ④ 「적용」 단추 → 사유(필수)·2단계 인증 코드(요청마다) **모달** (D-10, DESIGN 2.7 —
 *      허용 사이트 화면과 같은 꼴이다. 폼을 화면에 펼쳐 두지 않는다).
 *
 * ★ 화면이 막지 않는다. 잠긴 칸을 개발자도구로 풀어 보내도, 코드를 비워 보내도, 사유를
 *   지워 보내도 서버가 400 으로 막고 `permission.change deny …` 로 남긴다 (PRD S-01, T-39).
 *   실패 문구는 **서버가 준 문구 그대로** 보여 준다.
 *
 * ★ HTML 에 들어가는 동적 값은 공통 셸의 다섯 개뿐이다 (shell_top.php). 표의 값·이유·미리보기는
 *   app.js 가 API 응답을 **textContent 로만** 그린다 (PRD S-03).
 *
 * ★ 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self').
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '권한 매트릭스 편집';
$activeNav = 'admin';
$pageKey   = 'admin';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19). */
$pageHelp  = 'permissions';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">권한 매트릭스 편집</h1>
        <p class="page-head__sub">역할 × 등급 표를 바꿉니다. <a href="<?= Http::url('/admin') ?>">관리자 페이지로 돌아가기</a></p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
      </div>
    </header>

    <p class="warn">체크 하나로 Guest 가 사내 문서를 볼 수 있게 됩니다. 저장에는 사유와 인증 앱 코드가 필요하고, 이미 로그인해 있는 사람에게도 바로 적용됩니다.</p>

    <section class="card card--pad perm-edit">
      <div class="table-wrap">
        <table class="files perm-table">
          <thead>
            <tr>
              <th scope="col">역할</th>
              <th scope="col">공개 (public)</th>
              <th scope="col">사내 (internal)</th>
              <th scope="col">팀 (team)*</th>
              <th scope="col">관리자 (admin-only)</th>
            </tr>
          </thead>
          <tbody id="perm-table"></tbody>
        </table>
      </div>
      <p class="muted">* 팀 등급의 「읽기」는 <strong>같은 팀일 때</strong>라는 뜻입니다 (Admin 은 모든 팀).</p>

      <ul id="perm-rules" class="notice__list muted"></ul>

      <div class="perm-preview">
        <p class="field__label">변경 미리보기</p>
        <ul id="perm-preview" class="list list--scroll"></ul>
        <p id="perm-preview-empty" class="muted">바꾼 칸이 없습니다. 표에서 체크를 바꾸면 여기에 나옵니다.</p>
      </div>

      <!-- D-10 (DESIGN 2.7) — 사유·코드 폼은 화면에 펼쳐 두지 않는다. 여기에는 단추 줄만
           남고, 「적용」 이 아래 모달을 연다. 바뀐 칸이 0 이면 `disabled` 다(예전에
           `#perm-submit` 에 걸려 있던 바로 그 조건을 app.js 가 이 단추에도 건다). -->
      <div class="perm-form__actions">
        <button id="perm-apply-open" class="btn" type="button"
                aria-haspopup="dialog" aria-controls="perm-dialog" disabled>적용</button>
        <button id="perm-reset" class="btn btn--quiet" type="button">되돌리기</button>
      </div>
    </section>

    <!-- ─── 사유 · 인증 앱 코드 모달 (D-10 — 허용 사이트 화면과 같은 꼴) ──────────
         폼 id·칸·요청 본문(`PATCH /api/admin/permissions`)은 그대로고 자리만 옮겼다.
         코드는 **1회성**이라 한 번 쓴 코드로 다시 적용하면 서버가 거부한다 (F-25 ④).
         제출 단추가 폼 밖(.modal__foot)에 있으므로 form="perm-form" 로 잇는다. -->
    <dialog id="perm-dialog" class="modal modal--form" aria-labelledby="perm-dialog-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="lock"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="perm-dialog-title">권한 매트릭스 변경 적용</h2>
          <div class="modal__meta"><span>사유와 인증 앱 코드가 필요합니다. 바로 적용됩니다</span></div>
        </div>
        <button id="perm-dialog-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <!-- 미리보기에서 센 개수를 app.js 가 textContent 로 넣는다 (PRD S-03) -->
        <p id="perm-dialog-count" class="muted"></p>
        <form id="perm-form" class="form perm-form" autocomplete="off">
          <label class="field">
            <span class="field__label">사유 (필수 · 500자)</span>
            <input id="perm-reason" type="text" maxlength="500" required>
          </label>
          <label class="field">
            <span class="field__label">인증 앱의 6자리 코드</span>
            <input id="perm-otp" type="text" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]{6}" maxlength="6" required>
          </label>
        </form>
      </div>
      <div class="modal__foot">
        <p id="perm-msg" class="msg" role="alert" aria-live="polite"></p>
        <button id="perm-dialog-close" class="btn btn--quiet" type="button">닫기</button>
        <button id="perm-submit" class="btn" type="submit" form="perm-form">적용</button>
      </div>
    </dialog>
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
