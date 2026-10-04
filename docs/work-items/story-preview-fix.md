# 여러 스레드의 요약 표시 수정

- 상태: 서버 검증·v2.47.1 운영 배포 완료 (2026-10-04)
- 작업 브랜치: `cloud/story-preview-fix`
- 기준 main 커밋: `b4e2ad60e778908b8df69ec225ea6b7cadfbea21` (v2.47.0 배포 기록 포함)
- 후보 커밋: 이 문서를 포함한 기능 커밋. Git2 PR의 head로 고정한다.
- 담당 환경: Git2만 접속 가능한 클라우드

## 목적과 범위

사용자가 첫 첨부에서 이전 사진 이야기는 전체 본문이 길게 펼쳐지고 두 번째 첨부의 최신 글만 요약된다고 보고했다. 글을 다시 작성하거나 AI로 재생성하지 않아도 모든 피드 항목이 대표 사진·짧은 요약·내용 더 보기로 표시되어야 한다.

`memories.js`의 `render()`에서 `Array.map(postCard)`가 콜백의 두 번째 인수인 배열 인덱스를 `postCard(post, detailed)`의 상세 모드로 넘겼다. 첫 항목의 0은 요약, 나머지 1·2…는 전체 보기가 되는 문제다. 목록에서는 요약 모드를 명시하고 상세 모달에서만 전체 보기를 사용한다. 기존 단일 글 fixture로는 드러나지 않아 여러 서로 다른 합성 글을 함께 표시하는 회귀 시험을 추가한다.

변경 범위: `public/assets/community/memories.js`, `tests/community/memories_social_browser.py`, 커뮤니티 import 버전 문자열과 이 작업의 지시서·디자인·검토·단계·위키 안내. 새로운 API/권한/DB/의존성은 없다. ChatGPT 원격 MCP PR #4에 의존하지 않는 별도 수정이다.

## 검사

- 수정 전: `python3 tests/community/memories_social_browser.py` 종료1, 세 합성 글 중 첫 항목만 `post-preview` 626px, 두 번째 긴 글은 `post-detail` 4734px, 세 번째 이야기는 `post-detail` 3147px. 뒤 두 항목에 내용 더 보기 없음. 증거 `/tmp/qaredi-story-preview-before.log`.
- 수정 후 같은 명령: 종료0, **12개 시나리오 통과**(새 여러 글 시나리오 3개 + 기존 9개). 기존 40개 너비/테마 조합 포함. 390/1440px의 내 목록·팔로잉·발견·회원 프로필·익명 공유에서 순서를 바꾼 세 글, 각 글 상세/앨범·닫기/초점·추가 커서·새로고침 재정렬 확인. `/tmp/qaredi-story-preview-after.log`, `/tmp/qaredi-story-social/results.json`.
- 별도 화면 확인: 390px 다크에서 세 항목 모두 `post-preview`, 626/368/626px, 각각 내용 더 보기 1개. 이미지 자연 너비 800px 로딩 완료 확인. `/tmp/qaredi-story-preview-fix/multiple-cards-390-dark.png`. 실제 사용자 첨부 대신 합성 그림만 사용했다.
- `node --check` 변경된 6개 JS, Python AST, 변경 위키 JSON 및 community index 대상 파일 존재, `git diff --check`: 종료0.
- `php scripts/community_asset_version.php --check`, `php scripts/wiki_check.php`: 각각 종료127(`php: command not found`), **미실행**. 공식 PHP 자산 스크립트의 정렬/정규화/SHA256 계산을 `/tmp/community_asset_refresh.py`로 대체해 app=`22ef11dd4de6`, vendor=`5823578fa61a` 확인; 5개 상대 import 버전만 갱신 후 재계산 변경0. 이 대체 검산은 공식 위키/PHP 실행을 대체하지 않는다.
- `bash scripts/security_lint.sh`: 종료0, 금지 패턴0. `/tmp/story-preview-review-security.log`.
- 별도 문맥 검토: 수정판 독립 실행12/12 통과(`/tmp/story-preview-review-browser.log`), `git show b4e2ad6:public/assets/community/memories.js`를 브라우저 응답으로만 교체하여 같은 수정 전 실패도 독립 재현. 변경 JS/문서에 남은 차단 지적 없음. 독립 자산 검산39파일/59import, 누락/불일치/미지원 상대 참조0. 이는 서버 검증·운영 보안 승인을 의미하지 않는다.

