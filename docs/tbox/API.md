# t-box API 문서 (API.md)

외부 프로그램(로컬 업로드 도구 등)이 부를 수 있는 창구의 **주소·요청·응답 예시**를 모아 둔 문서다.
API 를 추가할 때마다 이 문서에 **이어서** 적는다(마지막 장의 「추가 규칙」). 요구사항의 원본은 `PRD.md` 8장 API 표이며,
이 문서와 8장이 어긋나면 8장이 옳다. 이 파일은 저장소 루트에 있고 웹으로는 열리지 않는다(`public/` 밖 — ARCHITECTURE 5.24).

## 0. 공통

| 항목 | 내용 |
|---|---|
| 기본 주소 | 공개: `https://changkwon.mso.so` · 서버 안 검토용: `http://127.0.0.1:8082` |
| 형식 | 요청·응답은 JSON (`Content-Type: application/json; charset=utf-8`). 파일 업로드만 `multipart/form-data` |
| 인증 | 기본은 **세션 쿠키**(아래 「세션」)다. 헤더 `X-API-Token` 이 통하는 창구는 **열**이다 — `POST /api/upload`(7장) · `GET /api/files`(8장) · `GET /api/files/{id}/download`(9장) · `GET /api/whoami`(10장) · `GET /api/folders`·`POST /api/folders`·`PATCH /api/folders/{num}`(11장) · `PATCH /api/files/{id}`(12장, 폴더 이동만) · `DELETE /api/files/{id}`·`DELETE /api/folders/{num}`(14장, **개인 토큰만**). 업로드와 whoami 둘은 토큰 전용이고(세션 쿠키로는 부를 수 없다 — 헤더가 없으면 401), 나머지 여덟은 화면도 쓰는 창구라 **헤더를 보내면 토큰으로, 안 보내면 세션으로** 판정한다. 그 헤더는 **그 밖의 창구에서는 무시된다**(등급 변경·휴지통 복원·계정·감사 등은 읽지도 않는다). **토큰은 두 종류**다(13장): **본인이** 「내 계정」 화면에서 발급하는 **개인 토큰**(권장 — 누가 했는지 사람 단위로 남고 회수할 수 있다)과 서버 `.env` 의 **공용 토큰**(F-49, 이행용 — 삭제 창구는 쓸 수 없다) |
| 범위·만료 (F-66) | 개인 토큰은 발급할 때 고른 **범위**(`read` · `read+upload` · `read+upload+delete`)만큼만 창구를 열고, 발급 시각 + **180일**에 만료된다. 범위 밖 창구 → `403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}` · 만료된 토큰 → `401 {"error":"만료된 API 토큰입니다 — 내 계정에서 새로 발급하세요"}`(틀린·회수된 토큰의 401 문구와 다르다). 범위는 창구를 좁힐 뿐이고 파일 한 건의 판정은 요청마다 그대로 한다 — 토큰이 계정보다 커질 수 없다. 공용 토큰은 범위·만료가 없고 `read+upload` 로 본다(이행용). 어느 장이 어느 범위인지는 각 장의 「범위(F-66)」 줄 |
| 세션 | 로그인 성공 시 쿠키 `tbox_session` (HttpOnly·Secure·SameSite=Lax). 이후 요청에 그 쿠키를 함께 보낸다. 유휴 30분·최대 8시간 |
| CSRF | 상태를 바꾸는 요청(POST·PATCH·DELETE)은 헤더 `X-CSRF-Token: <토큰>` 필수. 토큰은 `GET /api/me` 의 `csrf_token`. 없거나 틀리면 `403 {"error":"권한이 없습니다"}` |
| 오류 응답 | 항상 `{"error":"<한국어 문구>"}`. 권한 없음은 언제나 403 + 같은 문구(대상 정보 없음). 서버 오류는 500 `{"error":"서버 오류"}` — 원인은 응답에 나오지 않는다 |
| 속도 제한 | `POST /api/login` 은 IP 당 분당 30회(순간 10회까지 허용), 넘으면 429. 비밀번호 5회 실패 → 계정 10분 잠금(423) |
| 캐시 | 모든 API 응답은 `Cache-Control: no-store` |
| 도구 표식 | 사람이 아닌 프로그램이 부를 때는 `User-Agent` 를 알아볼 수 있게 붙이는 것을 권장한다(예: `-A "my-uploader/1.0"`). 감사 로그에 남아 사람의 조작과 구분된다 |

---

## 1. 상태 확인 — `GET /api/health`

서버(PHP)와 DB(PostgreSQL)가 살아 있는지 알려준다. **로그인 없이** 누구나 부를 수 있다. DB 에 `SELECT 1` 을 한 번 해 본다.

| 항목 | 내용 |
|---|---|
| 메서드·경로 | `GET /api/health` |
| 인증 | 없음 (public) |
| 요청 | 본문·파라미터 없음 |
| 성공 | `200` `{"status":"ok","db":"ok","time":"2026-09-15T19:02:11+09:00"}` — `time` 은 ISO 8601(서버 시간대 Asia/Seoul) |
| DB 실패 | `503` `{"status":"fail","db":"connect_failed"}` (연결 자체가 안 됨) 또는 `{"status":"fail","db":"query_failed"}` (연결은 됐는데 질의 실패) |
| 다른 메서드 | `405` |
| 넣지 않는 것 | 버전·서버 이름·DB 오류 원문·호스트·포트·계정. `db` 가 문장이 아니라 고정 분류어인 이유다(원문은 서버 로그에만) |

```bash
# 성공 예
curl -s -w '\n[%{http_code}]\n' https://changkwon.mso.so/api/health
{"status":"ok","db":"ok","time":"2026-09-15T19:02:11+09:00"}
[200]

# DB 가 죽었을 때
{"status":"fail","db":"connect_failed"}
[503]
```

한 줄 확인(상태 코드까지): `curl -s -w ' [%{http_code}]\n' https://changkwon.mso.so/api/health`

---

## 2. 로그인 — `POST /api/login`

| 항목 | 내용 |
|---|---|
| 인증 | 없음 |
| 요청 | `Content-Type: application/json` **필수**(폼 인코딩은 400). 본문 `{"username":"…","password":"…","otp":"123456"}` — `otp` 는 2단계 인증 코드 6자리(모든 계정 필수) |
| 성공 | `200` `{"ok":true,"next":null}` + `Set-Cookie: tbox_session=…`. `next` 가 `"password_change"` 또는 `"mfa_setup"` 이면 그 절차를 마쳐야 다른 API 가 열린다(그 전엔 403 `{"error":…,"next":"…"}`) |
| 코드 누락 | `401` `{"error":"2단계 인증 코드가 필요합니다","mfa_required":true}` — 비밀번호는 맞았고 `otp` 만 더 보내면 된다 |
| 실패 | `401 {"error":"아이디 또는 비밀번호가 올바르지 않습니다"}` · `423`(잠금) · `429`(속도 제한) · `400`(형식 오류) |
| 주의 | 같은 30초 창의 코드는 **한 번만** 쓸 수 있다. 두 번째 로그인은 다음 창까지 기다린다 |

```bash
curl -s -c jar -H 'Content-Type: application/json' \
  -d '{"username":"qa_employee01","password":"<비밀번호>","otp":"<6자리>"}' \
  https://changkwon.mso.so/api/login
{"ok":true,"next":null}
```

## 3. 내 정보 · CSRF 토큰 — `GET /api/me`

| 항목 | 내용 |
|---|---|
| 인증 | 세션 쿠키 |
| 응답 | `200` — 업로드 도구가 쓰는 키: `username`, `role`, `team_id`, **`csrf_token`**, **`upload_levels`**(내가 올릴 수 있는 접근 등급 목록, 예 `["public","internal","team"]`), `next`. 그 밖의 `can_*`·`acting*` 등은 화면용 편의 값이다 |
| 실패 | `401 {"error":"로그인이 필요합니다"}` |

```bash
curl -s -b jar https://changkwon.mso.so/api/me
{"username":"qa_employee01","role":"Employee","team_id":1,"csrf_token":"…","upload_levels":["public","internal","team"],"next":null, …}
```

## 4. 파일 업로드 — `POST /api/files` (multipart/form-data)

