# F-70 지시서 — 휴대폰으로 경보 받기 (설치형 웹 앱 + 웹 푸시) (2026-09-24)

> **상태: 결정 대기 — L-3·L-8**(서버 계정·방화벽 변경이라 사용자에게 올라감, 2026-09-24). L-1·L-2·L-4·L-5·L-6·L-7·L-9 는 권장안 수락(조율 세션 전달). 두 답이 오면 「확정」으로 바꾸고 개발 대기열(`/var/www/qaredi/docs/dev-queue.md` 순서 4)에 알린다. 문서만 — 이 문서를 쓰는 동안 코드·운영 DB·`/etc` 는 바뀌지 않았다.
> 요구사항: t-box PRD **F-70**(방식 확정 Q-28 ⓐ: PWA + 웹 푸시, VAPID) · **F-69 선행**(경보 `origin` — 사람·시스템 경보는 즉시, Claude 경보는 하루 요약) · S-05(외부 요청 13조건) · S-07 · S-09 · SECURITY 새 R(아래 E장).
> 실측(2026-09-24): PHP 8.1 + OpenSSL 3.0.2 에 `openssl_pkey_derive`(ECDH)·`hash_hkdf`·`aes-128-gcm`·`prime256v1` 전부 있다 → **새 라이브러리 없이** 웹 푸시 암호화(RFC 8291)와 서명(RFC 8292)을 짤 수 있다. libcurl 7.81 에 HTTP/2(nghttp2) 있음. **방화벽 ufw 는 꺼져 있고 iptables 정책은 전부 ACCEPT** — S-05 ⑫(아웃바운드 443 만)의 인프라 잠금이 지금 없다(기존 R-22·R-03). `alerts.log` 는 root 0644, 폴더 `/var/log/tbox-v2` 는 `tbox-php:adm` 0750.

## A. 무엇을 만드나 (한 그림)

```
[경보가 생김] cli_raise_alert → alerts.log 한 줄(F-69: origin·title)
                                      │ (읽기만)
[보내는 배치] scripts/alert_push.php (cron 매분, 전용 계정 — L-3)
      ├ 사람·시스템 경보 → 곧바로          ─┐
      ├ Claude 경보 → 하루 한 번 요약(L-2) ─┤ 내용을 기기 키로 암호화(RFC 8291) + VAPID 서명(RFC 8292)
      └ 화면의 「시험 알림」 요청(대기표)  ─┘ → src/Fetch.php 의 푸시 전용 함수 → 푸시 서버(허용 3곳, L-4)
                                                                       │
[관리자 휴대폰] 「Qaredi 경보」 웹 앱(홈 화면에 추가) ← 서비스 워커가 알림을 띄움 → 누르면 /tbox/admin (로그인·재인증)
[웹(PHP)] 받기 등록·해지·시험 요청만 — **웹은 밖으로 한 번도 나가지 않는다**
```

원칙
1. **웹 요청 처리 중에는 외부 요청 0.** 밖으로 나가는 것은 배치 하나뿐이고, 그 코드도 `src/Fetch.php` 한 파일 안(S-05 ⑬).
2. 받는 사람은 **Admin 만**(F-70). 보낼 때마다 그 사람이 아직 활성 Admin 인지 다시 본다 — 아니면 보내지 않고 받기를 해지한다.
3. 알림 내용은 **최소**: 규칙 번호·짧은 이름·출처(사람/시스템/Claude)·시각·묶음 수. 계정 이름·IP·파일 이름·명령은 넣지 않는다(잠금 화면에 보인다 — L-1). 자세한 것은 앱을 열고 로그인해서 본다.
4. 푸시가 실패해도 경보는 그대로다(파일·화면). 푸시는 **덧붙은 전달 경로**일 뿐이다.

## B. 지시서

