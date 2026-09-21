# F-65 지시서 (Fable → Opus) — 실행 파일이 든 압축본의 관리자 확인 업로드 · 확인 다운로드

> Fable 작성 2026-09-21. 사용자 결정 Q-23(「2차 인증·주의 사항으로 보완」 → 「받는 쪽도 OTP」).
> 문서가 먼저 갱신돼 있다: PRD **F-65** · 7.6 S-06 「관리자 확인 예외」 행 · 8장 `POST /api/files/{id}/exec-download` 행 · 7.11 **AL-21** · 12.2 **T-77** · 14장 Q-23, ARCH **5.29**(명세 본문) · 7.4(16행) · 7.5(키 4개), SECURITY **R-94**, API.md **9-1**, STAGES F-65 절. **전부 읽고 시작할 것. ARCH 5.29 의 두 표가 곧 명세다 — 어긋나면 멈추고 보고.**

```
목표        : F-53 의 `zip_blocked_entry` 한 갈래를 「Admin·admin-only·웹 세션·요청마다 비밀번호+OTP·확인 문구」로 통과시키고,
              그 파일(`files.exec_content=true`)은 공유·토큰·미리보기·등급 변경을 막고 받을 때도 요청마다 비밀번호+OTP 를 묻는다.
              그 밖의 모든 업로드·다운로드 동작은 한 글자도 바뀌지 않는다.
요구사항    : PRD F-65 ①~⑧ · S-06 · S-01 · S-07 · 7.7(비밀 미기록) · T-77, ARCH 5.29 · 5.2 · 5.3 · 5.12 · 5.28 · 7.4 · 7.5, SECURITY R-45 · R-48 · R-94
수정 파일   : 1장 허용 목록. 목록 밖 파일은 만들거나 고치지 않는다.
금지 패턴   : 운영 DB DDL/DML(마이그레이션은 임시 DB 로만 검증) · `git add -A` · 커밋 · 시드 계정 · 비밀번호/OTP 값을 감사·로그·응답에 싣기 ·
              `reauth_at` 을 놓거나 세션에 「확인됨」 상태를 남기기(요청마다 확인이 명세) · GET 쿼리에 비밀번호 · innerHTML ·
              `admin_permissions_update.php` 수정 · `Upload::ALLOWED_EXT`/`BLOCKED_EXT` 변경 · 토큰 입구(`/api/upload`)·URL 가져오기에 예외 열기
완료 기준   : PRD T-77 (a)~(h) — 3장
보고 형식   : 4장
```

## 1. 수정 파일 — 허용 목록

**신규**: `db/migrations/2026-09-21_files_exec_content.sql` · `src/handlers/files_exec_download.php` · `tests/f65.sh`

**보안 핵심(CLAUDE.md 4장, 이번에 허용)**: `src/Auth.php`(helper 두 개 **추가만**) · `src/Upload.php`(`validate`/`validatePath`/`inspectZip` 의 `allowBlockedEntries` 인자와 예외 클래스) · `src/Audit.php`(7.4 문장 16행 · 7.5 키 4개) · `db/schema.sql` · `public/index.php`(라우트 1행 — `TBOX_API_TOKEN_*` 에는 넣지 않는다) · `deploy/install.sh`(4-4-1 감지 목록 한 줄만)

**핸들러·공통**: `src/handlers/_upload_common.php` · `files_upload.php` · `files_download.php` · `files_preview.php` · `files_preview_raw.php` · `share_create.php` · `share_download.php` · `files_update.php` · `files_list.php` · `src/Http.php`(문구 상수 `MSG_EXEC_ACK_REQUIRED` = 「실행 파일이 든 압축본입니다 — 비밀번호와 2단계 인증 코드로 확인해야 합니다」)

**화면**: `src/handlers/home.php`(올리기 창 확인 블록 · 확인 다운로드 창 `<dialog>`) · `public/assets/app.js` · `public/assets/app.css`(배지 `badge--exec` 한 규칙 + 확인 블록 최소 규칙, 인라인 style 0)

**운영·경보**: `scripts/alerts.php`(AL-21 — AL-20 블록과 같은 모양)

**문서(경미 보정만)**: `ARCHITECTURE.md` 6.2 `files` 표에 `exec_content` 행 1개 · `README.md` 사용 안내에 「실행 파일 포함」 문단 1개 · `STAGES.md` F-65 절 ① 체크 표만. PRD·SECURITY·API.md·ARCH 5.29/7.4/7.5 는 Fable 이 썼다 — 손대지 않는다.

