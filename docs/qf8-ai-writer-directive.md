# Q:F-8 AI 글쓰기 — 구현 지시서 (Fable → Opus · 2026-09-28)

사용자 요구(2026-09-28 18:4x): 「내가 어떤 주제를 AI 에게 말하면 AI 가 커뮤니티에 글을 써 줬으면 좋겠어. 부족한 건 추가 개발해서 진행.」
사용자 결정(18:5x · 셋): ① 경로 = **Claude(Max 구독) + PC 의 MCP 서버** ② 글 자리 = **내 개인 공간 · 비공개**(사람이 읽고 공개·게시) ③ MCP 코드 = `clients/mcp/`.
사이트 PRD 10장 Q:F-8. 기능 묶음 하나(지침 3.1) = 조각 둘(S 서버 · C 클라이언트). 검토·반영은 묶음 끝에 한 번.

지금 사실(코드로 확인): 프로그램 토큰(`X-API-Token`)은 `public/index.php` `TBOX_API_TOKEN_ROUTES` 11개(t-box 파일 창구)에서만 읽힌다. `/community…` 요청은 그 갈래(`[2-T]`, index.php:815)에 닿기 **전에** `community_dispatch()`(index.php:706~713 → `src/community/routes.php`)로 빠지고, 거기에는 토큰 관문이 없다(세션만). 커뮤니티 창구 자체(77개 · `GET /api/pages` · `POST /api/pages` …)는 있다. 토큰 범위는 `Authz::TOKEN_SCOPES = read · read+upload · read+upload+delete`(F-66, `api_tokens.scope` CHECK).

---

## 조각 S — 커뮤니티 창구 셋에 개인 토큰 열기 (서버)

```
목표        : 개인 프로그램 토큰(새 범위 community.write)으로 「내 개인 공간에 비공개 페이지 만들기 · 그 페이지 읽기 · 내 공간 번호 알기」 셋만 되게 한다. 그 밖의 커뮤니티 창구는 토큰에 닿지 않는다(지금 그대로).
요구사항    : Q:F-8(사이트 PRD 10장) · t-box PRD F-49 ①(토큰은 정해진 창구에서만) · F-59(개인 토큰) · F-66(범위) · ARCHITECTURE 4.2~4.7(토큰 갈래·범위 관문 — 같은 원칙을 커뮤니티 라우터에 **한 곳** 더 둔다) · 커뮤니티 ARCHITECTURE 21.1(라우터) · 13.3(개인 공간) · SECURITY R-90·R-91(토큰 창구 위험)
              — 해당 절을 읽고 시작할 것. 화면은 「내 계정 → 프로그램 토큰 발급」의 범위 고르기 한 줄뿐(DESIGN 2.x 기존 셀렉트 모양 · 새 클래스·색값 0).
수정 파일   : src/community/routes.php(토큰 관문 · 허용 표 셋 — 보안 핵심) · src/Authz.php(TOKEN_SCOPES · tokenScopesFor · tokenScopeAllows — 보안 핵심) ·
              src/community/handlers/pages_create.php(토큰 모드 제한) · src/handlers/me_tokens_issue.php(새 범위 허용) ·
              db/migrations/2026-10-11_api_tokens_scope_community.sql(CHECK 에 값 하나 — 보안 핵심 db/**) ·
              public/assets/app.js(범위 셀렉트 한 줄 · 설명 글자) · src/views/*.php(범위 설명 글자가 서버 뷰에 있으면 그 파일) ·
              API.md(19장) · ARCHITECTURE.md(4.8) · PRD.md(F-74 행) · SECURITY.md(R-112 · 7장 한 행) · docs/qf8-implementation.md(구현 기록) ·
              tests/qf8_token_write.sh · tests/catalog.json(한 줄) · tests/static/*(필요하면 기준 판 값만).
              ★ public/index.php · src/Auth.php · src/Http.php · src/Db.php · src/community/CommunityAuthz.php · db/grants.sql · deploy/** 은 **고치지 않는다**.
                (Auth::requireApiToken · Http::apiTokenHeader · Authz::tokenScopeAllows 를 **그대로 부른다** — 새 판정 함수·새 헤더 0)
금지 패턴   : SQL 문자열 결합 · innerHTML · 사용자 입력이 들어간 파일 경로 · curl_exec/file_get_contents('http…') · 역할 판단을 Authz 밖에서 직접 · 요청 파라미터의 role 값 · 브라우저 HTML 저장 ·
              **토큰 허용 판단을 라우트 패턴이 아닌 요청 값으로** · 세션 경로의 동작 변화(헤더가 없으면 지금과 100% 같아야 한다) · 다른 커뮤니티 창구에 토큰 허용 · 범위 사다리(read → read+upload → …)에 community.write 를 끼워 넣기(독립 값이다)
완료 기준   : 아래 「설계」 전부 + `tests/qf8_token_write.sh` 전부 통과 + 회귀(커뮤니티 세션 시험 대표 `tests/community/stage3.sh` · `stage5a2.sh` 중 **셸 절**(브라우저 없음) · `tests/api_whoami.sh` · `tests/f66.sh` 의 정적 절 · `scripts/security_lint.sh` 0 · `tests/static/selftest.sh` · `php -l` · `node --check`)
보고 형식   : 변경 파일 목록(파일·줄 수) / 실행한 명령과 출력 원문(수락·회귀·정적) / 못 한 것과 이유 / 지시와 다르게 한 것 / 남긴 임시 자원 0
```