사용자 첨부·실제 글·사진은 fixture나 Git에 넣지 않았다. 구현은 목록 콜백에 게시글 한 인수만 전달하는 한 줄 수정이며 상세 모달의 명시적 `true`는 유지한다.

PHP 실행 파일·PostgreSQL·운영 접속이 없는 환경이므로 실제 PHP/DB/운영 HTTPS·실물 휴대폰 검사는 미검증이다. Chromium 합성 HTTP 시험을 서버 인가 검사로 간주하지 않는다.

## 서버 적용

- DB 변경: 없음. 기존 글의 재저장·재생성·마이그레이션 불필요.
- 패키지/설정/cron/서비스: 없음. 기존 PHP 페이지는 `memories.js`의 filemtime을 URL 버전으로 사용하고 HTML은 no-store다.
- 기존 데이터 보존과 충돌 판정: 글·사진·AI 이야기·공개 범위·댓글·좋아요 저장 경로 변경 없음. main에 다른 후보가 통합됐으면 변경을 보존하고 자산 버전을 다시 계산한다. 기준과 다른 5개 JS 파일의 버전만 통째로 덮어쓰지 않는다.
- 후보 검증: `python3 tests/community/memories_social_browser.py`, `bash scripts/security_lint.sh`, `php scripts/community_asset_version.php --check`, `php scripts/wiki_check.php`. 공통 서버 회귀는 지침의 변경 범위에 맞춰 수행한다.
- 백업·드리프트·후보 커밋을 확인한 후 코드 반영. 실제 HTTPS에서 새 자산 URL을 확인하고 기존 두 개 이상의 글이 모두 요약되며 내용 더 보기에서 해당 글의 전체 사진·장면이 열리는지 확인한다. 일반 목록·내 스레드·발견·프로필·익명 공유 및 더 불러오기·앨범에서 돌아오기를 확인한다. 상세를 닫은 뒤에도 목록은 요약 상태여야 한다.
- 실패 시 대응: 이 표시 수정의 JS와 대응 자산 버전만 이전 검증 판으로 되돌릴 수 있다. 다른 기능이나 기존 followers 권한 코드를 구버전으로 되돌리지 않는다. DB 삭제·복원·글 재저장은 하지 않는다.

## 배포 결과 (서버에서만 작성)

2026-10-04 사용자 배포 지시로 PR #5를 v2.47.1 `6c5b9df`로 운영 반영했다. 후보 `645ce82`, 변경18파일 모의 적용/해시 일치, DB migration·설정 변경·서비스 재시작 없음. 합성 브라우저12시나리오·실제 PHP/격리 DB 화면19·운영 HTTPS105/105 통과. 운영의 커뮤니티 공개 기존 글3개를 검토 계정으로 읽어 390/1440px에서 모든 카드 요약, 대표 이미지 로딩, 해당 글 전체 장면 모달, 닫기/앨범/목록/새로고침과 새 캐시 URL을 확인했다. 원문/사진/공개 범위 재저장 없이 검사 대상 DB행·기존 owner/ACL·저장 원본1028파일 SHA-256 보존. 실물 휴대폰 사용자 확인은 미보고다.

백업: `/var/backups/tbox-v2/story-preview-20261004` (DB·코드/설정·storage 암호화, 체크섬 및 복호화 확인). 비공개 실행 로그/배포 계획/해시/HTTPS 결과: `/home/changkwon/qaredi-story-preview-results-20261004`. 서버 공식 PHP 자산 검사39파일/59import·위키67편·보안린트0 통과. 합성 시험용 Pillow는 비공개 test-deps에만 설치했고 서버 앱 의존성은 변경하지 않았다. 첫 시험 시 Pillow 및 `/usr/bin/chromium` 부재는 전용 시험 경로와 기존 Playwright Chromium으로 해결했다. 합성 시험 종료 때 Playwright 비동기 종료 경고가 있었으나 실행 종료0·12항목 통과이며 실제 PHP/운영 브라우저에서는 JS/5xx 오류0이다. 더 불러오기·순서 변경·익명 공유는 합성 시험에서 검증했고 운영에서는 공개 범위를 변경하거나 새 글을 만들지 않았다.
