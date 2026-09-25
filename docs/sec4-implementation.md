# 보안 넷 — 구현 기록 (R-100 · R-31 · R-79 · R-39)

| 항목 | 내용 |
|---|---|
| 근거 | 사용자 결정 d52 「① 넷 다 진행」(현황판, 2026-09-25 23:22) · 취합 세션 지시(개발 세션 대신 구현 — claude, Opus) |
| 작업 자리 | 복제본 `~/.local/share/qaredi-dev/sec4w` — 기준 운영 HEAD `335791d`. 그 사이 운영에 발행 `93d447b`(VERSION·CHANGELOG)와 `a0d5503`(SECURITY R-22·8.4-3·7장 한 줄 — d54)이 들어와 SECURITY 7장 끝이 겹쳤다 → 운영 HEAD 를 복제본에 합쳤다(`e6d2180`, 시각 순서대로 d54 줄 다음에 이 묶음 줄). **운영용 패치 기준은 `a0d5503`** |
| 커밋 | ① `eacb5ba` AL-9 · ② `6e93529` R-31 nginx · ③ `d9be427` R-79 · ④ `e777e3f` R-39 · 시험 틀 `c23b931`(+ 시험 고침 `9d05136`·`a1a54c2` — 코드 변경 0) · 문서·반영 스크립트는 그 뒤 |
| 태그 | `selftest-base/sec4` = `c23b931`(첫 수락 시험 직전) · `review-base/sec4` = `1c260a0`(독립 검토 판 — 옮기지 않는다) · `review-fix/sec4`(검토 반영 끝 — 8장) |
| 운영 반영 | **하지 않았다** — 취합 세션이 한다. 순서는 7장 |

규칙 그대로: 운영 트리·운영 DB(`tbox_re`)·운영 nginx·운영 포트(80·8082·8081)에 쓰지 않았다(읽기·`pg_dump` 파이프·설정 파일 읽기만).
시험은 임시 DB(`tbox_sec4_<포트>_test` 등 — 시험 틀이 만들고 지움) · 임시 인스턴스(`php -S`, 8191~8194) · 이 사용자로 띄운 임시 nginx(8191)에서만.
검토 계정 `claude_` 다섯 · UA `tbox-review/<날짜>` · 비밀은 표준입력으로만. sudo 는 postgres(psql·pg_dump·createdb·dropdb)와 비밀 awk 뿐.

---

## 1. ① R-100 남은 틈 — AL-9 가 `sudo /usr/bin/rm` 을 못 잡음

**바꾼 파일** `scripts/alerts.php`(발동 정규식 한 갈래) · `tests/f69.sh`(T3 경계 줄 + dry-run 대조 · 복제본용 손잡이)

| 결정 | 내용 · 근거 |
|---|---|
| 식 | `(^|\s)rm\s` → `(^|\/|\s)rm\s`. sudo 기록의 `COMMAND=` 는 늘 절대 경로라(`/usr/bin/rm -rf …`) 앞이 `/` 인 rm 을 옛 식이 못 잡았다. 다른 도구(`psql`·`dropdb` …)는 이미 `(^|\/|\s)` 였다 |
| 경계 | 뒤의 `\s` 는 그대로 — `/usr/bin/rmdir`·`/usr/bin/firmware`·`/usr/local/bin/xrm` 처럼 rm 이 이름 속에 든 명령, 인자 없는 `/usr/bin/rm` 은 울리지 않는다(단위 대조: 옛 식 0 → 새 식 1 은 `/usr/bin/rm -rf`·`/bin/rm -f`·`/usr/bin/rm<TAB>-rf` 셋뿐) |
| origin | 판정(`Origin::ofSudoCommand`)은 그대로 — DB 이름이 없는 명령이라 `human`(f69 T1 표 「rm -rf /var/lib/x (DB 없음) → human」 과 같다) |
| 시험 손잡이 | `tests/f69.sh` 에 `F69_PORT` · `F69_NO_ROOT`(root 갈래 T5 verify_backup · T6 운영 alerts.log · T8 운영 저장소 · T10 운영 dry-run 을 건너뜀으로 센다) · `QF2_ENV_SRC`(임시 .env 원본). **기본값은 전과 같다** — 복제본에서 sudo 를 postgres·awk 로만 쓰려고 더했다 |
| 운영 경보 상태 | 시험은 **사본 auth.log(`--auth-log`) · 임시 상태 파일(`--state`) · `--dry-run`** 으로만 — 운영 auth.log 오프셋·운영 상태 파일에 닿지 않는다(대기열 2c 함정) |
| 남는 것(독립 검토 — 받아들이고 적어 둠) | 낱말 기반이라 ⓐ `sudo xargs rm`(rm 뒤 인자 없음) ⓑ `sh -c 'cd /;rm …'`·`(rm …)`(rm 앞이 `;`·`(`) ⓒ `sudo -i`·`sudo bash`·`su -` 대화형 root 셸 안의 명령 ⓓ `unlink`·`shred`·`cp /dev/null …` 은 못 잡는다 · 작은 오탐: 인자 경로가 `/rm` 으로 끝남. 정규식 넓히기 `(^\|[\/\s;&\|(\x60])rm(\s\|$)` 는 **후속 후보**(시험 범위가 커져 이번엔 안 함) |

