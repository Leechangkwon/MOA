<?php
declare(strict_types=1);

/**
 * src/community/handlers/work_home_page.php — GET /community/ (정책: community.read) — **업무 홈**(첫 화면)
 *
 * 요구사항: 사이트 Q:F-12 WK-7 ② · WK-8 ② · 기획 `../../../../community/work-ux-plan.md` 5.3 (a),
 *           DESIGN.md 2.19 ~ 2.22 · 6장, 커뮤니티 `../../../../community/ARCHITECTURE.md` 23.4 · 23.8.
 *
 * 화면(위에서 아래로): 인사(화면 머리) → 업무함 앞 10줄(Q:F-12 U2 · DESIGN 2.20-A — `GET /api/my-tasks` + `GET /api/briefing`, work.js) →
 * 내 프로젝트(진행률 — 이 파일이 서버에서 그린다) → 안 읽은 공지 한 줄(`GET /api/briefing` 의 `others` 공지, work.js) · 작업 상세 칸(aside — 2.21).
 *
 * ★ 옛 둘러보기(overview_page.php — 없앴다)의 넘김을 **그대로** 이어받는다(WK-15 A — 즐겨찾기·옛 링크 보존):
 *   `?view=mine|feed|shared|discover` → 스레드 화면(이야기 셸) · 문서 공간 꼬리표(`space`·`space_id`·`q`·`trash`·`page`·`database`) → 「내 문서」.
 * ★ 이 화면은 판단하지 않는다 — 「내 프로젝트」는 `community_work_projects()`(보는 사람의 검색 조건절 안에서만 · 판정 0),
 *   쓰는 단추(「작업 추가」)는 `Authz::canCommunityWrite`(= `/api/me` 의 `can_community_write`)가 거짓이면 그리지 않는다(역할 비교 0).
 * ★ HTML 로 나가는 동적 값(표시 이름 · 프로젝트 제목 · id · 색 이름 · 진행 수)은 전부 htmlspecialchars 를 거친다(PRD S-03).
 *   색은 허용 목록 넷(`DbProps::PROJECT_COLORS`)만 클래스가 되고, 진행 폭은 10% 계단 클래스(인라인 style 0 — DESIGN 2.22).
 * ★ 인라인 `<script>`·`style=`·`on*` 0 (CSP `script-src 'self'`).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

// 옛 스레드 책갈피(`/community/?view=…`)는 전처럼 스레드 화면으로(이야기 셸 — memories_page.php 가 그린다)
if (is_string($_GET['view'] ?? null) && in_array($_GET['view'], ['mine', 'feed', 'shared', 'discover'], true)) {
    require __DIR__ . '/memories_page.php';
    return;
}
// 옛 문서 공간 링크(`/community/?space=…` 등)는 「내 문서」 화면 그대로
if (array_intersect(array_keys($_GET), ['space', 'space_id', 'q', 'trash', 'page', 'database'])) {
    require __DIR__ . '/home_page.php';
    return;
}

$pageTitle   = '홈';
$activeNav   = 'community-overview';
$pageKey     = 'community-overview';
$pageCss     = ['community/community.css'];
$shellModule = 'community';
$pagePalette = community_palette_version();

$workWrite = Authz::canCommunityWrite($user);
$workName  = isset($user['display_name']) && is_string($user['display_name']) && $user['display_name'] !== ''
    ? $user['display_name'] : (string) $user['username'];
$workNow   = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$workDay   = $workNow->format('Y-m-d') . ' (' . ['', '월', '화', '수', '목', '금', '토', '일'][(int) $workNow->format('N')] . ')';
$workProjects = community_work_projects($user, 24, false, true);
$workEsc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

require __DIR__ . '/../../views/shell_top.php';
?>
    <div id="work-home" class="work-stack">
      <header class="page-head" id="work-home-welcome">
        <div class="page-head__text">
          <h1 id="work-home-title" class="page-head__title">안녕하세요, <?= $workEsc($workName) ?> 님</h1>
          <p class="page-head__sub"><?= $workEsc($workDay) ?> · 오늘 할 일과 프로젝트를 한눈에 봐요.</p>
        </div>
        <div class="page-head__actions">
          <button type="button" class="btn btn--quiet" data-usage-guide-open>
            <span class="icon" data-icon="help"></span><span>사용 방법</span>
          </button>
          <a id="work-home-docs" class="btn btn--quiet" href="<?= Http::communityUrl('/workspace') ?>">
            <span class="icon" data-icon="files"></span><span>내 문서</span>
          </a>
<?php if ($workWrite): ?>
          <button id="work-home-add" class="btn" type="button" data-work-new-task aria-haspopup="dialog">
            <span class="icon" data-icon="plus"></span><span>작업 추가</span>
          </button>
<?php endif; ?>
        </div>
      </header>

      <p id="msg" class="msg" role="alert" aria-live="polite"></p>

      <!-- 업무함 앞 10줄 (Q:F-12 U2 · WK-20 C · DESIGN 2.20-A) — 전엔 세 칸(지난 기한 · 오늘 · 이번 주)이던 자리. 중요 순 한 줄 흐름(지난 기한 → 오늘 →
           검토 요청 · 멘션 → 이번 주 → 나중에 → 기한 없음 · 묶음 안 ★ 먼저)을 work.js 가 `GET /api/my-tasks` + `GET /api/briefing` 두 창구의 답을
           **잇기만** 해(buildInbox — 보는 사람의 검색 조건절 안 · 화면이 거르거나 더하지 않는다) createElement/textContent 로 채운다. 11번째 줄부터는
           「모두 보기」(내 작업). 줄을 누르면 ≥1200 은 그 줄 아래 펼침, 그보다 좁으면 아래 작업 상세 칸(aside — 2.21)이 목록을 대신한다. -->
      <section id="work-home-tasks" class="panel" aria-labelledby="work-home-tasks-title">
        <div class="toolbar">
          <h2 id="work-home-tasks-title" class="panel__title">내 작업</h2>
          <a id="work-home-all" class="btn btn--quiet btn--small" href="<?= Http::communityUrl('/my-tasks') ?>">모두 보기</a>
        </div>
        <section class="task-list task-inbox" aria-label="업무함 — 중요 순">
          <ul id="work-home-rows" class="task-list__rows"></ul>
          <p id="work-home-empty" class="muted" hidden></p>
        </section>
      </section>

      <!-- 내 프로젝트 (기획 5.3 (a) · DESIGN 2.22 진행률) — 서버가 그린다: 보는 사람의 검색 조건절 안의 프로젝트만(즐겨찾기 → 최근 본 것 → 최근 고친 것),
           진행률도 그 사람이 볼 수 있는 작업만 센다(사람마다 숫자가 다를 수 있다). 폭은 10% 계단 클래스 — 인라인 style 0. -->
      <section id="work-projects" class="panel" aria-labelledby="work-projects-title">
        <div class="toolbar">
          <h2 id="work-projects-title" class="panel__title">내 프로젝트</h2>
<?php if ($workWrite): ?>
          <button id="work-home-new-project" class="btn btn--quiet btn--small" type="button" data-work-new-project aria-haspopup="dialog">
            <span class="icon" data-icon="plus"></span><span>프로젝트</span>
          </button>
<?php endif; ?>
        </div>
<?php if ($workProjects['items'] === []): ?>
        <p class="muted"><?= $workWrite ? '아직 프로젝트가 없어요. 「＋ 프로젝트」로 섹션과 보기가 갖춰진 프로젝트를 만들어 보세요.' : '볼 수 있는 프로젝트가 없어요.' ?></p>
<?php else: ?>
        <div class="card-grid">
<?php   foreach ($workProjects['items'] as $workP): ?>
<?php     $workDone = (int) $workP['progress']['done']; $workTotal = (int) $workP['progress']['total']; ?>
          <a class="card card--link" href="<?= Http::communityUrl('/p/') ?><?= $workEsc($workP['id']) ?>">
            <span class="task-color task-color--lg<?= $workP['color'] === null ? '' : ' moa-' . $workEsc($workP['color']) ?>" aria-hidden="true"></span>
            <span class="card__body">
              <span class="card__title"><?= $workEsc($workP['title']) ?></span>
              <span class="progress-line">
                <span class="progress <?= community_progress_step($workDone, $workTotal) ?>" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $workTotal ?>" aria-valuenow="<?= $workDone ?>" aria-label="완료 <?= $workDone ?>/<?= $workTotal ?>"></span>
                <span class="progress__text"><?= $workDone ?>/<?= $workTotal ?></span>
              </span>
            </span>
          </a>
<?php   endforeach; ?>
        </div>
<?php   if ($workProjects['more']): ?>
        <p class="help">더 많은 프로젝트는 검색(Ctrl K)으로 찾을 수 있어요.</p>
<?php   endif; ?>
<?php endif; ?>
        <p class="help">진행률은 내가 볼 수 있는 작업만 세요 — 사람마다 숫자가 다를 수 있어요.</p>
      </section>

      <!-- 안 읽은 공지 한 줄 (WK-8 ② — 공지는 작업 칸에 섞이지 않는다) — work.js 가 `GET /api/briefing` 의 `others` 중 공지 한 줄(게시판 읽기 판정 그대로)로
           채우고, 없으면 숨은 채다. -->
      <p id="work-home-notice" class="notice notice--recommend" hidden>
        <span class="badge">공지</span>
        <a id="work-home-notice-link" href="<?= Http::communityUrl('/') ?>"></a>
      </p>
    </div>
    <!-- 작업 상세 칸(DESIGN 2.21 — Q:F-12 U2: 업무함 줄을 1200px 미만에서 누르면 이 칸이 목록을 대신한다 · 내 작업 화면과 같은 aside 한 칸).
         ≥1200 에서는 줄 아래 펼침이라 쓰지 않는다. 안쪽은 work.js 가 창구 답으로 createElement/textContent 로 그린다. 주소 `?task={public_id}`. -->
    <aside id="task-pane" class="task-pane" aria-label="작업 상세" hidden></aside>
<?php
$pageMsgTop = true;   // 알림 줄(#msg)은 이 화면이 머리 아래에 한 번 놓았다(id 중복 0)
require __DIR__ . '/../../views/shell_bottom.php';
