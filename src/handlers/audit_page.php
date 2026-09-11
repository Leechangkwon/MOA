<?php
declare(strict_types=1);

/**
 * src/handlers/audit_page.php — GET /audit (정책: audit.read → Admin 만, PRD 5.4 Q-8)
 *
 * 요구사항: PRD F-09 · 10장(감사 로그 화면), S-07 "조회 화면 필터", ARCH 7.6, SECURITY 1.4
 *
 * 이 파일이 HTML 에 넣는 동적 값은 **사용자명·역할 두 개뿐**이며 둘 다 DB 에서 읽은 값이고
 * htmlspecialchars(ENT_QUOTES) 를 거친다 (PRD S-03). 감사 로그 행은 서버가 HTML 로 만들지 않는다 —
 * app.js 가 `/api/audit` 응답을 **textContent 로만** 그린다.
 * `summary`·`target_name`·`user_agent`·`detail` 은 공격자가 값을 정할 수 있는 문자열이므로
 * (파일명·UA) 다른 사용자 입력과 똑같이 다룬다 (SECURITY 1.4 보완, ARCH 7.4 마지막 규칙).
 *
 * ★ 이 파일에도 역할 비교를 두지 않는다. "Admin 만" 은 라우트 정책 `audit.read` 가 판단하고
 *   (Authz::canReadAudit), 화면은 여기까지 왔다는 사실 자체가 통과의 증거다 (PRD S-01).
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
<title>감사 로그 · t-box (재구축)</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="card card--wide">
  <header class="topbar">
    <div>
      <h1>감사 로그</h1>
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
    이 화면은 <strong>관리자만</strong> 볼 수 있고, <strong>이 조회 자체도 감사 로그에 남습니다</strong>
    (<code>audit.view</code>). 기록은 지울 수 없습니다 — 앱 계정에는 추가(INSERT) 권한만 있습니다.
    <code>ip</code>·<code>user_agent</code> 는 개인정보이며 보안 사고 조사·감사 목적으로만 봅니다.
  </p>

  <section class="panel">
    <h2>필터 — 누가 · 언제 · 어디서 · 무엇을 · 어떻게 · 왜</h2>
    <form id="audit-filter" class="form form--grid" autocomplete="off">
      <label class="field">
        <span class="field__label">누가 — 계정명 또는 로그인 회차(12자)</span>
        <input id="f-actor" type="text" maxlength="64" placeholder="qa_employee01 · 3f2a1b0c9d8e">
      </label>
      <label class="field">
        <span class="field__label">언제 — 시작</span>
        <input id="f-from" type="datetime-local">
      </label>
      <label class="field">
        <span class="field__label">언제 — 끝</span>
        <input id="f-to" type="datetime-local">
      </label>
      <label class="field">
        <span class="field__label">어디서 — IP</span>
        <input id="f-ip" type="text" maxlength="45" placeholder="127.0.0.1">
      </label>
      <label class="field">
        <span class="field__label">무엇을 — 대상 이름(부분 일치)</span>
        <input id="f-target" type="text" maxlength="200">
      </label>
      <label class="field">
        <span class="field__label">무엇을 — 접근 등급</span>
        <select id="f-level">
          <option value="">(전체)</option>
          <option value="public">public</option>
          <option value="internal">internal</option>
          <option value="team">team</option>
          <option value="admin-only">admin-only</option>
        </select>
      </label>
      <label class="field">
        <span class="field__label">어떻게 — 행동</span>
        <select id="f-action">
          <option value="">(전체)</option>
          <option value="auth.login">auth.login</option>
          <option value="auth.logout">auth.logout</option>
          <option value="auth.required">auth.required</option>
          <option value="csrf">csrf</option>
          <option value="authz.route">authz.route</option>
          <option value="file.upload">file.upload</option>
          <option value="file.fetch">file.fetch</option>
          <option value="file.download">file.download</option>
          <option value="file.delete">file.delete</option>
          <option value="file.update">file.update</option>
          <option value="file.level_change">file.level_change</option>
          <option value="audit.view">audit.view</option>
        </select>
      </label>
      <label class="field">
        <span class="field__label">왜 — 결과</span>
        <select id="f-result">
          <option value="">(전체)</option>
          <option value="allow">allow</option>
          <option value="deny">deny</option>
          <option value="error">error</option>
        </select>
      </label>
      <label class="field">
        <span class="field__label">왜 — 거부 사유(reason)</span>
        <input id="f-reason" type="text" maxlength="40" placeholder="team_mismatch">
      </label>
      <label class="field">
        <span class="field__label">왜 — 허용 근거(granted_by)</span>
        <input id="f-granted" type="text" maxlength="40" placeholder="owner">
      </label>
      <button id="audit-submit" class="btn" type="submit">조회</button>
      <button id="audit-reset" class="btn btn--quiet" type="button">필터 지우기</button>
    </form>
  </section>

  <section class="panel">
    <h2>기록</h2>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">시각</th>
            <th scope="col">누가</th>
            <th scope="col">역할</th>
            <th scope="col">행동</th>
            <th scope="col">대상</th>
            <th scope="col">등급</th>
            <th scope="col">결과</th>
            <th scope="col">사유 / 근거</th>
            <th scope="col">IP</th>
            <th scope="col">요약</th>
            <th scope="col">상세</th>
          </tr>
        </thead>
        <tbody id="audit-body"></tbody>
      </table>
    </div>
    <p id="audit-empty" class="muted" hidden>조건에 맞는 기록이 없습니다.</p>

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
