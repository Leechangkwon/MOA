<?php
declare(strict_types=1);

/**
 * src/community/handlers/my_tasks_page.php — GET /community/my-tasks (정책: community.read) — **내 작업**
 *
 * 요구사항: 사이트 Q:F-12 WK-4 · WK-8 · 기획 `../../../../community/work-ux-plan.md` 5.3 (b), DESIGN.md 2.20 · 2.21 · 6장,
 *           커뮤니티 `../../../../community/ARCHITECTURE.md` 23.4 · 23.5. 옛 「오늘」(`/community/today`)은 308 로 여기로 온다.
 *
 * 이 파일은 **뼈대만 그린다.** 묶음(지난 기한 · 오늘 · 이번 주 · 나중에 · 기한 없음)과 줄은 work.js 가 `GET /api/my-tasks` 의 답으로,
 * 오른쪽 작업 상세 칸(`aside.task-pane` — 이 화면에서는 「‹ 목록」 · 전체 화면 규칙이 프로젝트 화면과 같다)은 `GET /api/pages/{id}` 로 채운다.
 * 그 자리 추가(「＋ 작업 추가」)는 「내 할 일」 빠른 추가(`POST /api/pages {my_tasks:true}` — 처음이면 서버가 「내 할 일」 프로젝트를 만든다).
 *
 * ★ 판단하지 않는다 — 목록은 창구의 검색 조건절 안, 쓰는 단추는 `Authz::canCommunityWrite`(`/api/me` 와 같은 함수) 거짓이면 그리지 않는다.
 * ★ 동적 값 0(고정 글자뿐) · 인라인 `<script>`·`style=`·`on*` 0.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle   = '내 작업';
$activeNav   = 'community-today';
$pageKey     = 'community-today';
$pageHelp    = 'community-today';
$pageCss     = ['community/community.css'];
$shellModule = 'community';
$pagePalette = community_palette_version();
$workWrite   = Authz::canCommunityWrite($user);
require __DIR__ . '/../../views/shell_top.php';
?>
    <div id="work-my-tasks" class="work-stack">
      <header class="page-head">
        <div class="page-head__text">
          <h1 class="page-head__title">내 작업</h1>
          <p class="page-head__sub">나에게 맡겨진 작업과, 맡긴 사람이 없는 내 작업 중 상태나 기한이 있는 것이에요.</p>
        </div>
        <div class="page-head__actions">
          <button type="button" class="btn btn--quiet" data-help-open>
            <span class="icon" data-icon="help"></span><span>사용 방법</span>
          </button>
<?php if ($workWrite): ?>
          <button id="my-tasks-add" class="btn" type="button" data-work-new-task aria-haspopup="dialog">
            <span class="icon" data-icon="plus"></span><span>작업 추가</span>
          </button>
<?php endif; ?>
        </div>
      </header>

      <!-- 보기 탭 — 목록 · 보드 · 달력(지금 데이터베이스 보기 탭과 같은 모양 · 같은 클래스). 「끝난 작업」은 같은 창구의 `done=1` -->
      <div class="toolbar db-viewbar" role="group" aria-label="보는 모양">
        <button class="btn btn--quiet btn--small db-type is-active" type="button" data-my-view="list" aria-pressed="true">목록</button>
        <button class="btn btn--quiet btn--small db-type" type="button" data-my-view="board" aria-pressed="false">보드</button>
        <button class="btn btn--quiet btn--small db-type" type="button" data-my-view="calendar" aria-pressed="false">달력</button>
        <span class="editor-toolbar__sep" aria-hidden="true"></span>
        <button id="my-tasks-done" class="btn btn--quiet btn--small db-type" type="button" aria-pressed="false">끝난 작업 보기</button>
      </div>

      <p id="msg" class="msg" role="alert" aria-live="polite"></p>

      <!-- 목록(DESIGN 2.20) — 묶음 · 줄은 work.js 가 그린다. 칸: 이름 · 프로젝트 · 마감 -->
      <section id="my-tasks-list" class="task-list panel" aria-label="내 작업 목록">
        <div class="task-list__head" aria-hidden="true"><span>이름</span><span>프로젝트</span><span>마감</span></div>
        <div id="my-tasks-groups"></div>
        <p id="my-tasks-empty" class="muted" hidden></p>
      </section>
      <!-- 보드 — 묶음을 열로(끌어 옮기지 않는다 — 묶음은 기한이 정한다) · 달력 — 기한 날짜 칸(calendar.js 그대로) -->
      <div id="my-tasks-board" class="db-board" hidden></div>
      <div id="my-tasks-calendar" class="panel" hidden></div>
    </div>
    <!-- 작업 상세 칸(DESIGN 2.21) — 모달이 아니라 화면의 한 칸(aside). 안쪽은 work.js 가 창구 답으로 createElement/textContent 로 그린다.
         주소 `?task={public_id}` 가 칸을 연다(뒤로 가기로 앞 작업 · 닫힘). -->
    <aside id="task-pane" class="task-pane" aria-label="작업 상세" hidden></aside>
<?php
$pageMsgTop = true;   // 알림 줄(#msg)은 이 화면이 머리 아래에 한 번 놓았다(id 중복 0)
require __DIR__ . '/../../views/shell_bottom.php';
