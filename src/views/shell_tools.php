<?php
declare(strict_types=1);

/**
 * src/views/shell_tools.php — 본문 오른쪽 위 셋(메신저 · 앱 · 내 계정)과 통합 검색 창 (DESIGN 2.30 · 2.31 · 사이트 H1 2026-10-14)
 *
 * 셸(`shell_top.php`)과 로그인한 모아 홈(`launcher.php` 의 `$launcherLive` 갈래)이 **이 한 파일**을 require 한다 — 마크업 두 벌 0.
 * 전에는 shell_top.php 안에 있던 `div.shell-tools` 와 `dialog#search-dialog`(사이드바 안 — search.js 가 열 때 본문 끝으로 옮긴다)를 그대로 옮겼다.
 *
 * 받는 변수 (부르는 쪽이 require 전에 정한다 — 전부 서버 값 · 요청 값 0)
 *   · $shellMessenger  bool    메신저 창을 쓸 수 있나(판정 함수 Authz::canCommunityWrite · 「역할로 보기」 아님 — 부르는 쪽이 계산)
 *   · $shellUser       array   지금 사용자(DB 에서 읽은 값)
 *   · $shellApps       array   Apps::all() — 앱 판의 타일 순서
 *   · $shellCurrentApp string  aria-current 가 붙는 앱 key — 셸은 'tbox' · 'community' · 'story', 모아 홈은 'home'(「모아 홈」 타일)
 *   · $shellActive     string  셸 내비 키(내 계정 · 내 활동 타일의 aria-current) — 모아 홈은 ''
 *   · $shellMeLabel · $shellInitial · $shellRole  이미 htmlspecialchars 를 거친 이름 · 이니셜 · 역할 글자
 *
 * ★ 역할 비교 0 · 인라인 style · script · on* 0(CSP). 관리자 타일은 hidden 으로 나가고 app.js 가 `/api/me` 의 can_admin(표시용)으로 연다.
 * ★ 아이콘 자리(`[data-icon]`)는 icon_templates.php 의 <template> 을 app.js paintIcons 가 붙인다(모아 홈도 같은 두 파일을 싣는다).
 */