| 항목 | 내용 |
|---|---|
| 인증 | 세션 쿠키 + `X-CSRF-Token` |
| 필드 | `file`(파일, 필수) · `access_level`(필수: `public`·`internal`·`team`·`admin-only` — `upload_levels` 안의 값만) · `team_id`(`access_level=team` 일 때 필수, 양의 정수) · `description`(선택, 500자) · `folder_id`(선택, 폴더 번호) |
| 제한 | 100MB · 허용 확장자 `pdf docx xlsx pptx hwp hwpx txt csv md png jpg jpeg gif zip` · 내용(MIME)이 확장자와 맞아야 함 · 이중 확장자(`a.php.txt`) 거부 |
| 성공 | `201` `{"id":"<UUID>","name":"<원래 파일명>"}` — 이후 다운로드·삭제는 이 `id` 로 |
| 실패 | `400` — **사유마다 사람 문장**이다 (F-68, ARCHITECTURE 5.30 표가 원본): 확장자 `{"error":"허용되지 않는 확장자입니다 (허용: pdf docx xlsx pptx hwp hwpx txt csv md png jpg jpeg gif zip)"}` · 등급 `{"error":"level 값이 올바르지 않습니다 (public·internal·team·admin-only)"}` · 팀 `{"error":"team 등급은 team_id(활성 팀 번호)가 필요합니다"}` · 내용 검사 `{"error":"문서에 매크로가 들어 있어 올릴 수 없습니다"}` 등. **허용 키 밖·값의 형식 오류만** `{"error":"잘못된 요청입니다"}` · `403`(CSRF 없음 또는 그 등급·팀에 올릴 권한 없음 — Guest 는 업로드 불가) · `413`(크기) |

```bash
CSRF=$(curl -s -b jar https://changkwon.mso.so/api/me | python3 -c 'import sys,json;print(json.load(sys.stdin)["csrf_token"])')
curl -s -b jar -H "X-CSRF-Token: $CSRF" \
  -F 'file=@report.pdf' -F 'access_level=internal' -F 'description=9월 보고서' \
  https://changkwon.mso.so/api/files
{"id":"e7fecdea-e3f4-4133-9c1f-887408829e01","name":"report.pdf"}
```

## 5. 파일 삭제(휴지통) — `DELETE /api/files/{id}`

| 항목 | 내용 |
|---|---|
| 인증 | 세션 쿠키 + `X-CSRF-Token` |
| 성공 | `200 {"ok":true}` — 30일 보존 휴지통으로 간다(되살릴 수 있음) |
| 실패 | `403`(남의 파일·권한 없음) · `404`(없는 id) |

```bash
curl -s -b jar -H "X-CSRF-Token: $CSRF" -X DELETE https://changkwon.mso.so/api/files/<id>
{"ok":true}
```

## 6. 로그아웃 — `POST /api/logout`

세션 쿠키 + `X-CSRF-Token`. 성공 `200 {"ok":true}`. 이후 `GET /api/me` 는 401.

```bash
curl -s -b jar -H "X-CSRF-Token: $CSRF" -X POST https://changkwon.mso.so/api/logout
```

## 7. 프로그램용 업로드 — `POST /api/upload` (multipart/form-data)

로컬 PC 의 **프로그램**이 사람의 로그인·2단계 인증 없이 파일을 올리는 창구다. 4장(`POST /api/files`)과
**같은 검증·저장 코드**를 쓰고, 다른 것은 ① 인증 방법 ② 등급 필드 이름과 기본값 ③ 실패 문구 셋뿐이다.

**범위(F-66)** — 이 창구는 `read+upload` 이상의 범위가 필요하다. `read` 토큰으로 부르면 `403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}` 다 (13장 · ARCHITECTURE 4.7).

| 항목 | 내용 |
|---|---|
| 메서드·경로 | `POST /api/upload` |
| 인증 | 헤더 **`X-API-Token: <토큰>`** 하나. 세션 쿠키도 `X-CSRF-Token` 도 필요 없다. 이 헤더가 통하는 창구는 **여기 하나뿐**이고 다른 경로는 읽지도 않는다(F-50·F-56 으로 넷, 0장). 토큰 값은 서버의 `.env` 에만 있다 — **서버에서 `cat .env` 로 확인**하고, 문서·채팅·소스에 적지 않는다 |
| 요청 | `multipart/form-data`. `file`(필수) · `description`(선택, 500자) · `level`(선택: `public`·`internal`·`team`·`admin-only` — **없으면 `public`**) · `team_id`(`level=team` 이면 필수, 양의 정수) · `folder_id`(선택) |
| 제한 | 4장과 같다 — 100MB · 허용 확장자 `pdf docx xlsx pptx hwp hwpx txt csv md png jpg jpeg gif zip` · 내용(MIME)이 확장자와 맞아야 함 · 이중 확장자 거부 · 하루 총량 2GB · 민감정보가 보이면 `team`/`admin-only` 로만 |
| 성공 | `201` `{"id":"<UUID>","name":"<원래 파일명>","level":"<등급>"}` — 4장과 달리 `level` 이 함께 온다(기본값 `public` 이 적용됐는지 프로그램이 확인할 수 있게). **`201` 을 받은 뒤 검사 창 동안(보통 30초 안팎) 9장 다운로드는 `423` 이다** — 잠시 뒤 다시 하면 200 이다(F-57, 9장 「왜 423 인가」) |
| 실패 | `401`(헤더 없음·토큰 틀림) · `400`(형식·확장자·등급·팀·폴더) · `403`(그 등급·팀에 올릴 권한 없음) · `413`(100MB 초과·하루 총량 초과) — **이 네 가지는 이유를 알려 준다**(`{"error":"허용되지 않는 확장자입니다"}` 같은 한국어 한 줄). `503`(서버에 토큰이 설정되지 않음·연결 계정이 잘못됨) · `500` `{"error":"서버 오류"}` |
| 넣지 않는 것 | `500` 은 원인을 알려 주지 않는다(예외·DB 오류 원문은 서버 로그의 `request_id` 로만). 토큰 값·연결 계정 이름은 응답에도 감사 로그에도 나오지 않는다 |
| 누구 이름으로 올라가나 | 서버 `.env` 의 `API_TOKEN_USER` 계정. 그 계정 기준으로 등급·팀 권한을 판정하므로, 그 계정이 못 올리는 등급은 토큰이 있어도 403 이다. 감사 로그에는 그 계정 이름 + `detail.auth=api_token` 이 남는다 |

```bash
# 토큰은 서버의 .env 에 있다. 값을 문서·채팅에 옮겨 적지 말고 환경 변수로만 다룬다.
#   서버에서:  cat /var/www/tbox-v2/.env        ← API_TOKEN= 줄
#   바꾸려면:  bash scripts/api_token_rotate.sh [귀속계정아이디]   ← 값은 출력되지 않는다
export API_TOKEN='<서버 .env 의 값>'

curl -s -w '\n[%{http_code}]\n' \
  -H "X-API-Token: $API_TOKEN" \
  -F 'file=@report.pdf' \
  -F 'description=9월 보고서' \
  -F 'level=internal' \
  https://changkwon.mso.so/api/upload
```

실제 응답 — 2026-09-15 검토 포트 8082 에서 **검토 계정 `qa_employee01`(Employee) 을 귀속 계정으로 잠시 두고** 실측한 값이다(올린 시험 파일은 곧바로 휴지통으로 보냈고, 검토 뒤 귀속은 운영 계정으로 되돌렸다).

```bash
# 성공 — level 을 생략하면 public 이 적용되고 응답에 그 값이 온다
curl -s -w '\n[%{http_code}]\n' -H "X-API-Token: $API_TOKEN" \
  -F 'file=@F49-token.txt' -F 'description=F-49 검토' http://127.0.0.1:8082/api/upload
{"id":"f087f6d5-8248-4f74-b614-28dd2925d9d0","name":"F49-token.txt","level":"public"}
[201]
```

실패 — **이유가 그대로 온다** (전부 실측).

