# 모아 커뮤니티 기록 플러그인

## 현재: 1.0.2 계정 갱신 (2026-10-23)

원본은 계정의 기존 개인 플러그인1.0.2와 일치한다. 같은 플러그인 ID·비공개 범위·표시·기본 프롬프트·아이콘을 보존했고, 계정 저장 뒤 실제 Codex `plugin/read`에서 installed/enabled=true, 서버 `moa`·스킬 `work-records`를 확인했다. 아래1.0.1의 수동 업로드 대기는 해소됐다. 집 PC에서 실행 중인 앱의 캐시·사용자 OAuth는 별도 확인한다.

1.0.0의 서버 `extensions.com.openai.auth`는 실제 Codex0.160.0에서 서버 전체를 누락시켰다. transport 문자열만 바꾸면 해결되지 않았다. 1.0.2는 portable `plugin.json`·`mcp.json`과 계정 서비스가 생성한 Codex 호환 파일을 함께 보존하고, 서버 설정은 검증된 HTTPS URL·transport만 둔다. 서버의 OAuth discovery/DCR·PKCE·동의·토큰·권한 검사는 유지한다. 고정 토큰을 포함하지 않는다.

```bash
python3 clients/plugins/build.py /absolute/output/moa-community-records-1.0.2.zip
python3 tests/mcp/plugin_loader.py
```

계정은 이미 갱신했다. 새 중복 플러그인 생성이나 반복 ZIP 업로드는 필요 없다. 계정 업데이트에는 수정한 다섯 텍스트 파일만 보냈으며 기존 아이콘을 보존했다. 빌더는 아이콘 포함 전체 여섯 파일을 만든다.

사용할 PC에서 플러그인 → 모아 커뮤니티 기록을 열고 새로고침한다. 앱을 완전히 종료·재실행하고 새 Codex 대화의 `/mcp`를 확인한다. 로그인 시 `memories.read`, `work.read`, `work.write` 세 범위만 승인한다. 갱신 선택에 읽기가 자동 포함되는 화면이면 추가 읽기 선택은 필요 없다. 서버가 지원하는 다른 범위도 discovery로 표시될 수 있으므로 사진·이야기 쓰기·여행은 자동 승인하지 않는다. CLI에서는 실제 서버 이름으로 `codex mcp login <실제 서버 이름> --scopes memories.read,work.read,work.write`를 사용한다.

`qaredi_2` 접두사나 페이지 생성 도구로 연결 성공을 판단하지 않는다. 실제 업무 도구 네 개와 URL·계정을 확인한다. 무인증401은 정상 challenge이며 기존 토큰 만료의 증거가 아니다. 콜백은 실제 요청에서 확인하며 이번 Codex 시험에서는 `http://127.0.0.1:43093/callback`이었다. 다른 PC의 포트를 추측해 고정하지 않는다.

계정 저장·운영 MCP·서버의 실제 Codex CLI·집 Windows 앱·모바일을 구분한다. 상세 결과와 남은 조작: `docs/work-items/moa-mcp-recovery-20261023.md`.

## 이전 배포 설명 (2026-10-18, 1.0.1)

`moa-community-records/`는 ChatGPT 데스크톱 앱의 Codex·Work에서 사용하는 native 패키지 원본이다. 동일한 이름·기능·표시·아이콘을 유지한 1.0.1이며, 기존 portable 1.0.0의 서버 확장 설정은 실제 Codex 0.160.0에서 스킬만 로드되고 MCP 서버 0개로 확인됐다. 확장 없는 portable 최소 서버는 읽히므로 portable 전체를 지원하지 않는다고 단정하지 않는다. 이 배포물은 native manifest와 OAuth discovery를 사용하며 두 형식을 섞지 않는다.

빌드(운영 자격이나 사용자 문서를 포함하지 않음):

```bash
python3 clients/plugins/build.py /absolute/output/moa-community-records-1.0.1.zip
python3 tests/mcp/plugin_loader.py
```

ZIP은 압축을 풀지 않고 기존 개인 플러그인의 새 버전 업로드에 사용한다. 설치만으로 사용자 로그인이 완료되지는 않는다. MCP 서버 Moa의 계정 연결에서 모아에 로그인하고, 업무 문서 읽기·갱신이 필요하면 **업무 문서 갱신**을 선택한다. 업무 읽기는 함께 포함되고 **내 글 읽기**는 기존 기본 범위다. 이야기·사진·여행 쓰기는 선택하지 않는다.

서버에는 `MCP_OAUTH_LOOPBACK_ENABLED=1`과 기존 HTTPS OAuth 설정이 필요하다. Codex HTTP OAuth는 discovery/DCR로 연결하며 PKCE/state/issuer와 사용자 동의를 생략하지 않는다. 고정 개인 토큰·비밀번호·client secret을 ZIP에 넣지 않는다. 기존 portable OAuth endpoint 설정은 서버의 discovery로 제공되며, native 클라이언트는 서버가 알리는 전체 승인 후보를 보여 줄 수 있다. 승인 화면의 다른 기능 범위는 기본 미선택이다.

이 ZIP의 imported MCP는 Desktop only다. 웹·모바일은 검증된 등록 앱 ID를 이용하는 별도 앱 연결이 필요하다. 데스크톱 로더/서버 수락 통과는 실제 집 Windows·사용자 인증·모바일 연결 통과와 구분한다. 계정에 올라간 기존 플러그인을 서버 파일 수정만으로 자동 갱신했다고 말하지 않는다.

검증과 운영 기록: `docs/work-items/moa-host-compat.md`.
