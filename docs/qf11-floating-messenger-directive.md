# Q:F-11 — 어느 화면에서나 이어지는 메신저 (지시서 A·B, 2026-10-06)

사용자 결정(2026-10-06, 원문 요지는 `../../docs/persistent-messenger-plan.md` v0.2 1.1·8장): 공용 창 = **모아 메신저 하나**, 유지 방식 = **이어 열기 + 떠 있는 창**(PC 비모달 · 휴대폰 아래 시트 · 모든 로그인 화면에 같은 단추 · 같은 탭에서 화면을 옮기면 열림·상대/AI 스레드·스크롤·쓰던 글 복원), 커뮤니티 대화 창은 **대체**, 사진 꾸미기의 AI 단추는 **모아 AI(Gemini)** 로, `/messages` 페이지는 **남김**, 알림은 **배지 숫자만**, PC 도우미(loopback)는 `/messages` 에서만, GPT 라우트 2개·`community.gpt_turns`·`conversations`·`messages` 표는 **휴면/보존**.

기준 문서(읽고 시작): 기획 `../../docs/persistent-messenger-plan.md`(3장 M-1~M-10 · 4장 설계 · 5장 보안 · 6장 Q:T-11~23) · `DESIGN.md` **2.17 떠 있는 창** + 6장 체크리스트 · `SECURITY.md` R-116~R-118 · C:R-10 · `API.md` 「모아 초대 가입·메신저」 표(`request_id`) + 「커뮤니티 대화 창 창구 — 제거·휴면」 · `ARCHITECTURE.md` 13장(5대 방어) · `../community/ARCHITECTURE.md` 「임시저장」 절(보관 열쇠·모양) · `../CLAUDE.md` 2·3·4장.

기능 묶음 = **A(바닥) + B(창 + 대체)**. CLAUDE.md 3.1 대로 검토·회귀·운영 반영은 묶음 끝에 한 번이되, **A 는 보안 바닥이라 먼저 독립 검토만 받는다**(저장·열쇠·멱등을 B 가 기댄다). 운영 반영은 B 까지 끝난 뒤 메인 세션이 한다.

## 공통 — 구현 자리 · 시험 수단 · 금지

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf11-20261006`**(브랜치 `feat/qf11-floating-messenger`, main `057c4a8` 위의 문서 커밋에서 시작 — 메인 세션이 만든다). 운영 `/var/www/qaredi/tbox-v2` 와 운영 DB(`tbox_re`)·운영 8082 는 **건드리지 않는다**(읽기도 하지 않는다). `tests/stage89_cross_regression/run.py:14` 와 `tests/community/memories_harness.py` 는 `/var/www/` 아래에서 돌기를 거부한다 — worktree 에서만 돈다 |
| 격리 PHP+PostgreSQL 틀 | `python3 tests/community/memories_harness.py --suite <파일> --output <결과 폴더>` — 합성 DB(`tbox_memories_test_*`)·임시 복사본·로컬 PHP 서버, 끝나면 삭제. **새 스위트는 `memories_harness.py:37` 의 `--suite` 허용 목록(choices)에 이름을 더해야 돈다**(그 줄만 고친다). 스위트가 틀에 붙는 모양은 `tests/community/memory_story_http.py`(머리 — 공유 fixture·`call`·`check`·`sql` 헬퍼) · `tests/mcp/remote_http.py`(결과 파일에 단언 이름·참/거짓만, 응답·자격 출력 0)를 따른다 |
| 브라우저 | `. /home/changkwon/qaredi-qf11-results-20261006/env.sh` 로 `CHROMIUM_EXECUTABLE`·`PYTHONPATH`(Playwright)·`PATH` **만** 받는다(2026-10-06 A 때 다른 결과 폴더의 `env.sh` 가 셸 변수 `R`·`W` 까지 덮어 결과가 남의 폴더에 쓰이고 `rm -rf` 가 그 폴더에서 돈 사고 — 다른 폴더의 `env.sh` 금지 · 셸 변수는 고유한 이름 · `rm -rf` 는 자기 결과 폴더 안 절대 경로만). 실제 PHP 위의 브라우저 시험은 위 틀 안에서(`memories_runtime_browser.py` 방식), 모의 HTTP 만 쓰는 구성 요소 시험은 `tests/community/memories_browser.py` 의 `Mock`/`render_expressions` 방식 |
| 교차 회귀 | `python3 tests/stage89_cross_regression/run.py --output <폴더>`(빈 합성 DB · 공통 영향 경로) — A·B 각각 끝에 한 번 |
| 기존 메신저 시험 | `tests/member_onboarding.py`(`MOA_TEST_DB=tbox_moa_<이름>` · `MOA_TEST_BASE=http://localhost:<포트>` · `sudo -n -u postgres psql` 필요 — 돌릴 수 있으면 돌리고, 못 돌리면 **못 돌렸다고** 보고) · `tests/moa_local_assist.py` · `tests/moa_local_permissions.py` · `tests/community/messenger_browser.py`(B 에서 **대체** — 아래) |
| 계정·표식 | 격리 틀의 fixture 계정(틀이 만든다 — 운영 아님). 운영 8082 를 치는 시험은 이 지시서에 **없다**. 어쩔 수 없이 운영을 읽을 일이 생기면 멈추고 보고. `claude_` 검토 계정 5개 + UA `tbox-review/<날짜>` 규칙은 메인 세션의 검토 몫. 시드 계정(`admin01` …) 사용 금지. **주의**: 사람 목록 SQL 이 `claude_%`·`qa_%` 아이디를 **뺀다**(`src/handlers/member_messages.php:14`) — 검토 계정끼리의 대화 시험은 목록이 아니라 `GET/POST /api/messages/{num}` 을 번호로 친다 |
| 금지 패턴 | CLAUDE.md 2장 그대로: SQL 문자열 결합 · `innerHTML`(및 `insertAdjacentHTML`·`outerHTML`) · 사용자 입력이 든 파일 경로 · `curl_exec`/`file_get_contents('http…')` · 역할 판단을 `Authz` 밖에서 · 요청의 role 값 · 브라우저 HTML 저장. 더해서: 인라인 `style`/`<script>`/`on*` 속성 0 · 좌표를 JS 로 써 넣지 않음 · `alert/prompt/confirm` 0 · 새 색값·새 `z-index` 숫자 0(토큰 `--z-floating` 하나) · 새 창구 0(라우트 표에 **추가** 없음 — B 는 제거만) · `BroadcastChannel`/`sessionStorage` 에서 받은 값은 **데이터**로만(문자열을 DOM 으로 해석하지 않는다, 숫자·열거값은 정규식/범위 검사 뒤 사용) · 초안 평문 저장 0(열쇠를 못 받으면 쓰지 않는다) · 콘솔 출력 0 · 외부 URL 0 |
| 보고 형식 | 변경 파일 목록 / 실행한 명령과 출력 원문(결과 폴더 경로 포함) / 못 한 것과 이유 / **확정한 값**(저장 열쇠 이름 · 이벤트 이름 · 단추 글자 · 안내 문구 원문) |

