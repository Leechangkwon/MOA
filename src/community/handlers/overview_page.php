<?php
declare(strict_types=1);
// Existing thread bookmarks remain useful while the unfiltered entrance shows all modules.
if (is_string($_GET['view'] ?? null) && in_array($_GET['view'], ['mine','feed','shared','discover'], true)) {
    require __DIR__ . '/memories_page.php';
    return;
}
// Preserve saved links to the existing document workspace.
if (array_intersect(array_keys($_GET), ['space','space_id','q','trash','page','database'])) {
    require __DIR__ . '/home_page.php';
    return;
}
$pageTitle = '커뮤니티 둘러보기';
$activeNav = 'community-overview';
$pageKey = 'community-overview';
$pageCss = ['community/community.css','community/overview.css'];
$shellModule = 'community';
$pagePalette = community_palette_version();
$overviewWrite = Authz::canCommunityWrite($user);
// 사이트 Q:F-11 (지시서 B-4) — 개요 카드의 단추는 셸의 떠 있는 메신저 창을 연다 — 창이 있는 사람(쓰기 자격 · 역할로 보기 아님)에게만
$overviewMessenger = $overviewWrite && ($user['acting'] ?? false) !== true;
require __DIR__ . '/../../views/shell_top.php';
?>
<section class="community-overview" id="community-overview" aria-labelledby="overview-title">
  <header class="overview-heading" id="overview-welcome">
    <p class="overview-kicker">일상도, 일도, 함께 모아</p>
    <h1 id="overview-title">오늘은 무엇을 해 볼까요?</h1>
    <p>사진 한 장의 추억부터 함께 쓰는 문서까지.<br>모아의 여러 공간을 한눈에 둘러보세요.</p>
    <button class="btn btn--quiet" type="button" data-usage-guide-open>화면을 보며 사용법 배우기</button>
  </header>
  <div class="overview-grid">
    <article class="overview-card" id="overview-memories"><span class="overview-kicker">나의 일상</span><h2>사진과 이야기를 남겨요</h2><p>사진·영상과 짧은 글로 나만의 스레드를 만들어요. 긴 이야기는 요약으로, 자세한 내용은 펼쳐서 볼 수 있어요.</p><a class="btn" href="<?= Http::communityUrl('/memories?view=mine') ?>">나의 스레드 열기</a></article>
    <article class="overview-card" id="overview-feed"><span class="overview-kicker">함께 발견</span><h2>사람을 팔로우하고 만나요</h2><p>팔로잉에서 소식을 받고 발견에서 새로운 이야기를 만나요. 내 글을 누가 볼 수 있는지는 내가 정해요.</p><div class="overview-actions"><a class="btn btn--quiet" href="<?= Http::communityUrl('/memories') ?>">팔로잉 소식 보기</a><a href="<?= Http::communityUrl('/memories?view=discover') ?>">새로운 이야기 발견 ↗</a></div></article>
    <article class="overview-card" id="overview-workspace"><span class="overview-kicker">함께 하는 일</span><h2>문서·게시판·업무 공간</h2><p>메모와 회의록, 공지와 질문을 모아요. 표·보드·달력으로 일을 정리하고 필요한 사람과 문서를 나눠요.</p><a class="btn btn--quiet" href="<?= Http::communityUrl('/workspace') ?>">문서와 게시판 열기</a></article>
    <article class="overview-card" id="overview-travel"><span class="overview-kicker">계획과 기록</span><h2>여행을 유연하게 계획해요</h2><p>가고 싶은 곳과 일정을 적고 실제 여행 기록을 연결해요. 계획이 바뀌어도 일정과 메모를 자유롭게 고칠 수 있어요.</p><a class="btn btn--quiet" href="<?= Http::communityUrl('/memories?view=mine&travel=1') ?>">나의 여행 계획 열기</a></article>
    <article class="overview-card" id="overview-files"><span class="overview-kicker">우리의 자료</span><h2>파일을 보관하고 찾아요</h2><p>문서와 사진을 폴더에 모으고 검색·미리보기·공유로 다시 활용해요. 문서에 관련 자료를 연결할 수도 있어요.</p><a class="btn btn--quiet" href="<?= Http::url('/') ?>">자료실 열기</a></article>
    <article class="overview-card" id="overview-messenger"><span class="overview-kicker">대화와 도움</span><h2>메신저와 AI 비서</h2><p>회원 대화는 작은 창에서 이어가요. AI로 문장을 다듬거나 여행 글감을 찾고, 사진 이야기는 스레드에서 만들어요.</p><div class="overview-actions"><?php if ($overviewMessenger): ?><button class="btn btn--quiet" type="button" data-messenger-open aria-haspopup="dialog" aria-controls="messenger-window" aria-expanded="false">메신저 창 열기</button><?php endif; ?><a href="<?= Http::url('/welcome') ?>#welcome-buddy">AI 비서 연결 안내 ↗</a></div></article>
  </div>
  <aside class="overview-next" id="overview-account"><div><h2>내 방식대로 시작하세요</h2><p>로그인 방법, 모바일·PC 설치, 비서 연결은 시작 안내에서 선택할 수 있어요. 오늘 할 일도 놓치지 마세요.</p></div><div class="overview-actions"><a class="btn btn--quiet" href="<?= Http::url('/welcome') ?>">시작 안내와 설치</a><a class="btn btn--quiet" href="<?= Http::communityUrl('/today') ?>">오늘 할 일</a></div></aside>
</section>
<?php require __DIR__ . '/../../views/shell_bottom.php'; ?>
