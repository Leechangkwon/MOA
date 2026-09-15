# Opus 구현 지시서 — F-49 프로그램용 업로드 API `POST /api/upload` (정적 API 토큰) — v2

> Fable 작성 2026-09-15 (v2, 설계 A). **v1(사용자별 DB 토큰)은 폐기·원복됐다 — 그 설계를 구현하지 마라.**
> CLAUDE.md 2장 형식. 시작 전에 "요구사항"의 절을 실제로 읽을 것.

```
목표      : 강사 2차 프롬프트 계약대로 POST /api/upload 를 추가한다 — .env 정적 API_TOKEN + X-API-Token 헤더 인증,
            웹 업로드와 같은 검증·저장 함수 재사용(중복 구현 금지), 400/401/403/413 은 사람이 읽는 이유·500 은 일반 문구.
요구사항  : PRD F-49 · 8장 `/api/upload` 행 · 12.2 T-61 · S-01 · S-06 · S-09-7 · S-09-11 · F-16 ⑤(예외)
            ARCHITECTURE 4.0 · 4.1 · 4.2(이 기능의 설계 — 그대로 구현) · 5.2(업로드 흐름) · 7장(감사 허용표) · 8장(nginx)
            SECURITY R-82
수정 파일  : 아래 "수정 파일" 목록만. 목록 밖은 만들지도 고치지도 않는다.
금지 패턴  : SQL 문자열 결합, innerHTML, 사용자 입력이 들어간 파일 경로, curl_exec/file_get_contents('http…'),
            역할 판단을 Authz 밖에서 직접, 요청 파라미터의 role 값 사용,
            토큰 값을 출력·로그·감사·문서에 적기, `==`/`===` 로 토큰 비교(hash_equals 만), 업로드 검증 로직 복사
완료 기준  : PRD 12.2 T-61 (a)~(f) 실행. (g) 긍정 경로는 실행하지 않는다(아래 "하지 말 것").
보고 형식  : 변경·생성 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유
```

## 계약 (강사 프롬프트 원문 요약 — 이대로)

- `POST /api/upload` (multipart/form-data). 필드: `file`(필수) · `description`(선택) · `level`(기존 등급 값 그대로: `public`·`internal`·`team`·`admin-only`, **없으면 `public`**) · `team_id`(level=team 이면 필수) · `folder_id`(선택).
- 성공 **201** `{"id":"<public_id>","name":"<원래 파일명>","level":"<등급>"}`.
- 실패 **400/401/403/413** `{"error":"사람이 읽을 수 있는 이유"}` — 이유를 숨기지 않는다. **500** 은 기존 `{"error":"서버 오류"}` 유지(사용자 결정, S-09-7).
- 인증: 요청 헤더 `X-API-Token` 이 `.env` `API_TOKEN` 과 `hash_equals` 로 일치할 때만. 아니면 401. **다른 라우트는 이 헤더를 무시한다.**
- 웹 업로드와 **같은 검증·저장 로직**을 재사용한다.

## 수정 파일 (허용 목록)

보안 핵심 파일(지시서에 명시됐으므로 고쳐도 된다):
- `public/index.php` — 라우트 1줄 + `[2]` 토큰 분기
- `src/Auth.php` — 귀속 계정 로더 `loadApiTokenUser(): ?array` (username → `SQL_LOAD_USER` 재사용, `currentUser()` 와 같은 배열 모양)
- `src/Http.php` — `apiTokenHeader(): ?string`
- `src/Audit.php` — 새 템플릿 + 상세 키 `auth` + 요청 스코프 자동 첨부
- `deploy/sites/tbox-v2.conf` — JSON 413
- `src/handlers/files_upload.php` — 본문을 공통 함수 호출로 축소(동작 불변)

새 파일:
- `src/handlers/_upload_common.php` — 공통 업로드 함수
- `src/handlers/api_upload.php` — API 핸들러
- `scripts/api_token_rotate.sh` — 토큰 생성·교체(값 비출력)
- `tests/api_upload.sh` — T-61 (a)~(f)

문서:
- `.env.example` — `API_TOKEN=` · `API_TOKEN_USER=` 키와 주석(값 없음)
- `API.md` — **7장** 「프로그램용 업로드 — `POST /api/upload`」 추가(1장 표 "인증" 줄에도 한 줄)

