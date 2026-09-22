# F-66 지시서 (Fable → Opus) — 프로그램 토큰 본인 발급 · 범위(scope) · 만료 180일

> Fable 작성 2026-09-22. 사용자 결정 Q-24(2026-09-21) → 「ok 진행해」(2026-09-22).
> 문서가 먼저 갱신돼 있다: PRD **F-66** · 8장 `/api/me/api-tokens` 세 행(+ 관리자 발급 행 제거) · 7.11 AL-20 · 10장 내 계정 · 12.2 **T-78**, ARCH **4.7**(명세 본문 — 먼저 읽을 것) · 6.2-5 · 7.4(6행) · 7.5(`token_scope`), SECURITY **R-97** · R-91, STAGES F-66 절.
> 화면이 바뀐다 → **DESIGN.md 2.3(패널)·2.4(표)·2.5(폼)·2.6(알림)·6장 체크리스트**를 따른다. 새 클래스·색값을 만들지 않는다.

```
목표        : 개인 프로그램 토큰의 발급을 「관리자가 남의 것을」에서 「본인이 비밀번호+OTP 를 치고, 범위를 골라, 180일짜리를」로 옮긴다.
              토큰 창구 열의 인가·판정·응답은 범위 관문 하나가 앞에 붙는 것 외에 바뀌지 않는다.
요구사항    : PRD F-66 ①~⑥ · 8장 세 행 · T-78 · 7.7(비밀 미기록) · S-01 · S-07 · S-09-11, ARCH 4.7 · 4.6 · 6.2-5 · 7.4 · 7.5 · 5.30(문구 예외 목록),
              SECURITY R-97 · R-91 · R-48 · R-45, DESIGN.md 2.3 · 2.4 · 2.5 · 2.6 · 6장
수정 파일   : 1장 허용 목록. 목록 밖 파일은 만들거나 고치지 않는다.
금지 패턴   : 운영 DB DDL/DML(마이그레이션은 임시 DB 로만 — T-78 (d) 의 시험 토큰 UPDATE 는 시험 스크립트가 F66- 토큰에만) · `git add`·`commit`·`checkout`·`stash`·`reset` ·
              시드 계정 · 비밀번호/OTP/토큰 원문을 응답(발급 201 한 줄 제외)·감사·로그·화면 텍스트에 싣기 · `reauth_at` 을 놓거나 세션에 「확인됨」을 남기기 ·
              GET 쿼리에 비밀번호 · innerHTML · 화면에서 역할 문자열 비교(범위 선택지는 `/api/me` 의 `token_scopes` 로만) ·
              토큰 집합(`TBOX_API_TOKEN_ROUTES`)에 `/api/me/api-tokens` 넣기(토큰이 토큰을 낳지 않는다) · `Authz` 판정 함수(decide*) 변경 ·
              인증 실패(`bad_credentials`·`bad_code`·`code_reused`)에 문장 붙이기(5.30 예외 목록) · `api_upload.php`·`_upload_common.php` 변경
완료 기준   : PRD T-78 (a)~(h) — 3장
보고 형식   : 4장
```

## 0. 작업 환경 — 운영 트리, 순서

- 작업 트리 `/var/www/tbox-v2` 가 곧 운영(8082)이다. 다른 세션(디자인)이 같은 트리를 쓰므로 `git` 은 `status`·`diff` 읽기만. `git status` 에 내 것이 아닌 변경이 보이면 **그 파일은 열지 않는다**(지금은 Fable 의 문서 4개 PRD/ARCHITECTURE/SECURITY/STAGES 가 M 상태 — 손대지 말 것, 지시서 1장의 「문서(경미)」만 예외).
- **배포 순서: 마이그레이션 → 코드.** 운영 DB 에 `api_tokens.scope`·`expires_at` 이 없으면 새 `SQL_FIND_API_TOKEN` 이 500 이다(F-59 때 1분 500 사고). 그래서:
  1. 마이그레이션·schema·install 감지 → **임시 DB**(`tbox_f66_fresh`, T-76 (f)/T-77 (g) 방식: createdb → schema.sql → 옛 모양으로 되돌려 마이그레이션 적용 → 열 확인 → dropdb)로 검증.
  2. 코드는 **열이 없어도 죽지 않게** 쓴다: `requireApiToken` 은 `SQL_FIND_API_TOKEN` 한 문장이므로 그럴 수 없다 → 대신 Fable 이 사용자에게 마이그레이션 실행을 먼저 청한다. **Opus 는 `Auth.php`·`index.php`·핸들러를 다 써 두되, 스모크는 Fable 이 마이그레이션 확인 뒤 돈다.** 그 전까지 토큰 요청은 500 이 될 수 있다 — 그래서 `Auth.php` 편집은 **가장 마지막**에 하고, 편집 직전에 보고서에 「지금부터 마이그레이션 전까지 토큰 경로 500」을 적는다. (사용자 비서가 토큰을 쓰고 있을 수 있다 — Fable 이 시각을 STAGES 에 남긴다.)
  3. 편집한 PHP 는 매번 `php -l`. 파일을 문법 오류 상태로 두지 않는다.
