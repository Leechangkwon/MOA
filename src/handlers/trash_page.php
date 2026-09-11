<?php
declare(strict_types=1);

/**
 * src/handlers/trash_page.php — GET /trash (정책: role:Admin → PRD 5.4 "삭제된 파일 복구", F-11)
 *
 * 요구사항: PRD F-11 · F-20 · 10장 화면 요구사항(「휴지통 (`/trash`)」), S-01, S-03
 *
 * 12단계(F-20)에서 홈 화면의 「휴지통」 패널을 이 화면으로 옮겼다. 마크업·id 는 그대로이고
 * 데이터는 예전과 같은 `GET /api/files/trash` · `POST /api/files/{id}/restore` 에서 온다 —
 * **서버 규칙·권한·API 는 바뀌지 않았다.**
 *
 * ★ 이 파일에 역할 비교를 두지 않는다. "Admin 만" 은 라우트 정책 `role:Admin` 이 판단하고,
 *   화면은 여기까지 왔다는 사실 자체가 통과의 증거다 (PRD S-01).
 *   좌측 내비의 「휴지통」 항목을 개발자도구로 되살려도 이 경로와 API 가 다시 403 으로 막는다.
 *
 * 서버가 HTML 에 넣는 동적 값은 공통 셸의 사용자명·역할뿐이고(전부 htmlspecialchars),
 * 표의 행은 app.js 가 `textContent` 로만 그린다 (PRD S-03).
 * 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '휴지통';
$activeNav = 'trash';
$pageKey   = 'trash';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">휴지통</h1>
        <p class="page-head__sub">삭제된 파일을 30일 안에 되살립니다. 복구도 감사 로그에 남습니다.</p>
      </div>
    </header>

    <p class="notice">
      삭제는 <strong>논리 삭제</strong>이고 30일 동안 되돌릴 수 있습니다.
      30일이 지나 배치가 실제 파일을 지운 뒤에는 목록에 나오지 않고 복구할 수도 없습니다.
    </p>

    <section class="card card--flush">
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
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