### 설계 (지키는 것)
1. **범위** `community.write` — `Authz::TOKEN_SCOPES` 에 넷째 값. `tokenScopesFor($u)`: `Authz::canCommunityWrite($u)` 가 참일 때만 허용(Guest 0). `tokenScopeAllows(scope, policy)`: 정책 `community.write` · `community.read` ← 범위 `community.write` 만 참(기존 셋은 거짓) · 기존 정책(file.*)은 `community.write` 에 거짓(파일 창구를 열지 않는다 — 독립 값). 마이그레이션은 CHECK 제약에 값 하나 더할 뿐(`api_tokens_scope_check` 를 지우고 넷으로 다시 · 멱등 · 두 번 적용 안전 · 되돌리기 절 주석).
2. **관문 자리** = `community_dispatch()` 한 곳. 라우트 매칭 **뒤** · 세션 조회 **앞**에: `COMMUNITY_API_TOKEN_ROUTES = ['GET /api/me/space', 'POST /api/pages', 'GET /api/pages/{id}']`(라우트 패턴 글자 그대로 · 표는 상수 하나) 이고 `Http::apiTokenHeader() !== null` 일 때만 → `Auth::requireApiToken(true)`(개인 토큰만 — F-59 인자) → `Authz::tokenScopeAllows($token['scope'], $policy)` 거짓이면 403 `token_scope`(index.php `[2-S]` 와 같은 응답·감사) → `define('TBOX_AUTH_METHOD','api_token')` → CSRF·최초 절차·재인증 건너뜀(index.php 주석의 까닭 그대로) → 아래 인가·핸들러는 세션과 **같은 코드**. 헤더가 없으면 지금 세션 경로 그대로(한 글자도 안 바뀐다). 표 밖 라우트에 헤더가 오면 **읽지 않는다**(세션 경로 → 401).
3. **토큰 모드의 `POST /api/pages`** — `pages_create.php` 머리에서 `TBOX_AUTH_METHOD === 'api_token'` 이면 허용 키는 `title` · `doc` 둘뿐: `parent_id` · `space_id` · `database` · `properties` · `template_id` 가 **있으면**(null 이어도) 400 `token_scope_fields`(사유 문자열은 기존 표에 더한다). 나머지 검증([1]~[5] · Doc::validate · 개인 공간 없으면 409)은 그대로 → 결과는 언제나 **내 개인 공간 · visibility private · 부모 없음**. 응답 모양은 세션과 같다.
4. **`GET /api/me/space`** 토큰 모드도 세션과 같은 동작(없으면 만든다 — 멱등). **`GET /api/pages/{id}`** 는 판정 그대로(`CommunityAuthz` — 남의 글이면 세션과 같은 404/403).
5. **감사**: 자동 분류(`Audit::autoDetail` 의 `auth=api_token`)로 충분 — 새 문장 0. 거부는 기존 `Audit::deny` 경로.
6. **발급 화면**: 내 계정 → 토큰 발급의 범위 셀렉트에 「커뮤니티 글쓰기 — 내 개인 공간에 비공개 페이지 만들기·읽기(파일 창구 없음)」 한 줄(글쓰기 자격 없는 역할이면 서버가 400 — 화면은 `tokenScopesFor` 가 준 목록만 보여 준다 · 지금 방식 그대로).
7. **문서**: API.md 19장(창구 셋 · 예 · 오류표 · 「기존 글 읽기·검색은 열지 않는다」) · ARCHITECTURE 4.8(관문이 둘이 된 까닭 — 라우터가 둘이라 한 곳씩 · 같은 함수 재사용) · PRD F-74 · SECURITY R-112(위험: 토큰 유출 → 내 개인 공간에 비공개 글이 쌓임 · 완화: 개인 토큰만 · 범위 독립 · 창구 셋 · 비공개 고정 · 만료 180일) · 7장 한 행 · 구현 기록.

