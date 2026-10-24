<?php
declare(strict_types=1);

/**
 * src/handlers/legal_page.php — GET /privacy · GET /terms (사이트 Q:F-13 P1 · 기획 ../docs/privacy-terms-plan.md 5.2 「공개 화면」)
 *
 * 정책 **public** — 런처와 같은 공개 화면 규칙이다.
 *   · 세션을 **읽지 않는다**(Auth::currentUser 를 부르지 않는다) — 로그인 전·후 · 계정이 무엇이든 응답이 한 벌이고 개인 데이터 0.
 *     그래서 `Set-Cookie` 도 0 이다(옛 이름 쿠키를 치우는 머리조차 붙지 않는다 — 그 머리는 세션을 읽을 때만 나온다).
 *   · 스크립트 0(Design::assets(false)) · 인라인 style·script 0 · CSP 는 부트스트랩 것 그대로.
 *   · 어느 문서인가는 **라우트 패턴**(TBOX_ROUTE)으로만 고른다. 이전 판 `?v=` 는 목록(config/legal.json)에 글자 그대로 있는 판만 —
 *     그 밖(없는 날짜 · `../x` · 배열)은 404. 읽는 파일은 언제나 목록이 준 이름이다(Legal::entry → Legal::html — PRD S-06).
 *   · 문서 조각은 저장소의 정적 HTML(사용자 입력 0)이라 그대로 싣는다. 그 밖의 값(판 글자)은 전부 htmlspecialchars.
 */

require_once __DIR__ . '/../Legal.php';
require_once __DIR__ . '/../Design.php';

$legalDoc = TBOX_ROUTE === 'GET /terms' ? 'terms' : 'privacy';
$legalOther = $legalDoc === 'terms' ? 'privacy' : 'terms';
$legalAsked = $_GET['v'] ?? null;
$legalEntry = $legalAsked === null ? Legal::entry($legalDoc)
    : (is_string($legalAsked) ? Legal::entry($legalDoc, $legalAsked) : null);
if ($legalEntry === null) {
    require_once __DIR__ . '/../site/not_found_page.php';
    qaredi_not_found(false);
}
$legalCurrent = Legal::entry($legalDoc);
$legalIsCurrent = $legalEntry['version'] === $legalCurrent['version'];
$legalBody = Legal::html($legalEntry);
$legalVersions = array_reverse(Legal::catalog()[$legalDoc]);
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$legalHref = static fn (string $doc): string => Http::url('/' . $doc);

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
}
?>
<!doctype html>
<html lang="ko"<?= Http::themeAttr() ?><?= Design::attrs() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= $e(Legal::LABELS[$legalDoc]) ?> · 모아</title>
<link rel="icon" type="image/svg+xml" href="<?= Http::url('/assets/moa-favicon.svg') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/moa-favicon.svg') ?>">
<link rel="stylesheet" href="<?= Http::url('/assets/app.css') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/app.css') ?>">
<?= Design::assets(false) ?>
</head>
<body class="moa-site" data-legal-doc="<?= $e($legalDoc) ?>">
<a class="skip" href="#legal-main">본문으로 건너뛰기</a>
<header class="moa-nav"><a class="moa-logo" href="/"><?php $moaWordmarkLogo=true; require __DIR__.'/../views/moa_wordmark.php'; /* H4 (DESIGN 2.33) */ ?><span class="moa-logo-note">우리 회사의 일과 자료를 한곳에 모아.</span></a><nav aria-label="약관과 방침"><a href="<?= $e($legalHref('privacy')) ?>"<?= $legalDoc === 'privacy' ? ' aria-current="page"' : '' ?>><strong>개인정보 처리방침</strong></a><a href="<?= $e($legalHref('terms')) ?>"<?= $legalDoc === 'terms' ? ' aria-current="page"' : '' ?>>이용약관</a><a href="/">모아 홈</a></nav></header>
<main id="legal-main" class="moa-member-main">
<article class="wiki-article" aria-labelledby="legal-title">
<header class="wiki-head">
<h1 class="wiki-title" id="legal-title"><?= $e(Legal::TITLES[$legalDoc]) ?></h1>
<p class="wiki-summary">시행일 <span data-legal-version><?= $e($legalEntry['version']) ?></span><?= $legalIsCurrent ? ' · 지금 판입니다' : ' · 이전 판입니다' ?></p>
<?php if (!$legalIsCurrent): ?>
<p class="wiki-meta"><a href="<?= $e($legalHref($legalDoc)) ?>">지금 판(시행일 <?= $e($legalCurrent['version']) ?>) 보기</a></p>
<?php endif; ?>
<?php if (count($legalVersions) > 1): ?>
<nav class="wiki-meta" aria-label="판 목록"><span class="muted">판:</span>
<?php foreach ($legalVersions as $legalItem): ?>
<a href="<?= $e($legalHref($legalDoc)) ?>?v=<?= $e($legalItem['version']) ?>"<?= $legalItem['version'] === $legalEntry['version'] ? ' aria-current="page"' : '' ?>><?= $e($legalItem['version']) ?></a>
<?php endforeach; ?>
</nav>
<?php else: ?>
<p class="wiki-meta muted">이전 판이 없습니다.</p>
<?php endif; ?>
</header>
<?= $legalBody ?>
<p class="wiki-meta"><a href="<?= $e($legalHref($legalOther)) ?>"><?= $legalOther === 'privacy' ? '<strong>개인정보 처리방침</strong>' : '이용약관' ?> 보기</a></p>
</article>
</main>
<footer class="moa-footer"><a class="moa-logo" href="/"><?php require __DIR__.'/../views/moa_wordmark.php'; ?><span>moa</span></a><p class="legal-links"><a href="<?= $e($legalHref('privacy')) ?>"><strong>개인정보 처리방침</strong></a> · <a href="<?= $e($legalHref('terms')) ?>">이용약관</a></p></footer>
</body>
</html>
