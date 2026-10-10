# 홈에서 시작하는 PC 비서 연결 안내

- 상태: 개발 중
- 브랜치: cloud/pc-buddy-guide
- 기준 main: 9f49cc0
- 환경: 서버 Codex 직접 구현·검토

## 목적과 범위
홈 PC 비서 연결이 계정/OTP/업무토큰/MCP가 섞인 가입 안내로 들어가던 문제를 고친다. 같은 인증된 /welcome의 section=pc-assistant 전용 읽기 화면으로 안내한다. 처음 설치(setup.cmd→run.cmd)와 기존 앱 업데이트(update.cmd→기존 설치 폴더 setup.cmd→기존 바로가기), 트레이 실제 메뉴/ChatGPT 로그인, 웹 동료 대화→AI 도구→GPT 연결, 성공 상태/오류 도움말을 표시한다. PC Gemini 토큰 설정은 별도 접힌 도움말. 토큰이나 OAuth 입력 수집 없음.
허용 파일: src/handlers/member_welcome_page.php, programs_page.php, member_messages_page.php; src/views/launcher.php, member_local_buddy.php, member_pc_buddy_guide.php; tests/fixtures/moa_member_render.php, tests/pc_buddy_guide.py, tests/moa_local_assist.py; DESIGN/STAGES/SECURITY, wiki/qaredi/guide-site.json/index.json, docs/work-items/CLOUD-HANDOFF, VERSION/CHANGELOG, deploy/production-state.json(검증 후).
DB/API/권한/PC ZIP 변경 없음. 기존 MFA/토큰 발급 폼은 기존 시작 안내에 유지한다. 새 화면은 안내와 링크만 제공한다.

## 검증/반영
PHP/Playwright 모바일·PC 안내/기존 시작 안내 분기, 기존 AI 도구, 공통 격리 회귀, security_lint/asset/wiki 검사. 운영 암호화 백업·복호화·모의 적용·Git 드리프트 확인→fast-forward→HTTPS 홈 링크 실제 클릭과 데이터 보존→기록/push. 실제 Windows 실행 성공은 안내 변경만으로 주장하지 않는다. DB 복원/재시드/파일 삭제 없음.

## 검사 결과

- 전용 안내 Playwright 27/0 (360/820/1280px), 기존 AI 도구79/0, 공통 격리 회귀164/0.
- PHP 문법·보안 린트·부품 버전·위키 검사 통과. 실제 PC 소스의 run/setup/update 및 트레이 메뉴 명칭과 안내를 대조함.
- 안내 화면을 방문해도 PC 실행/연결·공급자 요청0. 실제 Windows 실행은 이번 변경 범위가 아님.
