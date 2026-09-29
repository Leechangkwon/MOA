# Q:F-9 등록 PC 에서 토큰으로 바로 로그인 — 구현 지시서 (Fable → Opus · 2026-09-29)

사용자 요구(2026-09-29 12:xx): 「AI 비서(tbox-buddy)는 토큰으로 서버와 소통하고 Ctrl+B 를 누르면 홈페이지가 열리는데, 이때 토큰 값으로 바로 로그인된 화면이면 좋겠다. **고정으로 등록된 PC 에서만** — 무분별한 진행은 원하지 않는다.」
사용자 결정(12:4x · 기본안 승인): ① 서버가 보는 값은 **공인 IP**(사무실 회선 `106.247.x.x` — 값은 문서에 적지 않는다)뿐이므로 LAN·VPN IP 는 쓰지 않는다 ② 「이 PC 로그인 전용」 토큰 = **DPAPI(그 PC 에서만 풀림) + 발급 때 서버가 본 공인 IP** 두 겹 ③ 1대만 ④ 1회용 30초 링크 ⑤ 토큰 출신 세션은 민감 동작 재인증 필수 · 유휴 10분 ⑥ 감사·경보 ⑦ IP 가 바뀌면 로그인 화면으로 떨어지고 비서가 「이 PC 다시 등록」 안내.
**보안 결정**: 이것은 2단계 인증을 우회하는 통로가 하나 생기는 일이다(F-49 ① 「토큰은 좁은 열쇠」의 예외). SECURITY R-113 에 「사용자 결정 + 완화 ①~⑥」으로 남긴다. 사이트 PRD 10장 Q:F-9 · t-box PRD F-75.

지금 사실(코드로 확인): 세션은 `sessions`(id=쿠키 sha256 · ip · reauth_at · act_*) · 유휴는 `.env SESSION_IDLE_MIN`(30) 하나 · 재인증은 `Authz::REAUTH_POLICIES` 집합 + `SQL_LOOKUP` 의 `reauth_fresh`(15분) · 클라이언트 IP 는 `Http::clientIp()`(REMOTE_ADDR — nginx 가 프록시 XFF 를 복원) · 개인 토큰은 `api_tokens`(scope · expires_at · hint · created_by) · 토큰 갈래 index.php [2-T]/[2-S] · 로그인 화면 `/login`(public). 기능 묶음 = 조각 S(서버 · T-Box_Re) + 조각 B(비서 · 저장소 `aa` = tbox-buddy).

---

## 조각 S — 서버 (T-Box_Re · worktree `/home/user/qaredi/qf9` · 브랜치 wip/qf9 · 기준 b7f6da4 v2.36.0)