- 시험(`tests/f66.sh`)은 마이그레이션 뒤에만 (a)~(g) 를 돈다 — 열이 없으면 (h) 만 돌고 나머지 SKIP(`f65.sh` 의 (g) 방식).

## 1. 수정 파일 — 허용 목록

**신규**: `db/migrations/2026-09-22_api_tokens_scope_expiry.sql` · `src/handlers/me_tokens_list.php` · `me_tokens_issue.php` · `me_tokens_revoke.php` · `tests/f66.sh`
**삭제**: `src/handlers/api_tokens_issue.php`(관리자 발급 — 라우트와 함께 지운다. `TBOX_API_TOKEN_MAX_LIVE`·`TBOX_API_TOKEN_CHARS` 상수는 `me_tokens_issue.php` 로 옮긴다)
**보안 핵심(CLAUDE.md 4장, 이번에 허용)**: `src/Auth.php`(`SQL_FIND_API_TOKEN` 두 열 · `requireApiToken` 만료 갈래 · `TBOX_API_TOKEN_SCOPE` · 새 상수 `API_TOKEN_TTL_DAYS = 180`·`API_TOKEN_SCOPES`) · `src/Authz.php`(`tokenScopesFor`·`tokenScopeAllows` **추가만**) · `src/Audit.php`(7.4 문장 6행 · `ALLOWED_DETAIL_KEYS` 에 `token_scope` · `autoDetail` 한 줄) · `public/index.php`(라우트 셋 추가·하나 제거·토큰 가지 범위 관문) · `db/schema.sql` · `deploy/install.sh`(4-4-1 감지 목록 한 줄)
**핸들러·공통**: `src/Http.php`(`MSG_TOKEN_EXPIRED`·`MSG_TOKEN_SCOPE` 추가만) · `src/handlers/api_tokens_list.php`(`scope`·`expires_at`) · `src/handlers/me.php`(`token_scopes`·`can_issue_tokens`) · `scripts/alerts.php`(AL-20 evidence `token_scope`)
**화면**: `src/handlers/settings_page.php`(「프로그램 토큰」 절) · `public/assets/app.js`(F-59 토큰 구역을 **공통 함수로 갈라** `/settings` 본인 구역과 `/users` 모달이 같은 표 그리기·한 번 표시를 쓴다; `/users` 모달은 발급 폼 제거) · `public/assets/app.css`(**필요할 때만** 기존 토큰 규칙을 재사용 — 새 규칙은 최소, 인라인 style 0) · `src/help/screens.php`(settings 절에 「프로그램 토큰」 항목)
**문서(경미 보정)**: `API.md`(13장 재작성 — 본인 창구 셋·범위·만료·401 두 문구, 0장에 범위·만료 한 줄, 7~14장 각 「범위 밖 403」 한 줄) · `README.md`(프로그램 토큰 문단 한 개) · `.env.example`(공용 토큰 주석에 「범위·만료 없음, 이행용」 한 줄) · `STAGES.md` F-66 절 **① 체크 표만**. PRD·ARCH·SECURITY 는 Fable 이 썼다 — 손대지 않는다.

## 2. 무엇을 어떻게 (ARCH 4.7 이 우선 — 여기는 구현 메모)