```bash
# 헤더 없음
{"error":"X-API-Token 헤더가 없습니다"}                                  [401]
# 틀린 토큰
{"error":"API 토큰이 올바르지 않습니다"}                                 [401]
# 서버에 토큰이 아직 없을 때(설정 전) — 무엇을 보내도
{"error":"API 토큰이 서버에 설정되지 않았습니다"}                        [503]
# 귀속 계정(Employee)이 못 올리는 등급 — -F 'level=admin-only'
{"error":"이 계정은 그 등급(팀)으로 올릴 권한이 없습니다"}                 [403]
# -F 'level=team' 인데 team_id 없음
{"error":"team 등급은 team_id(활성 팀 번호)가 필요합니다"}                 [400]
# -F 'level=zzz'
{"error":"level 값이 올바르지 않습니다 (public·internal·team·admin-only)"} [400]
# x.exe
{"error":"허용되지 않는 확장자입니다 (허용: pdf docx xlsx pptx hwp hwpx txt csv md png jpg jpeg gif zip)"} [400]
# a.php.txt
{"error":"이중 확장자 파일은 올릴 수 없습니다"}                           [400]
# 0바이트 파일
{"error":"빈 파일(0바이트)은 올릴 수 없습니다"}                           [400]
# description 501자
{"error":"설명은 500자까지입니다"}                                        [400]
# 토큰은 이 창구 밖에서 아무것도 열지 못한다 — 같은 헤더로 GET /api/users · DELETE /api/files/{id}
{"error":"로그인이 필요합니다"}                                           [401]
```

100MB 초과(102MB 실측)는 nginx 가 PHP 앞에서 끊는다. 설정 반영(`deploy/switch-site.sh`) 뒤에는
`413 {"error":"파일이 100MB 를 넘습니다"}` 이고, 반영 전에는 nginx 기본 HTML 413 이다(상태 코드는 둘 다 413).

---

## 8. 프로그램용 검색 — `GET /api/files?q=&limit=`

프로그램이 **볼 수 있는 파일을 찾는** 창구다. 같은 주소를 화면(브라우저)도 쓰지만, `X-API-Token` 을
보내면 응답이 **프로그램용 간결한 배열**로 온다. 세션 쿠키로 부르면 지금까지의 화면용 응답 그대로다.

**범위(F-66)** — 이 창구는 `read` 이상, 곧 **모든 범위**가 쓸 수 있다. 범위 밖 403 은 나오지 않는다 (13장 · ARCHITECTURE 4.7).

| 항목 | 내용 |
|---|---|
| 메서드·경로 | `GET /api/files?q=<검색어>&limit=<1~100>` |
| 인증 | 헤더 **`X-API-Token: <토큰>`**(7장과 같은 토큰). 헤더를 보내지 않으면 세션 쿠키 경로로 판정한다 |
| 요청 | `q`(선택, 200자 — 파일명·설명 **부분 일치**) · `limit`(선택, 1~100, 기본 20 — 형태가 아니거나 범위 밖이면 20) · `page`(선택, 1부터) |
| 성공 | `200` — 최상위가 **배열**이고 원소 키는 여섯뿐이다(F-65 로 `exec_content` 가 더해졌다): `[{"id":"<UUID>","name":"<파일명>","level":"<등급>","size":<바이트>,"uploaded_at":"<올린 시각>","exec_content":<true|false>}]`. 결과가 없으면 `[]` |
| 실패 | `401`(헤더를 보냈는데 토큰이 틀림 → `{"error":"API 토큰이 올바르지 않습니다"}` · 헤더도 세션도 없음 → `{"error":"로그인이 필요합니다"}`) · `503`(서버에 토큰 미설정·귀속 계정 문제) |
| 무엇이 보이나 | **토큰 귀속 계정(`API_TOKEN_USER`)의 역할로 볼 수 있는 파일만.** 권한 없는 파일은 걸러지는 것이 아니라 **DB 질의에서 원천 제외**되므로 `id` 조차 응답에 나오지 않는다. 휴지통에 간 파일도 나오지 않는다 |
| 검색어의 성질 | `q` 는 **값**이다. `%`·`_`·`#` 는 와일드카드가 아니라 **글자 그대로** 찾는다(`q=_` 는 이름·설명에 밑줄이 든 파일만). SQL 문장으로 해석되는 일도 없다 — 값은 전부 바인딩으로 들어간다 |
| 넣지 않는 것 | 전체 건수·화면 편의 값(`can_delete`·`can_edit`·미리보기 갈래·민감 배지)·팀 이름·폴더 경로·올린 사람. 프로그램에 필요 없고, 적게 내보낼수록 정찰 재료도 적다 |

```bash
export API_TOKEN='<서버 .env 의 값>'    # 값은 문서·채팅에 옮겨 적지 않는다

curl -s -w '\n[%{http_code}]\n' -H "X-API-Token: $API_TOKEN" \
  'https://changkwon.mso.so/api/files?q=보고서&limit=8'
```

실제 응답(200) — 2026-09-15 Fable 검토, 검토 포트 8082, 귀속을 검토 계정 `qa_employee01` 로 잠시 둔 상태.
토큰으로 올린 `F50-doc.txt` 를 검색한 결과다(**최상위가 배열**, 원소 키 여섯). 검토 뒤 파일은 휴지통, 귀속은 운영 계정으로 되돌렸다.

```bash
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/api/files?q=F50-doc&limit=8'
[{"id":"6faa43a5-9673-4224-96ea-b4b5d20bb01b","name":"F50-doc.txt","level":"public","size":22,"uploaded_at":"2026-09-15 21:06:58.855433+09","exec_content":false}]

# 아무것도 안 걸리면 빈 배열이다(빈 객체 `{}` 가 아니다)
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/api/files?q=zzz-none-9f3a&limit=8'
[]

# 주입 시도는 글자 그대로 검색된다 — %' OR 1=1 -- 를 URL 인코딩해 보내면 그런 이름의 파일이 없으므로 빈 배열
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/api/files?q=%25%27%20OR%201%3D1%20--&limit=8'
[]
```

Employee 귀속 토큰의 검색에는 `admin-only` 파일이 **한 건도 오지 않는다**(T-62 (b) 실측 — 같은 검색을 `qa_admin01` 세션으로 하면 보인다).


아래는 **실측**이다(2026-09-15, 검토 포트 8082).

```bash
# 헤더도 세션도 없이
curl -s -w ' [%{http_code}]\n' 'http://127.0.0.1:8082/api/files?q=F50'
{"error":"로그인이 필요합니다"} [401]

# 헤더를 보냈는데 토큰이 틀림 — 세션으로 되돌아가지 않는다
curl -s -w ' [%{http_code}]\n' -H 'X-API-Token: <틀린 값>' 'http://127.0.0.1:8082/api/files?q=F50'
{"error":"API 토큰이 올바르지 않습니다"} [401]

# 세션(화면)으로 부르면 응답 모양은 지금까지와 똑같다 — 최상위가 객체다
curl -s -b jar 'http://127.0.0.1:8082/api/files?q=F50'
{"files":[…],"total":0,"page":1,"per_page":20,"scope":"all","folder":{…}}  [200]
```

## 9. 프로그램용 다운로드 — `GET /api/files/{id}/download`

8장이 돌려준 `id` 로 **파일 내용을 받는다**. 화면의 다운로드와 **같은 창구·같은 권한 판정**이고,
다른 것은 인증 방법뿐이다. 서버는 보내기 전에 저장된 sha256 과 대조한다(다르면 500).

**범위(F-66)** — 이 창구는 `read` 이상, 곧 **모든 범위**가 쓸 수 있다 — 「읽기만」 토큰도 받을 수 있는 파일은 내려받는다 (13장 · ARCHITECTURE 4.7, 남는 위험 R-97).

