# Opus 구현 지시서 — F-50 프로그램용 검색·다운로드 (토큰 라우트 확장)

> Fable 작성 2026-09-15. CLAUDE.md 2장 형식. 작은 변경이다 — **새 인가 코드를 쓰지 마라.**

```
목표      : F-49 의 X-API-Token 이 GET /api/files(검색) 와 GET /api/files/{id}/download 에도 통하게 하고,
            토큰 요청의 검색 응답만 간결 배열 [{id,name,level,size,uploaded_at}] + limit 으로 갈래를 낸다.
요구사항  : PRD F-50 · 8장 `/api/files`·download 행 · 12.2 T-62 · S-02 · S-04 ; ARCHITECTURE 4.2 · 4.3(설계) · 5.1 · 5.3 ; SECURITY R-82 · R-83
수정 파일  : public/index.php · src/handlers/files_list.php · API.md · tests/api_read.sh(새) — 이 넷만.
금지 패턴  : SQL 문자열 결합, innerHTML, 사용자 입력 파일 경로, curl_exec/file_get_contents('http…'),
            역할 판단을 Authz 밖에서, 요청 파라미터의 role 값, 토큰 값 출력·저장, 새 권한 판정 코드 작성
완료 기준  : PRD 12.2 T-62 — (c)(f)(g)(h) 는 실행, (a)(b)(d)(e) 는 토큰이 필요하므로 아래 규칙.
보고 형식  : 변경 파일 / 실행 명령·출력 원문 / 못 한 것과 이유
```

## 손대지 않는다
`src/handlers/files_download.php`(이미 원래 이름 `Content-Disposition`·`decideRead` 403·404·sha256 대조 — 그대로가 정답),
`src/Auth.php`·`src/Http.php`·`src/Audit.php`·`src/Authz.php`·`db/*`·`.env`(읽기 금지, 존재는 `grep -c`)·문서(PRD/ARCH/SECURITY/STAGES).

## 구체 설계

### 1) `public/index.php`
- `const TBOX_API_TOKEN_ROUTE = 'POST /api/upload';` → 
  ```php
  const TBOX_API_TOKEN_ROUTES = ['POST /api/upload', 'GET /api/files', 'GET /api/files/{id}/download'];
  ```
  값은 `TBOX_ROUTE`(메서드 + **라우트 패턴**)와 같은 표기. 주석: 「F-50 에서 셋으로. 넓히려면 여기 + R-82/R-83 재검토」.
- `[2-T]`: `if (TBOX_ROUTE === TBOX_API_TOKEN_ROUTE)` → `if (in_array(TBOX_ROUTE, TBOX_API_TOKEN_ROUTES, true))`. 그 밖은 그대로(`requireApiToken`·`TBOX_AUTH_METHOD`·`$viaApiToken`).
- 다른 곳에서 옛 상수를 쓰면 전부 바꾼다(`grep -rn TBOX_API_TOKEN_ROUTE`).

### 2) `src/handlers/files_list.php` — 마지막 투영만 갈래
- 파일 앞부분에서 `$viaToken = defined('TBOX_AUTH_METHOD');`
- `$perPage`: 토큰이면 `Http::queryInt('limit', 20, 1, 100)`, 아니면 기존 20. (`page` 는 그대로 동작.)
- **SQL·바인딩·검색·폴더·권한 조건은 한 글자도 바꾸지 않는다.** `visibleFilesWhere` 가 곧 「토큰 주인의 역할로 볼 수 있는 것만」이다.
- 응답 직전: 토큰이면
  ```php
  Http::json(array_map(static fn(array $f): array => [
      'id' => $f['id'], 'name' => $f['name'], 'level' => $f['access_level'],
      'size' => $f['size_bytes'], 'uploaded_at' => $f['created_at'],
  ], $files));   // 최상위가 배열. 빈 결과는 []
  ```
  아니면 기존 객체 응답 그대로. (`Http::json(array)` 가 리스트를 JSON 배열로 낸다 — 빈 배열이 `{}` 로 나가지 않는지 실측해라; 그러면 `Http` 를 고치지 말고 핸들러에서 `header('Content-Type: application/json'); echo '[]';` 같은 우회를 쓰지 말고 **멈춰서 보고**.)
- 토큰 요청의 `can_delete`·`can_edit`·`preview_kind` 계산은 하지 않아도 되지만, 코드 단순성을 위해 `$files` 를 그대로 만든 뒤 투영해도 된다(비용은 목록 크기만큼).

