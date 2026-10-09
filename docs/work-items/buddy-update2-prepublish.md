# 최종 기존 비서 update.2 — 사내 배포 완료

- 상태: 2026-10-09 17:14 KST 사내(internal) 게시 완료 · 실제 구성원 다운로드 검증 완료
- 웹 운영: v2.53.2 `739445c7f7cd978031fca8e81f906b03867a7897`, 실제 HTTPS30/0
- 배포함: https://changkwon.mso.so/tbox/programs
- Gemini 창구: https://changkwon.mso.so/tbox/api/assistant/chat (개인 assistant.run 전용, 기존 계약 동일)
- 이전 preview.1/2·update.1: 게시 금지. 이 파일만 현재 게시 준비 대상이다.

## 파일과 표시 정보

| 항목 | 값 |
|---|---|
| ZIP | tbox-buddy-4.0.0-tray-update.2.zip |
| 버전 | 4.0.0-tray-update.2 |
| 크기 | 258850 bytes |
| SHA256 | 65bff9f6fd5e3c1579b10eef962aad2fcefcc740f9e0e3a687245ba15ad2f009 |
| 서버 준비 위치 | /home/changkwon/qaredi-buddy-server-20261009/incoming/ |
| 증거 | /home/changkwon/qaredi-buddy-update2-prepublish-20261009/ |
| 상태 표식 | incoming/READY_FOR_ADMIN-update.2.json (Git·공개 웹 배포 제외) |

권장 파일 설명: **기존 트레이 비서 업데이트. update.cmd로 기존 설치 폴더 선택, 설정·바로가기·파일 팔레트·단일 트레이 유지. AI 열기: 기존 Ctrl+Shift+Space → AI Ctrl+J. GPT 실응답 확인. Gemini는 개인 assistant.run 토큰 등록 후 실응답 확인 필요. Ctrl+Alt+Space 등록 충돌 미해결.** 받는 범위는 게시 화면에서 관리자가 선택한다(사내 구성원용이면 internal).

## 서버에서 확인한 것

- 전달받은 정확한 크기·SHA256과 LF sidecar 일치; sha256sum -c 통과.
- ZIP CRC·80항목 경로/중복/심볼릭링크 없음·명세79개 해시 전부 일치.
- 개인 config/queue/activity/logs/.git/.env/.venv/업무자료 및 개발 designer_link 제외.
- update.1과 비교한 변경은 사용 안내/검증 문서, ai_chat/native_hotkey/tray, 관련 단축키 시험, manifest뿐이다. 기존 키를 Windows 등록 방식으로 먼저 등록하고 불가능한 경우 기존 훅으로 처리한다. 설정 키를 대체키로 바꾸지 않는다.
- 업데이트 도구는 update.1과 바이트가 같으며 기존 Linux 임시 폴더 시험4/0 근거를 재사용한다. Windows 실행을 Linux 검사로 주장하지 않는다.
- 운영 Upload::validatePath 일반 갈래는 F-65 확인 요구. 확인 갈래의 ZIP 형식 검사 통과(실행 항목73), 258850 bytes/위 SHA256. 실제 업로드 요청이나 DB 등록은 하지 않았다.
- 운영 배포판 ClamAV1.5.4: 종료0·감염0·ZIP 내부 검사,26.324초. 운영 scan.lock을 함께 사용했다. 이것은 사전 검사이며 인증된 업로드 후 운영 file.scan 감사 기록을 대체하지 않는다.

## 로컬 Windows 세션 보고

최종 ZIP을 새로 추출해 기존 Python3.11에서356검사(354통과·2skip). 기존 C:\vibe\tbox-buddy에 업데이트 적용, config/queue/activity SHA 불변, 원본 백업 C:\vibe\tbox-buddy-before-update-20261009-152625-332423. 기존 앱 한 프로세스만 상주. 다른 앱에서 원래 Ctrl+Shift+Space를 눌러 기존 파일/폴더 팔레트 호출, AI Ctrl+J로 같은 프로세스의 AI 대화 호출, GPT 실제 응답 성공. 서버가 직접 Windows를 조작한 결과가 아니라 로컬 세션 실측 보고다.

