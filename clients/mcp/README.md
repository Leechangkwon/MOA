# qaredi-mcp — Claude 가 Qaredi 커뮤니티에 글을 써 주는 MCP 서버

Claude Desktop · Claude Code 에서 「○○ 주제로 글 써서 올려」라고 하면, Claude 가 Markdown 으로 쓴 글을
Qaredi 커뮤니티 **내 개인 공간에 비공개 페이지**로 만듭니다(Q:F-8).
**공개·게시는 사람이 화면에서** 합니다 — 이 프로그램은 비공개 페이지를 만들고 읽는 일, 그리고 내가 볼 수 있는 페이지를 **검색**하는 일(Q:F-10)만 합니다.

- PC 에서 도는 프로그램입니다(표준입출력 stdio). Qaredi 서버에는 설치하지 않습니다.
- 부르는 창구는 다섯뿐입니다: `GET /tbox/api/whoami` · `GET /community/api/me/space` · `POST /community/api/pages` · `GET /community/api/pages/{id}` · `GET /community/api/search?q=`.
- 필요한 것: Python 3.10 이상 · 외부 패키지 `mcp`(버전 고정 — `pyproject.toml`).

## 도구

| 도구 | 하는 일 |
|---|---|
| `qaredi_whoami()` | 토큰이 누구의 것인지(계정 이름·역할). 서버가 알려 주면 이 토큰이 가진 범위 `token_scopes_held` 도 함께. 토큰 값은 돌려주지 않습니다 |
| `qaredi_search_pages(q, limit=10)` | 커뮤니티에서 **내가 볼 수 있는** 페이지를 검색(찾을 말 1~100자, 건수 1~20). 한 줄에 한 건 `제목 — 공간 — snippet — id` — id 는 `qaredi_read_page` 로 읽습니다. 범위가 없으면(403) 오류 대신 까닭 문장을 돌려줍니다 |
| `qaredi_write_page(title, markdown)` | Markdown → 블록 문서 → 내 개인 공간에 비공개 페이지. 돌려줌 `{id, url, title, visibility, space, blocks}` |
| `qaredi_read_page(id)` | 페이지(id 또는 `…/community/p/<id>` 주소)를 Markdown 으로. 고치는 창구는 없습니다 — 새 페이지로 다시 씁니다 |

Markdown 에서 되는 것: 제목 `#`~`###`(넷째 이상은 셋째로) · 문단 · 목록 `-` `*` `1.`(들여쓰기로 겹치기, 10단계까지) ·
할 일 `- [ ]` `- [x]` · 인용 `>` · 코드 펜스(언어는 서버 목록에 있을 때만, 아니면 `plain`) · 구분선 `---` · 표(200행 × 20열) ·
`**굵게**` `*기울임*` `~~취소~~` `` `코드` `` `[링크](https://…)`(https·http·mailto 만).
이미지 `![]()`·HTML 태그·각주는 **글자로** 남습니다(이미지는 링크가 걸린 글자). 상한: 블록 3000 · 문서 1MB —
넘으면 올리기 전에 어느 상한인지 말하고 멈춥니다. 2000자가 넘는 문단은 알아서 나눠 담습니다.

## 1. 토큰 발급

1. Qaredi 에 로그인 → **내 계정 → 프로그램 토큰 → 발급**.
2. 범위는 **「커뮤니티 글쓰기」**(내 개인 공간에 비공개 페이지 만들기·읽기 — 파일 창구는 열리지 않습니다).
   검색(`qaredi_search_pages`)은 **「커뮤니티 읽기」** 이상이 필요합니다 — 한 토큰에 범위를 여럿 고를 수 있으니(Q:F-10)
   글쓰기를 고르면 읽기가 함께 들어갑니다. 비서(tbox-buddy)와 같은 토큰 하나를 써도 됩니다.
3. 토큰은 **한 번만** 보입니다. **나만 읽는 토큰 파일** 하나에 넣습니다(아래). 채팅·문서·명령 줄에 적지 않습니다.
4. 만료는 발급 뒤 **180일**. 새어 나갔다고 생각되면 같은 화면에서 **회수**하고 새로 발급합니다.

**토큰 파일 만들기 (권장 · 기본)** — 토큰 한 줄만 담은 일반 파일, 권한은 나만(0600).

