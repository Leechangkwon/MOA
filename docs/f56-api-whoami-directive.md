# F-56 지시서 — 프로그램용 `GET /api/whoami` (토큰 귀속 계정의 최소 정보)

> Fable 작성 2026-09-16. 사용자 요청(강사 4차 프롬프트 형식): 「t-box 에 API 하나 추가. API.md 10장으로 이어서, 끝나면 한글 커밋」.
> 문서가 먼저 갱신돼 있다(추가 규칙 4): PRD 8장 `/api/whoami` 행 · 13장 F-56 · 12.2 T-68, ARCH 4.4, SECURITY R-89. **읽고 시작할 것.**

## 목표

로컬 프로그램이 `X-API-Token` 하나로 「내가 누구고 무엇을 올릴 수 있나」를 알 수 있는 창구 하나를 만든다. 프로그램은 이 응답으로 `admin-only` 를 「미확인 기본값」이 아니라 「계정 권한」으로 잡는다.

## 요구사항

- PRD 8장 `GET /api/whoami` 행 · 13장 F-56 ①~⑤ · 12.2 **T-68** · S-01(권한 판단은 서버, 화면·프로그램은 받은 값만)
- ARCHITECTURE 4.2(토큰 인증) · 4.3(라우트 집합·헤더 갈림길) · **4.4**(이번 창구)
- SECURITY R-82 · R-83 · **R-89**
- API.md 「추가 규칙」 1~4

## 명세 (PRD 8장 행 그대로)

| 항목 | 내용 |
|---|---|
| 라우트 | `GET /api/whoami`, 핸들러 `src/handlers/api_whoami.php`, 정책 `Authz::POLICY_AUTHENTICATED` |
| 인증 | 헤더 `X-API-Token` **하나**. 세션 쿠키는 받지 않는다 → `TBOX_API_TOKEN_ROUTES` **와** `TBOX_API_TOKEN_ONLY_ROUTES` **둘 다**에 추가(`POST /api/upload` 와 같은 갈래). 헤더가 없으면 세션이 있어도 401 `missing_header` |
| 성공 200 | `{"username":"…","role":"…","team_id":<정수 또는 null>,"upload_levels":["public","internal",…]}` — 키는 **정확히 이 넷**, 이 순서 |
| `upload_levels` | **`Authz::uploadableLevels($user)` 를 그대로 호출**한다(`me.php` 가 쓰는 함수). 새 계산·복사·필터 금지 |
| 대상 | `Auth::requireApiToken()` 이 돌려주는 귀속 계정(`.env` `API_TOKEN_USER`) — 라우터 `[2-T]` 가 이미 `$user` 로 앉힌다. 핸들러는 그 `$user` 만 쓴다 |
| 실패 | 401(헤더 없음·틀림) · 503(토큰 미설정·귀속 계정 무효) — **전부 `Auth::requireApiToken` 이 이미 낸다.** 핸들러에 새 실패 경로·새 문구를 만들지 않는다 |
| 넣지 않는 것 | `csrf_token`, `can_*`, `next`, `acting*`, `team_name`, 세션 정보, 토큰 값. **넷 밖의 키가 하나라도 있으면 실패** |
| 감사 | 성공은 감사 행 없음(조회, `/api/me` 와 같다). 실패는 `auth.token deny` 기존 네 조합 그대로. `Audit.php` 문장표에 새 조합 추가 없음 |

## 수정 파일 — 허용 목록

