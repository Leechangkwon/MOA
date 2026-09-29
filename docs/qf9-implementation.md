# Q:F-9 등록 PC 에서 토큰으로 바로 로그인 — 구현 기록

지시서 `docs/qf9-pc-login-directive.md`. 기능 묶음 하나 = 조각 둘(S 서버 · B 비서 aa) — 검토·회귀·반영은 묶음 끝에 한 번(지침 3.1).
이 파일은 조각마다 한 절씩 쓴다(조각 B 는 B 작업자가 아래에 더한다).

---

## 조각 S — 「이 PC 로그인 전용」 토큰 · 1회용 30초 링크 · 토큰 출신 세션 (서버) · 2026-09-29 · 구현자 Opus(메인 Fable 이 맡김) · 검토 전

### 1. 한 줄 요약

개인 토큰에 다섯째 범위 **`session.open`**(독립 값 · 2단계 켜진 비Guest 만)을 더했다. 발급 때 서버가 본 공인 IP(`Http::clientIp()`)에 묶이고
같은 사람의 앞 로그인 전용 토큰은 자동 회수된다(1대). 그 토큰으로 `POST /api/me/session-link` → 30초·1회용 링크 → 브라우저가
`GET /session/open?code=…` 를 열면 **`origin='token'` 세션**이 생긴다. 토큰 세션은 유휴 10분 · 민감 동작 재인증 필수 · 토큰 회수/만료/IP 변경 즉시 종료.
일반 로그인 세션(`origin='login'`)의 동작은 바뀌지 않았다(T-6 기준 판 b7f6da4 대조).

### 2. 변경 파일

| 파일 | 줄 | 무엇 |
|---|---|---|
| `db/migrations/2026-10-12_session_open.sql` | 새 파일 | `api_tokens.bound_ip` · scope CHECK 다섯 값 · `api_tokens_bound_ip_check` · `sessions.origin`(DEFAULT 'login')·`token_id`(FK CASCADE)·`sessions_origin_check` · `session_links` 표(code_hash 만) · 권한 · 멱등 · 행 변경 0 · 되돌리기 절 |
| `db/schema.sql` | +49 −3 | 같은 모양(새 설치) — sessions.token_id FK 는 api_tokens 뒤에 ALTER 로 |
| `db/grants.sql` | +24 | session_links 절(tbox_app SELECT·INSERT·UPDATE·DELETE · backup SELECT) |
| `public/index.php` | +36 −5 | 라우트 둘 · 토큰 허용 표 셋(ROUTES·ONLY·PERSONAL)에 링크 창구 · [3-2] 가 `Authz::requiresReauth($policy, session_origin, TBOX_ROUTE)` · 주석 |
| `src/Auth.php` | +276 −18 | `TOKEN_SESSION_IDLE_MIN=10` · 링크 상수 셋 · `SQL_LOOKUP`(origin·token_id · 유휴 CASE · token 세션은 토큰 생존·bound_ip=s.ip=지금 IP) · `currentUser` 에 `session_origin`·`session_token_id` · `issueSession($tokenId)` 갈래 · `openSessionFromLink()` · `AuthSessionLinkUsedException` |
| `src/Authz.php` | +154 −3 | KNOWN_POLICIES `session.open` · `TOKEN_SESSION_REAUTH_POLICIES`·`_ROUTES` · TOKEN_SCOPES 다섯 · `canOpenSessionByToken` · tokenScopesFor · tokenScopeAllows · `requiresReauth($policy, $origin, $route)` · authorize case |
| `src/Audit.php` | +29 | **수정 파일에 사후 추가**(메인 승인 2026-09-29 — 아래 6 ①) — 문장 11줄 · autoDetail 에 `auth=token_session`·`token_id` |
| `src/handlers/me_session_link.php` | 새 파일 | 링크 창구(IP 대조 403 ip_mismatch · 1분 5회 429 · 코드 sha256 · 감사) |
| `src/handlers/session_open_page.php` | 새 파일 | 교환 화면(성공 302 /tbox/ 또는 허용 목록 to · 실패 302 /tbox/login) |
| `src/handlers/me_tokens_issue.php` | +90 −? | session.open: bound_ip(없으면 400 ip_unknown) · 앞 토큰 회수(`api_token.revoke allow replaced`) · 상한 계산에서 로그인 전용 제외 · 응답 bound_ip·revoked_previous · 감사 detail bound_ip |
| `src/handlers/me_tokens_list.php` · `api_tokens_list.php` | +3 · +5 | 줄마다 `bound_ip` |
| `public/assets/app.js` | +40 | 범위 라벨 · 범위 칸 「· 묶인 IP」 · 고르면 도움말 줄 글자 바뀜 · 발급 결과 「묶인 IP · 앞 토큰 n개 회수됨」(textContent · 새 클래스 0) |
| `scripts/alerts.php` | +40 | **AL-24**(지시서의 AL-18 은 이미 사용 중 — 아래 6 ②) |
| `API.md` 20장 · `ARCHITECTURE.md` 4.9·7.4(11행)·7.5(2행) · `PRD.md` F-75·8장 2행 · `SECURITY.md` R-113·6.8·7장 1행 | 문서 |
| `tests/qf9_pc_login.sh` · `tests/catalog.json` | 새 시험 + 카탈로그 한 항목 |