### 수락 시험 `tests/qf8_token_write.sh` (임시 인스턴스 · 임시 DB · 포트 8401~8403 · 브라우저 0)
틀은 `tests/ux_r1_ui.sh` 의 셸 부분(임시 DB `tbox_qf8_<포트>_test` 를 `tbox_testdb` 역할로 · `sudo -n -u postgres pg_dump --no-owner -d tbox_re` 파이프 · 임시 .env · `router.php` · 검토 계정 claude_ · 비밀은 표준입력 `tests/lib/secrets.sh`). 마이그레이션은 임시 DB 에 적용해 본다(두 번 적용 → 같은 결과).
- T-1 발급: claude_employee01 세션으로 `POST /tbox/api/me/tokens` scope=community.write → 201 · Guest(claude_guest01)는 400(허용 목록 밖) · 잘못된 값 400
- T-2 쓰기: 토큰으로 `POST /community/api/pages` {title, doc(문단 둘·제목·목록)} → 201 · 응답 `space.kind=personal` · `visibility=private` · `parent_id=null` · 임시 DB 로 행 확인 · 감사 행에 `auth=api_token`
- T-3 읽기: 같은 토큰 `GET /community/api/pages/{id}` 200 · 남(claude_employee02)의 비공개 글 id → 세션과 같은 답(404/403) · `GET /community/api/me/space` 200(둘 다 같은 번호)
- T-4 거부: body 에 `space_id`·`parent_id`·`template_id`·`database`·`properties` 각각 → 400 `token_scope_fields`(다섯 번) · 범위 `read` 토큰 → 403 `token_scope` · 관리자가 발급한(개인 아닌) 토큰 → 403(F-59) · 만료·회수 토큰 → 401 · 틀린 값 → 401(세션으로 되돌아가지 않음)
- T-5 닫힘: 토큰으로 `GET /community/api/pages`(목록) · `GET /community/api/search` · `PATCH /community/api/pages/{id}` · `POST /community/api/pages/{id}/comments` · `DELETE …` → 전부 **401 로그인 필요**(토큰을 읽지 않는다) · `community.write` 토큰으로 t-box `POST /tbox/api/upload` → 403 `token_scope`
- T-6 세션 불변: 헤더 없이 세션으로 같은 창구 셋 → 지금과 같은 응답(CSRF 없으면 403 등) · 기존 커뮤니티 시험(stage3 셸 절) 통과
- T-7 정적: 허용 표는 상수 하나 · `X-API-Token` 을 읽는 곳이 routes.php 에 한 곳 · index.php 차이 0 · `security_lint` 0 · `php -l`

---

## 조각 C — MCP 서버 `clients/mcp/` (PC 에서 도는 프로그램 · Python)