```
목표        : 「이 PC 로그인 전용」 개인 토큰(범위 session.open · 발급 IP 에 묶임)으로 30초·1회용 로그인 링크를 받아, 그 링크를 브라우저가 열면 세션이 생긴다. 그 세션은 origin=token 으로 표시되어 민감 동작에 재인증이 필수이고 유휴 10분이다.
요구사항    : Q:F-9 · t-box PRD F-49 ①(예외임을 적는다) · F-59 · F-66 · S-09-2(세션) · F-23(재인증) · ARCHITECTURE 4.0 · 4.2~4.8 · SECURITY R-90·R-91·R-112 — 해당 절을 읽고 시작할 것. 화면은 내 계정 「프로그램 토큰」 발급 폼의 범위 셀렉트 한 줄 + 목록 칸의 「묶인 IP」 표시(DESIGN 기존 요소 · 새 클래스·색값 0).
수정 파일   : public/index.php(라우트 둘 + 토큰 허용 표 — 보안 핵심) · src/Auth.php(링크 교환 → 세션 생성 함수 · 세션 조회에 origin·유휴 — 보안 핵심) · src/Authz.php(TOKEN_SCOPES 다섯째 · tokenScopesFor · tokenScopeAllows · 토큰 출신 세션의 재인증 집합 — 보안 핵심) ·
              src/handlers/me_tokens_issue.php(session.open: 발급 IP 기록 · 같은 사용자의 앞 session.open 토큰 자동 회수) · src/handlers/me_session_link.php(새) · src/handlers/session_open_page.php(새) · src/handlers/me_tokens_list.php·api_tokens_list.php(묶인 IP·종류 표시) ·
              db/migrations/2026-10-12_session_open.sql(api_tokens.bound_ip INET · scope CHECK 다섯 값 · sessions.origin TEXT DEFAULT 'login' · sessions.token_id · session_links 표) · db/schema.sql(같은 값) · db/grants.sql(session_links 에 tbox_app 권한 — 보안 핵심 db/**) ·
              public/assets/app.js(범위 라벨 · 발급 폼 안내 「지금 접속 IP 에 묶임」 · 목록 칸) · scripts/alerts.php(AL-18 「같은 토큰 IP 불일치 3회/10분」) ·
              API.md(20장) · ARCHITECTURE.md(4.9) · PRD.md(F-75 · 8장 라우트 행) · SECURITY.md(R-113 · 7장 한 행 · 6장 앱 밖 위험 한 줄) · docs/qf9-implementation.md · tests/qf9_pc_login.sh · tests/catalog.json.
              ★ src/Http.php · src/Db.php · src/community/** · deploy/** 은 고치지 않는다.
금지 패턴   : SQL 문자열 결합 · innerHTML · 사용자 입력이 들어간 파일 경로 · curl_exec/file_get_contents('http…') · 역할 판단을 Authz 밖에서 · 요청 파라미터의 role · 브라우저 HTML 저장 ·
              **링크 코드·토큰 원문을 저장·로그·감사에 남기기**(해시만) · IP 를 요청 값(헤더·본문)에서 읽기(`Http::clientIp()` 만) · 링크 교환에서 세션을 「일반 로그인과 같은 모양」으로 만들기(origin 표시 없이) · 기존 로그인·세션·재인증 동작의 변화(origin='login' 세션은 한 글자도 안 바뀐다)
완료 기준   : 아래 「설계」 전부 + `tests/qf9_pc_login.sh` 전부 통과 + 회귀(닿는 것: `tests/f66.sh` 정적·발급 절 · `tests/api_whoami.sh` · `tests/qf8_token_write.sh`(범위 표가 바뀌므로) · `tests/sec4.sh` 의 세션·재인증 절 · `tests/passkey_unit.php`) · `scripts/security_lint.sh` 0 · `tests/static/selftest.sh` · `php -l` · `node --check` · 마이그레이션 두 번 멱등
보고 형식   : 변경 파일 목록(파일·줄 수) / 실행한 명령과 출력 원문 / 못 한 것과 이유 / 지시와 다르게 한 것 / 판단 대기 / 남긴 임시 자원 0
```

