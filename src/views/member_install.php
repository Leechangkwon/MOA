<?php declare(strict_types=1); ?>
<section class="moa-install-note" id="member-install" aria-labelledby="member-install-title">
  <h3 id="member-install-title">휴대폰·컴퓨터에 모아 앱 추가하기 <small>(선택)</small></h3>
  <p>모아를 홈 화면이나 바탕화면에서 바로 열 수 있어요. 사진 기록과 메신저의 Gemini 비서는 웹과 같은 계정을 사용합니다. <strong>별도 연결 토큰을 발급하거나 붙여 넣을 필요가 없어요.</strong></p>
  <div class="moa-setup-actions">
    <button class="btn" id="member-install-start" type="button" aria-controls="member-install-help" aria-expanded="false">앱 설치 방법 보기</button>
    <a class="btn btn--quiet" href="<?= Http::communityUrl('/') ?>">설치 없이 모아 시작하기</a>
  </div>
  <p id="member-install-status" role="status" aria-live="polite" class="moa-muted">설치는 선택 사항입니다. 브라우저에서도 같은 기능을 사용할 수 있어요.</p>
  <div id="member-install-help" hidden>
    <p id="member-install-browser-note" class="notice" hidden>카카오톡 등 앱 안의 브라우저에서는 설치가 제한될 수 있어요. 브라우저 메뉴의 ‘다른 브라우저로 열기’를 선택해 Chrome·Safari·Edge에서 이 화면을 다시 열어 주세요.</p>
    <label class="field"><span>설치할 기기</span><select id="member-install-device"><option value="android">Android 휴대폰·태블릿</option><option value="ios">iPhone·iPad</option><option value="desktop">Windows·Mac 컴퓨터</option></select></label>
    <div id="member-install-android" data-install-guide="android"><h4>Android에 추가하기</h4><ol><li>Chrome에서 모아를 열고 오른쪽 위 메뉴(⋮)를 누릅니다.</li><li>‘홈 화면에 추가’ 또는 ‘앱 설치’를 선택하고 확인합니다. 삼성 인터넷에서는 메뉴의 ‘현재 페이지 추가 → 홈 화면’을 이용할 수 있어요.</li><li>홈 화면의 모아 아이콘을 누르면 내 계정으로 시작합니다. 로그인이 필요한 경우 등록한 패스키 또는 비밀번호로 로그인해 주세요.</li></ol></div>
    <div id="member-install-ios" data-install-guide="ios" hidden><h4>iPhone·iPad에 추가하기</h4><ol><li>Safari에서 모아를 열고 공유 버튼(위쪽 화살표)을 누릅니다.</li><li>‘홈 화면에 추가’를 선택합니다. ‘웹 앱으로 열기’가 보이면 켜고 ‘추가’를 누릅니다.</li><li>홈 화면의 모아 아이콘을 엽니다. 앱에서 처음 로그인을 요청하면 등록한 패스키 또는 비밀번호로 로그인해 주세요.</li></ol></div>
    <div id="member-install-desktop" data-install-guide="desktop" hidden><h4>컴퓨터에 추가하기</h4><ol><li>Chrome 또는 Edge로 모아를 엽니다.</li><li>주소창 오른쪽 설치 아이콘이나 브라우저 메뉴의 ‘앱 → 이 사이트를 앱으로 설치’를 선택합니다. Chrome 버전에 따라 ‘전송, 저장, 공유 → 페이지를 앱으로 설치’로 표시됩니다.</li><li>Mac의 Safari에서는 ‘파일 → Dock에 추가’를 사용할 수 있어요. 추가한 모아를 열고 필요하면 로그인해 주세요.</li></ol></div>
    <p class="moa-muted">브라우저마다 메뉴 이름이 조금 다를 수 있어요. 설치 메뉴가 없다면 이 페이지를 즐겨찾기에 추가해 사용해도 됩니다.</p>
  </div>
  <details><summary>설치한 뒤 로그인과 AI 연결은 어떻게 되나요?</summary><p>같은 브라우저의 로그인 상태를 사용할 수 있으면 바로 연결됩니다. 기기·브라우저가 달라지거나 로그인이 만료되면 앱에서 다시 로그인해 주세요. 등록한 OTP·패스키는 내 계정의 로그인 설정을 따릅니다. 로그아웃하면 같은 로그인 상태를 공유하는 브라우저와 앱에서도 로그인이 해제될 수 있어요.</p><p>Gemini는 메신저의 AI 대화와 기록의 ‘AI로 꾸미기’에서 사용합니다. 이용 가능 여부와 사용량은 계정 설정을 따릅니다. 개인 ChatGPT PC 비서는 아래의 별도 설치 안내를 확인해 주세요.</p></details>
</section>