```
목표        : Admin 이 휴대폰에 「Qaredi 경보」 웹 앱을 설치해 두면, 사람·시스템 경보는 곧바로, Claude 경보는 하루 한 번 요약으로
              알림을 받는다. 밖으로 나가는 통로는 푸시 서버 허용 목록 세 곳뿐이고 S-05 13조건을 그대로 지킨다.

요구사항    : t-box PRD F-70 · Q-28 · F-69(origin) · F-24(관리자 영역·재인증) · F-23(재인증) · S-05 ①~⑬ · S-07 · S-09 · S-10(계정·권한)
              · ARCH 11.4(경보) · 5.6(URL 가져오기 처리 순서 — 같은 틀) · 5.14(경보 화면) · SECURITY R-22·R-03(방화벽) · 이 문서 E장 R-99
              · RFC 8030(웹 푸시) · RFC 8291(내용 암호화 aes128gcm) · RFC 8292(VAPID) · 선행: F-69 완료, F-71(claude_ 시험 계정)
              화면: DESIGN.md 구성 요소 + 6장 체크리스트(새 클래스·색값 0)

수정 파일   : src/Fetch.php                      푸시 전용 함수 하나(pushSend) — 기존 check()·resolve·IP 고정·리다이렉트 금지·시간 제한을 그대로 쓰고,
                                                  허용 호스트는 **fetch_hosts 표가 아니라 코드 상수 PUSH_HOSTS**(L-4)
              src/WebPush.php (새)                RFC 8291 암호화 · RFC 8292 JWT(ES256, DER→r||s) · 구독 값 검사 — 외부 요청 0(보내기는 Fetch)
              scripts/alert_push.php (새)         보내는 배치(아래 C) · --dry-run · --once
              scripts/vapid_keygen.php (새)       P-256 키 한 쌍 — 비밀 키는 /etc/tbox-v2/vapid-private.pem(보내는 계정 0600), 공개 키는 .env VAPID_PUBLIC_KEY
              scripts/lib/cli_bootstrap.php       alerts.log 를 오프셋으로 읽는 함수를 alerts.php 의 것과 한 벌로(회전·잘림 처리 같게)
              db/migrations/<날짜>_f70_push.sql   public.alert_push_subscriptions · public.alert_push_outbox · 역할 tbox_push(L-3) · grants
              db/grants.sql                       위 표의 권한(아래 D)
              public/index.php                    라우트 5개(아래 D) — 정책 role:Admin, 쓰기는 재인증 + CSRF
              src/handlers/push_app_page.php (새)          GET  /alerts-app
              src/handlers/push_key.php (새)               GET  /api/admin/push/key
              src/handlers/push_subscriptions.php (새)     GET·POST /api/admin/push/subscriptions
              src/handlers/push_subscription_delete.php (새) DELETE /api/admin/push/subscriptions/{id}
              src/handlers/push_test.php (새)              POST /api/admin/push/test  (대기표에 한 행 — 보내지 않는다)
              public/assets/alerts-app.js (새) · public/assets/alerts-sw.js (새) · public/assets/alerts-app.webmanifest (새) · 아이콘 PNG 192·512(새, 인라인 SVG 원본에서)
              deploy/sites/tbox-v2.conf·tbox-v2-local.conf (+ 운영 파일)  서비스 워커·매니페스트 location(Content-Type·보안 헤더·Service-Worker-Allowed)
              deploy/cron.d/tbox-v2               alert_push.php 매분(보내는 계정) · 하루 요약 시각(L-2)
              deploy/install.sh                   보내는 계정·키 만들기 단계(L-3)
              src/help/screens.php                관리자 경보 화면 도움말 — 설치·받기 켜기·끄기·기기 잃었을 때
              tests/f70.sh · tests/f70_webpush_vectors.php (새)
              문서: PRD F-70 행(구현 반영) · ARCHITECTURE 11.4·새 절(웹 푸시) · API.md(관리자 창구) · SECURITY.md R-99 + 6장(나가는 통로) + 7장 · deploy/DEPENDENCIES.md(외부 서비스 3곳) · STAGES · wiki/tbox

금지 패턴   : SQL 문자열 결합 · innerHTML · 사용자 입력이 든 파일 경로 · Fetch.php 밖의 curl_*/소켓/file_get_contents('http…')
              · 웹 요청 처리 중 외부 요청(시험 알림도 대기표만) · 구독 주소(endpoint)·auth 비밀·VAPID 비밀 키를 감사 detail·로그·응답에 남기기
              · 서비스 워커에 fetch 처리기(캐시)를 두기 — 로그인한 화면을 기기에 남기지 않는다 · 알림 내용에 계정명·IP·파일명·명령
              · 서비스 워커·앱에 인라인 스크립트(CSP script-src 'self' 그대로) · fetch_hosts 표로 푸시 호스트를 넓히기

완료 기준   : 아래 G장 F70-T1 ~ F70-T10 전부

보고 형식   : 변경 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유 · 실제 기기 시험(관문 ③ 사용자) 결과는 「대기」
```

## C. 보내는 배치 `scripts/alert_push.php`

