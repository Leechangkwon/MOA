# Q:F-10 — 토큰 하나에 범위 여럿 · 커뮤니티 검색·읽기를 토큰에 열기 · 비서에서 커뮤니티 검색 (지시서, 2026-09-29)

사용자 결정(2026-09-29 오후): ① 토큰 발급 때 범위를 **여러 개 골라** 한 토큰에 묶는다(PC 에 토큰 하나면 되게) ② 커뮤니티 **검색·읽기** 창구를 토큰에 연다 ③ 비서(tbox-buddy)에서 커뮤니티 글을 검색해 바로 연다 ④ 메신저는 별도 기획(`docs/messenger-plan.md`) — 이 묶음에 넣지 않는다.
사용자 지적: 「토큰이 너무 많이 필요해지는 거 아니야?」 — 지금 PC 에 셋(비서 파일용 · 비서 Ctrl+B 용 · MCP 글쓰기용).

지금 사실(코드로 확인 · b2ee066): `api_tokens.scope` TEXT 한 값 — CHECK 다섯 값(`read` · `read+upload` · `read+upload+delete` · `community.write` · `session.open`) · `bound_ip` 는 `session.open` 일 때만 · 살아 있는 `session.open` 토큰은 사람당 하나(부분 유일 인덱스 `api_tokens_one_session_open`) · 판정은 `Authz::tokenScopeAllows(범위, 정책)` 한 곳 · 고를 수 있는 목록은 `Authz::tokenScopesFor($u)` → `/api/me` 의 `token_scopes` → 발급 폼 `<select>` · 커뮤니티 토큰 창구 셋(`COMMUNITY_API_TOKEN_ROUTES`) · 검색 창구 `GET /community/api/search?q=`(정책 community.read · 응답 `{results:[{id,title,icon,space,path,snippet,in_title}], comments:[…], q}`) · `Http::returnPath()` 허용 목록(`/tbox/session/open?to=` 가 쓴다). 비서 `aa`: 토큰 칸 둘(`api_token` · `login_token`, DPAPI) · `api.py` 한 곳 · 팔레트 `Cmd` 표 · `weblaunch.py`.

기능 묶음 = 조각 S(서버 · T-Box_Re) + 조각 B(비서 · `aa`) + 조각 C(MCP · `clients/mcp`). 검토·회귀·반영은 묶음 끝에 한 번(CLAUDE.md 3.1). 조각 S 의 판정 함수·스키마는 **보안 바닥**이라 먼저 검토만 받는다.

---

## 조각 S — 서버 (T-Box_Re · 작업 트리 `/home/user/qaredi/qf10` · 브랜치 `wip/qf10`)

