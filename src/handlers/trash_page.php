<?php
declare(strict_types=1);

/**
 * src/handlers/trash_page.php — GET /trash (정책: authenticated → 여기서 범위 판정)
 *
 * 요구사항: PRD F-11 · F-20 · 5.4 각주 ¹ · 10장 화면 요구사항(「휴지통 (`/trash`)」), S-01, S-03
 *           Q:F-4 U-2b (2026-09-24) — Admin 에게는 「휴지통」(전체), 그 밖에는 「내 휴지통」
 *
 * 12단계(F-20)에서 홈 화면의 「휴지통」 패널을 이 화면으로 옮겼다. 마크업·id 는 그대로이고
 * 데이터는 예전과 같은 `GET /api/files/trash` · `POST /api/files/{id}/restore` 에서 온다.
 *
 * Q:F-4 U-2b — 라우트 정책이 데이터 창구와 함께 `authenticated` 로 바뀌었다. 화면이 보여 주는 범위는
 * `Authz::restorableFilesWhere` 가 준 **범위 코드**로만 고른다(이 파일에 역할 비교가 없다):
 *   `admin_all`  → 「휴지통」 — 모든 사람의 지운 파일. 재인증(PRD F-23)은 전과 같다: 최근 15분 안에
 *                  비밀번호를 다시 확인하지 않았으면 `/reauth?to=trash` 로 302 (관문 [3-2] 와 같은 문장·
 *                  같은 고정 표 `TBOX_REAUTH_RETURN` — 요청 값은 Location 에 닿지 않는다).
 *   그 밖        → 「내 휴지통」 — 내가 올리고 내가 지운 파일. 재인증을 묻지 않는다(본인 자료).
 * 제목·열 구성은 **편의**다. 표에 실리는 행은 데이터 창구의 SQL WHERE 가 정한다 — 화면을 고쳐도
 * 남의 행은 응답에 없다 (PRD S-02).
 *
 * 서버가 HTML 에 넣는 동적 값은 공통 셸의 사용자명·역할뿐이고(전부 htmlspecialchars),
 * 제목·설명 문구는 코드에 적힌 두 벌 중 하나다. 표의 행은 app.js 가 `textContent` 로만 그린다 (PRD S-03).
 * 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

[, , $trashScope] = Authz::restorableFilesWhere($user);
$trashAll = $trashScope === Authz::CODE_ADMIN_ALL;

// ─── [재인증] 전체 휴지통(Admin)만 — 관문 [3-2] 의 화면 가지와 같은 문장·같은 302 ───
if ($trashAll && ($user['reauth_fresh'] ?? false) !== true) {
    Audit::deny('auth.gate', 'reauth_required', [
        'target_type' => 'user',
        'target_id'   => (int) $user['id'],
        'target_name' => (string) $user['username'],
        // route 패턴은 Audit 이 TBOX_ROUTE 에서 자동으로 넣는다 (ARCH 7.5 route).
        'detail'      => ['next' => 'reauth', 'policy' => Authz::POLICY_AUTHENTICATED],
    ]);
    // 꼬리표는 고정 표에서 고른 키(`trash`)뿐이다 — 표에 없으면 꼬리표 없이 보낸다.
    $reauthKey = TBOX_REAUTH_RETURN[TBOX_ROUTE] ?? null;
    Http::redirect(Http::url(
        $reauthKey === null ? TBOX_REAUTH_PATH : TBOX_REAUTH_PATH . '?to=' . $reauthKey
    ));
}

$pageTitle = $trashAll ? '휴지통' : '내 휴지통';
$activeNav = 'trash';
$pageKey   = 'trash';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19).
   전체·내 휴지통이 같은 글을 본다(두 경우를 한 글에 적었다). */
$pageHelp  = 'trash';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
<?php if ($trashAll): ?>
        <h1 class="page-head__title">휴지통</h1>
        <p class="page-head__sub">지운 파일을 되살립니다.</p>
<?php else: ?>
        <h1 class="page-head__title">내 휴지통</h1>
        <p class="page-head__sub">내가 올리고 내가 지운 파일을 30일 안에 되살립니다.</p>
<?php endif; ?>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
      </div>
    </header>

<?php if ($trashAll): ?>
    <p class="help">보존 기간이 지난 파일은 목록에 나오지 않고 되살릴 수 없습니다.</p>
<?php else: ?>
    <p class="help">다른 사람이 지운 내 파일은 여기 나오지 않습니다 — 관리자에게 되살려 달라고 요청하세요. 보존 기간(30일)이 지난 파일은 되살릴 수 없습니다.</p>
<?php endif; ?>

    <!-- 되살린 뒤 결과 한 줄 — 기존 구성 요소 .ok-note (파일 화면의 #files-ok 와 같다). 글자는 app.js 가 textContent 로. -->
    <div id="trash-ok" class="ok-note" role="status" aria-live="polite" hidden></div>

    <section class="card card--flush">
      <div class="table-wrap">
        <table class="files">
          <thead>
            <tr>
              <th scope="col">이름</th>
              <th scope="col">크기</th>
              <th scope="col">등급</th>
<?php if ($trashAll): ?>
              <th scope="col">올린 사람</th>
              <th scope="col">지운 사람</th>
<?php endif; ?>
              <th scope="col">지운 시각</th>
              <th scope="col">작업</th>
            </tr>
          </thead>
          <tbody id="trash-body"<?= $trashAll ? '' : ' data-trash-own' ?>></tbody>
        </table>
      </div>
      <p id="trash-empty" class="muted" hidden>휴지통이 비어 있습니다.</p>
    </section>
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
