# Opus 구현 지시서 — F-51 2단계 인증 등록 화면에 QR 코드

> Fable 작성 2026-09-16. CLAUDE.md 2장 형식. **외부 라이브러리·PHP 확장·이미지 파일·외부 요청 없이** QR 을 만든다.

```
목표      : POST /api/me/mfa/enroll 응답에 otpauth 문자열의 QR 모듈 행렬 qr:{version,size,rows} 를 더하고,
            등록 화면(내 계정 · 강제 절차 슬롯)이 그것을 <canvas> 에 그려 인증 앱의 「QR 코드 스캔」으로 찍게 한다.
요구사항  : PRD F-51 · 8장 `/api/me/mfa/enroll` 행 · 12.2 T-63 · S-03 · S-10-C ; ARCHITECTURE 5.25 · 13장 ; SECURITY R-84 · R-05
            — 세 문서의 해당 절을 **읽고 시작**한다.
수정 파일  : src/Qr.php(새) · src/handlers/mfa_enroll.php · src/views/mfa_enroll.php · public/assets/app.js · public/assets/app.css
            · src/bootstrap.php(`require_once` 한 줄만) · README.md(2단계 인증 절만) · tests/f51.sh(새) — 이 여덟만.
금지 패턴  : SQL 문자열 결합, innerHTML/outerHTML/document.write, SVG·HTML 문자열 주입, 사용자 입력이 들어간 파일 경로,
            curl_exec/file_get_contents('http…')/fopen/fsockopen, imagecreate*/GD/Imagick, data: URL 생성, eval/exec/system,
            역할 판단을 Authz 밖에서, 요청 파라미터의 role 값, 비밀·otpauth·rows 를 로그·감사·오류 문구에 넣기,
            외부 코드 복사(라이브러리 파일을 저장소에 넣는 것 포함 — 직접 작성한다)
완료 기준  : PRD 12.2 T-63 (a)~(f) 전부 실행. tests/f51.sh 가 그것을 한다.
보고 형식  : 변경 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유
```

## 손대지 않는다
`src/Totp.php` · `src/Auth.php` · `src/handlers/mfa_confirm.php` · `mfa_disable.php` · `src/Http.php` · `src/Audit.php` · `public/index.php` · `db/*` · `deploy/*` · `.env`(읽기 금지) · PRD/ARCH/SECURITY/STAGES(Fable 몫).
운영 데이터에 손대지 않는다(CLAUDE.md 4장). 시험은 **`qa_employee01` 만**, 모든 요청에 `-A "tbox-review/$(date +%F)"`.

## 1) `src/Qr.php` — 외부 의존 0 QR 인코더

머리말 주석은 `src/Totp.php` 와 같은 결을 따른다: 왜 직접 만드나(S-10-C) · 규격 · 이 파일이 하지 않는 것(로그·파일·요청·이미지).

```php
final class Qr
{
    /** @return array{version:int, size:int, rows:list<string>}  rows 는 '0'(밝음)/'1'(어두움), 여백(quiet zone) 없음 */
    public static function encode(string $text, ?int $mask = null): array;
}
```
- 규격: ISO/IEC 18004. **바이트 모드만**(모드 지시자 `0100`), 오류정정 **M**, 버전 **1~10 자동**(들어가는 가장 작은 것), `$mask` 가 null 이면 벌점(N1=3·N2=3·N3=40·N4=10)이 가장 낮은 마스크, 0~7 이면 그 마스크 강제(시험용).
- 안 들어가면(버전 10 초과) `RuntimeException('QR 로 만들기에 너무 깁니다')`. `$mask` 가 0~7 밖이면 `InvalidArgumentException`.
- 검증에 쓸 표 (오류정정 M):

| 버전 | 크기 | 바이트 용량 | 데이터 코드워드 | 블록당 EC | 블록 구조(데이터 코드워드 수) | 정렬 패턴 중심 | 나머지 비트 |
|---|---|---|---|---|---|---|---|
| 1 | 21 | 14 | 16 | 10 | 1×16 | — | 0 |
| 2 | 25 | 26 | 28 | 16 | 1×28 | 6,18 | 7 |
| 3 | 29 | 42 | 44 | 26 | 1×44 | 6,22 | 7 |
| 4 | 33 | 62 | 64 | 18 | 2×32 | 6,26 | 7 |
| 5 | 37 | 84 | 86 | 24 | 2×43 | 6,30 | 7 |
| 6 | 41 | 106 | 108 | 16 | 4×27 | 6,34 | 7 |
| 7 | 45 | 122 | 124 | 18 | 4×31 | 6,22,38 | 0 |
| 8 | 49 | 152 | 154 | 22 | 2×38 + 2×39 | 6,24,42 | 0 |
| 9 | 53 | 180 | 182 | 22 | 3×36 + 2×37 | 6,26,46 | 0 |
| 10 | 57 | 213 | 216 | 26 | 4×43 + 1×44 | 6,28,50 | 0 |

