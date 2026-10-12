<?php declare(strict_types=1); ?>
<dialog id="document-trash-dialog" class="modal modal--form" aria-labelledby="document-trash-title">
  <div class="modal__head"><h2 id="document-trash-title" class="modal__title">휴지통으로 옮기기</h2></div>
  <div class="modal__body">
    <p id="document-trash-lead"></p>
    <ul id="document-trash-items"></ul>
    <p class="help">선택한 문서의 하위 문서도 함께 이동합니다. 30일 동안 휴지통에서 복원할 수 있습니다.</p>
    <p id="document-trash-msg" class="notice" role="status"></p>
  </div>
  <div class="modal__foot">
    <button type="button" class="btn btn--quiet" id="document-trash-cancel">취소</button>
    <button type="button" class="btn" id="document-trash-confirm">휴지통으로 옮기기</button>
  </div>
</dialog>
<dialog id="document-trash-results" class="modal modal--form" aria-label="문서 휴지통 진행">
  <section id="document-trash-panel" hidden>
    <div class="modal__head"><h2 id="document-trash-progress-title" class="modal__title" tabindex="-1"></h2></div>
    <div class="modal__body">
      <table class="upload-status"><thead><tr><th>문서</th><th>결과</th></tr></thead><tbody id="document-trash-body"></tbody></table>
      <div id="document-trash-stop" hidden>
        <p id="document-trash-stop-text" role="status"></p>
        <a id="document-trash-login" target="_blank" rel="noopener" href="<?= Http::url('/login') ?>" hidden>로그인 화면 열기</a>
        <button class="btn" id="document-trash-resume" type="button">이어서 하기</button>
      </div>
      <p id="document-trash-summary" role="status"></p>
    </div>
    <div class="modal__foot">
      <button class="btn btn--quiet" id="document-trash-undo" type="button" hidden>되돌리기</button>
      <button class="btn btn--quiet" id="document-trash-close" type="button">닫기</button>
    </div>
  </section>
</dialog>
