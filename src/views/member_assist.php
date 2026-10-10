<?php
/*
 * src/views/member_assist.php — 메시지 AI 도구 창(모달 · `/messages` 와 떠 있는 메신저 창이 함께 쓴다). 동작은 public/assets/member-assist.js(id 로만 찾는다).
 * 창 규칙 DESIGN 2.7(사이트 Q:F-11 지시서 C-5): 머리 ✕(`#assist-close` · aria-label 「닫기」) · 바닥 줄 「닫기」(테두리) 하나 — 바닥 「닫기」는
 * `form method="dialog"` 라 스크립트 없이 창을 닫는다(닫힘 정리는 member-assist.js 의 `close` 이벤트 한 곳). 창마다 도구·결과가 바뀌어 바닥에
 * 놓을 주요 동작 하나가 없으므로 본문의 실행·적용 단추는 테두리다(2.7 ④ 「검정은 많아야 하나이고 바닥에만」).
 */
?>
<dialog id="message-assist-dialog" data-pc-user="<?= (int)$user['id'] ?>" class="moa-assist-dialog" aria-labelledby="assist-title">
  <header class="moa-assist-head modal__head"><div><p class="moa-kicker">A LITTLE HELP, A BETTER MESSAGE</p><h2 id="assist-title">말이 조금 더 잘 전해지도록</h2><p id="assist-recipient" class="moa-muted"></p></div><button id="assist-close" type="button" class="btn btn--icon btn--plain" aria-label="닫기"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button></header>
  <div class="moa-assist-body">
    <div class="moa-assist-providers" role="group" aria-label="AI 사용 방법 선택">
      <button type="button" data-assist-provider="gemini" aria-pressed="true"><strong>Gemini 3.5 Flash · 기본</strong><small>모아에서 바로 처리</small></button>
      <button type="button" data-assist-provider="local" aria-pressed="false"><strong>GPT · 내 ChatGPT</strong><small>내 구독 · Windows PC 연결</small></button>
      <button type="button" data-assist-provider="web" aria-pressed="false"><strong>ChatGPT 웹</strong><small>새 창에서 직접 작업</small></button>
    </div>
    <div class="moa-assist-actions" role="group" aria-label="AI 도구 선택">
      <button type="button" data-assist-action="polish" aria-pressed="true"><b aria-hidden="true">✎</b><strong>문장 다듬기</strong><small>자연스럽고 정중하게</small></button>
      <button type="button" data-assist-action="spelling" aria-pressed="false"><b aria-hidden="true">✓</b><strong>맞춤법</strong><small>오탈자·띄어쓰기 확인</small></button>
      <button type="button" data-assist-action="translate" aria-pressed="false"><b aria-hidden="true">A↔가</b><strong>번역</strong><small>읽기도, 답장도 편하게</small></button>
      <button type="button" data-assist-action="image" aria-pressed="false"><b aria-hidden="true">▧</b><strong>이미지 만들기</strong><small>설명을 그림으로 · 유료</small></button>
    </div>
    <div class="moa-assist-options">
      <label id="assist-language-field" class="field" hidden><span>번역할 언어</span><select id="assist-language"><option value="en">영어</option><option value="ko">한국어</option><option value="ja">일본어</option><option value="zh">중국어</option><option value="vi">베트남어</option><option value="es">스페인어</option></select></label>
      <label id="assist-aspect-field" class="field" hidden><span>이미지 비율</span><select id="assist-aspect"><option value="1:1">정사각형</option><option value="16:9">가로로 넓게</option><option value="9:16">세로로 길게</option></select></label>
      <label id="assist-history-field" class="field"><span>이 대화에서 내가 쓴 Gemini 도구</span><select id="assist-history"><option value="">최근 결과 다시 보기</option></select></label>
    </div>
    <label class="field"><span id="assist-input-label">다듬을 문장</span><textarea id="assist-input" rows="4" aria-describedby="assist-input-count" placeholder="동료에게 전하고 싶은 내용을 적어 주세요."></textarea></label>
    <div class="moa-assist-run"><small id="assist-input-count" class="moa-muted">0 / 4,000자</small><button id="assist-restore-input" type="button" class="btn btn--quiet btn--small">처음 원문으로</button></div>
    <p id="assist-source-note" class="moa-muted" hidden>선택한 메시지만 번역합니다. 결과는 나에게만 보입니다.</p>
    <p id="assist-disclosure" class="moa-assist-disclosure">위 내용만 Google AI로 보냅니다. 무료 API에는 개인정보·기밀을 입력하지 마세요. 결과를 확인한 뒤 작성란에 넣거나 첨부할 수 있습니다.</p>
    <div id="assist-gemini-run" class="moa-assist-run"><button type="button" id="assist-run" class="btn btn--quiet" disabled>문장 다듬기</button><span id="assist-quota" class="moa-muted"></span></div>
    <section id="assist-local" class="moa-assist-result" aria-label="내 ChatGPT로 처리하기" hidden>
      <h3>내 PC에서 작업하고, 여기서 이어 쓰세요.</h3>
      <?php require __DIR__ . '/member_local_buddy.php'; ?>
      <p id="assist-local-task"></p>
      <p id="assist-local-image-note" hidden>개인 ChatGPT 로컬 연결은 이미지 생성을 지원하지 않습니다. 위에서 모아 공용 Gemini 또는 ChatGPT 웹을 선택해 주세요.</p>
      <div id="assist-local-text-steps">
        <h3>처음 한 번만 PC에서 승인하세요</h3>
        <p>같은 Windows PC의 비서에 ChatGPT로 로그인한 뒤 ‘GPT 연결’을 누르세요. 승인한 브라우저는 30일간 기억합니다.</p>
        <div class="moa-assist-run"><button id="assist-local-connect" type="button" class="btn btn--quiet">GPT 연결</button><a class="btn btn--quiet" href="tbox-buddy://connect">PC 비서 실행</a><button id="assist-local-unpair" type="button" class="btn btn--quiet">연결 해제</button><button id="assist-local-run" type="button" class="btn btn--quiet">GPT로 처리</button><button id="assist-local-cancel" type="button" class="btn btn--quiet">요청 취소</button></div>
        <p id="assist-local-state" role="status">PC 연결 전</p>
        <details><summary>구버전 비서 · 코드로 연결</summary>
          <label class="field"><span>PC 비서의 일회용 페어링 코드</span><input id="assist-local-code" type="text" maxlength="32" autocomplete="off" spellcheck="false"></label>
          <button id="assist-local-pair" type="button" class="btn btn--quiet">코드로 PC 연결</button>
          <p>PC 앱의 ‘모아 웹 연결’에서 코드를 만드세요. 이 방식은 원문 처리 때마다 PC에서 확인합니다.</p>
        </details>
        <details><summary>연결 없이 직접 복사하기</summary>
        <ol><li>아래 버튼으로 <strong>원문만 복사</strong>합니다.</li><li>PC 비서에서 위 작업·언어를 선택하고 원문을 붙여 넣어 처리합니다.</li><li>PC 비서의 ‘완료 결과 복사’를 누른 뒤 아래 결과 칸에 붙여 넣습니다.</li></ol>
        <button id="assist-local-copy" type="button" class="btn btn--quiet">원문만 복사</button>
        </details>
      </div>
    </section>
    <details id="assist-external" class="moa-assist-external" open hidden><summary>내 ChatGPT에서 직접 작업하기 ↗</summary><p>요청문을 복사하고 ChatGPT를 열어 붙여 넣으세요. 그곳에 로그인한 계정의 요금제와 한도로 사용합니다. 모아에 계정이 연결되거나 결과가 자동으로 돌아오지는 않습니다.</p><div><button id="assist-external-copy" type="button" class="btn btn--quiet btn--small">요청문 복사</button><a href="https://chatgpt.com/" target="_blank" rel="noopener noreferrer" class="btn btn--quiet btn--small">ChatGPT 열기 ↗</a></div><textarea id="assist-external-prompt" rows="4" readonly aria-label="ChatGPT에 붙여 넣을 요청문" hidden></textarea></details>
    <section id="assist-manual-result" class="moa-assist-result" aria-label="직접 가져온 결과" hidden>
      <label class="field"><span>2. 가져온 결과 붙여 넣기</span><textarea id="assist-manual-input" rows="4" placeholder="PC 비서 또는 ChatGPT에서 복사한 결과를 직접 붙여 넣으세요." aria-describedby="assist-manual-count"></textarea></label>
      <p id="assist-manual-count" class="moa-muted">0 / 4,000자 · 상대에게 아직 보내지 않습니다.</p>
      <button id="assist-manual-preview" type="button" class="btn btn--quiet">결과 미리보기</button>
    </section>
    <p id="assist-status" role="status" aria-live="polite"></p>
    <?php if (Authz::canManageUsers($user)): ?><a id="assist-settings" class="moa-assist-settings" href="<?= Http::url('/users') ?>#member-ai-settings">Gemini 사용·이미지 생성 설정 ↗</a><?php endif; ?>
    <section id="assist-result" class="moa-assist-result" aria-label="AI 결과 미리보기" hidden>
      <div class="moa-assist-result-head"><h3>보내기 전에 확인해 주세요.</h3><span>나에게만 보이는 미리보기</span></div>
      <p id="assist-result-origin" class="moa-muted"></p>
      <label id="assist-text-result" class="field"><span class="sr-only">AI가 제안한 문장</span><textarea id="assist-output" rows="5" readonly></textarea></label>
      <img id="assist-image" alt="AI로 만든 이미지 미리보기" hidden>
      <div class="moa-assist-result-actions"><button type="button" id="assist-apply" class="btn btn--quiet">작성란에 넣기</button><button type="button" id="assist-copy" class="btn btn--quiet">결과 복사</button></div>
    </section>
  </div>
  <form method="dialog" class="modal__foot"><button type="submit" class="btn btn--quiet">닫기</button></form>
</dialog>
