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
// 역할은 한글로 보인다(DESIGN 5장 · 디버깅 묶음 U M7 — 셸 `shell_top.php` 와 같은 표, 값은 그대로 · 원래 값은 title)
$settingsRoleNames = ['Admin' => '관리자', 'Manager' => '팀장', 'Employee' => '직원', 'Guest' => '손님'];
$settingsRoleRaw   = (string) $user['role'];
$displayRole      = htmlspecialchars($settingsRoleNames[$settingsRoleRaw] ?? $settingsRoleRaw, ENT_QUOTES, 'UTF-8');
$displayRoleTitle = htmlspecialchars($settingsRoleRaw, ENT_QUOTES, 'UTF-8');
/* 5-A″ — 이름은 **보기만**(바꾸는 창구는 Admin 의 계정 관리뿐). 없으면 「—」와 안내 한 줄. */
$myDisplayName = isset($user['display_name']) && is_string($user['display_name']) && $user['display_name'] !== ''
    ? htmlspecialchars($user['display_name'], ENT_QUOTES, 'UTF-8')
    : '';

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
        <p class="page-head__sub">비밀번호 · 2단계 인증 · 프로그램 토큰을 관리합니다.</p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
      </div>
    </header>

    <p><a class="btn btn--quiet" href="<?= Http::url('/welcome') ?>">시작 안내 · OTP · AI 비서 연결 →</a></p>
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
            <span class="field__label">이름</span>
            <span class="info-item__value"><?= $myDisplayName === '' ? '— (관리자가 정합니다)' : $myDisplayName ?></span>
          </div>
          <div class="info-item">
            <span class="field__label">역할</span>
            <span class="info-item__value" title="<?= $displayRoleTitle ?>"><?= $displayRole ?></span>
          </div>
          <div class="info-item">
            <span class="field__label">팀</span>
            <!-- 팀 이름은 /api/me 의 team_name 을 app.js 가 textContent 로 넣는다 -->
            <span class="info-item__value" id="me-team">—</span>
          </div>
        </div>
      </section>

      <!-- 커뮤니티 7-D3 「화면」 (DESIGN 2.16 · 지시서 2장 H-6) — 고대비 화면 켜고 끄기. 새 클래스 0(.panel · .form · .field · .help).
           체크의 처음 상태는 /api/me 의 high_contrast 를 app.js(initSettings)가 놓고, 바꾸면 PATCH /api/me/settings 하나 →
           200 이면 <html> 의 data-contrast 를 놓거나 뗀다 · 실패면 체크를 되돌리고 #msg 에 서버 문구. 값은 계정 칸이라 어느 기기에서나 같다. -->
      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">화면</h2>
          <p class="panel__sub">이 화면에서만이 아니라 내 계정 전체에 적용됩니다.</p>
        </div>
        <div class="form">
          <label class="field">
            <span class="field__label">
              <input id="contrast-toggle" type="checkbox" disabled>
              고대비 화면
            </span>
          </label>
          <p class="help">글자와 배경의 차이를 더 크게 합니다. 밝은 화면·어두운 화면 모두에 적용됩니다.</p>
        </div>
      </section>

      <section class="panel">
        <div class="panel__head"><h2 class="panel__title">디자인 테마</h2><p class="panel__sub">색과 움직임을 골라 나만의 업무 공간을 만드세요.</p></div>
        <button type="button" class="btn btn--quiet q-design-open" data-design-open aria-haspopup="dialog" aria-controls="q-design-dialog"><span class="q-design-dot" aria-hidden="true"></span><span data-design-name>Astra</span> · 테마 고르기</button>
        <p class="help">기본 테마는 이 브라우저에 저장됩니다. 고대비 설정은 계정 전체에 적용됩니다.</p>
      </section>
      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">비밀번호 변경</h2>
          <p class="panel__sub">바꾸면 <strong>다른 기기의 세션이 모두 끊기고 개인 프로그램 토큰도 모두 취소됩니다</strong>(지금 이 창은 유지됩니다).</p>
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

      <!-- 프로그램 토큰 (F-66 — PRD F-66 · 10장 「설정 — 내 계정」, ARCH 4.7 「화면」,
           DESIGN 2.3 패널 · 2.4 표 · 2.5 폼 · 2.6 알림).
           목록은 /api/me/api-tokens 가 내려 주고 **내 것만** 나온다(서버가 세션으로 고정).
           토큰 값도 그 해시도 응답에 없다 — 발급 직후 한 번 보여 준 값을 여기서 다시 볼 수 없다.
           발급 폼을 보일지(②③)와 범위 선택지는 /api/me 의 can_issue_tokens·token_scopes 로만
           고른다 — 이 파일에도 app.js 에도 역할 비교가 없다 (DESIGN 0장 1, PRD S-01).
           모든 값은 app.js 가 textContent 로 넣는다 (PRD S-03). 인라인 style·script 0. -->
      <section class="panel span-all">
        <!-- D-10 (DESIGN 2.7) — 발급 폼은 패널에 펼쳐 두지 않는다. 패널 머리를
             「제목·설명 | 단추」 가로 배치로 만들려고 **기존** `.org-detail__head`(flex ·
             space-between) 로 감싸기만 한다 — 새 class·새 CSS 0 (DESIGN 6장).
             `.panel__head` 는 세로 묶음 그대로라 두 클래스를 한 요소에 겹치지 않는다. -->
        <div class="org-detail__head">
          <div class="panel__head">
            <h2 class="panel__title">프로그램 토큰</h2>
            <p class="panel__sub">
              이 계정의 권한 안에서 프로그램이 쓰는 토큰입니다. 값은 발급할 때 한 번만 보이고, 180일 뒤 만료됩니다.
            </p>
          </div>
          <!-- 발급 가능 여부(`can_issue_tokens`)는 예전 폼과 **같은 조건**으로 app.js 가 켜고 끈다 -->
          <button id="token-issue-open" class="btn" type="button"
                  aria-haspopup="dialog" aria-controls="token-issue-dialog" hidden>
            <span class="icon" data-icon="plus"></span>토큰 발급
          </button>
        </div>

        <!-- ① 만료가 가까운 토큰(14일 안)이 있으면 app.js 가 hidden 을 내린다 -->
        <p id="tokens-expiring" class="notice notice--recommend" hidden>
          만료가 가까운 토큰이 있습니다 — 새로 발급하고 프로그램에 바꿔 심으세요
        </p>

        <!-- ② 내 토큰 표 -->
        <div class="table-wrap">
          <table class="files">
            <thead>
              <tr>
                <th scope="col">이름</th>
                <th scope="col">용도</th>
                <th scope="col">앞 6자</th>
                <th scope="col">범위</th>
                <th scope="col">만료</th>
                <th scope="col">마지막 사용</th>
                <th scope="col">상태</th>
                <th scope="col">작업</th>
              </tr>
            </thead>
            <tbody id="my-tokens-body"></tbody>
          </table>
        </div>
        <p id="my-tokens-empty" class="muted" hidden>발급한 프로그램 토큰이 없습니다. 오른쪽 위 「토큰 발급」 으로 발급하세요.</p>

        <!-- ③ 발급 폼은 아래 「토큰 발급」 모달 안에 있다 (D-10) -->

        <!-- ④ 2단계 인증이 꺼져 있으면 폼 대신 이 줄 (can_issue_tokens 가 거짓) -->
        <p id="token-issue-blocked" class="notice notice--recommend" hidden>
          2단계 인증을 켜야 발급할 수 있습니다 — 아래 2단계 인증 절에서 켜세요
        </p>
      </section>

      <!-- T:F-72 지문·얼굴 로그인 (지시서 1.9 「내 계정」 · 결정 PK-D1 · PK-D3). 패널은 처음 hidden 이고 passkey.js 가 `/api/me` 의
           passkey_available 이 참일 때만 켠다. 「이 휴대폰 등록하기」는 휴대폰 + 지문·얼굴 확인 인증기가 있을 때만(PC 는 안내 한 줄) —
           화면 편의일 뿐 서버는 휴대폰을 가리지 않는다. 표는 **내 것만**(`GET /api/me/passkeys`) · 줄의 「해제」는 공용 확인 창
           (`#confirm-dialog` · 「해제하기」 빨강). 첫 칸이 이름 · 동작은 `td.actions`(좁은 폭 쌓기 — DESIGN 2.4). 글자는 전부 textContent. -->
      <section class="panel span-all" id="passkey-panel" hidden>
        <div class="org-detail__head">
          <div class="panel__head">
            <h2 class="panel__title">지문·얼굴 로그인</h2>
            <p class="panel__sub">이 휴대폰을 등록하면 다음부터 비밀번호와 2단계 코드 없이 지문이나 얼굴로 로그인합니다.</p>
          </div>
          <button id="passkey-register-open" class="btn btn--quiet" type="button"
                  aria-haspopup="dialog" aria-controls="passkey-register-dialog" hidden>이 휴대폰 등록하기</button>
        </div>
        <p id="passkey-pc-note" class="muted" hidden>지금은 휴대폰에서만 등록합니다. 휴대폰 브라우저로 이 화면을 열어 등록하세요.</p>
        <div id="passkey-ok" class="ok-note" role="status" hidden></div>
        <div class="table-wrap">
          <table class="files">
            <thead>
              <tr>
                <th scope="col">기기 이름</th>
                <th scope="col">기기</th>
                <th scope="col">등록한 날</th>
                <th scope="col">마지막 사용</th>
                <th scope="col">상태</th>
                <th scope="col">작업</th>
              </tr>
            </thead>
            <tbody id="passkey-body"></tbody>
          </table>
        </div>
        <p id="passkey-empty" class="muted" hidden>등록한 기기가 없습니다.</p>
        <p id="passkey-msg" class="msg" role="alert"></p>
      </section>

      <!-- 2단계 인증 (PRD F-16 ③ → F-52 "필수는 Admin 만, 나머지 역할은 권장", SECURITY R-05·R-85).
           QR(F-51, `src/Qr.php` 행렬을 canvas 에 그림)·비밀·otpauth 주소를 함께 보여 준다 —
           QR 이 기본 경로, 글자는 카메라를 못 쓸 때의 대안. 화면에 나오는 값은 전부 app.js 가
           textContent / setAttribute 로 넣는다. -->
      <section class="panel span-all">
        <div class="panel__head">
          <h2 class="panel__title">2단계 인증</h2>
          <p class="panel__sub">관리자 권한이 있는 계정은 반드시 켜야 하고, 그 밖의 계정은 켜 두기를 권장합니다.</p>
        </div>
        <div class="mfa">
          <p id="mfa-state" class="muted">2단계 인증 상태를 불러오는 중…</p>

          <!-- D-10 — 끄기 폼은 상태 문장 아래 이 단추가 여는 모달 안에 있다. 단추의 hidden 은
               예전 폼과 **같은 조건**(켜져 있을 때만)으로 app.js 가 켜고 끈다. 위험 동작이라
               `.btn--danger` 다 (DESIGN 2.1). `.toolbar` 는 기존 class — 새 CSS 0. -->
          <div class="toolbar">
            <button id="mfa-disable-open" class="btn btn--danger" type="button"
                    aria-haspopup="dialog" aria-controls="mfa-disable-dialog" hidden>2단계 인증 끄기</button>
          </div>

