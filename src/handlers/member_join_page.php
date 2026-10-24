<?php
declare(strict_types=1);
require_once __DIR__.'/../Member.php';
if (Auth::currentUser()!==null) Http::redirect(Http::url('/welcome'));
$joinCsrf=Member::csrf(); $memberTitle='함께 시작해요'; $memberPage='join';
require __DIR__.'/../views/member_top.php';
?>
<div class="moa-onboarding">
<aside class="moa-welcome-art"><img src="<?= Http::url('/assets/moa/lake.jpg') ?>" alt="산과 숲이 잔잔한 호수에 비치는 풍경"><div><span class="moa-kicker">초대받은 동료만</span><h1>반가워요.<br>이제 함께<br>모아볼까요?</h1><p>소소한 이야기부터 새로운 아이디어까지.<br>당신의 자리를 준비했어요.</p></div></aside>
<section class="moa-join-card">
<ol class="moa-steps" aria-label="시작 순서"><li aria-current="step">01 가입</li><li>02 로그인 방법 (선택)</li><li>03 PC 비서 연결 (선택)</li></ol>
<p class="moa-kicker">모아 가입</p><h2>나만의 계정 만들기</h2><p class="moa-muted">아이디와 비밀번호를 정해 주세요. 가입 후 OTP 또는 지문·얼굴·기기 잠금 로그인을 선택할 수 있어요. 나중에 설정해도 됩니다.</p>
<form id="join-form" class="form">
<label class="field"><span>초대 코드</span><input id="join-invite" autocomplete="off" maxlength="64" pattern="[a-f0-9]{64}" required><small>초대 링크로 들어오면 자동으로 채워져요. 링크가 없다면 관리자에게 초대를 요청해 주세요.</small></label>
<label class="field"><span>이름</span><input id="join-name" autocomplete="name" maxlength="40" placeholder="함께 부를 이름" required></label>
<label class="field"><span>아이디</span><input id="join-username" autocomplete="username" autocapitalize="none" spellcheck="false" minlength="3" maxlength="64" pattern="[a-z0-9_.-]{3,64}" placeholder="영문 소문자, 숫자, _ . -" required></label>
<label class="field"><span>비밀번호</span><input id="join-password" type="password" autocomplete="new-password" minlength="10" maxlength="1024" placeholder="10자 이상" required></label>
<label class="field"><span>비밀번호 확인</span><input id="join-confirm" type="password" autocomplete="new-password" minlength="10" maxlength="1024" required></label>
<?php /* 사이트 Q:F-13 P1 (결정 PT-4 · PT-9 · 기획 5.1 「동의·고지」) — 약관 동의 · 만 14세 이상 확인(필수 둘) + 처리방침 요약 고지.
         체크는 편의일 뿐이다 — 막는 것은 서버(member_register.php: 둘 다 true 가 아니면 400 · 계정 0). 처리방침에는 「필수 동의」 체크를 두지 않는다(확인·고지). */ ?>
<p class="moa-muted" id="join-privacy-summary">모아는 가입과 이용을 위해 이름·아이디·비밀번호(되돌릴 수 없게 변환해 저장)·소속 팀을 받고, 이용하며 만든 글·파일·메시지와 접속 기록을 회원 관리·서비스 제공·보안에 씁니다. 계정 정보는 회원인 동안, 메시지와 AI 대화는 1년, 감사 기록은 3년 보관합니다. AI 기능은 처음 쓸 때 국외 전송 동의를 따로 묻습니다. 자세한 내용은 <a href="<?= Http::url('/privacy') ?>" target="_blank" rel="noopener"><strong>개인정보 처리방침</strong></a>에서 확인하세요.</p>
<label class="field"><span class="field__label"><input id="join-terms" type="checkbox" required aria-describedby="join-terms-help"> 이용약관에 동의합니다 (필수)</span></label>
<p class="legal-links" id="join-terms-help"><a href="<?= Http::url('/terms') ?>" target="_blank" rel="noopener">이용약관 전문 보기</a></p>
<label class="field"><span class="field__label"><input id="join-age14" type="checkbox" required> 만 14세 이상입니다 (필수)</span></label>
<button class="btn moa-primary" type="submit">가입하고 다음으로 <span aria-hidden="true">→</span></button><p id="member-status" role="status" aria-live="polite"></p>
</form><p class="moa-muted">이미 계정이 있나요? <a href="<?= Http::url('/login?to=community') ?>">로그인</a></p>
</section></div>
</main><?php require __DIR__.'/../views/moa_footer.php'; /* H4 — 바닥(DESIGN 2.33 「바닥 붙이기」) */ ?></body></html>
