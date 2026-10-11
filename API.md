# t-box API 문서 (API.md)

외부 프로그램(로컬 업로드 도구 등)이 부를 수 있는 창구의 **주소·요청·응답 예시**를 모아 둔 문서다.
API 를 추가할 때마다 이 문서에 **이어서** 적는다(마지막 장의 「추가 규칙」). 요구사항의 원본은 `PRD.md` 8장 API 표이며,
이 문서와 8장이 어긋나면 8장이 옳다. 이 파일은 저장소 루트에 있고 웹으로는 열리지 않는다(`public/` 밖 — ARCHITECTURE 5.24).

## 0. 공통

| 항목 | 내용 |
|---|---|
| 기본 주소 | 공개: `https://changkwon.mso.so/tbox` · 서버 안 검토용: `http://127.0.0.1:8082/tbox` — **2026-09-22 바뀌었다**(아래 「주소 변경」 절). 도메인 루트(`/`)는 Qaredi 런처다 |
| 형식 | 요청·응답은 JSON (`Content-Type: application/json; charset=utf-8`). 파일 업로드만 `multipart/form-data` |
| 인증 | 기본은 **세션 쿠키**(아래 「세션」)다. 헤더 `X-API-Token` 이 통하는 창구는 **열**이다 — `POST /api/upload`(7장) · `GET /api/files`(8장) · `GET /api/files/{id}/download`(9장) · `GET /api/whoami`(10장) · `GET /api/folders`·`POST /api/folders`·`PATCH /api/folders/{num}`(11장) · `PATCH /api/files/{id}`(12장, 폴더 이동만) · `DELETE /api/files/{id}`·`DELETE /api/folders/{num}`(14장, **개인 토큰만**) — 그리고 커뮤니티 **셋** `GET /community/api/me/space`·`POST /community/api/pages`·`GET /community/api/pages/{id}`(19장, Q:F-8 · **개인 토큰 · 범위 `community.write` 만**). 업로드와 whoami 둘은 토큰 전용이고(세션 쿠키로는 부를 수 없다 — 헤더가 없으면 401), 나머지 여덟은 화면도 쓰는 창구라 **헤더를 보내면 토큰으로, 안 보내면 세션으로** 판정한다. 그 헤더는 **그 밖의 창구에서는 무시된다**(등급 변경·휴지통 복원·계정·감사 등은 읽지도 않는다). **토큰은 두 종류**다(13장): **본인이** 「내 계정」 화면에서 발급하는 **개인 토큰**(권장 — 누가 했는지 사람 단위로 남고 회수할 수 있다)과 서버 `.env` 의 **공용 토큰**(F-49, 이행용 — 삭제 창구는 쓸 수 없다) |
| 범위·만료 (F-66) | 개인 토큰은 발급할 때 고른 **범위**(`read` · `read+upload` · `read+upload+delete` · Q:F-8 의 `community.write` — 커뮤니티 창구 셋과 whoami 만, 파일 창구 없음 · 19장)만큼만 창구를 열고, 발급 시각 + **180일**에 만료된다. 범위 밖 창구 → `403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}` · 만료된 토큰 → `401 {"error":"만료된 API 토큰입니다 — 내 계정에서 새로 발급하세요"}`(틀린·회수된 토큰의 401 문구와 다르다). 범위는 창구를 좁힐 뿐이고 파일 한 건의 판정은 요청마다 그대로 한다 — 토큰이 계정보다 커질 수 없다. 공용 토큰은 범위·만료가 없고 `read+upload` 로 본다(이행용). 어느 장이 어느 범위인지는 각 장의 「범위(F-66)」 줄 |
| 세션 | 로그인 성공 시 쿠키 **`__Host-tbox_session`** (HttpOnly·Secure·SameSite=Lax·Path=/·Domain 없음). 이후 요청에 그 쿠키를 함께 보낸다. 유휴 30분·최대 8시간. **v2.31 부터 이름이 바뀌었다**(전: `tbox_session`) — 옛 이름은 서버가 읽지 않고 만료 머리로 치우므로, 쿠키 이름을 글자로 적어 둔 프로그램은 새 이름으로 고친다(프로그램용 토큰 `X-API-Token` 은 그대로) |
| CSRF | 상태를 바꾸는 요청(POST·PATCH·DELETE)은 헤더 `X-CSRF-Token: <토큰>` 필수. 토큰은 `GET /api/me` 의 `csrf_token`. 없거나 틀리면 `403 {"error":"권한이 없습니다"}` |
| 오류 응답 | 항상 `{"error":"<한국어 문구>"}`. 권한 없음은 언제나 403 + 같은 문구(대상 정보 없음). 서버 오류는 500 `{"error":"서버 오류"}` — 원인은 응답에 나오지 않는다 |
| 속도 제한 | `POST /api/login` 은 IP 당 분당 30회(순간 10회까지 허용), 넘으면 429. 비밀번호 5회 실패 → 계정 10분 잠금(423) |
| 캐시 | 모든 API 응답은 `Cache-Control: no-store` |
| 도구 표식 | 사람이 아닌 프로그램이 부를 때는 `User-Agent` 를 알아볼 수 있게 붙이는 것을 권장한다(예: `-A "my-uploader/1.0"`). 감사 로그에 남아 사람의 조작과 구분된다 |

### 주소 변경 2026-09-22 (Qaredi C — 28단계)

t-box 의 **모든 주소 앞에 `/tbox` 가 붙었다.** 도메인 루트(`/`)에는 Qaredi 런처(앱 고르는 첫 화면)가 선다.

| 무엇 | 전 | 후 |
|---|---|---|
| 기본 주소 (공개) | `https://changkwon.mso.so` | `https://changkwon.mso.so/tbox` |
| 기본 주소 (서버 안 검토용) | `http://127.0.0.1:8082` | `http://127.0.0.1:8082/tbox` |
| 상태 확인 | `…/api/health` | `…/tbox/api/health` |
| 프로그램 업로드 | `…/api/upload` | `…/tbox/api/upload` |
| 공유 링크 | `…/s/<토큰>` | `…/tbox/s/<토큰>` |
| 2단계 코드 생성기 | `…/assets/totp-generator.html` | `…/tbox/assets/totp-generator.html` |

**옛 주소는 308 로 새 주소로 보낸다.** 308(Permanent Redirect)은 **메서드와 본문을 보존**하므로,
`POST /api/upload` 를 옛 주소로 보내도 리다이렉트를 따르는 클라이언트면 그대로 올라간다.
쿼리스트링도 원문 그대로 이어진다(`/api/files?q=x` → `/tbox/api/files?q=x`).

```bash
# 옛 주소로 부르면 308 과 새 주소가 온다
curl -sI https://changkwon.mso.so/api/health | head -2
HTTP/1.1 308 Permanent Redirect
Location: /tbox/api/health

# curl 은 -L 을 붙여야 따라간다. POST 의 본문·메서드도 그대로 간다.
curl -sL -w ' [%{http_code}]\n' https://changkwon.mso.so/api/health
```

**리다이렉트를 따르지 않는 프로그램은 주소를 바꾼다.** 설정에 적어 둔 주소의 호스트 뒤에 `/tbox` 를
넣으면 된다(`https://changkwon.mso.so/api/upload` → `https://changkwon.mso.so/tbox/api/upload`).
curl 은 `-L`, Python `requests` 는 기본으로 따라가지만 `allow_redirects=False` 로 쓰고 있으면 바꿔야 한다.

t-box 안의 **판정·권한·감사·응답 형식은 한 글자도 바뀌지 않았다** — 바뀐 것은 주소뿐이다.

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
curl -s -w '\n[%{http_code}]\n' https://changkwon.mso.so/tbox/api/health
{"status":"ok","db":"ok","time":"2026-09-15T19:02:11+09:00"}
[200]

# DB 가 죽었을 때
{"status":"fail","db":"connect_failed"}
[503]
```

한 줄 확인(상태 코드까지): `curl -s -w ' [%{http_code}]\n' https://changkwon.mso.so/tbox/api/health`

---

## 2. 로그인 — `POST /api/login`

| 항목 | 내용 |
|---|---|
| 인증 | 없음 |
| 요청 | `Content-Type: application/json` **필수**(폼 인코딩은 400). 본문 `{"username":"…","password":"…","otp":"123456"}` — `otp` 는 2단계 인증 코드 6자리(모든 계정 필수) |
| 성공 | `200` `{"ok":true,"next":null}` + `Set-Cookie: __Host-tbox_session=…`. `next` 가 `"password_change"` 또는 `"mfa_setup"` 이면 그 절차를 마쳐야 다른 API 가 열린다(그 전엔 403 `{"error":…,"next":"…"}`) |
| 코드 누락 | `401` `{"error":"2단계 인증 코드가 필요합니다","mfa_required":true}` — 비밀번호는 맞았고 `otp` 만 더 보내면 된다 |
| 실패 | `401 {"error":"아이디 또는 비밀번호가 올바르지 않습니다"}` · `423`(잠금) · `429`(속도 제한) · `400`(형식 오류) |
| 주의 | 같은 30초 창의 코드는 **한 번만** 쓸 수 있다. 두 번째 로그인은 다음 창까지 기다린다 |

```bash
curl -s -c jar -H 'Content-Type: application/json' \
  -d '{"username":"qa_employee01","password":"<비밀번호>","otp":"<6자리>"}' \
  https://changkwon.mso.so/tbox/api/login
{"ok":true,"next":null}
```

## 3. 내 정보 · CSRF 토큰 — `GET /api/me`

| 항목 | 내용 |
|---|---|
| 인증 | 세션 쿠키 |
| 응답 | `200` — 업로드 도구가 쓰는 키: `username`, `role`, `team_id`, **`csrf_token`**, **`upload_levels`**(내가 올릴 수 있는 접근 등급 목록, 예 `["public","internal","team"]`), `next`. 그 밖의 `can_*`·`acting*` 등은 화면용 편의 값이다 |
| 커뮤니티 쓰기 자격 (2026-09-26 디버깅 묶음 U) | `can_community_write` — `true`/`false`. 커뮤니티에 **쓸 수 있는 역할인가**(새 페이지·템플릿·편집·댓글 등). 값은 커뮤니티 쓰기 라우트 정책 `community.write` 가 부르는 함수(`Authz::canCommunityWrite` — 권한 매트릭스의 올리기 자격, Guest 거짓)를 그대로 부른 결과다. 다른 `can_*` 와 같은 **화면 편의 값**이며 권한이 아니다 — 쓰는 창구는 전부 정책 `community.write` 와 행 단위 판정이 매 요청 다시 막는다(손님이 `POST /community/api/pages` → `403`) |
| 지문·얼굴 로그인 (2026-09-27 T:F-72 — 16장) | `passkey_available` — 이 서버에서 쓸 수 있나(`APP_URL` 이 https 이름이고 표가 있다) · `passkeys_active` — 내 살아 있는 열쇠 수(해제·90일 제외). 둘 다 **화면 편의 값**이며 권한이 아니다 — 창구는 요청마다 다시 판정한다. 휴대폰인지는 화면이 정한다(서버는 가리지 않는다) |
| 고대비 화면 (커뮤니티 7-D3 — 18장) | `high_contrast` — `true`/`false`. 「내 계정」 「화면」 체크가 처음 상태를 이 값으로 놓는다. 계정 칸(`users.high_contrast`)이라 어느 기기에서나 같다. **화면 편의 값**이며 권한이 아니다 — 어떤 판정도 이 값을 읽지 않고, `<html data-contrast="high">` 는 서버가 같은 칸으로 그린다 |
| 실패 | `401 {"error":"로그인이 필요합니다"}` |

```bash
curl -s -b jar https://changkwon.mso.so/tbox/api/me
{"username":"qa_employee01","role":"Employee","team_id":1,"csrf_token":"…","upload_levels":["public","internal","team"],"next":null, …}
```

## 4. 파일 업로드 — `POST /api/files` (multipart/form-data)

| 항목 | 내용 |
|---|---|
| 인증 | 세션 쿠키 + `X-CSRF-Token` |
| 필드 | `file`(파일, 필수) · `access_level`(필수: `public`·`internal`·`team`·`admin-only`·`private`(G-0 개인 — 나와 지정한 사람만 · 팀 없음) — `upload_levels` 안의 값만) · `team_id`(`access_level=team` 일 때 필수, 양의 정수) · `description`(선택, 500자) · `folder_id`(선택, 폴더 번호) |
| 제한 | 100MB · 허용 확장자 `pdf docx xlsx pptx hwp hwpx txt csv md png jpg jpeg gif zip` · 내용(MIME)이 확장자와 맞아야 함 · 이중 확장자(`a.php.txt`) 거부 |
| 성공 | `201` `{"id":"<UUID>","name":"<원래 파일명>"}` — 이후 다운로드·삭제는 이 `id` 로 |
| 실패 | `400` — **사유마다 사람 문장**이다 (F-68, ARCHITECTURE 5.30 표가 원본): 확장자 `{"error":"허용되지 않는 확장자입니다 (허용: pdf docx xlsx pptx hwp hwpx txt csv md png jpg jpeg gif zip)"}` · 등급 `{"error":"level 값이 올바르지 않습니다 (public·internal·team·admin-only)"}` · 팀 `{"error":"team 등급은 team_id(활성 팀 번호)가 필요합니다"}` · 내용 검사 `{"error":"문서에 매크로가 들어 있어 올릴 수 없습니다"}` 등. **허용 키 밖·값의 형식 오류만** `{"error":"잘못된 요청입니다"}` · `403`(CSRF 없음 또는 그 등급·팀에 올릴 권한 없음 — Guest 는 업로드 불가) · `413`(크기) |

```bash
CSRF=$(curl -s -b jar https://changkwon.mso.so/tbox/api/me | python3 -c 'import sys,json;print(json.load(sys.stdin)["csrf_token"])')
curl -s -b jar -H "X-CSRF-Token: $CSRF" \
  -F 'file=@report.pdf' -F 'access_level=internal' -F 'description=9월 보고서' \
  https://changkwon.mso.so/tbox/api/files
{"id":"e7fecdea-e3f4-4133-9c1f-887408829e01","name":"report.pdf"}
```

## 5. 파일 삭제(휴지통) — `DELETE /api/files/{id}`

| 항목 | 내용 |
|---|---|
| 인증 | 세션 쿠키 + `X-CSRF-Token` |
| 성공 | `200 {"ok":true}` — 30일 보존 휴지통으로 간다(되살릴 수 있음) |
| 실패 | `403`(남의 파일·권한 없음) · `404`(없는 id) |

```bash
curl -s -b jar -H "X-CSRF-Token: $CSRF" -X DELETE https://changkwon.mso.so/tbox/api/files/<id>
{"ok":true}
```

## 6. 로그아웃 — `POST /api/logout`

세션 쿠키 + `X-CSRF-Token`. 성공 `200 {"ok":true}`. 이후 `GET /api/me` 는 401.

```bash
curl -s -b jar -H "X-CSRF-Token: $CSRF" -X POST https://changkwon.mso.so/tbox/api/logout
```

## 7. 프로그램용 업로드 — `POST /api/upload` (multipart/form-data)

로컬 PC 의 **프로그램**이 사람의 로그인·2단계 인증 없이 파일을 올리는 창구다. 4장(`POST /api/files`)과
**같은 검증·저장 코드**를 쓰고, 다른 것은 ① 인증 방법 ② 등급 필드 이름과 기본값 ③ 실패 문구 셋뿐이다.

**범위(F-66)** — 이 창구는 `read+upload` 이상의 범위가 필요하다. `read` 토큰으로 부르면 `403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}` 다 (13장 · ARCHITECTURE 4.7).

| 항목 | 내용 |
|---|---|
| 메서드·경로 | `POST /api/upload` |
| 인증 | 헤더 **`X-API-Token: <토큰>`** 하나. 세션 쿠키도 `X-CSRF-Token` 도 필요 없다. 이 헤더가 통하는 창구는 **여기 하나뿐**이고 다른 경로는 읽지도 않는다(F-50·F-56 으로 넷, 0장). 토큰 값은 서버의 `.env` 에만 있다 — **서버에서 `cat .env` 로 확인**하고, 문서·채팅·소스에 적지 않는다 |
| 요청 | `multipart/form-data`. `file`(필수) · `description`(선택, 500자) · `level`(선택: `public`·`internal`·`team`·`admin-only`·`private` — **없으면 `public`**) · `team_id`(`level=team` 이면 필수, 양의 정수) · `folder_id`(선택) |
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
  https://changkwon.mso.so/tbox/api/upload
```

실제 응답 — 2026-09-15 검토 포트 8082 에서 **검토 계정 `qa_employee01`(Employee) 을 귀속 계정으로 잠시 두고** 실측한 값이다(올린 시험 파일은 곧바로 휴지통으로 보냈고, 검토 뒤 귀속은 운영 계정으로 되돌렸다).

```bash
# 성공 — level 을 생략하면 public 이 적용되고 응답에 그 값이 온다
curl -s -w '\n[%{http_code}]\n' -H "X-API-Token: $API_TOKEN" \
  -F 'file=@F49-token.txt' -F 'description=F-49 검토' http://127.0.0.1:8082/tbox/api/upload
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
{"error":"level 값이 올바르지 않습니다 (public·internal·team·admin-only·private)"} [400]
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
  'https://changkwon.mso.so/tbox/api/files?q=보고서&limit=8'
```

실제 응답(200) — 2026-09-15 Fable 검토, 검토 포트 8082, 귀속을 검토 계정 `qa_employee01` 로 잠시 둔 상태.
토큰으로 올린 `F50-doc.txt` 를 검색한 결과다(**최상위가 배열**, 원소 키 여섯). 검토 뒤 파일은 휴지통, 귀속은 운영 계정으로 되돌렸다.

```bash
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/tbox/api/files?q=F50-doc&limit=8'
[{"id":"6faa43a5-9673-4224-96ea-b4b5d20bb01b","name":"F50-doc.txt","level":"public","size":22,"uploaded_at":"2026-09-15 21:06:58.855433+09","exec_content":false}]

# 아무것도 안 걸리면 빈 배열이다(빈 객체 `{}` 가 아니다)
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/tbox/api/files?q=zzz-none-9f3a&limit=8'
[]

# 주입 시도는 글자 그대로 검색된다 — %' OR 1=1 -- 를 URL 인코딩해 보내면 그런 이름의 파일이 없으므로 빈 배열
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/tbox/api/files?q=%25%27%20OR%201%3D1%20--&limit=8'
[]
```

Employee 귀속 토큰의 검색에는 `admin-only` 파일이 **한 건도 오지 않는다**(T-62 (b) 실측 — 같은 검색을 `qa_admin01` 세션으로 하면 보인다).


아래는 **실측**이다(2026-09-15, 검토 포트 8082).

```bash
# 헤더도 세션도 없이
curl -s -w ' [%{http_code}]\n' 'http://127.0.0.1:8082/tbox/api/files?q=F50'
{"error":"로그인이 필요합니다"} [401]

# 헤더를 보냈는데 토큰이 틀림 — 세션으로 되돌아가지 않는다
curl -s -w ' [%{http_code}]\n' -H 'X-API-Token: <틀린 값>' 'http://127.0.0.1:8082/tbox/api/files?q=F50'
{"error":"API 토큰이 올바르지 않습니다"} [401]

# 세션(화면)으로 부르면 응답 모양은 지금까지와 똑같다 — 최상위가 객체다
curl -s -b jar 'http://127.0.0.1:8082/tbox/api/files?q=F50'
{"files":[…],"total":0,"page":1,"per_page":20,"scope":"all","folder":{…}}  [200]
```

## 9. 프로그램용 다운로드 — `GET /api/files/{id}/download`

8장이 돌려준 `id` 로 **파일 내용을 받는다**. 화면의 다운로드와 **같은 창구·같은 권한 판정**이고,
다른 것은 인증 방법뿐이다. 서버는 보내기 전에 저장된 sha256 과 대조한다(다르면 500).

> **여러 파일을 zip 하나로 받기(`POST /api/files/zip`, 2026-09-26 Q:F-3a 라운드 C)는 화면 전용이다 — 토큰 창구가 아니다**(결정 M-5).
> `X-API-Token` 을 보내도 읽지 않고 세션 경로로 판정한다(세션 없으면 401). 프로그램은 이 장의 창구로 한 건씩 받는다.
> 판정은 이 창구와 같은 함수(ARCHITECTURE 5.34)라, 프로그램으로 한 건씩 받아도 zip 과 결과가 같다.

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
  https://changkwon.mso.so/tbox/api/files/<UUID>/download
```