### 3. 토큰 출신 세션의 재인증 집합 (확정 목록 — 지시서 5 의 좁은 쪽 기본안)

정책(`TOKEN_SESSION_REAUTH_POLICIES`): `user.manage` · `org.manage` · `audit.read` · `role:Admin`(= admin.* 전부 · 커뮤니티 관리 창구) · `file.delete`(파일 삭제 · 폴더 삭제).
라우트(`TOKEN_SESSION_REAUTH_ROUTES` — **24줄**, T-7 이 줄마다 실제 라우트인지 대조. 처음 27줄에서 메인 판단으로 조회 둘·고대비를 뺐다 — 9절):
- 내 계정 보안: `POST /api/me/password` · `POST /api/me/mfa/enroll` · `POST /api/me/mfa/confirm` · `POST /api/me/mfa/disable` · `POST /api/me/api-tokens` · `DELETE /api/me/api-tokens/{num}` · `POST /api/me/passkeys/options` · `POST /api/me/passkeys` · `DELETE /api/me/passkeys/{num}`
- 파일: `POST /api/files/{id}/share`
- 커뮤니티 지우기: `DELETE /community/api/pages/{id}` · `DELETE /community/api/templates/{id}` · `DELETE /community/api/comments/{id}`
- 옮기기: `PATCH /community/api/pages/{id}/move`
- 공개 범위: `PATCH …/pages/{id}/visibility` · `POST|DELETE …/pages/{id}/publish` · `POST|DELETE …/pages/{id}/hide` · `POST|DELETE …/comments/{id}/hide`
- 공유·인계: `PUT|DELETE …/pages/{id}/shares/{username}` · `POST …/pages/{id}/handover`

일부러 뺀 것: 토큰 목록·패스키 목록 조회 · `PATCH /api/me/settings`(고대비 — 메인 판단) · 로그아웃 · 재인증 창구 넷(비밀번호·지문 — 막으면 들어갈 길 없음) · 역할로 보기 끄기 · 공유 링크 회수 · 휴지통 되살리기 · URL 가져오기 · 판 되돌리기(version restore) · DB 스키마 고치기 · 일상 읽기·쓰기.
커뮤니티 라우터는 `src/community/**` 를 고치지 않고 덮었다 — `requiresReauth` 가 인자가 없으면 현재 사용자의 `session_origin` 과 `TBOX_ROUTE` 를 읽는다.

### 4. 실행한 명령과 결과 (원문은 메인 보고에)

| 명령 | 결과 |
|---|---|
| `bash tests/qf9_pc_login.sh` | **130 통과 · 실패 0** (최종 재실행 결과는 보고) |
| `REGRESS=1 bash tests/qf9_pc_login.sh` | 수락 130/0 + api_whoami **22/0** + f66 **114/3**(실패 셋 = `token_scopes` 끝에 session.open · 내/관리자 목록 행 키에 `bound_ip` — **의도된 변화**, f66.sh 는 수정 파일 밖) |
| `tests/qf8_token_write.sh` 사본(포트 8421·8422 · DB `tbox_qf9_q8_…` · 2026-10-12 마이그레이션 추가) | **83/5** — 다섯 전부 의도된 변화(범위 다섯째 · CHECK 가 둘 · 기준 c2b4d76 대비 index/Auth 차이 · 사본에 넣은 마이그레이션 위치 때문의 「두 번 적용 같음」) — T-2~T-6 동작 항목 전부 통과 |
| `tests/sec4.sh` 사본(포트 8423 · 접두 tbox_qf9_ · 마이그레이션 추가) | **62/2** — 실패 둘 = Playwright 없음(환경) |
| `php tests/passkey_unit.php` | 자체 항목 22 통과 · 표본 파일 항목 미검증(python cryptography 의 `_cffi_backend` 없음 · pip 설치 불가 — 환경) |
| `bash scripts/security_lint.sh .` | 통과(금지 패턴 0건) |
| `bash tests/static/selftest.sh` | 238/0 |
| `python3 tests/static/r2_audit_crosscheck.py --root .` | 기준 판과 FINDING 같음(12 — 새 것 0) · pass 711→729 |
| `php -l`(바뀐 PHP 전부) · `node --check public/assets/app.js` | 0 |

