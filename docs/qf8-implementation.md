# Q:F-8 AI 글쓰기 — 구현 기록

지시서 `docs/qf8-ai-writer-directive.md`. 기능 묶음 하나 = 조각 둘(S 서버 · C 클라이언트) — 검토·회귀·반영은 묶음 끝에 한 번(지침 3.1).
이 파일은 조각마다 한 절씩 쓴다(조각 C 는 C 작업자가 아래에 더한다).

---

## 조각 S — 커뮤니티 창구 셋에 개인 토큰 열기 (서버) · 2026-09-28 · 구현자 Opus(메인 Fable 이 맡김) · 검토 전

### 1. 한 줄 요약

개인 프로그램 토큰에 넷째 범위 **`community.write`**(독립 값)를 더하고, 커뮤니티 라우터 `community_dispatch()` 에 토큰 관문을 **한 곳** 두어
`GET /community/api/me/space` · `POST /community/api/pages` · `GET /community/api/pages/{id}` **셋만** 토큰으로 열었다.
토큰으로 만든 페이지는 `title`·`doc` 만 받아 언제나 **내 개인 공간 · 비공개 · 부모 없음**이다. 그 밖의 커뮤니티 창구는 헤더를 읽지 않는다(세션 없음 401).
`public/index.php` · `src/Auth.php` · `src/Http.php` · `src/Db.php` · `src/community/CommunityAuthz.php` · `db/grants.sql` · `deploy/**` 차이 **0**(수락 시험 T-7 이 git diff 로 확인).

### 2. 변경 파일

| 파일 | 줄 | 무엇 |
|---|---|---|
| `src/community/routes.php` | +59 −4 | 머리 주석 · 상수 `COMMUNITY_API_TOKEN_ROUTES`(셋) · `community_dispatch` [2-T] 갈래(표 + 헤더 → `Auth::requireApiToken(true)` → `TBOX_AUTH_METHOD` → `Authz::tokenScopeAllows` 403·감사) · 세션 경로는 `else` 로 감쌌을 뿐 줄 내용 그대로(index.php 와 같이 들여쓰기 유지) · 재인증에 `!$viaApiToken` |
| `src/Authz.php` | +30 −6 | `FILE_TOKEN_SCOPES`(사다리 셋) 새 상수 · `TOKEN_SCOPES` 넷 · `tokenScopesFor` 가 `canCommunityWrite` 이면 `community.write` 를 끝에 · `tokenScopeAllows`: `authenticated` 넷 다 참 · `file.read` 는 파일 셋만 · `community.read`·`community.write` 는 `community.write` 만 |
| `src/community/handlers/pages_create.php` | +18 −1 | [1-1] 토큰 모드(`TBOX_AUTH_METHOD === 'api_token'`)에서 `parent_id`·`space_id`·`database`·`properties`·`template_id` 키가 **있으면** 400 `{"error":…,"reason":"token_scope_fields"}` · 머리 주석 |
| `src/handlers/me_tokens_issue.php` | +3 −1 | 머리 주석만(범위 목록 설명) — 판정은 이미 `tokenScopesFor` 를 부른다 |
| `db/migrations/2026-10-11_api_tokens_scope_community.sql` | 새 파일 49 | `api_tokens_scope_check` DROP IF EXISTS → 네 값으로 ADD(한 트랜잭션 · 멱등 · 행 변경 0 · 되돌리기 절 주석 · 배포 순서 「마이그레이션 → 코드」) |
| `public/assets/app.js` | +4 −2 | `TOKEN_SCOPE_LABELS` 에 `community.write` 라벨 한 줄(선택지는 서버 `token_scopes` 그대로 · 새 클래스·색 0) |
| `tests/qf8_token_write.sh` | 새 파일 532 | 수락 시험 T-1~T-7 + `REGRESS=1` 회귀 틀 |
| `tests/catalog.json` | +43 | 항목 하나 `core.qf8_token_write` |
| `API.md` | +51 −3 | 19장 새로(표 · curl · 실제 응답) · 0장 인증·범위 두 줄 · 13장 범위 줄 |
| `ARCHITECTURE.md` | +42 −1 | 4.8 새로(관문이 둘인 까닭 · 판정 순서 표 · 범위 × 정책 표 · 열지 않는 것) · 6.2-5 스키마 조각 |
| `PRD.md` | +2 | F-74 행 · 8장 API 표 한 행 |
| `SECURITY.md` | +2 | R-112 · 7장 이력 한 행 |
| `docs/qf8-implementation.md` | 새 파일 | 이 기록 |

`src/views/*.php` 는 고치지 않았다 — 범위 설명 글자가 서버 뷰에 없다(select 옵션은 `app.js` 가 `/api/me` 의 `token_scopes` 로 채운다 · `settings_page.php` 주석 그대로 맞다).

### 3. 설계 1~7 대응

