# 서비스 홈과 로그인 시작 화면

- 상태: 서버 검증 완료·배포 대기
- 작업 브랜치: cloud/service-home
- 기준 main 커밋: c53c2b22b08d
- 담당 환경: 서버, 격리 worktree / 같은 Codex가 구현·검증 (독립 검토 아님)

## 목적과 범위
일반 로그인은 모아 홈으로, 명시적 목적지는 기존 허용 목록대로 복귀한다. 홈은 업무·자료실·AI 비서·이야기의 목적과 진입 동작을 먼저 보여준다. 최근 작업을 앞에 두고 기존 읽을거리와 이야기 피드는 접어서 보존한다. 기본 모아 테마는 중립 본문과 서비스별 강조색을 사용한다. 기존 다른 테마, 밝기·고대비, 모바일 모달 메뉴를 보존한다.
허용 파일: src/Design.php(테마 설명), src/Http.php(복귀 허용 목록에 기존 PC 안내와 내 작업 경로만), src/views/{launcher,member_top,shell_top}.php, src/handlers/{login_page,site_manifest}.php, public/index.php(미로그인 자료실 루트 복귀 꼬리표만), public/assets/{app.js,moa.js,moa.css}, tests/service_home.py, tests/fixtures/service_home_render.php, tests/stage89_cross_regression/*(달라진 기대값), DESIGN.md, STAGES.md, SECURITY.md, docs/work-items/service-home.md, docs/design-guide.html, wiki/qaredi/{guide-site,index}.json, docs/server-context/{PRD,STAGES}.md, VERSION, CHANGELOG.*, docs/CLOUD-HANDOFF.md, deploy/production-state.json(서버 검증 후).

## 검사
로그인 기본·명시 목적지·악성 목적지, 비밀번호·패스키 공통 이동, 홈 서비스 링크, 접힌 이야기, 최근 작업, 모바일/데스크톱 밝음·어두움·고대비, 공통 격리 회귀. 렌더/브라우저 70/0, 격리 DB/실제 PHP/4개 서비스 화면 196/0, 기존 AI 도구 79/0. PHP·JS 문법, security_lint·자산 버전·위키·diff 검사 통과. 초기 공통 시험의 안내 PATCH 대기 조건을 dismissed 상태로 특정해 기존 비동기 경쟁을 보정했다. 증거: /home/changkwon/qaredi-service-home-results-20261010. 실물 휴대폰/PWA 갱신 시점은 미검증.

## 서버 적용
- DB/패키지/설정/cron 변경 없음. PHP-FPM reload만.
- 백업·복호화→파일 적용 모의 시험→원격/운영 드리프트 확인→FF main→HTTPS 확인.
- 원본 storage/파일 행·소유자·ACL 보존 확인. 실패 시 이전 코드 복귀만 판단, DB 복원/삭제 없음.

## 배포 결과
아직 미배포.