| 단계 | 내용 |
|---|---|
| 1 읽기 | `alerts.log` 를 저장된 오프셋부터(상태 파일은 보내는 계정 0600). **처음 켤 때 오프셋 = 파일 끝** — 옛 889줄은 보내지 않는다. 회전·잘림은 alerts.php 의 `al_read_auth_log` 와 같은 규칙(한 벌로 옮긴다). 대기표(`alert_push_outbox`)의 시험 요청도 읽는다 |
| 2 가르기 | `origin` 이 `human`·`system` → 즉시 묶음 · `claude-review`·`claude-session` → 하루 요약 칸에 개수만 더함 · origin 이 없는 줄(F-69 전 형식) → `human` 으로 본다(좁은 쪽 = 알린다) |
| 3 받는 사람 | `alert_push_subscriptions` 중 살아 있는 것, 그 사용자가 **지금** `role='Admin' AND is_active` 인지 SQL 로 다시 확인 → 아니면 보내지 않고 `revoked_reason='not_admin'` |
| 4 만들기 | 내용 JSON(≤ 1KB): `{v:1, kind:"alert"|"digest"|"test", rule, name, origin, at, n}` — `name` 은 규칙의 고정 짧은 이름(코드 표 — evidence 문자열을 옮기지 않는다) → RFC 8291 aes128gcm 암호화(구독의 p256dh·auth) → VAPID JWT(`aud` = 엔드포인트의 스킴+호스트, `exp` ≤ 12시간, `sub` = L-9) |
| 5 보내기 | `Fetch::pushSend()` — S-05 ①~⑧ 그대로(https·443·호스트 정확 일치·IP 검사·IP 고정·리다이렉트 실패·연결 3초/전체 10초·응답 본문 버림). 헤더 `TTL`(즉시 3600, 요약 86400) · `Urgency: high`(즉시) · `Content-Encoding: aes128gcm` |
| 6 결과 | 201 → `last_ok_at` · 404/410 → 해지(`gone`) · 413 → 기록만(내용 크기 버그) · 429/5xx → `fail_count+1`, 다음 실행에 다시(같은 경보는 최대 3회) · 연속 실패 24시간 → 해지 안 함, 아래 AL-22(L-5) |
| 7 줄이기 | 받기 하나에 즉시 알림 **시간당 10건**(L-7) — 넘으면 그 시간의 나머지는 「외 n건」 한 건으로 다음 정시에 |
| 8 기록 | 감사 `alert.push_send allow|error` (actor 없음 = system) detail `{host, status, rule, kind}` — **엔드포인트·키·내용 원문 없음** |

- `--dry-run`: 보낼 대상 수·호스트·내용 크기만 찍고 Fetch 를 부르지 않는다. `--once`: 한 번 돌고 끝(시험용).
- 실패해도 exit 0(cron 메일 없음) — 대신 감사·AL-22.

## D. 표와 창구

**`public.alert_push_subscriptions`** — `id, user_id(→users), endpoint TEXT(≤1024, UNIQUE), host TEXT, p256dh BYTEA(65), auth BYTEA(16), device_hint TEXT(≤40 — 「Android Chrome」 같은 UA 요약, 원문 저장 안 함), created_at, last_ok_at, fail_count, revoked_at, revoked_reason` · CHECK host IN (PUSH_HOSTS) · 한 사람 살아 있는 받기 ≤ 3(L-6, 핸들러 + 부분 UNIQUE 색인 대신 COUNT 검사).
**`public.alert_push_outbox`** — 시험 알림 대기표 `id, user_id, requested_at, sent_at` (웹은 INSERT 만).

| 역할 | 권한 |
|---|---|
| `tbox_app`(웹) | subscriptions SELECT·INSERT·UPDATE(`revoked_at`,`revoked_reason`) — **DELETE 없음**(기록) · outbox INSERT·SELECT |
| `tbox_push`(보내는 배치, L-3) | subscriptions SELECT·UPDATE(`last_ok_at`,`fail_count`,`revoked_at`,`revoked_reason`) · outbox SELECT·UPDATE(`sent_at`) · `users(id, role, is_active)` 열 SELECT · audit_logs INSERT(기존 감사 규칙 — append-only) · 그 밖 0 |

