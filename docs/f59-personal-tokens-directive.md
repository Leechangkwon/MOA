# F-59 지시서 — 사용자별 프로그램 토큰(설계 B) + 개인 토큰으로 삭제

> Fable 작성 2026-09-16. 사용자 결정 「진행해」(F-58 검토 결론: 지우기를 프로그램에 열려면 누가 지웠는지 사람 단위로 남는 토큰이 먼저).
> 문서가 먼저 갱신돼 있다: PRD 8장(`/api/users/{uid}/api-tokens` 3행 · `DELETE /api/files/{id}`·`DELETE /api/folders/{num}` 행) · 7.11 **AL-20** · 13장 **F-59** · 12.2 **T-71**, ARCH **4.6** · **6.2-5** · 7.4(9행) · 7.5(3행), SECURITY R-82(갱신)·**R-91**, API.md 0장·**13장·14장**(실측 자리 비움). **전부 읽고 시작할 것.** 특히 ARCH 4.6 의 판정 순서 표가 곧 `requireApiToken` 의 명세다.

## 목표

계정별 개인 토큰(`api_tokens`, sha256 저장)을 관리자가 발급·회수하게 하고, `X-API-Token` 인증이 개인 토큰을 먼저 보게 한 뒤, 삭제 창구 둘을 **개인 토큰에만** 연다. 공용 토큰(`.env`)은 이행용으로 남긴다.

## 요구사항

- PRD F-59 ①~⑧ · 8장 다섯 행 · 12.2 T-71 · 7.11 AL-20 · S-01 · S-07(관리자 행동 사유) · S-09-11(비밀 미기록) · R-07(허용 키)
- ARCH 4.2·4.3(불변 부분) · **4.6**(판정 순서·감사·창구) · **6.2-5**(표) · 7.4·7.5(감사 문장·키) · 5.16(폴더 삭제 세 갈래 불변)
- SECURITY R-82 · R-90 · **R-91**
- API.md 13장·14장 · 「추가 규칙」

## 명세 요약 (문서가 우선 — 어긋나면 멈추고 보고)