## 2. 무엇을 어떻게 (ARCH 5.29 가 우선 — 여기는 구현 메모)

### 2-1. 표
- 마이그레이션(`2026-09-16_api_tokens.sql` 머리 형식): `ALTER TABLE files ADD COLUMN IF NOT EXISTS exec_content BOOLEAN NOT NULL DEFAULT false; COMMENT ON COLUMN …`. `schema.sql` `files` 에 같은 열(`scan_status` 아래, 주석에 ARCH 5.29). `grants.sql` 변경 없음(열 추가는 표 권한 그대로). `install.sh` 4-4-1 에 `"exec_content|files|2026-09-21_files_exec_content.sql"`.

### 2-2. `src/Auth.php` — helper 둘, **기존 함수는 건드리지 않는다**
- `public static function verifyCurrentPassword(array $user, string $password): bool` — `reauth()` 의 `SQL_LOAD_BY_ID` + `password_verify` 부분만. 감사·실패 집계는 **호출자**가 한다(사유가 action 마다 다르므로). `reauth_at` 을 놓지 않는다.
- `public static function verifyOtpOnce(array $user, string $otp): string` — `admin_permissions_update.php` 151~170행의 규칙 그대로: `mfa_enabled` 거짓 → `'mfa_not_enabled'` · `Totp::verifyStep` 실패 → `'bad_code'` · `mfa_last_step` 과 같거나 작으면 → `'code_reused'` · 통과면 `mfa_last_step` 갱신 후 `'ok'`. 코드 형식(`Totp::CODE_RE`)은 호출자가 먼저 검사. 주석에 「`admin_permissions_update.php` ②와 같은 규칙 두 벌 — R-94 비고」.

### 2-3. `src/Upload.php`
- 새 예외 `final class UploadBlockedEntries extends RuntimeException { public function __construct(public readonly array $entries) { parent::__construct('zip_blocked_entry'); } }` — 파일 상단, 기존 `RuntimeException('zip_blocked_entry')` 자리를 이것으로. 메시지가 같으므로 기존 `catch (RuntimeException $e) { $e->getMessage() }` 흐름은 **그대로 동작**한다(회귀 f53.sh 가 증명).
- `validate(array $file, bool $allowBlockedEntries = false)` → `validatePath(…, $allowBlockedEntries)` → `inspectContent` → `inspectZip(…, $allowBlockedEntries)`: 참이면 차단 이름을 배열에 모으고 계속(나머지 검사는 그대로 던진다), 끝에 이름 목록을 반환값 `['exec_entries' => [...]]` 로 싣는다(기존 반환 배열에 키 추가). 거짓이면 첫 항목에서 `UploadBlockedEntries`(entries 에는 **그 시점까지 모은 이름 전부** — 화면에 보여 줄 목록이라 첫 항목만이 아니라 끝까지 훑은 목록이어야 한다. 즉 순서: 이름 검사에서 걸리면 모으기만 하고, 루프가 끝난 뒤 `$allowBlockedEntries` 가 거짓이고 목록이 비어 있지 않으면 던진다. 경로 탈출·중첩·폭탄은 만나는 즉시 던진다 — 그 셋은 예외가 없다).

