# Q:F-8 조각 C — 구현 기록 (Opus · 2026-09-28)

지시서 `docs/qf8-ai-writer-directive.md` 「조각 C」·「공통」. 작업 자리 worktree `/home/user/qaredi/qf8`(wip/qf8).
고친 곳은 `clients/mcp/**`(새 폴더)와 `deploy/DEPENDENCIES.md`(5.5절 새로) 둘뿐. 서버 코드(src·public·db·tests)는 읽기만 했다.

## 1. 변경 파일

| 파일 | 줄 | 무엇 |
|---|---|---|
| `clients/mcp/pyproject.toml` | 24 | 패키지 `qaredi-mcp` 0.1.0 · 의존 `mcp==1.12.4` 하나 · 명령 `qaredi-mcp` · 스키마 사본을 패키지 데이터로 |
| `clients/mcp/README.md` | 98 | 토큰 발급 · 설치(pipx/pip -e) · Claude Desktop/Code 설정 · 오류표 · 시험 |
| `clients/mcp/qaredi_mcp/__init__.py` | 3 | 버전 |
| `clients/mcp/qaredi_mcp/markdown_doc.py` | 878 | Markdown ↔ 블록 문서 변환기(표준 라이브러리만) |
| `clients/mcp/qaredi_mcp/client.py` | 238 | 창구 넷 HTTP 클라이언트(urllib) + 도구 흐름 |
| `clients/mcp/qaredi_mcp/server.py` | 103 | FastMCP stdio 서버 · 도구 셋 |
| `clients/mcp/qaredi_mcp/doc-schema.json` | 89 | `public/assets/community/doc-schema.json` 사본(바이트 같음 — 시험이 대조) |
| `clients/mcp/tests/test_markdown_doc.py` | 284 | 변환기 단위 시험 35 |
| `clients/mcp/tests/test_client.py` | 231 | 클라이언트 시험 15(가짜 창구 127.0.0.1:8404) |
| `clients/mcp/tests/test_server.py` | 156 | 도구 시험 5(`mcp` 없으면 최소 흉내로 import) |
| `clients/mcp/tests/validate_with_php.sh` | 132 | 변환 결과 → 저장소 `Doc::validate` |
| `clients/mcp/tests/samples/01~12_*.md` | — | 표본 12 |
| `clients/mcp/IMPLEMENTATION.md` | — | 이 기록 |
| `deploy/DEPENDENCIES.md` | +15 | 5.5절 「PC 클라이언트 — `mcp`」 |

## 2. 설계 요지

- **모양은 Doc.php 를 읽고 맞췄다.** 조각 `{"t","m"}`(`m` 은 비어도 `{}`) · 마크 `bold/italic/strike/code: true` · `link: {"href"}` ·
  블록 필수 키(`to_do.checked` · `code.language` · `table.header_row/header_col/rows[].cells[].text`) · 선택 키는 넣지 않음(`color`·`align`·`size`·`start`·`toggle`·`caption`).
  id 는 `uuid.uuid4()`(Doc.php 정규식 `-4…-[89ab]…`).
- **Doc.php 에서 찾은 함정과 대응**: 조각 글자에 **탭도 금지**(`[^\P{Cc}\n]`) → 탭은 빈칸, 그 밖 Cc(C1 포함) 제거 · 코드는 `\n`·`\t` 만 허용 → 그 밖 Cc 제거 ·
  링크 규칙(`schemeOk`)은 PHP 가 `/u` 없이 **바이트**로 돈다 → 파이썬도 bytes 정규식 · 길이 2048 **바이트** · `mailto` 앞뒤 1~128 바이트.
  허용되지 않는 주소(상대 주소·`javascript:`)는 링크를 걸지 않고 「글자 (주소)」로 남긴다.
- **상한**: 조각 2000자 넘으면 나눔(코드 포인트 — iconv_strlen 과 같은 단위) · 조각 100/블록 · 블록 3000(자식 포함) · 깊이 10 · 표 200행(머리 포함)·20열 · 코드 50000자 · 문서 1MB →
  `DocLimitError(limit=<schema limits 이름>)`, 이때 **요청을 보내지 않는다**. 오류 문장에 본문을 넣지 않는다(처음엔 조각 상한 문장에 본문 미리보기 30자를 넣었다가 금지 패턴이라 뺐다).