| 항목 | 내용 |
|---|---|
| 메서드·경로 | `GET /api/files/{id}/download` — `{id}` 는 업로드·검색이 돌려준 **UUID 36자** |
| 인증 | 헤더 **`X-API-Token: <토큰>`**. 헤더를 보내지 않으면 세션 쿠키 경로로 판정한다 |
| 요청 | 본문·파라미터 없음 |
| 성공 | `200` + 파일 내용(`Content-Disposition` 에 **올릴 때의 원래 이름**, `Content-Type`·`Content-Length`) |
| 실패 | `401`(토큰 틀림·인증 없음) · **`403`** `{"error":"권한이 없습니다"}` — 파일은 있지만 귀속 계정이 볼 수 없음 · **`423`** `{"error":"검사 중입니다. 잠시 뒤 다시 시도하세요","retry_after":30}` + 헤더 **`Retry-After: 30`** — 올린 직후. 잠시 뒤 200 (F-57) · **`403`** `{"error":"악성코드가 발견되어 격리된 파일입니다"}` — 격리, 영구 · **`404`** `{"error":"찾을 수 없습니다"}` — 그런 파일이 없거나 휴지통에 있거나 `id` 가 UUID 형태가 아님 · `500 {"error":"서버 오류"}` |
| 넣지 않는 것 | 403 은 파일 이름·등급·소유자를 알려 주지 않는다(항상 같은 문구). 500 도 원인을 알려 주지 않는다 — 원인은 서버 로그의 `request_id` 로만 |
| 왜 423 인가 | `403` 은 「인증됐지만 허용되지 않음」= **영구**라 다시 해 보라는 신호가 없고, `409` 는 쓰기의 상태 충돌 관례라 GET 에 어색하고, `503` 은 서버 장애로 읽혀 헬스체크·경보를 오염시키고, `202` 는 본문 없는 GET 이 돼 클라이언트가 빈 파일을 저장할 위험이 있다. **`423 Locked`**(RFC 4918)은 「잠겨 있다, 풀린다」는 뜻 그대로다 — 이 서비스에서 423 을 쓰는 다른 자리(2장 로그인 계정 10분 잠금)도 같은 뜻이라 의미가 어긋나지 않는다. 대기 시간은 문구가 아니라 `Retry-After` 헤더로 준다 (PRD F-57) |
| 검사 소요 | 업로드 → 검사 완료가 실측 **21~76초, 중앙값 25초**(배치가 매분 돌고 `clamscan` 이 약 22초). 프로그램은 **30초 간격으로 최대 4번**(약 2분) 다시 시도하기를 권한다 — 그래도 423 이면 사람에게 알린다 |
| 감사 | 성공·거부 모두 `file.download` 로 남고, 토큰으로 들어온 요청에는 `detail.auth=api_token` 이 붙는다 |

```bash
# -OJ 는 Content-Disposition 의 원래 이름으로 저장한다
curl -OJ -H "X-API-Token: $API_TOKEN" \
  https://changkwon.mso.so/api/files/<UUID>/download
```

실제 200 응답 — 2026-09-15 Fable 검토(8장과 같은 조건). 헤더에 **원래 이름**이 담기고 본문은 올린 파일과 바이트가 같다(`diff` 동일, T-62 (d) sha256 일치).

```bash
curl -s -D - -o F50-doc.txt -H "X-API-Token: $API_TOKEN" https://changkwon.mso.so/api/files/6faa43a5-9673-4224-96ea-b4b5d20bb01b/download
HTTP/1.1 200 OK
Content-Type: application/octet-stream
X-Content-Type-Options: nosniff
Content-Disposition: attachment; filename="F50-doc.txt"; filename*=UTF-8''F50-doc.txt

# 없는 id
{"error":"찾을 수 없습니다"}   [404]
# 권한 없는(admin-only) 파일 id 를 Employee 귀속 토큰으로 — T-62 (e) 실측
{"error":"권한이 없습니다"}     [403]
```


아래는 **실측**이다(2026-09-15, 검토 포트 8082).

```bash
# UUID 형태가 아니면 라우트에 맞지 않아 404 다 — 인증보다 먼저 끝난다
curl -s -w ' [%{http_code}]\n' 'http://127.0.0.1:8082/api/files/not-a-uuid/download'
{"error":"찾을 수 없습니다"} [404]

# 헤더도 세션도 없이
curl -s -w ' [%{http_code}]\n' 'http://127.0.0.1:8082/api/files/00000000-0000-4000-8000-000000000000/download'
{"error":"로그인이 필요합니다"} [401]

# 틀린 토큰
curl -s -w ' [%{http_code}]\n' -H 'X-API-Token: <틀린 값>' \
  'http://127.0.0.1:8082/api/files/00000000-0000-4000-8000-000000000000/download'
{"error":"API 토큰이 올바르지 않습니다"} [401]
```

검사 중(F-57)의 실측이다 — 올린 **직후** 같은 `id` 로 부르면 423 이고, `Retry-After` 초만큼 뒤에 다시 하면 200 이다.

```bash
curl -s -D - -o /dev/null -w '[%{http_code}]\n' -H "X-API-Token: $API_TOKEN" \
  'http://127.0.0.1:8082/api/files/<UUID>/download'
# 실측 2026-09-16 15:1x — qa_employee01 세션, 검토 포트 8082. 올린 직후(scan_status=pending) 네 창구가 같은 답이다
curl -s -D - -o body 'http://127.0.0.1:8082/api/files/<id>/download' | grep -E '^HTTP|^Retry-After'; cat body
HTTP/1.1 423 Locked
Retry-After: 30
{"error":"검사 중입니다. 잠시 뒤 다시 시도하세요","retry_after":30}
# 같은 순간 미리보기 · preview/raw · 공유 링크 /s/{token} → 전부 423 · Retry-After: 30 · 같은 본문
# 권한 없는(admin-only) 파일 — 403 에는 Retry-After 가 없다(영구)
{"error":"권한이 없습니다"}     [403]
# 격리된 파일 — 403, Retry-After 없음 (tests/f54.sh (b) 실측)
{"error":"악성코드가 발견되어 격리된 파일입니다"}     [403]
# 배치가 돈 뒤(이 실측에서는 70초) 같은 요청
HTTP/1.1 200 OK
```

> 토큰은 이 넷 밖에서는 아무것도 열지 못한다 — 같은 헤더로 `DELETE /api/files/{id}` ·
> `PATCH /api/files/{id}` · `GET /api/users` 를 불러도 `401 {"error":"로그인이 필요합니다"}` 다(실측).

---

### 9-1. 받을 수 없는 파일 — 「실행 파일 포함」 (F-65, 2026-09-21)

관리자가 확인 절차를 거쳐 올린 **실행 파일이 든 압축본**(목록의 `exec_content: true`)은 토큰으로 받을 수 없다 — 사람이 비밀번호와 2단계 코드를 넣어야 하는 파일이라서다. 토큰으로 `GET /api/files/{id}/download` 를 부르면 **403** `{"error":"권한이 없습니다"}` 이고 감사에 `file.download deny exec_content_token` 이 남는다. 웹 화면에서 받는 창구는 `POST /api/files/{id}/exec-download`(세션 전용)이며 프로그램은 쓸 수 없다.

## 10. 프로그램용 자기 확인 — `GET /api/whoami`

프로그램이 **「나는 누구고 무엇을 올릴 수 있나」** 를 묻는 창구다 (PRD F-56, ARCHITECTURE 4.4).
7장의 `level` 을 고를 때 프로그램이 `admin-only` 를 「모르니까 일단 기본값」이 아니라
**계정 권한**으로 정할 수 있게 하는 것이 목적이다. 3장(`GET /api/me`)과 **같은 함수**로 등급 목록을
만들지만, 화면용 값(`csrf_token`·`can_*`·`next` …)은 하나도 오지 않는다.

**범위(F-66)** — 이 창구는 `read` 이상, 곧 **모든 범위**가 쓸 수 있다 (13장 · ARCHITECTURE 4.7).

