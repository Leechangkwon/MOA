<?php
declare(strict_types=1);
/**
 * src/handlers/invite_request_page.php — GET /request-invite (정책 public · 사이트 H2 · DESIGN 2.32 「초대 요청 화면」)
 *
 * 가입 화면과 같은 틀(모아 머리 화면 member_top.php · 패널 하나 · 폭 480). 서버가 넣는 동적 값은 가입 CSRF 하나(Member::csrf —
 * 가입 화면과 같은 쿠키 · 같은 확인). 자료 0. 로그인한 사람은 첫 화면(/)으로. 폼 · 완료 패널 · 복사는 invite-request.js.
 * $memberPage 는 'join'(가입 화면과 같은 머리 메뉴 — 모아 홈 · 업무 · 로그인) — member.js 의 가입 갈래는 가입 폼이 없으면 아무 일도 하지 않는다.
 */
require_once __DIR__.'/../Member.php';
if (Auth::currentUser() !== null) Http::redirect('/');
$joinCsrf = Member::csrf(); $memberTitle = '초대 요청'; $memberPage = 'join';
require __DIR__.'/../views/member_top.php';
?>
<section class="panel invite-request" aria-labelledby="invite-request-title">
<div class="panel__head"><h1 class="panel__title" id="invite-request-title">초대 요청</h1><p class="panel__sub">모아는 초대받은 동료만 가입해요. 아래를 남기면 관리자가 확인해요.</p></div>
<form id="invite-request-form" class="form" autocomplete="off" novalidate>
<label class="field"><span class="field__label">이름 (필수)</span><input id="invite-request-name" type="text" maxlength="40" autocomplete="name" required></label>
<label class="field"><span class="field__label">소속 — 회사 · 팀 (필수)</span><input id="invite-request-org" type="text" maxlength="80" autocomplete="organization" required></label>
<label class="field"><span class="field__label">쓰려는 이유 (필수)</span><textarea id="invite-request-reason" rows="5" maxlength="500" required aria-describedby="invite-request-count"></textarea><span class="help" id="invite-request-count" aria-live="polite">0 / 500자</span></label>
<div class="field" data-trap aria-hidden="true"><label for="invite-request-website">웹사이트</label><input id="invite-request-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
<label class="field"><span class="field__label"><input id="invite-request-agree" type="checkbox" required aria-describedby="invite-request-privacy"> 개인정보 처리방침(초대 요청 항목)을 확인했어요 (필수)</span></label>
<p class="help" id="invite-request-privacy">이름 · 소속 · 이유 · 접속 IP 를 받아 심사에만 쓰고, 처리 뒤 30일 안에 지워요. 이메일 · 전화번호는 받지 않아요. <a href="<?= Http::url('/privacy') ?>" target="_blank" rel="noopener"><strong>개인정보 처리방침</strong></a></p>
<button class="btn btn--block" type="submit">요청하기</button>
<p id="invite-request-msg" class="msg" role="alert"></p>
</form>
<div id="invite-request-done" hidden>
<p class="ok-note" role="status">요청했어요</p>
<label class="field"><span class="field__label">요청 확인 주소</span><input id="invite-request-url" type="text" readonly></label>
<button id="invite-request-copy" class="btn btn--quiet" type="button">복사</button>
<p id="invite-request-copied" class="help" role="status" aria-live="polite"></p>
<p class="notice">이 주소를 저장해 두세요 — 다시 보여 드릴 수 없어요. 이 주소에서 결과와 초대 링크를 볼 수 있어요.</p>
</div>
</section>
<script defer src="<?= Http::url('/assets/invite-request.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/invite-request.js') ?>"></script>
</main><?php require __DIR__.'/../views/moa_footer.php'; /* H4 — 바닥(DESIGN 2.33 「바닥 붙이기」) */ ?></body></html>
