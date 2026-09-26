# 디버깅 묶음 S2 — 구현 기록 (사용자 결정 d72 ① 「불편 없는 것부터」 + 2026-09-26 검토에서 남은 보안 낮음)

| 항목 | 내용 |
|---|---|
| 근거 | 취합 세션(changkwon-32) 지시 「디버깅 묶음 S2」 · 사용자 결정 d72 ① · SECURITY 6.7(인터넷 공개 전제) · 디버깅 묶음 S·U 검토에서 남긴 낮음 |
| 작업 자리 | 복제본 `~/.local/share/qaredi-dev/d2sw` — 운영 t-box `4d256f6`(v2.30.0)에서 복제, push 막음, 작성자 claude |
| 커밋 · 태그 | `selftest-base/dbg-S2`(첫 수락 시험 직전 WIP) · `review-base/dbg-S2`(검토 판) — 9장 |
| 운영 반영 | **하지 않았다** — 취합 세션이 한다(`review-scripts/apply-dbg-s2-repo-2026-09-26.sh`). nginx 제안안은 따로(사용자 결정) |
| 새 기능 | 없다 — 보안 강화·결함 고치기만. 새 라우트 0 · 새 표 0 · DB 변경 0 · nginx·/etc 변경 0 |

규칙 그대로: 운영 트리·운영 DB(`tbox_re`)·`/etc`·운영 포트(80·8082·8081)에 쓰지 않았다(읽기·`pg_dump` 파이프만). 시험은 임시 DB `tbox_d2s_*` · 임시 인스턴스 `php -S` 8261~8269 에서만.
검토 계정 `claude_` 다섯 · UA `tbox-review/<날짜>` · 비밀은 표준입력으로만. 화면 파일은 런처 뷰(`src/views/launcher.php`) 하나만 최소로 고쳤다(아래 C) — `app.js`·`app.css`·`community/*.js`·`DESIGN.md` 0.

---

## 1. A 세션 쿠키 이름 `__Host-tbox_session` (R-108)

| 무엇 | 어떻게 |
|---|---|
| 이름 | `Auth::COOKIE_NAME = '__Host-tbox_session'`(t-box·커뮤니티가 같은 쿠키 — 커뮤니티는 `Auth::currentUser` 를 그대로 쓴다). 옛 이름 `Auth::LEGACY_COOKIE_NAME = 'tbox_session'` |
| 속성 | `Http::sendCookie` 한 곳 — HttpOnly · Secure · SameSite=Lax · Path=/ · **Domain 없음**(원래도 없었다 — 주석으로 못 박음) · 만료 없음. `__Host-` 접두가 브라우저에 Secure·Path=/·Domain 없음을 강제한다 |
| 옛 쿠키 | **읽지 않는다.** 실려 오면 `Auth::dropLegacyCookie`(부르는 곳 `cookieSessionId` 하나 → `currentUser`·`login`)가 같은 속성의 만료 머리(`tbox_session=deleted; …; Max-Age=0; path=/; secure; HttpOnly; SameSite=Lax`)를 한 요청에 한 번 보낸다. 값은 보지 않는다(DB 0). 옛 세션 행은 지우지 않는다 — 유휴 30분·절대 8시간 · 로그인 때 만료 정리가 치운다 |
| 결과 | 반영 순간 모두 한 번 다시 로그인(d72 ① 받아들임). 둘 다 실리면 새 이름으로 로그인 유지 + 옛 이름만 치움 |
| 시험 틀 | `tests/**` 65개 파일의 이름을 모두 고쳤다(curl 쿠키 병 `$6=="__Host-tbox_session"` · 머리 글자 · 파이썬 `re.match`). Playwright 로 넣는 쿠키 30개는 `secure: True`(없으면 Chromium 이 `__Host-` 쿠키를 거부 — 「Invalid cookie fields」)이고 `url` 로 넣던 둘은 호스트·경로로(`url` 을 주면 Playwright 가 http 에서 secure 를 거짓으로 덮어쓴다). `scripts/ui_snapshot.py`(help_button 의 화면 점검) · `scripts/review_account_setup.php`(F-71 검토 계정 설정 — `RAS_COOKIE`) 도 같은 이름. 가림 규칙(`tests/lib/results.py`)은 부분 일치라 두 이름 다 가린다 — 고치지 않음 |
| http 127.0.0.1 | 실측: curl 7.81 과 Chromium 은 127.0.0.1 을 믿을 만한 출처로 보아 http 에서도 Secure·`__Host-` 쿠키를 받고 보낸다 → **운영 코드에 시험용 예외·.env 설정을 넣지 않았다** |
| 문서 | ARCH 4.0 세션 쿠키 줄 · API.md 0장(프로그램이 쿠키 이름을 글자로 적었으면 고칠 것) · README(「v2.31 에서 한 번 다시 로그인」) · 도움말 내 계정 · SECURITY R-108·6.7 |