| 창구 | 정책 | 하는 일 |
|---|---|---|
| `GET /alerts-app` | `role:Admin` | 설치형 앱 화면(매니페스트 연결, 「알림 받기 켜기」·기기 목록·「시험 알림」) |
| `GET /api/admin/push/key` | `role:Admin` | VAPID 공개 키(비밀 아님) |
| `GET /api/admin/push/subscriptions` | `role:Admin` | 내 받기 목록 `{id, host, device_hint, created_at, last_ok_at}` — 엔드포인트 원문 없음 |
| `POST /api/admin/push/subscriptions` | `role:Admin` + **재인증** + CSRF | 받기 등록. 검사: 허용 키 셋(`endpoint`,`keys.p256dh`,`keys.auth`)만 · endpoint 는 `Fetch::check` 의 ①②④(https·443·호스트 표기)를 통과하고 호스트가 PUSH_HOSTS **정확 일치** · p256dh 는 base64url → 65바이트, 첫 바이트 0x04, **곡선 위의 점**(openssl 로 키로 읽혀야) · auth 16바이트 · 3개 초과 400 · 감사 `alert.push_subscribe allow` detail `{host, device_hint}` |
| `DELETE /api/admin/push/subscriptions/{id}` | `role:Admin` + 재인증 + CSRF | 자기 것만(남의 것 404 같은 문구), `revoked_reason='user'`, 감사 |
| `POST /api/admin/push/test` | `role:Admin` + CSRF | outbox 에 한 행(1분 안에 배치가 보냄), 사람당 10분에 1회 |

**화면·앱**: 매니페스트 `start_url` `/tbox/alerts-app`, `scope` `/tbox/alerts-app`, 아이콘 PNG(아이폰은 PNG 필요). 서비스 워커 `alerts-sw.js` 는 **`push`·`notificationclick` 처리기만** — 알림 제목 「[사람] AL-1 권한 우회 시도」·「[시스템] AL-8 백업 실패」·「[Claude] 오늘 경보 37건」, 누르면 **고정 주소** `/tbox/admin`(내용의 어떤 값도 주소로 쓰지 않는다). 아이폰은 「홈 화면에 추가」 뒤 앱 안에서만 알림 허락을 물을 수 있다(iOS 16.4+) — 도움말에 순서.

## E. 보안 — S-05 심사 표와 새 위험

### E-1 S-05 13조건을 푸시 통로에 맞춰 보면

| # | 조건 | 푸시 통로에서 |
|---|---|---|
| ① | https 만 | 등록 때·보낼 때 둘 다 `Fetch::check` · `CURLOPT_PROTOCOLS=HTTPS` |
| ② | 443 만 | 같음 |
| ③ | 호스트 허용 목록 정확 일치 | **코드 상수 PUSH_HOSTS**(L-4 기본: `fcm.googleapis.com` · `web.push.apple.com` · `updates.push.services.mozilla.com`). fetch_hosts 표와 **따로** — 관리자 화면에서 넓힐 수 없다. 하위 도메인이 바뀌는 Edge(`*.notify.windows.com`)는 정확 일치 원칙과 맞지 않아 뺀다 |
| ④ | 호스트 표기 정규화 | 기존 `HOST_RE`·사용자정보·IP 리터럴 거부 그대로 |
| ⑤ | IP 검증(모든 A/AAAA 공인) | 그대로 — 보낼 때마다 |
| ⑥ | IP 고정 접속 | `CURLOPT_RESOLVE` 그대로 |
| ⑦ | 리다이렉트 | 3xx = 실패(따라가지 않음) |
| ⑧ | 크기·시간 | 연결 3초 · 전체 10초 · 보내는 본문 ≤ 4KB · **응답 본문은 읽어 버린다**(최대 8KB) |
| ⑨ | 가져온 파일 검증 | 해당 없음(받는 것이 없다 — 상태 코드만 쓴다) |
| ⑩ | 응답 비노출 | 푸시 서버 응답은 화면에 보이지 않는다(상태 코드만 감사에) |
| ⑪ | 권한·속도 | 등록은 Admin + 재인증, 보내기는 배치만 · 시간당 상한(L-7) · 감사 |
| ⑫ | 인프라 이중 잠금 | **지금 없음** — ufw 꺼짐·iptables ACCEPT(R-22·R-03 열림). `allow_url_fopen=Off`·FPM `disable_functions` 는 그대로지만 배치는 CLI 라 FPM 설정 밖이다 → L-8 |
| ⑬ | 코드 격리 | `curl_*` 는 `Fetch.php` 에만(린트 그대로). `WebPush.php` 는 암호화·서명만 |

### E-2 SECURITY 새 위험 R-99 (안)

