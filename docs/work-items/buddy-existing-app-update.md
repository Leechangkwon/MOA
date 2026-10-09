# 기존 트레이 비서 업데이트로 안내 정정

- 상태: 운영 반영 완료 (기존 Windows 앱 적용·ZIP 게시와 별도)
- 브랜치: cloud/buddy-server-contract
- 기준 main: 9ee24869c59d76538e6a9df2116115ce85b1a932
- 담당: 서버 Codex (Windows 기존 앱 흡수·재시작은 로컬 세션)

## 목적과 범위

2026-10-09 사용자 정정: 별도 비서가 아니라 사용하던 C:\vibe\tbox-buddy에 기능을 흡수한다. 기존 바로가기·파일 팔레트·단일 트레이를 유지한다. 개발 검증 폴더는 배포 앱으로 안내하지 않는다. 기존 프로세스 종료 뒤 Ctrl+Alt+Space 점유를 확인하는 로컬 작업이 우선이며 대체키 선택 요청은 철회됐다. preview.1/2 모두 게시 보류.

수정 허용: src/views/member_local_buddy.php, src/views/shell_top.php, src/handlers/programs_page.php, public/assets/programs.js의 사용자 문구; tests/moa_local_assist.py·tests/assistant/programs.py의 기존 안내 단언; 관련 DESIGN/PRD/STAGES/SECURITY/위키·발행·배포 기록. 라우트/권한/SQL/DB/Gemini API 계약 변경 없음. 토큰·로컬 앱·업데이트 패키지 내용은 수정하지 않는다.

## 검증·반영

문구 변경에 맞는 기존 모아 브라우저 시험과 PHP/JS·보안/자산/위키 검사를 수행한다. 새 구현을 따라 하는 테스트는 만들지 않는다. 백업/복호화·변경 파일 모의 적용·드리프트 확인 후 코드만 반영하고 실제 HTTPS 화면을 검사한다. 이전 코드로 복귀할 수 있으며 DB 복원·설치본 게시를 하지 않는다.

검사: 모아 합성 브라우저73/0, 공통 격리 HTTP/브라우저164/0. PHP3/JS 구문·security_lint·community_asset_version·wiki_check·diff 검사 통과. 데이터/권한/다운로드 코드는 변경하지 않아 전체 비서 생성/게시 시험을 중복 실행하지 않았다. 증거 `/home/changkwon/qaredi-buddy-existing-update-results-20261009/`.

## 운영 결과

v2.53.1, 기능8107979·발행1ebd78d04fada46718d196ba5f939026ebf509a8. 암호화 백업/복호화(`/var/backups/tbox-v2/buddy-existing-update-20261009`) → 16파일 모의 적용/해시 대조 → 드리프트 확인 → 코드 fast-forward/PHP-FPM reload 완료. DB·패키지·서비스 설정 변경0.

실제 HTTPS30/0, 390/1280px, 기존 앱 업데이트·백업·기존 바로가기 안내 확인. JS오류0·5xx0·실제 PC/공급자 호출0. 원본 저장파일1030 SHA256·이야기5표·소유자/ACL 보존. 운영 프로그램0건, preview.1/2 미게시. incoming 파일은 Git/배포에 포함하지 않았다. 루트 Qaredi f019c638 보존.

기존 C:\vibe\tbox-buddy에 검증 변경을 흡수하는 Windows 작업과 실제 Gemini 응답은 로컬 세션 후속이다. 대체 단축키 안내 없이 기존 프로세스 종료 후 Ctrl+Alt+Space 점유를 확인한다. 서버 Gemini 계약은 v2.53.0과 동일하다.