| 설계 | 한 일 | 증거(수락 시험 항목) |
|---|---|---|
| 1 범위 | `TOKEN_SCOPES` 넷 · `tokenScopesFor`(Guest 0) · `tokenScopeAllows` 표 · 마이그레이션 CHECK | T-7 「tokenScopeAllows 표」(9 정책 × 4 범위) · 준비 「두 번 적용 뒤 CHECK 하나 · 네 값」 · T-1 token_scopes |
| 2 관문 자리 | `community_dispatch` 라우트 매칭 뒤 · 세션 조회 앞 · 표 상수 하나 · 헤더 있을 때만 | T-7 정적 넷 · T-4 틀린 값+세션 쿠키 401 · T-5 표 밖 401 · 감사 `auth=api_token` 행 0 |
| 3 토큰 모드 POST | 다섯 키 → 400 `token_scope_fields` · 나머지 검증 그대로 | T-4 다섯(null) + space_id 값 · visibility 400 · T-2 personal·private·parent null |
| 4 me/space · pages/{id} | 핸들러 무변경 — 세션과 같은 코드 | T-3 같은 공간 번호 · 남의 비공개 글 = 세션 답(403) · 없는 id 404 |
| 5 감사 | 새 문장 0 — `Audit::autoDetail` 자동 · 거부는 index.php [2-S] 와 같은 `auth.token deny scope_not_permitted` | T-2 `page.create allow` detail 다섯 키 · T-4 거부 두 행 |
| 6 발급 화면 | `app.js` 라벨 한 줄 | T-1 발급 201 · Guest 400 · 모르는 값 400 · 사다리 끼우기 400 (화면 자체는 미검증 — 아래 5절) |
| 7 문서 | API 19 · ARCH 4.8 · PRD F-74 · SECURITY R-112 · 7장 · 이 기록 | — |

### 4. 실행한 명령과 출력 원문

정적(각각 저장소 `/home/user/qaredi/qf8` 에서):

```text
$ for f in src/Authz.php src/community/routes.php src/community/handlers/pages_create.php src/handlers/me_tokens_issue.php; do php -l $f; done
No syntax errors detected in src/Authz.php
No syntax errors detected in src/community/routes.php
No syntax errors detected in src/community/handlers/pages_create.php
No syntax errors detected in src/handlers/me_tokens_issue.php
$ node --check public/assets/app.js; echo "node rc=$?"
node rc=0
$ bash scripts/security_lint.sh . | tail -1
결과: 통과 (금지 패턴 0건)
$ bash tests/static/selftest.sh | tail -2
합계: 규칙 22 · 견본 215 · 항목 238 (py_compile 22 포함) · 맞음 238 · 틀림 0
RESULT pass=238 fail=0 skip=0
$ python3 tests/static/r15_secret_args.py --walk | grep -c qf8     → 0   (새 시험 파일의 비밀 인자 0 — 처음 판의 7건을 고친 뒤)
$ python3 tests/static/r23_vacuous_assert.py --walk | grep -c qf8  → 0   (빈 값 같음 비교 0 — 처음 판의 1건을 고친 뒤)
```

수락 + 회귀(한 번에) — `REGRESS=1 bash tests/qf8_token_write.sh` 의 출력 원문 전부(토큰·비밀번호·코드 값은 출력하지 않는 시험이다):

