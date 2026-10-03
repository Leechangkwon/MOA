# 커뮤니티 전체 기능 입구와 선택형 화면 안내

- 기준: main `7c874502ef4026a052380eb05ce68d706c92d8e5`
- 작업 브랜치: `cloud/community-member-experience`
- 상태: 구현·클라우드 브라우저 검사 완료, 서버 통합·배포 대기
- 지시서: `docs/community-member-experience-directive.md` 항목 3·6

## 목적과 동작

쿼리 없는 `/community/`는 개인 기록·팔로잉·발견·문서/게시판/업무·여행 계획·자료실·메신저/비서·로그인/설치를 소개하는 입구다. 개인 스레드는 `/community/memories?view=mine`, 업무 문서는 `/community/workspace`로 직접 들어간다. 기존 루트의 문서 검색/공간 쿼리와 `view=mine|feed|shared|discover` 북마크는 각 기존 화면으로 연결한다.

새 회원은 최초 사용 안내를 수락하거나 나중으로 넘길 수 있다. 마이그레이션 시각 이후 가입한 계정에만 자동 제안한다. 기존 회원은 각 화면의 물음표에서 직접 시작한다. 계정별로 수락·진행 위치·건너뛰기·완료를 저장한다. OTP·패스키나 콘텐츠 접근 권한과 무관한 화면 취향이다.

모달은 네이티브 `dialog`로 초점·키보드를 지원하고 SVG 사각형으로 화면을 어둡게 하며 대상을 강조한다. CSP를 완화하거나 인라인 스타일/스크립트를 넣지 않는다. 커뮤니티 개요, 스레드, 작성/사진, 문서, 파일, 시작 안내, 회원/GPT 메신저, 스토리/댓글/공유, 여행, 오늘 할 일의 실제 보이는 요소만 설명한다. 작성창이나 메신저를 자동으로 열거나 닫거나 제출하지 않는다. 사용자가 연 창에 설명 버튼을 더하며 쓰던 내용과 원래 창을 보존한다. 진행 저장 실패는 안내 창 안에도 표시한다.

## 변경 파일과 통합

- `src/community/handlers/overview_page.php`, `public/assets/community/overview.css`
- `src/community/UsageGuide.php`, `src/community/handlers/usage_guide_api.php`
- `src/views/usage_guide.php`, `public/assets/usage-guide.js`, `public/assets/usage-guide.css`
- `db/migrations/2026-10-14_usage_guide.sql`
- `tests/community/usage_guide_browser.py`, `tests/community/usage_guide_unit.php`
- 통합 담당: `src/community/routes.php` GET `/` 개요, GET/PATCH `/api/me/guide` 정책 `community.read`; 토큰 허용 목록에는 추가하지 않는다.
- 통합 담당: 공통 셸 `community-overview` 화면/내비 키와 `community/overview.css` 허용, 인증된 셸/회원 화면에서 `usage_guide.php` 한 번 포함. 공개 공유 화면에서는 포함하지 않는다.

## 검증

- `node --check public/assets/usage-guide.js`: 통과.
- `CHROMIUM_EXECUTABLE=/usr/bin/chromium python3 tests/community/usage_guide_browser.py`: 모바일 390px 다크·PC 1280px 라이트, 실제 JS/CSS 및 PHP 부분 템플릿을 이용한 합성 HTTP 검사 통과. 엄격한 CSP, 동의 전 쓰기 없음, 건너뛰기/완료/재개, 키보드/초점, 현재 카메라/앨범 및 여행 일정 버튼, 미완성 초안·열린 모달 보존, 오류 안내, 안내 취향 외 API 변경 없음.
- `php tests/community/usage_guide_unit.php`: 준비됨. 계정 ID 혼입, 미허용 문맥/상태, 비정수/초과 단계를 거부. 클라우드에 PHP가 없어 미실행.
- 실제 PostgreSQL 마이그레이션·권한·CSRF·다른 계정 분리, PHP 렌더, 실제 새 초대 가입, 운영 HTTPS, 실물 휴대폰/Windows: 미검증. 브라우저 합성 검사는 서버 검증을 대신하지 않는다.

## DB·배포·보존·실패 대응

새 표 `community.usage_guide_versions`, `community.usage_guides`만 추가한다. 기존 회원·게시글·문서·사진을 변경하지 않는다. 표 소유자를 변경하지 않으며 `tbox_app`은 버전 조회와 본인 안내 선호의 조회/추가/수정 권한, `tbox_backup`은 조회 권한을 조건부로 받는다. 공개 사용자 ID 입력을 받지 않고 서버 세션의 본인 ID로 조회/수정한다. 새 패키지·비밀·환경 설정은 없다.

서버는 공통 인계의 백업/후보 검증 절차 후 마이그레이션을 먼저 적용하고 코드를 배치한다. 표 준비 전 API는 503을 반환하고 필수 기능을 막지 않는다. 안내 API가 실패해도 명시적으로 연 화면 안내를 읽을 수 있으며 상태 저장 실패를 알린다. 복귀 시 앱 코드만 이전 상태로 되돌리고 새 선호 표는 남겨도 기존 앱 동작에 영향을 주지 않는다. 운영 기록 파일은 변경하지 않았다.
