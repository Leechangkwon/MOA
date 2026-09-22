# F-68 지시서 (Fable → Opus) — 400 거부의 이유를 화면에: 사유 코드마다 사람 문장

> Fable 작성 2026-09-22. 사용자 결정 Q-27(2026-09-21 「이유를 표기하는 것을 기본으로」).
> 문서가 먼저 갱신돼 있다: PRD **F-68** · 7장 **S-09-12** · 12.2 **T-81** · 13장 · 14장 Q-27, ARCH **5.30**(문장 표 **원본**) · 5.27/5.29 보정, SECURITY **R-96**, STAGES F-68 절.
> **ARCH 5.30 을 먼저 읽고 시작한다. 문장은 그 표를 글자 그대로 옮긴다 — 여기서 새 문장을 짓지 않는다.**

```
목표        : 400 거부 응답의 문구를 「잘못된 요청입니다」 하나에서 사유 코드마다의 사람 문장(ARCH 5.30 표)으로 바꾼다.
              상태 코드·감사 사유 코드·응답 모양·403/404/인증 실패/URL 가져오기의 통일 문구는 한 글자도 바뀌지 않는다.
요구사항    : PRD F-68 ①~⑥ · S-09-12 · S-09-5 · T-81, ARCH 5.30(표·예외 목록) · 5.27 · 5.29, SECURITY R-96 · R-48 · R-45 · R-63
              화면(HTML·CSS·JS)은 바뀌지 않는다 — DESIGN.md 해당 없음.
수정 파일   : 1장 허용 목록. 목록 밖 파일은 만들거나 고치지 않는다.
금지 패턴   : `git add`·`git commit`·`git checkout`·`git stash`·`git reset`(다른 세션의 미커밋 변경이 같은 트리에 있다) · 시드 계정(admin01 …) 사용 ·
              운영 DB DDL/DML · `Audit.php`·`Authz.php`·`Auth.php`·`Upload.php`·`index.php`·`_upload_common.php` 수정 · 상태 코드 변경 ·
              감사 `Audit::deny(action, reason, …)` 의 action·reason 변경 · 응답에 `code`/`reason` 키 추가 · 문장에 대상 이름·개수·경로·예외 문구 넣기 ·
              `bad_credentials`·`bad_code`·`code_reused`·`same_password`·`weak_password`·`bad_request` 에 문장 붙이기 · 403 자리의 `MSG_FORBIDDEN` 변경 ·
              `files_fetch.php`(`MSG_FETCH_FAILED`) 변경 · `app.js`·`home.php`·`app.css`·`shell_*`·`*_page.php`(다른 세션 작업 중) 변경
완료 기준   : PRD T-81 (a)~(e) — 3장
보고 형식   : 4장
```

## 0. 작업 환경 — 운영 트리에서 직접, 순서를 지킨다

- 작업 트리는 `/var/www/tbox-v2` 이고 **그 트리가 곧 운영**(로컬 `http://127.0.0.1:8082`, 공개 도메인)이다. worktree 를 쓰지 않는 이유: 바뀌는 것이 문구뿐이고 시험이 8082 를 친다.
- `git status` 에 다른 세션(디자인·커뮤니티)의 미커밋 변경(`app.css`·`app.js`·`home.php`·`*_page.php`·`shell_*`·`DESIGN.md`·`docs/d*`·PRD/STAGES/SECURITY 일부)이 섞여 있다. **그 파일은 열어 보지도 고치지도 않는다.** git 은 `git status`·`git diff <파일>` 읽기만.
- 편집 순서와 확인(F-65 때 합친 직후 40분 장애가 났다):
  1. `src/Http.php` 에 **추가만**(상수·메서드) → `php -l src/Http.php` → `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8082/api/health` 가 200.
  2. 핸들러를 **한 파일씩** 고치고 매번 `php -l`. 15개가 끝나면 qa 계정으로 업로드 1건(txt) 201 · 팀 목록 200 · 폴더 목록 200 을 확인한다(스모크).
  3. 그 뒤에 시험 스크립트와 `API.md`.
- 파일 편집 중간에 문법 오류 상태로 두지 않는다(저장 = 운영 반영). 한 파일의 편집은 한 번에 끝낸다.

## 1. 수정 파일 — 허용 목록

**공통**: `src/Http.php` (상수 `REASON_UNIFORM` + 메서드 `reasonMessage()` **추가만**. 기존 상수·메서드는 건드리지 않는다)