## 2. C 로그인 전 런처 — 사이트 이름 + 로그인 단추만 (R-98)

`src/site/launcher_page.php` 가 로그인 전이면 앱 목록을 **비운다**(`Apps::all()` 는 그대로 불러 모양 검사는 한다). 뷰(`src/views/launcher.php`)는 목록이 비면 격자를 그리지 않고,
로그인 전이면 머리 문구 「로그인하면 쓸 수 있는 기능이 보입니다.」 + `.page-head__actions` 안 `<a class="btn" href="/tbox/login">로그인</a>`(새 클래스 0 · 스크립트 0 · 인라인 0).
로그인 뒤는 그대로(타일 둘 · 배지). 쿠키가 없으면 DB 질의 0 · Set-Cookie 0 그대로(앞단 헬스체크 `/` 200 그대로).
**남는 것**: 로그인 단추 주소(`/tbox/login`)와 로그인 화면 문구(「Qaredi 계정 하나로 t-box 와 커뮤니티를…」)로는 여전히 앱이 있다는 것을 안다 — 그 화면은 이번에 건드리지 않았다(결정 필요 시).
사이트 문서(`../docs/launcher/ARCHITECTURE.md` 5장 · 사이트 PRD Q:T-1)는 운영 폴더라 고치지 않았다 — 취합이 반영 때 한 줄.

## 3. B 로그인 시도 제한 — 점검 표(인터넷 공개 전제)

| 지킴 | 지금 값(읽은 곳) | 공개 전제에서 | 판단 |
|---|---|---|---|
| nginx IP 속도 제한 | `limit_req_zone $binary_remote_addr zone=tbox_login:1m rate=30r/m` + `burst=10 nodelay` · 429 (`deploy/sites/tbox-common.conf:58` · `tbox-v2.conf` 12자리 — `/tbox/api/login`·`/me/password`·`/me/mfa/enroll`·`confirm`·`disable`·`/me/reauth` × 끝 슬래시). `$binary_remote_addr` 는 real_ip 뒤 = 실제 손님 주소. /etc 설치본 = 저장소(대조) | IP 하나당 분당 30 · 하루 약 4.3만 번 — 여러 IP 로 나누면 무력 | 그대로. 아래 빈 곳 넷은 제안안 |
| nginx 가 없는 비밀 창구 | `POST /api/me/api-tokens`(비밀번호+코드) · `POST /api/files/{id}/exec-download`(비밀번호+코드) · `PATCH /api/admin/permissions`(코드) · `POST /api/fetch-hosts`(코드) · 올리기 확인(비밀번호+코드, `POST /api/files`) | 세션을 쥔 사람만 닿는다 · 앱 잠금이 5회에서 끊고 세션도 회수 | **제안안**(아래) — 올리기 확인은 보통 올리기와 같은 창구라 빼고 앱 잠금만 |
| 앱 계정 잠금 | 실패 5회 → 10분(`Auth::MAX_FAILED`·`LOCK_MINUTES`) · 잠긴 동안 423(비밀번호도 보지 않음) · 풀린 뒤 카운터 1부터(R-49) · 성공하면 0 · 로그인 뒤 창구 실패도 같은 카운터 + 잠기면 그 계정 세션 전부 회수(R-48) | 계정 하나당 10분에 5번 = 하루 720번 | 그대로 |
| IP 별(앱) | 없음 — nginx 한 겹 | | 그대로(앱에 IP 표를 두면 사무실 한 주소 뒤 여럿이 함께 막힌다) |
| 2단계 코드 시도 | 로그인 틀린 코드·재사용 = 실패 1회(같은 카운터) · 코드 없이 비밀번호만 = 세지 않음(순차 2단계의 정상 1단계) · 로그인 뒤 코드 창구 전부 `countFailureAndRevoke` · 1회성(`mfa_last_step`) · 창 ±1 | 비밀번호를 아는 사람이 하루 720번 → 맞힐 확률 하루 약 0.2%(한 번에 맞는 코드 3개 / 100만) · 한 달 약 6% | **있다**(추가할 것 없음). 더 좁히려면 아래 선택지 ④ |
| AL-2 | 10분 창 · IP 별 · 인증 실패 20회 이상 **또는 잠금 발생** · 30분 억제 | 「잠금 발생」이 잠긴 계정으로 **다시** 로그인한 행(`locked`)만 셌다 → **정확히 5번 틀리고 10분 쉬는 잠금 DoS 와 로그인 뒤 코드 추측 잠금은 조용했다** | **고침**(아래) |
| 잠금 DoS (R-06) | 아이디만 알면 10분에 요청 5개로 계속 잠금 — IP 제한 밑 · IP 를 바꿔도 된다 | 관리자 계정을 계속 잠글 수 있다 | 막는 것은 **결정 필요**(선택지 ②③) · 이번엔 **알림**만 생김 |