```
목표        : Claude Desktop · Claude Code 에서 「○○ 주제로 글 써서 올려」라고 하면 Claude 가 Markdown 으로 쓴 글을 Qaredi 커뮤니티 **내 개인 공간에 비공개 페이지**로 만드는 stdio MCP 서버.
요구사항    : Q:F-8 · 조각 S 의 창구 셋(API.md 19장이 될 계약 — 아래 「계약」이 원문) · `public/assets/community/doc-schema.json`(블록 모양·상한 — 검증기와 같은 파일) · `src/community/Doc.php`(validate 규칙을 읽고 같은 모양을 만든다) · 스킬 `anthropic-skills:mcp-builder` 를 먼저 읽는다.
수정 파일   : clients/mcp/** 만 (새 폴더): qaredi_mcp/server.py · qaredi_mcp/markdown_doc.py(변환기) · qaredi_mcp/client.py(HTTP) · pyproject.toml 또는 requirements.txt(버전 고정) · README.md(설치·Claude Desktop/Code 설정·토큰 다루기) · tests/test_markdown_doc.py(변환기 단위 시험 · pytest) · tests/validate_with_php.sh(변환 결과를 저장소의 Doc::validate 로 통과 확인) ·
              deploy/DEPENDENCIES.md(새 절 — `mcp` 파이썬 패키지 버전·라이선스·sha256 · 「PC 클라이언트 전용 · 서버에 설치하지 않는다」).
금지 패턴   : 토큰·본문을 로그·표준출력·오류 문장에 찍기 · 토큰을 파일에 쓰기(환경 변수 QAREDI_TOKEN 로만 받는다) · 창구 셋 밖의 주소 호출 · HTML 생성(블록 JSON 만) · 리다이렉트 따라가기 · TLS 검증 끄기 · 서버 코드(src/**·public/**) 변경.
완료 기준   : 단위 시험 전부 통과 · validate_with_php.sh 통과(표본 10 이상: 제목 셋 · 목록 둘 · 할 일 · 인용 · 코드 · 구분선 · 표 · 인라인 마크 · 링크 · 2000자 넘는 문단 쪼개기 · 3000 블록 상한) · README 대로 Claude Code 에 붙였을 때 `whoami` 도구가 답한다(조각 S 가 반영된 임시 인스턴스에서 — 메인이 붙여 본다).
보고 형식   : 조각 S 와 같다.
```

### 계약 (조각 S 의 창구 · 조각 C 는 이것만 부른다)
- 기본 주소 `QAREDI_URL`(기본 `https://changkwon.mso.so`) · 헤더 `X-API-Token: <토큰>` · `User-Agent: qaredi-mcp/<버전>` · JSON.
- `GET  {URL}/tbox/api/whoami` → 200 `{username, role, …}`(있는 창구 — 토큰 확인용)
- `GET  {URL}/community/api/me/space` → 200 `{space:{id, kind:'personal', name}}` (모양은 `src/community/handlers/space_me.php` 응답 그대로 — 구현자가 읽어 맞춘다)
- `POST {URL}/community/api/pages` `{title, doc}` → 201 `{page:{id, title, visibility:'private', space:{…}, …}}` · 페이지 주소는 `{URL}/community/p/{id}`
- `GET  {URL}/community/api/pages/{id}` → 200 `{page:{…, doc}}`
- 오류: 401(토큰 없음·틀림·만료) · 403 `token_scope`(범위) · 400(문서 검증 — `reason` 문자열) · 409(개인 공간 없음 — `GET /api/me/space` 를 먼저 부르면 생긴다)

### 도구 (MCP tools)
- `qaredi_whoami()` → 계정 이름·역할(토큰 값은 절대 돌려주지 않는다)
- `qaredi_write_page(title: str, markdown: str)` → Markdown → 블록 문서 → `GET /api/me/space`(멱등) → `POST /api/pages` → `{id, url, title, blocks}`. 제목 200자 초과 400 을 미리 막지 말고 서버 답을 그대로 돌려준다(검증은 서버 한 곳).
- `qaredi_read_page(id: str)` → 블록 문서 → Markdown 으로 되돌려 준다(Claude 가 다시 읽고 고칠 수 있게 — 고치기 창구는 이 조각에 없다: 새 페이지로 다시 쓴다)
- 도구 설명(description)은 한국어·영어 한 줄씩 · 「비공개 개인 공간에만 만든다 · 공개·게시는 사람이 화면에서」를 적는다.

