<?php
declare(strict_types=1);

/**
 * src/handlers/home.php — GET / (정책: authenticated)
 *
 * 3단계 화면: 파일 목록(검색·정렬·페이지) · 업로드 폼 · 다운로드/삭제 버튼.
 *
 * 서버가 HTML 에 넣는 동적 값은 **사용자명·역할 두 개뿐**이고, 둘 다 DB 에서 읽은 값이며
 * (요청에 실려 온 값이 아니다 — PRD S-01) htmlspecialchars(ENT_QUOTES) 를 거친다 (PRD S-03).
 * 파일 목록은 서버가 HTML 로 만들지 않는다 — JS 가 /api/files 응답을 textContent 로만 그린다.
 *
 * ★ 3단계에는 화면 권한 로직도 두지 않는다: 삭제 버튼을 모든 사용자에게 보인다.
 *   4단계에서도 "버튼을 숨기는 것" 은 편의일 뿐 권한의 근거가 아니다 (PRD 10장, S-01) —
 *   허용 여부는 서버 Authz 가 매 요청 판단한다.
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
      <h1>t-box (재구축) — 3단계</h1>
      <p class="lead">
        <strong><?= $displayName ?></strong> 님으로 로그인했습니다 ·
        역할 <code><?= $displayRole ?></code>
      </p>
    </div>
    <button id="logout" class="btn btn--quiet" type="button">로그아웃</button>
  </header>

  <p class="notice">
    3단계는 <strong>역할·팀 권한 검사가 없는 상태</strong>입니다. 로그인만 하면 모든 파일이
    보이고 다운로드·삭제가 됩니다(과제의 4단계 전후 비교용). 인증·CSRF·SQL 바인딩·출력
    이스케이프·저장 경로 안전은 이 단계에도 적용돼 있습니다.
  </p>

  <section class="panel">
    <h2>파일 올리기</h2>
    <form id="upload-form" class="form form--grid" autocomplete="off">
      <label class="field">
        <span class="field__label">파일 (최대 100MB)</span>
        <input id="upload-file" type="file" required>
      </label>
      <label class="field">
        <span class="field__label">설명 (500자 이내)</span>
        <input id="upload-description" type="text" maxlength="500">
      </label>
      <label class="field">
        <span class="field__label">접근 레벨</span>
        <select id="upload-level">
          <option value="public">public — 전체 공개</option>
          <option value="internal">internal — 사내 전체</option>
          <option value="team">team — 팀 전용</option>
          <option value="admin-only">admin-only — 관리자 전용</option>
        </select>
      </label>
      <label class="field" id="upload-team-field" hidden>
        <span class="field__label">팀</span>
        <select id="upload-team"></select>
      </label>
      <button id="upload-submit" class="btn" type="submit">올리기</button>
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

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