**손대지 않는다:** 위 밖 전부. 특히 `src/Upload.php`·`src/Storage.php`·`src/Authz.php`·`db/*`·실제 `.env`(읽지도 말 것)·`STAGES.md`·`SECURITY.md` 7장·버전 파일.

## 구체 설계 (ARCH 4.2 를 구현한다)

### 1) 공통 업로드 함수 — `src/handlers/_upload_common.php`
`files_upload.php` 의 [1]~[5] 본문을 **그대로 옮겨** 함수로 만든다(예):
```php
/**
 * @param array    $user        라우터가 세운 현재 사용자(세션 또는 API 토큰 귀속 계정)
 * @param string   $levelField  'access_level'(웹) | 'level'(API)
 * @param string   $levelDefault ''(웹: 없으면 bad_access_level) | 'public'(API)
 * @param callable $reject      fn(string $reasonCode, int $status, array $auditEntry): never — 감사 deny 남기고 응답 후 exit
 * @return array{id:string,name:string,level:string}
 */
function tbox_upload_run(array $user, string $levelField, string $levelDefault, callable $reject): array
```
- 기존 `$rejectBadRequest`·403 블록·[3-4] 400 은 전부 `$reject(사유코드, 상태, 감사엔트리)` 호출로 바꾼다. **사유 코드·감사 엔트리·상태 코드는 지금과 동일**해야 한다(웹 회귀 0).
- `sensitive_level_required` 는 지금도 예외적으로 문구를 알려 주는 400 이다 — 그대로.
- 트랜잭션·롤백·`Audit::allow('file.upload', …)`·성공 반환은 그대로. 성공 시 `['id'=>public_id,'name'=>$original,'level'=>$level]` 반환.

### 2) `files_upload.php` (웹) — 축소
`require_once __DIR__.'/_upload_common.php';` 뒤, `$reject` = "감사 deny 남기고 `Http::error($status, $status===403 ? MSG_FORBIDDEN : MSG_BAD_REQUEST)`" (지금과 같은 통일 문구). `tbox_upload_run($user,'access_level','',$reject)` → `Http::json(['id'=>…,'name'=>…], 201)`. **응답 모양·문구·감사 전부 불변.**

### 3) `api_upload.php` (API) — 이유 문구
`$reject` = 감사 deny 남기고 **사유 코드 → 한국어 이유** 매핑으로 `Http::json(['error'=>$msg], $status)`. 매핑표는 이 파일 안 상수 배열 하나. **`Upload::validate`·`checkDailyQuota`·`Authz::fitsUnderFolder`·`decideUploadAs` 가 내는 코드와 `_upload_common` 의 `$reject` 호출 코드를 전부 grep 해 빠짐없이** 넣는다. 예:
`no_file`→「file 필드에 파일이 없습니다」 · `bad_access_level`→「level 값이 올바르지 않습니다 (public·internal·team·admin-only)」 · `bad_team`→「team 등급은 team_id(활성 팀 번호)가 필요합니다」 · `description_too_long`→「설명은 500자까지입니다」 · 확장자 거부→「허용되지 않는 확장자입니다 (허용: pdf docx …)」 · 이중 확장자→「이중 확장자 파일은 올릴 수 없습니다」 · `mime_mismatch`→「파일 내용이 확장자와 다릅니다」 · 크기 초과→**413** 「파일이 100MB 를 넘습니다」 · 일일 총량→**413** 「오늘 업로드 총량(2GB)을 넘었습니다」 · `bad_folder`→「folder_id 가 없거나 접근할 수 없는 폴더입니다」 · 폴더 규칙 코드→「파일 등급이 폴더 등급보다 넓을 수 없습니다」 · 403(`decideUploadAs` 코드들)→「이 계정은 그 등급(팀)으로 올릴 권한이 없습니다」 · 모르는 코드→「잘못된 요청입니다」.
성공: `Http::json(['id','name','level'], 201)`.

