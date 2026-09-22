# 1·2단계 지시서 (Fable → Opus) — 커뮤니티: t-box 골격 이식 + 계정 공유 + 게시판

> Fable 작성 2026-09-21. 사용자 결정 Q-1(별개 앱 `/var/www/community` · t-box 계정 공유 · t-box 골격 복사).
> **문서가 먼저다**: `PRD.md`(4장 권한 표 · 5장 F-01~F-12 · 6장 S-01~S-10 · 7장 API · 8장 표 · 10장 T-1~T-13), `ARCHITECTURE.md`(4장 관문 · **5장 계정 공유** · 6장 DDL · 7장 흐름 · 8장 감사 문장 표 · 9장 서버 설정), `SECURITY.md`(R-01~R-10), `CLAUDE.md`, `DESIGN.md`. **전부 읽고 시작할 것. ARCH 5·6·7·8·9장이 곧 명세다 — 어긋나면 멈추고 보고.**
> 원본 t-box 는 `/var/www/tbox-v2` 다. **읽고 복사만 한다. 그 저장소의 파일·git 상태를 한 글자도 바꾸지 않는다.**

```
목표        : (1부) t-box 골격을 이 저장소로 옮겨 t-box 계정으로 로그인·로그아웃·재인증·상태 확인이 되는 앱을 만들고,
              같은 DB 안 스키마 `community` 와 역할 둘·최소 권한 grants·설치 스크립트·nginx/php-fpm 설정을 준비한다.
              (2부) 그 위에 게시판·글·댓글(F-03~F-09)과 화면·도움말·감사 문장 표를 만든다.
              t-box 는 코드·표·동작이 한 글자도 바뀌지 않는다(G-4).
요구사항    : PRD 3·4장 · F-01~F-12 · S-01~S-10 · 7·8장 · T-1~T-13, ARCH 2~9장, SECURITY R-01~R-09, DESIGN.md 6장 체크리스트
수정 파일   : 1장 허용 목록(이 저장소 안). t-box 저장소는 금지.
금지 패턴   : t-box 파일 수정 · 운영 DB DDL/DML(임시 DB 로만 검증) · 시스템 설정 적용(install.sh 는 **작성만**, 실행은 사용자) ·
             `git add -A` · 커밋 · 시드 계정(admin01…) 사용 · innerHTML 류 · SQL 문자열 결합 · curl_*/소켓/file_get_contents('http') ·
             역할 문자열 비교를 Authz.php 밖에서 · `X-API-Token`/`api_tokens`/`act_*`/`Fetch`/`Storage`/`Upload`/`Preview` 코드 잔존 ·
             `community` 스키마에 users/teams/permissions 이름의 표 · 마크다운/HTML 렌더링 · 비밀번호·OTP·토큰을 응답·감사·로그에
완료 기준   : 3장 (T-1~T-13 중 HTTP 없이 가능한 것 전부 + 정적 검사 + 임시 DB 검증). HTTP 시험은 사용자 install.sh 뒤 Fable 이 돈다.
보고 형식   : 4장 — 1부·2부 따로.
```

## 1. 수정(생성) 파일 — 허용 목록 (전부 `/var/www/community/` 아래)

**복사해 다듬는 것** (원본 → 대상. 원본에서 이 앱에 없는 기능을 **지운다** — 아래 2장에 무엇을 지우는지 적었다)
- `src/bootstrap.php` `src/Env.php` `src/Db.php` `src/Http.php` `src/Auth.php` `src/Authz.php` `src/Audit.php` `src/Totp.php` `src/Changelog.php`
- `public/index.php` · `src/views/shell_top.php` `shell_bottom.php` · `src/handlers/login_page.php` `login.php` `logout.php` `me.php` `health.php` `reauth.php`
- `public/assets/app.css`(**그대로** + 끝에 커뮤니티 절) · `public/assets/app.js`(공통부만 옮기고 나머지는 새로)
- `scripts/security_lint.sh` `scripts/release.sh` `scripts/changelog_md.php` `scripts/totp_code.php`(시험용) · `deploy/php-fpm-pool.conf` · `deploy/logrotate.d/community` · `deploy/install.sh`
- `src/help/screens.php`(구조만, 글은 새로)