```bash
# Linux · macOS — 토큰이 셸 이력에 남지 않게 붙여넣기로 받는다(read -s 는 화면에도 안 보인다)
mkdir -p ~/.config/qaredi && chmod 700 ~/.config/qaredi
( umask 077; IFS= read -rs -p '토큰 붙여넣기: ' T && printf '%s\n' "$T" > ~/.config/qaredi/mcp-token; unset T ); echo
chmod 600 ~/.config/qaredi/mcp-token
```

Windows 는 사용자 폴더(예: `C:\Users\<나>\.config\qaredi\mcp-token`)에 메모장으로 한 줄 저장합니다
(사용자 폴더는 기본으로 나만 읽습니다 — 권한 비트 검사는 Linux·macOS 에서만 합니다).

이 프로그램은 `QAREDI_TOKEN_FILE` 을 **먼저** 봅니다. 그 파일이 심볼릭 링크이거나, 일반 파일이 아니거나, 내 것이 아니거나,
다른 사람이 읽거나 쓸 수 있는 권한(0600·0400 이 아님)이거나, 두 줄 이상이면 **거절**하고 멈춥니다(환경 변수로 넘어가지 않습니다).
토큰을 파일에 쓰지 않고, 로그·오류 문장에 찍지 않습니다.

> 환경 변수 `QAREDI_TOKEN` 에 토큰을 바로 넣는 방식도 되지만 **권하지 않습니다** — `claude mcp add -e QAREDI_TOKEN=…` 처럼 명령 줄에 쓰면 셸 이력·프로세스 목록에 남습니다.

## 2. 설치

```bash
pipx install ./clients/mcp          # 권장 — 따로 떨어진 환경에 설치
# 또는
pip install -e ./clients/mcp
```

설치되면 명령 `qaredi-mcp` 가 생깁니다(stdio 로 도는 MCP 서버 — 직접 켜 둘 필요 없이 Claude 가 띄웁니다).

## 3. Claude 에 붙이기

**Claude Desktop** — `claude_desktop_config.json` 의 `mcpServers` 에:

```json
{
  "mcpServers": {
    "qaredi": {
      "command": "qaredi-mcp",
      "env": {
        "QAREDI_URL": "https://changkwon.mso.so",
        "QAREDI_TOKEN_FILE": "/home/<나>/.config/qaredi/mcp-token"
      }
    }
  }
}
```

**Claude Code**:

```bash
claude mcp add qaredi -e QAREDI_URL=https://changkwon.mso.so -e QAREDI_TOKEN_FILE="$HOME/.config/qaredi/mcp-token" -- qaredi-mcp
```

설정 파일(`claude_desktop_config.json` · Claude Code 설정)에는 **파일 경로만** 들어가고 토큰 글자는 들어가지 않습니다.

붙인 뒤 「qaredi_whoami 로 내가 누군지 확인해 줘」라고 하면 계정 이름·역할이 나옵니다.
그다음 「이번 주 회의 내용을 정리해서 Qaredi 에 올려 줘」처럼 말하면 됩니다.

설정 값

| 환경 변수 | 뜻 | 기본 |
|---|---|---|
| `QAREDI_TOKEN_FILE` | 토큰 파일 경로(권장 — 위 「토큰 파일 만들기」). 있으면 이것만 본다. `~` 는 풀어 준다 | 없음 |
| `QAREDI_TOKEN` | 토큰 값을 직접(권하지 않음 — 이력에 남는다). `QAREDI_TOKEN_FILE` 이 없을 때만 | 없음 |
| `QAREDI_URL` | Qaredi 주소. 호스트(와 포트)만 — 경로·쿼리 없이. `http://` 는 이 PC 안(127.0.0.1·localhost)만 | `https://changkwon.mso.so` |

## 오류가 날 때

| 답 | 뜻 · 할 일 |
|---|---|
| `HTTP 401` | 토큰이 없거나 틀리거나 만료·회수됨 → 새로 발급 |
| `QAREDI_TOKEN_FILE …` | 토큰 파일 검사에서 멈춤 — 문장이 까닭을 말한다(권한이면 `chmod 600 <경로>`) |
| `HTTP 403 reason=token_scope` | 범위가 「커뮤니티 글쓰기」가 아님 → 그 범위로 발급 |
| `HTTP 403 …` (검색 결과 대신) | 토큰에 「커뮤니티 읽기」 범위가 없음 → 그 범위를 넣어 새로 발급 |
| `HTTP 403` / `404` (읽기) | 없거나 내가 볼 수 없는 페이지 |
| `HTTP 400 … 검증 오류 path:reason` | 서버가 문서를 받지 않음(제목 1~200자 등) — 까닭 그대로 |
| `문서 상한(blocks)` 등 | 올리기 전에 멈춤 — 글을 나눠 여러 페이지로 |
| `HTTP 3xx … 따라가지 않습니다` | 주소가 다른 곳을 가리킴 — `QAREDI_URL` 확인(리다이렉트는 따라가지 않습니다) |

