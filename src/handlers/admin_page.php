<?php
declare(strict_types=1);

/**
 * src/handlers/admin_page.php — GET /admin (정책: role:Admin + 재인증, PRD F-24)
 *
 * 요구사항: PRD F-24 · 8장 `/admin` 행 · 10장 「관리자 허브」 · 12.2 T-38,
 *           ARCH 5.14, SECURITY R-61
 *
 * 관리 기능의 **단일 입구**다. 일반 셸에서 관리 항목(계정·조직·감사·휴지통·위키)을 치우고,
 * Admin 에게만 「관리자 페이지」 한 항목을 열어 여기로 들여보낸다. 세부 화면은 이 허브의
 * 카드로만 간다 — 기존 경로(`/users`·`/org`·`/audit`·`/trash`·`/wiki`)는 그대로 두었다
 * (위키 글과 문서가 그 경로를 가리키고, 이미 같은 정책·재인증 대상이다).
 *
 * 막는 겹은 셋이다 (ARCH 5.14)
 *   ① 서버 인가 — 라우트 정책 `role:Admin`. 비관리자는 여기 오기 전에 403 이고 그 시도가
 *      `authz.route deny` 로 남는다. 이 파일에는 역할 비교가 없다 (PRD S-01, ARCH 4.1).
 *   ② 재인증 15분 창 — `role:Admin` 은 `Authz::REAUTH_POLICIES` 에 들어 있어 관문 [3-2] 가
 *      화면 요청을 `/reauth?to=admin` 으로 돌려보낸다 (F-23). 여기 왔다는 것은 통과했다는 뜻이다.
 *   ③ 네트워크 — nginx 의 관리망 허용 블록. **기본은 꺼짐(주석)** 이다: 접속 출처가 셋이라
 *      켜면 스스로 잠길 수 있다 (SECURITY R-61, README 「관리자 영역을 관리망에서만」).
 *
 * ★ 이 화면이 HTML 에 넣는 동적 값은 공통 셸의 다섯 개(사용자명·역할·제목·활성 메뉴·페이지 키)
 *   뿐이고 전부 htmlspecialchars 를 거친다 (shell_top.php, PRD S-03). 공유 링크·경보 값은
 *   서버가 HTML 로 만들지 않는다 — app.js 가 API 응답을 **textContent 로만** 그린다.
 *
 * ★ 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self').
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '관리자 페이지';
$activeNav = 'admin';
$pageKey   = 'admin';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">관리자 페이지</h1>
        <p class="page-head__sub">관리 기능의 단일 입구입니다. 각 기능은 아래 카드로 들어갑니다.</p>
      </div>
      <div class="page-head__actions">
        <span id="admin-reauth" class="badge">재인증 확인 중…</span>
      </div>
    </header>

    <p class="notice">
      이 영역은 <strong>관리자만</strong> 쓸 수 있고, 들어올 때 <strong>비밀번호를 다시 확인</strong>합니다
      (재인증 창 15분). 창이 닫히면 다시 묻습니다 — 훔친 세션 쿠키나 자리 비움만으로
      관리 기능에 닿지 못하게 하는 겹입니다. 여기서 한 일은 모두 감사 로그에 남습니다.
    </p>

    <section class="admin-hub">
      <a class="admin-card" href="/users">
        <span class="admin-card__icon"><span class="icon" data-icon="users"></span></span>
        <span class="admin-card__text">
          <strong class="admin-card__name">계정 관리</strong>
          <span class="admin-card__desc">계정 생성·역할 변경·비활성화, 임시 비밀번호 재발급, 2단계 인증 초기화</span>
        </span>
      </a>
      <a class="admin-card" href="/org">
        <span class="admin-card__icon"><span class="icon" data-icon="org"></span></span>
        <span class="admin-card__text">
          <strong class="admin-card__name">조직 · 권한</strong>
          <span class="admin-card__desc">팀 만들기·이름 변경·구성원 배정·비활성화. 권한 매트릭스 확인</span>
        </span>
      </a>
      <a class="admin-card admin-card--warn" href="/admin/permissions">
        <span class="admin-card__icon"><span class="icon" data-icon="lock"></span></span>
        <span class="admin-card__text">
          <strong class="admin-card__name">권한 매트릭스 편집</strong>
          <span class="admin-card__desc">역할 × 등급 표를 바꿉니다 — 사유와 2단계 인증 코드가 필요하고, 칸마다 감사 기록이 남습니다</span>
        </span>
      </a>
      <a class="admin-card" href="/audit">
        <span class="admin-card__icon"><span class="icon" data-icon="log"></span></span>
        <span class="admin-card__text">
          <strong class="admin-card__name">감사 로그</strong>
          <span class="admin-card__desc">누가·언제·어디서·무엇을·어떻게·왜 — 전체 기록 조회</span>
        </span>
      </a>
      <a class="admin-card" href="/trash">
        <span class="admin-card__icon"><span class="icon" data-icon="trash"></span></span>
        <span class="admin-card__text">
          <strong class="admin-card__name">휴지통</strong>
          <span class="admin-card__desc">삭제된 파일 복구 (30일 안)</span>
        </span>
      </a>
      <a class="admin-card" href="/wiki">
        <span class="admin-card__icon"><span class="icon" data-icon="shield"></span></span>
        <span class="admin-card__text">
          <strong class="admin-card__name">위키</strong>
          <span class="admin-card__desc">이 서비스를 어떻게 안전하게 만들었나 — 네 문서의 해설</span>
        </span>
      </a>
    </section>

    <section class="card card--pad">
      <div class="org-matrix__head">
        <div class="org-detail__text">
          <strong class="org-detail__name">공유 링크 전체</strong>
          <span class="muted">지금 밖에 나가 있는 링크입니다. 토큰은 어디에도 저장하지 않으므로 여기에도 없습니다.</span>
        </div>
      </div>
      <div class="table-wrap">
        <table class="files">
          <thead>
            <tr>
              <th scope="col">파일</th>
              <th scope="col">발급자</th>
              <th scope="col">상태</th>
              <th scope="col">받은 횟수</th>
              <th scope="col">만료</th>
            </tr>
          </thead>
          <tbody id="admin-shares"></tbody>
        </table>
      </div>
      <p id="admin-shares-empty" class="muted" hidden>발급된 공유 링크가 없습니다.</p>
    </section>

    <section class="card card--pad">
      <div class="org-matrix__head">
        <div class="org-detail__text">
          <strong class="org-detail__name">최근 경보</strong>
          <span class="muted">5분마다 도는 경보 배치(<code>scripts/alerts.php</code>)가 남긴 마지막 50건입니다.</span>
        </div>
      </div>
      <ul id="admin-alerts" class="alert-list"></ul>
      <p id="admin-alerts-empty" class="muted" hidden>기록된 경보가 없습니다.</p>
    </section>
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
