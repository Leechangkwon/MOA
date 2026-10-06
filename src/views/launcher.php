<?php
declare(strict_types=1);
require_once __DIR__.'/../Design.php';
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$signed=is_array($launcherUser);
$communityHref=$signed?Http::communityUrl('/'):Http::url('/login?to=community');
// 사이트 Q:F-12 C-1 (WK-2 ① · WK-10 · ARCH 23.8) — 커뮤니티를 「업무」·「이야기」 두 앱으로 나눴다. 이름·설명은 앱 목록(config/apps.json)과 같은 글자.
// 이야기 = `/community/memories`(주소 그대로 — WK-15 A). 미로그인이면 로그인 뒤 그 주소로(돌아갈 주소 허용 목록 Http::returnPath 그대로 — 넓히지 않는다).
$storyHref=$signed?Http::communityUrl('/memories'):Http::url('/login?to=%2Fcommunity%2Fmemories');
$name=$signed?(($launcherUser['display_name']??'')?:$launcherUser['username']):'';
// 사이트 Q:F-11 (지시서 B-1 · 결정 Q-10) — 로그인했고 쓰기 자격이 있고 「역할로 보기」가 아닐 때만 떠 있는 메신저 창 + 스크립트 셋.
// 미로그인 런처는 창 마크업·스크립트 0 · 세션은 읽기만(Set-Cookie 0 — launcher_page.php 그대로). 역할 비교 0(판정 함수).
$launcherMessenger=$signed&&Authz::canCommunityWrite($launcherUser)&&($launcherUser['acting']??false)!==true;
?>
<!doctype html><html lang="ko"<?= Http::themeAttr() ?><?= Design::attrs() ?><?= $signed&&($launcherUser['high_contrast']??false)?' data-contrast="high"':'' ?>>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="description" content="좋아하는 풍경, 소소한 이야기, 함께하는 사람들. 우리의 일상이 모이는 커뮤니티, 모아."><title>모아 — 함께라서 더 좋은 일상</title>
<link rel="stylesheet" href="<?= Http::url('/assets/app.css') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/app.css') ?>">
<link rel="manifest" href="<?= Http::url('/app.webmanifest') ?>">
<?= Design::assets() ?>
<script defer src="<?= Http::url('/assets/moa.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/moa.js') ?>"></script>
<?php if($signed): ?><script defer src="<?= Http::url('/assets/start.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/start.js') ?>"></script><?php endif; ?>
</head><body class="moa-site moa-home" data-base="<?= TBOX_BASE ?>" data-community-base="<?= COMMUNITY_BASE ?>" data-signed="<?= $signed?'yes':'no' ?>">
<a class="skip" href="#moa-main">본문으로 건너뛰기</a>
<header class="moa-nav"><a class="moa-logo" href="/"><span class="moa-symbol" aria-hidden="true">m.</span>모아<span class="moa-logo-note">우리의 이야기가 모이는 곳</span></a><nav aria-label="주 메뉴"><a href="#discover" aria-current="page">발견</a><a href="<?= $e($communityHref) ?>">업무</a><a href="<?= $e($storyHref) ?>">이야기</a><?php if($signed): ?><a href="<?= Http::url('/messages') ?>">메신저</a><a class="moa-account" href="<?= Http::url('/settings') ?>"><?= $e($name) ?> 님</a><?php else: ?><a href="<?= Http::url('/login?to=community') ?>">로그인</a><a class="btn moa-nav-join" href="<?= Http::url('/join') ?>">초대받고 시작하기 ↗</a><?php endif; ?></nav></header>
<main class="moa-container" id="moa-main">
<div class="moa-greeting"><p><span class="moa-live-dot" aria-hidden="true"></span><?= $signed?$e($name).' 님, 오늘도 반가워요.':'취향이 모이고, 이야기가 시작되는 곳' ?></p><button class="moa-theme-button" type="button" data-design-open aria-controls="q-design-dialog" aria-haspopup="dialog">화면 꾸미기 <span aria-hidden="true">☼</span></button></div>
<section class="moa-hero" aria-labelledby="moa-hero-title"><img src="<?= Http::url('/assets/moa/lake.jpg') ?>" width="1800" height="1200" fetchpriority="high" alt="잔잔한 호수에 비친 산과 작은 집, 초록빛 풍경"><div class="moa-hero-shade"></div><div class="moa-hero-copy"><span class="moa-overline">EVERYDAY, A LITTLE MORE TOGETHER</span><h1 id="moa-hero-title">좋은 순간은,<br>함께 모아.</h1><p>마음에 드는 풍경 하나, 나누고 싶은 이야기 하나.<br>우리의 일상에 작은 즐거움을 더해 보세요.</p><a class="moa-white-button" href="<?= $e($communityHref) ?>">커뮤니티 둘러보기 <span aria-hidden="true">↗</span></a></div><div class="moa-hero-caption"><span>잠깐, 쉬어 가도 괜찮아요.</span><span>오늘의 풍경 · 호숫가에서</span></div><span class="moa-hero-stamp" aria-hidden="true">일상의<br>작은 발견 ✳</span></section>
<section class="moa-choose" aria-labelledby="moa-choose-title"><div class="moa-section-head"><div><p class="moa-kicker">모아에서 할 수 있는 일</p><h2 id="moa-choose-title">지금 필요한 곳으로.</h2></div><span class="moa-muted">아이콘과 이름을 누르면 해당 공간으로 이동해요.</span></div>
<nav class="moa-shortcuts" aria-label="목적에 맞는 공간 선택">
<?php /* Q:F-12 C-1 — 타일 순서는 앱 목록·아이콘 줄과 같게: 자료실 · 업무 · 이야기 → 메신저 · PC 비서(Q:T-38) */ ?>
<a href="<?= Http::url('/') ?>"><span class="moa-shortcut-icon moa-sand"><?php $moaIcon='library'; require __DIR__.'/moa_icon.php'; ?></span><span><strong>자료실</strong><small>파일 보관 · 찾기 · 공유</small></span><b aria-hidden="true">↗</b></a>
<a href="<?= $e($communityHref) ?>"><span class="moa-shortcut-icon moa-mint"><?php $moaIcon='work'; require __DIR__.'/moa_icon.php'; ?></span><span><strong>업무</strong><small>작업 · 프로젝트 · 문서</small></span><b aria-hidden="true">↗</b></a>
<a href="<?= $e($storyHref) ?>"><span class="moa-shortcut-icon moa-peach"><?php $moaIcon='community'; require __DIR__.'/moa_icon.php'; ?></span><span><strong>이야기</strong><small>스레드 · 여행 — 일상 나누기</small></span><b aria-hidden="true">↗</b></a>
<?php if($launcherMessenger): /* 타일이 창을 연다(스크립트가 붙기 전·없을 때는 링크 그대로 — /messages) */ ?><a id="messenger-open" href="<?= Http::url('/messages') ?>" data-messenger-open aria-haspopup="dialog" aria-controls="messenger-window" aria-expanded="false"><span class="moa-shortcut-icon moa-lilac"><?php $moaIcon='messages'; require __DIR__.'/moa_icon.php'; ?></span><span><strong>메신저 · 모아 AI <span class="badge badge--internal" data-message-unread hidden></span></strong><small>동료에게 연락 · AI에게 질문</small></span><b aria-hidden="true">↗</b></a><?php else: ?><a href="<?= Http::url('/messages') ?>"><span class="moa-shortcut-icon moa-lilac"><?php $moaIcon='messages'; require __DIR__.'/moa_icon.php'; ?></span><span><strong>메신저 · 모아 AI</strong><small>동료에게 연락 · AI에게 질문</small></span><b aria-hidden="true">↗</b></a><?php endif; ?>
<a href="<?= Http::url('/welcome') ?>"><span class="moa-shortcut-icon moa-lilac"><?php $moaIcon='assistant'; require __DIR__.'/moa_icon.php'; ?></span><span><strong>PC 비서 연결</strong><small>tbox-buddy 설치 · 내 계정 연결</small></span><b aria-hidden="true">↗</b></a>
</nav></section>
<div class="moa-content-grid"><div class="moa-main-column">
<section id="discover" class="moa-discover"><div class="moa-section-head"><div><p class="moa-kicker">A LITTLE INSPIRATION</p><h2>오늘, 이런 여유 어때요?</h2></div><span class="moa-muted">모아 에디터의 작은 제안</span></div><div class="moa-tabs" role="group" aria-label="읽을거리 주제"><button type="button" data-topic="all" aria-pressed="true">전체</button><button type="button" data-topic="nature" aria-pressed="false">풍경과 산책</button><button type="button" data-topic="rest" aria-pressed="false">잠깐의 쉼</button></div>
<div class="moa-story-grid">
<button type="button" class="moa-story" data-story="lake" data-category="nature" aria-haspopup="dialog"><div class="moa-story-photo"><img src="<?= Http::url('/assets/moa/lake.jpg') ?>" width="600" height="450" loading="lazy" alt="산 아래 호수에 비친 작은 집"><span>풍경 수집</span><b aria-hidden="true">↗</b></div><p class="moa-story-meta">SLOW MOMENTS · 1분 읽기</p><h3>가만히 바라보는 것만으로<br>충분한 순간</h3><p>호수에 비친 풍경처럼, 잠깐 마음을 비워요.</p></button>
<button type="button" class="moa-story" data-story="forest" data-category="nature" aria-haspopup="dialog"><div class="moa-story-photo"><img src="<?= Http::url('/assets/moa/forest.jpg') ?>" width="600" height="450" loading="lazy" alt="초록 숲 사이로 들어오는 햇살"><span>산책의 기쁨</span><b aria-hidden="true">↗</b></div><p class="moa-story-meta">INTO THE GREEN · 1분 읽기</p><h3>늘 걷던 길에서<br>새로운 초록을 찾는 법</h3><p>멀리 떠나지 않아도 발견은 시작되니까요.</p></button>
<button type="button" class="moa-story" data-story="ocean" data-category="rest" aria-haspopup="dialog"><div class="moa-story-photo"><img src="<?= Http::url('/assets/moa/ocean.jpg') ?>" width="600" height="450" loading="lazy" alt="잔잔하게 밀려오는 푸른 바다의 파도"><span>쉬어 가기</span><b aria-hidden="true">↗</b></div><p class="moa-story-meta">TAKE A BREATH · 1분 읽기</p><h3>바쁜 하루 사이,<br>나를 위한 작은 쉼표</h3><p>화면에서 눈을 떼고, 오늘의 여유를 찾아요.</p></button>
</div></section>
<section class="moa-community-feed" aria-labelledby="moa-feed-title"><div class="moa-section-head"><div><p class="moa-kicker">OUR CONVERSATIONS</p><h2 id="moa-feed-title">지금, 우리들의 이야기</h2></div><a href="<?= $e($communityHref) ?>">커뮤니티 전체 보기 ↗</a></div>
<?php if($signed): ?><div id="moa-board-tabs" class="moa-tabs" role="group" aria-label="게시판"></div><div id="moa-feed"></div><p id="moa-feed-status" role="status" aria-live="polite">이야기를 불러오고 있어요.</p><?php else: ?><div class="moa-community-preview"><div class="moa-avatar-stack" aria-hidden="true"><span>봄</span><span>온</span><span>결</span></div><div><h3>우리끼리 나누는 이야기는 여기서.</h3><p>함께 쓰는 글과 자료는 로그인 후에 볼 수 있어요.<br>초대 링크를 받았다면, 지금 당신의 이야기를 시작해 보세요.</p><a class="btn" href="<?= Http::url('/join') ?>">모아에 함께하기 →</a></div></div><?php endif; ?></section>
<?php if($signed): ?><section class="q-start moa-recent" id="q-start" data-base="<?= TBOX_BASE ?>" data-community-base="<?= COMMUNITY_BASE ?>" aria-labelledby="q-start-title"><div class="q-start-head"><h2 id="q-start-title">최근 작업 · 즐겨찾기</h2><button type="button" class="btn btn--quiet btn--small" id="q-start-retry">새로고침</button></div><div class="toolbar"><button type="button" class="btn btn--quiet btn--small" data-start-view="recent" aria-pressed="true">최근</button><button type="button" class="btn btn--quiet btn--small" data-start-view="favorites" aria-pressed="false">즐겨찾기</button></div><div id="q-start-list" class="q-start-list"></div><p id="q-start-status" class="help" role="status"></p></section><?php endif; ?>
</div><aside class="moa-side-column"><section class="moa-prompt-card"><span class="moa-kicker">TODAY'S QUESTION</span><span class="moa-prompt-doodle" aria-hidden="true">“</span><h2>요즘 당신을<br>웃게 만든 것은<br>무엇인가요?</h2><p>맛있었던 점심, 우연히 들은 노래,<br>별것 아닌 순간도 좋아요.</p><a href="<?= $e($communityHref) ?>">우리 이야기로 이어가기 <span aria-hidden="true">↗</span></a></section>
<section class="moa-side-card"><span class="moa-kicker">HELLO, NEW FRIEND</span><h3><?= $signed?'모아를 더 편하게':'처음 오셨나요?' ?></h3><p>가입부터 OTP, 비서 연결까지.<br>차근차근 함께 시작해요.</p><ol><li><span>01</span>내 계정 만들기</li><li><span>02</span>OTP로 안전하게</li><li><span>03</span>AI 비서와 연결하기</li></ol><a class="btn btn--quiet" href="<?= Http::url($signed?'/welcome':'/join') ?>">시작 안내 보기 →</a></section>
<?php if(is_int($launcherBriefingCount)): ?><a class="moa-briefing" href="<?= Http::communityUrl('/my-tasks') ?>"><span>오늘 챙길 일</span><strong><?= $launcherBriefingCount ?><small>건</small></strong><span>차근차근 살펴볼까요? ↗</span></a><?php endif; ?>
<div class="moa-side-note"><span aria-hidden="true">✳</span><p>작은 이야기가 모여<br>우리의 하루가 됩니다.</p></div></aside></div>
<section class="moa-bottom-banner"><div><p class="moa-kicker">BETTER, TOGETHER</p><h2>당신의 일상도, 여기에 모아.</h2><p>오늘의 발견을 함께 나누고 내일의 이야기를 만들어 보세요.</p></div><a class="moa-white-button" href="<?= $e($communityHref) ?>">이야기 시작하기 ↗</a></section>
</main><footer class="moa-footer"><a class="moa-logo" href="/">모아 <span>moa.</span></a><p>좋아하는 것들과 함께하는 사람들.</p><span>풍경 사진 · Unsplash</span></footer>
<dialog class="moa-story-dialog" id="moa-story-dialog" aria-labelledby="moa-story-title"><button class="moa-dialog-close" type="button" id="moa-story-close" aria-label="읽을거리 닫기">×</button><img id="moa-story-image" alt=""><div><p class="moa-kicker">모아의 작은 발견</p><h2 id="moa-story-title"></h2><div id="moa-story-body"></div><a class="btn" href="<?= $e($communityHref) ?>">나의 이야기도 나누기 ↗</a></div></dialog>
<?php if($launcherMessenger): $messengerUser=$launcherUser; ?>
<?php require __DIR__.'/messenger_window.php'; ?>
<?php endif; ?>
<?php include __DIR__.'/design_controls.php'; ?>
</body></html>
