# F-57 지시서 — 「검사 중」은 423 + `Retry-After` (일시 상태와 영구 거부를 상태 코드로)

> Fable 작성 2026-09-16. 계기: 사용자 프로그램(tbox-buddy) 실측 보고 — 올린 직후 `GET /api/files/{id}/download` 가 `403 {"error":"검사 중입니다. 잠시 뒤 다시 시도하세요"}`(잠시 뒤 200). API.md 9장에는 403 이 「권한이 없습니다」 하나뿐이어서 클라이언트가 403 을 전부 영구 실패로 처리했다. 문구로 구분하는 것은 문구가 바뀌면 조용히 깨진다.
> 문서가 먼저 갱신돼 있다: PRD 8장 download·preview·raw·`/s/{token}` 행 · 13장 F-57(다른 코드를 고르지 않은 이유 포함) · 12.2 T-69, ARCH 5.28, SECURITY R-86. **읽고 시작할 것.**

## 목표

`scan_status='pending'` 거부를 **423 Locked + `Retry-After: 30`** 으로 바꾼다. `infected`(격리)와 권한 없음은 **403 그대로**.

## 요구사항

- PRD F-57 ①~④ · 8장 네 행 · 12.2 T-69 · S-06
- ARCHITECTURE 5.28(「일시 상태와 영구 거부는 상태 코드로 갈린다」 단락)
- SECURITY R-86
- API.md 추가 규칙 2(실측 응답만)·3(「넣지 않는 것」)

## 명세

| 항목 | 내용 |
|---|---|
| 새 함수 | `src/Http.php` 에 `public static function locked(int $retryAfterSeconds, string $message): void` — `http_response_code(423)`, 헤더 `Retry-After: <초>`, 본문 `{"error":"<문구>","retry_after":<초>}`, `exit`. `Http::error` 와 같은 자리에, 같은 `Cache-Control: no-store`·JSON 헤더(`Http::json` 을 거쳐도 좋다 — 단 `Retry-After` 헤더는 `json()` 호출 **앞**에 `header()` 로) |
| 상수 | `Http::SCAN_RETRY_AFTER_SECONDS = 30` (주석: 감사 로그 실측 21~76초·중앙값 25초, 배치 매분 + clamscan ≈22초) |
| 바꾸는 자리 | `src/handlers/files_download.php` · `files_preview.php` · `files_preview_raw.php` · `share_download.php` 의 `Http::error(403, $scan === 'infected' ? MSG_SCAN_INFECTED : MSG_SCAN_PENDING)` 한 줄씩 → `infected` 는 `Http::error(403, Http::MSG_SCAN_INFECTED)` 그대로, `pending` 은 `Http::locked(Http::SCAN_RETRY_AFTER_SECONDS, Http::MSG_SCAN_PENDING)`. **그 앞의 `Audit::deny(... scan_pending / scan_infected ...)` 는 한 글자도 바꾸지 않는다** |
| 문구 | `MSG_SCAN_PENDING`·`MSG_SCAN_INFECTED` 값 불변. 새 문구 0, `Audit.php` 문장표 변경 0 |
| 화면 | `public/assets/app.js` 는 상태 코드를 분기하지 않고 `errorMessage(data)` 로 서버 문구를 보인다(1425행) — **고치지 않는다.** 단, 미리보기 카드가 423 본문의 `error` 를 그대로 보이는지 T-69 (e) 로 확인 |
| API.md 9장 | 표 「실패」 줄: `403 {"error":"권한이 없습니다"}` 옆에 **`423 {"error":"검사 중입니다. 잠시 뒤 다시 시도하세요","retry_after":30}` + `Retry-After: 30`** — 「올린 직후. 잠시 뒤 200」, 그리고 `403 {"error":"악성코드가 발견되어 격리된 파일입니다"}` — 「격리, 영구」. 「넣지 않는 것」 줄 뒤에 **「왜 423 인가」** 한 줄(PRD F-57 이유 요약: 403 은 영구, 409 는 쓰기 충돌 관례, 503 은 장애로 읽힘, 202 는 빈 본문 위험 → 423 + Retry-After). 검사 소요 「보통 21~76초, 중앙값 25초 — 30초 간격 최대 4번 권장」. 실측 응답 블록은 `<Fable 실측으로 채움>` 자리표시(Fable 이 채운다). 7장(업로드) 성공 줄에 「201 뒤 검사 창 동안 9장은 423」 한 마디 |
| 시험 | `tests/f54.sh` (a) 의 `expect "다운로드 상태" "403"` → `"423"`(+ `Retry-After` 헤더 존재·`retry_after` 30 확인), (b) 의 격리 403 은 그대로. 새 `tests/f57.sh` 는 만들지 않는다 — T-69 는 f54.sh 갱신 + Fable 추가 실측으로 충족(PRD T-69 문구의 `tests/f57.sh` 는 Fable 이 정정한다) |

## 수정 파일 — 허용 목록

`src/Http.php` · `src/handlers/files_download.php` · `src/handlers/files_preview.php` · `src/handlers/files_preview_raw.php` · `src/handlers/share_download.php` · `API.md`(9장·7장 한 마디) · `tests/f54.sh`

**손대지 않는다**: `src/Audit.php` · `src/Authz.php` · `src/Auth.php` · `public/index.php` · `public/assets/app.js` · `scripts/scan_pending.php` · `.env` · db. 고칠 이유가 생기면 멈추고 보고.

## 금지 패턴

- 감사 사유·문장 변경, 새 문구, `scan_status` 를 psql 로 바꾸는 것, `.env` 수정(`SCAN_ENABLED` 은 현재 값 그대로 — 켜져 있는지 `grep -c` 로만 확인)
- 시드 계정 사용, 토큰 값 출력, `git add/commit/stash/checkout`
- 상태 코드 분기를 핸들러마다 다르게 쓰는 것 — 넷이 **같은 두 줄**이어야 한다

## 완료 기준 — PRD 12.2 T-69

(a) pending → 다운로드·미리보기·raw·공유 링크 **423**, `Retry-After: 30`, 본문 `error`·`retry_after`(30) (b) 배치 뒤 200 (c) 격리 403·권한 403 불변, 그 응답엔 `Retry-After` 없음 (d) 감사 `file.download deny scan_pending` 불변·새 조합 0 (e) 화면 회귀(미리보기 카드 문구) · `scripts/security_lint.sh` 0 · `php -l` 5개 · `bash -n`.

`bash tests/f54.sh` 를 **실제로 돌려** 출력 원문을 붙인다(SCAN_ENABLED 이 꺼져 있으면 그 사실과 SKIP 을 적는다 — 켜지 않는다). (a) 의 423 은 배치가 돌기 전 60초 안에 요청해야 잡힌다 — f54.sh 가 이미 그렇게 한다.

## 보고 형식

변경 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유 / 지시와 다르게 판단한 것
