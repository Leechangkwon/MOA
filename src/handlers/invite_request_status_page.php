<?php
declare(strict_types=1);
/**
 * src/handlers/invite_request_status_page.php — GET /request-invite/status (정책 public · 사이트 H2 · DESIGN 2.32 「요청 확인 화면」)
 *
 * 화면 뼈대만(자료 0). 확인 코드는 주소 `#code=` 뒤에만 있어 이 요청 · 서버 로그 · Referer 에 실리지 않는다 — invite-request.js 가
 * 읽어 POST /api/invite-requests/status 의 **본문**으로 보낸다. 로그인한 사람은 첫 화면(/)으로. 틀은 초대 요청 화면과 같다.
 */
require_once __DIR__.'/../Member.php';
if (Auth::currentUser() !== null) Http::redirect('/');
$joinCsrf = Member::csrf(); $memberTitle = '초대 요청 확인'; $memberPage = 'join';
require __DIR__.'/../views/member_top.php';
?>
<section class="panel invite-request" aria-labelledby="invite-status-title" data-invite-status>
<div class="panel__head"><h1 class="panel__title" id="invite-status-title">초대 요청 확인</h1><p class="panel__sub">관리자가 확인한 결과를 여기서 볼 수 있어요.</p></div>
<p id="invite-status-text" role="status" aria-live="polite">확인하고 있어요…</p>
<?php /* 사이트 H2b — 승인된 요청은 「초대 링크 받기」를 눌러야 초대가 만들어진다(보기만으로는 0 · 다시 받으면 앞 링크는 거둠 · 세 번까지) */ ?>
<button id="invite-status-issue" class="btn btn--block" type="button" hidden>초대 링크 받기</button>
<p id="invite-status-issue-help" class="help" hidden>다시 받으면 앞의 링크는 쓸 수 없어요 (최대 3번)</p>
<label class="field" id="invite-status-link-field" hidden><span class="field__label">초대 링크 (7일 · 한 번)</span><input id="invite-status-link" type="text" readonly></label>
<a id="invite-status-join" class="btn btn--block" href="<?= Http::url('/join') ?>" hidden>가입하기</a>
<a id="invite-status-login" class="btn btn--quiet btn--block" href="<?= Http::url('/login') ?>" hidden>로그인</a>
<p class="help"><a href="<?= Http::url('/request-invite') ?>">초대 요청 화면으로</a></p>
</section>
<script defer src="<?= Http::url('/assets/invite-request.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/invite-request.js') ?>"></script>
</main><?php require __DIR__.'/../views/moa_footer.php'; /* H4 — 바닥(DESIGN 2.33 「바닥 붙이기」) */ ?></body></html>