실제 200 응답 — 2026-09-15 Fable 검토(8장과 같은 조건). 헤더에 **원래 이름**이 담기고 본문은 올린 파일과 바이트가 같다(`diff` 동일, T-62 (d) sha256 일치).

```bash
curl -s -D - -o F50-doc.txt -H "X-API-Token: $API_TOKEN" https://changkwon.mso.so/tbox/api/files/6faa43a5-9673-4224-96ea-b4b5d20bb01b/download
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
curl -s -w ' [%{http_code}]\n' 'http://127.0.0.1:8082/tbox/api/files/not-a-uuid/download'
{"error":"찾을 수 없습니다"} [404]

# 헤더도 세션도 없이
curl -s -w ' [%{http_code}]\n' 'http://127.0.0.1:8082/tbox/api/files/00000000-0000-4000-8000-000000000000/download'
{"error":"로그인이 필요합니다"} [401]

# 틀린 토큰
curl -s -w ' [%{http_code}]\n' -H 'X-API-Token: <틀린 값>' \
  'http://127.0.0.1:8082/tbox/api/files/00000000-0000-4000-8000-000000000000/download'
{"error":"API 토큰이 올바르지 않습니다"} [401]
```

검사 중(F-57)의 실측이다 — 올린 **직후** 같은 `id` 로 부르면 423 이고, `Retry-After` 초만큼 뒤에 다시 하면 200 이다.

```bash
curl -s -D - -o /dev/null -w '[%{http_code}]\n' -H "X-API-Token: $API_TOKEN" \
  'http://127.0.0.1:8082/tbox/api/files/<UUID>/download'
# 실측 2026-09-16 15:1x — qa_employee01 세션, 검토 포트 8082. 올린 직후(scan_status=pending) 네 창구가 같은 답이다
curl -s -D - -o body 'http://127.0.0.1:8082/tbox/api/files/<id>/download' | grep -E '^HTTP|^Retry-After'; cat body
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
| 성공 | `200` `{"username":"…","role":"…","team_id":<정수 또는 null>,"upload_levels":["public","internal",…],"token_scope":"<정규형>","token_scopes_held":["<원자>",…]}` — 키는 **정확히 이 여섯**(F-56 의 넷 + **Q:F-10 의 둘** — 이 토큰이 **쥔** 범위, 21장), 이 순서다. 대상은 `.env` `API_TOKEN_USER` 의 귀속 계정이고, `upload_levels` 는 3장 `GET /api/me` 의 같은 이름 값과 **원소·순서까지 같다**(같은 함수 `Authz::uploadableLevels`) |
| 실패 | `401` `{"error":"X-API-Token 헤더가 없습니다"}`(헤더 없음 — 세션 쿠키만 보내도 같다) · `401` `{"error":"API 토큰이 올바르지 않습니다"}`(값이 틀림, 세션으로 되돌아가지 않는다) · `503`(서버에 토큰이 설정되지 않음·귀속 계정이 잘못됨). 문구·상태는 7장과 **한 글자도 같다** |
| 넣지 않는 것 | `csrf_token`(세션이 없으니 뜻이 없다) · `can_*`·`next`·`acting*`·`team_name` 같은 화면 편의 값 · 세션 정보 · 내부 id · **토큰 값**. 여섯 밖의 키는 하나도 오지 않는다 |
| 감사 | 성공은 감사 행을 남기지 않는다(대상 없는 조회, `GET /api/me` 와 같다). 실패는 `auth.token deny`(`missing_header`·`bad_token`·`not_configured`·`user_invalid`) 로 남는다 |

```bash
# 실측 2026-09-16 — 귀속을 검토 계정 qa_employee01(Employee, 팀 1)로 돌려 받은 값. 운영 귀속(관리자 계정 — 아이디는 d74 로 바뀜, 문서에 적지 않음)이면 role=Admin, upload_levels 4개
curl -s -w '\n[%{http_code}]\n' -H "X-API-Token: $API_TOKEN" \
  https://changkwon.mso.so/tbox/api/whoami
{"username":"qa_employee01","role":"Employee","team_id":1,"upload_levels":["public","internal","team"]}
[200]
```

프로그램이 쓰는 방법 — 받은 `upload_levels` 안에 있는 값만 7장의 `level` 로 보낸다. 목록에 없는 값을
보내도 서버가 `Authz::decideUploadAs` 로 다시 판단해 403 이므로(PRD S-01), 이 응답은 **권한이 아니라
선택지**다. 3장의 `upload_levels` 와 같은 성격이다.

아래는 **실측**이다(2026-09-16, 검토 포트 8082, `-A "tbox-review/2026-09-16"`).

```bash
# 헤더 없음 — 세션 쿠키를 함께 보내도 같다(토큰 전용 창구)
curl -s -w ' [%{http_code}]\n' 'http://127.0.0.1:8082/tbox/api/whoami'
{"error":"X-API-Token 헤더가 없습니다"} [401]

# 틀린 토큰
curl -s -w ' [%{http_code}]\n' -H 'X-API-Token: <틀린 값>' 'http://127.0.0.1:8082/tbox/api/whoami'
{"error":"API 토큰이 올바르지 않습니다"} [401]

# 세션 쿠키만 (qa_employee01 로 로그인한 상태) — 헤더가 없으므로 같은 401 이다
curl -s -w ' [%{http_code}]\n' -b jar 'http://127.0.0.1:8082/tbox/api/whoami'
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
| 성공 | `200` `{"folder":{"id":<parent>,"name":…,"access_level":…,"breadcrumb":[{id,name,access_level}…]},"folders":[{"id":<정수>,"name":…,"depth":1~5,"access_level":…,"team_id":…,"team_name":…,"child_count":<n>,"file_count":<n>}]}` — 화면 응답과 **동일**. `folders` 는 **귀속 계정이 읽을 수 있는 자식만**(못 읽는 폴더는 응답에 없다), `file_count` 는 그 안에서 귀속 계정이 볼 수 있는 파일 수 · `child_count` 도 귀속 계정이 볼 수 있는 하위 폴더 수(2026-09-26 디버깅 묶음 S-7 부터 — 그 폴더를 펼쳤을 때 돌아오는 수와 같다) |
| 실패 | `401`(헤더 없음 → 「로그인이 필요합니다」 · 틀린 토큰 → 「API 토큰이 올바르지 않습니다」) · `403 {"error":"권한이 없습니다"}`(`parent` 를 읽을 수 없거나 없는 번호 — 둘을 구분하지 않는다) · `503`(토큰 미설정) |
| 넣지 않는 것 | 폴더를 만든 사람 · 못 읽는 폴더의 존재 · 전체 개수 |

폴더 안의 **파일**은 8장의 검색에 `folder=<id>` 를 붙여 받는다: `GET /api/files?folder=<id>&limit=` (`q` 없이 `folder` 만 보내면 그 폴더 안 목록, `q` 도 보내면 그 폴더 안에서 검색).

```bash
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/tbox/api/folders'            # 루트
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/tbox/api/folders?parent=12'  # 12번 폴더의 자식
curl -s -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/tbox/api/files?folder=12&limit=50'   # 12번 폴더 안 파일(8장 배열)
```

실측 2026-09-16 (검토 포트 8082, 귀속을 검토 계정 `qa_employee01`(Employee·팀 1)로 잠시 두고, `-A "tbox-review/2026-09-16"`). 시험이 만든 폴더는 곧바로 지웠고 파일은 휴지통으로 보냈다.

```bash
# 루트 — 이 계정이 볼 수 있는 루트 폴더가 없던 시점. 같은 계정의 세션 응답과 JSON 문자열까지 동일했다
curl -s -w ' [%{http_code}]\n' -H "X-API-Token: $API_TOKEN" 'http://127.0.0.1:8082/tbox/api/folders'
{"folder":{"id":0,"name":null,"access_level":null,"breadcrumb":[]},"folders":[]} [200]

# qa_admin01 이 만든 admin-only 폴더 S — Employee 귀속의 트리에는 없고, 번호로 직접 열어도 403
curl -s -w ' [%{http_code}]\n' -H "X-API-Token: $API_TOKEN" 'http://127.0.0.1:8082/tbox/api/folders?parent=<S>'
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
  -d '{"name":"2026-09 보고서","access_level":"internal"}' https://changkwon.mso.so/tbox/api/folders
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
  -d '{"name":"2026-09 월간 보고서"}' https://changkwon.mso.so/tbox/api/folders/12
curl -s -w '\n[%{http_code}]\n' -X PATCH -H "X-API-Token: $API_TOKEN" -H 'Content-Type: application/json' \
  -d '{"parent_id":7}' https://changkwon.mso.so/tbox/api/folders/12
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
같은 경로가 화면에서는 설명·등급 변경도 한다. 토큰 요청은 아래의 폴더 이동 또는 12.1의 개인 파일명 변경 중 하나만 받는다.

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
  -d '{"folder_id":12}' https://changkwon.mso.so/tbox/api/files/<id>
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

### 12.1 개인 파일 표시 이름 변경

개인 `read+upload` 또는 `read+upload+delete` 토큰으로 본인이 올린 파일만 변경한다. 공용 토큰과 쿠키 전용 요청은 거부한다. 폴더/설명/등급 변경과 혼합하지 않는다.

```json
{"name":"8월_보고서.xlsx","expected_name":"보고서.xlsx"}
```

성공200은 `{"id":"선택한 파일 UUID","name":"8월_보고서.xlsx"}`다. `expected_name`은 목록의 현재 이름을 그대로 보내며 불일치409 `code:"name_conflict"`다. 같은 이름 재시도는200이고 추가 변경 감사는 없다. 경로 없는 UTF-8 파일명은 최대255자이며 확장자를 유지한다. 빈 이름·앞뒤 공백·제어 문자·경로·위험 이중 확장자는400, 확장자 변경은400 `code:"extension_changed"`다. 형식/이름 검사는400, 개인 토큰/소유권 검사는403, 없는/삭제된 파일은404다. 라우터의 기존 인증·scope 오류는 기존 문자열 `error` 형태를 유지하므로 모든 오류에 `code`가 있다고 가정하지 않는다.

UUID·폴더·등급·팀·원본 바이트·저장 파일명은 그대로다. 검색은 새 이름을, 다운로드는 새 Content-Disposition 파일명을 반환한다. 실제 HTTP 샘플과 상세 계약: `docs/file-rename-contract.md`. `assistant.run/files.rename` 신설은 이 기능의 선행조건이 아니다.

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
| 목록 | `GET /api/me/api-tokens` → `200 {"tokens":[{"id":<정수>,"name":"…","hint":"<앞 6자>","purpose":"…|null","scope":"<정규형 — 21장>","scopes":["<원자>",…],"created_at":"…","expires_at":"…","expires_soon":<true/false>,"last_used_at":"…|null","revoked_at":"…|null"}]}` — **토큰 값은 없다**. `expires_soon` 은 만료가 14일 안인가(화면이 안내 줄을 켜는 값) |
| 발급 | `POST /api/me/api-tokens` JSON **허용 키 다섯** `{"name":"<1~50자>","purpose":"<필수, 500자>","scope":["<원자>",…],"password":"<현재 비밀번호>","otp":"<6자리>"}`(Q:F-10 — `scope` 는 **원자 배열**, 옛 문자열 `"read+upload"` 도 받는다 · 21장) → `201 {"id":<정수>,"name":"…","hint":"…","scope":"<정규형>","scopes":["<원자>",…],"expires_at":"…","token":"<48자>"}`. **`token` 은 이 응답에만 나온다** — 서버는 sha256 만 저장하므로 다시 보여 줄 수 없다. 잃으면 회수하고 새로 발급한다 |
| 고를 수 있는 범위 | **Q:F-10(2026-09-29) 부터 원자 여섯을 골라 묶는다** — `read`(파일 읽기) · `upload`(올리기 — `read` 필요) · `delete`(지우기 — `read`·`upload` 필요) · `community.read`(커뮤니티 검색·읽기) · `community.write`(커뮤니티 글쓰기 — 읽기를 덧붙임) · `session.open`(이 PC 로그인 — 20장). 규칙·판정 표는 **21장**. (옛 표기: `read` · `read+upload` · `read+upload+delete` · `community.write` — 그대로 받는다.) **역할이 허용하는 것만** 고를 수 있다 — 올릴 수 없는 역할(Guest)은 `read` 하나뿐이다. 그 목록은 `GET /api/me` 의 `token_scopes` 이고, 발급 폼을 보일지는 `can_issue_tokens`(= 2단계 인증이 켜졌나)가 정한다 |
| 회수 | `DELETE /api/me/api-tokens/{tid}` → `200 {"ok":true}`. 그 순간부터 그 토큰은 401 「API 토큰이 올바르지 않습니다」. **비밀번호·코드를 묻지 않는다** — 닫는 방향은 자격을 줄이는 일이다 |
| 자동 회수 | (2026-09-26 디버깅 묶음 S-5) **비밀번호를 바꾸면**(본인) · 관리자가 **임시 비밀번호를 재발급**하거나 **2단계 인증을 초기화**하면 그 계정의 살아 있는 토큰이 **전부** 회수된다 — 그 뒤 토큰 요청은 401 「API 토큰이 올바르지 않습니다」. 프로그램에는 새로 발급한 토큰을 다시 심는다. 감사 `api_token.revoke allow password_change|password_reset|mfa_reset`(토큰마다 한 행) |
| 실패 | `400 {"error":"살아 있는 토큰이 상한에 이르렀습니다 — 먼저 하나를 회수하세요"}` — 살아 있는 토큰이 이미 5개(F-68, ARCHITECTURE 5.30) · `400 {"error":"잘못된 요청입니다"}` — 허용 외 키·이름 규칙·용도 없음·**역할 밖 범위나 모르는 범위**(역할 힌트를 주지 않는다), 그리고 **인증 실패 전부**(틀린 비밀번호·틀린 코드·이미 쓴 코드 — 세 사유가 한 문구다, ARCHITECTURE 5.30 예외 목록·R-48) · `403 {"error":"권한이 없습니다"}` — **2단계 인증이 꺼진 계정**(`mfa_not_enabled`, 실패로 세지 않는다) · CSRF 없음 · `404 {"error":"찾을 수 없습니다"}` — 내 토큰이 아닌 tid(그 번호가 있는지도 새지 않는다) · 이미 회수된 토큰은 `400 {"error":"잘못된 요청입니다"}` |
| 넣지 않는 것 | 토큰 원문(발급 응답 한 번 밖에는 어디에도 — 목록·감사·로그) · 해시 · **비밀번호·2단계 코드**(응답·감사·로그 0) |
| 감사 | `api_token.issue allow`(detail `token_id`·`token_name`·**`token_scope`**·`reason_text`·`otp_verified:true`) · 거부 `mfa_not_enabled`·`bad_credentials`·`bad_code`·`code_reused`·`bad_request`·`limit_exceeded` · `api_token.revoke allow` · 경보 **AL-20**(발급 1건 즉시, 근거에 범위) |
| 관리자 창구 | 목록 `GET /api/users/{uid}/api-tokens`(행에 `scope`·`expires_at` 추가) · 회수 `DELETE /api/users/{uid}/api-tokens/{tid}` — 정책 `user.manage`(Admin + 재인증 15분 창). **발급은 없다**(`POST` → 405) |
| 토큰으로는 부를 수 없다 | 이 셋은 **토큰 집합 밖**이다 — `X-API-Token` 으로 부르면 세션이 없어 `401 {"error":"로그인이 필요합니다"}` 다. 토큰이 토큰을 낳지 않는다 |

```bash
# 본인 세션(jar)으로 — CSRF 는 3장. 비밀번호와 지금의 2단계 코드를 **요청마다** 함께 보낸다.
curl -s -b jar -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' \
  -d '{"name":"사무실 PC","purpose":"트레이 프로그램용","scope":"read+upload","password":"<현재 비밀번호>","otp":"123456"}' \
  https://changkwon.mso.so/tbox/api/me/api-tokens

# 목록 · 회수
curl -s -b jar https://changkwon.mso.so/tbox/api/me/api-tokens
curl -s -b jar -X DELETE -H "X-CSRF-Token: $CSRF" https://changkwon.mso.so/tbox/api/me/api-tokens/<tid>
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
curl -s -w '\n[%{http_code}]\n' -X DELETE -H "X-API-Token: $API_TOKEN" https://changkwon.mso.so/tbox/api/files/<id>
curl -s -w '\n[%{http_code}]\n' -X DELETE -H "X-API-Token: $API_TOKEN" 'https://changkwon.mso.so/tbox/api/folders/<번호>?move_trashed=1'
```

실측 2026-09-16 (검토 포트 8082, 검토 계정 `qa_admin01`(발급자·재인증)·`qa_employee01`(대상), `-A "tbox-review/2026-09-16"`, `tests/api_tokens.sh`). 발급받은 토큰은 규칙(S-09-11)대로 어디에도 기록하지 않았다 — 아래 `token` 자리는 모양만이다. 시험이 발급한 토큰은 전부 회수했고 파일은 휴지통, 폴더는 삭제했다.

```bash
# 개인 토큰(qa_employee01)으로 — whoami 가 그 사람을 가리킨다 (F-56 ⑤)
curl -s -H "X-API-Token: $API_TOKEN" http://127.0.0.1:8082/tbox/api/whoami
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

감사 로그(실측): `file.delete allow`·`folder.delete allow` 행에 `detail.auth=api_token`·`token_kind=personal`·`token_id=<번호>` 가 붙었다. 공용 토큰(`.env`, 귀속 관리자 계정 — 아이디는 d74 로 바뀜, 문서에 적지 않음)으로 같은 DELETE 둘을 부른 실측(2026-09-17): `{"error":"이 창구는 개인 토큰으로만 쓸 수 있습니다"} [403]`, 감사 `auth.token deny shared_token_not_permitted` + `token_kind=shared`. 같은 토큰의 `GET /api/files` 는 200 그대로다.

> 토큰은 이 **열** 밖에서는 아무것도 열지 못한다 — 등급 변경·휴지통 복원·계정·감사 창구는 헤더를 읽지도 않는다(0장).

---

## 15. 관리자 창구 — 휴대폰 경보 (F-70, 2026-09-24~25 · 세션)

프로그램이 부르는 창구가 아니라 **관리자가 휴대폰 경보를 켜고 끄는** 화면(`/tbox/alerts-app`)의 창구다(PRD F-70, ARCHITECTURE 5.32, SECURITY R-99).
**X-API-Token 으로는 부를 수 없다**(토큰 창구 집합 밖). 알림을 실제로 보내는 것은 서버의 배치(매분)이고, 이 창구들은 **밖으로 한 번도 나가지 않는다** —
「시험 알림」도 대기표에 한 행을 넣을 뿐이다.