---

## 지시서 A — M-A 바닥 (메신저 JS 분리 · 창 상태 · 쓰던 글 암호화 · 로그아웃/계정 바뀜 삭제 · `request_id` 멱등)

```
목표        : 모아 메신저의 화면 JS 를 페이지에서 떼어 「페이지·떠 있는 창」이 같이 쓰는 모듈로 만들고, 창 상태(sessionStorage)·쓰던 글(암호화 localStorage)·
              삭제 규칙·문자 메시지 멱등(request_id)을 넣는다. /messages 페이지는 겉보기·동작이 전과 같다(창은 아직 없다).
요구사항    : Q:F-11 M-3(복원 자료) · M-4(중복 전송 방지) · M-5 · M-8 · M-9 — 기획 4.1 · 4.2 · 4.4 · 5장, SECURITY R-116 · R-118 · C:R-10, API.md 「모아 초대 가입·메신저」 표,
              ../community/ARCHITECTURE.md 「임시저장」 절(보관 열쇠·모양·암호화·쓰는 때·지우는 때), ARCHITECTURE.md 13장 — 해당 절을 읽고 시작할 것
              화면은 바뀌지 않는다(DESIGN 6장 체크는 /messages 가 전과 같은지만).
수정 파일   : public/assets/messenger.js(새 · type="module") · public/assets/member.js(messenger() 를 떼어 내고 모듈을 부른다 · api() 래퍼는 그대로 넘긴다) ·
              public/assets/member-ai.js · public/assets/member-assist.js(모듈이 넘기는 저장/복원 훅을 받는 최소 변경 — DOM id 는 바꾸지 않는다) ·
              public/assets/community/drafts.js(열쇠 종류 `message:`·`ai:` 둘 추가 — 네트워크·DOM 0 규칙 그대로) ·
              public/assets/app.js(로그아웃 처리 한 곳 — clearMyDrafts 옆에서 sessionStorage 의 메신저 상태 열쇠도 지운다) ·
              src/views/member_top.php(/messages 에 모듈 스크립트 한 줄) · src/handlers/member_messages_page.php(바뀔 게 없어야 정상 — 마크업 id 불변) ·
              src/handlers/member_messages.php(POST 에 request_id 필수·멱등·409) ·
              ★ db/migrations/2026-10-06_member_messages_request_id.sql(새 · 추가 전용) · ★ db/schema.sql(새 설치용 같은 열·제약) ·
              ARCHITECTURE.md(「모아 초대 가입·메신저」 절 끝에 request_id 멱등 한 문단) ·
              tests/community/messenger_http.py(새) · tests/community/messenger_store_browser.py(새) · tests/community/memories_harness.py(--suite choices 두 이름 추가만) ·
              tests/member_onboarding.py(POST 에 request_id 를 넣도록 갱신 — 기대값 외 변경 0) · tests/catalog.json(새 시험 2건 등록)
              여기 없는 파일은 만들거나 고치지 않는다. 특히 public/index.php · src/community/routes.php · src/Auth*.php · src/Http.php · deploy/** 는 손대지 않는다.
금지 패턴   : 위 「공통」 표. 더해서: 초안을 서버로 보내지 않는다 · 열쇠(AES 키)를 저장소·전역·쿠키에 두지 않는다 · 다른 사람 접두의 값을 읽지 않는다 ·
              request_id 없는 옛 요청을 「조용히 통과」시키지 않는다(400).
완료 기준   : 아래 A-5 시험 전부 + tests/stage89_cross_regression/run.py 통과 + tests/moa_local_assist.py · tests/moa_local_permissions.py 전과 같은 수 통과 +
              scripts/security_lint.sh 0건 + php -l(바뀐 PHP 전부) + node --check(바뀐 JS 전부; 모듈은 node --check 가 안 되면 node --input-type=module -e 'import(...)' 또는 esbuild 없는 구문 검사 방법을 적는다) +
              /messages 페이지가 전과 같이 동작(사람 대화·AI·도구·이미지·PC 도우미 UI — 격리 틀 브라우저로 확인).
보고 형식   : 「공통」 표의 보고 형식. 확정한 값: sessionStorage 열쇠 이름 · drafts.js 의 새 이름 규칙 · 모듈이 내보내는 함수 서명 · 409 문구 원문.
```

### A-1 모듈 분리 — `public/assets/messenger.js`