```
목표        : 개인 토큰의 범위를 「원자 범위의 집합」으로 바꿔 한 토큰에 여러 범위를 묶고, 커뮤니티 검색·읽기 창구를 토큰에 연다.
요구사항    : T:F-66(범위·만료) · T:F-74(커뮤니티 창구 셋) · T:F-75(로그인 전용) · Q:F-8 · Q:F-9 · ARCHITECTURE 4.7 · 4.8 · 4.9 · 6.2-5 · 13장 — 읽고 시작
              화면: DESIGN.md 3절(폼) + 6장 체크리스트. 새 클래스·색값을 만들지 않는다.
수정 파일   : src/Authz.php(보안 핵심 — 범위 원자 표·정규화·판정) · src/Auth.php(보안 핵심 — 토큰 조회에 bound_ip 판정을 범위 집합 기준으로 · SQL_LOOKUP 의 session.open 판정) ·
              src/handlers/me_tokens_issue.php · src/handlers/me_tokens_list.php · src/handlers/api_tokens_list.php · src/handlers/me.php(token_scope 응답 한 칸) ·
              src/community/routes.php(COMMUNITY_API_TOKEN_ROUTES 에 셋 추가) · src/community/handlers/search.php(토큰 갈래에서도 같은 코드 — 바뀔 게 없으면 손대지 않는다) ·
              db/schema.sql · db/migrations/2026-10-13_api_tokens_multi_scope.sql(새) · db/grants.sql(필요할 때만) ·
              public/assets/app.js(발급 폼 <select> → 체크박스 묶음 · 목록 칸 · 라벨) · public/index.php(고칠 게 없어야 정상 — TBOX_API_TOKEN_ROUTES 불변) ·
              scripts/alerts.php(AL-24 의 session.open 판정식을 집합 기준으로) ·
              tests/qf10_multi_scope.sh(새) · tests/catalog.json · API.md(20장 갱신 + 21장 새) · ARCHITECTURE.md(4.10 새) · PRD.md(F-76 새 행) · SECURITY.md(R-114 새 · R-112·R-113 갱신) · wiki/qaredi/guide-roadmap.json(Q:F-10 행)
금지 패턴   : CLAUDE.md 2장 그대로. 역할 문자열 비교로 범위를 정하지 않는다(tokenScopesFor 는 지금처럼 판정 함수 결과로). 요청의 scope 값을 정규화 없이 저장하지 않는다.
완료 기준   : 아래 T-1~T-8 전부 + tests/api_tokens.sh · tests/qf8_token_write.sh · tests/qf9_pc_login.sh 회귀 그대로 통과(옛 값 다섯이 그대로 유효해야 한다) + scripts/security_lint.sh 0건 + php -l 전부 + node --check app.js + php scripts/wiki_check.php 0건
보고 형식   : 변경 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유 / 확정한 원자 표와 정규화 규칙 원문
```

### S-1 범위 모델 — 원자의 집합, 문자열 하나에 `+` 로 이어 저장

| 원자 | 뜻 | 조건 |
|---|---|---|
| `read` | 파일 읽기·목록·내려받기 | 파일 원자의 바닥. `upload`·`delete` 는 `read` 없이 못 붙는다 |
| `upload` | 파일 올리기·이름 바꾸기·옮기기(`file.upload`·`file.update`) | `read` 필요 |
| `delete` | 파일 지우기(`file.delete`) | `read`+`upload` 필요 |
| `community.read` | 커뮤니티 검색·읽기(`community.read` 정책의 토큰 창구) | 독립. **새 원자** |
| `community.write` | 커뮤니티 쓰기(`community.write` 정책) + 읽기 포함 | 독립. 붙으면 `community.read` 는 정규화가 **덧붙인다**(저장 문자열에 둘 다 — 판정이 단순해진다) |
| `session.open` | 1회용 로그인 링크(`session.open` 정책) | 독립. 붙으면 `bound_ip` 필수 · 사람당 살아 있는 것 하나(지금 규칙 그대로) · **그 토큰의 모든 창구가 묶인 IP 밖에서는 401**(아래 S-3) |

- **정규화 `Authz::normalizeScope(array|string): string`** — 원자 집합을 받아 위 표의 순서로 정렬해 `+` 로 잇는다. 모르는 원자 → 예외(발급 창구는 400 `scope_invalid`). 의존 조건 위반(`upload` 만 · `delete` 만) → 400. `community.write` 가 있으면 `community.read` 를 덧붙인다. 빈 집합 → 400.
- 옛 다섯 값은 정규화 결과 그대로다: `read` · `read+upload` · `read+upload+delete` · `session.open`. **예외 `community.write`** — 옛 행은 `community.write` 한 원자로 저장돼 있으니 마이그레이션이 `community.read+community.write` 로 바꾼다(아래 S-2). 코드는 옛 문자열 그대로 와도 통하게(`community.write` 만 있어도 read 로 판정) — 이중 안전.
- **판정 `Authz::tokenScopeAllows(string $scope, string $policy): bool`** — 서명 불변. 안에서 `explode('+')` → 집합 → 정책별:
  `authenticated` → 집합이 비어 있지 않고 전부 아는 원자 · `file.read` → `read` · `file.upload`/`file.update` → `upload` · `file.delete` → `delete` · `community.read` → `community.read` 또는 `community.write` · `community.write` → `community.write` · `session.open` → `session.open` · default false.