| 항목 | 내용 |
|---|---|
| 표 | `db/migrations/2026-09-16_api_tokens.sql`(BEGIN/COMMIT + 권한 — `2026-09-17_fetch_hosts.sql` 형식) **과** `db/schema.sql`(6.2-5 그대로) **과** `db/grants.sql`(`SELECT, INSERT, UPDATE` — DELETE 없음 · 시퀀스 `USAGE, SELECT` · `tbox_backup` SELECT · REVOKE 목록에 추가) 세 곳을 같은 모양으로. `deploy/install.sh` 4-4-1 감지 목록에 `"token_hash|api_tokens|2026-09-16_api_tokens.sql"` |
| 라우트 | `['GET', '/api/users/{num}/api-tokens', 'api_tokens_list.php', 'user.manage']` · `['POST', …, 'api_tokens_issue.php', 'user.manage']` · `['DELETE', '/api/users/{num}/api-tokens/{num}', 'api_tokens_revoke.php', 'user.manage']` — `/api/users/{num}/mfa/reset` 행 아래. `$params[0]`=uid, `$params[1]`=tid |
| 토큰 상수 | `TBOX_API_TOKEN_ROUTES` 에 `'DELETE /api/files/{id}'`·`'DELETE /api/folders/{num}'` 추가(→ **열**). 새 상수 `TBOX_API_TOKEN_PERSONAL_ROUTES = ['DELETE /api/files/{id}', 'DELETE /api/folders/{num}']`. `TBOX_API_TOKEN_ONLY_ROUTES` 무변경. [2-T] 는 `Auth::requireApiToken(in_array(TBOX_ROUTE, TBOX_API_TOKEN_PERSONAL_ROUTES, true))` 로 부른다. 주석의 「여덟」→「열」 |
| `Auth::requireApiToken(bool $personalOnly = false)` | ARCH 4.6 표의 순서 그대로. ① 헤더 없음 401 `missing_header`(불변) ② `hash('sha256', $sent)` 로 `api_tokens`(`revoked_at IS NULL`) 조회 → 있으면 `SQL_LOAD_USER` 와 **같은 열 목록**으로 `WHERE id = :id` 조회(새 상수 `SQL_LOAD_USER_BY_ID`), 4조건 실패 → `denyApiToken('user_disabled')` + `Http::error(403, Http::MSG_FORBIDDEN)`; 성공 → `$row` 앉힘 + `define('TBOX_API_TOKEN_KIND','personal')` + `define('TBOX_API_TOKEN_ID', (int) id)` + `last_used_at` 갱신(`WHERE id=:id AND (last_used_at IS NULL OR last_used_at < now() - interval '60 seconds')`) ③ 없으면 공용: `API_TOKEN` 32자 이상 **이고** `hash_equals` → 기존 ④(귀속 4조건, 실패 503 `user_invalid`) 그대로 + `define('TBOX_API_TOKEN_KIND','shared')` ④ 둘 다 아님 → `bad_token` 401 ⑤ `$personalOnly && KIND==='shared'` → `denyApiToken('shared_token_not_permitted')` + `Http::error(403, '이 창구는 개인 토큰으로만 쓸 수 있습니다')`(문구는 `Http` 상수 `MSG_PERSONAL_TOKEN_REQUIRED` 로). `not_configured` 갈래는 **제거**(문장 틀은 남긴다). `hash_equals`·`unset(password_hash…)`·`self::$user` 앉히기는 기존 코드 재사용 |
| 감사 | `Audit::autoDetail`: `TBOX_API_TOKEN_KIND` 가 정의돼 있으면 `token_kind`, `TBOX_API_TOKEN_ID` 가 있으면 `token_id`(int). `ALLOWED_DETAIL_KEYS` 에 `token_kind`·`token_id`·`token_name` 정확 일치 예외(주석에 ARCH 7.5 근거). `SUMMARY_TEMPLATES` 에 ARCH 7.4 의 **9행을 글자 그대로** |
| 발급 `api_tokens_issue.php` | 허용 키 `name`·`reason_text`(둘 다 필수, R-07 — 다른 키 400). `name` 은 폴더 이름과 같은 규칙(UTF-8·제어문자 제거·1~50자), `reason_text` ≤500. 대상 `users` 행 없으면 404. 대상 `is_active=false` 또는 만료 → 400 `target_inactive`. 살아 있는 토큰(`revoked_at IS NULL`) ≥5 → 400 `limit_exceeded`. 토큰 = `random_bytes(64)` → base64 → `[A-Za-z0-9]` 만 → 앞 48자(`scripts/api_token_rotate.sh` 와 같은 방식, 48자 미만이면 500). INSERT(`token_hash`=sha256 hex, `hint`=앞 6자, `created_by`=행위자, `reason_text`). 201 `{"id","name","hint","token"}`. 감사 `api_token.issue allow` target_type `user`·target_id·target_name(대상 username), detail `token_id`·`token_name`·`reason_text`. **토큰 원문·hint 는 감사·로그 어디에도 넣지 않는다.** 자기 자신 대상 허용(`decideUserChange` 부르지 않음) |
| 목록 `api_tokens_list.php` | 대상 없으면 404. `{"tokens":[{id,name,hint,created_at,last_used_at,revoked_at}]}` id 오름차순. 감사 없음(조회) |
| 회수 `api_tokens_revoke.php` | 대상·토큰 행(`user_id`=uid) 없으면 404 + `api_token.revoke deny not_found`. 이미 회수 → 400 `bad_request`. UPDATE `revoked_at=now(), revoked_by=행위자`. 200 `{"ok":true}`. 감사 `api_token.revoke allow` detail `token_id`·`token_name` |
| 화면 `public/assets/app.js` | `openUserEdit(item)`(3923행 부근) 모달에 **「프로그램 토큰」 구역**: 열 때 `GET …/api-tokens` 로 표(이름·hint·발급일·마지막 사용·상태 살아있음/회수됨) · 「발급」 버튼 → 이름·사유 입력 → `POST` → 성공 시 토큰을 읽기 전용 입력칸에 **한 번** 표시 + 「복사」(`navigator.clipboard.writeText`) + 문구 「지금만 보입니다. 닫으면 다시 볼 수 없습니다」 → 표 갱신 · 살아 있는 행마다 「회수」(confirm) → `DELETE` → 표 갱신. 실패는 알림 줄에 서버 문구. **전부 `textContent`**, innerHTML 금지. 기존 `postJson`·알림 함수 재사용. `app.css` 에 최소 스타일만 |
| 경보 | `scripts/alerts.php` 에 AL-20 — AL-18 블록과 같은 모양(`action='api_token.issue' AND result='allow'`, 5분 창, `rule_text`·`count`·대상(`target_name` 목록)·`token_name`(detail)·`reason_text`·`last_at`). 머리 주석의 규칙 목록에 AL-20 |
| 이행 안내 | `.env.example` F-49 블록 머리에 「F-59: 개인 토큰(관리 → 계정 → 프로그램 토큰)이 기본. 이 두 줄은 이행용 — 프로그램에 개인 토큰을 심었으면 두 줄을 지운다(그러면 공용 경로가 닫힌다)」 · `scripts/api_token_rotate.sh` 머리 주석에 같은 안내 한 단락(동작 무변경) |
| 시험 `tests/api_tokens.sh` | **새 파일**. T-71 (a)~(g). `tests/api_folders.sh`·`tests/fetch_hosts.sh` 의 규칙·구조(qa 계정만 · `-A` · R-73·R-80 관문 · `login()`·`reauth()` · 토큰 값 미출력 — **발급받은 개인 토큰도 변수로만, 어디에도 출력하지 않는다** · 종료 코드). (d) 공용 토큰 항목은 귀속이 `qa_` 일 때만, 아니면 SKIP. 정리: 발급한 토큰 전부 회수, 파일 휴지통, 폴더 삭제(trap 에서도) |

