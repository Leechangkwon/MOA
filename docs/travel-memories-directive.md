# 여행 기록 구현 범위

사용자 요청(2026-10-03 KST): qaredi 앱의 커뮤니티에서 촬영·짧은 글·개인 피드·공유·GPT를 통한 추억 꾸미기.

- 기존 PHP/PostgreSQL·세션·CSRF를 사용한다. 커뮤니티 첫 화면과 기존 공통 셸 안에 모든/나의/공유 스레드를 통합한다. 기존 문서(`/community/workspace`)·공간·게시판은 같은 내비게이션에서 접근한다. 기존 페이지·게시판 API는 유지한다.
- 나만 보기 / 커뮤니티 / 링크 공유를 글마다 선택한다. 링크는 계정 없이 읽기만 가능하며 30일 만료·소유자 해제·원본 접근 제한을 적용한다.
- 사진·영상은 전용 비공개 저장 디렉터리에 저장하고 모든 전송에 글 접근을 다시 검사한다. 소유자만 수정·삭제·꾸미기 원본을 볼 수 있다.
- ChatGPT로 가져갈 요청문·원본 다운로드/기기 공유와 다듬은 글·미디어 다시 가져오기를 제공한다. 원본 글·미디어는 보존한다. 회원 1:1 대화와 GPT 텍스트 대화를 공통 메신저 모달에 제공한다. GPT는 서버 설정으로 활성화하며 미연결 상태를 명확히 알린다. 사진/영상 가공은 ChatGPT에서 편집한 파일 가져오기를 사용한다.
- 수정 범위: `src/community/{routes.php,Memories.php,handlers/memories_*.php}`, `public/assets/community/memories*`, 기존 홈/내비 링크, `db/migrations/2026-10-03_community_memories.sql`, 테스트·문서·위키.
- 보안 핵심 변경: 위 새 DB migration과 `db/community/grants.sql`의 앱/백업 권한, `src/Http.php`의 로그인 복귀 경로 허용 목록, `deploy/sites/tbox-v2{,-local}.conf`의 여행 화면 전용 CSP 전달·링크 접근 로그 제외. 저장소 정합성 검사는 `scripts/consistency_check.php`에 여행 미디어 해시 대조를 추가한다. 감사 변경은 `src/Audit.php`의 `memory.change` 요약 문장 한 줄 등록이다. 기존 Auth/Authz/Db/Storage/Upload/public/index.php의 공통 관문은 바꾸지 않는다.
- 클라우드에서는 git2만 허용. 운영 배포·DB 변경은 불가능하므로 검증 가능한 코드/브랜치와 정확한 적용 절차를 준비한다. PHP/PostgreSQL 없는 환경의 미실행 검사는 통과로 기록하지 않는다.

## 커뮤니티 통합·메신저 수정 범위

사용자 의도: 커뮤니티는 업무뿐 아니라 일상·여행·공유·대화를 담는 공간. 별도 앱이나 메신저 페이지를 만들지 않는다.

- `src/views/shell_{top,bottom}.php`, `community_messenger.php`, 기존 editor/app.js 부팅 분기에 스레드 화면과 회원/GPT 모달 연결.
- `src/community/Messenger.php`, `handlers/messenger_api.php`, `routes.php`, `public/assets/community/messenger.{js,css}`.
- 보안 핵심: `db/migrations/2026-10-03_community_messenger.sql`, `db/community/grants.sql`에 대화/메시지/GPT 이력 및 최소 권한. `src/Audit.php`에 본문 없는 메신저 감사 종류.
- `src/Fetch.php`에 고정 OpenAI HTTPS 목적지만 허용하는 POST. 기존 DNS 사설 IP 차단, IP 고정, TLS 검증, 리다이렉트 차단 재사용. `.env.example`에 기본 비활성 설정. API 키·대화 본문은 로그에 남기지 않는다.
- 회원 대화는 두 참여자, GPT 이력은 본인만 접근. 세션/CSRF/쓰기 자격은 기존 관문을 그대로 사용한다.
