# 모아 커뮤니티 기록 플러그인

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
