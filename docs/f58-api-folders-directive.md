# F-58 지시서 — 프로그램용 폴더 관리 (토큰으로 폴더 목록·생성·이름 변경·이동, 파일 이동)

> Fable 작성 2026-09-16. 사용자 요청: 「폴더 목록 생성 이동은 왜 안됨? … 해줘, 이것들이 되어야 로컬 PC 에서 확장성을 가질 수 있을 것 같아」.
> 문서가 먼저 갱신돼 있다(추가 규칙 4): PRD 8장 `/api/folders` 3행·`PATCH /api/files/{id}` 행 · 13장 F-58 · 12.2 **T-70**, ARCH **4.5**, SECURITY **R-90**, API.md 0장·**11장·12장**(응답 원문 자리는 비어 있다). **읽고 시작할 것.**

## 목표

로컬 프로그램이 `X-API-Token` 하나로 폴더를 **탐색·생성·이름 변경·이동**하고 파일을 **폴더로 옮길** 수 있게, 토큰 라우트 집합을 넷에서 **여덟**으로 넓힌다. 삭제·등급 변경은 열지 않는다.

## 요구사항

- PRD 13장 **F-58 ①~⑥** · 8장 네 행 · 12.2 **T-70** · S-01 · S-02 · R-07(허용 키)
- ARCHITECTURE 4.2(토큰 인증) · 4.3(헤더 유무 갈림길) · **4.5**(이번 변경) · 5.16(폴더 판정)
- SECURITY R-82 · R-83 · **R-90**
- API.md 11장·12장 · 「추가 규칙」 1~4

## 명세

| 항목 | 내용 |
|---|---|
| 라우트 집합 | `public/index.php` `TBOX_API_TOKEN_ROUTES` 에 **넉 줄** 추가: `'GET /api/folders'` · `'POST /api/folders'` · `'PATCH /api/folders/{num}'` · `'PATCH /api/files/{id}'`. 표기는 라우트 표의 패턴과 **글자까지 같게**(`{num}`·`{id}`) — 라우트 표 자기검사가 어긋나면 부팅 실패다 |
| 토큰 전용 아님 | `TBOX_API_TOKEN_ONLY_ROUTES` 는 **건드리지 않는다**. 넷 다 화면이 쓰는 라우트라 헤더가 없으면 세션 경로다(F-50 갈림길 그대로) |
| 라우트 표 | 라우트 행·정책·핸들러 **변경 없음** |
| `folders_update.php` | `$allowedFields = ['name', 'parent_id', 'access_level', 'team_id', 'cascade', 'reason_text'];` 한 줄을 → `defined('TBOX_AUTH_METHOD') ? ['name', 'parent_id'] : [기존 여섯]` 으로. 그 아래 허용 키 검사·판정·감사·응답은 **한 글자도** 바꾸지 않는다. 바로 위에 왜(ARCH 4.5 「토큰 요청은 허용 키가 더 좁다」)를 3~5줄 주석으로 |
| `files_update.php` | `$allowedFields = ['description', 'access_level', 'team_id', 'folder_id'];` 한 줄을 → `defined('TBOX_AUTH_METHOD') ? ['folder_id'] : [기존 넷]` 으로. 같은 주석. 나머지 불변 |
| `folders_list.php`·`folders_create.php` | **수정 없음**. 토큰 요청도 세션과 같은 응답을 낸다(투영 없음, PRD F-58 ④) |
| 오류 문구 | 기존 통일 문구 그대로(400·403·409). **새 문구·새 사유 코드·새 감사 문장 없음** |
| 감사 | `Audit::autoDetail` 이 `detail.auth=api_token` 을 자동으로 붙인다 — 핸들러가 할 일 없음. `Audit.php` 무수정 |

## 수정 파일 — 허용 목록