- **고를 수 있는 목록 `Authz::tokenScopesFor($u)`** — 이제 **원자** 목록을 돌려준다(역할이 아니라 판정 함수 결과로): `read` 항상 · `upload` = uploadableLevels ≠ [] · `delete` = 같은 조건(지금 사다리 규칙 그대로 — delete 를 고를 수 있는 조건이 upload 와 다르면 지금 코드의 판정을 그대로 옮긴다) · `community.read` = canCommunityRead 가 있으면 그것, 없으면 canCommunityWrite 와 같은 판정(Guest 0 — 판정 함수 이름을 보고에 적는다) · `community.write` = canCommunityWrite · `session.open` = canOpenSessionByToken. `/api/me` 의 `token_scopes` 키 이름은 그대로(값이 원자 배열이 된다 — app.js 가 같이 바뀐다).
- `FILE_TOKEN_SCOPES`·`TOKEN_SCOPES` 상수는 쓰는 곳을 전부 원자 표 `TOKEN_SCOPE_ATOMS`(순서 = 정규화 순서)로 옮기고 지운다. 남기면 「두 진실」이다.

### S-2 DB — `db/migrations/2026-10-13_api_tokens_multi_scope.sql` + `db/schema.sql` 같은 모양

```sql
ALTER TABLE api_tokens DROP CONSTRAINT api_tokens_scope_check;
ALTER TABLE api_tokens ADD CONSTRAINT api_tokens_scope_check
  CHECK (scope <> '' AND string_to_array(scope, '+') <@ ARRAY['read','upload','delete','community.read','community.write','session.open']::text[]);
ALTER TABLE api_tokens DROP CONSTRAINT api_tokens_bound_ip_check;
ALTER TABLE api_tokens ADD CONSTRAINT api_tokens_bound_ip_check
  CHECK (('session.open' = ANY (string_to_array(scope, '+'))) = (bound_ip IS NOT NULL));
DROP INDEX api_tokens_one_session_open;
CREATE UNIQUE INDEX api_tokens_one_session_open ON api_tokens (user_id)
  WHERE 'session.open' = ANY (string_to_array(scope, '+')) AND revoked_at IS NULL;
UPDATE api_tokens SET scope = 'community.read+community.write' WHERE scope = 'community.write';
```
- 마이그레이션은 트랜잭션 하나 · 멱등(두 번 돌려도 같은 모양 — `IF EXISTS`·`WHERE scope = …`) · 앞에 `SELECT count(*) … scope NOT IN (…)` 로 모르는 값이 있으면 `RAISE EXCEPTION`.
- 순서·의존은 앱이 본다(CHECK 는 원자 집합 포함 여부만). 이유를 schema.sql 주석에 적는다(ARCH 6.2-5 의 원칙 「DB 는 마지막 그물」).
- `db/grants.sql` 은 바뀔 게 없어야 정상(열 추가 0).

### S-3 로그인 전용 원자가 섞인 토큰의 IP 묶음 — `Auth::requireApiToken`

- `SQL_FIND_API_TOKEN` 이 `host(bound_ip) AS bound_ip` 를 함께 읽고, `bound_ip` 가 NULL 이 아니면 `Http::clientIp() === bound_ip` 가 아닐 때 **401 `token_ip_mismatch`** + `Audit::deny('auth.token', 'ip_mismatch', …)`(detail 에 token_id · 요청 IP 는 감사 행 ip 칸). 지금은 링크 창구 핸들러(`me_session_link.php`)만 보던 것을 **토큰 조회 한 곳**으로 올린다 — 링크 창구의 검사는 남겨도 되지만 같은 함수를 부르게(중복 판정 코드 0). ARCH 4.9 의 「IP 가 바뀌면 로그인 화면」 규칙이 그 토큰의 파일·커뮤니티 창구에도 똑같이 적용된다(사용자 결정 「고정 PC 에서만」의 자연스러운 확장 — **보고에 명시**).
- `Auth::SQL_LOOKUP`(토큰 출신 세션의 생존 판정)의 `t.scope = 'session.open'` → `'session.open' = ANY (string_to_array(t.scope, '+'))`. `session_links` 교환 SQL(`token_ok`)도 같게. `scripts/alerts.php` AL-24 도 같게.
- 토큰 출신 세션의 제한(유휴 10분 · 재인증 집합)은 **불변**.

