# 최종 기존 비서 update.2 — 관리자 게시 준비 완료

- 상태: 검증 완료·관리자 인증 전 미게시
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
2. 관리자 T-box 파일 화면에서 이 ZIP을 최상위 폴더/admin-only로 올린다. 기존 실행 ZIP 확인·비밀번호·OTP 절차를 사용한다.
3. 운영 악성코드 검사 완료 뒤 AI 비서 배포함에서 해당 파일·위 버전·SHA256·받을 범위를 확인하고 관리자 비밀번호와 새 OTP로 게시한다.

현재 운영 프로그램0건. 관리자 인증을 대신하거나 우회하지 않았고 ZIP을 Git/정적 웹 경로에 올리지 않았다. 웹 안내는 기존 앱 업데이트 기준으로 이미 운영 반영됐으며 DB·API·권한을 추가로 수정하지 않는다.