```text

════════════════════════════════════════════════════════════
T-7 정적 (저장소 — 기준 판 3ebee61 대비)
════════════════════════════════════════════════════════════
  ✔ 허용 표는 상수 하나(COMMUNITY_API_TOKEN_ROUTES) · 항목 셋  (기대=1 3 실제=1 3)
  ✔ 표의 셋 = GET /api/me/space · POST /api/pages · GET /api/pages/{id}  (기대=GET /api/me/space|POST /api/pages|GET /api/pages/{id} 실제=GET /api/me/space|POST /api/pages|GET /api/pages/{id})
  ✔ X-API-Token 을 읽는 곳 — routes.php 에 한 곳(Http::apiTokenHeader) · 커뮤니티 폴더의 다른 파일 0 · $_SERVER 직접 0  (기대=1 0 0 실제=1 0 0)
  ✔ 토큰 갈래의 판정 함수는 기존 셋 그대로(requireApiToken(true) · tokenScopeAllows · MSG_TOKEN_SCOPE)  (기대=1 1 1 실제=1 1 1)
  ✔ 고치지 않는 파일 차이 0 (index.php · Auth · Http · Db · CommunityAuthz · grants · deploy — 조각 C 의 DEPENDENCIES.md 제외)  (기대=0 실제=0)
  ✔ 바뀐 PHP php -l 실패 0  (기대=0 실제=0)
  ✔ app.js node --check  (기대=0 실제=0)
  ✔ 보안 린트 통과(금지 패턴 0건)  (기대=1 실제=1)
  ✔ 더한 JS 줄에 문자열 → HTML 자리 0  (기대=0 실제=0)
  ✔ tokenScopeAllows 표(read · read+upload · read+upload+delete · community.write)  (기대=authenticated=1111 file.read=1110 file.upload=0110 file.update=0110 file.delete=0010 community.read=0001 community.write=0001 role:Admin=0000 file.fetch=0000 SCOPES=read,read+upload,read+upload+delete,community.write 실제=authenticated=1111 file.read=1110 file.upload=0110 file.update=0110 file.delete=0010 community.read=0001 community.write=0001 role:Admin=0000 file.fetch=0000 SCOPES=read,read+upload,read+upload+delete,community.write)
  ✔ 마이그레이션 — 제약 하나를 지우고 다시(네 값) · UPDATE·DELETE·TRUNCATE 0 · 되돌리기 절  (기대=1 1 0 1 실제=1 1 0 1)

════════════════════════════════════════════════════════════
준비 — 임시 DB · 마이그레이션 두 번 · 임시 인스턴스 둘 (운영 사본에는 쓰지 않는다)
════════════════════════════════════════════════════════════
  · 임시 DB tbox_qf8_8401_test — 사용자 5 · 페이지 6
  · 적용 전 CHECK: 1 CHECK ((scope = ANY (ARRAY['read'::text, 'read+upload'::text, 'read+upload+delete'::text])))
  ✔ 마이그레이션 첫 적용 rc  (기대=rc=0 실제=rc=0)
  ✔ 마이그레이션 둘째 적용 rc(멱등)  (기대=rc=0 실제=rc=0)
  ✔ 두 번 적용 뒤 scope CHECK 하나 · 네 값  (기대=1 CHECK ((scope = ANY (ARRAY['read'::text, 'read+upload'::text, 'read+upload+delete'::text, 'community.write'::text]))) 실제=1 CHECK ((scope = ANY (ARRAY['read'::text, 'read+upload'::text, 'read+upload+delete'::text, 'community.write'::text]))))
  ✔ 첫 적용과 둘째 적용의 결과가 같다  (기대=1 CHECK ((scope = ANY (ARRAY['read'::text, 'read+upload'::text, 'read+upload+delete'::text, 'community.write'::text]))) 실제=1 CHECK ((scope = ANY (ARRAY['read'::text, 'read+upload'::text, 'read+upload+delete'::text, 'community.write'::text]))))
  ✔ 기존 토큰 행의 범위는 그대로(네 값 밖 0)  (기대=0 실제=0)
  · 임시 .env 확인: DB_NAME=tbox_qf8_8401_test · 공용 토큰 귀속 API_TOKEN_USER=claude_employee01
  ✔ 새 코드 인스턴스가 떴다(8401)  (기대=0 실제=0)
  ✔ 기준 판 인스턴스가 떴다(8402)  (기대=0 실제=0)

════════════════════════════════════════════════════════════
로그인 (claude_ 검토 계정, 순차, F-71)
════════════════════════════════════════════════════════════
  · 세션 셋(claude_employee01 · claude_employee02 · claude_guest01)

════════════════════════════════════════════════════════════
T-1 발급 — 범위 community.write
════════════════════════════════════════════════════════════
  ✔ claude_employee01 token_scopes(파일 셋 + community.write)  (기대=read,read+upload,read+upload+delete,community.write 실제=read,read+upload,read+upload+delete,community.write)
  ✔ claude_guest01 token_scopes 는 read 하나(커뮤니티 쓰기 자격 없음)  (기대=read 실제=read)
  ✔ Guest 가 community.write 발급 → 400(허용 목록 밖 · 비밀번호 확인 전에 끝남)  (기대=400 실제=400)
  ✔ 모르는 범위 값 → 400  (기대=400 실제=400)
  ✔ 사다리에 끼운 값(read+community.write) → 400  (기대=400 실제=400)
  ✔ 위 세 거부는 실패 집계 0(claude_employee01 failed_count)  (기대=0 실제=0)
  · claude_employee01 다음 2단계 코드 창까지 11초 기다린다 (R-45 1회성)
  ✔ claude_employee01 community.write 발급 → 201  (기대=201 community.write 실제=201 community.write)
  ✔ DB 행 범위 · 만료 180일  (기대=community.write 180 실제=community.write 180)
  · claude_employee01 다음 2단계 코드 창까지 29초 기다린다 (R-45 1회성)
  ✔ claude_employee01 read 발급 → 201(T-4 범위 거부용)  (기대=201 read 실제=201 read)

════════════════════════════════════════════════════════════
T-3(앞) · T-2 쓰기 — 토큰으로 내 개인 공간에 비공개 페이지
════════════════════════════════════════════════════════════
  ✔ 토큰 GET /community/api/me/space → 200 · personal  (기대=200 personal 실제=200 personal)
  · 실제 200 본문(API.md 19장): {"space":{"id":5,"name":"claude_employee01 의 공간","kind":"personal","created_at":"2026-09-28 09:38:40.0161+00","page_count":2},"created":false,"start_page":null}
  ✔ 세션 GET /community/api/me/space 와 같은 번호  (기대=5 실제=5)
  ✔ 토큰 POST /community/api/pages {title, doc} → 201  (기대=201 실제=201)
  · 실제 201 본문(비밀 없음 — API.md 19장): {"page":{"id":"c86ffdcf-7b17-40bc-8d71-e915a48b6f85","title":"QF8 토큰 글","doc_version":1,"block_count":5,"visibility":"private","space":{"id":5,"kind":"personal","name":"claude_employee01 의 공간"},"parent_id":null,"position":2,"created_at":"2026-09-28 23:08:32.126411+00","updated_at":"2026-09-28 23:08:32.126411+00","url":"/community/p/c86ffdcf-7b17-40bc-8d71-e915a48b6f85","database":null,"values":null}}
  ✔ 응답 space.kind · visibility · parent_id · 내 공간 번호  (기대=personal private None 5 실제=personal private None 5)
  ✔ 임시 DB 행 — 개인 공간 · 비공개 · 부모 없음 · 작성자 claude_employee01 · 블록 5  (기대=personal private true 3 5 실제=personal private true 3 5)
  ✔ 감사 page.create allow 한 행 — auth=api_token · token_kind=personal · token_id · token_scope · 행위자 claude_employee01 · UA  (기대=1 실제=1)
  ✔ 감사 route = POST /community/api/pages  (기대=POST /community/api/pages 실제=POST /community/api/pages)

════════════════════════════════════════════════════════════
T-3 읽기
════════════════════════════════════════════════════════════
  ✔ 토큰 GET /community/api/pages/{내 글} → 200 · 같은 id · 블록 5  (기대=200 c86ffdcf-7b17-40bc-8d71-e915a48b6f85 5 실제=200 c86ffdcf-7b17-40bc-8d71-e915a48b6f85 5)
  · claude_employee02 의 비공개 글 id = 1e7cc02a-7c3d-482d-8401-ae52ef4f41b8
  ✔ claude_employee02 글은 비공개  (기대=private 실제=private)
  ✔ 남의 비공개 글 — 토큰 답 = 세션 답(403/404)  (기대=403 권한이 없습니다 실제=403 권한이 없습니다)
  ✔ 그 답은 403 또는 404  (기대=yes 실제=yes)
  ✔ 없는 페이지 id → 404(세션과 같은 갈래)  (기대=404 실제=404)

════════════════════════════════════════════════════════════
T-4 거부
════════════════════════════════════════════════════════════
  ✔ 토큰 POST 에 space_id(null) → 400 token_scope_fields  (기대=400 token_scope_fields 실제=400 token_scope_fields)
  ✔ 토큰 POST 에 parent_id(null) → 400 token_scope_fields  (기대=400 token_scope_fields 실제=400 token_scope_fields)
  ✔ 토큰 POST 에 template_id(null) → 400 token_scope_fields  (기대=400 token_scope_fields 실제=400 token_scope_fields)
  ✔ 토큰 POST 에 database(null) → 400 token_scope_fields  (기대=400 token_scope_fields 실제=400 token_scope_fields)
  ✔ 토큰 POST 에 properties(null) → 400 token_scope_fields  (기대=400 token_scope_fields 실제=400 token_scope_fields)
  ✔ 토큰 POST 에 space_id(내 공간 번호 — 값이 맞아도) → 400 token_scope_fields  (기대=400 token_scope_fields 실제=400 token_scope_fields)
  ✔ 토큰 POST 에 visibility(원래 허용 키 밖) → 400 잘못된 요청(세션과 같은 갈래)  (기대=400 잘못된 요청입니다 실제=400 잘못된 요청입니다)
  ✔ 거부 뒤 claude_employee01 의 페이지 수 늘지 않음(토큰으로 만든 것 1)  (기대=1 실제=1)
  ✔ read 범위 토큰 POST /api/pages → 403 token_scope 문구  (기대=403 이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요 실제=403 이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요)
  ✔ read 범위 토큰 GET /api/me/space → 403(범위 독립 — 읽기 사다리가 커뮤니티를 열지 않는다)  (기대=403 실제=403)
  ✔ 감사 auth.token deny scope_not_permitted 두 행(token_scope=read · auth=api_token · route 패턴)  (기대=2 실제=2)
  ✔ 공용(.env) 토큰 → 403 개인 토큰만(F-59)  (기대=403 이 창구는 개인 토큰으로만 쓸 수 있습니다 실제=403 이 창구는 개인 토큰으로만 쓸 수 있습니다)
  ✔ (대조) 같은 공용 토큰 GET /tbox/api/whoami → 200(값은 맞다)  (기대=200 실제=200)
  ✔ 틀린 값 → 401  (기대=401 API 토큰이 올바르지 않습니다 실제=401 API 토큰이 올바르지 않습니다)
  ✔ 틀린 값 + 살아 있는 세션 쿠키 → 401(세션으로 되돌아가지 않는다)  (기대=401 실제=401)
  ✔ 만료 토큰 → 401 token_expired 문구  (기대=401 만료된 API 토큰입니다 — 내 계정에서 새로 발급하세요 실제=401 만료된 API 토큰입니다 — 내 계정에서 새로 발급하세요)

════════════════════════════════════════════════════════════
T-5 닫힘 — 표 밖 창구는 헤더를 읽지 않는다
════════════════════════════════════════════════════════════
  ✔ 토큰 GET /community/api/pages(목록) → 401 로그인 필요  (기대=401 로그인이 필요합니다 실제=401 로그인이 필요합니다)
  ✔ 토큰 GET /community/api/search → 401  (기대=401 로그인이 필요합니다 실제=401 로그인이 필요합니다)
  ✔ 토큰 PATCH /community/api/pages/{id} → 401  (기대=401 로그인이 필요합니다 실제=401 로그인이 필요합니다)
  ✔ 토큰 POST /community/api/pages/{id}/comments → 401  (기대=401 로그인이 필요합니다 실제=401 로그인이 필요합니다)
  ✔ 토큰 DELETE /community/api/pages/{id} → 401  (기대=401 로그인이 필요합니다 실제=401 로그인이 필요합니다)
  ✔ 토큰 GET /community/api/spaces → 401  (기대=401 로그인이 필요합니다 실제=401 로그인이 필요합니다)
  ✔ 닫힌 창구의 토큰 사용 기록 0(auth=api_token 행 0 — 헤더를 읽지 않았다)  (기대=0 실제=0)
  ✔ 그 페이지는 그대로(지워지지 않고 제목 그대로)  (기대=QF8 토큰 글 true 실제=QF8 토큰 글 true)
  ✔ community.write 토큰으로 t-box POST /tbox/api/upload → 403 token_scope  (기대=403 이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요 실제=403 이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요)
  ✔ community.write 토큰으로 t-box GET /tbox/api/files → 403 token_scope  (기대=403 실제=403)
  ✔ community.write 토큰으로 GET /tbox/api/whoami → 200 claude_employee01(조각 C 의 토큰 확인)  (기대=200 claude_employee01 실제=200 claude_employee01)

════════════════════════════════════════════════════════════
T-6 세션 불변 — 헤더 없는 요청을 기준 판(3ebee61 · http://127.0.0.1:8402)과 견준다
════════════════════════════════════════════════════════════
  · claude_employee01 로그인 재시도 — 다음 코드 창까지 27초
  · claude_employee01 로그인 재시도 — 다음 코드 창까지 28초
  ✔ 세션 GET /community/api/me/space — 상태 코드 새=기준  (기대=200 실제=200)
  ✔ 세션 GET /community/api/me/space — 본문 모양 새=기준  (기대={"created": "bool", "space": {"created_at": "str", "id": "int", "kind": "str", "name": "str", "page_count": "int"}, "start_page": "NoneType"} 실제={"created": "bool", "space": {"created_at": "str", "id": "int", "kind": "str", "name": "str", "page_count": "int"}, "start_page": "NoneType"})
  ✔   그 번호도 같다  (기대=5 실제=5)
  ✔ 세션 GET /community/api/pages/{토큰으로 만든 글} — 상태 코드 새=기준  (기대=200 실제=200)
  ✔ 세션 GET /community/api/pages/{토큰으로 만든 글} — 본문 모양 새=기준  (기대={"backlinks": [], "board": "NoneType", "breadcrumb": [{"icon": "NoneType", "id": "str", "title": "str"}], "can_comment": "bool", "can_edit": "bool", "can_handover": "bool", "can_hide": "bool", "can_manage": "bool", "can_publish": "bool", "can_resolve": "bool", "can_set_properties": "bool", "can_set_visibility": "bool", "can_share": "bool", "can_tag": "bool", "can_unpublish": "bool", "can_view_reads": "bool", "child_titles": {}, "children": [], "database": "NoneType", "editors": [], "embeds": {"frames": "bool", "hosts": []}, "file_titles": {}, "granted_by": "str", "link_titles": {}, "mention_labels": {}, "page": {"assignee": "NoneType", "block_count": "int", "created_at": "str", "doc": {"blocks": [{"id": "str", "level": "int", "text": [{"m": [], "t": "str"}], "type": "str"}], "schema": "int"}, "doc_version": "int", "font": "str", "full_width": "bool", "icon": "NoneType", "id": "str", "owner": {"display_name": "NoneType", "is_active": "bool", "kind": "str", "username": "str"}, "parent": "NoneType", "position": "int", "small_text": "bool", "status": "NoneType", "title": "str", "updated_at": "str", "visibility": "str"}, "published_from": "NoneType", "resolved": "bool", "row": "NoneType", "share_level": "NoneType", "space": {"id": "int", "kind": "str", "name": "str", "team_id": "NoneType"}, "subtree_count": "int", "tags": [], "template_scopes": ["str"], "view_count": "int", "writer": "NoneType"} 실제={"backlinks": [], "board": "NoneType", "breadcrumb": [{"icon": "NoneType", "id": "str", "title": "str"}], "can_comment": "bool", "can_edit": "bool", "can_handover": "bool", "can_hide": "bool", "can_manage": "bool", "can_publish": "bool", "can_resolve": "bool", "can_set_properties": "bool", "can_set_visibility": "bool", "can_share": "bool", "can_tag": "bool", "can_unpublish": "bool", "can_view_reads": "bool", "child_titles": {}, "children": [], "database": "NoneType", "editors": [], "embeds": {"frames": "bool", "hosts": []}, "file_titles": {}, "granted_by": "str", "link_titles": {}, "mention_labels": {}, "page": {"assignee": "NoneType", "block_count": "int", "created_at": "str", "doc": {"blocks": [{"id": "str", "level": "int", "text": [{"m": [], "t": "str"}], "type": "str"}], "schema": "int"}, "doc_version": "int", "font": "str", "full_width": "bool", "icon": "NoneType", "id": "str", "owner": {"display_name": "NoneType", "is_active": "bool", "kind": "str", "username": "str"}, "parent": "NoneType", "position": "int", "small_text": "bool", "status": "NoneType", "title": "str", "updated_at": "str", "visibility": "str"}, "published_from": "NoneType", "resolved": "bool", "row": "NoneType", "share_level": "NoneType", "space": {"id": "int", "kind": "str", "name": "str", "team_id": "NoneType"}, "subtree_count": "int", "tags": [], "template_scopes": ["str"], "view_count": "int", "writer": "NoneType"})
  ✔ 세션 GET /community/api/pages/{남의 비공개 글} — 상태 코드 새=기준  (기대=403 실제=403)
  ✔ 세션 GET /community/api/pages/{남의 비공개 글} — 본문 모양 새=기준  (기대={"error": "str"} 실제={"error": "str"})
  ✔ 세션 POST /community/api/pages CSRF 없음 — 상태 코드 새=기준  (기대=403 실제=403)
  ✔ 세션 POST /community/api/pages CSRF 없음 — 본문 모양 새=기준  (기대={"error": "str"} 실제={"error": "str"})
  ✔   그 답은 403  (기대=403 실제=403)
  ✔ 세션 POST /community/api/pages {title, space_id} CSRF 있음(세션은 space_id 를 받는다) — 상태 코드 새=기준  (기대=201 실제=201)
  ✔ 세션 POST /community/api/pages {title, space_id} CSRF 있음(세션은 space_id 를 받는다) — 본문 모양 새=기준  (기대={"page": {"block_count": "int", "created_at": "str", "database": "NoneType", "doc_version": "int", "id": "str", "parent_id": "NoneType", "position": "int", "space": {"id": "int", "kind": "str", "name": "str"}, "title": "str", "updated_at": "str", "url": "str", "values": "NoneType", "visibility": "str"}} 실제={"page": {"block_count": "int", "created_at": "str", "database": "NoneType", "doc_version": "int", "id": "str", "parent_id": "NoneType", "position": "int", "space": {"id": "int", "kind": "str", "name": "str"}, "title": "str", "updated_at": "str", "url": "str", "values": "NoneType", "visibility": "str"}})
  ✔   새 코드 쪽 201 · personal · private  (기대=201 personal private 실제=201 personal private)
  ✔ 세션 GET /community/api/pages(목록) — 상태 코드 새=기준  (기대=200 실제=200)
  ✔ 세션 GET /community/api/pages(목록) — 본문 모양 새=기준  (기대={"page": "int", "pages": [{"assignee": "NoneType", "block_count": "int", "can_edit": "bool", "created_at": "str", "doc_version": "int", "excerpt": "str", "icon": "NoneType", "id": "str", "parent_id": "NoneType", "status": "NoneType", "title": "str", "updated_at": "str", "visibility": "str"}], "per_page": "int", "q": "str", "space": {"can_write": "bool", "id": "int", "kind": "str", "name": "str", "team_id": "NoneType"}, "status": "str", "total": "int"} 실제={"page": "int", "pages": [{"assignee": "NoneType", "block_count": "int", "can_edit": "bool", "created_at": "str", "doc_version": "int", "excerpt": "str", "icon": "NoneType", "id": "str", "parent_id": "NoneType", "status": "NoneType", "title": "str", "updated_at": "str", "visibility": "str"}], "per_page": "int", "q": "str", "space": {"can_write": "bool", "id": "int", "kind": "str", "name": "str", "team_id": "NoneType"}, "status": "str", "total": "int"})
  ✔ 쿠키·헤더 없음 GET /community/api/me/space — 새=기준(401)  (기대=401 로그인이 필요합니다 실제=401 로그인이 필요합니다)
  ✔ 쿠키 없음 화면 /community/ — 새=기준(302 로그인)  (기대=302 http://127.0.0.1:PORT/tbox/login?to=community 실제=302 http://127.0.0.1:PORT/tbox/login?to=community)
  ✔ 세션으로 community.write 토큰 회수 → 200  (기대=200 실제=200)
  ✔ 회수된 토큰 → 401(틀린 토큰과 같은 문구)  (기대=401 API 토큰이 올바르지 않습니다 실제=401 API 토큰이 올바르지 않습니다)
  ✔ 시험이 만든 read 토큰 회수 → 200 · 이 시험이 남긴 살아 있는 토큰 0  (기대=200 0 실제=200 0)

════════════════════════════════════════════════════════════
회귀 — 같은 임시 인스턴스·임시 DB 에서(ux_r1_ui.sh 의 회귀 틀 · 8082 → http://127.0.0.1:8401 · tbox_re → tbox_qf8_8401_test)
════════════════════════════════════════════════════════════
  ▸ api_whoami (시작 23:10:01)
    종료 코드 0 · 통과 22 · 실패 0 · 건너뜀/미검증 0
  ▸ community/stage3 (시작 23:10:31)
    종료 코드 1 · 통과 82 · 실패 1 · 건너뜀/미검증 2
         ✖ 내려받기 실패
        ✖ vendor_prosemirror.sh --verify 통과  (기대=0 실제=1)
  ▸ community/stage5a2 (시작 23:11:01)
    종료 코드 0 · 통과 136 · 실패 0 · 건너뜀 2
  ▸ f66 (시작 23:11:31)
    종료 코드 1 · stage26.sh · help_button.sh 는 이 스크립트가 돌리지 않는다 — 따로 실행해 결과를 보고에 적는다.
        ✖ 준비 /api/me token_scopes (Employee 셋)  (기대=read,read+upload,read+upload+delete 실제=read,read+upload,read+upload+delete,community.write)

════════════════════════════════════════════════════════════
서버 로그
════════════════════════════════════════════════════════════
  ✔ 새 코드 인스턴스 로그의 PHP 오류(Fatal·Warning·Notice·Deprecated) 0  (기대=0 실제=0)
  ✔ 새 코드 인스턴스 로그의 잡히지 않은 예외 0  (기대=0 실제=0)
  ✔ 이 시험이 부른 주소는 임시 인스턴스뿐(127.0.0.1:840x)  (기대=1 1 실제=1 1)

통과 91 · 실패 2
```