- `member.js:102-168` 의 `messenger()` 안 코드를 **그대로 옮긴다**(동작 변화는 아래 A-2~A-4 뿐). 모듈은 `window.MoaMessenger = { mount(options) }` 하나를 내놓고, `mount({ api, me, node, say, date, label, mode: 'page' })` 로 지금 `/messages` 가 쓰던 헬퍼를 **넘겨받는다**(복제 0 — `member.js` 의 `api()`·`node()`·`say()`·`date()`·`label()` 그대로). `mode` 는 `'page'`(붙박이 — 지금) / `'window'`(B 에서) 둘뿐이고, A 에서는 `'page'` 만 쓴다.
- `member.js:190` 의 `if(page==='messages')await messenger();` → `await window.MoaMessenger.mount({...})`. `member.js` 가 먼저 돌고 모듈이 늦게 실릴 수 있으므로, `member_top.php` 에서 모듈 스크립트를 `member.js` **앞**에 두고 `defer`/`module` 순서를 적는다(모듈은 defer 와 같은 시점이지만 선언 순서대로 실행된다 — 확인하고 보고).
- `member-ai.js`·`member-assist.js` 는 `document.getElementById('message-…')` 로 묶여 있다 — **id 를 바꾸지 않는다**. 모듈이 두 파일에 넘기는 것은 지금 `activate`/`context` 콜백 그대로이고, 복원용으로 **두 가지 훅**만 더한다: AI 쪽 `getState()/setState({thread})`(현재 스레드 번호 읽기/열기) · 도구 쪽 `attachment(peerId)` 는 이미 있으니 그대로.
- 모듈은 `import` 로 `./community/drafts.js` 를 가져온다(커뮤니티 `editor.js` 가 가져오는 방식·버전 문자열 규칙 그대로 — 그 줄을 보고 같게).

### A-2 창 상태 — `sessionStorage`(기획 4.1-A)

| 항목 | 값 |
|---|---|
| 열쇠 | `qaredi-messenger:v1:<아이디>` — `<아이디>` 는 `/api/me` 의 `username`(역할로 보기 중에도 실제 계정), 글자 집합 `[a-z0-9_.-]{3,64}` 를 검사한 값만 |
| 값 | `{"v":1,"open":bool,"kind":"person"|"ai","peer":정수|null,"thread":정수|null,"scroll":{"fromBottom":정수},"at":ms}` — 본문·이름 0. 읽을 때 모양·범위를 검사하고 틀리면 **지우고 무시** |
| 쓰는 때 | 상대/스레드를 열거나 바꿀 때 · 창을 열고 닫을 때(A 에서는 페이지라 `open` 은 늘 true 로 두지 않는다 — `open` 은 B 의 떠 있는 창 몫, A 는 `kind/peer/thread/scroll` 만 쓴다) · 목록 스크롤이 멈춘 뒤 300ms · `pagehide` |
| 지우는 때 | `document` 의 `qaredi:logout` 이벤트(app.js 가 쏜다 — `app.js:11812`) · 저장된 `<아이디>` 가 `/api/me` 의 아이디와 다를 때(다른 사람 열쇠는 **값을 읽지 않고** 이름으로만 골라 지운다) · `/api/me` 가 `can_community_write=false` 또는 `acting=true` 일 때 |
| `app.js` | `clearMyDrafts(me.username)` 바로 뒤에서 `sessionStorage.removeItem('qaredi-messenger:v1:' + owner)` — 아이디 검사·try/catch 는 `clearMyDrafts` 와 같은 모양. `qaredi:logout` 이벤트는 이미 그 앞에서 쏘고 있으니 모듈 쪽 처리와 **둘 다** 둔다(한쪽이 없는 화면이 있다 — `/messages` 에는 app.js 가 없고, t-box 셸에는 모듈이 B 전까지 없다) |

### A-3 쓰던 글 — 암호화 `localStorage`(기획 4.2-A · C:R-10 재사용)

- `drafts.js` 에 두 종류를 더한다: `qaredi-draft:v1:<아이디>:message:<상대 번호>`(값 `{text, attachment_id|null}`) · `qaredi-draft:v1:<아이디>:ai:<스레드 번호 또는 0>`(값 `{text}`). 번호는 `^[1-9][0-9]{0,9}$`(ai 는 `0` 도). 기존 페이지·댓글 종류와 **같은 암호화·AAD·7일·20개·sweep·shutdown** 을 탄다. 모듈 안 열쇠는 `extractable=false`, 받은 바이트는 0 으로 덮는다(지금 코드 그대로). **`drafts.js` 는 여전히 네트워크·DOM 에 닿지 않는다** — 열쇠를 받는 함수는 `messenger.js` 가 준다(`api('/community/api/draft-key')` 가 아니라 **`fetch(COMMUNITY_BASE + '/api/me/draft-key')`** — `member.js` 의 `api()` 는 `/tbox` 접두를 붙이므로 쓰지 못한다. 커뮤니티 `editor.js:262` 가 받는 방법을 보고 같은 헤더·`credentials`·`cache:'no-store'` 로).
- 열쇠는 **한 화면 수명에 한 번**만 받는다 — 초안을 **쓰기 시작할 때** 또는 열 때 **그 상대/스레드 항목이 있을 때**. 실패(503·401·WebCrypto 없음)도 기억하고 다시 조르지 않으며 그동안 **저장하지 않는다**(평문 대체 0). 실패 때 입력 칸 옆 `.badge.badge--off` 「임시저장 못 함」(커뮤니티와 같은 글자·클래스).
- 쓰는 때: 입력마다 1초 묶음 · 상대/스레드를 바꾸기 직전 · `pagehide`/`visibilitychange(hidden)` · **401 을 받은 그 순간**(세션 만료 — `say('로그인이 만료되었습니다…')` 직전). 지우는 때: 그 상대/스레드로 **201/200** 전송 뒤 · 입력 칸을 비웠을 때 · 로그아웃(`clearMyDrafts` 접두 삭제 — 코드 추가 0) · 7일.
- 복원: 상대/스레드를 열 때 항목이 있으면 풀어서 입력 칸에 넣는다(지금 메모리 `drafts` Map 과 같은 자리). 풀기 실패 → 조용히 지움. 첨부 대기 이미지는 `attachment_id` 만 — 복원 뒤 `GET /api/messages/assist?peer=&request=` 로 아직 유효한지 확인하고(지금 `member-assist.js` 가 상대를 열 때 하는 일) 없으면 버린다.