### 설계 (지키는 것)
1. **범위** `session.open` — `TOKEN_SCOPES` 다섯째 · **독립 값**(파일·커뮤니티 창구 전부 403) · `tokenScopeAllows(session.open, policy)` 는 정책 `session.open`(아래 창구 하나)과 `authenticated`(whoami — 비서가 토큰 확인용)만 참 · `tokenScopesFor`: 2단계가 켜진 계정만(`mfa_enabled`) · Guest 제외.
2. **발급**(`me_tokens_issue.php` 토큰 모드 아님 — 세션·비밀번호·OTP 그대로): scope=session.open 이면 `bound_ip = Http::clientIp()` 를 함께 저장(NULL 이면 400 「접속 IP 를 알 수 없어 발급하지 않습니다」) · **같은 사용자의 살아 있는 session.open 토큰은 전부 회수**(1대 규칙 — 응답에 `revoked_previous: n`) · 응답에 `bound_ip` 를 그대로 돌려준다(사람이 확인) · 감사 `api_token.issue` detail 에 `scope`·`bound_ip`.
3. **링크 발급** `POST /tbox/api/me/session-link`(정책 `session.open` · `TBOX_API_TOKEN_ROUTES` + `TBOX_API_TOKEN_ONLY_ROUTES` + `TBOX_API_TOKEN_PERSONAL_ROUTES` 셋 다) — [2-T] 갈래 그대로(`requireApiToken(true)` → 범위 관문) → 핸들러: `Http::clientIp() === bound_ip` 아니면 **403 `ip_mismatch`** + `Audit::deny('session.link', 'ip_mismatch', …)`(detail 에 token_id · 요청 IP 는 감사 행의 ip 칸에 이미 있음) · 맞으면 `session_links` 에 한 행(`code_hash` sha256 · token_id · user_id · ip · expires_at = now()+30s · used_at NULL) → `201 {"url": APP_URL + "/tbox/session/open?code=<32바이트 base64url>", "expires_in": 30}` · 감사 `session.link allow`. 한 토큰당 **분당 5회** 넘으면 429(기존 속도 제한 도우미가 있으면 재사용).
4. **링크 교환** `GET /tbox/session/open?code=…`(정책 public · 화면 라우트 · CSRF 없음 — GET 이고 부작용은 「내 세션 만들기」뿐) → `Auth::openSessionFromLink(code)`(보안 핵심 · 새 함수): 코드 해시로 행 조회 · 만료·사용됨·토큰 회수/만료·사용자 비활성/절차 중이면 **로그인 화면으로 302**(`/tbox/login?reason=link_expired` 같은 고정 사유 — 코드 원문은 어디에도 남기지 않음) · `Http::clientIp() === session_links.ip` 아니면 같은 302 + 감사 deny · 통과하면 `used_at=now()` (UPDATE … WHERE used_at IS NULL RETURNING — 동시 두 번 중 하나만) → **세션 생성**(기존 로그인 세션 생성 함수를 재사용하되 `origin='token'` · `token_id` · `reauth_at NULL`) → 쿠키 → `302 /tbox/`(`?to=` 가 있으면 기존 return_path 검사 함수로 같은 출처 경로만) · 감사 `session.open allow`(actor = 토큰 주인 · detail `auth=token_session` · token_id).
5. **토큰 출신 세션의 제한** — `Auth` 세션 조회(SQL_LOOKUP)가 `origin` 을 함께 읽고: 유휴 판정에 `origin='token'` 이면 **10분**(상수 `TOKEN_SESSION_IDLE_MIN` — .env 값이 아니라 코드 상수 · 절대 만료는 그대로 8시간) · `Authz::requiresReauth($policy)` 는 세션 origin 을 **인자로** 받아(또는 `Auth::currentUser()` 가 `session_origin` 을 실어 주고 라우터가 넘김) origin=token 이면 **넓은 집합** `TOKEN_SESSION_REAUTH_POLICIES` = 기존 REAUTH_POLICIES ∪ {계정·토큰·패스키·2단계·비밀번호 변경 정책 전부 · `file.delete` · 폴더 삭제 · 공유 링크 만들기 · 커뮤니티 `community.write` 중 **지우기·옮기기·공개 범위·공유·인계** 핸들러(라우트 정책이 하나뿐이면 라우트 이름 집합으로 — 구현자가 `KNOWN_POLICIES`·COMMUNITY_ROUTES 를 읽고 목록을 확정해 보고에 적는다) · `admin.*` 전부} · 재인증 창구(`/api/me/reauth`)는 그대로(비밀번호+OTP) · 재인증 뒤 15분은 기존과 같다.
6. **화면**: 내 계정 → 토큰 발급 범위 셀렉트에 「이 PC 로그인 전용 — 지금 접속한 IP 에서만 · 로그인 없이 화면 열기(민감한 일은 다시 인증)」 · 발급 결과 칸에 「묶인 IP <값> · 앞 토큰 n개 회수됨」 · 목록 표에 종류·묶인 IP · 관리자 토큰 목록(`api_tokens_list`)에도 같은 칸.
7. **경보** AL-18(`scripts/alerts.php`): 같은 token_id 의 `session.link deny ip_mismatch` 가 10분 안 3회 → 경보 한 줄(기존 규칙 모양 그대로 · 중복 억제).
8. **감사 분류**: 토큰 출신 세션의 모든 요청 행에 `Audit::autoDetail` 이 `auth=token_session`·`token_id` 를 붙인다(일반 세션은 지금 그대로 `session`).
9. **문서**: API.md 20장(발급 → 링크 → 교환 · 오류표 · 「왜 GET 인가」 · 「코드는 30초·1회」) · ARCH 4.9(흐름도 · origin 열 · 재인증 집합 둘 · 왜 IP 를 서버가 본 값으로만) · PRD F-75 · SECURITY R-113(위험: 토큰 유출 시 그 IP 에서 2단계 없이 세션 · PC 내부 악성코드 · 회선 공유자(같은 공인 IP 의 폰·다른 PC — DPAPI 가 막는다) / 완화 ①~⑥ · 사용자 결정) · 6장(회선 IP 변경 시 절차) · 7장 행 · 구현 기록.