**앱 코드로 고친 것** — 잠금이 걸린 순간 감사 `auth.lock deny failed_limit` 한 행(`Auth::countFailure` — `SQL_COUNT_FAILED` 가 갱신 뒤 `failed_count` 를 돌려주고, 카운터가 **정확히 한도**일 때만 — 잠긴 동안의 추가 실패는 다시 남지 않음 · 로그인 전이면 actor 없음 · 대상 = 그 계정 · `detail.lock_minutes`) ·
`scripts/alerts.php` AL-2 가 그 행을 세는 행·`locked` 수에 넣는다(`after_login` 에는 넣지 않음) · 문장 틀·ARCH 7.4·7.5 등록.
실측(B-4): 127.0.0.2 에서 5번만 틀림 → 새 판 AL-2 1줄(locked 1) · **기준 판 alerts.php 는 같은 DB·같은 창에서 0줄**.
순서 메모: 로그인 창구는 세고 나서 거부 행을 적으므로 잠금 행이 다섯 번째 거부 행 **앞**에, 로그인 뒤 창구는 거부 행을 먼저 적으므로 **뒤**에 온다(문장·경보에는 영향 없음).

**nginx 제안안(반영 안 함)** — `review-scripts/apply-nginx-login-limit-2026-09-26.sh` + 제안 판 `review-scripts/nginx-login-limit-2026-09-26/tbox-v2.conf`
(운영 판 `deploy/sites/tbox-v2.conf` 에 location 일곱만 더함 — 지우거나 바꾼 줄 0 · zone 은 그대로 · 로컬 8082 는 바꾸지 않음: f65·f66·fetch_hosts 시험이 이 창구를 빠르게 부른다).
틀: `--plan` · `--dry-run`(/etc/nginx 임시 사본에 끼워 `nginx -t`) · `--apply`(자동 되돌림 **15분 예약 먼저** → 백업 → 설치 → `nginx -t` → reload → 확인, 실패하면 바로 되돌림) · `--confirm` · `--revert`.
인자는 정확히 하나 · 확인은 감사 행이 남지 않는 요청만(도메인 health·로그인 화면·런처·`nginx -T` 자리 수). 복제본에서 `--plan`·`--dry-run`(통과 — 자리 24 → 31)과 인자 거부(없음·`--dryrun`·`--dry-run=1`·둘·빈 값 → 1)만 돌렸다.
반영하면 저장소 `deploy/sites/tbox-v2.conf` 를 같은 내용으로 맞추는 일이 남는다(다음 저장소 묶음).

