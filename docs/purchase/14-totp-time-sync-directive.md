# F-42 지시서 (Fable → Opus) — 오프라인 2단계 코드 생성기의 서버 시각 동기화 · 잠금 문구 최소 12자

작성 2026-09-15. 근거: 사용자 결정 2026-09-15 — 「생성기 화면의 초와 실제 인증 초가 같게. 서버 시각을 하드코딩하지 말고 **인터넷이 있으면 서버와 동기화**」. 앞선 결정(같은 날): 생성기 소스·화면에는 사용 방법만, 구조 설명은 문서(`docs/totp-generator-internals.md`)에.
PRD F-42(신설)·12.2 T-55 ; ARCH 5.21 ; SECURITY R-40 보충(생성기 위험) ; STAGES 「F-42」.

## 0. 사실

- 서버 검증은 `Totp::verify`(HMAC-SHA1 · 30초 · 6자리 · 앞뒤 1스텝). 스텝 = `intdiv(time(), 30)` — 경계는 매 분 00초·30초.
- 생성기 `public/assets/totp-generator.html` 은 `tick()` 이 `Date.now()`(PC 시계)로 남은 초·코드를 계산한다(500~534행). 잠금 문구 최소 길이 상수 `MIN_PASS` 가 이미 있다(542행).
- 생성기 전용 CSP(nginx `deploy/sites/tbox-v2.conf` 64~72행)가 `default-src 'none'` 이라 **어떤 연결도 막힌다** — 동기화에는 `connect-src 'self'` 가 필요하다. 파일을 PC 에 저장해 `file://` 로 열면 출처가 달라 CORS 허용도 필요하다.
- 배포된 `/etc/nginx/sites-available/tbox-v2` 는 저장소 사본과 같다(diff 없음). **nginx 재적용은 Fable 이 한다**(오늘 사용자가 발표·리허설 중 — 재적용 시점은 사용자 승인).