### 2-1. 표
- 마이그레이션(`2026-09-16_api_tokens.sql` 머리 형식, `\set ON_ERROR_STOP on`·BEGIN/COMMIT):
  ```sql
  ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS scope TEXT;
  ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ;
  UPDATE api_tokens SET scope = 'read+upload+delete' WHERE scope IS NULL;           -- 그때는 범위가 없어 전부 열려 있었다 (ARCH 4.7)
  UPDATE api_tokens SET expires_at = created_at + interval '180 days' WHERE expires_at IS NULL;
  ALTER TABLE api_tokens ALTER COLUMN scope SET NOT NULL, ALTER COLUMN expires_at SET NOT NULL;
  ALTER TABLE api_tokens ADD CONSTRAINT api_tokens_scope_check CHECK (scope IN ('read','read+upload','read+upload+delete'));
  COMMENT ON COLUMN … (두 열)
  ```
  GRANT 변경 없음(열 추가는 표 권한을 따른다). `schema.sql` 은 ARCH 6.2-5 와 같은 두 열. `install.sh` 4-4-1 목록에 `"scope|api_tokens|2026-09-22_api_tokens_scope_expiry.sql"`.

### 2-2. `src/Authz.php` — 추가만
```php
public const TOKEN_SCOPES = ['read', 'read+upload', 'read+upload+delete'];
/** 이 계정이 고를 수 있는 범위 — Guest 는 read 만 (PRD 5.2·5.3: 올릴 수도 지울 수도 없다) */
public static function tokenScopesFor(array $u): array   // uploadableLevels($u) === [] ? ['read'] : TOKEN_SCOPES
/** 라우트 정책 → 필요한 범위 (ARCH 4.7 표). 모르는 정책은 거부(닫힌 실패) */
public static function tokenScopeAllows(string $scope, string $policy): bool
```
매핑: `file.read`·`authenticated` → 전부 허용 / `file.upload`·`file.update` → `read+upload`·`read+upload+delete` / `file.delete` → `read+upload+delete` / 그 밖 → false. 역할 문자열을 보지 않는다 — `uploadableLevels` 가 빈 배열이면 Guest 다(기존 함수 재사용).

### 2-3. `src/Auth.php`
- `SQL_FIND_API_TOKEN` 에 `scope, expires_at` 추가(`revoked_at IS NULL` 그대로). 행이 있고 `expires_at <= now()`(SQL 에서 `(expires_at <= now()) AS is_expired` 로 받는다 — PHP 시계와 DB 시계를 섞지 않는다) → `denyApiToken('token_expired')` + **401** `Http::MSG_TOKEN_EXPIRED`. 이 판정은 계정 4조건 **앞**에 둔다(만료된 토큰은 계정을 읽지 않는다).
- 통과 시 `define('TBOX_API_TOKEN_SCOPE', (string) $found['scope'])`; 공용 토큰 갈래는 `'read+upload'`.
- `denyApiToken` 은 그대로. `token_expired` 거부 행의 detail 에 `token_id` 를 넣고 싶으면 `Audit::deny(..., ['detail' => ['token_id' => …]])` — `token_scope` 도 함께(둘 다 허용 키).
- 상수 `API_TOKEN_TTL_DAYS = 180`(발급 핸들러가 `interval` 문자열을 만들 때 이 상수만 쓴다 — 180 을 두 곳에 적지 않는다).

### 2-4. `public/index.php`
- 라우트 셋 추가(본인 계정 관리 블록, `POLICY_AUTHENTICATED`):
  `['GET','/api/me/api-tokens','me_tokens_list.php',…]` · `['POST','/api/me/api-tokens','me_tokens_issue.php',…]` · `['DELETE','/api/me/api-tokens/{num}','me_tokens_revoke.php',…]`. 주석에 「토큰 집합 밖 — 토큰이 토큰을 낳지 않는다」.
- `['POST','/api/users/{num}/api-tokens', …]` 행 **삭제**(주석도 F-66 으로 고친다). 목록·회수 두 행은 그대로.
- 토큰 가지: `$user = Auth::requireApiToken(...)` 직후, 정책 인가 **앞**에
  ```php
  if (!Authz::tokenScopeAllows(TBOX_API_TOKEN_SCOPE, $policy)) {
      Audit::deny('auth.token', 'scope_not_permitted', ['target_type'=>'system','target_name'=>TBOX_ROUTE,'detail'=>['token_scope'=>TBOX_API_TOKEN_SCOPE] + (defined('TBOX_API_TOKEN_ID') ? ['token_id'=>TBOX_API_TOKEN_ID] : [])]);
      Http::error(403, Http::MSG_TOKEN_SCOPE);
  }
  ```
  `$policy` 변수 이름은 라우터가 쓰는 실제 이름을 따른다(읽고 맞출 것). 감사 형식은 `Auth::denyApiToken` 과 같은 target 모양.