## 수정 파일 — 허용 목록

`public/index.php`(라우트 3행 · 상수 둘 · [2-T] 호출 인자 · 주석) · `src/Auth.php`(`requireApiToken` · `SQL_LOAD_USER_BY_ID` · 주석) · `src/Http.php`(`MSG_PERSONAL_TOKEN_REQUIRED` 상수 한 줄) · `src/Audit.php`(`ALLOWED_DETAIL_KEYS` 3키 · `autoDetail` 두 키 · `SUMMARY_TEMPLATES` 9행) · `src/handlers/api_tokens_list.php`·`api_tokens_issue.php`·`api_tokens_revoke.php`(**새 파일**) · `public/assets/app.js`·`app.css` · `db/migrations/2026-09-16_api_tokens.sql`(**새**) · `db/schema.sql` · `db/grants.sql` · `deploy/install.sh`(감지 목록 한 줄) · `scripts/alerts.php` · `.env.example` · `scripts/api_token_rotate.sh`(주석만) · `tests/api_tokens.sh`(**새**).

**손대지 않는다**: `src/Authz.php` · `src/Db.php` · `src/handlers/files_delete.php`·`folders_delete.php`·`api_whoami.php`·`me.php`·`users_*.php` · `.env` · nginx · 운영 DB(마이그레이션 **적용은 Fable** 이 한다 — 구현자는 파일만 만든다). 이 중 하나라도 고칠 이유가 생기면 멈추고 보고.

## 금지 패턴

- 토큰 원문·hint·해시를 감사 detail·로그·응답 목록·시험 출력에 넣는 것(`FORBIDDEN_DETAIL_KEYS` 를 느슨하게 푸는 것 포함 — 예외는 세 키 정확 일치만)
- 문자열 비교에 `===`/`==`(`hash_equals` 만) · `md5`·`sha1` · 예측 가능한 난수(`rand`·`mt_rand`·`uniqid`)
- SQL 문자열 결합 · `innerHTML` · 역할 판단을 Authz 밖에서(라우트 정책 `user.manage` 가 Admin 을 본다 — 핸들러가 role 을 다시 보지 않는다)
- 삭제 핸들러 안에서 토큰 종류를 보는 코드(개인 전용 판정은 **라우터 [2-T] + `requireApiToken($personalOnly)` 한 곳**)
- 시드 계정으로 요청 · `.env` 수정 · 운영 DB 에 DDL 적용 · 커밋·`git add`

## 완료 기준 — PRD 12.2 T-71

구현자가 돌릴 수 있는 것: **마이그레이션은 Fable 이 적용한 뒤**에야 (a)~(c)(e)(g) 가 돈다 — 그러므로 구현자는 ① `php -l` 전 파일 ② `bash -n` ③ `scripts/security_lint.sh` 0 ④ `php -r` 로 라우트 표 자기검사(부팅) 통과 확인(`php public/index.php` 는 아니고, `tbox_validate_routes` 를 부르는 기존 방식이 없으면 8082 `GET /api/health` 200 으로 대체) ⑤ 표 없이도 돌아가는 회귀(`tests/api_whoami.sh`·`tests/api_folders.sh` 의 SKIP 아닌 항목) 를 실행하고, 시험 스크립트 본체는 **Fable 이 마이그레이션 뒤 실행**한다고 보고에 적는다. ★ 마이그레이션 전이라도 `requireApiToken` 이 `api_tokens` 를 조회하다 표가 없어 500 이 나면 **모든 토큰 요청이 깨진다** — 그래서 구현 파일을 저장하는 순간 사용자 PC 프로그램에 영향이 간다. 구현 순서: 마이그레이션 파일·핸들러·시험 먼저 작성 → **`Auth.php`·`index.php` 는 마지막에 저장**하고 즉시 보고. Fable 이 곧바로 마이그레이션을 적용한다.

## 보고 형식

변경 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유(마이그레이션 미적용으로 SKIP 한 항목) / 지시와 다르게 판단한 것 / `git diff --stat`