### 5. 못 한 것과 이유

- 화면(설정 화면 select·도움말·목록 칸) 브라우저 확인 — Playwright 없음 → **미검증**.
- passkey_unit 의 표본 기반 항목 — 파이썬 cryptography 가 이 환경에서 깨져 있음 → 미검증.
- 운영 반영·nginx(`/tbox/session/open` 로그 쿼리 빼기·속도 제한) — deploy 는 조각 밖.

### 6. 지시와 다르게 한 것

① **`src/Audit.php` 를 고쳤다**(지시서는 「autoDetail(읽기만)」·수정 파일 목록 밖). 까닭: 설계 3·4 의 감사 `session.link`·`session.open` 과 발급의
`ip_unknown`·`replaced` 는 `SUMMARY_TEMPLATES` 에 문장 틀이 없으면 **기록 실패 = 요청 500** 이고, 설계 8 의 `auth=token_session` 은 autoDetail 안에서만
붙일 수 있다(`TBOX_AUTH_METHOD` 를 세우면 files_update·folders_update·files_list 가 「헤더 토큰 요청」 갈래를 타 화면이 깨진다). 고친 것은 문장 11줄과
autoDetail 의 조건 한 덩이(일반 세션 행 불변)뿐 — ARCH 7.4 에 같은 글자로 더해 R2 대조 통과. 받아들이지 않으면 감사 설계를 다시 정해야 한다.
② 경보 번호 **AL-24** — AL-18 은 이미 「URL 가져오기 허용 사이트 변경」이다.
③ 실패 302 는 `/tbox/login`(꼬리표 없음) — `Http::redirect` 가 `?reason=` 모양을 받지 않고 Http.php 는 금지 파일이다.
④ (더함) 세션 조회가 token 세션에 한해 **토큰 생존 + 묶인 IP = 세션 IP = 지금 IP** 를 본다 — 토큰 회수·IP 변경 때 이미 연 세션도 끝난다(좁은 쪽).
⑤ (더함) 링크 세션은 `failed_count` 를 0 으로 돌리지 않는다(비밀번호를 맞힌 일이 아니다).
⑥ 계약의 「503(계정 절차 중)」 — 실제는 기존 `requireApiToken` 대로 **403 권한이 없습니다**(바꾸지 않았다). API.md 20장에 실제 값으로 적었다 → 조각 B 가 알아야 한다.
⑦ 재인증 창구는 지시서 표현(「비밀번호+OTP」)과 달리 지금 코드 그대로 **비밀번호만**이다(고치지 않았다 — 판단 대기).

### 7. 판단 대기 (기본안 = 좁은 쪽으로 해 둠)

1. Audit.php 수정(6 ①) 승인 여부.
2. 토큰 세션의 재인증에 2단계 코드를 더할지(지금은 비밀번호만 · R-113 ⑥).
3. 재인증 집합 — 목록 조회 둘(`GET /api/me/api-tokens`·`GET /api/me/passkeys`)과 `PATCH /api/me/settings`(고대비)까지 넣었다. 빼도 되는지.
4. nginx: `/tbox/session/open` 쿼리를 접근 로그에서 빼기 · 속도 제한(deploy).
5. 조각 B 계약 보정: 절차 중 계정은 503 이 아니라 403 · IP 불일치는 `{"code":"ip_mismatch"}` · 429 는 `{"code":"rate_limited","retry_after":60}`.

### 8. 남긴 임시 자원

없음 — 임시 DB(`tbox_qf9_8421_test` · `tbox_qf9_q8_8421_test` · `tbox_qf9_sec4_8423_test`) 지움 확인, 포트 8421~8423 비어 있음, 임시 폴더는 trap 이 지움.
참고: 이 문맥의 PostgreSQL 16 클러스터가 꺼져 있어(`pg_lsclusters` down) `sudo -n pg_ctlcluster 16 main start` 로 켰다.

### 9. 메인 판단 반영 (2026-09-29)

| 판단 | 반영 |
|---|---|
| ① `src/Audit.php` 수정 받아들임 | 지시서 「수정 파일」에 **사후 추가**한 것으로 적는다(검토자가 그 diff 를 따로 본다 — 문장 11줄 · autoDetail 조건 한 덩이) |
| ② 재인증에 OTP 추가 | **사용자 판단으로 올림** — 지금은 F-23 그대로 비밀번호만. SECURITY R-113 완화 ⑦ 「재인증은 F-23 그대로 비밀번호만 — OTP 추가는 판단 대기」 |
| ③ 재인증 집합에서 셋 뺌 | `GET /api/me/api-tokens` · `GET /api/me/passkeys` · `PATCH /api/me/settings` — 민감한 「변경」이 아니다. 27 → **24줄**. T-5 는 그 셋이 토큰 세션에서 **200**(재인증 없음)인지 새로 본다(집합 정의 변경 — 시험 약화 아님, 시험 줄에 까닭). T-7 줄 수 24 |
| ④ nginx | 코드로 만들지 않는다 — 아래 「반영 절차」에 사용자가 할 한 줄 |
| ⑤ 계약 보정 | API.md 20장이 진실(절차 중 403 · `code` 값 `ip_mismatch`·`rate_limited` · 429 본문) — 메인이 조각 B 에 전달 |