## 2. ② R-31 — 사내망에서 VM 80 에 직접 붙으면 평문 로그인 화면

**바꾼 파일** `deploy/sites/tbox-common.conf`(map) · `deploy/sites/tbox-v2.conf`(80 서버 블록 첫머리 관문) · 새 시험 `tests/sec4_r31_nginx.sh`

```nginx
# conf.d/tbox-common.conf (http 단)
map $realip_remote_addr $tbox_via_proxy {
    default          0;
    192.168.104.100  1;   # 강사 TLS 프록시 = set_real_ip_from 과 같은 값
    127.0.0.1        1;
    ::1              1;
}
# sites-available/tbox-v2 (listen 80 서버 블록 첫머리)
if ($tbox_via_proxy = 0) {
    return 301 https://changkwon.mso.so$request_uri;
}
```

| 결정 | 내용 · 근거 |
|---|---|
| 방식 | 방화벽 없이 nginx(ⓒ안 — 지시). `$realip_remote_addr` 는 real_ip 적용 **전**의 TCP 상대라 `X-Forwarded-For` 로 바꿀 수 없다(시험 N2 위조 셋 모두 301) |
| 이름 | 지시의 `$via_proxy` 대신 **`$tbox_via_proxy`** — `tbox-common.conf` 머리 규칙 「이름에 tbox_ 접두어(다른 사이트 정의와 충돌하지 않게)」를 따랐다. 뜻은 같다 |
| 자리 | 서버 단 `if` + `return` 하나(nginx 가 안전하다고 적어 둔 쓰임). rewrite 단계라 모든 location(로그인 속도 제한 · 미리보기 raw · 공유 링크 · 옛 자산 308 · 단일 입구)보다 먼저 돈다 — 시험 N2 에서 `POST /tbox/api/login`·`/tbox/api/login/`·공유 링크 모양·옛 `/api/health` 모두 301 |
| 열린 리다이렉트 | 없음 — `$request_uri` 는 경로+쿼리만. 요청 줄이 `GET http://evil.example/x` 여도 · `Host: evil.example` 여도 · 경로 `//evil.example/x` 여도 Location 호스트는 `changkwon.mso.so`(시험 N2) |
| 301 | 지시대로 301. POST 는 GET 으로 바뀌어 도메인으로 간다 — 평문 화면을 주지 않는 것이 목적이라 받아들임 |
| 사전 조사의 사각(독립 검토) | UA 없는 요청(`$tbox_loggable` 0)과 자산 location(`access_log off`)은 접근 로그에 없어, 아래 표의 실측으로는 그 길의 직접 접속을 알 수 없다. **반영 뒤 며칠 301 을 받은 출처를 본다**: `awk '$10==301{print $2}' /var/log/nginx/tbox-v2.access.log \| sort \| uniq -c`(둘째 칸 = TCP 상대). 301 은 서버 단이라 자산 경로도 UA 가 있으면 남는다 — `tests/sec4_r31_nginx.sh` 가 확인(UA 없는 것은 여전히 안 남는다) |
| 위험 | **프록시 주소가 바뀌면 고리**: 프록시로 온 요청도 301 → 도메인 → 다시 301. `set_real_ip_from` 도 같은 값을 쓰므로(지금도 주소가 바뀌면 실제 IP 기록이 틀어진다) 둘을 **함께** 고친다. 반영 스크립트가 두 값이 같은지 사전 확인하고, 반영 뒤 도메인 경로 200(고리 아님)을 확인해 틀리면 되돌린다. 시험 N3 도 두 값 대조 |