- **모르는 문법은 글자로**: HTML 태그·각주·7개 `#` 는 문단 글자 · 이미지 `![alt](url)` 은 alt 에 링크 마크 · 표의 넘치는 칸은 마지막 칸에 ` | ` 로 이어 붙임(행마다 칸 수가 같아야 한다 — `ragged_row`).
- **`---` 는 언제나 구분선**(문단 바로 뒤여도 setext 제목으로 읽지 않는다 — 지시서 규칙 그대로).
- **클라이언트**: 주소는 코드 상수 넷 + 검사한 UUID 로만 만든다(읽기 도구의 id 는 UUID 또는 `…/community/p/<uuid>` 주소만) · 3xx 는 따라가지 않고 오류 ·
  TLS 기본 검증 · `QAREDI_URL` 은 https(이 PC 안만 http)·호스트만 · 토큰은 `QAREDI_TOKEN` 에서만, 보이는 ASCII 8~512자(헤더 주입 차단) · repr·오류 문장에 없음 ·
  응답 4MB 상한 · 오류 문장은 서버의 `error`·`reason`·검증 오류 `path:reason`(10개까지)만 옮긴다.
- **write 흐름**: 변환(상한 검사) → `GET /community/api/me/space`(멱등 — 409 예방) → `POST /community/api/pages {title, doc}`(키 둘뿐) → `{id, url, title, visibility, space{kind,name}, blocks}`.
  제목은 미리 막지 않는다(서버 400 을 그대로 — 지시서).
- **server.py**: 표준출력에 찍는 코드 0(`print` 0 — grep 확인). 클라이언트는 첫 호출 때 환경 변수를 읽는다(설정이 틀려도 서버는 뜨고 도구가 까닭을 말한다). 실패는 `ToolError`.

## 3. 실행한 명령과 출력 원문

```
$ cd clients/mcp && python3 -m unittest discover -s tests
.......................................................
----------------------------------------------------------------------
Ran 55 tests in 1.616s

OK
```

```
$ bash clients/mcp/tests/validate_with_php.sh
== 문서 단계 COMMUNITY_DOC_STAGE=7 (bootstrap.php 에서 읽음) · 검증기 /home/user/qaredi/qf8/src/community/Doc.php
INFO long_paragraph: 4500자 문단 → 조각 3개 · 길이 [2000, 2000, 500]
OK  blocks_3001: 변환기 오류 limit=blocks — 블록이 3000개를 넘습니다(지금 3001개) — 글을 나눠 여러 페이지로 올리세요
OK  depth_11: 변환기 오류 limit=depth — 목록·인용이 10단계보다 깊게 겹쳤습니다(지금 11단계) — 들여쓰기를 줄이세요
OK  table_21cols: 변환기 오류 limit=table_cols — 표의 열이 20개를 넘습니다(지금 21개)
OK  table_201rows: 변환기 오류 limit=table_rows — 표의 행이 200개를 넘습니다(머리 행 포함 지금 201개) — 표를 나누세요
OK  segments_101: 변환기 오류 limit=segments_per_text — …
OK  doc_bytes_over: 변환기 오류 limit=doc_bytes — 문서가 1048576바이트(1MB)를 넘습니다(지금 1079981바이트) — 글을 나누세요
OK  code_chars_over: 변환기 오류 limit=code_chars — 코드 블록 하나가 50000자를 넘습니다(지금 50001자) — 코드를 나누세요
INFO 변환 32건 · 변환기 상한 확인 실패 0건
OK   01_headings … 12_edge (각각 + 「~왕복」 블록→Markdown→블록) 24건 전부 결과=ok
OK   long_paragraph_4500      기대=pass                   결과=ok
OK   long_bold_3000           기대=pass                   결과=ok
OK   control_chars            기대=pass                   결과=ok
OK   blocks_3000              기대=pass                   결과=ok (최상위 블록 3000)
OK   blocks_3001_forced       기대=fail:too_many_blocks   결과=fail:too_many_blocks (최상위 블록 3001)
OK   depth_10                 기대=pass                   결과=ok
OK   table_20x200             기대=pass                   결과=ok
OK   negative_control_tab     기대=fail:control_char      결과=fail:control_char
== Doc::validate 결과: 통과 32 · 실패 0
```
(표본 12: 제목 셋·넷째·일곱 개 / 글머리 목록 겹침 / 번호 목록 겹침·`3)` / 할 일 / 인용·인용 안 목록 / 코드 5종(언어 별칭·모르는 언어·틸드·닫히지 않은 펜스) /
구분선 셋 / 표(정렬·코드 속 `\|`·모자란 칸·넘치는 칸) / 인라인 마크 / 링크 전 종류·이미지·HTML·각주 / 섞인 글 / 가장자리.)

