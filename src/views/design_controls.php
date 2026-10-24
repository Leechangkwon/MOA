<?php
declare(strict_types=1);
require_once __DIR__ . '/../Design.php';
?>
<dialog id="q-design-dialog" class="q-design-dialog" aria-labelledby="q-design-title" aria-describedby="q-design-description">
  <div class="q-design-heading">
    <div><p class="q-eyebrow">화면 꾸미기</p><h2 id="q-design-title">나에게 맞는 모아</h2></div>
    <button type="button" class="btn btn--quiet" data-design-close aria-label="테마 선택 닫기">닫기</button>
  </div>
  <p id="q-design-description" class="q-design-description">테마를 눌러 미리 보세요. 기본으로 저장하면 이 브라우저의 모든 화면에 적용됩니다.</p>
  <div class="q-theme-grid" role="group" aria-label="디자인 테마">
<?php foreach (Design::THEMES as $qDesignKey => $qDesignDetails): ?>
    <button type="button" class="q-theme-option" data-design-choice="<?= $qDesignKey ?>" aria-pressed="<?= Design::theme() === $qDesignKey ? 'true' : 'false' ?>">
      <span class="q-theme-swatch" aria-hidden="true"><span>m.</span><i></i><i></i><i></i></span>
      <span class="q-theme-label"><strong><?= $qDesignDetails[0] ?></strong><?php if ($qDesignKey === 'nova'): ?><span class="q-theme-tag">시그니처</span><?php endif; ?></span>
      <span class="q-theme-desc"><?= $qDesignDetails[1] ?></span>
    </button>
<?php endforeach; ?>
  </div>
  <?php /* 사이트 H4 (DESIGN 2.33 「고를 수 있게」) — 「모아 글씨 색」 넷. 줄마다 그 갈래로 그린 「모아.」 미리보기(data-wordmark-preview — app.css 의 같은 갈래 줄).
           고르면 화면 전체가 바로 바뀌고(미리보기) 「기본으로 저장」 이 테마와 같은 길로 쿠키 qaredi_wordmark 를 쓴다(design.js) · 닫으면 저장 전 선택은 되돌린다. */ ?>
  <fieldset class="q-wordmark-picker">
    <legend>모아 글씨 색</legend>
<?php foreach (Design::WORDMARKS as $qWordmarkKey => $qWordmarkLabel): ?>
    <label class="q-wordmark-option"><input type="radio" name="q-wordmark" value="<?= $qWordmarkKey ?>"<?= Design::wordmark() === $qWordmarkKey ? ' checked' : '' ?>><span class="q-wordmark-sample" data-wordmark-preview="<?= $qWordmarkKey ?>"><?php $moaWordmarkLabel = false; require __DIR__ . '/moa_wordmark.php'; ?></span><span><?= $qWordmarkLabel ?><?= $qWordmarkKey === 'd' ? ' (기본)' : '' ?></span></label>
<?php endforeach; ?>
  </fieldset>
  <label class="q-motion-control"><input type="checkbox" id="q-motion-reduce"> 움직임 줄이기<span>기기의 모션 설정도 따릅니다.</span></label>
  <p class="q-design-status" id="q-design-status" role="status" aria-live="polite"></p>
  <div class="q-design-footer"><span>밝은 화면 · 어두운 화면 모두 지원</span><button type="button" class="btn" id="q-design-save">기본으로 저장</button></div>
</dialog>
