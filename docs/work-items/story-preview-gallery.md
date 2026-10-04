# 요약 카드에서 사진 좌우로 넘기기

- 상태: 서버 후보 검증 완료·운영 반영 준비 (2026-10-04)
- 작업 브랜치: `cloud/story-preview-gallery`
- 기준 main 커밋: `6dc6ec2` (v2.47.1 및 여러 글 요약 보정 배포 기록)
- 후보 커밋: PR #6 `394d452a0dc4681c1e6b0b71c60921294aa187ad`.
- 담당 환경: Git2만 접속 가능한 클라우드

## 목적과 범위

사용자가 요약 카드의 모양·짧은 본문과 펼친 상세 화면은 유지하고 요약 카드의 사진만 옆으로 넘겨 볼 수 있도록 요청했다. 사진 칸의 기존 높이와 비율 보존을 유지하면서 첨부 순서대로 한 장씩 가로 스크롤한다. 현재 번호, 모바일 스와이프와 PC 이전/다음·키보드를 제공하며 자동 넘김은 하지 않는다. 한 장이나 글만 있는 기록은 불필요한 제어를 표시하지 않는다.

변경: `public/assets/community/memories.js`, `memories.css`, 기존 social 브라우저 시험과 새 gallery 브라우저 시험, 필수 자산 버전 문자열과 지시서·디자인·검토·단계·위키 안내. 본문 요약 줄 수, 좋아요·댓글·공유, 펼친 상세의 이야기/원본 메모/전체 사진 구성은 유지한다. `docs/story-preview-gallery-directive.md`를 따른다.

ChatGPT MCP와 독립된 기능이며 이미 main에 포함된 이전 요약 오류 수정을 보존한다. 사용자 첨부와 실제 사진/글을 Git 또는 시험 fixture에 넣지 않는다.

## 검사

- `CHROMIUM_EXECUTABLE=/usr/bin/chromium python3 tests/community/memories_attachments_browser.py --output /tmp/qaredi-preview-gallery-attachments`: 종료0, 기존 사진 첨부/저장/실패 복구 9개 통과.
- 변경6개 JS의 `node --check`, 변경2개 Python AST, 위키 JSON 구조/인덱스 대상 파일 존재, `git diff --check`: 종료0.
- `php scripts/community_asset_version.php --check`, `php scripts/wiki_check.php`: 종료127, PHP 실행 파일이 없어 미실행. 공식 스크립트의 정렬/정규화/SHA256 알고리즘을 기존 임시 Python 보조 도구로 검산: app=`367fb9b91616`, vendor=`5823578fa61a`, import5파일의 문자열만 변경 후 재계산 변경0. 서버에서 공식 PHP 검사를 수행한다.
- `python3 tests/community/memories_gallery_browser.py`: 최종 별도 문맥 실행 종료0, **8개 시나리오 통과**. 320/390/1440px × 5개 피드 경로의 0/1/3/10장, 사진 순서/로딩·44px 버튼·경계·키보드·리사이즈·추가 불러오기·번호, 실제 CDP 터치 가로/세로 스크롤, 합성 영상 컨트롤/비활성 슬라이드 중지, 상세 DOM/기존 카드 높이 보존 확인. `/tmp/qaredi-preview-gallery-review.log`, `/tmp/qaredi-memories-gallery/results.json`.
- `python3 tests/community/memories_social_browser.py`: 구현자 및 별도 검토 실행 종료0, 기존 **12개 시나리오**(40개 너비/테마 조합 포함). DOM 전체 사진 수 대신 가로 창에 실제 한 장만 보이고 높이·비율이 유지됨을 확인한다.
- `bash scripts/security_lint.sh`: 별도 문맥 실행 종료0, 금지 패턴0. 독립 비교에서도 320/390/1440px의 제목·본문 위치/크기·카드 높이와 상세 DOM 동일(`/tmp/qaredi-preview-gallery-independent.json`). 남은 코드 차단 지적 없음.
- 초기 터치 시험에서 전체 페이지 스크린샷이 모바일 viewport를 일시 변경하고 고정 시간 대기가 스냅 완료 전에 판정하는 시험 문제를 확인했다. 카드 캡처 및 실제 스냅 좌표 대기로 보정하고 전체 검사를 다시 통과했다. 실패를 운영 동작 성공으로 간주하지 않았다.
- 시각 확인: `/tmp/qaredi-memories-gallery/mobile-gallery-second.png`, `desktop-gallery-fifth.png`. 합성 그림과 번호·버튼·본문을 확인했고 상세에 새 갤러리 요소가 생기지 않는다.