**막히지 않게 확인한 길** (접근 로그 `/var/log/nginx/tbox-v2.access.log*` 의 둘째 칸 = `$realip_remote_addr` 실측, 2026-09-11 ~ 09-26 01:56 — adm 그룹 읽기)

| 길 | TCP 상대(80) | 판정 | 근거 |
|---|---|---|---|
| 공개 도메인 전체(브라우저 · 강사 프록시 UA 없는 2초 헬스체크) | 192.168.104.100 | 1 → 그대로 | 로그 91,888줄 전부 이 주소(헬스체크는 `$tbox_loggable` 로 로그 제외 — 같은 프록시에서 온다, tbox-common.conf 주석) |
| 사용자 PC 모니터 프로그램(tbox-buddy, `/api/health` 하루 약 7,500번) | 192.168.104.100 | 1 → 그대로 | 도메인으로 부른다 — 로그의 `/api/health` 요청 16,324줄 모두 프록시 경유 |
| 이 서버 자신 `http://127.0.0.1/…`(반영 스크립트 `L80` · `deploy/switch-site.sh` · 검토 요청) | 127.0.0.1 | 1 → 그대로 | 로그 420줄 — UA `tbox-review/…`·`curl/7.81.0` |
| `::1` | ::1 | 1 → 그대로 | 시험 N2 `::1` 200 |
| 로컬 검토 포트 8082(`tbox-v2-local`, 로컬 시험·점검 봇 BASE) | — | 영향 없음 | 다른 server 블록 · 127.0.0.1 에만 listen · map 을 쓰지 않는다(시험 N3) |
| 실습 8081(`tbox-v1-local`) | — | 영향 없음 | 같은 이유 |
| 경보 `alerts.php`(cron) · 휴대폰 푸시 `alert_push.php`(밖으로 나가는 웹 푸시) · 백업·검사·정리 cron | — | 영향 없음 | HTTP 80 을 쓰지 않는다(`deploy/cron.d` 전부 php·bash 직접 실행) |
| PC 백업 끌어오기(`deploy/pc/pull_backup.ps1`, 192.168.105.44) | — | 영향 없음 | SSH(22)다 — HTTP 가 아니다 |
| 커뮤니티 `/community/` | 80 같은 서버 블록 | 도메인이면 그대로 · 직접이면 301 | 같은 평문 로그인 문제라 의도대로 |
| VM 자기 LAN 주소 `http://192.168.105.44/` | 192.168.105.44 | 0 → 301 | 로그 3줄(2026-09-18 R-31 실측 그 자체). 반영 스크립트가 이 길로 301 을 확인한다 |
| 사내망의 다른 PC | 그 PC 주소 | 0 → 301 https 도메인 | 이번에 닫는 길 |

## 3. ③ R-79 — 2단계 코드가 스텝 경계 뒤 1~2초 이전 값

**바꾼 파일** `src/handlers/time.php` · `public/assets/totp-generator.html` · `tests/totp_time_sync.sh` · 문서(ARCH 5.21 · PRD API 표·T-55 (a) · `docs/totp-generator-internals.md`)

| 결정 | 내용 · 근거 |
|---|---|
| 창구 | `GET /api/time` 에 **`now_ms`(정수 밀리초) 추가** · `now`(정수 초)는 그대로 = `⌊now_ms/1000⌋`(한 번 읽은 시각에서 둘 다). 쓰는 곳 grep: 생성기 하나 · 시험 `totp_time_sync.sh`(키 목록 기대 갱신)·`changelog.sh`(본문에 버전 0 — 영향 없음)·`qf4b1.sh`(상태 코드만). 이미 내려받아 둔 옛 생성기 사본은 `now` 만 읽으므로 그대로 돈다 |
| 보정 | 생성기 `serverMillis()` — `now_ms` 가 있으면 그것, 없으면(옛 서버) `now×1000`. `offset = ms + (t1−t0)/2 − t1` |
| 박자 | `setInterval(tick, 1000)` → `scheduleTick()`: `serverNow()` 의 **다음 초 경계 + 20ms** 에 `setTimeout`(30초 스텝 경계도 초 경계). 시각을 다시 맞추면 박자도 다시 잡는다 · 숨긴 탭이 다시 보이면(`visibilitychange`) 바로 그린다 |
| 남는 것(검토 반영 재실행) | 보정값은 요청 한 번의 「왕복 절반」 추정 — `t1` 을 JSON 본문을 읽은 뒤에 재므로 화면이 바쁠 때(초기화 중) 응답 처리가 늦으면 몇십~몇백 ms 뒤처진다. `tests/sec4.sh` 재실행 네 번 중 한 번 경계 +0.25초 2/3(나머지 3/3, 잰 보정값 −59.5·−58.5ms). 따로 잰 진단(같은 조건 6스텝 × +0.05·+0.25·+0.6초)은 18/18(+12.5ms). 5분마다 다시 맞춘다. **후속 후보**: 여러 번 물어 왕복이 가장 짧은 값 쓰기 · 응답 머리를 받은 때를 `t1` 로 — 이번 검토 반영 범위 밖이라 코드는 그대로 두었다 |
| 바꾸지 않은 것 | 서버 판정(`Totp` 창 0) · CSP(생성기 location 의 `script-src 'unsafe-inline'` — 해시 고정이 아니라 스크립트가 바뀌어도 nginx 변경 없음) · 생성기 소스·화면에 문서 번호를 넣지 않는 규칙(주석에 R-번호 0) |