### 10. 반영 절차 (묶음 끝 — 사용자)

1. 마이그레이션: `sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-10-12_session_open.sql` — **코드보다 먼저**(코드가 먼저면 모든 로그인 요청 500).
2. `db/grants.sql` 재실행(같은 권한 — 멱등).
3. 코드 반영.
4. (선택 · deploy) nginx 한 줄 — `/tbox/session/open` 의 쿼리(코드)를 접근 로그에서 빼고 속도 제한:
   `location = /tbox/session/open { access_log /var/log/nginx/tbox-v2.access.log noquery; limit_req zone=tbox_share burst=10 nodelay; include snippets/tbox-php.conf; }`
   — `log_format noquery '$remote_addr - $remote_user [$time_local] "$request_method $uri" $status $body_bytes_sent';` 가 http 블록에 있어야 하고, zone·include 이름은 운영 설정의 실제 이름으로 맞춘다(코드로 만들지 않았다 — 미검증).

### 11. 독립 검토 반영 (`/home/user/qaredi-dev/review-qf9-2026-09-29.md` ②절 · 메인 결정 — 좁은 쪽 기본안)

| 발견 | 반영 | 수락 항목 |
|---|---|---|
| RV-QF9-1 로그인 CSRF·기존 세션 강제 종료 | `session_open_page.php` — `Auth::currentUser()` 가 있으면 교환하지 않는다: `Auth::refuseLinkWhileLoggedIn()`(링크 소비 0 · 감사 `session.open deny already_logged_in` — 행위자 = 지금 쿠키 주인 · 대상 = 토큰 주인) 뒤 성공과 같은 곳(`/tbox/` · 허용 목록 `to`)으로. 문장 틀 1줄(Audit.php · ARCH 7.4). R-113 ⑤ 보강 | employee02 로그인 브라우저가 employee01 링크 → 302 /tbox/ · 여전히 employee02 · 세션 수 불변 · 링크 used_at NULL · 감사 1 · 그 링크를 로그인 안 된 브라우저로 → 성공 |
| RV-QF9-2 1대 규칙 경쟁 | 부분 유일 인덱스 `api_tokens_one_session_open ON api_tokens(user_id) WHERE scope='session.open' AND revoked_at IS NULL`(마이그레이션 · schema.sql · 되돌리기 절) · 발급은 회수→삽입 한 트랜잭션, 23505(이 인덱스)면 한 번 다시 · 그래도면 409 | 둘째 살아 있는 행 INSERT → 유일 위반. schema.sql 새 설치 = 마이그레이션 두 번 적용 모양 **SAME**(제약·인덱스·열 — 임시 DB `tbox_qf9_fresh`·`tbox_qf9_mig` 대조 뒤 지움) |
| RV-QF9-3 2단계 끈 뒤 토큰 세션 | `SQL_LOOKUP` token 갈래에 `AND u.mfa_enabled` | 끈 뒤 토큰 세션 401 · 일반 세션 200 · 되돌림 |
| RV-QF9-4 IP 불일치에 속도 제한 없음 | 링크 창구의 분당 5회를 **IP 확인보다 앞**으로 · 셈 = 받은 링크 + 같은 token_id 의 `session.link deny ip_mismatch`(최근 60초 · 429 자신은 세지 않음) | employee02 토큰(틀린 IP) 6번 → 403×5 뒤 429 · IP 를 맞춰도 1분 안이면 429 · 링크 행 0 |
| RV-QF9-8 | ARCH 4.9 · API.md 20장 「틀린 IP 시도는 링크를 소비하지 않는다」 | (위 · 교환 쪽은 T-4) |
| RV-QF9-7 시험 | 인스턴스 `PHP_CLI_SERVER_WORKERS=4` · 동시 넷 → 성공 1 · 로그인 3 · `QF9_PORT` 8421~8424(기준 판 +1 · 8425 까지) | 동시 넷 1/3 |

결과: `bash tests/qf9_pc_login.sh` **통과 144 · 실패 0** · `security_lint` 0 · `php -l` 0 · R2 대조 새 FINDING 0 · 마이그레이션 두 번 멱등.