### 수락 시험 `tests/qf9_pc_login.sh` (임시 인스턴스 8421~8423 · 임시 DB `tbox_qf9_<포트>_test` · 브라우저 0 · 틀은 `tests/qf8_token_write.sh`)
- T-1 발급: claude_employee01 세션 → scope=session.open → 201 · `bound_ip` = 127.0.0.1 · 두 번째 발급 → 앞 것 회수(`revoked_previous=1` · 옛 토큰으로 링크 401) · Guest 400 · 2단계 없는 계정(있으면) 400
- T-2 링크: 토큰 → `POST /api/me/session-link` 201 · url 모양 · 같은 토큰 6번째 → 429 · 다른 범위 토큰(read·community.write) → 403 token_scope · 공용 토큰 403 · 이 토큰으로 파일·커뮤니티 창구 → 403
- T-3 교환: GET url → 302 `/tbox/` + 쿠키 → `GET /api/me` 200(username 같음) · 같은 url 다시 → 302 login(1회용) · 31초 뒤 새 링크 → 302 login(만료) · 감사 `session.open allow` 에 `auth=token_session`
- T-4 IP 불일치: `bound_ip` 를 임시 DB 에서 다른 값으로 바꾼 뒤 링크 요청 → 403 ip_mismatch + 감사 deny · 3회 → alerts.php 드라이런에 AL-18 한 줄 · 링크 발급 뒤 `session_links.ip` 를 바꾸고 교환 → 302 login
- T-5 세션 제한: 토큰 세션으로 `GET /api/files` 200(일반 읽기) · `DELETE /api/files/{id}` → 403 `next: reauth`(일반 로그인 세션은 그대로 되는 것을 **같은 임시 DB 의 일반 세션으로 대조**) · 관리 창구 · 토큰 발급 창구 · 패스키 등록 → reauth · `/api/me/reauth` 뒤 → 200 · 유휴: `last_seen_at` 을 11분 전으로 → 401 · 일반 세션은 11분 전이어도 200(30분)
- T-6 불변: 일반 로그인(비밀번호+OTP) 흐름·세션·재인증이 기준 판 인스턴스와 응답 동일(대표 8 요청 대조) · `origin` 기본값 'login'
- T-7 정적: 코드·토큰 원문 저장 0(`session_links` 에 code 열 없음 — 스키마로) · 로그·감사에 32바이트 코드 모양 0 · IP 는 `Http::clientIp()` 한 곳 · 라우트 둘 · 허용 표 · security_lint 0 · php -l · 마이그레이션 멱등
- 회귀: 위 완료 기준 목록.

---

## 조각 B — 비서 tbox-buddy (저장소 `aa` · `/home/user/aa` · 브랜치 main 위 새 브랜치 `wip/qf9` · Python · Windows 전용 프로그램이라 이 문맥에서는 **순수 로직만** 시험)