| 항목 | 내용 |
|---|---|
| 메서드·경로 | `GET /api/whoami` |
| 인증 | 헤더 **`X-API-Token: <토큰>`** 하나. **세션 쿠키는 받지 않는다** — 화면이 쓰지 않는 토큰 전용 창구라(7장과 같은 갈래), 로그인해 둔 브라우저로 불러도 헤더가 없으면 401 이다. 토큰 값은 서버의 `.env` 에만 있다(`cat .env` 로 확인, 문서·채팅·소스에 적지 않는다) |
| 요청 | 본문·파라미터 없음 |
| 성공 | `200` `{"username":"…","role":"…","team_id":<정수 또는 null>,"upload_levels":["public","internal",…]}` — 키는 **정확히 이 넷**, 이 순서다. 대상은 `.env` `API_TOKEN_USER` 의 귀속 계정이고, `upload_levels` 는 3장 `GET /api/me` 의 같은 이름 값과 **원소·순서까지 같다**(같은 함수 `Authz::uploadableLevels`) |
| 실패 | `401` `{"error":"X-API-Token 헤더가 없습니다"}`(헤더 없음 — 세션 쿠키만 보내도 같다) · `401` `{"error":"API 토큰이 올바르지 않습니다"}`(값이 틀림, 세션으로 되돌아가지 않는다) · `503`(서버에 토큰이 설정되지 않음·귀속 계정이 잘못됨). 문구·상태는 7장과 **한 글자도 같다** |
| 넣지 않는 것 | `csrf_token`(세션이 없으니 뜻이 없다) · `can_*`·`next`·`acting*`·`team_name` 같은 화면 편의 값 · 세션 정보 · 내부 id · **토큰 값**. 넷 밖의 키는 하나도 오지 않는다 |
| 감사 | 성공은 감사 행을 남기지 않는다(대상 없는 조회, `GET /api/me` 와 같다). 실패는 `auth.token deny`(`missing_header`·`bad_token`·`not_configured`·`user_invalid`) 로 남는다 |

```bash
# 실측 2026-09-16 — 귀속을 검토 계정 qa_employee01(Employee, 팀 1)로 돌려 받은 값. 운영 귀속(admin01)이면 role=Admin, upload_levels 4개
curl -s -w '\n[%{http_code}]\n' -H "X-API-Token: $API_TOKEN" \
  https://changkwon.mso.so/api/whoami
{"username":"qa_employee01","role":"Employee","team_id":1,"upload_levels":["public","internal","team"]}
[200]
```

프로그램이 쓰는 방법 — 받은 `upload_levels` 안에 있는 값만 7장의 `level` 로 보낸다. 목록에 없는 값을
보내도 서버가 `Authz::decideUploadAs` 로 다시 판단해 403 이므로(PRD S-01), 이 응답은 **권한이 아니라
선택지**다. 3장의 `upload_levels` 와 같은 성격이다.

아래는 **실측**이다(2026-09-16, 검토 포트 8082, `-A "tbox-review/2026-09-16"`).

```bash
# 헤더 없음 — 세션 쿠키를 함께 보내도 같다(토큰 전용 창구)
curl -s -w ' [%{http_code}]\n' 'http://127.0.0.1:8082/api/whoami'
{"error":"X-API-Token 헤더가 없습니다"} [401]

# 틀린 토큰
curl -s -w ' [%{http_code}]\n' -H 'X-API-Token: <틀린 값>' 'http://127.0.0.1:8082/api/whoami'
{"error":"API 토큰이 올바르지 않습니다"} [401]

# 세션 쿠키만 (qa_employee01 로 로그인한 상태) — 헤더가 없으므로 같은 401 이다
curl -s -w ' [%{http_code}]\n' -b jar 'http://127.0.0.1:8082/api/whoami'
{"error":"X-API-Token 헤더가 없습니다"} [401]
```

> 200 응답은 토큰 귀속이 검토 계정(`qa_`)일 때만 찍을 수 있어 비워 두었다 — 지금 귀속은
> 사용자 프로그램용 계정이다. 추가 규칙 2 에 따라 **추정 응답을 적지 않는다**.

---

## 11. 프로그램용 폴더 — `GET /api/folders` · `POST /api/folders` · `PATCH /api/folders/{num}`

프로그램이 **폴더를 탐색하고, 만들고, 이름을 바꾸고, 옮기는** 창구다 (PRD F-58, ARCHITECTURE 4.5). 화면의 폴더 기능과
**같은 창구·같은 권한 판정·같은 응답**이고, 다른 것은 ① 인증 방법 ② `PATCH` 의 허용 키가 더 좁다는 것 둘뿐이다.
폴더 **삭제**(`DELETE /api/folders/{num}`)와 **등급 변경**은 토큰으로 열려 있지 않다 — 헤더를 보내도 무시되어 401 「로그인이 필요합니다」다.

### 11-1. 목록(트리) — `GET /api/folders?parent=<id>`

**범위(F-66)** — 목록(`GET`)은 `read` 이상, 만들기·이름 변경·이동(`POST`·`PATCH`)은 `read+upload` 이상이다. 범위 밖이면 `403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}` (13장 · ARCHITECTURE 4.7).

| 항목 | 내용 |
|---|---|
| 인증 | 헤더 **`X-API-Token: <토큰>`**(7장과 같은 토큰). 헤더를 보내지 않으면 세션 쿠키 경로로 판정한다 |
| 요청 | `parent`(선택, 폴더 번호. **0 또는 없음 = 루트**) |
| 성공 | `200` `{"folder":{"id":<parent>,"name":…,"access_level":…,"breadcrumb":[{id,name,access_level}…]},"folders":[{"id":<정수>,"name":…,"depth":1~5,"access_level":…,"team_id":…,"team_name":…,"child_count":<n>,"file_count":<n>}]}` — 화면 응답과 **동일**. `folders` 는 **귀속 계정이 읽을 수 있는 자식만**(못 읽는 폴더는 응답에 없다), `file_count` 는 그 안에서 귀속 계정이 볼 수 있는 파일 수 |
| 실패 | `401`(헤더 없음 → 「로그인이 필요합니다」 · 틀린 토큰 → 「API 토큰이 올바르지 않습니다」) · `403 {"error":"권한이 없습니다"}`(`parent` 를 읽을 수 없거나 없는 번호 — 둘을 구분하지 않는다) · `503`(토큰 미설정) |
| 넣지 않는 것 | 폴더를 만든 사람 · 못 읽는 폴더의 존재 · 전체 개수 |

폴더 안의 **파일**은 8장의 검색에 `folder=<id>` 를 붙여 받는다: `GET /api/files?folder=<id>&limit=` (`q` 없이 `folder` 만 보내면 그 폴더 안 목록, `q` 도 보내면 그 폴더 안에서 검색).

```bash
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/api/folders'            # 루트
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/api/folders?parent=12'  # 12번 폴더의 자식
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/api/files?folder=12&limit=50'   # 12번 폴더 안 파일(8장 배열)
```

실측 2026-09-16 (검토 포트 8082, 귀속을 검토 계정 `qa_employee01`(Employee·팀 1)로 잠시 두고, `-A "tbox-review/2026-09-16"`). 시험이 만든 폴더는 곧바로 지웠고 파일은 휴지통으로 보냈다.

```bash
# 루트 — 이 계정이 볼 수 있는 루트 폴더가 없던 시점. 같은 계정의 세션 응답과 JSON 문자열까지 동일했다
curl -s -w ' [%{http_code}]\n' -H "X-API-Token: $API_TOKEN" 'http://127.0.0.1:8082/api/folders'
{"folder":{"id":0,"name":null,"access_level":null,"breadcrumb":[]},"folders":[]} [200]

# qa_admin01 이 만든 admin-only 폴더 S — Employee 귀속의 트리에는 없고, 번호로 직접 열어도 403
curl -s -w ' [%{http_code}]\n' -H "X-API-Token: $API_TOKEN" 'http://127.0.0.1:8082/api/folders?parent=<S>'
{"error":"권한이 없습니다"} [403]
```

### 11-2. 만들기 — `POST /api/folders`

| 항목 | 내용 |
|---|---|
| 인증 | 헤더 **`X-API-Token`**. `X-CSRF-Token` 은 필요 없다 |
| 요청 | JSON `{"name":"<1~50자>","access_level":"public|internal|team|admin-only","parent_id":<번호 또는 생략>,"team_id":<team 일 때 필수>}` — 허용 키는 이 넷뿐(화면과 같다) |
| 성공 | `201` `{"folder":{"id":<정수>,"parent_id":…,"name":…,"depth":…,"access_level":…,"team_id":…,"child_count":0,"file_count":0,"created_at":…}}` — 화면 응답과 동일 |
| 실패 | `400` — 깊이 `{"error":"폴더는 5단계까지만 만들 수 있습니다"}` · 부모보다 넓은 등급 `{"error":"파일이나 폴더의 등급이 상위 폴더의 등급보다 넓을 수 없습니다"}` (F-68, ARCHITECTURE 5.30) · 이름 규칙·허용 외 키는 `{"error":"잘못된 요청입니다"}` · `403 {"error":"권한이 없습니다"}`(그 등급·팀으로 만들 자격 없음 — 10장 `upload_levels` 밖 · 부모를 읽을 수 없음) · `409 {"error":"이미 있는 이름입니다"}`(같은 부모 안 같은 이름) |

