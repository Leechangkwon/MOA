# t-box API 문서 (API.md)

외부 프로그램(로컬 업로드 도구 등)이 부를 수 있는 창구의 **주소·요청·응답 예시**를 모아 둔 문서다.
API 를 추가할 때마다 이 문서에 **이어서** 적는다(마지막 장의 「추가 규칙」). 요구사항의 원본은 `PRD.md` 8장 API 표이며,
이 문서와 8장이 어긋나면 8장이 옳다. 이 파일은 저장소 루트에 있고 웹으로는 열리지 않는다(`public/` 밖 — ARCHITECTURE 5.24).

## 0. 공통

| 항목 | 내용 |
|---|---|
| 기본 주소 | 공개: `https://changkwon.mso.so` · 서버 안 검토용: `http://127.0.0.1:8082` |
| 형식 | 요청·응답은 JSON (`Content-Type: application/json; charset=utf-8`). 파일 업로드만 `multipart/form-data` |
| 인증 | 기본은 **세션 쿠키**(아래 「세션」)다. **`POST /api/upload` 만 예외** — 헤더 `X-API-Token` 하나로 인증하고 세션·CSRF·2단계 인증을 쓰지 않는다(7장). 그 헤더는 **다른 창구에서는 무시된다** |
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

## 추가 규칙 (API 를 새로 적을 때)

1. 장 번호를 이어서 붙이고 제목은 `## N. 이름 — 메서드 경로`.
2. 표에 **인증 · 요청 · 성공 · 실패** 네 줄은 반드시 적고, 그 아래 `curl` 예시와 **실제로 받은 응답**을 붙인다(추정 응답을 적지 않는다).
3. 응답에 넣지 않는 것(정찰 재료·오류 원문)이 있으면 「넣지 않는 것」 줄로 밝힌다.
4. `PRD.md` 8장 API 표에 같은 행이 있어야 한다 — 문서가 먼저, 구현이 다음.

> 예시 응답은 2026-09-15 에 검토 계정 `qa_employee01` 로 서버 안 검토 포트에서 실제로 받은 값이다(업로드한 시험 파일은 곧바로 휴지통으로 보냈다).