읽는 법: 맨 끝 「통과 91 · 실패 2」 중 **수락 항목은 89 통과 · 실패 0**이고, 나머지 넷은 회귀 스크립트 하나당 한 항목이다(api_whoami ✔ · stage5a2 ✔ · stage3 ✖ · f66 ✖). 두 ✖ 의 까닭:
- `community/stage3` 82/1 — 실패 한 항목은 C:T-20 `scripts/vendor_prosemirror.sh --verify` 가 `https://registry.npmjs.org` 에서 tarball 을 받지 못한 것(이 환경의 나가는 망이 403 — `curl … registry.npmjs.org/prosemirror-model` → `403` 확인). 코드 변경과 무관. 브라우저 두 항목(C:T-19 JS 쪽 · C:T-21)은 Playwright 가 없어 미검증.
- `f66` 116/1 — 실패 한 항목은 `tests/f66.sh` 357줄 「준비 /api/me token_scopes (Employee 셋)」 기대 `read,read+upload,read+upload+delete` · 실제 `…,community.write`. **이 조각이 의도한 변화**다(설계 1 — 쓰기 자격 있는 역할의 선택지에 넷째 값). `tests/f66.sh` 는 수정 파일 밖이라 고치지 않았다 → 판단 대기 ①. (b)7 · (g) 두 건은 환경 사유로 건너뜀(2단계 꺼진 검토 계정 없음 · Playwright 없음).