- 글자 수 지시자: 버전 1~9 는 8비트, 10 은 16비트. 종결자 `0000`(남은 만큼만) → 바이트 경계 → 채움 `0xEC`,`0x11` 반복.
- 리드-솔로몬: GF(2⁸) 원시다항식 `0x11D`, 생성다항식은 EC 개수만큼 `(x - α^i)` 곱(i=0…). 블록 인터리빙(데이터 → EC 순, 짧은 블록 먼저 규칙 그대로).
- 형식 정보: 5비트 = EC 2비트(**M = `00`**) + 마스크 3비트, BCH(15,5) 생성 `0x537`, XOR `0x5412`. 두 곳에 놓고 (4v+9, 8) 어두운 모듈 고정.
- 버전 정보(버전 ≥ 7): 6비트 + BCH(18,6) 생성 `0x1F25`, 좌하·우상 6×3.
- 마스크 조건(i=행, j=열): 0 `(i+j)%2==0` · 1 `i%2==0` · 2 `j%3==0` · 3 `(i+j)%3==0` · 4 `(⌊i/2⌋+⌊j/3⌋)%2==0` · 5 `(i*j)%2+(i*j)%3==0` · 6 `((i*j)%2+(i*j)%3)%2==0` · 7 `((i+j)%2+(i*j)%3)%2==0`.
- 데이터 배치: 오른쪽 아래에서 2열 지그재그, 6번 열(타이밍) 건너뜀, 기능 패턴·형식/버전 정보 자리는 **미리 예약**.
- 구현 중 어긋나면 **오라클로 잡는다**: `python3 -c 'import qrcode,qrcode.util as u; ...'`(아래 시험 (a) 코드) 와 행렬을 `diff` 해 어느 행부터 다른지 본다. 마스크를 강제(`mask_pattern=m`)하면 배치·RS·형식정보 오류가 마스크 선택과 분리된다.

## 2) `src/handlers/mfa_enroll.php`
- 저장 뒤:
  ```php
  $otpauth = Totp::otpauthUri((string) $user['username'], $secret);
  try {
      $qr = Qr::encode($otpauth);          // {version,size,rows}
  } catch (RuntimeException $e) {
      $qr = null;                          // QR 은 편의 — 텍스트 등록은 그대로 된다 (PRD F-51 ⑤). 문구·비밀을 로그에 남기지 않는다
  }
  Http::json(['secret' => $secret, 'otpauth' => $otpauth, 'digits' => Totp::DIGITS, 'period' => Totp::PERIOD, 'qr' => $qr]);
  ```
- 머리말 주석의 「QR 라이브러리를 넣지 않으므로 사용자가 인증 앱에 손으로 옮겨 적는다」 → 「QR 은 `src/Qr.php`(외부 의존 0)가 만든 행렬을 화면이 그린다 — 이미지 파일·외부 요청 없음(ARCH 5.25)」로 고친다.
- `src/bootstrap.php`: `require_once __DIR__ . '/Totp.php';` 바로 아래에 `require_once __DIR__ . '/Qr.php';    // 2단계 인증 등록 QR (F-51, ARCH 5.25)` 한 줄. 그 밖은 손대지 않는다.

## 3) 화면 — `src/views/mfa_enroll.php` · `public/assets/app.js` · `public/assets/app.css`
- 뷰: 안내 `<ol class="steps">` 의 2·3번을 「② 앱에서 **「QR 코드 스캔」** 을 고르고 아래 QR 을 찍습니다. ③ 카메라를 쓸 수 없으면 — 휴대폰에서 이 화면을 보고 있다면 「인증 앱에 바로 추가」 링크, 아니면 「설정 키 입력」(수동 입력)에 계정 이름 `t-box:아이디` · 키 = 아래 비밀 키 · 시간 기반 · 6자리」 로 바꾼다. 나머지 항목·경고문은 그대로.
- 뷰: `비밀 키` 라벨 **위에**
  ```html
  <p class="field__label">QR 코드 (인증 앱의 「QR 코드 스캔」으로 찍으세요)</p>
  <canvas id="mfa-qr" class="mfa-qr" width="0" height="0" hidden aria-label="2단계 인증 등록 QR 코드"></canvas>
  ```
