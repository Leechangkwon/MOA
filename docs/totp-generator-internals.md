# 오프라인 2단계 인증 코드 생성기 — 내부 구조 (public/assets/totp-generator.html)

2026-09-15 사용자 결정: 생성기 문서·화면에는 사용 방법만 두고, 용도·경고·저장 방식·검토 포인트는 이 문서로 옮긴다(피싱용 복제 시 「그럴듯함」을 주지 않기 위해 구조를 노출하지 않는다 — 소스는 브라우저에서 읽히지만 설명까지 붙여 주지는 않는다).

아래는 옮겨 온 원문 머리 주석이다.

```
<!--
  totp-generator.html — 오프라인 2단계 인증(TOTP) 코드 생성기 · t-box 11단계 부록
  근거: PRD.md F-17 · F-17-2 · SECURITY.md R-40 · 서버 구현 src/Totp.php 와 같은 규격(RFC 6238)

  [용도]
    스마트폰 인증 앱을 쓸 수 없을 때 쓰는 대안. 이 파일 하나를 PC 에 저장해 브라우저로 열면
    비밀 키(base32) 또는 otpauth 주소로부터 6자리 코드를 30초마다 계산한다.
    네트워크 요청 0 · 외부 스크립트 0 · 서버와 통신하지 않는다. 계산은 전부 이 파일 안에서 한다.

  [경고 — SECURITY.md R-40]
    이 생성기를 "로그인하는 그 PC" 에 두면 두 번째 겹이 그 PC 하나로 줄어든다.
    그 PC 를 쥔 사람(악성코드·자리 비움)은 비밀번호와 코드를 함께 얻는다.
    스마트폰 인증 앱은 기기가 분리돼 있어 더 강하다. 가능하면 앱을 쓴다.

  [저장 방식 — PRD F-17-2]
    평문 저장 없음. 「잠금 문구」에서 PBKDF2-SHA256(무작위 salt 16B, 60만 회)으로 잠금 키를 만들어
    AES-GCM(무작위 IV 12B)으로 비밀 키를 암호화하고, **암호문만** localStorage 에 둔다:
      tbox-totp-vault = {"v":1,"kdf":"PBKDF2-SHA256","iter":600000,"salt":b64,"iv":b64,"ct":b64}
    문구·잠금 키·평문은 저장하지 않는다(복호화된 값은 메모리 변수에만). 「잠금」은 메모리만,
    「지우기」는 암호문까지 지운다. 문구를 잊으면 t-box 「내 계정」에서 2단계 인증을 껐다 켜서 새 키를 받는다.

  [검토자가 볼 곳]
    · base32 해독 base32Decode() / 인코딩 base32Encode()  · 8바이트 카운터 counterBytes()
    · HMAC-SHA1 hmacSha1() (WebCrypto crypto.subtle 만 사용)
    · 동적 절단·자릿수 totpCode()  · 입력 파싱 parseInput()  · 4자 묶음 표시 groupFours()
    · 잠금 키 유도 deriveLockKey() (PBKDF2-SHA256 600000회)
    · 암호화 lockToVault() · 복호화 openVault() · 저장 saveVault()(「잠금 문구 확인」 칸과 대조) /
      열기 unlockVault()(확인 칸 없음 — 한 번만 입력) / 지우기 wipeVault()
    · 옛 평문 저장값 즉시 삭제 dropLegacySecret()
    · RFC 6238 부록 B 시험값 TEST_VECTORS / runSelfTest() + 잠금 왕복·틀린 문구·평문 미포함 점검 vaultSelfTest()
    · DOM 조작은 textContent 와 createElement 만 — HTML 문자열 주입·동적 코드 실행·외부 리소스 없음.
-->
```

## 화면에서 뺀 문구

- 부제 「RFC 6238 TOTP · HMAC-SHA1 · 이 파일 안에서만 계산합니다 (네트워크 요청 없음)」
- 「서버는 시계 오차를 ±30초까지 인정하므로 한 코드는 만들어진 뒤 최대 1분 안에만 통과」(허용 창 노출)
- 자체 점검 설명의 「RFC 6238 부록 B 시험값 6개 + 잠금·해제 3개」·「60만 회 반복」
- 각주 「근거: PRD F-17 · F-17-2 · SECURITY R-40 · 서버 구현 src/Totp.php」, 「(SECURITY R-40)」

## 예정 (F-42)

- 인터넷이 있으면 `GET /api/time` 으로 서버 시각과 동기화(왕복 지연 보정), 차이를 브라우저에 저장해 오프라인에서도 사용. 상단 상태 「서버와 동기화됨 / 오프라인·저장된 보정값 / 오프라인·PC 시각」.
- 잠금 문구 최소 길이 12자.