```
목표        : 생성기가 열릴 때와 이후 주기적으로 서버 시각을 받아 「서버 시각 − PC 시각」 차이를 구해, 코드 계산·남은 초 표시를 서버 시각 기준으로 한다.
              오프라인이면 마지막 차이(브라우저 저장)를, 그것도 없으면 PC 시각을 쓰고 상태를 표시한다. 잠금 문구는 12자 이상.
요구사항    : PRD F-17·F-17-2(생성기)·F-42 ; S-03·S-05(외부 요청 없음 — 생성기가 부르는 것은 이 서버 자신) ; 12.2 T-55 ; ARCH 5.21 ; SECURITY R-40 ;
              `src/Totp.php`(PERIOD·verify 창) · `public/index.php` 라우트 표(`Authz::POLICY_PUBLIC` 예: /api/health) · `src/handlers/health.php`(공개 응답의 본보기) · `deploy/sites/tbox-v2.conf` 64~72 ·
              `docs/totp-generator-internals.md`(구조 설명은 여기에만 쓴다 — 생성기 소스·화면에는 사용 문구만).
수정 파일   :
  [새로 만듦]
    src/handlers/time.php                     — GET /api/time → `{"now": <unix 초, 정수>, "period": 30}` 만. 로그인 불필요(POLICY_PUBLIC). 캐시 금지 헤더(`Cache-Control: no-store`). **CORS**: `Access-Control-Allow-Origin: *`·`Access-Control-Allow-Methods: GET` — 이 창구만(시각 하나뿐이라 잃을 것이 없다). 감사 기록 없음(조회, health 와 같음). 속도 제한은 기존 IP 제한이 있으면 그대로 따른다.
    tests/totp_time_sync.sh                   — T-55 (a)~(e). 로그인 없음, 데이터 없음.
  [고침]
    public/index.php                          — 라우트 1줄 `['GET', '/api/time', 'time.php', Authz::POLICY_PUBLIC]`. 그 밖 변경 없음.
    public/assets/totp-generator.html         — (1) `syncTime()`: `fetch(BASE + '/api/time', {cache:'no-store'})` — BASE 는 문서가 http(s) 로 열렸으면 `location.origin`, `file://` 이면 저장된 서버 주소(아래 (4)). 보낸 시각 t0·받은 시각 t1(PC 시계), 서버 now(초→ms) 로 `offset = now*1000 + (t1-t0)/2 - t1`. 성공하면 `localStorage['tbox-totp-offset'] = {offset, at: t1, base: BASE}` 저장. 열릴 때 1회 + 5분마다.
                                                (2) `serverNow()` = `Date.now() + offset` 을 `tick()`·`totpCode()` 카운터 계산에 쓴다(PC 시각 직접 사용 0).
                                                (3) 상태 줄(기존 `el.utc` 자리): 「서버와 동기화됨 · 서버 시각 HH:MM:SS · PC 와 +N초」 / 「오프라인 · 저장된 보정값 사용(마지막 동기화 M분 전)」 / 「오프라인 · PC 시각 사용 — 코드가 틀리면 PC 시계를 맞추세요」. 차이가 ±60초를 넘으면 경고색.
                                                (4) `file://` 로 열렸고 저장된 서버 주소가 없으면 입력칸 「t-box 주소(예: https://tbox.회사)」 하나를 상태 줄 옆에 보이고, 넣으면 저장 뒤 동기화. 주소는 `https?://` 로 시작하는 것만.
                                                (5) `MIN_PASS` 를 12 로. 안내 문구 「잠금 문구는 12자 이상이어야 합니다. 단어 2~3개를 권합니다.」
                                                (6) 소스 안 주석은 사용·동작 설명만(구조·근거·문서 번호 금지 — 앞선 결정).
    deploy/sites/tbox-v2.conf                 — 생성기 location 의 CSP 에 `connect-src 'self'` 추가(그 밖 지시어 그대로). **/etc/nginx 에는 복사하지 않는다** — Fable 이 사용자 승인 뒤 적용·reload.
    docs/totp-generator-internals.md          — 「동기화」 절: 공식·저장 키·상태 3종·file:// 처리·CORS 근거.
    PRD.md(F-42·T-55·8장 API 행) · ARCHITECTURE.md 5.21 · SECURITY.md R-40 보충 한 줄(생성기가 서버에서 받는 것은 시각뿐, 보내는 것은 없음) · STAGES.md 「F-42」 개발 사항 · README 생성기 문단 한 줄.
금지 패턴   : 생성기가 비밀 키·코드·문구를 어디로든 보내는 코드 · `/api/time` 응답에 시각·period 이외 값 · 외부 시간 서버(NTP·타 사이트) 호출 · 인라인 이벤트 속성 · 서버 시각을 문서에 박아 넣기(하드코딩) · 다른 라우트에 CORS 헤더 · 생성기 소스·화면에 구조 설명·문서 번호.
완료 기준   : tests/totp_time_sync.sh —
             (a) `GET /api/time` 200, 본문 키 정확히 {now, period}, now 가 서버 `date +%s` ±2초, 헤더 `Cache-Control: no-store`·`Access-Control-Allow-Origin: *` ; 다른 공개 창구(/api/health)에는 CORS 헤더 없음 ; 로그인 없이 됨 ; 감사 로그 행 0
             (b) 생성기 소스 정적 검사: `Date.now()` 직접 사용은 `serverNow()`·`syncTime()` 안에만 · `MIN_PASS = 12` · 구조 설명 키워드(RFC|HMAC|PBKDF2|PRD|R-\d|F-1\d) 가 **화면 문구**에 0(코드 식별자는 제외)
             (c) Playwright(headless, 서버에서 열기): 상태 줄이 「서버와 동기화됨」, 표시된 남은 초가 `30 - (서버 now % 30)` 과 ±1초 ; 자체 점검 통과 ; 콘솔 오류 0 ; **CSP 반영 전에는 fetch 가 막혀 「오프라인」 상태여야 하고 그것도 오류가 아니다** — 두 경우를 모두 적는다(Fable 이 CSP 적용 뒤 재확인)
             (d) Playwright(file:// 로 열기, 저장된 보정값 없음): 상태 「오프라인 · PC 시각 사용」 + 주소 입력칸 표시 ; localStorage 에 보정값을 넣어 두고 열면 「저장된 보정값 사용」
             (e) 시각 일치 실증: 서버에서 qa_admin01 의 mfa_secret 을 **읽지 말고**, 시험용 임의 비밀 키 하나로 `php -r` 로 `Totp` 코드를 만들고 같은 키를 생성기에 넣어(Playwright) 같은 스텝에서 같은 6자리가 나오는지 3회(스텝 경계 직후·중간·직전) — 서버 검증 함수와 생성기 출력의 일치
             회귀: `scripts/security_lint.sh` 0건 · `php -l` · `tests/help_button.sh`(변경 무관, 셸 확인) · 로그인 화면 200.
보고 형식   : 변경 파일 / 실행 명령·출력 원문 / 스크린샷(동기화 상태 줄·오프라인 상태 줄) / 못 한 것(CSP 미적용 상태의 결과 포함) / ARCH 13장 체크리스트.
```
