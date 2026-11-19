<?php
declare(strict_types=1);
$moaIsCommunity = ($moaGuide ?? '') === 'community';
$moaMayWrite = Authz::canCommunityWrite($user);
?>
<details class="moa-purpose" aria-labelledby="moa-purpose-title">
  <summary class="moa-purpose-heading">
    <span id="moa-purpose-title"><?= $moaIsCommunity ? '문서와 업무 시작 안내' : '자료실 시작 안내' ?></span>
    <span class="muted">사용 방법</span>
  </summary>
  <div class="moa-purpose-heading">
    <span class="moa-purpose-mark moa-module-mark--<?= $moaIsCommunity ? 'community' : 'tbox' ?>"><?php /* Q:F-12 — 「내 문서」(업무 앱) 안내는 업무 아이콘 */ $moaIcon=$moaIsCommunity?'work':'library'; require __DIR__.'/moa_icon.php'; ?></span>
    <div><p class="moa-kicker"><?= $moaIsCommunity ? '함께 이야기하고, 함께 기록해요' : '필요한 자료를, 필요할 때 바로' ?></p><h2><?= $moaIsCommunity ? '여기서 무엇을 해 볼까요?' : '우리 팀의 파일을 한곳에.' ?></h2></div>
  </div>
  <div class="moa-purpose-grid">
  <?php if ($moaIsCommunity): ?>
    <article class="moa-purpose-card"><span class="moa-purpose-step">01 · 게시판에 글 올리기</span><h3>소식 읽고, 궁금한 것 묻기</h3><p>공지, 일상의 발견, 동료에게 묻고 싶은 질문까지. 게시판에서 함께 이야기해요.</p><div id="moa-board-doors" class="moa-board-doors" aria-label="볼 수 있는 게시판"><span class="moa-muted">게시판을 불러오는 중…</span></div></article>
    <article class="moa-purpose-card"><span class="moa-purpose-step">02 · 기록 남기기</span><h3>메모를 쓸모 있는 문서로</h3><p>회의 메모, 업무 방법, 인수인계를 남겨요. 내 문서에서 시작하고 공개 범위를 정해 함께 보세요.</p><div class="moa-purpose-actions"><?php if ($moaMayWrite): ?><button class="btn btn--small" type="button" data-moa-action="community-new">새 문서 작성 ↗</button><button class="btn btn--quiet btn--small" type="button" data-moa-action="community-template-new">양식으로 시작</button><?php else: ?><a class="btn btn--quiet btn--small" href="#moa-my-documents">볼 수 있는 문서로 ↓</a><?php endif; ?></div></article>
    <article class="moa-purpose-card"><span class="moa-purpose-step">03 · 함께 진행하기</span><h3>할 일과 진행 상황 챙기기</h3><p>문서에 할 일을 적고, 담당자·기한·진행 상태를 정하세요. 「내 작업」에서 다시 모아 볼 수 있어요.</p><a class="btn btn--quiet btn--small" href="<?= Http::communityUrl('/my-tasks') ?>">내 작업 보기 ↗</a></article>
  <?php else: ?>
    <article class="moa-purpose-card"><span class="moa-purpose-step">01 · 보관하기</span><h3>문서·사진·파일 올리기</h3><p>원하는 폴더를 열고 파일을 올려요. 파일 설명과 볼 수 있는 범위를 함께 정하면 찾기 쉬워집니다.</p><button class="btn btn--small" type="button" data-moa-action="upload-toggle">파일 올리기 ↑</button></article>
    <article class="moa-purpose-card"><span class="moa-purpose-step">02 · 찾아보기</span><h3>필요한 파일 빠르게 찾기</h3><p>이름이나 설명으로 검색하고 내용을 미리 보세요. 자주 쓰는 파일에 별을 달면 즐겨찾기에 모입니다.</p><button class="btn btn--quiet btn--small" type="button" data-moa-action="search-q">파일 검색하기 ↗</button></article>
    <article class="moa-purpose-card"><span class="moa-purpose-step">03 · 전달하기</span><h3>동료에게 자료 공유하기</h3><p>파일의 작업 메뉴에서 ‘공유 링크 만들기’를 선택하세요. 공유할 수 있는 파일에만 표시됩니다.</p><button class="btn btn--quiet btn--small" type="button" data-moa-action="moa-file-list">내 파일에서 시작 ↓</button></article>
  <?php endif; ?>
  </div>
  <details class="moa-use-recipes"><summary>이렇게 함께 쓰면 더 편해요 <span>실제 활용 예시 3가지</span></summary>
    <div class="moa-recipe-grid">
      <article><h3>회의 한 번, 기록 한 장</h3><p>업무에 회의 메모 작성 → 결정 사항과 할 일 정리 → 담당자·기한 설정 → 다음 회의에 이어 보기.</p></article>
      <article><h3>자료와 설명을 함께 전달</h3><p>자료실에 파일 보관 → 업무 문서에 사용 방법과 파일 링크 정리 → 필요한 사람에게 문서 공유.</p></article>
      <article><h3>다음 사람을 위한 인수인계</h3><p>업무 문서에 일의 순서와 체크리스트 작성 → 관련 자료 연결 → 함께 확인하고 문서의 인계 기능으로 담당 변경.</p></article>
    </div>
    <p class="moa-usage-tip"><?= $moaIsCommunity ? '문서는 한 장의 메모부터 시작해도 좋아요. 표·할 일·파일 링크가 필요해질 때 블록을 더하세요.' : '파일 자체는 자료실에, 파일에 대한 설명·질문·논의는 업무 문서에 모으면 함께 찾기 쉬워요.' ?></p>
    <a href="<?= $moaIsCommunity ? Http::url('/') : Http::communityUrl('/') ?>"><?= $moaIsCommunity ? '파일을 보관할 자료실로 가기' : '설명과 논의를 남길 업무로 가기' ?> ↗</a>
  </details>
</details>