| 항목 | 내용 |
|---|---|
| 인증 | 세션 쿠키 + `X-CSRF-Token`(POST·DELETE). 정책 `role:Admin` — **재인증(15분 창) 관문이 모든 창구에 붙는다**. 창이 닫혔으면 `403 {"error":"관리 기능을 쓰려면 비밀번호를 다시 확인해야 합니다","next":"reauth"}`(화면 요청은 302 `/tbox/reauth?to=alerts`). Admin 이 아니면 `403 {"error":"권한이 없습니다"}` |
| 공개 키 | `GET /api/admin/push/key` → `200 {"public_key":"<base64url 87자 — 65바이트 P-256 점>"}`. 서버에 키가 아직 없으면 `503 {"error":"휴대폰 경보가 아직 준비되지 않았습니다 (서버의 알림 키가 없습니다)"}`. 비밀이 아니다 — 브라우저 `pushManager.subscribe({applicationServerKey})` 에 넣는다 |
| 받기 목록 | `GET /api/admin/push/subscriptions` → `200 {"subscriptions":[{"id":<정수>,"host":"fcm.googleapis.com","device_hint":"Android Chrome","created_at":"…","last_ok_at":"…|null"}],"max_devices":3}` — **내** 살아 있는 받기만. 받기 주소(endpoint)·키는 응답에 없다 |
| 받기 등록 | `POST /api/admin/push/subscriptions` JSON **허용 키 셋만** `{"endpoint":"https://…","keys":{"p256dh":"<base64url 65바이트>","auth":"<base64url 16바이트>"}}` (브라우저 `PushSubscription.toJSON()` 의 `expirationTime` 은 빼고 보낸다) → `201 {"ok":true,"subscription":{…목록 항목 모양…}}`. 같은 주소를 다시 보내면 `200 {"ok":true,"already":true,…}` |
| 등록 검사 | 주소: 1,024자 이하 · https · 포트 없음(443) · 사용자정보·IP 리터럴 없음 · 호스트가 **정확히** `fcm.googleapis.com`(안드로이드 Chrome) · `web.push.apple.com`(아이폰 Safari 홈 화면 앱, iOS 16.4+) · `updates.push.services.mozilla.com`(Firefox) 중 하나(Edge 는 안 된다). 키: p256dh 65바이트 · 첫 바이트 0x04 · P-256 곡선 위의 점, auth 16바이트. 한 사람 3대 |
| 등록 실패 | `400` 문구 — 「받기 정보의 모양이 맞지 않습니다」(허용 밖 키·길이) · 「받기 주소가 올바르지 않습니다」(http·포트·사용자정보) · 「이 브라우저의 푸시 서버는 쓸 수 없습니다 (안드로이드 Chrome · 아이폰 Safari · Firefox 만)」 · 「받기 키가 올바르지 않습니다」 · 「기기는 한 사람 3대까지 등록할 수 있습니다 — 쓰지 않는 기기를 먼저 해지하세요」 · 「이 브라우저의 받기는 다른 관리자 계정에 등록돼 있습니다 — 그 계정에서 해지한 뒤 다시 켜세요」 |
| 해지 | `DELETE /api/admin/push/subscriptions/{num}` → `200 {"ok":true}`. 내 것만 — 남의 번호·없는 번호·이미 해지한 번호는 `404 {"error":"찾을 수 없습니다"}`(같은 문구). 행은 지우지 않는다(해지 기록) |
| 시험 알림 | `POST /api/admin/push/test`(본문 `{}`) → `202 {"ok":true,"queued":true,"subscriptions":<내 기기 수>}` — 1분 안에 내 기기 전부에 시험 알림이 간다. 10분 안에 다시 → `429 {"error":"시험 알림은 10분에 한 번 보낼 수 있습니다","retry_after":<초>}` + `Retry-After` 머리 · 기기가 없으면 `400 {"error":"이 계정에 등록된 기기가 없습니다 — 먼저 「알림 받기 켜기」를 누르세요"}` |
| 앱 파일 | `GET /tbox/alerts-sw.js`(서비스 워커 — `application/javascript; charset=utf-8`, `Cache-Control: no-cache`) · `GET /tbox/alerts-app.webmanifest`(`application/manifest+json`) — 둘 다 같은 정책(Admin + 재인증). 화면은 매니페스트를 `crossorigin="use-credentials"` 로 부른다 |
| 준비 안 됨 | 서버 쪽 반영(표 둘을 만드는 마이그레이션 — `apply-f70-host`) 전이면 받기 목록·등록·해지·시험 알림이 `503 {"error":"휴대폰 경보가 아직 준비되지 않았습니다 (서버 쪽 반영 전 — 관리자 작업이 끝나면 켤 수 있습니다)"}`(500 이 아니다). 공개 키 창구는 위 문구. 화면 `/tbox/alerts-app` 은 `200` 그대로 「준비 안 됨」 안내를 보이고 켜기·시험 단추를 막는다 |
| 알림 내용 | 휴대폰에 가는 것은 규칙 번호·짧은 이름·출처(사람/시스템/Claude)·시각·개수뿐이다(계정·IP·파일 이름·명령 없음). 누르면 언제나 `/tbox/admin` 이 열린다 |
| 감사 | `alert.push_subscribe allow|deny`(detail `{host, device_hint}` — 주소·키 없음) · `alert.push_unsubscribe` · `alert.push_test` · 배치의 `alert.push_send`·`alert.push_revoke`(ARCHITECTURE 7.4) |

## 16. 지문·얼굴 로그인 — 패스키 (T:F-72, 2026-09-27 · 웹 화면 전용 · 세션)

프로그램이 부르는 창구가 아니라 **로그인 화면·「내 계정」·재인증 화면**의 창구다(PRD F-72, ARCHITECTURE 5.35, SECURITY R-109).
**X-API-Token 으로는 부를 수 없다**(토큰 창구 집합 불변). 브라우저의 `navigator.credentials`(WebAuthn)가 만든 값을 그대로 옮긴다 — 값의 이진 칸은 전부
base64url(패딩 없음 · 엄격). 열쇠 id·공개 키·서명·챌린지는 어느 응답·감사에도 다시 나오지 않는다(목록은 행 번호·이름·날짜·상태·기기 요약·동기화 표시만).

| 항목 | 내용 |
|---|---|
| 인증 | 로그인 둘(`/api/login/passkey/options` · `/api/login/passkey`)은 **로그인 전**(public · JSON 만 · nginx 속도 제한 30r/m burst 10). 나머지는 세션 쿠키 + `X-CSRF-Token`(POST·DELETE). 등록 옵션·재인증 둘도 같은 속도 제한 |
| 설정 | RP ID = `APP_URL` 의 호스트(`changkwon.mso.so`) · origin = `https://changkwon.mso.so` 정확 일치. `APP_URL` 이 IP 거나 표가 없으면 모든 창구 `503 {"error":"지금은 지문·얼굴 로그인을 쓸 수 없습니다. 비밀번호로 로그인하세요."}` 이고 `/api/me` `passkey_available:false` |
| 로그인 옵션 | `POST /api/login/passkey/options` 본문 `{}` → `200 {"publicKey":{"challenge","rpId","timeout":300000,"userVerification":"required","allowCredentials":[]}}` + 쿠키 `__Host-tbox_pk`(HttpOnly · Secure · SameSite=Strict · Max-Age 600 — 챌린지가 이 쿠키에 묶인다). **아이디를 받지 않고 열쇠 목록을 주지 않는다**. 살아 있는 로그인 챌린지가 5,000 을 넘으면 `503 {"error":"잠시 뒤 다시 시도하세요"}` |
| 로그인 | `POST /api/login/passkey` 허용 키만 `{id, rawId, type:"public-key", clientDataJSON, authenticatorData, signature, userHandle, authenticatorAttachment?}`(`id = rawId` · attachment 는 `platform` 이나 null) → `200 {"ok":true,"next":…,"mfa_required":…,"mfa_recommended":…}` — **`/api/login` 과 같은 모양** · 새 세션 쿠키 · 로그인 전 쿠키 지움. 코드 칸 없음(2단계 충족 — F-52 ⑦) · 재인증 창은 열리지 않는다 |
| 로그인 실패 | 전부 `401 {"error":"아이디 또는 비밀번호가 올바르지 않습니다"}`(없는 열쇠·서명 틀림·주소·챌린지·서명 횟수·비활성·Guest 만료·비밀번호 변경 먼저 — **본문이 바이트까지 같다**. 사유는 감사에만) · 잠긴 계정 `423` · **꺼진 열쇠**(해제·90일)만 `401 {"error":"이 휴대폰의 지문·얼굴 로그인은 꺼져 있습니다(해제했거나 90일 동안 쓰지 않음). …","passkey_off":true}` · 모양이 틀리면 `400 {"error":"잘못된 요청입니다"}`(실패로 세지 않는다) |
| 내 목록 | `GET /api/me/passkeys` → `200 {"available":true,"passkeys":[{"id":<행 번호>,"name","device_hint","created_at","last_used_at"|null,"state":"active"|"stale","synced":<bool>}],"max":5}` — 해제한 열쇠는 없다 · `stale` = 90일 안 써서 꺼짐 |
| 등록 옵션 | `POST /api/me/passkeys/options` `{"name":"1~40자","password":"…","otp":"6자리"(2단계 켠 계정만)}` → 비밀번호·코드를 **먼저** 확인(틀리면 `400` + 로그인과 같은 실패 카운터 — 5회면 잠금·세션 전부 끊김) → `200 {"name","publicKey":{challenge, rp:{id,name:"Qaredi"}, user:{id,name,displayName}, pubKeyCredParams:[{alg:-7}], timeout:120000, attestation:"none", authenticatorSelection:{authenticatorAttachment:"platform", residentKey:"required", requireResidentKey:true, userVerification:"required"}, excludeCredentials:[내 살아 있는 열쇠]}}`. 5개면 `400 {"error":"지문·얼굴 로그인 기기는 한 사람 5개까지입니다 — 먼저 하나를 해제하세요"}` |
| 등록 | `POST /api/me/passkeys` `{name, id, rawId, type, clientDataJSON, attestationObject, transports?, authenticatorAttachment?}` → `201 {"ok":true,"passkey":{"id","name","device_hint","synced"}}`. 거부는 `400` — 「이 휴대폰을 등록하지 못했습니다. 창을 닫고 처음부터 다시 해 보세요」(챌린지·주소·증명 형식 `none` 밖·UV 없음 등) · 「이 휴대폰은 이미 등록되어 있습니다」 · 5개 문구 |
| 해제 | `DELETE /api/me/passkeys/{num}` → `200 {"ok":true,"remaining":<남은 수>}` · 남의 번호·없는 번호·이미 해제 → `404 {"error":"찾을 수 없습니다"}`(같은 본문) |
| 재인증 | `POST /api/me/reauth/passkey/options` `{}` → `200 {"publicKey":{challenge, rpId, timeout:120000, userVerification:"required", allowCredentials:[지금 사람의 살아 있는 열쇠]}}` · `POST /api/me/reauth/passkey` (로그인과 같은 키) → `200 {"ok":true,"reauth_until":"…"}`(지금 세션만 15분) · 남의 열쇠·틀린 서명 → `400`, 실패는 로그인 뒤 창구 규칙(5회면 잠금·세션 끊김) |
| 홈 화면 앱 | `GET /tbox/app.webmanifest`(public · 데이터 0) → `application/manifest+json` — 이름 「Qaredi」 · 시작 `/community/` · 범위 `/` · standalone · 아이콘 192·512 · 바로가기 없음 · 서비스 워커 없음 |
| 자동 해제 | 본인 비밀번호 변경(`POST /api/me/password` 응답에 `passkeys_revoked` 수) · 관리자 임시 비밀번호 재발급 · 2단계 초기화 · 역할 변경 · 비활성화 → 그 사람 열쇠 전부 |
| 감사 | `auth.login allow passkey`·`deny passkey_*`·`forced_step` · `auth.reauth allow|deny passkey*` · `auth.passkey_register allow|deny` · `auth.passkey_revoke allow|deny` (ARCHITECTURE 7.4 · detail `credential_no`·`credential_label`·`device_hint`·`backed_up`) · 경보 AL-23 |
| 넣지 않는 것 | 로그인 전 사람에게 「이 아이디에 열쇠가 있다」(아이디를 받지 않는다) · 열쇠 id 원문·공개 키·서명·챌린지·clientDataJSON·attestationObject(응답·감사·로그 0) |

실제로 받은 응답(2026-09-27 임시 인스턴스 `http://localhost:8301` · 검토 계정 `claude_employee02` · 소프트웨어 인증기 — 챌린지·id 는 `…` 로 가림):

```text
POST /tbox/api/login/passkey/options {}      → 200 {"publicKey":{"challenge":"…","rpId":"localhost","timeout":300000,"userVerification":"required","allowCredentials":[]}}
GET  /tbox/api/me/passkeys                   → 200 {"available":true,"passkeys":[],"max":5}
POST /tbox/api/me/passkeys/options           → 200 {"name":"내 폴드","publicKey":{"challenge":"…","rp":{"id":"localhost","name":"Qaredi"},"user":{"id":"…","name":"claude_employee02","displayName":"claude_employee02"},"pubKeyCredParams":[{"type":"public-key","alg":-7}],"timeout":120000,"attestation":"none","authenticatorSelection":{"authenticatorAttachment":"platform","residentKey":"required","requireResidentKey":true,"userVerification":"required"},"excludeCredentials":[]}}
POST /tbox/api/me/passkeys                   → 201 {"ok":true,"passkey":{"id":1,"name":"내 폴드","device_hint":"기타 브라우저","synced":true}}
GET  /tbox/api/me/passkeys                   → 200 {"available":true,"passkeys":[{"id":1,"name":"내 폴드","device_hint":"기타 브라우저","created_at":"…","last_used_at":null,"state":"active","synced":true}],"max":5}
POST /tbox/api/me/passkeys/options (틀린 비밀번호) → 400 {"error":"잘못된 요청입니다"}
POST /tbox/api/login/passkey (없는 열쇠)     → 401 {"error":"아이디 또는 비밀번호가 올바르지 않습니다"}
POST /tbox/api/login/passkey (맞는 서명)     → 200 {"ok":true,"next":null,"mfa_required":false,"mfa_recommended":true}
POST /tbox/api/login/passkey {}              → 400 {"error":"잘못된 요청입니다"}
GET  /tbox/api/me (두 값만)                  → 200 {"passkey_available":true,"passkeys_active":1}
DELETE /tbox/api/me/passkeys/999999          → 404 {"error":"찾을 수 없습니다"}
DELETE /tbox/api/me/passkeys/1               → 200 {"ok":true,"remaining":0}
```

## 17. 관리자 창구 — 로그인 아이디 바꾸기 — `PATCH /api/users/{uid}` 의 `username` (d74, 2026-09-27 · 세션)

프로그램이 부르는 창구가 아니라 **계정 관리 화면(「관리」 → 「로그인 아이디 바꾸기」)**이 부르는 관리자 창구다(PRD F-10 d74 · SECURITY R-06 완화 · 구현 기록 `docs/d74-username-change-implementation.md`).
기존 계정 수정 창구에 칸 하나를 더한 것이고 새 라우트는 없다. **X-API-Token 으로는 부를 수 없다**(토큰 창구 집합 밖).

| 항목 | 내용 |
|---|---|
| 인증 | 세션 쿠키 + `X-CSRF-Token`. 정책 `user.manage`(Admin) — **재인증(15분 창) 관문**. 창이 닫혔으면 `403 {"error":"관리 기능을 쓰려면 비밀번호를 다시 확인해야 합니다","next":"reauth"}` · Admin 이 아니면 `403 {"error":"권한이 없습니다"}` · CSRF 가 없거나 남의 것이면 `403` |
| 요청 | JSON `{"username":"<새 아이디>","reason_text":"<사유 1~500자>"}` — `display_name` 은 함께 보내도 된다. `role`·`team_id`·`is_active`·`expires_at` 과 **섞으면 400**. 자기 자신은 바꿀 수 없다(400 「자기 자신에게는 쓸 수 없습니다 — 내 계정 화면을 이용하세요」) |
| 규칙 | 계정 만들기(`POST /api/users`)와 **같은 함수**: 소문자·숫자·`_ . -` 3~64자(대문자는 형식 오류) · 이미 있는 아이디 불가 · `claude_`·`qa_` 접두 불가. 더해서 — 지금 이름이 검토 계정이면 바꿀 수 없다 · 지금 이름이 공용 프로그램 토큰(`.env` `API_TOKEN_USER`)의 귀속 아이디면(공용 토큰이 켜져 있을 때) 409 — 귀속 줄을 먼저 새 아이디로 바꾸면(결정 d76 ② · 도우미 `apply-d74-rename-conf --env`) 통과하고 공용 토큰은 바꾼 계정으로 다시 이어진다 · 지금과 같은 이름이면 「바뀐 것 없음」 400 |
| 성공 | `200 {"id":<번호>,"username":"<새 아이디>","display_name":…,"role":…,"team_id":…,"is_active":…,"expires_at":…,"sessions_revoked":0}` — **세션을 끊지 않는다**(그 사람의 로그인·개인 토큰·2단계 인증·패스키는 계정 번호에 묶여 그대로). 다음 로그인부터 새 아이디, 옛 아이디 로그인은 없는 아이디와 같은 401 |
| 실패 | `400 {"error":"아이디 형식이 맞지 않습니다 — 소문자·숫자·_ . - 로 3~64자입니다"}` · `400 {"error":"이미 있는 아이디입니다"}` · `400 {"error":"검토·시험 계정 전용 접두(qa_·claude_)로 시작하는 아이디는 만들 수 없습니다 — 사람 계정은 다른 이름으로 만드세요"}` · `400 {"error":"검토·시험 계정의 아이디는 바꿀 수 없습니다 — 검토 요청과 사람 행동을 가르는 표식입니다"}` · `409 {"error":"이 계정은 공용 프로그램 토큰에 묶여 있어 아이디를 바꾸면 그 토큰이 끊깁니다 — 공용 토큰을 먼저 정리하세요"}` · 사유 없음·섞은 칸·같은 이름 `400 {"error":"잘못된 요청입니다"}` · 없는 번호 `404 {"error":"찾을 수 없습니다"}` |
| 감사 | 성공 `user.update allow` — `detail.changed_fields = ["username"]`, 대상 이름(`target_name`)은 **바꾸기 전** 아이디. **새 아이디는 감사 어디에도 없다**(문장·상세). 거부 `user.update deny bad_request·already_exists·reserved_prefix·review_account·shared_token_user·self_change` — 대상은 바꾸려는 계정이고 **시도한 새 아이디는 남기지 않는다** |
| 넣지 않는 것 | 응답·오류 문구에 요청한 아이디를 되찍지 않는다(성공 응답의 `username` 만 — 요청한 관리자에게) · 공용 토큰 값은 길이만 본다 |

실측(2026-09-27, 임시 인스턴스 `tests/d74_username.sh` — 대상은 시험이 임시 DB 에 만든 사람 계정, 이름 자리는 `<아이디>` 로 가림):

```text
재인증 없음      → 403 {"error":"관리 기능을 쓰려면 비밀번호를 다시 확인해야 합니다","next":"reauth"}
형식(대문자)     → 400 {"error":"아이디 형식이 맞지 않습니다 — 소문자·숫자·_ . - 로 3~64자입니다"}
이미 있음        → 400 {"error":"이미 있는 아이디입니다"}
검토 접두        → 400 {"error":"검토·시험 계정 전용 접두(qa_·claude_)로 시작하는 아이디는 만들 수 없습니다 — 사람 계정은 다른 이름으로 만드세요"}
검토 계정 대상   → 400 {"error":"검토·시험 계정의 아이디는 바꿀 수 없습니다 — 검토 요청과 사람 행동을 가르는 표식입니다"}
공용 토큰 귀속   → 409 {"error":"이 계정은 공용 프로그램 토큰에 묶여 있어 아이디를 바꾸면 그 토큰이 끊깁니다 — 공용 토큰을 먼저 정리하세요"}
바꾸기 성공      → 200 {"id":35,"username":"<아이디>","display_name":null,"role":"Employee","team_id":1,"is_active":true,"expires_at":null,"sessions_revoked":0}
```

```bash
# 요청 모양(관리자 세션 · 재인증 15분 창 · CSRF). 새 아이디·사유는 본문으로만 — 명령 인자에 비밀·새 아이디를 싣지 않는다(R-62)
curl -s -w '\n[%{http_code}]\n' -X PATCH -b "$JAR" -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' \
  --data-binary @body.json https://changkwon.mso.so/tbox/api/users/<번호>     # body.json = {"username":"…","reason_text":"…"}
```

> 공용 토큰 귀속 계정은 아이디 바꾸기 전에 공용 토큰 정리(F-59 ⑥) — 사용자 결정 **d76 ②**(2026-09-27): 공용 토큰은 두고 **귀속 줄만 새 아이디로**. 순서 ① 도우미 `--env`(`.env` 귀속 줄 — 공용 토큰 잠시 503) → ② 이 창구로 아이디 바꾸기 → ③ 공용 토큰 다시 200 → ④ 도우미 `--seed`(결정 d77 ②). `.env` 는 요청마다 읽혀 reload 가 필요 없다. 서버 파일이라 취합 세션이 root 로 한다.