**결정이 필요한 선택지**(편의와 맞바꿈 — Fable/claude 는 고르지 않는다)
① 위 nginx 제안안 반영 ② 잠금 DoS: 잠금 대신 지연(실패마다 대기 늘림) 또는 (계정+IP) 잠금 — 공격자는 자기 IP 만 잠그게 되지만 IP 를 바꾸는 무차별 대입에는 계정 잠금이 약해진다
③ 관리자 아이디를 짐작하기 어렵게(`admin01` 류 이름 바꾸기) ④ 2단계 코드 추측을 더 좁히기 — 하루에 잠금이 여러 번이면 더 긴 잠금·관리자 풀기(잠금 DoS 가 더 아파진다)
⑤ 로그인에 오래 도는 두 번째 창(예: IP 당 시간 100번) — 사무실처럼 한 공인 주소 뒤 여럿이 아침에 몰리면 걸릴 수 있다.

## 4. D 낮음 셋

| 무엇 | 고친 것 |
|---|---|
| 손님 커뮤니티 쓰기 값 | 개인 공간 **주인**을 역할과 상관없이 참으로 보는 판정(`decidePageChange`·`decidePageManage`) 때문에 손님 자기 공간(「시작하기」)에서 `can_edit` 등이 참이었다(쓰기 창구는 라우트 정책 `community.write` 가 403 — 권한 구멍 아님). 판정 함수는 그대로 두고 **창구 값만** `Authz::canCommunityWrite($user)` 와 AND: `pages_get.php`(can_edit · can_set_visibility · can_set_properties · can_manage · can_share · can_handover · can_tag · can_publish · can_unpublish · can_hide · can_resolve · can_comment · template_scopes · DB 페이지 `can_add_rows`·`can_manage_schema` · 행 `can_edit`) · `database_rows.php`(can_add_rows · can_edit_values · can_manage_schema) · 같은 모양 넷 — `pages_list.php`(공간 `can_write`·공유 행 `can_edit`) · `versions_list.php`(can_edit) · `pages_shares_list.php`(can_manage) · `spaces_list.php`(늘 true 이던 `can_write`). `community/database.js` 는 창구 값을 그대로 따르므로 고치지 않았다(다른 에이전트 몫 — U 검토의 database.js L-2 는 그대로 남음) |
| root 경보 배치의 감사 파일 사본 | `Audit::toFile` — root(또는 posix 없는) CLI 에서 `appendLineNoFollow` 가 던지면 **경고 한 줄(표준 오류) 뒤 계속**(DB 행은 이미 남음). 전에는 회차 전체가 멈췄다. 남는 것 → SECURITY R-105 ⑥(그 행은 파일 사본·해시 체인에 없다 · cron 은 표준 오류를 버린다) |
| `getmyuid()` 대체 | 제거 — `scripts/alerts.php` 상태 파일은 posix 가 없으면 **믿지 않고**(빈 상태 · 경고) · `cli_append_line` 은 **쓰지 않는다**(예외 → `alerts_log_failed`) · `Audit::toFile` 은 posix 가 없으면 링크를 따라가지 않는 쪽으로 쓴다. `getmyuid()` 는 스크립트 파일의 주인이라 root 배치가 changkwon 소유 상태 파일을 믿을 수 있었다 |
| AL-9 | 지시대로 **그대로**(검토·반영 스크립트의 운영 `sudo psql` 읽기가 경보를 올리는 것 — 이름으로 빼면 감시가 뚫린다) |

## 5. 시험

**수락 시험** `tests/dbg_s2_security.sh`(임시 DB `tbox_d2s_8261_test` · 인스턴스 8261): A(발급 머리 · 옛 쿠키 든 로그인 · 로그아웃 · 새/옛/둘 다/흉내 이름 · 커뮤니티 · Chromium 저장·전송·옛 쿠키 지움) · C(미로그인 앱 낱말 9개 0 · 세 역할 로그인 뒤 타일 · 옛 쿠키만) · D-1(같은 계정 직원 17개 참 ↔ 손님 21개 거짓 · 되돌리면 참 · 진짜 손님 계정 · 쓰기 403 그대로) ·
B(2단계 코드 5회 → 423 · 잠금 행 1 · 코드 재사용 · 재인증 5회 → 세션 전부 끊김 · 잠금 DoS → AL-2 새 판 1줄 / 기준 판 0줄 · nginx 값 확인) · D-2·3(posix 없음 두 번 · root 한 번 · getmyuid 토큰 0) · 정적(쿠키 이름 · 시험 틀 전체 이름 · Playwright 쿠키 사전 30개).
출발지는 루프백 다른 주소(127.0.0.2~5, `curl --interface`)라 AL-2 의 IP 창이 서로 섞이지 않는다.