### 4) `public/index.php`
- 라우트: `['POST', '/api/upload', 'api_upload.php', 'file.upload'],` (files 라우트 옆, 주석에 F-49·ARCH 4.2).
- `[2]` 블록 `if ($policy !== Authz::POLICY_PUBLIC) {` **맨 앞**:
  ```php
  if (TBOX_ROUTE === 'POST /api/upload') {
      $user = Auth::requireApiToken();   // 아래 5) — 실패 시 응답하고 exit
      define('TBOX_AUTH_METHOD', 'api_token');
      // CSRF · [3-1] · [3-2] 건너뜀 → 바로 [4] 인가로
  } else {
      … 기존 세션 흐름 그대로 …
  }
  ```
  `[4] Authz::authorize($policy, $user)` 이하는 공통(토큰 경로도 탄다). 재인증 관문 `[3-2]` 는 `file.upload` 가 `REAUTH_POLICIES` 에 없어 자동으로 해당 없음이지만, 코드 구조상 토큰 경로가 그 블록을 지나지 않게 하라.
- 다른 라우트에서는 `X-API-Token` 을 **읽지도 않는다.**

### 5) `src/Auth.php` · `src/Http.php`
- `Http::apiTokenHeader(): ?string` — `$_SERVER['HTTP_X_API_TOKEN']` trim, 빈 값→null, 256자 초과→null.
- `Auth::requireApiToken(): array`:
  1. `$cfg = Env::getOrEmpty('API_TOKEN')`; `strlen($cfg) < 32` → `Audit::deny('auth.token','not_configured', system/TBOX_ROUTE)` → `Http::json(['error'=>'API 토큰이 서버에 설정되지 않았습니다'], 503)`.
  2. 헤더 null → `missing_header` → 401 「X-API-Token 헤더가 없습니다」.
  3. `!hash_equals($cfg, $hdr)` → `bad_token` → 401 「API 토큰이 올바르지 않습니다」. (**절대** `==`/`===`/`strcmp`.)
  4. `$name = Env::getOrEmpty('API_TOKEN_USER')` → `SQL_LOAD_USER` 로 로드(`login()` 과 같은 SELECT 재사용, 비밀번호 검증 없음). 없음·`is_active` 아님·`nextStep() !== null` → `user_invalid` → 503 「API 토큰 귀속 계정이 올바르지 않습니다(서버 설정)」. 계정 이름은 응답에 넣지 않는다.
  5. 성공: `currentUser()` 가 주는 것과 **같은 배열**을 반환(role·team_id·id·username 등). `act_*`(역할로 보기)는 없음(NULL).
  감사 deny 의 target 은 `target_type=system`, `target_name=TBOX_ROUTE`(계정 정보 노출 없음).

### 6) `src/Audit.php`
- `SUMMARY_TEMPLATES` 추가: `auth.token|deny|missing_header`, `auth.token|deny|bad_token`, `auth.token|deny|not_configured`, `auth.token|deny|user_invalid` (문안은 기존 톤: 「API 토큰 없이 {target} 을 부르려 해서 막았습니다」 등).
- `ALLOWED_DETAIL_KEYS` 에 `auth` 추가.
- **자동 첨부**: 기록 함수 한 곳에서 `defined('TBOX_AUTH_METHOD')` 이면 `detail['auth'] = TBOX_AUTH_METHOD` 를 넣는다(값은 고정 분류어). 이렇게 하면 공통 업로드 함수를 고치지 않고 `file.upload allow` 에 `auth=api_token` 이 남는다.

### 7) `deploy/sites/tbox-v2.conf` — JSON 413
서버 블록에 (기존 `client_max_body_size 101m` 유지):
```nginx
error_page 413 = @json413;
location @json413 { default_type application/json; return 413 '{"error":"파일이 100MB 를 넘습니다"}'; }
```
문법만 확인(`sudo -n nginx -t` 가 되면 실행해 보고, 안 되면 못 했다고 적는다). **reload 는 하지 마라** — 배포는 Fable 이 한다.

### 8) `scripts/api_token_rotate.sh`
- `openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | head -c 48` 로 만들고, `.env` 의 `API_TOKEN=` 줄을 **그 줄만** 교체(없으면 추가). 첫 인자로 `API_TOKEN_USER` 를 받으면 그 줄도 같은 방식. 파일 소유·권한(0640) 유지(`sed -i` 는 권한을 보존한다 — 확인). **토큰 값은 stdout·stderr·로그 어디에도 출력하지 않는다.** 마지막에 `echo "API_TOKEN 갱신됨 — 값은 서버에서 cat .env 로 확인"` 만.
- **실행하지 마라.** 파일만 만든다(`bash -n` 만).