### A-4 `request_id` 멱등 — `src/handlers/member_messages.php` + ★ 마이그레이션

- 마이그레이션 `db/migrations/2026-10-06_member_messages_request_id.sql`: `BEGIN; ALTER TABLE public.member_messages ADD COLUMN IF NOT EXISTS request_id uuid; CREATE UNIQUE INDEX IF NOT EXISTS member_messages_request_idx ON public.member_messages(sender_id, request_id) WHERE request_id IS NOT NULL; COMMIT;` — 기존 행은 NULL(옛 글은 멱등 없음, R-118 「남는 것」). 소유자·권한(GRANT) 변경 0. `db/schema.sql` 에 같은 열·인덱스를 새 설치용으로.
- 핸들러: 본문 키는 `['request_id','text']` 또는 `['attachment_id','request_id','text']` 만(정렬 비교 그대로 — 지금 `:24-26`). `request_id` 는 `member_ai.php:21` 과 같은 UUIDv4 정규식. **트랜잭션** 안에서 `SELECT id FROM users WHERE id=:uid FOR UPDATE` → `SELECT id,recipient_id,body FROM member_messages WHERE sender_id=:uid AND request_id=CAST(:r AS uuid)` → 있으면 상대·본문이 같을 때 `{ok:true,id}` **200**, 다르면 **409** 「전송 내용을 확인하고 새로 보내 주세요.」(`messenger_api.php:79` 문구 그대로) → 없으면 지금 흐름(한도 → INSERT 에 `request_id` 포함 → 감사 → 201). 이미지 첨부 갈래(`:30-50`)도 같은 트랜잭션 안으로(첨부 중복 검사 `:36-41` 은 그대로 둔다 — 두 멱등이 겹쳐도 결과는 같다). `Member::rate` 는 **새 행을 만들 때만** 센다(재전송이 한도를 먹지 않게).
- 화면: 모듈이 상대별 초안에 `request_id` 를 붙여 두고(커뮤니티 `messenger.js:14` 의 `drafts.set(key, {text, request})` 방식 — 글이 바뀌면 새 UUID), 전송 실패 뒤 **같은 글** 재전송은 같은 UUID 로. `crypto.randomUUID()` 가 없으면 `messenger.js:11` 의 대체식.

### A-5 시험(완료 기준)

| 시험 | 수단 | 확인 |
|---|---|---|
| `tests/community/messenger_http.py`(새 — 격리 틀) | fixture 사용자 둘(쓰기 자격)·손님 하나 | ① `POST /api/messages/{num}` 에 `request_id` 없음·모양 틀림 → 400 ② 같은 UUID 2회 → 1행, 두 번째 200 `{ok,id}` 같은 id ③ 같은 UUID 다른 본문 → 409, 행 1 ④ 같은 UUID 다른 상대 → 409 ⑤ 동시 2요청(ThreadPoolExecutor) 같은 UUID → 행 1 ⑥ 재전송은 `member_rate_limits` 횟수를 늘리지 않음 ⑦ 첨부 갈래도 ①~③ ⑧ 손님·역할로 보기 → 403 그대로 ⑨ `GET /api/messages/{num}` 응답 모양 불변(`request_id` 는 응답에 내지 않는다) ⑩ 마이그레이션 2회 적용 멱등 · 기존 행 NULL 보존 ⑪ 감사 `member.message.send` 는 새 행에만 1건 |
| `tests/community/messenger_store_browser.py`(새 — 격리 틀 + Chromium, 390·1440) | `/messages` 페이지에서 | ① 상대 A 에 「ㄱㄴㄷ」 입력 → 새로고침 → 같은 글 복원 · `localStorage` 값에 평문 「ㄱㄴㄷ」 0(암호문) · `sessionStorage` 값에 본문 0 ② 전송 201 뒤 그 항목 삭제 ③ 상대 바꿈 → 각자 초안 ④ AI 스레드 초안도 같게 ⑤ 로그아웃(셸 단추가 없는 페이지이므로 `document.dispatchEvent(new CustomEvent('qaredi:logout'))` 와 `app.js` 경로 둘 다 — 후자는 커뮤니티 홈에서 로그아웃 눌러 확인) → 그 사람 접두 0건 · 메신저 상태 열쇠 0건 ⑥ 사용자 B 로 같은 탭 로그인 → A 의 초안·상태가 보이지 않고 A 접두 항목 삭제 ⑦ 열쇠 창구 503 흉내(APP_SECRET 짧게 한 두 번째 인스턴스 — `memories_harness.py` 의 두 번째 서버 방식) → 배지 「임시저장 못 함」·저장 0 ⑧ 콘솔 0 · 가로 넘침 0 ⑨ Gemini 대화·도구 창·이미지 첨부·PC 도우미 단추가 전과 같이 보이고 동작(합성 provider) |
| 회귀 | `tests/stage89_cross_regression/run.py` · `tests/moa_local_assist.py` · `tests/moa_local_permissions.py` · `tests/member_onboarding.py`(갱신 뒤, 돌릴 수 있으면) | 전과 같은 통과 수 |
| 정적 | `scripts/security_lint.sh` · `php -l` · 구문 검사 · `git diff --check` | 0건 |

A 가 끝나면 **멈춘다** — 메인 세션(Fable)이 독립 검토(CLAUDE.md 3장)를 한 뒤 B 를 시작한다.

---

## 지시서 B — M-B 창 + M-C 대체 (떠 있는 창 · 모든 껍데기의 단추 · 복원 · 폴링·배지 · 여러 탭 · 커뮤니티 창 대체 · 꾸미기 → 모아 AI)