## 18. 화면 설정 — 고대비 화면 — `PATCH /api/me/settings` (커뮤니티 7-D3, 2026-09-27 · 웹 화면 전용 · 세션)

프로그램이 부르는 창구가 아니라 **「내 계정」 「화면」 패널의 「고대비 화면」 체크**가 부르는 본인 창구다(커뮤니티 지시서 `stage7d-palette-contrast-mention-directive.md` 2장 · DESIGN.md 2.16 · ARCHITECTURE 7.4 `me.settings|allow`).
**X-API-Token 으로는 부를 수 없다**(토큰 창구 집합 밖 — 토큰만 보내면 세션이 없어 401). 대상은 언제나 **세션의 본인**이고 본문에 대상 계정 칸이 없다.

| 항목 | 내용 |
|---|---|
| 인증 | 세션 쿠키 + `X-CSRF-Token`. 정책 `authenticated`(다른 `/api/me/*` 와 같은 자리). 최초 로그인 강제 절차 중이면 `403 {"error":"비밀번호를 먼저 변경해야 합니다","next":"password_change"}` |
| 요청 | JSON `{"high_contrast": true}` 또는 `{"high_contrast": false}` — **이 한 칸뿐**(JSON 불린만 · `Content-Type: application/json`) |
| 성공 | `200 {"high_contrast":<저장된 값>}` — 곧바로 `GET /api/me` 의 `high_contrast`(3장)와 셸·런처의 `<html data-contrast="high">` 가 이 값을 따른다. 성공 알림은 없다(화면이 바뀌는 것이 알림) |
| 실패 | 세션 없음 `401 {"error":"로그인이 필요합니다"}` · CSRF 없음·틀림 `403 {"error":"권한이 없습니다"}` · 다른 키·둘 이상 키·문자열 `"true"`·`1`·`null`·배열·빈 본문·JSON 아님·JSON 머리 아님 `400 {"error":"잘못된 요청입니다"}`(값 그대로 · 기록 0) · GET 등 다른 메서드 `405` |
| 감사 | 성공 한 행 `me.settings allow` — 대상 = 본인 계정 · `detail.high_contrast`(바꾼 뒤 값). 형식이 틀린 400 은 남기지 않는다(바뀐 것 0) |
| 넣지 않는 것 | 다른 계정을 가리키는 칸(보내면 모르는 키라 400) · 쿠키·브라우저 저장소(고대비 판단은 계정 칸으로만) |

```bash
# 세션 잼 + CSRF(3장 csrf_token). 머리 값은 표준입력으로 — 명령 인자에 싣지 않는다(R-62)
printf 'X-CSRF-Token: %s' "$CSRF" | curl -s -w '\n[%{http_code}]\n' -X PATCH -b jar -H @- -H 'Content-Type: application/json' \
  --data-binary '{"high_contrast":true}' https://changkwon.mso.so/tbox/api/me/settings
```

실제로 받은 응답(2026-09-28 모의 인스턴스 `http://127.0.0.1:8108` · 7-D3 검토 반영 판 · 검토 계정 `claude_employee01` · 임시 DB):

```text
PATCH /tbox/api/me/settings (세션 없음)                         → 401 {"error":"로그인이 필요합니다"}
PATCH … (CSRF 머리 없음)                                        → 403 {"error":"권한이 없습니다"}
PATCH … {"high_contrast":true}                                  → 200 {"high_contrast":true}
GET  /tbox/api/me (그 값만)                                     → 200 {"high_contrast": true}
PATCH … {"high_contrast":"true"}                                → 400 {"error":"잘못된 요청입니다"}
PATCH … {}                                                      → 400 {"error":"잘못된 요청입니다"}
PATCH … {"high_contrast":true,"username":"claude_employee02"}   → 400 {"error":"잘못된 요청입니다"}
GET  /tbox/api/me/settings                                      → 405 {"error":"허용되지 않는 메서드입니다"}
PATCH … {"high_contrast":false}                                 → 200 {"high_contrast":false}
```

> 개인 프로그램 토큰(범위 `read`)으로 `PATCH` → `401 {"error":"로그인이 필요합니다"}`(같은 토큰의 `GET /api/whoami` 는 200) — 수락 시험 `tests/community/stage7d3.sh` C:T-210.

## 19. 프로그램용 커뮤니티 글쓰기 — `GET /community/api/me/space` · `POST /community/api/pages` · `GET /community/api/pages/{id}` (Q:F-8, 2026-09-28 · 개인 토큰만)

「주제를 말하면 AI 가 커뮤니티에 글을 써 준다」(사이트 PRD Q:F-8 · t-box PRD F-74 · ARCHITECTURE 4.8)의 서버 창구다. PC 의 MCP 서버(`clients/mcp/`)가 이 **셋만** 부른다.
화면이 쓰는 **같은 창구**이고, `X-API-Token` 헤더가 왔을 때만 토큰으로 판정한다(헤더가 없으면 지금까지와 똑같은 세션 창구다).
만든 페이지는 언제나 **내 개인 공간 · 비공개 · 부모 없음**이다 — 공개·게시는 사람이 화면에서 한다.

| 항목 | 내용 |
|---|---|
| 인증 | 헤더 **`X-API-Token: <개인 토큰>`** — 범위 **`community.write`** 인 개인 토큰만(13장에서 발급 · 180일). `X-CSRF-Token` 은 필요 없다. 공용 토큰(`.env`)은 403 |
| 범위 | `community.write` 는 **독립 값**이다 — 이 셋과 `GET /tbox/api/whoami`(10장 — 토큰 확인용)만 연다. 파일 창구(7~9·11·12·14장)는 403 `이 토큰의 범위에 없는 창구입니다 …` 이고, 거꾸로 `read`·`read+upload`·`read+upload+delete` 토큰은 이 셋에서 403 이다 |
| 내 공간 | `GET /community/api/me/space` → `200 {"space":{"id":<정수>,"name":"…","kind":"personal","created_at":"…","page_count":<정수>},"created":<true/false>,"start_page":{…}|null}` — 없으면 그 자리에서 만든다(멱등 · 세션과 같은 동작) |
| 만들기 | `POST /community/api/pages` JSON **`{"title":"<1~200자>","doc":<블록 문서>}` 둘만**(둘 다 선택 — 없으면 「제목 없는 페이지」 · 빈 문단 하나). 블록 문서 모양은 `public/assets/community/doc-schema.json`(검증은 서버 `Doc::validate` 한 곳) → `201 {"page":{"id":"<uuid>","title":"…","visibility":"private","space":{"id":…,"kind":"personal","name":"…"},"parent_id":null,"url":"/community/p/<uuid>",…}}` — 응답 모양은 세션과 같다. 페이지 주소는 `{기본 주소}/community/p/{id}` |
| 읽기 | `GET /community/api/pages/{id}` → `200 {"page":{…,"doc":<블록 문서>}, …}` — 세션과 같은 판정(`CommunityAuthz::decidePageRead`) |
| 실패 | `400 {"error":"프로그램 토큰으로는 제목과 본문만 보냅니다 — 새 페이지는 내 개인 공간에 비공개로 만들어집니다","reason":"token_scope_fields"}` — `parent_id`·`space_id`·`database`·`properties`·`template_id` 가 **있으면**(null 이어도) · `400 {"error":"잘못된 요청입니다","errors":[{"path":"…","reason":"…"}]}` — 블록 문서 검증 실패(어느 자리가 왜) · `400 {"error":"잘못된 요청입니다"}` — 그 밖의 키(`visibility` 등)·제목 규칙 · `409 {"error":"개인 공간을 먼저 엽니다"}` — 개인 공간이 아직 없음(`GET …/me/space` 를 먼저 부르면 생긴다) · `401 {"error":"API 토큰이 올바르지 않습니다"}` — 틀린·회수된 토큰(세션으로 되돌아가지 않는다) · `401 {"error":"만료된 API 토큰입니다 — 내 계정에서 새로 발급하세요"}` · `403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}` — 범위가 `community.write` 가 아님 · `403 {"error":"이 창구는 개인 토큰으로만 쓸 수 있습니다"}` — 공용 토큰 · `403 {"error":"권한이 없습니다"}` — 남의 비공개 페이지 읽기(세션과 같은 답) · `404` — 없는 페이지 |
| 감사 | 이 요청의 모든 행에 `detail.auth=api_token`·`token_kind=personal`·`token_id`·`token_scope=community.write` — 만들기는 `page.create allow`(행위자 = 토큰 주인), 범위 거부는 `auth.token deny scope_not_permitted` |
| 열지 않는 것 | **목록·검색은 열지 않는다**(글을 **찾을** 길이 없다 — `GET /api/pages/{id}` 는 **id 를 아는 글**에 한해 세션과 같은 판정으로 읽힌다: 내 글 · 사내 공개 · 팀 · 공유받은 글 · 관리자는 `admin_all`. 독립 검토 RV-QF8-4 실측) — 목록(`GET /community/api/pages`)·검색·고치기·지우기·옮기기·공개 범위·공유·댓글·게시·템플릿·DB 창구는 토큰 헤더를 **읽지 않는다**(보내면 세션이 없어 `401 {"error":"로그인이 필요합니다"}`). 고치는 창구가 없으니 고친 글은 새 페이지로 다시 쓴다 |

```bash
# 개인 토큰(범위 community.write)을 환경 변수에만 두고, 머리 값은 표준입력으로 (명령 인자에 싣지 않는다 — R-62)
printf 'X-API-Token: %s' "$QAREDI_TOKEN" | curl -s -w '\n[%{http_code}]\n' -H @- https://changkwon.mso.so/community/api/me/space
printf 'X-API-Token: %s' "$QAREDI_TOKEN" | curl -s -w '\n[%{http_code}]\n' -H @- -H 'Content-Type: application/json' \
  --data-binary '{"title":"AI 가 쓴 글","doc":{"schema":1,"blocks":[{"id":"<uuid4>","type":"paragraph","text":[{"t":"본문","m":{}}]}]}}' \
  https://changkwon.mso.so/community/api/pages
```

실제로 받은 응답(2026-09-28 임시 인스턴스 `http://127.0.0.1:8401` · 임시 DB `tbox_qf8_8401_test` · 검토 계정 `claude_employee01` · `-A "tbox-review/2026-09-28"` · `tests/qf8_token_write.sh`). 토큰 값은 규칙(S-09-11)대로 어디에도 적지 않았다 — 시험이 만든 토큰은 끝에 회수했다.

```text
GET  /community/api/me/space   (community.write 토큰)   → 200 {"space":{"id":5,"name":"claude_employee01 의 공간","kind":"personal","created_at":"2026-09-28 09:38:40.0161+00","page_count":2},"created":false,"start_page":null}
POST /community/api/pages {title, doc(제목·문단 둘·목록 둘)}  → 201 {"page":{"id":"0e2d2cab-7e6c-48ed-9da6-9d5495c4cfba","title":"QF8 토큰 글","doc_version":1,"block_count":5,"visibility":"private","space":{"id":5,"kind":"personal","name":"claude_employee01 의 공간"},"parent_id":null,"position":2,"created_at":"2026-09-28 22:58:02.363823+00","updated_at":"2026-09-28 22:58:02.363823+00","url":"/community/p/0e2d2cab-7e6c-48ed-9da6-9d5495c4cfba","database":null,"values":null}}
GET  /community/api/pages/{그 id}                         → 200 (page.id 같음 · doc.blocks 5)
GET  /community/api/pages/{claude_employee02 의 비공개 글} → 403 {"error":"권한이 없습니다"}   (같은 계정 세션도 403 — 같은 답)
POST … {…, "space_id":null}  (parent_id·template_id·database·properties 도 각각 · 값이 있어도 같다)
                                                          → 400 {"error":"프로그램 토큰으로는 제목과 본문만 보냅니다 — 새 페이지는 내 개인 공간에 비공개로 만들어집니다","reason":"token_scope_fields"}
POST … {…, "visibility":"team"}                          → 400 {"error":"잘못된 요청입니다"}
POST /community/api/pages   (read 토큰)                   → 403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}
POST /community/api/pages   (공용 .env 토큰)              → 403 {"error":"이 창구는 개인 토큰으로만 쓸 수 있습니다"}
GET  /community/api/me/space (틀린 값 — 세션 쿠키를 함께 보내도) → 401 {"error":"API 토큰이 올바르지 않습니다"}
GET  /community/api/pages/{id} (만료된 토큰)              → 401 {"error":"만료된 API 토큰입니다 — 내 계정에서 새로 발급하세요"}
GET  /community/api/pages · /api/search · /api/spaces · PATCH·DELETE /api/pages/{id} · POST /api/pages/{id}/comments (community.write 토큰)
                                                          → 401 {"error":"로그인이 필요합니다"}   (헤더를 읽지 않는다 — 감사에 토큰 행 0)
POST /tbox/api/upload · GET /tbox/api/files (community.write 토큰) → 403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}
GET  /tbox/api/whoami        (community.write 토큰)       → 200 {"username":"claude_employee01", …}
(회수 뒤) GET /community/api/pages/{id}                   → 401 {"error":"API 토큰이 올바르지 않습니다"}
```

감사 로그(임시 DB 실측): `page.create allow` 한 행 — actor `claude_employee01` · detail `auth=api_token`·`token_kind=personal`·`token_id=<발급 번호>`·`token_scope=community.write`·`route=POST /community/api/pages`. 범위 거부는 `auth.token deny scope_not_permitted`(detail `token_scope=read`·`auth=api_token`).

## 20. 등록 PC 에서 바로 로그인 — `POST /api/me/session-link` → `GET /session/open?code=…` (Q:F-9, 2026-09-29 · 「이 PC 로그인 전용」 토큰만)

AI 비서(tbox-buddy)의 Ctrl+B 「t-box 열기」가 **로그인 화면 없이** 로그인된 화면을 열게 하는 창구다(사이트 PRD Q:F-9 · t-box PRD F-75 · ARCHITECTURE 4.9 · SECURITY R-113).
세 걸음이다: ① 사람이 「내 계정 → 프로그램 토큰」에서 범위 **`session.open`**(「이 PC 로그인 전용」) 토큰을 발급한다 — 그 순간의 접속 IP 에 묶인다(13장 창구 그대로 · 비밀번호+2단계 코드) ② 비서가 그 토큰으로 **30초 · 1회용** 로그인 링크를 받는다 ③ 비서가 받은 주소를 **그대로** 브라우저로 열고 버린다 — 브라우저가 연 순간 세션이 생긴다.
그 세션은 **토큰 출신**(`origin=token`)이라 유휴 10분이고, 민감한 일(삭제·공유 링크·토큰 발급/회수·패스키 등록/해제·비밀번호·2단계·관리·커뮤니티 지우기/옮기기/공개 범위/공유/인계)은 비밀번호를 다시 확인해야 한다(`403 {"next":"reauth"}` — 화면이 재인증 창을 띄운다).

| 항목 | 내용 |
|---|---|
| 발급(①) | 13장 `POST /api/me/api-tokens` 에 `"scope":"session.open"` — 고를 수 있는 사람은 **2단계 인증이 켜진 비Guest** 뿐(`GET /api/me` 의 `token_scopes` 끝에 있을 때만 · 아니면 400). 응답에 두 칸이 더 온다: `"bound_ip":"<서버가 본 공인 IP>"` · `"revoked_previous":<n>` — **한 사람 1대**라 앞의 로그인 전용 토큰은 전부 회수된다(그 토큰으로 연 세션도 끝난다). 접속 IP 를 알 수 없으면 `400 {"error":"접속 IP 를 알 수 없어 발급하지 않습니다"}`(비밀번호·코드를 확인하기 전 — 코드를 태우지 않는다). 목록(13장 GET)의 줄마다 `"bound_ip"`(다른 범위는 `null`) |
| 인증(②) | 헤더 **`X-API-Token: <로그인 전용 토큰>`** — **토큰 전용**(헤더가 없으면 세션 쿠키가 있어도 401) · **개인 토큰만**(공용 403) · 범위에 `session.open` 원자가 없으면 403(Q:F-10 — 다른 원자와 묶인 토큰도 된다 · 21장). `X-CSRF-Token` 필요 없음 · 본문 없음 |
| 링크(②) | `POST /tbox/api/me/session-link` → **`201 {"url":"<APP_URL>/tbox/session/open?code=<43자>","expires_in":30}`** — 서버가 본 지금 IP 가 그 토큰의 묶인 IP 일 때만. 주소의 호스트는 서버 설정(`APP_URL`) 한 값이다(요청 머리로 만들지 않는다). 코드는 서버에 **sha256 만** 남는다 |
| 교환(③) | 브라우저가 `GET /tbox/session/open?code=…` → **`302 /tbox/`** + 세션 쿠키. `&to=<같은 출처 경로>` 가 있으면 로그인 화면의 「보던 곳으로」와 **같은 허용 목록**(`/tbox/` · `/tbox/settings` · `/tbox/activity` · `/community/…`)일 때만 그리로, 아니면 `/tbox/`. 실패는 전부 **`302 /tbox/login`**(사유를 주소에 싣지 않는다 — 감사에만): 이미 쓴 링크 · 30초 지남 · 토큰 회수/만료 · 링크를 받은 IP 와 다른 IP · 계정 비활성/만료/절차 중/2단계 꺼짐 · 모양이 틀린·없는 코드 |
| 실패(②) | `401 {"error":"X-API-Token 헤더가 없습니다"}` · `401 {"error":"API 토큰이 올바르지 않습니다"}`(틀림·**회수** — 1대 규칙으로 회수된 앞 토큰도) · `401 {"error":"만료된 API 토큰입니다 — 내 계정에서 새로 발급하세요"}` · `403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}`(범위가 session.open 아님) · **`403 {"error":"이 토큰이 묶인 IP 가 아닌 곳에서 요청했습니다 — 이 PC 를 다시 등록하세요(내 계정 → 프로그램 토큰)","code":"ip_mismatch"}`** — 비서는 `code` 로 가른다 · `403 {"error":"이 창구는 개인 토큰으로만 쓸 수 있습니다"}`(공용) · `403 {"error":"권한이 없습니다"}` — 계정 비활성·만료·**절차 중**(Auth::requireApiToken 의 `user_disabled` — 503 이 아니다) 또는 그 사이 2단계를 끔 · **`429 {"error":"로그인 링크를 너무 자주 요청했습니다 — 잠시 뒤 다시 시도하세요","code":"rate_limited","retry_after":60}`** — 한 토큰 1분 5회 · `503 {"error":"서버 설정 문제로 로그인 링크를 만들 수 없습니다"}` — `APP_URL` 이 `scheme://host[:port]` 모양이 아님 |
| 검토 반영 | **이미 로그인된 브라우저**가 링크를 열면 갈아타지 않는다 — 링크는 쓰이지 않고(30초 뒤 만료) `302 /tbox/`(지금 세션 그대로 · 감사 `session.open deny already_logged_in`) · **틀린 IP 시도는 링크를 소비하지 않는다** · 분당 5회는 받은 링크와 IP 불일치 거부를 함께 센다(넘으면 IP 가 맞아도 429) · 2단계를 끄면 열린 토큰 세션도 401 · 로그인 전용 발급이 겹치면 `409 {"error":"다른 발급과 겹쳤습니다 — 잠시 뒤 다시 발급하세요"}`(한 번 다시 시도한 뒤) |
| 이 토큰으로 열리는 것 | `session.open` **한 원자만** 든 토큰은 이 창구와 `GET /tbox/api/whoami`(10장 — 토큰 확인용) **둘뿐**이다. 파일(7~9·11·12·14장)·커뮤니티(19장) 창구는 전부 `403 … 범위에 없는 창구 …`. **Q:F-10** — 다른 원자와 묶으면 그 원자의 창구도 열린다. 대신 **묶인 IP 밖에서는 그 토큰의 모든 창구가 `401 {"error":"이 토큰이 묶인 IP 가 아닌 곳에서 요청했습니다 — 이 PC 를 다시 등록하세요(내 계정 → 프로그램 토큰)","code":"token_ip_mismatch"}`**(감사 `auth.token deny ip_mismatch` · AL-24 가 함께 센다). 이 링크 창구만은 위 표 그대로 `403 … "code":"ip_mismatch"`(분당 셈 뒤) — 21장 |
| 감사 | 링크: `session.link allow` / `deny ip_mismatch`(10분 3회면 경보 AL-24) / `deny rate_limited` — detail `auth=api_token`·`token_id`. 교환: `session.open allow`(행위자 = 토큰 주인) / `deny link_used·link_expired·token_invalid·ip_mismatch·user_disabled`. 토큰 세션의 **모든 행**에 `auth=token_session`·`token_id`. 발급: `api_token.issue allow`(detail `bound_ip`) · 앞 토큰 회수는 토큰마다 `api_token.revoke allow replaced` |