### S-4 발급·목록·화면

- `POST /api/me/tokens` 본문 `scope` 는 **문자열 배열**(예: `["read","upload","community.read","session.open"]`) — 옛 문자열도 받되(`"read+upload"` → explode) 응답의 `scope` 는 정규화된 문자열. `scope ⊆ tokenScopesFor($user)` 아니면 403 `token_scope`(지금 코드). 살아 있는 토큰 상한 5 규칙: 지금 `n_other`/`n` 나눔을 「session.open 포함 여부」로.
- 발급 응답·목록(`me_tokens_list` · `api_tokens_list`)의 `scope` 는 정규화 문자열 그대로 + **`scopes` 배열** 한 칸 추가(화면·비서가 쪼개지 않게).
- `GET /api/me` 가 토큰으로 불렸을 때(`TBOX_API_TOKEN_SCOPE` 정의됨) 응답에 `token_scope`(문자열)·`token_scopes_held`(배열) 두 칸 — 비서가 「이 토큰으로 Ctrl+B 가 되나」를 판단한다. 세션으로 불렸을 때는 그 칸이 없다(지금 `token_scopes` = 고를 수 있는 목록과 이름이 다르니 헷갈리지 않게 API.md 에 표로).
- `app.js` 발급 폼: `<select>` → 원자마다 체크박스(라벨 표 `TOKEN_SCOPE_LABELS` 를 원자 기준으로 다시 씀 · `upload` 체크 시 `read` 자동 · `delete` 체크 시 `read`·`upload` 자동 · `community.write` 체크 시 `community.read` 자동 · `session.open` 체크 시 지금의 「지금 접속 IP 에 묶임」 안내 + **「이 토큰의 다른 범위도 그 IP 에서만 통한다」** 문장). 목록 칸은 원자 라벨을 「·」 로 이어 보인다. 발급 결과 화면의 `bound_ip` 표시는 지금대로.
- 관리자 토큰 목록 화면도 같은 라벨 함수.

### S-5 커뮤니티 창구 — `COMMUNITY_API_TOKEN_ROUTES` 에 셋 추가

```
'GET /api/search',          // community.read — 검색
'GET /api/pages',           // community.read — 한 공간의 목록(?space=)
'GET /api/spaces',          // community.read — 내가 볼 수 있는 공간
```
- 정책은 라우트 표의 값 그대로(`community.read`). 핸들러는 손대지 않는다 — 행 관문(SQL 조건절)이 세션과 같은 코드다. `search.php` 가 세션 전용 전제(예: `$_SESSION`·CSRF·`Auth::currentUser()` 직접 호출)를 갖고 있으면 그 부분만 라우터가 넘긴 `$user` 를 쓰게 고치고 보고에 적는다.
- `Http::returnPath()` 허용 목록이 `/community/…` 를 통과시키는지 확인 — 안 되면 **허용 목록에 `/community/` 접두 하나**만 추가(Http.php 는 보안 핵심 — 이 한 줄만 · 보고에 적는다). `/tbox/session/open?to=/community/p/<id>` 가 비서의 「검색 결과 열기」 경로다.

### S-6 시험 `tests/qf10_multi_scope.sh` (임시 인스턴스 · `claude_` 계정 · `-A "tbox-review/$(date +%F)"`)