```
목표        : A 의 모듈을 모든 로그인 화면에서 「떠 있는 창」(PC 비모달 · 휴대폰 아래 시트)으로 띄우고, 화면을 옮겨도 같은 탭에서 복원되게 하며,
              커뮤니티 대화 창을 이 창으로 대체하고 사진 꾸미기의 AI 단추를 모아 AI 칸으로 잇는다.
요구사항    : Q:F-11 M-1 · M-2 · M-3 · M-4 · M-6 · M-7 · M-8 · M-10 — 기획 4.3 · 4.4-B · 4.5 · 4.6-A · 4.7 · 4.8, DESIGN.md 2.17 + 6장 체크리스트(새 클래스·색값·z-index 숫자를 만들지 않는다 — 토큰 --z-floating 하나),
              SECURITY R-117, API.md 「커뮤니티 대화 창 창구 — 제거·휴면」, ARCHITECTURE.md 13장, ../docs/launcher/ARCHITECTURE.md 5장(런처는 세션 읽기만·Set-Cookie 0) — 해당 절을 읽고 시작할 것
수정 파일   : src/views/messenger_window.php(새 · 창 마크업 — /messages 의 section 과 같은 id) · src/views/shell_top.php(:274-276 messenger.css link 제거 · :333 · :503 단추를 두 모드 공용으로, 글자 「메신저」) ·
              src/views/shell_bottom.php(:193 community_messenger.php include → messenger_window.php + 스크립트 셋, 로그인 셸 전부) ·
              src/views/member_top.php(:18 nav 「메신저」 — /messages 가 아닐 때 단추 · 스크립트) · src/handlers/member_welcome_page.php(</main> 앞 창 include) ·
              src/views/launcher.php(:26 타일 → 단추(링크 보조) · 로그인 때만 창 include + 스크립트) · src/site/launcher_page.php(바뀔 게 없어야 정상) ·
              src/handlers/member_messages_page.php(:5 CSP 불변 · 머리에 창이 뜨지 않도록 body 표식 하나) ·
              public/assets/messenger.js(mode 'window' · 복원 · 폴링 · 배지 · 대표 탭 · BroadcastChannel · qaredi:messenger/qaredi:ai-result) · public/assets/member-assist.js(MoaLocalBridge 가 없을 때 「내 PC 의 ChatGPT」 → 안내 + /messages 링크) ·
              public/assets/member-ai.js(요청에서 온 답변에 「꾸미기 화면으로」 단추 — 모듈이 준 콜백이 있을 때만) · public/assets/app.css(dialog.floating 한 벌 + :root --z-floating) · public/assets/moa.css(창 안쪽 재사용에 필요한 최소 — 새 색 0) ·
              public/assets/community/memories.js(:570 studio-chat → {mode:'ai', prompt} · :587 qaredi:ai-result) · src/community/handlers/memories_page.php(꾸미기 창 단추 글자·안내 문구 — Gemini/Google 기준) ·
              src/community/handlers/overview_page.php(:35) · src/community/handlers/memories_page.php(:66) ·
              삭제: src/views/community_messenger.php · public/assets/community/messenger.js · public/assets/community/messenger.css · tests/community/messenger_browser.py ·
              ★ src/community/routes.php(:136-143 — 사람 대화 6개 제거, gpt 2개 유지) · src/community/handlers/messenger_api.php(gpt 두 갈래만 남김) · src/community/Messenger.php(gpt 관련 함수만 남김) ·
              tests/community/messenger_window_browser.py(새) · tests/community/memories_harness.py(choices 한 이름 추가만) · tests/catalog.json(새 1건 등록 · messenger_browser.py 항목 있으면 제거) · tests/browser/dbg_u2_ui.py(떠 있는 창을 창 바닥 규칙 대상에 넣는 최소 변경) ·
              DESIGN.md(2.17 「확인」 결과 한 줄 — 수치) · docs/design-guide.html(떠 있는 창 견본 한 칸, 있으면)
              여기 없는 파일은 만들거나 고치지 않는다. public/index.php · src/bootstrap.php · deploy/** · src/Auth*.php · src/Http.php · db/** 는 손대지 않는다(새 라우트 0 · CSP 불변).
금지 패턴   : 「공통」 표. 더해서: showModal 로 창을 열지 않는다 · 복원으로 열릴 때 초점을 옮기지 않는다 · 창이 닫혀 있을 때 aria-live 로 읽어 주지 않는다 · 소리·Notification·문서 제목 변경 0 ·
              창 상태에 본문·이름 0 · 역할 문자열 비교 0(/api/me 의 can_community_write · acting 만) · /messages 밖에서 127.0.0.1:43821 로 요청하는 코드 경로 0 · 「GPT」「OpenAI」 글자를 화면·JS 에 남기지 않는다(휴면 서버 코드 제외).
완료 기준   : 기획 6장 Q:T-11 · 12 · 13 · 16 · 17 · 18 · 19 · 20 · 21 · 22 · 23 전부(B-6) + A-5 재실행 + tests/stage89_cross_regression/run.py + scripts/security_lint.sh 0건 + php -l · 구문 검사 · tests/browser/dbg_u2_ui.py · scripts/ui_snapshot.sh(1440 · 1280) 결함 0 · 콘솔 0.
보고 형식   : 「공통」 표의 보고 형식. 확정한 값: 이벤트 이름 둘(qaredi:messenger 의 detail 모양 · qaredi:ai-result) · 단추 글자 · 안내 문구 원문 · 폴링 상수 · BroadcastChannel 이름 · 2.17 「확인」 수치(창 좌표 · 겹침 0 증거).
```

### B-1 창 마크업 — `src/views/messenger_window.php`