| 실행 | 결과 |
|---|---|
| 1회(첫 수락 시험 — `selftest-base/dbg-S2`) | 100/2 — **시험 쪽** 결함 둘: 옛 쿠키 든 로그인 병에 새 이름을 같이 실어 세션 고정 막기가 S_E2 를 버림(→ CSRF 1건) · 재시도가 옛 쿠키를 먼저 치워 머리가 한 줄 → 병을 옛 이름 하나로 · 새 코드 창 첫머리에 보냄 |
| 2회 | **102/0** |
| 3회(sudo psql 한 모양 규칙 반영 뒤 · qf3c 회귀 포함) | **103/0** + qf3c_zip 117/0 = 104/0 |

**회귀**(같은 임시 인스턴스 · 운영 쓰기 시험 제외 — 카탈로그 writes_prod·manual_only 거부 · 19:26~19:40)

| 시험 | 결과 | 비고 |
|---|---|---|
| help_button | 16/0 | 화면 점검(ui_snapshot) 포함 — `__Host-` 쿠키 넣기 고친 뒤 |
| api_whoami | 21/1 | `/api/me` 키 집합 기대값에 `can_community_write`(묶음 U 가 더함)가 없다 — **기준 판 4d256f6 부터** 같은 실패(시험 기대값 낡음 · 이 묶음 밖 — 보고만) |
| api_read · api_folders | 25/0/4 · 25/0/5 | |
| f68 | 109/0/2 | |
| q_launcher | 97/4/1 | 4건 = nginx 없는 임시 인스턴스(생성기 CSP · 옛 `/assets/app.css` 308·Location) + 임시 저장소에 바이트가 없어 링크 받기 404 — S·U 기록과 같은 넷(기준 판도 같음). 새 항목(로그인 전 앱 0 · 옛 쿠키 만료 · `__Host-` 이름·Domain 없음 · 로그인 뒤 타일) 전부 통과 |
| qf3c_zip(사본 · 8262~8267 · `tbox_d2s_qf3c_`) | 104/13 → 고친 뒤 **117/0** | 13건 = C-T10 옛 판(기준 판 코드) 인스턴스에 새 이름 쿠키를 보내 401 — 옛 판으로 가는 요청만 옛 이름으로 고침 |
| dbg_s_security(사본 · 8268 · `tbox_d2s_s_`) | 153/0 | |
| dbg_u_ui(사본 · 8269 · `tbox_d2s_u_`) | 19/0 | 화면 |
| community stage6a · stage7d1(사본 · 8264·8265) | 122/0 · 63/0 | 커뮤니티 대표(DB 페이지 창구 값 · 최근 단계) |
| 보안 린트 | 0건 | |
| 봇 정적 selftest | 238/0 | |
| 봇 정적 규칙(기준 판 대 이 판) | 같음 — R15 +6 · R20 +1 · R31 +1 은 새 시험 파일 몫 | R31(셈 범위에 tests/static) · R20(임시 복제의 .env 사본 — make_env 와 같은 받아들인 위험 표식) 은 고침. **R15 여섯은 오탐으로 봇에 알린다** — 아래 |

