<?php
declare(strict_types=1);
require_once __DIR__.'/../Member.php';
// Focused PC onboarding; existing route authentication and account guards remain in force.
if (($_GET['section'] ?? null) === 'pc-assistant') {
    $memberTitle='PC 비서 연결'; $memberPage='buddy-guide';
    require __DIR__.'/../views/member_top.php';
    require __DIR__.'/../views/member_pc_buddy_guide.php';
    echo '</main></body></html>';
    return;
}

$memberTitle='시작 안내'; $memberPage='welcome'; $installer=Member::installer();
$memberWindow=true; // 사이트 Q:F-11 B-1 — 이 화면에도 떠 있는 메신저 창(nav 「메신저」가 연다)
require __DIR__.'/../views/member_top.php';
?>
<div class="moa-setup-heading"><p class="moa-kicker">MAKE YOURSELF AT HOME</p><h1>우리, 여기서 시작해요.</h1><p>계정 보호부터 비서 연결까지 한 걸음씩 안내할게요.</p></div>
<ol class="moa-steps" id="welcome-steps"><li>01 계정 ✓</li><li aria-current="step">02 로그인 방법 (선택)</li><li>03 비서 연결 (선택)</li><li>04 시작</li></ol>
<p id="member-status" role="status" aria-live="polite"></p>
<section class="moa-setup-card" id="welcome-security"><span class="moa-step-number">02</span><h2>로그인 방법을 골라 주세요</h2>
<p id="welcome-security-note">비밀번호로 바로 시작할 수 있어요. OTP 또는 지문·얼굴·기기 잠금(패스키)을 원하는 때 추가하세요.</p>
<div class="moa-setup-actions"><button class="btn" id="welcome-choose-passkey" type="button" hidden>지문·얼굴·기기 잠금 등록</button><button class="btn btn--quiet" id="welcome-choose-otp" type="button">OTP 인증 앱 등록</button><a class="btn btn--quiet" id="welcome-security-later" href="#welcome-buddy">나중에 설정하고 계속 →</a></div>
<p id="welcome-passkey-unavailable" class="moa-muted" hidden>이 브라우저에서 패스키를 사용할 수 없어요. Chrome·Safari·Edge 등 기기의 기본 브라우저에서 열어 주세요. OTP나 비밀번호로도 시작할 수 있습니다.</p>
<section id="welcome-passkey" hidden><h3>이 기기를 등록하기</h3><p>기기의 지문·얼굴 또는 잠금 화면으로 본인을 확인합니다. 생체 정보는 모아에 저장되지 않아요. 다음 로그인에서 패스키 또는 비밀번호를 선택할 수 있습니다.</p><form id="welcome-passkey-form" class="form"><label class="field"><span>기기 이름</span><input id="welcome-passkey-name" maxlength="40" value="내 기기" required></label><label class="field"><span>비밀번호 확인</span><input id="welcome-passkey-password" type="password" autocomplete="current-password" maxlength="1024" required></label><label class="field" id="welcome-passkey-otp-field" hidden><span>현재 OTP 코드</span><input id="welcome-passkey-otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6"></label><p id="welcome-passkey-otp-note" class="moa-muted" hidden>이미 OTP를 사용 중이면 새 기기 등록을 보호하기 위해 이번에만 코드를 확인합니다. 이후 패스키 로그인에는 OTP가 필요하지 않습니다.</p><button class="btn" type="submit">기기에서 확인하고 등록</button><button class="btn btn--quiet" id="welcome-passkey-cancel" type="button">나중에</button></form></section>
<section id="welcome-mfa" hidden><h3>OTP 인증 앱 등록</h3><p>OTP를 켜면 비밀번호 로그인 때 인증 앱의 6자리 코드를 함께 입력해요. 패스키로 로그인할 때는 입력하지 않습니다.</p>
<details><summary>인증 앱이 없나요? 설치 안내 보기</summary><p>휴대폰에 아래 앱 중 하나를 설치하고 돌아와서 ‘OTP 등록 시작’을 눌러 주세요.</p><ul><li>Google Authenticator: <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank" rel="noopener noreferrer">Android 설치</a> · <a href="https://apps.apple.com/app/google-authenticator/id388497605" target="_blank" rel="noopener noreferrer">iPhone 설치</a></li><li>Microsoft Authenticator: <a href="https://play.google.com/store/apps/details?id=com.azure.authenticator" target="_blank" rel="noopener noreferrer">Android 설치</a> · <a href="https://apps.apple.com/app/microsoft-authenticator/id983156458" target="_blank" rel="noopener noreferrer">iPhone 설치</a></li></ul></details>
<button class="btn" id="welcome-enroll" type="button">OTP 등록 시작</button>
<div id="welcome-qr-area" hidden><canvas id="welcome-qr" aria-label="OTP 등록 QR 코드"></canvas><p><a id="welcome-otp-link">이 휴대폰의 인증 앱에 추가</a></p><details><summary>QR 코드를 사용할 수 없나요?</summary><p>인증 앱에서 ‘설정 키 입력’과 ‘시간 기반’을 선택하고 아래 키를 입력해 주세요.</p><code id="welcome-secret"></code></details><form id="welcome-otp-form" class="form"><label class="field"><span>인증 앱의 6자리 코드</span><input id="welcome-otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button class="btn" type="submit">OTP 확인하고 저장</button></form></div></section>
</section>
<section class="moa-setup-card" id="welcome-buddy" hidden><span class="moa-step-number">03</span><h2>앱과 AI 비서도 원하는 만큼</h2><p>설치와 비서 연결은 선택이에요. 지금 시작하거나 나중에 내 계정의 ‘시작 안내’에서 이어갈 수 있습니다.</p>
<?php require __DIR__ . '/../views/member_install.php'; ?>
<section id="welcome-local-buddy" aria-label="개인 ChatGPT 로컬 비서"><?php require __DIR__ . '/../views/member_local_buddy.php'; ?></section>
<details id="welcome-external-buddy"><summary>외부 PC 비서용 연결 토큰 <small>(선택 · 고급 설정)</small></summary>
<?php if($installer!==null): ?><div class="moa-install-note"><h3>관리자가 제공한 PC 비서</h3><a class="btn" href="<?= $e($installer) ?>" target="_blank" rel="noopener noreferrer">PC 비서 다운로드 ↗</a><p class="moa-muted">모바일용 모아 앱과 별도입니다. 배포 파일의 설치 안내와 지원 운영체제를 확인하고, 모아 업무 기능 연결을 지원하는 경우 아래 토큰을 사용하세요.</p></div><?php endif; ?>
<h3>기존 비서의 모아 업무 기능 연결</h3><p class="moa-muted">이미 모아 업무용 tbox-buddy를 사용하는 경우에만 발급하세요. 설치형 모아 웹앱과 개인 ChatGPT 독립 공유본에는 이 토큰이 필요하지 않습니다. ChatGPT 원격 연결은 별도 승인 화면을 사용합니다. 필요한 기능을 선택해 각각 발급할 수 있어요. 장기 연결 토큰을 사용하려면 OTP 설정이 필요하며 발급할 때마다 새 코드를 확인합니다.</p>
<p id="welcome-token-protection" class="moa-muted" hidden>웹앱은 로그인만으로 사용할 수 있어요. 외부 비서 연결 토큰이 필요한 경우 <a href="#welcome-security">OTP를 먼저 설정</a>해 주세요.</p>
<form id="welcome-token-form" class="form"><label class="field"><span>연결할 기능</span><select id="welcome-scope"></select></label><label class="field"><span>이 PC 이름</span><input id="welcome-token-name" maxlength="50" value="내 PC 비서" required></label><label class="field"><span>비밀번호 확인</span><input id="welcome-password" type="password" autocomplete="current-password" maxlength="1024" required></label><label class="field"><span>새 OTP 코드</span><input id="welcome-token-otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button type="submit" class="btn">연결 토큰 발급</button></form>
<div id="welcome-token-result" class="moa-token-result" hidden><h3>3. 비서 설정에 붙여넣기</h3><p>tbox-buddy의 해당 기능 토큰 칸에 붙여넣고 저장해 주세요. 이 토큰은 한 번만 표시됩니다.</p><label class="field"><span>연결 주소</span><input id="welcome-server" readonly value="<?= $e(rtrim(Env::get('APP_URL'),'/').TBOX_BASE) ?>"></label><label class="field"><span id="welcome-token-label">토큰</span><input id="welcome-token" type="password" readonly autocomplete="off"></label><button id="welcome-token-copy" type="button" class="btn btn--quiet">토큰 복사</button><p id="welcome-token-expiry" class="moa-muted"></p><p class="moa-muted">AI 모델 제공자의 API 키가 필요한 경우에는 비서 앱에서 별도로 설정합니다. 모아 연결 토큰과 다른 값입니다.</p></div>
</details>
<h3>ChatGPT에서 내 글·사진과 여행 계획 다루기</h3>
<p class="moa-muted">원격 연결을 사용하면 모아 계정으로 읽기·사진·쓰기 권한을 고르고 언제든 해제할 수 있어요. ChatGPT 메뉴와 요금제·조직 설정에 따라 이용 가능 여부가 달라지며, 휴대폰 지원은 실제 계정에서 확인해 주세요.</p>
<p><a class="btn btn--quiet" href="<?= Http::url('/mcp-connect') ?>">ChatGPT 연결 안내와 설정 →</a></p>
<div class="moa-setup-actions"><a class="btn moa-primary" href="/">설정 없이 모아 둘러보기 →</a><a href="<?= Http::communityUrl('/') ?>">커뮤니티로 가기</a><a href="<?= Http::url('/messages') ?>">동료에게 인사하기</a></div><p class="moa-muted">비서 연결은 나중에 해도 됩니다. 내 계정의 ‘시작 안내’에서 이어갈 수 있어요.</p>
</section>
<?php if ($memberMessenger): $messengerUser = $user; require __DIR__ . '/../views/messenger_window.php'; endif; ?>
</main></body></html>
