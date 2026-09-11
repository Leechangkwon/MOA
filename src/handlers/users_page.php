<?php
declare(strict_types=1);

/**
 * src/handlers/users_page.php — GET /users (정책: user.manage → Admin 만, PRD 5.4)
 *
 * 요구사항: PRD F-10 · 10장 화면 요구사항("계정 관리 — 목록·생성·역할 변경, Admin 만"),
 *           S-03(출력 이스케이프), S-07("관리자 행동 사유"), SECURITY R-05 · R-12 · R-20
 *
 * 이 파일이 HTML 에 넣는 동적 값은 **사용자명·역할 두 개뿐**이며 둘 다 DB 에서 읽은 값이고
 * htmlspecialchars(ENT_QUOTES) 를 거친다. 계정 목록은 서버가 HTML 로 만들지 않는다 —
 * app.js 가 `/api/users` 응답을 **textContent 로만** 그린다 (아이디도 사용자가 정한 문자열이다).
 *
 * ★ 이 파일에도 역할 비교를 두지 않는다. "Admin 만" 은 라우트 정책 `user.manage` 가 판단하고
 *   (Authz::canManageUsers), 화면은 여기까지 왔다는 사실 자체가 통과의 증거다 (PRD S-01).
 *   `/users` 화면과 `/api/users` 데이터는 같은 정책을 쓴다 — 화면만 숨기면 우회 경로가 된다.
 *
 * 사유(`reason_text`) 칸은 **화면에서 필수**이고, 비워 두면 서버도 400 으로 막는다
 * (PRD S-07 "관리자 행동 사유", SECURITY R-20 "확인 단계").
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
<title>계정 관리 · t-box (재구축)</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="card card--wide">
  <header class="topbar">
    <div>
      <h1>계정 관리</h1>
      <p class="lead">
        <strong><?= $displayName ?></strong> 님으로 로그인했습니다 ·
        역할 <code><?= $displayRole ?></code>
      </p>
    </div>
    <div class="topbar__actions">
      <a class="btn btn--quiet" href="/">파일 목록으로</a>
      <button id="logout" class="btn btn--quiet" type="button">로그아웃</button>
    </div>
  </header>

  <p class="notice">
    이 화면은 <strong>관리자만</strong> 볼 수 있습니다. 계정은 <strong>1인 1개</strong>이며
    공용 계정을 쓰지 않습니다 — 감사 로그의 「누가」는 계정이 사람에 1:1 로 묶일 때만 성립합니다.
    역할 변경·비활성화·재활성화에는 <strong>사유 입력이 필수</strong>이고, 사유는 감사 로그
    <code>detail.reason_text</code> 에 남습니다. 역할·팀을 바꾸거나 계정을 끄면
    <strong>그 사람의 모든 기기 세션이 즉시 끊깁니다</strong>.
    <strong>자기 자신</strong>의 역할·활성 상태는 이 화면에서 바꿀 수 없고,
    <strong>마지막 활성 관리자</strong>는 강등·비활성화되지 않습니다.
  </p>
  <p class="notice">
    <strong>Guest 계정은 만료일이 필수</strong>입니다(협력사 계정이 프로젝트가 끝나도 남지 않게).
    만료된 뒤에는 비밀번호가 맞아도 로그인할 수 없습니다.
    계정을 지우는 기능은 없습니다 — 파일·감사 로그가 계정을 가리키고 있어,
    지우면 추적이 끊어집니다. 그만 쓸 계정은 <strong>비활성화</strong>합니다.
  </p>

  <section class="panel">
    <h2>계정 만들기</h2>
    <form id="user-create-form" class="form form--grid" autocomplete="off">
      <label class="field">
        <span class="field__label">아이디 (소문자·숫자·<code>_ . -</code>, 3~64자)</span>
        <input id="u-username" type="text" maxlength="64" inputmode="latin"
               autocapitalize="none" autocorrect="off" spellcheck="false" required>
      </label>
      <label class="field">
        <span class="field__label">최초 비밀번호 (12자 이상)</span>
        <input id="u-password" type="password" maxlength="1024" minlength="12" required>
      </label>
      <label class="field">
        <span class="field__label">역할</span>
        <select id="u-role">
          <option value="Employee">Employee</option>
          <option value="Manager">Manager</option>
          <option value="Guest">Guest</option>
          <option value="Admin">Admin</option>
        </select>
      </label>
      <label class="field">
        <span class="field__label">팀 (Guest 는 비움)</span>
        <select id="u-team"></select>
      </label>
      <label class="field">
        <span class="field__label">만료일 (Guest 는 필수)</span>
        <input id="u-expires" type="date">
      </label>
      <label class="field">
        <span class="field__label">사유 (필수 · 500자)</span>
        <input id="u-reason" type="text" maxlength="500" required>
      </label>
      <button id="u-submit" class="btn" type="submit">만들기</button>
    </form>
  </section>

  <section class="panel">
    <h2>계정 목록</h2>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">id</th>
            <th scope="col">아이디</th>
            <th scope="col">역할</th>
            <th scope="col">팀</th>
            <th scope="col">활성</th>
            <th scope="col">만료</th>
            <th scope="col">2단계</th>
            <th scope="col">만든 시각</th>
            <th scope="col">작업</th>
          </tr>
        </thead>
        <tbody id="users-body"></tbody>
      </table>
    </div>
    <p id="users-empty" class="muted" hidden>표시할 계정이 없습니다.</p>

    <div class="pager">
      <button id="page-prev" class="btn btn--quiet" type="button">이전</button>
      <span id="page-info" class="muted"></span>
      <button id="page-next" class="btn btn--quiet" type="button">다음</button>
    </div>
  </section>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
