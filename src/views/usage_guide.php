<?php
declare(strict_types=1);
// Include once, only on authenticated screens. Public share pages do not load this.
if (!isset($usageGuideIncluded) && isset($user) && is_array($user) && isset($user['id'])):
$usageGuideIncluded = true;
?>
<link rel="stylesheet" href="<?= Http::url('/assets/usage-guide.css') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/usage-guide.css') ?>">
<div id="usage-guide-root" data-base="<?= htmlspecialchars(rtrim(Http::communityUrl('/'), '/'), ENT_QUOTES, 'UTF-8') ?>" data-app-base="<?= htmlspecialchars(rtrim(Http::url('/'), '/'), ENT_QUOTES, 'UTF-8') ?>">
  <button class="usage-guide-launch" type="button" data-usage-guide-open aria-label="사용 안내 열기">? <span>사용 안내</span></button>
  <p class="usage-guide-notice" id="usage-guide-notice" role="status" hidden></p>
</div>
<script defer src="<?= Http::url('/assets/usage-guide.js') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/usage-guide.js') ?>"></script>
<?php endif; ?>