- app.js `initAccount`: `var mfaQr = document.getElementById('mfa-qr');` · `drawMfaQr(qr)`: 모양 검사(객체, `size` 정수 21~177, `rows` 배열 길이 = size, 각 원소 문자열 길이 = size, `/^[01]+$/`) 통과 시에만 그린다 — **모듈 4px, 여백 4모듈**, `canvas.width = canvas.height = (size+8)*4`, 배경 흰색 `fillRect` 전체 → `'1'` 만 검정 `fillRect`. `devicePixelRatio` 확대는 해도 되고 안 해도 된다(하면 CSS 크기 유지). 통과 못 하면 `clearMfaQr()`.
  `clearMfaQr()`: `clearRect` + `width=height=0` + `hidden=true`. 부르는 곳: `clearMfaUriLink` 를 부르는 모든 자리(등록 응답 전, 확정 성공 뒤, `showMfa(true)`).
  머리말 주석(3149행 부근)의 「QR 라이브러리를 쓰지 않으므로 글자 그대로」 문장을 실제와 맞게 고친다. **innerHTML·SVG 문자열·`data:` URL 금지** — 좌표 계산과 `fillRect` 뿐.
- app.css `.mfa` 묶음 옆: `.mfa-qr { display:block; max-width:100%; height:auto; image-rendering: pixelated; background:#fff; border:1px solid var(--line); border-radius: var(--r-ctl); }` — 다크 테마에서도 QR 은 **흰 바탕·검정 모듈** 이어야 앱이 읽는다(색 토큰을 쓰지 않는다).

## 4) `README.md` — 2단계 인증 절(1205~1230행 부근·1304행)만
- 「QR 라이브러리를 쓰지 않으므로 … 글자로 보여 줍니다 — 손으로 입력」 행 → 「QR 코드 · 비밀 키 · `otpauth://` 주소를 함께 보여 줍니다. 인증 앱의 「QR 코드 스캔」이 기본 경로이고, 비밀 키 손 입력·링크는 카메라를 못 쓸 때의 대안입니다(외부 라이브러리 없이 서버가 만든 QR)」.
- 3번 절차를 「QR 스캔 → 안 되면 수동 입력」 순으로. 그 밖의 문장은 손대지 않는다.

## 5) `tests/f51.sh` — PRD T-63 (a)~(f)
`tests/f46.sh` 의 골격(`set -u` · `BASE=http://127.0.0.1:8082` · `UA` · `psqlq` · `login` · `expect` · R-73 비-qa 감사 사전 검사 · 결과 집계)을 **복사해서** 쓴다. 준비물: sudo(`/etc/tbox-v2` 읽기·psql), php, curl, python3(+`qrcode`, +playwright).

- **(a) 오라클 대조** — 문자열 6종 × 마스크 0~7 = 48. PHP 쪽:
  ```bash
  php -r 'require $argv[1]; $r = Qr::encode($argv[2], (int)$argv[3]); echo $r["version"], "\n", implode("\n", $r["rows"]), "\n";' src/Qr.php "$S" "$m"
  ```
  파이썬 쪽(같은 출력 형식):
  ```bash
  python3 - "$S" "$m" <<'PY'
  import sys, qrcode, qrcode.util as u
  s, m = sys.argv[1], int(sys.argv[2])
  q = qrcode.QRCode(error_correction=qrcode.constants.ERROR_CORRECT_M, border=0, mask_pattern=m)
  q.add_data(u.QRData(s.encode('utf-8'), mode=u.MODE_8BIT_BYTE)); q.make(fit=True)
  print(q.version); print("\n".join("".join("1" if c else "0" for c in row) for row in q.get_matrix()))
  PY
  ```
  `diff` 로 비교, 다르면 실패에 **첫 다른 행 번호**를 찍는다. 문자열: `A` · `HELLO WORLD` · `Totp::otpauthUri('abc', <32자 base32>)` · `Totp::otpauthUri(<64자 아이디>, <32자>)`(174자) · `한글 시험 문자열입니다` · `?&=%:/+#`. 비밀은 `Totp::generateSecret()` 로 **시험 안에서 만든 값**(실제 계정 비밀이 아님). 자동 마스크: `Qr::encode($S)` 결과가 8종 중 하나와 같은지(파이썬의 자동 선택과 같은지는 **정보로만** 출력, 실패 아님 — 벌점 동률 처리가 구현마다 다를 수 있다).