Gemini는 아직 사용자 개인 assistant.run 토큰 입력을 기다리므로 실제 응답을 검증했다고 표시하지 않는다. Ctrl+Alt+Space 등록1409 해결이나 GPT 모아 웹↔PC 전체 페어링도 완료로 표시하지 않는다.

## 사용자에게 남은 단계

1. 기존 비서의 Gemini 연결 설정에 개인 assistant.run 토큰을 등록해 실제 짧은 응답을 확인한다. 토큰은 앱에서 직접 입력하며 채팅/웹의 GPT 페어링 칸으로 보내지 않는다.
2. [AI 비서 배포함](https://changkwon.mso.so/tbox/programs)에서 최종 ZIP을 받을 수 있다. 기존 폴더와 설정을 백업하고 비서를 종료한 뒤, 압축을 완전히 풀고 update.cmd로 기존 설치 폴더를 선택한다. 관리자 업로드·게시 단계는 완료됐다.

사전 준비 시점에는 운영 프로그램0건이었다. 아래 최신 사용자 지시에 따라 정상 인증 업로드·게시를 완료했다. ZIP은 Git/정적 웹 경로에 올리지 않았다. 웹 안내는 기존 앱 업데이트 기준으로 이미 운영 반영됐으며 DB·API·권한을 추가로 수정하지 않는다.

## 2026-10-09 직접 게시 지시

이전의 사용자 직접 인증·게시 대기 범위를 최신 지시가 대체한다. 지정 관리자 작업 계정 `claude_admin01`의 기존 인증 자료로 정상 HTTPS 로그인, 매 요청 새 OTP·비밀번호, F-65 실행 ZIP 확인, 운영 ClamAV, F-67 게시를 수행한다. 범위는 기본값인 사내(`internal`), 최종 update.2 한 파일이다. 사람의 계정·세션을 대신하거나 인증 관문·DB 게시 필드를 우회하지 않는다. 업로드 후 실제 일반 구성원 다운로드 바이트·SHA256과 감사 기록을 확인하고 결과를 이 문서에 추가한다. 앱 코드/DB 스키마/설정 변경은 없다. 기존 파일은 보존하며 오류 시 업로드·게시 상태를 보고하고 삭제·복원을 수행하지 않는다.

## 실제 게시 결과

- 사용자 최신 지시로 이전 인증 대기 범위를 대체했다. 지정 관리자 작업 계정 claude_admin01로 HTTPS 로그인, 최신 필수 약관 확인, F-65 업로드 비밀번호·새 OTP, 운영 scan, F-67 게시 비밀번호·새 OTP를 모두 통과했다. 최초 시도는 작업 계정의 최신 약관 확인 화면으로 이동해 게시 전 중단됐다. 필수 약관만 정상 창구로 확인했으며 AI 국외 전송 동의는 변경하지 않았다.
- 2026-10-09 17:14 KST, 버전4.0.0-tray-update.2, 공개 범위 internal(사내). 파일 UUID657a9013-b400-42a6-a26c-a5d85725d43f. 프로그램 현행1건. preview.1/2·update.1 미게시 유지.
- 정상 업로드201 → 운영 ClamAV clean 및 같은 SHA256 file.scan allow → 게시201 → 일반 구성원 다운로드200·258850 bytes·원본 SHA256 일치. 업로드/검사/게시/다운로드 감사 기록 전부 확인.
- HTTPS 게시·다운로드 검증24/0, 실제 브라우저 확인10/0(390/1280px·가로 넘침0·JS/5xx0·다운로드 버튼으로 받은 파일명/해시 일치).
- 게시 전 기존 files1039행 전체 해시 불변. 직전 배포 때 확인한 저장 원본1030개 SHA256 전부 보존. 새 릴리스 파일만 정상 업로드로 추가했으며 기존 파일/프로그램 삭제·DB 직접 게시 갱신·권한/스키마/설정 변경은 없다. 웹 런타임 v2.53.2 그대로.
- 증거: /home/changkwon/qaredi-buddy-update2-publication-20261009/{result.json,browser-checks.json,scan.log,storage-preservation.json,published-390.png,published-1280.png}.
- 남은 제품 검증: 실제 Windows Gemini 응답(개인 assistant.run 토큰 필요), Ctrl+Alt+Space 등록 충돌, 전체 웹↔PC 페어링. 파일 게시 성공과 이 항목들은 구분한다.