## 4. ④ R-39 — 임시 비밀번호 유효기간 72시간

**바꾼 파일** `db/migrations/2026-10-04_users_temp_password_issued_at.sql`(새) · `db/schema.sql` · `src/Auth.php` · `src/Audit.php` · `src/handlers/login.php` · `src/handlers/users_create.php` · `src/handlers/users_password_reset.php` · ARCH 4.0 · 7.4

| 결정 | 내용 · 근거 |
|---|---|
| 칸 | `users.temp_password_issued_at TIMESTAMPTZ` — NULL 허용·기본값 없음. **기존 행 NULL = 만료 없음**(데이터 바꾸는 문장 0). `lock_timeout 5s` · `IF NOT EXISTS` |
| 채우는 곳 | 계정 만들기(`POST /api/users` INSERT) · 재발급(`POST /api/users/{num}/password/reset` UPDATE) — 둘 다 **같은 문장**에서 `now()`. 재발급은 옛 시각을 덮어 새 임시 비밀번호가 곧바로 만료되지 않는다(시험 S4-39 7: 100시간 전 → 재발급 → 1분 안) |
| 지우는 곳 | `Auth::changePassword` 의 `SQL_SET_PASSWORD` — `must_change_password = false` 와 **같은 문장**에서 `NULL` |
| 판정 | `Auth::login` — 비밀번호가 맞은 **뒤**(틀리면 옛 동일 문구 — 만료 여부가 새지 않는다) · Guest 만료 뒤 · **2단계 인증 앞**(만료된 값으로는 코드까지 맞아도 못 들어오므로 코드를 묻지 않는다). `must_change_password` 인 계정만 따로 한 번 읽는다(`SQL_TEMP_EXPIRED` — 판정은 SQL `now() - make_interval(hours => 72)`, PHP·DB 시계를 섞지 않는다). `SQL_LOAD_USER`·`_BY_ID`(두 경로가 같은 열이어야 하는 문장)는 건드리지 않았다 |
| 응답 | 401 `{"error":"임시 비밀번호가 만료되었습니다(발급 뒤 72시간). 관리자에게 다시 발급을 요청하세요"}` — 키는 `error` 하나(F-68 응답 모양). 화면은 서버 문장을 그대로 띄운다(`app.js` 수정 0). `mfa_required` 와 같은 자리의 정보(비밀번호가 맞은 사람에게만)라 S-09-12 통일 문구 원칙과 어긋나지 않는다 |
| 문장 상수 자리 | `Auth::MSG_TEMP_PASSWORD_EXPIRED` — `Http.php` 는 보안 핵심 파일이고 이번 허락 범위(`Auth.php`·`Audit.php`) 밖이라 `Auth` 에 두었다. 옮기려면 후속 |
| 감사 | `auth.login deny temp_expired`(대상 id·이름) · 요약 문장 `{target} 계정으로 로그인을 시도했지만 임시 비밀번호의 유효기간(발급 뒤 72시간)이 지났습니다`(ARCH 7.4 = Audit.php). **실패 횟수에 세지 않는다**(추측이 아니다 — 추측은 틀린 비밀번호에서 센다). 세션을 만들지 않는다 |
| 경보 | AL-2 는 `auth.login` deny 를 **전부** 센다 — 만료된 임시 비밀번호로 10분에 20번이면 울린다(바꾸지 않음) |
| CLI 시드 | `scripts/seed_users.php`(root, 값은 `/etc/tbox-v2/seed-accounts.txt` 0600)는 바꾸지 않았다 — 새 행은 NULL = 만료 없음. 전달 경로가 사람 손이 아니라서(R-39 의 「메신저·구두 전달」과 다름). 필요하면 후속 |
| 남는 것 | 72시간 안에 로그인해 받은 **세션**은 그 세션의 수명(`SESSION_MAX_HOURS` 8시간) 동안 비밀번호 변경 화면을 쓸 수 있다 — 세션은 72시간 안에 정당하게 받은 것이라 받아들임 |

