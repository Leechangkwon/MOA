<?php
declare(strict_types=1);

/**
 * src/handlers/settings_page.php — GET /settings (정책: authenticated)
 *
 * 요구사항: PRD F-20 · 10장 화면 요구사항(「설정 — 내 계정 (`/settings`)」), F-16 ③,
 *           S-03(출력 이스케이프), SECURITY R-05 · R-10 · R-33
 *
 * 12단계(F-20)에서 홈 화면의 「내 계정」 절을 이 화면으로 옮겼다. 마크업·id 는 그대로이고
 * 데이터는 예전과 같은 `POST /api/me/password` · `/api/me/mfa/*` 로 간다 —
 * **서버 규칙·권한·API 는 바뀌지 않았다.**
 *
 * ★ 대상은 언제나 **세션의 본인**이다. 화면이 "누구의" 를 고르지 않고, 요청 본문에도
 *   대상 계정이 들어가지 않는다 (PRD 8장 `/api/me/*` 행).
 * ★ 이 파일에 역할 비교를 두지 않는다. 로그인한 사람이면 누구나 자기 계정을 볼 수 있고,
 *   그 판단은 라우트 정책 `authenticated` 가 한다 (PRD S-01).
 *
 * 서버가 HTML 에 넣는 동적 값은 **사용자명·역할 두 개뿐**이며 둘 다 DB 에서 읽은 값이고
 * htmlspecialchars(ENT_QUOTES) 를 거친다. 팀 이름은 `/api/me` 의 `team_name` 을
 * app.js 가 `textContent` 로 넣는다 (PRD S-03, inner/outer HTML 계열 금지).
 *
 * 2단계 인증 등록 마크업은 홈의 강제 절차 상자와 **같은 파일**(`src/views/mfa_enroll.php`)이다.
 * 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$displayName = htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8');
$displayRole = htmlspecialchars((string) $user['role'], ENT_QUOTES, 'UTF-8');

$pageTitle = '내 계정';
$activeNav = 'settings';
$pageKey   = 'settings';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19). */
$pageHelp  = 'settings';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">내 계정</h1>
        <p class="page-head__sub">비밀번호와 2단계 인증을 관리합니다.</p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
      </div>
    </header>

    <div class="grid-2">
      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">내 정보</h2>
          <p class="panel__sub">이 화면에서 바꿀 수 없습니다.</p>
        </div>
        <div class="info-grid">
          <div class="info-item">
            <span class="field__label">아이디</span>
            <span class="info-item__value"><?= $displayName ?></span>
          </div>
          <div class="info-item">
            <span class="field__label">역할</span>
            <span class="info-item__value"><?= $displayRole ?></span>
          </div>
          <div class="info-item">
            <span class="field__label">팀</span>
            <!-- 팀 이름은 /api/me 의 team_name 을 app.js 가 textContent 로 넣는다 -->
            <span class="info-item__value" id="me-team">—</span>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">비밀번호 변경</h2>
          <p class="panel__sub">바꾸면 <strong>다른 기기의 세션이 모두 끊깁니다</strong>(지금 이 창은 유지됩니다).</p>
        </div>
        <form id="password-form" class="form" autocomplete="off">
          <label class="field">
            <span class="field__label">현재 비밀번호</span>
            <input id="pw-current" type="password" maxlength="1024" required>
          </label>
          <label class="field">
            <span class="field__label">새 비밀번호 (10자 이상)</span>
            <input id="pw-new" type="password" maxlength="1024" minlength="10" required>
          </label>
          <div class="form-actions">
            <button id="pw-submit" class="btn" type="submit">비밀번호 바꾸기</button>
          </div>
        </form>
      </section>

      <!-- 내 공유 링크 (15-2 — PRD F-13 「설정 화면 목록·회수」, ARCH 5.12).
           목록은 /api/me/shares 가 내려 주고 **내가 만든 것만** 나온다(서버가 세션으로 고정).
           토큰도 그 해시도 응답에 없다 — 만들 때 한 번 보여 준 주소를 여기서 다시 볼 수 없다.
           모든 값은 app.js 가 textContent 로 넣는다 (PRD S-03). -->
      <section class="panel span-all">
        <div class="panel__head">
          <h2 class="panel__title">내 공유 링크</h2>
          <p class="panel__sub">
            내가 만든 링크만 보입니다. <strong>주소는 다시 볼 수 없고</strong>, 필요 없어지면 회수하세요 —
            회수하면 그 주소로는 더 이상 받을 수 없습니다.
          </p>
        </div>
        <div class="table-wrap">
          <table class="files">
            <thead>
              <tr>
                <th scope="col">파일</th>
                <th scope="col">만료</th>
                <th scope="col">받은 횟수</th>
                <th scope="col">상태</th>
                <th scope="col">만든 시각</th>
                <th scope="col">사유</th>
                <th scope="col">작업</th>
              </tr>
            </thead>
            <tbody id="shares-body"></tbody>
          </table>
        </div>
        <p id="shares-empty" class="muted" hidden>만든 공유 링크가 없습니다.</p>
      </section>

      <!-- 2단계 인증 (PRD F-16 ③ "모든 역할에 필수", SECURITY R-05).
           QR 라이브러리를 넣지 않으므로 비밀과 otpauth 주소를 **글자 그대로** 보여 주고
           사용자가 인증 앱에 손으로 옮겨 적는다. 화면에 나오는 값은 전부 app.js 가
           textContent / setAttribute 로 넣는다. -->
      <section class="panel span-all">
        <div class="panel__head">
          <h2 class="panel__title">2단계 인증</h2>
          <p class="panel__sub">끄면 다음 로그인 때 다시 등록합니다.</p>
        </div>
        <div class="mfa">
          <p id="mfa-state" class="muted">2단계 인증 상태를 불러오는 중…</p>

<?php require __DIR__ . '/../views/mfa_enroll.php'; ?>

          <form id="mfa-disable-form" class="form form--grid" autocomplete="off" hidden>
            <p class="field field--full muted">끄려면 현재 비밀번호와 지금의 코드가 필요합니다.</p>
            <label class="field">
              <span class="field__label">현재 비밀번호</span>
              <input id="mfa-off-password" type="password" maxlength="1024" required>
            </label>
            <label class="field">
              <span class="field__label">2단계 인증 코드</span>
              <input id="mfa-off-code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
            </label>
            <button id="mfa-disable" class="btn btn--danger" type="submit">2단계 인증 끄기</button>
          </form>

          <p class="muted">스마트폰이 어려우면 <a href="/assets/totp-generator.html">2단계 코드 생성기</a>를 내려받아 PC 에 두고 쓸 수 있습니다.</p>
        </div>
      </section>
    </div>
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
