<?php
declare(strict_types=1);

/**
 * src/views/launcher.php — Qaredi 런처 화면 (Q:F-1, ARCH-launcher 5장, DESIGN.md 2.3 · 2.10 · 2.11 · 2.12)
 *
 * 셸(사이드바)을 쓰지 않는 **독립 화면**이다 — 로그인 화면(DESIGN 2.10)과 같은 층.
 * 화면 전용 클래스를 하나도 만들지 않는다(DESIGN 원칙 6): 배치는 `.app` + `.main`,
 * 머리는 `.page-head`, 타일은 `.card-grid` > `.card.card--link`(2.3), 「준비 중」은 `.badge`(2.2) 다.
 *
 * ★ `<script>` 0 · 인라인 `style=` 0 · `on*` 0 (CSP, DESIGN 원칙 2).
 * ★ 아이콘은 서버가 그리는 **인라인 SVG** 다 — 스크립트가 없어 `<template>` 복제를 못 쓴다.
 *   path 데이터는 `Apps::ICON_PATHS` 한 벌이고 전부 고정 문자열이다(요청 값 0).
 * ★ HTML 로 나가는 동적 값은 앱 목록(저장소 파일)과 사용자명·역할 둘뿐이고 전부
 *   htmlspecialchars(ENT_QUOTES, 'UTF-8') 를 거친다 (PRD S-03).
 *
 * 받는 변수
 *   · $launcherUser array|null  세션 사용자(없으면 null) — 읽기만 한 값
 *   · $launcherApps list<array> Apps::all()
 */

/** @var array<string,mixed>|null $launcherUser */
/** @var list<array<string,mixed>> $launcherApps */

$launcherEsc = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

/** 자산 주소에 파일이 마지막으로 바뀐 시각을 붙인다 (셸·로그인 화면과 같은 방식) */
$launcherCssVersion = (int) filemtime(__DIR__ . '/../../public/assets/app.css');

/*
 * 로그인 사용자 표시 (DESIGN.md 2.9 「자리 정정 2026-09-22」) — 배지 하나다.
 *
 * `.userchip` 을 쓰지 않는 이유: 그 구성 요소는 사이드바 **바닥**용이라 위 테두리와 위 여백을
 * 갖고 있어 화면 머리에서는 칩 위에 가로선이 보인다. 화면 머리에 들어가는 한 조각은
 * `.page-head__actions` 안의 `.badge`(2.2) 다 — 새 클래스를 만들지 않는다(원칙 6).
 *
 * 내용은 표시 이름과 **유효 역할** 둘뿐이다(데이터 0 — 사이트 PRD 4장).
 */
$launcherWho = '';
if (is_array($launcherUser)) {
    // 5-A″ — 「이름 (아이디)」, 이름이 없으면 「아이디」(bootstrap `tbox_person_label` 한 벌)
    $launcherNameRaw = tbox_person_label(
        isset($launcherUser['display_name']) && is_string($launcherUser['display_name']) ? $launcherUser['display_name'] : null,
        (string) ($launcherUser['username'] ?? '')
    );
    $launcherRoleRaw = (string) ($launcherUser['role'] ?? '');
    if ($launcherNameRaw !== '') {
        $launcherWho = $launcherEsc(
            $launcherRoleRaw === '' ? $launcherNameRaw : $launcherNameRaw . ' · ' . $launcherRoleRaw
        );
    }
}
?>
<!doctype html>
<html lang="ko"<?= Http::themeAttr() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>Qaredi</title>
<!-- 브라우저가 스스로 부르는 `/favicon.ico` 가 404 로 떨어지지 않게 문서 안에 둔다(셸과 같은 고정 문자열). -->
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%2314213d' d='M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5z'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= $launcherEsc(Http::url('/assets/app.css')) ?>?v=<?= $launcherCssVersion ?>">
</head>
<body>
<div class="app">
  <main class="main">
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">Qaredi</h1>
        <p class="page-head__sub">사내 업무 도구 모음입니다. 쓸 기능을 고르세요.</p>
      </div>
<?php if ($launcherWho !== ''): ?>
      <div class="page-head__actions">
        <span class="badge"><?= $launcherWho ?></span>
      </div>
<?php endif; ?>
    </header>

    <section class="card-grid">
<?php foreach ($launcherApps as $launcherApp): ?>
<?php   $launcherSoon = ($launcherApp['status'] ?? '') === 'soon'; ?>
<?php   $launcherIcon = Apps::ICON_PATHS[$launcherApp['icon']]; ?>
<?php   if ($launcherSoon): ?>
      <div class="card card--link card--soon">
<?php   else: ?>
      <a class="card card--link" href="<?= $launcherEsc($launcherApp['path']) ?>">
<?php   endif; ?>
        <span class="card__icon"><span class="icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= $launcherIcon ?>"/></svg></span></span>
        <span class="card__body">
          <span class="card__head">
            <strong class="card__title"><?= $launcherEsc($launcherApp['name']) ?></strong>
<?php   if ($launcherSoon): ?>
            <span class="badge">준비 중</span>
<?php   endif; ?>
          </span>
          <span class="card__desc"><?= $launcherEsc($launcherApp['desc']) ?></span>
        </span>
<?php   if ($launcherSoon): ?>
      </div>
<?php   else: ?>
      </a>
<?php   endif; ?>
<?php endforeach; ?>
    </section>
  </main>
</div>
</body>
</html>
