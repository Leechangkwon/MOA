# 지문 로그인 복구 동선 — 2026-10-01

사용자 증상: Qaredi 버튼을 누르면 Google 인증 창이 `changkwon.mso.so에서 사용 가능한 로그인 정보 없음`을 표시한다. Samsung Pass 선호 서비스, Android 17 / One UI 9.0, Samsung Internet에서도 같은 결과. 서버에는 활성 등록과 9월 29일 정상 사용 기록이 있다. 서버 검증·RP ID·쿠키 및 패스키 구현에는 변경이 없었다.

목표: 열쇠 없음·취소 후 비밀번호 길을 명확히 보여 주고, 본인 인증을 거쳐 새 등록을 추가하는 방법을 안내한다. 오류만으로 패스키 삭제를 단정하지 않는다.

수정 파일: public/assets/passkey.js, src/handlers/login_page.php, src/help/screens.php, tests/passkey_recovery_browser.py, tests/catalog.json, SECURITY.md, STAGES.md, wiki/tbox/guide-security.json, 이 기록. Auth/Passkey/DB/라우트/인증 조건은 변경하지 않는다.

완료 기준: 실제 로그인 화면과 실제 passkey.js에서 이전 기기 표시가 있는 상태의 수동 NotAllowedError/AbortError 뒤 비밀번호 칸·제출 단추·복구 안내가 보임, 단추 재시도 가능, 인증 옵션·UV 불변, 정상 WebAuthn 등록·로그인·재인증 회귀 통과, 구문·보안 린트·위키 검사. 실제 사용자 휴대폰 재등록 결과는 별도 확인한다.

운영 계정·기존 패스키를 삭제·초기화하지 않는다. 실제 휴대폰에 새 열쇠를 추가하는 일은 사용자가 본인 비밀번호·필요한 2단계 인증으로 직접 수행한다.

검증: 복구 화면 112/0 · 기존 패스키 수락 251/0(임시 DB·가상 인증기·운영 패스키 쓰기 0). 사용자가 Google 새 등록 후 실제 지문 로그인 성공을 확인했다. Samsung Pass 기존 키를 찾지 못한 정확한 기기 원인은 미확인. 서버의 RP/UV/서명·쿠키 검증은 변경하지 않았다.