| 파일 | 내용 |
|---|---|
| `public/index.php` | ① 라우트 표에 `['GET', '/api/whoami', 'api_whoami.php', Authz::POLICY_AUTHENTICATED]` — `/api/upload` 행 바로 아래, 주석에 F-56·PRD 8장·ARCH 4.4 ② `TBOX_API_TOKEN_ROUTES` 에 `'GET /api/whoami'` ③ `TBOX_API_TOKEN_ONLY_ROUTES` 에 `'GET /api/whoami'` ④ **주석의 「셋뿐」·「한 줄뿐」·「셋으로」 문구를 넷에 맞게** 고친다(61행·654~677행·835행 부근). 그 밖의 줄은 건드리지 않는다 |
| `src/handlers/api_whoami.php` | **새 파일**. `$user` 에서 넷을 꺼내 `Http::json(200, […])`. 다른 핸들러의 머리 주석 형식(왜·근거 ID)을 따른다. `me.php` 를 복사하지 말고 필요한 네 줄만 |
| `API.md` | ① **10장** `## 10. 프로그램용 자기 확인 — GET /api/whoami` — 추가 규칙 2 의 표(인증·요청·성공·실패) + 「넣지 않는 것」 줄 + curl 예시. **응답 원문 자리는 `<Fable 실측으로 채움>` 로 비워 둔다**(추정 응답을 적지 않는다 — 규칙 2). 9장 끝 `---` 뒤, 「추가 규칙」 앞 ② **0장 「인증」 줄**의 「**셋뿐**이다 — …(9장)」 를 「**넷**이다 — … · `GET /api/whoami`(10장)」 로, 「앞의 하나는 토큰 전용이고」 를 「업로드와 whoami 둘은 토큰 전용이고」 로 ③ 9장 끝 인용문 「토큰은 이 **셋** 밖에서는」 → 「넷」 ④ 7장 130행 「이 헤더가 통하는 창구는 **여기 하나뿐**」 은 F-49 당시 기록이라 그대로 두되 뒤에 「(F-50·F-56 으로 넷, 0장)」 을 덧붙인다 |
| `tests/api_whoami.sh` | **새 파일**. T-68 (a)~(e). `tests/api_read.sh` 의 규칙·구조(검토 계정만, `-A "tbox-review/$(date +%F)"`, 귀속이 `qa_` 가 아니면 토큰 항목 SKIP, 토큰 값 미출력, R-73 사전 검사, 종료 코드)를 그대로 따른다. (b) 는 같은 계정으로 세션 로그인(`seed-accounts.txt`·TOTP, api_read.sh 방식)해 `/api/me` 의 `upload_levels` 와 JSON 배열 문자열로 비교 |

**손대지 않는다**: `src/Auth.php` · `src/Http.php` · `src/Audit.php` · `src/Authz.php` · `src/handlers/me.php` · `db/*` · nginx · `.env`. 이 중 하나라도 고칠 이유가 생기면 멈추고 보고한다.

## 금지 패턴

- `upload_levels` 를 직접 계산·복사(`permissions` 조회, 역할 문자열 분기) — **오직 `Authz::uploadableLevels($user)`**
- 응답에 넷 밖의 키 · 토큰 값 · 세션 id
- 핸들러 안에서 `Http::apiTokenHeader()`·`Env::get('API_TOKEN…')` 을 다시 읽는 것(인증은 라우터 `[2-T]` 한 곳)
- 시드 계정(`admin01` 등)으로 요청 · 토큰 값을 화면·로그·문서에 출력 · `.env` 수정(귀속 회전은 Fable 검토 절차에서 한다)
- 새 감사 문장·새 오류 문구

## 완료 기준 — PRD 12.2 T-68

(a) 200·키 정확히 넷·`username`==`API_TOKEN_USER` (b) `upload_levels` == 같은 계정 세션 `/api/me` 의 값(원소·순서) (c) 헤더 없음 401·**세션 쿠키만** 401·틀린 토큰 401(문구 7장과 동일) (d) 감사 `auth.token deny missing_header·bad_token`, 성공은 감사 행 없음 (e) 회귀 — `/api/me` 세션 응답 키 집합 불변, 토큰으로 `GET /api/users` 401 「로그인이 필요합니다」, `scripts/security_lint.sh` 0, `php -l` 두 파일, `bash -n`.

토큰 항목은 **귀속이 `qa_` 계정일 때만** 실행된다. 현재 귀속은 `admin01`(사용자 프로그램용)이므로 구현자는 (c) 중 「헤더 없음·틀린 토큰」·(e) 회귀만 돌릴 수 있고, 나머지는 **SKIP 으로 보고**한다 — Fable 이 검토 절차(`api_token_rotate.sh qa_employee01` → 실측 → `admin01` 복귀)로 마저 돌린다. **`.env` 를 스스로 돌리지 않는다.**

## 보고 형식

변경 파일 목록 / 실행한 명령과 출력 원문(`tests/api_whoami.sh` 결과, `php -l`, lint) / 못 한 것과 이유(SKIP 항목 명시) / 지시와 다르게 판단한 것