**새로 쓰는 것**
- `db/schema.sql` `db/grants.sql` `db/seed.sql`
- `deploy/sites/community-common.conf` `community-local.conf` `community.conf`
- `src/handlers/home.php` `boards_list.php` `boards_create.php` `boards_update.php` `posts_list.php` `posts_create.php` `posts_get.php` `posts_update.php` `posts_delete.php` `comments_create.php` `comments_delete.php`
- `tests/stage1.sh` `tests/stage2.sh` · `tests/lib.sh`(공통 login/reauth 함수 — t-box `tests/stage26.sh` 의 `login()`·`reauth()` 를 BASE 8083·쿠키 이름만 바꿔)
- `CHANGELOG.md`(scripts/changelog_md.php 로 생성)

**손대지 않는 것**: `PRD.md` `ARCHITECTURE.md` `SECURITY.md` `CLAUDE.md` `DESIGN.md` `README.md` `.env.example` `VERSION` `CHANGELOG.json`(Fable 몫). `STAGES.md` 는 1·2단계 절의 「① 구현」 체크 표만.

## 2. 무엇을 어떻게 (ARCH 가 우선 — 여기는 구현 메모)

### 2-0. 이름 바꾸기 규칙
- PHP 상수 접두 `TBOX_` → `COMM_` (`COMM_ROUTES`·`COMM_ROUTE`·`COMM_REQUEST_ID`·`COMM_HANDLER_DIR`·`COMM_PATH_PATTERNS`·`COMM_LOGIN_PATH`·`COMM_HOME_PATH`·`COMM_GATE_ALLOW`). 함수 `tbox_uuid_v4` → `comm_uuid_v4`, `tbox_validate_routes` → `comm_validate_routes`, `tbox_pattern_to_regex` → `comm_pattern_to_regex`. 로그 접두 `[tbox]` → `[community]`. `Audit::autoDetail` 이 보는 상수 이름도 함께.
- 쿠키 `Auth::COOKIE_NAME = 'comm_session'`. 테마 쿠키 `tbox_theme` 는 **그대로**(공유 의도, R-09).
- 화면 문구·브랜드: 「t-box」 → 「커뮤니티」, 부제 「t-box 계정으로 로그인」. `<title>` 은 「… · 커뮤니티」.
- 주석 속 요구사항 ID(`PRD S-03`, `ARCH 4.1` …)는 이 저장소 문서의 번호로 고치거나, 원문이 t-box 라면 `t-box PRD S-03` 처럼 앞에 `t-box` 를 붙인다. 뜻이 맞지 않는 주석은 지운다 — 거짓 주석은 없는 것보다 나쁘다.

### 2-1. `src/Auth.php` — 지울 것·바꿀 것
- 지운다: `requireApiToken`·`SQL_FIND_API_TOKEN`·`SQL_TOUCH_API_TOKEN`·`api_tokens` 관련 전부, `act_*`(`ACT_*` 상수·`SQL_CLEAR_ACT`·`SQL_LOOKUP` 의 act 열·`act.expire` 기록·`real_role`/`acting` 키), `changePassword`·`SQL_SET_PASSWORD`·`generateTempPassword`·`verifyOtpOnce`·`verifyCurrentPassword`(재인증은 `reauth()` 만 남긴다), MFA 등록 관련.
- `SQL_LOOKUP`: `FROM sessions s JOIN users u` **그대로**(스키마 이름을 붙이지 않는다 — ARCH 5.1 search_path). `SQL_TOUCH` 는 `last_seen_at = now()` 만.
- `currentUser()` 의 배열: `id`·`username`·`role`·`team_id`·`session_id`·`mfa_enabled`·`must_change_password`·`mfa_setup_required`·`reauth_fresh`·`reauth_at`. **`role` 은 언제나 users 행 값.**
- `nextStep(array $user): ?string` → `must_change_password` 또는 (`mfaRequired` 이고 `mfa_enabled` 거짓)이면 `'tbox'`, 아니면 `null`. `MFA_REQUIRED_ROLES = ['Admin']` (t-box 와 같은 값 — 주석에 「t-box 와 같아야 한다, ARCH 5.5」).
- `login()` 5)~8) 그대로. `countFailure`·`countFailureAndRevoke`·`markMfaStep`·`isMfaStepUsed`·`reauth()`·`logout()`·`revokeAllSessions`·`csrfToken()` 유지. `SQL_COUNT_FAILED`·`SQL_SET_MFA_STEP`·`SQL_CLEAR_FAILED` 는 `users` 의 **그 3열만** 건드린다(그대로다 — 확인만).

