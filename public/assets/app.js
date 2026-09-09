'use strict';
/* public/assets/app.js — 1단계 골격
 *
 * 규칙 (PRD S-03, ARCHITECTURE 13장 체크리스트)
 *   · DOM 에 값을 넣을 때는 textContent 만 쓴다. HTML 문자열을 그대로 넣는 API
 *     (inner/outer HTML 계열, document 에 직접 쓰는 계열)는 쓰지 않는다.
 *     금지 패턴이며 scripts/security_lint.sh 가 반려한다.
 *   · 권한 판단을 브라우저에서 하지 않는다. 버튼을 숨기는 것은 편의일 뿐,
 *     허용 여부는 서버 Authz 가 매 요청 판단한다 (PRD S-01).
 *   · 상태를 바꾸는 요청에는 CSRF 토큰 헤더를 붙인다 (2단계에서 추가, PRD S-09-3).
 */

/** 텍스트를 안전하게 넣는 유일한 통로. 2단계부터 화면 코드가 이 함수만 쓴다. */
function setText(el, value) {
  if (el) {
    el.textContent = value === null || value === undefined ? '' : String(value);
  }
}

/* 1단계에는 동적으로 그릴 것이 없다. 진입점만 남겨 둔다. */
document.addEventListener('DOMContentLoaded', function () {
  void setText;
});