회귀 결과 사본(요약 줄):

```text
api_whoami          통과 22 · 실패 0 · 건너뜀/미검증 0
community/stage3    통과 82 · 실패 1 · 건너뜀/미검증 2
community/stage5a2  통과 136 · 실패 0 · 건너뜀 2         (자기 임시 인스턴스 8403 · 임시 DB tbox_qf8_5a2_test)
f66                 통과 116 · 실패 1 · 건너뜀/미검증 2   ((a)~(f)·(h) — 임시 DB 에 마이그레이션이 있어 전부 실행)
```

남긴 임시 자원 확인(시험 끝 뒤):

```text
$ psql -U tbox_testdb … -c "select datname from pg_database order by 1"
postgres
tbox_re
template0
template1
$ ps aux | grep -E "php -S 127.0.0.1:84" | grep -v grep     → (없음)
$ ls -d /tmp/tmp.* | wc -l                                   → 0
```

### 5. 못 한 것과 이유

- **발급 화면(select 한 줄)을 브라우저로 보지 못했다** — 이 환경에 Playwright 가 없다. 서버 쪽 선택지(`/api/me` 의 `token_scopes`)와 발급 창구는 실측했고, 화면 코드는 `node --check` 와 diff 로만 봤다 → **미검증**.
- `tests/community/stage3.sh` C:T-20(ProseMirror 내려받기 검증) — 나가는 망 403 → **미검증**(코드 무관).
- 회귀 기본 묶음 중 `stage26.sh` · `help_button.sh` · `api_read.sh` · `api_folders.sh` · `f68.sh` · `q_launcher.sh` 는 돌리지 않았다 — 지시서 완료 기준의 회귀 목록(stage3 · stage5a2 셸 절 · api_whoami · f66 정적 절)만 돌렸다. f66 은 정적 절만이 아니라 (a)~(f)·(h) 전부 돌았다(임시 DB 에 마이그레이션이 있어서).
- 운영 반영·운영 DB 마이그레이션은 하지 않는다(사용자 몫).