**왜 GET 인가.** 비서는 주소를 브라우저에 넘길 뿐이고 브라우저는 주소를 GET 으로 연다. 부작용은 「그 코드의 주인으로 내 브라우저에 세션 만들기」 하나다. 코드는 256비트 난수 · 30초 · 한 번 · 링크를 받은 IP 에서만 통하므로, 다른 사이트가 이 주소를 불러도 만들 세션이 없다(CSRF 대상이 아니다). **코드는 30초 · 1회**다 — 비서는 받은 주소를 파일·로그·클립보드에 남기지 않고 곧바로 열고 버린다. 30초 안에 열리지 않으면 로그인 화면이 뜬다(그때는 비밀번호로 로그인하면 된다).

```bash
# 비서(tbox-buddy)가 하는 일과 같다 — 토큰은 환경 변수에만, 머리 값은 표준입력으로(명령 인자에 싣지 않는다 — R-62)
printf 'X-API-Token: %s' "$QAREDI_LOGIN_TOKEN" | curl -s -w '\n[%{http_code}]\n' -H @- -X POST https://changkwon.mso.so/tbox/api/me/session-link
# → 201 {"url":"https://changkwon.mso.so/tbox/session/open?code=…","expires_in":30}  — 이 url 을 브라우저로 연다(30초 안 · 한 번)
```

실제로 받은 응답(2026-09-29 임시 인스턴스 `http://127.0.0.1:8421` · 임시 DB `tbox_qf9_8421_test` · 검토 계정 `claude_employee01`·`claude_admin01` · `-A "tbox-review/2026-09-29"` · `tests/qf9_pc_login.sh` 통과 항목). 토큰·코드 값은 규칙(S-09-11)대로 적지 않았다(`<43자>`).

```text
POST /tbox/api/me/api-tokens {scope:"session.open", …}      → 201 {…,"scope":"session.open","bound_ip":"127.0.0.1","revoked_previous":0}
(같은 사람이 한 번 더)                                        → 201 {…,"revoked_previous":1}   · 앞 토큰으로 링크 → 401 {"error":"API 토큰이 올바르지 않습니다"}
POST /tbox/api/me/api-tokens {scope:"session.open"} (claude_guest01) → 400 {"error":"잘못된 요청입니다"}
POST /tbox/api/me/session-link   (로그인 전용 토큰)          → 201 {"url":"<APP_URL>/tbox/session/open?code=<43자>","expires_in":30}
POST /tbox/api/me/session-link   (헤더 없이 세션 쿠키+CSRF) → 401 {"error":"X-API-Token 헤더가 없습니다"}
POST /tbox/api/me/session-link   (read · community.write 토큰) → 403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}
POST /tbox/api/me/session-link   (공용 .env 토큰)            → 403 {"error":"이 창구는 개인 토큰으로만 쓸 수 있습니다"}
POST /tbox/api/me/session-link   (묶인 IP 를 임시 DB 에서 10.20.30.40 으로 바꾼 뒤)
                                                              → 403 {"error":"이 토큰이 묶인 IP 가 아닌 곳에서 요청했습니다 — 이 PC 를 다시 등록하세요(내 계정 → 프로그램 토큰)","code":"ip_mismatch"}
POST /tbox/api/me/session-link   (같은 토큰 1분 안 6번째)    → 429 {…,"code":"rate_limited",…}
GET /tbox/api/files · /api/folders · /api/upload · /community/api/me/space · POST /community/api/pages (로그인 전용 토큰)
                                                              → 403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}
GET /tbox/api/whoami             (로그인 전용 토큰)          → 200 {"username":"claude_employee01", …}
GET /tbox/session/open?code=<43자>                            → 302 Location: /tbox/  + Set-Cookie __Host-tbox_session  → GET /tbox/api/me 200 (claude_employee01)
(같은 주소 다시 · 31초 지난 주소 · 받은 IP 와 다른 IP · 모양 틀린·없는 코드) → 302 Location: /tbox/login
GET /tbox/session/open?code=…&to=%2Ftbox%2Fsettings           → 302 Location: /tbox/settings   · to=https://evil.example/ → 302 /tbox/
(같은 주소를 동시에 두 번)                                    → 하나만 302 /tbox/, 다른 하나 302 /tbox/login
(토큰 세션) GET /tbox/api/files                               → 200
(토큰 세션) DELETE /tbox/api/files/{id} · POST /tbox/api/me/api-tokens · POST /tbox/api/me/passkeys/options · DELETE /community/api/pages/{id} …
                                                              → 403 {"error":"관리 기능을 쓰려면 비밀번호를 다시 확인해야 합니다","next":"reauth"}
(같은 임시 DB 의 일반 세션) DELETE /tbox/api/files/{id}      → 200   (일반 세션은 전과 같다)
(토큰 세션) GET /tbox/api/me/api-tokens · GET /tbox/api/me/passkeys · PATCH /tbox/api/me/settings → 200   (조회·화면 설정은 재인증 집합 밖)
(토큰 세션) POST /tbox/api/me/reauth {password} 뒤 같은 DELETE → 200
(토큰 세션 · 활동 없는 11분 뒤) GET /tbox/api/me             → 401   (일반 세션은 11분 뒤 200 — 30분)
(그 토큰을 회수한 뒤 · 또는 묶인 IP 를 바꾼 뒤) 토큰 세션 GET /tbox/api/me → 401
```

## G-0. 「개인」 등급 · 사람 지정 권한 · 권한 요청 (G-0 · PRD F-73, 2026-09-28 · 웹 화면 전용 · 세션)

- 새 창구(`/api/folders/{num}/grants` · `/api/files/{id}/grants` · `/api/access/people` · `/api/grants/mine` · `/api/access-requests`)는 **세션 전용**이다 — 토큰 창구 집합(`TBOX_API_TOKEN_ROUTES`)에 넣지 않았다(사람에게 권한을 주는 결정은 프로그램 토큰으로 하지 않는다). 토큰으로 부르면 401.
- 프로그램 토큰의 목록·검색·받기·폴더 목록은 **귀속 계정 기준으로 같은 판정**을 지난다 — 그 계정이 주인이거나 권한을 받은 개인 항목만 보이고, 나머지는 목록에 0 · 받기 403 · 폴더 403(없는 번호와 같은 본문). Admin 귀속 토큰도 목록에는 남의 개인 항목이 없다.
- 올리기: `level=private`(`/api/upload`) · `access_level=private`(`/api/files`)는 손님이 아닌 계정이면 된다(팀 없음 — `team_id` 는 `team` 등급일 때만 읽으므로 함께 보내도 무시된다). 개인 폴더 안에는 개인만(`folder_id` 가 남의 개인 폴더면 주인·편집 권한자만 — 아니면 없는 폴더와 같은 400 `bad_folder`).
- 자세한 창구 모양은 PRD 8장 G-0 행 · ARCHITECTURE 5.36.

## 21. 토큰 하나에 범위 여럿 · 커뮤니티 검색·읽기 창구 (Q:F-10, 2026-09-29 · 개인 토큰)

PC 한 대에 토큰이 셋(비서 파일용 · 비서 Ctrl+B 용 · MCP 글쓰기용)이던 것을 **하나로** 묶는다(사이트 PRD Q:F-10 · t-box PRD F-76 · ARCHITECTURE 4.10 · SECURITY R-114).
발급 때 범위를 **여러 개 고르고**(원자 배열), 커뮤니티 **검색·읽기** 창구 셋이 토큰에 열린다. 비서의 「커뮤니티 검색 → 결과 열기」와 MCP 의 `qaredi_search_pages` 가 이 장의 창구를 쓴다.

**원자 여섯 — 순서가 곧 정규화 순서다.**

| 원자 | 뜻 | 조건 |
|---|---|---|
| `read` | 파일 읽기 — 목록·검색·내려받기·폴더 목록 | 파일 원자의 바닥 |
| `upload` | 파일 올리기 — 올리기·폴더 만들기/이름/이동·파일 이동 | `read` 필요 |
| `delete` | 파일·폴더 지우기(개인 토큰만 — 14장) | `read` + `upload` 필요 |
| `community.read` | 커뮤니티 검색·읽기(아래 창구 표) | 독립 |
| `community.write` | 커뮤니티 글쓰기(19장) + 읽기 | 독립 · 서버가 `community.read` 를 **덧붙인다** |
| `session.open` | 이 PC 로그인 — 1회용 로그인 링크(20장) | 독립 · 지금 접속 IP 에 묶임 · 한 사람 하나 · **그 토큰의 모든 창구가 묶인 IP 에서만** |

**정규화 규칙(서버 `Authz::normalizeScope` — 발급 창구가 부른다).**
1. `scope` 는 원자의 **배열**(16개 이하 · 전부 문자열). 옛 **문자열**(`"read+upload"`)도 받는다 — `+` 로 쪼갠다.
2. 원자마다 위 표의 글자 그대로여야 한다(대소문자 그대로 · 빈 글자 안 됨). 모르는 원자 → 400.
3. 겹친 원자는 하나로. 빈 집합 → 400.
4. `community.write` 가 있으면 `community.read` 를 덧붙인다.
5. `upload` 에 `read` 가 없거나 `delete` 에 `read`·`upload` 가 없으면 → **400**(서버가 채워 주지 않는다 — 고른 것보다 넓은 토큰을 조용히 만들지 않는다. 화면의 체크박스는 연동해서 함께 켠다).
6. 위 표의 순서로 정렬해 `+` 로 이은 문자열이 저장·응답의 `scope` 다(예: `["session.open","read","upload","community.read"]` → `read+upload+community.read+session.open`).
7. 정규형의 원자가 전부 **이 사람이 고를 수 있는 것**(`GET /api/me` 의 `token_scopes`)이어야 한다 — 아니면 400.
400 은 전부 `{"error":"잘못된 요청입니다"}` 하나이고(힌트 없음) 비밀번호·코드 확인 **전**이라 실패로 세지 않는다. 옛 값 다섯 중 넷은 정규형과 글자까지 같다. `community.write` 한 원자로 저장된 옛 토큰은 마이그레이션이 `community.read+community.write` 로 바꾸고, 바뀌기 전에도 읽기 창구가 통한다.

**창구 → 필요한 원자(`Authz::tokenScopeAllows(범위, 라우트 정책)` 한 곳).**

| 라우트 정책 | 필요한 원자 | 창구 |
|---|---|---|
| `authenticated` | 아무것(원자가 전부 아는 것) | `GET /tbox/api/whoami` |
| `file.read` | `read` | `GET /tbox/api/files` · `…/{id}/download` · `GET /tbox/api/folders` |
| `file.upload` · `file.update` | `upload` | `POST /tbox/api/upload` · `POST /tbox/api/folders` · `PATCH /tbox/api/folders/{num}` · `PATCH /tbox/api/files/{id}` |
| `file.delete` | `delete` | `DELETE /tbox/api/files/{id}` · `DELETE /tbox/api/folders/{num}` |
| `community.read` | `community.read` 또는 `community.write` | `GET /community/api/search?q=` · `GET /community/api/pages?space=` · `GET /community/api/spaces` · `GET /community/api/me/space` · `GET /community/api/pages/{id}` |
| `community.write` | `community.write` | `POST /community/api/pages`(19장 — `title`·`doc` 만) |
| `session.open` | `session.open` | `POST /tbox/api/me/session-link`(20장) |

범위 밖 → `403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}` + 감사 `auth.token deny scope_not_permitted`(지금 모양 그대로 — 판별 칸은 없다. 프로그램은 403 과 이 문장으로 가른다). 모르는 조각이 든 범위(DB CHECK 가 막아 생기지 않는다)는 모든 창구에서 거짓이다. 표 밖 커뮤니티 창구(고치기·지우기·옮기기·공개 범위·공유·인계·댓글·즐겨찾기·최근 기록·게시판)는 토큰 헤더를 읽지 않는다 → `401 {"error":"로그인이 필요합니다"}`.

**커뮤니티 읽기 창구 셋(새로 열림).** 응답은 화면(세션)과 **같다** — 같은 핸들러·같은 행 관문이다. 볼 수 없는 페이지는 결과·개수·조각 어디에도 없다(검색은 관리자 특권 갈래를 쓰지 않는다 — 관리자 토큰으로도 남의 비공개 글은 검색에 나오지 않는다).

| 창구 | 받는 값 | 성공 |
|---|---|---|
| `GET /community/api/search?q=<1~100자>` | `q` — 빈 글자·기호만이면 빈 결과 | `200 {"results":[{"id","title","icon","space":{"kind","name"},"path":[{"id","title"}],"snippet","in_title"}],"comments":[…],"q"}` — 페이지 최대 20 · 댓글 최대 20 |
| `GET /community/api/pages?space=<공간 번호>` | 화면과 같은 쿼리(`page`·`q`·`status`·`parent`·`tree`) | `200 {"pages":[…],"total","page","per_page",…}` · 볼 수 없는 공간 → `403 {"error":"권한이 없습니다"}` |
| `GET /community/api/spaces` | 없음 | `200 {"spaces":[{"id","name","kind","team_id","page_count","can_write"}],"created"}` — `created` 는 이 요청이 팀 공간을 처음 만들었나(화면과 같은 게으른 만들기 — 감사 `space.create` 에 `auth=api_token`) |

**쥔 범위 확인 — `GET /tbox/api/whoami`(10장).** `token_scope`(정규형 문자열) · `token_scopes_held`(원자 배열)가 온다. 이름이 비슷한 `GET /api/me` 의 `token_scopes` 와 다르다:

| 칸 | 어디 | 뜻 |
|---|---|---|
| `token_scopes` | `GET /tbox/api/me`(세션 — 화면) | 이 사람이 **고를 수 있는** 원자(발급 폼의 체크박스) |
| `token_scope` · `token_scopes_held` | `GET /tbox/api/whoami`(토큰) | 이 요청을 연 토큰이 **쥔** 범위 — 세션 응답에는 없다 |
| `scope` · `scopes` | 발급 201 · `GET /tbox/api/me/api-tokens` · 관리자 목록 | 그 토큰 행의 범위(정규형 문자열 · 원자 배열) |

**IP 묶음(`session.open` 이 든 토큰).** 묶인 IP 밖에서는 **어느 창구든** `401 {"error":"이 토큰이 묶인 IP 가 아닌 곳에서 요청했습니다 — 이 PC 를 다시 등록하세요(내 계정 → 프로그램 토큰)","code":"token_ip_mismatch"}` — 만료 판정 다음, 계정을 읽기 전이다. 예외는 링크 창구 하나(20장의 `403 … "code":"ip_mismatch"` 그대로). 1대 규칙도 원자 기준 — `session.open` 이 든 토큰을 새로 받으면 앞의 `session.open` 이 든 토큰은 다른 원자가 섞였어도 **통째로 회수**된다(`revoked_previous`).

**비서·MCP 가 쓰는 순서.** ① 사람이 「내 계정 → 프로그램 토큰」에서 체크박스를 골라 **한 개** 발급(예: 파일 읽기·올리기 · 커뮤니티 읽기 · 이 PC 로그인) ② 프로그램은 시작할 때(또는 토큰을 바꾼 뒤) `GET /tbox/api/whoami` 로 `token_scopes_held` 를 읽어 **메모리에만** 둔다 — 어떤 기능을 켤지 고르는 참고값이고, 허용은 서버가 요청마다 다시 본다 ③ 커뮤니티 검색 `GET /community/api/search?q=` → 결과의 `id` ④ 열기: 브라우저 세션이 있으면 `/community/p/<id>` 를 바로, 없으면 `POST /tbox/api/me/session-link` 로 받은 `url` 에 `&to=` + URL 인코딩한 `/community/p/<id>` 를 이어 연다(로그인 화면의 「보던 곳으로」와 같은 허용 목록) ⑤ `403 … 범위에 없는 창구 …` 이면 「이 토큰의 범위에 없는 창구입니다 — 내 계정 → 프로그램 토큰에서 범위를 묶어 다시 발급하세요」, `401 token_ip_mismatch` 면 「이 PC 다시 등록」을 안내한다.

```bash
# 발급 — 원자 배열(세션 · CSRF · 비밀번호+코드는 13장 그대로)
curl -s -b jar -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' \
  -d '{"name":"사무실 PC","purpose":"비서·MCP 한 개로","scope":["read","upload","community.read","session.open"],"password":"<현재 비밀번호>","otp":"123456"}' \
  https://changkwon.mso.so/tbox/api/me/api-tokens
# 검색 — 토큰은 표준입력으로(명령 인자에 싣지 않는다 — R-62)
printf 'X-API-Token: %s' "$QAREDI_TOKEN" | curl -s -H @- 'https://changkwon.mso.so/community/api/search?q=%ED%9A%8C%EC%9D%98'
```

실제로 받은 응답(2026-09-29 임시 인스턴스 `http://127.0.0.1:8451` · 임시 DB `tbox_qf10_8451_test` · 검토 계정 `claude_employee01`·`claude_employee02`·`claude_guest01` · `-A "tbox-review/2026-09-29"` · `tests/qf10_multi_scope.sh` 통과 항목). 토큰 값은 적지 않았다.

```text
POST /tbox/api/me/api-tokens scope=["upload"] · ["delete","read"] · ["x"] · [] · [1] · {"a":"read"} · ["READ"] · "read+" · "" · "read+admin" · null · 7
                                                              → 400 {"error":"잘못된 요청입니다"}   (실패 집계 그대로 · 감사 api_token.issue deny bad_request)
POST /tbox/api/me/api-tokens scope=["community.read"] (claude_guest01) → 400
POST /tbox/api/me/api-tokens scope=["upload","read"]          → 201 {…,"scope":"read+upload","scopes":["read","upload"],…}
POST /tbox/api/me/api-tokens scope=["community.write"]        → 201 {…,"scope":"community.read+community.write","scopes":["community.read","community.write"],…}
POST /tbox/api/me/api-tokens scope="read+upload+delete"       → 201 {…,"scope":"read+upload+delete",…}
POST /tbox/api/me/api-tokens scope=["read","upload","community.read","session.open"]
                                                              → 201 {…,"scope":"read+upload+community.read+session.open","scopes":[…],"bound_ip":"127.0.0.1","revoked_previous":0}
(그 묶음 토큰) GET /tbox/api/files 200 · POST /tbox/api/upload 201 · GET /community/api/search?q=… 200 · POST /tbox/api/me/session-link 201
(그 묶음 토큰) DELETE /tbox/api/files/{id} · POST /community/api/pages → 403 {"error":"이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}
(그 묶음 토큰) GET /tbox/api/whoami                           → 200 {…,"token_scope":"read+upload+community.read+session.open","token_scopes_held":["read","upload","community.read","session.open"]}
GET /tbox/session/open?code=<43자>&to=%2Fcommunity%2Fp%2F<id>  → 302 Location: /community/p/<id>
(묶인 IP 를 임시 DB 에서 10.9.9.9 로) GET /tbox/api/files · GET /community/api/search · GET /tbox/api/whoami
                                                              → 401 {"error":"이 토큰이 묶인 IP 가 아닌 곳에서 요청했습니다 — 이 PC 를 다시 등록하세요(내 계정 → 프로그램 토큰)","code":"token_ip_mismatch"}
(같은 때) POST /tbox/api/me/session-link                      → 403 {…,"code":"ip_mismatch"}   · 묶이지 않은 read+upload 토큰 GET /tbox/api/files → 200
POST /tbox/api/me/api-tokens scope=["community.read","session.open"] (묶음이 살아 있을 때) → 201 {…,"revoked_previous":1}  · 앞 묶음 토큰 → 401 {"error":"API 토큰이 올바르지 않습니다"}
(claude_employee02 · community.read 토큰) GET /community/api/search · /api/spaces · /api/pages?space=<내 공간> · /api/pages/{내 것} → 200 (세션과 본문까지 같음)
(같은 토큰) GET /community/api/pages?space=<남의 개인 공간> · /api/pages/{남의 비공개} → 403 {"error":"권한이 없습니다"} (세션과 같음)
(같은 토큰) POST /community/api/pages · GET /tbox/api/files · POST /tbox/api/me/session-link → 403 범위 밖
(같은 토큰) PATCH·DELETE /community/api/pages/{id} · GET /community/api/me/favorites · /api/me/recent · POST …/comments · GET /api/boards/1/pages → 401
(같은 토큰) GET /tbox/api/whoami → 200 {"username":"claude_employee02",…,"token_scope":"community.read","token_scopes_held":["community.read"]}
```

