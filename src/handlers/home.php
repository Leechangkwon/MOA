<?php
declare(strict_types=1);

/**
 * src/handlers/home.php — GET / (정책: authenticated)
 *
 * 7단계 화면: 파일 목록(검색·정렬·페이지) · 업로드 폼 · "URL 로 가져오기" 폼 · 다운로드/삭제/수정 버튼.
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
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="card card--wide">
  <header class="topbar">
    <div>
      <h1>t-box (재구축) — 10단계</h1>
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

  <p class="notice">
    6단계: <strong>업로드를 잠갔습니다.</strong>
    허용 확장자는 <code>pdf docx xlsx pptx hwp hwpx txt csv md png jpg jpeg gif zip</code> 뿐이고,
    서버가 파일 내용을 직접 판별해 <strong>실제 내용(MIME)과 확장자가 다르면 거부</strong>합니다.
    <code>shell.php.txt</code> 같은 이중 확장자도 거부합니다. 한 파일은 최대 100MB,
    한 사람이 하루에 올릴 수 있는 총량은 2GB 입니다.
    내려받을 때는 저장된 파일의 해시를 기록과 대조한 뒤에만 전송합니다.
  </p>
  <p class="notice">
    7단계: <strong>URL 로 가져오기(Manager 이상)를 열었습니다.</strong>
    서버는 <code>https</code> · 443 포트 · <strong>허용 목록에 정확히 일치하는 주소</strong>만 갑니다.
    주소가 내부망 IP 로 해석되면 가지 않고, 리다이렉트도 따라가지 않습니다.
    가져온 파일은 직접 올린 파일과 <strong>똑같은 검사</strong>를 거칩니다.
    실패하면 이유를 알려 주지 않고 <code>가져올 수 없습니다</code> 한 문장만 보여 줍니다 —
    이유는 감사 로그에만 남습니다.
  </p>
  <p class="notice">
    목록에는 볼 수 있는 파일만 담기고(SQL 조건), 다운로드·삭제·업로드·수정은 요청마다 서버가
    다시 검사합니다. <strong>버튼이 보이지 않아도 요청은 서버가 다시 검사합니다</strong> —
    버튼을 되살리거나 콘솔에서 직접 호출해도 결과는 같습니다.
  </p>

  <section class="panel">
    <h2>파일 올리기</h2>
    <form id="upload-form" class="form form--grid" autocomplete="off">
      <label class="field">
        <span class="field__label">파일 (최대 100MB · 허용 형식만 · 하루 2GB)</span>
        <input id="upload-file" type="file" required>
      </label>
      <label class="field">
        <span class="field__label">설명 (500자 이내)</span>
        <input id="upload-description" type="text" maxlength="500">
      </label>
      <label class="field">
        <span class="field__label">접근 레벨</span>
        <!-- 선택지는 /api/me 의 upload_levels 로 app.js 가 채운다 (서버가 정한다) -->
        <select id="upload-level"></select>
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
        <span class="field__label">설명 (500자 이내)</span>
        <input id="fetch-description" type="text" maxlength="500">
      </label>
      <label class="field">
        <span class="field__label">접근 레벨</span>
        <!-- 선택지는 /api/me 의 upload_levels 로 app.js 가 채운다 (서버가 정한다) -->
        <select id="fetch-level"></select>
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
        <span class="field__label">새 비밀번호 (12자 이상)</span>
        <input id="pw-new" type="password" maxlength="1024" minlength="12" required>
      </label>
      <button id="pw-submit" class="btn" type="submit">비밀번호 바꾸기</button>
    </form>

    <!-- 2단계 인증 (SECURITY R-05). QR 라이브러리를 넣지 않으므로 비밀과 otpauth 주소를
         **글자 그대로** 보여 주고 사용자가 인증 앱에 손으로 옮겨 적는다.
         화면에 나오는 값은 전부 app.js 가 textContent 로 넣는다. -->
    <div class="mfa">
      <p id="mfa-state" class="muted">2단계 인증 상태를 확인하는 중입니다.</p>

      <div id="mfa-off" hidden>
        <button id="mfa-enroll" class="btn btn--quiet" type="button">2단계 인증 켜기</button>
        <div id="mfa-setup" hidden>
          <p class="muted">아래 값을 인증 앱(Google Authenticator 등)에 입력한 뒤, 앱이 보여 주는 6자리 코드를 적으세요.</p>
          <p class="field__label">비밀 키</p>
          <pre id="mfa-secret" class="audit-detail"></pre>
          <p class="field__label">otpauth 주소</p>
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
<script src="/assets/app.js"></script>
</body>
</html>