### 6. 지시와 다르게 한 것

1. **`TBOX_AUTH_METHOD` 를 범위 판정 **앞**에 세웠다**(지시서 설계 2 는 「범위 거짓이면 403 → define」 순서). `public/index.php` [2-T]·[2-S] 가 그 순서라서, 범위 거부 감사 행에도 `auth=api_token` 이 붙어 **index.php 와 같은 감사**가 된다(설계 2 「index.php [2-S] 와 같은 응답·감사」 쪽을 따랐다). 거부되면 핸들러에 닿지 않으므로 동작 차이는 감사 detail 한 키뿐이다.
2. **`authenticated` 정책(= `GET /tbox/api/whoami`)은 `community.write` 에도 참**으로 두었다. 조각 C 계약이 「`GET /tbox/api/whoami` → 200(토큰 확인용)」을 쓰기 때문이다. 설계 1 은 `file.*` 만 거짓으로 적었고 `authenticated` 는 언급이 없다 — whoami 가 돌려주는 것은 계정 이름·역할·팀·올릴 수 있는 등급뿐이다(F-56).
3. 수락 시험 T-1 의 발급 주소는 지시서의 `/tbox/api/me/tokens` 가 아니라 실제 창구 `/tbox/api/me/api-tokens`(API.md 13장)다.
4. 「관리자가 발급한(개인 아닌) 토큰 → 403」은 **공용 `.env` 토큰**으로 시험했다 — F-66 이후 관리자가 남의 토큰을 발급하는 창구가 없어서, 「개인이 아닌 토큰」은 공용 토큰뿐이다. 임시 `.env` 에 시험이 만든 난수와 `API_TOKEN_USER=claude_employee01` 을 넣었다(0600 · 끝에 지움).
5. 수락 시험에 T-6 「세션 불변」을 **기준 판(3ebee61) 인스턴스와 나란히** 띄워 같은 요청의 상태 코드·본문 모양을 견주는 방식으로 넣었다(지시서는 「지금과 같은 응답」).
6. 회귀를 돌리려고 수락 시험에 `REGRESS=1` 틀(ux_r1_ui.sh 와 같은 사본 방식)을 넣었다. stage5a2 는 사본에서 포트 8085 → **8403**, 임시 DB → `tbox_qf8_5a2_test` 로 바꿔 이 조각의 포트·접두 안에서만 돌았다.
7. 로컬 PostgreSQL 16 클러스터가 내려가 있어(`pg_lsclusters` → down, 남은 pid 파일) `sudo -n pg_ctlcluster 16 main start` 로 올렸다 — 이 문맥의 로컬 시험 환경이다(운영 없음).