### 2-2. `src/Authz.php`
- 남긴다: `POLICY_*`·`KNOWN_POLICIES`(= `public`·`authenticated`·`post.write`·`board.manage`)·`REAUTH_POLICIES = ['board.manage']`·`CODE_*`(파일 전용 `DOWNGRADE`·`PARENT_NARROWER`·`SELF_CHANGE`·`LAST_ADMIN` 은 지운다)·`LEVEL_RANK`·`SQL_MATRIX`·`matrix()`·`flag()`·`readableLevels()`·`roleOf()`·`seesAllTeams()`·`visibleWhere(u, alias)`(별칭 허용 목록 **`['b']`** 하나, `$alive` 는 `TRUE` — 게시판 활성 여부는 핸들러가 따로 다룬다)·`decideRead()`·`decideOwnership()`·`requiresReauth()`·`authorize()`.
- 지운다: `visibleFilesWhere`·`decideUploadAs`·`decideDelete`·`decideEdit`·`decideLevelChange`·`uploadableLevels`·`can*` 파일·조직·계정·위키·fetch·폴더 함수 전부·`decideUserChange`·`decideShareRevoke`·`decideFolderChange`·`fitsUnderFolder`·`canUploadExec`.
- 추가한다(전부 `public static`, 배열 `[bool, code]` 또는 bool):
  - `canWrite(array $u): bool` — t-box `canUploadAtAll` 과 같은 몸(매트릭스 `upload` 가 하나라도 참).
  - `canManageBoards(array $u): bool` — `roleOf($u) === 'Admin'`.
  - `decidePostCreate(array $u, array $board): array` — `decideRead` 실패면 그 코드 · `!canWrite` → `CODE_ROLE_NOT_PERMITTED` · `$board['notice'] === true && !canManageBoards` → `CODE_ROLE_NOT_PERMITTED` · 통과면 `decideRead` 의 허용 코드.
  - `decideCommentCreate(array $u, array $board): array` — `decideRead` + `canWrite` (공지 무관).
  - `decidePostChange(array $u, array $row): array` — `$row` 에 `author_id`·`team_id`(게시판의) 가 있다. `decideOwnership($u, ['uploaded_by' => $row['author_id'], 'team_id' => $row['team_id']])` 를 그대로 돌려준다. `decideOwnership` 의 인자 이름은 `$file` → `$row` 로 바꾸고 주석을 이 앱 말로.
  - `authorize()`: `post.write` → `canWrite($u)` · `board.manage` → `canManageBoards($u)` · 알 수 없는 정책 false.

### 2-3. `src/Audit.php`
- `SUMMARY_TEMPLATES` 를 **ARCH 8.2 표 그대로** 옮긴다(t-box 와 같은 키는 t-box 파일에서 글자 그대로 복사). 표에 없는 키는 지운다(파일·폴더·토큰·역할로 보기·조직·계정 문장 전부).
- `ALLOWED_DETAIL_KEYS`: `next`·`reason_text` 등 남길 것만 + 새 키는 금지 목록 부분 일치에 걸리지 않는지 확인(`board_id`·`post_id`·`notice`·`target_team`·`changed`·`before`·`after`·`granted_by` — 걸리는 것이 없어야 정상. 걸리면 보고).
- `autoDetail()`: `route`·`actor_team`·`login_ref` 만. `auth`·`token_*`·`acting_as`·`real_role` 가지 삭제. 상수 이름 `COMM_ROUTE`.

