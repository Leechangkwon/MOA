# 모아 회사·모임 공간 설계와 개인 홈 구성

- 상태: 격리 브라우저 검증 완료·서버 검증·배포 대기
- 작업 브랜치: cloud/group-dashboard
- 기준 main 커밋: e1cece55b10d (origin/main fetch 확인, 운영 기록 v2.53.8 97cc6d8)
- 후보 커밋: cloud/group-dashboard의 최종 커밋 (Git 로그 참조)
- 담당 환경: 서버 접근 가능한 격리 worktree. 운영 checkout/DB/설정 변경 없음.

## 목적과 범위

회사 업무와 모임 모두를 지원하는 제품 공간 모델을 `docs/space-dashboard-plan.md`에 설계한다. 첫 구현은 개인 홈 구성: 기존 최근·즐겨찾기, 서비스, 연락과 안내, 이야기 위젯의 표시/순서/폭, 최근 자료실/문서 소스와 초기 목록 선택, 초안 저장/취소/기본 구성 복구. 숨긴 서비스는 헤더 바로가기로 임시 표시되며 저장값은 보존된다. 모두 숨김은 안내와 구성 단추로 복구한다.

계정별 브라우저 localStorage에는 허용 목록 구성만 저장한다. 민감 콘텐츠/권한/토큰/파일명/공간 식별자는 저장하지 않는다. 다른 기기 동기화 없음은 UI에 명시했다. 최초 계정 설정·동의 관문·기존 서버 행 권한·Claude 요청 제한 유지. 제목은 textContent, UUID 경로만 허용한다. 새 공유 공간, 공지/일정/프로젝트 필터 위젯, 서버 구성 동기화는 미구현 후속이다. 기존 community dashboard/DB-view 블록 기능을 대체하지 않는다.

변경: launcher.php, home-dashboard.js, start.js, moa.css, 합성 fixture/browser 검사, DESIGN.md/design-guide.html, 구현 지시서·설계·본 인계.

## 검사

- PHP launcher와 fixture 문법, JS 두 파일 문법: 통과.
- `tests/home_dashboard.py`: 합성 API와 PHP 렌더/Chromium. 결과 `outputs/group-dashboard-checks/checks.json`; 계정 분리·손상 설정·저장 차단·동의/최초 계정 관문·안전 출력·편집/복구·360/390/820/1280 밝게/어둡게·작은 카드·고대비 포함.
- `tests/service_home.py`: 기존 로그인 목적지·홈·테마·메신저 없는 합성 fixture·이야기 지연 로딩 회귀. 결과 `outputs/group-dashboard-regression/checks.json`.
- `bash scripts/security_lint.sh`: 금지 패턴 0건.
- `php scripts/community_asset_version.php --check`: 통과(community 자산 수정 없음).
- `php scripts/wiki_check.php`: 구조/누락 0건(69편), 서버 공통 문서는 Git 전달 사본 사용.
- `git diff --check`: 통과.

개인 홈 수락57/0, 기존 서비스 홈 회귀70/0. 별도 root 검토 브라우저3/0: 이전 소스의 늦은 응답 차단·숨김 시 이전 내용 제거·Escape 초점 복귀. 근거 outputs/dashboard-independent-review.json. 전체 운영 DB 회귀·실제 로그인/HTTPS·실물 휴대폰은 미검증. 실제 개인/공간 데이터는 합성 응답으로 대신했다. 구현과 같은 컨텍스트의 자체 시험이며 독립 검토라고 주장하지 않는다.

## 서버 적용

- DB/마이그레이션: 없음.
- 패키지/설정/cron/서비스: 없음, 신규 외부 의존성 없음.
- 기존 데이터 보존: 기존 읽기 API만 사용. 기존 파일/문서/메신저/공유 정책 변경 없음.
- 반영: 후보 검토 → 변경 파일 고정 → 백업/드리프트 확인 → 모의 코드 적용 → 지정 코드 반영 → 실제 HTTPS/권한 관문/기존 데이터 보존 확인.
- 실패 대응: 코드 원복 가능, 브라우저 구성만 남아 기존 코드에서 무시된다. DB 삭제·복원은 수행하지 않는다.

## 배포 결과

미배포. production-state.json/VERSION 변경 없음. 후보 브랜치 전달, 운영 main 병합·배포 없음.