| 파일 | 내용 |
|---|---|
| `public/index.php` | ① `TBOX_API_TOKEN_ROUTES` 넉 줄 ② 주석의 「넷뿐」·「넷으로」·「F-56 에서 **넷으로**」 문구를 여덟에 맞게(61행·660~672행·845행 부근 — `TBOX_API_TOKEN_ONLY_ROUTES` 주석의 「넷 중 나머지 둘」도 「여덟 중 나머지 여섯」으로) ③ 폴더 라우트 4행 주석(254행 부근)에 「F-58: DELETE 를 뺀 셋은 `X-API-Token` 으로도(ARCH 4.5)」 한 줄. 그 밖의 줄은 건드리지 않는다 |
| `src/handlers/folders_update.php` | `$allowedFields` 한 줄 + 주석 |
| `src/handlers/files_update.php` | `$allowedFields` 한 줄 + 주석 |
| `API.md` | 11장·12장의 `<Fable 실측으로 채움 …>` 자리는 **그대로 둔다**(구현자는 귀속이 `admin01` 이라 200 을 찍을 수 없다 — 추정 응답 금지, 규칙 2). 명세 표에 구현과 어긋나는 문장이 있으면 **고치지 말고 보고**한다 |
| `tests/api_folders.sh` | **새 파일**. T-70 (a)~(g). `tests/api_read.sh`·`tests/api_whoami.sh` 의 규칙·구조를 그대로(검토 계정만 · `-A "tbox-review/$(date +%F)"` · 귀속이 `qa_` 가 아니면 토큰 항목 SKIP · 토큰 값 미출력 · R-73·R-80 사전 관문 · `login()` 재사용 · 종료 코드). **만든 것은 전부 치운다**(trap 안에서도): 파일은 세션 `DELETE /api/files/{id}`(휴지통), 폴더는 세션 `DELETE /api/folders/{num}`(하위부터, 휴지통 파일이 남으면 `?move_trashed=1`). `qa_admin01` 의 `admin-only` 폴더 S 도 만든 뒤 지운다. 시험 이름은 `F58-` 접두 + 날짜·PID 로 충돌을 피한다 |

**손대지 않는다**: `src/Auth.php` · `src/Http.php` · `src/Audit.php` · `src/Authz.php` · `src/handlers/folders_list.php` · `src/handlers/folders_create.php` · `src/handlers/folders_delete.php` · `src/handlers/files_list.php` · `public/app.js` · `db/*` · nginx · `.env`. 이 중 하나라도 고칠 이유가 생기면 **멈추고 보고**한다.

## 금지 패턴

- 핸들러 안에서 `Http::apiTokenHeader()`·`Env::get('API_TOKEN…')` 을 읽는 것(인증은 라우터 `[2-T]` 한 곳). 갈림길은 **오직 `defined('TBOX_AUTH_METHOD')`**
- 토큰 요청을 위한 별도 판정·별도 SQL·별도 응답 투영(「새 판정 코드 0」)
- 새 오류 문구·새 감사 (action·result·reason) 조합·새 정책 이름
- 시드 계정(`admin01` 등)으로 요청 · 토큰 값 출력 · `.env` 수정(귀속 전환은 Fable 검토 절차에서)
- `git add -A` · 커밋(커밋은 Fable 이 한다)

## 완료 기준 — PRD 12.2 T-70

(a) 트리 200·세션과 JSON 동일·admin-only 폴더 없음·`?parent=S` 403 (b) 201·409·400·403 (c) 이름 200·이동 200 depth 2·`cycle` 400·`access_level` **400**·`cascade` 400·남의 폴더 403 (d) 파일 이동 200·`?folder=` 에 보임·루트 복귀 200·`description` **400**·못 읽는 폴더 400 (e) 토큰 DELETE 둘 401 「로그인이 필요합니다」·헤더 없음 401·틀린 토큰 401 (f) 감사 행 전부 `detail.auth=api_token`·새 조합 0 (g) 세션 회귀(폴더 등급 변경 200·파일 설명 변경 200·트리 모양 불변)·`scripts/security_lint.sh` 0·`php -l` 세 파일·`bash -n`.

토큰 항목은 **귀속이 `qa_` 계정일 때만** 실행된다. 현재 귀속은 `admin01`(사용자 프로그램용)이므로 구현자는 (e) 중 「헤더 없음·틀린 토큰·토큰 DELETE 401」과 (g) 회귀만 돌릴 수 있고, 나머지는 **SKIP 으로 보고**한다 — Fable 이 검토 절차(귀속을 `qa_employee01` 로 잠시 → 실측 → `admin01` 복귀)로 마저 돌린다. **`.env` 를 스스로 바꾸지 않는다.**

## 보고 형식

변경 파일 목록 / 실행한 명령과 출력 원문(`tests/api_folders.sh` 결과, `php -l`, lint, `git diff --stat`) / 못 한 것과 이유(SKIP 항목 명시) / 지시와 다르게 판단한 것