**핸들러 15개** (각각 400 문구 자리만):
`src/handlers/org_team_update.php` · `folders_create.php` · `folders_update.php` · `files_update.php` · `users_update.php` · `users_create.php` · `users_mfa_reset.php` · `users_password_reset.php` · `api_tokens_issue.php` · `admin_permissions_update.php` · `fetch_hosts_update.php` · `mfa_enroll.php` · `share_create.php` · `files_upload.php` · `api_upload.php`

**시험**: 신규 `tests/f68.sh` · 기대값 줄만 갱신 `tests/api_folders.sh`(361 cycle · 425 description · 436 못 읽는 폴더 — 312/368/374 는 `bad_request` 라 **불변**) · `tests/f53.sh`(318 `deny()` 의 문구 단언) · `tests/f65.sh`(429 직원 internal) · `tests/api_upload.sh`(13·241 zip 안 `.exe`)

**문서(경미 보정만)**: `API.md` — `400 {"error":"잘못된 요청입니다"}` 로 적힌 실패 행·예시 중 이제 문장이 나가는 것(폴더 깊이·cycle·부모보다 넓은 등급·못 읽는 폴더·설명 길이 등)을 고친다. 허용 키 밖(`bad_request`)은 그대로 「잘못된 요청입니다」. PRD·ARCH·SECURITY·STAGES 는 Fable 이 이미 썼다 — 손대지 않는다.

## 2. 무엇을 어떻게 (ARCH 5.30 이 우선 — 여기는 구현 메모)

### 2-1. `src/Http.php`

```php
/**
 * 어떤 사유 코드에도 절대 문장을 붙이지 않는다 — 오라클 차단 (ARCH 5.30 예외 목록, R-48·R-45).
 * REASON_MESSAGES 에 누가 넣어도 이 목록이 이긴다.
 */
public const REASON_UNIFORM = ['bad_request', 'bad_credentials', 'bad_code', 'code_reused', 'same_password', 'weak_password'];

/**
 * 400 거부의 사유 코드 → 사람 문장 (PRD F-68 · S-09-12, ARCH 5.30 표가 원본).
 * 표에 없는 코드는 MSG_BAD_REQUEST. 403·404 자리에서는 부르지 않는다.
 */
public static function reasonMessage(string $reason): string
{
    if (in_array($reason, self::REASON_UNIFORM, true)) {
        return self::MSG_BAD_REQUEST;
    }
    if ($reason === 'ext_not_allowed') {
        // 허용 목록은 코드가 만든다 — 손으로 베끼면 목록이 바뀌는 날 어긋난다 (api_upload.php 의 옛 주석 그대로)
        return '허용되지 않는 확장자입니다 (허용: ' . implode(' ', Upload::ALLOWED_EXT) . ')';
    }
    return self::REASON_MESSAGES[$reason] ?? self::MSG_BAD_REQUEST;
}

/** @var array<string,string> ARCH 5.30 표 — 글자 그대로. 기존 상수는 상수를 가리킨다 */
private const REASON_MESSAGES = [
    'team_has_members' => '활성 구성원이 있는 팀은 비활성화할 수 없습니다 — 먼저 구성원을 다른 팀으로 옮기세요',
    // … ARCH 5.30 표의 나머지 행 전부 (ext_not_allowed 는 위 메서드에서) …
    'sensitive_level_required' => self::MSG_SENSITIVE_LEVEL_REQUIRED,
    'folder_has_children'      => self::MSG_FOLDER_HAS_CHILDREN,
    'folder_not_empty'         => self::MSG_FOLDER_NOT_EMPTY,
    'folder_has_trashed'       => self::MSG_FOLDER_HAS_TRASHED,
    'exec_ack_required'        => self::MSG_EXEC_ACK_REQUIRED,
];
```

- 표는 ARCH 5.30 의 행 순서대로, 문장은 **복사**한다(`—` 는 em dash U+2014, 괄호·중점 `·` 포함 글자 그대로). T-81 (a) 가 `Http.php` 와 ARCH 표를 대조한다.
- `Http.php` 는 `Upload` 를 참조하게 된다(`ext_not_allowed`). 자동 로딩이 이미 두 클래스를 다 읽는지 `public/index.php` 의 require 순서로 확인하고, 아니면 `reasonMessage()` 안에서만 `class_exists('Upload')` 로 지켜라 — `index.php` 는 고치지 않는다.
- 파일 머리 docblock 의 「문구는 아래 상수뿐이다」 문장에 한 줄 덧붙인다: 「400 의 사유 문장은 `reasonMessage()` 표(ARCH 5.30)에서만 나온다」.

