<?php
declare(strict_types=1);

/**
 * src/community/handlers/portfolio_page.php — GET /community/portfolios/{id} (정책: community.read) — **포트폴리오**
 *
 * 요구사항: 사이트 Q:F-12 WK-12 ③ · WK-13 ② · 기획 `../../../../community/work-ux-plan.md` 5.5, DESIGN.md 2.22 · 2.4 · 6장,
 *           커뮤니티 `../../../../community/ARCHITECTURE.md` 23.7, API.md 22-5.
 *
 * 이 파일은 **뼈대만 그린다.** 이름 · 상태별 수 · 표(이름 · 상태 · 진행률 · 담당 · 마감 · 최근 상태 글)는 work.js 가
 * `GET /api/portfolios/{id}` 의 답으로 채운다 — 그 창구가 `decidePortfolioRead` 로 판정하고(못 보면 404 같은 문구),
 * 항목 · 수 · 진행률은 **보는 사람의 검색 조건절 안에서만** 센다(볼 수 없는 프로젝트는 줄이 없다 · 「n개 숨김」 0).
 * 「프로젝트 넣기」 · 이름 바꾸기 · 지우기 · 빼기는 그 창구의 `can_manage` 가 참일 때만 work.js 가 연다(역할 비교 0).
 *
 * 「프로젝트 넣기」 고르개의 선택지는 이 파일이 서버에서 그린다 — `community_work_projects(…, projectsOnly)`: 넣는 사람의 검색 조건절 안
 * (관리자 특권 말고 볼 수 있는 것 — `decidePortfolioAdd` 와 같은 범위)의 프로젝트만. 넣을 수 있나는 창구가 다시 판정한다(PRD S-01).
 *
 * ★ HTML 로 나가는 동적 값은 포트폴리오 id(라우터가 UUID 만 통과) · 프로젝트 id · 제목뿐이고 전부 htmlspecialchars.
 * ★ 인라인 `<script>`·`style=`·`on*` 0.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 포트폴리오 public_id (UUID)
 */

$portfolioId = (string) $params[0];
$pageTitle   = '포트폴리오';
$activeNav   = '';
$pageKey     = 'community-overview';
$pageHelp    = 'community-today';
$pageCss     = ['community/community.css'];
$shellModule = 'community';
$pagePalette = community_palette_version();
$pfEsc       = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
// 넣기 고르개 — 쓰기 자격이 있을 때만 읽는다(손님은 넣을 수 없다 — 창구 정책 community.write)
$pfChoices   = Authz::canCommunityWrite($user) ? community_work_projects($user, 100, true, false)['items'] : [];
require __DIR__ . '/../../views/shell_top.php';
?>
    <div id="work-portfolio" class="work-stack" data-portfolio-id="<?= $pfEsc($portfolioId) ?>">
      <header class="page-head">
        <div class="page-head__text">
          <h1 id="portfolio-title" class="page-head__title">포트폴리오</h1>
          <p id="portfolio-sub" class="page-head__sub"></p>
        </div>
        <div class="page-head__actions">
          <button type="button" class="btn btn--quiet" data-help-open>
            <span class="icon" data-icon="help"></span><span>사용 방법</span>
          </button>
          <!-- 이름 바꾸기 · 지우기(맨 아래 빨강) — 창구의 can_manage 가 참일 때만 항목이 열린다. 모두 숨으면 메뉴째 숨는다(DESIGN 2.15) -->
          <details id="portfolio-more" class="menu">
            <summary class="btn btn--quiet"><span class="icon" data-icon="chevron"></span>더보기</summary>
            <div class="menu__list">
              <button id="portfolio-rename" class="menu__item" type="button" aria-haspopup="dialog" hidden><span class="icon" data-icon="edit"></span>이름 바꾸기</button>
              <hr class="menu__sep">
              <button id="portfolio-delete" class="menu__item menu__item--danger" type="button" hidden><span class="icon" data-icon="trash"></span>포트폴리오 지우기</button>
            </div>
          </details>
          <button id="portfolio-add" class="btn" type="button" aria-haspopup="dialog" hidden>
            <span class="icon" data-icon="plus"></span><span>프로젝트 넣기</span>
          </button>
        </div>
      </header>

      <p id="msg" class="msg" role="alert" aria-live="polite"></p>

      <!-- 안내(DESIGN 2.22 — 사람마다 숫자가 다를 수 있다) + 상태별 수(창구가 보는 사람 몫으로 센 것) -->
      <div class="notice notice--recommend" id="portfolio-info">
        <p>내가 볼 수 있는 프로젝트와 작업만 보여요. 진행률도 내가 볼 수 있는 작업만 세어서 사람마다 숫자가 다를 수 있어요.</p>
        <p id="portfolio-counts" class="toolbar" aria-label="상태별 프로젝트 수"></p>
      </div>

      <div class="table-wrap">
        <table id="portfolio-table" class="files">
          <thead>
            <tr><th scope="col">이름</th><th scope="col">상태</th><th scope="col">진행률</th><th scope="col">담당</th><th scope="col">마감</th><th scope="col">최근 상태 글</th><th scope="col" class="actions"><span class="narrow-hide">동작</span></th></tr>
          </thead>
          <tbody id="portfolio-rows"></tbody>
        </table>
      </div>
      <p id="portfolio-empty" class="muted" hidden>아직 넣은 프로젝트가 없어요.</p>
      <button id="portfolio-add-row" class="btn btn--quiet btn--small" type="button" aria-haspopup="dialog" hidden>
        <span class="icon" data-icon="plus"></span><span>프로젝트 넣기<span class="narrow-hide"> — 내가 볼 수 있는 프로젝트만 고를 수 있어요</span></span>
      </button>

      <!-- 「프로젝트 넣기」 창(DESIGN 2.7 `.modal--form` · 창 바닥 규칙) — 고르개는 넣는 사람이 볼 수 있는 프로젝트만(서버가 그렸다) -->
      <dialog id="portfolio-add-dialog" class="modal modal--form" aria-labelledby="portfolio-add-title">
        <div class="modal__head">
          <h2 id="portfolio-add-title" class="modal__title">프로젝트 넣기</h2>
          <button class="btn btn--icon btn--plain" type="button" data-portfolio-close aria-label="닫기"><span class="icon" data-icon="x"></span></button>
        </div>
        <form id="portfolio-add-form" class="modal__body form" method="dialog">
          <p class="help">내가 볼 수 있는 프로젝트만 고를 수 있어요. 넣어도 그 프로젝트를 볼 수 있는 사람은 바뀌지 않아요.</p>
          <label class="field field--full">
            <span class="field__label">프로젝트</span>
            <select id="portfolio-add-pick" required>