### Markdown → 블록 (변환기 규칙 · doc-schema.json 의 모양 그대로)
- 문단 → `paragraph` · `#`/`##`/`###` → `heading` level 1~3(넷째 이상은 3) · `-`/`*` → `bulleted` · `1.` → `numbered` · `- [ ]`/`- [x]` → `to_do` · `>` → `quote` · ``` 펜스 → `code`(language 는 스키마 `languages` 목록에 있을 때만, 아니면 `plain` 같은 기본값 — 스키마에서 읽는다) · `---` → `divider` · GFM 표 → `table`(header_row true · 200행·20열 상한) · 들여쓴 목록 → `children`(depth 상한 10).
- 인라인 마크: `**` bold · `*`/`_` italic · `` ` `` code · `~~` strike · `[텍스트](https://…)` link(href 는 스키마 `link_schemes` 만 · 2048자) — richtext 조각 모양(`text[]` 의 `t`·`m`)은 Doc.php 를 읽어 맞춘다.
- 상한: 조각 2000자 넘으면 나눈다 · 조각 100개/블록 · 블록 3000 · 문서 1MB — 넘으면 변환기가 명확한 오류(어느 상한인지)를 돌려준다.
- 블록 `id` 는 uuid4 · `color`·`align`·`size` 는 넣지 않는다(기본값).
- 모르는 문법(이미지 `![]()`, HTML 태그, 각주)은 **본문 글자로**(잃지 않는다) — 이미지는 링크 마크가 있는 문단.

### 설치·설정 (README 에 이 셋)
- `pipx install ./clients/mcp` 또는 `pip install -e ./clients/mcp` · 실행 `qaredi-mcp`(stdio)
- Claude Desktop: `claude_desktop_config.json` 의 `mcpServers.qaredi = {command:"qaredi-mcp", env:{QAREDI_URL, QAREDI_TOKEN}}`
- Claude Code: `claude mcp add qaredi -e QAREDI_URL=… -e QAREDI_TOKEN=… -- qaredi-mcp`
- 토큰은 내 계정 화면에서 범위 「커뮤니티 글쓰기」로 발급 · 180일 만료 · 유출되면 그 화면에서 회수.

---

## 공통 (두 조각 모두)
- 작업 자리: worktree `/home/user/qaredi/qf8`(브랜치 `wip/qf8` · 기준 운영 HEAD c2b4d76). 커밋은 **파일 지정**(`git add -A` 금지) · 한글 · 첫 줄 `Qaredi Q:F-8 S: …` / `Qaredi Q:F-8 C: …` · 꼬리 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. push 는 하지 않는다(메인이 한다).
- 이 문맥의 환경(서버 아님 · 운영 없음): PostgreSQL 16 로컬 · DB `tbox_re`(빈 설치 + 검토 계정 다섯 + 개인 공간 있음) · `/etc/tbox-v2/seed-accounts.txt`·`qa-totp.txt`(root 0600 · `sudo -n` 은 root 라 그냥 된다) · `~/.config/qaredi/pgpass-testdb`·`pgpass-testread` · `/var/www/qaredi` → `/home/user/qaredi` 링크 · 운영 `.env` 자리 `/var/www/qaredi/tbox-v2/.env`(있음). 포트 S 8401~8403 · C 8404~8405. 임시 DB 접두 `tbox_qf8_`. 끝나면 임시 인스턴스·DB·폴더를 **이름으로** 지운다.
- 하위 에이전트 0 · 다른 worktree(`/home/user/qaredi/tbox-v2` 는 라운드 1 작업 중) 건드리지 않는다 · 비밀을 출력·커밋하지 않는다 · 시험 기대값을 약화하지 않는다.
- 사람 판단이 필요한 갈림길은 **기본안(좁은 쪽)** 으로 하고 보고에 「판단 대기」로 적는다.