### 2-2. 핸들러 — 패턴은 셋뿐

**(가) 닫힘 함수 안에서 `Http::error(400, Http::MSG_BAD_REQUEST)` 를 내는 것** — 그 한 줄을 `Http::error(400, Http::reasonMessage($reason))` 로.
`files_update.php:148` · `users_mfa_reset.php:61` · `users_password_reset.php:62` · `api_tokens_issue.php:75` · `admin_permissions_update.php:77`(`bad_code`·`code_reused` 는 `REASON_UNIFORM` 이 알아서 통일 문구로 떨어진다 — 분기 쓰지 말 것) · `fetch_hosts_update.php:83`(같음) · `users_update.php:94`(`$reject(string $action, string $reason)` — `$reason` 사용).

**(나) 호출자가 문구를 인자로 넘기는 것** — 닫힘 함수는 그대로 두고 **사유가 `bad_request` 가 아닌 호출 줄만** `Http::MSG_BAD_REQUEST` → `Http::reasonMessage('<그 코드>')`(또는 변수 `$fitCode` 등이면 `Http::reasonMessage($fitCode)`).
`org_team_update.php:124`(`team_has_members`) · `folders_create.php:157`(`$fitCode`)·`169`(`depth_exceeded`) · `folders_update.php:293`(`$fitCode`)·`299`·`334`(`depth_exceeded`)·`330`(`cycle`)·`449`·`456`·`603`(`children_exceed`)·`482`(`not_owner` — **400 자리라서** 문장; 다른 `not_owner` 403 자리는 없다는 것을 grep 으로 확인). `bad_request` 를 넘기는 줄(`folders_update.php` 130~278, `folders_create.php` 68~144, `org_team_update.php` 76~114)은 **손대지 않는다**.

**(다) 직접 `Http::error(400, Http::MSG_BAD_REQUEST)` 앞에 `Audit::deny(…, '<코드>', …)` 가 있는 것** — 문구만 `Http::reasonMessage('<그 코드>')`.
`users_update.php:215`(`$changeCode` — `self_change`·`last_admin`) · `users_create.php:149`·`206`(`already_exists`) · `mfa_enroll.php:59`(`already_enabled`) · `share_create.php:112`(`exec_content`) · `files_update.php:141`(`exec_content`). `bad_request` 사유의 자리(`users_create.php:53`, `share_create.php:49·118`, `files_update.php:67·364`, `mfa_enroll.php:34·53`)는 **그대로**.

**업로드 둘**
- `files_upload.php` `$reject`: 마지막 `Http::error($status, $reason === 'sensitive_level_required' ? … : Http::MSG_BAD_REQUEST)` → `Http::error($status, $status === 400 ? Http::reasonMessage($reason) : Http::MSG_FORBIDDEN)`. `exec_ack_required` 갈래(`next`·`entries`)와 403 갈래는 그대로. F-65 ④ 의 `bad_credentials`·`bad_code`·`code_reused` 는 `REASON_UNIFORM` 으로 통일 문구가 유지된다 — **응답에 `next` 가 없는 것도 그대로**(T-77 (b)).
- `api_upload.php` `$reasonTable`: 문장 열을 지우고 **상태 코드만** 남긴다 — `'too_large' => 413, 'quota_exceeded' => 413, 'role_not_permitted' => 403, 'team_mismatch' => 403`, 그 밖은 표에서 빠진다(기본 `$status`). `$reject` 안은
  `$code = $statusTable[$reason] ?? $status; $message = $code === 403 ? '이 계정은 그 등급(팀)으로 올릴 권한이 없습니다' : Http::reasonMessage($reason);`.
  머리 주석(「표에 없는 코드는 … 그물이지 설계가 아니다」)은 「문장은 `Http::reasonMessage` 한 표(ARCH 5.30)에서 온다 — 새 사유 코드를 만들면 그 표에 한 줄을 더한다」로 고쳐 쓴다. **T-61 의 문장은 글자까지 그대로여야 한다** — `tests/api_upload.sh` 가 그대로 통과하는 것으로 확인(단 241 줄의 zip 안 `.exe` 는 이제 `zip_blocked_entry` 문장 — 그 줄과 13 줄 주석은 고친다).