<?php foreach ($pfChoices as $pfP): ?>
              <option value="<?= $pfEsc($pfP['id']) ?>"><?= $pfEsc($pfP['title']) ?></option>
<?php endforeach; ?>
            </select>
          </label>
<?php if ($pfChoices === []): ?>
          <p class="muted">넣을 수 있는 프로젝트가 없어요. 먼저 프로젝트를 만들어 보세요.</p>
<?php endif; ?>
        </form>
        <div class="form-actions">
          <p id="portfolio-add-msg" class="msg" role="alert" aria-live="polite"></p>
          <button class="btn btn--quiet" type="button" data-portfolio-close>닫기</button>
          <button class="btn" type="submit" form="portfolio-add-form">넣기</button>
        </div>
      </dialog>

      <!-- 이름 바꾸기 창 -->
      <dialog id="portfolio-rename-dialog" class="modal modal--form" aria-labelledby="portfolio-rename-title">
        <div class="modal__head">
          <h2 id="portfolio-rename-title" class="modal__title">이름 바꾸기</h2>
          <button class="btn btn--icon btn--plain" type="button" data-portfolio-close aria-label="닫기"><span class="icon" data-icon="x"></span></button>
        </div>
        <form id="portfolio-rename-form" class="modal__body form" method="dialog">
          <label class="field field--full">
            <span class="field__label">포트폴리오 이름</span>
            <input id="portfolio-rename-input" type="text" maxlength="80" autocomplete="off" required>
          </label>
        </form>
        <div class="form-actions">
          <p id="portfolio-rename-msg" class="msg" role="alert" aria-live="polite"></p>
          <button class="btn btn--quiet" type="button" data-portfolio-close>닫기</button>
          <button class="btn" type="submit" form="portfolio-rename-form">바꾸기</button>
        </div>
      </dialog>
    </div>
<?php
$pageMsgTop = true;   // 알림 줄(#msg)은 이 화면이 머리 아래에 한 번 놓았다(id 중복 0)
require __DIR__ . '/../../views/shell_bottom.php';
