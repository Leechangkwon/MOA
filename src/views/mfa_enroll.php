<?php
declare(strict_types=1);

/**
 * src/views/mfa_enroll.php — 「2단계 인증 등록」 마크업 한 벌 (PRD F-16 ③, SECURITY R-05)
 *
 * 두 곳이 **같은 파일**을 include 한다:
 *   · `/settings` (settings_page.php) — 평소에 스스로 켜는 자리
 *   · `/`        (home.php)         — 최초 로그인 강제 절차 상자 안의 슬롯
 * 같은 화면을 두 벌 만들면 언젠가 한쪽만 고쳐진다. id 가 겹치지 않는 이유는 두 화면이
 * 동시에 뜨지 않기 때문이다 — `/` 는 강제 절차 중에만 이 상자를 열고(그때 `/settings` 는
 * 라우터 관문이 302 로 돌려보낸다), `/settings` 는 절차가 끝난 뒤에만 열린다.
 *
 * 서버가 여기에 넣는 동적 값은 **없다**. 비밀 키와 otpauth 주소는 서버가 enroll 응답으로
 * 딱 한 번 내려 주고 app.js 가 textContent 로만 넣는다 (PRD S-03, inner/outer HTML 계열 금지).
 * QR 도 마찬가지다 — 응답의 모듈 행렬(`qr.rows`)을 app.js 가 아래 <canvas> 에 fillRect 로
 * 그린다. 마크업·SVG 문자열·`data:` 주소를 만들어 넣지 않는다 (F-51, ARCH 5.25).
 * 인라인 <script>·style 속성·on* 속성을 쓰지 않는다 (CSP).
 *
 * 받는 변수: 없음.
 */
?>
<div id="mfa-off" hidden>
  <p><button id="mfa-enroll" class="btn" type="button"><span class="icon" data-icon="lock"></span>2단계 인증 켜기</button></p>
  <div id="mfa-setup" hidden>
    <p class="field__label">인증 앱에 등록하는 순서</p>
    <ol class="steps">
      <li>스마트폰에 <strong>인증 앱</strong>을 설치합니다 — Google Authenticator · Microsoft Authenticator ·
          Aegis(Android, 오픈소스) · FreeOTP(iOS·Android, 오픈소스) 중 아무거나 됩니다.
          모두 무료이고 계정 가입 없이 쓸 수 있으며, 등록 뒤에는 인터넷 없이도 코드가 나옵니다.</li>
      <li>앱에서 <strong>「QR 코드 스캔」</strong> 을 고르고 아래 QR 코드를 찍습니다.
          이것이 인증 앱의 정상 경로입니다 — 손으로 옮겨 적을 것이 없습니다.</li>
      <li>카메라를 쓸 수 없다면 — 지금 이 화면을 스마트폰에서 보고 있다면 아래
          <strong>「인증 앱에 바로 추가」</strong> 링크를 누르면 앱이 대신 받아 적습니다.
          그것도 안 되면 앱에서 <strong>「설정 키 입력」(수동 입력)</strong> 을 고르고,
          계정 이름은 <code>모아:아이디</code>, 키는 <strong>아래 「비밀 키」 문자열을 그대로</strong>
          넣습니다. 종류는 <strong>시간 기반(Time based) · 6자리</strong> 입니다.</li>
      <li>앱에 <strong>6자리 코드</strong>가 뜹니다(30초마다 새로 바뀝니다). 그 숫자를 아래 칸에 넣고
          <strong>「확인하고 켜기」</strong> 를 누릅니다.</li>
      <li>그 뒤로는 로그인할 때마다 <strong>비밀번호 + 그 시각의 6자리 코드</strong>가 필요합니다.</li>
    </ol>
    <p class="warn">
      비밀 키를 잃으면 앱을 처음부터 다시 등록해야 하니 <strong>지금 옮겨 적으세요.</strong>
      이 문자열은 다시 보여 주지 않습니다.
    </p>
    <p class="field__label">QR 코드 (인증 앱의 「QR 코드 스캔」으로 찍으세요)</p>
    <canvas id="mfa-qr" class="mfa-qr" width="0" height="0" hidden aria-label="2단계 인증 등록 QR 코드"></canvas>
    <p class="field__label">비밀 키 (인증 앱에 손으로 넣는 값)</p>
    <pre id="mfa-secret" class="audit-detail"></pre>
    <p class="field__label">otpauth 주소</p>
    <!-- href 는 비워 둔다. app.js 가 서버가 준 문자열이 otpauth:// 로 시작할 때만 넣는다. -->
    <p><a id="mfa-uri-link" hidden>인증 앱에 바로 추가 (otpauth 링크)</a></p>
    <pre id="mfa-uri" class="audit-detail"></pre>
    <form id="mfa-confirm-form" class="form form--grid" autocomplete="off">
      <label class="field">
        <span class="field__label">인증 앱이 보여 주는 6자리 코드</span>
        <input id="mfa-code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
      </label>
      <button id="mfa-confirm" class="btn" type="submit">확인하고 켜기</button>
    </form>
  </div>
</div>