### 2-4. `src/Http.php` · `Db.php` · `Env.php` · `Totp.php` · `Changelog.php` · `bootstrap.php`
- `Http`: `apiTokenHeader()` 삭제, 문구 상수는 `MSG_FORBIDDEN`·`MSG_UNAUTHORIZED`·`MSG_NOT_FOUND`·`MSG_BAD_REQUEST`·`MSG_CONFLICT`·`MSG_LOGIN_FAILED`·`MSG_LOGIN_LOCKED`·`MSG_MFA_REQUIRED`·**`MSG_TBOX_REQUIRED` = 「t-box 에서 최초 로그인 절차(비밀번호 변경·2단계 인증 설정)를 먼저 마치세요」**·`MSG_REAUTH_REQUIRED`(t-box 것) · `MSG_BOARD_INACTIVE` = 「보관된 게시판에는 글을 쓸 수 없습니다」. 나머지 파일·스캔 문구 삭제. `jsonBody`·`query*`·`requireCsrf`·쿠키·`redirect`·`clientIp`·`userAgent` 유지.
- `Db`: `perm()`·`DB_PERM_*` 삭제. `bootstrap`: `require` 는 위 9파일만(`Storage`·`Upload`·`Preview`·`Fetch`·`Qr` 삭제), CSP 헤더 그대로.

### 2-5. `public/index.php` — 라우트 표(ARCH 4장·PRD 7장)
```
['GET','/', 'home.php', authenticated] ['GET','/login','login_page.php',public] ['POST','/api/login','login.php',public]
['POST','/api/logout','logout.php',authenticated] ['GET','/api/me','me.php',authenticated] ['GET','/api/health','health.php',public]
['POST','/api/me/reauth','reauth.php',authenticated]
['GET','/api/boards','boards_list.php',authenticated] ['POST','/api/boards','boards_create.php','board.manage'] ['PATCH','/api/boards/{num}','boards_update.php','board.manage']
['GET','/api/boards/{num}/posts','posts_list.php',authenticated] ['POST','/api/boards/{num}/posts','posts_create.php','post.write']
['GET','/api/posts/{id}','posts_get.php',authenticated] ['PATCH','/api/posts/{id}','posts_update.php','post.write'] ['DELETE','/api/posts/{id}','posts_delete.php','post.write']
['POST','/api/posts/{id}/comments','comments_create.php','post.write'] ['DELETE','/api/comments/{id}','comments_delete.php','post.write']
```
- 관문 순서는 ARCH 4장. **토큰 가지·`TBOX_API_TOKEN_*`·재인증 되돌아갈 표·`{slug}`·`{token}` 자리표시자 삭제.** 강제 절차 관문은 `COMM_GATE_ALLOW = ['GET /', 'GET /api/me', 'POST /api/logout']` 하나와 `Auth::nextStep($user) === 'tbox'` 판정 → `Audit::deny('auth.gate','tbox_required', target=본인, detail.next='tbox')` → API 403 `{error: Http::MSG_TBOX_REQUIRED, next:'tbox'}` / 화면(`/api/` 밖·`/` 아님) 302 `/`.
- 재인증 관문(`[5]`): t-box 그대로(403 `{error, next:'reauth'}`). 화면 라우트에는 `board.manage` 가 없으므로 302 갈래는 필요 없다 — 코드에서 지운다.

