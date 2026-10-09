# 기존 비서 update.1 게시 전 준비

- 상태: update.1 게시 금지·update.2 교체 대기 (일반 업데이트 안내 운영 반영 완료)
- 기준 main: 0f5b0b0 (v2.53.1)
- 브랜치: cloud/buddy-server-contract
- Windows 앱 변경 담당: 로컬 세션. 서버는 전달받은 바이트를 수정하지 않는다.

## 사용자 범위

기존 C:\vibe\tbox-buddy에 백업 후 변경 흡수 완료라는 로컬 보고를 받았다. 별도 preview 프로세스 종료, 기존 앱 하나·원래 팔레트·기본 트레이 유지. config/queue/activity 전후 해시 동일. Ctrl+Alt+Space 등록1409는 기존 앱 종료 뒤에도 남아 대체키를 적용하지 않았고, 기존 Ctrl+Shift+Space→AI Ctrl+J로 연다. Gemini 실응답은 개인 assistant.run 토큰 등록 대기다.

최종 게시 전까지만 준비한다. 관리자 재인증은 사용자가 수행하며 우회하지 않는다. preview.1/2는 계속 게시 금지. 허용 변경은 `src/views/member_local_buddy.php`, `src/handlers/programs_page.php`의 실제 업데이트/호출 방법·미검증 안내, 관련 DESIGN/STAGES/SECURITY/위키·작업/발행/배포 기록뿐이다. API·권한·SQL·DB 변경0. 새 업로드 경로나 인증 예외를 만들지 않는다.

## 게시할 정확한 파일

- 파일: `tbox-buddy-4.0.0-tray-update.1.zip`
- 버전: `4.0.0-tray-update.1`
- 크기: 257760 bytes
- SHA256: `9a68994e0ce72fcf8e3397fb1e2ffb877af91af6fd4a26761bfce66a6217ad7a`
- 서버 준비 위치: `/home/changkwon/qaredi-buddy-server-20261009/incoming/`
- 접근 범위: 게시 화면에서 관리자가 결정(사내 구성원용이면 internal).
- 업로드 설명 권장 문구: `기존 트레이 비서 업데이트. 기존 설정·바로가기·파일 팔레트 유지. update.cmd로 기존 폴더 선택. AI 열기: Ctrl+Shift+Space → Ctrl+J. Ctrl+Alt+Space 등록 충돌은 미해결. GPT 실응답 확인, Gemini 실응답은 개인 assistant.run 등록 후 확인 필요.`

## 확인할 결과

압축 CRC·79항목의 안전한 경로/심볼릭링크 없음·78개 파일의 명세 SHA256 일치, 설정/로그/.git/.venv/업무자료/개발 designer_link 제외, SHA sidecar LF. 운영 Upload::validatePath의 일반 검사는 실행 ZIP 확인 필요, F-65 확인 갈래에서는 형식 통과. 이것은 인증을 건너뛰는 업로드가 아니며 DB/파일 등록0.

기존 업데이트 도구 시험4개를 서버 임시 폴더에서 실행한다(기존 파일/설정 백업·보존, 해시 실패, 경로 거부, 실행 중 거부). 첫 실행은 작업 디렉터리가 달라 Python tools 모듈을 찾지 못했다. 추출한 패키지 루트에서 재실행한 결과를 기록한다. Windows353검사351통과2skip와 실제 기존 앱 적용은 로컬 세션 보고로 구분한다. 새 실제 Gemini 호출은 하지 않는다.

ClamAV는 운영과 같은 크기/재귀 제한으로 읽기 전용 검사하고 기존 scan.lock을 함께 쓴다. 이 사전 검사는 업로드 뒤 운영 배치의 file.scan 감사 기록을 대체하지 않는다. 증거 `/home/changkwon/qaredi-buddy-update1-prepublish-20261009/`.

## 관리자에게 남는 단계

1. T-box 파일 화면에서 위 ZIP을 최상위 폴더/admin-only로 올리고 실행 ZIP 확인·관리자 비밀번호·OTP를 직접 입력한다.
2. 운영 악성코드 검사 완료 뒤 `/tbox/programs`에서 해당 파일을 고르고 위 버전·SHA256·받을 범위를 확인한다.
3. 관리자 비밀번호와 새 OTP로 게시한다. 로그인·OTP 값은 채팅으로 전달하지 않는다.

`update.cmd`는 압축을 푼 업데이트 묶음에서 실행해 **기존 설치 폴더**를 선택하는 도구다. 실행 중인 기존 비서는 트레이에서 먼저 종료한다. 소스 백업과 설정/로그인/데이터 보존 후 동일 앱을 갱신하고 기존 바로가기로 실행한다. 검증 폴더의 앱을 별도 상주 앱으로 실행하지 않는다.

## 서버 실측 결과

- 전달 ZIP 크기/SHA256 일치, CRC 정상,79항목/명세78개 해시 전부 일치, 개인 설정/로그/개발 협업 도구 제외 확인, LF sidecar 확인.
- PHP Upload 사전 검사: 일반 경로는 F-65 확인 요구, 관리자 확인 갈래의 형식 검사 통과(실행 항목72). DB 업로드/등록은 하지 않음.
- 배포된 ClamAV1.5.4: 종료0·감염0·ZIP 내부 검사 완료(24.565초). 운영 업로드 뒤 검사/감사 절차는 별도로 유지.
- 추출본 updater 기존 시험4/0(Linux 임시 폴더). 최초 import 경로 오류와 루트 디렉터리 재실행 결과 모두 보존. Windows351통과2skip는 로컬 보고.
- 변경 안내에 대해 기존 모아 브라우저73/0, PHP2·보안/자산/위키/diff 검사 통과. 생성/게시 로직은 바뀌지 않아 직전 v2.53.1 공통164와 v2.53.0 API 검사를 반복하지 않았다.
- `incoming/READY_FOR_ADMIN-update.1.json`에 검증 상태와 인증 필요를 표시. preview.1/2 게시 금지 표식 유지. 패키지를 Git이나 공개 경로에 넣지 않았다.

## 교체 및 운영 안내 반영

로컬 세션이 기존 Ctrl+Shift+Space 등록을 보강한 update.2로 교체를 요청했다. **위 update.1 해시는 과거 검증 기록이며 게시 대상이 아니다.** update.1의 READY 표식을 DO_NOT_PUBLISH로 전환했다. preview.1/2도 계속 게시 금지다. update.2 바이트/해시를 받으면 그 파일을 새로 확인한다.

일반 업데이트 절차·기존 팔레트 AI 호출·Gemini 미검증 안내는 v2.53.2(기능0c64464·발행739445c7f7cd978031fca8e81f906b03867a7897)로 운영 반영했다. 백업/복호화·11파일 모의 적용·드리프트 확인 후 코드만 반영. 모아73·실제 HTTPS30/0, 390/1280px·JS/5xx0, DB/설정변경0·원본1030파일/데이터/소유자/ACL 보존. 공급자 요청0·프로그램 등록0. 증거는 위 경로 post/https.log, deploy/ 하위다.