## 개발 · 시험

```bash
cd clients/mcp
python3 -m unittest discover -s tests -v     # 변환기·클라이언트·도구 (pytest 로도 돈다) — mcp 없이도 돈다
bash tests/validate_with_php.sh               # 변환 결과를 저장소의 서버 검증기(src/community/Doc.php)로 확인
```

`qaredi_mcp/doc-schema.json` 은 저장소 `public/assets/community/doc-schema.json` 의 사본입니다(설치된 PC 에는 저장소가 없다).
원본이 바뀌면 복사해 맞춥니다 — 단위 시험이 두 파일이 같은지 봅니다.

## 개인 여행 계획 (선택 연결)

기존 문서 권한만 든 토큰으로는 여행 계획에 접근할 수 없습니다. 여행 기능이 배포된 서버의 **내 계정 → 프로그램 토큰**에서 `travel.read`(여행 계획 읽기), 필요하면 `travel.write`(여행 계획 저장·기록 연결)를 직접 선택해 발급하고 같은 `QAREDI_TOKEN_FILE` 설정에 넣으세요. 쓰기는 읽기를 포함합니다. 문서도 사용하려면 필요한 `community.read`/`community.write`를 별도로 선택합니다. `assistant.run` 전용 토큰과는 묶지 않습니다.

| 도구 | 동작 | 범위 |
|---|---|---|
| `qaredi_list_travel_plans` | 내 여행의 요약 목록 | `travel.read` |
| `qaredi_read_travel_plan` | 일정과 연결한 내 기록 읽기 | `travel.read` |
| `qaredi_save_travel_plan` | 계획 생성 또는 전체 계획 수정 | `travel.write` |
| `qaredi_link_travel_record` | 내 기존 게시글을 여행·일정에 연결 | `travel.write` |

계획은 항상 비공개이며, 기록 연결은 게시글의 공개 범위나 원문·사진을 바꾸지 않습니다. 수정 전 읽기 도구로 현재 내용을 읽고 `revision`과 전체 계획을 보냅니다. 유지할 일정의 `id`를 그대로 넣고, 새 일정은 `id`를 생략할 수 있습니다. 빠진 일정은 계획에서 제외되지만 연결한 글은 여행 전체에 남습니다. 날짜를 정하지 않거나 기존 여행 기간 밖으로 옮겨도 됩니다. 상태는 `planned`(예정), `done`(다녀옴), `skipped`(건너뜀)입니다. 생성 재시도에는 같은 UUID의 `request_id`를 사용하면 중복 생성을 막을 수 있습니다.

예: “내 제주 여행 계획을 읽고, 내일 비가 오면 실내 코스로 바꾸는 안을 제안해 줘. 내가 고른 내용으로만 저장해 줘.” 변경 충돌(409)은 최신 내용을 다시 읽어 사람의 변경을 확인한 뒤 처리하며 자동 덮어쓰지 않습니다. 이 저장소에 MCP 도구가 추가되어도 현재 사용하는 ChatGPT/Claude 채팅에 자동 연결되지는 않습니다. 사용하는 앱에서 MCP 클라이언트를 설치·설정해야 합니다.


## ChatGPT용 원격 연결

이 디렉터리는 기존 PC stdio 클라이언트입니다. 별도 HTTP 원격 MCP 후보는 앱 본체의 `src/mcp/`에 있으며 사용자/서버 안내는 `docs/chatgpt-remote-mcp.md`와 `docs/work-items/chatgpt-remote-mcp.md`를 참조하세요. 원격 연결은 모아 OAuth 로그인으로 본인 기록·사진·여행 권한을 선택하며 기존 QAREDI_TOKEN_FILE을 ChatGPT에 붙여 넣지 않습니다. 원격 기능의 서버 배포와 ChatGPT 실계정 등록이 끝나기 전에는 연결 완료가 아닙니다. 두 연결은 권한·전송 방식이 다르고 기존 문서용 stdio 도구는 유지됩니다.
