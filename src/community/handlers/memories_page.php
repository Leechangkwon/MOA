<?php
declare(strict_types=1);
// Preserve existing bookmarked work-space filters at the previous home URL.
if (TBOX_ROUTE === 'GET /community/' && array_intersect(array_keys($_GET), ['space', 'space_id', 'q', 'trash', 'page', 'database'])) {
    require __DIR__ . '/home_page.php';
    return;
}
$sharedToken = $sharedToken ?? '';
$isShared = $sharedToken !== '';
$profileName = !$isShared && TBOX_ROUTE === 'GET /community/memories/u/{username}' ? $params[0] : '';
$postId = !$isShared && TBOX_ROUTE === 'GET /community/memories/p/{id}' ? $params[0] : '';
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
// Local camera previews require blob media; no remote images, scripts, or frames.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
$pageTitle = '스레드';
$activeNav = 'community-threads';
$pageKey = 'community-threads';
$pageHelp = 'community-threads';
$pageCss = ['community/community.css', 'community/memories.css'];
$shellModule = 'community';
$pagePalette = $isShared ? '' : community_palette_version();
$cssVersion = filemtime(__DIR__ . '/../../../public/assets/community/memories.css');
$jsVersion = filemtime(__DIR__ . '/../../../public/assets/community/memories.js');

?>
<?php if ($isShared): ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f7f6f2">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>공유 스레드 · 커뮤니티</title>
<link rel="manifest" href="<?= Http::url('/app.webmanifest') ?>">
<link rel="apple-touch-icon" href="<?= Http::url('/assets/qaredi-icon-192.png') ?>">
<link rel="stylesheet" href="<?= Http::url('/assets/community/memories.css') ?>?v=<?= $cssVersion ?>">