### 9) `.env.example` · `API.md`
- `.env.example`: `# ─── F-49 프로그램용 업로드 API ───` 주석 + `API_TOKEN=`(무작위 32자 이상, `scripts/api_token_rotate.sh`) + `API_TOKEN_USER=`(귀속 계정 아이디). 값은 비운다.
- `API.md`: 1장 표 "인증" 줄에 「`POST /api/upload` 만 예외 — `X-API-Token` 헤더(4장·7장)」 한 줄, 그리고 **7장** 추가: 표(인증·요청·성공·실패 네 줄 필수, 126행 규칙) + curl 예시(토큰은 `$API_TOKEN` 자리표시) + **토큰 값은 절대 적지 않고 「서버에서 `cat .env` 로 확인」** + 실패 예시(헤더 없음 401 · `.exe` 400 — 실제로 받은 응답을 붙일 것; 긍정 201 예시는 「관문 ③에서 사용자가 확인」이라 적고 **추정 응답을 적지 않는다**).

## 실행할 테스트 — `tests/api_upload.sh` (T-61 a~f). 반드시 실제 실행, 출력 원문 보고

규칙(CLAUDE.md 3장): 검토 계정 `qa_` 만 · 모든 요청 `-A "tbox-review/$(date +%F)"` · `BASE=http://127.0.0.1:8082` · 운영 DB·`.env`·nginx 에 손대지 않음. 관례는 `tests/health.sh`·`tests/stage26.sh`.
- **(a)** 헤더 없이 `POST /api/upload` → 401 `{"error":"X-API-Token 헤더가 없습니다"}` (단, 실제 `.env` 에 `API_TOKEN` 이 아직 없으면 503 `not_configured` 가 정답 — 스크립트는 둘 중 하나를 받아들이되 어느 쪽이었는지 출력) + 감사 행 확인(`sudo -n -u postgres psql`).
- **(b)** `X-API-Token: wrong-token-…` → 401 「API 토큰이 올바르지 않습니다」(또는 미설정이면 503) + `auth.token deny`.
- **(c)** 틀린 토큰 헤더로 `GET /api/users`·`DELETE /api/files/00000000-0000-4000-8000-000000000000` → 401 「로그인이 필요합니다」, `auth.token` 감사 행 **0**(다른 라우트는 헤더 무시).
- **(d)** 웹 회귀 — `qa_employee01` 로그인(`totp_code.php`, 1회성 주의) → `POST /api/files` 정상 txt 201 `{id,name}`(키 정확히 둘) · `x.exe` → 400 `{"error":"잘못된 요청입니다"}` · 감사 `file.upload deny` 사유 코드 전과 같음 · 올린 파일은 같은 세션으로 `DELETE` 해 정리.
- **(e)** 102MB 를 `/api/upload` 에(헤더 없이) → **413 + JSON** `{"error":…}`, `Content-Type: application/json` — nginx reload 전이면 HTML 이 나온다: 그 경우 「reload 전, HTML 413 실측」이라 적고 실패로 세지 않되 미검증 표시.
- **(f)** `scripts/security_lint.sh` 0 · 바뀐 PHP 전부 `php -l` · `bash -n` 두 스크립트 · `sudo -n nginx -t`(가능하면).

## 하지 말 것 (어기면 실패)
- **(g) 긍정 경로(진짜 토큰으로 201) 를 실행하지 마라.** 진짜 토큰은 사용자 계정(`API_TOKEN_USER`)으로 동작하고, 그 값을 읽으면 에이전트 기록에 비밀이 남는다(R-62). 관문 ③에서 사용자가 한다.
- 실제 `.env` 를 `cat`·`grep`·복사하지 마라. 키 존재 여부가 필요하면 `grep -c '^API_TOKEN=' .env` 처럼 **개수만**.
- 마이그레이션·DB 변경·nginx reload·php-fpm reload 금지. 커밋 금지(검토는 Fable).
- 시드 계정(`admin01` 등) 사용 금지.

## 보고
CLAUDE.md 2장 형식. 못 돌린 항목은 「미실행 — 이유」로. 추정 「통과」 금지.