- `<dialog id="messenger-window" class="floating" aria-labelledby="messenger-window-title" hidden-until-mount>` 안에 **`/messages` 의 `section.moa-conversation` + `aside.moa-people` 과 같은 id 들**(`message-people`·`message-search`·`message-ai`·`message-ai-tools`·`message-list`·`message-form`·`message-text`·`message-send`·`message-older`·`message-assist-toolbar`·`message-attachment`·`message-peer-name`·`message-peer-meta`·`message-ai-notice`…) — `member-ai.js`·`member-assist.js` 가 그대로 돌게. 머리: 제목 「메신저」 + `<a class="btn btn--quiet btn--small" href="<?= Http::url('/messages') ?>">크게 보기</a>` + ✕(`.btn--icon[aria-label="닫기"]`) + 좁은 폭 바닥 「닫기」. 「‹ 목록」 단추(사람·AI 목록 ↔ 대화). `member_assist.php` 뷰(도구 창)도 함께 include(없는 화면에서는 「AI 도구」가 동작하지 않는다).
- 서버가 그리는 조건: `$shellUser`(또는 그 화면의 `$user`)가 있고 `Authz::canCommunityWrite` 이고 `acting` 이 아닐 때만 마크업을 내보낸다(DESIGN 2.9 「쓰는 단추는 쓸 수 있는 사람에게만」 H3 와 같은 자리 — 역할 비교가 아니라 판정 함수). JS 는 다시 `/api/me` 의 `can_community_write`·`acting` 로 확인한다(둘 중 하나라도 아니면 mount 하지 않고 저장소를 지운다).
- `/messages` 페이지: `<body data-member-page="messages">` 에서는 창을 **include 하지 않는다**(페이지가 붙박이) — 모듈은 `mode:'page'`.
- 런처: `$signed` 일 때만 include + 스크립트 셋(`messenger.js` 모듈 · `member-ai.js` · `member-assist.js`). 미로그인 런처는 마크업·스크립트 0, `Set-Cookie` 0 그대로(`q_launcher.sh` 의 스크립트 수 기대값을 로그인 쪽만 +3 으로 갱신하고 보고). `/api/me` 는 런처에서 `start.js` 가 이미 부르면 그 결과를 쓰고(전역이 있으면), 없으면 모듈이 한 번만 부른다 — **화면당 `/api/me` 요청이 둘 이상 늘지 않는다**(셸은 `window.qarediMe` — `app.js:825`).

### B-2 CSS — `app.css` 의 `dialog.floating` 한 벌(DESIGN 2.17 표 그대로)

- `:root { --z-floating: 60; }`(다크·고대비 블록에는 넣지 않는다 — 색이 아니다). `dialog.floating { position:fixed; right:16px; bottom:72px; width:400px; height:min(640px, 100dvh - 96px); z-index:var(--z-floating); margin:0; padding:0; border:1px solid var(--line); border-radius:var(--r-card); background:var(--surface); color:var(--fg); box-shadow:var(--shadow-lg); overflow:hidden; display:none; } dialog.floating[open] { display:flex; flex-direction:column; }` · 720px 미만: `inset:auto 0 0 0; width:100%; height:88dvh; max-height:88dvh; border-radius:var(--r-card) var(--r-card) 0 0; border-left:0; border-right:0;` + safe-area 바닥 여백 + 입력 글자 16px. 창 안쪽은 `moa.css` 의 `.moa-person`·`.moa-bubble`·`.moa-compose`·`.moa-message-list` 그대로(폭 400 에 맞는 최소 보정만 — 새 색·새 클래스 이름 0, 필요한 보정은 `dialog.floating .moa-…` 선택자로).
- 사용 안내 단추(`usage-guide.css` `bottom:16px; right:16px`)와 **겹치지 않는다** — 바꾸지 않는다.

### B-3 모듈 `mode:'window'` — 열기·복원·폴링·배지·여러 탭

| 항목 | 규칙 |
|---|---|
| 단추 | 모든 `[data-messenger-open]`(셸 사이드바·폰 윗줄·모아 nav·런처 타일·커뮤니티 개요·스레드) 에 click → `open({focus:true})`. 안 읽은 수는 모든 `[data-message-unread]` 에 같은 값(`.badge.badge--internal`, 0 이면 `hidden`) |
| 열기 | `dialog.show()`(비모달). 사람이 열면 입력 칸에 초점. 닫기(✕·「닫기」·Esc)는 `close()` → 상태 `open:false` 저장, 초점은 열었던 단추로(복원으로 열렸다 닫히면 초점 그대로) |
| Esc | `dialog` 안 `keydown` 에서 `Escape` 이고 `e.target` 이 창 안이며 `isComposing` 아닐 때만 — `preventDefault` 뒤 `close()` |
| 복원 | 모듈이 mount 되면 A-2 의 상태를 읽어 `open` 이면 **초점을 옮기지 않고** `show()` → `kind/peer/thread` 로 열고 → 목록을 그린 뒤 `scroll.fromBottom` 을 적용(맨 아래였으면 맨 아래). 초안은 A-3 로 입력 칸에. 상태의 상대가 `GET /api/messages/{num}` 404 면 상태를 비우고 목록만 보인다 |
| 폴링 | 열림+보임: 현재 대화 + 사람 목록 8초(`member.js:167` 그대로) · 닫힘+보임: `GET /api/messages?q=` 30초, `unread` 합만 배지에 · 숨김: **정지**, 다시 보이면 즉시 1회(`editor.js:288` `whileVisible` 과 같은 모양을 모듈 안에 작게 — editor.js 를 import 하지 않는다) · 모든 자동 요청은 `api(..., passive=true)`(`X-Qaredi-Background: 1`) · 사람이 누른 보내기·「이전 대화」만 일반 요청 |
| 대표 탭 | `navigator.locks` 가 있으면 `navigator.locks.request('qaredi-messenger:' + 아이디, {mode:'exclusive'}, () => 영원히 기다리는 Promise)` 로 대표를 잡고, 대표만 폴링한다. 결과(사람 목록·현재 대화 응답·안 읽은 합)를 `new BroadcastChannel('qaredi-messenger:' + 아이디)` 로 `postMessage({v:1, kind:'people'|'messages'|'unread'|'sent'|'read', peer, data})`. 받는 쪽은 **모양·범위 검사** 뒤 데이터로만 그린다(`node()`+`textContent`). `navigator.locks` 가 없으면 탭마다 폴링(기획 4.4-A) — 어느 길인지 보고. 전송 뒤 `sent` 를 뿌려 다른 탭의 같은 상대 초안을 지우고(A-3 삭제 규칙과 같이) 목록을 갱신 |
| `/messages` 와 창 | 같은 탭에서 `/messages` 로 가면 페이지가 상태를 그대로 이어 받는다(`mode:'page'` 도 A-2·A-3 를 읽는다). `/messages` 에서 다른 화면으로 가면 창이 `open:true` 로 복원되도록 페이지 모드는 `open` 을 true 로 쓴다 |
| PC 도우미 | `member-assist.js`: `window.MoaLocalBridge` 가 없으면(창) 「내 PC 의 ChatGPT」 를 고를 때 안내 한 줄 「PC 비서 연결은 메신저 전체 화면에서 할 수 있습니다.」 + `크게 보기` 와 같은 `/messages` 링크. `/messages` 밖에서 `127.0.0.1:43821` 로 가는 코드 경로가 **없어야** 한다(CSP 가 막기 전에 코드가 안 간다) |