## 5. 시험 — 회차와 결과(원문 요약)

| 회차 | 무엇 | 결과 |
|---|---|---|
| 개발 중(태그 전) | `tests/sec4_r31_nginx.sh` 를 만들며 두 번 — 시험 쪽 결함 둘(자산 location 은 `access_log off` 라 로그 대조를 로그인 요청으로 · asset_cache 대조는 같은 파일 이름으로) 고친 뒤 39/0. 설정 파일은 그 사이 바뀌지 않았다 | — |
| 1 (`selftest-base/sec4` 뒤) | `tests/sec4.sh` | 통과 61 · 실패 3 — 시험 쪽 셋: 로그인 화면 대기(`wait_for_function` 이 화면 CSP 아래에서 멈춤) · 이 서버 grep(ugrep 7.8)의 `-E .*` 가 한글 줄 끝까지 닿지 않음 · `wiki_check` 가 복제본에 없는 부모 폴더 STAGES 를 찾음 → `9d05136` |
| 2 | `tests/sec4.sh` | **통과 67 · 실패 0** |
| 2 | `tests/sec4_r31_nginx.sh` | **통과 39 · 실패 0** |
| 회귀 | `tests/sec4.sh REGRESS_ONLY=1`(임시 인스턴스 8192 · 주소·DB·저장 경로만 바꾼 사본) | stage26 **113/0** · help_button **16/0** · f68 **109/0/2** · totp_time_sync **75/0** · api_whoami **22/0** — `sec4.sh` 합계 통과 13 · 실패 0(준비·마이그레이션 8 + 회귀 스크립트 5) |
| R-100 | `F69_PORT=8193 F69_NO_ROOT=1 QF2_ENV_SRC=… tests/f69.sh` | 회차 1 통과 89 · 실패 4 — 임시 DB 에 새 마이그레이션이 없어 2b 계정 만들기 500(반영 순서 DB → 코드 그대로 — 시험이 임시 DB 에 먼저 넣게 고침) · no-root 에서 T5 가 만드는 AL-8 을 T9 가 찾음 둘 · 복제본 위키 경로 → `a1a54c2` → 회차 2 **통과 91 · 실패 0 · 건너뜀/미검증 7**(root 갈래 T5·T6·T8·T9 AL-8 둘·T10 운영 dry-run) — T3 경계 줄 넷·dry-run 발동 목록 `s0 s1 … s7`(rmdir·firmware 없음) 전부 ✔ |

못 돌린 시험(미검증): `tests/alerts_dryrun_offset.sh`(root 로 운영 auth.log 를 읽어야 성립 — 이번 sudo 범위 밖) · `f69.sh` 의 T5·T6·T8·T10 운영 dry-run(`F69_NO_ROOT` 로 건너뜀) · `tests/f52.sh`(`/etc/tbox-v2/qa-totp.txt` 에 씀 — 운영 쓰기) · `tests/q_launcher.sh`(운영 nginx) · 실제 강사 프록시를 거친 요청(반영 스크립트 ③ 이 반영 뒤 도메인 200 으로 확인한다) · 관문 ③(사용자).

## 6. 반영 스크립트 (복제본 `review-scripts/` — 검토 뒤 `/var/www/qaredi/scripts/` 로)