```
$ bash scripts/security_lint.sh | tail -1
결과: 통과 (금지 패턴 0건)
$ python3 -m py_compile qaredi_mcp/*.py tests/*.py && echo py_compile-ok
py_compile-ok
$ pip wheel --no-deps --no-build-isolation .   (scratchpad venv — 배포판 setuptools 는 install_layout 오류라 venv 의 setuptools 79.0.1 로)
Successfully built qaredi-mcp   → 휠에 doc-schema.json 포함 확인 · 설치본에서 markdown_to_doc 동작 · 명령 qaredi-mcp 생성
$ qaredi-mcp </dev/null
ModuleNotFoundError: No module named 'mcp'      ← 이 문맥에 mcp 가 없어서(아래 4)
$ curl -s -o /dev/null -w '%{http_code}' https://pypi.org/simple/mcp/
403
```

## 4. 못 한 것과 이유

- **`mcp` 패키지 설치·진짜 FastMCP 로 서버 기동 — 못 함.** pypi.org·files.pythonhosted.org 가 403. 그래서 `server.py` 는 `py_compile` 과
  `test_server.py` 의 **최소 흉내**(FastMCP.tool 기록 · ToolError · ToolAnnotations · pydantic.Field)로만 확인했다 — stdio 전송·입력 스키마 생성은 **미검증**.
  `mcp` 가 설치된 곳에서 `test_server.py` 는 흉내 없이 진짜 `list_tools()` 로 돈다.
- **`mcp==1.12.4` 의 존재·sha256·라이선스 실측 — 못 함**(같은 까닭). DEPENDENCIES.md 5.5 에 「이 문맥에서 받지 못함 — 반영 전 채운다」로 적었다.
- **Claude Code 에 붙여 `qaredi_whoami` 답 확인 — 하지 않음**(지시서상 메인이 조각 S 반영 임시 인스턴스에서 붙여 본다).
- **조각 S 창구에 실제로 대 보기 — 하지 않음.** 가짜 창구(127.0.0.1:8404)로만. 응답 모양은 `space_me.php`·`pages_create.php`·`pages_get.php` 를 읽고 맞췄다.
- pytest 는 이 문맥에 없어 `python3 -m unittest` 로 돌렸다(시험은 pytest 로도 모이는 이름·모양).

## 5. 지시와 다르게 한 것

- 시험 파일을 둘 더 뒀다(`test_client.py` · `test_server.py`) — 금지 패턴(토큰·본문 노출 · 리다이렉트 · 창구 밖 호출)을 실행으로 보이려고. 표본은 `tests/samples/` 에 파일로.
- HTTP 는 `httpx` 대신 표준 `urllib` — 외부 의존을 `mcp` 하나로 줄이고 리다이렉트 차단·TLS 기본값을 코드에서 바로 보이게.
- 도구 결과에 `visibility`·`space{kind,name}` 를 더했다(Claude 가 「비공개 · 개인 공간」을 사람에게 말할 수 있게). 토큰·내부 번호는 없다.

## 6. 판단 대기

1. **whoami 범위** — `qaredi_whoami` 는 `GET /tbox/api/whoami`(정책 `authenticated`)를 부른다. 지금 `Authz::tokenScopeAllows` 는 `authenticated` 를
   「`TOKEN_SCOPES` 안의 모든 범위」로 허용하므로, 조각 S 가 `community.write` 를 `TOKEN_SCOPES` 에 넣으면 whoami 가 **열린다**. 조각 S 설계 1의
   「기존 정책(file.*)은 거짓」과는 부딪히지 않지만, S 가 `authenticated` 도 막으면 이 도구는 403 `token_scope` 를 받는다(도구는 그 답을 그대로 말한다). 어느 쪽인지 S 와 맞춘다.
2. **`mcp` 버전 번호** — `1.12.4` 는 이 문맥에서 확인하지 못한 값. 반영 전 PyPI 로 확인해 고정값·sha256 을 채운다.
3. **스키마 사본이 셋째 벌** — `clients/mcp/qaredi_mcp/doc-schema.json`. 저장소 안에서는 단위 시험이 원본과 대조하지만, 서버 쪽 사본 대조 시험(tbox 의 diff 시험)에는
   넣지 않았다(서버 tests 는 이 조각의 수정 범위 밖). 넣을지 메인이 정한다.