### 2-4. `_upload_common.php` · `files_upload.php`
- 필드 읽기 자리(53행 부근)에서 `exec_ack`·`password`·`otp` 를 `$_POST` 에서 읽고 **즉시 `unset($_POST[...])`**. 세 값은 지역 변수에만.
- `Upload::validate` 를 `try` 로 감싸 `UploadBlockedEntries` 만 따로 잡는다. 갈래는 ARCH 5.29 「올리기」 표 ①~⑤ 그대로. 자격 판정은 `$user['real_role'] === 'Admin' && ($user['acting'] ?? false) !== true && $level === 'admin-only' && !defined('TBOX_AUTH_METHOD')` — 이 한 줄이 Authz 밖의 역할 비교이므로 **`Authz::canUploadExec(array $u): bool`** 로 두고 여기서는 그 함수만 부른다(`scripts/security_lint.sh` 의 역할 비교 검사가 걸리지 않게; `real_role` 비교는 린트 (f) 의 허용 파일 밖이므로 Authz 에 둔다 — Authz 는 이번 목록에 없지만 **이 함수 하나 추가**는 허용한다).
- ③ 갈래의 응답: `$reject` 로는 통일 문구만 나가므로, `files_upload.php` 의 `$reject` 에 `exec_ack_required` 분기를 더해 `Http::json(['error' => Http::MSG_EXEC_ACK_REQUIRED, 'next' => 'exec_ack', 'entries' => $entry['detail']['exec_entries']], 400)`. `entries` 는 ≤ 20, 문자열 그대로(화면이 textContent 로만 넣는다). 감사 action 은 `file.upload_exec`.
- ④ 확인 순서: 비밀번호 → OTP. 실패 사유마다 `Audit::deny('file.upload_exec', …)` + `bad_credentials`·`bad_code`·`code_reused` 는 `Auth::countFailureAndRevoke`. `mfa_not_enabled` 는 403.
- ⑤ 통과 뒤 `Upload::validate($uploaded, true)` 로 다시 검사(비용은 zip 목록 한 번 더 — 100MB zip 도 0.1초). INSERT 에 `exec_content = true`. 감사 `Audit::allow('file.upload_exec', …)` detail 에 `exec_ack:true`·`otp_verified:true`·`exec_entry_count`·`exec_entries`(≤20) + 기존 업로드 detail 키. **`file.upload allow` 는 남기지 않는다**(한 업로드에 allow 두 줄이 생기지 않게 — action 이 갈린다).

### 2-5. 받기 관문 (ARCH 5.29 「받기」 표)
- `files_download.php`·`files_preview.php`·`files_preview_raw.php`: SELECT 에 `f.exec_content` 추가, `decideRead` **뒤** · `scan_status` **앞**에 관문. 토큰(`TBOX_AUTH_METHOD` 정의)이면 `exec_content_token` 403 `MSG_FORBIDDEN`, 세션이면 `exec_ack_required` 403 `{error: MSG_EXEC_ACK_REQUIRED, next:'exec_ack'}`.
- `share_create.php`: `decideEdit` 뒤 `exec_content` 면 400 통일 문구 + `share.create deny exec_content`. `share_download.php`: 링크 조회 SELECT 에 `f.exec_content`, 기존 404 갈래 하나 추가(사유 `exec_content`).
- `files_update.php`: `access_level` 키가 있고 파일이 `exec_content` 면 값이 무엇이든 400 + `file.update deny exec_content`(등급이 같아도 거부 — 단순하게). `description`·`folder_id` 는 그대로.
- **`files_exec_download.php`** — `['POST', '/api/files/{id}/exec-download', 'files_exec_download.php', 'file.read']` 를 `download` 행 바로 아래. 본문 허용 키 `password`·`otp` 둘(그 밖은 400 `bad_request`). 순서: uuid → 행(`exec_content` 포함) → 404 → `decideRead` 403 → `exec_content` 거짓이면 400 `bad_request` → `verifyCurrentPassword` → `verifyOtpOnce` → `scan_status` 관문(5.28, 423/403) → `Storage::assertExists` → sha256 대조 → `Audit::allow('file.download_exec', …)` → `files_download.php` 와 **같은 전송 코드**(헤더·`X-Accel`/readfile 방식 그대로 — 그 부분을 복사하지 말고 `files_download.php` 의 전송부를 함수로 뽑아 둘이 같이 쓸 수 있으면 그렇게, 어렵거나 위험하면 복사하고 보고에 적는다). 비밀번호·코드는 읽은 직후 `unset`.
- `files_list.php`: 항목에 `'exec_content' => $row['exec_content'] === true`(SELECT 추가). 토큰용 간결 배열(329행 부근)에도 `exec_content` 추가(API.md 9-1 이 그 값을 말한다).