**고치지 않는 파일(다시)**: `_upload_common.php`(사유 코드를 만들어 넘길 뿐, 문구를 정하지 않는다) · `files_fetch.php` · `login.php`·`reauth.php`·`password_change.php`·`mfa_confirm.php`·`mfa_disable.php`·`files_exec_download.php`·`me_act_start.php`·`api_tokens_revoke.php`·`share_revoke.php`·`files_preview_raw.php`·`folders_delete.php`·`org_team_create.php`(전부 `bad_request`/인증/기존 상수만이라 바뀌는 것이 없다).

### 2-3. `tests/f68.sh` — `tests/f65.sh` 의 골격을 그대로

머리 주석·안전 관문(최근 10분 비-qa actor 대기, 디스크 1GB, `F68-` 접두, 검토 UA, 실패 카운터 규칙)·`expect/skip/pass/fail` 헬퍼·정리(`trap`)는 `f65.sh` 를 따른다. 로그인·CSRF·재인증·TOTP(`scripts/totp_code.php`, `qa-totp.txt`)도 같은 방식.

(a) 문장 갈래 — 각 항목마다 **상태 400 · 본문 키가 `error` 하나 · `error` 가 ARCH 5.30 문장과 정확히 같음 · 직전 감사 행의 reason 이 옛 코드와 같음**(psql, `f65.sh` 의 `mark()`/최근 행 조회 방식) · 문장에 시험이 만든 이름(`F68-…`)·숫자 개수·다른 계정 이름이 들어 있지 않음:
1. `team_has_members` — qa_admin01(재인증) `GET /api/org/teams` 로 qa_employee01 의 팀 id 와 `member_count>0` 확인 → `PATCH /api/org/teams/{id}` `{is_active:false, reason_text:"F68 시험"}` → 400. **`member_count` 가 0 이면 이 항목은 skip**(팀을 실제로 내릴 위험을 만들지 않는다). 성공(200)이 나오면 즉시 `is_active:true` 로 되돌리고 fail.
2. `depth_exceeded` — `F68-d1`~`F68-d6` 을 차례로 만들어 6번째 400. 정리에서 역순 삭제.
3. `cycle` — `F68-a` 아래 `F68-b` → `PATCH F68-a {parent_id: b}` 400.
4. `parent_narrower` — `F68-p`(internal) 아래 `{name:"F68-c", access_level:"public"}` 400.
5. `children_exceed` — `F68-p`(internal) 아래 `F68-q`(internal) 를 두고 `PATCH F68-p {access_level:"team", team_id:<qa_admin01 팀>}` cascade 없이 → 400.
6. `description_too_long` — `F68-desc.txt` 업로드 뒤 `PATCH /api/files/{id} {description: 'a'×501}` 400.
7. `no_change` — 같은 파일에 현재 설명과 같은 값 PATCH → 400.
8. `already_exists` — qa_admin01 `POST /api/users` `{username:"qa_employee01", …필수 필드}` → 400 (아무것도 만들어지지 않음을 users 조회로 확인).
9. `self_change` — qa_admin01 `PATCH /api/users/{자기 id}` `{role:"Manager", reason_text:"F68 시험"}` → 400, 역할 불변 확인.
10. `ext_not_allowed` — 웹 업로드 `F68-x.exe` → 400, 문장에 `txt` 등 `ALLOWED_EXT` 원소가 들어 있음(`Upload::ALLOWED_EXT` 를 `php -r` 로 읽어 비교).
11. `zip_blocked_entry` — qa_employee01 이 `x.sh` 든 zip(internal) 업로드 → 400 문장 · **`next` 키 0** · 감사 `file.upload deny zip_blocked_entry`.
12. `macro_found` — `f53.sh` 의 매크로 픽스처 생성 방식을 그대로 가져와 400.
13. `limit_exceeded` — qa_admin01(재인증)이 qa_employee01 에게 `POST /api/users/{id}/tokens` 를 살아 있는 토큰이 5개가 될 때까지 발급(기존 살아 있는 수를 먼저 세고 부족분만) → 6번째 400. 정리에서 시험이 만든 토큰만 회수.
14. `already_enabled` — qa_admin01 `POST /api/me/mfa/enroll` → 400(2단계가 켜진 계정), `mfa_secret` 불변 확인.
15. 표 대조 — `php -r` 로 `Http::REASON_MESSAGES` 는 private 이라 못 읽으니 `Http::reasonMessage()` 를 ARCH 5.30 표의 코드마다 불러 문장을 얻고, `ARCHITECTURE.md` 5.30 표를 파싱한 문장과 **글자 대조**(`ext_not_allowed` 는 접두 「허용되지 않는 확장자입니다 (허용: 」 만). 불일치 0.

(b) 통일 문구 갈래 — 각각 **상태와 문구가 옛 값과 같음**:
1. 허용 키 밖 — `PATCH /api/files/{id} {foo:1}` → 400 「잘못된 요청입니다」.
2. `POST /api/me/password {current:"틀림", new:"F68-new-password-1"}` (qa_employee01) → 400 「잘못된 요청입니다」. **1회만**, 직후 다시 로그인(실패 집계 0).
3. `POST /api/me/mfa/disable` (qa_admin01) 맞는 비밀번호 + 틀린 코드 `000000` → 400 문구가 2 와 **같음**. 1회만, 직후 재로그인.
4. qa_employee01 이 qa_admin01 의 admin-only 파일(`F68-adm.txt` 를 먼저 올림) `GET …/download` → 403 「권한이 없습니다」.
5. `GET /s/<43자 무작위>` → 404 「찾을 수 없습니다」.
6. `POST /api/files/fetch {url:"http://127.0.0.1:1/x"}` → 400 「가져올 수 없습니다」.
7. F-65 확인 업로드 틀린 비밀번호 — `f65.sh` (a) 와 같은 요청 1회 → 400 「잘못된 요청입니다」, `next` 0. 직후 재로그인.

(c) `bash tests/api_upload.sh` 를 **이 스크립트가 직접 실행**하지는 않는다 — 회귀는 4장 보고에서 따로 돈다.

(d)(e) `security_lint.sh` 0 · 바뀐 PHP `php -l` · `bash -n` · 정리(파일은 `DELETE` 로 휴지통, 폴더 역순 삭제, 토큰 회수) · 마지막에 「검토 UA 로 들어온 비-qa actor 행 0」 확인(f65 (h) 방식).

### 2-4. 기존 시험 기대값 — **그 줄만**

- `api_folders.sh:361` cycle 문구 → `{"error":"폴더를 자기 자신이나 그 하위 폴더 안으로 옮길 수 없습니다"}` · `:425` description → 「설명은 500자까지입니다」 · `:436` 못 읽는 폴더 → 「folder_id 가 없거나 접근할 수 없는 폴더입니다」. (`312`·`368`·`374` 는 허용 키 밖 = 불변.) 각 줄의 옛 사유 코드가 실제로 그 코드인지 스크립트 앞뒤를 읽고 확인한 뒤 고친다 — 확신이 없으면 고치지 말고 보고.
- `f53.sh:318` `deny()` — 문구 단언을 `Http::reasonMessage("$2")` 의 값과 비교하도록(`php -r` 한 줄로 얻는다) 바꾼다. 8종 각각의 문장이 나가는지가 곧 F-68 의 검증이다.
- `f65.sh:429` 직원 internal → 「압축본 안에 실행 파일이나 스크립트가 있어 올릴 수 없습니다」. 353·366·584(틀린 비밀번호·코드)는 **불변**.
- `api_upload.sh:241` zip 안 `.exe` → 같은 `zip_blocked_entry` 문장, 13 줄 주석도.

## 3. 완료 기준 — PRD T-81 (a)~(e)

`sudo -v && bash tests/f68.sh` 실패 0 · 회귀 `tests/api_upload.sh`·`api_folders.sh`·`f53.sh`·`f65.sh`·`api_tokens.sh`·`stage26.sh`·`help_button.sh` 실패 0 · `scripts/security_lint.sh` 0건 · 바뀐 PHP 전부 `php -l`. `f65.sh` 는 20분 넘게 걸린다 — 마지막에 돌린다.

## 4. 보고 형식

1. 변경 파일 목록(줄 번호 범위와 한 줄 요약) — 15 핸들러는 (가)/(나)/(다) 어느 패턴인지.
2. 실행한 명령과 출력 **원문**: `php -l` 전부 · 스모크 3건(업로드 201·팀 목록 200·폴더 목록 200)의 상태 줄 · `f68.sh` 통과/실패/건너뜀 수와 실패 줄 원문 · 회귀 7종의 마지막 줄 · lint.
3. 못 한 것과 이유(skip 된 항목 포함 — 예: `member_count` 0 으로 1번 skip).
4. 판단이 필요했던 곳 — 지시서와 코드가 어긋난 자리, 문장이 표에 없던 사유 코드(있다면 **문장을 짓지 말고** 코드 이름을 보고).
