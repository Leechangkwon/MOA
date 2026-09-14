<?php
declare(strict_types=1);

/**
 * src/handlers/org_page.php — GET /org (정책: org.manage → Admin 만, PRD 5.4)
 *
 * 요구사항: PRD F-19 · 10장 화면 요구사항(「조직·권한」 행) · 14장 Q-12 · S-03(출력 이스케이프) ·
 *           S-07("관리자 행동 사유"), ARCH 5.9, SECURITY R-43 · R-44
 *
 * 화면은 세 덩이다.
 *   ① 좌측 — 팀 카드 목록(이름·활성 배지·구성원 수·Manager 수·파일 수) + 「팀 만들기」
 *   ② 우측 위 — 고른 팀의 상세(만든 날·마지막 변경) + 이름 변경·구성원 배정·비활성화 +
 *      구성원 표(아이디·역할·상태·「다른 팀으로…」)
 *   ③ 우측 아래 — 권한 매트릭스 2표(읽기·업로드). **읽기 전용**이며 편집 UI 가 없다.
 *
 * ★ 이 화면이 HTML 에 넣는 동적 값은 공통 셸의 **사용자명·역할·제목·활성 메뉴·페이지 키**뿐이고
 *   전부 htmlspecialchars(ENT_QUOTES) 를 거친다 (shell_top.php). 팀 이름·구성원 아이디·매트릭스
 *   값은 서버가 HTML 로 만들지 않는다 — app.js 가 API 응답을 **textContent 로만** 그린다
 *   (팀 이름도 사람이 적은 자유 텍스트다, PRD S-03).
 *
 * ★ 이 파일에 역할 비교를 두지 않는다. "Admin 만" 은 라우트 정책 `org.manage` 가 판단하고
 *   (Authz::canManageOrg), 화면은 여기까지 왔다는 사실 자체가 통과의 증거다 (PRD S-01).
 *   `/org` 화면과 `/api/org/*` 데이터는 같은 정책을 쓴다 — 화면만 숨기면 우회 경로가 된다.
 *
 * ★ 매트릭스에 편집 단추가 없는 것은 꾸밈이 아니라 설계다. 편집을 허용하면 앱 DB 계정에
 *   그 표의 변경 권한이 필요해지고, 그것이 곧 권한 상승 경로가 된다 (ARCH 6.2-1,
 *   SECURITY R-43, PRD Q-12). 표 아래 안내 문구가 그 이유를 화면에서도 밝힌다.
 *
 * 모든 변경(생성·이름 변경·활성/비활성·구성원 배정)에는 **사유 칸이 필수**이고, 비워 두면
 * 서버도 400 으로 막는다 (PRD S-07, SECURITY R-20). 구성원 배정은 새 API 가 아니라 기존
 * `PATCH /api/users/{uid}` 를 부른다 — 규칙이 두 곳으로 갈라지지 않게 (ARCH 5.9).
 *
 * 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self').
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '조직·권한';
$activeNav = 'org';
$pageKey   = 'org';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">조직·권한</h1>
        <p class="page-head__sub">팀을 만들고 구성원을 배정합니다. 권한 매트릭스는 확인만 할 수 있습니다.</p>
      </div>
    </header>

  <p class="help">모든 변경에 사유가 필요합니다. 팀을 바꾸면 그 사람은 다시 로그인해야 합니다.</p>

  <div class="org-layout">
    <section class="panel org-teams">
      <div class="org-teams__head">
        <h2 class="panel__title">팀</h2>
        <button id="org-team-new" class="btn" type="button">
          <span class="icon" data-icon="plus"></span>팀 만들기
        </button>
      </div>
      <div id="org-teams" class="org-cards"></div>
      <p id="org-teams-empty" class="muted" hidden>표시할 팀이 없습니다.</p>
      <p class="muted">
        팀은 지우지 않고 비활성화합니다. 활성 구성원이 0명일 때만 됩니다.
      </p>
    </section>

    <section class="org-side">
      <div class="card card--pad org-detail">
        <p id="org-detail-none" class="muted">왼쪽에서 팀을 고르면 상세와 구성원이 나옵니다.</p>

        <div id="org-detail" hidden>
          <div class="org-detail__head">
            <div class="org-detail__text">
              <strong id="org-detail-name" class="org-detail__name"></strong>
              <span id="org-detail-badge" class="badge"></span>
              <span id="org-detail-meta" class="muted"></span>
            </div>
            <div class="org-detail__actions">
              <button id="org-rename" class="btn btn--quiet btn--small" type="button">
                <span class="icon" data-icon="edit"></span>이름 변경
              </button>
              <button id="org-assign" class="btn btn--quiet btn--small" type="button">
                <span class="icon" data-icon="plus"></span>구성원 배정
              </button>
              <button id="org-toggle" class="btn btn--danger btn--small" type="button">비활성화</button>
            </div>
          </div>

          <div class="table-wrap">
            <table class="files">
              <thead>
                <tr>
                  <th scope="col">아이디</th>
                  <th scope="col">역할</th>
                  <th scope="col">상태</th>
                  <th scope="col">배정</th>
                </tr>
              </thead>
              <tbody id="org-members"></tbody>
            </table>
          </div>
          <p id="org-members-empty" class="muted" hidden>이 팀에 배정된 계정이 없습니다.</p>

          
        </div>
      </div>

      <div class="card card--pad org-matrix">
        <div class="org-matrix__head">
          <div class="org-detail__text">
            <strong class="org-detail__name">권한 매트릭스</strong>
            <span class="muted">역할 × 접근 등급</span>
          </div>
          <span class="badge badge--off">
            <span class="icon" data-icon="lock"></span>읽기 전용
          </span>
        </div>

        <div class="matrix-grid">
          <div class="matrix-block">
            <p class="field__label">읽기 (목록 + 다운로드 + 미리보기)</p>
            <div class="table-wrap">
              <table class="files matrix">
                <thead>
                  <tr>
                    <th scope="col">역할</th>
                    <th scope="col">공개</th>
                    <th scope="col">사내</th>
                    <th scope="col">팀*</th>
                    <th scope="col">관리자</th>
                  </tr>
                </thead>
                <tbody id="org-matrix-read"></tbody>
              </table>
            </div>
          </div>
          <div class="matrix-block">
            <p class="field__label">업로드 — 지정할 수 있는 레벨</p>
            <div class="table-wrap">
              <table class="files matrix">
                <thead>
                  <tr>
                    <th scope="col">역할</th>
                    <th scope="col">공개</th>
                    <th scope="col">사내</th>
                    <th scope="col">팀*</th>
                    <th scope="col">관리자</th>
                  </tr>
                </thead>
                <tbody id="org-matrix-upload"></tbody>
              </table>
            </div>
          </div>
        </div>

        <ul id="org-matrix-rules" class="notice__list muted"></ul>

        <p class="muted">* 팀 등급은 같은 팀일 때만 적용됩니다 (Admin 은 모든 팀). 바꾸려면 <a href="/admin/permissions">관리자 페이지 → 권한 매트릭스 편집</a>.</p>
      </div>
    </section>
  </div>

  <!-- ─── 대화상자 세 개 ──────────────────────────────────────────
       전부 <dialog class="modal"> 이며 12-2 미리보기 모달과 같은 뼈대를 쓴다.
       값은 app.js 가 textContent·value 로만 넣고, 인라인 style·on* 속성이 없다. -->
  <dialog id="team-create-dialog" class="modal modal--form" aria-labelledby="tc-title">
    <form id="team-create-form" class="form" autocomplete="off">
      <div class="modal__head">
        <h2 class="modal__title" id="tc-title">팀 만들기</h2>
      </div>
      <div class="modal__body">
        <label class="field">
          <span class="field__label">팀 이름 (1~50자)</span>
          <input id="tc-name" type="text" maxlength="50" required>
        </label>
        <label class="field">
          <span class="field__label">사유 (필수 · 500자)</span>
          <input id="tc-reason" type="text" maxlength="500" required>
        </label>
        <p id="tc-msg" class="msg" role="alert" aria-live="polite"></p>
      </div>
      <div class="modal__foot">
        <button id="tc-cancel" class="btn btn--quiet" type="button">취소</button>
        <button id="tc-submit" class="btn" type="submit">만들기</button>
      </div>
    </form>
  </dialog>

  <dialog id="team-rename-dialog" class="modal modal--form" aria-labelledby="tr-title">
    <form id="team-rename-form" class="form" autocomplete="off">
      <div class="modal__head">
        <h2 class="modal__title" id="tr-title">팀 이름 변경</h2>
      </div>
      <div class="modal__body">
        <p id="tr-target" class="muted"></p>
        <label class="field">
          <span class="field__label">새 이름 (1~50자)</span>
          <input id="tr-name" type="text" maxlength="50" required>
        </label>
        <label class="field">
          <span class="field__label">사유 (필수 · 500자)</span>
          <input id="tr-reason" type="text" maxlength="500" required>
        </label>
        <p id="tr-msg" class="msg" role="alert" aria-live="polite"></p>
      </div>
      <div class="modal__foot">
        <button id="tr-cancel" class="btn btn--quiet" type="button">취소</button>
        <button id="tr-submit" class="btn" type="submit">바꾸기</button>
      </div>
    </form>
  </dialog>

  <!-- 구성원 배정 — 새 API 가 아니라 기존 PATCH /api/users/{uid} 를 부른다 (ARCH 5.9).
       「구성원 배정」은 계정을 고르고, 구성원 표의 「다른 팀으로…」는 팀을 고른다. -->
  <dialog id="team-assign-dialog" class="modal modal--form" aria-labelledby="ta-title">
    <form id="team-assign-form" class="form" autocomplete="off">
      <div class="modal__head">
        <h2 class="modal__title" id="ta-title">구성원 배정</h2>
      </div>
      <div class="modal__body">
        <label class="field">
          <span class="field__label">계정</span>
          <select id="ta-user" required></select>
        </label>
        <label class="field">
          <span class="field__label">배정할 팀 (활성 팀만)</span>
          <select id="ta-team" required></select>
        </label>
        <label class="field">
          <span class="field__label">사유 (필수 · 500자)</span>
          <input id="ta-reason" type="text" maxlength="500" required>
        </label>
        <p class="muted">팀을 바꾸면 그 사람은 다시 로그인해야 합니다.</p>
        <p id="ta-msg" class="msg" role="alert" aria-live="polite"></p>
      </div>
      <div class="modal__foot">
        <button id="ta-cancel" class="btn btn--quiet" type="button">취소</button>
        <button id="ta-submit" class="btn" type="submit">배정</button>
      </div>
    </form>
  </dialog>

  <dialog id="team-active-dialog" class="modal modal--form" aria-labelledby="td-title">
    <form id="team-active-form" class="form" autocomplete="off">
      <div class="modal__head">
        <h2 class="modal__title" id="td-title">팀 비활성화</h2>
      </div>
      <div class="modal__body">
        <p id="td-note" class="muted"></p>
        <label class="field">
          <span class="field__label">사유 (필수 · 500자)</span>
          <input id="td-reason" type="text" maxlength="500" required>
        </label>
        <p id="td-msg" class="msg" role="alert" aria-live="polite"></p>
      </div>
      <div class="modal__foot">
        <button id="td-cancel" class="btn btn--quiet" type="button">취소</button>
        <button id="td-submit" class="btn" type="submit">비활성화</button>
      </div>
    </form>
  </dialog>

<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