- T-1 정규화: 발급 `["upload"]` → 400 · `["delete","read"]` → 400 · `["upload","read"]` → 201 `scope=read+upload` · `["community.write"]` → 201 `scope=community.read+community.write` · `["x"]` → 400 · `[]` → 400 · 옛 문자열 `"read+upload+delete"` → 201.
- T-2 묶음 토큰: `["read","upload","community.read","session.open"]` → 201 · `bound_ip` 있음 · `GET /api/files` 200 · `POST /community/api/pages` 403 `token_scope` · `GET /community/api/search?q=…` 200 · `POST /api/me/session-link` 201 · `DELETE /api/files/{id}` 403.
- T-3 IP 묶음 확장: T-2 토큰을 `X-Forwarded-For` 가 아니라 **다른 소스 IP**로(임시 인스턴스는 127.0.0.1 로만 오니 DB 에서 `bound_ip` 를 `10.9.9.9` 로 바꿔 흉내) → `GET /api/files` 401 `token_ip_mismatch` · 감사 `auth.token deny ip_mismatch` 1행 · `bound_ip` 없는 토큰은 영향 0.
- T-4 하나 규칙: session.open 포함 토큰이 살아 있는데 또 하나(다른 원자 섞어서) → 409(지금 코드의 오류 코드 그대로) · 회수 뒤 → 201.
- T-5 옛 값 회귀: DB 에 옛 다섯 값을 직접 INSERT(마이그레이션 전 모양) → 마이그레이션 실행 → `community.write` 행만 바뀜 · 다른 행 그대로 · 두 번 실행 멱등 · 모르는 값이 있으면 실패.
- T-6 커뮤니티 읽기 토큰: `["community.read"]` → 검색 200 · 목록 200 · 공간 200 · `GET /community/api/pages/{id}` 200(내 것) · 남의 비공개 페이지 404/403(세션과 같은 답 — 세션으로 같은 요청을 대조) · `POST /community/api/pages` 403 · 파일 창구 403.
- T-7 `/api/me` 토큰 갈래: `token_scope`·`token_scopes_held` 있음 · 세션 갈래: 없음 · `token_scopes` 는 원자 배열.
- T-8 회귀: `tests/api_tokens.sh` · `tests/qf8_token_write.sh` · `tests/qf9_pc_login.sh` 그대로 통과(발급 본문이 문자열이던 시험은 그대로 두고 통과해야 한다 — 옛 문자열 호환).

### S-7 문서

- API.md 20장(발급 본문·응답 `scopes` · `/api/me` 두 칸 · 401 `token_ip_mismatch`) + **21장 「Q:F-10 범위 묶음·커뮤니티 읽기 창구」**(원자 표 · 정규화 규칙 · 창구 → 필요한 원자 표 · 비서·MCP 가 쓰는 순서).
- ARCHITECTURE 4.10(원자 모델 · 왜 TEXT 한 열을 유지했나 — 마이그레이션 최소·조회 경로 불변 · CHECK 는 포함 여부만) · 4.7~4.9 의 「범위 다섯 값」 문장을 4.10 을 가리키게.
- PRD F-76 행. SECURITY R-114(「범위 묶음으로 토큰 하나의 피해 반경이 커진다 — 완화: 사용자가 고른 만큼만 · session.open 포함 시 IP 묶음이 전부에 적용 · 만료 180일 · 회수 한 번에 전부」) · R-112·R-113 갱신 · 7장 이력 행. wiki 로드맵 행.
```

---

## 조각 B — 비서 tbox-buddy (저장소 `aa` · 브랜치 `wip/qf10` · 작업 트리 `/home/user/aa`)

```
목표        : 토큰 하나로 파일·Ctrl+B·커뮤니티 검색이 되게 하고, 팔레트에 「커뮤니티 검색」을 넣는다.
요구사항    : 위 조각 S 의 API 계약(API.md 21장이 되기 전이라 이 지시서 S-4·S-5 가 계약) · aa 의 README·CLAUDE.md 가 있으면 읽고 시작
수정 파일   : tbox/api.py(HTTP 한 곳 — whoami 응답 두 칸 · search_pages() · session_link 가 쓸 토큰 고르기) · tbox/config.py(login_token 칸 유지 — 비어 있으면 api_token 이 session.open 을 가질 때 그것으로) ·
              tbox/tray.py(「API 토큰 변경」 뒤 whoami 로 범위를 읽어 상태 문구에 「파일 · PC 로그인 · 커뮤니티」 표시) · tbox/palette.py(Cmd 「Ctrl+F 커뮤니티 검색」 · 결과 목록 · Enter 로 열기) ·
              tbox/weblaunch.py(to= 를 붙여 열기) · README.md · tests/(있으면 같은 틀로 단위 시험)