### 2-6. 핸들러 (ARCH 7장 순서 그대로 — 검사 순서·사유 코드·상태 코드가 명세다)
- 형식은 t-box `src/handlers/folders_create.php`(허용 키 → `$reject` 클로저 → 형식 → 권한 → 저장·기록 한 트랜잭션). 머리 주석에 정책·검사 순서·「이 파일이 하지 않는 것」.
- `me.php`: PRD 7장 키 — `username`·`role`·`team_id`·`team_name`(`LEFT JOIN teams`)·`csrf_token`·`can_write`·`can_manage_boards`·`next`·`reauth_fresh`·`tbox_url`(`Env::getOrEmpty('TBOX_URL')`).
- `home.php`: 셸 + 마크업(아래 2-8). `login_page.php`: t-box 것에서 브랜드·부제·「계정·비밀번호는 t-box 에서 관리합니다」 + `TBOX_URL` 링크(`htmlspecialchars`, `rel="noopener"`). TOTP 생성기 링크는 t-box 주소로.
- 본문 규칙 함수는 **한 곳**: `src/Text.php` 신설 `final class Text { public static function clean(mixed $raw, int $min, int $max, bool $multiline): ?string }` — 문자열·유효 UTF-8 확인 → `\r` 제거 → `trim` → 제어문자 검사(`multiline` 이면 `\n` 허용, 아니면 전부 금지: `preg_match('/[^\P{Cc}\n]/u')` / `'/\p{Cc}/u'`) → `iconv_strlen` 범위 → 실패면 `null`. 제목(1,100,false)·본문(1,10000,true)·댓글(1,2000,true)·게시판 이름(1,50,false; `/`·`\` 도 금지)·설명(0,200,false)·사유(1,500,true). 허용 목록에 `src/Text.php` 를 더한다(보안 핵심 아님, 새 파일).
- 검색 `ILIKE`: `$pattern = '%' . strtr($q, ['#' => '##', '%' => '#%', '_' => '#_']) . '%'` 를 바인딩, SQL 은 `(p.title ILIKE :q1 ESCAPE '#' OR p.body ILIKE :q2 ESCAPE '#')`(같은 값을 두 이름으로 — PDO 이름 재사용 금지).
- 글 목록 응답의 `author`·글 한 편의 `author` 는 `users.username`. 시각은 DB `TIMESTAMPTZ` 문자열 그대로(화면이 자른다, DESIGN 2.4).
- **boolean 바인딩 관례**(t-box F-65 핫픽스 교훈): PDO 는 PHP `false` 를 빈 문자열로 보내 PostgreSQL 이 거절한다. boolean 열은 `CAST(:x AS boolean)` + 값 `'true'`/`'false'` 문자열로.

### 2-7. DB — `db/schema.sql`(ARCH 6장 DDL 그대로, `SET search_path = community, public;` 를 파일 첫 줄에 두고 `CREATE TABLE sessions …` 처럼 스키마 없는 이름으로 — 소유자 실행 시 `community` 에 만들어진다. 단 FK 는 `public.users(id)`·`public.teams(id)` 명시) · `db/seed.sql`(게시판 2개) · `db/grants.sql`(ARCH 5.2 표 **그대로** — `community_owner`·`community_app`·`tbox_backup`; `DO $$ … RAISE EXCEPTION 'tbox_backup 역할이 없다' $$` 검사; `REVOKE CREATE ON SCHEMA community FROM PUBLIC`; `ALTER ROLE community_app SET search_path = community, public`; **`public.*` 에는 GRANT 만, REVOKE 는 `community_app`·`community_owner` 에 대해서만**).
- **임시 DB 검증**(운영 DB 금지): `sudo -n -u postgres createdb comm_check` → t-box `db/schema.sql`+`seed.sql`(public 표를 만들기 위해; `tbox_owner` 가 없으면 postgres 로) → 역할 `community_owner_chk`·`community_app_chk` 를 만들어 이 앱의 schema/seed/grants 를 이름만 바꿔 적용(또는 grants.sql 을 `-v app=…` 변수로 만들지 말고, 검증용 사본을 `/tmp` 에 sed 로) → `SET ROLE community_app_chk` 로 T-5 의 다섯 문장이 **전부 permission denied** 인지, `UPDATE public.users SET failed_count=0` 만 되는지, `SELECT count(*) FROM sessions` 가 `community.sessions` 로 풀리는지(`EXPLAIN` 또는 `SELECT to_regclass('sessions')`) → `dropdb comm_check` + 역할 삭제. 명령·출력 원문을 보고에.

### 2-8. 화면 (`home.php` · `app.js` · `app.css` · `shell_*` · `screens.php`)
- 셸 내비: 「게시판」(`nav-boards`, href `/`) · 「t-box 로 가기」(`nav-tbox`, href = `TBOX_URL` 서버 이스케이프, `target="_blank" rel="noopener"`) · 관리 묶음 없음. `shellKeys = ['boards']`. 사용자 칩·버전·해/달·로그아웃 그대로.
- `home.php`: `$pageKey='boards'`, `$pageHelp='boards'`. 머리: 제목 「게시판」 · 오른쪽 「사용 방법」 · `#board-manage-toggle`(hidden, `can_manage_boards`) · `#post-new`(검정 단추 하나). 관문 안내 `<section id="tbox-gate" class="gate" hidden>`(문구 + `TBOX_URL` 링크 + 로그아웃). 본문 `.grid-2`: 좌 `<aside>` 게시판 목록 `<ul id="board-list">`, 우 `<section>`: 검색 툴바(`#search-q` maxlength 200 · `#search-sort` new/old) → 표 `#posts-body`(제목·글쓴이·시각·댓글) + 빈 문구 + 페이지 → 글 한 편 `<article id="post-view" hidden>`(제목·메타·본문 `<pre class="post-body">`·「고치기」「삭제」 hidden·댓글 `<ul id="comment-list">`·댓글 폼). 모달 셋: 글쓰기/고치기 `#post-dialog`(제목·본문 textarea) · 게시판 관리 `#board-dialog`(목록 표 + 폼: 이름·설명·등급 select·팀 select·공지 체크·활성 체크(고치기만)·사유) · 재인증 `#reauth-dialog`(t-box 것 그대로). 전부 DESIGN.md 2장 클래스, 인라인 style·script 0.
- `app.js`(새 파일, `'use strict'`): t-box 에서 **옮길 것** — `setText`·`makeIcon`·`paintIcons`·`makeIconButton`·`LEVEL_BADGE_LABELS`·`makeLevelBadge`·`errorMessage`·`readJson`·`toLogin`·`toGate`(→ `next==='tbox'` 면 `/` 로)·`setReauthRetry`·`handleReauth`·`openReauthModal`·`postReauth`·`initLogin`·`initShell`(내비 두 개만)·`initHelpDialog`·`initChangelogDialog`·`currentTheme`·`applyThemeLabel`·`initThemeToggle`·`bootPage`. **새로**: `initBoards(me, csrf)` — `URLSearchParams` 에서 `board`(`/^[1-9][0-9]{0,8}$/`)·`post`(`UUID_RE`) 만 읽는다 → `GET /api/boards` 로 좌측 목록(등급 배지·글 수·비활성 「보관」 배지) → 선택 게시판 `GET /api/boards/{num}/posts?q&sort&page` → 표 → 행 클릭 `GET /api/posts/{id}` → 글 한 편(본문 `textContent`, `can_edit` 면 단추) → 댓글 목록(`can_delete` 면 삭제 아이콘 단추) → 폼 제출은 `fetch` + `X-CSRF-Token` + JSON, 실패 문구는 서버 것 그대로, 403 `next:'reauth'` 면 `handleReauth(…, 재시도)`. `history.replaceState` 로 `?board=&post=` 갱신. 역할 문자열 비교 0, 화면 판단 0 — `can_post`·`can_edit`·`can_delete`·`can_manage_boards` 만 본다.
- `app.css`: t-box 파일 그대로 복사 + 끝에 `/* ─── 커뮤니티 (게시판 목록·글·댓글) ─── */` 절: `.board-list` `.post-body { white-space: pre-wrap; overflow-wrap: anywhere; }` `.comment` 등 **토큰만 써서** 최소 규칙. 새 색값 0.
- `src/help/screens.php` 키 `boards`: 게시판 고르기·글쓰기(평문만, 10,000자)·댓글·수정·삭제(되살릴 수 없음)·게시판 관리(관리자, 등급은 못 바꿈)·「계정은 t-box 에서」 — 요구사항 ID·내부 구조 말 금지.

### 2-9. 배포 파일 (ARCH 9장 그대로) — **작성만, 실행 금지**
- `deploy/php-fpm-pool.conf`(`[community]`) · `deploy/sites/community-common.conf`(zone `comm_login`) · `community-local.conf`(`listen 127.0.0.1:8083`) · `community.conf`(공개용 — `listen 80` 을 **쓰지 않고** `# Q-3 결정 뒤 server_name·listen 채움` 주석과 같은 location 블록만) · `deploy/logrotate.d/community`(t-box 것에서 경로만).
- `deploy/install.sh`: ARCH 9.4 ①~⑦. t-box `install.sh` 의 함수(`step`·`info`·`die`·`psql_su`·`role_exists`·`db_exists`·`env_value`)를 복사. DB `tbox_re` 가 없으면 `die`. `tbox_backup` 이 없으면 `die`. 스키마 적용 조건은 `to_regclass('community.boards') IS NULL`. `.env` 에 `APP_SECRET`·`DB_PASS` 는 `openssl rand -hex`. 자체 확인 ⑦ 에 `curl /api/health` 와 **권한 부정 확인**(`psql -U community_app … "SELECT 1 FROM public.files"` 가 실패해야 통과 — 성공하면 `die`). `bash -n` 통과.

### 2-10. 린트·시험
- `scripts/security_lint.sh`: t-box 것에서 cURL 예외·`Db::perm`·`act_role` 절을 **지우고**, 규칙 (h) 추가: `grep -E 'CREATE TABLE +(IF NOT EXISTS +)?(community\.)?(users|teams|permissions)\b' db/*.sql` 가 있으면 반려. `curl_(init|exec|…)` 는 예외 없이 반려.
- `tests/lib.sh`: `BASE=http://127.0.0.1:8083`, `TBOX=http://127.0.0.1:8082`, `UA="community-review/$(date +%F)"`, `login()`·`reauth()`·`expect()`·`hr()`·`sub()` (t-box `stage26.sh`·`f54.sh` 형식). 계정 파일은 `/etc/tbox-v2/seed-accounts.txt`·`qa-totp.txt`(sudo -n).
- `tests/stage1.sh`: T-1·T-2·T-3·T-5·T-6·T-13(스크립트로 되는 부분). T-2 의 잠금은 **qa_employee02** 로 만들고 시험 끝에 `locked_until` 을 되돌리지 않는다(운영 데이터 불변 — 10분 자연 해제; 그래서 T-2 는 마지막 항목). T-3 은 `totp_code.php` 로 코드 하나를 커뮤니티에 쓰고 **같은 코드**를 t-box 에 즉시 → 401. T-6 은 t-box `stage26.sh`·`help_button.sh` 를 **설치 전에 한 번** 돌려 통과 수를 파일에 적어 두고(설치 뒤 Fable 이 비교), `sudo -n -u postgres pg_dump -s -U tbox_backup`… 은 pgpass 가 root 것이라 `sudo -n env PGPASSFILE=/etc/tbox-v2/backup.pgpass pg_dump -h 127.0.0.1 -U tbox_backup -s tbox_re > /dev/null` 로.
- `tests/stage2.sh`: T-7~T-13 을 PRD 10장 항목 이름 그대로 `expect` 로. XSS (d) 의 Playwright 는 t-box `scripts/ui_snapshot.py` 방식(크로미움 직접 경로, `node` 없음)으로 dialog·본문·콘솔을 본다. 끝에 만든 글·댓글은 `DELETE` 창구로, `C2-팀` 게시판은 `is_active:false`.
- **지금 시점에 HTTP 시험은 돌지 않는다**(DB 역할·풀·nginx 없음). `bash -n` 까지만 하고, 「실행은 install.sh 뒤」 로 보고.

## 3. 완료 기준 (Fable 이 다시 실행한다)
- 모든 `.php` `php -l` 통과 · `bash -n` 전부 · `scripts/security_lint.sh` **0건** · `grep -rn "TBOX_\|tbox_session\|api_token\|act_role\|Fetch::\|Storage::\|Upload::\|Db::perm" src public` **0건**(주석의 `t-box` 낱말은 허용 — 대문자 상수·클래스만 센다) · `grep -rn "innerHTML\|insertAdjacentHTML" public/assets/app.js` 0건
- 임시 DB 검증(2-7) 원문: 스키마 rc=0 · seed rc=0 · grants rc=0 · T-5 다섯 문장 permission denied · 3열 UPDATE 성공 · `to_regclass('sessions')` = `community.sessions`
- 라우트 표 검증: `php -r` 로 `index.php` 의 표를 읽어 정책이 전부 `KNOWN_POLICIES` 안, 핸들러 파일 17개 존재
- t-box 저장소 `git -C /var/www/tbox-v2 status --short` 가 **네 작업 전과 같다**(시작할 때 한 번 찍어 두고 끝에 비교 — 원문 둘 다 보고)
- 라우트·핸들러·감사 문장 표가 ARCH 7·8장과 1:1 (표에 없는 action|reason 을 코드가 쓰지 않는다 — `grep -o "Audit::\(allow\|deny\)('[a-z_.]*'\(, *'[a-z_]*'\)\?" -r src | sort -u` 출력과 8.2 표를 대조해 보고)

## 4. 보고 형식 (1부·2부 각각)
변경 파일(신규/복사·다듬음) / 3장 기준 각각의 명령·출력 원문 / 임시 DB 검증 원문 / t-box `git status` 전·후 원문 / ARCH 와 어긋나 **멈춘 것**·판단이 필요한 것 / 사용자가 실행할 것: `sudo bash /var/www/community/deploy/install.sh` (그 뒤 Fable 이 `tests/stage1.sh`·`stage2.sh` 를 돈다)