- **(b) 응답** — `login qa_employee01` → `POST /api/me/mfa/disable` `{password, otp}` → 200 → `POST /api/me/mfa/enroll` `{}` → 200. `python3 -c 'import json,sys…'` 로 `qr.version`·`size`·`rows` 검사(size = 17+4×version, 길이, `[01]` 만), `secret` 이 `^[A-Z2-7]{32}$`, `otpauth` 가 `otpauth://totp/` 로 시작, `digits`=6, `period`=30. 응답의 `otpauth` 를 `Qr::encode` 에 다시 넣어 `rows` 와 `diff` 0. 이어 새 `secret` 으로 코드를 만들어(`Totp::code($secret)`) `POST /api/me/mfa/confirm` → 200. **그 다음 반드시** `/etc/tbox-v2/qa-totp.txt` 의 `qa_employee01` 행 2열을 새 비밀로 바꾼다(다른 시험이 이 파일로 로그인한다):
  ```bash
  sudo -n awk -v u=qa_employee01 -v s="$NEW" '$1==u{$2=s} {print}' /etc/tbox-v2/qa-totp.txt > "$TMP" && sudo -n install -o root -g root -m 600 "$TMP" /etc/tbox-v2/qa-totp.txt && rm -f "$TMP"
  ```
  (`$TMP` 는 `mktemp`, 비밀은 변수에만 — 화면·로그에 찍지 않는다, R-62). 코드 1회성(R-45): 같은 30초 창에서 disable 과 confirm 에 같은 코드를 쓰면 거부되는 것이 정상 — 비밀이 바뀌었으므로 실제로는 다른 코드이지만, 로그인 코드와 disable 코드는 같은 비밀이라 **창이 바뀔 때까지 기다린다**(`f46.sh` 의 대기 방식).
- **(c) 비노출** — enroll 응답 헤더 `Cache-Control: no-store`; `psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > '$T0' AND detail::text ~ '(rows|otpauth|$NEW)'"` = 0; `sudo -n grep -c "$NEW" /var/log/tbox-v2/app.log`(경로는 `src/bootstrap.php`·`deploy` 에서 확인, 없으면 미검증으로 표시) = 0; `grep -cE 'curl_|file_get_contents|fopen\(|imagecreate|exec\(|require|include' src/Qr.php` = 0.
- **(d) 화면** — `tests/f46.sh` (c) 의 Playwright 호출 방식을 그대로: `qa_employee01` 로 로그인(코드는 (b) 뒤의 새 비밀) → `/settings` → 끄기 → 켜기 → `#mfa-qr` 가 `hidden` 아님·`getBoundingClientRect().width >= 180` → 스크린샷(390×844, 1440×900). 강제 절차 슬롯: 끄기 뒤 `/` 로 가면 `mfa_setup_required` 상자가 열린다 → 켜기 → 같은 검사·스크린샷 2장. **끝나면 confirm 으로 반드시 다시 켜고 qa-totp.txt 를 다시 갱신**한다(위 (b) 와 같은 방식). 출력 폴더 기본 `~/.claude/ui-shots/f51`.
- **(e) 폴백** — `php -r` 로 214바이트 문자열 → `RuntimeException` 잡힘 = 통과. 핸들러 `qr:null` 갈래는 코드 읽기 항목으로 출력에 「코드 읽기」라고 표시.
- **(f)** `scripts/security_lint.sh` 0건 · `php -l src/Qr.php src/handlers/mfa_enroll.php`.

## 6) 완료 전 자기 점검
- ARCHITECTURE 13장 체크리스트.
- `git status` 에 위 여덟 파일 밖의 변경이 없는지. `.env`·`/etc/tbox-v2/*` 내용을 출력에 남기지 않았는지.
- 보고에 **(a) 48/48, (b)(c)(d)(e)(f) 각 결과 원문**과 스크린샷 경로, 그리고 `qa-totp.txt` 갱신 여부를 적는다.