## 22. 업무 화면 창구 (Q:F-12) — 담당 · 프로젝트 상태 · 포트폴리오 (지시서 A, 2026-10-07 · 웹 화면 전용 · 세션)

사이트 Q:F-12 WK-3 · WK-12 · WK-13 · WK-14 · 커뮤니티 `../community/ARCHITECTURE.md` 23.3 · 23.6 · 23.7 · 23.9 · SECURITY C:R-15 · C:R-16.
전부 **세션 전용**이다(`COMMUNITY_API_TOKEN_ROUTES` 에 넣지 않았다 — 프로그램 토큰으로는 401). 바꾸는 요청은 CSRF(`X-CSRF-Token`).
**새 권한이 없다** — 담당은 「맡은 사람」 표시일 뿐 볼·고칠 권한을 주지 않고, 포트폴리오는 프로젝트를 볼 권한을 주지 않는다.
판정은 `CommunityAuthz::decidePageAssign` · `decideAssignTarget` · `decidePortfolioRead` · `decidePortfolioManage` · `decidePortfolioAdd` 다섯과
기존 `decidePageRead` · `decidePageChange` 뿐이다. 아래 응답 모양·문구는 격리 틀 시험 `tests/community/qf12_floor_http.py`(Q:T-24~28 · 33~37)가
단언한 키·값이다(지시서 B 의 22-6~22-10 은 `tests/community/qf12_server_http.py` — Q:T-29~32) — 운영 응답 원문은 묶음 끝 검토에서 붙인다(추정 응답을 적지 않는다).

### 22-1. 담당 정하기 — `PATCH /community/api/pages/{id}/properties` 의 `assignee`

| 항목 | 내용 |
|---|---|
| 인증 | 세션 · 정책 `community.write`(손님 403) · CSRF |
| 요청 | `{"assignee": "<계정명>"}` 또는 `{"assignee": null}`(비우기). 다른 속성 키(`status`·`due` …)와 함께 보낼 수 있다 — 그 키들은 전처럼 `decidePageChange` |
| 판정 순서 | 행 잠금·읽기 → `decidePageRead`(못 보면 403·404) → **`decidePageAssign`**(게시판 글 `space_governed` · 그 밖 `decidePageChange` 그대로 — Admin 은 남의 작업 담당을 못 정한다) → 대상 찾기 → **대상 몫으로 다시 읽은 행**에 `decideAssignTarget`(활성 · 쓰기 자격 · 그 작업을 **고칠 수 있는** 사람 — WK-14 A) → 같은 사람이면 변경 없음 |
| 성공 | 200 `{"page":{"id","status","resolved","assignee":{"username","role","is_active"}\|null},"changed":[…,"assignee"],"verify",…,"page_kind"}` · 바뀌었으면 한 트랜잭션에 `pages.assignee_id` + `page_property_events`(`prop='assignee'`, 값 = 계정명) + 감사 `page.assign\|allow`(`detail.granted_by`·`from`·`to`), 커밋 뒤 알림 `assigned` 하나(받는 사람 기준 다시 판정 · 자기 자신 제외 · 옛 담당·비운 일은 알리지 않음). 같은 값이면 `changed` 에 없고 기록 0 |
| 실패 | 403 `{"error":"권한이 없습니다"}` — 정할 수 없는 사람(감사 `page.assign\|deny\|<코드>` — `not_owner`·`not_member`·`role_not_permitted`·`space_governed`) · **400 `{"error":"이 사람에게는 맡길 수 없어요"}` 하나** — 없는 계정 · 모양이 틀린 값(문자열 아님 · `Auth::USERNAME_RE` 밖) · 비활성 · 손님 · 그 작업을 고칠 수 없는 사람을 응답이 가르지 않는다(이유 코드 `inactive`·`not_writer`·`assignee_cannot_edit` 는 감사에만, 모양 틀림은 감사 없음) |
| 넣지 않는 것 | 대상이 왜 안 되는지 · 대상 계정의 번호·팀 |

**정해 둔 동작**: 담당이 나중에 권한을 잃어도(공유 회수·팀 이동) `assignee_id` 는 남는다 — 자동으로 풀지 않고 아래 `assignee_can_edit=false` 로 알린다.
담당은 `page_values` 로 쓰지 않는다(쓰기는 이 창구 한 곳).

### 22-2. 담당 후보 — `GET /community/api/pages/{id}/assignee-candidates?q=`

| 항목 | 내용 |
|---|---|
| 인증 | 세션 · 정책 `community.write`(맡기는 화면에서만 쓴다 — 손님 403) |
| 요청 | `q` 공백을 뗀 1~64자(아이디·이름 부분 일치, `%`·`_` 는 글자로). 비면 빈 목록 |
| 판정 | 그 페이지를 볼 수 있어야(`community_history_gate` — 403·404) + **부르는 사람이 `decidePageAssign` 통과**(아니면 403, 목록 조회라 감사 없음). 활성 사용자 20명을 찾아 한 명씩 `decideAssignTarget`(그 사람 몫으로 다시 읽은 행) — 통과한 사람만 10명까지. 자기 자신도 후보(「나에게 맡기기」) |
| 성공 | 200 `{"candidates":[{"username","display_name"}]}` — 키는 이 둘뿐(계정 번호·역할·팀 0) |
| 실패 | 403 `{"error":"권한이 없습니다"}` · 404 `{"error":"찾을 수 없습니다"}` |

### 22-3. 페이지 응답에 더한 두 칸 — `GET /community/api/pages/{id}`

`can_assign`(요청자의 `decidePageAssign` · 쓰기 자격과 AND) · `assignee_can_edit`(지금 담당이 그 작업을 고칠 수 있나 — 담당 몫으로 다시 읽은 행에 `decideAssignTarget`, 담당이 없으면 `null`).
기존 `page.assignee` 객체 모양(`{"username","role","is_active"}`)은 그대로다.

### 22-4. 프로젝트 상태 — `POST|GET /community/api/projects/{id}/status`

`{id}` = 프로젝트(데이터베이스 페이지)의 public_id. 데이터베이스의 `purpose='project'` 가 아니면(일반 페이지·그냥 표) **400** `{"error":"프로젝트에서만 상태를 쓰고 볼 수 있습니다"}`.

| 항목 | POST | GET |
|---|---|---|
| 인증 | 세션 · `community.write` · CSRF | 세션 · `community.read` |
| 요청 | `{"status": "on_track"\|"at_risk"\|"off_track"\|"on_hold"\|"complete", "note": "…"}` — 키는 이 둘뿐, `note` 선택(기본 빈 글) · 280자(코드 포인트) 이하 · 줄바꿈 말고 제어 문자 0 | `?limit=` 1~50(기본 20) |
| 판정 | 페이지 관문(`decidePageRead`) → **`decidePageChange`**(고칠 수 있는 사람 — Admin 의 열람만으로는 안 된다) → 프로젝트인가 | 페이지 관문(`decidePageRead`) → 프로젝트인가 |
| 성공 | 200 `{"status":{"status","note","at","by":{"username","display_name"}}}` · 감사 `project.status\|allow`(`detail.status`, 글은 남기지 않음) · 알림 없음 | 200 `{"current": <줄>\|null, "updates": [<줄>…]}` — 줄 = `{"status","note","at","by":{…}\|null}`, 지금 상태 = 마지막 줄(`at`·`id` 내림) |
| 실패 | 400(모양 · 값 밖 · 281자 · 남는 키 · 프로젝트 아님) · 403(감사 `project.status\|deny\|<코드>` — `not_owner`·`not_member`·`role_not_permitted`·`not_author`·`board_inactive`) · 404 | 400(limit · 프로젝트 아님) · 403 · 404 |

고치기·지우기 창구는 없다(`PATCH`·`DELETE` → 405) — 앱 계정은 `project_status_updates` 에 SELECT · INSERT 만이다.

### 22-5. 포트폴리오 — 팀 공간에서 팀이 함께 보는 프로젝트 묶음

| 메서드 경로 | 정책 | 요청 | 성공 |
|---|---|---|---|
| `GET /community/api/spaces/{num}/portfolios` | `community.read` | `{num}` = 공간 번호(공간 창구와 같은 식별자) | 200 `{"space":{"id","name","kind"},"portfolios":[{"id","name","created_at"}],"can_create"}` |
| `POST /community/api/portfolios` | `community.write` | `{"space": <번호>, "name": "…"}` — 이름 앞뒤 공백 뗀 1~80자 · 제어·서식 문자 0 · 키는 이 둘뿐 | 201 `{"portfolio":{"id","name","space":{"id","name"},"created_at"},"can_manage":true}` |
| `GET /community/api/portfolios/{id}` | `community.read` | — | 200 `{"portfolio":{…},"can_manage","total","by_status":{"on_track","at_risk","off_track","on_hold","complete","none"},"items":[…]}` |
| `PATCH /community/api/portfolios/{id}` | `community.write` | `{"name": "…"}` | 200 `{"portfolio":{…},"changed":true\|false}` |
| `DELETE /community/api/portfolios/{id}` | `community.write` | — | 200 `{"deleted":true}`(= `deleted_at`, 줄은 남는다) |
| `POST /community/api/portfolios/{id}/items` | `community.write` | `{"project": "<프로젝트 public_id>"}` | 201 `{"added":true}` · 이미 있으면 200 `{"added":false}` |
| `DELETE /community/api/portfolios/{id}/items/{id}` | `community.write` | 두 번째 `{id}` = 프로젝트 public_id | 200 `{"removed":true}` |

`items` 한 줄 = `{"project":{"id","title","icon","color"},"status":{"status","note","at"}|null,"progress":{"done","total"},"owner":{"username","display_name"}|null,"due"}`
(`owner` = 프로젝트 페이지의 소유자(개인 공간)·작성자(그 밖) · `status` = 마지막 상태 줄 · `color` = `peach`·`lilac`·`mint`·`sand`·null).

| 판정 | 내용 |
|---|---|
| 보기 | `decidePortfolioRead` — 지워짐 `not_found` · 그 팀 공간의 구성원 `team_member` · Admin `admin_all`(읽기만 — 감사 `portfolio.read\|allow\|admin_all`, 목록·한 건 모두) · 그 밖 `not_member`(감사 `portfolio.read\|deny\|not_member`) |
| 만들기·이름·지우기·빼기 | `decidePortfolioManage` — 구성원 + 쓰기 자격. Admin(구성원이 아니면)·손님은 거부 |
| 넣기 | `decidePortfolioAdd` — 관리 자격 + 넣는 사람이 그 프로젝트를 **관리자 특권(`admin_all`·`board_moderator`) 말고** 볼 수 있음(`project_not_readable`) + `purpose='project'`(`not_project` → 400) |
| 항목 · 수 · 진행률 | **보는 사람의 검색 조건절(`community_search_pages_where` — 관리자·게시판 관리자 갈래를 끈 판) 안에서만** — 볼 수 없는 프로젝트는 줄이 없고 `total`·`by_status` 도 그 결과에서만 센다(「n개 숨김」 같은 칸 0). **관리자도 자기 몫으로 볼 수 있는 프로젝트만** 보인다(포트폴리오를 `admin_all` 로 읽어도 남의 비공개 프로젝트 줄은 없다 — A 검토 RV-A1). 진행률 = 그 DB 의 행 중 같은 조건절을 지난 것의 `done`/전체 — 사람마다 다를 수 있다 |
| 빼기 | 빼는 사람이 그 프로젝트를 (관리자 특권 없이) 볼 수 있을 때만 줄을 찾는다(`community_search_pages_where`) — 아니면 404 |

| 실패 | 응답 |
|---|---|
| 못 보는 포트폴리오 · 지운 포트폴리오 · 없는 포트폴리오 · 구성원이 아닌 공간·개인 공간에 만들기 | **404 `{"error":"찾을 수 없습니다"}` — 같은 문구**(있는지도 알리지 않는다) |
| 볼 수는 있으나 관리 못 함(Admin 의 열람) · 넣는 사람이 볼 수 없는 프로젝트 | 403 `{"error":"권한이 없습니다"}` + 감사 `portfolio.<동작>\|deny\|<코드>` |
| 모양 · 프로젝트 아님 | 400 `{"error":"잘못된 요청입니다"}` · `{"error":"프로젝트만 포트폴리오에 넣을 수 있습니다"}` |
| 살아 있는 같은 이름(대소문자 무시) | 409 `{"error":"같은 이름의 포트폴리오가 이미 있습니다"}` |
| 한도 — 팀 공간당 50 · 포트폴리오당 프로젝트 100 | 400 `{"error":"팀 공간 하나에 포트폴리오는 50개까지 만들 수 있습니다"}` · `{"error":"포트폴리오 하나에 프로젝트는 100개까지 넣을 수 있습니다"}` |

변경마다 `portfolio_events`(`create`·`rename`·`add`·`remove`·`delete` — 지울 수 없는 기록, 앱 계정 SELECT · INSERT 만) 한 줄 + 감사 `portfolio.create|rename|delete|add|remove|allow`.
화면 `GET /community/portfolios/{id}` 는 지시서 D 몫이다.

### 22-6. 내 작업 — `GET /community/api/my-tasks` (지시서 B · ARCH 23.4 · C:R-17)

| 항목 | 내용 |
|---|---|
| 인증 | 세션 · 정책 `community.read`(손님은 빈 목록) |
| 요청 | `?done=0`(기본 — 끝나지 않은 것) · `?done=1`(끝난 것만) · `?bucket=<키>`(그 묶음만) · `?cursor=<n>`(그 묶음 안에서 이미 받은 줄 수 — `bucket` 과 함께만, 0~10000). 그 밖의 값은 400 |
| 대상 | ① 담당이 나 ② 담당 없음 + **내 것**(개인 공간 소유자 · 팀·게시판은 작성자) + 상태 또는 기한이 있음. 공통 — 지우지 않았음 · **보는 사람의 검색 조건절(`community_search_pages_where` — 관리자·게시판 관리자 갈래를 끈 판) 안에서만**: 담당이 나여도 볼 수 없는 작업은 없고, Admin 에게도 남의 비공개 작업은 섞이지 않는다. 담당이 권한을 잃으면(공유 회수·팀 이동) 그 줄은 목록에서 빠진다(`assignee_id` 는 남는다) |
| 묶음 | 서버가 **Asia/Seoul** 날짜로 가른다 — `overdue` 「지난 기한」(기한 < 오늘) · `today` 「오늘」 · `this_week` 「이번 주」(내일 ~ 이번 주 일요일) · `later` 「나중에」 · `no_due` 「기한 없음」. 묶음마다 기한 오름차순 → 고친 때 내림차순, 한 번에 100줄 |
| 성공 | 200 `{"today":"Y-m-d","done":false,"buckets":[{"key","name","items":[<줄>…],"next_cursor":"100"\|null}…]}` — `bucket` 을 주면 그 묶음 하나만 |
| 줄 | `{"public_id","title","status","due","project":{"public_id","title","color"}\|null,"doc_space":"문서 · <공간 이름>"\|null,"assigned_by":"<표시 이름>"\|null,"comments_n","sub_done","sub_total"}` — `project` = 행이면 그 데이터베이스 페이지(그 페이지를 **볼 수 있을 때만**, 아니면 null) · `doc_space` = 행이 아닌 문서의 「문서 · 공간 이름」 · `assigned_by` = 마지막 담당 사건이 나에게 맡긴 것이고 내가 한 일이 아닐 때 그 사람의 이름(없으면 계정명) · `comments_n` = 지우거나 숨기지 않은 댓글 수 · `sub_done`/`sub_total` = **볼 수 있는** 하위 페이지 중 끝난 것/전체 |
| 실패 | 400 `{"error":"잘못된 요청입니다"}` · 401 |

### 22-7. 「오늘」 — 공지는 작업 칸에서 빠진다 · 옛 주소 넘김 (지시서 B · WK-8 ②)

- `GET /community/api/briefing` 모양은 그대로다. 바뀐 것: 공지(`kind: "notice"`)는 `top`·`missed` 를 차지하지 않고 `others` 에만 남는다(읽지 않은 공지 · 게시판 읽기 판정 그대로 · 최대 20). `count`(런처의 「오늘 n건」 = `top`+`missed`)에 공지가 들지 않는다. 홈의 「공지 한 줄」은 이 `others` 의 `notice` 줄로 새 창구 없이 채울 수 있다(게시판 창구 `GET /api/boards/{num}/pages` 에는 읽음 여부 칸이 없다).
- `GET /community/today` → **308** `Location: /community/my-tasks`(고정 대상 — 요청의 쿼리·머리는 Location 에 닿지 않는다). 미로그인은 전처럼 302 로그인 화면. 화면 `/community/my-tasks` 는 지시서 D 몫이다.

### 22-8. 프로젝트 만들기 · 「프로젝트로 쓰기」 (지시서 B · WK-5 · WK-16 A · ARCH 23.5)

**만들기** — `POST /community/api/pages {"database": true, "preset": "project", "title", "space_id"?, "parent_id"?}`. 판정은 보통 페이지 만들기 그대로(부모 행 또는 공간의 `decidePageChange`). `preset` 은 `"project"` 하나뿐이고 `database:true` 와 함께만(그 밖 400). 칸·보기·색은 **서버 상수에서만** 채운다 — 요청에서 칸·보기 모양을 받지 않는다:

| 무엇 | 값(원문) |
|---|---|
| 칸 | `{"key":"section","name":"섹션","type":"select","options":["00. 준비","01. 진행","02. 마무리"]}` — 섹션 key 는 서버가 정한 고정 `section`(속성 더하기로는 만들 수 없다). 섹션 추가·이름·순서 = 보통 스키마 변경(`options`·`rename` — `decidePageManage`, 편집 공유자는 403) |
| 보기 넷 | `{"name":"목록","type":"list","group_by":"section"}` · `{"name":"보드","type":"board","group_by":"section"}` · `{"name":"타임라인","type":"timeline","date_prop":"due","scale":"month"}` · `{"name":"달력","type":"calendar","date_prop":"due","mode":"month"}` (보기 id 는 서버가 새로) |
| 색 | `peach` → `lilac` → `mint` → `sand` 차례 — 그 공간에서 **만드는 사람이 볼 수 있는** 프로젝트(쓰임이 있는 데이터베이스) 수로 고른다 |
| 쓰임 | `databases.purpose = 'project'` |

성공 201 — 보통 응답의 `page.database` 에 `"purpose":"project","color":"<색>"` 이 더해진다. 감사 `database.create|allow`(`detail.preset = "project"`).

**「프로젝트로 쓰기」·「표로 쓰기」** — `PATCH /community/api/databases/{id}/schema {"use_as": "project" | "table"}`(다른 동작 키와 함께 주면 400 · `version` 은 전처럼 선택). 자격은 스키마 변경과 같은 `decidePageManage`(아니면 403 + 감사 `database.schema|deny|not_owner`).
- `"project"`: `purpose='project'` · 섹션 칸이 없으면 더함 · 기본 보기 넷 중 **같은 이름(대소문자 무시)의 보기가 없는 것만** 더함 · 색이 없으면 차례 색. 다시 불러도 칸·보기가 겹치지 않는다(멱등 — `changed:false`). 「섹션」 이름의 다른 칸이 있으면 400 `name_taken` · 칸 30개 넘음 400 `too_many_props` · 보기 20개 넘음 400 `too_many_views`.
- `"table"`: `purpose = NULL` **만**(칸·보기·색은 그대로).
- 「내 할 일」(`purpose='my_tasks'`)은 바꾸지 않는다 — 400 `{"reason":"my_tasks"}`.
- 성공 200 `{"database":{"id","purpose","color","schema","views","version"},"added":{"prop":true|false,"views":["목록",…]},"changed":true|false}` · 바뀐 때만 감사 `database.schema|allow`(`detail.op = "use_as"`, `to`, `added_prop`, `added_views`).