| 항목 | 내용 |
|---|---|
| 제목 | 푸시 서버로 나가는 통로(F-70) |
| 위험 | ① 받기 등록의 endpoint 는 브라우저가 준 **입력**이다 — 허용 목록이 없으면 서버가 아무 곳으로나 POST 하는 SSRF 가 된다 ② 푸시 회사(구글·애플·모질라)에 **보내는 시각·횟수·서버 IP** 가 보인다(내용은 암호화돼 못 읽는다) ③ VAPID 비밀 키가 새면 관리자 휴대폰에 **가짜 경보**를 보낼 수 있다 ④ 알림을 눌러 여는 주소를 내용으로 정하면 피싱 통로가 된다 ⑤ 잠금 화면 노출 ⑥ 휴대폰 분실 ⑦ 인프라 잠금(⑫) 부재 |
| 대책 | ① PUSH_HOSTS 정확 일치 + S-05 ①~⑧ ② 내용 최소(규칙·시각·출처·개수) — 받는 쪽 기록은 남는다는 것을 수용 ③ 비밀 키는 보내는 전용 계정 0600, 웹 계정(tbox-php)은 읽지 못함, 교체 절차(키 바꾸면 모든 기기 다시 등록) ④ 여는 주소는 고정 `/tbox/admin` ⑤ 계정·IP·파일명 없음(L-1) ⑥ 관리자 화면에서 기기별 해지 + 계정 비활성·역할 변경 시 보낼 때 자동 해지 ⑦ L-8 |
| 남는 위험 | 푸시 회사가 멈추면 알림이 안 온다(경보 자체는 파일·화면에 남는다) · 받는 쪽이 받은 시각을 안다 · ⑫ 미적용 동안은 코드 잠금 한 겹 |
| 상태 | ◐(설계) → 구현·검토 뒤 갱신 |

### E-3 경보 규칙 하나 더 (L-5)
**AL-22 「경보 전달이 24시간 실패」** — 살아 있는 받기 전부에 24시간 동안 201 이 한 번도 없으면 `alerts.log` 에 origin `system` 으로 한 줄(그 줄도 보내 보려 한다 — 성공하면 복구 알림이 된다). 중복 억제는 alerts.php 와 같은 창(`DEDUP_MINUTES`).

## F. 운영 적용 (HANDOFF 7장 방식 — 사용자 승인 뒤)
1. `--dry-run` 적용 스크립트: 보내는 계정 만들기(L-3) · VAPID 키 생성(평문 비밀 표준출력 0) · 마이그레이션(표·역할·grants) · nginx location · cron 한 줄 · `.env` 공개 키.
2. 건강 확인(기존) + `sudo -u <보내는 계정> php scripts/alert_push.php --dry-run --once` → 대상 0(아직 받기 없음).
3. 관문 ③(사용자): 휴대폰에 설치 → 받기 켜기 → 「시험 알림」 → 1분 안에 알림 → 누르면 /tbox/admin. 기기를 목록에서 해지 → 다음 시험 알림이 오지 않음.

## G. 완료 기준