### 2-5. 핸들러
- **`me_tokens_issue.php`** — `mfa_disable.php` 의 검증 흐름 + `api_tokens_issue.php` 의 발급 흐름을 합친다. 허용 키 다섯 `name`·`purpose`·`scope`·`password`·`otp`. 순서(ARCH 4.7): 키/형식(이름 규칙은 F-59 그대로, `purpose` 1~500자 trim, `otp` 는 `Totp::CODE_RE`) → `in_array($scope, Authz::tokenScopesFor($user), true)` 아니면 `bad_request` → `mfa_enabled` 아니면 `Audit::deny('api_token.issue','mfa_not_enabled')` + **403** `MSG_FORBIDDEN`(집계 안 함) → `Auth::verifyCurrentPassword` 실패 → `bad_credentials` + `countFailureAndRevoke` + 400 `reasonMessage`(예외 목록이라 통일 문구) → `Auth::verifyOtpOnce` 가 `'ok'` 아니면 그 코드(`bad_code`·`code_reused`) + 집계 + 400 → `unset($password,$otp,$body)` → 살아 있는 5개 → INSERT `(user_id, name, token_hash, hint, created_by, reason_text, scope, expires_at) VALUES (…, :uid(본인), …, now() + (:ttl || ' days')::interval)` 또는 `make_interval(days => :ttl)` — 바인딩으로 180 을 넘긴다 → `Audit::allow('api_token.issue', target=본인, detail token_id·token_name·token_scope·reason_text·otp_verified:true)` → 201 `{id,name,hint,scope,expires_at,token}`. `$auditTarget` 은 본인(`target_type user`, 본인 id·username).
- **`me_tokens_list.php`** — `api_tokens_list.php` 를 본인 고정(`:uid` = 세션 본인)으로. 행 키: 여섯 + `scope`·`purpose`(`reason_text`)·`expires_at`·`expires_soon`(SQL `expires_at < now() + interval '14 days'` 을 bool 로).
- **`me_tokens_revoke.php`** — `api_tokens_revoke.php` 를 본인 고정으로(`user_id = 본인` 조건, 남의 tid → 404 `not_found`). `revoked_by` = 본인. 비밀번호·OTP 없음.
- `api_tokens_list.php` 응답에 `scope`·`expires_at` 추가(관리자 목록). `api_tokens_revoke.php` 무수정.
- `me.php` 에 `'token_scopes' => Authz::tokenScopesFor($user)`, `'can_issue_tokens' => mfa_enabled 값` — `mfa_enabled` 키 바로 아래.

### 2-6. `Http` · `Audit` · `alerts`
- `Http::MSG_TOKEN_EXPIRED = '만료된 API 토큰입니다 — 내 계정에서 새로 발급하세요'` · `Http::MSG_TOKEN_SCOPE = '이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요'`(둘 다 4.2 오류 문구 정책: 부르는 쪽이 우리 프로그램). `reasonMessage` 표는 건드리지 않는다(새 400 사유 코드 없음).
- `Audit::SUMMARY_TEMPLATES` 에 ARCH 7.4 의 6행을 **글자 그대로**. `ALLOWED_DETAIL_KEYS` 에 `'token_scope'`(정확 일치 예외, 주석은 `token_kind` 줄과 같은 형식). `autoDetail()` 에 `if (defined('TBOX_API_TOKEN_SCOPE')) $out['token_scope'] = …` 한 줄(`token_id` 줄 아래).
- `alerts.php` AL-20: SELECT 에 `max(detail->>'token_scope') AS token_scope`, evidence 에 `token_scope`. 그 밖은 그대로(발급자=대상=본인이라도 규칙은 같다).

