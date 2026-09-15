# t-box API 문서 (API.md)

외부 프로그램(로컬 업로드 도구 등)이 부를 수 있는 창구의 **주소·요청·응답 예시**를 모아 둔 문서다.
API 를 추가할 때마다 이 문서에 **이어서** 적는다(마지막 장의 「추가 규칙」). 요구사항의 원본은 `PRD.md` 8장 API 표이며,
이 문서와 8장이 어긋나면 8장이 옳다. 이 파일은 저장소 루트에 있고 웹으로는 열리지 않는다(`public/` 밖 — ARCHITECTURE 5.24).

## 0. 공통

| 항목 | 내용 |
|---|---|
| 기본 주소 | 공개: `https://changkwon.mso.so` · 서버 안 검토용: `http://127.0.0.1:8082` |
| 형식 | 요청·응답은 JSON (`Content-Type: application/json; charset=utf-8`). 파일 업로드만 `multipart/form-data` |
| 인증 | 기본은 **세션 쿠키**(아래 「세션」)다. 헤더 `X-API-Token` 이 통하는 창구는 **셋뿐**이다 — `POST /api/upload`(7장) · `GET /api/files`(8장) · `GET /api/files/{id}/download`(9장). 앞의 하나는 토큰 전용이고, 뒤의 둘은 화면도 쓰는 창구라 **헤더를 보내면 토큰으로, 안 보내면 세션으로** 판정한다. 그 헤더는 **그 밖의 창구에서는 무시된다**(삭제·수정·계정·감사 등은 읽지도 않는다) |
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
| 실패 | `400 {"error":"잘못된 요청입니다"}`(형식·확장자·등급·팀 오류) · `403`(CSRF 없음 또는 그 등급·팀에 올릴 권한 없음 — Guest 는 업로드 불가) · `413`(크기) |

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

| 항목 | 내용 |
|---|---|
| 메서드·경로 | `POST /api/upload` |
| 인증 | 헤더 **`X-API-Token: <토큰>`** 하나. 세션 쿠키도 `X-CSRF-Token` 도 필요 없다. 이 헤더가 통하는 창구는 **여기 하나뿐**이고 다른 경로는 읽지도 않는다. 토큰 값은 서버의 `.env` 에만 있다 — **서버에서 `cat .env` 로 확인**하고, 문서·채팅·소스에 적지 않는다 |
| 요청 | `multipart/form-data`. `file`(필수) · `description`(선택, 500자) · `level`(선택: `public`·`internal`·`team`·`admin-only` — **없으면 `public`**) · `team_id`(`level=team` 이면 필수, 양의 정수) · `folder_id`(선택) |
| 제한 | 4장과 같다 — 100MB · 허용 확장자 `pdf docx xlsx pptx hwp hwpx txt csv md png jpg jpeg gif zip` · 내용(MIME)이 확장자와 맞아야 함 · 이중 확장자 거부 · 하루 총량 2GB · 민감정보가 보이면 `team`/`admin-only` 로만 |
| 성공 | `201` `{"id":"<UUID>","name":"<원래 파일명>","level":"<등급>"}` — 4장과 달리 `level` 이 함께 온다(기본값 `public` 이 적용됐는지 프로그램이 확인할 수 있게) |
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

| 항목 | 내용 |
|---|---|
| 메서드·경로 | `GET /api/files?q=<검색어>&limit=<1~100>` |
| 인증 | 헤더 **`X-API-Token: <토큰>`**(7장과 같은 토큰). 헤더를 보내지 않으면 세션 쿠키 경로로 판정한다 |
| 요청 | `q`(선택, 200자 — 파일명·설명 **부분 일치**) · `limit`(선택, 1~100, 기본 20 — 형태가 아니거나 범위 밖이면 20) · `page`(선택, 1부터) |
| 성공 | `200` — 최상위가 **배열**이고 원소 키는 다섯뿐이다: `[{"id":"<UUID>","name":"<파일명>","level":"<등급>","size":<바이트>,"uploaded_at":"<올린 시각>"}]`. 결과가 없으면 `[]` |
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
토큰으로 올린 `F50-doc.txt` 를 검색한 결과다(**최상위가 배열**, 원소 키 다섯). 검토 뒤 파일은 휴지통, 귀속은 운영 계정으로 되돌렸다.

```bash
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/api/files?q=F50-doc&limit=8'
[{"id":"6faa43a5-9673-4224-96ea-b4b5d20bb01b","name":"F50-doc.txt","level":"public","size":22,"uploaded_at":"2026-09-15 21:06:58.855433+09"}]

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

| 항목 | 내용 |
|---|---|
| 메서드·경로 | `GET /api/files/{id}/download` — `{id}` 는 업로드·검색이 돌려준 **UUID 36자** |
| 인증 | 헤더 **`X-API-Token: <토큰>`**. 헤더를 보내지 않으면 세션 쿠키 경로로 판정한다 |
| 요청 | 본문·파라미터 없음 |
| 성공 | `200` + 파일 내용(`Content-Disposition` 에 **올릴 때의 원래 이름**, `Content-Type`·`Content-Length`) |
| 실패 | `401`(토큰 틀림·인증 없음) · **`403`** `{"error":"권한이 없습니다"}` — 파일은 있지만 귀속 계정이 볼 수 없음 · **`404`** `{"error":"찾을 수 없습니다"}` — 그런 파일이 없거나 휴지통에 있거나 `id` 가 UUID 형태가 아님 · `500 {"error":"서버 오류"}` |
| 넣지 않는 것 | 403 은 파일 이름·등급·소유자를 알려 주지 않는다(항상 같은 문구). 500 도 원인을 알려 주지 않는다 — 원인은 서버 로그의 `request_id` 로만 |
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

> 토큰은 이 셋 밖에서는 아무것도 열지 못한다 — 같은 헤더로 `DELETE /api/files/{id}` ·
> `PATCH /api/files/{id}` · `GET /api/users` 를 불러도 `401 {"error":"로그인이 필요합니다"}` 다(실측).

---

## 추가 규칙 (API 를 새로 적을 때)

1. 장 번호를 이어서 붙이고 제목은 `## N. 이름 — 메서드 경로`.
2. 표에 **인증 · 요청 · 성공 · 실패** 네 줄은 반드시 적고, 그 아래 `curl` 예시와 **실제로 받은 응답**을 붙인다(추정 응답을 적지 않는다).
3. 응답에 넣지 않는 것(정찰 재료·오류 원문)이 있으면 「넣지 않는 것」 줄로 밝힌다.
4. `PRD.md` 8장 API 표에 같은 행이 있어야 한다 — 문서가 먼저, 구현이 다음.

> 예시 응답은 2026-09-15 에 검토 계정 `qa_employee01` 로 서버 안 검토 포트에서 실제로 받은 값이다(업로드한 시험 파일은 곧바로 휴지통으로 보냈다).