```
목표        : Ctrl+B(「t-box 열기」)가 로그인 전용 토큰이 있으면 서버에서 1회용 링크를 받아 그 주소로 브라우저를 열고, 없거나 실패하면 지금처럼 로그인 화면을 연다. 로그인 전용 토큰은 파일 토큰과 **따로** 보관한다.
요구사항    : 조각 S 의 계약(아래) · 이 저장소의 구조(tbox/api.py 한 곳에서만 서버를 부른다 · tbox/creds.py DPAPI · tbox/config.py · tbox/palette.py cmd_open_web · tbox/tray.py 설정 흐름 · tests/ 의 unittest 방식) — 읽고 시작할 것.
수정 파일   : tbox/api.py(창구 하나 `session_link()`) · tbox/config.py(`login_token` — DPAPI · 파일 토큰과 다른 키) · tbox/palette.py(cmd_open_web 갈래) · tbox/tray.py(트레이 메뉴 「이 PC 로그인 전용 토큰 넣기/지우기」 — 기존 토큰 입력 흐름과 같은 모양 · 실패 시 알림 한 줄 「이 PC 를 다시 등록하세요 — 내 계정 → 프로그램 토큰」) · docs/SERVER_API.md(절 하나) · README 또는 docs 의 사용 절 · tests/test_session_link.py(새).
금지 패턴   : 토큰·링크 코드를 로그·알림·클립보드에 찍기(로그는 기존 마스킹) · 링크를 파일에 쓰기 · 링크 재사용(한 번 열면 버린다) · 로그인 전용 토큰으로 파일 창구 부르기 · 서버 코드 변경.
완료 기준   : tests 전부 통과(`python3 -m unittest discover -s tests` — 새 시험은 가짜 서버로 201/401/403/429/연결 실패 갈래 · 토큰 없음 갈래 · 열리는 url 이 서버가 준 것 그대로인지 · 실패 시 로그인 화면 url) · 기존 시험 통과 · 화면·트레이·DPAPI 는 **미검증(Windows 아님)** 으로 보고.
보고 형식   : 조각 S 와 같다.
```

### 계약 (조각 S 창구 · 조각 B 는 이것만 부른다)
- `POST {base}/api/me/session-link` · 헤더 `X-API-Token: <로그인 전용 토큰>` · 본문 없음 → `201 {"url": "https://…/tbox/session/open?code=…", "expires_in": 30}` · 실패: 401(없음·틀림·만료·회수) · 403 `token_scope`(범위 다름) · 403 `ip_mismatch`(IP 다름 → 「이 PC 다시 등록」) · 429(분당 5회) · 503(계정 절차 중)
- 비서는 url 을 **그대로** `webbrowser.open()` 하고 버린다. 30초 안에 열리지 않으면 로그인 화면이 뜬다(서버가 302).
- `GET {base}/api/whoami` 로 토큰 확인(있는 창구).

---

## 공통
- 커밋: 파일 지정 · 한글 · S 는 `Qaredi Q:F-9 S: …`(T-Box_Re) · B 는 `tbox-buddy Q:F-9 B: …`(aa) · 꼬리 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push 는 메인이.
- 환경(이 문맥): PostgreSQL 16 로컬 `tbox_re`(운영 사본 노릇 — 읽기·pg_dump 만) · `/etc/tbox-v2/seed-accounts.txt`·`qa-totp.txt`(root · sudo -n 그대로) · pgpass `~/.config/qaredi/pgpass-testdb` · `/var/www/qaredi` 링크 · 운영 .env 자리 `/var/www/qaredi/tbox-v2/.env`. 임시 DB 접두 `tbox_qf9_`. 끝나면 이름으로 지운다. 하위 에이전트 0 · 다른 worktree 불변 · 비밀 출력 0 · 기대값 약화 0 · 사람 판단 갈림길은 좁은 쪽 기본안 + 「판단 대기」.
