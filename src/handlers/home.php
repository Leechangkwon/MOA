<?php
declare(strict_types=1);

/**
 * src/handlers/home.php — GET / (정책: authenticated)
 *
 * 홈 화면: 파일 목록(검색·정렬·페이지) · 업로드 폼 · "URL 로 가져오기" 폼 ·
 * 다운로드/삭제/수정 버튼 · 내 계정(비밀번호·2단계 인증) · 휴지통.
 *
 * ★ 화면 문구에 "N단계" 같은 **개발 진행 단계 번호를 쓰지 않는다** (사용자 피드백 ①).
 *   단계 번호는 개발자용 기록이라 주석·STAGES.md 에만 남기고, 사용자에게는 서비스 설명만 보인다.
 *
 * 서버가 HTML 에 넣는 동적 값은 **사용자명·역할 두 개뿐**이고, 둘 다 DB 에서 읽은 값이며
 * (요청에 실려 온 값이 아니다 — PRD S-01) htmlspecialchars(ENT_QUOTES) 를 거친다 (PRD S-03).
 * 파일 목록은 서버가 HTML 로 만들지 않는다 — JS 가 /api/files 응답을 textContent 로만 그린다.
 *
 * ★ 5단계에도 화면에 권한 로직을 두지 않는다. 삭제·수정 버튼은 서버가 행마다 내려 준
 *   `can_delete`·`can_edit` 로만, 업로드 레벨 선택지는 서버가 내려 준 `upload_levels` 로만 만든다.
 *   7단계의 "URL 로 가져오기" 패널도 같다 — `hidden` 으로 두고 `/api/me` 의 `can_fetch` 가
 *   참일 때만 app.js 가 연다. 화면은 역할 문자열을 보지 않는다 (F-14 는 Manager 이상, PRD 5.4).
 *   그래서 업로드 <select> 는 여기서 **비워 둔다** — 화면이 레벨 목록을 알고 있으면
 *   그것이 곧 화면에 심어 둔 권한 판단이 된다. "버튼을 숨기는 것" 은 편의일 뿐
 *   권한의 근거가 아니다 (PRD 10장, S-01) — 허용 여부는 서버 Authz 가 매 요청 판단한다.
 *
 * 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self').
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$displayName = htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8');
$displayRole = htmlspecialchars((string) $user['role'], ENT_QUOTES, 'UTF-8');

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>t-box (재구축)</title>
<!-- 자산 주소에 파일이 마지막으로 바뀐 시각(정수)을 붙인다 (11단계).
     화면 코드를 고쳐도 브라우저가 옛 파일을 계속 쓰면, 서버는 새 규칙인데 화면만
     옛 규칙인 상태가 된다 — 강제 절차처럼 화면과 서버가 함께 움직여야 하는 기능에서는
     그것이 곧 "안 막히는 것처럼 보이는" 오해가 된다. 출력값은 filemtime 의 **정수**뿐이라
     이스케이프 대상이 없다(사용자 입력이 섞이지 않는다). -->
<link rel="stylesheet" href="/assets/app.css?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/app.css') ?>">
</head>
<body>
<main class="card card--wide">
  <header class="topbar">
    <div>
      <h1>t-box (재구축)</h1>
      <p class="lead">
        <strong><?= $displayName ?></strong> 님으로 로그인했습니다 ·
        역할 <code><?= $displayRole ?></code>
      </p>
    </div>
    <div class="topbar__actions">
      <!-- 8단계: "감사 로그" 링크는 **항상 HTML 에 있고 hidden 이다**. 서버가 /api/me 로 내려 준
           can_view_audit 이 참일 때만 app.js 가 연다 (F-09, Admin 만 — PRD 5.4 Q-8).
           여기(서버 HTML)에 역할 비교를 넣지 않는다: 판단은 Authz 한 곳에서만 한다.
           링크를 개발자도구로 되살려도 /audit·/api/audit 은 라우터 정책 audit.read 가 다시 막는다. -->
      <a id="audit-link" class="btn btn--quiet" href="/audit" hidden>감사 로그</a>
      <!-- 10단계: "계정 관리" 링크도 같은 방식이다 — 항상 HTML 에 있고 hidden 이며,
           서버가 /api/me 로 내려 준 can_manage_users 가 참일 때만 app.js 가 연다 (F-10, Admin 만).
           링크를 개발자도구로 되살려도 /users·/api/users 는 라우터 정책 user.manage 가 다시 막는다. -->
      <a id="users-link" class="btn btn--quiet" href="/users" hidden>계정 관리</a>
      <button id="logout" class="btn btn--quiet" type="button">로그아웃</button>
    </div>
  </header>

  <!-- ─── 11단계: 최초 로그인 강제 절차 (PRD F-16, ARCH 4.0) ──────────────────
       임시 비밀번호로 처음 들어온 사람은 **비밀번호 변경 → 2단계 인증 등록**을 마칠 때까지
       다른 화면을 보지 못한다. app.js 가 /api/me 의 `next` 를 보고 이 상자만 남기고
       나머지(안내·업로드·목록·내 계정·휴지통)를 감춘다.
       ★ 감추는 것은 **편의**일 뿐 막는 것이 아니다 — 화면을 되살려 API 를 직접 불러도
         라우터 관문(public/index.php)이 403 으로 막고 감사 로그에 남긴다 (PRD S-01). -->
  <section class="panel" id="forced-gate" hidden>
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
      <!-- app.js 가 「내 계정」의 등록 UI(#mfa-off)를 **그대로 옮겨 와서** 여기에 붙인다.
           같은 화면을 두 벌 만들면 한쪽만 고쳐지는 날이 온다. -->
      <div id="forced-mfa-slot"></div>
    </div>

    <p><button id="forced-logout" class="btn btn--quiet btn--small" type="button">로그아웃</button></p>
  </section>

  <!-- 사용자 피드백 ①: 개발 단계별 안내문(6·7·…)을 없애고 **서비스 설명 한 상자**로 합쳤다.
       여기에 역할 이름(Admin·Manager …)을 적지 않는다 — 무엇이 보이는지는 서버가 정한다. -->
  <div class="notice">
    <p class="notice__title">이 서비스는 이렇게 동작합니다</p>
    <ul class="notice__list">
      <li>목록에는 <strong>내가 볼 수 있는 파일만</strong> 나옵니다.</li>
      <li>올릴 수 있는 형식은 <code>pdf docx xlsx pptx hwp hwpx txt csv md png jpg jpeg gif zip</code>
          이고, 한 파일은 최대 100MB · 한 사람이 하루에 올릴 수 있는 총량은 2GB 입니다.</li>
      <li>다운로드는 브라우저에서 열리지 않고 <strong>항상 파일로 저장</strong>됩니다.</li>
      <li>올리기·내려받기·지우기 등 <strong>모든 행동은 감사 로그에 남습니다</strong>.</li>
      <li>URL 가져오기 · 감사 로그 · 계정 관리 · 휴지통은 <strong>권한이 있는 계정에만 보입니다</strong>.</li>
      <li>거부되거나 잘 안 되면 관리자에게 문의하세요.</li>
    </ul>
  </div>

  <section class="panel">
    <h2>파일 올리기</h2>
    <form id="upload-form" class="form form--grid" autocomplete="off">
      <label class="field">
        <span class="field__label">파일 (최대 100MB · 허용 형식만 · 하루 2GB)</span>
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
             고르지 않고 올리는 일을 막기 위해서다. 기본값은 가장 좁은 team(내 팀)이고,
             팀이 없는 계정은 자리표시가 선택돼 있어 반드시 직접 고르게 된다. -->
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
    <h2>URL 로 가져오기</h2>
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

  <section class="panel">
    <h2>파일 목록</h2>
    <form id="search-form" class="toolbar" autocomplete="off">
      <input id="search-q" type="search" maxlength="200" placeholder="파일명 · 설명 검색">
      <select id="search-sort">
        <option value="created_at">올린 시각</option>
        <option value="name">이름</option>
        <option value="size">크기</option>
      </select>
      <select id="search-dir">
        <option value="desc">내림차순</option>
        <option value="asc">오름차순</option>
      </select>
      <button class="btn btn--quiet" type="submit">검색</button>
    </form>

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

    <div class="pager">
      <button id="page-prev" class="btn btn--quiet" type="button">이전</button>
      <span id="page-info" class="muted"></span>
      <button id="page-next" class="btn btn--quiet" type="button">다음</button>
    </div>
  </section>

  <!-- ─── 10단계: 내 계정 (비밀번호 변경 · 2단계 인증) ─────────────────
       대상은 언제나 **세션의 본인**이다. 화면이 "누구의" 를 고르지 않는다. -->
  <section class="panel">
    <h2>내 계정</h2>

    <form id="password-form" class="form form--grid" autocomplete="off">
      <p class="field field--full muted">
        비밀번호를 바꾸면 <strong>다른 기기의 세션이 모두 끊깁니다</strong>(지금 이 창은 유지됩니다).
        설치할 때 발급된 최초 비밀번호는 첫 로그인 뒤 바꾸고,
        <code>/etc/tbox-v2/seed-accounts.txt</code> 는 지우는 것이 운영 절차입니다.
      </p>
      <label class="field">
        <span class="field__label">현재 비밀번호</span>
        <input id="pw-current" type="password" maxlength="1024" required>
      </label>
      <label class="field">
        <span class="field__label">새 비밀번호 (10자 이상)</span>
        <input id="pw-new" type="password" maxlength="1024" minlength="10" required>
      </label>
      <button id="pw-submit" class="btn" type="submit">비밀번호 바꾸기</button>
    </form>

    <!-- 2단계 인증 (SECURITY R-05). QR 라이브러리를 넣지 않으므로 비밀과 otpauth 주소를
         **글자 그대로** 보여 주고 사용자가 인증 앱에 손으로 옮겨 적는다.
         화면에 나오는 값은 전부 app.js 가 textContent / setAttribute 로 넣는다.
         사용자 피드백 ③: "코드를 어디서 구하나" 가 화면만 보고 답이 되도록 절차를 번호로 적는다. -->
    <div class="mfa">
      <p id="mfa-state" class="muted">2단계 인증 상태를 불러오는 중…</p>

      <div id="mfa-off" hidden>
        <button id="mfa-enroll" class="btn btn--quiet" type="button">2단계 인증 켜기</button>
        <div id="mfa-setup" hidden>
          <p class="field__label">인증 앱에 등록하는 순서</p>
          <ol class="steps">
            <li>스마트폰에 <strong>인증 앱</strong>을 설치합니다 — Google Authenticator · Microsoft Authenticator ·
                Aegis · 1Password 중 아무거나 됩니다.</li>
            <li>앱에서 <strong>“수동 입력”(설정 키 입력)</strong> 을 고릅니다. QR 을 찍는 화면이 아니라
                글자로 적어 넣는 화면입니다.</li>
            <li>계정 이름은 <code>t-box:아이디</code>, 키는 <strong>아래 「비밀 키」 문자열을 그대로</strong>
                넣습니다. 종류는 <strong>시간 기반(Time based) · 6자리</strong> 입니다.
                — 또는 지금 이 화면을 스마트폰에서 보고 있다면 아래
                <strong>「인증 앱에 바로 추가」</strong> 링크를 누르면 앱이 대신 받아 적습니다.</li>
            <li>앱에 <strong>6자리 코드</strong>가 뜹니다(30초마다 새로 바뀝니다). 그 숫자를 아래 칸에 넣고
                <strong>「확인하고 켜기」</strong> 를 누릅니다.</li>
            <li>그 뒤로는 로그인할 때마다 <strong>비밀번호 + 그 시각의 6자리 코드</strong>가 필요합니다.</li>
          </ol>
          <p class="warn">
            비밀 키를 잃으면 앱을 처음부터 다시 등록해야 하니 <strong>지금 옮겨 적으세요.</strong>
            이 문자열은 다시 보여 주지 않습니다.
          </p>
          <p class="field__label">비밀 키 (인증 앱에 손으로 넣는 값)</p>
          <pre id="mfa-secret" class="audit-detail"></pre>
          <p class="field__label">otpauth 주소</p>
          <!-- href 는 비워 둔다. app.js 가 서버가 준 문자열이 otpauth:// 로 시작할 때만 넣는다. -->
          <p><a id="mfa-uri-link" hidden>인증 앱에 바로 추가 (otpauth 링크)</a></p>
          <pre id="mfa-uri" class="audit-detail"></pre>
          <form id="mfa-confirm-form" class="form form--grid" autocomplete="off">
            <label class="field">
              <span class="field__label">인증 앱이 보여 주는 6자리 코드</span>
              <input id="mfa-code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
            </label>
            <button id="mfa-confirm" class="btn" type="submit">확인하고 켜기</button>
          </form>
        </div>
      </div>

      <form id="mfa-disable-form" class="form form--grid" autocomplete="off" hidden>
        <p class="field field--full muted">
          끄려면 <strong>현재 비밀번호와 지금의 코드</strong>가 모두 필요합니다 —
          세션만 훔친 사람이 2단계 인증을 꺼 버리지 못하게 하기 위해서입니다.
        </p>
        <label class="field">
          <span class="field__label">현재 비밀번호</span>
          <input id="mfa-off-password" type="password" maxlength="1024" required>
        </label>
        <label class="field">
          <span class="field__label">2단계 인증 코드</span>
          <input id="mfa-off-code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
        </label>
        <button id="mfa-disable" class="btn btn--quiet" type="submit">2단계 인증 끄기</button>
      </form>
    </div>
  </section>

  <!-- ─── 10단계: 휴지통 (F-11, Admin 만) ───────────────────────────────
       감사 로그 링크와 같은 방식이다 — 항상 HTML 에 있고 hidden 이며,
       /api/me 의 can_manage_users 가 참일 때만 app.js 가 연다.
       패널을 되살려도 GET /api/files/trash · POST …/restore 는 정책 role:Admin 이 다시 막는다. -->
  <section class="panel" id="trash-panel" hidden>
    <h2>휴지통 — 삭제된 파일 복구</h2>
    <p class="muted">
      삭제는 30일 동안 되돌릴 수 있습니다. 30일이 지나 배치가 실제 파일을 지운 뒤에는
      목록에 나오지 않고 복구할 수도 없습니다.
    </p>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">이름</th>
            <th scope="col">크기</th>
            <th scope="col">레벨</th>
            <th scope="col">올린 사람</th>
            <th scope="col">지운 사람</th>
            <th scope="col">지운 시각</th>
            <th scope="col">작업</th>
          </tr>
        </thead>
        <tbody id="trash-body"></tbody>
      </table>
    </div>
    <p id="trash-empty" class="muted" hidden>휴지통이 비어 있습니다.</p>
  </section>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>
</main>
<script src="/assets/app.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/app.js') ?>"></script>
</body>
</html>