**봇 오탐 알림(R15 — 달래지 않고 알림)**: `tests/dbg_s2_security.sh` 의 여섯 줄(258·265·449·490·507·583 — 커밋 `34f802a` 기준 줄)은 비밀이 명령 인자로 가지 않는다.
① `$(curl_json_secret …)`·`$(curl_hdr_secret …)` 의 **출력**은 `-w '%{http_code}'` — 상태 코드인데 함수 이름의 SECRET 으로 비밀로 친다 ② `$(review_password …)`·`$(review_secret …)` 값은 `printf -v`(빌트인)·`py_secret <이름>`(표준입력)으로만 간다
③ `$(session_of …)` 값은 `S_OUT_HDR` 에 담겨 `curl_hdr_secret Cookie S_OUT_HDR`(이름 — 표준입력)로만 간다 ④ 보고된 받는 쪽 명령 이름이 「e2old.jar" | wc -l」·「—」·「·」·「이」처럼 **설명 글자**다 — `"$(awk … '$6==n' "…jar" | wc -l | tr -d ' ')"` 같은 따옴표 겹침을 풀이기가 잘못 나눈 것으로 보인다(쪼개 돌리면 260줄째에서 처음 나타남).

**운영 흔적**(읽기만): 운영 감사 max(id)·alerts.log 는 시험 동안 **운영 cron 의 AL-9 만** 늘었다 — 시험의 `sudo psql`·`pg_dump`(임시 DB)가 auth.log 에 남아 명령마다 한 줄. 18:48 시작 24076 · 1179줄 → 22:0x 24500 · 1603줄(두 묶음 시험 합계 — 취합 보고 417줄).
21:4x 취합 규칙 뒤로 이 묶음의 시험 틀은 `psql` 명령 줄을 **세 모양**(임시 DB · postgres · 운영 읽기 시작·끝 한 번씩)으로 묶고 SQL 은 표준입력 — 3회 실행의 몫은 4~5줄(pg_dump 포함). 기존 시험 틀(qf3c·q_launcher·dbg_s_security 등)의 `-c` 는 고치지 않았다(범위 밖 — 보고만).
`/etc/tbox-v2/qa-totp.txt` mtime 은 처음부터 끝까지 `16:57:23` 그대로. 운영 감사에 이 시험 표식(UA) 행 0(매 실행 확인).

## 6. 반영 스크립트

`review-scripts/apply-dbg-s2-repo-2026-09-26.sh` — 본보기 `apply-dbg-s-repo-2026-09-26.sh`(오늘 통과판) 그대로 + 이 묶음에 맞춘 다른 점:
★ 머리 주석과 사전 확인에 **반영 순간 모두 로그아웃** — 한 번의 운영 읽기(표준입력)로 「최근 10분 사람 감사 행」(0 이어야)과 「살아 있는 사람 세션·사람 수」를 보고, 사람 세션이 있으면 멈춘다(알고 돌릴 때만 `--logout-ok`) ·
확인은 `php -r`(쿠키 이름 새·옛 · `auth.lock` 문장 틀 · getmyuid 토큰 0 · AL-2 가 auth.lock 을 셈) + HTTP(쿠키 없는 런처 = 앱 0·로그인 단추 · 옛 이름 쿠키만 = 만료 머리 한 줄 · 로그인 화면 둘 200 · 미로그인 커뮤니티 302) — **미로그인 /api·/s/ 0**(런처는 쿠키가 없거나 옛 이름뿐이면 DB 에도 붙지 않는다) ·
되돌리면 한 번 더 모두 로그아웃(머리 주석). 고정 값은 `review-scripts/pins-dbg-s2-repo.sh`(본보기 pins-dbg-s 그대로 — 라우트·DB·배포 파일이 패치에 있으면 찍지 않는다).
연습 결과는 10장.

## 9. 커밋 · 태그 · 산출물

| 무엇 | 값 |
|---|---|
| WIP(첫 수락 시험 직전) | `3230093` — 태그 `selftest-base/dbg-S2` |
| WIP 2·3 | `34f802a`(문서·반영 틀·nginx 제안) · `7f44c86`(sudo psql 한 모양 · qf3c 옛 판 쿠키 · 정적 R31·R20) |
| 검토 판 | 태그 `review-base/dbg-S2` — 번들 `review-base-dbg-S2.bundle` · 패치 `dbg-S2-repo.patch` = `git diff 4d256f6 review-base/dbg-S2 -- . ':!review-scripts'` · sha256 목록 `dbg-S2-SHA256SUMS`(모두 복제본 밖 `~/.local/share/qaredi-dev/` · 600 — 앞 묶음과 같은 자리·이름 틀) |