```bash
curl -s -w '\n[%{http_code}]\n' -H "X-API-Token: $API_TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"2026-09 보고서","access_level":"internal"}' https://changkwon.mso.so/api/folders
```

실측 2026-09-16 (검토 포트 8082, 귀속을 검토 계정 `qa_employee01`(Employee·팀 1)로 잠시 두고, `-A "tbox-review/2026-09-16"`). 시험이 만든 폴더는 곧바로 지웠고 파일은 휴지통으로 보냈다.

```bash
# 만들기 → 201
{"folder":{"id":57,"parent_id":null,"name":"F58-a-0916-1453127","depth":1,"access_level":"internal","team_id":null,"child_count":0,"file_count":0,"created_at":"2026-09-16 17:15:19.57908+09"}} [201]
# 같은 이름 다시 → 409
{"error":"이미 있는 이름입니다"} [409]
# name 없음 → 400
{"error":"잘못된 요청입니다"} [400]
# access_level=admin-only (Employee 귀속 — 10장 upload_levels 밖) → 403
{"error":"권한이 없습니다"} [403]
```

### 11-3. 이름 변경·이동 — `PATCH /api/folders/{num}`

| 항목 | 내용 |
|---|---|
| 인증 | 헤더 **`X-API-Token`** |
| 요청 | JSON — 토큰 요청의 허용 키는 **`name`·`parent_id` 둘뿐**이다. `{"name":"새 이름"}` · `{"parent_id":<번호>}`(옮기기) · `{"parent_id":null}`(루트로) · 둘을 함께. `access_level`·`team_id`·`cascade`·`reason_text` 는 화면에서는 되지만 **토큰으로 보내면 400** — 등급 변경은 사람의 결정이라 프로그램 창구에 열지 않았다(ARCH 4.5) |
| 성공 | `200` `{"folder":{"id","parent_id","name","depth","access_level","team_id","updated_at"},"cascaded":0}` — 화면 응답과 동일 |
| 실패 | `400`(허용 외 키 · 이름 규칙 · 바뀌는 것 없음 · 자기 자신·자손 아래로 이동 · 옮긴 뒤 깊이 5 초과 · 새 부모보다 넓은 등급) · `403`(귀속 계정이 고칠 수 없는 폴더 — 남이 만든 폴더, Employee 는 자기가 만든 것만 · 없는 번호도 같은 403) · `409`(새 위치에 같은 이름) |

```bash
curl -s -w '\n[%{http_code}]\n' -X PATCH -H "X-API-Token: $API_TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"2026-09 월간 보고서"}' https://changkwon.mso.so/api/folders/12
curl -s -w '\n[%{http_code}]\n' -X PATCH -H "X-API-Token: $API_TOKEN" -H 'Content-Type: application/json' \
  -d '{"parent_id":7}' https://changkwon.mso.so/api/folders/12
```

실측 2026-09-16 (검토 포트 8082, 귀속을 검토 계정 `qa_employee01`(Employee·팀 1)로 잠시 두고, `-A "tbox-review/2026-09-16"`). 시험이 만든 폴더는 곧바로 지웠고 파일은 휴지통으로 보냈다.

```bash
# 이름 변경 {"name":"F58-a2-…"} → 200
{"folder":{"id":57,"parent_id":null,"name":"F58-a2-0916-1453127","depth":1,"access_level":"internal","team_id":null,"updated_at":"2026-09-16 17:15:20.046726+09"},"cascaded":0} [200]
# 폴더 58 을 57 아래로 {"parent_id":57} → 200, depth 2
{"folder":{"id":58,"parent_id":57,"name":"F58-b-0916-1453127","depth":2,"access_level":"internal","team_id":null,"updated_at":"2026-09-16 17:15:20.248046+09"},"cascaded":0} [200]
# 57 을 자기 자식 58 아래로 → 400 (cycle) — F-68 부터 사유 문장이다
{"error":"폴더를 자기 자신이나 그 하위 폴더 안으로 옮길 수 없습니다"} [400]
# {"access_level":"public"} — 토큰 허용 키 밖 → 400 (화면에서는 되는 요청이다)
{"error":"잘못된 요청입니다"} [400]
# {"name":"…","cascade":false} — 토큰 허용 키 밖 → 400
{"error":"잘못된 요청입니다"} [400]
# 못 읽는(admin-only) 폴더 S 의 이름 변경 → 403
{"error":"권한이 없습니다"} [403]
```

---

## 12. 프로그램용 파일 이동 — `PATCH /api/files/{id}`

8장이 돌려준 파일 `id` 를 **다른 폴더로 옮기는** 창구다 (PRD F-58, ARCHITECTURE 4.5). 화면의 「이동」과 같은 창구·같은 판정이다.
같은 경로가 화면에서는 설명·등급 변경도 하지만, **토큰 요청은 `folder_id` 하나만** 받는다.

**범위(F-66)** — 이 창구는 `read+upload` 이상의 범위가 필요하다. `read` 토큰으로 부르면 `403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}` 다 (13장 · ARCHITECTURE 4.7).

| 항목 | 내용 |
|---|---|
| 인증 | 헤더 **`X-API-Token`**. `X-CSRF-Token` 은 필요 없다 |
| 요청 | JSON `{"folder_id":<폴더 번호>}` 또는 `{"folder_id":null}`(루트로). **다른 키가 하나라도 있으면 400** |
| 성공 | `200 {"ok":true}` |
| 실패 | `400` — 대상 폴더가 없거나 **읽을 수 없음**(둘을 구분하지 않는다) `{"error":"folder_id 가 없거나 접근할 수 없는 폴더입니다"}` · 폴더보다 넓은 등급의 파일 `{"error":"파일이나 폴더의 등급이 상위 폴더의 등급보다 넓을 수 없습니다"}` · 바뀌는 것 없음 `{"error":"바뀐 내용이 없습니다"}` (F-68, ARCHITECTURE 5.30) · 허용 외 키는 `{"error":"잘못된 요청입니다"}` · `403 {"error":"권한이 없습니다"}`(귀속 계정이 고칠 수 없는 파일 — Employee 는 자기가 올린 것만) · `404`(없는 id) |
| 삭제는 | `DELETE /api/files/{id}` 는 토큰으로 열려 있지 않다(헤더 무시 → 401 「로그인이 필요합니다」) |

```bash
curl -s -w '\n[%{http_code}]\n' -X PATCH -H "X-API-Token: $API_TOKEN" -H 'Content-Type: application/json' \
  -d '{"folder_id":12}' https://changkwon.mso.so/api/files/<id>
```

실측 2026-09-16 (검토 포트 8082, 귀속을 검토 계정 `qa_employee01`(Employee·팀 1)로 잠시 두고, `-A "tbox-review/2026-09-16"`). 시험이 만든 폴더는 곧바로 지웠고 파일은 휴지통으로 보냈다.

```bash
# 7장으로 올린 파일을 폴더 57 로 {"folder_id":57} → 200. 이어서 GET /api/files?folder=57 배열에 그 id 가 있었다
{"ok":true} [200]
# 루트로 {"folder_id":null} → 200
{"ok":true} [200]
# {"description":"x"} — 토큰 허용 키 밖 → 400 (화면에서는 되는 요청이다)
{"error":"잘못된 요청입니다"} [400]
# 못 읽는(admin-only) 폴더 S 로 {"folder_id":<S>} → 400 (없는 폴더와 같은 응답 — 존재를 알리지 않는다)
{"error":"folder_id 가 없거나 접근할 수 없는 폴더입니다"} [400]
```

## 13. 개인 프로그램 토큰 — 본인 발급·목록·회수 (내 계정 화면·세션)

프로그램이 부르는 창구가 아니라 **내가 내 토큰을 만드는** 창구다 (PRD F-66, ARCHITECTURE 4.7).
「내 계정」(`/settings`)의 **「프로그램 토큰」** 절이 이 셋을 부른다.