4. **문단 안 줄바꿈** — Markdown 의 줄바꿈(soft break)을 빈칸이 아니라 `\n` 로 담았다(쓴 줄 모양 그대로). 화면이 `\n` 을 줄바꿈으로 그리는지는 확인하지 않았다(미검증).
5. **번호 목록 시작 번호** — `numbered.start` 를 넣지 않는다(렌더러 `doc-render.js` 가 그 값을 쓰지 않는 것을 확인). `10.` 로 시작해도 화면은 1 부터.

## 7. 남긴 임시 자원

- 가짜 창구 서버(8404)는 시험 안에서 켜고 끈다 · validate 의 임시 폴더는 `trap` 으로 지운다 · 휠·venv 는 세션 scratchpad 에만(저장소 밖) · `__pycache__`·`build/`·`*.egg-info` 는 지웠다. DB·인스턴스 0.

## 8. 검토 반영 (독립 검토 `review-qf8-2026-09-28.md` ②절 · 메인 지시)

- **RV-QF8-2(낮음)**: `QarediClient.from_env` 가 `QAREDI_TOKEN_FILE` 을 **먼저** 본다(있으면 그것만 — 틀려도 환경 변수로 넘어가지 않는다).
  `read_token_file`: `O_NOFOLLOW` 로 열고 연 기술자로 검사(심볼릭 링크 거부 · 일반 파일만 · POSIX 에서 주인 = 나 · `mode & 077 == 0`) · 한 줄(끝 줄바꿈 하나 허용) · 1024바이트까지 · 오류 문장엔 경로만.
  README 는 파일 방식이 기본(`~/.config/qaredi/mcp-token` 0600 을 `read -rs` 로 이력 없이 만들기 · Windows 사용자 폴더 · Claude Desktop/Code 설정에 경로만) · 환경 변수 방식은 「이력에 남아 권하지 않음」 한 줄.
- **RV-QF8-3(권고)**: 토큰은 `_Secret`(repr·str = `<token>`)로 들고, 글자는 `_build_request` 한 함수에서만 꺼낸다(헤더 dict 는 이름 없는 인자로만 · 그 함수는 예외를 던지지 않는다).
  `_call` 의 요청 객체는 `_Masked`(repr `<request>`)로 감싼다. 생성자는 받은 글자를 곧바로 `_Secret` 로 바꾸고 `del`.
  시험: `traceback.TracebackException(..., capture_locals=True)` 로 401·400·302·연결 실패·모양 틀린 토큰 다섯 경우 토큰 글자 0(지역 변수가 실제로 찍혔는지도 확인).
  본문(Markdown)은 도구 입력 그 자체라 호출 프레임에 남는다 — 이 항목의 대상 밖(판단 대기로 남기지 않음 · 참고).
- 시험: `python3 -m unittest discover -s clients/mcp/tests` → 83 OK(55 → 83: TokenSecrecy 2 · TokenFile 4 · 하위 사례 포함).

## 2026-10-14 개인 여행 계획 확장

현재 클라이언트는 기존 문서 도구 네 개와 여행 도구 네 개를 제공합니다. 여행 목록·상세·생성·수정·기존 게시글 연결의 고정 경로(`/community/api/travel-plans`, `/{uuid}`, `/{uuid}/records`)만 추가합니다. 삭제·게시·공유·남의 기록 접근 도구는 추가하지 않습니다. 서버는 별도 `travel.read`/`travel.write` 범위와 매 요청의 소유자 조건을 함께 검사합니다. 기존 커뮤니티 토큰은 여행 권한이 자동으로 늘어나지 않습니다.

수정은 전체 계획과 현재 `revision`을 보내며 충돌 시 자동 재시도하지 않습니다. 생성은 `request_id`로 재시도 가능하고 새 일정은 UUID를 부여합니다. 원문·사진·공개 범위는 바꾸지 않으며, 날짜·순서·진행 상태는 자유롭게 수정합니다. 새 외부 패키지나 AI 공급자 호출은 없습니다. AI가 이 도구를 사용하려면 사용 중인 채팅 앱에 이 MCP 클라이언트를 별도로 연결해야 합니다.

검증: `python3 -m unittest discover -s clients/mcp/tests -p 'test_*.py' -q` **114개 통과**(실제 설치된 FastMCP와 localhost HTTP 모의 서버). 여행 추가 9개: 범위 오류, 수정 충돌, UUID 경로 주입 거부, 소유자·공개 필드 주입 거부, 일정 ID 보존, 본문 크기, 기록 연결. PHP/PostgreSQL·운영 HTTPS 검증은 이 클라우드 환경에서 미실행이며 `tests/community/travel_plans_http.py`를 격리 harness로 실행해야 합니다.