### 22-9. 「내 할 일」 빠른 추가 — `POST /community/api/pages {"my_tasks": true, "title", "due"?}` (지시서 B · WK-4 · ARCH 23.5)

- 함께 줄 수 있는 키는 `title` · `due`(`Y-m-d` 또는 null) 뿐이다(그 밖 400 · `my_tasks` 는 `true` 만 · `due` 는 `my_tasks` 와 함께만). 프로그램 토큰으로는 `my_tasks`·`preset`·`due` 를 받지 않는다(400 `token_scope_fields` — Q:F-8 그대로).
- 처음이면 내 개인 공간 최상위에 **비공개** 프로젝트 「내 할 일」(`purpose='my_tasks'` · 칸·보기는 프로젝트 preset 과 같음 · 색 차례)을 만들고 `my_tasks_projects` 에 적는다. 두 요청이 겹치면 늦은 쪽은 PK 충돌 → 자기가 만든 프로젝트를 통째로 되돌리고 먼저 적힌 프로젝트를 다시 읽어 쓴다(사람마다 하나). 적어 둔 프로젝트를 휴지통에 넣었거나 내 개인 공간 밖으로 옮겼으면 적어 둔 줄만 지우고 새로 만든다.
- 그다음은 그 프로젝트의 **행**을 보통 만들기 길(부모 행의 `decidePageChange` · 행 한도 · 감사 `page.create`)로 만든다 — 상태 `todo` · 준 기한으로 시작(만들 때의 첫 값이라 변경 사건은 없다).
- 성공 201 — 보통 응답 + `page.status`·`page.due` + 맨 위 `"my_tasks":{"project_id":"<프로젝트 public_id>","created":true|false}`. 준비하지 못하면 409 `{"reason":"my_tasks_busy"}`.

### 22-10. 보드·목록 묶기 · 내장 담당 칸 (지시서 B · ARCH 23.5)

- `PATCH /community/api/databases/{id}/views` 의 `board`·`list` 보기가 `group_by` 를 받는다 — 값은 `"status"` 또는 **그 데이터베이스의 select 칸 key** 만(글·다중 선택·없는 key, 그 밖 보기 종류의 `group_by` 는 400 `bad_view`). 저장 모양 `{"id","name","type":"board","group_by":"section"}` — 행 목록 응답(`GET …/rows` 의 `views`)에 그대로 나온다. 행 응답은 그대로이고 묶기는 화면이 한다: 보드는 고른 저장 보드의 `group_by` 가 select 칸이면 그 선택지 순서로 열을 그리고(빈 값 열 「섹션 없음」 — 섹션이 아닌 칸이면 「<칸 이름> 없음」), 카드 옮기기는 그 select 값을 `PATCH /api/pages/{id}/values` 로 쓴다(사건 `db:<key>` — 섹션이면 `db:section`). `group_by` 가 없거나 `status` 면 전처럼 상태 열.
- 내장 칸 `assignee`(= `pages.assignee_id`) — 거르기 `eq`(계정명 — 「나」는 화면이 자기 계정명을 보낸다) · `empty` · `not_empty` 와 정렬(계정명). **값 쓰기는 22-1 창구 한 곳**이다 — `PATCH …/values {"assignee": …}` 는 400 `unknown_prop`. 행 목록 응답에는 담당 값이 아직 없다(`database_rows.php` 는 이 조각의 수정 파일이 아니다 — D 에서 필요하면 그때).

## 23. 처리방침·약관 — 공개 화면 · 동의 기록 · AI 국외 전송 동의 (사이트 Q:F-13 P1, 2026-10-07 · 웹 화면 · 세션)

기획 `../docs/privacy-terms-plan.md` 5.2 · 결정 PT-1~PT-4 · PT-7~PT-9 · 판정 한 곳 `src/Legal.php` · 표 `user_consents`(앱 계정 SELECT·INSERT 만).
**토큰 창구 집합(`TBOX_API_TOKEN_ROUTES`)은 그대로다** — 아래 창구는 사람의 세션으로만 부르고, 프로그램 토큰 창구는 동의 관문을 지나지 않는다.

### 23-1. 공개 화면 — `GET /tbox/privacy` · `GET /tbox/terms`

| 항목 | 값 |
|---|---|
| 인증 | 없음(public) — 세션을 읽지 않는다 · `Set-Cookie` 0 · 스크립트 0. 옛 경로 `/privacy`·`/terms` 는 사이트 층 규칙대로 308 → `/tbox/…` |
| 요청 | 선택 `?v=YYYY-MM-DD` — `config/legal.json` 목록에 **글자 그대로 있는 판**만. 읽는 파일은 언제나 목록이 준 이름(`content/legal/<문서>-<판>.html`) |
| 성공 | 200 HTML — 제목 · `시행일 <판>` · 지금/이전 판 표시 · 판 목록(둘 이상일 때) · 문서 조각 · 바닥 약관 링크 |
| 실패 | 목록에 없는 판(`1999-01-01`·`../x`·빈 값·배열 등) 404 · POST 405 · 목록 모양이 어긋나면 500(조용히 넘기지 않는다) |

### 23-2. 동의 관문 — 세션이 있는 모든 요청(t-box · 커뮤니티)

로그인 뒤 지금 판의 `terms`·`privacy` 동의 줄(문서별 마지막 줄이 agree 이고 판이 지금 판)이 없으면 `Auth::requireLogin` 이 막는다.
열린 창구는 `GET /consent` · `GET|POST /api/me/consents` · `GET /api/me` · `POST /api/logout` · `GET|POST /api/me/delete-request` · `POST /api/me/reauth`(비밀번호 재인증 — 권한을 주지 않는다) 여덟뿐(동의하지 않은 사람도 탈퇴는 요청한다 — 약관 제3조, P2-R1·R2). 패스키 재인증 창구는 열지 않는다. 최초 로그인 절차(비밀번호 변경 · 2단계 등록)가 남았으면 그 관문이 먼저다.

| 막힌 것 | 응답 |
|---|---|
| 화면(`/tbox/…` · `/community/…`, `/api/` 아님) | 302 `Location: /tbox/consent` — 보던 곳이 `Http::returnPath` 허용 목록 안이면 `?to=<정규형>` |
| API | 403 `{"error":"이용약관 동의와 개인정보 처리방침 확인을 먼저 마쳐 주세요","next":"consent","code":"consent_required"}` · 감사 `auth.gate|deny|consent_required` |

실제 응답(격리 틀 `tests/qf13_consent_http.py` · 합성 계정 · 판 자리 값 `0000-00-00`, 2026-10-07):

```
GET /tbox/settings            → 302 Location: /tbox/consent?to=%2Ftbox%2Fsettings
GET /tbox/api/files           → 403 {"error":"이용약관 동의와 개인정보 처리방침 확인을 먼저 마쳐 주세요","next":"consent","code":"consent_required"}
GET /tbox/api/files (X-API-Token, 같은 계정) → 200 (토큰 창구는 막지 않는다)
```

### 23-3. 동의 상태 · 동의 줄 더하기 — `GET|POST /tbox/api/me/consents`

| 항목 | 값 |
|---|---|
| 인증 | 세션(authenticated) · POST 는 `X-CSRF-Token` · 대상은 언제나 세션의 본인(본문에 사람 칸 없음) |
| 요청 | GET 없음 · POST `{"doc":"terms"}`(약관 동의 + 처리방침 확인 — 두 줄) · `{"doc":"ai_overseas"}`(AI 국외 전송 동의) · `{"doc":"ai_overseas","action":"withdraw"}`(철회). 그 밖의 키·값 400 |
| 성공 | 200 `{current:{terms,privacy}, required, ai_overseas, consents:{terms|privacy|age14|ai_overseas: {version,action,at}|null}}` (+ GET 은 `notice` · POST 는 `ok`·`changed`). 이미 그 상태면 줄을 더하지 않고 `changed:false` |
| 실패 | 400 「잘못된 요청입니다」 · CSRF 403 · 로그인 없음 401 |
| 넣지 않는 것 | 동의 줄의 IP |

```
POST /tbox/api/me/consents {"doc":"terms"}
→ 200 {"ok":true,"changed":true,"current":{"terms":"0000-00-00","privacy":"0000-00-00"},"required":false,"ai_overseas":false,
       "consents":{"terms":{"version":"0000-00-00","action":"agree","at":"2026-10-07 07:03:35.898914+09"},"privacy":{"version":"0000-00-00","action":"agree","at":"2026-10-07 07:03:35.898914+09"},"age14":null,"ai_overseas":null}}
POST /tbox/api/me/consents {"doc":"privacy"} → 400 {"error":"잘못된 요청입니다"}
```

### 23-4. AI 국외 전송 동의 — 409 `ai_consent_required`

모아 AI(`POST /tbox/api/messages/ai`) · 메시지 AI 도구(`POST /tbox/api/messages/assist`) · 사진 이야기(`POST /community/api/memories/{id}/caption`) ·
커뮤니티 AI(`POST /community/api/ai/*` · `/api/pages/{id}/ai-summary`)는 외부 호출 **전에** `ai_overseas` 지금 상태(마지막 줄 agree)를 본다.
아니면 예약·기록·Google 호출 0 으로 409 — 화면(messenger.js · memories.js · editor.js)이 `notice` 로 동의 창을 띄우고, 동의하면 같은 요청을 한 번 다시 보낸다.

```
POST /tbox/api/messages/ai {"thread_id":null,"text":"…","request_id":"<UUIDv4>"}
→ 409 {"error":"AI 기능을 쓰려면 먼저 Google 로 보내는 국외 전송에 동의해 주세요","code":"ai_consent_required",
       "notice":{"title":"AI 기능 — 국외 전송 동의","rows":[["받는 곳","Google LLC (Gemini API)"],["나라","미국 등"],["보내는 것","질문·앞 대화(최대 10턴)·다듬을 글(상대 메시지 포함 가능)·사진(위치 정보 제거)·장소·날짜"],["언제·어떻게","AI 기능을 실행할 때 암호화된 통신으로"],["보유","Google 의 정책에 따름"],["거부하면","AI 기능만 쓸 수 없고, 서비스의 다른 기능은 그대로 쓸 수 있습니다"]],
                 "note":"동의는 「약관·동의」 화면에서 언제든 철회할 수 있습니다.","policy_url":"/tbox/privacy#privacy-7"}}
```

감사: `consent.agree|allow` · `consent.withdraw|allow`(대상 = 동의한 사람 · `detail.consents` = 문서·판 목록 · 가입은 `detail.via=register`) · `auth.gate|deny|consent_required`.

## 24. 보관 기간 정리 · 탈퇴 요청·처리 (사이트 Q:F-13 P2, 2026-10-07 · 웹 화면 · 세션)

기획 `../docs/privacy-terms-plan.md` 5.1(보관 기간 표) · 5.2 「탈퇴」 「보관 기간 파기」 「감사 3년」 · 표 `account_deletion_requests`(앱 계정 SELECT · INSERT(user_id) · UPDATE(status·processed_by·processed_at) — DELETE 없음).
**토큰 창구 집합(`TBOX_API_TOKEN_ROUTES`)은 그대로다** — 아래 창구는 사람의 세션으로만 부른다. 동의 관문(23-2)은 탈퇴 요청 두 창구를 열어 둔다 — 약관에 동의하지 않은 사람도 동의 화면에서 비밀번호 재인증 → 탈퇴 요청을 할 수 있다(관리자 처리 창구는 동의 뒤에만).
파기(1년 · 30일 · 3년 · 탈퇴 30일 뒤 익명화)는 창구가 없다 — root cron 04:40 `scripts/privacy_purge.php --run` 이 **표 소유자 전용 함수** 둘(`public.privacy_purge_expired` · `public.privacy_purge_accounts`)을 부른다(앱 계정은 42501).

### 24-1. 내 탈퇴 요청 — `GET|POST /tbox/api/me/delete-request`

| 항목 | 값 |
|---|---|
| 인증 | 세션(authenticated) · POST 는 `X-CSRF-Token` · 대상은 언제나 세션의 본인(본문에 사람 칸 없음) · POST 는 **비밀번호 재확인 15분 창** 안이어야 한다(핸들러가 묻는다 — 관문 [3-2] 와 같은 403 모양) |
| 요청 | GET 없음 · POST `{"confirm":true}` 한 칸만(그 밖 키·값 400) |
| 성공 | GET 200 `{request:{id,status,requested_at,processed_at}|null}`(대기 · 처리됨만) · POST 201 `{request:{…, status:"pending"}}` |
| 실패 | 재확인 없음 403 `{error, next:"reauth"}` + 감사 `auth.gate|deny|reauth_required` · 이미 열린 요청 409 + 감사 `account.delete_request|deny|already_requested` · 형식 400 · CSRF 403 · 로그인 없음 401 |
| 하지 않는 것 | 요청만으로는 아무것도 지우거나 막지 않는다 — 관리자가 처리할 때까지 그대로 쓴다 |

실제 응답(격리 틀 `tests/qf13_retention_http.py` · 합성 계정, 2026-10-07):

```
POST /tbox/api/me/delete-request {"confirm":true}   (재확인 전)
→ 403 {"error":"탈퇴를 요청하려면 비밀번호를 다시 확인해야 합니다","next":"reauth"}
POST /tbox/api/me/reauth {"password":"…"} → 200
POST /tbox/api/me/delete-request {"confirm":true}
→ 201 {"request":{"id":1,"status":"pending","requested_at":"2026-10-07 08:27:19.447393+09","processed_at":null}}
POST /tbox/api/me/delete-request {"confirm":true} → 409 {"error":"이미 탈퇴를 요청했습니다. 관리자가 처리할 때까지 기다려 주세요."}
```

### 24-2. 탈퇴 요청 목록 · 처리 — `GET /tbox/api/admin/deletion-requests` · `POST /tbox/api/admin/deletion-requests/{번호}`

| 항목 | 값 |
|---|---|
| 인증 | 세션 · 정책 `role:Admin` — **재인증 관문 [3-2]** 가 자동으로 붙는다(15분 창 밖이면 403 `next:"reauth"`) · POST 는 `X-CSRF-Token` |
| 요청 | GET 없음 · POST `{"confirm":true}` 한 칸만 · `{번호}` 는 요청 번호(`[0-9]{1,9}`) |
| 성공 | GET 200 `{requests:[{id,user_id,username,display_name,status,requested_at,processed_at,purge_after,purged_at,processed_by_name,remaining:{personal_pages,memory_posts,travel_plans}|null}], purge_days:30}` — 대기 · 처리됨 전부 + 90일 안에 파기된 것 · `remaining` 은 열린 요청만(인계할 것 확인용) · POST 200 `{request:{id,status:"processed",requested_at,processed_at,purge_after}}` |
| 처리하는 것(한 트랜잭션) | 계정 비활성 · 비밀번호를 아무도 모르는 값의 Argon2id 해시로 · 2단계 비밀값 지움 · 세션 전부 끊기 · 지문·얼굴 열쇠 해제(열쇠마다 감사 `auth.passkey_revoke|allow|deactivate`) · 개인 토큰 회수 · 로그인 링크 · 일회용 문제 · ChatGPT 연결 지움 · 휴대폰 경보 받기 해지 · 요청 `processed`(처리 때는 DB 트리거가 now() 로 고정) · 감사 `account.delete_process|allow`(detail `request_no` · `sessions_revoked` · `device_keys_revoked` · `program_keys_revoked` · `purge_after_days`) |
| 실패 | 없는 번호 404 `not_found` · 대기 아님 409 `not_pending` · 자기 자신 400 `self_change` · 마지막 활성 관리자 400 `last_admin`(문구는 `Http::reasonMessage`) · 재인증 403 · Admin 아님 403 |

```
POST /tbox/api/admin/deletion-requests/1 {"confirm":true}   (재확인 전) → 403 {"error":"관리 기능을 쓰려면 비밀번호를 다시 확인해야 합니다","next":"reauth"}
GET  /tbox/api/admin/deletion-requests
→ 200 {"requests":[{"id":1,"user_id":8,"username":"qf13r_member","display_name":"합성 qf13r_member","status":"pending",
       "requested_at":"2026-10-07 08:27:19.447393+09","processed_at":null,"purge_after":null,"purged_at":null,"processed_by_name":null,
       "remaining":{"personal_pages":3,"memory_posts":1,"travel_plans":1}}, …],"purge_days":30}
POST /tbox/api/admin/deletion-requests/1 {"confirm":true}
→ 200 {"request":{"id":1,"status":"processed","requested_at":"2026-10-07 08:27:19.447393+09","processed_at":"2026-10-07 08:27:20.730378+09","purge_after":"2026-11-06 08:27:20.730378+09"}}
POST /tbox/api/admin/deletion-requests/<자기 요청> {"confirm":true} → 400 {"error":"자기 자신에게는 쓸 수 없습니다 — 내 계정 화면을 이용하세요"}
```

### 24-3. 30일 뒤(배치) · 보관 기간(배치) — 창구 없음

| 무엇 | 결과 |
|---|---|
| 탈퇴 처리 30일 뒤 | 아이디 `deleted-<번호>` · 표시 이름 `탈퇴한 사용자 #<번호>` · 개인 공간 이름 `탈퇴한 사용자 #<번호> 의 공간` · 개인 공간 문서 내용 삭제(게시판에 게시한 글 제외 — 행은 「영구 삭제된 페이지」) · 사진 기록 전부(사진 파일 포함) · 여행 계획 · 팔로우 · 공유 링크 · 받은 알림 · 남은 패스키·토큰·세션 행 삭제 · 요청 `purged` · 감사 `account.purge|allow`. 건너뜀: 다시 켠 계정 `account.purge|error|still_active` · 아이디 충돌 `…|name_conflict` |
| 남는 것 | 팀 공간 · 게시판 글 · 댓글 · 자료실 파일 — 작성자 칸이 같은 사람이라 화면에 `deleted-<번호>` / `탈퇴한 사용자 #<번호>` 로 보인다. 예: 게시판 글 `GET /community/api/pages/{id}` → `"writer":{"username":"deleted-8","display_name":"탈퇴한 사용자 #8"}` |
| 보관 기간 | 1:1 메시지(모아 · 커뮤니티 대화 창) 보낸 날부터 1년 · 모아 AI 대화 · AI 도구 · 커뮤니티 GPT · 커뮤니티 AI 입력·출력 1년 · 지운 사진 기록(파일까지) · 여행 계획 30일 · 감사 기록 3년 — 감사 `privacy.purge|allow`(detail `counts` = 종류별 건수 · `files` · `accounts`) · 실패 `privacy.purge|error|purge_failed` |

## 추가 규칙 (API 를 새로 적을 때)

1. 장 번호를 이어서 붙이고 제목은 `## N. 이름 — 메서드 경로`.
2. 표에 **인증 · 요청 · 성공 · 실패** 네 줄은 반드시 적고, 그 아래 `curl` 예시와 **실제로 받은 응답**을 붙인다(추정 응답을 적지 않는다).
3. 응답에 넣지 않는 것(정찰 재료·오류 원문)이 있으면 「넣지 않는 것」 줄로 밝힌다.
4. `PRD.md` 8장 API 표에 같은 행이 있어야 한다 — 문서가 먼저, 구현이 다음.

> 예시 응답은 2026-09-15 에 검토 계정 `qa_employee01` 로 서버 안 검토 포트에서 실제로 받은 값이다(업로드한 시험 파일은 곧바로 휴지통으로 보냈다).

## 모아 회원 초대·시작 안내·웹 메신저 (2026-10-02)

