<?php
declare(strict_types=1);
?>
<dialog id="messenger-unavailable" class="modal modal--form" aria-labelledby="messenger-unavailable-title" data-path="<?= Http::url('/messages') ?>">
  <div class="modal__head"><h2 class="modal__title" id="messenger-unavailable-title">메신저 이용 안내</h2></div>
  <div class="modal__body"><p>메신저는 동료와 1:1로 대화하는 기능입니다.</p><p>현재 계정 또는 역할로 보기 상태에서는 이용할 수 없습니다. 손님 계정은 허용된 게시글과 자료를 읽을 수 있습니다.</p><p class="muted">업무상 이용이 필요하면 관리자에게 계정 권한을 확인해 주세요.</p></div>
  <form method="dialog" class="modal__foot"><button class="btn" autofocus>확인</button></form>
</dialog>
<script defer src="<?= Http::url('/assets/messenger-access.js') ?>?v=<?= (int) filemtime(__DIR__.'/../../public/assets/messenger-access.js') ?>"></script>