## 10. 반영 스크립트 연습

연습(경로만 바꾼 사본 — `APP` = `4d256f6` 으로 둔 임시 git clone(`--shared`) · `L8082` = 임시 인스턴스 8262(그 트리를 서빙) · `DB` = 임시 DB `tbox_d2s_8262_test` ·
운영 트리·운영 포트·운영 DB 쓰기 0 · 연습 틀 `practice.sh` 는 복제본 밖 작업 폴더):

| 경우 | 결과 |
|---|---|
| ① 빈 인자 · ② 인자 모자람 · ③ 모르는 인자(`--dryrun`) | 사용법 · 종료 1 · 트리 그대로 |
| ④ 틀린 sha | 「패치 sha256 이 알려 준 값과 다르다」 · 1 · 그대로 |
| ⑤ `--dry-run` | 사전 확인까지(고칠 파일 87 = 기준 판 · 라우트 두 표 그대로 · apply --check · 사람 감사 0 · **로그아웃될 사람 세션 0 0**) · 0 · 그대로 |
| ⑥a 살아 있는 사람 세션 하나(임시 DB 에 넣음) · `--logout-ok` 없음 | 「살아 있는 사람 세션이 있다(1 1) — 반영하면 그 사람들이 로그아웃된다」 · 3 · 그대로 |
| ⑥b 정상(`--logout-ok`) | 확인 16 ✔(바뀐·새 파일 89 = 검토 판 · php -l 15 · bash -n 38 · py_compile 29 · 부품 버전 · 라우트 두 표 · `php -r` 둘 · HTTP 일곱 — 런처 앱 0·로그인 단추 · 옛 쿠키 만료 한 줄 · 로그인 화면 둘 · 커뮤니티 302) · 0 · 89개 바뀜 |
| ⑦ 이어서 다시 | 「커밋 안 된 변경이 있다」 · 2 |
| ⑧ 확인 실패(HTTP 를 없는 포트로) | HTTP ✖ → 되돌림 → 고친 파일 = 기준 판 · 새 파일 2 없음 · 라우트 그대로 · 4 |
| ⑨ 패치 직후 TERM | 신호 덫 → 되돌림 → 기준 판 · 4 |
| ⑩ curl 이 실패하는 PATH | `code()`·런처 요청이 `|| true` 라 ERR 가 아니라 확인 실패로 잡힘 → 되돌림 · 4 · 기준 판 |

이 기록을 넣으면 패치 sha 가 바뀌므로, 마지막 판(`review-base/dbg-S2`)에서 고정 값을 다시 찍고 ①~⑩ 을 한 번 더 돌렸다(보고의 sha 가 최종).

## 11. 독립 검토 반영 (「고친 뒤 반영」 — 높음 0 · 새 커밋 · 태그 `review-fix/dbg-S2`)

