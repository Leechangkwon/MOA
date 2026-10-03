<?php
declare(strict_types=1);
require_once __DIR__.'/../Member.php';
$memberTitle='시작 안내'; $memberPage='welcome'; $installer=Member::installer();
require __DIR__.'/../views/member_top.php';
?>
<div class="moa-setup-heading"><p class="moa-kicker">MAKE YOURSELF AT HOME</p><h1>우리, 여기서 시작해요.</h1><p>계정 보호부터 비서 연결까지 한 걸음씩 안내할게요.</p></div>
<ol class="moa-steps" id="welcome-steps"><li>01 계정 ✓</li><li aria-current="step">02 OTP 설정</li><li>03 비서 연결</li><li>04 시작</li></ol>
<p id="member-status" role="status" aria-live="polite"></p>
<section class="moa-setup-card" id="welcome-mfa" hidden><span class="moa-step-number">02</span><h2>내 계정을 안전하게</h2><p>휴대폰의 Google Authenticator 또는 Microsoft Authenticator에서 QR 코드를 추가해 주세요.</p>
<button class="btn" id="welcome-enroll" type="button">OTP 등록 시작</button>
<div id="welcome-qr-area" hidden><canvas id="welcome-qr" aria-label="OTP 등록 QR 코드"></canvas><p><a id="welcome-otp-link">이 휴대폰의 인증 앱에 추가</a></p><details><summary>QR 코드를 사용할 수 없나요?</summary><p>인증 앱에서 ‘설정 키 입력’과 ‘시간 기반’을 선택하고 아래 키를 입력해 주세요.</p><code id="welcome-secret"></code></details><form id="welcome-otp-form" class="form"><label class="field"><span>인증 앱의 6자리 코드</span><input id="welcome-otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button class="btn" type="submit">확인하고 다음으로 →</button></form></div></section>
<section class="moa-setup-card" id="welcome-buddy" hidden><span class="moa-step-number">03</span><h2>든든한 AI 비서, tbox-buddy</h2><p>PC에 비서를 설치하고 필요한 기능만 연결하세요. 나중에 이 화면으로 돌아와 설정할 수도 있어요.</p>
<section id="welcome-local-buddy" aria-label="개인 ChatGPT 로컬 비서"><h3>개인 ChatGPT로 문장 다듬기·번역</h3><p class="moa-muted">공용 Gemini는 모아 메신저에서 바로 사용합니다. 개인 ChatGPT 비서는 PC에 따로 설치하고 본인 계정으로 연결합니다. 아래에서 현재 공유본과 사용 조건을 확인하세요.</p><?php require __DIR__ . '/../views/member_local_buddy.php'; ?></section>
<div class="moa-install-note"><h3>1. PC에 설치하기</h3>
<?php if($installer!==null): ?><a class="btn" href="<?= $e($installer) ?>" target="_blank" rel="noopener noreferrer">tbox-buddy 설치 파일 받기 ↗</a><p class="moa-muted">다운로드한 파일을 실행한 뒤 비서의 연결 설정을 열어 주세요.</p><?php else: ?><p>설치 파일 배포를 준비하고 있어요. 관리자가 다운로드 주소를 등록하면 여기에 표시됩니다.</p><p class="moa-muted">이미 설치했다면 아래에서 토큰을 연결하세요.</p><?php endif; ?></div>
<h3>2. 기존 비서의 모아 업무 기능 연결</h3><p class="moa-muted">아래 토큰은 파일·커뮤니티 등 모아 업무 기능을 지원하는 기존 tbox-buddy용입니다. 위의 개인 ChatGPT 독립 공유본에는 입력하지 않습니다. 필요한 기능을 선택해 각각 발급하고, 발급할 때마다 새 OTP 코드를 사용해 주세요.</p>
<form id="welcome-token-form" class="form"><label class="field"><span>연결할 기능</span><select id="welcome-scope"></select></label><label class="field"><span>이 PC 이름</span><input id="welcome-token-name" maxlength="50" value="내 PC 비서" required></label><label class="field"><span>비밀번호 확인</span><input id="welcome-password" type="password" autocomplete="current-password" maxlength="1024" required></label><label class="field"><span>새 OTP 코드</span><input id="welcome-token-otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button type="submit" class="btn">연결 토큰 발급</button></form>
<div id="welcome-token-result" class="moa-token-result" hidden><h3>3. 비서 설정에 붙여넣기</h3><p>tbox-buddy의 해당 기능 토큰 칸에 붙여넣고 저장해 주세요. 이 토큰은 한 번만 표시됩니다.</p><label class="field"><span>연결 주소</span><input id="welcome-server" readonly value="<?= $e(rtrim(Env::get('APP_URL'),'/').TBOX_BASE) ?>"></label><label class="field"><span id="welcome-token-label">토큰</span><input id="welcome-token" type="password" readonly autocomplete="off"></label><button id="welcome-token-copy" type="button" class="btn btn--quiet">토큰 복사</button><p id="welcome-token-expiry" class="moa-muted"></p><p class="moa-muted">AI 모델 제공자의 API 키가 필요한 경우에는 비서 앱에서 별도로 설정합니다. 모아 연결 토큰과 다른 값입니다.</p></div>
<div class="moa-setup-actions"><a class="btn moa-primary" href="/">모아 둘러보기 →</a><a href="<?= Http::communityUrl('/') ?>">커뮤니티로 가기</a><a href="<?= Http::url('/messages') ?>">동료에게 인사하기</a></div><p class="moa-muted">비서 연결은 나중에 해도 됩니다. 내 계정의 ‘시작 안내’에서 이어갈 수 있어요.</p>
</section></main></body></html>