새 gallery 시험은 기존 Chromium/Playwright/Pillow 및 **시험 환경의 ffmpeg**로 합성 영상만 만든다. 운영 앱의 의존성을 추가하지 않는다. 시험 전용 임시 파일은 `/tmp/qaredi-memories-gallery`이며 실제 사진/영상은 사용하지 않는다. 상세 DOM과 카드 높이는 Git의 기준 판 `6dc6ec2` JS/CSS를 메모리 내 응답으로 제공해 비교한다.

합성 HTTP + 실제 Chromium 검사를 실제 서버 권한 또는 실물 휴대폰 검사로 간주하지 않는다. PHP/DB/nginx·운영 HTTPS·실물 기기·기존 서버 전체 교차 회귀는 이 환경에서 미검증이다.

## 서버 적용

- DB/패키지/설정/cron/서비스 변경: 없음. 기존 사진·글 재저장이나 재생성 불필요.
- 기존 데이터 보존: 첨부 순서·원본·본문·공개 범위·좋아요·댓글의 저장 및 서버 권한 검사 변경 없음.
- 코드 충돌: 최신 main의 다른 변경을 보존해 통합하고 `php scripts/community_asset_version.php`로 자산 해시를 다시 계산한다. 요약 오류 수정의 목록 렌더 콜백을 이전 방식으로 되돌리지 않는다.
- 후보 검사: `python3 tests/community/memories_gallery_browser.py`, `python3 tests/community/memories_social_browser.py`, `bash scripts/security_lint.sh`, `php scripts/community_asset_version.php --check`, `php scripts/wiki_check.php`. 서버의 해당 공통 회귀는 지침에 맞춰 수행한다.
- 백업/드리프트와 후보 커밋·변경 파일을 고정한 후 코드 반영. 기존 PHP 페이지는 CSS/JS의 filemtime을 URL 버전으로 사용한다. 실제 HTTPS 응답의 새 자산 버전과 실물 휴대폰 스와이프·세로 스크롤, PC 버튼, 1장/여러 장/영상·사진 혼합, 사진 순서/현재 번호와 모달 상세 보존을 확인한다. 운영 글을 시험용으로 수정하지 않는다.
- 실패 시 대응: 이번 표시 변경의 JS/CSS와 대응 자산 버전만 이전 검증 판으로 되돌릴 수 있다. 다른 기능·권한/스키마를 되돌리지 않고 DB 삭제·복원·글 재저장 없이 처리한다.

## 배포 결과 (서버에서만 작성)

서버 검증·배포 대기. 클라우드는 `VERSION`·배포 성공 기록을 갱신하지 않는다.

## 서버 후보 검증 (2026-10-04)

사용자가 현재 서버에서 PR #6 배포를 지시했다. 별도 worktree에서 공식 PHP 자산39파일/59import·위키67편·보안린트0·변경JS 구문·공백 검사 통과. 사진 넘김 브라우저8/8, social12/12(40너비/테마 포함), 실제 PHP/격리 DB 화면19/19, 공통 교차 회귀162/162 통과. 카드 높이/전체 상세 DOM 기준판 동일, CDP 가로/세로 터치·PC 버튼·번호·미디어 순서/경계·영상 중지 확인. 합성 모바일 캡처의 2/10과 버튼/본문 배치를 확인했다.

비공개 실행 증거 `/home/changkwon/qaredi-gallery-results-20261004`. 첫 social 실행은 Chromium 실행 경로가 맞지 않아 실패했고 기존 `chrome-linux64/chrome` 경로로 수정 후 전부 재실행 통과했다. 영상 시험용 ffmpeg는 비공개 test-deps에만 설치했다. 운영 앱 패키지·DB·설정 변경 없음. 암호화 DB/코드·설정/storage 백업과 체크섬·복호화 확인 완료: `/var/backups/tbox-v2/gallery-20261004`. 운영 배포와 HTTPS 확인은 아래 최종 기록으로 구분하며 실물 휴대폰·Safari/Firefox 검사는 미실시다.