### 2-7. 화면 (DESIGN.md 2.3 · 2.4 · 2.5 · 2.6 · 6장)
- `settings_page.php`: 「2단계 인증」 절 **위**에 `<section class="panel span-all">` 「프로그램 토큰」. 머리 `panel__sub`: 「이 계정의 권한 안에서 프로그램이 쓰는 토큰입니다. 값은 발급할 때 한 번만 보이고, 180일 뒤 만료됩니다.」 안에 ① `<p id="tokens-expiring" class="notice notice--recommend" hidden>`(만료 14일 안 토큰이 있으면 「만료가 가까운 토큰이 있습니다 — 새로 발급하고 프로그램에 바꿔 심으세요」) ② `.table-wrap` 표 `id="my-tokens-body"`(열: 이름·용도·앞 6자·범위·만료·마지막 사용·상태·동작) + `id="my-tokens-empty"` ③ `<form id="token-issue-form" class="form form--grid" hidden>`: 이름(필수)·용도(필수)·범위 `<select id="token-scope">`(옵션은 JS 가 `me.token_scopes` 로 채움 — 라벨 `read`→「읽기만」, `read+upload`→「읽기 + 올리기」, `read+upload+delete`→「읽기 + 올리기 + 지우기」)·현재 비밀번호·2단계 코드(`.otp-box`, `inputmode=numeric pattern=[0-9]{6}`)·`<button class="btn">토큰 발급</button>` · `.msg` 한 줄 ④ `<p id="token-issue-blocked" class="notice notice--recommend" hidden>` 「2단계 인증을 켜야 발급할 수 있습니다 — 아래 2단계 인증 절에서 켜세요」. `me.can_issue_tokens` 가 참이면 ③, 거짓이면 ④. 인라인 style·script 0.
- `app.js`: F-59 구역(4333~)의 `addTokenRow`·`showIssuedToken`·`tokenFetch` 를 **인자로 컨테이너를 받는 공통 함수**로 갈라(모달과 본인 절이 같은 코드를 쓴다), `/users` 모달에서는 **발급 폼과 `issueToken` 을 제거**하고 표에 범위·만료 열을 더한다(회수는 그대로). 새 `initMyTokens(csrfToken, me)` 를 `page === 'settings'` 가지에서 `initShares` 다음에 부른다: 목록 로드 → 표 · `expires_soon` 있으면 ① 보임 · 발급 → 201 이면 한 번 표시(값은 `input.value` 로만, 문서 텍스트에 넣지 않는다)·비밀번호/코드 칸 즉시 비움·목록 재로드 · 실패는 `.msg` 에 서버 문구 그대로 · 회수는 `confirm` 뒤 DELETE. 401 → `toLogin()`, 403 `next:reauth` 는 해당 없음(정책 authenticated). 범위·만료 표시는 `textContent`. 화면에 역할 비교 0.
- `app.css`: 기존 `.token-box*` 규칙이 본인 절에도 맞는지 보고, 필요한 최소 규칙만(예: `.otp-box` 는 이미 있다). 새 색값 0.
- `screens.php` settings: `['h' => '프로그램 토큰', 'items' => ['내 계정의 권한 안에서 PC 프로그램이 쓰는 토큰입니다. 발급할 때 비밀번호와 2단계 인증 코드를 넣고, 범위(읽기만 · 읽기+올리기 · 읽기+올리기+지우기)를 고릅니다.', '값은 발급 직후 한 번만 보입니다. 잃으면 회수하고 새로 발급하세요. 180일이 지나면 만료되어 프로그램이 멈추니 만료 안내가 보이면 미리 바꿔 심으세요.', '관리자는 계정 관리에서 다른 사람의 토큰을 볼 수 있고 회수할 수 있지만, 발급은 본인만 합니다.']]` — 「2단계 인증」 항목 앞에.
- DESIGN 6장 체크리스트를 보고서에 붙인다. `scripts/ui_snapshot.sh` 1440·1280 실행(결함 수가 이전과 같거나 적게).