### 2-6. 화면 (`home.php` · `app.js` · `app.css`)
- 올리기 창: `uploadOne` 의 400 응답에 `next === 'exec_ack'` 이면 그 파일의 상태 줄 아래에 확인 블록(`<div class="upload-exec">`: 경고 문장 · `<ul>` 항목 이름(textContent) · 체크박스 「이 묶음에는 실행 파일이 있습니다. 내가 만든 것이 맞고 내용을 압니다」 · 비밀번호 · 2단계 코드 · 「확인하고 올리기」). 체크 없이는 요청을 보내지 않고 안내만. 보낼 때 같은 파일로 `FormData` 에 `exec_ack=1`·`password`·`otp` 를 더해 다시 `POST /api/files`. 성공·실패 문구는 기존 상태 줄에.
- 목록: `file.exec_content === true` 면 「실행 파일 포함」 배지(`badge badge--exec`, `addNameCell` 의 scan 배지와 같은 방식). 다운로드 링크 대신 단추 → `<dialog id="exec-download-dialog">`(올린 사람·올린 날짜·경고 문장·비밀번호·코드·「확인하고 받기」) → `fetch POST …/exec-download` → `blob` → `a[download]`. 실패 400 은 「비밀번호 또는 코드가 올바르지 않습니다」 한 문구(서버 문구 그대로 써도 된다). 공유 단추는 `exec_content` 면 만들지 않고, 편집 창의 등급 셀렉트는 `disabled`.
- 전부 `textContent`·`createElement`. `node` 가 없으니 문법 확인은 Playwright 파싱으로(T-76 (b) 방식).

### 2-7. 감사·경보
- `Audit.php` `SUMMARY_TEMPLATES` 에 ARCH 7.4 의 16행을 **글자 그대로**. `ALLOWED_DETAIL_KEYS` 에 `exec_ack`·`otp_verified`(이미 있으면 그대로)·`exec_entry_count`·`exec_entries`. `FORBIDDEN_DETAIL_KEYS` 부분 일치(`otp`)에 `otp_verified` 가 걸리는지 확인 — 17-2 가 이미 쓰는 키이므로 예외 목록에 있어야 정상. 없으면 넣고 보고.
- `alerts.php`: AL-21 = `file.upload_exec allow` 1건이라도 즉시, dedup 은 AL-20 과 같은 30분 규칙, evidence `target_name`·`actor_name`·`detail.exec_entry_count`. 머리 주석의 규칙 목록에 AL-21 추가.

### 2-8. 시험 `tests/f65.sh` — T-77 (a)~(h) 를 항목 이름 그대로 `expect` 로. 형식은 `tests/f54.sh`. zip 은 `python3 -c "import zipfile…"` 로 만든다(`x.php`·`run.sh`·`a.txt`). OTP 는 `scripts/totp_code.php`; 같은 30초 창에서 두 번 쓰면 `code_reused` 가 **정상**이라 (a) 의 재사용 항목은 그 성질을 이용하고, 그 밖의 호출은 창이 바뀔 때까지 기다린다(`stage26.sh` `login` 의 대기 방식). 개인 토큰이 필요한 항목은 `tests/api_tokens.sh` 가 만드는 방식으로 qa 계정 토큰을 발급·회수. 끝에 시험 파일은 휴지통으로, 만든 토큰은 회수, 비-qa 행 수 불변 확인(stage26 방식).

## 3. 완료 기준 — PRD T-77 (a)~(h) 전부. 추가로:
- `scripts/security_lint.sh` 0건 · 바뀐 `.php` 전부 `php -l` · `bash -n tests/f65.sh` · Playwright 파싱으로 `app.js` 오류 0
- `grep -rn "password\|otp" src/handlers/_upload_common.php src/handlers/files_exec_download.php` 의 모든 줄이 「읽기 → unset」 뿐이고 `Audit::`·`error_log`·`Http::json` 인자에 그 변수가 없음(줄 번호로 보고)
- 운영 DB `\d files` 에 `exec_content` **없음**(네가 실행하지 않았다는 증거) · 임시 DB 로 마이그레이션·새 schema 검증(T-76 (f) 방식)
- 회귀: `tests/f53.sh` · `f54.sh` · `api_tokens.sh` · `api_upload.sh` · `help_button.sh` · `stage26.sh` 통과

## 4. 보고 형식
변경 파일(신규/편집) / T-77 (a)~(h) 항목별 명령·출력 원문 / 3장 추가 기준 각각의 원문 / 전송부 함수 추출 여부와 근거 / 못 한 것·미결 / 사용자가 실행할 것: `sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-09-21_files_exec_content.sql` (**운영 DB 에 이 열이 없는 동안은 새 코드가 SELECT 에서 실패한다** — 그러므로 배포 순서는 「마이그레이션 → 코드」다. 시험은 임시 DB 가 아니라 운영 DB 로 도니, 시험 전에 Fable 에게 마이그레이션 실행을 사용자에게 청하라고 **먼저 보고하고 멈춘다**. 코드 작성·정적 검사·임시 DB 검증까지 한 뒤 보고.)