| 순서 | 스크립트 | 누가 | 하는 일 |
|---|---|---|---|
| ① | `apply-sec4-db-2026-09-26.sh [--dry-run|--migrate] <MIG>` | root | 마이그레이션 sha256 고정 · 문장 목록 다섯 줄 고정 · 사용 중 확인 · 적용 뒤 칸·형·NULL·행 수·권한·사이트 200 · 틀리면 이번에 만든 칸만 DROP |
| ② | `apply-sec4-repo-2026-09-26.sh <PATCH> <SHA256> [--dry-run]` | changkwon | git 이 읽은 경로·요약 · 기준 판/검토 통과 판 sha256 · ① 확인 · php -l · bash -n · 표식 수(패치가 건드린 파일 안에서만) · 8082 `now_ms`·생성기 새 박자·200/401 · AL-9 dry-run(사본 auth.log·임시 상태) · 틀리면 `git apply -R` |
| ③ | `apply-nginx-r31-2026-09-26.sh [--dry-run]` | root | /etc 두 파일 알려진 판 · 원본 검토 통과 판 · map 주소 = set_real_ip_from · 반영 전 도메인 200 · 백업 · `nginx -t` · reload · 루프백 200 · 직접(LAN 주소) 301 + Location · 위조 XFF 301 · 도메인 200(고리 아님) · 8082·8081 그대로 · 보안 헤더 불변 · 틀리면 백업으로 되돌리고 reload |

**연습(반영 전 — 운영 배치를 흉내 낸 사본)**: 임시 DB(운영 `pg_dump` 파이프) · 운영 HEAD `a0d5503` 사본 트리 · 임시 인스턴스 8195 에서 ①② 를 자리만 바꾼 사본으로 — ② 를 먼저 하면 「① 이 먼저다」 rc 3 · ① `--dry-run` · `--migrate` 확인 7 ✔ · 다시 `--migrate` 「이미 적용됨」 rc 0 · ② `--dry-run` · 본 실행 확인 17 ✔ · 다시 ② 는 「커밋 안 된 변경」 rc 2 · 검토 통과 판 해시 하나를 틀리게 한 사본은 확인 실패 → `git apply -R` → 「되돌린 뒤 고친 파일 = 기준 판」 rc 4. 줄 끝 공백 경고 0. ③ 은 root 라 연습하지 못했다(설정 검사·동작은 `tests/sec4_r31_nginx.sh`, 스크립트의 주소 뽑기 — VM 192.168.105.44 · map·`set_real_ip_from` 192.168.104.100 — 만 따로 확인).

순서가 **DB → 코드 → nginx** 인 까닭: 새 코드는 ① 의 칸에 쓴다(없으면 계정 만들기·재발급·비밀번호 변경이 500). ③ 은 ② 가 운영 트리의 `deploy/sites` 두 파일을 검토 통과 판으로 만든 뒤에만 시작한다(원본 sha256 확인).

## 8. 독립 검토 반영 (2026-09-26 — 판정 「조건부 반영 가능」, 검토 판 `review-base/sec4` = `1c260a0`)

| 등급 | 자리 | 무엇을 | 고친 것 |
|---|---|---|---|
| 중간 | `review-scripts/apply-nginx-r31-2026-09-26.sh` 인자 | 모르는 인자(`--dryrun`·`-n`·`--dry-run=1`·`--check`)가 본 실행으로 샜다 | 인자는 없거나 `--dry-run` 하나 — 그 밖·둘 이상은 사용법 + 1(root 확인보다 먼저) |
| 낮음 | `apply-sec4-repo` | 패치 sha256 이 인자로만 왔다 | `PATCH_WANT` 를 스크립트 안(@@HASHES@@)에 고정 — 인자와 파일이 둘 다 맞아야. 패치를 0700 임시 폴더로 먼저 복사해 그 사본을 해시·적용 |
| 낮음 | `apply-nginx-r31` 이미 새 판 | /etc 가 이미 새 판이면 새 판을 「백업」해 되돌림이 빈 동작이 됐다 | 두 파일이 모두 새 판이면 백업·설치·reload 없이 **확인만**(실패하면 되돌리지 않고 4 — 사람이 본다) · 반쯤 바뀐 /etc 는 1 |
| 낮음 | `apply-nginx-r31` 설치 뒤 | 설치~확인 사이 오류·신호에 되돌림이 없었다 · 되돌림 뒤 확인이 nginx -t 뿐 | 설치 직전 `trap 'restore …' ERR INT TERM`(끝나면 해제) · `restore` 는 한 번만 돌고 되돌린 두 파일 = 반영 전 기준 판(sha256) · nginx -t · reload · 도메인·루프백 `/tbox/api/health` 200 을 보고, 하나라도 틀리면 「사람이 바로」 |
| 낮음 | root 스크립트의 확인-사용 틈 | db 는 `$MIG` 를, nginx 는 `deploy/sites` 원본을 확인한 뒤 다시 읽어 썼다 | 둘 다 root 소유 0700 임시 폴더로 먼저 복사 → 그 사본을 해시·문장 검사·diff·nginx -t·실행/설치 |
| 낮음 | `apply-sec4-db` | 칸이 이미 있으면 모양과 무관하게 「이미 적용」 0 · 되돌림 DROP 에 잠금 제한 없음 | 모양(형·NULL 허용·기본값)이 기대와 다르면 1 · 되돌림은 `cd /` 뒤 `SET lock_timeout = '5s'` 다음 DROP(실패하면 칸이 남았다고 알림) |
| 낮음 | `tests/sec4_r31_nginx.sh` | map 주소·`set_real_ip_from` 비교가 빈 값끼리도 통과 | 둘 다 IPv4 모양인지 먼저 · 빈 값은 `(none)`/`(empty)` 로 달리 |
| 문서 | SECURITY R-100 · 1장 | 낱말 기반 경보의 한계 | 1장 「남는 것」 · R-100 행 |
| 문서 | SECURITY R-31 · 2장 | 사전 조사의 사각 | 2장 「사전 조사의 사각」 · R-31 행 · 시험 두 줄(301 은 로그에 남음 · UA 없는 것은 안 남음) |