### 2-8. `tests/f66.sh` — `tests/f65.sh`·`api_tokens.sh` 골격
머리 주석·안전 관문(비-qa 10분 대기·디스크·`F66-` 접두·검토 UA)·헬퍼·`trap` 정리·토큰 값 취급 규칙(변수·0700 임시 파일만, echo 금지, psql 인자로 넘기지 않음)은 `api_tokens.sh` 를 따른다. 로그인·TOTP(`/etc/tbox-v2/qa-totp.txt`)·재로그인(실패 집계 0)은 `f68.sh` (b) 방식. **마이그레이션 감지**: `information_schema.columns` 에 `api_tokens.scope` 가 없으면 (a)~(g) SKIP, (h) 만.
- (a)~(h) 는 PRD T-78 그대로. 몇 가지 구현 메모:
  - (b) 실패마다 재로그인(5회 잠금). `mfa_not_enabled` 실측은 2단계가 꺼진 qa 계정이 **있을 때만**(`GET /api/me` 의 `mfa_enabled`) — 없으면 SKIP 하고 코드 읽기로 적는다. Guest 범위 시험도 `qa_guest01` 이 있을 때만.
  - (c) 세 범위 토큰을 각각 발급해 창구 열을 훑는다(각 범위 × 열 = 30 요청). `read` 토큰의 다운로드는 qa_employee01 이 볼 수 있는 파일(시험이 올린 `F66-…txt` 를 세션으로 먼저 올림). 삭제는 시험이 올린 파일·만든 폴더만. 각 거부의 감사 행 `auth.token deny scope_not_permitted` 와 `detail.token_scope` 를 psql **읽기**로 확인.
  - (d) `sudo -n -u postgres psql -d tbox_re -c "UPDATE api_tokens SET expires_at = now() - interval '1 minute' WHERE id = <시험 토큰 id> AND name LIKE 'F66-%'"` — **시험이 방금 만든 토큰 한 개에만**, `rowCount` 1 확인. 이어 그 토큰으로 `GET /api/whoami` → 401 만료 문구 · 틀린 토큰 → 401 「API 토큰이 올바르지 않습니다」 · 회수한 토큰 → 같은 「올바르지 않습니다」(만료와 다름). `expires_soon` 은 다른 시험 토큰을 `now() + interval '7 days'` 로. 끝나면 둘 다 회수.
  - (g) Playwright 는 `f65.sh (e)` 방식(크로미움 실행 파일 직접, stderr 따로) — 없으면 SKIP.
- **기존 시험 갱신**: `tests/api_tokens.sh` — 발급이 관리자 창구였던 곳(`POST /api/users/<uid>/api-tokens`)을 **qa_employee01 본인 발급**(`/api/me/api-tokens`, 비밀번호+OTP, scope `read+upload+delete`)으로 바꾸고, 「재인증 없는 Admin 발급 → 403 next:reauth」 항목은 「관리자 발급 창구 → 404」로, 「Manager 세션 발급 403」은 삭제(누구나 본인 발급). 목록·회수·(b)(c)(d)(e)(g) 는 그대로 통과해야 한다. `tests/f68.sh` (a)13 `limit_exceeded` 도 본인 발급으로(qa_employee01 세션, 비밀번호+OTP 5회 — 코드 1회성이라 30초 창을 기다린다; 오래 걸리면 (a)13 을 `f66.sh` 로 옮기고 f68 에서는 SKIP 사유를 적는다). 그 밖 `api_whoami.sh`·`api_read.sh`·`api_folders.sh` 가 토큰을 어떻게 얻는지 grep 하고(관리자 발급이면 같은 방식으로 갱신), 공용 토큰(`.env`)을 쓰는 항목은 그대로.

## 3. 완료 기준 — PRD T-78 (a)~(h)

`sudo -v && bash tests/f66.sh` 실패 0(마이그레이션 뒤) · `tests/api_tokens.sh`·`f68.sh`·`api_whoami.sh`·`api_read.sh`·`api_folders.sh`·`stage26.sh`·`help_button.sh` 실패 0 · `scripts/alerts.php --rule AL-20 --dry-run` 1건(evidence 에 `token_scope`) · `scripts/security_lint.sh` 0 · 바뀐 PHP 전부 `php -l` · `bash -n` · `scripts/ui_snapshot.sh` 결함 수 이전 이하 · 임시 DB 마이그레이션 검증(열 둘·CHECK·NOT NULL·기존 행 채움).

## 4. 보고 형식

1. 변경 파일 목록(줄 범위·한 줄 요약). 삭제한 파일·라우트.
2. 실행한 명령과 출력 **원문**: 임시 DB 마이그레이션(`\d api_tokens` 발췌) · `php -l` 전부 · `f66.sh` 통과/실패/건너뜀과 실패 줄 · 회귀 7종 마지막 줄 · AL-20 dry-run · lint · ui_snapshot 결함 수. **토큰 원문·비밀번호·코드는 출력에 0**.
3. **운영 마이그레이션 명령**(사용자가 실행): `sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-09-22_api_tokens_scope_expiry.sql` 과 확인 SQL(`\d api_tokens`, 기존 행의 scope·expires_at). `Auth.php` 를 편집한 **시각**.
4. 못 한 것과 이유(SKIP 포함). 5. 판단이 필요했던 곳 — 지시서와 코드가 어긋난 자리는 **코드가 맞고 지시서가 틀린 것일 수 있다**: 고치지 말고 보고(F-68 때 `api_folders.sh:425` 처럼).
6. DESIGN.md 6장 체크리스트 각 항목의 결과.
