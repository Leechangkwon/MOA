<?php
declare(strict_types=1);

/**
 * src/handlers/activity_page.php — GET /activity (정책: authenticated)
 *
 * ★ 15-1: 내 활동 화면 (PRD F-12 · 10장 「내 활동」, ARCH 5.11).
 *
 * 로그인한 사람이면 누구나 **자기 것만** 본다. "누구의" 를 고르는 입력이 화면에도 API 에도 없다 —
 * 대상은 서버가 세션에서 읽는다 (PRD S-02). 화면 경로와 데이터 경로(`/api/me/activity`)는
 * **같은 정책**(`authenticated`)을 쓴다 — 한쪽만 열려 있으면 그것이 곧 우회 경로다 (PRD S-01).
 *
 * 서버가 HTML 에 넣는 동적 값은 공통 셸의 **사용자명·역할 두 개뿐**이고 htmlspecialchars 를 거친다.
 * 활동 행은 서버가 HTML 로 만들지 않는다 — app.js 가 `/api/me/activity` 응답을
 * **textContent 로만** 그린다. `summary`·`target_name` 은 파일명이 섞이는 값이라
 * 다른 사용자 입력과 똑같이 다룬다 (PRD S-03, ARCH 7.4 "파일명이 문장을 흉내").
 *
 * 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self').
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '내 활동';
$activeNav = 'activity';
$pageKey   = 'activity';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">내 활동</h1>
        <p class="page-head__sub">내가 한 일만 최근 순서로 보여 줍니다. 20건씩 넘겨 볼 수 있습니다.</p>
      </div>
    </header>

  <p class="notice">
    여기에는 <strong>로그인한 본인의 행동만</strong> 나옵니다 — 대상은 서버가 세션에서 정하고,
    주소에 다른 계정을 적어 넣을 수 있는 칸이 없습니다. 접속 IP·기기 정보(User-Agent)는
    본인 것이라도 이 화면에 보이지 않습니다. 이 화면을 보는 것 자체는 감사 로그에 남지 않습니다.
  </p>

  <section class="panel">
    <div class="panel__head"><h2 class="panel__title">최근 활동</h2></div>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">시각</th>
            <th scope="col">요약</th>
            <th scope="col">결과</th>
          </tr>
        </thead>
        <tbody id="activity-body"></tbody>
      </table>
    </div>
    <p id="activity-empty" class="muted" hidden>아직 기록된 활동이 없습니다.</p>

    <div class="table-foot">
      <span id="page-info" class="muted"></span>
      <div class="pager">
        <button id="page-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="page-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
    </div>
  </section>

<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