금지 패턴   : 토큰 평문 로그 0(지금 가림막 그대로) · HTTP 는 api.py 밖에서 0 · 서버 응답 HTML 을 그대로 쓰기 0 · 새 외부 패키지 0
완료 기준   : B-1~B-4 + 기존 시험 통과 + python -m py_compile 전부
보고 형식   : 2장 형식
```

- B-1 **토큰 고르기**: `session_link()` 는 `login_token` 이 있으면 그것, 없으면 `api_token` 을 쓴다(서버가 범위 밖이면 403 `token_scope` → 문구 「이 토큰에는 「이 PC 로그인」 범위가 없습니다 — 토큰을 다시 발급하거나 로그인 전용 토큰을 넣으세요」). 「API 토큰 변경」 뒤 `GET /api/me` 의 `token_scopes_held` 를 읽어 메모리에만 두고 상태 문구에 보인다(저장 0 — 다음 시작 때 다시 읽는다).
- B-2 **커뮤니티 검색**: 팔레트 `Ctrl+F` → 입력 → `GET /community/api/search?q=` (헤더 `X-API-Token`) → `results` 를 제목 · 공간 이름 · snippet 으로 목록 → Enter → 열기. 열기는 **세션이 있으면** `/community/p/<id>` 직접, 아니면 `session_link()` 로 링크를 받아 `?to=/community/p/<id>` 를 붙여 브라우저로(Ctrl+B 와 같은 경로 · 링크 응답의 `url` 에 `&to=` 를 이어 붙인다 — `to` 값은 URL 인코딩). 범위 없음 403 → 문구 「이 토큰에는 커뮤니티 읽기 범위가 없습니다」.
- B-3 파일 창구 403 `token_scope` 문구를 「이 토큰의 범위에 없는 창구입니다 — 내 계정 → 프로그램 토큰에서 범위를 묶어 다시 발급하세요」로(지금 문구가 범위 사다리 시절 것).
- B-4 README 에 「토큰 하나로」 절 — 발급 때 고를 체크박스 셋과 트레이 순서.

---

## 조각 C — MCP `clients/mcp` (T-Box_Re · 같은 작업 트리 · 조각 S 와 같은 브랜치)

```
목표        : 검색 도구 하나를 더한다. 토큰 모델 변화는 MCP 에 영향이 없어야 한다(확인만).
수정 파일   : clients/mcp/qaredi_mcp/server.py · client.py · README.md · tests/
```
- C-1 `qaredi_search_pages(q: str, limit: int = 10) -> str` — `GET /community/api/search?q=` → 결과를 「제목 — 공간 — snippet — id」 줄로. 범위 없음 403 은 문구 그대로 돌려준다(예외 아님).
- C-2 `qaredi_whoami` 가 `token_scopes_held` 가 있으면 함께 보여 준다.
- C-3 기존 시험 그대로 통과 + 새 도구 단위 시험(가짜 서버 응답).

---

## 반영 순서(묶음 끝)

S 검토(바닥 먼저: Authz 정규화·판정 · 마이그레이션) → B·C 검토 → 회귀 한 번 → `review-scripts/apply-qf10-db-*.sh`(마이그레이션 · 백업 · dry-run) → `apply-qf10-repo-*.sh` → 사용자가 서버에서 실행 → `release.sh minor "토큰 하나로 · 비서에서 커뮤니티 검색" …` → PC: `git pull` · 토큰 한 개 새로 발급(범위 셋 체크) · 「API 토큰 변경」 에 넣기 · 옛 토큰 셋 회수.