### 7. 판단 대기 (기본안 = 좁은 쪽으로 해 둠)

① **`tests/f66.sh` 357줄 기대값** — Employee 의 `token_scopes` 가 넷이 됐다. 고치려면 기대를 `read,read+upload,read+upload+delete,community.write` 로(약화가 아니라 새 사실 반영). 수정 파일 밖이라 그대로 두었다.
② **`db/schema.sql` 의 CHECK** — 아직 세 값이다(수정 파일 밖). 새로 설치하는 DB 는 이 마이그레이션을 이어서 적용해야 「커뮤니티 글쓰기」 발급이 된다(없으면 발급만 CHECK 위반 500). ARCH 6.2-5 조각에 주석으로 적었다.
③ **관리자에게 이 범위를 줄지** — 지금은 쓰기 자격 있는 모든 역할(Admin 포함)이 고를 수 있다. Admin 토큰의 `GET /api/pages/{id}` 는 세션과 같이 `admin_all` 로 남의 비공개 글을 읽는다(id 를 알아야 하고 목록·검색은 닫힘 · 열람 감사). 좁히려면 `tokenScopesFor` 에서 뺀다(R-112 ②).
④ 번호 — 지시서대로 F-74 · R-112 를 썼다(F-73 · R-111 은 비어 있다 — 다른 작업이 잡아 둔 번호인지 메인이 확인).
⑤ `api_tokens` 목록 표의 범위 칸에도 같은 라벨(긴 글자)이 보인다 — 라벨 표가 하나라서(발급 select · 목록 칸 공용). 짧게 가를지는 화면 판단.