### B-4 커뮤니티 창 대체 · 꾸미기 → 모아 AI

- 지우기: `community_messenger.php` · `community/messenger.js` · `messenger.css` · `shell_top.php:274-276` link · `shell_bottom.php:193` include. 단추 글자: `shell_top.php:503` 「메신저 · 회원 / GPT」 → 「메신저」(아이콘은 `bell` 대신 셸 아이콘 중 말풍선이 있으면 그것, 없으면 그대로 — 새 SVG 를 만들지 않는다), `:333` 폰 윗줄은 두 모드 공용 `.btn--icon`(종 옆, 터치 ① 규칙 — 사이 12px).
- ★ `routes.php:136-143`: 사람 대화 6개 삭제, `GET/POST /api/messenger/gpt` 두 줄은 **그대로**. `messenger_api.php`: gpt 두 갈래와 404 만 남긴다. `Messenger.php`: `requireReady`·`payload`·`gptEnabled`·`gptHistory`·`gpt`·`audit` 만 남긴다(`member`·`conversation`·`participant`·`message` 삭제 — 다른 곳에서 부르는지 `grep` 으로 0 확인하고 보고).
- `memories.js:570`: `qaredi:messenger` 의 detail 을 `{ mode:'ai', prompt }` 로. 모듈은 `mode:'ai'` 면 창을 열어(사람이 눌렀으니 초점 이동) **모아 AI** 칸으로 바꾸고, 현재(또는 새) 스레드 입력 칸에 `prompt` 를 **미리 채운다**(기존 초안이 있으면 두 줄 띄고 뒤에 붙임 · 4,000자 초과면 자르고 `say` 로 알림) — **자동 전송 0**. 그 요청에서 보낸 질문의 답변 말풍선에만 「꾸미기 화면으로」 단추(`member-ai.js` 가 모듈이 넘긴 `onResult` 콜백이 있을 때만 그린다 — `messenger.js:26-28` 의 `fromStudio` 방식) → `window.dispatchEvent(new CustomEvent('qaredi:ai-result', {detail:{text}}))` + 창 닫기. `memories.js:587` 은 `qaredi:ai-result` 를 받아 `#studio-result` 에 넣고 꾸미기 창을 다시 연다(지금 코드 그대로, 이벤트 이름만).
- `memories_page.php` 의 꾸미기 창: 단추 글자 「GPT 와 대화」 → **「모아 AI 에게 묻기」**, 안내 문장은 `member_messages_page.php:25` 의 「AI에게 보낸 내용과 최근 AI 대화가 Google로 전송됩니다. 무료 API에는 개인정보·기밀을 입력하지 마세요.」 와 같은 뜻으로(사진·영상은 전송되지 않는다는 말을 덧붙인다). 「ChatGPT 열기 ↗」 복사 흐름(`copy-prompt`)은 그대로(그건 사용자가 직접 붙여 넣는 길이다).
- AI 가 꺼져 있을 때(`/api/messages/ai` 503 등): 모아 AI 칸의 기존 「키 미등록」 상태 문구가 그대로 보인다 — 새 문장을 짓지 않는다.

### B-5 `tests/community/messenger_browser.py` 대체

- 그 파일(커뮤니티 모달 전용, 모의 HTTP)은 **삭제**하고 `tests/community/messenger_window_browser.py`(격리 틀 + 실제 PHP + Chromium — `memories_runtime_browser.py` 방식, `--suite` 에 등록)로 바꾼다. 카탈로그에 옛 항목이 있으면 지우고 새 항목을 `covers`(이 지시서의 수정 파일)와 함께 등록한다.

### B-6 시험(완료 기준) — 기획 6장 번호

