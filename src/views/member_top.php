<?php
declare(strict_types=1);
require_once __DIR__.'/../Design.php';
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
?>
<!doctype html><html lang="ko"<?= Http::themeAttr() ?><?= Design::attrs() ?>><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark">
<title><?= $e($memberTitle) ?> · 모아</title>
<?php if ($memberPage === 'welcome'): ?><link rel="manifest" href="<?= Http::url('/app.webmanifest') ?>"><script defer src="<?= Http::url('/assets/member-install.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/member-install.js') ?>"></script><?php endif; ?>
<link rel="stylesheet" href="<?= Http::url('/assets/app.css') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/app.css') ?>">
<?= Design::assets() ?>
<?php if ($memberPage === 'mcp-connect'): ?><link rel="stylesheet" href="<?= Http::url('/assets/mcp-connect.css') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/mcp-connect.css') ?>"><script defer src="<?= Http::url('/assets/mcp-connect.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/mcp-connect.js') ?>"></script><?php endif; ?>
<?php if ($memberPage === 'messages'): ?><script defer src="<?= Http::url('/assets/moa-local-bridge.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/moa-local-bridge.js') ?>"></script><script defer src="<?= Http::url('/assets/member-ai.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/member-ai.js') ?>"></script><script defer src="<?= Http::url('/assets/member-assist.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/member-assist.js') ?>"></script><?php endif; ?>
<?php /* Q:F-11 지시서 A-1 — 메신저 모듈. type="module" 은 defer 와 같은 때(문서를 다 읽은 뒤) 선언 순서대로 돈다 → member-ai·member-assist(defer) 뒤, member.js(defer) 앞. */ if ($memberPage === 'messages'): ?><script type="module" src="<?= Http::url('/assets/messenger.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/messenger.js') ?>"></script><?php endif; ?>
<?php if ($memberPage === 'welcome'): ?><script defer src="<?= Http::url('/assets/passkey.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/passkey.js') ?>"></script><?php endif; ?>
<script defer src="<?= Http::url('/assets/member.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/member.js') ?>"></script>
</head><body class="moa-site" data-member-page="<?= $e($memberPage) ?>" data-base="<?= TBOX_BASE ?>"<?= isset($joinCsrf)?' data-join-csrf="'.$e($joinCsrf).'"':'' ?>>
<a class="skip" href="#member-main">본문으로 건너뛰기</a>
<header class="moa-nav"><a class="moa-logo" href="/"><span class="moa-symbol" aria-hidden="true">m.</span>모아<span class="moa-logo-note">우리의 이야기가 모이는 곳</span></a><nav aria-label="주 메뉴"><a href="/">둘러보기</a><a href="<?= Http::communityUrl('/') ?>">커뮤니티</a><?php if($memberPage==='join'): ?><a href="<?= Http::url('/login?to=community') ?>">로그인</a><?php else: ?><a href="<?= Http::url('/messages') ?>">메신저</a><a href="<?= Http::url('/settings') ?>">내 계정</a><?php endif; ?></nav></header>
<main id="member-main" class="moa-member-main">
<?php if ($memberPage !== 'join'): require __DIR__ . '/usage_guide.php'; endif; ?>