### 8. 남긴 임시 자원

0 — 임시 DB 셋(`tbox_qf8_8401_test` · stage5a2 의 `tbox_qf8_5a2_test` · f66 (h) 의 자기 임시 DB)은 각 시험이 이름으로 지웠고, 인스턴스 폴더·임시 `.env` 는 trap 으로 지웠다(4절 확인 출력).

### 9. 판단 대기 ①·② 반영 (메인 결정 2026-09-28)

- `db/schema.sql` — `api_tokens.scope` CHECK 를 네 값으로(마이그레이션과 같은 값 · 같은 제약 이름이라는 주석). ARCH 6.2-5 주석도 맞춤.
- `tests/f66.sh` 357줄 — 기대값을 `read,read+upload,read+upload+delete,community.write` 로(까닭 한 줄).
- ③ 은 그대로(사용자 결정으로 올림) · ④ 번호 맞음(G-0 이 F-73·R-111) · ⑤ 그대로.
- 다시 돈 것: `php -l` 네 파일 통과 · `security_lint` 「결과: 통과 (금지 패턴 0건)」 · `bash -n tests/f66.sh` · `REGRESS=1 REGRESS_LIST=f66 bash tests/qf8_token_write.sh` → 「통과 90 · 실패 0」(수락 89 + f66 한 항목), 사본 f66 은 「통과 117 · 실패 0 · 건너뜀/미검증 2」((h) schema.sql 새 DB 적용 rc 0 · scope CHECK 1 포함 · 건너뜀은 2단계 꺼진 계정 없음·Playwright 없음). 임시 DB·인스턴스 0.
