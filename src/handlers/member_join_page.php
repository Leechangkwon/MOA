<?php
declare(strict_types=1);
require_once __DIR__.'/../Member.php';
if (Auth::currentUser()!==null) Http::redirect(Http::url('/welcome'));
$joinCsrf=Member::csrf(); $memberTitle='함께 시작해요'; $memberPage='join';
require __DIR__.'/../views/member_top.php';
?>
<div class="moa-onboarding">
<aside class="moa-welcome-art"><img src="<?= Http::url('/assets/moa/lake.jpg') ?>" alt="산과 숲이 잔잔한 호수에 비치는 풍경"><div><span class="moa-kicker">A PLACE FOR US</span><h1>반가워요.<br>이제 함께<br>모아볼까요?</h1><p>소소한 이야기부터 새로운 아이디어까지.<br>당신의 자리를 준비했어요.</p></div></aside>
<section class="moa-join-card">
<ol class="moa-steps" aria-label="시작 순서"><li aria-current="step">01 가입</li><li>02 로그인 방법 (선택)</li><li>03 비서 연결 (선택)</li></ol>
<p class="moa-kicker">WELCOME TO MOA</p><h2>나만의 계정 만들기</h2><p class="moa-muted">아이디와 비밀번호를 정해 주세요. 가입 후 OTP 또는 지문·얼굴·기기 잠금 로그인을 선택할 수 있어요. 나중에 설정해도 됩니다.</p>
<form id="join-form" class="form">
<label class="field"><span>초대 코드</span><input id="join-invite" autocomplete="off" maxlength="64" pattern="[a-f0-9]{64}" required><small>초대 링크로 들어오면 자동으로 채워져요. 링크가 없다면 관리자에게 초대를 요청해 주세요.</small></label>
<label class="field"><span>이름</span><input id="join-name" autocomplete="name" maxlength="40" placeholder="함께 부를 이름" required></label>
<label class="field"><span>아이디</span><input id="join-username" autocomplete="username" autocapitalize="none" spellcheck="false" minlength="3" maxlength="64" pattern="[a-z0-9_.-]{3,64}" placeholder="영문 소문자, 숫자, _ . -" required></label>
<label class="field"><span>비밀번호</span><input id="join-password" type="password" autocomplete="new-password" minlength="10" maxlength="1024" placeholder="10자 이상" required></label>
<label class="field"><span>비밀번호 확인</span><input id="join-confirm" type="password" autocomplete="new-password" minlength="10" maxlength="1024" required></label>
<button class="btn moa-primary" type="submit">가입하고 다음으로 <span aria-hidden="true">→</span></button><p id="member-status" role="status" aria-live="polite"></p>
</form><p class="moa-muted">이미 계정이 있나요? <a href="<?= Http::url('/login?to=community') ?>">로그인</a></p>
</section></div>
</main></body></html>