| # | 시험 | 기대 |
|---|---|---|
| F70-T1 | `tests/f70_webpush_vectors.php` — RFC 8291 의 예시(5장 예시 메시지 · 부록 A 중간값)의 키·salt·평문으로 암호화 | **바이트 단위로 같은 암호문** · RFC 8292 JWT: 헤더·클레임 모양, ES256 서명이 공개 키로 `openssl_verify` 통과(DER↔r‖s 변환 왕복) |
| F70-T2 | 받기 등록 검사(`claude_admin01`, 임시 인스턴스) | 허용 밖 호스트·http·`:8443`·IP 리터럴·`user@host`·1,025자·p256dh 64바이트/곡선 밖 점·auth 15바이트·허용 밖 키 → 400 · 4번째 기기 400 · 재인증 없이 → 재인증 흐름 · `claude_manager01` 403 · 감사 detail 에 endpoint·키 원문 0 |
| F70-T3 | 웹은 밖으로 안 나간다 | `security_lint.sh` 0(curl 은 Fetch.php 만) · 새 핸들러 5개에 `Fetch::` 호출 0(grep) · 시험 알림 요청 뒤 응답까지 php-fpm 의 443 연결 0(`ss -tnp` 표본) |
| F70-T4 | `Fetch::pushSend` 거부 | PUSH_HOSTS 밖 · 사설 IP 로 풀리는 이름(기존 fetch_hosts 시험의 방식) · 3xx → 실패 · 응답 본문 8KB 넘으면 끊음 |
| F70-T5 | 배치 가르기(`--dry-run`, 임시 alerts.log) | 처음 켤 때 옛 줄 0건 · human·system·origin 없는 줄 → 즉시 · claude-* → 요약 칸 · 시간당 11번째 → 「외 n건」 · 요약은 L-2 시각에 한 번, 0건이면 안 보냄 |
| F70-T6 | 받는 사람 다시 확인 | 받기가 있는 Admin 을 비활성 → 다음 실행에 보내지 않고 `not_admin` 해지 · 역할을 Manager 로 → 같음 · 410 응답(가짜 결과 주입) → `gone` 해지 |
| F70-T7 | 비밀 키 격리 | `/etc/tbox-v2/vapid-private.pem` 이 보내는 계정 0600 · `sudo -u tbox-php cat` 실패 · 감사·로그·`auth.log` 에 키·auth 원문 0 |
| F70-T8 | 앱·서비스 워커 | 매니페스트 유효(아이콘 PNG 192·512) · `alerts-sw.js` 에 `fetch` 처리기 0 · 알림 누르면 `/tbox/admin` 고정 · 다른 화면의 CSP 문자열 전후 동일(Q:T-7) · 헤드리스로 `/tbox/alerts-app` 라이트·다크·390px 스크린샷을 직접 본다 |
| F70-T9 | 실제 기기(관문 ③, 사용자) | 안드로이드 Chrome · 아이폰 Safari(홈 화면) 에서 F 장 3 — **대기**로 보고 |
| F70-T10 | 회귀·앱 밖 | T-17~T-19 · `sudo nginx -t` · `php-fpm8.1 -t` · `f69.sh` · `stage26.sh` · `help_button.sh` · `f68.sh` · `wiki_check.php` 0 |

## H. 사용자에게 물을 것 (답이 오면 확정)

| # | 질문 | 기본안 | 답 |
|---|---|---|---|
| L-1 | 잠금 화면에 보일 내용 — PRD F-70 은 「규칙 번호·**대상**·시각·origin」으로 적혀 있다 | 규칙 번호·짧은 이름·출처·시각·개수만 — **대상(계정·IP·파일명)은 뺀다**(잠금 화면·푸시 회사 쪽 기록). 자세한 건 앱을 열어 로그인. 이대로면 PRD 문구를 고친다 || **수락** — 대상 빼기. PRD F-70 「대상」 문구는 구현 세션이 고친다 |
| L-2 | Claude 경보 하루 요약 시각 | 매일 18:00, 0건이면 안 보냄 || **수락** — 18:00 |
| L-3 | 보내는 배치를 어느 계정으로 | **전용 OS 계정 `tbox-push`**(로그인 불가, sudo 없음, `adm` 그룹으로 alerts.log 읽기) + DB 역할 `tbox_push`(D 표의 권한만) — 대안: root(alerts.php 와 같음, 간단하지만 네트워크 코드가 root 로 돈다) || **결정 대기(사용자)** |
| L-4 | 받을 수 있는 브라우저 | 안드로이드 Chrome(`fcm.googleapis.com`) · 아이폰 Safari 홈 화면(`web.push.apple.com`) · Firefox(`updates.push.services.mozilla.com`). Edge 는 뺀다(하위 도메인이 바뀌어 정확 일치 불가) || **수락** — 3곳, Edge 제외 |
| L-5 | 「경보 전달 24시간 실패」 규칙 AL-22 를 새로 둘지 | 둔다(origin `system`) || **수락** — AL-22 |
| L-6 | 관리자 한 사람 기기 수 | 3대 || **수락** — 3대 |
| L-7 | 즉시 알림 상한 | 기기당 시간당 10건, 넘으면 「외 n건」 한 건 || **수락** — 시간당 10건 |
| L-8 | 아웃바운드 방화벽(S-05 ⑫)을 F-70 운영 반영 **전에** 켤지 | **따로 판단** — 켜려면 서버 전체 방화벽(R-22) 작업이라 범위가 크다. 켜지 않으면 R-99 에 「코드 잠금 한 겹」으로 남긴다. 켤 경우 규칙: 나가는 443 은 허용하되(푸시 회사 IP 는 자주 바뀐다) 나머지 포트 차단 || **결정 대기(사용자)** |
| L-9 | VAPID 연락처(`sub` — 푸시 회사에 보내는 값) | **사이트 주소 `https://changkwon.mso.so`**(RFC 8292 가 https 주소를 허용) — 개인 메일을 외부 회사에 보내지 않는다 || **수락** — 사이트 주소 |