?>
    <!-- ─── 앱 격자 (사이트 Q:F-12 U2 · WK-23 B · DESIGN 2.18) ────────────────────────────────────────────────────
         본문 오른쪽 위 ⋮⋮⋮ — 앱 바꾸기 · 계정 · 관리자 · 검색 · 로그아웃을 **한 메뉴**에. 2026-09-23 의 앱 메뉴(`details.appbar__apps` — 클래스 이름은
         시험 Q:T-8 · 문서가 쓰므로 유지)를 사이드바 브랜드 줄에서 **이 자리로 옮겼다** — 문서에 하나뿐이다(두 벌 금지). 세 앱 셸 모두 이 마크업 한 벌.
         스크립트 0 으로 열린다(`<details>`) · 바깥 누름 · Esc · 항목 누름으로 닫는 것은 app.js initMenus 한 곳. 960px 미만은 CSS 가 이 줄을 숨기고
         윗줄 앱 이름 ▾(`#appbar-apps`)가 같은 details 를 연다(판은 윗줄 아래 전체 폭). 앱 목록은 런처와 **같은** `Apps::all()`(soon 은 `.is-soon`).
         관리자 페이지(`#menu-admin`)는 hidden 으로 나가고 `/api/me` 의 can_admin(표시용) 이 참일 때만 app.js 가 연다 — 막는 것은 라우트 정책 · 재인증 관문(PRD S-01).
         메신저 타일은 떠 있는 창을 여는 단추(messenger.js 가 모든 `[data-messenger-open]` 을 연다) — 메신저 자격이 없으면(손님 · 역할로 보기) 이용 안내 링크.
         ⋮⋮⋮ 의 점은 안 읽은 메시지 수(`[data-message-unread]` — messenger.js 의 같은 폴링 한 벌이 숫자를 넣고 CSS 가 점으로 그린다 · 새 요청 0).
         검색 타일은 사이드바 「검색」(#nav-search)과 같은 창을 연다(app.js — 같은 단추를 누른다 · 창 두 벌 0). 인라인 style·on* 0 (CSP).
         Q:F-12 U5-2(DESIGN 2.28) — ⋮⋮⋮ 에 글자 「앱」(아이콘 20 + 14px · 높이 40 · 테두리 단추)을 보이고, 이니셜 원은 **사용자 칩**(아바타 32 + 표시 이름 + 「님」 ·
         최대 폭 넘으면 말줄임 · 누르면 내 계정)으로. 이름은 위 `$shellMeLabel`(이스케이프 한 번) 그대로 — 새 동적 값 0 · 판정 0. 접근 이름은 aria-label 그대로라
         보이는 글자는 aria-hidden(두 번 읽히지 않게). 폰(<960)은 이 줄을 지금처럼 숨긴다.
         카페 C6(DESIGN 2.30 — 사용자 지적 2026-10-14) — 줄 = 메신저 단추 · 앱 단추 · 내 계정 칩. 앱 판은 앱과 도구만(메신저 · 내 계정 · 내 활동 타일과 로그아웃 줄은 뺐다) ·
         메신저는 앱 단추 왼쪽 단추로 · 내 계정 · 내 활동 · 로그아웃은 이름 칩이 여는 내 계정 판(앱 판과 같은 부품)으로 옮겼다(요소는 옮김 — 두 벌 0). -->
    <div class="shell-tools">
<?php if ($shellMessenger): ?>
      <!-- 카페 C6 (DESIGN 2.30 「메신저 단추」) — 메신저는 다른 화면으로 가는 길이 아니라 전역 떠 있는 창(2.17)이라 앱 판에서 빼고 앱 단추 왼쪽에 따로.
           id · data-messenger-open · aria-haspopup · aria-controls · aria-expanded 는 옛 타일의 것을 그대로 옮겼다(messenger.js 가 모든 [data-messenger-open] 을 연다/닫는다).
           안 읽은 수(`[data-message-unread]`)는 앱 단추에서 이 단추 오른쪽 위로 옮겼다(숫자 · 99+ — 같은 폴링 한 벌). -->
      <button id="messenger-open" class="btn btn--quiet shell-tools__messenger" type="button" data-messenger-open aria-haspopup="dialog" aria-controls="messenger-window" aria-expanded="false"><span class="icon" data-icon="messages"></span><span>메신저</span><span class="badge badge--internal" data-message-unread hidden></span></button>
<?php elseif (!empty($shellUser)): ?>
      <!-- 메신저를 못 쓰는 사람(손님 · 역할로 보기) — 같은 모양의 「메신저 이용 안내」 링크 단추(messenger-access.js 가 안내 창으로 연다) -->
      <a class="btn btn--quiet shell-tools__messenger" href="<?= Http::url('/messages') ?>" aria-label="메신저 이용 안내"><span class="icon" data-icon="messages"></span><span aria-hidden="true">메신저</span></a>
<?php endif; ?>
      <details class="appbar__apps" id="app-grid">
        <summary class="btn btn--quiet" aria-label="앱 — 앱 바꾸기">
          <span class="icon" data-icon="apps"><?php /* 카페 C3 (DESIGN 2.28 「앱 단추」) — 채움 아이콘 apps(Apps::ICON_PATHS · moa_icon.php 한 벌). 아이콘 틀(shell_bottom)에 없는 키라 이 자리에서 그린다 — 채운 자리는 app.js paintIcons 가 건너뛴다 */ $moaIcon = 'apps'; require __DIR__ . '/moa_icon.php'; ?></span><span aria-hidden="true">앱</span>
        </summary>
        <div class="panel app-menu">
          <ul class="app-menu__apps">
            <li><a class="app-menu__app" href="/"<?= $shellCurrentApp === 'home' ? ' aria-current="page"' : '' ?>><span class="app-menu__icon icon" data-icon="home"></span><span class="app-menu__text"><span class="app-menu__name">모아 홈</span><span class="app-menu__desc">이야기와 풍경이 모이는 곳</span></span></a></li>
<?php foreach ($shellApps as $shellApp): ?>
<?php   $shellAppName = htmlspecialchars((string) $shellApp['name'], ENT_QUOTES, 'UTF-8'); ?>
<?php   $shellAppDesc = htmlspecialchars((string) ($shellApp['desc'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
<?php   $shellAppIcon = htmlspecialchars((string) ($shellApp['icon'] ?? 'grid'), ENT_QUOTES, 'UTF-8'); ?>
<?php   if (($shellApp['status'] ?? '') === 'soon'): ?>
            <li><span class="app-menu__app is-soon"><span class="app-menu__icon icon" data-icon="<?= $shellAppIcon ?>"></span><span class="app-menu__text"><span class="app-menu__name"><?= $shellAppName ?></span><span class="app-menu__desc"><?= $shellAppDesc ?></span></span><span class="badge">준비 중</span></span></li>
<?php   else: ?>
            <li><a class="app-menu__app" href="<?= htmlspecialchars((string) $shellApp['path'], ENT_QUOTES, 'UTF-8') ?>"<?= $shellApp['key'] === $shellCurrentApp ? ' aria-current="page"' : '' ?>><span class="app-menu__icon icon" data-icon="<?= $shellAppIcon ?>"></span><span class="app-menu__text"><span class="app-menu__name"><?= $shellAppName ?></span><span class="app-menu__desc"><?= $shellAppDesc ?></span></span></a></li>
<?php   endif; ?>
<?php endforeach; ?>
            <li><a id="menu-admin" class="app-menu__app" href="<?= Http::url('/admin') ?>" hidden><span class="app-menu__icon icon" data-icon="lock"></span><span class="app-menu__text"><span class="app-menu__name">관리자 페이지</span><span class="app-menu__desc">계정 · 조직 · 감사</span></span></a></li>
            <li><button class="app-menu__app" type="button" data-search-tile aria-haspopup="dialog" aria-controls="search-dialog"><span class="app-menu__icon icon" data-icon="search"></span><span class="app-menu__text"><span class="app-menu__name">검색</span><span class="app-menu__desc">Ctrl K — 페이지 · 댓글 · 파일</span></span></button></li>
          </ul>
        </div>
      </details>
      <!-- 카페 C6 (DESIGN 2.30 「내 계정 칩」 · 「내 계정 판」) — 이름 칩은 링크가 아니라 내 계정 판의 summary. 판은 앱 판과 **같은 부품 · 같은 클래스**
           (`.panel.app-menu` · 같은 폭 · 같은 그림자): 머리 한 줄(아바타 40 + 표시 이름 + 아이디 · 역할) → 타일 내 계정 · 내 활동(지금 그 화면이면 aria-current) →
           「로그아웃」 줄(`#logout` — 앱 판에서 옮김 · 같은 함수가 붙는다). H1(DESIGN 2.31 「테마」) — 내 계정 · 내 활동 다음 타일 「테마」(`data-design-open` — 사이드바 바닥 단추와 같은 테마 창 q-design-dialog · design.js). 열고 닫기 · 하나만 열림 · 초점 되돌림은 앱 판과 같은 함수(app.js initMenus).
           동적 값은 위에서 이스케이프한 이름 · 이니셜 · 아이디 · 역할 글자뿐 · 역할 비교 0. -->
      <details class="shell-account" id="account-menu">
        <summary class="shell-tools__me" aria-label="내 계정 메뉴 — <?= $shellMeLabel ?>"><span aria-hidden="true"><?= $shellInitial ?></span><span class="shell-tools__name" aria-hidden="true"><span><?= $shellMeLabel ?></span>님</span><span class="icon" data-icon="chevron"></span></summary>
        <div class="panel app-menu">
          <div class="app-menu__head"><span aria-hidden="true"><?= $shellInitial ?></span><span><strong><?= $shellMeLabel ?></strong><span><?= htmlspecialchars((string) ($shellUser['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= $shellRole ?></span></span></div>
          <ul class="app-menu__apps">
            <li><a id="menu-settings" class="app-menu__app" href="<?= Http::url('/settings') ?>"<?= $shellActive === 'settings' ? ' aria-current="page"' : '' ?>><span class="app-menu__icon icon" data-icon="settings"></span><span class="app-menu__text"><span class="app-menu__name">내 계정</span><span class="app-menu__desc">비밀번호 · 보안 · 화면 설정</span></span></a></li>
            <li><a id="menu-activity" class="app-menu__app" href="<?= Http::url('/activity') ?>"<?= $shellActive === 'activity' ? ' aria-current="page"' : '' ?>><span class="app-menu__icon icon" data-icon="log"></span><span class="app-menu__text"><span class="app-menu__name">내 활동</span><span class="app-menu__desc">내가 한 일 기록</span></span></a></li>
            <li><button class="app-menu__app" type="button" data-design-open aria-haspopup="dialog" aria-controls="q-design-dialog"><span class="app-menu__icon icon" data-icon="sun"></span><span class="app-menu__text"><span class="app-menu__name">테마</span><span class="app-menu__desc">화면 색 · 모양 · 움직임</span></span></button></li>
          </ul>
          <button id="logout" class="app-menu__logout" type="button"><span class="icon" data-icon="logout"></span><span>로그아웃</span></button>
        </div>
      </details>
    </div>
    <!-- 통합 검색 창 (Q:F-2 두 앱 공통 · search.js) — 사이드바 「검색」 · Ctrl+K · 앱 판 「검색」 타일 · 모아 홈 검색 칸(H1 — `qarediSearchOpen(글자)`)이 모두 이 창 하나를 연다.
         창에 커뮤니티 기준 주소를 싣는다(t-box 화면의 <body> 에는 커뮤니티 기준이 없다). 값은 고정 상수다. -->
    <dialog id="search-dialog" class="modal modal--form search-dialog" aria-label="통합 검색" data-community-base="<?= COMMUNITY_BASE ?>">
      <div class="search-dialog__bar">
        <span class="icon" data-icon="search"></span>
        <input id="search-input" type="search" maxlength="100" autocomplete="off" spellcheck="false"
               placeholder="찾기 — 페이지·댓글·파일" aria-label="찾을 말" aria-controls="search-results">
        <button class="btn btn--small btn--quiet" type="button" data-search-close>닫기</button>
      </div>
      <ul id="search-results" class="search-results" role="listbox" aria-label="검색 결과"></ul>
      <p id="search-note" class="search-dialog__note muted" role="status"></p>
    </dialog>
