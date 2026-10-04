# 여러 스레드의 요약 표시 수정 지시서

- 목표: 글의 목록 순서·작성 시점과 무관하게 피드에서는 대표 사진·짧은 요약·내용 더 보기만 보이고 전체 사진과 본문은 상세 모달에서 연다.
- 기준: origin/main `b4e2ad60e778908b8df69ec225ea6b7cadfbea21`, `cloud/story-preview-fix`. MCP PR #4와 독립된 수정이다.
- 요구사항: `DESIGN.md` 회원 경험 개선, `docs/community-member-experience-directive.md` 이야기 요약/상세, `PRD.md`·`API.md`·`ARCHITECTURE.md` 커뮤니티 회원 경험. `AGENTS.md`, `CLAUDE.md`, `docs/server-context/site-rules.md`를 읽고 시작한다.
- 원인 후보: `render()`의 `Array.map(postCard)`가 배열 인덱스를 `postCard`의 두 번째 `detailed` 인수로 전달한다. 0 다음의 항목은 상세 모드가 된다.
- 수정 파일(구현자): `public/assets/community/memories.js`, `tests/community/memories_social_browser.py`. 추가 파일이 필요하면 먼저 보고한다. 부모는 아래 문서와 자산 버전 갱신 여부를 확인한다.
- 자산 버전 갱신(부모): `public/assets/community/dashboard.js`, `database.js`, `editor.js`, `suggestions.js`, `templates.js`의 상대 import 버전 문자열만 공식 알고리즘에 맞춰 갱신한다. PHP 도구가 없으므로 동일 알고리즘 대체 검산을 기록하고 서버의 공식 검사를 남긴다.
- 금지 패턴: HTML 문자열 출력/innerHTML, 새 외부 요청, 권한·공유·API·DB·원문·미디어 수정, 실제 사용자 데이터/사진을 시험 fixture로 사용, 새 스타일·클래스. 커밋·push·브랜치 변경은 부모가 한다.
- 완료 기준: 최소 3개의 서로 다른 합성 글(사진 이야기·긴 일반 글·다른 이야기)을 한 목록에 넣어 수정 전 실패를 재현하고 수정 후 모든 글이 요약되는지 확인한다. 나의 목록·팔로잉·발견·다른 회원·익명 공유 목록 및 더 불러오기·앨범/목록 전환 중 관련 경로를 검증한다. 각 요약에서 더 보기를 누르면 해당 글 전체가 모달에서 열리고 닫기 후 피드는 접혀 있어야 한다. 모바일·데스크톱 및 기존 40개 테마/너비 검사와 XSS·댓글·좋아요·초점 복귀를 유지한다. 검사 범위를 무리하게 늘리지 않는다.
- 검사: `python3 tests/community/memories_social_browser.py`, `node --check public/assets/community/memories.js`, `git diff --check`; 부모가 보안 린트·자산 버전·위키 가능 여부와 독립 검토를 기록한다.
- 보고: 변경 파일 / 수정 전 실패와 수정 후 실행 명령·결과 / 미검증과 이유. 합성 HTTP 브라우저 시험을 실제 서버·실물 휴대폰 검사로 보고하지 않는다.
- 담당: 저장소의 Fable/Opus 도구는 이 환경에 없으므로 사용 가능한 Codex 구현 에이전트와 별도 문맥 검토로 역할을 충족한다.
