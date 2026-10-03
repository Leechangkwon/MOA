<?php
declare(strict_types=1);
require_once __DIR__.'/../Member.php'; Member::requireMessenger($user);
// Only the authenticated messenger may connect to the explicitly paired PC helper.
header("Content-Security-Policy: default-src 'self'; connect-src 'self' http://127.0.0.1:43821; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
$memberTitle='메신저';$memberPage='messages'; require __DIR__.'/../views/member_top.php';
?>
<div class="moa-section-head"><div><p class="moa-kicker">SAY HELLO</p><h1>마음을 잇는 대화</h1><p class="moa-muted">함께하는 사람에게, 때로는 든든한 AI에게. 편하게 말을 걸어 보세요.</p></div><a href="/" class="btn btn--quiet">홈으로</a></div>
<p id="member-status" role="status" aria-live="polite"></p>
<div class="moa-messenger"><aside class="moa-people">
<div class="moa-messenger-self" aria-label="현재 로그인한 내 계정">
  <span class="moa-avatar" aria-hidden="true">나</span>
  <div><span class="moa-self-label">내 계정</span><strong id="message-self-name"><?= $e($user['username']) ?></strong></div>
  <a href="<?= Http::url('/settings') ?>" aria-label="내 계정 설정">설정</a>
</div>
<button id="message-ai" class="moa-person moa-person--ai" type="button" aria-pressed="false">
  <span class="moa-avatar moa-ai-spark" aria-hidden="true">✦</span><span>모아 AI <b class="moa-ai-badge">AI</b></span><small>공용 Gemini · 나만의 대화 기록</small>
</button>
<a class="moa-assist-settings" href="<?= Http::url('/welcome') ?>#welcome-local-buddy">내 PC의 개인 ChatGPT 사용 안내 ↗</a>
<label class="field"><span>함께하는 사람들</span><input type="search" id="message-search" placeholder="이름 또는 아이디" maxlength="64"></label><div id="message-people"></div>
</aside><section class="moa-conversation" aria-label="대화"><header>
<div class="moa-chat-heading"><div><h2 id="message-peer-name">어떤 이야기를 나눌까요?</h2><p id="message-peer-meta" class="moa-muted">대화 상대를 골라 주세요.</p></div><button id="message-ai-new" type="button" class="btn btn--quiet btn--small" hidden>＋ 새 대화</button></div>
<div id="message-ai-tools" class="moa-ai-tools" hidden><label><span class="sr-only">내 AI 대화 기록</span><select id="message-ai-threads" aria-label="내 AI 대화 기록"><option value="0">새 대화</option></select></label><span id="message-ai-remaining"></span></div>
</header>
<div id="message-ai-notice" class="moa-ai-notice" hidden><span>AI에게 보낸 내용과 최근 AI 대화가 Google로 전송됩니다. 무료 API에는 개인정보·기밀을 입력하지 마세요.</span><?php if (Authz::canManageUsers($user)): ?><a href="<?= Http::url('/users') ?>#member-ai-settings">AI 연결 설정 ↗</a><?php endif; ?></div>
<button class="btn btn--quiet" id="message-older" type="button" hidden>이전 대화 불러오기</button>
<div id="message-list" class="moa-message-list" role="log" aria-label="메시지" aria-live="polite"><div class="moa-empty"><span aria-hidden="true">☏</span><h3>짧은 인사로 시작해요.</h3><p>안부도, 궁금한 것도, 함께 나누고 싶은 소식도.</p></div></div>
<div id="message-assist-toolbar" class="moa-compose-tools" hidden><button id="message-assist" type="button" class="btn btn--quiet btn--small" aria-haspopup="dialog">✦ AI 도구</button><span>문장 다듬기 · 맞춤법 · 번역 · 이미지</span><button id="message-assist-undo" type="button" class="btn btn--quiet btn--small" hidden>적용 전으로</button></div>
<form id="message-form" class="moa-compose"><div id="message-attachment" class="moa-attachment-draft" hidden><img id="message-attachment-image" alt="보낼 AI 이미지"><div><strong>AI 이미지 첨부</strong><small>보내기를 누르면 상대에게 전달됩니다.</small></div><button id="message-attachment-remove" type="button" class="btn btn--quiet btn--small">첨부 취소</button></div><label class="field"><span class="sr-only">메시지</span><textarea id="message-text" rows="2" placeholder="메시지를 입력하세요. Enter로 전송, Shift+Enter로 줄바꿈" required disabled></textarea></label><button id="message-send" class="btn" type="submit" disabled>보내기 ↑</button></form>
</section></div>
<?php require __DIR__ . '/../views/member_assist.php'; ?>
</main></body></html>
