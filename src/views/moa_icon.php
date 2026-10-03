<?php
declare(strict_types=1);
require_once __DIR__ . '/../Apps.php';
/** Fixed module artwork. Call with $moaIcon; no user-provided SVG or markup. */
$moaIconPath = Apps::ICON_PATHS[$moaIcon ?? 'community'] ?? Apps::ICON_PATHS['community'];
?>
<svg class="moa-module-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= htmlspecialchars($moaIconPath, ENT_QUOTES, 'UTF-8') ?>"/></svg>