아래 API는 **브라우저 세션 전용**이다. 기존 X-API-Token 허용 범위를 넓히지 않는다.

| 경로 (`/tbox` 뒤) | 용도 | 인증 |
|---|---|---|
| GET `/join` | 초대 가입 화면. 링크 `#invite=<code>` 자동 입력 | 공개 |
| POST `/api/register` | `{invite,username,display_name,password,agree_terms,age14}` → 가입·세션·OTP 안내. **Q:F-13 P1**: `agree_terms`·`age14` 둘 다 JSON `true` 가 아니면 400 「이용약관 동의와 만 14세 이상 확인이 필요합니다.」·계정 0 — 성공하면 같은 트랜잭션에 동의 줄 셋(23장) | 전용 가입 CSRF |
| GET `/welcome` | OTP 등록·비서 설치·기능별 토큰 발급 안내 | 세션, 관문 중 허용 |
| GET/POST `/api/invites` | 초대 목록 / `{label,team_id,max_uses,days}` 발급 | 관리자·재인증·CSRF |
| DELETE `/api/invites/{num}` | 초대 회수 | 관리자·재인증·CSRF |
| POST `/api/member-installer` | `{url}` HTTPS 설치 주소, 빈 문자열은 해제 | 관리자·재인증·CSRF |
| GET `/messages` | 웹 1:1 메신저 — 넓은 화면용 페이지(Q:F-11 뒤에도 남는다 · 떠 있는 창과 같은 모듈이 「붙박이」로 돈다 · PC 도우미 loopback 은 이 페이지만) | 구성원 세션 |
| GET `/api/messages?q=` | 대화 가능 구성원·미읽음 수. Q:F-11: 떠 있는 창이 닫혀 있을 때 안 읽은 수 배지도 이 응답의 `unread` 합으로(30초 · `X-Qaredi-Background: 1`) | 구성원 세션 |
| GET `/api/messages/{num}?before=` | 나와 해당 구성원의 최근/이전 메시지 60개 | 구성원 세션 |
| POST `/api/messages/{num}` | `{text, request_id[, attachment_id]}` 전송, 최대 4,000자. **Q:F-11(지시서 A)부터 `request_id`(UUIDv4) 필수** — 같은 사람·같은 `request_id` 는 기존 행을 돌려주고(200 `{ok,id}`), 상대나 본문이 다르면 **409** 「전송 내용을 확인하고 새로 보내 주세요.」(커뮤니티 창이 쓰던 규칙). 없거나 모양이 틀리면 400 | 구성원 세션·CSRF |
| PATCH `/api/messages/{num}` | `{through}` 지정 메시지까지 수신 메시지 읽음 처리 | 구성원 세션·CSRF |

`/api/me`에는 `member_id`(본인 번호)가 추가된다. 비서 설치 파일은 별도 배포 주소가 있어야 다운로드할 수 있다. 웹 화면은 PC 프로그램 설치 완료나 연결 성공을 자동 판정하지 않는다.

## 모아 메신저 AI (2026-10-02)

- `GET /api/messages/ai`: 로그인한 구성원의 AI 대화 기록. `thread` 생략=최근 대화, `0`=새 대화 화면, 양수=본인 대화. `before`는 이전 turn id. 최근 30개 질문/답변, `has_more`; 본인 대화 선택 목록 최근100개. 다른 사용자 id를 받지 않는다.
- `POST /api/messages/ai`: `{text, thread_id, request_id}`. thread_id는 신규일 때null, request_id는 브라우저가 생성한UUIDv4. 세션·CSRF·구성원 권한 필수. 요청UUID는 사용자별 고유하므로 재전송은 기존 turn을 반환한다. 완료/실패200, 처리중202; 실제 상태는 `turn.state`. 사용 한도429, 본인 외 대화404.
- `GET/POST /api/member-ai-settings`: `user.manage`+재인증. POST `{api_key, enabled, user_daily, site_daily}`; 빈키는기존유지. 키원문조회없음, configured만반환. 모델고정`gemini-3.5-flash`.
- X-API-Token/tbox-buddy 토큰으로 이 API를 호출할 수 없다. 이번 범위는 웹 메신저의 AI 대화다.

### 모아 메시지 AI 도구 (2026-10-02)

- `GET /api/messages/assist?peer=<user_id>[&request=<UUIDv4>]`: 로그인 사용자·현재 상대에 묶인 최근 도구 결과 8건과 요청 상태, 남은 공통/이미지 한도.
- `POST /api/messages/assist`: 정확한 JSON `{request_id,peer_id,action,source_id,text,language,aspect}`. action=polish/spelling/translate/image, language=ko/en/ja/zh/vi/es, aspect=1:1/16:9/9:16. source_id는 번역만 허용하며 서버에서 참여자 범위를 확인해 원문을 조회한다. 일반 text는 1~4,000자. 동일 사용자+request_id 중복 억제. 결과는 요청자에게만 제공한다.
- `GET /api/messages/attachments/<UUID>`: 생성자는 미리보기 가능, 전송 후 해당 메시지 참여자만 이미지에 접근 가능. UUID는 권한을 대체하지 않는다. 캐시 금지·SHA-256 확인 후 PNG/JPEG 인증 스트림.
- 기존 `POST /api/messages/<user_id>`에 선택적 `attachment_id`(UUID/null) 추가. 완료한 본인 이미지와 해당 상대 일치 검증. 첨부만 보낼 때 기본 본문 표시, 이미지 재전송 중복 억제. GET 메시지에 ai_attachment_id/image_url 추가.
- 관리자 `/api/member-ai-settings`는 image_enabled/image_user_daily(1~20)/image_site_daily(1~200) 추가. 기존 4필드 요청은 이미지 설정을 보존한다. 기본 false/3/20.
- 모두 기존 세션·CSRF·가입 완료 관문 및 메신저 권한을 적용. Guest/역할 보기/일반 파일 API 토큰으로 우회 불가.

### 커뮤니티 대화 창 창구 — 제거·휴면 (사이트 Q:F-11 · 사용자 결정 2026-10-06)

`src/community/routes.php` 의 메신저 창구 8개(정책 `community.write`, 이 문서에는 적힌 적이 없었다)는 모아 메신저가 공용 창이 되면서 다음과 같이 된다. 표 `community.conversations` · `community.messages`(운영 0건) · `community.gpt_turns` 는 **그대로 둔다**(삭제는 별도 사용자 결정).

| 창구 | 처리 |
|---|---|
| `GET /community/api/messenger` · `GET …/messenger/members` · `POST …/messenger/conversations` · `GET …/messenger/conversations/{id}` · `POST …/conversations/{id}/messages` · `POST …/conversations/{id}/read` | **제거**(라우트 표에서 지움 → 404). 대화는 `/tbox/api/messages*` 하나로 |
| `GET /community/api/messenger/gpt` · `POST /community/api/messenger/gpt` | **휴면** — 라우트·핸들러·`Messenger::gpt*` 는 남고 화면에서 부르는 곳이 없다. 키가 없어 지금처럼 503(「GPT 연결이 아직 설정되지 않았어요」). 되살리거나 지우는 것은 별도 사용자 결정 |


### 개인 ChatGPT 수동 전달 (2026-10-02)

새 서버 API는 없다. 메신저 AI 모달에서 공용 Gemini/PC 로컬 비서/외부 ChatGPT 웹을 선택한다. 로컬 경로는 원문만 복사하고 결과를 직접 붙여 넣어 미리보기·명시적 적용·별도 전송한다. 파일 API 토큰이나 assistant.run 권한은 메신저 호출 권한이 아니다. 기존 브라우저 세션·CSRF·참여자 검사를 유지한다.

후속 loopback API는 아직 제안이며 호출할 수 없다. JSON v1 계약, 독립 페어링, 만료/중복 처리 및 Windows 구현 지시서는 `docs/tbox-buddy-bridge-prompt.txt`. OAuth callback을 작업 수신기로 쓰지 않는다.


## 비서 실행 MVP — 2026-10-01 (검증 사본)

비서 API 확장(운영 미적용): docs/assistant-minimum-contract.md를 계약 기준으로 한다. POST/GET runs, GET 단건/events, POST cancel/confirm. 독립 assistant.run만 허용. 기존 토큰 범위 불변.

## 스레드 Gemini 글쓰기 (2026-10-03, v2.45.0 운영 반영)

- `GET /community/api/memories/ai`: 모아 Gemini의 `ready`, `model`, 미설정 `notice`. 키 값은 반환하지 않는다.
- `POST /community/api/memories/{id}/caption`: 정확한 JSON `{request_id, revision, media_ids, tone, wish, consent}`. consent는 true, request_id는 UUID, revision은 현재 정수 판, wish는 1~500자다. tone은 화면의 네 분위기 값. 현재 글의 JPG/PNG/WebP ID를 0~4개 선택한다(한 장10MiB/합계12MiB). 결과 `{state, text, error, model, photo_count, revision}`, pending이면 202, complete/failed면 200이다. 게시글은 수정하지 않는다.
- 세션·CSRF·community.write·모아 메신저 자격(역할 보기 제외)·글 소유권 적용. 남의 공개 글도 생성 불가. 일반 파일/커뮤니티 글쓰기 API 토큰을 허용하는 표는 넓히지 않는다.
- 같은 사용자/요청 UUID/참조 입력은 기존 AI 결과로 중복을 막는다. 새 내용으로 같은 UUID를 사용하면 409. 판 충돌409, 부적격 첨부404/400, 사진 용량413, 공통 AI 한도429, 미설정503. 초안 확인 후 기존 게시글 PATCH로 저장한다.
- 서버 적용과 보관 범위: `docs/thread-gemini-writing-2026-10-03.md`.


### 2026-10-03 사진과 글의 이야기 구성

POST /community/api/memories/{id}/caption에 선택적 format=story 추가. 최대10장의 번호별 참조 이미지와 최초 메모로 title/intro/scenes 구조를 생성하며 story/suggested_story/order_reason/text를 반환. 기본 scene 순서는 입력 사진 순서를 보존하고 제안은 별도다. 생성 결과는 게시하지 않는다. POST/PATCH memories의 story는 null 또는 {title,intro,scenes:[{media_ids,title,text}]}이며 현재 첨부의 중복 없는 부분집합/순서를 검증한다. 생략 시 기존 story 유지, 충돌409. 공개 응답도 저장된 story만 반환하고 원본은 소유자만 조회한다.

## 회원 경험 개선 후보 — 팔로우·여행 (2026-10-03, 서버 검증 대기)

웹 세션 API는 CSRF와 기존 커뮤니티 관문을 따른다. 소셜은 프로그램 토큰에 열리지 않는다.

| 경로(`/community` 접두) | 메서드 | 기능 |
|---|---|---|
| `/api/memories/social` | GET | `profile`, `list=following/followers/members`, `q`, `offset`로 프로필/관계 목록 |
| `/api/memories/follow` | POST/DELETE | `{username}` 팔로우/해제 |
| `/api/memories/preferences` | PATCH | `{default_visibility}` 새 글의 기본 공개 범위 |
| `/api/me/guide` | GET/PATCH | 본인의 동의형 사용 안내 상태(세션 전용) |
| `/api/travel-plans` | GET/POST | 본인 계획 목록/생성 |
| `/api/travel-plans/{id}` | GET/PATCH/DELETE | 본인 계획 읽기/판 번호 기반 수정/소프트 삭제 |
| `/api/travel-plans/{id}/records` | POST/DELETE | 본인 글을 일정 또는 여행 전체에 연결/해제 |

기록 저장은 `visibility=private/followers/community/shared`와 boolean `discoverable`을 받는다. 발견 게시에는 community 또는 shared가 필요하다. 목록 `view=feed`는 본인+팔로잉, `view=discover`는 명시 게시된 공개 글, `view=mine`은 본인 기록이다. 응답 `next_cursor`를 다음 요청의 `cursor`로 보낸다. 프로필·검색·열람자가 달라지면 커서를 재사용하지 않는다. 기존 링크 capability는 shared만 계속 허용한다.

여행 GET/POST/PATCH 및 기록 POST는 `X-API-Token` 개인 토큰도 받는다. GET은 `travel.read` 또는 `travel.write`, 쓰기는 `travel.write`가 있어야 한다. `travel.write` 발급은 `travel.read`를 포함한다. 기존 문서/파일/assistant.run 토큰에는 여행 권한이 생기지 않는다. 여행 DELETE는 웹 세션 전용이다. API 전체 계약과 MCP 도구 이름은 `clients/mcp` 구현·README를 함께 확인한다. 사진 자체의 게시글 읽기/수정 MCP는 이번 여행 도구와 별개다.


## 원격 MCP OAuth와 도구 — 2026-10-04 후보

프로그램 토큰 API와 별도이다. 기존 X-API-Token 또는 세션 쿠키로 `/mcp`를 사용할 수 없으며, MCP access token도 기존 파일/관리 API의 권한으로 바뀌지 않는다.

| 창구 | 용도 |
|---|---|
| `POST /mcp` | Bearer 인증 후 MCP JSON-RPC, stateless Streamable HTTP |
| `GET /.well-known/oauth-protected-resource/mcp` | RFC9728 resource와 authorization server 검색 (root alias 제공) |
| `GET /.well-known/oauth-authorization-server` | RFC8414 authorization metadata |
| `/tbox/oauth/register` | exact redirect allowlist 안의 동적 client 등록 |
| `/tbox/oauth/authorize` | Authorization Code + PKCE S256 시작, 모아 로그인·동의 |
| `/tbox/oauth/token` | resource/client/PKCE 묶인 일회 code 교환, refresh 회전 |
| `/tbox/oauth/revoke` | 연결 자격 회수 |
| `/tbox/mcp-connect` | 사람의 연결 안내·승인·관리 화면 |

OAuth 범위: `memories.read`, `photos.read`, `memories.write`, `travel.read`, `travel.write`. 쓰기는 해당 읽기를 포함하고 사진은 별도 승인한다. tool 목록은 승인한 범위에 맞춰 내려가며 호출할 때 다시 검사한다. 본인 글 목록/읽기/비공개 생성/내용 수정/사진 읽기와 여행 목록/읽기/저장/기존 기록 연결을 제공한다. 공개/삭제/다른 회원 조회는 제공하지 않는다. 실제 도구 input schema는 `tools/list`가 진실이며 수정에는 현재 revision이 필요하다.

일반 글·사진과 도구 결과의 문자열은 자료이며 실행 지시로 신뢰하지 않는다. 글 저장 전에 결과를 보여 주고 사용자 의사를 확인한다. 사진을 제공한 적 없이 사진을 봤다고 하지 않는다. 모아는 이 경로에서 OpenAI API를 호출하지 않는다. 설정과 실제 ChatGPT 등록 검증은 `docs/chatgpt-remote-mcp.md`와 작업 인계를 따른다.

## 이야기 피드·댓글 조회 보완 — 2026-10-08

`GET /community/api/memories` 및 공유 피드의 `include_stats=0|1`(기본 1): 0이면 통계 집계를 실행하지 않고 `stats:null`을 반환한다. UI는 프로필 첫 조회에서만 통계를 요청하고, 이후 페이지에서는 이미 받은 통계를 유지한다. 공개 범위 판정은 매 요청 다시 한다. 일반 피드는 `(created_at,id)` 커서와 같은 정렬, 발견 피드는 기존 `(feed_rank,created_at,id)` 순서를 유지한다.

`GET /community/api/memories/{id}/comments?before=<next_cursor>`: 최대 30개, 페이지 안에서는 오래된 순으로 반환한다. 응답은 `{comments:[...], next_cursor:string|null}`. 커서는 해당 글에만 사용할 수 있고 매 요청 글 접근 권한을 검사한다. POST 응답도 최신 30개와 이전 댓글 커서를 반환한다. 같은 시각 댓글은 UUID로 순서를 확정한다. 기본 메신저 페이지의 제한 계정 응답은 403 HTML 안내이고, 메신저 API는 기존 403 JSON을 유지한다.

발견 정렬은 점수 가산폭(최대 21599초)을 이용해 후보 시간 범위를 제한한다. 커서·현재 공개 범위·정렬 의미는 유지하며, 최신 대상 N개의 최저 시각에서 21600초 이전의 행은 계산 후보에서 제외한다. N은 커서 사용 시 21, OFFSET 호환 시 offset+21이다.

### PC 비서 Gemini · 설치본 배포함 (2026-10-09)

`GET|POST /tbox/api/assistant/chat`는 개인 `assistant.run` 토큰 전용이다. 기존 `/api/assistant/runs` 읽기 도구와 별개이며 공용 Gemini 설정·한도·본인 동의를 재사용한다. 요청/응답/오류/타임아웃 복구의 정확한 계약은 `docs/work-items/buddy-server-contract.md` 「PC Gemini 계약 v1」. Google 키나 GPT 자격을 PC/웹 사이에 전달하지 않는다.

배포함은 브라우저 세션 전용이다. `GET /tbox/programs` 화면, `GET /tbox/api/programs`는 현재 사용자의 파일 읽기 권한에 맞는 현재 설치본 목록을 반환한다. 미게시/검사 미완료/권한 밖이면 빈 목록. 응답 `{programs:[{id,name,version,sha256,size_bytes,access_level,published_at,uploaded_by}]}`.

`POST /tbox/api/programs`: 관리자만, CSRF 필수. JSON 정확히 `{file_id,version,sha256,level,team_id,password,otp,ack:true}`. 먼저 기존 웹 `/api/files`의 F-65 확인 절차로 본인이 root/admin-only에 올린 실행 ZIP이어야 한다. 검사 clean+해시 일치 감사 기록·원본 해시를 확인하고 매 요청 비밀번호·OTP를 검사한다. 정책 file.upload 뒤 `Authz::canUploadExec(user,'admin-only')`로 관리자/비대행/비토큰을 판정한다. F-65와 같이 재인증 시간을 열지 않는다. level은 public/internal/team/admin-only, team_id는 team일 때만 정수이고 나머지는 null. 버전은 영문·숫자로 시작하는 1~64자의 영문/숫자/`.`/`_`/`+`/`-`. 성공201 `{ok:true}`. 새 판 게시가 이전 판을 삭제하지 않는다. 최초 제품은 tbox-buddy 하나다.

`POST /tbox/api/programs/{uuid}/download`: CSRF와 `{ack:true}` 필수. 현재 판·본인 파일 읽기 권한·clean·SHA256 대조 후 attachment로 스트림한다. 받는 사람에게 OTP를 요구하지 않는다. 공유 링크·API 토큰·일반/실행 파일 받기로 우회할 수 없다. 권한403, 이전판/없는파일404, 검사미완료423, 해시불일치500. 다운로더는 오류 JSON을 ZIP으로 저장하지 않는다.


### 2026-10-10 GPT 간편 연결

GPT 간편 연결: 같은 Windows PC의 비서에 로그인한 뒤 웹에서 GPT 연결을 눌러 한 번 승인한다. 브라우저 연결을 최대 30일 기억하고 이후 GPT 선택 때 재연결한다. 원문은 GPT로 처리 버튼을 눌러야 전송되며 결과는 미리보기로만 돌아온다. PC 비서 실행 링크와 구버전 코드 연결을 제공한다. 휴대폰에서 다른 PC로 원격 연결하는 기능은 아직 없다.
상세: `docs/work-items/gpt-easy-connect.md`. DB/서버 API 변경 없음. Windows 앱 프로토콜/로그인 실검증은 별도.


## 회사·모임 제품 공간 — 2026-10-11
세션 인증된 `/tbox/api/product-spaces` 및 `/tbox/api/me/{home-dashboard,product-home}`는 기존 동의·첫 로그인·CSRF 관문을 적용한다. 프로그램 토큰 범위를 확대하지 않는다. 공간 역할·초대·소유권 재인증·설정 revision·기록/다운로드 계약은 [제품 공간 API](docs/product-spaces-api.md)를 따른다. 기존 community `/api/spaces` 문서 컨테이너와 별개이다.


개인 홈 v3 선택 shortcuts배열(2026-10-11): 고정서비스ID 최대8·중복금지·순서보존, 없음=기본/[]=숨김. 공동/v1/v2거부·기존revision/CSRF그대로. 상세 docs/product-spaces-api.md, docs/compact-home-directive.md.