### 3) `API.md`
- 1장 표 「인증」 줄의 F-49 문구를 「`POST /api/upload`·`GET /api/files`·`GET /api/files/{id}/download` 는 `X-API-Token` 도 됨(7~9장)」으로.
- **8장** 「프로그램용 검색 — `GET /api/files?q=&limit=`」 : 표(인증·요청·성공·실패 4줄 필수) + curl 예시(`$API_TOKEN` 자리표시) + 「응답은 배열, 키 5개, 볼 수 있는 파일만 — 권한 없는 파일은 응답에서 원천 제외」 + `q` 는 파일명·설명 부분 일치, `%`·`_`·`#` 는 글자 그대로 검색됨(와일드카드 아님).
- **9장** 「프로그램용 다운로드 — `GET /api/files/{id}/download`」 : 표 + curl 예시(`-OJ` 로 원래 이름 저장) + 401/403/404 의미(403 = 있는데 권한 없음, 404 = 없음·형태 아님).
- 실측 응답은 **네가 받은 것만**(토큰 없이 받을 수 있는 401/404, 세션으로 받은 웹 모양). 토큰 응답 예시는 「Fable 검토에서 채움」이라 적고 추정 금지.

### 4) `tests/api_read.sh` — T-62
관례는 `tests/api_upload.sh` 를 그대로. `qa_` 계정만, `-A "tbox-review/$(date +%F)"`, `BASE=http://127.0.0.1:8082`, 운영 DB·`.env`·nginx 불변.
- 토큰 값을 스크립트가 **읽어도 된다**(변수로만, 절대 출력 금지) — 단 `API_TOKEN_USER` 가 `qa_` 계정일 때만 (a)(b)(d)(e) 를 돌리고, 아니면 「귀속이 검토 계정이 아니라 SKIP」 출력. 판단은 `grep -oE '^API_TOKEN_USER=.*' .env | cut -d= -f2-` 로 계정 이름만 읽는다.
- 준비: `qa_employee01` 세션으로 `POST /api/files` 로 `F50-emp.txt`(internal) 올리고, `qa_admin01` 세션으로 `F50-adm.txt`(admin-only) 올린다(끝에 둘 다 휴지통).
- (a) 토큰 `GET /api/files?q=F50&limit=8` → 200 · 최상위 배열(`python3 -c 'json.load(...); assert isinstance(v, list)'`) · 원소 키 == `{id,name,level,size,uploaded_at}` · `F50-emp.txt` 있음.
- (b) 결과에 `level=admin-only` 0건 · `F50-adm.txt` 없음; `qa_admin01` 세션 `GET /api/files?q=F50` 에는 있음(대조).
- (c) `q=%25%27%20OR%201%3D1%20--`(`%' OR 1=1 --`) → 200, 배열 길이 0 또는 리터럴 일치만; `q=_`·`q=%23` 도 200; `limit=abc` → 20건 이하 정상, `limit=9999` → ≤100.
- (d) 토큰으로 `F50-emp.txt` id 다운로드 `-D 헤더파일 -o 본문` → 200 · `Content-Disposition` 에 `F50-emp.txt` · 본문 sha256 == 올린 파일.
- (e) 토큰으로 `F50-adm.txt` id → **403** `{"error":"권한이 없습니다"}` + 감사 `file.download deny` 행의 `detail.auth=api_token`; `00000000-0000-4000-8000-000000000000` → 404; `not-a-uuid` → 404.
- (f) 토큰으로 `DELETE /api/files/{id}`·`PATCH /api/files/{id}`·`GET /api/users` → 401 「로그인이 필요합니다」, `auth.token` 행 0.
- (g) `qa_employee01` 세션 `GET /api/files?q=F50` → 최상위 객체, 키 `files,total,page,per_page,scope,folder` 정확히.
- (h) `bash scripts/security_lint.sh` 0 · `php -l` 두 파일 · `bash -n`.

## 규칙
- 시드 계정 금지. 커밋·reload·DB 변경 금지. 못 돌린 항목은 「미실행 — 이유」.
- 보고: ① 파일 ② 명령·출력 원문 ③ 못 한 것.

---
## 정오표 (Fable, 2026-09-15 검토)
§1 의 「`in_array(...)` 하나로만」은 **틀렸다** — 그대로 하면 화면의 목록·다운로드가 세션으로도 401 이 된다(Opus 실측). 구현은 헤더가 실제로 온
요청만 토큰 경로로 보내고(`TBOX_API_TOKEN_ONLY_ROUTES = ['POST /api/upload']` 만 예외), 틀린 토큰은 세션으로 되돌리지 않는다. ARCH 4.3 「헤더 유무의 갈림길」에 반영.
