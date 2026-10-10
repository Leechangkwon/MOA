# PC 비서 첫 연결 토큰 안내

- 상태: 배포 완료
- 작업 브랜치: cloud/buddy-token-onboarding
- 기준 main 커밋: ec4c4da55bb3
- 담당 환경: 서버, Codex 직접 구현·검토(독립 검토로 보고하지 않음)

## 목적과 범위

사용자: 설치는 완료했지만 토큰이 없고, 처음 설치한 사람이 발급받기 어렵다.
홈의 PC 비서 연결에 기본 Gemini 연결을 먼저 배치한다. 기능 이름으로 AI/파일/GPT를 선택하고 기존 발급 API로 필요한 범위만 발급한다. OTP 미등록자는 같은 화면에서 등록한 뒤 새 코드로 발급한다. 결과는 정확한 트레이 메뉴·복사·사용 순서로 이어진다. GPT 로그인 방식과 토큰 발급은 구분한다.
허용 파일: src/views/member_pc_buddy_guide.php, src/views/member_top.php, src/handlers/member_welcome_page.php, public/assets/member.js, public/assets/pc-buddy-setup.js, tests/pc_buddy_guide.py, tests/pc_buddy_setup.py, DESIGN.md, STAGES.md, SECURITY.md, wiki/qaredi/guide-start.json, wiki/qaredi/index.json, 본 작업 기록, docs/CLOUD-HANDOFF.md, deploy/production-state.json(검증 후), 발행 VERSION/CHANGELOG 파일.
인증/권한/발급 API·DB·PC 설치물 변경 없음. 파일용 범위는 읽기 기본, 업로드 가능 계정에만 읽기+업로드 선택. 삭제·로그인 범위 자동 부여 없음. 토큰/OTP 비밀은 브라우저 저장소·주소에 저장하지 않는다.

## 검사

신규 첫 연결 합성 API 브라우저41/0, 안내27/0, AI79/0, 격리 실제 PHP/DB 공통196/0. 보안 린트·자산 버전·위키·PHP/JS 문법·diff 검사 통과. 증거: /home/changkwon/qaredi-buddy-token-results-20261011/. 첫 실행 시험은 접힌 details 내부 innerText가 빈 값이어서 실패했고 textContent로 비밀 삭제/생성 검증을 수정했다. 실Windows 등록/응답은 미검증.

## 서버 적용

- DB/패키지/설정/cron 변경 없음. 코드 반영 후 PHP-FPM reload.
- 기존 데이터/파일 해시 보존, 암호화 백업 및 복호화 확인, 후보 모의 적용, 원격/운영 드리프트 확인 후 배포.
- 실제 HTTPS에서는 지정 claude_ 계정만 사용. 토큰 원문·비밀번호·OTP 로그 금지.
- 실패 시 새 사용자 데이터는 보존하며 원인 조사. DB 복원/삭제 없음.

## 배포 결과

v2.53.8 `97cc6d868b5917d999e7c0595f834649a2d8786b` 운영 반영 완료 (2026-10-11T04:21:29+09:00). 변경16파일/삭제0, DB/패키지/시스템 설정 변경0. 암호화 DB·코드/설정·저장소 백업과 복호화 확인: `/var/backups/tbox-v2/buddy-token-onboarding-20261011`. 모의 적용과 원격/운영 drift0 확인 뒤 fast-forward·PHP-FPM reload.
실제 HTTPS28/0: 정상 검토 계정 비밀번호+새 OTP 로그인 및 새 UI 발급201, assistant.run 토큰 whoami200·파일 접근403·테스트 토큰만 정상 회수200·회수 후401. 실제 사용자의 토큰을 만들거나 변경하지 않았다. 페이지 개방만으로 발급/PC 연결/AI 요청0. JS 오류/5xx0. 화면390/1280px overflow0.
기존 저장소975파일 SHA·파일1042행 모든 필드·업무 콘텐츠5표·DB 소유권/ACL·nginx/cron 유지. 백업976파일은 자동화 커서1 포함.
검사 증거: `/home/changkwon/qaredi-buddy-token-results-20261011/` (setup41, guide27, assist79, cross196, post28).
PC update.4 재설치 불필요. 실제 Windows 토큰 붙여넣기 및 Gemini 응답은 사용자 확인 필요. 기존 프로그램 ZIP과 GPT 인증 방식 변경 없음.