| 시험 | 수단 | 확인 |
|---|---|---|
| Q:T-11 단추 | `messenger_window_browser.py` — fixture 직원으로 t-box 셸 화면 15 종 중 대표 6(홈·설정·활동·내 휴지통·위키·관리 허브는 관리자 fixture) · 커뮤니티 9 · `/welcome` · 런처 | 같은 id 의 단추 1개씩 · `/messages` 에 창 없음 · 손님 fixture 와 역할로 보기 중에는 단추·창 마크업 0 · 미로그인 런처는 창 마크업·스크립트 0·`Set-Cookie` 0 |
| Q:T-12 이동 복원 | 커뮤니티 글 → 자료실 홈 → 설정 → 런처 | 창 열림·상대 같음·입력 「ㄱㄴㄷ」·스크롤(맨 아래 아님) 같음 · AI 스레드로 바꾼 뒤 이동 → 같은 스레드 · 복원 때 `document.activeElement` 가 창 밖 |
| Q:T-13 여러 탭 | 같은 context 에 탭 둘 | 탭 1 전송 → 탭 2 초안 비고 목록에 뜸(≤8초) · 배지 같음 · DB 행 1 · 두 탭 같은 UUID 동시 전송 → 행 1 · 대표 탭을 닫으면 다른 탭이 폴링을 잇는다(네트워크 기록) |
| Q:T-14·15 | A-5 ⑤⑥ 를 창에서 다시 | 로그아웃 뒤 접두·상태 0 · B 로 로그인 → 창 닫힘·초안 없음 |
| Q:T-16 폭·테마 | 390 · 412(터치·마우스) · 820 · 1440 × 밝게·어둡게 × 고대비 | 가로 넘침 0 · 콘솔 0 · <720 아래 시트(좌표 측정) · ≥720 창 아래 모서리가 사용 안내 단추 위(`getBoundingClientRect` 로 겹침 0) · 누름 자리 44 · `scripts/ui_snapshot.sh` 1440·1280 결함 0 |
| Q:T-17 비모달 | 창 연 채 t-box 파일 표 정렬·링크·스크롤 | 동작 · 창 밖 클릭에 안 닫힘 · Esc 는 초점이 창 안일 때만 · 재인증 창(`/tbox/admin` 진입)·확인 창·AI 도구 창이 창 **위** |
| Q:T-18 폴링 | Playwright 요청 기록 60초 | 닫힘 30초 1회 `X-Qaredi-Background: 1` · 열림 8초 · 숨김(`page.evaluate` 로 visibilityState 흉내 또는 두 번째 탭 활성) 0 · DB `sessions.last_seen_at`(이름은 `Auth.php` 에서 확인)이 자동 요청으로 늘지 않음 |
| Q:T-19 대체 | 커뮤니티 9 화면 | `#community-messenger` 0 · 6개 라우트 404 · `GET/POST /community/api/messenger/gpt` 503(세션+쓰기 자격) · 화면·`public/assets` 에 「GPT」「OpenAI」 글자 0(`grep`, 휴면 서버 코드 제외) |
| Q:T-20 꾸미기 | 스레드 「AI 로 꾸미기」 → 「모아 AI 에게 묻기」 | 창이 AI 칸으로 열리고 요청문이 입력 칸에(전송 0) → 보내기(합성 provider) → 답변에 「꾸미기 화면으로」 → `#studio-result` 에 답변·꾸미기 창 열림 · 다른 답변엔 단추 없음 · 안내 문구 원문 보고 |
| Q:T-21 회귀 | A-5 전부 · `moa_local_*.py` · `member_onboarding.py`(가능하면) · `/messages` 의 CSP 두 헤더 값(격리 틀은 PHP 헤더만 — nginx 는 메인 세션 몫으로 「미검증」 표기) | 전과 같은 수 |
| Q:T-22 정적 | `security_lint.sh` · `php -l` · 구문 · `tests/browser/dbg_u2_ui.py` · `php scripts/wiki_check.php`(위키는 메인 세션 몫 — 0건이 아니면 보고만) | 0건 |
| Q:T-23 사이트 회귀 | `tests/stage89_cross_regression/run.py` · `tests/q_launcher.sh` 의 기대값(로그인 런처 스크립트 수) 갱신 | 통과 |

### B-7 A 독립 검토 반영 — 경미 항목 (2026-10-06 Fable 검토 `~/qaredi-qf11-results-20261006/review-A/REPORT.md` · 판정 통과: 차단 0 · 중요 0 · 경미 6)

수정 파일(B 의 목록에 **더한다**): `scripts/community_asset_version.php` · `tests/moa_local_permissions.py` · `tests/moa_local_assist.py`.

| 검토 번호 | 할 일 |
|---|---|
| 3 부품 판 스크립트 공백 | `scripts/community_asset_version.php` 가 `public/assets/messenger.js` 의 `community/drafts.js?v=` import 판도 찍고(`--check` 가 어긋남을 잡게) 검사한다. 스크립트를 돌린 전후 출력 원문 보고 |
| 4 글자 수 규칙 시험 공백 | `tests/moa_local_permissions.py:44` 근처 「4001 code points」 요청에 유효한 `request_id` 를 넣어, 400 이 **글자 수 문구**(「메시지를 1~4,000자로 입력해 주세요.」)로 나는지 단언한다 |
| 5 `moa_local_assist.py` 2건 | 모의 서버에 `GET /community/api/me/draft-key` 합성 열쇠(`{"v":1,"key":…}` 32바이트 base64url)를 더하고, 「저장소 0」 단언은 메신저 창 상태 열쇠를 빼고 **PC 도우미 자격 증명이 저장소에 없음**을 본다. 기준선에서도 앞쪽 묵은 단언(「welcome explains unconfirmed connection and private repository」)에서 멈추던 것은 원인을 `git log -S` 로 찾아 보고하고, 화면 문구가 의도적으로 바뀐 것이 확인될 때만 기대값을 맞춘다 — 뒤 단언이 실제로 돌았다는 출력 원문 필수 |
| 2 보고 ⑪ 정정 | `messenger.js:45` 는 `data-community-base` 를 **읽는다**(A 보고와 다름). 창 모드로 커뮤니티·t-box·런처 화면에 뜰 때 그 값이 무엇인지 확인해 보고(코드 변경은 필요할 때만) |
| 1 전송 한도 계산 | `src/handlers/member_messages.php` 는 B 범위 밖 — **사용자 결정 대기**. 결정되면 메인 세션이 따로 지시한다 |
| 6 초안 20개 공용 상한 | 지시서대로 둔다(보안 영향 0) — 변경 없음 |

B 가 끝나면 멈춘다 — 메인 세션이 묶음 전체 diff 로 독립 검토·회귀·운영 반영(마이그레이션 → 코드 → 검증)·`STAGES.md`·`wiki/qaredi`·`SECURITY.md` 7장·발행 v2.50.0 을 한다.
