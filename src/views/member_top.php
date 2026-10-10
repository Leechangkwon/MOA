<?php
declare(strict_types=1);
require_once __DIR__.'/../Design.php';
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
// 사이트 Q:F-11 (지시서 B-1) — 떠 있는 메신저 창을 넣는 화면(`$memberWindow = true` — 지금은 시작 안내 하나)에서만 nav 「메신저」가 창을 연다.
// 조건은 창을 그리는 조건과 같다(쓰기 자격 · 역할로 보기 아님 — 역할 비교 0). `/messages` 는 페이지가 붙박이라 창이 없다(링크 그대로).
$memberMessenger = ($memberWindow ?? false) === true && $memberPage !== 'messages' && isset($user) && is_array($user)
    && Authz::canCommunityWrite($user) && ($user['acting'] ?? false) !== true;
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
?>
<?php /* 사이트 Q:F-13 P1 — 고대비 화면(DESIGN 2.16 · 계정 전체 취향)을 모아 안내 화면에도(런처와 같은 조건 — 세션의 본인 값 · 권한 아님) */ ?>
<!doctype html><html lang="ko"<?= Http::themeAttr() ?><?= Design::attrs() ?><?= isset($user)&&is_array($user)&&($user['high_contrast']??false)===true?' data-contrast="high"':'' ?>><head>
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
</head><body class="moa-site" data-service="assistant" data-member-page="<?= $e($memberPage) ?>" data-base="<?= TBOX_BASE ?>"<?= isset($joinCsrf)?' data-join-csrf="'.$e($joinCsrf).'"':'' ?><?= $memberPage==='consent'&&isset($consentTo)&&is_string($consentTo)?' data-consent-to="'.$e($consentTo).'"':'' ?>>
<a class="skip" href="#member-main">본문으로 건너뛰기</a>
<header class="moa-nav"><a class="moa-logo" href="/"><span class="moa-symbol" aria-hidden="true">m.</span>모아<span class="moa-logo-note">일과 일상을 연결하는 공간</span></a><nav aria-label="주 메뉴"><a href="/">모아 홈</a><?php if($memberPage==='consent'): /* Q:F-13 P1 — 동의 전에는 다른 화면이 모두 이 화면으로 돌아오므로 메뉴는 둘러보기 하나 */ ?><?php else: ?><a href="<?= Http::communityUrl('/') ?>">업무</a><?php endif; ?><?php if($memberPage==='consent'): ?><?php elseif($memberPage==='join'): ?><a href="<?= Http::url('/login') ?>">로그인</a><?php else: ?><?php if($memberMessenger): ?><a id="messenger-open" href="<?= Http::url('/messages') ?>" data-messenger-open aria-haspopup="dialog" aria-controls="messenger-window" aria-expanded="false">메신저 <span class="badge badge--internal" data-message-unread hidden></span></a><?php else: ?><a href="<?= Http::url('/messages') ?>">메신저</a><?php endif; ?><a href="<?= Http::url('/settings') ?>">내 계정</a><?php endif; ?></nav><?php /* 사이트 Q:F-13 P1 — 약관 링크 줄(모아 머리 화면 · 처리방침은 굵게) */ ?><p class="legal-links"><a href="<?= Http::url('/privacy') ?>"><strong>개인정보 처리방침</strong></a> · <a href="<?= Http::url('/terms') ?>">이용약관</a></p></header>
<?php if (isset($user) && is_array($user) && (!Authz::canCommunityWrite($user) || ($user['acting'] ?? false))): require __DIR__.'/messenger_unavailable.php'; endif; ?>
<main id="member-main" class="moa-member-main">
<?php if ($memberPage !== 'join' && $memberPage !== 'consent'): require __DIR__ . '/usage_guide.php'; endif; ?>
