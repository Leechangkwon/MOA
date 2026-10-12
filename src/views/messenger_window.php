<?php
declare(strict_types=1);
/**
 * src/views/messenger_window.php — 떠 있는 메신저 창 (사이트 Q:F-11 지시서 B-1 · DESIGN 2.17 · 기획 4.7)
 *
 * 모든 로그인 화면(t-box·커뮤니티 셸 · 시작 안내 · 로그인한 런처)이 이 한 벌을 include 한다. `/messages` 는 페이지가 붙박이라 넣지 않는다.
 * 안쪽은 `/messages` 와 **같은 id**(message-*)라 member-ai.js · member-assist.js 가 그대로 돈다. 동작은 public/assets/messenger.js('window').
 *
 * 받는 변수: $messengerUser (array|null) — 그 화면의 현재 사용자(라우터·세션이 DB 에서 읽은 값. 요청 값이 아니다).
 *
 * ★ 그리는 조건은 판정 함수 하나 — `Authz::canCommunityWrite` 이고 「역할로 보기」가 아닐 때(서버 `Member::requireMessenger` 가 403 을 주지
 *   않는 조건과 같다). 역할 문자열 비교 0. 손님·역할로 보기에게는 창도 스크립트도 나가지 않는다. 화면은 다시 `/api/me` 로 확인한다.
 * ★ 동적 값은 고정 경로(`Http::url`)와 자산 파일 시각(정수)뿐 — 사용자 입력 0. 인라인 style·script·on* 0(CSP). 닫기 아이콘은 고정 SVG.
 * ★ 비모달 `dialog` 를 `show()` 로 연다(스크립트). 이 파일은 닫힌 채로 내보낸다. 층은 `--z-floating`(app.css).
 */
if (!(isset($messengerUser) && is_array($messengerUser) && Authz::canCommunityWrite($messengerUser)
    && ($messengerUser['acting'] ?? false) !== true)) {
    return;
}
$messengerAsset = static function (string $file): string {
    return Http::url('/assets/' . $file) . '?v=' . (int) filemtime(__DIR__ . '/../../public/assets/' . $file);
};
?>
<dialog id="messenger-window" class="floating" aria-labelledby="messenger-window-title" data-view="list">
  <div class="modal__head">
    <button type="button" id="messenger-back" class="btn btn--quiet btn--small" hidden>‹ 목록</button>
    <h2 id="messenger-window-title" class="modal__title"><span id="messenger-move-handle" class="messenger-move-handle" tabindex="0" role="button" aria-label="메신저 창 이동" aria-describedby="messenger-move-help">메신저</span></h2>
    <button type="button" id="messenger-position-reset" class="btn btn--quiet btn--small" aria-label="메신저 창을 기본 위치로">기본 위치</button>
    <a class="btn btn--quiet btn--small" href="<?= Http::url('/messages') ?>">크게 보기</a>
    <button type="button" class="btn btn--icon btn--plain" data-messenger-close aria-label="닫기"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
  </div>
  <span id="messenger-move-help" class="sr-only">창 제목을 끌거나 방향키로 이동하세요. Shift와 방향키를 함께 누르면 크게 이동합니다.</span>
  <p id="messenger-window-status" role="status" aria-live="polite"></p>
  <div class="moa-messenger">
    <aside class="moa-people" aria-label="대화 상대">
      <button id="message-ai" class="moa-person moa-person--ai" type="button" aria-pressed="false">
        <span class="moa-avatar moa-ai-spark" aria-hidden="true">✦</span><span>모아 AI <b class="moa-ai-badge">AI</b></span><small>공용 Gemini · 나만의 대화 기록</small>
      </button>
      <label class="field"><span>동료 · 1:1 대화</span><input type="search" id="message-search" placeholder="이름 또는 아이디" maxlength="64"></label>
      <div id="message-people"></div>
    </aside>
    <section class="moa-conversation" aria-label="대화">
      <header>
        <div class="moa-chat-heading"><div><h3 id="message-peer-name">대화 상대를 선택하세요</h3><p id="message-peer-meta" class="moa-muted">대화 상대를 골라 주세요.</p></div><button id="message-ai-new" type="button" class="btn btn--quiet btn--small" hidden>＋ 새 대화</button></div>
        <div id="message-ai-tools" class="moa-ai-tools" hidden><label><span class="sr-only">내 AI 대화 기록</span><select id="message-ai-threads" aria-label="내 AI 대화 기록"><option value="0">새 대화</option></select></label><span id="message-ai-remaining"></span></div>
      </header>
      <div id="message-ai-notice" class="moa-ai-notice" hidden><span>AI에게 보낸 내용과 최근 AI 대화가 Google로 전송됩니다. 무료 API에는 개인정보·기밀을 입력하지 마세요.</span><?php if (Authz::canManageUsers($messengerUser)): ?><a href="<?= Http::url('/users') ?>#member-ai-settings">AI 연결 설정 ↗</a><?php endif; ?></div>
      <button class="btn btn--quiet" id="message-older" type="button" hidden>이전 대화 불러오기</button>
      <div id="message-list" class="moa-message-list" role="log" aria-label="메시지" aria-live="polite"></div>
    </section>
  </div>
  <div id="message-assist-toolbar" class="moa-compose-tools" hidden><button id="message-assist" type="button" class="btn btn--quiet btn--small" aria-haspopup="dialog">✦ AI 도구</button><span>문장 다듬기 · 맞춤법 · 번역 · 이미지</span><button id="message-assist-undo" type="button" class="btn btn--quiet btn--small" hidden>적용 전으로</button></div>
  <form id="message-form" class="moa-compose"><div id="message-attachment" class="moa-attachment-draft" hidden><img id="message-attachment-image" alt="보낼 AI 이미지"><div><strong>AI 이미지 첨부</strong><small>보내기를 누르면 상대에게 전달됩니다.</small></div><button id="message-attachment-remove" type="button" class="btn btn--quiet btn--small">첨부 취소</button></div><label class="field"><span class="sr-only">메시지</span><textarea id="message-text" rows="2" placeholder="메시지를 입력하세요. Enter로 전송, Shift+Enter로 줄바꿈" required disabled></textarea></label><button type="button" class="btn btn--quiet" data-messenger-close>닫기</button><button id="message-send" class="btn" type="submit" disabled>보내기 ↑</button></form>
</dialog>
<?php
// AI 도구 창(모달 — 떠 있는 창 **위** 최상위 층에 뜬다). 그 뷰는 `$user` 를 읽으므로 따로 묶어 부른다(부르는 화면의 변수를 덮지 않는다).
(static function (array $user): void { require __DIR__ . '/member_assist.php'; })($messengerUser);
?>
<script defer src="<?= $messengerAsset('member-ai.js') ?>"></script>
<script defer src="<?= $messengerAsset('member-assist.js') ?>"></script>
<script type="module" src="<?= $messengerAsset('messenger.js') ?>"></script>