**다시 돌린 것** (복제본 `review-fix/sec4` 판 · 임시 DB·인스턴스·nginx 만 · 원문 `~/.local/share/qaredi-dev/sec4-logs/*-fix*.txt`)

| 무엇 | 결과 |
|---|---|
| `tests/sec4_r31_nginx.sh` | **42/0**(두 주소 IPv4 모양 확인 · 301 은 자산 경로도 로그에 남음 · UA 없는 것은 안 남음 — 셋 추가) |
| `tests/sec4.sh` | 1회 66/1(새 생성기 경계 +0.25초 **2/3** — 코드 변경 없이 진단을 더해 다시) → 2회 **67/0**(보정값 −59.5ms · 줄마다 0.262/0.292/0.357초 새 코드) → 3회 **67/0**(−58.5ms). 옛 생성기 대조 0/3 · 0/3(보정값 −1151 · −714ms). 3장 「남는 것」 |
| 진단(생성기만) | 같은 조건 6스텝 × +0.05·+0.25·+0.6초 **18/18**(보정값 +12.5ms · 읽기 6~13ms) |
| 반영 스크립트 ①② 연습(운영을 흉내 낸 사본 — 임시 DB 둘 · a0d5503 사본 트리 · 포트 8195) | ② 먼저 → 3 · 알려 준 sha256 ≠ PATCH_WANT → 1 · 패치 한 바이트 다름 → 1 · ① `--dry-run` 0 · 마이그레이션 다름 → 1 · `--migrate` 0(확인 7 ✔) · 다시 → 「이미 적용 · 같은 모양」 0 · 칸이 TEXT 로 이미 있음 → 1 · 적용 뒤 확인 실패 → 이번에 만든 칸만 DROP → 4(칸 0) · ② `--dry-run` 0 · 본 실행 0(확인 17 ✔) · 다시 → 2 · 해시 하나 틀린 사본 → `git apply -R` → 「기준 판」 4 |
| 반영 스크립트 ③ 떼어 낸 연습 | 인자: 없음·`--dry-run` 만 root 확인까지 가고 `--dryrun`·`-n`·`--dry-run=1`·`--check`·`--dry-run --dry-run`·`x y` 는 사용법 거부(1). 자리만 임시 트리로 바꾼 사본 + 가짜 nginx·systemctl·curl·install: 정상 0 · 이미 새 판 → 확인만 0(백업 폴더 늘지 않음) · 반쯤 바뀜 1 · 모르는 판 1 · 설치 뒤 nginx -t 실패 4 · 두 번째 install 실패(ERR trap) 4 · 반영 뒤 도메인 301(고리 흉내) 4 · 확인 도중 TERM 4 — 넷 모두 「되돌린 두 파일 = 반영 전 기준 판 · nginx -t · 도메인·루프백 200」 ✔ · 반영 전 도메인 502 → 1 · `--dry-run` 0(/etc 사본 그대로) |