| # | 지적 | 고친 것 | 시험 |
|---|---|---|---|
| M-1 | 손님이 전 직원 목록을 뽑음 — `share_candidates.php` 가 `decidePageShare` 만 봐서 손님이 자기 「시작하기」 페이지 id 로 `GET /community/api/share-candidates?page=…&q=a` 를 부르면 활성 사용자 전체(아이디·이름·역할) · 팀 공간 팀원인 손님도 | 판정 뒤 `Authz::canCommunityWrite($user)` 가 아니면 403(공유·인계 둘 다 — 쓰는 창구가 community.write). **같은 부류 훑기**(커뮤니티 GET 라우트 31개 중 사람 목록·이름을 주는 것): `mention-candidates`(멘션은 댓글 쓰기에서만 — 손님 403) · `pages/{id}/shares` 의 이름 목록(`shares` — 손님은 수만, 이름 null) 을 같이 고침. `page_reads` 는 수만 · `comments`·`history`·`versions` 의 이름은 읽을 수 있는 글의 쓴 사람이라 그대로 | `tests/dbg_s2_security.sh` D-1 — 같은 계정이 직원일 때 공유·인계·멘션 후보 200(후보 1명 이상) · 이름 목록 list ↔ 손님일 때 403 403 403(본문에 후보 0) · 이름 null · 진짜 손님 계정의 자기 「시작하기」 id 로도 403 셋 |
| L-1 | 템플릿 `can_delete`(routes.php 템플릿 출력)가 손님에게도 참 | `Authz::canCommunityWrite($u) &&` | D-1 — 직원 자기 개인 템플릿 can_delete 참 ↔ 손님 거짓 |
| L-3 | AL-2 가 잠금 행(`auth.lock`)을 실패 수·`locked` 에 더함 | count·locked·HAVING 의 20회 기준에서 뺌(`FILTER (WHERE action <> 'auth.lock')`) · 근거에 `lock_events` 로 따로 · 발동 조건에는 그대로(잠금 순간 알림) | B-4 — dry-run 근거 `count 5 · locked 0 · lock_events 1` · 실제 경보 줄 같은 값 · 기준 판 0줄 그대로 |
| L-4 | 감사 조회 「어떻게」 허용 목록(`audit_list.php`)·화면 선택지(`audit_page.php`)에 `auth.lock` 없음(`auth.reauth` 도 빠져 있었음) | 둘 다 넣음(화면 파일은 선택지 두 줄만) | 정적 대조(허용 목록 한 줄 · 선택지 둘) |
| L-2 | 미로그인 `/` 에 `/tbox/login` 링크와 `/tbox/assets/app.css` 경로가 남음 | **취합 결정: 받아들임** — 앱 목록·이름 타일은 0, 로그인 경로와 스타일 경로는 남김(2장 「남는 것」과 같음) | — |
| nginx 권함 ① | 같은 경로의 GET(토큰 목록·권한 표·호스트 목록)이 로그인과 같은 IP 버킷을 씀 | 새 zone `tbox_secret`(30r/m · burst 10) — `map $request_method` 로 GET·HEAD 는 키가 빈 값(세지 않음) · 로그인 zone 과 다른 버킷. map·zone 은 제안 판 맨 위(http 단 — sites 파일은 http 안에 include) → conf.d 는 그대로. 머리 주석에 「반영하면 저장소 deploy/sites 도 바로 맞춘다」. 여전히 반영 안 함 | `--dry-run`(nginx -t 통과 · 로그인 자리 24 그대로 · 비밀 자리 7) · `--plan` |

**시험(검토 반영 판)**: `tests/dbg_s2_security.sh` 112/1(시험 쪽 결함 — 쓰기 값 모음에 새 단언 `shares.names` 가 섞임) → 고친 뒤 **110/0**(몸통만 · 회귀 없이). 회귀(바뀐 창구를 다루는 커뮤니티 시험 — 사본 · 8264~8266 · `tbox_d2s_`): stage5a2 139/0(공유·인계 후보) · stage5d 93/0(댓글·멘션) · stage7b 141/0(템플릿) · 보안 린트 0 · nginx 초안 `--dry-run` 통과. 운영 AL-9: 이 회귀 사본 셋(기존 틀의 `psql -c`)이 150줄(22:25·22:30) — 이 묶음 틀 몫은 한 모양씩 몇 줄.

반영 스크립트 틀: 이 검토로 `src/community/routes.php`(라우트 표 **밖** 함수 한 줄)가 패치에 든다 — `pins-dbg-s2-repo.sh` 는 파일은 받되 라우트 표 줄만 모은 sha 가 기준 판과 같을 때만 찍고, 반영 스크립트는 글자 거부 목록에서 routes.php 를 빼고 반영 전·뒤 라우트 표 줄 sha(`CROUTES_WANT`)를 그대로 본다. 확인 `php -r` 에 「사람 찾기 창구 둘이 쓰기 자격을 봄 · AL-2 lock_events」.

**다음에 할 것**: 도움말(`src/help/screens.php` 내 계정)·README 의 「v2.31 에서 한 번 다시 로그인」은 **한시 문구**다 — 반영 다음 판에서 뺀다(취합 권고) · nginx 제안안을 반영하면 저장소 `deploy/sites/tbox-v2.conf` 를 같은 커밋으로 맞춘다 · 기존 시험 틀의 `sudo psql -c`(AL-9 소음)는 이 묶음 밖 — 보고만.