F-66(2026-09-22)에서 세 가지가 바뀌었다.
1. **발급은 본인만** 한다. 관리자가 남의 토큰을 만드는 창구(`POST /api/users/{uid}/api-tokens`)는 **라우터에서 지웠다** — 지금 부르면 `405 {"error":"허용되지 않는 메서드입니다"}` 다(같은 경로의 목록 `GET` 은 남아 있어 라우터가 「메서드만 다르다」로 먼저 끝낸다. 인증·인가에는 닿지 않는다). 관리자는 남의 토큰을 **보고 회수**할 수만 있다(아래 「관리자 창구」).
2. 발급 요청마다 **현재 비밀번호 + 2단계 인증 코드**를 함께 보낸다. 세션에 「확인됨」이 남지 않으므로 15분 창 같은 것이 없고, **2단계 인증이 꺼진 계정은 발급할 수 없다**.
3. 토큰마다 **범위**와 **만료 180일**이 있다(0장 「범위·만료」).

개인 토큰은 발급받은 **그 계정의 권한** 안에서, **고른 범위**만큼 동작한다. 감사 로그에 계정·토큰 번호·범위가 남고, 회수하면 즉시 401 이다. 공용 토큰(7장, `.env`)은 이행용으로만 남는다 — 개인 토큰을 프로그램에 심었으면 서버 `.env` 의 `API_TOKEN`·`API_TOKEN_USER` 두 줄을 지운다.

| 항목 | 내용 |
|---|---|
| 인증 | 세션 쿠키 + `X-CSRF-Token`(POST·DELETE). **로그인한 본인**이면 누구나(정책 `authenticated`) — 재인증 관문은 없다. 대상은 언제나 세션의 본인이고 요청에 계정을 받지 않는다 |
| 목록 | `GET /api/me/api-tokens` → `200 {"tokens":[{"id":<정수>,"name":"…","hint":"<앞 6자>","purpose":"…|null","scope":"read|read+upload|read+upload+delete","created_at":"…","expires_at":"…","expires_soon":<true/false>,"last_used_at":"…|null","revoked_at":"…|null"}]}` — **토큰 값은 없다**. `expires_soon` 은 만료가 14일 안인가(화면이 안내 줄을 켜는 값) |
| 발급 | `POST /api/me/api-tokens` JSON **허용 키 다섯** `{"name":"<1~50자>","purpose":"<필수, 500자>","scope":"<아래 셋 중 하나>","password":"<현재 비밀번호>","otp":"<6자리>"}` → `201 {"id":<정수>,"name":"…","hint":"…","scope":"…","expires_at":"…","token":"<48자>"}`. **`token` 은 이 응답에만 나온다** — 서버는 sha256 만 저장하므로 다시 보여 줄 수 없다. 잃으면 회수하고 새로 발급한다 |
| 고를 수 있는 범위 | `read`(읽기만) · `read+upload`(읽기 + 올리기) · `read+upload+delete`(읽기 + 올리기 + 지우기). **역할이 허용하는 것만** 고를 수 있다 — 올릴 수 없는 역할(Guest)은 `read` 하나뿐이다. 그 목록은 `GET /api/me` 의 `token_scopes` 이고, 발급 폼을 보일지는 `can_issue_tokens`(= 2단계 인증이 켜졌나)가 정한다 |
| 회수 | `DELETE /api/me/api-tokens/{tid}` → `200 {"ok":true}`. 그 순간부터 그 토큰은 401 「API 토큰이 올바르지 않습니다」. **비밀번호·코드를 묻지 않는다** — 닫는 방향은 자격을 줄이는 일이다 |
| 실패 | `400 {"error":"살아 있는 토큰이 상한에 이르렀습니다 — 먼저 하나를 회수하세요"}` — 살아 있는 토큰이 이미 5개(F-68, ARCHITECTURE 5.30) · `400 {"error":"잘못된 요청입니다"}` — 허용 외 키·이름 규칙·용도 없음·**역할 밖 범위나 모르는 범위**(역할 힌트를 주지 않는다), 그리고 **인증 실패 전부**(틀린 비밀번호·틀린 코드·이미 쓴 코드 — 세 사유가 한 문구다, ARCHITECTURE 5.30 예외 목록·R-48) · `403 {"error":"권한이 없습니다"}` — **2단계 인증이 꺼진 계정**(`mfa_not_enabled`, 실패로 세지 않는다) · CSRF 없음 · `404 {"error":"찾을 수 없습니다"}` — 내 토큰이 아닌 tid(그 번호가 있는지도 새지 않는다) · 이미 회수된 토큰은 `400 {"error":"잘못된 요청입니다"}` |
| 넣지 않는 것 | 토큰 원문(발급 응답 한 번 밖에는 어디에도 — 목록·감사·로그) · 해시 · **비밀번호·2단계 코드**(응답·감사·로그 0) |
| 감사 | `api_token.issue allow`(detail `token_id`·`token_name`·**`token_scope`**·`reason_text`·`otp_verified:true`) · 거부 `mfa_not_enabled`·`bad_credentials`·`bad_code`·`code_reused`·`bad_request`·`limit_exceeded` · `api_token.revoke allow` · 경보 **AL-20**(발급 1건 즉시, 근거에 범위) |
| 관리자 창구 | 목록 `GET /api/users/{uid}/api-tokens`(행에 `scope`·`expires_at` 추가) · 회수 `DELETE /api/users/{uid}/api-tokens/{tid}` — 정책 `user.manage`(Admin + 재인증 15분 창). **발급은 없다**(`POST` → 405) |
| 토큰으로는 부를 수 없다 | 이 셋은 **토큰 집합 밖**이다 — `X-API-Token` 으로 부르면 세션이 없어 `401 {"error":"로그인이 필요합니다"}` 다. 토큰이 토큰을 낳지 않는다 |

```bash
# 본인 세션(jar)으로 — CSRF 는 3장. 비밀번호와 지금의 2단계 코드를 **요청마다** 함께 보낸다.
curl -s -b jar -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' \
  -d '{"name":"사무실 PC","purpose":"트레이 프로그램용","scope":"read+upload","password":"<현재 비밀번호>","otp":"123456"}' \
  https://changkwon.mso.so/api/me/api-tokens

# 목록 · 회수
curl -s -b jar https://changkwon.mso.so/api/me/api-tokens
curl -s -b jar -X DELETE -H "X-CSRF-Token: $CSRF" https://changkwon.mso.so/api/me/api-tokens/<tid>
```

실측은 `tests/f66.sh`(T-78)가 남긴다 — 검토 계정 `qa_employee01`(본인 발급)·`qa_admin01`(관리자 목록·회수), `-A "tbox-review/<날짜>"`. 발급받은 토큰·비밀번호·코드는 규칙(S-09-11 · 7.7)대로 어디에도 기록하지 않는다 — 아래 `token` 자리는 모양만이다. 시험이 발급한 토큰은 전부 회수하고 파일은 휴지통, 폴더는 삭제한다.

```bash
# 본인 발급 → 201. token 은 48자 [A-Za-z0-9], hint 는 그 앞 6자, expires_at 은 발급 + 180일
{"id":12,"name":"F66-a-…","hint":"<앞 6자>","scope":"read+upload","expires_at":"2027-03-21 12:00:00+09","token":"<48자 — 이 응답에만>"} [201]
# 내 목록 → 200. 행의 키는 열(created_at·expires_at·expires_soon·hint·id·last_used_at·name·purpose·revoked_at·scope), token 키 없음
{"tokens":[{"id":12,"name":"F66-a-…","hint":"<앞 6자>","purpose":"…","scope":"read+upload","created_at":"…","expires_at":"…","expires_soon":false,"last_used_at":null,"revoked_at":null}]} [200]
# 틀린 비밀번호 · 틀린 코드 · 이미 쓴 코드 · 역할 밖 범위 · 모르는 키 → 전부 같은 400 문구
{"error":"잘못된 요청입니다"} [400]
# 2단계 인증이 꺼진 계정 → 403 (실패로 세지 않는다)
{"error":"권한이 없습니다"} [403]
# 살아 있는 토큰 5개 뒤 6번째 → 400 (limit_exceeded — F-68 부터 사유 문장이다)
{"error":"살아 있는 토큰이 상한에 이르렀습니다 — 먼저 하나를 회수하세요"} [400]
# 회수 → 200 · 다시 회수 → 400 · 내 것이 아닌 tid → 404
{"ok":true} [200]
{"error":"잘못된 요청입니다"} [400]
{"error":"찾을 수 없습니다"} [404]
# 회수된 토큰으로 GET /api/whoami → 401 (회수 사실을 알리지 않는다 — 틀린 토큰과 같은 답)
{"error":"API 토큰이 올바르지 않습니다"} [401]
# **만료된** 토큰은 문구가 다르다 (시간의 결과라 숨길 이유가 없다 — ARCHITECTURE 4.7)
{"error":"만료된 API 토큰입니다 — 내 계정에서 새로 발급하세요"} [401]
# 범위 밖 창구 (read 토큰으로 POST /api/upload)
{"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"} [403]
# 옛 관리자 발급 창구 → 405 (POST 라우트만 제거 · GET 은 남음) · 개인 토큰으로 본인 발급 창구 → 401 (토큰 집합 밖)
{"error":"허용되지 않는 메서드입니다"} [405]
{"error":"로그인이 필요합니다"} [401]
```