<?php require __DIR__ . '/../views/mfa_enroll.php'; ?>

          <p class="muted">스마트폰이 어려우면 <a href="<?= Http::url('/assets/totp-generator.html') ?>">2단계 코드 생성기</a>를 받아서 PC 에 두고 쓸 수 있습니다.</p>
        </div>
      </section>
    </div>

    <!-- ─── 「이 휴대폰 등록하기」 창 (T:F-72 지시서 1.1 등록 · 1.9 — DESIGN 2.7 · `.modal--form` 520) ─────────────
         칸: 기기 이름(기본값 = 기기 요약, 1~40자) · 현재 비밀번호 · 2단계 코드(켠 계정만 — passkey.js 가 hidden 을 내린다).
         「등록하기」 → 서버가 비밀번호·코드를 **먼저** 확인(틀리면 로그인과 같은 카운터로 실패 1회) → 휴대폰이 지문 창 → 서버 검증 → 목록.
         바닥 = 알림 줄 · 「닫기」(테두리) · 「등록하기」(검정 — 오른쪽 끝, 폼 밖이라 form="…"). 머리 ✕. 비밀번호·코드는 보낸 뒤 칸을 비운다. -->
    <dialog id="passkey-register-dialog" class="modal modal--form" aria-labelledby="passkey-register-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="key"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="passkey-register-title">이 휴대폰 등록하기</h2>
          <div class="modal__meta"><span>등록할 때마다 현재 비밀번호(2단계 인증을 켰으면 코드도)를 확인합니다</span></div>
        </div>
        <button id="passkey-register-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <form id="passkey-register-form" class="form form--grid" autocomplete="off">
          <label class="field">
            <span class="field__label">기기 이름</span>
            <input id="passkey-name" type="text" maxlength="40" required>
            <span class="help">「내 폴드」처럼 알아볼 이름입니다. 목록과 감사 기록에 남습니다.</span>
          </label>
          <label class="field">
            <span class="field__label">현재 비밀번호</span>
            <input id="passkey-password" type="password" maxlength="1024" autocomplete="current-password" required>
          </label>
          <label class="field otp-box" id="passkey-otp-field" hidden>
            <span class="field__label">2단계 인증 코드</span>
            <input id="passkey-otp" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">
          </label>
        </form>
      </div>
      <div class="modal__foot">
        <p id="passkey-register-msg" class="msg" role="alert"></p>
        <button id="passkey-register-close" class="btn btn--quiet" type="button">닫기</button>
        <button id="passkey-register" class="btn" type="submit" form="passkey-register-form">등록하기</button>
      </div>
    </dialog>

    <!-- ─── 「토큰 발급」 모달 (D-10, DESIGN 2.7 · 2.1) ────────────────────────
         폼 id·칸·요청 본문(`POST /api/me/api-tokens`)은 그대로고 **자리만** 옮겼다.
         폼의 `hidden` 도 그대로 남는다 — `can_issue_tokens` 가 거짓이면 단추와 폼이 함께
         숨고 패널의 `#token-issue-blocked` 안내만 보인다.
         제출 단추가 폼 밖(.modal__foot)에 있으므로 form="token-issue-form" 로 잇는다.
         발급에 성공하면 한 번만 보이는 토큰 상자가 **이 모달 안**(폼 바로 아래)에 나온다 —
         그래서 성공에도 모달을 곧바로 닫지 않는다. -->
    <dialog id="token-issue-dialog" class="modal modal--form" aria-labelledby="token-issue-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="key"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="token-issue-title">토큰 발급</h2>
          <div class="modal__meta"><span>값은 발급할 때 한 번만 보이고 180일 뒤 만료됩니다</span></div>
        </div>
        <button id="token-issue-dialog-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <!-- 범위 <select> 는 app.js 가 /api/me 의 token_scopes(원자)마다 체크박스 한 줄인 묶음으로 바꾼다(Q:F-10 · 고정 목록 0). -->
        <form id="token-issue-form" class="form form--grid" autocomplete="off" hidden>
          <label class="field">
            <span class="field__label">이름 (필수)</span>
            <input id="token-name" type="text" maxlength="50" required>
            <span class="help">「사무실 PC」처럼 어느 프로그램인지 알아볼 이름입니다.</span>
          </label>
          <label class="field">
            <span class="field__label">용도 (필수)</span>
            <input id="token-purpose" type="text" maxlength="500" required>
            <span class="help">무엇에 쓰는 토큰인지 적습니다. 감사 기록에 함께 남습니다.</span>
          </label>
          <label class="field">
            <span class="field__label">범위 (필수)</span>
            <select id="token-scope" required></select>
            <span class="help">계정 권한보다 넓은 범위는 고를 수 없습니다.</span>
          </label>
          <label class="field">
            <span class="field__label">현재 비밀번호</span>
            <input id="token-password" type="password" maxlength="1024" required>
          </label>
          <!-- 6자리 코드는 `.otp-box` 한 칸 — 여섯 칸으로 쪼개지 않는다 (DESIGN 2.5).
               모노·20px·글자 간격은 기존 `.otp-box input` 규칙이 준다(새 CSS 0). -->
          <label class="field otp-box">
            <span class="field__label">2단계 인증 코드</span>
            <input id="token-otp" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
          </label>
        </form>
      </div>
      <div class="modal__foot">
        <p id="token-issue-msg" class="msg" role="alert"></p>
        <button id="token-issue-dialog-close" class="btn btn--quiet" type="button">닫기</button>
        <button id="token-issue" class="btn" type="submit" form="token-issue-form">토큰 발급</button>
      </div>
    </dialog>

    <!-- ─── 「2단계 인증 끄기」 모달 (D-10, DESIGN 2.7 · 2.1) ──────────────────
         폼 id·칸·요청 본문(`POST /api/me/mfa/disable`)은 그대로다. 안내 문장은 폼 안에
         있던 것을 그대로 머리의 메타로 옮겼다. 제출 단추는 위험 동작이라 `.btn--danger`
         이고 폼 밖에 있으므로 form="mfa-disable-form" 로 잇는다. -->
    <dialog id="mfa-disable-dialog" class="modal modal--form" aria-labelledby="mfa-disable-title">
      <div class="modal__head">
        <span class="preview__mark"><span class="icon" data-icon="shield"></span></span>
        <div class="preview__headtext">
          <h2 class="modal__title" id="mfa-disable-title">2단계 인증 끄기</h2>
          <div class="modal__meta"><span>끄려면 현재 비밀번호와 지금의 코드가 필요합니다.</span></div>
        </div>
        <button id="mfa-disable-dialog-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <form id="mfa-disable-form" class="form form--grid" autocomplete="off" hidden>
          <label class="field">
            <span class="field__label">현재 비밀번호</span>
            <input id="mfa-off-password" type="password" maxlength="1024" required>
          </label>
          <label class="field">
            <span class="field__label">2단계 인증 코드</span>
            <input id="mfa-off-code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
          </label>
        </form>
      </div>
      <div class="modal__foot">
        <!-- 끄기가 서버에 막혔을 때의 문구 자리. 화면 맨 아래 공통 `#msg` 에 쓰면 모달이
             열려 있는 동안 백드롭 뒤에 가려 보이지 않는다. 문구는 **서버가 준 것 그대로**를
             app.js 가 textContent 로 넣는다 (PRD S-03). 성공 끝맺음은 모달이 닫히므로
             그대로 `#msg` 에 남긴다. -->
        <p id="mfa-disable-msg" class="msg" role="alert" aria-live="polite"></p>
        <button id="mfa-disable-dialog-close" class="btn btn--quiet" type="button">닫기</button>
        <button id="mfa-disable" class="btn btn--danger" type="submit" form="mfa-disable-form">2단계 인증 끄기</button>
      </div>
    </dialog>
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
