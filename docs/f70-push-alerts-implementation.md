# F-70 구현 기록 — 휴대폰으로 경보 받기 (설치형 웹 앱 + 웹 푸시) (2026-09-24~25)

> 지시서 `docs/f70-push-alerts-directive.md`(확정 — **본문은 고치지 않았다**)를 구현하면서 **다르게 간 곳**과 그 이유, 지시서가 정하지 않은
> 빈칸을 어떻게 채웠는지 적는다. 1~5 는 취합 세션이 구현 전에 수락한 설계(개발 세션 전달 2026-09-24)이고, 6 부터는 구현 중에 정한 것이다.
> 「사용자 결정 대기」였던 셋(4 감사 자리 · 5 AL-9 · 9 acl)은 2026-09-25 10:44 사용자가 모두 권장안으로 확정했다(현황판 d37·d38·d39) — 기본값 그대로이므로 코드 변경 0.
> 구현: Claude(Opus 구현 에이전트) · 복제본 `f70w`(운영 31c785d 위) · 운영 반영 전.

## 요약 — 무엇이 생겼나

| 자리 | 무엇 |
|---|---|
| `src/WebPush.php` (새) | RFC 8291 내용 암호화(aes128gcm) · RFC 8292 VAPID JWT(ES256, DER↔r‖s) · 받기 값 검사(p256dh 65바이트·0x04·곡선 위의 점 · auth 16바이트) · 받기 주소 검사 감싸개 · 알림 내용 모양 · 기기 요약. **네트워크 0** |
| `src/Fetch.php` | `check()` 의 ①②④ 를 `parseTarget()`, ⑤ 를 `publicIpOf()` 로 떼어(순서·사유 코드 그대로) **같은 함수**를 쓰는 `pushEndpoint()`(네트워크 없는 주소 검사) · `pushSend()`(POST 한 번) · 코드 상수 `PUSH_HOSTS` 셋 |
| `scripts/alert_push.php` (새) | 보내는 배치(C장 1~8) · `--dry-run` · `--once` · `--conf` |
| `scripts/lib/push_bootstrap.php` (새) | 배치 시작점 — `.env` 를 읽지 않는다(3) |
| `scripts/lib/log_tail.php` (새) | alerts.log·auth.log 를 오프셋으로 읽는 **한 벌**(회전·잘림) — `scripts/alerts.php` 도 이것을 쓴다 |
| `scripts/vapid_keygen.php` (새) | 키 한 쌍 만들기(파일에만, 덮어쓰기 0) · 짝 확인 `--check` |
| `db/migrations/2026-09-30_f70_push.sql` (새) · `db/grants.sql` | 표 둘 · 역할 `tbox_push`(NOLOGIN) · 권한(D 표) · 역할 시간 제한 |
| 라우트 8줄 · 핸들러 7 (새) | `GET /alerts-app` · `GET /alerts-sw.js` · `GET /alerts-app.webmanifest` · `GET /api/admin/push/key` · `GET·POST /api/admin/push/subscriptions` · `DELETE /api/admin/push/subscriptions/{num}` · `POST /api/admin/push/test` — 전부 `role:Admin`(재인증 자동) |
| `src/push/alerts-sw.js` · `alerts-icon.svg` (새) | 서비스 워커 원본(public/ 밖) · 아이콘 원본 |
| `public/assets/alerts-app.js` · `alerts-icon-192.png` · `-512.png` (새) | 화면 동작 · 아이콘(`scripts/alerts_icons.py` 가 SVG 를 헤드리스로 찍어 만듦 — GD 없음) |
| `scripts/alerts.php` · `src/Origin.php` | 오프셋 읽기를 `log_tail.php` 로 · **AL-22** 판정(6) · AL-22 = `system` |
| `src/Audit.php` · ARCH 7.4 | 문장 틀 24줄(`alert.push_subscribe`·`_unsubscribe`·`_revoke`·`_test`·`_send`) — 쓰기 코드는 무변경(4) |
| 셸 · 관리자 허브 · 도움말 | `src/views/shell_top.php`·`shell_bottom.php` 에 허용 목록 한 값(`$pageApp = 'alerts'`) · `src/handlers/admin_page.php` 허브 카드 「휴대폰 경보」 · 재인증 돌아갈 키 `alerts`(`public/index.php` `TBOX_REAUTH_RETURN`·`TBOX_REAUTH_PATHS` + `public/assets/app.js` `REAUTH_PATHS`·`isAdminPath`) · `src/help/screens.php` `admin` 도움말에 「휴대폰 경보」 절(10) |
| 배포 | `deploy/cron.d/tbox-v2`(운영 판 + 한 덩이) · `deploy/push.conf`(새) · `deploy/install.sh` 6-3 · `deploy/DEPENDENCIES.md` 4.2·5 |
| 반영 스크립트 | `review-scripts/apply-f70-host-2026-09-24.sh`(사용자 sudo) · `apply-f70-repo-2026-09-24.sh`(changkwon) |
| 시험 | `tests/f70_webpush_vectors.php` · `tests/f70.sh` · `tests/catalog.json` |

## 1. 서비스 워커·매니페스트는 PHP 라우트 — nginx 변경 0 (지시서 B 「deploy/sites … location」 대신)

- `GET /tbox/alerts-sw.js`(→ `src/handlers/push_sw.php`) · `GET /tbox/alerts-app.webmanifest`(→ `push_manifest.php`). 둘 다 `role:Admin`.
- 스크립트 주소가 `/tbox/` 바로 아래라 등록 범위 `/tbox/alerts-app` 가 **Service-Worker-Allowed 머리 없이** 된다. nginx 는 `location /` 의
  `try_files $uri /index.php` 로 이미 PHP 에 넘긴다 → **deploy/sites 두 파일·운영 nginx 설정을 고치지 않는다**(reload 도 없다).
- Content-Type `application/javascript; charset=utf-8` · `application/manifest+json`, 둘 다 `Cache-Control: no-cache`.
  보안 머리(CSP · nosniff · X-Frame-Options · Referrer-Policy)는 `src/bootstrap.php` 가 다른 PHP 응답과 **똑같이** 붙인다 — `tests/f70.sh` T3 가 대조한다.
- 원본 `src/push/alerts-sw.js` 는 public/ 밖 → `/tbox/assets/alerts-sw.js` 는 404. 핸들러가 바꾸는 글자는 아이콘 버전 자리 하나(`__ICON_VERSION__` → 정수).
- 앱 JS·아이콘 PNG 는 정적 파일이고 셸처럼 `?v=<파일 시각>` 을 붙인다(`/tbox/assets/` 는 버전 붙은 주소에 1년 캐시 — U-4′).
- ★ 매니페스트는 브라우저가 기본으로 **쿠키 없이** 부르는데 이 라우트는 로그인·재인증 뒤에만 열린다 → 화면이
  `<link rel="manifest" … crossorigin="use-credentials">` 로 부른다(같은 사이트 요청). 아이폰이 매니페스트를 못 읽는 경우를 위해
  `apple-mobile-web-app-capable` 메타와 `apple-touch-icon`(PNG)도 둔다.