</head>
<body class="threads-public">
<?php else: require __DIR__ . '/../../views/shell_top.php'; endif; ?>
<section id="community-threads" class="community-threads" data-base="<?= $e(rtrim(Http::communityUrl('/'), '/')) ?>" data-shared="<?= $e($sharedToken) ?>" data-profile="<?= $e($profileName) ?>" data-post="<?= $e($postId) ?>" aria-label="커뮤니티 스레드">
<svg class="symbol-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><defs>
<symbol id="i-spark" viewBox="0 0 24 24"><path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z"/></symbol>
<symbol id="i-camera" viewBox="0 0 24 24"><path d="M4 6h4l2-3h4l2 3h4v15H4Z"/><circle cx="12" cy="13" r="4"/></symbol>
<symbol id="i-home" viewBox="0 0 24 24"><path d="m3 10 9-7 9 7v11H3V10Zm6 11v-8h6v8"/></symbol>
<symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M4 21v-3a8 8 0 0 1 16 0v3"/></symbol>
<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 4v16M4 12h16"/></symbol>
<symbol id="i-heart" viewBox="0 0 24 24"><path d="M20.5 4.5a5 5 0 0 0-8.5 2 5 5 0 0 0-8.5-2C-1 9 7 16 12 21c5-5 13-12 8.5-16.5Z"/></symbol>
<symbol id="i-comment" viewBox="0 0 24 24"><path d="M21 11a9 9 0 0 1-9 9 10 10 0 0 1-4-1l-5 2 1-5a9 9 0 1 1 17-5Z"/></symbol>
<symbol id="i-share" viewBox="0 0 24 24"><path d="M12 16V2m-5 5 5-5 5 5M5 12H3v10h18V12h-2"/></symbol>
<symbol id="i-grid" viewBox="0 0 24 24"><path d="M3 3h7v7H3Zm11 0h7v7h-7ZM3 14h7v7H3Zm11 0h7v7h-7Z"/></symbol>
<symbol id="i-photo" viewBox="0 0 24 24"><path d="M3 3h18v18H3Z M3 17l6-6 5 5 3-3 4 4"/><circle cx="16" cy="8" r="1"/></symbol>
<symbol id="i-pin" viewBox="0 0 24 24"><path d="M19 9c0 6-7 13-7 13S5 15 5 9a7 7 0 1 1 14 0Z"/><circle cx="12" cy="9" r="2"/></symbol>
<symbol id="i-arrow" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
<symbol id="i-close" viewBox="0 0 24 24"><path d="m6 6 12 12M6 18 18 6"/></symbol>
<symbol id="i-folder" viewBox="0 0 24 24"><path d="M3 5h7l3 3h8v12H3Z"/></symbol>
<symbol id="i-video" viewBox="0 0 24 24"><path d="M3 6h12v12H3Zm12 4 6-4v12l-6-4"/></symbol>
</defs></svg>
<div id="connection" class="connection" role="status" hidden>인터넷 연결이 끊겼어요. 작성한 기록은 이 기기에 임시 저장할 수 있어요.</div>
<header class="threads-heading" id="hero"><div><p class="eyebrow">OUR COMMUNITY</p><h1>우리의 하루가 모이는 곳</h1><p class="hero-sub">일상의 한 줄부터 여행의 한 장까지, 함께 나눠요.</p></div><span id="today" class="muted"></span><?php if (!$isShared): ?><button type="button" class="btn btn--quiet" data-help-open>사용 방법</button><?php endif; ?></header>
<nav class="threads-tabs" aria-label="스레드 메뉴"><button data-view="feed" class="active">모든 스레드</button><button data-view="mine" data-member>나의 스레드</button><button data-view="shared">공유 스레드</button></nav>
<div class="threads-quick" data-member><button class="threads-write" data-compose data-writer hidden><span class="avatar" data-my-initial>나</span><span>지금 어떤 이야기를 남기고 싶나요?</span><svg><use href="#i-camera"/></svg></button><button class="secondary" data-messenger-open>메신저</button></div>
<section class="profile-panel" id="profile-panel" hidden><div class="profile-cover"></div><div class="profile-content"><span class="avatar profile-avatar" id="profile-avatar">나</span><div><p class="eyebrow">MY THREADS</p><h1 id="profile-title">나의 스레드</h1><p class="muted" id="profile-description">평범한 하루도, 낯선 여행도. 내가 사랑한 순간들.</p></div><button class="secondary" id="share-profile" data-writer hidden><svg><use href="#i-share"/></svg>페이지 공유</button></div><div class="profile-stats" id="profile-stats"></div></section>
<div class="main-columns"><section class="feed-column" aria-label="커뮤니티 스레드">
<div class="feed-head"><div><p class="eyebrow" id="feed-kicker">OUR MOMENTS</p><h2 id="feed-title">함께 나누는 순간</h2></div><div class="view-toggle" aria-label="기록 보기 방식"><button id="list-view" class="icon-button selected" aria-label="피드로 보기" aria-pressed="true"><svg><use href="#i-home"/></svg></button><button id="grid-view" class="icon-button" aria-label="앨범으로 보기" aria-pressed="false"><svg><use href="#i-grid"/></svg></button></div></div>
<div class="feed-tools"><label class="search-label"><span class="sr-only">스레드 검색</span><input type="search" id="search" placeholder="여행, 장소, 한 줄의 기억 찾기" maxlength="100"></label><button class="chip" id="refresh">새로 보기</button></div>
<button class="draft-banner" id="resume-draft" hidden>쓰다 만 순간이 있어요 <span>이어서 쓰기 →</span></button>
<div id="feed-status" class="feed-status" role="status">스레드를 불러오고 있어요…</div>
<div id="feed" class="feed" aria-live="polite"></div>
<section id="empty" class="empty" hidden><span class="empty-icon"><svg><use href="#i-camera"/></svg></span><p class="eyebrow">YOUR STORY STARTS HERE</p><h3 id="empty-title">아직 비어 있는 첫 페이지</h3><p id="empty-copy">완벽한 사진이 아니어도 괜찮아요.<br>지금 남기는 한 장이, 내일의 추억이 될 테니까요.</p><button class="primary" data-compose data-writer hidden>나의 첫 순간 기록하기<svg><use href="#i-arrow"/></svg></button></section>
<button class="secondary load-more" id="load-more" hidden>이전 순간 더 보기</button>
</section>
<aside class="journal-aside"><section class="journal-card"><div class="card-label">A NOTE TO MYSELF <svg><use href="#i-spark"/></svg></div><h3>오늘은 어떤 순간을<br>간직하고 싶나요?</h3><p>처음 만난 골목의 빛,<br>우연히 들어간 작은 카페,<br>함께 웃었던 그 한마디.</p><span class="journal-line"></span><p class="handwritten">작은 순간도 충분히 특별해요.</p><button class="text-button" data-compose data-writer hidden>한 줄 남기기 <svg><use href="#i-arrow"/></svg></button></section><section class="studio-card"><span class="studio-badge"><svg><use href="#i-spark"/></svg>MEMORY STUDIO</span><h3>기억에 감성을 더해요</h3><p>기록을 ChatGPT로 가져가<br>글·사진·영상을 다듬어 보세요.<br>처음 남긴 원본은 간직할게요.</p><span class="small-muted">메신저의 GPT 탭에서 대화해 보세요</span></section><p class="aside-footer">YOUR DAYS, BEAUTIFULLY KEPT.<br>Qaredi · 커뮤니티</p></aside>
</div><div class="toast" id="memory-toast" role="status" hidden></div>
<dialog id="compose-dialog" class="compose-dialog" aria-labelledby="compose-title">
<form id="compose-form"><header class="dialog-head"><div><p class="eyebrow">KEEP THIS MOMENT</p><h2 id="compose-title">새로운 순간</h2></div><button type="button" class="icon-button" data-close="compose-dialog" aria-label="작성창 닫기"><svg><use href="#i-close"/></svg></button></header>
<div class="dialog-body"><div class="capture-actions"><button type="button" class="capture-button" id="take-photo"><svg><use href="#i-camera"/></svg><strong>사진 찍기</strong><span>지금, 이 순간</span></button><button type="button" class="capture-button" id="pick-media"><svg><use href="#i-photo"/></svg><strong>앨범에서</strong><span>사진과 영상</span></button><button type="button" class="capture-button" id="take-video"><svg><use href="#i-video"/></svg><strong>영상 찍기</strong><span>움직이는 기억</span></button></div>
<input id="camera-input" type="file" accept="image/*" capture="environment" hidden><input id="video-input" type="file" accept="video/*" capture="environment" hidden><input id="media-input" type="file" accept="image/*,video/mp4,video/webm,video/quicktime" multiple hidden>
<p id="media-selection-status" class="field-hint" role="status">선택된 사진·영상이 없어요</p>
<p id="media-selection-error" class="form-error" role="alert" hidden></p><button type="button" id="skip-rejected-media" class="text-button" hidden>추가하지 못한 파일은 제외하고 계속</button>
<div id="media-preview" class="media-preview"></div><p class="field-hint">최대 10개 · 사진 20MB / 영상 100MB · 첫 사진이 앨범 표지예요</p>
<label class="field">이 순간의 이야기<textarea id="caption" maxlength="5000" rows="5" placeholder="어디에 있었나요? 어떤 마음이었나요? 짧게 적어도 좋아요."></textarea></label><div class="caption-meta"><button type="button" class="text-button" id="caption-prompt">글감이 필요해요</button><span id="caption-count">0 / 5,000</span></div>
<div class="field-row"><label class="field">장소<input id="location" maxlength="100" placeholder="예: 제주, 세화 해변"></label><label class="field">이야기 모음<input id="trip" maxlength="80" placeholder="예: 가을의 제주"></label></div>
<div class="field-row"><label class="field">기록한 날<input type="date" id="taken-on" required></label><label class="field">누구와 나눌까요?<select id="visibility"><option value="private">나만 보기</option><option value="community">커뮤니티에 공개</option><option value="shared">커뮤니티 + 링크 공유</option></select></label></div><p id="visibility-hint" class="field-hint">나만 볼 수 있어요. 나중에 공개 범위를 바꿀 수 있습니다.</p>
<p id="compose-error" class="form-error" role="alert"></p><p id="draft-status" class="field-hint" aria-live="polite"></p><progress id="upload-progress" max="100" value="0" hidden></progress></div>
<footer class="dialog-footer"><button type="button" class="text-button danger" id="discard-draft">작성 내용 버리기</button><button class="primary" id="publish" type="submit"><svg><use href="#i-plus"/></svg><span>순간 저장하기</span></button></footer></form></dialog>
<dialog id="studio-dialog" class="studio-dialog" aria-labelledby="studio-title"><header class="dialog-head"><div><p class="eyebrow">MEMORY STUDIO</p><h2 id="studio-title">추억에 감성을 더해요</h2></div><button type="button" class="icon-button" data-close="studio-dialog" aria-label="꾸미기 닫기"><svg><use href="#i-close"/></svg></button></header><div class="dialog-body"><p class="studio-intro">원본의 순간은 그대로 간직하고,<br>당신의 이야기를 조금 더 아름답게.</p><ol class="studio-steps"><li><b>01</b><span>요청문을 복사하고 원본 사진·영상을 받아요.</span></li><li><b>02</b><span>ChatGPT에 요청문과 파일을 함께 보내 다듬어요.</span></li><li><b>03</b><span>완성한 글과 사진·영상을 이 기록에 적용해요.</span></li></ol>
<div class="field-row"><label class="field">꾸미고 싶은 것<select id="studio-kind"><option value="caption">감성적인 여행 글</option><option value="photo">사진 색감과 분위기</option><option value="video">짧은 여행 영상</option></select></label><label class="field">원하는 분위기<select id="studio-tone"><option value="담백하고 따뜻한">담백하고 따뜻하게</option><option value="서정적이고 잔잔한">서정적이고 잔잔하게</option><option value="밝고 유쾌한">밝고 유쾌하게</option><option value="영화처럼 감각적인">영화처럼 감각적으로</option></select></label></div><label class="field">ChatGPT에 보낼 요청문<textarea id="studio-prompt" rows="8"></textarea></label><div class="studio-actions"><button class="primary" id="studio-chat" type="button" data-member>GPT 메신저로 보내기</button><button class="secondary" id="copy-prompt">요청문 복사</button><a class="secondary" href="https://chatgpt.com/" target="_blank" rel="noopener noreferrer">ChatGPT 열기 ↗</a></div><div id="studio-originals" class="original-files"></div><p class="field-hint">GPT 메신저에는 확인 후 전송한 글만 전달돼요. 사진·영상 편집은 ChatGPT에서 파일과 함께 진행한 뒤 결과를 가져오세요.</p><hr><label class="field">다듬은 글 가져오기<textarea id="studio-result" rows="5" maxlength="5000" placeholder="ChatGPT가 다듬어 준 글을 붙여 넣어 주세요."></textarea></label><button class="secondary" id="studio-import">다듬은 사진·영상 고르기</button><input type="file" id="studio-files" accept="image/*,video/mp4,video/webm,video/quicktime" multiple hidden><div id="studio-imported" class="field-hint"></div><label class="check-label"><input type="checkbox" id="studio-replace">새 파일로 게시물의 사진·영상을 교체하기 (원본 보관)</label><p class="form-error" id="studio-error" role="alert"></p></div><footer class="dialog-footer"><button class="text-button" id="restore-original">원본으로 돌아가기</button><button class="primary" id="apply-studio">편집 화면에서 확인</button></footer></dialog>
<dialog id="comments-dialog" aria-labelledby="comments-title"><header class="dialog-head"><h2 id="comments-title">함께 남긴 마음</h2><button class="icon-button" data-close="comments-dialog" aria-label="댓글 닫기"><svg><use href="#i-close"/></svg></button></header><div class="dialog-body"><div id="comments-list"></div><form id="comment-form"><label class="field">댓글<textarea id="comment-body" rows="3" maxlength="1000" required placeholder="이 순간에 마음을 남겨 주세요."></textarea></label><p id="comment-error" class="form-error" role="alert"></p><button class="primary" type="submit">댓글 남기기</button></form></div></dialog>
<dialog id="share-dialog" aria-labelledby="share-title"><header class="dialog-head"><h2 id="share-title">추억을 나눠요</h2><button class="icon-button" data-close="share-dialog" aria-label="공유 닫기"><svg><use href="#i-close"/></svg></button></header><div class="dialog-body"><p id="share-description">‘링크 공유’로 저장한 기록만 보입니다. 링크는 30일 동안 유효해요.</p><label class="field">공유 링크<input id="share-url" readonly></label><div class="studio-actions"><button class="primary" id="native-share">친구에게 보내기</button><button class="secondary" id="copy-link">링크 복사</button></div><p class="field-hint" id="share-feedback" role="status"></p><button class="text-button danger" id="revoke-links">내 스레드의 모든 공유 링크 해제</button></div></dialog>
<dialog id="confirm-dialog" aria-labelledby="confirm-title"><div class="dialog-body"><h2 id="confirm-title">기록을 지울까요?</h2><p id="confirm-description">피드와 공유 링크에서 더 이상 보이지 않아요.</p></div><footer class="dialog-footer"><button class="secondary" data-close="confirm-dialog">취소</button><button class="primary" id="confirm-ok">삭제하기</button></footer></dialog>
</section>
<script defer src="<?= Http::url('/assets/community/memories.js') ?>?v=<?= $jsVersion ?>"></script>
<?php if ($isShared): ?></body></html><?php else: require __DIR__ . '/../../views/shell_bottom.php'; endif; ?>