감사 로그: `api_token.issue allow` 의 actor 와 target 이 **같은 본인**이고 detail 은 `token_id`·`token_name`·`token_scope`·`reason_text`·`otp_verified`(+`route`·`login_ref`·`actor_team`)다. 범위 거부는 `auth.token deny scope_not_permitted`(detail `token_id`·`token_scope`), 만료는 `auth.token deny token_expired`. `audit_logs`·`api_tokens` 어디에도 토큰 원문·비밀번호·코드가 없다. `scripts/alerts.php --rule AL-20 --dry-run` 이 발급자(=본인) 1명을 잡고 근거에 범위가 담긴다.

---

## 14. 프로그램용 삭제 — `DELETE /api/files/{id}` · `DELETE /api/folders/{num}` (개인 토큰만)

8장·11장이 돌려준 `id`·번호로 **파일을 휴지통에 보내고 빈 폴더를 지우는** 창구다 (PRD F-59, ARCHITECTURE 4.6). 화면의 삭제와 같은 창구·같은 판정이다.
**개인 토큰(13장)만 통한다.** 공용 토큰(`.env`)으로 부르면 403 이다 — 누가 지웠는지 사람 단위로 남지 않는 자격으로는 지울 수 없게 했다.

**범위(F-66)** — 이 창구는 **`read+upload+delete`** 범위만 쓸 수 있다. 좁은 범위의 토큰으로 부르면 `403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}` 이고, 개인 전용 창구 관문(공용 토큰 403)이 그보다 **앞**이다 (13장 · ARCHITECTURE 4.7).

| 항목 | 내용 |
|---|---|
| 인증 | 헤더 **`X-API-Token: <개인 토큰>`**. `X-CSRF-Token` 은 필요 없다. 헤더를 보내지 않으면 세션 쿠키 경로 |
| 파일 | `DELETE /api/files/{id}` → `200 {"ok":true}` — **30일 휴지통**(관리자가 화면 「휴지통」에서 되살릴 수 있다). 토큰 계정이 지울 수 있는 파일만(Employee 는 자기가 올린 것) |
| 폴더 | `DELETE /api/folders/{num}` → `200 {"ok":true,"moved_trashed":0}` — **빈 폴더만**. 하위 폴더 있음 → `400 {"error":"하위 폴더가 있습니다 — 먼저 지우세요","code":"folder_has_children"}` · 살아 있는 파일 있음 → `400 {"error":"파일이 있는 폴더는 지울 수 없습니다","code":"folder_not_empty"}` · 휴지통 파일만 남음 → `400 {"error":"휴지통에 있는 파일만 남았습니다 — …","code":"folder_has_trashed","trashed":[…]}` 이고 `?move_trashed=1` 을 붙여 다시 보내면 그 파일을 루트로 옮기고 폴더를 지운다. 재귀 삭제는 없다 |
| 실패 | `401`(헤더 없음 → 「로그인이 필요합니다」 · 틀린·회수된 토큰 → 「API 토큰이 올바르지 않습니다」) · **`403 {"error":"이 창구는 개인 토큰으로만 쓸 수 있습니다"}`**(공용 토큰) · `403 {"error":"권한이 없습니다"}`(남의 파일·폴더 · **없는 폴더 번호도 같은 403** — 순차 정수라 404 와 가르면 개수를 셀 수 있다) · `404`(없는 파일 id — UUID 라 열거가 안 된다) |
| 감사 | `file.delete allow`·`folder.delete allow` 에 `detail.auth=api_token`·`token_kind=personal`·`token_id=<번호>` — 관리자가 13장 목록의 번호와 맞춰 「어느 PC 의 토큰」인지 안다 |

```bash
curl -s -w '\n[%{http_code}]\n' -X DELETE -H "X-API-Token: $API_TOKEN" https://changkwon.mso.so/api/files/<id>
curl -s -w '\n[%{http_code}]\n' -X DELETE -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/api/folders/<번호>?move_trashed=1'
```

실측 2026-09-16 (검토 포트 8082, 검토 계정 `qa_admin01`(발급자·재인증)·`qa_employee01`(대상), `-A "tbox-review/2026-09-16"`, `tests/api_tokens.sh`). 발급받은 토큰은 규칙(S-09-11)대로 어디에도 기록하지 않았다 — 아래 `token` 자리는 모양만이다. 시험이 발급한 토큰은 전부 회수했고 파일은 휴지통, 폴더는 삭제했다.

```bash
# 개인 토큰(qa_employee01)으로 — whoami 가 그 사람을 가리킨다 (F-56 ⑤)
curl -s -H "X-API-Token: $API_TOKEN" http://127.0.0.1:8082/api/whoami
{"username":"qa_employee01","role":"Employee","team_id":1,"upload_levels":["public","internal","team"]} [200]
# 자기가 올린 파일 삭제 → 200, DB deleted_at 설정(휴지통)
{"ok":true} [200]
# 자기 빈 폴더 삭제 → 200
{"ok":true,"moved_trashed":0} [200]
# 파일이 든 폴더 → 400 (code 로 갈래를 준다 — F-41)
{"error":"파일이 있는 폴더는 지울 수 없습니다","code":"folder_not_empty"} [400]
# 남의(qa_admin01) 파일 → 403 · 없는 폴더 번호 → 403(같은 문구) · 없는 파일 id → 404
{"error":"권한이 없습니다"} [403]
{"error":"찾을 수 없습니다"} [404]
# 세션으로 같은 삭제를 CSRF 없이 → 403 (웹 동작 불변)
{"error":"권한이 없습니다"} [403]
```

감사 로그(실측): `file.delete allow`·`folder.delete allow` 행에 `detail.auth=api_token`·`token_kind=personal`·`token_id=<번호>` 가 붙었다. 공용 토큰(`.env`, 귀속 admin01)으로 같은 DELETE 둘을 부른 실측(2026-09-17): `{"error":"이 창구는 개인 토큰으로만 쓸 수 있습니다"} [403]`, 감사 `auth.token deny shared_token_not_permitted` + `token_kind=shared`. 같은 토큰의 `GET /api/files` 는 200 그대로다.

> 토큰은 이 **열** 밖에서는 아무것도 열지 못한다 — 등급 변경·휴지통 복원·계정·감사 창구는 헤더를 읽지도 않는다(0장).

---

## 추가 규칙 (API 를 새로 적을 때)

1. 장 번호를 이어서 붙이고 제목은 `## N. 이름 — 메서드 경로`.
2. 표에 **인증 · 요청 · 성공 · 실패** 네 줄은 반드시 적고, 그 아래 `curl` 예시와 **실제로 받은 응답**을 붙인다(추정 응답을 적지 않는다).
3. 응답에 넣지 않는 것(정찰 재료·오류 원문)이 있으면 「넣지 않는 것」 줄로 밝힌다.
4. `PRD.md` 8장 API 표에 같은 행이 있어야 한다 — 문서가 먼저, 구현이 다음.

> 예시 응답은 2026-09-15 에 검토 계정 `qa_employee01` 로 서버 안 검토 포트에서 실제로 받은 값이다(업로드한 시험 파일은 곧바로 휴지통으로 보냈다).