- ★ 재인증 창(15분)이 닫힌 뒤의 워커 **갱신 확인**은 302 로 실패하지만(리다이렉트는 오류로 처리된다) 등록된 워커는 그대로 남아 알림을 띄운다.
  워커 자체는 네트워크를 쓰지 않는다(fetch 처리기 0).

## 2. VAPID 공개 키는 `/etc/tbox-v2/vapid-public.txt` — 운영 `.env` 무변경 (지시서 B `vapid_keygen.php` 줄의 「.env VAPID_PUBLIC_KEY」 대신)

- 공개 키: `/etc/tbox-v2/vapid-public.txt` (root 0644, base64url 한 줄 = 65바이트 점). 웹(tbox-php)이 읽어 `GET /api/admin/push/key` 로 낸다
  (`/etc/tbox-v2` 는 root:tbox-php 0750 이고 open_basedir 안이다). 경로는 코드 상수 `WebPush::PUBLIC_KEY_FILE` — 시험은 **인스턴스 사본의 이 한 줄만** 바꾼다.
- 비밀 키: `/etc/tbox-v2/vapid-private.pem` (tbox-push 0600, PKCS#8 PEM). 웹 계정은 읽지 못한다(L-3). 배치 경로는 `push.conf` 의 `VAPID_PRIVATE_KEY_FILE`.
- **키를 어디서 만드나 — 반영 스크립트 안의 시스템 openssl**(`openssl ecparam -name prime256v1 -genkey -noout` → `openssl pkey` → 공개 점은 SPKI DER 끝 65바이트).
  저장소의 PHP 를 root 로 돌리지 않는 쪽이 더 안전해서다(root 가 실행하는 코드를 줄인다). 비밀 키는 `umask 077` 임시 폴더의 파일로만 오가고
  `install -o tbox-push -m 0600` 뒤 임시 파일은 `shred`. 출력·명령 인자에 비밀 0. **이미 있으면 바꾸지 않는다**(바꾸면 모든 기기를 다시 등록해야 한다).
- `scripts/vapid_keygen.php` 는 새로 설치하는 서버·시험용 만들기(파일에만, 배타적 만들기 `x` — 덮어쓰기 0)와 **짝 확인 `--check`**. 반영 스크립트는
  짝 확인만 보내는 계정(tbox-push)으로 부른다. 배치도 보낼 때마다 비밀 키에서 뽑은 공개 키가 공개 키 파일과 같은지 먼저 본다(아니면 `no_key`).

## 3. 보내는 배치(OS 계정 tbox-push)는 `.env` 를 읽지 않는다 — 자기 시작 파일 + `push.conf` + `push.pgpass`

- `.env` 에는 tbox_app 비밀번호와 APP_SECRET 이 있다. 밖으로 나가는 유일한 배치가 그것을 쥐지 않게, 배치는 `scripts/lib/push_bootstrap.php`
  (cli_bootstrap·src/bootstrap 을 싣지 않는다 — Env·Db·Http·Auth·Audit·Fetch·WebPush·Origin·log_tail 만 명시 목록으로)와
  **`/etc/tbox-v2/push.conf`**(비밀 없음, root 0644 — 저장소 `deploy/push.conf`)를 읽는다. 형식은 `.env` 와 같다(src/Env.php 파서).
  칸: DB 호스트·포트·이름 · 역할 `tbox_push` · `PGPASSFILE` · 키 두 경로 · `STATE_DIR` · `AUDIT_LOG_FILE`(자기 감사 사본) · `ALERTS_LOG` · `DIGEST_RULES`.
- DB 비밀번호는 **`/etc/tbox-v2/push.pgpass`**(tbox-push 0600). PHP 는 이 파일을 열지 않는다 — 비밀번호 칸을 비운 채 `PGPASSFILE` 을 두면 libpq 가 직접 읽는다
  (비밀번호가 PHP 변수·명령 인자·출력 어디에도 없다).
- DB 역할 `tbox_push` 는 **마이그레이션에서 NOLOGIN** 으로만 만든다. 비밀번호·LOGIN 은 사용자 sudo 반영 스크립트가 한다:
  `openssl rand -hex 24` → **표준입력으로** `ALTER ROLE tbox_push LOGIN PASSWORD '…'`(`printf … | psql -f -` — printf 는 bash 내장이라 인자 목록에 없다) →
  pgpass 0600. 반영 스크립트는 먼저 DB 서버 `log_statement` 가 `none` 인지 본다(문장이 서버 로그에 남지 않게). 시험은 역할 이름만 바꾼 사본으로 같은 흐름을 돈다.
- `Fetch::pushSend()` 는 Db·Env 없이 돈다(`PUSH_HOSTS` 코드 상수 · request_id 가 없으면 `-`).
- 설정 파일 경로는 코드 상수 `PUSH_CONF_FILE`(/etc/tbox-v2/push.conf)이고, 셸 인자 `--conf <경로>` 로만 바꾼다(시험·반영 스크립트의 확인용 — 운영 cron 은 인자 없음).
  이 인자로 바뀌는 것은 설정 파일 하나뿐이고 PUSH_HOSTS·검사는 그대로다(시험 전용 구멍이 아니다).

## 4. 배치의 감사 — DB 행 + 자기 사본 `/var/log/tbox-push/audit.log` (**사용자 확정 2026-09-25 10:44 현황판 d37 「① 권장대로」**)

- **원본은 DB 행이다.** `/var/log/tbox-push/audit.log` 사본은 **참고용**이다 — 앱 감사 사본과 달리 해시 체인(`audit_chain.php`)·`chattr +a`(append-only)
  **밖**에 있다(tbox-push 가 자기 파일을 지울 수 있다). 두 곳이 어긋나면 DB 행을 믿는다. 사본을 앱 사본과 합치지 않고 따로 둔다 — 사용자 확정 d37(2026-09-25). 잠글지(chattr +a 등)는 뒤로 미룬다(R-99 남는 위험).
- DB: 역할 `tbox_push` 가 `audit_logs` 에 INSERT 만(append-only 규칙 그대로 — SELECT·UPDATE·DELETE 없음).
- **받기 해지(`alert.push_revoke`)는 해지 UPDATE 와 감사 한 줄을 한 트랜잭션으로** 묶는다(Audit 은 같은 연결에 쓴다). 감사(DB 행·파일 사본)가 실패하면 되돌리고
  받기는 살아 있다 — 다음 실행이 다시 판정한다(기록 없는 상태 변경을 남기지 않는다). 보내기 감사(`alert.push_send`)는 상태를 바꾸지 않으므로 실패를 삼킨다.
- 파일 사본: `/var/log/tbox-push/audit.log`(tbox-push 0640). 기존 사본(`/var/log/tbox-v2/audit.log`, tbox-php:adm 0640, `chattr +a`)에 tbox-push 쓰기 ACL 을 주는
  방식은 **넣지 않았다**.
- 더 작은 변경을 골랐다: **`Audit` 클래스의 쓰기 코드는 한 줄도 바꾸지 않았다.** Audit 은 파일 경로를 `Env::get('AUDIT_LOG_FILE')` 로 읽는데, 배치에서는 그 값이
  push.conf 의 `/var/log/tbox-push/audit.log` 라 권한 문제로 예외가 날 일이 없다. Audit.php 에 더한 것은 문장 틀 24줄뿐이다(ARCH 7.4 와 같은 글자).
- detail 은 **host · status · rule · kind** 만(+ Audit 이 늘 붙이는 route). 받기 주소(endpoint)·키·내용 원문은 넣지 않는다 — `tests/f70.sh` T5·T7 이 detail 키와
  원문 0 을 확인한다. 보내기 감사가 실패해도 배치는 계속 보낸다(보내기는 민감 행동이 아니다 — 표준오류에 한 줄).
- 결정 대기인 것: ① 사본 두 곳을 합칠지(원격 수집 R-11 이 생기면 두 파일을 함께 보내야 한다) ② 사본 회전 — 지금 logrotate 설정에 없다(하루 몇 줄이라 급하지 않음).

## 5. AL-9 는 기본 「하루 요약」 — `DIGEST_RULES=AL-9` (**사용자 확정 2026-09-25 10:44 현황판 d38 「① 하루 요약으로」** — 분류를 좁히면 이 줄에서 뺀다)

- 오늘(2026-09-24) 출처 `human` AL-9 17건이 전부 Claude 세션의 `sudo psql` 이었다(F-69 판정은 운영 DB 를 읽으면 사람). 즉시로 두면 휴대폰이 시험마다 울린다.
- push.conf 한 줄 `DIGEST_RULES=AL-9`(쉼표로 여럿). **비우면 지시서대로** 사람·시스템 경보는 전부 즉시다. Claude 경보(`claude-*`)는 이 값과 상관없이 늘 요약.
- 요약 제목: 전부 Claude 면 「[Claude] 오늘 경보 n건」, 요약 규칙(사람 AL-9)이 섞였으면 좁은 쪽으로 「[요약] 오늘 경보 n건」(내용 origin `human`).
- 기본값이 휴대폰을 즉시 울리지 않는 쪽이라는 점이 곧 위험이다 — 진짜 사람의 위험한 sudo 도 18시까지 늦는다(R-99 남는 위험 ⑤ — 「결정 뒤 확정」).

## 6. AL-22 판정은 `alerts.php`(root) — 보내는 배치는 경보 줄을 쓸 수 없다

- 지시서 E-3 은 「`alerts.log` 에 origin `system` 으로 한 줄」이다. 그런데 `alerts.log` 는 root 0644 이고 tbox-push 는 **읽기만** 한다(L-3 — 폴더 통과 ACL 뿐).
  경보를 쓰는 계정을 늘리는 대신, 늘 경보를 쓰던 `scripts/alerts.php`(root, 5분)가 판정한다. 앱 계정 tbox_app 은 받기 표에 SELECT 가 있다(D 표).
- 판정 재료 `alert_push_subscriptions.fail_since`(**D 표에 없던 칸 — 더함**): 연속 실패가 시작된 시각, 2xx 면 NULL. 「살아 있는 받기 **전부**가
  `fail_since ≤ now() - 24h`」면 AL-22(`AL-22|push`, 이 규칙만 6시간 억제 — `AL22_DEDUP_MINUTES`). 조용한 날(보낼 것이 없어 시도가 없는 날)에는 나지 않는다 — `last_ok_at` 만으로는
  「실패」와 「보낼 것이 없었음」을 가를 수 없어서 칸을 더했다. tbox_push 는 이 칸을 UPDATE 할 수 있다.
- 그 줄은 다음 실행의 보내는 배치가 다시 보내 본다(system → 즉시) — 성공하면 복구 알림이고 `fail_since` 가 비워진다(E-3 그대로).
- 표가 없거나(마이그레이션 전) 읽을 권한이 없으면 AL-22 만 건너뛴다(`to_regclass` · `has_table_privilege` — 저장소 반영이 호스트 반영보다 먼저라도 alerts.php 가 죽지 않게).
- `Origin::ofFixedRule('AL-22')` = `system`.
- **배치가 돌지 못하는 경우(`AL-22|stale`)**: 설정 오류·pgpass·잡히지 않은 예외로 배치가 시작조차 못 하면 받기 표에 흔적이 없어 위 판정으로는 안 보인다.
  그래서 `alerts.php` 가 `/etc/tbox-v2/push.conf` 가 **있을 때만** 그 파일의 `STATE_DIR` 한 줄을 읽어 배치의 상태 파일(`alert-push-state.json` — 배치가
  매 실행 다시 쓴다) 시각을 본다. 15분 넘게 그대로면(상태 파일이 아직 없으면 설정 파일 시각부터) 한 줄. 설정 전이면 조용하다(저장소 쪽만 반영된 상태).
  설정 전체를 Env 로 싣지 않는다 — 한 줄만 좁은 무늬로 읽는다(이 배치의 `.env` 값과 섞이지 않게). 근거는 `state_file`(unchanged·missing)·분만 — 경로 없음.
- **복구 한 줄**: push·stale 가운데 하나라도 참이면 상태 키 `AL-22|down` 을 매 실행 새로 적고(7일 지난 키를 버리는 규칙에 걸리지 않게), 둘 다 풀린 첫 실행에
  `AL-22|recovered` 한 줄(origin system)을 내고 표시를 지운다. dry-run 은 표시·억제 시각을 쓰지 않는다. 보내는 배치는 그 줄을 「휴대폰 경보 전달 복구」로 보낸다.
- **오류 기록**: 배치의 시작점(push_bootstrap)이 설정을 읽기 **전에** `error_log` 를 `/var/log/tbox-push/error.log` 로 건다(push.conf `ERROR_LOG` 로 바꿈).
  설정 오류 문구(키 이름만)·잡히지 않은 예외(클래스·파일:줄)가 남는다. cron 은 출력을 버리므로(MAILTO="") 이 파일이 원인을 보는 자리다. dry-run 은 쓰지 않는다.

## 7. `tbox_push` 에도 문장 15s · 트랜잭션 안 유휴 60s

- tbox_app 과 같은 안전망(Q:F-4 P-7)을 마이그레이션에서 건다. 배치의 문장은 짧고(받기 몇 줄), **밖으로 나가는 동안 트랜잭션을 열어 두지 않는다**(문장마다 자동 커밋 —
  바깥 요청이 10초까지 걸려도 60초 유휴 제한에 걸릴 길이 없다). 새 연결부터 걸린다. 시험은 `IN DATABASE <임시>` 로만 건다(전역 ALTER ROLE 0).

## 8. `--once` · 한 번에 도는 모양

- 기본도 **한 번** 돈다 — cron 이 매분 부른다. `--once` 는 「한 번 돌고 끝」 표식(시험·손 실행)이고 동작이 같다. 반복 모드(분 안에서 여러 번)는 넣지 않았다:
  경보가 5분마다 생기므로 1분 지연으로 충분하고, 되풀이는 다시 보내기 간격·잠금만 복잡하게 만든다.
- 겹쳐 돌지 않게 `STATE_DIR/alert-push.lock` 을 `flock`(LOCK_NB — 앞 실행이 돌면 이번은 건너뜀). dry-run 은 잠금 파일도 만들지 않는다.
- 한 번의 순서: 읽기 → 가르기 → 받는 사람 다시 확인(not_admin 해지) → 지난 시간의 넘친 개수로 「외 n건」 → 즉시(받기마다 시간당 10건) → 18시 요약 →
  시험 요청(대기표 `sent_at` 적음) → **보내기 전에 상태 저장**(중간에 죽어도 줄을 잃지 않는다 — 다시 보낼 수는 있다) → 보내기 → 상태 저장.
- 다시 보내기: 429·5xx·연결 실패·사설 IP·응답 8KB 초과·키 없음은 다음 실행(50초 뒤)부터, 같은 알림 최대 3회. 3xx·그 밖 4xx·모양 오류는 다시 보내지 않는다.
  푸시 서버가 `Retry-After`(초 또는 HTTP 날짜)를 주면 `max(50초, 그 값)` 뒤 — 상한 1시간(`Fetch::PUSH_MAX_RETRY_AFTER`). `Fetch::pushSend` 가 응답 머리 중 이 하나만 읽어
  `retry_after` 정수로 돌려준다(모양이 아니면 null — 기본 간격).
  404/410 은 해지(`gone`). 413 은 기록만(내용 크기 버그 — 받기 실패로 세지 않는다). 2xx 는 모두 성공으로 본다(RFC 8030 은 201).
- 상태 파일(`alert-push-state.json`, 0600): 읽기 위치 · 대기(받기 **번호**·종류·규칙·시각·시도 수) · 받기별 이번 시간 개수 · 요약 칸 개수. 주소·키는 DB 에만.
- 대기 상한 300(넘치면 오래된 것부터 버리고 출력에 적는다 — 푸시 회사가 오래 멈춘 경우 상태 파일이 자라지 않게).

## 9. 폴더 통과 권한 — 대상 두 곳 · x 하나씩 · **acl 패키지 — 사용자 확정 d39 「① 설치」, 2026-09-25 10:45 취합이 `apt-get install acl` 2.3.1-1 설치 완료**

- 대상 두 곳: `setfacl -m u:tbox-push:x /var/log/tbox-v2` · `setfacl -m u:tbox-push:x /etc/tbox-v2`.
  - `/var/log/tbox-v2`(tbox-php:adm 0750): 지시서 L-3 그대로 — alerts.log(root 0644)는 읽히고 app.log·audit.log(0640)는 못 읽는다.
  - **`/etc/tbox-v2`(root:tbox-php 0750) 도 필요하다(구현 중 발견).** push.conf·push.pgpass·vapid-private.pem 이 그 폴더에 있어 통과 권한 없이는 배치가
    자기 설정도 못 연다. x 만이라 목록은 못 보고, 다른 파일(seed-accounts.txt·qa-totp.txt·owner.pgpass·backup.key·backup.pgpass — 전부 root 0600)은 못 읽는다.
- **이 서버에는 acl 패키지(setfacl·getfacl)가 없다**(2026-09-24 확인). 반영 스크립트는 그때 **멈추고(exit 1)** 「사용자 sudo 로 `sudo apt install acl` 뒤 다시」를
  안내한다 — 패키지를 스스로 설치하지 않는다 — 운영 서버에는 사용자 확정 d39 로 2026-09-25 설치됐다. `deploy/install.sh` 6-3 도 setfacl 이 있을 때만 걸고, 없으면 안내만 한다.
- **ACL 이진 형식을 손으로 쓰는 우회(python `os.setxattr`)는 하지 않는다**(취합 결정 2026-09-25 — 손으로 만든 형식이 틀리면 조용히 권한이 어긋난다). 한때 짰던 코드는 지웠다.
- setfacl 이 있을 때의 확인: getfacl 되읽기(`user:tbox-push:--x`, mask 가 막지 않음) + **실제 효과** — `sudo -u tbox-push test -x` 두 폴더 ·
  **두 폴더의 모든 항목**(`/etc/tbox-v2/*` · `/var/log/tbox-v2/*`, 숨은 파일 포함 — 목록을 손으로 적지 않는다)을 tbox-push 로 읽어 **허용 셋만 성공**
  (push.conf · push.pgpass · vapid-private.pem · vapid-public.txt / alerts.log, 있으면 회전된 alerts.log.1 — 배치가 회전 꼬리를 읽는다), 그 밖은 실패
  (폴더면 `ls`) · .env 못 읽음 · 폴더 목록 못 봄 · 권한 비트 0750 그대로. 새로 생긴 비밀 파일이 넓은 권한이면 여기서 잡혀 되돌린다.
  되돌림 `setfacl -x u:tbox-push <폴더>`.
- **반영 스크립트의 되돌림(확인 실패 → 종료 4)**: cron 백업 복원 · 이번에 만든 키·설정·pgpass 지움 · 이번에 건 ACL 걷음 · 역할 NOLOGIN·비밀번호 NULL ·
  이번에 **새로 만든** 폴더 둘 지움(`/var/lib/tbox-push` · `/var/log/tbox-push` — 로그 폴더는 배치 기록이 이미 있으면 남김, 원본은 DB 행).
  OS 계정·표·역할은 지우지 않고(「쓸 수 없게」까지), 지우는 손 명령을 출력에 적는다 — 이번에 만든 계정이면 `userdel`, 이번 마이그레이션이 만든 표면
  `DROP TABLE`, 이번에 만든 역할이면 `DROP OWNED BY`·`DROP ROLE`, 전부터 있던 역할이면 `ALTER ROLE … RESET ALL`. 실행은 사용자가 고른다.
- 시험(`tests/f70.sh` ⑨)은 비root 라 진짜 ACL 을 걸지 못한다 — 「setfacl 없음 → exit 1 · 안내 문구 · 바뀐 것 0」은 지금 PATH 그대로, 나머지 흐름은
  부른 인자를 적는 **가짜 setfacl/getfacl** 로 흉내 낸다. 진짜 효과는 운영 반영 스크립트의 확인 절이 본다(미검증).

## 10. 화면 — 공통 셸 · 셸 키 `admin` · 도움말은 `admin` 절 · 새 클래스·색값 0

- `GET /tbox/alerts-app` 는 공통 셸을 쓰고 셸 키(`$pageKey`)는 관리자 영역과 같은 `admin` 이다 — app.js 는 셸만 그리고 물러나며, 화면 동작은
  `public/assets/alerts-app.js` 가 셸의 `qarediMe`·`qarediHandleReauth` 를 빌려 한다(app.js 에는 재인증 돌아갈 키 한 줄과 관리 경로 판정 한 줄만 더함).
- 셸이 화면별로 붙이는 것은 허용 목록 **한 값**(`$pageApp = 'alerts'`): 머리에 매니페스트·아이폰 메타, 바닥에 alerts-app.js. 그 밖의 값은 아무것도 붙지 않는다.
- 구성 요소: page-head · panel · grid-2 · info-grid · list · notice--recommend · steps · modal(해지 확인) · badge--ok · btn(--quiet·--danger·--small) · msg/ok-note.
  새 CSS 0 · 새 클래스 0 · 새 색값 0(아이콘은 토큰 값 `--fg #09090b` · `--surface #ffffff` 둘만).
- 도움말: `tests/help_button.sh` 가 도움말 키 수(13)·`$pageHelp` 를 넘기는 파일 수(13)를 세므로 **새 키를 만들지 않고**, 지시서 문구 그대로
  「관리자 경보 화면 도움말」 = `admin` 키에 「휴대폰 경보」 절(설치·켜기·끄기·잃었을 때)을 더했다. 휴대폰 화면 자체에는 「설치 순서」 판을 둔다.
- 재인증: `role:Admin` 은 재인증 관문이 자동이라 **일곱 라우트 전부**(시험 알림·목록·화면·워커·매니페스트까지) 15분 창 안에서만 된다 — 지시서의
  「시험은 CSRF」보다 좁은 쪽이다. 화면 요청은 `/reauth?to=alerts` 로 가고 확인 뒤 이 화면으로 돌아온다(`alerts` 키 — 설치한 앱의 시작 화면이라 허브가 아니라 여기로).
- ★ 브라우저 시험은 「알림 받기 켜기」를 누르지 않는다 — 크로미움의 `pushManager.subscribe` 는 구글 FCM 에 붙는다(바깥 푸시 서비스 요청 금지). 켜기 흐름은 실제 기기(T9)에서.

### 10-1. 저장소 쪽만 반영, 서버 쪽은 아직 — 웹은 500 대신 503 · 화면은 「준비 안 됨」

반영은 저장소 쪽(apply-f70-repo) → 서버 쪽(apply-f70-host) 순서이고, 그 사이에는 키 파일·표·push.conf 가 없다. 그 상태에서도 사이트가 멀쩡해야
「저장소 쪽만 먼저」를 고를 수 있다. `WebPush::tablesReady()`(표 둘이 있나 — `to_regclass`, 네트워크 없음)가 false 면 받기 목록·등록·해지·시험 알림 창구는
`503`(`WebPush::NOT_READY_MESSAGE`), 공개 키 창구는 전처럼 503. 화면 `/tbox/alerts-app` 은 200 그대로 — 표·공개 키 파일 둘 중 하나라도 없으면
「준비 안 됨」 안내(`notice--recommend`, 새 클래스 0)와 `data-push-ready="0"` 을 내고, `alerts-app.js` 는 켜기·시험 단추를 막고 창구를 부르지 않는다.
`alerts.php` 는 표가 없으면 AL-22 받기 판정을 건너뛰고, push.conf 가 없으면 배치 확인도 조용하다. `tests/f70.sh` T11 이 이 상태를 따로 만든다
(마이그레이션 전의 DB 사본 · 두 번째 인스턴스 · 키·설정 자리는 없는 경로).

## 11. 받기 표 — D 표와 다른 작은 곳

| 곳 | 지시서 | 구현 | 이유 |
|---|---|---|---|
| `endpoint` UNIQUE | 칸 전체 UNIQUE | **살아 있는 줄만** 부분 UNIQUE 색인 | 해지한 줄은 기록으로 남기되(DELETE 없음) 같은 브라우저가 같은 주소로 다시 등록할 수 있게 |
| `fail_since` | 없음 | 더함 | 6 |
| 해지 창구 자리표시자 | `{id}` | `{num}` | 라우터의 `{id}` 는 UUID 36자다. 받기 번호는 BIGSERIAL 이라 `{num}`(api-tokens 와 같은 모양) |
| tbox_app INSERT | 표 INSERT | **칸 INSERT**(user_id·endpoint·host·p256dh·auth·device_hint) · 대기표는 user_id 만 | 웹이 전달 결과 칸(last_ok_at·fail_count…)을 적을 수 없게 — D 표의 뜻을 더 좁게 |
| 기기 3대 | 핸들러 COUNT | 사람 행 `FOR UPDATE` 로 잠근 뒤 COUNT | 겹친 두 등록이 3대를 넘지 못하게. 시험 알림 10분 1회도 같은 잠금 |
| 알림 종류 | alert·digest·test | + **`more`**(「외 n건」) | 시간당 상한을 넘긴 개수를 한 건으로 — 제목 「[경보] 지난 한 시간 외 n건」 |
| 표 소유자 | — | tbox_owner(마이그레이션이 postgres 로 만든 뒤 넘긴다) | DROP·ALTER 는 소유자만(db/grants.sql 머리) |
| db/schema.sql | — | 고치지 않음 | 새 설치도 마이그레이션으로 만든다(install.sh 4-4-1 이 알린다). grants.sql 의 F-70 절은 표·역할이 있을 때만 건다 |

## 12. 그 밖의 결정

- 받기 주소 검사는 핸들러가 `Fetch` 를 직접 부르지 않도록 `WebPush::checkEndpoint()` 가 `Fetch::pushEndpoint()` 를 감싼다 — 새 핸들러에 `Fetch::` 글자 0 을
  grep 한 번으로 확인하려고(G F70-T3). 검사 규칙 자체는 Fetch 한 벌이다. IP 리터럴 주소는 Fetch 판정 그대로 `host_not_allowed`(대상 = 그 IP 글자)로 남는다.
- 알림 내용의 `name` 은 배치의 코드 표(`PUSH_RULE_NAMES`)에서만 온다(경보 근거 글자를 옮기지 않는다). 서비스 워커는 규칙 번호 모양·이름 40자·개수를 다시 좁히고,
  모르는 내용이 와도 고정 제목 알림 하나는 띄운다(띄우지 않으면 브라우저가 받기를 끊는다). 누르면 언제나 `/tbox/admin`.
- 기기 요약(`device_hint`)은 UA 에서 고정 글자(「Android Chrome」·「iPhone Safari」…) 하나만 뽑는다 — UA 원문은 저장하지 않는다.
- `deploy/cron.d/tbox-v2` 는 저장소 판이 옛 경로(`/var/www/tbox-v2`)로 운영 판과 어긋나 있었다 → **운영 판을 그대로 옮기고** 머리 주석 한 줄 + alert_push 덩이만 더했다.
  반영 스크립트는 운영 판이 알려진 판(sha256)인지, 새 판이 **줄을 더하기만** 하는지(지우는 줄 0 · 명령 줄은 alert_push 하나) 본 뒤 백업하고 넣는다.
- 반영 순서: 저장소(apply-f70-repo) → **곧바로** 호스트(apply-f70-host). 그 사이에는 받기 목록 창구가 500(표 없음)·키 창구가 503(키 없음)이다.
  alerts.php 의 AL-22 는 표가 없으면 건너뛴다.
- 관리망 IP 제한(R-61, nginx 주석으로 준비된 `location ^~ /tbox/admin`·`/tbox/api/admin`)을 켜는 날, 화면 `/tbox/alerts-app`·`/tbox/alerts-sw.js`·
  매니페스트는 `/tbox/admin` 접두 밖이라 **걸리지 않는다**(창구 `/tbox/api/admin/push/*` 는 걸린다) — 그때 휴대폰은 사내망 밖에서 목록을 못 본다. R-61 을 켤 때 함께 정한다.

## 13. 시험에서만 바꾼 곳 (제품 코드에는 시험 구멍 0)

| 사본 | 바꾼 곳 | 왜 |
|---|---|---|
| 인스턴스 사본 `src/WebPush.php` | `PUBLIC_KEY_FILE` 한 줄 → 임시 공개 키 | 웹이 시험 키를 내게 |
| 보내는 쪽 사본 `src/Fetch.php` | `PORT` 443 → 18443 · `rebuild()` 가 포트를 붙임 · `resolve()`: fcm·apple → 127.0.0.1, mozilla → 10.20.30.40 · `PRIVATE_CIDRS` 에서 127.0.0.0/8 만 뺌 | 가짜 푸시 서버(127.0.0.1)로만 가게 — P-7 가져오기 실측과 같은 방식. 진짜 이름 풀이 0 |
| 보내는 쪽 사본 `scripts/alert_push.php` | `$now = time();` 한 줄 → 환경 변수 `F70_NOW` | 정시·18시·다음 날을 흉내 |
| 배치 실행 | `php -d curl.cainfo=<가짜 인증서>` | 가짜 CA 만 믿는다 — 진짜 푸시 회사 인증서는 통과할 수 없다(이름 풀이 · 포트 · 인증서 세 겹) |
| 호스트 반영 스크립트 사본 | 경로·계정·명령 줄(APP·ETC·CRON·LOG_DIR·PSQL_SU·AS_PUSH·AS_APP·USERADD·ROOT_USER…)과 해시 고정값 · `s.setdatabase = 0` → 임시 DB · 가짜 setfacl/getfacl | 비root 로 흐름을 돈다. 다른 계정으로 읽는 경계 항목은 흉내 낼 수 없다(→ 미검증) |

시험 키(VAPID 한 쌍·기기 12벌·auth)는 **실행할 때 새로** 만든다 — 저장소에 키 파일 0. `tests/f70_webpush_vectors.php` 안의 키는 RFC 8291·8292 **공개 예시**뿐이다.

## 14. 남은 것 · 미검증

- **T9 실제 기기**(안드로이드 Chrome · 아이폰 Safari 홈 화면) — 관문 ③ 사용자, 대기. 켜기(구독)·알림 표시·누르면 /tbox/admin·해지 뒤 다음 시험 알림 안 옴.
- 실제 OS 계정·ACL 경계(tbox-push·tbox-php) — 운영 반영 스크립트의 확인 절이 처음 본다(acl 패키지 설치 뒤).
- 실제 푸시 서버(FCM·애플·모질라)의 응답 모양(201 외 코드·Retry-After) — 가짜 서버로만 확인. HTTP/2 는 curl 기본(2TLS)에 맡겼다.
- 방화벽(아웃바운드 443 만 — S-05 ⑫)은 L-8 로 따로 계획 — 그동안 코드 잠금 한 겹(R-99 ⑦).
- 결정 대기: 4(감사 사본) · 5(AL-9 요약 기본값) · 9(acl 패키지) — R-99 남는 위험 ④⑤ 「결정 뒤 확정」.

## 15. 시험 회차 기록 (selftest-base/f70 = `d98c200` 부터 있는 그대로)

| 회차 | RFC 벡터 | `tests/f70.sh` (통과·실패·미검증) | 실패 원인 → 고침 |
|---|---|---|---|
| 커밋 전 린트 | — | — | `security_lint` 3종(주석의 innerHTML 글자 · 정규식 `.exec(` · 바인딩 SQL 과 `$userId` 한 줄) — 고치지 않고 회차 1 로 넘김 |
| 1 | 55 / 1 | 233 / 14 / 2 | **제품 결함 1**: 셸 머리 `$shellApp` 을 앱 그리드 foreach 가 다시 써서 `alerts-app.js` 가 붙지 않았다 → `$shellPwa`. 린트 3종 고침. 벡터 1: RFC 8291 5장 머리의 `Content-Length: 145` 가 같은 절 본문(144바이트)과 어긋난다 — 암호문은 바이트 단위로 같음 → 기대를 본문 쪽으로. 나머지는 시험 쪽(psql 불리언 글자 · awk 문자열 비교 · 주석 grep · 비밀 grep 이 sha256 에 걸림) |
| 2 | 56 / 0 | 245 / 2 / 2 | 시험 환경: 크로미움이 긴 TMPDIR 안 소켓 경로로 시작 못 함 → 브라우저 과정만 짧은 경로. ⑨(라) 두 번째 실행 종료 4 — 진단 줄을 더함(회차 3·4·5 에서 되풀이 안 됨, 원인 미확정) |
| 3 | — | 267 / 2 / 2 | 시험 기대값 둘(화면 제목은 셸 브랜드 「휴대폰 경보 · t-box」 · 40자 자름 기대를 계산으로) |
| 4 | — | 273 / 0 / 2 | 저장소 반영 스크립트에 rename/copy 머리 차단(대기열 11 검토) + 거부 셋 시험 |
| 5 | 56 / 0 | **273 / 0 / 2** | 회귀 R15 가 `tests/f70.sh` 6건을 잡음(CSRF 를 쿠키 머리 파일을 읽는 명령에서 받아 흐름상 비밀) → 로그인 도우미가 CSRF 머리 파일(0600)을 쓰고 `-H @파일` 로만 보냄 |
| 모의 반영 1 | 56 / 0 | 249 / 2 / 2 (반영 스크립트가 되돌림 — 종료 4) | 운영 b8a4a33 복제에 패치를 얹고 `apply-f70-repo` 를 끝까지 — ⑨ 「허용 목록이 이 판의 바뀐 파일을 덮는다」가 31c785d 와 비교해 **뒤 단계 커밋(대기열 1m·11)까지** F-70 변경으로 셌다(시험 결함 — 좋은 패치를 되돌리게 됨). 둘째 실패는 같은 때 정적 규칙을 함께 돌린 부하 속이라 출력이 끝 여섯 줄만 남아 무엇인지 모른다 — 부하 없이 같은 트리(패치만 얹음)에서 다시 돌리니 실패는 ⑨ 하나(272/1/2) |
| 6 | — | 273 / 0 / 2 | `f70_changed()` — 복제본은 31c785d 부터, 반영 트리는 HEAD 위 커밋 전 변경만(같은 가정의 두 줄 + 키 파일 확인도). 세 상태 단위 확인: 복제본 42 · 반영 트리 42 · 되돌린 트리 0(건너뜀) |
| 7 | — | **278 / 0 / 2** | 점검 봇 v3 R20(`.env` 사본 umask 077 + 표식) · R23(빈 값끼리 같음 셋 · 셸 글자 탐침 → 화면 표식 먼저, 없어야 할 것은 따로) — 단언이 나뉘어 +5 |
| 8 (모의 반영 2) | 56 / 0 | **278 / 0 / 2** | 운영 b8a4a33 새 복제 + 이 판의 패치(42파일) → `apply-f70-repo` dry-run 0 · 본 실행 0(해시 아홉 · php -l 스물둘 · bash -n · catalog · 새 시험 둘 — 스크립트 안에서 돈 f70.sh) |

회귀 — 반영 트리(운영 b8a4a33 + 패치, **운영의 새 시험 판**으로): `f69` 99/0/1 · `stage26` 113/0 · `help_button` 16/0 · `f68` 109/0/2 ·
`stage7d1` 66/0 · `alerts_dryrun_offset` 8/0. v3 규칙 19개(파일 지정 커밋 뒤): 기준보다 늘어난 발견 0(16장).

미검증 2 는 늘 같다: T7 의 다른 OS 계정으로 읽는 경계(비root 시험 — 반영 스크립트 확인 절이 처음 봄) · T9 실제 기기(관문 ③).

회귀(회차 5 뒤, 같은 판): `tests/f69.sh` 99/0/1 · 임시 인스턴스(`tests/community/stage7d1.sh` `REGRESS=1`)에서 `stage26` 113/0 · `help_button` 16/0 · `f68` 109/0/2 · `stage7d1` 자체 66/0 · `tests/alerts_dryrun_offset.sh` 8/0(복제본 `alerts.php` — 읽기 한 벌 `log_tail` 로 바꾼 뒤) · `security_lint` 0 · `wiki_check` 0 · 점검 봇 규칙 R15·R16·R18 발견 = 기준 `31c785d` 와 같음(R15 1 · R18 3 — 둘 다 옛 파일, R16 0; R16 건너뜀 +2 는 `WebPush` 의 키 파일 읽기 둘로 `is_file` 뒤). `f69.sh`·`stage7d1.sh` 는 브라우저 소켓 경로 때문에 TMPDIR 기본값(/tmp)으로 돌렸다 — 두 시험의 임시 폴더는 스스로 지워졌고 /tmp 에 남은 것 0.

## 16. 지시서 「수정 파일」 대조 · 점검 봇 v3 규칙 (운영 b8a4a33 복제에 얹어 봄)

운영 트리가 31c785d 뒤 `278f515`(대기열 1m — tests/static 규칙판 v3)·`b8a4a33`(대기열 11)로 나아갔다. 이 판의 패치(review-scripts 뺀
42파일)는 두 커밋과 겹치는 파일이 0 이다. 운영을 **읽어** 복제한 트리에 패치를 얹고 `apply-f70-repo` 를 끝까지 돌렸다(15장 회차 8).

**v3 규칙 — 기준(운영 b8a4a33) 대비 늘어난 발견** (패치를 얹은 트리 · 파일 지정 커밋 뒤)

| 규칙 | 기준 → 뒤 | 무엇 · 처리 |
|---|---|---|
| R20 비밀 파일 임시 사본 | 0 → 1 → **0** | `tests/f70.sh` 임시 인스턴스 `.env` 사본 — umask 077 로 만들고 대기열 11 과 같은 `qcheck-allow: R20` 표식 |
| R23 늘 참인 단언 | 1 → 8 → **1**(기준의 FP-0007) | 보안 머리 비교의 빈 값끼리 같음 셋 → 기준 쪽 `${H_ME:-(기준 없음)}` · 셸 글자 탐침 넷 → 이 화면만의 표식(`id="push-app"`·`id="push-devices"`·`id="admin-reauth"`)을 먼저 단언하고, 없어야 하는 것은 따로 나눔. 매니페스트·아이폰 메타는 셸 글자지만 `$shellPwa === 'alerts'` 일 때만 찍혀 `qcheck-allow: R23`(이유 적음) |
| R6 커밋 범위 | 0 → 97 → **0** | 패치를 얹고 **커밋 전**에는 새 파일이 추적 전이라 K2a 75 · K2c 22(새 파일 머리의 자기 경로). 파일 지정 커밋 뒤 0(복제 트리에서 확인) |
| R22 낡은 주석(참고) | 0 → 9 | 몸이 바뀐 `Fetch::check`·`al_read_auth_log`·`al_split_lines`·`TBOX_REAUTH_PATHS` 를 가리키는 주석 9곳 — 규칙이 「고치지 말고 사람이 대조」라 하여 **읽어 대조함: 전부 여전히 맞다**(check 는 ①②③④⑤ 순서·사유 그대로, 제어문자 bad_request 그대로 · `--auth-log` 는 오프셋을 갱신하지 않음 그대로 · 돌아갈 경로는 고정 문자열뿐 그대로). 고친 줄 0 |
| R15 · R16 · R18 · R19 · R1 · R2 · R3 · R5 · R9 · R11 · R21 · R25~R28 | 같음 | R16 건너뜀 +2 는 `WebPush` 키 파일 읽기 둘(`is_file` 뒤, 주소 아님) |

**R6 K1·K4(지시서 `--directive docs/f70-push-alerts-directive.md`, 복제본 31c785d..HEAD) — 목록과 다른 곳의 까닭**

| 갈래 | 파일 | 까닭 |
|---|---|---|
| 목록 밖 · 바꿈 | `src/push/alerts-sw.js`·`alerts-icon.svg` · `src/handlers/push_sw.php`·`push_manifest.php` | 1 — 워커·매니페스트를 PHP 라우트로(nginx 변경 0). 지시서의 `public/assets/alerts-sw.js`·`alerts-app.webmanifest`·`deploy/sites/…` 대신 | <!-- qcheck-allow: R6 — 지시서가 적은 자리(만들지 않은 까닭을 적는 줄) -->
| | `deploy/push.conf` · `scripts/lib/push_bootstrap.php` | 3 — 배치는 `.env` 를 읽지 않는다 |
| | `scripts/lib/log_tail.php` · `scripts/alerts.php` | 지시서의 `scripts/lib/cli_bootstrap.php`(오프셋 읽기 한 벌) 대신 — cli_bootstrap 은 `.env`·DB 를 여는 시작점이라 배치(3)가 불러올 수 없어 Env·Db 없는 새 파일로 떼고 alerts.php 가 그것을 쓴다(개발 세션 지시). alerts.php 에는 AL-22(6) |
| | `src/Audit.php`(보안 핵심 — 문장 틀 24줄만, 쓰기 코드 무변경) · `src/Origin.php`(AL-22 = system) | 새 감사 행동마다 문장 틀이 있어야 화면 문장·R2 대조가 선다 · 새 규칙의 출처 |
| | `src/views/shell_top.php`·`shell_bottom.php` · `src/handlers/admin_page.php` · `public/assets/app.js` | 10 — 셸 허용 값 한 개 · 허브 카드 · 재인증 돌아갈 키 |
| | `public/assets/alerts-icon-192.png`·`-512.png` · `scripts/alerts_icons.py` | 지시서 「아이콘 PNG 192·512(새, 인라인 SVG 원본에서)」 — 경로 없이 적혀 목록 밖으로 셈 |
| | `docs/f70-push-alerts-implementation.md` · `tests/catalog.json` · `review-scripts/*` | 개발 세션 지시 |
| 목록에 있는데 안 바꿈 | `.env`(VAPID_PUBLIC_KEY) | 2 — 공개 키는 `/etc/tbox-v2/vapid-public.txt` |
| | `scripts/lib/cli_bootstrap.php` | 위 `log_tail.php` |
| | `public/assets/alerts-sw.js` · `deploy/sites/tbox-v*.conf` | 1 | <!-- qcheck-allow: R6 — 지시서가 적은 자리(만들지 않은 까닭을 적는 줄) -->
| | STAGES · wiki/tbox | 이 일의 목록(개발 세션)에 없어 손대지 않음 — 합칠 때 개발 세션 몫 |
| 규칙 오탐 | `src/Fetch.php` 「수정 0·금지 경로」 높음 | 지시서 38줄이 **수정 파일**로 적었다(pushSend). 규칙이 「금지 패턴」 줄의 「Fetch.php 밖의 curl_*…」 를 금지 경로로 읽음 |
| | `deploy/cron.d/tbox-v2` 목록 밖 | 목록 55줄에 있다 — 확장자 없는 경로를 규칙이 경로로 못 읽음 |
| | 맨 이름 `alerts.php`·`alert_push.php` 안 바뀜 | 규칙이 뿌리 경로로 풂 — 실제로는 `scripts/` 아래에서 바뀜·새로 생김 |
| K2c(낮음) | `deploy/install.sh`·`apply-f70-repo` 안내의 `scripts/apply-f70-…` | 저장소 `scripts/` 가 아니라 `/var/www/qaredi/scripts/` 를 뜻함 → 절대 경로로 고쳐 적음 |

## 17. 독립 검토 반영 (판정 「조건부 반영 가능」 · 2026-09-25 — review-base/f70 = ed11b68 위)

봇의 F-70 채점이 끝난 뒤(5c10102) 적는다. 발견마다 고친 커밋을 따로 두었다(봇 정답지 fix_commit). 태그 두 개는 그대로다.

| 발견 | 무엇이 문제였나 | 고친 것 | 커밋 | 시험(tests/f70.sh) |
|---|---|---|---|---|
| 중간-1 | 보내는 배치가 아예 돌지 못하면(설정 오류·pgpass·치명 오류) 받기 표에 흔적이 없어 AL-22 가 안 나고, cron 이 출력을 버려 원인도 안 남는다 | alerts.php: push.conf 가 있으면 배치 상태 파일 mtime 이 15분 넘게 그대로일 때 AL-22 stale(없으면 설정 파일 시각부터 · 설정 전이면 조용) · push_bootstrap: error_log = /var/log/tbox-push/error.log(push.conf ERROR_LOG) — 설정 전에 건다, dry-run 은 안 씀 · 반영 스크립트가 error.log 0640 을 만든다 | `2d40334` | pgpass 없음 → 기록 +1(dry-run 0) · stale 다섯 갈래 |
| 중간-2 | 받기 해지(DB 상태 변경)의 감사가 실패해도 해지는 남아 「기록 없는 상태 변경」이 된다 | push_revoke = begin → UPDATE → Audit::allow → commit, 감사 실패면 rollback(다음 실행에 다시) · 보내기 감사는 전처럼 삼킴 · 구현 기록 4·R-99: DB 행이 원본, 사본은 참고 | `1bfc3f4` | 감사 사본 0444 → 되돌림 · 되살린 뒤 gone + 행 1 |
| 낮음-3 | ALTER ROLE … PASSWORD 가 실패하면 오류 문장 기록(log_min_error_statement)으로 원문이 postgres 로그에 남을 수 있다 · log_statement 확인 두 줄이 겹침 | 같은 표준입력 맨 앞에 `SET log_min_error_statement = 'panic'; SET log_statement = 'none';` · 확인 한 줄 | `5118cdf` | 기록기(psql-rec)로 두 SET 이 ALTER 보다 먼저 같은 입력에 · 기록에 값 0 |
| 낮음-4 | 「tbox-push 는 못 읽는다」가 고정 목록 — 새 비밀 파일이 생기면 안 걸린다 | 두 폴더의 모든 항목을 돌며 허용 셋(push.conf·pgpass·키 둘 / alerts.log·alerts.log.1)만 read, 그 밖 fail | `734b0a6` | 허용 밖 파일(0644) → 그 항목 ✖ · 되돌림 4 |
| 낮음-5 | 되돌림이 이번에 만든 폴더를 남기고, 남는 것(계정·표·역할 설정)을 어떻게 걷는지 안내가 없다 | 새로 만든 폴더 둘 지움(로그 폴더는 기록이 비었을 때만) · 「== 남는 것」에 userdel · DROP TABLE · DROP OWNED/ROLE · ALTER ROLE RESET ALL(출력만) | `a2bb414` | 되돌림 뒤 폴더 없음 · 손 명령 갈래 둘 |
| 낮음-6 | 429 의 Retry-After 를 무시하고 50초 뒤 다시 보낸다 | Fetch::pushSend 가 retry_after(초·HTTP 날짜, 상한 3600) · 배치 next_at = max(50, 그 값) | `7d6ab55` | 120 · 3600 상한 · 날짜 · 모양 아님 · 배치 +120 |
| 낮음-7 | 시험이 기기 auth·p256dh·받기 주소를 python3·php·grep 인자로 넘긴다(R15) | 값은 파일(kf · kpfx · b2h_dev)·표준입력으로만 | `37ca85d` | (시험 자체) |
| 낮음-8 | AL-22 가 30분마다 다시 쌓이고, 풀렸다는 줄이 없다 | 이 규칙만 6시간 억제 · 막힘 표시(AL-22|down) → 풀리면 recovered 한 줄 · 배치는 「휴대폰 경보 전달 복구」로 보냄 | `74a156c` | 6시간(31분 전도 억제) · 복구 한 줄만 · dry-run 무기록 · 배치 이름 |
| 낮음-9 | 상태 파일이 깨지면 조용히 「처음 켬」으로 간다 | push_warn + 감사 alert.push_send error state_corrupt(방향은 그대로 — 안 보냄) · 문장 틀 한 줄 | `4ea7a84` | dry-run(안내만) · 본 실행(감사 1 · 위치 = 끝) |
| 참고-11 | 반영 스크립트의 hdr() 가 쓰이지 않는다 | 지움(시험의 eval 단언도) | `7e9752d` | hdr() 없음 |
| 참고-12 | 확인 단계에서 f70.sh 가 실패해 되돌릴 때 이유가 안 보인다 | 되돌림은 그대로 · 끝 줄 + 실패 항목 15줄 + 「환경 요인이면 다시 · 코드 결함이면 알림」 안내 | `6200adc` | 안내·항목·exit 4 가 스크립트에 |
| 참고-15 | 「없음」 단언이 빈 값끼리 같다고 통과한다(Service-Worker-Allowed · fail_since NULL …) | `(none)` · `(비어 있음)` · `(살아 있음)` · `(없음)` 표지 | `a289317` | (시험 자체) |
| 추가 시험 | 「저장소 쪽만 반영, 서버 쪽은 아직」에서 받기 창구가 500(없는 표 질의) | WebPush::tablesReady · 창구 넷 503 · 화면 200 「준비 안 됨」(data-push-ready=0 — JS 가 단추를 막고 창구를 안 부름) | `7420ea3` | T11(인스턴스 · alerts.php · 헤드리스 셋) |
| (해시) | 반영 스크립트 고정값 | CONF_WANT · CODE_WANT 넷 | `246bc5b` | ⑨ 해시 = 이 트리 |
| (직접 찾음) | pipefail 아래 `printf … \| grep -q` · `\| head` 가 SIGPIPE 로 판정을 흔든다 — 맞는 출력을 틀렸다고 봄 · 저장소 스크립트는 되돌림 전에 끝날 수 있었다(회차 2 의 ⑨(라) 종료 4 도 이것으로 본다) · 7단계 확인 실패가 「이미 됨」인데도 4 | here-string · grep -c · sed -n · checks_failed(바꾼 것 없음 → 5) | `af5aecb` | `\| grep -q`·`\| head` 0 · 이미 됨 + 확인 틀림 → 5 |

**반영 뒤 회차**(같은 기준 — 복제본): 1회차 331/2/2(⑨(라) 두 줄 — 위 「직접 찾음」, 제품 아닌 반영 스크립트 결함) → 2회차 **335/0/2**.
운영 복제 모의 반영·회귀는 15장 뒤 줄과 개발 세션 보고에 적는다.
